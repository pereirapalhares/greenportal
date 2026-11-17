<?php
declare(strict_types=1);

/*
 * Avaliação da eficácia nos planos de ação (migração 362; GER-5 do 01_Mudancas_Gerais: "incluir em todos os planos de ação uma
 * flag no final da tabela para indicar se é ou não necessário avaliar a eficácia do plano de ação"). A NC já tinha
 * ("Passar pela análise da eficácia?", por ocorrência); aqui entram os planos de ação das Condicionantes de licenças
 * (li_planodeacao) e dos Fornecedores (fo_planodeacao).
 *
 * Decisões do usuário (16/11/2026):
 *   - a marcação fica em CADA AÇÃO (colunas no fim da grade do plano);
 *   - ação marcada que chega a 100% vira PENDÊNCIA "Avaliar eficácia" com alerta; quem avalia registra Eficaz ou Não eficaz,
 *     data e observação; Não eficaz REABRE a ação;
 *   - quem avalia é ESCOLHIDO NA AÇÃO (campo Avaliador); vazio = o Responsável do registro (da condicionante / do fornecedor);
 *     sem ninguém, o responsável da ação.
 *
 * Colunas novas em cada tabela: AVALIAR_EFICACIA (0/1), CODAVALIADOR, EFICACIA ('EFICAZ' | 'NAO_EFICAZ'), EFICACIA_DATA,
 * EFICACIA_OBS, EFICACIA_CODUSUARIO (quem registrou).
 * Gatilhos trg_eficacia_<tabela>_bi/_bu (regras no banco, valem para a grade, a IA e qualquer outra gravação):
 *   - inclusão (e duplicar): sem resultado de eficácia;
 *   - resultado só com "Avaliar eficácia?" = Sim e a ação a 100%, e só pelo avaliador (ou ADMINISTRADOR / ADM DA EMPRESA;
 *     gravação fora do portal passa); grava quem e quando, e uma linha em plano_eficacia_historico;
 *   - Não eficaz: a ação volta para 75% (reaberta), o resultado continua aparecendo;
 *   - ação reaberta que volta a 100%: o resultado anterior sai e a avaliação fica pendente de novo;
 *   - durante o sincronismo com o sistema antigo (@gp_sinc_legado = 1) os gatilhos não fazem nada.
 * Views: vw_eficacia_planos (todas as ações marcadas, com avaliador efetivo e situação), vw_eficacia_pendentes (a avaliar,
 * por CODUSUARIO = avaliador; Minhas pendências, sino, Minhas ações e alertas) e as views das grades com as colunas novas
 * (a definição anterior vira <view>_base362).
 */
final class EficaciaPlanos
{
    public const COLLATE = 'utf8mb4_unicode_ci';
    public const COLUNAS = [
        'AVALIAR_EFICACIA' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'CODAVALIADOR' => 'BIGINT NULL',
        'EFICACIA' => 'VARCHAR(12) NULL',
        'EFICACIA_DATA' => 'DATE NULL',
        'EFICACIA_OBS' => 'VARCHAR(1000) NULL',
        'EFICACIA_CODUSUARIO' => 'BIGINT NULL',
    ];

    /** módulo => tabela, chave, origem (coluna / tabela / chave / responsável), view da grade, página do plano, menu, ajuda. */
    public const PLANOS = [
        'CONDICIONANTE' => [
            'nome' => ['pt' => 'Condicionantes de licenças', 'en' => 'License conditions', 'es' => 'Condicionantes de licencias'],
            'tabela' => 'li_planodeacao', 'chave' => 'CODPLANOACAO', 'origem' => 'CODCONDICIONANTE',
            'tabela_origem' => 'li_condicionantes', 'chave_origem' => 'CODCONDICIONANTE', 'responsavel_origem' => 'CODUSUARIO',
            'grid' => 'vw_licencas_condicionantes_planodeacao_grid', 'pagina' => 'manutencaolegal_licencas_condicionantesplanodeacao.php',
            'menu' => 'LICENCAS', 'transacao' => 'liPlanodeAcao',
        ],
        'FORNECEDOR' => [
            'nome' => ['pt' => 'Fornecedores', 'en' => 'Suppliers', 'es' => 'Proveedores'],
            'tabela' => 'fo_planodeacao', 'chave' => 'CODPLANOACAO', 'origem' => 'CODFORNECEDOR',
            'tabela_origem' => 'fo_fornecedores', 'chave_origem' => 'CODFORNECEDOR', 'responsavel_origem' => 'CODUSUARIO_RESPONSAVEL_FORNECEDOR',
            'grid' => 'vw_homologacao_fornecedor_planos_grid', 'pagina' => 'homologacaofornecedor_fornecedorxplanodeacao.php',
            'menu' => 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR', 'transacao' => 'homologacaofornecedor_fornecedorxplanodeacao',
        ],
    ];

    private static array $cache = [];

    public static function colunas(PDO $pdo, string $t, bool $recarregar = false): array
    {
        $k = spl_object_id($pdo) . ':' . strtolower($t);
        if (!$recarregar && isset(self::$cache[$k])) return self::$cache[$k];
        $s = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $s->execute([$t]);
        $r = [];
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $c) $r[strtolower((string)$c)] = (string)$c;
        $s->closeCursor();
        return self::$cache[$k] = $r;
    }

    public static function limparCache(): void { self::$cache = []; }

    public static function existe(PDO $pdo, string $t): bool { return self::colunas($pdo, $t) !== []; }

    private static function q(string $c): string { return '`' . str_replace('`', '', $c) . '`'; }

    private static function txt(string $expr, int $n = 400): string
    {
        return "CONVERT(LEFT(COALESCE($expr, ''), $n) USING utf8mb4) COLLATE " . self::COLLATE;
    }

    /** Coluna real (nome como está no banco) ou null. */
    private static function col(PDO $pdo, string $t, string $c): ?string
    {
        return self::colunas($pdo, $t)[strtolower($c)] ?? null;
    }

    /** Planos que existem no banco: módulo => 'ok' ou motivo. */
    public static function planos(PDO $pdo): array
    {
        $r = [];
        foreach (self::PLANOS as $m => $p) {
            if (!self::col($pdo, $p['tabela'], $p['chave'])) { $r[$m] = 'sem a tabela ' . $p['tabela']; continue; }
            if (!self::col($pdo, $p['tabela'], 'STATUS')) { $r[$m] = 'sem a coluna STATUS em ' . $p['tabela']; continue; }
            $r[$m] = 'ok';
        }
        return $r;
    }

    /** ALTER TABLE para as colunas que faltam (ou null). */
    public static function alterTabela(PDO $pdo, string $modulo): ?string
    {
        $p = self::PLANOS[$modulo];
        $falta = [];
        foreach (self::COLUNAS as $c => $def) if (!self::col($pdo, $p['tabela'], $c)) $falta[] = 'ADD COLUMN ' . self::q($c) . ' ' . $def;
        return $falta ? 'ALTER TABLE ' . self::q($p['tabela']) . ' ' . implode(', ', $falta) : null;
    }

    /** Avaliador efetivo (expressão SQL com o alias da linha do plano). */
    private static function avaliador(PDO $pdo, string $modulo, string $a): string
    {
        $p = self::PLANOS[$modulo];
        $resp = self::col($pdo, $p['tabela_origem'], $p['responsavel_origem']);
        $chO = self::col($pdo, $p['tabela_origem'], $p['chave_origem']);
        $orig = self::col($pdo, $p['tabela'], $p['origem']);
        $doRegistro = ($resp && $chO && $orig) ? '(SELECT NULLIF(o.' . self::q($resp) . ', 0) FROM ' . self::q($p['tabela_origem']) . ' o WHERE o.' . self::q($chO) . " = $a." . self::q($orig) . ' LIMIT 1)' : 'NULL';
        $acao = self::col($pdo, $p['tabela'], 'CODUSUARIO');
        return "COALESCE(NULLIF($a.`CODAVALIADOR`, 0), $doRegistro" . ($acao ? ", NULLIF($a." . self::q($acao) . ', 0)' : '') . ')';
    }

    /** Gatilhos (DROP + CREATE) do módulo. */
    public static function gatilhos(PDO $pdo, string $modulo): array
    {
        $p = self::PLANOS[$modulo];
        $t = $p['tabela'];
        $st = self::q((string)self::col($pdo, $t, 'STATUS'));
        $pct = static fn(string $x): string => "CAST(COALESCE($x.$st, 0) AS SIGNED)";
        $hist = "INSERT INTO `plano_eficacia_historico` (`MODULO`, `CODPLANOACAO`, `RESULTADO`, `DATA_AVALIACAO`, `OBSERVACAO`, `CODUSUARIO`)
            VALUES ('$modulo', NEW." . self::q($p['chave']) . ', NEW.`EFICACIA`, NEW.`EFICACIA_DATA`, NEW.`EFICACIA_OBS`, @gp_usuario)';
        $pf = self::colunas($pdo, 'perfis');
        $adm = (isset($pf['codperfil'], $pf['codigo']) && isset(self::colunas($pdo, 'usuarios')['codperfil']))
            ? "EXISTS (SELECT 1 FROM `usuarios` u JOIN `perfis` pf ON pf.`codperfil` = u.`codperfil` WHERE u.`codusuario` = @gp_usuario
                 AND UPPER(REPLACE(TRIM(pf.`codigo`), ' ', '_')) IN ('ADMINISTRADOR', 'ADM_DA_EMPRESA'))" : '0';
        $bi = "trg_eficacia_{$t}_bi"; $bu = "trg_eficacia_{$t}_bu";
        return [
            "DROP TRIGGER IF EXISTS `$bi`",
            "CREATE TRIGGER `$bi` BEFORE INSERT ON `$t` FOR EACH ROW
BEGIN
    -- Migração 362 (GER-5): ação nova (ou duplicada) começa sem resultado de eficácia; avaliador 0 = sem avaliador
    SET NEW.`CODAVALIADOR` = NULLIF(NEW.`CODAVALIADOR`, 0);
    IF COALESCE(@gp_sinc_legado, 0) <> 1 THEN
        SET NEW.`AVALIAR_EFICACIA` = IF(COALESCE(NEW.`AVALIAR_EFICACIA`, 0) = 1, 1, 0);
        SET NEW.`EFICACIA` = NULL, NEW.`EFICACIA_DATA` = NULL, NEW.`EFICACIA_OBS` = NULL, NEW.`EFICACIA_CODUSUARIO` = NULL;
    END IF;
END",
            "DROP TRIGGER IF EXISTS `$bu`",
            "CREATE TRIGGER `$bu` BEFORE UPDATE ON `$t` FOR EACH ROW
BEGIN
    -- Migração 362 (GER-5): avaliação da eficácia da ação (ver classes/EficaciaPlanos.php)
    DECLARE v_avaliador BIGINT DEFAULT NULL;
    -- avaliador 0 = sem avaliador (a grade barra a referência 0 como inexistente)
    SET NEW.`CODAVALIADOR` = NULLIF(NEW.`CODAVALIADOR`, 0);
    IF COALESCE(@gp_sinc_legado, 0) <> 1 THEN
        SET NEW.`AVALIAR_EFICACIA` = IF(COALESCE(NEW.`AVALIAR_EFICACIA`, 0) = 1, 1, 0);
        SET NEW.`EFICACIA` = NULLIF(UPPER(TRIM(NEW.`EFICACIA`)), '');
        -- data vazia da grade chega como 0000-00-00 (CRUD do legado sem modo estrito): trata como sem data
        IF NEW.`EFICACIA_DATA` < '1000-01-01' THEN SET NEW.`EFICACIA_DATA` = NULL; END IF;
        IF NEW.`EFICACIA` IS NOT NULL AND NEW.`EFICACIA` NOT IN ('EFICAZ', 'NAO_EFICAZ') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Eficácia inválida: use Eficaz ou Não eficaz.';
        END IF;
        IF NOT (NEW.`EFICACIA` <=> OLD.`EFICACIA`) AND NEW.`EFICACIA` IS NOT NULL THEN
            IF NEW.`AVALIAR_EFICACIA` <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Marque \"Avaliar eficácia?\" = Sim para registrar a eficácia.';
            END IF;
            IF " . $pct('NEW') . " < 100 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A eficácia só é avaliada com a ação a 100%.';
            END IF;
            SET v_avaliador = " . self::avaliador($pdo, $modulo, 'NEW') . ";
            IF @gp_usuario IS NOT NULL AND v_avaliador IS NOT NULL AND @gp_usuario <> v_avaliador AND NOT $adm THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Só o avaliador da ação registra a eficácia.';
            END IF;
            SET NEW.`EFICACIA_CODUSUARIO` = @gp_usuario;
            IF NEW.`EFICACIA_DATA` IS NULL THEN SET NEW.`EFICACIA_DATA` = CURDATE(); END IF;
            $hist;
            IF NEW.`EFICACIA` = 'NAO_EFICAZ' THEN SET NEW.$st = '75'; END IF;
        ELSEIF NEW.`EFICACIA` IS NULL AND OLD.`EFICACIA` IS NOT NULL AND NEW.`EFICACIA_CODUSUARIO` <=> OLD.`EFICACIA_CODUSUARIO` THEN
            -- resultado apagado à mão: limpa quem/quando
            SET NEW.`EFICACIA_DATA` = NULL, NEW.`EFICACIA_CODUSUARIO` = NULL;
        ELSEIF " . $pct('OLD') . " < 100 AND " . $pct('NEW') . " >= 100 AND NEW.`EFICACIA` IS NOT NULL THEN
            -- ação reaberta voltou a 100%: nova avaliação
            SET NEW.`EFICACIA` = NULL, NEW.`EFICACIA_DATA` = NULL, NEW.`EFICACIA_OBS` = NULL, NEW.`EFICACIA_CODUSUARIO` = NULL;
        END IF;
    END IF;
END",
        ];
    }

    /** Remover: gatilhos. */
    public static function gatilhosRemover(string $modulo): array
    {
        $t = self::PLANOS[$modulo]['tabela'];
        return ["DROP TRIGGER IF EXISTS `trg_eficacia_{$t}_bi`", "DROP TRIGGER IF EXISTS `trg_eficacia_{$t}_bu`"];
    }

    /** vw_eficacia_planos + vw_eficacia_pendentes (só com os módulos do banco). */
    public static function views(PDO $pdo, array $modulos): array
    {
        $partes = [];
        foreach ($modulos as $m) {
            $p = self::PLANOS[$m];
            $t = $p['tabela'];
            $ch = self::q((string)self::col($pdo, $t, $p['chave']));
            $or = self::col($pdo, $t, $p['origem']);
            $acao = self::col($pdo, $t, 'ACAO');
            $resp = self::col($pdo, $t, 'CODUSUARIO');
            $df = self::col($pdo, $t, 'DATAFINAL');
            if ($m === 'CONDICIONANTE') {
                $temLic = self::existe($pdo, 'li_licencas') && self::existe($pdo, 'li_condicionantes');
                $origem = $temLic ? "LEFT(CONCAT(COALESCE(l.`DSCLICENCA`, CONCAT('Licença ', c.`CODLICENCA`)), ' › ', COALESCE(c.`DSCCONDICIONANTE`, '')), 400)" : "CONCAT('Condicionante ', p.`CODCONDICIONANTE`)";
                $de = $temLic ? ' LEFT JOIN `li_condicionantes` c ON c.`CODCONDICIONANTE` = p.`CODCONDICIONANTE` LEFT JOIN `li_licencas` l ON l.`CODLICENCA` = c.`CODLICENCA`' : '';
            } else {
                $f = self::colunas($pdo, 'fo_fornecedores');
                $nomes = array_values(array_filter([isset($f['nomefantasia']) ? "NULLIF(TRIM(f.`nomefantasia`), '')" : null, isset($f['razaosocial']) ? "NULLIF(TRIM(f.`razaosocial`), '')" : null]));
                $origem = $f ? 'COALESCE(' . implode(', ', $nomes ?: ['NULL']) . ", CONCAT('Fornecedor ', p.`CODFORNECEDOR`))" : "CONCAT('Fornecedor ', p.`CODFORNECEDOR`)";
                $de = $f ? ' LEFT JOIN `fo_fornecedores` f ON f.`codfornecedor` = p.`CODFORNECEDOR`' : '';
            }
            $partes[] = "SELECT CONVERT('$m' USING utf8mb4) COLLATE " . self::COLLATE . " AS `MODULO`, CAST(p.$ch AS SIGNED) AS `CODPLANOACAO`, "
                . ($or ? 'CAST(p.' . self::q($or) . ' AS SIGNED)' : 'NULL') . ' AS `CODORIGEM`, ' . self::txt($origem) . ' AS `ORIGEM`, '
                . self::txt($acao ? 'p.' . self::q($acao) : "''", 1000) . ' AS `ACAO`, ' . ($df ? 'p.' . self::q($df) : 'NULL') . ' AS `DATAFINAL`, '
                . 'CAST(COALESCE(p.`STATUS`, 0) AS SIGNED) AS `STATUS`, ' . ($resp ? 'CAST(p.' . self::q($resp) . ' AS SIGNED)' : 'NULL') . ' AS `CODRESPONSAVEL`, '
                . 'CAST(' . self::avaliador($pdo, $m, 'p') . ' AS SIGNED) AS `CODAVALIADOR_EFETIVO`, p.`EFICACIA`, p.`EFICACIA_DATA`, '
                . '(SELECT COUNT(*) FROM `plano_eficacia_historico` h WHERE h.`MODULO` = ' . "'$m'" . ' AND h.`CODPLANOACAO` = p.' . $ch . ') AS `AVALIACOES`'
                . " FROM `$t` p$de WHERE p.`AVALIAR_EFICACIA` = 1";
        }
        $v = [];
        $v['vw_eficacia_planos'] = 'CREATE OR REPLACE VIEW `vw_eficacia_planos` AS ' . ($partes ? implode("\nUNION ALL\n", $partes)
            : "SELECT CONVERT('' USING utf8mb4) COLLATE " . self::COLLATE . " AS `MODULO`, 0 AS `CODPLANOACAO`, 0 AS `CODORIGEM`, '' AS `ORIGEM`, '' AS `ACAO`, NULL AS `DATAFINAL`, 0 AS `STATUS`, NULL AS `CODRESPONSAVEL`, NULL AS `CODAVALIADOR_EFETIVO`, NULL AS `EFICACIA`, NULL AS `EFICACIA_DATA`, 0 AS `AVALIACOES` FROM DUAL WHERE 1 = 0");
        // a avaliar: marcada, a 100% e sem resultado; CODUSUARIO = avaliador (filtro exato das telas Minhas)
        $v['vw_eficacia_pendentes'] = "CREATE OR REPLACE VIEW `vw_eficacia_pendentes` AS
SELECT e.`MODULO`, e.`CODPLANOACAO`, e.`CODORIGEM`, e.`ORIGEM`, e.`ACAO`, e.`DATAFINAL`, e.`STATUS`, e.`CODRESPONSAVEL`, e.`AVALIACOES`,
       e.`CODAVALIADOR_EFETIVO` AS `CODUSUARIO`
  FROM `vw_eficacia_planos` e
 WHERE e.`STATUS` >= 100 AND e.`EFICACIA` IS NULL AND e.`CODAVALIADOR_EFETIVO` IS NOT NULL";
        return $v;
    }

    /** Situação da eficácia (texto para a grade). */
    private static function situacao(string $st): string
    {
        return "CASE WHEN COALESCE(p.`AVALIAR_EFICACIA`, 0) <> 1 THEN ''
            WHEN p.`EFICACIA` = 'EFICAZ' THEN CONCAT('Eficaz', IF(p.`EFICACIA_DATA` IS NULL, '', CONCAT(' em ', DATE_FORMAT(p.`EFICACIA_DATA`, '%d/%m/%Y'))))
            WHEN p.`EFICACIA` = 'NAO_EFICAZ' THEN CONCAT('Não eficaz', IF(p.`EFICACIA_DATA` IS NULL, '', CONCAT(' em ', DATE_FORMAT(p.`EFICACIA_DATA`, '%d/%m/%Y'))), ': ação reaberta')
            WHEN CAST(COALESCE(p.$st, 0) AS SIGNED) >= 100 THEN 'Avaliar eficácia'
            ELSE 'Aguardando a ação chegar a 100%' END";
    }

    /**
     * View da grade com as colunas novas: a definição anterior vira <grid>_base362 (uma vez) e a grade = base + colunas.
     * @return array{0: ?array<string>, 1: string} [comandos, situação]
     */
    public static function viewGrade(PDO $pdo, string $modulo, string $logDir, string $banco): array
    {
        $p = self::PLANOS[$modulo];
        $g = $p['grid']; $base = $g . '_base362';
        if (!self::existe($pdo, $g)) return [null, 'sem a view ' . $g . ' (a grade usa a tabela)'];
        $corpo = null;
        if (self::existe($pdo, $base)) $corpo = null; // já aplicada: só refaz a grade em cima da base
        else {
            $def = (string)($pdo->query('SHOW CREATE VIEW ' . self::q($g))->fetch(PDO::FETCH_ASSOC)['Create View'] ?? '');
            if (!preg_match('/\sVIEW\s+`[^`]+`\s+AS\s+(.*)$/is', $def, $m)) return [null, 'NÃO alterada: definição em formato inesperado'];
            $corpo = trim($m[1]);
            @file_put_contents($logDir . DIRECTORY_SEPARATOR . "migracao_362_{$g}_anterior_$banco.sql", 'CREATE OR REPLACE VIEW `' . $g . '` AS ' . $corpo . ";\n");
        }
        $ch = (string)self::col($pdo, $p['tabela'], $p['chave']);
        $st = self::q((string)self::col($pdo, $p['tabela'], 'STATUS'));
        $novas = ['avaliar_eficacia', 'codavaliador', 'avaliador', 'eficacia', 'eficacia_data', 'eficacia_obs', 'eficacia_codusuario', 'eficacia_situacao'];
        $cmd = [];
        if ($corpo !== null) $cmd[] = 'CREATE OR REPLACE VIEW ' . self::q($base) . ' AS ' . $corpo;
        // colunas da base (sem as novas, caso a base já tenha alguma) + as novas
        $cmd[] = ['__GRADE__', $g, $base, $ch, $novas, "SELECT __BASE_COLS__, p.`AVALIAR_EFICACIA`, NULLIF(p.`CODAVALIADOR`, 0) AS `CODAVALIADOR`, " . self::txt('ua.`nome`', 200) . " AS `AVALIADOR`,
       p.`EFICACIA`, p.`EFICACIA_DATA`, p.`EFICACIA_OBS`, p.`EFICACIA_CODUSUARIO`, " . self::txt(self::situacao($st), 200) . " AS `EFICACIA_SITUACAO`
  FROM " . self::q($base) . " b
  LEFT JOIN " . self::q($p['tabela']) . " p ON p." . self::q($ch) . " = b.__CHAVE__
  LEFT JOIN `usuarios` ua ON ua.`codusuario` = p.`CODAVALIADOR`"];
        return [$cmd, $corpo === null ? 'refeita (base já existia)' : 'ok: definição anterior em ' . $base . ' e storage/logs'];
    }

    /** Executa os comandos de viewGrade (o da grade depende das colunas da base, lidas depois de criá-la). */
    public static function aplicarGrade(PDO $pdo, array $cmds): void
    {
        foreach ($cmds as $c) {
            if (is_string($c)) { $pdo->exec($c); self::limparCache(); continue; }
            [, $g, $base, $ch, $novas, $sql] = $c;
            $cols = self::colunas($pdo, $base, true);
            if (!isset($cols[strtolower($ch)])) throw new RuntimeException("a view $base não tem a coluna $ch");
            $lista = [];
            foreach ($cols as $k => $real) if (!in_array($k, $novas, true)) $lista[] = 'b.' . self::q($real);
            $pdo->exec('CREATE OR REPLACE VIEW ' . self::q($g) . ' AS ' . str_replace(['__BASE_COLS__', '__CHAVE__'], [implode(', ', $lista), self::q($cols[strtolower($ch)])], $sql));
            self::limparCache();
        }
    }

    /** --remover: grade volta à definição anterior. */
    public static function gradeRestaurar(PDO $pdo, string $modulo): string
    {
        $g = self::PLANOS[$modulo]['grid']; $base = $g . '_base362';
        if (!self::existe($pdo, $base)) return 'nada a restaurar';
        $def = (string)($pdo->query('SHOW CREATE VIEW ' . self::q($base))->fetch(PDO::FETCH_ASSOC)['Create View'] ?? '');
        if (!preg_match('/\sVIEW\s+`[^`]+`\s+AS\s+(.*)$/is', $def, $m)) return 'NÃO restaurada (formato inesperado)';
        $pdo->exec('CREATE OR REPLACE VIEW ' . self::q($g) . ' AS ' . trim($m[1]));
        $pdo->exec('DROP VIEW IF EXISTS ' . self::q($base));
        self::limparCache();
        return 'restaurada';
    }

    /**
     * Campos da grade do plano (no fim): só quando a 362 está aplicada no banco da sessão (a view da grade tem as colunas novas);
     * sem ela, [] e a grade fica como estava.
     */
    public static function camposGrade(string $tabela, string $view): array
    {
        try {
            if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
            $banco = (string)($_SESSION['tenant']['banco_dados'] ?? '');
            if ($banco === '' || !preg_match('/^[A-Za-z0-9_]+$/', $banco)) return [];
            require_once __DIR__ . '/GreenPortalDatabase.php';
            $pdo = GreenPortalDatabase::connect(GreenPortalDatabase::env(), $banco, 'GPT');
            if (!isset(self::colunas($pdo, $view)['eficacia_situacao'])) return [];
        } catch (Throwable) { return []; }
        $l = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];
        return [
            'AVALIAR_EFICACIA' => ['label' => $l('Avaliar eficácia?', 'Review effectiveness?', '¿Evaluar eficacia?'), 'filter' => 'select', 'width' => 110,
                'options' => ['0' => $l('Não', 'No', 'No'), '1' => $l('Sim', 'Yes', 'Sí')]],
            'CODAVALIADOR' => ['label' => $l('Avaliador', 'Reviewer', 'Evaluador'), 'filter' => 'select', 'width' => 180,
                'foreign' => ['view' => 'vw_usuarios_grid', 'value' => 'codusuario', 'label' => 'nome', 'filters' => ['ativo' => 1], 'description' => 'nome']],
            'EFICACIA' => ['label' => $l('Eficácia', 'Effectiveness', 'Eficacia'), 'filter' => 'select', 'width' => 120,
                'options' => ['' => '', 'EFICAZ' => $l('Eficaz', 'Effective', 'Eficaz'), 'NAO_EFICAZ' => $l('Não eficaz', 'Not effective', 'No eficaz')]],
            'EFICACIA_DATA' => ['label' => $l('Data da avaliação', 'Review date', 'Fecha de la evaluación'), 'type' => 'date', 'filter' => 'text', 'width' => 130],
            'EFICACIA_OBS' => ['label' => $l('Observação da avaliação', 'Review note', 'Observación de la evaluación'), 'type' => 'textarea', 'filter' => 'text', 'width' => 240],
            'EFICACIA_SITUACAO' => ['label' => $l('Situação da eficácia', 'Effectiveness status', 'Situación de la eficacia'), 'readonly' => true, 'filter' => 'text', 'width' => 200],
        ];
    }

    /** Pendências do avaliador (Minhas pendências / Minhas ações): linhas de vw_eficacia_pendentes do usuário. */
    public static function pendentes(PDO $pdo, int $uid): array
    {
        if ($uid <= 0 || !self::existe($pdo, 'vw_eficacia_pendentes')) return [];
        $s = $pdo->prepare('CALL sp_crud_select(?, ?, NULL, 1000, 0)');
        $s->execute(['vw_eficacia_pendentes', json_encode(['CODUSUARIO' => ['value' => (string)$uid, 'exact' => true]])]);
        $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
        return array_values(array_filter($r, static fn($x) => (int)($x['CODUSUARIO'] ?? 0) === $uid));
    }

    /** URL do plano de ação (relativa a principal/). */
    public static function url(string $modulo, int $origem, int $plano = 0): string
    {
        $p = self::PLANOS[$modulo] ?? null;
        if (!$p || $origem <= 0) return '';
        $f = [$p['origem'] => (string)$origem];
        return $p['pagina'] . '?filtros=' . rawurlencode((string)json_encode($f, JSON_UNESCAPED_UNICODE));
    }
}
