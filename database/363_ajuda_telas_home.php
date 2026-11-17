<?php
declare(strict_types=1);

// Migração 363 — Ajuda da transação (ajuda_transacoes) das telas Minhas atividades, Ações e Histórico de alterações.
// Gravada entre <!-- ml363 --> … <!-- /ml363 -->; texto que a empresa escreveu fora da marca é mantido.
// Só as marcas aceitas pela Ajuda: p, ul/ol/li, strong/b, em/i e a.
$L = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];

$atividadesPt = '<p><strong>Para que serve</strong></p>'
    . '<p>Minhas atividades reúne, numa só tela da Home, tudo pelo qual você responde. Cada aba é a tela do módulo já filtrada para o que é seu: '
    . 'o registro em que você é o <strong>Responsável</strong>, em que recebeu uma <strong>atribuição</strong> (você ou um grupo do qual faz parte) ou que se encaixa numa regra de <strong>Responsáveis e avisos</strong> do módulo.</p>'
    . '<p><strong>As abas</strong></p><ul>'
    . '<li><strong>Requisitos legais</strong>: requisitos de leis em vigor pelos quais você responde;</li>'
    . '<li><strong>Licenças</strong> e <strong>Condicionantes</strong>: licenças e exigências das licenças;</li>'
    . '<li><strong>Medições e monitoramentos</strong>, <strong>Controles</strong>, <strong>Treinamentos</strong> e <strong>Infraestruturas</strong>;</li>'
    . '<li><strong>Fornecedores</strong> e <strong>Funcionários de fornecedores</strong> (só para usuários internos);</li>'
    . '<li><strong>Ocorrências (NC)</strong>: não conformidades em que você está envolvido e que ainda não foram finalizadas;</li>'
    . '<li><strong>Ações</strong>: as suas ações dos planos de ação (veja a ajuda da aba Ações).</li></ul>'
    . '<p>O número ao lado do nome é a quantidade de registros (em Ações, as não concluídas; em Ocorrências, as não finalizadas). As abas ficam em ordem, da que tem mais registros para a que tem menos. Só aparecem os módulos que o seu perfil pode ver.</p>'
    . '<p><strong>Como usar</strong></p><ol>'
    . '<li>Na Home, clique em <strong>Minhas atividades</strong> e espere a contagem das abas;</li>'
    . '<li>Clique na aba do módulo;</li>'
    . '<li>Altere o que precisar na linha e clique em <strong>Salvar</strong> (e em Confirmar).</li></ol>'
    . '<p><strong>Bom saber</strong></p><ul>'
    . '<li>É a mesma tela do módulo: o que você salva aqui vale para o registro;</li>'
    . '<li>Aqui não se inclui nem se exclui: use a tela do módulo pelo menu;</li>'
    . '<li>Se o número de uma aba aparecer como "?", a contagem não respondeu; a aba funciona normalmente. Tecle F5 para contar de novo;</li>'
    . '<li>Tecle <strong>F1</strong> ou clique no <strong>?</strong> para os guias passo a passo com "Mostre-me".</li></ul>';
$atividadesEn = '<p><strong>What it is for</strong></p><p>My work gathers, on one Home screen, everything you are responsible for. Each tab is the module screen already filtered to what is yours: records where you are the <strong>Owner</strong>, received an <strong>assignment</strong> (you or a group you belong to) or that match a <strong>People responsible and alerts</strong> rule of the module.</p>'
    . '<p><strong>Tabs</strong>: Legal requirements, Licenses, Conditions, Measurements and monitoring, Controls, Training, Infrastructure, Suppliers and Supplier employees (internal users only), Occurrences (NC: the ones you are involved in that are not finished) and Actions (your action plan actions). The number next to the name is the record count; tabs are sorted from most to fewest. Only modules your profile can see appear.</p>'
    . '<p><strong>How to use</strong></p><ol><li>On Home, click <strong>My work</strong> and wait for the counts;</li><li>Click the module tab;</li><li>Change what you need in the row and click <strong>Save</strong> (and Confirm).</li></ol>'
    . '<p><strong>Good to know</strong></p><ul><li>It is the same module screen: what you save applies to the record;</li><li>You cannot add or delete here: use the module screen from the menu;</li><li>If a tab number shows "?", its count did not answer; the tab works normally. Press F5 to count again;</li><li>Press <strong>F1</strong> or click <strong>?</strong> for step-by-step guides with "Show me".</li></ul>';
$atividadesEs = '<p><strong>Para qué sirve</strong></p><p>Mis actividades reúne, en una sola pantalla de Inicio, todo de lo que usted es responsable. Cada pestaña es la pantalla del módulo ya filtrada a lo suyo: registros en los que usted es el <strong>Responsable</strong>, recibió una <strong>asignación</strong> (usted o un grupo del que forma parte) o que encajan en una regla de <strong>Responsables y avisos</strong> del módulo.</p>'
    . '<p><strong>Pestañas</strong>: Requisitos legales, Licencias, Condicionantes, Mediciones y monitoreos, Controles, Capacitaciones, Infraestructuras, Proveedores y Empleados de proveedores (solo usuarios internos), Ocurrencias (NC en las que participa y que no fueron finalizadas) y Acciones (sus acciones de los planes de acción). El número al lado del nombre es la cantidad de registros; las pestañas quedan en orden, de la que tiene más a la que tiene menos. Solo aparecen los módulos que su perfil puede ver.</p>'
    . '<p><strong>Cómo usar</strong></p><ol><li>En Inicio, haga clic en <strong>Mis actividades</strong> y espere el conteo;</li><li>Haga clic en la pestaña del módulo;</li><li>Cambie lo que necesite en la fila y haga clic en <strong>Guardar</strong> (y en Confirmar).</li></ol>'
    . '<p><strong>Bueno saber</strong></p><ul><li>Es la misma pantalla del módulo: lo que guarde vale para el registro;</li><li>Aquí no se incluye ni se excluye: use la pantalla del módulo desde el menú;</li><li>Si el número de una pestaña aparece como "?", su conteo no respondió; la pestaña funciona normalmente. Presione F5 para contar de nuevo;</li><li>Presione <strong>F1</strong> o haga clic en <strong>?</strong> para las guías paso a paso con "Muéstrame".</li></ul>';

$acoesPt = '<p><strong>Para que serve</strong></p>'
    . '<p>A aba Ações junta, numa lista só, as ações dos planos de ação de <strong>Condicionantes de licenças</strong>, <strong>Fornecedores</strong> e <strong>Não Conformidades</strong> em que você é o responsável da ação, e também as ações concluídas que esperam a <strong>sua avaliação da eficácia</strong>.</p>'
    . '<p><strong>Situações</strong> (cartões no alto, com a quantidade de cada uma)</p><ul>'
    . '<li><strong>Avaliar eficácia</strong>: ação a 100%, marcada com "Avaliar eficácia? = Sim", em que você é o avaliador e ainda sem resultado;</li>'
    . '<li><strong>Atrasada</strong>: abaixo de 100% e a data final já passou;</li>'
    . '<li><strong>Vence em até 7 dias</strong>: abaixo de 100% e a data final é nos próximos 7 dias;</li>'
    . '<li><strong>No prazo</strong>: abaixo de 100% e a data final é depois disso;</li>'
    . '<li><strong>Sem prazo</strong>: abaixo de 100% e sem data final;</li>'
    . '<li><strong>Concluída</strong>: 100%.</li></ul>'
    . '<p><strong>Como usar</strong></p><ol>'
    . '<li>Clique num <strong>cartão</strong> para ver só aquela situação (clique de novo para voltar às em aberto);</li>'
    . '<li>Se quiser, escolha o <strong>Módulo</strong>, troque a <strong>Situação</strong> (Em aberto, Todas ou uma situação) ou digite na <strong>busca</strong> (procura na ação e na origem);</li>'
    . '<li>Clique na <strong>linha</strong>: o plano de ação abre numa aba nova do portal, já filtrado na condicionante, no fornecedor ou na ocorrência;</li>'
    . '<li>No plano, atualize o <strong>Status</strong> (percentual), as datas e a evidência e clique em <strong>Salvar</strong> e em Confirmar.</li></ol>'
    . '<p><strong>Para avaliar a eficácia</strong>: clique no cartão Avaliar eficácia, abra a ação e, nas últimas colunas do plano, escolha <strong>Eficácia</strong> (Eficaz ou Não eficaz), informe a Data da avaliação (vazia = hoje) e a Observação, e salve. <em>Não eficaz</em> reabre a ação (volta para 75%); quando ela chegar de novo a 100%, a avaliação fica pendente outra vez.</p>'
    . '<p><strong>Bom saber</strong></p><ul>'
    . '<li>A lista é montada quando a aba abre; depois de atualizar o plano em outra aba, tecle F5 para vê-la atualizada;</li>'
    . '<li>Só aparecem os módulos que o seu perfil pode ver; a linha só abre o plano se você pode ver a tela do plano no módulo;</li>'
    . '<li>As ações que você deve avaliar também aparecem em Minhas pendências e no sino, e você recebe um alerta quando ficam prontas.</li></ul>';
$acoesEn = '<p><strong>What it is for</strong></p><p>The Actions tab gathers in one list the action plan actions of <strong>License conditions</strong>, <strong>Suppliers</strong> and <strong>Nonconformities</strong> you own, plus completed actions waiting for <strong>your effectiveness review</strong>.</p>'
    . '<p><strong>Statuses</strong> (cards at the top, with counts): <strong>Review effectiveness</strong> (at 100%, flagged, you are the reviewer, no result yet); <strong>Overdue</strong> (below 100%, end date passed); <strong>Due within 7 days</strong>; <strong>On time</strong>; <strong>No deadline</strong> (no end date); <strong>Done</strong> (100%).</p>'
    . '<p><strong>How to use</strong></p><ol><li>Click a <strong>card</strong> to see only that status (click again to go back to open ones);</li><li>Optionally choose the <strong>Module</strong>, change the <strong>Status</strong> filter or type in the <strong>search</strong> (action and source);</li><li>Click the <strong>row</strong>: the action plan opens in a new portal tab, already filtered;</li><li>In the plan, update <strong>Status</strong> (%), dates and evidence and click <strong>Save</strong> and Confirm.</li></ol>'
    . '<p><strong>To review effectiveness</strong>: click the Review effectiveness card, open the action and, in the last plan columns, choose <strong>Effectiveness</strong> (Effective or Not effective), enter the Review date (empty = today) and a Note, and save. <em>Not effective</em> reopens the action (back to 75%); when it reaches 100% again, the review is pending again.</p>'
    . '<p><strong>Good to know</strong></p><ul><li>The list is built when the tab opens; after updating the plan in another tab, press F5 to see it updated;</li><li>Only modules your profile can see appear; the row only opens the plan if you can see the plan screen;</li><li>Actions to review also appear in My pending items and in the bell, and you get an alert when they are ready.</li></ul>';
$acoesEs = '<p><strong>Para qué sirve</strong></p><p>La pestaña Acciones reúne en una sola lista las acciones de los planes de <strong>Condicionantes de licencias</strong>, <strong>Proveedores</strong> y <strong>No Conformidades</strong> de las que usted es responsable, y también las concluidas que esperan <strong>su evaluación de eficacia</strong>.</p>'
    . '<p><strong>Situaciones</strong> (tarjetas arriba, con la cantidad): <strong>Evaluar eficacia</strong> (al 100%, marcada, usted es el evaluador, sin resultado); <strong>Atrasada</strong> (debajo de 100%, fecha final pasada); <strong>Vence en hasta 7 días</strong>; <strong>En plazo</strong>; <strong>Sin plazo</strong> (sin fecha final); <strong>Concluida</strong> (100%).</p>'
    . '<p><strong>Cómo usar</strong></p><ol><li>Haga clic en una <strong>tarjeta</strong> para ver solo esa situación (otro clic vuelve a las abiertas);</li><li>Si quiere, elija el <strong>Módulo</strong>, cambie el filtro de <strong>Situación</strong> o escriba en la <strong>búsqueda</strong> (acción y origen);</li><li>Haga clic en la <strong>fila</strong>: el plan de acción se abre en una pestaña nueva del portal, ya filtrado;</li><li>En el plan, actualice el <strong>Estado</strong> (%), las fechas y la evidencia y haga clic en <strong>Guardar</strong> y en Confirmar.</li></ol>'
    . '<p><strong>Para evaluar la eficacia</strong>: haga clic en la tarjeta Evaluar eficacia, abra la acción y, en las últimas columnas del plan, elija <strong>Eficacia</strong> (Eficaz o No eficaz), informe la Fecha de la evaluación (vacía = hoy) y la Observación, y guarde. <em>No eficaz</em> reabre la acción (vuelve al 75%); cuando llegue de nuevo al 100%, la evaluación queda pendiente otra vez.</p>'
    . '<p><strong>Bueno saber</strong></p><ul><li>La lista se arma al abrir la pestaña; después de actualizar el plan en otra pestaña, presione F5 para verla actualizada;</li><li>Solo aparecen los módulos que su perfil puede ver; la fila solo abre el plan si usted puede ver la pantalla del plan;</li><li>Las acciones a evaluar también aparecen en Mis pendientes y en la campana, y usted recibe una alerta cuando están listas.</li></ul>';

$historicoPt = '<p><strong>Para que serve</strong></p>'
    . '<p>Mostra tudo o que aconteceu com o registro selecionado: cada <strong>inclusão</strong>, <strong>alteração</strong> e <strong>exclusão</strong>, de qualquer origem, com data e hora, quem fez e de onde veio. A gravação é automática, feita pelo banco de dados, e começa na ativação do histórico (migração 359).</p>'
    . '<p><strong>Onde fica</strong>: botão <strong>Histórico</strong> na barra das telas de Medição e monitoramento, Controles, Treinamentos, Infraestrutura, Fornecedores (e Fornecedores desativados) e Funcionários de fornecedores, inclusive nas telas Minhas desses módulos. Quem vê a tela do módulo vê o histórico.</p>'
    . '<p><strong>Como usar</strong></p><ol>'
    . '<li>Na tela do módulo, clique na <strong>linha</strong> do registro (o botão Histórico fica cinza até haver uma linha salva selecionada);</li>'
    . '<li>Clique em <strong>Histórico</strong>: abre a janela, do evento mais recente para o mais antigo;</li>'
    . '<li>Para procurar, use <strong>De</strong> / <strong>Até</strong> (datas) e escolha um campo em <strong>Todos os campos</strong>; o total de eventos aparece ao lado;</li>'
    . '<li>Clique em <strong>Exportar</strong> para baixar o que está na tela em planilha (CSV).</li></ol>'
    . '<p><strong>Como ler</strong></p><ul>'
    . '<li>Cada bloco é um evento: tipo (<em>Inclusão</em>, <em>Alteração</em> ou <em>Exclusão</em>), data e hora, usuário e origem;</li>'
    . '<li>Na alteração, cada campo mostra <strong>Antes</strong> (riscado, em vermelho) e <strong>Depois</strong> (em verde); na inclusão, os valores gravados; na exclusão, os valores que o registro tinha;</li>'
    . '<li>Origem: <em>Portal — tela</em> (alguém no portal), <em>Rotina automática / IA</em> (serviço do sistema), <em>Sincronismo (sistema antigo)</em> ou <em>Fora do portal</em> (rotina direta no banco); "(sem usuário do portal)" quando não foi uma pessoa logada.</li></ul>'
    . '<p>O histórico só mostra; não desfaz. Para voltar um valor, altere o registro na tela do módulo (a volta também fica registrada).</p>';
$historicoEn = '<p><strong>What it is for</strong></p><p>Shows everything that happened to the selected record: every <strong>creation</strong>, <strong>change</strong> and <strong>deletion</strong>, from any source, with date and time, who did it and where it came from. Recording is automatic (by the database) and starts when history was activated (migration 359).</p>'
    . '<p><strong>Where</strong>: the <strong>History</strong> button in Measurement and monitoring, Controls, Training, Infrastructure, Suppliers (and Deactivated suppliers) and Supplier employees, including the My screens of these modules. Whoever sees the module screen sees the history.</p>'
    . '<p><strong>How to use</strong></p><ol><li>In the module screen, click the record <strong>row</strong> (History is grey until a saved row is selected);</li><li>Click <strong>History</strong>: the window opens, newest event first;</li><li>To search, use <strong>From</strong> / <strong>To</strong> and choose a field in <strong>All fields</strong>;</li><li>Click <strong>Export</strong> to download what is on screen (CSV).</li></ol>'
    . '<p><strong>How to read</strong>: each block is an event (Created, Changed or Deleted, date and time, user and source). For changes, each field shows <strong>Before</strong> (struck through) and <strong>After</strong>. Source: Portal — screen, Automatic routine / AI, Sync (legacy system) or Outside the portal; "(no portal user)" when it was not a logged-in person.</p>'
    . '<p>History only shows; it does not undo. To restore a value, change the record in the module screen.</p>';
$historicoEs = '<p><strong>Para qué sirve</strong></p><p>Muestra todo lo que pasó con el registro seleccionado: cada <strong>inclusión</strong>, <strong>cambio</strong> y <strong>exclusión</strong>, de cualquier origen, con fecha y hora, quién lo hizo y de dónde vino. La grabación es automática (la hace la base de datos) y empieza con la activación del historial (migración 359).</p>'
    . '<p><strong>Dónde está</strong>: botón <strong>Historial</strong> en Medición y monitoreo, Controles, Capacitaciones, Infraestructura, Proveedores (y Proveedores desactivados) y Empleados de proveedores, incluso en las pantallas Mis de esos módulos. Quien ve la pantalla del módulo ve el historial.</p>'
    . '<p><strong>Cómo usar</strong></p><ol><li>En la pantalla del módulo, haga clic en la <strong>fila</strong> del registro (Historial queda gris hasta que haya una fila guardada seleccionada);</li><li>Haga clic en <strong>Historial</strong>: se abre la ventana, del evento más reciente al más antiguo;</li><li>Para buscar, use <strong>Desde</strong> / <strong>Hasta</strong> y elija un campo en <strong>Todos los campos</strong>;</li><li>Haga clic en <strong>Exportar</strong> para descargar lo que está en pantalla (CSV).</li></ol>'
    . '<p><strong>Cómo leer</strong>: cada bloque es un evento (Inclusión, Cambio o Exclusión, fecha y hora, usuario y origen). En el cambio, cada campo muestra <strong>Antes</strong> (tachado) y <strong>Después</strong>. Origen: Portal — pantalla, Rutina automática / IA, Sincronismo (sistema anterior) o Fuera del portal; "(sin usuario del portal)" cuando no fue una persona conectada.</p>'
    . '<p>El historial solo muestra; no deshace. Para volver un valor, cambie el registro en la pantalla del módulo.</p>';

return [
    'minhas_atividades' => ['titulo' => $L('Minhas atividades', 'My work', 'Mis actividades'), 'texto' => $L($atividadesPt, $atividadesEn, $atividadesEs)],
    'minhas_acoes' => ['titulo' => $L('Minhas atividades › Ações', 'My work › Actions', 'Mis actividades › Acciones'), 'texto' => $L($acoesPt, $acoesEn, $acoesEs)],
    'historico_registro' => ['titulo' => $L('Histórico de alterações', 'Change history', 'Historial de cambios'), 'texto' => $L($historicoPt, $historicoEn, $historicoEs)],
];
