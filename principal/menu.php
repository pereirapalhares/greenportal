<?php
declare(strict_types=1);

if (!isset($menuByParent, $baseUrl)) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
$renderMenus = static function (array $menus, int $nivel = 1) use (&$renderMenus, $menuByParent, $baseUrl): void {
    foreach ($menus as $menu) {
        $codigo = (string)$menu['codigo'];
        $label = (string)$menu['_label'];
        $children = $menuByParent[(int)$menu['codmenu']] ?? [];
        $classe = $nivel === 1 ? 'portal-menu-item' : 'portal-submenu-item';
        $icon = (string)$menu['_icon'];
        $id = (string)$menu['_id'];
        if ($children) {
            echo '<button class="' . $classe . ' menu-parent" type="button" data-menu-parent data-menu-id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" aria-expanded="false"><i class="bi ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i><span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span><i class="bi bi-chevron-down menu-chevron"></i></button>';
            echo '<div class="portal-submenu" data-submenu>';
            $renderMenus($children, $nivel + 1);
            echo '</div>';
        } elseif ((string)($menu['url'] ?? '') !== '') {
            echo '<button class="' . $classe . ($codigo === 'DASHBOARD' ? ' active' : '') . '" type="button" data-open-tab="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" data-tab-url="' . htmlspecialchars($baseUrl . $menu['url'], ENT_QUOTES, 'UTF-8') . '" data-tab-title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"><i class="bi ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i><span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span></button>';
        } else {
            echo '<button class="' . $classe . '" type="button" data-construction data-title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"><i class="bi ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i><span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span></button>';
        }
    }
};
$renderMenus($menuByParent[0] ?? []);
