<?php
declare(strict_types=1);

/*
 * Responsáveis e avisos (migração 361; item 4 do 01_Mudancas_Gerais, GER-3 — LIC-50/51, MM-72/78, CON-91/94, TRE-106/109, INF-121/124).
 *
 * ?area=LICENCAS|MEDICAO|CONTROLES|TREINAMENTOS|INFRAESTRUTURA            regras do módulo (menu "Responsáveis e avisos (<módulo>)",
 *                                                                          entradas responsaveis_regras_<área>.php)
 * ?area=...&registro=<código>                                              responsáveis de um registro só (botão "Responsáveis" da grade)
 *
 * Regra = Tipo (Licenças: Tipo documental; Medição: Tipo de medição) e/ou Unidade → usuário ou grupo, com os avisos escolhidos
 * (prazos, atribuição, alteração). Dinâmica: vale na hora para registros novos e antigos; nada é copiado para o registro; soma
 * com o Responsável do registro e com as atribuições. Grupo: valem os usuários ativos do grupo.
 * Permissão: a do menu da 361 (módulo) ou, no modo registro, também a da tela do módulo / tela Minhas.
 * Leitura por sp_crud_select (tabela resp_regras e views vw_resp_*); gravação por sp_crud_insert / sp_crud_update / sp_crud_delete.
 */
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$sair = static function (int $c, string $m): never { http_response_code($c); exit(htmlspecialchars($m, ENT_QUOTES, 'UTF-8')); };
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $sair(401, IdiomaService::t('home.erro.sessao', ['pt' => 'Sessão expirada.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.']));
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/AuditService.php';
require_once dirname(__DIR__) . '/classes/ResponsaveisRegras.php';
$pdo = GreenPortalDatabase::connect(GreenPortalDatabase::env(), (string)$_SESSION['tenant']['banco_dados'], 'GPT');
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $sair(401, 'Sessão expirada.');
$idioma = IdiomaService::idioma();
$t = static fn(string $c, string $pt, string $en, string $es): string => IdiomaService::t('resp_regras.' . $c, ['pt' => $pt, 'en' => $en, 'es' => $es]);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$tr = static fn(?array $x): string => $x ? (string)($x[$idioma] ?? $x['pt']) : '';

$area = strtoupper(trim((string)($_GET['area'] ?? '')));
if (!isset(ResponsaveisRegras::AREAS[$area])) $sair(404, $t('area_invalida', 'Módulo inválido.', 'Invalid module.', 'Módulo inválido.'));
$cfg = ResponsaveisRegras::AREAS[$area];
$registro = filter_var($_GET['registro'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$modoRegistro = $registro > 0;

// Permissão: a do menu da 361; no modo registro, também a da tela do módulo e da tela Minhas (o botão está nessas grades)
$perm = PermissaoTransacao::obter($cfg['menu_regras']);
if ($modoRegistro) foreach (array_unique([$cfg['menu'], $cfg['menu_minhas']]) as $m) foreach (PermissaoTransacao::obter($m) as $k => $v) $perm[$k] = !empty($perm[$k]) || !empty($v);
if (empty($perm['pode_visualizar'])) $sair(403, $t('negado', 'Perfil sem permissão para ver os responsáveis deste módulo.', 'Your profile cannot see the people responsible for this module.', 'Su perfil no puede ver los responsables de este módulo.'));
if (!ResponsaveisRegras::disponivel($pdo)) $sair(503, $t('sem_migracao', 'Responsáveis e avisos ainda não foram ativados nesta empresa (migração 361).', 'People responsible and alerts are not active for this company yet (migration 361).', 'Responsables y avisos aún no fueron activados en esta empresa (migración 361).'));
AuditService::record($pdo, 'responsaveis_regras', 'ACESSAR', 'SUCESSO', 'resp_regras', $area . ($modoRegistro ? ':' . $registro : ''));
$eu = (int)($_SESSION['usuario']['codusuario'] ?? 0);

// ---------------------------------------------------------------- leitura (sp_crud_select em lotes de 1000; filtro conferido aqui)
$ler = static function (string $view, array $filtros = [], int $max = 20000) use ($pdo): array {
    if (!ResponsaveisRegras::existe($pdo, $view)) return [];
    $cols = ResponsaveisRegras::colunas($pdo, $view);
    $f = [];
    foreach ($filtros as $k => $v) if (isset($cols[strtolower($k)])) $f[$cols[strtolower($k)]] = $v;
    $out = [];
    for ($off = 0; $off < $max; $off += 1000) {
        $s = $pdo->prepare('CALL sp_crud_select(?, ?, NULL, 1000, ?)');
        $s->execute([$view, $f ? json_encode($f, JSON_UNESCAPED_UNICODE) : null, $off]);
        $lote = array_map('array_change_key_case', $s->fetchAll(PDO::FETCH_ASSOC)); $s->closeCursor();
        array_push($out, ...$lote);
        if (count($lote) < 1000) break;
    }
    foreach ($filtros as $k => $v) $out = array_values(array_filter($out, static fn($r) => (string)($r[strtolower($k)] ?? '') === (string)$v));
    return $out;
};
$crud = static function (string $sql, array $p) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };

$item = null;
if ($modoRegistro) {
    $item = $ler('vw_resp_regras_itens', ['AREA' => $area, 'REGISTRO_ID' => $registro])[0] ?? null;
    if (!$item) $sair(404, $t('registro_inexistente', 'Registro não encontrado (ou é uma versão antiga da licença).', 'Record not found (or it is an old license version).', 'Registro no encontrado (o es una versión antigua de la licencia).'));
}
// listas
$usuarios = [];
// pessoas da empresa (vw_resp_regras_pessoas: ativos, sem as contas de fornecedor); antes da view, todos os usuários ativos
foreach (ResponsaveisRegras::existe($pdo, 'vw_resp_regras_pessoas') ? $ler('vw_resp_regras_pessoas') : (ResponsaveisRegras::existe($pdo, 'vw_responsabilidade_usuarios_ativos') ? $ler('vw_responsabilidade_usuarios_ativos') : $ler('usuarios', ['ativo' => 1])) as $u)
    $usuarios[(int)$u['codusuario']] = trim((string)($u['nome'] ?? '')) ?: (string)($u['userid'] ?? $u['codusuario']);
$grupos = [];
foreach (ResponsaveisRegras::existe($pdo, 'vw_responsabilidade_grupos_ativos') ? $ler('vw_responsabilidade_grupos_ativos') : $ler('grupos', ['ativo' => 1]) as $g)
    $grupos[(int)$g['codgrupo']] = (string)($g['nome'] ?? $g['codgrupo']);
$unidades = [];
foreach ($ler('unidades') as $un) $unidades[(int)($un['codunidade'] ?? 0)] = (string)($un['dscunidade'] ?? '');
unset($unidades[0]);
$tipos = [];
if ($cfg['tipo'] && ResponsaveisRegras::existe($pdo, $cfg['tipo'][1]))
    foreach ($ler($cfg['tipo'][1]) as $x) $tipos[(int)($x[strtolower($cfg['tipo'][2])] ?? 0)] = (string)($x[strtolower($cfg['tipo'][3])] ?? '');
unset($tipos[0]);
foreach ([&$usuarios, &$grupos, &$unidades, &$tipos] as &$lista) asort($lista, SORT_NATURAL | SORT_FLAG_CASE);
unset($lista);
$temTipo = $cfg['tipo'] !== null;
$temPrazo = (bool)$cfg['prazos'];
$temAlteracao = (bool)$cfg['historico'] && ResponsaveisRegras::existe($pdo, 'historico_alteracoes');

$carregar = static function () use ($ler, $area, $registro, $modoRegistro): array {
    return array_values(array_filter($ler('resp_regras', ['AREA' => $area]), static fn($r) => $modoRegistro ? (int)($r['registro_id'] ?? 0) === $registro : ($r['registro_id'] ?? null) === null));
};
$regras = $carregar();

// ---------------------------------------------------------------- gravação
if (empty($_SESSION['resp_regras_csrf'])) $_SESSION['resp_regras_csrf'] = bin2hex(random_bytes(16));
$msg = ''; $erros = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['acao'] ?? '') === 'salvar') {
    if (!hash_equals((string)$_SESSION['resp_regras_csrf'], (string)($_POST['csrf'] ?? ''))) $sair(400, $t('csrf', 'A página expirou. Abra de novo e grave.', 'The page expired. Open it again and save.', 'La página expiró. Ábrala de nuevo y guarde.'));
    if (empty($perm['pode_incluir']) && empty($perm['pode_editar']) && empty($perm['pode_excluir'])) $sair(403, $t('sem_gravar', 'Perfil sem permissão para alterar responsáveis.', 'Your profile cannot change people responsible.', 'Su perfil no puede cambiar responsables.'));
    $existentes = [];
    foreach ($regras as $r) $existentes[(int)$r['codregra']] = $r;
    $ignorados = 0; $feitos = 0;
    foreach ((array)($_POST['rows'] ?? []) as $chave => $row) {
        $row = (array)$row;
        $id = ctype_digit((string)$chave) ? (int)$chave : 0;
        if ($id && !isset($existentes[$id])) continue; // regra de outro módulo / registro: nunca mexe
        if (!empty($row['_delete'])) {
            if (!$id) continue;
            if (empty($perm['pode_excluir'])) { $ignorados++; continue; }
            $crud('CALL sp_crud_delete(?, ?)', ['resp_regras', json_encode(['CODREGRA' => $id])]); $feitos++;
            continue;
        }
        if ($id ? empty($perm['pode_editar']) : empty($perm['pode_incluir'])) { $ignorados++; continue; }
        $antes = $id ? $existentes[$id] : [];
        $destino = strtoupper((string)($row['TIPO_DESTINO'] ?? 'USUARIO')) === 'GRUPO' ? 'GRUPO' : 'USUARIO';
        $usu = (int)($row['CODUSUARIO'] ?? 0); $gru = (int)($row['CODGRUPO'] ?? 0);
        $tipo = (int)($row['CODTIPO'] ?? 0); $uni = (int)($row['CODUNIDADE'] ?? 0);
        // valores válidos: os das listas, ou o que a regra já tinha (usuário/grupo que ficou inativo, tipo/unidade excluídos)
        $vale = static fn(int $v, array $lista, string $campo): bool => $v > 0 && (isset($lista[$v]) || (int)($antes[$campo] ?? 0) === $v);
        $linha = $id ? '#' . $id : $t('nova', 'nova regra', 'new rule', 'nueva regla');
        if ($destino === 'USUARIO' && !$vale($usu, $usuarios, 'codusuario')) { $erros[] = $linha . ': ' . $t('falta_usuario', 'escolha o usuário.', 'choose the user.', 'elija el usuario.'); continue; }
        if ($destino === 'GRUPO' && !$vale($gru, $grupos, 'codgrupo')) { $erros[] = $linha . ': ' . $t('falta_grupo', 'escolha o grupo.', 'choose the group.', 'elija el grupo.'); continue; }
        if ($tipo && (!$temTipo || !$vale($tipo, $tipos, 'codtipo'))) $tipo = 0;
        if ($uni && !$vale($uni, $unidades, 'codunidade')) $uni = 0;
        $dados = [
            'CODTIPO' => $tipo ?: null, 'CODUNIDADE' => $uni ?: null, 'TIPO_DESTINO' => $destino,
            'CODUSUARIO' => $destino === 'USUARIO' ? $usu : null, 'CODGRUPO' => $destino === 'GRUPO' ? $gru : null,
            'AVISO_PRAZO' => $temPrazo && !empty($row['AVISO_PRAZO']) ? 1 : 0, 'AVISO_ATRIBUICAO' => !empty($row['AVISO_ATRIBUICAO']) ? 1 : 0,
            'AVISO_ALTERACAO' => $temAlteracao && !empty($row['AVISO_ALTERACAO']) ? 1 : 0, 'ATIVO' => !empty($row['ATIVO']) ? 1 : 0,
            'OBSERVACAO' => mb_substr(trim((string)($row['OBSERVACAO'] ?? '')), 0, 500) ?: null,
        ];
        try {
            if ($id) {
                $mudou = false;
                foreach ($dados as $k => $v) if ((string)($antes[strtolower($k)] ?? '') !== (string)($v ?? '')) $mudou = true;
                if (!$mudou) continue;
                $crud('CALL sp_crud_update(?, ?, ?)', ['resp_regras', json_encode(['CODREGRA' => $id]), json_encode($dados + ['CODUSUARIOALTERACAO' => $eu ?: null], JSON_UNESCAPED_UNICODE)]);
            } else {
                $crud('CALL sp_crud_insert(?, ?)', ['resp_regras', json_encode(['AREA' => $area, 'REGISTRO_ID' => $modoRegistro ? $registro : null, 'CODUSUARIO_CRIACAO' => $eu ?: null] + $dados, JSON_UNESCAPED_UNICODE)]);
            }
            $feitos++;
        } catch (PDOException $x) {
            $erros[] = $linha . ': ' . ((string)$x->getCode() === '23000'
                ? $t('repetida', 'já existe uma regra igual (mesmo tipo, unidade e responsável).', 'an identical rule already exists (same type, unit and person).', 'ya existe una regla igual (mismo tipo, unidad y responsable).')
                : IdiomaService::erro($x, 'gravar'));
        }
    }
    $msg = $feitos ? $t('gravado', 'Responsáveis gravados. Valem na hora para os registros que se encaixam.', 'Saved. They apply right away to the matching records.', 'Guardado. Valen de inmediato para los registros que coinciden.') : ($erros ? '' : $t('nada', 'Nada mudou.', 'Nothing changed.', 'Nada cambió.'));
    if ($ignorados) $msg .= ' ' . $t('ignorados', 'Alterações sem permissão do seu perfil foram ignoradas.', 'Changes your profile is not allowed to make were ignored.', 'Los cambios sin permiso de su perfil fueron ignorados.');
    AuditService::record($pdo, 'responsaveis_regras', 'ALTERAR', $erros ? 'PARCIAL' : 'SUCESSO', 'resp_regras', $area . ($modoRegistro ? ':' . $registro : ''), ['gravadas' => $feitos, 'erros' => count($erros)]);
    $regras = $carregar();
}
$csrf = (string)$_SESSION['resp_regras_csrf'];

// cobertura e (modo registro) quem responde hoje
$cobertura = [];
foreach ($ler('vw_resp_regras_cobertura') as $c) $cobertura[(int)$c['codregra']] = $c;
$quem = [];
if ($modoRegistro) {
    $regraDoRegistro = [];
    foreach ($ler('vw_resp_regras_registros', ['AREA' => $area, 'REGISTRO_ID' => $registro]) as $r) $regraDoRegistro[(int)$r['codusuario']][] = (int)$r['codregra'];
    $todasRegras = [];
    foreach ($ler('resp_regras', ['AREA' => $area]) as $r) $todasRegras[(int)$r['codregra']] = $r;
    foreach ($ler('vw_resp_acesso', ['AREA' => $area, 'REGISTRO_ID' => $registro]) as $a) {
        $u = (int)$a['codusuario_acesso'];
        $o = strtoupper((string)$a['origem']);
        if ($o === 'REGRA') {
            foreach ($regraDoRegistro[$u] ?? [] as $cr) $quem[$u][] = ($todasRegras[$cr]['registro_id'] ?? null) !== null
                ? $t('origem.regra_registro', 'Responsável deste registro', 'Responsible for this record', 'Responsable de este registro')
                : $t('origem.regra_modulo', 'Regra do módulo', 'Module rule', 'Regla del módulo');
        } else $quem[$u][] = $o === 'REGISTRO' ? $t('origem.registro', 'Responsável do registro', 'Record owner', 'Responsable del registro') : $t('origem.atribuicao', 'Atribuição', 'Assignment', 'Asignación');
    }
}
$nomeUsuario = static function (int $id) use ($usuarios, $pdo, $ler): string {
    if (isset($usuarios[$id])) return $usuarios[$id];
    $u = $ler('usuarios', ['codusuario' => $id])[0] ?? null;
    return ($u ? (trim((string)($u['nome'] ?? '')) ?: (string)($u['userid'] ?? '')) : '#' . $id) . ' (inativo)';
};
$podeAlterar = !empty($perm['pode_incluir']) || !empty($perm['pode_editar']) || !empty($perm['pode_excluir']);
$titulo = $t('titulo', 'Responsáveis e avisos', 'People responsible and alerts', 'Responsables y avisos') . ' — ' . $tr($cfg['nome']);
$theme = in_array((string)($_SESSION['layout_tema'] ?? ''), ['verde', 'azul', 'cinza'], true) ? (string)$_SESSION['layout_tema'] : 'verde';
session_write_close();

/** <option>s com o valor atual, mesmo que não esteja mais na lista. */
$opcoes = static function (array $lista, int $atual, string $vazio, string $sufixoAusente = '') use ($e): string {
    $h = '<option value="">' . $e($vazio) . '</option>';
    if ($atual && !isset($lista[$atual])) $h .= '<option value="' . $atual . '" selected>#' . $atual . $e($sufixoAusente) . '</option>';
    foreach ($lista as $k => $v) $h .= '<option value="' . (int)$k . '"' . ($k === $atual ? ' selected' : '') . '>' . $e($v) . '</option>';
    return $h;
};
$txtTodos = $t('todos', 'Todos', 'All', 'Todos'); $txtTodas = $t('todas', 'Todas', 'All', 'Todas'); $txtEscolha = $t('escolha', 'Escolha…', 'Choose…', 'Elija…');
$linha = static function (string $chave, array $r) use ($e, $t, $opcoes, $usuarios, $grupos, $unidades, $tipos, $temTipo, $temPrazo, $temAlteracao, $cobertura, $modoRegistro, $podeAlterar, $txtTodos, $txtTodas, $txtEscolha): string {
    $dest = strtoupper((string)($r['tipo_destino'] ?? 'USUARIO'));
    $cod = (int)($r['codregra'] ?? 0);
    $dis = $podeAlterar ? '' : ' disabled';
    $n = 'rows[' . $e($chave) . ']';
    $h = '<tr data-linha="' . $e($chave) . '">';
    if (!$modoRegistro) {
        if ($temTipo) $h .= '<td><select class="form-select form-select-sm" name="' . $n . '[CODTIPO]"' . $dis . '>' . $opcoes($tipos, (int)($r['codtipo'] ?? 0), $txtTodos, ' ' . $t('excluido', '(excluído)', '(deleted)', '(eliminado)')) . '</select></td>';
        $h .= '<td><select class="form-select form-select-sm" name="' . $n . '[CODUNIDADE]"' . $dis . '>' . $opcoes($unidades, (int)($r['codunidade'] ?? 0), $txtTodas, ' ' . $t('excluida', '(excluída)', '(deleted)', '(eliminada)')) . '</select></td>';
    }
    $h .= '<td><select class="form-select form-select-sm js-destino" name="' . $n . '[TIPO_DESTINO]"' . $dis . '><option value="USUARIO"' . ($dest !== 'GRUPO' ? ' selected' : '') . '>' . $e($t('usuario', 'Usuário', 'User', 'Usuario')) . '</option><option value="GRUPO"' . ($dest === 'GRUPO' ? ' selected' : '') . '>' . $e($t('grupo', 'Grupo', 'Group', 'Grupo')) . '</option></select></td>';
    $h .= '<td><select class="form-select form-select-sm js-usuario' . ($dest === 'GRUPO' ? ' d-none' : '') . '" name="' . $n . '[CODUSUARIO]"' . $dis . '>' . $opcoes($usuarios, (int)($r['codusuario'] ?? 0), $txtEscolha, ' ' . $t('inativo', '(inativo)', '(inactive)', '(inactivo)')) . '</select>'
        . '<select class="form-select form-select-sm js-grupo' . ($dest === 'GRUPO' ? '' : ' d-none') . '" name="' . $n . '[CODGRUPO]"' . $dis . '>' . $opcoes($grupos, (int)($r['codgrupo'] ?? 0), $txtEscolha, ' ' . $t('inativo', '(inativo)', '(inactive)', '(inactivo)')) . '</select></td>';
    $chk = static fn(string $campo, bool $padrao) => ' name="' . $n . '[' . $campo . ']" value="1"' . ((isset($r[strtolower($campo)]) ? !empty($r[strtolower($campo)]) : $padrao) ? ' checked' : '') . $dis;
    $h .= '<td class="text-nowrap rr-avisos">'
        . ($temPrazo ? '<label class="form-check-label me-2"><input class="form-check-input me-1" type="checkbox"' . $chk('AVISO_PRAZO', true) . '>' . $e($t('aviso.prazo', 'Prazos', 'Deadlines', 'Plazos')) . '</label>' : '')
        . '<label class="form-check-label me-2"><input class="form-check-input me-1" type="checkbox"' . $chk('AVISO_ATRIBUICAO', true) . '>' . $e($t('aviso.atribuicao', 'Atribuição', 'Assignment', 'Asignación')) . '</label>'
        . ($temAlteracao ? '<label class="form-check-label"><input class="form-check-input me-1" type="checkbox"' . $chk('AVISO_ALTERACAO', false) . '>' . $e($t('aviso.alteracao', 'Alteração', 'Change', 'Cambio')) . '</label>' : '')
        . '</td>';
    $h .= '<td class="text-center"><input class="form-check-input" type="checkbox"' . $chk('ATIVO', true) . '></td>';
    $h .= '<td><input class="form-control form-control-sm" maxlength="500" name="' . $n . '[OBSERVACAO]" value="' . $e($r['observacao'] ?? '') . '"' . $dis . '></td>';
    if (!$modoRegistro) {
        $c = $cobertura[$cod] ?? null;
        $h .= '<td class="text-nowrap small text-muted">' . ($cod ? $e(sprintf($t('cobre', '%d registro(s) · %d pessoa(s)', '%d record(s) · %d person(s)', '%d registro(s) · %d persona(s)'), (int)($c['registros'] ?? 0), (int)($c['pessoas'] ?? 0))) : '') . '</td>';
    }
    $h .= '<td>' . ($podeAlterar ? '<button type="button" class="btn btn-sm btn-outline-danger js-excluir" title="' . $e($t('excluir', 'Excluir', 'Delete', 'Eliminar')) . '"><i class="bi bi-trash"></i></button>' : '') . '</td></tr>';
    return $h;
};
?><!doctype html><html lang="<?= $idioma === 'pt' ? 'pt-BR' : $e($idioma) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($titulo) ?></title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet"><link href="../assets/css/app.css?v=3" rel="stylesheet">
<?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?>
<style>
body{font-size:13px}.rr{padding:14px 16px}.rr-ajuda{color:var(--gp-muted,#6c757d);max-width:980px}
.rr table select,.rr table input.form-control{min-width:140px}.rr-avisos .form-check-input{float:none}
.rr-quem li{margin-bottom:2px}.rr-quem .badge{font-weight:500}
</style></head>
<body class="theme-<?= $e($theme) ?>" data-transacao="responsaveis_regras"><main class="rr">
<h5 class="mb-1 d-flex align-items-center"><i class="bi bi-people me-2"></i><?= $e($titulo) ?>
<button type="button" class="btn btn-sm btn-link ms-1 p-0" data-open-transacao-ajuda data-help-transaction="responsaveis_regras" title="<?= $e($t('ajuda', 'Ajuda — Responsáveis e avisos', 'Help — People responsible and alerts', 'Ayuda — Responsables y avisos')) ?>"><i class="bi bi-question-circle"></i></button></h5>
<?php if ($modoRegistro): ?>
<div class="mb-2"><b><?= $e($t('registro', 'Registro', 'Record', 'Registro')) ?> <?= $e($registro) ?></b> — <?= $e($item['descricao'] ?? '') ?></div>
<p class="rr-ajuda small mb-3"><?= $e($t('ajuda.registro', 'Quem você incluir aqui passa a responder só por este registro, somando com o Responsável do registro, as atribuições e as regras do módulo. Grupo: valem os usuários ativos do grupo. Os avisos marcados chegam nos Alertas (e por e-mail, conforme Minha conta › Alertas por e-mail).', 'People added here become responsible for this record only, in addition to the record owner, assignments and module rules. Group: its active users. The checked alerts arrive in Alerts (and by e-mail, per My account › E-mail alerts).', 'Quien incluya aquí pasa a responder solo por este registro, sumándose al Responsable, las asignaciones y las reglas del módulo. Grupo: valen los usuarios activos del grupo. Los avisos marcados llegan a Alertas (y por correo, según Mi cuenta › Alertas por correo).')) ?></p>
<?php else: ?>
<p class="rr-ajuda small mb-3"><?= $e(sprintf($t('ajuda.modulo', 'Cada regra diz quem responde pelos registros de %s que se encaixam nela (%s). Vale na hora para registros novos e antigos e nada é gravado no registro; soma com o Responsável do registro e com as atribuições. Deixe em "Todos/Todas" para não filtrar. Grupo: valem os usuários ativos do grupo. Os avisos marcados chegam nos Alertas (e por e-mail, conforme Minha conta › Alertas por e-mail). Para um registro só, use o botão Responsáveis da grade.', 'Each rule says who is responsible for the %s records that match it (%s). It applies right away to new and existing records and nothing is written to the record; it adds to the record owner and the assignments. Leave "All" to not filter. Group: its active users. The checked alerts arrive in Alerts (and by e-mail, per My account › E-mail alerts). For a single record, use the People button of the grid.', 'Cada regla dice quién responde por los registros de %s que coinciden con ella (%s). Vale de inmediato para registros nuevos y antiguos y no se graba nada en el registro; se suma al Responsable y a las asignaciones. Deje "Todos/Todas" para no filtrar. Grupo: valen los usuarios activos. Los avisos marcados llegan a Alertas (y por correo). Para un solo registro, use el botón Responsables de la grilla.'), $tr($cfg['nome']), ($temTipo ? $tr($cfg['tipo_rotulo']) . ' + ' : '') . $t('unidade', 'Unidade', 'Unit', 'Unidad'))) ?></p>
<?php endif; ?>
<p class="rr-ajuda small mb-3"><b><?= $e($t('avisos', 'Avisos', 'Alerts', 'Avisos')) ?>:</b>
<?php if ($temPrazo): ?><?= $e($area === 'LICENCAS' ? $t('aviso.prazo_lic2', 'Prazos = validade em até 30 dias e data de reação atingida (últimos 7 dias); o Responsável da licença e quem tem atribuição recebem sempre, a regra inclui mais pessoas.', 'Deadlines = expiry within 30 days and reaction date reached (last 7 days); the license owner and assignees always get them, the rule adds more people.', 'Plazos = vencimiento en hasta 30 días y fecha de reacción alcanzada (últimos 7 días); el Responsable de la licencia y quien tiene asignación siempre los reciben, la regla agrega más personas.') : $t('aviso.prazo_mm2', 'Prazos = medição prevista em até 7 dias e data de reação atingida (últimos 7 dias); o Responsável da medição e quem tem atribuição recebem sempre, a regra inclui mais pessoas.', 'Deadlines = measurement due within 7 days and reaction date reached (last 7 days); the measurement owner and assignees always get them, the rule adds more people.', 'Plazos = medición prevista en hasta 7 días y fecha de reacción alcanzada (últimos 7 días); el Responsable de la medición y quien tiene asignación siempre los reciben, la regla agrega más personas.')) ?> <?php endif; ?>
<?= $e($t('aviso.atrib_txt', 'Atribuição = quando a pessoa passa a responder por registros.', 'Assignment = when the person becomes responsible for records.', 'Asignación = cuando la persona pasa a responder por registros.')) ?>
<?php if ($temAlteracao): ?> <?= $e($t('aviso.alt_txt', 'Alteração = quando outra pessoa altera o registro (histórico).', 'Change = when someone else changes the record (history).', 'Cambio = cuando otra persona cambia el registro (historial).')) ?><?php endif; ?></p>
<?php if ($msg !== ''): ?><div class="alert alert-success py-2"><?= $e($msg) ?></div><?php endif; ?>
<?php foreach ($erros as $er): ?><div class="alert alert-danger py-2 mb-1"><?= $e($er) ?></div><?php endforeach; ?>
<?php if ($modoRegistro): ?>
<div class="card mb-3"><div class="card-body py-2"><div class="fw-semibold mb-1"><?= $e($t('quem', 'Quem responde hoje por este registro', 'Who is responsible for this record today', 'Quién responde hoy por este registro')) ?></div>
<?php if (!$quem): ?><div class="text-muted small"><?= $e($t('ninguem', 'Ninguém: sem Responsável, atribuição ou regra que se encaixe.', 'Nobody: no owner, assignment or matching rule.', 'Nadie: sin Responsable, asignación ni regla que coincida.')) ?></div>
<?php else: ?><ul class="rr-quem list-unstyled small mb-0"><?php foreach ($quem as $u => $os): ?><li><i class="bi bi-person me-1"></i><?= $e($nomeUsuario((int)$u)) ?> <?php foreach (array_unique($os) as $o): ?><span class="badge text-bg-light border ms-1"><?= $e($o) ?></span><?php endforeach; ?></li><?php endforeach; ?></ul><?php endif; ?>
</div></div>
<?php endif; ?>
<form method="post" id="rrForm"><input type="hidden" name="acao" value="salvar"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<div class="table-responsive border rounded bg-white"><table class="table table-sm align-middle mb-0" id="rrTabela"><thead class="table-light"><tr>
<?php if (!$modoRegistro): ?><?php if ($temTipo): ?><th><?= $e($tr($cfg['tipo_rotulo'])) ?></th><?php endif; ?><th><?= $e($t('unidade', 'Unidade', 'Unit', 'Unidad')) ?></th><?php endif; ?>
<th><?= $e($t('destino', 'Destino', 'Target', 'Destino')) ?></th><th><?= $e($t('responsavel', 'Responsável', 'Responsible', 'Responsable')) ?></th><th><?= $e($t('avisos', 'Avisos', 'Alerts', 'Avisos')) ?></th><th class="text-center"><?= $e($t('ativa', 'Ativa', 'Active', 'Activa')) ?></th><th><?= $e($t('observacao', 'Observação', 'Note', 'Observación')) ?></th>
<?php if (!$modoRegistro): ?><th><?= $e($t('cobertura', 'Cobre hoje', 'Covers today', 'Cubre hoy')) ?></th><?php endif; ?><th></th></tr></thead>
<tbody><?php foreach ($regras as $r) echo $linha((string)(int)$r['codregra'], $r); ?>
<?php if (!$regras): ?><tr class="rr-vazio"><td colspan="10" class="text-muted py-3"><?= $e($modoRegistro ? $t('vazio.registro', 'Nenhum responsável próprio deste registro.', 'No people responsible for this record only.', 'Ningún responsable propio de este registro.') : $t('vazio.modulo', 'Nenhuma regra ainda.', 'No rules yet.', 'Ninguna regla aún.')) ?></td></tr><?php endif; ?>
</tbody></table></div>
<template id="rrModelo"><?= $linha('__N__', []) ?></template>
<?php if ($podeAlterar): ?>
<div class="d-flex justify-content-between mt-3">
<?php if (!empty($perm['pode_incluir'])): ?><button type="button" class="btn btn-sm btn-outline-primary" id="rrAdicionar"><i class="bi bi-plus-lg me-1"></i><?= $e($modoRegistro ? $t('adicionar.pessoa', 'Adicionar responsável', 'Add person', 'Agregar responsable') : $t('adicionar.regra', 'Adicionar regra', 'Add rule', 'Agregar regla')) ?></button><?php else: ?><span></span><?php endif; ?>
<button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check2 me-1"></i><?= $e($t('salvar', 'Salvar', 'Save', 'Guardar')) ?></button></div>
<?php else: ?><div class="alert alert-warning mt-3 py-2"><?= $e($t('so_consulta', 'Seu perfil só consulta os responsáveis deste módulo.', 'Your profile can only view the people responsible for this module.', 'Su perfil solo consulta los responsables de este módulo.')) ?></div><?php endif; ?>
</form></main>
<script>
(function () {
  var tab = document.querySelector('#rrTabela tbody'), seq = 0;
  function ligar(tr) {
    var d = tr.querySelector('.js-destino'); if (!d) return;
    d.addEventListener('change', function () { var g = d.value === 'GRUPO'; tr.querySelector('.js-usuario').classList.toggle('d-none', g); tr.querySelector('.js-grupo').classList.toggle('d-none', !g); });
  }
  tab.querySelectorAll('tr[data-linha]').forEach(ligar);
  var add = document.getElementById('rrAdicionar');
  if (add) add.addEventListener('click', function () {
    var vazio = tab.querySelector('.rr-vazio'); if (vazio) vazio.remove();
    var html = document.getElementById('rrModelo').innerHTML.split('__N__').join('novo_' + (++seq));
    var corpo = document.createElement('tbody'); corpo.innerHTML = html;
    var tr = corpo.firstElementChild; tab.appendChild(tr); ligar(tr);
  });
  tab.addEventListener('click', function (ev) {
    var b = ev.target.closest('.js-excluir'); if (!b) return;
    var tr = b.closest('tr'), k = tr.getAttribute('data-linha');
    if (/^\d+$/.test(k)) { var h = document.createElement('input'); h.type = 'hidden'; h.name = 'rows[' + k + '][_delete]'; h.value = '1'; document.getElementById('rrForm').appendChild(h); }
    tr.remove();
  });
}());
</script><script src="../assets/js/ajuda-transacao.js?v=1"></script></body></html>
