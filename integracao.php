<?php
declare(strict_types=1);

/*
 * Integração de funcionários — página do funcionário (migração 348; Onda 2 da apresentação Atlas).
 * Aberta pelo link gerado em Homologação › Integração de funcionários: integracao.php?e=<código da empresa>&t=<código do link>.
 * Sem senha: o código do link (24 bytes aleatórios; no banco fica só o SHA-256) vale para um funcionário, até o vencimento e
 * para uma resposta. O funcionário responde o questionário de integração do seu tipo (motor universal de questionários);
 * ao enviar, a nota e a sugestão (Aprovado/Reprovado pela nota mínima) vão para o responsável, que confirma a decisão.
 * A nota não aparece para o funcionário. Banco: sp_fo_integracao_* e o motor de questionários (QuestionnaireService).
 */
require_once __DIR__ . '/classes/GreenPortalDatabase.php';
require_once __DIR__ . '/classes/IdiomaService.php';
require_once __DIR__ . '/classes/IntegracaoFuncionario.php';
require_once __DIR__ . '/classes/QuestionnaireService.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$idiomas = ['pt' => 'Português', 'en' => 'English', 'es' => 'Español'];
if (isset($_GET['l']) && isset($idiomas[(string)$_GET['l']])) $_SESSION['integracao_idioma'] = (string)$_GET['l'];
$lg = (string)($_SESSION['integracao_idioma'] ?? (in_array((string)($_SESSION['idioma'] ?? ''), ['pt', 'en', 'es'], true) ? $_SESSION['idioma'] : 'pt'));
// Textos no idioma escolhido nesta página (não muda o idioma de quem estiver logado no portal neste navegador)
$T = static fn(string $c, array $p): string => $p[$lg] ?? $p['pt'];
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$empresaCod = trim((string)($_GET['e'] ?? ''));
$token = trim((string)($_GET['t'] ?? ''));

$tela = static function (string $titulo, string $corpo, string $empresaNome = '', bool $erro = false) use ($e, $lg, $idiomas, $empresaCod, $token): never {
    $url = static fn(string $l): string => '?' . http_build_query(['e' => $empresaCod, 't' => $token, 'l' => $l]);
    echo '<!doctype html><html lang="' . ($lg === 'pt' ? 'pt-BR' : $lg) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
        . '<title>' . $e($titulo) . '</title><link rel="stylesheet" href="assets/vendor/bootstrap/css/bootstrap.min.css?v=2"><link rel="stylesheet" href="assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2">'
        . '<link rel="stylesheet" href="assets/css/integracao-publica.css?v=1"></head><body><main class="ip">'
        . '<header class="ip-topo"><div><div class="ip-emp">' . $e($empresaNome) . '</div><h1>' . $e($titulo) . '</h1></div><nav class="ip-idiomas">'
        . implode('', array_map(static fn($k, $n) => '<a href="' . $e($url($k)) . '"' . ($k === $lg ? ' class="ativo"' : '') . ' hreflang="' . $k . '">' . $e($n) . '</a>', array_keys($idiomas), $idiomas)) . '</nav></header>'
        . ($erro ? '<div class="ip-aviso"><i class="bi bi-exclamation-circle"></i> ' . $corpo . '</div>' : $corpo)
        . '</main><script src="assets/js/integracao-publica.js?v=1"></script></body></html>';
    exit;
};
$tituloPadrao = $T('titulo', ['pt' => 'Questionário de integração', 'en' => 'Induction questionnaire', 'es' => 'Cuestionario de integración']);
$invalido = $T('link_invalido', ['pt' => 'Link inválido. Confira o endereço recebido ou peça um novo link ao responsável pela integração.', 'en' => 'Invalid link. Check the address you received or ask the induction coordinator for a new link.', 'es' => 'Enlace inválido. Revise la dirección recibida o pida un nuevo enlace al responsable de la integración.']);
if (!preg_match('/^[A-Za-z0-9_.-]{1,60}$/', $empresaCod) || !preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token)) $tela($tituloPadrao, $e($invalido), '', true);

// Empresa pelo código (a mesma lista do login) e o banco dela
try {
    $env = GreenPortalDatabase::env();
    $universal = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
    $empresa = null;
    foreach (GreenPortalDatabase::call($universal, 'CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', ['tabela' => 'vw_empresas_login_migradas', 'filtros' => '{}']) as $c)
        if (strcasecmp((string)($c['codigo'] ?? ''), $empresaCod) === 0) $empresa = $c;
    if (!$empresa || !preg_match('/^[A-Za-z0-9_]+$/', (string)$empresa['banco_dados'])) $tela($tituloPadrao, $e($invalido), '', true);
    $pdo = GreenPortalDatabase::connect($env, (string)$empresa['banco_dados'], 'GPT');
    if (!IntegracaoFuncionario::disponivel($pdo)) $tela($tituloPadrao, $e($invalido), (string)$empresa['nome'], true);
    $i = IntegracaoFuncionario::chamar($pdo, 'CALL sp_fo_integracao_por_token(?)', [IntegracaoFuncionario::hashToken($token)])[0] ?? null;
} catch (Throwable $x) {
    error_log('[integração pública] ' . $x->getMessage());
    $tela($tituloPadrao, $e($T('indisponivel', ['pt' => 'O questionário está indisponível no momento. Tente de novo em alguns minutos.', 'en' => 'The questionnaire is unavailable right now. Try again in a few minutes.', 'es' => 'El cuestionario no está disponible ahora. Intente de nuevo en unos minutos.'])), '', true);
}
$empNome = (string)$empresa['nome'];
if (!$i) $tela($tituloPadrao, $e($invalido), $empNome, true);
$feito = static fn(): string => '<div class="ip-ok"><i class="bi bi-check-circle"></i><h2>' . $e($T('obrigado', ['pt' => 'Respostas enviadas. Obrigado!', 'en' => 'Answers sent. Thank you!', 'es' => '¡Respuestas enviadas. Gracias!'])) . '</h2><p>'
    . $e($T('obrigado_texto', ['pt' => 'O responsável pela integração vai conferir o resultado e liberar a sua entrada. Você pode fechar esta página.', 'en' => 'The induction coordinator will review the result and release your access. You can close this page.', 'es' => 'El responsable de la integración revisará el resultado y liberará su entrada. Puede cerrar esta página.'])) . '</p></div>';
if ((string)$i['STATUS'] !== 'AGUARDANDO') {
    if (in_array((string)$i['STATUS'], ['RESPONDIDA', 'APROVADA', 'REPROVADA'], true)) $tela($tituloPadrao, $feito(), $empNome);
    $tela($tituloPadrao, $e($T('cancelado', ['pt' => 'Este link foi substituído ou cancelado. Peça o link novo ao responsável pela integração.', 'en' => 'This link was replaced or cancelled. Ask the induction coordinator for the new link.', 'es' => 'Este enlace fue reemplazado o cancelado. Pida el nuevo enlace al responsable de la integración.'])), $empNome, true);
}
if (!(int)$i['LINK_VALIDO']) $tela($tituloPadrao, $e($T('expirado', ['pt' => 'Este link venceu. Peça um novo link ao responsável pela integração.', 'en' => 'This link has expired. Ask the induction coordinator for a new link.', 'es' => 'Este enlace venció. Pida un nuevo enlace al responsable de la integración.'])), $empNome, true);

// Execução do questionário (uma por integração; a chave garante a mesma execução se a página for aberta de novo)
try {
    $q = new QuestionnaireService($pdo, $universal, (int)$empresa['codempresa']);
    $exec = $q->start((string)$i['CODIGO_QUESTIONARIO'], 0, (int)($i['CODUNIDADE'] ?? 0) ?: null, 'fo_funcionarios', (string)(int)$i['CODFUNCIONARIO'], 'INTEGRACAO:' . (int)$i['CODINTEGRACAO']);
    $codexec = (int)$exec['codexecucao'];
    if ((int)($i['CODEXECUCAO'] ?? 0) !== $codexec) IntegracaoFuncionario::chamar($pdo, 'CALL sp_fo_integracao_execucao(?, ?)', [(int)$i['CODINTEGRACAO'], $codexec]);
    $perguntas = $q->questions($codexec, $lg);
} catch (Throwable $x) {
    error_log('[integração pública] questionário: ' . $x->getMessage());
    $tela($tituloPadrao, $e($T('indisponivel', ['pt' => 'O questionário está indisponível no momento. Tente de novo em alguns minutos.', 'en' => 'The questionnaire is unavailable right now. Try again in a few minutes.', 'es' => 'El cuestionario no está disponible ahora. Intente de nuevo en unos minutos.'])), $empNome, true);
}

$csrfChave = 'integracao_csrf_' . (int)$i['CODINTEGRACAO'];
if (empty($_SESSION[$csrfChave])) $_SESSION[$csrfChave] = bin2hex(random_bytes(24));
$erroEnvio = '';
$respostas = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $respostas = is_array($_POST['r'] ?? null) ? $_POST['r'] : [];
    if (!hash_equals((string)$_SESSION[$csrfChave], (string)($_POST['csrf'] ?? ''))) $erroEnvio = $T('sessao', ['pt' => 'A página ficou aberta por muito tempo. Confira as respostas e envie de novo.', 'en' => 'The page was open for too long. Check your answers and send again.', 'es' => 'La página estuvo abierta mucho tiempo. Revise las respuestas y envíe de nuevo.']);
    else {
        $limpas = [];
        foreach ($perguntas as $p) {
            if ($p['tipo'] === 'ANEXO') continue;
            $v = $respostas[(string)$p['codpergunta']] ?? null;
            if (is_array($v)) $v = array_values(array_filter(array_map('strval', $v), static fn($x) => $x !== ''));
            elseif ($v !== null) $v = mb_substr(trim((string)$v), 0, 2000);
            if ($v !== null && $v !== '' && $v !== []) $limpas[(string)$p['codigo']] = $v;
        }
        try {
            $r = $q->submit($codexec, 0, $limpas, $lg);
            $pct = (float)$r['percentual'];
            $alt = (int)(IntegracaoFuncionario::chamar($pdo, 'CALL sp_fo_integracao_respondida(?, ?, ?)', [(int)$i['CODINTEGRACAO'], $codexec, $pct])[0]['ALTERADOS'] ?? 0);
            if ($alt > 0) {
                $sug = (string)(IntegracaoFuncionario::chamar($pdo, 'SELECT SUGESTAO FROM vw_fo_integracoes WHERE CODINTEGRACAO = ?', [(int)$i['CODINTEGRACAO']])[0]['SUGESTAO'] ?? '');
                IntegracaoFuncionario::alertarRespondida($pdo, $i, $pct, $sug);
            }
            unset($_SESSION[$csrfChave]);
            $tela($tituloPadrao, $feito(), $empNome);
        } catch (InvalidArgumentException $x) {
            $erroEnvio = str_contains($x->getMessage(), 'obrigatória')
                ? $T('obrigatoria', ['pt' => 'Responda todas as perguntas marcadas com *.', 'en' => 'Answer all questions marked with *.', 'es' => 'Responda todas las preguntas marcadas con *.'])
                : $T('erro_envio', ['pt' => 'Não foi possível enviar. Confira as respostas.', 'en' => 'Could not send. Check your answers.', 'es' => 'No fue posible enviar. Revise las respuestas.']);
        } catch (Throwable $x) {
            error_log('[integração pública] envio: ' . $x->getMessage());
            $erroEnvio = $T('erro_envio', ['pt' => 'Não foi possível enviar. Confira as respostas.', 'en' => 'Could not send. Check your answers.', 'es' => 'No fue posible enviar. Revise las respuestas.']);
        }
    }
}

// Blocos (temas) do questionário, na ordem
$blocos = [];
try {
    $s = $universal->prepare('SELECT codbloco, titulo_pt, titulo_en, titulo_es, ordem FROM vw_amb_questionario_blocos WHERE codversao = ? ORDER BY ordem, codbloco');
    $s->execute([(int)$exec['codversao']]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $b) $blocos[(int)$b['codbloco']] = ['titulo' => (string)($b['titulo_' . $lg] ?: $b['titulo_pt']), 'ordem' => (int)$b['ordem'], 'perguntas' => []];
} catch (Throwable) {}
$semBloco = ['titulo' => '', 'ordem' => PHP_INT_MAX, 'perguntas' => []];
foreach ($perguntas as $p) { $b = $p['codbloco']; if ($b !== null && isset($blocos[$b])) $blocos[$b]['perguntas'][] = $p; else $semBloco['perguntas'][] = $p; }
$blocos = array_values(array_filter($blocos, static fn($b) => $b['perguntas']));
if ($semBloco['perguntas']) $blocos[] = $semBloco;

$campo = static function (array $p) use ($e, $respostas, $T): string {
    $id = (int)$p['codpergunta']; $nome = 'r[' . $id . ']'; $atual = $respostas[(string)$id] ?? null;
    if (in_array($p['tipo'], ['UNICA_ESCOLHA', 'SIM_NAO'], true)) {
        return '<div class="ip-opcoes">' . implode('', array_map(static fn($o) => '<label class="ip-opcao"><input type="radio" name="' . $e($nome) . '" value="' . $e($o['codigo']) . '"'
            . ((string)$atual === (string)$o['codigo'] ? ' checked' : '') . ($p['obrigatoria'] ? ' required' : '') . '><span>' . $e($o['rotulo']) . '</span></label>', $p['opcoes'])) . '</div>';
    }
    if ($p['tipo'] === 'MULTIPLA_ESCOLHA') {
        $marc = is_array($atual) ? array_map('strval', $atual) : [];
        return '<div class="ip-opcoes">' . implode('', array_map(static fn($o) => '<label class="ip-opcao"><input type="checkbox" name="' . $e($nome) . '[]" value="' . $e($o['codigo']) . '"'
            . (in_array((string)$o['codigo'], $marc, true) ? ' checked' : '') . '><span>' . $e($o['rotulo']) . '</span></label>', $p['opcoes'])) . '</div>';
    }
    if ($p['tipo'] === 'ANEXO') return '<div class="text-muted small">' . $e($T('anexo', ['pt' => 'Não se aplica nesta etapa.', 'en' => 'Not applicable at this step.', 'es' => 'No se aplica en esta etapa.'])) . '</div>';
    if ($p['tipo'] === 'TEXTO') return '<textarea class="form-control" rows="2" maxlength="2000" name="' . $e($nome) . '"' . ($p['obrigatoria'] ? ' required' : '') . '>' . $e(is_scalar($atual) ? $atual : '') . '</textarea>';
    return '<input class="form-control" type="' . ($p['tipo'] === 'NUMERO' ? 'number' : ($p['tipo'] === 'DATA' ? 'date' : 'text')) . '" name="' . $e($nome) . '" value="' . $e(is_scalar($atual) ? $atual : '') . '"' . ($p['obrigatoria'] ? ' required' : '') . '>';
};
$n = 0; $total = count(array_filter($perguntas, static fn($p) => $p['tipo'] !== 'ANEXO'));
$html = '<section class="ip-quem"><div><span>' . $e($T('funcionario', ['pt' => 'Funcionário', 'en' => 'Employee', 'es' => 'Empleado'])) . '</span><b>' . $e($i['FUNCIONARIO']) . '</b></div>'
    . ($i['RAZAOSOCIAL'] ? '<div><span>' . $e($T('empresa', ['pt' => 'Empresa', 'en' => 'Company', 'es' => 'Empresa'])) . '</span><b>' . $e($i['RAZAOSOCIAL']) . '</b></div>' : '')
    . '<div><span>' . $e($T('questionario', ['pt' => 'Questionário', 'en' => 'Questionnaire', 'es' => 'Cuestionario'])) . '</span><b>' . $e($i['QUESTIONARIO'] ?: $i['CODIGO_QUESTIONARIO']) . '</b></div></section>'
    . '<p class="ip-intro">' . $e($T('intro', ['pt' => 'Leia cada pergunta com atenção e escolha a resposta. As perguntas com * são obrigatórias. Ao terminar, toque em "Enviar respostas" (só é possível enviar uma vez).', 'en' => 'Read each question carefully and choose the answer. Questions marked with * are required. When done, tap "Send answers" (you can send only once).', 'es' => 'Lea cada pregunta con atención y elija la respuesta. Las preguntas con * son obligatorias. Al terminar, toque "Enviar respuestas" (solo se puede enviar una vez).'])) . '</p>'
    . ($erroEnvio !== '' ? '<div class="ip-aviso"><i class="bi bi-exclamation-circle"></i> ' . $e($erroEnvio) . '</div>' : '')
    . '<form method="post" data-ip-form novalidate><input type="hidden" name="csrf" value="' . $e($_SESSION[$csrfChave]) . '">';
foreach ($blocos as $b) {
    $html .= '<section class="ip-bloco">' . ($b['titulo'] !== '' ? '<h2>' . $e($b['titulo']) . '</h2>' : '');
    foreach ($b['perguntas'] as $p) {
        if ($p['tipo'] !== 'ANEXO') $n++;
        $html .= '<fieldset class="ip-pergunta" data-ip-pergunta' . ($p['obrigatoria'] && $p['tipo'] !== 'ANEXO' ? ' data-obrigatoria' : '') . '><legend><span class="ip-n">' . ($p['tipo'] !== 'ANEXO' ? $n : '') . '</span>' . $e($p['texto']) . ($p['obrigatoria'] && $p['tipo'] !== 'ANEXO' ? ' <span class="ip-ob">*</span>' : '') . '</legend>' . $campo($p) . '</fieldset>';
    }
    $html .= '</section>';
}
$html .= '<div class="ip-enviar"><span class="ip-progresso" data-ip-progresso data-total="' . $total . '"></span><button type="submit" class="btn btn-success btn-lg"><i class="bi bi-send me-1"></i>' . $e($T('enviar', ['pt' => 'Enviar respostas', 'en' => 'Send answers', 'es' => 'Enviar respuestas'])) . '</button></div>'
    . '<div class="ip-falta" data-ip-falta hidden>' . $e($T('obrigatoria', ['pt' => 'Responda todas as perguntas marcadas com *.', 'en' => 'Answer all questions marked with *.', 'es' => 'Responda todas las preguntas marcadas con *.'])) . '</div></form>'
    . '<p class="ip-rodape">' . $e($T('validade_link', ['pt' => 'Link válido até', 'en' => 'Link valid until', 'es' => 'Enlace válido hasta'])) . ' ' . $e(date('d/m/Y H:i', strtotime((string)$i['LINK_EXPIRA_EM']))) . '</p>';
$tela($tituloPadrao, $html, $empNome);
