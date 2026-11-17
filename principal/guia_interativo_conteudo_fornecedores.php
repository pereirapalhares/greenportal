<?php
declare(strict_types=1);

/*
 * Ajuda inteligente (botão ? / F1) de Homologação de Fornecedores — guias passo a passo com "Mostre-me" e "Sobre esta tela".
 * A migração 357 junta este conteúdo ao assunto "fornecedores" do Guia do usuário publicado (só preenche guias/telas vazios).
 * Janelas abertas por cima de uma tela (Documentos, Detalhe do ranking, Ficha) vêm primeiro na lista de telas: o painel usa
 * a primeira tela cuja chave bate, e a janela também carrega as chaves da tela de trás.
 * Títulos dos guias iguais aos "Como fazer" do guia: o painel mostra o guia completo no lugar do texto simples.
 */

if (!defined('GREEN_GUIA_USUARIO')) { http_response_code(404); exit; }

$L = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];
$P = static fn(array $texto, array $ver = [], string $alvo = '', string $tela = '', string $quem = '', bool $auto = true): array
    => ['texto' => $texto, 'ver' => $ver ?: $L('', '', ''), 'alvo' => $alvo, 'tela' => $tela, 'quem' => $quem, 'auto' => $auto];

$tForn = ['HOMOLOGACAO_FORNECEDOR_FORNECEDOR', 'homologacaofornecedor_fornecedor', 'arquivo:homologacaofornecedor_fornecedor.php'];
$tDocs = ['homologacaofornecedor_fornecedorxcriterios', 'arquivo:homologacaofornecedor_fornecedorxcriterios.php'];
$tFunc = ['HOMOLOGACAO_FORNECEDOR_FUNCIONARIO', 'homologacaofornecedor_funcionario', 'arquivo:homologacaofornecedor_funcionario.php'];
$tInteg = ['HOMOLOGACAO_INTEGRACAO_FUNCIONARIOS', 'homologacaofornecedores_integracao', 'arquivo:homologacaofornecedores_integracao.php'];
$tPainel = ['HOMOLOGACAO_PAINEL', 'homologacaofornecedores_painel', 'arquivo:homologacaofornecedores_painel.php'];
$tRel = ['HOMOLOGACAO_RELATORIOS_GERENCIAIS', 'homologacaofornecedores_relatorios', 'arquivo:homologacaofornecedores_relatorios.php'];
$tRank = ['HOMOLOGACAO_FORNECEDOR_RANKING', 'arquivo:homologacaofornecedor_ranking.php'];
$tPesos = ['ADMINISTRACAO_PESOS', 'arquivo:administracao_pesos.php'];
$tPortF = ['PORTARIA_DOCUMENTACAO_FORNECEDOR', 'portaria_documentacaofornecedor'];
$tPortU = ['PORTARIA_DOCUMENTACAO_FUNCIONARIO', 'portaria_documentacaofuncionario'];
$tCad = ['HOMOLOGACAO_TIPOS_FORNECEDORES', 'HOMOLOGACAO_CRITERIOS_FORNECEDORES', 'HOMOLOGACAO_TIPOS_FUNCIONARIOS', 'HOMOLOGACAO_CRITERIOS_FUNCIONARIOS', 'HOMOLOGACAO_TIPOS_TREINAMENTOS',
    'homologacaofornecedores_tiposfornecedores', 'homologacaofornecedores_criteriosfornecedores', 'homologacaofornecedores_tiposfuncionarios', 'homologacaofornecedores_criteriosfuncionarios', 'homologacaofornecedores_tipostreinamentos'];

$abrirForn = $P(
    $L('No menu à esquerda, abra **Homologação de Fornecedores** e clique em **Gestão de Fornecedores**.', 'In the left menu, open **Supplier Homologation** and click **Supplier Management**.', 'En el menú de la izquierda, abra **Homologación de Proveedores** y haga clic en **Gestión de Proveedores**.'),
    $L('A tela mostra os fornecedores ativos das suas unidades, com a faixa e a nota no ranking.', 'The screen shows the active suppliers of your units, with their ranking band and score.', 'La pantalla muestra los proveedores activos de sus unidades, con la franja y la nota en el ranking.'),
    'menu:HOMOLOGACAO_FORNECEDOR_FORNECEDOR', 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR');
$selForn = $P(
    $L('Clique na linha do fornecedor para selecioná-la (use a busca se a lista for grande).', 'Click the supplier row to select it (use the search if the list is long).', 'Haga clic en la fila del proveedor para seleccionarla (use la búsqueda si la lista es grande).'),
    $L('A linha fica destacada e os botões do fornecedor (Documentos, Contatos, Questionário...) ficam liberados.', 'The row is highlighted and the supplier buttons (Documents, Contacts, Questionnaire...) are enabled.', 'La fila queda resaltada y se habilitan los botones del proveedor (Documentos, Contactos, Cuestionario...).'),
    'linha', '', '', false);
$btn = static fn(string $id): string => 'css:[data-context-id="' . $id . '"]';

$guias = [
    // ------------------------------------------------------------ 1. cadastrar
    [
        'id' => 'forn-cadastrar',
        'titulo' => $L('Cadastrar um novo fornecedor', 'Register a new supplier', 'Registrar un nuevo proveedor'),
        'resumo' => $L('Incluir o fornecedor, dizer onde ele atua e quem responde por ele.', 'Add the supplier, say where it works and who is responsible for it.', 'Incluir el proveedor, indicar dónde actúa y quién responde por él.'),
        'antes' => $L('Os tipos de fornecedor e os critérios de cada tipo precisam estar cadastrados (Cadastros › Tipos de fornecedores).', 'Supplier types and the criteria of each type must be registered (Registers › Supplier types).', 'Los tipos de proveedor y los criterios de cada tipo deben estar registrados (Registros › Tipos de proveedores).'),
        'depois' => $L('Com os tipos escolhidos, os documentos exigidos do fornecedor podem ser gerados no botão Documentos.', 'With the types chosen, the documents required from the supplier can be created in the Documents button.', 'Con los tipos elegidos, los documentos exigidos al proveedor se pueden generar en el botón Documentos.'),
        'perfis' => ['adm', 'resp_forn'],
        'telas' => $tForn,
        'passos' => [
            $abrirForn,
            $P($L('Clique em **Novo** (sinal de mais).', 'Click **New** (plus sign).', 'Haga clic en **Nuevo** (signo más).'), $L('Uma linha nova aparece no topo do grid.', 'A new row appears at the top of the grid.', 'Aparece una fila nueva arriba de la grilla.'), 'acao:new', '', 'adm', false),
            $P($L('Escolha a **Unidade** e informe o **CNPJ/CPF**, a **Razão Social** e o **Nome Fantasia**.', 'Choose the **Unit** and enter the **Tax ID**, the **Company name** and the **Trade name**.', 'Elija la **Unidad** e indique el **CNPJ/CPF**, la **Razón Social** y el **Nombre Fantasía**.'), [], 'campo:CODUNIDADE', '', 'adm'),
            $P($L('Marque se **trabalha nas instalações**, se tem **produtos químicos** e se tem **equipamentos**: isso define o que será exigido dele.', 'Mark whether it **works on site**, has **chemicals** and has **equipment**: this defines what will be required.', 'Marque si **trabaja en las instalaciones**, si tiene **productos químicos** y si tiene **equipos**: eso define lo que se le exigirá.'), [], 'campo:TRABALHA_INSTALACAO_FORNECEDOR', '', 'adm'),
            $P($L('Escolha o **Responsável pelo fornecedor** e o **Responsável pela integração** e informe o **nível de serviço** e as **datas da avaliação**.', 'Choose the **Supplier owner** and the **Onboarding owner** and enter the **service level** and the **assessment dates**.', 'Elija el **Responsable del proveedor** y el **Responsable de la integración** e indique el **nivel de servicio** y las **fechas de evaluación**.'), [], 'campo:CODUSUARIO_RESPONSAVEL_FORNECEDOR', '', 'adm'),
            $P($L('Clique em **Salvar** (visto verde).', 'Click **Save** (green check).', 'Haga clic en **Guardar** (visto verde).'), $L('O fornecedor ganha código e os botões da linha passam a funcionar.', 'The supplier gets a code and the row buttons start working.', 'El proveedor recibe código y los botones de la fila empiezan a funcionar.'), 'acao:save', '', 'adm', false),
            $P($L('Com a linha selecionada, clique em **Tipos do fornecedor** e marque os tipos de serviço dele.', 'With the row selected, click **Supplier types** and tick its service types.', 'Con la fila seleccionada, haga clic en **Tipos del proveedor** y marque sus tipos de servicio.'), [], $btn('tipos'), '', 'adm', false),
            $P($L('Para o fornecedor acessar o portal, clique em **Usuários do fornecedor** e ligue os usuários dele.', 'For the supplier to access the portal, click **Supplier users** and link its users.', 'Para que el proveedor acceda al portal, haga clic en **Usuarios del proveedor** y vincule sus usuarios.'), [], $btn('usuarios'), '', 'adm', false),
        ],
    ],
    // ------------------------------------------------------------ 2. documentos
    [
        'id' => 'forn-documentos',
        'titulo' => $L('Registrar documentos e certificados', 'Record documents and certificates', 'Registrar documentos y certificados'),
        'resumo' => $L('Gerar a lista do que o fornecedor deve apresentar, anexar cada documento e dar a situação.', 'Create the list of what the supplier must provide, attach each document and set its status.', 'Generar la lista de lo que el proveedor debe presentar, adjuntar cada documento y dar la situación.'),
        'antes' => $L('O fornecedor precisa ter os tipos escolhidos (botão Tipos do fornecedor).', 'The supplier must have its types chosen (Supplier types button).', 'El proveedor debe tener los tipos elegidos (botón Tipos del proveedor).'),
        'depois' => $L('Ao chegar um arquivo, a IA confere o documento pelo modelo e procura sinais de adulteração; o parecer só sugere — quem aprova é a pessoa.', 'When a file arrives, the AI checks the document against the template and looks for signs of tampering; the opinion only suggests — a person approves.', 'Al llegar un archivo, la IA revisa el documento según el modelo y busca señales de adulteración; el parecer solo sugiere — quien aprueba es la persona.'),
        'perfis' => ['resp_doc', 'adm'],
        'telas' => array_merge($tDocs, $tForn),
        'passos' => [
            $abrirForn, $selForn,
            $P($L('Clique em **Documentos**.', 'Click **Documents**.', 'Haga clic en **Documentos**.'), $L('Abre a janela Documentos do fornecedor, com a situação de cada documento em cores.', 'The supplier Documents window opens, with each document status in colors.', 'Se abre la ventana Documentos del proveedor, con la situación de cada documento en colores.'), $btn('criterios'), '', 'resp_doc', false),
            $P($L('Se a lista estiver vazia ou faltar algum, clique em **Gerar documentos que faltam** (varinha).', 'If the list is empty or something is missing, click **Create missing documents** (wand).', 'Si la lista está vacía o falta alguno, haga clic en **Generar documentos faltantes** (varita).'), [], 'css:[data-acao="gerar"]', '', 'resp_doc', false),
            $P($L('Clique na linha do documento e em **Editar** (lápis).', 'Click the document row and **Edit** (pencil).', 'Haga clic en la fila del documento y en **Editar** (lápiz).'), $L('O formulário do documento abre abaixo do grid.', 'The document form opens below the grid.', 'El formulario del documento se abre debajo de la grilla.'), 'css:[data-acao="editar"]', '', 'resp_doc', false),
            $P($L('Clique em **Evidências** e envie o arquivo do documento.', 'Click **Evidence** and upload the document file.', 'Haga clic en **Evidencias** y suba el archivo del documento.'), $L('O número de arquivos aparece na coluna Evidências.', 'The number of files shows in the Evidence column.', 'El número de archivos aparece en la columna Evidencias.'), 'css:[data-form-evidencias]', '', 'resp_doc', false),
            $P($L('Escolha a **Situação do documento** e informe a **Data de validade** (ou "Aprovado sem validade"). Para reprovar, escreva o motivo na **Observação da empresa**.', 'Choose the **Document status** and enter the **Expiry date** (or "Approved without expiry"). To reject, write the reason in the **Company note**.', 'Elija la **Situación del documento** e indique la **Fecha de vigencia** (o "Aprobado sin vigencia"). Para reprobar, escriba el motivo en la **Observación de la empresa**.'), [], 'css:#fSITUACAO', '', 'resp_doc'),
            $P($L('Clique em **Salvar**.', 'Click **Save**.', 'Haga clic en **Guardar**.'), $L('A cor da linha muda conforme a situação e a validade.', 'The row color changes with the status and expiry.', 'El color de la fila cambia según la situación y la vigencia.'), 'css:[data-form-salvar]', '', 'resp_doc', false),
            $P($L('Para repetir os mesmos dados em vários documentos, use **Copiar dados do documento selecionado**, marque os destinos e **Colar nos documentos marcados**.', 'To repeat the same data in several documents, use **Copy data from the selected document**, tick the targets and **Paste into the checked documents**.', 'Para repetir los mismos datos en varios documentos, use **Copiar datos del documento seleccionado**, marque los destinos y **Pegar en los documentos marcados**.'), [], 'css:[data-acao="copiar"]', '', 'resp_doc', false),
            $P($L('Os certificados (ISO etc.) ficam no botão **Certificados** da Gestão de Fornecedores, com a validade de cada um.', 'Certificates (ISO etc.) are in the **Certificates** button of Supplier Management, each with its expiry.', 'Los certificados (ISO etc.) están en el botón **Certificados** de la Gestión de Proveedores, con la vigencia de cada uno.'), [], $btn('certificados'), '', 'resp_doc', false),
        ],
    ],
    // ------------------------------------------------------------ 3. contatos e responsabilidades
    [
        'id' => 'forn-contatos-responsaveis',
        'titulo' => $L('Contatos e responsáveis do fornecedor', 'Supplier contacts and owners', 'Contactos y responsables del proveedor'),
        'resumo' => $L('Registrar com quem falar no fornecedor e quem responde por ele na empresa.', 'Record who to talk to at the supplier and who is responsible for it in the company.', 'Registrar con quién hablar en el proveedor y quién responde por él en la empresa.'),
        'antes' => $L('', '', ''),
        'depois' => $L('Os responsáveis recebem os lembretes e as pendências do fornecedor.', 'The owners get the supplier reminders and pending items.', 'Los responsables reciben los recordatorios y pendientes del proveedor.'),
        'perfis' => ['adm', 'resp_forn'],
        'telas' => $tForn,
        'passos' => [
            $abrirForn, $selForn,
            $P($L('Clique em **Contatos** e inclua as pessoas de contato (nome, e-mail, telefone).', 'Click **Contacts** and add the contact people (name, e-mail, phone).', 'Haga clic en **Contactos** e incluya las personas de contacto (nombre, correo, teléfono).'), [], $btn('contatos'), '', 'resp_forn', false),
            $P($L('Clique em **Responsabilidades** para definir quem responde pelo fornecedor, pela documentação e pela integração, com a periodicidade.', 'Click **Responsibilities** to set who is responsible for the supplier, the documentation and the onboarding, with the frequency.', 'Haga clic en **Responsabilidades** para definir quién responde por el proveedor, la documentación y la integración, con la periodicidad.'), [], $btn('responsabilidades'), '', 'adm', false),
        ],
    ],
    // ------------------------------------------------------------ 4. funcionários
    [
        'id' => 'forn-funcionarios',
        'titulo' => $L('Cadastrar funcionários do fornecedor', 'Register supplier employees', 'Registrar empleados del proveedor'),
        'resumo' => $L('Incluir quem vai trabalhar nas instalações e registrar os critérios (ASO, NRs) e treinamentos de cada um.', 'Add who will work on site and record each one\'s criteria (medical exam, safety standards) and training.', 'Incluir quién trabajará en las instalaciones y registrar los criterios (ASO, NR) y capacitaciones de cada uno.'),
        'antes' => $L('O fornecedor precisa estar cadastrado e marcado como "trabalha nas instalações".', 'The supplier must be registered and marked as "works on site".', 'El proveedor debe estar registrado y marcado como "trabaja en las instalaciones".'),
        'depois' => $L('A Portaria passa a ver se o funcionário está liberado (critérios, treinamentos e integração em dia).', 'The Gatehouse can see whether the employee is cleared (criteria, training and onboarding up to date).', 'La Portería pasa a ver si el empleado está liberado (criterios, capacitaciones e integración al día).'),
        'perfis' => ['resp_int', 'adm'],
        'telas' => $tFunc,
        'passos' => [
            $P($L('No menu, abra **Homologação de Fornecedores** › **Gestão de Funcionários**.', 'In the menu, open **Supplier Homologation** › **Employee Management**.', 'En el menú, abra **Homologación de Proveedores** › **Gestión de Empleados**.'), [], 'menu:HOMOLOGACAO_FORNECEDOR_FUNCIONARIO', 'HOMOLOGACAO_FORNECEDOR_FUNCIONARIO', 'resp_int'),
            $P($L('Clique em **Novo**, escolha o **Fornecedor** e informe o **Nome**, o tipo de funcionário, a função e o setor.', 'Click **New**, choose the **Supplier** and enter the **Name**, the employee type, the role and the sector.', 'Haga clic en **Nuevo**, elija el **Proveedor** e indique el **Nombre**, el tipo de empleado, la función y el sector.'), [], 'acao:new', '', 'resp_int', false),
            $P($L('Clique em **Salvar**.', 'Click **Save**.', 'Haga clic en **Guardar**.'), [], 'acao:save', '', 'resp_int', false),
            $P($L('Selecione o funcionário e clique em **Critérios** para registrar cada exigência (atende?, validade, evidência).', 'Select the employee and click **Criteria** to record each requirement (met?, expiry, evidence).', 'Seleccione el empleado y haga clic en **Criterios** para registrar cada exigencia (¿cumple?, vigencia, evidencia).'), [], $btn('criterios'), '', 'resp_int', false),
            $P($L('Clique em **Treinamentos** para registrar os treinamentos feitos e a validade.', 'Click **Training** to record the training done and its validity.', 'Haga clic en **Capacitaciones** para registrar las capacitaciones realizadas y la vigencia.'), [], $btn('treinamentos'), '', 'resp_int', false),
        ],
    ],
    // ------------------------------------------------------------ 5. integração
    [
        'id' => 'forn-integracao',
        'titulo' => $L('Fazer a integração de um funcionário', 'Onboard an employee', 'Hacer la integración de un empleado'),
        'resumo' => $L('Mandar o link do questionário de integração, conferir as respostas e aprovar ou reprovar.', 'Send the onboarding questionnaire link, check the answers and approve or reject.', 'Enviar el enlace del cuestionario de integración, revisar las respuestas y aprobar o reprobar.'),
        'antes' => $L('O tipo do funcionário precisa ter um questionário publicado (Configurar questionários por tipo de funcionário).', 'The employee type must have a published questionnaire (Configure questionnaires by employee type).', 'El tipo de empleado debe tener un cuestionario publicado (Configurar cuestionarios por tipo de empleado).'),
        'depois' => $L('Aprovada: a integração ganha validade (libera na portaria) e o certificado em PDF fica disponível.', 'Approved: the onboarding gets a validity (cleared at the gatehouse) and the PDF certificate becomes available.', 'Aprobada: la integración recibe vigencia (libera en la portería) y el certificado en PDF queda disponible.'),
        'perfis' => ['resp_int'],
        'telas' => $tInteg,
        'passos' => [
            $P($L('No menu, abra **Homologação de Fornecedores** › **Integração de funcionários**.', 'In the menu, open **Supplier Homologation** › **Employee onboarding**.', 'En el menú, abra **Homologación de Proveedores** › **Integración de empleados**.'), $L('A lista mostra cada funcionário, o questionário do tipo dele, a situação e a validade da integração.', 'The list shows each employee, the questionnaire of their type, the status and the onboarding validity.', 'La lista muestra cada empleado, el cuestionario de su tipo, la situación y la vigencia de la integración.'), 'menu:HOMOLOGACAO_INTEGRACAO_FUNCIONARIOS', 'HOMOLOGACAO_INTEGRACAO_FUNCIONARIOS', 'resp_int'),
            $P($L('Na primeira vez, clique em **Configurar questionários por tipo de funcionário** (engrenagem) e escolha o questionário, a nota mínima e a validade.', 'The first time, click **Configure questionnaires by employee type** (gear) and choose the questionnaire, the minimum score and the validity.', 'La primera vez, haga clic en **Configurar cuestionarios por tipo de empleado** (engranaje) y elija el cuestionario, la nota mínima y la vigencia.'), [], 'css:[data-ifn-config]', '', 'resp_int', false),
            $P($L('Clique na linha do funcionário e em **Gerar link de integração** (corrente). Copie o link e mande ao funcionário — ele responde sem senha.', 'Click the employee row and **Generate induction link** (chain). Copy the link and send it to the employee — they answer without a password.', 'Haga clic en la fila del empleado y en **Generar enlace de integración** (cadena). Copie el enlace y envíelo al empleado — responde sin contraseña.'), [], 'css:[data-grid-acao-linha="link"]', '', 'resp_int', false),
            $P($L('Quando a situação mudar para respondida, clique em **Ver respostas e confirmar a decisão** (ou dê duplo clique na linha).', 'When the status changes to answered, click **See answers and confirm the decision** (or double-click the row).', 'Cuando la situación cambie a respondida, haga clic en **Ver respuestas y confirmar la decisión** (o doble clic en la fila).'), $L('Abre a decisão com as respostas, a nota e a sugestão pela nota mínima.', 'The decision opens with the answers, the score and the suggestion by the minimum score.', 'Se abre la decisión con las respuestas, la nota y la sugerencia por la nota mínima.'), 'css:[data-grid-acao-linha="decidir"]', '', 'resp_int', false),
            $P($L('Clique em **Aprovar** ou **Reprovar**. A decisão é sempre sua; a nota só sugere.', 'Click **Approve** or **Reject**. The decision is always yours; the score only suggests.', 'Haga clic en **Aprobar** o **Reprobar**. La decisión es siempre suya; la nota solo sugiere.'), [], 'css:[data-ifn-dec="APROVADA"]', '', 'resp_int', false),
            $P($L('Para imprimir, clique em **Certificado de integração (PDF)**.', 'To print, click **Induction certificate (PDF)**.', 'Para imprimir, haga clic en **Certificado de integración (PDF)**.'), [], 'css:[data-grid-acao-linha="certificado"]', '', 'resp_int', false),
        ],
    ],
    // ------------------------------------------------------------ 6. questionário
    [
        'id' => 'forn-questionario',
        'titulo' => $L('Aplicar o questionário de avaliação', 'Apply the assessment questionnaire', 'Aplicar el cuestionario de evaluación'),
        'resumo' => $L('Responder o questionário do fornecedor e enviar o resultado para a decisão de homologação.', 'Answer the supplier questionnaire and send the result for the homologation decision.', 'Responder el cuestionario del proveedor y enviar el resultado para la decisión de homologación.'),
        'antes' => $L('Precisa existir um questionário publicado para fornecedores.', 'A published questionnaire for suppliers must exist.', 'Debe existir un cuestionario publicado para proveedores.'),
        'depois' => $L('O sistema calcula o resultado e abre a avaliação; quem aprova vê o pedido em Aprovações (barra da direita) e decide com motivo.', 'The system calculates the result and opens the assessment; the approver sees the request in Approvals (right bar) and decides with a reason.', 'El sistema calcula el resultado y abre la evaluación; quien aprueba ve el pedido en Aprobaciones (barra derecha) y decide con motivo.'),
        'perfis' => ['resp_forn', 'aprovador'],
        'telas' => $tForn,
        'passos' => [
            $abrirForn, $selForn,
            $P($L('Clique em **Questionário do fornecedor** (botão verde).', 'Click **Supplier questionnaire** (green button).', 'Haga clic en **Cuestionario del proveedor** (botón verde).'), [], $btn('questionario'), '', 'resp_forn', false),
            $P($L('Escolha o **Questionário publicado** e clique em **Iniciar execução**.', 'Choose the **Published questionnaire** and click **Start**.', 'Elija el **Cuestionario publicado** y haga clic en **Iniciar ejecución**.'), [], '', '', 'resp_forn', false),
            $P($L('Responda as perguntas, anexe as evidências pedidas e clique em **Concluir questionário**.', 'Answer the questions, attach the requested evidence and click **Finish questionnaire**.', 'Responda las preguntas, adjunte las evidencias pedidas y haga clic en **Concluir cuestionario**.'), $L('Aparece o percentual obtido.', 'The percentage obtained appears.', 'Aparece el porcentaje obtenido.'), '', '', 'resp_forn', false),
            $P($L('Quem aprova abre **Aprovações** na barra da direita, vê o resultado e aprova ou rejeita, com motivo.', 'The approver opens **Approvals** in the right bar, sees the result and approves or rejects, with a reason.', 'Quien aprueba abre **Aprobaciones** en la barra derecha, ve el resultado y aprueba o rechaza, con motivo.'), [], '', '', 'aprovador', false),
        ],
    ],
    // ------------------------------------------------------------ 7. auditoria e plano
    [
        'id' => 'forn-auditoria-plano',
        'titulo' => $L('Registrar auditoria e plano de ação', 'Record audit and action plan', 'Registrar auditoría y plan de acción'),
        'resumo' => $L('Registrar a auditoria de homologação e as ações corretivas das falhas encontradas.', 'Record the homologation audit and the corrective actions for the failures found.', 'Registrar la auditoría de homologación y las acciones correctivas de las fallas encontradas.'),
        'antes' => $L('', '', ''),
        'depois' => $L('Auditoria vencida ou reprovada aparece no Painel de Homologação e pesa no ranking.', 'An overdue or failed audit shows on the Homologation Dashboard and weighs on the ranking.', 'Una auditoría vencida o reprobada aparece en el Panel de Homologación y pesa en el ranking.'),
        'perfis' => ['resp_forn', 'adm'],
        'telas' => $tForn,
        'passos' => [
            $abrirForn, $selForn,
            $P($L('Clique em **Auditoria de homologação** e registre a data, o resultado e a próxima auditoria.', 'Click **Homologation audit** and record the date, the result and the next audit.', 'Haga clic en **Auditoría de homologación** y registre la fecha, el resultado y la próxima auditoría.'), [], $btn('auditoria'), '', 'resp_forn', false),
            $P($L('Para cada falha, clique em **Plano de ação** e inclua a ação, o responsável e o prazo. Se a ação precisar de avaliação da eficácia, marque **Avaliar eficácia? = Sim** nas últimas colunas (o avaliador, vazio = o responsável do fornecedor, registra Eficaz ou Não eficaz quando a ação chegar a 100%).', 'For each failure, click **Action plan** and add the action, the owner and the deadline. If the action needs an effectiveness review, set **Review effectiveness? = Yes** in the last columns (the reviewer, empty = the supplier owner, records Effective or Not effective when the action reaches 100%).', 'Para cada falla, haga clic en **Plan de acción** e incluya la acción, el responsable y el plazo. Si la acción necesita evaluación de eficacia, marque **¿Evaluar eficacia? = Sí** en las últimas columnas (el evaluador, vacío = el responsable del proveedor, registra Eficaz o No eficaz cuando la acción llegue al 100%).'), [], $btn('plano'), '', 'resp_forn', false),
        ],
    ],
    // ------------------------------------------------------------ 8. portaria
    [
        'id' => 'forn-portaria',
        'titulo' => $L('Conferir na portaria se o fornecedor ou o funcionário pode entrar', 'Check at the gatehouse whether the supplier or employee can enter', 'Verificar en la portería si el proveedor o el empleado puede entrar'),
        'resumo' => $L('Consultar a situação da documentação antes de liberar a entrada.', 'Check the documentation status before allowing entry.', 'Consultar la situación de la documentación antes de liberar la entrada.'),
        'antes' => $L('', '', ''),
        'depois' => $L('Verde: em dia. Amarelo: vence nos dias de reação ou está com a empresa. Vermelho: vencido, reprovado ou pendente com o fornecedor.', 'Green: up to date. Yellow: expires within the reaction days or is with the company. Red: expired, rejected or pending with the supplier.', 'Verde: al día. Amarillo: vence en los días de reacción o está con la empresa. Rojo: vencido, reprobado o pendiente con el proveedor.'),
        'perfis' => [],
        'telas' => array_merge($tPortF, $tPortU),
        'passos' => [
            $P($L('No menu, abra **Portaria** › **Documentação de funcionário** (ou **Documentação de fornecedor**).', 'In the menu, open **Gatehouse** › **Employee documentation** (or **Supplier documentation**).', 'En el menú, abra **Portería** › **Documentación de empleado** (o **Documentación de proveedor**).'), [], 'menu:PORTARIA_DOCUMENTACAO_FUNCIONARIO', 'PORTARIA_DOCUMENTACAO_FUNCIONARIO'),
            $P($L('Digite o nome na **busca**.', 'Type the name in the **search**.', 'Escriba el nombre en la **búsqueda**.'), [], 'busca'),
            $P($L('Use **Filtrar por situação** para ver só os vencidos ou só os em dia.', 'Use **Filter by status** to see only expired or only up-to-date items.', 'Use **Filtrar por situación** para ver solo los vencidos o solo los al día.'), [], 'texto:Filtrar por situação', '', '', false),
            $P($L('Para ver o documento, clique no botão de **Evidências** da linha (mostra a quantidade de arquivos).', 'To see the document, click the row **Evidence** button (it shows the number of files).', 'Para ver el documento, haga clic en el botón **Evidencias** de la fila (muestra la cantidad de archivos).'), [], '', '', '', false),
        ],
    ],
    // ------------------------------------------------------------ 9. painel
    [
        'id' => 'forn-painel',
        'titulo' => $L('Acompanhar as pendências no Painel de Homologação', 'Follow the pending items on the Homologation Dashboard', 'Seguir los pendientes en el Panel de Homologación'),
        'resumo' => $L('Ver o que está vencido ou pendente, abrir a ficha do fornecedor e cobrar os responsáveis.', 'See what is expired or pending, open the supplier sheet and chase the owners.', 'Ver lo que está vencido o pendiente, abrir la ficha del proveedor y cobrar a los responsables.'),
        'antes' => $L('', '', ''),
        'depois' => $L('Toda segunda-feira chega um resumo das suas pendências em Home › Alertas.', 'Every Monday a summary of your pending items arrives in Home › Alerts.', 'Cada lunes llega un resumen de sus pendientes en Inicio › Alertas.'),
        'perfis' => ['adm', 'resp_doc', 'resp_forn'],
        'telas' => $tPainel,
        'passos' => [
            $P($L('No menu, abra **Homologação de Fornecedores** › **Painel de Homologação**.', 'In the menu, open **Supplier Homologation** › **Homologation Dashboard**.', 'En el menú, abra **Homologación de Proveedores** › **Panel de Homologación**.'), [], 'menu:HOMOLOGACAO_PAINEL', 'HOMOLOGACAO_PAINEL'),
            $P($L('Ajuste os filtros do topo (unidade, tipo, responsável...). Eles valem para todos os números, consultas e gráficos. **Salvar filtro** guarda a escolha.', 'Adjust the top filters (unit, type, owner...). They apply to all numbers, queries and charts. **Save filter** keeps the choice.', 'Ajuste los filtros de arriba (unidad, tipo, responsable...). Valen para todos los números, consultas y gráficos. **Guardar filtro** guarda la elección.'), [], 'css:[data-salvar-filtro]', '', '', false),
            $P($L('Clique num número (por exemplo **Vencidos**) para ver a lista daqueles documentos.', 'Click a number (for example **Expired**) to see the list of those documents.', 'Haga clic en un número (por ejemplo **Vencidos**) para ver la lista de esos documentos.'), [], 'texto:Vencidos', '', '', false),
            $P($L('Na lista, **Abrir ficha** mostra tudo do fornecedor numa página: documentos, funcionários, auditoria e histórico.', 'In the list, **Open sheet** shows everything about the supplier on one page: documents, employees, audit and history.', 'En la lista, **Abrir ficha** muestra todo del proveedor en una página: documentos, empleados, auditoría e historial.'), [], 'texto:Abrir ficha', '', '', false),
            $P($L('Clique em **Cobrar responsáveis** para mandar um alerta a quem responde pelos itens da lista.', 'Click **Chase owners** to send an alert to whoever is responsible for the items on the list.', 'Haga clic en **Cobrar a los responsables** para enviar una alerta a quien responde por los ítems de la lista.'), [], 'css:[data-cobrar]', '', 'adm', false),
            $P($L('Use **Exportar esta lista** para o Excel (chega nos Alertas) ou **Gerar PDF** para imprimir.', 'Use **Export this list** for Excel (it arrives in Alerts) or **Create PDF** to print.', 'Use **Exportar esta lista** para Excel (llega en las Alertas) o **Generar PDF** para imprimir.'), [], 'css:[data-exportar-lista]', '', '', false),
        ],
    ],
    // ------------------------------------------------------------ 10. relatórios
    [
        'id' => 'forn-relatorios',
        'titulo' => $L('Tirar um relatório de homologação', 'Run a homologation report', 'Generar un informe de homologación'),
        'resumo' => $L('Escolher o relatório no catálogo, filtrar, agrupar e salvar como "meu relatório".', 'Choose the report in the catalog, filter, group and save it as "my report".', 'Elegir el informe en el catálogo, filtrar, agrupar y guardarlo como "mi informe".'),
        'antes' => $L('', '', ''),
        'depois' => $L('Os relatórios salvos podem chegar toda semana nos seus Alertas.', 'Saved reports can arrive every week in your Alerts.', 'Los informes guardados pueden llegar cada semana en sus Alertas.'),
        'perfis' => [],
        'telas' => $tRel,
        'passos' => [
            $P($L('No menu, abra **Homologação de Fornecedores** › **Relatórios de Homologação**.', 'In the menu, open **Supplier Homologation** › **Homologation reports**.', 'En el menú, abra **Homologación de Proveedores** › **Informes de Homologación**.'), $L('O catálogo mostra cada relatório e quantos itens pedem ação (vermelhos).', 'The catalog shows each report and how many items need action (red).', 'El catálogo muestra cada informe y cuántos ítems piden acción (rojos).'), 'menu:HOMOLOGACAO_RELATORIOS_GERENCIAIS', 'HOMOLOGACAO_RELATORIOS_GERENCIAIS'),
            $P($L('Clique no relatório desejado (por exemplo **Documentos do fornecedor**).', 'Click the report you want (for example **Supplier documents**).', 'Haga clic en el informe deseado (por ejemplo **Documentos del proveedor**).'), [], 'texto:Documentos do fornecedor', '', '', false),
            $P($L('Use os filtros rápidos (Vencidos, Pendentes com o fornecedor...) e os filtros das colunas.', 'Use the quick filters (Expired, Pending with the supplier...) and the column filters.', 'Use los filtros rápidos (Vencidos, Pendientes con el proveedor...) y los filtros de las columnas.'), [], 'texto:Vencidos', '', '', false),
            $P($L('Clique em **Salvar como meu relatório** para guardar esta visão e, se quiser, recebê-la toda semana.', 'Click **Save as my report** to keep this view and, if you want, receive it every week.', 'Haga clic en **Guardar como mi informe** para guardar esta vista y, si quiere, recibirla cada semana.'), [], 'texto:Salvar como meu relatório', '', '', false),
        ],
    ],
    // ------------------------------------------------------------ 11. ranking
    [
        'id' => 'forn-ranking',
        'titulo' => $L('Ver por que um fornecedor está na faixa D', 'See why a supplier is in band D', 'Ver por qué un proveedor está en la franja D'),
        'resumo' => $L('Abrir o detalhe da nota e ver o que está puxando o fornecedor para baixo.', 'Open the score detail and see what is pulling the supplier down.', 'Abrir el detalle de la nota y ver qué está bajando al proveedor.'),
        'antes' => $L('A nota é recalculada pelo serviço agendado; mudanças de hoje aparecem no próximo cálculo.', 'The score is recalculated by the scheduled service; today\'s changes show in the next calculation.', 'La nota la recalcula el servicio programado; los cambios de hoy aparecen en el próximo cálculo.'),
        'depois' => $L('Resolvidas as pendências (documentos, treinamentos, NCs), a nota sobe no próximo cálculo.', 'Once the pending items (documents, training, NCs) are solved, the score goes up in the next calculation.', 'Resueltos los pendientes (documentos, capacitaciones, NC), la nota sube en el próximo cálculo.'),
        'perfis' => [],
        'telas' => array_merge(['arquivo:homologacaofornecedor_ranking_detalhe.php'], $tRank),
        'passos' => [
            $P($L('No menu, abra **Homologação de Fornecedores** › **Ranking de fornecedores**.', 'In the menu, open **Supplier Homologation** › **Supplier ranking**.', 'En el menú, abra **Homologación de Proveedores** › **Ranking de proveedores**.'), $L('Os fornecedores aparecem em ordem de nota (0–100), com a faixa A/B/C/D e a tendência.', 'Suppliers appear by score (0–100), with band A/B/C/D and trend.', 'Los proveedores aparecen por nota (0–100), con la franja A/B/C/D y la tendencia.'), 'menu:HOMOLOGACAO_FORNECEDOR_RANKING', 'HOMOLOGACAO_FORNECEDOR_RANKING'),
            $P($L('Digite o nome do fornecedor na **busca** ou escolha a unidade.', 'Type the supplier name in the **search** or choose the unit.', 'Escriba el nombre del proveedor en la **búsqueda** o elija la unidad.'), [], 'css:[data-rk-busca]'),
            $P($L('Clique na linha do fornecedor.', 'Click the supplier row.', 'Haga clic en la fila del proveedor.'), $L('Abre o detalhe: cada componente (peso, nota, quanto contribui), o bloqueio e a evolução.', 'The detail opens: each component (weight, score, contribution), the block and the trend.', 'Se abre el detalle: cada componente (peso, nota, cuánto aporta), el bloqueo y la evolución.'), 'css:tr[data-rk-linha]', '', '', false),
            $P($L('Veja a lista "o que está puxando a nota para baixo": documentos vencidos ou pendentes, treinamentos e não conformidades. Resolva esses itens.', 'Look at the "what is pulling the score down" list: expired or pending documents, training and non-conformities. Solve those items.', 'Vea la lista "qué está bajando la nota": documentos vencidos o pendientes, capacitaciones y no conformidades. Resuelva esos ítems.'), [], '', '', '', false),
        ],
    ],
    // ------------------------------------------------------------ 12. pesos e bloqueio
    [
        'id' => 'forn-bloqueio',
        'titulo' => $L('Bloquear ou desbloquear um fornecedor', 'Block or unblock a supplier', 'Bloquear o desbloquear un proveedor'),
        'resumo' => $L('Impedir (ou voltar a permitir) que um fornecedor trabalhe, com motivo, e ajustar os pesos do ranking.', 'Prevent (or allow again) a supplier from working, with a reason, and adjust the ranking weights.', 'Impedir (o volver a permitir) que un proveedor trabaje, con motivo, y ajustar los pesos del ranking.'),
        'antes' => $L('ADMINISTRADOR, ADM DA EMPRESA, GESTOR ou quem tiver Editar no menu Pesos do ranking de fornecedores.', 'ADMINISTRADOR, ADM DA EMPRESA, GESTOR or whoever has Edit on the Supplier ranking weights menu.', 'ADMINISTRADOR, ADM DA EMPRESA, GESTOR o quien tenga Editar en el menú Pesos del ranking de proveedores.'),
        'depois' => $L('Bloqueado, o fornecedor aparece como tal na Gestão de Fornecedores, no ranking e na portaria. Tudo fica no histórico.', 'Once blocked, the supplier shows as such in Supplier Management, the ranking and the gatehouse. Everything goes to the history.', 'Bloqueado, el proveedor aparece así en la Gestión de Proveedores, el ranking y la portería. Todo queda en el historial.'),
        'perfis' => ['adm'],
        'telas' => $tPesos,
        'passos' => [
            $P($L('No menu, abra **Administração** › **Pesos do ranking de fornecedores**.', 'In the menu, open **Administration** › **Supplier ranking weights**.', 'En el menú, abra **Administración** › **Pesos del ranking de proveedores**.'), [], 'menu:ADMINISTRACAO_PESOS', 'ADMINISTRACAO_PESOS', 'adm'),
            $P($L('Em **Bloqueio de fornecedores**, clique em **Bloquear** na linha do fornecedor (ou **Desbloquear**, se já estiver bloqueado).', 'In **Supplier block**, click **Block** on the supplier row (or **Unblock**, if already blocked).', 'En **Bloqueo de proveedores**, haga clic en **Bloquear** en la fila del proveedor (o **Desbloquear**, si ya está bloqueado).'), [], 'css:[data-rp-bloq]', '', 'adm', false),
            $P($L('Escreva o motivo (obrigatório) e confirme.', 'Write the reason (required) and confirm.', 'Escriba el motivo (obligatorio) y confirme.'), [], 'css:[data-rp-confirmar]', '', 'adm', false),
            $P($L('Para mudar os pesos da nota (soma 100), as faixas ou o desconto das NCs, altere os valores e clique em **Salvar configuração**; **Recalcular agora** pede um novo cálculo.', 'To change the score weights (sum 100), the bands or the NC discount, change the values and click **Save configuration**; **Recalculate now** requests a new calculation.', 'Para cambiar los pesos de la nota (suma 100), las franjas o el descuento de las NC, cambie los valores y haga clic en **Guardar configuración**; **Recalcular ahora** pide un nuevo cálculo.'), [], 'css:[data-rp-recalcular]', '', 'adm', false),
        ],
    ],
];

$telas = [
    // ------------------------------------------------------------ janelas (vêm primeiro)
    [
        'chaves' => $tDocs,
        'oque' => $L('Documentos do fornecedor: cada linha é uma exigência dos tipos dele, com situação, validade, responsáveis e evidências. A cor da linha resume a situação.', 'Supplier documents: each row is a requirement of its types, with status, expiry, owners and evidence. The row color sums up the status.', 'Documentos del proveedor: cada fila es una exigencia de sus tipos, con situación, vigencia, responsables y evidencias. El color de la fila resume la situación.'),
        'pode' => [
            ['texto' => $L('Editar o documento selecionado', 'Edit the selected document', 'Editar el documento seleccionado'), 'alvo' => 'css:[data-acao="editar"]'],
            ['texto' => $L('Gerar os documentos que faltam', 'Create the missing documents', 'Generar los documentos faltantes'), 'alvo' => 'css:[data-acao="gerar"]'],
            ['texto' => $L('Copiar os dados de um documento e colar em outros', 'Copy one document\'s data and paste into others', 'Copiar los datos de un documento y pegar en otros'), 'alvo' => 'css:[data-acao="copiar"]'],
            ['texto' => $L('Ver o histórico de alterações', 'See the change history', 'Ver el historial de cambios'), 'alvo' => 'css:[data-acao="historico"]'],
            ['texto' => $L('Reiniciar um documento (limpa os dados)', 'Reset a document (clears the data)', 'Reiniciar un documento (limpia los datos)'), 'alvo' => 'css:[data-acao="reiniciar"]'],
        ],
        'antes' => $L('O fornecedor externo grava só a parte dele (validade, protocolo, observação do fornecedor e evidências).', 'The external supplier saves only its part (expiry, protocol, supplier note and evidence).', 'El proveedor externo graba solo su parte (vigencia, protocolo, observación del proveedor y evidencias).'),
        'depois' => $L('Arquivo novo é conferido pela IA (modelo do documento e adulteração). Documento de risco alto só é aprovado com ciência e motivo.', 'A new file is checked by the AI (document template and tampering). A high-risk document is only approved with acknowledgement and reason.', 'El archivo nuevo lo revisa la IA (modelo del documento y adulteración). Un documento de riesgo alto solo se aprueba con ciencia y motivo.'),
        'duvidas' => [
            ['p' => $L('Por que a lista está vazia?', 'Why is the list empty?', '¿Por qué la lista está vacía?'), 'r' => $L('Os documentos vêm dos tipos do fornecedor e dos critérios de cada tipo. Confira os Tipos do fornecedor e clique em Gerar documentos que faltam.', 'Documents come from the supplier types and the criteria of each type. Check the Supplier types and click Create missing documents.', 'Los documentos vienen de los tipos del proveedor y los criterios de cada tipo. Revise los Tipos del proveedor y haga clic en Generar documentos faltantes.')],
            ['p' => $L('O que são os dias de reação?', 'What are reaction days?', '¿Qué son los días de reacción?'), 'r' => $L('Quantos dias antes do vencimento o documento passa a amarelo e os responsáveis são avisados.', 'How many days before expiry the document turns yellow and the owners are warned.', 'Cuántos días antes del vencimiento el documento pasa a amarillo y los responsables reciben el aviso.')],
        ],
    ],
    [
        'chaves' => ['arquivo:homologacaofornecedor_ranking_detalhe.php'],
        'oque' => $L('Detalhe da nota do fornecedor: cada componente com peso, nota e quanto contribui, o bloqueio, a evolução e o que está puxando a nota para baixo.', 'Supplier score detail: each component with weight, score and contribution, the block, the trend and what is pulling the score down.', 'Detalle de la nota del proveedor: cada componente con peso, nota y aporte, el bloqueo, la evolución y lo que está bajando la nota.'),
        'pode' => [],
        'antes' => $L('', '', ''),
        'depois' => $L('Componente que não existe para o fornecedor tem o peso redistribuído entre os demais.', 'A component that does not apply to the supplier has its weight spread among the others.', 'El componente que no existe para el proveedor tiene su peso redistribuido entre los demás.'),
        'duvidas' => [],
    ],
    [
        'chaves' => ['arquivo:homologacaofornecedores_ficha.php'],
        'oque' => $L('Ficha do fornecedor: tudo do fornecedor numa página — dados, documentos por situação, funcionários, auditoria e histórico.', 'Supplier sheet: everything about the supplier on one page — data, documents by status, employees, audit and history.', 'Ficha del proveedor: todo del proveedor en una página — datos, documentos por situación, empleados, auditoría e historial.'),
        'pode' => [],
        'antes' => $L('', '', ''),
        'depois' => $L('', '', ''),
        'duvidas' => [],
    ],
    // ------------------------------------------------------------ telas do menu
    [
        'chaves' => $tForn,
        'oque' => $L('Gestão de Fornecedores: o cadastro principal. Cada linha é um fornecedor ativo, com faixa e nota no ranking, bloqueio, tipo, responsáveis e datas de avaliação.', 'Supplier Management: the main register. Each row is an active supplier, with ranking band and score, block, type, owners and assessment dates.', 'Gestión de Proveedores: el registro principal. Cada fila es un proveedor activo, con franja y nota en el ranking, bloqueo, tipo, responsables y fechas de evaluación.'),
        'pode' => [
            ['texto' => $L('Incluir um fornecedor', 'Add a supplier', 'Incluir un proveedor'), 'alvo' => 'acao:new'],
            ['texto' => $L('Documentos exigidos do fornecedor', 'Documents required from the supplier', 'Documentos exigidos al proveedor'), 'alvo' => 'css:[data-context-id="criterios"]'],
            ['texto' => $L('Tipos do fornecedor', 'Supplier types', 'Tipos del proveedor'), 'alvo' => 'css:[data-context-id="tipos"]'],
            ['texto' => $L('Questionário do fornecedor', 'Supplier questionnaire', 'Cuestionario del proveedor'), 'alvo' => 'css:[data-context-id="questionario"]'],
            ['texto' => $L('Responsabilidades', 'Responsibilities', 'Responsabilidades'), 'alvo' => 'css:[data-context-id="responsabilidades"]'],
            ['texto' => $L('Exportar para Excel', 'Export to Excel', 'Exportar a Excel'), 'alvo' => 'acao:export'],
        ],
        'antes' => $L('Os botões do fornecedor ficam cinza até você clicar numa linha. Você vê os fornecedores das suas unidades e os pelos quais responde.', 'The supplier buttons stay grey until you click a row. You see the suppliers of your units and those you are responsible for.', 'Los botones del proveedor quedan grises hasta que haga clic en una fila. Usted ve los proveedores de sus unidades y aquellos de los que es responsable.'),
        'depois' => $L('Fornecedor desativado sai desta lista e vai para Fornecedores desativados.', 'A deactivated supplier leaves this list and goes to Deactivated suppliers.', 'El proveedor desactivado sale de esta lista y va a Proveedores desactivados.'),
        'duvidas' => [
            ['p' => $L('O que significam as faixas A, B, C e D?', 'What do bands A, B, C and D mean?', '¿Qué significan las franjas A, B, C y D?'), 'r' => $L('É a nota do ranking (0–100) agrupada: A é o melhor. Veja o motivo em Ranking de fornecedores, clicando na linha.', 'It is the ranking score (0–100) grouped: A is the best. See why in Supplier ranking, clicking the row.', 'Es la nota del ranking (0–100) agrupada: A es la mejor. Vea el motivo en Ranking de proveedores, haciendo clic en la fila.')],
        ],
    ],
    [
        'chaves' => $tFunc,
        'oque' => $L('Gestão de Funcionários: os funcionários dos fornecedores que trabalham nas instalações, com critérios (ASO, NRs), treinamentos e anexos.', 'Employee Management: supplier employees who work on site, with criteria (medical exam, safety standards), training and attachments.', 'Gestión de Empleados: los empleados de los proveedores que trabajan en las instalaciones, con criterios (ASO, NR), capacitaciones y adjuntos.'),
        'pode' => [
            ['texto' => $L('Incluir um funcionário', 'Add an employee', 'Incluir un empleado'), 'alvo' => 'acao:new'],
            ['texto' => $L('Critérios do funcionário', 'Employee criteria', 'Criterios del empleado'), 'alvo' => 'css:[data-context-id="criterios"]'],
            ['texto' => $L('Treinamentos do funcionário', 'Employee training', 'Capacitaciones del empleado'), 'alvo' => 'css:[data-context-id="treinamentos"]'],
        ],
        'antes' => $L('Selecione o funcionário (clique na linha) para liberar os botões.', 'Select the employee (click the row) to enable the buttons.', 'Seleccione el empleado (clic en la fila) para habilitar los botones.'),
        'depois' => $L('A situação dos critérios e treinamentos aparece na Portaria › Documentação de funcionário.', 'The status of criteria and training shows in Gatehouse › Employee documentation.', 'La situación de criterios y capacitaciones aparece en Portería › Documentación de empleado.'),
        'duvidas' => [],
    ],
    [
        'chaves' => $tInteg,
        'oque' => $L('Integração de funcionários: cada funcionário com o questionário do tipo dele, a situação da integração (aguardando, respondida, aprovada, reprovada), a validade e se está liberado na portaria.', 'Employee onboarding: each employee with the questionnaire of their type, the onboarding status (waiting, answered, approved, rejected), the validity and whether they are cleared at the gatehouse.', 'Integración de empleados: cada empleado con el cuestionario de su tipo, la situación de la integración (esperando, respondida, aprobada, reprobada), la vigencia y si está liberado en la portería.'),
        'pode' => [
            ['texto' => $L('Gerar o link para o funcionário responder', 'Generate the link for the employee to answer', 'Generar el enlace para que el empleado responda'), 'alvo' => 'css:[data-grid-acao-linha="link"]'],
            ['texto' => $L('Ver as respostas e decidir', 'See the answers and decide', 'Ver las respuestas y decidir'), 'alvo' => 'css:[data-grid-acao-linha="decidir"]'],
            ['texto' => $L('Certificado de integração (PDF)', 'Induction certificate (PDF)', 'Certificado de integración (PDF)'), 'alvo' => 'css:[data-grid-acao-linha="certificado"]'],
            ['texto' => $L('Configurar questionários por tipo de funcionário', 'Configure questionnaires by employee type', 'Configurar cuestionarios por tipo de empleado'), 'alvo' => 'css:[data-ifn-config]'],
        ],
        'antes' => $L('O tipo do funcionário precisa ter questionário publicado; sem ele, o link não pode ser gerado.', 'The employee type needs a published questionnaire; without it, the link cannot be generated.', 'El tipo de empleado necesita un cuestionario publicado; sin él, no se puede generar el enlace.'),
        'depois' => $L('A nota mínima só sugere o resultado; a decisão é de quem confirma. Integração vencida bloqueia na portaria.', 'The minimum score only suggests the result; the decision belongs to whoever confirms. Expired onboarding blocks at the gatehouse.', 'La nota mínima solo sugiere el resultado; la decisión es de quien confirma. La integración vencida bloquea en la portería.'),
        'duvidas' => [],
    ],
    [
        'chaves' => $tPainel,
        'oque' => $L('Painel de Homologação: números clicáveis (vencidos, pendentes, sem responsável...), consultas prontas, gráficos e a lista dos itens, tudo com os mesmos filtros.', 'Homologation Dashboard: clickable numbers (expired, pending, no owner...), ready-made queries, charts and the item list, all with the same filters.', 'Panel de Homologación: números clicables (vencidos, pendientes, sin responsable...), consultas listas, gráficos y la lista de ítems, todo con los mismos filtros.'),
        'pode' => [
            ['texto' => $L('Guardar os filtros escolhidos', 'Keep the chosen filters', 'Guardar los filtros elegidos'), 'alvo' => 'css:[data-salvar-filtro]'],
            ['texto' => $L('Cobrar os responsáveis da lista', 'Chase the owners of the list', 'Cobrar a los responsables de la lista'), 'alvo' => 'css:[data-cobrar]'],
            ['texto' => $L('Exportar a lista para o Excel', 'Export the list to Excel', 'Exportar la lista a Excel'), 'alvo' => 'css:[data-exportar-lista]'],
            ['texto' => $L('Gerar o PDF', 'Create the PDF', 'Generar el PDF'), 'alvo' => 'css:[data-imprimir]'],
        ],
        'antes' => $L('', '', ''),
        'depois' => $L('O Excel é preparado em segundo plano e chega em Home › Alertas.', 'The Excel file is prepared in the background and arrives in Home › Alerts.', 'El Excel se prepara en segundo plano y llega en Inicio › Alertas.'),
        'duvidas' => [],
    ],
    [
        'chaves' => $tRel,
        'oque' => $L('Relatórios de Homologação: catálogo dos relatórios (documentos, auditoria, nível de serviço, químicos, equipamentos, plano de ação, funcionários, integração, treinamentos, unificado e histórico) com filtros rápidos, agrupamento e evidências.', 'Homologation reports: catalog of reports (documents, audit, service level, chemicals, equipment, action plan, employees, onboarding, training, unified and history) with quick filters, grouping and evidence.', 'Informes de Homologación: catálogo de informes (documentos, auditoría, nivel de servicio, químicos, equipos, plan de acción, empleados, integración, capacitaciones, unificado e historial) con filtros rápidos, agrupación y evidencias.'),
        'pode' => [
            ['texto' => $L('Salvar como meu relatório', 'Save as my report', 'Guardar como mi informe'), 'alvo' => 'texto:Salvar como meu relatório'],
        ],
        'antes' => $L('', '', ''),
        'depois' => $L('Os relatórios salvos ficam em "Meus relatórios" e podem ser enviados toda semana por alerta.', 'Saved reports stay in "My reports" and can be sent every week by alert.', 'Los informes guardados quedan en "Mis informes" y pueden enviarse cada semana por alerta.'),
        'duvidas' => [],
    ],
    [
        'chaves' => $tRank,
        'oque' => $L('Ranking de fornecedores: os fornecedores ativos em ordem de nota (0–100), com faixa A/B/C/D, tendência, componentes da nota e bloqueio.', 'Supplier ranking: active suppliers by score (0–100), with band A/B/C/D, trend, score components and block.', 'Ranking de proveedores: los proveedores activos por nota (0–100), con franja A/B/C/D, tendencia, componentes de la nota y bloqueo.'),
        'pode' => [
            ['texto' => $L('Buscar o fornecedor', 'Find the supplier', 'Buscar el proveedor'), 'alvo' => 'css:[data-rk-busca]'],
            ['texto' => $L('Abrir o detalhe da nota (clique na linha)', 'Open the score detail (click the row)', 'Abrir el detalle de la nota (clic en la fila)'), 'alvo' => 'css:tr[data-rk-linha]'],
        ],
        'antes' => $L('Só consulta. A nota é calculada pelo serviço agendado.', 'Read only. The score is calculated by the scheduled service.', 'Solo consulta. La nota la calcula el servicio programado.'),
        'depois' => $L('', '', ''),
        'duvidas' => [],
    ],
    [
        'chaves' => $tPesos,
        'oque' => $L('Pesos do ranking: pesos dos componentes da nota (soma 100), faixas A/B/C/D, desconto das não conformidades e o bloqueio de fornecedores com motivo.', 'Ranking weights: score component weights (sum 100), A/B/C/D bands, non-conformity discount and supplier blocking with a reason.', 'Pesos del ranking: pesos de los componentes de la nota (suma 100), franjas A/B/C/D, descuento de las no conformidades y el bloqueo de proveedores con motivo.'),
        'pode' => [
            ['texto' => $L('Recalcular agora', 'Recalculate now', 'Recalcular ahora'), 'alvo' => 'css:[data-rp-recalcular]'],
            ['texto' => $L('Bloquear ou desbloquear um fornecedor', 'Block or unblock a supplier', 'Bloquear o desbloquear un proveedor'), 'alvo' => 'css:[data-rp-bloq]'],
        ],
        'antes' => $L('Alterar exige a permissão Editar.', 'Changing requires the Edit permission.', 'Alterar exige el permiso Editar.'),
        'depois' => $L('Toda alteração e todo bloqueio ficam no histórico, com usuário, data e motivo.', 'Every change and block stays in the history, with user, date and reason.', 'Todo cambio y bloqueo queda en el historial, con usuario, fecha y motivo.'),
        'duvidas' => [],
    ],
    [
        'chaves' => $tPortF,
        'oque' => $L('Portaria › Documentação de fornecedor: consulta dos documentos exigidos de cada fornecedor, com situação (cor), validade e observações.', 'Gatehouse › Supplier documentation: lookup of the documents required from each supplier, with status (color), expiry and notes.', 'Portería › Documentación de proveedor: consulta de los documentos exigidos a cada proveedor, con situación (color), vigencia y observaciones.'),
        'pode' => [
            ['texto' => $L('Buscar o fornecedor', 'Find the supplier', 'Buscar el proveedor'), 'alvo' => 'busca'],
            ['texto' => $L('Filtrar por situação', 'Filter by status', 'Filtrar por situación'), 'alvo' => 'texto:Filtrar por situação'],
        ],
        'antes' => $L('Só consulta. Você vê as unidades a que tem acesso.', 'Read only. You see the units you have access to.', 'Solo consulta. Usted ve las unidades a las que tiene acceso.'),
        'depois' => $L('', '', ''),
        'duvidas' => [],
    ],
    [
        'chaves' => $tPortU,
        'oque' => $L('Portaria › Documentação de funcionário: os critérios exigidos de cada funcionário (ASO, treinamentos, documentos), se atende, a situação e a validade.', 'Gatehouse › Employee documentation: the criteria required from each employee (medical exam, training, documents), whether met, status and expiry.', 'Portería › Documentación de empleado: los criterios exigidos a cada empleado (ASO, capacitaciones, documentos), si cumple, la situación y la vigencia.'),
        'pode' => [
            ['texto' => $L('Buscar o funcionário', 'Find the employee', 'Buscar el empleado'), 'alvo' => 'busca'],
            ['texto' => $L('Filtrar por situação', 'Filter by status', 'Filtrar por situación'), 'alvo' => 'texto:Filtrar por situação'],
            ['texto' => $L('Ver os treinamentos do funcionário', 'See the employee training', 'Ver las capacitaciones del empleado'), 'alvo' => 'texto:Treinamentos do funcionário'],
        ],
        'antes' => $L('', '', ''),
        'depois' => $L('Com a permissão Editar, ao enviar um arquivo a IA analisa o documento e propõe a atualização do critério, que você revisa.', 'With the Edit permission, when a file is uploaded the AI analyzes it and proposes the criterion update, which you review.', 'Con el permiso Editar, al subir un archivo la IA lo analiza y propone la actualización del criterio, que usted revisa.'),
        'duvidas' => [],
    ],
    [
        'chaves' => $tCad,
        'oque' => $L('Cadastros da homologação: tipos de fornecedor e de funcionário, os critérios (documentos exigidos) de cada um e os tipos de treinamento. É daqui que saem os documentos exigidos.', 'Homologation registers: supplier and employee types, the criteria (required documents) of each and the training types. The required documents come from here.', 'Registros de la homologación: tipos de proveedor y de empleado, los criterios (documentos exigidos) de cada uno y los tipos de capacitación. De aquí salen los documentos exigidos.'),
        'pode' => [
            ['texto' => $L('Incluir um registro', 'Add a record', 'Incluir un registro'), 'alvo' => 'acao:new'],
            ['texto' => $L('Salvar as alterações', 'Save the changes', 'Guardar los cambios'), 'alvo' => 'acao:save'],
        ],
        'antes' => $L('', '', ''),
        'depois' => $L('Registro em uso não pode ser excluído. Nos critérios, o botão Modelo do documento define o que a IA confere em cada arquivo.', 'A record in use cannot be deleted. In the criteria, the Document template button defines what the AI checks in each file.', 'Un registro en uso no se puede eliminar. En los criterios, el botón Modelo del documento define lo que la IA revisa en cada archivo.'),
        'duvidas' => [],
    ],
];

return ['fornecedores' => ['guias' => $guias, 'telas' => $telas]];
