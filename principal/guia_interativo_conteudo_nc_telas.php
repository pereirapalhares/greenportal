<?php
declare(strict_types=1);

/*
 * "Sobre esta tela" do assunto Não Conformidades. Incluído por guia_interativo_conteudo_nc.php.
 * As abas da ocorrência vêm primeiro: o painel mostra a primeira tela cuja chave bate, e a NC aberta da lista também
 * carrega as chaves da lista por trás da janela.
 */

if (!defined('GREEN_GUIA_USUARIO')) { http_response_code(404); exit; }

return [
    // ------------------------------------------------------------ ocorrência: aba Ocorrência
    [
        'chaves' => ['nc_ocorrencia_ocorrencia'],
        'oque' => $L('A aba Ocorrência registra o problema: unidade, gestor, lista de distribuição, data, classificação, sumário e descrição. Os campos mudam conforme o tipo da NC; * indica obrigatório no envio.', 'The Occurrence tab records the problem: unit, manager, distribution list, date, classification, summary and description. Fields change with the NC type; * means required to send.', 'La pestaña Ocurrencia registra el problema: unidad, gestor, lista de distribución, fecha, clasificación, resumen y descripción. Los campos cambian según el tipo de NC; * indica obligatorio para enviar.'),
        'pode' => [
            ['texto' => $L('Salvar sem enviar (a NC continua Em criação)', 'Save without sending (the NC stays In creation)', 'Guardar sin enviar (la NC sigue En creación)'), 'alvo' => 'css:[data-enviar="ocorrencia"][data-sub="salvar"]'],
            ['texto' => $L('Salvar e enviar ao gestor', 'Save and send to the manager', 'Guardar y enviar al gestor'), 'alvo' => 'css:[data-enviar="ocorrencia"][data-sub="enviar"]'],
            ['texto' => $L('Anexar fotos e documentos (Anexos da ocorrência)', 'Attach photos and documents (Occurrence attachments)', 'Adjuntar fotos y documentos (Adjuntos de la ocurrencia)'), 'alvo' => 'css:[data-ged="ncocorrencia"]'],
            ['texto' => $L('Gerar o PDF da ocorrência', 'Create the occurrence PDF', 'Generar el PDF de la ocurrencia'), 'alvo' => 'css:[data-nc-pdf]'],
        ],
        'antes' => $L('Só o emitente altera esta aba, e só em "Em criação" ou "Recusado pelo Gestor". Para os demais, ela é consulta.', 'Only the issuer changes this tab, and only in "In creation" or "Refused by the Manager". For everyone else it is read-only.', 'Solo el emisor cambia esta pestaña, y solo en "En creación" o "Rechazado por el Gestor". Para los demás es de consulta.'),
        'depois' => $L('Enviada, a NC vai para "Com o Gestor" e o gestor e a lista são avisados.', 'Once sent, the NC goes to "With the Manager" and the manager and the list are notified.', 'Enviada, la NC pasa a "Con el Gestor" y el gestor y la lista reciben el aviso.'),
        'duvidas' => [
            ['p' => $L('Por que os botões não aparecem?', 'Why don\'t the buttons show?', '¿Por qué no aparecen los botones?'), 'r' => $L('Os botões só aparecem para quem age na etapa atual. O lápis verde no nome da aba indica onde você age agora.', 'Buttons only show for whoever acts in the current stage. The green pencil on the tab name shows where you act now.', 'Los botones solo aparecen para quien actúa en la etapa actual. El lápiz verde en el nombre de la pestaña indica dónde actúa usted ahora.')],
            ['p' => $L('O envio deu erro. Perdi o que digitei?', 'Sending failed. Did I lose what I typed?', 'El envío dio error. ¿Perdí lo que escribí?'), 'r' => $L('Não. A tela continua com o que você digitou e a mensagem diz o campo que falta. Gravar é tudo ou nada: nada fica gravado pela metade.', 'No. The screen keeps what you typed and the message says which field is missing. Saving is all or nothing: nothing is half-saved.', 'No. La pantalla conserva lo que escribió y el mensaje dice qué campo falta. Grabar es todo o nada: nada queda grabado a medias.')],
            ['p' => $L('Qual tipo de NC devo abrir?', 'Which NC type should I open?', '¿Qué tipo de NC debo abrir?'), 'r' => $L('Empresa: problema interno. Fornecedor: causado por um fornecedor. Cliente: reclamação de cliente. Acidente/Incidente: segurança. Planejamento e Assistência Técnica: vão direto ao plano de ação.', 'Company: internal problem. Supplier: caused by a supplier. Customer: customer complaint. Accident/Incident: safety. Planning and Technical Assistance: go straight to the action plan.', 'Empresa: problema interno. Proveedor: causado por un proveedor. Cliente: reclamo de cliente. Accidente/Incidente: seguridad. Planificación y Asistencia Técnica: van directo al plan de acción.')],
        ],
    ],
    // ------------------------------------------------------------ ocorrência: aba Avaliação do gestor
    [
        'chaves' => ['nc_ocorrencia_gestor'],
        'oque' => $L('A aba Avaliação do gestor é onde o gestor registra a ação imediata e a análise da abrangência, responde sobre riscos e mudança e escolhe o responsável pela análise da causa.', 'The Manager assessment tab is where the manager records the immediate action and the scope analysis, answers about risks and change and chooses who analyzes the cause.', 'La pestaña Evaluación del gestor es donde el gestor registra la acción inmediata y el análisis del alcance, responde sobre riesgos y cambio y elige el responsable del análisis de la causa.'),
        'pode' => [
            ['texto' => $L('Enviar ao responsável', 'Send to the responsible', 'Enviar al responsable'), 'alvo' => 'css:[data-enviar="gestor"][data-sub="enviar"]'],
            ['texto' => $L('Recusar e devolver ao emitente (com motivo)', 'Refuse and return to the issuer (with reason)', 'Rechazar y devolver al emisor (con motivo)'), 'alvo' => 'css:[data-enviar="gestor"][data-sub="recusar"]'],
            ['texto' => $L('Finalizar como improcedente', 'Close as unfounded', 'Finalizar como improcedente'), 'alvo' => 'css:[data-enviar="gestor"][data-sub="improcedente"]'],
            ['texto' => $L('Salvar para continuar depois', 'Save to continue later', 'Guardar para seguir después'), 'alvo' => 'css:[data-enviar="gestor"][data-sub="salvar"]'],
        ],
        'antes' => $L('Só o gestor da NC age aqui, com a NC "Com o Gestor".', 'Only the NC manager acts here, with the NC "With the Manager".', 'Solo el gestor de la NC actúa aquí, con la NC "Con el Gestor".'),
        'depois' => $L('Enviada: o responsável é avisado. Recusada: volta ao emitente com o motivo. Improcedente: finalizada.', 'Sent: the responsible is notified. Refused: back to the issuer with the reason. Unfounded: closed.', 'Enviada: el responsable recibe el aviso. Rechazada: vuelve al emisor con el motivo. Improcedente: finalizada.'),
        'duvidas' => [
            ['p' => $L('O que é obrigatório para enviar ao responsável?', 'What is required to send to the responsible?', '¿Qué es obligatorio para enviar al responsable?'), 'r' => $L('Ação imediata, análise da abrangência e o responsável.', 'Immediate action, scope analysis and the responsible.', 'Acción inmediata, análisis del alcance y el responsable.')],
            ['p' => $L('Recusar ou improcedente?', 'Refuse or unfounded?', '¿Rechazar o improcedente?'), 'r' => $L('Recuse quando falta informação ou algo está errado na ocorrência (o emitente corrige e reenvia). Use improcedente quando não é uma não conformidade (a NC termina).', 'Refuse when information is missing or something is wrong (the issuer fixes and resends). Use unfounded when it is not a non-conformity (the NC ends).', 'Rechace cuando falta información o algo está mal (el emisor corrige y reenvía). Use improcedente cuando no es una no conformidad (la NC termina).')],
        ],
    ],
    // ------------------------------------------------------------ ocorrência: aba Análise da causa e plano de ação
    [
        'chaves' => ['nc_ocorrencia_analise'],
        'oque' => $L('Aqui o responsável escreve a análise da causa (sub-aba Análise), cadastra as causas (Causas) e as ações com responsável, prazo e status (Plano de ação). No Planejamento e na Assistência Técnica esta aba é só o Plano de ação.', 'Here the responsible writes the cause analysis (Analysis sub-tab), registers the causes (Causes) and the actions with owner, deadline and status (Action plan). In Planning and Technical Assistance this tab is just the Action plan.', 'Aquí el responsable escribe el análisis de la causa (sub-pestaña Análisis), registra las causas (Causas) y las acciones con responsable, plazo y estado (Plan de acción). En Planificación y Asistencia Técnica esta pestaña es solo el Plan de acción.'),
        'pode' => [
            ['texto' => $L('Cadastrar uma causa', 'Register a cause', 'Registrar una causa'), 'alvo' => 'css:[data-nc-sub="causas"]'],
            ['texto' => $L('Criar ou editar uma ação do plano', 'Create or edit an action of the plan', 'Crear o editar una acción del plan'), 'alvo' => 'css:[data-nc-sub="plano"]'],
            ['texto' => $L('Pedir sugestões à IA (causas, ações e texto da análise)', 'Ask the AI for suggestions (causes, actions and analysis text)', 'Pedir sugerencias a la IA (causas, acciones y texto del análisis)'), 'alvo' => 'css:[data-nc-ia]'],
            ['texto' => $L('Anexar as evidências da análise', 'Attach the analysis evidence', 'Adjuntar las evidencias del análisis'), 'alvo' => 'css:[data-ged="ncanalise"]'],
            ['texto' => $L('Enviar para a avaliação da eficácia', 'Send to the effectiveness check', 'Enviar a la evaluación de la eficacia'), 'alvo' => 'css:[data-enviar="analise"][data-sub="enviar"]'],
        ],
        'antes' => $L('Análise, causas e plano: só o responsável escolhido pelo gestor. O status da ação: também o responsável de cada ação.', 'Analysis, causes and plan: only the responsible chosen by the manager. Action status: also each action owner.', 'Análisis, causas y plan: solo el responsable elegido por el gestor. Estado de la acción: también el responsable de cada acción.'),
        'depois' => $L('Para enviar à eficácia: análise, data prevista, pelo menos 1 causa, 1 ação por causa e o plano realizado.', 'To send to the effectiveness check: analysis, expected date, at least 1 cause, 1 action per cause and the plan done.', 'Para enviar a la eficacia: análisis, fecha prevista, al menos 1 causa, 1 acción por causa y el plan realizado.'),
        'duvidas' => [
            ['p' => $L('Excluí uma causa. O que acontece com as ações?', 'I deleted a cause. What happens to the actions?', 'Eliminé una causa. ¿Qué pasa con las acciones?'), 'r' => $L('As ações daquela causa são excluídas junto.', 'The actions of that cause are deleted too.', 'Las acciones de esa causa se eliminan junto.')],
            ['p' => $L('A IA grava alguma coisa sozinha?', 'Does the AI save anything by itself?', '¿La IA graba algo sola?'), 'r' => $L('Não. A resposta chega em Home › Alertas; você revisa, marca o que quer e aplica. Enquanto um pedido estiver em processamento, não é possível pedir outro.', 'No. The answer arrives in Home › Alerts; you review, tick what you want and apply. While a request is processing, you cannot ask for another.', 'No. La respuesta llega en Inicio › Alertas; usted revisa, marca lo que quiere y aplica. Mientras un pedido esté en proceso, no es posible pedir otro.')],
            ['p' => $L('A NC voltou da eficácia. Por quê?', 'The NC came back from the effectiveness check. Why?', 'La NC volvió de la eficacia. ¿Por qué?'), 'r' => $L('O gestor recusou ou marcou "Não eficaz". O motivo aparece em destaque no topo desta aba.', 'The manager refused it or set "Not effective". The reason shows highlighted at the top of this tab.', 'El gestor la rechazó o marcó "No eficaz". El motivo aparece destacado arriba de esta pestaña.')],
        ],
    ],
    // ------------------------------------------------------------ ocorrência: aba Verificação da eficácia
    [
        'chaves' => ['nc_ocorrencia_eficacia'],
        'oque' => $L('Na Verificação da eficácia o gestor confere se as ações resolveram o problema, descreve a verificação e aplica o resultado: Eficaz, Encerrada sem a verificação ou Não eficaz.', 'In the Effectiveness check the manager confirms whether the actions solved the problem, describes the check and applies the result: Effective, Closed without check or Not effective.', 'En la Verificación de la eficacia el gestor confirma si las acciones resolvieron el problema, describe la verificación y aplica el resultado: Eficaz, Cerrada sin verificación o No eficaz.'),
        'pode' => [
            ['texto' => $L('Aplicar o resultado', 'Apply the result', 'Aplicar el resultado'), 'alvo' => 'css:[data-enviar="eficacia"][data-sub="finalizar"]'],
            ['texto' => $L('Recusar e devolver ao responsável (com motivo)', 'Refuse and return to the responsible (with reason)', 'Rechazar y devolver al responsable (con motivo)'), 'alvo' => 'css:[data-enviar="eficacia"][data-sub="recusar"]'],
            ['texto' => $L('Pedir o parecer da IA com base nas evidências', 'Ask the AI for an opinion based on the evidence', 'Pedir el parecer de la IA con base en las evidencias'), 'alvo' => 'css:[data-nc-iaef]'],
        ],
        'antes' => $L('Só o gestor da NC, com a NC na Avaliação da Eficácia. Confira os arquivos de cada ação no Plano de ação.', 'Only the NC manager, with the NC in the Effectiveness Check. Look at each action\'s files in the Action plan.', 'Solo el gestor de la NC, con la NC en la Evaluación de la Eficacia. Revise los archivos de cada acción en el Plan de acción.'),
        'depois' => $L('Finalizada, a NC não aceita mais anexos novos (só download) e todos os envolvidos são avisados.', 'Once finalized, the NC no longer accepts new attachments (download only) and everyone involved is notified.', 'Finalizada, la NC ya no acepta adjuntos nuevos (solo descarga) y todos los involucrados reciben el aviso.'),
        'duvidas' => [
            ['p' => $L('O parecer da IA finaliza a NC?', 'Does the AI opinion close the NC?', '¿El parecer de la IA finaliza la NC?'), 'r' => $L('Não. "Levar para a verificação" só preenche o texto e sugere o resultado; quem conclui é você, em Aplicar o resultado.', 'No. "Take to the check" only fills in the text and suggests the result; you conclude it in Apply the result.', 'No. "Llevar a la verificación" solo completa el texto y sugiere el resultado; quien concluye es usted, en Aplicar el resultado.')],
            ['p' => $L('Por que pede data e responsável da pendência?', 'Why does it ask for a date and an owner of the pending item?', '¿Por qué pide fecha y responsable de la pendiente?'), 'r' => $L('Quando o parâmetro "eficácia exige prazo" está ligado na Configuração do módulo, esses dois campos são obrigatórios para aplicar o resultado.', 'When the "effectiveness requires deadline" parameter is on in the Module configuration, these two fields are required to apply the result.', 'Cuando el parámetro "eficacia exige plazo" está activo en la Configuración del módulo, estos dos campos son obligatorios para aplicar el resultado.')],
        ],
    ],
    // ------------------------------------------------------------ ocorrência: aba Histórico
    [
        'chaves' => ['nc_ocorrencia_historico'],
        'oque' => $L('O Histórico mostra cada passagem de etapa e cada alteração da NC: data, quem fez, de qual etapa para qual e o detalhe (motivos de recusa, correções da administração, uso da IA).', 'The History shows every stage change and every NC change: date, who did it, from which stage to which and the detail (refusal reasons, administration corrections, AI use).', 'El Historial muestra cada cambio de etapa y cada alteración de la NC: fecha, quién lo hizo, de qué etapa a cuál y el detalle (motivos de rechazo, correcciones de la administración, uso de la IA).'),
        'pode' => [
            ['texto' => $L('Escolher as colunas do grid', 'Choose the grid columns', 'Elegir las columnas de la grilla'), 'alvo' => 'acao:columns'],
        ],
        'antes' => $L('', '', ''),
        'depois' => $L('O histórico é gravado pelo sistema e não pode ser editado.', 'The history is written by the system and cannot be edited.', 'El historial lo graba el sistema y no se puede editar.'),
        'duvidas' => [],
    ],
    // ------------------------------------------------------------ Minhas ocorrências
    [
        'chaves' => ['naoconformidades_minhasocorrencias', 'NAO_CONFORMIDADES_MINHAS_OCORRENCIAS'],
        'oque' => $L('Minhas ocorrências lista as NCs em que você é emitente, gestor, responsável ou responsável de uma ação, inclusive as finalizadas. A coluna Etapa mostra onde cada uma está.', 'My occurrences lists the NCs where you are issuer, manager, responsible or action owner, finalized ones included. The Stage column shows where each one is.', 'Mis ocurrencias lista las NC en las que usted es emisor, gestor, responsable o responsable de una acción, incluidas las finalizadas. La columna Etapa muestra dónde está cada una.'),
        'pode' => [
            ['texto' => $L('Abrir a NC (duplo clique na linha)', 'Open the NC (double-click the row)', 'Abrir la NC (doble clic en la fila)'), 'alvo' => 'linha'],
            ['texto' => $L('Buscar por número, nome ou palavra', 'Search by number, name or word', 'Buscar por número, nombre o palabra'), 'alvo' => 'busca'],
            ['texto' => $L('Filtrar pela etapa', 'Filter by stage', 'Filtrar por etapa'), 'alvo' => 'filtro:ETAPA'],
            ['texto' => $L('Gerar o PDF da NC selecionada', 'Create the PDF of the selected NC', 'Generar el PDF de la NC seleccionada'), 'alvo' => 'css:[data-open-nc-pdf]'],
            ['texto' => $L('Exportar para Excel', 'Export to Excel', 'Exportar a Excel'), 'alvo' => 'acao:export'],
        ],
        'antes' => $L('', '', ''),
        'depois' => $L('Os filtros esperam 3 segundos depois da última tecla; Enter pesquisa na hora.', 'Filters wait 3 seconds after the last key; Enter searches at once.', 'Los filtros esperan 3 segundos después de la última tecla; Enter busca en el momento.'),
        'duvidas' => [
            ['p' => $L('Não encontro uma NC. Por quê?', 'I can\'t find an NC. Why?', 'No encuentro una NC. ¿Por qué?'), 'r' => $L('Aqui só aparecem as NCs em que você participa. Confira se há filtro de coluna preenchido ou procure na Lista geral de ocorrências (se tiver acesso).', 'Only NCs you take part in show here. Check for a filled column filter or look in the General occurrence list (if you have access).', 'Aquí solo aparecen las NC en las que usted participa. Revise si hay un filtro de columna lleno o busque en la Lista general de ocurrencias (si tiene acceso).')],
        ],
    ],
    // ------------------------------------------------------------ Lista geral
    [
        'chaves' => ['naoconformidades_listageralocorrencias', 'NAO_CONFORMIDADES_LISTA_OCORRENCIAS'],
        'oque' => $L('A Lista geral mostra todas as NCs que você pode ver, da mais nova para a mais antiga. Abre sem as finalizadas; escolha "Finalizado" no filtro Etapa para vê-las.', 'The General list shows every NC you can see, newest first. It opens without the finalized ones; choose "Finalized" in the Stage filter to see them.', 'La Lista general muestra todas las NC que usted puede ver, de la más nueva a la más antigua. Abre sin las finalizadas; elija "Finalizado" en el filtro Etapa para verlas.'),
        'pode' => [
            ['texto' => $L('Abrir a NC (duplo clique na linha)', 'Open the NC (double-click the row)', 'Abrir la NC (doble clic en la fila)'), 'alvo' => 'linha'],
            ['texto' => $L('Filtrar pela etapa', 'Filter by stage', 'Filtrar por etapa'), 'alvo' => 'filtro:ETAPA'],
            ['texto' => $L('Gerar o PDF da NC selecionada', 'Create the PDF of the selected NC', 'Generar el PDF de la NC seleccionada'), 'alvo' => 'css:[data-open-nc-pdf]'],
            ['texto' => $L('Exportar para Excel', 'Export to Excel', 'Exportar a Excel'), 'alvo' => 'acao:export'],
            ['texto' => $L('Escolher as colunas', 'Choose the columns', 'Elegir las columnas'), 'alvo' => 'acao:columns'],
        ],
        'antes' => $L('Sem a permissão de visualizar o total, a lista mostra só as NCs em que você está envolvido.', 'Without the view-all permission, the list shows only the NCs you are involved in.', 'Sin el permiso de ver el total, la lista muestra solo las NC en las que usted participa.'),
        'depois' => $L('', '', ''),
        'duvidas' => [],
    ],
    // ------------------------------------------------------------ Correção
    [
        'chaves' => ['NAO_CONFORMIDADES_CORRECAO', 'naoconformidades_correcao_busca', 'arquivo:naoconformidades_correcao.php'],
        'oque' => $L('Correção de ocorrência: busque a NC pelo número e corrija num só lugar emitente, gestor, responsável, responsáveis das ações, data, unidade, lista e textos. A etapa só muda por Reabrir, Devolver uma etapa ou Encerrar administrativamente.', 'Occurrence correction: find the NC by number and fix in one place issuer, manager, responsible, action owners, date, unit, list and texts. The stage only changes through Reopen, Return one stage or Close administratively.', 'Corrección de ocurrencia: busque la NC por número y corrija en un solo lugar emisor, gestor, responsable, responsables de las acciones, fecha, unidad, lista y textos. La etapa solo cambia con Reabrir, Devolver una etapa o Cerrar administrativamente.'),
        'pode' => [
            ['texto' => $L('Buscar a NC pelo número', 'Find the NC by number', 'Buscar la NC por número'), 'alvo' => 'css:input[name="nc"]'],
            ['texto' => $L('Escrever o motivo (obrigatório)', 'Write the reason (required)', 'Escribir el motivo (obligatorio)'), 'alvo' => 'css:#ncMotivo'],
            ['texto' => $L('Salvar as correções', 'Save the corrections', 'Guardar las correcciones'), 'alvo' => 'texto:Salvar correções'],
        ],
        'antes' => $L('Até a virada, só NCs do greenportal (a partir de 50001) são alteradas; as do sistema anterior aparecem só para consulta.', 'Until the cut-over, only greenportal NCs (from 50001) are changed; the previous system\'s NCs are read-only.', 'Hasta el cambio, solo se alteran las NC del greenportal (desde 50001); las del sistema anterior son solo de consulta.'),
        'depois' => $L('Tudo vai para o Histórico da NC com "antes → depois", e quem passa a ser gestor ou responsável é avisado.', 'Everything goes to the NC History with "before → after", and whoever becomes manager or responsible is notified.', 'Todo va al Historial de la NC con "antes → después", y quien pasa a ser gestor o responsable recibe el aviso.'),
        'duvidas' => [],
    ],
    // ------------------------------------------------------------ Transferência
    [
        'chaves' => ['naoconformidades_transferencia', 'NAO_CONFORMIDADES_TRANSFERENCIA'],
        'oque' => $L('Transferência de responsabilidades: escolha uma pessoa (desligamento ou troca de função), veja tudo o que está com ela — NCs como gestor, responsável ou emitente, ações abertas e listas — e passe o que marcar para outra pessoa.', 'Responsibility transfer: choose a person (leaver or role change), see everything they hold — NCs as manager, responsible or issuer, open actions and lists — and pass what you tick to someone else.', 'Transferencia de responsabilidades: elija una persona (desvinculación o cambio de función), vea todo lo que está con ella — NC como gestor, responsable o emisor, acciones abiertas y listas — y pase lo marcado a otra persona.'),
        'pode' => [
            ['texto' => $L('Escolher o usuário de origem', 'Choose the source user', 'Elegir el usuario de origen'), 'alvo' => 'css:#origem'],
            ['texto' => $L('Escolher o usuário de destino', 'Choose the target user', 'Elegir el usuario de destino'), 'alvo' => 'css:#destino'],
            ['texto' => $L('Transferir os marcados', 'Transfer the ticked ones', 'Transferir los marcados'), 'alvo' => 'texto:Transferir os marcados'],
        ],
        'antes' => $L('O motivo é obrigatório. Itens do sistema anterior aparecem só para consulta.', 'The reason is required. Items from the previous system are read-only.', 'El motivo es obligatorio. Los ítems del sistema anterior son solo de consulta.'),
        'depois' => $L('O destino é avisado em Home › Alertas.', 'The target is notified in Home › Alerts.', 'El destino recibe el aviso en Inicio › Alertas.'),
        'duvidas' => [],
    ],
    // ------------------------------------------------------------ Saneamento
    [
        'chaves' => ['naoconformidades_saneamento', 'NAO_CONFORMIDADES_SANEAMENTO'],
        'oque' => $L('Painel de saneamento: filas prontas para agir — NCs abertas sem gestor, com pessoa desativada, sem movimento, antigas e com ações vencidas.', 'Clean-up panel: ready-made queues — open NCs without manager, with a deactivated person, without movement, old and with overdue actions.', 'Panel de saneamiento: colas listas para actuar — NC abiertas sin gestor, con persona desactivada, sin movimiento, antiguas y con acciones vencidas.'),
        'pode' => [
            ['texto' => $L('Atribuir gestor às NCs marcadas', 'Assign a manager to the ticked NCs', 'Asignar gestor a las NC marcadas'), 'alvo' => 'css:[aria-label="Atribuir gestor às NCs marcadas"]'],
            ['texto' => $L('Lembrar os responsáveis das ações vencidas marcadas', 'Remind the owners of the ticked overdue actions', 'Recordar a los responsables de las acciones vencidas marcadas'), 'alvo' => 'css:[aria-label="Lembrar os responsáveis das ações marcadas"]'],
            ['texto' => $L('Abrir a NC selecionada na Correção de ocorrência', 'Open the selected NC in Occurrence correction', 'Abrir la NC seleccionada en Corrección de ocurrencia'), 'alvo' => 'css:[aria-label="Abrir a NC selecionada"]'],
        ],
        'antes' => $L('', '', ''),
        'depois' => $L('O painel não encerra NCs: para encerrar, use a Correção de ocorrência.', 'The panel does not close NCs: to close one, use Occurrence correction.', 'El panel no cierra NC: para cerrar, use la Corrección de ocurrencia.'),
        'duvidas' => [],
    ],
    // ------------------------------------------------------------ Listas de distribuição
    [
        'chaves' => ['naoconformidades_listasdistribuicao', 'NAO_CONFORMIDADES_LISTAS_DISTRIBUICAO', 'naoconformidades_listadistribuicao_pessoas'],
        'oque' => $L('Listas de distribuição: quem toma conhecimento das NCs. O grid mostra quantas pessoas cada lista tem, quantas estão inativas e em quantas NCs foi usada.', 'Distribution lists: who is informed of the NCs. The grid shows how many people each list has, how many are inactive and in how many NCs it was used.', 'Listas de distribución: quién toma conocimiento de las NC. La grilla muestra cuántas personas tiene cada lista, cuántas están inactivas y en cuántas NC se usó.'),
        'pode' => [
            ['texto' => $L('Criar uma lista nova', 'Create a new list', 'Crear una lista nueva'), 'alvo' => 'css:[aria-label="Nova lista"]'],
            ['texto' => $L('Abrir a lista selecionada', 'Open the selected list', 'Abrir la lista seleccionada'), 'alvo' => 'css:[aria-label="Abrir a lista selecionada"]'],
            ['texto' => $L('Incluir uma pessoa na lista aberta', 'Add a person to the open list', 'Incluir una persona en la lista abierta'), 'alvo' => 'css:#ncBusca'],
        ],
        'antes' => $L('Listas do sistema anterior (código abaixo de 50001) são só consulta; use "Copiar como nova lista" para alterar.', 'Lists from the previous system (code below 50001) are read-only; use "Copy as a new list" to change them.', 'Las listas del sistema anterior (código menor a 50001) son solo de consulta; use "Copiar como nueva lista" para alterarlas.'),
        'depois' => $L('Lista usada em NCs não pode ser excluída.', 'A list used in NCs cannot be deleted.', 'Una lista usada en NC no se puede eliminar.'),
        'duvidas' => [],
    ],
    // ------------------------------------------------------------ Participantes externos
    [
        'chaves' => ['naoconformidades_participantes', 'NAO_CONFORMIDADES_PARTICIPANTES', 'naoconformidades_participante_listas'],
        'oque' => $L('Participantes externos: pessoas sem login que estão nas listas de distribuição e recebem por e-mail o aviso de criação da NC.', 'External participants: people without login who are on the distribution lists and get the NC creation notice by e-mail.', 'Participantes externos: personas sin login que están en las listas de distribución y reciben por correo el aviso de creación de la NC.'),
        'pode' => [
            ['texto' => $L('Incluir um participante', 'Add a participant', 'Incluir un participante'), 'alvo' => 'css:[aria-label="Novo participante"]'],
            ['texto' => $L('Abrir o participante selecionado', 'Open the selected participant', 'Abrir el participante seleccionado'), 'alvo' => 'css:[aria-label="Abrir o participante selecionado"]'],
        ],
        'antes' => $L('Os do sistema anterior vêm pelo sincronismo: aqui só se altera o Ativo deles.', 'The ones from the previous system come through the sync: here only their Active flag changes.', 'Los del sistema anterior vienen por la sincronización: aquí solo se altera su Activo.'),
        'depois' => $L('Desativado, o participante deixa de receber os e-mails.', 'Once deactivated, the participant stops receiving the e-mails.', 'Desactivado, el participante deja de recibir los correos.'),
        'duvidas' => [],
    ],
    // ------------------------------------------------------------ Configuração
    [
        'chaves' => ['NAO_CONFORMIDADES_CONFIGURACAO', 'naoconformidades_configuracao_lembretes', 'arquivo:naoconformidades_configuracao.php'],
        'oque' => $L('Configuração do módulo: campos obrigatórios de cada tipo, parâmetros da empresa (eficácia exige prazo, listas de fornecedor, IA, lembretes) e as regras de lembrete automático.', 'Module configuration: required fields of each type, company parameters (effectiveness requires deadline, supplier lists, AI, reminders) and the automatic reminder rules.', 'Configuración del módulo: campos obligatorios de cada tipo, parámetros de la empresa (eficacia exige plazo, listas de proveedor, IA, recordatorios) y las reglas de recordatorio automático.'),
        'pode' => [
            ['texto' => $L('Campos por tipo', 'Fields by type', 'Campos por tipo'), 'alvo' => 'aba:Campos por tipo'],
            ['texto' => $L('Parâmetros', 'Parameters', 'Parámetros'), 'alvo' => 'aba:Parâmetros'],
            ['texto' => $L('Ligar e ajustar lembretes', 'Turn on and adjust reminders', 'Activar y ajustar recordatorios'), 'alvo' => 'aba:Lembretes'],
            ['texto' => $L('Simular os lembretes (não envia)', 'Simulate the reminders (sends nothing)', 'Simular los recordatorios (no envía)'), 'alvo' => 'css:[aria-label="Simular"]'],
        ],
        'antes' => $L('Só o perfil ADMINISTRADOR.', 'ADMINISTRADOR profile only.', 'Solo el perfil ADMINISTRADOR.'),
        'depois' => $L('As regras nascem desligadas; as ligadas rodam a cada hora. Toda alteração fica na aba Histórico.', 'Rules are created off; the ones that are on run every hour. Every change is in the History tab.', 'Las reglas nacen desactivadas; las activas corren cada hora. Todo cambio queda en la pestaña Historial.'),
        'duvidas' => [],
    ],
    // ------------------------------------------------------------ Cadastros
    [
        'chaves' => $telasCadastros,
        'oque' => $L('Cadastro usado nas listas de escolha das NCs. Registros do sistema anterior (código abaixo de 50001) são só consulta, menos a coluna Ativo; os novos são criados aqui.', 'Register used in the NC pick lists. Records from the previous system (code below 50001) are read-only, except the Active column; new ones are created here.', 'Registro usado en las listas de elección de las NC. Los registros del sistema anterior (código menor a 50001) son solo de consulta, salvo la columna Activo; los nuevos se crean aquí.'),
        'pode' => [
            ['texto' => $L('Incluir um registro', 'Add a record', 'Incluir un registro'), 'alvo' => 'acao:new'],
            ['texto' => $L('Salvar as alterações', 'Save the changes', 'Guardar los cambios'), 'alvo' => 'acao:save'],
            ['texto' => $L('Exportar para Excel', 'Export to Excel', 'Exportar a Excel'), 'alvo' => 'acao:export'],
        ],
        'antes' => $L('', '', ''),
        'depois' => $L('Desativado, o item some das escolhas, mas continua nas NCs que já o usam. Registro em uso não pode ser excluído e nome repetido é bloqueado.', 'Once deactivated, the item disappears from the choices but stays in NCs that already use it. A record in use cannot be deleted and duplicate names are blocked.', 'Desactivado, el ítem desaparece de las opciones pero sigue en las NC que ya lo usan. Un registro en uso no se puede eliminar y el nombre repetido se bloquea.'),
        'duvidas' => [],
    ],
];
