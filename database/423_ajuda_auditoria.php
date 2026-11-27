<?php
declare(strict_types=1);

// Migração 423 — Ajuda do Módulo de Auditoria (Revisão G item 175), gravada em ajuda_transacoes entre <!-- ml423 --> e <!-- /ml423 -->.
// Só as marcas aceitas pela Ajuda: p, ul/ol/li, strong/b, em/i e a.
$L = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];
$gestaoPt = '<p><strong>Como funciona a auditoria</strong></p><ol>'
    . '<li><strong>Planejamento</strong> (Nova auditoria): título, tipo (interna, externa, de fornecedor ou de cliente), unidade, norma/referência, objetivo, escopo, período, local, '
    . '<strong>avaliador</strong> (ou grupo) que aprova o plano, <strong>auditor líder</strong> (ou grupo) e a origem das RNCs que a auditoria abrir. Já é possível montar o checklist.</li>'
    . '<li><strong>Enviar para avaliação</strong>: o avaliador recebe o aviso (sininho) e a pendência. Ele registra a avaliação na aba Avaliação e <strong>aprova</strong> (vai para execução) '
    . 'ou <strong>recusa</strong> com o motivo (volta ao planejamento).</li>'
    . '<li><strong>Checklist</strong>: perguntas manuais ou <em>Gerar com IA</em> a partir do texto da norma/procedimento ou de um arquivo da pasta da auditoria (botão Arquivos). '
    . 'Na execução o auditor responde cada pergunta (Conforme, NC maior, NC menor, Observação, Melhoria ou Não se aplica) com a evidência; o botão de estrelas pede à IA a avaliação da evidência.</li>'
    . '<li><strong>Constatações</strong>: registre cada achado (pelo checklist ou direto). Cada não conformidade (maior ou menor) precisa de uma <strong>RNC</strong>: '
    . 'o botão <em>Abrir RNC</em> abre a ocorrência já preenchida e ligada à auditoria; ao voltar, a RNC aparece ligada sozinha.</li>'
    . '<li><strong>Execução</strong>: observações, data da execução e da reunião final.</li>'
    . '<li><strong>Encerrar</strong>: só com a execução registrada, toda NC com RNC e as RNCs finalizadas (follow-up na aba RNC). <strong>Cancelar</strong> pede o motivo.</li></ol>'
    . '<p>A barra de etapas mostra em que passo a auditoria está; o Histórico guarda cada mudança de situação (como no sistema anterior) e o Relatório gera o PDF com planejamento, avaliação, '
    . 'execução, checklist, constatações e RNCs. As auditorias aparecem na agenda do auditor e do avaliador e nas Minhas pendências de quem precisa agir.</p>'
    . '<p>Situações: <em>Em Planejamento</em>, <em>Em Avaliação</em>, <em>Recusada</em>, <em>Em Execução</em>, <em>Encerrada</em> e <em>Cancelada</em>. '
    . 'As auditorias do sistema anterior (Green 3) chegam pelo sincronismo diário.</p>';
$gestaoEn = '<p><strong>How the audit works</strong></p><ol><li><strong>Plan</strong>: title, type, unit, standard, objective, scope, period, location, <strong>reviewer</strong> (or group), <strong>lead auditor</strong> (or group) and the origin of the NCRs.</li>'
    . '<li><strong>Send for review</strong>: the reviewer records the review and <strong>approves</strong> (execution) or <strong>rejects</strong> with a reason (back to planning).</li>'
    . '<li><strong>Checklist</strong>: manual questions or <em>Generate with AI</em> from the standard/procedure text or a file of the audit folder. During execution the auditor answers each question with the evidence; the stars button asks the AI to assess the evidence.</li>'
    . '<li><strong>Findings</strong>: each nonconformity needs an <strong>NCR</strong> (<em>Open NCR</em> opens it already linked to the audit).</li>'
    . '<li><strong>Close</strong>: only with the execution recorded, every NC with an NCR and the NCRs finished (follow-up). <strong>Cancel</strong> asks for the reason.</li></ol>'
    . '<p>The stage bar shows where the audit is; History keeps every status change; Report generates the PDF.</p>';
$gestaoEs = '<p><strong>Cómo funciona la auditoría</strong></p><ol><li><strong>Planificación</strong>: título, tipo, unidad, norma, objetivo, alcance, período, lugar, <strong>evaluador</strong> (o grupo), <strong>auditor líder</strong> (o grupo) y el origen de las RNC.</li>'
    . '<li><strong>Enviar a evaluación</strong>: el evaluador registra la evaluación y <strong>aprueba</strong> (ejecución) o <strong>rechaza</strong> con el motivo (vuelve a la planificación).</li>'
    . '<li><strong>Checklist</strong>: preguntas manuales o <em>Generar con IA</em> desde el texto de la norma/procedimiento o un archivo de la carpeta. En la ejecución el auditor responde cada pregunta con la evidencia; el botón de estrellas pide a la IA la evaluación de la evidencia.</li>'
    . '<li><strong>Hallazgos</strong>: cada no conformidad necesita una <strong>RNC</strong> (<em>Abrir RNC</em> la abre ya vinculada).</li>'
    . '<li><strong>Cerrar</strong>: solo con la ejecución registrada, toda NC con RNC y las RNC finalizadas (seguimiento). <strong>Cancelar</strong> pide el motivo.</li></ol>'
    . '<p>La barra de etapas muestra dónde está la auditoría; el Historial guarda cada cambio; el Informe genera el PDF.</p>';
$homePt = '<p><strong>Home de Auditoria</strong>: cartões com as auditorias em cada situação, as novas no mês, as abertas com período vencido e as RNCs de auditoria em cada etapa '
    . '(criação, com o gestor, recusada pelo gestor, análise da causa raiz, plano de ação, avaliação da eficácia, recusada na eficácia e finalizada). Os gráficos mostram as auditorias por situação, '
    . 'unidade, tipo, auditor e mês. Os filtros do topo (unidade, auditor, tipo e período) valem para a página toda; clique num cartão ou numa barra para listar e dê duplo clique para abrir.</p>';
$homeEn = '<p><strong>Audit home</strong>: cards with the audits in each status, new this month, open with the period passed and the audit NCRs in each stage. Charts by status, unit, type, auditor and month. The filters apply to the whole page.</p>';
$homeEs = '<p><strong>Inicio de Auditoría</strong>: tarjetas con las auditorías en cada situación, nuevas en el mes, abiertas con período vencido y las RNC de auditoría en cada etapa. Gráficos por situación, unidad, tipo, auditor y mes. Los filtros valen para toda la página.</p>';
return [
    'auditoria_gestao' => ['titulo' => $L('Gestão de Auditorias', 'Audit management', 'Gestión de Auditorías'), 'texto' => $L($gestaoPt, $gestaoEn, $gestaoEs)],
    'minhas_auditorias' => ['titulo' => $L('Minhas Auditorias', 'My audits', 'Mis Auditorías'), 'texto' => $L('<p>As auditorias de que você participa (criou, avalia ou audita, direto ou pelo grupo).</p>' . $gestaoPt, '<p>The audits you take part in.</p>' . $gestaoEn, '<p>Las auditorías en que usted participa.</p>' . $gestaoEs)],
    'home_auditoria' => ['titulo' => $L('Home de Auditoria', 'Audit home', 'Inicio de Auditoría'), 'texto' => $L($homePt, $homeEn, $homeEs)],
];
