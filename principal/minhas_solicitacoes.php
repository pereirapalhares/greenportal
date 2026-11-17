<?php
declare(strict_types=1);

/*
 * Transação: Administração › Minhas solicitações (ADMINISTRACAO_MINHAS_SOLICITACOES).
 * Qualquer usuário registra e acompanha as próprias solicitações de melhoria (etapa 2 do assistente).
 * Dados: solicitacoes_melhoria, via api/solicitacoes_melhoria.php. ?id=N abre a solicitação N (link dos alertas).
 */

$solicitacoesModo = 'minhas';
require dirname(__DIR__) . '/components/solicitacoes_pagina.php';
$solicitacoesCabecalho($ui['titulo_minhas']);
?>
<body class="sol-pagina" data-modo="minhas">
<main class="sol-conteudo">
    <header class="sol-topo">
        <div>
            <h1><i class="bi bi-lightbulb" aria-hidden="true"></i> <?= $e($ui['titulo_minhas']) ?></h1>
            <p><?= $e($ui['sub_minhas']) ?></p>
        </div>
        <?php if ($solicitacoesInstalado): ?><button type="button" class="btn btn-success btn-sm" data-sol-nova><i class="bi bi-plus-lg" aria-hidden="true"></i> <?= $e($ui['nova']) ?></button><?php endif; ?>
    </header>
    <?php if (!$solicitacoesInstalado): ?>
    <div class="alert alert-warning"><?= $e($ui['nao_instalado']) ?></div>
    <?php else: ?>
    <form class="sol-form-nova" data-sol-form-nova hidden>
        <div class="row g-2">
            <div class="col-12"><label class="form-label" for="solTitulo"><?= $e($ui['f_titulo']) ?></label><input class="form-control form-control-sm" id="solTitulo" name="titulo" maxlength="200" required placeholder="<?= $e($ui['f_titulo_ph']) ?>"></div>
            <div class="col-12"><label class="form-label" for="solDescricao"><?= $e($ui['f_descricao']) ?></label><textarea class="form-control form-control-sm" id="solDescricao" name="descricao" rows="4" maxlength="4000" required placeholder="<?= $e($ui['f_descricao_ph']) ?>"></textarea></div>
            <div class="col-12"><label class="form-label" for="solBeneficio"><?= $e($ui['f_beneficio']) ?></label><input class="form-control form-control-sm" id="solBeneficio" name="beneficio" maxlength="500"></div>
            <div class="col-md-4"><label class="form-label" for="solModulo"><?= $e($ui['f_modulo']) ?></label><select class="form-select form-select-sm" id="solModulo" name="modulo"><option value=""><?= $e($ui['nao_sei']) ?></option><?php foreach ($solicitacoesConfig['modulos'] as $modulo): ?><option value="<?= $e($modulo) ?>"><?= $e($modulo) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label" for="solCategoria"><?= $e($ui['f_categoria']) ?></label><select class="form-select form-select-sm" id="solCategoria" name="categoria"><?php foreach ($solicitacoesConfig['categorias'] as $codigo => $rotulo): ?><option value="<?= $e($codigo) ?>"<?= $codigo === 'MELHORIA' ? ' selected' : '' ?>><?= $e($rotulo) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label" for="solPrioridade"><?= $e($ui['f_prioridade']) ?></label><select class="form-select form-select-sm" id="solPrioridade" name="prioridade_usuario"><option value="BAIXA"><?= $e($ui['baixa']) ?></option><option value="MEDIA" selected><?= $e($ui['media']) ?></option><option value="ALTA"><?= $e($ui['alta']) ?></option></select></div>
        </div>
        <div class="sol-form-botoes"><button type="button" class="btn btn-outline-secondary btn-sm" data-sol-cancelar><?= $e($ui['cancelar']) ?></button><button type="submit" class="btn btn-success btn-sm"><?= $e($ui['enviar']) ?></button></div>
    </form>
    <div class="sol-mensagem" data-sol-mensagem role="status" aria-live="polite"></div>
    <div class="sol-lista" data-sol-lista><div class="sol-vazio"><?= $e($ui['carregando']) ?></div></div>
    <?php endif; ?>
</main>
<?php $solicitacoesRodape(); ?>
