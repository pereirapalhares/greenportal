<?php
declare(strict_types=1);

/*
 * Migração 317 — Homologação de Fornecedores: cronograma da auditoria de homologação (20/10/2026).
 * Legado: aba Auditoria → link "Cronograma" (tipos com Necessita auditoria = Sim) → cadastros/ev_schedule_c.php?codauditoria=
 * <codfornecedorxtipofornecedores>; eventos em ev_eventos (codauditoria, categoria 2 = Auditoria, facilidades necessárias,
 * auditores, check list). Greenportal: coluna Cronograma na aba Auditoria → principal/eventos.php?codauditoria=N.
 *
 * Em cada banco de cliente da homologação:
 *  1. ev_eventos: cria onde não existe (estrutura do legado, sem copiar dados) e acrescenta as colunas da auditoria que faltarem;
 *  2. vw_fo_cronograma_checklists: check lists do legado (pa_checklist) quando o banco tem a tabela; senão, view vazia;
 *  3. views e procedimentos de database/317_cronograma_auditoria_homologacao.sql.
 * Não mexe em eventos existentes. Pode ser executada mais de uma vez. --simular só mostra o que faria.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-317-cronograma-auditoria-homologacao.php [--simular] [--banco=gatlas_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$simular = in_array('--simular', $argv ?? [], true);
try { $alvos = HomologacaoBancos::lista($argv ?? []); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
$env = GreenPortalDatabase::env();
$is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$is->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$valor = static function (PDO $pdo, string $sql, array $p = []): mixed { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchColumn(); $s->closeCursor(); return $r; };
$tabela = static fn(string $db, string $t): bool => (int)$valor($is, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'", [$db, $t]) > 0;
$coluna = static fn(string $db, string $t, string $c): bool => (int)$valor($is, 'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND LOWER(COLUMN_NAME) = ?', [$db, $t, $c]) > 0;

$blocos = [];
foreach (preg_split('/^-- @@\s*$/m', (string)file_get_contents(dirname(__DIR__) . '/database/317_cronograma_auditoria_homologacao.sql')) as $b) {
    $limpo = trim(preg_replace('/^\s*--.*$/m', '', $b) ?? '');
    if ($limpo !== '') $blocos[] = $limpo;
}
if (count($blocos) < 10) { fwrite(STDERR, "database/317_cronograma_auditoria_homologacao.sql incompleto.\n"); exit(1); }

// Estrutura do legado (green_modelo.ev_eventos), usada quando o banco não tem ev_eventos nem o legado de origem tem a tabela.
const M317_EV_EVENTOS = "CREATE TABLE IF NOT EXISTS `ev_eventos` (
  `codevento` INT NOT NULL AUTO_INCREMENT, `codcategoria` INT NULL, `codusuario` INT NULL, `dscevento` VARCHAR(1000) NULL,
  `dsclocalisacao` VARCHAR(200) NULL, `lembrar` INT NULL, `enviaremail` VARCHAR(1) NULL, `datainicial` DATETIME NULL, `datafim` DATETIME NULL,
  `codunidade` INT NULL, `codusuario_envolvidos` VARCHAR(2000) NULL, `codauditoria` INT NULL, `codmonitoramento` INT NULL,
  `auditoria_facilidades_necessarias` TEXT NULL, `codusuarios_auditores` VARCHAR(2000) NULL, `codchecklists` VARCHAR(2000) NULL,
  PRIMARY KEY (`codevento`), KEY `ix_ev_eventos_auditoria` (`codauditoria`), KEY `ix_ev_eventos_monitoramento` (`codmonitoramento`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";
const M317_COLUNAS = ['codauditoria' => 'INT NULL', 'auditoria_facilidades_necessarias' => 'TEXT NULL', 'codusuarios_auditores' => 'VARCHAR(2000) NULL', 'codchecklists' => 'VARCHAR(2000) NULL'];

$rel = ['ok' => true, 'migracao' => '317', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
foreach ($alvos as $banco) {
    if (!(int)$valor($is, 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$banco])) {
        if ($banco !== 'gmodelo2_dbp') { $rel['ok'] = false; $rel['bancos'][$banco] = ['erro' => 'banco não existe']; }
        continue;
    }
    $info = [];
    try {
        foreach (['fo_fornecedores', 'fo_fornecedoresxtipofornecedores', 'fo_tipofornecedores', 'usuarios'] as $t)
            if (!$tabela($banco, $t)) throw new RuntimeException("tabela $t não existe");
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $leg = (string)HomologacaoBancos::legado($banco);

        // 1. ev_eventos
        if (!$tabela($banco, 'ev_eventos')) {
            $info['ev_eventos'] = $simular ? 'seria criada (vazia, estrutura do legado)' : 'criada (vazia, estrutura do legado)';
            if (!$simular) {
                if ($tabela($leg, 'ev_eventos')) {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS `ev_eventos` LIKE `$leg`.`ev_eventos`");
                    $pdo->exec('ALTER TABLE `ev_eventos` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci, ENGINE = InnoDB');
                } else $pdo->exec(M317_EV_EVENTOS);
            }
        } else $info['ev_eventos'] = 'já existe (' . (int)$valor($pdo, 'SELECT COUNT(*) FROM ev_eventos') . ' eventos)';
        $faltam = [];
        foreach (M317_COLUNAS as $c => $def) if (!$tabela($banco, 'ev_eventos') || !$coluna($banco, 'ev_eventos', $c)) $faltam[$c] = $def;
        if ($tabela($banco, 'ev_eventos') && $faltam) {
            $info['colunas_acrescentadas'] = array_keys($faltam);
            if (!$simular) foreach ($faltam as $c => $def) $pdo->exec("ALTER TABLE `ev_eventos` ADD COLUMN `$c` $def");
        }

        // 2. check lists
        $temChecklist = $tabela($banco, 'pa_checklist') && $coluna($banco, 'pa_checklist', 'codchecklist') && $coluna($banco, 'pa_checklist', 'requisito');
        $info['check_list'] = $temChecklist ? 'pa_checklist (' . (int)$valor($pdo, 'SELECT COUNT(*) FROM pa_checklist') . ' itens)' : 'sem pa_checklist: campo Check list não aparece';
        if (!$simular) $pdo->exec($temChecklist
            ? 'CREATE OR REPLACE VIEW `vw_fo_cronograma_checklists` AS SELECT c.codchecklist AS CODCHECKLIST, c.requisito AS REQUISITO FROM `pa_checklist` c'
            : 'CREATE OR REPLACE VIEW `vw_fo_cronograma_checklists` AS SELECT CAST(NULL AS SIGNED) AS CODCHECKLIST, CAST(NULL AS CHAR(255)) AS REQUISITO FROM DUAL WHERE 1 = 0');

        // 3. views e procedimentos
        if (!$simular) {
            foreach ($blocos as $b) $pdo->exec($b);
            $info['estrutura'] = 'views e procedimentos gravados';
            $info['eventos_de_auditoria'] = (int)$valor($pdo, 'SELECT COUNT(*) FROM vw_fo_auditoria_cronograma');
        }
        $info['auditorias_que_exigem_cronograma'] = (int)$valor($pdo, "SELECT COUNT(*) FROM fo_fornecedoresxtipofornecedores ft JOIN fo_tipofornecedores t ON t.codtipofornecedor = ft.codtipofornecedor WHERE t.necessita_auditoria = 'Sim'");
        $rel['bancos'][$banco] = $info;
    } catch (Throwable $e) { $rel['ok'] = false; $rel['bancos'][$banco] = $info + ['erro' => $e->getMessage()]; }
}

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_317_cronograma_auditoria' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
