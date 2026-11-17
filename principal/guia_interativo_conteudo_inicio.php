<?php
declare(strict_types=1);

/*
 * Migração 363 — Ajuda do assunto "Home e minhas atividades" (Guia do usuário + botão ? / F1).
 * Telas: Home › Minhas atividades (minhas_atividades.php), a aba Ações (minhas_acoes.php) e o Histórico de alterações de um
 * registro (historico_registro.php, botão Histórico das grades). Publicado por scripts/aplicar-migracao-363-ajuda-home.php.
 * Limites do Guia (GuiaUsuarioService::normalizar): "o que é" 400, item de "pode" 200, antes/depois 400, dúvida 200/600,
 * passo 400, "você deve ver" 300.
 * Alvos do "Mostre-me": css:seletor, texto:Nome, acao:save, campo:COLUNA, linha.
 */
if (!defined('GREEN_GUIA_USUARIO')) { http_response_code(404); exit; }
$L = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];
$P = static fn(array $texto, array $ver = [], string $alvo = '', string $quem = '', bool $auto = true): array
    => ['texto' => $texto, 'ver' => $ver ?: $L('', '', ''), 'alvo' => $alvo, 'tela' => '', 'quem' => $quem, 'auto' => $auto];

$telasHome = ['arquivo:home.php', 'minhas_atividades', 'arquivo:minhas_atividades.php'];
$telasAcoes = ['minhas_acoes', 'arquivo:minhas_acoes.php'];
$telasPlanos = ['liPlanodeAcao', 'arquivo:manutencaolegal_licencas_condicionantesplanodeacao.php', 'homologacaofornecedor_fornecedorxplanodeacao', 'arquivo:homologacaofornecedor_fornecedorxplanodeacao.php'];
$telasHistorico = ['historico_registro', 'arquivo:historico_registro.php'];
// telas com o botão Histórico (HistoricoAlteracoes::TABELAS)
$telasComHistorico = ['MM_PLANO_MEDICAO_MONITORAMENTO', 'CONTROLE_DOCUMENTOS', 'MEUS_CONTROLES', 'MODULOLEGAL_TREINAMENTOS', 'MEUS_TREINAMENTOS',
    'MODULOLEGAL_INFRAESTRUTURA', 'MINHAS_INFRAESTRUTURAS', 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR', 'HOMOLOGACAO_FORNECEDORES_DESATIVADOS', 'HOMOLOGACAO_FORNECEDOR_FUNCIONARIO'];

$recarregar = $L(
    'A lista é montada quando a aba abre. Depois de atualizar um registro em outra aba, tecle F5 para ver a lista atualizada.',
    'The list is built when the tab opens. After updating a record in another tab, press F5 to see the updated list.',
    'La lista se arma cuando se abre la pestaña. Después de actualizar un registro en otra pestaña, presione F5 para ver la lista actualizada.'
);

return [
    'inicio' => [
        'id' => 'inicio',
        'icone' => 'bi-house-door',
        'ativo' => true,
        'nome' => $L('Home e minhas atividades', 'Home and my work', 'Inicio y mis actividades'),
        'resumo' => $L(
            'O que é seu no dia a dia: Minhas pendências (o que precisa de ação), Minhas atividades (os registros de cada módulo pelos quais você responde), Ações (as ações dos planos de ação, inclusive as que você deve avaliar a eficácia) e o Histórico de alterações de cada registro.',
            'What is yours day to day: My pending items (what needs action), My work (the records of each module you are responsible for), Actions (the action plan actions, including the ones whose effectiveness you must review) and the Change history of each record.',
            'Lo suyo en el día a día: Mis pendientes (lo que necesita acción), Mis actividades (los registros de cada módulo de los que usted es responsable), Acciones (las acciones de los planes de acción, incluso las que debe evaluar la eficacia) y el Historial de cambios de cada registro.'
        ),
        'perfis' => ['adm', 'gestor', 'resp'],
        'perfis_nota' => $L(
            'Cada pessoa vê só os módulos que o perfil dela pode ver. "Seu" = você é o Responsável do registro, recebeu uma atribuição (você ou um grupo seu) ou está numa regra de Responsáveis e avisos do módulo.',
            'Each person sees only the modules their profile can see. "Yours" = you are the record owner, received an assignment (you or one of your groups) or are in a People responsible and alerts rule of the module.',
            'Cada persona ve solo los módulos que su perfil puede ver. "Suyo" = usted es el Responsable del registro, recibió una asignación (usted o un grupo suyo) o está en una regla de Responsables y avisos del módulo.'
        ),
        'fases' => [
            [
                'nome' => $L('Rotina do dia a dia', 'Daily routine', 'Rutina diaria'),
                'passos' => [
                    ['tipo' => 'M', 'perfil' => 'resp', 'transacao' => '',
                        'titulo' => $L('Ver o que precisa de ação', 'See what needs action', 'Ver lo que necesita acción'),
                        'texto' => $L('Comece pela Home › Minhas pendências: cada linha diz o que fazer (motivo) e o prazo. Clique na linha para abrir o registro. O sino do cabeçalho mostra o mesmo resumo.', 'Start at Home › My pending items: each row says what to do (reason) and the deadline. Click the row to open the record. The bell in the header shows the same summary.', 'Empiece por Inicio › Mis pendientes: cada fila dice qué hacer (motivo) y el plazo. Haga clic en la fila para abrir el registro. La campana del encabezado muestra el mismo resumen.'),
                        'onde' => $L('Home › Minhas pendências', 'Home › My pending items', 'Inicio › Mis pendientes')],
                    ['tipo' => 'M', 'perfil' => 'resp', 'transacao' => '',
                        'titulo' => $L('Trabalhar nos seus registros', 'Work on your records', 'Trabajar en sus registros'),
                        'texto' => $L('Em Home › Minhas atividades, cada aba é um módulo já filtrado para você, com a quantidade ao lado do nome. Você pode alterar e salvar ali mesmo; para incluir ou excluir, use a tela do módulo no menu.', 'In Home › My work, each tab is a module already filtered for you, with the count next to its name. You can change and save right there; to add or delete, use the module screen in the menu.', 'En Inicio › Mis actividades, cada pestaña es un módulo ya filtrado para usted, con la cantidad al lado del nombre. Puede cambiar y guardar ahí mismo; para incluir o excluir, use la pantalla del módulo en el menú.'),
                        'onde' => $L('Home › Minhas atividades', 'Home › My work', 'Inicio › Mis actividades')],
                    ['tipo' => 'M', 'perfil' => 'resp', 'transacao' => '',
                        'titulo' => $L('Atualizar as suas ações', 'Update your actions', 'Actualizar sus acciones'),
                        'texto' => $L('A aba Ações junta as ações dos planos de Condicionantes, Fornecedores e Não Conformidades em que você é o responsável, por situação (atrasada, vence em até 7 dias, no prazo, sem prazo, concluída). Clique na ação para abrir o plano e atualizar o percentual e a evidência.', 'The Actions tab gathers the actions of the License condition, Supplier and Nonconformity plans you own, by status (overdue, due within 7 days, on time, no deadline, done). Click the action to open the plan and update the progress and evidence.', 'La pestaña Acciones reúne las acciones de los planes de Condicionantes, Proveedores y No Conformidades de las que usted es responsable, por situación (atrasada, vence en hasta 7 días, en plazo, sin plazo, concluida). Haga clic en la acción para abrir el plan y actualizar el porcentaje y la evidencia.'),
                        'onde' => $L('Home › Minhas atividades › Ações', 'Home › My work › Actions', 'Inicio › Mis actividades › Acciones')],
                    ['tipo' => 'M', 'perfil' => 'resp', 'transacao' => '',
                        'titulo' => $L('Avaliar a eficácia', 'Review effectiveness', 'Evaluar la eficacia'),
                        'texto' => $L('Quando uma ação marcada com "Avaliar eficácia? = Sim" chega a 100% e você é o avaliador, ela aparece em Ações como "Avaliar eficácia" (e em Minhas pendências, com alerta). Abra o plano e registre Eficaz ou Não eficaz; Não eficaz reabre a ação.', 'When an action flagged "Review effectiveness? = Yes" reaches 100% and you are the reviewer, it appears in Actions as "Review effectiveness" (and in My pending items, with an alert). Open the plan and record Effective or Not effective; Not effective reopens the action.', 'Cuando una acción marcada con "¿Evaluar eficacia? = Sí" llega al 100% y usted es el evaluador, aparece en Acciones como "Evaluar eficacia" (y en Mis pendientes, con alerta). Abra el plan y registre Eficaz o No eficaz; No eficaz reabre la acción.'),
                        'onde' => $L('Home › Minhas atividades › Ações › plano de ação', 'Home › My work › Actions › action plan', 'Inicio › Mis actividades › Acciones › plan de acción')],
                    ['tipo' => 'M', 'perfil' => 'resp', 'transacao' => '',
                        'titulo' => $L('Conferir o que mudou num registro', 'Check what changed in a record', 'Consultar qué cambió en un registro'),
                        'texto' => $L('Nas telas de Medição, Controles, Treinamentos, Infraestrutura, Fornecedores e Funcionários de fornecedores, selecione a linha e clique em Histórico: aparecem todas as inclusões, alterações e exclusões, com quem fez, quando e de onde.', 'In the Measurement, Controls, Training, Infrastructure, Suppliers and Supplier employees screens, select the row and click History: every creation, change and deletion appears, with who, when and from where.', 'En las pantallas de Medición, Controles, Capacitaciones, Infraestructura, Proveedores y Empleados de proveedores, seleccione la fila y haga clic en Historial: aparecen todas las inclusiones, cambios y exclusiones, con quién, cuándo y desde dónde.'),
                        'onde' => $L('Tela do módulo › botão Histórico', 'Module screen › History button', 'Pantalla del módulo › botón Historial')],
                ],
            ],
        ],
        'tarefas' => [
            ['titulo' => $L('Encontrar os registros que são meus em cada módulo', 'Find my records in each module', 'Encontrar mis registros en cada módulo'), 'transacao' => '', 'passos' => [
                $L('Na Home, clique na aba Minhas atividades.', 'On Home, click the My work tab.', 'En Inicio, haga clic en la pestaña Mis actividades.'),
                $L('Espere a contagem: as abas ficam em ordem, da que tem mais registros para a que tem menos.', 'Wait for the count: tabs are sorted from the one with most records to the one with fewest.', 'Espere el conteo: las pestañas quedan en orden, de la que tiene más registros a la que tiene menos.'),
                $L('Clique na aba do módulo (ex.: Licenças, Controles, Treinamentos).', 'Click the module tab (e.g. Licenses, Controls, Training).', 'Haga clic en la pestaña del módulo (ej.: Licencias, Controles, Capacitaciones).'),
                $L('Altere o que precisar na linha e clique em Salvar. Para incluir ou excluir, use a tela do módulo no menu.', 'Change what you need in the row and click Save. To add or delete, use the module screen in the menu.', 'Cambie lo que necesite en la fila y haga clic en Guardar. Para incluir o excluir, use la pantalla del módulo en el menú.'),
            ]],
            ['titulo' => $L('Atualizar uma ação do plano de ação', 'Update an action plan action', 'Actualizar una acción del plan de acción'), 'transacao' => '', 'passos' => [
                $L('Na Home, abra Minhas atividades e clique na aba Ações.', 'On Home, open My work and click the Actions tab.', 'En Inicio, abra Mis actividades y haga clic en la pestaña Acciones.'),
                $L('Clique no cartão Atrasada (ou Vence em até 7 dias) para ver primeiro o que está mais urgente.', 'Click the Overdue card (or Due within 7 days) to see the most urgent first.', 'Haga clic en la tarjeta Atrasada (o Vence en hasta 7 días) para ver primero lo más urgente.'),
                $L('Clique na linha da ação: o plano de ação abre numa aba nova, já filtrado.', 'Click the action row: the action plan opens in a new tab, already filtered.', 'Haga clic en la fila de la acción: el plan de acción se abre en una pestaña nueva, ya filtrado.'),
                $L('Atualize o Status (%), as datas e a evidência e clique em Salvar e em Confirmar.', 'Update Status (%), dates and evidence and click Save and Confirm.', 'Actualice el Estado (%), las fechas y la evidencia y haga clic en Guardar y en Confirmar.'),
            ]],
            ['titulo' => $L('Avaliar a eficácia de uma ação', 'Review the effectiveness of an action', 'Evaluar la eficacia de una acción'), 'transacao' => '', 'passos' => [
                $L('Em Minhas atividades › Ações, clique no cartão Avaliar eficácia.', 'In My work › Actions, click the Review effectiveness card.', 'En Mis actividades › Acciones, haga clic en la tarjeta Evaluar eficacia.'),
                $L('Clique na linha da ação para abrir o plano de ação.', 'Click the action row to open the action plan.', 'Haga clic en la fila de la acción para abrir el plan de acción.'),
                $L('Na linha da ação, vá até as últimas colunas e escolha Eficácia: Eficaz ou Não eficaz.', 'In the action row, go to the last columns and choose Effectiveness: Effective or Not effective.', 'En la fila de la acción, vaya a las últimas columnas y elija Eficacia: Eficaz o No eficaz.'),
                $L('Se quiser, informe a Data da avaliação (vazia = hoje) e a Observação.', 'Optionally enter the Review date (empty = today) and a Note.', 'Si quiere, informe la Fecha de la evaluación (vacía = hoy) y la Observación.'),
                $L('Clique em Salvar e em Confirmar. Não eficaz reabre a ação em 75%; quando ela voltar a 100%, a avaliação fica pendente de novo.', 'Click Save and Confirm. Not effective reopens the action at 75%; when it reaches 100% again, the review is pending again.', 'Haga clic en Guardar y en Confirmar. No eficaz reabre la acción al 75%; cuando vuelva al 100%, la evaluación queda pendiente otra vez.'),
            ]],
            ['titulo' => $L('Ver o histórico de alterações de um registro', 'See the change history of a record', 'Ver el historial de cambios de un registro'), 'transacao' => '', 'passos' => [
                $L('Abra a tela do módulo (Medição, Controles, Treinamentos, Infraestrutura, Fornecedores ou Funcionários de fornecedores).', 'Open the module screen (Measurement, Controls, Training, Infrastructure, Suppliers or Supplier employees).', 'Abra la pantalla del módulo (Medición, Controles, Capacitaciones, Infraestructura, Proveedores o Empleados de proveedores).'),
                $L('Clique na linha do registro para selecioná-la.', 'Click the record row to select it.', 'Haga clic en la fila del registro para seleccionarla.'),
                $L('Clique em Histórico na barra de botões.', 'Click History in the button bar.', 'Haga clic en Historial en la barra de botones.'),
                $L('Use De / Até e o campo para filtrar; clique em Exportar para baixar em planilha (CSV).', 'Use From / To and the field to filter; click Export to download a spreadsheet (CSV).', 'Use Desde / Hasta y el campo para filtrar; haga clic en Exportar para descargar una planilla (CSV).'),
            ]],
        ],
        'menu' => [
            ['caminho' => $L('Home › Minhas pendências', 'Home › My pending items', 'Inicio › Mis pendientes'), 'transacao' => '',
                'texto' => $L('O que precisa de ação agora, com motivo e prazo; clique para abrir o registro.', 'What needs action now, with reason and deadline; click to open the record.', 'Lo que necesita acción ahora, con motivo y plazo; haga clic para abrir el registro.')],
            ['caminho' => $L('Home › Minhas atividades', 'Home › My work', 'Inicio › Mis actividades'), 'transacao' => '',
                'texto' => $L('Uma aba por módulo, já filtrada para você; altera e salva sem sair da Home.', 'One tab per module, already filtered for you; change and save without leaving Home.', 'Una pestaña por módulo, ya filtrada para usted; cambia y guarda sin salir de Inicio.')],
            ['caminho' => $L('Home › Minhas atividades › Ações', 'Home › My work › Actions', 'Inicio › Mis actividades › Acciones'), 'transacao' => '',
                'texto' => $L('Todas as suas ações de planos de ação, por situação; também as que você deve avaliar a eficácia.', 'All your action plan actions, by status; also the ones whose effectiveness you must review.', 'Todas sus acciones de planes de acción, por situación; también las que debe evaluar la eficacia.')],
            ['caminho' => $L('Tela do módulo › Histórico', 'Module screen › History', 'Pantalla del módulo › Historial'), 'transacao' => '',
                'texto' => $L('Todas as alterações do registro selecionado, campo a campo.', 'All changes of the selected record, field by field.', 'Todos los cambios del registro seleccionado, campo por campo.')],
        ],
        'termos' => [
            ['termo' => $L('Meu registro', 'My record', 'Mi registro'), 'texto' => $L('Registro em que você é o Responsável, recebeu uma atribuição (você ou um grupo seu) ou está numa regra de Responsáveis e avisos do módulo.', 'A record where you are the owner, received an assignment (you or one of your groups) or are in a People responsible and alerts rule of the module.', 'Registro en el que usted es el Responsable, recibió una asignación (usted o un grupo suyo) o está en una regla de Responsables y avisos del módulo.')],
            ['termo' => $L('Situação da ação', 'Action status', 'Situación de la acción'), 'texto' => $L('Calculada pelo percentual e pelo prazo: Concluída (100%), Atrasada (prazo vencido), Vence em até 7 dias, No prazo, Sem prazo (sem data final). Avaliar eficácia = concluída esperando a sua avaliação.', 'Calculated from progress and deadline: Done (100%), Overdue (deadline passed), Due within 7 days, On time, No deadline (no end date). Review effectiveness = done and waiting for your review.', 'Calculada por el porcentaje y el plazo: Concluida (100%), Atrasada (plazo vencido), Vence en hasta 7 días, En plazo, Sin plazo (sin fecha final). Evaluar eficacia = concluida esperando su evaluación.')],
            ['termo' => $L('Avaliador da eficácia', 'Effectiveness reviewer', 'Evaluador de la eficacia'), 'texto' => $L('Quem registra Eficaz ou Não eficaz. É o escolhido na ação; vazio = o Responsável da condicionante ou do fornecedor; sem ninguém, o responsável da ação.', 'Who records Effective or Not effective. It is the one chosen in the action; empty = the condition or supplier owner; with nobody, the action owner.', 'Quien registra Eficaz o No eficaz. Es el elegido en la acción; vacío = el Responsable de la condicionante o del proveedor; sin nadie, el responsable de la acción.')],
            ['termo' => $L('Origem (histórico)', 'Source (history)', 'Origen (historial)'), 'texto' => $L('De onde veio a alteração: Portal (com o nome da tela), Rotina automática / IA, Sincronismo com o sistema antigo ou Fora do portal (rotina do banco).', 'Where the change came from: Portal (with the screen name), Automatic routine / AI, Sync with the legacy system or Outside the portal (database routine).', 'De dónde vino el cambio: Portal (con el nombre de la pantalla), Rutina automática / IA, Sincronismo con el sistema anterior o Fuera del portal (rutina de la base).')],
        ],
        'guias' => [
            [
                'id' => 'inicio-minhas-atividades',
                'titulo' => $L('Encontrar e atualizar os meus registros de cada módulo', 'Find and update my records in each module', 'Encontrar y actualizar mis registros de cada módulo'),
                'resumo' => $L('Usar Home › Minhas atividades para ver, módulo a módulo, só o que é seu e atualizar sem procurar no menu.', 'Use Home › My work to see, module by module, only what is yours and update it without searching the menu.', 'Usar Inicio › Mis actividades para ver, módulo por módulo, solo lo suyo y actualizarlo sin buscar en el menú.'),
                'antes' => $L('Cada aba só aparece se o seu perfil pode ver a tela do módulo.', 'Each tab only appears if your profile can see the module screen.', 'Cada pestaña solo aparece si su perfil puede ver la pantalla del módulo.'),
                'depois' => $recarregar,
                'perfis' => ['adm', 'gestor', 'resp'], 'telas' => array_merge($telasHome, $telasAcoes),
                'passos' => [
                    $P($L('No topo da Home, clique na aba **Minhas atividades**.', 'At the top of Home, click the **My work** tab.', 'En la parte superior de Inicio, haga clic en la pestaña **Mis actividades**.'),
                        $L('Aparecem as abas dos módulos, com a quantidade de registros ao lado de cada nome.', 'The module tabs appear, with the record count next to each name.', 'Aparecen las pestañas de los módulos, con la cantidad de registros al lado de cada nombre.'), 'css:[data-home-tab="minhas-atividades"]'),
                    $P($L('Clique na aba do módulo em que quer trabalhar. A primeira é a que tem mais registros.', 'Click the tab of the module you want to work on. The first one has the most records.', 'Haga clic en la pestaña del módulo en el que quiere trabajar. La primera es la que tiene más registros.'),
                        $L('A tela do módulo abre logo abaixo, já filtrada para você.', 'The module screen opens right below, already filtered for you.', 'La pantalla del módulo se abre justo debajo, ya filtrada para usted.'), 'css:.ma-tab'),
                    $P($L('Altere o que precisar na linha (datas, situação, observação…) e clique em **Salvar**.', 'Change what you need in the row (dates, status, notes…) and click **Save**.', 'Cambie lo que necesite en la fila (fechas, situación, observación…) y haga clic en **Guardar**.'),
                        $L('Aparece "Registro salvo com sucesso.". Incluir e excluir não ficam aqui: use a tela do módulo no menu.', '"Record saved successfully." appears. Adding and deleting are not here: use the module screen in the menu.', 'Aparece "Registro guardado correctamente.". Incluir y excluir no están aquí: use la pantalla del módulo en el menú.'), 'acao:save'),
                ],
            ],
            [
                'id' => 'inicio-minhas-acoes',
                'titulo' => $L('Ver e atualizar as minhas ações dos planos de ação', 'See and update my action plan actions', 'Ver y actualizar mis acciones de los planes de acción'),
                'resumo' => $L('Achar as ações atrasadas ou perto do prazo e abrir o plano para atualizar o percentual e a evidência.', 'Find overdue or nearly due actions and open the plan to update progress and evidence.', 'Encontrar las acciones atrasadas o cerca del plazo y abrir el plan para actualizar el porcentaje y la evidencia.'),
                'antes' => $L('Entram as ações de Condicionantes, Fornecedores e Não Conformidades em que você é o responsável da ação.', 'It includes the License condition, Supplier and Nonconformity actions where you are the action owner.', 'Entran las acciones de Condicionantes, Proveedores y No Conformidades en las que usted es el responsable de la acción.'),
                'depois' => $recarregar,
                'perfis' => ['adm', 'gestor', 'resp'], 'telas' => array_merge($telasHome, $telasAcoes),
                'passos' => [
                    $P($L('No topo da Home, clique na aba **Minhas atividades**.', 'At the top of Home, click the **My work** tab.', 'En la parte superior de Inicio, haga clic en la pestaña **Mis actividades**.'), [], 'css:[data-home-tab="minhas-atividades"]'),
                    $P($L('Clique na aba **Ações**.', 'Click the **Actions** tab.', 'Haga clic en la pestaña **Acciones**.'),
                        $L('Aparecem os cartões por situação e a lista das ações em aberto.', 'The status cards and the list of open actions appear.', 'Aparecen las tarjetas por situación y la lista de las acciones abiertas.'), 'css:[data-ma-aba="acoes"]'),
                    $P($L('Clique no cartão **Atrasada** para ver só as ações com prazo vencido (clique de novo para voltar).', 'Click the **Overdue** card to see only the actions past their deadline (click again to go back).', 'Haga clic en la tarjeta **Atrasada** para ver solo las acciones con plazo vencido (haga clic de nuevo para volver).'),
                        $L('A lista mostra só as atrasadas e o cartão fica marcado.', 'The list shows only the overdue ones and the card is highlighted.', 'La lista muestra solo las atrasadas y la tarjeta queda marcada.'), 'css:.mac-card[data-sit="atrasada"]'),
                    $P($L('Se quiser, escolha o **Módulo** ou digite uma palavra na busca para achar a ação.', 'If you want, choose the **Module** or type a word in the search to find the action.', 'Si quiere, elija el **Módulo** o escriba una palabra en la búsqueda para encontrar la acción.'), [], 'css:#macModulo'),
                    $P($L('Clique na linha da ação.', 'Click the action row.', 'Haga clic en la fila de la acción.'),
                        $L('O plano de ação abre numa aba nova do portal, já filtrado na condicionante, no fornecedor ou na ocorrência.', 'The action plan opens in a new portal tab, already filtered on the condition, supplier or occurrence.', 'El plan de acción se abre en una pestaña nueva del portal, ya filtrado en la condicionante, el proveedor o la ocurrencia.'), 'css:.mac-tab tbody tr[data-url]'),
                    $P($L('No plano, atualize o **Status** (percentual), as datas e a evidência e clique em **Salvar** e em **Confirmar**.', 'In the plan, update **Status** (percentage), dates and evidence and click **Save** and **Confirm**.', 'En el plan, actualice el **Estado** (porcentaje), las fechas y la evidencia y haga clic en **Guardar** y en **Confirmar**.'),
                        $L('Aparece "Registro salvo com sucesso.". Com 100%, a ação passa a Concluída.', '"Record saved successfully." appears. At 100%, the action becomes Done.', 'Aparece "Registro guardado correctamente.". Con 100%, la acción pasa a Concluida.'), '', '', false),
                ],
            ],
            [
                'id' => 'inicio-avaliar-eficacia',
                'titulo' => $L('Avaliar a eficácia de uma ação concluída', 'Review the effectiveness of a completed action', 'Evaluar la eficacia de una acción concluida'),
                'resumo' => $L('Registrar Eficaz ou Não eficaz numa ação marcada para avaliação, quando você é o avaliador.', 'Record Effective or Not effective on an action flagged for review, when you are the reviewer.', 'Registrar Eficaz o No eficaz en una acción marcada para evaluación, cuando usted es el evaluador.'),
                'antes' => $L('Só o avaliador da ação, o ADMINISTRADOR e o ADM DA EMPRESA registram, e só com a ação a 100%. Você recebe um alerta quando a ação fica pronta para avaliar.', 'Only the action reviewer, ADMINISTRADOR and ADM DA EMPRESA can record it, and only at 100%. You get an alert when the action is ready to review.', 'Solo el evaluador de la acción, el ADMINISTRADOR y el ADM DA EMPRESA registran, y solo con la acción al 100%. Usted recibe una alerta cuando la acción está lista para evaluar.'),
                'depois' => $L('Eficaz encerra a avaliação. Não eficaz reabre a ação (volta para 75%) para o responsável continuar; quando ela chegar de novo a 100%, a avaliação fica pendente outra vez. Toda avaliação fica no histórico.', 'Effective closes the review. Not effective reopens the action (back to 75%) for the owner to continue; when it reaches 100% again, the review is pending again. Every review is kept in the history.', 'Eficaz cierra la evaluación. No eficaz reabre la acción (vuelve al 75%) para que el responsable continúe; cuando llegue de nuevo al 100%, la evaluación queda pendiente otra vez. Toda evaluación queda en el historial.'),
                'perfis' => ['adm', 'gestor', 'resp'], 'telas' => array_merge($telasHome, $telasAcoes, $telasPlanos),
                'passos' => [
                    $P($L('Na Home, abra **Minhas atividades** e a aba **Ações**.', 'On Home, open **My work** and the **Actions** tab.', 'En Inicio, abra **Mis actividades** y la pestaña **Acciones**.'), [], 'css:[data-ma-aba="acoes"]'),
                    $P($L('Clique no cartão **Avaliar eficácia**.', 'Click the **Review effectiveness** card.', 'Haga clic en la tarjeta **Evaluar eficacia**.'),
                        $L('A lista mostra só as ações concluídas que esperam a sua avaliação.', 'The list shows only the completed actions waiting for your review.', 'La lista muestra solo las acciones concluidas que esperan su evaluación.'), 'css:.mac-card[data-sit="avaliar"]'),
                    $P($L('Clique na linha da ação.', 'Click the action row.', 'Haga clic en la fila de la acción.'),
                        $L('O plano de ação abre numa aba nova, com a coluna Situação da eficácia = "Avaliar eficácia".', 'The action plan opens in a new tab, with the Effectiveness status column = "Review effectiveness".', 'El plan de acción se abre en una pestaña nueva, con la columna Situación de la eficacia = "Evaluar eficacia".'), 'css:.mac-tab tbody tr[data-url]'),
                    $P($L('Na linha da ação, em **Eficácia**, escolha Eficaz ou Não eficaz.', 'In the action row, in **Effectiveness**, choose Effective or Not effective.', 'En la fila de la acción, en **Eficacia**, elija Eficaz o No eficaz.'), [], 'campo:EFICACIA'),
                    $P($L('Se quiser, informe a **Data da avaliação** (vazia = hoje) e escreva a **Observação da avaliação**.', 'If you want, enter the **Review date** (empty = today) and write the **Review note**.', 'Si quiere, informe la **Fecha de la evaluación** (vacía = hoy) y escriba la **Observación de la evaluación**.'), [], 'campo:EFICACIA_OBS'),
                    $P($L('Clique em **Salvar** e depois em **Confirmar**.', 'Click **Save** and then **Confirm**.', 'Haga clic en **Guardar** y después en **Confirmar**.'),
                        $L('A Situação da eficácia mostra "Eficaz em …" ou "Não eficaz em …: ação reaberta" (e a ação volta para 75%).', 'The Effectiveness status shows "Effective on …" or "Not effective on …: action reopened" (and the action goes back to 75%).', 'La Situación de la eficacia muestra "Eficaz el …" o "No eficaz el …: acción reabierta" (y la acción vuelve al 75%).'), 'acao:save'),
                ],
            ],
            [
                'id' => 'inicio-historico',
                'titulo' => $L('Ver o histórico de alterações de um registro', 'See the change history of a record', 'Ver el historial de cambios de un registro'),
                'resumo' => $L('Descobrir quem incluiu, alterou ou excluiu um registro, quando, de onde e o valor de antes e de depois.', 'Find out who created, changed or deleted a record, when, from where and the before and after values.', 'Descubrir quién incluyó, cambió o excluyó un registro, cuándo, desde dónde y el valor de antes y de después.'),
                'antes' => $L('O botão Histórico existe em Medição e monitoramento, Controles, Treinamentos, Infraestrutura, Fornecedores (e desativados) e Funcionários de fornecedores, e nas telas Minhas desses módulos.', 'The History button exists in Measurement and monitoring, Controls, Training, Infrastructure, Suppliers (and deactivated) and Supplier employees, and in the My screens of these modules.', 'El botón Historial existe en Medición y monitoreo, Controles, Capacitaciones, Infraestructura, Proveedores (y desactivados) y Empleados de proveedores, y en las pantallas Mis de esos módulos.'),
                'depois' => $L('O histórico só mostra; não desfaz alterações. Para voltar um valor, altere o registro na tela do módulo.', 'History only shows; it does not undo changes. To restore a value, change the record in the module screen.', 'El historial solo muestra; no deshace cambios. Para volver un valor, cambie el registro en la pantalla del módulo.'),
                'perfis' => ['adm', 'gestor', 'resp'], 'telas' => array_merge($telasComHistorico, $telasHistorico),
                'passos' => [
                    $P($L('Na tela do módulo, clique na linha do registro para selecioná-la.', 'In the module screen, click the record row to select it.', 'En la pantalla del módulo, haga clic en la fila del registro para seleccionarla.'),
                        $L('A linha fica destacada e o botão Histórico fica disponível.', 'The row is highlighted and the History button becomes available.', 'La fila queda resaltada y el botón Historial queda disponible.'), 'linha'),
                    $P($L('Clique em **Histórico** na barra de botões.', 'Click **History** in the button bar.', 'Haga clic en **Historial** en la barra de botones.'),
                        $L('Abre a janela Histórico de alterações, do mais recente para o mais antigo.', 'The Change history window opens, newest first.', 'Se abre la ventana Historial de cambios, del más reciente al más antiguo.'), 'css:[data-historico-registro]'),
                    $P($L('Cada bloco é um evento: tipo (Inclusão, Alteração ou Exclusão), data e hora, quem fez e a origem; abaixo, cada campo com o valor **Antes** e **Depois**.', 'Each block is an event: type (Created, Changed or Deleted), date and time, who did it and the source; below, each field with the **Before** and **After** value.', 'Cada bloque es un evento: tipo (Inclusión, Cambio o Exclusión), fecha y hora, quién lo hizo y el origen; abajo, cada campo con el valor **Antes** y **Después**.'), [], 'css:.hr-ev', '', false),
                    $P($L('Para procurar, use **De** / **Até** (datas) e escolha um campo em **Todos os campos**.', 'To search, use **From** / **To** (dates) and choose a field in **All fields**.', 'Para buscar, use **Desde** / **Hasta** (fechas) y elija un campo en **Todos los campos**.'),
                        $L('Ficam só os eventos do período e do campo escolhidos; o total aparece ao lado.', 'Only the events of the chosen period and field remain; the total appears beside.', 'Quedan solo los eventos del período y del campo elegidos; el total aparece al lado.'), 'css:#hrCampo'),
                    $P($L('Clique em **Exportar** para baixar o que está na tela em planilha (CSV).', 'Click **Export** to download what is on screen as a spreadsheet (CSV).', 'Haga clic en **Exportar** para descargar lo que está en pantalla como planilla (CSV).'), [], 'css:#hrExportar'),
                ],
            ],
        ],
        'telas' => [
            [
                'chaves' => $telasAcoes,
                'oque' => $L('Ações: todas as ações dos planos de ação de Condicionantes, Fornecedores e Não Conformidades em que você é o responsável, mais as ações concluídas que esperam a sua avaliação da eficácia. Cada linha mostra situação, módulo, ação, origem, início, prazo e percentual realizado.', 'Actions: every action of the License condition, Supplier and Nonconformity plans you own, plus completed actions waiting for your effectiveness review. Each row shows status, module, action, source, start, deadline and progress.', 'Acciones: todas las acciones de los planes de Condicionantes, Proveedores y No Conformidades de las que usted es responsable, más las concluidas que esperan su evaluación de eficacia. Cada fila muestra situación, módulo, acción, origen, inicio, plazo y porcentaje realizado.'),
                'pode' => [
                    ['texto' => $L('Filtrar pela situação clicando num cartão (clique de novo para voltar às em aberto)', 'Filter by status by clicking a card (click again to go back to open ones)', 'Filtrar por situación haciendo clic en una tarjeta (otro clic vuelve a las abiertas)'), 'alvo' => 'css:.mac-card[data-sit="atrasada"]'],
                    ['texto' => $L('Ver as ações que você deve avaliar a eficácia', 'See the actions whose effectiveness you must review', 'Ver las acciones cuya eficacia debe evaluar'), 'alvo' => 'css:.mac-card[data-sit="avaliar"]'],
                    ['texto' => $L('Filtrar por módulo', 'Filter by module', 'Filtrar por módulo'), 'alvo' => 'css:#macModulo'],
                    ['texto' => $L('Ver todas, só as em aberto ou uma situação', 'See all, only open ones or one status', 'Ver todas, solo abiertas o una situación'), 'alvo' => 'css:#macSituacao'],
                    ['texto' => $L('Buscar uma palavra na ação ou na origem', 'Search a word in the action or source', 'Buscar una palabra en la acción o el origen'), 'alvo' => 'css:#macBusca'],
                    ['texto' => $L('Abrir o plano de ação clicando na linha', 'Open the action plan by clicking the row', 'Abrir el plan de acción haciendo clic en la fila'), 'alvo' => 'css:.mac-tab tbody tr[data-url]'],
                ],
                'antes' => $L('A lista abre com as ações em aberto, da mais urgente para a menos urgente: Avaliar eficácia, Atrasada, Vence em até 7 dias, No prazo, Sem prazo.', 'The list opens with open actions, most urgent first: Review effectiveness, Overdue, Due within 7 days, On time, No deadline.', 'La lista se abre con las acciones abiertas, de la más urgente a la menos: Evaluar eficacia, Atrasada, Vence en hasta 7 días, En plazo, Sin plazo.'),
                'depois' => $L('A ação é atualizada no plano de ação do módulo (aba nova). A lista é montada quando a aba abre: tecle F5 para vê-la atualizada.', 'The action is updated in the module action plan (new tab). The list is built when the tab opens: press F5 to see it updated.', 'La acción se actualiza en el plan de acción del módulo (pestaña nueva). La lista se arma al abrir la pestaña: presione F5 para verla actualizada.'),
                'duvidas' => [
                    ['p' => $L('Por que uma ação minha não aparece?', 'Why does one of my actions not appear?', '¿Por qué no aparece una acción mía?'),
                        'r' => $L('Aqui entram as ações em que você é o Responsável da ação (coluna Responsável do plano). Confira também o filtro de Situação: a lista abre só com as em aberto; escolha "Todas" para ver as concluídas. O módulo só aparece se o seu perfil pode ver a tela dele.', 'This list has the actions where you are the action Owner (Responsible column of the plan). Also check the Status filter: the list opens with open ones only; choose "All" to see done ones. The module only appears if your profile can see its screen.', 'Aquí entran las acciones en las que usted es el Responsable de la acción (columna Responsable del plan). Revise también el filtro de Situación: la lista abre solo con las abiertas; elija "Todas" para ver las concluidas. El módulo solo aparece si su perfil puede ver su pantalla.')],
                    ['p' => $L('Como a situação é calculada?', 'How is the status calculated?', '¿Cómo se calcula la situación?'),
                        'r' => $L('100% = Concluída. Abaixo de 100%: sem data final = Sem prazo; data final já passou = Atrasada; vence nos próximos 7 dias = Vence em até 7 dias; senão, No prazo. Avaliar eficácia = ação a 100%, marcada para avaliação, em que você é o avaliador e ainda sem resultado.', '100% = Done. Below 100%: no end date = No deadline; end date passed = Overdue; due in the next 7 days = Due within 7 days; otherwise On time. Review effectiveness = action at 100%, flagged for review, where you are the reviewer and with no result yet.', '100% = Concluida. Debajo de 100%: sin fecha final = Sin plazo; fecha final pasada = Atrasada; vence en los próximos 7 días = Vence en hasta 7 días; si no, En plazo. Evaluar eficacia = acción al 100%, marcada para evaluación, en la que usted es el evaluador y aún sin resultado.')],
                    ['p' => $L('Atualizei a ação e a lista não mudou.', 'I updated the action and the list did not change.', 'Actualicé la acción y la lista no cambió.'),
                        'r' => $L('A lista é montada quando a aba abre. Tecle F5 (recarregar) para ver a situação nova.', 'The list is built when the tab opens. Press F5 (reload) to see the new status.', 'La lista se arma cuando se abre la pestaña. Presione F5 (recargar) para ver la situación nueva.')],
                    ['p' => $L('A linha não abre o plano.', 'The row does not open the plan.', 'La fila no abre el plan.'),
                        'r' => $L('Só abre se o seu perfil pode ver a tela do plano no módulo (ex.: Licenças para as condicionantes). Peça a permissão ao administrador.', 'It only opens if your profile can see the plan screen in the module (e.g. Licenses for conditions). Ask the administrator for permission.', 'Solo abre si su perfil puede ver la pantalla del plan en el módulo (ej.: Licencias para las condicionantes). Pida el permiso al administrador.')],
                ],
            ],
            [
                'chaves' => $telasHome,
                'oque' => $L('Minhas atividades: uma aba por módulo (Requisitos, Licenças, Condicionantes, Medições, Controles, Treinamentos, Infraestruturas, Fornecedores, Funcionários, Ocorrências e Ações), cada uma com a tela do módulo já filtrada para o que é seu. O número ao lado do nome é a quantidade de registros.', 'My work: one tab per module (Requirements, Licenses, Conditions, Measurements, Controls, Training, Infrastructure, Suppliers, Employees, Occurrences and Actions), each with the module screen already filtered to what is yours. The number next to the name is the record count.', 'Mis actividades: una pestaña por módulo (Requisitos, Licencias, Condicionantes, Mediciones, Controles, Capacitaciones, Infraestructuras, Proveedores, Empleados, Ocurrencias y Acciones), cada una con la pantalla del módulo ya filtrada a lo suyo. El número al lado del nombre es la cantidad de registros.'),
                'pode' => [
                    ['texto' => $L('Trocar de módulo clicando na aba', 'Switch module by clicking the tab', 'Cambiar de módulo haciendo clic en la pestaña'), 'alvo' => 'css:.ma-tab'],
                    ['texto' => $L('Ver as suas ações dos planos de ação', 'See your action plan actions', 'Ver sus acciones de los planes de acción'), 'alvo' => 'css:[data-ma-aba="acoes"]'],
                    ['texto' => $L('Alterar o registro na linha e salvar (incluir e excluir ficam na tela do módulo)', 'Change the record in the row and save (adding and deleting are in the module screen)', 'Cambiar el registro en la fila y guardar (incluir y excluir están en la pantalla del módulo)'), 'alvo' => 'acao:save'],
                ],
                'antes' => $L('Só aparecem as abas dos módulos que o seu perfil pode ver (e que a empresa contratou). As abas ficam em ordem, da que tem mais registros para a que tem menos.', 'Only the tabs of modules your profile can see (and the company contracted) appear. Tabs are sorted from most records to fewest.', 'Solo aparecen las pestañas de los módulos que su perfil puede ver (y que la empresa contrató). Las pestañas quedan en orden, de la que tiene más registros a la que tiene menos.'),
                'depois' => $L('O que você salvar aqui vale para o registro do módulo (é a mesma tela, só filtrada). As quantidades são contadas quando a aba Minhas atividades abre.', 'What you save here applies to the module record (it is the same screen, just filtered). Counts are taken when the My work tab opens.', 'Lo que guarde aquí vale para el registro del módulo (es la misma pantalla, solo filtrada). Las cantidades se cuentan al abrir la pestaña Mis actividades.'),
                'duvidas' => [
                    ['p' => $L('O que conta como "meu"?', 'What counts as "mine"?', '¿Qué cuenta como "mío"?'),
                        'r' => $L('O registro em que você é o Responsável, em que recebeu uma atribuição (você ou um grupo do qual faz parte) ou que se encaixa numa regra de Responsáveis e avisos do módulo com o seu nome ou grupo. Em Ocorrências, as não conformidades em que você está envolvido; em Ações, as ações em que você é o responsável.', 'The record where you are the Owner, received an assignment (you or a group you belong to) or that matches a People responsible and alerts rule of the module with your name or group. In Occurrences, the nonconformities you are involved in; in Actions, the actions you own.', 'El registro en el que usted es el Responsable, recibió una asignación (usted o un grupo del que forma parte) o que encaja en una regla de Responsables y avisos del módulo con su nombre o grupo. En Ocurrencias, las no conformidades en las que participa; en Acciones, las acciones de las que es responsable.')],
                    ['p' => $L('Falta uma aba de módulo.', 'A module tab is missing.', 'Falta una pestaña de módulo.'),
                        'r' => $L('A aba só aparece se o seu perfil pode ver a tela do módulo e a empresa contratou o módulo. Peça ao administrador (Segurança › Permissões).', 'The tab only appears if your profile can see the module screen and the company contracted it. Ask the administrator (Security › Permissions).', 'La pestaña solo aparece si su perfil puede ver la pantalla del módulo y la empresa lo contrató. Pida al administrador (Seguridad › Permisos).')],
                    ['p' => $L('Por que não consigo incluir nem excluir aqui?', 'Why can I not add or delete here?', '¿Por qué no puedo incluir ni excluir aquí?'),
                        'r' => $L('Minhas atividades é para acompanhar e atualizar o que já é seu. Para incluir ou excluir, abra a tela do módulo pelo menu, onde valem as permissões do seu perfil.', 'My work is for following up and updating what is already yours. To add or delete, open the module screen from the menu, where your profile permissions apply.', 'Mis actividades es para acompañar y actualizar lo que ya es suyo. Para incluir o excluir, abra la pantalla del módulo desde el menú, donde valen los permisos de su perfil.')],
                    ['p' => $L('O número da aba aparece como "?".', 'The tab number shows "?".', 'El número de la pestaña aparece como "?".'),
                        'r' => $L('A contagem daquela aba não respondeu (tela do módulo indisponível ou demorada). A aba funciona normalmente; tecle F5 para contar de novo.', 'That tab count did not answer (module screen unavailable or slow). The tab works normally; press F5 to count again.', 'El conteo de esa pestaña no respondió (pantalla del módulo no disponible o lenta). La pestaña funciona normalmente; presione F5 para contar de nuevo.')],
                ],
            ],
            [
                'chaves' => $telasHistorico,
                'oque' => $L('Histórico de alterações do registro selecionado: cada inclusão, alteração e exclusão, com data e hora, quem fez e de onde veio (portal, rotina automática, sincronismo com o sistema antigo). Em cada evento, os campos com o valor Antes (riscado) e Depois.', 'Change history of the selected record: every creation, change and deletion, with date and time, who did it and where it came from (portal, automatic routine, legacy sync). In each event, the fields with the Before (struck through) and After value.', 'Historial de cambios del registro seleccionado: cada inclusión, cambio y exclusión, con fecha y hora, quién lo hizo y de dónde vino (portal, rutina automática, sincronismo con el sistema anterior). En cada evento, los campos con el valor Antes (tachado) y Después.'),
                'pode' => [
                    ['texto' => $L('Filtrar por período (De / Até)', 'Filter by period (From / To)', 'Filtrar por período (Desde / Hasta)'), 'alvo' => 'css:#hrDe'],
                    ['texto' => $L('Ver só um campo', 'See only one field', 'Ver solo un campo'), 'alvo' => 'css:#hrCampo'],
                    ['texto' => $L('Exportar o que está na tela (CSV)', 'Export what is on screen (CSV)', 'Exportar lo que está en pantalla (CSV)'), 'alvo' => 'css:#hrExportar'],
                ],
                'antes' => $L('Quem vê a tela do módulo vê o histórico do registro. O registro começa na ativação do histórico (migração 359): o que mudou antes disso não aparece.', 'Whoever can see the module screen can see the record history. Recording starts when history was activated (migration 359): earlier changes do not appear.', 'Quien ve la pantalla del módulo ve el historial del registro. El registro empieza con la activación del historial (migración 359): lo que cambió antes no aparece.'),
                'depois' => $L('O histórico só mostra; não desfaz. Para voltar um valor, altere o registro na tela do módulo (a volta também entra no histórico).', 'History only shows; it does not undo. To restore a value, change the record in the module screen (that also goes into history).', 'El historial solo muestra; no deshace. Para volver un valor, cambie el registro en la pantalla del módulo (eso también entra en el historial).'),
                'duvidas' => [
                    ['p' => $L('O que significa a origem?', 'What does the source mean?', '¿Qué significa el origen?'),
                        'r' => $L('Portal — nome da tela: alteração feita por alguém no portal. Rotina automática / IA: serviço do sistema (ex.: análise de documento). Sincronismo (sistema antigo): dado que veio do sistema antigo enquanto ele ainda é sincronizado. Fora do portal: rotina direta no banco de dados.', 'Portal — screen name: change made by someone in the portal. Automatic routine / AI: a system service (e.g. document analysis). Sync (legacy system): data that came from the legacy system while it is still synced. Outside the portal: a routine run directly on the database.', 'Portal — nombre de la pantalla: cambio hecho por alguien en el portal. Rutina automática / IA: servicio del sistema (ej.: análisis de documento). Sincronismo (sistema anterior): dato que vino del sistema anterior mientras aún se sincroniza. Fuera del portal: rutina directa en la base de datos.')],
                    ['p' => $L('Aparece "(sem usuário do portal)".', '"(no portal user)" appears.', 'Aparece "(sin usuario del portal)".'),
                        'r' => $L('A alteração não foi feita por uma pessoa logada no portal: veio de uma rotina ou do sincronismo com o sistema antigo. Veja a origem ao lado.', 'The change was not made by a person logged in to the portal: it came from a routine or the legacy sync. See the source beside it.', 'El cambio no lo hizo una persona conectada al portal: vino de una rutina o del sincronismo con el sistema anterior. Vea el origen al lado.')],
                    ['p' => $L('O botão Histórico está cinza.', 'The History button is grey.', 'El botón Historial está gris.'),
                        'r' => $L('Selecione antes uma linha já salva (clique nela). Linha nova, ainda não salva, não tem histórico.', 'First select a row that is already saved (click it). A new, unsaved row has no history.', 'Seleccione antes una fila ya guardada (haga clic en ella). Una fila nueva, aún no guardada, no tiene historial.')],
                    ['p' => $L('Em quais telas existe o Histórico?', 'Which screens have History?', '¿En qué pantallas existe el Historial?'),
                        'r' => $L('Medição e monitoramento, Controles, Treinamentos, Infraestrutura, Fornecedores (e Fornecedores desativados) e Funcionários de fornecedores, inclusive nas telas Minhas desses módulos.', 'Measurement and monitoring, Controls, Training, Infrastructure, Suppliers (and Deactivated suppliers) and Supplier employees, including the My screens of these modules.', 'Medición y monitoreo, Controles, Capacitaciones, Infraestructura, Proveedores (y Proveedores desactivados) y Empleados de proveedores, incluso en las pantallas Mis de esos módulos.')],
                ],
            ],
        ],
    ],
];
