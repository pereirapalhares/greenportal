<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'PainelDireitoService.php';
if (!isset($baseUrl)) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
$rightRailItems = PainelDireitoService::itens($baseUrl, (string)($_SESSION['idioma'] ?? 'pt'));
?>
<aside class="portal-right-rail" aria-label="Informações importantes">
    <?php foreach ($rightRailItems as $item): ?>
        <button class="right-rail-button" type="button" data-open-tab="rail-<?php echo strtolower($item['codigo']); ?>" data-tab-url="<?php echo htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8'); ?>" data-tab-title="<?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>">
            <i class="bi <?php echo htmlspecialchars($item['icone'], ENT_QUOTES, 'UTF-8'); ?>"></i>
            <?php if (!empty($item['calendar'])): ?><span class="right-rail-calendar-day"><?php echo (int)$item['badge']; ?></span><?php elseif ((int)$item['badge'] > 0): ?><span class="right-rail-badge"><?php echo (int)$item['badge']; ?></span><?php endif; ?>
        </button>
    <?php endforeach; ?>
</aside>
