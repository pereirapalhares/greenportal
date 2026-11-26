(function ($) {
    'use strict';
    var configs = [
        { selector: '.controle-documentos-grid-fragment', type: 'controles', key: 'CODDOCUMENTO', folder: 'documentos', column: 'ARQUIVO', title: { pt: 'Arquivos do controle', en: 'Control files', es: 'Archivos del control' } },
        { selector: '.modulo-legal-treinamentos-grid-fragment', type: 'treinamentos', key: 'CODTREINAMENTO', column: 'ARQUIVO', title: { pt: 'Arquivos do treinamento', en: 'Training files', es: 'Archivos de la capacitación' } },
        { selector: '.modulo-legal-infraestrutura-grid-fragment', type: 'infraestrutura', key: 'CODINFRAESTRUTURA', column: 'ARQUIVO', title: { pt: 'Arquivos da infraestrutura', en: 'Infrastructure files', es: 'Archivos de la infraestructura' } },
        // Migra\u00e7\u00e3o 410: certificados de cada trabalhador treinado (bot\u00e3o Treinados de Treinamentos)
        // Migra\u00e7\u00e3o 412: evid\u00eancias de cada manuten\u00e7\u00e3o do plano da infraestrutura
        { selector: '.ml-infraestrutura-manutencoes-grid-fragment', type: 'infraestruturamanutencoes', key: 'CODMANUTENCAO', folder: 'ml_infraestrutura_manutencoes', column: 'ARQUIVO', describe: 'ATIVIDADE', title: { pt: 'Evid\u00eancias da manuten\u00e7\u00e3o', en: 'Maintenance evidence', es: 'Evidencias del mantenimiento' } },
        { selector: '.ml-treinamentos-participantes-grid-fragment', type: 'treinamentoparticipantes', key: 'CODPARTICIPANTE', folder: 'ml_treinamentos_participantes', column: 'ARQUIVO', describe: 'NOME', title: { pt: 'Certificados do treinado', en: 'Trained worker certificates', es: 'Certificados del capacitado' } },
        { selector: '.licencas-condicionantes-planodeacao-grid-fragment', type: 'planodeacao', key: 'CODPLANOACAO', column: 'ARQUIVO', describe: 'ACAO', title: { pt: 'Evidências do plano de ação', en: 'Action plan evidence', es: 'Evidencias del plan de acción' } },
        // Revis\u00e3o G item 73: Evid\u00eancia da medi\u00e7\u00e3o (Plano de Medi\u00e7\u00e3o e Monitoramento)
        { selector: '.medicao-monitoramento-grid-fragment', type: 'medicao', key: 'CODMONITORAMENTO', folder: 'mm_monitoramento', column: 'EVIDENCIA', describe: 'PARAMETROS', title: { pt: 'Evid\u00eancia da medi\u00e7\u00e3o', en: 'Measurement evidence', es: 'Evidencia de la medici\u00f3n' } },
        { selector: '.licencas-grid-fragment', type: 'licencas', key: 'CODLICENCA', folder: 'licencas', column: 'ARQUIVOS', describe: 'DSCLICENCA', title: { pt: 'Arquivos da licença', en: 'License files', es: 'Archivos de la licencia' } },
        { selector: '.homologacao-funcionario-grid-fragment', type: 'funcionarios', key: 'CODFUNCIONARIO', folder: 'fo_funcionarios', column: 'ANEXO', describe: 'NOME', title: { pt: 'Anexos do funcionário', en: 'Employee attachments', es: 'Anexos del empleado' } },
        { selector: '.portaria-documentacao-fornecedor-grid-fragment', type: 'fornecedorcriterios', key: 'CODFORNECEDORXCRITERIOS', folder: 'fo_fornecedorxcriterios', column: 'ARQUIVOS', describe: 'DSCRITERIO', title: { pt: 'Evidências', en: 'Evidence', es: 'Evidencias' } },
        { selector: '.homologacao-fornecedor-criterios-grid-fragment', type: 'fornecedorcriterios', key: 'CODFORNECEDORXCRITERIOS', folder: 'fo_fornecedorxcriterios', column: 'ARQUIVOS', describe: 'DSCRITERIO', title: { pt: 'Evid' + String.fromCharCode(234) + 'ncias', en: 'Evidence', es: 'Evidencias' } },
        { selector: '.portaria-documentacao-funcionario-grid-fragment', type: 'funcionariocriterios', key: 'CODIGO', folder: 'fo_funcionarioxtiposxcriterios', column: 'ARQUIVOS', describe: 'FUNCIONARIO', title: { pt: 'Evidências', en: 'Evidence', es: 'Evidencias' } },
        { selector: '.portaria-documentacao-treinamento-grid-fragment', type: 'funcionariotreinamentos', key: 'CODIGO', folder: 'fo_funcionarioxtiposxtreinamentos', column: 'ARQUIVOS', describe: 'DSCTIPOTREINAMENTO', title: { pt: 'Evidências', en: 'Evidence', es: 'Evidencias' } },
        // Migração 300: abas do cadastro do fornecedor (mesmas pastas do GED do legado)
        { selector: '.homologacao-fornecedor-certificados-grid-fragment', type: 'fornecedorcertificados', key: 'CODCERTIFICADO', folder: 'fo_certificados', column: 'ARQUIVOS', describe: 'DSCCERTIFICADO', title: { pt: 'Arquivos do certificado', en: 'Certificate files', es: 'Archivos del certificado' } },
        { selector: '.homologacao-fornecedor-quimicos-grid-fragment', type: 'fornecedorquimicos', key: 'CODPRODUTO', folder: 'fo_produtoquimico', column: 'ARQUIVOS', describe: 'DSCPRODUTO', title: { pt: 'FDS / arquivos do produto', en: 'SDS / product files', es: 'FDS / archivos del producto' } },
        { selector: '.homologacao-fornecedor-equipamentos-grid-fragment', type: 'fornecedorequipamentos', key: 'CODEQUIPAMENTO', folder: 'fo_equipamentos', column: 'ARQUIVOS', describe: 'DSCEQUIPAMENTO', title: { pt: 'Arquivos do equipamento', en: 'Equipment files', es: 'Archivos del equipo' } },
        { selector: '.homologacao-fornecedor-planos-grid-fragment', type: 'fornecedorplanos', key: 'CODPLANOACAO', folder: 'fo_planodeacao', column: 'ARQUIVOS', describe: 'ACAO', title: { pt: 'Arquivos do plano de ação', en: 'Action plan files', es: 'Archivos del plan de acción' } },
        { selector: '.homologacao-fornecedor-auditoria-grid-fragment', type: 'fornecedorauditoria', key: 'CODFORNECEDORXTIPOFORNECEDORES', folder: 'fo_fornecedorxauditoria', column: 'ARQUIVOS', describe: 'CODTIPOFORNECEDOR', title: { pt: 'Evidências da auditoria', en: 'Audit evidence', es: 'Evidencias de la auditoría' } }
    ];
    function idioma() { var host = window.top || window; return String(host.greenPortalIdioma || window.greenPortalIdioma || 'pt').toLowerCase().substring(0, 2); }
    function titulo(config, $row) {
        if (config.describe && $row && $row.length) {
            var $d = $row.find('[data-column="' + config.describe + '"]');
            var description = $.trim(String($d.find('select option:selected').text() || $d.find('input, textarea').first().val() || $d.text() || '')).substring(0, 80);
            if (description) return config.type === 'licencas' ? description : (config.title[idioma()] || config.title.pt) + ' - ' + description;
        }
        return config.title[idioma()] || config.title.pt;
    }
    configs.forEach(function (config) {
        $(config.selector).each(function () {
            var $fragment = $(this), $table = $fragment.find('.menu-batch-grid').first();
            if (!$table.length) return;
            // Código do registro da linha: data-key; em linha nova (novo_...) ainda não gravada, o valor escolhido no campo-chave
            // (ex.: licença selecionada na associação), para já poder ver e enviar os arquivos dela.
            function rowId($row) {
                var key = String($row.attr('data-key') || '');
                if (/^\d+$/.test(key)) return Number(key);
                var v = String($row.find('[data-column="' + config.key + '"] select, [data-column="' + config.key + '"] input').first().val() || '').trim();
                return /^\d+$/.test(v) ? Number(v) : 0;
            }
            function refreshCounts() {
                var ids = $table.find('tbody tr[data-key]').map(function () { return rowId($(this)); }).get().filter(Boolean);
                if (!ids.length) return;
                $.getJSON('api/ged_contagens.php', { tipo: config.type, ids: JSON.stringify(ids) }).done(function (data) {
                    if (!data || !data.ok) return;
                    $table.find('tbody tr[data-key]').each(function () { var id = String(rowId($(this)) || ''); $(this).find('[data-ml-file-count]').text(String((data.counts || {})[id] || 0)); });
                });
            }
            function transform() {
                $table.find('tbody tr[data-key]').each(function () {
                    var $row = $(this), id = rowId($row), $cell = $row.find('[data-column="' + config.column + '"]').first();
                    if (!$cell.length || !id || $cell.find('[data-ml-file-button]').length) return;
                    var label = titulo(config, $row);
                    $cell.empty().append($('<button>', { type: 'button', class: 'btn btn-sm btn-outline-secondary', 'data-ml-file-button': '', 'data-ged-type': config.type, 'data-ged-key': config.key, 'data-ged-folder': config.folder || '', 'data-bs-toggle': 'tooltip', 'data-bs-placement': 'top', title: label, 'aria-label': label }).append($('<i>', { class: 'bi bi-files' }), ' ', $('<span>', { 'data-ml-file-count': '', text: '0' })));
                    if (window.bootstrap && bootstrap.Tooltip) bootstrap.Tooltip.getOrCreateInstance($cell.find('[data-ml-file-button]')[0]);
                });
                refreshCounts();
            }
            function openFiles(event) {
                event.preventDefault(); event.stopPropagation();
                var $row = $(event.currentTarget).closest('tr'), id = rowId($row), host = window.top || window;
                if (!id) return;
                var urlObject = new URL('gestao_arquivos.php?tipo=' + encodeURIComponent(config.type) + '&chave=' + encodeURIComponent(config.key) + '&valor=' + encodeURIComponent(id), window.location.href), context = $fragment.data('context-filters') || {};
                if (context.CODUNIDADE) urlObject.searchParams.set('CODUNIDADE', context.CODUNIDADE);
                if (context.CODLEGISLACAO) urlObject.searchParams.set('CODLEGISLACAO', context.CODLEGISLACAO);
                var url = urlObject.toString();
                var $frame = host.jQuery ? host.jQuery('<iframe>', { src: url, style: 'width:100%;height:100%;border:0;display:block;' }) : null; if ($frame) $frame.on('load.gedPreviewLabels', function () { var frame = this; window.setTimeout(function () { try { var doc = frame.contentDocument, label = 'Visualiza' + String.fromCharCode(231, 227) + 'o do arquivo'; host.jQuery(doc).find('#gedPreviewTab').text(label); host.jQuery(doc).find('#gedPreviewFrame').attr('title', label); host.jQuery(doc).find('#gedPreviewImage').attr('alt', label); } catch (ignore) {} }, 250); });
                if (host.GreenPortalPopups && host.jQuery && $frame) {
                    var popupTitle = titulo(config, $row);
                    if (typeof host.GreenPortalPopups.transacaoIndependente === 'function') host.GreenPortalPopups.transacaoIndependente({ titulo: popupTitle, conteudo: $frame, compacto: config.type !== 'licencas', amplo: config.type === 'licencas', redimensionavel: true });
                    else host.GreenPortalPopups.transacao({ titulo: popupTitle, conteudo: $frame });
                } else window.open(url, '_blank');
            }
            var espera = null;
            $fragment.off('gridPageLoaded.gedFiles click.gedFiles change.gedFiles').on('gridPageLoaded.gedFiles', transform).on('click.gedFiles', '[data-ml-file-button]', openFiles)
                .on('change.gedFiles', 'tbody tr[data-key^="novo_"]', function () { window.clearTimeout(espera); espera = window.setTimeout(transform, 60); });
            transform();
        });
    });
    // Migração 308: dados do plano de ação aprovados na análise por IA da evidência (gestao_arquivos.php) atualizam a linha
    // do grid aberto, sem recarregar a tela.
    window.addEventListener('message', function (event) {
        var msg = event && event.data;
        if (event.origin !== window.location.origin || !msg || msg.tipo !== 'gedPlanoAcaoAtualizado' || !msg.aplicados) return;
        $('.licencas-condicionantes-planodeacao-grid-fragment tbody tr[data-key="' + String(Number(msg.valor) || 0) + '"]').each(function () {
            var $row = $(this);
            Object.keys(msg.aplicados).forEach(function (campo) {
                if (campo === 'RESPONSAVEL') return;
                var valor = String(msg.aplicados[campo] == null ? '' : msg.aplicados[campo]), $campo = $row.find('[data-column="' + campo + '"]').find('input, textarea, select').first();
                if (!$campo.length) return;
                if ($campo.is('select') && !$campo.find('option').filter(function () { return this.value === valor; }).length)
                    $campo.append($('<option>', { value: valor, text: campo === 'CODUSUARIO' ? String(msg.aplicados.RESPONSAVEL || valor) : (campo === 'STATUS' ? valor + '%' : valor) }));
                $campo.val(valor);
                if ($campo.is('textarea')) $campo.text(valor);
                $campo.attr('data-raw-value', valor);
            });
            $row.addClass('table-success'); window.setTimeout(function () { $row.removeClass('table-success'); }, 2500);
        });
    });
}(jQuery));
