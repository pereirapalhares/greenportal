<?php
declare(strict_types=1);

// Aba "Perfil de atribuições" de Administração › Questionários: o questionário PERFIL_ATRIBUICOES (módulo USER_PROFILE)
require_once dirname(__DIR__) . '/classes/QuestionnaireAdminPage.php';
QuestionnaireAdminPage::render('USER_PROFILE', ['pt' => 'Perfil de atribuições', 'en' => 'Responsibilities profile', 'es' => 'Perfil de atribuciones']);
