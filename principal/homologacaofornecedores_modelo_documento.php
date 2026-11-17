<?php
declare(strict_types=1);

/*
 * Homologação de Fornecedores › Critérios › Modelo do documento (migração 347; Onda 2 da apresentação Atlas: validação documental
 * conforme modelos previamente cadastrados). Aberta pelo botão "Modelo do documento" de Critérios dos Fornecedores e de Critérios dos
 * funcionários (popup), com ?escopo=FORNECEDOR|FUNCIONARIO&criterio=N.
 *
 * O modelo diz o que o documento precisa ter: itens (obrigatórios ou recomendados), orientação para a IA e regras fixas (conferir com o
 * cadastro, assinatura, validade escrita, validade mínima, emissão máxima). Arquivo de exemplo: o texto é lido pelo serviço de OCR e a
 * IA sugere os itens (a pessoa escolhe o que usar). Com a validação automática ligada, cada arquivo novo de um documento "Pendente com a
 * empresa" é conferido pelo serviço "ia" do Agendador e o parecer fica na Gestão de Arquivos › Análises de IA para a pessoa decidir.
 * Acesso: a permissão do cadastro de critérios (Visualizar para ver; Editar para gravar, enviar o exemplo e pedir a sugestão).
 * Banco: leitura por views (vw_doc_modelos, vw_doc_modelo_validacoes, critérios) e gravação por procedures (sp_doc_modelo_*).
 */
require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/HomologacaoDocumentoModelo.php';
require_once dirname(__DIR__) . '/classes/GedStorageService.php';
require_once dirname(__DIR__) . '/classes/OcrQueueService.php';
require_once dirname(__DIR__) . '/classes/DocumentAiQueueService.php';
require_once dirname(__DIR__) . '/classes/GedAnaliseIaRegistro.php';
require_once dirname(__DIR__) . '/components/pagina_padrao.php';

const DMO_TRANSACAO = 'homologacaofornecedores_modelo_documento';
const DMO_PASTA = 'fo_documento_modelos';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$escopo = strtoupper((string)($_GET['escopo'] ?? $_POST['escopo'] ?? 'FORNECEDOR'));
if (!isset(HomologacaoDocumentoModelo::ESCOPOS[$escopo])) $escopo = 'FORNECEDOR';
$cfg = HomologacaoDocumentoModelo::ESCOPOS[$escopo];
$criterio = max(0, (int)($_GET['criterio'] ?? $_POST['criterio'] ?? 0));
$pdo = TransactionSecurity::connect($cfg['menu']);
$permissao = PermissaoTransacao::obter($cfg['menu']);
$podeEditar = !empty($permissao['pode_editar']);
$usuario = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$T = static fn(string $c, array $p): string => IdiomaService::t('homologacaofornecedores_modelo_documento.' . $c, $p);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
if (empty($_SESSION['doc_modelo_csrf'])) $_SESSION['doc_modelo_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['doc_modelo_csrf'];
$ler = static function (string $view, array $filtros, int $limite = 500) use ($pdo): ?array {
    try { $s = $pdo->prepare('CALL sp_crud_select(?, ?, NULL, ?, 0)'); $s->execute([$view, json_encode($filtros ?: new stdClass()), $limite]); $r = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) {} $s->closeCursor(); return $r; }
    catch (Throwable) { return null; }
};
// Filtro simples (texto) em sp_crud_select; a linha exata é escolhida aqui (o mesmo vale nas versões antigas do CRUD, sem "exact")
$exato = static fn($v): string => (string)$v;
$modeloAtual = static function () use ($ler, $exato, $escopo, $criterio): ?array {
    $r = $ler('vw_doc_modelos', ['ESCOPO' => $exato($escopo), 'CODCRITERIO' => $exato($criterio)], 1000);
    if ($r === null) return null;
    foreach ($r as $m) if ((string)$m['ESCOPO'] === $escopo && (int)$m['CODCRITERIO'] === $criterio) return $m;
    return [];
};
$disponivel = HomologacaoDocumentoModelo::disponivel($pdo);
$extensoes = HomologacaoDocumentoModelo::EXTENSOES;

// ------------------------------------------------------------------ arquivo de exemplo (visualizar)
if (($_GET['action'] ?? '') === 'exemplo') {
    $m = $modeloAtual();
    $nome = (string)($m['ARQUIVO_EXEMPLO'] ?? '');
    $arquivo = $m && $nome !== '' ? (new GedStorageService($pdo, GreenPortalDatabase::env(), $_SESSION))->resolve(DMO_PASTA, (int)$m['CODMODELO'], $nome) : null;
    if ($arquivo === null) { http_response_code(404); exit('Arquivo não encontrado.'); }
    $ext = strtolower(pathinfo($arquivo, PATHINFO_EXTENSION));
    $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'bmp' => 'image/bmp', 'tif' => 'image/tiff', 'tiff' => 'image/tiff'][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($mime === 'application/octet-stream' ? 'attachment' : 'inline') . '; filename="' . str_replace('"', '', basename($arquivo)) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($arquivo));
    readfile($arquivo);
    exit;
}

// ------------------------------------------------------------------ ações (JSON)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $responder = static function (array $d, int $st = 200): never { http_response_code($st); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; };
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $responder(['ok' => false, 'message' => $T('msg.csrf', ['pt' => 'Token de segurança inválido. Recarregue a página.', 'en' => 'Invalid security token. Reload the page.', 'es' => 'Token de seguridad inválido. Recargue la página.'])], 419);
    if (!$disponivel) $responder(['ok' => false, 'message' => $T('msg.sem_migracao', ['pt' => 'Os modelos de documento ainda não estão disponíveis nesta empresa (migração 347).', 'en' => 'Document templates are not available for this company yet (migration 347).', 'es' => 'Los modelos de documento aún no están disponibles en esta empresa (migración 347).'])], 422);
    $acao = (string)($_POST['action'] ?? '');
    $semPermissao = ['ok' => false, 'message' => $T('msg.sem_permissao', ['pt' => 'Seu perfil não pode alterar o modelo (Segurança › Permissões › Critérios › Editar).', 'en' => 'Your profile cannot change the template (Security › Permissions › Criteria › Edit).', 'es' => 'Su perfil no puede cambiar el modelo (Seguridad › Permisos › Criterios › Editar).'])];
    if (in_array($acao, ['salvar', 'enviar_exemplo', 'sugerir'], true) && !$podeEditar) $responder($semPermissao, 403);
    try {
        if ($acao === 'salvar') {
            $itens = HomologacaoDocumentoModelo::itens(json_decode((string)($_POST['itens'] ?? '[]'), true) ?: []);
            $dias = static function (string $c): ?int { $v = trim((string)($_POST[$c] ?? '')); return $v === '' ? null : (int)$v; };
            $b = static fn(string $c): int => !empty($_POST[$c]) && $_POST[$c] !== '0' ? 1 : 0;
            $r = HomologacaoDocumentoModelo::chamar($pdo, 'CALL sp_doc_modelo_salvar(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$escopo, $criterio, $b('ativo'), $b('automatico'), $b('existentes'),
                mb_substr(trim((string)($_POST['orientacao'] ?? '')), 0, 2000), json_encode($itens, JSON_UNESCAPED_UNICODE), $b('cadastro'), $b('assinatura'), $b('validade'), $dias('emissao'), $dias('validade_min'), $usuario]);
            $responder(['ok' => true, 'codmodelo' => (int)($r[0]['CODMODELO'] ?? 0), 'message' => $T('msg.salvo', ['pt' => 'Modelo gravado. Os arquivos que chegarem a partir de agora nos documentos pendentes com a empresa serão conferidos por ele.', 'en' => 'Template saved. Files arriving from now on in documents pending with the company will be checked against it.', 'es' => 'Modelo guardado. Los archivos que lleguen desde ahora a los documentos pendientes con la empresa se revisarán con él.'])]);
        }
        $m = $modeloAtual();
        if (!$m) $responder(['ok' => false, 'message' => $T('msg.grave_antes', ['pt' => 'Grave o modelo antes.', 'en' => 'Save the template first.', 'es' => 'Guarde el modelo antes.'])], 422);
        $cod = (int)$m['CODMODELO'];
        if ($acao === 'enviar_exemplo') {
            $up = $_FILES['arquivo'] ?? null;
            if (!is_array($up) || ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $responder(['ok' => false, 'message' => $T('msg.selecione', ['pt' => 'Selecione um arquivo válido.', 'en' => 'Select a valid file.', 'es' => 'Seleccione un archivo válido.'])], 400);
            if (!in_array(strtolower(pathinfo((string)$up['name'], PATHINFO_EXTENSION)), $extensoes, true) || (int)$up['size'] > HomologacaoDocumentoModelo::MAX_BYTES)
                $responder(['ok' => false, 'message' => $T('msg.formato', ['pt' => 'Envie PDF, imagem (JPG, PNG, TIFF, BMP) ou Word (DOC, DOCX) de até 20 MB.', 'en' => 'Upload a PDF, image (JPG, PNG, TIFF, BMP) or Word (DOC, DOCX) file up to 20 MB.', 'es' => 'Envíe PDF, imagen (JPG, PNG, TIFF, BMP) o Word (DOC, DOCX) de hasta 20 MB.'])], 422);
            $env = GreenPortalDatabase::env();
            $g = (new GedStorageService($pdo, $env, $_SESSION))->store(DMO_PASTA, $cod, $up, 'DOCMODELO', $usuario);
            $job = (new OcrQueueService($pdo, $env))->enqueue(['chave_idempotencia' => 'GED_EXTRACAO:docmodelo:' . $cod . ':' . $g['name'] . ':' . $g['hash'], 'tipo' => 'DOC_MODELO', 'entidade' => 'fo_documento_modelos',
                'registro_id' => (string)$cod, 'arquivo' => $g['name'], 'caminho_arquivo' => $g['path'], 'tamanho_bytes' => filesize($g['path']), 'codusuario' => $usuario]);
            HomologacaoDocumentoModelo::chamar($pdo, 'CALL sp_doc_modelo_exemplo(?, ?, ?, ?)', [$cod, $g['name'], (int)$job['codjob'], $usuario]);
            $responder(['ok' => true, 'message' => $T('msg.exemplo', ['pt' => 'Exemplo enviado. O texto é lido pelo serviço de OCR (em poucos minutos); depois use "Sugerir itens pela IA".', 'en' => 'Sample uploaded. The OCR service reads the text in a few minutes; then use "Suggest items with AI".', 'es' => 'Ejemplo enviado. El servicio de OCR lee el texto en pocos minutos; luego use "Sugerir ítems con IA".'])]);
        }
        if ($acao === 'estado') {
            $leitura = null; $caracteres = (int)$m['TEXTO_EXEMPLO_TAMANHO']; $erro = '';
            if ((string)($m['ARQUIVO_EXEMPLO'] ?? '') !== '') {
                if ($caracteres > 0) $leitura = 'LIDO';
                elseif ((int)$m['EXEMPLO_OCR_JOB'] > 0) {
                    $o = HomologacaoDocumentoModelo::chamar($pdo, 'CALL sp_doc_modelo_ocr_texto(?)', [(int)$m['EXEMPLO_OCR_JOB']])[0] ?? null;
                    $st = (string)($o['status'] ?? 'ERRO');
                    if ($st === 'CONCLUIDA' && trim((string)$o['texto']) !== '') {
                        HomologacaoDocumentoModelo::chamar($pdo, 'CALL sp_doc_modelo_exemplo_texto(?, ?, ?)', [$cod, (int)$m['EXEMPLO_OCR_JOB'], mb_substr((string)$o['texto'], 0, 200000)]);
                        $leitura = 'LIDO'; $caracteres = mb_strlen((string)$o['texto']);
                    } elseif (in_array($st, ['PENDENTE', 'PROCESSANDO'], true)) $leitura = 'LENDO';
                    else { $leitura = 'ERRO'; $erro = (string)($o['erro'] ?? '') ?: $T('msg.sem_texto', ['pt' => 'sem texto legível', 'en' => 'no readable text', 'es' => 'sin texto legible']); }
                }
            }
            $responder(['ok' => true, 'arquivo' => (string)($m['ARQUIVO_EXEMPLO'] ?? ''), 'leitura' => $leitura, 'caracteres' => $caracteres, 'erro' => $erro, 'sugestao_job' => (int)($m['SUGESTAO_JOB'] ?? 0)]);
        }
        if ($acao === 'sugerir') {
            $job = HomologacaoDocumentoModelo::pedirSugestao($pdo, $m, $usuario);
            $responder(['ok' => true, 'job' => (int)$job['codjob'], 'status' => (string)$job['status']]);
        }
        if ($acao === 'sugestao') {
            $job = (new DocumentAiQueueService($pdo))->find(max(0, (int)($_POST['job'] ?? 0)));
            if (!$job || (string)$job['tipo'] !== HomologacaoDocumentoModelo::TIPO_SUGESTAO || (int)$job['registro_id'] !== $cod) $responder(['ok' => false, 'message' => $T('msg.job', ['pt' => 'Pedido não encontrado.', 'en' => 'Request not found.', 'es' => 'Pedido no encontrado.'])], 404);
            if ((string)$job['status'] === 'ERRO') $responder(['ok' => false, 'message' => (string)$job['erro']], 422);
            if ((string)$job['status'] !== 'CONCLUIDA') $responder(['ok' => true, 'pronto' => false, 'status' => (string)$job['status']]);
            $responder(['ok' => true, 'pronto' => true, 'sugestao' => HomologacaoDocumentoModelo::lerSugestao($job)]);
        }
    } catch (IaLimiteExcedido $x) { $responder(['ok' => false, 'message' => $x->getMessage()], 429); }
    catch (Throwable $x) {
        $msg = $x->getMessage();
        foreach (['CRITERIO_INEXISTENTE' => $T('msg.criterio', ['pt' => 'Critério não encontrado.', 'en' => 'Criterion not found.', 'es' => 'Criterio no encontrado.']),
            'DIAS_INVALIDOS' => $T('msg.dias', ['pt' => 'Dias inválidos: use de 1 a 3650.', 'en' => 'Invalid days: use 1 to 3650.', 'es' => 'Días inválidos: use de 1 a 3650.'])] as $k => $v) if (str_contains($msg, $k)) $responder(['ok' => false, 'message' => $v], 422);
        error_log('modelo de documento: ' . $msg);
        $responder(['ok' => false, 'message' => $T('msg.erro', ['pt' => 'Não foi possível concluir a operação.', 'en' => 'The operation could not be completed.', 'es' => 'No fue posible concluir la operación.']) . ($podeEditar ? ' (' . mb_substr($msg, 0, 200) . ')' : '')], 500);
    }
    $responder(['ok' => false, 'message' => $T('msg.acao', ['pt' => 'Ação inválida.', 'en' => 'Invalid action.', 'es' => 'Acción inválida.'])], 400);
}

// ------------------------------------------------------------------ página
$crit = $escopo === 'FORNECEDOR'
    ? $ler('vw_homologacao_criterios_fornecedores_grid', ['CODCRITERIOFORNECEDOR' => $exato($criterio)], 1000)
    : $ler('vw_homologacao_criterios_funcionarios_grid', ['CODCRITERIOFUNCIONARIO' => $exato($criterio)], 1000);
$nomeCriterio = '';
foreach ($crit ?? [] as $c) if ((int)($c['CODCRITERIOFORNECEDOR'] ?? $c['CODCRITERIOFUNCIONARIO'] ?? 0) === $criterio) $nomeCriterio = trim((string)$c['DSCRITERIO']);
$m = $disponivel ? $modeloAtual() : null;
$validacoes = [];
if ($m) {
    $validacoes = $ler('vw_doc_modelo_validacoes', ['CODMODELO' => $exato($m['CODMODELO'])], 1000) ?? [];
    $validacoes = array_values(array_filter($validacoes, static fn($x) => (int)$x['CODMODELO'] === (int)$m['CODMODELO']));
    usort($validacoes, static fn($a, $b) => (int)$b['CODVALIDACAO'] <=> (int)$a['CODVALIDACAO']);
    $validacoes = array_slice($validacoes, 0, 50);
}
$estado = [
    'csrf' => $csrf, 'escopo' => $escopo, 'criterio' => $criterio, 'podeEditar' => $podeEditar, 'codmodelo' => (int)($m['CODMODELO'] ?? 0),
    'itens' => HomologacaoDocumentoModelo::itens($m['ITENS'] ?? '[]'), 'extensoes' => $extensoes, 'sugestaoJob' => (int)($m['SUGESTAO_JOB'] ?? 0),
    'textos' => [
        'item_ph' => $T('js.item_ph', ['pt' => 'Ex.: assinatura e CRM do médico responsável', 'en' => 'E.g.: signature and license number of the physician', 'es' => 'Ej.: firma y matrícula del médico responsable']),
        'obrigatorio' => $T('js.obrigatorio', ['pt' => 'Obrigatório', 'en' => 'Required', 'es' => 'Obligatorio']),
        'remover' => $T('js.remover', ['pt' => 'Remover item', 'en' => 'Remove item', 'es' => 'Quitar ítem']),
        'sem_itens' => $T('js.sem_itens', ['pt' => 'Nenhum item. Use "+ Item" ou peça a sugestão da IA a partir de um arquivo de exemplo.', 'en' => 'No items. Use "+ Item" or ask the AI for a suggestion from a sample file.', 'es' => 'Ningún ítem. Use "+ Ítem" o pida la sugerencia de la IA a partir de un archivo de ejemplo.']),
        'gravando' => $T('js.gravando', ['pt' => 'Gravando…', 'en' => 'Saving…', 'es' => 'Guardando…']),
        'enviando' => $T('js.enviando', ['pt' => 'Enviando o exemplo…', 'en' => 'Uploading the sample…', 'es' => 'Enviando el ejemplo…']),
        'lendo' => $T('js.lendo', ['pt' => 'Lendo o texto do exemplo (serviço de OCR)…', 'en' => 'Reading the sample text (OCR service)…', 'es' => 'Leyendo el texto del ejemplo (servicio de OCR)…']),
        'lido' => $T('js.lido', ['pt' => 'Texto do exemplo lido ({n} caracteres).', 'en' => 'Sample text read ({n} characters).', 'es' => 'Texto del ejemplo leído ({n} caracteres).']),
        'erro_leitura' => $T('js.erro_leitura', ['pt' => 'Não foi possível ler o exemplo: {e}', 'en' => 'Could not read the sample: {e}', 'es' => 'No fue posible leer el ejemplo: {e}']),
        'sem_exemplo' => $T('js.sem_exemplo', ['pt' => 'Nenhum arquivo de exemplo.', 'en' => 'No sample file.', 'es' => 'Ningún archivo de ejemplo.']),
        'pensando' => $T('js.pensando', ['pt' => 'A IA está montando a sugestão…', 'en' => 'The AI is preparing the suggestion…', 'es' => 'La IA está preparando la sugerencia…']),
        'sugestao_titulo' => $T('js.sugestao_titulo', ['pt' => 'Sugestão da IA — marque o que quer usar', 'en' => 'AI suggestion — select what to use', 'es' => 'Sugerencia de la IA — marque lo que quiere usar']),
        'usar' => $T('js.usar', ['pt' => 'Acrescentar os marcados', 'en' => 'Add the selected', 'es' => 'Agregar los marcados']),
        'substituir' => $T('js.substituir', ['pt' => 'Substituir a lista pelos marcados', 'en' => 'Replace the list with the selected', 'es' => 'Reemplazar la lista por los marcados']),
        'sug_regras' => $T('js.sug_regras', ['pt' => 'Também sugerido: {r}', 'en' => 'Also suggested: {r}', 'es' => 'También sugerido: {r}']),
        'sug_validade' => $T('js.sug_validade', ['pt' => 'exigir validade', 'en' => 'require expiration', 'es' => 'exigir vencimiento']),
        'sug_assinatura' => $T('js.sug_assinatura', ['pt' => 'exigir assinatura', 'en' => 'require signature', 'es' => 'exigir firma']),
        'sug_emissao' => $T('js.sug_emissao', ['pt' => 'emitido há no máximo {n} dias', 'en' => 'issued at most {n} days ago', 'es' => 'emitido hace como máximo {n} días']),
        'aplicado' => $T('js.aplicado', ['pt' => 'Itens acrescentados. Confira e grave o modelo.', 'en' => 'Items added. Review and save the template.', 'es' => 'Ítems agregados. Revise y guarde el modelo.']),
        'erro' => $T('js.erro', ['pt' => 'Não foi possível concluir a operação.', 'en' => 'The operation could not be completed.', 'es' => 'No fue posible concluir la operación.']),
    ],
];
$v = static fn(string $c, $padrao = '') => $m ? ($m[$c] ?? $padrao) : $padrao;
$marcado = static fn(bool $b): string => $b ? ' checked' : '';
$dt = static function (?string $d): string { if (!$d || !preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}))?/', $d, $x)) return ''; return (IdiomaService::idioma() === 'en' ? "$x[2]/$x[3]/$x[1]" : "$x[3]/$x[2]/$x[1]") . (isset($x[4]) ? " $x[4]:$x[5]" : ''); };
$rotRes = ['CONFORME' => [$T('res.conforme', ['pt' => 'Conforme', 'en' => 'Compliant', 'es' => 'Conforme']), 'success'], 'NAO_CONFORME' => [$T('res.nao_conforme', ['pt' => 'Fora do modelo', 'en' => 'Off template', 'es' => 'Fuera del modelo']), 'danger'],
    'INCERTO' => [$T('res.incerto', ['pt' => 'Conferir', 'en' => 'Check', 'es' => 'Revisar']), 'warning']];
$rotSt = ['AGUARDANDO_TEXTO' => $T('st.lendo', ['pt' => 'Lendo o arquivo', 'en' => 'Reading the file', 'es' => 'Leyendo el archivo']), 'NA_IA' => $T('st.ia', ['pt' => 'Na IA', 'en' => 'With the AI', 'es' => 'En la IA']),
    'ERRO' => $T('st.erro', ['pt' => 'Não concluída', 'en' => 'Not completed', 'es' => 'No concluida']), 'IGNORADA' => $T('st.ignorada', ['pt' => 'Não validada', 'en' => 'Not validated', 'es' => 'No validada'])];
$titulo = $T('titulo', ['pt' => 'Modelo do documento', 'en' => 'Document template', 'es' => 'Modelo del documento']);
$dis = $podeEditar ? '' : ' disabled';
?><!doctype html>
<html lang="<?= IdiomaService::idioma() === 'pt' ? 'pt-BR' : $e(IdiomaService::idioma()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $e($titulo) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2"><link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2">
<link rel="stylesheet" href="../assets/css/app.css?v=5"><link rel="stylesheet" href="../assets/css/main.css?v=18">
<?php greenPortalEstiloPagina(); ?>
<link rel="stylesheet" href="../assets/css/documento-modelo.css?v=1">
</head>
<body data-transacao="<?= $e(DMO_TRANSACAO) ?>">
<main class="dmo">
  <div class="dmo-topo">
    <div>
      <h2><i class="bi bi-file-earmark-check"></i> <?= $e($titulo) ?></h2>
      <div class="text-muted dmo-sub"><?= $e($escopo === 'FORNECEDOR' ? $T('sub.fornecedor', ['pt' => 'Documento exigido do fornecedor', 'en' => 'Required supplier document', 'es' => 'Documento exigido del proveedor']) : $T('sub.funcionario', ['pt' => 'Documento exigido do funcionário', 'en' => 'Required employee document', 'es' => 'Documento exigido del empleado'])) ?>: <strong><?= $e($nomeCriterio !== '' ? $nomeCriterio : '#' . $criterio) ?></strong></div>
    </div>
    <div class="dmo-acoes">
      <button type="button" class="btn btn-sm btn-success" data-dmo-salvar<?= $dis ?>><i class="bi bi-floppy me-1"></i><?= $e($T('botao.gravar', ['pt' => 'Gravar', 'en' => 'Save', 'es' => 'Guardar'])) ?></button>
      <button type="button" class="btn btn-sm btn-outline-secondary" data-open-transacao-ajuda data-help-transaction="<?= $e(DMO_TRANSACAO) ?>" title="<?= $e($T('botao.ajuda', ['pt' => 'Ajuda', 'en' => 'Help', 'es' => 'Ayuda'])) ?>"><i class="bi bi-question-circle"></i></button>
    </div>
  </div>
  <div class="dmo-msg small" data-dmo-msg role="status"></div>
<?php if (!$disponivel): ?>
  <div class="alert alert-warning"><?= $e($T('msg.sem_migracao', ['pt' => 'Os modelos de documento ainda não estão disponíveis nesta empresa (migração 347).', 'en' => 'Document templates are not available for this company yet (migration 347).', 'es' => 'Los modelos de documento aún no están disponibles en esta empresa (migración 347).'])) ?></div>
<?php elseif ($nomeCriterio === ''): ?>
  <div class="alert alert-warning"><?= $e($T('msg.criterio', ['pt' => 'Critério não encontrado.', 'en' => 'Criterion not found.', 'es' => 'Criterio no encontrado.'])) ?></div>
<?php else: ?>
  <div class="dmo-grade">
    <section class="dmo-card">
      <h3><?= $e($T('c.validacao', ['pt' => 'Validação', 'en' => 'Validation', 'es' => 'Validación'])) ?></h3>
      <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="dmoAtivo" data-dmo="ativo"<?= $marcado(!$m || (int)$v('ATIVO', 1) === 1) . $dis ?>><label class="form-check-label" for="dmoAtivo"><?= $e($T('f.ativo', ['pt' => 'Modelo ativo (usado também no botão "Analisar com IA" da Gestão de Arquivos)', 'en' => 'Active template (also used by the "Analyze with AI" button)', 'es' => 'Modelo activo (usado también por el botón "Analizar con IA")'])) ?></label></div>
      <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="dmoAuto" data-dmo="automatico"<?= $marcado(!$m || (int)$v('VALIDAR_AUTOMATICO', 1) === 1) . $dis ?>><label class="form-check-label" for="dmoAuto"><?= $e($T('f.automatico', ['pt' => 'Validar automaticamente cada arquivo que chegar (documento "Pendente com a empresa")', 'en' => 'Automatically validate each file that arrives (document "Pending with the company")', 'es' => 'Validar automáticamente cada archivo que llegue (documento "Pendiente con la empresa")'])) ?></label></div>
      <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="dmoExist" data-dmo="existentes"<?= $marcado((int)$v('VALIDAR_EXISTENTES', 0) === 1) . $dis ?>><label class="form-check-label" for="dmoExist"><?= $e($T('f.existentes', ['pt' => 'Validar também os arquivos que já estão nos documentos pendentes (usa IA para cada um)', 'en' => 'Also validate the files already in pending documents (uses AI for each)', 'es' => 'Validar también los archivos que ya están en los documentos pendientes (usa IA en cada uno)'])) ?></label></div>
      <p class="dmo-nota"><?= $e($T('n.decisao', ['pt' => 'A IA só sugere: o parecer item a item fica em Gestão de Arquivos › Análises de IA e o responsável pelo documento recebe um alerta. Aprovar, reprovar ou devolver ao fornecedor é sempre decisão de uma pessoa.', 'en' => 'The AI only suggests: the item-by-item opinion stays in File management › AI analyses and the document owner gets an alert. Approving, rejecting or returning to the supplier is always a person\'s decision.', 'es' => 'La IA solo sugiere: el dictamen ítem por ítem queda en Gestión de archivos › Análisis de IA y el responsable recibe una alerta. Aprobar, rechazar o devolver al proveedor siempre es decisión de una persona.'])) ?></p>
      <label class="form-label small mb-0 mt-1" for="dmoOrientacao"><?= $e($T('f.orientacao', ['pt' => 'Orientação para a IA (opcional)', 'en' => 'Guidance for the AI (optional)', 'es' => 'Orientación para la IA (opcional)'])) ?></label>
      <textarea class="form-control form-control-sm" id="dmoOrientacao" rows="3" maxlength="2000" data-dmo="orientacao" placeholder="<?= $e($T('f.orientacao_ph', ['pt' => 'Ex.: aceitar só certidão emitida pela Receita Federal; o PGR deve citar a unidade de Sorocaba.', 'en' => 'E.g.: accept only certificates issued by the Federal Revenue.', 'es' => 'Ej.: aceptar solo certificados emitidos por la Receita Federal.'])) ?>"<?= $dis ?>><?= $e($v('ORIENTACAO')) ?></textarea>
    </section>
    <section class="dmo-card">
      <h3><?= $e($T('c.regras', ['pt' => 'Regras fixas', 'en' => 'Fixed rules', 'es' => 'Reglas fijas'])) ?></h3>
      <div class="form-check"><input class="form-check-input" type="checkbox" id="dmoCad" data-dmo="cadastro"<?= $marcado(!$m || (int)$v('CONFERIR_CADASTRO', 1) === 1) . $dis ?>><label class="form-check-label" for="dmoCad"><?= $e($escopo === 'FORNECEDOR' ? $T('f.cadastro_forn', ['pt' => 'Razão social e CNPJ iguais ao cadastro do fornecedor', 'en' => 'Company name and CNPJ equal to the supplier record', 'es' => 'Razón social y CNPJ iguales al registro del proveedor']) : $T('f.cadastro_func', ['pt' => 'Nome e CPF iguais ao cadastro do funcionário', 'en' => 'Name and CPF equal to the employee record', 'es' => 'Nombre y CPF iguales al registro del empleado'])) ?></label></div>
      <div class="form-check"><input class="form-check-input" type="checkbox" id="dmoAss" data-dmo="assinatura"<?= $marcado((int)$v('EXIGE_ASSINATURA', 0) === 1) . $dis ?>><label class="form-check-label" for="dmoAss"><?= $e($T('f.assinatura', ['pt' => 'Exigir assinatura, carimbo ou certificação digital', 'en' => 'Require signature, stamp or digital certification', 'es' => 'Exigir firma, sello o certificación digital'])) ?></label></div>
      <div class="form-check"><input class="form-check-input" type="checkbox" id="dmoVal" data-dmo="validade"<?= $marcado((int)$v('EXIGE_VALIDADE', 0) === 1) . $dis ?>><label class="form-check-label" for="dmoVal"><?= $e($T('f.validade', ['pt' => 'Exigir data de validade escrita no documento', 'en' => 'Require an expiration date written on the document', 'es' => 'Exigir fecha de vencimiento escrita en el documento'])) ?></label></div>
      <div class="dmo-linha"><label for="dmoEmissao"><?= $e($T('f.emissao', ['pt' => 'Emitido há no máximo', 'en' => 'Issued at most', 'es' => 'Emitido hace como máximo'])) ?></label><input type="number" min="1" max="3650" class="form-control form-control-sm" id="dmoEmissao" data-dmo="emissao" value="<?= $e($v('EMISSAO_MAX_DIAS')) ?>"<?= $dis ?>><span><?= $e($T('f.dias', ['pt' => 'dias (vazio = não conferir)', 'en' => 'days (empty = do not check)', 'es' => 'días (vacío = no revisar)'])) ?></span></div>
      <div class="dmo-linha"><label for="dmoValMin"><?= $e($T('f.validade_min', ['pt' => 'Válido por pelo menos', 'en' => 'Valid for at least', 'es' => 'Válido por al menos'])) ?></label><input type="number" min="0" max="3650" class="form-control form-control-sm" id="dmoValMin" data-dmo="validade_min" value="<?= $e($v('VALIDADE_MIN_DIAS')) ?>"<?= $dis ?>><span><?= $e($T('f.dias_hoje', ['pt' => 'dias a partir do dia da conferência', 'en' => 'days from the check date', 'es' => 'días desde el día de la revisión'])) ?></span></div>
    </section>
  </div>
  <section class="dmo-card mt-3">
    <div class="d-flex align-items-center justify-content-between"><h3 class="mb-0"><?= $e($T('c.itens', ['pt' => 'O que o documento precisa ter', 'en' => 'What the document must contain', 'es' => 'Lo que el documento debe tener'])) ?></h3>
      <button type="button" class="btn btn-sm btn-outline-success" data-dmo-item<?= $dis ?>><i class="bi bi-plus-lg me-1"></i><?= $e($T('botao.item', ['pt' => 'Item', 'en' => 'Item', 'es' => 'Ítem'])) ?></button></div>
    <p class="dmo-nota"><?= $e($T('n.itens', ['pt' => 'Escreva cada item de forma que dê para conferir no texto do documento. Obrigatório: se faltar, a IA sugere devolver ao fornecedor. Recomendado: só aparece no parecer.', 'en' => 'Write each item so it can be checked in the document text. Required: if missing, the AI suggests returning it to the supplier. Recommended: only shown in the opinion.', 'es' => 'Escriba cada ítem de forma que se pueda revisar en el texto del documento. Obligatorio: si falta, la IA sugiere devolver al proveedor. Recomendado: solo aparece en el dictamen.'])) ?></p>
    <div data-dmo-itens></div>
  </section>
  <section class="dmo-card mt-3">
    <h3><?= $e($T('c.exemplo', ['pt' => 'Arquivo de exemplo', 'en' => 'Sample file', 'es' => 'Archivo de ejemplo'])) ?></h3>
    <p class="dmo-nota"><?= $e($T('n.exemplo', ['pt' => 'Envie um documento bom (aprovado) deste tipo. O texto é lido pelo serviço de OCR e a IA sugere os itens; nada é aplicado sem você escolher. Os dados pessoais do exemplo não entram nos itens.', 'en' => 'Upload a good (approved) document of this type. The OCR service reads it and the AI suggests the items; nothing is applied until you choose. Personal data from the sample is not used in the items.', 'es' => 'Envíe un documento bueno (aprobado) de este tipo. El servicio de OCR lo lee y la IA sugiere los ítems; nada se aplica sin que usted elija.'])) ?></p>
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <input type="file" class="d-none" data-dmo-arquivo accept="<?= $e(implode(',', array_map(static fn($x) => '.' . $x, $extensoes))) ?>">
      <button type="button" class="btn btn-sm btn-outline-success" data-dmo-enviar<?= $dis ?>><i class="bi bi-upload me-1"></i><?= $e($T('botao.enviar', ['pt' => 'Enviar exemplo', 'en' => 'Upload sample', 'es' => 'Enviar ejemplo'])) ?></button>
      <a class="small" data-dmo-ver target="_blank" rel="noopener" href="?action=exemplo&amp;escopo=<?= $e($escopo) ?>&amp;criterio=<?= (int)$criterio ?>" hidden></a>
      <span class="small text-muted" data-dmo-leitura></span>
      <button type="button" class="btn btn-sm btn-success ms-auto" data-dmo-sugerir disabled><i class="bi bi-stars me-1"></i><?= $e($T('botao.sugerir', ['pt' => 'Sugerir itens pela IA', 'en' => 'Suggest items with AI', 'es' => 'Sugerir ítems con IA'])) ?></button>
    </div>
    <div data-dmo-sugestao class="mt-2"></div>
  </section>
  <section class="dmo-card mt-3">
    <h3><?= $e($T('c.validacoes', ['pt' => 'Últimas validações automáticas', 'en' => 'Latest automatic validations', 'es' => 'Últimas validaciones automáticas'])) ?></h3>
<?php if (!$validacoes): ?>
    <p class="dmo-nota mb-0"><?= $e($T('n.sem_validacoes', ['pt' => 'Nenhuma ainda. O serviço confere a cada 5 minutos os arquivos novos dos documentos pendentes com a empresa.', 'en' => 'None yet. The service checks new files of documents pending with the company every 5 minutes.', 'es' => 'Ninguna todavía. El servicio revisa cada 5 minutos los archivos nuevos de los documentos pendientes con la empresa.'])) ?></p>
<?php else: ?>
    <div class="table-responsive"><table class="table table-sm dmo-tabela mb-0"><thead><tr>
      <th><?= $e($T('t.data', ['pt' => 'Data', 'en' => 'Date', 'es' => 'Fecha'])) ?></th><th><?= $e($escopo === 'FORNECEDOR' ? $T('t.fornecedor', ['pt' => 'Fornecedor', 'en' => 'Supplier', 'es' => 'Proveedor']) : $T('t.funcionario', ['pt' => 'Funcionário / fornecedor', 'en' => 'Employee / supplier', 'es' => 'Empleado / proveedor'])) ?></th>
      <th><?= $e($T('t.arquivo', ['pt' => 'Arquivo', 'en' => 'File', 'es' => 'Archivo'])) ?></th><th><?= $e($T('t.resultado', ['pt' => 'Resultado', 'en' => 'Result', 'es' => 'Resultado'])) ?></th><th><?= $e($T('t.parecer', ['pt' => 'Parecer', 'en' => 'Opinion', 'es' => 'Dictamen'])) ?></th></tr></thead><tbody>
<?php foreach ($validacoes as $x): $res = $rotRes[(string)$x['RESULTADO']] ?? null; $link = GedAnaliseIaRegistro::link($cfg['ged'], $cfg['chave'], (int)$x['CODIGO'], (string)$x['ARQUIVO']); ?>
      <tr><td class="text-nowrap"><?= $e($dt((string)($x['CONCLUIDO_EM'] ?: $x['CRIADO_EM']))) ?></td><td><?= $e($x['DOCUMENTO_DE']) ?></td>
        <td><a href="<?= $e(preg_replace('#^/principal/#', '', $link)) ?>" target="_blank" rel="noopener" data-dmo-abrir><?= $e(mb_strimwidth((string)$x['ARQUIVO'], 0, 48, '…')) ?></a></td>
        <td class="text-nowrap"><?php if ($res): ?><span class="badge text-bg-<?= $e($res[1]) ?>"><?= $e($res[0]) ?></span> <span class="text-muted"><?= (int)$x['CONFORMES'] ?>/<?= (int)$x['TOTAL'] ?></span><?php else: ?><span class="text-muted"><?= $e($rotSt[(string)$x['STATUS']] ?? (string)$x['STATUS']) ?></span><?php endif; ?></td>
        <td class="small"><?= $e((string)$x['MENSAGEM']) ?></td></tr>
<?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>
  </section>
<?php endif; ?>
</main>
<script>window.greenDocModelo = <?= json_encode($estado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="../assets/js/documento-modelo.js?v=1"></script>
<script src="../assets/js/ajuda-transacao.js?v=1"></script>
</body></html>
