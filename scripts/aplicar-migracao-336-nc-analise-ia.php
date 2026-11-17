<?php
declare(strict_types=1);

/*
 * Migração 336 — Não Conformidades: análise da causa com IA (23/10/2026).
 * Em cada banco de empresa com Não Conformidades (e no gmodelo_dbp, de onde os prompts são replicados):
 *  - cadastra o prompt NC_ANALISE_CAUSA em pt/en/es (editável em Setup › Prompts de IA; não sobrescreve se já existir);
 *  - grava a tabela nc_ia_analises, a view vw_nc_ia_analises e as procedures de database/336_nc_analise_ia.sql
 *    (sp_nc_ia_semelhantes, sp_nc_ia_analise_registrar, sp_nc_ia_analise_resultado, sp_nc_ia_analise_encerrar e, desde 23/10,
 *    sp_nc_ia_analise_concluida: o serviço "ia" avisa nos Alertas quem pediu quando a resposta chega ou dá erro).
 * Usa a fila de IA da migração 284 (green_ia_document_jobs), processada pelo serviço "ia" do Agendador
 * (scripts/windows-service.php ia). Não cria menu (é um botão na aba "Análise da causa e plano de ação") e não altera o legado.
 * Pode ser executada mais de uma vez. --simular só confere.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-336-nc-analise-ia.php [--simular] [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$simular = in_array('--simular', $argv ?? [], true);
try { $pares = HomologacaoBancos::pares($argv ?? []); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
$env = GreenPortalDatabase::env();
$is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$is->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$valor = static function (PDO $pdo, string $sql, array $p = []): mixed { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchColumn(); $s->closeCursor(); return $r; };
$tabela = static fn(string $db, string $t): bool => (int)$valor($is, 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db, $t]) > 0;
$rotina = static fn(string $db, string $r): bool => (int)$valor($is, 'SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? AND ROUTINE_NAME = ?', [$db, $r]) > 0;

$blocos = [];
foreach (preg_split('/^-- @@\s*$/m', (string)@file_get_contents(dirname(__DIR__) . '/database/336_nc_analise_ia.sql')) as $b) {
    $limpo = trim(preg_replace('/^\s*--.*$/m', '', $b) ?? '');
    if ($limpo !== '') $blocos[] = $limpo;
}
if (count($blocos) < 16) { fwrite(STDERR, "database/336_nc_analise_ia.sql incompleto.\n"); exit(1); }

$formato = '{"resumo_problema":"","cinco_porques":[""],"causas":[{"tipo_causa":null,"descricao":"","justificativa":"","confianca":0,"base":[]}],'
    . '"acoes":[{"causa":1,"acao":"","prioridade":"Alta|Média|Baixa","prazo_dias":30,"responsavel":null,"recursos":null,"base":[]}],"analise_da_causa":"","alertas":[]}';
$prompt = [
    'pt' => 'Você é especialista em gestão da qualidade, meio ambiente, saúde e segurança do trabalho e segurança de alimentos (ISO 9001, ISO 14001, ISO 45001, FSSC 22000), '
        . 'com prática em análise de causa raiz (5 porquês, Ishikawa/6M) e em ações corretivas. Você recebe uma não conformidade (NC): os dados da OCORRÊNCIA, a ANÁLISE, as CAUSAS e as AÇÕES '
        . 'já registradas, os TIPOS DE CAUSA da empresa e as NCS SEMELHANTES já tratadas na empresa, com as causas e ações delas. '
        . 'Sugira a análise da causa desta NC. Regras: '
        . '1) Busque a causa raiz, não o sintoma nem a correção imediata; não repita causas ou ações já cadastradas. '
        . '2) causas: no máximo 4; tipo_causa deve ser exatamente um dos TIPOS DE CAUSA listados (ou null); descricao objetiva (até 300 caracteres); '
        . 'justificativa curta ligando a causa aos fatos da ocorrência; confianca de 0 a 100; base = números das NCs semelhantes que apoiam a causa (ex.: "50005/2026") ou []. '
        . '3) acoes: no máximo 6 ações corretivas que eliminem a causa (evite apenas "treinar" ou "conscientizar" sem uma ação concreta; inclua padronização ou verificação quando couber); '
        . 'causa = número da causa sugerida (1 = primeira); acao começa com verbo no infinitivo (até 300 caracteres); prioridade Alta, Média ou Baixa; prazo_dias realista; '
        . 'responsavel = cargo ou área (nome de pessoa só se estiver escrito no texto) ou null; recursos curto ou null; base como nas causas. Cada causa deve ter pelo menos 1 ação. '
        . '4) cinco_porques: até 5 passos curtos ("Por que ...? Porque ...") que levam à causa principal. '
        . '5) analise_da_causa: texto corrido de até 1200 caracteres para o campo "Análise da causa" da NC, com o método usado e a conclusão. '
        . '6) resumo_problema: uma frase com o problema. '
        . '7) Use só as informações recebidas; não invente fatos, números, nomes ou datas. Em alertas, diga o que precisa ser verificado para confirmar as causas '
        . '(ex.: registros de manutenção, dados do processo, entrevista com o operador), se a NC parece recorrente pelas semelhantes e se faltam dados na ocorrência. '
        . 'Retorne exclusivamente JSON válido, sem Markdown, no formato ' . $formato,
    'en' => 'You are a specialist in quality, environmental, occupational health and safety and food safety management (ISO 9001, ISO 14001, ISO 45001, FSSC 22000), '
        . 'experienced in root cause analysis (5 whys, Ishikawa/6M) and corrective actions. You receive a nonconformity (NC): the OCCURRENCE data, the ANALYSIS, CAUSES and ACTIONS '
        . 'already recorded, the company CAUSE TYPES and the SIMILAR NCs already handled in the company, with their causes and actions. '
        . 'Suggest the cause analysis of this NC. Rules: '
        . '1) Look for the root cause, not the symptom or the immediate correction; do not repeat causes or actions already recorded. '
        . '2) causas: at most 4; tipo_causa must be exactly one of the listed CAUSE TYPES (or null); descricao objective (up to 300 characters); '
        . 'justificativa short, linking the cause to the facts of the occurrence; confianca from 0 to 100; base = numbers of the similar NCs that support the cause (e.g. "50005/2026") or []. '
        . '3) acoes: at most 6 corrective actions that eliminate the cause (avoid just "train" or "raise awareness" without a concrete action; include standardization or verification when applicable); '
        . 'causa = number of the suggested cause (1 = first); acao starts with a verb (up to 300 characters); prioridade Alta, Média or Baixa; realistic prazo_dias; '
        . 'responsavel = role or area (a person name only if written in the text) or null; recursos short or null; base as in the causes. Each cause must have at least 1 action. '
        . '4) cinco_porques: up to 5 short steps ("Why ...? Because ...") leading to the main cause. '
        . '5) analise_da_causa: running text of up to 1200 characters for the NC "Cause analysis" field, with the method used and the conclusion. '
        . '6) resumo_problema: one sentence with the problem. '
        . '7) Use only the information received; do not invent facts, numbers, names or dates. In alertas, say what must be checked to confirm the causes '
        . '(e.g. maintenance records, process data, operator interview), whether the NC seems recurrent given the similar ones and whether data is missing in the occurrence. '
        . 'Return only valid JSON, without Markdown, in the format ' . $formato,
    'es' => 'Usted es especialista en gestión de la calidad, medio ambiente, salud y seguridad en el trabajo e inocuidad de alimentos (ISO 9001, ISO 14001, ISO 45001, FSSC 22000), '
        . 'con práctica en análisis de causa raíz (5 porqués, Ishikawa/6M) y en acciones correctivas. Usted recibe una no conformidad (NC): los datos de la OCURRENCIA, el ANÁLISIS, las CAUSAS y las ACCIONES '
        . 'ya registradas, los TIPOS DE CAUSA de la empresa y las NC SEMEJANTES ya tratadas en la empresa, con sus causas y acciones. '
        . 'Sugiera el análisis de la causa de esta NC. Reglas: '
        . '1) Busque la causa raíz, no el síntoma ni la corrección inmediata; no repita causas o acciones ya registradas. '
        . '2) causas: como máximo 4; tipo_causa debe ser exactamente uno de los TIPOS DE CAUSA listados (o null); descricao objetiva (hasta 300 caracteres); '
        . 'justificativa corta que relacione la causa con los hechos de la ocurrencia; confianca de 0 a 100; base = números de las NC semejantes que apoyan la causa (ej.: "50005/2026") o []. '
        . '3) acoes: como máximo 6 acciones correctivas que eliminen la causa (evite solo "capacitar" o "concientizar" sin una acción concreta; incluya estandarización o verificación cuando corresponda); '
        . 'causa = número de la causa sugerida (1 = primera); acao empieza con un verbo en infinitivo (hasta 300 caracteres); prioridade Alta, Média o Baixa; prazo_dias realista; '
        . 'responsavel = cargo o área (nombre de persona solo si está escrito en el texto) o null; recursos corto o null; base como en las causas. Cada causa debe tener al menos 1 acción. '
        . '4) cinco_porques: hasta 5 pasos cortos ("¿Por qué ...? Porque ...") que llevan a la causa principal. '
        . '5) analise_da_causa: texto corrido de hasta 1200 caracteres para el campo "Análisis de la causa" de la NC, con el método usado y la conclusión. '
        . '6) resumo_problema: una frase con el problema. '
        . '7) Use solo la información recibida; no invente hechos, números, nombres ni fechas. En alertas, indique lo que debe verificarse para confirmar las causas '
        . '(ej.: registros de mantenimiento, datos del proceso, entrevista con el operador), si la NC parece recurrente por las semejantes y si faltan datos en la ocurrencia. '
        . 'Devuelva exclusivamente JSON válido, sin Markdown, en el formato ' . $formato,
];
$nomes = ['Análise da causa da não conformidade (NC)', 'Nonconformity cause analysis (NC)', 'Análisis de la causa de la no conformidad (NC)'];

/** Cadastra o prompt se ainda não existe (o que já existe pode ter sido editado em Setup › Prompts de IA: fica como está). */
$cadastrarPrompt = static function (PDO $pdo) use ($simular, $prompt, $nomes, $valor): string {
    if ((int)$valor($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ia_prompts'") === 0) return 'AVISO: o banco não tem ia_prompts (rodar a migração 142)';
    if ((int)$valor($pdo, 'SELECT COUNT(*) FROM ia_prompts WHERE codigo = ?', ['NC_ANALISE_CAUSA']) > 0) return 'já existia (mantido, pode ter sido editado)';
    if ($simular) return 'será cadastrado';
    $pdo->prepare('INSERT INTO ia_prompts (codigo, nome_pt, nome_en, nome_es, prompt_pt, prompt_en, prompt_es) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute(['NC_ANALISE_CAUSA', $nomes[0], $nomes[1], $nomes[2], $prompt['pt'], $prompt['en'], $prompt['es']]);
    return 'cadastrado';
};

$rel = ['ok' => true, 'migracao' => '336', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
foreach ($pares as [$legado, $banco]) {
    if (!$tabela($banco, 'nc_indicacao_ocorrencia') || !$tabela($banco, 'nc_causas')) { $rel['bancos'][$banco] = 'sem Não Conformidades: nada a fazer'; continue; }
    $info = [];
    try {
        if (!$rotina($banco, 'sp_nc_causa_salvar')) throw new RuntimeException('falta sp_nc_causa_salvar: rodar antes a migração 321');
        if (!$tabela($banco, 'green_ia_document_jobs')) throw new RuntimeException('falta a fila de IA green_ia_document_jobs: rodar antes a migração 284');
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $info['prompt'] = $cadastrarPrompt($pdo);
        if ($simular) $info['estrutura'] = 'tabela nc_ia_analises, view e procedures seriam gravadas';
        else {
            foreach ($blocos as $b) $pdo->exec($b);
            $info['estrutura'] = count($blocos) . ' blocos gravados (tabela, view e procedures)';
            // Conferência da busca de semelhantes (só leitura)
            $s = $pdo->prepare('CALL sp_nc_ia_semelhantes(?, ?, ?)'); $s->execute([0, '["vazamento"]', 1]); $s->fetchAll(); while ($s->nextRowset()) {} $s->closeCursor();
            $info['busca_semelhantes'] = 'ok';
        }
        $info['ncs_com_causa'] = (int)$valor($pdo, 'SELECT COUNT(DISTINCT CODOCORRENCIA) FROM nc_causas');
        $info['fila_ia_pendentes'] = (int)$valor($pdo, "SELECT COUNT(*) FROM green_ia_document_jobs WHERE status IN ('PENDENTE', 'PROCESSANDO')");
        $rel['bancos'][$banco] = $info;
    } catch (Throwable $e) { $rel['ok'] = false; $rel['bancos'][$banco] = $info + ['erro' => $e->getMessage()]; }
}
// Banco modelo: os prompts são replicados dele (scripts/replicar-prompts-ia.php); sem o NC_ANALISE_CAUSA lá, a replicação não o leva
if (!isset($rel['bancos']['gmodelo_dbp'])) {
    try {
        $m = GreenPortalDatabase::connect($env, 'gmodelo_dbp', 'GPT');
        $m->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $m->exec('SET NAMES utf8mb4');
        $rel['modelo_gmodelo_dbp'] = ['prompt' => $cadastrarPrompt($m)];
    } catch (Throwable $e) { $rel['modelo_gmodelo_dbp'] = 'aviso: ' . $e->getMessage(); }
}
if (!$pares) $rel['bancos'] = 'nenhum banco encontrado';
$rel['servico_ia'] = 'a sugestão é processada pelo serviço "ia" do Agendador (Administração › Serviços); a chave da IA vem do .env (GPT_AI_*)';
$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_336_nc_analise_ia' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
