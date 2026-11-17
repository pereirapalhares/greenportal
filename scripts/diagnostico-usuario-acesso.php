<?php
declare(strict_types=1);

/*
 * Diagnóstico (SÓ LEITURA) — o que um usuário vê na Homologação de Fornecedores, no greenportal e pela regra do legado (20/10/2026).
 * Greenportal: perfil, modo de acesso (vw_homologacao_usuario_acesso: 0 vê tudo, 1 externo, 2 interno restrito), unidades
 * (vw_portaria_usuario_unidades) e fornecedores ativos que ele vê (vw_homologacao_fornecedores_unidade_grid / _usuario_grid).
 * Legado: grupos (<legado>_seguranca), unidades (usuariosxunidades) e fornecedores ativos das unidades dele
 * (regra de configuracoes/fo_fornecedor.php para quem não é administrador).
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-usuario-acesso.php --banco=gcrown_dbp --userid=nathalia.capacla
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$banco = ''; $userid = '';
foreach ($argv ?? [] as $a) {
    if (preg_match('/^--banco=(g[a-z0-9_]+_dbp)$/', $a, $m)) $banco = $m[1];
    if (preg_match('/^--userid=([A-Za-z0-9._@\-]{1,80})$/', $a, $m)) $userid = $m[1];
}
if ($banco === '' || $userid === '' || HomologacaoBancos::legado($banco) === null) { fwrite(STDERR, "Informe --banco=g..._dbp (da homologação) e --userid=login\n"); exit(1); }
$leg = (string)HomologacaoBancos::legado($banco); $seg = $leg . '_seguranca';
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
$q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$v = static fn(string $sql, array $p = []) => ($r = $q($sql, $p)) ? array_values($r[0])[0] : null;
$tenta = static function (callable $f) { try { return $f(); } catch (Throwable $e) { return ['erro' => mb_substr($e->getMessage(), 0, 200)]; } };

$u = $q('SELECT u.codusuario, u.nome, u.ativo, p.codigo AS perfil FROM usuarios u LEFT JOIN perfis p ON p.codperfil = u.codperfil WHERE u.userid = ?', [$userid])[0] ?? null;
if (!$u) { fwrite(STDERR, "Usuário $userid não encontrado em $banco\n"); exit(1); }
$cod = (int)$u['codusuario'];
$ativo = "LOWER(TRIM(COALESCE(f.ativadofor, ''))) IN ('sim', 's', '1')";
$r = ['banco' => $banco, 'usuario' => ['codusuario' => $cod, 'nome' => $u['nome'], 'perfil' => $u['perfil'], 'ativo' => (int)$u['ativo']]];
$r['greenportal'] = [
    'modo' => $tenta(fn() => $q('SELECT PERFIL, MODO FROM vw_homologacao_usuario_acesso WHERE CODUSUARIO = ?', [$cod])[0] ?? null),
    'unidades' => $tenta(fn() => array_column($q('SELECT x.CODUNIDADE, un.dscunidade FROM vw_portaria_usuario_unidades x LEFT JOIN unidades un ON un.codunidade = x.CODUNIDADE WHERE x.CODUSUARIO = ? ORDER BY 2', [$cod]), 'dscunidade', 'CODUNIDADE')),
    'fornecedores_ativos_total' => (int)$v("SELECT COUNT(*) FROM fo_fornecedores f WHERE $ativo"),
    'fornecedores_ativos_que_ve_restrito' => $tenta(fn() => (int)$v('SELECT COUNT(DISTINCT CODFORNECEDOR) FROM vw_homologacao_fornecedores_unidade_grid WHERE CODUSUARIO_ACESSO = ?', [$cod])),
    'fornecedores_ativos_das_unidades' => $tenta(fn() => (int)$v("SELECT COUNT(*) FROM fo_fornecedores f WHERE $ativo AND f.codunidade IN (SELECT CODUNIDADE FROM vw_portaria_usuario_unidades WHERE CODUSUARIO = ?)", [$cod])),
];
$r['legado'] = [
    'grupos' => $tenta(fn() => array_column($q("SELECT g.dscgrupo FROM `$seg`.usuariosxgrupos ug JOIN `$seg`.grupos g ON g.codgrupo = ug.codgrupo WHERE ug.codusuario = ?", [$cod]), 'dscgrupo')),
    'unidades' => $tenta(fn() => (int)$v("SELECT COUNT(DISTINCT codunidade) FROM `$seg`.usuariosxunidades WHERE codusuario = ?", [$cod])),
    'fornecedores_ativos_das_unidades' => $tenta(fn() => (int)$v("SELECT COUNT(*) FROM `$leg`.fo_fornecedores f WHERE f.ativadofor = 'Sim' AND f.codunidade IN (SELECT codunidade FROM `$seg`.usuariosxunidades WHERE codusuario = ?)", [$cod])),
    'fornecedores_ativos_total' => $tenta(fn() => (int)$v("SELECT COUNT(*) FROM `$leg`.fo_fornecedores f WHERE f.ativadofor = 'Sim'")),
    'obs' => 'no legado, quem tem o grupo ADMINISTRADOR vê todos; os demais, só os fornecedores ativos das suas unidades',
];
echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
