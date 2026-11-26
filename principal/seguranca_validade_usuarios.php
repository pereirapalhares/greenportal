<?php
declare(strict_types=1);

/*
 * Segurança › Validade dos usuários (Revisão G item 9; migração 421, 26/11/2026).
 * Monitora se cada usuário ativo continua na empresa: sem acesso há mais de N dias (padrão 90) e, se ligada, a confirmação periódica
 * (a cada N dias). O gestor confirma ("continua na empresa") ou o ADMINISTRADOR / ADM DA EMPRESA inativa. O ADMINISTRADOR e o
 * ADM DA EMPRESA veem todos e recebem a pendência "Usuários a revisar"; os demais veem a própria equipe (subordinados).
 * Permissão: menu VALIDADE_USUARIOS (visualizar; editar = confirmar e configurar). Regras em classes/UsuarioValidade.php.
 * Leitura por sp_crud_select; gravação por sp_crud_insert / sp_crud_update (usuarios.ativo = 0 ao inativar).
 */
require_once dirname(__DIR__) . '/classes/PaginaSimples.php';
require_once dirname(__DIR__) . '/classes/UsuarioValidade.php';
require_once dirname(__DIR__) . '/classes/PainelLateralService.php';
use PaginaSimples as P;

P::abrir('VALIDADE_USUARIOS');
if (!P::existe('usuario_validade')) P::sair(503, P::L('Validade dos usuários ainda não instalada nesta empresa (migração 421).', 'User validity is not installed yet (migration 421).', 'Validez de usuarios aún no instalada (migración 421).'));
$csrf = P::csrf('validade_usuarios_csrf');
$perfil = strtoupper((string)preg_replace('/[\s_-]+/', ' ', (string)($_SESSION['usuario']['codigo_perfil'] ?? '')));
$adm = in_array($perfil, ['ADMINISTRADOR', 'ADM DA EMPRESA', 'ADM EMPRESA'], true);
$equipe = $adm ? null : array_keys((new PainelLateralService(P::$pdo, P::$uid))->equipe());
$cfg = UsuarioValidade::config(P::$pdo);
$msg = ''; $erros = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    P::conferirCsrf('validade_usuarios_csrf');
    $acao = (string)($_POST['acao'] ?? '');
    if ($acao === 'config') {
        if (!$adm || !P::pode('editar')) P::sair(403, P::L('Só o ADM da empresa muda as regras.', 'Only the company admin changes the rules.', 'Solo el ADM de la empresa cambia las reglas.'));
        foreach (['USUARIO_DIAS_SEM_ACESSO' => [15, 730], 'USUARIO_REVALIDAR_DIAS' => [0, 730]] as $k => [$min, $max]) {
            $v = max($min, min($max, (int)($_POST[$k] ?? $cfg[$k])));
            if (P::sel('portal_config', ['CHAVE' => $k], 1)) P::upd('portal_config', ['CHAVE' => $k], ['VALOR' => (string)$v, 'CODUSUARIO' => P::$uid ?: null]);
            else P::ins('portal_config', ['CHAVE' => $k, 'VALOR' => (string)$v, 'CODUSUARIO' => P::$uid ?: null]);
        }
        $cfg = UsuarioValidade::config(P::$pdo);
        $msg = P::L('Regras gravadas.', 'Rules saved.', 'Reglas guardadas.');
    } elseif (in_array($acao, ['confirmar', 'inativar'], true)) {
        if (!P::pode('editar')) P::sair(403, P::L('Seu perfil só consulta.', 'Your profile can only view.', 'Su perfil solo consulta.'));
        if ($acao === 'inativar' && !$adm) P::sair(403, P::L('Só o ADM da empresa inativa usuários.', 'Only the company admin deactivates users.', 'Solo el ADM de la empresa inactiva usuarios.'));
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
        $obs = mb_substr(trim((string)($_POST['obs'] ?? '')), 0, 500) ?: null;
        $n = 0;
        foreach ($ids as $id) {
            if ($id === P::$uid && $acao === 'inativar') { $erros[] = P::L('Você não pode inativar o próprio usuário.', 'You cannot deactivate yourself.', 'No puede inactivarse a sí mismo.'); continue; }
            if ($equipe !== null && !in_array($id, $equipe, true)) continue;
            if ($acao === 'inativar') P::upd('usuarios', ['codusuario' => $id], ['ativo' => 0]);
            P::ins('usuario_validade', ['CODUSUARIO' => $id, 'SITUACAO' => $acao === 'inativar' ? 'INATIVADO' : 'CONFIRMADO', 'OBS' => $obs, 'CODUSUARIO_GESTOR' => P::$uid ?: null]);
            $n++;
        }
        AuditService::record(P::$pdo, 'seguranca_validade_usuarios', strtoupper($acao), 'SUCESSO', 'usuario_validade', null, ['usuarios' => $ids, 'qtd' => $n]);
        $msg = sprintf($acao === 'inativar' ? P::L('%d usuário(s) inativado(s).', '%d user(s) deactivated.', '%d usuario(s) inactivado(s).') : P::L('%d usuário(s) confirmado(s) como válido(s).', '%d user(s) confirmed as valid.', '%d usuario(s) confirmado(s).'), $n);
    }
}
$usuarios = UsuarioValidade::lista(P::$pdo, $cfg);
if ($equipe !== null) $usuarios = array_values(array_filter($usuarios, static fn($u) => in_array((int)$u['CODUSUARIO'], $equipe, true)));
$mostrar = (string)($_GET['mostrar'] ?? 'revisar');
$revisar = array_filter($usuarios, static fn($u) => $u['MOTIVO'] !== null);
$lista = $mostrar === 'todos' ? array_filter($usuarios, static fn($u) => (int)$u['ATIVO'] === 1) : $revisar;
usort($lista, static fn($a, $b) => [-(int)($a['DIAS_SEM_ACESSO'] ?? 99999), (string)$a['NOME']] <=> [-(int)($b['DIAS_SEM_ACESSO'] ?? 99999), (string)$b['NOME']]);
$nomes = [];
foreach ($usuarios as $u) $nomes[(int)$u['CODUSUARIO']] = (string)$u['NOME'];
session_write_close();
$fmt = static fn(?string $d): string => $d && ($t = strtotime($d)) ? date(P::$idioma === 'en' ? 'm/d/Y' : 'd/m/Y', $t) : '—';
$rotulo = static function (?array $m): string {
    if ($m === null) return '<span class="badge text-bg-success">' . P::e(P::L('Em dia', 'Up to date', 'Al día')) . '</span>';
    return match ($m['tipo']) {
        'SEM_ACESSO' => '<span class="badge text-bg-danger">' . P::e($m['dias'] === null ? P::L('Nunca entrou', 'Never logged in', 'Nunca ingresó') : sprintf(P::L('Sem acesso há %d dias', 'No access for %d days', 'Sin acceso hace %d días'), $m['dias'])) . '</span>',
        'NUNCA_CONFIRMADO' => '<span class="badge text-bg-warning">' . P::e(P::L('Nunca confirmado', 'Never confirmed', 'Nunca confirmado')) . '</span>',
        default => '<span class="badge text-bg-warning">' . P::e(sprintf(P::L('Confirmação vencida (%d dias)', 'Confirmation expired (%d days)', 'Confirmación vencida (%d días)'), (int)$m['dias'])) . '</span>',
    };
};
P::cabecalho(P::L('Validade dos usuários', 'User validity', 'Validez de los usuarios'), 'bi-person-check', 'seguranca_validade_usuarios');
?>
<p class="ps-ajuda small mb-2"><?= P::e(P::L('Garante que a lista de usuários ativos está atualizada: quem não entra há muito tempo (ou não teve a confirmação periódica) aparece aqui. Confirme quem continua na empresa ou inative quem saiu. Nada é enviado por e-mail.', 'Keeps the active users list up to date: who has not logged in for a long time (or lacks the periodic confirmation) shows here. Confirm who is still in the company or deactivate who left. No e-mail is sent.', 'Mantiene actualizada la lista de usuarios activos: quien no ingresa hace mucho (o no tuvo la confirmación periódica) aparece aquí. Confirme o inactive. No se envía correo.')) ?></p>
<?php if ($msg !== ''): ?><div class="alert alert-success py-2"><?= P::e($msg) ?></div><?php endif; foreach ($erros as $er): ?><div class="alert alert-danger py-1 mb-1"><?= P::e($er) ?></div><?php endforeach; ?>
<?php if ($adm): ?><form method="post" class="card mb-2"><div class="card-body py-2 d-flex flex-wrap gap-3 align-items-end"><input type="hidden" name="csrf" value="<?= P::e($csrf) ?>"><input type="hidden" name="acao" value="config">
<label class="small"><?= P::e(P::L('Revisar quem não entra há mais de (dias)', 'Review who has not logged in for more than (days)', 'Revisar quien no ingresa hace más de (días)')) ?><input type="number" min="15" max="730" class="form-control form-control-sm" name="USUARIO_DIAS_SEM_ACESSO" value="<?= (int)$cfg['USUARIO_DIAS_SEM_ACESSO'] ?>"<?= P::pode('editar') ? '' : ' disabled' ?> data-sem-memoria></label>
<label class="small"><?= P::e(P::L('Confirmação periódica de todos a cada (dias; 0 = desligada)', 'Periodic confirmation of everyone every (days; 0 = off)', 'Confirmación periódica de todos cada (días; 0 = apagada)')) ?><input type="number" min="0" max="730" class="form-control form-control-sm" name="USUARIO_REVALIDAR_DIAS" value="<?= (int)$cfg['USUARIO_REVALIDAR_DIAS'] ?>"<?= P::pode('editar') ? '' : ' disabled' ?> data-sem-memoria></label>
<?php if (P::pode('editar')): ?><button class="btn btn-sm btn-outline-success" type="submit"><?= P::e(P::L('Gravar regras', 'Save rules', 'Guardar reglas')) ?></button><?php endif; ?></div></form><?php endif; ?>
<div class="mb-2 small"><a href="?mostrar=revisar" class="btn btn-sm <?= $mostrar !== 'todos' ? 'btn-primary' : 'btn-outline-primary' ?>"><?= P::e(P::L('A revisar', 'To review', 'A revisar')) ?> (<?= count($revisar) ?>)</a> <a href="?mostrar=todos" class="btn btn-sm <?= $mostrar === 'todos' ? 'btn-primary' : 'btn-outline-primary' ?>"><?= P::e(P::L('Todos os ativos', 'All active', 'Todos los activos')) ?></a></div>
<form method="post"><input type="hidden" name="csrf" value="<?= P::e($csrf) ?>">
<div class="table-responsive border rounded bg-white" style="max-height:60vh;overflow:auto"><table class="table table-sm mb-0"><thead class="table-light"><tr><th><input type="checkbox" class="form-check-input" onclick="document.querySelectorAll('[name=&quot;ids[]&quot;]').forEach(c=>c.checked=this.checked)"></th><th><?= P::e(P::L('Usuário', 'User', 'Usuario')) ?></th><th><?= P::e(P::L('Perfil', 'Profile', 'Perfil')) ?></th><th><?= P::e(P::L('Superior', 'Manager', 'Superior')) ?></th><th><?= P::e(P::L('Último acesso', 'Last access', 'Último acceso')) ?></th><th><?= P::e(P::L('Confirmado em', 'Confirmed on', 'Confirmado el')) ?></th><th><?= P::e(P::L('Situação', 'Status', 'Situación')) ?></th></tr></thead><tbody>
<?php foreach ($lista as $u): ?><tr><td><input type="checkbox" class="form-check-input" name="ids[]" value="<?= (int)$u['CODUSUARIO'] ?>"></td><td><?= P::e($u['NOME']) ?><div class="small text-muted"><?= P::e($u['USERID']) ?></div></td><td class="small"><?= P::e($u['PERFIL'] ?? '') ?></td>
<td class="small"><?= P::e($nomes[(int)($u['CODUSUARIO_SUPERIOR'] ?? 0)] ?? '') ?></td><td class="small"><?= P::e($fmt($u['ULTIMO_ACESSO'] ?? null)) ?></td><td class="small"><?= P::e($fmt($u['CONFIRMADO_EM'] ?? null)) ?><?= !empty($u['CONFIRMADO_POR']) ? '<div class="text-muted">' . P::e($nomes[(int)$u['CONFIRMADO_POR']] ?? '') . '</div>' : '' ?></td><td><?= $rotulo($u['MOTIVO']) ?></td></tr>
<?php endforeach; if (!$lista): ?><tr><td colspan="7" class="text-muted"><?= P::e(P::L('Nenhum usuário a revisar.', 'No user to review.', 'Ningún usuario a revisar.')) ?></td></tr><?php endif; ?></tbody></table></div>
<?php if (P::pode('editar') && $lista): ?><div class="d-flex flex-wrap gap-2 mt-2 align-items-center"><input class="form-control form-control-sm" style="max-width:360px" name="obs" maxlength="500" placeholder="<?= P::e(P::L('Observação (opcional)', 'Note (optional)', 'Observación (opcional)')) ?>" data-sem-memoria>
<button class="btn btn-sm btn-success" type="submit" name="acao" value="confirmar"><i class="bi bi-person-check me-1"></i><?= P::e(P::L('Confirmar: continuam na empresa', 'Confirm: still in the company', 'Confirmar: siguen en la empresa')) ?></button>
<?php if ($adm): ?><button class="btn btn-sm btn-outline-danger" type="submit" name="acao" value="inativar"><i class="bi bi-person-x me-1"></i><?= P::e(P::L('Inativar escolhidos', 'Deactivate selected', 'Inactivar elegidos')) ?></button><?php endif; ?></div><?php endif; ?></form>
<?php P::rodape(); ?>
