(function (window) {
    'use strict';
    function openGuide(event) {
        var button = event.target.closest ? event.target.closest('[data-open-user-guide]') : null;
        if (!button) return;
        event.preventDefault();
        var host = window.top || window;
        var base = String(host.greenPortalBaseUrl || '');
        var url = new URL(base + '/principal/guia_usuario.php', window.location.href).toString();
        var title = String(button.getAttribute('data-guide-title') || 'Guia do usuário');
        try {
            var old = host.document.getElementById('greenPortalUserGuideDrawer');
            if (old) old.remove();
            var css = host.document.getElementById('greenPortalUserGuideDrawerCss');
            if (!css) {
                css = host.document.createElement('style');
                css.id = 'greenPortalUserGuideDrawerCss';
                css.textContent = '#greenPortalUserGuideDrawer{position:fixed;top:0;right:0;bottom:0;width:min(760px,100vw);z-index:2147483000;background:var(--gp-surface,#fff);border-left:1px solid var(--gp-border,#dce9e2);box-shadow:-8px 0 28px rgba(20,60,45,.2);transition:width .18s ease,height .18s ease,top .18s ease,bottom .18s ease}#greenPortalUserGuideDrawer iframe{width:100%;height:100%;display:block;border:0}#greenPortalUserGuideDrawer .guide-drawer-minimize{position:absolute;right:42px;top:9px;z-index:2;border:0;border-radius:5px;background:transparent;color:var(--gp-muted,#71808c);padding:3px 7px;cursor:pointer;font-size:16px;line-height:1}#greenPortalUserGuideDrawer .guide-drawer-minimize:hover{background:var(--gp-primary-soft,#e8f5ee);color:var(--gp-primary-dark,#185640)}#greenPortalUserGuideDrawer.is-minimized{top:auto;bottom:20px;width:270px;height:48px;border:1px solid var(--gp-border,#dce9e2);border-radius:10px;box-shadow:var(--gp-shadow-md,0 10px 28px rgba(22,72,55,.18))}#greenPortalUserGuideDrawer.is-minimized iframe{display:none}';
                host.document.head.appendChild(css);
            }
            var drawer = host.document.createElement('aside');
            drawer.id = 'greenPortalUserGuideDrawer';
            drawer.setAttribute('aria-label', title);
            var frame = host.document.createElement('iframe');
            frame.title = title;
            frame.src = url;
            var minimize = host.document.createElement('button');
            minimize.type = 'button';
            minimize.className = 'guide-drawer-minimize';
            minimize.textContent = '−';
            minimize.setAttribute('aria-label', 'Minimizar guia');
            minimize.title = 'Minimizar guia';
            minimize.onclick = function () {
                var small = drawer.classList.toggle('is-minimized');
                minimize.textContent = small ? '□' : '−';
                minimize.setAttribute('aria-label', small ? 'Restaurar guia' : 'Minimizar guia');
                minimize.title = small ? 'Restaurar guia' : 'Minimizar guia';
            };
            drawer.appendChild(minimize);
            drawer.appendChild(frame);
            host.document.body.appendChild(drawer);
        } catch (error) {
            window.open(url, '_blank', 'noopener');
        }
    }
    function bind() {
        if (!window.document || window.document.documentElement.dataset.guiaUsuarioBound === '1') return;
        window.document.documentElement.dataset.guiaUsuarioBound = '1';
        window.document.addEventListener('click', openGuide);
    }
    if (window.document.readyState === 'loading') window.document.addEventListener('DOMContentLoaded', bind); else bind();
}(window));
