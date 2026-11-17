<?php
declare(strict_types=1);

/*
 * Migração 308 — Análise por IA das evidências do Plano de ação da condicionante
 * (Manutenção legal › Licenças › Condicionantes › Plano de ação › Evidências; api/planodeacao_ia.php).
 *
 * Em cada banco de empresa com li_planodeacao e ia_prompts:
 *  - cadastra o prompt LI_PLANOACAO_DOCUMENTO (editável em Setup › Prompts de IA; não sobrescreve se já existir).
 * Não cria tabela: o histórico e a aprovação usam ged_analises_ia (migrações 222/223) e a gravação usa sp_crud_update.
 * Quem pode usar: perfis com "Editar" na transação LICENCAS.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-308-planodeacao-ia.php [--simular] [--banco=gcrown_dbp]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$simular = in_array('--simular', $argv ?? [], true);
$soBanco = null;
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--banco=')) $soBanco = array_filter(array_map('trim', explode(',', substr($a, 8))));
$env = GreenPortalDatabase::env();
$is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$is->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$bancos = $is->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('li_planodeacao', 'ia_prompts') AND TABLE_SCHEMA LIKE 'g%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 2 ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
if ($soBanco) $bancos = array_values(array_intersect($bancos, $soBanco));

$formato = '{"tipo_documento":null,"corresponde_a_condicionante":"SIM|NAO|INCERTO","acao":null,"responsavel":null,"data_inicial":null,"data_final":null,'
    . '"recursos":null,"status":null,"resumo":null,"confianca":0,"alertas":[]}';
$prompt = [
    'pt' => 'Você analisa evidências anexadas ao plano de ação de uma condicionante de licença ambiental ou operacional (por exemplo: relatórios de monitoramento, laudos de análise, '
        . 'protocolos de entrega ao órgão ambiental, ofícios, notas fiscais, ordens de serviço, certificados, fotos com legenda, atas, cronogramas). '
        . 'O texto começa com a LICENÇA, a CONDICIONANTE e o PLANO DE AÇÃO cadastrados; depois vem o texto do arquivo. '
        . 'Identifique o tipo do documento (tipo_documento, texto curto) e diga se ele é evidência da condicionante (corresponde_a_condicionante: SIM, NAO ou INCERTO). '
        . 'Preencha somente o que estiver claramente no documento e que sirva ao plano de ação: acao (o que foi ou será feito, frase objetiva), responsavel (nome da pessoa responsável, se escrito), '
        . 'data_inicial e data_final (início e conclusão/entrega/protocolo da ação, formato AAAA-MM-DD), recursos (custos, materiais, empresas contratadas, equipe), '
        . 'status (percentual de execução 0, 25, 50, 75 ou 100: use 100 quando o documento comprovar a conclusão ou a entrega/protocolo). '
        . 'Não invente nem calcule datas; use null quando a informação não existir. resumo: uma frase sobre o conteúdo. confianca de 0 a 100. '
        . 'Em alertas, informe dúvidas de leitura, documento que não corresponde à condicionante, datas incoerentes ou prazo vencido. '
        . 'Retorne exclusivamente JSON válido no formato ' . $formato,
    'en' => 'You analyze evidence attached to the action plan of an environmental or operating license condition (for example: monitoring reports, analysis reports, '
        . 'delivery receipts to the environmental agency, official letters, invoices, work orders, certificates, captioned photos, minutes, schedules). '
        . 'The text starts with the registered LICENSE, CONDITION and ACTION PLAN; then comes the file text. '
        . 'Identify the document type (tipo_documento, short text) and say whether it is evidence of the condition (corresponde_a_condicionante: SIM, NAO or INCERTO). '
        . 'Fill only what is clearly in the document and useful to the action plan: acao (what was or will be done, objective sentence), responsavel (name of the responsible person, if written), '
        . 'data_inicial and data_final (start and completion/delivery/filing of the action, YYYY-MM-DD format), recursos (costs, materials, contracted companies, team), '
        . 'status (execution percentage 0, 25, 50, 75 or 100: use 100 when the document proves completion or delivery/filing). '
        . 'Do not invent or calculate dates; use null when the information does not exist. resumo: one sentence about the content. confianca from 0 to 100. '
        . 'In alertas, report reading doubts, a document that does not match the condition, inconsistent dates or an overdue deadline. '
        . 'Return only valid JSON in the format ' . $formato,
    'es' => 'Usted analiza evidencias adjuntas al plan de acción de una condicionante de licencia ambiental u operativa (por ejemplo: informes de monitoreo, laudos de análisis, '
        . 'protocolos de entrega al órgano ambiental, oficios, facturas, órdenes de servicio, certificados, fotos con leyenda, actas, cronogramas). '
        . 'El texto comienza con la LICENCIA, la CONDICIONANTE y el PLAN DE ACCIÓN registrados; luego viene el texto del archivo. '
        . 'Identifique el tipo de documento (tipo_documento, texto corto) e indique si es evidencia de la condicionante (corresponde_a_condicionante: SIM, NAO o INCERTO). '
        . 'Complete solo lo que esté claramente en el documento y sirva al plan de acción: acao (lo que se hizo o se hará, frase objetiva), responsavel (nombre de la persona responsable, si está escrito), '
        . 'data_inicial y data_final (inicio y conclusión/entrega/protocolo de la acción, formato AAAA-MM-DD), recursos (costos, materiales, empresas contratadas, equipo), '
        . 'status (porcentaje de ejecución 0, 25, 50, 75 o 100: use 100 cuando el documento compruebe la conclusión o la entrega/protocolo). '
        . 'No invente ni calcule fechas; use null cuando la información no exista. resumo: una frase sobre el contenido. confianca de 0 a 100. '
        . 'En alertas, informe dudas de lectura, documento que no corresponde a la condicionante, fechas incoherentes o plazo vencido. '
        . 'Devuelva exclusivamente JSON válido en el formato ' . $formato,
];

$rel = ['ok' => true, 'migracao' => '308', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
foreach ($bancos as $banco) {
    try {
        $pdo = GreenPortalDatabase::connect($env, (string)$banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $s = $pdo->prepare("SELECT COUNT(*) FROM ia_prompts WHERE codigo = 'LI_PLANOACAO_DOCUMENTO'"); $s->execute(); $existe = (int)$s->fetchColumn() > 0; $s->closeCursor();
        $ged = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ged_analises_ia'")->fetchColumn() > 0;
        $info = ['prompt' => $existe ? 'já existia (mantido, pode ter sido editado)' : ($simular ? 'será cadastrado' : 'cadastrado'), 'historico_ged_analises_ia' => $ged ? 'ok' : 'AVISO: ged_analises_ia não existe (rodar 222/223)'];
        if (!$simular && !$existe)
            $pdo->prepare('INSERT INTO ia_prompts (codigo, nome_pt, nome_en, nome_es, prompt_pt, prompt_en, prompt_es) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute(['LI_PLANOACAO_DOCUMENTO', 'Evidência do plano de ação da condicionante (Licenças)', 'Condition action plan evidence (Licenses)', 'Evidencia del plan de acción de la condicionante (Licencias)', $prompt['pt'], $prompt['en'], $prompt['es']]);
        $rel['bancos'][$banco] = $info;
    } catch (Throwable $e) { $rel['ok'] = false; $rel['bancos'][$banco] = 'ERRO: ' . $e->getMessage(); }
}
if (!$bancos) $rel['bancos'] = 'nenhum banco com li_planodeacao e ia_prompts';
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
