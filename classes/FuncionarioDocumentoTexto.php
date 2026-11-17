<?php
declare(strict_types=1);

/*
 * Texto de documento de funcionário (ASO, certificados) antes do envio à IA: dados de saúde não saem do servidor.
 * Remove linhas com diagnóstico, CID, exames, sintomas e afins e mascara códigos no formato da CID-10 (ex.: M54.5).
 * "Altura" não entra na lista: é o nome do treinamento NR-35.
 * Mesma regra de principal/api/funcionarios_ia.php (análise na Gestão de Funcionários).
 */
final class FuncionarioDocumentoTexto
{
    private const SAUDE = '/\b(c\.?\s?i\.?\s?d|diagn[oó]s|patolog|doen[cç]a|enfermidade|sintoma|queixa|anamnese|hist[oó]ri(co|a)\s+(cl[ií]nic|m[eé]dic)|medica(mento|[cç][aã]o)|exames?\s+(complementar|cl[ií]nic|laborat)|audiometr|espirometr|hemograma|glicemia|raio[\s-]?x|eletrocardio|acuidade|press[aã]o\s+arterial|\bimc\b|gestante|gravidez|defici[eê]ncia|afastamento\s+m[eé]dic)/iu';

    /** @return array{texto: string, omitidas: int} */
    public static function limpar(string $texto): array
    {
        $omitidas = 0;
        $linhas = [];
        foreach (preg_split('/\R/u', $texto) ?: [] as $linha) {
            if (preg_match(self::SAUDE, $linha)) { $omitidas++; continue; }
            $linhas[] = preg_replace('/\b[A-TV-Z]\d{2}(\.\d{1,2})?\b/u', '[omitido]', $linha) ?? $linha;
        }
        return ['texto' => trim(implode("\n", $linhas)), 'omitidas' => $omitidas];
    }

    /** Campos que podem voltar da IA (nada de saúde além do resultado de aptidão do ASO). */
    public const CAMPOS = ['tipo_documento', 'nome', 'cpf', 'rg', 'funcao', 'empresa', 'cnpj', 'aso_tipo_exame', 'aso_resultado', 'aso_data_exame', 'aso_validade', 'aso_medico', 'aso_crm',
        'treinamento_nome', 'treinamento_norma', 'treinamento_carga_horaria', 'treinamento_data_realizacao', 'treinamento_validade', 'treinamento_instituicao', 'treinamento_instrutor', 'confianca', 'alertas'];
}
