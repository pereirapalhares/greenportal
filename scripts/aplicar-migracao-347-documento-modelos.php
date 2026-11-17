<?php
declare(strict_types=1);

/*
 * Migração 347 — Validação dos documentos da homologação pelos modelos cadastrados (Onda 2 da apresentação Atlas Schindler 2026:
 * "Validação documental — conferência conforme modelos e templates previamente cadastrados").
 *
 * Universal: a Ajuda (Modelo do documento e os dois cadastros de critérios).
 * Cada empresa da homologação (classes/HomologacaoBancos.php; precisa das migrações 261, 262 e 284):
 *   1. database/347_documento_modelos.sql — modelos (fo_documento_modelos), validações (fo_documento_modelo_validacoes),
 *      procedures sp_doc_modelo_* e views vw_doc_modelo*;
 *   2. prompt DOC_MODELO_SUGESTAO em ia_prompts (sugestão dos itens a partir do arquivo de exemplo; editável em Setup › Prompts
 *      de IA; não sobrescreve se já existir);
 *   3. registros_migracao (DOCUMENTO_MODELOS_347).
 * Nada é validado até alguém cadastrar e ativar um modelo (botão "Modelo do documento" nos Critérios). A validação automática
 * roda no serviço "ia" do Agendador (GreenPortal-IaWorker, scripts/windows-service.php --service=ia), passa pela fila de OCR e pela
 * fila de IA e respeita o limite mensal de IA. A IA só sugere: a decisão do documento continua sendo de uma pessoa.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-347-documento-modelos.php [--simular] [--banco=gatlas_dbp]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$env = GreenPortalDatabase::env();
$rel = ['ok' => true, 'migracao' => '347', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'universal' => [], 'bancos' => []];

// ---------------------------------------------------------------- universal: ajuda
try {
    $u = (new MenuCatalogoService($env))->universal();
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $rel['universal']['ajuda'] = gravarAjuda347($u, require dirname(__DIR__) . '/database/347_ajuda_documento_modelos.php', $simular);
} catch (Throwable $e) { $rel['ok'] = false; $rel['universal']['erro'] = $e->getMessage(); }

// ---------------------------------------------------------------- prompt da sugestão de itens
$formato = '{"itens":[{"texto":"...","obrigatorio":true}],"orientacao":null,"exige_validade":false,"exige_assinatura":false,"emissao_max_dias":null}';
$prompt = [
    'pt' => 'Você ajuda a montar o modelo de conferência de um documento exigido na homologação de fornecedores (documentos da empresa fornecedora ou dos seus funcionários: certidões, alvarás, licenças, ASO, certificados de treinamento NR, PGR/PCMSO, ART, apólices etc.). '
        . 'O texto começa com o DOCUMENTO EXIGIDO e o ESCOPO; depois vem o texto lido de um arquivo de exemplo, considerado bom. '
        . 'Liste de 3 a 12 itens que todo documento desse tipo precisa ter e que dá para conferir lendo o texto (ex.: órgão emissor, razão social e CNPJ do titular, nome e CPF do funcionário, número do documento, data de emissão, validade, resultado ou conclusão, carga horária, norma, assinatura e registro do profissional responsável). '
        . 'Escreva cada item de forma genérica e curta: não copie dados do exemplo (nomes, CPF, CNPJ, números, datas) e não inclua dados de saúde. obrigatorio=true quando a falta do item torna o documento inválido; false quando é só recomendado. '
        . 'orientacao: uma frase opcional para quem confere (ou null). exige_validade e exige_assinatura: true se esse tipo de documento sempre tem validade escrita / assinatura. emissao_max_dias: prazo usual de aceite pela data de emissão (ex.: 30 ou 90 para certidões) ou null. '
        . 'Retorne exclusivamente JSON válido no formato ' . $formato,
    'en' => 'You help build the checking template of a document required in supplier approval (documents of the supplier company or its employees: clearance certificates, permits, licenses, occupational health certificates, NR training certificates, PGR/PCMSO, ART, insurance policies etc.). '
        . 'The text starts with the REQUIRED DOCUMENT and the SCOPE; then comes the text read from a sample file considered good. '
        . 'List 3 to 12 items every document of this type must have and that can be checked by reading the text (e.g.: issuing body, holder company name and CNPJ, employee name and CPF, document number, issue date, expiration, result or conclusion, workload, standard, signature and registration of the responsible professional). '
        . 'Write each item generically and briefly: do not copy data from the sample (names, CPF, CNPJ, numbers, dates) and do not include health data. obrigatorio=true when the missing item makes the document invalid; false when only recommended. '
        . 'orientacao: one optional sentence for the reviewer (or null). exige_validade and exige_assinatura: true if this kind of document always has a written expiration / signature. emissao_max_dias: usual acceptance period by issue date (e.g.: 30 or 90 for certificates) or null. '
        . 'Return only valid JSON in the format ' . $formato,
    'es' => 'Usted ayuda a armar el modelo de revisión de un documento exigido en la homologación de proveedores (documentos de la empresa proveedora o de sus empleados: certificados, permisos, licencias, ASO, certificados de capacitación NR, PGR/PCMSO, ART, pólizas etc.). '
        . 'El texto comienza con el DOCUMENTO EXIGIDO y el ALCANCE; luego viene el texto leído de un archivo de ejemplo considerado bueno. '
        . 'Liste de 3 a 12 ítems que todo documento de ese tipo debe tener y que se pueden revisar leyendo el texto (ej.: órgano emisor, razón social y CNPJ del titular, nombre y CPF del empleado, número del documento, fecha de emisión, vencimiento, resultado o conclusión, carga horaria, norma, firma y registro del profesional responsable). '
        . 'Escriba cada ítem de forma genérica y corta: no copie datos del ejemplo (nombres, CPF, CNPJ, números, fechas) y no incluya datos de salud. obrigatorio=true cuando la falta del ítem invalida el documento; false cuando es solo recomendado. '
        . 'orientacao: una frase opcional para quien revisa (o null). exige_validade y exige_assinatura: true si ese tipo de documento siempre tiene vencimiento escrito / firma. emissao_max_dias: plazo usual de aceptación por la fecha de emisión (ej.: 30 o 90 para certificados) o null. '
        . 'Devuelva exclusivamente JSON válido en el formato ' . $formato,
];

// ---------------------------------------------------------------- empresas da homologação
$sql = (string)file_get_contents(dirname(__DIR__) . '/database/347_documento_modelos.sql');
$sql = (string)preg_replace('/^--(?! @@).*$/m', '', $sql);
$blocos = array_values(array_filter(array_map('trim', preg_split('/^-- @@\s*$/m', $sql)), static fn($b) => $b !== ''));
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
        foreach (['fo_criteriofornecedores', 'fo_criteriofuncionarios', 'fo_fornecedorxcriterios', 'fo_funcionarioxtiposxcriterios', 'green_ocr_jobs', 'green_ia_document_jobs', 'ia_prompts', 'alertas'] as $t) if (!$tem('T', $t)) $faltam[] = $t;
        foreach (['sp_docfornecedor_ia_registro', 'sp_docfornecedor_ia_gravar', 'sp_docfuncionario_ia_registro', 'sp_docfuncionario_ia_gravar', 'sp_ged_analise_ia_iniciar', 'sp_ia_prompt_obter', 'sp_alerta_criar', 'sp_crud_select'] as $r) if (!$tem('R', $r)) $faltam[] = $r . '()';
        if ($faltam) throw new RuntimeException('faltam objetos das migrações anteriores (261, 262, 222, 284): ' . implode(', ', $faltam));
        $info['criterios'] = ['fornecedor' => (int)$um('SELECT COUNT(*) FROM fo_criteriofornecedores'), 'funcionario' => (int)$um('SELECT COUNT(*) FROM fo_criteriofuncionarios')];
        $info['pendentes_com_a_empresa'] = ['fornecedor' => (int)$um("SELECT COUNT(*) FROM fo_fornecedorxcriterios WHERE TRIM(COALESCE(situacao, '')) = 'Pendente com a empresa'"),
            'funcionario' => (int)$um("SELECT COUNT(*) FROM fo_funcionarioxtiposxcriterios WHERE TRIM(COALESCE(situacao, '')) = 'Pendente com a empresa'")];
        $existePrompt = (int)$um("SELECT COUNT(*) FROM ia_prompts WHERE codigo = 'DOC_MODELO_SUGESTAO'") > 0;
        $jaTinha = $tem('T', 'fo_documento_modelos');
        if ($simular) {
            $info['estrutura'] = $jaTinha ? 'será regravada (modelos existentes mantidos)' : 'será criada';
            $info['prompt'] = $existePrompt ? 'já existia (mantido)' : 'será cadastrado';
        } else {
            foreach ($blocos as $b) $pdo->exec($b);
            $info['estrutura'] = count($blocos) . ' blocos gravados';
            if (!$existePrompt) {
                $pdo->prepare('INSERT INTO ia_prompts (codigo, nome_pt, nome_en, nome_es, prompt_pt, prompt_en, prompt_es) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute(['DOC_MODELO_SUGESTAO', 'Modelo de documento — sugestão dos itens (Homologação)', 'Document template — item suggestion (Approval)', 'Modelo de documento — sugerencia de ítems (Homologación)', $prompt['pt'], $prompt['en'], $prompt['es']]);
                $info['prompt'] = 'cadastrado';
            } else $info['prompt'] = 'já existia (mantido, pode ter sido editado)';
            $info['modelos'] = (int)$um('SELECT COUNT(*) FROM vw_doc_modelos');
            if ($tem('T', 'registros_migracao'))
                $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
                    VALUES ('DOCUMENTO_MODELOS_347', 'Critérios › Modelo do documento', 'fo_documento_modelos;fo_documento_modelo_validacoes;sp_doc_modelo_*', 'ESTRUTURA', '4.0.1', 'CONCLUIDA', 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
                    ON DUPLICATE KEY UPDATE status = VALUES(status), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")->execute([json_encode($info, JSON_UNESCAPED_UNICODE)]);
        }
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}
$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$logs = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logs)) @mkdir($logs, 0775, true);
@file_put_contents($logs . DIRECTORY_SEPARATOR . 'migracao_347_documento_modelos' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);

function gravarAjuda347(PDO $u, array $textos, bool $simular): array
{
    if (!MigracaoCrud::existe($u, 'ajuda_transacoes')) return ['aviso' => 'sem ajuda_transacoes no universal'];
    $out = [];
    foreach ($textos as $transacao => $d) {
        $atual = MigracaoCrud::primeiro($u, 'ajuda_transacoes', ['transacao' => $transacao]);
        $pt = (string)($atual['texto_pt'] ?? '');
        $assinatura = trim(html_entity_decode(strip_tags((string)preg_replace('/^.*?<strong>(.*?)<\/strong>.*$/s', '$1', $d['texto']['pt'])), ENT_QUOTES, 'UTF-8'));
        if ($atual !== null && !str_contains($pt, '<!-- ml347 -->') && $assinatura !== '' && str_contains(html_entity_decode(strip_tags($pt), ENT_QUOTES, 'UTF-8'), $assinatura)) { $out[$transacao] = 'já tem o texto (editado na tela de ajuda): não alterada'; continue; }
        $dados = [];
        foreach (['pt', 'en', 'es'] as $lg) {
            $velho = trim((string)preg_replace('/\s*<!-- ml347 -->.*?<!-- \/ml347 -->/s', '', (string)($atual['texto_' . $lg] ?? '')));
            $dados['texto_' . $lg] = ($velho === '' ? '' : $velho . "\n") . "<!-- ml347 -->\n" . $d['texto'][$lg] . "\n<!-- /ml347 -->";
            if ($atual === null || trim((string)($atual['titulo_' . $lg] ?? '')) === '') $dados['titulo_' . $lg] = $d['titulo'][$lg];
        }
        if ($atual !== null) { $igual = true; foreach ($dados as $k => $v) if ((string)($atual[$k] ?? '') !== $v) $igual = false; if ($igual) { $out[$transacao] = 'igual'; continue; } }
        if ($simular) { $out[$transacao] = $atual === null ? 'será incluída' : 'será atualizada'; continue; }
        $r = MigracaoCrud::salvar($u, 'ajuda_transacoes', ['transacao' => $transacao], $dados + ['ativo' => 1]);
        $out[$transacao] = $r === 'alterado' ? 'atualizada' : 'incluída';
    }
    return $out;
}
