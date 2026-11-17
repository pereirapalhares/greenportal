<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/QuestionnaireAdminPage.php';
QuestionnaireAdminPage::render('SGA', ['pt' => 'Questionários SGA', 'en' => 'Questionários SGA', 'es' => 'Questionários SGA']);
