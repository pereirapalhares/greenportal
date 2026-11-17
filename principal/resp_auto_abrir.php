<?php
declare(strict_types=1);

/*
 * Link dos alertas da indicação automática de responsáveis (migração 345): abre o cadastro do pilar já filtrado no item.
 * O link do alerta não pode levar JSON (main.php só aceita endereço simples), por isso esta página monta o filtro.
 * ?area=LICENCAS|MONITORAMENTO|TREINAMENTOS|INFRAESTRUTURA|CONTROLES&id=N. A tela de destino confere a permissão.
 */
require_once dirname(__DIR__) . '/classes/ResponsavelAutomatico.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (empty($_SESSION['autenticado'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
$area = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($_GET['area'] ?? '')) ?? '');
$id = max(0, (int)($_GET['id'] ?? 0));
$t = ResponsavelAutomatico::TABELAS[$area] ?? null;
if ($t === null || $id <= 0) { header('Location: home_alertas.php'); exit; }
[, $chave, , $pagina] = $t;
header('Location: ' . $pagina . '?' . http_build_query(['filtros' => json_encode([$chave => ['value' => (string)$id, 'exact' => true]], JSON_UNESCAPED_SLASHES)]));
exit;
