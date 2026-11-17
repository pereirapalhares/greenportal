<?php
declare(strict_types=1);

// Migração 366 — trecho da Ajuda (ajuda_transacoes) dos dashboards sobre Não Conformidades e Fornecedores, entre
// <!-- ml366 --> … <!-- /ml366 --> (o texto que já existe fica). Marcas aceitas: p, ul/ol/li, strong/b, em/i e a.
$L = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];

$pessoalPt = '<p><strong>Minhas não conformidades</strong> (aba): as NCs que esperam uma ação sua agora, separadas pela etapa — '
    . 'completar ou corrigir (você é o emitente), avaliar como gestor, análise da causa e plano de ação (você é o responsável) e avaliar a eficácia (gestor). '
    . 'A lista mostra o que fazer e há quantos dias a NC está parada; clique na linha para abrir a NC já na aba certa. Abaixo, as NCs que você abriu e estão com outras pessoas.</p>'
    . '<p><strong>Meus fornecedores</strong> (aba): as pendências de fornecedor de Home › Minhas pendências (vencidas, vencendo em 30 dias e sem data, como a próxima avaliação) '
    . 'e os fornecedores em que você é o responsável, com documentos vencidos, a vencer e sem situação, pendências de funcionários e NCs abertas. Clique para abrir o fornecedor.</p>'
    . '<p>Na <strong>Visão geral</strong> aparecem os cartões "Não conformidades esperando minha ação" e "Meus fornecedores com pendência".</p>';
$pessoalEn = '<p><strong>My nonconformities</strong> (tab): the NCs waiting for your action now, by stage — complete or fix (issuer), review as manager, root cause and action plan (owner) and effectiveness (manager). Click a row to open the NC on the right tab. Below, the NCs you opened that are with other people.</p>'
    . '<p><strong>My suppliers</strong> (tab): the supplier items from Home › My pending items and the suppliers you own, with expired, expiring and no-status documents, employee items and open NCs.</p>';
$pessoalEs = '<p><strong>Mis no conformidades</strong> (pestaña): las NC que esperan su acción ahora, por etapa — completar o corregir (emisor), evaluar como gestor, análisis de causa y plan de acción (responsable) y eficacia (gestor). Haga clic en la fila para abrir la NC en la pestaña correcta. Abajo, las NC que usted abrió y están con otras personas.</p>'
    . '<p><strong>Mis proveedores</strong> (pestaña): los pendientes de proveedor de Inicio › Mis pendientes y los proveedores de los que usted es responsable, con documentos vencidos, por vencer y sin situación, pendientes de empleados y NC abiertas.</p>';

$empresaPt = '<p><strong>Não conformidades</strong> (aba): todas as NCs abertas da empresa — por etapa, por tipo e tempo em aberto (até 30 dias, 31–90, 91 dias a 1 ano, mais de 1 ano), '
    . 'abertas × finalizadas nos últimos 12 meses, por unidade, <strong>com quem estão</strong> (quem precisa agir em cada etapa, com as paradas há mais de 90 dias e as de usuários inativos) '
    . 'e as 100 paradas há mais tempo. Clique no tipo para abrir a Lista geral e na linha para abrir a NC.</p>'
    . '<p><strong>Fornecedores</strong> (aba): antes do ranking, a <strong>situação da homologação</strong> dos fornecedores ativos — sem responsável, avaliação vencida ou sem data, '
    . 'documentos vencidos/reprovados, a vencer e sem situação, pendências de funcionários e os fornecedores que mais precisam de atenção. As cores seguem o Painel de Homologação.</p>'
    . '<p>A <strong>Visão geral</strong> ganhou os resumos de Não conformidades e de Fornecedores.</p>';
$empresaEn = '<p><strong>Nonconformities</strong> (tab): all open NCs — by stage, by type and time open, opened × closed in the last 12 months, by unit, <strong>who holds them</strong> and the 100 idle the longest.</p>'
    . '<p><strong>Suppliers</strong> (tab): before the ranking, the <strong>homologation status</strong> of active suppliers — without owner, evaluation overdue or without date, expired/expiring/no-status documents, employee items and the suppliers needing the most attention.</p>';
$empresaEs = '<p><strong>No conformidades</strong> (pestaña): todas las NC abiertas — por etapa, por tipo y tiempo abierta, abiertas × finalizadas en los últimos 12 meses, por unidad, <strong>con quién están</strong> y las 100 paradas hace más tiempo.</p>'
    . '<p><strong>Proveedores</strong> (pestaña): antes del ranking, la <strong>situación de la homologación</strong> de los proveedores activos — sin responsable, evaluación vencida o sin fecha, documentos vencidos/por vencer/sin situación, pendientes de empleados y los proveedores que más necesitan atención.</p>';

return [
    'dashboard' => ['titulo' => $L('Ajuda — Dashboard', 'Help — Dashboard', 'Ayuda — Panel'), 'texto' => $L($pessoalPt, $pessoalEn, $pessoalEs)],
    'dashboard_admin' => ['titulo' => $L('Ajuda — Dashboard da empresa', 'Help — Company dashboard', 'Ayuda — Panel de la empresa'), 'texto' => $L($empresaPt, $empresaEn, $empresaEs)],
];
