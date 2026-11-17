<?php
declare(strict_types=1);

require_once __DIR__ . '/../classes/GreenPortalDatabase.php';

$questionnaires = [
    'QUALITY_AUDIT' => [
        ['QUALITY_AUDIT_PLANEJADA', 'A auditoria de qualidade foi planejada com escopo, critérios e responsáveis definidos?', 'SIM_NAO'],
        ['QUALITY_CRITERIOS_DEFINIDOS', 'Os critérios de avaliação estão documentados e foram comunicados aos participantes?', 'SIM_NAO'],
        ['QUALITY_EVIDENCIAS_DISPONIVEIS', 'As evidências necessárias para a auditoria estão disponíveis e rastreáveis?', 'SIM_NAO'],
        ['QUALITY_NC_REGISTRADA', 'As não conformidades identificadas foram registradas com descrição e evidência?', 'SIM_NAO'],
        ['QUALITY_ACAO_RESPONSAVEL', 'Cada ação corretiva possui responsável definido?', 'SIM_NAO'],
        ['QUALITY_ACAO_PRAZO', 'Cada ação corretiva possui prazo de conclusão definido?', 'SIM_NAO'],
        ['QUALITY_EFICACIA_VERIFICADA', 'A eficácia das ações corretivas concluídas foi verificada?', 'SIM_NAO'],
        ['QUALITY_REGISTROS_CONTROLADOS', 'Os registros da auditoria estão controlados e disponíveis para consulta?', 'SIM_NAO'],
    ],
    'WORKFLOW_CHECKLIST' => [
        ['WORKFLOW_OBJETIVO_DEFINIDO', 'O objetivo desta etapa do processo está claro para o responsável?', 'SIM_NAO'],
        ['WORKFLOW_RESPONSAVEL_DEFINIDO', 'A etapa possui responsável ou grupo responsável definido?', 'SIM_NAO'],
        ['WORKFLOW_ENTRADAS_DISPONIVEIS', 'As informações e documentos necessários para executar a etapa estão disponíveis?', 'SIM_NAO'],
        ['WORKFLOW_CRITERIOS_APROVACAO', 'Os critérios para aprovação ou rejeição da etapa estão definidos?', 'SIM_NAO'],
        ['WORKFLOW_PRAZO_DEFINIDO', 'O prazo para conclusão da etapa está definido e sendo acompanhado?', 'SIM_NAO'],
        ['WORKFLOW_EVIDENCIA_REGISTRADA', 'A evidência da execução da etapa foi registrada?', 'SIM_NAO'],
        ['WORKFLOW_EXCECAO_TRATADA', 'As exceções identificadas foram tratadas e justificadas?', 'SIM_NAO'],
    ],
    'SUPPLIER_HOMOLOGATION' => [
        ['SUPPLIER_ESCOPO_SERVICO', 'O escopo do serviço que será prestado na empresa cliente está definido e foi compreendido pelo fornecedor?', 'SIM_NAO'],
        ['SUPPLIER_DOCUMENTACAO_VALIDA', 'A documentação legal, fiscal, trabalhista e contratual do prestador está válida?', 'SIM_NAO'],
        ['SUPPLIER_RISCO_AVALIADO', 'Os riscos do serviço e das atividades do prestador foram avaliados antes do início?', 'SIM_NAO'],
        ['SUPPLIER_INTEGRACAO_REALIZADA', 'Os profissionais do prestador receberam integração sobre as regras e os riscos da empresa cliente?', 'SIM_NAO'],
        ['SUPPLIER_ACESSO_AUTORIZADO', 'Os acessos do prestador às áreas, sistemas e informações estão autorizados e controlados?', 'SIM_NAO'],
        ['SUPPLIER_EPI_DISPONIVEL', 'Os equipamentos de proteção individual necessários estão definidos, disponíveis e registrados?', 'SIM_NAO'],
        ['SUPPLIER_EXECUCAO_MONITORADA', 'A execução do serviço está sendo acompanhada conforme o escopo e os critérios acordados?', 'SIM_NAO'],
        ['SUPPLIER_INCIDENTES_COMUNICADOS', 'Incidentes, desvios e não conformidades do prestador são comunicados à empresa cliente?', 'SIM_NAO'],
        ['SUPPLIER_AVALIACAO_REALIZADA', 'O desempenho do prestador foi avaliado após a execução ou no período definido?', 'SIM_NAO'],
    ],
    'ESG_ASSESSMENT' => [
        ['ESG_INDICADORES_AMBIENTAIS', 'Os indicadores ambientais relevantes estão definidos e possuem dados atualizados?', 'SIM_NAO'],
        ['ESG_CONSUMOS_MONITORADOS', 'Os consumos relevantes de água, energia e recursos são monitorados?', 'SIM_NAO'],
        ['ESG_CONDICOES_TRABALHO', 'As condições de trabalho são avaliadas e tratadas quando apresentam riscos?', 'SIM_NAO'],
        ['ESG_DIVERSIDADE_RESPEITO', 'Existem práticas para promover respeito, diversidade e não discriminação?', 'SIM_NAO'],
        ['ESG_ETICA_COMUNICADA', 'As regras de ética, integridade e conflito de interesses são comunicadas?', 'SIM_NAO'],
        ['ESG_CANAL_DENUNCIA', 'Existe canal disponível para denúncias e tratamento de condutas inadequadas?', 'SIM_NAO'],
        ['ESG_RISCOS_GOVERNANCA', 'Os riscos ESG são acompanhados pela governança responsável?', 'SIM_NAO'],
        ['ESG_EVIDENCIAS_DISPONIVEIS', 'As informações e evidências dos indicadores ESG estão disponíveis e rastreáveis?', 'SIM_NAO'],
    ],
    'PROJECT_DELIVERABLES' => [
        ['PROJECT_OBJETIVO_DEFINIDO', 'O objetivo, escopo e resultado esperado do projeto estão definidos?', 'SIM_NAO'],
        ['PROJECT_RESPONSAVEIS_DEFINIDOS', 'As responsabilidades pelas entregas do projeto estão definidas?', 'SIM_NAO'],
        ['PROJECT_CRONOGRAMA_ATUALIZADO', 'O cronograma do projeto está atualizado e sendo acompanhado?', 'SIM_NAO'],
        ['PROJECT_RISCOS_AVALIADOS', 'Os riscos do projeto foram identificados e possuem tratamento definido?', 'SIM_NAO'],
        ['PROJECT_ENTREGAVEL_VALIDADO', 'O entregável da fase foi validado conforme os critérios definidos?', 'SIM_NAO'],
        ['PROJECT_MUDANCAS_CONTROLADAS', 'As mudanças de escopo, prazo ou custo foram avaliadas e aprovadas?', 'SIM_NAO'],
        ['PROJECT_LICAO_REGISTRADA', 'As lições aprendidas e decisões relevantes foram registradas?', 'SIM_NAO'],
    ],
    'EMPLOYEE_INTEGRATION' => [
        ['EMPLOYEE_DOCUMENTACAO_CONFERIDA', 'A documentação necessária para a integração do funcionário foi conferida?', 'SIM_NAO'],
        ['EMPLOYEE_FUNCAO_APRESENTADA', 'A função, responsabilidades e expectativas foram apresentadas ao funcionário?', 'SIM_NAO'],
        ['EMPLOYEE_REGRAS_EMPRESA', 'As regras, políticas e canais de comunicação da empresa foram apresentados?', 'SIM_NAO'],
        ['EMPLOYEE_SEGURANCA_TREINADA', 'Os treinamentos iniciais de segurança e prevenção aplicáveis foram realizados?', 'SIM_NAO'],
        ['EMPLOYEE_AMBIENTAL_TREINADA', 'As orientações ambientais aplicáveis à função foram apresentadas?', 'SIM_NAO'],
        ['EMPLOYEE_ACESSOS_LIBERADOS', 'Os acessos necessários para o trabalho foram liberados conforme autorização?', 'SIM_NAO'],
        ['EMPLOYEE_RECURSOS_DISPONIVEIS', 'Os recursos, equipamentos e infraestrutura necessários estão disponíveis?', 'SIM_NAO'],
        ['EMPLOYEE_CIENCIA_REGISTRADA', 'A ciência do funcionário sobre as orientações recebidas foi registrada?', 'SIM_NAO'],
    ],
];

$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'guniversal_dbp', 'GUNIVERSAL');
$pdo->beginTransaction();
$inserted = 0;
$counts = [];
try {
    $findVersion = $pdo->prepare(
        "SELECT v.codversao,v.status
           FROM amb_questionario q
           JOIN amb_questionario_versao v ON v.codquestionario=q.codquestionario
          WHERE q.codigo=:codigo AND v.versao=1
          LIMIT 1"
    );
    $findQuestion = $pdo->prepare(
        'SELECT codpergunta FROM amb_questionario_pergunta WHERE codversao=:versao AND codigo=:codigo LIMIT 1'
    );
    $insertQuestion = $pdo->prepare(
        'INSERT INTO amb_questionario_pergunta
            (codversao,codigo,texto_pt,tipo,obrigatoria,ordem,peso,ativo)
         VALUES (:versao,:codigo,:texto,:tipo,1,:ordem,1,1)'
    );
    foreach ($questionnaires as $questionnaire => $questions) {
        $findVersion->execute(['codigo' => $questionnaire]);
        $version = $findVersion->fetch(PDO::FETCH_ASSOC);
        if (!$version) throw new RuntimeException('Questionario nao encontrado: ' . $questionnaire);
        if ((string)$version['status'] !== 'RASCUNHO') throw new RuntimeException('Questionario nao esta em rascunho: ' . $questionnaire);
        $count = 0;
        foreach ($questions as $index => [$code, $text, $type]) {
            $findQuestion->execute(['versao' => (int)$version['codversao'], 'codigo' => $code]);
            if ($findQuestion->fetchColumn()) {
                $count++;
                continue;
            }
            $insertQuestion->execute([
                'versao' => (int)$version['codversao'],
                'codigo' => $code,
                'texto' => $text,
                'tipo' => $type,
                'ordem' => ($index + 1) * 10,
            ]);
            $inserted++;
            $count++;
        }
        $counts[$questionnaire] = $count;
    }
    $details = json_encode([
        'questionarios' => $counts,
        'perguntas_inseridas' => $inserted,
        'status' => 'RASCUNHO',
        'idempotente' => true,
        'fornecedor_foco' => 'prestador de servico em empresa cliente',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $migration = $pdo->prepare(
        "INSERT INTO green_universal_migrations(codigo_migracao,versao,status,detalhes)
         VALUES ('GREEN_QUESTIONNAIRE_EXAMPLES_206','4.0.1','CONCLUIDA',:detalhes)
         ON DUPLICATE KEY UPDATE status=VALUES(status),detalhes=VALUES(detalhes),executado_em=CURRENT_TIMESTAMP"
    );
    $migration->execute(['detalhes' => $details]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}

echo json_encode([
    'ok' => true,
    'migration' => '206',
    'status' => 'RASCUNHO',
    'questionarios' => $counts,
    'perguntas_inseridas' => $inserted,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
