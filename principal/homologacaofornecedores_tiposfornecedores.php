<?php
declare(strict_types=1);

/*
 * Homologação de Fornecedores › Tipos de Fornecedores
 * Transação: homologacaofornecedores_tiposfornecedores — menu/permissão HOMOLOGACAO_TIPOS_FORNECEDORES.
 * Legado: hpr_configuracoes, transação foTiposdeFornecedor (codtransacao 138), tabela fo_tipofornecedores
 * (DSCTIPO, NECESSITA_AUDITORIA), com as abas Critérios (139 + 140 Nível de exigência) e Palavras chave (141).
 *
 * Leitura: vw_homologacao_tipos_fornecedores_cadastro_grid (sp_crud_select / sp_crud_count).
 * Gravação: sp_crud_insert / sp_crud_update / sp_crud_delete na tabela fo_tipofornecedores.
 * Exclusão: tipo usado por fornecedores ou documentos é bloqueado no banco (trg_fo_tipofornecedores_antes_excluir ->
 * sp_fo_tipofornecedores_validar_exclusao); os critérios e palavras-chave do tipo saem junto. Objetos: migração 292 (via 248).
 * Vínculos (botões Critérios / Palavras-chave): homologacaofornecedores_tiposfornecedores_vinculos.php,
 * duas listas (disponíveis / vinculados) e nível de exigência por critério.
 * O que cada perfil pode fazer vem de Segurança › Permissões.
 */

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
// Textos acentuados escritos como \u.... (não sofrem a correção automática de acentuação do portal).
$u = static fn(string $s): string => (string)json_decode('"' . $s . '"');
$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$botao = static function (string $relacao, string $icone, string $titulo) use ($h): string {
    return '<button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child'
        . ' data-context-id="tipofornecedor-' . $h(strtolower($relacao)) . '"'
        . ' data-context-url="/principal/homologacaofornecedores_tiposfornecedores_vinculos.php?relacao=' . $h($relacao) . '"'
        . ' data-context-fields="CODTIPOFORNECEDOR" data-context-title="' . $h($titulo) . '" disabled'
        . ' title="' . $h($titulo) . '" aria-label="' . $h($titulo) . '"><i class="bi ' . $h($icone) . '"></i></button>';
};
$extras = $botao('CRITERIOS', 'bi-list-check', IdiomaService::t('homologacaofornecedores_tiposfornecedores.botao.criterios', ['pt' => $u('Critérios e nível de exigência'), 'en' => 'Criteria and requirement level', 'es' => 'Criterios y nivel de exigencia']))
    . $botao('PALAVRASCHAVE', 'bi-tags', IdiomaService::t('homologacaofornecedores_tiposfornecedores.botao.palavraschave', ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave']));

$simNao = ['Sim' => ['pt' => 'Sim', 'en' => 'Yes', 'es' => $u('Sí')], $u('Não') => ['pt' => $u('Não'), 'en' => 'No', 'es' => 'No']];

ManutencaoLegalCadastroGrid::render([
    'menu' => 'HOMOLOGACAO_TIPOS_FORNECEDORES',
    'title' => ['pt' => 'Tipos de Fornecedores', 'en' => 'Supplier types', 'es' => 'Tipos de proveedores'],
    'transaction' => 'homologacaofornecedores_tiposfornecedores', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT',
    'fragment_class' => 'homologacao-tipos-fornecedores-grid-fragment',
    'table' => 'fo_tipofornecedores', 'view' => 'vw_homologacao_tipos_fornecedores_cadastro_grid',
    'primary' => 'CODTIPOFORNECEDOR', 'primary_auto' => true, 'primary_editable' => false,
    'default_sort' => ['column' => 'DSCTIPO', 'direction' => 'asc'],
    'extras' => $extras,
    'fields' => [
        'CODTIPOFORNECEDOR' => ['label' => ['pt' => $u('Código'), 'en' => 'Code', 'es' => $u('Código')], 'hidden' => true, 'readonly' => true, 'filter' => 'none', 'width' => 0],
        'DSCTIPO' => ['label' => ['pt' => 'Tipo de fornecedor', 'en' => 'Supplier type', 'es' => 'Tipo de proveedor'], 'type' => 'textarea', 'required' => true, 'filter' => 'text', 'width' => 460],
        'NECESSITA_AUDITORIA' => ['label' => ['pt' => 'Necessita de auditoria?', 'en' => 'Requires audit?', 'es' => $u('¿Necesita auditoría?')], 'required' => true, 'options' => $simNao, 'filter' => 'select', 'width' => 160],
        'QTD_CRITERIOS' => ['label' => ['pt' => $u('Critérios'), 'en' => 'Criteria', 'es' => 'Criterios'], 'readonly' => true, 'filter' => 'none', 'width' => 90],
        'QTD_OBRIGATORIOS' => ['label' => ['pt' => $u('Obrigatórios'), 'en' => 'Mandatory', 'es' => 'Obligatorios'], 'readonly' => true, 'filter' => 'none', 'width' => 100],
        'QTD_PALAVRASCHAVE' => ['label' => ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave'], 'readonly' => true, 'filter' => 'none', 'width' => 110],
        'QTD_FORNECEDORES' => ['label' => ['pt' => 'Fornecedores', 'en' => 'Suppliers', 'es' => 'Proveedores'], 'readonly' => true, 'filter' => 'none', 'width' => 100],
        'QTD_EM_USO' => ['label' => ['pt' => 'Em uso', 'en' => 'In use', 'es' => 'En uso'], 'readonly' => true, 'filter' => 'none', 'width' => 90],
    ],
]);

require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
// Barra de botões: Operações (padrão do grid) | Associações (Critérios, Palavras-chave).
greenPortalToolbarGrupos('.homologacao-tipos-fornecedores-grid-fragment', ['associacoes' => ['[data-context-id^="tipofornecedor-"]']]);
?>
<script src="../assets/js/associacoes-contagem.js?v=6"></script>
