<?php
declare(strict_types=1);

/*
 * Administração › Consumo de IA — gravações da tela (migração 340). POST JSON {acao, ...} com o cabeçalho X-CSRF-Token.
 * Só o perfil ADMINISTRADOR (regra do taxímetro: o ADM DA EMPRESA só consulta). Só procedures:
 *   limite_salvar   sp_ia_limite_salvar (banco da empresa)        extra_incluir  sp_ia_limite_extra_incluir (banco da empresa)
 *   preco_salvar    sp_ia_preco_salvar (universal)                 cambio_salvar  sp_ia_cambio_gravar (universal, fonte MANUAL)
 *   ptax_atualizar  busca o PTAX no Banco Central e grava (universal)
 *   exportar        Excel das chamadas do mês pela fila do Agendador (ExportacaoSolicitacao → alerta); ADMINISTRADOR e ADM DA EMPRESA (Exportar Excel)
 */

require_once dirname(__DIR__, 2) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__, 2) . '/classes/NaoConformidadeAdministracao.php';
require_once dirname(__DIR__, 2) . '/classes/IaTaximetro.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$responder = static function (array $r, int $http = 200): never { http_response_code($http); echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $responder(['ok' => false, 'erro' => 'Método não permitido.'], 405);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado'])) $responder(['ok' => false, 'erro' => 'Sessão expirada.'], 401);
if (!NaoConformidadeAdministracao::csrfValido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) $responder(['ok' => false, 'erro' => 'Sessão da tela expirou: recarregue a página.'], 403);
$d = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($d)) $responder(['ok' => false, 'erro' => 'Requisição inválida.'], 400);
$acao = (string)($d['acao'] ?? '');
if (!in_array($acao, ['limite_salvar', 'extra_incluir', 'preco_salvar', 'cambio_salvar', 'ptax_atualizar', 'exportar'], true)) $responder(['ok' => false, 'erro' => 'Ação inválida.'], 400);

$pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $responder(['ok' => false, 'erro' => 'Sessão inválida.'], 401);
// Excel: quem tem "Exportar Excel" no menu (ADMINISTRADOR e ADM DA EMPRESA); a planilha é gerada pelo GreenPortal-ExportWorker
if ($acao === 'exportar') {
    require_once dirname(__DIR__, 2) . '/classes/ExportacaoSolicitacao.php';
    if (empty(PermissaoTransacao::obter(IaTaximetro::MENU)['pode_exportar_excel'])) $responder(['ok' => false, 'erro' => 'Seu perfil não tem permissão para exportar.'], 403);
    $mes = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($d['mes'] ?? '')) ? (string)$d['mes'] : date('Y-m');
    $empresa = trim((string)($_SESSION['tenant']['nome'] ?? ''));
    $id = ExportacaoSolicitacao::solicitar($pdo, (int)($_SESSION['usuario']['codusuario'] ?? 0), 'administracao_consumo_ia', 'Consumo de IA (' . substr($mes, 5, 2) . '/' . substr($mes, 0, 4) . ')',
        ['filtros' => ['mes' => $mes, 'empresa' => $empresa]]);
    if ($id <= 0) $responder(['ok' => false, 'erro' => 'Não foi possível solicitar a exportação.'], 500);
    $responder(['ok' => true, 'resultado' => ['codexportacaogrid' => $id, 'mensagem' => ExportacaoSolicitacao::mensagem()]]);
}
if (strtoupper(trim((string)($_SESSION['usuario']['codigo_perfil'] ?? ''))) !== 'ADMINISTRADOR') $responder(['ok' => false, 'erro' => 'Só o perfil ADMINISTRADOR altera o limite, os preços e a cotação da IA.'], 403);
$usuario = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$nome = mb_substr(trim((string)($_SESSION['usuario']['nome'] ?? $_SESSION['usuario']['login'] ?? ('usuário ' . $usuario))), 0, 150);
$dec = static function (mixed $v, bool $vazio = false): ?string {
    $v = trim(str_replace(',', '.', (string)$v));
    if ($v === '') return $vazio ? '' : null;
    if (!preg_match('/^\d+(\.\d+)?$/', $v)) throw new DomainException('Número inválido: ' . mb_substr($v, 0, 20));
    return $v;
};
$data = static function (mixed $v): string {
    $v = trim((string)$v);
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if (!$dt || $dt->format('Y-m-d') !== $v) throw new DomainException('Data inválida.');
    return $v;
};

try {
    $r = match ($acao) {
        'limite_salvar' => NaoConformidadeAdministracao::proc($pdo, 'sp_ia_limite_salvar', [mb_substr((string)($d['chave'] ?? ''), 0, 40), (string)$dec($d['valor'] ?? '', true),
            mb_substr(trim((string)($d['motivo'] ?? '')), 0, 500), $usuario])[0] ?? [],
        'extra_incluir' => NaoConformidadeAdministracao::proc($pdo, 'sp_ia_limite_extra_incluir', [$dec($d['valor'] ?? ''), mb_substr(trim((string)($d['motivo'] ?? '')), 0, 500), $usuario])[0] ?? [],
        'preco_salvar' => NaoConformidadeAdministracao::proc(IaTaximetro::universal(), 'sp_ia_preco_salvar', [(int)($d['codigo'] ?? 0), mb_substr(trim((string)($d['modelo'] ?? '')), 0, 100),
            mb_substr(trim((string)($d['descricao'] ?? '')), 0, 200), $dec($d['entrada'] ?? ''), $dec($d['cache'] ?? ''), $dec($d['saida'] ?? ''), $data($d['vigente'] ?? ''),
            (int)!empty($d['ativo']), mb_substr(trim((string)($d['fonte'] ?? '')), 0, 200), $nome])[0] ?? [],
        'cambio_salvar' => NaoConformidadeAdministracao::proc(IaTaximetro::universal(), 'sp_ia_cambio_gravar', [$data($d['data'] ?? ''), $dec($d['cotacao'] ?? ''), null, 'MANUAL', $nome])[0] ?? [],
        'ptax_atualizar' => IaTaximetro::ptaxAtualizar(null, $nome),
    };
    try { AuditService::record($pdo, 'administracao_consumo_ia', strtoupper($acao), 'SUCESSO', 'ia_consumo', (string)($d['chave'] ?? $d['modelo'] ?? $d['data'] ?? '')); } catch (Throwable) {}
    $responder(['ok' => true, 'resultado' => $r]);
} catch (DomainException $e) {
    $responder(['ok' => false, 'erro' => $e->getMessage()], 422);
} catch (RuntimeException $e) {
    if ($e instanceof PDOException) { error_log('[ia_consumo api] ' . $e->getMessage()); $responder(['ok' => false, 'erro' => 'Não foi possível gravar (banco de dados). Confira se a migração 340 foi aplicada.'], 500); }
    $responder(['ok' => false, 'erro' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[ia_consumo api] ' . $e->getMessage());
    $responder(['ok' => false, 'erro' => 'Não foi possível gravar.'], 500);
}
