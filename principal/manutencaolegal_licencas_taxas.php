<?php
declare(strict_types=1);

/*
 * Manutenção legal › Taxas das licenças (migração 344; Onda 1 da apresentação Atlas Schindler 2026).
 * Duas formas de abrir:
 *   - botão Taxas do cadastro de Licenças (?filtros={"CODLICENCA":"N"}): as taxas da licença, que ficam na licença original
 *     (CODLICENCA_BASE) e continuam aparecendo depois da renovação; a licença vem do contexto;
 *   - menu Taxas das licenças: todas as taxas (a licença é escolhida na inclusão); ?codtaxa=N abre só a taxa (link dos alertas).
 * Ao gravar, as taxas recorrentes pagas criam a próxima (sp_li_taxas_gerar_proximas). Comprovante: botão do clipe (GED, tipo
 * licencataxas). Leitura pela view vw_li_taxas_grid; gravação pelo CRUD padrão (sp_crud_*), com os gatilhos de validação.
 */
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/LicencaTaxa.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$filtros = json_decode((string)($_GET['filtros'] ?? '{}'), true);
$filtros = is_array($filtros) ? $filtros : [];
// Link dos alertas (sem JSON no endereço): ?codtaxa=N
if (($codtaxa = max(0, (int)($_GET['codtaxa'] ?? 0))) > 0) $filtros = ['CODTAXA' => ['value' => (string)$codtaxa, 'exact' => true]];
// Botão Taxas da licença: a licença da linha (pode ser uma versão) vira a licença original
$codLicenca = 0;
foreach ($filtros as $k => $v) if (strcasecmp((string)$k, 'CODLICENCA') === 0 && is_scalar($v)) { $codLicenca = (int)$v; unset($filtros[$k]); }
$tituloLicenca = '';
if ($codLicenca > 0 && !empty($_SESSION['autenticado'])) {
    try {
        $pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
        $s = $pdo->prepare('CALL sp_crud_select(?, ?, NULL, 1, 0)');
        $s->execute(['li_licencas', json_encode(['CODLICENCA' => (string)$codLicenca])]);
        $l = $s->fetch(PDO::FETCH_ASSOC) ?: []; $s->closeCursor();
        if ((int)($l['CODLICENCA'] ?? 0) === $codLicenca && (int)($l['CODLICENCA_BASE'] ?? 0) > 0) $codLicenca = (int)$l['CODLICENCA_BASE'];
        $tituloLicenca = trim((string)($l['DSCLICENCA'] ?? ''));
        $pdo = null;
    } catch (Throwable) {}
    $filtros['CODLICENCA'] = (string)$codLicenca;
}
$_GET['filtros'] = json_encode($filtros, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$porLicenca = $codLicenca > 0;

$T = static fn(string $c, array $p): string => IdiomaService::t('manutencaolegal_licencas_taxas.' . $c, $p);
$rotuloComprovante = $T('botao.comprovante', ['pt' => 'Comprovante de pagamento da taxa selecionada', 'en' => 'Payment receipt of the selected fee', 'es' => 'Comprobante de pago de la tasa seleccionada']);
$extras = '<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-taxa-comprovante disabled title="' . $esc($rotuloComprovante) . '" aria-label="' . $esc($rotuloComprovante) . '"><i class="bi bi-paperclip"></i></button>';

$campos = [
    'CODTAXA' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'readonly' => true, 'hidden' => true, 'filter' => 'none'],
    'NUMERO_TAXA' => ['label' => ['pt' => 'Nº', 'en' => 'No.', 'es' => 'Nº'], 'readonly' => true, 'display_only' => true, 'filter' => 'text', 'width' => 60],
    'CODLICENCA' => $porLicenca
        ? ['label' => ['pt' => 'Licença', 'en' => 'License', 'es' => 'Licencia'], 'readonly' => true, 'hidden' => true]
        : ['label' => ['pt' => 'Licença', 'en' => 'License', 'es' => 'Licencia'], 'required' => true, 'display_from' => 'LICENCA_DESC', 'foreign' => ['view' => 'vw_li_taxas_licencas', 'value' => 'CODLICENCA', 'label' => 'LICENCA_DESC', 'description' => 'LICENCA_DESC'], 'lazy_foreign' => true, 'filter' => 'none', 'width' => 300],
    'LICENCA_DESC' => ['label' => ['pt' => 'Unidade - licença', 'en' => 'Unit - license', 'es' => 'Unidad - licencia'], 'readonly' => true, 'display_only' => true, 'filter' => 'text', 'width' => 300, 'hidden' => $porLicenca],
    'TIPO' => ['label' => ['pt' => 'Tipo', 'en' => 'Type', 'es' => 'Tipo'], 'required' => true, 'options' => LicencaTaxa::TIPOS, 'display_from' => 'TIPO_DESC', 'filter' => 'select', 'width' => 150],
    'DESCRICAO' => ['label' => ['pt' => 'Descrição', 'en' => 'Description', 'es' => 'Descripción'], 'type' => 'textarea', 'filter' => 'text', 'width' => 240],
    'NUMERO_GUIA' => ['label' => ['pt' => 'Nº da guia / boleto', 'en' => 'Payment slip no.', 'es' => 'Nº de guía / boleta'], 'filter' => 'text', 'width' => 140],
    'VALOR' => ['label' => ['pt' => 'Valor (R$)', 'en' => 'Amount (R$)', 'es' => 'Valor (R$)'], 'filter' => 'text', 'width' => 110, 'tooltip' => ['pt' => 'Formato 1.234,56', 'en' => 'Format 1.234,56', 'es' => 'Formato 1.234,56']],
    'VENCIMENTO' => ['label' => ['pt' => 'Vencimento', 'en' => 'Due date', 'es' => 'Vencimiento'], 'type' => 'date', 'required' => true, 'filter' => 'text', 'width' => 120],
    'FREQUENCIA' => ['label' => ['pt' => 'Frequência', 'en' => 'Frequency', 'es' => 'Frecuencia'], 'options' => LicencaTaxa::FREQUENCIAS, 'display_from' => 'FREQUENCIA_DESC', 'filter' => 'select', 'width' => 130,
        'tooltip' => ['pt' => 'Recorrente: ao registrar o pagamento, a próxima taxa é criada sozinha', 'en' => 'Recurring: when the payment is recorded, the next fee is created automatically', 'es' => 'Recurrente: al registrar el pago, la próxima tasa se crea sola']],
    'PAGO_EM' => ['label' => ['pt' => 'Pago em', 'en' => 'Paid on', 'es' => 'Pagado el'], 'type' => 'date', 'filter' => 'text', 'width' => 120],
    'VALOR_PAGO' => ['label' => ['pt' => 'Valor pago (R$)', 'en' => 'Amount paid (R$)', 'es' => 'Valor pagado (R$)'], 'filter' => 'text', 'width' => 110, 'tooltip' => ['pt' => 'Vazio = o valor da taxa', 'en' => 'Empty = the fee amount', 'es' => 'Vacío = el valor de la tasa']],
    'SITUACAO_TAXA' => ['label' => ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación'], 'readonly' => true, 'options' => LicencaTaxa::SITUACOES, 'display_from' => 'SITUACAO_TAXA_DESC', 'filter' => 'select', 'width' => 150, 'force_visible' => true],
    'ORIGEM' => ['label' => ['pt' => 'Origem', 'en' => 'Origin', 'es' => 'Origen'], 'readonly' => true, 'options' => ['MANUAL' => ['pt' => 'Manual', 'en' => 'Manual', 'es' => 'Manual'], 'AUTOMATICA' => ['pt' => 'Automática (recorrência)', 'en' => 'Automatic (recurrence)', 'es' => 'Automática (recurrencia)']], 'display_from' => 'ORIGEM_DESC', 'filter' => 'select', 'width' => 120],
    'RESPONSAVEL' => ['label' => ['pt' => 'Responsável pela licença', 'en' => 'License owner', 'es' => 'Responsable de la licencia'], 'readonly' => true, 'display_only' => true, 'filter' => 'text', 'width' => 170, 'hidden' => $porLicenca],
    'OBSERVACAO' => ['label' => ['pt' => 'Observação', 'en' => 'Notes', 'es' => 'Observación'], 'type' => 'textarea', 'filter' => 'text', 'width' => 240],
    'CODUSUARIO' => ['label' => ['pt' => 'Código do responsável', 'en' => 'Owner code', 'es' => 'Código del responsable'], 'readonly' => true, 'hidden' => true, 'filter' => 'none'],
];

$limpar = [];
ManutencaoLegalCadastroGrid::render([
    'menu' => LicencaTaxa::MENU,
    'title' => $porLicenca && $tituloLicenca !== ''
        ? ['pt' => 'Taxas da licença: ' . $tituloLicenca, 'en' => 'License fees: ' . $tituloLicenca, 'es' => 'Tasas de la licencia: ' . $tituloLicenca]
        : ['pt' => 'Taxas das licenças', 'en' => 'License fees', 'es' => 'Tasas de las licencias'],
    'transaction' => 'manutencaolegal_licencas_taxas', 'fragment_class' => 'taxas-grid-fragment',
    'database' => 'gmodelo_dbp', 'prefix' => 'GPT', 'table' => 'li_taxas', 'view' => 'vw_li_taxas_grid',
    'primary' => 'CODTAXA', 'primary_auto' => true, 'context_fields' => $porLicenca ? ['CODLICENCA'] : [],
    // Sem "visualizar tudo" no perfil, só as taxas das licenças em que a pessoa é a responsável (como no cadastro de licenças)
    'user_scope_field' => 'CODUSUARIO',
    'extras' => $extras,
    'button_options' => ['save' => true, 'new' => true, 'duplicate' => true, 'delete' => true, 'reload' => true, 'export' => true, 'columns' => true],
    'legacy_order' => ['CODTAXA', 'NUMERO_TAXA', 'CODLICENCA', 'LICENCA_DESC', 'TIPO', 'DESCRICAO', 'NUMERO_GUIA', 'VALOR', 'VENCIMENTO', 'FREQUENCIA', 'PAGO_EM', 'VALOR_PAGO', 'SITUACAO_TAXA', 'ORIGEM', 'RESPONSAVEL', 'OBSERVACAO', 'CODUSUARIO'],
    'fields' => $campos,
    // Campo vazio não vai para o CRUD (ele gravaria '' em DATE/DECIMAL): na inclusão fica NULL; numa taxa já gravada, o campo
    // apagado (ex.: pagamento desfeito) é limpo depois por sp_li_taxa_limpar
    'before_save' => static function (array $rows) use (&$limpar): array {
        foreach ($rows as $k => $r) {
            if (!is_array($r)) continue;
            foreach (['VALOR', 'VALOR_PAGO'] as $c) if (array_key_exists($c, $r)) $rows[$k][$c] = LicencaTaxa::valor((string)$r[$c]);
            foreach (['VALOR', 'VALOR_PAGO', 'PAGO_EM', 'VENCIMENTO', 'NUMERO_GUIA', 'DESCRICAO', 'OBSERVACAO'] as $c) {
                if (!array_key_exists($c, $rows[$k]) || trim((string)$rows[$k][$c]) !== '') continue;
                unset($rows[$k][$c]);
                if (!str_starts_with((string)$k, 'novo_') && (int)$k > 0 && $c !== 'VENCIMENTO') $limpar[] = [(int)$k, $c];
            }
        }
        return $rows;
    },
    'after_save' => static function (PDO $pdo) use (&$limpar): void {
        $exec = static function (string $sql, array $p) use ($pdo): void { $s = $pdo->prepare($sql); $s->execute($p); do { try { $s->fetchAll(); } catch (Throwable) {} } while ($s->nextRowset()); $s->closeCursor(); };
        foreach ($limpar as [$cod, $campo]) $exec('CALL sp_li_taxa_limpar(?, ?)', [$cod, $campo]);
        $exec('CALL sp_li_taxas_gerar_proximas()', []);
    },
]);
?>
<?= IdiomaService::script(['manutencaolegal_licencas_taxas'], [
    'manutencaolegal_licencas_taxas.titulo_comprovante' => ['pt' => 'Comprovante da taxa', 'en' => 'Fee receipt', 'es' => 'Comprobante de la tasa'],
]) ?>
<script>
(function () {
    // Comprovante: arquivos da taxa selecionada (GED, tipo licencataxas), em popup do portal
    var fragment = document.querySelector('.taxas-grid-fragment');
    if (!fragment) return;
    var botao = fragment.querySelector('[data-taxa-comprovante]');
    function selecionada() { var tr = fragment.querySelector('table tbody tr.selected'); var k = tr ? String(tr.getAttribute('data-key') || '') : ''; return k && k.indexOf('novo_') !== 0 ? k : ''; }
    function atualizar() { if (botao) botao.disabled = !selecionada(); }
    fragment.addEventListener('click', function (ev) { if (ev.target.closest && ev.target.closest('table tbody tr')) window.setTimeout(atualizar, 0); });
    if (window.jQuery) window.jQuery(fragment).on('gridPageLoaded', atualizar);
    if (botao) botao.addEventListener('click', function (ev) {
        ev.preventDefault(); ev.stopPropagation();
        var id = selecionada(); if (!id) return;
        var host = window.top || window, titulo = (window.greenT ? window.greenT('manutencaolegal_licencas_taxas.titulo_comprovante', 'Comprovante da taxa') : 'Comprovante da taxa') + ' nº ' + id;
        var url = (host.greenPortalBaseUrl || '') + '/principal/gestao_arquivos.php?tipo=licencataxas&chave=CODTAXA&valor=' + encodeURIComponent(id);
        if (host.GreenPortalPopups && host.jQuery) {
            var frame = host.jQuery('<iframe>', { title: titulo, style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', url);
            host.GreenPortalPopups.transacaoIndependente({ titulo: titulo, conteudo: frame, amplo: true, redimensionavel: true });
        } else if (host.greenPortalOpenTab) host.greenPortalOpenTab('taxa-comprovante-' + id, url, titulo);
    });
    atualizar();
}());
</script>
<?php
$toolbarGroups = [
    'fragmento' => '.taxas-grid-fragment',
    'rotuloOutros' => IdiomaService::t('comum.grupo.outros', ['pt' => 'Outros', 'en' => 'Others', 'es' => 'Otros']),
    'grupos' => [
        ['id' => 'operacoes', 'rotulo' => IdiomaService::t('comum.grupo.operacoes', ['pt' => 'Operações', 'en' => 'Operations', 'es' => 'Operaciones']), 'botoes' => ['[data-crud-action="save"]', '[data-crud-action="new"]', '[data-crud-action="duplicate"]', '[data-crud-action="delete"]', '[data-crud-action="reload"]', '[data-crud-action="export"]', '[data-crud-action="columns"]', '[data-open-transacao-ajuda]']],
        ['id' => 'associacoes', 'rotulo' => IdiomaService::t('comum.grupo.associacoes', ['pt' => 'Associações', 'en' => 'Associations', 'es' => 'Asociaciones']), 'botoes' => ['[data-taxa-comprovante]']],
    ],
];
?>
<link href="../assets/css/toolbar-grupos.css?v=3" rel="stylesheet">
<script type="application/json" data-toolbar-grupos-config><?= json_encode($toolbarGroups, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="../assets/js/toolbar-grupos.js?v=8"></script>
