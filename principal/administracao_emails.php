<?php
declare(strict_types=1);

/*
 * Administração › Configuração de e-mail (migração 360, 12/11/2026; item 4 da análise do 01_Mudancas_Gerais, parte SMTP).
 * Era a transação "Emails" em construção. Uma configuração de servidor de e-mail (SMTP) para todas as empresas, gravada no banco
 * universal (smtp_configuracao); vale no lugar das chaves SMTP_* / GREENPORTAL_URL do .env. Só o ADMINISTRADOR usa a tela.
 * - Botão Ligar/Desligar o envio: desligado, os alertas não viram e-mail e os e-mails pendentes da fila são cancelados no próximo
 *   ciclo do serviço de e-mail (a cada 2 min), em todas as empresas — ao religar, não sai nada acumulado.
 * - Senha: gravada cifrada; nunca é mostrada; em branco ao salvar = mantém a atual.
 * - E-mail de teste para um endereço digitado, com os valores da tela (mesmo antes de salvar e com o envio desligado).
 * Auditoria: ADMINISTRACAO_EMAILS / SALVAR, LIGAR, DESLIGAR, TESTE (sem a senha).
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/AuditService.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/SmtpConfiguracao.php';
require_once dirname(__DIR__) . '/classes/SmtpCliente.php';
require_once dirname(__DIR__) . '/classes/EmailFilaService.php';

$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$json = static function (array $d, int $c = 200): never { http_response_code($c); header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
$negar = static function (string $m, int $c) use ($post, $json): never { if ($post) $json(['ok' => false, 'message' => $m], $c); http_response_code($c); exit(htmlspecialchars($m, ENT_QUOTES, 'UTF-8')); };
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $negar('Sessão expirada. Entre novamente no portal.', 401);
$env = GreenPortalDatabase::env();
$pdo = TenantContext::connect($env, $_SESSION);
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $negar('Sessão expirada. Entre novamente no portal.', 401);
if (strtoupper(trim((string)($_SESSION['usuario']['codigo_perfil'] ?? ''))) !== 'ADMINISTRADOR') $negar('Somente o ADMINISTRADOR configura o servidor de e-mail.', 403);
$quem = trim((string)($_SESSION['usuario']['nome'] ?? '')) ?: (string)($_SESSION['usuario']['userid'] ?? '');
$_SESSION['smtp_cfg_csrf'] ??= bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['smtp_cfg_csrf'];
$auditar = static function (string $acao, string $res, array $det = []) use ($pdo): void { try { AuditService::record($pdo, 'ADMINISTRACAO_EMAILS', $acao, $res, 'smtp_configuracao', '1', $det); } catch (Throwable) {} };

$tabelaOk = true;
try { $u = SmtpConfiguracao::universal($env); $tabelaOk = (int)$u->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'smtp_configuracao'")->fetchColumn() > 0; }
catch (Throwable $e) { $negar('Banco universal indisponível.', 503); }
$linha = $tabelaOk ? SmtpConfiguracao::linha($env, true) : null;

$crud = static function (string $proc, array $args) use ($u): void { $s = $u->prepare('CALL ' . $proc . '(' . implode(',', array_fill(0, count($args), '?')) . ')'); $s->execute($args); $s->closeCursor(); };
$gravar = static function (array $dados) use ($crud, $linha): void {
    if ($linha) $crud('sp_crud_update', [SmtpConfiguracao::TABELA, json_encode(['codconfiguracao' => 1]), json_encode($dados, JSON_UNESCAPED_UNICODE)]);
    else $crud('sp_crud_insert', [SmtpConfiguracao::TABELA, json_encode(['codconfiguracao' => 1] + $dados, JSON_UNESCAPED_UNICODE)]);
};
// valores do formulário (validados); $exigir = false no teste parcial
$ler = static function (array $p): array {
    $seg = strtolower(trim((string)($p['seguranca'] ?? 'tls')));
    $v = [
        'host' => trim((string)($p['host'] ?? '')), 'porta' => (int)($p['porta'] ?? 0), 'seguranca' => in_array($seg, ['tls', 'ssl', 'nenhuma'], true) ? $seg : 'tls',
        'usuario' => trim((string)($p['usuario'] ?? '')), 'remetente' => trim((string)($p['remetente'] ?? '')), 'remetente_nome' => trim((string)($p['remetente_nome'] ?? '')),
        'timeout' => (int)($p['timeout'] ?? 30), 'verificar_certificado' => !empty($p['verificar_certificado']) ? 1 : 0, 'ehlo' => trim((string)($p['ehlo'] ?? '')),
        'url_portal' => rtrim(trim((string)($p['url_portal'] ?? '')), '/'),
    ];
    $erros = [];
    if ($v['host'] === '' || !preg_match('/^[A-Za-z0-9.\-]+$/', $v['host'])) $erros[] = 'Informe o servidor SMTP (nome ou IP, sem "http://").';
    if ($v['porta'] < 1 || $v['porta'] > 65535) $erros[] = 'Porta inválida (comum: 587 para TLS, 465 para SSL, 25 sem segurança).';
    if (filter_var($v['remetente'], FILTER_VALIDATE_EMAIL) === false) $erros[] = 'Informe o e-mail do remetente.';
    if ($v['timeout'] < 5 || $v['timeout'] > 120) $erros[] = 'Tempo limite entre 5 e 120 segundos.';
    if ($v['url_portal'] !== '' && !preg_match('#^https?://[^\s]+$#i', $v['url_portal'])) $erros[] = 'Endereço do portal deve começar com http:// ou https://.';
    if ($v['ehlo'] !== '' && !preg_match('/^[A-Za-z0-9.\-]+$/', $v['ehlo'])) $erros[] = 'Nome EHLO inválido.';
    if (mb_strlen($v['remetente_nome']) > 150) $erros[] = 'Nome do remetente muito longo.';
    if ($v['remetente_nome'] === '') $v['remetente_nome'] = 'GreenSolutions';
    return [$v, $erros];
};

if ($post) {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $json(['ok' => false, 'message' => 'A tela ficou desatualizada. Recarregue (F5).'], 419);
    if (!$tabelaOk) $json(['ok' => false, 'message' => 'Falta aplicar a migração 360 (tabela smtp_configuracao).'], 503);
    $acao = (string)($_POST['acao'] ?? '');
    try {
        if ($acao === 'salvar') {
            [$v, $erros] = $ler($_POST);
            if ($erros) $json(['ok' => false, 'message' => implode(' ', $erros)], 422);
            $senhaNova = (string)($_POST['senha'] ?? '');
            if (!empty($_POST['remover_senha'])) $v['senha_cifrada'] = '';
            elseif ($senhaNova !== '') $v['senha_cifrada'] = SmtpConfiguracao::cifrar($senhaNova, $env);
            elseif (!$linha) $v['senha_cifrada'] = SmtpConfiguracao::cifrar((string)($env['SMTP_SENHA'] ?? ''), $env); // 1ª gravação: aproveita a senha do .env
            $v['alterado_em'] = date('Y-m-d H:i:s'); $v['alterado_por'] = $quem;
            if (!$linha) $v['ativo'] = 0;
            $gravar($v);
            $det = $v; unset($det['senha_cifrada']); $det['senha'] = array_key_exists('senha_cifrada', $v) ? ($v['senha_cifrada'] === '' ? 'removida' : 'alterada') : 'mantida';
            $auditar('SALVAR', 'SUCESSO', $det);
            $json(['ok' => true, 'message' => 'Configuração salva.' . ($linha ? '' : ' O envio continua DESLIGADO: use "Ligar envio" depois de testar.'), 'recarregar' => true]);
        }
        if ($acao === 'ligar' || $acao === 'desligar') {
            if (!$linha) $json(['ok' => false, 'message' => 'Salve a configuração antes de ligar o envio.'], 422);
            $ligar = $acao === 'ligar';
            if ($ligar && !SmtpCliente::parametrosOk(SmtpConfiguracao::aplicar($env))) $json(['ok' => false, 'message' => 'Preencha e salve o servidor e o remetente antes de ligar.'], 422);
            $gravar(['ativo' => $ligar ? 1 : 0, 'ligado_desligado_em' => date('Y-m-d H:i:s'), 'ligado_desligado_por' => $quem]);
            $auditar($ligar ? 'LIGAR' : 'DESLIGAR', 'SUCESSO');
            $json(['ok' => true, 'message' => $ligar ? 'Envio de e-mails LIGADO. Vale a partir do próximo ciclo do serviço (até 2 minutos).' : 'Envio de e-mails DESLIGADO. No próximo ciclo (até 2 minutos) os e-mails pendentes da fila são cancelados.', 'recarregar' => true]);
        }
        if ($acao === 'testar') {
            $destino = trim((string)($_POST['destino'] ?? ''));
            if (filter_var($destino, FILTER_VALIDATE_EMAIL) === false) $json(['ok' => false, 'message' => 'Informe um e-mail válido para o teste.'], 422);
            [$v, $erros] = $ler($_POST);
            if ($erros) $json(['ok' => false, 'message' => implode(' ', $erros)], 422);
            $senha = (string)($_POST['senha'] ?? '');
            if ($senha === '' && empty($_POST['remover_senha'])) $senha = (string)(SmtpConfiguracao::aplicar($env)['SMTP_SENHA'] ?? '');
            $envTeste = ['SMTP_HOST' => $v['host'], 'SMTP_PORT' => (string)$v['porta'], 'SMTP_SEGURANCA' => $v['seguranca'], 'SMTP_USUARIO' => $v['usuario'], 'SMTP_SENHA' => $senha,
                'SMTP_REMETENTE' => $v['remetente'], 'SMTP_REMETENTE_NOME' => $v['remetente_nome'], 'SMTP_TIMEOUT' => (string)$v['timeout'],
                'SMTP_VERIFICAR_CERTIFICADO' => $v['verificar_certificado'] ? '1' : '0', 'SMTP_EHLO' => $v['ehlo']];
            $texto = "Este é um e-mail de teste enviado pela tela Administração › Configuração de e-mail do GreenPortal.\n\nEnviado por: $quem\nData: " . date('d/m/Y H:i:s') . "\nServidor: {$v['host']}:{$v['porta']} ({$v['seguranca']})";
            $resultado = '';
            try {
                $smtp = new SmtpCliente($envTeste, false);
                $smtp->conectar();
                $smtp->enviar([$destino], '', 'Teste de e-mail do GreenPortal', EmailFilaService::html('GreenPortal', 'Teste de e-mail do GreenPortal', $texto), $texto);
                $smtp->fechar();
                $resultado = 'OK: enviado para ' . $destino;
            } catch (Throwable $e) { $resultado = 'FALHOU: ' . mb_substr($e->getMessage(), 0, 900); }
            if ($linha) $gravar(['ultimo_teste_em' => date('Y-m-d H:i:s'), 'ultimo_teste_resultado' => $resultado]);
            $auditar('TESTE', str_starts_with($resultado, 'OK') ? 'SUCESSO' : 'ERRO', ['destino' => $destino, 'resultado' => $resultado]);
            $json(['ok' => str_starts_with($resultado, 'OK'), 'message' => str_starts_with($resultado, 'OK') ? 'E-mail de teste enviado para ' . $destino . '. Confira a caixa de entrada (e o spam).' : $resultado]);
        }
        $json(['ok' => false, 'message' => 'Ação inválida.'], 400);
    } catch (Throwable $e) {
        error_log('administracao_emails: ' . $e->getMessage());
        $auditar(strtoupper($acao ?: 'ACAO'), 'ERRO', ['mensagem' => mb_substr($e->getMessage(), 0, 300)]);
        $json(['ok' => false, 'message' => 'Não foi possível concluir: ' . mb_substr($e->getMessage(), 0, 300)], 500);
    }
}

// valores mostrados: os gravados; sem gravação, os do .env (sem a senha)
$origem = $linha ? 'tela' : 'env';
$val = $linha ? $linha : [
    'host' => (string)($env['SMTP_HOST'] ?? ''), 'porta' => (int)($env['SMTP_PORT'] ?? 587), 'seguranca' => strtolower((string)($env['SMTP_SEGURANCA'] ?? 'tls')),
    'usuario' => (string)($env['SMTP_USUARIO'] ?? ''), 'remetente' => (string)($env['SMTP_REMETENTE'] ?? ''), 'remetente_nome' => (string)($env['SMTP_REMETENTE_NOME'] ?? 'GreenSolutions'),
    'timeout' => (int)($env['SMTP_TIMEOUT'] ?? 30), 'verificar_certificado' => (string)($env['SMTP_VERIFICAR_CERTIFICADO'] ?? '1') !== '0' ? 1 : 0,
    'ehlo' => (string)($env['SMTP_EHLO'] ?? ''), 'url_portal' => (string)($env['GREENPORTAL_URL'] ?? ''), 'ativo' => 0,
];
$temSenha = $linha ? (string)($linha['senha_cifrada'] ?? '') !== '' : (string)($env['SMTP_SENHA'] ?? '') !== '';
$ativo = (int)($val['ativo'] ?? 0) === 1;
$fila = [];
try { foreach ($pdo->query("SELECT status, COUNT(*) n FROM email_fila WHERE criado_em >= NOW() - INTERVAL 30 DAY GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $r) $fila[(string)$r['status']] = (int)$r['n']; } catch (Throwable) { $fila = null; }
$theme = in_array((string)($_SESSION['layout_tema'] ?? ''), ['verde', 'azul', 'cinza'], true) ? (string)$_SESSION['layout_tema'] : 'verde';
$emailUsuario = '';
try { $s = $pdo->prepare('CALL sp_crud_select(?, ?, NULL, 1, 0)'); $s->execute(['vw_usuarios_grid', json_encode(['codusuario' => ['value' => (string)(int)($_SESSION['usuario']['codusuario'] ?? 0), 'exact' => true]])]); $emailUsuario = (string)(($s->fetch(PDO::FETCH_ASSOC) ?: [])['email'] ?? ''); $s->closeCursor(); } catch (Throwable) {}
session_write_close();
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmt = static fn(?string $d): string => $d ? date('d/m/Y H:i', strtotime($d) ?: time()) : '';
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Configuração de e-mail</title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<link href="../assets/css/app.css?v=5" rel="stylesheet"><link href="../assets/css/main.css?v=18" rel="stylesheet"><link href="../assets/css/design-system.css?v=3" rel="stylesheet"><link href="../assets/css/botoes-crud.css?v=4" rel="stylesheet">
<style>
.sm-status{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.sm-pill{border-radius:14px;padding:3px 10px;font-weight:600;font-size:12px}.sm-pill.on{background:#dff2e7;color:#1f6b40}.sm-pill.off{background:#fde4e1;color:#a5281b}
.sm-fila span{display:inline-block;margin-right:14px}.sm-ajuda{font-size:11px;color:var(--gp-muted,#6b8279)}
.setup-field .form-check{margin-top:6px;display:flex;align-items:center;gap:6px;padding-left:0}.setup-field .form-check-input{width:15px!important;height:15px!important;min-height:0!important;padding:0!important;margin:0!important;float:none;flex:0 0 auto}
.setup-field .form-check-label{font-weight:400}
.setup-field select{width:100%;min-height:36px;padding:6px 10px;border:1px solid var(--gp-border,#d6e2dc);border-radius:8px;background:#fff;font-size:12px;color:var(--gp-text)}
</style></head>
<body data-transacao="administracao_emails">
<section class="setup-fragment">
  <div class="crud-toolbar menu-grid-toolbar"><div class="crud-actions crud-actions-standard">
    <button class="btn btn-sm btn-success grid-crud-button grid-icon-button" type="submit" form="smForm" data-crud-action="save" title="Salvar configuração" aria-label="Salvar configuração"><i class="bi bi-check2"></i></button>
    <?php if ($linha): ?><button class="btn btn-sm <?= $ativo ? 'btn-outline-danger' : 'btn-outline-success' ?> grid-crud-button grid-icon-button" type="button" data-sm-acao="<?= $ativo ? 'desligar' : 'ligar' ?>" title="<?= $ativo ? 'Desligar o envio de e-mails' : 'Ligar o envio de e-mails' ?>" aria-label="<?= $ativo ? 'Desligar o envio de e-mails' : 'Ligar o envio de e-mails' ?>"><i class="bi <?= $ativo ? 'bi-pause-circle' : 'bi-play-circle' ?>"></i></button><?php endif; ?>
    <button class="btn btn-sm btn-outline-primary grid-crud-button grid-icon-button" type="button" data-sm-acao="testar" title="Enviar e-mail de teste (para o endereço do campo E-mail para teste)" aria-label="Enviar e-mail de teste"><i class="bi bi-send"></i></button>
    <button class="btn btn-sm btn-outline-secondary grid-crud-button grid-icon-button" type="button" data-crud-action="reload" onclick="window.location.reload()" title="Recarregar" aria-label="Recarregar"><i class="bi bi-arrow-clockwise"></i></button>
    <button class="btn btn-sm btn-outline-info grid-crud-button grid-icon-button" type="button" data-open-transacao-ajuda data-help-transaction="administracao_emails" title="Ajuda" aria-label="Ajuda"><i class="bi bi-question-circle"></i></button><script src="../assets/js/ajuda-transacao.js?v=1"></script>
  </div></div>
  <?php if (!$tabelaOk): ?><div class="alert alert-warning py-2" role="alert">Falta aplicar a migração 360 (scripts\aplicar-migracao-360-smtp.php). Até lá, a configuração vem do .env do servidor.</div><?php endif; ?>
  <div id="smMsg" role="status"></div>
  <form id="smForm" class="setup-form" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <div class="setup-card">
      <div class="sm-status">
        <span class="sm-pill <?= $ativo ? 'on' : 'off' ?>"><i class="bi <?= $ativo ? 'bi-send-check' : 'bi-send-slash' ?> me-1"></i>Envio de e-mails <?= $ativo ? 'LIGADO' : 'DESLIGADO' ?></span>
        <span class="sm-ajuda"><?php if ($linha && $linha['ligado_desligado_em']): ?><?= $ativo ? 'Ligado' : 'Desligado' ?> em <?= $e($fmt($linha['ligado_desligado_em'])) ?> por <?= $e($linha['ligado_desligado_por']) ?>.<?php elseif (!$linha): ?>Ainda não gravada: os valores abaixo vêm do .env do servidor. Salve para a tela passar a valer; o envio começa desligado.<?php endif; ?></span>
      </div>
      <div class="sm-ajuda mt-1">Desligado: os alertas não viram e-mail e os e-mails pendentes da fila são cancelados no próximo ciclo do serviço (até 2 min), em todas as empresas. Ao religar, nada do período desligado é enviado.</div>
      <?php if (is_array($fila)): ?><div class="sm-fila sm-ajuda mt-1"><b>Fila desta empresa (30 dias):</b> <span>Pendentes: <?= (int)($fila['PENDENTE'] ?? 0) + (int)($fila['PROCESSANDO'] ?? 0) ?></span><span>Enviados: <?= (int)($fila['CONCLUIDA'] ?? 0) ?></span><span>Com erro: <?= (int)($fila['ERRO'] ?? 0) ?></span><span>Cancelados: <?= (int)($fila['CANCELADA'] ?? 0) ?></span></div><?php endif; ?>
    </div>
    <div class="setup-card"><div class="setup-fields">
      <div class="setup-field"><label for="host">Servidor SMTP (host)</label><input type="text" id="host" name="host" value="<?= $e($val['host']) ?>" placeholder="smtp.empresa.com.br"></div>
      <div class="setup-field"><label for="porta">Porta</label><input type="number" id="porta" name="porta" min="1" max="65535" value="<?= (int)$val['porta'] ?>"></div>
      <div class="setup-field"><label for="seguranca">Segurança</label><select id="seguranca" name="seguranca">
        <?php foreach (['tls' => 'TLS (STARTTLS) — porta 587', 'ssl' => 'SSL — porta 465', 'nenhuma' => 'Nenhuma — porta 25 (só rede interna)'] as $k => $r): ?><option value="<?= $e($k) ?>"<?= $val['seguranca'] === $k ? ' selected' : '' ?>><?= $e($r) ?></option><?php endforeach; ?>
      </select></div>
      <div class="setup-field"><label for="usuario">Usuário (login no servidor)</label><input type="text" id="usuario" name="usuario" value="<?= $e($val['usuario']) ?>" placeholder="em branco = sem autenticação"></div>
      <div class="setup-field"><label for="senha">Senha</label><input type="password" id="senha" name="senha" autocomplete="new-password" placeholder="<?= $temSenha ? '•••••• (gravada — em branco mantém)' : 'sem senha gravada' ?>">
        <?php if ($temSenha): ?><div class="form-check"><input class="form-check-input" type="checkbox" id="remover_senha" name="remover_senha" value="1"><label class="form-check-label sm-ajuda" for="remover_senha">Remover a senha gravada</label></div><?php endif; ?>
        <span class="sm-ajuda">Gravada cifrada; a tela nunca mostra a senha.</span></div>
      <div class="setup-field"><label for="remetente">E-mail do remetente</label><input type="email" id="remetente" name="remetente" value="<?= $e($val['remetente']) ?>" placeholder="naoresponda@empresa.com.br"><span class="sm-ajuda">Normalmente uma conta (ou alias) do próprio servidor.</span></div>
      <div class="setup-field"><label for="remetente_nome">Nome do remetente</label><input type="text" id="remetente_nome" name="remetente_nome" value="<?= $e($val['remetente_nome']) ?>"></div>
      <div class="setup-field"><label for="url_portal">Endereço do portal (botão "Abrir no portal")</label><input type="text" id="url_portal" name="url_portal" value="<?= $e($val['url_portal']) ?>" placeholder="http://187.191.99.180/greenportal"><span class="sm-ajuda">Sem ele, os alertas por e-mail saem sem o botão.</span></div>
      <div class="setup-field"><label for="timeout">Tempo limite (segundos)</label><input type="number" id="timeout" name="timeout" min="5" max="120" value="<?= (int)$val['timeout'] ?>"></div>
      <div class="setup-field"><label for="ehlo">Nome EHLO (opcional)</label><input type="text" id="ehlo" name="ehlo" value="<?= $e($val['ehlo']) ?>" placeholder="nome do servidor"></div>
      <div class="setup-field"><label for="verificar_certificado">Certificado do servidor</label><div class="form-check"><input class="form-check-input" type="checkbox" id="verificar_certificado" name="verificar_certificado" value="1"<?= (int)$val['verificar_certificado'] === 1 ? ' checked' : '' ?>><label class="form-check-label" for="verificar_certificado">Verificar (desmarque só para servidor interno com certificado próprio)</label></div></div>
      <div class="setup-field"><label for="destino">E-mail para teste</label><input type="email" id="destino" value="<?= $e($emailUsuario) ?>" placeholder="seu e-mail"><span class="sm-ajuda">O botão <i class="bi bi-send"></i> envia só para este endereço, com os valores da tela (mesmo sem salvar e com o envio desligado).<?php if ($linha && $linha['ultimo_teste_em']): ?><br>Último teste em <?= $e($fmt($linha['ultimo_teste_em'])) ?>: <?= $e($linha['ultimo_teste_resultado']) ?><?php endif; ?></span></div>
    </div>
    <?php if ($linha && $linha['alterado_em']): ?><div class="sm-ajuda mt-2">Última alteração em <?= $e($fmt($linha['alterado_em'])) ?> por <?= $e($linha['alterado_por']) ?>.</div><?php endif; ?>
    </div>
  </form>
</section>
<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script>
<script>
(function () {
  var form = document.getElementById('smForm'), msg = document.getElementById('smMsg');
  function aviso(t, ok) { msg.className = 'alert py-2 ' + (ok ? 'alert-success' : 'alert-danger'); msg.textContent = t; msg.scrollIntoView({ block: 'nearest' }); }
  function perguntar(texto, seguir) {
    var host = window.top || window;
    try { if (host.GreenPortalPopups && typeof host.GreenPortalPopups.mensagem === 'function') return host.GreenPortalPopups.mensagem({ titulo: 'Configuração de e-mail', mensagem: texto, icone: 'bi-question-circle', confirmar: seguir }); } catch (e) {}
    if (window.confirm(texto)) seguir();
  }
  function enviar(acao, extra, botao) {
    var d = new FormData(form); d.append('acao', acao); Object.keys(extra || {}).forEach(function (k) { d.append(k, extra[k]); });
    if (botao) botao.disabled = true;
    msg.className = 'alert alert-secondary py-2'; msg.textContent = acao === 'testar' ? 'Enviando o e-mail de teste…' : 'Gravando…';
    fetch(window.location.pathname, { method: 'POST', body: d, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Resposta inválida do servidor.' }; }); })
      .then(function (j) { aviso(j.message || (j.ok ? 'Feito.' : 'Falhou.'), !!j.ok); if (j.ok && j.recarregar) setTimeout(function () { window.location.reload(); }, 1200); })
      .catch(function () { aviso('Falha de comunicação com o servidor.', false); })
      .then(function () { if (botao) botao.disabled = false; });
  }
  form.addEventListener('submit', function (ev) { ev.preventDefault(); enviar('salvar', null, document.querySelector('[data-crud-action="save"]')); });
  document.querySelectorAll('[data-sm-acao]').forEach(function (b) {
    b.addEventListener('click', function () {
      var a = b.getAttribute('data-sm-acao');
      if (a === 'testar') return enviar('testar', { destino: document.getElementById('destino').value }, b);
      perguntar(a === 'ligar' ? 'Ligar o envio de e-mails para todas as empresas? Os alertas novos passam a ser enviados por e-mail.' : 'Desligar o envio de e-mails para todas as empresas? Os e-mails pendentes da fila serão cancelados.', function () { enviar(a, null, b); });
    });
  });
}());
</script>
<?php require_once dirname(__DIR__) . '/components/toolbar_grupos.php'; greenPortalToolbarGruposAuto(); ?>
</body></html>
