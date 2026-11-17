<?php
declare(strict_types=1);

/*
 * Transação: Administração › Gestão de solicitações (ADMINISTRACAO_SOLICITACOES_MELHORIA).
 * ADMINISTRADOR e ADM DA EMPRESA acompanham as solicitações de melhoria da própria empresa: situação, escopo
 * (cliente ou produto), duplicidade e resposta para quem pediu (que recebe um alerta). A aba "Sinais do assistente"
 * mostra as perguntas em que o assistente não ajudou, dificuldades, erros e funcionalidades inexistentes.
 * Dados: solicitacoes_melhoria e ia_assistente_mensagens, via api/solicitacoes_melhoria.php.
 */

$solicitacoesModo = 'gestao';
require dirname(__DIR__) . '/components/solicitacoes_pagina.php';
$solicitacoesCabecalho($ui['titulo_gestao']);
?>
<body class="sol-pagina" data-modo="gestao">
<main class="sol-conteudo">
    <header class="sol-topo">
        <div>
            <h1><i class="bi bi-kanban" aria-hidden="true"></i> <?= $e($ui['titulo_gestao']) ?></h1>
            <p><?= $e($ui['sub_gestao']) ?></p>
        </div>
    </header>
    <?php if (!$solicitacoesInstalado): ?>
    <div class="alert alert-warning"><?= $e($ui['nao_instalado']) ?></div>
    <?php else: ?>
    <ul class="nav nav-tabs sol-abas" role="tablist">
        <li class="nav-item" role="presentation"><button class="nav-link active" type="button" data-bs-toggle="tab" data-bs-target="#solAbaLista" role="tab"><i class="bi bi-list-check" aria-hidden="true"></i> <?= $e($ui['abas_solicitacoes']) ?></button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" type="button" data-bs-toggle="tab" data-bs-target="#solAbaSinais" role="tab" data-sol-aba-sinais><i class="bi bi-activity" aria-hidden="true"></i> <?= $e($ui['abas_sinais']) ?></button></li>
    </ul>
    <div class="tab-content">
        <section class="tab-pane fade show active" id="solAbaLista" role="tabpanel">
            <div class="sol-totais" data-sol-totais></div>
            <div class="sol-filtros">
                <select class="form-select form-select-sm" data-sol-filtro="status">
                    <option value="ABERTAS"><?= $e($ui['abertas']) ?></option><option value=""><?= $e($ui['todas']) ?></option>
                    <?php foreach ($solicitacoesConfig['status'] as $codigo => $rotulo): ?><option value="<?= $e($codigo) ?>"><?= $e($rotulo) ?></option><?php endforeach; ?>
                </select>
                <select class="form-select form-select-sm" data-sol-filtro="categoria"><option value=""><?= $e($ui['todos_tipos']) ?></option><?php foreach ($solicitacoesConfig['categorias'] as $codigo => $rotulo): ?><option value="<?= $e($codigo) ?>"><?= $e($rotulo) ?></option><?php endforeach; ?></select>
                <input type="search" class="form-control form-control-sm" data-sol-filtro="busca" placeholder="<?= $e($ui['buscar']) ?>" aria-label="<?= $e($ui['buscar']) ?>">
            </div>
            <div class="sol-gestao">
                <div class="sol-tabela-caixa">
                    <table class="table table-sm table-hover sol-tabela">
                        <thead><tr><th>#</th><th><?= $e($ui['c_titulo']) ?></th><th><?= $e($ui['c_tipo']) ?></th><th><?= $e($ui['c_modulo']) ?></th><th><?= $e($ui['c_solicitante']) ?></th><th><?= $e($ui['c_importancia']) ?></th><th><?= $e($ui['c_situacao']) ?></th><th><?= $e($ui['c_data']) ?></th></tr></thead>
                        <tbody data-sol-tabela><tr><td colspan="8" class="sol-vazio"><?= $e($ui['carregando']) ?></td></tr></tbody>
                    </table>
                </div>
                <aside class="sol-detalhe" data-sol-detalhe><div class="sol-vazio"><?= $e($ui['g_selecione']) ?></div></aside>
            </div>
        </section>
        <section class="tab-pane fade" id="solAbaSinais" role="tabpanel">
            <p class="sol-explica"><?= $e($ui['s_explica']) ?></p>
            <div class="sol-filtros">
                <label class="form-label mb-0" for="solDias"><?= $e($ui['s_periodo']) ?></label>
                <select class="form-select form-select-sm sol-dias" id="solDias" data-sol-dias>
                    <?php foreach ([7, 30, 90] as $dias): ?><option value="<?= $dias ?>"<?= $dias === 30 ? ' selected' : '' ?>><?= $e(str_replace('{n}', (string)$dias, $ui['s_dias'])) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="sol-resumo" data-sol-resumo></div>
            <div class="sol-tabela-caixa">
                <table class="table table-sm sol-tabela">
                    <thead><tr><th><?= $e($ui['s_c_quando']) ?></th><th><?= $e($ui['s_c_usuario']) ?></th><th><?= $e($ui['s_c_tela']) ?></th><th><?= $e($ui['s_c_motivo']) ?></th><th><?= $e($ui['s_c_pergunta']) ?></th></tr></thead>
                    <tbody data-sol-sinais><tr><td colspan="5" class="sol-vazio"><?= $e($ui['carregando']) ?></td></tr></tbody>
                </table>
            </div>
        </section>
    </div>
    <?php endif; ?>
</main>
<?php $solicitacoesRodape(); ?>
