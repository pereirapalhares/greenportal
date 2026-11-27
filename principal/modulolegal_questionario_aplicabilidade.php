<?php
declare(strict_types=1);

/*
 * Módulo Legal › Questionário de aplicabilidade legal (Revisão G itens 20, 23 e 43; migração 421, 26/11/2026).
 *
 * Por unidade:
 *   1. Âmbitos que valem para a unidade (Federal, o Estado e o Município dela...): ml_aplicab_ambito.
 *   2. Perguntas "A unidade tem, realiza ou está sujeita a: <palavra-chave>?", agrupadas pelo tipo da palavra-chave
 *      (Tipos × Palavras-chave), só palavras usadas por alguma lei: ml_aplicab_resposta (Sim/Não). A IA pode sugerir as respostas
 *      a partir de uma descrição da unidade (o usuário confere e grava).
 *   3. Leis sugeridas: as leis com palavras respondidas "Sim", nos âmbitos da unidade, que ainda não são requisitos da unidade.
 *      O usuário inclui (vira requisito: legislacaoxempresa, como a atribuição manual) ou recusa (não volta a ser sugerida):
 *      ml_aplicab_lei. A atribuição manual de leis (Manutenção Legal › Legislação × Empresas) continua igual.
 * Permissão: menu QUESTIONARIO_APLICABILIDADE_LEGAL (visualizar; editar = responder; incluir = incluir leis nos requisitos).
 * Leitura por sp_crud_select; gravação por sp_crud_insert / sp_crud_update / sp_crud_delete.
 */
require_once dirname(__DIR__) . '/classes/PaginaSimples.php';
require_once dirname(__DIR__) . '/classes/LegislacaoEmpresas.php';
require_once dirname(__DIR__) . '/classes/InteligenciaArtificialDocumento.php';
use PaginaSimples as P;

$ajax = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' || isset($_GET['acao']);
P::abrir('QUESTIONARIO_APLICABILIDADE_LEGAL', $ajax);
if (!P::existe('ml_aplicab_resposta')) P::sair(503, P::L('O questionário ainda não foi instalado nesta empresa (migração 421).', 'The questionnaire is not installed yet (migration 421).', 'El cuestionario aún no fue instalado (migración 421).'), $ajax);
$empresa = (int)($_SESSION['tenant']['codempresa'] ?? 0);
$csrf = P::csrf('aplicab_csrf');
$json = static function (array $d, int $c = 200): never { http_response_code($c); header('Content-Type: application/json; charset=utf-8'); exit(json_encode($d, JSON_UNESCAPED_UNICODE)); };

// unidades: as usadas na manutenção legal; sem marcação, todas
$unidades = [];
foreach (P::sel('unidades', [], 5000) as $u) {
    $marc = mb_strtoupper(trim((string)($u['USADO_EM_MANUTENCAO_LEGAL'] ?? '')));
    $unidades[(int)$u['CODUNIDADE']] = ['nome' => (string)($u['DSCUNIDADE'] ?? $u['CODUNIDADE']), 'legal' => $marc === 'SIM'];
}
if (array_filter($unidades, static fn($u) => $u['legal'])) $unidades = array_filter($unidades, static fn($u) => $u['legal']);
uasort($unidades, static fn($a, $b) => strnatcasecmp($a['nome'], $b['nome']));
$unidade = (int)($_POST['unidade'] ?? $_GET['unidade'] ?? 0);
if (!isset($unidades[$unidade])) $unidade = (int)(array_key_first($unidades) ?? 0);

$ambitosUnidade = static fn(int $un): array => array_map(static fn($r) => (int)$r['CODAMBITO'], P::sel('vw_ml_aplicab_ambito', ['CODUNIDADE' => $un]));
$respostas = static function (int $un): array { $o = []; foreach (P::sel('vw_ml_aplicab_resposta', ['CODUNIDADE' => $un], 20000) as $r) $o[(int)$r['CODPALAVRACHAVE']] = (string)$r['RESPOSTA']; return $o; };
$palavras = static function (): array {
    $o = [];
    foreach (P::sel('vw_aplicab_palavras', [], 20000) as $p) if ((int)$p['QTD_LEIS'] > 0) $o[(int)$p['CODPALAVRACHAVE']] = ['palavra' => trim((string)$p['PALAVRA']), 'tipos' => trim((string)($p['TIPOS'] ?? '')), 'leis' => (int)$p['QTD_LEIS']];
    uasort($o, static fn($a, $b) => strnatcasecmp($a['palavra'], $b['palavra']));
    return $o;
};

// ---------------------------------------------------------------- ações (JSON)
if ($ajax) {
    $acao = (string)($_POST['acao'] ?? $_GET['acao'] ?? '');
    if ($unidade <= 0) $json(['ok' => false, 'message' => P::L('Escolha a unidade.', 'Choose the unit.', 'Elija la unidad.')], 422);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') P::conferirCsrf('aplicab_csrf', true);
    try {
        if ($acao === 'salvar') {
            if (!P::pode('editar') && !P::pode('incluir')) $json(['ok' => false, 'message' => P::L('Seu perfil só consulta o questionário.', 'Your profile can only view the questionnaire.', 'Su perfil solo consulta el cuestionario.')], 403);
            $amb = array_values(array_unique(array_filter(array_map('intval', (array)json_decode((string)($_POST['ambitos'] ?? '[]'), true)))));
            $atuais = $ambitosUnidade($unidade);
            foreach (array_diff($atuais, $amb) as $a) foreach (P::sel('ml_aplicab_ambito', ['CODUNIDADE' => $unidade, 'CODAMBITO' => $a]) as $r) P::del('ml_aplicab_ambito', ['CODAPLICABAMBITO' => (int)$r['CODAPLICABAMBITO']]);
            foreach (array_diff($amb, $atuais) as $a) P::ins('ml_aplicab_ambito', ['CODUNIDADE' => $unidade, 'CODAMBITO' => $a, 'CODUSUARIO' => P::$uid ?: null]);
            $existentes = [];
            foreach (P::sel('ml_aplicab_resposta', ['CODUNIDADE' => $unidade], 20000) as $r) $existentes[(int)$r['CODPALAVRACHAVE']] = $r;
            $mud = 0;
            foreach ((array)json_decode((string)($_POST['respostas'] ?? '{}'), true) as $cod => $resp) {
                $cod = (int)$cod; $resp = strtoupper((string)$resp); $origem = str_ends_with($resp, '*') ? 'IA' : 'USUARIO'; $resp = rtrim($resp, '*');
                if ($cod <= 0) continue;
                $atual = $existentes[$cod] ?? null;
                if (!in_array($resp, ['S', 'N'], true)) { if ($atual) { P::del('ml_aplicab_resposta', ['CODAPLICABRESPOSTA' => (int)$atual['CODAPLICABRESPOSTA']]); $mud++; } continue; }
                if ($atual && (string)$atual['RESPOSTA'] === $resp) continue;
                $dados = ['RESPOSTA' => $resp, 'ORIGEM' => $origem, 'CODUSUARIO' => P::$uid ?: null, 'RESPONDIDO_EM' => date('Y-m-d H:i:s')];
                if ($atual) P::upd('ml_aplicab_resposta', ['CODAPLICABRESPOSTA' => (int)$atual['CODAPLICABRESPOSTA']], $dados);
                else P::ins('ml_aplicab_resposta', ['CODUNIDADE' => $unidade, 'CODPALAVRACHAVE' => $cod] + $dados);
                $mud++;
            }
            AuditService::record(P::$pdo, 'modulolegal_questionario_aplicabilidade', 'SALVAR', 'SUCESSO', 'ml_aplicab_resposta', (string)$unidade, ['respostas' => $mud, 'ambitos' => count($amb)]);
            $json(['ok' => true, 'alteradas' => $mud]);
        }
        if ($acao === 'sugerir_ia') {
            $texto = trim((string)($_POST['descricao'] ?? ''));
            if (mb_strlen($texto) < 20) $json(['ok' => false, 'message' => P::L('Descreva a unidade com mais detalhes (atividades, processos, equipamentos, resíduos...).', 'Describe the unit in more detail.', 'Describa la unidad con más detalle.')], 422);
            $lista = [];
            foreach ($palavras() as $cod => $p) $lista[] = $cod . '=' . $p['palavra'];
            $prompt = 'Você é consultor de requisitos legais ambientais, de saúde e segurança do trabalho no Brasil. A partir da descrição da unidade, escolha na lista de palavras-chave (formato codigo=palavra) '
                . 'as que se aplicam à unidade (atividades, instalações, equipamentos, resíduos, produtos, licenças e obrigações). Seja criterioso: só o que a descrição indica ou que é obrigatório para esse tipo de unidade. '
                . 'Retorne só JSON: {"sim":[codigo,...],"motivos":{"codigo":"motivo curto"}}.';
            $r = InteligenciaArtificialDocumento::analisar("DESCRIÇÃO DA UNIDADE:\n" . mb_substr($texto, 0, 4000) . "\n\nPALAVRAS-CHAVE:\n" . implode("\n", $lista), $prompt, P::$idioma);
            $r = is_array($r['sugestao'] ?? null) ? $r['sugestao'] : $r;
            $validos = $palavras();
            $sim = array_values(array_filter(array_unique(array_map('intval', (array)($r['sim'] ?? []))), static fn($c) => isset($validos[$c])));
            $mot = [];
            foreach ((array)($r['motivos'] ?? []) as $c => $m) if (in_array((int)$c, $sim, true)) $mot[(int)$c] = mb_substr((string)$m, 0, 200);
            AuditService::record(P::$pdo, 'modulolegal_questionario_aplicabilidade', 'SUGERIR_IA', 'SUCESSO', 'ml_aplicab_resposta', (string)$unidade, ['sim' => count($sim)]);
            $json(['ok' => true, 'sim' => $sim, 'motivos' => (object)$mot]);
        }
        if ($acao === 'sugestoes') {
            $amb = $ambitosUnidade($unidade);
            $sim = array_keys(array_filter($respostas($unidade), static fn($r) => $r === 'S'));
            if (!$amb || !$sim) $json(['ok' => true, 'leis' => [], 'faltam' => !$amb ? 'ambitos' : 'respostas']);
            $ja = [];
            foreach (P::sel('legislacaoxempresa', ['codunidade' => $unidade], 50000) as $r) $ja[(int)$r['CODLEGISLACAO']] = true;
            $decididas = [];
            foreach (P::sel('vw_ml_aplicab_lei', ['CODUNIDADE' => $unidade], 50000) as $r) $decididas[(int)$r['CODLEGISLACAO']] = (string)$r['DECISAO'];
            $pal = $palavras(); $leis = [];
            foreach ($sim as $cod) {
                foreach (P::sel('vw_aplicab_lei_palavra', ['CODPALAVRACHAVE' => $cod], 5000) as $l) {
                    $leg = (int)$l['CODLEGISLACAO'];
                    if (!in_array((int)$l['CODAMBITO'], $amb, true) || isset($ja[$leg]) || ($decididas[$leg] ?? '') === 'RECUSADA') continue;
                    if (preg_match('/revogad/i', (string)($l['SITUACAO_LEI'] ?? ''))) continue;
                    $leis[$leg] ??= ['leg' => $leg, 'codigo' => (string)$l['CODIGO'], 'ambito' => (string)($l['DSCAMBITO'] ?? ''), 'padrao' => mb_strimwidth(trim((string)($l['PADRAO'] ?? '')), 0, 220, '…'), 'palavras' => []];
                    $pw = $pal[$cod]['palavra'] ?? ('#' . $cod);
                    if (!in_array($pw, $leis[$leg]['palavras'], true)) $leis[$leg]['palavras'][] = $pw;   // a view repete a lei por âmbito/tipo
                }
            }
            $leis = array_values($leis);
            usort($leis, static fn($a, $b) => [count($b['palavras']), $a['codigo']] <=> [count($a['palavras']), $b['codigo']]);
            $json(['ok' => true, 'leis' => array_slice($leis, 0, 1000), 'total' => count($leis), 'incluidas' => count(array_filter($decididas, static fn($d) => $d === 'INCLUIDA'))]);
        }
        if ($acao === 'decidir') {
            $decisao = (string)($_POST['decisao'] ?? '') === 'incluir' ? 'INCLUIDA' : 'RECUSADA';
            if ($decisao === 'INCLUIDA' && !P::pode('incluir')) $json(['ok' => false, 'message' => P::L('Seu perfil não pode incluir leis nos requisitos.', 'Your profile cannot add laws to the requirements.', 'Su perfil no puede incluir leyes en los requisitos.')], 403);
            if ($decisao === 'RECUSADA' && !P::pode('editar') && !P::pode('incluir')) $json(['ok' => false, 'message' => P::L('Seu perfil só consulta o questionário.', 'Your profile can only view.', 'Su perfil solo consulta.')], 403);
            $itens = array_slice((array)json_decode((string)($_POST['leis'] ?? '[]'), true), 0, 500);
            $feitas = 0;
            foreach ($itens as $i) {
                $leg = (int)($i['leg'] ?? 0); if ($leg <= 0) continue;
                if ($decisao === 'INCLUIDA') LegislacaoEmpresas::vincular($leg, $empresa, $unidade);
                $atual = P::sel('ml_aplicab_lei', ['CODUNIDADE' => $unidade, 'CODLEGISLACAO' => $leg])[0] ?? null;
                $dados = ['DECISAO' => $decisao, 'PALAVRAS' => mb_substr(implode(', ', array_map('strval', (array)($i['palavras'] ?? []))), 0, 1000), 'CODUSUARIO' => P::$uid ?: null];
                if ($atual) P::upd('ml_aplicab_lei', ['CODAPLICABLEI' => (int)$atual['CODAPLICABLEI']], $dados);
                else P::ins('ml_aplicab_lei', ['CODUNIDADE' => $unidade, 'CODLEGISLACAO' => $leg] + $dados);
                $feitas++;
            }
            AuditService::record(P::$pdo, 'modulolegal_questionario_aplicabilidade', $decisao === 'INCLUIDA' ? 'INCLUIR_LEIS' : 'RECUSAR_LEIS', 'SUCESSO', 'ml_aplicab_lei', (string)$unidade, ['qtd' => $feitas]);
            $json(['ok' => true, 'feitas' => $feitas]);
        }
        $json(['ok' => false, 'message' => 'Ação inválida.'], 400);
    } catch (Throwable $e) { $json(['ok' => false, 'message' => IdiomaService::erro($e, 'gravar')], 500); }
}

// ---------------------------------------------------------------- tela
$ambitos = [];
foreach (P::sel('vw_aplicab_ambitos', [], 5000) as $a) $ambitos[(int)$a['CODAMBITO']] = (string)$a['DSCAMBITO'];
asort($ambitos, SORT_NATURAL | SORT_FLAG_CASE);
$marcados = $unidade ? $ambitosUnidade($unidade) : [];
if (!$marcados) foreach ($ambitos as $c => $n) if (mb_strtolower(trim($n)) === 'federal') $marcados[] = $c;
$resp = $unidade ? $respostas($unidade) : [];
$pals = $palavras();
$grupos = [];
foreach ($pals as $cod => $p) foreach ($p['tipos'] !== '' ? array_map('trim', explode(',', $p['tipos'])) : [P::L('Outros', 'Others', 'Otros')] as $t) $grupos[$t][$cod] = true;
ksort($grupos, SORT_NATURAL | SORT_FLAG_CASE);
$podeResponder = P::pode('editar') || P::pode('incluir');
$titulo = P::L('Questionário de aplicabilidade legal', 'Legal applicability questionnaire', 'Cuestionario de aplicabilidad legal');
P::cabecalho($titulo, 'bi-ui-checks', 'modulolegal_questionario_aplicabilidade', '.qa-pal{max-height:52vh;overflow:auto}.qa-pal tr.qa-sim td{background:#e8f5ee}.qa-pal tr.qa-ia td:first-child::after{content:" IA";font-size:10px;color:#0d6efd}.qa-amb{max-height:180px;overflow:auto;columns:3 220px}.qa-amb label{display:block}.qa-leis{max-height:52vh;overflow:auto}.qa-btn-r .btn{padding:1px 8px}');
?>
<p class="ps-ajuda small mb-2"><?= P::e(P::L('Responda por unidade. As perguntas vêm das palavras-chave usadas nas leis; cada "Sim" traz as leis com aquela palavra, nos âmbitos marcados, como sugestão. Você confere e inclui nos requisitos da unidade (ou recusa). A atribuição manual de leis continua valendo.', 'Answer per unit. Questions come from the keywords used in laws; each "Yes" brings the laws with that keyword, in the checked scopes, as suggestions. Review and add them to the unit requirements (or reject). Manual assignment still works.', 'Responda por unidad. Las preguntas vienen de las palabras clave de las leyes; cada "Sí" trae las leyes con esa palabra, en los ámbitos marcados, como sugerencia. Revise e incluya en los requisitos de la unidad (o rechace). La asignación manual sigue valiendo.')) ?></p>
<div id="qa" data-csrf="<?= P::e($csrf) ?>" data-unidade="<?= (int)$unidade ?>">
<form class="d-flex gap-2 align-items-center mb-2" method="get"><label class="small fw-semibold"><?= P::e(P::L('Unidade', 'Unit', 'Unidad')) ?></label>
<select name="unidade" class="form-select form-select-sm" style="max-width:320px" onchange="this.form.submit()"><?php foreach ($unidades as $c => $u): ?><option value="<?= (int)$c ?>"<?= $c === $unidade ? ' selected' : '' ?>><?= P::e($u['nome']) ?></option><?php endforeach; ?></select>
<span class="small text-muted ms-2" data-qa-resumo></span></form>
<ul class="nav nav-tabs mb-2"><li class="nav-item"><button type="button" class="nav-link active" data-qa-aba="perguntas">1. <?= P::e(P::L('Âmbitos e perguntas', 'Scopes and questions', 'Ámbitos y preguntas')) ?></button></li>
<li class="nav-item"><button type="button" class="nav-link" data-qa-aba="leis">2. <?= P::e(P::L('Leis sugeridas', 'Suggested laws', 'Leyes sugeridas')) ?> <span class="badge text-bg-secondary" data-qa-nleis></span></button></li></ul>
<section data-qa-painel="perguntas">
<div class="card mb-2"><div class="card-body py-2"><div class="fw-semibold small mb-1"><?= P::e(P::L('Âmbitos que valem para a unidade', 'Scopes that apply to the unit', 'Ámbitos que valen para la unidad')) ?> <input class="form-control form-control-sm d-inline-block ms-2" style="width:220px" placeholder="<?= P::e(P::L('Filtrar âmbitos…', 'Filter scopes…', 'Filtrar ámbitos…')) ?>" data-qa-filtro-amb data-sem-memoria></div>
<div class="qa-amb small"><?php foreach ($ambitos as $c => $n): ?><label><input type="checkbox" class="form-check-input me-1" value="<?= (int)$c ?>" data-qa-amb<?= in_array($c, $marcados, true) ? ' checked' : '' ?><?= $podeResponder ? '' : ' disabled' ?>><?= P::e($n) ?></label><?php endforeach; ?></div></div></div>
<div class="d-flex flex-wrap gap-2 align-items-center mb-2">
<select class="form-select form-select-sm" style="max-width:230px" data-qa-grupo data-sem-memoria><option value=""><?= P::e(P::L('Todos os tipos', 'All types', 'Todos los tipos')) ?> (<?= count($pals) ?>)</option><?php foreach ($grupos as $g => $cs): ?><option value="<?= P::e($g) ?>"><?= P::e($g) ?> (<?= count($cs) ?>)</option><?php endforeach; ?></select>
<select class="form-select form-select-sm" style="max-width:180px" data-qa-mostrar data-sem-memoria><option value=""><?= P::e(P::L('Todas as perguntas', 'All questions', 'Todas las preguntas')) ?></option><option value="S"><?= P::e(P::L('Respondidas Sim', 'Answered Yes', 'Respondidas Sí')) ?></option><option value="N"><?= P::e(P::L('Respondidas Não', 'Answered No', 'Respondidas No')) ?></option><option value="-"><?= P::e(P::L('Sem resposta', 'Unanswered', 'Sin respuesta')) ?></option></select>
<input class="form-control form-control-sm" style="max-width:240px" placeholder="<?= P::e(P::L('Buscar palavra…', 'Search keyword…', 'Buscar palabra…')) ?>" data-qa-busca data-sem-memoria>
<?php if ($podeResponder): ?><button type="button" class="btn btn-sm btn-outline-primary" data-qa-ia><i class="bi bi-stars me-1"></i><?= P::e(P::L('Sugerir respostas com IA', 'Suggest answers with AI', 'Sugerir respuestas con IA')) ?></button>
<button type="button" class="btn btn-sm btn-success ms-auto" data-qa-salvar><i class="bi bi-check2 me-1"></i><?= P::e(P::L('Salvar respostas', 'Save answers', 'Guardar respuestas')) ?></button><?php endif; ?></div>
<div class="alert d-none py-1 px-2 small" data-qa-msg></div>
<div class="qa-pal border rounded bg-white"><table class="table table-sm mb-0"><thead class="table-light"><tr><th><?= P::e(P::L('A unidade tem, realiza ou está sujeita a…', 'Does the unit have, perform or is subject to…', '¿La unidad tiene, realiza o está sujeta a…')) ?></th><th><?= P::e(P::L('Tipo', 'Type', 'Tipo')) ?></th><th class="text-end"><?= P::e(P::L('Leis', 'Laws', 'Leyes')) ?></th><th class="text-center"><?= P::e(P::L('Resposta', 'Answer', 'Respuesta')) ?></th></tr></thead><tbody>
<?php foreach ($pals as $cod => $p): $r = $resp[$cod] ?? ''; ?>
<tr data-qa-p="<?= (int)$cod ?>" data-tipos="<?= P::e($p['tipos'] !== '' ? $p['tipos'] : P::L('Outros', 'Others', 'Otros')) ?>" data-r="<?= P::e($r) ?>"<?= $r === 'S' ? ' class="qa-sim"' : '' ?>><td><?= P::e($p['palavra']) ?><div class="small text-muted d-none" data-qa-motivo></div></td><td class="small text-muted"><?= P::e($p['tipos']) ?></td><td class="text-end small"><?= (int)$p['leis'] ?></td>
<td class="text-center text-nowrap qa-btn-r"><div class="btn-group btn-group-sm"><button type="button" class="btn btn-outline-success<?= $r === 'S' ? ' active' : '' ?>" data-v="S"<?= $podeResponder ? '' : ' disabled' ?>><?= P::e(P::L('Sim', 'Yes', 'Sí')) ?></button><button type="button" class="btn btn-outline-secondary<?= $r === 'N' ? ' active' : '' ?>" data-v="N"<?= $podeResponder ? '' : ' disabled' ?>><?= P::e(P::L('Não', 'No', 'No')) ?></button></div></td></tr>
<?php endforeach; ?></tbody></table></div>
</section>
<section data-qa-painel="leis" class="d-none">
<div class="d-flex flex-wrap gap-2 align-items-center mb-2"><span class="small text-muted" data-qa-leis-info></span>
<button type="button" class="btn btn-sm btn-link" data-qa-marcar="1"><?= P::e(P::L('Marcar todas', 'Check all', 'Marcar todas')) ?></button><button type="button" class="btn btn-sm btn-link" data-qa-marcar="0"><?= P::e(P::L('Desmarcar todas', 'Uncheck all', 'Desmarcar todas')) ?></button>
<?php if (P::pode('incluir')): ?><button type="button" class="btn btn-sm btn-success ms-auto" data-qa-decidir="incluir"><i class="bi bi-plus-lg me-1"></i><?= P::e(P::L('Incluir marcadas nos requisitos da unidade', 'Add checked to the unit requirements', 'Incluir marcadas en los requisitos de la unidad')) ?></button><?php endif; ?>
<?php if ($podeResponder): ?><button type="button" class="btn btn-sm btn-outline-danger<?= P::pode('incluir') ? '' : ' ms-auto' ?>" data-qa-decidir="recusar"><i class="bi bi-x-lg me-1"></i><?= P::e(P::L('Recusar marcadas', 'Reject checked', 'Rechazar marcadas')) ?></button><?php endif; ?></div>
<div class="alert d-none py-1 px-2 small" data-qa-msg2></div>
<div class="qa-leis border rounded bg-white"><table class="table table-sm mb-0"><thead class="table-light"><tr><th></th><th><?= P::e(P::L('Lei', 'Law', 'Ley')) ?></th><th><?= P::e(P::L('Âmbito', 'Scope', 'Ámbito')) ?></th><th><?= P::e(P::L('Palavras "Sim"', '"Yes" keywords', 'Palabras "Sí"')) ?></th><th><?= P::e(P::L('Requisito', 'Requirement', 'Requisito')) ?></th></tr></thead><tbody data-qa-leis></tbody></table></div>
</section>
<div class="modal fade" id="qaIa" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-header py-2"><h5 class="modal-title"><i class="bi bi-stars me-1"></i><?= P::e(P::L('Sugerir respostas com IA', 'Suggest answers with AI', 'Sugerir respuestas con IA')) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><p class="small text-muted"><?= P::e(P::L('Descreva a unidade: o que produz, processos, instalações e equipamentos (caldeira, tanques, empilhadeiras...), produtos químicos, resíduos e efluentes, número de funcionários, licenças. A IA marca "Sim" nas palavras que se aplicam; nada é gravado até você clicar em Salvar respostas.', 'Describe the unit: products, processes, facilities and equipment, chemicals, waste and effluents, headcount, licenses. AI marks "Yes" on the keywords that apply; nothing is saved until you click Save answers.', 'Describa la unidad: productos, procesos, instalaciones y equipos, químicos, residuos y efluentes, empleados, licencias. La IA marca "Sí" en las palabras que aplican; nada se guarda hasta que haga clic en Guardar respuestas.')) ?></p>
<textarea class="form-control" rows="7" maxlength="4000" data-qa-desc data-sem-memoria></textarea><div class="alert alert-danger d-none py-1 px-2 small mt-2" data-qa-ia-erro></div></div>
<div class="modal-footer py-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= P::e(P::L('Cancelar', 'Cancel', 'Cancelar')) ?></button><button type="button" class="btn btn-sm btn-primary" data-qa-ia-ok><i class="bi bi-stars me-1"></i><?= P::e(P::L('Sugerir', 'Suggest', 'Sugerir')) ?></button></div></div></div></div>
</div>
<script>
(function () {
  var raiz = document.getElementById('qa'), csrf = raiz.dataset.csrf, unidade = raiz.dataset.unidade, mudou = {}, leis = [];
  var TX = <?= json_encode(['salvo' => P::L('Respostas gravadas.', 'Answers saved.', 'Respuestas guardadas.'), 'nada' => P::L('Nada mudou.', 'Nothing changed.', 'Nada cambió.'), 'pend' => P::L('Há respostas não gravadas: salve antes de ver as leis.', 'There are unsaved answers: save before viewing the laws.', 'Hay respuestas sin guardar: guarde antes de ver las leyes.'),
    'ia_feito' => P::L('A IA marcou {n} palavra(s) com Sim (destacadas). Confira e clique em Salvar respostas.', 'AI marked {n} keyword(s) Yes (highlighted). Review and click Save answers.', 'La IA marcó {n} palabra(s) con Sí. Revise y haga clic en Guardar respuestas.'),
    'sem_amb' => P::L('Marque os âmbitos da unidade e salve.', 'Check the unit scopes and save.', 'Marque los ámbitos de la unidad y guarde.'), 'sem_resp' => P::L('Responda "Sim" a alguma pergunta e salve.', 'Answer "Yes" to some question and save.', 'Responda "Sí" a alguna pregunta y guarde.'),
    'info' => P::L('{t} lei(s) sugerida(s) que ainda não são requisitos da unidade · {i} já incluída(s) pelo questionário', '{t} suggested law(s) not yet unit requirements · {i} already added by the questionnaire', '{t} ley(es) sugerida(s) · {i} ya incluida(s)'),
    'nenhuma' => P::L('Nenhuma lei nova para sugerir.', 'No new law to suggest.', 'Ninguna ley nueva.'), 'feito' => P::L('{n} lei(s) atualizada(s).', '{n} law(s) updated.', '{n} ley(es) actualizada(s).'), 'marque' => P::L('Marque as leis.', 'Check the laws.', 'Marque las leyes.'),
    'resumo' => P::L('{s} Sim · {n} Não · {r} de {t} respondidas', '{s} Yes · {n} No · {r} of {t} answered', '{s} Sí · {n} No · {r} de {t} respondidas')], JSON_UNESCAPED_UNICODE) ?>;
  function f(t, o) { return String(t).replace(/\{(\w)\}/g, function (_, k) { return o[k]; }); }
  function esc(v) { var d = document.createElement('div'); d.textContent = v == null ? '' : String(v); return d.innerHTML; }
  function msg(sel, tipo, t) { var m = raiz.querySelector(sel); m.className = 'alert py-1 px-2 small alert-' + tipo + (t ? '' : ' d-none'); m.textContent = t || ''; }
  function post(dados) { var b = new URLSearchParams(dados); b.set('csrf', csrf); b.set('unidade', unidade); return fetch(location.pathname, { method: 'POST', credentials: 'same-origin', body: b }).then(function (r) { return r.json(); }); }
  function resumo() {
    var linhas = raiz.querySelectorAll('[data-qa-p]'), s = 0, n = 0;
    linhas.forEach(function (tr) { if (tr.dataset.r === 'S') s++; if (tr.dataset.r === 'N') n++; });
    raiz.querySelector('[data-qa-resumo]').textContent = f(TX.resumo, { s: s, n: n, r: s + n, t: linhas.length });
  }
  function filtrar() {
    var g = raiz.querySelector('[data-qa-grupo]').value, m = raiz.querySelector('[data-qa-mostrar]').value, b = raiz.querySelector('[data-qa-busca]').value.trim().toLowerCase();
    raiz.querySelectorAll('[data-qa-p]').forEach(function (tr) {
      var ok = (!g || (', ' + tr.dataset.tipos + ',').indexOf(', ' + g + ',') >= 0) && (!m || (m === '-' ? tr.dataset.r === '' : tr.dataset.r === m)) && (!b || tr.cells[0].textContent.toLowerCase().indexOf(b) >= 0);
      tr.classList.toggle('d-none', !ok);
    });
  }
  function marcar(tr, v, ia) {
    if (tr.dataset.r === v && !ia) v = '';
    tr.dataset.r = v; tr.classList.toggle('qa-sim', v === 'S'); tr.classList.toggle('qa-ia', !!ia && v === 'S');
    tr.querySelectorAll('[data-v]').forEach(function (b) { b.classList.toggle('active', b.dataset.v === v); });
    mudou[tr.dataset.qaP] = v + (ia ? '*' : '');
    resumo();
  }
  raiz.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-v]'); if (b) { marcar(b.closest('tr'), b.dataset.v, false); return; }
    var a = ev.target.closest('[data-qa-aba]');
    if (a) {
      raiz.querySelectorAll('[data-qa-aba]').forEach(function (x) { x.classList.toggle('active', x === a); });
      raiz.querySelectorAll('[data-qa-painel]').forEach(function (p) { p.classList.toggle('d-none', p.dataset.qaPainel !== a.dataset.qaAba); });
      if (a.dataset.qaAba === 'leis') carregarLeis();
      return;
    }
    var mk = ev.target.closest('[data-qa-marcar]'); if (mk) { raiz.querySelectorAll('[data-qa-lei]').forEach(function (c) { c.checked = mk.dataset.qaMarcar === '1'; }); return; }
    var d = ev.target.closest('[data-qa-decidir]'); if (d) decidir(d.dataset.qaDecidir);
  });
  ['data-qa-grupo', 'data-qa-mostrar'].forEach(function (s) { raiz.querySelector('[' + s + ']').addEventListener('change', filtrar); });
  raiz.querySelector('[data-qa-busca]').addEventListener('input', filtrar);
  raiz.querySelector('[data-qa-filtro-amb]').addEventListener('input', function () { var t = this.value.toLowerCase(); raiz.querySelectorAll('.qa-amb label').forEach(function (l) { l.classList.toggle('d-none', t !== '' && l.textContent.toLowerCase().indexOf(t) < 0); }); });
  var ambAlterado = false; raiz.querySelectorAll('[data-qa-amb]').forEach(function (c) { c.addEventListener('change', function () { ambAlterado = true; }); });
  var bs = raiz.querySelector('[data-qa-salvar]');
  if (bs) bs.addEventListener('click', function () {
    var amb = Array.prototype.map.call(raiz.querySelectorAll('[data-qa-amb]:checked'), function (c) { return Number(c.value); });
    bs.disabled = true;
    post({ acao: 'salvar', ambitos: JSON.stringify(amb), respostas: JSON.stringify(mudou) }).then(function (r) {
      bs.disabled = false;
      if (!r.ok) { msg('[data-qa-msg]', 'danger', r.message); return; }
      mudou = {}; ambAlterado = false; msg('[data-qa-msg]', 'success', TX.salvo);
    }).catch(function () { bs.disabled = false; });
  });
  var bia = raiz.querySelector('[data-qa-ia]');
  if (bia) bia.addEventListener('click', function () { bootstrap.Modal.getOrCreateInstance(document.getElementById('qaIa')).show(); });
  raiz.querySelector('[data-qa-ia-ok]').addEventListener('click', function () {
    var b = this, er = raiz.querySelector('[data-qa-ia-erro]'); b.disabled = true; er.classList.add('d-none');
    b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>…';
    post({ acao: 'sugerir_ia', descricao: raiz.querySelector('[data-qa-desc]').value }).then(function (r) {
      b.disabled = false; b.innerHTML = '<i class="bi bi-stars me-1"></i>OK';
      if (!r.ok) { er.classList.remove('d-none'); er.textContent = r.message; return; }
      var n = 0;
      (r.sim || []).forEach(function (cod) {
        var tr = raiz.querySelector('[data-qa-p="' + cod + '"]'); if (!tr || tr.dataset.r === 'S') return;
        marcar(tr, 'S', true); n++;
        var m = (r.motivos || {})[cod]; if (m) { var dv = tr.querySelector('[data-qa-motivo]'); dv.textContent = m; dv.classList.remove('d-none'); }
      });
      bootstrap.Modal.getInstance(document.getElementById('qaIa')).hide();
      raiz.querySelector('[data-qa-mostrar]').value = 'S'; filtrar();
      msg('[data-qa-msg]', 'info', f(TX.ia_feito, { n: n }));
    }).catch(function () { b.disabled = false; });
  });
  function carregarLeis() {
    var corpo = raiz.querySelector('[data-qa-leis]');
    if (Object.keys(mudou).length || ambAlterado) { msg('[data-qa-msg2]', 'warning', TX.pend); corpo.innerHTML = ''; return; }
    msg('[data-qa-msg2]', '', ''); corpo.innerHTML = '<tr><td colspan="5" class="text-muted"><span class="spinner-border spinner-border-sm"></span></td></tr>';
    fetch(location.pathname + '?acao=sugestoes&unidade=' + encodeURIComponent(unidade), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
      if (!r.ok) { corpo.innerHTML = ''; msg('[data-qa-msg2]', 'danger', r.message); return; }
      leis = r.leis || [];
      raiz.querySelector('[data-qa-nleis]').textContent = r.total || '';
      raiz.querySelector('[data-qa-leis-info]').textContent = r.faltam ? (r.faltam === 'ambitos' ? TX.sem_amb : TX.sem_resp) : f(TX.info, { t: r.total || 0, i: r.incluidas || 0 });
      corpo.innerHTML = leis.length ? leis.map(function (l, i) { return '<tr><td><input type="checkbox" class="form-check-input" data-qa-lei="' + i + '"></td><td class="text-nowrap">' + esc(l.codigo) + '</td><td class="small">' + esc(l.ambito) + '</td><td class="small">' + esc(l.palavras.join(', ')) + '</td><td class="small">' + esc(l.padrao) + '</td></tr>'; }).join('') : '<tr><td colspan="5" class="text-muted">' + esc(TX.nenhuma) + '</td></tr>';
    });
  }
  function decidir(tipo) {
    var marc = Array.prototype.map.call(raiz.querySelectorAll('[data-qa-lei]:checked'), function (c) { var l = leis[Number(c.dataset.qaLei)]; return { leg: l.leg, palavras: l.palavras }; });
    if (!marc.length) { msg('[data-qa-msg2]', 'warning', TX.marque); return; }
    post({ acao: 'decidir', decisao: tipo, leis: JSON.stringify(marc) }).then(function (r) {
      if (!r.ok) { msg('[data-qa-msg2]', 'danger', r.message); return; }
      carregarLeis(); window.setTimeout(function () { msg('[data-qa-msg2]', 'success', f(TX.feito, { n: r.feitas })); }, 300);
    });
  }
  resumo();
}());
</script>
<?php P::rodape(); ?>
