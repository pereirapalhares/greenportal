<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) { http_response_code(401); exit(IdiomaService::t('gestao_arquivos.erro.sessao', ['pt' => 'Sessão expirada.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.'])); }
// Textos em Administração › Textos e idiomas (grupo GESTAO_ARQUIVOS; cadastro automático ao abrir como ADMINISTRADOR).
$gTextosPadrao = [
    // Situações das versões e da extração (códigos gravados no banco; só o texto exibido muda).
    'codigo.ATIVA' => ['pt' => 'Ativa', 'en' => 'Active', 'es' => 'Activa'],
    'codigo.SUBSTITUIDA' => ['pt' => 'Substituída', 'en' => 'Superseded', 'es' => 'Sustituida'],
    'codigo.CANCELADA' => ['pt' => 'Cancelada', 'en' => 'Cancelled', 'es' => 'Cancelada'],
    'codigo.NAO_SOLICITADA' => ['pt' => 'Não solicitada', 'en' => 'Not requested', 'es' => 'No solicitada'],
    'codigo.PENDENTE' => ['pt' => 'Pendente', 'en' => 'Pending', 'es' => 'Pendiente'],
    'codigo.PROCESSANDO' => ['pt' => 'Processando', 'en' => 'Processing', 'es' => 'Procesando'],
    'codigo.CONCLUIDA' => ['pt' => 'Concluída', 'en' => 'Completed', 'es' => 'Concluida'],
    'codigo.ERRO' => ['pt' => 'Erro', 'en' => 'Error', 'es' => 'Error'],
    'codigo.UPLOAD' => ['pt' => 'Upload', 'en' => 'Upload', 'es' => 'Carga'],
    'codigo.IA' => ['pt' => 'IA', 'en' => 'AI', 'es' => 'IA'],
    'codigo.OCR' => ['pt' => 'OCR', 'en' => 'OCR', 'es' => 'OCR'],
    'aba.arquivos' => ['pt' => 'Lista de arquivos', 'en' => 'File list', 'es' => 'Lista de archivos'],
    'aba.versoes' => ['pt' => 'Histórico de versões', 'en' => 'Version history', 'es' => 'Historial de versiones'],
    'botao.enviar_dica' => ['pt' => 'Enviar arquivo', 'en' => 'Upload file', 'es' => 'Enviar archivo'],
    'botao.enviar' => ['pt' => 'Upload', 'en' => 'Upload', 'es' => 'Subir'],
    'botao.excluir_dica' => ['pt' => 'Excluir arquivo selecionado', 'en' => 'Delete selected file', 'es' => 'Eliminar archivo seleccionado'],
    'botao.excluir' => ['pt' => 'Excluir arquivo', 'en' => 'Delete file', 'es' => 'Eliminar archivo'],
    'coluna.nome' => ['pt' => 'Nome físico', 'en' => 'File name', 'es' => 'Nombre físico'],
    'coluna.criacao' => ['pt' => 'Data de criação', 'en' => 'Created on', 'es' => 'Fecha de creación'],
    'coluna.tamanho' => ['pt' => 'Tamanho', 'en' => 'Size', 'es' => 'Tamaño'],
    'msg.carregando' => ['pt' => 'Carregando...', 'en' => 'Loading...', 'es' => 'Cargando...'],
    'versao.versao' => ['pt' => 'Versão', 'en' => 'Version', 'es' => 'Versión'],
    'versao.status' => ['pt' => 'Status', 'en' => 'Status', 'es' => 'Estado'],
    'versao.validade' => ['pt' => 'Validade', 'en' => 'Expiry', 'es' => 'Validez'],
    'versao.extracao' => ['pt' => 'Extração', 'en' => 'Extraction', 'es' => 'Extracción'],
    'versao.confianca' => ['pt' => 'Confiança', 'en' => 'Confidence', 'es' => 'Confianza'],
    'versao.arquivo' => ['pt' => 'Arquivo', 'en' => 'File', 'es' => 'Archivo'],
    'previa.titulo' => ['pt' => 'Visualização do arquivo', 'en' => 'File preview', 'es' => 'Vista previa del archivo'],
    'baixar' => ['pt' => 'Baixar', 'en' => 'Download', 'es' => 'Descargar'],
    'dono.dica' => ['pt' => 'Excluir arquivo selecionado (você enviou este arquivo)', 'en' => 'Delete selected file (you uploaded this file)', 'es' => 'Eliminar archivo seleccionado (usted envió este archivo)'],
    'previa.selecione' => ['pt' => 'Selecione um arquivo na Lista de arquivos para visualizar seu conteúdo.', 'en' => 'Select a file in the File list to preview its content.', 'es' => 'Seleccione un archivo en la Lista de archivos para ver su contenido.'],
    'previa.sem_formato' => ['pt' => 'Este formato não possui visualização incorporada. Use o link do arquivo na Lista de arquivos para baixar o documento.', 'en' => 'This format has no built-in preview. Use the file link in the File list to download the document.', 'es' => 'Este formato no tiene vista previa integrada. Use el enlace del archivo en la Lista de archivos para descargar el documento.'],
    'msg.somente_consulta' => ['pt' => 'Registro encerrado: os arquivos ficam só para consulta e download. Não é possível enviar nem excluir arquivos.', 'en' => 'Closed record: files are read-only (view and download only). Uploading and deleting are not allowed.', 'es' => 'Registro cerrado: los archivos son solo de consulta y descarga. No es posible enviar ni eliminar archivos.'],
    'msg.nenhum_arquivo' => ['pt' => 'Nenhum arquivo carregado.', 'en' => 'No files uploaded.', 'es' => 'No hay archivos cargados.'],
    'msg.nenhuma_versao' => ['pt' => 'Nenhuma versão registrada.', 'en' => 'No versions recorded.', 'es' => 'No hay versiones registradas.'],
    'erro.carregar' => ['pt' => 'Não foi possível carregar os arquivos.', 'en' => 'Could not load the files.', 'es' => 'No fue posible cargar los archivos.'],
    'msg.upload_concluido' => ['pt' => 'Upload concluído.', 'en' => 'Upload completed.', 'es' => 'Carga concluida.'],
    'erro.enviar' => ['pt' => 'Não foi possível enviar o arquivo.', 'en' => 'Could not upload the file.', 'es' => 'No fue posible enviar el archivo.'],
    'confirmar.excluir' => ['pt' => 'Confirma a exclusão do arquivo selecionado?', 'en' => 'Delete the selected file?', 'es' => '¿Confirma la eliminación del archivo seleccionado?'],
    'erro.excluir' => ['pt' => 'Não foi possível excluir o arquivo.', 'en' => 'Could not delete the file.', 'es' => 'No fue posible eliminar el archivo.'],
    'ia.campo.descricao' => ['pt' => 'Descrição', 'en' => 'Description', 'es' => 'Descripción'],
    'ia.campo.validade' => ['pt' => 'Data de validade', 'en' => 'Expiry date', 'es' => 'Fecha de validez'],
    'ia.campo.atividade' => ['pt' => 'Atividade', 'en' => 'Activity', 'es' => 'Actividad'],
    'ia.campo.orgao' => ['pt' => 'Órgão emissor', 'en' => 'Issuing authority', 'es' => 'Órgano emisor'],
    'ia.campo.numero' => ['pt' => 'Número da licença', 'en' => 'License number', 'es' => 'Número de licencia'],
    'ia.campo.observacao' => ['pt' => 'Observação', 'en' => 'Notes', 'es' => 'Observación'],
    'ia.sem_alertas' => ['pt' => 'Nenhum alerta informado.', 'en' => 'No alerts reported.', 'es' => 'No se informaron alertas.'],
    'erro.gravar' => ['pt' => 'Não foi possível gravar.', 'en' => 'Could not save.', 'es' => 'No fue posible guardar.'],
    'erro.leitor_pdf' => ['pt' => 'Não foi possível carregar o leitor de PDF.', 'en' => 'Could not load the PDF reader.', 'es' => 'No fue posible cargar el lector de PDF.'],
    'erro.baixar_pdf' => ['pt' => 'Não foi possível baixar o PDF.', 'en' => 'Could not download the PDF.', 'es' => 'No fue posible descargar el PDF.'],
    'erro.pdf_sem_texto' => ['pt' => 'PDF sem texto selecionável. Use o processamento OCR.', 'en' => 'PDF has no selectable text. Use OCR processing.', 'es' => 'PDF sin texto seleccionable. Use el procesamiento OCR.'],
    'ia.extraindo' => ['pt' => 'Extraindo dados do documento...', 'en' => 'Extracting data from the document...', 'es' => 'Extrayendo datos del documento...'],
    'erro.arquivo_nao_localizado' => ['pt' => 'Arquivo não localizado.', 'en' => 'File not found.', 'es' => 'Archivo no encontrado.'],
    'erro.extracao' => ['pt' => 'A extração não foi concluída.', 'en' => 'The extraction was not completed.', 'es' => 'La extracción no se completó.'],
    'erro.ia_sem_sugestao' => ['pt' => 'A IA não retornou uma sugestão.', 'en' => 'The AI did not return a suggestion.', 'es' => 'La IA no devolvió una sugerencia.'],
    'ia.sugestao_gerada' => ['pt' => 'Sugestão gerada. Revise antes de confirmar.', 'en' => 'Suggestion generated. Review before confirming.', 'es' => 'Sugerencia generada. Revise antes de confirmar.'],
    'erro.analisar' => ['pt' => 'Não foi possível analisar o documento.', 'en' => 'Could not analyze the document.', 'es' => 'No fue posible analizar el documento.'],
    'msg.nova_versao' => ['pt' => 'Nova versão {versao} criada e enviada para processamento.', 'en' => 'New version {versao} created and sent for processing.', 'es' => 'Nueva versión {versao} creada y enviada para procesamiento.'],
    'ia.botao_dica' => ['pt' => 'Analisar documento com IA', 'en' => 'Analyze document with AI', 'es' => 'Analizar documento con IA'],
    'ia.botao' => ['pt' => 'Analisar com IA', 'en' => 'Analyze with AI', 'es' => 'Analizar con IA'],
    'ia.aviso' => ['pt' => 'A IA gerou uma sugestão. Revise os dados antes de confirmar.', 'en' => 'The AI generated a suggestion. Review the data before confirming.', 'es' => 'La IA generó una sugerencia. Revise los datos antes de confirmar.'],
    'ia.confianca' => ['pt' => 'Confiança', 'en' => 'Confidence', 'es' => 'Confianza'],
    'ia.alertas' => ['pt' => 'Alertas', 'en' => 'Alerts', 'es' => 'Alertas'],
    'ia.revisao_titulo' => ['pt' => 'Revisão dos dados extraídos', 'en' => 'Review of extracted data', 'es' => 'Revisión de los datos extraídos'],
    'botao.fechar' => ['pt' => 'Fechar', 'en' => 'Close', 'es' => 'Cerrar'],
    'botao.cancelar' => ['pt' => 'Cancelar', 'en' => 'Cancel', 'es' => 'Cancelar'],
    'botao.confirmar_gravar' => ['pt' => 'Confirmar e gravar', 'en' => 'Confirm and save', 'es' => 'Confirmar y guardar'],
    // Análise por IA dos documentos de funcionários (ASO e certificados de treinamento).
    'dia.revisao_titulo' => ['pt' => 'Revisão do documento do fornecedor', 'en' => 'Supplier document review', 'es' => 'Revisión del documento del proveedor'],
    'dia.aviso' => ['pt' => 'Confira os dados lidos pela IA. Nada é gravado no documento do fornecedor sem a sua confirmação.', 'en' => 'Check the data read by the AI. Nothing is saved to the supplier document without your confirmation.', 'es' => 'Revise los datos leídos por la IA. Nada se guarda en el documento del proveedor sin su confirmación.'],
    'dia.analisando' => ['pt' => 'Lendo o documento e analisando com IA. Pode levar até 1 minuto…', 'en' => 'Reading the document and analyzing with AI. It may take up to 1 minute…', 'es' => 'Leyendo el documento y analizando con IA. Puede tardar hasta 1 minuto…'],
    'dia.erro' => ['pt' => 'Não foi possível analisar o documento.', 'en' => 'Could not analyze the document.', 'es' => 'No fue posible analizar el documento.'],
    'dia.cadastro' => ['pt' => 'Exigido', 'en' => 'Required', 'es' => 'Exigido'],
    'dia.lido' => ['pt' => 'Lido no arquivo', 'en' => 'Read from file', 'es' => 'Leído en el archivo'],
    'dia.tipo' => ['pt' => 'Tipo do documento', 'en' => 'Document type', 'es' => 'Tipo de documento'],
    'dia.corresponde' => ['pt' => 'Corresponde ao exigido?', 'en' => 'Matches the required document?', 'es' => '¿Corresponde al exigido?'],
    'dia.corresponde.SIM' => ['pt' => 'Sim', 'en' => 'Yes', 'es' => 'Sí'],
    'dia.corresponde.NAO' => ['pt' => 'Não', 'en' => 'No', 'es' => 'No'],
    'dia.corresponde.INCERTO' => ['pt' => 'Incerto', 'en' => 'Uncertain', 'es' => 'Incierto'],
    'dia.numero' => ['pt' => 'Número', 'en' => 'Number', 'es' => 'Número'],
    'dia.orgao' => ['pt' => 'Órgão emissor', 'en' => 'Issuer', 'es' => 'Órgano emisor'],
    'dia.razao' => ['pt' => 'Razão social / CNPJ no documento', 'en' => 'Company name / CNPJ on document', 'es' => 'Razón social / CNPJ en el documento'],
    'dia.emissao' => ['pt' => 'Emissão', 'en' => 'Issue date', 'es' => 'Emisión'],
    'dia.resumo' => ['pt' => 'Resumo', 'en' => 'Summary', 'es' => 'Resumen'],
    'dia.atualizar' => ['pt' => 'Atualizar o documento do fornecedor com estes dados', 'en' => 'Update the supplier document with this data', 'es' => 'Actualizar el documento del proveedor con estos datos'],
    'dia.validade' => ['pt' => 'Data de validade', 'en' => 'Expiration date', 'es' => 'Fecha de validez'],
    'dia.situacao' => ['pt' => 'Situação do documento', 'en' => 'Document status', 'es' => 'Situación del documento'],
    'dia.enviado' => ['pt' => 'Documento enviado', 'en' => 'Document sent', 'es' => 'Documento enviado'],
    'dia.observacao' => ['pt' => 'Obs. da empresa', 'en' => 'Company notes', 'es' => 'Obs. de la empresa'],
    'dia.atual' => ['pt' => 'atual', 'en' => 'current', 'es' => 'actual'],
    'dia.sugerida' => ['pt' => 'sugerida pela leitura', 'en' => 'suggested by the reading', 'es' => 'sugerida por la lectura'],
    'dfu.revisao_titulo' => ['pt' => 'Revisão do documento do funcionário', 'en' => 'Employee document review', 'es' => 'Revisión del documento del empleado'],
    'dfu.aviso' => ['pt' => 'Confira os dados lidos pela IA. Nada é gravado no critério do funcionário sem a sua confirmação. Diagnóstico, CID e resultados de exames não são enviados à IA.', 'en' => 'Check the data read by the AI. Nothing is saved to the employee criterion without your confirmation. Diagnosis, ICD and exam results are not sent to the AI.', 'es' => 'Revise los datos leídos por la IA. Nada se guarda en el criterio del empleado sin su confirmación. Diagnóstico, CIE y resultados de exámenes no se envían a la IA.'],
    'dfu.exigido' => ['pt' => 'Exigido', 'en' => 'Required', 'es' => 'Exigido'],
    'dfu.atualizar' => ['pt' => 'Atualizar o critério do funcionário com estes dados', 'en' => 'Update the employee criterion with this data', 'es' => 'Actualizar el criterio del empleado con estos datos'],
    'dfu.atende' => ['pt' => 'Atende ao critério?', 'en' => 'Meets the criterion?', 'es' => '¿Cumple el criterio?'],
    'dfu.situacao' => ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación'],
    'dfu.situacao_manter' => ['pt' => 'Não alterar', 'en' => 'Do not change', 'es' => 'No modificar'],
    'dfu.situacao_nenhuma' => ['pt' => 'Sem pendência', 'en' => 'No pending item', 'es' => 'Sin pendiente'],
    'dfu.observacao' => ['pt' => 'Observações', 'en' => 'Notes', 'es' => 'Observaciones'],
    'fia.revisao_titulo' => ['pt' => 'Revisão do documento do funcionário', 'en' => 'Employee document review', 'es' => 'Revisión del documento del empleado'],
    'fia.aviso' => ['pt' => 'Confira os dados lidos pela IA. Nada é gravado sem a sua confirmação. Diagnóstico, CID e resultados de exames não são enviados à IA.', 'en' => 'Check the data read by the AI. Nothing is saved without your confirmation. Diagnosis, ICD codes and exam results are not sent to the AI.', 'es' => 'Revise los datos leídos por la IA. Nada se guarda sin su confirmación. Diagnóstico, CIE y resultados de exámenes no se envían a la IA.'],
    'fia.analisando' => ['pt' => 'Lendo o documento e analisando com IA. Pode levar até 1 minuto…', 'en' => 'Reading the document and analyzing with AI. It may take up to 1 minute…', 'es' => 'Leyendo el documento y analizando con IA. Puede tardar hasta 1 minuto…'],
    'fia.tipo' => ['pt' => 'Tipo de documento', 'en' => 'Document type', 'es' => 'Tipo de documento'],
    'fia.tipo.ASO' => ['pt' => 'ASO (Atestado de Saúde Ocupacional)', 'en' => 'ASO (occupational health certificate)', 'es' => 'ASO (certificado de salud ocupacional)'],
    'fia.tipo.TREINAMENTO' => ['pt' => 'Certificado de treinamento', 'en' => 'Training certificate', 'es' => 'Certificado de capacitación'],
    'fia.tipo.OUTRO' => ['pt' => 'Outro documento', 'en' => 'Other document', 'es' => 'Otro documento'],
    'fia.cadastro' => ['pt' => 'Cadastro', 'en' => 'Record', 'es' => 'Registro'],
    'fia.documento' => ['pt' => 'Documento', 'en' => 'Document', 'es' => 'Documento'],
    'fia.nome' => ['pt' => 'Nome', 'en' => 'Name', 'es' => 'Nombre'],
    'fia.cpf' => ['pt' => 'CPF', 'en' => 'CPF', 'es' => 'CPF'],
    'fia.aso_tipo_exame' => ['pt' => 'Tipo de exame', 'en' => 'Exam type', 'es' => 'Tipo de examen'],
    'fia.exame.ADMISSIONAL' => ['pt' => 'Admissional', 'en' => 'Pre-employment', 'es' => 'De ingreso'],
    'fia.exame.PERIODICO' => ['pt' => 'Periódico', 'en' => 'Periodic', 'es' => 'Periódico'],
    'fia.exame.RETORNO_AO_TRABALHO' => ['pt' => 'Retorno ao trabalho', 'en' => 'Return to work', 'es' => 'Retorno al trabajo'],
    'fia.exame.MUDANCA_DE_RISCO' => ['pt' => 'Mudança de risco ocupacional', 'en' => 'Change of occupational risk', 'es' => 'Cambio de riesgo ocupacional'],
    'fia.exame.DEMISSIONAL' => ['pt' => 'Demissional', 'en' => 'Termination', 'es' => 'De egreso'],
    'fia.aso_resultado' => ['pt' => 'Resultado', 'en' => 'Result', 'es' => 'Resultado'],
    'fia.resultado.APTO' => ['pt' => 'Apto', 'en' => 'Fit', 'es' => 'Apto'],
    'fia.resultado.APTO_COM_RESTRICAO' => ['pt' => 'Apto com restrição', 'en' => 'Fit with restriction', 'es' => 'Apto con restricción'],
    'fia.resultado.INAPTO' => ['pt' => 'Inapto', 'en' => 'Unfit', 'es' => 'No apto'],
    'fia.aso_data_exame' => ['pt' => 'Data do exame', 'en' => 'Exam date', 'es' => 'Fecha del examen'],
    'fia.validade' => ['pt' => 'Validade', 'en' => 'Valid until', 'es' => 'Vigencia'],
    'fia.aso_medico' => ['pt' => 'Médico', 'en' => 'Physician', 'es' => 'Médico'],
    'fia.aso_crm' => ['pt' => 'CRM', 'en' => 'Medical license', 'es' => 'Matrícula médica'],
    'fia.treinamento_nome' => ['pt' => 'Treinamento', 'en' => 'Training', 'es' => 'Capacitación'],
    'fia.treinamento_norma' => ['pt' => 'Norma', 'en' => 'Standard', 'es' => 'Norma'],
    'fia.treinamento_carga_horaria' => ['pt' => 'Carga horária (h)', 'en' => 'Workload (h)', 'es' => 'Carga horaria (h)'],
    'fia.treinamento_data_realizacao' => ['pt' => 'Data de realização', 'en' => 'Completion date', 'es' => 'Fecha de realización'],
    'fia.treinamento_instituicao' => ['pt' => 'Instituição', 'en' => 'Institution', 'es' => 'Institución'],
    'fia.destino_criterio' => ['pt' => 'Atualizar o critério do funcionário', 'en' => 'Update the employee criterion', 'es' => 'Actualizar el criterio del empleado'],
    'fia.destino_treinamento' => ['pt' => 'Atualizar o treinamento do funcionário', 'en' => 'Update the employee training', 'es' => 'Actualizar la capacitación del empleado'],
    'fia.destino_nenhum' => ['pt' => '— Só guardar a análise, sem atualizar —', 'en' => '— Only store the analysis, no update —', 'es' => '— Solo guardar el análisis, sin actualizar —'],
    'fia.destino_atual' => ['pt' => 'atual', 'en' => 'current', 'es' => 'actual'],
    'fia.destino_dica' => ['pt' => 'Ao confirmar, a validade e a situação deste item passam a ser as do documento e não são sobrescritas pelo sincronismo com o sistema antigo.', 'en' => 'On confirmation, the validity and status of this item become those of the document and are not overwritten by the legacy sync.', 'es' => 'Al confirmar, la vigencia y la situación de este ítem pasan a ser las del documento y no las sobrescribe la sincronización con el sistema antiguo.'],
    'fia.fonte' => ['pt' => 'Leitura', 'en' => 'Reading', 'es' => 'Lectura'],
    'fia.fonte.PDF_TEXTO' => ['pt' => 'PDF com texto', 'en' => 'PDF with text', 'es' => 'PDF con texto'],
    'fia.fonte.OCR_PDF' => ['pt' => 'OCR de PDF digitalizado', 'en' => 'OCR of scanned PDF', 'es' => 'OCR de PDF escaneado'],
    'fia.fonte.OCR_IMAGEM' => ['pt' => 'OCR de imagem', 'en' => 'Image OCR', 'es' => 'OCR de imagen'],
    'fia.fonte.DOCX_TEXTO' => ['pt' => 'Documento Word', 'en' => 'Word document', 'es' => 'Documento Word'],
    'fia.botao_descartar' => ['pt' => 'Descartar análise', 'en' => 'Discard analysis', 'es' => 'Descartar análisis'],
    'fia.erro' => ['pt' => 'Não foi possível analisar o documento.', 'en' => 'Could not analyze the document.', 'es' => 'No fue posible analizar el documento.'],
    // Aba "Análises de IA" (histórico de análises, para todos os tipos de arquivo).
    'aba.analises' => ['pt' => 'Análises de IA', 'en' => 'AI analyses', 'es' => 'Análisis de IA'],
    'analises.arquivo' => ['pt' => 'Arquivo selecionado', 'en' => 'Selected file', 'es' => 'Archivo seleccionado'],
    'analises.todos' => ['pt' => 'Todos os arquivos', 'en' => 'All files', 'es' => 'Todos los archivos'],
    'analises.atualizar' => ['pt' => 'Atualizar', 'en' => 'Refresh', 'es' => 'Actualizar'],
    'analises.selecione' => ['pt' => 'Selecione um arquivo na Lista de arquivos ou escolha "Todos os arquivos".', 'en' => 'Select a file in the File list or choose "All files".', 'es' => 'Seleccione un archivo en la Lista de archivos o elija "Todos los archivos".'],
    'analises.nenhuma' => ['pt' => 'Nenhuma análise de IA registrada.', 'en' => 'No AI analysis recorded.', 'es' => 'Ningún análisis de IA registrado.'],
    'analises.col.data' => ['pt' => 'Data', 'en' => 'Date', 'es' => 'Fecha'],
    'analises.col.arquivo' => ['pt' => 'Arquivo', 'en' => 'File', 'es' => 'Archivo'],
    'analises.col.situacao' => ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación'],
    'analises.col.resultado' => ['pt' => 'Resultado', 'en' => 'Result', 'es' => 'Resultado'],
    'analises.col.confianca' => ['pt' => 'Confiança', 'en' => 'Confidence', 'es' => 'Confianza'],
    'analises.col.usuario' => ['pt' => 'Usuário', 'en' => 'User', 'es' => 'Usuario'],
    'analises.detalhes' => ['pt' => 'Clique na linha para ver os dados extraídos na aba Revisão dos dados (análises ainda não confirmadas abrem para revisão e confirmação).', 'en' => 'Click a row to see the extracted data in the Data review tab (unconfirmed analyses open for review and confirmation).', 'es' => 'Haga clic en la fila para ver los datos extraídos en la pestaña Revisión de los datos (los análisis no confirmados se abren para revisión y confirmación).'],
    'analises.dados_extraidos' => ['pt' => 'Dados extraídos pela IA', 'en' => 'Data extracted by AI', 'es' => 'Datos extraídos por la IA'],
    'analises.confirmado_por' => ['pt' => 'Confirmado/descartado por', 'en' => 'Confirmed/discarded by', 'es' => 'Confirmado/descartado por'],
    'codigo.CONFIRMADA' => ['pt' => 'Confirmada', 'en' => 'Confirmed', 'es' => 'Confirmada'],
    'codigo.DESCARTADA' => ['pt' => 'Descartada', 'en' => 'Discarded', 'es' => 'Descartada'],
    'codigo.PDF_TEXTO' => ['pt' => 'PDF com texto', 'en' => 'PDF with text', 'es' => 'PDF con texto'],
    'codigo.OCR_PDF' => ['pt' => 'OCR de PDF', 'en' => 'PDF OCR', 'es' => 'OCR de PDF'],
    'codigo.OCR_IMAGEM' => ['pt' => 'OCR de imagem', 'en' => 'Image OCR', 'es' => 'OCR de imagen'],
    'codigo.DOCX_TEXTO' => ['pt' => 'Documento Word', 'en' => 'Word document', 'es' => 'Documento Word'],
    'zoom.mais' => ['pt' => 'Aumentar zoom', 'en' => 'Zoom in', 'es' => 'Aumentar zoom'],
    'zoom.menos' => ['pt' => 'Diminuir zoom', 'en' => 'Zoom out', 'es' => 'Reducir zoom'],
    'zoom.ajustar' => ['pt' => 'Ajustar à tela (100%)', 'en' => 'Fit to screen (100%)', 'es' => 'Ajustar a la pantalla (100%)'],
    'aba.revisao' => ['pt' => 'Revisão dos dados', 'en' => 'Data review', 'es' => 'Revisión de los datos'],
    'revisao.vazia' => ['pt' => 'Nenhuma análise em revisão. Analise um arquivo com IA ou escolha uma análise concluída na aba Análises de IA.', 'en' => 'No analysis under review. Analyze a file with AI or pick a completed analysis in the AI analyses tab.', 'es' => 'Ningún análisis en revisión. Analice un archivo con IA o elija un análisis concluido en la pestaña Análisis de IA.'],
    'analises.revisar' => ['pt' => 'Revisar e confirmar', 'en' => 'Review and confirm', 'es' => 'Revisar y confirmar'],
    // Controle de duplicatas no envio à IA.
    'ia.ja_analisado' => ['pt' => 'Já analisado pela IA', 'en' => 'Already analyzed by AI', 'es' => 'Ya analizado por la IA'],
    'ia.ver_analise' => ['pt' => 'Ver a análise de IA deste documento (já analisado; não é enviado de novo)', 'en' => 'View the AI analysis of this document (already analyzed; not sent again)', 'es' => 'Ver el análisis de IA de este documento (ya analizado; no se envía de nuevo)'],
    'ia.analisar_novamente' => ['pt' => 'Analisar novamente', 'en' => 'Analyze again', 'es' => 'Analizar nuevamente'],
    'ia.confirmar_reanalise' => ['pt' => 'Este documento já foi analisado. Enviar de novo à IA? (uma nova análise será registrada)', 'en' => 'This document was already analyzed. Send it to the AI again? (a new analysis will be recorded)', 'es' => 'Este documento ya fue analizado. ¿Enviarlo de nuevo a la IA? (se registrará un nuevo análisis)'],
    'ia.reaproveitada' => ['pt' => 'Leitura reaproveitada de análise anterior do mesmo documento (sem novo envio à IA). Revise antes de confirmar.', 'en' => 'Reading reused from a previous analysis of the same document (not sent to the AI again). Review before confirming.', 'es' => 'Lectura reaprovechada de un análisis anterior del mismo documento (sin nuevo envío a la IA). Revise antes de confirmar.'],
    // Análise por IA das evidências do plano de ação da condicionante (migração 308).
    'pia.revisao_titulo' => ['pt' => 'Aprovação dos dados do plano de ação', 'en' => 'Action plan data approval', 'es' => 'Aprobación de los datos del plan de acción'],
    'pia.aviso' => ['pt' => 'A IA leu a evidência e sugere os campos abaixo. Marque o que deve ser gravado no plano de ação e confirme; nada é gravado sem a sua aprovação.', 'en' => 'The AI read the evidence and suggests the fields below. Check what should be saved to the action plan and confirm; nothing is saved without your approval.', 'es' => 'La IA leyó la evidencia y sugiere los campos abajo. Marque lo que debe guardarse en el plan de acción y confirme; nada se guarda sin su aprobación.'],
    'pia.condicionante' => ['pt' => 'Condicionante', 'en' => 'Condition', 'es' => 'Condicionante'],
    'pia.licenca' => ['pt' => 'Licença', 'en' => 'License', 'es' => 'Licencia'],
    'pia.lido' => ['pt' => 'Documento lido', 'en' => 'Document read', 'es' => 'Documento leído'],
    'pia.corresponde' => ['pt' => 'é evidência da condicionante?', 'en' => 'is it evidence of the condition?', 'es' => '¿es evidencia de la condicionante?'],
    'pia.corresponde.SIM' => ['pt' => 'Sim', 'en' => 'Yes', 'es' => 'Sí'],
    'pia.corresponde.NAO' => ['pt' => 'Não', 'en' => 'No', 'es' => 'No'],
    'pia.corresponde.INCERTO' => ['pt' => 'Incerto', 'en' => 'Uncertain', 'es' => 'Incierto'],
    'pia.resumo' => ['pt' => 'Resumo', 'en' => 'Summary', 'es' => 'Resumen'],
    'pia.campo' => ['pt' => 'Campo', 'en' => 'Field', 'es' => 'Campo'],
    'pia.atual' => ['pt' => 'Atual', 'en' => 'Current', 'es' => 'Actual'],
    'pia.sugerido' => ['pt' => 'Sugerido pela IA (pode ajustar)', 'en' => 'Suggested by AI (you can adjust)', 'es' => 'Sugerido por la IA (puede ajustar)'],
    'pia.acao' => ['pt' => 'Ação', 'en' => 'Action', 'es' => 'Acción'],
    'pia.responsavel' => ['pt' => 'Responsável', 'en' => 'Responsible', 'es' => 'Responsable'],
    'pia.data_inicial' => ['pt' => 'Data inicial', 'en' => 'Start date', 'es' => 'Fecha inicial'],
    'pia.data_final' => ['pt' => 'Data final', 'en' => 'End date', 'es' => 'Fecha final'],
    'pia.recursos' => ['pt' => 'Recursos', 'en' => 'Resources', 'es' => 'Recursos'],
    'pia.status' => ['pt' => 'Status', 'en' => 'Status', 'es' => 'Estado'],
    'pia.nao_encontrado' => ['pt' => 'não encontrado entre os usuários', 'en' => 'not found among users', 'es' => 'no encontrado entre los usuarios'],
    'pia.nada_novo' => ['pt' => 'A IA não encontrou no documento informação nova para o plano de ação. Você pode ajustar e marcar os campos, ou descartar.', 'en' => 'The AI found no new information for the action plan in the document. You can adjust and check fields, or discard.', 'es' => 'La IA no encontró en el documento información nueva para el plan de acción. Puede ajustar y marcar los campos, o descartar.'],
    'pia.lendo' => ['pt' => 'Lendo a evidência (OCR)...', 'en' => 'Reading the evidence (OCR)...', 'es' => 'Leyendo la evidencia (OCR)...'],
    'pia.analisando' => ['pt' => 'A IA está analisando a evidência...', 'en' => 'The AI is analyzing the evidence...', 'es' => 'La IA está analizando la evidencia...'],
    'pia.nenhum_marcado' => ['pt' => 'Nenhum campo marcado: a análise será confirmada sem alterar o plano de ação.', 'en' => 'No field checked: the analysis will be confirmed without changing the action plan.', 'es' => 'Ningún campo marcado: el análisis se confirmará sin modificar el plan de acción.'],
    // Migração 347: parecer pelo modelo do documento (Homologação › Critérios › Modelo do documento)
    'mod.titulo' => ['pt' => 'Conferência pelo modelo do documento', 'en' => 'Check against the document template', 'es' => 'Revisión por el modelo del documento'],
    'mod.obrigatorio' => ['pt' => 'obrigatório', 'en' => 'required', 'es' => 'obligatorio'],
    'mod.recomendado' => ['pt' => 'recomendado', 'en' => 'recommended', 'es' => 'recomendado'],
    'mod.regra' => ['pt' => 'regra', 'en' => 'rule', 'es' => 'regla'],
    'mod.SIM' => ['pt' => 'Atende', 'en' => 'Meets', 'es' => 'Cumple'],
    'mod.NAO' => ['pt' => 'Não atende', 'en' => 'Does not meet', 'es' => 'No cumple'],
    'mod.INCERTO' => ['pt' => 'Conferir', 'en' => 'Check', 'es' => 'Revisar'],
    'mod.res.CONFORME' => ['pt' => 'Conforme o modelo', 'en' => 'Meets the template', 'es' => 'Conforme al modelo'],
    'mod.res.NAO_CONFORME' => ['pt' => 'Fora do modelo', 'en' => 'Off template', 'es' => 'Fuera del modelo'],
    'mod.res.INCERTO' => ['pt' => 'Conferir no arquivo', 'en' => 'Check the file', 'es' => 'Revisar en el archivo'],
    'mod.decisao' => ['pt' => 'A IA só sugere: a decisão (aprovar, reprovar ou devolver ao fornecedor) é sua.', 'en' => 'The AI only suggests: the decision (approve, reject or return to the supplier) is yours.', 'es' => 'La IA solo sugiere: la decisión (aprobar, rechazar o devolver al proveedor) es suya.'],
];
$h = static fn(string $chave): string => htmlspecialchars(IdiomaService::t('gestao_arquivos.' . $chave, $gTextosPadrao[$chave] ?? ''), ENT_QUOTES, 'UTF-8');
require_once dirname(__DIR__) . '/classes/GedPermissaoTipo.php';$gedTipoPermissao=strtolower(trim((string)($_GET['tipo']??'')));$gedPermission=GedPermissaoTipo::obter($gedTipoPermissao);$gedPdo=TransactionSecurity::connect(GedPermissaoTipo::transacao($gedTipoPermissao),GedPermissaoTipo::acao($gedTipoPermissao));if(empty($gedPermission['pode_visualizar'])){http_response_code(403);exit(IdiomaService::t('gestao_arquivos.erro.sem_permissao', ['pt' => 'Sem permissão para visualizar arquivos.', 'en' => 'Not allowed to view files.', 'es' => 'Sin permiso para ver archivos.']));}
require_once dirname(__DIR__) . '/classes/GedAnaliseIaRegistro.php';$podeReanalisar=GedAnaliseIaRegistro::podeReanalisar($_SESSION);$gedCsrf=(string)($_SESSION['gestao_arquivos_csrf']??'');if($gedCsrf===''){$gedCsrf=bin2hex(random_bytes(32));$_SESSION['gestao_arquivos_csrf']=$gedCsrf;}
$tipo=strtolower(trim((string)($_GET['tipo']??'')));$chave=trim((string)($_GET['chave']??''));$valor=max(0,(int)($_GET['valor']??0));$iaPermissionCode=['licencas'=>'LICENCAS','controles'=>'CONTROLE_DOCUMENTOS','infraestrutura'=>'MODULOLEGAL_INFRAESTRUTURA','treinamentos'=>'MODULOLEGAL_TREINAMENTOS','funcionarios'=>'HOMOLOGACAO_FORNECEDOR_FUNCIONARIO','fornecedorcriterios'=>'PORTARIA_DOCUMENTACAO_FORNECEDOR','funcionariocriterios'=>'PORTARIA_DOCUMENTACAO_FUNCIONARIO','planodeacao'=>'LICENCAS'][$tipo]??'';$iaPermission=$iaPermissionCode!==''?PermissaoTransacao::obter($iaPermissionCode):[];$canAnalyze=$iaPermissionCode!==''&&!empty($iaPermission['pode_editar']);$labels=['controles'=>['pt'=>'Arquivos do controle','en'=>'Control files','es'=>'Archivos del control'],'documentos'=>['pt'=>'Documentos do controle','en'=>'Control documents','es'=>'Documentos del control'],'licencas'=>['pt'=>'Arquivos da licença','en'=>'License files','es'=>'Archivos de la licencia'],'treinamentos'=>['pt'=>'Arquivos do treinamento','en'=>'Training files','es'=>'Archivos de la capacitación'],'infraestrutura'=>['pt'=>'Arquivos da infraestrutura','en'=>'Infrastructure files','es'=>'Archivos de la infraestructura'],'funcionarios'=>['pt'=>'Anexos do funcionário','en'=>'Employee attachments','es'=>'Anexos del empleado'],'fornecedorcriterios'=>['pt'=>'Evidências do documento do fornecedor','en'=>'Supplier document evidence','es'=>'Evidencias del documento del proveedor'],'funcionariocriterios'=>['pt'=>'Evidências do critério do funcionário','en'=>'Employee criterion evidence','es'=>'Evidencias del criterio del empleado'],'planodeacao'=>['pt'=>'Evidências do plano de ação','en'=>'Action plan evidence','es'=>'Evidencias del plan de acción'],'licencataxas'=>['pt'=>'Comprovante da taxa da licença','en'=>'License fee receipt','es'=>'Comprobante de la tasa de la licencia'],'funcionariotreinamentos'=>['pt'=>'Evidências do treinamento do funcionário','en'=>'Employee training evidence','es'=>'Evidencias de la capacitación del empleado'],'fornecedorcertificados'=>['pt'=>'Arquivos do certificado do fornecedor','en'=>'Supplier certificate files','es'=>'Archivos del certificado del proveedor'],'fornecedorquimicos'=>['pt'=>'FDS e arquivos do produto químico','en'=>'SDS and chemical product files','es'=>'FDS y archivos del producto químico'],'fornecedorequipamentos'=>['pt'=>'Arquivos do equipamento do fornecedor','en'=>'Supplier equipment files','es'=>'Archivos del equipo del proveedor'],'fornecedorplanos'=>['pt'=>'Arquivos do plano de ação do fornecedor','en'=>'Supplier action plan files','es'=>'Archivos del plan de acción del proveedor'],'fornecedorauditoria'=>['pt'=>'Evidências da auditoria de homologação','en'=>'Approval audit evidence','es'=>'Evidencias de la auditoría de homologación'],'ncocorrencia'=>['pt'=>'Anexos da não conformidade','en'=>'Nonconformity attachments','es'=>'Anexos de la no conformidad'],'ncanalise'=>['pt'=>'Evidências da análise da causa','en'=>'Root cause analysis evidence','es'=>'Evidencias del análisis de la causa'],'ncplano'=>['pt'=>'Arquivos da ação do plano','en'=>'Action plan item files','es'=>'Archivos de la acción del plan']];$titulo=isset($labels[$tipo])?IdiomaService::t('gestao_arquivos.titulo.'.$tipo,$labels[$tipo]):IdiomaService::t('gestao_arquivos.titulo.geral',['pt'=>'Gestão de arquivos','en'=>'File management','es'=>'Gestión de archivos']);
// Título da janela: <Unidade> - <Título do documento> - <Responsável> - <Vencimento> (as partes que o registro tiver),
// lido por sp_crud_select na view do grid (licenças) ou na tabela do registro; sem registro, fica o título do tipo.
$gedTituloJanela = (static function (PDO $pdo, string $tipo, string $chave, int $valor, string $padrao): string {
    $fontes = ['licencas' => [['vw_licencas_grid', 'CODLICENCA'], ['li_licencas', 'CODLICENCA']], 'controles' => [['documentos', 'CODDOCUMENTO']], 'documentos' => [['documentos', 'CODDOCUMENTO']],
        'treinamentos' => [['vw_modulolegal_treinamentos_grid', 'CODTREINAMENTO'], ['ml_treinamentos', 'CODTREINAMENTO']], 'infraestrutura' => [['ml_infraestrutura', 'CODINFRAESTRUTURA']],
        'planodeacao' => [['li_planodeacao', 'CODPLANOACAO']], 'licencataxas' => [['vw_li_taxas_grid', 'CODTAXA']], 'funcionarios' => [['fo_funcionarios', 'CODFUNCIONARIO']], 'fornecedorcriterios' => [['fo_fornecedorxcriterios', 'CODFORNECEDORXCRITERIOS']],
        'funcionariocriterios' => [['fo_funcionarioxtiposxcriterios', 'CODIGO']], 'funcionariotreinamentos' => [['fo_funcionarioxtiposxtreinamentos', 'CODIGO']],
        'fornecedorcertificados' => [['fo_certificados', 'CODCERTIFICADO']], 'fornecedorquimicos' => [['fo_produtoquimico', 'CODPRODUTO']], 'fornecedorequipamentos' => [['fo_equipamentos', 'CODEQUIPAMENTO']],
        'fornecedorplanos' => [['fo_planodeacao', 'CODPLANOACAO']], 'fornecedorauditoria' => [['fo_fornecedoresxtipofornecedores', 'CODFORNECEDORXTIPOFORNECEDORES']],
        'ncocorrencia' => [['vw_nc_ocorrencia', 'CODOCORRENCIA']], 'ncanalise' => [['vw_nc_ocorrencia', 'CODOCORRENCIA']], 'ncplano' => [['vw_nc_planodeacao', 'CODPLANOACAO']]];
    if ($valor <= 0 || !isset($fontes[$tipo])) return $padrao;
    $ler = static function (string $view, array $filtros) use ($pdo): ?array {
        try { $st = $pdo->prepare('CALL sp_crud_select(:t, :f, NULL, 1, 0)'); $st->execute(['t' => $view, 'f' => json_encode($filtros)]); $r = $st->fetchAll(PDO::FETCH_ASSOC); $st->closeCursor(); return $r[0] ?? null; }
        catch (Throwable) { return null; }
    };
    $linha = null;
    foreach ($fontes[$tipo] as [$view, $campo]) { $linha = $ler($view, [$campo => $valor]); if ($linha) break; }
    if (!$linha) return $padrao;
    $campo = static function (array $nomes) use ($linha) { foreach ($linha as $k => $v) foreach ($nomes as $n) if (strcasecmp((string)$k, $n) === 0 && trim((string)$v) !== '') return trim((string)$v); return ''; };
    $unidade = $campo(['DSCUNIDADE', 'UNIDADE']);
    if ($unidade === '' && ($cu = $campo(['CODUNIDADE'])) !== '') $unidade = (string)(($ler('vw_unidades_grid', ['CODUNIDADE' => (int)$cu]) ?? [])['DSCUNIDADE'] ?? '');
    $titulo = $campo(['DSCLICENCA', 'DSCDOCUMENTO', 'DSCTREINAMENTO', 'DSCINFRAESTRUTURA', 'IDENTIFICACAO', 'DESCRICAO', 'ACAO', 'NOME', 'DSCCRITERIO', 'TITULO']);
    $resp = $campo(['RESPONSAVEL', 'NOME_RESPONSAVEL']);
    if ($resp === '' && ($cr = $campo(['CODUSUARIO', 'CODUSUARIO_RESPONSAVEL'])) !== '') { $u = $ler('vw_usuarios_grid', ['codusuario' => (int)$cr]) ?? []; $resp = trim((string)($u['nome'] ?? $u['NOME'] ?? '')); }
    $venc = $campo(['DATA_VALIDADE', 'VALIDADE', 'DATA_VENCIMENTO', 'DATAFINAL', 'DATA_VALIDADE_DOCUMENTO']);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $venc, $m)) $venc = $m[0] === '0000-00-00' ? '' : $m[3] . '/' . $m[2] . '/' . $m[1];
    $partes = array_values(array_filter([$unidade, $titulo !== '' ? $titulo : $padrao, $resp, $venc], static fn($p) => $p !== ''));
    return implode(' - ', $partes);
})($gedPdo, $tipo, $chave, $valor, $titulo);
$titulo = $gedTituloJanela;
?><!doctype html><html lang="<?= IdiomaService::idioma() === 'pt' ? 'pt-BR' : IdiomaService::idioma() ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=htmlspecialchars($titulo,ENT_QUOTES,'UTF-8')?></title><link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2"><link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2"><link rel="stylesheet" href="../assets/css/app.css?v=4"><link rel="stylesheet" href="../assets/css/main.css?v=17"><style>#gedGrid thead th,#gedVersions thead th{cursor:pointer;user-select:none;white-space:nowrap}</style></head><body class="p-2 bg-light"><main class="container-fluid"><ul class="nav nav-tabs ged-tabs mb-2" id="gedTabs" role="tablist"><li class="nav-item" role="presentation"><button class="nav-link active" type="button" data-ged-tab="files" role="tab" aria-selected="true"><?= $h('aba.arquivos') ?></button></li><?php if($tipo==='licencas'): ?><li class="nav-item" role="presentation"><button class="nav-link" type="button" data-ged-tab="versions" role="tab" aria-selected="false"><?= $h('aba.versoes') ?></button></li><?php endif; ?></ul><div class="ged-tab-content"><div class="ged-tab-pane active" id="gedFilesPane" role="tabpanel"><div class="alert alert-secondary py-1 px-2 small mb-2" id="gedSomenteConsulta" hidden><i class="bi bi-lock me-1"></i><span></span></div><div class="crud-toolbar menu-grid-toolbar mb-2"><div class="crud-actions"><button type="button" class="btn btn-sm btn-outline-primary" id="gedUpload" title="<?= $h('botao.enviar_dica') ?>" aria-label="<?= $h('botao.enviar_dica') ?>"><i class="bi bi-upload me-1"></i><?= $h('botao.enviar') ?></button><button type="button" class="btn btn-sm btn-outline-danger" id="gedDelete" disabled title="<?= $h('botao.excluir_dica') ?>" aria-label="<?= $h('botao.excluir_dica') ?>"><i class="bi bi-trash me-1"></i><?= $h('botao.excluir') ?></button><input type="file" id="gedInput" hidden></div></div><div id="gedMessage" class="small text-muted mb-2"></div><div class="menu-grid-scroll"><table class="table table-sm table-hover menu-batch-grid" id="gedGrid"><thead><tr><th data-ged-sort="name"><span><?= $h('coluna.nome') ?></span></th><th data-ged-sort="created_at"><span><?= $h('coluna.criacao') ?></span></th><th data-ged-sort="size"><span><?= $h('coluna.tamanho') ?></span></th></tr></thead><tbody><tr><td colspan="3"><?= $h('msg.carregando') ?></td></tr></tbody></table></div></div><?php if($tipo==='licencas'): ?><div class="ged-tab-pane" id="gedVersionsPane" role="tabpanel"><div class="menu-grid-scroll"><table class="table table-sm table-hover menu-batch-grid" id="gedVersions"><thead><tr><th data-ged-vsort="VERSAO"><span><?= $h('versao.versao') ?></span></th><th data-ged-vsort="STATUS_VERSAO"><span><?= $h('versao.status') ?></span></th><th data-ged-vsort="DATA_VALIDADE"><span><?= $h('versao.validade') ?></span></th><th data-ged-vsort="STATUS_EXTRACAO"><span><?= $h('versao.extracao') ?></span></th><th data-ged-vsort="CONFIANCA_EXTRACAO"><span><?= $h('versao.confianca') ?></span></th><th data-ged-vsort="NOME_ARQUIVO"><span><?= $h('versao.arquivo') ?></span></th></tr></thead><tbody><tr><td colspan="6" class="text-muted"><?= $h('msg.carregando') ?></td></tr></tbody></table></div></div><?php endif; ?></div></main>
<?php $gTextos = []; foreach ($gTextosPadrao as $gChave => $gPadrao) $gTextos[$gChave] = IdiomaService::t('gestao_arquivos.' . $gChave, $gPadrao); ?><script>(function(){var t=<?= json_encode($titulo, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;try{var f=window.frameElement,m=f&&f.closest('.modal');if(m){var h=m.querySelector('.modal-title,[data-popup-transacao-title]');if(h){h.textContent=t;h.title=t;}}}catch(e){}}());</script><script>var G=<?= json_encode($gTextos, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;function esc0(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}</script><script src="../assets/vendor/jquery/jquery-3.7.1.min.js?v=2"></script><script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script><script>$(function(){var tabs=$('#gedTabs'),filesPane=$('#gedFilesPane');if(tabs.length&&!$('#gedPreviewTab').length){$('<li class="nav-item" role="presentation"><button class="nav-link" id="gedPreviewTab" type="button" data-ged-tab="preview" role="tab" aria-selected="false" disabled>'+esc0(G['previa.titulo'])+'</button></li>').insertAfter(tabs.find('[data-ged-tab="files"]').closest('li'));$('<div class="ged-tab-pane" id="gedPreviewPane" role="tabpanel"><div id="gedPreviewEmpty" class="ged-preview-empty text-muted">'+esc0(G['previa.selecione'])+'</div><iframe id="gedPreviewFrame" class="ged-preview-frame" title="'+esc0(G['previa.titulo'])+'" hidden></iframe><img id="gedPreviewImage" class="ged-preview-image" alt="'+esc0(G['previa.titulo'])+'" hidden></div>').insertAfter(filesPane);}window.gedResetPreview=function(){$('#gedPreviewTab').prop('disabled',true);$('#gedPreviewFrame').attr('src','').prop('hidden',true);$('#gedPreviewImage').attr('src','').prop('hidden',true);$('#gedPreviewEmpty').text(G['previa.selecione']).prop('hidden',false);if($('#gedPreviewPane').hasClass('active'))$('#gedTabs [data-ged-tab="files"]').trigger('click');};window.gedShowPreview=function(name){var file=(data.files||[]).find(function(item){return String(item.name)===String(name);});if(!file)return;var url=String(file.url||''),ext=String(file.name||'').split('.').pop().toLowerCase();$('#gedPreviewTab').prop('disabled',false);$('#gedPreviewFrame').attr('src','').prop('hidden',true);$('#gedPreviewImage').attr('src','').prop('hidden',true);$('#gedPreviewEmpty').prop('hidden',true);if(['jpg','jpeg','jfif','png','gif','bmp','webp'].indexOf(ext)>=0)$('#gedPreviewImage').attr('src',url).prop('hidden',false);else $('#gedPreviewFrame').attr('src',gedVer(url)).prop('hidden',false);/* demais formatos: visualizador universal (texto, mídia, Office/DWG convertidos pelo serviço agendado) */$('#gedTabs [data-ged-tab="preview"]').trigger('click');};$('#gedTabs [data-ged-tab]').on('click',function(){if(this.disabled)return;var tab=$(this).data('ged-tab');$('#gedTabs .nav-link').removeClass('active').attr('aria-selected','false');$(this).addClass('active').attr('aria-selected','true');$('.ged-tab-pane').removeClass('active');$('#'+({versions:'gedVersionsPane',preview:'gedPreviewPane',analyses:'gedAnalysesPane',review:'gedReviewPane'}[tab]||'gedFilesPane')).addClass('active');$(document).trigger('gedAbaAtivada',[tab]);});});</script><script>
const api='api/gestao_arquivos.php',params={tipo:<?=json_encode($tipo)?>,chave:<?=json_encode($chave)?>,valor:<?=$valor?>,CODUNIDADE:<?= (int)($_GET['CODUNIDADE']??0) ?>,CODLEGISLACAO:<?= (int)($_GET['CODLEGISLACAO']??0) ?>,csrf:<?=json_encode($gedCsrf)?>},iaTipo=<?=json_encode(in_array($tipo,['licencas','controles','infraestrutura','treinamentos'],true)?$tipo:'')?>,iaUrl=iaTipo==='licencas'?'api/licencas_ia.php':'api/documentos_ia.php',canAnalyze=<?=json_encode($canAnalyze)?>,podeReanalisar=<?=json_encode($podeReanalisar)?>;let data={files:[],versions:[]},selected='';const esc=v=>$('<div>').text(String(v??'')).html();const cod=v=>{const c=String(v??'');return G['codigo.'+c]||c;};const gedVer=u=>'visualizador_arquivo.php?u='+encodeURIComponent(String(u||''));
// Ordenação por coluna (padrão dos grids): clique no título alterna crescente/decrescente; arquivos começam pela data de criação, do mais novo para o mais antigo
let ordem={col:'created_at',dir:-1},ordemVersoes={col:'VERSAO',dir:-1};
const comparar=(a,b)=>{const x=String(a??''),y=String(b??''),nx=Number(x.replace(',','.')),ny=Number(y.replace(',','.'));if(x!==''&&y!==''&&!Number.isNaN(nx)&&!Number.isNaN(ny))return nx-ny;return x.localeCompare(y,'pt-BR',{numeric:true,sensitivity:'base'});};
const ordenar=(lista,o)=>lista.slice().sort((a,b)=>comparar(a[o.col],b[o.col])*o.dir);
const dataHora=v=>{const m=String(v||'').match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);return m?m[3]+'/'+m[2]+'/'+m[1]+(m[4]?' '+m[4]+':'+m[5]:''):String(v||'');};
function iconesOrdem(){[['#gedGrid','data-ged-sort',ordem],['#gedVersions','data-ged-vsort',ordemVersoes]].forEach(([t,a,o])=>{$(t+' thead th['+a+']').each(function(){const ativo=$(this).attr(a)===o.col;$(this).toggleClass('sort-asc',ativo&&o.dir===1).toggleClass('sort-desc',ativo&&o.dir===-1);let $i=$(this).find('.column-sort-icon');if(!$i.length)$i=$('<i class="bi column-sort-icon ms-1" aria-hidden="true"></i>').appendTo($(this).find('span').first());$i.attr('class','bi column-sort-icon ms-1 '+(ativo?(o.dir===1?'bi-arrow-up':'bi-arrow-down'):'bi-arrow-down-up'));});});}
$(document).on('click','#gedGrid thead th[data-ged-sort]',function(){const c=$(this).attr('data-ged-sort');ordem=ordem.col===c?{col:c,dir:-ordem.dir}:{col:c,dir:c==='name'?1:-1};render();});
$(document).on('click','#gedVersions thead th[data-ged-vsort]',function(){const c=$(this).attr('data-ged-vsort');ordemVersoes=ordemVersoes.col===c?{col:c,dir:-ordemVersoes.dir}:{col:c,dir:1};render();});
// Arquivo: abre no visualizador em popup (90% da tela que chamou), nunca em nova aba do navegador.
// Ligado no #gedGrid (como a seleção da linha) para rodar antes do render() que recria a tabela.
$('#gedGrid').on('click','tbody td>a[target=_blank]',function(e){let h=null;try{h=window.top&&window.top.GreenPortalPopups&&window.top.jQuery?window.top:null;}catch(x){h=null;}if(!h)return;e.preventDefault();h.GreenPortalPopups.transacao90({titulo:$(this).text(),url:new URL(this.getAttribute('href'),window.location.href).toString()});});
function render(){let html='';iconesOrdem();ordenar(data.files||[],ordem).forEach(f=>html+='<tr data-name="'+esc(f.name)+'"'+(String(f.name)===String(selected)?' class="table-active"':'')+'><td><a href="'+esc(gedVer(f.url))+'" target="_blank" rel="noopener">'+esc(f.name)+'</a>'+(f.download?' <a href="'+esc(f.download)+'" download="'+esc(f.name)+'" data-ged-baixar class="ms-1 text-secondary" title="'+esc(G['baixar'])+'" aria-label="'+esc(G['baixar'])+'"><i class="bi bi-download"></i></a>':'')+((data.analisados||{})[f.name]?' <i class="bi bi-stars text-success ms-1" title="'+esc(G['ia.ja_analisado']+': '+cod((data.analisados||{})[f.name]))+'"></i>':'')+'</td><td style="white-space:nowrap">'+esc(dataHora(f.created_at))+'</td><td class="text-end" style="white-space:nowrap">'+Math.ceil(Number(f.size||0)/1024)+' KB</td></tr>');if(!html)html='<tr><td colspan="3" class="text-muted">'+esc(G['msg.nenhum_arquivo'])+'</td></tr>';$('#gedGrid tbody').html(html);if($('#gedVersions').length){let versions='';ordenar(data.versions||[],ordemVersoes).forEach(v=>versions+='<tr><td>'+esc(v.VERSAO)+'</td><td>'+esc(cod(v.STATUS_VERSAO))+'</td><td>'+esc(dataHora(v.DATA_VALIDADE))+'</td><td>'+esc(cod(v.STATUS_EXTRACAO||''))+(v.FONTE_EXTRACAO?' / '+esc(cod(v.FONTE_EXTRACAO)):'')+'</td><td>'+esc(v.CONFIANCA_EXTRACAO??'')+'</td><td>'+esc(v.NOME_ARQUIVO||'')+'</td></tr>');if(!versions)versions='<tr><td colspan="6" class="text-muted">'+esc(G['msg.nenhuma_versao'])+'</td></tr>';$('#gedVersions tbody').html(versions);}const fSel=(data.files||[]).find(x=>String(x.name)===String(selected));$('#gedUpload,#gedInput').prop('hidden',data.can_upload===false).prop('disabled',data.can_upload===false);$('#gedSomenteConsulta').prop('hidden',!(data.can_upload===false&&data.can_delete===false)).find('span').text(G['msg.somente_consulta']||'');$('#gedDelete').prop('hidden',data.can_upload===false&&data.can_delete===false&&!(data.files||[]).some(x=>x.pode_excluir));(function(){const tb=$('#gedUpload').closest('.crud-toolbar');tb.prop('hidden',false);tb.prop('hidden',!tb.find('button').filter(function(){return !this.hidden&&!$(this).closest('[hidden]').length;}).length);})();$('#gedDelete').prop('disabled',!fSel||!(fSel.pode_excluir??data.can_delete)).attr('title',fSel&&fSel.dono?G['dono.dica']:G['botao.excluir_dica']);if(window.gedAnalyzeButton){const jaAnalisado=!!(selected&&(data.analisados||{})[selected]);window.gedAnalyzeButton.prop('disabled',!selected||!/\.(pdf|jpe?g|png|tiff?|bmp|docx?)$/i.test(selected)||!canAnalyze).attr('title',jaAnalisado?G['ia.ver_analise']:G['ia.botao_dica']).attr('aria-label',jaAnalisado?G['ia.ver_analise']:G['ia.botao_dica']).find('i').attr('class',jaAnalisado?'bi bi-eye me-1':'bi bi-stars me-1');}}
// Documento já analisado: não envia de novo; abre a análise existente (para revisar, se ainda não confirmada).
// O perfil ADM da empresa pode pedir uma nova análise, com confirmação.
window.gedTratarDuplicado=function(r,refazer){$('#gedMessage').text(r.message||'');const a=r.analise||{};if(r.situacao!=='EM_ANDAMENTO'){if(a.status==='CONCLUIDA'&&window.gedReabrirAnalise&&canAnalyze)window.gedReabrirAnalise(a);else if(window.gedMostrarAnalise)window.gedMostrarAnalise(a);}if(r.situacao!=='EM_ANDAMENTO'&&r.pode_reanalisar&&podeReanalisar&&refazer)$('<button type="button" class="btn btn-sm btn-outline-warning ms-2"><i class="bi bi-arrow-repeat me-1"></i>'+esc(G['ia.analisar_novamente'])+'</button>').on('click',function(){if(confirm(G['ia.confirmar_reanalise']))refazer();}).appendTo('#gedMessage');};
function load(){var previous=selected;return $.getJSON(api,{...params,action:'list'}).done(d=>{data=d;selected=previous&&(data.files||[]).some(function(file){return String(file.name)===String(previous);})?previous:'';render();}).fail(x=>$('#gedMessage').text((x.responseJSON||{}).message||G['erro.carregar']));}
$('#gedUpload').on('click',()=>$('#gedInput').trigger('click'));$('#gedInput').on('change',function(){const file=this.files[0];if(!file)return;const fd=new FormData();Object.keys(params).forEach(k=>fd.append(k,params[k]));fd.append('action','upload');fd.append('arquivo',file);$.ajax({url:api,method:'POST',data:fd,processData:false,contentType:false}).done(d=>{if(iaTipo==='licencas'&&d.version&&d.version.codlicenca)params.valor=Number(d.version.codlicenca);data=d;selected=d.version?String(d.version.arquivo||''):'';render();if(selected)window.gedShowPreview(selected);$('#gedMessage').text(d.version?G['msg.nova_versao'].replace('{versao}',d.version.versao):G['msg.upload_concluido']);setTimeout(load,250);}).fail(x=>$('#gedMessage').text((x.responseJSON||{}).message||G['erro.enviar']));this.value='';});
$('#gedGrid').on('click','tbody tr[data-name]',function(){$('#gedGrid tbody tr').removeClass('table-active');$(this).addClass('table-active');selected=String($(this).data('name'));render();$(document).trigger('gedArquivoSelecionado',[selected]);});$('#gedDelete').on('click',function(){if(!selected||!confirm(G['confirmar.excluir']))return;$.post(api,{...params,action:'delete',arquivo:selected}).done(d=>{data=d;selected='';render();window.gedResetPreview();}).fail(x=>$('#gedMessage').text((x.responseJSON||{}).message||G['erro.excluir']));});
// A visualização só abre pelo link do arquivo; clicar na linha apenas seleciona.
$('#gedGrid').on('click','tbody tr[data-name] a:not([data-ged-baixar])',function(event){event.preventDefault();event.stopPropagation();var $row=$(this).closest('tr');$row.trigger('click');window.gedShowPreview(String($row.data('name')));});
<?php if (in_array($tipo,['licencas','controles','infraestrutura','treinamentos'],true)): ?>
window.gedAnalyzeButton=$('<button type="button" class="btn btn-sm btn-outline-success ms-1" id="gedAnalyze" disabled title="'+esc(G['ia.botao_dica'])+'" aria-label="'+esc(G['ia.botao_dica'])+'"><i class="bi bi-stars me-1"></i>'+esc(G['ia.botao'])+'</button>').appendTo('.crud-actions');
function showSuggestion(suggestion,fileName){const fields=[['descricao',G['ia.campo.descricao']],['data_validade',G['ia.campo.validade']],['atividade',G['ia.campo.atividade']],['orgao_emissor',G['ia.campo.orgao']],['numero_licenca',G['ia.campo.numero']],['observacao',G['ia.campo.observacao']]];const rawConfidence=Number(suggestion.confianca||0);const confidence=rawConfidence>0&&rawConfidence<=1?rawConfidence*100:rawConfidence;let body='<div class="alert alert-info py-2">'+esc(G['ia.aviso'])+'</div><div class="row g-2">';fields.forEach(([key,label])=>body+='<div class="col-md-6"><label class="form-label">'+esc(label)+'</label><input class="form-control form-control-sm" data-ia-field="'+key+'" value="'+esc(suggestion[key]||'')+'"></div>');body+='<div class="col-12"><small class="text-muted">'+esc(G['ia.confianca'])+': '+esc(confidence.toFixed(2).replace(/\.00$/,''))+'% | '+esc(G['versao.arquivo'])+': '+esc(fileName)+'</small></div><div class="col-12"><label class="form-label">'+esc(G['ia.alertas'])+'</label><div class="small text-warning">'+esc((suggestion.alertas||[]).join('; ')||G['ia.sem_alertas'])+'</div></div></div>';window.gedRevisao.abrir({titulo:G['ia.revisao_titulo'],arquivo:fileName,corpo:body,confirmar:function(painel,button){const clean={};painel.find('[data-ia-field]').each(function(){clean[$(this).data('ia-field')]=String($(this).val()||'').trim()||null;});button.prop('disabled',true);$.post(iaUrl,{action:'confirm',tipo:iaTipo,valor:params.valor,CODLICENCA:params.valor,arquivo:fileName,csrf:params.csrf,codanalise_ged:window.gedUltimaAnaliseGed||0,sugestao:JSON.stringify({...suggestion,confianca:confidence,...clean})},r=>{if(r&&r.ok){window.gedRevisao.fechar(true);$('#gedMessage').text(r.message);setTimeout(load,500);$(document).trigger('gedAnaliseConcluida');}else $('#gedMessage').text((r&&r.message)||G['erro.gravar']);},'json').fail(x=>$('#gedMessage').text((x.responseJSON||{}).message||G['erro.gravar'])).always(()=>button.prop('disabled',false));}});}
// Reabre uma análise concluída (aba Análises de IA) na aba de revisão.
window.gedReabrirAnalise=function(a){window.gedUltimaAnaliseGed=a.codanalise||0;showSuggestion(a.dados_ia&&typeof a.dados_ia==='object'?a.dados_ia:{},a.arquivo);};
async function extractPdfText(fileUrl){if(!window.pdfjsLib)await new Promise((resolve,reject)=>{const script=document.createElement('script');script.src='../assets/vendor/pdfjs/pdf.min.js?v=1';script.onload=resolve;script.onerror=()=>reject(new Error(G['erro.leitor_pdf']));document.head.appendChild(script);});window.pdfjsLib.GlobalWorkerOptions.workerSrc='../assets/vendor/pdfjs/pdf.worker.min.js?v=1';const response=await fetch(new URL(fileUrl,window.location.href).href,{credentials:'same-origin'});if(!response.ok)throw new Error(G['erro.baixar_pdf']);const pdf=await window.pdfjsLib.getDocument({data:await response.arrayBuffer()}).promise;let text='';for(let pageNo=1;pageNo<=pdf.numPages;pageNo++){const page=await pdf.getPage(pageNo),content=await page.getTextContent();text+=content.items.map(item=>item.str||'').join(' ')+'\n';}if(text.trim())return {text,ocr:false};throw new Error(G['erro.pdf_sem_texto']);}
async function extracaoLicencaFila(dados){let r=await $.ajax({url:'api/licencas_ia.php',method:'POST',data:dados,dataType:'json'});if(!r.queued)return r;for(let i=0;i<120;i++){await new Promise(resolve=>setTimeout(resolve,1000));r=await $.ajax({url:'api/licencas_ia.php',method:'POST',data:{action:'extract',CODLICENCA:params.valor,arquivo:selected,csrf:params.csrf,job_id:r.job_id,codanalise_ged:r.codanalise_ged},dataType:'json'});if(!r.queued)return r;}throw new Error('A extracao excedeu o tempo de espera do worker.');}
async function extracaoGedFila(tipo,valor,arquivo){let r=await $.ajax({url:'api/documentos_ocr.php',method:'POST',data:{tipo,valor,arquivo,csrf:params.csrf},dataType:'json'});for(let i=0;i<120;i++){if(r.status==='CONCLUIDA')return {texto:r.texto||'',fonte:'OCR_WORKER',confianca:r.confianca||0};if(r.status==='ERRO'||r.status==='CANCELADA')throw new Error(r.erro||G['erro.extracao']);await new Promise(resolve=>setTimeout(resolve,1000));r=await $.getJSON('api/documentos_ocr_status.php',{job_id:r.job_id});}throw new Error('A extracao excedeu o tempo de espera do worker.');}
async function analiseIaFila(url,dados){let r=await $.ajax({url,method:'POST',data:dados,timeout:240000,dataType:'json'});for(let i=0;i<180&&r.queued;i++){await new Promise(resolve=>setTimeout(resolve,1000));r=await $.ajax({url,method:'POST',data:{...dados,job_id:r.job_id},timeout:240000,dataType:'json'});}return r;}
async function gedAnalisar(forcar){if(!selected)return;const button=$('#gedAnalyze').prop('disabled',true);$('#gedMessage').text(G['ia.extraindo']);try{const file=(data.files||[]).find(item=>item.name===selected);if(!file)throw new Error(G['erro.arquivo_nao_localizado']);let extraction;if(iaTipo==='licencas'){extraction=await extracaoLicencaFila({action:'extract',CODLICENCA:params.valor,arquivo:selected,csrf:params.csrf,forcar:forcar?1:0});if(!extraction.ok)throw new Error(extraction.message||G['erro.extracao']);if(extraction.duplicado){window.gedTratarDuplicado(extraction,()=>gedAnalisar(true));return;}}else{try{extraction=await extractPdfText(file.url);}catch(e){extraction={text:'',ocr:true};}}const target=iaTipo==='licencas'?'api/licencas_ia.php':iaUrl;const result=extraction.reaproveitada?extraction:await analiseIaFila(target,{action:'analyze',tipo:iaTipo,valor:params.valor,CODLICENCA:params.valor,arquivo:selected,texto:extraction.texto||extraction.text||'',fonte:extraction.fonte||'',codanalise_ged:extraction.codanalise_ged||'',forcar:forcar?1:0,csrf:params.csrf});if(!result.ok)throw new Error(result.message||G['erro.ia_sem_sugestao']);if(result.duplicado){window.gedTratarDuplicado(result,()=>gedAnalisar(true));return;}window.gedUltimaAnaliseGed=result.codanalise_ged||0;showSuggestion(result.sugestao||{},result.arquivo||selected);$('#gedMessage').text(G['ia.sugestao_gerada']);$(document).trigger('gedAnaliseConcluida');}catch(error){$(document).trigger('gedAnaliseConcluida');const response=error&&error.responseJSON?error.responseJSON:{};$('#gedMessage').text(response.message||error.message||G['erro.analisar']);}finally{button.prop('disabled',false);render();}}
$('#gedAnalyze').on('click',()=>gedAnalisar(false));
<?php endif; ?>
<?php if ($tipo === 'funcionarios'): ?>
// Análise por IA de documentos do funcionário (ASO e certificados de treinamento): api/funcionarios_ia.php.
window.gedAnalyzeButton=$('<button type="button" class="btn btn-sm btn-outline-success ms-1" id="gedAnalyze" disabled title="'+esc(G['ia.botao_dica'])+'" aria-label="'+esc(G['ia.botao_dica'])+'"><i class="bi bi-stars me-1"></i>'+esc(G['ia.botao'])+'</button>').appendTo('.crud-actions');
(function(){
const fiaUrl='api/funcionarios_ia.php';
const op=(v,t,s)=>'<option value="'+esc(v)+'"'+(String(v)===String(s??'')?' selected':'')+'>'+esc(t)+'</option>';
const campo=(k,label,v,type)=>'<div class="col-md-4"><label class="form-label small mb-0">'+esc(label)+'</label><input class="form-control form-control-sm" type="'+(type||'text')+'" data-fia="'+k+'" value="'+esc(v??'')+'"></div>';
const lista=(k,label,codes,prefix,v)=>'<div class="col-md-4"><label class="form-label small mb-0">'+esc(label)+'</label><select class="form-select form-select-sm" data-fia="'+k+'">'+op('','—',v)+codes.map(c=>op(c,G[prefix+c]||c,v)).join('')+'</select></div>';
const destinos=(rows,sug)=>op('',G['fia.destino_nenhum'],sug?String(sug):'')+(rows||[]).map(r=>op(r.codigo,r.descricao+(r.validade||r.situacao?' ('+G['fia.destino_atual']+': '+[r.validade,r.situacao].filter(Boolean).join(' · ')+')':''),sug)).join('');
function abrir(r){
  const s=r.sugestao||{},f=r.funcionario||{},tipo=s.tipo_documento||'OUTRO';
  const conf=Number(r.confianca||0);
  let body='<div class="alert alert-info py-2 small">'+esc(G['fia.aviso'])+'</div>';
  body+='<div class="row g-2 small mb-2"><div class="col-md-6"><strong>'+esc(G['fia.cadastro'])+':</strong> '+esc(f.nome||'')+(f.registro?' · '+esc(f.registro):'')+(f.funcao?' · '+esc(f.funcao):'')+'</div><div class="col-md-6"><strong>'+esc(G['fia.documento'])+':</strong> '+esc(s.nome||'—')+(s.cpf?' · '+esc(s.cpf):'')+(s.funcao?' · '+esc(s.funcao):'')+'</div></div>';
  body+='<div class="row g-2"><div class="col-md-4"><label class="form-label small mb-0">'+esc(G['fia.tipo'])+'</label><select class="form-select form-select-sm" data-fia="tipo_documento">'+['ASO','TREINAMENTO','OUTRO'].map(c=>op(c,G['fia.tipo.'+c],tipo)).join('')+'</select></div></div>';
  body+='<div class="row g-2 mt-1" data-fia-bloco="ASO">'+lista('aso_tipo_exame',G['fia.aso_tipo_exame'],['ADMISSIONAL','PERIODICO','RETORNO_AO_TRABALHO','MUDANCA_DE_RISCO','DEMISSIONAL'],'fia.exame.',s.aso_tipo_exame)+lista('aso_resultado',G['fia.aso_resultado'],['APTO','APTO_COM_RESTRICAO','INAPTO'],'fia.resultado.',s.aso_resultado)+campo('aso_data_exame',G['fia.aso_data_exame'],s.aso_data_exame,'date')+campo('aso_validade',G['fia.validade'],s.aso_validade,'date')+campo('aso_medico',G['fia.aso_medico'],s.aso_medico)+campo('aso_crm',G['fia.aso_crm'],s.aso_crm)
    +'<div class="col-12"><label class="form-label small mb-0">'+esc(G['fia.destino_criterio'])+'</label><select class="form-select form-select-sm" data-fia-destino="ASO">'+destinos(r.criterios,tipo==='ASO'?r.destino_sugerido:'')+'</select></div></div>';
  body+='<div class="row g-2 mt-1" data-fia-bloco="TREINAMENTO">'+campo('treinamento_nome',G['fia.treinamento_nome'],s.treinamento_nome)+campo('treinamento_norma',G['fia.treinamento_norma'],s.treinamento_norma)+campo('treinamento_carga_horaria',G['fia.treinamento_carga_horaria'],s.treinamento_carga_horaria,'number')+campo('treinamento_data_realizacao',G['fia.treinamento_data_realizacao'],s.treinamento_data_realizacao,'date')+campo('treinamento_validade',G['fia.validade'],s.treinamento_validade,'date')+campo('treinamento_instituicao',G['fia.treinamento_instituicao'],s.treinamento_instituicao)
    +'<div class="col-12"><label class="form-label small mb-0">'+esc(G['fia.destino_treinamento'])+'</label><select class="form-select form-select-sm" data-fia-destino="TREINAMENTO">'+destinos(r.treinamentos,tipo==='TREINAMENTO'?r.destino_sugerido:'')+'</select></div></div>';
  body+='<div class="small text-muted mt-2" data-fia-dica>'+esc(G['fia.destino_dica'])+'</div>';
  body+='<div class="small text-muted mt-2">'+esc(G['ia.confianca'])+': '+esc((conf>0&&conf<=1?conf*100:conf).toFixed(0))+'% · '+esc(G['fia.fonte'])+': '+esc(G['fia.fonte.'+r.fonte_texto]||r.fonte_texto||'')+' · '+esc(r.arquivo||'')+'</div>';
  body+='<div class="mt-1"><label class="form-label small mb-0">'+esc(G['ia.alertas'])+'</label><div class="small text-warning">'+((s.alertas||[]).map(a=>'• '+esc(a)).join('<br>')||esc(G['ia.sem_alertas']))+'</div></div>';
  const enviar=(action,extra,botao)=>{botao.prop('disabled',true);return $.post(fiaUrl,{action,valor:params.valor,codanalise:r.codanalise,csrf:params.csrf,...extra},null,'json').done(x=>{$('#gedMessage').text(x.message||'');if(x.ok){window.gedRevisao.fechar(true);$(document).trigger('gedAnaliseConcluida');}}).fail(x=>$('#gedMessage').text((x.responseJSON||{}).message||G['erro.gravar'])).always(()=>botao.prop('disabled',false));};
  const painel=window.gedRevisao.abrir({titulo:G['fia.revisao_titulo'],arquivo:r.arquivo,corpo:body,
    confirmar:function(painel,botao){const t=painel.find('[data-fia="tipo_documento"]').val(),d={tipo_documento:t,nome:s.nome||null,cpf:s.cpf||null};painel.find('[data-fia-bloco="'+t+'"] [data-fia]').each(function(){d[$(this).data('fia')]=String($(this).val()||'').trim()||null;});d.destino=painel.find('[data-fia-destino="'+t+'"]').val()||null;enviar('confirm',{dados:JSON.stringify(d)},botao);},
    descartar:function(painel,botao){enviar('discard',{},botao);}});
  const blocos=()=>{const t=painel.find('[data-fia="tipo_documento"]').val();painel.find('[data-fia-bloco]').each(function(){$(this).toggle($(this).data('fia-bloco')===t);});painel.find('[data-fia-dica]').toggle(t!=='OUTRO');};
  painel.on('change','[data-fia="tipo_documento"]',blocos);blocos();
}
// Reabre uma análise concluída e ainda não confirmada (aba Análises de IA) na aba de revisão.
window.gedReabrirAnalise=function(a){if(!a.referencia){$('#gedMessage').text(G['fia.erro']);return;}$.post(fiaUrl,{action:'review',valor:params.valor,codanalise:a.referencia,csrf:params.csrf},null,'json').done(x=>{if(x&&x.ok)abrir(x);else $('#gedMessage').text((x&&x.message)||G['fia.erro']);}).fail(x=>$('#gedMessage').text((x.responseJSON||{}).message||G['fia.erro']));};
async function analisarFuncionario(forcar){if(!selected)return;const b=$('#gedAnalyze').prop('disabled',true);$('#gedMessage').text(G['fia.analisando']);try{const extraction=await extracaoGedFila('funcionarios',params.valor,selected);const base={action:'analyze',valor:params.valor,arquivo:selected,texto:extraction.texto,fonte:extraction.fonte,confianca_texto:extraction.confianca,csrf:params.csrf,forcar:forcar?1:0};let r=await $.ajax({url:fiaUrl,method:'POST',dataType:'json',timeout:240000,data:base});for(let i=0;i<180&&r.queued;i++){await new Promise(resolve=>setTimeout(resolve,1000));r=await $.ajax({url:fiaUrl,method:'POST',dataType:'json',timeout:240000,data:{...base,ia_job_id:r.ia_job_id}});}if(!r||!r.ok)throw new Error((r&&r.message)||G['fia.erro']);if(r.duplicado){window.gedTratarDuplicado(r,()=>analisarFuncionario(true));return;}$('#gedMessage').text(r.reaproveitada?G['ia.reaproveitada']:G['ia.sugestao_gerada']);abrir(r);}catch(e){$('#gedMessage').text(e.message||G['fia.erro']);}finally{b.prop('disabled',false);$(document).trigger('gedAnaliseConcluida');load();}}
$('#gedAnalyze').on('click',()=>analisarFuncionario(false));
})();
<?php endif; ?>
<?php if ($tipo === 'fornecedorcriterios'): ?>
// Análise por IA das evidências do documento do fornecedor (Portaria › Documentação de Fornecedor): api/fornecedorcriterios_ia.php.
// Usar a IA exige "Editar" na transação PORTARIA_DOCUMENTACAO_FORNECEDOR (canAnalyze).
window.gedAnalyzeButton=$('<button type="button" class="btn btn-sm btn-outline-success ms-1" id="gedAnalyze" disabled title="'+esc(G['ia.botao_dica'])+'" aria-label="'+esc(G['ia.botao_dica'])+'"><i class="bi bi-stars me-1"></i>'+esc(G['ia.botao'])+'</button>').appendTo('.crud-actions');
window.gedModeloHtml=window.gedModeloHtml||function(m){if(!m||typeof m!=='object')return'';const cor={SIM:'success',NAO:'danger',INCERTO:'warning'},rc={CONFORME:'success',NAO_CONFORME:'danger',INCERTO:'warning'};const linha=(t,tag,a,ev)=>'<tr><td><span class="badge text-bg-'+(cor[a]||'secondary')+'">'+esc(G['mod.'+a]||a)+'</span></td><td>'+esc(t)+' <span class="text-muted">('+esc(tag)+')</span>'+(ev?'<div class="text-muted">'+esc(ev)+'</div>':'')+'</td></tr>';
  let h='<div class="mt-3 border rounded p-2"><div class="d-flex justify-content-between align-items-center small fw-semibold mb-1"><span>'+esc(G['mod.titulo'])+'</span><span class="badge text-bg-'+(rc[m.resultado]||'secondary')+'">'+esc(G['mod.res.'+m.resultado]||m.resultado||'')+' · '+esc(String(m.conformes||0))+'/'+esc(String(m.total||0))+'</span></div>';
  h+='<table class="table table-sm small mb-1"><tbody>'+(m.itens||[]).map(i=>linha(i.texto,i.obrigatorio?G['mod.obrigatorio']:G['mod.recomendado'],i.atende,i.evidencia)).join('')+(m.regras||[]).map(r=>linha(r.texto,G['mod.regra'],r.atende,r.detalhe)).join('')+'</tbody></table>';
  return h+(m.parecer?'<div class="small">'+esc(m.parecer)+'</div>':'')+'<div class="small text-muted">'+esc(G['mod.decisao'])+'</div></div>';};
(function(){
const diaUrl='api/fornecedorcriterios_ia.php';
const op=(v,t,s)=>'<option value="'+esc(v)+'"'+(String(v)===String(s??'')?' selected':'')+'>'+esc(t)+'</option>';
const campo=(k,label,v,type,col)=>'<div class="'+(col||'col-md-4')+'"><label class="form-label small mb-0">'+esc(label)+'</label><input class="form-control form-control-sm" type="'+(type||'text')+'" data-dia="'+k+'" value="'+esc(v??'')+'"></div>';
function abrir(r){
  const s=r.sugestao||{},c=r.cadastro||{},conf=Number(r.confianca||0),sug=r.situacao_sugerida||'';
  let body='<div class="alert alert-info py-2 small">'+esc(G['dia.aviso'])+'</div>';
  body+='<div class="row g-2 small mb-2"><div class="col-md-6"><strong>'+esc(G['dia.cadastro'])+':</strong> '+esc(c.documento||'')+'<br>'+esc(c.fornecedor||'')+(c.cnpj?' · '+esc(c.cnpj):'')+'</div><div class="col-md-6"><strong>'+esc(G['dia.lido'])+':</strong> '+esc(s.tipo_documento||'—')+' · '+esc(G['dia.corresponde'])+' <b>'+esc(G['dia.corresponde.'+(s.corresponde_ao_exigido||'INCERTO')]||'')+'</b><br>'+esc([s.razao_social,s.cnpj].filter(Boolean).join(' · ')||'—')+'</div></div>';
  body+='<div class="row g-2">'+campo('tipo_documento',G['dia.tipo'],s.tipo_documento)+campo('numero_documento',G['dia.numero'],s.numero_documento)+campo('orgao_emissor',G['dia.orgao'],s.orgao_emissor)+campo('data_emissao',G['dia.emissao'],s.data_emissao,'date')+'</div>';
  if(s.resumo)body+='<div class="small text-muted mt-1">'+esc(G['dia.resumo'])+': '+esc(s.resumo)+'</div>';
  body+='<div class="form-check mt-3"><input class="form-check-input" type="checkbox" id="diaAtualizar" data-dia="atualizar"'+(s.corresponde_ao_exigido==='NAO'?'':' checked')+'><label class="form-check-label small fw-semibold" for="diaAtualizar">'+esc(G['dia.atualizar'])+'</label></div>';
  body+='<div class="row g-2 mt-1" data-dia-bloco>'
    +campo('data_validade',G['dia.validade']+' ('+G['dia.atual']+': '+(c.validade||'—')+')',s.data_validade||c.validade,'date')
    +'<div class="col-md-4"><label class="form-label small mb-0">'+esc(G['dia.situacao'])+' ('+esc(G['dia.atual'])+': '+esc(c.situacao||'—')+')</label><select class="form-select form-select-sm" data-dia="situacao">'+op('','—',sug||c.situacao)+(r.situacoes||[]).map(x=>op(x,x+(x===sug?' — '+G['dia.sugerida']:''),sug||c.situacao)).join('')+'</select></div>'
    +'<div class="col-md-4"><label class="form-label small mb-0">'+esc(G['dia.enviado'])+'</label><select class="form-select form-select-sm" data-dia="enviado">'+op('Sim',G['dia.corresponde.SIM'],'Sim')+op('Não',G['dia.corresponde.NAO'],'')+'</select></div>'
    +'<div class="col-12"><label class="form-label small mb-0">'+esc(G['dia.observacao'])+'</label><textarea class="form-control form-control-sm" rows="2" data-dia="observacao">'+esc(r.observacao_sugerida||c.observacao||'')+'</textarea></div></div>';
  body+='<div class="small text-muted mt-2">'+esc(G['ia.confianca'])+': '+esc((conf>0&&conf<=1?conf*100:conf).toFixed(0))+'% · '+esc(G['fia.fonte'])+': '+esc(G['fia.fonte.'+r.fonte_texto]||r.fonte_texto||'')+' · '+esc(r.arquivo||'')+'</div>';
  body+='<div class="mt-1"><label class="form-label small mb-0">'+esc(G['ia.alertas'])+'</label><div class="small text-warning">'+((s.alertas||[]).map(a=>'• '+esc(a)).join('<br>')||esc(G['ia.sem_alertas']))+'</div></div>';
  body+=window.gedModeloHtml(s.modelo);
  const enviar=(action,extra,botao)=>{botao.prop('disabled',true);return $.post(diaUrl,{action,valor:params.valor,codanalise:r.codanalise,csrf:params.csrf,...extra},null,'json').done(x=>{$('#gedMessage').text(x.message||'');if(x.ok){window.gedRevisao.fechar(true);$(document).trigger('gedAnaliseConcluida');try{(window.top||window).postMessage({tipo:'gedDocumentoFornecedorAtualizado',valor:params.valor},window.location.origin);}catch(e){}}}).fail(x=>$('#gedMessage').text((x.responseJSON||{}).message||G['erro.gravar'])).always(()=>botao.prop('disabled',false));};
  const painel=window.gedRevisao.abrir({titulo:G['dia.revisao_titulo'],arquivo:r.arquivo,corpo:body,
    confirmar:function(painel,botao){const d={atualizar:painel.find('[data-dia="atualizar"]').prop('checked')?1:0};painel.find('[data-dia]').not('[data-dia="atualizar"]').each(function(){d[$(this).data('dia')]=String($(this).val()||'').trim()||null;});if(d.observacao===null)d.observacao='';enviar('confirm',{dados:JSON.stringify(d)},botao);},
    descartar:function(painel,botao){enviar('discard',{},botao);}});
  const bloco=()=>painel.find('[data-dia-bloco]').find('input,select,textarea').prop('disabled',!painel.find('[data-dia="atualizar"]').prop('checked'));
  painel.on('change','[data-dia="atualizar"]',bloco);bloco();
}
window.gedReabrirAnalise=function(a){if(!a.referencia){$('#gedMessage').text(G['dia.erro']);return;}$.post(diaUrl,{action:'review',valor:params.valor,codanalise:a.referencia,csrf:params.csrf},null,'json').done(x=>{if(x&&x.ok)abrir(x);else $('#gedMessage').text((x&&x.message)||G['dia.erro']);}).fail(x=>$('#gedMessage').text((x.responseJSON||{}).message||G['dia.erro']));};
async function analisar(forcar){if(!selected)return;const b=$('#gedAnalyze').prop('disabled',true);$('#gedMessage').text(G['dia.analisando']);try{const extraction=await extracaoGedFila('fornecedorcriterios',params.valor,selected);const base={action:'analyze',valor:params.valor,arquivo:selected,texto:extraction.texto,fonte:extraction.fonte,confianca_texto:extraction.confianca,csrf:params.csrf,forcar:forcar?1:0};let r=await $.ajax({url:diaUrl,method:'POST',dataType:'json',timeout:240000,data:base});for(let i=0;i<180&&r.queued;i++){await new Promise(resolve=>setTimeout(resolve,1000));r=await $.ajax({url:diaUrl,method:'POST',dataType:'json',timeout:240000,data:{...base,ia_job_id:r.ia_job_id}});}if(!r||!r.ok)throw new Error((r&&r.message)||G['dia.erro']);if(r.duplicado){window.gedTratarDuplicado(r,()=>analisar(true));return;}$('#gedMessage').text(r.reaproveitada?G['ia.reaproveitada']:G['ia.sugestao_gerada']);abrir(r);}catch(e){$('#gedMessage').text(e.message||G['dia.erro']);}finally{b.prop('disabled',false);$(document).trigger('gedAnaliseConcluida');load();}}
$('#gedAnalyze').on('click',()=>analisar(false));
})();
<?php endif; ?>
<?php if ($tipo === 'planodeacao'): ?>
// Análise por IA das evidências do plano de ação da condicionante (api/planodeacao_ia.php, migração 308).
// Ao abrir a tela, a evidência mais recente ainda não analisada é lida e analisada automaticamente; um arquivo enviado
// também. O que a IA encontrar para os campos do plano vai para a aba "Revisão dos dados", para aprovação do operador.
// Usar a IA exige "Editar" na transação LICENCAS (canAnalyze).
window.gedAnalyzeButton=$('<button type="button" class="btn btn-sm btn-outline-success ms-1" id="gedAnalyze" disabled title="'+esc(G['ia.botao_dica'])+'" aria-label="'+esc(G['ia.botao_dica'])+'"><i class="bi bi-stars me-1"></i>'+esc(G['ia.botao'])+'</button>').appendTo('.crud-actions');
$('#gedInput').attr('accept','.pdf,.jpg,.jpeg,.png,.tif,.tiff,.bmp,.doc,.docx');
(function(){
const piaUrl='api/planodeacao_ia.php',analisavel=n=>/\.(pdf|jpe?g|png|tiff?|bmp|docx?)$/i.test(String(n||''));
const espera=ms=>new Promise(r=>setTimeout(r,ms));
const post=d=>$.ajax({url:piaUrl,method:'POST',dataType:'json',timeout:240000,data:{valor:params.valor,csrf:params.csrf,...d}});
const campos=[['ACAO','acao','pia.acao','textarea'],['CODUSUARIO','codusuario','pia.responsavel','usuario'],['DATAINICIAL','data_inicial','pia.data_inicial','date'],['DATAFINAL','data_final','pia.data_final','date'],['RECURSOS','recursos','pia.recursos','textarea'],['STATUS','status','pia.status','status']];
const dataBr=v=>{const m=String(v||'').match(/^(\d{4})-(\d{2})-(\d{2})/);return m?m[3]+'/'+m[2]+'/'+m[1]:String(v||'');};
// Avisa as telas abertas (grid do plano de ação) para atualizar a linha sem recarregar.
const avisar=msg=>{const ir=w=>{try{w.postMessage(msg,window.location.origin);}catch(e){}try{for(let i=0;i<w.frames.length;i++)ir(w.frames[i]);}catch(e){}};try{ir(window.top||window);}catch(e){ir(window);}};
function abrir(r){
  const s=r.sugestao||{},c=r.cadastro||{},conf=Number(s.confianca||0);
  const atual={ACAO:c.acao,CODUSUARIO:c.responsavel,DATAINICIAL:dataBr(c.data_inicial),DATAFINAL:dataBr(c.data_final),RECURSOS:c.recursos,STATUS:(c.status!==''&&c.status!=null)?c.status+'%':''};
  let body='<div class="alert alert-info py-2 small">'+esc(G['pia.aviso'])+'</div>';
  body+='<div class="small mb-2"><strong>'+esc(G['pia.condicionante'])+':</strong> '+esc(c.condicionante||'—')+(c.licenca?'<br><strong>'+esc(G['pia.licenca'])+':</strong> '+esc(c.licenca):'')+'<br><strong>'+esc(G['pia.lido'])+':</strong> '+esc(s.tipo_documento||'—')+' · '+esc(G['pia.corresponde'])+' <b>'+esc(G['pia.corresponde.'+(s.corresponde_a_condicionante||'INCERTO')]||'')+'</b></div>';
  if(s.resumo)body+='<div class="small text-muted mb-2">'+esc(G['pia.resumo'])+': '+esc(s.resumo)+'</div>';
  body+='<table class="table table-sm align-middle small mb-1"><thead><tr><th style="width:2rem"></th><th>'+esc(G['pia.campo'])+'</th><th>'+esc(G['pia.atual'])+'</th><th style="min-width:45%">'+esc(G['pia.sugerido'])+'</th></tr></thead><tbody>';
  let algum=false;
  campos.forEach(([f,k,lab,tp])=>{
    const v=tp==='usuario'?(s.codusuario||''):(s[k]??'');
    const tem=String(v)!=='';
    const igual=tem&&(tp==='usuario'?String(v)===String(c.codusuario||''):String(v).trim()===String(c[k]??'').trim());
    const marcar=tem&&!igual;if(marcar)algum=true;
    let input;
    if(tp==='textarea')input='<textarea class="form-control form-control-sm" rows="2" data-pia-valor="'+f+'">'+esc(v)+'</textarea>';
    else if(tp==='date')input='<input type="date" class="form-control form-control-sm" data-pia-valor="'+f+'" value="'+esc(v)+'">';
    else if(tp==='status')input='<select class="form-select form-select-sm" data-pia-valor="'+f+'"><option value=""></option>'+(r.status_opcoes||['0','25','50','75','100']).map(x=>'<option value="'+esc(x)+'"'+(String(x)===String(v)?' selected':'')+'>'+esc(x)+'%</option>').join('')+'</select>';
    else input='<select class="form-select form-select-sm" data-pia-valor="'+f+'" data-pia-usuarios><option value="'+esc(v)+'" selected>'+esc(s.codusuario?(s.responsavel||''):(s.responsavel?s.responsavel+' — '+G['pia.nao_encontrado']:''))+'</option></select>';
    body+='<tr><td><input class="form-check-input" type="checkbox" data-pia-aplicar="'+f+'"'+(marcar?' checked':'')+'></td><td>'+esc(G[lab])+'</td><td class="text-muted">'+esc(atual[f]||'—')+'</td><td>'+input+'</td></tr>';
  });
  body+='</tbody></table>';
  if(!algum)body+='<div class="small text-warning mb-1">'+esc(G['pia.nada_novo'])+'</div>';
  body+='<div class="small text-muted mt-2">'+esc(G['ia.confianca'])+': '+esc((conf>0&&conf<=1?conf*100:conf).toFixed(0))+'%'+(r.arquivo?' · '+esc(r.arquivo):'')+'</div>';
  body+='<div class="mt-1"><label class="form-label small mb-0">'+esc(G['ia.alertas'])+'</label><div class="small text-warning">'+((s.alertas||[]).map(a=>'• '+esc(a)).join('<br>')||esc(G['ia.sem_alertas']))+'</div></div>';
  const enviar=(action,extra,botao)=>{botao.prop('disabled',true);return post({action,codanalise_ged:r.codanalise_ged,arquivo:r.arquivo,...extra}).done(x=>{$('#gedMessage').text(x.message||'');if(x.ok){window.gedRevisao.fechar(true);$(document).trigger('gedAnaliseConcluida');if(action==='confirm'&&x.aplicados&&Object.keys(x.aplicados).length)avisar({tipo:'gedPlanoAcaoAtualizado',valor:params.valor,aplicados:x.aplicados});load();}}).fail(x=>$('#gedMessage').text((x.responseJSON||{}).message||G['erro.gravar'])).always(()=>botao.prop('disabled',false));};
  const painel=window.gedRevisao.abrir({titulo:G['pia.revisao_titulo'],arquivo:r.arquivo,corpo:body,
    confirmar:function(p,botao){const d={};p.find('[data-pia-aplicar]:checked').each(function(){const f=$(this).data('pia-aplicar');d[f]=String(p.find('[data-pia-valor="'+f+'"]').val()||'').trim();});if(!Object.keys(d).length)$('#gedMessage').text(G['pia.nenhum_marcado']);enviar('confirm',{dados:JSON.stringify({campos:d})},botao);},
    descartar:function(p,botao){enviar('discard',{},botao);}});
  // Mudou o valor sugerido: marca o campo para gravar (desmarca se ficar vazio).
  painel.on('input change','[data-pia-valor]',function(){painel.find('[data-pia-aplicar="'+$(this).data('pia-valor')+'"]').prop('checked',String($(this).val()||'')!=='');});
  // Lista de usuários carregada só quando o operador abre o campo Responsável.
  painel.one('focus mousedown','[data-pia-usuarios]',function(){const sel=$(this),atualV=String(sel.val()||'');$.getJSON('api/grid_opcoes_lazy.php',{view:'vw_usuarios_grid',label:'nome',filters:JSON.stringify({ativo:1})}).done(x=>{if(!x||!x.ok)return;const txt=sel.find('option:selected').text();sel.empty().append($('<option>',{value:'',text:''}));let achou=false;(x.rows||[]).forEach(u=>{const v=String(u.codusuario||'');if(!v)return;if(v===atualV)achou=true;sel.append($('<option>',{value:v,text:String(u.nome||v),selected:v===atualV}));});if(atualV&&!achou)sel.prepend($('<option>',{value:atualV,text:txt,selected:true}));sel.val(atualV);});});
}
window.gedReabrirAnalise=function(a){post({action:'review',codanalise_ged:a.codanalise||0,arquivo:a.arquivo||'',sugestao:JSON.stringify(a.dados_ia&&typeof a.dados_ia==='object'?a.dados_ia:{}),fonte:a.fonte_texto||''}).done(x=>{if(x&&x.ok)abrir(x);else $('#gedMessage').text((x&&x.message)||G['erro.analisar']);}).fail(x=>$('#gedMessage').text((x.responseJSON||{}).message||G['erro.analisar']));};
let emAndamento=false;
async function analisar(forcar,nome){nome=nome||selected;if(!nome||emAndamento)return;emAndamento=true;const b=$('#gedAnalyze').prop('disabled',true);$('#gedMessage').text(G['pia.lendo']);
  try{
    let r=await post({action:'extract',arquivo:nome,forcar:forcar?1:0});
    if(r.duplicado){window.gedTratarDuplicado(r,()=>analisar(true,nome));return;}
    if(!r.ok)throw new Error(r.message||G['erro.extracao']);
    if(r.sugestao){$('#gedMessage').text(G['ia.reaproveitada']);abrir(r);return;}
    for(let i=0;i<180&&r.queued;i++){await espera(1000);r=await post({action:'extract',arquivo:nome,job_id:r.job_id,codanalise_ged:r.codanalise_ged});}
    if(!r.ok||r.queued)throw new Error(r.message||G['erro.extracao']);
    $('#gedMessage').text(G['pia.analisando']);
    const cod=r.codanalise_ged;
    let a=await post({action:'analyze',arquivo:nome,codanalise_ged:cod,texto:r.texto||'',fonte:r.fonte||''});
    for(let i=0;i<180&&a.queued;i++){await espera(1000);a=await post({action:'analyze',arquivo:nome,codanalise_ged:cod,ia_job_id:a.ia_job_id});}
    if(!a.ok||a.queued)throw new Error(a.message||G['erro.ia_sem_sugestao']);
    $('#gedMessage').text(G['ia.sugestao_gerada']);abrir(a);
  }catch(e){const x=e&&e.responseJSON?e.responseJSON:{};$('#gedMessage').text(x.message||(e&&e.message)||G['erro.analisar']);}
  finally{emAndamento=false;b.prop('disabled',false);$(document).trigger('gedAnaliseConcluida');load();}}
$('#gedAnalyze').on('click',()=>analisar(false));
// Automático: na abertura, a evidência mais recente (sem análise, ou com análise aguardando aprovação); e cada arquivo enviado.
let nomes=null,abertura=!new URLSearchParams(location.search).get('aba');
$(document).ajaxSuccess(function(ev,xhr,opt,d){
  if(!canAnalyze||!d||!Array.isArray(d.files)||String(opt.url||'').indexOf(api)<0)return;
  const atuais=d.files.map(f=>String(f.name)),enviou=(typeof FormData!=='undefined'&&opt.data instanceof FormData&&opt.data.get('action')==='upload');
  if(enviou&&nomes){const novo=atuais.filter(n=>nomes.indexOf(n)<0&&analisavel(n))[0];nomes=atuais;if(novo)setTimeout(()=>{selected=novo;render();analisar(false,novo);},400);return;}
  nomes=atuais;
  if(!abertura)return;abertura=false;
  const recente=d.files.filter(f=>analisavel(f.name)).sort((x,y)=>String(y.created_at||'').localeCompare(String(x.created_at||'')))[0];
  if(!recente)return;const st=String((d.analisados||{})[recente.name]||'');
  if(st===''||st==='CONCLUIDA')setTimeout(()=>{selected=String(recente.name);render();analisar(false,selected);},300);
});
})();
<?php endif; ?>
<?php if ($tipo === 'funcionariocriterios'): ?>
// Análise por IA das evidências do critério do funcionário (Portaria › Documentação de Funcionário): api/funcionariocriterios_ia.php.
// Usar a IA exige "Editar" na transação PORTARIA_DOCUMENTACAO_FUNCIONARIO (canAnalyze).
window.gedAnalyzeButton=$('<button type="button" class="btn btn-sm btn-outline-success ms-1" id="gedAnalyze" disabled title="'+esc(G['ia.botao_dica'])+'" aria-label="'+esc(G['ia.botao_dica'])+'"><i class="bi bi-stars me-1"></i>'+esc(G['ia.botao'])+'</button>').appendTo('.crud-actions');
window.gedModeloHtml=window.gedModeloHtml||function(m){if(!m||typeof m!=='object')return'';const cor={SIM:'success',NAO:'danger',INCERTO:'warning'},rc={CONFORME:'success',NAO_CONFORME:'danger',INCERTO:'warning'};const linha=(t,tag,a,ev)=>'<tr><td><span class="badge text-bg-'+(cor[a]||'secondary')+'">'+esc(G['mod.'+a]||a)+'</span></td><td>'+esc(t)+' <span class="text-muted">('+esc(tag)+')</span>'+(ev?'<div class="text-muted">'+esc(ev)+'</div>':'')+'</td></tr>';
  let h='<div class="mt-3 border rounded p-2"><div class="d-flex justify-content-between align-items-center small fw-semibold mb-1"><span>'+esc(G['mod.titulo'])+'</span><span class="badge text-bg-'+(rc[m.resultado]||'secondary')+'">'+esc(G['mod.res.'+m.resultado]||m.resultado||'')+' · '+esc(String(m.conformes||0))+'/'+esc(String(m.total||0))+'</span></div>';
  h+='<table class="table table-sm small mb-1"><tbody>'+(m.itens||[]).map(i=>linha(i.texto,i.obrigatorio?G['mod.obrigatorio']:G['mod.recomendado'],i.atende,i.evidencia)).join('')+(m.regras||[]).map(r=>linha(r.texto,G['mod.regra'],r.atende,r.detalhe)).join('')+'</tbody></table>';
  return h+(m.parecer?'<div class="small">'+esc(m.parecer)+'</div>':'')+'<div class="small text-muted">'+esc(G['mod.decisao'])+'</div></div>';};
(function(){
const dfuUrl='api/funcionariocriterios_ia.php';
const op=(v,t,s)=>'<option value="'+esc(v)+'"'+(String(v)===String(s??'')?' selected':'')+'>'+esc(t)+'</option>';
const campo=(k,label,v,type)=>'<div class="col-md-4"><label class="form-label small mb-0">'+esc(label)+'</label><input class="form-control form-control-sm" type="'+(type||'text')+'" data-dfu="'+k+'" value="'+esc(v??'')+'"></div>';
const info=(label,v)=>v?'<div class="col-md-4 small"><span class="text-muted">'+esc(label)+':</span> '+esc(v)+'</div>':'';
function abrir(r){
  const s=r.sugestao||{},c=r.cadastro||{},g=r.sugerido||{},tipo=s.tipo_documento||'OUTRO',conf=Number(r.confianca||0);
  let body='<div class="alert alert-info py-2 small">'+esc(G['dfu.aviso'])+'</div>';
  body+='<div class="row g-2 small mb-2"><div class="col-md-6"><strong>'+esc(G['dfu.exigido'])+':</strong> '+esc(c.criterio||'')+'<br>'+esc(c.funcionario||'')+(c.registro?' · '+esc(c.registro):'')+(c.funcao?' · '+esc(c.funcao):'')+'</div><div class="col-md-6"><strong>'+esc(G['fia.documento'])+':</strong> '+esc(G['fia.tipo.'+tipo]||tipo)+'<br>'+esc(s.nome||'—')+(s.cpf?' · '+esc(s.cpf):'')+'</div></div>';
  body+='<div class="row g-1 mb-2">'
    +(tipo==='ASO'?info(G['fia.aso_tipo_exame'],G['fia.exame.'+s.aso_tipo_exame]||s.aso_tipo_exame)+info(G['fia.aso_resultado'],G['fia.resultado.'+s.aso_resultado]||s.aso_resultado)+info(G['fia.aso_data_exame'],s.aso_data_exame)+info(G['fia.validade'],s.aso_validade)+info(G['fia.aso_medico'],s.aso_medico)+info(G['fia.aso_crm'],s.aso_crm):'')
    +(tipo==='TREINAMENTO'?info(G['fia.treinamento_nome'],s.treinamento_nome)+info(G['fia.treinamento_norma'],s.treinamento_norma)+info(G['fia.treinamento_carga_horaria'],s.treinamento_carga_horaria)+info(G['fia.treinamento_data_realizacao'],s.treinamento_data_realizacao)+info(G['fia.validade'],s.treinamento_validade)+info(G['fia.treinamento_instituicao'],s.treinamento_instituicao):'')
    +'</div>';
  body+='<div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="dfuAtualizar" data-dfu="atualizar"'+(tipo==='OUTRO'?'':' checked')+'><label class="form-check-label small fw-semibold" for="dfuAtualizar">'+esc(G['dfu.atualizar'])+'</label></div>';
  const atendeSel=g.atende||c.atende||'';
  const sitSel=g.situacao===''?'':'-';
  body+='<div class="row g-2 mt-1" data-dfu-bloco>'
    +campo('data_validade',G['dia.validade']+' ('+G['dia.atual']+': '+(c.validade||'—')+')',g.validade||c.validade,'date')
    +'<div class="col-md-4"><label class="form-label small mb-0">'+esc(G['dfu.atende'])+' ('+esc(G['dia.atual'])+': '+esc(c.atende||'—')+')</label><select class="form-select form-select-sm" data-dfu="atende">'+op('','—',atendeSel)+(r.atende_opcoes||[]).map(x=>op(x,x+(x===g.atende?' — '+G['dia.sugerida']:''),atendeSel)).join('')+'</select></div>'
    +'<div class="col-md-4"><label class="form-label small mb-0">'+esc(G['dfu.situacao'])+' ('+esc(G['dia.atual'])+': '+esc(c.situacao||'—')+')</label><select class="form-select form-select-sm" data-dfu="situacao">'+op('-',G['dfu.situacao_manter'],sitSel)+(r.situacoes||[]).map(x=>op(x,x===''?G['dfu.situacao_nenhuma']:x,sitSel)).join('')+'</select></div>'
    +'<div class="col-12"><label class="form-label small mb-0">'+esc(G['dfu.observacao'])+'</label><textarea class="form-control form-control-sm" rows="2" maxlength="500" data-dfu="observacao">'+esc(r.observacao_sugerida||c.observacao||'')+'</textarea></div></div>';
  body+='<div class="small text-muted mt-2">'+esc(G['ia.confianca'])+': '+esc((conf>0&&conf<=1?conf*100:conf).toFixed(0))+'% · '+esc(G['fia.fonte'])+': '+esc(G['fia.fonte.'+r.fonte_texto]||r.fonte_texto||'')+' · '+esc(r.arquivo||'')+'</div>';
  body+='<div class="mt-1"><label class="form-label small mb-0">'+esc(G['ia.alertas'])+'</label><div class="small text-warning">'+((s.alertas||[]).map(a=>'• '+esc(a)).join('<br>')||esc(G['ia.sem_alertas']))+'</div></div>';
  body+=window.gedModeloHtml(s.modelo);
  const enviar=(action,extra,botao)=>{botao.prop('disabled',true);return $.post(dfuUrl,{action,valor:params.valor,codanalise:r.codanalise,csrf:params.csrf,...extra},null,'json').done(x=>{$('#gedMessage').text(x.message||'');if(x.ok){window.gedRevisao.fechar(true);$(document).trigger('gedAnaliseConcluida');try{(window.top||window).postMessage({tipo:'gedDocumentoFuncionarioAtualizado',valor:params.valor},window.location.origin);}catch(e){}}}).fail(x=>$('#gedMessage').text((x.responseJSON||{}).message||G['erro.gravar'])).always(()=>botao.prop('disabled',false));};
  const painel=window.gedRevisao.abrir({titulo:G['dfu.revisao_titulo'],arquivo:r.arquivo,corpo:body,
    confirmar:function(painel,botao){const d={atualizar:painel.find('[data-dfu="atualizar"]').prop('checked')?1:0,tipo_documento:tipo,aso_resultado:s.aso_resultado||null,aso_data_exame:s.aso_data_exame||null,treinamento_nome:s.treinamento_nome||null,treinamento_norma:s.treinamento_norma||null,treinamento_data_realizacao:s.treinamento_data_realizacao||null};painel.find('[data-dfu-bloco] [data-dfu]').each(function(){d[$(this).data('dfu')]=String($(this).val()??'');});enviar('confirm',{dados:JSON.stringify(d)},botao);},
    descartar:function(painel,botao){enviar('discard',{},botao);}});
  const bloco=()=>painel.find('[data-dfu-bloco]').find('input,select,textarea').prop('disabled',!painel.find('[data-dfu="atualizar"]').prop('checked'));
  painel.on('change','[data-dfu="atualizar"]',bloco);bloco();
}
window.gedReabrirAnalise=function(a){if(!a.referencia){$('#gedMessage').text(G['fia.erro']);return;}$.post(dfuUrl,{action:'review',valor:params.valor,codanalise:a.referencia,csrf:params.csrf},null,'json').done(x=>{if(x&&x.ok)abrir(x);else $('#gedMessage').text((x&&x.message)||G['fia.erro']);}).fail(x=>$('#gedMessage').text((x.responseJSON||{}).message||G['fia.erro']));};
async function analisar(forcar){if(!selected)return;const b=$('#gedAnalyze').prop('disabled',true);$('#gedMessage').text(G['fia.analisando']);try{const extraction=await extracaoGedFila('funcionariocriterios',params.valor,selected);const base={action:'analyze',valor:params.valor,arquivo:selected,texto:extraction.texto,fonte:extraction.fonte,confianca_texto:extraction.confianca,csrf:params.csrf,forcar:forcar?1:0};let r=await $.ajax({url:dfuUrl,method:'POST',dataType:'json',timeout:240000,data:base});for(let i=0;i<180&&r.queued;i++){await new Promise(resolve=>setTimeout(resolve,1000));r=await $.ajax({url:dfuUrl,method:'POST',dataType:'json',timeout:240000,data:{...base,ia_job_id:r.ia_job_id}});}if(!r||!r.ok)throw new Error((r&&r.message)||G['fia.erro']);if(r.duplicado){window.gedTratarDuplicado(r,()=>analisar(true));return;}$('#gedMessage').text(r.reaproveitada?G['ia.reaproveitada']:G['ia.sugestao_gerada']);abrir(r);}catch(e){$('#gedMessage').text(e.message||G['fia.erro']);}finally{b.prop('disabled',false);$(document).trigger('gedAnaliseConcluida');load();}}
$('#gedAnalyze').on('click',()=>analisar(false));
})();
<?php endif; ?>
// Zoom da visualização do arquivo (botões − / + / Ajustar), para PDF e imagem.
$(function(){
  const pane=$('#gedPreviewPane');if(!pane.length||!window.gedShowPreview)return;
  const niveis=[25,50,75,100,125,150,175,200,250,300,400];let zoom=100,urlPdf='';
  const barra=$('<div class="ged-zoom d-flex align-items-center gap-1 mb-1 d-none" hidden><button type="button" class="btn btn-sm btn-outline-secondary" data-zoom="-" title="'+esc(G['zoom.menos'])+'" aria-label="'+esc(G['zoom.menos'])+'"><i class="bi bi-zoom-out"></i></button><span class="small text-muted text-center" style="min-width:3.5rem" data-zoom-valor>100%</span><button type="button" class="btn btn-sm btn-outline-secondary" data-zoom="+" title="'+esc(G['zoom.mais'])+'" aria-label="'+esc(G['zoom.mais'])+'"><i class="bi bi-zoom-in"></i></button><button type="button" class="btn btn-sm btn-outline-secondary" data-zoom="0" title="'+esc(G['zoom.ajustar'])+'" aria-label="'+esc(G['zoom.ajustar'])+'"><i class="bi bi-arrows-angle-contract"></i></button></div>').prependTo(pane);
  const img=$('#gedPreviewImage'),frame=$('#gedPreviewFrame');
  const caixa=$('<div class="ged-zoom-caixa" style="flex:1 1 auto;min-height:0;overflow:auto;display:block"></div>').insertBefore(img);img.appendTo(caixa);caixa.prop('hidden',true);
  function aplicar(){barra.find('[data-zoom-valor]').text(zoom+'%');
    if(!img.prop('hidden')&&img.attr('src')){const nw=img[0].naturalWidth||1,nh=img[0].naturalHeight||1,cw=caixa[0].clientWidth||pane.width(),ch=caixa[0].clientHeight;let f=cw/nw;if(ch>50)f=Math.min(f,ch/nh);const base=Math.max(40,Math.round(Math.min(f,1)*nw*zoom/100));img.css({width:base+'px',height:'auto',maxWidth:'none',maxHeight:'none',objectFit:'fill',margin:(base<cw?'0 auto':'0')});}
    if(!frame.prop('hidden')&&urlPdf){const u=urlPdf+(urlPdf.indexOf('?')>=0?'&':'?')+'zoom='+zoom+(zoom===100?'#view=FitH':'#zoom='+zoom);frame.attr('src',u);}}
  barra.on('click','[data-zoom]',function(){const d=$(this).data('zoom');if(d==='0'||d===0){zoom=100;}else{let i=niveis.indexOf(zoom);if(i<0)i=niveis.indexOf(100);i=Math.max(0,Math.min(niveis.length-1,i+(d==='+'?1:-1)));zoom=niveis[i];}aplicar();});
  // Ctrl + roda do mouse sobre a imagem também aproxima/afasta.
  caixa.on('wheel',function(e){if(!e.ctrlKey)return;e.preventDefault();barra.find('[data-zoom="'+(e.originalEvent.deltaY<0?'+':'-')+'"]').trigger('click');});
  const mostrar=window.gedShowPreview;
  window.gedShowPreview=function(name){mostrar(name);zoom=100;const pdf=!frame.prop('hidden'),imagem=!img.prop('hidden');urlPdf=pdf?String(frame.attr('src')||'').split('#')[0]:'';caixa.prop('hidden',!imagem);barra.prop('hidden',!imagem).toggleClass('d-none',!imagem);if(pdf)frame.attr('src',urlPdf+'#view=FitH');barra.find('[data-zoom-valor]').text('100%');if(imagem){const ajustar=()=>aplicar();if(img[0].complete&&img[0].naturalWidth)ajustar();else img.one('load',ajustar);}};
  const reset=window.gedResetPreview;if(reset)window.gedResetPreview=function(){reset();barra.prop('hidden',true).addClass('d-none');caixa.prop('hidden',true);urlPdf='';};
});
<?php if (in_array($tipo, ['licencas','controles','infraestrutura','treinamentos','funcionarios','fornecedorcriterios','funcionariocriterios','planodeacao'], true)): ?>
// Aba "Análises de IA": histórico das análises do arquivo selecionado ou de todos os arquivos, mais recentes primeiro.
(function(){
  $('<li class="nav-item" role="presentation"><button class="nav-link" id="gedAnalysesTab" type="button" data-ged-tab="analyses" role="tab" aria-selected="false">'+esc(G['aba.analises'])+'</button></li>').appendTo('#gedTabs');
  // Aba "Revisão dos dados": os dados extraídos pela IA são revisados e confirmados aqui (não mais em popup).
  $('<li class="nav-item" role="presentation"><button class="nav-link" id="gedReviewTab" type="button" data-ged-tab="review" role="tab" aria-selected="false" disabled>'+esc(G['aba.revisao'])+'</button></li>').appendTo('#gedTabs');
  const revisao=$('<div class="ged-tab-pane" id="gedReviewPane" role="tabpanel"><div class="ged-revisao-scroll" style="flex:1 1 auto;min-height:0;overflow-y:auto;overflow-x:hidden;padding-right:4px"><div class="d-flex align-items-baseline gap-2 mb-2"><h6 class="mb-0" data-revisao-titulo></h6><span class="small text-muted" data-revisao-arquivo></span></div><div data-revisao-corpo><div class="text-muted small">'+esc(G['revisao.vazia'])+'</div></div></div><div class="d-flex gap-2 justify-content-end border-top pt-2 mt-2" data-revisao-acoes hidden><button type="button" class="btn btn-sm btn-outline-danger me-auto" data-revisao-descartar hidden>'+esc(G['fia.botao_descartar'])+'</button><button type="button" class="btn btn-sm btn-outline-secondary" data-revisao-cancelar>'+esc(G['botao.cancelar'])+'</button><button type="button" class="btn btn-sm btn-success" data-revisao-confirmar>'+esc(G['botao.confirmar_gravar'])+'</button></div></div>').appendTo('.ged-tab-content');
  let atual=null;
  window.gedRevisao={
    abrir(o){atual=o;revisao.find('[data-revisao-titulo]').text(o.titulo||G['ia.revisao_titulo']);revisao.find('[data-revisao-arquivo]').text(o.arquivo||'');const corpo=revisao.find('[data-revisao-corpo]').html(o.corpo||'');revisao.find('[data-revisao-acoes]').prop('hidden',false);revisao.find('[data-revisao-descartar]').prop('hidden',!o.descartar||!!o.somenteLeitura);revisao.find('[data-revisao-confirmar]').prop('hidden',!!o.somenteLeitura);revisao.find('[data-revisao-cancelar]').text(o.somenteLeitura?G['botao.fechar']:G['botao.cancelar']);$('#gedReviewTab').prop('disabled',false).trigger('click');return corpo;},
    fechar(irParaAnalises){atual=null;revisao.find('[data-revisao-titulo],[data-revisao-arquivo]').text('');revisao.find('[data-revisao-corpo]').html('<div class="text-muted small">'+esc(G['revisao.vazia'])+'</div>');revisao.find('[data-revisao-acoes]').prop('hidden',true);if(irParaAnalises)$('#gedAnalysesTab').trigger('click');}
  };
  revisao.on('click','[data-revisao-confirmar]',function(){if(atual&&atual.confirmar)atual.confirmar(revisao.find('[data-revisao-corpo]'),$(this));});
  revisao.on('click','[data-revisao-descartar]',function(){if(atual&&atual.descartar)atual.descartar(revisao.find('[data-revisao-corpo]'),$(this));});
  revisao.on('click','[data-revisao-cancelar]',function(){window.gedRevisao.fechar(true);});
  const pane=$('<div class="ged-tab-pane" id="gedAnalysesPane" role="tabpanel"><div class="d-flex align-items-center gap-2 mb-2 flex-wrap"><div class="btn-group btn-group-sm" role="group"><input type="radio" class="btn-check" name="gedAnalisesModo" id="gedAnalisesArquivo" value="arquivo" checked><label class="btn btn-outline-secondary" for="gedAnalisesArquivo">'+esc(G['analises.arquivo'])+'</label><input type="radio" class="btn-check" name="gedAnalisesModo" id="gedAnalisesTodos" value="todos"><label class="btn btn-outline-secondary" for="gedAnalisesTodos">'+esc(G['analises.todos'])+'</label></div><button type="button" class="btn btn-sm btn-outline-primary" id="gedAnalisesAtualizar" title="'+esc(G['analises.atualizar'])+'"><i class="bi bi-arrow-clockwise"></i></button><span class="small text-muted" id="gedAnalisesInfo"></span></div><div class="menu-grid-scroll"><table class="table table-sm table-hover menu-batch-grid" id="gedAnalyses"><thead><tr><th>'+esc(G['analises.col.data'])+'</th><th>'+esc(G['analises.col.arquivo'])+'</th><th>'+esc(G['analises.col.situacao'])+'</th><th>'+esc(G['analises.col.resultado'])+'</th><th>'+esc(G['analises.col.confianca'])+'</th><th>'+esc(G['analises.col.usuario'])+'</th></tr></thead><tbody></tbody></table></div></div>').appendTo('.ged-tab-content');
  const fmt=v=>{if(!v)return '';const d=new Date(String(v).replace(' ','T'));return isNaN(d)?String(v):d.toLocaleString(document.documentElement.lang||'pt-BR',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});};
  const cor={PROCESSANDO:'secondary',CONCLUIDA:'info',ERRO:'danger',CONFIRMADA:'success',DESCARTADA:'dark'};
  let lista=[];
  function detalhes(a){const d=a.dados_ia&&typeof a.dados_ia==='object'?a.dados_ia:{};let h='<dl class="row small mb-0">';Object.keys(d).forEach(k=>{let v=d[k];if(v===null||v===''||(Array.isArray(v)&&!v.length))return;if(Array.isArray(v))v=v.join('; ');else if(typeof v==='object')v=JSON.stringify(v);h+='<dt class="col-sm-4 text-muted fw-normal">'+esc(k)+'</dt><dd class="col-sm-8 mb-1">'+esc(v)+'</dd>';});if(a.mensagem)h+='<dt class="col-sm-4 text-muted fw-normal">'+esc(G['analises.col.resultado'])+'</dt><dd class="col-sm-8 mb-1 text-danger">'+esc(a.mensagem)+'</dd>';if(a.fonte_texto)h+='<dt class="col-sm-4 text-muted fw-normal">'+esc(G['fia.fonte']||'Leitura')+'</dt><dd class="col-sm-8 mb-1">'+esc(cod(a.fonte_texto))+'</dd>';if(a.confirmado_em)h+='<dt class="col-sm-4 text-muted fw-normal">'+esc(G['analises.confirmado_por'])+'</dt><dd class="col-sm-8 mb-1">'+esc((a.usuario_confirmacao||'')+' · '+fmt(a.confirmado_em))+'</dd>';h+='</dl>';return h;}
  function desenhar(){let h='';lista.forEach((a,i)=>{const conf=a.confianca!==null&&a.confianca!==undefined&&a.confianca!==''?Math.round(Number(a.confianca))+'%':'';h+='<tr data-analise="'+i+'" style="cursor:pointer"><td>'+esc(fmt(a.solicitado_em))+'</td><td>'+esc(a.arquivo)+'</td><td><span class="badge text-bg-'+(cor[a.status]||'secondary')+'">'+esc(cod(a.status))+'</span></td><td>'+esc(a.status==='ERRO'?(a.mensagem||''):(a.resumo||''))+'</td><td>'+esc(conf)+'</td><td>'+esc(a.usuario||'')+'</td></tr>';});if(!h)h='<tr><td colspan="6" class="text-muted">'+esc(G['analises.nenhuma'])+'</td></tr>';$('#gedAnalyses tbody').html(h);$('#gedAnalisesInfo').text(lista.length?G['analises.detalhes']:'');}
  function carregar(){const modo=$('[name="gedAnalisesModo"]:checked').val();if(modo==='arquivo'&&!selected){lista=[];$('#gedAnalyses tbody').html('<tr><td colspan="6" class="text-muted">'+esc(G['analises.selecione'])+'</td></tr>');$('#gedAnalisesInfo').text('');return;}$('#gedAnalyses tbody').html('<tr><td colspan="6" class="text-muted">'+esc(G['msg.carregando'])+'</td></tr>');$.getJSON(api,{...params,action:'analises',arquivo:modo==='arquivo'?selected:''}).done(r=>{lista=(r&&r.analises)||[];desenhar();}).fail(x=>$('#gedAnalyses tbody').html('<tr><td colspan="6" class="text-danger">'+esc((x.responseJSON||{}).message||G['erro.carregar'])+'</td></tr>'));}
  const ativa=()=>$('#gedAnalysesPane').hasClass('active');
  $('#gedAnalyses').on('click','tbody tr[data-analise]',function(){const tr=$(this),a=lista[Number(tr.data('analise'))];if(!a)return;$('#gedAnalyses tbody tr').removeClass('table-active');tr.addClass('table-active');
    if(a.status==='CONCLUIDA'&&window.gedReabrirAnalise&&canAnalyze){window.gedReabrirAnalise(a);return;}
    window.gedMostrarAnalise(a);});
  window.gedMostrarAnalise=function(a){
    const sit='<div class="mb-2"><span class="badge text-bg-'+(cor[a.status]||'secondary')+'">'+esc(cod(a.status))+'</span> <span class="small text-muted">'+esc(fmt(a.solicitado_em))+(a.usuario?' · '+esc(a.usuario):'')+(a.confianca!==null&&a.confianca!==undefined&&a.confianca!==''?' · '+esc(G['analises.col.confianca'])+' '+Math.round(Number(a.confianca))+'%':'')+'</span></div>';
    window.gedRevisao.abrir({titulo:G['analises.dados_extraidos'],arquivo:a.arquivo,corpo:sit+(a.resumo?'<div class="mb-2"><strong>'+esc(a.resumo)+'</strong></div>':'')+detalhes(a),somenteLeitura:true});};
  pane.on('change','[name="gedAnalisesModo"]',carregar);$('#gedAnalisesAtualizar').on('click',carregar);
  $(document).on('gedAbaAtivada',(e,tab)=>{if(tab==='analyses')carregar();}).on('gedArquivoSelecionado gedAnaliseConcluida',()=>{if(ativa())carregar();});
  // Link dos alertas: ?aba=analises&arquivo=... abre direto nesta aba, com o arquivo selecionado.
  const url=new URLSearchParams(location.search);
  if(url.get('aba')==='analises')$(document).one('gedArquivosCarregados',function(){const nome=url.get('arquivo')||'';if(nome&&(data.files||[]).some(f=>String(f.name)===nome)){selected=nome;render();}else $('#gedAnalisesTodos').prop('checked',true);$('#gedAnalysesTab').trigger('click');});
})();
<?php endif; ?>
if(iaTipo==='licencas')$('#gedInput').attr('accept','.pdf,.jpg,.jpeg,.png,.tif,.tiff,.bmp,.doc,.docx');load().always(()=>$(document).trigger('gedArquivosCarregados'));
$(function(){var previewLabel=G['previa.titulo'];$('#gedPreviewTab').text(previewLabel);$('#gedPreviewFrame').attr('title',previewLabel);$('#gedPreviewImage').attr('alt',previewLabel);});
// Sem "Baixar/imprimir arquivos" (migração 266): a imagem da visualização não tem menu de contexto nem arrastar.
$(document).on('contextmenu dragstart','#gedPreviewImage',function(e){const fSel=(data.files||[]).find(x=>String(x.name)===String(selected));if(!(fSel&&fSel.download))e.preventDefault();});
</script><?php
// Migração 349: aba Adulteração (documentos de fornecedor e de funcionário da homologação; fornecedor externo não vê)
require_once dirname(__DIR__) . '/classes/HomologacaoFornecedorAcesso.php';
if (in_array($tipo, ['fornecedorcriterios', 'funcionariocriterios'], true) && !HomologacaoFornecedorAcesso::externo()):
    $adPadrao = [
        'aba' => ['pt' => 'Adulteração', 'en' => 'Tampering', 'es' => 'Adulteración'],
        'analisar' => ['pt' => 'Analisar este arquivo', 'en' => 'Analyze this file', 'es' => 'Analizar este archivo'],
        'analisar_dica' => ['pt' => 'Pedir a análise de adulteração do arquivo selecionado (arquivos novos são analisados sozinhos)', 'en' => 'Request the tampering analysis of the selected file (new files are analyzed automatically)', 'es' => 'Pedir el análisis de adulteración del archivo seleccionado (los archivos nuevos se analizan solos)'],
        'atualizar' => ['pt' => 'Atualizar', 'en' => 'Refresh', 'es' => 'Actualizar'],
        'info' => ['pt' => 'Arquivos novos são analisados sozinhos. Selecione um arquivo na Lista de arquivos para pedir a análise de um arquivo antigo.', 'en' => 'New files are analyzed automatically. Select a file in the File list to request the analysis of an older file.', 'es' => 'Los archivos nuevos se analizan solos. Seleccione un archivo en la Lista de archivos para pedir el análisis de un archivo antiguo.'],
        'selecione' => ['pt' => 'Selecione um arquivo na Lista de arquivos', 'en' => 'Select a file in the File list', 'es' => 'Seleccione un archivo en la Lista de archivos'],
        'ja_analisado' => ['pt' => 'Este arquivo já foi analisado (ou está na fila)', 'en' => 'This file was already analyzed (or is queued)', 'es' => 'Este archivo ya fue analizado (o está en la cola)'],
        'sem_permissao' => ['pt' => 'Seu perfil só consulta a análise', 'en' => 'Your profile can only view the analysis', 'es' => 'Su perfil solo consulta el análisis'],
        'col.data' => ['pt' => 'Data', 'en' => 'Date', 'es' => 'Fecha'], 'col.arquivo' => ['pt' => 'Arquivo', 'en' => 'File', 'es' => 'Archivo'],
        'col.risco' => ['pt' => 'Risco', 'en' => 'Risk', 'es' => 'Riesgo'], 'col.resumo' => ['pt' => 'Resumo', 'en' => 'Summary', 'es' => 'Resumen'], 'col.ciencia' => ['pt' => 'Ciência', 'en' => 'Acknowledgment', 'es' => 'Conocimiento'],
        'risco.ALTO' => ['pt' => 'Risco alto', 'en' => 'High risk', 'es' => 'Riesgo alto'], 'risco.MEDIO' => ['pt' => 'Risco médio', 'en' => 'Medium risk', 'es' => 'Riesgo medio'],
        'risco.BAIXO' => ['pt' => 'Risco baixo', 'en' => 'Low risk', 'es' => 'Riesgo bajo'], 'risco.SEM_INDICIOS' => ['pt' => 'Sem indícios', 'en' => 'No signs', 'es' => 'Sin indicios'],
        'grav.ALTA' => ['pt' => 'Alta', 'en' => 'High', 'es' => 'Alta'], 'grav.MEDIA' => ['pt' => 'Média', 'en' => 'Medium', 'es' => 'Media'], 'grav.BAIXA' => ['pt' => 'Baixa', 'en' => 'Low', 'es' => 'Baja'], 'grav.INFO' => ['pt' => 'Informação', 'en' => 'Info', 'es' => 'Información'],
        'grupo.ASSINATURA' => ['pt' => 'Assinatura digital', 'en' => 'Digital signature', 'es' => 'Firma digital'], 'grupo.METADADOS' => ['pt' => 'Metadados do arquivo', 'en' => 'File metadata', 'es' => 'Metadatos del archivo'],
        'grupo.IMAGEM' => ['pt' => 'Imagem', 'en' => 'Image', 'es' => 'Imagen'], 'grupo.CONTEUDO' => ['pt' => 'Conteúdo (regras fixas)', 'en' => 'Content (fixed rules)', 'es' => 'Contenido (reglas fijas)'], 'grupo.IA' => ['pt' => 'Conteúdo (leitura da IA)', 'en' => 'Content (AI reading)', 'es' => 'Contenido (lectura de la IA)'],
        'na_fila' => ['pt' => 'Na fila', 'en' => 'Queued', 'es' => 'En la cola'], 'erro' => ['pt' => 'Não analisado', 'en' => 'Not analyzed', 'es' => 'No analizado'],
        'arquivo_excluido' => ['pt' => 'arquivo excluído', 'en' => 'file deleted', 'es' => 'archivo eliminado'],
        'nenhuma' => ['pt' => 'Nenhuma análise de adulteração neste documento.', 'en' => 'No tampering analysis for this document.', 'es' => 'Ningún análisis de adulteración en este documento.'],
        'indisponivel' => ['pt' => 'A análise de adulteração ainda não foi instalada nesta empresa (migração 349).', 'en' => 'The tampering analysis is not installed for this company yet (migration 349).', 'es' => 'El análisis de adulteración aún no fue instalado en esta empresa (migración 349).'],
        'clique' => ['pt' => 'Clique numa linha para ver os indícios.', 'en' => 'Click a row to see the signs.', 'es' => 'Haga clic en una fila para ver los indicios.'],
        'sem_indicios' => ['pt' => 'Nenhum indício encontrado nas verificações.', 'en' => 'No signs found by the checks.', 'es' => 'Ningún indicio encontrado en las verificaciones.'],
        'origem.AUTOMATICA' => ['pt' => 'Automática (arquivo novo)', 'en' => 'Automatic (new file)', 'es' => 'Automático (archivo nuevo)'], 'origem.MANUAL' => ['pt' => 'Pedida na tela', 'en' => 'Requested on screen', 'es' => 'Pedido en pantalla'],
        'com_ia' => ['pt' => 'com leitura da IA', 'en' => 'with AI reading', 'es' => 'con lectura de la IA'],
        'tec.titulo' => ['pt' => 'Dados técnicos do arquivo', 'en' => 'File technical data', 'es' => 'Datos técnicos del archivo'], 'tec.produtor' => ['pt' => 'Programa que gerou', 'en' => 'Producer', 'es' => 'Programa que generó'],
        'tec.criador' => ['pt' => 'Programa de origem', 'en' => 'Creator', 'es' => 'Programa de origen'], 'tec.criado' => ['pt' => 'Criado em', 'en' => 'Created', 'es' => 'Creado'], 'tec.modificado' => ['pt' => 'Modificado em', 'en' => 'Modified', 'es' => 'Modificado'],
        'tec.revisoes' => ['pt' => 'Gravações do arquivo', 'en' => 'File saves', 'es' => 'Grabaciones del archivo'], 'tec.modificado_por' => ['pt' => 'Modificado por', 'en' => 'Modified by', 'es' => 'Modificado por'],
        'tec.software' => ['pt' => 'Programas', 'en' => 'Software', 'es' => 'Programas'], 'tec.camera' => ['pt' => 'Câmera', 'en' => 'Camera', 'es' => 'Cámara'],
        'ciencia_alto' => ['pt' => 'Para aprovar este documento, registre a ciência: por que ele pode ser aceito apesar dos indícios?', 'en' => 'To approve this document, record the acknowledgment: why can it be accepted despite the signs?', 'es' => 'Para aprobar este documento, registre el conocimiento: ¿por qué puede aceptarse a pesar de los indicios?'],
        'ciencia_medio' => ['pt' => 'Registrar a ciência (opcional): o que foi conferido?', 'en' => 'Record the acknowledgment (optional): what was checked?', 'es' => 'Registrar el conocimiento (opcional): ¿qué se revisó?'],
        'motivo_dica' => ['pt' => 'Ex.: conferido com o emissor pelo código de autenticação; o PDF foi só comprimido pelo fornecedor.', 'en' => 'E.g.: checked with the issuer by the authentication code; the supplier only compressed the PDF.', 'es' => 'Ej.: verificado con el emisor por el código de autenticación; el proveedor solo comprimió el PDF.'],
        'motivo_curto' => ['pt' => 'Escreva o motivo (pelo menos 10 caracteres).', 'en' => 'Write the reason (at least 10 characters).', 'es' => 'Escriba el motivo (al menos 10 caracteres).'],
        'registrar_ciencia' => ['pt' => 'Registrar ciência', 'en' => 'Record acknowledgment', 'es' => 'Registrar conocimiento'],
        'ciente' => ['pt' => 'ciência registrada', 'en' => 'acknowledged', 'es' => 'conocimiento registrado'],
        'ciente_por' => ['pt' => 'Ciência de {n} em {d}:', 'en' => 'Acknowledged by {n} on {d}:', 'es' => 'Conocimiento de {n} el {d}:'],
        'pendente_ciencia' => ['pt' => 'Falta a ciência', 'en' => 'Needs acknowledgment', 'es' => 'Falta el conocimiento'],
        'pendente_ciencia_info' => ['pt' => 'Risco alto: o documento só pode ser aprovado depois da ciência com o motivo, por quem pode editar a documentação.', 'en' => 'High risk: the document can only be approved after the acknowledgment with the reason, by someone who can edit the documentation.', 'es' => 'Riesgo alto: el documento solo puede aprobarse después del conocimiento con el motivo, por quien puede editar la documentación.'],
        'so_aponta' => ['pt' => 'A análise só aponta indícios: confira o arquivo (e, se preciso, o emissor). A decisão do documento é sua.', 'en' => 'The analysis only points out signs: check the file (and the issuer if needed). The decision on the document is yours.', 'es' => 'El análisis solo señala indicios: revise el archivo (y, si es necesario, el emisor). La decisión sobre el documento es suya.'],
        'carregando' => ['pt' => 'Carregando...', 'en' => 'Loading...', 'es' => 'Cargando...'], 'erro_carregar' => ['pt' => 'Não foi possível carregar.', 'en' => 'Could not load.', 'es' => 'No fue posible cargar.'],
    ];
    $adTextos = []; foreach ($adPadrao as $adK => $adV) $adTextos[$adK] = IdiomaService::t('gestao_arquivos.adult.' . $adK, $adV);
?><script>window.gedAdulteracaoTextos=<?= json_encode($adTextos, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script><script src="../assets/js/ged-adulteracao.js?v=1"></script><?php endif; ?><?php require_once dirname(__DIR__) . '/components/toolbar_grupos.php'; greenPortalToolbarGruposAuto(); ?></body></html>
