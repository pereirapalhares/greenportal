<?php
declare(strict_types=1);
// Migração 385 — RCL Completa / RCL Padrão (Manutenção Legal › Legislação). Só ADMINISTRADOR.
// GET: empresa, unidade, mes (AAAA-MM), tipo (completa|padrao). Devolve o .xlsx para download e grava o histórico do dia
// em legislacaoxrevisoes do banco da empresa (como o legado).
session_start();
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/UsuarioImagem.php';
require_once dirname(__DIR__) . '/classes/LegislacaoRcl.php';

$erro = static function (int $http, string $msg): never {
    http_response_code($http);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta charset="utf-8"><body style="font:14px Arial;padding:16px">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</body>');
};
if (empty($_SESSION['autenticado'])) $erro(401, 'Sessão expirada. Entre de novo no portal.');
$env = GreenPortalDatabase::env();
$tenantPdo = GreenPortalDatabase::connect($env, (string)($_SESSION['tenant']['banco_dados'] ?? ''), 'GPT');
if (!UsuarioSessao::atualizar($tenantPdo, $_SESSION)) $erro(401, 'Sessão expirada. Entre de novo no portal.');
if (($_SESSION['usuario']['codigo_perfil'] ?? '') !== 'ADMINISTRADOR') $erro(403, 'Transação exclusiva do perfil ADMINISTRADOR.');
$usuario = (string)($_SESSION['usuario']['nome'] ?? $_SESSION['usuario']['userid'] ?? '');
session_write_close();

$empresa = (int)preg_replace('/\D/', '', (string)($_GET['empresa'] ?? ''));
$unidade = (int)preg_replace('/\D/', '', (string)($_GET['unidade'] ?? ''));
$mes = (string)($_GET['mes'] ?? '');
$completa = strtolower((string)($_GET['tipo'] ?? '')) === 'completa';
if ($empresa < 1 || $unidade < 1 || !preg_match('/^\d{4}-\d{2}$/', $mes)) $erro(400, 'Para gerar o RCL escolha Empresa, Unidade e Mês/Ano nos filtros da tela.');

@set_time_limit(600);
$arquivo = tempnam(sys_get_temp_dir(), 'rcl');
try {
    $dados = LegislacaoRcl::dados($empresa, $unidade, $mes, $completa);
    $logo = null;
    try { $f = UsuarioImagem::logoArquivo($dados['banco']); if (is_file((string)$f) && @getimagesize((string)$f)) $logo = (string)$f; } catch (Throwable) {}
    LegislacaoRcl::gravarXlsx($arquivo, $dados, $completa, $mes, $usuario, $logo);
    try { LegislacaoRcl::gravarRevisao($empresa, $dados['revisao']); } catch (Throwable $e) { error_log('[RCL] histórico não gravado: ' . $e->getMessage()); }
} catch (InvalidArgumentException $e) {
    @unlink($arquivo); $erro(400, $e->getMessage());
} catch (Throwable $e) {
    @unlink($arquivo); error_log('[RCL] ' . $e->getMessage()); $erro(500, 'Não foi possível gerar o RCL.');
}
$nome = LegislacaoRcl::nomeArquivo($dados['empresa'], $dados['unidade'], $completa, $mes);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nome . '"; filename*=UTF-8\'\'' . rawurlencode($nome));
header('Content-Length: ' . (string)filesize($arquivo));
header('Cache-Control: no-store');
readfile($arquivo);
@unlink($arquivo);
