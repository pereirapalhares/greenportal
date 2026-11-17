<?php
declare(strict_types=1);

/*
 * Serviço de backup e retenção (Administração › Backup; migração 351; decisão do usuário de 25/10/2026).
 * Agendador do Windows: \GreenSolutions\GreenPortal-Backup, a cada 15 minutos, como SYSTEM (scripts\criar-agendamento-backup.ps1).
 *
 * A cada ciclo:
 *   1. pega o bloqueio do serviço (um só por vez) e marca o ciclo; no horário do dia coloca o backup diário na fila;
 *   2. executa o backup da fila, numa pasta nova <destino>\greenportal_AAAAMMDD_HHMMSS:
 *        - bancos *_dbp: mysqldump --single-transaction (rotinas, gatilhos e eventos), compactado em .sql.gz e conferido
 *          ("Dump completed" no fim);
 *        - aplicação: aplicacao.zip (com o .env; sem backups, exports, runtime, storage, .git e node_modules);
 *        - GED: espelho em <destino>\ged_espelho\portal e \legado — copia só o que é novo ou mudou; nada é apagado do espelho;
 *        - MANIFEST.txt (tamanho e SHA-256 de cada arquivo) e resumo.json;
 *   3. rotação: mantém os N diários mais recentes e o último de cada uma das M semanas; apaga as outras pastas greenportal_*;
 *   4. retenção (scripts\manutencao-retencao-exportacoes-alertas.php --execute) em cada empresa ativa;
 *   5. grava o resultado (sp_backup_worker_finalizar) e, se falhar, alerta os ADMINISTRADORES.
 * Também alerta uma vez por dia se o último backup concluído tiver mais de 26 horas.
 * Destino: BACKUP_DIR no .env (outro disco ou compartilhamento de rede); sem ele, backups\automatico na aplicação.
 * mysqldump: MYSQLDUMP_PATH no .env (padrão E:\wamp\bin\mysql\mysql8.4.7\bin\mysqldump.exe). Credenciais: GPT_DB_* do .env, passadas
 * num arquivo temporário (nunca na linha de comando). O sistema anterior (legado) só é lido: nada é gravado nele.
 *
 * Uso manual (teste): E:\wamp\bin\php\php8.4.15\php.exe scripts\backup-worker.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/AssistenteAplicacaoService.php';

@set_time_limit(0);
@ini_set('memory_limit', '512M');
date_default_timezone_set('America/Sao_Paulo');
$raiz = dirname(__DIR__);
$dirLog = $raiz . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'backup';
if (!is_dir($dirLog)) @mkdir($dirLog, 0775, true);
$log = static function (string $msg) use ($dirLog): void { @file_put_contents($dirLog . DIRECTORY_SEPARATOR . 'worker.log', '[' . date('Y-m-d H:i:s') . "] $msg" . PHP_EOL, FILE_APPEND); };

$env = GreenPortalDatabase::env();
$central = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$central->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$call = static fn(string $p, array $a = []): array => AssistenteAplicacaoService::chamarProcedure($central, $p, $a);

if ((int)$central->query("SELECT GET_LOCK('greenportal_backup_worker', 0)")->fetchColumn() !== 1) exit(0);
try { $call('sp_backup_worker_agendar'); }
catch (Throwable $e) { $log('ERRO ao ler o agendamento (a migração 351 foi aplicada?): ' . $e->getMessage()); exit(1); }

// ------------------------------------------------------------------ funções

/** Destino dos backups: [pasta, protegido, aviso]. Protegido = BACKUP_DIR em outro disco ou compartilhamento de rede. */
function backupDestino(array $env, string $raiz): array
{
    $dir = rtrim(trim((string)($env['BACKUP_DIR'] ?? '')), '\\/');
    if ($dir === '') return [$raiz . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'automatico', false, 'BACKUP_DIR não configurado no .env: backup no mesmo disco da aplicação'];
    $rede = str_starts_with($dir, '\\\\') || str_starts_with($dir, '//');
    $unidade = static fn(string $p): string => preg_match('/^([A-Za-z]):/', $p, $m) ? strtoupper($m[1]) : '';
    if (!$rede && $unidade($dir) !== '' && $unidade($dir) === $unidade($raiz)) return [$dir, false, 'BACKUP_DIR está no mesmo disco da aplicação (' . $unidade($dir) . ':)'];
    return [$dir, true, ''];
}

/**
 * Collation da sessão do mysqldump. As views foram criadas pelo portal com a conexão em utf8mb4_general_ci; na
 * collation padrão do MySQL 8 (utf8mb4_0900_ai_ci) o SHOW CREATE de uma view que converte número em texto e compara
 * com um texto fixo (ex.: TRIM(NOTA) REGEXP '...' em vw_fornecedor_ranking_calculo) falha com "Illegal mix of
 * collations" e o mysqldump para no meio do banco. BACKUP_COLLATION no .env troca a collation, se for preciso.
 */
function backupInitCommand(array $env): string
{
    $col = trim((string)($env['BACKUP_COLLATION'] ?? ''));
    if (!preg_match('/^utf8mb4_[a-z0-9_]+$/', $col)) $col = 'utf8mb4_general_ci';
    return "SET SESSION collation_connection = '$col'";
}

/** Interrupção do backup em andamento: CANCELADO (pedido na tela) ou ERRO (pouco espaço no destino). */
final class BackupInterrompido extends RuntimeException
{
    public function __construct(public readonly string $status, string $mensagem) { parent::__construct($mensagem); }
}

/** Espaço livre mínimo no destino (BACKUP_MIN_LIVRE_GB no .env, padrão 20 GB): abaixo disso o backup não começa ou para. */
function backupMinimoLivre(array $env): float
{
    $gb = (float)str_replace(',', '.', (string)($env['BACKUP_MIN_LIVRE_GB'] ?? '20'));
    return ($gb > 0 ? $gb : 20.0) * 1073741824;
}

/** Banco → .sql.gz com mysqldump (credenciais em arquivo temporário). Retorna [bytes, erro]. */
function backupDumpBanco(array $env, string $exe, string $banco, string $arquivo, ?callable $tique = null): array
{
    $cnf = tempnam(sys_get_temp_dir(), 'gpbk');
    $q = static fn(string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    file_put_contents($cnf, "[client]\nuser=" . $q((string)($env['GPT_DB_USERNAME'] ?? '')) . "\npassword=" . $q((string)($env['GPT_DB_PASSWORD'] ?? ''))
        . "\nhost=" . $q((string)($env['GPT_DB_HOST'] ?? '127.0.0.1')) . "\nport=" . (int)($env['GPT_DB_PORT'] ?? 3306) . "\n");
    @chmod($cnf, 0600);
    $erroArq = $arquivo . '.stderr';
    try {
        $cmd = [$exe, '--defaults-extra-file=' . $cnf, '--single-transaction', '--quick', '--routines', '--triggers', '--events', '--hex-blob',
            '--default-character-set=utf8mb4', '--init-command=' . backupInitCommand($env), '--no-tablespaces', '--set-gtid-purged=OFF', $banco];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', $erroArq, 'w']], $pipes);
        if (!is_resource($proc)) return [0, 'não foi possível iniciar o mysqldump'];
        $gz = gzopen($arquivo, 'wb6');
        if (!$gz) { fclose($pipes[1]); proc_close($proc); return [0, 'não foi possível criar ' . $arquivo]; }
        $fim = '';
        try {
            while (!feof($pipes[1])) {
                $bloco = fread($pipes[1], 1048576);
                if ($bloco === false || $bloco === '') continue;
                gzwrite($gz, $bloco);
                if ($tique) $tique();
                $fim = substr($fim . $bloco, -400);
            }
        } catch (Throwable $e) {
            @proc_terminate($proc); @fclose($pipes[1]); @gzclose($gz); @proc_close($proc); @unlink($arquivo);
            throw $e;
        }
        fclose($pipes[1]); gzclose($gz);
        $codigo = proc_close($proc);
        $stderr = trim((string)@file_get_contents($erroArq));
        // Aviso conhecido do mysqldump quando o GTID está desligado não é erro
        $stderr = trim((string)preg_replace('/^.*(Warning: A partial dump|GTID).*$/mi', '', $stderr));
        if ($codigo !== 0) return [(int)@filesize($arquivo), 'mysqldump terminou com código ' . $codigo . ($stderr !== '' ? ': ' . mb_substr($stderr, 0, 500) : '')];
        if (!str_contains($fim, 'Dump completed')) return [(int)@filesize($arquivo), 'o dump não terminou (sem "Dump completed" no fim)' . ($stderr !== '' ? ': ' . mb_substr($stderr, 0, 500) : '')];
        return [(int)filesize($arquivo), ''];
    } finally {
        @unlink($cnf);
        if (is_file($erroArq) && (int)@filesize($erroArq) === 0) @unlink($erroArq);
    }
}

/** Pasta da aplicação → zip, sem as pastas de dados e de trabalho. Retorna [bytes, arquivos, erro]. */
function backupZipAplicacao(string $raiz, string $arquivo, array $fora): array
{
    if (!class_exists('ZipArchive')) return [0, 0, 'extensão zip do PHP desligada'];
    $zip = new ZipArchive();
    if ($zip->open($arquivo, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return [0, 0, 'não foi possível criar ' . $arquivo];
    $base = rtrim($raiz, '\\/') . DIRECTORY_SEPARATOR;
    $foraTopo = array_map('strtolower', ['backups', 'exports', 'runtime', 'storage', '.git', 'node_modules']);
    $foraAbs = array_values(array_filter(array_map(static fn($p) => strtolower(rtrim((string)$p, '\\/')) . DIRECTORY_SEPARATOR, $fora)));
    $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $f) use ($base, $foraTopo, $foraAbs): bool {
            if ($f->isLink()) return false;
            $abs = strtolower($f->getPathname()) . ($f->isDir() ? DIRECTORY_SEPARATOR : '');
            foreach ($foraAbs as $x) if (str_starts_with($abs, $x)) return false;
            $rel = substr($f->getPathname(), strlen($base));
            if (!str_contains($rel, DIRECTORY_SEPARATOR) && $f->isDir() && in_array(strtolower($rel), $foraTopo, true)) return false;
            return true;
        }), RecursiveIteratorIterator::LEAVES_ONLY);
    $n = 0;
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $zip->addFile($f->getPathname(), str_replace('\\', '/', substr($f->getPathname(), strlen($base))));
        $n++;
    }
    if (!$zip->close()) return [(int)@filesize($arquivo), $n, 'falha ao fechar o zip: ' . $zip->getStatusString()];
    return [(int)filesize($arquivo), $n, ''];
}

/** Espelho incremental: copia o que é novo ou mudou; não apaga nada. Retorna estatísticas. */
function backupEspelhar(string $origem, string $destino, array $foraTopo, array $foraAbs, ?callable $tique = null): array
{
    $r = ['origem' => $origem, 'arquivos' => 0, 'copiados' => 0, 'bytes_copiados' => 0, 'erros' => 0, 'exemplos_erro' => []];
    if (!is_dir($origem)) { $r['aviso'] = 'pasta não encontrada'; return $r; }
    $base = rtrim($origem, '\\/') . DIRECTORY_SEPARATOR;
    $foraTopo = array_map('strtolower', $foraTopo);
    $foraAbs = array_values(array_filter(array_map(static fn($p) => strtolower(rtrim((string)$p, '\\/')) . DIRECTORY_SEPARATOR, $foraAbs)));
    $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($origem, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $f) use ($base, $foraTopo, $foraAbs): bool {
            if ($f->isLink()) return false;
            $abs = strtolower($f->getPathname()) . ($f->isDir() ? DIRECTORY_SEPARATOR : '');
            foreach ($foraAbs as $x) if (str_starts_with($abs, $x)) return false;
            $rel = substr($f->getPathname(), strlen($base));
            if (!str_contains($rel, DIRECTORY_SEPARATOR) && $f->isDir() && in_array(strtolower($rel), $foraTopo, true)) return false;
            return true;
        }), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $r['arquivos']++;
        if ($tique && $r['arquivos'] % 500 === 0) $tique();
        $rel = substr($f->getPathname(), strlen($base));
        $alvo = rtrim($destino, '\\/') . DIRECTORY_SEPARATOR . $rel;
        $tam = $f->getSize(); $mt = $f->getMTime();
        if (is_file($alvo) && filesize($alvo) === $tam && filemtime($alvo) >= $mt) continue;
        $pasta = dirname($alvo);
        if ((!is_dir($pasta) && !@mkdir($pasta, 0770, true) && !is_dir($pasta)) || !@copy($f->getPathname(), $alvo)) {
            $r['erros']++;
            if (count($r['exemplos_erro']) < 10) $r['exemplos_erro'][] = $rel;
            continue;
        }
        @touch($alvo, $mt);
        $r['copiados']++; $r['bytes_copiados'] += $tam;
    }
    return $r;
}

/** Quais backups manter: os N diários mais recentes (um por dia) e o último de cada uma das M semanas. */
function backupManter(array $guardados, int $diarios, int $semanais): array
{
    usort($guardados, static fn($a, $b) => strcmp((string)$b['finalizado_em'], (string)$a['finalizado_em']));
    $manter = []; $dias = []; $semanas = [];
    $limiteSemana = (int)date('oW', strtotime('monday this week -' . max(0, $semanais - 1) . ' weeks'));
    foreach ($guardados as $g) {
        $t = strtotime((string)$g['finalizado_em']); $dia = date('Y-m-d', $t); $sem = (int)date('oW', $t);
        if (!isset($dias[$dia]) && count($dias) < $diarios) { $dias[$dia] = true; $manter[(int)$g['codbackup']] = true; }
        if ($semanais > 0 && $sem >= $limiteSemana && !isset($semanas[$sem])) { $semanas[$sem] = true; $manter[(int)$g['codbackup']] = true; }
    }
    return $manter;
}

function backupApagarPasta(string $pasta): bool
{
    if (!is_dir($pasta)) return true;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pasta, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    return @rmdir($pasta);
}

/** Alerta os ADMINISTRADORES ativos de cada empresa (link para a tela de backup). */
function backupAlertar(array $env, array $bancos, string $titulo, string $msg, callable $log): void
{
    foreach ($bancos as $banco) {
        if (!preg_match('/^g[a-z0-9_]+_dbp$/i', (string)$banco)) continue;
        try {
            $pdo = GreenPortalDatabase::connect($env, (string)$banco, 'GPT');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $adm = $pdo->query("SELECT u.codusuario FROM usuarios u JOIN perfis p ON p.codperfil = u.codperfil WHERE COALESCE(u.ativo, 0) = 1 AND UPPER(TRIM(p.codigo)) = 'ADMINISTRADOR'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($adm as $u) { $q = $pdo->prepare("CALL sp_alerta_criar(?, ?, ?, ?, 'NAO')"); $q->execute([(int)$u, mb_substr($titulo, 0, 180), mb_substr($msg, 0, 1000), '/principal/administracao_backup.php']); $q->closeCursor(); }
        } catch (Throwable $e) { $log("aviso: não foi possível alertar os administradores de $banco: " . $e->getMessage()); }
    }
}

$bancosEmpresas = static function () use ($call): array { try { return array_column($call('sp_backup_bancos_retencao'), 'banco_dados'); } catch (Throwable) { return []; } };

// ------------------------------------------------------------------ alerta de atraso (uma vez por dia)
try {
    $cfg = $call('sp_backup_config_obter')[0] ?? null;
    $marca = $dirLog . DIRECTORY_SEPARATOR . 'alerta_atraso.txt';
    if ($cfg && (int)$cfg['ativo'] === 1 && $cfg['horas_desde_ultimo_ok'] !== null && (int)$cfg['horas_desde_ultimo_ok'] > 26 && trim((string)@file_get_contents($marca)) !== date('Y-m-d')) {
        @file_put_contents($marca, date('Y-m-d'));
        backupAlertar($env, $bancosEmpresas(), 'Backup atrasado', 'O último backup concluído do GreenPortal tem ' . (int)$cfg['horas_desde_ultimo_ok'] . ' horas. Abra Administração › Backup e confira o histórico e o serviço GreenPortal-Backup.', $log);
        $log('alerta de atraso enviado (' . (int)$cfg['horas_desde_ultimo_ok'] . ' h sem backup concluído)');
    }
} catch (Throwable $e) { $log('aviso: verificação de atraso: ' . $e->getMessage()); }

// ------------------------------------------------------------------ fila
while (true) {
    $ex = $call('sp_backup_worker_reivindicar')[0] ?? null;
    if (!$ex) break;
    $id = (int)$ex['codbackup'];
    $log("backup #$id ($ex[origem]) iniciado");
    $resumo = ['bancos' => [], 'aplicacao' => null, 'ged' => [], 'rotacao' => null, 'retencao' => [], 'avisos' => []];
    $erros = []; $avisos = []; $pasta = null; $total = 0; $protegido = 0;
    // Mantém viva a conexão que segura o bloqueio do serviço durante cópias longas (sem ela, outro ciclo marcaria o backup como interrompido)
    $ultimoPing = time();
    $manterViva = static function () use ($central, &$ultimoPing): void { if (time() - $ultimoPing >= 60) { try { $central->query('SELECT 1')->fetchAll(); } catch (Throwable) {} $ultimoPing = time(); } };
    // A cada 30 segundos: espaço livre no destino e pedido de cancelamento na tela (Administração › Backup)
    $minLivre = backupMinimoLivre($env); $destinoVigia = null; $ultimaVigia = 0;
    $vigiar = static function (bool $agora = false) use ($call, $id, $minLivre, &$destinoVigia, &$ultimaVigia, $manterViva): void {
        $manterViva();
        if (!$agora && time() - $ultimaVigia < 30) return;
        $ultimaVigia = time();
        try { $st = (string)($call('sp_backup_worker_situacao', [$id])[0]['status'] ?? ''); } catch (Throwable) { $st = ''; }
        if ($st === 'CANCELANDO') throw new BackupInterrompido('CANCELADO', 'cancelado pelo usuário durante o backup');
        if ($destinoVigia !== null) {
            $livre = @disk_free_space($destinoVigia);
            if ($livre !== false && $livre < $minLivre) throw new BackupInterrompido('ERRO', 'interrompido por pouco espaço no destino: ' . round($livre / 1073741824, 1)
                . ' GB livres, mínimo ' . round($minLivre / 1073741824, 1) . ' GB (BACKUP_MIN_LIVRE_GB no .env; configure BACKUP_DIR em outro disco)');
        }
    };
    $progresso = static function () use ($call, $id, &$pasta, &$resumo): void { try { $call('sp_backup_worker_progresso', [$id, $pasta, json_encode($resumo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]); } catch (Throwable) {} };
    try {
        [$destino, $prot, $avisoDestino] = backupDestino($env, $raiz);
        $protegido = $prot ? 1 : 0;
        if ($avisoDestino !== '') $avisos[] = $avisoDestino;
        if (!is_dir($destino) && !@mkdir($destino, 0770, true) && !is_dir($destino)) throw new RuntimeException("não foi possível criar a pasta de destino $destino");
        if (!is_writable($destino)) throw new RuntimeException("a pasta de destino $destino não aceita gravação pelo usuário do serviço");
        // Espaço: pelo menos 1,2 × o último backup (sem o espelho do GED)
        $ultimo = 0; foreach ($call('sp_backup_guardados') as $g) { $ultimo = (int)$g['tamanho_bytes']; break; }
        $livre = @disk_free_space($destino);
        $destinoVigia = $destino;
        if ($livre !== false && $livre < $minLivre) throw new RuntimeException('pouco espaço no destino: ' . round($livre / 1073741824, 1) . ' GB livres, mínimo '
            . round($minLivre / 1073741824, 1) . ' GB (BACKUP_MIN_LIVRE_GB no .env; configure BACKUP_DIR em outro disco)');
        if ($livre !== false && $ultimo > 0 && $livre < $ultimo * 1.2) throw new RuntimeException('pouco espaço no destino: ' . round($livre / 1073741824, 1) . ' GB livres, o último backup ocupou ' . round($ultimo / 1073741824, 1) . ' GB');
        $pasta = $destino . DIRECTORY_SEPARATOR . 'greenportal_' . date('Ymd_His');
        if (!@mkdir($pasta, 0770, true)) throw new RuntimeException("não foi possível criar $pasta");
        $progresso();

        // 1. bancos
        $exe = (string)($env['MYSQLDUMP_PATH'] ?? 'E:\\wamp\\bin\\mysql\\mysql8.4.7\\bin\\mysqldump.exe');
        if (!is_file($exe)) throw new RuntimeException("mysqldump não encontrado em $exe (configure MYSQLDUMP_PATH no .env)");
        $is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
        $bancos = $is->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE '%\\_dbp' ORDER BY SCHEMA_NAME")->fetchAll(PDO::FETCH_COLUMN);
        if (!$bancos) throw new RuntimeException('nenhum banco *_dbp visível para o usuário GPT_DB_USERNAME');
        foreach ($bancos as $b) {
            $ini = microtime(true);
            [$bytes, $erro] = backupDumpBanco($env, $exe, (string)$b, $pasta . DIRECTORY_SEPARATOR . $b . '.sql.gz', $vigiar);
            $resumo['bancos'][$b] = ['bytes' => $bytes, 'segundos' => (int)round(microtime(true) - $ini)] + ($erro !== '' ? ['erro' => $erro] : []);
            if ($erro !== '') $erros[] = "$b: $erro";
            $progresso();
        }

        // Banco com erro: o backup já não serve para restaurar; não gasta tempo nem disco com a aplicação e o GED
        if ($erros) throw new RuntimeException('a aplicação e o GED não foram copiados porque há banco com erro');
        $vigiar(true);

        // 2. aplicação
        if ((int)$ex['incluir_aplicacao'] === 1) {
            [$bytes, $n, $erro] = backupZipAplicacao($raiz, $pasta . DIRECTORY_SEPARATOR . 'aplicacao.zip', [$destino]);
            $resumo['aplicacao'] = ['bytes' => $bytes, 'arquivos' => $n] + ($erro !== '' ? ['erro' => $erro] : []);
            if ($erro !== '') $avisos[] = 'aplicação: ' . $erro;
            $progresso();
            $vigiar(true);
        }

        // 3. GED (espelho incremental fora da pasta do dia)
        if ((int)$ex['incluir_ged'] === 1) {
            $espelho = $destino . DIRECTORY_SEPARATOR . 'ged_espelho';
            $raizes = ['portal' => (string)($env['GGED_STORAGE_LOCAL_ROOT'] ?? $raiz . DIRECTORY_SEPARATOR . 'storage'), 'legado' => (string)($env['GGED_LEGACY_ROOT'] ?? 'E:\\projetoGreen\\GED')];
            foreach ($raizes as $nome => $orig) {
                $r = backupEspelhar($orig, $espelho . DIRECTORY_SEPARATOR . $nome, ['logs'], [$destino], $vigiar);
                $resumo['ged'][$nome] = $r;
                if (isset($r['aviso'])) $avisos[] = "GED $nome: " . $r['aviso'] . " ($orig)";
                if ($r['erros'] > 0) $avisos[] = "GED $nome: {$r['erros']} arquivo(s) não copiado(s)";
                $progresso();
            }
        }

        // 4. manifesto
        $linhas = []; $total = 0;
        foreach (glob($pasta . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            if (!is_file($f)) continue;
            $tam = (int)filesize($f); $total += $tam;
            $linhas[] = hash_file('sha256', $f) . '  ' . str_pad((string)$tam, 14, ' ', STR_PAD_LEFT) . '  ' . basename($f);
        }
        file_put_contents($pasta . DIRECTORY_SEPARATOR . 'MANIFEST.txt', "GreenPortal — backup #$id de " . date('d/m/Y H:i') . "\nSHA-256  tamanho  arquivo\n" . implode("\n", $linhas) . "\n");

        // 5. rotação (só depois de um backup sem erro nos bancos)
        if (!$erros) {
            $guardados = array_values(array_filter($call('sp_backup_guardados'), static fn($g) => (int)$g['codbackup'] !== $id));
            $guardados[] = ['codbackup' => $id, 'pasta' => $pasta, 'finalizado_em' => (string)$central->query('SELECT NOW()')->fetchColumn(), 'tamanho_bytes' => $total]; // hora do banco, como as outras
            $manter = backupManter($guardados, (int)$ex['manter_diarios'], (int)$ex['manter_semanais']);
            $rot = ['mantidos' => count($manter), 'apagados' => 0, 'falhas' => 0];
            foreach ($guardados as $g) {
                $cod = (int)$g['codbackup'];
                if (isset($manter[$cod]) || $cod === $id) continue;
                $p = (string)$g['pasta'];
                if (!preg_match('/^greenportal_\d{8}_\d{6}$/', basename($p))) continue;
                if (backupApagarPasta($p)) { $call('sp_backup_marcar_removido', [$cod]); $rot['apagados']++; } else $rot['falhas']++;
            }
            if ($rot['falhas']) $avisos[] = "rotação: {$rot['falhas']} pasta(s) antiga(s) não apagada(s)";
            $resumo['rotacao'] = $rot;
        }

        // 6. retenção de exportações e alertas
        if ((int)$ex['retencao'] === 1) {
            $script = $raiz . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'manutencao-retencao-exportacoes-alertas.php';
            foreach ($bancosEmpresas() as $b) {
                $tem = (int)$is->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = " . $is->quote((string)$b) . " AND ROUTINE_NAME IN ('sp_exportacoes_retencao_listar', 'sp_alertas_retencao', 'sp_crud_delete')")->fetchColumn();
                if ($tem < 3) { $resumo['retencao'][$b] = ['aviso' => 'sem as procedures da retenção (migração 281)']; continue; }
                $saida = []; $rc = 0;
                exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --execute --database=' . escapeshellarg((string)$b) . ' 2>&1', $saida, $rc);
                $txt = implode("\n", $saida); $p = strpos($txt, '{'); $j = $p === false ? null : json_decode(substr($txt, $p), true);
                if ($rc !== 0 || !is_array($j)) { $resumo['retencao'][$b] = ['erro' => mb_substr($txt, 0, 400)]; $avisos[] = "retenção $b: falhou"; continue; }
                $resumo['retencao'][$b] = ['exportacoes' => (int)$j['deleted_exports'], 'arquivos' => (int)$j['deleted_files'], 'alertas' => (int)$j['deleted_alerts']];
            }
        }
        $resumo['avisos'] = $avisos;
        file_put_contents($pasta . DIRECTORY_SEPARATOR . 'resumo.json', json_encode($resumo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    } catch (BackupInterrompido $e) {
        $erros[] = $e->getMessage();
        $cancelado = $e->status === 'CANCELADO';
    } catch (Throwable $e) { $erros[] = $e->getMessage(); }

    $status = !empty($cancelado) ? 'CANCELADO' : ($erros ? 'ERRO' : ($avisos ? 'CONCLUIDO_COM_AVISO' : 'CONCLUIDO'));
    $cancelado = false;
    // Backup com erro não é guardado (não serve para restaurar e a rotação só cuida dos concluídos): a pasta do dia é apagada
    if (in_array($status, ['ERRO', 'CANCELADO'], true) && $pasta && preg_match('/^greenportal_\d{8}_\d{6}$/', basename($pasta)) && backupApagarPasta($pasta)) { $total = 0; $erros[] = 'pasta do backup apagada'; }
    $msg = $erros ? implode(' | ', $erros) . ($avisos ? ' | avisos: ' . implode(' | ', $avisos) : '') : ($avisos ? implode(' | ', $avisos) : null);
    try { $call('sp_backup_worker_finalizar', [$id, $status, $pasta, $protegido, $total, json_encode($resumo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $msg !== null ? mb_substr($msg, 0, 4000) : null]); }
    catch (Throwable $e) { $log("ERRO ao gravar o fim do backup #$id: " . $e->getMessage()); }
    $log("backup #$id terminou: $status" . ($msg ? " — $msg" : '') . ($pasta ? " ($pasta, " . round($total / 1048576, 1) . ' MB)' : ''));
    if ($status === 'ERRO') backupAlertar($env, $bancosEmpresas(), 'Backup com erro', "O backup #$id do GreenPortal falhou: " . mb_substr(implode(' | ', $erros), 0, 700) . '. Abra Administração › Backup.', $log);
}
$central->query("SELECT RELEASE_LOCK('greenportal_backup_worker')")->fetchAll();
exit(0);
