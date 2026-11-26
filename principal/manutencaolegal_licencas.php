<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/LicencaSituacao.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$language = in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$_SESSION['idioma'] : 'pt';
$pendingMode = !empty($_GET['minhas_pendencias']);
$isMyLicenses = !empty($_GET['minhas']) || $pendingMode;
$licenseMenu = $pendingMode ? 'LICENCAS' : ($isMyLicenses ? 'MINHAS_LICENCAS' : 'LICENCAS');
$licenseTransaction = $pendingMode ? 'manutencaolegal_licencas' : ($isMyLicenses ? 'minhas_licencas' : 'manutencaolegal_licencas');
$licenseTitle = $isMyLicenses ? IdiomaService::textos('minhas_licencas.titulo', ['pt' => 'Minhas Licenças', 'en' => 'My Licenses', 'es' => 'Mis Licencias']) : IdiomaService::textos('manutencaolegal_licencas.titulo', ['pt' => 'Licenças', 'en' => 'Licenses', 'es' => 'Licencias']);
// Botões de correlacionamento: Administração › Textos e idiomas (cadastro automático ao abrir como ADMINISTRADOR).
$actionLabels = [
    IdiomaService::t('manutencaolegal_licencas.botao.condicionantes', ['pt' => 'Condicionantes', 'en' => 'Conditions', 'es' => 'Condicionantes']),
    IdiomaService::t('manutencaolegal_licencas.botao.palavras_chave', ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave']),
    IdiomaService::t('manutencaolegal_licencas.botao.responsabilidades', ['pt' => 'Responsabilidades', 'en' => 'Responsibilities', 'es' => 'Responsabilidades']),
    IdiomaService::t('manutencaolegal_licencas.botao.arquivos', ['pt' => 'Arquivos', 'en' => 'Files', 'es' => 'Archivos']),
];
$esc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$licenseActions = '<button type="button" class="btn btn-sm btn-outline-success grid-crud-button" data-license-condicionantes title="' . $esc($actionLabels[0]) . '"><i class="bi bi-list-check"></i></button><button type="button" class="btn btn-sm btn-outline-success grid-crud-button" data-license-palavras-chave title="' . $esc($actionLabels[1]) . '"><i class="bi bi-tags"></i></button><button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-license-responsabilidades title="' . $esc($actionLabels[2]) . '" disabled><i class="bi bi-people"></i></button><button type="button" class="btn btn-sm btn-outline-info grid-crud-button" data-license-requisitos title="Requisitos e documentos" disabled><i class="bi bi-diagram-3"></i></button><button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-license-arquivos title="' . $esc($actionLabels[3]) . '" disabled><i class="bi bi-files"></i></button>';
// Migração 344: taxas da licença (valor, vencimento, recorrência, pagamento e comprovante)
// Revisão G item 63 (migração 421): campos personalizados da licença selecionada
if (is_file(__DIR__ . '/licencas_campos.php')) $licenseActions .= '<button type="button" class="btn btn-sm btn-outline-success grid-crud-button" data-license-campos title="' . $esc(IdiomaService::t('manutencaolegal_licencas.botao.campos', ['pt' => 'Campos personalizados da licença', 'en' => 'License custom fields', 'es' => 'Campos personalizados de la licencia'])) . '" disabled><i class="bi bi-input-cursor-text"></i></button>';
if (is_file(__DIR__ . '/manutencaolegal_licencas_taxas.php')) $licenseActions .= '<button type="button" class="btn btn-sm btn-outline-success grid-crud-button" data-license-taxas title="' . $esc(IdiomaService::t('manutencaolegal_licencas.botao.taxas', ['pt' => 'Taxas da licença (valor, vencimento, pagamento e comprovante)', 'en' => 'License fees (amount, due date, payment and receipt)', 'es' => 'Tasas de la licencia (valor, vencimiento, pago y comprobante)'])) . '" disabled><i class="bi bi-cash-coin"></i></button>';

// Licenças em protocolo (migração 288): situação Válida / Válida (em protocolo) / Vencida e número do protocolo.
// As colunas só entram no grid depois que a migração cria NUMERO_PROTOCOLO e as colunas da view.
$licencaSituacao = false;
try {
    $licencaPdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
    $licencaSituacao = (int)$licencaPdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND ((TABLE_NAME = 'li_licencas' AND COLUMN_NAME = 'NUMERO_PROTOCOLO') OR (TABLE_NAME = " . $licencaPdo->quote($pendingMode ? 'vw_minhas_licencas_grid' : 'vw_licencas_grid') . " AND COLUMN_NAME = 'SITUACAO_LICENCA'))")->fetchColumn() === 2;
    $licencaPdo = null;
} catch (Throwable $e) { $licencaSituacao = false; }
// Migração 415 (Revisão G itens 48, 55 e 56): Arquivar licença?, Validade inexistente e Validade recorrente (meses)
$licenca415 = false;
if ($licencaSituacao) {
    try {
        $licencaPdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
        $licenca415 = (int)$licencaPdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "
            . $licencaPdo->quote($pendingMode ? 'vw_minhas_licencas_grid' : 'vw_licencas_grid') . " AND COLUMN_NAME IN ('ARQUIVADA', 'VALIDADE_INEXISTENTE', 'VALIDADE_RECORRENTE', 'RECORRENCIA_MESES', 'PROXIMO_VENCIMENTO')")->fetchColumn() === 5;
        $licencaPdo = null;
    } catch (Throwable $e) { $licenca415 = false; }
}
$simNao = ['N' => ['pt' => 'Não', 'en' => 'No', 'es' => 'No'], 'S' => ['pt' => 'Sim', 'en' => 'Yes', 'es' => 'Sí']];
$licencaCampos = static function (array $campos) use ($licencaSituacao, $licenca415, $simNao): array {
    if (!$licencaSituacao) return $campos;
    $opcoes = [];
    foreach (LicencaSituacao::CODIGOS as $codigo) $opcoes[$codigo] = LicencaSituacao::ROTULOS[$codigo];
    $novo = [];
    foreach ($campos as $chave => $campo) {
        $novo[$chave] = $campo;
        if ($chave === 'DSCLICENCA') {
            $novo['SITUACAO_LICENCA'] = ['label' => ['pt' => 'Situação da licença', 'en' => 'License status', 'es' => 'Situación de la licencia'], 'readonly' => true, 'display_from' => 'SITUACAO_LICENCA_DESC', 'options' => $opcoes, 'filter' => 'select', 'width' => 170, 'force_visible' => true];
            $novo['MOTIVO_SITUACAO'] = ['label' => ['pt' => 'Motivo da situação', 'en' => 'Status reason', 'es' => 'Motivo de la situación'], 'readonly' => true, 'filter' => 'text', 'width' => 300];
        }
        if ($chave === 'PROTOCOLADO_EM') $novo['NUMERO_PROTOCOLO'] = ['label' => ['pt' => 'Número do protocolo', 'en' => 'Protocol number', 'es' => 'Número de protocolo'], 'filter' => 'text', 'width' => 160];
        if ($licenca415 && $chave === 'DATA_VALIDADE') {
            $novo['VALIDADE_INEXISTENTE'] = ['label' => ['pt' => 'Validade inexistente?', 'en' => 'No expiry?', 'es' => '¿Sin validez?'], 'options' => $simNao, 'default_new' => 'N', 'insert_value' => 'N', 'filter' => 'select', 'width' => 130,
                'tooltip' => ['pt' => 'Sim = a licença não tem validade (não vence). Diferente de deixar a Data de Vencimento em branco, que aparece como "Sem data de vencimento" (não preenchida).', 'en' => 'Yes = the license has no expiry date (does not expire). Different from leaving the due date blank (not filled).', 'es' => 'Sí = la licencia no tiene validez (no vence). Diferente de dejar la fecha en blanco (no completada).']];
            $novo['VALIDADE_RECORRENTE'] = ['label' => ['pt' => 'Validade recorrente?', 'en' => 'Recurring validity?', 'es' => '¿Validez recurrente?'], 'options' => $simNao, 'default_new' => 'N', 'insert_value' => 'N', 'filter' => 'select', 'width' => 130,
                'tooltip' => ['pt' => 'Sim = a validade se renova sozinha a cada "Recorrência (meses)" a partir da Data de Vencimento: depois do vencimento vale o próximo ciclo, mostrado em "Próximo vencimento".', 'en' => 'Yes = the validity renews itself every "Recurrence (months)" from the due date.', 'es' => 'Sí = la validez se renueva sola cada "Recurrencia (meses)" desde la fecha de vencimiento.']];
            $novo['RECORRENCIA_MESES'] = ['label' => ['pt' => 'Recorrência (meses)', 'en' => 'Recurrence (months)', 'es' => 'Recurrencia (meses)'], 'type' => 'number', 'filter' => 'text', 'width' => 120];
            $novo['PROXIMO_VENCIMENTO'] = ['label' => ['pt' => 'Próximo vencimento', 'en' => 'Next due date', 'es' => 'Próximo vencimiento'], 'type' => 'date', 'readonly' => true, 'filter' => 'text', 'width' => 130];
        }
        if ($licenca415 && $chave === 'CODSTATUS')
            $novo['ARQUIVADA'] = ['label' => ['pt' => 'Arquivar licença?', 'en' => 'Archive license?', 'es' => '¿Archivar licencia?'], 'options' => $simNao, 'default_new' => 'N', 'insert_value' => 'N', 'filter' => 'select', 'width' => 130,
                'tooltip' => ['pt' => 'Sim = licença arquivada: continua no grid (situação "Arquivada"), mas sai dos indicadores, da Home, das pendências e dos avisos de prazo.', 'en' => 'Yes = archived license: stays in the grid but leaves indicators, Home, pending items and deadline notices.', 'es' => 'Sí = licencia archivada: sigue en la grilla, pero sale de los indicadores, la Home, los pendientes y los avisos de plazo.']];
    }
    return $novo;
};
$licencaOrdem = static function (array $ordem) use ($licencaSituacao, $licenca415): array {
    if (!$licencaSituacao) return $ordem;
    $novo = [];
    foreach ($ordem as $c) {
        $novo[] = $c;
        if ($licenca415 && $c === 'DATA_VALIDADE') array_push($novo, 'VALIDADE_INEXISTENTE', 'VALIDADE_RECORRENTE', 'RECORRENCIA_MESES', 'PROXIMO_VENCIMENTO');
        if ($licenca415 && $c === 'CODSTATUS') $novo[] = 'ARQUIVADA';
        if ($c === 'DSCLICENCA') { $novo[] = 'SITUACAO_LICENCA'; $novo[] = 'MOTIVO_SITUACAO'; }
        if ($c === 'PROTOCOLADO_EM') $novo[] = 'NUMERO_PROTOCOLO';
    }
    return $novo;
};

ManutencaoLegalCadastroGrid::render([
    // Migração 341: Severidade × Probabilidade = Risco do item (obrigatório ao incluir; ver Ajuda de Requisitos legais)
    'risk_area' => 'LICENCAS',
    'menu' => $licenseMenu, 'title' => $licenseTitle,
    'transaction' => $licenseTransaction, 'fragment_class' => 'licencas-grid-fragment',
    'database' => 'gmodelo_dbp', 'prefix' => 'GPT', 'table' => 'li_licencas', 'view' => $pendingMode ? 'vw_minhas_licencas_grid' : 'vw_licencas_grid',
    'primary' => 'CODLICENCA', 'primary_auto' => true,
    // Migração 361: botão Responsáveis (registro selecionado); Minhas licenças = Responsável + atribuições + regras (vw_resp_minhas_licencas)
    'resp_area' => 'LICENCAS', 'resp_minhas' => ($isMyLicenses && !$pendingMode) ? 'LICENCAS' : '',
    'user_scope_field' => 'CODUSUARIO', 'force_user_scope' => $isMyLicenses, 'questionnaire_context' => 'SGA', 'questionnaire_origin_fields' => ['CODLICENCA', 'CODUNIDADE'], 'extras' => $licenseActions,
    'button_options' => $isMyLicenses ? ['save' => true, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true] : [],
    'legacy_order' => $licencaOrdem(['DSCUNIDADE','CODLICENCA','DSCLICENCA','CODTIPODOCUMENTO','DSCTIPODOCUMENTO','DATA_VALIDADE','DATA_REACAO','DATA_PROTOCOLO','ATIVIDADE','ORGAO_EMISSOR','NUMERO_LICENCA','CODUSUARIO','ARQUIVOS','CODLISTA','EMPROTOCOLO','PROTOCOLADO_EM','PROTOCOLADO_NO_PRAZO','QTD_DIAS_EM_PROTOCOLO','CODSTATUS','OBSERVACAO','CODCLASSIFICACAO','CODFREQUENCIA','QTDDIAS','QTDDIAS2','CODUNIDADE']),
    'fields' => $licencaCampos([
        'CODLICENCA' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'hidden' => true],
        'DSCUNIDADE' => ['label' => ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'], 'readonly' => true, 'filter' => 'text', 'width' => 150],
        'DSCLICENCA' => ['label' => ['pt' => 'Descrição', 'en' => 'Description', 'es' => 'Descripción'], 'type' => 'textarea', 'filter' => 'text', 'width' => 300, 'force_visible' => true],
        'CODTIPODOCUMENTO' => ['label' => ['pt' => 'Tipo documental', 'en' => 'Document type', 'es' => 'Tipo documental'], 'foreign' => ['view' => 'li_tipos_documentos', 'value' => 'CODTIPODOCUMENTO', 'label' => 'DSCTIPODOCUMENTO', 'description' => 'DSCTIPODOCUMENTO'], 'filter' => 'select', 'width' => 220, 'force_visible' => true],
        'DSCTIPODOCUMENTO' => ['label' => ['pt' => 'Tipo documental', 'en' => 'Document type', 'es' => 'Tipo documental'], 'readonly' => true, 'display_only' => true, 'filter' => 'none', 'hidden' => true],
        'DATA_VALIDADE' => ['label' => ['pt' => 'Data de Vencimento', 'en' => 'Due Date', 'es' => 'Fecha de vencimiento'], 'type' => 'date', 'filter' => 'text', 'width' => 130],
        'DATA_REACAO' => ['label' => ['pt' => 'Data de Reação', 'en' => 'Reaction Date', 'es' => 'Fecha de reacción'], 'type' => 'date', 'filter' => 'text', 'width' => 130],
        'DATA_PROTOCOLO' => ['label' => ['pt' => 'Data do protocolo', 'en' => 'Protocol Date', 'es' => 'Fecha de protocolo'], 'type' => 'date', 'filter' => 'text', 'width' => 130],
        'ATIVIDADE' => ['label' => ['pt' => 'Atividade', 'en' => 'Activity', 'es' => 'Actividad'], 'type' => 'textarea', 'filter' => 'text', 'width' => 220],
        'ORGAO_EMISSOR' => ['label' => ['pt' => 'Órgão emissor', 'en' => 'Issuing authority', 'es' => 'Organismo emisor'], 'filter' => 'text', 'width' => 180],
        'NUMERO_LICENCA' => ['label' => ['pt' => 'Número da licença', 'en' => 'License number', 'es' => 'Número de licencia'], 'filter' => 'text', 'width' => 160],
        'CODUSUARIO' => ['label' => ['pt' => 'Responsável', 'en' => 'Responsible', 'es' => 'Responsable'], 'display_from' => 'RESPONSAVEL', 'foreign' => ['view' => 'vw_usuarios_grid', 'value' => 'codusuario', 'label' => 'nome', 'filters' => ['ativo' => 1], 'description' => 'nome'], 'lazy_foreign' => true, 'allow_empty' => true, 'filter' => 'text', 'width' => 180],
        'ARQUIVOS' => ['label' => ['pt' => 'Arquivos', 'en' => 'Files', 'es' => 'Archivos'], 'readonly' => true, 'filter' => 'text', 'width' => 100],
        'CODLISTA' => ['label' => ['pt' => 'Lista de distribuição', 'en' => 'Distribution list', 'es' => 'Lista de distribución'], 'filter' => 'text', 'width' => 300],
        'EMPROTOCOLO' => ['label' => ['pt' => 'Em protocolo?', 'en' => 'On protocol?', 'es' => '¿En protocolo?'], 'options' => ['' => '', 'S' => ['pt' => 'Sim', 'en' => 'Yes', 'es' => 'Sí'], 'N' => ['pt' => 'Não', 'en' => 'No', 'es' => 'No']], 'filter' => 'select', 'width' => 100],
        'PROTOCOLADO_EM' => ['label' => ['pt' => 'Protocolado em', 'en' => 'Protocolized on', 'es' => 'Protocolizado en'], 'type' => 'date', 'filter' => 'text', 'width' => 130],
        'PROTOCOLADO_NO_PRAZO' => ['label' => ['pt' => 'Protocolado no prazo?', 'en' => 'Protocolized on time?', 'es' => '¿Protocolizado en plazo?'], 'options' => ['' => '', 'S' => ['pt' => 'Sim', 'en' => 'Yes', 'es' => 'Sí'], 'N' => ['pt' => 'Não', 'en' => 'No', 'es' => 'No']], 'filter' => 'select', 'width' => 130],
        'QTD_DIAS_EM_PROTOCOLO' => ['label' => ['pt' => 'Dias em protocolo', 'en' => 'Days under protocol', 'es' => 'Días en protocolo'], 'readonly' => true, 'filter' => 'text', 'width' => 120],
        'CODSTATUS' => ['label' => ['pt' => 'Status do protocolo', 'en' => 'Protocol status', 'es' => 'Estado del protocolo'], 'foreign' => ['view' => 'li_status_protocolo', 'value' => 'CODSTATUS', 'label' => 'DSCSTATUS', 'description' => 'DSCSTATUS'], 'filter' => 'select', 'width' => 150],
        'OBSERVACAO' => ['label' => ['pt' => 'Observação', 'en' => 'Notes', 'es' => 'Observación'], 'type' => 'textarea', 'filter' => 'text', 'width' => 220],
        'CODCLASSIFICACAO' => ['label' => ['pt' => 'Classificação', 'en' => 'Classification', 'es' => 'Clasificación'], 'foreign' => ['view' => 'li_classificacao', 'value' => 'CODCLASSIFICACAO', 'label' => 'DSCCLASSIFICACAO', 'description' => 'DSCCLASSIFICACAO'], 'filter' => 'select', 'width' => 140],
        'CODFREQUENCIA' => ['label' => ['pt' => 'Frequência', 'en' => 'Frequency', 'es' => 'Frecuencia'], 'foreign' => ['view' => 'li_frequencia', 'value' => 'CODFREQUENCIA', 'label' => 'DSCFREQUENCIA', 'description' => 'DSCFREQUENCIA'], 'filter' => 'select', 'width' => 120],
        'QTDDIAS' => ['label' => ['pt' => 'Qtd dias vencimento', 'en' => 'Validity days', 'es' => 'Días hasta vencimiento'], 'readonly' => true, 'filter' => 'text', 'width' => 120],
        'QTDDIAS2' => ['label' => ['pt' => 'Qtd dias reação', 'en' => 'Reaction days', 'es' => 'Días hasta reacción'], 'readonly' => true, 'filter' => 'text', 'width' => 120],
        'CODUNIDADE' => ['label' => ['pt' => 'Código da Unidade', 'en' => 'Unit Code', 'es' => 'Código de unidad'], 'hidden' => true, 'readonly' => true, 'filter' => 'none']
    ])
]);
?>
<?= IdiomaService::script(['manutencaolegal_licencas'], [
    'manutencaolegal_licencas.msg.erro_atualizar' => ['pt' => 'Não foi possível atualizar o grid.', 'en' => 'The grid could not be refreshed.', 'es' => 'No fue posible actualizar la cuadrícula.'],
    'manutencaolegal_licencas.msg.salva' => ['pt' => 'Licença salva com sucesso.', 'en' => 'License saved successfully.', 'es' => 'Licencia guardada correctamente.'],
    'manutencaolegal_licencas.msg.erro_salvar' => ['pt' => 'Não foi possível salvar a licença.', 'en' => 'The license could not be saved.', 'es' => 'No fue posible guardar la licencia.'],
    'manutencaolegal_licencas.legenda.vencida' => ['pt' => 'Vencida', 'en' => 'Expired', 'es' => 'Vencida'],
    'manutencaolegal_licencas.legenda.atencao' => ['pt' => 'Em atenção (prazo de reação ou em protocolo)', 'en' => 'Attention (reaction period or filed for renewal)', 'es' => 'En atención (plazo de reacción o en protocolo)'],
    'manutencaolegal_licencas.legenda.emdia' => ['pt' => 'Em dia', 'en' => 'Up to date', 'es' => 'Al día'],
    'manutencaolegal_licencas.legenda.semdata' => ['pt' => 'Sem data de vencimento', 'en' => 'No due date', 'es' => 'Sin fecha de vencimiento'],
    'manutencaolegal_licencas.popup.taxas' => ['pt' => 'Taxas da licença', 'en' => 'License fees', 'es' => 'Tasas de la licencia'],
    'manutencaolegal_licencas.legenda.protocolo' => ['pt' => 'Válida (em protocolo): vencida com a renovação protocolada no prazo', 'en' => 'Valid (renewal filed on time)', 'es' => 'Válida (en protocolo)'],
]) ?>
<script src="../assets/js/modulo-legal-arquivos.js?v=5"></script>
<script>
(function () {
    if (!window.jQuery) return;
    var $ = window.jQuery;
    $(document).off('click.greenPortalDateOpen click.greenPortalDatePicker click.greenPortalNativeBridge');
    $(function () {
        var transaction = <?= json_encode($licenseTransaction, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        var gridId = '#' + transaction + 'BatchGrid';
        var formId = '#' + transaction + 'GridForm';
        var $fragment = $('.licencas-grid-fragment').first(), $form = $(formId);
        if (!$fragment.length || !$form.length || $form.data('ajax-save-ready')) return;
        $form.data('ajax-save-ready', true);
        $form[0].addEventListener('submit', function (event) {
            if (!$form.data('confirmed-submit')) return;
            event.preventDefault();
            // Mantém a marca durante a propagação para o listener genérico
            // de CRUD não abrir novamente o modal de confirmação.
            window.setTimeout(function () { $form.removeData('confirmed-submit'); }, 0);
            var $save = $fragment.find('[data-crud-action="save"],button[type="submit"]').first();
            $save.prop('disabled', true).attr('aria-busy', 'true');
            var data = $form.serializeArray();
            data.forEach(function (item) {
                if (!/\[DATA_|\[PROTOCOLADO_EM\]/.test(item.name) || !item.value) return;
                var match = String(item.value).match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
                if (!match) return;
                item.value = window.greenPortalIdioma === 'en'
                    ? match[3] + '-' + match[1] + '-' + match[2]
                    : match[3] + '-' + match[2] + '-' + match[1];
            });
            $.ajax({ url: window.location.href, method: 'POST', data: $.param(data), dataType: 'html' })
                .done(function (html) {
                    // 26/11/2026: o servidor devolve a página com o aviso de erro (ex.: referência inválida); antes a tela
                    // mostrava "Licença salva com sucesso" mesmo quando nada era gravado.
                    var $resp = $(html), $erro = $resp.find('.alert-danger, .alert-warning').filter(function () { return !$(this).is('[data-license-save-message]'); }).first();
                    if ($erro.length) { showMessage($.trim($erro.text()), 'danger'); return; }
                    var $newBody = $resp.find(gridId + ' tbody').first();
                    if (!$newBody.length) { showMessage(window.greenT ? window.greenT('manutencaolegal_licencas.msg.erro_atualizar', 'Não foi possível atualizar o grid.') : 'Não foi possível atualizar o grid.', 'danger'); return; }
                    $fragment.find(gridId + ' tbody').replaceWith($newBody);
                    $fragment.trigger('gridPageLoaded');
                    showMessage(window.greenT ? window.greenT('manutencaolegal_licencas.msg.salva', 'Licença salva com sucesso.') : 'Licença salva com sucesso.', 'success');
                })
                .fail(function (xhr) { showMessage((xhr.responseJSON && xhr.responseJSON.message) || (window.greenT ? window.greenT('manutencaolegal_licencas.msg.erro_salvar', 'Não foi possível salvar a licença.') : 'Não foi possível salvar a licença.'), 'danger'); })
                .always(function () { $save.prop('disabled', false).removeAttr('aria-busy'); });
        }, true);
        $fragment.on('click', '[data-crud-action="save"]', function (event) {
            event.preventDefault(); event.stopImmediatePropagation();
            $form.data('confirmed-submit', true);
            $form[0].requestSubmit();
        });
        function showMessage(text, type) {
            $fragment.find('[data-license-save-message]').remove();
            $('<div>', { 'data-license-save-message': '', class: 'alert alert-' + type + ' py-2 mb-2', text: text }).insertAfter($fragment.find('.crud-toolbar').first());
        }
    });
}());
</script>
<script>
(function () {
    var ordemGridLicencas = <?= json_encode($licencaOrdem(['DSCUNIDADE','CODLICENCA','DSCLICENCA','DATA_VALIDADE','DATA_REACAO','DATA_PROTOCOLO','ATIVIDADE','ORGAO_EMISSOR','NUMERO_LICENCA','CODUSUARIO','ARQUIVOS','CODLISTA','EMPROTOCOLO','PROTOCOLADO_EM','PROTOCOLADO_NO_PRAZO','QTD_DIAS_EM_PROTOCOLO','CODSTATUS','OBSERVACAO','CODCLASSIFICACAO','CODFREQUENCIA','QTDDIAS','QTDDIAS2','CODUNIDADE']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    function exportarSnapshotGrid(event) {
        var target = event.target && event.target.closest ? event.target.closest('[data-export-cadastro]') : null;
        var fragment = target && target.closest('.licencas-grid-fragment');
        if (!target || !fragment) return;
        event.preventDefault(); event.stopImmediatePropagation();
        var table = fragment.querySelector('.menu-batch-grid'), visible = {};
        if (!table) return;
        table.querySelectorAll('thead th[data-column]').forEach(function (heading) {
            if (heading.offsetParent !== null && getComputedStyle(heading).display !== 'none') visible[heading.getAttribute('data-column')] = true;
        });
        var columns = ordemGridLicencas.filter(function (column) { return visible[column]; });
        var filters = {};
        table.querySelectorAll('[data-column-filter]').forEach(function (input) { if (input.value !== '') filters[input.getAttribute('data-column-filter')] = input.value; });
        var search = fragment.querySelector('.crud-search input');
        target.disabled = true; target.setAttribute('data-export-active', '1');
        if (window.jQuery) window.jQuery.post(window.location.href, { action: 'export_request', filtros: JSON.stringify(filters), busca: search ? search.value : '', colunas: JSON.stringify(columns) }).always(function () { target.disabled = false; target.removeAttribute('data-export-active'); });
    }
    document.addEventListener('click', exportarSnapshotGrid, true);
}());
</script>
<script>
(function () {
    function normalizeEmptyDates() {
        document.querySelectorAll('.licencas-grid-fragment input[type="date"]').forEach(function (input) {
            input.lang = window.greenPortalIdioma === 'en' ? 'en-US' : (window.greenPortalIdioma === 'es' ? 'es-ES' : 'pt-BR');
            input.type = 'date';
        });
    }
    normalizeEmptyDates();
    if (window.jQuery) window.jQuery('.licencas-grid-fragment').on('gridPageLoaded', normalizeEmptyDates);
    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('.licencas-grid-fragment .calendar-picker') : null;
        if (!button) return;
        var input = button.parentElement ? button.parentElement.querySelector('input') : null;
        if (!input) return;
        input.lang = window.greenPortalIdioma === 'en' ? 'en-US' : (window.greenPortalIdioma === 'es' ? 'es-ES' : 'pt-BR');
        window.setTimeout(function () { try { if (typeof input.showPicker === 'function') input.showPicker(); } catch (error) { input.focus(); } }, 0);
    }, true);
}());
</script>
<?php
// Barra de botões em grupos (assets/js/toolbar-grupos.js). O JSON sai em ASCII (\\u...) para não
// sofrer com a correção automática de acentuação do portal.
$toolbarGroupLabels = [
    IdiomaService::t('comum.grupo.operacoes', ['pt' => 'Operações', 'en' => 'Operations', 'es' => 'Operaciones']),
    IdiomaService::t('comum.grupo.associacoes', ['pt' => 'Associações', 'en' => 'Associations', 'es' => 'Asociaciones']),
    IdiomaService::t('comum.grupo.avaliacao', ['pt' => 'Avaliação', 'en' => 'Assessment', 'es' => 'Evaluación']),
];
$toolbarGroups = [
    'fragmento' => '.licencas-grid-fragment',
    'rotuloOutros' => IdiomaService::t('comum.grupo.outros', ['pt' => 'Outros', 'en' => 'Others', 'es' => 'Otros']),
    'grupos' => [
        ['id' => 'operacoes', 'rotulo' => $toolbarGroupLabels[0], 'botoes' => ['[data-crud-action="save"]', '[data-crud-action="new"]', '[data-crud-action="duplicate"]', '[data-crud-action="delete"]', '[data-crud-action="reload"]', '[data-crud-action="export"]', '[data-crud-action="columns"]', '[data-open-transacao-ajuda]']],
        ['id' => 'associacoes', 'rotulo' => $toolbarGroupLabels[1], 'botoes' => ['[data-license-condicionantes]', '[data-license-palavras-chave]', '[data-license-responsabilidades]', '[data-license-requisitos]', '[data-license-arquivos]', '[data-license-taxas]', '[data-license-campos]']],
        ['id' => 'avaliacao', 'rotulo' => $toolbarGroupLabels[2], 'botoes' => ['[data-context-id^="questionario-"]']],
    ],
];
?>
<link href="../assets/css/toolbar-grupos.css?v=3" rel="stylesheet">
<script type="application/json" data-toolbar-grupos-config><?= json_encode($toolbarGroups, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="../assets/js/toolbar-grupos.js?v=8"></script>
<link href="../assets/css/licencas-vencimento.css?v=3" rel="stylesheet">
<script src="../assets/js/licencas-vencimento.js?v=5"></script>
<script src="../assets/js/associacoes-contagem.js?v=6"></script>
<script>
(function () {
    if (!window.jQuery) return;
    var $ = window.jQuery, fragment = $('.licencas-grid-fragment').first(), button = fragment.find('[data-license-requisitos]'), taxas = fragment.find('[data-license-taxas]'), campos = fragment.find('[data-license-campos]');
    function selectedId() { var row = fragment.find('table tbody tr.selected').first(); return row.length ? Number(row.attr('data-key') || 0) : 0; }
    function refresh() { button.prop('disabled', !selectedId()); taxas.prop('disabled', !selectedId()); campos.prop('disabled', !selectedId()); }
    // Revisão G item 63: campos personalizados da licença selecionada (popup)
    campos.on('click', function () { var id = selectedId(); if (!id) return; var host = window.top || window, url = (host.greenPortalBaseUrl || '') + '/principal/licencas_campos.php?licenca=' + encodeURIComponent(id), titulo = campos.attr('title') || 'Campos personalizados';
        if (host.GreenPortalPopups && host.jQuery) host.GreenPortalPopups.transacaoIndependente({ titulo: titulo, conteudo: host.jQuery('<iframe>', { src: url, style: 'width:100%;height:100%;border:0;display:block;' }), redimensionavel: true });
        else if (host.greenPortalOpenTab) host.greenPortalOpenTab('licenca-campos-' + id, url, titulo); });
    // Migração 344: taxas da licença selecionada (ficam na licença original e valem para todas as versões)
    taxas.on('click', function () { var id = selectedId(); if (!id) return; var host = window.top || window, row = fragment.find('table tbody tr.selected').first(), nome = $.trim(row.find('[data-column="DSCLICENCA"]').find('textarea,input').first().val() || row.find('[data-column="DSCLICENCA"]').text() || ''), titulo = (window.greenT ? window.greenT('manutencaolegal_licencas.popup.taxas', 'Taxas da licença') : 'Taxas da licença') + (nome ? ' - ' + nome.substring(0, 70) : ''), url = (host.greenPortalBaseUrl || '') + '/principal/manutencaolegal_licencas_taxas.php?filtros=' + encodeURIComponent(JSON.stringify({ CODLICENCA: String(id) })); if (host.GreenPortalPopups && host.jQuery) { var frame = host.jQuery('<iframe>', { src: url, style: 'width:100%;height:100%;border:0;display:block;' }); host.GreenPortalPopups.transacaoIndependente({ titulo: titulo, conteudo: frame, amplo: true, redimensionavel: true }); } else if (host.greenPortalOpenTab) host.greenPortalOpenTab('licenca-taxas-' + id, url, titulo); });
    fragment.on('click', 'table tbody tr', refresh).on('gridPageLoaded', refresh);
    button.on('click', function () { var id = selectedId(); if (!id) return; var host = window.top || window, frame = host.jQuery('<iframe>', { src: 'licenca_requisitos_documentos.php?codlicenca=' + encodeURIComponent(id), style: 'width:100%;height:100%;border:0;display:block;' }); if (host.GreenPortalPopups && host.jQuery) host.GreenPortalPopups.transacaoIndependente({ titulo: 'Requisitos e documentos da licença', conteudo: frame, amplo: true, redimensionavel: true }); });
    refresh();
}());
</script>
