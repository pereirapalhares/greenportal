<?php
declare(strict_types=1);

// Minhas atividades › Meus fornecedores (migração 358, GER-5.1 / HOM-136): a Gestão de Fornecedores só com os fornecedores
// pelos quais o usuário responde (responsável pelo fornecedor, pela integração ou atribuição de usuário/grupo).
define('GP_MEUS_FORNECEDORES', true);
require __DIR__ . '/homologacaofornecedor_fornecedor.php';
