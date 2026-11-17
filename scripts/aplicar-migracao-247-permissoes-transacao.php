<?php
declare(strict_types=1);

/*
 * Migração 247 — libera (ou retira) as ações de uma transação para perfis informados, em todos os clientes.
 * É o mesmo que marcar as caixas em Segurança › Permissões, perfil por perfil. Grava em permissoes e perfil_menus.
 * Perfis que não existem num cliente são ignorados (aparecem no relatório). ADMINISTRADOR já tem acesso por regra.
 *
 * Uso (padrão: Critérios dos funcionários para ADM_DA_EMPRESA e HOMOLOGACAO, acesso completo):
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-247-permissoes-transacao.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-247-permissoes-transacao.php
 * Opções: --transacao=HOMOLOGACAO_CRITERIOS_FUNCIONARIOS  --perfis=ADM_DA_EMPRESA,HOMOLOGACAO
 *         --acoes=visualizar,incluir,editar,excluir,exportar[,pdf,total,arquivos,importar]   --banco=gatlas_dbp (só esses clientes; vários separados por vírgula)   --remover
 * Os usuários veem a mudança no próximo login.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$opcao = static function (string $nome, string $padrao) use ($argv): string {
    foreach ($argv ?? [] as $a) if (str_starts_with($a, "--$nome=")) return trim(substr($a, strlen($nome) + 3));
    return $padrao;
};
$simular = in_array('--simular', $argv ?? [], true);
$remover = in_array('--remover', $argv ?? [], true);
$transacao = strtoupper($opcao('transacao', 'HOMOLOGACAO_CRITERIOS_FUNCIONARIOS'));
$perfis = array_values(array_filter(array_map(static fn($p) => strtoupper(trim($p)), explode(',', $opcao('perfis', 'ADM_DA_EMPRESA,HOMOLOGACAO')))));
$mapa = ['visualizar' => 'pode_visualizar', 'incluir' => 'pode_incluir', 'editar' => 'pode_editar', 'excluir' => 'pode_excluir', 'exportar' => 'pode_exportar_excel', 'pdf' => 'pode_gerar_pdf', 'total' => 'pode_visualizar_total', 'arquivos' => 'pode_visualizar_arquivos', 'importar' => 'pode_importar_arquivos'];
$acoes = array_values(array_intersect_key($mapa, array_flip(array_map('trim', explode(',', strtolower($opcao('acoes', 'visualizar,incluir,editar,excluir,exportar')))))));
$somenteBanco = $opcao('banco', '');
if (!preg_match('/^[A-Z0-9_]+$/', $transacao) || !$perfis || !$acoes) { fwrite(STDERR, "Parâmetros inválidos.\n"); exit(1); }
if ($remover) $acoes = array_values($mapa); // retirar = tudo zerado

$env = GreenPortalDatabase::env();
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'perfis', 'permissoes') AND TABLE_SCHEMA LIKE '%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 3 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
if ($somenteBanco !== '') $bancos = array_values(array_intersect($bancos, array_map('trim', explode(',', strtolower($somenteBanco))))); // um ou vários, separados por vírgula

$relatorio = ['ok' => true, 'migracao' => '247', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'acao' => $remover ? 'RETIRAR ACESSO' : 'LIBERAR',
    'transacao' => $transacao, 'acoes' => $remover ? 'todas = 0' : $acoes, 'bancos' => []];
foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $q = $pdo->prepare('SELECT codmenu FROM menus WHERE codigo = ?'); $q->execute([$transacao]);
        $codmenu = (int)$q->fetchColumn();
        if ($codmenu < 1) { $info['aviso'] = "transação $transacao não existe no menu deste cliente"; $relatorio['bancos'][$banco] = $info; continue; }
        $tabelas = [];
        foreach (['permissoes', 'perfil_menus'] as $t) {
            $q = $pdo->prepare('SELECT LOWER(COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'); $q->execute([$t]);
            $cols = $q->fetchAll(PDO::FETCH_COLUMN);
            if ($cols) $tabelas[$t] = $cols;
        }
        $buscaPerfil = $pdo->prepare('SELECT codperfil, codigo FROM perfis WHERE UPPER(codigo) = ?');
        foreach ($perfis as $perfil) {
            $buscaPerfil->execute([$perfil]);
            $p = $buscaPerfil->fetch(PDO::FETCH_ASSOC);
            if (!$p) { $info[$perfil] = 'não existe neste cliente'; continue; }
            if ($perfil === 'ADMINISTRADOR') { $info[$perfil] = 'tem acesso por regra do sistema'; continue; }
            $estado = [];
            foreach ($tabelas as $t => $cols) {
                $flags = [];
                foreach (array_values($mapa) as $c) if (in_array($c, $cols, true)) $flags[$c] = $remover ? 0 : (in_array($c, $acoes, true) ? 1 : null);
                $flags = array_filter($flags, static fn($v) => $v !== null);
                $q = $pdo->prepare("SELECT * FROM `$t` WHERE codperfil = ? AND codmenu = ?"); $q->execute([(int)$p['codperfil'], $codmenu]);
                $atual = $q->fetch(PDO::FETCH_ASSOC);
                if (!$atual) {
                    $estado[$t] = 'INCLUIDA';
                    if (!$simular) {
                        $campos = ['codperfil', 'codmenu', ...array_keys($flags)];
                        $pdo->prepare("INSERT INTO `$t` (`" . implode('`, `', $campos) . '`) VALUES (' . implode(', ', array_fill(0, count($campos), '?')) . ')')
                            ->execute([(int)$p['codperfil'], $codmenu, ...array_values($flags)]);
                    }
                } else {
                    $muda = array_filter($flags, static fn($v, $c) => (int)($atual[$c] ?? 0) !== $v, ARRAY_FILTER_USE_BOTH);
                    $estado[$t] = $muda ? 'ALTERADA (' . implode(', ', array_keys($muda)) . ')' : 'JA ESTAVA ASSIM';
                    if ($muda && !$simular) {
                        $pdo->prepare("UPDATE `$t` SET " . implode(', ', array_map(static fn($c) => "`$c` = ?", array_keys($muda))) . ' WHERE codperfil = ? AND codmenu = ?')
                            ->execute([...array_values($muda), (int)$p['codperfil'], $codmenu]);
                    }
                }
            }
            $info[$perfil] = $estado;
        }
        $relatorio['bancos'][$banco] = ['codmenu' => $codmenu] + $info;
    } catch (Throwable $e) {
        $relatorio['ok'] = false;
        $relatorio['bancos'][$banco] = ['erro' => $e->getMessage()];
    }
}
$relatorio['observacao'] = 'Os usuários desses perfis veem a mudança no próximo login.';
echo json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
