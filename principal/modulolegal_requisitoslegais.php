<?php
declare(strict_types=1);

// Transação do menu Módulo Legal / Requisitos legais.
// As transações contextuais de LAIA, LPRO e LAIS usam arquivos próprios.
require_once dirname(__DIR__) . '/classes/ModuloLegalRequisitosGrid.php';

ModuloLegalRequisitosGrid::render();
