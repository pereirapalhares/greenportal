<?php
declare(strict_types=1);

/*
 * Migração 319 — Homologação de Fornecedores: perfil FORNECEDORES (Crown) tratado como fornecedor externo (20/10/2026).
 * Decisão do usuário: na Crown, FORNECEDORES são usuários de fornecedor (diagnostico-perfis-fornecedor.php: 691 de 692 ligados
 * em Fornecedor x Usuários e no grupo FORNECEDORES do legado); GESTORES continuam internos (restrição por unidade, 316).
 * PHP: HomologacaoFornecedorAcesso::PERFIS_EQUIVALENTES e UsuarioSessao. Banco: recria vw_homologacao_usuario_acesso
 * (database/316_restricao_unidade_homologacao.sql), usada sem sessão pelo envio semanal dos relatórios.
 * Só view (não mexe em dados). Pode ser executada mais de uma vez.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-319-perfil-fornecedores.php [--simular] [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$simular = in_array('--simular', $argv ?? [], true);
try { $alvos = HomologacaoBancos::lista($argv ?? []); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
$env = GreenPortalDatabase::env();
$view = null;
foreach (preg_split('/^-- @@\s*$/m', (string)file_get_contents(dirname(__DIR__) . '/database/316_restricao_unidade_homologacao.sql')) as $b) {
    $limpo = trim(preg_replace('/^\s*--.*$/m', '', $b) ?? '');
    if (str_contains($limpo, 'VIEW `vw_homologacao_usuario_acesso`')) $view = $limpo;
}
if ($view === null || !str_contains($view, "'FORNECEDORES'")) { fwrite(STDERR, "database/316 não está na versão da migração 319.\n"); exit(1); }

$rel = ['ok' => true, 'migracao' => '319', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
foreach ($alvos as $banco) {
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $n = (int)$pdo->query("SELECT COUNT(*) FROM usuarios u JOIN perfis p ON p.codperfil = u.codperfil WHERE u.ativo = 1 AND UPPER(TRIM(p.codigo)) = 'FORNECEDORES'")->fetchColumn();
        if (!$simular) $pdo->exec($view);
        $info = ['usuarios_ativos_FORNECEDORES' => $n, 'view' => $simular ? 'seria recriada' : 'recriada'];
        if (!$simular) $info['usuarios_ativos_por_modo'] = $pdo->query("SELECT CASE a.MODO WHEN 0 THEN 'vê tudo' WHEN 1 THEN 'fornecedor externo' ELSE 'interno restrito' END AS modo, COUNT(*) AS n
            FROM vw_homologacao_usuario_acesso a JOIN usuarios u ON u.codusuario = a.CODUSUARIO WHERE u.ativo = 1 GROUP BY a.MODO ORDER BY a.MODO")->fetchAll(PDO::FETCH_KEY_PAIR);
        $rel['bancos'][$banco] = $info;
    } catch (Throwable $e) { $rel['ok'] = false; $rel['bancos'][$banco] = ['erro' => $e->getMessage()]; }
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
