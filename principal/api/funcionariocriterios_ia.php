<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/classes/DocumentAiQueueService.php';

/*
 * Análise por IA das evidências da Portaria › Documentação de Funcionário (GED tipo funcionariocriterios:
 * um critério exigido de um funcionário, fo_funcionarioxtiposxcriterios).
 *
 * Ações (POST, com o token csrf da Gestão de Arquivos; valor = CODIGO do critério do funcionário):
 *   analyze  — extrai o texto (PDF, imagem com OCR, Word), remove dados de saúde (classes/FuncionarioDocumentoTexto.php),
 *              envia à IA com o prompt FUNCIONARIO_DOCUMENTO (migração 221) junto com o critério exigido e o funcionário,
 *              confere nome, CPF, tipo de documento x critério e validade, e grava a análise PENDENTE.
 *   review   — reabre uma análise pendente (aba "Revisão dos dados").
 *   confirm  — grava no critério do funcionário a validade, "Atende ao critério?", a situação e a observação revisados;
 *              os campos ficam protegidos contra o sincronismo legado -> novo.
 *   discard  — descarta a análise.
 * Autorização: "Editar" na transação PORTARIA_DOCUMENTACAO_FUNCIONARIO e o registro de uma unidade do usuário
 * (ADMINISTRADOR vê todas). Banco: só procedures (migração 262).
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/IdiomaService.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';
require_once dirname(__DIR__, 2) . '/classes/GedStorageService.php';
require_once dirname(__DIR__, 2) . '/classes/GedPermissaoTipo.php';
require_once dirname(__DIR__, 2) . '/classes/LicenseDocumentExtractionService.php';
require_once dirname(__DIR__, 2) . '/classes/InteligenciaArtificialDocumento.php';
require_once dirname(__DIR__, 2) . '/classes/GedAnaliseIaRegistro.php';
require_once dirname(__DIR__, 2) . '/classes/FuncionarioDocumentoTexto.php';
require_once dirname(__DIR__, 2) . '/classes/HomologacaoDocumentoIa.php';
require_once dirname(__DIR__, 2) . '/classes/HomologacaoDocumentoModelo.php';

const FIA_TRANSACAO = 'PORTARIA_DOCUMENTACAO_FUNCIONARIO';
const FIA_TIPO_GED = 'funcionariocriterios';
const FIA_ATENDE = ['Sim', 'Não', 'Desligado', 'NAP', 'Afastado'];
const FIA_SITUACOES = ['', 'Pendente com o fornecedor', 'Pendente com a empresa'];

$json = static function (array $data, int $status = 200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; };
$t = static fn(string $chave, array $padrao): string => IdiomaService::t('api.funcionariocriterios_ia.' . $chave, $padrao);
$sessaoExpirada = ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.'];
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', $sessaoExpirada)], 401);
$env = GreenPortalDatabase::env();
$pdo = TenantContext::connect($env, $_SESSION);
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', $sessaoExpirada)], 401);

$permissao = PermissaoTransacao::obter(FIA_TRANSACAO);
if (empty($permissao['pode_editar'])) $json(['ok' => false, 'message' => $t('sem_permissao', ['pt' => 'Seu perfil não tem autorização para usar a IA nos documentos de funcionário (Segurança › Permissões › Documentação de Funcionário › Editar).', 'en' => 'Your profile is not authorized to use AI on employee documents (Security › Permissions › Employee Documentation › Edit).', 'es' => 'Su perfil no tiene autorización para usar la IA en los documentos de empleado (Seguridad › Permisos › Documentación de Empleado › Editar).'])], 403);
$csrf = (string)($_SESSION['gestao_arquivos_csrf'] ?? '');
if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $json(['ok' => false, 'message' => IdiomaService::t('api.token_invalido', ['pt' => 'Token de segurança inválido. Recarregue a página e tente novamente.', 'en' => 'Invalid security token. Reload the page and try again.', 'es' => 'Token de seguridad inválido. Recargue la página e inténtelo nuevamente.'])], 419);
$acao = strtolower(trim((string)($_POST['action'] ?? 'analyze')));
$usuario = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$codigo = max(0, (int)($_POST['valor'] ?? 0));
if (!GedPermissaoTipo::registroPermitido($pdo, FIA_TIPO_GED, $codigo)) $json(['ok' => false, 'message' => IdiomaService::t('api.arquivos.unidade_usuario', ['pt' => 'Registro de uma unidade sem acesso para o seu usuário.', 'en' => 'This record belongs to a unit your user cannot access.', 'es' => 'Registro de una unidad sin acceso para su usuario.'])], 403);

$call = static fn(string $sql, array $p = []): array => GreenPortalDatabase::call($pdo, $sql, $p);
$registro = $call('CALL sp_docfuncionario_ia_registro(:c)', ['c' => $codigo])[0] ?? null;
if (!$registro) $json(['ok' => false, 'message' => $t('nao_localizado', ['pt' => 'Critério do funcionário não localizado.', 'en' => 'Employee criterion not found.', 'es' => 'Criterio del empleado no encontrado.'])], 404);

$auditar = static function (string $acao, array $detalhes) use ($pdo, $usuario, $codigo): void {
    try { GreenPortalDatabase::call($pdo, 'CALL sp_auditoria_registrar(?, ?, ?, ?, ?, ?, ?)', [$usuario, FIA_TRANSACAO, $acao, 'fo_funcionarioxtiposxcriterios_documentos_ia', (string)$codigo, 'SUCESSO', json_encode($detalhes, JSON_UNESCAPED_UNICODE)]); }
    catch (Throwable $e) { error_log($e->getMessage()); }
};
$normalizar = static function (?string $s): string {
    $s = (string)$s;
    $s = class_exists('Normalizer') ? (string)Normalizer::normalize($s, Normalizer::FORM_D) : $s;
    $s = preg_replace('/\p{Mn}+/u', '', $s) ?? $s;
    return trim(preg_replace('/[^A-Z0-9 ]+/', ' ', strtoupper($s)) ?? '');
};
$data = static function ($v): ?string { $v = trim((string)$v); if ($v === '') return null; if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $v, $m)) $v = "$m[3]-$m[2]-$m[1]"; return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)) ? $v : null; };
$cadastro = static fn(array $r): array => ['funcionario' => $r['FUNCIONARIO'], 'registro' => $r['NRIDENTIFICACAO'], 'funcao' => $r['DSCTIPO'], 'fornecedor' => $r['RAZAOSOCIAL'],
    'unidade' => $r['DSCUNIDADE'], 'criterio' => $r['DSCRITERIO'], 'atende' => $r['ATENDEAOCRITERIO'], 'situacao' => $r['SITUACAO'], 'validade' => $r['DATADEVALIDADE'], 'observacao' => $r['OBSERVACAO']];

/** Sugestão para o critério: validade do documento, "Atende" pela aptidão/validade, situação resolvida quando atende. */
$sugerir = static function (array $s) use ($data): array {
    $tipo = (string)($s['tipo_documento'] ?? 'OUTRO');
    $validade = $data($tipo === 'ASO' ? ($s['aso_validade'] ?? '') : ($tipo === 'TREINAMENTO' ? ($s['treinamento_validade'] ?? '') : ''));
    $atende = null;
    if ($tipo === 'ASO') { $res = strtoupper((string)($s['aso_resultado'] ?? '')); if ($res !== '') $atende = in_array($res, ['APTO', 'APTO_COM_RESTRICAO'], true) ? 'Sim' : 'Não'; }
    elseif ($tipo === 'TREINAMENTO') $atende = 'Sim';
    if ($atende === 'Sim' && $validade !== null && $validade < date('Y-m-d')) $atende = 'Não';
    // Migração 347: com o modelo do documento, item obrigatório ausente (ou regra que não confere) sugere devolver ao fornecedor
    if ((int)($s['modelo']['faltando'] ?? 0) > 0) return ['validade' => $validade, 'atende' => 'Não', 'situacao' => 'Pendente com o fornecedor'];
    return ['validade' => $validade, 'atende' => $atende, 'situacao' => $atende === 'Sim' ? '' : null];
};
$resposta = static function (int $codanalise, string $arquivo, array $s, float $confianca, string $fonte, int $omitidas, bool $reaproveitada = false) use ($registro, $cadastro, $sugerir): array {
    return ['ok' => true, 'codanalise' => $codanalise, 'arquivo' => $arquivo, 'sugestao' => $s, 'confianca' => $confianca, 'fonte_texto' => $fonte, 'linhas_omitidas' => $omitidas,
        'reaproveitada' => $reaproveitada, 'cadastro' => $cadastro($registro), 'atende_opcoes' => FIA_ATENDE, 'situacoes' => FIA_SITUACOES, 'sugerido' => $sugerir($s),
        'observacao_sugerida' => is_array($s['modelo'] ?? null) ? HomologacaoDocumentoModelo::observacaoDevolucao($s['modelo']) : null];
};

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
    $arquivo = $storage->resolve('fo_funcionarioxtiposxcriterios', $codigo, $nomeArquivo);
    if ($arquivo === null) $json(['ok' => false, 'message' => $t('arquivo_nao_localizado', ['pt' => 'Arquivo não localizado.', 'en' => 'File not found.', 'es' => 'Archivo no encontrado.'])], 404);
    $ged = new GedAnaliseIaRegistro($pdo, $usuario);
    if ($filaJob === null) {
        try { $decisao = $ged->preparar(FIA_TIPO_GED, 'CODIGO', $codigo, $nomeArquivo, $arquivo, !empty($_POST['forcar']), $_SESSION, (string)$registro['FUNCIONARIO'] . ' / ' . (string)$registro['DSCRITERIO']); }
        catch (Throwable $e) { $json(['ok' => false, 'message' => $e->getMessage()], 403); }
        if (in_array($decisao['acao'], ['DUPLICADO', 'EM_ANDAMENTO'], true)) $json(GedAnaliseIaRegistro::respostaDuplicado($decisao) + ['pode_reanalisar' => GedAnaliseIaRegistro::podeReanalisar($_SESSION)]);
        $codGed = (int)$decisao['codanalise'];
    } else { $codGed = (int)$filaJob['codanalise_ged']; $decisao = ['acao'=>'IA_FILA','codanalise'=>$codGed]; }
    $falhar = static function (string $mensagem, int $status) use ($ged, $codGed, $json): never { $ged->falhar($codGed, $mensagem); $json(['ok' => false, 'message' => $mensagem], $status); };
    $alertas = []; $omitidas = 0;
    if ($decisao['acao'] === 'REAPROVEITAR') {
        $origem = $decisao['origem'];
        $s = is_array($origem['dados_ia']) ? $origem['dados_ia'] : [];
        $alertas[] = $t('alerta_reaproveitada', ['pt' => 'Leitura reaproveitada de análise anterior do mesmo documento (não foi enviado de novo à IA).', 'en' => 'Reading reused from a previous analysis of the same document (not sent to the AI again).', 'es' => 'Lectura reaprovechada de un análisis anterior del mismo documento (no se envió de nuevo a la IA).']);
        $extracao = ['fonte' => (string)($origem['fonte_texto'] ?? ''), 'confianca' => 0];
    } else {
        try {
            $textoFila = trim((string)($_POST['texto'] ?? ''));
            $extracao = $textoFila !== ''
                ? ['texto' => $textoFila, 'fonte' => (string)($_POST['fonte'] ?? 'OCR_WORKER'), 'confianca' => (float)($_POST['confianca_texto'] ?? 0)]
                : (function (): array { throw new RuntimeException('A extração deve ser realizada pelo worker OCR antes da análise.'); })();
        } catch (Throwable $e) {
            $falhar($t('extracao', ['pt' => 'Não foi possível ler o texto do documento: ', 'en' => 'Could not read the document text: ', 'es' => 'No fue posible leer el texto del documento: ']) . $e->getMessage(), 422);
        }
        $limpo = FuncionarioDocumentoTexto::limpar((string)($extracao['texto'] ?? ''));
        $omitidas = $limpo['omitidas'];
        if ($limpo['texto'] === '') $falhar($t('sem_texto', ['pt' => 'O documento não tem texto legível para análise.', 'en' => 'The document has no readable text to analyze.', 'es' => 'El documento no tiene texto legible para analizar.']), 422);
        // Migração 347: com modelo cadastrado para o critério, a IA confere também os itens e as regras do modelo
        $modeloDoc = HomologacaoDocumentoModelo::doDocumento($pdo, 'FUNCIONARIO', $codigo);
        $contexto = 'CRITÉRIO EXIGIDO: ' . $registro['DSCRITERIO'] . "\nFUNCIONÁRIO: " . $registro['FUNCIONARIO'] . ($registro['DSCTIPO'] ? ' (' . $registro['DSCTIPO'] . ')' : '') . "\nEMPRESA: " . $registro['RAZAOSOCIAL'] . "\n"
            . ($modeloDoc ? HomologacaoDocumentoModelo::instrucoes($modeloDoc, 'FUNCIONARIO') : '') . "---\n";
        $idioma = (string)($_SESSION['idioma'] ?? 'pt');
        try {
            $p = $call('CALL sp_ia_prompt_obter(:codigo,:idioma)', ['codigo' => 'FUNCIONARIO_DOCUMENTO', 'idioma' => $idioma]);
            $prompt = trim((string)($p[0]['prompt'] ?? ''));
            if ($prompt === '') throw new RuntimeException($t('prompt', ['pt' => 'Prompt FUNCIONARIO_DOCUMENTO não configurado (rode a migração 221).', 'en' => 'FUNCIONARIO_DOCUMENTO prompt not configured (run migration 221).', 'es' => 'Prompt FUNCIONARIO_DOCUMENTO no configurado (ejecute la migración 221).']));
            if ($iaResultado === null) {
                $job = (new DocumentAiQueueService($pdo))->enqueue(['chave_idempotencia'=>'IA:FUNCIONARIOCRITERIO:'.$codigo.':'.$nomeArquivo.':'.hash('sha256',$limpo['texto']),'tipo'=>'FUNCIONARIOCRITERIOS','entidade'=>'fo_funcionarioxtiposxcriterios','registro_id'=>(string)$codigo,'arquivo'=>$nomeArquivo,'codanalise_ged'=>$codGed,'codusuario'=>$usuario,'idioma'=>$idioma,'prompt'=>$prompt,'texto'=>$contexto.$limpo['texto']]);
                $json(['ok'=>true,'queued'=>true,'status'=>$job['status'],'ia_job_id'=>(int)$job['codjob'],'codanalise'=>$codGed,'arquivo'=>$nomeArquivo],202);
            }
            $ia = $iaResultado;
        } catch (Throwable $e) { $falhar($e->getMessage(), 422); }
        $s = is_array($ia['sugestao'] ?? null) ? $ia['sugestao'] : [];
    }
    // Conferência com o cadastro e o critério (e com o modelo do documento, migração 347) e gravação da análise: classes/HomologacaoDocumentoIa.php
    $fin = HomologacaoDocumentoIa::funcionario($pdo, $registro, $codigo, $nomeArquivo, $s, $extracao, $alertas, $omitidas, $usuario, $codGed, HomologacaoDocumentoModelo::doDocumento($pdo, 'FUNCIONARIO', $codigo), $ged);
    $s = $fin['s']; $confianca = (float)$fin['confianca']; $codanalise = (int)$fin['codanalise'];
    $auditar('ANALISE_IA', ['codanalise' => $codanalise, 'arquivo' => $nomeArquivo, 'tipo' => $s['tipo_documento'], 'linhas_omitidas' => $omitidas]);
    $json($resposta($codanalise, $nomeArquivo, $s, $confianca, (string)($extracao['fonte'] ?? ''), $omitidas, $decisao['acao'] === 'REAPROVEITAR'));
}

$codanalise = max(0, (int)($_POST['codanalise'] ?? 0));
$analise = $call('CALL sp_docfuncionario_ia_obter(?, ?)', [$codanalise, $codigo])[0] ?? null;
if (!$analise) $json(['ok' => false, 'message' => $t('analise_nao_localizada', ['pt' => 'Análise não localizada.', 'en' => 'Analysis not found.', 'es' => 'Análisis no encontrado.'])], 404);
if ($analise['status'] !== 'PENDENTE') $json(['ok' => false, 'message' => $t('analise_encerrada', ['pt' => 'Esta análise já foi confirmada ou descartada.', 'en' => 'This analysis was already confirmed or discarded.', 'es' => 'Este análisis ya fue confirmado o descartado.'])], 409);

if ($acao === 'review') {
    $s = json_decode((string)($analise['dados_ia'] ?? '{}'), true) ?: [];
    $json($resposta($codanalise, (string)$analise['arquivo'], $s, (float)($analise['confianca'] ?? 0), (string)($analise['fonte_texto'] ?? ''), (int)$analise['linhas_omitidas']));
}
if ($acao === 'discard') {
    $call('CALL sp_docfuncionario_ia_descartar(?, ?)', [$codanalise, $usuario]);
    $auditar('ANALISE_IA_DESCARTADA', ['codanalise' => $codanalise]);
    (new GedAnaliseIaRegistro($pdo, $usuario))->encerrarPorReferencia(FIA_TIPO_GED, $codanalise, 'DESCARTADA');
    $json(['ok' => true, 'message' => $t('descartada', ['pt' => 'Análise descartada.', 'en' => 'Analysis discarded.', 'es' => 'Análisis descartado.'])]);
}
if ($acao !== 'confirm') $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.acao_invalida', ['pt' => 'Ação inválida.', 'en' => 'Invalid action.', 'es' => 'Acción inválida.'])], 400);

$dados = json_decode((string)($_POST['dados'] ?? '{}'), true);
if (!is_array($dados)) $json(['ok' => false, 'message' => IdiomaService::t('api.ia.sugestao_invalida', ['pt' => 'Sugestão inválida.', 'en' => 'Invalid suggestion.', 'es' => 'Sugerencia inválida.'])], 422);
$atualizar = !empty($dados['atualizar']);
$validade = $atualizar ? $data($dados['data_validade'] ?? '') : null;
$atende = $atualizar && in_array((string)($dados['atende'] ?? ''), FIA_ATENDE, true) && (string)$dados['atende'] !== (string)($registro['ATENDEAOCRITERIO'] ?? '') ? (string)$dados['atende'] : null;
// Situação: '-' = não mexer; '' = sem pendência (documento resolvido)
$sit = (string)($dados['situacao'] ?? '-');
$situacao = $atualizar && $sit !== '-' && in_array($sit, FIA_SITUACOES, true) && $sit !== (string)($registro['SITUACAO'] ?? '') ? $sit : null;
$observacao = $atualizar && array_key_exists('observacao', $dados) && trim((string)$dados['observacao']) !== trim((string)($registro['OBSERVACAO'] ?? '')) ? mb_substr(trim((string)$dados['observacao']), 0, 500) : null;
$limpos = array_intersect_key($dados, array_flip(['atualizar', 'tipo_documento', 'data_validade', 'atende', 'situacao', 'observacao', 'aso_resultado', 'aso_data_exame', 'treinamento_nome', 'treinamento_norma', 'treinamento_data_realizacao']));
try {
    $r = $call('CALL sp_docfuncionario_ia_confirmar(?, ?, ?, ?, ?, ?, ?, ?)', [$codanalise, $codigo, $validade, $atende, $situacao, $observacao, json_encode($limpos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $usuario]);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    foreach (['ATENDE_INVALIDO' => $t('atende_invalido', ['pt' => '"Atende ao critério?" inválido.', 'en' => 'Invalid "Meets the criterion?".', 'es' => '"¿Cumple el criterio?" inválido.']), 'SITUACAO_INVALIDA' => $t('situacao_invalida', ['pt' => 'Situação inválida.', 'en' => 'Invalid status.', 'es' => 'Situación inválida.']), 'ANALISE_ENCERRADA' => $t('analise_encerrada', ['pt' => 'Esta análise já foi confirmada ou descartada.', 'en' => 'This analysis was already confirmed or discarded.', 'es' => 'Este análisis ya fue confirmado o descartado.'])] as $k => $v) if (str_contains($msg, $k)) $msg = $v;
    $json(['ok' => false, 'message' => $msg], 422);
}
$aplicados = json_decode((string)($r[0]['aplicados'] ?? '{}'), true) ?: [];
$auditar('ANALISE_IA_CONFIRMADA', ['codanalise' => $codanalise, 'aplicados' => $aplicados]);
(new GedAnaliseIaRegistro($pdo, $usuario))->encerrarPorReferencia(FIA_TIPO_GED, $codanalise, 'CONFIRMADA');
$json(['ok' => true, 'aplicados' => $aplicados, 'message' => $aplicados
    ? $t('confirmada_aplicada', ['pt' => 'Dados confirmados e gravados no critério do funcionário.', 'en' => 'Data confirmed and saved to the employee criterion.', 'es' => 'Datos confirmados y guardados en el criterio del empleado.'])
    : $t('confirmada_sem_alteracao', ['pt' => 'Análise confirmada e guardada (nenhum dado do critério foi alterado).', 'en' => 'Analysis confirmed and stored (no criterion data was changed).', 'es' => 'Análisis confirmado y guardado (ningún dato del criterio fue modificado).'])]);
