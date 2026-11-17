<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/QuestionnaireAdminPage.php';
QuestionnaireAdminPage::render('SUPPLIER', ['pt' => 'Questionários de Fornecedores', 'en' => 'Questionários de Fornecedores', 'es' => 'Questionários de Fornecedores']);
