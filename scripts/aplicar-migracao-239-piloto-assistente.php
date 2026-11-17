<?php
declare(strict_types=1);

/*
 * Migração 239 — piloto do assistente de IA ("Como posso ajudar?") para mais perfis de UM cliente.
 * Marca Visualizar = Sim na transação ASSISTENTE_IA (Segurança › Permissões) para os perfis informados.
 * Não altera outros perfis, outras transações nem outros clientes. É o mesmo que marcar a caixa em
 * Segurança › Permissões, perfil por perfil. Para desfazer, desmarque Visualizar na tela ou rode com --remover.
 * Os usuários passam a ver o ícone no próximo login.
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-239-piloto-assistente.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-239-piloto-assistente.php
 * Opções: --banco=gatlas_dbp (padrão)  --perfis=GESTOR,EMITENTE,REVISOR,APROVADOR (padrão)  --remover  --simular
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$opcao = static function (string $nome, string $padrao) use ($argv): string {
    foreach ($argv ?? [] as $a) if (str_starts_with($a, "--$nome=")) return trim(substr($a, strlen($nome) + 3));
    return $padrao;
};
$simular = in_array('--simular', $argv ?? [], true);
$remover = in_array('--remover', $argv ?? [], true);
$banco = $opcao('banco', 'gatlas_dbp');
$perfis = array_values(array_filter(array_map(static fn(string $p): string => strtoupper(trim($p)), explode(',', $opcao('perfis', 'GESTOR,EMITENTE,REVISOR,APROVADOR')))));
$codigo = 'ASSISTENTE_IA';

if (!preg_match('/^[A-Za-z0-9_]+_dbp$/', $banco)) { fwrite(STDERR, "Banco inválido: $banco\n"); exit(1); }
if (!$perfis) { fwrite(STDERR, "Informe --perfis=...\n"); exit(1); }

$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
$colunas = static function (string $tabela) use ($pdo): array {
    $q = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $q->execute([$tabela]);
    return array_map('strtolower', $q->fetchAll(PDO::FETCH_COLUMN));
};

$resultado = ['ok' => true, 'migracao' => '239', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'acao' => $remover ? 'REMOVER ACESSO' : 'LIBERAR ACESSO', 'banco' => $banco, 'perfis' => []];

$q = $pdo->prepare('SELECT codmenu FROM menus WHERE codigo = ?');
$q->execute([$codigo]);
$codmenu = (int)$q->fetchColumn();
if ($codmenu < 1) { fwrite(STDERR, "Transação $codigo não encontrada em $banco.menus. Rode antes a migração 236.\n"); exit(1); }
$resultado['transacao'] = ['codigo' => $codigo, 'codmenu' => $codmenu];

$controle = (string)$pdo->query("SELECT valor FROM ia_assistente_config WHERE chave = 'controle_acesso'")->fetchColumn();
if (strtoupper($controle) !== 'PERMISSOES') $resultado['aviso'] = 'ia_assistente_config.controle_acesso não é PERMISSOES; a liberação só vale depois da migração 236.';

$colsUsuarios = $colunas('usuarios');
$contaUsuarios = in_array('codperfil', $colsUsuarios, true)
    ? $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE codperfil = ?' . (in_array('ativo', $colsUsuarios, true) ? ' AND ativo = 1' : ''))
    : null;
$tabelas = array_values(array_filter(['permissoes', 'perfil_menus'], static fn(string $t): bool => (bool)$colunas($t)));
$valor = $remover ? 0 : 1;

$buscaPerfil = $pdo->prepare('SELECT codperfil, codigo FROM perfis WHERE UPPER(codigo) = ?');
foreach ($perfis as $perfil) {
    $buscaPerfil->execute([$perfil]);
    $p = $buscaPerfil->fetch(PDO::FETCH_ASSOC);
    if (!$p) { $resultado['perfis'][$perfil] = 'NÃO ENCONTRADO (nada feito)'; continue; }
    if (strtoupper((string)$p['codigo']) === 'ADMINISTRADOR') { $resultado['perfis'][$perfil] = 'ADMINISTRADOR sempre tem acesso (nada feito)'; continue; }
    $codperfil = (int)$p['codperfil'];
    $info = ['codperfil' => $codperfil];
    if ($contaUsuarios) { $contaUsuarios->execute([$codperfil]); $info['usuarios'] = (int)$contaUsuarios->fetchColumn(); }
    try {
        foreach ($tabelas as $tabela) {
            $q = $pdo->prepare("SELECT pode_visualizar FROM `$tabela` WHERE codperfil = ? AND codmenu = ?");
            $q->execute([$codperfil, $codmenu]);
            $atual = $q->fetchColumn();
            if ($atual === false) {
                $estado = 'INCLUIDA';
                if (!$simular) {
                    $flags = array_intersect_key(['pode_visualizar' => $valor, 'pode_incluir' => 0, 'pode_editar' => 0, 'pode_excluir' => 0, 'pode_exportar_excel' => 0, 'pode_gerar_pdf' => 0], array_flip($colunas($tabela)));
                    $campos = ['codperfil', 'codmenu', ...array_keys($flags)];
                    $pdo->prepare("INSERT INTO `$tabela` (`" . implode('`, `', $campos) . '`) VALUES (' . implode(', ', array_fill(0, count($campos), '?')) . ')')
                        ->execute([$codperfil, $codmenu, ...array_values($flags)]);
                }
            } elseif ((int)$atual === $valor) {
                $estado = 'JA ESTAVA ' . ($valor ? 'LIBERADO' : 'SEM ACESSO');
            } else {
                $estado = $valor ? 'LIBERADO' : 'ACESSO REMOVIDO';
                if (!$simular) $pdo->prepare("UPDATE `$tabela` SET pode_visualizar = ? WHERE codperfil = ? AND codmenu = ?")->execute([$valor, $codperfil, $codmenu]);
            }
            $info[$tabela] = $estado;
        }
    } catch (Throwable $e) {
        $resultado['ok'] = false;
        $info['erro'] = $e->getMessage();
    }
    $resultado['perfis'][(string)$p['codigo']] = $info;
}

if (!$simular && $colunas('registros_migracao')) {
    $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
        VALUES ('GREEN_ASSISTENTE_PILOTO_239', 'perfis', 'permissoes', 'CONFIGURACAO', '4.0.1', 'CONCLUIDA', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
        ON DUPLICATE KEY UPDATE status = VALUES(status), concluida_em = VALUES(concluida_em), quantidade_registros = VALUES(quantidade_registros), detalhes = VALUES(detalhes)")
        ->execute([count($resultado['perfis']), json_encode($resultado, JSON_UNESCAPED_UNICODE)]);
}
$resultado['observacao'] = 'Os usuários desses perfis veem o ícone "Como posso ajudar?" no próximo login. Limite: ' . ((string)$pdo->query("SELECT valor FROM ia_assistente_config WHERE chave = 'limite_diario'")->fetchColumn() ?: '?') . ' perguntas por usuário por dia.';

echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($resultado['ok'] ? 0 : 1);
