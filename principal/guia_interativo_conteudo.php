<?php
declare(strict_types=1);

/*
 * Ajuda interativa (botão ? / F1) — carga inicial dos guias passo a passo e dos textos "Sobre esta tela"
 * do Módulo Legal e da Manutenção Legal. A migração 309 junta este conteúdo ao Guia do usuário publicado
 * (Administração › Guia do usuário, aba do módulo "legal"), onde ele passa a ser mantido.
 *
 * Regras de escrita (docs/help.txt): uma ação por passo; nome exato do botão/campo em **negrito**;
 * "Você deve ver" sempre que a tela muda; quem faz (perfil) explícito; linguagem simples; PT/EN/ES.
 * alvo: onde o "Mostre-me" procura (menu:CODIGO, acao:new|save|delete|export|reload|columns, texto:Nome,
 * campo:COLUNA, coluna:COLUNA, filtro:COLUNA, aba:Nome, linha, busca, css:seletor). Sem alvo = o nome em negrito.
 * tela: menu aberto pelo "Ir para a tela" (só para quem tem permissão).
 */

if (!defined('GREEN_GUIA_USUARIO')) { http_response_code(404); exit; }

$L = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];
$P = static fn(array $texto, array $ver = [], string $alvo = '', string $tela = '', string $quem = '', bool $auto = true): array
    => ['texto' => $texto, 'ver' => $ver ?: $L('', '', ''), 'alvo' => $alvo, 'tela' => $tela, 'quem' => $quem, 'auto' => $auto];

$telasRequisitos = ['REQUISITOS_LEGAIS', 'modulolegal_requisitoslegais', 'arquivo:modulolegal_requisitoslegais.php'];
$telasLicencas = ['LICENCAS', 'arquivo:manutencaolegal_licencas.php'];
$telasCondicionantes = ['manutencaolegal_licencas_condicionantes', 'arquivo:manutencaolegal_licencas_condicionantes.php'];
$telasPlano = ['liPlanodeAcao', 'arquivo:manutencaolegal_licencas_condicionantesplanodeacao.php'];

$abrirRequisitos = $P(
    $L('No menu à esquerda, abra **Requisitos** e clique em **Requisitos legais**.', 'In the left menu, open **Requirements** and click **Legal requirements**.', 'En el menú de la izquierda, abra **Requisitos** y haga clic en **Requisitos legales**.'),
    $L('A tela Requisitos legais abre com as leis que valem para as suas unidades.', 'The Legal requirements screen opens with the laws that apply to your units.', 'La pantalla Requisitos legales se abre con las leyes que aplican a sus unidades.'),
    'menu:REQUISITOS_LEGAIS', 'REQUISITOS_LEGAIS');
$selecionarRequisito = $P(
    $L('Clique na linha do requisito para selecioná-la.', 'Click the requirement row to select it.', 'Haga clic en la fila del requisito para seleccionarla.'),
    $L('A linha fica destacada e os botões de **Associações** e **Avaliação** ficam liberados.', 'The row is highlighted and the **Associations** and **Assessment** buttons are enabled.', 'La fila queda resaltada y se habilitan los botones de **Asociaciones** y **Evaluación**.'),
    'linha');
$abrirLicencas = $P(
    $L('No menu à esquerda, abra **Licenças** e clique em **Licenças**.', 'In the left menu, open **Licenses** and click **Licenses**.', 'En el menú de la izquierda, abra **Licencias** y haga clic en **Licencias**.'),
    $L('A tela Licenças abre com as licenças das suas unidades e a situação de cada uma.', 'The Licenses screen opens with your units\' licenses and the status of each one.', 'La pantalla Licencias se abre con las licencias de sus unidades y la situación de cada una.'),
    'menu:LICENCAS', 'LICENCAS');
$selecionarLicenca = $P(
    $L('Clique na linha da licença para selecioná-la.', 'Click the license row to select it.', 'Haga clic en la fila de la licencia para seleccionarla.'),
    $L('A linha fica destacada e os botões da licença (Condicionantes, Arquivos...) ficam liberados.', 'The row is highlighted and the license buttons (Conditions, Files...) are enabled.', 'La fila queda resaltada y se habilitan los botones de la licencia (Condicionantes, Archivos...).'),
    'linha');

return [
    'legal' => [
        'guias' => [
            [
                'id' => 'ml-avaliar-requisito',
                'titulo' => $L('Avaliar se um requisito legal está sendo atendido', 'Assess whether a legal requirement is met', 'Evaluar si un requisito legal se está cumpliendo'),
                'resumo' => $L('Registrar a situação de uma lei na unidade: se atende, quem responde e quando reavaliar.', 'Record the status of a law at the unit: whether it is met, who is responsible and when to reassess.', 'Registrar la situación de una ley en la unidad: si se cumple, quién responde y cuándo reevaluar.'),
                'antes' => $L('A lei precisa estar ligada à unidade (Manutenção Legal › X Empresas).', 'The law must be linked to the unit (Legal Maintenance › X Companies).', 'La ley debe estar vinculada a la unidad (Mantenimiento Legal › X Empresas).'),
                'depois' => $L('A situação passa a contar no Painel e na Situação quanto ao cumprimento; o responsável recebe as pendências.', 'The status starts counting on the Dashboard and in Compliance status; the responsible person receives the pending items.', 'La situación pasa a contar en el Panel y en Situación de cumplimiento; el responsable recibe los pendientes.'),
                'perfis' => ['adm', 'gestor', 'resp'], 'telas' => $telasRequisitos,
                'passos' => [
                    $abrirRequisitos,
                    $P($L('Digite parte do número ou do assunto da lei na **busca** do grid e tecle Enter.', 'Type part of the law number or subject in the grid **search** and press Enter.', 'Escriba parte del número o del asunto de la ley en la **búsqueda** del grid y presione Enter.'),
                        $L('O grid mostra só as linhas que combinam com o texto.', 'The grid shows only the rows that match the text.', 'El grid muestra solo las filas que coinciden con el texto.'), 'busca', '', '', false),
                    $selecionarRequisito,
                    $P($L('No grupo **Avaliação**, clique em **Situação**.', 'In the **Assessment** group, click **Status**.', 'En el grupo **Evaluación**, haga clic en **Situación**.'),
                        $L('Abre a janela da situação do requisito nesta unidade.', 'The requirement status window opens for this unit.', 'Se abre la ventana de la situación del requisito en esta unidad.'), 'texto:Situação'),
                    $P($L('Em **Atendido?**, escolha se a empresa atende ao requisito.', 'In **Met?**, choose whether the company meets the requirement.', 'En **¿Cumplido?**, elija si la empresa cumple el requisito.'),
                        [], 'texto:Atendido', '', '', false),
                    $P($L('Em **Responsável**, escolha quem responde pelo atendimento.', 'In **Responsible**, choose who answers for compliance.', 'En **Responsable**, elija quién responde por el cumplimiento.'),
                        [], 'texto:Responsável', '', '', false),
                    $P($L('Informe a **Data de Avaliação** e a **Data de Vencimento** (quando a lei deve ser reavaliada).', 'Enter the **Assessment Date** and the **Due Date** (when the law must be reassessed).', 'Informe la **Fecha de Evaluación** y la **Fecha de Vencimiento** (cuándo debe reevaluarse la ley).'),
                        [], 'texto:Data de Avaliação', '', '', false),
                    $P($L('Clique em **Salvar**.', 'Click **Save**.', 'Haga clic en **Guardar**.'),
                        $L('Aparece a mensagem de sucesso e o grid passa a mostrar a nova situação.', 'A success message appears and the grid shows the new status.', 'Aparece el mensaje de éxito y el grid pasa a mostrar la nueva situación.'), 'texto:Salvar'),
                ],
            ],
            [
                'id' => 'ml-ia-requisito',
                'titulo' => $L('Pedir sugestões à IA e aprová-las', 'Ask the AI for suggestions and approve them', 'Pedir sugerencias a la IA y aprobarlas'),
                'resumo' => $L('A IA lê a lei e sugere licenças, medições, treinamentos, infraestrutura e documentos ligados a ela.', 'The AI reads the law and suggests licenses, measurements, trainings, infrastructure and documents related to it.', 'La IA lee la ley y sugiere licencias, mediciones, capacitaciones, infraestructura y documentos relacionados.'),
                'antes' => $L('A lei precisa ter o texto cadastrado (PDF ou ementa).', 'The law must have its text registered (PDF or summary).', 'La ley debe tener su texto registrado (PDF o resumen).'),
                'depois' => $L('Só o que você aprovar vira ligação do requisito; as rejeitadas ficam registradas.', 'Only what you approve becomes a requirement link; rejected ones stay recorded.', 'Solo lo que usted apruebe se vuelve vínculo del requisito; las rechazadas quedan registradas.'),
                'perfis' => ['adm', 'gestor'], 'telas' => $telasRequisitos,
                'passos' => [
                    $abrirRequisitos,
                    $selecionarRequisito,
                    $P($L('No grupo **Avaliação**, clique em **Solicitar análise de IA**.', 'In the **Assessment** group, click **Request AI analysis**.', 'En el grupo **Evaluación**, haga clic en **Solicitar análisis de IA**.'),
                        $L('A análise entra na fila. Se já existir uma análise desta lei, ela abre direto.', 'The analysis is queued. If an analysis of this law already exists, it opens directly.', 'El análisis entra en la cola. Si ya existe un análisis de esta ley, se abre directamente.'), 'texto:Solicitar análise de IA'),
                    $P($L('Aguarde o aviso no sino de **Alertas**, no alto da tela, e clique nele.', 'Wait for the notice on the **Alerts** bell at the top of the screen and click it.', 'Espere el aviso en la campana de **Alertas**, arriba en la pantalla, y haga clic en ella.'),
                        $L('O alerta "Análise de IA concluída" abre o resultado.', 'The "AI analysis completed" alert opens the result.', 'La alerta "Análisis de IA concluido" abre el resultado.'), 'texto:Alertas'),
                    $P($L('Em cada aba (Licenças, Medição, Treinamentos, Infraestrutura, Documentos), selecione a sugestão.', 'In each tab (Licenses, Measurement, Trainings, Infrastructure, Documents), select the suggestion.', 'En cada pestaña (Licencias, Medición, Capacitaciones, Infraestructura, Documentos), seleccione la sugerencia.'),
                        [], 'linha', '', '', false),
                    $P($L('Clique em **Aprovar** (ou em **Rejeitar**, se a sugestão não servir).', 'Click **Approve** (or **Reject** if the suggestion does not fit).', 'Haga clic en **Aprobar** (o en **Rechazar** si la sugerencia no sirve).'),
                        $L('A sugestão aprovada passa a aparecer nas associações do requisito.', 'The approved suggestion appears in the requirement associations.', 'La sugerencia aprobada aparece en las asociaciones del requisito.'), 'texto:Aprovar'),
                ],
            ],
            [
                'id' => 'ml-requisito-licenca',
                'titulo' => $L('Ligar uma licença a um requisito legal', 'Link a license to a legal requirement', 'Vincular una licencia a un requisito legal'),
                'resumo' => $L('Mostrar que a licença é a evidência de atendimento da lei.', 'Show that the license is the evidence of compliance with the law.', 'Mostrar que la licencia es la evidencia de cumplimiento de la ley.'),
                'antes' => $L('A licença precisa estar cadastrada em Licenças › Licenças.', 'The license must be registered in Licenses › Licenses.', 'La licencia debe estar registrada en Licencias › Licencias.'),
                'perfis' => ['adm', 'gestor', 'resp'], 'telas' => $telasRequisitos,
                'passos' => [
                    $abrirRequisitos,
                    $selecionarRequisito,
                    $P($L('No grupo **Associações**, clique em **Licenças**.', 'In the **Associations** group, click **Licenses**.', 'En el grupo **Asociaciones**, haga clic en **Licencias**.'),
                        $L('Abre a janela com as licenças já ligadas a este requisito.', 'The window with the licenses already linked to this requirement opens.', 'Se abre la ventana con las licencias ya vinculadas a este requisito.'), 'texto:Licenças'),
                    $P($L('Na janela, clique em **Novo** e escolha a licença na lista.', 'In the window, click **New** and choose the license from the list.', 'En la ventana, haga clic en **Nuevo** y elija la licencia en la lista.'),
                        $L('Aparece uma linha nova para a licença escolhida.', 'A new row appears for the chosen license.', 'Aparece una fila nueva para la licencia elegida.'), 'acao:new'),
                    $P($L('Clique em **Salvar**.', 'Click **Save**.', 'Haga clic en **Guardar**.'),
                        $L('A licença fica ligada ao requisito e aparece em Requisitos e documentos da licença.', 'The license is linked to the requirement and appears in the license Requirements and documents.', 'La licencia queda vinculada al requisito y aparece en Requisitos y documentos de la licencia.'), 'acao:save'),
                ],
            ],
            [
                'id' => 'ml-cadastrar-licenca',
                'titulo' => $L('Cadastrar uma licença', 'Register a license', 'Registrar una licencia'),
                'resumo' => $L('Incluir a licença com vencimento e responsável, para receber os avisos de prazo.', 'Add the license with due date and responsible person, to receive deadline notices.', 'Incluir la licencia con vencimiento y responsable, para recibir los avisos de plazo.'),
                'depois' => $L('O responsável recebe os avisos de reação e de vencimento da licença.', 'The responsible person receives the license reaction and expiration notices.', 'El responsable recibe los avisos de reacción y de vencimiento de la licencia.'),
                'perfis' => ['adm', 'gestor'], 'telas' => $telasLicencas,
                'passos' => [
                    $abrirLicencas,
                    $P($L('Clique em **Novo**.', 'Click **New**.', 'Haga clic en **Nuevo**.'), $L('Aparece uma linha em branco no alto do grid.', 'A blank row appears at the top of the grid.', 'Aparece una fila en blanco arriba del grid.'), 'acao:new'),
                    $P($L('Escolha a **Unidade** e escreva a **Descrição** da licença.', 'Choose the **Unit** and write the license **Description**.', 'Elija la **Unidad** y escriba la **Descripción** de la licencia.'), [], 'campo:CODUNIDADE', '', '', false),
                    $P($L('Informe a **Data de Vencimento** e o **Número da licença**.', 'Enter the **Due Date** and the **License number**.', 'Informe la **Fecha de Vencimiento** y el **Número de la licencia**.'), [], 'campo:DATA_VALIDADE', '', '', false),
                    $P($L('Em **Responsável**, escolha quem cuida da licença.', 'In **Responsible**, choose who takes care of the license.', 'En **Responsable**, elija quién cuida la licencia.'), [], 'campo:CODUSUARIO', '', '', false),
                    $P($L('Clique em **Salvar**.', 'Click **Save**.', 'Haga clic en **Guardar**.'), $L('Aparece "Licença salva com sucesso." e a coluna de situação mostra Em dia, Em atenção ou Vencida.', '"License saved successfully." appears and the status column shows Up to date, Attention or Expired.', 'Aparece "Licencia guardada con éxito." y la columna de situación muestra Al día, En atención o Vencida.'), 'acao:save'),
                ],
            ],
            [
                'id' => 'ml-licenca-pdf-ia',
                'titulo' => $L('Preencher uma licença lendo o PDF com IA', 'Fill in a license by reading the PDF with AI', 'Completar una licencia leyendo el PDF con IA'),
                'resumo' => $L('Enviar o arquivo da licença e deixar a IA sugerir número, órgão, atividade e vencimento.', 'Upload the license file and let the AI suggest number, agency, activity and due date.', 'Enviar el archivo de la licencia y dejar que la IA sugiera número, órgano, actividad y vencimiento.'),
                'perfis' => ['adm', 'gestor', 'resp'], 'telas' => array_merge($telasLicencas, ['arquivo:gestao_arquivos.php']),
                'passos' => [
                    $abrirLicencas,
                    $selecionarLicenca,
                    $P($L('Clique em **Arquivos**.', 'Click **Files**.', 'Haga clic en **Archivos**.'), $L('Abre a janela de arquivos da licença.', 'The license files window opens.', 'Se abre la ventana de archivos de la licencia.'), 'texto:Arquivos'),
                    $P($L('Clique em **Enviar** e escolha o PDF da licença.', 'Click **Upload** and choose the license PDF.', 'Haga clic en **Enviar** y elija el PDF de la licencia.'), $L('O arquivo aparece na lista e uma nova versão da licença é criada.', 'The file appears in the list and a new license version is created.', 'El archivo aparece en la lista y se crea una nueva versión de la licencia.'), 'css:#gedUpload'),
                    $P($L('Com o arquivo selecionado, clique em **Analisar com IA**.', 'With the file selected, click **Analyze with AI**.', 'Con el archivo seleccionado, haga clic en **Analizar con IA**.'), $L('A aba **Revisão dos dados** abre com os dados lidos.', 'The **Data review** tab opens with the data read.', 'La pestaña **Revisión de los datos** se abre con los datos leídos.'), 'css:#gedAnalyze'),
                    $P($L('Confira os campos e clique em **Confirmar e gravar**.', 'Check the fields and click **Confirm and save**.', 'Revise los campos y haga clic en **Confirmar y guardar**.'), $L('A licença é atualizada com os dados confirmados.', 'The license is updated with the confirmed data.', 'La licencia se actualiza con los datos confirmados.'), 'texto:Confirmar e gravar'),
                ],
            ],
            [
                'id' => 'ml-condicionantes',
                'titulo' => $L('Registrar as condicionantes de uma licença', 'Record the conditions of a license', 'Registrar las condicionantes de una licencia'),
                'resumo' => $L('Cadastrar as exigências da licença, com frequência, responsável e se estão sendo atendidas.', 'Register the license requirements, with frequency, responsible person and whether they are met.', 'Registrar las exigencias de la licencia, con frecuencia, responsable y si se están cumpliendo.'),
                'depois' => $L('Cada condicionante pode receber um plano de ação com evidências.', 'Each condition can receive an action plan with evidence.', 'Cada condicionante puede recibir un plan de acción con evidencias.'),
                'perfis' => ['adm', 'gestor', 'resp'], 'telas' => array_merge($telasLicencas, $telasCondicionantes),
                'passos' => [
                    $abrirLicencas,
                    $selecionarLicenca,
                    $P($L('Clique em **Condicionantes**.', 'Click **Conditions**.', 'Haga clic en **Condicionantes**.'), $L('Abre a janela com as condicionantes desta licença.', 'The window with this license\'s conditions opens.', 'Se abre la ventana con las condicionantes de esta licencia.'), 'texto:Condicionantes'),
                    $P($L('Na janela, clique em **Novo**.', 'In the window, click **New**.', 'En la ventana, haga clic en **Nuevo**.'), $L('Aparece uma linha em branco.', 'A blank row appears.', 'Aparece una fila en blanco.'), 'acao:new'),
                    $P($L('Escreva a **Descrição** da condicionante e escolha a **Frequência de medição**.', 'Write the condition **Description** and choose the **Measurement frequency**.', 'Escriba la **Descripción** de la condicionante y elija la **Frecuencia de medición**.'), [], 'campo:DSCCONDICIONANTE', '', '', false),
                    $P($L('Escolha **Atende?** e o **Responsável**.', 'Choose **Attended?** and the **Responsible** person.', 'Elija **¿Atiende?** y el **Responsable**.'), [], 'campo:ATENDIDO', '', '', false),
                    $P($L('Clique em **Salvar**.', 'Click **Save**.', 'Haga clic en **Guardar**.'), $L('A condicionante fica gravada e o botão **Plano de Acao** da linha é liberado.', 'The condition is saved and the row **Action plan** button is enabled.', 'La condicionante queda guardada y se habilita el botón **Plan de acción** de la fila.'), 'acao:save'),
                ],
            ],
            [
                'id' => 'ml-plano-evidencia',
                'titulo' => $L('Plano de ação da condicionante com evidência analisada pela IA', 'Condition action plan with evidence analyzed by AI', 'Plan de acción de la condicionante con evidencia analizada por la IA'),
                'resumo' => $L('Registrar o que será feito para atender a condicionante e anexar a evidência; a IA sugere datas, recursos e status para você aprovar.', 'Record what will be done to meet the condition and attach the evidence; the AI suggests dates, resources and status for you to approve.', 'Registrar lo que se hará para cumplir la condicionante y adjuntar la evidencia; la IA sugiere fechas, recursos y estado para que usted apruebe.'),
                'antes' => $L('A condicionante precisa estar gravada (guia "Registrar as condicionantes de uma licença").', 'The condition must be saved (guide "Record the conditions of a license").', 'La condicionante debe estar guardada (guía "Registrar las condicionantes de una licencia").'),
                'depois' => $L('Os campos aprovados são gravados no plano e o histórico fica na aba Análises de IA.', 'The approved fields are saved to the plan and the history stays in the AI analyses tab.', 'Los campos aprobados se guardan en el plan y el historial queda en la pestaña Análisis de IA.'),
                'perfis' => ['adm', 'gestor', 'resp'], 'telas' => array_merge($telasCondicionantes, $telasPlano, ['arquivo:gestao_arquivos.php']),
                'passos' => [
                    $P($L('Na janela **Condicionantes da licença**, clique na linha da condicionante.', 'In the **License conditions** window, click the condition row.', 'En la ventana **Condicionantes de la licencia**, haga clic en la fila de la condicionante.'), $L('A linha fica destacada.', 'The row is highlighted.', 'La fila queda resaltada.'), 'linha'),
                    $P($L('No fim da linha, clique em **Plano de Acao**.', 'At the end of the row, click **Action plan**.', 'Al final de la fila, haga clic en **Plan de acción**.'), $L('Abre a janela Plano de Ação da condicionante.', 'The condition Action plan window opens.', 'Se abre la ventana Plan de acción de la condicionante.'), 'texto:Plano de Acao'),
                    $P($L('Clique em **Novo** e escreva a **Ação**.', 'Click **New** and write the **Action**.', 'Haga clic en **Nuevo** y escriba la **Acción**.'), $L('Aparece uma linha nova do plano.', 'A new plan row appears.', 'Aparece una fila nueva del plan.'), 'acao:new'),
                    $P($L('Escolha o **Responsável** e informe a **Data inicial** e a **Data final**.', 'Choose the **Responsible** person and enter the **Start date** and the **End date**.', 'Elija el **Responsable** e informe la **Fecha inicial** y la **Fecha final**.'), [], 'campo:CODUSUARIO', '', '', false),
                    $P($L('Clique em **Salvar**.', 'Click **Save**.', 'Haga clic en **Guardar**.'), $L('A ação fica gravada e o botão da coluna **Evidências** aparece na linha.', 'The action is saved and the **Evidence** column button appears in the row.', 'La acción queda guardada y aparece el botón de la columna **Evidencias** en la fila.'), 'acao:save'),
                    $P($L('Na coluna **Evidências**, clique no botão da linha.', 'In the **Evidence** column, click the row button.', 'En la columna **Evidencias**, haga clic en el botón de la fila.'), $L('Abre a janela Evidências do plano de ação.', 'The Action plan evidence window opens.', 'Se abre la ventana Evidencias del plan de acción.'), 'css:[data-ml-file-button]'),
                    $P($L('Clique em **Enviar** e escolha o arquivo da evidência (PDF, imagem ou Word).', 'Click **Upload** and choose the evidence file (PDF, image or Word).', 'Haga clic en **Enviar** y elija el archivo de la evidencia (PDF, imagen o Word).'), $L('A IA começa a ler o arquivo sozinha: "Lendo a evidência (OCR)..." e depois "A IA está analisando...".', 'The AI starts reading the file by itself: "Reading the evidence (OCR)..." and then "The AI is analyzing...".', 'La IA empieza a leer el archivo sola: "Leyendo la evidencia (OCR)..." y luego "La IA está analizando...".'), 'css:#gedUpload'),
                    $P($L('Na aba **Revisão dos dados**, confira o valor sugerido de cada campo e deixe marcado só o que deve ser gravado.', 'In the **Data review** tab, check the suggested value of each field and keep checked only what should be saved.', 'En la pestaña **Revisión de los datos**, revise el valor sugerido de cada campo y deje marcado solo lo que debe guardarse.'), [], 'aba:Revisão dos dados', '', '', false),
                    $P($L('Clique em **Confirmar e gravar**.', 'Click **Confirm and save**.', 'Haga clic en **Confirmar y guardar**.'), $L('"Dados aprovados e gravados no plano de ação." e a linha do plano é atualizada.', '"Data approved and saved to the action plan." and the plan row is updated.', '"Datos aprobados y guardados en el plan de acción." y la fila del plan se actualiza.'), 'texto:Confirmar e gravar'),
                ],
            ],
            [
                'id' => 'ml-minhas',
                'titulo' => $L('Ver tudo o que está comigo', 'See everything assigned to me', 'Ver todo lo que está conmigo'),
                'resumo' => $L('Consultar só as licenças, treinamentos, infraestruturas e controles sob a sua responsabilidade.', 'See only the licenses, trainings, infrastructures and controls under your responsibility.', 'Consultar solo las licencias, capacitaciones, infraestructuras y controles bajo su responsabilidad.'),
                'perfis' => ['resp', 'gestor', 'adm'], 'telas' => ['MINHAS_LICENCAS', 'REQUISITOS_LEGAIS', 'LICENCAS'],
                'passos' => [
                    $P($L('No menu à esquerda, clique em **Minhas Licenças** (use a busca do menu se não estiver vendo).', 'In the left menu, click **My Licenses** (use the menu search if you do not see it).', 'En el menú de la izquierda, haga clic en **Mis Licencias** (use la búsqueda del menú si no la ve).'), $L('Aparecem só as licenças em que você é o responsável.', 'Only the licenses where you are responsible appear.', 'Aparecen solo las licencias en las que usted es el responsable.'), 'menu:MINHAS_LICENCAS', 'MINHAS_LICENCAS'),
                    $P($L('Use a **busca** ou os filtros das colunas para achar um item.', 'Use the **search** or the column filters to find an item.', 'Use la **búsqueda** o los filtros de las columnas para encontrar un ítem.'), [], 'busca', '', '', false),
                    $P($L('Faça o mesmo em **Meus Treinamentos**, **Minhas Infraestruturas** e **Meus Controles**, no mesmo menu.', 'Do the same in **My Trainings**, **My Infrastructures** and **My Controls**, in the same menu.', 'Haga lo mismo en **Mis Capacitaciones**, **Mis Infraestructuras** y **Mis Controles**, en el mismo menú.'), [], '', '', '', false),
                ],
            ],
            [
                'id' => 'ml-exportar',
                'titulo' => $L('Exportar uma lista para Excel', 'Export a list to Excel', 'Exportar una lista a Excel'),
                'resumo' => $L('Gerar a planilha com as linhas que o grid está mostrando (com os filtros).', 'Generate the spreadsheet with the rows the grid is showing (with the filters).', 'Generar la planilla con las filas que el grid está mostrando (con los filtros).'),
                'perfis' => [], 'telas' => array_merge($telasRequisitos, $telasLicencas, $telasCondicionantes, $telasPlano, ['LEGISLACAO', 'LEGISLACAO_X_EMPRESAS', 'MINHAS_LICENCAS']),
                'passos' => [
                    $P($L('Use a **busca** ou os filtros das colunas para deixar no grid só o que você quer exportar.', 'Use the **search** or the column filters to keep in the grid only what you want to export.', 'Use la **búsqueda** o los filtros de las columnas para dejar en el grid solo lo que quiere exportar.'), [], 'busca', '', '', false),
                    $P($L('Clique em **Exportar Excel**.', 'Click **Export Excel**.', 'Haga clic en **Exportar Excel**.'), $L('A exportação entra na fila e um aviso aparece no sino de Alertas quando o arquivo fica pronto.', 'The export is queued and a notice appears on the Alerts bell when the file is ready.', 'La exportación entra en la cola y aparece un aviso en la campana de Alertas cuando el archivo está listo.'), 'acao:export'),
                    $P($L('Clique no aviso em **Alertas** para baixar a planilha.', 'Click the notice in **Alerts** to download the spreadsheet.', 'Haga clic en el aviso en **Alertas** para descargar la planilla.'), [], 'texto:Alertas', '', '', false),
                ],
            ],
            [
                'id' => 'ml-legislacao-empresa',
                'titulo' => $L('Dizer quais leis valem para uma unidade', 'Define which laws apply to a unit', 'Indicar qué leyes aplican a una unidad'),
                'resumo' => $L('Ligar a lei à empresa e à unidade; só assim ela aparece em Requisitos legais.', 'Link the law to the company and unit; only then it appears in Legal requirements.', 'Vincular la ley a la empresa y a la unidad; solo así aparece en Requisitos legales.'),
                'perfis' => ['adm', 'gestor'], 'telas' => ['LEGISLACAO_X_EMPRESAS', 'arquivo:manutencaolegal_legislacaoxempresa.php'],
                'passos' => [
                    $P($L('No menu à esquerda, abra **Manutenção Legal** e clique em **X Empresas**.', 'In the left menu, open **Legal Maintenance** and click **X Companies**.', 'En el menú de la izquierda, abra **Mantenimiento Legal** y haga clic en **X Empresas**.'), $L('Abre a lista das leis já ligadas às unidades.', 'The list of laws already linked to the units opens.', 'Se abre la lista de las leyes ya vinculadas a las unidades.'), 'menu:LEGISLACAO_X_EMPRESAS', 'LEGISLACAO_X_EMPRESAS'),
                    $P($L('Clique em **Novo**.', 'Click **New**.', 'Haga clic en **Nuevo**.'), $L('Aparece uma linha em branco.', 'A blank row appears.', 'Aparece una fila en blanco.'), 'acao:new'),
                    $P($L('Escolha a lei e a unidade na linha nova.', 'Choose the law and the unit in the new row.', 'Elija la ley y la unidad en la fila nueva.'), [], 'linha', '', '', false),
                    $P($L('Clique em **Salvar**.', 'Click **Save**.', 'Haga clic en **Guardar**.'), $L('A lei passa a aparecer em Requisitos › Requisitos legais para essa unidade.', 'The law starts to appear in Requirements › Legal requirements for that unit.', 'La ley pasa a aparecer en Requisitos › Requisitos legales para esa unidad.'), 'acao:save'),
                ],
            ],
        ],
        'telas' => [
            [
                'chaves' => $telasRequisitos,
                'oque' => $L('O centro do Módulo Legal: cada linha é uma lei que vale para uma unidade, com a situação de atendimento, o responsável e as ligações com licenças, medições, treinamentos, infraestrutura, controles e fornecedores.', 'The center of the Legal Module: each row is a law that applies to a unit, with the compliance status, the responsible person and the links with licenses, measurements, trainings, infrastructure, controls and suppliers.', 'El centro del Módulo Legal: cada fila es una ley que aplica a una unidad, con la situación de cumplimiento, el responsable y los vínculos con licencias, mediciones, capacitaciones, infraestructura, controles y proveedores.'),
                'pode' => [
                    ['texto' => $L('Avaliar a situação de um requisito', 'Assess a requirement status', 'Evaluar la situación de un requisito'), 'alvo' => 'texto:Situação'],
                    ['texto' => $L('Ligar licenças, medições, treinamentos e controles', 'Link licenses, measurements, trainings and controls', 'Vincular licencias, mediciones, capacitaciones y controles'), 'alvo' => 'texto:Licenças'],
                    ['texto' => $L('Responder o questionário legal', 'Answer the legal questionnaire', 'Responder el cuestionario legal'), 'alvo' => 'texto:Questionário Legal'],
                    ['texto' => $L('Pedir sugestões à IA', 'Ask the AI for suggestions', 'Pedir sugerencias a la IA'), 'alvo' => 'texto:Solicitar análise de IA'],
                    ['texto' => $L('Gerar o Relatório de Atendimento Legal', 'Generate the Legal Compliance Report', 'Generar el Informe de Cumplimiento Legal'), 'alvo' => 'texto:Relatório de Atendimento Legal'],
                ],
                'antes' => $L('A lei precisa estar ligada à unidade em Manutenção Legal › X Empresas. Os botões das associações e da avaliação só funcionam com uma linha selecionada.', 'The law must be linked to the unit in Legal Maintenance › X Companies. Association and assessment buttons only work with a selected row.', 'La ley debe estar vinculada a la unidad en Mantenimiento Legal › X Empresas. Los botones de asociaciones y evaluación solo funcionan con una fila seleccionada.'),
                'depois' => $L('A situação e as ligações alimentam o Painel, a Situação quanto ao cumprimento e as pendências de cada responsável.', 'Status and links feed the Dashboard, Compliance status and each responsible person\'s pending items.', 'La situación y los vínculos alimentan el Panel, la Situación de cumplimiento y los pendientes de cada responsable.'),
                'duvidas' => [
                    ['p' => $L('Por que um botão está cinza?', 'Why is a button gray?', '¿Por qué un botón está gris?'), 'r' => $L('Selecione uma linha do grid. Se continuar cinza, o seu perfil não tem a permissão daquela ação; peça ao administrador da empresa.', 'Select a grid row. If it stays gray, your profile lacks that permission; ask the company administrator.', 'Seleccione una fila del grid. Si sigue gris, su perfil no tiene ese permiso; pida al administrador de la empresa.')],
                    ['p' => $L('Uma lei não aparece no grid. O que faço?', 'A law does not appear in the grid. What do I do?', 'Una ley no aparece en el grid. ¿Qué hago?'), 'r' => $L('Confira os filtros e a busca. Se mesmo assim não aparecer, a lei não está ligada à unidade em Legislação x Empresas.', 'Check the filters and search. If it still does not appear, the law is not linked to the unit in Legislation x Companies.', 'Revise los filtros y la búsqueda. Si aun así no aparece, la ley no está vinculada a la unidad en Legislación x Empresas.')],
                ],
            ],
            [
                'chaves' => $telasLicencas,
                'oque' => $L('Cadastro das licenças de cada unidade, com vencimento, prazo de reação, protocolo, responsável e arquivos. A cor da situação mostra se a licença está em dia, em atenção ou vencida.', 'Register of each unit\'s licenses, with due date, reaction period, filing, responsible person and files. The status color shows whether the license is up to date, needs attention or expired.', 'Registro de las licencias de cada unidad, con vencimiento, plazo de reacción, protocolo, responsable y archivos. El color de la situación muestra si la licencia está al día, en atención o vencida.'),
                'pode' => [
                    ['texto' => $L('Incluir uma licença', 'Add a license', 'Incluir una licencia'), 'alvo' => 'acao:new'],
                    ['texto' => $L('Registrar as condicionantes', 'Record the conditions', 'Registrar las condicionantes'), 'alvo' => 'texto:Condicionantes'],
                    ['texto' => $L('Enviar o PDF e preencher com IA', 'Upload the PDF and fill it in with AI', 'Enviar el PDF y completar con IA'), 'alvo' => 'texto:Arquivos'],
                    ['texto' => $L('Ver os requisitos e documentos da licença', 'See the license requirements and documents', 'Ver los requisitos y documentos de la licencia'), 'alvo' => 'texto:Requisitos e documentos'],
                ],
                'antes' => $L('Selecione a linha da licença para liberar os botões Condicionantes, Palavras-chave, Responsabilidades, Requisitos e documentos e Arquivos.', 'Select the license row to enable the Conditions, Keywords, Responsibilities, Requirements and documents and Files buttons.', 'Seleccione la fila de la licencia para habilitar los botones Condicionantes, Palabras clave, Responsabilidades, Requisitos y documentos y Archivos.'),
                'depois' => $L('O responsável recebe os avisos de reação e de vencimento; a licença conta no Painel e em Minhas Licenças.', 'The responsible person receives reaction and expiration notices; the license counts on the Dashboard and in My Licenses.', 'El responsable recibe los avisos de reacción y vencimiento; la licencia cuenta en el Panel y en Mis Licencias.'),
                'duvidas' => [
                    ['p' => $L('O que é a Data de Reação?', 'What is the Reaction Date?', '¿Qué es la Fecha de Reacción?'), 'r' => $L('É a data limite para pedir a renovação ao órgão. Depois dela a licença fica "Em atenção".', 'It is the deadline to request renewal from the agency. After it, the license becomes "Attention".', 'Es la fecha límite para pedir la renovación al órgano. Después de ella la licencia queda "En atención".')],
                ],
            ],
            [
                'chaves' => $telasCondicionantes,
                'oque' => $L('As exigências (condicionantes) da licença escolhida: o que precisa ser feito, com que frequência, se está sendo atendido e quem responde.', 'The requirements (conditions) of the chosen license: what must be done, how often, whether it is met and who answers for it.', 'Las exigencias (condicionantes) de la licencia elegida: qué debe hacerse, con qué frecuencia, si se cumple y quién responde.'),
                'pode' => [
                    ['texto' => $L('Incluir uma condicionante', 'Add a condition', 'Incluir una condicionante'), 'alvo' => 'acao:new'],
                    ['texto' => $L('Abrir o plano de ação da condicionante', 'Open the condition action plan', 'Abrir el plan de acción de la condicionante'), 'alvo' => 'texto:Plano de Acao'],
                ],
                'depois' => $L('Excluir uma condicionante apaga também os planos de ação dela (como no sistema anterior).', 'Deleting a condition also deletes its action plans (as in the previous system).', 'Excluir una condicionante también borra sus planes de acción (como en el sistema anterior).'),
            ],
            [
                'chaves' => $telasPlano,
                'oque' => $L('O plano de ação da condicionante: cada linha é uma ação com responsável, datas, recursos, status (0 a 100%) e evidências. As últimas colunas tratam da avaliação da eficácia: marque Avaliar eficácia? = Sim e escolha o Avaliador (vazio = o responsável da condicionante); quando a ação chega a 100%, o avaliador registra Eficaz ou Não eficaz (Não eficaz reabre a ação em 75%).', 'The condition action plan: each row is an action with responsible person, dates, resources, status (0 to 100%) and evidence. The last columns handle the effectiveness review: set Review effectiveness? = Yes and choose the Reviewer (empty = the condition owner); when the action reaches 100%, the reviewer records Effective or Not effective (Not effective reopens the action at 75%).', 'El plan de acción de la condicionante: cada fila es una acción con responsable, fechas, recursos, estado (0 a 100%) y evidencias. Las últimas columnas tratan de la evaluación de la eficacia: marque ¿Evaluar eficacia? = Sí y elija el Evaluador (vacío = el responsable de la condicionante); cuando la acción llega al 100%, el evaluador registra Eficaz o No eficaz (No eficaz reabre la acción al 75%).'),
                'pode' => [
                    ['texto' => $L('Incluir uma ação', 'Add an action', 'Incluir una acción'), 'alvo' => 'acao:new'],
                    ['texto' => $L('Anexar a evidência (a IA sugere os campos)', 'Attach the evidence (the AI suggests the fields)', 'Adjuntar la evidencia (la IA sugiere los campos)'), 'alvo' => 'css:[data-ml-file-button]'],
                ],
                'depois' => $L('Ao abrir as Evidências, a IA analisa a evidência mais recente e pede a sua aprovação antes de gravar qualquer campo.', 'When you open Evidence, the AI analyzes the most recent evidence and asks for your approval before saving any field.', 'Al abrir las Evidencias, la IA analiza la evidencia más reciente y pide su aprobación antes de guardar cualquier campo.'),
            ],
        ],
    ],
];
