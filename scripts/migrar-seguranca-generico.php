<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/AssistenteAplicacaoService.php';

function argValue(string $name): string
{
    foreach ($GLOBALS['argv'] as $arg) {
        if (str_starts_with($arg, '--' . $name . '=')) return substr($arg, strlen($name) + 3);
    }
    return '';
}

function qi(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) throw new RuntimeException('Identificador invalido: ' . $name);
    return '`' . $name . '`';
}

function columns(PDO $pdo, string $table): array
{
    $q = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
    $q->execute([$table]);
    return array_map('strtolower', $q->fetchAll(PDO::FETCH_COLUMN));
}

function tableExists(PDO $pdo, string $table): bool
{
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]);
    return (int)$q->fetchColumn() > 0;
}

function pick(array $available, array $names): ?string
{
    $map = array_fill_keys(array_map('strtolower', $available), true);
    foreach ($names as $name) if (isset($map[strtolower($name)])) return strtolower($name);
    return null;
}

function value(array $row, ?string $column, mixed $default = null): mixed
{
    return $column !== null && array_key_exists($column, $row) ? $row[$column] : $default;
}

function perfilCodigoOficial(string $nome): string
{
    $value = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome) : $nome;
    $value = strtoupper(trim((string)$value));
    $value = preg_replace('/[^A-Z0-9]+/', '_', $value) ?: 'GRUPO';
    $especiais = [
        'ADMINISTRADOR' => 'ADMINISTRADOR',
        'ADM_DA_EMPRESA' => 'ADM_DA_EMPRESA',
        'ADM_EMPRESA' => 'ADM_DA_EMPRESA',
    ];
    return $especiais[$value] ?? $value;
}

function mesclarPerfilLegado(PDO $target, string $table, string $profileIdColumn, int $oldId, int $newId): void
{
    $columns = columns($target, $table);
    if (!in_array('codperfil', $columns, true)) return;
    $insertColumns = array_values(array_filter($columns, static fn(string $column): bool => $column !== 'id'));
    $select = [];
    foreach ($insertColumns as $column) $select[] = $column === 'codperfil' ? '?' : qi($column);
    $updates = [];
    foreach ($insertColumns as $column) if (!in_array($column, ['codperfil', 'codmenu'], true)) $updates[] = qi($column) . '=VALUES(' . qi($column) . ')';
    if (!$updates) $updates[] = qi('codperfil') . '=VALUES(' . qi('codperfil') . ')';
    $sql = 'INSERT INTO ' . qi($table) . ' (' . implode(',', array_map('qi', $insertColumns)) . ') SELECT ' . implode(',', $select) . ' FROM ' . qi($table) . ' WHERE codperfil=? ON DUPLICATE KEY UPDATE ' . implode(',', $updates);
    $target->prepare($sql)->execute([$newId, $oldId]);
    $target->prepare('DELETE FROM ' . qi($table) . ' WHERE codperfil=?')->execute([$oldId]);
}

function normalizarMenu(string $value): string
{
    $value = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : $value;
    return strtolower(trim(preg_replace('/[^a-z0-9]+/', ' ', (string)$value) ?: ''));
}

try {
    $origins = array_values(array_filter(array_map('trim', explode(',', argValue('origins')))));
    $targetName = trim(argValue('target'));
    if (!$origins || !preg_match('/^[A-Za-z0-9_]+$/', $targetName)) throw new RuntimeException('Origens ou destino invalidos.');
    $env = GreenPortalDatabase::env();
    $sources = [];
    foreach ($origins as $name) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) throw new RuntimeException('Banco origem invalido: ' . $name);
    $sources[$name] = GreenPortalDatabase::connect($env, $name, 'GPT');
    }
    $target = GreenPortalDatabase::connect($env, $targetName, 'GPT');
    $universal = GreenPortalDatabase::connect($env, 'guniversal_dbp', 'GPT');
    if (!tableExists($universal, 'menus') || !tableExists($target, 'menus')) throw new RuntimeException('Catalogo universal de menus indisponivel.');
    $universalColumns = columns($universal, 'menus'); $targetMenuColumns = columns($target, 'menus');
    $menuFields = array_values(array_intersect($targetMenuColumns, $universalColumns));
    $menuFields = array_values(array_filter($menuFields, static fn(string $field): bool => in_array($field, ['codmenu','codmenu_pai','modulo','codigo','label','label_pt','label_en','label_es','url','nivel','ordem','ativo'], true)));
    if (!in_array('codmenu', $menuFields, true)) throw new RuntimeException('Catalogo de menus do destino sem chave codmenu.');
    $menuRowsUniversal = $universal->query('SELECT * FROM menus ORDER BY COALESCE(nivel,1),COALESCE(ordem,0),codmenu')->fetchAll(PDO::FETCH_ASSOC);
    $menuInsert = $target->prepare('INSERT INTO menus (' . implode(',', array_map('qi', $menuFields)) . ') VALUES (' . implode(',', array_fill(0, count($menuFields), '?')) . ') ON DUPLICATE KEY UPDATE ' . implode(',', array_map(static fn(string $field): string => qi($field) . '=VALUES(' . qi($field) . ')', array_values(array_diff($menuFields, ['codmenu'])))));
    $target->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($menuRowsUniversal as $menuRow) { $menuRow = array_change_key_case($menuRow, CASE_LOWER); $menuInsert->execute(array_map(static fn(string $field): mixed => $menuRow[$field] ?? null, $menuFields)); }
    $target->exec('SET FOREIGN_KEY_CHECKS=1');
    $securityName = null;
    foreach ($sources as $name => $pdo) {
        if (tableExists($pdo, 'usuarios') && tableExists($pdo, 'grupos')) { $securityName = $name; break; }
    }
    if ($securityName === null) throw new RuntimeException('Nenhum banco de origem possui as tabelas usuarios e grupos.');
    $security = $sources[$securityName];
    $functionalName = null;
    foreach ($sources as $name => $pdo) if ($name !== $securityName) { $functionalName = $name; break; }

    $groupColumns = columns($security, 'grupos');
    $groupId = pick($groupColumns, ['codgrupo', 'CODGRUPO', 'id']);
    $groupName = pick($groupColumns, ['nome', 'NOME', 'dscgrupo', 'DSCGRUPO', 'descricao']);
    if (!$groupId || !$groupName) throw new RuntimeException('A tabela grupos nao possui chave e descricao reconheciveis.');
    $groups = $security->query('SELECT ' . qi($groupId) . ' AS id,' . qi($groupName) . ' AS nome FROM ' . qi('grupos'))->fetchAll(PDO::FETCH_ASSOC);

    $targetGroupColumns = columns($target, 'grupos');
    $targetGroupId = pick($targetGroupColumns, ['codgrupo', 'id']);
    $targetGroupName = pick($targetGroupColumns, ['nome', 'dscgrupo', 'descricao']);
    if (!$targetGroupId || !$targetGroupName) throw new RuntimeException('A tabela grupos do destino nao possui estrutura compativel.');
    $groupInsert = $target->prepare('INSERT INTO ' . qi('grupos') . ' (' . qi($targetGroupId) . ',' . qi($targetGroupName) . ') VALUES (?,?) ON DUPLICATE KEY UPDATE ' . qi($targetGroupName) . '=VALUES(' . qi($targetGroupName) . ')');
    foreach ($groups as $group) $groupInsert->execute([(int)$group['id'], (string)$group['nome']]);

    $profiles = [];
    if (!tableExists($target, 'perfis')) throw new RuntimeException('A tabela perfis nao existe no banco destino.');
    $profileCode = pick(columns($target, 'perfis'), ['codigo']);
    $profileName = pick(columns($target, 'perfis'), ['nome', 'descricao']);
    $profileId = pick(columns($target, 'perfis'), ['codperfil', 'id']);
    if (!$profileCode || !$profileName || !$profileId) throw new RuntimeException('A tabela perfis do destino nao possui estrutura compativel.');
    $profileInsert = $target->prepare('INSERT INTO ' . qi('perfis') . ' (' . qi($profileCode) . ',' . qi($profileName) . ') VALUES (?,?) ON DUPLICATE KEY UPDATE ' . qi($profileName) . '=VALUES(' . qi($profileName) . ')');
    $profileFind = $target->prepare('SELECT ' . qi($profileId) . ' FROM ' . qi('perfis') . ' WHERE ' . qi($profileCode) . '=? LIMIT 1');
    foreach ($groups as $group) {
        $code = perfilCodigoOficial((string)$group['nome']);
        $profileInsert->execute([$code, (string)$group['nome']]);
        $profileFind->execute([$code]);
        $profiles[(int)$group['id']] = (int)$profileFind->fetchColumn();
        $legacyCode = 'LEGADO_' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', trim((string)$group['nome'])) ?: 'GRUPO_' . (int)$group['id']);
        if ($legacyCode !== $code) {
            $profileFind->execute([$legacyCode]);
            $oldProfileId = (int)$profileFind->fetchColumn();
            if ($oldProfileId > 0 && $oldProfileId !== $profiles[(int)$group['id']]) {
                $newProfileId = $profiles[(int)$group['id']];
                $target->prepare('UPDATE usuarios SET codperfil=? WHERE codperfil=?')->execute([$newProfileId, $oldProfileId]);
                foreach (['perfil_menus', 'permissoes'] as $permissionTable) if (tableExists($target, $permissionTable)) mesclarPerfilLegado($target, $permissionTable, $profileId, $oldProfileId, $newProfileId);
                $target->prepare('DELETE FROM perfis WHERE ' . qi($profileId) . '=?')->execute([$oldProfileId]);
            }
        }
    }
    $legacyProfiles = $target->query('SELECT ' . qi($profileId) . ' id,' . qi($profileCode) . ' codigo FROM ' . qi('perfis') . ' WHERE ' . qi($profileCode) . ' LIKE \'LEGADO_%\'')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($legacyProfiles as $legacyProfile) {
        $legacyCode = (string)$legacyProfile['codigo'];
        $officialCode = substr($legacyCode, 7);
        if ($officialCode === '') continue;
        $profileFind->execute([$officialCode]); $officialId = (int)$profileFind->fetchColumn(); $legacyId = (int)$legacyProfile['id'];
        if ($officialId < 1 || $officialId === $legacyId) continue;
        $target->prepare('UPDATE usuarios SET codperfil=? WHERE codperfil=?')->execute([$officialId, $legacyId]);
        foreach (['perfil_menus', 'permissoes'] as $permissionTable) if (tableExists($target, $permissionTable)) mesclarPerfilLegado($target, $permissionTable, $profileId, $legacyId, $officialId);
        $target->prepare('DELETE FROM perfis WHERE ' . qi($profileId) . '=? AND NOT EXISTS (SELECT 1 FROM usuarios WHERE codperfil=?)')->execute([$legacyId, $legacyId]);
    }

    $userColumns = columns($security, 'usuarios');
    $userId = pick($userColumns, ['codusuario', 'CODUSUARIO', 'id']);
    $userLogin = pick($userColumns, ['userid', 'USERID', 'login', 'usuario']);
    $userName = pick($userColumns, ['nome', 'NOME']);
    if (!$userId || !$userLogin || !$userName) throw new RuntimeException('A tabela usuarios nao possui campos reconheciveis.');
    $userRows = $security->query('SELECT * FROM ' . qi('usuarios'))->fetchAll(PDO::FETCH_ASSOC);
    $targetUserColumns = columns($target, 'usuarios');
    $targetUserId = pick($targetUserColumns, ['codusuario', 'id']);
    if (!$targetUserId) throw new RuntimeException('A tabela usuarios do destino nao possui chave reconhecivel.');
    $userGroups = [];
    if (tableExists($security, 'usuariosxgrupos')) {
        $relColumns = columns($security, 'usuariosxgrupos');
        $relUser = pick($relColumns, ['codusuario', 'CODUSUARIO', 'usuario_id']);
        $relGroup = pick($relColumns, ['codgrupo', 'CODGRUPO', 'grupo_id']);
        if ($relUser && $relGroup) foreach ($security->query('SELECT ' . qi($relUser) . ' usuario,' . qi($relGroup) . ' grupo FROM ' . qi('usuariosxgrupos')) as $rel) $userGroups[(int)$rel['usuario']][] = (int)$rel['grupo'];
    }
    $targetUserInsertColumns = [$targetUserId, 'userid', 'nome'];
    $targetUserInsertValues = ['id', 'login', 'name'];
    foreach (['email', 'senha', 'senha_usa_hash', 'codgrupo', 'codperfil', 'ativo', 'trocar_senha', 'criado_em'] as $column) if (in_array($column, $targetUserColumns, true)) { $targetUserInsertColumns[] = $column; $targetUserInsertValues[] = $column; }
    $quotedColumns = implode(',', array_map('qi', $targetUserInsertColumns));
    $placeholders = implode(',', array_fill(0, count($targetUserInsertColumns), '?'));
    $userInsert = $target->prepare('INSERT INTO ' . qi('usuarios') . ' (' . $quotedColumns . ') VALUES (' . $placeholders . ') ON DUPLICATE KEY UPDATE ' . implode(',', array_map(static fn(string $c): string => qi($c) . '=VALUES(' . qi($c) . ')', array_slice($targetUserInsertColumns, 1))));
    $userCount = 0;
    foreach ($userRows as $sourceUser) {
        $sourceUser = array_change_key_case($sourceUser, CASE_LOWER);
        $uid = (int)value($sourceUser, $userId, 0); $login = trim((string)value($sourceUser, $userLogin, '')); if ($uid < 1 || $login === '') continue;
        $groupsForUser = $userGroups[$uid] ?? [];
        $groupForUser = (int)($groupsForUser[0] ?? value($sourceUser, pick($userColumns, ['codgrupo', 'CODGRUPO', 'grupo_id']), 0));
        $profileForUser = $profiles[$groupForUser] ?? null;
        $values = [];
        foreach ($targetUserInsertValues as $meaning) {
            $values[] = match ($meaning) {
                'id' => $uid,
                'login' => $login,
                'name' => (string)value($sourceUser, $userName, $login),
                'email' => (string)value($sourceUser, pick($userColumns, ['email', 'emails', 'EMAILS']), ''),
                'senha' => (string)value($sourceUser, pick($userColumns, ['senha', 'SENHA']), ''),
                'senha_usa_hash' => 0,
                'codgrupo' => $groupForUser ?: null,
                'codperfil' => $profileForUser,
                'ativo' => strtoupper((string)value($sourceUser, pick($userColumns, ['ativado', 'ativo', 'ATIVADO', 'ATIVO']), 'S')) === 'N' ? 0 : 1,
                'trocar_senha' => 0,
                'criado_em' => (string)value($sourceUser, pick($userColumns, ['dtinclusao', 'criado_em', 'created_at']), date('Y-m-d H:i:s')),
                default => null,
            };
        }
        $userInsert->execute($values); $userCount++;
    }

    if ($userCount === 0 && $profiles) {
        $technicalProfile = (int)reset($profiles);
        $technicalValues = [];
        foreach ($targetUserInsertValues as $meaning) $technicalValues[] = match ($meaning) {
            'id' => 900000000,
            'login' => '__GREENPORTAL_MIGRACAO__',
            'name' => 'GreenPortal Migração',
            'email' => 'greenportal.migracao@localhost',
            'senha' => bin2hex(random_bytes(16)),
            'senha_usa_hash' => 0,
            'codgrupo' => null,
            'codperfil' => $technicalProfile,
            'ativo' => 1,
            'trocar_senha' => 0,
            'criado_em' => date('Y-m-d H:i:s'),
            default => null,
        };
        $userInsert->execute($technicalValues); $userCount = 1;
    }
    $permissions = 0; $unmappedMenus = 0; $unmappedMenuDetails = [];
    if (tableExists($security, 'gruposxmenus')) {
        $relColumns = columns($security, 'gruposxmenus'); $relGroup = pick($relColumns, ['codgrupo', 'CODGRUPO', 'grupo_id']); $relMenu = pick($relColumns, ['codmenu', 'CODMENU', 'menu_id']);
        $menuCatalog = [];
        foreach ($sources as $sourcePdo) if (tableExists($sourcePdo, 'menus')) {
            $mc = columns($sourcePdo, 'menus'); $mi = pick($mc, ['codmenu','CODMENU','id']); $ml = pick($mc, ['codigo','CODIGO','code']); $md = pick($mc, ['dscmenu','nome','label','descricao']);
            if ($mi) foreach ($sourcePdo->query('SELECT * FROM menus') as $menu) { $menu = array_change_key_case($menu, CASE_LOWER); $menuCatalog[(int)$menu[$mi]] = ['codigo' => $ml ? (string)($menu[$ml] ?? '') : '', 'label' => $md ? (string)($menu[$md] ?? '') : '']; }
        }
        try { $legacyCatalogPdo = GreenPortalDatabase::connect($env, 'green_seguranca', 'GPT'); if (tableExists($legacyCatalogPdo, 'menus')) foreach ($legacyCatalogPdo->query('SELECT CODMENU,DSCMENU,URL FROM menus') as $menu) $menuCatalog[(int)$menu['CODMENU']]=['codigo'=>(string)$menu['URL'],'label'=>(string)$menu['DSCMENU']]; } catch (Throwable) {}
        $targetMenuColumns = columns($target, 'menus'); $targetMenuId = pick($targetMenuColumns, ['codmenu','id']); $targetMenuCode = pick($targetMenuColumns, ['codigo']); $targetMenuLabel = pick($targetMenuColumns, ['label_pt','label']);
        $targetByCode=[]; $targetByLabel=[]; foreach($target->query('SELECT * FROM menus') as $menu){$menu=array_change_key_case($menu,CASE_LOWER);$id=(int)$menu[$targetMenuId];if($targetMenuCode)$targetByCode[strtolower((string)$menu[$targetMenuCode])]=$id;if($targetMenuLabel)$targetByLabel[normalizarMenu((string)$menu[$targetMenuLabel])]=$id;}
        $targetPermTables=array_values(array_filter(['perfil_menus','permissoes'],static fn(string $t):bool=>tableExists($target,$t)));
        if($relGroup&&$relMenu&&$targetMenuId)foreach($security->query('SELECT * FROM gruposxmenus') as $rel){$rel=array_change_key_case($rel,CASE_LOWER);$gid=(int)$rel[$relGroup];$legacyId=(int)$rel[$relMenu];if(!isset($profiles[$gid]))continue;$legacy=$menuCatalog[$legacyId]??['codigo'=>'','label'=>''];$mid=0;if($legacy['codigo']!==''&&isset($targetByCode[strtolower($legacy['codigo'])]))$mid=$targetByCode[strtolower($legacy['codigo'])];if(!$mid&&$legacy['label']!=='')$mid=$targetByLabel[normalizarMenu($legacy['label'])]??0;if(!$mid){$mid=$legacyId;}$check=$target->prepare('SELECT 1 FROM menus WHERE '.$targetMenuId.'=? LIMIT 1');$check->execute([$mid]);if(!$check->fetchColumn()){$unmappedMenus++;if(count($unmappedMenuDetails)<5000)$unmappedMenuDetails[]=['grupo_legado'=>$gid,'codmenu_legado'=>$legacyId,'codigo'=>$legacy['codigo'],'label'=>$legacy['label']];continue;}foreach($targetPermTables as $permTable){$pc=columns($target,$permTable);$profileColumn=pick($pc,['codperfil']);$menuColumn=pick($pc,['codmenu']);if(!$profileColumn||!$menuColumn)continue;$fields=[$profileColumn,$menuColumn];$vals=[$profiles[$gid],$mid];foreach(['pode_incluir','pode_visualizar','pode_editar','pode_excluir','pode_exportar_excel','pode_gerar_pdf'] as $permission)if(in_array($permission,$pc,true)){$fields[]=$permission;$vals[]=1;}$updates=array_slice($fields,2)?:[$profileColumn];$sql='INSERT INTO '.qi($permTable).' ('.implode(',',array_map('qi',$fields)).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).') ON DUPLICATE KEY UPDATE '.implode(',',array_map(static fn(string $f):string=>qi($f).'=VALUES('.qi($f).')',$updates));$target->prepare($sql)->execute($vals);}$permissions++;}
    }
    foreach ($unmappedMenuDetails as $unmapped) try { $legacyId=(int)($unmapped['codmenu_legado']??0); $legacyCode=trim((string)($unmapped['codigo']??''))?:($legacyId?'LEGADO_MENU_'.$legacyId:''); $legacyLabel=trim((string)($unmapped['label']??''))?:($legacyId?'Menu legado #'.$legacyId:'Menu legado não identificado'); AssistenteAplicacaoService::chamarProcedure($universal, 'sp_migracao_menu_nao_mapeado_registrar', [$targetName, (int)($unmapped['grupo_legado'] ?? 0), $legacyId, $legacyCode, $legacyLabel]); } catch (Throwable) {}
    echo json_encode(['ok' => true, 'origem_seguranca' => $securityName, 'origem_funcional' => $functionalName, 'destino' => $targetName, 'menus_universais' => count($menuRowsUniversal), 'grupos' => count($groups), 'usuarios' => $userCount, 'permissoes' => $permissions, 'menus_nao_mapeados' => $unmappedMenus, 'menus_nao_mapeados_detalhes' => $unmappedMenuDetails], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
