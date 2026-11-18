<?php
declare(strict_types=1);

require_once __DIR__ . '/IdiomaService.php';
require_once __DIR__ . '/ExportacaoTextos.php';

/**
 * Alertas e e-mails de alerta no idioma de quem lê (Onda 2 de idiomas, 18/11/2026).
 * Os alertas são gravados em português, por vários pontos do PHP e por procedures (sp_nc_alertar, sp_resp_regras_avisar,
 * sp_eficacia_avisar, sp_ia_limite_avisar…). Em vez de mexer em cada um, a tradução acontece na hora de mostrar
 * (AlertasService::listar, sino e Home › Alertas) e de montar o e-mail (AlertaEmailService, no idioma do destinatário).
 * Também vale para os alertas já gravados.
 * Cada modelo é um texto em português com lacunas {nome}; o que cai nas lacunas (nomes, números, datas, dados) fica como
 * está. Lacunas que começam com "t_" são traduzidas de novo (ex.: o tipo da exportação, a frase do alerta de NC).
 * A mensagem é traduzida inteira ou linha a linha; linha que não bate com nenhum modelo fica como está.
 * Textos em Administração › Textos e idiomas, grupo "alertas" (chave alertas.<id>).
 */
final class AlertaTextos
{
    public const GRUPO = 'alertas';

    /** id => [pt, en, es] — do mais específico para o mais geral dentro de cada assunto. */
    private const MODELOS = [
        // ---- exportações (ExportacaoSolicitacao, ExportacaoGridWorker, grids)
        'exp.iniciada' => ['Exportação iniciada', 'Export started', 'Exportación iniciada'],
        'exp.concluida' => ['Exportação concluída', 'Export completed', 'Exportación concluida'],
        'exp.erro' => ['Erro na exportação', 'Export error', 'Error en la exportación'],
        'exp.msg_iniciada_planilha' => ['A exportação de {t_tipo} foi iniciada em {data}. A planilha chegará nos alertas assim que for gerada.', 'The export of {t_tipo} started on {data}. The spreadsheet will arrive in the alerts as soon as it is generated.', 'La exportación de {t_tipo} se inició el {data}. La planilla llegará en las alertas en cuanto se genere.'],
        'exp.msg_iniciada' => ['A exportação de {t_tipo} foi iniciada em {data}.', 'The export of {t_tipo} started on {data}.', 'La exportación de {t_tipo} se inició el {data}.'],
        'exp.msg_planilha' => ['A planilha de {t_tipo} está disponível para download. Início: {ini}. Fim: {fim}.', 'The {t_tipo} spreadsheet is available for download. Start: {ini}. End: {fim}.', 'La planilla de {t_tipo} está disponible para descargar. Inicio: {ini}. Fin: {fim}.'],
        'exp.msg_pdf' => ['O PDF de {t_tipo} está disponível para download. Início: {ini}. Fim: {fim}.', 'The {t_tipo} PDF is available for download. Start: {ini}. End: {fim}.', 'El PDF de {t_tipo} está disponible para descargar. Inicio: {ini}. Fin: {fim}.'],
        'exp.msg_erro' => ['Não foi possível gerar a planilha de {t_tipo}. Início: {ini}. Fim: {fim}. Erro: {erro}', 'The {t_tipo} spreadsheet could not be generated. Start: {ini}. End: {fim}. Error: {erro}', 'No fue posible generar la planilla de {t_tipo}. Inicio: {ini}. Fin: {fim}. Error: {erro}'],
        'exp.relatorio_titulo' => ['Relatório de Atendimento Legal solicitado', 'Legal Compliance Report requested', 'Informe de Cumplimiento Legal solicitado'],
        'exp.relatorio_msg' => ['O relatório ({formatos}) está sendo gerado; os arquivos chegam nos alertas.', 'The report ({formatos}) is being generated; the files arrive in the alerts.', 'El informe ({formatos}) se está generando; los archivos llegan en las alertas.'],
        // nomes das exportações (lacuna t_tipo)
        'tipo.atendimento' => ['Relatório de Atendimento Legal', 'Legal Compliance Report', 'Informe de Cumplimiento Legal'],
        'tipo.requisitos' => ['Requisitos legais', 'Legal requirements', 'Requisitos legales'],
        'tipo.requisitos_cod' => ['modulolegal_requisitoslegais', 'legal requirements', 'requisitos legales'],
        'tipo.painel_homolog_filtro' => ['Painel de Homologação ({t_filtro})', 'Supplier approval panel ({t_filtro})', 'Panel de Homologación ({t_filtro})'],
        'tipo.painel_homolog' => ['Painel de Homologação', 'Supplier approval panel', 'Panel de Homologación'],
        'tipo.rel_homolog_nome' => ['Relatórios de Homologação ({t_filtro})', 'Approval reports ({t_filtro})', 'Informes de Homologación ({t_filtro})'],
        'tipo.rel_homolog' => ['Relatórios de Homologação', 'Approval reports', 'Informes de Homologación'],
        'tipo.lista_nc' => ['Lista geral de ocorrências', 'General list of occurrences', 'Lista general de ocurrencias'],
        'tipo.docs_fornecedor' => ['Documentos do fornecedor {nome}', 'Supplier documents {nome}', 'Documentos del proveedor {nome}'],
        'tipo.todos_itens' => ['Todos os itens do filtro', 'All items in the filter', 'Todos los ítems del filtro'],
        'tipo.integracao' => ['Integração', 'Onboarding', 'Integración'],
        // ---- não conformidades (sp_nc_alertar, sp_nc_alertar_usuario, sp_nc_ia_analise_concluida)
        'nc.titulo' => ['NC {nr}: {t_frase}', 'NC {nr}: {t_frase}', 'NC {nr}: {t_frase}'],
        'nc.fornecedor' => ['Sr. Fornecedor, {t_frase}', 'Dear Supplier, {t_frase}', 'Estimado proveedor, {t_frase}'],
        'nc.causa' => ['Favor avaliar a causa raiz de não conformidade', 'Please assess the root cause of the nonconformity', 'Favor evaluar la causa raíz de la no conformidad'],
        'nc.acao' => ['Favor avaliar a ação sob sua responsabilidade de uma não conformidade', 'Please assess the action under your responsibility in a nonconformity', 'Favor evaluar la acción bajo su responsabilidad de una no conformidad'],
        'nc.planejamento' => ['Favor preencher o plano de ação para esta não conformidade de Planejamento', 'Please fill in the action plan for this Planning nonconformity', 'Favor completar el plan de acción de esta no conformidad de Planificación'],
        'nc.gestor' => ['Favor avaliar a ocorrência de não conformidade', 'Please assess the nonconformity occurrence', 'Favor evaluar la ocurrencia de no conformidad'],
        'nc.criacao' => ['Tomar conhecimento da criação da não conformidade', 'For your information: a nonconformity was created', 'Para su conocimiento: se creó la no conformidad'],
        'nc.recusada' => ['Não conformidade foi recusada pelo Gestor', 'Nonconformity was rejected by the manager', 'La no conformidad fue rechazada por el gestor'],
        'nc.improcedente' => ['Não conformidade foi encerrada por improcedência', 'Nonconformity was closed as unfounded', 'La no conformidad fue cerrada por improcedente'],
        'nc.eficacia' => ['Favor fazer a análise da eficácia da não conformidade', 'Please carry out the effectiveness assessment of the nonconformity', 'Favor hacer el análisis de la eficacia de la no conformidad'],
        'nc.recusa_eficacia' => ['Favor avaliar a recusa da não conformidade pela Avaliação da Eficácia', 'Please review the rejection of the nonconformity at the effectiveness assessment', 'Favor evaluar el rechazo de la no conformidad en la evaluación de la eficacia'],
        'nc.finalizacao' => ['Tomar conhecimento da finalização da não conformidade', 'For your information: the nonconformity was closed', 'Para su conocimiento: la no conformidad fue finalizada'],
        'nc.ia_eficacia_pronta' => ['o parecer da IA para a avaliação da eficácia está pronto', 'the AI opinion for the effectiveness assessment is ready', 'el dictamen de la IA para la evaluación de la eficacia está listo'],
        'nc.ia_eficacia_falhou' => ['a IA não conseguiu avaliar a eficácia', 'the AI could not assess the effectiveness', 'la IA no pudo evaluar la eficacia'],
        'nc.ia_causa_pronta' => ['a sugestão da IA para a análise da causa está pronta', 'the AI suggestion for the cause analysis is ready', 'la sugerencia de la IA para el análisis de la causa está lista'],
        'nc.ia_causa_falhou' => ['a IA não conseguiu fazer a análise da causa', 'the AI could not do the cause analysis', 'la IA no pudo hacer el análisis de la causa'],
        'nc.msg_etapa' => ['Não conformidade {nr} — {sumario}. Etapa: {v_etapa}.', 'Nonconformity {nr} — {sumario}. Stage: {v_etapa}.', 'No conformidad {nr} — {sumario}. Etapa: {v_etapa}.'],
        'nc.msg_ia_parecer' => ['Não conformidade {nr} — {sumario}. Abra para revisar o parecer (nada foi gravado na NC; o resultado continua sendo decisão do gestor).', 'Nonconformity {nr} — {sumario}. Open it to review the opinion (nothing was saved in the NC; the result is still the manager\'s decision).', 'No conformidad {nr} — {sumario}. Ábrala para revisar el dictamen (no se guardó nada en la NC; el resultado sigue siendo decisión del gestor).'],
        'nc.msg_ia_sugestao' => ['Não conformidade {nr} — {sumario}. Abra para revisar a sugestão e aplicar o que quiser (nada foi gravado na NC).', 'Nonconformity {nr} — {sumario}. Open it to review the suggestion and apply what you want (nothing was saved in the NC).', 'No conformidad {nr} — {sumario}. Ábrala para revisar la sugerencia y aplicar lo que quiera (no se guardó nada en la NC).'],
        'nc.msg_ia_erro' => ['Não conformidade {nr} — {sumario}. Erro: {erro}. Você pode pedir uma nova análise.', 'Nonconformity {nr} — {sumario}. Error: {erro}. You can request a new analysis.', 'No conformidad {nr} — {sumario}. Error: {erro}. Puede pedir un nuevo análisis.'],
        'nc.msg' => ['Não conformidade {nr} — {sumario}', 'Nonconformity {nr} — {sumario}', 'No conformidad {nr} — {sumario}'],
        // ---- responsáveis e avisos dos módulos (sp_resp_regras_avisar) e eficácia (sp_eficacia_avisar)
        'resp.passou' => ['{t_area}: você passou a responder por {n} registro(s)', '{t_area}: you are now responsible for {n} record(s)', '{t_area}: usted pasó a responder por {n} registro(s)'],
        'resp.vence30' => ['{t_area}: {n} vence(m) em até 30 dias', '{t_area}: {n} due within 30 days', '{t_area}: {n} vence(n) en hasta 30 días'],
        'resp.reacao' => ['{t_area}: {n} com a data de reação atingida', '{t_area}: {n} reached the reaction date', '{t_area}: {n} con la fecha de reacción alcanzada'],
        'resp.medicao7' => ['{t_area}: {n} medição(ões) prevista(s) em até 7 dias', '{t_area}: {n} measurement(s) scheduled within 7 days', '{t_area}: {n} medición(es) prevista(s) en hasta 7 días'],
        'resp.alterados' => ['{t_area}: {n} registro(s) alterado(s) por outra pessoa', '{t_area}: {n} record(s) changed by someone else', '{t_area}: {n} registro(s) modificado(s) por otra persona'],
        'resp.eficacia' => ['{t_area}: avaliar a eficácia de {n} ação(ões) concluída(s)', '{t_area}: assess the effectiveness of {n} completed action(s)', '{t_area}: evaluar la eficacia de {n} acción(es) concluida(s)'],
        'resp.linha_vence' => ['• {item} — vence em {data}', '• {item} — due on {data}', '• {item} — vence el {data}'],
        'resp.linha_reacao' => ['• {item} — reação em {data}', '• {item} — reaction on {data}', '• {item} — reacción el {data}'],
        'resp.rodape' => ['Você recebe este aviso porque responde por estes registros (Responsável do registro, atribuição ou regra de Responsáveis e avisos do módulo).', 'You receive this notice because you are responsible for these records (record owner, assignment or rule in the module\'s Owners and notices).', 'Recibe este aviso porque responde por estos registros (responsable del registro, asignación o regla de Responsables y avisos del módulo).'],
        'resp.eficacia_rodape' => ['Você é o avaliador destas ações. Abra o plano de ação e registre Eficaz ou Não eficaz (Não eficaz reabre a ação).', 'You are the assessor of these actions. Open the action plan and record Effective or Not effective (Not effective reopens the action).', 'Usted es el evaluador de estas acciones. Abra el plan de acción y registre Eficaz o No eficaz (No eficaz reabre la acción).'],
        'area.licencas' => ['Licenças', 'Licenses', 'Licencias'],
        'area.medicao' => ['Medição e monitoramento', 'Measurement and monitoring', 'Medición y monitoreo'],
        'area.controles' => ['Controles', 'Controls', 'Controles'],
        'area.treinamentos' => ['Treinamentos', 'Trainings', 'Capacitaciones'],
        'area.infraestrutura' => ['Infraestrutura', 'Infrastructure', 'Infraestructura'],
        'area.condicionantes' => ['Condicionantes', 'Conditions', 'Condicionantes'],
        'area.fornecedores' => ['Fornecedores', 'Suppliers', 'Proveedores'],
        // ---- homologação (workers e painel)
        'hom.resumo' => ['Resumo semanal da homologação', 'Weekly supplier approval summary', 'Resumen semanal de la homologación'],
        'hom.resumo_msg_30' => ['Suas pendências na homologação de fornecedores: {v} vermelho(s), {a} amarelo(s), {p} vencendo nos próximos {d} dias. Abra o Painel de Homologação para ver a lista.', 'Your pending items in supplier approval: {v} red, {a} yellow, {p} expiring in the next {d} days. Open the Approval panel to see the list.', 'Sus pendencias en la homologación de proveedores: {v} rojo(s), {a} amarillo(s), {p} venciendo en los próximos {d} días. Abra el Panel de Homologación para ver la lista.'],
        'hom.resumo_msg' => ['Suas pendências na homologação de fornecedores: {v} vermelho(s), {a} amarelo(s). Abra o Painel de Homologação para ver a lista.', 'Your pending items in supplier approval: {v} red, {a} yellow. Open the Approval panel to see the list.', 'Sus pendencias en la homologación de proveedores: {v} rojo(s), {a} amarillo(s). Abra el Panel de Homologación para ver la lista.'],
        'hom.docs_analise' => ['Homologação — documentos para análise', 'Supplier approval — documents to review', 'Homologación — documentos para análisis'],
        'hom.docs_fornecedor' => ['Homologação — documentos pendentes com o fornecedor', 'Supplier approval — documents pending with the supplier', 'Homologación — documentos pendientes con el proveedor'],
        'hom.protocolo' => ['Protocolo vencido — documentos de fornecedor', 'Filing deadline expired — supplier documents', 'Protocolo vencido — documentos de proveedor'],
        'hom.protocolo_msg' => ['Venceu o prazo do protocolo de {n} documento(s) de fornecedor; voltaram para "Pendente com o fornecedor": {docs}', 'The filing (protocol) deadline of {n} supplier document(s) expired; they went back to "Pending with the supplier": {docs}', 'Venció el plazo del protocolo de {n} documento(s) de proveedor; volvieron a "Pendiente con el proveedor": {docs}'],
        'hom.relatorio_semanal' => ['Relatório semanal: {nome}', 'Weekly report: {nome}', 'Informe semanal: {nome}'],
        'hom.painel_titulo' => ['Homologação: {t_filtro}', 'Supplier approval: {t_filtro}', 'Homologación: {t_filtro}'],
        'hom.painel_msg' => ['Há {n} item(ns) em "{t_filtro}" sob sua responsabilidade. Abra o Painel de Homologação para ver a lista.', 'There are {n} item(s) in "{t_filtro}" under your responsibility. Open the Approval panel to see the list.', 'Hay {n} ítem(s) en "{t_filtro}" bajo su responsabilidad. Abra el Panel de Homologación para ver la lista.'],
        'hom.adulteracao_alto' => ['Risco alto de adulteração — {doc}', 'High risk of tampering — {doc}', 'Riesgo alto de adulteración — {doc}'],
        'hom.adulteracao' => ['Indícios de adulteração — {doc}', 'Signs of tampering — {doc}', 'Indicios de adulteración — {doc}'],
        'hom.fora_modelo' => ['Documento fora do modelo — {doc}', 'Document does not match the template — {doc}', 'Documento fuera del modelo — {doc}'],
        'hom.parecer_modelo' => ['Parecer da IA pelo modelo — {doc}', 'AI opinion based on the template — {doc}', 'Dictamen de la IA según el modelo — {doc}'],
        'hom.integracao' => ['Integração respondida — {nome}', 'Onboarding answered — {nome}', 'Integración respondida — {nome}'],
        'hom.integracao_msg' => ['{nome} ({fornecedor}) respondeu o questionário de integração: {pct}%, sugestão {sugestao}. Confirme a decisão.', '{nome} ({fornecedor}) answered the onboarding questionnaire: {pct}%, suggestion {sugestao}. Confirm the decision.', '{nome} ({fornecedor}) respondió el cuestionario de integración: {pct}%, sugerencia {sugestao}. Confirme la decisión.'],
        // ---- módulo legal, IA e tarefas
        'lei.revisar' => ['Revisar: {lei} — {tipo}', 'Review: {lei} — {tipo}', 'Revisar: {lei} — {tipo}'],
        'lei.pode_afetar' => ['Lei alterada pode afetar: {nome}', 'Changed law may affect: {nome}', 'Ley modificada puede afectar: {nome}'],
        'lei.relacionada' => ['A {lei} teve {tipo} e parece relacionada ({pct}%) com "{nome}", de que você é responsável.', '{lei} had {tipo} and seems related ({pct}%) to "{nome}", which you are responsible for.', 'La {lei} tuvo {tipo} y parece relacionada ({pct}%) con "{nome}", de lo cual usted es responsable.'],
        'ia.potencial_solicitada' => ['Analise de IA solicitada', 'AI analysis requested', 'Análisis de IA solicitado'],
        'ia.potencial_fila' => ['A analise foi colocada na fila e sera processada em segundo plano.', 'The analysis was queued and will be processed in the background.', 'El análisis fue puesto en cola y será procesado en segundo plano.'],
        'ia.risco_prontas' => ['Sugestões de risco da IA prontas para revisar', 'AI risk suggestions ready for review', 'Sugerencias de riesgo de la IA listas para revisar'],
        'ia.risco_falhou' => ['A IA não conseguiu sugerir o risco', 'The AI could not suggest the risk', 'La IA no pudo sugerir el riesgo'],
        'ia.limite_usado' => ['IA: {pct}% do limite mensal já usado', 'AI: {pct}% of the monthly limit already used', 'IA: {pct}% del límite mensual ya usado'],
        'ia.limite_atingido' => ['IA: limite mensal da empresa atingido', 'AI: company monthly limit reached', 'IA: límite mensual de la empresa alcanzado'],
        'ia.bloqueada' => ['IA bloqueada: limite mensal e folga esgotados', 'AI blocked: monthly limit and margin used up', 'IA bloqueada: límite mensual y holgura agotados'],
        'tarefa.ia' => ['Tarefa criada a partir de IA', 'Task created from AI', 'Tarea creada a partir de la IA'],
        'tarefa.ia_msg' => ['O potencial aprovado "{nome}" gerou uma tarefa para tratamento.', 'The approved potential "{nome}" created a task to be handled.', 'El potencial aprobado "{nome}" generó una tarea para tratamiento.'],
        // ---- pendências e transferências
        'pend.ocultada' => ['Pendência ocultada: {nome}', 'Pending item hidden: {nome}', 'Pendencia ocultada: {nome}'],
        'pend.ocultadas' => ['{n} pendências suas foram ocultadas', '{n} of your pending items were hidden', '{n} pendencias suyas fueron ocultadas'],
        'pend.sem' => ['Registro sem pendências: {nome}', 'Record without pending items: {nome}', 'Registro sin pendencias: {nome}'],
        'pend.desligadas_ate' => ['Suas pendências foram desligadas até {data}', 'Your pending items were turned off until {data}', 'Sus pendencias fueron desactivadas hasta el {data}'],
        'pend.desligadas' => ['Suas pendências foram desligadas', 'Your pending items were turned off', 'Sus pendencias fueron desactivadas'],
        'pend.voltaram' => ['Suas pendências voltaram a ser geradas', 'Your pending items are being generated again', 'Sus pendencias volvieron a generarse'],
        'pend.reativada' => ['Pendência reativada: {nome}', 'Pending item reactivated: {nome}', 'Pendencia reactivada: {nome}'],
        'transf.para_voce' => ['Responsabilidades de {nome} transferidas para você', 'Responsibilities of {nome} transferred to you', 'Responsabilidades de {nome} transferidas a usted'],
        'transf.suas' => ['Suas responsabilidades foram transferidas para {nome}', 'Your responsibilities were transferred to {nome}', 'Sus responsabilidades fueron transferidas a {nome}'],
        'transf.gestor' => ['Transferência de responsabilidades: {de} → {para}', 'Transfer of responsibilities: {de} → {para}', 'Transferencia de responsabilidades: {de} → {para}'],
        'transf.voltaram' => ['Suas responsabilidades voltaram para você', 'Your responsibilities came back to you', 'Sus responsabilidades volvieron a usted'],
        'transf.encerrada' => ['Transferência encerrada: as responsabilidades de {nome} voltaram para essa pessoa', 'Transfer closed: the responsibilities of {nome} went back to that person', 'Transferencia cerrada: las responsabilidades de {nome} volvieron a esa persona'],
        // ---- administração (serviços, sincronismo)
        'adm.servicos' => ['Serviços — {t_titulo}', 'Services — {t_titulo}', 'Servicios — {t_titulo}'],
        'adm.continua' => ['Continua: {t_titulo}', 'Still open: {t_titulo}', 'Continúa: {t_titulo}'],
        'adm.resolvido' => ['Resolvido: {t_titulo}', 'Resolved: {t_titulo}', 'Resuelto: {t_titulo}'],
        'adm.suspenso' => ['Serviço {servico} suspenso na empresa {empresa}', 'Service {servico} suspended in company {empresa}', 'Servicio {servico} suspendido en la empresa {empresa}'],
        'adm.suspenso_msg' => ['Deu erro 3 vezes seguidas nesta empresa e fica sem rodar nela até {data}; nas outras empresas segue normal.', 'It failed 3 times in a row in this company and will not run there until {data}; it keeps running normally in the other companies.', 'Falló 3 veces seguidas en esta empresa y no se ejecutará en ella hasta el {data}; en las demás empresas sigue normal.'],
        'adm.sinc_parado' => ['Sincronismo agendado parado — revisar antes de aplicar', 'Scheduled synchronization stopped — review before applying', 'Sincronización programada detenida — revisar antes de aplicar'],
        // ---- reavaliação de lei: mensagem e motivo
        'lei.msg' => ['A {lei} teve {tipo}. {t_motivo} Revise os requisitos e itens indicados (Módulo Legal › Reavaliação por mudança de lei).', '{lei} had {tipo}. {t_motivo} Review the indicated requirements and items (Legal Module › Reassessment due to law change).', 'La {lei} tuvo {tipo}. {t_motivo} Revise los requisitos e ítems indicados (Módulo Legal › Reevaluación por cambio de ley).'],
        'lei.motivo_req_par' => ['A lei está nos requisitos legais da empresa e há {n} item(ns) parecido(s).', 'The law is among the company\'s legal requirements and there are {n} similar item(s).', 'La ley está en los requisitos legales de la empresa y hay {n} ítem(s) parecido(s).'],
        'lei.motivo_req' => ['A lei está nos requisitos legais da empresa.', 'The law is among the company\'s legal requirements.', 'La ley está en los requisitos legales de la empresa.'],
        'lei.motivo_fora_par' => ['Fora dos requisitos, mas parecida com {n} item(ns) da empresa (até {pct}%).', 'Not in the requirements, but similar to {n} company item(s) (up to {pct}%).', 'Fuera de los requisitos, pero parecida a {n} ítem(s) de la empresa (hasta {pct}%).'],
        'lei.motivo_fora_sem_muito' => ['Fora dos requisitos e sem item muito parecido (maior semelhança {pct}%).', 'Not in the requirements and with no very similar item (highest similarity {pct}%).', 'Fuera de los requisitos y sin ítem muy parecido (mayor semejanza {pct}%).'],
        'lei.motivo_fora_sem' => ['Fora dos requisitos e sem item parecido.', 'Not in the requirements and with no similar item.', 'Fuera de los requisitos y sin ítem parecido.'],
        // ---- risco (sp_ml_risco_lote_verificar)
        'risco.item_um' => ['Item "{item}": {n} sugestão de Severidade × Probabilidade para revisar. Nada foi gravado nos itens: aceite, ajuste ou recuse cada sugestão.', 'Item "{item}": {n} Severity × Probability suggestion to review. Nothing was saved in the items: accept, adjust or reject each suggestion.', 'Ítem "{item}": {n} sugerencia de Severidad × Probabilidad para revisar. No se guardó nada en los ítems: acepte, ajuste o rechace cada sugerencia.'],
        'risco.item_varios' => ['Item "{item}": {n} sugestões de Severidade × Probabilidade para revisar. Nada foi gravado nos itens: aceite, ajuste ou recuse cada sugestão.', 'Item "{item}": {n} Severity × Probability suggestions to review. Nothing was saved in the items: accept, adjust or reject each suggestion.', 'Ítem "{item}": {n} sugerencias de Severidad × Probabilidad para revisar. No se guardó nada en los ítems: acepte, ajuste o rechace cada sugerencia.'],
        // ---- análises de IA de arquivos (já criadas no idioma de quem pediu; aqui para quem lê em outro idioma)
        'ged.iniciada' => ['Análise de IA iniciada', 'AI analysis started', 'Análisis de IA iniciado'],
        'ged.concluida' => ['Análise de IA concluída', 'AI analysis completed', 'Análisis de IA concluido'],
        'ged.erro' => ['Análise de IA não concluída', 'AI analysis failed', 'Análisis de IA no concluido'],
        // ---- mensagens de transferência e de pendências
        'transf.msg_destino' => ['Você passa a responder pelo que é de {nome} ({resumo}). As pendências e os alertas dessa pessoa nesses módulos vão aparecer para você. Motivo: {motivo}', 'You are now responsible for what belongs to {nome} ({resumo}). That person\'s pending items and alerts in these modules will appear for you. Reason: {motivo}', 'Usted pasa a responder por lo que es de {nome} ({resumo}). Las pendencias y alertas de esa persona en esos módulos aparecerán para usted. Motivo: {motivo}'],
        'transf.msg_origem' => ['{nome} passa a responder pelo que é seu ({resumo}). Nesse período as pendências e os alertas desses módulos vão para essa pessoa. Motivo: {motivo}', '{nome} is now responsible for what is yours ({resumo}). During this period the pending items and alerts of these modules go to that person. Reason: {motivo}', '{nome} pasa a responder por lo que es suyo ({resumo}). En ese período las pendencias y alertas de esos módulos van a esa persona. Motivo: {motivo}'],
        'transf.msg_gestor' => ['As responsabilidades de {de} passaram para {para} ({resumo}). Motivo: {motivo}', 'The responsibilities of {de} went to {para} ({resumo}). Reason: {motivo}', 'Las responsabilidades de {de} pasaron a {para} ({resumo}). Motivo: {motivo}'],
        'transf.msg_encerrada' => ['Transferência nº {n} ({resumo}) encerrada. Motivo: {motivo}', 'Transfer no. {n} ({resumo}) closed. Reason: {motivo}', 'Transferencia n.º {n} ({resumo}) cerrada. Motivo: {motivo}'],
        'pend.msg_registro' => ['{nome} desligou as pendências deste registro ({pilar}). Motivo: {motivo}', '{nome} turned off the pending items of this record ({pilar}). Reason: {motivo}', '{nome} desactivó las pendencias de este registro ({pilar}). Motivo: {motivo}'],
        'pend.msg_desligou_ate' => ['{nome} desligou as suas pendências até {data}. Motivo: {motivo}', '{nome} turned off your pending items until {data}. Reason: {motivo}', '{nome} desactivó sus pendencias hasta el {data}. Motivo: {motivo}'],
        'pend.msg_desligou' => ['{nome} desligou as suas pendências. Motivo: {motivo}', '{nome} turned off your pending items. Reason: {motivo}', '{nome} desactivó sus pendencias. Motivo: {motivo}'],
        'pend.msg_ocultada' => ['Ocultada por {nome}. Motivo: {motivo}', 'Hidden by {nome}. Reason: {motivo}', 'Ocultada por {nome}. Motivo: {motivo}'],
        'pend.msg_ocultadas' => ['Ocultadas por {nome}. Motivo: {motivo}', 'Hidden by {nome}. Reason: {motivo}', 'Ocultadas por {nome}. Motivo: {motivo}'],
    ];

    /** Frases fixas que podem aparecer no meio de uma mensagem (trocadas onde estiverem). */
    private const FRASES = [
        'fr.lei_revise' => ['Revise os requisitos e itens indicados (Módulo Legal › Reavaliação por mudança de lei).', 'Review the indicated requirements and items (Legal Module › Reassessment due to law change).', 'Revise los requisitos e ítems indicados (Módulo Legal › Reevaluación por cambio de ley).'],
        'fr.adulteracao_revise' => ['Revise o arquivo (a análise só aponta).', 'Review the file (the analysis only points things out).', 'Revise el archivo (el análisis solo señala).'],
        'fr.adulteracao_ciencia' => ['O documento só pode ser aprovado depois da ciência com o motivo.', 'The document can only be approved after the acknowledgement with the reason.', 'El documento solo puede aprobarse después de la toma de conocimiento con el motivo.'],
        'fr.modelo_revise' => ['Revise e decida (a IA só sugere).', 'Review and decide (the AI only suggests).', 'Revise y decida (la IA solo sugiere).'],
        'fr.ia_consumo' => ['Acompanhe o consumo em Administração › Consumo de IA no greenportal.', 'Follow the usage in Administration › AI usage in greenportal.', 'Acompañe el consumo en Administración › Consumo de IA en greenportal.'],
        'fr.ia_folga' => ['para terminar os trabalhos em andamento; depois os novos pedidos de IA serão bloqueados. Fale com o administrador para negociar o aumento do limite.', 'to finish the work in progress; after that new AI requests will be blocked. Talk to the administrator to negotiate a higher limit.', 'para terminar los trabajos en curso; después los nuevos pedidos de IA serán bloqueados. Hable con el administrador para negociar el aumento del límite.'],
        'fr.ia_bloqueados' => ['Os novos pedidos de IA estão bloqueados até o administrador aumentar o limite; as análises já pedidas continuam. Fale com o administrador para negociar o aumento.', 'New AI requests are blocked until the administrator raises the limit; analyses already requested continue. Talk to the administrator to negotiate the increase.', 'Los nuevos pedidos de IA están bloqueados hasta que el administrador aumente el límite; los análisis ya pedidos continúan. Hable con el administrador para negociar el aumento.'],
        'fr.sinc' => ['Nada foi gravado. Abra Administração › Migração › Sincronismo, confira o relatório da simulação e, se estiver certo, clique em Aplicar.', 'Nothing was saved. Open Administration › Migration › Synchronization, check the simulation report and, if it is right, click Apply.', 'No se guardó nada. Abra Administración › Migración › Sincronización, revise el informe de la simulación y, si está correcto, haga clic en Aplicar.'],
        'fr.risco_nada' => ['Nada foi gravado nos itens: aceite, ajuste ou recuse cada sugestão.', 'Nothing was saved in the items: accept, adjust or reject each suggestion.', 'No se guardó nada en los ítems: acepte, ajuste o rechace cada sugerencia.'],
        'fr.motivo' => ['Motivo:', 'Reason:', 'Motivo:'],
        'fr.detalhes_servicos' => ['Detalhes em Administração › Serviços.', 'Details in Administration › Services.', 'Detalles en Administración › Servicios.'],
    ];

    private static array $regex = [];

    /** Texto do alerta (título ou mensagem) no idioma pedido; sem modelo que sirva, devolve como está. */
    public static function traduzir(?string $texto, ?string $idioma = null): string
    {
        $texto = (string)$texto;
        $idioma = $idioma ?? IdiomaService::idioma();
        if ($texto === '' || !in_array($idioma, ['en', 'es'], true)) return $texto;
        if (!str_contains($texto, "\n")) return self::linha($texto, $idioma, 0) ?? self::frases($texto, $idioma);
        $linhas = preg_split('/(\r?\n)/', $texto, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$texto];
        foreach ($linhas as $i => $l) if ($i % 2 === 0 && trim($l) !== '') $linhas[$i] = self::linha($l, $idioma, 0) ?? self::frases($l, $idioma);
        return implode('', $linhas);
    }

    /** Itens para registrar no banco (scripts/aplicar-onda2-banco-idiomas.php). */
    public static function itensIdioma(): array
    {
        $itens = []; $o = 0;
        foreach (self::MODELOS as $id => [$pt, $en, $es]) $itens[] = ['chave' => 'alertas.' . str_replace('.', '_', $id), 'tipo' => 'TEXTO', 'ordem' => ++$o, 'descricao' => 'Modelo de alerta', 'pt' => $pt, 'en' => $en, 'es' => $es];
        foreach (self::FRASES as $id => [$pt, $en, $es]) $itens[] = ['chave' => 'alertas.' . str_replace('.', '_', $id), 'tipo' => 'TEXTO', 'ordem' => ++$o, 'descricao' => 'Frase de alerta', 'pt' => $pt, 'en' => $en, 'es' => $es];
        return $itens;
    }

    /** Linhas de AlertasService (titulo, mensagem) traduzidas. */
    public static function linhaAlerta(array $alerta, ?string $idioma = null): array
    {
        foreach (['titulo', 'mensagem', 'TITULO', 'MENSAGEM'] as $c) if (isset($alerta[$c]) && is_string($alerta[$c])) $alerta[$c] = self::traduzir($alerta[$c], $idioma);
        return $alerta;
    }

    private static function linha(string $texto, string $idioma, int $nivel): ?string
    {
        if ($nivel > 3) return null;
        $t = trim($texto);
        if ($t === '') return null;
        foreach (self::compilados() as $id => [$re, $nomes]) {
            if (!preg_match($re, $t, $m)) continue;
            $saida = self::modelo($id, $idioma);
            foreach ($nomes as $n) {
                $v = (string)($m[$n] ?? '');
                if (str_starts_with($n, 't_')) $v = self::linha($v, $idioma, $nivel + 1) ?? (isset(ExportacaoTextos::TIPOS[$v]) ? ExportacaoTextos::tipo($v, $idioma) : self::minuscula($v, $idioma, $nivel));
                elseif (str_starts_with($n, 'v_') && class_exists('NaoConformidadeTextos')) $v = self::comIdioma($idioma, static fn() => NaoConformidadeTextos::valor($v));
                else $v = self::valorLivre($v, $idioma);
                $saida = str_replace('{' . $n . '}', $v, $saida);
            }
            return $saida;
        }
        return null;
    }

    /** Lacuna livre: datas dd/mm/aaaa viram mm/dd/aaaa em inglês e "XLSX E PDF" vira "XLSX and PDF"/"XLSX y PDF". */
    private static function valorLivre(string $v, string $idioma): string
    {
        if ($idioma === 'pt') return $v;
        if ($idioma === 'en') $v = preg_replace('/\b(\d{2})\/(\d{2})\/(\d{4})\b/', '$2/$1/$3', $v) ?? $v;
        return preg_replace('/\b([A-Z]{2,5}) E ([A-Z]{2,5})\b/', '$1 ' . ($idioma === 'en' ? 'and' : 'y') . ' $2', $v) ?? $v;
    }

    /** "Sr. Fornecedor, favor avaliar…": a frase vem com minúscula; procura com maiúscula e devolve com minúscula. */
    private static function minuscula(string $v, string $idioma, int $nivel): string
    {
        $maiuscula = mb_strtoupper(mb_substr($v, 0, 1)) . mb_substr($v, 1);
        if ($maiuscula === $v) return $v;
        $r = self::linha($maiuscula, $idioma, $nivel + 1);
        return $r === null ? $v : mb_strtolower(mb_substr($r, 0, 1)) . mb_substr($r, 1);
    }

    /** Roda $fn com o idioma pedido (worker de e-mail: um destinatário por vez) e volta ao idioma anterior. */
    public static function comIdioma(string $idioma, callable $fn): mixed
    {
        $antes = IdiomaService::idioma();
        if ($antes === $idioma) return $fn();
        IdiomaService::usarIdioma($idioma);
        try { return $fn(); } finally { IdiomaService::usarIdioma($antes); }
    }

    private static function frases(string $texto, string $idioma): string
    {
        foreach (self::FRASES as $id => $d) if (str_contains($texto, $d[0])) $texto = str_replace($d[0], self::texto('alertas.' . $id, $d, $idioma), $texto);
        return $texto;
    }

    private static function modelo(string $id, string $idioma): string
    {
        return self::texto('alertas.' . str_replace('.', '_', $id), self::MODELOS[$id], $idioma);
    }

    private static function texto(string $chave, array $d, string $idioma): string
    {
        $chave = 'alertas.' . str_replace('.', '_', substr($chave, strlen('alertas.')));
        $t = IdiomaService::textos($chave, ['pt' => $d[0], 'en' => $d[1], 'es' => $d[2]]);
        return (string)($t[$idioma] ?? $d[$idioma === 'en' ? 1 : 2]);
    }

    /** id => [regex, nomes das lacunas] a partir do português (do modelo cadastrado, se houver). */
    private static function compilados(): array
    {
        if (self::$regex) return self::$regex;
        foreach (self::MODELOS as $id => $d) {
            $pt = (string)(IdiomaService::textos('alertas.' . str_replace('.', '_', $id), ['pt' => $d[0], 'en' => $d[1], 'es' => $d[2]])['pt'] ?? $d[0]);
            preg_match_all('/\{([a-z_]+)\}/', $pt, $mm);
            $partes = preg_split('/\{[a-z_]+\}/', $pt) ?: [$pt];
            $re = '';
            foreach ($partes as $i => $p) {
                $re .= preg_quote($p, '/');
                if (isset($mm[1][$i])) $re .= '(?P<' . $mm[1][$i] . '>.+?)';
            }
            self::$regex[$id] = ['/^' . $re . '$/u', $mm[1]]; // uma linha por vez (sem /s)
        }
        return self::$regex;
    }
}
