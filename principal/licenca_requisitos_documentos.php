<?php
declare(strict_types=1);

/*
 * Manutenção legal › Licenças › Requisitos e documentos.
 * Mesma tela do Módulo Legal › Requisitos legais (modulolegal_requisitoslegais.php, classes/ModuloLegalRequisitosGrid.php),
 * só com os requisitos vinculados à licença escolhida na tela anterior (?codlicenca=; view da migração 306).
 * O filtro da licença fica fixo (não pode ser removido) e vale também para a contagem, a exportação e o relatório.
 */
ob_start(); // a classe envia cabeçalhos HTTP; a marcação abaixo sai junto com a tela
if (max(0, (int)($_GET['codlicenca'] ?? 0)) <= 0) { http_response_code(400); { require_once dirname(__DIR__) . '/classes/IdiomaService.php'; exit(htmlspecialchars(IdiomaService::t('licenca_requisitos_documentos.erro.licenca', ['pt' => 'Licença não informada.', 'en' => 'License not provided.', 'es' => 'Licencia no informada.']), ENT_QUOTES, 'UTF-8')); } }
require_once dirname(__DIR__) . '/classes/ModuloLegalRequisitosGrid.php';
// Exportação e preferências de colunas são as da tela de requisitos legais
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && empty($_GET['ajax'])) echo '<body data-transacao="modulolegal_requisitoslegais">'; // POST = respostas JSON da tela
ModuloLegalRequisitosGrid::render();
ob_end_flush();
