<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/QuestionnaireAdminPage.php';
QuestionnaireAdminPage::render('LEGAL', ['pt' => 'Questionários Legais', 'en' => 'Questionários Legais', 'es' => 'Questionários Legais']);
