<?php
declare(strict_types=1);

/*
 * Administração › Logs do sistema (Revisão G item 16; migração 421, 26/11/2026).
 * Consulta e download (CSV) dos logs da empresa: Ações (auditoria: acessos, gravações, exportações, IA...) e Alterações de dados
 * (histórico obrigatório das tabelas de gestão: campo, valor anterior e novo). Filtros por período, usuário e texto.
 * Retenção contratável: LOG_RETENCAO_DIAS (portal_config; padrão 365) limita o período disponível para consulta e download.
 * Envio automático: LOG_EMAIL_DESTINO e LOG_EMAIL_FREQUENCIA (semanal / mensal) — o serviço scripts/logs-email-worker.php gera o
 * CSV do período e manda o link por e-mail (fila de e-mails do portal).
 * Permissão: menu LOGS_SISTEMA (visualizar; exportar Excel para baixar; editar para as configurações).
 * Leitura: SELECT nas views vw_logs_acoes e vw_logs_alteracoes (filtro de período); gravação da configuração por sp_crud_*.
 */
require_once dirname(__DIR__) . '/classes/PaginaSimples.php';
use PaginaSimples as P;

P::abrir('LOGS_SISTEMA');
if (!P::existe('portal_config')) P::sair(503, P::L('Logs do sistema ainda não instalados nesta empresa (migração 421).', 'System logs are not installed yet (migration 421).', 'Registros del sistema aún no instalados (migración 421).'));
$csrf = P::csrf('logs_csrf');
$cfgPadrao = ['LOG_RETENCAO_DIAS' => '365', 'LOG_EMAIL_DESTINO' => '', 'LOG_EMAIL_FREQUENCIA' => 'NENHUM'];
$cfg = $cfgPadrao;
foreach (P::sel('vw_portal_config', [], 200) as $r) if (array_key_exists((string)$r['CHAVE'], $cfg)) $cfg[(string)$r['CHAVE']] = (string)$r['VALOR'];
$retencao = max(30, min(3650, (int)$cfg['LOG_RETENCAO_DIAS']));
$msg = ''; $erros = [];
$baixarArquivo = basename((string)($_GET['arquivo'] ?? ''));
if ($baixarArquivo !== '') {
    // CSV gerado pelo serviço de envio por e-mail (link do e-mail)
    if (!P::pode('exportar_excel') && !P::admin()) P::sair(403, P::L('Seu perfil não pode baixar os logs.', 'Your profile cannot download the logs.', 'Su perfil no puede descargar los registros.'));
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs_export' . DIRECTORY_SEPARATOR . preg_replace('/[^A-Za-z0-9_]/', '', (string)$_SESSION['tenant']['banco_dados']);
    $f = $dir . DIRECTORY_SEPARATOR . $baixarArquivo;
    if (!preg_match('/^logs_[0-9_\-]+\.csv$/', $baixarArquivo) || !is_file($f)) P::sair(404, P::L('Arquivo não encontrado (pode ter passado do prazo de retenção).', 'File not found (it may be past the retention period).', 'Archivo no encontrado.'));
    AuditService::record(P::$pdo, 'administracao_logs', 'DOWNLOAD', 'SUCESSO', 'logs', $baixarArquivo);
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $baixarArquivo . '"'); readfile($f); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    P::conferirCsrf('logs_csrf');
    if (!P::pode('editar')) P::sair(403, P::L('Seu perfil não altera as configurações.', 'Your profile cannot change the settings.', 'Su perfil no cambia la configuración.'));
    $novo = ['LOG_RETENCAO_DIAS' => (string)max(30, min(3650, (int)($_POST['LOG_RETENCAO_DIAS'] ?? 365))), 'LOG_EMAIL_DESTINO' => trim((string)($_POST['LOG_EMAIL_DESTINO'] ?? '')), 'LOG_EMAIL_FREQUENCIA' => in_array((string)($_POST['LOG_EMAIL_FREQUENCIA'] ?? ''), ['NENHUM', 'SEMANAL', 'MENSAL'], true) ? (string)$_POST['LOG_EMAIL_FREQUENCIA'] : 'NENHUM'];
    if ($novo['LOG_EMAIL_DESTINO'] !== '' && !filter_var($novo['LOG_EMAIL_DESTINO'], FILTER_VALIDATE_EMAIL)) $erros[] = P::L('E-mail inválido.', 'Invalid e-mail.', 'Correo inválido.');
    if ($novo['LOG_EMAIL_FREQUENCIA'] !== 'NENHUM' && $novo['LOG_EMAIL_DESTINO'] === '') $erros[] = P::L('Informe o e-mail para o envio automático.', 'Enter the e-mail for automatic sending.', 'Informe el correo para el envío automático.');
    if (!P::admin() && (int)$novo['LOG_RETENCAO_DIAS'] !== $retencao) { $novo['LOG_RETENCAO_DIAS'] = (string)$retencao; $erros[] = P::L('A retenção é contratada: só o ADMINISTRADOR (Green) altera.', 'Retention is contracted: only the ADMINISTRATOR changes it.', 'La retención es contratada: solo el ADMINISTRADOR la cambia.'); }
    if (!array_filter($erros, static fn($e) => !str_contains($e, 'ADMINISTRADOR'))) {
        foreach ($novo as $k => $v) {
            if (P::sel('portal_config', ['CHAVE' => $k], 1)) P::upd('portal_config', ['CHAVE' => $k], ['VALOR' => $v, 'CODUSUARIO' => P::$uid ?: null]);
            else P::ins('portal_config', ['CHAVE' => $k, 'VALOR' => $v, 'CODUSUARIO' => P::$uid ?: null]);
        }
        $cfg = $novo + $cfg; $retencao = (int)$cfg['LOG_RETENCAO_DIAS'];
        AuditService::record(P::$pdo, 'administracao_logs', 'CONFIGURAR', 'SUCESSO', 'portal_config', null, ['retencao' => $novo['LOG_RETENCAO_DIAS'], 'frequencia' => $novo['LOG_EMAIL_FREQUENCIA']]);
        $msg = P::L('Configuração gravada.', 'Settings saved.', 'Configuración guardada.');
    }
}

// filtros
$aba = (string)($_GET['aba'] ?? 'acoes') === 'alteracoes' ? 'alteracoes' : 'acoes';
$limite = (new DateTimeImmutable('today'))->modify('-' . $retencao . ' days')->format('Y-m-d');
$de = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['de'] ?? '')) ? (string)$_GET['de'] : (new DateTimeImmutable('today'))->modify('-7 days')->format('Y-m-d');
$ate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ate'] ?? '')) ? (string)$_GET['ate'] : date('Y-m-d');
if ($de < $limite) $de = $limite;
$usuario = (int)($_GET['usuario'] ?? 0);
$texto = mb_substr(trim((string)($_GET['texto'] ?? '')), 0, 100);
$view = $aba === 'acoes' ? 'vw_logs_acoes' : 'vw_logs_alteracoes';
$colUsu = $aba === 'acoes' ? null : 'codusuario';
$linhas = []; $erroView = '';
if (P::existe($view)) {
    try {
        $cols = P::$pdo->query('SELECT * FROM `' . $view . '` LIMIT 0');
        $nomes = []; for ($i = 0; $i < $cols->columnCount(); $i++) $nomes[] = (string)($cols->getColumnMeta($i)['name'] ?? '');
        $colUsu = current(array_filter($nomes, static fn($c) => in_array(strtolower($c), ['codusuario', 'usuario', 'cod_usuario'], true))) ?: null;
        $colTexto = array_values(array_filter($nomes, static fn($c) => in_array(strtolower($c), ['transacao', 'acao', 'entidade', 'registro', 'resultado', 'tabela', 'campo', 'operacao', 'registro_id', 'origem', 'valor_anterior', 'valor_novo', 'detalhes'], true)));
        $sql = 'SELECT * FROM `' . $view . '` WHERE `DATA_EVENTO` >= ? AND `DATA_EVENTO` < DATE_ADD(?, INTERVAL 1 DAY)';
        $par = [$de, $ate];
        if ($usuario > 0 && $colUsu) { $sql .= ' AND `' . $colUsu . '` = ?'; $par[] = $usuario; }
        if ($texto !== '' && $colTexto) { $sql .= ' AND (' . implode(' OR ', array_map(static fn($c) => '`' . $c . '` LIKE ?', $colTexto)) . ')'; foreach ($colTexto as $_) $par[] = '%' . $texto . '%'; }
        $sql .= ' ORDER BY `DATA_EVENTO` DESC LIMIT ' . (isset($_GET['csv']) ? 100000 : 1000);
        $s = P::$pdo->prepare($sql); $s->execute($par); $linhas = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
    } catch (Throwable $e) { $erroView = $e->getMessage(); }
} else $erroView = P::L('View de logs ausente (rode a migração 421).', 'Log view missing (run migration 421).', 'Falta la vista de registros (migración 421).');
if (isset($_GET['csv']) && !$erroView) {
    if (!P::pode('exportar_excel') && !P::admin()) P::sair(403, P::L('Seu perfil não pode baixar os logs.', 'Your profile cannot download the logs.', 'Su perfil no puede descargar los registros.'));
    AuditService::record(P::$pdo, 'administracao_logs', 'EXPORTAR', 'SUCESSO', $view, null, ['de' => $de, 'ate' => $ate, 'linhas' => count($linhas)]);
    $nome = 'logs_' . $aba . '_' . $de . '_' . $ate . '.csv';
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $nome . '"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    if ($linhas) fputcsv($out, array_keys($linhas[0]), ';');
    foreach ($linhas as $l) fputcsv($out, array_map(static fn($v) => is_scalar($v) || $v === null ? (string)$v : json_encode($v), $l), ';');
    exit;
}
$pessoas = P::usuarios();
session_write_close();
$q = static fn(array $extra) => '?' . http_build_query(array_merge(['aba' => $aba, 'de' => $de, 'ate' => $ate, 'usuario' => $usuario ?: '', 'texto' => $texto], $extra));
$mostrar = $aba === 'acoes' ? ['DATA_EVENTO' => P::L('Quando', 'When', 'Cuándo'), 'NOME_USUARIO' => P::L('Usuário', 'User', 'Usuario'), 'TRANSACAO' => P::L('Tela', 'Screen', 'Pantalla'), 'ACAO' => P::L('Ação', 'Action', 'Acción'), 'ENTIDADE' => P::L('Objeto', 'Object', 'Objeto'), 'REGISTRO' => P::L('Registro', 'Record', 'Registro'), 'RESULTADO' => P::L('Resultado', 'Result', 'Resultado')]
    : ['DATA_EVENTO' => P::L('Quando', 'When', 'Cuándo'), 'NOME_USUARIO' => P::L('Usuário', 'User', 'Usuario'), 'TABELA' => P::L('Tabela', 'Table', 'Tabla'), 'REGISTRO_ID' => P::L('Registro', 'Record', 'Registro'), 'OPERACAO' => P::L('Operação', 'Operation', 'Operación'), 'CAMPO' => P::L('Campo', 'Field', 'Campo'), 'VALOR_ANTERIOR' => P::L('Antes', 'Before', 'Antes'), 'VALOR_NOVO' => P::L('Depois', 'After', 'Después'), 'ORIGEM' => P::L('Origem', 'Source', 'Origen')];
$val = static function (array $l, string $c): string { foreach ($l as $k => $v) if (strcasecmp((string)$k, $c) === 0) return (string)$v; return ''; };
P::cabecalho(P::L('Logs do sistema', 'System logs', 'Registros del sistema'), 'bi-journal-text', 'administracao_logs', '.lg-tab td{max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}');
?>
<p class="ps-ajuda small mb-2"><?= P::e(sprintf(P::L('Ações de cada usuário e alterações de dados, para consulta e download. Disponível: últimos %d dias (retenção contratada).', 'Each user action and data changes, to view and download. Available: last %d days (contracted retention).', 'Acciones de cada usuario y cambios de datos, para consulta y descarga. Disponible: últimos %d días (retención contratada).'), $retencao)) ?></p>
<?php if ($msg !== ''): ?><div class="alert alert-success py-2"><?= P::e($msg) ?></div><?php endif; foreach ($erros as $er): ?><div class="alert alert-danger py-1 mb-1"><?= P::e($er) ?></div><?php endforeach; ?>
<ul class="nav nav-tabs mb-2"><li class="nav-item"><a class="nav-link<?= $aba === 'acoes' ? ' active' : '' ?>" href="<?= P::e($q(['aba' => 'acoes'])) ?>"><?= P::e(P::L('Ações', 'Actions', 'Acciones')) ?></a></li><li class="nav-item"><a class="nav-link<?= $aba === 'alteracoes' ? ' active' : '' ?>" href="<?= P::e($q(['aba' => 'alteracoes'])) ?>"><?= P::e(P::L('Alterações de dados', 'Data changes', 'Cambios de datos')) ?></a></li></ul>
<form method="get" class="d-flex flex-wrap gap-2 align-items-end mb-2"><input type="hidden" name="aba" value="<?= P::e($aba) ?>">
<label class="small"><?= P::e(P::L('De', 'From', 'Desde')) ?><input type="date" class="form-control form-control-sm" name="de" value="<?= P::e($de) ?>" min="<?= P::e($limite) ?>" data-sem-memoria></label>
<label class="small"><?= P::e(P::L('Até', 'To', 'Hasta')) ?><input type="date" class="form-control form-control-sm" name="ate" value="<?= P::e($ate) ?>" data-sem-memoria></label>
<label class="small"><?= P::e(P::L('Usuário', 'User', 'Usuario')) ?><select class="form-select form-select-sm" name="usuario" data-sem-memoria><?= P::opcoes($pessoas, $usuario ?: '', P::L('Todos', 'All', 'Todos')) ?></select></label>
<label class="small"><?= P::e(P::L('Texto', 'Text', 'Texto')) ?><input class="form-control form-control-sm" name="texto" value="<?= P::e($texto) ?>" data-sem-memoria></label>
<button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-search me-1"></i><?= P::e(P::L('Pesquisar', 'Search', 'Buscar')) ?></button>
<?php if (P::pode('exportar_excel') || P::admin()): ?><a class="btn btn-sm btn-outline-success" href="<?= P::e($q(['csv' => 1])) ?>"><i class="bi bi-filetype-csv me-1"></i><?= P::e(P::L('Baixar CSV do período', 'Download period CSV', 'Descargar CSV del período')) ?></a><?php endif; ?></form>
<?php if ($erroView !== ''): ?><div class="alert alert-warning py-2"><?= P::e($erroView) ?></div><?php endif; ?>
<div class="small text-muted mb-1"><?= P::e(sprintf(P::L('%d registro(s)%s', '%d record(s)%s', '%d registro(s)%s'), count($linhas), count($linhas) >= 1000 ? P::L(' (os 1.000 mais recentes; o CSV traz todos)', ' (latest 1,000; the CSV has all)', ' (los 1.000 más recientes; el CSV trae todos)') : '')) ?></div>
<div class="table-responsive border rounded bg-white" style="max-height:55vh;overflow:auto"><table class="table table-sm mb-0 lg-tab"><thead class="table-light"><tr><?php foreach ($mostrar as $r): ?><th><?= P::e($r) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($linhas as $l): ?><tr><?php foreach (array_keys($mostrar) as $c): $v = $val($l, $c); ?><td class="small" title="<?= P::e($v) ?>"><?= P::e($c === 'DATA_EVENTO' && ($t = strtotime($v)) ? date(P::$idioma === 'en' ? 'm/d/Y H:i:s' : 'd/m/Y H:i:s', $t) : $v) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div>
<?php if (P::pode('editar')): ?>
<form method="post" class="card mt-3"><div class="card-body py-2"><input type="hidden" name="csrf" value="<?= P::e($csrf) ?>">
<div class="fw-semibold small mb-1"><?= P::e(P::L('Retenção e envio automático por e-mail', 'Retention and automatic e-mail', 'Retención y envío automático por correo')) ?></div>
<div class="d-flex flex-wrap gap-3 align-items-end"><label class="small"><?= P::e(P::L('Retenção (dias)', 'Retention (days)', 'Retención (días)')) ?><input type="number" min="30" max="3650" class="form-control form-control-sm" name="LOG_RETENCAO_DIAS" value="<?= $retencao ?>"<?= P::admin() ? '' : ' readonly' ?> data-sem-memoria></label>
<label class="small"><?= P::e(P::L('Enviar o CSV para', 'Send the CSV to', 'Enviar el CSV a')) ?><input type="email" class="form-control form-control-sm" style="width:260px" name="LOG_EMAIL_DESTINO" value="<?= P::e($cfg['LOG_EMAIL_DESTINO']) ?>" data-sem-memoria></label>
<label class="small"><?= P::e(P::L('Frequência', 'Frequency', 'Frecuencia')) ?><select class="form-select form-select-sm" name="LOG_EMAIL_FREQUENCIA" data-sem-memoria><?= P::opcoes(['NENHUM' => P::L('Não enviar', 'Do not send', 'No enviar'), 'SEMANAL' => P::L('Semanal (segunda-feira)', 'Weekly (Monday)', 'Semanal (lunes)'), 'MENSAL' => P::L('Mensal (dia 1)', 'Monthly (1st)', 'Mensual (día 1)')], $cfg['LOG_EMAIL_FREQUENCIA']) ?></select></label>
<button class="btn btn-sm btn-outline-success" type="submit"><?= P::e(P::L('Gravar', 'Save', 'Guardar')) ?></button></div>
<div class="small text-muted mt-1"><?= P::e(P::L('O e-mail leva o link do CSV do período (ações e alterações), que fica disponível aqui enquanto estiver dentro da retenção. O envio depende do serviço agendado logs-email-worker.', 'The e-mail carries the link to the period CSV, available here within the retention. Sending depends on the scheduled logs-email-worker service.', 'El correo lleva el enlace del CSV del período. El envío depende del servicio programado logs-email-worker.')) ?></div></div></form><?php endif; ?>
<?php P::rodape(); ?>
