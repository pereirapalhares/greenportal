<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';
require_once __DIR__ . '/IdiomaService.php';
require_once __DIR__ . '/GedAnaliseIaRegistro.php';
require_once __DIR__ . '/HomologacaoDocumentoModelo.php';

/**
 * Conferência da leitura da IA dos documentos da homologação e gravação da análise pendente de revisão — a mesma regra para a
 * análise pedida na Gestão de Arquivos (principal/api/fornecedorcriterios_ia.php, funcionariocriterios_ia.php) e para a validação
 * automática pelo modelo do documento (classes/HomologacaoDocumentoModelo.php, migração 347). Movido das duas APIs sem mudar a regra;
 * com modelo, a leitura ganha o parecer item a item em "modelo" e as pendências entram nos alertas.
 */
final class HomologacaoDocumentoIa
{
    public const CAMPOS_FORNECEDOR = ['tipo_documento', 'corresponde_ao_exigido', 'numero_documento', 'orgao_emissor', 'razao_social', 'cnpj', 'data_emissao', 'data_validade', 'resumo', 'confianca', 'alertas'];

    /**
     * Documento do fornecedor (fo_fornecedorxcriterios). $s: JSON da IA; $registro: sp_docfornecedor_ia_registro (CNPJ sem dígitos já nulo).
     * Devolve ['codanalise' => int, 's' => leitura conferida, 'confianca' => float].
     */
    public static function fornecedor(PDO $pdo, array $registro, int $codigo, string $nomeArquivo, array $s, array $extracao, array $alertas, int $usuario, int $codGed, ?array $modelo, GedAnaliseIaRegistro $ged): array
    {
        $t = static fn(string $chave, array $padrao): string => IdiomaService::t('api.fornecedorcriterios_ia.' . $chave, $padrao);
        $bruto = $s;
        $s = array_intersect_key($s, array_flip(self::CAMPOS_FORNECEDOR));
        $s['corresponde_ao_exigido'] = in_array(strtoupper((string)($s['corresponde_ao_exigido'] ?? '')), ['SIM', 'NAO'], true) ? strtoupper((string)$s['corresponde_ao_exigido']) : 'INCERTO';
        foreach (['data_emissao', 'data_validade'] as $c) $s[$c] = HomologacaoDocumentoModelo::data($s[$c] ?? '');
        $confianca = (float)($s['confianca'] ?? 0); if ($confianca > 0 && $confianca <= 1) $confianca *= 100;
        $alertas = array_values(array_filter(array_merge($alertas, array_map('strval', (array)($s['alertas'] ?? [])))));
        // Conferências com o cadastro
        if ($s['corresponde_ao_exigido'] === 'NAO') $alertas[] = IdiomaService::f('api.fornecedorcriterios_ia.alerta_documento', ['pt' => 'O arquivo não parece ser o documento exigido ({doc}).', 'en' => 'The file does not seem to be the required document ({doc}).', 'es' => 'El archivo no parece ser el documento exigido ({doc}).'], ['doc' => (string)$registro['DSCRITERIO']]);
        $nomeDoc = HomologacaoDocumentoModelo::nome($s['razao_social'] ?? '', true); $nomeCad = HomologacaoDocumentoModelo::nome($registro['RAZAOSOCIAL'] ?? '', true);
        if ($nomeDoc !== '' && $nomeCad !== '') { similar_text($nomeDoc, $nomeCad, $pct); if ($pct < 75) $alertas[] = IdiomaService::f('api.fornecedorcriterios_ia.alerta_razao', ['pt' => 'A razão social no documento ({doc}) é diferente do cadastro ({cad}).', 'en' => 'The company name on the document ({doc}) differs from the record ({cad}).', 'es' => 'La razón social en el documento ({doc}) difiere del registro ({cad}).'], ['doc' => (string)$s['razao_social'], 'cad' => (string)$registro['RAZAOSOCIAL']]); }
        $cnpjDoc = preg_replace('/\D/', '', (string)($s['cnpj'] ?? '')) ?? ''; $cnpjCad = preg_replace('/\D/', '', (string)($registro['CNPJ'] ?? '')) ?? '';
        if (strlen($cnpjDoc) === 14 && strlen($cnpjCad) === 14 && substr($cnpjDoc, 0, 8) !== substr($cnpjCad, 0, 8)) $alertas[] = $t('alerta_cnpj', ['pt' => 'O CNPJ do documento é de outra empresa (raiz diferente do cadastro).', 'en' => 'The document CNPJ belongs to another company (different root from the record).', 'es' => 'El CNPJ del documento es de otra empresa (raíz diferente del registro).']);
        if ($s['data_validade'] !== null && $s['data_validade'] < date('Y-m-d')) $alertas[] = $t('alerta_vencido', ['pt' => 'O documento está vencido.', 'en' => 'The document is expired.', 'es' => 'El documento está vencido.']);
        if (str_starts_with((string)($extracao['fonte'] ?? ''), 'OCR')) $alertas[] = $t('alerta_ocr', ['pt' => 'Documento lido por OCR (imagem ou digitalização): confira os dados com atenção.', 'en' => 'Document read by OCR (image or scan): check the data carefully.', 'es' => 'Documento leído por OCR (imagen o escaneo): revise los datos con atención.']);
        // Migração 347: parecer pelo modelo do documento (itens e regras)
        if ($modelo) {
            $s['modelo'] = HomologacaoDocumentoModelo::conferir($modelo, ['modelo_itens' => $bruto['modelo_itens'] ?? [], 'assinatura' => $bruto['assinatura'] ?? null] + $s, $registro, 'FORNECEDOR');
            foreach ($s['modelo']['pendencias'] as $p) $alertas[] = IdiomaService::f('doc_modelo.alerta_pendencia', ['pt' => 'Modelo: {p}', 'en' => 'Template: {p}', 'es' => 'Modelo: {p}'], ['p' => $p]);
        }
        $s['alertas'] = array_values(array_unique($alertas));
        $s['confianca'] = $confianca;
        $codanalise = (int)(GreenPortalDatabase::call($pdo, 'CALL sp_docfornecedor_ia_gravar(?, ?, ?, ?, ?, ?, ?, ?)', [$codigo, $nomeArquivo, (string)($s['tipo_documento'] ?? ''), (string)($extracao['fonte'] ?? ''), (float)($extracao['confianca'] ?? 0), json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $confianca, $usuario])[0]['codanalise'] ?? 0);
        $ged->concluir($codGed, $s, $confianca, (string)($extracao['fonte'] ?? ''), $codanalise);
        return ['codanalise' => $codanalise, 's' => $s, 'confianca' => $confianca];
    }

    /**
     * Critério do funcionário (fo_funcionarioxtiposxcriterios). $s: JSON da IA (texto já sem dados de saúde); $registro: sp_docfuncionario_ia_registro.
     * Devolve ['codanalise' => int, 's' => leitura conferida, 'confianca' => float].
     */
    public static function funcionario(PDO $pdo, array $registro, int $codigo, string $nomeArquivo, array $s, array $extracao, array $alertas, int $omitidas, int $usuario, int $codGed, ?array $modelo, GedAnaliseIaRegistro $ged): array
    {
        require_once __DIR__ . '/FuncionarioDocumentoTexto.php';
        $t = static fn(string $chave, array $padrao): string => IdiomaService::t('api.funcionariocriterios_ia.' . $chave, $padrao);
        $bruto = $s;
        // Só os campos previstos seguem adiante (nada de saúde além da aptidão do ASO).
        $s = array_intersect_key($s, array_flip(FuncionarioDocumentoTexto::CAMPOS));
        $tipo = strtoupper((string)($s['tipo_documento'] ?? 'OUTRO'));
        $s['tipo_documento'] = in_array($tipo, ['ASO', 'TREINAMENTO'], true) ? $tipo : 'OUTRO';
        foreach (['aso_data_exame', 'aso_validade', 'treinamento_data_realizacao', 'treinamento_validade'] as $c) if (array_key_exists($c, $s)) $s[$c] = HomologacaoDocumentoModelo::data($s[$c]);
        $confianca = (float)($s['confianca'] ?? 0); if ($confianca > 0 && $confianca <= 1) $confianca *= 100;
        $alertas = array_values(array_filter(array_merge($alertas, array_map('strval', (array)($s['alertas'] ?? [])))));
        // Conferências com o cadastro e com o critério exigido
        $nomeDoc = HomologacaoDocumentoModelo::nome($s['nome'] ?? ''); $nomeCad = HomologacaoDocumentoModelo::nome($registro['FUNCIONARIO'] ?? '');
        if ($nomeDoc !== '' && $nomeCad !== '') { similar_text($nomeDoc, $nomeCad, $pct); if ($pct < 80) $alertas[] = IdiomaService::f('api.funcionariocriterios_ia.alerta_nome', ['pt' => 'O nome no documento ({doc}) é diferente do cadastro ({cad}).', 'en' => 'The name on the document ({doc}) differs from the record ({cad}).', 'es' => 'El nombre en el documento ({doc}) difiere del registro ({cad}).'], ['doc' => (string)$s['nome'], 'cad' => (string)$registro['FUNCIONARIO']]); }
        foreach (['aso_medico', 'treinamento_instrutor'] as $campoPessoa) { $v = HomologacaoDocumentoModelo::nome($s[$campoPessoa] ?? ''); if ($v !== '' && $nomeCad !== '') { similar_text($v, $nomeCad, $pp); if ($pp >= 80) $s[$campoPessoa] = null; } }
        $cpfDoc = preg_replace('/\D/', '', (string)($s['cpf'] ?? '')) ?? ''; $cpfCad = preg_replace('/\D/', '', (string)($registro['NRIDENTIFICACAO'] ?? '')) ?? '';
        if (strlen($cpfDoc) === 11 && strlen($cpfCad) === 11 && $cpfDoc !== $cpfCad) $alertas[] = $t('alerta_cpf', ['pt' => 'O CPF do documento é diferente do Nº de Registro do cadastro.', 'en' => 'The document CPF differs from the record registration number.', 'es' => 'El CPF del documento difiere del N.º de registro.']);
        $criterio = (string)$registro['DSCRITERIO'];
        $criterioAso = (bool)preg_match('/\bASO\b|sa[uú]de\s+ocupacional|atestado\s+de\s+sa/iu', $criterio);
        $nrCriterio = preg_match('/NR\s*-?\s*0?(\d{1,2})\b/i', $criterio, $m1) ? (int)$m1[1] : 0;
        $nrDoc = preg_match('/NR\s*-?\s*0?(\d{1,2})\b/i', (string)($s['treinamento_norma'] ?? '') . ' ' . (string)($s['treinamento_nome'] ?? ''), $m2) ? (int)$m2[1] : 0;
        if (($criterioAso && $s['tipo_documento'] !== 'ASO') || (!$criterioAso && $nrCriterio > 0 && $s['tipo_documento'] === 'ASO') || ($nrCriterio > 0 && $nrDoc > 0 && $nrCriterio !== $nrDoc))
            $alertas[] = IdiomaService::f('api.funcionariocriterios_ia.alerta_criterio', ['pt' => 'O documento não parece ser o exigido pelo critério ({crit}).', 'en' => 'The document does not seem to be the one required by the criterion ({crit}).', 'es' => 'El documento no parece ser el exigido por el criterio ({crit}).'], ['crit' => $criterio]);
        $validade = self::validadeFuncionario($s);
        if ($validade !== null && $validade < date('Y-m-d')) $alertas[] = $t('alerta_vencido', ['pt' => 'O documento está vencido.', 'en' => 'The document is expired.', 'es' => 'El documento está vencido.']);
        if ($omitidas > 0) $alertas[] = IdiomaService::f('api.funcionariocriterios_ia.alerta_omitidas', ['pt' => '{n} linha(s) com dados de saúde foram omitidas antes do envio à IA.', 'en' => '{n} line(s) with health data were omitted before sending to the AI.', 'es' => '{n} línea(s) con datos de salud se omitieron antes del envío a la IA.'], ['n' => $omitidas]);
        if (str_starts_with((string)($extracao['fonte'] ?? ''), 'OCR')) $alertas[] = $t('alerta_ocr', ['pt' => 'Documento lido por OCR (imagem ou digitalização): confira os dados com atenção.', 'en' => 'Document read by OCR (image or scan): check the data carefully.', 'es' => 'Documento leído por OCR (imagen o escaneo): revise los datos con atención.']);
        // Migração 347: parecer pelo modelo do documento (itens e regras); datas extras pedidas pelo modelo
        if ($modelo) {
            $extra = ['modelo_itens' => $bruto['modelo_itens'] ?? [], 'assinatura' => $bruto['assinatura'] ?? null, 'data_emissao' => HomologacaoDocumentoModelo::data($bruto['data_emissao'] ?? null), 'data_validade' => HomologacaoDocumentoModelo::data($bruto['data_validade'] ?? null)];
            $s['modelo'] = HomologacaoDocumentoModelo::conferir($modelo, $extra + $s, $registro, 'FUNCIONARIO');
            foreach ($s['modelo']['pendencias'] as $p) $alertas[] = IdiomaService::f('doc_modelo.alerta_pendencia', ['pt' => 'Modelo: {p}', 'en' => 'Template: {p}', 'es' => 'Modelo: {p}'], ['p' => $p]);
        }
        $s['alertas'] = array_values(array_unique($alertas));
        $s['confianca'] = $confianca;
        $codanalise = (int)(GreenPortalDatabase::call($pdo, 'CALL sp_docfuncionario_ia_gravar(?, ?, ?, ?, ?, ?, ?, ?, ?)', [$codigo, $nomeArquivo, $s['tipo_documento'], (string)($extracao['fonte'] ?? ''), (float)($extracao['confianca'] ?? 0), $omitidas, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $confianca, $usuario])[0]['codanalise'] ?? 0);
        $ged->concluir($codGed, $s, $confianca, (string)($extracao['fonte'] ?? ''), $codanalise);
        return ['codanalise' => $codanalise, 's' => $s, 'confianca' => $confianca];
    }

    /** Validade lida do documento do funcionário (a mesma da sugestão da API: ASO ou treinamento). */
    public static function validadeFuncionario(array $s): ?string
    {
        $tipo = (string)($s['tipo_documento'] ?? 'OUTRO');
        return HomologacaoDocumentoModelo::data($tipo === 'ASO' ? ($s['aso_validade'] ?? '') : ($tipo === 'TREINAMENTO' ? ($s['treinamento_validade'] ?? '') : ''));
    }
}
