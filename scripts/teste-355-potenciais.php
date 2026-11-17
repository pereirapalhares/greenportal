<?php
declare(strict_types=1);

/*
 * Teste da migração 355 (aprovar potencial da IA escolhendo o destino) sem tocar em análises reais.
 *
 *   --criar : cria uma solicitação de IA FICTÍCIA ("TESTE GREENPORTAL", status CONCLUIDA) para um requisito da unidade, com
 *             2 potenciais de LICENÇA e 4 de CONTROLE (transporte, resíduos, dedetização, plano de emergência), todos SUGERIDO.
 *             Para não criar tarefa nem avisar outras pessoas, reserva a tarefa de cada potencial ("IA-POTENCIAL:<n>") para
 *             o próprio usuário do teste: o serviço de IA vê que ela já existe e não cria outra.
 *   --limpar: desfaz tudo o que os testes criaram: vínculos novos (requisito × licença/documento), licenças e documentos criados
 *             na aprovação, vínculos documentais IA_CONFIRMADA dos potenciais de teste, as tarefas reservadas, os potenciais e a
 *             solicitação. Licenças e documentos que já existiam continuam (só o vínculo novo sai).
 * Sem --aplicar, só mostra o que faria.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\teste-355-potenciais.php --banco=gcrown_dbp --criar --usuario=291 [--unidade=4] [--legislacao=N] [--aplicar]
 *      E:\wamp\bin\php\php8.4.15\php.exe scripts\teste-355-potenciais.php --banco=gcrown_dbp --limpar [--aplicar]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TaskService.php';

$o = ['banco' => '', 'usuario' => 0, 'unidade' => 4, 'legislacao' => 0];
foreach ($argv ?? [] as $a) if (preg_match('/^--(banco|usuario|unidade|legislacao)=(.+)$/', $a, $m)) $o[$m[1]] = $m[1] === 'banco' ? $m[2] : (int)$m[2];
$aplicar = in_array('--aplicar', $argv ?? [], true);
$criar = in_array('--criar', $argv ?? [], true);
$limpar = in_array('--limpar', $argv ?? [], true);
if (!preg_match('/^g[a-z0-9_]+_dbp$/', (string)$o['banco']) || $criar === $limpar) { fwrite(STDERR, "Informe --banco=g..._dbp e --criar ou --limpar\n"); exit(1); }
$pdo = GreenPortalDatabase::connect(GreenPortalDatabase::env(), $o['banco'], 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$x = static function (string $sql, array $p = []) use ($pdo): int { $s = $pdo->prepare($sql); $s->execute($p); return $s->rowCount(); };
$rel = ['banco' => $o['banco'], 'modo' => $aplicar ? 'APLICAR' : 'SIMULACAO (use --aplicar)'];

if ($criar) {
    if ($o['usuario'] <= 0) { fwrite(STDERR, "Informe --usuario=N (quem vai testar)\n"); exit(1); }
    $leg = (int)$o['legislacao'];
    if ($leg <= 0) {
        $r = $q('SELECT CODLEGISLACAO, COUNT(*) AS N FROM legislacaoxlicencas WHERE codunidade = ? GROUP BY CODLEGISLACAO ORDER BY N DESC LIMIT 1', [$o['unidade']]);
        $leg = (int)($r[0]['CODLEGISLACAO'] ?? 0);
        if ($leg <= 0) { $r = $q('SELECT CODLEGISLACAO FROM legislacaoxdocumentos WHERE CODUNIDADE = ? GROUP BY CODLEGISLACAO ORDER BY COUNT(*) DESC LIMIT 1', [$o['unidade']]); $leg = (int)($r[0]['CODLEGISLACAO'] ?? 0); }
    }
    if ($leg <= 0) { fwrite(STDERR, "Nenhum requisito com licença ou documento na unidade {$o['unidade']}: informe --legislacao=N\n"); exit(1); }
    $rel['requisito'] = ['CODLEGISLACAO' => $leg, 'CODUNIDADE' => $o['unidade'],
        'licencas_ja_vinculadas' => (int)($q('SELECT COUNT(*) AS N FROM legislacaoxlicencas WHERE CODLEGISLACAO = ? AND codunidade = ?', [$leg, $o['unidade']])[0]['N'] ?? 0),
        'documentos_ja_vinculados' => (int)($q('SELECT COUNT(*) AS N FROM legislacaoxdocumentos WHERE CODLEGISLACAO = ? AND CODUNIDADE = ?', [$leg, $o['unidade']])[0]['N'] ?? 0)];
    $potenciais = [
        ['LICENCA', 'TESTE GREENPORTAL - Licença Ambiental de Operação', 'A atividade exige licença ambiental de operação vigente.'],
        ['LICENCA', 'TESTE GREENPORTAL - Licença Ambiental', 'O requisito pede licença ambiental para cada atividade licenciável.'],
        ['CONTROLE', 'TESTE GREENPORTAL - Controle de transportadores', 'Homologar e acompanhar as empresas de transporte.'],
        ['CONTROLE', 'TESTE GREENPORTAL - Controle de empresas de resíduos', 'Homologar e acompanhar os receptores de resíduos.'],
        ['CONTROLE', 'TESTE GREENPORTAL - Controle de dedetização', 'Homologar e acompanhar as empresas de controle de pragas.'],
        ['CONTROLE', 'TESTE GREENPORTAL - Plano de emergência', 'Manter procedimento de resposta a emergências.'],
    ];
    $rel['potenciais'] = array_map(static fn($p) => $p[0] . ': ' . $p[1], $potenciais);
    if ($aplicar) {
        $pdo->beginTransaction();
        $x("INSERT INTO ml_potenciais_solicitacoes (codusuario, idioma, contexto_json, status, solicitada_em, iniciada_em, concluida_em, processador)
            VALUES (?, 'pt', ?, 'CONCLUIDA', NOW(), NOW(), NOW(), 'TESTE GREENPORTAL')",
            [$o['usuario'], json_encode(['CODLEGISLACAO' => $leg, 'CODUNIDADE' => $o['unidade'], 'ORIGEM' => 'TESTE GREENPORTAL'])]);
        $sol = (int)$pdo->lastInsertId();
        $ids = [];
        foreach ($potenciais as [$tp, $tit, $desc]) {
            $x("INSERT INTO ml_potenciais (codsolicitacao, codrequisitolegal, tipo_potencial, titulo, descricao, justificativa_ia, confianca_ia, idioma_conteudo, status)
                VALUES (?, ?, ?, ?, ?, 'Potencial de teste (não veio da IA).', 0.50, 'pt', 'SUGERIDO')", [$sol, $leg, $tp, $tit, $desc]);
            $ids[] = (int)$pdo->lastInsertId();
        }
        $pdo->commit();
        $tasks = new TaskService($pdo);
        foreach ($ids as $pid) $tasks->enqueue(['chave_idempotencia' => 'IA-POTENCIAL:' . $pid, 'tipo' => 'IA_APROVACAO', 'origem_transacao' => 'teste-355-potenciais.php',
            'origem_entidade' => 'ml_potenciais', 'origem_id' => (string)$pid, 'codunidade' => $o['unidade'], 'titulo' => 'TESTE GREENPORTAL - reserva (não tratar)',
            'descricao' => 'Reserva do teste da migração 355: impede que o serviço de IA crie tarefa e avise responsáveis. Sai com --limpar.',
            'prioridade' => 1, 'codusuario_destino' => $o['usuario'], 'criado_por' => $o['usuario']]);
        $rel['solicitacao'] = $sol;
        $rel['potenciais_criados'] = $ids;
        $rel['abrir'] = '/greenportal/principal/ia_potenciais_resultado.php?codsolicitacao=' . $sol;
    }
}

if ($limpar) {
    $sols = array_map('intval', array_column($q("SELECT codsolicitacao FROM ml_potenciais_solicitacoes WHERE JSON_UNQUOTE(JSON_EXTRACT(contexto_json, '$.ORIGEM')) = 'TESTE GREENPORTAL'"), 'codsolicitacao'));
    $rel['solicitacoes'] = $sols;
    if (!$sols) { echo json_encode($rel + ['resultado' => 'nada a limpar'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL; exit(0); }
    $in = implode(',', $sols);
    $pots = array_map('intval', array_column($q("SELECT codpotencial FROM ml_potenciais WHERE codsolicitacao IN ($in)"), 'codpotencial'));
    $pin = $pots ? implode(',', $pots) : '0';
    $temDest = (int)($q("SELECT COUNT(*) AS N FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ml_potenciais_destinos'")[0]['N'] ?? 0) > 0;
    $dest = $temDest ? $q("SELECT * FROM ml_potenciais_destinos WHERE codpotencial IN ($pin)") : [];
    // Licenças/documentos criados na aprovação (NOVO) — só se nenhum outro potencial/vínculo fora do teste os usa
    $novosLic = []; $novosDoc = []; $vincLic = []; $vincDoc = [];
    foreach ($dest as $d) {
        $k = [(int)$d['codlegislacao'], (int)$d['codregistro'], (int)$d['codunidade']];
        if ($d['tipo_destino'] === 'LICENCA') { if ($d['modo'] === 'NOVO') $novosLic[] = (int)$d['codregistro']; if ((int)$d['vinculo_criado'] === 1) $vincLic[implode('-', $k)] = $k; }
        else { if ($d['modo'] === 'NOVO') $novosDoc[] = (int)$d['codregistro']; if ((int)$d['vinculo_criado'] === 1) $vincDoc[implode('-', $k)] = $k; }
    }
    // Potenciais de teste aprovados pelo fluxo antigo (antes da 355 ou sem destino): o registro criado também sai
    foreach ($q("SELECT tipo_potencial, codregistro_destino, codrequisitolegal FROM ml_potenciais WHERE codpotencial IN ($pin) AND codregistro_destino IS NOT NULL") as $p) {
        $ja = array_filter($dest, static fn($d) => (int)$d['codregistro'] === (int)$p['codregistro_destino']);
        if (!$ja && $p['tipo_potencial'] === 'CONTROLE') $novosDoc[] = (int)$p['codregistro_destino'];
        if (!$ja && $p['tipo_potencial'] === 'LICENCA') $novosLic[] = (int)$p['codregistro_destino'];
    }
    $novosDoc = array_values(array_unique(array_filter($novosDoc, static fn($id) => (bool)$q("SELECT 1 FROM documentos WHERE CODDOCUMENTO = ? AND CODIGO LIKE 'IA-%'", [$id]))));
    $novosLic = array_values(array_unique(array_filter($novosLic, static fn($id) => (bool)$q('SELECT 1 FROM li_licencas WHERE CODLICENCA = ? AND DSCLICENCA LIKE ?', [$id, 'TESTE GREENPORTAL%']))));
    $rel['apagar'] = [
        'vinculos_requisito_licenca_novos' => array_values($vincLic), 'vinculos_requisito_documento_novos' => array_values($vincDoc),
        'licencas_criadas' => $novosLic, 'documentos_criados' => $novosDoc,
        'tarefas' => (int)($q("SELECT COUNT(*) AS N FROM green_tasks WHERE chave_idempotencia IN (" . ($pots ? implode(',', array_map(static fn($p) => "'IA-POTENCIAL:$p'", $pots)) : "''") . ')')[0]['N'] ?? 0),
        'potenciais' => $pots, 'solicitacoes' => $sols,
    ];
    if ($aplicar) {
        $pdo->beginTransaction();
        foreach ($vincLic as [$l, $r, $u]) $x('DELETE FROM legislacaoxlicencas WHERE CODLEGISLACAO = ? AND CODLICENCA = ? AND codunidade = ?', [$l, $r, $u]);
        foreach ($vincDoc as [$l, $r, $u]) $x('DELETE FROM legislacaoxdocumentos WHERE CODLEGISLACAO = ? AND CODDOCUMENTO = ? AND CODUNIDADE = ?', [$l, $r, $u]);
        foreach ($novosLic as $id) { $x('DELETE FROM legislacaoxlicencas WHERE CODLICENCA = ?', [$id]); $x('DELETE FROM li_licencas WHERE CODLICENCA = ?', [$id]); }
        $temContent = (int)($q("SELECT COUNT(*) AS N FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_document_links'")[0]['N'] ?? 0) > 0;
        if ($temContent) {
            foreach ($pots as $p) $x("DELETE FROM content_document_links WHERE origem_vinculo = 'IA_CONFIRMADA' AND observacao LIKE ?", ['Potencial IA ' . $p . ' aprovado%']);
            foreach ($novosDoc as $id) {
                $c = $q('SELECT coddocumento FROM content_documents WHERE chave_idempotencia = ?', ['CONTROLE_DOCUMENTO_' . $id]);
                if ($c && !$q('SELECT 1 FROM content_document_links WHERE coddocumento = ? LIMIT 1', [(int)$c[0]['coddocumento']]) && !$q('SELECT 1 FROM content_versions WHERE coddocumento = ? LIMIT 1', [(int)$c[0]['coddocumento']]))
                    $x('DELETE FROM content_documents WHERE coddocumento = ?', [(int)$c[0]['coddocumento']]);
            }
        }
        foreach ($novosDoc as $id) { $x('DELETE FROM legislacaoxdocumentos WHERE CODDOCUMENTO = ?', [$id]); $x('DELETE FROM documentos WHERE CODDOCUMENTO = ?', [$id]); }
        if ($temDest) $x("DELETE FROM ml_potenciais_destinos WHERE codpotencial IN ($pin)");
        if ($pots) {
            $ch = implode(',', array_map(static fn($p) => "'IA-POTENCIAL:$p'", $pots));
            $x("DELETE h FROM green_task_history h JOIN green_tasks t ON t.codtarefa = h.codtarefa WHERE t.chave_idempotencia IN ($ch)");
            $x("DELETE FROM green_tasks WHERE chave_idempotencia IN ($ch)");
        }
        $x("DELETE FROM ml_potenciais WHERE codsolicitacao IN ($in)");
        $x("DELETE FROM ml_potenciais_solicitacoes WHERE codsolicitacao IN ($in)");
        $pdo->commit();
        $rel['resultado'] = 'apagado';
    } else $rel['resultado'] = 'seria apagado';
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
