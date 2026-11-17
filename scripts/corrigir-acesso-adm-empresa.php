<?php
declare(strict_types=1);

/*
 * Reparo de acesso do perfil ADM DA EMPRESA de uma empresa (ex.: CROWN depois da migração com erro na etapa de segurança).
 *   1. Liga para a empresa os módulos comerciais Módulo Legal, Homologação de Fornecedores e Não Conformidades
 *      (guniversal_dbp.empresa_modulos).
 *   2. Banco da empresa: se `menus` for tabela, inclui os menus ativos do universal que faltarem (mesmo codmenu).
 *   3. Dá ao perfil ADM DA EMPRESA acesso completo (todas as colunas pode_*) a todos os menus desses três módulos.
 *   4. Para os demais menus, acrescenta o que o ADM DA EMPRESA da empresa de referência (--referencia, padrão ATLAS) tem e
 *      a empresa não tem — nunca tira permissão.
 *   5. Mostra a situação do usuário informado (--usuario), para conferência.
 * Os menus são casados pelo código (o número do menu pode variar entre as empresas); menus que faltam são incluídos dos pais para os filhos.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\corrigir-acesso-adm-empresa.php --empresa=CROWN [--usuario=nathalia.capacla] [--referencia=ATLAS] [--simular]
 * Pode ser executado mais de uma vez. O usuário precisa sair e entrar de novo no portal para carregar as permissões.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

const MODULOS = ['MODULO_LEGAL', 'HOMOLOGACAO_FORNECEDORES', 'NAO_CONFORMIDADES'];
// Raízes do menu de cada módulo (a Manutenção Legal faz parte do Módulo Legal)
const RAIZES = ['MODULO_LEGAL' => 'MODULO_LEGAL', 'MANUTENCAO_LEGAL' => 'MODULO_LEGAL', 'HOMOLOGACAO_FORNECEDORES' => 'HOMOLOGACAO_FORNECEDORES', 'NAO_CONFORMIDADES' => 'NAO_CONFORMIDADES'];

$opc = ['empresa' => '', 'usuario' => '', 'referencia' => 'ATLAS'];
foreach ($argv ?? [] as $a) if (preg_match('/^--(empresa|usuario|referencia)=(.+)$/', $a, $m)) $opc[$m[1]] = trim($m[2]);
$simular = in_array('--simular', $argv ?? [], true);
if ($opc['empresa'] === '') { fwrite(STDERR, "Informe --empresa=CODIGO (ex.: --empresa=CROWN)\n"); exit(1); }
$normPerfil = static fn(string $c): string => preg_replace('/\s+/', ' ', preg_replace('/[_-]+/', ' ', strtoupper(trim($c))) ?? '') ?? '';
$rel = ['ok' => true, 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'empresa' => strtoupper($opc['empresa'])];

try {
    $env = GreenPortalDatabase::env();
    $nomeUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
    $u = GreenPortalDatabase::connect($env, $nomeUniversal, 'GUNIVERSAL');
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $empresaPor = static function (string $codigo) use ($u): ?array { $s = $u->prepare('SELECT codempresa, codigo, nome, banco_dados FROM empresa WHERE UPPER(codigo) = UPPER(?) LIMIT 1'); $s->execute([$codigo]); return $s->fetch(PDO::FETCH_ASSOC) ?: null; };
    $emp = $empresaPor($opc['empresa']);
    if (!$emp) throw new RuntimeException('Empresa não encontrada: ' . $opc['empresa']);
    $rel['empresa'] = $emp['codigo'] . ' - ' . $emp['nome'] . ' (' . $emp['banco_dados'] . ')';

    // 1. módulos da empresa
    $antes = [];
    $s = $u->prepare('SELECT codigo_modulo, ativo FROM empresa_modulos WHERE codempresa = ?'); $s->execute([(int)$emp['codempresa']]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $antes[strtoupper((string)$r['codigo_modulo'])] = (int)$r['ativo'];
    foreach (MODULOS as $mod) {
        $estava = $antes[$mod] ?? null;
        $rel['modulos'][$mod] = $estava === 1 ? 'já ligado' : ($estava === null ? 'não existia → ligado' : 'desligado → ligado');
        if ($simular || $estava === 1) continue;
        $up = $u->prepare('UPDATE empresa_modulos SET ativo = 1 WHERE codempresa = ? AND codigo_modulo = ?'); $up->execute([(int)$emp['codempresa'], $mod]);
        if ($up->rowCount() === 0 && $estava === null) { $in = $u->prepare('INSERT INTO empresa_modulos (codempresa, codigo_modulo, ativo) VALUES (?, ?, 1)'); $in->execute([(int)$emp['codempresa'], $mod]); }
    }

    // menus do universal e módulo de cada um (pela raiz da árvore)
    $menus = $u->query('SELECT codmenu, codmenu_pai, codigo FROM vw_menus_arvore')->fetchAll(PDO::FETCH_ASSOC);
    $porId = []; foreach ($menus as $m) $porId[(int)$m['codmenu']] = $m;
    $moduloDe = static function (int $id) use ($porId): ?string { $g = 0; $c = $porId[$id] ?? null; while ($c && $g++ < 50) { $cod = strtoupper((string)$c['codigo']); if (isset(RAIZES[$cod])) return RAIZES[$cod]; $c = $porId[(int)($c['codmenu_pai'] ?? 0)] ?? null; } return null; };
    $menusModulo = [];
    foreach ($porId as $id => $m) if (($mod = $moduloDe($id)) !== null) $menusModulo[$id] = $mod;
    $rel['menus_dos_modulos'] = array_count_values($menusModulo);

    // 2. banco da empresa: os menus são casados pelo CÓDIGO (o número do menu pode ser diferente do universal)
    $t = GreenPortalDatabase::connect($env, (string)$emp['banco_dados'], 'GPT');
    $t->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $tipoMenus = (string)$t->query("SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menus'")->fetchColumn();
    $rel['menus_empresa'] = $tipoMenus ?: 'não existe';
    $idEmpresa = []; // CODIGO => codmenu no banco da empresa
    foreach ($t->query('SELECT codmenu, codigo FROM menus')->fetchAll(PDO::FETCH_ASSOC) as $m) $idEmpresa[strtoupper((string)$m['codigo'])] = (int)$m['codmenu'];
    if ($tipoMenus === 'BASE TABLE') {
        $colsT = $t->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menus'")->fetchAll(PDO::FETCH_COLUMN);
        $colsU = $u->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menus'")->fetchAll(PDO::FETCH_COLUMN);
        $comuns = array_values(array_diff(array_intersect($colsT, $colsU), ['codmenu', 'codmenu_pai']));
        $usados = array_flip(array_values($idEmpresa));
        $autoInc = str_contains((string)$t->query("SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menus' AND COLUMN_NAME = 'codmenu'")->fetchColumn(), 'auto_increment');
        $proximo = max(array_merge([0], array_keys($usados))) + 1;
        $uni = $u->query('SELECT * FROM menus WHERE ativo = 1 ORDER BY codmenu')->fetchAll(PDO::FETCH_ASSOC);
        $uniPorId = []; foreach ($uni as $m) $uniPorId[(int)$m['codmenu']] = $m;
        $faltam = array_values(array_filter($uni, static fn($m) => !isset($idEmpresa[strtoupper((string)$m['codigo'])])));
        $rel['menus_faltando_na_empresa'] = count($faltam);
        // inclui dos pais para os filhos; o pai é o menu de mesmo código no banco da empresa
        $incluidos = 0;
        for ($volta = 0; $faltam && $volta < 20; $volta++) {
            $resto = [];
            foreach ($faltam as $m) {
                $pai = (int)($m['codmenu_pai'] ?? 0);
                $paiCod = $pai > 0 ? strtoupper((string)($uniPorId[$pai]['codigo'] ?? '')) : '';
                if ($pai > 0 && ($paiCod === '' || !isset($idEmpresa[$paiCod]))) { $resto[] = $m; continue; }
                $cols = $comuns; $vals = array_map(static fn($c) => $m[$c], $comuns);
                $cols[] = 'codmenu_pai'; $vals[] = $pai > 0 ? $idEmpresa[$paiCod] : null;
                $id = (int)$m['codmenu'];
                // mesmo número do universal quando estiver livre; senão o próximo livre
                $ocupado = isset($usados[$id]);
                if ($ocupado && !$autoInc) { while (isset($usados[$proximo])) $proximo++; $id = $proximo; $ocupado = false; }
                if (!$ocupado) { $cols[] = 'codmenu'; $vals[] = $id; }
                if (!$simular) {
                    $st = $t->prepare('INSERT INTO menus (' . implode(', ', array_map(static fn($c) => "`$c`", $cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')');
                    $st->execute($vals);
                    $novoId = $ocupado ? (int)$t->lastInsertId() : $id;
                } else $novoId = $ocupado ? -$id : $id;
                $idEmpresa[strtoupper((string)$m['codigo'])] = $novoId; $usados[$novoId] = true; $incluidos++;
            }
            if (count($resto) === count($faltam)) break;
            $faltam = $resto;
        }
        $rel['menus_incluidos'] = $incluidos;
        if ($faltam) $rel['menus_sem_pai'] = array_map(static fn($m) => (string)$m['codigo'], $faltam);
    }
    // menus dos três módulos, no número do banco da empresa
    $menusModuloEmpresa = [];
    foreach ($menusModulo as $idU => $mod) { $c = strtoupper((string)$porId[$idU]['codigo']); if (isset($idEmpresa[$c]) && $idEmpresa[$c] > 0) $menusModuloEmpresa[$idEmpresa[$c]] = $mod; }

    // 3. perfil ADM DA EMPRESA
    $perfis = $t->query('SELECT codperfil, codigo, nome FROM perfis')->fetchAll(PDO::FETCH_ASSOC);
    $adm = array_values(array_filter($perfis, static fn($p) => in_array($normPerfil((string)$p['codigo']), ['ADM DA EMPRESA', 'ADM EMPRESA'], true)));
    if (!$adm && in_array('--criar-perfil', $argv ?? [], true)) {
        // cria o perfil ADM_DA_EMPRESA copiando o cadastro do perfil da empresa de referência (sem o número)
        $refEmpP = $empresaPor($opc['referencia']);
        $rp = $refEmpP ? GreenPortalDatabase::connect($env, (string)$refEmpP['banco_dados'], 'GPT') : null;
        $modelo = null;
        if ($rp) foreach ($rp->query('SELECT * FROM perfis')->fetchAll(PDO::FETCH_ASSOC) as $pf) if (in_array($normPerfil((string)$pf['codigo']), ['ADM DA EMPRESA', 'ADM EMPRESA'], true)) { $modelo = $pf; break; }
        $colsP = $t->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perfis' AND EXTRA NOT LIKE '%auto_increment%' AND COLUMN_NAME <> 'codperfil'")->fetchAll(PDO::FETCH_COLUMN);
        $dados = [];
        foreach ($colsP as $c) if ($modelo && array_key_exists($c, $modelo)) $dados[$c] = $modelo[$c];
        $dados['codigo'] = 'ADM_DA_EMPRESA'; if (in_array('nome', $colsP, true)) $dados['nome'] = $dados['nome'] ?? 'ADM DA EMPRESA'; if (in_array('ativo', $colsP, true)) $dados['ativo'] = 1;
        if (!$simular) { $st = $t->prepare('INSERT INTO perfis (' . implode(', ', array_map(static fn($c) => "`$c`", array_keys($dados))) . ') VALUES (' . implode(', ', array_fill(0, count($dados), '?')) . ')'); $st->execute(array_values($dados)); }
        $rel['perfil_criado'] = 'ADM_DA_EMPRESA' . ($simular ? ' (seria criado)' : '');
        $perfis = $t->query('SELECT codperfil, codigo, nome FROM perfis')->fetchAll(PDO::FETCH_ASSOC);
        $adm = array_values(array_filter($perfis, static fn($p) => in_array($normPerfil((string)$p['codigo']), ['ADM DA EMPRESA', 'ADM EMPRESA'], true)));
        if (!$adm && $simular) { $rel['perfis_existentes'] = array_map(static fn($p) => $p['codigo'], $perfis); throw new RuntimeException('Simulação: o perfil seria criado; rode sem --simular para gravar as permissões.'); }
    }
    if (!$adm) { $rel['perfis_existentes'] = array_map(static fn($p) => (string)$p['codigo'], $perfis); throw new RuntimeException('Perfil ADM DA EMPRESA não encontrado em ' . $emp['banco_dados'] . '. Para criá-lo (copiando o da empresa de referência), rode de novo com --criar-perfil.'); }
    $rel['perfil'] = array_map(static fn($p) => $p['codperfil'] . ' ' . $p['codigo'], $adm);
    $flags = array_values(array_filter($t->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'permissoes' AND COLUMN_NAME LIKE 'pode\\_%'")->fetchAll(PDO::FETCH_COLUMN)));
    $existentes = static function (int $perfil) use ($t): array { $s = $t->prepare('SELECT * FROM permissoes WHERE codperfil = ?'); $s->execute([$perfil]); $o = []; foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $o[(int)$r['codmenu']] = $r; return $o; };
    $gravar = static function (int $perfil, int $menu, array $valores) use ($t, $flags): void {
        $cols = array_merge(['codperfil', 'codmenu'], $flags);
        $vals = array_merge([$perfil, $menu], array_map(static fn($f) => (int)($valores[$f] ?? 0), $flags));
        $upd = implode(', ', array_map(static fn($f) => "`$f` = GREATEST(`$f`, VALUES(`$f`))", $flags));
        $s = $t->prepare('INSERT INTO permissoes (' . implode(', ', array_map(static fn($c) => "`$c`", $cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ") ON DUPLICATE KEY UPDATE $upd");
        $s->execute($vals);
    };
    // referência (ex.: ATLAS) para os menus fora dos três módulos, também casada pelo código do menu
    $ref = []; $refEmp = $empresaPor($opc['referencia']);
    if ($refEmp && strtoupper((string)$refEmp['codigo']) !== strtoupper((string)$emp['codigo'])) {
        $r = GreenPortalDatabase::connect($env, (string)$refEmp['banco_dados'], 'GPT');
        foreach ($r->query('SELECT p.*, pf.codigo AS perfil_codigo, m.codigo AS menu_codigo FROM permissoes p JOIN perfis pf ON pf.codperfil = p.codperfil JOIN menus m ON m.codmenu = p.codmenu')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!in_array($normPerfil((string)$row['perfil_codigo']), ['ADM DA EMPRESA', 'ADM EMPRESA'], true)) continue;
            $c = strtoupper((string)$row['menu_codigo']);
            if (isset($idEmpresa[$c]) && $idEmpresa[$c] > 0) $ref[$idEmpresa[$c]] = $row;
        }
        $rel['referencia'] = $refEmp['codigo'] . ': ' . count($ref) . ' menus no ADM DA EMPRESA';
    }
    foreach ($adm as $p) {
        $cod = (int)$p['codperfil']; $tinha = $existentes($cod);
        $novosModulo = 0; $completados = 0; $novosRef = 0;
        foreach ($menusModuloEmpresa as $menu => $mod) {
            $atual = $tinha[$menu] ?? null;
            $cheio = $atual !== null && !array_filter($flags, static fn($f) => (int)($atual[$f] ?? 0) !== 1);
            if ($cheio) continue;
            $atual === null ? $novosModulo++ : $completados++;
            if (!$simular) $gravar($cod, $menu, array_fill_keys($flags, 1));
        }
        foreach ($ref as $menu => $row) {
            if (isset($menusModuloEmpresa[$menu]) || isset($tinha[$menu])) continue;
            $novosRef++;
            if (!$simular) $gravar($cod, $menu, $row);
        }
        $rel['permissoes'][$p['codigo']] = ['antes' => count($tinha), 'modulos_novos' => $novosModulo, 'modulos_completados' => $completados, 'outros_da_referencia' => $novosRef, 'depois' => $simular ? null : count($existentes($cod))];
    }

    // 5. usuário
    if ($opc['usuario'] !== '') try {
        $s = $t->prepare('SELECT u.codusuario, u.userid, u.nome, u.ativo, pf.codigo AS perfil FROM usuarios u LEFT JOIN perfis pf ON pf.codperfil = u.codperfil WHERE u.userid = ?');
        $s->execute([$opc['usuario']]);
        $usr = $s->fetch(PDO::FETCH_ASSOC);
        $rel['usuario'] = $usr ? ['nome' => $usr['nome'], 'ativo' => (int)$usr['ativo'], 'perfil' => $usr['perfil'], 'perfil_e_adm_da_empresa' => in_array($normPerfil((string)$usr['perfil']), ['ADM DA EMPRESA', 'ADM EMPRESA'], true)] : 'não encontrado';
    } catch (Throwable $eu) { $rel['usuario'] = 'não foi possível conferir: ' . $eu->getMessage(); }
} catch (Throwable $e) { $rel['ok'] = false; $rel['erro'] = $e->getMessage(); }
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
