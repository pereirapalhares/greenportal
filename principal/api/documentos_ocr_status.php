<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/IdiomaService.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';
$json=static function(array $data,int $status=200):never{http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;};
if(empty($_SESSION['autenticado'])||empty($_SESSION['tenant']['banco_dados']))$json(['ok'=>false,'message'=>IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.'])],401);
$pdo=TenantContext::connect(GreenPortalDatabase::env(),$_SESSION);if(!UsuarioSessao::atualizar($pdo,$_SESSION))$json(['ok'=>false,'message'=>IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.'])],401);
$id=max(0,(int)($_GET['job_id']??$_POST['job_id']??0));if(!$id)$json(['ok'=>false,'message'=>IdiomaService::t('api.ocr.job_invalido', ['pt' => 'Job de OCR inválido.', 'en' => 'Invalid OCR job.', 'es' => 'Trabajo de OCR inválido.'])],422);
$st=$pdo->prepare('SELECT codjob,status,texto,paginas,confianca,erro,concluida_em FROM vw_green_ocr_jobs WHERE codjob=:id AND codusuario=:usuario LIMIT 1');$st->execute(['id'=>$id,'usuario'=>(int)($_SESSION['usuario']['codusuario']??0)]);$job=$st->fetch(PDO::FETCH_ASSOC);if(!$job)$json(['ok'=>false,'message'=>IdiomaService::t('api.ocr.job', ['pt' => 'Job de OCR não localizado.', 'en' => 'OCR job not found.', 'es' => 'Trabajo de OCR no encontrado.'])],404);
$json(['ok'=>true,'status'=>$job['status'],'texto'=>(string)($job['texto']??''),'paginas'=>(int)($job['paginas']??0),'confianca'=>(float)($job['confianca']??0),'erro'=>$job['erro'],'concluida_em'=>$job['concluida_em']]);
