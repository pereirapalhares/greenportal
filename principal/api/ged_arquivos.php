<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/TransactionSecurity.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$json = static function (array $data, int $status = 200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $json(['ok'=>false,'message'=>'Sessão expirada.'], 401);
$env = GreenPortalDatabase::env(); $db = (string)$_SESSION['tenant']['banco_dados']; $tenant = TransactionSecurity::connect('GESTAO_ARQUIVOS');
if (!UsuarioSessao::atualizar($tenant, $_SESSION)) $json(['ok'=>false,'message'=>'Sessão expirada.'], 401);
$permission = PermissaoTransacao::obter('GESTAO_ARQUIVOS'); if (!$permission['pode_visualizar']) $json(['ok'=>false,'message'=>'Sem permissão para acessar os arquivos.'], 403);
$documentId = max(0, (int)($_POST['coddocumento'] ?? $_GET['coddocumento'] ?? 0)); if ($documentId < 1) $json(['ok'=>false,'message'=>'Documento inválido.'], 400);
$call = static fn(string $procedure, array $params = []): array => GreenPortalDatabase::call($tenant, $procedure, $params);
$exists = $call('CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela'=>'documentos','filtros'=>json_encode(['CODDOCUMENTO'=>$documentId])]); if (!$exists) $json(['ok'=>false,'message'=>'Documento não localizado.'], 404);
$legacy = trim((string)($_SESSION['tenant']['banco_legado'] ?? $_SESSION['tenant']['ged'] ?? ''));
if ($legacy === '') { $legacy = preg_replace('/_dbp$/i', '', $db) ?: $db; $legacy = preg_replace('/^gmodelo$/i', 'green_modelo', $legacy) ?: $legacy; if (str_starts_with(strtolower($legacy), 'g') && !str_starts_with(strtolower($legacy), 'green_')) $legacy = 'green_' . substr($legacy, 1); }
$action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? 'list')));
$root = 'E:' . DIRECTORY_SEPARATOR . 'projetoGreen' . DIRECTORY_SEPARATOR . 'GED'; $folder = $root . DIRECTORY_SEPARATOR . $legacy . DIRECTORY_SEPARATOR . 'documentos' . DIRECTORY_SEPARATOR . (string)$documentId;
if ($action === 'upload' && !is_dir($root)) @mkdir($root, 0770, true);
$safeRoot = realpath($root);
if ($action === 'upload' && $safeRoot !== false && !is_dir($folder)) @mkdir($folder, 0770, true);
if (!is_dir($folder)) { if ($action === 'list') $json(['ok'=>true,'files'=>[],'legacy_database'=>$legacy,'folder'=>'documentos/' . $documentId,'can_upload'=>(bool)$permission['pode_incluir'],'can_delete'=>(bool)$permission['pode_excluir']]); $json(['ok'=>false,'message'=>'Arquivo não encontrado.'], 404); }
$safeFolder = realpath($folder); if ($safeFolder === false || $safeRoot === false || strncmp(strtolower($safeFolder), strtolower($safeRoot . DIRECTORY_SEPARATOR), strlen($safeRoot . DIRECTORY_SEPARATOR)) !== 0) $json(['ok'=>false,'message'=>'Pasta de arquivos inválida.'], 400);
$list = static function (string $dir): array { $items=[]; foreach (new DirectoryIterator($dir) as $file) { if ($file->isDot() || !$file->isFile()) continue; $items[]=['name'=>$file->getFilename(),'size'=>$file->getSize(),'created_at'=>date('Y-m-d H:i:s', $file->getCTime()),'url'=>'api/ged_arquivos.php?action=download&coddocumento=' . rawurlencode((string)($_GET['coddocumento'] ?? $_POST['coddocumento'] ?? '')) . '&arquivo=' . rawurlencode($file->getFilename())]; } usort($items, static fn(array $a,array $b):int=>strcmp($a['name'],$b['name'])); return $items; };
if ($action === 'list') $json(['ok'=>true,'files'=>$list($safeFolder),'legacy_database'=>$legacy,'folder'=>'documentos/' . $documentId,'can_upload'=>(bool)$permission['pode_incluir'],'can_delete'=>(bool)$permission['pode_excluir']]);
if ($action === 'download') { $name=basename((string)($_GET['arquivo']??'')); $file=realpath($safeFolder . DIRECTORY_SEPARATOR . $name); if($file===false||!is_file($file)||strncmp(strtolower($file),strtolower($safeFolder.DIRECTORY_SEPARATOR),strlen($safeFolder.DIRECTORY_SEPARATOR))!==0)$json(['ok'=>false,'message'=>'Arquivo não encontrado.'],404); header('Content-Type', mime_content_type($file) ?: 'application/octet-stream'); header('Content-Disposition: inline; filename="' . basename($file) . '"'); header('Content-Length: ' . (string)filesize($file)); readfile($file); exit; }
if (!$permission['pode_incluir'] && !$permission['pode_excluir']) $json(['ok'=>false,'message'=>'Sem permissão para alterar arquivos.'], 403);
if ($action === 'delete') { if(!$permission['pode_excluir'])$json(['ok'=>false,'message'=>'Sem permissão para excluir arquivos.'],403); $name=basename((string)($_POST['arquivo']??'')); $file=realpath($safeFolder.DIRECTORY_SEPARATOR.$name); if($file===false||!is_file($file)||strncmp(strtolower($file),strtolower($safeFolder.DIRECTORY_SEPARATOR),strlen($safeFolder.DIRECTORY_SEPARATOR))!==0)$json(['ok'=>false,'message'=>'Arquivo não encontrado.'],404); if(!@unlink($file))$json(['ok'=>false,'message'=>'Não foi possível excluir o arquivo.'],500); $json(['ok'=>true,'files'=>$list($safeFolder)]); }
if ($action === 'upload') { if(!$permission['pode_incluir'])$json(['ok'=>false,'message'=>'Sem permissão para enviar arquivos.'],403); $upload=$_FILES['arquivo']??null; if(!is_array($upload)||($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)$json(['ok'=>false,'message'=>'Selecione um arquivo válido.'],400); if((int)$upload['size']>50*1024*1024)$json(['ok'=>false,'message'=>'O arquivo excede o limite de 50 MB.'],400); $original=basename((string)$upload['name']); $name=preg_replace('/[^A-Za-z0-9._-]+/','_', $original) ?: 'arquivo'; $name=date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $name; if(!move_uploaded_file((string)$upload['tmp_name'],$safeFolder.DIRECTORY_SEPARATOR.$name))$json(['ok'=>false,'message'=>'Não foi possível armazenar o arquivo.'],500); $json(['ok'=>true,'files'=>$list($safeFolder)]); }
$json(['ok'=>false,'message'=>'Ação inválida.'],400);
