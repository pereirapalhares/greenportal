<?php
declare(strict_types=1);

/*
 * Homologação de Fornecedores › Fornecedores desativados (menu HOMOLOGACAO_FORNECEDORES_DESATIVADOS, migração 296).
 * Legado: configuracoes/fo_fornecedor.php?desativado=S (ativadofor <> 'Sim').
 * Mesma grade e mesmos botões da Gestão de Fornecedores, sobre vw_homologacao_fornecedores_desativados_grid.
 * Para reativar, mude "Ativado?" para Sim e salve. Os botões das abas usam a permissão da Gestão de Fornecedores.
 */

define('GP_FORNECEDORES_DESATIVADOS', true);
require __DIR__ . '/homologacaofornecedor_fornecedor.php';
