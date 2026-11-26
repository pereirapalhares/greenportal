<?php
declare(strict_types=1);

/*
 * Migração 418 — IA preenchendo Medição e RNC a partir do arquivo enviado (Revisão G itens 79 e 160, 26/11/2026).
 * Mesmo fluxo de Controle, Treinamento e Infraestrutura (migração 174): na Gestão de arquivos do registro, o botão
 * "Analisar com IA" lê o arquivo, a IA sugere os campos e o usuário confere antes de gravar.
 * Em cada empresa:
 *   1. mm_monitoramento.DADOS_DOCUMENTO_IA (JSON, última extração confirmada);
 *   2. prompts MEDICAO_DOCUMENTO, NC_OCORRENCIA_DOCUMENTO e SITUACAO_LEGAL_SUGESTAO (item 31) em ia_prompts (Administração › Prompts de IA), só se faltarem
 *      (um prompt já editado pela empresa não é trocado).
 * Na RNC a gravação passa por sp_nc_ocorrencia_salvar (só na criação ou devolvida pelo gestor, pelo emitente).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-418-ia-medicao-rnc.php [--simular] [--banco=gcrown_dbp]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$rel = ['ok' => true, 'migracao' => '418', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];

$PROMPTS = [
    'MEDICAO_DOCUMENTO' => [
        'nome' => ['Preenchimento de medição por documento', 'Measurement completion from document', 'Completar medición desde documento'],
        'pt' => 'Leia o documento de medição ou monitoramento (laudo, relatório de ensaio, boletim de análise ou planilha de resultados) e extraia somente informações claramente identificadas. '
            . 'Retorne exclusivamente JSON válido no formato {"parametros":null,"data_medicao":null,"resultado":null,"limite":null,"conforme":null,"laboratorio":null,"observacao":null,"confianca":0,"alertas":[]}. '
            . '"parametros": parâmetros medidos (ex.: pH, DBO, ruído dB(A)), em uma linha; "data_medicao": data da coleta ou medição no formato AAAA-MM-DD; '
            . '"resultado": resultados principais com unidade; "limite": limite legal ou de referência citado; "conforme": "Sim" ou "Não" quando o documento disser se atende; '
            . '"laboratorio": laboratório ou responsável técnico; "observacao": resumo curto do que o documento conclui. Não invente informações. Use null quando não estiver claramente disponível.',
        'en' => 'Read the measurement or monitoring document (lab report, test report, analysis bulletin or results sheet) and extract only clearly identified information. '
            . 'Return exclusively valid JSON in the format {"parametros":null,"data_medicao":null,"resultado":null,"limite":null,"conforme":null,"laboratorio":null,"observacao":null,"confianca":0,"alertas":[]} '
            . '("data_medicao" as YYYY-MM-DD; "conforme" "Sim" or "Não"). Do not invent information. Use null when not clearly available.',
        'es' => 'Lea el documento de medición o monitoreo (laudo, informe de ensayo, boletín de análisis o planilla de resultados) y extraiga únicamente información claramente identificada. '
            . 'Devuelva exclusivamente JSON válido en el formato {"parametros":null,"data_medicao":null,"resultado":null,"limite":null,"conforme":null,"laboratorio":null,"observacao":null,"confianca":0,"alertas":[]} '
            . '("data_medicao" como AAAA-MM-DD; "conforme" "Sim" o "Não"). No invente información. Use null cuando no esté claramente disponible.',
    ],
    'NC_OCORRENCIA_DOCUMENTO' => [
        'nome' => ['Preenchimento de não conformidade por documento', 'Nonconformity completion from document', 'Completar no conformidad desde documento'],
        'pt' => 'Leia o documento que relata a não conformidade (reclamação de cliente, relatório de inspeção, e-mail, laudo, fotos com legenda) e extraia somente informações claramente identificadas. '
            . 'Retorne exclusivamente JSON válido no formato {"sumario":null,"descricao":null,"providencia":null,"data_ocorrencia":null,"produto":null,"codigo_produto":null,"cliente":null,"numero_documento":null,"quantidade_envolvida":null,"confianca":0,"alertas":[]}. '
            . '"sumario": título curto do problema (até 150 caracteres); "descricao": o que aconteceu, onde e quando, com os fatos do documento; "providencia": ação imediata já tomada, se citada; '
            . '"data_ocorrencia": AAAA-MM-DD; "produto" e "codigo_produto": produto envolvido; "cliente": cliente que reclamou; "numero_documento": nota fiscal, pedido ou número da reclamação; '
            . '"quantidade_envolvida": quantidade com unidade. Não invente informações. Use null quando não estiver claramente disponível.',
        'en' => 'Read the document reporting the nonconformity (customer complaint, inspection report, e-mail, lab report) and extract only clearly identified information. '
            . 'Return exclusively valid JSON in the format {"sumario":null,"descricao":null,"providencia":null,"data_ocorrencia":null,"produto":null,"codigo_produto":null,"cliente":null,"numero_documento":null,"quantidade_envolvida":null,"confianca":0,"alertas":[]} '
            . '("data_ocorrencia" as YYYY-MM-DD). Do not invent information. Use null when not clearly available.',
        'es' => 'Lea el documento que relata la no conformidad (reclamo de cliente, informe de inspección, e-mail, laudo) y extraiga únicamente información claramente identificada. '
            . 'Devuelva exclusivamente JSON válido en el formato {"sumario":null,"descricao":null,"providencia":null,"data_ocorrencia":null,"produto":null,"codigo_produto":null,"cliente":null,"numero_documento":null,"quantidade_envolvida":null,"confianca":0,"alertas":[]} '
            . '("data_ocorrencia" como AAAA-MM-DD). No invente información. Use null cuando no esté claramente disponible.',
    ],
];

// Item 31: a IA sugere a situação de atendimento do requisito (tela Situação dos Requisitos legais); o código tem o mesmo texto como reserva
$PROMPTS['SITUACAO_LEGAL_SUGESTAO'] = [
    'nome' => ['Sugestão da situação de atendimento legal', 'Legal compliance status suggestion', 'Sugerencia de la situación de atención legal'],
    'pt' => 'Você é um auditor de conformidade legal. Com base no requisito da lei e nas evidências vinculadas (licenças, medições, treinamentos, infraestrutura, controles e fornecedores, com o status de cada uma), '
        . 'sugira a situação de atendimento do requisito na unidade. Retorne exclusivamente JSON válido: {"atendido":"S"|"N"|"PA","descricao":"texto curto da situação, citando as evidências que sustentam","faltas":["o que falta para atender"],"confianca":0}. '
        . 'Use "S" só quando as evidências cobrem o requisito e estão em dia; "PA" quando há evidência mas com falhas ou incompleta; "N" quando não há evidência ou ela está vencida/crítica. Não invente evidências.',
    'en' => 'You are a legal compliance auditor. Based on the law requirement and the linked evidence (licenses, measurements, training, infrastructure, controls and suppliers, with each status), suggest the compliance status of the requirement at the unit. '
        . 'Return exclusively valid JSON: {"atendido":"S"|"N"|"PA","descricao":"short status text citing the supporting evidence","faltas":["what is missing"],"confianca":0}. Do not invent evidence.',
    'es' => 'Usted es un auditor de cumplimiento legal. Con base en el requisito de la ley y en las evidencias vinculadas (licencias, mediciones, capacitaciones, infraestructura, controles y proveedores, con el estado de cada una), sugiera la situación de atención del requisito en la unidad. '
        . 'Devuelva exclusivamente JSON válido: {"atendido":"S"|"N"|"PA","descricao":"texto corto de la situación, citando las evidencias","faltas":["lo que falta"],"confianca":0}. No invente evidencias.',
];

$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'perfis', 'permissoes', 'usuarios') AND TABLE_SCHEMA LIKE '%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 4 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp')
    && preg_match('/^g[a-z0-9_]+_dbp$/', (string)$b) && ($soBanco === null || $b === $soBanco)));

foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4');
        $cols = array_flip(MigracaoCrud::colunas($pdo, 'mm_monitoramento'));
        if (!$cols) $info['mm_monitoramento'] = 'não existe';
        elseif (isset($cols['dados_documento_ia'])) $info['mm_monitoramento.DADOS_DOCUMENTO_IA'] = 'já existe';
        else {
            if (!$simular) $pdo->exec("ALTER TABLE `mm_monitoramento` ADD COLUMN `DADOS_DOCUMENTO_IA` JSON NULL COMMENT 'Última extração assistida da evidência da medição (migração 418)'");
            $info['mm_monitoramento.DADOS_DOCUMENTO_IA'] = $simular ? 'a criar' : 'criada';
        }
        if (!MigracaoCrud::existe($pdo, 'ia_prompts')) { $info['prompts'] = 'sem ia_prompts nesta empresa'; $rel['bancos'][$banco] = $info; continue; }
        if (!$simular) MigracaoCrud::garantir($pdo);
        foreach ($PROMPTS as $codigo => $p) {
            $existe = MigracaoCrud::primeiro($pdo, 'ia_prompts', ['codigo' => $codigo]);
            if ($existe) { $info[$codigo] = 'já existe (mantido)'; continue; }
            if (!$simular) MigracaoCrud::inserir($pdo, 'ia_prompts', ['codigo' => $codigo, 'nome_pt' => $p['nome'][0], 'nome_en' => $p['nome'][1], 'nome_es' => $p['nome'][2],
                'prompt_pt' => $p['pt'], 'prompt_en' => $p['en'], 'prompt_es' => $p['es']]);
            $info[$codigo] = $simular ? 'a criar' : 'criado';
        }
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
