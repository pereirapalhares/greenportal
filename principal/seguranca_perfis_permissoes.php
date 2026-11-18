<?php
declare(strict_types=1);
/*
 * Segurança › Perfis e Permissões (migração 395, pedido do usuário de 18/11/2026): junta as telas Perfis e Permissões
 * numa só, em abas. Cada aba mostra a tela que já existia (seguranca_perfil.php e seguranca_permissoes.php), sem mudar
 * as regras de gravação delas.
 * Acesso: permissão própria do menu SEGURANCA_PERFIS_PERMISSOES. A aba Perfis continua só para o ADMINISTRADOR (como a
 * tela antiga); a aba Permissões vale para o ADMINISTRADOR e o ADM DA EMPRESA (como a tela antiga).
 * Atalho: o perfil selecionado na aba Perfis abre filtrado na aba Permissões (?perfil=).
 */
require_once dirname(__DIR__) . '/classes/AdministracaoTextos.php';
require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const MENU_PERFIS_PERMISSOES = 'SEGURANCA_PERFIS_PERMISSOES';
TransactionSecurity::connect(MENU_PERFIS_PERMISSOES);

$perfilLogado = strtoupper(trim((string)($_SESSION['usuario']['codigo_perfil'] ?? '')));
$verPerfis = $perfilLogado === 'ADMINISTRADOR';
$verPermissoes = in_array($perfilLogado, ['ADMINISTRADOR', 'ADM_DA_EMPRESA'], true);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$perfilInicial = max(0, (int)($_GET['perfil'] ?? 0));
$abaInicial = (string)($_GET['aba'] ?? '');
if (!in_array($abaInicial, ['perfis', 'permissoes'], true) || ($abaInicial === 'perfis' && !$verPerfis) || ($abaInicial === 'permissoes' && !$verPermissoes)) $abaInicial = $verPerfis ? 'perfis' : 'permissoes';
if ($perfilInicial > 0 && $verPermissoes) $abaInicial = 'permissoes';
$srcPermissoes = 'seguranca_permissoes.php' . ($perfilInicial > 0 ? '?perfil=' . $perfilInicial : '');
?><!DOCTYPE html>
<html lang="<?= $e(['en' => 'en', 'es' => 'es'][IdiomaService::idioma()] ?? 'pt-BR') ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= adm_h('Perfis e Permissões') ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet"><link href="../assets/css/app.css" rel="stylesheet"><link href="../assets/css/main.css?v=18" rel="stylesheet"><link href="../assets/css/design-system.css?v=3" rel="stylesheet">
<style>
html,body{height:100%;margin:0;background:transparent}
.pp-pagina{display:flex;flex-direction:column;height:100vh;padding:8px 10px 0}
.pp-abas{display:flex;gap:4px;border-bottom:1px solid var(--gp-border,#d9e4df);flex:0 0 auto}
.pp-aba{white-space:nowrap;max-width:none;flex:0 0 auto;border:1px solid transparent;border-bottom:0;background:none;padding:6px 14px;font-size:13px;color:var(--gp-muted,#6b8279);border-radius:6px 6px 0 0;cursor:pointer}
.pp-aba:hover{color:var(--gp-primary,#2f7d5b)}
.pp-aba.active{background:#fff;border-color:var(--gp-border,#d9e4df);color:var(--gp-primary,#2f7d5b);font-weight:600;margin-bottom:-1px}
.pp-aba .pp-perfil{font-weight:400;font-size:12px;margin-left:4px;color:var(--gp-muted,#6b8279)}
.pp-painel{flex:1 1 auto;min-height:0}
.pp-painel[hidden]{display:none}
.pp-painel iframe{width:100%;height:100%;border:0;display:block;background:transparent}
</style>
</head>
<body>
<main class="pp-pagina" data-perfis-permissoes data-aba-inicial="<?= $e($abaInicial) ?>">
  <nav class="pp-abas" role="tablist" aria-label="<?= adm_a('Perfis e Permissões') ?>">
    <?php if ($verPerfis): ?><button type="button" role="tab" class="pp-aba" data-pp-aba="perfis"><i class="bi bi-person-badge me-1"></i><?= adm_h('Perfis') ?></button><?php endif; ?>
    <?php if ($verPermissoes): ?><button type="button" role="tab" class="pp-aba" data-pp-aba="permissoes" title="<?= adm_a('Escolha um perfil na aba Perfis para abrir as permissões dele') ?>"><i class="bi bi-key me-1"></i><?= adm_h('Permissões') ?><span class="pp-perfil" data-pp-perfil-nome></span></button><?php endif; ?>
  </nav>
  <?php if ($verPerfis): ?><section class="pp-painel" data-pp-painel="perfis" hidden><iframe title="<?= adm_a('Perfis') ?>" data-pp-frame="perfis" data-src="seguranca_perfil.php"></iframe></section><?php endif; ?>
  <?php if ($verPermissoes): ?><section class="pp-painel" data-pp-painel="permissoes" hidden><iframe title="<?= adm_a('Permissões') ?>" data-pp-frame="permissoes" data-src="<?= $e($srcPermissoes) ?>"></iframe></section><?php endif; ?>
</main>
<script>
(function () {
    'use strict';
    var raiz = document.querySelector('[data-perfis-permissoes]');
    if (!raiz) return;
    var chave = 'gp.perfisPermissoes.aba';
    function frame(id) { return raiz.querySelector('[data-pp-frame="' + id + '"]'); }
    function carregar(id) { var f = frame(id); if (f && !f.getAttribute('src')) f.setAttribute('src', f.getAttribute('data-src')); }
    // Perfil selecionado no grid da aba Perfis (mesma origem): linha tr.selected com data-key numérico
    function perfilSelecionado() {
        var f = frame('perfis'), d;
        try { d = f && f.contentDocument; } catch (e) { d = null; }
        var tr = d ? d.querySelector('tbody tr.selected[data-key]') : null;
        if (!tr || !/^\d+$/.test(String(tr.getAttribute('data-key')))) return null;
        var campo = tr.querySelector('input[name$="[nome]"]') || tr.querySelector('input[name$="[codigo]"]');
        return { id: tr.getAttribute('data-key'), nome: campo ? String(campo.value || '').trim() : '' };
    }
    function perfilDaUrl(src) { var m = /[?&]perfil=(\d+)/.exec(String(src || '')); return m ? m[1] : ''; }
    function abrir(id) {
        raiz.querySelectorAll('[data-pp-aba]').forEach(function (b) { var ativo = b.getAttribute('data-pp-aba') === id; b.classList.toggle('active', ativo); b.setAttribute('aria-selected', ativo ? 'true' : 'false'); });
        raiz.querySelectorAll('[data-pp-painel]').forEach(function (p) { p.hidden = p.getAttribute('data-pp-painel') !== id; });
        if (id === 'permissoes') {
            var p = perfilSelecionado(), f = frame('permissoes');
            if (p && f) {
                var atual = perfilDaUrl(f.getAttribute('src') || f.getAttribute('data-src'));
                if (atual !== p.id) f.setAttribute('src', 'seguranca_permissoes.php?perfil=' + encodeURIComponent(p.id));
                var rotulo = raiz.querySelector('[data-pp-perfil-nome]');
                if (rotulo) rotulo.textContent = p.nome ? '· ' + p.nome : '';
            }
        }
        carregar(id);
        try { sessionStorage.setItem(chave, id); } catch (e) {}
    }
    raiz.addEventListener('click', function (ev) { var b = ev.target.closest('[data-pp-aba]'); if (b) abrir(b.getAttribute('data-pp-aba')); });
    var inicial = raiz.getAttribute('data-aba-inicial') || 'perfis';
    try { var salva = sessionStorage.getItem(chave); if (salva && raiz.querySelector('[data-pp-aba="' + salva + '"]') && !/[?&](perfil|aba)=/.test(location.search)) inicial = salva; } catch (e) {}
    if (!raiz.querySelector('[data-pp-aba="' + inicial + '"]')) { var primeira = raiz.querySelector('[data-pp-aba]'); inicial = primeira ? primeira.getAttribute('data-pp-aba') : ''; }
    if (inicial) abrir(inicial);
    // a aba Perfis carrega junto (em segundo plano) para o atalho já achar o perfil selecionado
    carregar('perfis');
}());
</script>
</body>
</html>
