<?php
declare(strict_types=1);

/*
 * Migração 237 — ajuda das telas (leva 2): telas "Minhas/Meus", Controle, Situação quanto ao cumprimento e
 * cadastros de Administração (Unidades, Atividades, Áreas). Redigida a partir do código de cada tela.
 * Formato: transacao (nome do arquivo da tela) => [titulo pt/en/es, texto pt/en/es em HTML simples].
 */

$L = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];

return [
    'minhas_licencas' => [
        'titulo' => $L('Ajuda — Minhas Licenças', 'Help — My Licenses', 'Ayuda — Mis Licencias'),
        'texto' => $L(
            '<p>Mostra só as <strong>licenças sob a sua responsabilidade</strong>. Aqui você acompanha e atualiza, mas não inclui nem exclui licenças (isso é feito em Módulo Legal › Licenças).</p>'
            . '<ul><li><strong>Cores das linhas</strong>: vermelho = vencida; amarelo = em atenção (já passou a Data de Reação ou está em protocolo); verde = em dia; sem cor = sem data de vencimento.</li>'
            . '<li>Atualize datas, protocolo (Em protocolo?, Protocolado em, Protocolado no prazo?) e observações direto na linha e clique em Salvar.</li>'
            . '<li>Selecione uma linha para abrir <strong>Condicionantes</strong> (com plano de ação e evidências), <strong>Palavras-chave</strong>, <strong>Responsabilidades</strong> e <strong>Arquivos</strong> (documento da licença e análise por IA).</li>'
            . '<li>O card "Vencidos ou vencendo em 30 dias" do Dashboard abre esta tela já filtrada nas pendências.</li></ul>',
            '<p>Shows only the <strong>licenses under your responsibility</strong>. Here you follow up and update them, but you do not add or delete licenses (that is done in Legal Module › Licenses).</p>'
            . '<ul><li><strong>Row colors</strong>: red = expired; yellow = attention (reaction date reached or under renewal filing); green = up to date; no color = no expiry date.</li>'
            . '<li>Update dates, filing (Under filing?, Filed on, Filed on time?) and notes directly in the row and click Save.</li>'
            . '<li>Select a row to open <strong>Conditions</strong> (with action plan and evidence), <strong>Keywords</strong>, <strong>Responsibilities</strong> and <strong>Files</strong> (license document and AI analysis).</li>'
            . '<li>The Dashboard card "Expired or expiring in 30 days" opens this screen already filtered on pending items.</li></ul>',
            '<p>Muestra solo las <strong>licencias bajo su responsabilidad</strong>. Aquí las acompaña y actualiza, pero no incluye ni elimina licencias (eso se hace en Módulo Legal › Licencias).</p>'
            . '<ul><li><strong>Colores de las filas</strong>: rojo = vencida; amarillo = en atención (ya pasó la Fecha de Reacción o está en trámite); verde = al día; sin color = sin fecha de vencimiento.</li>'
            . '<li>Actualice fechas, trámite (¿En trámite?, Tramitado el, ¿Tramitado en plazo?) y observaciones directamente en la fila y haga clic en Guardar.</li>'
            . '<li>Seleccione una fila para abrir <strong>Condicionantes</strong> (con plan de acción y evidencias), <strong>Palabras clave</strong>, <strong>Responsabilidades</strong> y <strong>Archivos</strong> (documento de la licencia y análisis por IA).</li>'
            . '<li>La tarjeta "Vencidos o por vencer en 30 días" del Panel abre esta pantalla ya filtrada en los pendientes.</li></ul>'
        ),
    ],

    'meus_treinamentos' => [
        'titulo' => $L('Ajuda — Meus Treinamentos', 'Help — My Training', 'Ayuda — Mis Capacitaciones'),
        'texto' => $L(
            '<p>Mostra só os <strong>treinamentos sob a sua responsabilidade</strong>. Aqui você mantém as evidências, mas não inclui nem exclui treinamentos (isso é feito em Módulo Legal › Treinamentos).</p>'
            . '<ul><li><strong>Arquivos</strong>: o botão mostra quantos arquivos o treinamento tem e abre a gestão de arquivos para enviar listas de presença e certificados e pedir a análise por IA.</li>'
            . '<li>Você pode ajustar a <strong>Descrição</strong> e clicar em Salvar.</li>'
            . '<li>Selecione uma linha e use <strong>Palavras-chave</strong> para relacionar o treinamento aos requisitos legais (inclua, exclua e clique em Confirmar).</li></ul>',
            '<p>Shows only the <strong>training under your responsibility</strong>. Here you keep the evidence, but you do not add or delete training (that is done in Legal Module › Training).</p>'
            . '<ul><li><strong>Files</strong>: the button shows how many files the training has and opens file management to upload attendance lists and certificates and request AI analysis.</li>'
            . '<li>You can adjust the <strong>Description</strong> and click Save.</li>'
            . '<li>Select a row and use <strong>Keywords</strong> to relate the training to legal requirements (add, remove and click Confirm).</li></ul>',
            '<p>Muestra solo las <strong>capacitaciones bajo su responsabilidad</strong>. Aquí mantiene las evidencias, pero no incluye ni elimina capacitaciones (eso se hace en Módulo Legal › Capacitaciones).</p>'
            . '<ul><li><strong>Archivos</strong>: el botón muestra cuántos archivos tiene la capacitación y abre la gestión de archivos para enviar listas de asistencia y certificados y pedir el análisis por IA.</li>'
            . '<li>Puede ajustar la <strong>Descripción</strong> y hacer clic en Guardar.</li>'
            . '<li>Seleccione una fila y use <strong>Palabras clave</strong> para relacionar la capacitación con los requisitos legales (incluya, elimine y haga clic en Confirmar).</li></ul>'
        ),
    ],

    'minhas_infraestruturas' => [
        'titulo' => $L('Ajuda — Minhas Infraestruturas', 'Help — My Infrastructure', 'Ayuda — Mis Infraestructuras'),
        'texto' => $L(
            '<p>Mostra só os <strong>itens de infraestrutura sob a sua responsabilidade</strong>. Aqui você mantém as evidências, mas não inclui nem exclui itens (isso é feito em Módulo Legal › InfraEstrutura).</p>'
            . '<ul><li><strong>Arquivos</strong>: o botão mostra quantos arquivos o item tem e abre a gestão de arquivos.</li>'
            . '<li>Você pode ajustar a <strong>Descrição</strong> e clicar em Salvar.</li>'
            . '<li>Selecione uma linha para usar <strong>Atributos</strong> (pares Descrição e Valor, como capacidade ou localização) e <strong>Palavras-chave</strong>; inclua e clique em Confirmar.</li></ul>',
            '<p>Shows only the <strong>infrastructure items under your responsibility</strong>. Here you keep the evidence, but you do not add or delete items (that is done in Legal Module › Infrastructure).</p>'
            . '<ul><li><strong>Files</strong>: the button shows how many files the item has and opens file management.</li>'
            . '<li>You can adjust the <strong>Description</strong> and click Save.</li>'
            . '<li>Select a row to use <strong>Attributes</strong> (Description and Value pairs, such as capacity or location) and <strong>Keywords</strong>; add them and click Confirm.</li></ul>',
            '<p>Muestra solo los <strong>ítems de infraestructura bajo su responsabilidad</strong>. Aquí mantiene las evidencias, pero no incluye ni elimina ítems (eso se hace en Módulo Legal › Infraestructura).</p>'
            . '<ul><li><strong>Archivos</strong>: el botón muestra cuántos archivos tiene el ítem y abre la gestión de archivos.</li>'
            . '<li>Puede ajustar la <strong>Descripción</strong> y hacer clic en Guardar.</li>'
            . '<li>Seleccione una fila para usar <strong>Atributos</strong> (pares Descripción y Valor, como capacidad o ubicación) y <strong>Palabras clave</strong>; inclúyalos y haga clic en Confirmar.</li></ul>'
        ),
    ],

    'modulolegal_controle' => [
        'titulo' => $L('Ajuda — Controle', 'Help — Control', 'Ayuda — Control'),
        'texto' => $L(
            '<p>Cadastre os <strong>controles e documentos</strong> do sistema de gestão (procedimentos, instruções, registros) e relacione cada um ao que ele controla.</p>'
            . '<ul><li>Clique em <strong>+</strong>, informe o <strong>Código do documento</strong> e o <strong>Título</strong> e clique em Salvar.</li>'
            . '<li><strong>Arquivo</strong>: abre a gestão de arquivos do documento (envio, visualização e análise por IA).</li>'
            . '<li>Selecione uma linha para relacionar o documento a <strong>Unidades</strong>, <strong>Atividades</strong>, <strong>Aspectos</strong>, <strong>Impactos</strong>, <strong>Perigos</strong>, <strong>Riscos</strong>, <strong>Palavras-chave</strong>, <strong>Aspectos sociais</strong> e <strong>Impactos sociais</strong>. As colunas com esses nomes mostram o que já está relacionado.</li>'
            . '<li>A coluna <strong>Responsáveis</strong> mostra quem responde pelo documento.</li></ul>'
            . '<p>Em <strong>Meus Controles</strong> aparecem só os documentos sob sua responsabilidade.</p>',
            '<p>Register the management system <strong>controls and documents</strong> (procedures, instructions, records) and relate each one to what it controls.</p>'
            . '<ul><li>Click <strong>+</strong>, enter the <strong>Document code</strong> and the <strong>Title</strong> and click Save.</li>'
            . '<li><strong>File</strong>: opens the document\'s file management (upload, viewing and AI analysis).</li>'
            . '<li>Select a row to relate the document to <strong>Units</strong>, <strong>Activities</strong>, <strong>Aspects</strong>, <strong>Impacts</strong>, <strong>Hazards</strong>, <strong>Risks</strong>, <strong>Keywords</strong>, <strong>Social aspects</strong> and <strong>Social impacts</strong>. The columns with these names show what is already related.</li>'
            . '<li>The <strong>Responsible parties</strong> column shows who is accountable for the document.</li></ul>'
            . '<p>In <strong>My Controls</strong> only the documents under your responsibility are shown.</p>',
            '<p>Registre los <strong>controles y documentos</strong> del sistema de gestión (procedimientos, instrucciones, registros) y relacione cada uno con lo que controla.</p>'
            . '<ul><li>Haga clic en <strong>+</strong>, informe el <strong>Código del documento</strong> y el <strong>Título</strong> y haga clic en Guardar.</li>'
            . '<li><strong>Archivo</strong>: abre la gestión de archivos del documento (envío, visualización y análisis por IA).</li>'
            . '<li>Seleccione una fila para relacionar el documento con <strong>Unidades</strong>, <strong>Actividades</strong>, <strong>Aspectos</strong>, <strong>Impactos</strong>, <strong>Peligros</strong>, <strong>Riesgos</strong>, <strong>Palabras clave</strong>, <strong>Aspectos sociales</strong> e <strong>Impactos sociales</strong>. Las columnas con esos nombres muestran lo que ya está relacionado.</li>'
            . '<li>La columna <strong>Responsables</strong> muestra quién responde por el documento.</li></ul>'
            . '<p>En <strong>Mis Controles</strong> aparecen solo los documentos bajo su responsabilidad.</p>'
        ),
    ],

    'meus_controles' => [
        'titulo' => $L('Ajuda — Meus Controles', 'Help — My Controls', 'Ayuda — Mis Controles'),
        'texto' => $L(
            '<p>Mostra só os <strong>controles e documentos sob a sua responsabilidade</strong>. Aqui você mantém o documento em dia, mas não inclui nem exclui (isso é feito em Módulo Legal › Controle).</p>'
            . '<ul><li>Você pode ajustar o <strong>Código do documento</strong> e o <strong>Título</strong> e clicar em Salvar.</li>'
            . '<li><strong>Arquivos</strong>: o botão abre a gestão de arquivos para enviar a versão atual do documento e pedir a análise por IA.</li>'
            . '<li>As colunas Unidades, Atividades, Aspectos, Impactos, Perigos, Riscos, Palavras-chave e Responsáveis mostram a que o documento está relacionado; os relacionamentos são mantidos na tela Controle.</li></ul>',
            '<p>Shows only the <strong>controls and documents under your responsibility</strong>. Here you keep the document up to date, but you do not add or delete (that is done in Legal Module › Control).</p>'
            . '<ul><li>You can adjust the <strong>Document code</strong> and the <strong>Title</strong> and click Save.</li>'
            . '<li><strong>Files</strong>: the button opens file management to upload the current version of the document and request AI analysis.</li>'
            . '<li>The Units, Activities, Aspects, Impacts, Hazards, Risks, Keywords and Responsible parties columns show what the document is related to; relationships are maintained on the Control screen.</li></ul>',
            '<p>Muestra solo los <strong>controles y documentos bajo su responsabilidad</strong>. Aquí mantiene el documento al día, pero no incluye ni elimina (eso se hace en Módulo Legal › Control).</p>'
            . '<ul><li>Puede ajustar el <strong>Código del documento</strong> y el <strong>Título</strong> y hacer clic en Guardar.</li>'
            . '<li><strong>Archivos</strong>: el botón abre la gestión de archivos para enviar la versión actual del documento y pedir el análisis por IA.</li>'
            . '<li>Las columnas Unidades, Actividades, Aspectos, Impactos, Peligros, Riesgos, Palabras clave y Responsables muestran con qué está relacionado el documento; las relaciones se mantienen en la pantalla Control.</li></ul>'
        ),
    ],

    'modulolegal_situacaocumprimento' => [
        'titulo' => $L('Ajuda — Situação quanto ao cumprimento', 'Help — Compliance status', 'Ayuda — Situación de cumplimiento'),
        'texto' => $L(
            '<p>Consulta (somente leitura) da <strong>situação de atendimento das legislações</strong> por unidade e responsável.</p>'
            . '<ul><li>Cada linha mostra o Assunto, a Unidade, o Nível de controle, o Responsável, a Lei, a Situação, se está <strong>Atendido?</strong>, a Observação, a Data de avaliação e a Data de vencimento da avaliação.</li>'
            . '<li>Use os filtros das colunas e a busca para localizar, por exemplo, as situações não atendidas de uma unidade ou as avaliações vencidas.</li>'
            . '<li><strong>Exportar Excel</strong> gera a planilha com os filtros aplicados; o arquivo chega pelo sino de alertas.</li>'
            . '<li>Para registrar ou alterar uma situação, use o botão <strong>Situação</strong> em Módulo Legal › Requisitos legais, com o requisito selecionado.</li></ul>'
            . '<p>O card "Minhas pendências" do Dashboard abre esta tela.</p>',
            '<p>Read-only view of the <strong>compliance status of the legislation</strong> per unit and owner.</p>'
            . '<ul><li>Each row shows the Subject, Unit, Control level, Owner, Law, Situation, whether it is <strong>Met?</strong>, the Note, the Evaluation date and the evaluation Due date.</li>'
            . '<li>Use the column filters and the search to find, for example, the unmet situations of a unit or overdue evaluations.</li>'
            . '<li><strong>Export to Excel</strong> generates the spreadsheet with the applied filters; the file arrives in the alerts bell.</li>'
            . '<li>To record or change a situation, use the <strong>Status</strong> button in Legal Module › Legal requirements with the requirement selected.</li></ul>'
            . '<p>The Dashboard card "My pending items" opens this screen.</p>',
            '<p>Consulta (solo lectura) de la <strong>situación de cumplimiento de las legislaciones</strong> por unidad y responsable.</p>'
            . '<ul><li>Cada fila muestra el Asunto, la Unidad, el Nivel de control, el Responsable, la Ley, la Situación, si está <strong>¿Atendido?</strong>, la Observación, la Fecha de evaluación y la Fecha de vencimiento de la evaluación.</li>'
            . '<li>Use los filtros de las columnas y la búsqueda para localizar, por ejemplo, las situaciones no atendidas de una unidad o las evaluaciones vencidas.</li>'
            . '<li><strong>Exportar a Excel</strong> genera la planilla con los filtros aplicados; el archivo llega por la campana de alertas.</li>'
            . '<li>Para registrar o cambiar una situación, use el botón <strong>Situación</strong> en Módulo Legal › Requisitos legales, con el requisito seleccionado.</li></ul>'
            . '<p>La tarjeta "Mis pendientes" del Panel abre esta pantalla.</p>'
        ),
    ],

    'administracao_unidades' => [
        'titulo' => $L('Ajuda — Unidades', 'Help — Units', 'Ayuda — Unidades'),
        'texto' => $L(
            '<p>Cadastre as <strong>unidades</strong> da empresa (fábricas, filiais, escritórios). Elas são usadas em todo o sistema: requisitos legais, licenças, fornecedores, medições e outros.</p>'
            . '<ul><li>Clique em <strong>+</strong>, informe a <strong>Descrição</strong> e clique em Salvar. A lista vem em ordem alfabética.</li>'
            . '<li><strong>Usado em Manutenção Legal</strong>: marque Sim para a unidade aparecer nas listas do Módulo Legal (por exemplo, no Plano de Medição e Monitoramento).</li>'
            . '<li><strong>Tipo de RCL</strong>: escolha RCL Completa ou RCL Padrão para a unidade.</li>'
            . '<li>Antes de excluir uma unidade, verifique se ela não está em uso; registros ligados a ela podem impedir a exclusão.</li></ul>',
            '<p>Register the company\'s <strong>units</strong> (plants, branches, offices). They are used throughout the system: legal requirements, licenses, suppliers, measurements and others.</p>'
            . '<ul><li>Click <strong>+</strong>, enter the <strong>Description</strong> and click Save. The list is sorted alphabetically.</li>'
            . '<li><strong>Used in Legal Maintenance</strong>: set Yes for the unit to appear in the Legal Module lists (for example in the Measurement and Monitoring Plan).</li>'
            . '<li><strong>RCL type</strong>: choose Complete RCL or Standard RCL for the unit.</li>'
            . '<li>Before deleting a unit, check that it is not in use; records linked to it may prevent deletion.</li></ul>',
            '<p>Registre las <strong>unidades</strong> de la empresa (plantas, sucursales, oficinas). Se usan en todo el sistema: requisitos legales, licencias, proveedores, mediciones y otros.</p>'
            . '<ul><li>Haga clic en <strong>+</strong>, informe la <strong>Descripción</strong> y haga clic en Guardar. La lista viene en orden alfabético.</li>'
            . '<li><strong>Usado en Mantenimiento Legal</strong>: marque Sí para que la unidad aparezca en las listas del Módulo Legal (por ejemplo, en el Plan de Medición y Monitoreo).</li>'
            . '<li><strong>Tipo de RCL</strong>: elija RCL Completa o RCL Estándar para la unidad.</li>'
            . '<li>Antes de eliminar una unidad, verifique que no esté en uso; los registros vinculados pueden impedir la eliminación.</li></ul>'
        ),
    ],

    'administracao_atividades' => [
        'titulo' => $L('Ajuda — Atividades', 'Help — Activities', 'Ayuda — Actividades'),
        'texto' => $L(
            '<p>Cadastre as <strong>atividades</strong> realizadas pela empresa (por exemplo, manutenção, pintura, armazenamento de produtos químicos). Elas são usadas nos levantamentos ambiental, ocupacional e social (LAIA, LPRO e LAIS) e nos controles e documentos.</p>'
            . '<ul><li>Clique em <strong>+</strong>, descreva a <strong>Atividade</strong> e clique em Salvar. A lista vem em ordem alfabética.</li>'
            . '<li>Para alterar, edite a descrição na linha e clique em Salvar.</li>'
            . '<li>Antes de excluir, verifique se a atividade não está em uso nos levantamentos ou nos controles.</li></ul>',
            '<p>Register the <strong>activities</strong> carried out by the company (for example maintenance, painting, chemical storage). They are used in the environmental, occupational and social surveys (LAIA, LPRO and LAIS) and in controls and documents.</p>'
            . '<ul><li>Click <strong>+</strong>, describe the <strong>Activity</strong> and click Save. The list is sorted alphabetically.</li>'
            . '<li>To change it, edit the description in the row and click Save.</li>'
            . '<li>Before deleting, check that the activity is not used in the surveys or controls.</li></ul>',
            '<p>Registre las <strong>actividades</strong> realizadas por la empresa (por ejemplo mantenimiento, pintura, almacenamiento de productos químicos). Se usan en los relevamientos ambiental, ocupacional y social (LAIA, LPRO y LAIS) y en los controles y documentos.</p>'
            . '<ul><li>Haga clic en <strong>+</strong>, describa la <strong>Actividad</strong> y haga clic en Guardar. La lista viene en orden alfabético.</li>'
            . '<li>Para modificarla, edite la descripción en la fila y haga clic en Guardar.</li>'
            . '<li>Antes de eliminar, verifique que la actividad no se use en los relevamientos o controles.</li></ul>'
        ),
    ],

    'administracao_areas' => [
        'titulo' => $L('Ajuda — Áreas', 'Help — Areas', 'Ayuda — Áreas'),
        'texto' => $L(
            '<p>Cadastre as <strong>áreas</strong> da empresa (setores ou locais físicos, como Produção, Almoxarifado, Laboratório). Elas são usadas, por exemplo, no Plano de Medição e Monitoramento para indicar onde a medição é feita.</p>'
            . '<ul><li>Clique em <strong>+</strong>, informe a <strong>Área</strong> e clique em Salvar. A lista vem em ordem alfabética.</li>'
            . '<li>Para alterar, edite o nome na linha e clique em Salvar.</li>'
            . '<li>Antes de excluir, verifique se a área não está em uso.</li></ul>',
            '<p>Register the company\'s <strong>areas</strong> (departments or physical places, such as Production, Warehouse, Laboratory). They are used, for example, in the Measurement and Monitoring Plan to indicate where the measurement is taken.</p>'
            . '<ul><li>Click <strong>+</strong>, enter the <strong>Area</strong> and click Save. The list is sorted alphabetically.</li>'
            . '<li>To change it, edit the name in the row and click Save.</li>'
            . '<li>Before deleting, check that the area is not in use.</li></ul>',
            '<p>Registre las <strong>áreas</strong> de la empresa (sectores o lugares físicos, como Producción, Almacén, Laboratorio). Se usan, por ejemplo, en el Plan de Medición y Monitoreo para indicar dónde se realiza la medición.</p>'
            . '<ul><li>Haga clic en <strong>+</strong>, informe el <strong>Área</strong> y haga clic en Guardar. La lista viene en orden alfabético.</li>'
            . '<li>Para modificarla, edite el nombre en la fila y haga clic en Guardar.</li>'
            . '<li>Antes de eliminar, verifique que el área no esté en uso.</li></ul>'
        ),
    ],
];
