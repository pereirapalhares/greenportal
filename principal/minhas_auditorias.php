<?php
declare(strict_types=1);
// Auditoria › Minhas Auditorias (menu MINHAS_AUDITORIAS, migração 423): a mesma tela da Gestão, só com as auditorias de que o
// usuário participa (criador, avaliador, auditor ou membro dos grupos).
$GLOBALS['AUD_MINHAS'] = true;
require __DIR__ . '/auditoria_gestao.php';
