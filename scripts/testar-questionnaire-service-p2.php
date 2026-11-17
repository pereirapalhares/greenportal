<?php
declare(strict_types=1);

require_once __DIR__ . '/../classes/GreenPortalDatabase.php';
require_once __DIR__ . '/../classes/QuestionnaireService.php';

$env = GreenPortalDatabase::env();
$universal = GreenPortalDatabase::connect($env, 'guniversal_dbp', 'GUNIVERSAL');
$tenant = GreenPortalDatabase::connect($env, 'gatlas_dbp', 'GPT');
$suffix = date('YmdHis') . '_' . random_int(100, 999);
$code = 'TEST_P2_' . $suffix;
$questionnaireId = 0;
$versionId = 0;
$questionId = 0;
$optionYes = 0;
$optionNo = 0;
$executionId = 0;
$result = ['status' => 'ERRO', 'codigo' => $code];

try {
    $universal->beginTransaction();
    $q = $universal->prepare("INSERT INTO amb_questionario(codigo,nome_pt,modulo,criado_por) VALUES (:codigo,'Teste P2','TESTE',1)");
    $q->execute(['codigo' => $code]);
    $questionnaireId = (int)$universal->lastInsertId();
    $q = $universal->prepare("INSERT INTO amb_questionario_versao(codquestionario,versao,status,percentual_minimo,criado_por) VALUES (:questionario,1,'PUBLICADA',80,1)");
    $q->execute(['questionario' => $questionnaireId]);
    $versionId = (int)$universal->lastInsertId();
    $q = $universal->prepare("INSERT INTO amb_questionario_pergunta(codversao,codigo,texto_pt,tipo,obrigatoria,ordem) VALUES (:versao,'CONFIRMA','Confirma?','SIM_NAO',1,1)");
    $q->execute(['versao' => $versionId]);
    $questionId = (int)$universal->lastInsertId();
    $q = $universal->prepare("INSERT INTO amb_questionario_opcao(codpergunta,codigo,rotulo_pt,valor,pontuacao,ordem) VALUES (:pergunta,'SIM','Sim','SIM',1,1),(:pergunta,'NAO','Não','NAO',0,2)");
    $q->execute(['pergunta' => $questionId]);
    $optionQuery = $universal->prepare('SELECT codopcao,codigo FROM amb_questionario_opcao WHERE codpergunta=:pergunta ORDER BY codopcao');
    $optionQuery->execute(['pergunta' => $questionId]);
    foreach ($optionQuery->fetchAll(PDO::FETCH_ASSOC) as $option) {
        if ($option['codigo'] === 'SIM') $optionYes = (int)$option['codopcao'];
        if ($option['codigo'] === 'NAO') $optionNo = (int)$option['codopcao'];
    }
    $q = $universal->prepare("INSERT INTO amb_questionario_publicacao(codversao,codigo_publicacao,publicado_por) VALUES (:versao,:codigo,1)");
    $q->execute(['versao' => $versionId, 'codigo' => 'Q:' . $code . ':V:1']);
    $universal->commit();

    $service = new QuestionnaireService($tenant, $universal);
    $first = $service->start($code, 1, null, 'teste_p2', $suffix, 'TESTE-IDEMPOTENCIA-' . $suffix);
    $second = $service->start($code, 1, null, 'teste_p2', $suffix, 'TESTE-IDEMPOTENCIA-' . $suffix);
    if ((int)$first['codexecucao'] !== (int)$second['codexecucao']) {
        throw new RuntimeException('A chave de idempotência gerou execuções diferentes.');
    }
    $executionId = (int)$first['codexecucao'];
    $questions = $service->questions($executionId);
    if (count($questions) !== 1 || (int)$questions[0]['codpergunta'] !== $questionId) {
        throw new RuntimeException('Pergunta publicada não foi carregada corretamente.');
    }
    $submitted = $service->submit($executionId, 1, ['CONFIRMA' => 'SIM']);
    if ($submitted['status'] !== 'CONCLUIDA' || (float)$submitted['percentual'] !== 100.0) {
        throw new RuntimeException('Resultado do questionário inválido.');
    }
    $secondSubmitError = false;
    try {
        $service->submit($executionId, 1, ['CONFIRMA' => 'SIM']);
    } catch (DomainException $expected) {
        $secondSubmitError = true;
    }
    if (!$secondSubmitError) {
        throw new RuntimeException('A submissão encerrada deveria ser bloqueada.');
    }
    $result = [
        'status' => 'CONCLUIDA',
        'idempotencia' => 'OK',
        'perguntas' => count($questions),
        'execucao' => $executionId,
        'resultado' => $submitted,
        'reenvio_bloqueado' => 'OK',
    ];
} finally {
    if ($universal->inTransaction()) $universal->rollBack();
    if ($executionId > 0) {
        $tenant->prepare('DELETE FROM amb_questionario_evidencias WHERE codexecucao=:id')->execute(['id' => $executionId]);
        $tenant->prepare('DELETE FROM amb_questionario_resultados WHERE codexecucao=:id')->execute(['id' => $executionId]);
        $tenant->prepare('DELETE FROM amb_questionario_respostas WHERE codexecucao=:id')->execute(['id' => $executionId]);
        $tenant->prepare('DELETE FROM amb_questionario_execucoes WHERE codexecucao=:id')->execute(['id' => $executionId]);
    }
    if ($questionId > 0) $universal->prepare('DELETE FROM amb_questionario_opcao WHERE codpergunta=:id')->execute(['id' => $questionId]);
    if ($questionId > 0) $universal->prepare('DELETE FROM amb_questionario_pergunta WHERE codpergunta=:id')->execute(['id' => $questionId]);
    if ($versionId > 0) $universal->prepare('DELETE FROM amb_questionario_publicacao WHERE codversao=:id')->execute(['id' => $versionId]);
    if ($versionId > 0) $universal->prepare('DELETE FROM amb_questionario_versao WHERE codversao=:id')->execute(['id' => $versionId]);
    if ($questionnaireId > 0) $universal->prepare('DELETE FROM amb_questionario WHERE codquestionario=:id')->execute(['id' => $questionnaireId]);
    $leftUniversal = $universal->prepare('SELECT COUNT(*) FROM amb_questionario WHERE codigo=:codigo');
    $leftUniversal->execute(['codigo' => $code]);
    $leftTenant = $tenant->prepare('SELECT COUNT(*) FROM amb_questionario_execucoes WHERE chave_idempotencia=:chave');
    $leftTenant->execute(['chave' => 'TESTE-IDEMPOTENCIA-' . $suffix]);
    $result['limpeza'] = ((int)$leftUniversal->fetchColumn() === 0 && (int)$leftTenant->fetchColumn() === 0) ? 'OK' : 'FALHOU';
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
