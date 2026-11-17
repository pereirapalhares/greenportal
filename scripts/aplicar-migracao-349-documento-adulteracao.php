<?php
declare(strict_types=1);

/*
 * Migração 349 — Análise de adulteração dos documentos da homologação (Onda 3 da apresentação Atlas Schindler 2026:
 * "IA aplicada aos documentos — 3. Análise de adulteração").
 *
 * Universal: a Ajuda (Portaria › Documentação de fornecedor/funcionário e Gestão de Fornecedores/Funcionários).
 * Cada empresa da homologação (classes/HomologacaoBancos.php; precisa das migrações 261, 262 e 284):
 *   1. database/349_documento_adulteracao.sql — análises (fo_doc_adulteracao), procedures sp_doc_adult_*, views vw_doc_adult*
 *      e os gatilhos que recusam aprovar documento com risco alto sem ciência (o sincronismo do legado não é bloqueado);
 *   2. prompt DOC_ADULTERACAO em ia_prompts (leitura do conteúdo pela IA; editável em Setup › Prompts de IA; não sobrescreve);
 *   3. com as regras automáticas da 301 instaladas: regrava database/301_regras_documentos_homologacao.sql com as regras
 *      FORN_ADULTERACAO e FUNC_ADULTERACAO, que nascem ligadas só onde as outras regras do mesmo escopo estão ligadas;
 *   4. registros_migracao (DOCUMENTO_ADULTERACAO_349).
 * A partir da aplicação, cada arquivo novo é analisado pelo serviço "ia" do Agendador (GreenPortal-IaWorker); os arquivos que já
 * estavam no GED só pelo botão "Analisar este arquivo" (Gestão de Arquivos › Adulteração). A análise só aponta indícios.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-349-documento-adulteracao.php [--simular] [--banco=gatlas_dbp]
 * Pode ser executada mais de uma vez (a marca de ativação não muda).
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$env = GreenPortalDatabase::env();
$rel = ['ok' => true, 'migracao' => '349', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'php' => [], 'universal' => [], 'bancos' => []];

// O que este servidor consegue conferir (sem a extensão, a verificação correspondente fica de fora e a análise segue)
$rel['php'] = ['openssl_cms_verify (assinatura digital)' => function_exists('openssl_cms_verify') ? 'sim' : 'NÃO — habilite a extensão openssl no php.ini',
    'exif_read_data (dados EXIF de fotos)' => function_exists('exif_read_data') ? 'sim' : 'não — habilite a extensão exif no php.ini (o programa de edição ainda é procurado no arquivo)',
    'imagecreatefromjpeg (regiões coladas em JPEG)' => function_exists('imagecreatefromjpeg') ? 'sim' : 'não — habilite a extensão gd no php.ini',
    'gzuncompress (metadados comprimidos do PDF)' => function_exists('gzuncompress') ? 'sim' : 'NÃO', 'ZipArchive (metadados do Word)' => class_exists('ZipArchive') ? 'sim' : 'não'];

// ---------------------------------------------------------------- universal: ajuda
try {
    $u = (new MenuCatalogoService($env))->universal();
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $rel['universal']['ajuda'] = gravarAjuda349($u, require dirname(__DIR__) . '/database/349_ajuda_documento_adulteracao.php', $simular);
} catch (Throwable $e) { $rel['ok'] = false; $rel['universal']['erro'] = $e->getMessage(); }

// ---------------------------------------------------------------- prompt da leitura do conteúdo
$formato = '{"indicios":[{"tipo":"DATA|VALIDADE|EMISSOR|NUMERACAO|IDENTIFICACAO|TEXTO|OUTRO","descricao":"...","gravidade":"BAIXA|MEDIA|ALTA","evidencia":"trecho curto"}],"conclusao":"SEM_INDICIOS|SUSPEITO","resumo":"..."}';
$prompt = [
    'pt' => 'Você confere se um documento enviado na homologação de fornecedores (certidões, alvarás, licenças, ASO, certificados de treinamento NR, apólices, ART etc.) tem sinais de adulteração ou de falsificação. '
        . 'O texto começa com o DOCUMENTO EXIGIDO, o titular, a DATA DE HOJE, os METADADOS DO ARQUIVO e os INDÍCIOS TÉCNICOS já apontados pelo sistema (não os repita); depois do "---" vem o texto lido do arquivo. '
        . 'Aponte só incoerências que o texto mostra: datas impossíveis ou fora de ordem (emissão depois da validade, emissão futura), validade maior que a usual para o tipo (ex.: certidões federais e trabalhistas 180 dias, CRF do FGTS 30 dias, ASO em geral 12 meses), '
        . 'órgão emissor incompatível com o tipo de documento, numeração, código de controle ou de autenticação em formato estranho, titular, CNPJ ou nome diferentes em partes do documento, texto que contradiz o próprio documento, erros de português incomuns em documento oficial, e metadados incompatíveis com o emissor (ex.: certidão de órgão público gerada em editor de imagem). '
        . 'Não invente: sem indício claro, devolva a lista vazia. Não avalie aparência que você não vê. Gravidade: ALTA só com contradição objetiva; MEDIA para algo que precisa ser conferido com o emissor; BAIXA para detalhes. No máximo 8 indícios. '
        . 'Na evidência, cite um trecho curto do texto; não copie dados de saúde. resumo: uma frase. Retorne exclusivamente JSON válido no formato ' . $formato,
    'en' => 'You check whether a document submitted in supplier approval (clearance certificates, permits, licenses, occupational health certificates, NR training certificates, insurance policies, ART etc.) shows signs of tampering or forgery. '
        . 'The text starts with the REQUIRED DOCUMENT, the holder, TODAY\'S DATE, the FILE METADATA and the TECHNICAL SIGNS already found by the system (do not repeat them); after "---" comes the text read from the file. '
        . 'Point out only inconsistencies the text shows: impossible or out-of-order dates (issued after expiration, future issue date), validity longer than usual for the type, issuing body incompatible with the document type, '
        . 'numbering, control or authentication code in an odd format, holder, CNPJ or name differing across the document, text that contradicts the document itself, unusual language errors in an official document, and metadata incompatible with the issuer. '
        . 'Do not invent: without a clear sign, return an empty list. Do not judge appearance you cannot see. Severity: ALTA only for an objective contradiction; MEDIA for something to confirm with the issuer; BAIXA for details. At most 8 signs. '
        . 'In the evidence quote a short excerpt; do not copy health data. resumo: one sentence. Return only valid JSON in the format ' . $formato,
    'es' => 'Usted verifica si un documento enviado en la homologación de proveedores (certificados, permisos, licencias, ASO, certificados de capacitación NR, pólizas, ART etc.) tiene señales de adulteración o falsificación. '
        . 'El texto comienza con el DOCUMENTO EXIGIDO, el titular, la FECHA DE HOY, los METADATOS DEL ARCHIVO y los INDICIOS TÉCNICOS ya señalados por el sistema (no los repita); después de "---" viene el texto leído del archivo. '
        . 'Señale solo incoherencias que el texto muestra: fechas imposibles o fuera de orden (emisión después del vencimiento, emisión futura), validez mayor que la usual para el tipo, órgano emisor incompatible con el tipo de documento, '
        . 'numeración, código de control o de autenticación en formato extraño, titular, CNPJ o nombre diferentes en partes del documento, texto que contradice el propio documento, errores de lenguaje inusuales en un documento oficial, y metadatos incompatibles con el emisor. '
        . 'No invente: sin indicio claro, devuelva la lista vacía. No evalúe apariencia que no ve. Gravedad: ALTA solo con contradicción objetiva; MEDIA para algo a confirmar con el emisor; BAIXA para detalles. Como máximo 8 indicios. '
        . 'En la evidencia cite un fragmento corto; no copie datos de salud. resumo: una frase. Devuelva exclusivamente JSON válido en el formato ' . $formato,
];

// ---------------------------------------------------------------- empresas da homologação
$limpar = static fn(string $sql): array => array_values(array_filter(array_map('trim', preg_split('/^-- @@\s*$/m', (string)preg_replace('/^--(?! @@).*$/m', '', $sql))), static fn($b) => $b !== ''));
$blocos = $limpar((string)file_get_contents(dirname(__DIR__) . '/database/349_documento_adulteracao.sql'));
$blocos301 = $limpar((string)file_get_contents(dirname(__DIR__) . '/database/301_regras_documentos_homologacao.sql'));
foreach (HomologacaoBancos::lista($args) as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $um = static function (string $q, array $p = []) use ($pdo): mixed { $s = $pdo->prepare($q); $s->execute($p); $v = $s->fetchColumn(); $s->closeCursor(); return $v; };
        $tem = static fn(string $tipo, string $nome): bool => (int)$um($tipo === 'R'
            ? 'SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = ?'
            : 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$nome]) > 0;
        $faltam = [];
        foreach (['fo_fornecedores', 'fo_criteriofornecedores', 'fo_criteriofuncionarios', 'fo_fornecedorxcriterios', 'fo_funcionarioxtiposxcriterios', 'fo_funcionarios', 'green_ocr_jobs', 'green_ia_document_jobs', 'ia_prompts', 'alertas', 'usuarios'] as $t) if (!$tem('T', $t)) $faltam[] = $t;
        foreach (['sp_docfornecedor_ia_registro', 'sp_docfuncionario_ia_registro', 'sp_ia_prompt_obter', 'sp_alerta_criar'] as $r) if (!$tem('R', $r)) $faltam[] = $r . '()';
        if ($faltam) throw new RuntimeException('faltam objetos das migrações anteriores (261, 262, 222, 284): ' . implode(', ', $faltam));
        $info['documentos'] = ['fornecedor' => (int)$um("SELECT COUNT(*) FROM fo_fornecedorxcriterios"), 'funcionario' => (int)$um("SELECT COUNT(*) FROM fo_funcionarioxtiposxcriterios")];
        $info['gatilhos_existentes'] = array_column($pdo->query("SELECT CONCAT(TRIGGER_NAME, ' (', ACTION_TIMING, ' ', EVENT_MANIPULATION, ' ', EVENT_OBJECT_TABLE, ')') AS t FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE IN ('fo_fornecedorxcriterios', 'fo_funcionarioxtiposxcriterios') AND TRIGGER_NAME NOT LIKE 'trg_fo_doc_adult_%'")->fetchAll(PDO::FETCH_ASSOC), 't');
        $tem301 = $tem('T', 'fo_regras_documentos');
        $existePrompt = (int)$um("SELECT COUNT(*) FROM ia_prompts WHERE codigo = 'DOC_ADULTERACAO'") > 0;
        $jaTinha = $tem('T', 'fo_doc_adulteracao');
        if ($simular) {
            $info['estrutura'] = $jaTinha ? 'será regravada (análises existentes mantidas)' : 'será criada; arquivos enviados a partir da aplicação serão analisados';
            $info['prompt'] = $existePrompt ? 'já existia (mantido)' : 'será cadastrado';
            $info['regras_301'] = $tem301 ? 'regras FORN_ADULTERACAO / FUNC_ADULTERACAO serão incluídas (' . regrasAtivas($pdo) . ')' : 'sem as regras automáticas da 301 neste banco';
        } else {
            foreach ($blocos as $b) $pdo->exec($b);
            $info['estrutura'] = count($blocos) . ' blocos gravados';
            $info['ativada_em'] = date('d/m/Y H:i', (int)$um("SELECT VALOR FROM fo_doc_adulteracao_controle WHERE CHAVE = 'ATIVADO_TS'"));
            if (!$existePrompt) {
                $pdo->prepare('INSERT INTO ia_prompts (codigo, nome_pt, nome_en, nome_es, prompt_pt, prompt_en, prompt_es) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute(['DOC_ADULTERACAO', 'Análise de adulteração de documento (Homologação)', 'Document tampering analysis (Approval)', 'Análisis de adulteración de documento (Homologación)', $prompt['pt'], $prompt['en'], $prompt['es']]);
                $info['prompt'] = 'cadastrado';
            } else $info['prompt'] = 'já existia (mantido, pode ter sido editado)';
            if ($tem301) {
                $novas = array_values(array_filter(['FORN_ADULTERACAO', 'FUNC_ADULTERACAO'], static fn($c) => (int)$um('SELECT COUNT(*) FROM fo_regras_documentos WHERE codregra = ?', [$c]) === 0));
                foreach ($blocos301 as $b) $pdo->exec($b);
                // Regras novas seguem as outras do mesmo escopo: ligadas onde a empresa usa as regras automáticas, desligadas onde não usa
                foreach ($novas as $c) {
                    $escopo = $c === 'FORN_ADULTERACAO' ? 'FORNECEDOR' : 'FUNCIONARIO';
                    $ativo = (int)$um('SELECT COALESCE(MAX(ativo), 0) FROM fo_regras_documentos WHERE escopo = ? AND codregra <> ?', [$escopo, $c]);
                    $pdo->prepare('UPDATE fo_regras_documentos SET ativo = ? WHERE codregra = ?')->execute([$ativo, $c]);
                }
                $info['regras_301'] = 'regravadas (' . regrasAtivas($pdo) . ')';
            } else $info['regras_301'] = 'sem as regras automáticas da 301 neste banco';
            if ($tem('T', 'registros_migracao'))
                $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
                    VALUES ('DOCUMENTO_ADULTERACAO_349', 'Gestão de Arquivos › Adulteração', 'fo_doc_adulteracao;sp_doc_adult_*;trg_fo_doc_adult_*', 'ESTRUTURA', '4.0.1', 'CONCLUIDA', 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
                    ON DUPLICATE KEY UPDATE status = VALUES(status), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")->execute([json_encode($info, JSON_UNESCAPED_UNICODE)]);
        }
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}
$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$logs = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logs)) @mkdir($logs, 0775, true);
@file_put_contents($logs . DIRECTORY_SEPARATOR . 'migracao_349_documento_adulteracao' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);

function regrasAtivas(PDO $pdo): string
{
    $l = $pdo->query("SELECT escopo, SUM(ativo) AS ligadas, COUNT(*) AS total FROM fo_regras_documentos GROUP BY escopo ORDER BY escopo")->fetchAll(PDO::FETCH_ASSOC);
    return implode('; ', array_map(static fn($r) => $r['escopo'] . ': ' . (int)$r['ligadas'] . ' de ' . (int)$r['total'] . ' ligadas', $l));
}

function gravarAjuda349(PDO $u, array $textos, bool $simular): array
{
    if (!MigracaoCrud::existe($u, 'ajuda_transacoes')) return ['aviso' => 'sem ajuda_transacoes no universal'];
    $out = [];
    foreach ($textos as $transacao => $d) {
        $atual = MigracaoCrud::primeiro($u, 'ajuda_transacoes', ['transacao' => $transacao]);
        $pt = (string)($atual['texto_pt'] ?? '');
        $assinatura = trim(html_entity_decode(strip_tags((string)preg_replace('/^.*?<strong>(.*?)<\/strong>.*$/s', '$1', $d['texto']['pt'])), ENT_QUOTES, 'UTF-8'));
        if ($atual !== null && !str_contains($pt, '<!-- ml349 -->') && $assinatura !== '' && str_contains(html_entity_decode(strip_tags($pt), ENT_QUOTES, 'UTF-8'), $assinatura)) { $out[$transacao] = 'já tem o texto (editado na tela de ajuda): não alterada'; continue; }
        $dados = [];
        foreach (['pt', 'en', 'es'] as $lg) {
            $velho = trim((string)preg_replace('/\s*<!-- ml349 -->.*?<!-- \/ml349 -->/s', '', (string)($atual['texto_' . $lg] ?? '')));
            $dados['texto_' . $lg] = ($velho === '' ? '' : $velho . "\n") . "<!-- ml349 -->\n" . $d['texto'][$lg] . "\n<!-- /ml349 -->";
            if ($atual === null || trim((string)($atual['titulo_' . $lg] ?? '')) === '') $dados['titulo_' . $lg] = $d['titulo'][$lg];
        }
        if ($atual !== null) { $igual = true; foreach ($dados as $k => $v) if ((string)($atual[$k] ?? '') !== $v) $igual = false; if ($igual) { $out[$transacao] = 'igual'; continue; } }
        if ($simular) { $out[$transacao] = $atual === null ? 'será incluída' : 'será atualizada'; continue; }
        $r = MigracaoCrud::salvar($u, 'ajuda_transacoes', ['transacao' => $transacao], $dados + ['ativo' => 1]);
        $out[$transacao] = $r === 'alterado' ? 'atualizada' : 'incluída';
    }
    return $out;
}
