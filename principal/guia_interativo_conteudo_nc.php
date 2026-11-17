<?php
declare(strict_types=1);

/*
 * Ajuda inteligente (botão ? / F1) do módulo Não Conformidades — carga inicial do assunto "nc" do Guia do usuário
 * (fluxo, onde encontrar, termos, guias passo a passo com "Mostre-me" e "Sobre esta tela"). A migração 356 inclui o
 * assunto no Guia do usuário publicado (Administração › Guia do usuário), onde ele passa a ser mantido.
 *
 * Chaves de tela: código do menu, transação da tela (data-help-transaction) ou arquivo:nome.php. A tela da ocorrência
 * publica a chave da aba aberta (nc_ocorrencia_ocorrencia / _gestor / _analise / _eficacia / _historico), por isso o
 * "Sobre esta tela" muda junto com a aba. As telas das abas ficam primeiro na lista: o painel usa a primeira que bate.
 * Regras de escrita (docs/help.txt): uma ação por passo, nome do botão/campo em **negrito**, "Você deve ver", PT/EN/ES.
 */

if (!defined('GREEN_GUIA_USUARIO')) { http_response_code(404); exit; }

$L = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];
$P = static fn(array $texto, array $ver = [], string $alvo = '', string $tela = '', string $quem = '', bool $auto = true): array
    => ['texto' => $texto, 'ver' => $ver ?: $L('', '', ''), 'alvo' => $alvo, 'tela' => $tela, 'quem' => $quem, 'auto' => $auto];

$novas = ['NAO_CONFORMIDADES_NOVA_OCORRENCIA_EMPRESA', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_FORNECEDOR', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_CLIENTE',
    'NAO_CONFORMIDADES_NOVA_OCORRENCIA_PLANEJAMENTO', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_ASSISTENCIA', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_ACIDENTE'];
$listas = ['NAO_CONFORMIDADES_MINHAS_OCORRENCIAS', 'NAO_CONFORMIDADES_LISTA_OCORRENCIAS', 'naoconformidades_minhasocorrencias', 'naoconformidades_listageralocorrencias'];
$abas = ['nc_ocorrencia_ocorrencia', 'nc_ocorrencia_gestor', 'nc_ocorrencia_analise', 'nc_ocorrencia_eficacia', 'nc_ocorrencia_historico', 'arquivo:naoconformidades_ocorrencia.php'];
$telasOcorrencia = array_merge($abas, $listas, $novas);
$cadastros = [
    'NAO_CONFORMIDADES_CAD_CLASSIFICACAO' => 'naoconformidades_cadclassificacao', 'NAO_CONFORMIDADES_CAD_CLASSIFICACAO_FORNECEDOR' => 'naoconformidades_cadclassificacaofornecedor',
    'NAO_CONFORMIDADES_CAD_TIPOS_OCORRENCIA' => 'naoconformidades_cadtiposdeocorrencia', 'NAO_CONFORMIDADES_CAD_ORIGENS' => 'naoconformidades_cadorigens',
    'NAO_CONFORMIDADES_CAD_SISTEMAS_GESTAO' => 'naoconformidades_cadsistemasdegestao', 'NAO_CONFORMIDADES_CAD_SETORES' => 'naoconformidades_cadsetores',
    'NAO_CONFORMIDADES_CAD_TIPOS_CAUSA' => 'naoconformidades_cadtiposdecausa', 'NAO_CONFORMIDADES_CAD_CLIENTES' => 'naoconformidades_cadclientes',
    'NAO_CONFORMIDADES_CAD_UNIDADES_CLIENTE' => 'naoconformidades_cadunidadesdocliente', 'NAO_CONFORMIDADES_CAD_PRODUTOS' => 'naoconformidades_cadprodutos',
    'NAO_CONFORMIDADES_CAD_TIPOS_DOCUMENTO' => 'naoconformidades_cadtiposdedocumento', 'NAO_CONFORMIDADES_CAD_TIPOS_RECLAMACAO' => 'naoconformidades_cadtiposdereclamacao',
    'NAO_CONFORMIDADES_CAD_TIPOS_TAMPA' => 'naoconformidades_cadtiposdetampa', 'NAO_CONFORMIDADES_CAD_TAMANHOS_LATA' => 'naoconformidades_cadtamanhosdalata',
];
$telasCadastros = array_keys($cadastros); // a tela do cadastro abre pelo menu: o código do menu basta (limite de 20 chaves)

// ---------------------------------------------------------------- passos reutilizados
$abrirMinhas = $P(
    $L('No menu à esquerda, abra **Não Conformidades** e clique em **Minhas ocorrências**.', 'In the left menu, open **Non-conformities** and click **My occurrences**.', 'En el menú de la izquierda, abra **No Conformidades** y haga clic en **Mis ocurrencias**.'),
    $L('A lista mostra as NCs em que você é emitente, gestor, responsável ou responsável de uma ação (também as finalizadas).', 'The list shows the NCs where you are issuer, manager, responsible or owner of an action (finalized ones too).', 'La lista muestra las NC en las que usted es emisor, gestor, responsable o responsable de una acción (también las finalizadas).'),
    'menu:NAO_CONFORMIDADES_MINHAS_OCORRENCIAS', 'NAO_CONFORMIDADES_MINHAS_OCORRENCIAS');
$abrirNc = $P(
    $L('Dê **duplo clique** na linha da NC (use a busca ou o filtro da coluna se a lista for grande).', '**Double-click** the NC row (use the search or the column filter if the list is long).', 'Haga **doble clic** en la fila de la NC (use la búsqueda o el filtro de la columna si la lista es grande).'),
    $L('A NC abre numa janela com as abas Ocorrência, Avaliação do gestor, Análise da causa e plano de ação, Verificação da eficácia e Histórico. O lápis verde marca a aba em que você age agora.', 'The NC opens in a window with the tabs Occurrence, Manager assessment, Cause analysis and action plan, Effectiveness check and History. The green pencil marks the tab where you act now.', 'La NC se abre en una ventana con las pestañas Ocurrencia, Evaluación del gestor, Análisis de la causa y plan de acción, Verificación de la eficacia e Historial. El lápiz verde marca la pestaña en la que usted actúa ahora.'),
    'linha', '', '', false);
$abaOcorrencia = static fn(string $aba, array $nome) => $P(
    $L('Clique na aba **' . $nome['pt'] . '**.', 'Click the **' . $nome['en'] . '** tab.', 'Haga clic en la pestaña **' . $nome['es'] . '**.'),
    [], 'css:[data-bs-target="#aba-' . $aba . '"]');

$nomeGestor = $L('Avaliação do gestor', 'Manager assessment', 'Evaluación del gestor');
$nomeAnalise = $L('Análise da causa e plano de ação', 'Cause analysis and action plan', 'Análisis de la causa y plan de acción');
$nomeEficacia = $L('Verificação da eficácia', 'Effectiveness check', 'Verificación de la eficacia');

return [
    'perfis' => [
        'emitente' => ['icone' => 'bi-pencil-square', 'nome' => $L('Emitente', 'Issuer', 'Emisor')],
    ],
    'modulo' => [
        'id' => 'nc',
        'icone' => 'bi-exclamation-diamond',
        'ativo' => true,
        'nome' => $L('Não Conformidades', 'Non-conformities', 'No Conformidades'),
        'resumo' => $L(
            'Registre o problema, o gestor avalia, o responsável encontra a causa e monta o plano de ação, e o gestor confirma se a solução funcionou.',
            'Record the problem, the manager assesses it, the responsible person finds the cause and builds the action plan, and the manager confirms whether the solution worked.',
            'Registre el problema, el gestor lo evalúa, el responsable encuentra la causa y arma el plan de acción, y el gestor confirma si la solución funcionó.'),
        'perfis' => ['emitente', 'gestor', 'resp', 'adm'],
        'perfis_nota' => $L(
            'Emitente = quem abriu a NC. Gestor e Responsável são escolhidos na própria NC (qualquer usuário ativo). Administrador = ADMINISTRADOR e ADM_DA_EMPRESA, que agem em qualquer etapa.',
            'Issuer = who opened the NC. Manager and Responsible are chosen in the NC itself (any active user). Administrator = ADMINISTRADOR and ADM_DA_EMPRESA, who can act at any stage.',
            'Emisor = quien abrió la NC. Gestor y Responsable se eligen en la propia NC (cualquier usuario activo). Administrador = ADMINISTRADOR y ADM_DA_EMPRESA, que actúan en cualquier etapa.'),
        'fases' => [
            ['nome' => $L('Registrar', 'Record', 'Registrar'), 'passos' => [
                ['tipo' => 'M', 'perfil' => 'emitente', 'transacao' => 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_EMPRESA',
                    'titulo' => $L('Abrir a ocorrência', 'Open the occurrence', 'Abrir la ocurrencia'),
                    'texto' => $L('Escolha o tipo (Empresa, Cliente, Fornecedor, Planejamento, Assistência Técnica ou Acidente), informe unidade, gestor, lista de distribuição, sumário e descrição, anexe as fotos e documentos e envie ao gestor.', 'Choose the type (Company, Customer, Supplier, Planning, Technical Assistance or Accident), enter unit, manager, distribution list, summary and description, attach photos and documents and send it to the manager.', 'Elija el tipo (Empresa, Cliente, Proveedor, Planificación, Asistencia Técnica o Accidente), indique unidad, gestor, lista de distribución, resumen y descripción, adjunte fotos y documentos y envíela al gestor.'),
                    'onde' => $L('Não Conformidades › Nova ocorrência', 'Non-conformities › New occurrence', 'No Conformidades › Nueva ocurrencia')],
                ['tipo' => 'A', 'perfil' => 'sistema',
                    'titulo' => $L('Avisar o gestor e a lista', 'Notify the manager and the list', 'Avisar al gestor y a la lista'),
                    'texto' => $L('O gestor e as pessoas da lista de distribuição recebem o aviso em Home › Alertas; participantes externos recebem por e-mail.', 'The manager and the people on the distribution list get the notice in Home › Alerts; external participants get it by e-mail.', 'El gestor y las personas de la lista de distribución reciben el aviso en Inicio › Alertas; los participantes externos lo reciben por correo.')],
            ]],
            ['nome' => $L('Avaliar', 'Assess', 'Evaluar'), 'passos' => [
                ['tipo' => 'M', 'perfil' => 'gestor', 'transacao' => 'NAO_CONFORMIDADES_MINHAS_OCORRENCIAS',
                    'titulo' => $L('Avaliar e escolher o responsável', 'Assess and choose the responsible', 'Evaluar y elegir el responsable'),
                    'texto' => $L('Na aba Avaliação do gestor, registre a ação imediata e a análise da abrangência, escolha o responsável pela análise da causa e envie. Se faltar informação, recuse com motivo; se não for uma NC, finalize como improcedente.', 'In the Manager assessment tab, record the immediate action and the scope analysis, choose who analyzes the cause and send it. If information is missing, refuse with a reason; if it is not an NC, close it as unfounded.', 'En la pestaña Evaluación del gestor, registre la acción inmediata y el análisis de alcance, elija el responsable del análisis de la causa y envíe. Si falta información, rechace con motivo; si no es una NC, finalícela como improcedente.'),
                    'onde' => $L('Minhas ocorrências › NC › Avaliação do gestor', 'My occurrences › NC › Manager assessment', 'Mis ocurrencias › NC › Evaluación del gestor')],
            ]],
            ['nome' => $L('Analisar e planejar', 'Analyze and plan', 'Analizar y planificar'), 'passos' => [
                ['tipo' => 'M', 'perfil' => 'resp', 'transacao' => 'NAO_CONFORMIDADES_MINHAS_OCORRENCIAS',
                    'titulo' => $L('Análise, causas e plano de ação', 'Analysis, causes and action plan', 'Análisis, causas y plan de acción'),
                    'texto' => $L('Escreva a análise da causa e a data prevista de conclusão, cadastre as causas e pelo menos 1 ação para cada causa, com responsável e prazo. A IA pode sugerir causas e ações.', 'Write the cause analysis and the expected completion date, register the causes and at least 1 action for each cause, with owner and deadline. The AI can suggest causes and actions.', 'Escriba el análisis de la causa y la fecha prevista de conclusión, registre las causas y al menos 1 acción por causa, con responsable y plazo. La IA puede sugerir causas y acciones.'),
                    'onde' => $L('Minhas ocorrências › NC › Análise da causa e plano de ação', 'My occurrences › NC › Cause analysis and action plan', 'Mis ocurrencias › NC › Análisis de la causa y plan de acción')],
                ['tipo' => 'M', 'perfil' => 'resp', 'transacao' => 'NAO_CONFORMIDADES_MINHAS_OCORRENCIAS',
                    'titulo' => $L('Executar as ações', 'Carry out the actions', 'Ejecutar las acciones'),
                    'texto' => $L('Cada responsável de ação atualiza o status (0, 25, 50, 75 ou 100%) e anexa as evidências da sua ação.', 'Each action owner updates the status (0, 25, 50, 75 or 100%) and attaches the evidence of their action.', 'Cada responsable de acción actualiza el estado (0, 25, 50, 75 o 100%) y adjunta las evidencias de su acción.'),
                    'onde' => $L('NC › Análise da causa e plano de ação › Plano de ação', 'NC › Cause analysis and action plan › Action plan', 'NC › Análisis de la causa y plan de acción › Plan de acción')],
                ['tipo' => 'M', 'perfil' => 'resp', 'transacao' => 'NAO_CONFORMIDADES_MINHAS_OCORRENCIAS',
                    'titulo' => $L('Enviar para a eficácia', 'Send to the effectiveness check', 'Enviar a la eficacia'),
                    'texto' => $L('Com o plano realizado, o responsável envia a NC para o gestor verificar a eficácia.', 'When the plan is done, the responsible person sends the NC for the manager to check effectiveness.', 'Con el plan realizado, el responsable envía la NC para que el gestor verifique la eficacia.')],
            ]],
            ['nome' => $L('Verificar a eficácia', 'Check effectiveness', 'Verificar la eficacia'), 'passos' => [
                ['tipo' => 'M', 'perfil' => 'gestor', 'transacao' => 'NAO_CONFORMIDADES_MINHAS_OCORRENCIAS',
                    'titulo' => $L('Aplicar o resultado', 'Apply the result', 'Aplicar el resultado'),
                    'texto' => $L('O gestor descreve a verificação e escolhe o resultado: Eficaz (finaliza), Encerrada sem a verificação (finaliza) ou Não eficaz (volta ao responsável). A IA pode dar um parecer com base nas evidências.', 'The manager describes the check and picks the result: Effective (closes), Closed without check (closes) or Not effective (goes back to the responsible). The AI can give an opinion based on the evidence.', 'El gestor describe la verificación y elige el resultado: Eficaz (finaliza), Cerrada sin verificación (finaliza) o No eficaz (vuelve al responsable). La IA puede dar un parecer con base en las evidencias.'),
                    'onde' => $L('NC › Verificação da eficácia', 'NC › Effectiveness check', 'NC › Verificación de la eficacia')],
                ['tipo' => 'A', 'perfil' => 'sistema',
                    'titulo' => $L('Finalizar e registrar', 'Close and record', 'Finalizar y registrar'),
                    'texto' => $L('A NC fica Finalizada, os envolvidos são avisados e os anexos passam a ser só para consulta. Tudo fica no Histórico da NC.', 'The NC is Finalized, everyone involved is notified and the attachments become read-only. Everything stays in the NC History.', 'La NC queda Finalizada, los involucrados reciben el aviso y los adjuntos pasan a ser solo de consulta. Todo queda en el Historial de la NC.')],
            ]],
            ['nome' => $L('Administrar', 'Administer', 'Administrar'), 'passos' => [
                ['tipo' => 'M', 'perfil' => 'adm', 'transacao' => 'NAO_CONFORMIDADES_SANEAMENTO',
                    'titulo' => $L('Manter o módulo em ordem', 'Keep the module in order', 'Mantener el módulo en orden'),
                    'texto' => $L('Corrigir NCs, transferir responsabilidades, sanear NCs paradas, manter listas, participantes, cadastros e a configuração (campos obrigatórios, parâmetros e lembretes).', 'Correct NCs, transfer responsibilities, clean up stalled NCs, maintain lists, participants, registers and the configuration (required fields, parameters and reminders).', 'Corregir NC, transferir responsabilidades, sanear NC paradas, mantener listas, participantes, registros y la configuración (campos obligatorios, parámetros y recordatorios).'),
                    'onde' => $L('Não Conformidades › Administração', 'Non-conformities › Administration', 'No Conformidades › Administración')],
            ]],
        ],
        'tarefas' => [],
        'menu' => [
            ['caminho' => $L('Não Conformidades › Minhas ocorrências', 'Non-conformities › My occurrences', 'No Conformidades › Mis ocurrencias'), 'texto' => $L('As NCs em que você participa, inclusive as finalizadas.', 'The NCs you take part in, finalized ones included.', 'Las NC en las que usted participa, incluidas las finalizadas.'), 'transacao' => 'NAO_CONFORMIDADES_MINHAS_OCORRENCIAS'],
            ['caminho' => $L('Não Conformidades › Lista geral de ocorrências', 'Non-conformities › General occurrence list', 'No Conformidades › Lista general de ocurrencias'), 'texto' => $L('Todas as NCs que você pode ver; abre sem as finalizadas.', 'All NCs you can see; opens without the finalized ones.', 'Todas las NC que usted puede ver; abre sin las finalizadas.'), 'transacao' => 'NAO_CONFORMIDADES_LISTA_OCORRENCIAS'],
            ['caminho' => $L('Não Conformidades › Nova ocorrência › Empresa', 'Non-conformities › New occurrence › Company', 'No Conformidades › Nueva ocurrencia › Empresa'), 'texto' => $L('Problema interno da empresa.', 'Internal company problem.', 'Problema interno de la empresa.'), 'transacao' => 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_EMPRESA'],
            ['caminho' => $L('Não Conformidades › Nova ocorrência › Fornecedor', 'Non-conformities › New occurrence › Supplier', 'No Conformidades › Nueva ocurrencia › Proveedor'), 'texto' => $L('Problema causado por um fornecedor (fornecedor obrigatório).', 'Problem caused by a supplier (supplier required).', 'Problema causado por un proveedor (proveedor obligatorio).'), 'transacao' => 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_FORNECEDOR'],
            ['caminho' => $L('Não Conformidades › Nova ocorrência › Cliente', 'Non-conformities › New occurrence › Customer', 'No Conformidades › Nueva ocurrencia › Cliente'), 'texto' => $L('Reclamação de cliente (cliente, produto, documento e reclamação obrigatórios).', 'Customer complaint (customer, product, document and complaint required).', 'Reclamo de cliente (cliente, producto, documento y reclamo obligatorios).'), 'transacao' => 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_CLIENTE'],
            ['caminho' => $L('Não Conformidades › Nova ocorrência › Planejamento', 'Non-conformities › New occurrence › Planning', 'No Conformidades › Nueva ocurrencia › Planificación'), 'texto' => $L('Vai direto ao plano de ação, montado pelo gestor.', 'Goes straight to the action plan, built by the manager.', 'Va directo al plan de acción, armado por el gestor.'), 'transacao' => 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_PLANEJAMENTO'],
            ['caminho' => $L('Não Conformidades › Nova ocorrência › Assistência Técnica', 'Non-conformities › New occurrence › Technical Assistance', 'No Conformidades › Nueva ocurrencia › Asistencia Técnica'), 'texto' => $L('O emitente monta as ações antes de enviar o plano.', 'The issuer builds the actions before sending the plan.', 'El emisor arma las acciones antes de enviar el plan.'), 'transacao' => 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_ASSISTENCIA'],
            ['caminho' => $L('Não Conformidades › Nova ocorrência › Acidente/Incidente', 'Non-conformities › New occurrence › Accident/Incident', 'No Conformidades › Nueva ocurrencia › Accidente/Incidente'), 'texto' => $L('Mesmo fluxo da Empresa.', 'Same flow as Company.', 'Mismo flujo que Empresa.'), 'transacao' => 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_ACIDENTE'],
            ['caminho' => $L('Não Conformidades › Administração › Correção de ocorrência', 'Non-conformities › Administration › Occurrence correction', 'No Conformidades › Administración › Corrección de ocurrencia'), 'texto' => $L('Corrigir dados e pessoas de uma NC, reabrir, devolver ou encerrar.', 'Correct data and people of an NC, reopen, return or close it.', 'Corregir datos y personas de una NC, reabrir, devolver o cerrar.'), 'transacao' => 'NAO_CONFORMIDADES_CORRECAO'],
            ['caminho' => $L('Não Conformidades › Administração › Transferência de responsabilidades', 'Non-conformities › Administration › Responsibility transfer', 'No Conformidades › Administración › Transferencia de responsabilidades'), 'texto' => $L('Passar tudo o que está com uma pessoa para outra.', 'Move everything a person holds to someone else.', 'Pasar todo lo que está con una persona a otra.'), 'transacao' => 'NAO_CONFORMIDADES_TRANSFERENCIA'],
            ['caminho' => $L('Não Conformidades › Administração › Painel de saneamento', 'Non-conformities › Administration › Clean-up panel', 'No Conformidades › Administración › Panel de saneamiento'), 'texto' => $L('NCs sem gestor, paradas, antigas e ações vencidas.', 'NCs without manager, stalled, old and overdue actions.', 'NC sin gestor, paradas, antiguas y acciones vencidas.'), 'transacao' => 'NAO_CONFORMIDADES_SANEAMENTO'],
            ['caminho' => $L('Não Conformidades › Administração › Listas de distribuição', 'Non-conformities › Administration › Distribution lists', 'No Conformidades › Administración › Listas de distribución'), 'texto' => $L('Quem recebe o aviso das NCs.', 'Who gets the NC notices.', 'Quién recibe el aviso de las NC.'), 'transacao' => 'NAO_CONFORMIDADES_LISTAS_DISTRIBUICAO'],
            ['caminho' => $L('Não Conformidades › Administração › Participantes externos', 'Non-conformities › Administration › External participants', 'No Conformidades › Administración › Participantes externos'), 'texto' => $L('Pessoas sem login que recebem os avisos por e-mail.', 'People without login who get the notices by e-mail.', 'Personas sin login que reciben los avisos por correo.'), 'transacao' => 'NAO_CONFORMIDADES_PARTICIPANTES'],
            ['caminho' => $L('Não Conformidades › Administração › Configuração do módulo', 'Non-conformities › Administration › Module configuration', 'No Conformidades › Administración › Configuración del módulo'), 'texto' => $L('Campos obrigatórios por tipo, parâmetros e lembretes (só ADMINISTRADOR).', 'Required fields by type, parameters and reminders (ADMINISTRADOR only).', 'Campos obligatorios por tipo, parámetros y recordatorios (solo ADMINISTRADOR).'), 'transacao' => 'NAO_CONFORMIDADES_CONFIGURACAO'],
            ['caminho' => $L('Não Conformidades › Administração › Cadastros', 'Non-conformities › Administration › Registers', 'No Conformidades › Administración › Registros'), 'texto' => $L('Classificação, tipos, origens, setores, tipos de causa e os cadastros do cliente.', 'Classification, types, origins, sectors, cause types and the customer registers.', 'Clasificación, tipos, orígenes, sectores, tipos de causa y los registros del cliente.'), 'transacao' => 'NAO_CONFORMIDADES_CAD_CLASSIFICACAO'],
        ],
        'termos' => [
            ['termo' => $L('Etapa', 'Stage', 'Etapa'), 'texto' => $L('Onde a NC está: Em criação → Com o Gestor → Análise/Plano de Ação → Avaliação da Eficácia → Finalizado. Recusas voltam para "Recusado pelo Gestor" ou "Recusado pela Avaliação da Eficácia".', 'Where the NC is: In creation → With the Manager → Analysis/Action Plan → Effectiveness Check → Finalized. Refusals go back to "Refused by the Manager" or "Refused by the Effectiveness Check".', 'Dónde está la NC: En creación → Con el Gestor → Análisis/Plan de Acción → Evaluación de la Eficacia → Finalizado. Los rechazos vuelven a "Rechazado por el Gestor" o "Rechazado por la Evaluación de la Eficacia".')],
            ['termo' => $L('Emitente', 'Issuer', 'Emisor'), 'texto' => $L('Quem abriu a NC. Só ele altera a aba Ocorrência, e só em "Em criação" ou "Recusado pelo Gestor".', 'Who opened the NC. Only they change the Occurrence tab, and only in "In creation" or "Refused by the Manager".', 'Quien abrió la NC. Solo él cambia la pestaña Ocurrencia, y solo en "En creación" o "Rechazado por el Gestor".')],
            ['termo' => $L('Gestor', 'Manager', 'Gestor'), 'texto' => $L('Avalia a ocorrência, escolhe o responsável e, no fim, verifica a eficácia. Qualquer usuário ativo pode ser gestor.', 'Assesses the occurrence, chooses the responsible and, at the end, checks effectiveness. Any active user can be a manager.', 'Evalúa la ocurrencia, elige el responsable y, al final, verifica la eficacia. Cualquier usuario activo puede ser gestor.')],
            ['termo' => $L('Responsável', 'Responsible', 'Responsable'), 'texto' => $L('Faz a análise da causa, cadastra as causas e monta o plano de ação. Cada ação tem o seu próprio responsável.', 'Does the cause analysis, registers the causes and builds the action plan. Each action has its own owner.', 'Hace el análisis de la causa, registra las causas y arma el plan de acción. Cada acción tiene su propio responsable.')],
            ['termo' => $L('Improcedente', 'Unfounded', 'Improcedente'), 'texto' => $L('O gestor conclui que não é uma não conformidade: a NC é finalizada na hora.', 'The manager concludes it is not a non-conformity: the NC is closed at once.', 'El gestor concluye que no es una no conformidad: la NC se finaliza en el momento.')],
            ['termo' => $L('Lista de distribuição', 'Distribution list', 'Lista de distribución'), 'texto' => $L('Grupo de pessoas que toma conhecimento da NC quando ela é enviada ao gestor.', 'Group of people who are informed of the NC when it is sent to the manager.', 'Grupo de personas que toma conocimiento de la NC cuando se envía al gestor.')],
            ['termo' => $L('Status da ação', 'Action status', 'Estado de la acción'), 'texto' => $L('Quanto da ação foi feito: 0, 25, 50, 75 ou 100%. No Planejamento e na Assistência Técnica, quando todas chegam a 100% a NC avança sozinha.', 'How much of the action is done: 0, 25, 50, 75 or 100%. In Planning and Technical Assistance, when all reach 100% the NC moves on by itself.', 'Cuánto de la acción se hizo: 0, 25, 50, 75 o 100%. En Planificación y Asistencia Técnica, cuando todas llegan a 100% la NC avanza sola.')],
            ['termo' => $L('Dica', 'Tip', 'Consejo'), 'dica' => true, 'texto' => $L('Na NC aberta, aperte F1: a ajuda mostra o que fazer na aba em que você está.', 'With the NC open, press F1: the help shows what to do in the tab you are on.', 'Con la NC abierta, presione F1: la ayuda muestra qué hacer en la pestaña en la que usted está.')],
        ],
        'guias' => require __DIR__ . '/guia_interativo_conteudo_nc_guias.php',
        'telas' => require __DIR__ . '/guia_interativo_conteudo_nc_telas.php',
    ],
];
