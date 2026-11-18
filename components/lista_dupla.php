<?php
declare(strict_types=1);

/**
 * Lista dupla (dual listbox / transfer list) no padrão do portal — migração 388 (17/11/2026).
 * Esquerda: itens DISPONÍVEIS; direita: itens SELECIONADOS (vinculados). No meio, › inclui e ‹ retira os marcados;
 * duplo clique numa linha move a linha. Cada lado tem caixa de filtro (Buscar), marcação por linha, "marcar a página" no
 * cabeçalho e paginação. Cada movimento grava na hora (sem Salvar). Barra superior padrão: Recarregar + Ajuda da transação.
 *
 * Os dois lados são carregados pelo servidor (POST na própria página, JSON) — serve para listas grandes (ex.: 38 mil leis):
 *   action=ld_listar  lado=esquerda|direita, busca, pagina, filtros (JSON dos filtros extras)
 *                     → {ok, itens: [{id, cells: {COL: texto}, bloqueado?: 'motivo', detalhe?: 'linha menor sob a 1ª coluna'}], total: n|null, por_pagina: n}
 *   action=ld_mover   para=direita|esquerda, ids (JSON) → {ok, message, resumo?}
 * (a página responde essas ações; LegislacaoPopup/LegislacaoAssociacoes::csrf é enviado em 'csrf' quando informado).
 *
 * Uso:
 *   greenPortalListaDuplaHead();                       // no <head>, depois do greenPortalGridLocalHead()
 *   greenPortalListaDupla([
 *     'id' => 'ldFilhas', 'transaction' => 'manutencaolegal_legislacao',
 *     'colunas' => ['ROTULO' => ['label' => 'Legislação', 'width' => '40%'], 'CODIGO' => ['label' => 'Código']],
 *     'titulo_esquerda' => 'Leis disponíveis', 'titulo_direita' => 'Leis filhas',
 *     'filtros_esquerda' => '<select data-ld-filtro="tipo">...</select>',    // opcional: filtros extras do lado esquerdo
 *     'editavel' => true, 'csrf' => '...', 'url' => 'pagina.php?x=1',       // url: endpoint (padrão: a própria página)
 *     'vazio_esquerda' => '...', 'vazio_direita' => '...',
 *   ]);
 *   greenPortalListaDuplaScripts();                    // no fim do <body>, depois do greenPortalGridLocalScripts()
 * Eventos: 'lista-dupla-movida' (detail: resposta do servidor) na section, depois de cada movimento.
 */
require_once __DIR__ . '/botoes_crud.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';

if (!function_exists('greenPortalListaDupla')) {
    function greenPortalListaDuplaHead(): void
    {
        echo '<style>'
            . 'html,body{height:100%!important;overflow:hidden!important}'
            // a rolagem fica dentro de cada lista (cabeçalho fixo), nunca no popup/página
            . '.setup-fragment.lista-dupla{height:auto!important;flex:1 1 0!important;min-height:0!important;display:flex;flex-direction:column;gap:6px;overflow:hidden!important;padding:0!important;background:transparent}.lista-dupla>.crud-toolbar{flex:0 0 auto}'
            . '.lista-dupla .ld-corpo{flex:1 1 auto;min-height:0;display:grid;grid-template-columns:minmax(0,1fr) 44px minmax(0,1fr);grid-template-rows:minmax(0,1fr);gap:8px}'
            . '.lista-dupla .ld-lado{display:flex;flex-direction:column;min-height:0;height:100%;border:1px solid #dce9e2;border-radius:10px;background:#fff;overflow:hidden}'
            . '.lista-dupla .ld-cab{flex:0 0 auto;display:flex;align-items:center;gap:6px;flex-wrap:wrap;padding:6px 8px;border-bottom:1px solid #e6efea;background:#f6faf8}'
            . '.lista-dupla .ld-cab h3{font-size:12px;font-weight:700;margin:0;color:#1f4d3a;display:flex;align-items:center;gap:6px;white-space:nowrap;flex:0 0 auto}'
            . '.lista-dupla .ld-n{background:#e6f1eb;color:#1f4d3a;border-radius:10px;padding:0 7px;font-size:11px;font-weight:600}'
            . '.lista-dupla .ld-cab .crud-search{margin:0 0 0 auto;min-width:140px;flex:1 1 160px;max-width:280px;position:relative}.lista-dupla .ld-cab .crud-search input{height:28px;font-size:11px}'
            . '.lista-dupla .ld-cab .ld-extras{display:flex;gap:4px;flex:0 1 auto;min-width:0}.lista-dupla .ld-cab .ld-extras select,.lista-dupla .ld-cab .ld-extras input{font-size:11px;height:28px;padding:2px 24px 2px 6px;width:150px;min-width:0;flex:0 1 150px}'
            . '.lista-dupla .menu-grid-scroll{flex:1 1 auto;min-height:0;overflow:auto!important;max-height:none!important;height:auto}'
            . '.lista-dupla table.menu-batch-grid{table-layout:fixed;width:100%!important;min-width:0!important;max-width:100%!important;font-size:11px;margin:0}'
            . '.lista-dupla table.menu-batch-grid th{position:sticky;top:0;z-index:1}'
            . '.lista-dupla table.menu-batch-grid td{word-wrap:break-word;vertical-align:top}'
            . '.lista-dupla th.ld-marca,.lista-dupla td.ld-marca{width:34px!important;min-width:34px!important;max-width:34px!important;text-align:center;padding-left:4px!important;padding-right:4px!important}.lista-dupla table.menu-batch-grid th,.lista-dupla table.menu-batch-grid td{min-width:0!important}'
            . '.lista-dupla tbody tr{cursor:pointer}.lista-dupla tbody tr.ld-marcada{background:#eaf6ef}.lista-dupla tbody tr.ld-bloqueada{color:#8aa197;cursor:default}.lista-dupla .ld-detalhe{display:block;font-size:10px;color:#5f7d70;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
            . '.lista-dupla .ld-vazio td{padding:22px!important;text-align:center;color:#71877d;cursor:default}.lista-dupla .ld-lado{position:relative}.lista-dupla .ld-carregando{position:absolute;left:0;right:0;z-index:3;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.75);color:#1f4d3a;font-size:12px;font-weight:600;pointer-events:none}'
            . '.lista-dupla .ld-meio{display:flex;flex-direction:column;justify-content:center;gap:8px}.lista-dupla .ld-meio .btn{padding:4px 0;font-size:16px;line-height:1}'
            . '.lista-dupla .grid-pagination{flex:0 0 auto;border-top:1px solid #e6efea;padding:4px 8px;display:flex;align-items:center;gap:6px;justify-content:flex-end;margin:0}'
            . '@media (max-width:760px){.lista-dupla .ld-corpo{grid-template-columns:1fr;grid-template-rows:minmax(0,1fr) auto minmax(0,1fr)}.lista-dupla .ld-meio{flex-direction:row;justify-content:center}.lista-dupla .ld-meio .bi-chevron-right:before{content:"\\f282"}.lista-dupla .ld-meio .bi-chevron-left:before{content:"\\f286"}}'
            . '</style>';
    }

    function greenPortalListaDupla(array $cfg): void
    {
        $e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $t = static fn(string $c, array $p): string => IdiomaService::t($c, $p);
        $id = (string)($cfg['id'] ?? 'listaDupla');
        $colunas = (array)($cfg['colunas'] ?? []);
        $editavel = !empty($cfg['editavel']);
        $buscar = $t('comum.busca.grid', ['pt' => 'Buscar no grid', 'en' => 'Search the grid', 'es' => 'Buscar en la cuadrícula']);
        $conf = ['url' => (string)($cfg['url'] ?? ''), 'csrf' => (string)($cfg['csrf'] ?? ''), 'editavel' => $editavel, 'colunas' => array_keys($colunas),
            'vazio' => ['esquerda' => (string)($cfg['vazio_esquerda'] ?? $t('comum.lista_dupla.vazio', ['pt' => 'Nenhum item.', 'en' => 'No items.', 'es' => 'Ningún elemento.'])), 'direita' => (string)($cfg['vazio_direita'] ?? $t('comum.lista_dupla.vazio', ['pt' => 'Nenhum item.', 'en' => 'No items.', 'es' => 'Ningún elemento.']))]];
        echo '<section class="setup-fragment menu-grid-fragment lista-dupla grid-ready" id="' . $e($id) . '" data-lista-dupla="' . $e(json_encode($conf, JSON_UNESCAPED_UNICODE)) . '">';
        echo '<div class="crud-toolbar menu-grid-toolbar">';
        greenPortalCrudButtons(['transaction' => (string)($cfg['transaction'] ?? ''), 'save' => false, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => false, 'columns' => false,
            'extras' => (string)($cfg['extras'] ?? '')]);
        echo '<span class="small text-muted ms-2">' . $e($editavel ? $t('comum.lista_dupla.dica', ['pt' => 'Marque e use › para incluir ou ‹ para retirar (ou dê duplo clique na linha).', 'en' => 'Tick and use › to add or ‹ to remove (or double-click the row).', 'es' => 'Marque y use › para incluir o ‹ para quitar (o doble clic en la fila).']) : $t('comum.somente_consulta', ['pt' => 'Somente consulta.', 'en' => 'Read only.', 'es' => 'Solo consulta.'])) . '</span>';
        echo '</div><div class="ld-corpo">';
        foreach (['esquerda', 'direita'] as $lado) {
            if ($lado === 'direita') {
                echo '<div class="ld-meio">'
                    . '<button type="button" class="btn btn-sm btn-success" data-ld-mover="direita" disabled title="' . $e($t('comum.lista_dupla.incluir', ['pt' => 'Incluir os marcados', 'en' => 'Add ticked', 'es' => 'Incluir los marcados'])) . '"><i class="bi bi-chevron-right"></i></button>'
                    . '<button type="button" class="btn btn-sm btn-outline-danger" data-ld-mover="esquerda" disabled title="' . $e($t('comum.lista_dupla.retirar', ['pt' => 'Retirar os marcados', 'en' => 'Remove ticked', 'es' => 'Quitar los marcados'])) . '"><i class="bi bi-chevron-left"></i></button></div>';
            }
            echo '<div class="ld-lado" data-ld-lado="' . $lado . '"><div class="ld-cab"><h3>' . $e($cfg['titulo_' . $lado] ?? ($lado === 'esquerda' ? $t('comum.lista_dupla.disponiveis', ['pt' => 'Disponíveis', 'en' => 'Available', 'es' => 'Disponibles']) : $t('comum.lista_dupla.selecionados', ['pt' => 'Selecionados', 'en' => 'Selected', 'es' => 'Seleccionados']))) . ' <span class="ld-n" data-ld-total>…</span></h3>'
                . ($lado === 'esquerda' && ($cfg['filtros_esquerda'] ?? '') !== '' ? '<div class="ld-extras">' . $cfg['filtros_esquerda'] . '</div>' : '')
                . '<div class="crud-search"><i class="bi bi-search"></i><input type="search" data-ld-busca placeholder="' . $e($buscar) . '" aria-label="' . $e($buscar) . '"></div></div>'
                . '<div class="menu-grid-scroll"><table class="table table-sm menu-batch-grid"><thead><tr><th class="ld-marca">' . ($editavel ? '<input class="form-check-input" type="checkbox" data-ld-marcar-pagina title="' . $e($t('comum.lista_dupla.marcar_pagina', ['pt' => 'Marcar a página', 'en' => 'Tick the page', 'es' => 'Marcar la página'])) . '">' : '') . '</th>';
            foreach ($colunas as $k => $c) echo '<th data-column="' . $e($k) . '"' . (!empty($c['width']) ? ' style="width:' . $e($c['width']) . '"' : '') . '><div class="column-heading"><span>' . $e($c['label'] ?? $k) . '</span></div></th>';
            echo '</tr></thead><tbody data-ld-corpo></tbody></table></div>'
                . '<nav class="grid-pagination"><button type="button" class="btn btn-sm btn-outline-secondary" data-ld-pag="-1" title="' . $e($t('comum.paginacao.anterior', ['pt' => 'Anterior', 'en' => 'Previous', 'es' => 'Anterior'])) . '"><i class="bi bi-chevron-left"></i></button><span class="grid-page-status" data-ld-status></span><button type="button" class="btn btn-sm btn-outline-secondary" data-ld-pag="1" title="' . $e($t('comum.paginacao.proxima', ['pt' => 'Próxima', 'en' => 'Next', 'es' => 'Siguiente'])) . '"><i class="bi bi-chevron-right"></i></button></nav></div>';
        }
        echo '</div></section>';
    }

    function greenPortalListaDuplaScripts(): void
    {
        // textos do JavaScript (greenT) nos três idiomas
        echo IdiomaService::script(['comum'], greenPortalListaDuplaTextos());
        echo '<script src="../assets/js/lista-dupla.js?v=5"></script>';
    }

    /** Textos do JavaScript da lista dupla (greenT), nos três idiomas — também usados pelos popups da Legislação. */
    function greenPortalListaDuplaTextos(): array
    {
        return [
            'comum.relacionamentos.carregando' => ['pt' => 'Carregando...', 'en' => 'Loading...', 'es' => 'Cargando...'],
            'comum.lista_dupla.erro_carregar' => ['pt' => 'Não foi possível carregar.', 'en' => 'Could not load.', 'es' => 'No fue posible cargar.'],
            'comum.msg.nao_concluido' => ['pt' => 'Não foi possível concluir.', 'en' => 'Could not complete the operation.', 'es' => 'No fue posible concluir la operación.'],
            'comum.msg.atencao' => ['pt' => 'Atenção', 'en' => 'Attention', 'es' => 'Atención'],
            'comum.paginacao.registros' => ['pt' => 'registros', 'en' => 'records', 'es' => 'registros'],
        ];
    }
}
