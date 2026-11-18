<?php
declare(strict_types=1);

/*
 * Não Conformidades › Administração › Configuração › Configuração do módulo (migração 339, 23/10/2026; fase 4 da administração de
 * NC, só ADMINISTRADOR). Abas:
 *   Campos por tipo   — os campos que cada tipo mostra vêm do sistema anterior (nc_tipo_de_naoconformidadexcampos, só consulta);
 *                       aqui se escolhe quais o greenportal exige no envio da ocorrência, além das regras fixas.
 *   Parâmetros        — regras que eram fixas no código ou só da Crown (eficácia, listas de fornecedor, IA, alcance dos lembretes).
 *   Lembretes         — regras automáticas (serviço "nc" do Agendador): situação, dias, repetição, aviso ao superior, e-mail, tipos.
 *   Lembretes enviados e Histórico (nc_admin_historico, objeto CONFIGURACAO).
 * Leitura só por views (vw_nc_config_*, vw_nc_lembrete_*, vw_nc_tipo_campos, vw_nc_opcoes); gravação por api/nc_administracao.php.
 */

require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeAdministracao.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeConfig.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeWorkflow.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeTextos.php';
require_once dirname(__DIR__) . '/components/grid_local.php';

$pdo = TransactionSecurity::connect(NaoConformidadeConfig::MENU);
$perm = PermissaoTransacao::obter(NaoConformidadeConfig::MENU);
$pode = !empty($perm['pode_editar']);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$A = NaoConformidadeAdministracao::class;
$K = NaoConformidadeConfig::class;
$erro = '';
$tipos = []; $usa = []; $obrig = []; $params = []; $regras = []; $envios = []; $hist = [];
try {
    // Tipos de NC (vw_nc_opcoes lida inteira: o filtro por LISTA compara um texto fixo da view e dá conflito de collation no MySQL)
    foreach (NaoConformidadeWorkflow::opcoes($pdo, true)['TIPONC'] ?? [] as $cod => $nome) $tipos[(int)$cod] = (string)$nome;
    foreach ($A::ler($pdo, 'vw_nc_tipo_campos') as $c) $usa[(int)$c['CODTIPONAOCONFORMIDADE']][strtoupper((string)$c['CAMPO'])] = true;
    foreach ($A::ler($pdo, 'vw_nc_config_obrigatorios') as $o) $obrig[(int)$o['CODTIPONAOCONFORMIDADE']][strtoupper((string)$o['CAMPO'])] = true;
    foreach ($A::ler($pdo, 'vw_nc_config_parametros') as $p) $params[(string)$p['CHAVE']] = $p;
    $regras = $A::ler($pdo, 'vw_nc_lembrete_regras');
    $envios = $A::ler($pdo, 'vw_nc_lembrete_envios', [], 5000);
    usort($envios, static fn($a, $b) => strcmp((string)$b['ULTIMO_EM'], (string)$a['ULTIMO_EM']));
    $hist = $A::ler($pdo, 'vw_nc_admin_historico', ['OBJETO' => $A::exato('CONFIGURACAO')], 5000);
    usort($hist, static fn($a, $b) => (int)$b['CODHISTORICO'] <=> (int)$a['CODHISTORICO']);
} catch (Throwable $ex) {
    $erro = ncT('cfg.sem_migracao');
    error_log('[nc configuracao] ' . $ex->getMessage());
}
// Tipos com fluxo no greenportal, na ordem do menu
$ordemTipos = array_values(array_filter([3, 1, 2, 4, 5, 6], static fn($t) => isset($tipos[$t])));
$fixo = static function (int $tipo, string $campo) use ($K, $usa): bool {
    if (in_array($campo, $K::FIXOS[0], true) || in_array($campo, $K::FIXOS[$tipo] ?? [], true)) return true;
    return in_array($campo, $K::FIXOS_SE_USA, true) && !empty($usa[$tipo][$campo]);
};
$tiposTexto = static fn(?string $lista): string => trim((string)$lista) === '' ? ncT('comum.todos') : implode(', ', array_map(static fn($c) => $tipos[(int)$c] ?? '#' . $c, explode(',', (string)$lista)));
// Regras (grid) — listas fixas da classe no idioma do usuário
$EVENTOS = $K::eventos(); $PARAMETROS = $K::parametros(); $CAMPOS = $K::campos(); $SIM = ncT('comum.sim'); $NAO = ncT('comum.nao');
$rowsRegras = [];
foreach ($regras as $r) {
    $ev = $EVENTOS[(string)$r['EVENTO']] ?? [(string)$r['EVENTO'], '', ncT('cfg.dias_min'), 0, 0, 0];
    $json = json_encode(['codigo' => (int)$r['CODREGRA'], 'evento' => (string)$r['EVENTO'], 'nome' => (string)$r['NOME'], 'ativo' => (int)$r['ATIVO'], 'dias' => (int)$r['DIAS'],
        'repetir' => (int)$r['REPETIR_DIAS'], 'escalar' => (int)$r['ESCALAR_APOS'], 'email' => (int)$r['ENVIAR_EMAIL'], 'tipos' => array_values(array_filter(explode(',', (string)$r['TIPOS'])))], JSON_UNESCAPED_UNICODE);
    $rowsRegras[] = ['cells' => [
        'NOME' => ['text' => (string)$r['NOME'], 'html' => $e($r['NOME']) . ($pode ? greenPortalGridLocalLinha('<button type="button" data-linha-acao="editar" data-regra=\'' . $e($json) . '\'></button>') : '')],
        'EVENTO' => ['text' => $ev[0]], 'DESTINO' => ['text' => $ev[1]],
        'ATIVO' => ['text' => (int)$r['ATIVO'] ? $SIM : $NAO, 'html' => (int)$r['ATIVO'] ? '<span class="badge text-bg-success">' . $e($SIM) . '</span>' : '<span class="badge text-bg-secondary">' . $e($NAO) . '</span>'],
        'QUANDO' => ['text' => ncT($r['EVENTO'] === 'ACAO_VENCENDO' ? 'cfg.ate' : 'cfg.a_partir', ['n' => (int)$r['DIAS'], 'medida' => $ev[2]])],
        'REPETIR' => ['text' => (int)$r['REPETIR_DIAS'] ? ncT('cfg.a_cada', ['n' => (int)$r['REPETIR_DIAS']]) : ncT('cfg.uma_vez')],
        'SUPERIOR' => ['text' => (int)$r['ESCALAR_APOS'] ? ncT('cfg.depois_de', ['n' => (int)$r['ESCALAR_APOS']]) : ncT('cfg.nao_min')],
        'EMAIL' => ['text' => (int)$r['ENVIAR_EMAIL'] ? $SIM : $NAO],
        'TIPOS' => ['text' => $tiposTexto($r['TIPOS'])],
        'ULTIMA' => ['text' => $A::data($r['ULTIMA_EXECUCAO'], true), 'sort' => $A::ordenacao($r['ULTIMA_EXECUCAO'])],
        'ENVIADOS' => ['text' => (string)(int)$r['QTD_ENVIADOS'], 'sort' => str_pad((string)(int)$r['QTD_ENVIADOS'], 9, '0', STR_PAD_LEFT)],
    ]];
}
$rowsEnvios = [];
foreach ($envios as $v) $rowsEnvios[] = ['cells' => [
    'ULTIMO_EM' => ['text' => $A::data($v['ULTIMO_EM'], true), 'sort' => $A::ordenacao($v['ULTIMO_EM'])],
    'REGRA' => ['text' => (string)$v['REGRA']], 'NUMERO' => ['text' => (string)$v['NUMERO'], 'sort' => str_pad((string)(int)$v['CODOCORRENCIA'], 9, '0', STR_PAD_LEFT)],
    'ACAO' => ['text' => (int)$v['CODPLANOACAO'] ? (string)(int)$v['CODPLANOACAO'] : '—'], 'USUARIO' => ['text' => (string)$v['USUARIO']],
    'QTD' => ['text' => (string)(int)$v['QTD'], 'sort' => str_pad((string)(int)$v['QTD'], 5, '0', STR_PAD_LEFT)],
    'PRIMEIRO_EM' => ['text' => $A::data($v['PRIMEIRO_EM'], true), 'sort' => $A::ordenacao($v['PRIMEIRO_EM'])],
]];
$rotuloAcao = ['PARAMETRO' => ncT('cfg.h_parametro'), 'OBRIGATORIOS' => ncT('cfg.h_obrig'), 'LEMBRETE_INCLUIDO' => ncT('cfg.h_regra_inc'), 'LEMBRETE_ALTERADO' => ncT('cfg.h_regra_alt')];
$resumo = static function (?string $j): string {
    $d = json_decode((string)$j, true);
    if (!is_array($d)) return '';
    return implode('; ', array_map(static fn($k, $v) => $k . ': ' . (is_array($v) ? implode(', ', $v) : (is_bool($v) ? ($v ? ncT('cfg.sim_min') : ncT('cfg.nao_min')) : (string)$v)), array_keys($d), array_values($d)));
};
$rowsHist = [];
foreach ($hist as $h) $rowsHist[] = ['cells' => [
    'CRIADO_EM' => ['text' => $A::data($h['CRIADO_EM'], true), 'sort' => $A::ordenacao($h['CRIADO_EM'])],
    'NOME_USUARIO' => ['text' => (string)$h['NOME_USUARIO']], 'ACAO' => ['text' => $rotuloAcao[(string)$h['ACAO']] ?? (string)$h['ACAO']],
    'CODIGO' => ['text' => (string)($PARAMETROS[(string)$h['CODIGO']][0] ?? (preg_match('/^TIPO (\d+)$/', (string)$h['CODIGO'], $m) ? ncT('cfg.tipo_item', ['tipo' => $tipos[(int)$m[1]] ?? $m[1]]) : (string)$h['CODIGO']))],
    'ANTES' => ['text' => $resumo($h['ANTES']), 'title' => $resumo($h['ANTES'])], 'DEPOIS' => ['text' => $resumo($h['DEPOIS']), 'title' => $resumo($h['DEPOIS'])],
]];
$banco = (string)($_SESSION['tenant']['banco_dados'] ?? '');
?>
<!doctype html><html lang="<?= $e(IdiomaService::idioma() === 'pt' ? 'pt-BR' : IdiomaService::idioma()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e(ncT('cfg.titulo')) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?>
<?php greenPortalGridLocalHead(); ?>
<style>.nc-cfg{padding:12px}.nc-cfg .nc-tipos .nav-link{text-align:left;padding:.35rem .75rem;font-size:.85rem}.nc-cfg .table td,.nc-cfg .table th{font-size:.8rem;vertical-align:middle}
.nc-cfg .nc-param{border-left:4px solid var(--bs-success-border-subtle,#a3cfbb)}.nc-cfg .nc-param .small{max-width:780px}</style>
</head>
<body class="nc-cfg" data-transacao="naoconformidades_configuracao" data-csrf="<?= $e($A::csrf()) ?>" data-api="api/nc_administracao.php">
  <h2 class="h5 mb-1"><i class="bi bi-sliders2 me-1"></i><?= $e(ncT('cfg.titulo')) ?></h2>
  <p class="text-muted small mb-2"><?= $e(ncT('cfg.explica')) ?><?= $pode ? '' : ' ' . $e(ncT('cfg.so_consulta')) ?></p>
  <div data-nc-aviso hidden></div>
  <?php if ($erro !== ''): ?><div class="alert alert-warning py-2"><?= $e($erro) ?></div><?php else: ?>
  <ul class="nav nav-tabs mb-2" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#abaCampos" type="button" role="tab"><i class="bi bi-ui-checks me-1"></i><?= $e(ncT('cfg.aba_campos')) ?></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#abaParametros" type="button" role="tab"><i class="bi bi-toggles me-1"></i><?= $e(ncT('cfg.aba_param')) ?></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#abaLembretes" type="button" role="tab"><i class="bi bi-bell me-1"></i><?= $e(ncT('cfg.aba_lembretes')) ?> <span class="badge text-bg-light border"><?= count(array_filter($regras, static fn($r) => (int)$r['ATIVO'] === 1)) ?>/<?= count($regras) ?></span></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#abaEnvios" type="button" role="tab"><i class="bi bi-send-check me-1"></i><?= $e(ncT('cfg.aba_enviados')) ?></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#abaHistorico" type="button" role="tab"><i class="bi bi-clock-history me-1"></i><?= $e(ncT('aba.historico')) ?></button></li>
  </ul>
  <div class="tab-content">
    <!-- Campos por tipo -->
    <div class="tab-pane fade show active" id="abaCampos" role="tabpanel">
      <p class="small text-muted mb-2"><?= ncT('cfg.campos_explica') ?></p>
      <div class="row g-3">
        <div class="col-md-3"><div class="nav flex-column nav-pills nc-tipos" role="tablist">
          <?php foreach ($ordemTipos as $i => $t): ?><button class="nav-link<?= $i ? '' : ' active' ?>" data-bs-toggle="pill" data-bs-target="#tipo<?= $t ?>" type="button" role="tab"><?= $e($tipos[$t]) ?> <span class="badge text-bg-light border"><?= count($obrig[$t] ?? []) ?></span></button><?php endforeach; ?>
        </div></div>
        <div class="col-md-9"><div class="tab-content">
          <?php foreach ($ordemTipos as $i => $t): ?>
          <div class="tab-pane fade<?= $i ? '' : ' show active' ?>" id="tipo<?= $t ?>" role="tabpanel" data-tipo="<?= $t ?>">
            <table class="table table-sm table-hover mb-2"><thead><tr><th><?= $e(ncT('cfg.campo')) ?></th><th style="width:220px"><?= $e(ncT('cfg.aparece')) ?></th><th style="width:200px"><?= $e(ncT('cfg.exigido')) ?></th></tr></thead><tbody>
            <?php foreach ($CAMPOS as $campo => [$rotulo, $sempre]): if (!$sempre && empty($usa[$t][$campo])) continue; $fx = $fixo($t, $campo); ?>
              <tr><td><?= $e($rotulo) ?> <span class="text-muted small">(<?= $e(strtolower($campo)) ?>)</span></td>
                <td class="small text-muted"><?= $e($sempre ? ncT('cfg.sempre') : ncT('cfg.sim_anterior')) ?></td>
                <td><?php if ($fx): ?><span class="badge text-bg-secondary"><?= $e(ncT('cfg.regra_fixa')) ?></span><?php else: ?>
                  <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" data-obrig="<?= $e($campo) ?>" data-rotulo="<?= $e($rotulo) ?>"<?= !empty($obrig[$t][$campo]) ? ' checked' : '' ?><?= $pode ? '' : ' disabled' ?>></div><?php endif; ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php if ($pode): ?><button type="button" class="btn btn-sm btn-success" data-salvar-obrig="<?= $t ?>"><i class="bi bi-floppy me-1"></i><?= $e(ncT('cfg.salvar_obrig', ['tipo' => $tipos[$t]])) ?></button><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div></div>
      </div>
    </div>
    <!-- Parâmetros -->
    <div class="tab-pane fade" id="abaParametros" role="tabpanel">
      <form data-form-parametros class="d-grid gap-2" style="max-width:980px">
      <?php foreach ($PARAMETROS as $chave => [$rotulo, $ajuda, $tipoP]):
          $gravado = isset($params[$chave]); $valor = $gravado ? (string)$params[$chave]['VALOR'] : $K::padrao($chave, $banco); ?>
        <div class="card nc-param"><div class="card-body py-2 d-flex gap-3 align-items-center">
          <div class="flex-grow-1"><div class="fw-semibold small"><?= $e($rotulo) ?></div><div class="small text-muted"><?= $e($ajuda) ?><?= $gravado && $params[$chave]['ALTERADO_POR_NOME'] !== '' ? ' · ' . $e(ncT('cfg.alterado_por', ['nome' => $params[$chave]['ALTERADO_POR_NOME'], 'data' => $A::data($params[$chave]['ALTERADO_EM'], true)])) : ($gravado ? '' : ' · ' . $e(ncT('cfg.valor_padrao'))) ?></div></div>
          <?php if ($tipoP === 'sn'): ?><div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" data-param="<?= $e($chave) ?>" data-original="<?= $e($valor) ?>"<?= $valor === 'S' ? ' checked' : '' ?><?= $pode ? '' : ' disabled' ?> aria-label="<?= $e($rotulo) ?>"></div>
          <?php else: ?><input type="date" class="form-control form-control-sm" style="width:170px" data-param="<?= $e($chave) ?>" data-original="<?= $e($valor) ?>" value="<?= $e($valor) ?>"<?= $pode ? '' : ' disabled' ?> aria-label="<?= $e($rotulo) ?>"><?php endif; ?>
        </div></div>
      <?php endforeach; ?>
      <?php if ($pode): ?><div><button type="button" class="btn btn-sm btn-success" data-salvar-parametros><i class="bi bi-floppy me-1"></i><?= $e(ncT('cfg.salvar_param')) ?></button></div><?php endif; ?>
      </form>
    </div>
    <!-- Lembretes -->
    <div class="tab-pane fade" id="abaLembretes" role="tabpanel">
      <p class="small text-muted mb-2"><?= ncT('cfg.lembretes_explica') ?></p>
      <?php greenPortalGridLocal([
          'transaction' => 'naoconformidades_configuracao_lembretes', 'fragment_class' => 'nc-cfg-regras', 'id' => 'ncCfgRegras', 'page_size' => 25,
          'columns' => ['NOME' => ['label' => ncT('col.regra')], 'EVENTO' => ['label' => ncT('oc.situacao'), 'filter' => 'select'], 'DESTINO' => ['label' => ncT('col.quem_recebe'), 'filter' => 'select'],
              'ATIVO' => ['label' => ncT('col.ligada'), 'filter' => 'select', 'width' => '80px'], 'QUANDO' => ['label' => ncT('col.quando')], 'REPETIR' => ['label' => ncT('col.repetir')],
              'SUPERIOR' => ['label' => ncT('col.superior')], 'EMAIL' => ['label' => ncT('col.email'), 'filter' => 'select', 'width' => '80px'], 'TIPOS' => ['label' => ncT('col.tipos_nc')],
              'ULTIMA' => ['label' => ncT('col.ultima_exec'), 'filter' => 'none'], 'ENVIADOS' => ['label' => ncT('col.enviados'), 'filter' => 'none', 'width' => '90px']],
          'cell_boxes' => true, 'rows' => $rowsRegras, 'empty' => ncT('cfg.nenhuma_regra'), 'double_click_action' => $pode ? 'editar' : '',
          'buttons' => ['reload' => false, 'columns' => true, 'new' => $pode, 'new_attribute' => 'data-regra-nova'],
          'extras' => ($pode ? '<button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-grid-acao-linha="editar" disabled title="' . $e(ncT('cfg.editar_regra')) . '" aria-label="' . $e(ncT('cfg.editar_regra')) . '"><i class="bi bi-pencil"></i></button>' : '')
              . '<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-simular title="' . $e(ncT('cfg.simular_dica')) . '" aria-label="' . $e(ncT('cfg.simular')) . '"><i class="bi bi-calculator"></i></button>',
      ]); ?>
      <div data-simulacao class="mt-2"></div>
    </div>
    <div class="tab-pane fade" id="abaEnvios" role="tabpanel">
      <?php greenPortalGridLocal([
          'transaction' => 'naoconformidades_configuracao_envios', 'fragment_class' => 'nc-cfg-envios', 'id' => 'ncCfgEnvios',
          'columns' => ['ULTIMO_EM' => ['label' => ncT('col.ultimo_envio'), 'filter' => 'none', 'width' => '140px'], 'REGRA' => ['label' => ncT('col.regra'), 'filter' => 'select'], 'NUMERO' => ['label' => ncT('col.nc'), 'width' => '120px'],
              'ACAO' => ['label' => ncT('col.acao_n'), 'width' => '90px'], 'USUARIO' => ['label' => ncT('col.pessoa'), 'filter' => 'select'], 'QTD' => ['label' => ncT('col.lembretes'), 'filter' => 'none', 'width' => '90px'],
              'PRIMEIRO_EM' => ['label' => ncT('col.primeiro_envio'), 'filter' => 'none', 'width' => '140px']],
          'cell_boxes' => true, 'rows' => $rowsEnvios, 'default_sort' => ['ULTIMO_EM', 'desc'], 'buttons' => ['reload' => false, 'columns' => true], 'empty' => ncT('cfg.nenhum_enviado'),
      ]); ?>
    </div>
    <div class="tab-pane fade" id="abaHistorico" role="tabpanel">
      <?php greenPortalGridLocal([
          'transaction' => 'naoconformidades_configuracao_historico', 'fragment_class' => 'nc-cfg-hist', 'id' => 'ncCfgHist',
          'columns' => ['CRIADO_EM' => ['label' => ncT('col.data'), 'filter' => 'none', 'width' => '140px'], 'NOME_USUARIO' => ['label' => ncT('col.usuario'), 'filter' => 'select', 'width' => '170px'],
              'ACAO' => ['label' => ncT('col.alteracao'), 'filter' => 'select', 'width' => '210px'], 'CODIGO' => ['label' => ncT('col.item')], 'ANTES' => ['label' => ncT('col.antes')], 'DEPOIS' => ['label' => ncT('col.depois')]],
          'cell_boxes' => true, 'rows' => $rowsHist, 'default_sort' => ['CRIADO_EM', 'desc'], 'buttons' => ['reload' => false, 'columns' => true], 'empty' => ncT('cfg.nenhuma_alteracao'),
      ]); ?>
    </div>
  </div>
  <?php if ($pode): ?>
  <div class="modal fade" id="ncRegra" tabindex="-1"><div class="modal-dialog modal-lg"><form class="modal-content" data-form-regra>
    <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-bell me-1"></i><?= $e(ncT('cfg.regra_titulo')) ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $e(ncT('comum.fechar')) ?>"></button></div>
    <div class="modal-body row g-2"><input type="hidden" name="codigo">
      <div class="col-md-6"><label class="form-label small fw-semibold"><?= $e(ncT('oc.situacao')) ?></label><select class="form-select form-select-sm" name="evento" required>
        <?php foreach ($EVENTOS as $ev => $d): ?><option value="<?= $e($ev) ?>" data-destino="<?= $e($d[1]) ?>" data-medida="<?= $e($d[2]) ?>"><?= $e($d[0]) ?></option><?php endforeach; ?></select>
        <div class="small text-muted mt-1"><?= $e(ncT('cfg.quem_recebe_lbl')) ?> <b data-destino-texto></b></div></div>
      <div class="col-md-6"><label class="form-label small fw-semibold"><?= $e(ncT('cfg.nome_regra')) ?></label><input class="form-control form-control-sm" name="nome" maxlength="200" required></div>
      <div class="col-md-4"><label class="form-label small fw-semibold" data-rotulo-dias><?= $e(ncT('cfg.dias')) ?></label><input type="number" class="form-control form-control-sm" name="dias" min="0" max="3650" required></div>
      <div class="col-md-4"><label class="form-label small fw-semibold"><?= $e(ncT('cfg.repetir_dias')) ?></label><input type="number" class="form-control form-control-sm" name="repetir" min="0" max="365"><div class="small text-muted"><?= $e(ncT('cfg.uma_vez_so')) ?></div></div>
      <div class="col-md-4"><label class="form-label small fw-semibold"><?= $e(ncT('cfg.avisar_superior')) ?></label><input type="number" class="form-control form-control-sm" name="escalar" min="0" max="20"><div class="small text-muted"><?= $e(ncT('cfg.lembretes_sem')) ?></div></div>
      <div class="col-12"><label class="form-label small fw-semibold mb-1"><?= $e(ncT('col.tipos_nc')) ?></label><div class="d-flex flex-wrap gap-3">
        <?php foreach ($ordemTipos as $t): ?><label class="form-check-label small"><input type="checkbox" class="form-check-input me-1" name="tipos" value="<?= $t ?>"><?= $e($tipos[$t]) ?></label><?php endforeach; ?></div>
        <div class="small text-muted"><?= $e(ncT('cfg.nenhum_todos')) ?></div></div>
      <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="email" id="ncRegraEmail"><label class="form-check-label small" for="ncRegraEmail"><?= $e(ncT('cfg.enviar_email')) ?></label></div></div>
      <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="ativo" id="ncRegraAtivo"><label class="form-check-label small" for="ncRegraAtivo"><?= $e(ncT('cfg.regra_ligada')) ?></label></div></div>
    </div>
    <div class="modal-footer py-2"><button class="btn btn-sm btn-success" type="submit"><i class="bi bi-floppy me-1"></i><?= $e(ncT('comum.salvar')) ?></button></div>
  </form></div></div>
  <?php endif; ?>
  <?php endif; ?>
<?php greenPortalGridLocalScripts(); ?>
<?= NaoConformidadeTextos::script() ?>
<script src="../assets/js/nc-administracao.js?v=2"></script>
<script>
(function () {
  'use strict';
  function N(k, p) { return typeof window.greenT === 'function' ? window.greenT('naoconformidades.js.' + k, p) : p; }
  var eventos = <?= json_encode(array_map(static fn($d) => ['nome' => $d[0], 'destino' => $d[1], 'medida' => $d[2], 'dias' => $d[3], 'repetir' => $d[4], 'escalar' => $d[5]], $EVENTOS), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var recarregar = function (aba) { window.setTimeout(function () { window.location.href = window.location.pathname + (aba ? '#' + aba : ''); window.location.reload(); }, 1200); };
  // Aba aberta pelo endereço (#abaLembretes) depois de gravar
  if (window.location.hash) { var bt = document.querySelector('[data-bs-target="' + window.location.hash + '"]'); if (bt && window.bootstrap) window.bootstrap.Tab.getOrCreateInstance(bt).show(); }

  // Obrigatórios do tipo
  document.querySelectorAll('[data-salvar-obrig]').forEach(function (b) {
    b.addEventListener('click', function () {
      var tipo = Number(b.getAttribute('data-salvar-obrig')), painel = document.querySelector('[data-tipo="' + tipo + '"]');
      var campos = Array.prototype.filter.call(painel.querySelectorAll('[data-obrig]'), function (x) { return x.checked; }).map(function (x) { return { campo: x.getAttribute('data-obrig'), rotulo: x.getAttribute('data-rotulo') }; });
      b.disabled = true;
      GPNcAdmin.gravar('config_obrigatorios', { tipo: tipo, campos: campos }).then(function (r) {
        GPNcAdmin.aviso(N('cfg_obrig_gravados', 'Obrigatórios gravados: {n} campo(s) exigido(s) além das regras fixas.').replace('{n}', r.QTD || 0), 'success'); b.disabled = false;
      }).catch(function (e) { b.disabled = false; GPNcAdmin.aviso(e.message, 'danger'); });
    });
  });
  // Parâmetros (grava só os que mudaram)
  var bp = document.querySelector('[data-salvar-parametros]');
  if (bp) bp.addEventListener('click', function () {
    var mudou = Array.prototype.map.call(document.querySelectorAll('[data-param]'), function (x) {
      return { chave: x.getAttribute('data-param'), valor: x.type === 'checkbox' ? (x.checked ? 'S' : 'N') : x.value, original: x.getAttribute('data-original') };
    }).filter(function (p) { return p.valor !== p.original; });
    if (!mudou.length) { GPNcAdmin.aviso(N('cfg_nenhum_param', 'Nenhum parâmetro foi alterado.'), 'info'); return; }
    bp.disabled = true;
    mudou.reduce(function (pr, p) { return pr.then(function () { return GPNcAdmin.gravar('config_parametro', { chave: p.chave, valor: p.valor }); }); }, Promise.resolve())
      .then(function () { GPNcAdmin.aviso(N('cfg_params_gravados', '{n} parâmetro(s) gravado(s).').replace('{n}', mudou.length), 'success'); recarregar('abaParametros'); })
      .catch(function (e) { bp.disabled = false; GPNcAdmin.aviso(e.message, 'danger'); });
  });
  // Regras de lembrete
  var modalEl = document.getElementById('ncRegra'), form = document.querySelector('[data-form-regra]');
  function ajustarEvento() {
    if (!form) return;
    var op = form.elements.evento.selectedOptions[0];
    form.querySelector('[data-destino-texto]').textContent = op ? op.getAttribute('data-destino') : '';
    form.querySelector('[data-rotulo-dias]').textContent = N('cfg_dias_medida', 'Dias ({medida})').replace('{medida}', op ? op.getAttribute('data-medida') : '');
  }
  function abrirRegra(r) {
    if (!form) return;
    form.reset();
    var ev = eventos[r.evento] || {};
    form.elements.codigo.value = r.codigo || 0; form.elements.evento.value = r.evento; form.elements.nome.value = r.nome || ev.nome || '';
    form.elements.dias.value = r.dias !== undefined ? r.dias : ev.dias; form.elements.repetir.value = r.repetir !== undefined ? r.repetir : ev.repetir;
    form.elements.escalar.value = r.escalar !== undefined ? r.escalar : ev.escalar; form.elements.email.checked = r.email === undefined ? true : !!r.email; form.elements.ativo.checked = !!r.ativo;
    Array.prototype.forEach.call(form.querySelectorAll('[name="tipos"]'), function (c) { c.checked = (r.tipos || []).indexOf(c.value) >= 0; });
    ajustarEvento();
    window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
  }
  if (form) {
    form.elements.evento.addEventListener('change', function () {
      if (Number(form.elements.codigo.value)) { ajustarEvento(); return; }
      var ev = eventos[form.elements.evento.value] || {}; form.elements.nome.value = ev.nome || ''; form.elements.dias.value = ev.dias; form.elements.repetir.value = ev.repetir; form.elements.escalar.value = ev.escalar; ajustarEvento();
    });
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var f = form.elements, tipos = Array.prototype.filter.call(form.querySelectorAll('[name="tipos"]'), function (c) { return c.checked; }).map(function (c) { return c.value; });
      GPNcAdmin.gravar('lembrete_regra', { codigo: Number(f.codigo.value) || 0, evento: f.evento.value, nome: f.nome.value.trim(), ativo: f.ativo.checked ? 1 : 0, dias: Number(f.dias.value) || 0,
        repetir: Number(f.repetir.value) || 0, escalar: Number(f.escalar.value) || 0, email: f.email.checked ? 1 : 0, tipos: tipos.join(',') })
        .then(function () { window.bootstrap.Modal.getInstance(modalEl).hide(); GPNcAdmin.aviso(N('cfg_regra_gravada', 'Regra gravada.'), 'success'); recarregar('abaLembretes'); })
        .catch(function (e) { GPNcAdmin.aviso(e.message, 'danger'); });
    });
  }
  var nova = document.querySelector('[data-regra-nova]');
  if (nova) nova.addEventListener('click', function () { abrirRegra({ codigo: 0, evento: 'ACAO_VENCIDA', ativo: 0 }); });
  document.querySelectorAll('[data-regra]').forEach(function (b) { b.addEventListener('click', function () { abrirRegra(JSON.parse(b.getAttribute('data-regra'))); }); });
  // Simular: quantos lembretes as regras ligadas enviariam agora
  var bs = document.querySelector('[data-simular]');
  if (bs) bs.addEventListener('click', function () {
    var alvo = document.querySelector('[data-simulacao]'); bs.disabled = true; alvo.innerHTML = '<div class="small text-muted"><span class="spinner-border spinner-border-sm me-1"></span>' + N('cfg_calculando', 'Calculando...') + '</div>';
    GPNcAdmin.gravar('lembretes_simular', {}).then(function (r) {
      bs.disabled = false;
      var linhas = Array.isArray(r) ? r : [];
      if (!linhas.length) { alvo.innerHTML = '<div class="alert alert-info py-2 small mb-0">' + N('cfg_nenhuma_ligada', 'Nenhuma regra ligada.') + '</div>'; return; }
      var t = '<table class="table table-sm mb-0"><thead><tr><th>' + N('cfg_regra_ligada', 'Regra ligada') + '</th><th style="width:200px">' + N('cfg_lembretes_agora', 'Lembretes agora') + '</th><th style="width:220px">' + N('cfg_avisos_superior', 'Avisos ao superior') + '</th></tr></thead><tbody>';
      linhas.forEach(function (l) { var d = document.createElement('div'); d.textContent = l.NOME; t += '<tr><td>' + d.innerHTML + '</td><td>' + Number(l.LEMBRETES) + '</td><td>' + Number(l.SUPERIORES) + '</td></tr>'; });
      alvo.innerHTML = '<div class="card"><div class="card-body py-2"><div class="small text-muted mb-1">' + N('cfg_simulacao', 'Simulação (nada foi enviado): o que a próxima execução do serviço enviaria, até 500 por regra.') + '</div>' + t + '</tbody></table></div></div>';
    }).catch(function (e) { bs.disabled = false; alvo.innerHTML = ''; GPNcAdmin.aviso(e.message, 'danger'); });
  });
}());
</script>
</body></html>
