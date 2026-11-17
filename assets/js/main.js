(function ($) {
    'use strict';
    // Textos no idioma do usuário (Administração › Textos e idiomas, grupo PORTAL).
    function T(chave, padrao) { return typeof window.greenT === 'function' ? window.greenT('portal.js.' + chave, padrao) : padrao; }
    var $layout = $('#portalLayout');
    var portalTheme = (document.body.className.match(/theme-(verde|azul|cinza)/) || [])[1] || 'verde';
    var toast = bootstrap.Toast.getOrCreateInstance(document.getElementById('constructionToast'), { delay: 3200 });
    var idleTimeoutSeconds = Math.max(60, Number(window.greenPortalTimeoutSeconds || 1800)), idleTimer = null;
    function resetIdleTimer() { window.clearTimeout(idleTimer); idleTimer = window.setTimeout(function () { window.location.href = (window.greenPortalBaseUrl || '') + '/credenciais/sair.php?motivo=inatividade'; }, idleTimeoutSeconds * 1000); }
    // c571 (16/11/2026): pedido do usuário — sem balões de dica (tooltip do Bootstrap, fundo branco com seta) na aplicação.
    // Eles ficavam presos na tela quando a aba ou o popup fechava com o balão aberto. Fica só a dica simples do navegador
    // (atributo title); balões criados antes por outro script são desfeitos e o title original volta.
    function initializeTooltips(root) {
        $(root).find('[data-bs-toggle="tooltip"], [data-bs-original-title]').addBack('[data-bs-toggle="tooltip"], [data-bs-original-title]').each(function () {
            if (window.bootstrap && bootstrap.Tooltip) { var instance = bootstrap.Tooltip.getInstance(this); if (instance) instance.dispose(); }
            var original = this.getAttribute('data-bs-original-title');
            if (original && !this.getAttribute('title')) this.setAttribute('title', original);
            $(this).removeAttr('data-bs-toggle data-bs-placement data-bs-original-title');
        });
        $(root).find('.tooltip').addBack('.tooltip').remove();
        // O menu lateral continua sem dica nenhuma (como antes)
        $(root).find('.portal-menu [title]').addBack('.portal-menu [title]').removeAttr('title');
    }
    function initializeCalendars(root) { $(root).find('input[type="date"], input[type="datetime-local"]').addBack('input[type="date"], input[type="datetime-local"]').each(function () { var $input = $(this); if ($input.data('calendar-ready')) return; $input.data('calendar-ready', true); function ensureMidnight() { if ($input.attr('type') !== 'datetime-local') return; var value = String($input.val() || '').trim(); if (/^\d{4}-\d{2}-\d{2}$/.test(value)) $input.val(value + 'T00:00'); else if (/^\d{4}-\d{2}-\d{2}T\d{2}$/.test(value)) $input.val(value + ':00'); } ensureMidnight(); $input.on('input change blur', ensureMidnight); var $button = $('<button>', { type: 'button', class: 'btn btn-link btn-sm p-0 calendar-picker', title: T('abrir_calendario', 'Abrir calendário'), 'aria-label': T('abrir_calendario', 'Abrir calendário') }).append($('<i>', { class: 'bi bi-calendar3' })); $input.wrap('<div class="input-group input-group-sm flex-nowrap"></div>').parent().append($button); $button.on('click', function () { try { if (typeof $input[0].showPicker === 'function') $input[0].showPicker(); else $input.trigger('focus'); } catch (error) { $input.trigger('focus'); } }); }); }
    initializeTooltips(document);
    initializeCalendars(document);
    if (window.MutationObserver) new MutationObserver(function (mutations) { mutations.forEach(function (mutation) { $(mutation.addedNodes).each(function () { if (this.nodeType === 1) { initializeTooltips(this); initializeCalendars(this); } }); }); }).observe(document.body, { childList: true, subtree: true });
    $(document).on('mousemove mousedown keydown click scroll touchstart', resetIdleTimer);
    resetIdleTimer();

    var menuStateKey = 'greenportal.menu.expanded.v2';
    function readExpandedMenus() {
        try { var value = JSON.parse(window.sessionStorage.getItem(menuStateKey) || '[]'); return Array.isArray(value) ? value : []; } catch (error) { return []; }
    }
    function writeExpandedMenus() {
        var ids = [];
        $('[data-menu-parent].expanded').each(function () { var id = String($(this).data('menu-id') || ''); if (id) ids.push(id); });
        try { window.sessionStorage.setItem(menuStateKey, JSON.stringify(ids)); } catch (error) {}
    }
    function setMenuState($item, open, animate) {
        var $submenu = $item.next('[data-submenu]');
        if (!$submenu.length) return;
        $item.toggleClass('expanded', open).attr('aria-expanded', open ? 'true' : 'false');
        $submenu.stop(true, true)[open ? 'slideDown' : 'slideUp'](animate ? 160 : 0);
    }
    function restoreExpandedMenus() {
        $('[data-menu-parent]').each(function () {
            setMenuState($(this), false, false);
        });
        try { window.sessionStorage.removeItem(menuStateKey); } catch (error) {}
    }
    $layout.removeClass('menu-collapsed');
    $('[data-sidebar-toggle]').attr('aria-label', T('recolher_menu', 'Recolher menu')).find('i').removeClass('bi-layout-sidebar-inset-reverse').addClass('bi-layout-sidebar-inset');

    $('[data-sidebar-toggle]').on('click', function () {
        var collapsed = $layout.toggleClass('menu-collapsed').hasClass('menu-collapsed');
        $(this).attr('aria-label', collapsed ? T('expandir_menu', 'Expandir menu') : T('recolher_menu', 'Recolher menu'));
        $(this).find('i').toggleClass('bi-layout-sidebar-inset bi-layout-sidebar-inset-reverse');
    });

    function expandSidebarFromItem() {
        if (!$layout.hasClass('menu-collapsed')) return;
        $layout.removeClass('menu-collapsed');
        $('[data-sidebar-toggle]').attr('aria-label', T('recolher_menu', 'Recolher menu')).find('i').removeClass('bi-layout-sidebar-inset-reverse').addClass('bi-layout-sidebar-inset');
    }

    $('[data-construction]').on('click', function () {
        expandSidebarFromItem();
        $('#constructionMessage').text($(this).data('title') + ' — ' + T('em_construcao', 'Em construção'));
        toast.show();
    });

    function normalizeMenuText(value) {
        return String(value || '').toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    $('#menuSearch').on('input', function () {
        var term = normalizeMenuText($(this).val());
        if (!term) { restoreExpandedMenus(); return; }
        $('.portal-menu > .portal-menu-item').each(function () {
            var $item = $(this);
            var $submenu = $item.next('[data-submenu]');
            if (!$submenu.length) {
                $item.toggle(!term || normalizeMenuText($item.text()).indexOf(term) !== -1);
                return;
            }
            var parentMatch = normalizeMenuText($item.text()).indexOf(term) !== -1;
            var childMatch = false;
            $submenu.find('.portal-submenu-item').each(function () {
                var match = !term || parentMatch || normalizeMenuText($(this).text()).indexOf(term) !== -1;
                $(this).toggle(match);
                childMatch = childMatch || match;
            });
            $item.toggle(!term || parentMatch || childMatch);
            $submenu.toggle(!term || parentMatch || childMatch);
            if (childMatch) {
                $submenu.parents('[data-submenu]').each(function () {
                    var $parentSubmenu = $(this), $parent = $parentSubmenu.prev('[data-menu-parent]');
                    if ($parent.length) setMenuState($parent, true, false);
                });
            }
        });
    });

    $('[data-menu-parent]').on('click', function () {
        var $item = $(this), wasCollapsed = $layout.hasClass('menu-collapsed'), $submenu = $item.next('[data-submenu]');
        expandSidebarFromItem();
        if ($submenu.length) {
            var open = wasCollapsed || !$item.hasClass('expanded');
            $item.parent().children('[data-menu-parent]').not($item).each(function () { setMenuState($(this), false, true); });
            setMenuState($item, open, true);
            writeExpandedMenus();
        }
        window.setTimeout(function () { $item[0].scrollIntoView({ block: 'nearest', inline: 'nearest' }); }, wasCollapsed ? 260 : 0);
    });
    restoreExpandedMenus();

    function activateTab(id) {
        $('.workspace-tab, .workspace-tab-pane').removeClass('active');
        $('#tab-' + id + ', #pane-' + id).addClass('active');
        $('[data-open-tab]').removeClass('active');
        $('[data-open-tab="' + id + '"]').addClass('active');
        if (window.greenPortalFooterStatus) window.greenPortalFooterStatus('ready', $('[data-open-tab="' + id + '"]').data('tab-title') || id);
    }

    function closeTab(id) {
        var $tab = $('#tab-' + id), $pane = $('#pane-' + id);
        if (!$tab.length) return;
        var wasActive = $tab.hasClass('active');
        var $next = $tab.next('.workspace-tab'), $previous = $tab.prev('.workspace-tab');
        $pane.remove();
        $tab.remove();
        if (wasActive) {
            var $replacement = $next.length ? $next : $previous;
            if ($replacement.length) activateTab(String($replacement.data('tab-target')));
        }
    }

    function openTab(button) {
        var id = $(button).data('open-tab') || button.openTabId;
        var url = $(button).data('tab-url') || button.tabUrl;
        var title = $(button).data('tab-title') || button.tabTitle;
        // A mesma tela pode estar em mais de um menu (ex.: Atividades no LAIA, LPRO e LAIS): se já houver
        // uma aba aberta com o mesmo endereço, ela é reaproveitada em vez de abrir outra.
        var normalizar = function (endereco) { try { var u = new URL(String(endereco || ''), window.location.href); return u.pathname.toLowerCase() + u.search; } catch (e) { return String(endereco || '').toLowerCase(); } };
        if (!$('#tab-' + id).length && url) {
            var alvo = normalizar(url), $existente = $('.workspace-tab').filter(function () { return normalizar($(this).attr('data-tab-url')) === alvo; }).first();
            if ($existente.length) {
                activateTab(String($existente.data('tab-target')));
                if (button && button.nodeType === 1) $(button).addClass('active');
                return;
            }
        }
        if (window.greenPortalFooterStatus) window.greenPortalFooterStatus('loading', title);
        if (!$('#tab-' + id).length) {
            var closeLabel = window.greenPortalIdioma === 'en' ? 'Close tab' : (window.greenPortalIdioma === 'es' ? 'Cerrar pestaña' : 'Fechar aba');
            var $tab = $('<div class="workspace-tab" role="tab"></div>').attr('id', 'tab-' + id).attr('data-tab-target', id).attr('data-tab-url', url);
            var $tabLabel = $('<span class="workspace-tab-label"></span>').text(title);
            var $tabClose = $('<button type="button" class="workspace-tab-close" data-close-tab aria-label=""></button>').attr('aria-label', closeLabel).append('<i class="bi bi-x-lg" aria-hidden="true"></i>');
            $tab.append($tabLabel).append($tabClose);
            var $loader = $('<div class="workspace-pane-loading"><div class="spinner-border text-success" role="status"><span class="visually-hidden">Carregando</span></div><span>Carregando tela...</span></div>');
            var $frame = $('<iframe class="workspace-frame" loading="eager"></iframe>').attr('src', url).attr('aria-label', title).attr('name', 'greenportal-' + id);
            var $pane = $('<div class="workspace-tab-pane" role="tabpanel"></div>').attr('id', 'pane-' + id).append($loader).append($frame);
            $frame.on('load', function () { try { var frameBody = $frame.contents().find('body'), frameDocument = $frame.contents(); frameBody.addClass('portal-shell theme-' + portalTheme).attr('data-transacao', String(id).replace(/^menu-/, '').toUpperCase()); $frame.contents().on('mousemove mousedown keydown click scroll touchstart', resetIdleTimer).on('input change', function () { if (window.greenPortalFooterStatus) window.greenPortalFooterStatus('editing', title); }).on('submit', function () { if (window.greenPortalFooterStatus) window.greenPortalFooterStatus('saving', title); }); if ($frame[0].contentWindow && $frame[0].contentWindow.jQuery) { $frame[0].contentWindow.greenPortalPermissions = window.greenPortalPermissions || []; $frame[0].contentWindow.greenPortalIdioma = window.greenPortalIdioma || 'pt'; $frame[0].contentWindow.jQuery(frameDocument).on('ajaxStart', function () { if (window.greenPortalFooterStatus) window.greenPortalFooterStatus('loading', title); }).on('ajaxStop', function () { if (window.greenPortalFooterStatus) window.greenPortalFooterStatus('saved', title); }).trigger('greenPortalPermissionsReady', [window.greenPortalPermissions || []]); } } catch (error) {} if (window.greenPortalFooterStatus) window.greenPortalFooterStatus('ready', title); $loader.fadeOut(120, function () { $loader.remove(); }); });
            window.setTimeout(function () { $loader.fadeOut(120, function () { $loader.remove(); }); }, 15000);
            $('#workspaceTabs').append($tab);
            $('#workspaceTabPanes').append($pane);
            $tab.on('click', function (event) { if ($(event.target).closest('[data-close-tab]').length) return; activateTab(id); });
            $tab.on('click', '[data-close-tab]', function (event) { event.preventDefault(); event.stopPropagation(); closeTab(id); });
        }
        activateTab(id);
    }

    window.greenPortalOpenTab = function (id, url, title) {
        openTab({
            openTabId: id, tabUrl: url, tabTitle: title
        });
    };

    $('[data-open-tab]').on('click', function () { expandSidebarFromItem(); openTab(this); });
    var $homeTab = $('[data-open-tab="home"]').first();
    if ($homeTab.length) openTab($homeTab[0]);
    var preferredDefault = String(window.greenPortalDefaultTransaction || 'menu-dashboard');
    var $defaultTab = $('[data-open-tab]').filter(function () { return String($(this).data('open-tab')) === preferredDefault; }).first();
    if (!$defaultTab.length) $defaultTab = $('[data-open-tab="menu-dashboard"]');
    if ($defaultTab.length && String($defaultTab.data('open-tab')) !== 'home') openTab($defaultTab[0]);
}(jQuery));
