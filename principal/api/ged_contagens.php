<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/IdiomaService.php';
require_once dirname(__DIR__, 2) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$json = static function (array $data, int $status = 200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; };
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.'])], 401);
$types = [
    'controles' => ['table' => 'documentos', 'field' => 'CODDOCUMENTO', 'folder' => 'documentos'],
    'documentos' => ['table' => 'documentos', 'field' => 'CODDOCUMENTO', 'folder' => 'documentos'],
    'licencas' => ['table' => 'li_licencas', 'field' => 'CODLICENCA', 'folder' => 'licencas'],
    'treinamentos' => ['table' => 'ml_treinamentos', 'field' => 'CODTREINAMENTO', 'folder' => 'treinamentos'],
    'infraestrutura' => ['table' => 'ml_infraestrutura', 'field' => 'CODINFRAESTRUTURA', 'folder' => 'infraestrutura'],
    'planodeacao' => ['table' => 'li_planodeacao', 'field' => 'CODPLANOACAO', 'folder' => 'li_planodeacao'],
    // Migração 410: certificados dos trabalhadores treinados
    // Migração 412: evidências do plano de manutenção da infraestrutura
    // 26/11/2026 Revisão G item 73: Evidência da medição (Plano de Medição e Monitoramento)
    'medicao' => ['table' => 'mm_monitoramento', 'field' => 'CODMONITORAMENTO', 'folder' => 'mm_monitoramento'],
    // Revisão G itens 149/150: documentos dos veículos e equipamentos do fornecedor
    'homativo' => ['table' => 'hom_ativo_doc', 'field' => 'CODATIVODOC', 'folder' => 'hom_ativo_doc'],
    'infraestruturamanutencoes' => ['table' => 'ml_infraestrutura_manutencoes', 'field' => 'CODMANUTENCAO', 'folder' => 'ml_infraestrutura_manutencoes'],
    'treinamentoparticipantes' => ['table' => 'ml_treinamentos_participantes', 'field' => 'CODPARTICIPANTE', 'folder' => 'ml_treinamentos_participantes'],
    'funcionarios' => ['table' => 'fo_funcionarios', 'field' => 'CODFUNCIONARIO', 'folder' => 'fo_funcionarios'],
    'fornecedorcriterios' => ['table' => 'fo_fornecedorxcriterios', 'field' => 'CODFORNECEDORXCRITERIOS', 'folder' => 'fo_fornecedorxcriterios'],
    'funcionariocriterios' => ['table' => 'fo_funcionarioxtiposxcriterios', 'field' => 'CODIGO', 'folder' => 'fo_funcionarioxtiposxcriterios'],
    'funcionariotreinamentos' => ['table' => 'fo_funcionarioxtiposxtreinamentos', 'field' => 'CODIGO', 'folder' => 'fo_funcionarioxtiposxtreinamentos'],
    // Migração 300: abas do fornecedor (mesmas pastas do GED do legado)
    'fornecedorcertificados' => ['table' => 'fo_certificados', 'field' => 'CODCERTIFICADO', 'folder' => 'fo_certificados'],
    'fornecedorquimicos' => ['table' => 'fo_produtoquimico', 'field' => 'CODPRODUTO', 'folder' => 'fo_produtoquimico'],
    'fornecedorequipamentos' => ['table' => 'fo_equipamentos', 'field' => 'CODEQUIPAMENTO', 'folder' => 'fo_equipamentos'],
    'fornecedorplanos' => ['table' => 'fo_planodeacao', 'field' => 'CODPLANOACAO', 'folder' => 'fo_planodeacao'],
    'fornecedorauditoria' => ['table' => 'fo_fornecedoresxtipofornecedores', 'field' => 'CODFORNECEDORXTIPOFORNECEDORES', 'folder' => 'fo_fornecedorxauditoria'],
    // Não Conformidades (pastas do legado; lidas pelas views do fluxo)
    'ncocorrencia' => ['table' => 'vw_nc_ocorrencia', 'field' => 'CODOCORRENCIA', 'folder' => 'nc_indicacao_ocorrencia'],
    'ncanalise' => ['table' => 'vw_nc_ocorrencia', 'field' => 'CODOCORRENCIA', 'folder' => 'nc_analise_ocorrencia'],
    'ncplano' => ['table' => 'vw_nc_planodeacao', 'field' => 'CODPLANOACAO', 'folder' => 'nc_planodeacao'],
];
$type = strtolower(trim((string)($_POST['tipo'] ?? $_GET['tipo'] ?? 'documentos'))); $cfg = $types[$type] ?? null;
if (!$cfg) $json(['ok' => false, 'message' => IdiomaService::t('api.arquivos.tipo_invalido', ['pt' => 'Tipo de arquivo inválido.', 'en' => 'Invalid file type.', 'es' => 'Tipo de archivo inválido.'])], 400);
$env = GreenPortalDatabase::env(); $tenant = TenantContext::connect($env, $_SESSION);
if (!UsuarioSessao::atualizar($tenant, $_SESSION)) $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.'])], 401);
require_once dirname(__DIR__, 2) . '/classes/GedPermissaoTipo.php';
if (!GedPermissaoTipo::obter($type)['pode_visualizar']) $json(['ok' => false, 'message' => IdiomaService::t('api.arquivos.sem_permissao_ver', ['pt' => 'Sem permissão para visualizar arquivos.', 'en' => 'Not allowed to view files.', 'es' => 'Sin permiso para ver archivos.'])], 403);
$ids = json_decode((string)($_POST['ids'] ?? $_GET['ids'] ?? $_POST['coddocumentos'] ?? $_GET['coddocumentos'] ?? '[]'), true); if (!is_array($ids)) $json(['ok' => false, 'message' => IdiomaService::t('api.arquivos.lista_invalida', ['pt' => 'Lista de registros inválida.', 'en' => 'Invalid record list.', 'es' => 'Lista de registros inválida.'])], 400);
$db = (string)($_SESSION['tenant']['banco_dados'] ?? '');
require_once dirname(__DIR__, 2) . '/classes/GedStorageService.php';
$storage = new GedStorageService($tenant, $env, $_SESSION);
$root = $storage->legacyRoot() . DIRECTORY_SEPARATOR . $storage->legacyDatabase() . DIRECTORY_SEPARATOR . $cfg['folder']; $call = static fn(string $procedure, array $params = []): array => GreenPortalDatabase::call($tenant, $procedure, $params); $counts = [];
foreach ($ids as $value) { $id = max(0, (int)$value); if ($id < 1) continue; if (!$call('CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => $cfg['table'], 'filtros' => json_encode([$cfg['field'] => $id])])) continue; if (!GedPermissaoTipo::registroPermitido($tenant, $type, $id)) continue; // 26/11/2026: conta pelas mesmas pastas da lista de arquivos (GED novo + pasta do legado), sem repetir nome — antes só a
    // pasta do legado era contada e a coluna Arquivos mostrava 0 para arquivos enviados pelo portal.
    $counts[(string)$id] = count($storage->list($cfg['folder'], $id, '')); }
$json(['ok' => true, 'counts' => $counts]);
