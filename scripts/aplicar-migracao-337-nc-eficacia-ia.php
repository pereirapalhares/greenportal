<?php
declare(strict_types=1);

/*
 * Migração 337 — Não Conformidades: avaliação da eficácia com IA (23/10/2026).
 * Em cada banco de empresa com Não Conformidades (e no gmodelo_dbp, de onde os prompts são replicados):
 *  - cadastra o prompt NC_EFICACIA em pt/en/es (editável em Setup › Prompts de IA; não sobrescreve se já existir);
 *  - grava de novo database/336_nc_analise_ia.sql (nc_ia_analises ganha a coluna TIPO: CAUSA ou EFICACIA; as procedures passam a
 *    tratar os dois tipos e o alerta da resposta abre a aba certa) e database/337_nc_eficacia_ia.sql (sp_nc_ia_recorrencias).
 * O pedido roda no serviço "ia" do Agendador e a resposta chega nos Alertas, como na análise da causa (336).
 * Não cria menu (é o botão "Avaliar com IA" na aba "Verificação da eficácia") e não altera o legado.
 * Pode ser executada mais de uma vez. --simular só confere.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-337-nc-eficacia-ia.php [--simular] [--banco=gcrown_dbp]
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
foreach (['336_nc_analise_ia.sql' => 16, '337_nc_eficacia_ia.sql' => 2] as $arquivo => $minimo) {
    $n = 0;
    foreach (preg_split('/^-- @@\s*$/m', (string)@file_get_contents(dirname(__DIR__) . '/database/' . $arquivo)) as $b) {
        $limpo = trim(preg_replace('/^\s*--.*$/m', '', $b) ?? '');
        if ($limpo !== '') { $blocos[] = $limpo; $n++; }
    }
    if ($n < $minimo) { fwrite(STDERR, "database/$arquivo incompleto.\n"); exit(1); }
}

$formato = '{"parecer":"EFICAZ|NAO_EFICAZ|INCONCLUSIVO","confianca":0,"resumo":"","criterios":[{"criterio":"","avaliacao":"ATENDE|PARCIAL|NAO_ATENDE|SEM_DADOS","comentario":""}],'
    . '"acoes":[{"numero":1,"avaliacao":"ATENDE|PARCIAL|NAO_ATENDE|SEM_DADOS","comentario":""}],"recorrencias":[{"nc":"","comentario":""}],"falta_comprovar":[""],'
    . '"verificacao_da_eficacia":"","motivo_nao_eficaz":"","alertas":[]}';
$prompt = [
    'pt' => 'Você é auditor de sistemas de gestão (ISO 9001, ISO 14001, ISO 45001, FSSC 22000) e avalia a EFICÁCIA das ações corretivas de uma não conformidade (NC). '
        . 'Você recebe a OCORRÊNCIA, a ANÁLISE DA CAUSA, as CAUSAS, as AÇÕES DO PLANO (status, prazos, reprogramações, observações e os nomes dos arquivos de evidência anexados), '
        . 'a verificação já escrita pelo gestor e as NCS PARECIDAS ABERTAS DEPOIS desta. Regras: '
        . '1) Eficaz = as ações atacaram a causa raiz (não só o sintoma), foram concluídas e há evidência de que o problema não voltou. '
        . '2) Uma NC parecida aberta depois do envio para a eficácia (marcada "DEPOIS do envio") é forte indício de NÃO eficácia; antes do envio, indica recorrência anterior ao plano. Comente cada uma em recorrencias (nc = número). '
        . '3) Ação sem arquivo de evidência, com evidência cujo conteúdo não comprova a execução ou só com observação genérica deve ser avaliada como SEM_DADOS ou PARCIAL, e o que falta vai em falta_comprovar (ex.: registro de treinamento, ordem de serviço, medição após a ação, período de monitoramento). '
        . '3a) Nos comentários, diferencie sempre: (a) não há nenhum arquivo anexado → diga "nenhum arquivo anexado"; (b) há arquivo, mas o conteúdo não tem relação com a ação ou não a comprova → cite o nome do arquivo, diga em poucas palavras o que ele contém e por que não comprova; (c) o arquivo comprova → cite o nome do arquivo e o dado que comprova (data, registro, assinatura, medição). Nunca diga que não há evidência quando houver arquivo anexado. '
        . '4) parecer: EFICAZ, NAO_EFICAZ ou INCONCLUSIVO (quando faltam evidências para decidir); confianca de 0 a 100; resumo em até 3 frases. '
        . '5) criterios: avalie no máximo 6 critérios (por exemplo: ações atacam a causa raiz; ações concluídas no prazo; evidências da execução; resultado medido; sem recorrência; abrangência) com avaliacao ATENDE, PARCIAL, NAO_ATENDE ou SEM_DADOS e comentário curto. '
        . '6) acoes: uma entrada por ação, com numero igual ao da lista recebida, avaliacao e comentário curto. '
        . '7) verificacao_da_eficacia: texto corrido de até 1200 caracteres para o campo "Verificação da eficácia" da NC, com o que foi verificado e a conclusão. '
        . '8) motivo_nao_eficaz: só quando o parecer for NAO_EFICAZ, o motivo da devolução ao responsável e o que ele deve fazer (até 600 caracteres); senão "". '
        . '9) Use só as informações recebidas, inclusive o CONTEÚDO DAS EVIDÊNCIAS (texto lido dos arquivos por OCR, que pode ter erros; arquivo que não pôde ser lido conta como evidência não verificada); no comentário de cada ação cite o arquivo que a comprova. Não invente fatos, números, nomes ou datas. A decisão final é do gestor. '
        . 'Retorne exclusivamente JSON válido, sem Markdown, no formato ' . $formato,
    'en' => 'You are a management systems auditor (ISO 9001, ISO 14001, ISO 45001, FSSC 22000) evaluating the EFFECTIVENESS of the corrective actions of a nonconformity (NC). '
        . 'You receive the OCCURRENCE, the CAUSE ANALYSIS, the CAUSES, the PLAN ACTIONS (status, deadlines, rescheduling, notes and the names of the attached evidence files), '
        . 'the verification already written by the manager and the SIMILAR NCs OPENED AFTER this one. Rules: '
        . '1) Effective = the actions addressed the root cause (not only the symptom), were completed and there is evidence the problem has not come back. '
        . '2) A similar NC opened after the submission for effectiveness (marked "DEPOIS do envio") is a strong sign of NOT effective; before the submission, it shows recurrence prior to the plan. Comment each one in recorrencias (nc = number). '
        . '3) An action without an evidence file, with evidence whose content does not prove execution or with only a generic note must be rated SEM_DADOS or PARCIAL, and what is missing goes in falta_comprovar (e.g. training record, work order, measurement after the action, monitoring period). '
        . '3a) In the comments always distinguish: (a) no file attached at all → say "no file attached"; (b) there is a file but its content is unrelated to the action or does not prove it → cite the file name, say briefly what it contains and why it does not prove it; (c) the file proves it → cite the file name and the data that proves it (date, record, signature, measurement). Never say there is no evidence when a file is attached. '
        . '4) parecer: EFICAZ, NAO_EFICAZ or INCONCLUSIVO (when evidence is missing to decide); confianca from 0 to 100; resumo in up to 3 sentences. '
        . '5) criterios: rate at most 6 criteria (for example: actions address the root cause; actions completed on time; evidence of execution; measured result; no recurrence; scope) with avaliacao ATENDE, PARCIAL, NAO_ATENDE or SEM_DADOS and a short comment. '
        . '6) acoes: one entry per action, with numero equal to the received list, avaliacao and a short comment. '
        . '7) verificacao_da_eficacia: running text of up to 1200 characters for the NC "Effectiveness verification" field, with what was verified and the conclusion. '
        . '8) motivo_nao_eficaz: only when parecer is NAO_EFICAZ, the reason to return it to the responsible person and what to do (up to 600 characters); otherwise "". '
        . '9) Use only the information received, including the CONTEÚDO DAS EVIDÊNCIAS (text read from the files by OCR, which may contain errors; a file that could not be read counts as unverified evidence); in each action comment cite the file that proves it. Do not invent facts, numbers, names or dates. The final decision belongs to the manager. '
        . 'Return only valid JSON, without Markdown, in the format ' . $formato,
    'es' => 'Usted es auditor de sistemas de gestión (ISO 9001, ISO 14001, ISO 45001, FSSC 22000) y evalúa la EFICACIA de las acciones correctivas de una no conformidad (NC). '
        . 'Usted recibe la OCURRENCIA, el ANÁLISIS DE LA CAUSA, las CAUSAS, las ACCIONES DEL PLAN (estado, plazos, reprogramaciones, observaciones y los nombres de los archivos de evidencia adjuntos), '
        . 'la verificación ya escrita por el gestor y las NC SEMEJANTES ABIERTAS DESPUÉS de esta. Reglas: '
        . '1) Eficaz = las acciones atacaron la causa raíz (no solo el síntoma), se concluyeron y hay evidencia de que el problema no volvió. '
        . '2) Una NC semejante abierta después del envío a la eficacia (marcada "DEPOIS do envio") es fuerte indicio de NO eficacia; antes del envío, indica recurrencia anterior al plan. Comente cada una en recorrencias (nc = número). '
        . '3) Una acción sin archivo de evidencia, con evidencia cuyo contenido no comprueba la ejecución o solo con observación genérica debe evaluarse como SEM_DADOS o PARCIAL, y lo que falta va en falta_comprovar (ej.: registro de capacitación, orden de servicio, medición después de la acción, período de monitoreo). '
        . '3a) En los comentarios distinga siempre: (a) no hay ningún archivo adjunto → diga "ningún archivo adjunto"; (b) hay archivo, pero su contenido no tiene relación con la acción o no la comprueba → cite el nombre del archivo, diga en pocas palabras qué contiene y por qué no la comprueba; (c) el archivo la comprueba → cite el nombre del archivo y el dato que la comprueba (fecha, registro, firma, medición). Nunca diga que no hay evidencia cuando haya un archivo adjunto. '
        . '4) parecer: EFICAZ, NAO_EFICAZ o INCONCLUSIVO (cuando faltan evidencias para decidir); confianca de 0 a 100; resumo en hasta 3 frases. '
        . '5) criterios: evalúe como máximo 6 criterios (por ejemplo: las acciones atacan la causa raíz; acciones concluidas en plazo; evidencias de la ejecución; resultado medido; sin recurrencia; alcance) con avaliacao ATENDE, PARCIAL, NAO_ATENDE o SEM_DADOS y comentario corto. '
        . '6) acoes: una entrada por acción, con numero igual al de la lista recibida, avaliacao y comentario corto. '
        . '7) verificacao_da_eficacia: texto corrido de hasta 1200 caracteres para el campo "Verificación de la eficacia" de la NC, con lo verificado y la conclusión. '
        . '8) motivo_nao_eficaz: solo cuando el parecer sea NAO_EFICAZ, el motivo de la devolución al responsable y lo que debe hacer (hasta 600 caracteres); si no, "". '
        . '9) Use solo la información recibida, incluido el CONTEÚDO DAS EVIDÊNCIAS (texto leído de los archivos por OCR, que puede tener errores; un archivo que no pudo leerse cuenta como evidencia no verificada); en el comentario de cada acción cite el archivo que la comprueba. No invente hechos, números, nombres ni fechas. La decisión final es del gestor. '
        . 'Devuelva exclusivamente JSON válido, sin Markdown, en el formato ' . $formato,
];
$nomes = ['Avaliação da eficácia da não conformidade (NC)', 'Nonconformity effectiveness evaluation (NC)', 'Evaluación de la eficacia de la no conformidad (NC)'];

/** Cadastra o prompt se ainda não existe (o que já existe pode ter sido editado em Setup › Prompts de IA: fica como está). */
$cadastrarPrompt = static function (PDO $pdo) use ($simular, $prompt, $nomes, $valor): string {
    if ((int)$valor($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ia_prompts'") === 0) return 'AVISO: o banco não tem ia_prompts (rodar a migração 142)';
    if ((int)$valor($pdo, 'SELECT COUNT(*) FROM ia_prompts WHERE codigo = ?', ['NC_EFICACIA']) > 0) return 'já existia (mantido, pode ter sido editado)';
    if ($simular) return 'será cadastrado';
    $pdo->prepare('INSERT INTO ia_prompts (codigo, nome_pt, nome_en, nome_es, prompt_pt, prompt_en, prompt_es) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute(['NC_EFICACIA', $nomes[0], $nomes[1], $nomes[2], $prompt['pt'], $prompt['en'], $prompt['es']]);
    return 'cadastrado';
};

$rel = ['ok' => true, 'migracao' => '337', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
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
        if ($simular) $info['estrutura'] = 'coluna TIPO em nc_ia_analises, view e procedures (336 e 337) seriam gravadas';
        else {
            foreach ($blocos as $b) $pdo->exec($b);
            $info['estrutura'] = count($blocos) . ' blocos gravados (336 + 337)';
            $info['coluna_tipo'] = (int)$valor($pdo, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nc_ia_analises' AND COLUMN_NAME = 'TIPO'") ? 'ok' : 'FALTOU';
            // Conferência da busca de semelhantes (só leitura)
            $s = $pdo->prepare('CALL sp_nc_ia_recorrencias(?, ?, ?)'); $s->execute([0, '["vazamento"]', 1]); $s->fetchAll(); while ($s->nextRowset()) {} $s->closeCursor();
            $info['busca_recorrencias'] = 'ok';
        }
        $info['ncs_aguardando_eficacia'] = (int)$valor($pdo, "SELECT COUNT(*) FROM nc_indicacao_ocorrencia WHERE ETAPA LIKE 'Avalia%Eficacia'");
        $info['fila_ia_pendentes'] = (int)$valor($pdo, "SELECT COUNT(*) FROM green_ia_document_jobs WHERE status IN ('PENDENTE', 'PROCESSANDO')");
        $rel['bancos'][$banco] = $info;
    } catch (Throwable $e) { $rel['ok'] = false; $rel['bancos'][$banco] = $info + ['erro' => $e->getMessage()]; }
}
// Banco modelo: os prompts são replicados dele (scripts/replicar-prompts-ia.php); sem o NC_EFICACIA lá, a replicação não o leva
if (!isset($rel['bancos']['gmodelo_dbp'])) {
    try {
        $m = GreenPortalDatabase::connect($env, 'gmodelo_dbp', 'GPT');
        $m->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $m->exec('SET NAMES utf8mb4');
        $rel['modelo_gmodelo_dbp'] = ['prompt' => $cadastrarPrompt($m)];
    } catch (Throwable $e) { $rel['modelo_gmodelo_dbp'] = 'aviso: ' . $e->getMessage(); }
}
if (!$pares) $rel['bancos'] = 'nenhum banco encontrado';
$rel['servico_ia'] = 'o parecer é processado pelo serviço "ia" do Agendador (Administração › Serviços); a chave da IA vem do .env (GPT_AI_*)';
$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_337_nc_eficacia_ia' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
