<?php
declare(strict_types=1);

/*
 * Conteúdo do Guia do Usuário do GreenSolutions.
 *
 * Cada módulo é uma aba do guia. Para incluir um novo módulo, acrescente um
 * item em 'modulos' com a mesma estrutura (fluxo, tarefas, menu e termos).
 * Todos os textos possuem PT, EN e ES. Etapas com tipo 'A' são executadas
 * automaticamente pelo sistema; etapas com tipo 'M' são feitas pelo usuário.
 * 'transacao' (opcional) é o código do menu (guniversal_dbp.menus.codigo) aberto
 * pelo link direto; o link só aparece para quem tem permissão de visualizar a transação.
 */

if (!defined('GREEN_GUIA_USUARIO')) {
    http_response_code(404);
    exit;
}

$L = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];

return [
    'ui' => [
        'titulo' => $L('Guia do usuário', 'User guide', 'Guía del usuario'),
        'subtitulo' => $L('Veja o caminho de cada módulo, quem faz cada etapa e onde encontrar cada tela.', 'See the path of each module, who does each step and where to find each screen.', 'Vea el recorrido de cada módulo, quién hace cada etapa y dónde encontrar cada pantalla.'),
        'buscar' => $L('Buscar no guia', 'Search the guide', 'Buscar en la guía'),
        'buscar_placeholder' => $L('Ex.: licença, questionário, situação', 'E.g. license, questionnaire, status', 'Ej.: licencia, cuestionario, situación'),
        'limpar' => $L('Limpar busca', 'Clear search', 'Limpiar búsqueda'),
        'sem_resultado' => $L('Nada encontrado nesta aba. Tente outra palavra.', 'Nothing found in this tab. Try another word.', 'No se encontró nada en esta pestaña. Pruebe otra palabra.'),
        'secao_fluxo' => $L('Fluxo do processo', 'Process flow', 'Flujo del proceso'),
        'secao_tarefas' => $L('Como fazer', 'How to', 'Cómo hacer'),
        'secao_menu' => $L('Onde encontrar', 'Where to find', 'Dónde encontrar'),
        'secao_termos' => $L('Termos e dicas', 'Terms and tips', 'Términos y consejos'),
        'legenda' => $L('Legenda', 'Legend', 'Leyenda'),
        'manual' => $L('Manual', 'Manual', 'Manual'),
        'manual_desc' => $L('você faz', 'you do it', 'usted lo hace'),
        'automatico' => $L('Automático', 'Automatic', 'Automático'),
        'automatico_desc' => $L('o sistema faz sozinho', 'the system does it', 'el sistema lo hace solo'),
        'ver_perfil' => $L('Ver etapas de:', 'Show steps for:', 'Ver etapas de:'),
        'todos' => $L('Todos', 'Everyone', 'Todos'),
        'onde' => $L('Onde', 'Where', 'Dónde'),
        'etapa' => $L('Etapa', 'Step', 'Etapa'),
        'etapas' => $L('etapas', 'steps', 'etapas'),
        'usuario' => $L('Usuário', 'User', 'Usuario'),
        'empresa' => $L('Empresa', 'Company', 'Empresa'),
        'abrir' => $L('Abrir tela', 'Open screen', 'Abrir pantalla'),
        'abrir_nome' => $L('Abrir %s', 'Open %s', 'Abrir %s'),
        'abrir_titulo' => $L('Abrir a tela %s em uma aba do portal', 'Open the %s screen in a portal tab', 'Abrir la pantalla %s en una pestaña del portal'),
        'preview_rascunho' => $L('Pré-visualização do rascunho: os usuários ainda não veem estas alterações.', 'Draft preview: users do not see these changes yet.', 'Vista previa del borrador: los usuarios aún no ven estos cambios.'),
        'preview_versao' => $L('Pré-visualização da versão %s.', 'Preview of version %s.', 'Vista previa de la versión %s.'),
        'sem_conteudo' => $L('O guia ainda não tem conteúdo publicado.', 'The guide has no published content yet.', 'La guía aún no tiene contenido publicado.'),
        'nota_permissao' => $L('Você vê no menu somente as telas liberadas para o seu perfil. Botão cinza normalmente significa: selecione uma linha primeiro ou peça a permissão ao administrador.', 'The menu only shows the screens allowed for your profile. A grey button usually means: select a row first or ask the administrator for permission.', 'En el menú solo aparecen las pantallas permitidas para su perfil. Un botón gris normalmente significa: seleccione una fila primero o solicite el permiso al administrador.'),
    ],

    'perfis' => [
        'sistema' => ['icone' => 'bi-gear', 'nome' => $L('Sistema', 'System', 'Sistema')],
        'adm' => ['icone' => 'bi-person-gear', 'nome' => $L('Administrador', 'Administrator', 'Administrador')],
        'gestor' => ['icone' => 'bi-person-workspace', 'nome' => $L('Gestor', 'Manager', 'Gestor')],
        'resp' => ['icone' => 'bi-person-check', 'nome' => $L('Responsável', 'Responsible', 'Responsable')],
        'resp_forn' => ['icone' => 'bi-person-badge', 'nome' => $L('Resp. pelo fornecedor', 'Supplier owner', 'Resp. del proveedor')],
        'resp_doc' => ['icone' => 'bi-file-earmark-person', 'nome' => $L('Resp. pela documentação', 'Documentation owner', 'Resp. de la documentación')],
        'resp_int' => ['icone' => 'bi-person-vcard', 'nome' => $L('Resp. pela integração', 'Onboarding owner', 'Resp. de la integración')],
        'aprovador' => ['icone' => 'bi-person-check-fill', 'nome' => $L('Aprovador', 'Approver', 'Aprobador')],
    ],

    'modulos' => [
        // ------------------------------------------------------------------
        // MÓDULO LEGAL
        // ------------------------------------------------------------------
        [
            'id' => 'legal',
            'icone' => 'bi-bank',
            'nome' => $L('Módulo Legal', 'Legal Module', 'Módulo Legal'),
            'resumo' => $L(
                'Do cadastro da lei até a comprovação do atendimento: avalie cada requisito, planeje como atendê-lo (com ajuda da IA) e acompanhe os responsáveis.',
                'From registering the law to proving compliance: assess each requirement, plan how to meet it (with AI help) and follow up with the owners.',
                'Desde el registro de la ley hasta la comprobación del cumplimiento: evalúe cada requisito, planifique cómo cumplirlo (con ayuda de la IA) y haga seguimiento a los responsables.'
            ),
            'perfis' => ['adm', 'gestor', 'resp'],
            'perfis_nota' => $L(
                'Administrador = perfis ADMINISTRADOR e ADM_DA_EMPRESA. Responsável = qualquer usuário (ou grupo) indicado como responsável por um item.',
                'Administrator = ADMINISTRADOR and ADM_DA_EMPRESA profiles. Responsible = any user (or group) assigned as owner of an item.',
                'Administrador = perfiles ADMINISTRADOR y ADM_DA_EMPRESA. Responsable = cualquier usuario (o grupo) indicado como responsable de un ítem.'
            ),
            'fases' => [
                [
                    'nome' => $L('Montar a base legal', 'Build the legal base', 'Armar la base legal'),
                    'passos' => [
                        ['tipo' => 'M', 'transacao' => 'LEGISLACAO', 'perfil' => 'adm', 'icone' => 'bi-journal-text',
                            'titulo' => $L('Cadastrar as leis e ligá-las às unidades', 'Register laws and link them to units', 'Registrar las leyes y vincularlas a las unidades'),
                            'texto' => $L('Mantenha a lista de legislações e informe em quais empresas e unidades cada lei se aplica.', 'Keep the legislation list up to date and set which companies and units each law applies to.', 'Mantenga la lista de legislaciones e indique en qué empresas y unidades se aplica cada ley.'),
                            'onde' => $L('Manutenção Legal › Legislação  ·  Legislação x Empresas', 'Legal Maintenance › Legislation  ·  Legislation x Companies', 'Mantenimiento Legal › Legislación  ·  Legislación x Empresas')],
                        ['tipo' => 'M', 'transacao' => 'LAIA', 'perfil' => 'adm', 'icone' => 'bi-diagram-3',
                            'titulo' => $L('Fazer os levantamentos', 'Carry out the surveys', 'Realizar los levantamientos'),
                            'texto' => $L('Registre aspectos e impactos ambientais (LAIA), perigos e riscos ocupacionais (LPRO) e aspectos sociais (LAIS) de cada unidade.', 'Record environmental aspects and impacts (LAIA), occupational hazards and risks (LPRO) and social aspects (LAIS) for each unit.', 'Registre aspectos e impactos ambientales (LAIA), peligros y riesgos ocupacionales (LPRO) y aspectos sociales (LAIS) de cada unidad.'),
                            'onde' => $L('Módulo Legal › Levantamento Ambiental / Ocupacional / Social', 'Legal Module › Environmental / Occupational / Social Survey', 'Módulo Legal › Levantamiento Ambiental / Ocupacional / Social')],
                    ],
                ],
                [
                    'nome' => $L('Avaliar os requisitos', 'Assess the requirements', 'Evaluar los requisitos'),
                    'passos' => [
                        ['tipo' => 'M', 'transacao' => 'REQUISITOS_LEGAIS', 'perfil' => 'adm', 'icone' => 'bi-sliders',
                            'titulo' => $L('Definir o nível de controle', 'Set the control level', 'Definir el nivel de control'),
                            'texto' => $L('Para cada lei e unidade escolha Conhecimento, Influência ou Controle e clique em Confirmar alteração.', 'For each law and unit choose Knowledge, Influence or Control and click Confirm change.', 'Para cada ley y unidad elija Conocimiento, Influencia o Control y haga clic en Confirmar cambio.'),
                            'onde' => $L('Módulo Legal › Requisitos legais', 'Legal Module › Legal requirements', 'Módulo Legal › Requisitos legales')],
                        ['tipo' => 'M', 'transacao' => 'REQUISITOS_LEGAIS', 'perfil' => 'resp', 'icone' => 'bi-clipboard-check',
                            'titulo' => $L('Registrar a situação de atendimento', 'Record the compliance status', 'Registrar la situación de cumplimiento'),
                            'texto' => $L('Selecione o requisito, abra Situação e informe se está atendido, o responsável, as datas de avaliação e de vencimento.', 'Select the requirement, open Status and record whether it is met, the owner, and the assessment and due dates.', 'Seleccione el requisito, abra Situación e indique si se cumple, el responsable y las fechas de evaluación y de vencimiento.'),
                            'onde' => $L('Requisitos legais › botão Situação', 'Legal requirements › Status button', 'Requisitos legales › botón Situación')],
                        ['tipo' => 'A', 'perfil' => 'sistema', 'icone' => 'bi-clock-history',
                            'titulo' => $L('Histórico guardado', 'History saved', 'Historial guardado'),
                            'texto' => $L('Cada alteração ou exclusão da situação fica registrada com usuário, data e como estava antes.', 'Every change or deletion of a status is stored with user, date and the previous values.', 'Cada cambio o eliminación de la situación queda registrado con usuario, fecha y cómo estaba antes.'),
                            'onde' => $L('Situação › Histórico', 'Status › History', 'Situación › Historial')],
                    ],
                ],
                [
                    'nome' => $L('Planejar o atendimento', 'Plan compliance', 'Planificar el cumplimiento'),
                    'passos' => [
                        ['tipo' => 'M', 'transacao' => 'REQUISITOS_LEGAIS', 'perfil' => 'adm', 'icone' => 'bi-stars',
                            'titulo' => $L('Pedir sugestões à IA', 'Ask the AI for suggestions', 'Pedir sugerencias a la IA'),
                            'texto' => $L('Selecione um requisito e clique no botão de análise por IA. Você pode continuar trabalhando enquanto ela analisa.', 'Select a requirement and click the AI analysis button. You can keep working while it runs.', 'Seleccione un requisito y haga clic en el botón de análisis por IA. Puede seguir trabajando mientras analiza.'),
                            'onde' => $L('Requisitos legais › botão IA', 'Legal requirements › AI button', 'Requisitos legales › botón IA')],
                        ['tipo' => 'A', 'perfil' => 'sistema', 'icone' => 'bi-cpu',
                            'titulo' => $L('IA sugere o que é preciso', 'AI suggests what is needed', 'La IA sugiere lo necesario'),
                            'texto' => $L('A IA propõe licenças, medições, treinamentos, infraestrutura e documentos de controle. Um alerta no sino avisa quando terminar.', 'The AI proposes licenses, measurements, training, infrastructure and control documents. A bell alert tells you when it is done.', 'La IA propone licencias, mediciones, capacitaciones, infraestructura y documentos de control. Una alerta en la campana avisa cuando termina.'),
                            'onde' => $L('Sino de alertas', 'Alerts bell', 'Campana de alertas')],
                        ['tipo' => 'M', 'perfil' => 'adm', 'icone' => 'bi-hand-thumbs-up',
                            'titulo' => $L('Aprovar ou rejeitar cada sugestão', 'Approve or reject each suggestion', 'Aprobar o rechazar cada sugerencia'),
                            'texto' => $L('Revise as sugestões aba por aba. Nada é criado sem a sua aprovação. O Gestor apenas consulta.', 'Review the suggestions tab by tab. Nothing is created without your approval. Managers can only view.', 'Revise las sugerencias pestaña por pestaña. No se crea nada sin su aprobación. El Gestor solo consulta.'),
                            'onde' => $L('Alerta › Resultado da IA', 'Alert › AI result', 'Alerta › Resultado de la IA')],
                        ['tipo' => 'A', 'transacao' => 'PAINEL_PENDENCIAS_UNIVERSAL', 'perfil' => 'sistema', 'icone' => 'bi-list-task',
                            'titulo' => $L('Tarefa criada para o responsável', 'Task created for the owner', 'Tarea creada para el responsable'),
                            'texto' => $L('Cada sugestão aprovada vira uma tarefa com responsável, unidade e prazo de 7 dias, com alerta.', 'Each approved suggestion becomes a task with owner, unit and a 7-day deadline, with an alert.', 'Cada sugerencia aprobada se convierte en una tarea con responsable, unidad y plazo de 7 días, con alerta.'),
                            'onde' => $L('Painel de pendências', 'Pending items panel', 'Panel de pendientes')],
                        ['tipo' => 'M', 'transacao' => 'LICENCAS', 'perfil' => 'adm', 'icone' => 'bi-grid-3x3-gap',
                            'titulo' => $L('Organizar os 5 pilares e os responsáveis', 'Organize the 5 pillars and owners', 'Organizar los 5 pilares y los responsables'),
                            'texto' => $L('Cadastre ou ajuste Licenças, Medição e Monitoramento, Treinamentos, Infraestrutura e Controles, indicando quem responde por cada item.', 'Create or adjust Licenses, Measurement and Monitoring, Training, Infrastructure and Controls, setting who is responsible for each item.', 'Registre o ajuste Licencias, Medición y Monitoreo, Capacitaciones, Infraestructura y Controles, indicando quién responde por cada ítem.'),
                            'onde' => $L('Módulo Legal › Licenças, Treinamentos, Infraestrutura, Controle, Medição e Monitoramento', 'Legal Module › Licenses, Training, Infrastructure, Control, Measurement and Monitoring', 'Módulo Legal › Licencias, Capacitaciones, Infraestructura, Control, Medición y Monitoreo')],
                        ['tipo' => 'M', 'transacao' => 'REQUISITOS_LEGAIS', 'perfil' => 'adm', 'icone' => 'bi-truck',
                            'titulo' => $L('Lei de influência: ligar fornecedores', 'Influence law: link suppliers', 'Ley de influencia: vincular proveedores'),
                            'texto' => $L('Quando o nível for Influência, quem precisa agir é o fornecedor. Associe os fornecedores ao requisito.', 'When the level is Influence, the supplier must act. Link the suppliers to the requirement.', 'Cuando el nivel es Influencia, quien debe actuar es el proveedor. Asocie los proveedores al requisito.'),
                            'onde' => $L('Requisitos legais › botão Homologação de Fornecedores', 'Legal requirements › Supplier Homologation button', 'Requisitos legales › botón Homologación de Proveedores')],
                    ],
                ],
                [
                    'nome' => $L('Executar', 'Execute', 'Ejecutar'),
                    'passos' => [
                        ['tipo' => 'A', 'perfil' => 'sistema', 'icone' => 'bi-bell',
                            'titulo' => $L('Lembretes aos responsáveis', 'Reminders to owners', 'Recordatorios a los responsables'),
                            'texto' => $L('O sistema cria tarefas e alertas para responsabilidades que vencem em até 30 dias e coloca os monitoramentos recorrentes na agenda.', 'The system creates tasks and alerts for responsibilities due within 30 days and puts recurring monitoring on the calendar.', 'El sistema crea tareas y alertas para responsabilidades que vencen en hasta 30 días y agenda los monitoreos recurrentes.'),
                            'onde' => $L('Sino · Agenda · Painel de pendências', 'Bell · Calendar · Pending items panel', 'Campana · Agenda · Panel de pendientes')],
                        ['tipo' => 'M', 'transacao' => 'MINHAS_LICENCAS', 'perfil' => 'resp', 'icone' => 'bi-person-lines-fill',
                            'titulo' => $L('Cuidar do que é seu', 'Take care of your items', 'Ocuparse de lo suyo'),
                            'texto' => $L('Veja somente os itens sob sua responsabilidade, atualize os dados e anexe as evidências (arquivos).', 'See only the items you are responsible for, update the data and attach the evidence (files).', 'Vea solo los ítems bajo su responsabilidad, actualice los datos y adjunte las evidencias (archivos).'),
                            'onde' => $L('Módulo Legal › Minhas Licenças · Meus Treinamentos · Minhas Infraestruturas · Meus Controles', 'Legal Module › My Licenses · My Training · My Infrastructure · My Controls', 'Módulo Legal › Mis Licencias · Mis Capacitaciones · Mis Infraestructuras · Mis Controles')],
                        ['tipo' => 'M', 'transacao' => 'LICENCAS', 'perfil' => 'resp', 'icone' => 'bi-file-earmark-pdf',
                            'titulo' => $L('Preencher a licença a partir do PDF', 'Fill in the license from the PDF', 'Completar la licencia a partir del PDF'),
                            'texto' => $L('Em Arquivos, escolha o PDF e clique em Analisar com IA. A IA só sugere: confira e clique em Confirmar e gravar.', 'In Files, choose the PDF and click Analyze with AI. The AI only suggests: check it and click Confirm and save.', 'En Archivos, elija el PDF y haga clic en Analizar con IA. La IA solo sugiere: revise y haga clic en Confirmar y guardar.'),
                            'onde' => $L('Licenças › Arquivos', 'Licenses › Files', 'Licencias › Archivos')],
                        ['tipo' => 'M', 'perfil' => 'resp', 'icone' => 'bi-ui-checks-grid',
                            'titulo' => $L('Responder questionários', 'Answer questionnaires', 'Responder cuestionarios'),
                            'texto' => $L('Quando houver questionário para o item, responda e clique em Concluir questionário. Ele segue sozinho para revisão.', 'When there is a questionnaire for the item, answer it and click Complete questionnaire. It moves to review on its own.', 'Cuando haya un cuestionario para el ítem, respóndalo y haga clic en Concluir cuestionario. Pasa solo a revisión.'),
                            'onde' => $L('Botão Questionário no item selecionado', 'Questionnaire button on the selected item', 'Botón Cuestionario en el ítem seleccionado')],
                    ],
                ],
                [
                    'nome' => $L('Acompanhar', 'Follow up', 'Acompañar'),
                    'passos' => [
                        ['tipo' => 'A', 'transacao' => 'DASHBOARD', 'perfil' => 'sistema', 'icone' => 'bi-speedometer2',
                            'titulo' => $L('Indicadores sempre atualizados', 'Always up-to-date indicators', 'Indicadores siempre actualizados'),
                            'texto' => $L('O Dashboard calcula requisitos, licenças em atenção, situações pendentes e análises de IA abertas, mostrando o que envolve você.', 'The Dashboard calculates requirements, licenses needing attention, pending statuses and open AI analyses, showing what involves you.', 'El Dashboard calcula requisitos, licencias en atención, situaciones pendientes y análisis de IA abiertos, mostrando lo que le involucra.'),
                            'onde' => $L('Dashboard', 'Dashboard', 'Dashboard')],
                        ['tipo' => 'M', 'transacao' => 'SITUACAO_CUMPRIMENTO', 'perfil' => 'gestor', 'icone' => 'bi-graph-up',
                            'titulo' => $L('Acompanhar o cumprimento', 'Track compliance', 'Seguir el cumplimiento'),
                            'texto' => $L('Consulte a situação por unidade e responsável, os correlacionamentos e exporte para Excel quando precisar.', 'Check the status by unit and owner, the correlations, and export to Excel when needed.', 'Consulte la situación por unidad y responsable, los correlacionamientos y exporte a Excel cuando lo necesite.'),
                            'onde' => $L('Módulo Legal › Situação quanto ao cumprimento · Correlacionamentos', 'Legal Module › Compliance status · Correlations', 'Módulo Legal › Situación de cumplimiento · Correlacionamientos')],
                        ['tipo' => 'A', 'perfil' => 'sistema', 'icone' => 'bi-file-earmark-excel',
                            'titulo' => $L('Planilha pronta no sino', 'Spreadsheet ready in the bell', 'Planilla lista en la campana'),
                            'texto' => $L('A exportação para Excel é feita em segundo plano. Um alerta traz o arquivo para download.', 'The Excel export runs in the background. An alert brings the file for download.', 'La exportación a Excel se realiza en segundo plano. Una alerta trae el archivo para descargar.'),
                            'onde' => $L('Sino de alertas', 'Alerts bell', 'Campana de alertas')],
                        ['tipo' => 'M', 'transacao' => 'RESPONSABILIDADE_SUBSTITUICAO', 'perfil' => 'adm', 'icone' => 'bi-arrow-left-right',
                            'titulo' => $L('Trocar responsável quando alguém sai', 'Replace an owner when someone leaves', 'Cambiar el responsable cuando alguien se va'),
                            'texto' => $L('Solicite e aprove a substituição ou delegação, com período e motivo. Assim nenhuma obrigação fica sem dono.', 'Request and approve the replacement or delegation, with period and reason, so no obligation is left without an owner.', 'Solicite y apruebe la sustitución o delegación, con período y motivo. Así ninguna obligación queda sin dueño.'),
                            'onde' => $L('Substituição de responsabilidades', 'Responsibility replacement', 'Sustitución de responsabilidades')],
                    ],
                ],
            ],
            'tarefas' => [
                [
                    'transacao' => 'REQUISITOS_LEGAIS',

                    'titulo' => $L('Avaliar se um requisito legal está sendo atendido', 'Assess whether a legal requirement is met', 'Evaluar si un requisito legal se está cumpliendo'),
                    'passos' => [
                        $L('Abra Módulo Legal › Requisitos legais.', 'Open Legal Module › Legal requirements.', 'Abra Módulo Legal › Requisitos legales.'),
                        $L('Use a busca ou os filtros (Responsável, Lei revogada) para achar a lei.', 'Use search or the filters (Responsible, Repealed law) to find the law.', 'Use la búsqueda o los filtros (Responsable, Ley derogada) para encontrar la ley.'),
                        $L('Clique na linha para selecioná-la e depois no botão Situação.', 'Click the row to select it, then click Status.', 'Haga clic en la fila para seleccionarla y luego en el botón Situación.'),
                        $L('Preencha Atendido?, Responsável, Data de Avaliação e Data de Vencimento e salve.', 'Fill in Met?, Responsible, Assessment Date and Due Date, then save.', 'Complete ¿Cumplido?, Responsable, Fecha de Evaluación y Fecha de Vencimiento y guarde.'),
                    ],
                ],
                [
                    'transacao' => 'REQUISITOS_LEGAIS',

                    'titulo' => $L('Pedir sugestões à IA e aprová-las', 'Ask the AI for suggestions and approve them', 'Pedir sugerencias a la IA y aprobarlas'),
                    'passos' => [
                        $L('Em Requisitos legais, selecione a linha do requisito.', 'In Legal requirements, select the requirement row.', 'En Requisitos legales, seleccione la fila del requisito.'),
                        $L('Clique no botão de análise por IA. Se já existir uma análise, ela abre direto.', 'Click the AI analysis button. If an analysis already exists, it opens directly.', 'Haga clic en el botón de análisis por IA. Si ya existe un análisis, se abre directamente.'),
                        $L('Aguarde o alerta no sino e clique nele para abrir o resultado.', 'Wait for the bell alert and click it to open the result.', 'Espere la alerta en la campana y haga clic para abrir el resultado.'),
                        $L('Em cada aba (Licenças, Medição, Treinamentos, Infraestrutura, Documentos), selecione a sugestão e clique em Aprovar ou Rejeitar.', 'In each tab (Licenses, Measurement, Training, Infrastructure, Documents), select the suggestion and click Approve or Reject.', 'En cada pestaña (Licencias, Medición, Capacitaciones, Infraestructura, Documentos), seleccione la sugerencia y haga clic en Aprobar o Rechazar.'),
                    ],
                ],
                [
                    'transacao' => 'LICENCAS',

                    'titulo' => $L('Preencher uma licença lendo o PDF com IA', 'Fill in a license by reading the PDF with AI', 'Completar una licencia leyendo el PDF con IA'),
                    'passos' => [
                        $L('Abra Licenças (ou Minhas Licenças) e selecione a licença.', 'Open Licenses (or My Licenses) and select the license.', 'Abra Licencias (o Mis Licencias) y seleccione la licencia.'),
                        $L('Clique em Arquivos e escolha o PDF da licença.', 'Click Files and choose the license PDF.', 'Haga clic en Archivos y elija el PDF de la licencia.'),
                        $L('Clique em Analisar com IA e confira os dados sugeridos.', 'Click Analyze with AI and check the suggested data.', 'Haga clic en Analizar con IA y revise los datos sugeridos.'),
                        $L('Se estiver correto, clique em Confirmar e gravar. PDFs escaneados (imagem) não funcionam neste recurso.', 'If correct, click Confirm and save. Scanned (image) PDFs do not work with this feature.', 'Si es correcto, haga clic en Confirmar y guardar. Los PDF escaneados (imagen) no funcionan con este recurso.'),
                    ],
                ],
                [
                    'transacao' => 'REQUISITOS_LEGAIS',

                    'titulo' => $L('Ligar fornecedores a uma lei de influência', 'Link suppliers to an influence law', 'Vincular proveedores a una ley de influencia'),
                    'passos' => [
                        $L('Em Requisitos legais, confirme que o Nível de controle da linha é Influência.', 'In Legal requirements, make sure the row Control level is Influence.', 'En Requisitos legales, confirme que el Nivel de control de la fila es Influencia.'),
                        $L('Selecione a linha e clique em Homologação de Fornecedores.', 'Select the row and click Supplier Homologation.', 'Seleccione la fila y haga clic en Homologación de Proveedores.'),
                        $L('Inclua uma linha, escolha a Razão Social do fornecedor e salve.', 'Add a row, choose the supplier Company Name and save.', 'Incluya una fila, elija la Razón Social del proveedor y guarde.'),
                    ],
                ],
                [
                    'transacao' => 'MINHAS_LICENCAS',

                    'titulo' => $L('Ver tudo o que está comigo', 'See everything assigned to me', 'Ver todo lo que está a mi cargo'),
                    'passos' => [
                        $L('Olhe o sino no topo: ele mostra alertas e tarefas novas.', 'Check the bell at the top: it shows new alerts and tasks.', 'Mire la campana arriba: muestra alertas y tareas nuevas.'),
                        $L('Abra Minhas Licenças, Meus Treinamentos, Minhas Infraestruturas ou Meus Controles.', 'Open My Licenses, My Training, My Infrastructure or My Controls.', 'Abra Mis Licencias, Mis Capacitaciones, Mis Infraestructuras o Mis Controles.'),
                        $L('No Dashboard, os cartões de atenção mostram vencimentos e pendências por unidade.', 'On the Dashboard, the attention cards show due dates and pending items by unit.', 'En el Dashboard, las tarjetas de atención muestran vencimientos y pendientes por unidad.'),
                    ],
                ],
                [
                    'titulo' => $L('Exportar uma lista para Excel', 'Export a list to Excel', 'Exportar una lista a Excel'),
                    'passos' => [
                        $L('Aplique os filtros desejados no grid.', 'Apply the filters you want on the grid.', 'Aplique los filtros deseados en el grid.'),
                        $L('Clique no botão Excel.', 'Click the Excel button.', 'Haga clic en el botón Excel.'),
                        $L('Quando o arquivo ficar pronto, um alerta no sino traz o link para baixar.', 'When the file is ready, a bell alert brings the download link.', 'Cuando el archivo esté listo, una alerta en la campana trae el enlace para descargar.'),
                    ],
                ],
            ],
            'menu' => [
                ['transacao' => 'LEGISLACAO', 'caminho' => $L('Manutenção Legal › Legislação', 'Legal Maintenance › Legislation', 'Mantenimiento Legal › Legislación'),
                    'texto' => $L('Cadastro das leis e acesso ao texto da norma.', 'Law records and access to the full text.', 'Registro de las leyes y acceso al texto de la norma.')],
                ['transacao' => 'LEGISLACAO_X_EMPRESAS', 'caminho' => $L('Manutenção Legal › Legislação x Empresas', 'Legal Maintenance › Legislation x Companies', 'Mantenimiento Legal › Legislación x Empresas'),
                    'texto' => $L('Quais leis valem para cada empresa e unidade.', 'Which laws apply to each company and unit.', 'Qué leyes se aplican a cada empresa y unidad.')],
                ['transacao' => 'REQUISITOS_LEGAIS', 'caminho' => $L('Módulo Legal › Requisitos legais', 'Legal Module › Legal requirements', 'Módulo Legal › Requisitos legales'),
                    'texto' => $L('Centro do módulo: nível de controle, situação, IA, questionário e ligações com os pilares e fornecedores.', 'Heart of the module: control level, status, AI, questionnaire and links to pillars and suppliers.', 'Centro del módulo: nivel de control, situación, IA, cuestionario y vínculos con los pilares y proveedores.')],
                ['transacao' => 'LAIA', 'caminho' => $L('Módulo Legal › Levantamento Ambiental (LAIA)', 'Legal Module › Environmental Survey (LAIA)', 'Módulo Legal › Levantamiento Ambiental (LAIA)'),
                    'texto' => $L('Aspectos e impactos ambientais, atividades, destinos e revisões.', 'Environmental aspects and impacts, activities, destinations and reviews.', 'Aspectos e impactos ambientales, actividades, destinos y revisiones.')],
                ['transacao' => 'LPRO', 'caminho' => $L('Módulo Legal › Levantamento Ocupacional (LPRO)', 'Legal Module › Occupational Survey (LPRO)', 'Módulo Legal › Levantamiento Ocupacional (LPRO)'),
                    'texto' => $L('Perigos e riscos ocupacionais, atividades e revisões.', 'Occupational hazards and risks, activities and reviews.', 'Peligros y riesgos ocupacionales, actividades y revisiones.')],
                ['transacao' => 'LAIS', 'caminho' => $L('Módulo Legal › Levantamento Social (LAIS)', 'Legal Module › Social Survey (LAIS)', 'Módulo Legal › Levantamiento Social (LAIS)'),
                    'texto' => $L('Partes interessadas, aspectos e impactos sociais.', 'Stakeholders, social aspects and impacts.', 'Partes interesadas, aspectos e impactos sociales.')],
                ['transacao' => 'LICENCAS', 'caminho' => $L('Módulo Legal › Licenças', 'Legal Module › Licenses', 'Módulo Legal › Licencias'),
                    'texto' => $L('Licenças, vencimentos, protocolo, condicionantes, palavras-chave, responsáveis e arquivos.', 'Licenses, due dates, filing, conditions, keywords, owners and files.', 'Licencias, vencimientos, protocolo, condicionantes, palabras clave, responsables y archivos.')],
                ['transacao' => 'MM_PLANO_MEDICAO_MONITORAMENTO', 'caminho' => $L('Módulo Legal › Medição e Monitoramento', 'Legal Module › Measurement and Monitoring', 'Módulo Legal › Medición y Monitoreo'),
                    'texto' => $L('Equipamentos, medições, calibração e plano de monitoramento.', 'Equipment, measurements, calibration and monitoring plan.', 'Equipos, mediciones, calibración y plan de monitoreo.')],
                ['transacao' => 'MODULOLEGAL_TREINAMENTOS', 'caminho' => $L('Módulo Legal › Treinamentos · Infraestrutura · Controle', 'Legal Module › Training · Infrastructure · Control', 'Módulo Legal › Capacitaciones · Infraestructura · Control'),
                    'texto' => $L('Os demais pilares do atendimento legal, com seus documentos.', 'The other compliance pillars, with their documents.', 'Los demás pilares del cumplimiento legal, con sus documentos.')],
                ['transacao' => 'MINHAS_LICENCAS', 'caminho' => $L('Módulo Legal › Minhas… / Meus…', 'Legal Module › My…', 'Módulo Legal › Mis…'),
                    'texto' => $L('Somente os itens sob a sua responsabilidade.', 'Only the items you are responsible for.', 'Solo los ítems bajo su responsabilidad.')],
                ['transacao' => 'SITUACAO_CUMPRIMENTO', 'caminho' => $L('Módulo Legal › Situação quanto ao cumprimento', 'Legal Module › Compliance status', 'Módulo Legal › Situación de cumplimiento'),
                    'texto' => $L('Visão do atendimento por unidade e responsável.', 'Compliance view by unit and owner.', 'Vista del cumplimiento por unidad y responsable.')],
            ],
            'termos' => [
                ['termo' => $L('Nível de controle', 'Control level', 'Nivel de control'),
                    'texto' => $L('Conhecimento: só acompanhar. Controle: a empresa age. Influência: o fornecedor age.', 'Knowledge: just follow. Control: the company acts. Influence: the supplier acts.', 'Conocimiento: solo seguir. Control: la empresa actúa. Influencia: el proveedor actúa.')],
                ['termo' => $L('Atendido?', 'Met?', '¿Cumplido?'),
                    'texto' => $L('Sim, Não, Parcialmente atendido ou Atendimento indefinido.', 'Yes, No, Partially met or Undefined.', 'Sí, No, Parcialmente cumplido o Cumplimiento indefinido.')],
                ['termo' => $L('Os 5 pilares', 'The 5 pillars', 'Los 5 pilares'),
                    'texto' => $L('Licenças, Medição e Monitoramento, Treinamentos, Infraestrutura e Controles (documentos).', 'Licenses, Measurement and Monitoring, Training, Infrastructure and Controls (documents).', 'Licencias, Medición y Monitoreo, Capacitaciones, Infraestructura y Controles (documentos).')],
                ['termo' => $L('Potencial (IA)', 'Potential (AI)', 'Potencial (IA)'),
                    'texto' => $L('Sugestão da IA. Só vale depois que alguém aprova.', 'An AI suggestion. It only counts after someone approves it.', 'Sugerencia de la IA. Solo vale después de que alguien la aprueba.')],
                ['termo' => 'LAIA · LPRO · LAIS',
                    'texto' => $L('Levantamentos ambiental, ocupacional e social.', 'Environmental, occupational and social surveys.', 'Levantamientos ambiental, ocupacional y social.')],
                ['termo' => $L('Responsável ou grupo', 'Owner or group', 'Responsable o grupo'),
                    'texto' => $L('Um item pode ter um usuário, vários usuários ou um grupo como responsável.', 'An item can have one user, several users or a group as owner.', 'Un ítem puede tener un usuario, varios usuarios o un grupo como responsable.')],
                ['termo' => $L('Dica', 'Tip', 'Consejo'),
                    'texto' => $L('Selecione sempre a linha no grid antes de usar os botões de cima.', 'Always select the row in the grid before using the buttons above it.', 'Seleccione siempre la fila del grid antes de usar los botones de arriba.')],
            ],
        ],

        // ------------------------------------------------------------------
        // HOMOLOGAÇÃO DE FORNECEDORES
        // ------------------------------------------------------------------
        [
            'id' => 'fornecedores',
            'icone' => 'bi-truck',
            'nome' => $L('Homologação de Fornecedores', 'Supplier Homologation', 'Homologación de Proveedores'),
            'resumo' => $L(
                'Cadastre o fornecedor, reúna a documentação, avalie com o questionário e decida se ele está aprovado para trabalhar com a empresa.',
                'Register the supplier, gather the documentation, assess with the questionnaire and decide whether it is approved to work with the company.',
                'Registre el proveedor, reúna la documentación, evalúe con el cuestionario y decida si está aprobado para trabajar con la empresa.'
            ),
            'perfis' => ['adm', 'resp_forn', 'resp_doc', 'resp_int', 'aprovador'],
            'perfis_nota' => $L(
                'Os responsáveis são indicados no próprio cadastro do fornecedor e no botão Responsabilidades.',
                'Owners are set in the supplier record itself and in the Responsibilities button.',
                'Los responsables se indican en el propio registro del proveedor y en el botón Responsabilidades.'
            ),
            'fases' => [
                [
                    'nome' => $L('Cadastrar', 'Register', 'Registrar'),
                    'passos' => [
                        ['tipo' => 'M', 'transacao' => 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR', 'perfil' => 'adm', 'icone' => 'bi-building-add',
                            'titulo' => $L('Cadastrar o fornecedor', 'Register the supplier', 'Registrar el proveedor'),
                            'texto' => $L('Informe unidade, CNPJ/CPF, razão social, tipo, nível de serviço e se trabalha nas instalações, usa produtos químicos ou equipamentos.', 'Enter unit, tax ID, company name, type, service level and whether it works on site, uses chemicals or equipment.', 'Indique unidad, CNPJ/CPF, razón social, tipo, nivel de servicio y si trabaja en las instalaciones, usa productos químicos o equipos.'),
                            'onde' => $L('Homologação de Fornecedores › Gestão de Fornecedores', 'Supplier Homologation › Supplier Management', 'Homologación de Proveedores › Gestión de Proveedores')],
                        ['tipo' => 'M', 'transacao' => 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR', 'perfil' => 'adm', 'icone' => 'bi-people',
                            'titulo' => $L('Definir os responsáveis', 'Set the owners', 'Definir los responsables'),
                            'texto' => $L('Indique quem responde pelo fornecedor, pela documentação e pela integração dos funcionários.', 'Set who is responsible for the supplier, the documentation and the onboarding of workers.', 'Indique quién responde por el proveedor, la documentación y la integración de los empleados.'),
                            'onde' => $L('Gestão de Fornecedores › botão Responsabilidades', 'Supplier Management › Responsibilities button', 'Gestión de Proveedores › botón Responsabilidades')],
                        ['tipo' => 'M', 'transacao' => 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR', 'perfil' => 'resp_forn', 'icone' => 'bi-person-lines-fill',
                            'titulo' => $L('Registrar os contatos', 'Record the contacts', 'Registrar los contactos'),
                            'texto' => $L('Cadastre as pessoas de contato do fornecedor.', 'Add the supplier contact people.', 'Registre las personas de contacto del proveedor.'),
                            'onde' => $L('Gestão de Fornecedores › botão Contatos', 'Supplier Management › Contacts button', 'Gestión de Proveedores › botón Contactos')],
                    ],
                ],
                [
                    'nome' => $L('Reunir a documentação', 'Gather the documentation', 'Reunir la documentación'),
                    'passos' => [
                        ['tipo' => 'M', 'transacao' => 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR', 'perfil' => 'resp_doc', 'icone' => 'bi-file-earmark-check',
                            'titulo' => $L('Critérios, documentos e certificados', 'Criteria, documents and certificates', 'Criterios, documentos y certificados'),
                            'texto' => $L('Registre o que o fornecedor precisa apresentar, anexe os documentos e os certificados com suas validades.', 'Record what the supplier must provide, attach the documents and the certificates with their validity.', 'Registre lo que el proveedor debe presentar, adjunte los documentos y los certificados con sus vigencias.'),
                            'onde' => $L('Gestão de Fornecedores › Critérios e Documentos · Certificados', 'Supplier Management › Criteria and Documents · Certificates', 'Gestión de Proveedores › Criterios y Documentos · Certificados')],
                        ['tipo' => 'M', 'transacao' => 'HOMOLOGACAO_FORNECEDOR_FUNCIONARIO', 'perfil' => 'resp_int', 'icone' => 'bi-person-vcard',
                            'titulo' => $L('Funcionários, produtos e equipamentos', 'Workers, products and equipment', 'Empleados, productos y equipos'),
                            'texto' => $L('Se o fornecedor trabalha nas instalações, cadastre os funcionários com critérios e treinamentos, os produtos químicos e os equipamentos.', 'If the supplier works on site, add the workers with criteria and training, the chemicals and the equipment.', 'Si el proveedor trabaja en las instalaciones, registre los empleados con criterios y capacitaciones, los productos químicos y los equipos.'),
                            'onde' => $L('Gestão de Funcionários · Gestão de Fornecedores › Produtos Químicos · Equipamentos', 'Employee Management · Supplier Management › Chemicals · Equipment', 'Gestión de Empleados · Gestión de Proveedores › Productos Químicos · Equipos')],
                    ],
                ],
                [
                    'nome' => $L('Avaliar e decidir', 'Assess and decide', 'Evaluar y decidir'),
                    'passos' => [
                        ['tipo' => 'M', 'transacao' => 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR', 'perfil' => 'resp_forn', 'icone' => 'bi-ui-checks-grid',
                            'titulo' => $L('Aplicar o questionário', 'Run the questionnaire', 'Aplicar el cuestionario'),
                            'texto' => $L('Selecione o fornecedor, abra o questionário, responda as perguntas, anexe evidências e clique em Concluir questionário.', 'Select the supplier, open the questionnaire, answer the questions, attach evidence and click Complete questionnaire.', 'Seleccione el proveedor, abra el cuestionario, responda las preguntas, adjunte evidencias y haga clic en Concluir cuestionario.'),
                            'onde' => $L('Gestão de Fornecedores › botão Questionário do fornecedor', 'Supplier Management › Supplier questionnaire button', 'Gestión de Proveedores › botón Cuestionario del proveedor')],
                        ['tipo' => 'A', 'perfil' => 'sistema', 'icone' => 'bi-calculator',
                            'titulo' => $L('Resultado calculado e fluxo aberto', 'Result calculated and flow started', 'Resultado calculado y flujo abierto'),
                            'texto' => $L('O sistema calcula o resultado e abre a avaliação com prazo de 10 dias. Reabrir não duplica o questionário.', 'The system calculates the result and opens the assessment with a 10-day deadline. Reopening does not duplicate the questionnaire.', 'El sistema calcula el resultado y abre la evaluación con plazo de 10 días. Reabrir no duplica el cuestionario.'),
                            'onde' => $L('Automático', 'Automatic', 'Automático')],
                        ['tipo' => 'M', 'perfil' => 'aprovador', 'icone' => 'bi-check2-circle',
                            'titulo' => $L('Aprovar ou rejeitar', 'Approve or reject', 'Aprobar o rechazar'),
                            'texto' => $L('Uma pessoa decide se o fornecedor está homologado. O motivo é obrigatório nas duas opções.', 'A person decides whether the supplier is approved. A reason is required in both cases.', 'Una persona decide si el proveedor queda homologado. El motivo es obligatorio en ambas opciones.'),
                            'onde' => $L('Avaliação do questionário', 'Questionnaire assessment', 'Evaluación del cuestionario')],
                        ['tipo' => 'A', 'perfil' => 'sistema', 'icone' => 'bi-clock-history',
                            'titulo' => $L('Decisão registrada', 'Decision recorded', 'Decisión registrada'),
                            'texto' => $L('O status passa para Aprovado ou Rejeitado e fica no histórico com usuário, data e motivo.', 'The status changes to Approved or Rejected and stays in the history with user, date and reason.', 'El estado pasa a Aprobado o Rechazado y queda en el historial con usuario, fecha y motivo.'),
                            'onde' => $L('Automático', 'Automatic', 'Automático')],
                    ],
                ],
                [
                    'nome' => $L('Acompanhar e melhorar', 'Follow up and improve', 'Acompañar y mejorar'),
                    'passos' => [
                        ['tipo' => 'M', 'transacao' => 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR', 'perfil' => 'resp_forn', 'icone' => 'bi-clipboard-check',
                            'titulo' => $L('Auditoria e plano de ação', 'Audit and action plan', 'Auditoría y plan de acción'),
                            'texto' => $L('Registre a auditoria de homologação e, se houver falhas, as ações corretivas com responsável e prazo.', 'Record the homologation audit and, if there are gaps, the corrective actions with owner and deadline.', 'Registre la auditoría de homologación y, si hay fallas, las acciones correctivas con responsable y plazo.'),
                            'onde' => $L('Gestão de Fornecedores › Auditoria de Homologação · Plano de Ação', 'Supplier Management › Homologation Audit · Action Plan', 'Gestión de Proveedores › Auditoría de Homologación · Plan de Acción')],
                        ['tipo' => 'M', 'transacao' => 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR', 'perfil' => 'adm', 'icone' => 'bi-calendar-event',
                            'titulo' => $L('Atualizar a avaliação', 'Update the assessment', 'Actualizar la evaluación'),
                            'texto' => $L('Informe a data da avaliação e da próxima avaliação. Desative o fornecedor quando ele não puder mais atuar.', 'Enter the assessment date and the next assessment date. Deactivate the supplier when it can no longer work.', 'Indique la fecha de la evaluación y de la próxima evaluación. Desactive el proveedor cuando ya no pueda actuar.'),
                            'onde' => $L('Gestão de Fornecedores › Data da avaliação · Ativado?', 'Supplier Management › Assessment date · Active?', 'Gestión de Proveedores › Fecha de evaluación · ¿Activado?')],
                        ['tipo' => 'A', 'perfil' => 'sistema', 'icone' => 'bi-bell',
                            'titulo' => $L('Lembretes e alertas', 'Reminders and alerts', 'Recordatorios y alertas'),
                            'texto' => $L('Os responsáveis recebem tarefas e alertas quando uma responsabilidade está perto de vencer.', 'Owners receive tasks and alerts when a responsibility is about to expire.', 'Los responsables reciben tareas y alertas cuando una responsabilidad está por vencer.'),
                            'onde' => $L('Sino · Painel de pendências', 'Bell · Pending items panel', 'Campana · Panel de pendientes')],
                        ['tipo' => 'M', 'transacao' => 'REQUISITOS_LEGAIS', 'perfil' => 'adm', 'icone' => 'bi-bank',
                            'titulo' => $L('Leis que o fornecedor deve cumprir', 'Laws the supplier must follow', 'Leyes que el proveedor debe cumplir'),
                            'texto' => $L('Requisitos legais de Influência podem ser ligados ao fornecedor no Módulo Legal.', 'Influence legal requirements can be linked to the supplier in the Legal Module.', 'Los requisitos legales de Influencia pueden vincularse al proveedor en el Módulo Legal.'),
                            'onde' => $L('Módulo Legal › Requisitos legais › Homologação de Fornecedores', 'Legal Module › Legal requirements › Supplier Homologation', 'Módulo Legal › Requisitos legales › Homologación de Proveedores')],
                    ],
                ],
            ],
            'tarefas' => [
                [
                    'transacao' => 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR',

                    'titulo' => $L('Cadastrar um novo fornecedor', 'Register a new supplier', 'Registrar un nuevo proveedor'),
                    'passos' => [
                        $L('Abra Homologação de Fornecedores › Gestão de Fornecedores.', 'Open Supplier Homologation › Supplier Management.', 'Abra Homologación de Proveedores › Gestión de Proveedores.'),
                        $L('Clique em Novo e preencha unidade, CNPJ/CPF, razão social e tipo.', 'Click New and fill in unit, tax ID, company name and type.', 'Haga clic en Nuevo y complete unidad, CNPJ/CPF, razón social y tipo.'),
                        $L('Marque se trabalha nas instalações, se tem produtos químicos e equipamentos.', 'Tick whether it works on site and has chemicals and equipment.', 'Marque si trabaja en las instalaciones y si tiene productos químicos y equipos.'),
                        $L('Escolha o Responsável pelo Fornecedor e o Responsável pela Integração e salve.', 'Choose the Supplier Responsible and the Integration Responsible and save.', 'Elija el Responsable del Proveedor y el Responsable de Integración y guarde.'),
                    ],
                ],
                [
                    'transacao' => 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR',

                    'titulo' => $L('Registrar documentos e certificados', 'Record documents and certificates', 'Registrar documentos y certificados'),
                    'passos' => [
                        $L('Em Gestão de Fornecedores, clique na linha do fornecedor.', 'In Supplier Management, click the supplier row.', 'En Gestión de Proveedores, haga clic en la fila del proveedor.'),
                        $L('Clique em Critérios e Documentos e inclua cada exigência com o arquivo.', 'Click Criteria and Documents and add each requirement with its file.', 'Haga clic en Criterios y Documentos e incluya cada exigencia con el archivo.'),
                        $L('Clique em Certificados e registre os certificados com validade.', 'Click Certificates and record the certificates with validity.', 'Haga clic en Certificados y registre los certificados con su vigencia.'),
                    ],
                ],
                [
                    'transacao' => 'HOMOLOGACAO_FORNECEDOR_FUNCIONARIO',

                    'titulo' => $L('Cadastrar funcionários do fornecedor', 'Register supplier workers', 'Registrar empleados del proveedor'),
                    'passos' => [
                        $L('Abra Homologação de Fornecedores › Gestão de Funcionários.', 'Open Supplier Homologation › Employee Management.', 'Abra Homologación de Proveedores › Gestión de Empleados.'),
                        $L('Clique em Novo, escolha o Fornecedor e preencha nome, função e setor.', 'Click New, choose the Supplier and fill in name, role and department.', 'Haga clic en Nuevo, elija el Proveedor y complete nombre, función y sector.'),
                        $L('Selecione o funcionário e use Critérios e Treinamentos para registrar o que ele precisa ter.', 'Select the worker and use Criteria and Training to record what is required.', 'Seleccione el empleado y use Criterios y Capacitaciones para registrar lo que necesita tener.'),
                    ],
                ],
                [
                    'transacao' => 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR',

                    'titulo' => $L('Aplicar o questionário de avaliação', 'Run the assessment questionnaire', 'Aplicar el cuestionario de evaluación'),
                    'passos' => [
                        $L('Em Gestão de Fornecedores, selecione o fornecedor.', 'In Supplier Management, select the supplier.', 'En Gestión de Proveedores, seleccione el proveedor.'),
                        $L('Clique em Questionário do fornecedor e escolha o questionário publicado.', 'Click Supplier questionnaire and choose the published questionnaire.', 'Haga clic en Cuestionario del proveedor y elija el cuestionario publicado.'),
                        $L('Responda as perguntas e anexe evidências quando pedido.', 'Answer the questions and attach evidence when asked.', 'Responda las preguntas y adjunte evidencias cuando se solicite.'),
                        $L('Clique em Concluir questionário. A decisão final é de uma pessoa, com motivo.', 'Click Complete questionnaire. The final decision is made by a person, with a reason.', 'Haga clic en Concluir cuestionario. La decisión final es de una persona, con motivo.'),
                    ],
                ],
                [
                    'transacao' => 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR',

                    'titulo' => $L('Registrar auditoria e plano de ação', 'Record an audit and action plan', 'Registrar auditoría y plan de acción'),
                    'passos' => [
                        $L('Selecione o fornecedor em Gestão de Fornecedores.', 'Select the supplier in Supplier Management.', 'Seleccione el proveedor en Gestión de Proveedores.'),
                        $L('Clique em Auditoria de Homologação e registre o resultado.', 'Click Homologation Audit and record the result.', 'Haga clic en Auditoría de Homologación y registre el resultado.'),
                        $L('Para cada falha, abra Plano de Ação e inclua a ação, o responsável e o prazo.', 'For each gap, open Action Plan and add the action, owner and deadline.', 'Para cada falla, abra Plan de Acción e incluya la acción, el responsable y el plazo.'),
                    ],
                ],
            ],
            'menu' => [
                ['transacao' => 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR', 'caminho' => $L('Homologação de Fornecedores › Gestão de Fornecedores', 'Supplier Homologation › Supplier Management', 'Homologación de Proveedores › Gestión de Proveedores'),
                    'texto' => $L('Cadastro principal. Selecione uma linha para usar os botões do fornecedor.', 'Main record. Select a row to use the supplier buttons.', 'Registro principal. Seleccione una fila para usar los botones del proveedor.')],
                ['transacao' => 'HOMOLOGACAO_FORNECEDOR_FUNCIONARIO', 'caminho' => $L('Homologação de Fornecedores › Gestão de Funcionários', 'Supplier Homologation › Employee Management', 'Homologación de Proveedores › Gestión de Empleados'),
                    'texto' => $L('Funcionários dos fornecedores, com critérios e treinamentos.', 'Supplier workers, with criteria and training.', 'Empleados de los proveedores, con criterios y capacitaciones.')],
                ['transacao' => 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR', 'caminho' => $L('Botões do fornecedor selecionado', 'Selected supplier buttons', 'Botones del proveedor seleccionado'),
                    'texto' => $L('Contatos, Questionário, Responsabilidades, Certificados, Auditoria, Critérios e Documentos, Funcionários, Produtos Químicos, Equipamentos e Plano de Ação.', 'Contacts, Questionnaire, Responsibilities, Certificates, Audit, Criteria and Documents, Workers, Chemicals, Equipment and Action Plan.', 'Contactos, Cuestionario, Responsabilidades, Certificados, Auditoría, Criterios y Documentos, Empleados, Productos Químicos, Equipos y Plan de Acción.')],
                ['transacao' => 'REQUISITOS_LEGAIS', 'caminho' => $L('Módulo Legal › Requisitos legais', 'Legal Module › Legal requirements', 'Módulo Legal › Requisitos legales'),
                    'texto' => $L('Leis de Influência ligadas aos fornecedores.', 'Influence laws linked to suppliers.', 'Leyes de Influencia vinculadas a los proveedores.')],
            ],
            'termos' => [
                ['termo' => $L('Homologação', 'Homologation', 'Homologación'),
                    'texto' => $L('Aprovação formal para o fornecedor trabalhar com a empresa.', 'Formal approval for the supplier to work with the company.', 'Aprobación formal para que el proveedor trabaje con la empresa.')],
                ['termo' => $L('Integração', 'Onboarding', 'Integración'),
                    'texto' => $L('Preparo dos funcionários do fornecedor para entrar nas instalações.', 'Preparing supplier workers to enter the premises.', 'Preparación de los empleados del proveedor para ingresar a las instalaciones.')],
                ['termo' => $L('Critérios e Documentos', 'Criteria and Documents', 'Criterios y Documentos'),
                    'texto' => $L('Lista do que o fornecedor deve apresentar, com o arquivo de cada item.', 'List of what the supplier must provide, with the file of each item.', 'Lista de lo que el proveedor debe presentar, con el archivo de cada ítem.')],
                ['termo' => $L('Status da avaliação', 'Assessment status', 'Estado de la evaluación'),
                    'texto' => $L('Em preenchimento → Aprovado ou Rejeitado.', 'In progress → Approved or Rejected.', 'En llenado → Aprobado o Rechazado.')],
                ['termo' => $L('Fornecedor de influência', 'Influence supplier', 'Proveedor de influencia'),
                    'texto' => $L('Fornecedor que precisa cumprir uma lei classificada como Influência no Módulo Legal.', 'A supplier that must follow a law classified as Influence in the Legal Module.', 'Proveedor que debe cumplir una ley clasificada como Influencia en el Módulo Legal.')],
                ['termo' => $L('Dica', 'Tip', 'Consejo'),
                    'texto' => $L('Os botões do fornecedor ficam cinza até você clicar em uma linha do grid.', 'The supplier buttons stay grey until you click a row in the grid.', 'Los botones del proveedor quedan grises hasta que haga clic en una fila del grid.')],
            ],
        ],
    ],
];
