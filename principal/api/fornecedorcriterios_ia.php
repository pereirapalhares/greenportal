<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/classes/DocumentAiQueueService.php';

/*
 * Análise por IA das evidências da Portaria › Documentação de Fornecedor (GED tipo fornecedorcriterios).
 *
 * Ações (POST, com o token csrf da Gestão de Arquivos; valor = CODFORNECEDORXCRITERIOS):
 *   analyze  — extrai o texto do arquivo no servidor (PDF, imagem com OCR, Word), envia à IA com o prompt
 *              FORNECEDOR_DOCUMENTO junto com o documento exigido e o fornecedor cadastrados, confere razão social,
 *              CNPJ e validade, e grava a análise PENDENTE. Devolve a sugestão para revisão.
 *   review   — reabre uma análise pendente (aba "Revisão dos dados").
 *   confirm  — grava no documento do fornecedor (fo_fornecedorxcriterios) a validade, a situação, "documento enviado"
 *              e a observação revisados pelo usuário; os campos ficam protegidos contra o sincronismo legado -> novo.
 *   discard  — descarta a análise.
 * Autorização: "Editar" na transação PORTARIA_DOCUMENTACAO_FORNECEDOR (Segurança › Permissões) e o documento
 * de uma unidade do usuário (ADMINISTRADOR vê todas). Banco: só procedures (migração 261).
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
require_once dirname(__DIR__, 2) . '/classes/HomologacaoDocumentoIa.php';
require_once dirname(__DIR__, 2) . '/classes/HomologacaoDocumentoModelo.php';

const DIA_TRANSACAO = 'PORTARIA_DOCUMENTACAO_FORNECEDOR';
const DIA_TIPO_GED = 'fornecedorcriterios';
const DIA_SITUACOES = ['Aprovado', 'Aprovado sem validade', 'Pendente com o fornecedor', 'Pendente com a empresa', 'Reprovado', 'NA', 'Não enviar ao fornecedor', 'Ciente da regra'];

$json = static function (array $data, int $status = 200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; };
$t = static fn(string $chave, array $padrao): string => IdiomaService::t('api.fornecedorcriterios_ia.' . $chave, $padrao);
$sessaoExpirada = ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.'];
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', $sessaoExpirada)], 401);
$env = GreenPortalDatabase::env();
$pdo = TenantContext::connect($env, $_SESSION);
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', $sessaoExpirada)], 401);

$permissao = PermissaoTransacao::obter(DIA_TRANSACAO);
if (empty($permissao['pode_editar'])) $json(['ok' => false, 'message' => $t('sem_permissao', ['pt' => 'Seu perfil não tem autorização para usar a IA nos documentos de fornecedor (Segurança › Permissões › Documentação de Fornecedor › Editar).', 'en' => 'Your profile is not authorized to use AI on supplier documents (Security › Permissions › Supplier Documentation › Edit).', 'es' => 'Su perfil no tiene autorización para usar la IA en los documentos de proveedor (Seguridad › Permisos › Documentación de Proveedor › Editar).'])], 403);
$csrf = (string)($_SESSION['gestao_arquivos_csrf'] ?? '');
if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $json(['ok' => false, 'message' => IdiomaService::t('api.token_invalido', ['pt' => 'Token de segurança inválido. Recarregue a página e tente novamente.', 'en' => 'Invalid security token. Reload the page and try again.', 'es' => 'Token de seguridad inválido. Recargue la página e inténtelo nuevamente.'])], 419);
$acao = strtolower(trim((string)($_POST['action'] ?? 'analyze')));
$usuario = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$codigo = max(0, (int)($_POST['valor'] ?? 0));
if (!GedPermissaoTipo::registroPermitido($pdo, DIA_TIPO_GED, $codigo)) $json(['ok' => false, 'message' => IdiomaService::t('api.arquivos.unidade_usuario', ['pt' => 'Registro de uma unidade sem acesso para o seu usuário.', 'en' => 'This record belongs to a unit your user cannot access.', 'es' => 'Registro de una unidad sin acceso para su usuario.'])], 403);

$call = static fn(string $sql, array $p = []): array => GreenPortalDatabase::call($pdo, $sql, $p);
$registro = $call('CALL sp_docfornecedor_ia_registro(:c)', ['c' => $codigo])[0] ?? null;
if (!$registro) $json(['ok' => false, 'message' => $t('nao_localizado', ['pt' => 'Documento do fornecedor não localizado.', 'en' => 'Supplier document not found.', 'es' => 'Documento del proveedor no encontrado.'])], 404);
// CNPJ vazio no legado vem como máscara (ex.: "../-"): sem dígitos, é tratado como não informado.
if (!preg_match('/\d/', (string)($registro['CNPJ'] ?? ''))) $registro['CNPJ'] = null;

$auditar = static function (string $acao, array $detalhes) use ($pdo, $usuario, $codigo): void {
    try { GreenPortalDatabase::call($pdo, 'CALL sp_auditoria_registrar(?, ?, ?, ?, ?, ?, ?)', [$usuario, DIA_TRANSACAO, $acao, 'fo_fornecedorxcriterios_documentos_ia', (string)$codigo, 'SUCESSO', json_encode($detalhes, JSON_UNESCAPED_UNICODE)]); }
    catch (Throwable $e) { error_log($e->getMessage()); }
};
$normalizar = static function (?string $s): string {
    $s = (string)$s;
    $s = class_exists('Normalizer') ? (string)Normalizer::normalize($s, Normalizer::FORM_D) : $s;
    $s = preg_replace('/\p{Mn}+/u', '', $s) ?? $s;
    $s = preg_replace('/\b(LTDA|ME|EPP|EIRELI|S\s*A|SA|S\/A)\b/', '', strtoupper($s)) ?? $s;
    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Z0-9 ]+/', ' ', $s) ?? '') ?? '');
};
$data = static function ($v): ?string { $v = trim((string)$v); if ($v === '') return null; if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $v, $m)) $v = "$m[3]-$m[2]-$m[1]"; return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)) ? $v : null; };
$cadastro = static fn(array $r): array => ['fornecedor' => $r['RAZAOSOCIAL'], 'cnpj' => $r['CNPJ'], 'unidade' => $r['UNIDADEFORNECEDOR'], 'documento' => $r['DSCRITERIO'],
    'situacao' => $r['SITUACAO'], 'validade' => $r['DATADEVALIDADE'], 'enviado' => $r['ENVIADOODOCUMENTO'], 'observacao' => $r['OBSERVACAO'], 'observacao_fornecedor' => $r['OBSERVACAO_DO_FORNECEDOR']];

/** Situação sugerida a partir da leitura: corresponde ao exigido + validade. Sem correspondência, não sugere. */
$situacaoSugerida = static function (array $s) use ($data): ?string {
    // Migração 347: com o modelo do documento, item obrigatório ausente (ou regra que não confere) sugere devolver ao fornecedor
    if ((int)($s['modelo']['faltando'] ?? 0) > 0) return 'Pendente com o fornecedor';
    if (strtoupper((string)($s['corresponde_ao_exigido'] ?? '')) !== 'SIM') return null;
    $validade = $data($s['data_validade'] ?? '');
    if ($validade === null) return 'Aprovado sem validade';
    return $validade < date('Y-m-d') ? 'Reprovado' : 'Aprovado';
};
$resposta = static function (int $codanalise, string $arquivo, array $s, float $confianca, string $fonte, bool $reaproveitada = false) use ($registro, $cadastro, $situacaoSugerida): array {
    return ['ok' => true, 'codanalise' => $codanalise, 'arquivo' => $arquivo, 'sugestao' => $s, 'confianca' => $confianca, 'fonte_texto' => $fonte, 'reaproveitada' => $reaproveitada,
        'cadastro' => $cadastro($registro), 'situacoes' => DIA_SITUACOES, 'situacao_sugerida' => $situacaoSugerida($s),
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
    $arquivo = $storage->resolve('fo_fornecedorxcriterios', $codigo, $nomeArquivo);
    if ($arquivo === null) $json(['ok' => false, 'message' => $t('arquivo_nao_localizado', ['pt' => 'Arquivo não localizado.', 'en' => 'File not found.', 'es' => 'Archivo no encontrado.'])], 404);
    $ged = new GedAnaliseIaRegistro($pdo, $usuario);
    if ($filaJob === null) {
        try { $decisao = $ged->preparar(DIA_TIPO_GED, 'CODFORNECEDORXCRITERIOS', $codigo, $nomeArquivo, $arquivo, !empty($_POST['forcar']), $_SESSION, (string)$registro['RAZAOSOCIAL'] . ' / ' . (string)$registro['DSCRITERIO']); }
        catch (Throwable $e) { $json(['ok' => false, 'message' => $e->getMessage()], 403); }
        if (in_array($decisao['acao'], ['DUPLICADO', 'EM_ANDAMENTO'], true)) $json(GedAnaliseIaRegistro::respostaDuplicado($decisao) + ['pode_reanalisar' => GedAnaliseIaRegistro::podeReanalisar($_SESSION)]);
        $codGed = (int)$decisao['codanalise'];
    } else { $codGed = (int)$filaJob['codanalise_ged']; $decisao = ['acao'=>'IA_FILA','codanalise'=>$codGed]; }
    $falhar = static function (string $mensagem, int $status) use ($ged, $codGed, $json): never { $ged->falhar($codGed, $mensagem); $json(['ok' => false, 'message' => $mensagem], $status); };
    $alertas = [];
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
        $texto = trim((string)($extracao['texto'] ?? ''));
        if ($texto === '') $falhar($t('sem_texto', ['pt' => 'O documento não tem texto legível para análise.', 'en' => 'The document has no readable text to analyze.', 'es' => 'El documento no tiene texto legible para analizar.']), 422);
        // Migração 347: com modelo cadastrado para o critério, a IA confere também os itens e as regras do modelo
        $modeloDoc = HomologacaoDocumentoModelo::doDocumento($pdo, 'FORNECEDOR', $codigo);
        $contexto = 'DOCUMENTO EXIGIDO: ' . $registro['DSCRITERIO'] . "\nFORNECEDOR: " . $registro['RAZAOSOCIAL'] . ($registro['CNPJ'] ? ' (CNPJ ' . $registro['CNPJ'] . ')' : '') . "\n"
            . ($modeloDoc ? HomologacaoDocumentoModelo::instrucoes($modeloDoc, 'FORNECEDOR') : '') . "---\n";
        $idioma = (string)($_SESSION['idioma'] ?? 'pt');
        try {
            $p = $call('CALL sp_ia_prompt_obter(:codigo,:idioma)', ['codigo' => 'FORNECEDOR_DOCUMENTO', 'idioma' => $idioma]);
            $prompt = trim((string)($p[0]['prompt'] ?? ''));
            if ($prompt === '') throw new RuntimeException($t('prompt', ['pt' => 'Prompt FORNECEDOR_DOCUMENTO não configurado (rode a migração 261).', 'en' => 'FORNECEDOR_DOCUMENTO prompt not configured (run migration 261).', 'es' => 'Prompt FORNECEDOR_DOCUMENTO no configurado (ejecute la migración 261).']));
            if ($iaResultado === null) {
                $job = (new DocumentAiQueueService($pdo))->enqueue(['chave_idempotencia'=>'IA:FORNECEDORCRITERIO:'.$codigo.':'.$nomeArquivo.':'.hash('sha256',$texto),'tipo'=>'FORNECEDORCRITERIOS','entidade'=>'fo_fornecedorxcriterios','registro_id'=>(string)$codigo,'arquivo'=>$nomeArquivo,'codanalise_ged'=>$codGed,'codusuario'=>$usuario,'idioma'=>$idioma,'prompt'=>$prompt,'texto'=>$contexto.$texto]);
                $json(['ok'=>true,'queued'=>true,'status'=>$job['status'],'ia_job_id'=>(int)$job['codjob'],'codanalise'=>$codGed,'arquivo'=>$nomeArquivo],202);
            }
            $ia = $iaResultado;
        } catch (Throwable $e) { $falhar($e->getMessage(), 422); }
        $s = is_array($ia['sugestao'] ?? null) ? $ia['sugestao'] : [];
    }
    // Conferência com o cadastro (e com o modelo do documento, migração 347) e gravação da análise: classes/HomologacaoDocumentoIa.php
    $fin = HomologacaoDocumentoIa::fornecedor($pdo, $registro, $codigo, $nomeArquivo, $s, $extracao, $alertas, $usuario, $codGed, HomologacaoDocumentoModelo::doDocumento($pdo, 'FORNECEDOR', $codigo), $ged);
    $s = $fin['s']; $confianca = (float)$fin['confianca']; $codanalise = (int)$fin['codanalise'];
    $auditar('ANALISE_IA', ['codanalise' => $codanalise, 'arquivo' => $nomeArquivo, 'tipo' => $s['tipo_documento'] ?? null, 'corresponde' => $s['corresponde_ao_exigido']]);
    $json($resposta($codanalise, $nomeArquivo, $s, $confianca, (string)($extracao['fonte'] ?? ''), $decisao['acao'] === 'REAPROVEITAR'));
}

$codanalise = max(0, (int)($_POST['codanalise'] ?? 0));
$analise = $call('CALL sp_docfornecedor_ia_obter(?, ?)', [$codanalise, $codigo])[0] ?? null;
if (!$analise) $json(['ok' => false, 'message' => $t('analise_nao_localizada', ['pt' => 'Análise não localizada.', 'en' => 'Analysis not found.', 'es' => 'Análisis no encontrado.'])], 404);
if ($analise['status'] !== 'PENDENTE') $json(['ok' => false, 'message' => $t('analise_encerrada', ['pt' => 'Esta análise já foi confirmada ou descartada.', 'en' => 'This analysis was already confirmed or discarded.', 'es' => 'Este análisis ya fue confirmado o descartado.'])], 409);

if ($acao === 'review') {
    $s = json_decode((string)($analise['dados_ia'] ?? '{}'), true) ?: [];
    $json($resposta($codanalise, (string)$analise['arquivo'], $s, (float)($analise['confianca'] ?? 0), (string)($analise['fonte_texto'] ?? '')));
}
if ($acao === 'discard') {
    $call('CALL sp_docfornecedor_ia_descartar(?, ?)', [$codanalise, $usuario]);
    $auditar('ANALISE_IA_DESCARTADA', ['codanalise' => $codanalise]);
    (new GedAnaliseIaRegistro($pdo, $usuario))->encerrarPorReferencia(DIA_TIPO_GED, $codanalise, 'DESCARTADA');
    $json(['ok' => true, 'message' => $t('descartada', ['pt' => 'Análise descartada.', 'en' => 'Analysis discarded.', 'es' => 'Análisis descartado.'])]);
}
if ($acao !== 'confirm') $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.acao_invalida', ['pt' => 'Ação inválida.', 'en' => 'Invalid action.', 'es' => 'Acción inválida.'])], 400);

$dados = json_decode((string)($_POST['dados'] ?? '{}'), true);
if (!is_array($dados)) $json(['ok' => false, 'message' => IdiomaService::t('api.ia.sugestao_invalida', ['pt' => 'Sugestão inválida.', 'en' => 'Invalid suggestion.', 'es' => 'Sugerencia inválida.'])], 422);
$atualizar = !empty($dados['atualizar']);
$validade = $atualizar ? $data($dados['data_validade'] ?? '') : null;
$situacao = $atualizar && in_array((string)($dados['situacao'] ?? ''), DIA_SITUACOES, true) ? (string)$dados['situacao'] : null;
$enviado = $atualizar && in_array((string)($dados['enviado'] ?? ''), ['Sim', 'Não'], true) ? (string)$dados['enviado'] : null;
$observacao = $atualizar && array_key_exists('observacao', $dados) && trim((string)$dados['observacao']) !== trim((string)($registro['OBSERVACAO'] ?? '')) ? mb_substr(trim((string)$dados['observacao']), 0, 4000) : null;
$limpos = array_intersect_key($dados, array_flip(['atualizar', 'tipo_documento', 'numero_documento', 'orgao_emissor', 'data_emissao', 'data_validade', 'situacao', 'enviado', 'observacao']));
try {
    $r = $call('CALL sp_docfornecedor_ia_confirmar(?, ?, ?, ?, ?, ?, ?, ?)', [$codanalise, $codigo, $validade, $situacao, $enviado, $observacao, json_encode($limpos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $usuario]);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    foreach (['SITUACAO_INVALIDA' => $t('situacao_invalida', ['pt' => 'Situação inválida.', 'en' => 'Invalid status.', 'es' => 'Situación inválida.']), 'ANALISE_ENCERRADA' => $t('analise_encerrada', ['pt' => 'Esta análise já foi confirmada ou descartada.', 'en' => 'This analysis was already confirmed or discarded.', 'es' => 'Este análisis ya fue confirmado o descartado.'])] as $k => $v) if (str_contains($msg, $k)) $msg = $v;
    $json(['ok' => false, 'message' => $msg], 422);
}
$aplicados = json_decode((string)($r[0]['aplicados'] ?? '{}'), true) ?: [];
$auditar('ANALISE_IA_CONFIRMADA', ['codanalise' => $codanalise, 'aplicados' => $aplicados]);
(new GedAnaliseIaRegistro($pdo, $usuario))->encerrarPorReferencia(DIA_TIPO_GED, $codanalise, 'CONFIRMADA');
$json(['ok' => true, 'aplicados' => $aplicados, 'message' => $aplicados
    ? $t('confirmada_aplicada', ['pt' => 'Dados confirmados e gravados no documento do fornecedor.', 'en' => 'Data confirmed and saved to the supplier document.', 'es' => 'Datos confirmados y guardados en el documento del proveedor.'])
    : $t('confirmada_sem_alteracao', ['pt' => 'Análise confirmada e guardada (nenhum dado do documento foi alterado).', 'en' => 'Analysis confirmed and stored (no document data was changed).', 'es' => 'Análisis confirmado y guardado (ningún dato del documento fue modificado).'])]);
