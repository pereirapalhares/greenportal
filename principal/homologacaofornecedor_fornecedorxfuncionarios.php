<?php
declare(strict_types=1);
// Gestão de Fornecedores › Funcionários (26/11/2026): a mesma tela da Gestão de Funcionários
// (homologacaofornecedor_funcionario.php), só com os funcionários do fornecedor escolhido na tela anterior
// (filtros={"CODFORNECEDOR":n} na URL). Antes era a grade simplificada da HomologacaoFornecedorRelacionamentoGrid.
define('GP_FUNCIONARIOS_DO_FORNECEDOR', true);
require __DIR__ . '/homologacaofornecedor_funcionario.php';
