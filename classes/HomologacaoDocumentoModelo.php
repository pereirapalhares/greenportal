<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';
require_once __DIR__ . '/IdiomaService.php';

/**
 * Migração 347 — Validação dos documentos da homologação pelos modelos cadastrados (Onda 2 da apresentação Atlas: "Validação
 * documental — conferência conforme modelos e templates previamente cadastrados").
 *
 * Modelo (fo_documento_modelos, um por critério de fornecedor ou de funcionário): itens que o documento precisa ter (obrigatórios
 * ou recomendados), orientação para a IA, regras fixas (conferir com o cadastro, assinatura, validade, emissão máxima, validade
 * mínima) e um arquivo de exemplo, cujo texto lido serve para a IA sugerir os itens.
 *
 * Validação automática (serviço "ia" do Agendador, scripts/windows-service.php):
 *   1. varrer()   — a cada 5 minutos: documentos "Pendente com a empresa" com modelo ativo; cada arquivo novo (enviado depois da
 *                    ativação, ou todos com "validar também os pendentes") vai para a fila de OCR (a mesma leitura da Gestão de Arquivos);
 *   2. preparar() — leitura pronta: texto + cadastro + modelo vão para a fila de IA, com o prompt do documento (FORNECEDOR_DOCUMENTO /
 *                    FUNCIONARIO_DOCUMENTO) e as instruções do modelo;
 *   3. aoConcluirJob() — chamado pela fila de IA quando o pedido termina: grava a análise pendente de revisão (a mesma da análise
 *                    manual, com o parecer item a item em "modelo") e avisa o responsável da empresa pelo documento.
 * A IA só sugere: aprovar, reprovar ou devolver ao fornecedor é sempre decisão de uma pessoa (Gestão de Arquivos › Revisão dos dados).
 * Banco só por procedures e views (database/347_documento_modelos.sql).
 */
final class HomologacaoDocumentoModelo
{
    public const ESCOPOS = [
        'FORNECEDOR' => ['ged' => 'fornecedorcriterios', 'pasta' => 'fo_fornecedorxcriterios', 'chave' => 'CODFORNECEDORXCRITERIOS', 'tipo_ia' => 'FORNECEDORCRITERIOS',
            'prompt' => 'FORNECEDOR_DOCUMENTO', 'registro' => 'sp_docfornecedor_ia_registro', 'menu' => 'HOMOLOGACAO_CRITERIOS_FORNECEDORES'],
        'FUNCIONARIO' => ['ged' => 'funcionariocriterios', 'pasta' => 'fo_funcionarioxtiposxcriterios', 'chave' => 'CODIGO', 'tipo_ia' => 'FUNCIONARIOCRITERIOS',
            'prompt' => 'FUNCIONARIO_DOCUMENTO', 'registro' => 'sp_docfuncionario_ia_registro', 'menu' => 'HOMOLOGACAO_CRITERIOS_FUNCIONARIOS'],
    ];
    public const EXTENSOES = ['pdf', 'jpg', 'jpeg', 'png', 'tif', 'tiff', 'bmp', 'doc', 'docx'];
    public const MAX_BYTES = 20971520;
    public const MAX_ITENS = 30;
    /** Arquivos novos postos na leitura por empresa a cada varredura (o resto entra nas próximas). */
    public const LOTE = 20;
    public const VARREDURA_MIN = 5;
    public const OCR_ESPERA_HORAS = 6;
    public const TIPO_SUGESTAO = 'DOC_MODELO_SUGESTAO';
    public const PROMPT_SUGESTAO = 'DOC_MODELO_SUGESTAO';

    public static function disponivel(PDO $pdo): bool
    {
        try {
            $s = $pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME IN ('sp_doc_modelo_candidatos', 'sp_doc_modelo_do_documento')");
            $n = (int)$s->fetchColumn(); $s->closeCursor();
            return $n === 2;
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

    // ------------------------------------------------------------------ modelo

    /** Modelo ativo do critério do documento (null sem modelo ou antes da migração 347). */
    public static function doDocumento(PDO $pdo, string $escopo, int $codigo): ?array
    {
        if (!isset(self::ESCOPOS[$escopo]) || $codigo < 1) return null;
        try { $m = self::chamar($pdo, 'CALL sp_doc_modelo_do_documento(?, ?)', [$escopo, $codigo])[0] ?? null; }
        catch (Throwable) { return null; }
        if (!$m) return null;
        $m['ITENS'] = self::itens($m['ITENS'] ?? null);
        return $m['ITENS'] || (int)$m['CONFERIR_CADASTRO'] || (int)$m['EXIGE_ASSINATURA'] || (int)$m['EXIGE_VALIDADE'] || $m['EMISSAO_MAX_DIAS'] !== null || $m['VALIDADE_MIN_DIAS'] !== null ? $m : null;
    }

    /** Itens do modelo normalizados: [['texto' => ..., 'obrigatorio' => bool], ...] (sem vazios, no máximo MAX_ITENS). */
    public static function itens(mixed $json): array
    {
        $l = is_array($json) ? $json : (json_decode((string)$json, true) ?: []);
        $out = [];
        foreach ($l as $i) {
            $t = is_array($i) ? trim((string)($i['texto'] ?? '')) : trim((string)$i);
            $t = trim((string)preg_replace('/\s+/u', ' ', $t));
            if ($t === '') continue;
            $out[] = ['texto' => mb_substr($t, 0, 300), 'obrigatorio' => !is_array($i) || !array_key_exists('obrigatorio', $i) || filter_var($i['obrigatorio'], FILTER_VALIDATE_BOOLEAN)];
            if (count($out) >= self::MAX_ITENS) break;
        }
        return $out;
    }

    /** Instruções do modelo que vão junto ao texto do documento (antes do separador "---"). */
    public static function instrucoes(array $m, string $escopo): string
    {
        $l = ['MODELO CADASTRADO PARA ESTE DOCUMENTO — confira cada item no texto do arquivo:'];
        foreach ($m['ITENS'] as $n => $i) $l[] = ($n + 1) . '. (' . ($i['obrigatorio'] ? 'obrigatório' : 'recomendado') . ') ' . $i['texto'];
        if (!$m['ITENS']) $l[] = '(sem itens; confira só as regras abaixo)';
        if (trim((string)($m['ORIENTACAO'] ?? '')) !== '') $l[] = 'Orientação da empresa: ' . mb_substr(trim((string)$m['ORIENTACAO']), 0, 1500);
        $l[] = 'Além dos campos do formato pedido, inclua no JSON: "modelo_itens": [{"item": 1, "atende": "SIM|NAO|INCERTO", "evidencia": "trecho curto do documento que comprova, ou o que falta"}] '
            . '(um por item, na mesma ordem e numeração), "assinatura": "SIM|NAO|INCERTO" (o documento tem assinatura, carimbo ou assinatura/certificação digital) e '
            . '"data_emissao" (AAAA-MM-DD, só se a data de emissão estiver escrita)' . ($escopo === 'FUNCIONARIO' ? ' e "data_validade" (AAAA-MM-DD, só se escrita)' : '') . '. '
            . 'Na evidência não copie dados de saúde nem documentos pessoais; descreva o que encontrou.';
        return implode("\n", $l) . "\n";
    }

    // ------------------------------------------------------------------ conferência

    public static function data(mixed $v): ?string
    {
        $v = trim((string)$v); if ($v === '') return null;
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $v, $m)) $v = "$m[3]-$m[2]-$m[1]";
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)) ? $v : null;
    }

    public static function nome(?string $s, bool $semSufixos = false): string
    {
        $s = (string)$s;
        $s = class_exists('Normalizer') ? (string)Normalizer::normalize($s, Normalizer::FORM_D) : $s;
        $s = strtoupper(preg_replace('/\p{Mn}+/u', '', $s) ?? $s);
        if ($semSufixos) $s = preg_replace('/\b(LTDA|ME|EPP|EIRELI|S\s*A|SA|S\/A)\b/', '', $s) ?? $s;
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Z0-9 ]+/', ' ', $s) ?? '') ?? '');
    }

    /**
     * Parecer do documento pelo modelo. $ia: leitura da IA (já conferida pela análise: razao_social/cnpj ou nome/cpf, datas) com
     * modelo_itens e assinatura; $registro: cadastro (sp_docfornecedor_ia_registro / sp_docfuncionario_ia_registro).
     * Devolve itens e regras (atende SIM/NAO/INCERTO), contagens, pendências e o parecer em texto.
     */
    public static function conferir(array $m, array $ia, array $registro, string $escopo, ?string $hoje = null): array
    {
        $hoje ??= date('Y-m-d');
        $T = static fn(string $c, array $p, array $v = []): string => strtr(IdiomaService::t('doc_modelo.' . $c, $p), array_combine(array_map(static fn($k) => '{' . $k . '}', array_keys($v)), array_map('strval', array_values($v))) ?: []);
        $sn = static fn($v): string => in_array(strtoupper(trim((string)$v)), ['SIM', 'NAO'], true) ? strtoupper(trim((string)$v)) : (in_array(strtoupper(trim((string)$v)), ['NÃO', 'YES', 'NO', 'TRUE', 'FALSE'], true) ? (in_array(strtoupper(trim((string)$v)), ['YES', 'TRUE'], true) ? 'SIM' : 'NAO') : 'INCERTO');
        $lidos = [];
        foreach ((array)($ia['modelo_itens'] ?? []) as $k => $x) {
            if (!is_array($x)) continue;
            $n = (int)($x['item'] ?? ($k + 1));
            $lidos[$n] = ['atende' => $sn($x['atende'] ?? ''), 'evidencia' => mb_substr(trim((string)($x['evidencia'] ?? '')), 0, 240)];
        }
        $itens = [];
        foreach ($m['ITENS'] as $i => $it) {
            $l = $lidos[$i + 1] ?? ['atende' => 'INCERTO', 'evidencia' => ''];
            $itens[] = ['n' => $i + 1, 'texto' => $it['texto'], 'obrigatorio' => $it['obrigatorio'], 'atende' => $l['atende'], 'evidencia' => $l['evidencia']];
        }
        $regras = [];
        $regra = static function (string $codigo, string $texto, ?bool $ok, string $detalhe = '') use (&$regras): void { $regras[] = ['codigo' => $codigo, 'texto' => $texto, 'ok' => $ok, 'atende' => $ok === null ? 'INCERTO' : ($ok ? 'SIM' : 'NAO'), 'detalhe' => $detalhe]; };
        if ((int)($m['CONFERIR_CADASTRO'] ?? 0)) {
            if ($escopo === 'FORNECEDOR') {
                $ok = null; $det = [];
                $nd = self::nome($ia['razao_social'] ?? '', true); $nc = self::nome($registro['RAZAOSOCIAL'] ?? '', true);
                if ($nd !== '' && $nc !== '') { similar_text($nd, $nc, $pct); $ok = $pct >= 75; if (!$ok) $det[] = $T('det.razao', ['pt' => 'razão social do documento: {v}', 'en' => 'company name on the document: {v}', 'es' => 'razón social del documento: {v}'], ['v' => (string)$ia['razao_social']]); }
                $cd = preg_replace('/\D/', '', (string)($ia['cnpj'] ?? '')) ?? ''; $cc = preg_replace('/\D/', '', (string)($registro['CNPJ'] ?? '')) ?? '';
                if (strlen($cd) === 14 && strlen($cc) === 14) { $okc = substr($cd, 0, 8) === substr($cc, 0, 8); $ok = ($ok ?? true) && $okc; if (!$okc) $det[] = $T('det.cnpj', ['pt' => 'CNPJ de outra empresa', 'en' => 'CNPJ of another company', 'es' => 'CNPJ de otra empresa']); }
                $regra('CADASTRO', $T('r.cadastro_forn', ['pt' => 'Razão social e CNPJ conferem com o cadastro', 'en' => 'Company name and CNPJ match the record', 'es' => 'Razón social y CNPJ coinciden con el registro']), $ok,
                    $ok === null ? $T('det.nao_lido', ['pt' => 'não encontrados no documento', 'en' => 'not found in the document', 'es' => 'no encontrados en el documento']) : implode('; ', $det));
            } else {
                $ok = null; $det = [];
                $nd = self::nome($ia['nome'] ?? ''); $nc = self::nome($registro['FUNCIONARIO'] ?? '');
                if ($nd !== '' && $nc !== '') { similar_text($nd, $nc, $pct); $ok = $pct >= 80; if (!$ok) $det[] = $T('det.nome', ['pt' => 'nome no documento: {v}', 'en' => 'name on the document: {v}', 'es' => 'nombre en el documento: {v}'], ['v' => (string)$ia['nome']]); }
                $cd = preg_replace('/\D/', '', (string)($ia['cpf'] ?? '')) ?? ''; $cc = preg_replace('/\D/', '', (string)($registro['NRIDENTIFICACAO'] ?? '')) ?? '';
                if (strlen($cd) === 11 && strlen($cc) === 11) { $okc = $cd === $cc; $ok = ($ok ?? true) && $okc; if (!$okc) $det[] = $T('det.cpf', ['pt' => 'CPF diferente do cadastro', 'en' => 'CPF differs from the record', 'es' => 'CPF diferente del registro']); }
                $regra('CADASTRO', $T('r.cadastro_func', ['pt' => 'Nome e CPF conferem com o cadastro do funcionário', 'en' => 'Name and CPF match the employee record', 'es' => 'Nombre y CPF coinciden con el registro del empleado']), $ok,
                    $ok === null ? $T('det.nao_lido', ['pt' => 'não encontrados no documento', 'en' => 'not found in the document', 'es' => 'no encontrados en el documento']) : implode('; ', $det));
            }
        }
        if ((int)($m['EXIGE_ASSINATURA'] ?? 0)) {
            $a = $sn($ia['assinatura'] ?? '');
            $regra('ASSINATURA', $T('r.assinatura', ['pt' => 'Assinado (assinatura, carimbo ou certificação digital)', 'en' => 'Signed (signature, stamp or digital certification)', 'es' => 'Firmado (firma, sello o certificación digital)']), $a === 'INCERTO' ? null : $a === 'SIM');
        }
        $validade = self::data($ia['data_validade'] ?? null) ?? self::data($ia['aso_validade'] ?? null) ?? self::data($ia['treinamento_validade'] ?? null);
        $emissao = self::data($ia['data_emissao'] ?? null) ?? self::data($ia['aso_data_exame'] ?? null) ?? self::data($ia['treinamento_data_realizacao'] ?? null);
        $fmt = static fn(?string $d): string => $d ? (IdiomaService::idioma() === 'en' ? substr($d, 5, 2) . '/' . substr($d, 8, 2) . '/' . substr($d, 0, 4) : substr($d, 8, 2) . '/' . substr($d, 5, 2) . '/' . substr($d, 0, 4)) : '';
        $dias = static fn(string $a, string $b): int => (int)round((strtotime($b . ' 12:00:00') - strtotime($a . ' 12:00:00')) / 86400);
        if ((int)($m['EXIGE_VALIDADE'] ?? 0)) $regra('VALIDADE', $T('r.validade', ['pt' => 'Tem data de validade escrita', 'en' => 'Has a written expiration date', 'es' => 'Tiene fecha de vencimiento escrita']), $validade !== null, $validade ? $fmt($validade) : '');
        if ($m['VALIDADE_MIN_DIAS'] !== null && $m['VALIDADE_MIN_DIAS'] !== '') {
            $n = (int)$m['VALIDADE_MIN_DIAS'];
            $regra('VALIDADE_MIN', $T('r.validade_min', ['pt' => 'Válido por pelo menos {n} dia(s) a partir de hoje', 'en' => 'Valid for at least {n} day(s) from today', 'es' => 'Válido por al menos {n} día(s) desde hoy'], ['n' => $n]),
                $validade === null ? ((int)($m['EXIGE_VALIDADE'] ?? 0) ? false : null) : $dias($hoje, $validade) >= $n, $validade ? $fmt($validade) : $T('det.sem_validade', ['pt' => 'validade não encontrada', 'en' => 'expiration not found', 'es' => 'vencimiento no encontrado']));
        }
        if ($m['EMISSAO_MAX_DIAS'] !== null && $m['EMISSAO_MAX_DIAS'] !== '') {
            $n = (int)$m['EMISSAO_MAX_DIAS'];
            $regra('EMISSAO_MAX', $T('r.emissao', ['pt' => 'Emitido há no máximo {n} dia(s)', 'en' => 'Issued at most {n} day(s) ago', 'es' => 'Emitido hace como máximo {n} día(s)'], ['n' => $n]),
                $emissao === null ? null : $dias($emissao, $hoje) <= $n, $emissao ? $fmt($emissao) : $T('det.sem_emissao', ['pt' => 'data de emissão não encontrada', 'en' => 'issue date not found', 'es' => 'fecha de emisión no encontrada']));
        }
        $conformes = 0; $faltando = 0; $incertos = 0; $pend = []; $recom = [];
        foreach ($itens as $i) {
            if ($i['atende'] === 'SIM') $conformes++;
            elseif ($i['atende'] === 'NAO') { if ($i['obrigatorio']) { $faltando++; $pend[] = $i['texto']; } else $recom[] = $i['texto']; }
            else $incertos++;
        }
        foreach ($regras as $r) {
            if ($r['ok'] === true) $conformes++;
            elseif ($r['ok'] === false) { $faltando++; $pend[] = $r['texto'] . ($r['detalhe'] !== '' ? ' (' . $r['detalhe'] . ')' : ''); }
            else $incertos++;
        }
        $total = count($itens) + count($regras);
        $resultado = $faltando > 0 ? 'NAO_CONFORME' : ($incertos > 0 ? 'INCERTO' : 'CONFORME');
        $parecer = $T('p.conforme_de', ['pt' => 'Conforme o modelo: {c} de {t} item(ns).', 'en' => 'Per the template: {c} of {t} item(s).', 'es' => 'Según el modelo: {c} de {t} ítem(s).'], ['c' => $conformes, 't' => $total]);
        if ($pend) $parecer .= ' ' . $T('p.falta', ['pt' => 'Falta ou não confere: {l}.', 'en' => 'Missing or not matching: {l}.', 'es' => 'Falta o no coincide: {l}.'], ['l' => implode('; ', $pend)]);
        if ($recom) $parecer .= ' ' . $T('p.recomendado', ['pt' => 'Recomendado e ausente: {l}.', 'en' => 'Recommended and missing: {l}.', 'es' => 'Recomendado y ausente: {l}.'], ['l' => implode('; ', $recom)]);
        if ($incertos) $parecer .= ' ' . $T('p.incertos', ['pt' => '{n} item(ns) não puderam ser confirmados pela leitura: confira no arquivo.', 'en' => '{n} item(s) could not be confirmed: check the file.', 'es' => '{n} ítem(s) no se pudieron confirmar: revise el archivo.'], ['n' => $incertos]);
        return ['codmodelo' => (int)($m['CODMODELO'] ?? 0), 'itens' => $itens, 'regras' => $regras, 'conformes' => $conformes, 'total' => $total, 'faltando' => $faltando,
            'incertos' => $incertos, 'resultado' => $resultado, 'pendencias' => $pend, 'recomendados' => $recom, 'parecer' => $parecer];
    }

    /** Observação sugerida para devolver o documento ao fornecedor (só quando falta item obrigatório ou regra). */
    public static function observacaoDevolucao(array $modelo): ?string
    {
        if (empty($modelo['pendencias'])) return null;
        return mb_substr(IdiomaService::t('doc_modelo.obs_devolucao', ['pt' => 'Documento não atende ao modelo exigido. Falta ou não confere:', 'en' => 'The document does not meet the required template. Missing or not matching:', 'es' => 'El documento no cumple el modelo exigido. Falta o no coincide:'])
            . ' ' . implode('; ', $modelo['pendencias']) . '.', 0, 480);
    }

    // ------------------------------------------------------------------ serviço "ia" do Agendador

    /** Uma execução do serviço: varredura (a cada VARREDURA_MIN minutos) e leituras prontas indo para a IA. */
    public static function processar(PDO $pdo, array $env, string $banco): int
    {
        if (!self::disponivel($pdo)) return 0;
        $n = 0;
        try {
            $ultima = (string)(self::chamar($pdo, "SELECT VALOR FROM vw_doc_modelo_controle WHERE CHAVE = 'ULTIMA_VARREDURA'")[0]['VALOR'] ?? '');
            $agora = (int)(self::chamar($pdo, 'SELECT UNIX_TIMESTAMP() AS T')[0]['T'] ?? time());
            if ($ultima === '' || $agora - (int)$ultima >= self::VARREDURA_MIN * 60) {
                self::chamar($pdo, 'CALL sp_doc_modelo_controle(?, ?)', ['ULTIMA_VARREDURA', (string)$agora]);
                $n += self::varrer($pdo, $env, $banco);
            }
        } catch (Throwable $e) { error_log('[modelos de documento] varredura ' . $banco . ': ' . $e->getMessage()); }
        try { $n += self::preparar($pdo); } catch (Throwable $e) { error_log('[modelos de documento] preparar ' . $banco . ': ' . $e->getMessage()); }
        return $n;
    }

    /** Arquivos novos dos documentos pendentes com a empresa que têm modelo: vão para a leitura (OCR). Devolve quantos. */
    public static function varrer(PDO $pdo, array $env, string $banco, int $lote = self::LOTE): int
    {
        require_once __DIR__ . '/GedStorageService.php';
        require_once __DIR__ . '/HomologacaoBancos.php';
        require_once __DIR__ . '/OcrQueueService.php';
        $legado = HomologacaoBancos::legado($banco);
        $ged = new GedStorageService($pdo, $env, ['tenant' => ['banco_dados' => $banco] + ($legado !== null ? ['banco_legado' => $legado] : [])]);
        $ocr = new OcrQueueService($pdo, $env);
        $postos = 0;
        foreach (self::ESCOPOS as $escopo => $cfg) {
            if ($postos >= $lote) break;
            $cands = self::chamar($pdo, 'CALL sp_doc_modelo_candidatos(?, ?)', [$escopo, 2000]);
            if (!$cands) continue;
            $ja = [];
            foreach (array_chunk(array_map(static fn($c) => (int)$c['CODIGO'], $cands), 500) as $parte)
                foreach (self::chamar($pdo, 'CALL sp_doc_modelo_validacoes_hashes(?, ?)', [$escopo, json_encode($parte)]) as $h) { $ja[(int)$h['CODIGO']]['n'][(string)$h['ARQUIVO']] = true; $ja[(int)$h['CODIGO']]['h'][(string)$h['HASH']] = true; }
            foreach ($cands as $c) {
                if ($postos >= $lote) break;
                $codigo = (int)$c['CODIGO']; $desde = (int)($c['ATIVADO_TS'] ?? 0);
                foreach ($ged->folders($cfg['pasta'], $codigo) as $dir) {
                    if (!is_dir($dir)) continue;
                    $nomes = @scandir($dir);
                    if ($nomes === false) continue;
                    foreach ($nomes as $n) {
                        if ($postos >= $lote) break 2;
                        $f = new SplFileInfo($dir . DIRECTORY_SEPARATOR . $n);
                        if ($n === '.' || $n === '..' || !$f->isFile()) continue;
                        $nome = $f->getFilename();
                        if (isset($ja[$codigo]['n'][$nome]) || !in_array(strtolower(pathinfo($nome, PATHINFO_EXTENSION)), self::EXTENSOES, true) || $f->getSize() > self::MAX_BYTES || $f->getSize() < 1) continue;
                        if (!(int)$c['VALIDAR_EXISTENTES'] && $desde > 0 && $f->getMTime() < $desde) continue;
                        $caminho = $f->getPathname();
                        $hash = (string)hash_file('sha256', $caminho);
                        if ($hash === '' || isset($ja[$codigo]['h'][$hash])) continue;
                        $ja[$codigo]['n'][$nome] = true; $ja[$codigo]['h'][$hash] = true;
                        // Já analisado pela IA neste documento (botão Analisar): não envia de novo
                        if (self::jaAnalisado($pdo, $cfg['ged'], $codigo, $hash)) {
                            self::chamar($pdo, 'CALL sp_doc_modelo_validacao_registrar(?, ?, ?, ?, ?, ?, ?, ?)', [$escopo, $codigo, (int)$c['CODMODELO'], $nome, $hash, 'IGNORADA', 0, 'Arquivo já analisado pela IA neste documento (análise manual).']);
                            continue;
                        }
                        $dados = ['chave_idempotencia' => 'GED_EXTRACAO:' . $cfg['ged'] . ':' . $codigo . ':' . $nome . ':' . $hash, 'tipo' => strtoupper($cfg['ged']), 'entidade' => $cfg['pasta'],
                            'registro_id' => (string)$codigo, 'arquivo' => $nome, 'caminho_arquivo' => $caminho, 'tamanho_bytes' => $f->getSize(), 'codusuario' => null];
                        $job = $ocr->enqueue($dados);
                        if (in_array((string)($job['status'] ?? ''), ['ERRO', 'CANCELADA'], true)) $job = $ocr->enqueue(['chave_idempotencia' => substr($dados['chave_idempotencia'], 0, 170) . ':R' . date('YmdHis')] + $dados);
                        $cod = (int)(self::chamar($pdo, 'CALL sp_doc_modelo_validacao_registrar(?, ?, ?, ?, ?, ?, ?, ?)', [$escopo, $codigo, (int)$c['CODMODELO'], $nome, $hash, 'AGUARDANDO_TEXTO', (int)$job['codjob'], null])[0]['CODVALIDACAO'] ?? 0);
                        if ($cod > 0) $postos++;
                    }
                }
            }
        }
        return $postos;
    }

    private static function jaAnalisado(PDO $pdo, string $tipoGed, int $codigo, string $hash): bool
    {
        try {
            $q = $pdo->prepare("SELECT COUNT(*) FROM vw_ged_analises_ia WHERE tipo = ? AND codigo_registro = ? AND hash_arquivo = ?
                AND (status IN ('CONCLUIDA','CONFIRMADA','DESCARTADA') OR (status = 'PROCESSANDO' AND solicitado_em >= NOW() - INTERVAL 30 MINUTE))");
            $q->execute([$tipoGed, $codigo, $hash]); $n = (int)$q->fetchColumn(); $q->closeCursor();
            return $n > 0;
        } catch (Throwable) { return false; }
    }

    /** Leituras prontas: texto + cadastro + modelo vão para a fila de IA. Devolve quantos pedidos foram feitos. */
    public static function preparar(PDO $pdo, int $limite = 10): int
    {
        require_once __DIR__ . '/DocumentAiQueueService.php';
        require_once __DIR__ . '/GedAnaliseIaRegistro.php';
        require_once __DIR__ . '/FuncionarioDocumentoTexto.php';
        $feitos = 0;
        foreach (self::chamar($pdo, 'CALL sp_doc_modelo_validacoes_aguardando(?)', [$limite]) as $v) {
            $cod = (int)$v['CODVALIDACAO']; $escopo = (string)$v['ESCOPO']; $cfg = self::ESCOPOS[$escopo] ?? null; $codigo = (int)$v['CODIGO'];
            if (!$cfg) { self::erro($pdo, $cod, 'ERRO', 'Escopo inválido.'); continue; }
            $st = (string)($v['OCR_STATUS'] ?? '');
            if (in_array($st, ['PENDENTE', 'PROCESSANDO'], true)) { if ((int)$v['IDADE_MIN'] >= self::OCR_ESPERA_HORAS * 60) self::erro($pdo, $cod, 'ERRO', 'A leitura do arquivo (OCR) não terminou a tempo.'); continue; }
            if ($st !== 'CONCLUIDA') { self::erro($pdo, $cod, 'ERRO', 'Não foi possível ler o texto do arquivo' . (trim((string)($v['OCR_ERRO'] ?? '')) !== '' ? ': ' . mb_substr((string)$v['OCR_ERRO'], 0, 300) : '.')); continue; }
            try {
                $ocr = self::chamar($pdo, 'CALL sp_doc_modelo_ocr_texto(?)', [(int)$v['OCR_JOB']])[0] ?? [];
                $texto = trim((string)($ocr['texto'] ?? ''));
                $modelo = self::doDocumento($pdo, $escopo, $codigo);
                if (!$modelo) { self::erro($pdo, $cod, 'IGNORADA', 'O modelo do critério foi desativado ou removido.'); continue; }
                $registro = self::chamar($pdo, 'CALL ' . $cfg['registro'] . '(?)', [$codigo])[0] ?? null;
                if (!$registro) { self::erro($pdo, $cod, 'IGNORADA', 'Documento não localizado.'); continue; }
                $omitidas = 0;
                if ($escopo === 'FUNCIONARIO') { $limpo = FuncionarioDocumentoTexto::limpar($texto); $texto = $limpo['texto']; $omitidas = (int)$limpo['omitidas']; }
                if ($texto === '') { self::erro($pdo, $cod, 'ERRO', 'O documento não tem texto legível para análise.'); continue; }
                $contexto = $escopo === 'FORNECEDOR'
                    ? 'DOCUMENTO EXIGIDO: ' . $registro['DSCRITERIO'] . "\nFORNECEDOR: " . $registro['RAZAOSOCIAL'] . (preg_match('/\d/', (string)($registro['CNPJ'] ?? '')) ? ' (CNPJ ' . $registro['CNPJ'] . ')' : '') . "\n"
                    : 'CRITÉRIO EXIGIDO: ' . $registro['DSCRITERIO'] . "\nFUNCIONÁRIO: " . $registro['FUNCIONARIO'] . ($registro['DSCTIPO'] ? ' (' . $registro['DSCTIPO'] . ')' : '') . "\nEMPRESA: " . $registro['RAZAOSOCIAL'] . "\n";
                $contexto .= self::instrucoes($modelo, $escopo) . "---\n";
                $prompt = trim((string)(self::chamar($pdo, 'CALL sp_ia_prompt_obter(?, ?)', [$cfg['prompt'], 'pt'])[0]['prompt'] ?? ''));
                if ($prompt === '') { self::erro($pdo, $cod, 'ERRO', 'Prompt ' . $cfg['prompt'] . ' não configurado (Setup › Prompts de IA).'); continue; }
                try {
                    $job = (new DocumentAiQueueService($pdo))->enqueue(['chave_idempotencia' => 'IA:MODELO:' . substr($escopo, 0, 4) . ':' . $cod, 'tipo' => $cfg['tipo_ia'], 'entidade' => $cfg['pasta'],
                        'registro_id' => (string)$codigo, 'arquivo' => (string)$v['ARQUIVO'], 'codanalise_ged' => 0, 'codusuario' => 0, 'idioma' => 'pt', 'prompt' => $prompt, 'texto' => $contexto . $texto]);
                } catch (IaLimiteExcedido) { continue; } // limite mensal de IA: espera o próximo mês ou o aumento do limite
                $descricao = $escopo === 'FORNECEDOR' ? (string)$registro['RAZAOSOCIAL'] . ' / ' . (string)$registro['DSCRITERIO'] : (string)$registro['FUNCIONARIO'] . ' / ' . (string)$registro['DSCRITERIO'];
                $codGed = (new GedAnaliseIaRegistro($pdo, 0))->iniciar($cfg['ged'], $cfg['chave'], $codigo, (string)$v['ARQUIVO'], $descricao, (string)$v['HASH']);
                self::chamar($pdo, 'CALL sp_doc_modelo_validacao_ia(?, ?, ?)', [$cod, (int)$job['codjob'], $codGed]);
                $feitos++;
                // Pedido que já existia e terminou (mesma chave): conclui agora
                if (in_array((string)($job['status'] ?? ''), ['CONCLUIDA', 'ERRO'], true)) self::aoConcluirJob($pdo, ['codjob' => (int)$job['codjob'], 'tipo' => $cfg['tipo_ia']]);
            } catch (Throwable $e) { error_log('[modelos de documento] validação ' . $cod . ': ' . $e->getMessage()); self::erro($pdo, $cod, 'ERRO', mb_substr($e->getMessage(), 0, 500)); }
        }
        return $feitos;
    }

    private static function erro(PDO $pdo, int $cod, string $status, string $msg): void
    {
        try { self::chamar($pdo, 'CALL sp_doc_modelo_validacao_erro(?, ?, ?)', [$cod, $status, $msg]); } catch (Throwable $e) { error_log('[modelos de documento] ' . $e->getMessage()); }
    }

    /**
     * Fila de IA (DocumentAiQueueService::notificar): pedido de validação automática terminou. Grava a análise pendente de revisão,
     * conclui a validação e avisa o responsável da empresa. Pedido manual (botão Analisar) não tem validação: nada a fazer.
     */
    public static function aoConcluirJob(PDO $pdo, array $job): void
    {
        if (!in_array((string)($job['tipo'] ?? ''), ['FORNECEDORCRITERIOS', 'FUNCIONARIOCRITERIOS'], true) || !self::disponivel($pdo)) return;
        $v = self::chamar($pdo, 'CALL sp_doc_modelo_validacao_por_job(?)', [(int)$job['codjob']])[0] ?? null;
        if (!$v) return;
        require_once __DIR__ . '/GedAnaliseIaRegistro.php';
        require_once __DIR__ . '/HomologacaoDocumentoIa.php';
        $cod = (int)$v['CODVALIDACAO']; $escopo = (string)$v['ESCOPO']; $cfg = self::ESCOPOS[$escopo]; $codigo = (int)$v['CODIGO'];
        $ged = new GedAnaliseIaRegistro($pdo, 0);
        if ((string)$v['IA_STATUS'] === 'ERRO') { $ged->falhar((int)$v['CODANALISE_GED'], (string)$v['IA_ERRO']); self::erro($pdo, $cod, 'ERRO', 'IA: ' . mb_substr((string)$v['IA_ERRO'], 0, 500)); return; }
        if ((string)$v['IA_STATUS'] !== 'CONCLUIDA') return; // nova tentativa da fila
        try {
            $ia = json_decode((string)$v['IA_RESULTADO'], true);
            $s = is_array($ia['sugestao'] ?? null) ? $ia['sugestao'] : [];
            $registro = self::chamar($pdo, 'CALL ' . $cfg['registro'] . '(?)', [$codigo])[0] ?? null;
            if (!$registro) { self::erro($pdo, $cod, 'IGNORADA', 'Documento não localizado.'); return; }
            $modelo = self::doDocumento($pdo, $escopo, $codigo);
            $ocr = self::chamar($pdo, 'CALL sp_doc_modelo_ocr_texto(?)', [(int)$v['OCR_JOB']])[0] ?? [];
            $extracao = ['fonte' => 'OCR_WORKER', 'confianca' => (float)($ocr['confianca'] ?? 0)];
            $alertas = [IdiomaService::t('doc_modelo.alerta_automatica', ['pt' => 'Análise automática feita pelo modelo do documento quando o arquivo chegou.', 'en' => 'Automatic analysis by the document template when the file arrived.', 'es' => 'Análisis automático por el modelo del documento al llegar el archivo.'])];
            if ($escopo === 'FORNECEDOR') {
                if (!preg_match('/\d/', (string)($registro['CNPJ'] ?? ''))) $registro['CNPJ'] = null;
                $r = HomologacaoDocumentoIa::fornecedor($pdo, $registro, $codigo, (string)$v['ARQUIVO'], $s, $extracao, $alertas, 0, (int)$v['CODANALISE_GED'], $modelo, $ged);
            } else {
                require_once __DIR__ . '/FuncionarioDocumentoTexto.php';
                $omitidas = FuncionarioDocumentoTexto::limpar((string)($ocr['texto'] ?? ''))['omitidas'];
                $r = HomologacaoDocumentoIa::funcionario($pdo, $registro, $codigo, (string)$v['ARQUIVO'], $s, $extracao, $alertas, (int)$omitidas, 0, (int)$v['CODANALISE_GED'], $modelo, $ged);
            }
            $mp = $r['s']['modelo'] ?? null;
            self::chamar($pdo, 'CALL sp_doc_modelo_validacao_concluir(?, ?, ?, ?, ?, ?, ?)', [$cod, (int)$r['codanalise'], $mp['conformes'] ?? null, $mp['total'] ?? null, $mp['faltando'] ?? null,
                $mp['resultado'] ?? null, mb_substr((string)($mp['parecer'] ?? ''), 0, 1000)]);
            // Aviso ao responsável da empresa pelo documento (vira e-mail pelo serviço de e-mail, conforme a escolha do usuário)
            $u = (int)(self::chamar($pdo, 'SELECT CODUSUARIO_EMPRESA FROM vw_doc_modelo_validacoes WHERE CODVALIDACAO = ?', [$cod])[0]['CODUSUARIO_EMPRESA'] ?? 0);
            if ($u > 0) {
                $doc = $escopo === 'FORNECEDOR' ? trim((string)$registro['RAZAOSOCIAL']) . ': ' . trim((string)$registro['DSCRITERIO']) : trim((string)$registro['FUNCIONARIO']) . ' (' . trim((string)$registro['RAZAOSOCIAL']) . '): ' . trim((string)$registro['DSCRITERIO']);
                $titulo = ($mp && $mp['resultado'] === 'NAO_CONFORME') ? 'Documento fora do modelo — ' : 'Parecer da IA pelo modelo — ';
                $msg = $doc . ' — ' . (string)($mp['parecer'] ?? '') . ' Revise e decida (a IA só sugere).';
                self::chamar($pdo, "CALL sp_alerta_criar(?, ?, ?, ?, 'NAO')", [$u, mb_substr($titulo . trim((string)$registro['DSCRITERIO']), 0, 180), mb_substr($msg, 0, 1000),
                    GedAnaliseIaRegistro::link($cfg['ged'], $cfg['chave'], $codigo, (string)$v['ARQUIVO'])]);
            }
        } catch (Throwable $e) {
            error_log('[modelos de documento] concluir ' . $cod . ': ' . $e->getMessage());
            $ged->falhar((int)$v['CODANALISE_GED'], mb_substr($e->getMessage(), 0, 500));
            self::erro($pdo, $cod, 'ERRO', mb_substr($e->getMessage(), 0, 500));
        }
    }

    // ------------------------------------------------------------------ sugestão de itens a partir do exemplo

    /** Pede à IA a lista de itens a partir do texto do arquivo de exemplo. Devolve o job (status, codjob). */
    public static function pedirSugestao(PDO $pdo, array $modelo, int $usuario): array
    {
        require_once __DIR__ . '/DocumentAiQueueService.php';
        require_once __DIR__ . '/FuncionarioDocumentoTexto.php';
        $texto = trim((string)(self::chamar($pdo, 'SELECT TEXTO_EXEMPLO FROM vw_doc_modelo_texto_exemplo WHERE CODMODELO = ?', [(int)$modelo['CODMODELO']])[0]['TEXTO_EXEMPLO'] ?? ''));
        if ($modelo['ESCOPO'] === 'FUNCIONARIO') $texto = FuncionarioDocumentoTexto::limpar($texto)['texto'];
        if ($texto === '') throw new RuntimeException(IdiomaService::t('doc_modelo.sem_texto_exemplo', ['pt' => 'O texto do arquivo de exemplo ainda não foi lido.', 'en' => 'The sample file text has not been read yet.', 'es' => 'El texto del archivo de ejemplo aún no fue leído.']));
        $idioma = IdiomaService::idioma();
        $prompt = trim((string)(self::chamar($pdo, 'CALL sp_ia_prompt_obter(?, ?)', [self::PROMPT_SUGESTAO, $idioma])[0]['prompt'] ?? ''));
        if ($prompt === '') throw new RuntimeException('Prompt ' . self::PROMPT_SUGESTAO . ' não configurado (rode a migração 347).');
        $contexto = 'DOCUMENTO EXIGIDO: ' . $modelo['CRITERIO'] . "\nESCOPO: " . ($modelo['ESCOPO'] === 'FORNECEDOR' ? 'documento da empresa fornecedora' : 'documento de funcionário do fornecedor') . "\n---\n";
        $job = (new DocumentAiQueueService($pdo))->enqueue(['chave_idempotencia' => 'IA:' . self::TIPO_SUGESTAO . ':' . (int)$modelo['CODMODELO'] . ':' . sha1($idioma . $texto), 'tipo' => self::TIPO_SUGESTAO,
            'entidade' => 'fo_documento_modelos', 'registro_id' => (string)(int)$modelo['CODMODELO'], 'arquivo' => (string)($modelo['ARQUIVO_EXEMPLO'] ?? 'exemplo'), 'codanalise_ged' => 0,
            'codusuario' => $usuario, 'idioma' => $idioma, 'prompt' => $prompt, 'texto' => $contexto . mb_substr($texto, 0, 30000)]);
        self::chamar($pdo, 'CALL sp_doc_modelo_sugestao_job(?, ?)', [(int)$modelo['CODMODELO'], (int)$job['codjob']]);
        return $job;
    }

    /** Sugestão normalizada de um job terminado: itens, orientação e regras sugeridas. */
    public static function lerSugestao(array $job): array
    {
        $r = json_decode((string)($job['resultado_json'] ?? ''), true);
        $s = is_array($r['sugestao'] ?? null) ? $r['sugestao'] : [];
        $num = static fn($v): ?int => is_numeric($v) && (int)$v > 0 && (int)$v <= 3650 ? (int)$v : null;
        return ['itens' => self::itens($s['itens'] ?? []), 'orientacao' => mb_substr(trim((string)($s['orientacao'] ?? '')), 0, 1500),
            'exige_validade' => filter_var($s['exige_validade'] ?? false, FILTER_VALIDATE_BOOLEAN), 'exige_assinatura' => filter_var($s['exige_assinatura'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'emissao_max_dias' => $num($s['emissao_max_dias'] ?? null)];
    }
}
