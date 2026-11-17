<?php
declare(strict_types=1);

/*
 * Diagnóstico (SÓ LEITURA) — quem é usuário de fornecedor em cada banco (20/10/2026).
 * Decisão do usuário: na Crown, o perfil FORNECEDORES é de usuários de fornecedor; GESTORES pode ser de fornecedor ou da Crown.
 * Para cada perfil ativo: usuários, quantos têm fornecedor no cadastro (usuarios.codfornecedor > 0, como o legado marca o
 * usuário de fornecedor), quantos estão ligados a fornecedor em Fornecedor x Usuários (fo_fornecedorxusuario), quantos são
 * responsáveis internos de documentos (fo_fornecedorxcriterios.codusuario) e quantos são responsáveis do fornecedor
 * (codusuario_fornecedor). Mostra também os grupos do legado (<legado>_seguranca.usuariosxgrupos) desses usuários.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-perfis-fornecedor.php [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

try { $alvos = HomologacaoBancos::lista($argv ?? []); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$v = static fn(string $sql, array $p = []) => ($r = $q($sql, $p)) ? array_values($r[0])[0] : null;
$existe = static fn(string $db, string $t): bool => (int)$v('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db, $t]) > 0;
$temColuna = static fn(string $db, string $t, string $c): bool => (int)$v('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND LOWER(COLUMN_NAME) = ?', [$db, $t, $c]) > 0;
$tenta = static function (callable $f) { try { return $f(); } catch (Throwable $e) { return ['erro' => mb_substr($e->getMessage(), 0, 200)]; } };

$saida = ['gerado_em' => date('c'), 'bancos' => []];
foreach ($alvos as $banco) {
    if (!$existe($banco, 'usuarios')) continue;
    $seg = HomologacaoBancos::legado($banco) . '_seguranca';
    $G = "`$banco`";
    $codForn = $temColuna($banco, 'usuarios', 'codfornecedor') ? 'SUM(COALESCE(u.codfornecedor, 0) > 0)' : "'sem a coluna'";
    $codFornLeg = $existe($seg, 'usuarios') && $temColuna($seg, 'usuarios', 'codfornecedor')
        ? "(SELECT COUNT(*) FROM `$seg`.usuarios l WHERE l.codusuario IN (SELECT u2.codusuario FROM $G.usuarios u2 WHERE u2.codperfil <=> p.codperfil AND u2.ativo = 1) AND COALESCE(l.codfornecedor, 0) > 0)" : "'sem a coluna'";
    $r = $tenta(fn() => $q("SELECT COALESCE(NULLIF(TRIM(p.codigo), ''), '(sem perfil)') AS perfil, COUNT(*) AS usuarios_ativos,
            $codForn AS com_fornecedor_no_cadastro,
            $codFornLeg AS com_fornecedor_no_cadastro_legado,
            SUM(EXISTS (SELECT 1 FROM $G.fo_fornecedorxusuario x WHERE x.CODUSUARIO = u.codusuario)) AS ligados_em_fornecedor_x_usuarios,
            SUM(EXISTS (SELECT 1 FROM $G.fo_fornecedorxcriterios d WHERE d.codusuario = u.codusuario)) AS responsaveis_internos_de_documento,
            SUM(EXISTS (SELECT 1 FROM $G.fo_fornecedorxcriterios d WHERE d.codusuario_fornecedor = u.codusuario)) AS responsaveis_do_fornecedor_em_documento
          FROM $G.usuarios u LEFT JOIN $G.perfis p ON p.codperfil = u.codperfil
         WHERE u.ativo = 1 GROUP BY p.codperfil, p.codigo ORDER BY usuarios_ativos DESC"));
    $grupos = 'sem usuariosxgrupos no legado';
    if ($existe($seg, 'usuariosxgrupos') && $existe($seg, 'grupos')) {
        $grupos = $tenta(fn() => $q("SELECT COALESCE(NULLIF(TRIM(p.codigo), ''), '(sem perfil)') AS perfil, g.dscgrupo AS grupo_legado, COUNT(DISTINCT u.codusuario) AS usuarios
              FROM $G.usuarios u LEFT JOIN $G.perfis p ON p.codperfil = u.codperfil
              JOIN `$seg`.usuariosxgrupos ug ON ug.codusuario = u.codusuario JOIN `$seg`.grupos g ON g.codgrupo = ug.codgrupo
             WHERE u.ativo = 1 AND UPPER(COALESCE(p.codigo, '')) IN ('FORNECEDORES', 'GESTORES', 'FORNECEDOR', 'FORNECEDOR_GESTOR')
             GROUP BY 1, 2 ORDER BY 1, usuarios DESC"));
    }
    $saida['bancos'][$banco] = ['por_perfil' => $r, 'grupos_legado_dos_perfis_de_fornecedor' => $grupos];
}
$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'diagnostico_perfis_fornecedor.json', $json);
echo $json . PHP_EOL;
