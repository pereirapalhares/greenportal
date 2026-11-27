<?php
declare(strict_types=1);

// Migração 424 — Ajuda do Módulo de Auditoria com as telas no padrão do portal (barra em grupos, grids com filtro por coluna, modais).
// Gravada em ajuda_transacoes entre <!-- ml424 --> e <!-- /ml424 --> (substitui o bloco <!-- ml423 -->).
// Só as marcas aceitas pela Ajuda: p, ul/ol/li, strong/b, em/i e a.
$L = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];

// ---------------------------------------------------------------- lista (Gestão / Minhas)
$listaPt = '<p><strong>A lista</strong> segue o padrão dos grids do portal: filtro em cada coluna, <em>Buscar no grid</em>, ordenação pelo cabeçalho, paginação, '
    . '<em>Colunas</em> (mostrar/ocultar) e <em>Exportar Excel</em>. A coluna Situação tem a cor da etapa e <em>Período vencido?</em> mostra as abertas com o fim já passado.</p>'
    . '<ul><li><strong>Operações</strong>: Recarregar, Excel, Colunas, Ajuda, <em>Nova auditoria</em> e <em>Abrir</em> (ou duplo clique na linha).</li>'
    . '<li><strong>Associações</strong> (da linha selecionada): Checklist, Constatações, RNCs, Arquivos e Histórico — abrem a auditoria direto na aba.</li>'
    . '<li><strong>Outros</strong>: Relatório da auditoria em PDF.</li></ul>'
    . '<p>A auditoria abre numa janela por cima da lista; o que for gravado nela atualiza a lista sozinho.</p>';
$listaEn = '<p><strong>The list</strong> follows the portal grid standard: filter on each column, <em>Search the grid</em>, sorting, paging, <em>Columns</em> and <em>Export to Excel</em>. '
    . 'The Status column has the stage colour and <em>Overdue?</em> shows open audits past their end date.</p>'
    . '<ul><li><strong>Operations</strong>: Reload, Excel, Columns, Help, <em>New audit</em> and <em>Open</em> (or double-click the row).</li>'
    . '<li><strong>Associations</strong> (selected row): Checklist, Findings, NCRs, Files and History — open the audit on that tab.</li>'
    . '<li><strong>Others</strong>: audit report (PDF).</li></ul><p>The audit opens in a window over the list; whatever is saved there refreshes the list.</p>';
$listaEs = '<p><strong>La lista</strong> sigue el estándar de cuadrículas del portal: filtro en cada columna, <em>Buscar en la cuadrícula</em>, orden, paginación, <em>Columnas</em> y <em>Exportar a Excel</em>. '
    . 'La columna Situación tiene el color de la etapa y <em>¿Período vencido?</em> muestra las abiertas con el fin ya pasado.</p>'
    . '<ul><li><strong>Operaciones</strong>: Recargar, Excel, Columnas, Ayuda, <em>Nueva auditoría</em> y <em>Abrir</em> (o doble clic en la fila).</li>'
    . '<li><strong>Asociaciones</strong> (fila seleccionada): Checklist, Hallazgos, RNC, Archivos e Historial — abren la auditoría en esa pestaña.</li>'
    . '<li><strong>Otros</strong>: informe de la auditoría (PDF).</li></ul><p>La auditoría se abre en una ventana sobre la lista; lo que se guarde allí actualiza la lista.</p>';

// ---------------------------------------------------------------- ficha da auditoria
$fichaPt = '<p><strong>A auditoria</strong> mostra a barra de etapas (Planejamento → Aprovação do plano → Execução → Constatações e RNC → Encerramento), '
    . 'o <em>próximo passo</em> e quem deve agir. A barra de botões tem quatro grupos:</p>'
    . '<ul><li><strong>Operações</strong>: <em>Salvar</em> (grava a aba aberta: Planejamento, Avaliação, Execução ou as respostas do Checklist), <em>Excluir</em> (só no planejamento, por quem criou, sem RNC) e Ajuda.</li>'
    . '<li><strong>Fluxo</strong>: <em>Enviar para avaliação</em>, <em>Aprovar</em>, <em>Recusar</em> (pede o motivo), <em>Encerrar</em> e <em>Cancelar</em> (pede o motivo; vale em qualquer etapa aberta). '
    . 'Só aparecem os botões de quem pode agir na etapa. A chave <em>Avisar os responsáveis</em> manda o aviso (sininho) a quem deve agir em seguida.</li>'
    . '<li><strong>Associações</strong>: Arquivos da auditoria (normas, procedimentos e evidências), RNCs abertas pela auditoria e o Fornecedor auditado (Homologação).</li>'
    . '<li><strong>Outros</strong>: Relatório em PDF.</li></ul>'
    . '<p><strong>Como funciona</strong></p><ol>'
    . '<li><strong>Planejamento</strong>: título, tipo (interna, externa, de fornecedor ou de cliente), unidade, norma, objetivo, escopo, período, local, <strong>avaliador</strong> (ou grupo), '
    . '<strong>auditor líder</strong> (ou grupo) e a origem das RNCs. Salvar e depois <em>Enviar para avaliação</em>.</li>'
    . '<li><strong>Avaliação</strong>: o avaliador escreve a avaliação na aba Avaliação, usa Salvar e depois <em>Aprovar</em> (vai para execução) ou <em>Recusar</em> (volta ao planejamento com o motivo).</li>'
    . '<li><strong>Checklist</strong> (grid com filtro por coluna): <em>Novo</em> inclui uma pergunta; <em>Gerar perguntas com IA</em> cria as perguntas a partir do texto da norma ou de um arquivo da pasta; '
    . 'selecione a linha para <em>Excluir</em>, <em>Avaliar a evidência com IA</em> (sugere a resposta e a constatação) ou <em>Registrar constatação</em>. '
    . 'Na execução, escolha a resposta (Conforme, NC maior, NC menor, Observação, Melhoria ou Não se aplica), escreva a evidência e use Salvar.</li>'
    . '<li><strong>Execução</strong>: observações, data da execução e da reunião final (Salvar).</li>'
    . '<li><strong>Constatações</strong> (grid): <em>Novo</em> registra um achado; para cada não conformidade (maior ou menor) selecione a linha e use <em>Abrir RNC</em> — '
    . 'a ocorrência abre já preenchida e ligada à auditoria; ao voltar, a RNC aparece ligada sozinha. Também é possível <em>ligar a uma RNC</em> já aberta e <em>ver a RNC</em>.</li>'
    . '<li><strong>RNC</strong> (grid): cada RNC com a etapa e a barra do próprio fluxo (criação, gestor, análise da causa, plano de ação, eficácia, finalizada). Duplo clique abre a RNC.</li>'
    . '<li><strong>Encerrar</strong>: só com a execução registrada, toda NC com RNC e as RNCs finalizadas; o que falta aparece no próximo passo.</li></ol>'
    . '<p>O <strong>Histórico</strong> (grid) guarda cada mudança de situação e também avaliação, execução, perguntas, perguntas geradas pela IA, constatações e RNCs. '
    . 'As auditorias aparecem na agenda do auditor e do avaliador e nas Minhas pendências de quem precisa agir. As do sistema anterior (Green 3) chegam pelo sincronismo diário.</p>';
$fichaEn = '<p><strong>The audit</strong> shows the stage bar (Plan → Plan approval → Execution → Findings and NCRs → Closure), the <em>next step</em> and who must act. The button bar has four groups:</p>'
    . '<ul><li><strong>Operations</strong>: <em>Save</em> (saves the open tab), <em>Delete</em> (plan only, by the creator, without NCRs) and Help.</li>'
    . '<li><strong>Workflow</strong>: <em>Send for review</em>, <em>Approve</em>, <em>Reject</em> (asks for the reason), <em>Close</em> and <em>Cancel</em> (asks for the reason). '
    . 'The <em>Notify</em> switch notifies who must act next.</li>'
    . '<li><strong>Associations</strong>: audit files, NCRs opened by the audit and the audited supplier.</li><li><strong>Others</strong>: PDF report.</li></ul>'
    . '<ol><li><strong>Plan</strong>: title, type, unit, standard, objective, scope, period, <strong>reviewer</strong> and <strong>lead auditor</strong> (user or group). Save, then Send for review.</li>'
    . '<li><strong>Review</strong>: the reviewer writes the review, saves and Approves or Rejects.</li>'
    . '<li><strong>Checklist</strong> (grid): <em>New</em>, <em>Generate questions with AI</em>; select a row to Delete, <em>Assess the evidence with AI</em> or <em>Record finding</em>. Answer and write the evidence, then Save.</li>'
    . '<li><strong>Findings</strong> (grid): for each nonconformity select the row and use <em>Open NCR</em> (prefilled and linked to the audit).</li>'
    . '<li><strong>NCR</strong> (grid): each NCR with its own workflow bar.</li><li><strong>Close</strong>: needs the execution recorded and all NCRs finished.</li></ol>'
    . '<p><strong>History</strong> keeps status changes, reviews, execution, questions, findings and NCRs.</p>';
$fichaEs = '<p><strong>La auditoría</strong> muestra la barra de etapas (Planificación → Aprobación del plan → Ejecución → Hallazgos y RNC → Cierre), el <em>próximo paso</em> y quién debe actuar. La barra de botones tiene cuatro grupos:</p>'
    . '<ul><li><strong>Operaciones</strong>: <em>Guardar</em> (guarda la pestaña abierta), <em>Eliminar</em> (solo en planificación, por quien la creó, sin RNC) y Ayuda.</li>'
    . '<li><strong>Flujo</strong>: <em>Enviar a evaluación</em>, <em>Aprobar</em>, <em>Rechazar</em> (pide el motivo), <em>Cerrar</em> y <em>Cancelar</em> (pide el motivo). '
    . 'El interruptor <em>Avisar</em> avisa a quien debe actuar después.</li>'
    . '<li><strong>Asociaciones</strong>: archivos, RNC abiertas por la auditoría y el proveedor auditado.</li><li><strong>Otros</strong>: informe en PDF.</li></ul>'
    . '<ol><li><strong>Planificación</strong>: título, tipo, unidad, norma, objetivo, alcance, período, <strong>evaluador</strong> y <strong>auditor líder</strong> (usuario o grupo). Guardar y Enviar a evaluación.</li>'
    . '<li><strong>Evaluación</strong>: el evaluador escribe la evaluación, guarda y Aprueba o Rechaza.</li>'
    . '<li><strong>Checklist</strong> (cuadrícula): <em>Nuevo</em>, <em>Generar preguntas con IA</em>; seleccione una fila para Eliminar, <em>Evaluar la evidencia con IA</em> o <em>Registrar hallazgo</em>. Responda, escriba la evidencia y Guarde.</li>'
    . '<li><strong>Hallazgos</strong> (cuadrícula): para cada no conformidad seleccione la fila y use <em>Abrir RNC</em> (ya completada y vinculada).</li>'
    . '<li><strong>RNC</strong> (cuadrícula): cada RNC con la barra de su propio flujo.</li><li><strong>Cerrar</strong>: requiere la ejecución registrada y todas las RNC finalizadas.</li></ol>'
    . '<p>El <strong>Historial</strong> guarda los cambios de situación, evaluaciones, ejecución, preguntas, hallazgos y RNC.</p>';

// ---------------------------------------------------------------- Home
$homePt = '<p><strong>Home de Auditoria</strong>: cartões com as auditorias em cada situação, as novas no mês, as abertas com período vencido e as RNCs de auditoria em cada etapa '
    . '(criação, com o gestor, recusada pelo gestor, análise da causa raiz, plano de ação, avaliação da eficácia, recusada na eficácia e finalizada). Os gráficos mostram as auditorias por situação, '
    . 'unidade, tipo, auditor e mês, e o calendário mostra o início e o fim previsto de cada auditoria. Os filtros do topo (unidade, auditor, tipo e período) valem para a página toda; '
    . 'clique num cartão ou numa barra para listar nos grids (com filtro por coluna) e dê duplo clique para abrir a auditoria ou a RNC. '
    . 'Os botões do topo levam à Gestão de Auditorias (ou a Minhas Auditorias) e à Nova auditoria.</p>';
$homeEn = '<p><strong>Audit home</strong>: cards with the audits in each status, new this month, open with the period passed and the audit NCRs in each stage; charts by status, unit, type, auditor and month; calendar. '
    . 'The filters apply to the whole page; click a card or bar to list in the grids and double-click to open.</p>';
$homeEs = '<p><strong>Inicio de Auditoría</strong>: tarjetas con las auditorías en cada situación, nuevas en el mes, abiertas con período vencido y las RNC de auditoría en cada etapa; gráficos por situación, unidad, tipo, auditor y mes; calendario. '
    . 'Los filtros valen para toda la página; haga clic en una tarjeta o barra para listar y doble clic para abrir.</p>';

return [
    'auditoria_gestao' => ['titulo' => $L('Gestão de Auditorias', 'Audit management', 'Gestión de Auditorías'), 'texto' => $L($listaPt . $fichaPt, $listaEn . $fichaEn, $listaEs . $fichaEs)],
    'minhas_auditorias' => ['titulo' => $L('Minhas Auditorias', 'My audits', 'Mis Auditorías'), 'texto' => $L(
        '<p>As auditorias de que você participa: criou, avalia ou audita, direto ou por um grupo.</p>' . $listaPt . $fichaPt,
        '<p>The audits you take part in: you created, review or audit them, directly or through a group.</p>' . $listaEn . $fichaEn,
        '<p>Las auditorías en que usted participa: las creó, evalúa o audita, directamente o por un grupo.</p>' . $listaEs . $fichaEs)],
    'home_auditoria' => ['titulo' => $L('Home de Auditoria', 'Audit home', 'Inicio de Auditoría'), 'texto' => $L($homePt, $homeEn, $homeEs)],
];
