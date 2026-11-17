<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';
require_once __DIR__ . '/IdiomaService.php';

/**
 * Migração 349 — Análise de adulteração dos documentos da homologação (Onda 3 da apresentação Atlas: "IA aplicada aos
 * documentos — 3. Análise de adulteração").
 *
 * Cada arquivo novo de documento de fornecedor ou de funcionário (enviado depois da ativação; os antigos pelo botão "Analisar
 * este arquivo" da Gestão de Arquivos) passa por:
 *   - metadados: programa que gerou ou editou o PDF/imagem/Word, datas de criação e modificação, PDF salvo por cima;
 *   - assinatura digital do PDF: o conteúdo confere com a assinatura? o arquivo foi alterado depois da última assinatura?
 *   - imagem (JPEG): regiões com nível de compressão diferente do resto (possível colagem) — indício fraco;
 *   - conteúdo: CNPJ/CPF com dígito verificador inválido, datas impossíveis, emissão no futuro, documento "emitido" depois da
 *     última gravação do arquivo; e a leitura da IA (prompt DOC_ADULTERACAO), que aponta incoerências do texto.
 * Resultado: risco SEM_INDICIOS, BAIXO, MEDIO ou ALTO, com os indícios. A análise só aponta. Risco ALTO sem ciência impede a
 * aprovação do documento até alguém registrar a ciência com o motivo (gatilhos da migração 349).
 *
 * Serviço "ia" do Agendador (scripts/windows-service.php): processar() — varredura a cada VARREDURA_MIN minutos e leituras prontas;
 * aoConcluirJob() — chamado pela fila de IA quando o pedido DOC_ADULTERACAO termina. Banco só por procedures e views.
 */
final class HomologacaoDocumentoAdulteracao
{
    public const ESCOPOS = [
        'FORNECEDOR' => ['ged' => 'fornecedorcriterios', 'pasta' => 'fo_fornecedorxcriterios', 'chave' => 'CODFORNECEDORXCRITERIOS', 'registro' => 'sp_docfornecedor_ia_registro'],
        'FUNCIONARIO' => ['ged' => 'funcionariocriterios', 'pasta' => 'fo_funcionarioxtiposxcriterios', 'chave' => 'CODIGO', 'registro' => 'sp_docfuncionario_ia_registro'],
    ];
    public const TIPO_GED = ['fornecedorcriterios' => 'FORNECEDOR', 'funcionariocriterios' => 'FUNCIONARIO'];
    public const EXTENSOES = ['pdf', 'jpg', 'jpeg', 'png', 'tif', 'tiff', 'bmp', 'doc', 'docx'];
    public const MAX_BYTES = 26214400;
    public const LOTE = 30;
    public const VARREDURA_MIN = 10;
    public const OCR_ESPERA_HORAS = 6;
    public const TIPO_IA = 'DOC_ADULTERACAO';
    public const PROMPT = 'DOC_ADULTERACAO';
    public const RISCOS = ['SEM_INDICIOS', 'BAIXO', 'MEDIO', 'ALTO'];

    /** Programas de edição de imagem (no PDF, na imagem ou no histórico XMP). */
    private const EDITORES_IMAGEM = ['photoshop', 'gimp', 'paint.net', 'pixlr', 'canva', 'corel', 'affinity photo', 'picsart', 'snapseed', 'fotor', 'krita', 'photopea', 'lightroom'];
    /** Programas e sites de edição de PDF. */
    private const EDITORES_PDF = ['ilovepdf', 'sejda', 'pdfescape', 'smallpdf', 'pdf-xchange editor', 'pdfelement', 'wondershare', 'nitro', 'foxit phantompdf', 'foxit pdf editor', 'inkscape', 'pdf candy', 'pdffiller', 'dochub', 'soda pdf', 'pdf24', 'master pdf editor', 'pdf editor'];
    private const EDITORES_TEXTO = ['microsoft word', 'libreoffice', 'openoffice', 'wps office', 'google docs'];

    public static function disponivel(PDO $pdo): bool
    {
        try {
            $s = $pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME IN ('sp_doc_adult_candidatos', 'sp_doc_adult_registrar', 'sp_doc_adult_concluir')");
            $n = (int)$s->fetchColumn(); $s->closeCursor();
            return $n === 3;
        } catch (Throwable) { return false; }
    }

    public static function chamar(PDO $pdo, string $sql, array $p = []): array
    {
        $s = $pdo->prepare($sql); $s->execute($p);
        $r = [];
        try { $r = $s->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable) {}
        while ($s->nextRowset()) { try { $s->fetchAll(); } catch (Throwable) {} }
        $s->closeCursor();
        return $r;
    }

    // ------------------------------------------------------------------ serviço "ia" do Agendador

    public static function processar(PDO $pdo, array $env, string $banco): int
    {
        if (!self::disponivel($pdo)) return 0;
        $n = 0;
        try {
            $ultima = (string)(self::chamar($pdo, "SELECT VALOR FROM vw_doc_adult_controle WHERE CHAVE = 'ULTIMA_VARREDURA'")[0]['VALOR'] ?? '');
            $agora = (int)(self::chamar($pdo, 'SELECT UNIX_TIMESTAMP() AS T')[0]['T'] ?? time());
            if ($ultima === '' || $agora - (int)$ultima >= self::VARREDURA_MIN * 60) {
                self::chamar($pdo, 'CALL sp_doc_adult_controle(?, ?)', ['ULTIMA_VARREDURA', (string)$agora]);
                $n += self::varrer($pdo, $env, $banco, (int)$ultima);
            }
        } catch (Throwable $e) { error_log('[adulteração] varredura ' . $banco . ': ' . $e->getMessage()); }
        try { $n += self::preparar($pdo, $env, $banco); } catch (Throwable $e) { error_log('[adulteração] preparar ' . $banco . ': ' . $e->getMessage()); }
        return $n;
    }

    private static function ged(PDO $pdo, array $env, string $banco): GedStorageService
    {
        require_once __DIR__ . '/GedStorageService.php';
        require_once __DIR__ . '/HomologacaoBancos.php';
        $legado = HomologacaoBancos::legado($banco);
        return new GedStorageService($pdo, $env, ['tenant' => ['banco_dados' => $banco] + ($legado !== null ? ['banco_legado' => $legado] : [])]);
    }

    /**
     * Arquivos novos dos documentos (enviados depois da ativação): registra a análise e põe o arquivo na leitura (OCR). Só abre as
     * pastas que mudaram desde a última varredura (a pasta muda quando chega arquivo). Também marca os arquivos que saíram do GED.
     */
    public static function varrer(PDO $pdo, array $env, string $banco, int $desdeVarredura = 0, int $lote = self::LOTE): int
    {
        require_once __DIR__ . '/OcrQueueService.php';
        $ged = self::ged($pdo, $env, $banco);
        $ocr = new OcrQueueService($pdo, $env);
        $ativado = (int)(self::chamar($pdo, "SELECT VALOR FROM vw_doc_adult_controle WHERE CHAVE = 'ATIVADO_TS'")[0]['VALOR'] ?? 0);
        $postos = 0;
        foreach (self::ESCOPOS as $escopo => $cfg) {
            $cands = array_map(static fn($c) => (int)$c['CODIGO'], self::chamar($pdo, 'CALL sp_doc_adult_candidatos(?, ?)', [$escopo, 50000]));
            if (!$cands) continue;
            $ja = [];
            foreach (array_chunk($cands, 1000) as $parte)
                foreach (self::chamar($pdo, 'CALL sp_doc_adult_hashes(?, ?)', [$escopo, json_encode($parte)]) as $h) { $ja[(int)$h['CODIGO']]['n'][(string)$h['ARQUIVO']] = (int)$h['ARQUIVO_PRESENTE']; $ja[(int)$h['CODIGO']]['h'][(string)$h['HASH']] = true; }
            foreach ($cands as $codigo) {
                $nomesPresentes = []; $mudou = false;
                foreach ($ged->folders($cfg['pasta'], $codigo) as $dir) {
                    if (!is_dir($dir)) continue;
                    // Pasta sem mudança desde a última varredura: nada novo (a não ser que já tenha análises para conferir a presença)
                    $mt = (int)@filemtime($dir);
                    if ($desdeVarredura > 0 && $mt > 0 && $mt < $desdeVarredura - 60 && !isset($ja[$codigo])) continue;
                    $nomes = @scandir($dir);
                    if ($nomes === false) continue;
                    foreach ($nomes as $n) {
                        if ($n === '.' || $n === '..') continue;
                        $f = new SplFileInfo($dir . DIRECTORY_SEPARATOR . $n);
                        if (!$f->isFile()) continue;
                        $nome = $f->getFilename(); $nomesPresentes[] = $nome;
                        if ($postos >= $lote || isset($ja[$codigo]['n'][$nome])) continue;
                        if (!in_array(strtolower(pathinfo($nome, PATHINFO_EXTENSION)), self::EXTENSOES, true) || $f->getSize() > self::MAX_BYTES || $f->getSize() < 1) continue;
                        if ($ativado > 0 && $f->getMTime() < $ativado) continue; // anterior à ativação: só pelo botão
                        $hash = (string)hash_file('sha256', $f->getPathname());
                        if ($hash === '' || isset($ja[$codigo]['h'][$hash])) continue;
                        $ja[$codigo]['n'][$nome] = 1; $ja[$codigo]['h'][$hash] = true;
                        if (self::registrar($pdo, $ocr, $escopo, $codigo, $nome, $f->getPathname(), $f->getSize(), $hash, 'AUTOMATICA', 0)) $postos++;
                    }
                    $mudou = true;
                }
                // Arquivos que saíram do GED deixam de contar para o bloqueio da aprovação
                if ($mudou && isset($ja[$codigo])) {
                    $antes = array_keys(array_filter($ja[$codigo]['n'] ?? [], static fn($p) => $p === 1));
                    $saiu = array_diff($antes, $nomesPresentes); $voltou = array_intersect(array_keys(array_filter($ja[$codigo]['n'] ?? [], static fn($p) => $p === 0)), $nomesPresentes);
                    if ($saiu || $voltou) self::chamar($pdo, 'CALL sp_doc_adult_presenca(?, ?, ?)', [$escopo, $codigo, json_encode(array_values($nomesPresentes), JSON_UNESCAPED_UNICODE)]);
                }
            }
        }
        return $postos;
    }

    /** Registra o arquivo e pede a leitura (a mesma chave da Gestão de Arquivos e da validação por modelo: leitura reaproveitada). */
    private static function registrar(PDO $pdo, OcrQueueService $ocr, string $escopo, int $codigo, string $nome, string $caminho, int $tamanho, string $hash, string $origem, int $usuario): ?array
    {
        $cfg = self::ESCOPOS[$escopo];
        $dados = ['chave_idempotencia' => 'GED_EXTRACAO:' . $cfg['ged'] . ':' . $codigo . ':' . $nome . ':' . $hash, 'tipo' => strtoupper($cfg['ged']), 'entidade' => $cfg['pasta'],
            'registro_id' => (string)$codigo, 'arquivo' => $nome, 'caminho_arquivo' => $caminho, 'tamanho_bytes' => $tamanho, 'codusuario' => $usuario ?: null];
        $job = $ocr->enqueue($dados);
        if (in_array((string)($job['status'] ?? ''), ['ERRO', 'CANCELADA'], true)) $job = $ocr->enqueue(['chave_idempotencia' => substr($dados['chave_idempotencia'], 0, 170) . ':R' . date('YmdHis')] + $dados);
        $r = self::chamar($pdo, 'CALL sp_doc_adult_registrar(?, ?, ?, ?, ?, ?, ?)', [$escopo, $codigo, $nome, $hash, $origem, $usuario, (int)($job['codjob'] ?? 0)])[0] ?? null;
        return $r && (int)$r['NOVO'] === 1 ? $r : null;
    }

    /**
     * Pedido pela tela (botão "Analisar este arquivo"): vale para arquivos anteriores à ativação e para refazer uma análise com erro.
     * Devolve ['codanalise', 'novo', 'status'].
     */
    public static function pedirManual(PDO $pdo, array $env, string $banco, string $escopo, int $codigo, string $caminho, int $usuario): array
    {
        require_once __DIR__ . '/OcrQueueService.php';
        if (!isset(self::ESCOPOS[$escopo]) || !is_file($caminho)) throw new RuntimeException('Arquivo não encontrado.');
        $nome = basename($caminho);
        if (!in_array(strtolower(pathinfo($nome, PATHINFO_EXTENSION)), self::EXTENSOES, true)) throw new RuntimeException('Formato sem análise de adulteração (use PDF, imagem ou Word).');
        if (filesize($caminho) > self::MAX_BYTES) throw new RuntimeException('Arquivo maior que 25 MB.');
        $hash = (string)hash_file('sha256', $caminho);
        $r = self::registrar($pdo, new OcrQueueService($pdo, $env), $escopo, $codigo, $nome, $caminho, (int)filesize($caminho), $hash, 'MANUAL', $usuario);
        if ($r) return ['codanalise' => (int)$r['CODANALISE'], 'novo' => true, 'status' => (string)$r['STATUS']];
        $e = self::chamar($pdo, 'CALL sp_doc_adult_hashes(?, ?)', [$escopo, json_encode([$codigo])]);
        foreach ($e as $x) if ((string)$x['HASH'] === $hash) return ['codanalise' => (int)$x['CODANALISE'], 'novo' => false, 'status' => ''];
        throw new RuntimeException('Não foi possível registrar o pedido.');
    }

    /** Leituras prontas: verificações locais e, com a IA disponível, o pedido à IA. Sem IA, conclui só com as verificações locais. */
    public static function preparar(PDO $pdo, array $env, string $banco, int $limite = 10): int
    {
        require_once __DIR__ . '/DocumentAiQueueService.php';
        require_once __DIR__ . '/FuncionarioDocumentoTexto.php';
        $feitos = 0; $ged = null;
        foreach (self::chamar($pdo, 'CALL sp_doc_adult_aguardando(?)', [$limite]) as $a) {
            $cod = (int)$a['CODANALISE']; $escopo = (string)$a['ESCOPO']; $cfg = self::ESCOPOS[$escopo] ?? null; $codigo = (int)$a['CODIGO'];
            if (!$cfg) { self::erro($pdo, $cod, 'Escopo inválido.'); continue; }
            $st = (string)($a['OCR_STATUS'] ?? '');
            if (in_array($st, ['PENDENTE', 'PROCESSANDO'], true) && (int)$a['IDADE_MIN'] < self::OCR_ESPERA_HORAS * 60) continue;
            try {
                $ged ??= self::ged($pdo, $env, $banco);
                $caminho = $ged->resolve($cfg['pasta'], $codigo, (string)$a['ARQUIVO']);
                if ($caminho === null || !is_file($caminho)) {
                    self::chamar($pdo, 'CALL sp_doc_adult_arquivo_excluido(?, ?, ?)', [$escopo, $codigo, (string)$a['ARQUIVO']]);
                    self::erro($pdo, $cod, 'O arquivo não está mais no GED.'); continue;
                }
                $registro = self::chamar($pdo, 'CALL ' . $cfg['registro'] . '(?)', [$codigo])[0] ?? [];
                $texto = ''; $confianca = 0.0; $semTexto = null;
                if ($st === 'CONCLUIDA') {
                    $o = self::chamar($pdo, 'CALL sp_doc_adult_ocr_texto(?)', [(int)$a['OCR_JOB']])[0] ?? [];
                    $texto = trim((string)($o['texto'] ?? '')); $confianca = (float)($o['confianca'] ?? 0);
                } else $semTexto = $st === 'ERRO' ? 'Não foi possível ler o texto do arquivo' . (trim((string)($a['OCR_ERRO'] ?? '')) !== '' ? ': ' . mb_substr((string)$a['OCR_ERRO'], 0, 200) : '') : 'A leitura do texto (OCR) não terminou a tempo';
                if ($escopo === 'FUNCIONARIO' && $texto !== '') $texto = FuncionarioDocumentoTexto::limpar($texto)['texto'];
                $v = self::verificar($caminho, $texto, $confianca, $registro);
                if ($semTexto === null && $texto === '') $semTexto = 'O arquivo não tem texto legível';
                if ($semTexto) $v['indicios'][] = self::ind('CONTEUDO', 'SEM_TEXTO', 'INFO', $semTexto . ': o conteúdo não foi conferido.');
                // IA: só com texto legível, prompt cadastrado e limite mensal disponível
                $prompt = $texto !== '' ? trim((string)(self::chamar($pdo, 'CALL sp_ia_prompt_obter(?, ?)', [self::PROMPT, 'pt'])[0]['prompt'] ?? '')) : '';
                $motivoSemIa = $texto === '' ? null : ($prompt === '' ? 'Prompt ' . self::PROMPT . ' não configurado (Setup › Prompts de IA): conteúdo conferido só pelas regras fixas.' : null);
                if ($prompt !== '') {
                    try {
                        $job = (new DocumentAiQueueService($pdo))->enqueue(['chave_idempotencia' => 'IA:ADULT:' . $cod . ':' . substr((string)$a['HASH'], 0, 16), 'tipo' => self::TIPO_IA, 'entidade' => $cfg['pasta'],
                            'registro_id' => (string)$codigo, 'arquivo' => (string)$a['ARQUIVO'], 'codanalise_ged' => 0, 'codusuario' => (int)($a['CODUSUARIO_PEDIDO'] ?? 0), 'idioma' => 'pt',
                            'prompt' => $prompt, 'texto' => self::contextoIa($escopo, $registro, $v) . "---\n" . mb_substr($texto, 0, 24000)]);
                        self::chamar($pdo, 'CALL sp_doc_adult_ia(?, ?, ?)', [$cod, json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), (int)$job['codjob']]);
                        $feitos++;
                        if (in_array((string)($job['status'] ?? ''), ['CONCLUIDA', 'ERRO'], true)) self::aoConcluirJob($pdo, ['codjob' => (int)$job['codjob'], 'tipo' => self::TIPO_IA]);
                        continue;
                    } catch (IaLimiteExcedido) { $motivoSemIa = 'Limite mensal de IA da empresa atingido: conteúdo conferido só pelas regras fixas.'; }
                }
                if ($motivoSemIa) $v['indicios'][] = self::ind('IA', 'SEM_IA', 'INFO', $motivoSemIa);
                self::concluir($pdo, $cod, $v, [], false, $motivoSemIa);
                $feitos++;
            } catch (Throwable $e) { error_log('[adulteração] análise ' . $cod . ': ' . $e->getMessage()); self::erro($pdo, $cod, mb_substr($e->getMessage(), 0, 500)); }
        }
        return $feitos;
    }

    private static function erro(PDO $pdo, int $cod, string $msg): void
    {
        try { self::chamar($pdo, 'CALL sp_doc_adult_erro(?, ?)', [$cod, $msg]); } catch (Throwable $e) { error_log('[adulteração] ' . $e->getMessage()); }
    }

    /** Fila de IA (DocumentAiQueueService::notificar): o pedido DOC_ADULTERACAO terminou. Junta os indícios da IA aos locais e conclui. */
    public static function aoConcluirJob(PDO $pdo, array $job): void
    {
        if ((string)($job['tipo'] ?? '') !== self::TIPO_IA || !self::disponivel($pdo)) return;
        $a = self::chamar($pdo, 'CALL sp_doc_adult_por_job(?)', [(int)$job['codjob']])[0] ?? null;
        if (!$a) return;
        $cod = (int)$a['CODANALISE'];
        $v = json_decode((string)($a['LOCAIS'] ?? ''), true) ?: ['indicios' => [], 'tecnico' => []];
        if ((string)$a['IA_STATUS'] === 'ERRO') {
            $v['indicios'][] = self::ind('IA', 'IA_ERRO', 'INFO', 'A IA não respondeu (' . mb_substr((string)$a['IA_ERRO'], 0, 160) . '): conteúdo conferido só pelas regras fixas.');
            self::concluir($pdo, $cod, $v, [], false, 'IA: ' . mb_substr((string)$a['IA_ERRO'], 0, 400));
            return;
        }
        if ((string)$a['IA_STATUS'] !== 'CONCLUIDA') return; // nova tentativa da fila
        $r = json_decode((string)$a['IA_RESULTADO'], true);
        $s = is_array($r['sugestao'] ?? null) ? $r['sugestao'] : [];
        self::concluir($pdo, $cod, $v, $s, true, null);
    }

    /** Junta indícios locais e da IA, calcula o risco, grava e avisa o responsável (risco médio ou alto). */
    private static function concluir(PDO $pdo, int $cod, array $v, array $ia, bool $iaConsultada, ?string $mensagem): void
    {
        $indicios = $v['indicios'] ?? [];
        foreach (array_slice((array)($ia['indicios'] ?? []), 0, 8) as $x) {
            if (!is_array($x)) continue;
            $g = strtoupper(trim((string)($x['gravidade'] ?? '')));
            $g = in_array($g, ['BAIXA', 'MEDIA', 'MÉDIA', 'ALTA'], true) ? str_replace('É', 'E', $g) : 'BAIXA';
            $t = trim((string)preg_replace('/\s+/u', ' ', (string)($x['descricao'] ?? '')));
            if ($t === '') continue;
            $ev = trim((string)($x['evidencia'] ?? ''));
            $indicios[] = self::ind('IA', 'IA_' . strtoupper(substr(preg_replace('/[^A-Za-z_]/', '', (string)($x['tipo'] ?? 'CONTEUDO')) ?: 'CONTEUDO', 0, 30)), $g, mb_substr($t, 0, 400), $ev !== '' ? mb_substr($ev, 0, 200) : null);
        }
        $risco = self::risco($indicios);
        $resumo = self::resumo($risco, $indicios, trim((string)($ia['resumo'] ?? '')));
        $v['indicios'] = $indicios;
        $row = self::chamar($pdo, 'CALL sp_doc_adult_concluir(?, ?, ?, ?, ?, ?, ?)', [$cod, $risco, json_encode($indicios, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), mb_substr($resumo, 0, 1000), $iaConsultada ? 1 : 0, $mensagem])[0] ?? null;
        if (!$row || !in_array($risco, ['MEDIO', 'ALTO'], true)) return;
        try { self::alertar($pdo, $row, $risco, $resumo); } catch (Throwable $e) { error_log('[adulteração] alerta ' . $cod . ': ' . $e->getMessage()); }
    }

    private static function alertar(PDO $pdo, array $row, string $risco, string $resumo): void
    {
        require_once __DIR__ . '/GedAnaliseIaRegistro.php';
        $escopo = (string)$row['ESCOPO']; $cfg = self::ESCOPOS[$escopo];
        $destinos = array_filter([(int)($row['CODUSUARIO_EMPRESA'] ?? 0)]);
        if (!$destinos) {
            // Sem responsável da empresa pelo documento: os ADM DA EMPRESA (migração 345), quando a view existe
            try { foreach (self::chamar($pdo, 'SELECT CODUSUARIO FROM vw_resp_auto_adm') as $u) $destinos[] = (int)$u['CODUSUARIO']; } catch (Throwable) {}
        }
        $doc = $escopo === 'FORNECEDOR' ? trim((string)$row['RAZAOSOCIAL']) . ': ' . trim((string)$row['DSCRITERIO'])
            : trim((string)$row['FUNCIONARIO']) . ' (' . trim((string)$row['RAZAOSOCIAL']) . '): ' . trim((string)$row['DSCRITERIO']);
        $titulo = ($risco === 'ALTO' ? 'Risco alto de adulteração — ' : 'Indícios de adulteração — ') . trim((string)$row['DSCRITERIO']);
        $msg = $doc . ' — arquivo ' . (string)$row['ARQUIVO'] . '. ' . $resumo . ($risco === 'ALTO' ? ' O documento só pode ser aprovado depois da ciência com o motivo.' : '') . ' Revise o arquivo (a análise só aponta).';
        $link = str_replace('aba=analises', 'aba=adulteracao', GedAnaliseIaRegistro::link($cfg['ged'], $cfg['chave'], (int)$row['CODIGO'], (string)$row['ARQUIVO']));
        foreach (array_unique($destinos) as $u)
            self::chamar($pdo, "CALL sp_alerta_criar(?, ?, ?, ?, 'NAO')", [$u, mb_substr($titulo, 0, 180), mb_substr($msg, 0, 1000), $link]);
    }

    // ------------------------------------------------------------------ risco

    public static function ind(string $grupo, string $codigo, string $gravidade, string $texto, ?string $evidencia = null): array
    {
        return ['grupo' => $grupo, 'codigo' => $codigo, 'gravidade' => $gravidade, 'texto' => $texto] + ($evidencia !== null ? ['evidencia' => $evidencia] : []);
    }

    /** ALTA → ALTO; três ou mais MEDIA → ALTO; uma MEDIA → MEDIO; só BAIXA → BAIXO; nada (ou só INFO) → SEM_INDICIOS. */
    public static function risco(array $indicios): string
    {
        $c = ['ALTA' => 0, 'MEDIA' => 0, 'BAIXA' => 0];
        foreach ($indicios as $i) { $g = (string)($i['gravidade'] ?? ''); if (isset($c[$g])) $c[$g]++; }
        if ($c['ALTA'] > 0 || $c['MEDIA'] >= 3) return 'ALTO';
        if ($c['MEDIA'] > 0) return 'MEDIO';
        return $c['BAIXA'] > 0 ? 'BAIXO' : 'SEM_INDICIOS';
    }

    private static function resumo(string $risco, array $indicios, string $resumoIa): string
    {
        $nome = ['SEM_INDICIOS' => 'Sem indícios de adulteração', 'BAIXO' => 'Risco baixo', 'MEDIO' => 'Risco médio', 'ALTO' => 'Risco alto'][$risco];
        $ordem = ['ALTA' => 0, 'MEDIA' => 1, 'BAIXA' => 2];
        $l = array_values(array_filter($indicios, static fn($i) => isset($ordem[$i['gravidade'] ?? ''])));
        usort($l, static fn($a, $b) => $ordem[$a['gravidade']] <=> $ordem[$b['gravidade']]);
        $txt = $nome . ($l ? ': ' . implode('; ', array_map(static fn($i) => rtrim((string)$i['texto'], '.'), array_slice($l, 0, 3))) . (count($l) > 3 ? ' (e mais ' . (count($l) - 3) . ')' : '') : '') . '.';
        if ($risco === 'SEM_INDICIOS' && $resumoIa !== '') $txt .= ' ' . mb_substr($resumoIa, 0, 300);
        return $txt;
    }

    private static function contextoIa(string $escopo, array $registro, array $v): string
    {
        $l = [];
        $l[] = $escopo === 'FORNECEDOR'
            ? 'DOCUMENTO EXIGIDO: ' . ($registro['DSCRITERIO'] ?? '') . "\nFORNECEDOR: " . ($registro['RAZAOSOCIAL'] ?? '') . (preg_match('/\d/', (string)($registro['CNPJ'] ?? '')) ? ' (CNPJ ' . $registro['CNPJ'] . ')' : '')
            : 'CRITÉRIO EXIGIDO: ' . ($registro['DSCRITERIO'] ?? '') . "\nFUNCIONÁRIO: " . ($registro['FUNCIONARIO'] ?? '') . "\nEMPRESA: " . ($registro['RAZAOSOCIAL'] ?? '');
        $l[] = 'DATA DE HOJE: ' . date('Y-m-d');
        $t = $v['tecnico'] ?? [];
        $meta = [];
        foreach (['formato', 'produtor', 'criador', 'ferramentas', 'criado_em', 'modificado_em', 'revisoes', 'assinaturas', 'software'] as $k) if (!empty($t[$k])) $meta[] = $k . ': ' . (is_array($t[$k]) ? json_encode($t[$k], JSON_UNESCAPED_UNICODE) : (string)$t[$k]);
        if ($meta) $l[] = 'METADADOS DO ARQUIVO: ' . implode('; ', $meta);
        $loc = array_filter($v['indicios'] ?? [], static fn($i) => ($i['gravidade'] ?? '') !== 'INFO');
        if ($loc) $l[] = 'INDÍCIOS TÉCNICOS JÁ APONTADOS (não repita): ' . implode('; ', array_map(static fn($i) => rtrim((string)$i['texto'], '.'), $loc)) . '.';
        return implode("\n", $l) . "\n";
    }

    // ------------------------------------------------------------------ verificações locais

    /**
     * Verificações sem IA. $registro: cadastro do documento (CNPJ/CPF do cadastro). Devolve ['indicios' => [...], 'tecnico' => [...]].
     */
    public static function verificar(string $caminho, string $texto, float $confianca, array $registro = [], ?string $hoje = null): array
    {
        $hoje ??= date('Y-m-d');
        $ext = strtolower(pathinfo($caminho, PATHINFO_EXTENSION));
        $ind = []; $tec = ['formato' => strtoupper($ext)];
        $arq = null;
        try {
            if ($ext === 'pdf') $arq = self::pdf($caminho, $ind, $tec);
            elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'tif', 'tiff', 'bmp'], true)) self::imagem($caminho, $ext, $ind, $tec);
            elseif ($ext === 'docx') self::docx($caminho, $ind, $tec);
            elseif ($ext === 'doc') $ind[] = self::ind('METADADOS', 'FORMATO_EDITAVEL', 'INFO', 'Documento em formato editável (Word): confira se veio do emissor.');
        } catch (Throwable $e) { $ind[] = self::ind('METADADOS', 'LEITURA', 'INFO', 'Não foi possível ler os metadados do arquivo: ' . mb_substr($e->getMessage(), 0, 160)); }
        if ($texto !== '') self::texto($texto, $confianca, $registro, $hoje, $arq, $ind, $tec);
        return ['indicios' => $ind, 'tecnico' => $tec];
    }

    // ---- PDF

    /** Metadados, revisões e assinaturas do PDF. Devolve as datas do arquivo (para comparar com a emissão escrita no texto). */
    private static function pdf(string $caminho, array &$ind, array &$tec): array
    {
        $b = (string)file_get_contents($caminho);
        if (!str_starts_with(ltrim(substr($b, 0, 1024)), '%PDF')) { $ind[] = self::ind('METADADOS', 'PDF_INVALIDO', 'MEDIA', 'O arquivo tem extensão .pdf mas não é um PDF.'); return []; }
        $busca = $b . "\n" . self::pdfStreamsInternos($b);
        $info = [];
        foreach (['Producer', 'Creator', 'CreationDate', 'ModDate', 'Author', 'Title'] as $k)
            if (preg_match_all('/\/' . $k . '\s*(\((?:\\\\.|[^\\\\)])*\)|<[0-9A-Fa-f\s]*>)/s', $busca, $m) && $m[1]) $info[$k] = self::pdfString(end($m[1]));
        $xmp = [];
        if (preg_match('/<x:xmpmeta.*?<\/x:xmpmeta>/s', $busca, $xm)) {
            $x = $xm[0];
            foreach (['CreatorTool' => '/xmp:CreatorTool(?:>|=")([^<"]*)/', 'Producer' => '/pdf:Producer(?:>|=")([^<"]*)/', 'CreateDate' => '/xmp:CreateDate(?:>|=")([^<"]*)/', 'ModifyDate' => '/xmp:ModifyDate(?:>|=")([^<"]*)/'] as $k => $re)
                if (preg_match($re, $x, $q)) $xmp[$k] = html_entity_decode(trim($q[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
            if (preg_match_all('/stEvt:softwareAgent(?:>|=")([^<"]*)/', $x, $q)) $xmp['agentes'] = array_values(array_unique(array_map(static fn($s) => html_entity_decode(trim($s), ENT_QUOTES | ENT_XML1, 'UTF-8'), $q[1])));
        }
        $produtor = trim((string)($info['Producer'] ?? $xmp['Producer'] ?? ''));
        $criador = trim((string)($info['Creator'] ?? $xmp['CreatorTool'] ?? ''));
        $ferramentas = array_values(array_unique(array_filter(array_merge([$produtor, $criador, (string)($xmp['CreatorTool'] ?? ''), (string)($xmp['Producer'] ?? '')], $xmp['agentes'] ?? []), static fn($s) => trim($s) !== '')));
        $criado = self::pdfData($info['CreationDate'] ?? null) ?? self::isoData($xmp['CreateDate'] ?? null);
        $modificado = self::pdfData($info['ModDate'] ?? null) ?? self::isoData($xmp['ModifyDate'] ?? null);
        // Gravações: cada atualização incremental acrescenta objetos e um %%EOF. Não contam: o trecho final só com a tabela de
        // referências (arquivos "híbridos" do Office) e a primeira parte do PDF linearizado (web), que já nasce com dois %%EOF.
        $partes = preg_split('/%%EOF\s*/', $b) ?: [$b];
        $revisoes = 1;
        foreach (array_slice($partes, 1) as $parte) if (preg_match('/\d+\s+\d+\s+obj\b/', $parte)) $revisoes++;
        if ($revisoes > 1 && preg_match('/\/Linearized\s/', substr($b, 0, 2048))) $revisoes--;
        $tec += ['produtor' => $produtor, 'criador' => $criador, 'ferramentas' => $ferramentas, 'criado_em' => $criado?->format('Y-m-d H:i'), 'modificado_em' => $modificado?->format('Y-m-d H:i'), 'revisoes' => $revisoes];
        if ($produtor === '' && $criador === '' && !$xmp) $ind[] = self::ind('METADADOS', 'SEM_METADADOS', 'INFO', 'O PDF não informa o programa que o gerou (metadados ausentes ou removidos).');
        self::ferramentas($ferramentas, 'PDF', $ind);
        if ($criado && $modificado) {
            $dias = ($modificado->getTimestamp() - $criado->getTimestamp()) / 86400;
            if ($dias > 1) $ind[] = self::ind('METADADOS', 'PDF_MODIFICADO', 'BAIXA', 'PDF modificado ' . self::dias((int)floor($dias)) . ' depois de criado (criado em ' . $criado->format('d/m/Y') . ', modificado em ' . $modificado->format('d/m/Y') . ').');
            elseif ($dias < -1) $ind[] = self::ind('METADADOS', 'PDF_DATAS', 'BAIXA', 'A data de modificação do PDF é anterior à de criação.');
        }
        if ($criado && $criado->getTimestamp() > time() + 86400) $ind[] = self::ind('METADADOS', 'PDF_FUTURO', 'BAIXA', 'A data de criação do PDF está no futuro (' . $criado->format('d/m/Y') . ').');
        $assinaturas = self::pdfAssinaturas($b, $ind);
        if ($assinaturas) $tec['assinaturas'] = $assinaturas;
        elseif ($revisoes > 1) $ind[] = self::ind('METADADOS', 'PDF_REVISOES', 'BAIXA', 'O PDF foi salvo ' . $revisoes . ' vezes (alterações acrescentadas ao arquivo original).');
        return ['criado' => $criado, 'modificado' => $modificado];
    }

    /** Programa de edição de imagem ou de PDF entre os que geraram/editaram o arquivo. */
    private static function ferramentas(array $ferramentas, string $tipo, array &$ind): void
    {
        $achou = static function (array $lista) use ($ferramentas): ?string { foreach ($ferramentas as $f) foreach ($lista as $e) if (str_contains(mb_strtolower($f), $e)) return $f; return null; };
        if ($f = $achou(self::EDITORES_IMAGEM)) $ind[] = self::ind('METADADOS', $tipo . '_EDITOR_IMAGEM', 'MEDIA', ($tipo === 'PDF' ? 'PDF gerado ou editado' : 'Imagem gerada ou editada') . ' em programa de edição de imagem (' . mb_substr($f, 0, 80) . ').');
        if ($tipo === 'PDF' && ($f = $achou(self::EDITORES_PDF))) $ind[] = self::ind('METADADOS', 'PDF_EDITOR', 'MEDIA', 'PDF passou por editor de PDF (' . mb_substr($f, 0, 80) . '): confira se o conteúdo não foi alterado.');
        if ($tipo === 'PDF' && ($f = $achou(self::EDITORES_TEXTO))) $ind[] = self::ind('METADADOS', 'PDF_EDITOR_TEXTO', 'INFO', 'PDF gerado em editor de texto (' . mb_substr($f, 0, 80) . '): normal para declarações, incomum para certidões e documentos de órgãos.');
    }

    /** Conteúdo dos streams de objetos e de metadados (comprimidos), onde ficam o /Info e o XMP em PDFs modernos. */
    private static function pdfStreamsInternos(string $b): string
    {
        $out = ''; $n = 0;
        if (!function_exists('gzuncompress')) return '';
        if (preg_match_all('/<<((?:(?!>>\s*stream).){0,800}?)>>\s*stream\r?\n/s', $b, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $i => [$dict, $pos]) {
                if ($n >= 300) break;
                if (!preg_match('/\/Type\s*\/(ObjStm|Metadata)/', $dict) && !str_contains($dict, '/Metadata')) continue;
                $ini = $m[0][$i][1] + strlen($m[0][$i][0]);
                $fim = strpos($b, 'endstream', $ini); if ($fim === false) continue;
                $raw = rtrim(substr($b, $ini, $fim - $ini), "\r\n");
                if (str_contains($dict, '/FlateDecode')) { $d = @gzuncompress($raw); if ($d === false) $d = @gzinflate(substr($raw, 2)); $raw = $d === false ? '' : $d; }
                elseif (preg_match('/\/Filter/', $dict)) continue;
                $out .= "\n" . substr($raw, 0, 400000); $n++;
                if (strlen($out) > 8000000) break; // o /Info e o XMP ficam no começo; não precisa ler tudo
            }
        }
        return $out;
    }

    private static function pdfString(string $s): string
    {
        $s = trim($s);
        if (str_starts_with($s, '<')) $bin = (string)@hex2bin((string)preg_replace('/[^0-9A-Fa-f]/', '', substr($s, 1, -1)));
        else {
            $in = substr($s, 1, -1); $bin = '';
            for ($i = 0, $n = strlen($in); $i < $n; $i++) {
                $c = $in[$i];
                if ($c !== '\\') { $bin .= $c; continue; }
                $d = $in[++$i] ?? '';
                if (ctype_digit($d) && $d < '8') { $o = $d; while (strlen($o) < 3 && isset($in[$i + 1]) && ctype_digit($in[$i + 1]) && $in[$i + 1] < '8') $o .= $in[++$i]; $bin .= chr(octdec($o) & 255); }
                else $bin .= ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C"][$d] ?? ($d === "\n" || $d === "\r" ? '' : $d);
            }
        }
        if (str_starts_with($bin, "\xFE\xFF")) $bin = (string)@mb_convert_encoding(substr($bin, 2), 'UTF-8', 'UTF-16BE');
        elseif (!mb_check_encoding($bin, 'UTF-8')) $bin = (string)@mb_convert_encoding($bin, 'UTF-8', 'ISO-8859-1');
        return trim((string)preg_replace('/[\x00-\x1F]+/', ' ', $bin));
    }

    private static function pdfData(?string $v): ?DateTimeImmutable
    {
        if (!$v || !preg_match('/^(?:D:)?(\d{4})(\d{2})?(\d{2})?(\d{2})?(\d{2})?(\d{2})?([Zz+\-])?(\d{2})?\'?(\d{2})?/', trim($v), $m)) return null;
        $tz = 'UTC';
        if (!empty($m[7]) && in_array($m[7], ['+', '-'], true)) $tz = $m[7] . ($m[8] ?? '00') . ':' . (($m[9] ?? '') !== '' ? $m[9] : '00');
        try { return new DateTimeImmutable(sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2] ?: '01', $m[3] ?: '01', $m[4] ?: '00', $m[5] ?: '00', $m[6] ?: '00'), new DateTimeZone($tz)); }
        catch (Throwable) { return null; }
    }

    private static function isoData(?string $v): ?DateTimeImmutable
    {
        if (!$v || !preg_match('/^\d{4}-\d{2}-\d{2}/', trim($v))) return null;
        try { return new DateTimeImmutable(trim($v)); } catch (Throwable) { return null; }
    }

    /**
     * Assinaturas digitais (PAdES / PKCS#7): para cada /ByteRange confere se cobre o arquivo até o fim (a última) e, com o OpenSSL,
     * se o conteúdo assinado confere com a assinatura. Devolve a lista resumida para os metadados.
     */
    private static function pdfAssinaturas(string $b, array &$ind): array
    {
        if (!preg_match_all('/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/', $b, $m, PREG_SET_ORDER)) return [];
        $tam = strlen($b); $lista = []; $ultimaFim = 0;
        foreach ($m as $k => $r) {
            [$a0, $l0, $a1, $l1] = [(int)$r[1], (int)$r[2], (int)$r[3], (int)$r[4]];
            if ($a0 !== 0 || $a1 <= $a0 + $l0 || $a1 + $l1 > $tam) { $ind[] = self::ind('ASSINATURA', 'ASSINATURA_ESTRUTURA', 'ALTA', 'Assinatura digital com estrutura inconsistente (intervalo assinado fora do arquivo).'); continue; }
            $ultimaFim = max($ultimaFim, $a1 + $l1);
            $hex = substr($b, $a0 + $l0, $a1 - ($a0 + $l0));
            $hex = trim($hex, "<> \r\n\t");
            $der = (string)@hex2bin(preg_replace('/[^0-9A-Fa-f]/', '', $hex) ?? '');
            $der = self::derCortar($der);
            $s = ['n' => $k + 1, 'assinante' => null, 'emissor' => null, 'icp_brasil' => false, 'confere' => null];
            if ($der !== '' && function_exists('openssl_cms_verify') && defined('OPENSSL_CMS_DETACHED')) {
                $dir = sys_get_temp_dir(); $id = bin2hex(random_bytes(6));
                $fc = $dir . DIRECTORY_SEPARATOR . 'gp_adult_' . $id . '.bin'; $fs = $dir . DIRECTORY_SEPARATOR . 'gp_adult_' . $id . '.p7s'; $fx = $dir . DIRECTORY_SEPARATOR . 'gp_adult_' . $id . '.pem';
                try {
                    file_put_contents($fc, substr($b, $a0, $l0) . substr($b, $a1, $l1)); file_put_contents($fs, $der);
                    $ok = @openssl_cms_verify($fc, OPENSSL_CMS_BINARY | OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_DETACHED, $fx, [], null, null, null, $fs, OPENSSL_ENCODING_DER);
                    $erro = ''; while (($e = openssl_error_string()) !== false) $erro .= $e . ' ';
                    if ($ok === true) $s['confere'] = true;
                    elseif (str_contains($erro, 'content verify') || str_contains($erro, 'verification failure')) $s['confere'] = false;
                    if (is_file($fx) && ($c = @openssl_x509_parse((string)file_get_contents($fx)))) {
                        $s['assinante'] = mb_substr((string)($c['subject']['CN'] ?? ''), 0, 120);
                        $s['emissor'] = mb_substr((string)($c['issuer']['CN'] ?? $c['issuer']['O'] ?? ''), 0, 120);
                        $s['icp_brasil'] = str_contains(mb_strtoupper(json_encode($c['issuer'] ?? [], JSON_UNESCAPED_UNICODE) . json_encode($c['subject'] ?? [], JSON_UNESCAPED_UNICODE)), 'ICP-BRASIL');
                        $s['validade'] = isset($c['validFrom_time_t'], $c['validTo_time_t']) ? date('d/m/Y', (int)$c['validFrom_time_t']) . ' a ' . date('d/m/Y', (int)$c['validTo_time_t']) : null;
                    }
                } finally { foreach ([$fc, $fs, $fx] as $f) if (is_file($f)) @unlink($f); }
            }
            $quem = $s['assinante'] ? ' (' . $s['assinante'] . ($s['emissor'] ? ', emitido por ' . $s['emissor'] : '') . ')' : '';
            if ($s['confere'] === false) $ind[] = self::ind('ASSINATURA', 'ASSINATURA_NAO_CONFERE', 'ALTA', 'A assinatura digital ' . ($k + 1) . ' não confere com o conteúdo assinado' . $quem . ': o conteúdo foi alterado depois de assinado.');
            elseif ($s['confere'] === true) $ind[] = self::ind('ASSINATURA', 'ASSINATURA_OK', 'INFO', 'Assinatura digital ' . ($k + 1) . ' íntegra' . $quem . ($s['icp_brasil'] ? ', certificado ICP-Brasil' : '') . '. A cadeia do certificado não é conferida.');
            else $ind[] = self::ind('ASSINATURA', 'ASSINATURA_NAO_VERIFICADA', 'INFO', 'O PDF tem assinatura digital ' . ($k + 1) . ', mas ela não pôde ser conferida neste servidor' . $quem . '.');
            $lista[] = $s;
        }
        // Depois da última assinatura: só espaços ou atualização de validação (DSS / carimbo de tempo) são normais
        if ($ultimaFim > 0 && $ultimaFim < $tam) {
            $resto = substr($b, $ultimaFim);
            if (strlen(trim($resto)) > 0 && !preg_match('/\/DSS\b|\/DocTimeStamp|\/VRI\b/', $resto))
                $ind[] = self::ind('ASSINATURA', 'ALTERADO_APOS_ASSINATURA', 'ALTA', 'O PDF foi alterado depois da última assinatura digital (' . number_format(strlen($resto), 0, ',', '.') . ' bytes acrescentados).');
        }
        return $lista;
    }

    /** O campo /Contents vem completado com zeros: corta o DER no tamanho declarado no cabeçalho. */
    private static function derCortar(string $der): string
    {
        if (strlen($der) < 4 || ord($der[0]) !== 0x30) return '';
        $l = ord($der[1]);
        if ($l < 0x80) return substr($der, 0, 2 + $l);
        $n = $l & 0x7F; if ($n < 1 || $n > 4 || strlen($der) < 2 + $n) return '';
        $len = 0; for ($i = 0; $i < $n; $i++) $len = ($len << 8) | ord($der[2 + $i]);
        return substr($der, 0, 2 + $n + $len);
    }

    // ---- imagem

    private static function imagem(string $caminho, string $ext, array &$ind, array &$tec): void
    {
        $cab = (string)file_get_contents($caminho, false, null, 0, 262144);
        $soft = [];
        if (in_array($ext, ['jpg', 'jpeg', 'tif', 'tiff'], true) && function_exists('exif_read_data')) {
            $e = @exif_read_data($caminho, null, true) ?: [];
            foreach (['IFD0' => ['Software', 'Make', 'Model', 'DateTime'], 'EXIF' => ['DateTimeOriginal', 'DateTimeDigitized']] as $sec => $ks)
                foreach ($ks as $k) if (isset($e[$sec][$k]) && is_string($e[$sec][$k]) && trim($e[$sec][$k]) !== '') $tec['exif'][$k] = mb_substr(trim($e[$sec][$k]), 0, 80);
            if (!empty($tec['exif']['Software'])) $soft[] = $tec['exif']['Software'];
            $mod = self::exifData($tec['exif']['DateTime'] ?? null); $orig = self::exifData($tec['exif']['DateTimeOriginal'] ?? null);
            if ($mod && $orig && ($mod->getTimestamp() - $orig->getTimestamp()) > 86400)
                $ind[] = self::ind('IMAGEM', 'IMAGEM_MODIFICADA', 'BAIXA', 'Imagem salva de novo ' . self::dias((int)floor(($mod->getTimestamp() - $orig->getTimestamp()) / 86400)) . ' depois de capturada.');
        }
        if (preg_match_all('/<xmp:CreatorTool>([^<]{1,120})<|xmp:CreatorTool="([^"]{1,120})"|stEvt:softwareAgent(?:>|=")([^<"]{1,120})/', $cab, $m, PREG_SET_ORDER))
            foreach ($m as $x) $soft[] = trim(end($x) ?: ($x[1] ?? ''));
        if (str_contains($cab, 'Photoshop 3.0') && str_contains($cab, '8BIM')) $soft[] = 'Adobe Photoshop (marca do arquivo)';
        if (preg_match('/GIMP|Paint\.NET|Canva|Pixlr|Photopea/i', substr($cab, 0, 65536), $q)) $soft[] = $q[0];
        $soft = array_values(array_unique(array_filter($soft)));
        if ($soft) $tec['software'] = $soft;
        self::ferramentas($soft, 'IMAGEM', $ind);
        if (in_array($ext, ['jpg', 'jpeg'], true)) self::ela($caminho, $ind, $tec);
    }

    private static function exifData(?string $v): ?DateTimeImmutable
    {
        if (!$v || !preg_match('/^(\d{4}):(\d{2}):(\d{2}) (\d{2}):(\d{2}):(\d{2})/', $v, $m) || (int)$m[1] < 1990) return null;
        try { return new DateTimeImmutable("$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]"); } catch (Throwable) { return null; }
    }

    /**
     * Análise do nível de erro (ELA): regrava o JPEG com qualidade 90 e compara, em blocos de 16×16, quanto cada parte muda. Uma
     * região contígua com erro muito acima do resto pode ter sido colada de outra imagem. Indício fraco (texto e carimbos também
     * variam): conta como BAIXA e pede conferência visual.
     */
    private static function ela(string $caminho, array &$ind, array &$tec): void
    {
        if (!function_exists('imagecreatefromjpeg')) return;
        $info = @getimagesize($caminho);
        if (!$info || $info[0] * $info[1] > 16000000 || $info[0] < 64 || $info[1] < 64) return;
        $img = @imagecreatefromjpeg($caminho); if (!$img) return;
        ob_start(); imagejpeg($img, null, 90); $re = (string)ob_get_clean();
        $img2 = @imagecreatefromstring($re); if (!$img2) { imagedestroy($img); return; }
        $w = imagesx($img); $h = imagesy($img); $bs = 16; $bx = intdiv($w, $bs); $by = intdiv($h, $bs);
        $passo = max(1, (int)floor(sqrt(($w * $h) / 2000000))); // imagens grandes: amostra
        $blocos = [];
        for ($y = 0; $y < $by; $y++) for ($x = 0; $x < $bx; $x++) {
            $s = 0; $n = 0;
            for ($j = 0; $j < $bs; $j += $passo) for ($i = 0; $i < $bs; $i += $passo) {
                $p = imagecolorat($img, $x * $bs + $i, $y * $bs + $j); $q = imagecolorat($img2, $x * $bs + $i, $y * $bs + $j);
                $s += abs((($p >> 16) & 255) - (($q >> 16) & 255)) + abs((($p >> 8) & 255) - (($q >> 8) & 255)) + abs(($p & 255) - ($q & 255)); $n++;
            }
            $blocos[$y][$x] = $n ? $s / $n : 0;
        }
        imagedestroy($img); imagedestroy($img2);
        $todos = []; foreach ($blocos as $l) foreach ($l as $v) $todos[] = $v;
        $qt = count($todos); if ($qt < 64) return;
        $media = array_sum($todos) / $qt; $dp = sqrt(array_sum(array_map(static fn($v) => ($v - $media) ** 2, $todos)) / $qt);
        $lim = max($media + 5 * $dp, $media * 4, 12.0);
        // Histerese: a região é feita dos blocos acima da metade do limite que se ligam a pelo menos um bloco acima do limite
        $forte = []; $marc = [];
        foreach ($blocos as $y => $l) foreach ($l as $x => $v) { if ($v > $lim / 2) $marc["$x,$y"] = true; if ($v > $lim) $forte["$x,$y"] = true; }
        $tec['ela'] = ['media' => round($media, 2), 'limite' => round($lim, 2), 'blocos_acima' => count($forte), 'blocos' => $qt];
        if (!$forte || count($marc) > $qt * 0.25) return;
        // Maior região contígua
        $visto = []; $maior = 0; $caixa = null;
        foreach (array_keys($forte) as $k) {
            if (isset($visto[$k])) continue;
            $fila = [$k]; $visto[$k] = true; $tamanho = 0; $x0 = PHP_INT_MAX; $y0 = PHP_INT_MAX; $x1 = 0; $y1 = 0;
            while ($fila) {
                [$x, $y] = array_map('intval', explode(',', array_pop($fila))); $tamanho++;
                $x0 = min($x0, $x); $y0 = min($y0, $y); $x1 = max($x1, $x); $y1 = max($y1, $y);
                foreach ([[1, 0], [-1, 0], [0, 1], [0, -1], [1, 1], [-1, -1], [1, -1], [-1, 1]] as [$dx, $dy]) { $v = ($x + $dx) . ',' . ($y + $dy); if (isset($marc[$v]) && !isset($visto[$v])) { $visto[$v] = true; $fila[] = $v; } }
            }
            if ($tamanho > $maior) { $maior = $tamanho; $caixa = [$x0, $y0, $x1, $y1]; }
        }
        if ($maior >= 8 && $maior <= $qt * 0.10 && $caixa) {
            $pos = sprintf('%d%% a %d%% da largura, %d%% a %d%% da altura', (int)round($caixa[0] * 100 / $bx), (int)round(($caixa[2] + 1) * 100 / $bx), (int)round($caixa[1] * 100 / $by), (int)round(($caixa[3] + 1) * 100 / $by));
            $ind[] = self::ind('IMAGEM', 'IMAGEM_REGIAO', 'BAIXA', 'Região da imagem com compressão diferente do resto (' . $pos . '): pode ser colagem ou só um carimbo/assinatura. Confira visualmente.');
            $tec['ela']['regiao'] = $pos;
        }
    }

    // ---- Word

    private static function docx(string $caminho, array &$ind, array &$tec): void
    {
        $ind[] = self::ind('METADADOS', 'FORMATO_EDITAVEL', 'INFO', 'Documento em formato editável (Word): confira se veio do emissor.');
        if (!class_exists('ZipArchive')) return;
        $z = new ZipArchive(); if ($z->open($caminho) !== true) return;
        $core = (string)$z->getFromName('docProps/core.xml'); $app = (string)$z->getFromName('docProps/app.xml'); $z->close();
        $g = static fn(string $xml, string $tag): string => preg_match('/<' . preg_quote($tag, '/') . '[^>]*>([^<]*)</', $xml, $m) ? html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8') : '';
        $criador = $g($core, 'dc:creator'); $ultimo = $g($core, 'cp:lastModifiedBy');
        $criado = self::isoData($g($core, 'dcterms:created')); $mod = self::isoData($g($core, 'dcterms:modified'));
        $tec += ['criador' => $criador, 'modificado_por' => $ultimo, 'criado_em' => $criado?->format('Y-m-d H:i'), 'modificado_em' => $mod?->format('Y-m-d H:i'), 'produtor' => $g($app, 'Application'), 'revisoes' => $g($core, 'cp:revision')];
        if ($criado && $mod && ($mod->getTimestamp() - $criado->getTimestamp()) > 86400 && $ultimo !== '' && $criador !== '' && mb_strtolower($ultimo) !== mb_strtolower($criador))
            $ind[] = self::ind('METADADOS', 'WORD_MODIFICADO', 'BAIXA', 'Documento modificado por outra pessoa ' . self::dias((int)floor(($mod->getTimestamp() - $criado->getTimestamp()) / 86400)) . ' depois de criado.');
    }

    // ---- conteúdo (regras fixas)

    private static function texto(string $texto, float $confianca, array $registro, string $hoje, ?array $arq, array &$ind, array &$tec): void
    {
        $nativo = $confianca >= 0.99; // texto do próprio PDF (sem OCR): erro de leitura improvável
        $cnpjCad = preg_replace('/\D/', '', (string)($registro['CNPJ'] ?? '')) ?? '';
        $cpfCad = preg_replace('/\D/', '', (string)($registro['NRIDENTIFICACAO'] ?? '')) ?? '';
        $vistos = [];
        if (preg_match_all('/(?<!\d)(\d{2}\.?\d{3}\.?\d{3}\s?\/\s?\d{4}\s?-?\s?\d{2})(?!\d)/', $texto, $m)) foreach ($m[1] as $c) {
            $d = preg_replace('/\D/', '', $c) ?? ''; if (strlen($d) !== 14 || isset($vistos[$d]) || $d === $cnpjCad) continue; $vistos[$d] = true;
            if (!self::cnpjValido($d)) $ind[] = self::ind('CONTEUDO', 'CNPJ_INVALIDO', $nativo ? 'ALTA' : 'MEDIA', 'CNPJ ' . $c . ' com dígito verificador inválido' . ($nativo ? '' : ' (pode ser erro de leitura da digitalização)') . '.');
        }
        if (preg_match_all('/(?<![\d.\/])(\d{3}\.\d{3}\.\d{3}-\d{2})(?![\d])/', $texto, $m)) foreach ($m[1] as $c) {
            $d = preg_replace('/\D/', '', $c) ?? ''; if (isset($vistos[$d]) || $d === $cpfCad) continue; $vistos[$d] = true;
            if (!self::cpfValido($d)) $ind[] = self::ind('CONTEUDO', 'CPF_INVALIDO', $nativo ? 'ALTA' : 'MEDIA', 'CPF ' . $c . ' com dígito verificador inválido' . ($nativo ? '' : ' (pode ser erro de leitura da digitalização)') . '.');
        }
        if (preg_match_all('/(?<!\d)(\d{1,2})\/(\d{1,2})\/(\d{4})(?!\d)/', $texto, $m, PREG_SET_ORDER)) {
            $ruins = [];
            foreach ($m as $x) if ((int)$x[3] >= 1900 && (int)$x[3] <= 2100 && !checkdate((int)$x[2], (int)$x[1], (int)$x[3])) $ruins[] = $x[0];
            if ($ruins) $ind[] = self::ind('CONTEUDO', 'DATA_IMPOSSIVEL', $nativo ? 'MEDIA' : 'BAIXA', 'Data(s) impossível(is) no texto: ' . implode(', ', array_slice(array_unique($ruins), 0, 3)) . '.');
        }
        $emissao = self::dataRotulada($texto, '(?:emitid[oa]\s+em|data\s+(?:da|de)\s+emiss[aã]o|emiss[aã]o)');
        $validade = self::dataRotulada($texto, '(?:v[aá]lid[oa]\s+at[eé]|validade|data\s+de\s+validade|vencimento)');
        if ($emissao) {
            $tec['emissao_lida'] = $emissao;
            if ($emissao > date('Y-m-d', strtotime($hoje . ' +1 day'))) $ind[] = self::ind('CONTEUDO', 'EMISSAO_FUTURA', 'MEDIA', 'Data de emissão no futuro (' . self::br($emissao) . ').');
            if ($validade && $validade < $emissao) $ind[] = self::ind('CONTEUDO', 'VALIDADE_ANTES_EMISSAO', 'MEDIA', 'Validade (' . self::br($validade) . ') anterior à emissão (' . self::br($emissao) . ').');
            $c = $arq['criado'] ?? null; $mo = $arq['modificado'] ?? null;
            if ($c instanceof DateTimeImmutable) {
                $ultimaGravacao = ($mo instanceof DateTimeImmutable && $mo > $c) ? $mo : $c;
                if ($ultimaGravacao->format('Y-m-d') < date('Y-m-d', strtotime($emissao . ' -1 day')))
                    $ind[] = self::ind('CONTEUDO', 'EMISSAO_DEPOIS_ARQUIVO', 'ALTA', 'O documento diz ter sido emitido em ' . self::br($emissao) . ', mas o arquivo foi gravado pela última vez em ' . $ultimaGravacao->format('d/m/Y') . ' (antes da emissão).');
            }
        }
    }

    private static function dataRotulada(string $texto, string $rotulo): ?string
    {
        if (!preg_match('/' . $rotulo . '\s*[:\-]?\s*(?:[^\d\n]{0,20})?(\d{1,2})\/(\d{1,2})\/(\d{4})/iu', $texto, $m)) return null;
        return checkdate((int)$m[2], (int)$m[1], (int)$m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
    }

    public static function cnpjValido(string $d): bool
    {
        if (strlen($d) !== 14 || preg_match('/^(\d)\1{13}$/', $d)) return false;
        foreach ([12, 13] as $n) {
            $p = $n === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            $s = 0; for ($i = 0; $i < $n; $i++) $s += (int)$d[$i] * $p[$i];
            $r = $s % 11; if ((int)$d[$n] !== ($r < 2 ? 0 : 11 - $r)) return false;
        }
        return true;
    }

    public static function cpfValido(string $d): bool
    {
        if (strlen($d) !== 11 || preg_match('/^(\d)\1{10}$/', $d)) return false;
        foreach ([9, 10] as $n) {
            $s = 0; for ($i = 0; $i < $n; $i++) $s += (int)$d[$i] * ($n + 1 - $i);
            $r = ($s * 10) % 11; if ((int)$d[$n] !== ($r === 10 ? 0 : $r)) return false;
        }
        return true;
    }

    private static function br(string $iso): string { return substr($iso, 8, 2) . '/' . substr($iso, 5, 2) . '/' . substr($iso, 0, 4); }
    private static function dias(int $n): string { return $n === 1 ? '1 dia' : $n . ' dias'; }
}
