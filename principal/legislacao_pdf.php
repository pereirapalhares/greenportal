<?php
declare(strict_types=1);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
session_start();
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'UsuarioSessao.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'PermissaoTransacao.php';
if (empty($_SESSION['autenticado'])) { http_response_code(401); exit('Sessão expirada.'); }
$config = [];
foreach (file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) { $line = trim($line); if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue; [$key, $value] = explode('=', $line, 2); $config[trim($key)] = trim(trim($value), "\"'"); }
$pdo = new PDO('mysql:host=' . ($config['GPT_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($config['GPT_DB_PORT'] ?? '3306') . ';dbname=' . $_SESSION['tenant']['banco_dados'] . ';charset=utf8mb4', $config['GPT_DB_USERNAME'] ?? '', $config['GPT_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if (!UsuarioSessao::atualizar($pdo, $_SESSION) || !PermissaoTransacao::obter('LEGISLACAO')['pode_visualizar']) { http_response_code(403); exit('Seu perfil não tem permissão para visualizar esta legislação.'); }
$codigo = trim((string)($_GET['codigo'] ?? ''));
if (!preg_match('/^[A-Za-z0-9_-]+$/', $codigo)) { http_response_code(400); exit('Código de legislação inválido.'); }
$base = 'E:' . DIRECTORY_SEPARATOR . 'projetoGreen' . DIRECTORY_SEPARATOR . 'GED' . DIRECTORY_SEPARATOR . 'legislacao';
$codigoArquivo = preg_match('/[A-Za-z]$/', $codigo) ? substr($codigo, 0, -1) : $codigo;
$root = realpath($base); $file = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . $codigoArquivo . '.pdf');
if ($root === false || $file === false || strncmp(strtolower($file), strtolower($root . DIRECTORY_SEPARATOR), strlen($root . DIRECTORY_SEPARATOR)) !== 0 || !is_file($file)) { http_response_code(404); exit('O PDF desta legislação não foi encontrado.'); }
header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . basename($file) . '"'); header('Content-Length: ' . (string)filesize($file)); readfile($file);
