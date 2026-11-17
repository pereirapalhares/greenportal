<?php
declare(strict_types=1);

session_start();
$script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/credenciais/sair.php'));
$baseUrl = dirname(dirname($script));
$baseUrl = $baseUrl === '/' || $baseUrl === '.' ? '' : rtrim($baseUrl, '/');
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
header('Location: ' . $baseUrl . '/index.php');
exit;
