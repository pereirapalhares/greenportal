<?php
declare(strict_types=1);

/*
 * Migração 403 — Administração › Unidades igual à tela do legado (newlegislacao/configuracoes/mlUnidades.php), 23/11/2026.
 * O legado mostra a coluna "Nome MDB", calculada: <banco do legado>_<descrição com _ no lugar dos espaços>.mdb
 * (ex.: green_gd_americana_Goodyear_Americana.mdb). Esta migração cria, no banco de cada empresa migrada, a view
 * vw_unidades_admin = vw_unidades_grid + NOME_MDB, com o banco do legado de origem da empresa (mesma regra do sincronismo:
 * guniversal_dbp.migracao_solicitacoes.bancos_origem, senão registros_migracao; o primeiro green_* que tenha a tabela unidades).
 * Só leitura no grid; gravação continua na tabela unidades.
 * Uso: php scripts\aplicar-migracao-403-unidades-nome-mdb.php [--aplicar]   (pode rodar mais de uma vez)
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/LegislacaoEmpresas.php';

$aplicar = in_array('--aplicar', $argv ?? [], true);
$rel = ['ok' => true, 'migracao' => '403', 'modo' => $aplicar ? 'APLICAR' : 'SIMULACAO', 'empresas' => []];
try {
    $env = GreenPortalDatabase::env();
    $pdo = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GPT');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
    $existe = static fn(string $db, string $t): bool => (bool)$q('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db, $t]);
    foreach (LegislacaoEmpresas::migradas() as $cod => $emp) {
        $destino = $emp['banco']; $item = ['empresa' => $emp['nome'], 'banco' => $destino];
        try {
            if (!$existe($destino, 'vw_unidades_grid')) { $item['status'] = 'sem vw_unidades_grid'; $rel['empresas'][$cod] = $item; continue; }
            $origens = [];
            try {
                $req = $q('SELECT bancos_origem FROM guniversal_dbp.migracao_solicitacoes WHERE banco_destino = ? ORDER BY codsolicitacao DESC LIMIT 1', [$destino]);
                if ($req) $origens = json_decode((string)$req[0]['bancos_origem'], true) ?: [];
            } catch (Throwable) {}
            if (!$origens) {
                try { foreach ($q("SELECT origem FROM `$destino`.registros_migracao WHERE status = 'CONCLUIDA' AND origem LIKE 'green_%' ORDER BY codregistromigracao DESC") as $r) foreach (preg_split('/[^A-Za-z0-9_]+/', (string)$r['origem']) as $p) if (str_starts_with($p, 'green_')) $origens[] = $p; } catch (Throwable) {}
            }
            $origem = null;
            foreach (array_unique($origens) as $o) if (is_string($o) && preg_match('/^green_[A-Za-z0-9_]+$/', $o) && $existe($o, 'unidades')) { $origem = $o; break; }
            $item['legado'] = $origem ?? '(não encontrado: NOME_MDB fica vazio)';
            $expr = $origem === null ? 'CAST(NULL AS CHAR)' : "CONCAT('" . $origem . "_', REPLACE(u.DSCUNIDADE, ' ', '_'), '.mdb')";
            $sql = "CREATE OR REPLACE VIEW `$destino`.`vw_unidades_admin` AS SELECT u.*, $expr AS NOME_MDB FROM `$destino`.`vw_unidades_grid` u";
            if ($aplicar) { $pdo->exec($sql); $item['status'] = 'vw_unidades_admin criada'; }
            else $item['status'] = 'vw_unidades_admin será criada';
            if ($aplicar) $item['exemplo'] = $q("SELECT DSCUNIDADE, NOME_MDB FROM `$destino`.vw_unidades_admin ORDER BY DSCUNIDADE LIMIT 2");
        } catch (Throwable $e) { $item['erro'] = $e->getMessage(); $rel['ok'] = false; }
        $rel['empresas'][$cod] = $item;
    }
} catch (Throwable $e) { $rel['ok'] = false; $rel['erro'] = $e->getMessage(); }
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
