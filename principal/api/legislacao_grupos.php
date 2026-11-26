<?php
declare(strict_types=1);

/*
 * Grupos de leis (migração 419; Revisão G itens 33 e 34 — 26/11/2026). Botão "Grupos de leis" em Requisitos legais
 * (assets/js/legislacao-grupos.js), sempre a partir da linha selecionada (lei × unidade).
 *
 * GET  leg, unid                         → {ok, grupos:[{id,nome,palavras,qtd,membro}], pode_editar, csrf}
 * GET  acao=grupo, id                    → {ok, grupo, leis:[{leg,codigo}]}
 * POST acao=sugerir, leg                 → a IA gera {nome, palavras[]} a partir da lei (prompt LEGISLACAO_GRUPO_PALAVRAS)
 * POST acao=buscar, palavras (JSON)      → leis da empresa que contêm alguma palavra: [{leg,codigo,termos,padrao}]
 * POST acao=salvar, id?, nome, palavras, leis (JSON), origem → cria/atualiza o grupo e as leis dele
 * POST acao=excluir, id                  → apaga o grupo (as leis não mudam)
 * POST acao=vinculos, leg, unid          → vínculos da lei na unidade por área (licenças, medições, treinamentos, ...)
 * POST acao=compartilhar, id, leg, unid, areas[] → copia esses vínculos para as demais leis do grupo na mesma unidade
 * Permissão: Requisitos legais (REQUISITOS_LEGAIS) — visualizar para ler, editar para gravar. Escrita por sp_crud_*.
 */
require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';
require_once dirname(__DIR__, 2) . '/classes/IdiomaService.php';
require_once dirname(__DIR__, 2) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__, 2) . '/classes/InteligenciaArtificialDocumento.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$json = static function (array $d, int $status = 200): never { http_response_code($status); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
$T = static fn(string $c, array $p): string => IdiomaService::t('legislacao_grupos.' . $c, $p);
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $json(['ok' => false, 'message' => $T('sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.'])], 401);

// vínculos lei × item por área: [tabela, coluna do item, coluna da unidade, rótulo]
const LG_AREAS = [
    'LICENCAS' => ['legislacaoxlicencas', 'CODLICENCA', 'codunidade', ['pt' => 'Licenças', 'en' => 'Licenses', 'es' => 'Licencias']],
    'MEDICAO' => ['mm_medicaoxlegislacao', 'codmedicao', 'codunidade', ['pt' => 'Medições', 'en' => 'Measurements', 'es' => 'Mediciones']],
    'TREINAMENTOS' => ['legislacaoxtreinamentos', 'CODTREINAMENTO', 'CODUNIDADE', ['pt' => 'Treinamentos', 'en' => 'Training', 'es' => 'Capacitaciones']],
    'INFRAESTRUTURA' => ['legislacaoxinfraestruturas', 'CODINFRAESTRUTURA', 'CODUNIDADE', ['pt' => 'Infraestrutura', 'en' => 'Infrastructure', 'es' => 'Infraestructura']],
    'CONTROLES' => ['legislacaoxdocumentos', 'CODDOCUMENTO', 'codunidade', ['pt' => 'Controles', 'en' => 'Controls', 'es' => 'Controles']],
    'HOMOLOGACAO' => ['legislacaoxfornecedores', 'CODFORNECEDOR', 'CODUNIDADE', ['pt' => 'Fornecedores', 'en' => 'Suppliers', 'es' => 'Proveedores']],
];

try {
    $pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $json(['ok' => false, 'message' => $T('sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.'])], 401);
    $perm = PermissaoTransacao::obter('REQUISITOS_LEGAIS');
    if (empty($perm['pode_visualizar'])) $json(['ok' => false, 'message' => $T('sem_permissao', ['pt' => 'Sem permissão em Requisitos legais.', 'en' => 'No permission in Legal requirements.', 'es' => 'Sin permiso en Requisitos legales.'])], 403);
    $podeEditar = !empty($perm['pode_editar']);
    $uid = (int)($_SESSION['usuario']['codusuario'] ?? 0);
    $call = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = []; do { try { $r = array_merge($r, $s->fetchAll(PDO::FETCH_ASSOC)); } catch (Throwable) {} } while ($s->nextRowset()); $s->closeCursor(); return $r; };
    $sel = static fn(string $t, array $f, int $n = 1000, ?string $busca = null): array => $call('CALL sp_crud_select(:t,:f,:b,:n,0)', ['t' => $t, 'f' => json_encode($f ?: new stdClass(), JSON_UNESCAPED_UNICODE), 'b' => $busca, 'n' => $n]);
    $exato = static fn($v): array => ['value' => (string)$v, 'exact' => true];
    try { $sel('vw_legislacao_grupos', [], 1); } catch (Throwable) { $json(['ok' => false, 'message' => $T('sem_migracao', ['pt' => 'Grupos de leis ainda não instalados nesta empresa (migração 419).', 'en' => 'Law groups not installed (migration 419).', 'es' => 'Grupos de leyes no instalados (migración 419).'])], 503); }
    if (empty($_SESSION['legislacao_grupos_csrf'])) $_SESSION['legislacao_grupos_csrf'] = bin2hex(random_bytes(24));
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $acao = (string)($_POST['acao'] ?? $_GET['acao'] ?? '');

    if ($metodo === 'GET' && $acao === 'grupo') {
        $id = (int)($_GET['id'] ?? 0);
        $g = $sel('vw_legislacao_grupos', ['CODGRUPO' => $exato($id)], 1)[0] ?? null;
        if (!$g) $json(['ok' => false, 'message' => 'Grupo não encontrado.'], 404);
        $leis = array_map(static fn($r) => ['leg' => (int)$r['CODLEGISLACAO'], 'codigo' => (string)$r['CODIGO']], $sel('vw_legislacao_grupoxlei', ['CODGRUPO' => $exato($id)], 1000));
        usort($leis, static fn($a, $b) => strnatcasecmp($a['codigo'], $b['codigo']));
        $json(['ok' => true, 'grupo' => ['id' => (int)$g['CODGRUPO'], 'nome' => (string)$g['NOME'], 'palavras' => (string)($g['PALAVRAS_CHAVE'] ?? ''), 'origem' => (string)$g['ORIGEM']], 'leis' => $leis]);
    }
    if ($metodo === 'GET') {
        $leg = (int)($_GET['leg'] ?? 0);
        $membro = [];
        foreach ($sel('vw_legislacao_grupoxlei', ['CODLEGISLACAO' => $exato($leg)], 500) as $r) $membro[(int)$r['CODGRUPO']] = true;
        $grupos = array_map(static fn($g) => ['id' => (int)$g['CODGRUPO'], 'nome' => (string)$g['NOME'], 'palavras' => (string)($g['PALAVRAS_CHAVE'] ?? ''), 'qtd' => (int)$g['QTD_LEIS'], 'origem' => (string)$g['ORIGEM'], 'membro' => isset($membro[(int)$g['CODGRUPO']])], $sel('vw_legislacao_grupos', [], 1000));
        usort($grupos, static fn($a, $b) => [!$a['membro'], mb_strtolower($a['nome'])] <=> [!$b['membro'], mb_strtolower($b['nome'])]);
        $json(['ok' => true, 'grupos' => $grupos, 'pode_editar' => $podeEditar, 'csrf' => (string)$_SESSION['legislacao_grupos_csrf']]);
    }

    // POST
    if (!hash_equals((string)$_SESSION['legislacao_grupos_csrf'], (string)($_POST['csrf'] ?? ''))) $json(['ok' => false, 'message' => $T('csrf', ['pt' => 'A janela expirou: abra os Grupos de leis de novo.', 'en' => 'The window expired: open Law groups again.', 'es' => 'La ventana expiró: abra los Grupos de leyes de nuevo.'])], 419);
    $leg = (int)($_POST['leg'] ?? 0); $unid = (int)($_POST['unid'] ?? -1);

    if ($acao === 'sugerir') {
        $r = $sel('vw_modulolegal_requisitos_grid', ['CODLEGISLACAO' => $exato($leg)], 1)[0] ?? null;
        if (!$r) $json(['ok' => false, 'message' => 'Lei não encontrada nos requisitos.'], 404);
        $texto = 'CÓDIGO: ' . ($r['CODIGO'] ?? '') . "\nARTIGOS: " . ($r['ARTIGOS'] ?? '') . "\nASSUNTOS: " . ($r['DSCASSUNTO'] ?? '') . "\nREQUISITO: " . mb_substr((string)($r['PADRAO'] ?? ''), 0, 4000);
        $prompt = '';
        try { $prompt = trim((string)($call('CALL sp_ia_prompt_obter(:c,:i)', ['c' => 'LEGISLACAO_GRUPO_PALAVRAS', 'i' => (string)($_SESSION['idioma'] ?? 'pt')])[0]['prompt'] ?? '')); } catch (Throwable) {}
        if ($prompt === '') $prompt = 'Gere de 3 a 8 palavras-chave curtas (1 a 3 palavras) que identifiquem o TEMA da obrigação desta lei, para achar leis parecidas; evite palavras genéricas. Retorne só JSON: {"nome_grupo":"...","palavras":["..."],"confianca":0}.';
        $ia = InteligenciaArtificialDocumento::analisar($texto, $prompt, (string)($_SESSION['idioma'] ?? 'pt'));
        $ia = is_array($ia['sugestao'] ?? null) ? $ia['sugestao'] : $ia;
        $pal = array_values(array_unique(array_filter(array_map(static fn($p) => mb_substr(trim((string)$p), 0, 60), (array)($ia['palavras'] ?? [])), static fn($p) => mb_strlen($p) >= 3)));
        $json(['ok' => true, 'nome' => mb_substr(trim((string)($ia['nome_grupo'] ?? '')), 0, 150), 'palavras' => array_slice($pal, 0, 10)]);
    }
    if ($acao === 'buscar') {
        $pal = array_slice(array_values(array_filter(array_map(static fn($p) => trim((string)$p), (array)json_decode((string)($_POST['palavras'] ?? '[]'), true)), static fn($p) => mb_strlen($p) >= 3)), 0, 10);
        $achadas = [];
        foreach ($pal as $p) {
            foreach ($sel('vw_modulolegal_requisitos_grid', [], 1000, $p) as $r) {
                $l = (int)$r['CODLEGISLACAO'];
                $achadas[$l] ??= ['leg' => $l, 'codigo' => (string)($r['CODIGO'] ?? ''), 'termos' => [], 'padrao' => mb_strimwidth(trim((string)($r['PADRAO'] ?? '')), 0, 160, '…')];
                if (!in_array($p, $achadas[$l]['termos'], true)) $achadas[$l]['termos'][] = $p;
            }
        }
        $lista = array_values($achadas);
        usort($lista, static fn($a, $b) => [count($b['termos']), $a['codigo']] <=> [count($a['termos']), $b['codigo']]);
        $json(['ok' => true, 'leis' => array_slice($lista, 0, 300), 'total' => count($lista)]);
    }
    if (!$podeEditar) $json(['ok' => false, 'message' => $T('sem_editar', ['pt' => 'Seu perfil não pode alterar Requisitos legais.', 'en' => 'Your profile cannot edit Legal requirements.', 'es' => 'Su perfil no puede editar Requisitos legales.'])], 403);

    if ($acao === 'salvar') {
        $id = (int)($_POST['id'] ?? 0);
        $nome = mb_substr(trim((string)($_POST['nome'] ?? '')), 0, 150);
        if ($nome === '') $json(['ok' => false, 'message' => $T('nome', ['pt' => 'Informe o nome do grupo.', 'en' => 'Enter the group name.', 'es' => 'Informe el nombre del grupo.'])], 422);
        $leis = array_values(array_unique(array_filter(array_map('intval', (array)json_decode((string)($_POST['leis'] ?? '[]'), true)))));
        if (count($leis) < 2) $json(['ok' => false, 'message' => $T('minimo', ['pt' => 'Um grupo precisa de pelo menos 2 leis.', 'en' => 'A group needs at least 2 laws.', 'es' => 'Un grupo necesita al menos 2 leyes.'])], 422);
        $dados = ['NOME' => $nome, 'PALAVRAS_CHAVE' => mb_substr(trim((string)($_POST['palavras'] ?? '')), 0, 2000), 'ORIGEM' => ((string)($_POST['origem'] ?? '') === 'IA') ? 'IA' : 'MANUAL'];
        if ($id > 0) $call('CALL sp_crud_update(:t,:k,:d)', ['t' => 'legislacao_grupo', 'k' => json_encode(['CODGRUPO' => $id]), 'd' => json_encode($dados, JSON_UNESCAPED_UNICODE)]);
        else { $r = $call('CALL sp_crud_insert(:t,:d)', ['t' => 'legislacao_grupo', 'd' => json_encode($dados + ['CODUSUARIO' => $uid ?: null], JSON_UNESCAPED_UNICODE)]); $id = (int)($r[0]['chave_gerada'] ?? 0);
            if ($id <= 0) { $todos = $sel('vw_legislacao_grupos', ['NOME' => $exato($nome)], 50); usort($todos, static fn($a, $b) => (int)$b['CODGRUPO'] <=> (int)$a['CODGRUPO']); $id = (int)($todos[0]['CODGRUPO'] ?? 0); } }
        if ($id <= 0) throw new RuntimeException('Não foi possível obter o código do grupo.');
        $atuais = [];
        foreach ($sel('vw_legislacao_grupoxlei', ['CODGRUPO' => $exato($id)], 2000) as $r) $atuais[(int)$r['CODLEGISLACAO']] = (int)$r['CODGRUPOXLEI'];
        foreach ($atuais as $l => $k) if (!in_array($l, $leis, true)) $call('CALL sp_crud_delete(:t,:k)', ['t' => 'legislacao_grupoxlei', 'k' => json_encode(['CODGRUPOXLEI' => $k])]);
        foreach ($leis as $l) if (!isset($atuais[$l])) $call('CALL sp_crud_insert(:t,:d)', ['t' => 'legislacao_grupoxlei', 'd' => json_encode(['CODGRUPO' => $id, 'CODLEGISLACAO' => $l])]);
        try { $call('CALL sp_auditoria_registrar(:u,:t,:a,:en,:r,:res,:d)', ['u' => $uid, 't' => 'modulolegal_requisitoslegais', 'a' => 'GRUPO_LEIS_SALVAR', 'en' => 'legislacao_grupo', 'r' => (string)$id, 'res' => 'SUCESSO', 'd' => json_encode(['nome' => $nome, 'leis' => count($leis)])]); } catch (Throwable) {}
        $json(['ok' => true, 'id' => $id, 'message' => str_replace('{n}', (string)count($leis), $T('salvo', ['pt' => 'Grupo gravado com {n} leis.', 'en' => 'Group saved with {n} laws.', 'es' => 'Grupo guardado con {n} leyes.']))]);
    }
    if ($acao === 'excluir') {
        $id = (int)($_POST['id'] ?? 0);
        foreach ($sel('vw_legislacao_grupoxlei', ['CODGRUPO' => $exato($id)], 2000) as $r) $call('CALL sp_crud_delete(:t,:k)', ['t' => 'legislacao_grupoxlei', 'k' => json_encode(['CODGRUPOXLEI' => (int)$r['CODGRUPOXLEI']])]);
        $call('CALL sp_crud_delete(:t,:k)', ['t' => 'legislacao_grupo', 'k' => json_encode(['CODGRUPO' => $id])]);
        try { $call('CALL sp_auditoria_registrar(:u,:t,:a,:en,:r,:res,:d)', ['u' => $uid, 't' => 'modulolegal_requisitoslegais', 'a' => 'GRUPO_LEIS_EXCLUIR', 'en' => 'legislacao_grupo', 'r' => (string)$id, 'res' => 'SUCESSO', 'd' => '{}']); } catch (Throwable) {}
        $json(['ok' => true]);
    }
    // vínculos e compartilhamento (item 34)
    $vinculosDe = static function (int $l, int $u, string $area) use ($sel, $exato): array {
        [$t, , $cu] = LG_AREAS[$area];
        try { return $sel($t, ['CODLEGISLACAO' => $exato($l), $cu => $exato($u)], 1000); } catch (Throwable) { return []; }
    };
    if ($acao === 'vinculos') {
        $out = [];
        foreach (LG_AREAS as $area => [$t, $ci, , $rot]) $out[] = ['area' => $area, 'rotulo' => IdiomaService::t('legislacao_grupos.area.' . strtolower($area), $rot), 'qtd' => count($vinculosDe($leg, $unid, $area))];
        $json(['ok' => true, 'areas' => $out]);
    }
    if ($acao === 'compartilhar') {
        $id = (int)($_POST['id'] ?? 0);
        $areas = array_values(array_intersect(array_keys(LG_AREAS), (array)($_POST['areas'] ?? [])));
        $membros = array_map(static fn($r) => (int)$r['CODLEGISLACAO'], $sel('vw_legislacao_grupoxlei', ['CODGRUPO' => $exato($id)], 2000));
        if (!in_array($leg, $membros, true)) $json(['ok' => false, 'message' => 'A lei de origem não é do grupo.'], 422);
        // só as leis do grupo que são requisito nesta unidade
        $alvos = [];
        foreach ($membros as $l) if ($l !== $leg && $sel('legislacaoxempresa', ['CODLEGISLACAO' => $exato($l), 'codunidade' => $exato($unid)], 1)) $alvos[] = $l;
        $auto = static function (string $t) use ($pdo): ?string { $s = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND EXTRA LIKE '%auto_increment%'"); $s->execute([$t]); $c = $s->fetchColumn(); $s->closeCursor(); return $c !== false ? (string)$c : null; };
        $criados = 0; $jaTinham = 0;
        foreach ($areas as $area) {
            [$t, $ci, $cu] = LG_AREAS[$area];
            $origem = $vinculosDe($leg, $unid, $area); if (!$origem) continue;
            $pk = $auto($t);
            foreach ($origem as $o) {
                $chaveItem = null; foreach ($o as $k => $v) if (strcasecmp($k, $ci) === 0) { $chaveItem = $k; break; }
                if ($chaveItem === null) continue;
                foreach ($alvos as $l) {
                    if ($sel($t, ['CODLEGISLACAO' => $exato($l), $cu => $exato($unid), $ci => $exato($o[$chaveItem])], 1)) { $jaTinham++; continue; }
                    $novo = [];
                    foreach ($o as $k => $v) { if ($pk !== null && strcasecmp($k, $pk) === 0) continue; $novo[$k] = $v; }
                    foreach ($novo as $k => $v) if (strcasecmp($k, 'CODLEGISLACAO') === 0) $novo[$k] = $l;
                    $call('CALL sp_crud_insert(:t,:d)', ['t' => $t, 'd' => json_encode($novo, JSON_UNESCAPED_UNICODE)]);
                    $criados++;
                }
            }
        }
        try { $call('CALL sp_auditoria_registrar(:u,:t,:a,:en,:r,:res,:d)', ['u' => $uid, 't' => 'modulolegal_requisitoslegais', 'a' => 'GRUPO_LEIS_COMPARTILHAR', 'en' => 'legislacao_grupo', 'r' => $id . ':' . $leg . ':' . $unid, 'res' => 'SUCESSO', 'd' => json_encode(['areas' => $areas, 'alvos' => $alvos, 'criados' => $criados])]); } catch (Throwable) {}
        $json(['ok' => true, 'criados' => $criados, 'ja_tinham' => $jaTinham, 'leis' => count($alvos),
            'message' => str_replace(['{c}', '{l}', '{j}'], [(string)$criados, (string)count($alvos), (string)$jaTinham], $T('compartilhado', ['pt' => '{c} vínculo(s) criado(s) em {l} lei(s) do grupo nesta unidade ({j} já existiam).', 'en' => '{c} link(s) created in {l} group law(s) at this unit ({j} already existed).', 'es' => '{c} vínculo(s) creado(s) en {l} ley(es) del grupo en esta unidad ({j} ya existían).']))]);
    }
    $json(['ok' => false, 'message' => 'Ação inválida.'], 400);
} catch (Throwable $e) {
    $json(['ok' => false, 'message' => $T('erro', ['pt' => 'Não foi possível concluir.', 'en' => 'Could not complete.', 'es' => 'No fue posible concluir.']) . ' (' . mb_strimwidth($e->getMessage(), 0, 200, '…') . ')'], 500);
}
