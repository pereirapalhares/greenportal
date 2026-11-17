<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura): perfis de um cliente, quantos usuários ativos/inativos cada um tem
 * e se o perfil já tem acesso ao assistente de IA (transação ASSISTENTE_IA, Visualizar).
 * Nada é alterado. Ajuda a escolher os perfis do piloto (migração 239 com --perfis=...).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-perfis-assistente.php [--banco=gatlas_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$banco = 'gatlas_dbp';
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--banco=')) $banco = trim(substr($a, 8));
if (!preg_match('/^[A-Za-z0-9_]+_dbp$/', $banco)) { fwrite(STDERR, "Banco inválido: $banco\n"); exit(1); }

$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
$colunas = static function (string $tabela) use ($pdo): array {
    $q = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $q->execute([$tabela]);
    return array_map('strtolower', $q->fetchAll(PDO::FETCH_COLUMN));
};

$cols = $colunas('usuarios');
if (!in_array('codperfil', $cols, true)) { fwrite(STDERR, "usuarios.codperfil não existe em $banco\n"); exit(1); }
$ativo = in_array('ativo', $cols, true) ? 'SUM(u.ativo = 1)' : 'COUNT(u.codperfil)';
$inativo = in_array('ativo', $cols, true) ? 'SUM(u.ativo <> 1 OR u.ativo IS NULL)' : '0';
$descricao = in_array('descricao', $colunas('perfis'), true) ? 'p.descricao' : "''";

$codmenu = (int)$pdo->query("SELECT codmenu FROM menus WHERE codigo = 'ASSISTENTE_IA'")->fetchColumn();
$sql = "SELECT p.codperfil, p.codigo, $descricao AS descricao,
               COALESCE($ativo, 0) AS usuarios_ativos, COALESCE($inativo, 0) AS usuarios_inativos,
               COALESCE((SELECT pm.pode_visualizar FROM permissoes pm WHERE pm.codperfil = p.codperfil AND pm.codmenu = :m), 0) AS assistente
        FROM perfis p LEFT JOIN usuarios u ON u.codperfil = p.codperfil
        GROUP BY p.codperfil, p.codigo
        ORDER BY usuarios_ativos DESC, p.codigo";
$q = $pdo->prepare($sql);
$q->execute([':m' => $codmenu]);
$perfis = [];
$totais = ['perfis' => 0, 'perfis_com_usuarios_ativos' => 0, 'usuarios_ativos' => 0, 'usuarios_ativos_com_assistente' => 0];
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $totais['perfis']++;
    $ativos = (int)$p['usuarios_ativos'];
    $totais['usuarios_ativos'] += $ativos;
    $tem = strtoupper((string)$p['codigo']) === 'ADMINISTRADOR' || (int)$p['assistente'] === 1;
    if ($tem) $totais['usuarios_ativos_com_assistente'] += $ativos;
    if ($ativos === 0) continue; // perfis sem usuário ativo não entram na lista
    $totais['perfis_com_usuarios_ativos']++;
    $perfis[] = sprintf('%-28s ativos=%-4d inativos=%-4d assistente=%s%s', (string)$p['codigo'], $ativos, (int)$p['usuarios_inativos'], $tem ? 'SIM' : 'nao', trim((string)$p['descricao']) !== '' ? '  (' . trim((string)$p['descricao']) . ')' : '');
}
$semPerfil = (int)$pdo->query('SELECT COUNT(*) FROM usuarios WHERE codperfil IS NULL OR codperfil = 0' . (in_array('ativo', $cols, true) ? ' AND ativo = 1' : ''))->fetchColumn();

echo json_encode(['banco' => $banco, 'totais' => $totais + ['usuarios_ativos_sem_perfil' => $semPerfil], 'perfis_com_usuarios_ativos' => $perfis], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
