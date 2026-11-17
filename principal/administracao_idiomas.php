<?php
declare(strict_types=1);

/*
 * Transação: Administração › Textos e idiomas (ADMINISTRACAO_IDIOMAS).
 * Mantém os rótulos, dicas e mensagens das telas em PT/EN/ES. Textos únicos para todas as empresas:
 * a edição é feita no rascunho e só chega às telas depois de "Publicar".
 * Dados: guniversal_dbp (idioma_*), via api/administracao_idiomas.php.
 */

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: text/html; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';

$permissao = PermissaoTransacao::obter(IdiomaService::TRANSACAO);
if (empty($permissao['pode_visualizar'])) { http_response_code(403); exit('Sem permissão para administrar textos e idiomas.'); }
$podeEditar = strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? '')) === 'ADMINISTRADOR' && !empty($permissao['pode_editar']);
if (empty($_SESSION['idiomas_csrf'])) $_SESSION['idiomas_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['idiomas_csrf'];

$idioma = IdiomaService::idioma();
$L = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];
$textos = [
    'titulo' => $L('Textos e idiomas', 'Texts and languages', 'Textos e idiomas'),
    'subtitulo' => $L('Rótulos, dicas e mensagens das telas em PT, EN e ES. Valem para todas as empresas depois de publicados.', 'Screen labels, tooltips and messages in PT, EN and ES. They apply to all companies once published.', 'Rótulos, ayudas y mensajes de las pantallas en PT, EN y ES. Valen para todas las empresas una vez publicados.'),
    'salvar' => $L('Salvar rascunho', 'Save draft', 'Guardar borrador'),
    'publicar' => $L('Publicar', 'Publish', 'Publicar'),
    'descartar' => $L('Descartar rascunho', 'Discard draft', 'Descartar borrador'),
    'historico' => $L('Histórico', 'History', 'Historial'),
    'previa' => $L('Ver rascunho nas telas', 'Show draft on screens', 'Ver borrador en las pantallas'),
    'previa_on' => $L('Rascunho visível nas telas (só para você)', 'Draft visible on screens (only for you)', 'Borrador visible en las pantallas (solo para usted)'),
    'ajuda' => $L('Ajuda', 'Help', 'Ayuda'),
    'ajuda_titulo' => $L('Como usar a tela Textos e idiomas', 'How to use the Texts and languages screen', 'Cómo usar la pantalla Textos e idiomas'),
    'carregando' => $L('Carregando…', 'Loading…', 'Cargando…'),
    'grupos' => $L('Grupos', 'Groups', 'Grupos'),
    'todos_grupos' => $L('Todos os grupos', 'All groups', 'Todos los grupos'),
    'buscar' => $L('Buscar texto, chave ou local…', 'Search text, key or place…', 'Buscar texto, clave o lugar…'),
    'f_pendentes' => $L('Não publicados', 'Unpublished', 'No publicados'),
    'f_faltando' => $L('Tradução faltando', 'Missing translation', 'Traducción faltante'),
    'f_alterados' => $L('Não salvos', 'Unsaved', 'Sin guardar'),
    'c_texto' => $L('Onde aparece', 'Where it appears', 'Dónde aparece'),
    'c_situacao' => $L('Situação', 'Status', 'Situación'),
    'publicado' => $L('Publicado', 'Published', 'Publicado'),
    'publicacao' => $L('publicação', 'publication', 'publicación'),
    'por' => $L('por', 'by', 'por'),
    'nunca' => $L('Nada publicado ainda', 'Nothing published yet', 'Nada publicado aún'),
    'st_igual' => $L('Rascunho igual ao publicado', 'Draft matches what is published', 'Borrador igual al publicado'),
    'st_pendente' => $L('alterações salvas, falta publicar', 'saved changes waiting to be published', 'cambios guardados, falta publicar'),
    'st_sujo' => $L('alterações não salvas', 'unsaved changes', 'cambios sin guardar'),
    'st_leitura' => $L('Somente leitura: apenas o ADMINISTRADOR altera os textos.', 'Read only: only the ADMINISTRADOR profile can change texts.', 'Solo lectura: solo el perfil ADMINISTRADOR modifica los textos.'),
    'b_pendente' => $L('Salvo no rascunho, ainda não publicado', 'Saved in the draft, not published yet', 'Guardado en el borrador, aún no publicado'),
    'b_sujo' => $L('Alterado e ainda não salvo', 'Changed and not saved yet', 'Modificado y aún no guardado'),
    'b_faltando' => $L('Tradução vazia: a tela mostra o texto em português', 'Empty translation: the screen shows the Portuguese text', 'Traducción vacía: la pantalla muestra el texto en portugués'),
    'b_ok' => $L('Publicado', 'Published', 'Publicado'),
    'publicado_era' => $L('Publicado hoje:', 'Currently published:', 'Publicado hoy:'),
    'desfazer' => $L('Voltar ao texto publicado', 'Revert to the published text', 'Volver al texto publicado'),
    'nenhum' => $L('Nenhum texto encontrado com esses filtros.', 'No texts match these filters.', 'Ningún texto coincide con estos filtros.'),
    'pt_obrigatorio' => $L('O texto em português é obrigatório.', 'The Portuguese text is required.', 'El texto en portugués es obligatorio.'),
    'sem_tags' => $L('Não use os caracteres < e >.', 'Do not use the < and > characters.', 'No use los caracteres < y >.'),
    'salvo' => $L('Rascunho salvo. Para os usuários verem, clique em Publicar.', 'Draft saved. Click Publish so users can see it.', 'Borrador guardado. Para que los usuarios lo vean, haga clic en Publicar.'),
    'nada_salvar' => $L('Não há alterações para salvar.', 'There are no changes to save.', 'No hay cambios para guardar.'),
    'salve_antes' => $L('Salve o rascunho antes de publicar.', 'Save the draft before publishing.', 'Guarde el borrador antes de publicar.'),
    'publicado_ok' => $L('Publicação nº {n} concluída: {q} texto(s). As telas já mostram os novos textos (reabra a aba da transação).', 'Publication #{n} done: {q} text(s). Screens already show the new texts (reopen the transaction tab).', 'Publicación n.º {n} concluida: {q} texto(s). Las pantallas ya muestran los nuevos textos (vuelva a abrir la pestaña).'),
    'descartado_ok' => $L('{q} tradução(ões) voltaram ao texto publicado.', '{q} translation(s) reverted to the published text.', '{q} traducción(es) volvieron al texto publicado.'),
    'sair_sem_salvar' => $L('Há alterações não salvas. Deseja sair mesmo assim?', 'There are unsaved changes. Leave anyway?', 'Hay cambios sin guardar. ¿Desea salir de todos modos?'),
    'pub_titulo' => $L('Publicar textos', 'Publish texts', 'Publicar textos'),
    'pub_texto' => $L('Os textos abaixo passam a valer em todas as telas, para todas as empresas.', 'The texts below will apply to all screens, for all companies.', 'Los textos siguientes pasarán a valer en todas las pantallas, para todas las empresas.'),
    'pub_obs' => $L('O que mudou (opcional)', 'What changed (optional)', 'Qué cambió (opcional)'),
    'pub_nada' => $L('Não há alterações no rascunho para publicar.', 'There are no draft changes to publish.', 'No hay cambios en el borrador para publicar.'),
    'des_titulo' => $L('Descartar rascunho', 'Discard draft', 'Descartar borrador'),
    'des_texto' => $L('Todas as alterações salvas e não publicadas voltam ao texto publicado. Esta ação não pode ser desfeita.', 'All saved and unpublished changes go back to the published text. This cannot be undone.', 'Todos los cambios guardados y no publicados vuelven al texto publicado. Esta acción no se puede deshacer.'),
    'cancelar' => $L('Cancelar', 'Cancel', 'Cancelar'),
    'fechar' => $L('Fechar', 'Close', 'Cerrar'),
    'confirmar' => $L('Confirmar', 'Confirm', 'Confirmar'),
    'h_numero' => $L('Nº', 'No.', 'N.º'),
    'h_data' => $L('Data', 'Date', 'Fecha'),
    'h_por' => $L('Publicado por', 'Published by', 'Publicado por'),
    'h_obs' => $L('O que mudou', 'What changed', 'Qué cambió'),
    'h_qtd' => $L('Textos', 'Texts', 'Textos'),
    'h_ver' => $L('Ver alterações', 'See changes', 'Ver cambios'),
    'h_carga' => $L('Carga inicial', 'Initial load', 'Carga inicial'),
    'h_antes' => $L('Antes', 'Before', 'Antes'),
    'h_depois' => $L('Depois', 'After', 'Después'),
    'h_vazio' => $L('(vazio)', '(empty)', '(vacío)'),
    'h_novo' => $L('(novo)', '(new)', '(nuevo)'),
    'h_nenhum' => $L('Nenhuma publicação.', 'No publications.', 'Ninguna publicación.'),
    'erro' => $L('Não foi possível concluir a operação.', 'The operation could not be completed.', 'No fue posible completar la operación.'),
    'tipo_TITULO' => $L('Título', 'Title', 'Título'),
    'tipo_COLUNA' => $L('Coluna', 'Column', 'Columna'),
    'tipo_BOTAO' => $L('Botão', 'Button', 'Botón'),
    'tipo_DICA' => $L('Dica', 'Tooltip', 'Ayuda'),
    'tipo_MENSAGEM' => $L('Mensagem', 'Message', 'Mensaje'),
    'tipo_ROTULO' => $L('Rótulo', 'Label', 'Rótulo'),
    'tipo_MENU' => $L('Menu', 'Menu', 'Menú'),
    'textos' => $L('textos', 'texts', 'textos'),
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
<link rel="stylesheet" href="../assets/css/administracao-idiomas.css?v=2">
</head>
<body>
<main class="ti" data-idiomas data-csrf="<?= $e($csrf) ?>" data-pode-editar="<?= $podeEditar ? '1' : '0' ?>" data-idioma="<?= $e($idioma) ?>">
  <header class="ti-top">
    <div class="ti-title">
      <span class="ti-title-icon" aria-hidden="true"><i class="bi bi-translate"></i></span>
      <div>
        <h1><?= $e($ui['titulo']) ?></h1>
        <p><?= $e($ui['subtitulo']) ?></p>
      </div>
    </div>
    <div class="ti-actions">
      <button type="button" class="btn btn-sm btn-success" data-acao="salvar" disabled title="Ctrl+S"><i class="bi bi-floppy me-1"></i><?= $e($ui['salvar']) ?></button>
      <button type="button" class="btn btn-sm btn-outline-success" data-acao="publicar"><i class="bi bi-cloud-arrow-up me-1"></i><?= $e($ui['publicar']) ?></button>
      <button type="button" class="btn btn-sm btn-outline-secondary" data-acao="historico"><i class="bi bi-clock-history me-1"></i><?= $e($ui['historico']) ?></button>
      <button type="button" class="btn btn-sm btn-outline-danger" data-acao="descartar"><i class="bi bi-arrow-counterclockwise me-1"></i><?= $e($ui['descartar']) ?></button>
      <button type="button" class="btn btn-sm btn-outline-info" data-acao="ajuda" data-open-transacao-ajuda data-help-transaction="administracao_idiomas" title="<?= $e($ui['ajuda_titulo']) ?>" aria-label="<?= $e($ui['ajuda']) ?>"><i class="bi bi-question-circle me-1"></i><?= $e($ui['ajuda']) ?></button>
    </div>
  </header>

  <div class="ti-status" data-status aria-live="polite"><span class="ti-pill"><?= $e($ui['carregando']) ?></span></div>
  <div class="ti-alert d-none" data-alerta role="alert"></div>

  <div class="ti-body">
    <aside class="ti-groups" aria-label="<?= $e($ui['grupos']) ?>">
      <div class="ti-groups-head"><?= $e($ui['grupos']) ?></div>
      <div class="ti-groups-list" data-grupos role="listbox"></div>
      <label class="form-check form-switch ti-previa">
        <input class="form-check-input" type="checkbox" role="switch" data-previa <?= $podeEditar ? '' : 'disabled' ?>>
        <span class="form-check-label"><?= $e($ui['previa']) ?></span>
      </label>
    </aside>
    <section class="ti-main">
      <div class="ti-filters">
        <div class="ti-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" class="form-control form-control-sm" data-busca placeholder="<?= $e($ui['buscar']) ?>" aria-label="<?= $e($ui['buscar']) ?>"></div>
        <div class="ti-chips" role="group">
          <button type="button" class="ti-chip" data-filtro="sujo" aria-pressed="false"><span class="ti-dot is-dirty"></span><?= $e($ui['f_alterados']) ?> <b data-qtd="sujo">0</b></button>
          <button type="button" class="ti-chip" data-filtro="pendente" aria-pressed="false"><span class="ti-dot is-pending"></span><?= $e($ui['f_pendentes']) ?> <b data-qtd="pendente">0</b></button>
          <button type="button" class="ti-chip" data-filtro="faltando" aria-pressed="false"><span class="ti-dot is-missing"></span><?= $e($ui['f_faltando']) ?> <b data-qtd="faltando">0</b></button>
        </div>
      </div>
      <div class="ti-table-wrap">
        <table class="ti-table">
          <thead><tr>
            <th class="ti-col-onde"><?= $e($ui['c_texto']) ?></th>
            <th class="ti-col-lang"><span class="ti-flag">PT</span> Português</th>
            <th class="ti-col-lang"><span class="ti-flag">EN</span> English</th>
            <th class="ti-col-lang"><span class="ti-flag">ES</span> Español</th>
            <th class="ti-col-sit"><span class="visually-hidden"><?= $e($ui['c_situacao']) ?></span></th>
          </tr></thead>
          <tbody data-linhas><tr><td colspan="5" class="ti-empty"><?= $e($ui['carregando']) ?></td></tr></tbody>
        </table>
      </div>
    </section>
  </div>
</main>

<div class="modal fade" id="tiModalPublicar" tabindex="-1" aria-labelledby="tiModalPublicarTitulo" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="tiModalPublicarTitulo"><i class="bi bi-cloud-arrow-up me-2"></i><?= $e($ui['pub_titulo']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $e($ui['fechar']) ?>"></button></div>
    <div class="modal-body">
      <p class="small text-muted mb-2"><?= $e($ui['pub_texto']) ?></p>
      <div class="ti-diff" data-pub-lista></div>
      <label class="form-label small fw-semibold mt-3" for="tiObservacao"><?= $e($ui['pub_obs']) ?></label>
      <textarea class="form-control form-control-sm" id="tiObservacao" rows="2" maxlength="500"></textarea>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= $e($ui['cancelar']) ?></button><button type="button" class="btn btn-sm btn-success" data-confirmar-publicacao><i class="bi bi-cloud-arrow-up me-1"></i><?= $e($ui['publicar']) ?></button></div>
  </div></div>
</div>

<div class="modal fade" id="tiModalDescartar" tabindex="-1" aria-labelledby="tiModalDescartarTitulo" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="tiModalDescartarTitulo"><i class="bi bi-arrow-counterclockwise me-2"></i><?= $e($ui['des_titulo']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $e($ui['fechar']) ?>"></button></div>
    <div class="modal-body"><p class="mb-0"><?= $e($ui['des_texto']) ?></p></div>
    <div class="modal-footer"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= $e($ui['cancelar']) ?></button><button type="button" class="btn btn-sm btn-danger" data-confirmar-descarte><?= $e($ui['confirmar']) ?></button></div>
  </div></div>
</div>

<div class="modal fade" id="tiModalHistorico" tabindex="-1" aria-labelledby="tiModalHistoricoTitulo" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="tiModalHistoricoTitulo"><i class="bi bi-clock-history me-2"></i><?= $e($ui['historico']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $e($ui['fechar']) ?>"></button></div>
    <div class="modal-body p-0">
      <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 ti-hist">
        <thead><tr><th><?= $e($ui['h_numero']) ?></th><th><?= $e($ui['h_data']) ?></th><th><?= $e($ui['h_por']) ?></th><th><?= $e($ui['h_obs']) ?></th><th class="text-end"><?= $e($ui['h_qtd']) ?></th><th></th></tr></thead>
        <tbody data-historico><tr><td colspan="6"><?= $e($ui['carregando']) ?></td></tr></tbody>
      </table></div>
      <div class="ti-hist-itens d-none" data-historico-itens></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= $e($ui['fechar']) ?></button></div>
  </div></div>
</div>

<script type="application/json" id="tiTextos"><?= json_encode($ui, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script>
<script src="../assets/js/ajuda-transacao.js?v=1"></script>
<script src="../assets/js/administracao-idiomas.js?v=3"></script>
</body>
</html>
