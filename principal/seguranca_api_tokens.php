<?php
declare(strict_types=1);

/*
 * Segurança › Tokens da API (Revisão G item 10; migração 421, 26/11/2026).
 * Cria e revoga tokens da API de leitura (api/v1.php): nome, recursos liberados (requisitos, licenças, fornecedores) e validade
 * (até 1 ano). O token aparece uma única vez, ao criar; o banco guarda só o SHA-256. Mostra último uso e quantidade de usos.
 * Permissão: menu API_TOKENS (visualizar; incluir para criar; excluir para revogar). Leitura por sp_crud_select; gravação por
 * sp_crud_insert / sp_crud_update.
 */
require_once dirname(__DIR__) . '/classes/PaginaSimples.php';
use PaginaSimples as P;

P::abrir('API_TOKENS');
if (!P::existe('api_token')) P::sair(503, P::L('Tokens da API ainda não instalados nesta empresa (migração 421).', 'API tokens are not installed yet (migration 421).', 'Tokens de la API aún no instalados (migración 421).'));
$csrf = P::csrf('api_tokens_csrf');
$REC = ['requisitos' => P::L('Requisitos legais', 'Legal requirements', 'Requisitos legales'), 'licencas' => P::L('Licenças', 'Licenses', 'Licencias'), 'fornecedores' => P::L('Fornecedores', 'Suppliers', 'Proveedores')];
$empresa = (int)($_SESSION['tenant']['codempresa'] ?? 0);
$novo = ''; $msg = ''; $erros = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    P::conferirCsrf('api_tokens_csrf');
    if (($_POST['acao'] ?? '') === 'criar') {
        if (!P::pode('incluir')) P::sair(403, P::L('Seu perfil não pode criar tokens.', 'Your profile cannot create tokens.', 'Su perfil no puede crear tokens.'));
        $nome = mb_substr(trim((string)($_POST['nome'] ?? '')), 0, 100);
        $esc = array_values(array_intersect(array_keys($REC), (array)($_POST['escopos'] ?? [])));
        $ate = (string)($_POST['valido_ate'] ?? '');
        if ($nome === '') $erros[] = P::L('Informe o nome (para que o token serve).', 'Enter the name.', 'Informe el nombre.');
        if (!$esc) $erros[] = P::L('Escolha pelo menos um recurso.', 'Choose at least one resource.', 'Elija al menos un recurso.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate) || $ate < date('Y-m-d') || $ate > date('Y-m-d', strtotime('+1 year'))) $erros[] = P::L('Validade entre hoje e um ano.', 'Validity between today and one year.', 'Validez entre hoy y un año.');
        if ($empresa < 1) $erros[] = P::L('Empresa da sessão não identificada.', 'Company not identified.', 'Empresa no identificada.');
        if (!$erros) {
            $novo = 'gp_' . $empresa . '_' . bin2hex(random_bytes(20));
            P::ins('api_token', ['NOME' => $nome, 'PREFIXO' => substr($novo, 0, strlen('gp_' . $empresa . '_') + 6), 'HASH' => hash('sha256', $novo), 'ESCOPOS' => implode(',', $esc), 'VALIDO_ATE' => $ate, 'CODUSUARIO' => P::$uid ?: null]);
            AuditService::record(P::$pdo, 'seguranca_api_tokens', 'CRIAR', 'SUCESSO', 'api_token', null, ['nome' => $nome, 'escopos' => $esc, 'valido_ate' => $ate]);
        }
    } elseif (($_POST['acao'] ?? '') === 'revogar') {
        if (!P::pode('excluir')) P::sair(403, P::L('Seu perfil não pode revogar tokens.', 'Your profile cannot revoke tokens.', 'Su perfil no puede revocar tokens.'));
        $id = (int)($_POST['id'] ?? 0);
        if (P::sel('api_token', ['CODTOKEN' => $id], 1)) { P::upd('api_token', ['CODTOKEN' => $id], ['REVOGADO_EM' => date('Y-m-d H:i:s')]); $msg = P::L('Token revogado: deixa de funcionar na hora.', 'Token revoked: it stops working right away.', 'Token revocado: deja de funcionar de inmediato.'); AuditService::record(P::$pdo, 'seguranca_api_tokens', 'REVOGAR', 'SUCESSO', 'api_token', (string)$id); }
    }
}
$lista = P::sel('vw_api_token', [], 1000);
usort($lista, static fn($a, $b) => strcmp((string)$b['CRIADO_EM'], (string)$a['CRIADO_EM']));
$base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'servidor') . preg_replace('#/principal/[^/]*$#', '', (string)($_SERVER['SCRIPT_NAME'] ?? '')) . '/api/v1.php';
session_write_close();
$fmt = static fn(?string $d): string => $d && ($t = strtotime($d)) ? date(P::$idioma === 'en' ? 'm/d/Y H:i' : 'd/m/Y H:i', $t) : '—';
P::cabecalho(P::L('Tokens da API', 'API tokens', 'Tokens de la API'), 'bi-key', 'seguranca_api_tokens');
?>
<p class="ps-ajuda small mb-2"><?= P::e(P::L('Um token deixa outro sistema ler dados da empresa sem usuário e senha (só leitura). Dê a cada integração o seu token, só com os recursos que ela precisa e com validade curta; revogue quando não for mais usado.', 'A token lets another system read company data without a user and password (read-only). Give each integration its own token, only with the resources it needs and a short validity; revoke it when no longer used.', 'Un token permite que otro sistema lea datos de la empresa sin usuario y contraseña (solo lectura). Dé a cada integración su token, solo con los recursos necesarios y validez corta; revóquelo cuando no se use más.')) ?></p>
<?php if ($novo !== ''): ?><div class="alert alert-warning"><b><?= P::e(P::L('Copie o token agora: ele não aparece de novo.', 'Copy the token now: it will not be shown again.', 'Copie el token ahora: no aparece de nuevo.')) ?></b>
<div class="input-group input-group-sm mt-1"><input class="form-control font-monospace" readonly value="<?= P::e($novo) ?>" id="tkNovo" data-sem-memoria><button class="btn btn-outline-secondary" type="button" onclick="var i=document.getElementById('tkNovo');i.select();navigator.clipboard&&navigator.clipboard.writeText(i.value)"><i class="bi bi-clipboard"></i></button></div>
<div class="small mt-1 font-monospace">curl -H "Authorization: Bearer <?= P::e($novo) ?>" "<?= P::e($base) ?>?recurso=<?= P::e(explode(',', (string)($_POST['escopos'][0] ?? 'requisitos'))[0]) ?>"</div></div><?php endif; ?>
<?php if ($msg !== ''): ?><div class="alert alert-success py-2"><?= P::e($msg) ?></div><?php endif; foreach ($erros as $er): ?><div class="alert alert-danger py-1 mb-1"><?= P::e($er) ?></div><?php endforeach; ?>
<?php if (P::pode('incluir')): ?>
<form method="post" class="card mb-3"><div class="card-body py-2"><input type="hidden" name="csrf" value="<?= P::e($csrf) ?>"><input type="hidden" name="acao" value="criar">
<div class="row g-2 align-items-end"><div class="col-md-4"><label class="form-label small mb-0"><?= P::e(P::L('Nome (para que serve)', 'Name (what it is for)', 'Nombre (para qué sirve)')) ?></label><input class="form-control form-control-sm" name="nome" maxlength="100" required data-sem-memoria></div>
<div class="col-md-4"><label class="form-label small mb-0 d-block"><?= P::e(P::L('Recursos', 'Resources', 'Recursos')) ?></label><?php foreach ($REC as $k => $r): ?><label class="me-2 small"><input type="checkbox" class="form-check-input me-1" name="escopos[]" value="<?= P::e($k) ?>"><?= P::e($r) ?></label><?php endforeach; ?></div>
<div class="col-md-2"><label class="form-label small mb-0"><?= P::e(P::L('Válido até', 'Valid until', 'Válido hasta')) ?></label><input type="date" class="form-control form-control-sm" name="valido_ate" value="<?= date('Y-m-d', strtotime('+90 days')) ?>" min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+1 year')) ?>" data-sem-memoria></div>
<div class="col-md-2"><button class="btn btn-sm btn-success w-100" type="submit"><i class="bi bi-key me-1"></i><?= P::e(P::L('Criar token', 'Create token', 'Crear token')) ?></button></div></div></div></form><?php endif; ?>
<div class="table-responsive border rounded bg-white"><table class="table table-sm mb-0"><thead class="table-light"><tr><th><?= P::e(P::L('Nome', 'Name', 'Nombre')) ?></th><th><?= P::e(P::L('Token', 'Token', 'Token')) ?></th><th><?= P::e(P::L('Recursos', 'Resources', 'Recursos')) ?></th><th><?= P::e(P::L('Válido até', 'Valid until', 'Válido hasta')) ?></th><th><?= P::e(P::L('Criado', 'Created', 'Creado')) ?></th><th><?= P::e(P::L('Último uso', 'Last use', 'Último uso')) ?></th><th class="text-end"><?= P::e(P::L('Usos', 'Uses', 'Usos')) ?></th><th><?= P::e(P::L('Situação', 'Status', 'Situación')) ?></th><th></th></tr></thead><tbody>
<?php foreach ($lista as $t): $rev = !empty($t['REVOGADO_EM']); $venc = (string)$t['VALIDO_ATE'] < date('Y-m-d'); ?>
<tr><td><?= P::e($t['NOME']) ?></td><td class="font-monospace small"><?= P::e($t['PREFIXO']) ?>…</td><td class="small"><?= P::e(implode(', ', array_map(static fn($e) => $REC[$e] ?? $e, explode(',', (string)$t['ESCOPOS'])))) ?></td>
<td class="small"><?= P::e(substr((string)$t['VALIDO_ATE'], 0, 10)) ?></td><td class="small"><?= P::e($fmt($t['CRIADO_EM']) . ' · ' . $t['USUARIO']) ?></td><td class="small"><?= P::e($fmt($t['ULTIMO_USO_EM'])) ?></td><td class="text-end small"><?= (int)$t['USOS'] ?></td>
<td><?= $rev ? '<span class="badge text-bg-secondary">' . P::e(P::L('Revogado', 'Revoked', 'Revocado')) . '</span>' : ($venc ? '<span class="badge text-bg-warning">' . P::e(P::L('Vencido', 'Expired', 'Vencido')) . '</span>' : '<span class="badge text-bg-success">' . P::e(P::L('Ativo', 'Active', 'Activo')) . '</span>') ?></td>
<td><?php if (!$rev && P::pode('excluir')): ?><form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?= P::e($csrf) ?>"><input type="hidden" name="acao" value="revogar"><input type="hidden" name="id" value="<?= (int)$t['CODTOKEN'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit"><?= P::e(P::L('Revogar', 'Revoke', 'Revocar')) ?></button></form><?php endif; ?></td></tr>
<?php endforeach; if (!$lista): ?><tr><td colspan="9" class="text-muted"><?= P::e(P::L('Nenhum token criado.', 'No token created.', 'Ningún token creado.')) ?></td></tr><?php endif; ?></tbody></table></div>
<p class="small text-muted mt-2"><?= P::e(P::L('Endereço da API', 'API address', 'Dirección de la API')) ?>: <span class="font-monospace"><?= P::e($base) ?>?recurso=requisitos|licencas|fornecedores&amp;pagina=1&amp;por_pagina=200&amp;unidade=&lt;código&gt;</span></p>
<?php P::rodape(); ?>
