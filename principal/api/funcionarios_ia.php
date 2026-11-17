<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/classes/DocumentAiQueueService.php';

/*
 * Análise por IA dos documentos dos funcionários (Homologação de Fornecedores): ASO e certificados de treinamento.
 *
 * Ações (POST, com o token csrf da Gestão de Arquivos):
 *   analyze  — extrai o texto do arquivo no servidor (PDF, imagem com OCR, Word), remove linhas com dados de
 *              saúde (diagnóstico, CID, exames…), envia à IA com o prompt FUNCIONARIO_DOCUMENTO e grava a
 *              análise como PENDENTE em fo_funcionarios_documentos_ia. Devolve a sugestão para revisão.
 *   confirm  — grava os dados revisados pelo usuário no critério (ASO) ou treinamento escolhido e protege
 *              esses campos contra o sincronismo legado -> novo (fo_sinc_protecoes).
 *   discard  — marca a análise como DESCARTADA.
 *   list     — análises já feitas para o funcionário.
 *   review   — reabre uma análise ainda pendente para revisão (aba "Revisão dos dados").
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/IdiomaService.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';
require_once dirname(__DIR__, 2) . '/classes/GedStorageService.php';
require_once dirname(__DIR__, 2) . '/classes/LicenseDocumentExtractionService.php';
require_once dirname(__DIR__, 2) . '/classes/InteligenciaArtificialDocumento.php';
require_once dirname(__DIR__, 2) . '/classes/GedAnaliseIaRegistro.php';

$json = static function (array $data, int $status = 200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; };
$t = static fn(string $chave, array $padrao): string => IdiomaService::t('api.funcionarios_ia.' . $chave, $padrao);
$sessaoExpirada = ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.'];
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', $sessaoExpirada)], 401);
$env = GreenPortalDatabase::env();
$pdo = TenantContext::connect($env, $_SESSION);
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', $sessaoExpirada)], 401);

$permissao = PermissaoTransacao::obter('HOMOLOGACAO_FORNECEDOR_FUNCIONARIO');
if (empty($permissao['pode_visualizar'])) $json(['ok' => false, 'message' => $t('sem_permissao', ['pt' => 'Sem permissão para consultar funcionários.', 'en' => 'Not allowed to view employees.', 'es' => 'Sin permiso para consultar empleados.'])], 403);
$csrf = (string)($_SESSION['gestao_arquivos_csrf'] ?? '');
if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $json(['ok' => false, 'message' => IdiomaService::t('api.token_invalido', ['pt' => 'Token de segurança inválido. Recarregue a página e tente novamente.', 'en' => 'Invalid security token. Reload the page and try again.', 'es' => 'Token de seguridad inválido. Recargue la página e inténtelo nuevamente.'])], 419);
$acao = strtolower(trim((string)($_POST['action'] ?? 'analyze')));
if ($acao !== 'list' && empty($permissao['pode_editar'])) $json(['ok' => false, 'message' => $t('sem_permissao_editar', ['pt' => 'Seu perfil não pode analisar nem confirmar documentos de funcionários.', 'en' => 'Your profile cannot analyze or confirm employee documents.', 'es' => 'Su perfil no puede analizar ni confirmar documentos de empleados.'])], 403);
$usuario = (int)($_SESSION['usuario']['codusuario'] ?? 0);

$codfuncionario = max(0, (int)($_POST['valor'] ?? 0));
$q = $pdo->prepare('SELECT CODFUNCIONARIO, NOME, NRIDENTIFICACAO, CODTIPOFUNCIONARIO, CODFORNECEDOR, DSCTIPO, RAZAOSOCIAL FROM vw_fo_funcionario_ia WHERE CODFUNCIONARIO = ?');
$q->execute([$codfuncionario]);
$funcionario = $q->fetch(PDO::FETCH_ASSOC);
if (!$funcionario) $json(['ok' => false, 'message' => $t('funcionario_nao_localizado', ['pt' => 'Funcionário não localizado.', 'en' => 'Employee not found.', 'es' => 'Empleado no encontrado.'])], 404);
// Migração 297: o fornecedor externo só trata funcionários dos fornecedores ligados a ele.
require_once dirname(__DIR__, 2) . '/classes/HomologacaoFornecedorAcesso.php';
if (!HomologacaoFornecedorAcesso::pode($pdo, 'funcionario', $codfuncionario)) $json(['ok' => false, 'message' => HomologacaoFornecedorAcesso::mensagemNegado()], 403);

$auditar = static function (string $acao, array $detalhes) use ($pdo, $usuario, $codfuncionario): void {
    try {
        GreenPortalDatabase::call($pdo, 'CALL sp_auditoria_registrar(?, ?, ?, ?, ?, ?, ?)', [(int)$usuario, 'HOMOLOGACAO_FORNECEDOR_FUNCIONARIO', $acao, 'fo_funcionarios_documentos_ia', (string)$codfuncionario, 'SUCESSO', json_encode($detalhes, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) { error_log($e->getMessage()); }
};

/** Critérios e treinamentos do funcionário (destinos possíveis da confirmação). */
$destinos = static function () use ($pdo, $codfuncionario): array {
    $c = $pdo->prepare('SELECT codigo, descricao, codtipofuncionario, validade, situacao FROM vw_fo_funcionario_criterios_destino WHERE codfuncionario = ? ORDER BY descricao');
    $c->execute([$codfuncionario]);
    $tr = $pdo->prepare('SELECT codigo, descricao, codtipofuncionario, validade, situacao FROM vw_fo_funcionario_treinamentos_destino WHERE codfuncionario = ? ORDER BY descricao');
    $tr->execute([$codfuncionario]);
    return ['criterios' => $c->fetchAll(PDO::FETCH_ASSOC), 'treinamentos' => $tr->fetchAll(PDO::FETCH_ASSOC)];
};
$normalizar = static function (?string $s): string {
    $s = (string)$s;
    $s = class_exists('Normalizer') ? (string)Normalizer::normalize($s, Normalizer::FORM_D) : $s;
    $s = preg_replace('/\p{Mn}+/u', '', $s) ?? $s;
    return trim(preg_replace('/[^A-Z0-9 ]+/', ' ', strtoupper($s)) ?? '');
};

/** Critério (ASO) ou treinamento (mesma norma ou nome parecido) sugerido como destino da confirmação. */
$sugerirDestino = static function (string $tipo, array $s, array $opcoes) use ($normalizar): ?int {
    $sugerido = null;
    if ($tipo === 'ASO') {
        foreach ($opcoes['criterios'] as $o) if (preg_match('/\bASO\b|sa[uú]de\s+ocupacional|atestado\s+de\s+sa/iu', (string)$o['descricao'])) return (int)$o['codigo'];
    } elseif ($tipo === 'TREINAMENTO') {
        $norma = preg_match('/NR\s*-?\s*0?(\d{1,2})/i', (string)($s['treinamento_norma'] ?? '') . ' ' . (string)($s['treinamento_nome'] ?? ''), $m) ? (int)$m[1] : 0;
        foreach ($opcoes['treinamentos'] as $o) if ($norma > 0 && preg_match('/NR\s*-?\s*0?' . $norma . '\b/i', (string)$o['descricao'])) return (int)$o['codigo'];
        if (!empty($s['treinamento_nome'])) {
            $melhor = 0.0;
            foreach ($opcoes['treinamentos'] as $o) { similar_text($normalizar((string)$s['treinamento_nome']), $normalizar((string)$o['descricao']), $pct); if ($pct > $melhor && $pct >= 60) { $melhor = $pct; $sugerido = (int)$o['codigo']; } }
        }
    }
    return $sugerido;
};

if ($acao === 'list') {
    $l = $pdo->prepare('SELECT codanalise, arquivo, tipo_documento, status, confianca, destino_tabela, destino_codigo, analisado_em, confirmado_em FROM vw_fo_funcionarios_documentos_ia WHERE codfuncionario = ? ORDER BY codanalise DESC LIMIT 50');
    $l->execute([$codfuncionario]);
    $json(['ok' => true, 'analises' => $l->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($acao === 'analyze') {
    @set_time_limit(240);
    $iaResultado = null; $filaJob = null;
    if (!empty($_POST['ia_job_id'])) {
        $filaJob = (new DocumentAiQueueService($pdo))->find((int)$_POST['ia_job_id'], $usuario);
        if (!$filaJob) $json(['ok'=>false,'message'=>'Job de IA não localizado.'],404);
        if (in_array($filaJob['status'], ['PENDENTE','PROCESSANDO'], true)) $json(['ok'=>true,'queued'=>true,'status'=>$filaJob['status'],'ia_job_id'=>(int)$filaJob['codjob']],202);
        if ($filaJob['status'] === 'ERRO') $json(['ok'=>false,'message'=>(string)$filaJob['erro']],422);
        $iaResultado = json_decode((string)$filaJob['resultado_json'], true);
        if (!is_array($iaResultado)) $json(['ok'=>false,'message'=>'Resultado da IA inválido.'],422);
    }
    $nomeArquivo = basename((string)($_POST['arquivo'] ?? ''));
    $storage = new GedStorageService($pdo, $env, $_SESSION);
    $arquivo = $storage->resolve('fo_funcionarios', $codfuncionario, $nomeArquivo);
    if ($arquivo === null) $json(['ok' => false, 'message' => $t('arquivo_nao_localizado', ['pt' => 'Arquivo do funcionário não localizado.', 'en' => 'Employee file not found.', 'es' => 'Archivo del empleado no encontrado.'])], 404);
    // Controle de duplicatas: o mesmo documento (mesmo conteúdo) não é enviado de novo à IA.
    $registro = new GedAnaliseIaRegistro($pdo, $usuario);
    if ($filaJob === null) {
        try { $decisao = $registro->preparar('funcionarios', 'CODFUNCIONARIO', $codfuncionario, $nomeArquivo, $arquivo, !empty($_POST['forcar']), $_SESSION, (string)$funcionario['NOME']); }
        catch (Throwable $e) { $json(['ok' => false, 'message' => $e->getMessage()], 403); }
        if (in_array($decisao['acao'], ['DUPLICADO', 'EM_ANDAMENTO'], true)) $json(GedAnaliseIaRegistro::respostaDuplicado($decisao) + ['pode_reanalisar' => GedAnaliseIaRegistro::podeReanalisar($_SESSION)]);
        $codGed = (int)$decisao['codanalise'];
    } else { $codGed = (int)$filaJob['codanalise_ged']; $decisao = ['acao'=>'IA_FILA','codanalise'=>$codGed]; }
    $falhar = static function (string $mensagem, int $status) use ($registro, $codGed, $json): never { $registro->falhar($codGed, $mensagem); $json(['ok' => false, 'message' => $mensagem], $status); };
    if ($decisao['acao'] === 'REAPROVEITAR') {
        // Mesmo documento já lido pela IA em outro funcionário: reaproveita a leitura, sem novo envio.
        // As conferências deste funcionário (nome, CPF, critério sugerido) são refeitas abaixo.
        $origem = $decisao['origem'];
        $resposta = ['sugestao' => (is_array($origem['dados_ia']) ? $origem['dados_ia'] : []) + []];
        $resposta['sugestao']['alertas'] = [$t('alerta_reaproveitada', ['pt' => 'Leitura reaproveitada de análise anterior do mesmo documento (não foi enviado de novo à IA).', 'en' => 'Reading reused from a previous analysis of the same document (not sent to the AI again).', 'es' => 'Lectura reaprovechada de un análisis anterior del mismo documento (no se envió de nuevo a la IA).'])];
        $extracao = ['fonte' => (string)($origem['fonte_texto'] ?? ''), 'confianca' => 0];
        $omitidas = 0;
    } else {
        try {
            $textoFila = trim((string)($_POST['texto'] ?? ''));
            $extracao = $textoFila !== ''
                ? ['texto' => $textoFila, 'fonte' => (string)($_POST['fonte'] ?? 'OCR_WORKER'), 'confianca' => (float)($_POST['confianca_texto'] ?? 0)]
                : (function () use ($env, $arquivo): array {
                    throw new RuntimeException('A extração deve ser realizada pelo worker OCR antes da análise.');
                })();
        } catch (Throwable $e) {
            $falhar($t('extracao', ['pt' => 'Não foi possível ler o texto do documento: ', 'en' => 'Could not read the document text: ', 'es' => 'No fue posible leer el texto del documento: ']) . $e->getMessage(), 422);
        }
        // Dados de saúde não saem do servidor: remove linhas com diagnóstico, CID, exames, sintomas e afins
        // e mascara códigos no formato da CID-10 (ex.: M54.5). "Altura" não entra na lista: é o nome do treinamento NR-35.
        $omitidas = 0;
        $linhas = [];
        foreach (preg_split('/\R/u', (string)$extracao['texto']) ?: [] as $linha) {
            if (preg_match('/\b(c\.?\s?i\.?\s?d|diagn[oó]s|patolog|doen[cç]a|enfermidade|sintoma|queixa|anamnese|hist[oó]ri(co|a)\s+(cl[ií]nic|m[eé]dic)|medica(mento|[cç][aã]o)|exames?\s+(complementar|cl[ií]nic|laborat)|audiometr|espirometr|hemograma|glicemia|raio[\s-]?x|eletrocardio|acuidade|press[aã]o\s+arterial|\bimc\b|gestante|gravidez|defici[eê]ncia|afastamento\s+m[eé]dic)/iu', $linha)) { $omitidas++; continue; }
            $linhas[] = preg_replace('/\b[A-TV-Z]\d{2}(\.\d{1,2})?\b/u', '[omitido]', $linha) ?? $linha;
        }
        $texto = trim(implode("\n", $linhas));
        if ($texto === '') $falhar($t('sem_texto', ['pt' => 'O documento não tem texto legível para análise.', 'en' => 'The document has no readable text to analyze.', 'es' => 'El documento no tiene texto legible para analizar.']), 422);
        $idioma = (string)($_SESSION['idioma'] ?? 'pt');
        try {
            $p = GreenPortalDatabase::call($pdo, 'CALL sp_ia_prompt_obter(:codigo,:idioma)', ['codigo' => 'FUNCIONARIO_DOCUMENTO', 'idioma' => $idioma]);
            $prompt = trim((string)($p[0]['prompt'] ?? ''));
            if ($prompt === '') throw new RuntimeException($t('prompt', ['pt' => 'Prompt FUNCIONARIO_DOCUMENTO não configurado (rode a migração 221).', 'en' => 'FUNCIONARIO_DOCUMENTO prompt not configured (run migration 221).', 'es' => 'Prompt FUNCIONARIO_DOCUMENTO no configurado (ejecute la migración 221).']));
            if ($iaResultado === null) {
                $job = (new DocumentAiQueueService($pdo))->enqueue(['chave_idempotencia'=>'IA:FUNCIONARIOS:'.$codfuncionario.':'.$nomeArquivo.':'.hash('sha256',$texto),'tipo'=>'FUNCIONARIOS','entidade'=>'fo_funcionarios','registro_id'=>(string)$codfuncionario,'arquivo'=>$nomeArquivo,'codanalise_ged'=>$codGed,'codusuario'=>$usuario,'idioma'=>$idioma,'prompt'=>$prompt,'texto'=>$texto]);
                $json(['ok'=>true,'queued'=>true,'status'=>$job['status'],'ia_job_id'=>(int)$job['codjob'],'codanalise'=>$codGed,'arquivo'=>$nomeArquivo],202);
            }
            $resposta = $iaResultado;
        } catch (Throwable $e) {
            $falhar($e->getMessage(), 422);
        }
    }
    $s = is_array($resposta['sugestao'] ?? null) ? $resposta['sugestao'] : [];
    // Mesmo que a IA devolva algo de saúde além do pedido, só os campos previstos seguem adiante.
    $permitidos = ['tipo_documento', 'nome', 'cpf', 'rg', 'funcao', 'empresa', 'cnpj', 'aso_tipo_exame', 'aso_resultado', 'aso_data_exame', 'aso_validade', 'aso_medico', 'aso_crm',
        'treinamento_nome', 'treinamento_norma', 'treinamento_carga_horaria', 'treinamento_data_realizacao', 'treinamento_validade', 'treinamento_instituicao', 'treinamento_instrutor', 'confianca', 'alertas'];
    $s = array_intersect_key($s, array_flip($permitidos));
    $tipo = strtoupper((string)($s['tipo_documento'] ?? 'OUTRO'));
    $tipo = in_array($tipo, ['ASO', 'TREINAMENTO'], true) ? $tipo : 'OUTRO';
    $s['tipo_documento'] = $tipo;
    $confianca = (float)($s['confianca'] ?? 0); if ($confianca > 0 && $confianca <= 1) $confianca *= 100;
    $alertas = array_values(array_filter(array_map('strval', (array)($s['alertas'] ?? []))));

    // Conferência com o cadastro do funcionário.
    $nomeDoc = $normalizar($s['nome'] ?? '');
    $nomeCad = $normalizar($funcionario['NOME'] ?? '');
    if ($nomeDoc !== '' && $nomeCad !== '') {
        similar_text($nomeDoc, $nomeCad, $pct);
        if ($pct < 80) $alertas[] = IdiomaService::f('api.funcionarios_ia.alerta_nome', ['pt' => 'O nome no documento ({doc}) é diferente do cadastro ({cad}).', 'en' => 'The name on the document ({doc}) differs from the record ({cad}).', 'es' => 'El nombre en el documento ({doc}) difiere del registro ({cad}).'], ['doc' => (string)$s['nome'], 'cad' => (string)$funcionario['NOME']]);
    }
    // A IA às vezes coloca o nome do próprio funcionário no campo do médico examinador: descarta.
    foreach (['aso_medico', 'treinamento_instrutor'] as $campoPessoa) {
        $valorPessoa = $normalizar($s[$campoPessoa] ?? '');
        if ($valorPessoa !== '' && $nomeCad !== '') { similar_text($valorPessoa, $nomeCad, $pctPessoa); if ($pctPessoa >= 80) $s[$campoPessoa] = null; }
    }
    $cpfDoc = preg_replace('/\D/', '', (string)($s['cpf'] ?? '')) ?? '';
    $cpfCad = preg_replace('/\D/', '', (string)($funcionario['NRIDENTIFICACAO'] ?? '')) ?? '';
    if (strlen($cpfDoc) === 11 && strlen($cpfCad) === 11 && $cpfDoc !== $cpfCad) $alertas[] = $t('alerta_cpf', ['pt' => 'O CPF do documento é diferente do Nº de Registro do cadastro.', 'en' => 'The document CPF differs from the record registration number.', 'es' => 'El CPF del documento difiere del N.º de registro.']);
    if ($omitidas > 0) $alertas[] = IdiomaService::f('api.funcionarios_ia.alerta_omitidas', ['pt' => '{n} linha(s) com dados de saúde foram omitidas antes do envio à IA.', 'en' => '{n} line(s) with health data were omitted before sending to the AI.', 'es' => '{n} línea(s) con datos de salud se omitieron antes del envío a la IA.'], ['n' => $omitidas]);
    if (str_starts_with((string)($extracao['fonte'] ?? ''), 'OCR')) $alertas[] = $t('alerta_ocr', ['pt' => 'Documento lido por OCR (imagem ou digitalização): confira os dados com atenção.', 'en' => 'Document read by OCR (image or scan): check the data carefully.', 'es' => 'Documento leído por OCR (imagen o escaneo): revise los datos con atención.']);
    $s['alertas'] = $alertas;

    $opcoes = $destinos();
    $sugerido = $sugerirDestino($tipo, $s, $opcoes);

    $codanalise = (int)(GreenPortalDatabase::call($pdo, 'CALL sp_crud_insert(?, ?)', ['fo_funcionarios_documentos_ia', json_encode(['codfuncionario' => $codfuncionario, 'arquivo' => $nomeArquivo,
        'tipo_documento' => $tipo, 'fonte_texto' => (string)($extracao['fonte'] ?? ''), 'confianca_texto' => (float)($extracao['confianca'] ?? 0), 'linhas_omitidas' => $omitidas,
        'dados_ia' => json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'confianca' => $confianca, 'status' => 'PENDENTE', 'codusuario_analise' => $usuario ?: null],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)])[0]['chave_gerada'] ?? 0);
    $registro->concluir($codGed, $s, $confianca, (string)($extracao['fonte'] ?? ''), $codanalise);
    $auditar('ANALISE_IA', ['codanalise' => $codanalise, 'arquivo' => $nomeArquivo, 'tipo' => $tipo, 'linhas_omitidas' => $omitidas]);
    $json(['ok' => true, 'codanalise' => $codanalise, 'codanalise_ged' => $codGed, 'reaproveitada' => $decisao['acao'] === 'REAPROVEITAR', 'arquivo' => $nomeArquivo, 'sugestao' => $s, 'confianca' => $confianca,
        'fonte_texto' => (string)($extracao['fonte'] ?? ''), 'linhas_omitidas' => $omitidas,
        'funcionario' => ['nome' => $funcionario['NOME'], 'registro' => $funcionario['NRIDENTIFICACAO'], 'funcao' => $funcionario['DSCTIPO'], 'fornecedor' => $funcionario['RAZAOSOCIAL']],
        'criterios' => $opcoes['criterios'], 'treinamentos' => $opcoes['treinamentos'], 'destino_sugerido' => $sugerido]);
}

$codanalise = max(0, (int)($_POST['codanalise'] ?? 0));
$a = $pdo->prepare('SELECT * FROM vw_fo_funcionarios_documentos_ia WHERE codanalise = ? AND codfuncionario = ?');
$a->execute([$codanalise, $codfuncionario]);
$analise = $a->fetch(PDO::FETCH_ASSOC);
if (!$analise) $json(['ok' => false, 'message' => $t('analise_nao_localizada', ['pt' => 'Análise não localizada.', 'en' => 'Analysis not found.', 'es' => 'Análisis no encontrado.'])], 404);
if ($analise['status'] !== 'PENDENTE') $json(['ok' => false, 'message' => $t('analise_encerrada', ['pt' => 'Esta análise já foi confirmada ou descartada.', 'en' => 'This analysis was already confirmed or discarded.', 'es' => 'Este análisis ya fue confirmado o descartado.'])], 409);

if ($acao === 'review') {
    $s = json_decode((string)($analise['dados_ia'] ?? '{}'), true) ?: [];
    $tipo = in_array((string)$analise['tipo_documento'], ['ASO', 'TREINAMENTO'], true) ? (string)$analise['tipo_documento'] : 'OUTRO';
    $opcoes = $destinos();
    $json(['ok' => true, 'codanalise' => $codanalise, 'arquivo' => $analise['arquivo'], 'sugestao' => $s, 'confianca' => (float)($analise['confianca'] ?? 0),
        'fonte_texto' => (string)($analise['fonte_texto'] ?? ''), 'linhas_omitidas' => (int)$analise['linhas_omitidas'],
        'funcionario' => ['nome' => $funcionario['NOME'], 'registro' => $funcionario['NRIDENTIFICACAO'], 'funcao' => $funcionario['DSCTIPO'], 'fornecedor' => $funcionario['RAZAOSOCIAL']],
        'criterios' => $opcoes['criterios'], 'treinamentos' => $opcoes['treinamentos'], 'destino_sugerido' => $sugerirDestino($tipo, $s, $opcoes)]);
}
if ($acao === 'discard') {
    GreenPortalDatabase::call($pdo, 'CALL sp_fo_documento_ia_descartar(?, ?)', [$codanalise, (int)$usuario]);
    $auditar('ANALISE_IA_DESCARTADA', ['codanalise' => $codanalise]);
    (new GedAnaliseIaRegistro($pdo, $usuario))->encerrarPorReferencia('funcionarios', $codanalise, 'DESCARTADA');
    $json(['ok' => true, 'message' => $t('descartada', ['pt' => 'Análise descartada.', 'en' => 'Analysis discarded.', 'es' => 'Análisis descartado.'])]);
}
if ($acao !== 'confirm') $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.acao_invalida', ['pt' => 'Ação inválida.', 'en' => 'Invalid action.', 'es' => 'Acción inválida.'])], 400);

$dados = json_decode((string)($_POST['dados'] ?? '{}'), true);
if (!is_array($dados)) $json(['ok' => false, 'message' => IdiomaService::t('api.ia.sugestao_invalida', ['pt' => 'Sugestão inválida.', 'en' => 'Invalid suggestion.', 'es' => 'Sugerencia inválida.'])], 422);
$tipo = strtoupper((string)($dados['tipo_documento'] ?? $analise['tipo_documento'] ?? 'OUTRO'));
$tipo = in_array($tipo, ['ASO', 'TREINAMENTO'], true) ? $tipo : 'OUTRO';
$data = static function ($v): ?string { $v = trim((string)$v); if ($v === '') return null; if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $v, $m)) $v = "$m[3]-$m[2]-$m[1]"; return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)) ? $v : null; };
$destino = max(0, (int)($dados['destino'] ?? 0));
$aplicado = null;

$pdo->beginTransaction();
try {
    $protecoes = [];
    if ($tipo === 'ASO' && $destino > 0) {
        $chk = $pdo->prepare('SELECT codigo FROM vw_fo_funcionario_criterios_destino WHERE codigo = ? AND codfuncionario = ?');
        $chk->execute([$destino, $codfuncionario]);
        if (!$chk->fetchColumn()) throw new RuntimeException($t('destino_invalido', ['pt' => 'O critério ou treinamento escolhido não pertence a este funcionário.', 'en' => 'The chosen criterion or training does not belong to this employee.', 'es' => 'El criterio o capacitación elegido no pertenece a este empleado.']));
        $campos = [];
        if (($v = $data($dados['aso_validade'] ?? '')) !== null) $campos['datadevalidade'] = $v;
        if (($v = $data($dados['aso_data_exame'] ?? '')) !== null) $campos['datadeinicio'] = $v;
        $resultado = strtoupper((string)($dados['aso_resultado'] ?? ''));
        if ($resultado !== '') $campos['atendeaocriterio'] = in_array($resultado, ['APTO', 'APTO_COM_RESTRICAO'], true) ? 'Sim' : (string)json_decode('"Não"');
        if ($campos) {
            GreenPortalDatabase::call($pdo, 'CALL sp_crud_update(?, ?, ?)', ['fo_funcionarioxtiposxcriterios', json_encode(['codigo' => $destino]), json_encode($campos, JSON_UNESCAPED_UNICODE)]);
            foreach (array_keys($campos) as $c) $protecoes[] = ['fo_funcionarioxtiposxcriterios', (string)$destino, $c];
            $aplicado = ['tabela' => 'fo_funcionarioxtiposxcriterios', 'codigo' => $destino, 'campos' => $campos];
        }
    } elseif ($tipo === 'TREINAMENTO' && $destino > 0) {
        $chk = $pdo->prepare('SELECT codigo FROM vw_fo_funcionario_treinamentos_destino WHERE codigo = ? AND codfuncionario = ?');
        $chk->execute([$destino, $codfuncionario]);
        if (!$chk->fetchColumn()) throw new RuntimeException($t('destino_invalido', ['pt' => 'O critério ou treinamento escolhido não pertence a este funcionário.', 'en' => 'The chosen criterion or training does not belong to this employee.', 'es' => 'El criterio o capacitación elegido no pertenece a este empleado.']));
        $campos = ['treinamentorealizado' => 'Sim'];
        if (($v = $data($dados['treinamento_validade'] ?? '')) !== null) $campos['datadevalidadetreinamento'] = $v;
        GreenPortalDatabase::call($pdo, 'CALL sp_crud_update(?, ?, ?)', ['fo_funcionarioxtiposxtreinamentos', json_encode(['codigo' => $destino]), json_encode($campos, JSON_UNESCAPED_UNICODE)]);
        foreach (array_keys($campos) as $c) $protecoes[] = ['fo_funcionarioxtiposxtreinamentos', (string)$destino, $c];
        $aplicado = ['tabela' => 'fo_funcionarioxtiposxtreinamentos', 'codigo' => $destino, 'campos' => $campos];
    }
    // O sincronismo legado -> novo não sobrescreve os campos confirmados aqui.
    foreach ($protecoes as [$tabela, $chave, $coluna]) GreenPortalDatabase::call($pdo, 'CALL sp_fo_sinc_protecao_registrar(?, ?, ?, ?, ?)', [$tabela, $chave, strtolower($coluna), $codanalise, (int)$usuario]);
    $limpos = array_intersect_key($dados, array_flip(['tipo_documento', 'nome', 'cpf', 'aso_tipo_exame', 'aso_resultado', 'aso_data_exame', 'aso_validade', 'aso_medico', 'aso_crm', 'treinamento_nome', 'treinamento_norma', 'treinamento_carga_horaria', 'treinamento_data_realizacao', 'treinamento_validade', 'treinamento_instituicao', 'destino']));
    GreenPortalDatabase::call($pdo, 'CALL sp_fo_documento_ia_confirmar(?, ?, ?, ?, ?, ?)', [$codanalise, $tipo, json_encode($limpos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $aplicado['tabela'] ?? null, $aplicado['codigo'] ?? null, (int)$usuario]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $json(['ok' => false, 'message' => $e->getMessage()], 422);
}
$auditar('ANALISE_IA_CONFIRMADA', ['codanalise' => $codanalise, 'aplicado' => $aplicado]);
(new GedAnaliseIaRegistro($pdo, $usuario))->encerrarPorReferencia('funcionarios', $codanalise, 'CONFIRMADA');
$json(['ok' => true, 'aplicado' => $aplicado, 'message' => $aplicado
    ? $t('confirmada_aplicada', ['pt' => 'Dados confirmados e gravados no cadastro do funcionário.', 'en' => 'Data confirmed and saved to the employee record.', 'es' => 'Datos confirmados y guardados en el registro del empleado.'])
    : $t('confirmada_sem_destino', ['pt' => 'Análise confirmada e guardada (sem critério ou treinamento escolhido para atualizar).', 'en' => 'Analysis confirmed and stored (no criterion or training chosen to update).', 'es' => 'Análisis confirmado y guardado (sin criterio o capacitación elegido para actualizar).'])]);
