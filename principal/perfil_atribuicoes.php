<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/AdministracaoTextos.php';

/*
 * Meu perfil de atribuições (migração 268). Cada usuário responde o próprio perfil; não depende de permissão de menu.
 * Abre no login como popup (?popup=1, com "Lembrar depois") enquanto o perfil estiver pendente, e em Minha conta.
 * Questionário: catálogo universal PERFIL_ATRIBUICOES, só com os blocos que o usuário responde (PerfilAtribuicoesService).
 * Envio: principal/api/perfil_atribuicoes.php (action=salvar) → sp_perfil_atrib_enviar → aprovação do gestor.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/PerfilAtribuicoesService.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: text/html; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$h = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$t = static fn(string $k, array $p): string => IdiomaService::t('perfil_atribuicoes.' . $k, $p);
try {
    $pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
    if (!UsuarioSessao::atualizar($pdo, $_SESSION)) throw new RuntimeException('sessao');
    $svc = new PerfilAtribuicoesService($pdo, PerfilAtribuicoesService::universal());
    $q = $svc->questionarioDoUsuario($_SESSION);
    $sit = $svc->situacao($_SESSION);
    $respostas = $svc->respostasAtuais((int)$_SESSION['usuario']['codusuario']);
} catch (Throwable $e) {
    error_log('perfil_atribuicoes: ' . $e->getMessage());
    http_response_code(500);
    exit($h(IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou ou o perfil de atribuições ainda não foi instalado (migração 268).', 'en' => 'Your session expired or the responsibilities profile is not installed yet (migration 268).', 'es' => 'Su sesión expiró o el perfil de atribuciones aún no fue instalado (migración 268).'])));
}
if (empty($_SESSION['perfil_atribuicoes_csrf'])) $_SESSION['perfil_atribuicoes_csrf'] = bin2hex(random_bytes(32));
$popup = !empty($_GET['popup']);
if ($q && empty($sit['aplica'])) $q = null;   // perfil de acesso sem "Aplicar questionário" (Segurança › Perfil)
$T = [
    'titulo' => $t('titulo', ['pt' => 'Meu perfil de atribuições', 'en' => 'My responsibilities profile', 'es' => 'Mi perfil de atribuciones']),
    'intro' => $t('intro', ['pt' => 'Conte o que você faz na empresa. Com estas respostas o sistema indica as tarefas certas para você (agenda, pendências e alertas). Marque só o que faz parte do seu trabalho; o que ficar em branco significa que você não participa.', 'en' => 'Tell us what you do in the company. With these answers the system assigns you the right tasks (agenda, pending items and alerts). Check only what is part of your work; blank means you do not take part.', 'es' => 'Cuéntenos lo que hace en la empresa. Con estas respuestas el sistema le indica las tareas correctas (agenda, pendientes y alertas). Marque solo lo que forma parte de su trabajo; en blanco significa que no participa.']),
    'm_NUNCA_RESPONDEU' => $t('motivo.nunca', ['pt' => 'Você ainda não respondeu o seu perfil de atribuições.', 'en' => 'You have not answered your responsibilities profile yet.', 'es' => 'Aún no respondió su perfil de atribuciones.']),
    'm_DEVOLVIDO' => $t('motivo.devolvido', ['pt' => 'O seu gestor devolveu o perfil para ajustes:', 'en' => 'Your manager returned the profile for changes:', 'es' => 'Su gestor devolvió el perfil para ajustes:']),
    'm_REVISAO_PERIODICA' => $t('motivo.revisao', ['pt' => 'Chegou a hora da revisão anual do seu perfil. Confira se as respostas continuam valendo.', 'en' => 'It is time for the yearly review of your profile. Check whether the answers are still valid.', 'es' => 'Llegó la revisión anual de su perfil. Verifique si las respuestas siguen valiendo.']),
    'm_QUESTIONARIO_ATUALIZADO' => $t('motivo.versao', ['pt' => 'O questionário foi atualizado. Confira as suas respostas e envie de novo.', 'en' => 'The questionnaire was updated. Review your answers and submit again.', 'es' => 'El cuestionario fue actualizado. Revise sus respuestas y envíe de nuevo.']),
    'm_NOVO_MODULO' => $t('motivo.modulo', ['pt' => 'Você passou a ter acesso a um novo módulo. Responda as perguntas dele.', 'en' => 'You now have access to a new module. Answer its questions.', 'es' => 'Ahora tiene acceso a un nuevo módulo. Responda sus preguntas.']),
    'pendente' => $t('status.pendente', ['pt' => 'Perfil enviado em {data}, aguardando a aprovação de {gestor}. Você pode ajustar e reenviar.', 'en' => 'Profile submitted on {data}, waiting for {gestor} to approve. You can adjust and resubmit.', 'es' => 'Perfil enviado el {data}, esperando la aprobación de {gestor}. Puede ajustar y reenviar.']),
    'aprovado' => $t('status.aprovado', ['pt' => 'Perfil aprovado, válido até {data}.', 'en' => 'Profile approved, valid until {data}.', 'es' => 'Perfil aprobado, válido hasta {data}.']),
    'admin_empresa' => $t('status.admin_empresa', ['pt' => 'o administrador da empresa', 'en' => 'the company administrator', 'es' => 'el administrador de la empresa']),
    'sem_gestor' => $t('status.sem_gestor', ['pt' => 'Você não tem gestor cadastrado: o administrador da empresa fará a aprovação.', 'en' => 'You have no manager registered: the company administrator will approve.', 'es' => 'No tiene gestor registrado: el administrador de la empresa hará la aprobación.']),
    'papel_ajuda' => $t('papel_ajuda', ['pt' => 'Marque o seu papel em cada atividade (pode marcar mais de um).', 'en' => 'Check your role in each activity (you can check more than one).', 'es' => 'Marque su papel en cada actividad (puede marcar más de uno).']),
    'atividade' => $t('atividade', ['pt' => 'Atividade', 'en' => 'Activity', 'es' => 'Actividad']),
    'selecione' => $t('selecione', ['pt' => 'Selecione', 'en' => 'Select', 'es' => 'Seleccione']),
    'sim' => IdiomaService::t('comum.sim', ['pt' => 'Sim', 'en' => 'Yes', 'es' => 'Sí']),
    'nao' => IdiomaService::t('comum.nao', ['pt' => 'Não', 'en' => 'No', 'es' => 'No']),
    'obrigatoria' => $t('obrigatoria', ['pt' => 'obrigatória', 'en' => 'required', 'es' => 'obligatoria']),
    'enviar' => $t('enviar', ['pt' => 'Enviar para aprovação', 'en' => 'Submit for approval', 'es' => 'Enviar para aprobación']),
    'depois' => $t('depois', ['pt' => 'Lembrar depois', 'en' => 'Remind me later', 'es' => 'Recordar después']),
    'enviando' => $t('enviando', ['pt' => 'Enviando...', 'en' => 'Submitting...', 'es' => 'Enviando...']),
    'nao_aplica' => $t('nao_aplica', ['pt' => 'O questionário de atribuições não se aplica ao seu perfil de acesso.', 'en' => 'The responsibilities questionnaire does not apply to your access profile.', 'es' => 'El cuestionario de atribuciones no se aplica a su perfil de acceso.']),
    'indisponivel' => $t('indisponivel', ['pt' => 'O questionário de atribuições ainda não está publicado. Fale com o administrador do sistema.', 'en' => 'The responsibilities questionnaire is not published yet. Contact the system administrator.', 'es' => 'El cuestionario de atribuciones aún no está publicado. Hable con el administrador del sistema.']),
    'erro' => $t('erro.geral', ['pt' => 'Não foi possível concluir agora. Tente de novo em instantes.', 'en' => 'Could not complete now. Try again shortly.', 'es' => 'No fue posible concluir ahora. Intente de nuevo en instantes.']),
    'obrigatorias' => $t('msg.faltando', ['pt' => 'Responda as perguntas obrigatórias:', 'en' => 'Answer the required questions:', 'es' => 'Responda las preguntas obligatorias:']),
    'marcar_todas' => $t('marcar_todas', ['pt' => 'Marcar todas', 'en' => 'Select all', 'es' => 'Marcar todas']),
];
$data = static fn(?string $d): string => $d ? date(IdiomaService::idioma() === 'en' ? 'm/d/Y' : 'd/m/Y', strtotime($d)) : '';
// Faixa de situação
$faixa = null;
if (($sit['ultimo_status'] ?? '') === 'PENDENTE_APROVACAO') {
    $ultimo = $svc->envio((int)$sit['ultimo_codperfilatrib']) ?? [];
    $faixa = ['info', 'bi-hourglass-split', strtr($T['pendente'], ['{data}' => $data($ultimo['enviado_em'] ?? null), '{gestor}' => ($ultimo['gestor_nome'] ?? '') ?: $T['admin_empresa']])];
} elseif (!empty($sit['motivo'])) {
    $faixa = [$sit['motivo'] === 'DEVOLVIDO' ? 'warning' : 'primary', $sit['motivo'] === 'DEVOLVIDO' ? 'bi-arrow-return-left' : 'bi-person-lines-fill',
        $T['m_' . $sit['motivo']] . ($sit['motivo'] === 'DEVOLVIDO' && $sit['ultimo_motivo'] ? ' «' . $sit['ultimo_motivo'] . '»' : '')];
} elseif (!empty($sit['valido_ate'])) {
    $faixa = ['success', 'bi-check-circle', strtr($T['aprovado'], ['{data}' => $data($sit['valido_ate'])])];
}
$marcado = static function ($v, string $op): bool { return in_array($op, array_map('strval', (array)$v), true); };
?><!doctype html>
<html lang="<?= IdiomaService::idioma() === 'pt' ? 'pt-BR' : $h(IdiomaService::idioma()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($T['titulo']) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2">
<link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2">
<link rel="stylesheet" href="../assets/css/app.css?v=5">
<link rel="stylesheet" href="../assets/css/main.css?v=18">
<link rel="stylesheet" href="../assets/css/perfil-atribuicoes.css?v=2">
<?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?>
</head>
<body class="pa-pagina<?= $popup ? ' pa-popup' : '' ?>">
<form class="pa" data-pa data-csrf="<?= $h($_SESSION['perfil_atribuicoes_csrf']) ?>" novalidate>
    <div class="pa-corpo">
        <?php if (!$popup): ?><h1 class="pa-titulo"><i class="bi bi-person-lines-fill" aria-hidden="true"></i> <?= $h($T['titulo']) ?>
            <button type="button" class="btn btn-sm btn-link pa-ajuda" data-open-transacao-ajuda data-help-transaction="perfil_atribuicoes" title="<?= $h($T['titulo']) ?>"><i class="bi bi-question-circle"></i></button></h1><?php endif; ?>
        <?php if ($faixa): ?><div class="alert alert-<?= $faixa[0] ?> pa-faixa"><i class="bi <?= $faixa[1] ?>" aria-hidden="true"></i> <?= $h($faixa[2]) ?></div><?php endif; ?>
        <?php if (!$q): ?>
            <div class="alert alert-secondary"><?= $h(empty($sit['aplica']) ? $T['nao_aplica'] : $T['indisponivel']) ?></div>
        <?php else: ?>
            <p class="pa-intro"><?= $h($T['intro']) ?><?php if (!$sit['tem_gestor']): ?> <span class="pa-sem-gestor"><?= $h($T['sem_gestor']) ?></span><?php endif; ?></p>
            <?php foreach ($q['blocos'] as $bi => $b):
                $pilares = array_values(array_filter($b['perguntas'], static fn($p) => str_contains($p['codigo'], '.PILAR.')));
                $outras = array_values(array_filter($b['perguntas'], static fn($p) => !str_contains($p['codigo'], '.PILAR.'))); ?>
            <section class="pa-bloco" data-pa-bloco="<?= $h($b['codigo']) ?>">
                <h2 class="pa-bloco-titulo"><span class="pa-num"><?= $bi + 1 ?></span> <?= $h($b['titulo']) ?></h2>
                <?php if ($pilares): $papeis = $pilares[0]['opcoes']; ?>
                <div class="pa-matriz-caixa">
                    <div class="pa-matriz-ajuda"><?= $h($T['papel_ajuda']) ?></div>
                    <table class="table table-sm pa-matriz">
                        <thead><tr><th><?= $h($T['atividade']) ?></th><?php foreach ($papeis as $o): ?><th class="pa-col-papel"><?= $h($o['rotulo']) ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                        <?php foreach ($pilares as $p): $v = $respostas[$p['codigo']] ?? null; ?>
                            <tr data-pa-pergunta="<?= $h($p['codigo']) ?>" data-tipo="MULTIPLA_ESCOLHA">
                                <td class="pa-atividade"><?= $h($p['texto']) ?></td>
                                <?php foreach ($papeis as $o): ?><td class="pa-col-papel"><input type="checkbox" class="form-check-input" value="<?= $h($o['codigo']) ?>" aria-label="<?= $h($p['texto'] . ' — ' . $o['rotulo']) ?>"<?= $marcado($v, $o['codigo']) ? ' checked' : '' ?>></td><?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                <?php foreach ($outras as $p): $v = $respostas[$p['codigo']] ?? null; ?>
                <div class="pa-pergunta" data-pa-pergunta="<?= $h($p['codigo']) ?>" data-tipo="<?= $h($p['tipo']) ?>"<?= $p['obrigatoria'] ? ' data-obrigatoria="1"' : '' ?>>
                    <div class="pa-pergunta-texto"><?= $h($p['texto']) ?><?php if ($p['obrigatoria']): ?> <span class="pa-obrig" title="<?= $h($T['obrigatoria']) ?>">*</span><?php endif; ?></div>
                    <?php if ($p['tipo'] === 'MULTIPLA_ESCOLHA'): ?>
                        <div class="pa-chips">
                            <?php foreach ($p['opcoes'] as $o): $id = 'pa_' . md5($p['codigo'] . '|' . $o['codigo']); ?>
                            <input type="checkbox" class="btn-check" id="<?= $id ?>" value="<?= $h($o['codigo']) ?>"<?= $marcado($v, $o['codigo']) ? ' checked' : '' ?>>
                            <label class="btn btn-sm btn-outline-success pa-chip" for="<?= $id ?>"><?= $h($o['rotulo']) ?></label>
                            <?php endforeach; ?>
                            <?php if (count($p['opcoes']) > 3): ?><button type="button" class="btn btn-sm btn-link pa-todas" data-pa-todas><?= $h($T['marcar_todas']) ?></button><?php endif; ?>
                        </div>
                    <?php elseif ($p['tipo'] === 'UNICA_ESCOLHA'): ?>
                        <select class="form-select form-select-sm pa-select">
                            <option value=""><?= $h($T['selecione']) ?></option>
                            <?php foreach ($p['opcoes'] as $o): ?><option value="<?= $h($o['codigo']) ?>"<?= (string)$v === $o['codigo'] ? ' selected' : '' ?>><?= $h($o['rotulo']) ?></option><?php endforeach; ?>
                        </select>
                    <?php elseif ($p['tipo'] === 'SIM_NAO'): $id = 'pa_' . md5($p['codigo']); ?>
                        <div class="pa-chips">
                            <input type="radio" class="btn-check" name="<?= $id ?>" id="<?= $id ?>_s" value="SIM"<?= $v === 'SIM' ? ' checked' : '' ?>><label class="btn btn-sm btn-outline-success pa-chip" for="<?= $id ?>_s"><?= $h($T['sim']) ?></label>
                            <input type="radio" class="btn-check" name="<?= $id ?>" id="<?= $id ?>_n" value="NAO"<?= $v === 'NAO' ? ' checked' : '' ?>><label class="btn btn-sm btn-outline-success pa-chip" for="<?= $id ?>_n"><?= $h($T['nao']) ?></label>
                        </div>
                    <?php else: ?>
                        <input type="text" class="form-control form-control-sm pa-texto" maxlength="500" value="<?= $h(is_array($v) ? '' : (string)$v) ?>">
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <footer class="pa-rodape">
        <div class="pa-mensagem" data-pa-mensagem role="status" hidden></div>
        <?php if ($popup): ?><button type="button" class="btn btn-outline-secondary" data-pa-depois><i class="bi bi-clock" aria-hidden="true"></i> <?= $h($T['depois']) ?></button><?php endif; ?>
        <?php if ($q): ?><button type="submit" class="btn btn-success" data-pa-enviar><i class="bi bi-send" aria-hidden="true"></i> <?= $h($T['enviar']) ?></button><?php endif; ?>
    </footer>
</form>
<script src="../assets/vendor/jquery/jquery-3.7.1.min.js?v=2"></script>
<script>window.PA_TEXTOS = <?= json_encode($T, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>; window.PA_POPUP = <?= $popup ? 'true' : 'false' ?>;</script>
<?= AdministracaoTextos::script() ?><script src="../assets/js/ajuda-transacao.js?v=2"></script>
<script src="../assets/js/perfil-atribuicoes.js?v=2-i3"></script>
</body>
</html>
