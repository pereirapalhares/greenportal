<?php
declare(strict_types=1);

// Minhas atividades › Minhas medições e monitoramentos (migração 358, GER-5.1 / MM-70): o Plano de Medição e Monitoramento
// no modo "minhas pendências" (vw_minhas_monitoramentos_grid: responsável direto ou por atribuição de usuário/grupo; salvar sim,
// incluir/excluir não). Forçado em toda requisição (a grade grava e recarrega pela própria URL).
$_GET['minhas_pendencias'] = '1';
require __DIR__ . '/medicaoemonitoramento_mm_monitoramento.php';
