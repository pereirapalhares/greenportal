<?php
declare(strict_types=1);

/*
 * Migração 261 — Análise por IA das evidências da Portaria › Documentação de Fornecedor.
 * Pré-requisito: migração 260 (view vw_portaria_documentacao_fornecedor_grid).
 *
 * Em cada banco de cliente (gatlas_dbp, gmodelo_dbp, gmodelo2_dbp):
 *  1. tabela fo_fornecedorxcriterios_documentos_ia (análises), fo_sinc_protecoes (se ainda não existir) e as
 *     procedures sp_docfornecedor_ia_* (database/261_portaria_documentacao_fornecedor_ia.sql);
 *  2. prompt FORNECEDOR_DOCUMENTO em ia_prompts (editável em Setup › Prompts de IA; não sobrescreve se já existir);
 *  3. registra em registros_migracao (PORTARIA_DOCUMENTACAO_FORNECEDOR_IA_261).
 * Por último, a migração 247 libera "Editar" (= analisar com IA e atualizar o documento) na transação
 * PORTARIA_DOCUMENTACAO_FORNECEDOR para os perfis informados (padrão: HOMOLOGACAO, ADM_DA_EMPRESA).
 * Sem "Editar" o perfil continua vendo e enviando arquivos, mas o botão de IA fica desabilitado.
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-261-portaria-documentacao-fornecedor-ia.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-261-portaria-documentacao-fornecedor-ia.php
 * Opções: --perfis-ia=HOMOLOGACAO,ADM_DA_EMPRESA   --sem-permissoes
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

define('M261_BANCOS', HomologacaoBancos::lista($argv ?? [])); // classes/HomologacaoBancos.php (--banco= para só alguns)

$opcao = static function (string $nome, string $padrao) use ($argv): string {
    foreach ($argv ?? [] as $a) if (str_starts_with($a, "--$nome=")) return trim(substr($a, strlen($nome) + 3));
    return $padrao;
};
$simular = in_array('--simular', $argv ?? [], true);
$semPermissoes = in_array('--sem-permissoes', $argv ?? [], true);
$perfisIa = strtoupper(preg_replace('/[^A-Za-z0-9_,]/', '', $opcao('perfis-ia', 'HOMOLOGACAO,ADM_DA_EMPRESA')) ?? '');
$env = GreenPortalDatabase::env();
$template = (string)file_get_contents(dirname(__DIR__) . '/database/261_portaria_documentacao_fornecedor_ia.sql');
$template = (string)preg_replace('/^--(?! @@).*$/m', '', $template);

$formato = '{"tipo_documento":null,"corresponde_ao_exigido":"SIM|NAO|INCERTO","numero_documento":null,"orgao_emissor":null,'
    . '"razao_social":null,"cnpj":null,"data_emissao":null,"data_validade":null,"resumo":null,"confianca":0,"alertas":[]}';
$prompt = [
    'pt' => 'Você analisa documentos enviados por fornecedores para a homologação de fornecedores (por exemplo: certidões negativas federais, estaduais, municipais, FGTS e trabalhistas, alvará, licença ambiental, contrato social, cartão CNPJ, apólice de seguro, PGR/PCMSO, ART, certificados). '
        . 'O texto começa com o DOCUMENTO EXIGIDO e o FORNECEDOR cadastrados; depois vem o texto do arquivo. '
        . 'Identifique o tipo do documento (tipo_documento, texto curto) e diga se ele corresponde ao documento exigido (corresponde_ao_exigido: SIM, NAO ou INCERTO). '
        . 'Extraia somente informações claramente presentes no texto. Não invente; use null quando a informação não existir. Datas no formato AAAA-MM-DD. '
        . 'Não calcule validades: preencha data_validade apenas se a validade ou o vencimento estiverem escritos no documento. '
        . 'resumo: uma frase sobre o conteúdo. confianca de 0 a 100. Em alertas, informe dúvidas de leitura, razão social ou CNPJ diferentes do cadastro, documento vencido ou que não corresponde ao exigido. '
        . 'Retorne exclusivamente JSON válido no formato ' . $formato,
    'en' => 'You analyze documents sent by suppliers for supplier approval (for example: federal, state, municipal, FGTS and labor clearance certificates, business license, environmental license, articles of association, CNPJ card, insurance policy, PGR/PCMSO, ART, certificates). '
        . 'The text starts with the REQUIRED DOCUMENT and the registered SUPPLIER; then comes the file text. '
        . 'Identify the document type (tipo_documento, short text) and say whether it matches the required document (corresponde_ao_exigido: SIM, NAO or INCERTO). '
        . 'Extract only information clearly present in the text. Do not invent; use null when the information does not exist. Dates in YYYY-MM-DD format. '
        . 'Do not calculate validity: fill data_validade only if the validity or expiration date is written in the document. '
        . 'resumo: one sentence about the content. confianca from 0 to 100. In alertas, report reading doubts, company name or CNPJ different from the record, expired document or one that does not match the required document. '
        . 'Return only valid JSON in the format ' . $formato,
    'es' => 'Usted analiza documentos enviados por proveedores para la homologación de proveedores (por ejemplo: certificados negativos federales, estatales, municipales, FGTS y laborales, licencia de funcionamiento, licencia ambiental, contrato social, tarjeta CNPJ, póliza de seguro, PGR/PCMSO, ART, certificados). '
        . 'El texto comienza con el DOCUMENTO EXIGIDO y el PROVEEDOR registrados; luego viene el texto del archivo. '
        . 'Identifique el tipo de documento (tipo_documento, texto corto) e indique si corresponde al documento exigido (corresponde_ao_exigido: SIM, NAO o INCERTO). '
        . 'Extraiga solo información claramente presente en el texto. No invente; use null cuando la información no exista. Fechas en formato AAAA-MM-DD. '
        . 'No calcule vigencias: complete data_validade solo si la validez o el vencimiento están escritos en el documento. '
        . 'resumo: una frase sobre el contenido. confianca de 0 a 100. En alertas, informe dudas de lectura, razón social o CNPJ diferentes del registro, documento vencido o que no corresponde al exigido. '
        . 'Devuelva exclusivamente JSON válido en el formato ' . $formato,
];

$relatorio = ['ok' => true, 'migracao' => '261', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
foreach (M261_BANCOS as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $um = static function (string $sql, array $p = []) use ($pdo): mixed { $s = $pdo->prepare($sql); $s->execute($p); $v = $s->fetchColumn(); $s->closeCursor(); return $v; };
        if (!(int)$um("SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vw_portaria_documentacao_fornecedor_grid'")) throw new RuntimeException('rode antes a migração 260');
        $cnpj = $um("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fo_fornecedores' AND LOWER(COLUMN_NAME) IN ('cnpj', 'cgc', 'cnpj_cpf', 'cpf_cnpj', 'nrcnpj') ORDER BY FIELD(LOWER(COLUMN_NAME), 'cnpj', 'cnpj_cpf', 'cpf_cnpj', 'nrcnpj', 'cgc') LIMIT 1");
        $info['cnpj'] = $cnpj ? "fo_fornecedores.$cnpj" : 'não existe (conferência de CNPJ desligada)';
        $sql = strtr($template, ['{{CNPJ}}' => $cnpj ? 'f.`' . str_replace('`', '', (string)$cnpj) . '`' : 'NULL']);
        $temPrompts = (int)$um("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ia_prompts'") > 0;
        $existePrompt = $temPrompts && (int)$um("SELECT COUNT(*) FROM ia_prompts WHERE codigo = 'FORNECEDOR_DOCUMENTO'") > 0;
        $info['prompt'] = !$temPrompts ? 'tabela ia_prompts inexistente neste banco' : ($existePrompt ? 'já existia (mantido, pode ter sido editado)' : 'será cadastrado');
        if (!$simular) {
            foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) { $cmd = trim($bloco); if ($cmd !== '') $pdo->exec($cmd); }
            $info['estrutura'] = 'tabela e procedures gravadas';
            if ($temPrompts && !$existePrompt) {
                $pdo->prepare('INSERT INTO ia_prompts (codigo, nome_pt, nome_en, nome_es, prompt_pt, prompt_en, prompt_es) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute(['FORNECEDOR_DOCUMENTO', 'Documento de fornecedor (Portaria / Homologação)', 'Supplier document (Gatehouse / Approval)', 'Documento de proveedor (Portería / Homologación)', $prompt['pt'], $prompt['en'], $prompt['es']]);
                $info['prompt'] = 'cadastrado';
            }
            // Conferência: a procedure de leitura responde para um documento qualquer
            $cod = (int)$um('SELECT CODFORNECEDORXCRITERIOS FROM vw_portaria_documentacao_fornecedor_grid LIMIT 1');
            if ($cod) { $s = $pdo->prepare('CALL sp_docfornecedor_ia_registro(?)'); $s->execute([$cod]); $r = $s->fetch(PDO::FETCH_ASSOC); $s->closeCursor(); while ($s->nextRowset()) {} $info['exemplo'] = $r ? ['codigo' => $cod, 'fornecedor' => $r['RAZAOSOCIAL'], 'cnpj' => $r['CNPJ'], 'documento' => $r['DSCRITERIO']] : 'procedure não devolveu o documento'; }
            if ((int)$um("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registros_migracao'")) {
                $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
                    VALUES ('PORTARIA_DOCUMENTACAO_FORNECEDOR_IA_261', 'gestao_arquivos.php?tipo=fornecedorcriterios', 'fo_fornecedorxcriterios_documentos_ia;sp_docfornecedor_ia_*;ia_prompts', 'ESTRUTURA', '4.0.1', 'CONCLUIDA', 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
                    ON DUPLICATE KEY UPDATE status = VALUES(status), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
                    ->execute([json_encode($info, JSON_UNESCAPED_UNICODE)]);
            }
        }
    } catch (Throwable $e) { $relatorio['ok'] = false; $info['erro'] = $e->getMessage(); }
    $relatorio['bancos'][$banco] = $info;
}
$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents(dirname(__DIR__) . '/storage/logs/migracao_261_portaria_documentacao_fornecedor_ia' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;

if (!$semPermissoes && $perfisIa !== '') {
    echo PHP_EOL . "== Permissão para usar a IA (Editar): $perfisIa ==" . PHP_EOL;
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'aplicar-migracao-247-permissoes-transacao.php')
        . " --transacao=PORTARIA_DOCUMENTACAO_FORNECEDOR --perfis=$perfisIa --acoes=editar" . ' --banco=' . implode(',', M261_BANCOS) . ($simular ? ' --simular' : ''), $rc); // só os bancos desta migração
    if ($rc !== 0) $relatorio['ok'] = false;
}
exit($relatorio['ok'] ? 0 : 1);
