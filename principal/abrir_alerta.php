<?php
declare(strict_types=1);

/*
 * Link "Abrir no portal" dos e-mails de alerta (migração 343): /principal/abrir_alerta.php?e=EMPRESA&a=CODALERTA.
 * Já logado na mesma empresa: abre o portal com a tela do alerta como aba (main.php?abrir_alerta=N, que confere se o alerta é do
 * usuário e o marca como lido). Sem sessão, ou logado em outra empresa: vai para o login com a empresa escolhida; depois do login
 * a tela abre do mesmo jeito. Nada é aberto sem login.
 */

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
session_start();
$script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/principal/abrir_alerta.php'));
$base = str_replace('\\', '/', dirname(dirname($script)));
$base = $base === '/' || $base === '.' ? '' : rtrim($base, '/');
$empresa = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)($_GET['e'] ?? '')) ?? '';
$alerta = max(0, (int)($_GET['a'] ?? 0));
if ($alerta <= 0) { header('Location: ' . $base . '/index.php'); exit; }
$mesma = !empty($_SESSION['autenticado']) && $empresa !== '' && strcasecmp((string)($_SESSION['tenant']['codigo'] ?? ''), $empresa) === 0;
if ($mesma) { header('Location: ' . $base . '/principal/main.php?abrir_alerta=' . $alerta); exit; }
header('Location: ' . $base . '/index.php?' . http_build_query(['empresa' => $empresa, 'abrir_alerta' => $alerta] + (!empty($_SESSION['autenticado']) ? ['outra_empresa' => 1] : [])));
exit;
