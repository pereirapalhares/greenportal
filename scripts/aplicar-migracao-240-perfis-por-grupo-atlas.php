<?php
declare(strict_types=1);

/*
 * Migração 240 — troca os perfis técnicos ATLAS_GRUPO_<código> (criados na migração do legado) por perfis
 * com o nome do grupo do usuário (ex.: ATLAS_GRUPO_3 "USUARIOS" -> perfil USUARIOS) e elimina os ATLAS_GRUPO_*.
 *
 * Regras:
 *  1. Para cada perfil ATLAS_GRUPO_N, o nome do grupo N (tabela grupos) vira o código do perfil:
 *     maiúsculas, sem acento, espaços e hífens viram "_" (ADM DA EMPRESA -> ADM_DA_EMPRESA).
 *     - Se ainda não existe perfil com esse código: o ATLAS_GRUPO_N é RENOMEADO (mesmo codperfil, mesmas
 *       permissões; os usuários não perdem nenhum acesso).
 *     - Se já existe (ADMINISTRADOR, ADM_DA_EMPRESA, FORNECEDOR, HOMOLOGACAO...): os usuários vão para o
 *       perfil existente, que mantém as permissões dele; o ATLAS_GRUPO_N é EXCLUÍDO. A simulação mostra as
 *       telas que esses usuários ganham ou perdem.
 *  2. Usuário que está num perfil ATLAS_GRUPO_*: vai para o perfil do SEU grupo (usuarios.codgrupo).
 *     Sem grupo, ou grupo sem perfil correspondente: vai para o perfil que substitui o ATLAS_GRUPO_N atual.
 *  3. Não mexe em: perfis ATLAS_USUARIO_* (acesso somado de quem tinha vários grupos), usuários sem perfil,
 *     usuários em outros perfis. Eles só aparecem no relatório.
 *  4. Antes de alterar, copia perfis, permissoes, perfil_menus, permissoes_campos, usuarios (codusuario, codperfil, codgrupo) e
 *     atlas_perfis_legado / atlas_usuarios_perfis_legado para tabelas bkp240_*. Tudo numa transação.
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-240-perfis-por-grupo-atlas.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-240-perfis-por-grupo-atlas.php
 * Opção: --banco=gatlas_dbp (padrão). Os usuários afetados precisam entrar de novo no sistema.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$simular = in_array('--simular', $argv ?? [], true);
$banco = 'gatlas_dbp';
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--banco=')) $banco = trim(substr($a, 8));
if (!preg_match('/^[A-Za-z0-9_]+_dbp$/', $banco)) { fwrite(STDERR, "Banco inválido: $banco\n"); exit(1); }

$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$colunas = static function (string $tabela) use ($pdo): array {
    $q = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $q->execute([$tabela]);
    return array_map('strtolower', $q->fetchAll(PDO::FETCH_COLUMN));
};
$primeira = static fn(array $cols, array $nomes): ?string => array_values(array_intersect($nomes, $cols))[0] ?? null;
$normalizar = static function (string $nome): string {
    $s = mb_strtoupper(trim($nome), 'UTF-8');
    if (class_exists('Normalizer')) $s = preg_replace('/\p{Mn}+/u', '', Normalizer::normalize($s, Normalizer::FORM_D)) ?? $s;
    $s = strtr($s, ['Ç' => 'C', 'Ã' => 'A', 'Õ' => 'O', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Â' => 'A', 'Ê' => 'E', 'Ô' => 'O', 'À' => 'A']);
    return trim(preg_replace('/_+/', '_', preg_replace('/[^A-Z0-9]+/', '_', $s) ?? '') ?? '', '_');
};

// Estrutura
$colsUsuarios = $colunas('usuarios');
foreach (['codusuario', 'codperfil', 'codgrupo'] as $c) if (!in_array($c, $colsUsuarios, true)) { fwrite(STDERR, "usuarios.$c não existe em $banco\n"); exit(1); }
$colsGrupos = $colunas('grupos');
$grupoId = $primeira($colsGrupos, ['codgrupo', 'codigo', 'id']);
$grupoNome = $primeira($colsGrupos, ['nome', 'descricao', 'dscgrupo', 'grupo']);
if (!$grupoId || !$grupoNome) { fwrite(STDERR, "Tabela grupos sem código/nome reconhecível em $banco\n"); exit(1); }
$colsPerfis = $colunas('perfis');
$usuarioAtivo = in_array('ativo', $colsUsuarios, true) ? 'u.ativo' : '1';
// Permissões do próprio perfil (por tela e por campo): num caso UNIR são apagadas com o perfil antigo,
// nunca transferidas, para não alterar as permissões do perfil oficial.
$tabelasPermissao = array_values(array_filter(['permissoes', 'perfil_menus', 'permissoes_campos'], static fn(string $t): bool => in_array('codperfil', $colunas($t), true)));

$grupos = [];
foreach ($pdo->query("SELECT `$grupoId` id, `$grupoNome` nome FROM grupos") as $g) $grupos[(int)$g['id']] = trim((string)$g['nome']);
$perfisPorCodigo = [];
$perfisPorId = [];
foreach ($pdo->query('SELECT codperfil, codigo, nome' . (in_array('ativo', $colsPerfis, true) ? ', ativo' : ', 1 AS ativo') . ' FROM perfis') as $p) {
    $perfisPorCodigo[strtoupper((string)$p['codigo'])] = (int)$p['codperfil'];
    $perfisPorId[(int)$p['codperfil']] = $p;
}

// 1. Plano por perfil ATLAS_GRUPO_N
$plano = [];          // codperfil antigo => [...]
$destinoDoGrupo = []; // codgrupo => codperfil final
$reservados = [];     // códigos novos usados nesta execução
foreach ($perfisPorId as $id => $p) {
    if (!preg_match('/^ATLAS_GRUPO_(\d+)$/i', (string)$p['codigo'], $m)) continue;
    $n = (int)$m[1];
    $nomeGrupo = $grupos[$n] ?? trim((string)$p['nome']);
    $novo = $normalizar($nomeGrupo);
    $item = ['perfil' => (string)$p['codigo'], 'codperfil' => $id, 'grupo' => $n, 'nome_grupo' => $nomeGrupo, 'novo_codigo' => $novo];
    if ($novo === '' || str_starts_with($novo, 'ATLAS_')) {
        $item['acao'] = 'IGNORADO (nome do grupo não serve como código)';
    } elseif (isset($perfisPorCodigo[$novo]) && $perfisPorCodigo[$novo] !== $id) {
        $item['acao'] = 'UNIR';
        $item['destino'] = $perfisPorCodigo[$novo];
    } elseif (isset($reservados[$novo])) {
        $item['acao'] = 'UNIR';
        $item['destino'] = $reservados[$novo];
    } else {
        $item['acao'] = 'RENOMEAR';
        $item['destino'] = $id;
        $reservados[$novo] = $id;
    }
    if (isset($item['destino'])) $destinoDoGrupo[$n] = $item['destino'];
    $plano[$id] = $item;
}
// Grupos sem perfil ATLAS_GRUPO_ mas com perfil de mesmo nome (ex.: grupo ADMINISTRADOR -> ADMINISTRADOR)
foreach ($grupos as $n => $nome) if (!isset($destinoDoGrupo[$n]) && isset($perfisPorCodigo[$normalizar($nome)])) $destinoDoGrupo[$n] = $perfisPorCodigo[$normalizar($nome)];

$codigoFinal = static function (int $codperfil) use ($plano, $perfisPorId): string {
    if (isset($plano[$codperfil]) && $plano[$codperfil]['acao'] === 'RENOMEAR') return $plano[$codperfil]['novo_codigo'];
    return (string)($perfisPorId[$codperfil]['codigo'] ?? $codperfil);
};

// 2. Usuários nos perfis ATLAS_GRUPO_*
$movimentos = [];
$resumoUsuarios = [];
$divergentes = [];
if ($plano) {
    $ids = implode(',', array_map('intval', array_keys($plano)));
    foreach ($pdo->query("SELECT u.codusuario, u.codperfil, u.codgrupo, $usuarioAtivo AS ativo FROM usuarios u WHERE u.codperfil IN ($ids)") as $u) {
        $atual = (int)$u['codperfil'];
        $grupo = $u['codgrupo'] === null ? 0 : (int)$u['codgrupo'];
        $destino = ($grupo && isset($destinoDoGrupo[$grupo])) ? $destinoDoGrupo[$grupo] : ($plano[$atual]['destino'] ?? null);
        if ($destino === null) continue;
        $chave = $plano[$atual]['perfil'] . ' -> ' . $codigoFinal($destino);
        $resumoUsuarios[$chave] ??= ['ativos' => 0, 'inativos' => 0];
        $resumoUsuarios[$chave][(int)$u['ativo'] === 1 ? 'ativos' : 'inativos']++;
        if ($grupo && $grupo !== $plano[$atual]['grupo']) $divergentes[$plano[$atual]['perfil'] . ' com grupo ' . ($grupos[$grupo] ?? $grupo)] = ($divergentes[$plano[$atual]['perfil'] . ' com grupo ' . ($grupos[$grupo] ?? $grupo)] ?? 0) + 1;
        if ($destino !== $atual) $movimentos[] = [(int)$u['codusuario'], $destino, $atual];
    }
}

// 3. Diferença de permissões nos casos UNIR (o que os usuários movidos ganham/perdem)
$rotuloMenu = static function (int $codmenu) use ($pdo): string {
    static $cache = null;
    if ($cache === null) { $cache = []; foreach ($pdo->query('SELECT codmenu, COALESCE(NULLIF(label_pt, \'\'), label, codigo) r FROM menus') as $m) $cache[(int)$m['codmenu']] = (string)$m['r']; }
    return $cache[$codmenu] ?? (string)$codmenu;
};
$visiveis = static function (int $codperfil) use ($pdo): array {
    $q = $pdo->prepare('SELECT codmenu FROM permissoes WHERE codperfil = ? AND pode_visualizar = 1');
    $q->execute([$codperfil]);
    return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
};
foreach ($plano as $id => &$item) {
    if ($item['acao'] !== 'UNIR') continue;
    $origem = $visiveis($id); $destino = $visiveis((int)$item['destino']);
    $perde = array_diff($origem, $destino); $ganha = array_diff($destino, $origem);
    $item['destino_codigo'] = $codigoFinal((int)$item['destino']);
    $item['telas_que_perdem'] = array_values(array_map($rotuloMenu, array_slice($perde, 0, 30)));
    $item['telas_que_ganham'] = count($ganha);
    if (count($perde) > 30) $item['telas_que_perdem'][] = '... e mais ' . (count($perde) - 30);
}
unset($item);

// 4. Outras tabelas que guardam codperfil (para os casos UNIR)
$q = $pdo->query("SELECT TABLE_NAME FROM information_schema.COLUMNS c JOIN information_schema.TABLES t USING (TABLE_SCHEMA, TABLE_NAME)
    WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = 'codperfil' AND t.TABLE_TYPE = 'BASE TABLE'
      AND TABLE_NAME NOT IN ('perfis', 'usuarios', 'permissoes', 'perfil_menus', 'permissoes_campos') AND TABLE_NAME NOT LIKE 'bkp%' AND TABLE_NAME NOT LIKE '%backup%'");
$outrasTabelas = $q->fetchAll(PDO::FETCH_COLUMN);

$relatorio = [
    'ok' => true, 'migracao' => '240', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'banco' => $banco,
    'perfis' => array_values(array_map(static fn(array $i): array => array_filter($i, static fn($v) => $v !== [] && $v !== null), $plano)),
    'usuarios' => $resumoUsuarios,
    'usuarios_com_grupo_diferente_do_perfil' => $divergentes ?: 'nenhum',
    'nao_alterados' => [
        'perfis_ATLAS_USUARIO' => (int)$pdo->query("SELECT COUNT(*) FROM perfis WHERE codigo LIKE 'ATLAS\\_USUARIO\\_%'")->fetchColumn(),
        'usuarios_ativos_em_ATLAS_USUARIO' => (int)$pdo->query("SELECT COUNT(*) FROM usuarios u JOIN perfis p ON p.codperfil = u.codperfil WHERE p.codigo LIKE 'ATLAS\\_USUARIO\\_%' AND $usuarioAtivo = 1")->fetchColumn(),
        'usuarios_ativos_sem_perfil' => (int)$pdo->query("SELECT COUNT(*) FROM usuarios u WHERE (u.codperfil IS NULL OR u.codperfil = 0) AND $usuarioAtivo = 1")->fetchColumn(),
    ],
    'outras_tabelas_com_codperfil' => $outrasTabelas,
];

if ($simular) {
    echo json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

// Cópias de segurança (a primeira execução é preservada)
$copias = ['perfis' => 'SELECT * FROM perfis', 'usuarios' => 'SELECT codusuario, codperfil, codgrupo FROM usuarios'];
foreach ($tabelasPermissao as $t) $copias[$t] = "SELECT * FROM `$t`";
foreach (['atlas_perfis_legado', 'atlas_usuarios_perfis_legado'] as $t) if ($colunas($t)) $copias[$t] = "SELECT * FROM `$t`";
foreach ($copias as $t => $sql) {
    if ($colunas("bkp240_$t")) continue;
    $pdo->exec("CREATE TABLE `bkp240_$t` AS $sql");
}
$relatorio['copias'] = array_map(static fn(string $t): string => "bkp240_$t", array_keys($copias));

$pdo->beginTransaction();
try {
    // Renomear (codigo e nome); o nome fica o do grupo
    $renomear = $pdo->prepare('UPDATE perfis SET codigo = ?, nome = ? WHERE codperfil = ?');
    foreach ($plano as $id => $item) if ($item['acao'] === 'RENOMEAR') $renomear->execute([$item['novo_codigo'], $item['nome_grupo'], $id]);

    // Usuários
    $mover = $pdo->prepare('UPDATE usuarios SET codperfil = ? WHERE codusuario = ? AND codperfil = ?');
    foreach ($movimentos as [$usuario, $destino, $atual]) $mover->execute([$destino, $usuario, $atual]);
    $relatorio['usuarios_movidos'] = count($movimentos);

    // Unir: referências de outras tabelas, permissões do perfil antigo e o próprio perfil
    foreach ($plano as $id => &$item) {
        if ($item['acao'] !== 'UNIR') continue;
        $destino = (int)$item['destino'];
        $restante = (int)$pdo->query('SELECT COUNT(*) FROM usuarios WHERE codperfil = ' . (int)$id)->fetchColumn();
        if ($restante > 0) { $item['resultado'] = "NAO EXCLUIDO: ainda há $restante usuário(s)"; continue; }
        $bloqueio = [];
        foreach ($outrasTabelas as $t) {
            if (in_array($t, ['atlas_perfis_legado', 'atlas_usuarios_perfis_legado'], true)) {
                // tabelas auxiliares da migração do legado: passam a apontar para o perfil que substitui o antigo;
                // se o destino já tem o mesmo vínculo, a linha antiga (duplicada) sai
                $pdo->prepare("UPDATE IGNORE `$t` SET codperfil = ? WHERE codperfil = ?")->execute([$destino, $id]);
                $pdo->prepare("DELETE FROM `$t` WHERE codperfil = ?")->execute([$id]);
                continue;
            }
            $pdo->prepare("UPDATE IGNORE `$t` SET codperfil = ? WHERE codperfil = ?")->execute([$destino, $id]);
            $q = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE codperfil = ?");
            $q->execute([$id]);
            if ((int)$q->fetchColumn() > 0) $bloqueio[] = $t;
        }
        if ($bloqueio) {
            $pdo->prepare('UPDATE perfis SET ativo = 0 WHERE codperfil = ?')->execute([$id]);
            $item['resultado'] = 'INATIVADO (não excluído: ainda referenciado em ' . implode(', ', $bloqueio) . ')';
            continue;
        }
        foreach ($tabelasPermissao as $t) $pdo->prepare("DELETE FROM `$t` WHERE codperfil = ?")->execute([$id]);
        $pdo->prepare('DELETE FROM perfis WHERE codperfil = ?')->execute([$id]);
        $item['resultado'] = 'EXCLUIDO';
    }
    unset($item);
    foreach ($plano as $id => &$item) if ($item['acao'] === 'RENOMEAR') $item['resultado'] = 'RENOMEADO';
    unset($item);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $relatorio['ok'] = false;
    $relatorio['erro'] = $e->getMessage() . ' (nada foi alterado)';
}

$relatorio['perfis'] = array_values(array_map(static fn(array $i): array => array_filter($i, static fn($v) => $v !== [] && $v !== null), $plano));
$relatorio['restam_ATLAS_GRUPO'] = $pdo->query("SELECT GROUP_CONCAT(codigo) FROM perfis WHERE codigo LIKE 'ATLAS\\_GRUPO\\_%'")->fetchColumn() ?: 'nenhum';
if ($relatorio['ok'] && $colunas('registros_migracao')) {
    $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
        VALUES ('ATLAS_PERFIS_POR_GRUPO_240', 'perfis ATLAS_GRUPO_*', 'perfis por grupo', 'DADOS', '4.0.1', 'CONCLUIDA', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
        ON DUPLICATE KEY UPDATE status = VALUES(status), quantidade_registros = VALUES(quantidade_registros), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
        ->execute([count($movimentos), json_encode($relatorio, JSON_UNESCAPED_UNICODE)]);
}
$relatorio['observacao'] = 'Os usuários afetados precisam entrar de novo no sistema para o novo perfil valer. Cópias em bkp240_*.';

echo json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
