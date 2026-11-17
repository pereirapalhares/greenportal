<?php
declare(strict_types=1);

/*
 * Homologação de Fornecedores › Tipos de Funcionários
 * Transação: homologacaofornecedores_tiposfuncionarios — menu/permissão HOMOLOGACAO_TIPOS_FUNCIONARIOS.
 * Legado: hpr_configuracoes, transação foTiposdeFuncionarios (codtransacao 142), tabela fo_tipofuncionarios,
 * com as abas Critérios (144), Treinamentos (146) e Palavras chave (143).
 *
 * Leitura: vw_homologacao_tipos_funcionarios_cadastro_grid (sp_crud_select / sp_crud_count).
 * Gravação: sp_crud_insert / sp_crud_update / sp_crud_delete na tabela fo_tipofuncionarios.
 * Exclusão: tipo usado por funcionários é bloqueado no banco (trg_fo_tipofuncionarios_antes_excluir ->
 * sp_fo_tipofuncionarios_validar_exclusao); os vínculos do tipo saem junto. Objetos: migrações 248 e 249.
 * Vínculos (botões Critérios / Treinamentos / Palavras-chave): homologacaofornecedores_tiposfuncionarios_vinculos.php,
 * duas listas (disponíveis / vinculados) pelas procedures sp_fo_tipofuncionario_vinculos_listar / _gravar.
 * O que cada perfil pode fazer vem de Segurança › Permissões.
 */

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
// Textos acentuados escritos como \u.... (não sofrem a correção automática de acentuação do portal).
$u = static fn(string $s): string => (string)json_decode('"' . $s . '"');
$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$botao = static function (string $relacao, string $icone, string $titulo) use ($h): string {
    return '<button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child'
        . ' data-context-id="tipofuncionario-' . $h(strtolower($relacao)) . '"'
        . ' data-context-url="/principal/homologacaofornecedores_tiposfuncionarios_vinculos.php?relacao=' . $h($relacao) . '"'
        . ' data-context-fields="CODTIPOFUNCIONARIO" data-context-title="' . $h($titulo) . '" disabled'
        . ' title="' . $h($titulo) . '" aria-label="' . $h($titulo) . '"><i class="bi ' . $h($icone) . '"></i></button>';
};
$extras = $botao('CRITERIOS', 'bi-list-check', IdiomaService::t('homologacaofornecedores_tiposfuncionarios.botao.criterios', ['pt' => $u('Critérios'), 'en' => 'Criteria', 'es' => 'Criterios']))
    . $botao('TREINAMENTOS', 'bi-mortarboard', IdiomaService::t('homologacaofornecedores_tiposfuncionarios.botao.treinamentos', ['pt' => 'Treinamentos', 'en' => 'Training', 'es' => 'Capacitaciones']))
    . $botao('PALAVRASCHAVE', 'bi-tags', IdiomaService::t('homologacaofornecedores_tiposfuncionarios.botao.palavraschave', ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave']));

ManutencaoLegalCadastroGrid::render([
    'menu' => 'HOMOLOGACAO_TIPOS_FUNCIONARIOS',
    'title' => ['pt' => $u('Tipos de Funcionários'), 'en' => 'Employee types', 'es' => 'Tipos de empleados'],
    'transaction' => 'homologacaofornecedores_tiposfuncionarios', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT',
    'fragment_class' => 'homologacao-tipos-funcionarios-grid-fragment',
    'table' => 'fo_tipofuncionarios', 'view' => 'vw_homologacao_tipos_funcionarios_cadastro_grid',
    'primary' => 'CODTIPOFUNCIONARIO', 'primary_auto' => true, 'primary_editable' => false,
    'default_sort' => ['column' => 'DSCTIPO', 'direction' => 'asc'],
    'extras' => $extras,
    'fields' => [
        'CODTIPOFUNCIONARIO' => ['label' => ['pt' => $u('Código'), 'en' => 'Code', 'es' => $u('Código')], 'hidden' => true, 'readonly' => true, 'filter' => 'none', 'width' => 0],
        'DSCTIPO' => ['label' => ['pt' => $u('Tipo de funcionário'), 'en' => 'Employee type', 'es' => 'Tipo de empleado'], 'type' => 'textarea', 'required' => true, 'filter' => 'text', 'width' => 560],
        'QTD_EM_USO' => ['label' => ['pt' => 'Em uso', 'en' => 'In use', 'es' => 'En uso'], 'readonly' => true, 'filter' => 'none', 'width' => 90],
    ],
]);

require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
// Barra de botões: Operações (padrão do grid) | Associações (Critérios, Treinamentos, Palavras-chave).
greenPortalToolbarGrupos('.homologacao-tipos-funcionarios-grid-fragment', ['associacoes' => ['[data-context-id^="tipofuncionario-"]']]);
