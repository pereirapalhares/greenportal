<?php
declare(strict_types=1);

/*
 * Situação da licença (apresentação Atlas Schindler 2026 — licenças em protocolo / "espelho"):
 *   VALIDA     Válida                  vencimento hoje ou depois
 *   PROTOCOLO  Válida (em protocolo)   vencida, mas a renovação foi protocolada no órgão DENTRO do prazo: continua valendo
 *                                      até o órgão responder (mantém número e data do protocolo)
 *   PROT_SEM_PRAZO Em protocolo (prazo não informado)  vencida, marcada "Em protocolo?" = Sim, mas sem as datas para saber
 *                                      se foi no prazo (falta Data do protocolo ou Protocolado em) e sem "Protocolado no prazo?"
 *                                      (decisão do usuário de 27/10/2026): não conta como vencida nem como válida; fica em Atenção
 *   VENCIDA    Vencida                 vencida sem protocolo, ou protocolada fora do prazo
 *   SEM_DATA   Sem data de vencimento
 *   ENTREGA    Entrega periódica       (RAPP, inventário, relatório anual...: não é licença, tem prazo de entrega)
 * "No prazo" segue a mesma regra da situação legal calculada (migração 284): Protocolado em <= Data do protocolo (prazo
 * para protocolar); sem uma das datas, vale o campo "Protocolado no prazo?".
 * Códigos sem um conter o outro: os filtros do grid comparam por "contém".
 * Migração 415 (26/11/2026, Revisão G itens 48, 55 e 56), colunas opcionais de li_licencas:
 *   ARQUIVADA            'S' = licença arquivada: situação ARQUIVADA, fora dos indicadores, da Home e dos avisos de prazo
 *   VALIDADE_INEXISTENTE 'S' = a licença não tem validade (não vence): situação NAO_VENCE — diferente de SEM_DATA, que
 *                        passa a significar "data de vencimento não preenchida"
 *   VALIDADE_RECORRENTE  'S' + RECORRENCIA_MESES = validade que se renova sozinha a cada N meses: depois do vencimento
 *                        gravado vale o próximo ciclo (DATA_VALIDADE + k × N meses), sem alterar o registro
 * Usada pelas migrações 285 (vw_dash_licencas) e 288 (vw_licencas_situacao / grid de licenças) e pelo relatório.
 */
final class LicencaSituacao
{
    public const CODIGOS = ['VALIDA', 'PROTOCOLO', 'PROT_SEM_PRAZO', 'VENCIDA', 'SEM_DATA', 'NAO_VENCE', 'ENTREGA', 'ARQUIVADA'];

    public const ROTULOS = [
        'VALIDA' => ['pt' => 'Válida', 'en' => 'Valid', 'es' => 'Válida'],
        'PROTOCOLO' => ['pt' => 'Válida (em protocolo)', 'en' => 'Valid (renewal filed)', 'es' => 'Válida (en protocolo)'],
        'PROT_SEM_PRAZO' => ['pt' => 'Em protocolo (prazo não informado)', 'en' => 'Renewal filed (deadline not informed)', 'es' => 'En protocolo (plazo no informado)'],
        'VENCIDA' => ['pt' => 'Vencida', 'en' => 'Expired', 'es' => 'Vencida'],
        'SEM_DATA' => ['pt' => 'Sem data de vencimento', 'en' => 'No due date', 'es' => 'Sin fecha de vencimiento'],
        'NAO_VENCE' => ['pt' => 'Sem validade (não vence)', 'en' => 'No expiry (does not expire)', 'es' => 'Sin validez (no vence)'],
        'ENTREGA' => ['pt' => 'Entrega periódica', 'en' => 'Periodic submission', 'es' => 'Entrega periódica'],
        'ARQUIVADA' => ['pt' => 'Arquivada', 'en' => 'Archived', 'es' => 'Archivada'],
    ];

    public static function rotulo(string $codigo, string $idioma = 'pt'): string
    {
        return self::ROTULOS[$codigo][$idioma] ?? self::ROTULOS[$codigo]['pt'] ?? $codigo;
    }

    private static function d(string $e): string { return "(CASE WHEN YEAR($e) > 0 THEN DATE($e) END)"; }
    private static function sim(string $e): string { return "(UPPER(TRIM(COALESCE($e, ''))) IN ('S', 'SIM', '1', 'Y', 'YES', 'X'))"; }
    private static function f(string $e): string { return "DATE_FORMAT($e, '%d/%m/%Y')"; }

    /**
     * Expressões SQL sobre li_licencas (alias l). $c(coluna, padrão) devolve "l.`coluna`" ou o padrão quando a coluna não
     * existe; $periodico é a expressão (booleana) de entrega periódica do chamador.
     * Devolve: situacao, rotulo, motivo, no_prazo ('S'/'N'/NULL), em_protocolo (booleana), numero.
     */
    public static function sql(callable $c, string $periodico): array
    {
        $Vgravada = self::d('l.`DATA_VALIDADE`');
        $arquivada = self::sim($c('ARQUIVADA'));
        $naoVence = self::sim($c('VALIDADE_INEXISTENTE'));
        $n = "COALESCE(" . $c('RECORRENCIA_MESES') . ", 0)";
        $recorrente = "(" . self::sim($c('VALIDADE_RECORRENTE')) . " AND $n > 0)";
        // Próximo ciclo da validade recorrente: menor DATA_VALIDADE + k × N meses que seja hoje ou depois
        $k = "CEIL(TIMESTAMPDIFF(MONTH, $Vgravada, CURDATE()) / $n)";
        $V = "(CASE WHEN $recorrente AND $Vgravada < CURDATE() THEN DATE_ADD($Vgravada, INTERVAL ($n * ($k + (DATE_ADD($Vgravada, INTERVAL ($n * $k) MONTH) < CURDATE()))) MONTH) ELSE $Vgravada END)";
        $P = self::d($c('DATA_PROTOCOLO'));
        $PE = self::d($c('PROTOCOLADO_EM'));
        $emProt = self::sim($c('EMPROTOCOLO'));
        $flag = self::sim($c('PROTOCOLADO_NO_PRAZO'));
        $noPrazo = "(CASE WHEN $PE IS NOT NULL AND $P IS NOT NULL THEN $PE <= $P ELSE $flag END)";
        // Sem as duas datas e sem "Protocolado no prazo?" preenchido: não dá para saber se foi no prazo
        $semInfo = "(($PE IS NULL OR $P IS NULL) AND TRIM(COALESCE(" . $c('PROTOCOLADO_NO_PRAZO') . ", '')) = '')";
        $numero = "NULLIF(TRIM(" . $c('NUMERO_PROTOCOLO') . "), '')";
        $situacao = "(CASE WHEN $arquivada THEN 'ARQUIVADA'
                 WHEN $periodico THEN 'ENTREGA'
                 WHEN $naoVence THEN 'NAO_VENCE'
                 WHEN $V IS NULL THEN 'SEM_DATA'
                 WHEN $V >= CURDATE() THEN 'VALIDA'
                 WHEN $emProt AND $noPrazo THEN 'PROTOCOLO'
                 WHEN $emProt AND $semInfo THEN 'PROT_SEM_PRAZO'
                 ELSE 'VENCIDA' END)";
        $rotulo = "(CASE $situacao WHEN 'VALIDA' THEN 'Válida' WHEN 'PROTOCOLO' THEN 'Válida (em protocolo)' WHEN 'PROT_SEM_PRAZO' THEN 'Em protocolo (prazo não informado)' WHEN 'VENCIDA' THEN 'Vencida'
                 WHEN 'SEM_DATA' THEN 'Sem data de vencimento' WHEN 'NAO_VENCE' THEN 'Sem validade (não vence)' WHEN 'ARQUIVADA' THEN 'Arquivada' ELSE 'Entrega periódica' END)";
        $prot = "CONCAT(IF($PE IS NULL, '', CONCAT(' em ', " . self::f($PE) . ")), IF($numero IS NULL, '', CONCAT(' (protocolo nº ', $numero, ')')))";
        $ciclo = "IF($recorrente, CONCAT(' (validade recorrente a cada ', $n, IF($n = 1, ' mês', ' meses'), IF($Vgravada < CURDATE(), CONCAT('; vencimento gravado ', " . self::f($Vgravada) . "), ''), ')'), '')";
        $motivo = "(CASE WHEN $arquivada THEN 'Licença arquivada: fora dos indicadores, da Home e dos avisos de prazo'
                 WHEN $periodico THEN IF($V IS NULL, 'Entrega periódica sem prazo', CONCAT('Entrega periódica com prazo em ', " . self::f($V) . "))
                 WHEN $naoVence THEN 'Licença sem validade: não vence (marcada \"Validade inexistente\")'
                 WHEN $V IS NULL THEN 'Data de vencimento não preenchida'
                 WHEN $V >= CURDATE() THEN CONCAT('Válida até ', " . self::f($V) . ", $ciclo, IF($emProt, CONCAT('; renovação já protocolada', $prot), ''))
                 WHEN $emProt AND $noPrazo THEN CONCAT('Vencida em ', " . self::f($V) . ", '; renovação protocolada no prazo', $prot, ': vale até a resposta do órgão')
                 WHEN $emProt AND $semInfo THEN CONCAT('Vencida em ', " . self::f($V) . ", '; marcada em protocolo', $prot, ', mas sem as datas (Data do protocolo e Protocolado em) nem \"Protocolado no prazo?\": não dá para saber se foi no prazo')
                 WHEN $emProt THEN CONCAT('Vencida em ', " . self::f($V) . ", '; renovação protocolada fora do prazo', $prot)
                 ELSE CONCAT('Vencida em ', " . self::f($V) . ", ' sem protocolo de renovação') END)";
        return [
            'situacao' => $situacao, 'rotulo' => $rotulo, 'motivo' => $motivo, 'numero' => $numero, 'em_protocolo' => $emProt,
            'no_prazo' => "(CASE WHEN NOT $emProt THEN NULL WHEN $noPrazo THEN 'S' WHEN $semInfo THEN NULL ELSE 'N' END)", 'sem_info' => $semInfo,
            'validade' => $V, 'protocolado_em' => $PE, 'arquivada' => $arquivada, 'nao_vence' => $naoVence, 'recorrente' => $recorrente,
        ];
    }
}
