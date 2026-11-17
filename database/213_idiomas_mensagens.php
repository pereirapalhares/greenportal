<?php
declare(strict_types=1);

/*
 * Carga da migração 213 — mensagens de erro e de retorno do servidor.
 * Cada texto: [chave, tipo, onde aparece, pt, en, es]
 *
 * Grupo BANCO: avisos gerados pelas procedures do banco (SIGNAL). A chave é
 * banco.m + 12 primeiros caracteres do md5 da mensagem original, para que o texto em
 * português possa ser editado sem perder a ligação (IdiomaService::mensagemBanco).
 * Use {acao} onde a mensagem cita a operação ("salvar o registro", "excluir o registro"...).
 */

$banco = static function (string $original, string $pt, string $en, string $es): array {
    return ['banco.m' . substr(md5(trim($original)), 0, 12), 'MENSAGEM', 'Aviso do banco: "' . $original . '"', $pt, $en, $es];
};

return [
    'grupos' => [
        ['BANCO', 'Mensagens do banco de dados', 'Database messages', 'Mensajes de la base de datos', '', 30],
    ],
    'textos' => [
        'COMUM' => [
            ['comum.erro.sessao', 'MENSAGEM', 'Sessão expirada ou empresa inválida', 'Sua sessão expirou. Entre novamente no portal.', 'Your session has expired. Please sign in again.', 'Su sesión expiró. Vuelva a entrar al portal.'],
            ['comum.erro.sem_permissao', 'MENSAGEM', 'Usuário sem permissão para a transação', 'Seu perfil não tem permissão para acessar esta transação.', 'Your profile is not allowed to access this transaction.', 'Su perfil no tiene permiso para acceder a esta transacción.'],
            ['comum.erro.sem_permissao_acao', 'MENSAGEM', 'Usuário sem permissão para a ação (incluir, alterar, excluir)', 'Seu perfil não tem permissão para esta ação.', 'Your profile is not allowed to perform this action.', 'Su perfil no tiene permiso para esta acción.'],
            ['comum.erro.somente_consulta', 'MENSAGEM', 'Tentativa de gravar numa grade somente de consulta', 'Esta grade é somente para consulta.', 'This grid is read-only.', 'Esta cuadrícula es solo de consulta.'],
            ['comum.erro.banco_indisponivel', 'MENSAGEM', 'Banco de dados fora do ar', 'Banco de dados indisponível. Tente novamente em instantes.', 'Database unavailable. Please try again shortly.', 'Base de datos no disponible. Inténtelo de nuevo en unos instantes.'],
            ['comum.erro.acao_invalida', 'MENSAGEM', 'Ação ou parâmetro inválido enviado ao servidor', 'Ação inválida.', 'Invalid action.', 'Acción inválida.'],
            ['comum.erro.duplicado', 'MENSAGEM', 'Registro com código repetido ({acao} = a operação)', 'Não foi possível {acao} porque já existe um cadastro com o mesmo código ou identificação. Informe um valor diferente e tente novamente.', 'We could not {acao} because a record with the same code or identifier already exists. Enter a different value and try again.', 'No fue posible {acao} porque ya existe un registro con el mismo código o identificación. Informe otro valor e inténtelo nuevamente.'],
            ['comum.erro.vinculo', 'MENSAGEM', 'Registro ligado a outras informações ({acao} = a operação)', 'Não foi possível {acao} porque este registro está relacionado a outras informações. Revise os vínculos ou remova as dependências antes de continuar.', 'We could not {acao} because this record is related to other information. Review the links or remove the dependencies before continuing.', 'No fue posible {acao} porque este registro está relacionado con otra información. Revise los vínculos o elimine las dependencias antes de continuar.'],
            ['comum.erro.obrigatorio', 'MENSAGEM', 'Campo obrigatório vazio ({acao} = a operação)', 'Não foi possível {acao} porque há campos obrigatórios sem preenchimento. Revise o formulário e tente novamente.', 'We could not {acao} because required fields are empty. Review the form and try again.', 'No fue posible {acao} porque hay campos obligatorios vacíos. Revise el formulario e inténtelo nuevamente.'],
            ['comum.erro.generico', 'MENSAGEM', 'Erro sem causa identificada ({acao} = a operação)', 'Não foi possível {acao}. Revise os dados informados e tente novamente. Se o problema continuar, solicite apoio ao administrador do sistema.', 'We could not {acao}. Review the information and try again. If the problem persists, contact the system administrator.', 'No fue posible {acao}. Revise la información e inténtelo nuevamente. Si el problema continúa, solicite ayuda al administrador del sistema.'],
            ['comum.acao.concluir', 'ROTULO', 'Operação citada nas mensagens de erro: concluir', 'concluir a operação', 'complete the operation', 'completar la operación'],
            ['comum.acao.salvar', 'ROTULO', 'Operação citada nas mensagens de erro: salvar', 'salvar o registro', 'save the record', 'guardar el registro'],
            ['comum.acao.excluir', 'ROTULO', 'Operação citada nas mensagens de erro: excluir', 'excluir o registro', 'delete the record', 'eliminar el registro'],
            ['comum.acao.salvar_menu', 'ROTULO', 'Operação citada nas mensagens de erro: salvar o menu', 'salvar o menu', 'save the menu', 'guardar el menú'],
            ['comum.acao.associar', 'ROTULO', 'Operação citada nas mensagens de erro: gravar associações', 'gravar as associações', 'save the associations', 'guardar las asociaciones'],
            ['comum.msg.registro_excluido', 'MENSAGEM', 'Aviso após excluir', 'Registro excluído com sucesso.', 'Record deleted successfully.', 'Registro eliminado correctamente.'],
            ['comum.msg.exportacao_pedido', 'MENSAGEM', 'Retorno do servidor ao pedir a exportação', 'A exportação foi solicitada e será disponibilizada nos alertas.', 'The export was requested and will be available in the alerts.', 'La exportación fue solicitada y estará disponible en las alertas.'],
            ['comum.http.titulo', 'TITULO', 'Página de erro: título da aba', 'Erro', 'Error', 'Error'],
            ['comum.http.400', 'MENSAGEM', 'Página de erro 400', 'A solicitação não pôde ser processada.', 'The request could not be processed.', 'La solicitud no pudo ser procesada.'],
            ['comum.http.403', 'MENSAGEM', 'Página de erro 403', 'Você não tem permissão para acessar este recurso.', 'You do not have permission to access this resource.', 'No tiene permiso para acceder a este recurso.'],
            ['comum.http.404', 'MENSAGEM', 'Página de erro 404', 'O recurso solicitado não foi encontrado.', 'The requested resource was not found.', 'No se encontró el recurso solicitado.'],
            ['comum.http.500', 'MENSAGEM', 'Página de erro 500', 'Ocorreu um erro interno ao processar a solicitação.', 'An internal error occurred while processing the request.', 'Ocurrió un error interno al procesar la solicitud.'],
            ['comum.http.502', 'MENSAGEM', 'Página de erro 502', 'O serviço intermediário não respondeu corretamente.', 'The intermediate service did not respond correctly.', 'El servicio intermedio no respondió correctamente.'],
            ['comum.http.503', 'MENSAGEM', 'Página de erro 503', 'O serviço está temporariamente indisponível.', 'The service is temporarily unavailable.', 'El servicio no está disponible temporalmente.'],
            ['comum.http.voltar', 'BOTAO', 'Página de erro: botão de volta', 'Voltar ao Green Portal', 'Back to Green Portal', 'Volver a Green Portal'],
        ],
        'MODULOLEGAL_CONTROLE' => [
            ['modulolegal_controle.erro.relacionamento', 'MENSAGEM', 'API de correlacionamento: tipo inválido', 'Relacionamento inválido.', 'Invalid relationship.', 'Relación inválida.'],
            ['modulolegal_controle.erro.documento', 'MENSAGEM', 'API do Controle: código de documento inválido', 'Documento inválido.', 'Invalid document.', 'Documento inválido.'],
            ['modulolegal_controle.erro.documento_nao_localizado', 'MENSAGEM', 'API do Controle: documento não existe', 'Documento não localizado.', 'Document not found.', 'Documento no encontrado.'],
            ['modulolegal_controle.erro.ver_relacionamentos', 'MENSAGEM', 'Sem permissão para ver correlacionamentos', 'Sem permissão para visualizar relacionamentos.', 'You are not allowed to view relationships.', 'Sin permiso para ver las relaciones.'],
            ['modulolegal_controle.erro.lista_relacionamentos', 'MENSAGEM', 'Lista de correlacionamentos recebida inválida', 'Lista de relacionamentos inválida.', 'Invalid relationship list.', 'Lista de relaciones inválida.'],
            ['modulolegal_controle.erro.sincronizar', 'MENSAGEM', 'Sem permissão para gravar correlacionamentos', 'Sem permissão para sincronizar relacionamentos.', 'You are not allowed to update relationships.', 'Sin permiso para actualizar las relaciones.'],
            ['modulolegal_controle.erro.remover', 'MENSAGEM', 'Sem permissão para remover correlacionamentos', 'Sem permissão para remover relacionamentos.', 'You are not allowed to remove relationships.', 'Sin permiso para quitar relaciones.'],
            ['modulolegal_controle.erro.incluir', 'MENSAGEM', 'Sem permissão para incluir correlacionamentos', 'Sem permissão para incluir relacionamentos.', 'You are not allowed to add relationships.', 'Sin permiso para agregar relaciones.'],
            ['modulolegal_controle.resp.titulo', 'TITULO', 'Popup de responsáveis: título', 'Responsáveis', 'Responsible parties', 'Responsables'],
            ['modulolegal_controle.resp.erro_ver', 'MENSAGEM', 'Sem permissão para ver responsáveis', 'Sem permissão para visualizar responsáveis.', 'You are not allowed to view responsible parties.', 'Sin permiso para ver los responsables.'],
            ['modulolegal_controle.resp.erro_alterar', 'MENSAGEM', 'Sem permissão para alterar responsáveis', 'Sem permissão para alterar responsáveis.', 'You are not allowed to change responsible parties.', 'Sin permiso para modificar los responsables.'],
            ['modulolegal_controle.resp.erro_remover', 'MENSAGEM', 'Sem permissão para remover responsáveis', 'Sem permissão para remover responsáveis.', 'You are not allowed to remove responsible parties.', 'Sin permiso para quitar responsables.'],
            ['modulolegal_controle.resp.erro_incluir', 'MENSAGEM', 'Sem permissão para incluir responsáveis', 'Sem permissão para incluir responsáveis.', 'You are not allowed to add responsible parties.', 'Sin permiso para agregar responsables.'],
            ['modulolegal_controle.resp.erro_lista', 'MENSAGEM', 'Lista de responsáveis recebida inválida', 'Lista de responsáveis inválida.', 'Invalid list of responsible parties.', 'Lista de responsables inválida.'],
            ['modulolegal_controle.resp.erro_modulo', 'MENSAGEM', 'Configuração de responsabilidades ausente (módulo)', 'Módulo de responsabilidades não configurado.', 'Responsibility module not configured.', 'Módulo de responsabilidades no configurado.'],
            ['modulolegal_controle.resp.erro_tipo', 'MENSAGEM', 'Configuração de responsabilidades ausente (tipo)', 'Tipo de responsabilidade não configurado.', 'Responsibility type not configured.', 'Tipo de responsabilidad no configurado.'],
        ],
        'BANCO' => [
            $banco('Campo nao autorizado na operacao de alteracao', 'Um dos campos não pode ser alterado nesta tela.', 'One of the fields cannot be changed on this screen.', 'Uno de los campos no puede modificarse en esta pantalla.'),
            $banco('Campo nao autorizado na operacao de inclusao', 'Um dos campos não pode ser preenchido na inclusão.', 'One of the fields cannot be filled in when adding.', 'Uno de los campos no puede completarse al agregar.'),
            $banco('Chave obrigatoria para exclusao', 'Selecione o registro que deseja excluir.', 'Select the record you want to delete.', 'Seleccione el registro que desea eliminar.'),
            $banco('Dados e chave sao obrigatorios para alteracao', 'Não há dados para alterar neste registro.', 'There is no data to change in this record.', 'No hay datos para modificar en este registro.'),
            $banco('Nenhum campo informado para inclusao', 'Preencha pelo menos um campo antes de salvar.', 'Fill in at least one field before saving.', 'Complete al menos un campo antes de guardar.'),
            $banco('Tabela nao autorizada ou inexistente', 'Esta operação não está liberada para esta tela. Avise o administrador do sistema.', 'This operation is not enabled for this screen. Contact the system administrator.', 'Esta operación no está habilitada para esta pantalla. Avise al administrador del sistema.'),
            $banco('Tabela ou view nao autorizada ou inexistente', 'Esta consulta não está liberada para esta tela. Avise o administrador do sistema.', 'This query is not enabled for this screen. Contact the system administrator.', 'Esta consulta no está habilitada para esta pantalla. Avise al administrador del sistema.'),
            $banco('A data final nao pode ser anterior a data inicial.', 'A data final não pode ser anterior à data inicial.', 'The end date cannot be earlier than the start date.', 'La fecha final no puede ser anterior a la fecha inicial.'),
            $banco('A data inicial da vigencia e obrigatoria.', 'Informe a data inicial da vigência.', 'Enter the start date of validity.', 'Informe la fecha inicial de vigencia.'),
            $banco('Grupo inexistente ou inativo.', 'O grupo escolhido não existe ou está inativo.', 'The selected group does not exist or is inactive.', 'El grupo elegido no existe o está inactivo.'),
            $banco('Informe somente um grupo ativo.', 'Informe somente um grupo ativo.', 'Choose only one active group.', 'Informe solo un grupo activo.'),
            $banco('Informe somente um usuario ativo.', 'Informe somente um usuário ativo.', 'Choose only one active user.', 'Informe solo un usuario activo.'),
            $banco('Ja existe vinculo ativo com vigencia sobreposta.', 'Já existe um vínculo ativo no mesmo período.', 'There is already an active link for the same period.', 'Ya existe un vínculo activo en el mismo período.'),
            $banco('Tipo de destino invalido.', 'Escolha se o responsável é um usuário ou um grupo.', 'Choose whether the responsible party is a user or a group.', 'Elija si el responsable es un usuario o un grupo.'),
            $banco('Usuario inexistente ou inativo.', 'O usuário escolhido não existe ou está inativo.', 'The selected user does not exist or is inactive.', 'El usuario elegido no existe o está inactivo.'),
        ],
    ],
];
