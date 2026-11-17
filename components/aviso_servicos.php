<?php
declare(strict_types=1);

/*
 * Aviso de serviços parados no portal (migração 353; decisão do usuário de 11/11/2026).
 * Ao abrir o portal, o ADMINISTRADOR vê um quadro no canto da tela quando há problema nos serviços (sp_orq_saude):
 * trilha sem sinal de vida, tarefa desativada, serviço ou empresa suspensa, trilha em SIMULAR, vigia parado.
 * Não depende de nenhuma tarefa do Windows: se tudo estiver parado (até o vigia e o e-mail), o aviso aparece mesmo assim.
 * Fechar esconde só até mudar a lista de problemas (sessionStorage). Erro aqui nunca impede o portal de abrir.
 */
function greenPortalAvisoServicos(array $config, array $usuario, string $baseUrl): void
{
    if (strtoupper((string)($usuario['codigo_perfil'] ?? '')) !== 'ADMINISTRADOR') return;
    try {
        require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'GreenPortalDatabase.php';
        $config['DB_CONNECT_TIMEOUT'] = '3';
        $banco = preg_replace('/[^A-Za-z0-9_]/', '', (string)($config['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'));
        $u = GreenPortalDatabase::connect($config, $banco, 'GUNIVERSAL');
        $problemas = GreenPortalDatabase::call($u, 'CALL sp_orq_saude()');
    } catch (Throwable $e) {
        error_log('[aviso serviços] ' . $e->getMessage());
        return;
    }
    if (!$problemas) return;
    $h = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $erros = count(array_filter($problemas, static fn($p) => ($p['gravidade'] ?? '') === 'ERRO'));
    $assinatura = substr(sha1(implode('|', array_column($problemas, 'chave'))), 0, 12);
    $url = $baseUrl . '/principal/servicos.php';
    ?>
<div class="gp-aviso-servicos" id="gpAvisoServicos" role="alert" data-assinatura="<?= $h($assinatura) ?>" hidden
     style="position:fixed;left:16px;bottom:64px;z-index:1080;max-width:440px;background:var(--bs-body-bg,#fff);border:1px solid <?= $erros ? '#dc3545' : '#ffc107' ?>;border-left-width:5px;border-radius:.5rem;box-shadow:0 8px 24px rgba(0,0,0,.18);padding:.75rem .9rem;font-size:.85rem">
    <div style="display:flex;gap:.5rem;align-items:flex-start">
        <i class="bi bi-exclamation-octagon-fill" style="color:<?= $erros ? '#dc3545' : '#cc9a06' ?>;font-size:1.1rem" aria-hidden="true"></i>
        <div style="flex:1">
            <strong><?= $erros ? 'Serviços com problema' : 'Atenção nos serviços' ?> (<?= count($problemas) ?>)</strong>
            <ul style="margin:.35rem 0 .5rem;padding-left:1rem">
                <?php foreach (array_slice($problemas, 0, 4) as $p): ?><li><?= $h($p['titulo']) ?></li><?php endforeach; ?>
                <?php if (count($problemas) > 4): ?><li>e mais <?= count($problemas) - 4 ?></li><?php endif; ?>
            </ul>
            <button type="button" class="btn btn-sm btn-outline-danger" data-aviso-abrir>Abrir Administração › Serviços</button>
        </div>
        <button type="button" class="btn-close" aria-label="Fechar" data-aviso-fechar style="font-size:.7rem"></button>
    </div>
</div>
<script>
(function(){
    var box=document.getElementById('gpAvisoServicos'); if(!box)return;
    var chave='gpAvisoServicosFechado', assinatura=box.getAttribute('data-assinatura');
    try { if (sessionStorage.getItem(chave) === assinatura) return; } catch (e) {}
    box.hidden=false;
    box.querySelector('[data-aviso-fechar]').addEventListener('click',function(){ box.hidden=true; try { sessionStorage.setItem(chave, assinatura); } catch (e) {} });
    box.querySelector('[data-aviso-abrir]').addEventListener('click',function(){
        var url=<?= json_encode($url, JSON_UNESCAPED_SLASHES) ?>;
        // Mesmo id de aba que o link dos alertas (alertas.js), para não abrir a tela duas vezes
        var u=new URL(url, window.location.href), id='link-'+(u.pathname+u.search).replace(/[^a-z0-9]+/gi,'-').replace(/^-+|-+$/g,'').toLowerCase().slice(-80);
        if (window.greenPortalOpenTab) window.greenPortalOpenTab(id, url, 'Serviços'); else window.open(url, '_blank');
    });
})();
</script>
<?php
}
