<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
// Marca como lido um alerta do usuário logado (alertas.js, ao abrir o link do alerta)
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE); exit; }
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'UsuarioSessao.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'AlertasService.php';
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) { http_response_code(401); echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE); exit; }
$config = [];
foreach (file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) { if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue; [$key, $value] = explode('=', $line, 2); if (!isset($config[trim($key)])) $config[trim($key)] = trim(trim($value), "\"'"); }
$pdo = new PDO('mysql:host=' . ($config['GPT_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($config['GPT_DB_PORT'] ?? '3306') . ';dbname=' . $_SESSION['tenant']['banco_dados'] . ';charset=utf8mb4', $config['GPT_DB_USERNAME'] ?? '', $config['GPT_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { http_response_code(401); echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE); exit; }
$ok = (new AlertasService($pdo, (int)$_SESSION['usuario']['codusuario']))->marcarLido((int)($_POST['codalerta'] ?? 0));
echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
