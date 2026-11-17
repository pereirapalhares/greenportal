<?php
declare(strict_types=1);

/*
 * Migração 235 — textos de ajuda (botão "?" e base do assistente "Como posso ajudar?") das telas mais usadas,
 * segundo o diagnóstico de 13/10/2026 (scripts/diagnostico-ajuda-telas.php). Redigidos a partir do código de cada tela.
 * Formato: transacao (nome do arquivo da tela) => [titulo pt/en/es, texto pt/en/es em HTML simples].
 */

$L = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];

return [
    'dashboard' => [
        'titulo' => $L('Ajuda — Dashboard', 'Help — Dashboard', 'Ayuda — Panel'),
        'texto' => $L(
            '<p>O <strong>Dashboard</strong> mostra a sua visão do Módulo Legal: os itens em que você é o responsável ou tem uma responsabilidade atribuída.</p>'
            . '<ul><li><strong>Período</strong>: escolha Últimos 30, 90 ou 365 dias no canto superior.</li>'
            . '<li><strong>Cards</strong>: Meus requisitos, Minhas licenças, Vencidos ou vencendo em 30 dias, Minhas pendências, Meus controles, Meus treinamentos, Minha infraestrutura e IA pendente. Clique em um card para abrir a lista correspondente. Cards com valor zero ficam desabilitados e só aparecem os módulos que o seu perfil pode ver.</li>'
            . '<li><strong>Distribuição do atendimento</strong>: requisitos Atendidos, Parciais, Não atendidos e Indefinidos.</li>'
            . '<li><strong>Atenção imediata</strong>: até 8 licenças vencidas ou vencendo e situações pendentes; o prazo em vermelho já venceu.</li>'
            . '<li><strong>Cobertura legal</strong>: percentual de requisitos tratados (atendidos ou parcialmente atendidos).</li>'
            . '<li><strong>Pendências por unidade</strong>: requisitos, pendências e licenças por unidade.</li></ul>'
            . '<p>Use <strong>Gerar PDF</strong> para baixar o dashboard com a data de geração.</p>',
            '<p>The <strong>Dashboard</strong> shows your view of the Legal Module: the items you are responsible for or have an assigned responsibility on.</p>'
            . '<ul><li><strong>Period</strong>: choose Last 30, 90 or 365 days at the top.</li>'
            . '<li><strong>Cards</strong>: My requirements, My licenses, Expired or expiring in 30 days, My pending items, My controls, My training, My infrastructure and Pending AI. Click a card to open the related list. Cards with zero are disabled and only modules your profile can see are shown.</li>'
            . '<li><strong>Compliance distribution</strong>: requirements Met, Partial, Not met and Undefined.</li>'
            . '<li><strong>Immediate attention</strong>: up to 8 expired or expiring licenses and pending situations; a red deadline is already overdue.</li>'
            . '<li><strong>Legal coverage</strong>: percentage of requirements handled (met or partially met).</li>'
            . '<li><strong>Pending items by unit</strong>: requirements, pending items and licenses per unit.</li></ul>'
            . '<p>Use <strong>Generate PDF</strong> to download the dashboard with the generation date.</p>',
            '<p>El <strong>Panel</strong> muestra su visión del Módulo Legal: los ítems de los que usted es responsable o en los que tiene una responsabilidad asignada.</p>'
            . '<ul><li><strong>Período</strong>: elija Últimos 30, 90 o 365 días en la parte superior.</li>'
            . '<li><strong>Tarjetas</strong>: Mis requisitos, Mis licencias, Vencidos o por vencer en 30 días, Mis pendientes, Mis controles, Mis capacitaciones, Mi infraestructura e IA pendiente. Haga clic en una tarjeta para abrir la lista correspondiente. Las tarjetas en cero quedan deshabilitadas y solo aparecen los módulos que su perfil puede ver.</li>'
            . '<li><strong>Distribución del cumplimiento</strong>: requisitos Cumplidos, Parciales, No cumplidos e Indefinidos.</li>'
            . '<li><strong>Atención inmediata</strong>: hasta 8 licencias vencidas o por vencer y situaciones pendientes; el plazo en rojo ya venció.</li>'
            . '<li><strong>Cobertura legal</strong>: porcentaje de requisitos tratados (cumplidos o parcialmente cumplidos).</li>'
            . '<li><strong>Pendientes por unidad</strong>: requisitos, pendientes y licencias por unidad.</li></ul>'
            . '<p>Use <strong>Generar PDF</strong> para descargar el panel con la fecha de generación.</p>'
        ),
    ],

    'homologacaofornecedor_funcionario' => [
        'titulo' => $L('Ajuda — Gestão de Funcionários', 'Help — Employee Management', 'Ayuda — Gestión de Empleados'),
        'texto' => $L(
            '<p>Cadastre e acompanhe os <strong>funcionários dos fornecedores</strong> (terceirizados).</p>'
            . '<ul><li><strong>Filtrar</strong>: use Unidade e Fornecedor, à esquerda da busca, para ver só os funcionários de um fornecedor. A lista de fornecedores acompanha a unidade escolhida.</li>'
            . '<li><strong>Incluir</strong>: clique em <strong>+</strong>, escolha o <strong>Fornecedor</strong> e informe o <strong>Nome</strong> (os dois são obrigatórios) e clique em Salvar. Com um fornecedor escolhido no filtro, ele já vem preenchido.</li>'
            . '<li><strong>Alterar</strong>: edite direto na linha (Função, Setor, contatos, datas) e clique em Salvar.</li>'
            . '<li><strong>Anexo</strong>: o botão mostra quantos arquivos o funcionário tem e abre a gestão de arquivos, onde você envia o ASO e certificados e pode pedir a análise dos documentos por IA.</li>'
            . '<li><strong>Correlacionamentos</strong>: selecione uma linha para habilitar Critérios, Treinamentos e Responsabilidades do funcionário.</li>'
            . '<li><strong>Exportar Excel</strong>: a planilha traz também Unidade e Razão Social, o nome da função e a quantidade de anexos.</li></ul>'
            . '<p>Só aparecem funcionários ligados a um fornecedor; um aviso "Fornecedor não cadastrado" ou "Função não cadastrada" indica um vínculo antigo que precisa ser corrigido.</p>',
            '<p>Register and follow up <strong>supplier employees</strong> (contractors).</p>'
            . '<ul><li><strong>Filter</strong>: use Unit and Supplier, to the left of the search, to see only one supplier\'s employees. The supplier list follows the chosen unit.</li>'
            . '<li><strong>Add</strong>: click <strong>+</strong>, choose the <strong>Supplier</strong>, enter the <strong>Name</strong> (both are required) and click Save. If a supplier is chosen in the filter, it comes pre-filled.</li>'
            . '<li><strong>Edit</strong>: change the row directly (Role, Department, contacts, dates) and click Save.</li>'
            . '<li><strong>Attachment</strong>: the button shows how many files the employee has and opens file management, where you upload the ASO and certificates and can request AI analysis of the documents.</li>'
            . '<li><strong>Relationships</strong>: select a row to enable the employee\'s Criteria, Training and Responsibilities.</li>'
            . '<li><strong>Export to Excel</strong>: the spreadsheet also includes Unit and Corporate Name, the role name and the number of attachments.</li></ul>'
            . '<p>Only employees linked to a supplier are listed; a "Supplier not registered" or "Role not registered" notice points to an old link that must be fixed.</p>',
            '<p>Registre y acompañe a los <strong>empleados de los proveedores</strong> (tercerizados).</p>'
            . '<ul><li><strong>Filtrar</strong>: use Unidad y Proveedor, a la izquierda de la búsqueda, para ver solo los empleados de un proveedor. La lista de proveedores sigue la unidad elegida.</li>'
            . '<li><strong>Incluir</strong>: haga clic en <strong>+</strong>, elija el <strong>Proveedor</strong>, informe el <strong>Nombre</strong> (ambos obligatorios) y haga clic en Guardar. Con un proveedor elegido en el filtro, ya viene completado.</li>'
            . '<li><strong>Modificar</strong>: edite directamente en la fila (Función, Sector, contactos, fechas) y haga clic en Guardar.</li>'
            . '<li><strong>Anexo</strong>: el botón muestra cuántos archivos tiene el empleado y abre la gestión de archivos, donde envía el ASO y certificados y puede pedir el análisis de los documentos por IA.</li>'
            . '<li><strong>Correlaciones</strong>: seleccione una fila para habilitar Criterios, Capacitaciones y Responsabilidades del empleado.</li>'
            . '<li><strong>Exportar a Excel</strong>: la planilla incluye también Unidad y Razón Social, el nombre de la función y la cantidad de anexos.</li></ul>'
            . '<p>Solo aparecen empleados vinculados a un proveedor; un aviso "Proveedor no registrado" o "Función no registrada" indica un vínculo antiguo que debe corregirse.</p>'
        ),
    ],

    'questionarios' => [
        'titulo' => $L('Ajuda — Questionários', 'Help — Questionnaires', 'Ayuda — Cuestionarios'),
        'texto' => $L(
            '<p>Administre as perguntas dos questionários usados no sistema. Cada aba é um tipo de questionário: <strong>Legal</strong> (aplicado aos requisitos legais) e <strong>Fornecedores</strong> (aplicado na homologação).</p>'
            . '<ul><li>Escolha a versão em <strong>Selecionar questionário</strong> e use <strong>Buscar pergunta</strong> para localizar uma pergunta.</li>'
            . '<li><strong>Incluir pergunta</strong>: informe o Código (letras, números e _), a Pergunta, o Tipo (texto, número, data, sim/não, escolha única, múltipla escolha ou anexo), a Ordem, o Peso e se é obrigatória. Na aba Legal informe também a Classificação (ex.: 1. ou 1.1.).</li>'
            . '<li>Selecione uma pergunta para <strong>Editar</strong> ou <strong>Excluir</strong>.</li>'
            . '<li>Use <strong>Publicar versão</strong> quando as perguntas estiverem prontas para uso.</li></ul>'
            . '<p>O acesso depende da permissão de Questionários no seu perfil.</p>',
            '<p>Manage the questions of the questionnaires used in the system. Each tab is a questionnaire type: <strong>Legal</strong> (applied to legal requirements) and <strong>Suppliers</strong> (applied in supplier approval).</p>'
            . '<ul><li>Choose the version in <strong>Select questionnaire</strong> and use <strong>Search question</strong> to find a question.</li>'
            . '<li><strong>Add question</strong>: enter the Code (letters, numbers and _), the Question, the Type (text, number, date, yes/no, single choice, multiple choice or attachment), the Order, the Weight and whether it is required. On the Legal tab also enter the Classification (e.g. 1. or 1.1.).</li>'
            . '<li>Select a question to <strong>Edit</strong> or <strong>Delete</strong> it.</li>'
            . '<li>Use <strong>Publish version</strong> when the questions are ready for use.</li></ul>'
            . '<p>Access depends on the Questionnaires permission of your profile.</p>',
            '<p>Administre las preguntas de los cuestionarios usados en el sistema. Cada pestaña es un tipo de cuestionario: <strong>Legal</strong> (aplicado a los requisitos legales) y <strong>Proveedores</strong> (aplicado en la homologación).</p>'
            . '<ul><li>Elija la versión en <strong>Seleccionar cuestionario</strong> y use <strong>Buscar pregunta</strong> para localizar una pregunta.</li>'
            . '<li><strong>Incluir pregunta</strong>: informe el Código (letras, números y _), la Pregunta, el Tipo (texto, número, fecha, sí/no, elección única, múltiple o anexo), el Orden, el Peso y si es obligatoria. En la pestaña Legal informe también la Clasificación (ej.: 1. o 1.1.).</li>'
            . '<li>Seleccione una pregunta para <strong>Editar</strong> o <strong>Eliminar</strong>.</li>'
            . '<li>Use <strong>Publicar versión</strong> cuando las preguntas estén listas para usar.</li></ul>'
            . '<p>El acceso depende del permiso de Cuestionarios de su perfil.</p>'
        ),
    ],

    'manutencaolegal_licencas' => [
        'titulo' => $L('Ajuda — Licenças', 'Help — Licenses', 'Ayuda — Licencias'),
        'texto' => $L(
            '<p>Cadastre e acompanhe as <strong>licenças</strong> da empresa por unidade: vencimento, reação, protocolo de renovação e responsável.</p>'
            . '<ul><li><strong>Cores das linhas</strong>: vermelho = vencida; amarelo = em atenção (já passou a Data de Reação ou está em protocolo); verde = em dia; sem cor = sem data de vencimento. Passe o mouse na legenda ao lado da paginação.</li>'
            . '<li><strong>Datas</strong>: Data de Vencimento, Data de Reação (quando começar a renovar) e Data do protocolo. Qtd dias vencimento, Qtd dias reação e Dias em protocolo são calculados.</li>'
            . '<li><strong>Protocolo</strong>: marque "Em protocolo?" e informe "Protocolado em" e "Protocolado no prazo?" quando a renovação for protocolada.</li>'
            . '<li><strong>Responsável</strong> e <strong>Lista de distribuição</strong>: quem acompanha a licença.</li>'
            . '<li>Selecione uma linha para abrir <strong>Condicionantes</strong> (com plano de ação e evidências), <strong>Palavras-chave</strong>, <strong>Responsabilidades</strong>, <strong>Arquivos</strong> (documento da licença e análise por IA) e o <strong>Questionário SGA</strong>.</li>'
            . '<li><strong>Exportar Excel</strong> leva as colunas visíveis respeitando filtros e busca; o arquivo chega pelo sino de alertas.</li></ul>'
            . '<p>Em <strong>Minhas Licenças</strong> aparecem só as licenças sob sua responsabilidade, sem incluir ou excluir.</p>',
            '<p>Register and follow up the company\'s <strong>licenses</strong> per unit: expiry, reaction date, renewal filing and owner.</p>'
            . '<ul><li><strong>Row colors</strong>: red = expired; yellow = attention (reaction date reached or under renewal filing); green = up to date; no color = no expiry date. Hover over the legend next to the pagination.</li>'
            . '<li><strong>Dates</strong>: Expiry date, Reaction date (when to start renewing) and Filing date. Days to expiry, Days to reaction and Days in filing are calculated.</li>'
            . '<li><strong>Filing</strong>: check "Under filing?" and fill in "Filed on" and "Filed on time?" when the renewal is filed.</li>'
            . '<li><strong>Owner</strong> and <strong>Distribution list</strong>: who follows the license.</li>'
            . '<li>Select a row to open <strong>Conditions</strong> (with action plan and evidence), <strong>Keywords</strong>, <strong>Responsibilities</strong>, <strong>Files</strong> (license document and AI analysis) and the <strong>EMS Questionnaire</strong>.</li>'
            . '<li><strong>Export to Excel</strong> takes the visible columns respecting filters and search; the file arrives in the alerts bell.</li></ul>'
            . '<p>In <strong>My Licenses</strong> only the licenses under your responsibility are shown, without adding or deleting.</p>',
            '<p>Registre y acompañe las <strong>licencias</strong> de la empresa por unidad: vencimiento, reacción, trámite de renovación y responsable.</p>'
            . '<ul><li><strong>Colores de las filas</strong>: rojo = vencida; amarillo = en atención (ya pasó la Fecha de Reacción o está en trámite); verde = al día; sin color = sin fecha de vencimiento. Pase el mouse por la leyenda junto a la paginación.</li>'
            . '<li><strong>Fechas</strong>: Fecha de Vencimiento, Fecha de Reacción (cuándo comenzar a renovar) y Fecha del trámite. Días al vencimiento, Días a la reacción y Días en trámite se calculan.</li>'
            . '<li><strong>Trámite</strong>: marque "¿En trámite?" e informe "Tramitado el" y "¿Tramitado en plazo?" cuando se presente la renovación.</li>'
            . '<li><strong>Responsable</strong> y <strong>Lista de distribución</strong>: quién acompaña la licencia.</li>'
            . '<li>Seleccione una fila para abrir <strong>Condicionantes</strong> (con plan de acción y evidencias), <strong>Palabras clave</strong>, <strong>Responsabilidades</strong>, <strong>Archivos</strong> (documento de la licencia y análisis por IA) y el <strong>Cuestionario SGA</strong>.</li>'
            . '<li><strong>Exportar a Excel</strong> lleva las columnas visibles respetando filtros y búsqueda; el archivo llega por la campana de alertas.</li></ul>'
            . '<p>En <strong>Mis Licencias</strong> aparecen solo las licencias bajo su responsabilidad, sin incluir ni eliminar.</p>'
        ),
    ],

    'seguranca_permissoes' => [
        'titulo' => $L('Ajuda — Permissões', 'Help — Permissions', 'Ayuda — Permisos'),
        'texto' => $L(
            '<p>Defina o que cada <strong>perfil</strong> pode fazer em cada tela do menu. Disponível para os perfis ADMINISTRADOR e ADM DA EMPRESA.</p>'
            . '<ul><li>Ao abrir, escolha o perfil em <strong>Selecionar perfil</strong> e clique em Continuar. As permissões que faltarem são criadas automaticamente, todas desmarcadas (exceto Visualizar no Dashboard).</li>'
            . '<li>Use os filtros <strong>Perfil</strong> e <strong>Menu</strong>; o filtro de Menu inclui as telas abaixo do item escolhido.</li>'
            . '<li>Marque as opções de cada tela: Visualizar Parcial, Visualizar Total, Incluir, Editar, Excluir, Exportar Excel e Gerar PDF. Depois clique em Salvar.</li>'
            . '<li>Um menu só aparece para o usuário se o perfil tiver Visualizar naquela tela.</li></ul>'
            . '<p>O ADM DA EMPRESA não vê nem altera o perfil ADMINISTRADOR. As mudanças valem assim que o usuário abrir ou recarregar uma tela.</p>',
            '<p>Define what each <strong>profile</strong> can do on each menu screen. Available to the ADMINISTRADOR and ADM DA EMPRESA profiles.</p>'
            . '<ul><li>When opening, choose the profile in <strong>Select profile</strong> and click Continue. Missing permissions are created automatically, all unchecked (except View on the Dashboard).</li>'
            . '<li>Use the <strong>Profile</strong> and <strong>Menu</strong> filters; the Menu filter includes the screens under the chosen item.</li>'
            . '<li>Check the options for each screen: Partial view, Full view, Add, Edit, Delete, Export to Excel and Generate PDF. Then click Save.</li>'
            . '<li>A menu only appears to the user if the profile has View on that screen.</li></ul>'
            . '<p>ADM DA EMPRESA cannot see or change the ADMINISTRADOR profile. Changes apply as soon as the user opens or reloads a screen.</p>',
            '<p>Defina lo que cada <strong>perfil</strong> puede hacer en cada pantalla del menú. Disponible para los perfiles ADMINISTRADOR y ADM DA EMPRESA.</p>'
            . '<ul><li>Al abrir, elija el perfil en <strong>Seleccionar perfil</strong> y haga clic en Continuar. Los permisos que falten se crean automáticamente, todos desmarcados (excepto Visualizar en el Panel).</li>'
            . '<li>Use los filtros <strong>Perfil</strong> y <strong>Menú</strong>; el filtro de Menú incluye las pantallas debajo del ítem elegido.</li>'
            . '<li>Marque las opciones de cada pantalla: Visualizar Parcial, Visualizar Total, Incluir, Editar, Eliminar, Exportar a Excel y Generar PDF. Luego haga clic en Guardar.</li>'
            . '<li>Un menú solo aparece para el usuario si el perfil tiene Visualizar en esa pantalla.</li></ul>'
            . '<p>El ADM DA EMPRESA no ve ni modifica el perfil ADMINISTRADOR. Los cambios valen en cuanto el usuario abre o recarga una pantalla.</p>'
        ),
    ],

    'modulolegal_treinamentos' => [
        'titulo' => $L('Ajuda — Treinamentos', 'Help — Training', 'Ayuda — Capacitaciones'),
        'texto' => $L(
            '<p>Cadastre os <strong>treinamentos</strong> exigidos pelos requisitos legais e acompanhe as evidências.</p>'
            . '<ul><li>Clique em <strong>+</strong>, informe a <strong>Descrição</strong> e clique em Salvar.</li>'
            . '<li><strong>Arquivos</strong>: o botão mostra quantos arquivos o treinamento tem e abre a gestão de arquivos (listas de presença, certificados, análise por IA).</li>'
            . '<li>Selecione uma linha para usar <strong>Palavras-chave</strong> (inclua, exclua e clique em Confirmar; elas ajudam a relacionar o treinamento aos requisitos) e <strong>Responsabilidades</strong> (quem responde pelo treinamento).</li></ul>'
            . '<p>Em <strong>Meus Treinamentos</strong> aparecem só os treinamentos sob sua responsabilidade.</p>',
            '<p>Register the <strong>training</strong> required by legal requirements and follow up the evidence.</p>'
            . '<ul><li>Click <strong>+</strong>, enter the <strong>Description</strong> and click Save.</li>'
            . '<li><strong>Files</strong>: the button shows how many files the training has and opens file management (attendance lists, certificates, AI analysis).</li>'
            . '<li>Select a row to use <strong>Keywords</strong> (add, remove and click Confirm; they help relate the training to requirements) and <strong>Responsibilities</strong> (who is accountable for the training).</li></ul>'
            . '<p>In <strong>My Training</strong> only the training under your responsibility is shown.</p>',
            '<p>Registre las <strong>capacitaciones</strong> exigidas por los requisitos legales y acompañe las evidencias.</p>'
            . '<ul><li>Haga clic en <strong>+</strong>, informe la <strong>Descripción</strong> y haga clic en Guardar.</li>'
            . '<li><strong>Archivos</strong>: el botón muestra cuántos archivos tiene la capacitación y abre la gestión de archivos (listas de asistencia, certificados, análisis por IA).</li>'
            . '<li>Seleccione una fila para usar <strong>Palabras clave</strong> (incluya, elimine y haga clic en Confirmar; ayudan a relacionar la capacitación con los requisitos) y <strong>Responsabilidades</strong> (quién responde por la capacitación).</li></ul>'
            . '<p>En <strong>Mis Capacitaciones</strong> aparecen solo las capacitaciones bajo su responsabilidad.</p>'
        ),
    ],

    'medicaoemonitoramento_mm_monitoramento' => [
        'titulo' => $L('Ajuda — Plano de Medição e Monitoramento', 'Help — Measurement and Monitoring Plan', 'Ayuda — Plan de Medición y Monitoreo'),
        'texto' => $L(
            '<p>Planeje as <strong>medições e monitoramentos</strong> por unidade (por exemplo, ruído, efluentes, emissões) e o cronograma de execução.</p>'
            . '<ul><li>Clique em <strong>+</strong> e informe Unidade, Tipo de medição, Equipamento de medição, Área, Aspecto ou Perigo, Parâmetros, Data da medição, Data de reação e Responsável. Clique em Salvar.</li>'
            . '<li><strong>Cronograma</strong>: depois de salvar a linha, o botão abre o cronograma de eventos daquela medição; o número mostra quantos eventos ainda não venceram.</li>'
            . '<li>Use a Data de reação para se antecipar à medição e o Responsável para definir quem executa.</li></ul>'
            . '<p>Os cadastros de apoio ficam em Medição e Monitoramento › Equipamentos, Medição e Controle de Calibração.</p>',
            '<p>Plan the <strong>measurements and monitoring</strong> per unit (for example noise, effluents, emissions) and the execution schedule.</p>'
            . '<ul><li>Click <strong>+</strong> and fill in Unit, Measurement type, Measuring equipment, Area, Aspect or Hazard, Parameters, Measurement date, Reaction date and Owner. Click Save.</li>'
            . '<li><strong>Schedule</strong>: after saving the row, the button opens the event schedule of that measurement; the number shows how many events are not yet due.</li>'
            . '<li>Use the Reaction date to prepare ahead of the measurement and the Owner to define who performs it.</li></ul>'
            . '<p>Supporting records are in Measurement and Monitoring › Equipment, Measurement and Calibration Control.</p>',
            '<p>Planifique las <strong>mediciones y monitoreos</strong> por unidad (por ejemplo ruido, efluentes, emisiones) y el cronograma de ejecución.</p>'
            . '<ul><li>Haga clic en <strong>+</strong> e informe Unidad, Tipo de medición, Equipo de medición, Área, Aspecto o Peligro, Parámetros, Fecha de medición, Fecha de reacción y Responsable. Haga clic en Guardar.</li>'
            . '<li><strong>Cronograma</strong>: después de guardar la fila, el botón abre el cronograma de eventos de esa medición; el número muestra cuántos eventos aún no vencieron.</li>'
            . '<li>Use la Fecha de reacción para anticiparse a la medición y el Responsable para definir quién la ejecuta.</li></ul>'
            . '<p>Los registros de apoyo están en Medición y Monitoreo › Equipos, Medición y Control de Calibración.</p>'
        ),
    ],

    'modulolegal_requisitoslegais' => [
        'titulo' => $L('Ajuda — Requisitos legais', 'Help — Legal requirements', 'Ayuda — Requisitos legales'),
        'texto' => $L(
            '<p>Consulte as <strong>legislações aplicáveis</strong> a cada unidade e trate o atendimento de cada uma.</p>'
            . '<ul><li><strong>Filtros</strong>: Responsável e Lei revogada (padrão "Não") acima do grid, e filtros nas colunas Unidade, Nível, Atendido?, Âmbito e áreas (Meio Ambiente, Segurança, Qualidade, Social).</li>'
            . '<li>Os ícones abrem o <strong>Padrão</strong> e o <strong>Texto da Norma</strong> (PDF).</li>'
            . '<li><strong>Nível de Controle</strong> (Conhecimento, Influência ou Controle) é o único campo editável: altere e clique em <strong>Confirmar alteração</strong>.</li>'
            . '<li>Selecione uma linha para habilitar os <strong>Correlacionamentos</strong> (Licenças, Medição e Monitoramento, Treinamentos, Infraestrutura, Controles, Homologação de Fornecedores) e a <strong>Avaliação</strong> (Situação, CheckList, Alterações e Questionário Legal).</li>'
            . '<li><strong>Solicitar análise de IA</strong>: a IA sugere ações de gestão para o requisito selecionado; acompanhe nos alertas. Se já houver análise sua, o resultado é aberto. A revisão humana é obrigatória.</li></ul>'
            . '<p>Botões sem permissão mostram "Perfil sem permissão de acesso!".</p>',
            '<p>View the <strong>legislation applicable</strong> to each unit and handle compliance with each one.</p>'
            . '<ul><li><strong>Filters</strong>: Owner and Revoked law (default "No") above the grid, and filters on the Unit, Level, Met?, Scope and area columns (Environment, Safety, Quality, Social).</li>'
            . '<li>The icons open the <strong>Standard</strong> and the <strong>Law text</strong> (PDF).</li>'
            . '<li><strong>Control level</strong> (Knowledge, Influence or Control) is the only editable field: change it and click <strong>Confirm change</strong>.</li>'
            . '<li>Select a row to enable the <strong>Relationships</strong> (Licenses, Measurement and Monitoring, Training, Infrastructure, Controls, Supplier Approval) and the <strong>Evaluation</strong> (Status, Checklist, Changes and Legal Questionnaire).</li>'
            . '<li><strong>Request AI analysis</strong>: AI suggests management actions for the selected requirement; follow up in the alerts. If you already have an analysis, its result opens. Human review is mandatory.</li></ul>'
            . '<p>Buttons without permission show "Profile without access permission!".</p>',
            '<p>Consulte las <strong>legislaciones aplicables</strong> a cada unidad y trate el cumplimiento de cada una.</p>'
            . '<ul><li><strong>Filtros</strong>: Responsable y Ley derogada (por defecto "No") arriba del grid, y filtros en las columnas Unidad, Nivel, ¿Cumplido?, Ámbito y áreas (Medio Ambiente, Seguridad, Calidad, Social).</li>'
            . '<li>Los íconos abren el <strong>Estándar</strong> y el <strong>Texto de la Norma</strong> (PDF).</li>'
            . '<li><strong>Nivel de Control</strong> (Conocimiento, Influencia o Control) es el único campo editable: modifíquelo y haga clic en <strong>Confirmar cambio</strong>.</li>'
            . '<li>Seleccione una fila para habilitar las <strong>Correlaciones</strong> (Licencias, Medición y Monitoreo, Capacitaciones, Infraestructura, Controles, Homologación de Proveedores) y la <strong>Evaluación</strong> (Situación, CheckList, Cambios y Cuestionario Legal).</li>'
            . '<li><strong>Solicitar análisis de IA</strong>: la IA sugiere acciones de gestión para el requisito seleccionado; acompañe en las alertas. Si ya tiene un análisis, se abre el resultado. La revisión humana es obligatoria.</li></ul>'
            . '<p>Los botones sin permiso muestran "¡Perfil sin permiso de acceso!".</p>'
        ),
    ],

    'homologacaofornecedor_fornecedor' => [
        'titulo' => $L('Ajuda — Gestão de Fornecedores', 'Help — Supplier Management', 'Ayuda — Gestión de Proveedores'),
        'texto' => $L(
            '<p>Cadastre os <strong>fornecedores</strong> por unidade e conduza a homologação: documentos, funcionários, produtos químicos, equipamentos e avaliação.</p>'
            . '<ul><li>Clique em <strong>+</strong> e informe Unidade, CNPJ/CPF, Razão Social, Nome Fantasia e os demais dados (Ativado?, Trabalha nas Instalações?, Terá Produtos Químicos?, Terá Equipamentos?, responsáveis, avaliação do nível de serviço e endereço). Clique em Salvar.</li>'
            . '<li>Selecione uma linha para abrir os <strong>Correlacionamentos</strong>: Contatos, Certificados, Critérios e documentos (situação e validade de cada documento exigido), Funcionários, Produtos químicos, Equipamentos e Responsabilidades.</li>'
            . '<li>Na <strong>Avaliação</strong>: Questionário do fornecedor, Auditoria de homologação (aprovação e nota) e Plano de ação.</li>'
            . '<li>Use Data da Avaliação e Data da Próxima Avaliação para controlar a reavaliação periódica.</li></ul>',
            '<p>Register <strong>suppliers</strong> per unit and run the approval process: documents, employees, chemicals, equipment and evaluation.</p>'
            . '<ul><li>Click <strong>+</strong> and fill in Unit, Tax ID, Corporate Name, Trade Name and the other data (Active?, Works on site?, Will have chemicals?, Will have equipment?, owners, service level evaluation and address). Click Save.</li>'
            . '<li>Select a row to open the <strong>Relationships</strong>: Contacts, Certificates, Criteria and documents (status and validity of each required document), Employees, Chemicals, Equipment and Responsibilities.</li>'
            . '<li>Under <strong>Evaluation</strong>: Supplier questionnaire, Approval audit (approval and score) and Action plan.</li>'
            . '<li>Use Evaluation date and Next evaluation date to control periodic re-evaluation.</li></ul>',
            '<p>Registre los <strong>proveedores</strong> por unidad y conduzca la homologación: documentos, empleados, productos químicos, equipos y evaluación.</p>'
            . '<ul><li>Haga clic en <strong>+</strong> e informe Unidad, CNPJ/CPF, Razón Social, Nombre Fantasía y los demás datos (¿Activado?, ¿Trabaja en las instalaciones?, ¿Tendrá productos químicos?, ¿Tendrá equipos?, responsables, evaluación del nivel de servicio y dirección). Haga clic en Guardar.</li>'
            . '<li>Seleccione una fila para abrir las <strong>Correlaciones</strong>: Contactos, Certificados, Criterios y documentos (situación y validez de cada documento exigido), Empleados, Productos químicos, Equipos y Responsabilidades.</li>'
            . '<li>En la <strong>Evaluación</strong>: Cuestionario del proveedor, Auditoría de homologación (aprobación y nota) y Plan de acción.</li>'
            . '<li>Use Fecha de Evaluación y Fecha de Próxima Evaluación para controlar la reevaluación periódica.</li></ul>'
        ),
    ],

    'modulolegal_infraestrutura' => [
        'titulo' => $L('Ajuda — Infraestrutura', 'Help — Infrastructure', 'Ayuda — Infraestructura'),
        'texto' => $L(
            '<p>Cadastre os itens de <strong>infraestrutura</strong> exigidos pelos requisitos legais (por exemplo, extintores, bacias de contenção, sistemas de combate a incêndio) e suas evidências.</p>'
            . '<ul><li>Clique em <strong>+</strong>, informe a <strong>Descrição</strong> e clique em Salvar.</li>'
            . '<li><strong>Arquivos</strong>: o botão mostra quantos arquivos o item tem e abre a gestão de arquivos.</li>'
            . '<li>Selecione uma linha para usar <strong>Atributos</strong> (pares Descrição e Valor, como capacidade ou localização; inclua e clique em Confirmar), <strong>Palavras-chave</strong> e <strong>Responsabilidades</strong>.</li></ul>'
            . '<p>Em <strong>Minhas Infraestruturas</strong> aparecem só os itens sob sua responsabilidade.</p>',
            '<p>Register the <strong>infrastructure</strong> items required by legal requirements (for example extinguishers, containment basins, fire-fighting systems) and their evidence.</p>'
            . '<ul><li>Click <strong>+</strong>, enter the <strong>Description</strong> and click Save.</li>'
            . '<li><strong>Files</strong>: the button shows how many files the item has and opens file management.</li>'
            . '<li>Select a row to use <strong>Attributes</strong> (Description and Value pairs, such as capacity or location; add them and click Confirm), <strong>Keywords</strong> and <strong>Responsibilities</strong>.</li></ul>'
            . '<p>In <strong>My Infrastructure</strong> only the items under your responsibility are shown.</p>',
            '<p>Registre los ítems de <strong>infraestructura</strong> exigidos por los requisitos legales (por ejemplo extintores, cubetas de contención, sistemas contra incendios) y sus evidencias.</p>'
            . '<ul><li>Haga clic en <strong>+</strong>, informe la <strong>Descripción</strong> y haga clic en Guardar.</li>'
            . '<li><strong>Archivos</strong>: el botón muestra cuántos archivos tiene el ítem y abre la gestión de archivos.</li>'
            . '<li>Seleccione una fila para usar <strong>Atributos</strong> (pares Descripción y Valor, como capacidad o ubicación; inclúyalos y haga clic en Confirmar), <strong>Palabras clave</strong> y <strong>Responsabilidades</strong>.</li></ul>'
            . '<p>En <strong>Mis Infraestructuras</strong> aparecen solo los ítems bajo su responsabilidad.</p>'
        ),
    ],

    'minhas_solicitacoes' => [
        'titulo' => $L('Ajuda — Minhas solicitações', 'Help — My requests', 'Ayuda — Mis solicitudes'),
        'texto' => $L(
            '<p>Peça <strong>melhorias e novas funcionalidades</strong> do sistema e acompanhe a resposta da equipe.</p>'
            . '<ul><li><strong>Nova solicitação</strong>: informe o título, o que você precisa e para que precisa; escolha o módulo, o tipo (melhoria, nova funcionalidade, integração, relatório, IA...) e a importância. Clique em Registrar solicitação.</li>'
            . '<li>Pelo assistente <strong>Como posso ajudar?</strong>, quando ele identificar algo que o sistema ainda não faz, use <strong>Registrar como sugestão</strong>: a IA redige o pedido e você revisa antes de confirmar.</li>'
            . '<li>Clique em uma solicitação para ver a descrição e o histórico. A situação passa por Nova, Em análise, Planejada, Em desenvolvimento e Entregue (ou Recusada/Duplicada, com a explicação).</li>'
            . '<li>Quando a situação muda ou a equipe responde, você recebe um alerta no sino.</li></ul>'
            . '<p>Não informe dados pessoais (CPF, e-mail, telefone); eles são retirados automaticamente.</p>',
            '<p>Request <strong>improvements and new features</strong> of the system and follow the team\'s reply.</p>'
            . '<ul><li><strong>New request</strong>: enter the title, what you need and why; choose the module, the type (improvement, new feature, integration, report, AI...) and the importance. Click Register request.</li>'
            . '<li>From the <strong>How can I help?</strong> assistant, when it identifies something the system does not do yet, use <strong>Register as a suggestion</strong>: AI writes the request and you review it before confirming.</li>'
            . '<li>Click a request to see the description and history. The status goes through New, Under analysis, Planned, In development and Delivered (or Rejected/Duplicate, with the explanation).</li>'
            . '<li>When the status changes or the team replies, you get an alert in the bell.</li></ul>'
            . '<p>Do not enter personal data (tax ID, e-mail, phone); it is removed automatically.</p>',
            '<p>Pida <strong>mejoras y nuevas funcionalidades</strong> del sistema y acompañe la respuesta del equipo.</p>'
            . '<ul><li><strong>Nueva solicitud</strong>: informe el título, lo que necesita y para qué; elija el módulo, el tipo (mejora, nueva funcionalidad, integración, informe, IA...) y la importancia. Haga clic en Registrar solicitud.</li>'
            . '<li>Desde el asistente <strong>¿Cómo puedo ayudar?</strong>, cuando identifique algo que el sistema aún no hace, use <strong>Registrar como sugerencia</strong>: la IA redacta el pedido y usted lo revisa antes de confirmar.</li>'
            . '<li>Haga clic en una solicitud para ver la descripción y el historial. La situación pasa por Nueva, En análisis, Planificada, En desarrollo y Entregada (o Rechazada/Duplicada, con la explicación).</li>'
            . '<li>Cuando cambia la situación o el equipo responde, usted recibe una alerta en la campana.</li></ul>'
            . '<p>No informe datos personales (documento, correo, teléfono); se eliminan automáticamente.</p>'
        ),
    ],

    'administracao_solicitacoes' => [
        'titulo' => $L('Ajuda — Gestão de solicitações', 'Help — Request management', 'Ayuda — Gestión de solicitudes'),
        'texto' => $L(
            '<p>Acompanhe as <strong>solicitações de melhoria</strong> dos usuários desta empresa e os sinais do assistente de IA. Disponível para ADMINISTRADOR e ADM DA EMPRESA.</p>'
            . '<ul><li><strong>Solicitações</strong>: os totais por situação filtram a lista; use também Tipo e a busca (número, título, módulo ou solicitante). Clique em uma solicitação para ver o detalhe.</li>'
            . '<li>No detalhe, altere a <strong>Situação</strong>, o <strong>Escopo</strong> (só desta empresa ou produto, para todos os clientes) e escreva a <strong>Resposta para quem pediu</strong>; ao salvar, o solicitante recebe um alerta. Recusada e Duplicada exigem resposta; Duplicada exige o número da solicitação original.</li>'
            . '<li><strong>Sinais do assistente</strong>: perguntas em que o assistente não ajudou, dificuldades de uso, erros e funcionalidades inexistentes que ninguém registrou. Use para melhorar a ajuda das telas e identificar necessidades.</li></ul>'
            . '<p>Todas as mudanças ficam no histórico da solicitação.</p>',
            '<p>Follow the <strong>improvement requests</strong> of this company\'s users and the AI assistant signals. Available to ADMINISTRADOR and ADM DA EMPRESA.</p>'
            . '<ul><li><strong>Requests</strong>: the totals by status filter the list; also use Type and the search (number, title, module or requester). Click a request to see the details.</li>'
            . '<li>In the details, change the <strong>Status</strong>, the <strong>Scope</strong> (this company only or product-wide, for all customers) and write the <strong>Reply to the requester</strong>; when saving, the requester gets an alert. Rejected and Duplicate require a reply; Duplicate requires the original request number.</li>'
            . '<li><strong>Assistant signals</strong>: questions where the assistant did not help, usability issues, errors and missing features nobody registered. Use them to improve screen help and identify needs.</li></ul>'
            . '<p>All changes are kept in the request history.</p>',
            '<p>Acompañe las <strong>solicitudes de mejora</strong> de los usuarios de esta empresa y las señales del asistente de IA. Disponible para ADMINISTRADOR y ADM DA EMPRESA.</p>'
            . '<ul><li><strong>Solicitudes</strong>: los totales por situación filtran la lista; use también Tipo y la búsqueda (número, título, módulo o solicitante). Haga clic en una solicitud para ver el detalle.</li>'
            . '<li>En el detalle, cambie la <strong>Situación</strong>, el <strong>Alcance</strong> (solo esta empresa o el producto, para todos los clientes) y escriba la <strong>Respuesta para quien pidió</strong>; al guardar, el solicitante recibe una alerta. Rechazada y Duplicada exigen respuesta; Duplicada exige el número de la solicitud original.</li>'
            . '<li><strong>Señales del asistente</strong>: preguntas en que el asistente no ayudó, dificultades de uso, errores y funcionalidades inexistentes que nadie registró. Úselas para mejorar la ayuda de las pantallas e identificar necesidades.</li></ul>'
            . '<p>Todos los cambios quedan en el historial de la solicitud.</p>'
        ),
    ],
];
