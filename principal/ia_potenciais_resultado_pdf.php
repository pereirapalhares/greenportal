<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado'])) { http_response_code(401); exit('Sessão expirada.'); }

$env = [];
foreach (file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
$dsn = static fn(string $database): string => 'mysql:host=' . ($env['GPT_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['GPT_DB_PORT'] ?? '3306') . ';dbname=' . $database . ';charset=utf8mb4';
$database = (string)($_SESSION['tenant']['banco_dados'] ?? ($env['GPT_DB_DATABASE'] ?? 'gmodelo_dbp'));
$pdo = new PDO($dsn($database), $env['GPT_DB_USERNAME'] ?? '', $env['GPT_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { http_response_code(401); exit('Sessão expirada.'); }
$permission = PermissaoTransacao::obter('MODULOLEGAL_POTENCIAIS');
if (!$permission['pode_visualizar'] || !$permission['pode_gerar_pdf']) { http_response_code(403); exit('Acesso não autorizado.'); }
$uid = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$id = (int)($_GET['id'] ?? $_GET['codsolicitacao'] ?? 0);
$call = static function (PDO $connection, string $sql, array $params = []): array { $statement = $connection->prepare($sql); $statement->execute($params); $rows = $statement->fetchAll(PDO::FETCH_ASSOC); $statement->closeCursor(); return $rows; };
$e = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$date = static function (mixed $value): string { if (!$value) return '—'; try { return (new DateTime((string)$value))->format('d/m/Y H:i'); } catch (Throwable) { return (string)$value; } };
$status = static fn(mixed $value): string => match (strtoupper((string)$value)) { 'CONCLUIDA' => 'Concluída', 'PROCESSANDO' => 'Processando', 'PENDENTE' => 'Pendente', 'ERRO' => 'Erro', 'APROVADO' => 'Aprovado', 'REJEITADO' => 'Rejeitado', 'SUGERIDO' => 'Sugerido', default => (string)$value };
$requestRows = $call($pdo, 'SELECT codsolicitacao,codusuario,contexto_json,status,solicitada_em,iniciada_em,concluida_em,processador,erro FROM vw_ml_potenciais_solicitacoes WHERE codsolicitacao = :id LIMIT 1', ['id' => $id]);
// Quem pediu a análise ou quem o tem na equipe (mesma regra do calendário de pendências)
require_once dirname(__DIR__) . '/classes/PainelLateralService.php';
if ($requestRows && !PainelLateralService::podeVer($pdo, $uid, (int)($requestRows[0]['codusuario'] ?? 0))) $requestRows = [];
$request = $requestRows[0] ?? null;
if (!$request) { http_response_code(404); exit('Solicitação não encontrada.'); }
$context = json_decode((string)($request['contexto_json'] ?? ''), true); $context = is_array($context) ? $context : [];
$companyRows = $call($pdo, 'SELECT dscempresa FROM vw_mm_empresa_nome WHERE codempresa = :id LIMIT 1', ['id' => (int)($context['CODEMPRESA'] ?? 0)]);
$company = (string)($companyRows[0]['dscempresa'] ?? ('Empresa ' . ($context['CODEMPRESA'] ?? '—')));
$rows = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', ['tabela' => 'vw_ml_potenciais_grid', 'filtros' => json_encode(['codsolicitacao' => $id], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
$user = ((int)($request['codusuario'] ?? 0) === $uid) ? (string)($_SESSION['usuario']['nome'] ?? $_SESSION['usuario']['login'] ?? $uid) : (string)($request['codusuario'] ?? $uid);
$esc = static fn(mixed $value): string => nl2br($e($value ?: '—'));
$html = '<!doctype html><html><head><meta charset="utf-8"><style>@page{margin:28px 32px}body{font-family:DejaVu Sans, sans-serif;color:#202b25;font-size:10px}h1{font-size:18px;color:#176b45;margin:0 0 5px}h2{font-size:13px;color:#176b45;border-bottom:1px solid #b8d7c4;padding-bottom:4px;margin:18px 0 8px}.meta{color:#68756e;margin-bottom:14px}.origin{width:100%;border-collapse:collapse;margin-bottom:8px}.origin td{border:1px solid #c8ddd1;padding:6px;vertical-align:top}.label{color:#65756d;font-size:8px;text-transform:uppercase}.value{font-size:10px;margin-top:3px}.context{white-space:pre-wrap;background:#f4f8f5;border:1px solid #d7e5dc;padding:7px}.potentials{width:100%;border-collapse:collapse;table-layout:fixed}.potentials th{background:#e3f1e8;color:#164f36;text-align:left}.potentials th,.potentials td{border:1px solid #c8ddd1;padding:5px;vertical-align:top;word-wrap:break-word}.potentials th:nth-child(1){width:10%}.potentials th:nth-child(2){width:15%}.potentials th:nth-child(3){width:25%}.potentials th:nth-child(4){width:30%}.potentials th:nth-child(5){width:10%}.potentials th:nth-child(6){width:10%}.muted{color:#68756e}</style></head><body>';
// Nome da empresa no cabeçalho de todo PDF gerado (regra do usuário, 22/10/2026)
$nomeEmpresaTenant = trim((string)($_SESSION['tenant']['nome'] ?? $_SESSION['tenant']['empresa_nome'] ?? '')) ?: $company;
$html .= '<div style="background:#176b45;color:#fff;padding:8px 12px;margin:0 0 10px;font-size:14px;font-weight:bold">' . $e($nomeEmpresaTenant) . '<span style="float:right;font-size:9px;font-weight:normal">Green Portal</span></div>';
$html .= '<h1>Potenciais gerados por IA</h1><div class="meta">Relatório da solicitação #' . $e($id) . ' — emitido em ' . $e(date('d/m/Y H:i')) . '</div><h2>Origem da análise</h2><table class="origin"><tr><td><div class="label">Requisito legal</div><div class="value">' . $esc($context['PADRAO'] ?? '—') . '</div></td><td><div class="label">Código da legislação</div><div class="value">' . $e($context['CODIGO'] ?? '—') . '</div></td></tr><tr><td><div class="label">Unidade / empresa</div><div class="value">' . $e(($context['UNIDADE'] ?? '—') . ' / ' . $company) . '</div></td><td><div class="label">Solicitada por / em</div><div class="value">' . $e($user . ' / ' . $date($request['solicitada_em'] ?? '')) . '</div></td></tr><tr><td><div class="label">Status</div><div class="value">' . $e($status($request['status'] ?? '')) . '</div></td><td><div class="label">Processador</div><div class="value">' . $e($request['processador'] ?? '—') . '</div></td></tr></table><h2>Potenciais gerados</h2>';
$html .= '<table class="potentials"><thead><tr><th>Tipo</th><th>Título</th><th>Descrição</th><th>Justificativa</th><th>Confiança</th><th>Status</th></tr></thead><tbody>';
foreach ($rows as $row) { $confidence = ($row['confianca_ia'] !== null && $row['confianca_ia'] !== '') ? number_format((float)$row['confianca_ia'] * 100, 0, ',', '.') . '%' : '—'; $html .= '<tr><td>' . $e($row['tipo_potencial'] ?? '—') . '</td><td>' . $esc($row['titulo'] ?? '—') . '</td><td>' . $esc($row['descricao'] ?? '—') . '</td><td>' . $esc($row['justificativa_ia'] ?? '—') . '</td><td>' . $e($confidence) . '</td><td>' . $e($status($row['status'] ?? '')) . '</td></tr>'; }
if (!$rows) $html .= '<tr><td colspan="6" class="muted">Nenhum potencial foi gerado para esta solicitação.</td></tr>';
$html .= '</tbody></table><p class="muted">Este relatório apresenta sugestões para avaliação humana. A confiança não representa percentual de atendimento e a aprovação não declara, por si só, o cumprimento da obrigação legal.</p></body></html>';
$options = new Dompdf\Options(); $options->set('isRemoteEnabled', false); $options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf\Dompdf($options); $dompdf->loadHtml($html, 'UTF-8'); $dompdf->setPaper('A4', 'landscape'); $dompdf->render();
$dompdf->stream('potenciais_ia_solicitacao_' . $id . '.pdf', ['Attachment' => true]);
