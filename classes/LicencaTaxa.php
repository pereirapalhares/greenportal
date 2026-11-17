<?php
declare(strict_types=1);

/*
 * Taxas das licenças (migração 344): listas de tipo, frequência e situação, e as views que dependem das colunas de cada banco.
 *   vw_li_taxas_grid          grid (Manutenção legal › Taxas das licenças e botão Taxas do cadastro de licenças)
 *   vw_li_taxas_licencas      licenças para escolher na inclusão (licença original, com unidade e descrição da versão ativa)
 *   vw_li_taxas_responsaveis  quem responde por cada taxa = responsáveis da licença (vw_minhas_licencas_grid: direto e por atribuição)
 *   vw_minhas_taxas           pendências do usuário: não pagas vencidas ou vencendo em 30 dias (Home › Minhas pendências e Agenda)
 *   vw_li_taxas_avisos        alertas a criar: 30 dias antes e no dia do vencimento (sp_li_taxas_avisar)
 *   vw_dash_li_taxas          dashboard da empresa (aba Licenças): não pagas e pagas no ano
 * A taxa fica na licença original (CODLICENCA_BASE); unidade, descrição e responsável vêm da versão ativa.
 * Valores em texto no formato brasileiro (1.234,56): FORMAT(..., 'de_DE'), porque o pt_BR do MySQL não tem separador de milhar.
 */
final class LicencaTaxa
{
    public const MENU = 'LICENCAS_TAXAS';
    public const TELA = '/principal/manutencaolegal_licencas_taxas.php';

    public const TIPOS = [
        'EMISSAO' => ['pt' => 'Emissão', 'en' => 'Issuance', 'es' => 'Emisión'],
        'RENOVACAO' => ['pt' => 'Renovação', 'en' => 'Renewal', 'es' => 'Renovación'],
        'ANALISE' => ['pt' => 'Análise', 'en' => 'Review', 'es' => 'Análisis'],
        'FISCALIZACAO' => ['pt' => 'Fiscalização (anual)', 'en' => 'Inspection (annual)', 'es' => 'Fiscalización (anual)'],
        'PUBLICACAO' => ['pt' => 'Publicação', 'en' => 'Publication', 'es' => 'Publicación'],
        'OUTRA' => ['pt' => 'Outra', 'en' => 'Other', 'es' => 'Otra'],
    ];

    public const FREQUENCIAS = [
        'UNICA' => ['pt' => 'Única', 'en' => 'One-off', 'es' => 'Única'],
        'MENSAL' => ['pt' => 'Mensal', 'en' => 'Monthly', 'es' => 'Mensual'],
        'TRIMESTRAL' => ['pt' => 'Trimestral', 'en' => 'Quarterly', 'es' => 'Trimestral'],
        'SEMESTRAL' => ['pt' => 'Semestral', 'en' => 'Half-yearly', 'es' => 'Semestral'],
        'ANUAL' => ['pt' => 'Anual', 'en' => 'Yearly', 'es' => 'Anual'],
        'BIENAL' => ['pt' => 'A cada 2 anos', 'en' => 'Every 2 years', 'es' => 'Cada 2 años'],
        'TRIENAL' => ['pt' => 'A cada 3 anos', 'en' => 'Every 3 years', 'es' => 'Cada 3 años'],
        'QUADRIENAL' => ['pt' => 'A cada 4 anos', 'en' => 'Every 4 years', 'es' => 'Cada 4 años'],
        'QUINQUENAL' => ['pt' => 'A cada 5 anos', 'en' => 'Every 5 years', 'es' => 'Cada 5 años'],
    ];

    /** Situação: códigos sem um conter o outro (o filtro do grid compara por "contém"). */
    public const SITUACOES = [
        'VENCIDA' => ['pt' => 'Vencida (não paga)', 'en' => 'Overdue (unpaid)', 'es' => 'Vencida (no pagada)'],
        'VENCE_30' => ['pt' => 'Vence em até 30 dias', 'en' => 'Due within 30 days', 'es' => 'Vence en hasta 30 días'],
        'EM_ABERTO' => ['pt' => 'A pagar', 'en' => 'To pay', 'es' => 'A pagar'],
        'SEM_VENC' => ['pt' => 'Sem vencimento', 'en' => 'No due date', 'es' => 'Sin vencimiento'],
        'PAGA' => ['pt' => 'Paga', 'en' => 'Paid', 'es' => 'Pagada'],
    ];

    public const VIEWS = ['vw_li_taxas_grid', 'vw_li_taxas_licencas', 'vw_li_taxas_responsaveis', 'vw_minhas_taxas', 'vw_li_taxas_avisos', 'vw_dash_li_taxas'];

    /** Valor digitado no formato brasileiro (1.234,56) ou com ponto (1234.56) → texto aceito pelo DECIMAL; vazio = ''. */
    public static function valor(string $texto): string
    {
        $t = preg_replace('/[^0-9,.\-]/', '', trim($texto)) ?? '';
        if ($t === '' || $t === '-') return '';
        if (str_contains($t, ',')) $t = str_replace(',', '.', str_replace('.', '', $t));
        elseif (substr_count($t, '.') > 1) $t = str_replace('.', '', $t);
        if (!is_numeric($t)) throw new InvalidArgumentException('1644 Valor inválido: ' . $texto . ' (use, por exemplo, 1.234,56).');
        return number_format((float)$t, 2, '.', '');
    }

    private static function caso(string $coluna, array $lista): string
    {
        $s = "(CASE $coluna";
        foreach ($lista as $k => $r) $s .= " WHEN '$k' THEN '" . str_replace("'", "''", $r['pt']) . "'";
        return $s . " ELSE $coluna END)";
    }

    /** Colunas (minúsculas) de uma tabela ou view do banco atual. */
    private static function colunas(PDO $pdo, string $tabela): array
    {
        $s = $pdo->prepare('SELECT LOWER(COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $s->execute([$tabela]); $c = $s->fetchAll(PDO::FETCH_COLUMN); $s->closeCursor();
        return array_flip($c);
    }

    /**
     * DDL das views, conforme as colunas do banco. Exige li_taxas, li_licencas (CODLICENCA) e vw_licencas_grid.
     * @return array<string,string> nome => CREATE OR REPLACE VIEW
     */
    public static function views(PDO $pdo): array
    {
        $li = self::colunas($pdo, 'li_licencas');
        $g = self::colunas($pdo, 'vw_licencas_grid');
        if (!isset($li['codlicenca']) || !isset($g['codlicenca'])) throw new RuntimeException('sem li_licencas / vw_licencas_grid (cadastro de licenças)');
        $temMinhas = isset(self::colunas($pdo, 'vw_minhas_licencas_grid')['codusuario']);
        $col = static fn(string $alias, string $c, string $padrao = 'NULL'): string => isset($g[strtolower($c)]) ? "$alias.`$c`" : $padrao;
        $base = isset($li['codlicenca_base']) ? 'COALESCE(v.`CODLICENCA_BASE`, v.`CODLICENCA`)' : 'v.`CODLICENCA`';
        $ativa = isset($li['status_versao']) ? "WHERE COALESCE(v.`STATUS_VERSAO`, 'ATIVA') = 'ATIVA'" : '';
        // Versão ativa de cada licença original
        $k = "(SELECT $base AS `BASE`, MAX(v.`CODLICENCA`) AS `ATUAL` FROM `li_licencas` v $ativa GROUP BY $base)";
        $situacao = "(CASE WHEN t.`PAGO_EM` IS NOT NULL THEN 'PAGA' WHEN t.`VENCIMENTO` IS NULL THEN 'SEM_VENC'
            WHEN t.`VENCIMENTO` < CURDATE() THEN 'VENCIDA' WHEN t.`VENCIMENTO` <= CURDATE() + INTERVAL 30 DAY THEN 'VENCE_30' ELSE 'EM_ABERTO' END)";
        $unidade = $col('g', 'DSCUNIDADE', "''");
        $nomeLic = "COALESCE(NULLIF(TRIM(" . $col('g', 'DSCLICENCA', "''") . "), ''), CONCAT('Licença #', t.`CODLICENCA`))";
        $v = [];
        $v['vw_li_taxas_grid'] = "CREATE OR REPLACE VIEW `vw_li_taxas_grid` AS
SELECT t.`CODTAXA`, t.`CODTAXA` AS `NUMERO_TAXA`, t.`CODLICENCA`, COALESCE(k.`ATUAL`, t.`CODLICENCA`) AS `CODLICENCA_ATUAL`,
       " . $col('g', 'CODUNIDADE') . " AS `CODUNIDADE`, $unidade AS `DSCUNIDADE`, $nomeLic AS `LICENCA`,
       CONCAT_WS(' - ', NULLIF($unidade, ''), $nomeLic) AS `LICENCA_DESC`,
       t.`TIPO`, " . self::caso('t.`TIPO`', self::TIPOS) . " AS `TIPO_DESC`, t.`DESCRICAO`, t.`NUMERO_GUIA`,
       FORMAT(t.`VALOR`, 2, 'de_DE') AS `VALOR`, t.`VALOR` AS `VALOR_NUM`, t.`VENCIMENTO`,
       t.`FREQUENCIA`, " . self::caso('t.`FREQUENCIA`', self::FREQUENCIAS) . " AS `FREQUENCIA_DESC`,
       t.`PAGO_EM`, FORMAT(t.`VALOR_PAGO`, 2, 'de_DE') AS `VALOR_PAGO`, t.`VALOR_PAGO` AS `VALOR_PAGO_NUM`,
       $situacao AS `SITUACAO_TAXA`,
       (CASE $situacao WHEN 'PAGA' THEN CONCAT('Paga em ', DATE_FORMAT(t.`PAGO_EM`, '%d/%m/%Y'))
            WHEN 'SEM_VENC' THEN 'Sem vencimento'
            WHEN 'VENCIDA' THEN CONCAT('Vencida há ', DATEDIFF(CURDATE(), t.`VENCIMENTO`), ' dia(s)')
            WHEN 'VENCE_30' THEN IF(t.`VENCIMENTO` = CURDATE(), 'Vence hoje', CONCAT('Vence em ', DATEDIFF(t.`VENCIMENTO`, CURDATE()), ' dia(s)'))
            ELSE 'A pagar' END) AS `SITUACAO_TAXA_DESC`,
       IF(t.`PAGO_EM` IS NULL AND t.`VENCIMENTO` IS NOT NULL, DATEDIFF(t.`VENCIMENTO`, CURDATE()), NULL) AS `DIAS`,
       t.`ORIGEM`, IF(t.`ORIGEM` = 'AUTOMATICA', 'Automática (recorrência)', 'Manual') AS `ORIGEM_DESC`, t.`CODTAXA_ANTERIOR`, t.`OBSERVACAO`,
       " . $col('g', 'CODUSUARIO') . " AS `CODUSUARIO`, " . $col('g', 'RESPONSAVEL', "''") . " AS `RESPONSAVEL`, t.`CRIADO_EM`, t.`ALTERADO_EM`
  FROM `li_taxas` t
  LEFT JOIN $k k ON k.`BASE` = t.`CODLICENCA`
  LEFT JOIN `vw_licencas_grid` g ON g.`CODLICENCA` = COALESCE(k.`ATUAL`, t.`CODLICENCA`)";
        $v['vw_li_taxas_licencas'] = "CREATE OR REPLACE VIEW `vw_li_taxas_licencas` AS
SELECT k.`BASE` AS `CODLICENCA`, k.`ATUAL` AS `CODLICENCA_ATUAL`, " . $col('g', 'CODUNIDADE') . " AS `CODUNIDADE`,
       CONCAT_WS(' - ', NULLIF($unidade, ''), COALESCE(NULLIF(TRIM(" . $col('g', 'DSCLICENCA', "''") . "), ''), CONCAT('Licença #', k.`BASE`))) AS `LICENCA_DESC`
  FROM $k k JOIN `vw_licencas_grid` g ON g.`CODLICENCA` = k.`ATUAL`";
        $v['vw_li_taxas_responsaveis'] = $temMinhas
            ? "CREATE OR REPLACE VIEW `vw_li_taxas_responsaveis` AS
SELECT DISTINCT t.`CODTAXA`, m.`CODUSUARIO`
  FROM `li_taxas` t
  LEFT JOIN $k k ON k.`BASE` = t.`CODLICENCA`
  JOIN `vw_minhas_licencas_grid` m ON m.`CODLICENCA` = COALESCE(k.`ATUAL`, t.`CODLICENCA`)
 WHERE m.`CODUSUARIO` IS NOT NULL"
            : "CREATE OR REPLACE VIEW `vw_li_taxas_responsaveis` AS
SELECT `CODTAXA`, `CODUSUARIO` FROM `vw_li_taxas_grid` WHERE `CODUSUARIO` IS NOT NULL";
        $v['vw_minhas_taxas'] = "CREATE OR REPLACE VIEW `vw_minhas_taxas` AS
SELECT r.`CODUSUARIO`, x.`CODTAXA`, x.`CODLICENCA`, x.`CODLICENCA_ATUAL`, x.`CODUNIDADE`, x.`DSCUNIDADE`, x.`LICENCA`, x.`LICENCA_DESC`, x.`TIPO`, x.`TIPO_DESC`,
       x.`DESCRICAO`, x.`NUMERO_GUIA`, x.`VALOR`, x.`VALOR_NUM`, x.`VENCIMENTO`, x.`SITUACAO_TAXA`, x.`SITUACAO_TAXA_DESC`, x.`DIAS`
  FROM `vw_li_taxas_responsaveis` r JOIN `vw_li_taxas_grid` x ON x.`CODTAXA` = r.`CODTAXA`
 WHERE x.`PAGO_EM` IS NULL AND x.`VENCIMENTO` IS NOT NULL AND x.`VENCIMENTO` <= CURDATE() + INTERVAL 30 DAY";
        $usuarios = self::colunas($pdo, 'usuarios');
        $ativo = isset($usuarios['ativo']) ? 'JOIN `usuarios` u ON u.`codusuario` = r.`CODUSUARIO` AND u.`ativo` = 1' : '';
        $v['vw_li_taxas_avisos'] = "CREATE OR REPLACE VIEW `vw_li_taxas_avisos` AS
SELECT r.`CODUSUARIO`, x.`CODTAXA`, a.`AVISO`,
       (CASE WHEN a.`AVISO` = '30' THEN CONCAT('Taxa da licença vence em ', DATEDIFF(x.`VENCIMENTO`, CURDATE()), ' dia(s)')
             WHEN x.`VENCIMENTO` = CURDATE() THEN 'Taxa da licença vence hoje'
             ELSE 'Taxa da licença venceu sem pagamento' END) AS `TITULO`,
       CONCAT(x.`TIPO_DESC`, IF(COALESCE(TRIM(x.`DESCRICAO`), '') = '', '', CONCAT(' (', TRIM(x.`DESCRICAO`), ')')), ' — ', x.`LICENCA_DESC`, '. ',
              IF(x.`VALOR` IS NULL, 'Valor não informado', CONCAT('R$ ', x.`VALOR`)), ', vencimento em ', DATE_FORMAT(x.`VENCIMENTO`, '%d/%m/%Y'),
              IF(COALESCE(TRIM(x.`NUMERO_GUIA`), '') = '', '', CONCAT(', guia nº ', TRIM(x.`NUMERO_GUIA`))),
              '. Depois de pagar, registre a data do pagamento e anexe o comprovante na taxa.') AS `MENSAGEM`,
       CONCAT('/principal/manutencaolegal_licencas_taxas.php?codtaxa=', x.`CODTAXA`, '&aviso=', a.`AVISO`, '&venc=', DATE_FORMAT(x.`VENCIMENTO`, '%Y-%m-%d')) AS `LINK`
  FROM `vw_li_taxas_grid` x
  JOIN (SELECT '30' AS `AVISO` UNION ALL SELECT '0') a
    ON (a.`AVISO` = '30' AND x.`VENCIMENTO` BETWEEN CURDATE() + INTERVAL 1 DAY AND CURDATE() + INTERVAL 30 DAY)
    OR (a.`AVISO` = '0' AND x.`VENCIMENTO` BETWEEN CURDATE() - INTERVAL 3 DAY AND CURDATE())
  JOIN `vw_li_taxas_responsaveis` r ON r.`CODTAXA` = x.`CODTAXA`
  $ativo
 WHERE x.`PAGO_EM` IS NULL";
        $v['vw_dash_li_taxas'] = "CREATE OR REPLACE VIEW `vw_dash_li_taxas` AS
SELECT `CODTAXA`, `CODLICENCA`, `CODLICENCA_ATUAL`, `CODUNIDADE`, `DSCUNIDADE` AS `UNIDADE`, `LICENCA`, `TIPO`, `TIPO_DESC`, `DESCRICAO`, `NUMERO_GUIA`,
       `VALOR`, `VALOR_NUM`, `VENCIMENTO`, `PAGO_EM`, `VALOR_PAGO_NUM`, `SITUACAO_TAXA`, `SITUACAO_TAXA_DESC`, `DIAS`, `RESPONSAVEL`
  FROM `vw_li_taxas_grid`
 WHERE `PAGO_EM` IS NULL OR YEAR(`PAGO_EM`) = YEAR(CURDATE())";
        return $v;
    }
}
