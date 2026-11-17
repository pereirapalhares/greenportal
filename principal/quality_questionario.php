<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/QuestionnaireAdminPage.php';
QuestionnaireAdminPage::render('QUALITY', ['pt' => 'Questionários de Qualidade', 'en' => 'Questionários de Qualidade', 'es' => 'Questionários de Qualidade']);
