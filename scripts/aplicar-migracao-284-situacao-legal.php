<?php
declare(strict_types=1);

/*
 * Migração 284 — Situação legal consolidada automaticamente (slide 26 da apresentação Atlas Schindler 2026).
 *
 * Em cada empresa (bancos g*_dbp com menus, perfis, permissoes e usuarios; os *_seguranca_dbp ficam de fora) que tenha o
 * módulo legal (legislacaoxempresa):
 *   1. database/284_situacao_legal_calculada.sql — tabelas (situação calculada, histórico, fila, execuções), views das telas
 *      e procedures (sp_situacao_legal_recalcular, sp_situacao_legal_manutencao, sp_situacao_legal_decidir);
 *   2. views de evidência por área, montadas conforme as tabelas e colunas que existem no banco:
 *        vw_sl_ev_licencas, vw_sl_ev_medicao, vw_sl_ev_treinamentos, vw_sl_ev_infraestrutura, vw_sl_ev_controles,
 *        vw_sl_ev_homologacao, a união vw_sl_evidencias e vw_sl_responsaveis (responsável efetivo por registro);
 *      área sem tabela fica com uma view vazia (informada no relatório);
 *   3. gatilhos nas tabelas de gestão e de vínculo que só enfileiram o requisito em situacao_legal_fila (o recálculo é feito
 *      pelo serviço de responsabilidades a cada 5 minutos, e na hora quando a tela de situação é aberta);
 *   4. o primeiro cálculo completo.
 *
 * Regras por área (STATUS de cada item: OK, ATENCAO, FALHA, CRITICO):
 *   Entregas        tipos documentais de natureza ENTREGA_PERIODICA (RAPP, inventário de resíduos, mapas...) ou, sem tipo,
 *   periódicas      nome parecido: prazo passado sem registro de entrega (protocolado em) = FALHA, nunca CRITICO; entregue = OK/ATENCAO.
 *                   A natureza fica em li_tipos_documentos.NATUREZA (criada aqui; Manutenção legal › Tipos documentais de licenças).
 *   Licenças        vencida sem protocolo = CRITICO; vencida protocolada fora do prazo = FALHA; vencida protocolada no prazo,
 *                   sem validade, data de reação ou prazo de protocolo passados, sem responsável ou com taxa vencida e não paga
 *                   (migração 344, li_taxas) = ATENCAO; válida = OK.
 *                   Com versionamento (migração 208), vale a versão ATIVA da licença.
 *   Medição         mês previsto no cronograma (anterior ao atual) sem medição = FALHA; sem medição e sem cronograma = FALHA;
 *                   mês atual previsto e ainda não medido, data de reação passada ou sem responsável = ATENCAO.
 *   Treinamentos e  sem responsável definido = FALHA (os cadastros não têm data de realização nem validade).
 *   infraestrutura
 *   Controles       sem arquivo registrado ou sem responsável = ATENCAO.
 *   Homologação     fornecedor não encontrado ou desativado = FALHA.
 * Requisito: nenhum item = AI; algum CRITICO = N; nenhuma FALHA = S; nenhum OK/ATENCAO = N; senão PA.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-284-situacao-legal.php [--simular] [--sem-gatilhos] [--sem-calculo] [--banco=gatlas_dbp]
 * Pode ser executada mais de uma vez (empresa nova ou tabela criada depois: rodar de novo). Com a migração 341 aplicada no banco,
 * ela é reaplicada no fim (o cálculo com % de atendimento e risco substitui o desta migração).
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/Migracao341RequisitosRisco.php';

const M284_PROCEDURES = ['sp_situacao_legal_calcular_alvo', 'sp_situacao_legal_recalcular', 'sp_situacao_legal_manutencao', 'sp_situacao_legal_decidir'];
// Entregas periódicas (relatórios e declarações com prazo), reconhecidas pelo nome do tipo documental ou da licença
const M284_PADRAO_PERIODICO = '/\\b(RAPP|relat[oó]rio anual|invent[aá]rio|declara[cç][aã]o anual|mapa (de produtos|mensal|trimestral)|DMR)\\b/iu';
const M284_REGEXP_PERIODICO = 'RAPP|RELAT.RIO ANUAL|INVENT.RIO|DECLARA..O ANUAL|MAPA DE PRODUTOS|MAPA MENSAL|MAPA TRIMESTRAL|(^|[^A-Z])DMR([^A-Z]|$)';
const M284_AREAS = ['licencas', 'medicao', 'treinamentos', 'infraestrutura', 'controles', 'homologacao'];

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$semGatilhos = in_array('--sem-gatilhos', $args, true);
$semCalculo = in_array('--sem-calculo', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);

$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$relatorio = ['ok' => true, 'migracao' => '284', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];

$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'perfis', 'permissoes', 'usuarios') AND TABLE_SCHEMA LIKE '%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 4 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp')
    && preg_match('/^g[a-z0-9_]+_dbp$/', (string)$b) && ($soBanco === null || $b === $soBanco)));
$sql = (string)file_get_contents(dirname(__DIR__) . '/database/284_situacao_legal_calculada.sql');

foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $esquema = new M284Esquema($pdo);
        if (!$esquema->tem('legislacaoxempresa')) { $relatorio['bancos'][$banco] = ['ignorado' => 'sem módulo legal (legislacaoxempresa)']; continue; }

        // 1. Parte fixa
        $pulados = [];
        foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) {
            $requer = preg_match('/^-- requer:\s*(.+)$/m', $bloco, $m) ? array_map('trim', explode(',', $m[1])) : [];
            $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
            if ($cmd === '') continue;
            $faltam = array_values(array_filter($requer, static fn($t) => !$esquema->tem($t)));
            if ($faltam) {
                if (preg_match('/(VIEW|PROCEDURE)\s+`(\w+)`/', $cmd, $n) && !str_starts_with($cmd, 'DROP')) $pulados[$n[2]] = 'sem ' . implode(', ', $faltam);
                continue;
            }
            if (!$simular) $pdo->exec($cmd);
        }
        if ($pulados) $info['nao_criados'] = $pulados;
        $esquema->recarregar();

        // 1b. Natureza do tipo documental (licença x entrega periódica); valores padrão só na criação da coluna
        if ($esquema->tem('li_tipos_documentos', 'CODTIPODOCUMENTO', 'DSCTIPODOCUMENTO') && !$esquema->tem('li_tipos_documentos', 'NATUREZA')) {
            if (!$simular) {
                $pdo->exec("ALTER TABLE `li_tipos_documentos` ADD COLUMN `NATUREZA` VARCHAR(20) NOT NULL DEFAULT 'LICENCA'");
                $periodicos = [];
                foreach (MigracaoCrud::selecionar($pdo, 'li_tipos_documentos') as $t) {
                    if (preg_match(M284_PADRAO_PERIODICO, (string)$t['DSCTIPODOCUMENTO'])) {
                        MigracaoCrud::atualizar($pdo, 'li_tipos_documentos', ['CODTIPODOCUMENTO' => (int)$t['CODTIPODOCUMENTO']], ['NATUREZA' => 'ENTREGA_PERIODICA']);
                        $periodicos[] = (string)$t['DSCTIPODOCUMENTO'];
                    }
                }
                $info['tipos_entrega_periodica'] = $periodicos ?: 'nenhum';
                $esquema->recarregar();
            } else $info['tipos_entrega_periodica'] = 'coluna NATUREZA será criada';
        }

        // 2. Views de evidência
        $gerador = new M284Views($esquema);
        $views = $gerador->montar();
        foreach ($views as $nome => $ddl) if (!$simular) $pdo->exec($ddl);
        $info['areas'] = $gerador->situacaoAreas;
        if ($gerador->avisos) $info['avisos'] = $gerador->avisos;

        // 3. Gatilhos
        if ($semGatilhos) $info['gatilhos'] = 'não alterados (--sem-gatilhos)';
        else {
            $criados = 0; $erros = [];
            foreach ((new M284Gatilhos($esquema))->montar() as $nome => $ddl) {
                if ($simular) { $criados++; continue; }
                try {
                    $pdo->exec("DROP TRIGGER IF EXISTS `$nome`");
                    $pdo->exec($ddl);
                    $criados++;
                } catch (Throwable $e) { $erros[$nome] = $e->getMessage(); }
            }
            $info['gatilhos'] = $erros ? ['criados' => $criados, 'erros' => $erros] : $criados . ' criados';
            if ($erros) { $info['aviso_gatilhos'] = 'sem os gatilhos, o recálculo fica com o cálculo diário e com a abertura da tela de situação'; }
        }

        if (!$simular) {
            if ($criadas = MigracaoCrud::garantir($pdo)) $info['procedures_crud_criadas'] = $criadas;
            $s = $pdo->query("SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_TYPE = 'PROCEDURE'");
            $tem = $s->fetchAll(PDO::FETCH_COLUMN);
            $s->closeCursor();
            $faltamP = array_values(array_diff(M284_PROCEDURES, $tem, array_keys($pulados)));
            $info['procedures'] = $faltamP ? ['faltam' => $faltamP] : 'gravadas';
            if ($faltamP) $relatorio['ok'] = false;

            // 3b. Migração 341 (% de atendimento e risco) já aplicada: reaplica, senão o cálculo e as views voltariam à versão da 284
            $com341 = Migracao341RequisitosRisco::aplicada($pdo);
            if ($com341) $info['migracao_341'] = Migracao341RequisitosRisco::aplicar($pdo, false, !$semCalculo);

            // 4. Primeiro cálculo completo
            if (!$semCalculo && !$com341) {
                $t0 = microtime(true);
                $st = $pdo->prepare('CALL sp_situacao_legal_recalcular(NULL, NULL)');
                $st->execute();
                $r = $st->fetchAll(PDO::FETCH_ASSOC);
                $st->closeCursor();
                $totais = [];
                foreach (MigracaoCrud::selecionar($pdo, 'vw_situacao_legal_totais') as $l) $totais[(string)$l['SITUACAO']] = (int)$l['TOTAL'];
                ksort($totais);
                $info['calculo'] = ['requisitos' => (int)($r[0]['requisitos'] ?? 0), 'alterados' => (int)($r[0]['alterados'] ?? 0),
                    'por_situacao' => $totais, 'segundos' => round(microtime(true) - $t0, 1)];
            }
        }
    } catch (Throwable $e) { $relatorio['ok'] = false; $info['erro'] = $e->getMessage(); }
    $relatorio['bancos'][$banco] = $info;
}
echo json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);

/** Tabelas e colunas do banco (information_schema, só metadados). */
final class M284Esquema
{
    private array $colunas = [];

    public function __construct(public readonly PDO $pdo) { $this->recarregar(); }

    public function recarregar(): void
    {
        $this->colunas = [];
        $s = $this->pdo->query('SELECT LOWER(TABLE_NAME) t, LOWER(COLUMN_NAME) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()');
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $this->colunas[$r['t']][$r['c']] = true;
        $s->closeCursor();
    }

    public function tem(string $tabela, string ...$colunas): bool
    {
        $t = $this->colunas[strtolower($tabela)] ?? null;
        if ($t === null) return false;
        foreach ($colunas as $c) if (!isset($t[strtolower($c)])) return false;
        return true;
    }

    /** "alias.`coluna`" se a coluna existir, senão o valor padrão (SQL). */
    public function col(string $alias, string $tabela, string $coluna, string $padrao = 'NULL'): string
    {
        return $this->tem($tabela, $coluna) ? "$alias.`$coluna`" : $padrao;
    }

    /** Primeira tabela da lista que existe. */
    public function primeira(string ...$tabelas): ?string
    {
        foreach ($tabelas as $t) if ($this->tem($t)) return $t;
        return null;
    }
}

/** Monta as views de evidência conforme o banco. */
final class M284Views
{
    public array $situacaoAreas = [];
    public array $avisos = [];
    private string $resp = '1';

    public function __construct(private readonly M284Esquema $e) {}

    public function montar(): array
    {
        $v = [];
        $responsaveis = $this->responsaveis();
        if ($responsaveis) { $v['vw_sl_responsaveis'] = $responsaveis; }
        else $this->avisos[] = 'sem responsabilidades (vw_responsabilidade_efetiva / responsabilidade_atribuicoes): o critério "sem responsável" não é aplicado';
        foreach (M284_AREAS as $area) {
            [$sql, $situacao] = $this->{'area' . ucfirst($area)}();
            $this->situacaoAreas[$area] = $situacao;
            $v['vw_sl_ev_' . $area] = 'CREATE OR REPLACE VIEW `vw_sl_ev_' . $area . '` AS ' . ($sql ?? $this->vazia());
        }
        $v['vw_sl_evidencias'] = 'CREATE OR REPLACE VIEW `vw_sl_evidencias` AS '
            . implode("\nUNION ALL\n", array_map(static fn($a) => "SELECT `CODLEGISLACAO`, `CODUNIDADE`, `AREA`, `ITEM_ID`, `ITEM`, `STATUS`, `MOTIVO` FROM `vw_sl_ev_$a`", M284_AREAS));
        return $v;
    }

    // ------------------------------------------------------------------ auxiliares SQL
    private static function d(string $expr): string { return $expr === 'NULL' ? 'NULL' : "(CASE WHEN YEAR($expr) > 0 THEN DATE($expr) END)"; }
    private static function f(string $expr): string { return "DATE_FORMAT($expr, '%d/%m/%Y')"; }
    private static function txt(string $expr, int $n): string { return "CAST($expr AS CHAR($n) CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci"; }
    private static function sim(string $expr): string { return "(UPPER(TRIM(COALESCE($expr, ''))) IN ('S', 'SIM', '1', 'Y', 'YES', 'X'))"; }

    private function linha(string $leg, string $unid, string $area, string $id, string $item, string $status, string $motivo): string
    {
        return "SELECT CAST($leg AS SIGNED) AS `CODLEGISLACAO`, CAST(COALESCE($unid, 0) AS SIGNED) AS `CODUNIDADE`, "
            . self::txt("'$area'", 30) . ' AS `AREA`, CAST(' . $id . ' AS SIGNED) AS `ITEM_ID`, '
            . self::txt("LEFT($item, 300)", 300) . ' AS `ITEM`, ' . self::txt($status, 10) . ' AS `STATUS`, '
            . self::txt("LEFT($motivo, 500)", 500) . ' AS `MOTIVO`';
    }

    private function vazia(): string
    {
        return $this->linha('NULL', '0', '-', 'NULL', "''", "''", "''") . ' FROM DUAL WHERE 1 = 0';
    }

    /** Quantidade de responsáveis efetivos do registro (0 = nenhum). */
    private function temResp(string $entidade, string $id): string
    {
        return $this->resp === '1' ? '1'
            : "(SELECT COALESCE(SUM(s.`QTD`), 0) FROM `vw_sl_responsaveis` s WHERE s.`ENTIDADE` = '$entidade' AND s.`REGISTRO` IN (0, $id))";
    }

    private function responsaveis(): ?string
    {
        $e = $this->e;
        if ($e->tem('vw_responsabilidade_efetiva', 'ENTIDADE', 'REGISTRO_ID')) {
            $this->resp = 'view';
            return "CREATE OR REPLACE VIEW `vw_sl_responsaveis` AS
SELECT " . self::txt('`ENTIDADE`', 100) . " AS `ENTIDADE`,
       CAST(CASE WHEN TRIM(COALESCE(`REGISTRO_ID`, '')) = '' THEN 0 ELSE `REGISTRO_ID` END AS UNSIGNED) AS `REGISTRO`, COUNT(*) AS `QTD`
  FROM `vw_responsabilidade_efetiva`
 WHERE `ENTIDADE` IS NOT NULL AND TRIM(COALESCE(`REGISTRO_ID`, '')) REGEXP '^[0-9]*$'
 GROUP BY 1, 2";
        }
        if ($e->tem('responsabilidade_atribuicoes', 'ENTIDADE', 'REGISTRO_ID', 'ATIVO', 'VALIDO_DE', 'VALIDO_ATE')) {
            $this->resp = 'tabela';
            return "CREATE OR REPLACE VIEW `vw_sl_responsaveis` AS
SELECT " . self::txt('`ENTIDADE`', 100) . " AS `ENTIDADE`,
       CAST(CASE WHEN TRIM(COALESCE(`REGISTRO_ID`, '')) = '' THEN 0 ELSE `REGISTRO_ID` END AS UNSIGNED) AS `REGISTRO`, COUNT(*) AS `QTD`
  FROM `responsabilidade_atribuicoes`
 WHERE `ATIVO` = 1 AND `VALIDO_DE` <= CURDATE() AND (`VALIDO_ATE` IS NULL OR `VALIDO_ATE` >= CURDATE())
   AND `ENTIDADE` IS NOT NULL AND TRIM(COALESCE(`REGISTRO_ID`, '')) REGEXP '^[0-9]*$'
 GROUP BY 1, 2";
        }
        return null;
    }

    // ------------------------------------------------------------------ áreas
    private function areaLicencas(): array
    {
        $e = $this->e;
        if (!$e->tem('legislacaoxlicencas', 'CODLEGISLACAO', 'CODLICENCA') || !$e->tem('li_licencas', 'CODLICENCA', 'DATA_VALIDADE'))
            return [null, 'sem legislacaoxlicencas/li_licencas'];
        $unid = $e->tem('legislacaoxlicencas', 'codunidade') ? 'r.`codunidade`' : $e->col('b', 'li_licencas', 'codunidade', '0');
        $versao = $e->tem('li_licencas', 'CODLICENCA_BASE', 'STATUS_VERSAO');
        $cod = $versao
            ? "COALESCE((SELECT MAX(v.`CODLICENCA`) FROM `li_licencas` v WHERE COALESCE(v.`CODLICENCA_BASE`, v.`CODLICENCA`) = COALESCE(b.`CODLICENCA_BASE`, b.`CODLICENCA`) AND v.`STATUS_VERSAO` = 'ATIVA'), b.`CODLICENCA`)"
            : 'b.`CODLICENCA`';
        $V = self::d('l.`DATA_VALIDADE`');
        $R = self::d($e->col('l', 'li_licencas', 'DATA_REACAO'));
        $P = self::d($e->col('l', 'li_licencas', 'DATA_PROTOCOLO'));
        $PE = self::d($e->col('l', 'li_licencas', 'PROTOCOLADO_EM'));
        $emProt = self::sim($e->col('l', 'li_licencas', 'EMPROTOCOLO'));
        $flagPrazo = self::sim($e->col('l', 'li_licencas', 'PROTOCOLADO_NO_PRAZO'));
        $noPrazo = "(CASE WHEN $PE IS NOT NULL AND $P IS NOT NULL THEN $PE <= $P ELSE $flagPrazo END)";
        // Em protocolo sem as datas e sem "Protocolado no prazo?": não dá para saber se foi no prazo (decisão do usuário de 27/10/2026:
        // "Em protocolo (prazo não informado)", em Atenção — nem vencida nem válida)
        $semInfo = "(($PE IS NULL OR $P IS NULL) AND TRIM(COALESCE(" . $e->col('l', 'li_licencas', 'PROTOCOLADO_NO_PRAZO') . ", '')) = '')";
        $resp = $this->temResp('li_licencas', 'l.`CODLICENCA`');
        $nome = "COALESCE(NULLIF(TRIM(l.`DSCLICENCA`), ''), CONCAT('#', l.`CODLICENCA`))";
        if (!$e->tem('li_licencas', 'DSCLICENCA')) $nome = "CONCAT('#', l.`CODLICENCA`)";
        // Entrega periódica: natureza do tipo documental; sem tipo (ou "Não classificado"), pelo nome da licença
        $porNome = $e->tem('li_licencas', 'DSCLICENCA')
            ? "(UPPER(CONVERT(COALESCE(l.`DSCLICENCA`, '') USING utf8mb4)) COLLATE utf8mb4_unicode_ci REGEXP '" . M284_REGEXP_PERIODICO . "')" : 'FALSE';
        $joinTipo = '';
        $periodico = $porNome;
        if ($e->tem('li_licencas', 'CODTIPODOCUMENTO') && $e->tem('li_tipos_documentos', 'CODTIPODOCUMENTO', 'DSCTIPODOCUMENTO', 'NATUREZA')) {
            $joinTipo = "\n  LEFT JOIN `li_tipos_documentos` td ON td.`CODTIPODOCUMENTO` = l.`CODTIPODOCUMENTO`";
            $periodico = "(CASE WHEN td.`CODTIPODOCUMENTO` IS NULL OR UPPER(td.`DSCTIPODOCUMENTO`) LIKE 'N%O CLASSIFICADO%' THEN $porNome
                ELSE UPPER(td.`NATUREZA`) = 'ENTREGA_PERIODICA' END)";
        }
        // Migração 344: taxa da licença vencida e não paga (li_taxas, na licença original) deixa a licença válida em Atenção
        $taxa = 'FALSE'; $taxaMotivo = "''";
        if ($e->tem('li_taxas', 'CODLICENCA', 'PAGO_EM', 'VENCIMENTO')) {
            $origem = $e->tem('li_licencas', 'CODLICENCA_BASE') ? 'COALESCE(l.`CODLICENCA_BASE`, l.`CODLICENCA`)' : 'l.`CODLICENCA`';
            $taxa = "EXISTS (SELECT 1 FROM `li_taxas` tx WHERE tx.`CODLICENCA` = $origem AND tx.`PAGO_EM` IS NULL AND tx.`VENCIMENTO` < CURDATE())";
            $taxaMotivo = "(SELECT CONCAT('; ', COUNT(*), ' taxa(s) vencida(s) sem pagamento (a mais antiga em ', DATE_FORMAT(MIN(tx.`VENCIMENTO`), '%d/%m/%Y'), ')')
                FROM `li_taxas` tx WHERE tx.`CODLICENCA` = $origem AND tx.`PAGO_EM` IS NULL AND tx.`VENCIMENTO` < CURDATE())";
        }
        $entregue = "IF($PE IS NULL, '', CONCAT(' em ', " . self::f($PE) . '))';
        $protEm = "IF($PE IS NULL, '', CONCAT(' em ', " . self::f($PE) . '))';
        $status = "CASE WHEN $periodico AND $V IS NULL THEN 'ATENCAO'
            WHEN $periodico AND $V >= CURDATE() AND $R IS NOT NULL AND $R <= CURDATE() THEN 'ATENCAO'
            WHEN $periodico AND $V >= CURDATE() THEN 'OK'
            WHEN $periodico AND $PE IS NOT NULL THEN 'ATENCAO'
            WHEN $periodico THEN 'FALHA'
            WHEN $V IS NULL THEN 'ATENCAO'
            WHEN $V < CURDATE() AND $emProt AND $noPrazo THEN 'ATENCAO'
            WHEN $V < CURDATE() AND $emProt AND $semInfo THEN 'ATENCAO'
            WHEN $V < CURDATE() AND $emProt THEN 'FALHA'
            WHEN $V < CURDATE() THEN 'CRITICO'
            WHEN $taxa THEN 'ATENCAO'
            WHEN $emProt THEN 'OK'
            WHEN $R IS NOT NULL AND $R <= CURDATE() THEN 'ATENCAO'
            WHEN $P IS NOT NULL AND $P < CURDATE() THEN 'ATENCAO'
            WHEN $resp = 0 THEN 'ATENCAO'
            ELSE 'OK' END";
        $motivo = "CASE WHEN $periodico AND $V IS NULL THEN 'entrega periódica sem data de prazo cadastrada'
            WHEN $periodico AND $V >= CURDATE() AND $R IS NOT NULL AND $R <= CURDATE() THEN CONCAT('entrega periódica: prazo em ', " . self::f($V) . ", '; a preparação deveria ter começado em ', " . self::f($R) . ")
            WHEN $periodico AND $V >= CURDATE() THEN CONCAT('entrega periódica: próximo prazo em ', " . self::f($V) . ")
            WHEN $periodico AND $PE IS NOT NULL AND $PE <= $V THEN CONCAT('entrega periódica: entregue', $entregue, ' (prazo ', " . self::f($V) . ", '); cadastre o prazo da próxima entrega')
            WHEN $periodico AND $PE IS NOT NULL THEN CONCAT('entrega periódica: entregue com atraso', $entregue, ' (prazo ', " . self::f($V) . ", ')')
            WHEN $periodico THEN CONCAT('entrega periódica: prazo venceu em ', " . self::f($V) . ", ' sem registro de entrega')
            WHEN $V IS NULL THEN 'sem data de validade cadastrada'
            WHEN $V < CURDATE() AND $emProt AND $noPrazo THEN CONCAT('vencida em ', " . self::f($V) . ", '; renovação protocolada no prazo', $protEm)
            WHEN $V < CURDATE() AND $emProt AND $semInfo THEN CONCAT('vencida em ', " . self::f($V) . ", '; em protocolo, mas sem as datas do protocolo nem \"Protocolado no prazo?\": não dá para saber se foi no prazo', $protEm)
            WHEN $V < CURDATE() AND $emProt THEN CONCAT('vencida em ', " . self::f($V) . ", '; renovação protocolada fora do prazo', $protEm)
            WHEN $V < CURDATE() THEN CONCAT('vencida em ', " . self::f($V) . ", ' sem protocolo de renovação')
            WHEN $taxa THEN CONCAT('válida até ', " . self::f($V) . ", $taxaMotivo)
            WHEN $emProt THEN CONCAT('válida até ', " . self::f($V) . ", '; renovação já protocolada', $protEm)
            WHEN $R IS NOT NULL AND $R <= CURDATE() THEN CONCAT('válida até ', " . self::f($V) . ", '; a renovação deveria ter começado em ', " . self::f($R) . ")
            WHEN $P IS NOT NULL AND $P < CURDATE() THEN CONCAT('válida até ', " . self::f($V) . ", '; o prazo para protocolar a renovação venceu em ', " . self::f($P) . ")
            WHEN $resp = 0 THEN CONCAT('válida até ', " . self::f($V) . ", '; sem responsável definido')
            ELSE CONCAT('válida até ', " . self::f($V) . ') END';
        $sql = $this->linha('k.`CODLEGISLACAO`', 'k.`CODUNIDADE`', 'LICENCAS', 'l.`CODLICENCA`', $nome, $status, $motivo) . "
  FROM (SELECT DISTINCT r.`CODLEGISLACAO`, $unid AS `CODUNIDADE`, $cod AS `CODLICENCA`
          FROM `legislacaoxlicencas` r JOIN `li_licencas` b ON b.`CODLICENCA` = r.`CODLICENCA`) k
  JOIN `li_licencas` l ON l.`CODLICENCA` = k.`CODLICENCA`$joinTipo";
        return [$sql, ($versao ? 'ok (versão ativa' : 'ok (') . ($joinTipo ? ', entregas periódicas pelo tipo documental e pelo nome)' : ', entregas periódicas pelo nome)')];
    }

    private function areaMedicao(): array
    {
        $e = $this->e;
        if (!$e->tem('mm_medicaoxlegislacao', 'codmedicao', 'CODLEGISLACAO') || !$e->tem('mm_monitoramento', 'CODMONITORAMENTO', 'codmedicao'))
            return [null, 'sem mm_medicaoxlegislacao/mm_monitoramento'];
        $DM = self::d($e->col('m', 'mm_monitoramento', 'DATA_MEDICAO'));
        $DR = self::d($e->col('m', 'mm_monitoramento', 'DATA_REACAO'));
        $meses = ['janeiro', 'fevereiro', 'marco', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        $temCrono = true;
        foreach ($meses as $m) if (!$e->tem('mm_monitoramento', 'cronograma_' . $m)) $temCrono = false;
        $prev = '0'; $atual = 'FALSE'; $algum = 'FALSE';
        if ($temCrono) {
            $flag = fn($m) => "(UPPER(TRIM(COALESCE(CAST(m.`cronograma_$m` AS CHAR), ''))) NOT IN ('', 'N', '0', 'NAO', 'NÃO', 'FALSE'))";
            $p = []; $a = []; $any = [];
            foreach ($meses as $i => $m) {
                $n = $i + 1;
                $p[] = "IF({$flag($m)} AND $n < MONTH(CURDATE()), $n, 0)";
                $a[] = "({$flag($m)} AND $n = MONTH(CURDATE()))";
                $any[] = $flag($m);
            }
            $prev = 'GREATEST(' . implode(', ', $p) . ')';
            $atual = '(' . implode(' OR ', $a) . ')';
            $algum = '(' . implode(' OR ', $any) . ')';
        } else $this->avisos[] = 'mm_monitoramento sem as colunas cronograma_<mês>: a medição usa só as datas';
        $inicioMes = fn(string $n) => "MAKEDATE(YEAR(CURDATE()), 1) + INTERVAL ($n - 1) MONTH";
        $mesNome = fn(string $n) => "CONCAT(ELT($n, 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'), '/', YEAR(CURDATE()))";
        $faltaAnterior = "($prev > 0 AND ($DM IS NULL OR $DM < {$inicioMes($prev)}))";
        $faltaAtual = "($atual AND ($DM IS NULL OR $DM < {$inicioMes('MONTH(CURDATE())')}))";
        $resp = $this->temResp('mm_monitoramento', 'm.`CODMONITORAMENTO`');
        $nome = $e->tem('mm_monitoramento', 'PARAMETROS')
            ? "COALESCE(NULLIF(TRIM(m.`PARAMETROS`), ''), CONCAT('#', m.`CODMONITORAMENTO`))" : "CONCAT('#', m.`CODMONITORAMENTO`)";
        $ultima = "IF($DM IS NULL, '', CONCAT('; última medição em ', " . self::f($DM) . '))';
        $status = "CASE WHEN $faltaAnterior THEN 'FALHA'
            WHEN $DM IS NULL AND NOT $algum THEN 'FALHA'
            WHEN $faltaAtual THEN 'ATENCAO'
            WHEN $DM IS NULL THEN 'ATENCAO'
            WHEN $DR IS NOT NULL AND $DR < CURDATE() THEN 'ATENCAO'
            WHEN $resp = 0 THEN 'ATENCAO'
            ELSE 'OK' END";
        $motivo = "CASE WHEN $faltaAnterior THEN CONCAT('medição prevista no cronograma para ', {$mesNome($prev)}, ' não registrada', $ultima)
            WHEN $DM IS NULL AND NOT $algum THEN 'nenhuma medição registrada nem prevista no cronograma'
            WHEN $faltaAtual THEN CONCAT('medição prevista para este mês ainda não registrada', $ultima)
            WHEN $DM IS NULL THEN 'nenhuma medição registrada; a primeira está prevista no cronograma'
            WHEN $DR IS NOT NULL AND $DR < CURDATE() THEN CONCAT('última medição em ', " . self::f($DM) . ", '; a data de reação (', " . self::f($DR) . ", ') já passou')
            WHEN $resp = 0 THEN CONCAT('última medição em ', " . self::f($DM) . ", '; sem responsável definido')
            ELSE CONCAT('última medição em ', " . self::f($DM) . ') END';
        $unid = $e->col('m', 'mm_monitoramento', 'codunidade', '0');
        $sql = $this->linha('x.`CODLEGISLACAO`', $unid, 'MEDICAO', 'm.`CODMONITORAMENTO`', $nome, $status, $motivo) . "
  FROM `mm_medicaoxlegislacao` x JOIN `mm_monitoramento` m ON m.`codmedicao` = x.`codmedicao`";
        return [$sql, $temCrono ? 'ok (datas e cronograma)' : 'ok (só datas)'];
    }

    private function catalogo(string $area, string $vinculo, string $chaveVinculo, array $tabelas, string $chave, string $descricao, string $entidade): array
    {
        $e = $this->e;
        $t = $e->primeira(...$tabelas);
        if (!$e->tem($vinculo, 'CODLEGISLACAO', $chaveVinculo) || $t === null || !$e->tem($t, $chave)) return [null, "sem $vinculo/" . implode('|', $tabelas)];
        $resp = $this->temResp($entidade, "t.`$chave`");
        $nome = $e->tem($t, $descricao) ? "COALESCE(NULLIF(TRIM(t.`$descricao`), ''), CONCAT('#', t.`$chave`))" : "CONCAT('#', t.`$chave`)";
        $status = $this->resp === '1' ? "'OK'" : "CASE WHEN $resp = 0 THEN 'FALHA' ELSE 'OK' END";
        $motivo = $this->resp === '1' ? "'vinculado ao requisito'" : "CASE WHEN $resp = 0 THEN 'sem responsável definido' ELSE 'responsável definido' END";
        $unid = $e->col('r', $vinculo, 'CODUNIDADE', '0');
        $sql = $this->linha('r.`CODLEGISLACAO`', $unid, $area, "t.`$chave`", $nome, $status, $motivo) . "
  FROM `$vinculo` r JOIN `$t` t ON t.`$chave` = r.`$chaveVinculo`";
        return [$sql, "ok ($t)"];
    }

    private function areaTreinamentos(): array
    {
        return $this->catalogo('TREINAMENTOS', 'legislacaoxtreinamentos', 'CODTREINAMENTO', ['ml_treinamentos', 'ml_treinamenos'],
            'codtreinamento', 'dsctreinamento', 'ml_treinamentos');
    }

    private function areaInfraestrutura(): array
    {
        return $this->catalogo('INFRAESTRUTURA', 'legislacaoxinfraestruturas', 'CODINFRAESTRUTURA', ['ml_infraestrutura'],
            'codinfraestrutura', 'dscinfraestrutura', 'ml_infraestrutura');
    }

    private function areaControles(): array
    {
        $e = $this->e;
        if (!$e->tem('legislacaoxdocumentos', 'CODLEGISLACAO', 'CODDOCUMENTO') || !$e->tem('documentos', 'CODDOCUMENTO'))
            return [null, 'sem legislacaoxdocumentos/documentos'];
        $resp = $this->temResp('documentos', 'd.`CODDOCUMENTO`');
        $semArq = $e->tem('documentos', 'ARQUIVO') ? "TRIM(COALESCE(d.`ARQUIVO`, '')) = ''" : 'FALSE';
        $nome = "CONCAT_WS(' - ', NULLIF(TRIM(" . $e->col('d', 'documentos', 'CODIGO', "''") . "), ''), NULLIF(TRIM(" . $e->col('d', 'documentos', 'TITULO', "''") . "), ''), IF(TRIM(CONCAT(" . $e->col('d', 'documentos', 'CODIGO', "''") . ', ' . $e->col('d', 'documentos', 'TITULO', "''") . ")) = '', CONCAT('#', d.`CODDOCUMENTO`), NULL))";
        $status = "CASE WHEN $semArq THEN 'ATENCAO' WHEN $resp = 0 THEN 'ATENCAO' ELSE 'OK' END";
        $motivo = "CASE WHEN $semArq THEN 'documento sem arquivo registrado' WHEN $resp = 0 THEN 'sem responsável definido' ELSE 'documento com arquivo e responsável' END";
        $unid = $e->col('r', 'legislacaoxdocumentos', 'codunidade', '0');
        $sql = $this->linha('r.`CODLEGISLACAO`', $unid, 'CONTROLES', 'd.`CODDOCUMENTO`', $nome, $status, $motivo) . "
  FROM `legislacaoxdocumentos` r JOIN `documentos` d ON d.`CODDOCUMENTO` = r.`CODDOCUMENTO`";
        return [$sql, 'ok'];
    }

    private function areaHomologacao(): array
    {
        $e = $this->e;
        if (!$e->tem('legislacaoxfornecedores', 'CODLEGISLACAO', 'CODUNIDADE', 'CODFORNECEDOR')) return [null, 'sem legislacaoxfornecedores'];
        if (!$e->tem('fo_fornecedores', 'CODFORNECEDOR')) return [null, 'sem fo_fornecedores'];
        $inativo = $e->tem('fo_fornecedores', 'ATIVADOFOR')
            ? "UPPER(TRIM(COALESCE(CAST(f.`ATIVADOFOR` AS CHAR), 'S'))) IN ('N', '0', 'NAO', 'NÃO', 'INATIVO', 'FALSE')" : 'FALSE';
        $razao = $e->col('f', 'fo_fornecedores', 'RAZAOSOCIAL', "''");
        $fantasia = $e->col('f', 'fo_fornecedores', 'NOMEFANTASIA', "''");
        $nome = "COALESCE(NULLIF(TRIM($fantasia), ''), NULLIF(TRIM($razao), ''), CONCAT('#', r.`CODFORNECEDOR`))";
        $status = "CASE WHEN f.`CODFORNECEDOR` IS NULL THEN 'FALHA' WHEN $inativo THEN 'FALHA' ELSE 'OK' END";
        $motivo = "CASE WHEN f.`CODFORNECEDOR` IS NULL THEN 'fornecedor não encontrado no cadastro' WHEN $inativo THEN 'fornecedor desativado' ELSE 'fornecedor ativo' END";
        $sql = $this->linha('r.`CODLEGISLACAO`', 'r.`CODUNIDADE`', 'HOMOLOGACAO', 'r.`CODFORNECEDOR`', $nome, $status, $motivo) . "
  FROM `legislacaoxfornecedores` r LEFT JOIN `fo_fornecedores` f ON f.`CODFORNECEDOR` = r.`CODFORNECEDOR`";
        return [$sql, 'ok'];
    }
}

/** Gatilhos que só enfileiram o requisito (a tabela situacao_legal_fila não pode faltar). */
final class M284Gatilhos
{
    public function __construct(private readonly M284Esquema $e) {}

    private static function fila(string $select): string
    {
        return "INSERT INTO `situacao_legal_fila` (`CODLEGISLACAO`, `CODUNIDADE`, `enfileirado_em`) $select ON DUPLICATE KEY UPDATE `enfileirado_em` = NOW(6)";
    }

    private static function gatilho(string $nome, string $momento, string $tabela, array $comandos): array
    {
        $corpo = count($comandos) === 1 ? $comandos[0] : "BEGIN\n    " . implode(";\n    ", $comandos) . ";\nEND";
        return [$nome => "CREATE TRIGGER `$nome` AFTER $momento ON `$tabela` FOR EACH ROW $corpo"];
    }

    public function montar(): array
    {
        $e = $this->e;
        if (!$e->tem('situacao_legal_fila')) return [];
        $g = [];
        // Tabelas de vínculo requisito x item: enfileira o requisito (unidade da linha, ou 0 = todas as unidades)
        $vinculos = ['legislacaoxlicencas' => 'codunidade', 'mm_medicaoxlegislacao' => 'codunidade', 'legislacaoxtreinamentos' => 'CODUNIDADE',
            'legislacaoxinfraestruturas' => 'CODUNIDADE', 'legislacaoxdocumentos' => 'codunidade', 'legislacaoxfornecedores' => 'CODUNIDADE'];
        $curto = ['legislacaoxlicencas' => 'lxlic', 'mm_medicaoxlegislacao' => 'mmxleg', 'legislacaoxtreinamentos' => 'lxtre',
            'legislacaoxinfraestruturas' => 'lxinf', 'legislacaoxdocumentos' => 'lxdoc', 'legislacaoxfornecedores' => 'lxfor'];
        foreach ($vinculos as $t => $u) {
            if (!$e->tem($t, 'CODLEGISLACAO')) continue;
            $un = fn($r) => $e->tem($t, $u) ? "COALESCE($r.`$u`, 0)" : '0';
            $sel = fn($r) => self::fila("SELECT $r.`CODLEGISLACAO`, {$un($r)}, NOW(6) FROM DUAL WHERE $r.`CODLEGISLACAO` IS NOT NULL");
            $g += self::gatilho("trg_sl_{$curto[$t]}_ai", 'INSERT', $t, [$sel('NEW')]);
            $g += self::gatilho("trg_sl_{$curto[$t]}_au", 'UPDATE', $t, [$sel('NEW'), $sel('OLD')]);
            $g += self::gatilho("trg_sl_{$curto[$t]}_ad", 'DELETE', $t, [$sel('OLD')]);
        }
        // Itens de gestão: enfileira os requisitos vinculados ao item alterado
        if ($e->tem('li_licencas', 'CODLICENCA') && $e->tem('legislacaoxlicencas', 'CODLEGISLACAO', 'CODLICENCA')) {
            $u = $e->tem('legislacaoxlicencas', 'codunidade') ? 'COALESCE(r.`codunidade`, 0)' : '0';
            $onde = $e->tem('li_licencas', 'CODLICENCA_BASE')
                ? 'r.`CODLICENCA` IN (SELECT v.`CODLICENCA` FROM `li_licencas` v WHERE COALESCE(v.`CODLICENCA_BASE`, v.`CODLICENCA`) = COALESCE(NEW.`CODLICENCA_BASE`, NEW.`CODLICENCA`))'
                : 'r.`CODLICENCA` = NEW.`CODLICENCA`';
            $c = self::fila("SELECT DISTINCT r.`CODLEGISLACAO`, $u, NOW(6) FROM `legislacaoxlicencas` r WHERE $onde AND r.`CODLEGISLACAO` IS NOT NULL");
            $g += self::gatilho('trg_sl_lilic_ai', 'INSERT', 'li_licencas', [$c]);
            $g += self::gatilho('trg_sl_lilic_au', 'UPDATE', 'li_licencas', [$c]);
        }
        // Migração 344: taxas da licença (na licença original): enfileira os requisitos de todas as versões
        if ($e->tem('li_taxas', 'CODLICENCA') && $e->tem('li_licencas', 'CODLICENCA') && $e->tem('legislacaoxlicencas', 'CODLEGISLACAO', 'CODLICENCA')) {
            $u = $e->tem('legislacaoxlicencas', 'codunidade') ? 'COALESCE(r.`codunidade`, 0)' : '0';
            $orig = $e->tem('li_licencas', 'CODLICENCA_BASE') ? 'COALESCE(v.`CODLICENCA_BASE`, v.`CODLICENCA`)' : 'v.`CODLICENCA`';
            $c = static fn(string $rec): string => self::fila("SELECT DISTINCT r.`CODLEGISLACAO`, $u, NOW(6) FROM `legislacaoxlicencas` r JOIN `li_licencas` v ON v.`CODLICENCA` = r.`CODLICENCA` WHERE $orig = $rec.`CODLICENCA` AND r.`CODLEGISLACAO` IS NOT NULL");
            $g += self::gatilho('trg_sl_litax_ai', 'INSERT', 'li_taxas', [$c('NEW')]);
            $g += self::gatilho('trg_sl_litax_au', 'UPDATE', 'li_taxas', [$c('NEW')]);
            $g += self::gatilho('trg_sl_litax_ad', 'DELETE', 'li_taxas', [$c('OLD')]);
        }
        if ($e->tem('mm_monitoramento', 'codmedicao') && $e->tem('mm_medicaoxlegislacao', 'codmedicao', 'CODLEGISLACAO')) {
            $u = $e->tem('mm_monitoramento', 'codunidade') ? 'COALESCE(NEW.`codunidade`, 0)' : '0';
            $c = self::fila("SELECT DISTINCT x.`CODLEGISLACAO`, $u, NOW(6) FROM `mm_medicaoxlegislacao` x WHERE x.`codmedicao` = NEW.`codmedicao` AND x.`CODLEGISLACAO` IS NOT NULL");
            $g += self::gatilho('trg_sl_mmmon_ai', 'INSERT', 'mm_monitoramento', [$c]);
            $g += self::gatilho('trg_sl_mmmon_au', 'UPDATE', 'mm_monitoramento', [$c]);
        }
        if ($e->tem('documentos', 'CODDOCUMENTO') && $e->tem('legislacaoxdocumentos', 'CODLEGISLACAO', 'CODDOCUMENTO')) {
            $u = $e->tem('legislacaoxdocumentos', 'codunidade') ? 'COALESCE(r.`codunidade`, 0)' : '0';
            $g += self::gatilho('trg_sl_docum_au', 'UPDATE', 'documentos',
                [self::fila("SELECT DISTINCT r.`CODLEGISLACAO`, $u, NOW(6) FROM `legislacaoxdocumentos` r WHERE r.`CODDOCUMENTO` = NEW.`CODDOCUMENTO` AND r.`CODLEGISLACAO` IS NOT NULL")]);
        }
        if ($e->tem('fo_fornecedores', 'CODFORNECEDOR') && $e->tem('legislacaoxfornecedores', 'CODLEGISLACAO', 'CODUNIDADE', 'CODFORNECEDOR')) {
            $g += self::gatilho('trg_sl_fofor_au', 'UPDATE', 'fo_fornecedores',
                [self::fila('SELECT DISTINCT r.`CODLEGISLACAO`, r.`CODUNIDADE`, NOW(6) FROM `legislacaoxfornecedores` r WHERE r.`CODFORNECEDOR` = NEW.`CODFORNECEDOR`')]);
        }
        // Responsáveis por registro (atribuição direta); as por tipo/grupo entram no cálculo diário
        if ($e->tem('responsabilidade_atribuicoes', 'ENTIDADE', 'REGISTRO_ID')) {
            $mapas = [];
            if ($e->tem('legislacaoxlicencas', 'CODLEGISLACAO', 'CODLICENCA'))
                $mapas['li_licencas'] = ['legislacaoxlicencas r', 'r.`CODLICENCA`', $e->tem('legislacaoxlicencas', 'codunidade') ? 'COALESCE(r.`codunidade`, 0)' : '0'];
            if ($e->tem('mm_medicaoxlegislacao', 'codmedicao', 'CODLEGISLACAO') && $e->tem('mm_monitoramento', 'CODMONITORAMENTO', 'codmedicao'))
                $mapas['mm_monitoramento'] = ['mm_monitoramento m JOIN `mm_medicaoxlegislacao` r ON r.`codmedicao` = m.`codmedicao`', 'm.`CODMONITORAMENTO`',
                    $e->tem('mm_monitoramento', 'codunidade') ? 'COALESCE(m.`codunidade`, 0)' : '0'];
            if ($e->tem('legislacaoxtreinamentos', 'CODLEGISLACAO', 'CODTREINAMENTO'))
                $mapas['ml_treinamentos'] = ['legislacaoxtreinamentos r', 'r.`CODTREINAMENTO`', $e->tem('legislacaoxtreinamentos', 'CODUNIDADE') ? 'COALESCE(r.`CODUNIDADE`, 0)' : '0'];
            if ($e->tem('legislacaoxinfraestruturas', 'CODLEGISLACAO', 'CODINFRAESTRUTURA'))
                $mapas['ml_infraestrutura'] = ['legislacaoxinfraestruturas r', 'r.`CODINFRAESTRUTURA`', $e->tem('legislacaoxinfraestruturas', 'CODUNIDADE') ? 'COALESCE(r.`CODUNIDADE`, 0)' : '0'];
            if ($e->tem('legislacaoxdocumentos', 'CODLEGISLACAO', 'CODDOCUMENTO'))
                $mapas['documentos'] = ['legislacaoxdocumentos r', 'r.`CODDOCUMENTO`', $e->tem('legislacaoxdocumentos', 'codunidade') ? 'COALESCE(r.`codunidade`, 0)' : '0'];
            $cmds = function (string $rec) use ($mapas): array {
                $l = [];
                foreach ($mapas as $ent => [$from, $chave, $un]) {
                    $from = preg_replace('/^(\w+)/', '`$1`', $from);
                    $l[] = self::fila("SELECT DISTINCT r.`CODLEGISLACAO`, $un, NOW(6) FROM $from WHERE $rec.`ENTIDADE` = '$ent' AND TRIM(COALESCE($rec.`REGISTRO_ID`, '')) REGEXP '^[0-9]+$' AND $chave = CAST($rec.`REGISTRO_ID` AS UNSIGNED) AND r.`CODLEGISLACAO` IS NOT NULL");
                }
                return $l;
            };
            if ($mapas) {
                $g += self::gatilho('trg_sl_respa_ai', 'INSERT', 'responsabilidade_atribuicoes', $cmds('NEW'));
                $g += self::gatilho('trg_sl_respa_au', 'UPDATE', 'responsabilidade_atribuicoes', array_merge($cmds('NEW'), $cmds('OLD')));
                $g += self::gatilho('trg_sl_respa_ad', 'DELETE', 'responsabilidade_atribuicoes', $cmds('OLD'));
            }
        }
        return $g;
    }
}
