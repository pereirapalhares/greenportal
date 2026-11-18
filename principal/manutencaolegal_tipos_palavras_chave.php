<?php
// Migração 394 — Manutenção Legal › Tipos de palavras-chave (glegislacao_dbp.tipos_palavras_chaves), no lugar da transação
// "Tipos de palavras chave" do legado. Incluir e renomear; excluir só tipo sem palavras (gatilhos da 394 também impedem nome
// repetido, sem diferença de acento ou maiúsculas). As palavras de cada tipo ficam em Tipos × Palavras-chave.
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
// Migração 396: a tela abre dentro de Manutenção Legal › Tipos e Palavras-chave (permissão própria); o código antigo continua valendo.
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$menuTipos = !empty(PermissaoTransacao::obter('MANUTENCAOLEGAL_TIPOS_PALAVRAS')['pode_visualizar']) ? 'MANUTENCAOLEGAL_TIPOS_PALAVRAS' : 'TIPOS_PALAVRAS_CHAVE';
ManutencaoLegalCadastroGrid::render([
    'menu' => $menuTipos, 'title' => ['pt' => 'Tipos de palavras-chave', 'en' => 'Keyword types', 'es' => 'Tipos de palabras clave'],
    'transaction' => 'manutencaolegal_tipos_palavras_chave', 'database' => 'glegislacao_dbp', 'prefix' => 'GLEGISLACAO',
    'table' => 'tipos_palavras_chaves', 'view' => 'vw_tipos_palavras_chaves_grid', 'primary' => 'codtipopalavrachave', 'primary_auto' => true,
    'default_sort' => ['column' => 'dsctipopalavrachave', 'direction' => 'asc'],
    'fields' => [
        'codtipopalavrachave' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'readonly' => true],
        'dsctipopalavrachave' => ['label' => ['pt' => 'Tipo', 'en' => 'Type', 'es' => 'Tipo'], 'width' => 360],
        'QTD_PALAVRAS' => ['label' => ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave'], 'readonly' => true, 'filter' => 'none', 'width' => 140],
    ],
]);
