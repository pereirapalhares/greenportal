<?php
declare(strict_types=1);

// Entrada independente para a visão de Licenças atribuídas ao usuário logado.
$_GET['minhas'] = '1';
require __DIR__ . '/manutencaolegal_licencas.php';
