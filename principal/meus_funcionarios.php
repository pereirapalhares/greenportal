<?php
declare(strict_types=1);

// Minhas atividades › Meus funcionários de fornecedores (migração 358, GER-5.1 / HOM-137): a Gestão de Funcionários só com os
// funcionários dos meus fornecedores e os atribuídos a mim (usuário ou grupo).
define('GP_MEUS_FUNCIONARIOS', true);
require __DIR__ . '/homologacaofornecedor_funcionario.php';
