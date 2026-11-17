<?php
declare(strict_types=1);

require_once __DIR__ . '/NaoConformidadeAnaliseIa.php';

/**
 * Não Conformidades — avaliação da eficácia com IA (migração 337, 23/10/2026).
 * Na aba "Verificação da eficácia" o gestor pede o parecer da IA. O contexto enviado tem:
 *   - a ocorrência, a análise da causa e as causas;
 *   - as ações, com status, prazos, reprogramações, observações e os arquivos de evidência anexados (das ações, da análise e,
 *     desde 23/10/2026, os anexos da ocorrência);
 *   - desde a migração 338, o texto lido (OCR) desses arquivos: o pedido espera as leituras (EXTRAINDO) e o serviço "ia"
 *     (prepararPendentes) junta o texto ao contexto e põe na fila;
 *   - as evidências da análise;
 *   - as NCs parecidas abertas depois desta (possível recorrência), só as que o gestor pode ver.
 * Vai para a fila de IA com o prompt NC_EFICACIA (Setup › Prompts de IA). Como na análise da causa, o pedido roda em segundo
 * plano e a resposta chega nos Alertas (sp_nc_ia_analise_concluida); o alerta abre a NC na aba da eficácia com o parecer aberto.
 * A IA só dá o parecer: "Levar para a verificação" grava o texto da verificação por sp_nc_eficacia_salvar ('salvar') e deixa o
 * resultado sugerido (e, se não eficaz, o motivo da devolução) já preenchidos na tela; o resultado continua sendo aplicado pelo
 * gestor em "Aplicar o resultado" (o motivo não é gravado antes, para o "Salvar" da tela não apagá-lo).
 */
final class NaoConformidadeEficaciaIa
{
    public const PROMPT = 'NC_EFICACIA';
    public const TIPO = 'EFICACIA';
    public const MAX_RECORRENCIAS = 8;
    public const PARECERES = ['EFICAZ' => 'Eficaz', 'NAO_EFICAZ' => 'Não eficaz', 'INCONCLUSIVO' => 'Inconclusivo (faltam evidências)'];
    public const AVALIACOES = ['ATENDE' => 'Atende', 'PARCIAL' => 'Atende em parte', 'NAO_ATENDE' => 'Não atende', 'SEM_DADOS' => 'Sem dados'];

    /** Leitura (OCR) das evidências (migração 338): formatos, tamanho e quantidade máximos e o texto por arquivo/total no contexto. */
    public const OCR_EXTENSOES = ['pdf', 'jpg', 'jpeg', 'png', 'tif', 'tiff', 'bmp', 'doc', 'docx'];
    public const OCR_MAX_BYTES = 25 * 1024 * 1024;
    public const OCR_MAX_ARQUIVOS = 20;
    public const OCR_TEXTO_ARQUIVO = 3500;
    public const OCR_TEXTO_TOTAL = 40000;
    public const OCR_ESPERA_HORAS = 2;

    /** Arquivos anexados (GED, pastas do legado): evidências da análise e arquivos de cada ação, nome => caminho. */
    public static function evidencias(PDO $pdo, int $codigo, array $planos): array
    {
        $r = ['ocorrencia' => [], 'analise' => [], 'acoes' => []];
        try {
            require_once __DIR__ . '/GedStorageService.php';
            $ged = new GedStorageService($pdo, GreenPortalDatabase::env(), $_SESSION);
            $lista = static function (string $pasta, int $id) use ($ged): array {
                $nomes = [];
                foreach ($ged->folders($pasta, $id) as $dir) {
                    if (!is_dir($dir)) continue;
                    foreach (new DirectoryIterator($dir) as $f) if (!$f->isDot() && $f->isFile() && !isset($nomes[$f->getFilename()])) $nomes[$f->getFilename()] = $f->getPathname();
                }
                return $nomes;
            };
            // 23/10/2026 (teste do usuário na Crown): a evidência costuma ser anexada também no botão "Anexos" da ocorrência
            $r['ocorrencia'] = $lista('nc_indicacao_ocorrencia', $codigo);
            $r['analise'] = $lista('nc_analise_ocorrencia', $codigo);
            foreach ($planos as $p) $r['acoes'][(int)$p['CODPLANOACAO']] = $lista('nc_planodeacao', (int)$p['CODPLANOACAO']);
        } catch (Throwable $e) { error_log('NC eficácia IA (evidências): ' . $e->getMessage()); }
        return $r;
    }

    /** NCs parecidas abertas depois desta (só as que o usuário pode ver). */
    public static function recorrencias(PDO $pdo, int $uid, int $codigo, array $R, array $permissoes): array
    {
        $termos = NaoConformidadeAnaliseIa::termos((string)($R['IDENTIFICACAO'] ?? ''), (string)($R['DESCRICAO'] ?? ''));
        if (!$termos) return [];
        $s = $pdo->prepare('CALL sp_nc_ia_recorrencias(?, ?, ?)');
        $s->execute([$codigo, json_encode($termos, JSON_UNESCAPED_UNICODE), 30]);
        $linhas = $s->fetchAll(PDO::FETCH_ASSOC);
        do { try { $s->fetchAll(); } catch (Throwable) {} } while ($s->nextRowset());
        $s->closeCursor();
        $out = [];
        foreach ($linhas as $l) {
            if (count($out) >= self::MAX_RECORRENCIAS) break;
            if (NaoConformidadeWorkflow::podeVer($pdo, $uid, (int)$l['CODOCORRENCIA'], $permissoes)) $out[] = $l;
        }
        return $out;
    }

    private static function dia(mixed $v): string
    {
        $v = trim((string)$v);
        return $v === '' || str_starts_with($v, '0000') ? '' : substr($v, 0, 10);
    }

    /** Texto enviado à IA. */
    public static function contexto(array $R, array $causas, array $planos, array $evid, array $recorrencias): string
    {
        $c = [NaoConformidadeAnaliseIa::class, 'corta'];
        $l = ['OCORRÊNCIA ' . ($R['NRANO'] ?? $R['CODOCORRENCIA'] ?? '') . ' — avaliação da eficácia em ' . date('Y-m-d')];
        foreach (['DSCTIPONAOCONFORMIDADE' => 'Tipo de não conformidade', 'DSCTIPOOCORRENCIA' => 'Classificação', 'DSCUNIDADE' => 'Unidade', 'DSCAREA' => 'Área', 'DSCSETOR' => 'Setor',
            'RAZAOSOCIAL' => 'Fornecedor', 'DSCCLIENTE' => 'Cliente', 'DSCPRODUTO' => 'Produto', 'DATA_OCORRENCIA' => 'Data da ocorrência', 'IDENTIFICACAO' => 'Sumário',
            'DESCRICAO' => 'Descrição', 'ANALISE_DA_PROVIDENCIA' => 'Ação imediata', 'ANALISE_ABRANGENCIA' => 'Abrangência', 'DATA_DE_CONCLUSAO_PREVISTA' => 'Conclusão prevista',
            'DATA_DE_ENVIA_ANALISE_EFICACIA' => 'Enviada para a avaliação da eficácia em'] as $k => $rot) {
            $v = $c($R[$k] ?? '', in_array($k, ['DESCRICAO', 'ANALISE_DA_PROVIDENCIA', 'ANALISE_ABRANGENCIA'], true) ? 2500 : 300);
            if ($v !== '' && $v !== '0' && !str_starts_with($v, '0000')) $l[] = $rot . ': ' . $v;
        }
        $l[] = '';
        $l[] = 'ANÁLISE DA CAUSA: ' . ($c($R['ANALISE_DA_CAUSA'] ?? '', 3000) ?: '(vazia)');
        $l[] = 'ANEXOS DA OCORRÊNCIA (arquivos; podem ser registro do problema ou evidência das ações): ' . ($evid['ocorrencia'] ? implode('; ', array_slice(array_keys($evid['ocorrencia']), 0, 10)) : '(nenhum arquivo)');
        $l[] = 'EVIDÊNCIAS DA ANÁLISE (arquivos): ' . ($evid['analise'] ? implode('; ', array_slice(array_keys($evid['analise']), 0, 10)) : '(nenhum arquivo)');
        $l[] = 'CAUSAS: ' . ($causas ? '' : '(sem causas: tipo de NC tratado só por plano de ação)');
        foreach ($causas as $i => $x) $l[] = ($i + 1) . '. ' . ((string)$x['DSCTIPOCAUSA'] !== '' ? $x['DSCTIPOCAUSA'] . ': ' : '') . $c($x['DSCAUSA'], 500);
        $l[] = '';
        $l[] = 'AÇÕES DO PLANO (numere-as nesta ordem em acoes[].numero):';
        if (!$planos) $l[] = '(nenhuma ação)';
        foreach ($planos as $i => $p) {
            $arq = array_keys($evid['acoes'][(int)$p['CODPLANOACAO']] ?? []);
            $l[] = ($i + 1) . '. ' . $c($p['ACAO'], 500) . ((string)($p['DSCAUSA'] ?? '') !== '' ? ' [causa: ' . $c($p['DSCAUSA'], 120) . ']' : '');
            $l[] = '   status ' . (int)$p['STATUS'] . '%; responsável: ' . ((string)$p['RESPONSAVEL'] ?: '—') . '; início ' . (self::dia($p['DATAINICIAL']) ?: '—') . '; fim ' . (self::dia($p['DATAFINAL']) ?: '—')
                . (self::dia($p['DATA_REPROGRAMACAO'] ?? '') !== '' ? '; reprogramada para ' . self::dia($p['DATA_REPROGRAMACAO']) . ' (' . $c($p['MOTIVO_REPROGRAMACAO'] ?? '', 200) . ')' : '')
                . ((string)$p['RECURSOS'] !== '' ? '; recursos: ' . $c($p['RECURSOS'], 200) : '');
            if (($o = $c($p['OBS'] ?? '', 500)) !== '') $l[] = '   observação: ' . $o;
            $l[] = '   arquivos de evidência: ' . ($arq ? implode('; ', array_slice($arq, 0, 8)) : '(nenhum)');
        }
        $l[] = '';
        $l[] = 'VERIFICAÇÃO DA EFICÁCIA JÁ ESCRITA PELO GESTOR: ' . ($c($R['VERIFICACAO_DA_EFICACIA'] ?? '', 2000) ?: '(vazia)');
        $l[] = '';
        $l[] = 'NCS PARECIDAS ABERTAS DEPOIS DESTA (possível recorrência): ' . ($recorrencias ? '' : '(nenhuma encontrada)');
        foreach ($recorrencias as $x) {
            $l[] = '[' . ($x['NRANO'] ?: $x['CODOCORRENCIA']) . '] aberta em ' . self::dia($x['DATA']) . ((int)$x['DEPOIS_DO_PLANO'] === 1 ? ' (DEPOIS do envio desta NC para a eficácia)' : ' (antes do envio para a eficácia)')
                . '; situação: ' . $c(($x['ETAPA'] ?? '') . ((string)($x['SITUACAO'] ?? '') !== '' ? ' / ' . $x['SITUACAO'] : ''), 100);
            $l[] = '  Sumário: ' . $c($x['IDENTIFICACAO'], 300);
            if (($d = $c($x['DESCRICAO'], 400)) !== '') $l[] = '  Descrição: ' . $d;
        }
        return implode("\n", $l);
    }

    public static function solicitar(PDO $pdo, int $uid, string $idioma, int $codigo, array $R, array $causas, array $planos, array $permissoes): array
    {
        $prompt = NaoConformidadeAnaliseIa::prompt($pdo, self::PROMPT, $idioma, '337');
        NaoConformidadeAnaliseIa::exigirSemPendente($pdo, $codigo, $uid, self::TIPO);
        IaTaximetro::exigirDisponivel($pdo); // migração 340: antes da leitura das evidências, que só depois vai para a fila
        $evid = self::evidencias($pdo, $codigo, $planos);
        $rec = self::recorrencias($pdo, $uid, $codigo, $R, $permissoes);
        $texto = self::contexto($R, $causas, $planos, $evid, $rec);
        $ref = array_map(static fn($x) => ['codigo' => (int)$x['CODOCORRENCIA'], 'nrano' => (string)($x['NRANO'] ?: $x['CODOCORRENCIA']), 'sumario' => NaoConformidadeAnaliseIa::corta($x['IDENTIFICACAO'], 160),
            'data' => self::dia($x['DATA']), 'depois' => (int)$x['DEPOIS_DO_PLANO'] === 1, 'etapa' => (string)$x['ETAPA'], 'situacao' => (string)($x['SITUACAO'] ?? '')], $rec);
        // Migração 338: os arquivos de evidência vão antes para a leitura de texto (OCR); o serviço "ia" põe o pedido na fila depois
        $leituras = self::lerEvidencias($pdo, $uid, $codigo, $planos, $evid);
        if (!$leituras || !self::temExtracao($pdo)) return ['codanalise' => NaoConformidadeAnaliseIa::enfileirar($pdo, $uid, $idioma, $codigo, self::TIPO, 'NC_EFICACIA', $prompt, $texto, $ref), 'arquivos' => 0];
        $r = NaoConformidadeWorkflow::chamar($pdo, 'sp_nc_ia_analise_extrair', [$codigo, self::TIPO, $uid, hash('sha256', $prompt . "\n" . $texto), json_encode($ref, JSON_UNESCAPED_UNICODE),
            $texto, $prompt, $idioma, json_encode($leituras, JSON_UNESCAPED_UNICODE)]);
        return ['codanalise' => (int)($r['CODANALISE'] ?? 0), 'arquivos' => count($leituras)];
    }

    private static function temExtracao(PDO $pdo): bool
    {
        try { return (int)$pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_nc_ia_analise_extrair'")->fetchColumn() > 0; }
        catch (Throwable) { return false; }
    }

    /** Põe na fila de OCR os arquivos de evidência legíveis (o mesmo arquivo já lido é reaproveitado pela chave). */
    public static function lerEvidencias(PDO $pdo, int $uid, int $codigo, array $planos, array $evid): array
    {
        $arquivos = [];
        foreach ($evid['ocorrencia'] ?? [] as $nome => $caminho) $arquivos[] = ['ncocorrencia', 'nc_indicacao_ocorrencia', $codigo, 'Anexo da ocorrência', $nome, $caminho];
        foreach ($evid['analise'] as $nome => $caminho) $arquivos[] = ['ncanalise', 'nc_analise_ocorrencia', $codigo, 'Análise', $nome, $caminho];
        foreach ($planos as $i => $p) foreach ($evid['acoes'][(int)$p['CODPLANOACAO']] ?? [] as $nome => $caminho) $arquivos[] = ['ncplano', 'nc_planodeacao', (int)$p['CODPLANOACAO'], 'Ação ' . ($i + 1), $nome, $caminho];
        $leituras = [];
        try {
            require_once __DIR__ . '/OcrQueueService.php';
            $ocr = new OcrQueueService($pdo, GreenPortalDatabase::env());
            foreach ($arquivos as [$tipo, $entidade, $id, $origem, $nome, $caminho]) {
                if (count($leituras) >= self::OCR_MAX_ARQUIVOS) break;
                if (!in_array(strtolower(pathinfo($nome, PATHINFO_EXTENSION)), self::OCR_EXTENSOES, true) || !is_file($caminho) || filesize($caminho) > self::OCR_MAX_BYTES) continue;
                $dados = ['chave_idempotencia' => 'GED_EXTRACAO:' . $tipo . ':' . $id . ':' . $nome . ':' . hash_file('sha256', $caminho), 'tipo' => 'NC_EVIDENCIA',
                    'entidade' => $entidade, 'registro_id' => (string)$id, 'arquivo' => $nome, 'caminho_arquivo' => $caminho, 'tamanho_bytes' => filesize($caminho), 'codusuario' => $uid];
                $job = $ocr->enqueue($dados);
                // Leitura anterior com erro (ex.: serviço OCR fora do ar): lê de novo, com outra chave
                if (in_array((string)($job['status'] ?? ''), ['ERRO', 'CANCELADA'], true)) $job = $ocr->enqueue(['chave_idempotencia' => substr($dados['chave_idempotencia'], 0, 170) . ':R' . date('YmdHis')] + $dados);
                $leituras[] = ['codjob' => (int)$job['codjob'], 'arquivo' => $nome, 'origem' => $origem];
            }
        } catch (Throwable $e) { error_log('NC eficácia IA (OCR): ' . $e->getMessage()); }
        return $leituras;
    }

    /**
     * Serviço "ia" do Agendador (scripts/windows-service.php, antes de processar a fila): pedidos de eficácia que esperam a leitura
     * das evidências. Quando todas terminaram (ou depois de OCR_ESPERA_HORAS), junta o texto lido ao contexto e põe na fila de IA.
     */
    public static function prepararPendentes(PDO $pdo, int $limite = 10): int
    {
        try {
            $s = $pdo->query("SELECT CODANALISE, CODOCORRENCIA, CODUSUARIO, CONTEXTO, PROMPT, IDIOMA, EVIDENCIAS, CRIADO_EM, TIMESTAMPDIFF(MINUTE, CRIADO_EM, NOW()) AS IDADE_MIN FROM vw_nc_ia_analises WHERE STATUS = 'EXTRAINDO' ORDER BY CODANALISE LIMIT " . max(1, $limite));
            $pendentes = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
        } catch (Throwable) { return 0; } // empresa sem NC ou antes da migração 338
        $feitos = 0;
        foreach ($pendentes as $a) {
            try {
                $leituras = json_decode((string)$a['EVIDENCIAS'], true) ?: [];
                $jobs = [];
                foreach ($leituras as $l) {
                    $q = $pdo->prepare('SELECT status, texto, erro FROM vw_green_ocr_jobs WHERE codjob = ?'); $q->execute([(int)$l['codjob']]);
                    $jobs[(int)$l['codjob']] = $q->fetch(PDO::FETCH_ASSOC) ?: ['status' => 'ERRO', 'texto' => '', 'erro' => 'leitura não encontrada']; $q->closeCursor();
                }
                $andamento = array_filter($jobs, static fn($j) => in_array((string)$j['status'], ['PENDENTE', 'PROCESSANDO'], true));
                // idade calculada no banco (o fuso do PHP do Agendador pode ser outro)
                if ($andamento && (int)$a['IDADE_MIN'] < self::OCR_ESPERA_HORAS * 60) continue;
                $texto = (string)$a['CONTEXTO'] . "\n\n" . self::conteudoEvidencias($leituras, $jobs);
                $job = (new DocumentAiQueueService($pdo))->enqueue(['chave_idempotencia' => 'IA:NC_EFICACIA:' . (int)$a['CODOCORRENCIA'] . ':' . (int)$a['CODANALISE'],
                    'tipo' => 'NC_EFICACIA', 'entidade' => 'nc_indicacao_ocorrencia', 'registro_id' => (string)(int)$a['CODOCORRENCIA'], 'arquivo' => 'nc_eficacia',
                    'codanalise_ged' => 0, 'codusuario' => (int)$a['CODUSUARIO'], 'idioma' => (string)($a['IDIOMA'] ?: 'pt'), 'prompt' => (string)$a['PROMPT'], 'texto' => $texto,
                    'ignorar_limite' => true]); // pedido aceito antes (esperava o OCR): o limite da empresa já foi conferido
                NaoConformidadeWorkflow::chamar($pdo, 'sp_nc_ia_analise_iniciar', [(int)$a['CODANALISE'], (int)$job['codjob']]);
                $feitos++;
            } catch (Throwable $e) { error_log('NC eficácia IA (preparar ' . $a['CODANALISE'] . '): ' . $e->getMessage()); }
        }
        return $feitos;
    }

    /** Seção do contexto com o texto lido de cada arquivo (limitada por arquivo e no total). */
    public static function conteudoEvidencias(array $leituras, array $jobs): string
    {
        $l = ['CONTEÚDO DAS EVIDÊNCIAS (texto lido dos arquivos anexados por OCR; pode ter erros de leitura):'];
        $total = 0;
        foreach ($leituras as $x) {
            $j = $jobs[(int)$x['codjob']] ?? null;
            $cab = '=== ' . $x['origem'] . ' — ' . $x['arquivo'] . ' ===';
            $t = trim(preg_replace('/[ \t]+/u', ' ', preg_replace('/\n{3,}/u', "\n\n", (string)($j['texto'] ?? '')) ?? '') ?? '');
            if (!$j || (string)$j['status'] !== 'CONCLUIDA' || $t === '') {
                $motivo = !$j ? 'leitura não encontrada' : (in_array((string)$j['status'], ['PENDENTE', 'PROCESSANDO'], true) ? 'a leitura não terminou a tempo' : ((string)($j['erro'] ?? '') ?: 'sem texto legível'));
                $l[] = $cab . "\n(não foi possível ler: " . NaoConformidadeAnaliseIa::corta($motivo, 200) . ')';
                continue;
            }
            $resta = self::OCR_TEXTO_TOTAL - $total;
            if ($resta < 300) { $l[] = $cab . "\n(não incluído: limite de texto das evidências atingido)"; continue; }
            $t = mb_substr($t, 0, min(self::OCR_TEXTO_ARQUIVO, $resta), 'UTF-8');
            $total += mb_strlen($t, 'UTF-8');
            $l[] = $cab . "\n" . $t;
        }
        return implode("\n\n", $l);
    }

    /** Parecer conferido: códigos válidos, textos limitados, ações ligadas às do plano. */
    public static function normalizar(array $s, array $planos): array
    {
        $txt = static fn($v, int $n): string => is_scalar($v) ? NaoConformidadeAnaliseIa::corta($v, $n) : '';
        $cod = static function ($v, array $validos, string $padrao): string {
            $k = strtoupper(str_replace([' ', '-', 'Ã'], ['_', '_', 'A'], NaoConformidadeAnaliseIa::chave(is_scalar($v) ? (string)$v : '')));
            return isset($validos[$k]) ? $k : $padrao;
        };
        $lista = static fn($v, int $max, int $n): array => array_values(array_slice(array_filter(array_map(static fn($x) => is_scalar($x) ? NaoConformidadeAnaliseIa::corta($x, $n) : '', is_array($v) ? $v : [])), 0, $max));
        $conf = (float)($s['confianca'] ?? 0); if ($conf > 0 && $conf <= 1) $conf *= 100;
        $criterios = [];
        foreach (array_slice(is_array($s['criterios'] ?? null) ? $s['criterios'] : [], 0, 8) as $c) {
            if (!is_array($c) || ($n = $txt($c['criterio'] ?? '', 200)) === '') continue;
            $criterios[] = ['criterio' => $n, 'avaliacao' => $cod($c['avaliacao'] ?? '', self::AVALIACOES, 'SEM_DADOS'), 'comentario' => $txt($c['comentario'] ?? '', 500)];
        }
        $acoes = [];
        foreach (array_slice(is_array($s['acoes'] ?? null) ? $s['acoes'] : [], 0, 30) as $a) {
            if (!is_array($a)) continue;
            $i = (int)($a['numero'] ?? 0) - 1;
            if ($i < 0 || $i >= count($planos)) continue;
            $acoes[$i] = ['numero' => $i + 1, 'acao' => NaoConformidadeAnaliseIa::corta($planos[$i]['ACAO'] ?? '', 200), 'status' => (int)($planos[$i]['STATUS'] ?? 0),
                'avaliacao' => $cod($a['avaliacao'] ?? '', self::AVALIACOES, 'SEM_DADOS'), 'comentario' => $txt($a['comentario'] ?? '', 500)];
        }
        ksort($acoes);
        $rec = [];
        foreach (array_slice(is_array($s['recorrencias'] ?? null) ? $s['recorrencias'] : [], 0, self::MAX_RECORRENCIAS) as $x)
            if (is_array($x) && ($n = $txt($x['nc'] ?? '', 40)) !== '') $rec[] = ['nc' => $n, 'comentario' => $txt($x['comentario'] ?? '', 400)];
        $parecer = $cod($s['parecer'] ?? '', self::PARECERES, 'INCONCLUSIVO');
        return ['parecer' => $parecer, 'parecer_rotulo' => self::PARECERES[$parecer], 'confianca' => (int)max(0, min(100, round($conf))), 'resumo' => $txt($s['resumo'] ?? '', 800),
            'criterios' => $criterios, 'acoes' => array_values($acoes), 'recorrencias' => $rec, 'falta_comprovar' => $lista($s['falta_comprovar'] ?? [], 8, 300),
            'verificacao_da_eficacia' => $txt($s['verificacao_da_eficacia'] ?? '', 4000), 'motivo_nao_eficaz' => $txt($s['motivo_nao_eficaz'] ?? '', 2000),
            'alertas' => $lista($s['alertas'] ?? [], 8, 300)];
    }

    public static function consultar(PDO $pdo, int $uid, int $codigo, int $codanalise, array $planos): array
    {
        $r = NaoConformidadeAnaliseIa::estado($pdo, $uid, $codigo, $codanalise, self::TIPO);
        if (isset($r['bruto'])) { $r['parecer'] = self::normalizar($r['bruto'], $planos); unset($r['bruto']); }
        return $r;
    }

    /**
     * Leva o parecer para a verificação da eficácia: $dados = ['verificacao' => ['modo' => manter|acrescentar|substituir, 'texto'],
     * 'motivo' => ['usar' => bool, 'texto'], 'parecer' => EFICAZ|NAO_EFICAZ|INCONCLUSIVO]. Não aplica o resultado.
     */
    public static function aplicar(PDO $pdo, int $uid, bool $admin, int $codigo, int $codanalise, array $dados, array $R, bool $exigePrazo): array
    {
        $ver = is_array($dados['verificacao'] ?? null) ? $dados['verificacao'] : [];
        $modo = in_array($ver['modo'] ?? '', ['acrescentar', 'substituir'], true) ? (string)$ver['modo'] : 'manter';
        $texto = trim((string)($ver['texto'] ?? ''));
        $mot = is_array($dados['motivo'] ?? null) ? $dados['motivo'] : [];
        $motivo = !empty($mot['usar']) ? trim((string)($mot['texto'] ?? '')) : '';
        $parecer = isset(self::PARECERES[(string)($dados['parecer'] ?? '')]) ? (string)$dados['parecer'] : 'INCONCLUSIVO';
        $grava = [];
        if ($modo !== 'manter' && $texto !== '') {
            $atual = trim((string)($R['VERIFICACAO_DA_EFICACIA'] ?? ''));
            $grava['VERIFICACAO_DA_EFICACIA'] = $modo === 'acrescentar' && $atual !== '' ? $atual . "\n\n" . $texto : $texto;
        }
        $pdo->beginTransaction();
        try {
            if ($grava) NaoConformidadeWorkflow::chamar($pdo, 'sp_nc_eficacia_salvar', [$codigo, json_encode($grava, JSON_UNESCAPED_UNICODE), $uid, 'salvar', $exigePrazo ? 1 : 0, $admin ? 1 : 0]);
            $resumo = 'Parecer da IA: ' . self::PARECERES[$parecer] . '. ' . ($grava ? 'Texto levado para a verificação da eficácia.' : 'Texto da verificação não alterado.') . ' O resultado é aplicado pelo gestor.';
            NaoConformidadeWorkflow::chamar($pdo, 'sp_nc_ia_analise_encerrar', [$codanalise, $codigo, 'APLICADA', json_encode(['parecer' => $parecer, 'motivo' => mb_substr($motivo, 0, 5000)] + $grava, JSON_UNESCAPED_UNICODE), $resumo, $uid]);
            $pdo->commit();
        } catch (Throwable $x) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $x;
        }
        return ['resultado' => in_array($parecer, ['EFICAZ', 'NAO_EFICAZ'], true) ? $parecer : '', 'gravado' => array_keys($grava), 'message' => $resumo];
    }

    /** Preenchimento da tela depois de "Levar para a verificação" (?ia_ef=CODANALISE): resultado sugerido e motivo da devolução. */
    public static function preenchimento(PDO $pdo, int $uid, int $codigo, int $codanalise): array
    {
        try {
            $s = $pdo->prepare("SELECT APLICADO FROM vw_nc_ia_analises WHERE CODANALISE = ? AND CODOCORRENCIA = ? AND CODUSUARIO = ? AND TIPO = 'EFICACIA' AND STATUS = 'APLICADA'");
            $s->execute([$codanalise, $codigo, $uid]); $a = json_decode((string)$s->fetchColumn(), true); $s->closeCursor();
        } catch (Throwable) { $a = null; }
        if (!is_array($a)) return [];
        $p = (string)($a['parecer'] ?? '');
        return ['RESULTADO' => in_array($p, ['EFICAZ', 'NAO_EFICAZ'], true) ? $p : '', 'RECUSA_DO_GESTOR' => $p === 'NAO_EFICAZ' ? (string)($a['motivo'] ?? '') : ''];
    }

    public static function descartar(PDO $pdo, int $uid, int $codigo, int $codanalise): void
    {
        NaoConformidadeWorkflow::chamar($pdo, 'sp_nc_ia_analise_encerrar', [$codanalise, $codigo, 'DESCARTADA', null, 'Parecer da IA sobre a eficácia descartado (nada foi gravado).', $uid]);
    }
}
