<?php
declare(strict_types=1);

require_once __DIR__ . '/../classes/GreenPortalDatabase.php';
require_once __DIR__ . '/../classes/QuestionnaireService.php';
require_once __DIR__ . '/../classes/WorkflowService.php';
require_once __DIR__ . '/../classes/TaskService.php';

$env = GreenPortalDatabase::env();
$universal = GreenPortalDatabase::connect($env, $env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp', 'GUNIVERSAL');
$contexts = ['LEGAL', 'SGA', 'QUALITY', 'WORKFLOW'];
$codes = [];
$questionnaires = [];
$result = ['status' => 'ERRO', 'tenants' => [], 'contextos' => $contexts];

try {
    foreach ($contexts as $context) {
        $code = 'TEST_CTX_' . $context . '_' . date('YmdHis') . '_' . random_int(100, 999);
        $codes[] = $code;
        $insert = $universal->prepare('INSERT INTO amb_questionario(codigo,nome_pt,modulo,criado_por) VALUES (:codigo,:nome,:modulo,1)');
        $insert->execute(['codigo' => $code, 'nome' => 'Teste contexto ' . $context, 'modulo' => $context]);
        $questionnaireId = (int)$universal->lastInsertId();
        $version = $universal->prepare("INSERT INTO amb_questionario_versao(codquestionario,versao,status,percentual_minimo,criado_por) VALUES (:questionario,1,'PUBLICADA',100,1)");
        $version->execute(['questionario' => $questionnaireId]);
        $versionId = (int)$universal->lastInsertId();
        $question = $universal->prepare("INSERT INTO amb_questionario_pergunta(codversao,codigo,texto_pt,tipo,obrigatoria,ordem) VALUES (:versao,'ACEITE','Aceita o teste?','SIM_NAO',1,1)");
        $question->execute(['versao' => $versionId]);
        $questionId = (int)$universal->lastInsertId();
        $option = $universal->prepare("INSERT INTO amb_questionario_opcao(codpergunta,codigo,rotulo_pt,valor,pontuacao,ordem) VALUES (:pergunta,'SIM','Sim','SIM',1,1)");
        $option->execute(['pergunta' => $questionId]);
        $publication = $universal->prepare("INSERT INTO amb_questionario_publicacao(codversao,codigo_publicacao,publicado_por) VALUES (:versao,:codigo,1)");
        $publication->execute(['versao' => $versionId, 'codigo' => 'Q:' . $code . ':V:1']);
        $questionnaires[$context] = [$questionnaireId, $versionId, $questionId];
    }

    foreach (['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'] as $database) {
        $tenant = GreenPortalDatabase::connect($env, $database, 'GPT');
        $user = (int)$tenant->query('SELECT codusuario FROM usuarios ORDER BY codusuario LIMIT 1')->fetchColumn();
        if ($user <= 0) throw new RuntimeException('Usuário de teste não localizado em ' . $database);
        $tenantResult = ['user' => $user, 'contexts' => []];
        foreach ($contexts as $context) {
            $service = new QuestionnaireService($tenant, $universal);
            $code = $codes[array_search($context, $contexts, true)];
            $catalog = $service->catalogForContext($context);
            if (!array_filter($catalog, static fn(array $row): bool => (string)$row['codigo'] === $code)) throw new RuntimeException('Catálogo não filtrou o contexto ' . $context . ' em ' . $database);
            $key = 'TEST-CONTEXT:' . $database . ':' . $context . ':' . $code;
            $started = $service->start($code, $user, 1, 'QUESTIONNAIRE_' . $context, $context . ':1', $key, $service->contextWorkflow($context));
            $startedAgain = $service->start($code, $user, 1, 'QUESTIONNAIRE_' . $context, $context . ':1', $key, $service->contextWorkflow($context));
            if ((int)$started['codexecucao'] !== (int)$startedAgain['codexecucao']) throw new RuntimeException('Idempotência falhou em ' . $database . '/' . $context);
            $submitted = $service->submit((int)$started['codexecucao'], $user, ['ACEITE' => 'SIM']);
            $evidence1 = $service->addEvidence((int)$started['codexecucao'], $user, $questionnaires[$context][2], 'TESTE_P2', $context . ':1');
            $evidence2 = $service->addEvidence((int)$started['codexecucao'], $user, $questionnaires[$context][2], 'TESTE_P2', $context . ':1');
            if ((int)($evidence1['codevidencia'] ?? 0) !== (int)($evidence2['codevidencia'] ?? -1)) throw new RuntimeException('Evidência não idempotente em ' . $database . '/' . $context);
            $workflowQuery = $tenant->prepare("SELECT codinstancia,etapa_atual FROM workflow_instancias WHERE origem_entidade='amb_questionario_execucoes' AND origem_id=:origem ORDER BY codinstancia DESC LIMIT 1");
            $workflowQuery->execute(['origem' => (string)$started['codexecucao']]);
            $workflow = $workflowQuery->fetch(PDO::FETCH_ASSOC);
            if (!$workflow || (string)$workflow['etapa_atual'] !== 'REVISAO') throw new RuntimeException('Workflow não avançou para revisão em ' . $database . '/' . $context);
            $workflowService = new WorkflowService($tenant, new TaskService($tenant));
            $finalStage = $workflowService->transition((int)$workflow['codinstancia'], 'APROVAR', $user, 'Teste P2 aprovado.');
            if ($finalStage !== 'FINALIZADO') throw new RuntimeException('Workflow não finalizou em ' . $database . '/' . $context);
            $tenantResult['contexts'][$context] = ['execution' => (int)$started['codexecucao'], 'status' => $submitted['status'], 'evidence' => (int)$evidence1['codevidencia'], 'workflow' => (int)$workflow['codinstancia'], 'workflow_status' => 'FINALIZADO'];
        }
        $result['tenants'][$database] = $tenantResult;
    }
    $result['status'] = 'CONCLUIDA';
} finally {
    foreach (['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'] as $database) {
        try {
            $tenant = GreenPortalDatabase::connect($env, $database, 'GPT');
            $tenant->exec("DELETE h FROM workflow_historico h JOIN workflow_instancias i ON i.codinstancia=h.codinstancia WHERE BINARY i.origem_entidade = BINARY 'amb_questionario_execucoes' AND EXISTS (SELECT 1 FROM amb_questionario_execucoes x WHERE x.codexecucao = CAST(i.origem_id AS UNSIGNED) AND BINARY x.chave_idempotencia LIKE BINARY 'TEST-CONTEXT:%')");
            $tenant->exec("DELETE FROM workflow_instancias WHERE BINARY origem_entidade = BINARY 'amb_questionario_execucoes' AND EXISTS (SELECT 1 FROM amb_questionario_execucoes x WHERE x.codexecucao = CAST(workflow_instancias.origem_id AS UNSIGNED) AND BINARY x.chave_idempotencia LIKE BINARY 'TEST-CONTEXT:%')");
            $tenant->exec("DELETE e FROM amb_questionario_evidencias e JOIN amb_questionario_execucoes x ON x.codexecucao=e.codexecucao WHERE BINARY x.chave_idempotencia LIKE BINARY 'TEST-CONTEXT:%'");
            $tenant->exec("DELETE r FROM amb_questionario_resultados r JOIN amb_questionario_execucoes x ON x.codexecucao=r.codexecucao WHERE BINARY x.chave_idempotencia LIKE BINARY 'TEST-CONTEXT:%'");
            $tenant->exec("DELETE r FROM amb_questionario_respostas r JOIN amb_questionario_execucoes x ON x.codexecucao=r.codexecucao WHERE BINARY x.chave_idempotencia LIKE BINARY 'TEST-CONTEXT:%'");
            $tenant->exec("DELETE FROM amb_questionario_execucoes WHERE BINARY chave_idempotencia LIKE BINARY 'TEST-CONTEXT:%'");
        } catch (Throwable $cleanupError) { $result['limpeza_' . $database] = $cleanupError->getMessage(); }
    }
    foreach ($questionnaires as [$questionnaireId, $versionId, $questionId]) {
        try {
            $universal->prepare('DELETE FROM amb_questionario_publicacao WHERE codversao=:id')->execute(['id' => $versionId]);
            $universal->prepare('DELETE FROM amb_questionario_opcao WHERE codpergunta=:id')->execute(['id' => $questionId]);
            $universal->prepare('DELETE FROM amb_questionario_pergunta WHERE codpergunta=:id')->execute(['id' => $questionId]);
            $universal->prepare('DELETE FROM amb_questionario_versao WHERE codversao=:id')->execute(['id' => $versionId]);
            $universal->prepare('DELETE FROM amb_questionario WHERE codquestionario=:id')->execute(['id' => $questionnaireId]);
        } catch (Throwable $cleanupError) { $result['limpeza_universal'] = $cleanupError->getMessage(); }
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
}
