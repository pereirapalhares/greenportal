<?php
declare(strict_types=1);

/**
 * Padrão visual das páginas independentes do portal (popups e telas em iframe próprio).
 *
 * Uso, dentro do <head>, DEPOIS dos CSS do Bootstrap:
 *   <?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?>
 *
 * Emite assets/css/pagina-padrao.css e marca o <html> com "gp-pagina theme-<tema>". O tema vem da janela
 * principal do portal (classe theme-verde|azul|cinza do <body> do main.php); fora dela, da sessão; senão verde.
 * $base: caminho até a raiz do portal a partir da página ('..' para principal/, '.' para a raiz).
 */
if (!function_exists('greenPortalEstiloPagina')) {
    function greenPortalEstiloPagina(string $base = '..'): void
    {
        $tema = (string)($_SESSION['layout_tema'] ?? ($_SESSION['tema'] ?? 'verde'));
        if (!in_array($tema, ['verde', 'azul', 'cinza'], true)) $tema = 'verde';
        $base = rtrim($base, '/');
        echo '<link href="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '/assets/css/design-system.css?v=3" rel="stylesheet">';
        echo '<link href="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '/assets/css/popups.css?v=1" rel="stylesheet">';
        echo '<link href="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '/assets/css/modulo-legal.css?v=1" rel="stylesheet">';
        echo '<link href="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '/assets/css/homologacao-fornecedores.css?v=1" rel="stylesheet">';
        echo '<link href="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '/assets/css/nao-conformidades.css?v=1" rel="stylesheet">';
        echo '<link href="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '/assets/css/pagina-padrao.css?v=2" rel="stylesheet">';
        echo '<script>(function(){var t=' . json_encode($tema) . ';try{var b=window.top&&window.top!==window&&window.top.document.body;'
            . 'var m=b&&String(b.className).match(/\\btheme-(verde|azul|cinza)\\b/);if(m)t=m[1];}catch(e){}'
            . 'document.documentElement.classList.add("gp-pagina","theme-"+t);})();</script>';
    }
}

/**
 * Nome da empresa (tenant) da sessão, para o cabeçalho dos PDFs e impressões (regra do usuário, 22/10/2026: todo PDF gerado
 * tem o nome da empresa no cabeçalho). Mesmo valor do cabeçalho do portal (main.php).
 */
if (!function_exists('greenPortalNomeEmpresa')) {
    function greenPortalNomeEmpresa(?array $sessao = null): string
    {
        $t = (array)(($sessao ?? ($_SESSION ?? []))['tenant'] ?? []);
        return trim((string)($t['nome'] ?? $t['empresa_nome'] ?? '')) ?: (string)($t['banco_dados'] ?? '');
    }
}

/**
 * Cabeçalho que só aparece na impressão / "Salvar como PDF" do navegador: nome da empresa, título e data de emissão.
 * Uso: logo depois do <body>: <?php greenPortalCabecalhoImpressao('Ficha do fornecedor'); ?>
 */
if (!function_exists('greenPortalCabecalhoImpressao')) {
    function greenPortalCabecalhoImpressao(string $titulo = ''): void
    {
        $h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        echo '<style>.gp-cab-impressao{display:none}@media print{.gp-cab-impressao{display:table!important;width:100%;border-collapse:collapse;margin:0 0 10px;'
            . 'background:#1f5638!important;color:#fff!important;print-color-adjust:exact;-webkit-print-color-adjust:exact}.gp-cab-impressao td{padding:8px 12px;vertical-align:middle}'
            . '.gp-cab-impressao .gp-ci-empresa{font-size:15px;font-weight:700}.gp-cab-impressao .gp-ci-sub{font-size:10px;opacity:.85}.gp-cab-impressao .gp-ci-dir{text-align:right;font-size:10px}}</style>';
        echo '<table class="gp-cab-impressao" aria-hidden="true"><tr><td><div class="gp-ci-empresa">' . $h(greenPortalNomeEmpresa()) . '</div><div class="gp-ci-sub">Green Portal</div></td>'
            . '<td class="gp-ci-dir"><b>' . $h($titulo) . '</b><br>Emitido em ' . date('d/m/Y H:i') . '</td></tr></table>';
    }
}
