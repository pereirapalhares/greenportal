<?php
declare(strict_types=1);

/*
 * Serviço de conversão do visualizador de arquivos — roda SÓ pelo Agendador de Tarefas do Windows
 * (\GreenSolutions\GreenPortal-VisualizadorWorker, a cada minuto, como SYSTEM; scripts/criar-agendamento-visualizador.ps1).
 * A tela (visualizador_arquivo.php) e a API do GED apenas pedem a conversão na fila do banco universal
 * (visualizador_conversao, migração 287); este serviço pega os pedidos e grava o resultado em storage/runtime/visualizador:
 *   Word, PowerPoint, RTF, ODF, Visio, Publisher, TIFF...  -> PDF   (LibreOffice, perfil próprio)
 *   Excel / ODS / Numbers                                   -> HTML  (LibreOffice "HTML (StarCalc)", todas as abas)
 *   DWG                                                     -> DXF (ODA File Converter) -> PDF (Python + ezdxf, scripts/dxf_para_pdf.py)
 *   DXF                                                     -> PDF (Python + ezdxf)
 * Cada execução atende a fila por até ~50 segundos e termina; a próxima começa no minuto seguinte (uma por vez: trava em
 * storage/runtime/green-visualizador.lock). Prévias sem acesso há 30 dias são apagadas do cache.
 *
 * Programas (no .env ou variáveis de ambiente; os padrões abaixo valem para o servidor atual):
 *   GREEN_LIBREOFFICE = C:\Program Files\LibreOffice\program\soffice.exe
 *   GREEN_ODA         = C:\Program Files\ODA\ODAFileConverter 27.1.0\ODAFileConverter.exe (procura a versão mais nova)
 *   GREEN_PYTHON      = E:\Python37\python.exe   (python -m pip install ezdxf==1.0.3 matplotlib==3.5.3)
 *
 * Uso:
 *   php scripts\visualizador-worker.php                    atende a fila (o que o Agendador executa)
 *   php scripts\visualizador-worker.php --diagnostico      confere programas, bibliotecas Python, cache e fila
 *   php scripts\visualizador-worker.php --testar="C:\pasta\desenho.dwg"   converte um arquivo sem usar a fila
 *        (resultado em storage\runtime\visualizador\teste\)
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/VisualizadorPrevia.php';

const VW_TEMPO_EXECUCAO = 50;          // segundos atendendo a fila por execução
const VW_LIMITE_ARQUIVO = 200 * 1048576;
const VW_LIMITE_HTML = 30 * 1048576;
const VW_DIAS_CACHE = 30;

$args = $argv ?? [];
$opcao = static function (string $nome) use ($args): ?string {
    foreach ($args as $a) {
        if ($a === '--' . $nome) return '';
        if (str_starts_with($a, '--' . $nome . '=')) return trim(substr($a, strlen($nome) + 3), "\"' ");
    }
    return null;
};
$env = GreenPortalDatabase::env();
$windows = DIRECTORY_SEPARATOR === '\\';
$vwDiagnostico = false; $vwSaidaPython = ''; $vwUltimoDxf = '';
$raiz = dirname(__DIR__);
$runtime = $raiz . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'runtime';

function vw_log(string $texto): void
{
    global $raiz;
    $dir = is_dir($raiz . '/storage/logs') ? $raiz . '/storage/logs' : $raiz . '/storage/runtime';
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    @file_put_contents($dir . DIRECTORY_SEPARATOR . 'visualizador-worker-' . date('Ym') . '.log', date('Y-m-d H:i:s') . ' ' . $texto . PHP_EOL, FILE_APPEND);
}

function vw_config(string $chave): string
{
    global $env;
    return trim((string)($env[$chave] ?? (getenv($chave) ?: '')), "\"' ");
}

function vw_soffice(): ?string
{
    global $windows;
    foreach ([vw_config('GREEN_LIBREOFFICE'), 'C:\\Program Files\\LibreOffice\\program\\soffice.exe', 'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe'] as $c)
        if ($c !== '' && is_file($c)) return $c;
    if (!$windows) foreach (['/usr/bin/soffice', '/usr/local/bin/soffice'] as $c) if (is_file($c)) return $c;
    return null;
}

function vw_oda(): ?string
{
    $c = vw_config('GREEN_ODA');
    if ($c !== '' && is_file($c)) return $c;
    $achados = array_merge(glob('C:\\Program Files\\ODA\\ODAFileConverter*\\ODAFileConverter.exe') ?: [], glob('C:\\Program Files (x86)\\ODA\\ODAFileConverter*\\ODAFileConverter.exe') ?: []);
    usort($achados, static fn($a, $b) => strnatcasecmp($b, $a));   // versão mais nova primeiro
    return $achados[0] ?? null;
}

function vw_python(): ?string
{
    global $windows;
    foreach ([vw_config('GREEN_PYTHON'), 'E:\\Python37\\python.exe'] as $c) if ($c !== '' && is_file($c)) return $c;
    if (!$windows) foreach (['/usr/bin/python3', '/usr/local/bin/python3'] as $c) if (is_file($c)) return $c;
    return null;
}

/** Executa um programa sem shell, com tempo máximo (mata a árvore de processos ao estourar). */
function vw_executar(array $comando, int $segundos, string $pasta, array $ambiente = []): array
{
    global $windows;
    $saida = $pasta . DIRECTORY_SEPARATOR . '_stdout.txt';
    $erro = $pasta . DIRECTORY_SEPARATOR . '_stderr.txt';
    $env = null;
    if ($ambiente) { $env = getenv(); foreach ($ambiente as $k => $v) $env[$k] = $v; }
    $pipes = [];
    $p = proc_open($comando, [0 => ['pipe', 'r'], 1 => ['file', $saida, 'w'], 2 => ['file', $erro, 'w']], $pipes, $pasta, $env, ['bypass_shell' => true]);
    if (!is_resource($p)) return ['codigo' => -1, 'tempo_esgotado' => false, 'saida' => '', 'erro' => 'Não foi possível iniciar ' . basename((string)$comando[0])];
    fclose($pipes[0]);
    $inicio = microtime(true); $codigo = -1; $esgotado = false;
    while (true) {
        $st = proc_get_status($p);
        if (!$st['running']) { $codigo = (int)$st['exitcode']; break; }
        if (microtime(true) - $inicio > $segundos) {
            $esgotado = true;
            if ($windows) @exec('taskkill /F /T /PID ' . (int)$st['pid'] . ' 2>NUL');
            else @proc_terminate($p, 9);
            break;
        }
        usleep(250000);
    }
    proc_close($p);
    $ler = static fn(string $f): string => is_file($f) ? trim((string)@file_get_contents($f)) : '';
    $r = ['codigo' => $codigo, 'tempo_esgotado' => $esgotado, 'saida' => $ler($saida), 'erro' => $ler($erro)];
    @unlink($saida); @unlink($erro);
    return $r;
}

function vw_resumo_erro(array $r, string $programa): string
{
    if ($r['tempo_esgotado']) return "$programa excedeu o tempo máximo de conversão.";
    $txt = trim(preg_replace('/^findfont:.*$/m', '', $r['erro'] !== '' ? $r['erro'] : $r['saida']));
    $txt = trim((string)preg_replace('/\s+/', ' ', $txt));
    return "$programa falhou (código {$r['codigo']})" . ($txt !== '' ? ': ' . mb_substr($txt, -600) : '.');
}

function vw_url_arquivo(string $caminho): string
{
    $c = str_replace('\\', '/', $caminho);
    $partes = explode('/', $c);
    foreach ($partes as $i => $p) if (!preg_match('/^[A-Za-z]:$/', $p)) $partes[$i] = rawurlencode($p);
    $c = implode('/', $partes);
    return 'file://' . (str_starts_with($c, '/') ? '' : '/') . $c;
}

function vw_apagar_pasta(string $pasta): void
{
    if (!is_dir($pasta)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pasta, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($pasta);
}

/** LibreOffice -> PDF ou HTML. Devolve o arquivo gerado ou lança erro. */
function vw_libreoffice(string $entrada, string $alvo, string $pasta): string
{
    global $runtime;
    $soffice = vw_soffice();
    if ($soffice === null) throw new RuntimeException('LibreOffice não encontrado (GREEN_LIBREOFFICE).');
    $perfil = $runtime . DIRECTORY_SEPARATOR . 'lo-visualizador';
    if (!is_dir($perfil)) @mkdir($perfil, 0770, true);
    $saida = $pasta . DIRECTORY_SEPARATOR . 'saida';
    if (!is_dir($saida)) mkdir($saida, 0770, true);
    $filtro = $alvo === 'html' ? 'html:HTML (StarCalc)' : 'pdf';
    $r = vw_executar([$soffice, '-env:UserInstallation=' . vw_url_arquivo($perfil), '--headless', '--invisible', '--norestore', '--nologo', '--nodefault',
        '--nolockcheck', '--nofirststartwizard', '--convert-to', $filtro, '--outdir', $saida, $entrada], 180, $pasta);
    $gerado = $saida . DIRECTORY_SEPARATOR . pathinfo($entrada, PATHINFO_FILENAME) . '.' . $alvo;
    // soffice.exe às vezes devolve o controle um instante antes de fechar o arquivo
    for ($i = 0; $i < 20 && !$r['tempo_esgotado'] && !(is_file($gerado) && filesize($gerado) > 0); $i++) { usleep(250000); clearstatcache(); }
    if (!is_file($gerado) || filesize($gerado) === 0) throw new RuntimeException(vw_resumo_erro($r, 'LibreOffice'));
    return $gerado;
}

/** DWG -> DXF pelo ODA File Converter. */
function vw_oda_dxf(string $entrada, string $pasta): string
{
    $oda = vw_oda();
    if ($oda === null) throw new RuntimeException('ODA File Converter não encontrado (GREEN_ODA).');
    $saida = $pasta . DIRECTORY_SEPARATOR . 'dxf';
    if (!is_dir($saida)) mkdir($saida, 0770, true);
    // Argumentos: pasta de entrada, pasta de saída, versão, tipo, recursivo, auditar (corrige erros), filtro
    $r = vw_executar([$oda, dirname($entrada), $saida, 'ACAD2018', 'DXF', '0', '1', basename($entrada)], 240, $pasta);
    $gerado = $saida . DIRECTORY_SEPARATOR . pathinfo($entrada, PATHINFO_FILENAME) . '.dxf';
    for ($i = 0; $i < 12 && !$r['tempo_esgotado'] && !is_file($gerado); $i++) { usleep(250000); clearstatcache(); }
    if (!is_file($gerado) || filesize($gerado) === 0) {
        $log = glob($saida . DIRECTORY_SEPARATOR . '*.err') ?: [];
        if ($log) $r['erro'] = trim((string)@file_get_contents($log[0]));
        throw new RuntimeException(vw_resumo_erro($r, 'ODA File Converter'));
    }
    return $gerado;
}

/** DXF -> PDF pelo Python + ezdxf. */
function vw_dxf_pdf(string $dxf, string $pasta): string
{
    global $runtime, $raiz;
    $python = vw_python();
    if ($python === null) throw new RuntimeException('Python não encontrado (GREEN_PYTHON).');
    $mpl = $runtime . DIRECTORY_SEPARATOR . 'matplotlib';
    if (!is_dir($mpl)) @mkdir($mpl, 0770, true);
    $pdf = $pasta . DIRECTORY_SEPARATOR . 'desenho.pdf';
    global $vwDiagnostico, $vwSaidaPython, $vwUltimoDxf;
    $vwUltimoDxf = $dxf;
    $r = vw_executar([$python, $raiz . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'dxf_para_pdf.py', $dxf, $pdf], 300, $pasta,
        ['MPLBACKEND' => 'Agg', 'MPLCONFIGDIR' => $mpl, 'PYTHONIOENCODING' => 'utf-8'] + ($vwDiagnostico ? ['GP_DXF_DIAG' => '1'] : []));
    $vwSaidaPython = trim($r['saida'] . "\n" . preg_replace('/^findfont:.*$/m', '', $r['erro']));
    if ($r['codigo'] !== 0 || !is_file($pdf) || filesize($pdf) === 0) throw new RuntimeException(vw_resumo_erro($r, 'Conversão do desenho (Python/ezdxf)'));
    return $pdf;
}

/** Converte $origem para $alvo e devolve o arquivo gerado (dentro de $pasta). */
function vw_converter(string $origem, string $extensao, string $alvo, string $pasta): string
{
    $entrada = $pasta . DIRECTORY_SEPARATOR . 'entrada.' . $extensao;   // nome simples: sem acentos/espaços para os conversores
    if (!@copy($origem, $entrada)) throw new RuntimeException('Não foi possível copiar o arquivo de origem.');
    if ($extensao === 'dwg') return vw_dxf_pdf(vw_oda_dxf($entrada, $pasta), $pasta);
    if ($extensao === 'dxf') return vw_dxf_pdf($entrada, $pasta);
    return vw_libreoffice($entrada, $alvo, $pasta);
}

/** Planilha em HTML: o visualizador mostra só o HTML (sem imagens externas, sem scripts). */
function vw_ajustar_html(string $arquivo): void
{
    if (filesize($arquivo) > VW_LIMITE_HTML) throw new RuntimeException('A planilha é grande demais para a prévia; use Baixar.');
    $h = (string)file_get_contents($arquivo);
    $h = (string)preg_replace('#<script\b.*?</script>#is', '', $h);
    $h = (string)preg_replace('#<img\b[^>]*>#i', '', $h);
    file_put_contents($arquivo, $h);
}

function vw_tamanho(int $b): string { return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : round($b / 1024) . ' KB'; }

// ---------------------------------------------------------------- diagnóstico
if ($opcao('diagnostico') !== null) {
    $r = ['libreoffice' => vw_soffice(), 'oda_file_converter' => vw_oda(), 'python' => vw_python(), 'cache' => VisualizadorPrevia::pastaCache()];
    $pasta = $runtime . DIRECTORY_SEPARATOR . 'visualizador-diag';
    if (!is_dir($pasta)) @mkdir($pasta, 0770, true);
    if ($r['python']) {
        $p = vw_executar([$r['python'], '-c', 'import sys,ezdxf,matplotlib;print(sys.version.split()[0],"ezdxf",ezdxf.__version__,"matplotlib",matplotlib.__version__)'], 60, $pasta);
        $r['python_bibliotecas'] = $p['codigo'] === 0 ? $p['saida'] : 'FALTANDO: ' . vw_resumo_erro($p, 'python') . ' -> ' . $r['python'] . ' -m pip install ezdxf==1.0.3 matplotlib==3.5.3';
    }
    vw_apagar_pasta($pasta);
    $cache = VisualizadorPrevia::pastaCache();
    if (!is_dir($cache)) @mkdir($cache, 0770, true);
    $r['cache_gravavel'] = is_writable($cache);
    $r['cache_arquivos'] = count(glob($cache . DIRECTORY_SEPARATOR . '*.{pdf,html}', GLOB_BRACE) ?: []);
    try {
        $u = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
        $r['fila'] = $u->query('SELECT status, COUNT(*) qtd FROM vw_visualizador_conversao GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
        $r['ultimos_erros'] = $u->query("SELECT arquivo, erro, fim_em FROM vw_visualizador_conversao WHERE erro IS NOT NULL ORDER BY fim_em DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $r['fila'] = 'indisponível: ' . $e->getMessage() . ' (rode a migração 287)'; }
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

// ---------------------------------------------------------------- teste de um arquivo (sem fila)
if (($teste = $opcao('testar')) !== null) {
    if ($teste === '' || !is_file($teste)) { fwrite(STDERR, "Arquivo não encontrado: $teste\n"); exit(2); }
    $ext = strtolower(pathinfo($teste, PATHINFO_EXTENSION));
    $alvo = VisualizadorPrevia::alvo($ext);
    if ($alvo === null) { fwrite(STDERR, "O formato .$ext não precisa de conversão (" . VisualizadorPrevia::formato($ext) . ").\n"); exit(2); }
    $pasta = $runtime . DIRECTORY_SEPARATOR . 'visualizador' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'teste-' . bin2hex(random_bytes(4));
    mkdir($pasta, 0770, true);
    $inicio = microtime(true);
    $vwDiagnostico = true;
    $info = [];
    try {
        $gerado = vw_converter($teste, $ext, $alvo, $pasta);
        if (!empty($vwSaidaPython)) foreach (preg_split('/\R/', $vwSaidaPython) as $l) {
            if (str_starts_with($l, 'DIAG ')) $info['desenho'] = json_decode(substr($l, 5), true);
            elseif (trim($l) !== '') $info['python'][] = trim($l);
        }
        if ($alvo === 'html') vw_ajustar_html($gerado);
        $destDir = VisualizadorPrevia::pastaCache() . DIRECTORY_SEPARATOR . 'teste';
        if (!is_dir($destDir)) mkdir($destDir, 0770, true);
        $dest = $destDir . DIRECTORY_SEPARATOR . pathinfo($teste, PATHINFO_FILENAME) . '.' . $alvo;
        copy($gerado, $dest);
        // DWG: guarda também o DXF intermediário (gerado pelo ODA) para conferência
        if ($ext === 'dwg' && !empty($vwUltimoDxf) && is_file($vwUltimoDxf)) copy($vwUltimoDxf, $info['dxf'] = $destDir . DIRECTORY_SEPARATOR . pathinfo($teste, PATHINFO_FILENAME) . '.dxf');
        echo json_encode(['ok' => true, 'arquivo' => $dest, 'tamanho' => vw_tamanho((int)filesize($dest)), 'segundos' => round(microtime(true) - $inicio, 1)] + $info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $ok = true;
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'erro' => $e->getMessage(), 'segundos' => round(microtime(true) - $inicio, 1)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $ok = false;
    } finally { vw_apagar_pasta($pasta); }
    exit($ok ? 0 : 1);
}

// ---------------------------------------------------------------- serviço (Agendador do Windows)
if (!is_dir($runtime)) @mkdir($runtime, 0770, true);
$trava = fopen($runtime . DIRECTORY_SEPARATOR . 'green-visualizador.lock', 'c');
if ($trava === false || !flock($trava, LOCK_EX | LOCK_NB)) exit(0);   // execução anterior ainda em andamento

$cache = VisualizadorPrevia::pastaCache();
$tmp = $cache . DIRECTORY_SEPARATOR . 'tmp';
if (!is_dir($tmp)) @mkdir($tmp, 0770, true);
// O cache só é entregue pela API (que confere permissão e unidade): o Apache não serve a pasta
if (!is_file($cache . DIRECTORY_SEPARATOR . '.htaccess')) @file_put_contents($cache . DIRECTORY_SEPARATOR . '.htaccess', "Require all denied\n");
// Limpeza: prévias antigas e sobras de conversões interrompidas
$limite = time() - VW_DIAS_CACHE * 86400;
foreach (glob($cache . DIRECTORY_SEPARATOR . '*.{pdf,html}', GLOB_BRACE) ?: [] as $f) if (max((int)@filemtime($f), (int)@fileatime($f)) < $limite) @unlink($f);
foreach (glob($tmp . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $d) if ((int)@filemtime($d) < time() - 3600) vw_apagar_pasta($d);

try {
    $universal = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
} catch (Throwable $e) { vw_log('ERRO conexão banco universal: ' . $e->getMessage()); exit(1); }
$chamar = static function (string $sql, array $p = []) use ($universal): array {
    $q = $universal->prepare($sql); $q->execute($p); $r = $q->fetchAll(PDO::FETCH_ASSOC); $q->closeCursor(); return $r;
};

$fim = time() + VW_TEMPO_EXECUCAO;
$feitos = 0;
while (time() < $fim) {
    try { $pedido = $chamar('CALL sp_visualizador_proximo()')[0] ?? null; }
    catch (Throwable $e) { vw_log('ERRO fila: ' . $e->getMessage()); break; }
    if ($pedido === null) { sleep(3); continue; }
    $chave = (string)$pedido['chave']; $alvo = (string)$pedido['alvo']; $ext = strtolower((string)$pedido['extensao']); $origem = (string)$pedido['origem'];
    $inicio = microtime(true);
    $pasta = $tmp . DIRECTORY_SEPARATOR . $chave;
    $status = 'CONCLUIDO'; $erro = null;
    try {
        if (!preg_match('/^[a-f0-9]{40}$/', $chave) || VisualizadorPrevia::alvo($ext) !== $alvo) throw new RuntimeException('Pedido inválido.');
        if (!is_file($origem)) throw new RuntimeException('Arquivo de origem não encontrado no servidor.');
        if (VisualizadorPrevia::chave($origem, $alvo) !== $chave) throw new RuntimeException('O arquivo foi alterado depois do pedido; abra-o de novo.');
        if (filesize($origem) > VW_LIMITE_ARQUIVO) throw new RuntimeException('Arquivo grande demais para a prévia (' . vw_tamanho((int)filesize($origem)) . '); use Baixar.');
        vw_apagar_pasta($pasta);
        mkdir($pasta, 0770, true);
        $gerado = vw_converter($origem, $ext, $alvo, $pasta);
        if ($alvo === 'html') vw_ajustar_html($gerado);
        $destino = VisualizadorPrevia::arquivoCache($chave, $alvo);
        if (!@copy($gerado, $destino . '.parcial') || !@rename($destino . '.parcial', $destino)) throw new RuntimeException('Não foi possível gravar a prévia no cache.');
    } catch (Throwable $e) {
        $status = 'ERRO'; $erro = $e->getMessage();
    } finally { vw_apagar_pasta($pasta); }
    try { $chamar('CALL sp_visualizador_concluir(?,?,?)', [$chave, $status, $erro]); } catch (Throwable $e) { vw_log('ERRO concluir: ' . $e->getMessage()); }
    vw_log(sprintf('%s %s .%s -> %s %.1fs%s', $status, basename($origem), $ext, $alvo, microtime(true) - $inicio, $erro ? ' | ' . $erro : ''));
    $feitos++;
}
flock($trava, LOCK_UN);
fclose($trava);
exit(0);
