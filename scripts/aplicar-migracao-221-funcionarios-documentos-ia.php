<?php
declare(strict_types=1);

/*
 * Migração 221 — Análise por IA dos documentos dos funcionários (ASO e certificados de treinamento).
 * Cria fo_funcionarios_documentos_ia e fo_sinc_protecoes e cadastra o prompt FUNCIONARIO_DOCUMENTO
 * (editável depois em Setup › Prompts de IA; uma nova execução não sobrescreve o prompt editado).
 * Aplica em todos os bancos de clientes que têm o módulo de Homologação. Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-221-funcionarios-documentos-ia.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$env = GreenPortalDatabase::env();
$sql = file_get_contents(dirname(__DIR__) . '/database/221_funcionarios_documentos_ia.sql');
if ($sql === false) throw new RuntimeException('Script SQL da migração 221 não encontrado.');

$regras = [
    'pt' => 'Nunca retorne diagnóstico, CID, doença, sintomas, medicamentos, resultados de exames clínicos ou complementares, nem qualquer outro dado de saúde além do resultado de aptidão do ASO.',
    'en' => 'Never return diagnosis, ICD codes, diseases, symptoms, medication, clinical or complementary exam results, or any other health data beyond the fitness result of the ASO.',
    'es' => 'Nunca devuelva diagnóstico, CIE, enfermedades, síntomas, medicamentos, resultados de exámenes clínicos o complementarios, ni ningún otro dato de salud además del resultado de aptitud del ASO.',
];
$formato = '{"tipo_documento":"ASO|TREINAMENTO|OUTRO","nome":null,"cpf":null,"rg":null,"funcao":null,"empresa":null,"cnpj":null,'
    . '"aso_tipo_exame":null,"aso_resultado":null,"aso_data_exame":null,"aso_validade":null,"aso_medico":null,"aso_crm":null,'
    . '"treinamento_nome":null,"treinamento_norma":null,"treinamento_carga_horaria":null,"treinamento_data_realizacao":null,'
    . '"treinamento_validade":null,"treinamento_instituicao":null,"treinamento_instrutor":null,"confianca":0,"alertas":[]}';
$prompt = [
    'pt' => 'Você analisa documentos de funcionários de empresas terceirizadas para a homologação de fornecedores. '
        . 'Identifique o tipo do documento: ASO (Atestado de Saúde Ocupacional), TREINAMENTO (certificado, declaração ou lista de presença de treinamento ou capacitação, por exemplo NR-06, NR-10, NR-12, NR-33, NR-35 ou integração de segurança) ou OUTRO. '
        . 'Extraia somente informações claramente presentes no texto. Não invente; use null quando a informação não existir. Datas no formato AAAA-MM-DD. '
        . $regras['pt'] . ' '
        . 'aso_tipo_exame: ADMISSIONAL, PERIODICO, RETORNO_AO_TRABALHO, MUDANCA_DE_RISCO ou DEMISSIONAL. aso_resultado: APTO, INAPTO ou APTO_COM_RESTRICAO. '
        . 'Não calcule validades: preencha aso_validade e treinamento_validade apenas se estiverem escritas no documento. treinamento_carga_horaria em horas (número). '
        . 'confianca de 0 a 100. Em alertas, informe dúvidas de leitura (por exemplo, texto manuscrito ilegível ou nome divergente). '
        . 'Retorne exclusivamente JSON válido no formato ' . $formato,
    'en' => 'You analyze documents of employees of outsourced companies for supplier approval. '
        . 'Identify the document type: ASO (occupational health certificate), TREINAMENTO (training certificate, statement or attendance list, e.g. NR-06, NR-10, NR-12, NR-33, NR-35 or safety induction) or OUTRO. '
        . 'Extract only information clearly present in the text. Do not invent; use null when the information does not exist. Dates in YYYY-MM-DD format. '
        . $regras['en'] . ' '
        . 'aso_tipo_exame: ADMISSIONAL, PERIODICO, RETORNO_AO_TRABALHO, MUDANCA_DE_RISCO or DEMISSIONAL. aso_resultado: APTO, INAPTO or APTO_COM_RESTRICAO. '
        . 'Do not calculate validity dates: fill aso_validade and treinamento_validade only if written in the document. treinamento_carga_horaria in hours (number). '
        . 'confianca from 0 to 100. In alertas, report reading doubts (for example illegible handwriting or a different name). '
        . 'Return only valid JSON in the format ' . $formato,
    'es' => 'Usted analiza documentos de empleados de empresas tercerizadas para la homologación de proveedores. '
        . 'Identifique el tipo de documento: ASO (certificado de salud ocupacional), TREINAMENTO (certificado, declaración o lista de asistencia de capacitación, por ejemplo NR-06, NR-10, NR-12, NR-33, NR-35 o inducción de seguridad) u OUTRO. '
        . 'Extraiga solo información claramente presente en el texto. No invente; use null cuando la información no exista. Fechas en formato AAAA-MM-DD. '
        . $regras['es'] . ' '
        . 'aso_tipo_exame: ADMISSIONAL, PERIODICO, RETORNO_AO_TRABALHO, MUDANCA_DE_RISCO o DEMISSIONAL. aso_resultado: APTO, INAPTO o APTO_COM_RESTRICAO. '
        . 'No calcule vigencias: complete aso_validade y treinamento_validade solo si están escritas en el documento. treinamento_carga_horaria en horas (número). '
        . 'confianca de 0 a 100. En alertas, informe dudas de lectura (por ejemplo, texto manuscrito ilegible o nombre divergente). '
        . 'Devuelva exclusivamente JSON válido en el formato ' . $formato,
];

$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES
    WHERE TABLE_NAME IN ('fo_funcionarios', 'registros_migracao') AND TABLE_SCHEMA LIKE '%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 2 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
// Só os bancos da homologação (classes/HomologacaoBancos.php; --banco= para só alguns)
$homologacao = HomologacaoBancos::lista($argv ?? []);
$bancos = array_values(array_intersect($homologacao, $bancos));

$resultado = [];
foreach (array_diff($homologacao, $bancos) as $semTabelas) $resultado[$semTabelas] = ['aviso' => 'banco sem as tabelas exigidas: não aplicado'];
$ok = true;
foreach ($bancos as $banco) {
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->exec($sql);
        $temPrompts = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ia_prompts'")->fetchColumn() > 0;
        $promptSituacao = 'tabela ia_prompts inexistente neste banco';
        if ($temPrompts) {
            $existe = (int)$pdo->query("SELECT COUNT(*) FROM ia_prompts WHERE codigo = 'FUNCIONARIO_DOCUMENTO'")->fetchColumn() > 0;
            if ($existe) {
                $promptSituacao = 'já existia (mantido, pode ter sido editado)';
            } else {
                $pdo->prepare('INSERT INTO ia_prompts (codigo, nome_pt, nome_en, nome_es, prompt_pt, prompt_en, prompt_es) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute(['FUNCIONARIO_DOCUMENTO', 'Documento de funcionário (ASO e treinamentos)', 'Employee document (ASO and training)', 'Documento de empleado (ASO y capacitaciones)', $prompt['pt'], $prompt['en'], $prompt['es']]);
                $promptSituacao = 'cadastrado';
            }
        }
        $detalhes = ['tabelas' => ['fo_funcionarios_documentos_ia', 'fo_sinc_protecoes'], 'prompt' => 'FUNCIONARIO_DOCUMENTO', 'prompt_situacao' => $promptSituacao, 'dados_saude' => 'diagnóstico e CID omitidos antes do envio à IA'];
        $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
            VALUES ('GREEN_FUNCIONARIOS_DOCUMENTOS_IA_221', 'gestao_arquivos.php?tipo=funcionarios', 'fo_funcionarios_documentos_ia;fo_sinc_protecoes;ia_prompts', 'ESTRUTURA', '4.0.1', 'CONCLUIDA', 2, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
            ->execute([json_encode($detalhes, JSON_UNESCAPED_UNICODE)]);
        $resultado[$banco] = $detalhes;
    } catch (Throwable $e) {
        $ok = false;
        $resultado[$banco] = ['erro' => $e->getMessage()];
    }
}

echo json_encode(['ok' => $ok && $bancos !== [], 'migracao' => '221', 'bancos' => $resultado], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
