<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/IdiomaService.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__, 2) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';
require_once dirname(__DIR__, 2) . '/classes/OcrQueueService.php';

$json = static function (array $data, int $status = 200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
};
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $json(['ok'=>false,'message'=>IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.'])], 401);
$env = GreenPortalDatabase::env(); $pdo = TenantContext::connect($env, $_SESSION);
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $json(['ok'=>false,'message'=>IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.'])], 401);
$types = [
    'licencas'=>['id'=>'CODLICENCA','table'=>'li_licencas','permission'=>'LICENCAS'],
    'controles'=>['id'=>'CODDOCUMENTO','table'=>'documentos','permission'=>'CONTROLE_DOCUMENTOS'],
    'infraestrutura'=>['id'=>'CODINFRAESTRUTURA','table'=>'ml_infraestrutura','permission'=>'MODULOLEGAL_INFRAESTRUTURA'],
    'treinamentos'=>['id'=>'CODTREINAMENTO','table'=>'ml_treinamentos','permission'=>'MODULOLEGAL_TREINAMENTOS'],
    'funcionarios'=>['id'=>'CODFUNCIONARIO','table'=>'fo_funcionarios','permission'=>'PORTARIA_DOCUMENTACAO_FUNCIONARIO','ged'=>'fo_funcionarios'],
    'funcionariocriterios'=>['id'=>'CODIGO','table'=>'fo_funcionarioxtiposxcriterios','permission'=>'PORTARIA_DOCUMENTACAO_FUNCIONARIO','ged'=>'fo_funcionarioxtiposxcriterios'],
    'fornecedorcriterios'=>['id'=>'CODFORNECEDORXCRITERIOS','table'=>'fo_fornecedorxcriterios','permission'=>'PORTARIA_DOCUMENTACAO_FORNECEDOR','ged'=>'fo_fornecedorxcriterios'],
];
$tipo = strtolower(trim((string)($_POST['tipo'] ?? ''))); $cfg = $types[$tipo] ?? null;
if (!$cfg) $json(['ok'=>false,'message'=>IdiomaService::t('api.tipo_invalido', ['pt' => 'Tipo inválido.', 'en' => 'Invalid type.', 'es' => 'Tipo inválido.'])], 422);
$permission = PermissaoTransacao::obter($cfg['permission']);
if (empty($permission['pode_editar'])) $json(['ok'=>false,'message'=>IdiomaService::t('api.ocr.sem_permissao', ['pt' => 'Seu perfil não pode solicitar OCR.', 'en' => 'Your profile cannot request OCR.', 'es' => 'Su perfil no puede solicitar OCR.'])], 403);
$id = max(0, (int)($_POST['valor'] ?? 0)); if (!$id) $json(['ok'=>false,'message'=>IdiomaService::t('api.registro_invalido', ['pt' => 'Registro inválido.', 'en' => 'Invalid record.', 'es' => 'Registro inválido.'])], 422);
$csrf = (string)($_SESSION['gestao_arquivos_csrf'] ?? '');
if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $json(['ok'=>false,'message'=>IdiomaService::t('api.token_invalido', ['pt' => 'Token de segurança inválido. Recarregue a página e tente novamente.', 'en' => 'Invalid security token. Reload the page and try again.', 'es' => 'Token de seguridad inválido. Recargue la página e inténtelo nuevamente.'])], 419);
$call = static fn(string $sql, array $params = []): array => GreenPortalDatabase::call($pdo, $sql, $params);
if (!$call('CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela'=>$cfg['table'],'filtros'=>json_encode([$cfg['id']=>$id])])) $json(['ok'=>false,'message'=>IdiomaService::t('api.registro_nao_localizado', ['pt' => 'Registro não localizado.', 'en' => 'Record not found.', 'es' => 'Registro no encontrado.'])], 404);

$fileName = basename((string)($_POST['arquivo'] ?? ''));
$legacy = trim((string)($_SESSION['tenant']['banco_legado'] ?? $_SESSION['tenant']['ged'] ?? ''));
$db = (string)$_SESSION['tenant']['banco_dados'];
if ($legacy === '') { $legacy=preg_replace('/_dbp$/i','',$db)?:$db; $legacy=preg_replace('/^gmodelo$/i','green_modelo',$legacy)?:$legacy; if(str_starts_with(strtolower($legacy),'g')&&!str_starts_with(strtolower($legacy),'green_'))$legacy='green_'.substr($legacy,1); }
$folder = 'E:' . DIRECTORY_SEPARATOR . 'projetoGreen' . DIRECTORY_SEPARATOR . 'GED' . DIRECTORY_SEPARATOR . $legacy . DIRECTORY_SEPARATOR . ($cfg['ged'] ?? $tipo) . DIRECTORY_SEPARATOR . $id;
$safeFolder = realpath($folder); $file = $safeFolder !== false ? realpath($safeFolder . DIRECTORY_SEPARATOR . $fileName) : false;
if ($safeFolder === false || $file === false || !is_file($file) || strncmp(strtolower($file), strtolower($safeFolder . DIRECTORY_SEPARATOR), strlen($safeFolder . DIRECTORY_SEPARATOR)) !== 0 || strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'pdf') $json(['ok'=>false,'message'=>IdiomaService::t('api.ia.pdf_nao_localizado', ['pt' => 'Arquivo PDF não localizado.', 'en' => 'PDF file not found.', 'es' => 'Archivo PDF no encontrado.'])], 404);
$maxBytes = max(1, (int)($env['OCR_MAX_BYTES'] ?? 20971520)); if ((int)filesize($file) > $maxBytes) $json(['ok'=>false,'message'=>IdiomaService::t('api.ocr.limite', ['pt' => 'O PDF excede o limite configurado para OCR.', 'en' => 'The PDF exceeds the configured OCR limit.', 'es' => 'El PDF supera el límite configurado para OCR.'])], 413);
$hash = hash_file('sha256', $file) ?: hash('sha256', $fileName . '|' . filesize($file));
$key = implode(':', ['OCR', $db, $tipo, $id, $fileName, $hash]);
try {
    $job = (new OcrQueueService($pdo, $env))->enqueue(['chave_idempotencia'=>$key,'tipo'=>$tipo,'entidade'=>$cfg['table'],'registro_id'=>(string)$id,'arquivo'=>$fileName,'caminho_arquivo'=>$file,'tamanho_bytes'=>filesize($file),'codusuario'=>(int)($_SESSION['usuario']['codusuario']??0)]);
    $json(['ok'=>true,'status'=>$job['status'],'job_id'=>(int)$job['codjob'],'message'=>IdiomaService::t('api.ocr.na_fila', ['pt' => 'OCR colocado na fila para processamento em segundo plano.', 'en' => 'OCR queued for background processing.', 'es' => 'OCR puesto en cola para procesamiento en segundo plano.'])], 202);
} catch (Throwable $e) { $json(['ok'=>false,'message'=>IdiomaService::t('api.ocr.fila', ['pt' => 'Não foi possível colocar o OCR na fila.', 'en' => 'Could not queue the OCR.', 'es' => 'No fue posible poner el OCR en la cola.'])], 422); }
