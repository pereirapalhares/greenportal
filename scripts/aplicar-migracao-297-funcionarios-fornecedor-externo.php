<?php
declare(strict_types=1);

/*
 * Migração 297 — Homologação de Fornecedores, etapa 4 (continuação): Gestão de Funcionários do fornecedor externo.
 *
 * Em cada banco de cliente (gmodelo_dbp, gatlas_dbp, gmodelo2_dbp se existir) aplica
 * database/297_funcionarios_fornecedor_externo.sql (view vw_homologacao_funcionarios_usuario_grid).
 * O código que acompanha (classes HomologacaoFornecedorAcesso, PermissaoTransacao, GedPermissaoTipo e as telas de
 * funcionários) fecha para os perfis FORNECEDOR / FORNECEDOR_GESTOR as telas só da empresa (ranking, cadastros,
 * histórico, desativados, Portaria) e restringe a Gestão de Funcionários aos fornecedores ligados ao usuário.
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-297-funcionarios-fornecedor-externo.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-297-funcionarios-fornecedor-externo.php
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

define('M297_BANCOS', HomologacaoBancos::lista($argv ?? [])); // classes/HomologacaoBancos.php (--banco= para só alguns)

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$template = (string)file_get_contents(dirname(__DIR__) . '/database/297_funcionarios_fornecedor_externo.sql');
$linhas = static function (PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$valor = static function (PDO $pdo, string $sql, array $p = []) use ($linhas): mixed { $r = $linhas($pdo, $sql, $p); return $r ? array_values($r[0])[0] : null; };

$relatorio = ['ok' => true, 'migracao' => '297', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
foreach (M297_BANCOS as $banco) {
    $info = [];
    if (!$valor($geral, 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$banco])) {
        if ($banco !== 'gmodelo2_dbp') { $relatorio['ok'] = false; $relatorio['bancos'][$banco] = ['erro' => 'banco não existe']; }
        continue;
    }
    try {
        foreach (['vw_homologacao_fornecedores_do_usuario' => 'rode antes a migração 296', 'vw_homologacao_funcionarios_grid' => 'view vw_homologacao_funcionarios_grid não existe'] as $view => $erro)
            if (!$valor($geral, 'SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$banco, $view])) throw new RuntimeException($erro);
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $info['funcionarios_na_tela_da_empresa'] = (int)$valor($pdo, 'SELECT COUNT(*) FROM vw_homologacao_funcionarios_grid');
        if (!$simular) {
            $n = 0;
            foreach (preg_split('/^-- @@\s*$/m', $template) as $bloco) {
                $limpo = trim(preg_replace('/^\s*--.*$/m', '', $bloco) ?? '');
                if ($limpo === '') continue;
                $pdo->exec($limpo); $n++;
            }
            $info['objetos_297'] = $n;
            $info['acessos_funcionario_usuario'] = (int)$valor($pdo, 'SELECT COUNT(*) FROM vw_homologacao_funcionarios_usuario_grid');
            $info['usuarios_externos_com_funcionarios'] = $linhas($pdo, "SELECT p.codigo AS perfil, COUNT(DISTINCT v.CODUSUARIO_ACESSO) AS usuarios, COUNT(*) AS funcionarios
                FROM vw_homologacao_funcionarios_usuario_grid v JOIN usuarios u ON u.codusuario = v.CODUSUARIO_ACESSO JOIN perfis p ON p.codperfil = u.codperfil
                WHERE UPPER(p.codigo) IN ('FORNECEDOR', 'FORNECEDOR_GESTOR') GROUP BY p.codigo");
        }
    } catch (Throwable $e) {
        $relatorio['ok'] = false;
        $info['erro'] = $e->getMessage();
    }
    $relatorio['bancos'][$banco] = $info;
}

$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_297_funcionarios_fornecedor_externo' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
