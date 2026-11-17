<?php
declare(strict_types=1);

/*
 * Análise por IA das evidências do Plano de ação da condicionante (Manutenção legal › Licenças › Condicionantes ›
 * Plano de ação; GED tipo planodeacao, pasta li_planodeacao). Migração 308 (prompt LI_PLANOACAO_DOCUMENTO).
 *
 * Ações (POST, com o token csrf da Gestão de Arquivos; valor = CODPLANOACAO):
 *   extract — coloca o arquivo na fila do worker de OCR (GreenPortal-OcrWorker); com job_id, devolve o texto lido.
 *   analyze — envia o texto à IA (fila do worker de IA) com a condicionante e o plano cadastrados; com ia_job_id,
 *             devolve a sugestão (ação, datas, recursos, status, responsável) para o operador aprovar.
 *             O mesmo documento não é enviado de novo (GedAnaliseIaRegistro: duplicado/reaproveitado).
 *   confirm — grava em li_planodeacao só os campos que o operador marcou e encerra a análise como CONFIRMADA.
 *   discard — encerra a análise como DESCARTADA.
 * Autorização: "Editar" na transação LICENCAS (a mesma da tela do plano de ação). Banco: procedures sp_crud_* e as do GED.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/IdiomaService.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';
require_once dirname(__DIR__, 2) . '/classes/GedStorageService.php';
require_once dirname(__DIR__, 2) . '/classes/OcrQueueService.php';
require_once dirname(__DIR__, 2) . '/classes/DocumentAiQueueService.php';
require_once dirname(__DIR__, 2) . '/classes/InteligenciaArtificialDocumento.php';
require_once dirname(__DIR__, 2) . '/classes/GedAnaliseIaRegistro.php';

const PIA_TRANSACAO = 'LICENCAS';
const PIA_TIPO_GED = 'planodeacao';
const PIA_PASTA = 'li_planodeacao';
const PIA_PROMPT = 'LI_PLANOACAO_DOCUMENTO';
const PIA_STATUS = ['0', '25', '50', '75', '100'];
const PIA_EXTENSOES = ['pdf', 'jpg', 'jpeg', 'png', 'tif', 'tiff', 'bmp', 'doc', 'docx'];

$json = static function (array $data, int $status = 200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; };
$t = static fn(string $chave, array $padrao): string => IdiomaService::t('api.planodeacao_ia.' . $chave, $padrao);
$sessaoExpirada = ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.'];
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', $sessaoExpirada)], 401);
$env = GreenPortalDatabase::env();
$pdo = TenantContext::connect($env, $_SESSION);
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', $sessaoExpirada)], 401);

$permissao = PermissaoTransacao::obter(PIA_TRANSACAO);
if (empty($permissao['pode_editar'])) $json(['ok' => false, 'message' => $t('sem_permissao', ['pt' => 'Seu perfil não tem autorização para usar a IA no plano de ação (Segurança › Permissões › Licenças › Editar).', 'en' => 'Your profile is not authorized to use AI on the action plan (Security › Permissions › Licenses › Edit).', 'es' => 'Su perfil no tiene autorización para usar la IA en el plan de acción (Seguridad › Permisos › Licencias › Editar).'])], 403);
$csrf = (string)($_SESSION['gestao_arquivos_csrf'] ?? '');
if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $json(['ok' => false, 'message' => IdiomaService::t('api.token_invalido', ['pt' => 'Token de segurança inválido. Recarregue a página e tente novamente.', 'en' => 'Invalid security token. Reload the page and try again.', 'es' => 'Token de seguridad inválido. Recargue la página e inténtelo nuevamente.'])], 419);

$acao = strtolower(trim((string)($_POST['action'] ?? 'analyze')));
$usuario = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$idioma = (string)($_SESSION['idioma'] ?? 'pt');
$codigo = max(0, (int)($_POST['valor'] ?? 0));
$call = static fn(string $sql, array $p = []): array => GreenPortalDatabase::call($pdo, $sql, $p);
$um = static fn(string $tabela, array $filtro): ?array => $call('CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => $tabela, 'filtros' => json_encode($filtro)])[0] ?? null;

$plano = $codigo > 0 ? $um('li_planodeacao', ['CODPLANOACAO' => $codigo]) : null;
if (!$plano) $json(['ok' => false, 'message' => $t('nao_localizado', ['pt' => 'Plano de ação não localizado.', 'en' => 'Action plan not found.', 'es' => 'Plan de acción no encontrado.'])], 404);
$condicionante = $um('li_condicionantes', ['CODCONDICIONANTE' => (int)($plano['CODCONDICIONANTE'] ?? 0)]) ?? [];
$licenca = !empty($condicionante['CODLICENCA']) ? ($um('li_licencas', ['CODLICENCA' => (int)$condicionante['CODLICENCA']]) ?? []) : [];

$data = static function ($v): ?string {
    $v = trim((string)$v); if ($v === '') return null;
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $v, $m)) $v = "$m[3]-$m[2]-$m[1]";
    $v = substr($v, 0, 10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)) ? $v : null;
};
$status = static function ($v): ?string {
    if ($v === null || $v === '') return null;
    $n = (float)str_replace(['%', ','], ['', '.'], (string)$v);
    if ($n > 0 && $n <= 1 && str_contains((string)$v, '.')) $n *= 100;
    $n = max(0, min(100, $n));
    return (string)(int)(round($n / 25) * 25);
};
$usuarioPorNome = static function (?string $nome) use ($call): ?array {
    $nome = trim((string)$nome); if ($nome === '') return null;
    $norm = static function (string $s): string { $s = class_exists('Normalizer') ? (string)Normalizer::normalize($s, Normalizer::FORM_D) : $s; $s = preg_replace('/\p{Mn}+/u', '', $s) ?? $s; return trim(preg_replace('/\s+/', ' ', strtoupper($s)) ?? ''); };
    try {
        foreach ($call('CALL sp_crud_select(:tabela,:filtros,:busca,50,0)', ['tabela' => 'vw_usuarios_grid', 'filtros' => '{}', 'busca' => $nome]) as $u)
            if ($norm((string)($u['nome'] ?? '')) === $norm($nome)) return ['codusuario' => (int)$u['codusuario'], 'nome' => (string)$u['nome']];
    } catch (Throwable) {}
    return null;
};
$nomeUsuario = static function (int $cod) use ($um): string { if ($cod < 1) return ''; try { return (string)($um('vw_usuarios_grid', ['codusuario' => $cod])['nome'] ?? ''); } catch (Throwable) { return ''; } };
$cadastro = static fn(): array => [
    'licenca' => (string)($licenca['DSCLICENCA'] ?? ''), 'condicionante' => (string)($condicionante['DSCCONDICIONANTE'] ?? ''),
    'acao' => (string)($plano['ACAO'] ?? ''), 'codusuario' => (int)($plano['CODUSUARIO'] ?? 0), 'responsavel' => $nomeUsuario((int)($plano['CODUSUARIO'] ?? 0)),
    'data_inicial' => $data($plano['DATAINICIAL'] ?? ''), 'data_final' => $data($plano['DATAFINAL'] ?? ''), 'recursos' => (string)($plano['RECURSOS'] ?? ''),
    'status' => ($plano['STATUS'] ?? '') === null ? '' : (string)($plano['STATUS'] ?? ''),
];
/** Sugestão normalizada + campos do plano que ela pode preencher. */
$normalizar = static function (array $s) use ($data, $status, $usuarioPorNome): array {
    $s = array_intersect_key($s, array_flip(['tipo_documento', 'corresponde_a_condicionante', 'acao', 'responsavel', 'data_inicial', 'data_final', 'recursos', 'status', 'resumo', 'confianca', 'alertas']));
    foreach (['acao', 'responsavel', 'recursos', 'resumo', 'tipo_documento'] as $c) $s[$c] = isset($s[$c]) && is_scalar($s[$c]) && trim((string)$s[$c]) !== '' ? trim((string)$s[$c]) : null;
    $s['corresponde_a_condicionante'] = in_array(strtoupper((string)($s['corresponde_a_condicionante'] ?? '')), ['SIM', 'NAO'], true) ? strtoupper((string)$s['corresponde_a_condicionante']) : 'INCERTO';
    $s['data_inicial'] = $data($s['data_inicial'] ?? ''); $s['data_final'] = $data($s['data_final'] ?? ''); $s['status'] = $status($s['status'] ?? null);
    $u = $usuarioPorNome($s['responsavel']); $s['codusuario'] = $u['codusuario'] ?? null; if ($u) $s['responsavel'] = $u['nome'];
    $conf = (float)($s['confianca'] ?? 0); if ($conf > 0 && $conf <= 1) $conf *= 100; $s['confianca'] = round($conf, 2);
    $s['alertas'] = array_values(array_filter(array_map('strval', (array)($s['alertas'] ?? []))));
    if ($s['responsavel'] !== null && !$u) $s['alertas'][] = 'Responsável lido no documento (' . $s['responsavel'] . ') não foi encontrado entre os usuários: escolha na lista, se for o caso.';
    if ($s['corresponde_a_condicionante'] === 'NAO') $s['alertas'][] = 'O arquivo não parece ser evidência desta condicionante.';
    if ($s['data_inicial'] && $s['data_final'] && $s['data_final'] < $s['data_inicial']) $s['alertas'][] = 'A data final lida é anterior à data inicial.';
    $s['alertas'] = array_values(array_unique($s['alertas']));
    return $s;
};
$resposta = static function (int $codGed, string $arquivo, array $s, string $fonte, bool $reaproveitada = false) use ($cadastro): array {
    return ['ok' => true, 'codanalise_ged' => $codGed, 'arquivo' => $arquivo, 'sugestao' => $s, 'cadastro' => $cadastro(), 'fonte_texto' => $fonte, 'reaproveitada' => $reaproveitada, 'status_opcoes' => PIA_STATUS];
};
$arquivoPedido = static function () use ($pdo, $env, $codigo, $json, $t): array {
    $nome = basename((string)($_POST['arquivo'] ?? ''));
    $caminho = $nome !== '' ? (new GedStorageService($pdo, $env, $_SESSION))->resolve(PIA_PASTA, $codigo, $nome) : null;
    if ($caminho === null || !is_file($caminho)) $json(['ok' => false, 'message' => $t('arquivo_nao_localizado', ['pt' => 'Arquivo do plano de ação não localizado.', 'en' => 'Action plan file not found.', 'es' => 'Archivo del plan de acción no encontrado.'])], 404);
    if (!in_array(strtolower(pathinfo($caminho, PATHINFO_EXTENSION)), PIA_EXTENSOES, true)) $json(['ok' => false, 'message' => $t('formato', ['pt' => 'Formato não suportado para análise (use PDF, imagem ou Word).', 'en' => 'Format not supported for analysis (use PDF, image or Word).', 'es' => 'Formato no soportado para el análisis (use PDF, imagen o Word).'])], 422);
    return [$nome, $caminho];
};
$ged = new GedAnaliseIaRegistro($pdo, $usuario);
$descricaoGed = mb_substr(trim((string)($condicionante['DSCCONDICIONANTE'] ?? '') . ' / ' . (string)($plano['ACAO'] ?? '')), 0, 250);

if ($acao === 'extract') {
    [$nome, $caminho] = $arquivoPedido();
    if (empty($_POST['job_id'])) {
        try { $decisao = $ged->preparar(PIA_TIPO_GED, 'CODPLANOACAO', $codigo, $nome, $caminho, !empty($_POST['forcar']), $_SESSION, $descricaoGed); }
        catch (Throwable $e) { $json(['ok' => false, 'message' => $e->getMessage()], 403); }
        if (in_array($decisao['acao'], ['DUPLICADO', 'EM_ANDAMENTO'], true)) $json(GedAnaliseIaRegistro::respostaDuplicado($decisao) + ['pode_reanalisar' => GedAnaliseIaRegistro::podeReanalisar($_SESSION)]);
        $codGed = (int)$decisao['codanalise'];
        if ($decisao['acao'] === 'REAPROVEITAR') {
            $s = $normalizar(is_array($decisao['origem']['dados_ia'] ?? null) ? $decisao['origem']['dados_ia'] : []);
            $ged->concluir($codGed, $s, (float)$s['confianca'], (string)($decisao['origem']['fonte_texto'] ?? ''));
            $json($resposta($codGed, $nome, $s, (string)($decisao['origem']['fonte_texto'] ?? ''), true));
        }
        try {
            $job = (new OcrQueueService($pdo, $env))->enqueue(['chave_idempotencia' => 'GED_EXTRACAO:' . PIA_TIPO_GED . ':' . $codigo . ':' . $nome . ':' . GedAnaliseIaRegistro::hash($caminho), 'tipo' => PIA_TIPO_GED, 'entidade' => 'li_planodeacao', 'registro_id' => (string)$codigo, 'arquivo' => $nome, 'caminho_arquivo' => $caminho, 'tamanho_bytes' => filesize($caminho), 'codusuario' => $usuario]);
            $json(['ok' => true, 'queued' => true, 'status' => $job['status'], 'job_id' => (int)$job['codjob'], 'codanalise_ged' => $codGed, 'arquivo' => $nome], 202);
        } catch (Throwable $e) { $ged->falhar($codGed, $e->getMessage()); $json(['ok' => false, 'message' => $e->getMessage()], 422); }
    }
    $codGed = (int)($_POST['codanalise_ged'] ?? 0);
    $job = (new OcrQueueService($pdo, $env))->find((int)$_POST['job_id']);
    if (!$job || (int)($job['codusuario'] ?? 0) !== $usuario) $json(['ok' => false, 'message' => 'Job de extração não localizado.'], 404);
    if (in_array((string)($job['status'] ?? ''), ['PENDENTE', 'PROCESSANDO'], true)) $json(['ok' => true, 'queued' => true, 'status' => $job['status'], 'job_id' => (int)$job['codjob'], 'codanalise_ged' => $codGed], 202);
    if (in_array((string)($job['status'] ?? ''), ['ERRO', 'CANCELADA'], true)) { $m = (string)($job['erro'] ?? 'Falha na extração.'); $ged->falhar($codGed, $m); $json(['ok' => false, 'message' => $m], 422); }
    $json(['ok' => true, 'texto' => (string)($job['texto'] ?? ''), 'confianca' => (float)($job['confianca'] ?? 0), 'fonte' => 'OCR_WORKER', 'codanalise_ged' => $codGed, 'arquivo' => $nome]);
}

if ($acao === 'analyze') {
    $codGed = (int)($_POST['codanalise_ged'] ?? 0);
    $nome = basename((string)($_POST['arquivo'] ?? ''));
    if (!empty($_POST['ia_job_id'])) {
        $job = (new DocumentAiQueueService($pdo))->find((int)$_POST['ia_job_id'], $usuario);
        if (!$job) $json(['ok' => false, 'message' => 'Job de IA não localizado.'], 404);
        if (in_array($job['status'], ['PENDENTE', 'PROCESSANDO'], true)) $json(['ok' => true, 'queued' => true, 'status' => $job['status'], 'ia_job_id' => (int)$job['codjob']], 202);
        if ($job['status'] === 'ERRO') $json(['ok' => false, 'message' => (string)$job['erro']], 422);
        $r = json_decode((string)$job['resultado_json'], true);
        if (!is_array($r)) $json(['ok' => false, 'message' => 'Resultado da IA inválido.'], 422);
        $s = $normalizar(is_array($r['sugestao'] ?? null) ? $r['sugestao'] : []);
        $json($resposta((int)$job['codanalise_ged'], (string)$job['arquivo'], $s, 'IA_WORKER'));
    }
    $texto = trim((string)($_POST['texto'] ?? ''));
    if ($codGed < 1 || $nome === '') $json(['ok' => false, 'message' => $t('sem_extracao', ['pt' => 'Extraia o texto do arquivo antes da análise.', 'en' => 'Extract the file text before the analysis.', 'es' => 'Extraiga el texto del archivo antes del análisis.'])], 422);
    if ($texto === '') { $m = $t('sem_texto', ['pt' => 'O documento não tem texto legível para análise.', 'en' => 'The document has no readable text to analyze.', 'es' => 'El documento no tiene texto legible para analizar.']); $ged->falhar($codGed, $m); $json(['ok' => false, 'message' => $m], 422); }
    $prompt = trim((string)($call('CALL sp_ia_prompt_obter(:codigo,:idioma)', ['codigo' => PIA_PROMPT, 'idioma' => $idioma])[0]['prompt'] ?? ''));
    if ($prompt === '') { $m = $t('prompt', ['pt' => 'Prompt LI_PLANOACAO_DOCUMENTO não configurado (rode a migração 308).', 'en' => 'LI_PLANOACAO_DOCUMENTO prompt not configured (run migration 308).', 'es' => 'Prompt LI_PLANOACAO_DOCUMENTO no configurado (ejecute la migración 308).']); $ged->falhar($codGed, $m); $json(['ok' => false, 'message' => $m], 422); }
    $c = $cadastro();
    $contexto = 'LICENÇA: ' . $c['licenca'] . "\nCONDICIONANTE: " . $c['condicionante'] . "\nPLANO DE AÇÃO CADASTRADO: ação=" . ($c['acao'] ?: '—') . '; responsável=' . ($c['responsavel'] ?: '—')
        . '; data inicial=' . ($c['data_inicial'] ?: '—') . '; data final=' . ($c['data_final'] ?: '—') . '; recursos=' . ($c['recursos'] ?: '—') . '; status=' . ($c['status'] !== '' ? $c['status'] . '%' : '—') . "\n---\n";
    try {
        $job = (new DocumentAiQueueService($pdo))->enqueue(['chave_idempotencia' => 'IA:PLANODEACAO:' . $codigo . ':' . $nome . ':' . hash('sha256', $texto), 'tipo' => 'PLANODEACAO', 'entidade' => 'li_planodeacao', 'registro_id' => (string)$codigo, 'arquivo' => $nome, 'codanalise_ged' => $codGed, 'codusuario' => $usuario, 'idioma' => $idioma, 'prompt' => $prompt, 'texto' => $contexto . $texto]);
        $json(['ok' => true, 'queued' => true, 'status' => $job['status'], 'ia_job_id' => (int)$job['codjob'], 'codanalise_ged' => $codGed, 'arquivo' => $nome], 202);
    } catch (Throwable $e) { $ged->falhar($codGed, $e->getMessage()); $json(['ok' => false, 'message' => $e->getMessage()], 422); }
}

$codGed = max(0, (int)($_POST['codanalise_ged'] ?? 0));
if ($acao === 'review') {
    // reabre uma análise concluída (aba Análises de IA): devolve o cadastro atual junto
    $s = json_decode((string)($_POST['sugestao'] ?? '{}'), true);
    $json($resposta($codGed, basename((string)($_POST['arquivo'] ?? '')), $normalizar(is_array($s) ? $s : []), (string)($_POST['fonte'] ?? '')));
}
if ($acao === 'discard') {
    $ged->encerrar($codGed, 'DESCARTADA');
    $json(['ok' => true, 'message' => $t('descartada', ['pt' => 'Análise descartada; o plano de ação não foi alterado.', 'en' => 'Analysis discarded; the action plan was not changed.', 'es' => 'Análisis descartado; el plan de acción no fue modificado.'])]);
}
if ($acao !== 'confirm') $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.acao_invalida', ['pt' => 'Ação inválida.', 'en' => 'Invalid action.', 'es' => 'Acción inválida.'])], 400);

$dados = json_decode((string)($_POST['dados'] ?? '{}'), true);
if (!is_array($dados)) $json(['ok' => false, 'message' => IdiomaService::t('api.ia.sugestao_invalida', ['pt' => 'Sugestão inválida.', 'en' => 'Invalid suggestion.', 'es' => 'Sugerencia inválida.'])], 422);
$gravar = [];
foreach ((array)($dados['campos'] ?? []) as $campo => $valor) {
    $valor = is_scalar($valor) ? trim((string)$valor) : '';
    switch ($campo) {
        case 'ACAO': case 'RECURSOS': if ($valor !== '') $gravar[$campo] = mb_substr($valor, 0, 4000); break;
        case 'DATAINICIAL': case 'DATAFINAL': if (($d = $data($valor)) !== null) $gravar[$campo] = $d; break;
        case 'STATUS': if (in_array($valor, PIA_STATUS, true)) $gravar[$campo] = $valor; break;
        case 'CODUSUARIO': if ((int)$valor > 0 && $nomeUsuario((int)$valor) !== '') $gravar[$campo] = (int)$valor; break;
    }
}
if (isset($gravar['DATAINICIAL'], $gravar['DATAFINAL']) && $gravar['DATAFINAL'] < $gravar['DATAINICIAL'])
    $json(['ok' => false, 'message' => $t('datas', ['pt' => 'A data final não pode ser anterior à data inicial.', 'en' => 'The end date cannot be before the start date.', 'es' => 'La fecha final no puede ser anterior a la fecha inicial.'])], 422);
try {
    if ($gravar) $call('CALL sp_crud_update(:tabela,:chave,:dados)', ['tabela' => 'li_planodeacao', 'chave' => json_encode(['CODPLANOACAO' => $codigo]), 'dados' => json_encode($gravar, JSON_UNESCAPED_UNICODE)]);
} catch (Throwable $e) {
    error_log('planodeacao_ia confirm: ' . $e->getMessage());
    $json(['ok' => false, 'message' => $t('gravar', ['pt' => 'Não foi possível gravar o plano de ação.', 'en' => 'Could not save the action plan.', 'es' => 'No fue posible guardar el plan de acción.'])], 422);
}
$ged->encerrar($codGed, 'CONFIRMADA');
try { GreenPortalDatabase::call($pdo, 'CALL sp_auditoria_registrar(?, ?, ?, ?, ?, ?, ?)', [$usuario, PIA_TRANSACAO, 'ANALISE_IA_CONFIRMADA', 'li_planodeacao', (string)$codigo, 'SUCESSO', json_encode(['codanalise_ged' => $codGed, 'arquivo' => basename((string)($_POST['arquivo'] ?? '')), 'aplicados' => $gravar], JSON_UNESCAPED_UNICODE)]); } catch (Throwable $e) { error_log($e->getMessage()); }
if (isset($gravar['CODUSUARIO'])) $gravar['RESPONSAVEL'] = $nomeUsuario((int)$gravar['CODUSUARIO']);
$json(['ok' => true, 'aplicados' => $gravar, 'valor' => $codigo, 'message' => $gravar
    ? $t('confirmada', ['pt' => 'Dados aprovados e gravados no plano de ação.', 'en' => 'Data approved and saved to the action plan.', 'es' => 'Datos aprobados y guardados en el plan de acción.'])
    : $t('confirmada_sem_alteracao', ['pt' => 'Análise confirmada (nenhum campo do plano de ação foi alterado).', 'en' => 'Analysis confirmed (no action plan field was changed).', 'es' => 'Análisis confirmado (ningún campo del plan de acción fue modificado).'])]);
