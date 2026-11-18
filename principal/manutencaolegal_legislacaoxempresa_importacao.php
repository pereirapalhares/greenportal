<?php
declare(strict_types=1);
/*
 * Manutenção Legal › Legislação x Empresas › Importar planilha (migração 397, pedido do usuário de 18/11/2026).
 * Substitui o script legado legislacaoxempresax_v2.php. Duas etapas, nada é gravado sem autorização:
 *   1. Enviar e conferir: lê a planilha, compara com o portal e mostra o pedido (Resumo, Inclusões, Trocas de tipo,
 *      Retiradas — só as marcadas saem —, Padrões a criar, Problemas);
 *   2. Autorizar e aplicar (ou Descartar): o serviço LEGISLACAO_IMPORTACAO aplica em segundo plano e manda o alerta.
 * Acesso: permissão LEGISLACAO_X_EMPRESAS e perfil ADMINISTRADOR (quem envia pode autorizar). Abre no popup padrão a partir
 * do botão "Importar planilha" do grid, ou pelo link do alerta.
 */
require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/LegislacaoImportacao.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

TransactionSecurity::connect('LEGISLACAO_X_EMPRESAS');
if (($_SESSION['usuario']['codigo_perfil'] ?? '') !== 'ADMINISTRADOR') { http_response_code(403); exit('Somente o ADMINISTRADOR importa a planilha de Legislação x Empresas.'); }

const LEGIMP_TEXTOS = [
    'titulo' => ['Importar planilha — Legislação x Empresas', 'Import spreadsheet — Legislation x Companies', 'Importar planilla — Legislación x Empresas'],
    'enviar_titulo' => ['Enviar a planilha do mês', 'Send the monthly spreadsheet', 'Enviar la planilla del mes'],
    'enviar_texto' => ['A1 = "Codigo". Coluna A: código da lei. Linha 1: nome das unidades. Células: MATRIZ ou uma letra (padrão). Nada é gravado agora: o portal confere a planilha e mostra o que mudaria; as alterações só são gravadas quando você autorizar.', 'A1 = "Codigo". Column A: law code. Row 1: unit names. Cells: MATRIZ or a letter (standard). Nothing is saved now: the portal checks the spreadsheet and shows what would change; changes are saved only when you authorize them.', 'A1 = "Codigo". Columna A: código de la ley. Fila 1: nombre de las unidades. Celdas: MATRIZ o una letra (patrón). Nada se graba ahora: el portal revisa la planilla y muestra lo que cambiaría; los cambios solo se graban cuando usted los autorice.'],
    'arquivo' => ['Arquivo (.xls, .xlsx ou .csv)', 'File (.xls, .xlsx or .csv)', 'Archivo (.xls, .xlsx o .csv)'],
    'btn_conferir' => ['Enviar e conferir', 'Send and check', 'Enviar y revisar'],
    'conferindo' => ['Conferindo a planilha com o portal… pode levar alguns segundos.', 'Checking the spreadsheet against the portal… this may take a few seconds.', 'Revisando la planilla con el portal… puede tardar algunos segundos.'],
    'escolha_arquivo' => ['Escolha o arquivo da planilha.', 'Choose the spreadsheet file.', 'Elija el archivo de la planilla.'],
    'pedidos' => ['Pedidos anteriores', 'Previous requests', 'Pedidos anteriores'],
    'nenhum_pedido' => ['Nenhum pedido ainda.', 'No requests yet.', 'Ningún pedido todavía.'],
    'col_pedido' => ['Nº', 'No.', 'Nº'],
    'col_arquivo' => ['Arquivo', 'File', 'Archivo'],
    'col_ref' => ['Mês', 'Month', 'Mes'],
    'col_situacao' => ['Situação', 'Status', 'Situación'],
    'col_enviado' => ['Enviado', 'Sent', 'Enviado'],
    'col_autorizado' => ['Autorizado', 'Authorized', 'Autorizado'],
    'col_mudancas' => ['Incluir / Trocar / Retirar', 'Add / Change / Remove', 'Incluir / Cambiar / Retirar'],
    'col_problemas' => ['Problemas', 'Issues', 'Problemas'],
    'abrir' => ['Abrir', 'Open', 'Abrir'],
    'voltar' => ['Voltar à lista', 'Back to the list', 'Volver a la lista'],
    'pedido' => ['Pedido {n}', 'Request {n}', 'Pedido {n}'],
    'nao_encontrado' => ['Pedido não encontrado.', 'Request not found.', 'Pedido no encontrado.'],
    'st_CONFERIDA' => ['Aguardando autorização', 'Awaiting authorization', 'Esperando autorización'],
    'st_AUTORIZADA' => ['Autorizado — na fila', 'Authorized — queued', 'Autorizado — en cola'],
    'st_APLICANDO' => ['Aplicando…', 'Applying…', 'Aplicando…'],
    'st_APLICADA' => ['Aplicado', 'Applied', 'Aplicado'],
    'st_APLICADA_COM_ERROS' => ['Aplicado com erros', 'Applied with errors', 'Aplicado con errores'],
    'st_DESCARTADA' => ['Descartado', 'Discarded', 'Descartado'],
    'aba_resumo' => ['Resumo', 'Summary', 'Resumen'],
    'aba_incluir' => ['Inclusões', 'Additions', 'Inclusiones'],
    'aba_trocar' => ['Trocas de tipo', 'Type changes', 'Cambios de tipo'],
    'aba_retirar' => ['Retiradas', 'Removals', 'Retiros'],
    'aba_padroes' => ['Padrões a criar', 'Standards to create', 'Patrones a crear'],
    'aba_problemas' => ['Problemas', 'Issues', 'Problemas'],
    'aba_log' => ['Log', 'Log', 'Registro'],
    'r_incluir' => ['vínculos a incluir', 'links to add', 'vínculos a incluir'],
    'r_trocar' => ['tipos a trocar', 'types to change', 'tipos a cambiar'],
    'r_retirar' => ['fora da planilha (retirar só se marcar)', 'not in the spreadsheet (removed only if checked)', 'fuera de la planilla (se retiran solo si se marcan)'],
    'r_manter' => ['já estão iguais', 'already the same', 'ya son iguales'],
    'r_padroes' => ['padrões novos', 'new standards', 'patrones nuevos'],
    'r_problemas' => ['problemas', 'issues', 'problemas'],
    'r_planilha' => ['Planilha: {l} leis válidas de {t} linhas; {u} unidades reconhecidas de {c} colunas.', 'Spreadsheet: {l} valid laws out of {t} rows; {u} units recognized out of {c} columns.', 'Planilla: {l} leyes válidas de {t} filas; {u} unidades reconocidas de {c} columnas.'],
    'r_por_empresa' => ['Por empresa', 'By company', 'Por empresa'],
    'r_unidades' => ['Colunas reconhecidas', 'Recognized columns', 'Columnas reconocidas'],
    'r_desconsideradas' => ['Colunas desconsideradas: {n} (unidade ainda não cadastrada no portal; nada é validado nem gravado nelas)', 'Columns disregarded: {n} (unit not yet registered in the portal; nothing is checked or saved in them)', 'Columnas no consideradas: {n} (unidad aún no registrada en el portal; nada se valida ni se graba en ellas)'],
    'r_data' => ['As leis que receberem vínculo incluído ou trocado ficam com a data de atualização de hoje (entram em "Normas Inseridas" da RCL do mês).', 'Laws that get a link added or changed get today as the update date (they appear in "Added standards" of the month\'s RCL).', 'Las leyes que reciban un vínculo incluido o cambiado quedan con la fecha de actualización de hoy (entran en "Normas incluidas" de la RCL del mes).'],
    'c_empresa' => ['Empresa', 'Company', 'Empresa'],
    'c_unidade' => ['Unidade', 'Unit', 'Unidad'],
    'c_lei' => ['Lei', 'Law', 'Ley'],
    'c_atual' => ['Hoje', 'Current', 'Hoy'],
    'c_novo' => ['Planilha', 'Spreadsheet', 'Planilla'],
    'c_celula' => ['Célula', 'Cell', 'Celda'],
    'c_resultado' => ['Resultado', 'Result', 'Resultado'],
    'c_retirar' => ['Retirar?', 'Remove?', '¿Retirar?'],
    'c_padrao' => ['Padrão novo', 'New standard', 'Patrón nuevo'],
    'c_principal' => ['Lei principal', 'Main law', 'Ley principal'],
    'novo_padrao' => ['cria o padrão', 'creates the standard', 'crea el patrón'],
    'filtrar' => ['Filtrar', 'Filter', 'Filtrar'],
    'marcar_todas' => ['Marcar todas', 'Check all', 'Marcar todas'],
    'desmarcar_todas' => ['Desmarcar todas', 'Uncheck all', 'Desmarcar todas'],
    'retirar_texto' => ['Vínculos que existem no portal para as leis e unidades da planilha, mas com a célula vazia. Só saem os que você marcar.', 'Links that exist in the portal for the laws and units in the spreadsheet, but with an empty cell. Only the ones you check are removed.', 'Vínculos que existen en el portal para las leyes y unidades de la planilla, pero con la celda vacía. Solo se retiran los que usted marque.'],
    'nada' => ['Nada nesta aba.', 'Nothing in this tab.', 'Nada en esta pestaña.'],
    'aviso' => ['aviso', 'notice', 'aviso'],
    'btn_autorizar' => ['Autorizar e aplicar', 'Authorize and apply', 'Autorizar y aplicar'],
    'btn_descartar' => ['Descartar', 'Discard', 'Descartar'],
    'btn_confirmar' => ['Confirmar', 'Confirm', 'Confirmar'],
    'btn_cancelar' => ['Cancelar', 'Cancel', 'Cancelar'],
    'confirmar_titulo' => ['Autorizar a importação', 'Authorize the import', 'Autorizar la importación'],
    'confirmar_texto' => ['Serão gravados no portal: {i} inclusões, {t} trocas de tipo, {r} retiradas marcadas e {p} padrões novos. A aplicação roda em segundo plano e o resultado chega nos alertas.', 'This will be saved in the portal: {i} additions, {t} type changes, {r} checked removals and {p} new standards. It runs in the background and the result arrives in the alerts.', 'Se grabarán en el portal: {i} inclusiones, {t} cambios de tipo, {r} retiros marcados y {p} patrones nuevos. Se aplica en segundo plano y el resultado llega en las alertas.'],
    'descartar_texto' => ['Descartar este pedido? Nada será gravado.', 'Discard this request? Nothing will be saved.', '¿Descartar este pedido? No se grabará nada.'],
    'autorizado_msg' => ['Autorizado. O serviço aplica em até 1 minuto; o resultado chega nos alertas.', 'Authorized. The service applies it within 1 minute; the result arrives in the alerts.', 'Autorizado. El servicio lo aplica en hasta 1 minuto; el resultado llega en las alertas.'],
    'descartado_msg' => ['Pedido descartado. Nada foi gravado.', 'Request discarded. Nothing was saved.', 'Pedido descartado. No se grabó nada.'],
    'acompanhando' => ['A página se atualiza sozinha enquanto o pedido é aplicado.', 'This page refreshes by itself while the request is applied.', 'La página se actualiza sola mientras se aplica el pedido.'],
    'enviado_por' => ['Enviado por {n} em {d}', 'Sent by {n} on {d}', 'Enviado por {n} el {d}'],
    'autorizado_por' => ['Autorizado por {n} em {d}', 'Authorized by {n} on {d}', 'Autorizado por {n} el {d}'],
    'descartado_por' => ['Descartado por {n} em {d}', 'Discarded by {n} on {d}', 'Descartado por {n} el {d}'],
    'resultado' => ['Resultado: {a} aplicados, {g} ignorados, {e} erros.', 'Result: {a} applied, {g} skipped, {e} errors.', 'Resultado: {a} aplicados, {g} ignorados, {e} errores.'],
    'erro_generico' => ['Não foi possível concluir a operação.', 'The operation could not be completed.', 'No fue posible concluir la operación.'],
    'erro_pagina' => ['A página expirou. Feche e abra de novo.', 'The page has expired. Close it and open it again.', 'La página expiró. Ciérrela y ábrala de nuevo.'],
    'erro_upload' => ['O arquivo não chegou ao servidor (tamanho máximo: {m}).', 'The file did not reach the server (maximum size: {m}).', 'El archivo no llegó al servidor (tamaño máximo: {m}).'],
    'it_PENDENTE' => ['pendente', 'pending', 'pendiente'],
    'it_APLICADO' => ['aplicado', 'applied', 'aplicado'],
    'it_IGNORADO' => ['ignorado', 'skipped', 'ignorado'],
    'it_ERRO' => ['erro', 'error', 'error'],
];
function ti(string $k, array $v = []): string
{
    $d = LEGIMP_TEXTOS[$k] ?? [$k, $k, $k];
    return IdiomaService::f('manutencaolegal_legislacaoxempresa.importacao.' . $k, ['pt' => $d[0], 'en' => $d[1], 'es' => $d[2]], $v);
}
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$h = static fn(string $k, array $v = []): string => htmlspecialchars(ti($k, $v), ENT_QUOTES, 'UTF-8');
$dt = static fn(?string $v): string => ($v && ($t = strtotime($v))) ? date('d/m/Y H:i', $t) : '';

if (empty($_SESSION['legimp_csrf'])) $_SESSION['legimp_csrf'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['legimp_csrf'];
$usuario = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$nomeUsuario = trim((string)($_SESSION['usuario']['nome'] ?? $_SESSION['usuario']['login'] ?? '')) ?: ('usuário ' . $usuario);

// ------------------------------------------------------------------ ações (JSON)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $resp = static function (bool $ok, array $extra = []): never { echo json_encode(['ok' => $ok] + $extra, JSON_UNESCAPED_UNICODE); exit; };
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $resp(false, ['message' => ti('erro_pagina')]);
    $acao = (string)($_POST['acao'] ?? '');
    try {
        if ($acao === 'conferir') {
            $f = $_FILES['planilha'] ?? null;
            if (!$f || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
                $resp(false, ['message' => ($f && (int)$f['error'] !== UPLOAD_ERR_NO_FILE) ? ti('erro_upload', ['m' => ini_get('upload_max_filesize')]) : ti('escolha_arquivo')]);
            }
            @set_time_limit(300);
            $id = LegislacaoImportacao::conferir((string)$f['tmp_name'], basename((string)$f['name']), [
                'codusuario' => $usuario, 'nome' => $nomeUsuario,
                'codempresa' => (int)($_SESSION['tenant']['codempresa'] ?? 0), 'banco' => (string)($_SESSION['tenant']['banco_dados'] ?? ''),
            ]);
            $resp(true, ['codimportacao' => $id]);
        }
        $id = (int)($_POST['codimportacao'] ?? 0);
        if ($acao === 'autorizar') {
            $ret = json_decode((string)($_POST['retiradas'] ?? '[]'), true);
            LegislacaoImportacao::autorizar($id, $usuario, $nomeUsuario, is_array($ret) ? $ret : []);
            $resp(true, ['message' => ti('autorizado_msg')]);
        }
        if ($acao === 'descartar') {
            LegislacaoImportacao::descartar($id, $usuario, $nomeUsuario);
            $resp(true, ['message' => ti('descartado_msg')]);
        }
        $resp(false, ['message' => ti('erro_generico')]);
    } catch (Throwable $err) {
        $m = $err instanceof PDOException ? IdiomaService::mensagemBanco((string)preg_replace('/^.*?: \d+ /', '', (string)($err->errorInfo[2] ?? $err->getMessage()))) : $err->getMessage();
        $resp(false, ['message' => $m !== '' ? $m : ti('erro_generico')]);
    }
}

// ------------------------------------------------------------------ dados da página
$id = max(0, (int)($_GET['codimportacao'] ?? 0));
$pedido = $id > 0 ? LegislacaoImportacao::pedido($id) : null;
$itens = $pedido ? LegislacaoImportacao::itens($id) : [];
$pedidos = $pedido ? [] : LegislacaoImportacao::pedidos(50);
$porAcao = ['INCLUIR' => [], 'TROCAR' => [], 'RETIRAR' => []];
foreach ($itens as $it) $porAcao[$it['acao']][] = $it;
$resumo = $pedido ? (json_decode((string)($pedido['resumo'] ?? ''), true) ?: []) : [];
$problemas = $pedido ? (json_decode((string)($pedido['problemas'] ?? ''), true) ?: []) : [];
$aberto = $pedido && $pedido['status'] === 'CONFERIDA';
$emAndamento = $pedido && in_array($pedido['status'], ['AUTORIZADA', 'APLICANDO'], true);
$aplicado = $pedido && !in_array($pedido['status'], ['CONFERIDA', 'DESCARTADA'], true);
$corStatus = ['CONFERIDA' => 'warning', 'AUTORIZADA' => 'info', 'APLICANDO' => 'info', 'APLICADA' => 'success', 'APLICADA_COM_ERROS' => 'danger', 'DESCARTADA' => 'secondary'];
$badge = static fn(string $s): string => '<span class="badge text-bg-' . ($corStatus[$s] ?? 'secondary') . '">' . htmlspecialchars(ti('st_' . $s), ENT_QUOTES, 'UTF-8') . '</span>';
$tipoTxt = static fn(?string $tipo, ?string $codigo): string => $tipo === null || $tipo === '' ? '—' : htmlspecialchars($tipo . ($codigo ? ' · ' . $codigo : ''), ENT_QUOTES, 'UTF-8');
$resultadoTd = static function (array $it) use ($e, $aplicado): string {
    if (!$aplicado) return '';
    $cor = ['APLICADO' => 'success', 'IGNORADO' => 'secondary', 'ERRO' => 'danger'][$it['status']] ?? 'warning';
    return '<td><span class="badge text-bg-' . $cor . '">' . htmlspecialchars(ti('it_' . $it['status']), ENT_QUOTES, 'UTF-8') . '</span> <span class="small text-muted">' . $e($it['mensagem'] ?? '') . '</span></td>';
};
?><!DOCTYPE html>
<html lang="<?= $e(['en' => 'en', 'es' => 'es'][IdiomaService::idioma()] ?? 'pt-BR') ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h('titulo') ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet"><link href="../assets/css/app.css" rel="stylesheet"><link href="../assets/css/main.css?v=18" rel="stylesheet"><link href="../assets/css/design-system.css?v=3" rel="stylesheet">
<style>
body{background:transparent;font-size:13px}
.li-pagina{padding:10px 14px 70px}
.li-cartoes{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 12px}
.li-cartao{border:1px solid var(--gp-border,#d9e4df);border-radius:8px;padding:8px 12px;min-width:130px;background:#fff}
.li-cartao b{display:block;font-size:20px;line-height:1.1;color:var(--gp-primary,#2f7d5b)}
.li-cartao.alerta b{color:#b45309}.li-cartao.perigo b{color:#b91c1c}
.li-abas{display:flex;gap:4px;border-bottom:1px solid var(--gp-border,#d9e4df);flex-wrap:wrap}
.li-aba{border:1px solid transparent;border-bottom:0;background:none;padding:6px 12px;color:var(--gp-muted,#6b8279);border-radius:6px 6px 0 0;white-space:nowrap}
.li-aba.active{background:#fff;border-color:var(--gp-border,#d9e4df);color:var(--gp-primary,#2f7d5b);font-weight:600;margin-bottom:-1px}
.li-aba .badge{font-weight:500}
.li-painel{background:#fff;border:1px solid var(--gp-border,#d9e4df);border-top:0;padding:10px}
.li-painel[hidden]{display:none}
.li-tabela{max-height:calc(100vh - 330px);min-height:160px;overflow:auto}
.li-tabela table{margin:0}
.li-tabela thead th{position:sticky;top:0;background:#f4f8f6;z-index:1;white-space:nowrap}
.li-barra{position:fixed;left:0;right:0;bottom:0;background:#fff;border-top:1px solid var(--gp-border,#d9e4df);padding:8px 14px;display:flex;gap:8px;align-items:center;z-index:5}
.li-msg{margin-bottom:8px}
.li-msg .alert{white-space:pre-line}
.li-problema.aviso{color:#92400e}
.li-pagina .form-control{height:auto;min-height:0;padding:4px 8px;font-size:13px;line-height:1.4;border-radius:6px}
.li-pagina input[type=file].form-control{padding:3px 6px}
</style>
</head>
<body>
<main class="li-pagina" data-legimp data-csrf="<?= $e($csrf) ?>" data-codimportacao="<?= (int)$id ?>">
<div class="li-msg" data-msg></div>
<?php if (!$pedido): ?>
  <?php if ($id > 0): ?><div class="alert alert-warning py-2"><?= $h('nao_encontrado') ?></div><?php endif; ?>
  <section class="card mb-3"><div class="card-body">
    <h6 class="mb-1"><i class="bi bi-file-earmark-spreadsheet me-1"></i><?= $h('enviar_titulo') ?></h6>
    <p class="text-muted small mb-2"><?= $h('enviar_texto') ?></p>
    <form class="d-flex flex-wrap gap-2 align-items-end" data-form-upload>
      <div><label class="form-label small mb-1" for="liArquivo"><?= $h('arquivo') ?></label><input class="form-control form-control-sm" type="file" id="liArquivo" name="planilha" accept=".xls,.xlsx,.csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" style="min-width:320px"></div>
      <button class="btn btn-sm btn-success" type="submit" data-btn-conferir><i class="bi bi-upload me-1"></i><?= $h('btn_conferir') ?></button>
    </form>
  </div></section>
  <h6><?= $h('pedidos') ?></h6>
  <?php if (!$pedidos): ?><p class="text-muted"><?= $h('nenhum_pedido') ?></p><?php else: ?>
  <div class="li-tabela" style="max-height:calc(100vh - 260px)"><table class="table table-sm table-hover align-middle">
    <thead><tr><th><?= $h('col_pedido') ?></th><th><?= $h('col_arquivo') ?></th><th><?= $h('col_ref') ?></th><th><?= $h('col_situacao') ?></th><th><?= $h('col_enviado') ?></th><th><?= $h('col_autorizado') ?></th><th><?= $h('col_mudancas') ?></th><th><?= $h('col_problemas') ?></th><th></th></tr></thead>
    <tbody><?php foreach ($pedidos as $p): ?>
      <tr><td><?= (int)$p['codimportacao'] ?></td><td><?= $e($p['arquivo_nome']) ?></td><td><?= $e($p['referencia'] ? substr((string)$p['referencia'], 5, 2) . '/' . substr((string)$p['referencia'], 0, 4) : '') ?></td><td><?= $badge((string)$p['status']) ?></td>
        <td><?= $e($p['enviado_por_nome']) ?><br><span class="text-muted small"><?= $e($dt($p['enviado_em'])) ?></span></td>
        <td><?= $e($p['autorizado_por_nome'] ?? '') ?><br><span class="text-muted small"><?= $e($dt($p['autorizado_em'] ?? null)) ?></span></td>
        <td><?= (int)$p['total_incluir'] ?> / <?= (int)$p['total_trocar'] ?> / <?= (int)$p['total_retirar_marcadas'] ?><span class="text-muted"> (<?= (int)$p['total_retirar'] ?>)</span></td>
        <td><?= (int)$p['total_problemas'] ?></td>
        <td><a class="btn btn-sm btn-outline-primary" href="?codimportacao=<?= (int)$p['codimportacao'] ?>"><?= $h('abrir') ?></a></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
<?php else: ?>
  <div class="d-flex flex-wrap align-items-center gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="?"><i class="bi bi-arrow-left me-1"></i><?= $h('voltar') ?></a>
    <h6 class="m-0"><?= $h('pedido', ['n' => (int)$pedido['codimportacao']]) ?> · <?= $e($pedido['arquivo_nome']) ?></h6>
    <?= $badge((string)$pedido['status']) ?>
  </div>
  <div class="small text-muted mt-1">
    <?= $h('enviado_por', ['n' => $pedido['enviado_por_nome'], 'd' => $dt($pedido['enviado_em'])]) ?>
    <?php if (!empty($pedido['autorizado_em'])): ?> · <?= $h('autorizado_por', ['n' => $pedido['autorizado_por_nome'], 'd' => $dt($pedido['autorizado_em'])]) ?><?php endif; ?>
    <?php if (!empty($pedido['descartado_em'])): ?> · <?= $h('descartado_por', ['n' => $pedido['descartado_por_nome'], 'd' => $dt($pedido['descartado_em'])]) ?><?php endif; ?>
    <?php if ($pedido['total_aplicados'] !== null): ?> · <b><?= $h('resultado', ['a' => (int)$pedido['total_aplicados'], 'g' => (int)$pedido['total_ignorados'], 'e' => (int)$pedido['total_erros']]) ?></b><?php endif; ?>
  </div>
  <?php if ($emAndamento): ?><div class="alert alert-info py-2 mt-2 mb-0"><i class="bi bi-hourglass-split me-1"></i><?= $h('acompanhando') ?></div><?php endif; ?>
  <div class="li-cartoes">
    <div class="li-cartao"><b><?= (int)$pedido['total_incluir'] ?></b><?= $h('r_incluir') ?></div>
    <div class="li-cartao"><b><?= (int)$pedido['total_trocar'] ?></b><?= $h('r_trocar') ?></div>
    <div class="li-cartao alerta"><b data-total-retirar><?= (int)$pedido['total_retirar_marcadas'] ?> / <?= (int)$pedido['total_retirar'] ?></b><?= $h('r_retirar') ?></div>
    <div class="li-cartao"><b><?= (int)$pedido['total_padroes'] ?></b><?= $h('r_padroes') ?></div>
    <div class="li-cartao"><b><?= (int)$pedido['total_manter'] ?></b><?= $h('r_manter') ?></div>
    <div class="li-cartao perigo"><b><?= (int)$pedido['total_problemas'] ?></b><?= $h('r_problemas') ?></div>
  </div>
  <?php
    $abas = ['resumo' => [ti('aba_resumo'), null], 'incluir' => [ti('aba_incluir'), count($porAcao['INCLUIR'])], 'trocar' => [ti('aba_trocar'), count($porAcao['TROCAR'])],
        'retirar' => [ti('aba_retirar'), count($porAcao['RETIRAR'])], 'padroes' => [ti('aba_padroes'), count($resumo['padroes'] ?? [])], 'problemas' => [ti('aba_problemas'), count($problemas)], 'log' => [ti('aba_log'), null]];
    $filtro = '<input type="search" class="form-control form-control-sm mb-2" style="max-width:320px" data-filtro placeholder="' . $h('filtrar') . '">';
    $cabItem = '<th>' . $h('c_empresa') . '</th><th>' . $h('c_unidade') . '</th><th>' . $h('c_lei') . '</th><th>' . $h('c_celula') . '</th>';
    $tdItem = static fn(array $it): string => '<td>' . $e($it['empresa_nome']) . '</td><td>' . $e($it['unidade_nome']) . '</td><td>' . $e($it['codigo_lei']) . '</td><td class="text-muted">' . $e($it['coluna'] . $it['linha']) . '</td>';
  ?>
  <nav class="li-abas" role="tablist">
    <?php foreach ($abas as $k => [$rot, $n]): ?><button type="button" class="li-aba" role="tab" data-aba="<?= $k ?>"><?= $e($rot) ?><?php if ($n !== null): ?> <span class="badge text-bg-light"><?= (int)$n ?></span><?php endif; ?></button><?php endforeach; ?>
  </nav>
  <section class="li-painel" data-painel="resumo" hidden>
    <p class="mb-1"><?= $h('r_planilha', ['l' => (int)($resumo['leis_validas'] ?? 0), 't' => (int)($resumo['linhas_planilha'] ?? 0), 'u' => (int)($resumo['unidades_validas'] ?? 0), 'c' => (int)($resumo['colunas_planilha'] ?? 0)]) ?></p>
    <p class="text-muted small"><i class="bi bi-calendar-check me-1"></i><?= $h('r_data') ?></p>
    <div class="row g-3">
      <div class="col-lg-6"><h6 class="small fw-bold"><?= $h('r_por_empresa') ?></h6>
        <table class="table table-sm"><thead><tr><th><?= $h('c_empresa') ?></th><th><?= $h('aba_incluir') ?></th><th><?= $h('aba_trocar') ?></th><th><?= $h('aba_retirar') ?></th><th><?= $h('r_manter') ?></th></tr></thead><tbody>
        <?php foreach (($resumo['por_empresa'] ?? []) as $emp => $t): ?><tr><td><?= $e($emp) ?></td><td><?= (int)$t['INCLUIR'] ?></td><td><?= (int)$t['TROCAR'] ?></td><td><?= (int)$t['RETIRAR'] ?></td><td><?= (int)$t['MANTER'] ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      <div class="col-lg-6"><h6 class="small fw-bold"><?= $h('r_unidades') ?></h6>
        <div class="li-tabela" style="max-height:300px;min-height:0"><table class="table table-sm"><tbody>
        <?php foreach (($resumo['unidades'] ?? []) as $un): ?><tr><td class="text-muted"><?= $e($un['coluna']) ?></td><td><?= $e($un['empresa']) ?></td><td><?= $e($un['unidade']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php if (!empty($resumo['unidades_desconsideradas'])): ?><details class="mt-2"><summary class="small text-muted"><?= $h('r_desconsideradas', ['n' => count($resumo['unidades_desconsideradas'])]) ?></summary>
        <div class="small text-muted mt-1"><?php foreach ($resumo['unidades_desconsideradas'] as $un): ?><span class="me-2 text-nowrap"><?= $e($un['coluna']) ?> · <?= $e($un['unidade']) ?></span> <?php endforeach; ?></div></details><?php endif; ?>
      </div>
    </div>
  </section>
  <section class="li-painel" data-painel="incluir" hidden><?php if (!$porAcao['INCLUIR']): ?><p class="text-muted m-0"><?= $h('nada') ?></p><?php else: ?><?= $filtro ?>
    <div class="li-tabela"><table class="table table-sm table-striped"><thead><tr><?= $cabItem ?><th><?= $h('c_novo') ?></th><?= $aplicado ? '<th>' . $h('c_resultado') . '</th>' : '' ?></tr></thead><tbody>
    <?php foreach ($porAcao['INCLUIR'] as $it): ?><tr><?= $tdItem($it) ?><td><?= $tipoTxt($it['tipo_novo'], $it['codigo_novo']) ?><?= (int)$it['padrao_novo'] ? ' <span class="badge text-bg-warning">' . $h('novo_padrao') . '</span>' : '' ?></td><?= $resultadoTd($it) ?></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?></section>
  <section class="li-painel" data-painel="trocar" hidden><?php if (!$porAcao['TROCAR']): ?><p class="text-muted m-0"><?= $h('nada') ?></p><?php else: ?><?= $filtro ?>
    <div class="li-tabela"><table class="table table-sm table-striped"><thead><tr><?= $cabItem ?><th><?= $h('c_atual') ?></th><th><?= $h('c_novo') ?></th><?= $aplicado ? '<th>' . $h('c_resultado') . '</th>' : '' ?></tr></thead><tbody>
    <?php foreach ($porAcao['TROCAR'] as $it): ?><tr><?= $tdItem($it) ?><td><?= $tipoTxt($it['tipo_atual'], $it['codigo_atual']) ?></td><td><b><?= $tipoTxt($it['tipo_novo'], $it['codigo_novo']) ?></b><?= (int)$it['padrao_novo'] ? ' <span class="badge text-bg-warning">' . $h('novo_padrao') . '</span>' : '' ?></td><?= $resultadoTd($it) ?></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?></section>
  <section class="li-painel" data-painel="retirar" hidden>
    <p class="small text-muted mb-2"><?= $h('retirar_texto') ?></p>
    <?php if (!$porAcao['RETIRAR']): ?><p class="text-muted m-0"><?= $h('nada') ?></p><?php else: ?>
    <div class="d-flex gap-2 align-items-start"><?= $filtro ?><?php if ($aberto): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-marcar="1"><?= $h('marcar_todas') ?></button><button type="button" class="btn btn-sm btn-outline-secondary" data-marcar="0"><?= $h('desmarcar_todas') ?></button><?php endif; ?></div>
    <div class="li-tabela"><table class="table table-sm table-striped"><thead><tr><th><?= $h('c_retirar') ?></th><?= $cabItem ?><th><?= $h('c_atual') ?></th><?= $aplicado ? '<th>' . $h('c_resultado') . '</th>' : '' ?></tr></thead><tbody>
    <?php foreach ($porAcao['RETIRAR'] as $it): ?><tr><td><input class="form-check-input" type="checkbox" data-retirar="<?= (int)$it['coditem'] ?>"<?= (int)$it['selecionado'] ? ' checked' : '' ?><?= $aberto ? '' : ' disabled' ?>></td><?= $tdItem($it) ?><td><?= $tipoTxt($it['tipo_atual'], $it['codigo_atual']) ?></td><?= $resultadoTd($it) ?></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?></section>
  <section class="li-painel" data-painel="padroes" hidden><?php if (empty($resumo['padroes'])): ?><p class="text-muted m-0"><?= $h('nada') ?></p><?php else: ?>
    <div class="li-tabela"><table class="table table-sm table-striped"><thead><tr><th><?= $h('c_padrao') ?></th><th><?= $h('c_principal') ?></th></tr></thead><tbody>
    <?php foreach ($resumo['padroes'] as $pad => $principal): ?><tr><td><?= $e($pad) ?></td><td><?= $e($principal) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?></section>
  <section class="li-painel" data-painel="problemas" hidden><?php if (!$problemas): ?><p class="text-muted m-0"><?= $h('nada') ?></p><?php else: ?><?= $filtro ?>
    <div class="li-tabela"><table class="table table-sm table-striped"><tbody>
    <?php foreach ($problemas as $pb): ?><tr class="li-problema<?= !empty($pb['aviso']) ? ' aviso' : '' ?>"><td style="width:24px"><i class="bi <?= !empty($pb['aviso']) ? 'bi-info-circle' : 'bi-exclamation-triangle text-danger' ?>"></i></td><td><?= $e($pb['texto'] ?? '') ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?></section>
  <section class="li-painel" data-painel="log" hidden><pre class="small m-0" style="white-space:pre-wrap"><?= $e($pedido['log'] ?? '') ?></pre></section>
  <?php if ($aberto): ?>
  <div class="li-barra">
    <button type="button" class="btn btn-sm btn-success" data-acao="autorizar"><i class="bi bi-check2-circle me-1"></i><?= $h('btn_autorizar') ?></button>
    <button type="button" class="btn btn-sm btn-outline-danger" data-acao="descartar"><i class="bi bi-x-circle me-1"></i><?= $h('btn_descartar') ?></button>
    <span class="small text-muted ms-2" data-resumo-retirar></span>
  </div>
  <div class="modal fade" id="liConfirmar" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header py-2"><h6 class="modal-title" data-confirmar-titulo></h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $h('btn_cancelar') ?>"></button></div>
    <div class="modal-body" data-confirmar-texto></div>
    <div class="modal-footer py-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= $h('btn_cancelar') ?></button><button type="button" class="btn btn-sm btn-success" data-confirmar-ok><?= $h('btn_confirmar') ?></button></div>
  </div></div></div>
  <?php endif; ?>
<?php endif; ?>
</main>
<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script>
<script>
(function () {
    'use strict';
    var raiz = document.querySelector('[data-legimp]'); if (!raiz) return;
    var T = <?= json_encode([
        'conferindo' => ti('conferindo'), 'escolha' => ti('escolha_arquivo'), 'erro' => ti('erro_generico'),
        'confirmarTitulo' => ti('confirmar_titulo'), 'descartarTitulo' => ti('btn_descartar'), 'descartarTexto' => ti('descartar_texto'),
        'confirmarTexto' => ti('confirmar_texto', ['i' => (int)($pedido['total_incluir'] ?? 0), 't' => (int)($pedido['total_trocar'] ?? 0), 'r' => '{r}', 'p' => (int)($pedido['total_padroes'] ?? 0)]),
        'totalRetirar' => (int)($pedido['total_retirar'] ?? 0),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var csrf = raiz.getAttribute('data-csrf'), cod = raiz.getAttribute('data-codimportacao');
    function msg(texto, tipo) { var m = raiz.querySelector('[data-msg]'); m.innerHTML = ''; if (!texto) return; var d = document.createElement('div'); d.className = 'alert alert-' + (tipo || 'info') + ' py-2 mb-0'; d.textContent = texto; m.appendChild(d); }
    function enviar(dados) {
        dados.append('csrf', csrf);
        return fetch(window.location.pathname, { method: 'POST', body: dados, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); });
    }
    // ---------------------------------------------------- envio
    var form = raiz.querySelector('[data-form-upload]');
    if (form) form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var arq = form.querySelector('input[type=file]'); if (!arq.files.length) { msg(T.escolha, 'warning'); return; }
        var btn = form.querySelector('[data-btn-conferir]'); btn.disabled = true;
        msg(T.conferindo, 'info');
        var d = new FormData(); d.append('acao', 'conferir'); d.append('planilha', arq.files[0]);
        enviar(d).then(function (r) {
            if (r && r.ok) { window.location.href = window.location.pathname + '?codimportacao=' + encodeURIComponent(r.codimportacao); return; }
            btn.disabled = false; msg((r && r.message) || T.erro, 'danger');
        }).catch(function () { btn.disabled = false; msg(T.erro, 'danger'); });
    });
    // ---------------------------------------------------- abas e filtros
    var abas = raiz.querySelectorAll('[data-aba]');
    function abrir(id) {
        abas.forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-aba') === id); });
        raiz.querySelectorAll('[data-painel]').forEach(function (p) { p.hidden = p.getAttribute('data-painel') !== id; });
        try { sessionStorage.setItem('gp.legimp.aba', id); } catch (e) { }
    }
    abas.forEach(function (b) { b.addEventListener('click', function () { abrir(b.getAttribute('data-aba')); }); });
    if (abas.length) { var salva = null; try { salva = sessionStorage.getItem('gp.legimp.aba'); } catch (e) { } abrir(salva && raiz.querySelector('[data-aba="' + salva + '"]') ? salva : 'resumo'); }
    raiz.querySelectorAll('[data-filtro]').forEach(function (f) {
        f.addEventListener('input', function () {
            var q = f.value.trim().toLowerCase(), painel = f.closest('[data-painel]');
            painel.querySelectorAll('tbody tr').forEach(function (tr) { tr.hidden = q !== '' && tr.textContent.toLowerCase().indexOf(q) < 0; });
        });
    });
    // ---------------------------------------------------- retiradas
    function marcadas() { return Array.prototype.map.call(raiz.querySelectorAll('[data-retirar]:checked'), function (c) { return parseInt(c.getAttribute('data-retirar'), 10); }); }
    function atualizarRetiradas() {
        var n = marcadas().length, b = raiz.querySelector('[data-total-retirar]');
        if (b) b.textContent = n + ' / ' + T.totalRetirar;
    }
    raiz.addEventListener('change', function (ev) { if (ev.target.matches('[data-retirar]')) atualizarRetiradas(); });
    raiz.querySelectorAll('[data-marcar]').forEach(function (b) {
        b.addEventListener('click', function () {
            var v = b.getAttribute('data-marcar') === '1';
            raiz.querySelectorAll('[data-painel="retirar"] tbody tr:not([hidden]) [data-retirar]:not(:disabled)').forEach(function (c) { c.checked = v; });
            atualizarRetiradas();
        });
    });
    // ---------------------------------------------------- autorizar / descartar
    var modalEl = document.getElementById('liConfirmar'), acaoPendente = null;
    raiz.querySelectorAll('[data-acao]').forEach(function (b) {
        b.addEventListener('click', function () {
            acaoPendente = b.getAttribute('data-acao');
            modalEl.querySelector('[data-confirmar-titulo]').textContent = acaoPendente === 'autorizar' ? T.confirmarTitulo : T.descartarTitulo;
            modalEl.querySelector('[data-confirmar-texto]').textContent = acaoPendente === 'autorizar' ? T.confirmarTexto.replace('{r}', String(marcadas().length)) : T.descartarTexto;
            var ok = modalEl.querySelector('[data-confirmar-ok]'); ok.className = 'btn btn-sm ' + (acaoPendente === 'autorizar' ? 'btn-success' : 'btn-danger');
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        });
    });
    if (modalEl) modalEl.querySelector('[data-confirmar-ok]').addEventListener('click', function () {
        var ok = this; ok.disabled = true;
        var d = new FormData(); d.append('acao', acaoPendente); d.append('codimportacao', cod);
        if (acaoPendente === 'autorizar') d.append('retiradas', JSON.stringify(marcadas()));
        enviar(d).then(function (r) {
            bootstrap.Modal.getOrCreateInstance(modalEl).hide(); ok.disabled = false;
            if (r && r.ok) { msg(r.message, 'success'); setTimeout(function () { window.location.reload(); }, 1200); }
            else msg((r && r.message) || T.erro, 'danger');
        }).catch(function () { ok.disabled = false; bootstrap.Modal.getOrCreateInstance(modalEl).hide(); msg(T.erro, 'danger'); });
    });
    <?php if ($emAndamento): ?>setTimeout(function () { window.location.reload(); }, 15000);<?php endif; ?>
}());
</script>
</body>
</html>
