<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/AdministracaoTextos.php';

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
if (empty($permissao['pode_visualizar'])) { http_response_code(403); exit(adm_t('Sem permissão para administrar o guia do usuário.')); }
$podeEditar = strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? '')) === 'ADMINISTRADOR' && !empty($permissao['pode_editar']);
if (empty($_SESSION['guia_usuario_csrf'])) $_SESSION['guia_usuario_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['guia_usuario_csrf'];

$idioma = (string)($_SESSION['idioma'] ?? 'pt');
$idioma = in_array($idioma, ['pt', 'en', 'es'], true) ? $idioma : 'pt';
$L = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];
$textos = [
    'titulo' => $L(adm_t('Guia do usuário'), adm_t('User guide'), adm_t('Guía del usuario')),
    'subtitulo' => $L(adm_t('Conteúdo único para todas as empresas. Edite o rascunho e publique quando estiver pronto.'), adm_t('Single content for all companies. Edit the draft and publish when ready.'), adm_t('Contenido único para todas las empresas. Edite el borrador y publíquelo cuando esté listo.')),
    'salvar' => $L(adm_t('Salvar rascunho'), adm_t('Save draft'), adm_t('Guardar borrador')),
    'preview' => $L(adm_t('Pré-visualizar'), 'Preview', adm_t('Vista previa')),
    'publicar' => $L('Publicar', 'Publish', 'Publicar'),
    'versoes' => $L(adm_t('Versões'), 'Versions', 'Versiones'),
    'carregando' => $L('Carregando…', 'Loading…', 'Cargando…'),
    'publicado' => $L('Publicado', 'Published', 'Publicado'),
    'versao' => $L(adm_t('versão'), 'version', adm_t('versión')),
    'por' => $L('por', 'by', 'por'),
    'nunca' => $L(adm_t('Nada publicado ainda'), adm_t('Nothing published yet'), adm_t('Nada publicado aún')),
    'st_igual' => $L(adm_t('Rascunho igual ao publicado'), adm_t('Draft matches the published guide'), adm_t('Borrador igual al publicado')),
    'st_pendente' => $L(adm_t('Rascunho com alterações não publicadas'), adm_t('Draft has unpublished changes'), adm_t('Borrador con cambios no publicados')),
    'st_sujo' => $L(adm_t('Alterações não salvas'), adm_t('Unsaved changes'), adm_t('Cambios sin guardar')),
    'st_leitura' => $L(adm_t('Somente leitura: apenas o ADMINISTRADOR altera o guia.'), adm_t('Read only: only the ADMINISTRADOR profile can change the guide.'), adm_t('Solo lectura: solo el perfil ADMINISTRADOR modifica la guía.')),
    'estrutura' => $L(adm_t('Estrutura do guia'), adm_t('Guide structure'), adm_t('Estructura de la guía')),
    'perfis' => $L('Perfis', 'Profiles', 'Perfiles'),
    'abas' => $L(adm_t('Abas (módulos)'), 'Tabs (modules)', adm_t('Pestañas (módulos)')),
    'nova_aba' => $L(adm_t('Nova aba'), adm_t('New tab'), adm_t('Nueva pestaña')),
    'novo_perfil' => $L(adm_t('Novo perfil'), adm_t('New profile'), adm_t('Nuevo perfil')),
    'fluxo' => $L(adm_t('Fluxo do processo'), adm_t('Process flow'), adm_t('Flujo del proceso')),
    'tarefas' => $L(adm_t('Como fazer'), adm_t('How to'), adm_t('Cómo hacer')),
    'menu' => $L(adm_t('Onde encontrar'), adm_t('Where to find'), adm_t('Dónde encontrar')),
    'termos' => $L(adm_t('Termos e dicas'), adm_t('Terms and tips'), adm_t('Términos y consejos')),
    'fase' => $L('Fase', 'Phase', 'Fase'),
    'etapa' => $L('Etapa', 'Step', 'Etapa'),
    'nova_fase' => $L(adm_t('Nova fase'), adm_t('New phase'), adm_t('Nueva fase')),
    'nova_etapa' => $L(adm_t('Nova etapa'), adm_t('New step'), adm_t('Nueva etapa')),
    'nova_tarefa' => $L(adm_t('Novo "como fazer"'), adm_t('New "how to"'), adm_t('Nuevo "cómo hacer"')),
    'novo_item' => $L(adm_t('Novo item'), adm_t('New item'), adm_t('Nuevo ítem')),
    'novo_termo' => $L(adm_t('Novo termo'), adm_t('New term'), adm_t('Nuevo término')),
    'sem_titulo' => $L(adm_t('(sem título)'), '(untitled)', adm_t('(sin título)')),
    'subir' => $L(adm_t('Mover para cima'), adm_t('Move up'), 'Subir'),
    'descer' => $L(adm_t('Mover para baixo'), adm_t('Move down'), 'Bajar'),
    'duplicar' => $L('Duplicar', 'Duplicate', 'Duplicar'),
    'excluir' => $L('Excluir', 'Delete', 'Eliminar'),
    'idioma_edicao' => $L(adm_t('Idioma do texto'), adm_t('Text language'), adm_t('Idioma del texto')),
    'faltando' => $L(adm_t('tradução faltando'), 'missing translation', adm_t('traducción faltante')),
    'faltam' => $L(adm_t('traduções faltando'), 'missing translations', 'traducciones faltantes'),
    'aviso_traducao' => $L(adm_t('Campos vazios em EN ou ES mostram o texto em português.'), adm_t('Empty EN or ES fields show the Portuguese text.'), adm_t('Los campos vacíos en EN o ES muestran el texto en portugués.')),
    'f_codigo' => $L(adm_t('Código da aba'), adm_t('Tab code'), adm_t('Código de la pestaña')),
    'f_codigo_ajuda' => $L(adm_t('Letras minúsculas, números, - ou _. Usado no link ?modulo=código.'), 'Lowercase letters, numbers, - or _. Used in the ?modulo=code link.', adm_t('Minúsculas, números, - o _. Se usa en el enlace ?modulo=código.')),
    'f_nome' => $L('Nome', 'Name', 'Nombre'),
    'f_resumo' => $L(adm_t('Resumo (aparece abaixo das abas)'), 'Summary (shown below the tabs)', adm_t('Resumen (aparece debajo de las pestañas)')),
    'f_icone' => $L(adm_t('Ícone'), 'Icon', adm_t('Ícono')),
    'f_ativo' => $L(adm_t('Aba visível para os usuários'), adm_t('Tab visible to users'), adm_t('Pestaña visible para los usuarios')),
    'f_perfis' => $L(adm_t('Perfis desta aba (filtro "Ver etapas de")'), 'Profiles of this tab ("Show steps for" filter)', adm_t('Perfiles de esta pestaña (filtro "Ver etapas de")')),
    'f_perfis_nota' => $L(adm_t('Nota sobre os perfis'), adm_t('Note about profiles'), adm_t('Nota sobre los perfiles')),
    'f_tipo' => $L(adm_t('Quem executa'), adm_t('Who performs it'), adm_t('Quién lo ejecuta')),
    'f_manual' => $L(adm_t('M — Manual (o usuário faz)'), 'M — Manual (the user does it)', 'M — Manual (lo hace el usuario)'),
    'f_auto' => $L(adm_t('A — Automático (o sistema faz)'), 'A — Automatic (the system does it)', adm_t('A — Automático (lo hace el sistema)')),
    'f_perfil' => $L('Perfil', 'Profile', 'Perfil'),
    'f_titulo' => $L(adm_t('Título'), 'Title', adm_t('Título')),
    'f_texto' => $L(adm_t('Descrição'), 'Description', adm_t('Descripción')),
    'f_onde' => $L(adm_t('Onde (caminho no menu ou botão)'), 'Where (menu path or button)', adm_t('Dónde (ruta del menú o botón)')),
    'f_passos' => $L('Passos', 'Steps', 'Pasos'),
    'f_novo_passo' => $L(adm_t('Adicionar passo'), adm_t('Add step'), adm_t('Agregar paso')),
    'f_caminho' => $L(adm_t('Caminho no menu'), adm_t('Menu path'), adm_t('Ruta del menú')),
    'f_termo' => $L('Termo', 'Term', adm_t('Término')),
    'f_dica' => $L('Mostrar como dica (destaque verde)', adm_t('Show as a tip (green highlight)'), 'Mostrar como consejo (destaque verde)'),
    'f_chave' => $L(adm_t('Código do perfil'), adm_t('Profile code'), adm_t('Código del perfil')),
    'f_chave_ajuda' => $L(adm_t('Não pode ser alterado depois de criado.'), adm_t('Cannot be changed after creation.'), adm_t('No se puede cambiar después de creado.')),
    'f_sistema' => $L(adm_t('O perfil "sistema" é usado nas etapas automáticas e não pode ser excluído.'), adm_t('The "sistema" profile is used in automatic steps and cannot be deleted.'), adm_t('El perfil "sistema" se usa en las etapas automáticas y no puede eliminarse.')),
    'escolha' => $L(adm_t('Escolha um item à esquerda para editar.'), adm_t('Choose an item on the left to edit.'), adm_t('Elija un ítem a la izquierda para editar.')),
    'pergunta_codigo_aba' => $L(adm_t('Código da nova aba (ex.: nao-conformidades):'), 'New tab code (e.g. non-conformities):', adm_t('Código de la nueva pestaña (ej.: no-conformidades):')),
    'pergunta_codigo_perfil' => $L(adm_t('Código do novo perfil (ex.: comprador):'), 'New profile code (e.g. buyer):', adm_t('Código del nuevo perfil (ej.: comprador):')),
    'codigo_invalido' => $L(adm_t('Código inválido ou já usado. Use de 2 a 40 letras minúsculas, números, - ou _.'), adm_t('Invalid or already used code. Use 2 to 40 lowercase letters, numbers, - or _.'), adm_t('Código inválido o ya usado. Use de 2 a 40 minúsculas, números, - o _.')),
    'confirma_excluir' => $L(adm_t('Excluir este item? A exclusão só vale para os usuários depois de publicar.'), adm_t('Delete this item? Users will only see the change after publishing.'), adm_t('¿Eliminar este ítem? Los usuarios verán el cambio solo después de publicar.')),
    'perfil_em_uso' => $L(adm_t('Este perfil está em uso em etapas ou abas. Troque-o antes de excluir.'), adm_t('This profile is used in steps or tabs. Replace it before deleting.'), adm_t('Este perfil está en uso en etapas o pestañas. Cámbielo antes de eliminar.')),
    'salvo' => $L(adm_t('Rascunho salvo.'), adm_t('Draft saved.'), adm_t('Borrador guardado.')),
    'publicado_ok' => $L(adm_t('Guia publicado. Os usuários já veem a nova versão.'), adm_t('Guide published. Users now see the new version.'), adm_t('Guía publicada. Los usuarios ya ven la nueva versión.')),
    'restaurado' => $L(adm_t('Versão copiada para o rascunho. Revise e publique para valer.'), adm_t('Version copied to the draft. Review and publish to apply it.'), adm_t('Versión copiada al borrador. Revise y publique para aplicarla.')),
    'pub_titulo' => $L(adm_t('Publicar o guia'), adm_t('Publish the guide'), adm_t('Publicar la guía')),
    'pub_texto' => $L(adm_t('A versão publicada passa a ser vista por todos os usuários de todas as empresas.'), adm_t('The published version will be seen by all users of all companies.'), adm_t('La versión publicada la verán todos los usuarios de todas las empresas.')),
    'pub_obs' => $L(adm_t('O que mudou? (opcional)'), 'What changed? (optional)', adm_t('¿Qué cambió? (opcional)')),
    'cancelar' => $L('Cancelar', 'Cancel', 'Cancelar'),
    'fechar' => $L('Fechar', 'Close', 'Cerrar'),
    'v_numero' => $L(adm_t('Versão'), 'Version', adm_t('Versión')),
    'v_data' => $L(adm_t('Publicada em'), adm_t('Published on'), adm_t('Publicada el')),
    'v_por' => $L('Por', 'By', 'Por'),
    'v_obs' => $L(adm_t('Observação'), 'Note', adm_t('Observación')),
    'v_vigente' => $L('vigente', 'current', 'vigente'),
    'v_carga' => $L('carga inicial', 'initial load', 'carga inicial'),
    'v_ver' => $L('Ver', 'View', 'Ver'),
    'v_restaurar' => $L(adm_t('Restaurar no rascunho'), adm_t('Restore to draft'), adm_t('Restaurar en el borrador')),
    'v_descartar' => $L(adm_t('Descartar rascunho e voltar ao publicado'), adm_t('Discard draft and go back to published'), adm_t('Descartar borrador y volver al publicado')),
    'v_confirma' => $L(adm_t('Substituir o rascunho atual por esta versão?'), adm_t('Replace the current draft with this version?'), adm_t('¿Reemplazar el borrador actual por esta versión?')),
    'sair_sem_salvar' => $L(adm_t('Há alterações não salvas. Deseja descartá-las?'), adm_t('There are unsaved changes. Discard them?'), adm_t('Hay cambios sin guardar. ¿Descartarlos?')),
    'erro' => $L(adm_t('Não foi possível concluir a operação.'), adm_t('The operation could not be completed.'), adm_t('No fue posible completar la operación.')),
    'salve_antes' => $L(adm_t('Salve o rascunho antes de publicar.'), adm_t('Save the draft before publishing.'), adm_t('Guarde el borrador antes de publicar.')),
    'nada_publicar' => $L(adm_t('O rascunho já está publicado.'), adm_t('The draft is already published.'), adm_t('El borrador ya está publicado.')),
    'icones_sugeridos' => $L(adm_t('Sugestões'), 'Suggestions', 'Sugerencias'),
    'itens' => $L('itens', 'items', adm_t('ítems')),
    'f_link' => $L(adm_t('Link direto para a tela'), adm_t('Direct link to the screen'), adm_t('Enlace directo a la pantalla')),
    'f_link_nenhum' => $L(adm_t('— Sem link —'), adm_t('— No link —'), adm_t('— Sin enlace —')),
    'f_link_ajuda' => $L(adm_t('Mostra o botão "Abrir tela" no guia. Só aparece para quem tem permissão de acessar essa tela.'), adm_t('Shows the "Open screen" button in the guide. It only appears for users allowed to access that screen.'), adm_t('Muestra el botón "Abrir pantalla" en la guía. Solo aparece para quien tiene permiso de acceder a esa pantalla.')),
    'f_link_invalido' => $L(adm_t('A transação %s não existe ou está inativa no menu. O link não aparecerá até ser corrigido.'), adm_t('Transaction %s does not exist or is inactive in the menu. The link will not appear until fixed.'), adm_t('La transacción %s no existe o está inactiva en el menú. El enlace no aparecerá hasta corregirlo.')),
    'tem_link' => $L(adm_t('Tem link direto'), adm_t('Has a direct link'), adm_t('Tiene enlace directo')),
    'ajuda' => $L('Ajuda', 'Help', 'Ayuda'),
    'ajuda_titulo' => $L(adm_t('Ajuda — Guia do usuário (administração)'), 'Help — User guide (administration)', adm_t('Ayuda — Guía del usuario (administración)')),
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
<?= AdministracaoTextos::script() ?><script src="../assets/js/ajuda-transacao.js?v=1"></script>
<script src="../assets/js/administracao-guia-usuario.js?v=4-i3"></script>
</body>
</html>
