<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/AdministracaoTextos.php';
session_start();
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/ModuloEmpresaService.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';

if (empty($_SESSION['autenticado'])) { http_response_code(401); exit(adm_t('Sessão expirada.')); }
if (strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? '')) !== 'ADMINISTRADOR') { http_response_code(403); exit(adm_t('Acesso restrito ao perfil ADMINISTRADOR.')); }
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
// Todas as empresas cadastradas (antes só ATLAS, MODELO e MODELO2: as demais, como CROWN e Goodyear, ficavam sem como ligar os módulos)
$empresas = $pdo->query("SELECT codempresa,codigo,nome,banco_dados,status FROM empresa ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$modulos = $pdo->query('SELECT codigo_modulo,nome_pt,nome_en,nome_es,ativo FROM modulos_sistema ORDER BY ordem,codigo_modulo')->fetchAll(PDO::FETCH_ASSOC);
$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $empresa = (int)($_POST['codempresa'] ?? 0);
        $modulo = strtoupper(trim((string)($_POST['codigo_modulo'] ?? '')));
        $ativo = !empty($_POST['ativo']) ? 1 : 0;
        $validEmpresas = array_map(static fn(array $x): int => (int)$x['codempresa'], $empresas);
        $validModulos = array_map(static fn(array $x): string => strtoupper((string)$x['codigo_modulo']), $modulos);
        if (!in_array($empresa, $validEmpresas, true) || !in_array($modulo, $validModulos, true)) throw new RuntimeException(adm_t('Empresa ou módulo inválido.'));
        $s = $pdo->prepare('UPDATE empresa_modulos SET ativo=:ativo,atualizado_por=:usuario WHERE codempresa=:empresa AND codigo_modulo=:modulo');
        $s->execute(['ativo'=>$ativo,'usuario'=>(int)($_SESSION['usuario']['codusuario'] ?? 0),'empresa'=>$empresa,'modulo'=>$modulo]);
        if ($s->rowCount() === 0) { $i=$pdo->prepare('INSERT INTO empresa_modulos (codempresa,codigo_modulo,ativo,atualizado_por) VALUES (?,?,?,?)'); $i->execute([$empresa,$modulo,$ativo,(int)($_SESSION['usuario']['codusuario'] ?? 0)]); }
        header('Location: seguranca_modulos_empresa.php?ok=1'); exit;
    } catch (Throwable $e) { $erro = $e->getMessage(); }
}
$estado = [];
$q = $pdo->query('SELECT codempresa,codigo_modulo,ativo FROM empresa_modulos');
foreach ($q as $r) $estado[(int)$r['codempresa']][strtoupper((string)$r['codigo_modulo'])] = (int)$r['ativo'] === 1;
$idioma = IdiomaService::idioma();
require_once dirname(__DIR__) . '/components/pagina_padrao.php';
$label = static function(array $m, string $idioma): string { return (string)($m['nome_' . $idioma] ?? $m['nome_pt']); };
?><!doctype html><html lang="<?= htmlspecialchars($idioma === 'en' ? 'en' : ($idioma === 'es' ? 'es' : 'pt-BR'), ENT_QUOTES, 'UTF-8') ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= adm_h('Módulos por empresa') ?></title><link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2"><link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2"><?php greenPortalEstiloPagina(); ?><style>.page{padding:12px 16px}.table th{white-space:nowrap}.module-name{font-weight:600}.hint{color:var(--gp-muted)}</style></head><body><main class="page"><div class="d-flex justify-content-between align-items-center mb-3"><div><h2 class="mb-1"><i class="bi bi-grid-3x3-gap me-2"></i><?= adm_h('Módulos por empresa') ?></h2><div class="hint"><?= adm_h('Controle comercial dos módulos. Administração, Segurança e Configurações continuam sujeitas às permissões do usuário.') ?></div></div><span class="badge text-bg-dark">ADMINISTRADOR</span></div><?php if (isset($_GET['ok'])): ?><div class="alert alert-success"><?= adm_h('Configuração atualizada.') ?></div><?php endif; ?><?php if ($erro !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?><div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th><?= adm_h('Empresa') ?></th><?php foreach ($modulos as $m): ?><th class="text-center"><?= htmlspecialchars($label($m,$idioma), ENT_QUOTES, 'UTF-8') ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($empresas as $empresa): ?><tr><td><strong><?= htmlspecialchars((string)$empresa['nome'], ENT_QUOTES, 'UTF-8') ?></strong><div class="small text-muted"><?= htmlspecialchars((string)$empresa['codigo'], ENT_QUOTES, 'UTF-8') ?></div></td><?php foreach ($modulos as $m): $code=strtoupper((string)$m['codigo_modulo']); $enabled=!empty($estado[(int)$empresa['codempresa']][$code]); ?><td class="text-center"><form method="post" class="d-inline"><input type="hidden" name="codempresa" value="<?= (int)$empresa['codempresa'] ?>"><input type="hidden" name="codigo_modulo" value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="ativo" value="0"><div class="form-check form-switch d-flex justify-content-center"><input class="form-check-input" type="checkbox" name="ativo" value="1" <?= $enabled?'checked':'' ?> onchange="this.form.submit()" aria-label="<?= htmlspecialchars($label($m,$idioma), ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars((string)$empresa['nome'], ENT_QUOTES, 'UTF-8') ?>"></div></form></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div></div><div class="alert alert-info mt-3 mb-0"><i class="bi bi-info-circle me-1"></i><?= adm_h('O perfil') ?> <strong>ADMINISTRADOR</strong> <?= adm_h('mantém acesso a todos os módulos. A desativação impede o menu e o acesso direto para os demais perfis.') ?></div></main></body></html>
