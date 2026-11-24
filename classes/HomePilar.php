<?php
declare(strict_types=1);

require_once __DIR__ . '/IdiomaService.php';
require_once __DIR__ . '/HomeModulo.php';

/**
 * Homes de Controles, Treinamentos e Infraestrutura (24/11/2026, Homes por módulo — itens 83, 99 e 114 da Revisão G).
 * Uma só implementação para os três pilares (principal/home_controles.php, home_treinamentos.php, home_infraestrutura.php), no modelo
 * de classes/HomeModulo.php. Esses cadastros não têm datas: a Home mostra vínculo com requisitos, responsáveis, risco e a situação
 * legal de cada item (vw_sl_ev_<pilar>, a mesma do cálculo da situação dos requisitos — migração 284).
 * Dados (só leitura, sp_crud_select): grid do pilar, vw_<minhas>_grid (item × responsável: responsável direto, atribuições e regras
 * da 361), tabela de vínculo com os requisitos, vw_sl_ev_<pilar>, vw_ml_risco_itens, vw_modulolegal_requisitos_situacao_grid,
 * vw_unidades_grid e vw_modulolegal_responsaveis.
 * Quem tem acesso à tela do pilar vê todos os itens; quem só tem a tela "Minhas/Meus" vê só os itens de que é responsável.
 */
final class HomePilar
{
    public const AREAS = [
        'CONTROLES' => ['modulo' => 'CONTROLES', 'menu' => 'CONTROLE_DOCUMENTOS', 'minhas_menu' => 'MEUS_CONTROLES', 'grid' => 'vw_controle_documentos_grid',
            'minhas' => 'vw_meus_controles_grid', 'id' => 'CODDOCUMENTO', 'vinculo' => 'legislacaoxdocumentos', 'vinculo_id' => 'CODDOCUMENTO',
            'sl' => 'vw_sl_ev_controles', 'risco' => 'CONTROLES', 'tela' => 'modulolegal_controle.php', 'tela_minhas' => 'meus_controles.php', 'icone' => 'bi-file-earmark-check',
            'titulo_tela' => ['pt' => 'Controles', 'en' => 'Controls', 'es' => 'Controles'],
            'item' => ['pt' => 'Controle', 'en' => 'Control', 'es' => 'Control'], 'itens' => ['pt' => 'Controles (documentos)', 'en' => 'Controls (documents)', 'es' => 'Controles (documentos)']],
        'TREINAMENTOS' => ['modulo' => 'TREINAMENTOS', 'menu' => 'MODULOLEGAL_TREINAMENTOS', 'minhas_menu' => 'MEUS_TREINAMENTOS', 'grid' => 'vw_modulolegal_treinamentos_grid',
            'minhas' => 'vw_minhas_treinamentos_grid', 'id' => 'CODTREINAMENTO', 'vinculo' => 'legislacaoxtreinamentos', 'vinculo_id' => 'CODTREINAMENTO',
            'sl' => 'vw_sl_ev_treinamentos', 'risco' => 'TREINAMENTOS', 'tela' => 'modulolegal_treinamentos.php', 'tela_minhas' => 'meus_treinamentos.php', 'icone' => 'bi-mortarboard',
            'titulo_tela' => ['pt' => 'Treinamentos', 'en' => 'Trainings', 'es' => 'Capacitaciones'],
            'item' => ['pt' => 'Treinamento', 'en' => 'Training', 'es' => 'Capacitación'], 'itens' => ['pt' => 'Treinamentos', 'en' => 'Trainings', 'es' => 'Capacitaciones']],
        'INFRAESTRUTURA' => ['modulo' => 'INFRAESTRUTURA', 'menu' => 'MODULOLEGAL_INFRAESTRUTURA', 'minhas_menu' => 'MINHAS_INFRAESTRUTURAS', 'grid' => 'vw_modulolegal_infraestrutura_grid',
            'minhas' => 'vw_minhas_infraestrutura_grid', 'id' => 'CODINFRAESTRUTURA', 'vinculo' => 'legislacaoxinfraestruturas', 'vinculo_id' => 'CODINFRAESTRUTURA',
            'sl' => 'vw_sl_ev_infraestrutura', 'risco' => 'INFRAESTRUTURA', 'tela' => 'modulolegal_InfraEstrutura.php', 'tela_minhas' => 'minhas_infraestruturas.php', 'icone' => 'bi-building-gear',
            'titulo_tela' => ['pt' => 'Infraestrutura', 'en' => 'Infrastructure', 'es' => 'Infraestructura'],
            'item' => ['pt' => 'Infraestrutura', 'en' => 'Infrastructure', 'es' => 'Infraestructura'], 'itens' => ['pt' => 'Itens de infraestrutura', 'en' => 'Infrastructure items', 'es' => 'Ítems de infraestructura']],
    ];

    /** Pior situação primeiro (para a situação do item, que pode estar ligado a vários requisitos). */
    private const ORDEM_SIT = ['CRITICO' => 4, 'FALHA' => 3, 'ATENCAO' => 2, 'OK' => 1];

    private static function nomeItem(string $area, array $l): string
    {
        return match ($area) {
            'CONTROLES' => trim(trim((string)($l['CODIGO'] ?? '')) . (trim((string)($l['TITULO'] ?? '')) !== '' ? ' — ' . trim((string)$l['TITULO']) : '')),
            'TREINAMENTOS' => trim((string)($l['DSCTREINAMENTO'] ?? '')),
            default => trim((string)($l['DSCINFRAESTRUTURA'] ?? '')),
        };
    }

    public static function render(string $area): never
    {
        $cfg = self::AREAS[$area] ?? null;
        $home = HomeModulo::MENUS[$area] ?? null;
        $sair = static function (int $c, string $m): never { http_response_code($c); exit('<div style="font:13px sans-serif;padding:16px;color:#5f7268">' . htmlspecialchars($m, ENT_QUOTES, 'UTF-8') . '</div>'); };
        if (!$cfg || !$home) $sair(404, 'Home desconhecida.');
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $sair(401, IdiomaService::t('home.erro.sessao', ['pt' => 'Sessão expirada.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.']));
        require_once __DIR__ . '/GreenPortalDatabase.php';
        require_once __DIR__ . '/UsuarioSessao.php';
        require_once __DIR__ . '/PermissaoTransacao.php';
        $pdo = GreenPortalDatabase::connect(GreenPortalDatabase::env(), (string)$_SESSION['tenant']['banco_dados'], 'GPT');
        if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $sair(401, IdiomaService::t('home.erro.sessao', ['pt' => 'Sessão expirada.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.']));
        $idioma = IdiomaService::idioma();
        $tr = static fn(array $x): string => (string)($x[$idioma] ?? $x['pt']);
        $T = static fn(string $c, string $pt, string $en, string $es): string => HomeModulo::t('pilar.' . $c, $pt, $en, $es);
        $e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $uid = (int)($_SESSION['usuario']['codusuario'] ?? 0);
        $transacao = (string)$home['transacao'];

        $pHome = PermissaoTransacao::obter((string)$home['codigo']); $pTela = PermissaoTransacao::obter($cfg['menu']); $pMinhas = PermissaoTransacao::obter($cfg['minhas_menu']);
        if (empty($pHome['pode_visualizar']) && empty($pTela['pode_visualizar']) && empty($pMinhas['pode_visualizar']))
            $sair(403, $T('negado', 'Você não tem acesso a este módulo.', 'You do not have access to this module.', 'No tiene acceso a este módulo.'));
        $total = !empty($pTela['pode_visualizar']);
        $tela = $total ? $cfg['tela'] : $cfg['tela_minhas'];

        // ------------------------------------------------------------ dados
        $itens = HomeModulo::ler($pdo, $cfg['grid']);
        if ($itens === null) $sair(503, $T('sem_tela', 'O cadastro deste pilar não existe nesta empresa.', 'This register does not exist for this company.', 'Este registro no existe en esta empresa.'));
        $nomesU = HomeModulo::nomesUsuarios($pdo);
        $unidadesNome = []; foreach (HomeModulo::ler($pdo, 'vw_unidades_grid') ?? [] as $u) $unidadesNome[(int)$u['CODUNIDADE']] = trim((string)$u['DSCUNIDADE']);
        $resp = []; // item => [codusuario => true]
        foreach (HomeModulo::ler($pdo, $cfg['minhas'], [], 200000) ?? [] as $m) {
            $m = array_change_key_case($m, CASE_UPPER);
            if ((int)($m['CODUSUARIO'] ?? 0) > 0) $resp[(int)$m[$cfg['id']]][(int)$m['CODUSUARIO']] = true;
        }
        $vinc = []; // item => [ "lei|un" => [lei, un] ]
        foreach (HomeModulo::ler($pdo, $cfg['vinculo'], [], 200000) ?? [] as $v) {
            $v = array_change_key_case($v, CASE_UPPER);
            $vinc[(int)$v[$cfg['vinculo_id']]][(int)$v['CODLEGISLACAO'] . '|' . (int)$v['CODUNIDADE']] = [(int)$v['CODLEGISLACAO'], (int)$v['CODUNIDADE']];
        }
        $sl = []; // item => ["lei|un" => [status, motivo]]
        foreach (HomeModulo::ler($pdo, $cfg['sl'], [], 200000) ?? [] as $s) $sl[(int)$s['ITEM_ID']][(int)$s['CODLEGISLACAO'] . '|' . (int)$s['CODUNIDADE']] = [(string)$s['STATUS'], (string)$s['MOTIVO']];
        $risco = [];
        foreach (HomeModulo::ler($pdo, 'vw_ml_risco_itens', ['AREA' => ['value' => $cfg['risco'], 'exact' => true]]) ?? [] as $r) if ((string)$r['AREA'] === $cfg['risco']) $risco[(int)$r['ITEM_ID']] = (string)$r['CLASSE'];
        $reqs = []; // "lei|un" => linha do requisito (só os ligados)
        $precisa = [];
        foreach ($vinc as $vs) foreach ($vs as $k => $_) $precisa[$k] = true;
        if ($precisa) foreach (HomeModulo::consultar($pdo, 'vw_modulolegal_requisitos_situacao_grid', ['CODLEGISLACAO', 'CODUNIDADE', 'UNIDADE', 'CODIGO', 'DSCTIPO', 'NR', 'ANO', 'LEI_REVOGADA', 'SITUACAO_CONSOLIDADA']) ?? [] as $l) {
            $k = (int)$l['CODLEGISLACAO'] . '|' . (int)$l['CODUNIDADE'];
            if (isset($precisa[$k])) $reqs[$k] = $l;
        }

        $aviso = '';
        if (!$total) {
            $itens = array_values(array_filter($itens, static fn($l) => isset($resp[(int)$l[$cfg['id']]][$uid])));
            $aviso = $T('so_meus', 'Mostrando só os itens de que você é responsável.', 'Showing only the items you are responsible for.', 'Mostrando solo los ítems de los que usted es responsable.');
        }

        // ------------------------------------------------------------ filtros
        $f = ['u' => (string)($_GET['u'] ?? ''), 'r' => (string)($_GET['r'] ?? '')];
        $unidades = []; $responsaveis = [];
        foreach ($itens as $l) {
            $id = (int)$l[$cfg['id']];
            foreach ($vinc[$id] ?? [] as [, $un]) $unidades[(string)$un] = $unidadesNome[$un] ?? '#' . $un;
            foreach (array_keys($resp[$id] ?? []) as $u) $responsaveis[(string)$u] = $nomesU[$u] ?? '#' . $u;
        }
        asort($unidades, SORT_NATURAL | SORT_FLAG_CASE); asort($responsaveis, SORT_NATURAL | SORT_FLAG_CASE);
        $unidades = ['0' => $T('sem_vinculo', '(sem vínculo com requisito)', '(not linked to a requirement)', '(sin vínculo con requisito)')] + $unidades;
        $responsaveis = ['0' => $T('sem_resp', '(sem responsável)', '(no owner)', '(sin responsable)')] + $responsaveis;
        $lista = array_values(array_filter($itens, static function ($l) use ($f, $cfg, $vinc, $resp) {
            $id = (int)$l[$cfg['id']];
            if ($f['u'] !== '') {
                $uns = array_map(static fn($x) => (string)$x[1], $vinc[$id] ?? []);
                if ($f['u'] === '0' ? $uns !== [] : !in_array($f['u'], $uns, true)) return false;
            }
            if ($f['r'] !== '') {
                $rs = array_map('strval', array_keys($resp[$id] ?? []));
                if ($f['r'] === '0' ? $rs !== [] : !in_array($f['r'], $rs, true)) return false;
            }
            return true;
        }));

        // ------------------------------------------------------------ classificações
        $SIT = ['CRITICO' => [$T('s.vencido', 'Vencido', 'Expired', 'Vencido'), 'red'], 'FALHA' => [$T('s.falha', 'Falha', 'Failure', 'Falla'), 'red'],
            'ATENCAO' => [$T('s.atencao', 'Atenção', 'Attention', 'Atención'), 'orange'], 'OK' => [$T('s.ok', 'Ok', 'Ok', 'Ok'), 'green'],
            'SEM' => [$T('s.sem', 'Sem vínculo com requisito', 'Not linked', 'Sin vínculo'), 'gray']];
        $RISCO = ['CRITICO' => [$T('r.critico', 'Crítico', 'Critical', 'Crítico'), 'red'], 'ALTO' => [$T('r.alto', 'Alto', 'High', 'Alto'), 'orange'],
            'MODERADO' => [$T('r.moderado', 'Moderado', 'Moderate', 'Moderado'), 'yellow'], 'BAIXO' => [$T('r.baixo', 'Baixo', 'Low', 'Bajo'), 'green'],
            'NA' => [$T('r.na', 'Risco não avaliado', 'Risk not assessed', 'Riesgo no evaluado'), 'gray']];
        $abrir = static fn(int $id, string $nome): array => ['url' => $tela . '?' . http_build_query(['filtros' => json_encode([$cfg['id'] => $id])]),
            'title' => $tr($cfg['titulo_tela']) . ' - ' . mb_strimwidth($nome, 0, 70, '…')];
        $abrirReq = static fn(array $l): array => ['url' => 'modulolegal_requisitoslegais.php?' . http_build_query(['filtros' => json_encode(['CODLEGISLACAO' => (int)$l['CODLEGISLACAO'], 'CODUNIDADE' => (int)$l['CODUNIDADE'], 'LEI_REVOGADA' => strtoupper(trim((string)($l['LEI_REVOGADA'] ?? ''))) === 'SIM' ? 'SIM' : 'NAO'])]),
            'title' => $T('titulo_req', 'Requisitos legais', 'Legal requirements', 'Requisitos legales') . ' - ' . $l['CODIGO'] . ' · ' . $l['UNIDADE']];

        $cont = array_fill_keys(array_keys($SIT), 0); $contR = array_fill_keys(array_keys($RISCO), 0); $semResp = 0; $ligados = 0;
        $porUn = []; $porResp = []; $linhas = []; $rowsReq = [];
        $catUn = static fn(string $s): string => $s === 'CRITICO' ? 'FALHA' : $s; // vencido e falha juntos nas barras
        foreach ($lista as $l) {
            $id = (int)$l[$cfg['id']]; $nome = self::nomeItem($area, $l);
            $vs = $vinc[$id] ?? []; $rs = array_keys($resp[$id] ?? []);
            $pior = 'SEM'; $motivo = '';
            foreach ($vs as $k => [$lei, $un]) {
                [$st, $mt] = $sl[$id][$k] ?? ['', ''];
                if ($st !== '' && (self::ORDEM_SIT[$st] ?? 0) > (self::ORDEM_SIT[$pior] ?? 0)) { $pior = $st; $motivo = $mt; }
                $req = $reqs[$k] ?? null;
                if ($req) $rowsReq[] = ['cells' => [
                    'ITEM' => ['text' => $nome, 'title' => $nome], 'CODIGO' => (string)$req['CODIGO'], 'UNIDADE' => (string)$req['UNIDADE'],
                    'NORMA' => trim($req['DSCTIPO'] . ' ' . $req['NR'] . '/' . $req['ANO']),
                    'STATUS' => $st === '' ? '' : ['text' => $SIT[$st][0] ?? $st, 'html' => '<span class="hm-sit tom-' . ($SIT[$st][1] ?? 'gray') . '">' . $e($SIT[$st][0] ?? $st) . '</span>', 'sort' => (string)(9 - (self::ORDEM_SIT[$st] ?? 0))],
                    'MOTIVO' => ['text' => $mt, 'title' => $mt], 'SITREQ' => (string)$req['SITUACAO_CONSOLIDADA'],
                ], 'open' => $abrirReq($req), 'grupos' => HomeModulo::grupo('I', $id) . ' ' . HomeModulo::grupo('ST', $st === '' ? 'NA' : $st)];
            }
            if ($vs && $pior === 'SEM') $pior = 'OK'; // ligado, mas ainda sem cálculo: não é "sem vínculo"
            if ($vs) $ligados++;
            $rk = $risco[$id] ?? 'NA'; if (!isset($RISCO[$rk])) $rk = 'NA';
            $cont[$pior]++; $contR[$rk]++;
            $g = [HomeModulo::grupo('S', $pior), HomeModulo::grupo('K', $rk), $vs ? 'LIG' : 'NLIG'];
            if (in_array($pior, ['CRITICO', 'FALHA'], true)) $g[] = 'S_FALHAS';
            if (in_array($rk, ['CRITICO', 'ALTO'], true)) $g[] = 'K_CA';
            foreach ($rs ?: [0] as $u) { $g[] = HomeModulo::grupo('R', $u); $porResp[$u]['n'] = ($porResp[$u]['n'] ?? 0) + 1; $porResp[$u][$catUn($pior)] = ($porResp[$u][$catUn($pior)] ?? 0) + 1; }
            if (!$rs) $semResp++;
            $uns = array_unique(array_map(static fn($x) => $x[1], $vs));
            foreach ($uns ?: [0] as $un) { $g[] = HomeModulo::grupo('U', $un); $porUn[$un]['n'] = ($porUn[$un]['n'] ?? 0) + 1; $porUn[$un][$catUn($pior)] = ($porUn[$un][$catUn($pior)] ?? 0) + 1; }
            $nomesResp = implode(', ', array_map(static fn($u) => $nomesU[$u] ?? '#' . $u, $rs));
            $nomesUn = implode(', ', array_map(static fn($u) => $unidadesNome[$u] ?? '#' . $u, $uns));
            $linhas[] = ['cells' => [
                'ITEM' => ['text' => $nome, 'title' => $nome],
                'SITUACAO' => ['text' => $SIT[$pior][0], 'html' => '<span class="hm-sit tom-' . $SIT[$pior][1] . '" title="' . $e($motivo) . '">' . $e($SIT[$pior][0]) . '</span>', 'sort' => (string)(9 - (self::ORDEM_SIT[$pior] ?? 0))],
                'MOTIVO' => ['text' => $motivo, 'title' => $motivo],
                'RISCO' => ['text' => $RISCO[$rk][0], 'html' => '<span class="hm-sit tom-' . $RISCO[$rk][1] . '">' . $e($RISCO[$rk][0]) . '</span>', 'sort' => $RISCO[$rk][0]],
                'REQUISITOS' => ['text' => (string)count($vs), 'sort' => sprintf('%06d', count($vs))],
                'UNIDADES' => ['text' => $nomesUn, 'title' => $nomesUn],
                'RESPONSAVEIS' => ['text' => $nomesResp, 'title' => $nomesResp],
            ], 'open' => $abrir($id, $nome), 'grupos' => implode(' ', $g)];
        }
        $G = 'hp' . ucfirst(strtolower($area));
        $gridLista = HomeModulo::grid($G, [
            'ITEM' => ['label' => $tr($cfg['item']), 'width' => '320px'],
            'SITUACAO' => ['label' => $T('col.situacao', 'Situação legal', 'Legal status', 'Situación legal'), 'filter' => 'select', 'width' => '150px'],
            'MOTIVO' => ['label' => $T('col.motivo', 'Motivo', 'Reason', 'Motivo'), 'width' => '260px'],
            'RISCO' => ['label' => $T('col.risco', 'Risco', 'Risk', 'Riesgo'), 'filter' => 'select', 'width' => '130px'],
            'REQUISITOS' => ['label' => $T('col.requisitos', 'Requisitos ligados', 'Linked requirements', 'Requisitos vinculados'), 'width' => '110px'],
            'UNIDADES' => ['label' => $T('col.unidades', 'Unidades', 'Units', 'Unidades'), 'width' => '200px'],
            'RESPONSAVEIS' => ['label' => $T('col.responsaveis', 'Responsáveis', 'Owners', 'Responsables'), 'width' => '220px'],
        ], $linhas, ['transacao' => $transacao, 'ordem' => ['SITUACAO', 'asc'], 'por_pagina' => 50,
            'vazio' => $T('lista.vazia', 'Nenhum item com estes filtros.', 'No item with these filters.', 'Ningún ítem con estos filtros.')]);
        $gridReq = HomeModulo::grid($G . 'Req', [
            'ITEM' => ['label' => $tr($cfg['item']), 'width' => '260px'],
            'CODIGO' => ['label' => $T('col.codigo', 'Código', 'Code', 'Código'), 'width' => '90px'],
            'NORMA' => ['label' => $T('col.norma', 'Norma', 'Regulation', 'Norma'), 'width' => '220px'],
            'UNIDADE' => ['label' => $T('col.unidade', 'Unidade', 'Unit', 'Unidad'), 'filter' => 'select', 'width' => '140px'],
            'STATUS' => ['label' => $T('col.sit_item', 'Situação do item', 'Item status', 'Situación del ítem'), 'filter' => 'select', 'width' => '120px'],
            'MOTIVO' => ['label' => $T('col.motivo', 'Motivo', 'Reason', 'Motivo'), 'width' => '260px'],
            'SITREQ' => ['label' => $T('col.sit_req', 'Situação do requisito', 'Requirement status', 'Situación del requisito'), 'filter' => 'select', 'width' => '160px'],
        ], $rowsReq, ['transacao' => $transacao, 'ordem' => ['STATUS', 'asc'], 'por_pagina' => 50,
            'vazio' => $T('req.vazio', 'Nenhum item ligado a requisitos legais.', 'No item linked to legal requirements.', 'Ningún ítem vinculado a requisitos legales.')]);

        // ------------------------------------------------------------ gráficos
        $CAT = ['FALHA' => [$T('cat.falha', 'Falha / vencido', 'Failure / expired', 'Falla / vencido'), 'red'], 'ATENCAO' => [$SIT['ATENCAO'][0], 'orange'], 'OK' => [$SIT['OK'][0], 'green'], 'SEM' => [$SIT['SEM'][0], 'gray']];
        $partes = static function (array $c) use ($CAT): array { $o = []; foreach ($CAT as $k => [$rot, $tom]) if (($c[$k] ?? 0) > 0) $o[] = ['n' => $c[$k], 'tom' => $tom, 'rotulo' => $rot]; return $o; };
        $barSit = []; foreach ($SIT as $k => [$rot, $tom]) $barSit[] = ['rotulo' => $rot, 'n' => $cont[$k], 'grupo' => 'S_' . $k, 'tom' => $tom];
        $barRisco = []; foreach ($RISCO as $k => [$rot, $tom]) $barRisco[] = ['rotulo' => $rot, 'n' => $contR[$k], 'grupo' => 'K_' . $k, 'tom' => $tom];
        uasort($porUn, static fn($a, $b) => $b['n'] <=> $a['n']);
        $barUn = []; foreach ($porUn as $k => $c) $barUn[] = ['rotulo' => (int)$k === 0 ? $unidades['0'] : ($unidadesNome[(int)$k] ?? '#' . $k), 'n' => $c['n'], 'grupo' => HomeModulo::grupo('U', $k), 'partes' => $partes($c)];
        uasort($porResp, static fn($a, $b) => $b['n'] <=> $a['n']);
        $barResp = []; foreach ($porResp as $k => $c) $barResp[] = ['rotulo' => (int)$k === 0 ? $responsaveis['0'] : ($nomesU[(int)$k] ?? '#' . $k), 'n' => $c['n'], 'grupo' => HomeModulo::grupo('R', $k), 'partes' => $partes($c)];
        $leg = HomeModulo::legenda(array_combine(array_column($CAT, 1), array_column($CAT, 0)));
        $dica = $T('g.dica', 'Clique numa barra para ver os itens na Lista.', 'Click a bar to see the items in the List.', 'Haga clic en una barra para ver los ítems en la Lista.');
        $graficos = '<div class="hm-graficos">'
            . HomeModulo::grafico($T('g.situacao', 'Situação legal dos itens', 'Legal status of the items', 'Situación legal de los ítems'), 'bi-shield-check', HomeModulo::barras($G, $barSit),
                $T('g.sit_nota', 'A pior situação do item entre os requisitos a que ele está ligado (a mesma regra do cálculo da situação dos requisitos).', 'The worst status of the item among the requirements it is linked to.', 'La peor situación del ítem entre los requisitos vinculados.'))
            . HomeModulo::grafico($T('g.risco', 'Risco', 'Risk', 'Riesgo'), 'bi-exclamation-triangle', HomeModulo::barras($G, $barRisco), $dica)
            . HomeModulo::grafico($T('g.unidade', 'Por unidade (dos requisitos ligados)', 'By unit (of the linked requirements)', 'Por unidad (de los requisitos vinculados)'), 'bi-buildings', HomeModulo::barras($G, $barUn, 15) . $leg)
            . HomeModulo::grafico($T('g.responsavel', 'Por responsável', 'By owner', 'Por responsable'), 'bi-person', HomeModulo::barras($G, $barResp, 12) . $leg,
                $T('g.resp_nota', 'Responsável do registro, atribuições e regras de Responsáveis e avisos. Um item com vários responsáveis conta para cada um.', 'Record owner, assignments and rules. An item with several owners counts for each.', 'Responsable del registro, atribuciones y reglas. Un ítem con varios responsables cuenta para cada uno.'))
            . '</div>';

        // ------------------------------------------------------------ cartões
        $falhas = $cont['CRITICO'] + $cont['FALHA'];
        $cards = [
            ['rotulo' => $tr($cfg['itens']), 'valor' => count($lista), 'icone' => $cfg['icone'], 'tom' => 'blue', 'grupo' => '', 'extra' => $ligados . ' ' . $T('c.ligados', 'ligado(s) a requisitos', 'linked to requirements', 'vinculado(s) a requisitos')],
            ['rotulo' => $T('c.falha', 'Falha ou vencido', 'Failure or expired', 'Falla o vencido'), 'valor' => $falhas, 'icone' => 'bi-x-octagon', 'tom' => $falhas ? 'red' : 'green', 'grupo' => 'S_FALHAS'],
            ['rotulo' => $SIT['ATENCAO'][0], 'valor' => $cont['ATENCAO'], 'icone' => 'bi-exclamation-circle', 'tom' => 'orange', 'grupo' => 'S_ATENCAO'],
            ['rotulo' => $SIT['OK'][0], 'valor' => $cont['OK'], 'icone' => 'bi-check-circle', 'tom' => 'green', 'grupo' => 'S_OK'],
            ['rotulo' => $SIT['SEM'][0], 'valor' => $cont['SEM'], 'icone' => 'bi-link-45deg', 'tom' => 'gray', 'grupo' => 'NLIG'],
            ['rotulo' => $T('c.sem_resp', 'Sem responsável', 'No owner', 'Sin responsable'), 'valor' => $semResp, 'icone' => 'bi-person-x', 'tom' => $semResp ? 'orange' : 'green', 'grupo' => 'R_0'],
            ['rotulo' => $T('c.risco_ca', 'Risco crítico ou alto', 'Critical or high risk', 'Riesgo crítico o alto'), 'valor' => $contR['CRITICO'] + $contR['ALTO'], 'icone' => 'bi-exclamation-octagon', 'tom' => 'red', 'grupo' => 'K_CA'],
            ['rotulo' => $RISCO['NA'][0], 'valor' => $contR['NA'], 'icone' => 'bi-question-circle', 'tom' => 'gray', 'grupo' => 'K_NA'],
        ];
        $filtrosHtml = HomeModulo::filtros([
            'u' => ['rotulo' => $T('f.unidade', 'Unidade', 'Unit', 'Unidad'), 'todos' => $T('f.todas', 'Todas', 'All', 'Todas'), 'opcoes' => $unidades],
            'r' => ['rotulo' => $T('f.responsavel', 'Responsável', 'Owner', 'Responsable'), 'todos' => $T('f.todos', 'Todos', 'All', 'Todos'), 'opcoes' => $responsaveis],
        ], $f);
        $abas = DashboardGrid::subabas($G . 'Abas', [
            ['id' => 'graficos', 'icone' => 'bi-bar-chart', 'rotulo' => $T('sub.graficos', 'Gráficos', 'Charts', 'Gráficos'), 'html' => $graficos],
            ['id' => 'lista', 'icone' => 'bi-table', 'rotulo' => $T('sub.lista', 'Lista', 'List', 'Lista'), 'contagem' => count($lista), 'html' => $gridLista],
            ['id' => 'requisitos', 'icone' => 'bi-journal-text', 'rotulo' => $T('sub.requisitos', 'Requisitos ligados', 'Linked requirements', 'Requisitos vinculados'), 'contagem' => count($rowsReq), 'html' => $gridReq],
        ], true);

        $theme = in_array((string)($_SESSION['layout_tema'] ?? ''), ['verde', 'azul', 'cinza'], true) ? (string)$_SESSION['layout_tema'] : 'verde';
        session_write_close();
        $titulo = $tr($home['nome']);
        $desc = $T('desc', 'Situação legal, risco e responsáveis dos itens deste pilar. Os filtros do topo valem para a página toda; clique num cartão ou numa barra do gráfico para listar os itens. Duplo clique na linha abre o cadastro (na aba Requisitos ligados, abre o requisito).',
            'Legal status, risk and owners of the items of this pillar. The filters at the top apply to the whole page; click a card or a chart bar to list the items. Double-click a row to open it.',
            'Situación legal, riesgo y responsables de los ítems de este pilar. Los filtros valen para toda la página; haga clic en una tarjeta o una barra para listar. Doble clic abre el registro.');
        echo '<!doctype html><html lang="' . ($idioma === 'pt' ? 'pt-BR' : $e($idioma)) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $e($titulo) . '</title><link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">';
        HomeModulo::head();
        echo '</head><body class="theme-' . $e($theme) . '" data-transacao="' . $e($transacao) . '"><main class="hm">'
            . HomeModulo::cabecalho($cfg['icone'], $titulo, $e($desc), $aviso) . $filtrosHtml . HomeModulo::cartoes($G, $cards) . $abas . '</main>';
        HomeModulo::scripts();
        echo '</body></html>';
        exit;
    }
}
