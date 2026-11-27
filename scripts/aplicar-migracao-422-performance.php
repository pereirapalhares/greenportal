<?php
declare(strict_types=1);

/*
 * Migração 422 — performance dos bancos *_dbp (27/11/2026), a partir do diagnóstico scripts/diagnostico-performance-dbp.php.
 *
 * Em todos os bancos *_dbp (empresas, glegislacao_dbp e guniversal_dbp):
 *   1. Índices que faltam nas colunas de ligação e de filtro mais usadas (só se a tabela e a coluna existem e nenhum índice
 *      começa por ela): usuarios (codusuario, codgrupo, codfornecedor, codunidade), legislacaoxsituacao, legislacaox* dos
 *      correlacionamentos, lpro, situacao_legal_historico, responsabilidade_atribuicoes, fo_funcionarioxtiposxcriterios,
 *      nc_indicacao_ocorrencia, busca_indice, green_service_runs, green_tasks, alertas (usuário + lido), e no glegislacao /
 *      guniversal as tabelas de perguntas, categorias, execuções e traduções.
 *   2. Índices repetidos: apaga o índice NÃO único cujas colunas já são o começo de outro índice da mesma tabela
 *      (nunca apaga chave primária nem índice único; se o MySQL recusar por causa de chave estrangeira, fica).
 *   3. Tabelas em uso ainda MyISAM (fo_regras_documentos e fo_regras_documentos_log) passam para InnoDB.
 *   4. ANALYZE TABLE nas tabelas com mais de 1.000 linhas (estatísticas do otimizador; os planos estimavam milhões de
 *      linhas onde havia milhares).
 * Opcionais:
 *   --limpar-execucoes   apaga de green_service_runs e guniversal.orq_execucoes as execuções com mais de 90 dias (só log).
 *   --arquivar-backups   tabelas de backup das migrações (bkp*, zz_backup_*, *_bkp<n>, *_backup): grava cada uma em
 *                        storage/backups_bd/<banco>/<tabela>_<data>.sql.gz (estrutura + dados) e depois apaga a tabela.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-422-performance.php [--simular] [--banco=gcrown_dbp]
 *      [--limpar-execucoes] [--arquivar-backups]
 * Pode ser executada mais de uma vez. Fora do horário de uso, se possível (criar índice em tabela grande leva alguns segundos).
 */
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

@set_time_limit(0);
ini_set('memory_limit', '1024M');
$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$limpar = in_array('--limpar-execucoes', $args, true);
$arquivar = in_array('--arquivar-backups', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$leg = (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp');
$uni = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$conectar = static function (string $banco) use ($env, $leg, $uni): PDO {
    $p = GreenPortalDatabase::connect($env, $banco, $banco === $leg ? 'GLEGISLACAO' : ($banco === $uni ? 'GUNIVERSAL' : 'GPT'));
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $p;
};
$q = static function (PDO $p, string $sql, array $par = []): array { $s = $p->prepare($sql); $s->execute($par); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };

// tabela => [[colunas], ...]  (nome do índice: ix422_<tabela>_<colunas>)
$INDICES = [
    'usuarios' => [['codusuario'], ['codgrupo'], ['codfornecedor'], ['codunidade'], ['codusuario_superior']],
    'legislacaoxsituacao' => [['codunidade']],
    'legislacaoxaspecto' => [['codaspecto']], 'legislacaoxperigo' => [['codperigo']], 'legislacaoxrisco' => [['codrisco']], 'legislacaoxatividade' => [['codatividade']],
    'legislacaoxpoder' => [['codlegislacao', 'codunidade']],
    'lpro' => [['codatividade'], ['codperigo'], ['codrisco']],
    'situacao_legal_historico' => [['codunidade']],
    'responsabilidade_atribuicoes' => [['registro_id']],
    'fo_funcionarioxtiposxcriterios' => [['codcriteriofuncionario']],
    'fo_fornecedorxcriterios' => [['situacao']],
    'nc_indicacao_ocorrencia' => [['codfornecedor'], ['situacao']],
    'busca_indice' => [['codunidade'], ['codusuario']],
    'green_service_runs' => [['status']],
    'green_tasks' => [['origem_id']],
    'alertas' => [['codusuario', 'lido']],
    'fo_regras_documentos_log' => [['codfornecedor'], ['codregra']],
    // glegislacao_dbp
    'legislacaoxpergunta' => [['codpergunta']], 'legislacaoxcategoria' => [['codcategorialegislacao']], 'legislacaoxassunto' => [['codlegislacao']],
    // guniversal_dbp
    'orq_execucoes' => [['status']], 'idioma_traducoes' => [['codpublicacao']], 'amb_questionario_pergunta' => [['codigo']],
];
$MYISAM_EM_USO = ['fo_regras_documentos', 'fo_regras_documentos_log'];
$ehBackup = static fn(string $t): bool => (bool)preg_match('/^(bkp\d*_|zz_backup_)|_bkp\d*$|_backup$/i', $t);

$rel = ['ok' => true, 'migracao' => '422', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'opcoes' => ['limpar_execucoes' => $limpar, 'arquivar_backups' => $arquivar], 'bancos' => []];
try {
    $base = $conectar($uni);
    $bancos = array_column($q($base, "SELECT SCHEMA_NAME s FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'g%\\_dbp' ORDER BY 1"), 's');
} catch (Throwable $e) { echo json_encode(['ok' => false, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL; exit(1); }

foreach ($bancos as $banco) {
    if ($soBanco !== null && $banco !== $soBanco) continue;
    $info = ['indices_criados' => [], 'indices_apagados' => [], 'innodb' => [], 'analisadas' => 0];
    try {
        $p = $conectar($banco);
        $tabs = [];
        foreach ($q($p, "SELECT TABLE_NAME n, ENGINE e, TABLE_ROWS r FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'") as $t) $tabs[strtolower($t['n'])] = $t;
        $lerIndices = static function () use ($p, $q): array {
            $idx = [];
            foreach ($q($p, "SELECT TABLE_NAME t, INDEX_NAME i, NON_UNIQUE nu, SEQ_IN_INDEX s, LOWER(COLUMN_NAME) c, SUB_PART sp FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() ORDER BY 1, 2, 4") as $r) {
                $idx[strtolower($r['t'])][$r['i']]['unico'] = (int)$r['nu'] === 0;
                $idx[strtolower($r['t'])][$r['i']]['cols'][] = $r['c'] . ($r['sp'] !== null ? '(' . $r['sp'] . ')' : '');
            }
            return $idx;
        };
        $idx = $lerIndices();
        $colunas = [];
        foreach ($q($p, "SELECT LOWER(TABLE_NAME) t, LOWER(COLUMN_NAME) c, DATA_TYPE d FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()") as $r) $colunas[$r['t']][$r['c']] = strtolower((string)$r['d']);

        // 3. MyISAM em uso → InnoDB (antes dos índices)
        foreach ($MYISAM_EM_USO as $t) {
            if (!isset($tabs[$t]) || strcasecmp((string)$tabs[$t]['e'], 'MyISAM') !== 0) continue;
            if (!$simular) $p->exec('ALTER TABLE `' . $tabs[$t]['n'] . '` ENGINE=InnoDB');
            $info['innodb'][] = $tabs[$t]['n'];
        }
        // 1. índices que faltam
        foreach ($INDICES as $t => $lista) {
            if (!isset($tabs[$t])) continue;
            foreach ($lista as $cols) {
                if (array_diff($cols, array_keys($colunas[$t] ?? []))) continue;
                if (array_intersect(array_map(static fn($c) => $colunas[$t][$c], $cols), ['text', 'mediumtext', 'longtext', 'blob', 'json'])) continue;
                $tem = false;
                foreach ($idx[$t] ?? [] as $i) if (array_slice(array_map(static fn($c) => preg_replace('/\(\d+\)$/', '', $c), $i['cols']), 0, count($cols)) === $cols) { $tem = true; break; }
                if ($tem) continue;
                $nome = substr('ix422_' . $t . '_' . implode('_', $cols), 0, 64);
                if (!$simular) {
                    try { $p->exec('ALTER TABLE `' . $tabs[$t]['n'] . '` ADD INDEX `' . $nome . '` (' . implode(', ', array_map(static fn($c) => '`' . $c . '`', $cols)) . ')'); }
                    catch (Throwable $e) { $info['avisos'][] = $nome . ': ' . mb_substr($e->getMessage(), 0, 140); continue; }
                }
                $info['indices_criados'][] = $tabs[$t]['n'] . '(' . implode(',', $cols) . ')';
            }
        }
        // 2. índices repetidos (não únicos, começo de outro índice)
        $idx = $lerIndices();
        foreach ($idx as $t => $lista) {
            if ($ehBackup($t) && $arquivar) continue;   // a tabela vai sair
            $apagados = [];
            foreach ($lista as $nome => $i) {
                if ($nome === 'PRIMARY' || $i['unico']) continue;
                foreach ($lista as $outro => $j) {
                    if ($outro === $nome || isset($apagados[$outro])) continue;
                    $n = count($i['cols']);
                    if (count($j['cols']) < $n || array_slice($j['cols'], 0, $n) !== $i['cols']) continue;
                    if (count($j['cols']) === $n && !$j['unico'] && $outro !== 'PRIMARY' && strcmp($nome, $outro) < 0) continue; // iguais e não únicos: fica o de nome menor
                    if (!$simular) {
                        try { $p->exec('ALTER TABLE `' . $tabs[$t]['n'] . '` DROP INDEX `' . $nome . '`'); }
                        catch (Throwable $e) { $info['avisos'][] = 'manteve ' . $t . '.' . $nome . ': ' . mb_substr($e->getMessage(), 0, 120); break; }
                    }
                    $apagados[$nome] = true;
                    $info['indices_apagados'][] = $tabs[$t]['n'] . '.' . $nome . ' (' . implode(',', $i['cols']) . ') — coberto por ' . $outro;
                    break;
                }
            }
        }
        // opcional: execuções antigas (log de serviço)
        if ($limpar) {
            foreach (['green_service_runs' => ['started_at', 'iniciado_em', 'inicio', 'created_at', 'criado_em'], 'orq_execucoes' => ['inicio', 'iniciado_em', 'started_at', 'criado_em', 'created_at']] as $t => $possiveis) {
                if (!isset($tabs[$t])) continue;
                $col = current(array_filter($possiveis, static fn($c) => isset($colunas[$t][$c]))) ?: null;
                if (!$col) { $info['avisos'][] = "$t: sem coluna de data conhecida, não limpa"; continue; }
                $n = (int)$q($p, "SELECT COUNT(*) n FROM `{$tabs[$t]['n']}` WHERE `$col` < DATE_SUB(NOW(), INTERVAL 90 DAY)")[0]['n'];
                if (!$simular && $n > 0) { do { $s = $p->exec("DELETE FROM `{$tabs[$t]['n']}` WHERE `$col` < DATE_SUB(NOW(), INTERVAL 90 DAY) LIMIT 20000"); } while ($s >= 20000); }
                $info['execucoes_apagadas'][$t] = $n;
            }
        }
        // opcional: arquivar e apagar as tabelas de backup das migrações
        if ($arquivar) {
            $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups_bd' . DIRECTORY_SEPARATOR . $banco;
            foreach ($tabs as $t => $tab) {
                if (!$ehBackup($t)) continue;
                $arq = $dir . DIRECTORY_SEPARATOR . $tab['n'] . '_' . date('Ymd') . '.sql.gz';
                if ($simular) { $info['backups_arquivados'][] = $tab['n'] . ' (~' . (int)$tab['r'] . ' linhas) → ' . basename($arq); continue; }
                if (!is_dir($dir)) mkdir($dir, 0775, true);
                $gz = gzopen($arq, 'wb6');
                gzwrite($gz, "-- Backup da tabela {$tab['n']} ($banco), migração 422, " . date('c') . "\nSET NAMES utf8mb4;\n");
                gzwrite($gz, ($q($p, 'SHOW CREATE TABLE `' . $tab['n'] . '`')[0]['Create Table'] ?? '') . ";\n");
                $linhas = 0;
                $leitura = $conectar($banco);   // conexão própria, sem buffer: a tabela pode ter milhões de linhas
                $leitura->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                $s = $leitura->query('SELECT * FROM `' . $tab['n'] . '`', PDO::FETCH_ASSOC);
                $lote = [];
                foreach ($s as $r) {
                    $lote[] = '(' . implode(',', array_map(static fn($v) => $v === null ? 'NULL' : $p->quote((string)$v), $r)) . ')';
                    $linhas++;
                    if (count($lote) >= 500) { gzwrite($gz, 'INSERT INTO `' . $tab['n'] . '` VALUES ' . implode(",\n", $lote) . ";\n"); $lote = []; }
                }
                if ($lote) gzwrite($gz, 'INSERT INTO `' . $tab['n'] . '` VALUES ' . implode(",\n", $lote) . ";\n");
                $s->closeCursor(); $leitura = null; gzclose($gz);
                $conferido = (int)$q($p, 'SELECT COUNT(*) n FROM `' . $tab['n'] . '`')[0]['n'];
                if ($conferido !== $linhas || !is_file($arq) || filesize($arq) < 50) { $info['avisos'][] = $tab['n'] . ': backup não conferiu, tabela mantida'; continue; }
                $p->exec('DROP TABLE `' . $tab['n'] . '`');
                $info['backups_arquivados'][] = $tab['n'] . " ($linhas linhas) → " . basename($arq);
                unset($tabs[$t]);
            }
        }
        // 4. estatísticas
        foreach ($tabs as $t => $tab) {
            if ((int)$tab['r'] < 1000 || $ehBackup($t)) continue;
            if (!$simular) { try { $q($p, 'ANALYZE TABLE `' . $tab['n'] . '`'); } catch (Throwable) {} }
            $info['analisadas']++;
        }
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = mb_substr($e->getMessage(), 0, 300); }
    $rel['bancos'][$banco] = $info;
}
$rel['depois'] = 'Rode de novo scripts\\diagnostico-performance-dbp.php para comparar. Configuração do MySQL (my.ini) não é alterada por esta migração.';
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
