<?php
declare(strict_types=1);

/*
 * Auditoria › Gestão de Auditorias e Minhas Auditorias (migração 423 — Revisão G itens 166 a 177, 27/11/2026).
 * Equivale às telas do Green 3 (cadastros/auditoria_planejamento_c.php e as abas Avaliação, Execução, Workflow e Não Conformidades),
 * com o fluxo P → A → (R | X) → (E | C), mais: unidade, tipo e norma; checklist (manual ou gerado pela IA a partir da norma ou de
 * um procedimento da pasta da auditoria); resposta sugerida pela IA a partir da evidência; constatações, cada NC abrindo uma RNC já
 * ligada (CODAUDITORIA); follow-up das RNCs antes de encerrar; agenda do auditor e do avaliador; relatório em PDF.
 * ?minhas=1 (ou minhas_auditorias.php): só as auditorias de que o usuário participa (criador, avaliador, auditor ou grupo).
 * Migração 424 (27/11/2026): telas no padrão do portal — a lista no grid padrão (auditoria_lista.inc.php); a ficha com a barra de
 * ícones em grupos rotulados (Operações · Fluxo · Associações · Outros), motivo e confirmação em modal, e as abas Checklist,
 * Constatações, RNC e Histórico no grid local (filtro por coluna e "Buscar no grid"); a aba RNC mostra o fluxo de cada RNC;
 * o histórico registra também avaliação, execução, checklist pela IA, constatações e RNCs; Cancelar vale em qualquer etapa aberta.
 * Leitura por views (vw_aud_*); gravação por sp_crud_insert / sp_crud_update / sp_crud_delete; avisos por sp_alerta_criar.
 */
// Migração 424: sem ?id= nem ?novo= (e sem POST) é a lista, no grid padrão do portal
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && empty($_GET['id']) && !isset($_GET['novo']) && !isset($_GET['ajax']) && !isset($_GET['excluida'])) {
    require __DIR__ . '/auditoria_lista.inc.php';
    exit;
}
require_once dirname(__DIR__) . '/classes/Auditoria.php';

use PaginaSimples as P;
use Auditoria as A;

$minhas = !empty($GLOBALS['AUD_MINHAS']) || isset($_GET['minhas']);
$ajax = isset($_GET['ajax']);
P::abrir($minhas ? 'MINHAS_AUDITORIAS' : 'AUDITORIA_GESTAO', $ajax);
$uid = P::$uid;
$csrf = P::csrf('aud_csrf');
// Revisão G item 177: aberta pelo fornecedor (Homologação › Gestão de Fornecedores › Auditorias): só as auditorias dele
$fornFiltro = (int)($_GET['CODFORNECEDOR'] ?? $_GET['codfornecedor'] ?? 0);
$self = ($minhas ? 'auditoria_gestao.php?minhas=1' : 'auditoria_gestao.php') . ($fornFiltro ? ($minhas ? '&' : '?') . 'CODFORNECEDOR=' . $fornFiltro : '');
$link = static fn(int $id, string $aba = '') => $self . (str_contains($self, '?') ? '&' : '?') . 'id=' . $id . ($aba !== '' ? '&aba=' . $aba : '');
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$novo = isset($_GET['novo']) && P::pode('incluir');
$hoje = date('Y-m-d');
$aud = $id ? A::auditoria($id) : null;
if ($id && !$aud) P::sair(404, P::L('Auditoria não encontrada.', 'Audit not found.', 'Auditoría no encontrada.'), $ajax);
if ($aud && $minhas && !A::participa($aud, $uid) && !P::admin()) P::sair(403, P::L('Você não participa desta auditoria.', 'You do not take part in this audit.', 'Usted no participa en esta auditoría.'), $ajax);
$papel = $aud ? A::papeis($aud, $uid) : ['criador' => true, 'avaliador' => false, 'auditor' => false];
$st = $aud ? (string)$aud['STATUS'] : 'P';
$podeEd = P::pode('editar') || P::pode('incluir');
$pode = [
    'plano' => $podeEd && (!$aud || (in_array($st, ['P', 'R'], true) && $papel['criador'])),
    'avaliar' => $podeEd && $aud && $st === 'A' && $papel['avaliador'],
    'executar' => $podeEd && $aud && $st === 'X' && ($papel['auditor'] || $papel['criador']),
    'checklist' => $podeEd && $aud && ((in_array($st, ['P', 'R'], true) && ($papel['criador'] || $papel['auditor'])) || ($st === 'X' && ($papel['auditor'] || $papel['criador']))),
    'excluir' => P::pode('excluir') && $aud && in_array($st, ['P', 'R'], true) && $papel['criador'],
    // migração 424: cancelar em qualquer etapa aberta — quem criou (planejamento/avaliação) ou o auditor/criador (execução)
    'cancelar' => $podeEd && $aud && ((in_array($st, ['P', 'R', 'A'], true) && $papel['criador']) || ($st === 'X' && ($papel['auditor'] || $papel['criador']))),
];

// ------------------------------------------------------------------------------------------------ cadastros auxiliares
$nomeDe = static function (array $l, array $cands): string { foreach ($cands as $c) if (trim((string)($l[$c] ?? '')) !== '') return trim((string)$l[$c]); return ''; };
$usuarios = P::usuarios();
$grupos = [];
try { foreach (P::sel('vw_grupos_grid', ['ativo' => 1], 2000) as $g) $grupos[(int)$g['CODGRUPO']] = $nomeDe($g, ['NOME', 'DSCGRUPO', 'DESCRICAO']) ?: ('#' . $g['CODGRUPO']); } catch (Throwable) {}
asort($grupos, SORT_NATURAL | SORT_FLAG_CASE);
$unidades = [];
try { foreach (P::sel('vw_unidades_grid', [], 2000) as $u) $unidades[(int)$u['CODUNIDADE']] = trim((string)($u['DSCUNIDADE'] ?? '')) ?: ('#' . $u['CODUNIDADE']); } catch (Throwable) {}
asort($unidades, SORT_NATURAL | SORT_FLAG_CASE);
$origens = [];
try { foreach (P::sel('nc_origemacao', [], 500) as $o) $origens[(int)$o['CODORIGEMACAO']] = trim((string)$o['DSCORIGEMACAO']); } catch (Throwable) {}
$tipos = array_map(static fn($t) => A::rotulo($t), A::TIPOS);

// ------------------------------------------------------------------------------------------------ gravação
$flash = static function (string $m, string $tipo = 'success') { $_SESSION['aud_flash'] = [$m, $tipo]; };
$json = static function (array $r, int $c = 200): never { http_response_code($c); header('Content-Type: application/json; charset=utf-8'); exit(json_encode($r, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)); };
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    P::conferirCsrf('aud_csrf', $ajax);
    $acao = (string)($_POST['acao'] ?? '');
    $avisar = !empty($_POST['avisar']);
    $txt = static fn(string $k, int $max = 5000) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $data = static function (string $k) { $v = trim((string)($_POST[$k] ?? '')); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null; };
    $int = static fn(string $k) => (int)($_POST[$k] ?? 0) ?: null;
    $erro = static function (string $m) use ($ajax, $json, $flash, $link, $id): never { if ($ajax) $json(['ok' => false, 'message' => $m], 422); $flash($m, 'danger'); header('Location: ' . ($id ? $link($id) : 'auditoria_gestao.php?novo=1' . ($minhas ? '&minhas=1' : ''))); exit; };
    $status = static function (string $novo, string $obs) use ($id) { P::upd('auditoria_planejamento', ['CODAUDITORIA' => $id], ['status' => $novo]); A::registrar($id, $novo, $obs); };
    try {
        switch ($acao) {
            case 'plano':
                if (!$pode['plano']) $erro(P::L('Sem permissão para alterar o planejamento.', 'No permission to change the plan.', 'Sin permiso para cambiar el plan.'));
                $desc = $txt('DSCAUDITORIA', 500);
                if ($desc === '') $erro(P::L('Informe o título da auditoria.', 'Enter the audit title.', 'Informe el título de la auditoría.'));
                $ini = $data('datadeinicio'); $fim = $data('datadefim');
                if ($ini && $fim && $fim < $ini) $erro(P::L('A data de fim é anterior à de início.', 'The end date is before the start date.', 'La fecha de fin es anterior a la de inicio.'));
                $dados = ['DSCAUDITORIA' => $desc, 'OBJETIVO' => $txt('OBJETIVO'), 'datadeinicio' => $ini, 'datadefim' => $fim, 'localizacao' => $txt('localizacao', 500),
                    'codusuario_avaliador' => $int('codusuario_avaliador'), 'codgrupo_avaliadores' => $int('codgrupo_avaliadores'),
                    'codusuario_auditor' => $int('codusuario_auditor'), 'codgrupo_auditores' => $int('codgrupo_auditores')];
                if (!$id) {
                    P::ins('auditoria_planejamento', $dados + ['datadecriacao' => date('Y-m-d H:i:s'), 'codusuario_criador' => $uid, 'status' => 'P']);
                    $id = (int)P::$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
                    if (!$id) $id = (int)(P::$pdo->query('SELECT MAX(`CODAUDITORIA`) FROM `auditoria_planejamento` WHERE `codusuario_criador` = ' . $uid)->fetchColumn());
                    A::registrar($id, 'P', P::L('Planejamento de auditoria criado', 'Audit plan created', 'Plan de auditoría creado'));
                } else P::upd('auditoria_planejamento', ['CODAUDITORIA' => $id], $dados);
                $tipo = isset(A::TIPOS[(string)($_POST['TIPO'] ?? '')]) ? (string)$_POST['TIPO'] : 'INTERNA';
                $info = ['CODUNIDADE' => $int('CODUNIDADE'), 'TIPO' => $tipo, 'NORMA' => $txt('NORMA', 300), 'ESCOPO' => $txt('ESCOPO'),
                    'CODFORNECEDOR' => $tipo === 'FORNECEDOR' ? $int('CODFORNECEDOR') : null, 'CODORIGEMACAO' => $int('CODORIGEMACAO'), 'CODUSUARIO' => $uid];
                if (P::sel('aud_info', ['CODAUDITORIA' => $id], 1)) P::upd('aud_info', ['CODAUDITORIA' => $id], $info); else P::ins('aud_info', ['CODAUDITORIA' => $id] + $info);
                if ($a2 = A::auditoria($id)) A::agenda($a2);
                AuditService::record(P::$pdo, 'auditoria_gestao', 'SALVAR_PLANO', 'SUCESSO', 'auditoria_planejamento', (string)$id);
                $flash(P::L('Planejamento gravado.', 'Plan saved.', 'Plan guardado.'));
                header('Location: ' . $link($id)); exit;

            case 'enviar_avaliacao':
                if (!$pode['plano'] || !$aud) $erro(P::L('Só o planejamento pode ser enviado para avaliação.', 'Only a plan can be sent for review.', 'Solo el plan puede enviarse a evaluación.'));
                if (!$aud['CODUSUARIO_AVALIADOR'] && !$aud['CODGRUPO_AVALIADORES']) $erro(P::L('Informe o avaliador ou o grupo de avaliadores.', 'Choose the reviewer or reviewer group.', 'Informe el evaluador o el grupo de evaluadores.'));
                if (!$aud['DATADEINICIO']) $erro(P::L('Informe a data de início.', 'Enter the start date.', 'Informe la fecha de inicio.'));
                $status('A', P::L('Planejamento de auditoria enviado para avaliação', 'Audit plan sent for review', 'Plan de auditoría enviado a evaluación'));
                $n = $avisar ? A::avisar(A::pessoas((int)$aud['CODUSUARIO_AVALIADOR'], (int)$aud['CODGRUPO_AVALIADORES']), P::L('Avaliar plano de auditoria #', 'Review audit plan #', 'Evaluar plan de auditoría #') . $id, (string)$aud['DSCAUDITORIA'], $id) : 0;
                A::agenda(A::auditoria($id));
                $flash(P::L('Enviada para avaliação.', 'Sent for review.', 'Enviada a evaluación.') . ($n ? ' ' . P::L("$n aviso(s) enviados.", "$n notice(s) sent.", "$n aviso(s) enviados.") : ''));
                header('Location: ' . $link($id)); exit;

            case 'avaliacao':
                if (!$pode['avaliar']) $erro(P::L('Só o avaliador registra a avaliação.', 'Only the reviewer records the review.', 'Solo el evaluador registra la evaluación.'));
                $dsc = $txt('DSCAVALIACAO', 500);
                if ($dsc === '') $erro(P::L('Escreva a avaliação do plano.', 'Write the plan review.', 'Escriba la evaluación del plan.'));
                $dados = ['DSCAVALIACAO' => $dsc, 'DATAAVALIACAO' => $data('DATAAVALIACAO') ?? $hoje, 'codusuario_avaliador' => $uid];
                $ex = P::sel('auditoria_planejamentoxavaliacao', ['CODAUDITORIA' => $id], 50);
                if ($ex) P::upd('auditoria_planejamentoxavaliacao', ['CODAVALIACAO' => (int)end($ex)['CODAVALIACAO']], $dados); else P::ins('auditoria_planejamentoxavaliacao', ['CODAUDITORIA' => $id] + $dados);
                A::registrar($id, $st, P::L('Avaliação do plano registrada: ', 'Plan review recorded: ', 'Evaluación del plan registrada: ') . mb_strimwidth($dsc, 0, 300, '…'));
                $flash(P::L('Avaliação gravada.', 'Review saved.', 'Evaluación guardada.'));
                header('Location: ' . $link($id, 'avaliacao')); exit;

            case 'aprovar':
            case 'recusar':
                if (!$pode['avaliar']) $erro(P::L('Só o avaliador aprova ou recusa o plano.', 'Only the reviewer approves or rejects the plan.', 'Solo el evaluador aprueba o rechaza el plan.'));
                if ($acao === 'recusar') {
                    $mot = $txt('motivo', 500);
                    if ($mot === '') $erro(P::L('Informe o motivo da recusa.', 'Enter the reason.', 'Informe el motivo del rechazo.'));
                    $status('R', $mot);
                    if ($avisar) A::avisar([(int)$aud['CODUSUARIO_CRIADOR']], P::L('Plano de auditoria recusado #', 'Audit plan rejected #', 'Plan de auditoría rechazado #') . $id, $mot, $id);
                    $flash(P::L('Plano recusado: voltou ao planejamento.', 'Plan rejected: back to planning.', 'Plan rechazado: volvió a la planificación.'), 'warning');
                } else {
                    $av = array_filter(P::sel('auditoria_planejamentoxavaliacao', ['CODAUDITORIA' => $id], 50), static fn($x) => trim((string)$x['DSCAVALIACAO']) !== '' && !empty($x['DATAAVALIACAO']));
                    if (!$av) $erro(P::L('Registre a avaliação (texto e data) antes de aprovar.', 'Record the review (text and date) before approving.', 'Registre la evaluación (texto y fecha) antes de aprobar.'));
                    $status('X', P::L('Planejamento de auditoria aprovado e enviado para execução', 'Audit plan approved and sent for execution', 'Plan de auditoría aprobado y enviado a ejecución'));
                    if ($avisar) A::avisar(A::pessoas((int)$aud['CODUSUARIO_AUDITOR'], (int)$aud['CODGRUPO_AUDITORES']), P::L('Executar a auditoria #', 'Carry out audit #', 'Ejecutar la auditoría #') . $id, (string)$aud['DSCAUDITORIA'], $id);
                    $flash(P::L('Plano aprovado: auditoria em execução.', 'Plan approved: audit in progress.', 'Plan aprobado: auditoría en ejecución.'));
                }
                A::agenda(A::auditoria($id));
                header('Location: ' . $link($id)); exit;

            case 'execucao':
                if (!$pode['executar']) $erro(P::L('Só o auditor registra a execução.', 'Only the auditor records the execution.', 'Solo el auditor registra la ejecución.'));
                $dados = ['OBSERVACAO' => $txt('OBSERVACAO'), 'DATAEXECUCAO' => $data('DATAEXECUCAO'), 'DATAENCERRAMENTO' => $data('DATAENCERRAMENTO'), 'codusuario_auditor' => $uid];
                $ex = P::sel('auditoria_planejamentoxexecucao', ['CODAUDITORIA' => $id], 50);
                if ($ex) P::upd('auditoria_planejamentoxexecucao', ['CODEXECUCAO' => (int)end($ex)['CODEXECUCAO']], $dados); else P::ins('auditoria_planejamentoxexecucao', ['CODAUDITORIA' => $id] + $dados);
                A::registrar($id, $st, P::L('Execução registrada', 'Execution recorded', 'Ejecución registrada') . ($dados['DATAEXECUCAO'] ? ' (' . date('d/m/Y', strtotime($dados['DATAEXECUCAO'])) . ')' : ''));
                $flash(P::L('Execução gravada.', 'Execution saved.', 'Ejecución guardada.'));
                header('Location: ' . $link($id, 'execucao')); exit;

            case 'checklist_add':
                if (!$pode['checklist']) $erro(P::L('O checklist não pode ser alterado agora.', 'The checklist cannot be changed now.', 'El checklist no puede cambiarse ahora.'));
                $perg = $txt('PERGUNTA', 1000);
                if ($perg === '') $erro(P::L('Escreva a pergunta.', 'Write the question.', 'Escriba la pregunta.'));
                $ordem = (int)P::$pdo->query('SELECT COALESCE(MAX(`ORDEM`), 0) + 10 FROM `aud_checklist` WHERE `CODAUDITORIA` = ' . $id)->fetchColumn();
                P::ins('aud_checklist', ['CODAUDITORIA' => $id, 'ORDEM' => $ordem, 'REQUISITO' => $txt('REQUISITO', 200), 'PERGUNTA' => $perg, 'ORIENTACAO' => $txt('ORIENTACAO', 2000), 'ORIGEM' => 'MANUAL', 'CODUSUARIO' => $uid]);
                A::registrar($id, $st, P::L('Pergunta incluída no checklist: ', 'Question added to the checklist: ', 'Pregunta incluida en el checklist: ') . mb_strimwidth($perg, 0, 200, '…'));
                $flash(P::L('Pergunta incluída.', 'Question added.', 'Pregunta incluida.'));
                header('Location: ' . $link($id, 'checklist')); exit;

            case 'checklist_salvar':
                if (!$pode['checklist']) $erro(P::L('O checklist não pode ser alterado agora.', 'The checklist cannot be changed now.', 'El checklist no puede cambiarse ahora.'));
                $atuais = [];
                foreach (P::sel('aud_checklist', ['CODAUDITORIA' => $id], 2000) as $c) $atuais[(int)$c['CODCHECKLIST']] = $c;
                $n = 0;
                foreach ((array)($_POST['ck'] ?? []) as $k => $row) {
                    $k = (int)$k; $row = (array)$row;
                    if (!isset($atuais[$k])) continue;
                    if (!empty($row['_del'])) { if (!array_filter(P::sel('aud_constatacao', ['CODCHECKLIST' => $k], 5))) { P::del('aud_checklist', ['CODCHECKLIST' => $k]); $n++; } continue; }
                    $resp = isset(A::RESPOSTAS[(string)($row['RESPOSTA'] ?? '')]) ? (string)$row['RESPOSTA'] : null;
                    if ($st !== 'X') $resp = $atuais[$k]['RESPOSTA'] ?: null; // respostas só na execução
                    $ev = mb_substr(trim((string)($row['EVIDENCIA'] ?? '')), 0, 10000);
                    $d = ['RESPOSTA' => $resp, 'EVIDENCIA' => $ev !== '' ? $ev : null];
                    if ((string)($atuais[$k]['RESPOSTA'] ?? '') === (string)$resp && (string)($atuais[$k]['EVIDENCIA'] ?? '') === (string)($d['EVIDENCIA'] ?? '')) continue;
                    if ($resp !== null && (string)($atuais[$k]['RESPOSTA'] ?? '') !== $resp) $d += ['CODUSUARIO' => $uid, 'RESPONDIDO_EM' => date('Y-m-d H:i:s')];
                    P::upd('aud_checklist', ['CODCHECKLIST' => $k], $d); $n++;
                }
                $flash($n ? P::L("Checklist gravado ($n alteração(ões)).", "Checklist saved ($n change(s)).", "Checklist guardado ($n cambio(s)).") : P::L('Nada mudou.', 'Nothing changed.', 'Nada cambió.'), $n ? 'success' : 'secondary');
                header('Location: ' . $link($id, 'checklist')); exit;

            case 'checklist_del':
                if (!$pode['checklist']) $erro(P::L('O checklist não pode ser alterado agora.', 'The checklist cannot be changed now.', 'El checklist no puede cambiarse ahora.'));
                $c = P::sel('aud_checklist', ['CODCHECKLIST' => (int)($_POST['cod'] ?? 0)], 1)[0] ?? null;
                if (!$c || (int)$c['CODAUDITORIA'] !== $id) $erro(P::L('Pergunta não encontrada.', 'Question not found.', 'Pregunta no encontrada.'));
                if (P::sel('aud_constatacao', ['CODCHECKLIST' => (int)$c['CODCHECKLIST']], 1)) $erro(P::L('A pergunta tem constatação: exclua a constatação antes.', 'The question has a finding: delete the finding first.', 'La pregunta tiene hallazgo: elimine el hallazgo antes.'));
                P::del('aud_checklist', ['CODCHECKLIST' => (int)$c['CODCHECKLIST']]);
                A::registrar($id, $st, P::L('Pergunta excluída do checklist: ', 'Question deleted from the checklist: ', 'Pregunta eliminada del checklist: ') . mb_strimwidth((string)$c['PERGUNTA'], 0, 200, '…'));
                $flash(P::L('Pergunta excluída.', 'Question deleted.', 'Pregunta eliminada.'));
                header('Location: ' . $link($id, 'checklist')); exit;

            case 'ia_checklist':
                if (!$pode['checklist']) $json(['ok' => false, 'message' => P::L('O checklist não pode ser alterado agora.', 'The checklist cannot be changed now.', 'El checklist no puede cambiarse ahora.')], 403);
                $texto = $txt('texto', 120000);
                $arq = trim((string)($_POST['arquivo'] ?? ''));
                if ($arq !== '') $texto = trim(A::textoArquivo($id, $arq) . "\n" . $texto);
                if (mb_strlen($texto) < 80) $json(['ok' => false, 'message' => P::L('Cole o texto da norma/procedimento ou escolha um arquivo da pasta da auditoria.', 'Paste the standard/procedure text or choose a file from the audit folder.', 'Pegue el texto de la norma/procedimiento o elija un archivo de la carpeta de la auditoría.')], 422);
                $qtd = max(5, min(40, (int)($_POST['quantidade'] ?? 15)));
                $prompt = "Você é auditor líder de sistemas de gestão (ISO 9001, 14001, 45001 e afins). A partir do documento (norma, procedimento ou requisito) gere um checklist de auditoria com até $qtd perguntas objetivas, verificáveis por evidência, na ordem do documento. Para cada pergunta informe a cláusula/item de referência e uma orientação curta do que verificar (registro, entrevista ou observação). Retorne só JSON: {\"perguntas\":[{\"requisito\":\"4.1\",\"pergunta\":\"...\",\"orientacao\":\"...\"}]}";
                $escopo = 'AUDITORIA: ' . $aud['DSCAUDITORIA'] . "\nOBJETIVO: " . $aud['OBJETIVO'] . "\nNORMA: " . ($aud['NORMA'] ?? '') . "\nESCOPO: " . ($aud['ESCOPO'] ?? '') . "\n\nDOCUMENTO:\n";
                $r = A::ia($escopo . $texto, 'AUDITORIA_CHECKLIST', $prompt, $id);
                $ordem = (int)P::$pdo->query('SELECT COALESCE(MAX(`ORDEM`), 0) FROM `aud_checklist` WHERE `CODAUDITORIA` = ' . $id)->fetchColumn();
                $n = 0;
                foreach (array_slice((array)($r['perguntas'] ?? []), 0, $qtd) as $q) {
                    $p = mb_substr(trim((string)($q['pergunta'] ?? '')), 0, 1000);
                    if ($p === '') continue;
                    P::ins('aud_checklist', ['CODAUDITORIA' => $id, 'ORDEM' => $ordem += 10, 'REQUISITO' => mb_substr(trim((string)($q['requisito'] ?? '')), 0, 200) ?: null, 'PERGUNTA' => $p,
                        'ORIENTACAO' => mb_substr(trim((string)($q['orientacao'] ?? '')), 0, 2000) ?: null, 'ORIGEM' => 'IA', 'CODUSUARIO' => $uid]);
                    $n++;
                }
                AuditService::record(P::$pdo, 'auditoria_gestao', 'IA_CHECKLIST', $n ? 'SUCESSO' : 'VAZIO', 'aud_checklist', (string)$id, ['perguntas' => $n]);
                if ($n) A::registrar($id, $st, P::L("$n pergunta(s) do checklist geradas pela IA", "$n checklist question(s) generated by AI", "$n pregunta(s) del checklist generadas por la IA"));
                $json(['ok' => (bool)$n, 'message' => $n ? P::L("$n pergunta(s) criadas pela IA. Revise antes de usar.", "$n question(s) created by AI. Review before use.", "$n pregunta(s) creadas por la IA. Revise antes de usar.") : P::L('A IA não devolveu perguntas.', 'The AI returned no questions.', 'La IA no devolvió preguntas.')]);

            case 'ia_evidencia':
                if (!$pode['executar']) $json(['ok' => false, 'message' => P::L('Só na execução, pelo auditor.', 'Only during execution, by the auditor.', 'Solo en la ejecución, por el auditor.')], 403);
                $ck = P::sel('aud_checklist', ['CODCHECKLIST' => (int)($_POST['codchecklist'] ?? 0)], 1)[0] ?? null;
                if (!$ck || (int)$ck['CODAUDITORIA'] !== $id) $json(['ok' => false, 'message' => 'Pergunta não encontrada.'], 404);
                $texto = $txt('evidencia', 60000);
                $arq = trim((string)($_POST['arquivo'] ?? ''));
                if ($arq !== '') $texto = trim($texto . "\n\nARQUIVO " . basename($arq) . ":\n" . A::textoArquivo($id, $arq));
                if (mb_strlen($texto) < 15) $json(['ok' => false, 'message' => P::L('Descreva a evidência ou escolha um arquivo da pasta da auditoria.', 'Describe the evidence or choose a file from the audit folder.', 'Describa la evidencia o elija un archivo de la carpeta de la auditoría.')], 422);
                $prompt = 'Você é auditor de sistemas de gestão. Avalie se a EVIDÊNCIA atende à PERGUNTA do checklist. Classifique como CONFORME, NC_MAIOR (ausência ou falha sistêmica do requisito), NC_MENOR (falha pontual), OBSERVACAO (risco de virar NC) ou MELHORIA (oportunidade), ou NA se não se aplica. Não invente fatos: só o que está na evidência. Retorne só JSON: {"resposta":"CONFORME|NC_MAIOR|NC_MENOR|OBSERVACAO|MELHORIA|NA","justificativa":"...","constatacao":"texto da constatação quando não for CONFORME/NA"}';
                $r = A::ia('REQUISITO: ' . $ck['REQUISITO'] . "\nPERGUNTA: " . $ck['PERGUNTA'] . "\nORIENTAÇÃO: " . $ck['ORIENTACAO'] . "\n\nEVIDÊNCIA:\n" . $texto, 'AUDITORIA_EVIDENCIA', $prompt, $id);
                $resp = strtoupper(trim((string)($r['resposta'] ?? '')));
                AuditService::record(P::$pdo, 'auditoria_gestao', 'IA_EVIDENCIA', 'SUCESSO', 'aud_checklist', (string)$ck['CODCHECKLIST']);
                $json(['ok' => true, 'resposta' => isset(A::RESPOSTAS[$resp]) ? $resp : '', 'justificativa' => mb_substr((string)($r['justificativa'] ?? ''), 0, 2000), 'constatacao' => mb_substr((string)($r['constatacao'] ?? ''), 0, 3000)]);

            case 'constatacao':
                if (!$pode['executar']) $erro(P::L('Constatações só na execução, pelo auditor.', 'Findings only during execution, by the auditor.', 'Hallazgos solo en la ejecución, por el auditor.'));
                $cl = (string)($_POST['CLASSIFICACAO'] ?? '');
                $desc = $txt('DESCRICAO', 10000);
                if (!isset(A::CLASSIFICACAO[$cl]) || $desc === '') $erro(P::L('Informe a classificação e a descrição da constatação.', 'Enter the classification and description.', 'Informe la clasificación y la descripción.'));
                $ck = (int)($_POST['CODCHECKLIST'] ?? 0) ?: null;
                P::ins('aud_constatacao', ['CODAUDITORIA' => $id, 'CODCHECKLIST' => $ck, 'CLASSIFICACAO' => $cl, 'REQUISITO' => $txt('REQUISITO', 200), 'DESCRICAO' => $desc, 'EVIDENCIA' => $txt('EVIDENCIA', 10000), 'CODUSUARIO' => $uid]);
                A::registrar($id, $st, P::L('Constatação registrada', 'Finding recorded', 'Hallazgo registrado') . ' (' . A::rotulo(A::CLASSIFICACAO[$cl][0]) . '): ' . mb_strimwidth($desc, 0, 250, '…'));
                $flash(P::L('Constatação registrada.', 'Finding recorded.', 'Hallazgo registrado.') . (in_array($cl, ['NC_MAIOR', 'NC_MENOR'], true) ? ' ' . P::L('Abra a RNC dela.', 'Open its NCR.', 'Abra su RNC.') : ''));
                header('Location: ' . $link($id, 'constatacoes')); exit;

            case 'constatacao_del':
                if (!$pode['executar']) $erro(P::L('Constatações só na execução, pelo auditor.', 'Findings only during execution, by the auditor.', 'Hallazgos solo en la ejecución, por el auditor.'));
                $c = P::sel('aud_constatacao', ['CODCONSTATACAO' => (int)($_POST['cod'] ?? 0)], 1)[0] ?? null;
                if (!$c || (int)$c['CODAUDITORIA'] !== $id) $erro('Constatação não encontrada.');
                if (!empty($c['CODOCORRENCIA'])) $erro(P::L('A constatação já tem RNC: não pode ser excluída.', 'The finding already has an NCR: it cannot be deleted.', 'El hallazgo ya tiene RNC: no puede eliminarse.'));
                P::del('aud_constatacao', ['CODCONSTATACAO' => (int)$c['CODCONSTATACAO']]);
                A::registrar($id, $st, P::L('Constatação excluída: ', 'Finding deleted: ', 'Hallazgo eliminado: ') . mb_strimwidth((string)$c['DESCRICAO'], 0, 250, '…'));
                $flash(P::L('Constatação excluída.', 'Finding deleted.', 'Hallazgo eliminado.'));
                header('Location: ' . $link($id, 'constatacoes')); exit;

            case 'vincular_rnc':
                if (!$pode['executar'] && !($podeEd && $st === 'E')) $erro(P::L('Sem permissão.', 'No permission.', 'Sin permiso.'));
                $c = P::sel('aud_constatacao', ['CODCONSTATACAO' => (int)($_POST['cod'] ?? 0)], 1)[0] ?? null;
                $oc = (int)($_POST['codocorrencia'] ?? 0);
                $rncs = array_column(P::sel('vw_aud_rnc', ['CODAUDITORIA' => $id], 500), null, 'CODOCORRENCIA');
                if (!$c || (int)$c['CODAUDITORIA'] !== $id || !isset($rncs[$oc])) $erro(P::L('Escolha uma RNC desta auditoria.', 'Choose an NCR of this audit.', 'Elija una RNC de esta auditoría.'));
                P::upd('aud_constatacao', ['CODCONSTATACAO' => (int)$c['CODCONSTATACAO']], ['CODOCORRENCIA' => $oc]);
                A::registrar($id, $st, P::L('RNC ', 'NCR ', 'RNC ') . ($rncs[$oc]['NRANO'] ?: '#' . $oc) . P::L(' ligada à constatação', ' linked to the finding', ' vinculada al hallazgo'));
                $flash(P::L('RNC ligada à constatação.', 'NCR linked to the finding.', 'RNC vinculada al hallazgo.'));
                header('Location: ' . $link($id, 'constatacoes')); exit;

            case 'encerrar':
                if (!$pode['executar']) $erro(P::L('Só o auditor encerra a auditoria.', 'Only the auditor closes the audit.', 'Solo el auditor cierra la auditoría.'));
                if ($f = A::pendenciasEncerramento($id)) $erro(P::L('Ainda não pode encerrar: ', 'Cannot close yet: ', 'Aún no puede cerrar: ') . implode('; ', $f) . '.');
                $status('E', P::L('Auditoria encerrada', 'Audit closed', 'Auditoría cerrada'));
                A::agenda(A::auditoria($id));
                if ($avisar) A::avisar([(int)$aud['CODUSUARIO_CRIADOR']], P::L('Auditoria encerrada #', 'Audit closed #', 'Auditoría cerrada #') . $id, (string)$aud['DSCAUDITORIA'], $id);
                $flash(P::L('Auditoria encerrada.', 'Audit closed.', 'Auditoría cerrada.'));
                header('Location: ' . $link($id)); exit;

            case 'cancelar':
                if (!$pode['cancelar']) $erro(P::L('Sem permissão para cancelar.', 'No permission to cancel.', 'Sin permiso para cancelar.'));
                $mot = $txt('motivo', 500);
                if ($mot === '') $erro(P::L('Informe o motivo do cancelamento.', 'Enter the reason.', 'Informe el motivo de la cancelación.'));
                $status('C', $mot);
                A::agenda(A::auditoria($id));
                $flash(P::L('Auditoria cancelada.', 'Audit cancelled.', 'Auditoría cancelada.'), 'warning');
                header('Location: ' . $link($id)); exit;

            case 'excluir':
                if (!$pode['excluir']) $erro(P::L('Só o planejamento (não enviado ou recusado) pode ser excluído, por quem o criou.', 'Only a plan (not sent or rejected) can be deleted, by its creator.', 'Solo el plan (no enviado o rechazado) puede eliminarse, por quien lo creó.'));
                if (P::sel('vw_aud_rnc', ['CODAUDITORIA' => $id], 1)) $erro(P::L('A auditoria tem RNC: cancele em vez de excluir.', 'The audit has NCRs: cancel it instead.', 'La auditoría tiene RNC: cancélela en vez de eliminar.'));
                foreach ([['aud_constatacao', 'CODCONSTATACAO'], ['aud_checklist', 'CODCHECKLIST'], ['auditoria_planejamentoxavaliacao', 'CODAVALIACAO'], ['auditoria_planejamentoxexecucao', 'CODEXECUCAO'], ['auditoria_planodeacao', 'CODPLANOACAO'], ['auditoria_workflow', 'CODWORKFLOW']] as [$t, $pk])
                    foreach (P::sel($t, ['CODAUDITORIA' => $id], 5000) as $r) P::del($t, [$pk => (int)$r[$pk]]);
                if (P::sel('aud_info', ['CODAUDITORIA' => $id], 1)) P::del('aud_info', ['CODAUDITORIA' => $id]);
                $aud['STATUS'] = 'C'; A::agenda($aud);
                P::del('auditoria_planejamento', ['CODAUDITORIA' => $id]);
                AuditService::record(P::$pdo, 'auditoria_gestao', 'EXCLUIR', 'SUCESSO', 'auditoria_planejamento', (string)$id);
                header('Location: auditoria_gestao.php?excluida=' . $id . ($minhas ? '&minhas=1' : '')); exit;
        }
        $erro(P::L('Ação inválida.', 'Invalid action.', 'Acción inválida.'));
    } catch (Throwable $e) {
        if ($ajax) $json(['ok' => false, 'message' => $e->getMessage()], 500);
        $flash($e->getMessage(), 'danger'); header('Location: ' . ($id ? $link($id) : 'auditoria_gestao.php?novo=1')); exit;
    }
}


// ------------------------------------------------------------------------------------------------ tela (migração 424: padrão do portal)
require_once dirname(__DIR__) . '/components/grid_local.php';
require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
$msg = $_SESSION['aud_flash'] ?? null; unset($_SESSION['aud_flash']);
$fmt = static fn($d) => $d ? date('d/m/Y', strtotime(substr((string)$d, 0, 10))) : '';
$fmtH = static fn($d) => $d ? date('d/m/Y H:i', strtotime((string)$d)) : '';
$nomeU = static fn($c) => $c ? ($usuarios[(int)$c] ?? '#' . $c) : '';
$css = '.aud-cab{display:flex;flex-wrap:wrap;gap:6px 14px;align-items:center;margin:-2px 0 4px}.aud-pessoas{font-size:12px;color:var(--gp-muted,#6c757d)}.aud-pessoas b{color:var(--bs-body-color);font-weight:600}
.aud-etapas{display:flex;gap:0;margin:8px 0 6px;flex-wrap:wrap}.aud-etapa{flex:1;min-width:120px;text-align:center;position:relative;padding-top:22px;font-size:12px;color:#6c757d}
.aud-etapa:before{content:"";position:absolute;top:8px;left:0;right:0;height:3px;background:#dee2e6}.aud-etapa:first-child:before{left:50%}.aud-etapa:last-child:before{right:50%}
.aud-etapa i{position:absolute;top:0;left:50%;transform:translateX(-50%);background:#fff;border-radius:50%;font-size:18px;line-height:18px;color:#adb5bd}
.aud-etapa.feita{color:#198754}.aud-etapa.feita:before{background:#198754}.aud-etapa.feita i{color:#198754}.aud-etapa.atual{color:#0d6efd;font-weight:600}.aud-etapa.atual i{color:#0d6efd}.aud-etapa.parada{color:#dc3545}.aud-etapa.parada i{color:#dc3545}
.aud-proximo{font-size:12px;color:#0b5394;background:#eef5fc;border-radius:6px;padding:5px 10px;margin:0 0 8px}
.aud-barra-frag{margin:0 0 6px}.aud-barra-frag .crud-toolbar{margin:0}.aud-aba{padding:.6rem 0}
.aud-nc{display:flex;align-items:center;gap:2px;white-space:nowrap}.aud-nc span{width:14px;height:6px;border-radius:3px;background:#dee2e6}.aud-nc span.f{background:#2e9d6a}.aud-nc span.a{background:#0d6efd}.aud-nc span.p{background:#d64545}
.aud-aba .menu-grid-scroll{overflow-x:hidden}.aud-aba table.menu-batch-grid{width:100%!important;min-width:0!important;table-layout:fixed!important}.aud-aba .menu-batch-grid td{white-space:normal;overflow-wrap:anywhere}
.aud-ck-sel{min-width:0;width:100%}.aud-ck-ev{min-height:52px;font-size:11px}.aud-ia-res{font-size:11px}.aud-leitura{background:#f6f8f7;border-radius:.5rem;padding:.5rem .75rem;font-size:12px;white-space:pre-wrap}';

// ---------- auditoria excluída (a ficha pode estar num popup): avisa a lista e mostra a mensagem
if (isset($_GET['excluida'])) {
    P::cabecalho(P::L('Auditoria excluída', 'Audit deleted', 'Auditoría eliminada'), 'bi-clipboard2-check', $minhas ? 'minhas_auditorias' : 'auditoria_gestao', $css);
    echo '<div class="alert alert-success py-2 small">' . P::e(P::L('Auditoria #', 'Audit #', 'Auditoría #') . (int)$_GET['excluida'] . P::L(' excluída. Pode fechar esta janela.', ' deleted. You can close this window.', ' eliminada. Puede cerrar esta ventana.')) . '</div>';
    P::rodape('<script>try{new BroadcastChannel("greenportal-auditoria").postMessage({excluida:' . (int)$_GET['excluida'] . '});}catch(e){}</script>');
    exit;
}

// ================================================================================================ DADOS DA FICHA
$A = $aud ?? (['CODAUDITORIA' => 0, 'STATUS' => 'P', 'TIPO' => $fornFiltro ? 'FORNECEDOR' : 'INTERNA'] + ($fornFiltro ? ['CODFORNECEDOR' => $fornFiltro] : []));
$aba = (string)($_GET['aba'] ?? 'plano');
$av = $aud ? P::sel('vw_aud_avaliacao', ['CODAUDITORIA' => $id], 50) : [];
$ex = $aud ? P::sel('vw_aud_execucao', ['CODAUDITORIA' => $id], 50) : [];
$ck = $aud ? P::sel('vw_aud_checklist', ['CODAUDITORIA' => $id], 2000) : [];
usort($ck, static fn($a, $b) => [(int)$a['ORDEM'], (int)$a['CODCHECKLIST']] <=> [(int)$b['ORDEM'], (int)$b['CODCHECKLIST']]);
$cs = $aud ? P::sel('vw_aud_constatacao', ['CODAUDITORIA' => $id], 1000) : [];
$rncs = $aud ? P::sel('vw_aud_rnc', ['CODAUDITORIA' => $id], 500) : [];
$wf = $aud ? P::sel('vw_aud_workflow', ['CODAUDITORIA' => $id], 1000) : [];
usort($wf, static fn($a, $b) => [(string)$a['DATAOCORRENCIA'], (int)$a['CODWORKFLOW']] <=> [(string)$b['DATAOCORRENCIA'], (int)$b['CODWORKFLOW']]);
$arquivos = $aud ? A::arquivos($id) : [];
// liga automaticamente a RNC aberta a partir da constatação (o sumário leva "[AUD <cod>/C<constatação>]") e registra no histórico
$ligou = false;
if ($aud && $podeEd) foreach ($cs as $i => $c) {
    if (!empty($c['CODOCORRENCIA'])) continue;
    foreach ($rncs as $r) if (str_contains((string)$r['IDENTIFICACAO'], '[AUD ' . $id . '/C' . (int)$c['CODCONSTATACAO'] . ']')) {
        try {
            P::upd('aud_constatacao', ['CODCONSTATACAO' => (int)$c['CODCONSTATACAO']], ['CODOCORRENCIA' => (int)$r['CODOCORRENCIA']]);
            A::registrar($id, $st, P::L('RNC ', 'NCR ', 'RNC ') . ($r['NRANO'] ?: '#' . $r['CODOCORRENCIA']) . P::L(' aberta pela constatação', ' opened from the finding', ' abierta por el hallazgo') . ' (' . A::rotulo((A::CLASSIFICACAO[$c['CLASSIFICACAO']] ?? [[$c['CLASSIFICACAO'], '', '']])[0]) . ')');
            $cs[$i]['CODOCORRENCIA'] = (int)$r['CODOCORRENCIA']; $cs[$i]['RNC_NUMERO'] = $r['NRANO']; $cs[$i]['RNC_ETAPA'] = $r['ETAPA']; $ligou = true;
        } catch (Throwable) {}
        break;
    }
}
if ($ligou) { $wf = P::sel('vw_aud_workflow', ['CODAUDITORIA' => $id], 1000); usort($wf, static fn($a, $b) => [(string)$a['DATAOCORRENCIA'], (int)$a['CODWORKFLOW']] <=> [(string)$b['DATAOCORRENCIA'], (int)$b['CODWORKFLOW']]); }
$fornecedores = [];
if (($A['TIPO'] ?? '') === 'FORNECEDOR' || $pode['plano']) {
    try {
        foreach (P::sel('fo_fornecedores', [], 20000) as $f) {
            $cf = (int)($f['CODFORNECEDOR'] ?? 0); if (!$cf) continue;
            $fornecedores[$cf] = $nomeDe($f, ['RAZAOSOCIAL', 'NOMEFANTASIA', 'NOME', 'DSCFORNECEDOR']) ?: '#' . $cf;
        }
        asort($fornecedores, SORT_NATURAL | SORT_FLAG_CASE);
    } catch (Throwable) {}
}
$pend = $aud && $pode['executar'] ? A::pendenciasEncerramento($id) : [];
$rncFin = count(array_filter($rncs, static fn($r) => (string)$r['ETAPA'] === 'Finalizado'));

// ================================================================================================ CABEÇALHO
$titulo = $aud ? P::L('Auditoria', 'Audit', 'Auditoría') . ' #' . $id . ' · ' . $aud['DSCAUDITORIA'] : P::L('Nova auditoria', 'New audit', 'Nueva auditoría');
P::cabecalho($titulo, 'bi-clipboard2-check', $minhas ? 'minhas_auditorias' : 'auditoria_gestao', $css);
greenPortalGridLocalHead();
echo '<link rel="stylesheet" href="../assets/css/botoes-crud.css?v=4">';
echo '<div class="aud-cab">' . ($aud ? A::badge($st) : '');
if ($aud) echo '<span class="aud-pessoas">' . P::e(P::L('Criada por', 'Created by', 'Creada por')) . ' <b>' . P::e($nomeU($aud['CODUSUARIO_CRIADOR']) ?: '—') . '</b> · '
    . P::e(P::L('Avaliador', 'Reviewer', 'Evaluador')) . ' <b>' . P::e($aud['CODUSUARIO_AVALIADOR'] ? $nomeU($aud['CODUSUARIO_AVALIADOR']) : ($grupos[(int)$aud['CODGRUPO_AVALIADORES']] ?? '—')) . '</b> · '
    . P::e(P::L('Auditor', 'Auditor', 'Auditor')) . ' <b>' . P::e($aud['CODUSUARIO_AUDITOR'] ? $nomeU($aud['CODUSUARIO_AUDITOR']) : ($grupos[(int)$aud['CODGRUPO_AUDITORES']] ?? '—')) . '</b>'
    . ($aud['DATADEINICIO'] ? ' · ' . P::e(P::L('Período', 'Period', 'Período')) . ' <b>' . P::e($fmt($aud['DATADEINICIO']) . ($aud['DATADEFIM'] ? ' – ' . $fmt($aud['DATADEFIM']) : '')) . '</b>' : '') . '</span>';
$temFluxo = $aud && ($pode['plano'] || $pode['avaliar'] || $pode['executar'] || $pode['cancelar']);
if ($temFluxo) echo '<div class="form-check form-switch ms-auto small mb-0" title="' . P::e(P::L('Ao mudar de etapa, avisa no sininho quem deve agir (avaliador, auditor ou quem criou).', 'When the stage changes, notifies who must act (reviewer, auditor or creator).', 'Al cambiar de etapa, avisa a quien debe actuar (evaluador, auditor o quien creó).')) . '"><input class="form-check-input" type="checkbox" id="audAvisar" checked><label class="form-check-label" for="audAvisar">'
    . P::e(P::L('Avisar os responsáveis', 'Notify the people in charge', 'Avisar a los responsables')) . '</label></div>';
echo '</div>';
if ($msg) echo '<div class="alert alert-' . P::e($msg[1]) . ' py-2 small my-1" data-aud-flash="' . P::e($msg[1]) . '">' . P::e($msg[0]) . '</div>';

// barra de etapas (item 174): o ciclo da auditoria; o ciclo de cada RNC aparece na aba RNC
if ($aud) {
    $ordem = ['P' => 0, 'R' => 0, 'A' => 1, 'X' => 2, 'E' => 5, 'C' => -1];
    $pos = $ordem[$st] ?? 0;
    if ($st === 'X' && $cs) $pos = 3;
    echo '<div class="aud-etapas">';
    foreach (A::ETAPAS as $i => [$k, $r]) {
        $cls = $st === 'C' ? '' : ($i < $pos ? 'feita' : ($i === $pos ? 'atual' : ''));
        if ($st === 'R' && $i === 1) $cls = 'parada';
        $rot = A::rotulo($r) . ($k === 'N' && $rncs ? ' (' . $rncFin . '/' . count($rncs) . ' ' . P::L('RNC finalizadas', 'NCRs finished', 'RNC finalizadas') . ')' : '');
        echo '<div class="aud-etapa ' . $cls . '"><i class="bi ' . ($cls === 'feita' ? 'bi-check-circle-fill' : ($cls === 'parada' ? 'bi-x-circle-fill' : ($cls === 'atual' ? 'bi-record-circle-fill' : 'bi-circle'))) . '"></i>' . P::e($rot) . '</div>';
    }
    echo '</div>';
    // orientação: o próximo passo e quem faz
    $prox = match ($st) {
        'P' => $pode['plano'] ? P::L('Próximo passo: complete o planejamento (avaliador e data de início) e use Enviar para avaliação.', 'Next step: complete the plan (reviewer and start date) and use Send for review.', 'Próximo paso: complete el plan (evaluador y fecha de inicio) y use Enviar a evaluación.') : P::L('Em planejamento por quem criou a auditoria.', 'Being planned by the creator.', 'En planificación por quien creó la auditoría.'),
        'R' => $pode['plano'] ? P::L('O plano foi recusado: ajuste o planejamento e envie de novo para avaliação.', 'The plan was rejected: adjust it and send it for review again.', 'El plan fue rechazado: ajústelo y envíelo de nuevo a evaluación.') : P::L('Plano recusado: aguardando o ajuste de quem criou.', 'Plan rejected: waiting for the creator to adjust it.', 'Plan rechazado: esperando el ajuste de quien lo creó.'),
        'A' => $pode['avaliar'] ? P::L('Próximo passo: registre a avaliação do plano (aba Avaliação) e use Aprovar ou Recusar.', 'Next step: record the plan review (Review tab) and use Approve or Reject.', 'Próximo paso: registre la evaluación del plan (pestaña Evaluación) y use Aprobar o Rechazar.') : P::L('Aguardando a avaliação do plano.', 'Waiting for the plan review.', 'Esperando la evaluación del plan.'),
        'X' => $pode['executar'] ? ($pend ? P::L('Para encerrar: ', 'To close: ', 'Para cerrar: ') . implode('; ', $pend) . '.' : P::L('Tudo pronto: use Encerrar auditoria.', 'All set: use Close audit.', 'Todo listo: use Cerrar auditoría.')) : P::L('Em execução pelo auditor.', 'Being carried out by the auditor.', 'En ejecución por el auditor.'),
        'E' => P::L('Auditoria encerrada.', 'Audit closed.', 'Auditoría cerrada.'),
        'C' => P::L('Auditoria cancelada: ', 'Audit cancelled: ', 'Auditoría cancelada: ') . (string)(($c = array_values(array_filter($wf, static fn($w) => $w['ETAPA'] === 'C'))) ? end($c)['DSCOBSERVACAO'] : ''),
        default => '',
    };
    if ($st === 'R' && ($rec = array_values(array_filter($wf, static fn($w) => $w['ETAPA'] === 'R')))) $prox .= ' ' . P::L('Motivo: ', 'Reason: ', 'Motivo: ') . end($rec)['DSCOBSERVACAO'];
    if ($prox !== '') echo '<div class="aud-proximo"><i class="bi bi-signpost-split me-1"></i>' . P::e($prox) . '</div>';
}

// ================================================================================================ BARRA DE AÇÕES (padrão: grupos rotulados)
$abaForm = match ($aba) { 'plano' => $pode['plano'], 'avaliacao' => $pode['avaliar'], 'execucao' => $pode['executar'], 'checklist' => $ck && ($pode['checklist'] || ($st === 'X' && $pode['executar'])), default => false };
if (!$aud) $abaForm = $pode['plano'];
$ib = static fn(string $attrs, string $icone, string $titulo, string $classe = 'btn-outline-secondary', bool $desab = false) =>
    '<button type="button" class="btn btn-sm ' . $classe . ' grid-crud-button" ' . $attrs . ($desab ? ' disabled' : '') . ' title="' . P::e($titulo) . '" aria-label="' . P::e($titulo) . '"><i class="bi ' . $icone . '"></i></button>';
$x = '';
if ($pode['excluir']) $x .= $ib('data-aud-fluxo="excluir" data-confirma="' . P::e(P::L('Excluir a auditoria #', 'Delete audit #', '¿Eliminar la auditoría #') . $id . P::L('? Isso não pode ser desfeito.', '? This cannot be undone.', '? No se puede deshacer.')) . '"', 'bi-trash', P::L('Excluir a auditoria', 'Delete the audit', 'Eliminar la auditoría'), 'btn-outline-danger');
if ($aud && $pode['plano']) $x .= $ib('data-aud-fluxo="enviar_avaliacao"', 'bi-send', P::L('Enviar para avaliação', 'Send for review', 'Enviar a evaluación'), 'btn-outline-primary');
if ($pode['avaliar']) $x .= $ib('data-aud-fluxo="aprovar"', 'bi-check2-circle', P::L('Aprovar o plano e enviar para execução', 'Approve the plan and send for execution', 'Aprobar el plan y enviar a ejecución'), 'btn-outline-success')
    . $ib('data-aud-fluxo="recusar" data-motivo="' . P::e(P::L('Motivo da recusa do plano', 'Reason for rejecting the plan', 'Motivo del rechazo del plan')) . '"', 'bi-arrow-counterclockwise', P::L('Recusar o plano (volta ao planejamento)', 'Reject the plan (back to planning)', 'Rechazar el plan (vuelve a planificación)'), 'btn-outline-danger');
if ($pode['executar']) $x .= $ib('data-aud-fluxo="encerrar"', 'bi-flag', $pend ? P::L('Encerrar auditoria — falta: ', 'Close audit — missing: ', 'Cerrar auditoría — falta: ') . implode('; ', $pend) : P::L('Encerrar auditoria', 'Close audit', 'Cerrar auditoría'), 'btn-outline-success', (bool)$pend);
if ($pode['cancelar']) $x .= $ib('data-aud-fluxo="cancelar" data-motivo="' . P::e(P::L('Motivo do cancelamento', 'Reason for cancelling', 'Motivo de la cancelación')) . '"', 'bi-x-circle', P::L('Cancelar a auditoria', 'Cancel the audit', 'Cancelar la auditoría'), 'btn-outline-dark');
if ($aud) {
    $x .= $ib('data-aud-arquivos="' . $id . '"', 'bi-folder2-open', P::L('Arquivos da auditoria', 'Audit files', 'Archivos de la auditoría') . ' (' . count($arquivos) . ')');
    $x .= $ib('data-aud-ir="rnc"', 'bi-exclamation-octagon', P::L('RNCs abertas pela auditoria', 'NCRs opened by the audit', 'RNC abiertas por la auditoría') . ' (' . count($rncs) . ')');
    if ((int)($A['CODFORNECEDOR'] ?? 0) > 0) $x .= $ib('data-aud-fornecedor="' . (int)$A['CODFORNECEDOR'] . '"', 'bi-truck', P::L('Fornecedor auditado (Homologação)', 'Audited supplier (Approval)', 'Proveedor auditado (Homologación)') . ': ' . ($fornecedores[(int)$A['CODFORNECEDOR']] ?? '#' . $A['CODFORNECEDOR']));
    $x .= $ib('data-aud-relatorio="' . $id . '"', 'bi-file-earmark-pdf', P::L('Relatório da auditoria (PDF)', 'Audit report (PDF)', 'Informe de la auditoría (PDF)'), 'btn-outline-danger');
}
echo '<div class="aud-barra-frag"><div class="crud-toolbar menu-grid-toolbar">';
greenPortalCrudButtons(['form_id' => 'audForm', 'transaction' => $minhas ? 'minhas_auditorias' : 'auditoria_gestao', 'extras' => $x, 'grupos' => false,
    'save' => $abaForm, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => false, 'export' => false, 'columns' => false]);
echo '</div></div>';
$rot = greenPortalToolbarGruposRotulos();
$rotFluxo = P::L('Fluxo', 'Workflow', 'Flujo');
greenPortalToolbarGruposEmitir(['fragmento' => '.aud-barra-frag', 'auto' => false, 'rotuloOutros' => $rot['outros'], 'grupos' => [
    ['id' => 'operacoes', 'rotulo' => $rot['operacoes'], 'botoes' => ['[data-crud-action="save"]', '[data-aud-fluxo="excluir"]', '[data-open-transacao-ajuda]']],
    ['id' => 'fluxo', 'rotulo' => $rotFluxo, 'botoes' => ['[data-aud-fluxo="enviar_avaliacao"]', '[data-aud-fluxo="aprovar"]', '[data-aud-fluxo="recusar"]', '[data-aud-fluxo="encerrar"]', '[data-aud-fluxo="cancelar"]']],
    ['id' => 'associacoes', 'rotulo' => $rot['associacoes'], 'botoes' => ['[data-aud-arquivos]', '[data-aud-ir="rnc"]', '[data-aud-fornecedor]']],
    ['id' => 'outros', 'rotulo' => $rot['outros'], 'botoes' => ['[data-aud-relatorio]']],
]]);
// formulário das ações do fluxo (o motivo e a confirmação vêm dos modais)
echo '<form method="post" id="audFluxo" hidden><input type="hidden" name="csrf" value="' . P::e($csrf) . '"><input type="hidden" name="id" value="' . (int)$id . '"><input type="hidden" name="acao" value=""><input type="hidden" name="motivo" value=""><input type="hidden" name="avisar" value=""><input type="hidden" name="cod" value=""><input type="hidden" name="codocorrencia" value=""></form>';

// ================================================================================================ ABAS
$abas = ['plano' => P::L('Planejamento', 'Plan', 'Planificación')];
if ($aud) $abas += ['avaliacao' => P::L('Avaliação', 'Review', 'Evaluación') . ($av ? ' ✓' : ''), 'checklist' => 'Checklist (' . count($ck) . ')', 'execucao' => P::L('Execução', 'Execution', 'Ejecución') . ($ex ? ' ✓' : ''),
    'constatacoes' => P::L('Constatações', 'Findings', 'Hallazgos') . ' (' . count($cs) . ')', 'rnc' => 'RNC (' . count($rncs) . ')', 'historico' => P::L('Histórico', 'History', 'Historial')];
if (!isset($abas[$aba])) $aba = 'plano';
$ageAqui = ['plano' => $pode['plano'], 'avaliacao' => $pode['avaliar'], 'checklist' => $pode['checklist'] || $pode['executar'], 'execucao' => $pode['executar'], 'constatacoes' => $pode['executar']];
echo '<ul class="nav nav-tabs small mt-1">';
foreach ($abas as $k => $r) echo '<li class="nav-item"><a class="nav-link' . ($k === $aba ? ' active' : '') . '" href="' . P::e($aud ? $link($id, $k) : '#') . '">' . P::e($r)
    . (!empty($ageAqui[$k]) && $aud ? ' <i class="bi bi-pencil-square text-success" title="' . P::e(P::L('Você age nesta aba', 'You act on this tab', 'Usted actúa en esta pestaña')) . '"></i>' : '') . '</a></li>';
echo '</ul><div class="aud-aba">';
$ro = static fn(bool $pode) => $pode ? '' : ' disabled';
$hid = '<input type="hidden" name="csrf" value="' . P::e($csrf) . '"><input type="hidden" name="id" value="' . (int)$id . '">';
$lbl = static fn(string $t) => '<label class="form-label small mb-0">' . P::e($t) . '</label>';
$respostas = array_map(static fn($t) => A::rotulo($t), A::RESPOSTAS);
$classif = array_map(static fn($c) => A::rotulo($c[0]), A::CLASSIFICACAO);
$tipoNc = ['INTERNA' => 3, 'EXTERNA' => 3, 'FORNECEDOR' => 1, 'CLIENTE' => 2][$A['TIPO'] ?? 'INTERNA'] ?? 3;

if ($aba === 'plano') {
    $p = $pode['plano'];
    echo '<form method="post" class="row g-2" id="' . ($p ? 'audForm' : 'audPlanoLeitura') . '">' . $hid . '<input type="hidden" name="acao" value="plano">'
        . '<div class="col-md-8">' . $lbl(P::L('Título da auditoria', 'Audit title', 'Título de la auditoría') . ' *') . '<input class="form-control form-control-sm" name="DSCAUDITORIA" maxlength="500" required value="' . P::e($A['DSCAUDITORIA'] ?? '') . '"' . $ro($p) . '></div>'
        . '<div class="col-md-4">' . $lbl(P::L('Tipo', 'Type', 'Tipo')) . '<select class="form-select form-select-sm" name="TIPO" id="audTipoSel"' . $ro($p) . '>' . P::opcoes($tipos, $A['TIPO'] ?? 'INTERNA') . '</select></div>'
        . '<div class="col-md-4">' . $lbl(P::L('Unidade', 'Unit', 'Unidad')) . '<select class="form-select form-select-sm" name="CODUNIDADE"' . $ro($p) . '>' . P::opcoes($unidades, $A['CODUNIDADE'] ?? '', '—') . '</select></div>'
        . '<div class="col-md-4">' . $lbl(P::L('Norma / referência', 'Standard / reference', 'Norma / referencia')) . '<input class="form-control form-control-sm" name="NORMA" maxlength="300" placeholder="ISO 9001:2015, ISO 14001…" value="' . P::e($A['NORMA'] ?? '') . '"' . $ro($p) . '></div>'
        . '<div class="col-md-4" id="audFornBox">' . $lbl(P::L('Fornecedor', 'Supplier', 'Proveedor')) . '<select class="form-select form-select-sm" name="CODFORNECEDOR"' . $ro($p) . '>' . P::opcoes($fornecedores, $A['CODFORNECEDOR'] ?? '', '—') . '</select></div>'
        . '<div class="col-md-6">' . $lbl(P::L('Objetivo', 'Objective', 'Objetivo')) . '<textarea class="form-control form-control-sm" rows="3" name="OBJETIVO"' . $ro($p) . '>' . P::e($A['OBJETIVO'] ?? '') . '</textarea></div>'
        . '<div class="col-md-6">' . $lbl(P::L('Escopo (processos, áreas, requisitos)', 'Scope (processes, areas, requirements)', 'Alcance (procesos, áreas, requisitos)')) . '<textarea class="form-control form-control-sm" rows="3" name="ESCOPO"' . $ro($p) . '>' . P::e($A['ESCOPO'] ?? '') . '</textarea></div>'
        . '<div class="col-md-3">' . $lbl(P::L('Início', 'Start', 'Inicio')) . '<input type="date" class="form-control form-control-sm" name="datadeinicio" value="' . P::e(substr((string)($A['DATADEINICIO'] ?? ''), 0, 10)) . '"' . $ro($p) . '></div>'
        . '<div class="col-md-3">' . $lbl(P::L('Fim', 'End', 'Fin')) . '<input type="date" class="form-control form-control-sm" name="datadefim" value="' . P::e(substr((string)($A['DATADEFIM'] ?? ''), 0, 10)) . '"' . $ro($p) . '></div>'
        . '<div class="col-md-6">' . $lbl(P::L('Local', 'Location', 'Lugar')) . '<input class="form-control form-control-sm" name="localizacao" maxlength="500" value="' . P::e($A['LOCALIZACAO'] ?? '') . '"' . $ro($p) . '></div>'
        . '<div class="col-md-3">' . $lbl(P::L('Avaliador (aprova o plano)', 'Reviewer (approves the plan)', 'Evaluador (aprueba el plan)')) . '<select class="form-select form-select-sm" name="codusuario_avaliador"' . $ro($p) . '>' . P::opcoes($usuarios, $A['CODUSUARIO_AVALIADOR'] ?? '', '—') . '</select></div>'
        . '<div class="col-md-3">' . $lbl(P::L('ou grupo de avaliadores', 'or reviewer group', 'o grupo de evaluadores')) . '<select class="form-select form-select-sm" name="codgrupo_avaliadores"' . $ro($p) . '>' . P::opcoes($grupos, $A['CODGRUPO_AVALIADORES'] ?? '', '—') . '</select></div>'
        . '<div class="col-md-3">' . $lbl(P::L('Auditor líder', 'Lead auditor', 'Auditor líder')) . '<select class="form-select form-select-sm" name="codusuario_auditor"' . $ro($p) . '>' . P::opcoes($usuarios, $A['CODUSUARIO_AUDITOR'] ?? '', '—') . '</select></div>'
        . '<div class="col-md-3">' . $lbl(P::L('ou grupo de auditores', 'or auditor group', 'o grupo de auditores')) . '<select class="form-select form-select-sm" name="codgrupo_auditores"' . $ro($p) . '>' . P::opcoes($grupos, $A['CODGRUPO_AUDITORES'] ?? '', '—') . '</select></div>'
        . '<div class="col-md-4">' . $lbl(P::L('Origem das RNCs abertas', 'Origin of the NCRs opened', 'Origen de las RNC abiertas')) . '<select class="form-select form-select-sm" name="CODORIGEMACAO"' . $ro($p) . '>' . P::opcoes($origens, $A['CODORIGEMACAO'] ?? '', '—') . '</select></div>';
    if ($aud) echo '<div class="col-md-8 small text-muted align-self-end">' . P::e(P::L('Criada por ', 'Created by ', 'Creada por ') . $nomeU($aud['CODUSUARIO_CRIADOR']) . ($aud['DATADECRIACAO'] ? ' · ' . $fmt($aud['DATADECRIACAO']) : '')) . '</div>';
    echo '</form>';
}
if ($aba === 'avaliacao') {
    $u = end($av) ?: [];
    $pa = $pode['avaliar'];
    echo '<p class="small text-muted mb-2">' . P::e(P::L('O avaliador registra a avaliação do plano (Salvar) e então usa Aprovar (vai para execução) ou Recusar (volta ao planejamento), no grupo Fluxo da barra.', 'The reviewer records the plan review (Save) and then uses Approve (goes to execution) or Reject (back to planning), in the Workflow group of the bar.', 'El evaluador registra la evaluación del plan (Guardar) y luego usa Aprobar (pasa a ejecución) o Rechazar (vuelve a planificación), en el grupo Flujo de la barra.')) . '</p>';
    echo '<form method="post" class="row g-2" id="' . ($pa ? 'audForm' : 'audAvLeitura') . '">' . $hid . '<input type="hidden" name="acao" value="avaliacao"><div class="col-md-9">' . $lbl(P::L('Avaliação do plano', 'Plan review', 'Evaluación del plan') . ' *') . '<textarea class="form-control form-control-sm" rows="4" name="DSCAVALIACAO" maxlength="500"' . $ro($pa) . '>' . P::e($u['DSCAVALIACAO'] ?? '') . '</textarea></div>'
        . '<div class="col-md-3">' . $lbl(P::L('Data', 'Date', 'Fecha')) . '<input type="date" class="form-control form-control-sm" name="DATAAVALIACAO" value="' . P::e(substr((string)($u['DATAAVALIACAO'] ?? date('Y-m-d')), 0, 10)) . '"' . $ro($pa) . '>'
        . ($u ? '<div class="small text-muted mt-1">' . P::e($u['AVALIADOR'] ?? '') . '</div>' : '') . '</div></form>';
}
if ($aba === 'execucao') {
    $u = end($ex) ?: [];
    $pe = $pode['executar'];
    echo '<form method="post" class="row g-2" id="' . ($pe ? 'audForm' : 'audExLeitura') . '">' . $hid . '<input type="hidden" name="acao" value="execucao"><div class="col-md-12">' . $lbl(P::L('Observações da execução (como foi a auditoria, pontos fortes, conclusão)', 'Execution notes (how it went, strengths, conclusion)', 'Observaciones de la ejecución (cómo fue, puntos fuertes, conclusión)')) . '<textarea class="form-control form-control-sm" rows="5" name="OBSERVACAO"' . $ro($pe) . '>' . P::e($u['OBSERVACAO'] ?? '') . '</textarea></div>'
        . '<div class="col-md-3">' . $lbl(P::L('Data da execução', 'Execution date', 'Fecha de ejecución')) . '<input type="date" class="form-control form-control-sm" name="DATAEXECUCAO" value="' . P::e(substr((string)($u['DATAEXECUCAO'] ?? ''), 0, 10)) . '"' . $ro($pe) . '></div>'
        . '<div class="col-md-3">' . $lbl(P::L('Data do encerramento (reunião final)', 'Closing date (closing meeting)', 'Fecha de cierre (reunión final)')) . '<input type="date" class="form-control form-control-sm" name="DATAENCERRAMENTO" value="' . P::e(substr((string)($u['DATAENCERRAMENTO'] ?? ''), 0, 10)) . '"' . $ro($pe) . '></div>'
        . ($u ? '<div class="col-md-6 small text-muted align-self-end">' . P::e($u['AUDITOR'] ?? '') . '</div>' : '') . '</form>';
}

// ---------- Checklist: grid local (filtro por coluna, Buscar no grid); ações na barra para a linha selecionada
if ($aba === 'checklist') {
    $pc = $pode['checklist']; $resp = $st === 'X' && $pode['executar'];
    $edita = $pc || $resp;
    if ($resp && $arquivos) echo '<div class="d-flex align-items-center gap-2 small mb-2"><i class="bi bi-paperclip"></i>' . P::e(P::L('Arquivo de evidência para a IA (opcional):', 'Evidence file for the AI (optional):', 'Archivo de evidencia para la IA (opcional):'))
        . '<select class="form-select form-select-sm" style="max-width:320px" id="audArqEv">' . P::opcoes(array_combine($arquivos, $arquivos) ?: [], '', '—') . '</select></div>';
    $rows = [];
    $comConst = array_map('intval', array_filter(array_column($cs, 'CODCHECKLIST')));
    foreach ($ck as $c) {
        $k = (int)$c['CODCHECKLIST'];
        $r = (string)($c['RESPOSTA'] ?? '');
        $sel = '<span class="d-none">' . P::e($respostas[$r] ?? '') . '</span><select class="form-select form-select-sm aud-ck-sel" name="ck[' . $k . '][RESPOSTA]"' . $ro($resp) . '><option value="" label="—"></option>';
        foreach ($respostas as $rk => $rv) $sel .= '<option value="' . P::e($rk) . '" label="' . P::e($rv) . '"' . ($rk === $r ? ' selected' : '') . '></option>';
        $sel .= '</select>';
        $acoes = ($pc && !in_array($k, $comConst, true) ? '<button type="button" data-linha-acao="excluir" data-aud-ck-del="' . $k . '"></button>' : '')
            . ($resp ? '<button type="button" data-linha-acao="iaev" data-aud-ck-iaev="' . $k . '"></button><button type="button" data-linha-acao="constatar" data-aud-ck-constatar="' . $k . '"></button>' : '');
        $rows[] = ['attrs' => 'data-ck="' . $k . '"', 'cells' => [
            'ORDEM' => ['text' => (string)(int)$c['ORDEM'], 'sort' => sprintf('%08d', (int)$c['ORDEM'])],
            'CLAUSULA' => ['text' => (string)$c['REQUISITO']],
            'PERGUNTA' => ['text' => (string)$c['PERGUNTA'] . ' ' . (string)$c['ORIENTACAO'], 'html' => P::e($c['PERGUNTA']) . ($c['ORIENTACAO'] ? '<div class="small text-muted">' . P::e($c['ORIENTACAO']) . '</div>' : '') . greenPortalGridLocalLinha($acoes)],
            'ORIGEM' => ['text' => $c['ORIGEM'] === 'IA' ? 'IA' : P::L('Manual', 'Manual', 'Manual')],
            'RESPOSTA' => ['text' => $respostas[$r] ?? '', 'html' => $sel],
            'EVIDENCIA' => ['text' => (string)($c['EVIDENCIA'] ?? ''), 'html' => '<textarea class="form-control form-control-sm aud-ck-ev" name="ck[' . $k . '][EVIDENCIA]"' . $ro($edita) . '>' . P::e($c['EVIDENCIA'] ?? '') . '</textarea><div class="aud-ia-res" data-ia-res></div>'],
            'RESPONDIDO' => ['text' => $c['RESPONDIDO_EM'] ? trim(($c['USUARIO'] ?? '') . ' · ' . $fmt($c['RESPONDIDO_EM'])) : '', 'sort' => (string)($c['RESPONDIDO_EM'] ?? '')],
        ]];
    }
    $extras = ($pc ? $ib('data-aud-ck-ia', 'bi-stars', P::L('Gerar perguntas com IA (a partir da norma ou de um arquivo da pasta)', 'Generate questions with AI (from the standard or a folder file)', 'Generar preguntas con IA (desde la norma o un archivo de la carpeta)'), 'btn-outline-primary') : '')
        . ($resp ? '<button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-grid-acao-linha="iaev" disabled title="' . P::e(P::L('Avaliar a evidência da pergunta selecionada com IA', 'Assess the evidence of the selected question with AI', 'Evaluar la evidencia de la pregunta seleccionada con IA')) . '"><i class="bi bi-magic"></i></button>'
            . '<button type="button" class="btn btn-sm btn-outline-danger grid-crud-button" data-grid-acao-linha="constatar" disabled title="' . P::e(P::L('Registrar constatação da pergunta selecionada', 'Record a finding for the selected question', 'Registrar hallazgo de la pregunta seleccionada')) . '"><i class="bi bi-flag"></i></button>' : '');
    if ($edita && $ck) echo '<form method="post" id="audForm">' . $hid . '<input type="hidden" name="acao" value="checklist_salvar">';
    greenPortalGridLocal([
        'transaction' => $minhas ? 'minhas_auditorias' : 'auditoria_gestao', 'fragment_class' => 'aud-ck-grid', 'id' => 'audCkGrid', 'page_size' => 50,
        'columns' => [
            'ORDEM' => ['label' => '#', 'filter' => 'none', 'width' => '50px'],
            'CLAUSULA' => ['label' => P::L('Cláusula', 'Clause', 'Cláusula'), 'width' => '90px'],
            'PERGUNTA' => ['label' => P::L('Pergunta', 'Question', 'Pregunta')],
            'ORIGEM' => ['label' => P::L('Origem', 'Source', 'Origen'), 'filter' => 'select', 'width' => '90px'],
            'RESPOSTA' => ['label' => P::L('Resposta', 'Answer', 'Respuesta'), 'filter' => 'select', 'width' => '150px'],
            'EVIDENCIA' => ['label' => P::L('Evidência', 'Evidence', 'Evidencia'), 'width' => '32%'],
            'RESPONDIDO' => ['label' => P::L('Respondida por', 'Answered by', 'Respondida por'), 'width' => '140px'],
        ],
        'rows' => $rows, 'default_sort' => ['ORDEM', 'asc'], 'extras' => $extras,
        'empty' => $pc ? P::L('Nenhuma pergunta ainda: use Novo ou Gerar perguntas com IA.', 'No questions yet: use New or Generate questions with AI.', 'Ninguna pregunta aún: use Nuevo o Generar preguntas con IA.') : P::L('Nenhuma pergunta.', 'No questions.', 'Ninguna pregunta.'),
        'buttons' => ['reload' => false, 'columns' => true, 'new' => $pc, 'new_attribute' => 'data-aud-ck-nova', 'delete' => $pc, 'delete_attribute' => 'data-grid-acao-linha="excluir"'],
    ]);
    if ($edita && $ck) echo '</form>';
    if ($edita && $ck) echo '<div class="small text-muted mt-1">' . P::e(P::L('Respostas e evidências: altere nas linhas e use Salvar na barra de cima.', 'Answers and evidence: change them in the rows and use Save in the top bar.', 'Respuestas y evidencias: cámbielas en las filas y use Guardar en la barra de arriba.')) . '</div>';
    greenPortalToolbarGruposEmitir(['fragmento' => '.aud-ck-grid', 'auto' => false, 'rotuloOutros' => $rot['outros'], 'grupos' => [
        ['id' => 'operacoes', 'rotulo' => $rot['operacoes'], 'botoes' => ['[data-crud-action="new"]', '[data-crud-action="delete"]', '[data-crud-action="columns"]', '[data-open-transacao-ajuda]']],
        ['id' => 'avaliacao', 'rotulo' => P::L('Inteligência artificial', 'Artificial intelligence', 'Inteligencia artificial'), 'botoes' => ['[data-aud-ck-ia]', '[data-grid-acao-linha="iaev"]']],
        ['id' => 'associacoes', 'rotulo' => $rot['associacoes'], 'botoes' => ['[data-grid-acao-linha="constatar"]']],
    ]]);
}

// ---------- Constatações: grid local; Nova (modal), Excluir, Abrir RNC, Ligar a uma RNC, Ver a RNC
if ($aba === 'constatacoes') {
    $pe = $pode['executar']; $podeRnc = $pe || ($podeEd && $st === 'E');
    $semRnc = array_values(array_filter($rncs, static fn($r) => !in_array((int)$r['CODOCORRENCIA'], array_map('intval', array_column($cs, 'CODOCORRENCIA')), true)));
    $rows = [];
    foreach ($cs as $c) {
        $cl = A::CLASSIFICACAO[$c['CLASSIFICACAO']] ?? [[$c['CLASSIFICACAO'], $c['CLASSIFICACAO'], $c['CLASSIFICACAO']], 'secondary'];
        $nc = in_array($c['CLASSIFICACAO'], ['NC_MAIOR', 'NC_MENOR'], true);
        $cod = (int)$c['CODCONSTATACAO'];
        $prefill = ['IDENTIFICACAO' => mb_substr('[AUD ' . $id . '/C' . $cod . '] ' . ($c['REQUISITO'] ? $c['REQUISITO'] . ' – ' : '') . $c['DESCRICAO'], 0, 500),
            'DESCRICAO' => mb_substr(P::L('Auditoria', 'Audit', 'Auditoría') . ' #' . $id . ' – ' . $A['DSCAUDITORIA'] . ($A['NORMA'] ? ' (' . $A['NORMA'] . ')' : '') . "\n" . A::rotulo($cl[0]) . ($c['REQUISITO'] ? ' – ' . $c['REQUISITO'] : '') . "\n\n" . $c['DESCRICAO'] . ($c['EVIDENCIA'] ? "\n\n" . P::L('Evidência: ', 'Evidence: ', 'Evidencia: ') . $c['EVIDENCIA'] : ''), 0, 5000),
            'CODAUDITORIA' => $id] + ($A['CODUNIDADE'] ? ['CODUNIDADE' => (int)$A['CODUNIDADE']] : []) + ($A['CODFORNECEDOR'] && $tipoNc === 1 ? ['CODFORNECEDOR' => (int)$A['CODFORNECEDOR']] : []) + ($A['CODORIGEMACAO'] ? ['CODORIGEMACAO' => (int)$A['CODORIGEMACAO']] : []);
        $urlRnc = 'naoconformidades_ocorrencia.php?tipo=' . $tipoNc . '&prefill=' . rawurlencode(json_encode($prefill, JSON_UNESCAPED_UNICODE));
        $acoes = ($c['CODOCORRENCIA'] ? '<button type="button" data-linha-acao="verrnc" data-aud-rnc-ver="' . (int)$c['CODOCORRENCIA'] . '" data-titulo="RNC ' . P::e($c['RNC_NUMERO'] ?: '#' . $c['CODOCORRENCIA']) . '"></button>' : '')
            . (!$c['CODOCORRENCIA'] && $nc && $podeRnc ? '<button type="button" data-linha-acao="abrirrnc" data-aud-rnc-abrir="' . P::e($urlRnc) . '"></button>' . ($semRnc ? '<button type="button" data-linha-acao="vincular" data-aud-vincular="' . $cod . '"></button>' : '') : '')
            . ($pe && !$c['CODOCORRENCIA'] ? '<button type="button" data-linha-acao="excluir" data-aud-cs-del="' . $cod . '"></button>' : '');
        $rnc = $c['CODOCORRENCIA'] ? 'RNC ' . ($c['RNC_NUMERO'] ?: '#' . $c['CODOCORRENCIA']) . ' · ' . $c['RNC_ETAPA'] : ($nc ? P::L('Sem RNC', 'No NCR', 'Sin RNC') : '');
        $rows[] = ['attrs' => 'data-cs="' . $cod . '"', 'cells' => [
            'CLASSIFICACAO' => ['text' => A::rotulo($cl[0]), 'html' => '<span class="badge text-bg-' . $cl[1] . '">' . P::e(A::rotulo($cl[0])) . '</span>' . greenPortalGridLocalLinha($acoes)],
            'CLAUSULA' => ['text' => (string)$c['REQUISITO']],
            'DESCRICAO' => ['text' => (string)$c['DESCRICAO']],
            'EVIDENCIA' => ['text' => (string)($c['EVIDENCIA'] ?? '')],
            'RNC' => ['text' => $rnc, 'html' => $c['CODOCORRENCIA'] ? '<span class="badge text-bg-' . ((string)$c['RNC_ETAPA'] === 'Finalizado' ? 'success' : 'warning') . '">' . P::e($rnc) . '</span>' : ($nc ? '<span class="text-danger small"><i class="bi bi-exclamation-triangle me-1"></i>' . P::e($rnc) . '</span>' : '')],
            'REGISTRO' => ['text' => trim(($c['USUARIO'] ?? '') . ' · ' . $fmt($c['CRIADO_EM'])), 'sort' => (string)$c['CRIADO_EM']],
        ]];
    }
    $extras = ($podeRnc ? '<button type="button" class="btn btn-sm btn-danger grid-crud-button" data-grid-acao-linha="abrirrnc" disabled title="' . P::e(P::L('Abrir a RNC da não conformidade selecionada (já preenchida e ligada à auditoria)', 'Open the NCR of the selected nonconformity (prefilled and linked to the audit)', 'Abrir la RNC de la no conformidad seleccionada (ya completada y vinculada a la auditoría)')) . '"><i class="bi bi-exclamation-octagon"></i></button>'
            . ($semRnc ? '<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-grid-acao-linha="vincular" disabled title="' . P::e(P::L('Ligar a constatação selecionada a uma RNC já aberta por esta auditoria', 'Link the selected finding to an NCR already opened by this audit', 'Vincular el hallazgo seleccionado a una RNC ya abierta por esta auditoría')) . '"><i class="bi bi-link-45deg"></i></button>' : '') : '')
        . '<button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-grid-acao-linha="verrnc" disabled title="' . P::e(P::L('Abrir a RNC da constatação selecionada', 'Open the NCR of the selected finding', 'Abrir la RNC del hallazgo seleccionado')) . '"><i class="bi bi-box-arrow-up-right"></i></button>';
    if ($pe) echo '<div class="small text-muted mb-1">' . P::e(P::L('Cada não conformidade (maior ou menor) abre uma RNC: selecione a linha e use Abrir RNC. Salve a RNC e volte: ela é ligada aqui sozinha.', 'Each nonconformity (major or minor) opens an NCR: select the row and use Open NCR. Save the NCR and come back: it is linked here automatically.', 'Cada no conformidad (mayor o menor) abre una RNC: seleccione la fila y use Abrir RNC. Guarde la RNC y vuelva: se vincula aquí sola.')) . '</div>';
    greenPortalGridLocal([
        'transaction' => $minhas ? 'minhas_auditorias' : 'auditoria_gestao', 'fragment_class' => 'aud-cs-grid', 'id' => 'audCsGrid',
        'columns' => [
            'CLASSIFICACAO' => ['label' => P::L('Classificação', 'Classification', 'Clasificación'), 'filter' => 'select', 'width' => '170px'],
            'CLAUSULA' => ['label' => P::L('Cláusula', 'Clause', 'Cláusula'), 'width' => '90px'],
            'DESCRICAO' => ['label' => P::L('Constatação', 'Finding', 'Hallazgo')],
            'EVIDENCIA' => ['label' => P::L('Evidência', 'Evidence', 'Evidencia'), 'width' => '26%'],
            'RNC' => ['label' => 'RNC', 'filter' => 'select', 'width' => '190px'],
            'REGISTRO' => ['label' => P::L('Registrada por', 'Recorded by', 'Registrada por'), 'width' => '150px'],
        ],
        'rows' => $rows, 'extras' => $extras, 'double_click_action' => 'verrnc',
        'empty' => P::L('Nenhuma constatação.', 'No findings.', 'Ningún hallazgo.'),
        'buttons' => ['reload' => false, 'columns' => true, 'new' => $pe, 'new_attribute' => 'data-aud-cs-nova', 'delete' => $pe, 'delete_attribute' => 'data-grid-acao-linha="excluir"'],
    ]);
    greenPortalToolbarGruposEmitir(['fragmento' => '.aud-cs-grid', 'auto' => false, 'rotuloOutros' => $rot['outros'], 'grupos' => [
        ['id' => 'operacoes', 'rotulo' => $rot['operacoes'], 'botoes' => ['[data-crud-action="new"]', '[data-crud-action="delete"]', '[data-crud-action="columns"]', '[data-open-transacao-ajuda]']],
        ['id' => 'fluxo', 'rotulo' => 'RNC', 'botoes' => ['[data-grid-acao-linha="abrirrnc"]', '[data-grid-acao-linha="vincular"]', '[data-grid-acao-linha="verrnc"]']],
    ]]);
}

// ---------- RNC: grid local com o fluxo de cada RNC (o ciclo da RNC é o do módulo de Não Conformidades)
if ($aba === 'rnc') {
    $etNc = ['Em criação', 'Com o Gestor', 'Em Analise da Causa Raiz', 'Plano de Ação', 'Avaliação da Eficacia', 'Finalizado'];
    $posNc = ['Em criação' => 0, 'Com o Gestor' => 1, 'Recusado pelo Gestor' => 1, 'Em Analise da Causa Raiz' => 2, 'Plano de Ação' => 3, 'Avaliação da Eficacia' => 4, 'Recusado pela Avaliacao da Eficacia' => 4, 'Finalizado' => 6];
    $rows = [];
    foreach ($rncs as $r) {
        $et = (string)$r['ETAPA']; $p = $posNc[$et] ?? 0; $parada = str_starts_with($et, 'Recusado');
        $barra = '<span class="aud-nc" title="' . P::e($et) . '">';
        foreach ($etNc as $i => $nome) $barra .= '<span class="' . ($i < $p ? 'f' : ($i === $p ? ($parada ? 'p' : 'a') : '')) . '" title="' . P::e($nome) . '"></span>';
        $barra .= '</span>';
        $num = $r['NRANO'] ?: '#' . $r['CODOCORRENCIA'];
        $rows[] = ['cells' => [
            'NUMERO' => ['text' => (string)$num, 'sort' => sprintf('%010d', (int)$r['CODOCORRENCIA'])],
            'SUMARIO' => ['text' => (string)$r['IDENTIFICACAO']],
            'ETAPA' => ['text' => $et, 'html' => '<span class="badge text-bg-' . ($et === 'Finalizado' ? 'success' : ($parada ? 'danger' : 'warning')) . '">' . P::e($et) . '</span>'],
            'FLUXO' => ['text' => '', 'html' => $barra],
            'ABERTA' => ['text' => $fmt($r['DATA_CRIACAO']), 'sort' => (string)$r['DATA_CRIACAO']],
            'FINALIZADA' => ['text' => $fmt($r['DATA_FINALIZACAO'] ?? null), 'sort' => (string)($r['DATA_FINALIZACAO'] ?? '')],
        ], 'open' => ['url' => 'naoconformidades_ocorrencia.php?codigo=' . (int)$r['CODOCORRENCIA'], 'title' => 'RNC ' . $num]];
    }
    echo '<p class="small text-muted mb-1">' . P::e(P::L('Não conformidades abertas por esta auditoria (follow-up), cada uma com o próprio fluxo. A auditoria encerra quando todas estiverem Finalizadas.', 'Nonconformities opened by this audit (follow-up), each with its own workflow. The audit closes when all are Finished.', 'No conformidades abiertas por esta auditoría (seguimiento), cada una con su propio flujo. La auditoría se cierra cuando todas estén Finalizadas.')) . '</p>';
    greenPortalGridLocal([
        'transaction' => $minhas ? 'minhas_auditorias' : 'auditoria_gestao', 'fragment_class' => 'aud-rnc-grid', 'id' => 'audRncGrid', 'open_button' => true,
        'columns' => [
            'NUMERO' => ['label' => 'RNC', 'width' => '100px'],
            'SUMARIO' => ['label' => P::L('Sumário', 'Summary', 'Resumen')],
            'ETAPA' => ['label' => P::L('Etapa', 'Stage', 'Etapa'), 'filter' => 'select', 'width' => '200px'],
            'FLUXO' => ['label' => P::L('Fluxo da RNC', 'NCR workflow', 'Flujo de la RNC'), 'filter' => 'none', 'width' => '120px'],
            'ABERTA' => ['label' => P::L('Aberta em', 'Opened on', 'Abierta el'), 'width' => '100px'],
            'FINALIZADA' => ['label' => P::L('Finalizada em', 'Finished on', 'Finalizada el'), 'width' => '110px'],
        ],
        'rows' => $rows, 'default_sort' => ['NUMERO', 'asc'],
        'empty' => P::L('Nenhuma RNC ligada.', 'No NCR linked.', 'Ninguna RNC vinculada.'),
        'buttons' => ['reload' => false, 'columns' => true],
    ]);
}

// ---------- Histórico: grid local
if ($aba === 'historico') {
    $rows = [];
    foreach ($wf as $w) $rows[] = ['cells' => [
        'DATA' => ['text' => $fmtH($w['DATAOCORRENCIA']), 'sort' => (string)$w['DATAOCORRENCIA'] . sprintf('%010d', (int)$w['CODWORKFLOW'])],
        'ETAPA' => ['text' => A::situacao((string)$w['ETAPA']), 'html' => A::badge((string)$w['ETAPA'])],
        'OCORRENCIA' => ['text' => (string)$w['DSCOBSERVACAO']],
        'USUARIO' => ['text' => (string)($w['USUARIO'] ?? '')],
    ]];
    greenPortalGridLocal([
        'transaction' => $minhas ? 'minhas_auditorias' : 'auditoria_gestao', 'fragment_class' => 'aud-wf-grid', 'id' => 'audWfGrid', 'page_size' => 50,
        'columns' => [
            'DATA' => ['label' => P::L('Data', 'Date', 'Fecha'), 'width' => '130px'],
            'ETAPA' => ['label' => P::L('Etapa', 'Stage', 'Etapa'), 'filter' => 'select', 'width' => '150px'],
            'OCORRENCIA' => ['label' => P::L('Ocorrência', 'Event', 'Evento')],
            'USUARIO' => ['label' => P::L('Usuário', 'User', 'Usuario'), 'filter' => 'select', 'width' => '180px'],
        ],
        'rows' => $rows, 'default_sort' => ['DATA', 'desc'],
        'empty' => P::L('Sem histórico.', 'No history.', 'Sin historial.'),
        'buttons' => ['reload' => false, 'columns' => true],
    ]);
    echo '<div class="small text-muted mt-1">' . P::e(P::L('As alterações de campos ficam também em Administração › Logs do sistema.', 'Field changes are also in Administration › System logs.', 'Los cambios de campos también están en Administración › Registros del sistema.')) . '</div>';
}
echo '</div>';

// ================================================================================================ MODAIS (no lugar dos diálogos do navegador)
$modal = static fn(string $id, string $titulo, string $corpo, string $botao, string $cls = 'btn-success', string $extraAttr = '') =>
    '<div class="modal fade" id="' . $id . '" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered' . ($id === 'audCkIaModal' || $id === 'audCsModal' ? ' modal-lg' : '') . '"><div class="modal-content">'
    . '<div class="modal-header py-2"><h6 class="modal-title" data-modal-titulo>' . P::e($titulo) . '</h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="' . P::e(P::L('Fechar', 'Close', 'Cerrar')) . '"></button></div>'
    . '<div class="modal-body">' . $corpo . '</div><div class="modal-footer py-2"><span class="small me-auto" data-modal-msg></span><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">' . P::e(P::L('Voltar', 'Back', 'Volver')) . '</button>'
    . '<button type="button" class="btn btn-sm ' . $cls . '" data-modal-ok' . $extraAttr . '>' . P::e($botao) . '</button></div></div></div></div>';
echo $modal('audMotivoModal', '', '<textarea class="form-control form-control-sm" rows="3" maxlength="500" data-motivo-texto></textarea>', P::L('Confirmar', 'Confirm', 'Confirmar'));
echo $modal('audConfirmaModal', P::L('Confirmar', 'Confirm', 'Confirmar'), '<p class="mb-0 small" data-confirma-texto></p>', P::L('Confirmar', 'Confirm', 'Confirmar'), 'btn-danger');
if ($aud && $aba === 'checklist' && $pode['checklist']) {
    echo $modal('audCkNovaModal', P::L('Nova pergunta do checklist', 'New checklist question', 'Nueva pregunta del checklist'),
        '<form method="post" id="audCkNovaForm" class="row g-2">' . $hid . '<input type="hidden" name="acao" value="checklist_add"><div class="col-md-3">' . $lbl(P::L('Cláusula', 'Clause', 'Cláusula')) . '<input class="form-control form-control-sm" name="REQUISITO" maxlength="200"></div>'
        . '<div class="col-md-9">' . $lbl(P::L('Pergunta', 'Question', 'Pregunta') . ' *') . '<input class="form-control form-control-sm" name="PERGUNTA" maxlength="1000" required></div><div class="col-12">' . $lbl(P::L('O que verificar', 'What to check', 'Qué verificar')) . '<input class="form-control form-control-sm" name="ORIENTACAO" maxlength="2000"></div></form>',
        P::L('Incluir', 'Add', 'Incluir'));
    echo $modal('audCkIaModal', P::L('Gerar perguntas com IA', 'Generate questions with AI', 'Generar preguntas con IA'),
        '<p class="small text-muted">' . P::e(P::L('Cole o trecho da norma/procedimento ou escolha um arquivo da pasta da auditoria (botão Arquivos). As perguntas entram no fim do checklist para você revisar.', 'Paste the standard/procedure text or choose a file from the audit folder (Files button). The questions go to the end of the checklist for you to review.', 'Pegue el texto de la norma/procedimiento o elija un archivo de la carpeta de la auditoría (botón Archivos). Las preguntas van al final del checklist para revisar.')) . '</p>'
        . '<div class="row g-2"><div class="col-md-9">' . $lbl(P::L('Arquivo da pasta', 'Folder file', 'Archivo de la carpeta')) . '<select class="form-select form-select-sm" id="iaArq">' . P::opcoes(array_combine($arquivos, $arquivos) ?: [], '', '—') . '</select></div>'
        . '<div class="col-md-3">' . $lbl(P::L('Perguntas', 'Questions', 'Preguntas')) . '<input type="number" class="form-control form-control-sm" id="iaQtd" min="5" max="40" value="15"></div>'
        . '<div class="col-12">' . $lbl(P::L('Texto da norma/procedimento (opcional se escolher o arquivo)', 'Standard/procedure text (optional if a file is chosen)', 'Texto de la norma/procedimiento (opcional si elige archivo)')) . '<textarea class="form-control form-control-sm" rows="7" id="iaTexto"></textarea></div></div>',
        P::L('Gerar', 'Generate', 'Generar'), 'btn-primary');
}
if ($aud && $aba === 'constatacoes' && $pode['executar']) {
    $pre = (int)($_GET['ck'] ?? 0); $ckPre = $pre ? (array_column($ck, null, 'CODCHECKLIST')[$pre] ?? null) : null;
    echo $modal('audCsModal', P::L('Nova constatação', 'New finding', 'Nuevo hallazgo'),
        '<form method="post" id="audCsForm" class="row g-2"' . ($ckPre ? ' data-abrir' : '') . '>' . $hid . '<input type="hidden" name="acao" value="constatacao"><input type="hidden" name="CODCHECKLIST" value="' . ($ckPre ? $pre : '') . '">'
        . '<div class="col-md-5">' . $lbl(P::L('Classificação', 'Classification', 'Clasificación')) . '<select class="form-select form-select-sm" name="CLASSIFICACAO">' . P::opcoes($classif, in_array($ckPre['RESPOSTA'] ?? '', ['NC_MAIOR', 'NC_MENOR', 'OBSERVACAO', 'MELHORIA'], true) ? $ckPre['RESPOSTA'] : 'NC_MENOR') . '</select></div>'
        . '<div class="col-md-3">' . $lbl(P::L('Cláusula', 'Clause', 'Cláusula')) . '<input class="form-control form-control-sm" name="REQUISITO" maxlength="200" value="' . P::e($ckPre['REQUISITO'] ?? '') . '"></div>'
        . ($ckPre ? '<div class="col-12 small text-muted"><b>' . P::e(P::L('Pergunta:', 'Question:', 'Pregunta:')) . '</b> ' . P::e($ckPre['PERGUNTA']) . '</div>' : '')
        . '<div class="col-12">' . $lbl(P::L('Descrição da constatação (o que foi encontrado)', 'Finding description (what was found)', 'Descripción del hallazgo (lo que se encontró)') . ' *') . '<textarea class="form-control form-control-sm" rows="3" name="DESCRICAO" required>' . P::e((string)($_GET['txt'] ?? '')) . '</textarea></div>'
        . '<div class="col-12">' . $lbl(P::L('Evidência', 'Evidence', 'Evidencia')) . '<textarea class="form-control form-control-sm" rows="3" name="EVIDENCIA">' . P::e($ckPre['EVIDENCIA'] ?? '') . '</textarea></div></form>',
        P::L('Registrar', 'Record', 'Registrar'));
    $semRnc = array_values(array_filter($rncs, static fn($r) => !in_array((int)$r['CODOCORRENCIA'], array_map('intval', array_column($cs, 'CODOCORRENCIA')), true)));
    if ($semRnc) echo $modal('audVincModal', P::L('Ligar a uma RNC', 'Link to an NCR', 'Vincular a una RNC'),
        '<select class="form-select form-select-sm" data-vinc-rnc>' . P::opcoes(array_combine(array_map('intval', array_column($semRnc, 'CODOCORRENCIA')), array_map(static fn($r) => ($r['NRANO'] ?: '#' . $r['CODOCORRENCIA']) . ' – ' . mb_strimwidth((string)$r['IDENTIFICACAO'], 0, 70, '…'), $semRnc)), '', '—') . '</select>',
        P::L('Ligar', 'Link', 'Vincular'));
}

// ================================================================================================ SCRIPTS
$C = ['csrf' => $csrf, 'id' => $id, 'base' => 'auditoria_gestao.php' . ($minhas ? '?minhas=1&' : '?'), 'mudou' => $msg && in_array($msg[1], ['success', 'warning'], true),
    'aguarde' => P::L('A IA está trabalhando…', 'The AI is working…', 'La IA está trabajando…'), 'motivoFalta' => P::L('Informe o motivo.', 'Enter the reason.', 'Informe el motivo.'),
    'arquivos' => P::L('Arquivos da auditoria', 'Audit files', 'Archivos de la auditoría'), 'relatorio' => P::L('Relatório da auditoria', 'Audit report', 'Informe de la auditoría'),
    'fornecedor' => P::L('Fornecedor', 'Supplier', 'Proveedor'), 'rnc' => P::L('RNC', 'NCR', 'RNC'), 'novaRnc' => P::L('Nova RNC da auditoria', 'New NCR from the audit', 'Nueva RNC de la auditoría'),
    'excluirPerg' => P::L('Excluir a pergunta selecionada do checklist?', 'Delete the selected checklist question?', '¿Eliminar la pregunta seleccionada del checklist?'),
    'excluirCs' => P::L('Excluir a constatação selecionada?', 'Delete the selected finding?', '¿Eliminar el hallazgo seleccionado?'),
    'paraConstatacao' => P::L('→ registrar constatação', '→ record finding', '→ registrar hallazgo'), 'salveAntes' => P::L('Há alterações não salvas no checklist: salve antes.', 'There are unsaved checklist changes: save first.', 'Hay cambios sin guardar en el checklist: guarde antes.')];
echo '</main>';
greenPortalGridLocalScripts();
echo '<script>(function(){"use strict";var C=' . json_encode($C, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';' . <<<'JS'
function $q(s,r){return (r||document).querySelector(s);}function $a(s,r){return Array.prototype.slice.call((r||document).querySelectorAll(s));}
function post(d){var f=new FormData();f.append("csrf",C.csrf);f.append("id",C.id);Object.keys(d).forEach(function(k){f.append(k,d[k]);});return fetch(C.base+"ajax=1&id="+C.id,{method:"POST",body:f,credentials:"same-origin"}).then(function(r){return r.json();});}
function host(){try{if(window.parent&&window.parent!==window&&window.parent.GreenPortalPopups)return window.parent;}catch(e){}try{if(window.top&&window.top.GreenPortalPopups)return window.top;}catch(e){}return window;}
function popup(url,titulo,id){var h=host(),abs=new URL(url,location.href).toString();if(h.GreenPortalPopups&&h.jQuery){h.GreenPortalPopups.transacaoIndependente({id:id||("audPop"+Date.now()),titulo:titulo,conteudo:h.jQuery("<iframe>",{title:titulo,src:abs,style:"width:100%;height:100%;border:0;display:block;"}),amplo:true,redimensionavel:true});}else window.open(abs,"_blank");}
function modal(id){var el=document.getElementById(id);return el&&window.bootstrap?window.bootstrap.Modal.getOrCreateInstance(el):null;}
// a ficha gravou: a lista (outra janela/popup) recarrega
if(C.mudou){try{new BroadcastChannel("greenportal-auditoria").postMessage({id:C.id});}catch(e){}}
// ---- fluxo: Enviar, Aprovar, Recusar (motivo), Encerrar, Cancelar (motivo), Excluir (confirmação)
var fx=document.getElementById("audFluxo"),av=document.getElementById("audAvisar");
function fluxo(acao,motivo,extra){fx.acao.value=acao;fx.motivo.value=motivo||"";fx.avisar.value=av&&av.checked?"1":"";fx.cod.value=(extra&&extra.cod)||"";fx.codocorrencia.value=(extra&&extra.codocorrencia)||"";fx.submit();}
function confirmar(texto,ok){var m=modal("audConfirmaModal");if(!m){ok();return;}var el=document.getElementById("audConfirmaModal");$q("[data-confirma-texto]",el).textContent=texto;var b=$q("[data-modal-ok]",el);var nb=b.cloneNode(true);b.parentNode.replaceChild(nb,b);nb.addEventListener("click",function(){m.hide();ok();});m.show();}
$a("[data-aud-fluxo]").forEach(function(b){b.addEventListener("click",function(){var acao=b.getAttribute("data-aud-fluxo");
 if(b.hasAttribute("data-motivo")){var el=document.getElementById("audMotivoModal"),m=modal("audMotivoModal");$q("[data-modal-titulo]",el).textContent=b.getAttribute("data-motivo");var t=$q("[data-motivo-texto]",el);t.value="";$q("[data-modal-msg]",el).textContent="";
  var ok=$q("[data-modal-ok]",el),nb=ok.cloneNode(true);ok.parentNode.replaceChild(nb,ok);nb.addEventListener("click",function(){if(!t.value.trim()){$q("[data-modal-msg]",el).textContent=C.motivoFalta;$q("[data-modal-msg]",el).className="small me-auto text-danger";t.focus();return;}nb.disabled=true;fluxo(acao,t.value.trim());});
  m.show();el.addEventListener("shown.bs.modal",function(){t.focus();},{once:true});return;}
 if(b.hasAttribute("data-confirma")){confirmar(b.getAttribute("data-confirma"),function(){fluxo(acao);});return;}
 fluxo(acao);});});
// ---- associações e outros
$a("[data-aud-arquivos]").forEach(function(b){b.addEventListener("click",function(){var h=host();popup((h.greenPortalBaseUrl||"..")+"/principal/gestao_arquivos.php?tipo=auditoria&chave=CODAUDITORIA&valor="+encodeURIComponent(b.getAttribute("data-aud-arquivos")),C.arquivos+" #"+C.id);});});
$a("[data-aud-relatorio]").forEach(function(b){b.addEventListener("click",function(){popup("auditoria_relatorio.php?id="+encodeURIComponent(b.getAttribute("data-aud-relatorio")),C.relatorio+" #"+C.id);});});
$a("[data-aud-fornecedor]").forEach(function(b){b.addEventListener("click",function(){popup("homologacaofornecedor_fornecedor.php?filtros="+encodeURIComponent(JSON.stringify({CODFORNECEDOR:b.getAttribute("data-aud-fornecedor")})),C.fornecedor);});});
$a("[data-aud-ir]").forEach(function(b){b.addEventListener("click",function(){location.href=C.base+"id="+C.id+"&aba="+b.getAttribute("data-aud-ir");});});
// ---- planejamento: fornecedor só no tipo De fornecedor
var tsel=document.getElementById("audTipoSel"),fb=document.getElementById("audFornBox");function tf(){if(tsel&&fb)fb.style.display=tsel.value==="FORNECEDOR"?"":"none";}if(tsel){tsel.addEventListener("change",tf);tf();}
// ---- Enter nas buscas do grid não envia o formulário do checklist
$a(".crud-search input, .column-filter").forEach(function(i){i.addEventListener("keydown",function(ev){if(ev.key==="Enter")ev.preventDefault();});});
// ---- checklist
var sujo=false;$a("#audCkGrid select, #audCkGrid textarea").forEach(function(e){e.addEventListener("change",function(){sujo=true;var s=e.closest("td")&&e.closest("td").querySelector("span.d-none");if(s&&e.tagName==="SELECT")s.textContent=e.options[e.selectedIndex]?e.options[e.selectedIndex].label:"";});});
$a("[data-aud-ck-nova]").forEach(function(b){b.addEventListener("click",function(){var m=modal("audCkNovaModal");if(!m)return;var el=document.getElementById("audCkNovaModal");var ok=$q("[data-modal-ok]",el),nb=ok.cloneNode(true);ok.parentNode.replaceChild(nb,ok);
 nb.addEventListener("click",function(){var f=document.getElementById("audCkNovaForm");if(!f.reportValidity())return;nb.disabled=true;f.submit();});m.show();});});
$a("[data-aud-ck-del]").forEach(function(b){b.addEventListener("click",function(){confirmar(C.excluirPerg,function(){fluxo("checklist_del","",{cod:b.getAttribute("data-aud-ck-del")});});});});
$a("[data-aud-ck-ia]").forEach(function(b){b.addEventListener("click",function(){var m=modal("audCkIaModal");if(!m)return;var el=document.getElementById("audCkIaModal"),msg=$q("[data-modal-msg]",el);var ok=$q("[data-modal-ok]",el),nb=ok.cloneNode(true);ok.parentNode.replaceChild(nb,ok);
 nb.addEventListener("click",function(){nb.disabled=true;msg.className="small me-auto text-muted";msg.textContent=C.aguarde;
  post({acao:"ia_checklist",texto:$q("#iaTexto").value,arquivo:$q("#iaArq").value,quantidade:$q("#iaQtd").value}).then(function(r){msg.className="small me-auto "+(r.ok?"text-success":"text-danger");msg.textContent=r.message||"";if(r.ok)setTimeout(function(){location.href=C.base+"id="+C.id+"&aba=checklist";},900);else nb.disabled=false;}).catch(function(e){nb.disabled=false;msg.className="small me-auto text-danger";msg.textContent=e.message;});});m.show();});});
$a("[data-aud-ck-iaev]").forEach(function(b){b.addEventListener("click",function(){var tr=b.closest("tr"),res=tr.querySelector("[data-ia-res]"),ta=tr.querySelector("textarea"),bar=$q('[data-grid-acao-linha="iaev"]');if(bar)bar.disabled=true;res.className="aud-ia-res text-muted";res.textContent=C.aguarde;
 post({acao:"ia_evidencia",codchecklist:b.getAttribute("data-aud-ck-iaev"),evidencia:ta.value,arquivo:(document.getElementById("audArqEv")||{}).value||""}).then(function(r){if(bar)bar.disabled=false;if(!r.ok){res.className="aud-ia-res text-danger";res.textContent=r.message||"";return;}
  var s=tr.querySelector("select");if(r.resposta&&s){s.value=r.resposta;s.dispatchEvent(new Event("change"));}res.className="aud-ia-res text-primary";res.textContent="";var t=document.createElement("span");t.textContent="IA: "+(r.justificativa||"");res.appendChild(t);
  if(r.constatacao){var a=document.createElement("a");a.className="ms-1";a.href=C.base+"id="+C.id+"&aba=constatacoes&ck="+b.getAttribute("data-aud-ck-iaev")+"&txt="+encodeURIComponent(r.constatacao);a.textContent=C.paraConstatacao;res.appendChild(a);}}).catch(function(e){if(bar)bar.disabled=false;res.className="aud-ia-res text-danger";res.textContent=e.message;});});});
$a("[data-aud-ck-constatar]").forEach(function(b){b.addEventListener("click",function(){if(sujo){var r=b.closest("tr").querySelector("[data-ia-res]");if(r){r.className="aud-ia-res text-danger";r.textContent=C.salveAntes;}return;}location.href=C.base+"id="+C.id+"&aba=constatacoes&ck="+b.getAttribute("data-aud-ck-constatar");});});
// ---- constatações
function abrirCs(){var m=modal("audCsModal");if(!m)return;var el=document.getElementById("audCsModal");var ok=$q("[data-modal-ok]",el),nb=ok.cloneNode(true);ok.parentNode.replaceChild(nb,ok);
 nb.addEventListener("click",function(){var f=document.getElementById("audCsForm");if(!f.reportValidity())return;nb.disabled=true;f.submit();});m.show();}
$a("[data-aud-cs-nova]").forEach(function(b){b.addEventListener("click",abrirCs);});
var csf=document.getElementById("audCsForm");if(csf&&csf.hasAttribute("data-abrir"))abrirCs();
$a("[data-aud-cs-del]").forEach(function(b){b.addEventListener("click",function(){confirmar(C.excluirCs,function(){fluxo("constatacao_del","",{cod:b.getAttribute("data-aud-cs-del")});});});});
$a("[data-aud-rnc-abrir]").forEach(function(b){b.addEventListener("click",function(){popup(b.getAttribute("data-aud-rnc-abrir"),C.novaRnc+" #"+C.id);});});
$a("[data-aud-rnc-ver]").forEach(function(b){b.addEventListener("click",function(){popup("naoconformidades_ocorrencia.php?codigo="+encodeURIComponent(b.getAttribute("data-aud-rnc-ver")),b.getAttribute("data-titulo")||C.rnc);});});
$a("[data-aud-vincular]").forEach(function(b){b.addEventListener("click",function(){var m=modal("audVincModal");if(!m)return;var el=document.getElementById("audVincModal"),s=$q("[data-vinc-rnc]",el);var ok=$q("[data-modal-ok]",el),nb=ok.cloneNode(true);ok.parentNode.replaceChild(nb,ok);
 nb.addEventListener("click",function(){if(!s.value){s.focus();return;}nb.disabled=true;fluxo("vincular_rnc","",{cod:b.getAttribute("data-aud-vincular"),codocorrencia:s.value});});m.show();});});
// RNC aberta num popup: ao voltar o foco para a ficha, recarrega para ligar a RNC à constatação
var foiRnc=false;$a("[data-aud-rnc-abrir]").forEach(function(b){b.addEventListener("click",function(){foiRnc=true;});});
window.addEventListener("focus",function(){if(foiRnc){foiRnc=false;setTimeout(function(){location.reload();},400);}});
})();</script>
JS;
echo '</body></html>';
