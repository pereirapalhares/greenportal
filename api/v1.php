<?php
declare(strict_types=1);

/*
 * API de leitura do GreenPortal (Revisão G item 10; migração 421, 26/11/2026).
 *
 * Autenticação: token criado em Segurança › Tokens da API, enviado no cabeçalho
 *   Authorization: Bearer gp_<empresa>_<código>      (ou X-Api-Key: gp_<empresa>_<código>)
 * O banco guarda só o SHA-256 do token. Token revogado ou vencido é recusado. Cada uso conta no token e vai para a auditoria.
 * Só leitura (GET). Recursos (escopo do token):
 *   GET api/v1.php?recurso=requisitos[&pagina=1&por_pagina=200&unidade=<código>]   requisitos legais e situação
 *   GET api/v1.php?recurso=licencas[...]                                         licenças
 *   GET api/v1.php?recurso=fornecedores[...]                                     fornecedores
 *   GET api/v1.php                                                               escopos do token
 * Resposta JSON: {ok, recurso, pagina, por_pagina, total_pagina, dados:[...]}. Erros: {ok:false, erro}.
 */
date_default_timezone_set('America/Sao_Paulo');
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/LegislacaoEmpresas.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$fim = static function (int $codigo, array $dados): never { http_response_code($codigo); echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') $fim(405, ['ok' => false, 'erro' => 'Somente leitura (GET).']);

// recurso => [view, colunas entregues, coluna da unidade]
const RECURSOS = [
    'requisitos' => ['vw_modulolegal_requisitos_situacao_grid', ['CODLEGISLACAO', 'CODIGO', 'CODUNIDADE', 'UNIDADE', 'PADRAO', 'ATENDIDO', 'SITUACAO_CONSOLIDADA', 'PCT_ATENDIMENTO', 'RISCO_REQUISITO', 'AVALIACAO_LEGAL'], 'CODUNIDADE'],
    // grid das licenças (descrição + situação da migração 288); sem ele, a view só de situação
    'licencas' => [['vw_licencas_grid', 'vw_licencas_situacao'], ['CODLICENCA', 'DSCLICENCA', 'CODUNIDADE', 'NOMEUNIDADE', 'DSCUNIDADE', 'DATA_VALIDADE', 'PROXIMO_VENCIMENTO',
        'SITUACAO_LICENCA', 'SITUACAO_LICENCA_DESC', 'MOTIVO_SITUACAO', 'NUMERO_PROTOCOLO', 'ARQUIVADA'], 'CODUNIDADE'],
    'fornecedores' => ['fo_fornecedores', ['CODFORNECEDOR', 'RAZAOSOCIAL', 'NOMEFANTASIA', 'CNPJ', 'CODUNIDADE', 'ATIVADO', 'BLOQUEADO'], 'CODUNIDADE'],
];

$cab = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($cab === '' && function_exists('getallheaders')) foreach ((array)getallheaders() as $k => $v) if (strcasecmp((string)$k, 'Authorization') === 0) $cab = (string)$v;
$token = preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $cab, $m) ? $m[1] : trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
if (!preg_match('/^gp_(\d+)_([a-f0-9]{40})$/', $token, $t)) $fim(401, ['ok' => false, 'erro' => 'Token ausente ou inválido (cabeçalho Authorization: Bearer <token>).']);
$empresa = (int)$t[1];
try {
    $pdo = LegislacaoEmpresas::pdo($empresa);
} catch (Throwable) { $fim(401, ['ok' => false, 'erro' => 'Token inválido.']); }
$sel = static function (string $view, array $filtros, int $n, int $o) use ($pdo): array {
    $s = $pdo->prepare('CALL sp_crud_select(:t, :f, NULL, :n, :o)');
    $s->bindValue('t', $view); $s->bindValue('f', json_encode($filtros ?: new stdClass(), JSON_UNESCAPED_UNICODE)); $s->bindValue('n', $n, PDO::PARAM_INT); $s->bindValue('o', $o, PDO::PARAM_INT);
    $s->execute(); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r;
};
$auditar = static function (string $acao, string $resultado, string $registro, array $det) use ($pdo): void {
    try { $s = $pdo->prepare('CALL sp_auditoria_registrar(NULL, :t, :a, :e, :r, :res, :d)'); $s->execute(['t' => 'API_V1', 'a' => $acao, 'e' => 'api_token', 'r' => $registro, 'res' => $resultado, 'd' => json_encode($det, JSON_UNESCAPED_UNICODE)]); $s->closeCursor(); } catch (Throwable) {}
};
try { $linha = $sel('vw_api_token', ['HASH' => ['value' => hash('sha256', $token), 'exact' => true]], 1, 0)[0] ?? null; }
catch (Throwable) { $fim(503, ['ok' => false, 'erro' => 'API não instalada nesta empresa (migração 421).']); }
if (!$linha) { $auditar('NEGADO', 'NEGADO', '', ['motivo' => 'token desconhecido', 'ip' => $_SERVER['REMOTE_ADDR'] ?? '']); $fim(401, ['ok' => false, 'erro' => 'Token inválido.']); }
$linha = array_change_key_case($linha, CASE_UPPER);
if (!empty($linha['REVOGADO_EM'])) $fim(401, ['ok' => false, 'erro' => 'Token revogado.']);
if ((string)$linha['VALIDO_ATE'] < date('Y-m-d')) $fim(401, ['ok' => false, 'erro' => 'Token vencido em ' . $linha['VALIDO_ATE'] . '.']);
$escopos = array_values(array_filter(array_map('trim', explode(',', strtolower((string)$linha['ESCOPOS'])))));
$recurso = strtolower(trim((string)($_GET['recurso'] ?? '')));
try {
    $u = $pdo->prepare('CALL sp_crud_update(:t, :k, :d)');
    $u->execute(['t' => 'api_token', 'k' => json_encode(['CODTOKEN' => (int)$linha['CODTOKEN']]), 'd' => json_encode(['ULTIMO_USO_EM' => date('Y-m-d H:i:s'), 'USOS' => (int)$linha['USOS'] + 1])]); $u->closeCursor();
} catch (Throwable) {}
if ($recurso === '') $fim(200, ['ok' => true, 'token' => (string)$linha['NOME'], 'valido_ate' => (string)$linha['VALIDO_ATE'], 'recursos' => $escopos]);
if (!isset(RECURSOS[$recurso])) $fim(404, ['ok' => false, 'erro' => 'Recurso desconhecido. Use: ' . implode(', ', array_keys(RECURSOS)) . '.']);
if (!in_array($recurso, $escopos, true)) { $auditar('NEGADO', 'NEGADO', (string)$linha['CODTOKEN'], ['recurso' => $recurso]); $fim(403, ['ok' => false, 'erro' => 'O token não tem acesso a "' . $recurso . '".']); }
[$view, $colunas, $colUnidade] = RECURSOS[$recurso];
$pagina = max(1, (int)($_GET['pagina'] ?? 1));
$porPagina = min(1000, max(1, (int)($_GET['por_pagina'] ?? 200)));
$filtros = [];
if ((int)($_GET['unidade'] ?? 0) > 0) $filtros[$colUnidade] = ['value' => (string)(int)$_GET['unidade'], 'exact' => true];
$rows = null;
foreach ((array)$view as $v) {
    try { $rows = $sel($v, $filtros, $porPagina, ($pagina - 1) * $porPagina); break; } catch (Throwable) {}
}
if ($rows === null) $fim(503, ['ok' => false, 'erro' => 'Recurso indisponível nesta empresa.']);
$dados = [];
foreach ($rows as $r) {
    $r = array_change_key_case($r, CASE_UPPER); $o = [];
    foreach ($colunas as $c) if (array_key_exists($c, $r)) $o[strtolower($c)] = $r[$c];
    $dados[] = $o;
}
$auditar('LER', 'SUCESSO', (string)$linha['CODTOKEN'], ['recurso' => $recurso, 'pagina' => $pagina, 'linhas' => count($dados), 'ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
$fim(200, ['ok' => true, 'recurso' => $recurso, 'pagina' => $pagina, 'por_pagina' => $porPagina, 'total_pagina' => count($dados), 'dados' => $dados]);
