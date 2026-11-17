<?php
declare(strict_types=1);

/*
 * Transação: Administração › Guia do usuário (ADMINISTRACAO_GUIA_USUARIO).
 * Edita o conteúdo universal do guia (rascunho), pré-visualiza e publica versões.
 * Dados: guniversal_dbp (guia_usuario_rascunho / guia_usuario_versoes), via api/administracao_guia_usuario.php.
 */

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: text/html; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/GuiaUsuarioService.php';

$permissao = PermissaoTransacao::obter(GuiaUsuarioService::TRANSACAO);
if (empty($permissao['pode_visualizar'])) { http_response_code(403); exit('Sem permissão para administrar o guia do usuário.'); }
$podeEditar = strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? '')) === 'ADMINISTRADOR' && !empty($permissao['pode_editar']);
if (empty($_SESSION['guia_usuario_csrf'])) $_SESSION['guia_usuario_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['guia_usuario_csrf'];

$idioma = (string)($_SESSION['idioma'] ?? 'pt');
$idioma = in_array($idioma, ['pt', 'en', 'es'], true) ? $idioma : 'pt';
$L = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];
$textos = [
    'titulo' => $L('Guia do usuário', 'User guide', 'Guía del usuario'),
    'subtitulo' => $L('Conteúdo único para todas as empresas. Edite o rascunho e publique quando estiver pronto.', 'Single content for all companies. Edit the draft and publish when ready.', 'Contenido único para todas las empresas. Edite el borrador y publíquelo cuando esté listo.'),
    'salvar' => $L('Salvar rascunho', 'Save draft', 'Guardar borrador'),
    'preview' => $L('Pré-visualizar', 'Preview', 'Vista previa'),
    'publicar' => $L('Publicar', 'Publish', 'Publicar'),
    'versoes' => $L('Versões', 'Versions', 'Versiones'),
    'carregando' => $L('Carregando…', 'Loading…', 'Cargando…'),
    'publicado' => $L('Publicado', 'Published', 'Publicado'),
    'versao' => $L('versão', 'version', 'versión'),
    'por' => $L('por', 'by', 'por'),
    'nunca' => $L('Nada publicado ainda', 'Nothing published yet', 'Nada publicado aún'),
    'st_igual' => $L('Rascunho igual ao publicado', 'Draft matches the published guide', 'Borrador igual al publicado'),
    'st_pendente' => $L('Rascunho com alterações não publicadas', 'Draft has unpublished changes', 'Borrador con cambios no publicados'),
    'st_sujo' => $L('Alterações não salvas', 'Unsaved changes', 'Cambios sin guardar'),
    'st_leitura' => $L('Somente leitura: apenas o ADMINISTRADOR altera o guia.', 'Read only: only the ADMINISTRADOR profile can change the guide.', 'Solo lectura: solo el perfil ADMINISTRADOR modifica la guía.'),
    'estrutura' => $L('Estrutura do guia', 'Guide structure', 'Estructura de la guía'),
    'perfis' => $L('Perfis', 'Profiles', 'Perfiles'),
    'abas' => $L('Abas (módulos)', 'Tabs (modules)', 'Pestañas (módulos)'),
    'nova_aba' => $L('Nova aba', 'New tab', 'Nueva pestaña'),
    'novo_perfil' => $L('Novo perfil', 'New profile', 'Nuevo perfil'),
    'fluxo' => $L('Fluxo do processo', 'Process flow', 'Flujo del proceso'),
    'tarefas' => $L('Como fazer', 'How to', 'Cómo hacer'),
    'menu' => $L('Onde encontrar', 'Where to find', 'Dónde encontrar'),
    'termos' => $L('Termos e dicas', 'Terms and tips', 'Términos y consejos'),
    'fase' => $L('Fase', 'Phase', 'Fase'),
    'etapa' => $L('Etapa', 'Step', 'Etapa'),
    'nova_fase' => $L('Nova fase', 'New phase', 'Nueva fase'),
    'nova_etapa' => $L('Nova etapa', 'New step', 'Nueva etapa'),
    'nova_tarefa' => $L('Novo "como fazer"', 'New "how to"', 'Nuevo "cómo hacer"'),
    'novo_item' => $L('Novo item', 'New item', 'Nuevo ítem'),
    'novo_termo' => $L('Novo termo', 'New term', 'Nuevo término'),
    'sem_titulo' => $L('(sem título)', '(untitled)', '(sin título)'),
    'subir' => $L('Mover para cima', 'Move up', 'Subir'),
    'descer' => $L('Mover para baixo', 'Move down', 'Bajar'),
    'duplicar' => $L('Duplicar', 'Duplicate', 'Duplicar'),
    'excluir' => $L('Excluir', 'Delete', 'Eliminar'),
    'idioma_edicao' => $L('Idioma do texto', 'Text language', 'Idioma del texto'),
    'faltando' => $L('tradução faltando', 'missing translation', 'traducción faltante'),
    'faltam' => $L('traduções faltando', 'missing translations', 'traducciones faltantes'),
    'aviso_traducao' => $L('Campos vazios em EN ou ES mostram o texto em português.', 'Empty EN or ES fields show the Portuguese text.', 'Los campos vacíos en EN o ES muestran el texto en portugués.'),
    'f_codigo' => $L('Código da aba', 'Tab code', 'Código de la pestaña'),
    'f_codigo_ajuda' => $L('Letras minúsculas, números, - ou _. Usado no link ?modulo=código.', 'Lowercase letters, numbers, - or _. Used in the ?modulo=code link.', 'Minúsculas, números, - o _. Se usa en el enlace ?modulo=código.'),
    'f_nome' => $L('Nome', 'Name', 'Nombre'),
    'f_resumo' => $L('Resumo (aparece abaixo das abas)', 'Summary (shown below the tabs)', 'Resumen (aparece debajo de las pestañas)'),
    'f_icone' => $L('Ícone', 'Icon', 'Ícono'),
    'f_ativo' => $L('Aba visível para os usuários', 'Tab visible to users', 'Pestaña visible para los usuarios'),
    'f_perfis' => $L('Perfis desta aba (filtro "Ver etapas de")', 'Profiles of this tab ("Show steps for" filter)', 'Perfiles de esta pestaña (filtro "Ver etapas de")'),
    'f_perfis_nota' => $L('Nota sobre os perfis', 'Note about profiles', 'Nota sobre los perfiles'),
    'f_tipo' => $L('Quem executa', 'Who performs it', 'Quién lo ejecuta'),
    'f_manual' => $L('M — Manual (o usuário faz)', 'M — Manual (the user does it)', 'M — Manual (lo hace el usuario)'),
    'f_auto' => $L('A — Automático (o sistema faz)', 'A — Automatic (the system does it)', 'A — Automático (lo hace el sistema)'),
    'f_perfil' => $L('Perfil', 'Profile', 'Perfil'),
    'f_titulo' => $L('Título', 'Title', 'Título'),
    'f_texto' => $L('Descrição', 'Description', 'Descripción'),
    'f_onde' => $L('Onde (caminho no menu ou botão)', 'Where (menu path or button)', 'Dónde (ruta del menú o botón)'),
    'f_passos' => $L('Passos', 'Steps', 'Pasos'),
    'f_novo_passo' => $L('Adicionar passo', 'Add step', 'Agregar paso'),
    'f_caminho' => $L('Caminho no menu', 'Menu path', 'Ruta del menú'),
    'f_termo' => $L('Termo', 'Term', 'Término'),
    'f_dica' => $L('Mostrar como dica (destaque verde)', 'Show as a tip (green highlight)', 'Mostrar como consejo (destaque verde)'),
    'f_chave' => $L('Código do perfil', 'Profile code', 'Código del perfil'),
    'f_chave_ajuda' => $L('Não pode ser alterado depois de criado.', 'Cannot be changed after creation.', 'No se puede cambiar después de creado.'),
    'f_sistema' => $L('O perfil "sistema" é usado nas etapas automáticas e não pode ser excluído.', 'The "sistema" profile is used in automatic steps and cannot be deleted.', 'El perfil "sistema" se usa en las etapas automáticas y no puede eliminarse.'),
    'escolha' => $L('Escolha um item à esquerda para editar.', 'Choose an item on the left to edit.', 'Elija un ítem a la izquierda para editar.'),
    'pergunta_codigo_aba' => $L('Código da nova aba (ex.: nao-conformidades):', 'New tab code (e.g. non-conformities):', 'Código de la nueva pestaña (ej.: no-conformidades):'),
    'pergunta_codigo_perfil' => $L('Código do novo perfil (ex.: comprador):', 'New profile code (e.g. buyer):', 'Código del nuevo perfil (ej.: comprador):'),
    'codigo_invalido' => $L('Código inválido ou já usado. Use de 2 a 40 letras minúsculas, números, - ou _.', 'Invalid or already used code. Use 2 to 40 lowercase letters, numbers, - or _.', 'Código inválido o ya usado. Use de 2 a 40 minúsculas, números, - o _.'),
    'confirma_excluir' => $L('Excluir este item? A exclusão só vale para os usuários depois de publicar.', 'Delete this item? Users will only see the change after publishing.', '¿Eliminar este ítem? Los usuarios verán el cambio solo después de publicar.'),
    'perfil_em_uso' => $L('Este perfil está em uso em etapas ou abas. Troque-o antes de excluir.', 'This profile is used in steps or tabs. Replace it before deleting.', 'Este perfil está en uso en etapas o pestañas. Cámbielo antes de eliminar.'),
    'salvo' => $L('Rascunho salvo.', 'Draft saved.', 'Borrador guardado.'),
    'publicado_ok' => $L('Guia publicado. Os usuários já veem a nova versão.', 'Guide published. Users now see the new version.', 'Guía publicada. Los usuarios ya ven la nueva versión.'),
    'restaurado' => $L('Versão copiada para o rascunho. Revise e publique para valer.', 'Version copied to the draft. Review and publish to apply it.', 'Versión copiada al borrador. Revise y publique para aplicarla.'),
    'pub_titulo' => $L('Publicar o guia', 'Publish the guide', 'Publicar la guía'),
    'pub_texto' => $L('A versão publicada passa a ser vista por todos os usuários de todas as empresas.', 'The published version will be seen by all users of all companies.', 'La versión publicada la verán todos los usuarios de todas las empresas.'),
    'pub_obs' => $L('O que mudou? (opcional)', 'What changed? (optional)', '¿Qué cambió? (opcional)'),
    'cancelar' => $L('Cancelar', 'Cancel', 'Cancelar'),
    'fechar' => $L('Fechar', 'Close', 'Cerrar'),
    'v_numero' => $L('Versão', 'Version', 'Versión'),
    'v_data' => $L('Publicada em', 'Published on', 'Publicada el'),
    'v_por' => $L('Por', 'By', 'Por'),
    'v_obs' => $L('Observação', 'Note', 'Observación'),
    'v_vigente' => $L('vigente', 'current', 'vigente'),
    'v_carga' => $L('carga inicial', 'initial load', 'carga inicial'),
    'v_ver' => $L('Ver', 'View', 'Ver'),
    'v_restaurar' => $L('Restaurar no rascunho', 'Restore to draft', 'Restaurar en el borrador'),
    'v_descartar' => $L('Descartar rascunho e voltar ao publicado', 'Discard draft and go back to published', 'Descartar borrador y volver al publicado'),
    'v_confirma' => $L('Substituir o rascunho atual por esta versão?', 'Replace the current draft with this version?', '¿Reemplazar el borrador actual por esta versión?'),
    'sair_sem_salvar' => $L('Há alterações não salvas. Deseja descartá-las?', 'There are unsaved changes. Discard them?', 'Hay cambios sin guardar. ¿Descartarlos?'),
    'erro' => $L('Não foi possível concluir a operação.', 'The operation could not be completed.', 'No fue posible completar la operación.'),
    'salve_antes' => $L('Salve o rascunho antes de publicar.', 'Save the draft before publishing.', 'Guarde el borrador antes de publicar.'),
    'nada_publicar' => $L('O rascunho já está publicado.', 'The draft is already published.', 'El borrador ya está publicado.'),
    'icones_sugeridos' => $L('Sugestões', 'Suggestions', 'Sugerencias'),
    'itens' => $L('itens', 'items', 'ítems'),
    'f_link' => $L('Link direto para a tela', 'Direct link to the screen', 'Enlace directo a la pantalla'),
    'f_link_nenhum' => $L('— Sem link —', '— No link —', '— Sin enlace —'),
    'f_link_ajuda' => $L('Mostra o botão "Abrir tela" no guia. Só aparece para quem tem permissão de acessar essa tela.', 'Shows the "Open screen" button in the guide. It only appears for users allowed to access that screen.', 'Muestra el botón "Abrir pantalla" en la guía. Solo aparece para quien tiene permiso de acceder a esa pantalla.'),
    'f_link_invalido' => $L('A transação %s não existe ou está inativa no menu. O link não aparecerá até ser corrigido.', 'Transaction %s does not exist or is inactive in the menu. The link will not appear until fixed.', 'La transacción %s no existe o está inactiva en el menú. El enlace no aparecerá hasta corregirlo.'),
    'tem_link' => $L('Tem link direto', 'Has a direct link', 'Tiene enlace directo'),
    'ajuda' => $L('Ajuda', 'Help', 'Ayuda'),
    'ajuda_titulo' => $L('Ajuda — Guia do usuário (administração)', 'Help — User guide (administration)', 'Ayuda — Guía del usuario (administración)'),
];
$ui = [];
foreach ($textos as $chave => $valor) $ui[$chave] = $valor[$idioma] ?? $valor['pt'];
$e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="<?= $e($idioma === 'pt' ? 'pt-BR' : $idioma) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($ui['titulo']) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2">
<link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2">
<link rel="stylesheet" href="../assets/css/design-system.css?v=3">
<link rel="stylesheet" href="../assets/css/administracao-guia-usuario.css?v=1">
</head>
<body>
<main class="ge" data-guia-editor data-csrf="<?= $e($csrf) ?>" data-pode-editar="<?= $podeEditar ? '1' : '0' ?>" data-idioma="<?= $e($idioma) ?>">
  <header class="ge-top">
    <div class="ge-title">
      <span class="ge-title-icon" aria-hidden="true"><i class="bi bi-book-half"></i></span>
      <div>
        <h1><?= $e($ui['titulo']) ?></h1>
        <p><?= $e($ui['subtitulo']) ?></p>
      </div>
    </div>
    <div class="ge-actions">
      <button type="button" class="btn btn-sm btn-success" data-acao="salvar" disabled><i class="bi bi-floppy me-1"></i><?= $e($ui['salvar']) ?></button>
      <button type="button" class="btn btn-sm btn-outline-secondary" data-acao="preview"><i class="bi bi-eye me-1"></i><?= $e($ui['preview']) ?></button>
      <button type="button" class="btn btn-sm btn-outline-success" data-acao="publicar"><i class="bi bi-cloud-arrow-up me-1"></i><?= $e($ui['publicar']) ?></button>
      <button type="button" class="btn btn-sm btn-outline-secondary" data-acao="versoes"><i class="bi bi-clock-history me-1"></i><?= $e($ui['versoes']) ?></button>
      <button type="button" class="btn btn-sm btn-outline-info" data-acao="ajuda" data-open-transacao-ajuda data-help-transaction="administracao_guia_usuario" title="<?= $e($ui['ajuda_titulo']) ?>" aria-label="<?= $e($ui['ajuda']) ?>"><i class="bi bi-question-circle me-1"></i><?= $e($ui['ajuda']) ?></button>
    </div>
  </header>

  <div class="ge-status" data-status aria-live="polite"><span class="ge-pill"><?= $e($ui['carregando']) ?></span></div>
  <div class="ge-alert d-none" data-alerta role="alert"></div>

  <div class="ge-body">
    <aside class="ge-tree" aria-label="<?= $e($ui['estrutura']) ?>">
      <div class="ge-tree-head"><?= $e($ui['estrutura']) ?></div>
      <div class="ge-tree-scroll" data-arvore></div>
    </aside>
    <section class="ge-form" data-formulario aria-live="polite">
      <div class="ge-empty"><i class="bi bi-hand-index"></i><p><?= $e($ui['escolha']) ?></p></div>
    </section>
  </div>
</main>

<div class="modal fade" id="geModalPublicar" tabindex="-1" aria-labelledby="geModalPublicarTitulo" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="geModalPublicarTitulo"><i class="bi bi-cloud-arrow-up me-2"></i><?= $e($ui['pub_titulo']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $e($ui['fechar']) ?>"></button></div>
    <div class="modal-body">
      <p class="small text-muted"><?= $e($ui['pub_texto']) ?></p>
      <label class="form-label small fw-semibold" for="geObservacao"><?= $e($ui['pub_obs']) ?></label>
      <textarea class="form-control form-control-sm" id="geObservacao" rows="3" maxlength="500"></textarea>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= $e($ui['cancelar']) ?></button><button type="button" class="btn btn-sm btn-success" data-confirmar-publicacao><i class="bi bi-cloud-arrow-up me-1"></i><?= $e($ui['publicar']) ?></button></div>
  </div></div>
</div>

<div class="modal fade" id="geModalVersoes" tabindex="-1" aria-labelledby="geModalVersoesTitulo" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="geModalVersoesTitulo"><i class="bi bi-clock-history me-2"></i><?= $e($ui['versoes']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $e($ui['fechar']) ?>"></button></div>
    <div class="modal-body p-0"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 ge-versions">
      <thead><tr><th><?= $e($ui['v_numero']) ?></th><th><?= $e($ui['v_data']) ?></th><th><?= $e($ui['v_por']) ?></th><th><?= $e($ui['v_obs']) ?></th><th></th></tr></thead>
      <tbody data-versoes><tr><td colspan="5"><?= $e($ui['carregando']) ?></td></tr></tbody>
    </table></div></div>
    <div class="modal-footer justify-content-between"><button type="button" class="btn btn-sm btn-outline-danger" data-descartar-rascunho><i class="bi bi-arrow-counterclockwise me-1"></i><?= $e($ui['v_descartar']) ?></button><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= $e($ui['fechar']) ?></button></div>
  </div></div>
</div>

<script type="application/json" id="geTextos"><?= json_encode($ui, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script>
<script src="../assets/js/ajuda-transacao.js?v=1"></script>
<script src="../assets/js/administracao-guia-usuario.js?v=4"></script>
</body>
</html>
