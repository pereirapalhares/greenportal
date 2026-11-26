/*
 * Barra de etapas — "fluxo do estágio tipo entrega do Mercado Livre" (onda 1 da Revisão G, itens 18.4, 47.1, 65.1, 82.1,
 * 98.1, 113.1, 128.1, 154 e 164 — 24/11/2026). Acima do grid de cada módulo aparece a sequência de etapas do processo
 * de gestão (os workflows da planilha). Ao selecionar uma linha, a barra mostra em que etapa aquele registro está:
 *
 *   feito ....... verde com visto
 *   atual ....... azul (primeira etapa obrigatória ainda não feita)
 *   pendente .... cinza com o número
 *   facultativa . contorno tracejado (não trava o processo)
 *   não se aplica / ainda não disponível ... cinza claro com traço (ex.: sem plano de ação aberto, campo que ainda não existe)
 *
 * O que está na linha do grid (responsável, datas, situação) é lido da própria linha; o restante (evidências no GED, risco,
 * condicionantes, planos de ação, critérios do fornecedor, dados da ocorrência) vem de api/fluxo_etapas.php.
 * Carregado no main.php; instala-se no portal e nos iframes do mesmo endereço, como status-icones.js.
 * A barra pode ser recolhida (lembra por módulo, no navegador). Textos com acento escritos como \u.... .
 */
(function () {
    'use strict';
    var topo = window;
    try { while (topo.parent && topo.parent !== topo && topo.parent.document) topo = topo.parent; } catch (e) { /* outro endere\u00e7o */ }
    var idioma = String(topo.greenPortalIdioma || document.documentElement.getAttribute('lang') || 'pt').slice(0, 2).toLowerCase();
    var BASE = String(topo.greenPortalBaseUrl || '..');
    function L(pt, en, es) { return idioma === 'en' ? (en || pt) : (idioma === 'es' ? (es || pt) : pt); }
    function tr(chave, pt, en, es) {
        var padrao = L(pt, en, es);
        try { if (typeof topo.greenT === 'function') return topo.greenT('fluxo_etapas.' + chave, padrao); } catch (e) { /* sem tradu\u00e7\u00e3o */ }
        return padrao;
    }
    var NA = 'na', PASSOU = 'passou';

    /* ---------- leitura da linha ---------- */
    function cel(linha, col) { return linha.querySelector('td[data-column="' + col + '"]'); }
    function val(linha, col) {
        var td = cel(linha, col);
        if (!td) return null;
        var bruto = td.querySelector('[data-raw-value]');
        if (bruto && bruto.getAttribute('data-raw-value') !== '') return String(bruto.getAttribute('data-raw-value')).trim();
        var campo = td.querySelector('select, input, textarea');
        if (campo) return String(campo.value || '').trim();
        return String(td.textContent || '').trim();
    }
    function preenchido(linha, cols) {
        for (var i = 0; i < cols.length; i++) {
            var v = val(linha, cols[i]);
            if (v !== null && v !== '' && v !== '0' && !/^0{2}\/0{2}\/0{4}$/.test(v) && v !== '-') return true;
        }
        for (var j = 0; j < cols.length; j++) if (cel(linha, cols[j])) return false;
        return null; // nenhuma das colunas est\u00e1 no grid: n\u00e3o sei
    }
    function chave(linha, col) {
        var k = String(linha.getAttribute('data-key') || '');
        if (/^\d+$/.test(k)) return k;
        var v = val(linha, col);
        return v && /^\d+$/.test(v) ? v : '';
    }
    function sem(v) { return String(v || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toUpperCase().trim(); }
    function n(v) { return typeof v === 'number' ? v : (v === null || v === undefined ? null : Number(v)); }

    var NAO_EXISTE = tr('campo_novo', 'Campo ainda n\u00e3o existe no cadastro (onda 3: novos campos)', 'Field not in the form yet', 'Campo a\u00fan no existe en el registro');
    var SEM_AUDITORIA = tr('sem_auditoria', 'O m\u00f3dulo de Auditoria ainda n\u00e3o existe', 'The Audit module does not exist yet', 'El m\u00f3dulo de Auditor\u00eda a\u00fan no existe');

    // Etapa de conjunto (funcionário): feita quando todos os critérios/treinamentos ativos têm o dado; sem nenhum, não se aplica.
    function FU(campo, total) {
        return function (l, f) {
            if (f[total] === null || f[total] === undefined || f[campo] === null || f[campo] === undefined) return null;
            if (!n(f[total])) return NA;
            return n(f[campo]) >= n(f[total]);
        };
    }

    /* ---------- workflows (planilha Revisão G) ---------- */
    var MODULOS = [
        { sel: '.modulo-legal-requisitos-fragment', modulo: 'legal', nome: tr('m.legal', 'Requisito legal', 'Legal requirement', 'Requisito legal'),
            params: function (l) { var leg = val(l, 'CODLEGISLACAO'), un = val(l, 'CODUNIDADE'); return leg && un ? { leg: leg, unid: un } : null; },
            etapas: [
                [tr('legal.1', 'Recebe a lei', 'Receives the law', 'Recibe la ley'), 0, function () { return true; }],
                [tr('legal.2', 'Valida a lei (mant\u00e9m no banco)', 'Validates the law', 'Valida la ley'), 0, function () { return true; }],
                [tr('legal.3', 'Define o respons\u00e1vel', 'Assigns the owner', 'Define el responsable'), 0, function (l, f) { return f.responsavel; }],
                [tr('legal.4', 'Analisa o padr\u00e3o de desempenho', 'Reviews the performance standard', 'Analiza el patr\u00f3n de desempe\u00f1o'), 0, function (l, f) { return f.checklist === null && f.avaliada === null ? null : (n(f.checklist) > 0 || !!f.avaliada); }],
                [tr('legal.5', 'Define o atendimento', 'Sets compliance', 'Define el cumplimiento'), 0, function (l, f) { return f.avaliada; }],
                [tr('legal.6', 'Define as condicionantes', 'Sets conditions', 'Define las condicionantes'), 1, function (l, f) { return f.condicionantes === null ? null : n(f.condicionantes) > 0; }],
                [tr('legal.7', 'Atendimento das condicionantes', 'Conditions compliance', 'Cumplimiento de condicionantes'), 0, function (l, f) { if (f.condicionantes === null) return null; if (!n(f.condicionantes)) return NA; return n(f.condicionantes_atendidas) >= n(f.condicionantes); }],
                [tr('legal.8', 'Abre plano de a\u00e7\u00e3o', 'Opens an action plan', 'Abre plan de acci\u00f3n'), 1, function (l, f) { if (!f.planos) return null; if (n(f.planos[0]) > 0) return true; return ['N', 'PA', 'AI'].indexOf(String(f.atendido || '')) >= 0 ? false : NA; }],
                [tr('legal.9', 'Encerra o plano de a\u00e7\u00e3o', 'Closes the action plan', 'Cierra el plan de acci\u00f3n'), 0, function (l, f) { if (!f.planos) return null; if (!n(f.planos[0])) return NA; return n(f.planos[1]) >= n(f.planos[0]); }],
                [tr('legal.10', 'Define a pr\u00f3xima avalia\u00e7\u00e3o', 'Sets the next review', 'Define la pr\u00f3xima evaluaci\u00f3n'), 0, function (l, f) { return f.proxima; }]
            ] },
        { sel: '.licencas-grid-fragment', modulo: 'licencas', nome: tr('m.licencas', 'Licen\u00e7a', 'License', 'Licencia'),
            params: function (l) { var id = chave(l, 'CODLICENCA'); return id ? { id: id } : null; },
            alternativa: function (l, f) {
                var protocolo = f.protocolo === true || sem(val(l, 'EMPROTOCOLO')) === 'SIM' || sem(val(l, 'EMPROTOCOLO')) === 'S';
                if (!protocolo) return null;
                return { titulo: tr('licencas.prot', 'Licen\u00e7a em protocolo', 'License under renewal', 'Licencia en protocolo'), etapas: [
                    [tr('licencas.p1', 'Abre o protocolo', 'Files the renewal', 'Abre el protocolo'), 0, function () { return true; }],
                    [tr('licencas.p2', 'Carrega a nova licen\u00e7a', 'Uploads the new license', 'Carga la nueva licencia'), 0, function () { return false; }],
                    [tr('licencas.p3', 'Encerra o protocolo', 'Closes the renewal', 'Cierra el protocolo'), 0, function () { return false; }],
                    [tr('licencas.p4', 'Inativa a licen\u00e7a antiga', 'Deactivates the old license', 'Inactiva la licencia antigua'), 0, function () { return false; }]
                ] };
            },
            etapas: [
                [tr('licencas.1', 'Cadastra a licen\u00e7a', 'Registers the license', 'Registra la licencia'), 0, function () { return true; }],
                [tr('licencas.2', 'Define o respons\u00e1vel', 'Assigns the owner', 'Define el responsable'), 0, function (l) { return preenchido(l, ['CODUSUARIO', 'RESPONSAVEL']); }],
                [tr('licencas.3', 'Define a validade', 'Sets the due date', 'Define la validez'), 0, function (l) { var s = sem(val(l, 'SITUACAO_LICENCA')); if (s === 'ENTREGA') return true; if (s === 'SEM_DATA') return false; return preenchido(l, ['DATA_VALIDADE']); }],
                [tr('licencas.4', 'Define o n\u00edvel de risco', 'Sets the risk level', 'Define el nivel de riesgo'), 1, function (l, f) { return f.risco === null || f.risco === undefined ? null : f.risco !== ''; }],
                [tr('licencas.5', 'Carrega a evid\u00eancia do atendimento', 'Uploads the evidence', 'Carga la evidencia'), 0, function (l, f) { return f.arquivos === null || f.arquivos === undefined ? null : n(f.arquivos) > 0; }]
            ] },
        { sel: '.medicao-monitoramento-grid-fragment', modulo: 'medicao', nome: tr('m.medicao', 'Medi\u00e7\u00e3o e monitoramento', 'Measurement', 'Medici\u00f3n'),
            params: function (l) { var id = chave(l, 'CODMONITORAMENTO'); return id ? { id: id } : null; },
            etapas: [
                [tr('medicao.1', 'Cadastra a medi\u00e7\u00e3o', 'Registers the measurement', 'Registra la medici\u00f3n'), 0, function () { return true; }],
                [tr('medicao.2', 'Cadastra o equipamento', 'Registers the equipment', 'Registra el equipo'), 1, function (l, f) { return f.equipamento === null || f.equipamento === undefined ? preenchido(l, ['CODEQUIPAMENTO']) : f.equipamento; }],
                [tr('medicao.3', 'Define o respons\u00e1vel', 'Assigns the owner', 'Define el responsable'), 0, function (l) { return preenchido(l, ['CODUSUARIO']); }],
                [tr('medicao.4', 'Define o cronograma', 'Sets the schedule', 'Define el cronograma'), 0, function (l, f) { return f.cronograma === true || preenchido(l, ['DATA_MEDICAO']) === true ? true : (f.cronograma === null || f.cronograma === undefined ? null : false); }],
                [tr('medicao.5', 'Define o n\u00edvel de risco', 'Sets the risk level', 'Define el nivel de riesgo'), 1, function (l, f) { return f.risco === null || f.risco === undefined ? null : f.risco !== ''; }],
                [tr('medicao.6', 'Carrega a evid\u00eancia do atendimento', 'Uploads the evidence', 'Carga la evidencia'), 0, function () { return NA; }, NAO_EXISTE]
            ] },
        { sel: '.controle-documentos-grid-fragment', modulo: 'controles', nome: tr('m.controles', 'Controle', 'Control', 'Control'),
            params: function (l) { var id = chave(l, 'CODDOCUMENTO'); return id ? { id: id } : null; },
            etapas: [
                [tr('controles.1', 'Cadastra o controle', 'Registers the control', 'Registra el control'), 0, function () { return true; }],
                [tr('controles.2', 'Define o respons\u00e1vel', 'Assigns the owner', 'Define el responsable'), 0, function (l, f) { return f && typeof f.responsaveis === 'number' ? f.responsaveis > 0 : preenchido(l, ['RESPONSAVEIS', 'CODUSUARIO']); }],
                [tr('controles.3', 'Define a validade', 'Sets the due date', 'Define la validez'), 1, function (l) { return preenchido(l, ['DATA_VENCIMENTO']); }],
                [tr('controles.4', 'Define o prazo de guarda', 'Sets the retention period', 'Define el plazo de guarda'), 1, function (l) { return preenchido(l, ['PRAZO_GUARDA']); }],
                [tr('controles.5', 'Define o n\u00edvel de risco', 'Sets the risk level', 'Define el nivel de riesgo'), 1, function (l, f) { return f.risco === null || f.risco === undefined ? null : f.risco !== ''; }],
                [tr('controles.6', 'Carrega a evid\u00eancia do atendimento', 'Uploads the evidence', 'Carga la evidencia'), 0, function (l, f) { return f.arquivos === null || f.arquivos === undefined ? null : n(f.arquivos) > 0; }]
            ] },
        { sel: '.modulo-legal-treinamentos-grid-fragment', modulo: 'treinamentos', nome: tr('m.treinamentos', 'Treinamento', 'Training', 'Capacitaci\u00f3n'),
            params: function (l) { var id = chave(l, 'CODTREINAMENTO'); return id ? { id: id } : null; },
            etapas: [
                [tr('treinamentos.1', 'Cadastra o treinamento', 'Registers the training', 'Registra la capacitaci\u00f3n'), 0, function () { return true; }],
                [tr('treinamentos.2', 'Define o respons\u00e1vel', 'Assigns the owner', 'Define el responsable'), 0, function (l, f) { return f && typeof f.responsaveis === 'number' ? f.responsaveis > 0 : preenchido(l, ['CODUSUARIO', 'RESPONSAVEIS']); }],
                [tr('treinamentos.3', 'Define a validade', 'Sets the due date', 'Define la validez'), 1, function (l) { return preenchido(l, ['PERIODO_RECICLAGEM']); }],
                [tr('treinamentos.4', 'Define o n\u00edvel de risco', 'Sets the risk level', 'Define el nivel de riesgo'), 1, function (l, f) { return f.risco === null || f.risco === undefined ? null : f.risco !== ''; }],
                [tr('treinamentos.5', 'Carrega a evid\u00eancia (treinados)', 'Uploads the evidence (trainees)', 'Carga la evidencia (capacitados)'), 0, function (l, f) { if (preenchido(l, ['TREINADOS']) === true) return true; return f.arquivos === null || f.arquivos === undefined ? null : n(f.arquivos) > 0; }]
            ] },
        { sel: '.modulo-legal-infraestrutura-grid-fragment', modulo: 'infraestrutura', nome: tr('m.infraestrutura', 'Infraestrutura', 'Infrastructure', 'Infraestructura'),
            params: function (l) { var id = chave(l, 'CODINFRAESTRUTURA'); return id ? { id: id } : null; },
            etapas: [
                [tr('infra.1', 'Cadastra a infraestrutura', 'Registers the item', 'Registra la infraestructura'), 0, function () { return true; }],
                [tr('infra.2', 'Define o respons\u00e1vel', 'Assigns the owner', 'Define el responsable'), 0, function (l, f) { return f && typeof f.responsaveis === 'number' ? f.responsaveis > 0 : preenchido(l, ['CODUSUARIO', 'RESPONSAVEIS']); }],
                [tr('infra.3', 'Carrega o plano de manuten\u00e7\u00e3o', 'Uploads the maintenance plan', 'Carga el plan de mantenimiento'), 1, function (l) { return preenchido(l, ['MANUTENCOES']); }],
                [tr('infra.4', 'Define o n\u00edvel de risco', 'Sets the risk level', 'Define el nivel de riesgo'), 1, function (l, f) { return f.risco === null || f.risco === undefined ? null : f.risco !== ''; }],
                [tr('infra.5', 'Carrega a evid\u00eancia do atendimento', 'Uploads the evidence', 'Carga la evidencia'), 0, function (l, f) { return f.arquivos === null || f.arquivos === undefined ? null : n(f.arquivos) > 0; }]
            ] },
        { sel: '.homologacao-fornecedor-grid-fragment', modulo: 'fornecedor', nome: tr('m.fornecedor', 'Homologa\u00e7\u00e3o da empresa', 'Supplier approval', 'Homologaci\u00f3n de la empresa'),
            params: function (l) { var id = chave(l, 'CODFORNECEDOR'); return id ? { id: id } : null; },
            etapas: [
                [tr('fo.1', 'Cadastra a empresa', 'Registers the company', 'Registra la empresa'), 0, function () { return true; }],
                [tr('fo.2', 'Define o respons\u00e1vel', 'Assigns the owner', 'Define el responsable'), 0, function (l, f) { return f.responsavel === null || f.responsavel === undefined ? preenchido(l, ['CODUSUARIO_RESPONSAVEL_FORNECEDOR']) : f.responsavel; }],
                [tr('fo.3', 'Define o n\u00edvel de risco', 'Sets the risk level', 'Define el nivel de riesgo'), 1, function (l, f) { return f.risco === null || f.risco === undefined ? null : f.risco !== ''; }],
                [tr('fo.4', 'Define os crit\u00e9rios (tipo de empresa)', 'Sets criteria (company type)', 'Define los criterios (tipo de empresa)'), 0, function (l, f) { return f.tipos === null || f.tipos === undefined ? null : n(f.tipos) > 0; }],
                [tr('fo.5', 'Define a validade dos documentos', 'Sets document due dates', 'Define la validez de los documentos'), 0, function (l, f) { if (f.criterios === null || f.criterios === undefined) return null; return n(f.criterios) > 0 && n(f.com_validade) >= n(f.criterios); }],
                [tr('fo.6', 'Carrega as evid\u00eancias', 'Uploads the evidence', 'Carga las evidencias'), 0, function (l, f) { if (f.criterios === null || f.criterios === undefined) return null; return n(f.criterios) > 0 && n(f.enviados) >= n(f.criterios); }],
                [tr('fo.7', 'Aprova os documentos', 'Approves the documents', 'Aprueba los documentos'), 0, function (l, f) { if (f.criterios === null || f.criterios === undefined) return null; return n(f.criterios) > 0 && n(f.aprovados) >= n(f.criterios); }],
                [tr('fo.8', 'Define a necessidade de auditoria', 'Decides on an audit', 'Define la necesidad de auditor\u00eda'), 1, function () { return NA; }, SEM_AUDITORIA],
                [tr('fo.9', 'Realiza a auditoria', 'Performs the audit', 'Realiza la auditor\u00eda'), 1, function () { return NA; }, SEM_AUDITORIA],
                [tr('fo.10', 'Carrega as evid\u00eancias da auditoria', 'Uploads audit evidence', 'Carga las evidencias de la auditor\u00eda'), 1, function () { return NA; }, SEM_AUDITORIA],
                [tr('fo.11', 'Define a pr\u00f3xima avalia\u00e7\u00e3o', 'Sets the next evaluation', 'Define la pr\u00f3xima evaluaci\u00f3n'), 0, function (l, f) { return f.proxima === null || f.proxima === undefined ? preenchido(l, ['DATA_DA_PROXIMA_AVALIACAO']) : f.proxima; }],
                [tr('fo.12', 'Abre plano de a\u00e7\u00e3o', 'Opens an action plan', 'Abre plan de acci\u00f3n'), 1, function (l, f) { return f.planos ? n(f.planos[0]) > 0 : null; }],
                [tr('fo.13', 'Encerra o plano de a\u00e7\u00e3o', 'Closes the action plan', 'Cierra el plan de acci\u00f3n'), 0, function (l, f) { if (!f.planos) return null; if (!n(f.planos[0])) return NA; return n(f.planos[1]) >= n(f.planos[0]); }]
            ] },
        { sel: '.homologacao-funcionario-grid-fragment', modulo: 'funcionario', nome: tr('m.funcionario', 'Homologa\u00e7\u00e3o do funcion\u00e1rio', 'Contractor employee approval', 'Homologaci\u00f3n del empleado'),
            params: function (l) { var id = chave(l, 'CODFUNCIONARIO'); return id ? { id: id } : null; },
            etapas: [
                [tr('fu.1', 'Cadastra o funcion\u00e1rio', 'Registers the employee', 'Registra el empleado'), 0, function () { return true; }],
                [tr('fu.2', 'Define os crit\u00e9rios (tipo de fun\u00e7\u00e3o)', 'Sets criteria (job type)', 'Define los criterios (tipo de funci\u00f3n)'), 0, function (l, f) { return f.tipo === null || f.tipo === undefined ? preenchido(l, ['CODTIPOFUNCIONARIO']) : f.tipo; }],
                [tr('fu.3', 'Define a data da integra\u00e7\u00e3o', 'Sets the induction date', 'Define la fecha de integraci\u00f3n'), 0, function (l, f) { return f.integracao === null || f.integracao === undefined ? preenchido(l, ['DATA_VALIDADE']) : f.integracao; }],
                [tr('fu.4', 'Define o respons\u00e1vel pela documenta\u00e7\u00e3o', 'Assigns the documentation owner', 'Define el responsable de la documentaci\u00f3n'), 0, FU('cri_responsavel', 'criterios')],
                [tr('fu.5', 'Define a validade dos documentos', 'Sets document due dates', 'Define la validez de los documentos'), 0, FU('cri_validade', 'criterios')],
                [tr('fu.6', 'Carrega as evid\u00eancias dos documentos', 'Uploads document evidence', 'Carga las evidencias de los documentos'), 0, FU('cri_evidencias', 'criterios')],
                [tr('fu.7', 'Aprova os documentos', 'Approves the documents', 'Aprueba los documentos'), 0, FU('cri_aprovados', 'criterios')],
                [tr('fu.8', 'Define o respons\u00e1vel pelo treinamento', 'Assigns the training owner', 'Define el responsable de la capacitaci\u00f3n'), 0, FU('tre_responsavel', 'treinamentos')],
                [tr('fu.9', 'Informa se o treinamento foi realizado', 'Records whether training was done', 'Informa si la capacitaci\u00f3n fue realizada'), 0, FU('tre_realizado', 'treinamentos')],
                [tr('fu.10', 'Define a validade dos treinamentos', 'Sets training due dates', 'Define la validez de las capacitaciones'), 0, FU('tre_validade', 'treinamentos')],
                [tr('fu.11', 'Carrega as evid\u00eancias dos treinamentos', 'Uploads training evidence', 'Carga las evidencias de las capacitaciones'), 0, FU('tre_evidencias', 'treinamentos')]
            ] },
        { sel: '.nao-conformidades-grid-fragment', modulo: 'rnc', nome: tr('m.rnc', 'Ocorr\u00eancia (RNC)', 'Nonconformity', 'No conformidad'),
            params: function (l) { var id = chave(l, 'CODOCORRENCIA'); return id ? { id: id } : null; },
            // A etapa do workflow da NC manda: o que ficou para tras conta como passado mesmo sem o campo preenchido (registros antigos).
            posicao: function (l, f, estados) {
                var e = sem(f.etapa || val(l, 'ETAPA'));
                if (!e) return -1;
                if (e === 'FINALIZADO') return estados.length;
                if (e.indexOf('EM CRIA') === 0) return 0;
                if (e.indexOf('COM O GESTOR') === 0 || e.indexOf('RECUSADO PELO GESTOR') === 0) { for (var i = 1; i <= 5; i++) if (estados[i] !== true) return i; return 5; }
                if (e.indexOf('EM ANALISE') === 0 || e.indexOf('RECUSADO PELA AVALIA') === 0) return 6;
                if (e.indexOf('PLANO DE A') === 0) return f.planos && n(f.planos[0]) > 0 ? 8 : 7;
                if (e.indexOf('AVALIACAO DA EFIC') === 0) return 9;
                return -1;
            },
            etapas: [
                [tr('rnc.1', 'Cadastra a ocorr\u00eancia', 'Registers the occurrence', 'Registra la ocurrencia'), 0, function () { return true; }],
                [tr('rnc.2', 'Define o gestor', 'Assigns the manager', 'Define el gestor'), 0, function (l, f) { return f.gestor; }],
                [tr('rnc.3', 'Classifica a ocorr\u00eancia', 'Classifies it', 'Clasifica la ocurrencia'), 0, function (l, f) { return f.classificada; }],
                [tr('rnc.4', 'Define a a\u00e7\u00e3o imediata', 'Sets the immediate action', 'Define la acci\u00f3n inmediata'), 0, function (l, f) { return f.acao_imediata; }],
                [tr('rnc.5', 'Avalia a abrang\u00eancia', 'Assesses the scope', 'Eval\u00faa el alcance'), 0, function (l, f) { return f.abrangencia; }],
                [tr('rnc.6', 'Define o respons\u00e1vel pela an\u00e1lise', 'Assigns the analyst', 'Define el responsable del an\u00e1lisis'), 0, function (l, f) { return f.responsavel; }],
                [tr('rnc.7', 'Analisa a causa raiz', 'Root cause analysis', 'Analiza la causa ra\u00edz'), 0, function (l, f) { return f.causa; }],
                [tr('rnc.8', 'Define o plano de a\u00e7\u00e3o corretiva', 'Sets the corrective plan', 'Define el plan correctivo'), 0, function (l, f) { return f.planos ? n(f.planos[0]) > 0 : null; }],
                [tr('rnc.9', 'Finaliza o plano de a\u00e7\u00e3o', 'Completes the action plan', 'Finaliza el plan de acci\u00f3n'), 0, function (l, f) { return f.planos ? n(f.planos[0]) > 0 && n(f.planos[1]) >= n(f.planos[0]) : null; }],
                [tr('rnc.10', 'Avalia a efic\u00e1cia', 'Checks effectiveness', 'Eval\u00faa la eficacia'), 0, function (l, f) { return f.eficacia; }],
                [tr('rnc.11', 'Encerra a RNC', 'Closes the NC', 'Cierra la RNC'), 0, function (l, f) { if (f.etapa === null || f.etapa === undefined) return null; return sem(f.etapa) === 'FINALIZADO' || sem(f.situacao).indexOf('ENCERRAD') === 0; }]
            ] }
    ];

    /* ---------- dados do servidor (cache de 60 s por registro) ---------- */
    function fatos(win, mod, p, pronto) {
        var cache = win.__gpFluxoCache || (win.__gpFluxoCache = {});
        var qs = 'modulo=' + mod.modulo + Object.keys(p).map(function (k) { return '&' + k + '=' + encodeURIComponent(p[k]); }).join('');
        var c = cache[qs];
        if (c && c.dados && Date.now() - c.quando < 60000) return c.dados;
        if (c && c.pedindo) return null;
        cache[qs] = { pedindo: true, quando: Date.now() };
        win.fetch(BASE + '/principal/api/fluxo_etapas.php?' + qs, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) { cache[qs] = { pedindo: false, quando: Date.now(), dados: j && j.ok ? j.fatos || {} : {} }; pronto(); })
            .catch(function () { cache[qs] = { pedindo: false, quando: Date.now(), dados: {} }; pronto(); });
        return null;
    }

    /* ---------- estilo ---------- */
    var CSS = [
        '.gp-fluxo{border:1px solid #dce9e3;border-radius:8px;background:#fbfdfc;margin:6px 0 8px;padding:6px 10px 8px;font-size:12px}',
        '.gp-fluxo-topo{display:flex;align-items:center;gap:8px;min-height:20px}',
        '.gp-fluxo-titulo{font-weight:600;color:#2f4a3f;white-space:nowrap}',
        '.gp-fluxo-sub{color:#6b7a73;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1}',
        '.gp-fluxo-btn{border:0;background:transparent;color:#5b6770;padding:0 4px;line-height:1;font-size:13px;cursor:pointer}',
        '.gp-fluxo.recolhida .gp-fluxo-trilha{display:none}',
        '.gp-fluxo-trilha{display:flex;align-items:flex-start;overflow-x:auto;overflow-y:hidden;padding:8px 0 2px}',
        '.gp-et{flex:1 1 0;min-width:84px;position:relative;text-align:center;cursor:help}',
        '.gp-et:before{content:"";position:absolute;top:10px;left:-50%;right:50%;height:3px;background:#dfe5e2;z-index:0}',
        '.gp-et:first-child:before{display:none}',
        '.gp-et.feito:before,.gp-et.atual:before{background:#2e9d5b}',
        '.gp-et-bola{position:relative;z-index:1;margin:0 auto;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;background:#fff;border:2px solid #c3ccc8;color:#7b8781}',
        '.gp-et.feito .gp-et-bola{background:#2e9d5b;border-color:#2e9d5b;color:#fff}',
        '.gp-et.atual .gp-et-bola{background:#3b7dd8;border-color:#3b7dd8;color:#fff;box-shadow:0 0 0 4px rgba(59,125,216,.18)}',
        '.gp-et.opcional:not(.feito) .gp-et-bola{border-style:dashed}',
        '.gp-et.passou .gp-et-bola{background:#8fcaa6;border-color:#8fcaa6}',
        '.gp-et.na .gp-et-bola{background:#f1f3f2;border-color:#dfe3e1;color:#b3bbb7}',
        '.gp-et-rotulo{margin-top:4px;line-height:1.2;color:#56645d;padding:0 3px;font-size:11px}',
        '.gp-et.atual .gp-et-rotulo{color:#1f5fb4;font-weight:600}',
        '.gp-et.na .gp-et-rotulo{color:#a8b1ad}',
        '.gp-et-opc{display:block;font-size:10px;color:#93a09a;font-style:italic}',
        '.gp-fluxo.neutra .gp-et .gp-et-bola{background:#fff;border-color:#c3ccc8;color:#7b8781;box-shadow:none}',
        '.gp-fluxo.neutra .gp-et:before{background:#dfe5e2}',
        '.gp-fluxo.neutra .gp-et-rotulo{color:#56645d;font-weight:400}'
    ].join('\n');
    var CHECK = '<svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true"><path d="M6.3 11 3.4 8.1l1.1-1.1 1.8 1.8 4.6-4.6 1.1 1.1z" fill="#fff"/></svg>';

    function esc(s) { return String(s === null || s === undefined ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function lembrar(chaveLs, valor) { try { if (valor === undefined) return topo.localStorage.getItem(chaveLs); topo.localStorage.setItem(chaveLs, valor); } catch (e) { /* sem armazenamento */ } return null; }

    function painel(frag, mod, win) {
        var p = frag.querySelector('.gp-fluxo');
        if (p) return p;
        var grid = frag.querySelector('.menu-batch-grid');
        if (!grid) return null;
        var alvo = grid.closest('.menu-grid-scroll') || grid;
        p = win.document.createElement('div');
        p.className = 'gp-fluxo neutra';
        p.setAttribute('data-gp-fluxo', mod.modulo);
        var ls = 'gpFluxoRecolhida:' + mod.modulo;
        if (lembrar(ls) === '1') p.classList.add('recolhida');
        p.innerHTML = '<div class="gp-fluxo-topo"><span class="gp-fluxo-titulo"></span><span class="gp-fluxo-sub"></span>' +
            '<button type="button" class="gp-fluxo-btn" data-gp-fluxo-toggle></button></div><div class="gp-fluxo-trilha" role="list"></div>';
        var btn = p.querySelector('[data-gp-fluxo-toggle]');
        var rotular = function () {
            var r = p.classList.contains('recolhida');
            btn.innerHTML = r ? '&#9662;' : '&#9652;';
            btn.title = r ? tr('mostrar', 'Mostrar as etapas', 'Show stages', 'Mostrar etapas') : tr('recolher', 'Recolher as etapas', 'Hide stages', 'Ocultar etapas');
            btn.setAttribute('aria-label', btn.title);
        };
        btn.addEventListener('click', function (ev) { ev.preventDefault(); ev.stopPropagation(); p.classList.toggle('recolhida'); lembrar(ls, p.classList.contains('recolhida') ? '1' : '0'); rotular(); });
        rotular();
        alvo.parentNode.insertBefore(p, alvo);
        return p;
    }

    function desenhar(p, titulo, sub, etapas, estados, posicao) {
        p.querySelector('.gp-fluxo-titulo').textContent = titulo;
        var s = p.querySelector('.gp-fluxo-sub');
        s.textContent = sub || '';
        s.title = sub || '';
        var neutra = !estados;
        p.classList.toggle('neutra', neutra);
        var atual = -1;
        if (estados && typeof posicao === 'number' && posicao >= 0) {
            atual = posicao < etapas.length ? posicao : -1;
            for (var k = 0; k < Math.min(posicao, etapas.length); k++) if (estados[k] !== true && estados[k] !== NA) estados[k] = PASSOU;
        } else if (estados) {
            var fim = estados[estados.length - 1] === true;
            if (!fim) for (var i = 0; i < etapas.length; i++) if (!etapas[i][1] && estados[i] !== true && estados[i] !== NA) { atual = i; break; }
        }
        var html = etapas.map(function (et, i) {
            var st = estados ? estados[i] : null;
            var cls = 'gp-et' + (et[1] ? ' opcional' : '');
            var dica = et[0];
            if (!neutra) {
                if (st === true) { cls += ' feito'; dica += ' \u2014 ' + tr('st.feito', 'feito', 'done', 'hecho'); }
                else if (st === PASSOU) { cls += ' feito passou'; dica += ' \u2014 ' + tr('st.passou', 'etapa j\u00e1 passou (campo sem registro)', 'stage passed (no record)', 'etapa ya pas\u00f3 (sin registro)'); }
                else if (st === NA) { cls += ' na'; dica += ' \u2014 ' + (et[3] || tr('st.na', 'n\u00e3o se aplica', 'not applicable', 'no aplica')); }
                else if (i === atual) { cls += ' atual'; dica += ' \u2014 ' + tr('st.atual', 'etapa atual', 'current stage', 'etapa actual'); }
                else if (st === null || st === undefined) dica += ' \u2014 ' + tr('st.sei', 'sem informa\u00e7\u00e3o', 'no information', 'sin informaci\u00f3n');
                else dica += ' \u2014 ' + tr('st.pend', 'pendente', 'pending', 'pendiente');
            } else if (et[3]) dica += ' \u2014 ' + et[3];
            if (et[1]) dica += ' (' + tr('opc', 'facultativa', 'optional', 'opcional') + ')';
            var bola = !neutra && (st === true || st === PASSOU) ? CHECK : (!neutra && st === NA ? '&#8211;' : String(i + 1));
            return '<div class="' + cls + '" role="listitem" title="' + esc(dica) + '"><div class="gp-et-bola">' + bola + '</div><div class="gp-et-rotulo">' + esc(et[0]) +
                (et[1] ? '<span class="gp-et-opc">' + esc(tr('opc', 'facultativa', 'optional', 'opcional')) + '</span>' : '') + '</div></div>';
        }).join('');
        var trilha = p.querySelector('.gp-fluxo-trilha');
        if (trilha.__html !== html) { trilha.innerHTML = html; trilha.__html = html; }
    }

    function descricao(linha) {
        var cols = ['DSCLICENCA', 'CODLICENCA', 'PADRAO', 'CODIGO', 'TITULO', 'DSCTREINAMENTO', 'DSCINFRAESTRUTURA', 'PARAMETROS', 'RAZAOSOCIAL', 'NRANO', 'DESCRICAO'];
        var partes = [];
        for (var i = 0; i < cols.length && partes.length < 2; i++) {
            var td = cel(linha, cols[i]);
            if (!td) continue;
            var campo = td.querySelector('input, textarea, select');
            var v = campo ? (campo.tagName === 'SELECT' ? (campo.options[campo.selectedIndex] || {}).text : campo.value) : td.textContent;
            v = String(v || '').replace(/\s+/g, ' ').trim();
            if (v && !/^\d+$/.test(v)) partes.push(v.length > 90 ? v.slice(0, 90) + '\u2026' : v);
        }
        return partes.join(' \u2014 ');
    }

    function aplicar(win) {
        MODULOS.forEach(function (mod) {
            Array.prototype.forEach.call(win.document.querySelectorAll(mod.sel), function (frag) {
                if (frag.closest('.gp-fluxo')) return;
                var p = painel(frag, mod, win);
                if (!p) return;
                var linha = frag.querySelector('.menu-batch-grid tbody tr.selected');
                var titulo = tr('titulo', 'Etapas do processo', 'Process stages', 'Etapas del proceso') + ' \u00b7 ' + mod.nome;
                if (!linha) { desenhar(p, titulo, tr('dica', 'Selecione uma linha para ver em que etapa ela est\u00e1.', 'Select a row to see its stage.', 'Seleccione una fila para ver su etapa.'), mod.etapas, null); return; }
                var params = mod.params(linha);
                if (!params) { desenhar(p, titulo, tr('nova', 'Registro ainda n\u00e3o gravado.', 'Record not saved yet.', 'Registro a\u00fan no guardado.'), mod.etapas, null); return; }
                var f = fatos(win, mod, params, function () { agendar(win, 10); });
                var desc = descricao(linha);
                if (!f) { desenhar(p, titulo, desc + ' \u00b7 ' + tr('carregando', 'carregando\u2026', 'loading\u2026', 'cargando\u2026'), mod.etapas, null); return; }
                var etapas = mod.etapas;
                var alt = mod.alternativa ? mod.alternativa(linha, f) : null;
                if (alt) { etapas = alt.etapas; titulo = tr('titulo', 'Etapas do processo', 'Process stages', 'Etapas del proceso') + ' \u00b7 ' + alt.titulo; }
                var estados = etapas.map(function (et) { try { var r = et[2](linha, f); return r === undefined ? null : r; } catch (e) { return null; } });
                var pos = !alt && mod.posicao ? mod.posicao(linha, f, estados) : -1;
                if (pos >= 0) estados = estados.map(function (s, i) { return i < pos && s !== true && s !== NA ? PASSOU : s; });
                var feitas = estados.filter(function (s, i) { return (s === true || s === PASSOU) && !etapas[i][1]; }).length;
                var obrig = etapas.filter(function (et, i) { return !et[1] && estados[i] !== NA; }).length;
                var resumo = estados[estados.length - 1] === true || pos >= etapas.length || feitas >= obrig ? tr('completo', 'processo conclu\u00eddo', 'process complete', 'proceso concluido') : feitas + '/' + obrig + ' ' + tr('obrig', 'etapas obrigat\u00f3rias feitas', 'required stages done', 'etapas obligatorias hechas');
                desenhar(p, titulo, (desc ? desc + ' \u00b7 ' : '') + resumo, etapas, estados, pos);
            });
        });
    }

    function agendar(win, espera) {
        win.clearTimeout(win.__gpFluxoTimer);
        win.__gpFluxoTimer = win.setTimeout(function () { try { aplicar(win); } catch (e) { /* n\u00e3o interrompe a tela */ } }, espera || 150);
    }

    function instalar(win) {
        var doc;
        try { doc = win.document; } catch (e) { return; }
        if (!doc || doc.__gpFluxoEtapas) return;
        doc.__gpFluxoEtapas = true;
        var iniciar = function () {
            if (!doc.head || !doc.body) return;
            if (!doc.getElementById('gp-fluxo-etapas-css')) {
                var st = doc.createElement('style');
                st.id = 'gp-fluxo-etapas-css';
                st.textContent = CSS;
                doc.head.appendChild(st);
            }
            agendar(win, 60);
            win.setTimeout(function () { agendar(win, 10); }, 900);
            // Seleção de linha, troca de página, recarga e edições.
            doc.addEventListener('click', function (ev) { if (ev.target && ev.target.closest && !ev.target.closest('.gp-fluxo')) agendar(win, 60); }, true);
            doc.addEventListener('change', function () { agendar(win, 200); }, true);
            doc.addEventListener('keyup', function (ev) { if (ev.key === 'ArrowUp' || ev.key === 'ArrowDown') agendar(win, 120); }, true);
            if (win.MutationObserver) new win.MutationObserver(function (lista) {
                for (var i = 0; i < lista.length; i++) {
                    var alvo = lista[i].target;
                    if (alvo && alvo.closest && (alvo.closest('.gp-fluxo') || alvo.closest('.gp-st') || alvo.closest('.gp-st-legenda'))) continue;
                    if (alvo && alvo.nodeName === 'TBODY') { agendar(win, 150); return; }
                }
            }).observe(doc.body, { childList: true, subtree: true });
        };
        if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', iniciar); else iniciar();
        doc.addEventListener('load', function (ev) {
            var f = ev.target;
            if (f && f.tagName === 'IFRAME') { try { instalar(f.contentWindow); } catch (e) { /* outro endere\u00e7o */ } }
        }, true);
        var abertos = function () { Array.prototype.forEach.call(doc.querySelectorAll('iframe'), function (f) { try { instalar(f.contentWindow); } catch (e) { /* idem */ } }); };
        if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', abertos); else abertos();
    }
    instalar(window);
}());
