<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura) da Gestão de e-mails — 27/11/2026.
 * Legado: green_universal.emails (fila) e emails_enviados (enviados), política de e-mails da empresa
 * (<banco>_seguranca.politicaemails) e taxímetro. greenportal: email_fila, alertas por e-mail e SMTP.
 * Só números e agrupamentos: não mostra endereços, nomes nem corpo dos e-mails.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-emails.php [--legado=green_crown] [--banco=gcrown_dbp] > storage\logs\diag_emails.json
 */
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$leg = 'green_crown'; $novo = 'gcrown_dbp';
foreach ($argv ?? [] as $a) { if (str_starts_with($a, '--legado=')) $leg = substr($a, 9); if (str_starts_with($a, '--banco=')) $novo = substr($a, 8); }
if (!preg_match('/^\w+$/', $leg) || !preg_match('/^\w+$/', $novo)) exit("banco inválido\n");
$env = GreenPortalDatabase::env();
$p = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = static function (string $sql, array $a = []) use ($p): array { try { $s = $p->prepare($sql); $s->execute($a); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; } catch (Throwable $e) { return [['erro' => mb_substr($e->getMessage(), 0, 200)]]; } };
$v = static fn(string $sql, array $a = []) => ($r = $q($sql, $a)) && !isset($r[0]['erro']) ? array_values($r[0])[0] : ($r[0]['erro'] ?? null);
$existe = static fn(string $db, string $t) => (int)$v('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db, $t]) > 0;
$cols = static fn(string $db, string $t) => array_column($q('SELECT CONCAT(COLUMN_NAME, " ", COLUMN_TYPE) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$db, $t]), 'c');
$tam = static fn(string $db, string $t) => $q('SELECT TABLE_ROWS linhas_aprox, ROUND((DATA_LENGTH + INDEX_LENGTH) / 1048576, 1) mb, ENGINE engine, TABLE_COLLATION collation FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db, $t])[0] ?? null;
$st = static fn(string $c) => "LEFT(TRIM(REGEXP_REPLACE($c, '[[:alnum:]._%+-]+@[[:alnum:].-]+', '<e-mail>')), 70)";

$out = ['gerado_em' => date('c'), 'legado' => $leg, 'novo' => $novo];

// ------------------------------------------------ legado: fila e enviados (green_universal)
foreach (['emails', 'emails_enviados'] as $t) {
    if (!$existe('green_universal', $t)) { $out['universal'][$t] = 'não existe'; continue; }
    $i = ['colunas' => $cols('green_universal', $t), 'tamanho' => $tam('green_universal', $t),
        'total' => (int)$v("SELECT COUNT(*) FROM green_universal.`$t`"),
        'por_empresa' => $q("SELECT empresa, COUNT(*) n FROM green_universal.`$t` GROUP BY empresa ORDER BY n DESC LIMIT 15")];
    $w = "WHERE empresa = ?";
    $i['empresa'] = [
        'total' => (int)$v("SELECT COUNT(*) FROM green_universal.`$t` $w", [$leg]),
        'destinatarios_distintos' => (int)$v("SELECT COUNT(DISTINCT para) FROM green_universal.`$t` $w", [$leg]),
        'primeiro' => $v("SELECT MIN(data_envio) FROM green_universal.`$t` $w", [$leg]), 'ultimo' => $v("SELECT MAX(data_envio) FROM green_universal.`$t` $w", [$leg]),
        'enviado' => $q("SELECT enviado, COUNT(*) n FROM green_universal.`$t` $w GROUP BY 1 ORDER BY 2 DESC", [$leg]),
        'statusenvio' => $q("SELECT " . $st('statusenvio') . " status, COUNT(*) n FROM green_universal.`$t` $w GROUP BY 1 ORDER BY 2 DESC LIMIT 15", [$leg]),
        'transacao' => $q("SELECT transacao, COUNT(*) n FROM green_universal.`$t` $w GROUP BY 1 ORDER BY 2 DESC LIMIT 25", [$leg]),
        'repeticao' => $q("SELECT repeticao, COUNT(*) n FROM green_universal.`$t` $w GROUP BY 1 ORDER BY 2 DESC LIMIT 15", [$leg]),
        'assunto' => $q("SELECT LEFT(assunto, 90) assunto, COUNT(*) n FROM green_universal.`$t` $w GROUP BY 1 ORDER BY 2 DESC LIMIT 30", [$leg]),
        'por_mes' => $q("SELECT DATE_FORMAT(data_envio, '%Y-%m') mes, COUNT(*) n FROM green_universal.`$t` $w AND data_envio >= DATE_SUB(CURDATE(), INTERVAL 13 MONTH) GROUP BY 1 ORDER BY 1", [$leg]),
        'maior_qtd_por_destinatario_no_ultimo_mes' => $q("SELECT COUNT(*) n FROM green_universal.`$t` $w AND data_envio >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY para ORDER BY n DESC LIMIT 5", [$leg]),
        'repetidos_mesmo_assunto_corpo' => (int)$v("SELECT COALESCE(SUM(n - 1), 0) FROM (SELECT COUNT(*) n FROM green_universal.`$t` $w GROUP BY para, assunto, MD5(corpo) HAVING COUNT(*) > 1) x", [$leg]),
        'tamanho_medio_corpo' => $v("SELECT ROUND(AVG(LENGTH(corpo))) FROM green_universal.`$t` $w", [$leg]),
    ];
    $out['universal'][$t] = $i;
}

// ------------------------------------------------ legado: política de e-mails da empresa
foreach (["{$leg}_seguranca" => 'politicaemails', 'green_seguranca' => 'politicaemails', 'green_seguranca ' => 'politicaemailsxempresas'] as $db => $t) {
    $db = trim($db);
    if (!$existe($db, $t)) { $out['politica']["$db.$t"] = 'não existe'; continue; }
    $c = $cols($db, $t);
    $sel = array_values(array_filter(array_map(static fn($x) => strtok($x, ' '), $c), static fn($n) => !in_array(strtolower($n), ['corpoemail'], true)));
    $out['politica']["$db.$t"] = ['colunas' => $c, 'total' => (int)$v("SELECT COUNT(*) FROM `$db`.`$t`"),
        'linhas' => $t === 'politicaemails' ? $q("SELECT " . implode(', ', array_map(static fn($n) => "`$n`", $sel)) . ", LEFT(corpoemail, 120) corpo_inicio FROM `$db`.`$t` ORDER BY 1 LIMIT 200") : null];
}

// ------------------------------------------------ legado: taxímetro de e-mails
if ($existe($leg, 'taximetro')) $out['taximetro'] = $q("SELECT DATE_FORMAT(data_taximetro, '%Y-%m') mes, SUM(qtdtransacoes_emails) emails FROM `$leg`.taximetro WHERE tabela = 'emails' AND data_taximetro >= DATE_SUB(CURDATE(), INTERVAL 13 MONTH) GROUP BY 1 ORDER BY 1");

// ------------------------------------------------ greenportal (novo)
$n = [];
foreach (['email_fila', 'vw_email_fila', 'alertas', 'alertas_email_pref', 'alertas_email_resumo', 'resp_regras', 'resp_regras_avisos'] as $t) $n['existe'][$t] = $existe($novo, $t);
if ($n['existe']['email_fila']) {
    $n['email_fila'] = ['colunas' => $cols($novo, 'email_fila'), 'tamanho' => $tam($novo, 'email_fila'),
        'status' => $q("SELECT status, COUNT(*) n FROM `$novo`.email_fila GROUP BY 1"),
        'origem' => $q("SELECT origem, COUNT(*) n FROM `$novo`.email_fila GROUP BY 1 ORDER BY 2 DESC"),
        'por_mes' => $q("SELECT DATE_FORMAT(criado_em, '%Y-%m') mes, COUNT(*) n FROM `$novo`.email_fila GROUP BY 1 ORDER BY 1 DESC LIMIT 6"),
        'erros' => $q("SELECT " . $st('ultimo_erro') . " erro, COUNT(*) n FROM `$novo`.email_fila WHERE status = 'ERRO' GROUP BY 1 ORDER BY 2 DESC LIMIT 10")];
}
if ($n['existe']['alertas']) $n['alertas'] = ['total' => (int)$v("SELECT COUNT(*) FROM `$novo`.alertas"), 'ultimos_30_dias' => (int)$v("SELECT COUNT(*) FROM `$novo`.alertas WHERE criado_em >= DATE_SUB(NOW(), INTERVAL 30 DAY)")];
if ($n['existe']['alertas_email_pref']) {
    $pc = array_map(static fn($x) => strtok($x, ' '), $cols($novo, 'alertas_email_pref'));
    $col = null; foreach ($pc as $c) if (in_array(strtoupper($c), ['MODO', 'PREFERENCIA', 'ACAO', 'ENVIO', 'TIPO'], true)) { $col = $c; break; }
    $n['preferencias'] = $col ? $q("SELECT `$col` modo, COUNT(*) n FROM `$novo`.alertas_email_pref GROUP BY 1") : ['colunas' => $pc];
}
$n['procedures_email'] = array_column($q("SELECT ROUTINE_NAME r FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? AND (ROUTINE_NAME LIKE 'sp\\_email%' OR ROUTINE_NAME LIKE 'sp\\_alerta%email%')", [$novo]), 'r');
if ($existe((string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'smtp_configuracao'))
    $n['smtp'] = $q("SELECT ativo, host, porta, seguranca, remetente_nome, IF(senha_cifrada IS NULL OR senha_cifrada = '', 'sem senha', 'com senha') senha, url_portal FROM smtp_configuracao LIMIT 1");
$out['greenportal'] = $n;

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
