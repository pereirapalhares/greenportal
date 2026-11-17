<?php
declare(strict_types=1); session_start();
if (empty($_SESSION['autenticado'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
?>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/css/app.css" rel="stylesheet"><link href="../assets/css/main.css?v=15" rel="stylesheet">
<main class="dashboard-content"><section class="dashboard-panel"><div class="panel-title"><div><h3>Ajuda</h3><p>Orientações para utilização do Green Portal.</p></div><i class="bi bi-question-circle"></i></div><div class="empty-dashboard"><i class="bi bi-info-circle"></i><strong>Conteúdo de ajuda em construção</strong><span>As orientações serão disponibilizadas nesta área.</span></div></section></main>
