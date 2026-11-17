<?php
declare(strict_types=1);

/*
 * Homologação de Fornecedores › Integração de funcionários (migração 348; Onda 2 da apresentação Atlas:
 * "Integração de funcionários: avaliação e liberação" — cadastro, questionário, avaliação e decisão).
 *
 * Lista (grid local): os funcionários (as mesmas regras da Gestão de Funcionários), o questionário do tipo de cada um, a
 * situação da integração e a validade (liberado na portaria enquanto a integração estiver em dia).
 * Barra: Gerar link (o funcionário responde em integracao.php, sem senha; copiar ou abrir neste computador), Confirmar decisão
 * (respostas, nota, sugestão pela nota mínima; Aprovar/Reprovar), Certificado (PDF), Cancelar link e Configurar questionários
 * (tipo de funcionário → questionário, nota mínima, validade e prazo do link).
 * Modos: ?decisao=N (popup da decisão), ?certificado=N (PDF), ?config=1 (popup da configuração), ?integracao=N (link do alerta).
 * Acesso: menu HOMOLOGACAO_INTEGRACAO_FUNCIONARIOS (Visualizar; Editar para gerar, cancelar, decidir e configurar); usuário interno
 * restrito vê só os fornecedores das suas unidades (migração 316); fornecedor externo não acessa.
 * Banco: views vw_fo_integracao_* e procedures sp_fo_integracao_* (database/348_integracao_funcionarios.sql).
 */
require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/IntegracaoFuncionario.php';
require_once dirname(__DIR__) . '/classes/HomologacaoFornecedorAcesso.php';
require_once dirname(__DIR__) . '/components/grid_local.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$pdo = TransactionSecurity::connect(IntegracaoFuncionario::MENU);
$perm = PermissaoTransacao::obter(IntegracaoFuncionario::MENU);
$podeEditar = !empty($perm['pode_editar']);
$env = GreenPortalDatabase::env();
$usuario = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$T = static fn(string $c, array $p): string => IdiomaService::t('homologacaofornecedores_integracao.' . $c, $p);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$dt = static function (?string $d, bool $hora = false): string { if (!$d || !preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}))?/', $d, $x) || $x[1] === '0000') return ''; return (IdiomaService::idioma() === 'en' ? "$x[2]/$x[3]/$x[1]" : "$x[3]/$x[2]/$x[1]") . ($hora && isset($x[4]) ? " $x[4]:$x[5]" : ''); };
$pct = static fn($v): string => $v === null || $v === '' ? '' : number_format((float)$v, 1, ',', '.') . '%';
if (HomologacaoFornecedorAcesso::externo()) { http_response_code(403); exit($e(HomologacaoFornecedorAcesso::mensagemNegado())); }
$disponivel = IntegracaoFuncionario::disponivel($pdo);
if (empty($_SESSION['integracao_csrf'])) $_SESSION['integracao_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['integracao_csrf'];
$ler = static function (string $view, array $filtros = [], int $limite = 20000) use ($pdo): array {
    $s = $pdo->prepare('CALL sp_crud_select(?, ?, NULL, ?, 0)'); $s->execute([$view, json_encode($filtros ?: new stdClass()), $limite]);
    $r = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) {} $s->closeCursor(); return $r;
};
$integracao = static function (int $cod) use ($pdo): ?array { return IntegracaoFuncionario::chamar($pdo, 'SELECT * FROM vw_fo_integracoes WHERE CODINTEGRACAO = ?', [$cod])[0] ?? null; };
$podeFuncionario = static fn(int $codfuncionario): bool => HomologacaoFornecedorAcesso::pode($pdo, 'funcionario', $codfuncionario);
$empresaNome = trim((string)($_SESSION['tenant']['nome'] ?? '')) ?: (string)($_SESSION['tenant']['banco_dados'] ?? '');
$sessaoGed = ['tenant' => ($_SESSION['tenant'] ?? [])];
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';
if (($leg = HomologacaoBancos::legado((string)($_SESSION['tenant']['banco_dados'] ?? ''))) !== null && empty($sessaoGed['tenant']['banco_legado'])) $sessaoGed['tenant']['banco_legado'] = $leg;

// ------------------------------------------------------------------ certificado (PDF)
if (isset($_GET['certificado'])) {
    $i = $disponivel ? $integracao((int)$_GET['certificado']) : null;
    if (!$i || !$podeFuncionario((int)$i['CODFUNCIONARIO'])) { http_response_code(404); exit('Certificado não encontrado.'); }
    $arq = IntegracaoFuncionario::arquivoCertificado($pdo, $env, $sessaoGed, $i);
    if ($arq === null && (string)$i['STATUS'] === 'APROVADA' && $podeEditar) {
        try { IntegracaoFuncionario::certificado($pdo, $env, $sessaoGed, (int)$i['CODINTEGRACAO'], $empresaNome); $i = $integracao((int)$i['CODINTEGRACAO']); $arq = IntegracaoFuncionario::arquivoCertificado($pdo, $env, $sessaoGed, $i); }
        catch (Throwable $x) { error_log('[integração] certificado: ' . $x->getMessage()); }
    }
    if ($arq === null) { http_response_code(404); exit('Certificado não encontrado.'); }
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . basename($arq) . '"'); header('Content-Length: ' . filesize($arq));
    readfile($arq); exit;
}

// ------------------------------------------------------------------ ações (JSON)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $resp = static function (array $d, int $st = 200): never { http_response_code($st); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $resp(['ok' => false, 'message' => $T('msg.csrf', ['pt' => 'Token de segurança inválido. Recarregue a página.', 'en' => 'Invalid security token. Reload the page.', 'es' => 'Token de seguridad inválido. Recargue la página.'])], 419);
    if (!$disponivel) $resp(['ok' => false, 'message' => $T('msg.sem_migracao', ['pt' => 'A integração de funcionários ainda não está disponível nesta empresa (migração 348).', 'en' => 'Not available for this company yet (migration 348).', 'es' => 'Aún no disponible en esta empresa (migración 348).'])], 422);
    if (!$podeEditar) $resp(['ok' => false, 'message' => $T('msg.sem_permissao', ['pt' => 'Seu perfil não pode alterar a integração (Segurança › Permissões › Integração de funcionários › Editar).', 'en' => 'Your profile cannot change inductions.', 'es' => 'Su perfil no puede cambiar la integración.'])], 403);
    $acao = (string)($_POST['action'] ?? '');
    try {
        if ($acao === 'gerar') {
            $cf = (int)($_POST['codfuncionario'] ?? 0);
            if (!$podeFuncionario($cf)) $resp(['ok' => false, 'message' => $T('msg.sem_acesso', ['pt' => 'Este funcionário não é de um fornecedor de uma unidade sua.', 'en' => 'This employee does not belong to a supplier of your units.', 'es' => 'Este empleado no es de un proveedor de sus unidades.'])], 403);
            $g = IntegracaoFuncionario::gerar($pdo, $env, (string)($_SESSION['tenant']['codigo'] ?? ''), $cf, $usuario);
            $resp(['ok' => true] + $g + ['expira' => $dt($g['expira_em'], true)]);
        }
        if ($acao === 'config_salvar') {
            $num = static fn(string $c): ?float => trim((string)($_POST[$c] ?? '')) === '' ? null : (float)str_replace(',', '.', (string)$_POST[$c]);
            $tipo = trim((string)($_POST['tipo'] ?? '')) === '' ? null : (int)$_POST['tipo'];
            $r = IntegracaoFuncionario::chamar($pdo, 'CALL sp_fo_integracao_config_salvar(?, ?, ?, ?, ?, ?, ?, ?)', [(int)($_POST['codconfig'] ?? 0), $tipo, (string)($_POST['questionario'] ?? ''), $num('nota'),
                (int)($_POST['validade'] ?? 0), (int)($_POST['link_dias'] ?? 0), empty($_POST['ativo']) || $_POST['ativo'] === '0' ? 0 : 1, $usuario]);
            $resp(['ok' => true, 'codconfig' => (int)($r[0]['CODCONFIG'] ?? 0), 'message' => $T('msg.config_salva', ['pt' => 'Configuração gravada.', 'en' => 'Settings saved.', 'es' => 'Configuración guardada.'])]);
        }
        if ($acao === 'config_excluir') {
            IntegracaoFuncionario::chamar($pdo, 'CALL sp_fo_integracao_config_excluir(?)', [(int)($_POST['codconfig'] ?? 0)]);
            $resp(['ok' => true, 'message' => $T('msg.config_excluida', ['pt' => 'Configuração excluída.', 'en' => 'Setting deleted.', 'es' => 'Configuración eliminada.'])]);
        }
        $i = $integracao((int)($_POST['codintegracao'] ?? 0));
        if (!$i || !$podeFuncionario((int)$i['CODFUNCIONARIO'])) $resp(['ok' => false, 'message' => $T('msg.nao_encontrada', ['pt' => 'Integração não encontrada.', 'en' => 'Induction not found.', 'es' => 'Integración no encontrada.'])], 404);
        if ($acao === 'cancelar') {
            IntegracaoFuncionario::chamar($pdo, 'CALL sp_fo_integracao_cancelar(?, ?)', [(int)$i['CODINTEGRACAO'], $usuario]);
            $resp(['ok' => true, 'message' => $T('msg.cancelada', ['pt' => 'Link cancelado: ele não abre mais.', 'en' => 'Link cancelled: it no longer opens.', 'es' => 'Enlace cancelado: ya no se abre.'])]);
        }
        if ($acao === 'decidir') {
            $dec = (string)($_POST['decisao'] ?? '') === 'APROVADA' ? 'APROVADA' : 'REPROVADA';
            $r = IntegracaoFuncionario::chamar($pdo, 'CALL sp_fo_integracao_decidir(?, ?, ?, ?)', [(int)$i['CODINTEGRACAO'], $dec, mb_substr(trim((string)($_POST['motivo'] ?? '')), 0, 1000), $usuario])[0] ?? [];
            $cert = '';
            if ($dec === 'APROVADA') {
                // O sincronismo não deve desfazer a data: registra a proteção do campo (quando a proteção estiver ligada)
                try { IntegracaoFuncionario::chamar($pdo, 'CALL sp_fo_sinc_protecao_registrar(?, ?, ?, ?, ?)', ['fo_funcionarios', (string)(int)$i['CODFUNCIONARIO'], 'DATA_VALIDADE', (int)$i['CODINTEGRACAO'], $usuario]); } catch (Throwable) {}
                try { $cert = IntegracaoFuncionario::certificado($pdo, $env, $sessaoGed, (int)$i['CODINTEGRACAO'], $empresaNome); }
                catch (Throwable $x) { error_log('[integração] certificado: ' . $x->getMessage()); }
            }
            $msg = $dec === 'APROVADA'
                ? strtr($T('msg.aprovada', ['pt' => 'Integração aprovada. Liberado na portaria até {d}.', 'en' => 'Induction approved. Released at the gatehouse until {d}.', 'es' => 'Integración aprobada. Liberado en la portería hasta {d}.']), ['{d}' => $dt((string)($r['VALIDADE_ATE'] ?? ''))])
                    . ($cert !== '' ? ' ' . $T('msg.certificado', ['pt' => 'Certificado gravado nos arquivos do funcionário.', 'en' => 'Certificate saved in the employee files.', 'es' => 'Certificado guardado en los archivos del empleado.']) : '')
                : $T('msg.reprovada', ['pt' => 'Integração reprovada. Gere um novo link quando o funcionário for refazer.', 'en' => 'Induction rejected. Generate a new link when the employee retakes it.', 'es' => 'Integración rechazada. Genere un nuevo enlace cuando el empleado la rehaga.']);
            $resp(['ok' => true, 'message' => $msg, 'certificado' => $cert !== '']);
        }
        $resp(['ok' => false, 'message' => $T('msg.acao', ['pt' => 'Ação inválida.', 'en' => 'Invalid action.', 'es' => 'Acción inválida.'])], 400);
    } catch (Throwable $x) { $resp(['ok' => false, 'message' => IntegracaoFuncionario::mensagem($x)], 422); }
}

$cabecalho = static function (string $titulo) use ($e): void {
    echo '<!doctype html><html lang="' . (IdiomaService::idioma() === 'pt' ? 'pt-BR' : $e(IdiomaService::idioma())) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($titulo) . '</title>'
        . '<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">';
    greenPortalGridLocalHead();
    echo '<link href="../assets/css/integracao-funcionarios.css?v=1" rel="stylesheet"></head>';
};
$estadoJs = static function (array $extra = []) use ($csrf, $podeEditar, $T): string {
    return '<script>window.greenIntegracao=' . json_encode(['csrf' => $csrf, 'podeEditar' => $podeEditar, 'url' => 'homologacaofornecedores_integracao.php', 'textos' => [
        'link_titulo' => $T('js.link_titulo', ['pt' => 'Link de integração', 'en' => 'Induction link', 'es' => 'Enlace de integración']),
        'link_texto' => $T('js.link_texto', ['pt' => 'Envie este link ao funcionário (WhatsApp, e-mail ou impresso) ou abra aqui para ele responder neste computador. Vale até {d}, para uma resposta. Gerar outro link cancela este.', 'en' => 'Send this link to the employee or open it here for them to answer on this computer. Valid until {d}, for one answer. Generating another link cancels this one.', 'es' => 'Envíe este enlace al empleado o ábralo aquí para que responda en esta computadora. Vale hasta {d}, para una respuesta. Generar otro enlace cancela este.']),
        'copiar' => $T('js.copiar', ['pt' => 'Copiar link', 'en' => 'Copy link', 'es' => 'Copiar enlace']),
        'copiado' => $T('js.copiado', ['pt' => 'Link copiado.', 'en' => 'Link copied.', 'es' => 'Enlace copiado.']),
        'abrir_aqui' => $T('js.abrir_aqui', ['pt' => 'Responder neste computador', 'en' => 'Answer on this computer', 'es' => 'Responder en esta computadora']),
        'decisao' => $T('js.decisao', ['pt' => 'Decisão da integração', 'en' => 'Induction decision', 'es' => 'Decisión de la integración']),
        'certificado' => $T('js.certificado', ['pt' => 'Certificado de integração', 'en' => 'Induction certificate', 'es' => 'Certificado de integración']),
        'config' => $T('js.config', ['pt' => 'Questionários de integração por tipo de funcionário', 'en' => 'Induction questionnaires by employee type', 'es' => 'Cuestionarios de integración por tipo de empleado']),
        'cancelar_conf' => $T('js.cancelar_conf', ['pt' => 'Cancelar o link de integração deste funcionário? Ele não vai mais abrir.', 'en' => 'Cancel this employee\'s induction link? It will no longer open.', 'es' => '¿Cancelar el enlace de integración de este empleado? Ya no se abrirá.']),
        'gerar_conf' => $T('js.gerar_conf', ['pt' => 'Este funcionário já tem um link aguardando resposta. Gerar outro cancela o anterior. Continuar?', 'en' => 'This employee already has a link awaiting an answer. Generating another cancels it. Continue?', 'es' => 'Este empleado ya tiene un enlace esperando respuesta. Generar otro cancela el anterior. ¿Continuar?']),
        'erro' => $T('js.erro', ['pt' => 'Não foi possível concluir a operação.', 'en' => 'The operation could not be completed.', 'es' => 'No fue posible concluir la operación.']),
    ]] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
};
$semMigracao = $T('msg.sem_migracao', ['pt' => 'A integração de funcionários ainda não está disponível nesta empresa (migração 348).', 'en' => 'Not available for this company yet (migration 348).', 'es' => 'Aún no disponible en esta empresa (migración 348).']);

// ------------------------------------------------------------------ popup: decisão
if (isset($_GET['decisao'])) {
    $i = $disponivel ? $integracao((int)$_GET['decisao']) : null;
    $titulo = $T('decisao.titulo', ['pt' => 'Decisão da integração', 'en' => 'Induction decision', 'es' => 'Decisión de la integración']);
    $cabecalho($titulo);
    echo '<body data-transacao="homologacaofornecedores_integracao"><main class="ifn">';
    if (!$i || !$podeFuncionario((int)$i['CODFUNCIONARIO'])) { echo '<div class="alert alert-warning">' . $e($disponivel ? $T('msg.nao_encontrada', ['pt' => 'Integração não encontrada.', 'en' => 'Induction not found.', 'es' => 'Integración no encontrada.']) : $semMigracao) . '</div></main></body></html>'; exit; }
    require_once dirname(__DIR__) . '/classes/QuestionnaireService.php';
    $grupos = []; $pontos = 0.0; $max = 0.0;
    if ((int)$i['CODEXECUCAO'] > 0) {
        try {
            $universal = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
            $qs = new QuestionnaireService($pdo, $universal, (int)($_SESSION['tenant']['codempresa'] ?? 0));
            $perg = $qs->questions((int)$i['CODEXECUCAO'], IdiomaService::idioma());
            $det = $qs->details((int)$i['CODEXECUCAO'], 0);
            $resp = []; foreach ($det['answers'] as $a) $resp[(int)$a['codpergunta']] = json_decode((string)$a['valor_json'], true);
            $titulos = [];
            try { $s = $universal->prepare('SELECT codbloco, titulo_pt, titulo_en, titulo_es FROM vw_amb_questionario_blocos WHERE codversao = ? ORDER BY ordem, codbloco'); $s->execute([(int)$det['execution']['codversao']]); foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $b) $titulos[(int)$b['codbloco']] = (string)($b['titulo_' . IdiomaService::idioma()] ?: $b['titulo_pt']); } catch (Throwable) {}
            foreach ($perg as $p) {
                $v = $resp[(int)$p['codpergunta']] ?? null; $rot = []; $pts = null; $mx = null;
                $pontuadas = array_values(array_filter($p['opcoes'], static fn($o) => $o['pontuacao'] !== null));
                if ($pontuadas) { $mx = max(array_map(static fn($o) => (float)$o['pontuacao'], $pontuadas)); $pts = 0.0; }
                foreach ((array)$v as $x) foreach ($p['opcoes'] as $o) if ((string)$x === (string)$o['codigo'] || (string)$x === (string)$o['codopcao'] || (string)$x === (string)$o['valor']) { $rot[] = $o['rotulo']; if ($pts !== null) $pts += (float)($o['pontuacao'] ?? 0); continue 2; }
                if (!$p['opcoes'] && $v !== null) $rot[] = is_array($v) ? implode(', ', $v) : (string)$v;
                if ($mx !== null) { $pontos += $pts; $max += $mx; }
                $b = $p['codbloco'] !== null && isset($titulos[(int)$p['codbloco']]) ? $titulos[(int)$p['codbloco']] : '';
                $grupos[$b][] = ['texto' => $p['texto'], 'resposta' => $rot ? implode('; ', $rot) : '—', 'pts' => $pts, 'max' => $mx, 'ok' => $mx === null ? null : ($pts >= $mx)];
            }
        } catch (Throwable $x) { error_log('[integração] respostas: ' . $x->getMessage()); }
    }
    $sug = (string)$i['SUGESTAO'];
    echo '<div class="ifn-cab"><div><h2>' . $e($i['FUNCIONARIO']) . '</h2><div class="text-muted">' . $e(trim($i['RAZAOSOCIAL'] . ($i['DSCTIPO'] ? ' · ' . $i['DSCTIPO'] : '') . ($i['DSCUNIDADE'] ? ' · ' . $i['DSCUNIDADE'] : ''))) . '</div>'
        . '<div class="small">' . $e($i['QUESTIONARIO'] ?: $i['CODIGO_QUESTIONARIO']) . ($i['RESPONDIDA_EM'] ? ' · ' . $e($T('respondida_em', ['pt' => 'respondida em', 'en' => 'answered on', 'es' => 'respondida el'])) . ' ' . $e($dt((string)$i['RESPONDIDA_EM'], true)) : '') . '</div></div>'
        . '<div class="ifn-nota ' . ($sug === 'APROVADO' ? 'ok' : 'nao') . '"><div class="v">' . $e($pct($i['PERCENTUAL'])) . '</div><div class="l">' . $e($T('nota_minima', ['pt' => 'nota mínima', 'en' => 'minimum', 'es' => 'nota mínima'])) . ' ' . $e($pct($i['NOTA_MINIMA'] ?? 70)) . '</div>'
        . '<div class="s">' . $e($sug === 'APROVADO' ? $T('sug.aprovado', ['pt' => 'Sugestão: Aprovado', 'en' => 'Suggested: Approved', 'es' => 'Sugerencia: Aprobado']) : ($sug === 'REPROVADO' ? $T('sug.reprovado', ['pt' => 'Sugestão: Reprovado', 'en' => 'Suggested: Rejected', 'es' => 'Sugerencia: Rechazado']) : '')) . '</div></div></div>';
    foreach ($grupos as $b => $lista) {
        echo '<section class="ifn-bloco">' . ($b !== '' ? '<h3>' . $e($b) . '</h3>' : '') . '<table class="table table-sm ifn-resp"><tbody>';
        foreach ($lista as $r) echo '<tr><td>' . $e($r['texto']) . '</td><td class="' . ($r['ok'] === false ? 'text-danger' : '') . '">' . $e($r['resposta']) . '</td><td class="text-nowrap text-muted">' . ($r['max'] !== null ? $e(rtrim(rtrim(number_format((float)$r['pts'], 2, ',', ''), '0'), ',') . ' / ' . rtrim(rtrim(number_format((float)$r['max'], 2, ',', ''), '0'), ',')) : '') . '</td></tr>';
        echo '</tbody></table></section>';
    }
    if (!$grupos) echo '<p class="text-muted">' . $e($T('sem_respostas', ['pt' => 'Sem respostas para mostrar.', 'en' => 'No answers to show.', 'es' => 'Sin respuestas para mostrar.'])) . '</p>';
    if ((string)$i['STATUS'] === 'RESPONDIDA' && $podeEditar) {
        echo '<section class="ifn-decidir" data-ifn-decidir data-cod="' . (int)$i['CODINTEGRACAO'] . '" data-sugestao="' . $e($sug) . '"><label class="form-label small mb-0" for="ifnMotivo">' . $e($T('motivo', ['pt' => 'Motivo / observação (obrigatório para reprovar ou para aprovar abaixo da nota mínima)', 'en' => 'Reason / note (required to reject or to approve below the minimum)', 'es' => 'Motivo / observación (obligatorio para rechazar o aprobar debajo de la mínima)'])) . '</label>'
            . '<textarea class="form-control form-control-sm" id="ifnMotivo" rows="2" maxlength="1000" data-ifn-motivo></textarea><div class="d-flex gap-2 mt-2 align-items-center">'
            . '<button type="button" class="btn btn-sm btn-success" data-ifn-dec="APROVADA"><i class="bi bi-check2-circle me-1"></i>' . $e($T('aprovar', ['pt' => 'Aprovar e liberar', 'en' => 'Approve and release', 'es' => 'Aprobar y liberar'])) . '</button>'
            . '<button type="button" class="btn btn-sm btn-outline-danger" data-ifn-dec="REPROVADA"><i class="bi bi-x-circle me-1"></i>' . $e($T('reprovar', ['pt' => 'Reprovar', 'en' => 'Reject', 'es' => 'Rechazar'])) . '</button>'
            . '<span class="small" data-ifn-msg></span></div></section>';
    } else {
        $st = ['APROVADA' => $T('st.aprovada', ['pt' => 'Aprovada', 'en' => 'Approved', 'es' => 'Aprobada']), 'REPROVADA' => $T('st.reprovada', ['pt' => 'Reprovada', 'en' => 'Rejected', 'es' => 'Rechazada'])][(string)$i['STATUS']] ?? (string)$i['STATUS'];
        echo '<div class="alert alert-light border small">' . $e($st) . ($i['DECIDIDA_EM'] ? ' · ' . $e($dt((string)$i['DECIDIDA_EM'], true)) : '') . ($i['VALIDADE_ATE'] ? ' · ' . $e($T('valida_ate', ['pt' => 'válida até', 'en' => 'valid until', 'es' => 'válida hasta'])) . ' ' . $e($dt((string)$i['VALIDADE_ATE'])) : '') . ($i['MOTIVO'] ? '<br>' . $e($i['MOTIVO']) : '') . '</div>';
    }
    echo '</main>' . $estadoJs() . '<script src="../assets/vendor/jquery/jquery-3.7.1.min.js?v=2"></script><script src="../assets/js/integracao-funcionarios.js?v=1"></script></body></html>';
    exit;
}

// ------------------------------------------------------------------ popup: configuração
if (isset($_GET['config'])) {
    $titulo = $T('config.titulo', ['pt' => 'Questionários de integração por tipo de funcionário', 'en' => 'Induction questionnaires by employee type', 'es' => 'Cuestionarios de integración por tipo de empleado']);
    $cabecalho($titulo);
    echo '<body data-transacao="homologacaofornecedores_integracao"><main class="ifn">';
    if (!$disponivel) { echo '<div class="alert alert-warning">' . $e($semMigracao) . '</div></main></body></html>'; exit; }
    $cfgs = $ler('vw_fo_integracao_config'); $tipos = $ler('vw_fo_integracao_tipos'); $quests = $ler('vw_fo_integracao_questionarios');
    usort($tipos, static fn($a, $b) => strcasecmp((string)$a['DSCTIPO'], (string)$b['DSCTIPO']));
    usort($cfgs, static fn($a, $b) => [$a['CODTIPOFUNCIONARIO'] !== null, (string)$a['DSCTIPO']] <=> [$b['CODTIPOFUNCIONARIO'] !== null, (string)$b['DSCTIPO']]);
    $dis = $podeEditar ? '' : ' disabled';
    $optTipos = static function ($sel) use ($tipos, $e, $T): string { $h = '<option value="">' . $e($T('config.padrao', ['pt' => '(Padrão da empresa — todos os tipos sem questionário próprio)', 'en' => '(Company default)', 'es' => '(Estándar de la empresa)'])) . '</option>'; foreach ($tipos as $t) $h .= '<option value="' . (int)$t['CODTIPOFUNCIONARIO'] . '"' . ((string)$sel === (string)$t['CODTIPOFUNCIONARIO'] ? ' selected' : '') . '>' . $e($t['DSCTIPO']) . '</option>'; return $h; };
    $optQ = static function ($sel) use ($quests, $e): string { $h = '<option value=""></option>'; $achou = false; foreach ($quests as $q) { $s = strcasecmp((string)$sel, (string)$q['CODIGO_QUESTIONARIO']) === 0; $achou = $achou || $s; $h .= '<option value="' . $e($q['CODIGO_QUESTIONARIO']) . '"' . ($s ? ' selected' : '') . '>' . $e($q['NOME'] . ' (' . $q['CODIGO_QUESTIONARIO'] . ')') . '</option>'; } if (!$achou && (string)$sel !== '') $h .= '<option value="' . $e($sel) . '" selected>' . $e($sel) . ' — não publicado</option>'; return $h; };
    $linha = static function (array $c) use ($e, $optTipos, $optQ, $dis, $T): string {
        return '<tr data-ifn-cfg="' . (int)($c['CODCONFIG'] ?? 0) . '"><td><select class="form-select form-select-sm" data-c="tipo"' . $dis . '>' . $optTipos($c['CODTIPOFUNCIONARIO'] ?? '') . '</select></td>'
            . '<td><select class="form-select form-select-sm" data-c="questionario"' . $dis . '>' . $optQ($c['CODIGO_QUESTIONARIO'] ?? '') . '</select></td>'
            . '<td><input type="number" min="0" max="100" step="0.5" class="form-control form-control-sm" data-c="nota" value="' . $e($c['NOTA_MINIMA'] ?? '') . '" placeholder="' . $e(isset($c['PERCENTUAL_MINIMO']) ? rtrim(rtrim((string)$c['PERCENTUAL_MINIMO'], '0'), '.') : '70') . '"' . $dis . '></td>'
            . '<td><input type="number" min="1" max="120" class="form-control form-control-sm" data-c="validade" value="' . $e($c['VALIDADE_MESES'] ?? 12) . '"' . $dis . '></td>'
            . '<td><input type="number" min="1" max="90" class="form-control form-control-sm" data-c="link_dias" value="' . $e($c['LINK_DIAS'] ?? 7) . '"' . $dis . '></td>'
            . '<td class="text-center"><input class="form-check-input" type="checkbox" data-c="ativo"' . ((int)($c['ATIVO'] ?? 1) ? ' checked' : '') . $dis . '></td>'
            . '<td class="text-nowrap">' . ($dis === '' ? '<button type="button" class="btn btn-sm btn-outline-success" data-ifn-cfg-salvar title="' . $e($T('config.gravar', ['pt' => 'Gravar a linha', 'en' => 'Save row', 'es' => 'Guardar la fila'])) . '"><i class="bi bi-floppy"></i></button> <button type="button" class="btn btn-sm btn-outline-danger" data-ifn-cfg-excluir title="' . $e($T('config.excluir', ['pt' => 'Excluir a linha', 'en' => 'Delete row', 'es' => 'Eliminar la fila'])) . '"><i class="bi bi-trash"></i></button>' : '') . '</td></tr>';
    };
    echo '<h2 class="ifn-h"><i class="bi bi-ui-checks-grid"></i> ' . $e($titulo) . '</h2><p class="text-muted small">' . $e($T('config.nota', ['pt' => 'Cada tipo de funcionário responde o seu questionário; os tipos sem linha própria usam o padrão da empresa. Nota mínima vazia: a do questionário (senão 70%). A validade conta a partir da aprovação. Os questionários são cadastrados e publicados em Questionários de Integração de Funcionários.', 'en' => 'Each employee type answers its own questionnaire; types without a row use the company default. Empty minimum: the questionnaire\'s (or 70%). Validity counts from approval.', 'es' => 'Cada tipo de empleado responde su cuestionario; los tipos sin fila propia usan el estándar. Nota mínima vacía: la del cuestionario (o 70%). La validez cuenta desde la aprobación.'])) . '</p>';
    if (!$quests) echo '<div class="alert alert-warning small">' . $e($T('config.sem_publicado', ['pt' => 'Nenhum questionário de integração publicado ainda. Publique um em Questionários de Integração de Funcionários (a migração deixou um modelo em rascunho: INTEGRACAO_FUNCIONARIO_PADRAO).', 'en' => 'No induction questionnaire published yet.', 'es' => 'Ningún cuestionario de integración publicado todavía.'])) . '</div>';
    echo '<table class="table table-sm ifn-cfg"><thead><tr><th>' . $e($T('config.c.tipo', ['pt' => 'Tipo de funcionário', 'en' => 'Employee type', 'es' => 'Tipo de empleado'])) . '</th><th>' . $e($T('config.c.questionario', ['pt' => 'Questionário', 'en' => 'Questionnaire', 'es' => 'Cuestionario'])) . '</th><th style="width:100px">' . $e($T('config.c.nota', ['pt' => 'Nota mínima (%)', 'en' => 'Minimum (%)', 'es' => 'Nota mínima (%)'])) . '</th><th style="width:100px">' . $e($T('config.c.validade', ['pt' => 'Validade (meses)', 'en' => 'Validity (months)', 'es' => 'Validez (meses)'])) . '</th><th style="width:90px">' . $e($T('config.c.link', ['pt' => 'Link (dias)', 'en' => 'Link (days)', 'es' => 'Enlace (días)'])) . '</th><th style="width:60px">' . $e($T('config.c.ativo', ['pt' => 'Ativo', 'en' => 'Active', 'es' => 'Activo'])) . '</th><th style="width:90px"></th></tr></thead><tbody data-ifn-cfg-linhas>';
    foreach ($cfgs as $c) echo $linha($c);
    echo '</tbody></table>' . ($podeEditar ? '<button type="button" class="btn btn-sm btn-outline-success" data-ifn-cfg-nova><i class="bi bi-plus-lg me-1"></i>' . $e($T('config.nova', ['pt' => 'Nova linha', 'en' => 'New row', 'es' => 'Nueva fila'])) . '</button><template data-ifn-cfg-modelo>' . $linha([]) . '</template>' : '')
        . '<div class="small mt-2" data-ifn-msg></div></main>' . $estadoJs() . '<script src="../assets/vendor/jquery/jquery-3.7.1.min.js?v=2"></script><script src="../assets/js/integracao-funcionarios.js?v=1"></script></body></html>';
    exit;
}

// ------------------------------------------------------------------ lista
$titulo = $T('titulo', ['pt' => 'Integração de funcionários', 'en' => 'Employee induction', 'es' => 'Integración de empleados']);
$cabecalho($titulo);
echo '<body data-transacao="homologacaofornecedores_integracao">';
if (!$disponivel) { echo '<div class="alert alert-warning m-3">' . $e($semMigracao) . '</div></body></html>'; exit; }
$linhas = $ler('vw_fo_integracao_funcionarios');
if (HomologacaoFornecedorAcesso::modoBanco($pdo) === 2) {
    $ok = [];
    foreach ($ler('vw_homologacao_fornecedores_acesso_interno', ['CODUSUARIO' => (string)$usuario]) as $a) if ((int)$a['CODUSUARIO'] === $usuario) $ok[(int)$a['CODFORNECEDOR']] = true;
    $linhas = array_values(array_filter($linhas, static fn($l) => isset($ok[(int)$l['CODFORNECEDOR']])));
}
$hoje = date('Y-m-d'); $em30 = date('Y-m-d', strtotime('+30 days'));
// Decisão de 24/10/2026 (migração 350): fornecedor bloqueado no ranking = funcionários "Não liberado" na portaria
require_once dirname(__DIR__) . '/classes/HomologacaoRelatorios.php';
$bloqueados = HomologacaoRelatorios::lerBloqueados($pdo);
$rot = [
    'desligado' => $T('st.desligado', ['pt' => 'Desligado (sem link de integração)', 'en' => 'Dismissed (no induction link)', 'es' => 'Desvinculado (sin enlace de integración)']),
    'sem' => $T('st.sem', ['pt' => 'Sem integração pelo portal', 'en' => 'No portal induction', 'es' => 'Sin integración por el portal']),
    'aguardando' => $T('st.aguardando', ['pt' => 'Link enviado (até {d})', 'en' => 'Link sent (until {d})', 'es' => 'Enlace enviado (hasta {d})']),
    'vencido' => $T('st.link_vencido', ['pt' => 'Link vencido sem resposta', 'en' => 'Link expired unanswered', 'es' => 'Enlace vencido sin respuesta']),
    'respondida' => $T('st.respondida', ['pt' => 'Respondida — confirmar ({n}, sugestão {s})', 'en' => 'Answered — confirm ({n}, suggested {s})', 'es' => 'Respondida — confirmar ({n}, sugerencia {s})']),
    'APROVADA' => $T('st.aprovada', ['pt' => 'Aprovada', 'en' => 'Approved', 'es' => 'Aprobada']), 'REPROVADA' => $T('st.reprovada', ['pt' => 'Reprovada', 'en' => 'Rejected', 'es' => 'Rechazada']),
    'APROVADO' => $T('sug.ap', ['pt' => 'aprovado', 'en' => 'approved', 'es' => 'aprobado']), 'REPROVADO' => $T('sug.rep', ['pt' => 'reprovado', 'en' => 'rejected', 'es' => 'rechazado']),
    'liberado' => $T('lib.sim', ['pt' => 'Liberado', 'en' => 'Released', 'es' => 'Liberado']), 'bloqueado' => $T('lib.nao', ['pt' => 'Não liberado', 'en' => 'Not released', 'es' => 'No liberado']),
    'forn_bloq' => $T('lib.forn_bloqueado', ['pt' => 'Não liberado (fornecedor bloqueado)', 'en' => 'Not released (supplier blocked)', 'es' => 'No liberado (proveedor bloqueado)']),
    'sem_q' => $T('st.sem_questionario', ['pt' => 'Sem questionário configurado', 'en' => 'No questionnaire configured', 'es' => 'Sin cuestionario configurado']),
];
$bt = static fn(string $acao, string $extra = ''): string => '<button type="button" data-linha-acao="' . $acao . '"' . $extra . '></button>';
$rows = [];
foreach ($linhas as $l) {
    $val = substr((string)($l['DATA_VALIDADE'] ?? ''), 0, 10); if ($val === '' || str_starts_with($val, '0000')) $val = '';
    // Migração 350: DATA_VALIDADE da view = validade calculada (realização + meses); DATA_REALIZACAO = data gravada no funcionário
    $real = substr((string)($l['DATA_REALIZACAO'] ?? ''), 0, 10); if ($real === '' || str_starts_with($real, '0000')) $real = '';
    $sinal = $val === '' ? '' : ($val < $hoje ? 'vencido' : ($val <= $em30 ? 'a-vencer' : 'no-prazo'));
    $st = (string)($l['STATUS'] ?? '');
    $desl = (int)($l['DESLIGADO'] ?? 0) === 1; // 25/10/2026: desligado não recebe link
    $sit = $st === '' ? ($desl ? $rot['desligado'] : $rot['sem']) : ($st === 'AGUARDANDO' ? ((int)$l['LINK_VALIDO'] ? strtr($rot['aguardando'], ['{d}' => $dt((string)$l['LINK_EXPIRA_EM'])]) : $rot['vencido'])
        : ($st === 'RESPONDIDA' ? strtr($rot['respondida'], ['{n}' => $pct($l['PERCENTUAL']), '{s}' => $rot[(string)$l['SUGESTAO']] ?? '']) : ($rot[$st] ?? $st)));
    $acoes = '';
    if ($podeEditar && !$desl && (int)$l['QUESTIONARIO_PUBLICADO']) $acoes .= $bt('link', ' data-func="' . (int)$l['CODFUNCIONARIO'] . '" data-aguardando="' . ($st === 'AGUARDANDO' && (int)$l['LINK_VALIDO'] ? 1 : 0) . '" data-nome="' . $e($l['FUNCIONARIO']) . '"');
    if ((int)($l['CODINTEGRACAO'] ?? 0) > 0 && in_array($st, ['RESPONDIDA', 'APROVADA', 'REPROVADA'], true)) $acoes .= $bt('decidir', ' data-cod="' . (int)$l['CODINTEGRACAO'] . '" data-nome="' . $e($l['FUNCIONARIO']) . '"');
    if ($st === 'APROVADA') $acoes .= $bt('certificado', ' data-cod="' . (int)$l['CODINTEGRACAO'] . '" data-nome="' . $e($l['FUNCIONARIO']) . '"');
    if ($podeEditar && in_array($st, ['AGUARDANDO', 'RESPONDIDA'], true)) $acoes .= $bt('cancelar', ' data-cod="' . (int)$l['CODINTEGRACAO'] . '"');
    $rows[] = ['attrs' => 'data-integracao="' . (int)($l['CODINTEGRACAO'] ?? 0) . '"', 'cells' => [
        'FORNECEDOR' => ['text' => (string)$l['RAZAOSOCIAL']], 'FUNCIONARIO' => ['text' => (string)$l['FUNCIONARIO'], 'html' => $e($l['FUNCIONARIO']) . greenPortalGridLocalLinha($acoes)],
        'TIPO' => ['text' => (string)$l['DSCTIPO']], 'UNIDADE' => ['text' => (string)$l['DSCUNIDADE']],
        'QUESTIONARIO' => ['text' => $l['CODIGO_QUESTIONARIO'] ? (string)($l['QUESTIONARIO'] ?: $l['CODIGO_QUESTIONARIO']) . ((int)$l['QUESTIONARIO_PUBLICADO'] ? '' : ' (não publicado)') : $rot['sem_q']],
        'SITUACAO' => ['text' => $sit, 'sort' => ['RESPONDIDA' => '1', 'AGUARDANDO' => '2', 'REPROVADA' => '3', 'APROVADA' => '4'][$st] ?? '5'],
        'REALIZACAO' => ['text' => $dt($real), 'sort' => $real],
        'VALIDADE' => ['text' => $dt($val), 'html' => greenPortalGridLocalPrazo($dt($val), $sinal), 'sort' => $val],
        'PORTARIA' => ['text' => isset($bloqueados[(int)$l['CODFORNECEDOR']]) ? $rot['forn_bloq'] : ($val !== '' && $val >= $hoje ? $rot['liberado'] : $rot['bloqueado'])],
    ]];
}
$btn = static fn(string $acao, string $icone, string $titulo, string $cor = 'success'): string => '<button type="button" class="btn btn-sm btn-outline-' . $cor . ' grid-crud-button" data-grid-acao-linha="' . $acao . '" disabled title="' . $e($titulo) . '" aria-label="' . $e($titulo) . '"><i class="bi ' . $icone . '"></i></button>';
$extras = $btn('link', 'bi-link-45deg', $T('b.link', ['pt' => 'Gerar link de integração para o funcionário responder', 'en' => 'Generate induction link for the employee', 'es' => 'Generar enlace de integración para el empleado']))
    . $btn('decidir', 'bi-clipboard-check', $T('b.decidir', ['pt' => 'Ver respostas e confirmar a decisão', 'en' => 'See answers and confirm the decision', 'es' => 'Ver respuestas y confirmar la decisión']))
    . $btn('certificado', 'bi-award', $T('b.certificado', ['pt' => 'Certificado de integração (PDF)', 'en' => 'Induction certificate (PDF)', 'es' => 'Certificado de integración (PDF)']))
    . $btn('cancelar', 'bi-x-octagon', $T('b.cancelar', ['pt' => 'Cancelar o link (deixa de abrir)', 'en' => 'Cancel the link', 'es' => 'Cancelar el enlace']), 'danger')
    . '<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-ifn-config title="' . $e($T('b.config', ['pt' => 'Configurar questionários por tipo de funcionário', 'en' => 'Configure questionnaires by employee type', 'es' => 'Configurar cuestionarios por tipo de empleado'])) . '"><i class="bi bi-gear"></i></button>';
greenPortalGridLocal(['transaction' => 'homologacaofornecedores_integracao', 'fragment_class' => 'integracao-funcionarios-fragment', 'id' => 'integracaoGrid', 'cell_boxes' => true, 'extras' => $extras,
    'buttons' => ['columns' => true], 'double_click_action' => 'decidir',
    'columns' => [
        'FORNECEDOR' => ['label' => $T('c.fornecedor', ['pt' => 'Fornecedor', 'en' => 'Supplier', 'es' => 'Proveedor']), 'filter' => 'select', 'width' => '170px'],
        'FUNCIONARIO' => ['label' => $T('c.funcionario', ['pt' => 'Funcionário', 'en' => 'Employee', 'es' => 'Empleado']), 'width' => '200px'],
        'TIPO' => ['label' => $T('c.tipo', ['pt' => 'Tipo', 'en' => 'Type', 'es' => 'Tipo']), 'filter' => 'select', 'width' => '115px'],
        'UNIDADE' => ['label' => $T('c.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']), 'filter' => 'select', 'width' => '110px'],
        'QUESTIONARIO' => ['label' => $T('c.questionario', ['pt' => 'Questionário', 'en' => 'Questionnaire', 'es' => 'Cuestionario']), 'filter' => 'select', 'width' => '160px'],
        'SITUACAO' => ['label' => $T('c.situacao', ['pt' => 'Situação da integração', 'en' => 'Induction status', 'es' => 'Situación de la integración']), 'filter' => 'text', 'width' => '230px'],
        'REALIZACAO' => ['label' => $T('c.realizacao', ['pt' => 'Integração realizada em', 'en' => 'Induction done on', 'es' => 'Integración realizada el']), 'filter' => 'none', 'width' => '120px'],
        'VALIDADE' => ['label' => $T('c.validade', ['pt' => 'Integração válida até', 'en' => 'Induction valid until', 'es' => 'Integración válida hasta']), 'filter' => 'none', 'width' => '120px'],
        'PORTARIA' => ['label' => $T('c.portaria', ['pt' => 'Portaria', 'en' => 'Gatehouse', 'es' => 'Portería']), 'filter' => 'select', 'width' => '100px'],
    ],
    'rows' => $rows, 'default_sort' => ['SITUACAO', 'asc'],
    'empty' => $T('vazio', ['pt' => 'Nenhum funcionário.', 'en' => 'No employees.', 'es' => 'Ningún empleado.'])]);
echo $estadoJs(['abrir' => max(0, (int)($_GET['integracao'] ?? 0))]);
greenPortalGridLocalScripts();
echo '<script src="../assets/js/integracao-funcionarios.js?v=1"></script></body></html>';
