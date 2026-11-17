<?php
declare(strict_types=1);

/*
 * Não Conformidades › Administração — gravações das telas (migração 333): listas de distribuição, participantes externos
 * e reenvio de e-mail. POST JSON {acao, ...} com o cabeçalho X-CSRF-Token. Só procedures; a permissão é a do menu da tela
 * (incluir / editar / excluir em Segurança › Permissões).
 */

require_once dirname(__DIR__, 2) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__, 2) . '/classes/NaoConformidadeAdministracao.php';
require_once dirname(__DIR__, 2) . '/classes/NaoConformidadeConfig.php';

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
$inteiros = static fn(mixed $v): array => array_values(array_unique(array_filter(array_map('intval', is_array($v) ? $v : []), static fn(int $x) => $x > 0)));

// ação => [menu, permissão]
$regras = [
    'lista_salvar' => [NaoConformidadeAdministracao::MENU_LISTAS, ((int)($d['codigo'] ?? 0)) > 0 ? 'pode_editar' : 'pode_incluir'],
    'lista_membros' => [NaoConformidadeAdministracao::MENU_LISTAS, 'pode_editar'],
    'lista_excluir' => [NaoConformidadeAdministracao::MENU_LISTAS, 'pode_excluir'],
    'lista_copiar' => [NaoConformidadeAdministracao::MENU_LISTAS, 'pode_incluir'],
    'participante_salvar' => [NaoConformidadeAdministracao::MENU_PARTICIPANTES, ((int)($d['codigo'] ?? 0)) > 0 ? 'pode_editar' : 'pode_incluir'],
    'email_reenviar' => [NaoConformidadeAdministracao::MENU_EMAILS, 'pode_editar'],
    // Migração 334
    'nc_corrigir' => [NaoConformidadeAdministracao::MENU_CORRECAO, 'pode_editar'],
    'nc_etapa' => [NaoConformidadeAdministracao::MENU_CORRECAO, 'pode_editar'],
    'transferir' => [NaoConformidadeAdministracao::MENU_TRANSFERENCIA, 'pode_editar'],
    'atribuir_gestor' => [NaoConformidadeAdministracao::MENU_SANEAMENTO, 'pode_editar'],
    'lembrar' => [NaoConformidadeAdministracao::MENU_SANEAMENTO, 'pode_editar'],
    // Migração 339: Configuração do módulo (só ADMINISTRADOR tem o menu)
    'config_parametro' => [NaoConformidadeConfig::MENU, 'pode_editar'],
    'config_obrigatorios' => [NaoConformidadeConfig::MENU, 'pode_editar'],
    'lembrete_regra' => [NaoConformidadeConfig::MENU, 'pode_editar'],
    'lembretes_simular' => [NaoConformidadeConfig::MENU, 'pode_visualizar'],
];
// Campos que a Correção de ocorrência pode enviar (a procedure valida cada um)
$camposCorrecao = ['CODUSUARIO_EMISSOR', 'CODUSUARIO_GESTOR', 'CODUSUARIO_RESPONSAVEL', 'DATA_OCORRENCIA', 'codunidade', 'listadedistribuicao', 'IDENTIFICACAO',
    'DESCRICAO', 'PROVIDENCIA', 'ANALISE_ABRANGENCIA', 'CODFORNECEDOR', 'QTDENVOLVIDA', 'QTDDEVOLVIDA', 'DSCCLIENTE'];
if (!isset($regras[$acao])) $responder(['ok' => false, 'erro' => 'Ação inválida.'], 400);
[$menu, $permissao] = $regras[$acao];
$pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $responder(['ok' => false, 'erro' => 'Sessão inválida.'], 401);
if (empty(PermissaoTransacao::obter($menu)[$permissao])) $responder(['ok' => false, 'erro' => 'Seu perfil não tem permissão para esta operação.'], 403);
$usuario = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$p = static fn(string $sp, array $a): array => NaoConformidadeAdministracao::proc($pdo, $sp, $a);

try {
    $r = match ($acao) {
        'lista_salvar' => $p('sp_nc_lista_salvar', [(int)($d['codigo'] ?? 0), mb_substr(trim((string)($d['nome'] ?? '')), 0, 500), json_encode($inteiros($d['categorias'] ?? [])), $usuario])[0] ?? [],
        'lista_membros' => $p('sp_nc_lista_membros', [(int)($d['codigo'] ?? 0), json_encode($inteiros($d['incluir'] ?? [])), json_encode($inteiros($d['retirar'] ?? [])), $usuario])[0] ?? [],
        'lista_excluir' => $p('sp_nc_lista_excluir', [(int)($d['codigo'] ?? 0), $usuario])[0] ?? [],
        'lista_copiar' => $p('sp_nc_lista_copiar', [(int)($d['origem'] ?? 0), mb_substr(trim((string)($d['nome'] ?? '')), 0, 500), $usuario])[0] ?? [],
        'participante_salvar' => $p('sp_nc_participante_salvar', [(int)($d['codigo'] ?? 0), mb_substr(trim((string)($d['nome'] ?? '')), 0, 200), mb_substr(trim((string)($d['email'] ?? '')), 0, 500), !empty($d['ativo']) ? 1 : 0, $usuario])[0] ?? [],
        'email_reenviar' => $p('sp_email_reenviar', [(int)($d['codigo'] ?? 0)])[0] ?? [],
        'nc_corrigir' => $p('sp_nc_admin_corrigir', [(int)($d['codigo'] ?? 0),
            json_encode((object)array_map(static fn($v) => is_scalar($v) || $v === null ? (string)$v : '', array_intersect_key(is_array($d['dados'] ?? null) ? $d['dados'] : [], array_flip($camposCorrecao))), JSON_UNESCAPED_UNICODE),
            json_encode(array_values(array_map(static fn($a) => ['codplanoacao' => (int)($a['codplanoacao'] ?? 0), 'codusuario' => ((int)($a['codusuario'] ?? 0)) ?: null], array_filter(is_array($d['acoes'] ?? null) ? $d['acoes'] : [], 'is_array')))),
            mb_substr(trim((string)($d['motivo'] ?? '')), 0, 2000), $usuario])[0] ?? [],
        'nc_etapa' => $p('sp_nc_admin_etapa', [(int)($d['codigo'] ?? 0), in_array($d['etapa'] ?? '', ['REABRIR', 'DEVOLVER', 'ENCERRAR'], true) ? $d['etapa'] : '', mb_substr(trim((string)($d['motivo'] ?? '')), 0, 2000), $usuario])[0] ?? [],
        'transferir' => $p('sp_nc_admin_transferir', [(int)($d['origem'] ?? 0), (int)($d['destino'] ?? 0),
            json_encode(array_values(array_map(static fn($i) => ['tipo' => strtoupper((string)($i['tipo'] ?? '')), 'codigo' => (int)($i['codigo'] ?? 0)], array_filter(is_array($d['itens'] ?? null) ? $d['itens'] : [], 'is_array')))),
            mb_substr(trim((string)($d['motivo'] ?? '')), 0, 2000), $usuario])[0] ?? [],
        'atribuir_gestor' => $p('sp_nc_admin_atribuir_gestor', [json_encode($inteiros($d['itens'] ?? [])), (int)($d['gestor'] ?? 0), mb_substr(trim((string)($d['motivo'] ?? '')), 0, 2000), $usuario])[0] ?? [],
        'lembrar' => $p('sp_nc_admin_lembrar', [json_encode($inteiros($d['itens'] ?? [])), $usuario])[0] ?? [],
        'config_parametro' => $p('sp_nc_config_parametro_salvar', [(string)($d['chave'] ?? ''), mb_substr(trim((string)($d['valor'] ?? '')), 0, 500), $usuario])[0] ?? [],
        // só os campos do catálogo da tela (a procedure confere se a coluna existe)
        'config_obrigatorios' => $p('sp_nc_config_obrigatorios_salvar', [(int)($d['tipo'] ?? 0), json_encode(array_values(array_map(
            static fn($c) => ['campo' => strtoupper((string)$c['campo']), 'rotulo' => NaoConformidadeConfig::CAMPOS[strtoupper((string)$c['campo'])][0]],
            array_filter(is_array($d['campos'] ?? null) ? $d['campos'] : [], static fn($c) => is_array($c) && isset(NaoConformidadeConfig::CAMPOS[strtoupper((string)($c['campo'] ?? ''))])))), JSON_UNESCAPED_UNICODE), $usuario])[0] ?? [],
        'lembrete_regra' => $p('sp_nc_lembrete_regra_salvar', [(int)($d['codigo'] ?? 0), (string)($d['evento'] ?? ''), mb_substr(trim((string)($d['nome'] ?? '')), 0, 200), !empty($d['ativo']) ? 1 : 0,
            (int)($d['dias'] ?? 0), (int)($d['repetir'] ?? 0), (int)($d['escalar'] ?? 0), !empty($d['email']) ? 1 : 0,
            implode(',', array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($d['tipos'] ?? ''))), static fn(int $x) => $x > 0)))), $usuario])[0] ?? [],
        'lembretes_simular' => $p('sp_nc_lembretes_processar', [1]),
    };
    AuditService::record($pdo, $menu, strtoupper($acao), 'SUCESSO', 'nc_administracao', (string)($d['codigo'] ?? $d['origem'] ?? ''));
    $responder(['ok' => true, 'resultado' => $r]);
} catch (DomainException $e) {
    $responder(['ok' => false, 'erro' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[nc_administracao] ' . $acao . ': ' . $e->getMessage());
    $responder(['ok' => false, 'erro' => 'Não foi possível gravar (erro registrado no log do servidor).'], 500);
}
