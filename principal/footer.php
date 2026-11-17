<?php
declare(strict_types=1);
if (!isset($mainTexts, $tenant)) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'ApplicationIdentity.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'IdiomaService.php';
?>
<footer class="portal-footer" id="portalFooter">
    <div class="footer-status-area"><i class="bi bi-check-circle" id="footerStatusIcon"></i><span id="footerStatusText"><?php echo htmlspecialchars(IdiomaService::t('portal.rodape_pronto', ['pt' => 'Pronto', 'en' => 'Ready', 'es' => 'Listo']), ENT_QUOTES, 'UTF-8'); ?></span></div>
    <div class="footer-context" id="footerContext">Dashboard</div>
    <div class="footer-company"><span><?php echo htmlspecialchars($mainTexts['main_rodape_texto'], ENT_QUOTES, 'UTF-8'); ?></span><span><?php echo htmlspecialchars((string)$tenant['nome'], ENT_QUOTES, 'UTF-8'); ?></span><span><?php echo htmlspecialchars(ApplicationIdentity::VERSION, ENT_QUOTES, 'UTF-8'); ?></span></div>
</footer>
