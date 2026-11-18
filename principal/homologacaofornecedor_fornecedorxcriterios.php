<?php
declare(strict_types=1);

/*
 * Homologação de Fornecedores › Gestão de Fornecedores › Documentos (critérios exigidos do fornecedor).
 * Popup aberto pelo botão "Documentos" da Gestão de Fornecedores (fornecedor selecionado no grid).
 * Legado: configuracoes/fo_auditoriacriterios.php + cadastros/fo_auditoriacriterios_s.php, _copiar_colar_s.php e _historico.php.
 *
 * Leitura: vw_homologacao_fornecedor_documentos, vw_homologacao_fornecedor_documentos_historico, vw_homologacao_fornecedor_usuarios,
 *          vw_usuarios_grid (sp_crud_select via MigracaoCrud).
 * Gravação (procedures da migração 293): sp_fo_fornecedor_documento_salvar, sp_fo_fornecedor_documento_copiar,
 *          sp_fo_fornecedor_documento_reiniciar, sp_fo_fornecedor_documentos_gerar. Evidências copiadas no GED (GedStorageService).
 * Permissão: menu HOMOLOGACAO_FORNECEDOR_FORNECEDOR — visualizar = ver; editar = gravar, copiar/colar e gerar; excluir = reiniciar.
 * Fornecedor externo (migração 296, HomologacaoFornecedorAcesso): só fornecedores ativos ligados ao usuário. O perfil
 * FORNECEDOR grava no escopo FORNECEDOR (validade, protocolo, dias de reação, observação do fornecedor; evidências no GED)
 * e não reinicia nem gera documentos; FORNECEDOR_GESTOR grava como a empresa.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/AuditService.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/GedStorageService.php';
require_once dirname(__DIR__) . '/classes/HomologacaoFornecedorAcesso.php';

const DOC_MENU = 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR';
const DOC_TRANSACAO = 'homologacaofornecedor_fornecedorxcriterios';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$u = static fn(string $s): string => (string)json_decode('"' . $s . '"');
$t = static fn(string $chave, array $padrao): string => IdiomaService::t(DOC_TRANSACAO . '.' . $chave, $padrao);
$ajax = isset($_GET['acao']);
$falhar = static function (int $codigo, string $mensagem) use ($ajax): never {
    http_response_code($codigo);
    if ($ajax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'message' => $mensagem], JSON_UNESCAPED_UNICODE); }
    else { header('Content-Type: text/html; charset=utf-8'); echo htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8'); }
    exit;
};

try {
    $env = GreenPortalDatabase::env();
    $pdo = TenantContext::connect($env, $_SESSION);
    if (!UsuarioSessao::atualizar($pdo, $_SESSION)) throw new RuntimeException('sessao');
} catch (Throwable) {
    $falhar(401, IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.']));
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$permissao = PermissaoTransacao::obter(DOC_MENU);
if (empty($permissao['pode_visualizar'])) $falhar(403, IdiomaService::t('comum.erro.sem_permissao', ['pt' => 'Seu perfil não tem permissão para acessar esta transação.', 'en' => 'Your profile is not allowed to access this transaction.', 'es' => 'Su perfil no tiene permiso para acceder a esta transacción.']));
$podeEditar = !empty($permissao['pode_editar']);
$fornecedorComum = HomologacaoFornecedorAcesso::comum();
$podeReiniciar = !empty($permissao['pode_excluir']) && !$fornecedorComum;
$podeGerar = $podeEditar && !$fornecedorComum;
$podeExportar = !empty($permissao['pode_exportar_excel']);
$usuario = (int)($_SESSION['usuario']['codusuario'] ?? 0);
// Escopo FORNECEDOR (perfil FORNECEDOR): a procedure grava só os campos do fornecedor.
$escopo = HomologacaoFornecedorAcesso::escopo();
if (empty($_SESSION['fo_docs_csrf'])) $_SESSION['fo_docs_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['fo_docs_csrf'];

$filtros = json_decode((string)($_GET['filtros'] ?? '{}'), true);
$codfornecedor = filter_var($_GET['CODFORNECEDOR'] ?? (is_array($filtros) ? ($filtros['CODFORNECEDOR'] ?? null) : null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$codfornecedor) $falhar(400, $t('erro.fornecedor', ['pt' => 'Selecione um fornecedor já salvo.', 'en' => 'Select a supplier that has been saved.', 'es' => 'Seleccione un proveedor ya guardado.']));
if (!HomologacaoFornecedorAcesso::pode($pdo, 'fornecedor', (int)$codfornecedor)) $falhar(403, HomologacaoFornecedorAcesso::mensagemNegado());
$fornecedor = MigracaoCrud::primeiro($pdo, 'vw_homologacao_fornecedores_grid', ['CODFORNECEDOR' => $codfornecedor]);
if (!$fornecedor) $falhar(404, $t('erro.fornecedor_nao_encontrado', ['pt' => 'Fornecedor não encontrado.', 'en' => 'Supplier not found.', 'es' => 'Proveedor no encontrado.']));
session_write_close();

$chamar = static function (string $sql, array $p) use ($pdo): array {
    $s = $pdo->prepare($sql); $s->execute($p);
    $conjuntos = [];
    do { try { $conjuntos[] = $s->fetchAll(PDO::FETCH_ASSOC); } catch (PDOException) { } } while ($s->nextRowset());
    $s->closeCursor();
    return $conjuntos;
};
$documento = static function (int $cod) use ($pdo, $codfornecedor): array {
    $d = MigracaoCrud::primeiro($pdo, 'vw_homologacao_fornecedor_documentos', ['CODFORNECEDORXCRITERIOS' => $cod]);
    if (!$d || (int)$d['CODFORNECEDOR'] !== (int)$codfornecedor) throw new RuntimeException($t('erro.doc_nao_encontrado', ['pt' => 'Documento não encontrado neste fornecedor.', 'en' => 'Document not found for this supplier.', 'es' => 'Documento no encontrado en este proveedor.']));
    return $d;
};
$listar = static function () use ($pdo, $codfornecedor): array {
    $rows = MigracaoCrud::selecionar($pdo, 'vw_homologacao_fornecedor_documentos', ['CODFORNECEDOR' => $codfornecedor]);
    usort($rows, static fn($a, $b) => strcasecmp((string)$a['DSCTIPO'], (string)$b['DSCTIPO']) ?: strcasecmp((string)$a['DSCRITERIO'], (string)$b['DSCRITERIO']));
    return $rows;
};
$ged = new GedStorageService($pdo, $env, $_SESSION);
$copiarEvidencias = static function (int $origem, array $destinos) use ($ged): int {
    $copiados = 0;
    $arquivos = [];
    foreach ($ged->folders('fo_fornecedorxcriterios', $origem) as $pasta) {
        if (!is_dir($pasta)) continue;
        foreach (new DirectoryIterator($pasta) as $f) if ($f->isFile() && !isset($arquivos[$f->getFilename()])) $arquivos[$f->getFilename()] = $f->getPathname();
    }
    if (!$arquivos) return 0;
    foreach ($destinos as $d) {
        $pasta = $ged->folder('fo_fornecedorxcriterios', (int)$d, true);
        if (!is_dir($pasta) && !@mkdir($pasta, 0770, true) && !is_dir($pasta)) continue;
        foreach ($arquivos as $nome => $caminho) {
            $alvo = $pasta . DIRECTORY_SEPARATOR . $nome;
            if (is_file($alvo)) continue; // como no legado: não substitui
            if (@copy($caminho, $alvo)) $copiados++;
        }
    }
    return $copiados;
};

if ($ajax) {
    header('Content-Type: application/json; charset=utf-8');
    $acao = (string)$_GET['acao'];
    $post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $corpo = $post ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];
    if ($post && !hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) $falhar(419, IdiomaService::t('comum.erro.token', ['pt' => 'Token de segurança inválido. Atualize a tela.', 'en' => 'Invalid security token. Refresh the page.', 'es' => 'Token de seguridad inválido. Actualice la pantalla.']));
    $semPermissao = static fn() => $falhar(403, IdiomaService::t('comum.permissao.negada', ['pt' => 'Perfil sem permissão de acesso!', 'en' => 'Profile does not have access permission!', 'es' => '¡El perfil no tiene permiso de acceso!']));
    try {
        if ($acao === 'listar') { echo json_encode(['ok' => true, 'rows' => $listar()], JSON_UNESCAPED_UNICODE); exit; }
        if ($acao === 'historico') {
            $cod = (int)($_GET['codigo'] ?? 0); $documento($cod);
            $h = MigracaoCrud::selecionar($pdo, 'vw_homologacao_fornecedor_documentos_historico', ['CODFORNECEDORXCRITERIOS' => $cod]);
            usort($h, static fn($a, $b) => strcmp((string)$b['DATAALTERACAO'], (string)$a['DATAALTERACAO']) ?: ((int)$b['CODHISTORICO'] <=> (int)$a['CODHISTORICO']));
            echo json_encode(['ok' => true, 'rows' => $h], JSON_UNESCAPED_UNICODE); exit;
        }
        if (!$post) $falhar(405, 'POST');
        if ($acao === 'salvar') {
            if (!$podeEditar) $semPermissao();
            $cod = (int)($corpo['codigo'] ?? 0); $antes = $documento($cod);
            $permitidos = ['ENVIADOODOCUMENTO', 'CODUSUARIO', 'CODUSUARIO_FORNECEDOR', 'DATADEVALIDADE', 'EMPROTOCOLO', 'DATADEREACAO', 'SITUACAO', 'INCLUIDOCONTRATO', 'OBSERVACAO', 'OBSERVACAO_DO_FORNECEDOR'];
            $dados = array_intersect_key((array)($corpo['dados'] ?? []), array_flip($permitidos));
            $dados = array_map(static fn($v) => is_scalar($v) || $v === null ? (string)$v : '', $dados);
            $r = $chamar('CALL sp_fo_fornecedor_documento_salvar(?, ?, ?, ?)', [$cod, json_encode($dados, JSON_UNESCAPED_UNICODE), $usuario, $escopo]);
            AuditService::record($pdo, DOC_TRANSACAO, 'SALVAR', 'SUCESSO', 'fo_fornecedorxcriterios', (string)$cod, ['antes' => array_intersect_key($antes, array_flip($permitidos)), 'dados' => $dados]);
            echo json_encode(['ok' => true, 'row' => end($r)[0] ?? $documento($cod)], JSON_UNESCAPED_UNICODE); exit;
        }
        if ($acao === 'copiar') {
            if (!$podeEditar) $semPermissao();
            $origem = (int)($corpo['origem'] ?? 0); $documento($origem);
            $destinos = array_values(array_unique(array_filter(array_map('intval', (array)($corpo['destinos'] ?? [])), static fn($n) => $n > 0 && $n !== $origem)));
            if (!$destinos) $falhar(422, $t('erro.sem_destino', ['pt' => 'Marque os documentos de destino.', 'en' => 'Check the target documents.', 'es' => 'Marque los documentos de destino.']));
            $r = $chamar('CALL sp_fo_fornecedor_documento_copiar(?, ?, ?, ?)', [$origem, json_encode($destinos), $usuario, $escopo]);
            // A procedure devolve os códigos alterados num conjunto de resultados; o último conjunto do CALL vem vazio
            // (por isso end($r) dava sempre 0 na mensagem "colado em N documentos").
            $alterados = [];
            foreach ($r as $conjunto) if ($conjunto && array_key_exists('codigo', $conjunto[0])) $alterados = array_map(static fn($x) => (int)$x['codigo'], $conjunto);
            $arquivos = !empty($corpo['evidencias']) ? $copiarEvidencias($origem, $alterados) : 0;
            AuditService::record($pdo, DOC_TRANSACAO, 'SALVAR', 'SUCESSO', 'fo_fornecedorxcriterios.copiar', (string)$origem, ['destinos' => $alterados, 'arquivos' => $arquivos]);
            echo json_encode(['ok' => true, 'alterados' => count($alterados), 'arquivos' => $arquivos, 'rows' => $listar()], JSON_UNESCAPED_UNICODE); exit;
        }
        if ($acao === 'reiniciar') {
            if (!$podeReiniciar) $semPermissao();
            $cod = (int)($corpo['codigo'] ?? 0); $documento($cod);
            $chamar('CALL sp_fo_fornecedor_documento_reiniciar(?, ?)', [$cod, $usuario]);
            AuditService::record($pdo, DOC_TRANSACAO, 'EXCLUIR', 'SUCESSO', 'fo_fornecedorxcriterios.reiniciar', (string)$cod, []);
            echo json_encode(['ok' => true, 'row' => $documento($cod)], JSON_UNESCAPED_UNICODE); exit;
        }
        if ($acao === 'gerar') {
            if (!$podeGerar) $semPermissao();
            $r = $chamar('CALL sp_fo_fornecedor_documentos_gerar(?)', [$codfornecedor]);
            echo json_encode(['ok' => true, 'gerados' => (int)($r[0][0]['gerados'] ?? 0), 'rows' => $listar()], JSON_UNESCAPED_UNICODE); exit;
        }
        if ($acao === 'exportar') {
            // Excel: só o pedido. A planilha é gerada pelo GreenPortal-ExportWorker (Agendador do Windows,
            // classes/HomologacaoDocumentosExportacao.php) e chega nos alertas com o link.
            if (!$post || !$podeExportar) $semPermissao();
            $limpar = static function ($m): array { $o = []; foreach ((array)$m as $k => $v) if (is_scalar($v) && strlen((string)$k) <= 60) $o[(string)$k] = mb_substr((string)$v, 0, 200); return $o; };
            $mapas = [];
            foreach (['colunas', 'situacoes', 'simNao', 'cores', 'textos'] as $n) $mapas[$n] = $limpar($corpo['mapas'][$n] ?? []);
            $codigos = array_slice(array_values(array_unique(array_filter(array_map('intval', (array)($corpo['codigos'] ?? [])), static fn($n) => $n > 0))), 0, 5000);
            $colunasExp = array_values(array_filter((array)($corpo['colunas'] ?? []), static fn($c) => is_string($c) && preg_match('/^[A-Z_]{1,40}$/', $c) && $c !== 'ARQUIVOS'));
            require_once dirname(__DIR__) . '/classes/ExportacaoSolicitacao.php';
            $pedido = ExportacaoSolicitacao::solicitar($pdo, $usuario, DOC_TRANSACAO, $t('exportacao.nome', ['pt' => 'Documentos do fornecedor', 'en' => 'Supplier documents', 'es' => 'Documentos del proveedor']) . ' ' . trim((string)($fornecedor['RAZAOSOCIAL'] ?? '')), [
                'filtros' => ['CODFORNECEDOR' => (int)$codfornecedor, 'codigos' => $codigos, 'idioma' => in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$_SESSION['idioma'] : 'pt'],
                'busca' => '', 'colunas' => ['colunas' => $colunasExp, 'mapas' => $mapas]]);
            AuditService::record($pdo, DOC_TRANSACAO, 'EXPORTAR', $pedido > 0 ? 'SUCESSO' : 'ERRO', 'exportacoes_grid', (string)$codfornecedor, ['pedido' => $pedido, 'documentos' => count($codigos)]);
            if ($pedido <= 0) throw new RuntimeException(IdiomaService::t('comum.erro.exportacao', ['pt' => 'Não foi possível solicitar a exportação.', 'en' => 'The export could not be requested.', 'es' => 'No fue posible solicitar la exportación.']));
            echo json_encode(['ok' => true, 'message' => ExportacaoSolicitacao::mensagem()], JSON_UNESCAPED_UNICODE); exit;
        }
        $falhar(400, $t('erro.acao', ['pt' => 'Ação inválida.', 'en' => 'Invalid action.', 'es' => 'Acción inválida.']));
    } catch (Throwable $e) {
        AuditService::record($pdo, DOC_TRANSACAO, 'SALVAR', 'ERRO', 'fo_fornecedorxcriterios.' . $acao, (string)$codfornecedor, ['mensagem' => mb_substr($e->getMessage(), 0, 300)]);
        $msg = $e instanceof PDOException && preg_match('/1644\s*(.+)$/s', $e->getMessage(), $m) ? trim($m[1]) : ($e instanceof RuntimeException ? $e->getMessage() : IdiomaService::erro($e, 'salvar'));
        $falhar(422, $msg);
    }
}

AuditService::record($pdo, DOC_TRANSACAO, 'ACESSAR', 'SUCESSO', 'transacao', (string)$codfornecedor);
try { $linhas = $listar(); } catch (Throwable $e) { $falhar(500, IdiomaService::erro($e, 'consultar')); }
if ($fornecedorComum) {
    // O fornecedor comum não escolhe o responsável da empresa: só os nomes já gravados nos documentos (sem a lista de usuários).
    $usuariosEmpresa = [];
    foreach ($linhas as $l) if ((int)($l['CODUSUARIO'] ?? 0) > 0) $usuariosEmpresa[(int)$l['CODUSUARIO']] = ['codusuario' => (int)$l['CODUSUARIO'], 'nome' => (string)($l['RESPONSAVEL'] ?? '')];
    $usuariosEmpresa = array_values($usuariosEmpresa);
} else {
    $usuariosEmpresa = array_values(array_filter(MigracaoCrud::selecionar($pdo, 'vw_usuarios_grid', ['ativo' => 1]), static fn($x) => trim((string)$x['nome']) !== ''));
}
usort($usuariosEmpresa, static fn($a, $b) => strcasecmp((string)$a['nome'], (string)$b['nome']));
$usuariosFornecedor = MigracaoCrud::selecionar($pdo, 'vw_homologacao_fornecedor_usuarios', ['CODFORNECEDOR' => $codfornecedor]);
usort($usuariosFornecedor, static fn($a, $b) => strcasecmp((string)$a['NOME'], (string)$b['NOME']));

$idioma = in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$_SESSION['idioma'] : 'pt';
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$L = static fn(array $p): string => $p[$idioma] ?? $p['pt'];
$situacoes = [
    'Aprovado' => ['pt' => 'Aprovado', 'en' => 'Approved', 'es' => 'Aprobado'],
    'Aprovado sem validade' => ['pt' => 'Aprovado sem validade', 'en' => 'Approved without expiry', 'es' => 'Aprobado sin vencimiento'],
    'Ciente da regra' => ['pt' => 'Ciente da regra', 'en' => 'Aware of the rule', 'es' => 'Consciente de la regla'],
    'Reprovado' => ['pt' => 'Reprovado', 'en' => 'Rejected', 'es' => 'Reprobado'],
    'Pendente com a empresa' => ['pt' => 'Pendente com a empresa', 'en' => 'Pending with the company', 'es' => 'Pendiente con la empresa'],
    'Pendente com o fornecedor' => ['pt' => 'Pendente com o fornecedor', 'en' => 'Pending with the supplier', 'es' => 'Pendiente con el proveedor'],
    // Migração 300 (como no legado): ao marcar "Em protocolo" = Sim/Renovar o documento fica aguardando; na data de cobrança
    // (30 dias) o serviço scripts/homologacao-protocolo-worker.php (Agendador do Windows) volta para "Pendente com o fornecedor".
    'Aguardando vencimento do protocolo' => ['pt' => 'Aguardando vencimento do protocolo', 'en' => 'Waiting for the filing (protocol) deadline', 'es' => 'Esperando vencimiento del protocolo'],
    $u('Não enviar ao fornecedor') => ['pt' => $u('Não enviar ao fornecedor'), 'en' => 'Do not send to the supplier', 'es' => 'No enviar al proveedor'],
    'NA' => ['pt' => $u('Não se aplica'), 'en' => 'Not applicable', 'es' => 'No aplica'],
];
$simNao = ['Sim' => ['pt' => 'Sim', 'en' => 'Yes', 'es' => $u('Sí')], $u('Não') => ['pt' => $u('Não'), 'en' => 'No', 'es' => 'No']];
$protocolo = ['S' => ['pt' => 'Sim', 'en' => 'Yes', 'es' => $u('Sí')], 'N' => ['pt' => $u('Não'), 'en' => 'No', 'es' => 'No'], 'R' => ['pt' => 'Renovar (nova cobrança em 30 dias)', 'en' => 'Renew (new follow-up in 30 days)', 'es' => 'Renovar (nuevo cobro en 30 días)']];
$cores = [
    'VERMELHO' => ['pt' => 'Reprovado, vencido ou pendente com o fornecedor', 'en' => 'Rejected, expired or pending with the supplier', 'es' => 'Reprobado, vencido o pendiente con el proveedor'],
    'AMARELO' => ['pt' => 'Pendente com a empresa, aguardando vencimento do protocolo ou vence nos dias de reação', 'en' => 'Pending with the company, waiting for the protocol deadline or expiring within the reaction days', 'es' => 'Pendiente con la empresa, esperando vencimiento del protocolo o vence en los días de reacción'],
    'VERDE' => ['pt' => 'Aprovado', 'en' => 'Approved', 'es' => 'Aprobado'],
    'NEUTRO' => ['pt' => $u('Não se aplica / não enviar'), 'en' => 'Not applicable / do not send', 'es' => 'No aplica / no enviar'],
    'BRANCO' => ['pt' => $u('Sem situação'), 'en' => 'No status', 'es' => $u('Sin situación')],
];
$tx = static fn(string $k, array $p) => $t($k, $p);
$textos = [
    'editar' => $tx('botao.editar', ['pt' => 'Editar', 'en' => 'Edit', 'es' => 'Editar']),
    'copiar' => $tx('botao.copiar', ['pt' => 'Copiar dados do documento selecionado', 'en' => 'Copy data from the selected document', 'es' => 'Copiar datos del documento seleccionado']),
    'colar' => $tx('botao.colar', ['pt' => 'Colar nos documentos marcados', 'en' => 'Paste into the checked documents', 'es' => 'Pegar en los documentos marcados']),
    'historico' => $tx('botao.historico', ['pt' => 'Histórico de alterações', 'en' => 'Change history', 'es' => 'Historial de cambios']),
    'reiniciar' => $tx('botao.reiniciar', ['pt' => 'Reiniciar documento (limpa os dados)', 'en' => 'Reset document (clears the data)', 'es' => 'Reiniciar documento (limpia los datos)']),
    'gerar' => $tx('botao.gerar', ['pt' => 'Gerar documentos que faltam', 'en' => 'Create missing documents', 'es' => 'Generar documentos faltantes']),
    'recarregar' => $tx('botao.recarregar', ['pt' => 'Recarregar', 'en' => 'Reload', 'es' => 'Recargar']),
    'exportar' => $tx('botao.exportar', ['pt' => 'Exportar (planilha)', 'en' => 'Export (spreadsheet)', 'es' => 'Exportar (planilla)']),
    'ajuda' => $tx('botao.ajuda', ['pt' => 'Ajuda', 'en' => 'Help', 'es' => 'Ayuda']),
    'salvar' => $tx('botao.salvar', ['pt' => 'Salvar', 'en' => 'Save', 'es' => 'Guardar']),
    'fechar' => $tx('botao.fechar', ['pt' => 'Fechar', 'en' => 'Close', 'es' => 'Cerrar']),
    'gravando' => $tx('msg.gravando', ['pt' => 'Gravando...', 'en' => 'Saving...', 'es' => 'Guardando...']),
    'gravado' => $tx('msg.gravado', ['pt' => 'Documento gravado.', 'en' => 'Document saved.', 'es' => 'Documento guardado.']),
    'copiado' => $tx('msg.copiado', ['pt' => 'Copiado de: {doc}. Agora marque os destinos e clique em Colar.', 'en' => 'Copied from: {doc}. Now check the targets and click Paste.', 'es' => 'Copiado de: {doc}. Ahora marque los destinos y haga clic en Pegar.']),
    'colado' => $tx('msg.colado', ['pt' => 'Colado em {n} documento(s); {a} arquivo(s) de evidência copiados.', 'en' => 'Pasted into {n} document(s); {a} evidence file(s) copied.', 'es' => 'Pegado en {n} documento(s); {a} archivo(s) de evidencia copiados.']),
    'sem_copia' => $tx('msg.sem_copia', ['pt' => 'Selecione um documento e clique em Copiar antes.', 'en' => 'Select a document and click Copy first.', 'es' => 'Seleccione un documento y haga clic en Copiar antes.']),
    'sem_selecao' => $tx('msg.sem_selecao', ['pt' => 'Selecione um documento (clique na linha).', 'en' => 'Select a document (click the row).', 'es' => 'Seleccione un documento (haga clic en la fila).']),
    'sem_marcados' => $tx('msg.sem_marcados', ['pt' => 'Marque os documentos de destino.', 'en' => 'Check the target documents.', 'es' => 'Marque los documentos de destino.']),
    'confirmar_reiniciar' => $tx('msg.confirmar_reiniciar', ['pt' => 'Limpar os dados de "{doc}"? Clique de novo para confirmar.', 'en' => 'Clear the data of "{doc}"? Click again to confirm.', 'es' => '¿Limpiar los datos de "{doc}"? Haga clic de nuevo para confirmar.']),
    'confirmar_colar' => $tx('msg.confirmar_colar', ['pt' => 'Colar em {n} documento(s)? Só os campos vazios do destino serão preenchidos.', 'en' => 'Paste into {n} document(s)? Only the empty fields of the target are filled.', 'es' => '¿Pegar en {n} documento(s)? Solo se completan los campos vacíos del destino.']),
    'reiniciado' => $tx('msg.reiniciado', ['pt' => 'Documento reiniciado.', 'en' => 'Document reset.', 'es' => 'Documento reiniciado.']),
    'gerados' => $tx('msg.gerados', ['pt' => '{n} documento(s) criado(s).', 'en' => '{n} document(s) created.', 'es' => '{n} documento(s) creado(s).']),
    'erro' => $tx('msg.erro', ['pt' => 'Não foi possível concluir.', 'en' => 'Could not complete.', 'es' => 'No fue posible concluir.']),
    'vazio' => $tx('msg.vazio', ['pt' => 'Nenhum documento. Inclua os tipos do fornecedor e os critérios dos tipos.', 'en' => 'No documents. Add the supplier types and the type criteria.', 'es' => 'Ningún documento. Incluya los tipos del proveedor y los criterios de los tipos.']),
    'hist_vazio' => $tx('msg.hist_vazio', ['pt' => 'Sem alterações registradas.', 'en' => 'No recorded changes.', 'es' => 'Sin cambios registrados.']),
    'reprovar_obs' => $tx('msg.reprovar_obs', ['pt' => 'Informe na observação da empresa o motivo da reprovação do documento.', 'en' => 'Enter the reason for the rejection in the company note.', 'es' => 'Informe en la observación de la empresa el motivo del rechazo.']),
    'validade_vazia' => $tx('msg.validade_vazia', ['pt' => 'Atenção: documento aprovado sem data de validade. Use "Aprovado sem validade" se for o caso.', 'en' => 'Warning: approved document without expiry date. Use "Approved without expiry" if that is the case.', 'es' => 'Atención: documento aprobado sin fecha de vencimiento. Use "Aprobado sin vencimiento" si corresponde.']),
    'dias' => $tx('lista.dias', ['pt' => '{n} dias', 'en' => '{n} days', 'es' => '{n} días']),
    'vencido' => $tx('lista.vencido', ['pt' => 'vencido há {n} dias', 'en' => 'expired {n} days ago', 'es' => 'vencido hace {n} días']),
    'consulta' => $tx('msg.consulta', ['pt' => 'Consulta: seu perfil não pode alterar os documentos.', 'en' => 'Read-only: your profile cannot change the documents.', 'es' => 'Consulta: su perfil no puede modificar los documentos.']),
    'contagem' => $tx('lista.contagem', ['pt' => '{exibidos} de {total} documentos', 'en' => '{exibidos} of {total} documents', 'es' => '{exibidos} de {total} documentos']),
    'filtrar' => $tx('lista.filtrar', ['pt' => 'Filtrar...', 'en' => 'Filter...', 'es' => 'Filtrar...']),
    'todas_cores' => $tx('lista.todas', ['pt' => 'Todos', 'en' => 'All', 'es' => 'Todos']),
    'sem_resp_forn' => $tx('lista.sem_resp_forn', ['pt' => 'Sem responsável do fornecedor', 'en' => 'No supplier owner', 'es' => 'Sin responsable del proveedor']),
    'evidencias' => $tx('col.evidencias', ['pt' => 'Evidências', 'en' => 'Evidence', 'es' => 'Evidencias']),
    // c486: padrão de grid (textos do grupo COMUM)
    'buscar_grid' => IdiomaService::t('comum.busca.grid', ['pt' => 'Buscar no grid', 'en' => 'Search the grid', 'es' => 'Buscar en la cuadrícula']),
    'buscar' => IdiomaService::t('comum.busca.coluna', ['pt' => 'Buscar', 'en' => 'Search', 'es' => 'Buscar']),
    'todos' => IdiomaService::t('comum.filtro.todos', ['pt' => 'Todos', 'en' => 'All', 'es' => 'Todos']),
    'paginacao' => IdiomaService::t('comum.paginacao.rotulo', ['pt' => 'Paginação', 'en' => 'Pagination', 'es' => 'Paginación']),
    'anterior' => IdiomaService::t('comum.paginacao.anterior', ['pt' => 'Anterior', 'en' => 'Previous', 'es' => 'Anterior']),
    'proxima' => IdiomaService::t('comum.paginacao.proxima', ['pt' => 'Próxima', 'en' => 'Next', 'es' => 'Siguiente']),
    'pagina' => IdiomaService::t('comum.paginacao.pagina', ['pt' => 'Página', 'en' => 'Page', 'es' => 'Página']),
    'colunas_titulo' => IdiomaService::t('comum.colunas.titulo', ['pt' => 'Colunas do grid', 'en' => 'Grid columns', 'es' => 'Columnas de la cuadrícula']),
    'aplicar' => IdiomaService::t('comum.botao.aplicar', ['pt' => 'Aplicar', 'en' => 'Apply', 'es' => 'Aplicar']),
    'sem_filtro' => IdiomaService::t('comum.msg.nenhum_filtro', ['pt' => 'Nenhum registro encontrado com esses filtros.', 'en' => 'No records match these filters.', 'es' => 'Ningún registro con estos filtros.']),
    'marcar_todos' => $tx('lista.marcar_todos', ['pt' => 'Marcar todos (destinos do Colar)', 'en' => 'Check all (Paste targets)', 'es' => 'Marcar todos (destinos de Pegar)']),
    'copiar_evidencias' => $tx('form.copiar_evidencias', ['pt' => 'Copiar também as evidências (arquivos)', 'en' => 'Also copy the evidence files', 'es' => 'Copiar también las evidencias (archivos)']),
];
$colunas = [
    'DOCUMENTO' => ['pt' => 'Tipo / documento', 'en' => 'Type / document', 'es' => 'Tipo / documento'],
    'NIVEL_DE_EXIGENCIA' => ['pt' => 'Nível', 'en' => 'Level', 'es' => 'Nivel'],
    'ENVIADOODOCUMENTO' => ['pt' => 'Enviado?', 'en' => 'Sent?', 'es' => '¿Enviado?'],
    'RESPONSAVEL' => ['pt' => 'Responsável (empresa)', 'en' => 'Owner (company)', 'es' => 'Responsable (empresa)'],
    'RESPONSAVEL_FORNECEDOR' => ['pt' => 'Responsável (fornecedor)', 'en' => 'Owner (supplier)', 'es' => 'Responsable (proveedor)'],
    'ARQUIVOS' => ['pt' => 'Evidências', 'en' => 'Evidence', 'es' => 'Evidencias'],
    'DATADEVALIDADE' => ['pt' => 'Validade', 'en' => 'Expiry', 'es' => 'Vencimiento'],
    'QTDDIAS' => ['pt' => 'Dias', 'en' => 'Days', 'es' => 'Días'],
    'EMPROTOCOLO' => ['pt' => 'Em protocolo', 'en' => 'In protocol', 'es' => 'En protocolo'],
    'DATADEREACAO' => ['pt' => 'Dias de reação', 'en' => 'Reaction days', 'es' => 'Días de reacción'],
    'SITUACAO' => ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación'],
    'INCLUIDOCONTRATO' => ['pt' => 'No contrato?', 'en' => 'In contract?', 'es' => '¿En contrato?'],
    'OBSERVACAO' => ['pt' => 'Observação da empresa', 'en' => 'Company note', 'es' => 'Observación de la empresa'],
    'OBSERVACAO_DO_FORNECEDOR' => ['pt' => 'Observação do fornecedor', 'en' => 'Supplier note', 'es' => 'Observación del proveedor'],
];
$traduz = static fn(array $m): array => array_map($L, $m);
$cfg = [
    'url' => basename(__FILE__) . '?CODFORNECEDOR=' . (int)$codfornecedor, 'csrf' => $csrf, 'idioma' => $idioma,
    'podeEditar' => $podeEditar, 'podeReiniciar' => $podeReiniciar, 'podeGerar' => $podeGerar, 'podeExportar' => $podeExportar, 'escopo' => $escopo,
    // Campos que o escopo FORNECEDOR edita (o resto fica só para leitura no formulário).
    'camposFornecedor' => ['DATADEVALIDADE', 'EMPROTOCOLO', 'DATADEREACAO', 'OBSERVACAO_DO_FORNECEDOR'],
    'rows' => $linhas, 'textos' => $textos, 'colunas' => $traduz($colunas), 'situacoes' => $traduz($situacoes), 'simNao' => $traduz($simNao),
    'protocolo' => $traduz($protocolo), 'cores' => $traduz($cores),
    'usuariosEmpresa' => array_map(static fn($x) => ['v' => (string)$x['codusuario'], 't' => (string)$x['nome']], $usuariosEmpresa),
    'usuariosFornecedor' => array_map(static fn($x) => ['v' => (string)$x['CODUSUARIO'], 't' => (string)$x['NOME']], $usuariosFornecedor),
    'fornecedor' => (string)($fornecedor['RAZAOSOCIAL'] ?? ''),
];
$rotuloForm = $traduz([
    'documento' => ['pt' => 'Documento', 'en' => 'Document', 'es' => 'Documento'],
    'ENVIADOODOCUMENTO' => ['pt' => 'Enviado o documento?', 'en' => 'Document sent?', 'es' => '¿Documento enviado?'],
    'CODUSUARIO' => ['pt' => 'Responsável da empresa', 'en' => 'Company owner', 'es' => 'Responsable de la empresa'],
    'CODUSUARIO_FORNECEDOR' => ['pt' => 'Responsável do fornecedor', 'en' => 'Supplier owner', 'es' => 'Responsable del proveedor'],
    'DATADEVALIDADE' => ['pt' => 'Data de validade', 'en' => 'Expiry date', 'es' => 'Fecha de vencimiento'],
    'EMPROTOCOLO' => ['pt' => 'Em protocolo?', 'en' => 'In protocol?', 'es' => '¿En protocolo?'],
    'DATACOBRANCA_EMPROTOCOLO' => ['pt' => 'Próxima cobrança do protocolo', 'en' => 'Next protocol follow-up', 'es' => 'Próximo cobro del protocolo'],
    'DATADEREACAO' => ['pt' => 'Dias de reação (aviso antes do vencimento)', 'en' => 'Reaction days (warning before expiry)', 'es' => 'Días de reacción (aviso antes del vencimiento)'],
    'SITUACAO' => ['pt' => 'Situação do documento', 'en' => 'Document status', 'es' => 'Situación del documento'],
    'INCLUIDOCONTRATO' => ['pt' => 'Incluído no contrato?', 'en' => 'Included in the contract?', 'es' => '¿Incluido en el contrato?'],
    'OBSERVACAO' => ['pt' => 'Observação da empresa', 'en' => 'Company note', 'es' => 'Observación de la empresa'],
    'OBSERVACAO_DO_FORNECEDOR' => ['pt' => 'Observação do fornecedor', 'en' => 'Supplier note', 'es' => 'Observación del proveedor'],
]);
$titulo = $t('titulo', ['pt' => 'Documentos do fornecedor', 'en' => 'Supplier documents', 'es' => 'Documentos del proveedor']);
$opt = static function (array $mapa, bool $vazio = true) use ($e): string {
    $h = $vazio ? '<option value=""></option>' : '';
    foreach ($mapa as $v => $r) $h .= '<option value="' . $e($v) . '">' . $e($r) . '</option>';
    return $h;
};
?><!doctype html>
<html lang="<?= $e($idioma === 'pt' ? 'pt-BR' : $idioma) ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($titulo) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2">
<link rel="stylesheet" href="../assets/vendor/bootstrap-icons/bootstrap-icons.css?v=2">
<?php
// c486: padrão de grid do portal — mesmo visual do components/grid_local.php (app, design-system, main e página padrão),
// barra greenPortalCrudButtons em grupos, busca, filtro/ordenação por coluna, paginação na barra, células em caixa e Colunas.
require_once dirname(__DIR__) . '/components/grid_local.php';
require_once dirname(__DIR__) . '/components/botoes_crud.php';
greenPortalGridLocalHead();
?>
<link rel="stylesheet" href="../assets/css/homologacao-documentos.css?v=2"></head>
<body class="docs-body">
<main class="setup-fragment menu-grid-fragment grid-local-fragment docs-fragment" data-docs>
  <header class="docs-cab">
    <div class="docs-titulo"><h5 class="mb-0"><i class="bi bi-file-earmark-check me-2"></i><?= $e($titulo) ?></h5><div class="text-muted small"><?= $e($cfg['fornecedor']) ?></div></div>
    <div class="docs-legenda" data-legenda></div>
  </header>
  <div class="crud-toolbar menu-grid-toolbar">
<?php
$botaoDocs = static fn(string $acao, string $classe, string $icone): string => '<button type="button" class="btn btn-sm ' . $classe . ' grid-crud-button" data-acao="' . $acao
    . '" title="' . $e($textos[$acao]) . '" aria-label="' . $e($textos[$acao]) . '"><i class="bi ' . $icone . '"></i></button>';
$extrasDocs = $botaoDocs('editar', 'btn-outline-primary', 'bi-pencil-square') . $botaoDocs('historico', 'btn-outline-secondary', 'bi-clock-history')
    . $botaoDocs('copiar', 'btn-outline-secondary', 'bi-clipboard') . $botaoDocs('colar', 'btn-outline-secondary', 'bi-clipboard-check');
if (!$fornecedorComum) $extrasDocs .= $botaoDocs('reiniciar', 'btn-outline-danger', 'bi-eraser') . $botaoDocs('gerar', 'btn-outline-secondary', 'bi-magic');
greenPortalCrudButtons(['transaction' => DOC_TRANSACAO, 'save' => false, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true,
    'permission' => ['pode_exportar_excel' => $podeExportar], 'extras' => $extrasDocs]);
?>
    <div class="crud-search"><i class="bi bi-search"></i><input type="search" data-busca placeholder="<?= $e($textos['buscar_grid']) ?>" aria-label="<?= $e($textos['buscar_grid']) ?>"></div>
  </div>
  <div class="docs-status small" data-status aria-live="polite"><?php if (!$podeEditar): ?><span class="badge text-bg-secondary"><?= $e($textos['consulta']) ?></span><?php endif; ?></div>
  <div class="setup-form menu-grid-form docs-grid-form">
    <div class="menu-grid-scroll">
      <table class="table table-sm menu-batch-grid docs-tabela" data-tabela>
        <thead><tr data-cab></tr></thead>
        <tbody data-corpo></tbody>
      </table>
    </div>
  </div>
  <nav class="grid-pagination" data-paginacao aria-label="<?= $e($textos['paginacao']) ?>"><button type="button" class="btn btn-sm btn-outline-secondary grid-page-prev" title="<?= $e($textos['anterior']) ?>"><i class="bi bi-chevron-left"></i></button><span class="grid-page-status"></span><button type="button" class="btn btn-sm btn-outline-secondary grid-page-next" title="<?= $e($textos['proxima']) ?>"><i class="bi bi-chevron-right"></i></button></nav>
</main>

<div class="modal fade" id="docForm" tabindex="-1" aria-hidden="true" data-popup-90>
  <div class="modal-dialog modal-xl modal-dialog-scrollable docs-modal">
    <form class="modal-content" data-form novalidate>
      <div class="modal-header py-2">
        <h6 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i><span data-form-titulo></span></h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $e($textos['fechar']) ?>"></button>
      </div>
      <div class="docs-form-toolbar">
        <button type="submit" class="btn btn-sm btn-primary" data-form-salvar><i class="bi bi-floppy me-1"></i><?= $e($textos['salvar']) ?></button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-form-evidencias><i class="bi bi-files me-1"></i><?= $e($textos['evidencias']) ?></button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg me-1"></i><?= $e($textos['fechar']) ?></button>
        <span class="small ms-2" data-form-status aria-live="polite"></span>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12"><label class="form-label"><?= $e($rotuloForm['documento']) ?></label><div class="form-control-plaintext fw-semibold" data-form-documento></div></div>
          <div class="col-md-4"><label class="form-label" for="fSITUACAO"><?= $e($rotuloForm['SITUACAO']) ?></label><select class="form-select" id="fSITUACAO" name="SITUACAO"><?= $opt($cfg['situacoes']) ?></select></div>
          <div class="col-md-4"><label class="form-label" for="fDATADEVALIDADE"><?= $e($rotuloForm['DATADEVALIDADE']) ?></label><input type="date" class="form-control" id="fDATADEVALIDADE" name="DATADEVALIDADE"></div>
          <div class="col-md-4"><label class="form-label" for="fDATADEREACAO"><?= $e($rotuloForm['DATADEREACAO']) ?></label><input type="number" min="0" max="9999" class="form-control" id="fDATADEREACAO" name="DATADEREACAO"></div>
          <div class="col-md-4"><label class="form-label" for="fENVIADOODOCUMENTO"><?= $e($rotuloForm['ENVIADOODOCUMENTO']) ?></label><select class="form-select" id="fENVIADOODOCUMENTO" name="ENVIADOODOCUMENTO"><?= $opt($cfg['simNao']) ?></select></div>
          <div class="col-md-4"><label class="form-label" for="fEMPROTOCOLO"><?= $e($rotuloForm['EMPROTOCOLO']) ?></label><select class="form-select" id="fEMPROTOCOLO" name="EMPROTOCOLO"><?= $opt($cfg['protocolo']) ?></select></div>
          <div class="col-md-4"><label class="form-label"><?= $e($rotuloForm['DATACOBRANCA_EMPROTOCOLO']) ?></label><div class="form-control-plaintext" data-form-cobranca>—</div></div>
          <div class="col-md-4"><label class="form-label" for="fCODUSUARIO"><?= $e($rotuloForm['CODUSUARIO']) ?></label><select class="form-select" id="fCODUSUARIO" name="CODUSUARIO"></select></div>
          <div class="col-md-4"><label class="form-label" for="fCODUSUARIO_FORNECEDOR"><?= $e($rotuloForm['CODUSUARIO_FORNECEDOR']) ?></label><select class="form-select" id="fCODUSUARIO_FORNECEDOR" name="CODUSUARIO_FORNECEDOR"></select></div>
          <div class="col-md-4"><label class="form-label" for="fINCLUIDOCONTRATO"><?= $e($rotuloForm['INCLUIDOCONTRATO']) ?></label><select class="form-select" id="fINCLUIDOCONTRATO" name="INCLUIDOCONTRATO"><?= $opt($cfg['simNao']) ?></select></div>
          <div class="col-md-6"><label class="form-label" for="fOBSERVACAO"><?= $e($rotuloForm['OBSERVACAO']) ?></label><textarea class="form-control" rows="3" maxlength="500" id="fOBSERVACAO" name="OBSERVACAO"></textarea></div>
          <div class="col-md-6"><label class="form-label" for="fOBSERVACAO_DO_FORNECEDOR"><?= $e($rotuloForm['OBSERVACAO_DO_FORNECEDOR']) ?></label><textarea class="form-control" rows="3" maxlength="500" id="fOBSERVACAO_DO_FORNECEDOR" name="OBSERVACAO_DO_FORNECEDOR"></textarea></div>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="docHistorico" tabindex="-1" aria-hidden="true" data-popup-90>
  <div class="modal-dialog modal-xl modal-dialog-scrollable docs-modal">
    <div class="modal-content">
      <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-clock-history me-2"></i><span data-hist-titulo></span></h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $e($textos['fechar']) ?>"></button></div>
      <div class="modal-body p-0"><div class="table-responsive"><table class="table table-sm table-striped mb-0 docs-hist"><thead data-hist-cab></thead><tbody data-hist-corpo></tbody></table></div></div>
    </div>
  </div>
</div>

<div class="modal fade" id="docColar" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-clipboard-check me-2"></i><?= $e($textos['colar']) ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $e($textos['fechar']) ?>"></button></div>
    <div class="modal-body"><p class="mb-2" data-colar-texto></p><div class="form-check"><input class="form-check-input" type="checkbox" id="colarEvidencias" checked><label class="form-check-label" for="colarEvidencias"><?= $e($textos['copiar_evidencias']) ?></label></div></div>
    <div class="modal-footer py-2"><button type="button" class="btn btn-sm btn-primary" data-colar-confirmar><i class="bi bi-clipboard-check me-1"></i><?= $e($textos['colar']) ?></button><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= $e($textos['fechar']) ?></button></div>
  </div></div>
</div>

<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script>
<script>
window.greenDocs = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="../assets/js/homologacao-documentos.js?v=5"></script>
</body>
</html>
