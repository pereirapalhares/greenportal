<?php
declare(strict_types=1);

/*
 * Base das telas de solicitações de melhoria (Minhas solicitações e Gestão de solicitações):
 * sessão, serviço, token csrf e textos da interface no idioma do usuário.
 * Quem inclui define $solicitacoesModo = 'minhas' ou 'gestao' antes do require.
 */

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: text/html; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/SolicitacaoMelhoriaService.php';

$solicitacoesPdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
if (!UsuarioSessao::atualizar($solicitacoesPdo, $_SESSION)) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
$solicitacoesServico = new SolicitacaoMelhoriaService($solicitacoesPdo, (array)$_SESSION['usuario'], (array)($_SESSION['permissoes'] ?? []), IdiomaService::idioma());
if (($solicitacoesModo ?? 'minhas') === 'gestao' && !$solicitacoesServico->podeGerir()) {
    http_response_code(403);
    exit(htmlspecialchars(IdiomaService::t('solicitacoes.erro.permissao', ['pt' => 'Seu perfil não pode gerir as solicitações.', 'en' => 'Your profile cannot manage requests.', 'es' => 'Su perfil no puede gestionar las solicitudes.']), ENT_QUOTES, 'UTF-8'));
}
try {
    $solicitacoesInstalado = !empty(AssistenteAplicacaoService::chamarProcedure($solicitacoesPdo, 'sp_ia_assistente_recursos')[0]['solicitacoes']);
} catch (Throwable) {
    $solicitacoesInstalado = false;
}
if (empty($_SESSION['solicitacoes_csrf'])) $_SESSION['solicitacoes_csrf'] = bin2hex(random_bytes(32));

$idiomaSolicitacoes = IdiomaService::idioma();
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$t = static fn(string $chave, string $pt, string $en, string $es): string => IdiomaService::t('solicitacoes.ui.' . $chave, ['pt' => $pt, 'en' => $en, 'es' => $es]);
$ui = [
    'titulo_minhas' => $t('titulo_minhas', 'Minhas solicitações', 'My requests', 'Mis solicitudes'),
    'sub_minhas' => $t('sub_minhas', 'Sugestões de melhoria e novas funcionalidades que você pediu, com a situação e a resposta da equipe.', 'Improvement suggestions and new features you requested, with their status and the team\'s reply.', 'Sugerencias de mejora y nuevas funcionalidades que usted pidió, con la situación y la respuesta del equipo.'),
    'titulo_gestao' => $t('titulo_gestao', 'Gestão de solicitações', 'Request management', 'Gestión de solicitudes'),
    'sub_gestao' => $t('sub_gestao', 'Solicitações de melhoria dos usuários desta empresa e sinais do assistente de IA.', 'Improvement requests from this company\'s users and signals from the AI assistant.', 'Solicitudes de mejora de los usuarios de esta empresa y señales del asistente de IA.'),
    'nova' => $t('nova', 'Nova solicitação', 'New request', 'Nueva solicitud'),
    'f_titulo' => $t('f_titulo', 'Título', 'Title', 'Título'),
    'f_titulo_ph' => $t('f_titulo_ph', 'Ex.: Enviar aviso de vencimento do ASO por e-mail ao fornecedor', 'E.g.: Send ASO expiry notice by e-mail to the supplier', 'Ej.: Enviar aviso de vencimiento del ASO por correo al proveedor'),
    'f_descricao' => $t('f_descricao', 'O que você precisa', 'What you need', 'Lo que necesita'),
    'f_descricao_ph' => $t('f_descricao_ph', 'Descreva a situação e o que o sistema deveria fazer.', 'Describe the situation and what the system should do.', 'Describa la situación y lo que el sistema debería hacer.'),
    'f_beneficio' => $t('f_beneficio', 'Para que você precisa', 'Why you need it', 'Para qué lo necesita'),
    'f_modulo' => $t('f_modulo', 'Módulo', 'Module', 'Módulo'),
    'f_categoria' => $t('f_categoria', 'Tipo', 'Type', 'Tipo'),
    'f_prioridade' => $t('f_prioridade', 'Importância para você', 'Importance to you', 'Importancia para usted'),
    'nao_sei' => $t('nao_sei', 'Não sei / vários', 'Not sure / several', 'No sé / varios'),
    'baixa' => $t('baixa', 'Baixa', 'Low', 'Baja'), 'media' => $t('media', 'Média', 'Medium', 'Media'), 'alta' => $t('alta', 'Alta', 'High', 'Alta'),
    'enviar' => $t('enviar', 'Registrar solicitação', 'Register request', 'Registrar solicitud'),
    'cancelar' => $t('cancelar', 'Cancelar', 'Cancel', 'Cancelar'),
    'salvar' => $t('salvar', 'Salvar', 'Save', 'Guardar'),
    'vazio_minhas' => $t('vazio_minhas', 'Você ainda não registrou solicitações. Use "Nova solicitação" ou o assistente "Como posso ajudar?".', 'You have not registered any requests yet. Use "New request" or the "How can I help?" assistant.', 'Aún no registró solicitudes. Use "Nueva solicitud" o el asistente "¿Cómo puedo ayudar?".'),
    'vazio' => $t('vazio', 'Nenhuma solicitação encontrada.', 'No requests found.', 'Ninguna solicitud encontrada.'),
    'resposta_equipe' => $t('resposta_equipe', 'Resposta da equipe', 'Team reply', 'Respuesta del equipo'),
    'historico' => $t('historico', 'Histórico', 'History', 'Historial'),
    'criada' => $t('criada', 'Criada em', 'Created on', 'Creada el'),
    'atualizada' => $t('atualizada', 'Atualizada em', 'Updated on', 'Actualizada el'),
    'origem_assistente' => $t('origem_assistente', 'Pelo assistente', 'Via assistant', 'Por el asistente'),
    'origem_tela' => $t('origem_tela', 'Pela tela', 'Via screen', 'Por la pantalla'),
    'tela_origem' => $t('tela_origem', 'Tela de origem', 'Source screen', 'Pantalla de origen'),
    'pergunta_original' => $t('pergunta_original', 'Pergunta feita ao assistente', 'Question asked to the assistant', 'Pregunta hecha al asistente'),
    'duplicada_de' => $t('duplicada_de', 'Duplicada da solicitação', 'Duplicate of request', 'Duplicada de la solicitud'),
    'abas_solicitacoes' => $t('abas_solicitacoes', 'Solicitações', 'Requests', 'Solicitudes'),
    'abas_sinais' => $t('abas_sinais', 'Sinais do assistente', 'Assistant signals', 'Señales del asistente'),
    'abertas' => $t('abertas', 'Abertas', 'Open', 'Abiertas'),
    'todas' => $t('todas', 'Todas', 'All', 'Todas'),
    'todos_tipos' => $t('todos_tipos', 'Todos os tipos', 'All types', 'Todos los tipos'),
    'buscar' => $t('buscar', 'Buscar por número, título, módulo ou solicitante', 'Search by number, title, module or requester', 'Buscar por número, título, módulo o solicitante'),
    'c_num' => '#', 'c_titulo' => $t('c_titulo', 'Título', 'Title', 'Título'), 'c_tipo' => $t('c_tipo', 'Tipo', 'Type', 'Tipo'),
    'c_modulo' => $t('c_modulo', 'Módulo', 'Module', 'Módulo'), 'c_solicitante' => $t('c_solicitante', 'Solicitante', 'Requester', 'Solicitante'),
    'c_situacao' => $t('c_situacao', 'Situação', 'Status', 'Situación'), 'c_data' => $t('c_data', 'Criada', 'Created', 'Creada'),
    'c_importancia' => $t('c_importancia', 'Importância', 'Importance', 'Importancia'),
    'g_situacao' => $t('g_situacao', 'Situação', 'Status', 'Situación'),
    'g_escopo' => $t('g_escopo', 'Escopo', 'Scope', 'Alcance'),
    'g_cliente' => $t('g_cliente', 'Só desta empresa', 'This company only', 'Solo de esta empresa'),
    'g_produto' => $t('g_produto', 'Vale para o produto (todos os clientes)', 'Product-wide (all customers)', 'Vale para el producto (todos los clientes)'),
    'g_resposta' => $t('g_resposta', 'Resposta para quem pediu', 'Reply to the requester', 'Respuesta para quien pidió'),
    'g_resposta_ph' => $t('g_resposta_ph', 'Quem pediu recebe esta resposta num alerta.', 'The requester receives this reply in an alert.', 'Quien pidió recibe esta respuesta en una alerta.'),
    'g_original' => $t('g_original', 'Número da solicitação original', 'Original request number', 'Número de la solicitud original'),
    'g_selecione' => $t('g_selecione', 'Selecione uma solicitação na lista.', 'Select a request from the list.', 'Seleccione una solicitud de la lista.'),
    'g_somente_leitura' => $t('g_somente_leitura', 'Somente consulta: seu perfil não altera solicitações.', 'Read only: your profile cannot change requests.', 'Solo consulta: su perfil no modifica solicitudes.'),
    's_periodo' => $t('s_periodo', 'Período', 'Period', 'Período'),
    's_dias' => $t('s_dias', 'últimos {n} dias', 'last {n} days', 'últimos {n} días'),
    's_respostas' => $t('s_respostas', 'Respostas do assistente', 'Assistant answers', 'Respuestas del asistente'),
    's_uteis' => $t('s_uteis', 'Marcadas como úteis', 'Marked helpful', 'Marcadas como útiles'),
    's_nao_uteis' => $t('s_nao_uteis', 'Marcadas como "não ajudou"', 'Marked "not helpful"', 'Marcadas como "no ayudó"'),
    's_usuarios' => $t('s_usuarios', 'Usuários', 'Users', 'Usuarios'),
    's_explica' => $t('s_explica', 'Perguntas em que o assistente não ajudou, dificuldades de uso, erros relatados e funcionalidades inexistentes que ninguém registrou como solicitação. Bons indícios de tela difícil de achar ou de ajuda incompleta.', 'Questions where the assistant did not help, usability issues, reported errors and missing features nobody registered as a request. Good hints of hard-to-find screens or incomplete help.', 'Preguntas en que el asistente no ayudó, dificultades de uso, errores informados y funcionalidades inexistentes que nadie registró como solicitud. Buenos indicios de pantallas difíciles de encontrar o ayuda incompleta.'),
    's_vazio' => $t('s_vazio', 'Nenhum sinal no período.', 'No signals in the period.', 'Ninguna señal en el período.'),
    's_c_quando' => $t('s_c_quando', 'Quando', 'When', 'Cuándo'), 's_c_usuario' => $t('s_c_usuario', 'Usuário', 'User', 'Usuario'),
    's_c_tela' => $t('s_c_tela', 'Tela', 'Screen', 'Pantalla'), 's_c_motivo' => $t('s_c_motivo', 'Sinal', 'Signal', 'Señal'), 's_c_pergunta' => $t('s_c_pergunta', 'Pergunta', 'Question', 'Pregunta'),
    'm_NAO_AJUDOU' => $t('m_NAO_AJUDOU', 'Não ajudou', 'Not helpful', 'No ayudó'),
    'm_INEXISTENTE' => $t('m_INEXISTENTE', 'Funcionalidade inexistente', 'Missing feature', 'Funcionalidad inexistente'),
    'm_DIFICULDADE_USO' => $t('m_DIFICULDADE_USO', 'Dificuldade de uso', 'Usability issue', 'Dificultad de uso'),
    'm_ERRO' => $t('m_ERRO', 'Erro', 'Error', 'Error'),
    'carregando' => $t('carregando', 'Carregando…', 'Loading…', 'Cargando…'),
    'erro_conexao' => $t('erro_conexao', 'Não foi possível carregar. Tente novamente.', 'Could not load. Please try again.', 'No fue posible cargar. Inténtelo de nuevo.'),
    'nao_instalado' => $t('nao_instalado', 'As solicitações de melhoria ainda não foram instaladas nesta empresa (migração 230).', 'Improvement requests are not installed for this company yet (migration 230).', 'Las solicitudes de mejora aún no fueron instaladas en esta empresa (migración 230).'),
];
$solicitacoesConfig = [
    'modo' => $solicitacoesModo ?? 'minhas',
    'api' => 'api/solicitacoes_melhoria.php',
    'csrf' => (string)$_SESSION['solicitacoes_csrf'],
    'idioma' => $idiomaSolicitacoes,
    'abrir' => max(0, (int)($_GET['id'] ?? 0)),
    'podeEditar' => $solicitacoesServico->podeGerir(true),
    'status' => SolicitacaoMelhoriaService::rotulosStatus(),
    'categorias' => SolicitacaoMelhoriaService::rotulosCategoria(),
    'modulos' => $solicitacoesInstalado ? $solicitacoesServico->modulos() : [],
    'ui' => $ui,
];

/** Cabeçalho HTML comum às duas telas. */
$solicitacoesCabecalho = static function (string $titulo) use ($e, $idiomaSolicitacoes): void {
    echo '<!doctype html><html lang="' . $e($idiomaSolicitacoes === 'pt' ? 'pt-BR' : $idiomaSolicitacoes) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . $e($titulo) . '</title>'
        . '<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2"><link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2">'
        . '<link rel="stylesheet" href="../assets/css/solicitacoes.css?v=1">';
    // Padrão visual das páginas do portal (fonte, títulos, cores do tema).
    require_once __DIR__ . '/pagina_padrao.php';
    greenPortalEstiloPagina();
    echo '</head>';
};
$solicitacoesRodape = static function () use ($solicitacoesConfig): void {
    echo '<script type="application/json" id="solicitacoesConfig">' . json_encode($solicitacoesConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>'
        . '<script src="../assets/vendor/jquery/jquery-3.7.1.min.js?v=2"></script><script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script>'
        . '<script src="../assets/js/solicitacoes.js?v=1"></script></body></html>';
};
