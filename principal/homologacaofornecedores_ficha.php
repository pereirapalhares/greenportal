<?php
declare(strict_types=1);

/*
 * Homologação de Fornecedores › Ficha do fornecedor (c455, 19/10/2026).
 * Página própria, aberta em popup do portal (iframe) a partir do Painel de Homologação (e de qualquer tela que passe
 * ?CODFORNECEDOR=N). Antes era um modal do painel que buscava JSON (?acao=ficha) e desenhava a ficha no navegador.
 * Mesmos dados e regras do painel: CALL sp_fo_painel_itens (migração 298, regra única de cores), helpers de
 * classes/HomologacaoPainelConsultas.php. Permissão: HOMOLOGACAO_PAINEL (visualizar); fornecedor externo só os seus.
 * Desempenho: cada leitura pede só o que mostra (fornecedor: 1 linha; histórico: 6; antes 1.000 por chamada via MigracaoCrud),
 * sem a checagem de procedures do painel; a migração 313 cria os índices por fornecedor. O tempo de cada etapa vai no
 * cabeçalho Server-Timing (F12 › Rede) e, com ?diag=1, no rodapé.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
require_once dirname(__DIR__) . '/classes/HomologacaoFornecedorAcesso.php';

const PAINEL_TRANSACAO = 'homologacaofornecedores_painel';
$inicio = microtime(true);
$tempos = [];
$marcar = static function (string $etapa) use (&$tempos, &$inicio): void { $agora = microtime(true); $tempos[$etapa] = round(($agora - $inicio) * 1000, 1); $inicio = $agora; };

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: text/html; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$t = static fn(string $chave, array $padrao): string => IdiomaService::t(PAINEL_TRANSACAO . '.' . $chave, $padrao);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$falhar = static function (int $codigo, string $mensagem) use ($e): never {
    http_response_code($codigo);
    echo '<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2"></head><body class="p-3"><div class="alert alert-warning">' . $e($mensagem) . '</div></body></html>';
    exit;
};
try {
    $pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
    if (!UsuarioSessao::atualizar($pdo, $_SESSION)) throw new RuntimeException('sessao');
} catch (Throwable) {
    $falhar(401, IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.']));
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (empty(PermissaoTransacao::obter('HOMOLOGACAO_PAINEL')['pode_visualizar'])) $falhar(403, IdiomaService::t('comum.erro.sem_permissao', ['pt' => 'Seu perfil não tem permissão para acessar esta transação.', 'en' => 'Your profile is not allowed to access this transaction.', 'es' => 'Su perfil no tiene permiso para acceder a esta transacción.']));
$externo = HomologacaoFornecedorAcesso::externo();
$usuario = (int)($_SESSION['usuario']['codusuario'] ?? 0);
$idioma = in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$_SESSION['idioma'] : 'pt';
$diag = isset($_GET['diag']);
session_write_close(); // libera a sessão: o painel e outras abas não esperam a ficha
$marcar('sessao');

$cod = filter_var($_GET['CODFORNECEDOR'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$cod) $falhar(400, $t('erro.fornecedor', ['pt' => 'Fornecedor inválido.', 'en' => 'Invalid supplier.', 'es' => 'Proveedor inválido.']));
if (!HomologacaoFornecedorAcesso::pode($pdo, 'fornecedor', (int)$cod)) $falhar(403, HomologacaoFornecedorAcesso::mensagemNegado());
$marcar('acesso');

// Leitura por sp_crud_select com filtro exato e só as linhas que a ficha mostra.
$ler = static function (string $view, array $filtros, int $limite) use ($pdo): array {
    $f = [];
    foreach ($filtros as $k => $v) $f[$k] = ['value' => (string)$v, 'exact' => true];
    return GreenPortalDatabase::call($pdo, 'CALL sp_crud_select(:t, :f, NULL, :l, 0)', ['t' => $view, 'f' => json_encode($f, JSON_UNESCAPED_UNICODE), 'l' => $limite]);
};
$f = $ler('vw_homologacao_fornecedores_grid', ['CODFORNECEDOR' => $cod], 1)[0] ?? null;
if (!$f) $falhar(404, $t('erro.fornecedor_nao_encontrado', ['pt' => 'Fornecedor não encontrado.', 'en' => 'Supplier not found.', 'es' => 'Proveedor no encontrado.']));
$marcar('fornecedor');

// Itens acompanhados (mesma procedure e mesmas cores do painel).
$s = $pdo->prepare('CALL sp_fo_painel_itens(?, ?, NULL, NULL, \'\', \'\', \'\', ?)');
$s->execute([$usuario, HomologacaoFornecedorAcesso::modoBanco($pdo), $cod]); // 0 tudo, 1 externo, 2 interno restrito (316)
$lista = $s->fetchAll(PDO::FETCH_ASSOC);
while ($s->nextRowset()) { }
$s->closeCursor();
$marcar('itens');

require dirname(__DIR__) . '/classes/HomologacaoPainelConsultas.php'; // $ordenar, $linhaSaida, $data (só funções, sem consulta)
$ordenar($lista);
$porCat = [];
foreach ($lista as $i) $porCat[$i['CATEGORIA']][] = $linhaSaida($i);
$docs = array_values(array_filter($lista, static fn($i) => $i['CATEGORIA'] === 'DOCUMENTO'));
$cores = ['VERMELHO' => 0, 'AMARELO' => 0, 'VERDE' => 0, 'NEUTRO' => 0, 'BRANCO' => 0];
foreach ($docs as $i) $cores[$i['COR']] = ($cores[$i['COR']] ?? 0) + 1;
$base = count($docs) - $cores['NEUTRO'];
$atendidos = $base > 0 ? (int)round($cores['VERDE'] / $base * 100) : null;
$falta = array_map($linhaSaida, array_slice(array_values(array_filter($lista, static fn($i) => in_array($i['COR'], ['VERMELHO', 'AMARELO'], true))), 0, 8));

$historico = [];
try { $historico = $ler('vw_homologacao_fornecedor_documentos_historico', ['CODFORNECEDOR' => $cod], 6); } catch (Throwable) { }
$marcar('historico');
$usuariosForn = [];
try { $usuariosForn = array_values(array_unique(array_filter(array_map(static fn($u) => trim((string)($u['NOME'] ?? '')), $ler('vw_homologacao_fornecedor_usuarios', ['CODFORNECEDOR' => $cod], 200))))); } catch (Throwable) { }
$marcar('usuarios');

$ativo = strcasecmp((string)($f['ATIVADOFOR'] ?? ''), 'Sim') === 0;
$nome = (string)($f['RAZAOSOCIAL'] ?? '');
$tipos = array_values(array_filter(array_map('trim', explode(',', (string)($f['TIPOFORNECEDOR'] ?? '')))));
$ranking = !$externo && (string)($f['RANKING_FAIXA'] ?? '') !== '' ? ['faixa' => (string)$f['RANKING_FAIXA'], 'nota' => (string)($f['RANKING_NOTA'] ?? '')] : null;
$proxima = $data($f['DATA_DA_PROXIMA_AVALIACAO'] ?? '');
$pend = $cores['VERMELHO'] + $cores['AMARELO'];
$nomesCat = [
    'DOCUMENTO' => $t('ficha.cat.documento', ['pt' => 'Documentos', 'en' => 'Documents', 'es' => 'Documentos']),
    'INTEGRACAO' => $t('ficha.cat.integracao', ['pt' => 'Integração', 'en' => 'Onboarding', 'es' => 'Integración']),
    'DOCFUNC' => $t('ficha.cat.docfunc', ['pt' => 'Documentos de funcionário', 'en' => 'Employee documents', 'es' => 'Documentos de empleado']),
    'TREINAMENTO' => $t('ficha.cat.treinamento', ['pt' => 'Treinamentos', 'en' => 'Training', 'es' => 'Capacitaciones']),
    'AUDITORIA' => $t('ficha.cat.auditoria', ['pt' => 'Auditoria', 'en' => 'Audit', 'es' => 'Auditoría']),
    'NIVEL' => $t('ficha.cat.nivel', ['pt' => 'Nível de serviço', 'en' => 'Service level', 'es' => 'Nivel de servicio']),
    'EQUIPAMENTO' => $t('ficha.cat.equipamento', ['pt' => 'Equipamentos', 'en' => 'Equipment', 'es' => 'Equipos']),
    'QUIMICO' => $t('ficha.cat.quimico', ['pt' => 'Produtos químicos', 'en' => 'Chemicals', 'es' => 'Productos químicos']),
    'PLANO' => $t('ficha.cat.plano', ['pt' => 'Plano de ação', 'en' => 'Action plan', 'es' => 'Plan de acción']),
];
$corTexto = [
    'VERMELHO' => $t('cor.vermelho', ['pt' => 'Vermelho', 'en' => 'Red', 'es' => 'Rojo']), 'AMARELO' => $t('cor.amarelo', ['pt' => 'Amarelo', 'en' => 'Yellow', 'es' => 'Amarillo']),
    'VERDE' => $t('cor.verde', ['pt' => 'Verde', 'en' => 'Green', 'es' => 'Verde']), 'NEUTRO' => $t('cor.neutro', ['pt' => 'Cinza', 'en' => 'Gray', 'es' => 'Gris']), 'BRANCO' => $t('cor.branco', ['pt' => 'Branco', 'en' => 'White', 'es' => 'Blanco']),
];
$cats = array_values(array_filter(array_keys($nomesCat), static fn($k) => !empty($porCat[$k])));
$bola = static fn(string $cor): string => '<i class="painel-bola cor-' . htmlspecialchars($cor, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"></i>';
$quando = static fn(string $v): string => preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}:\d{2}))?/', $v, $m) ? ($idioma === 'en' ? "$m[2]/$m[3]/$m[1]" : "$m[3]/$m[2]/$m[1]") . (isset($m[4]) ? ' ' . $m[4] : '') : $v;
$marcar('montagem');
header('Server-Timing: ' . implode(', ', array_map(static fn($k, $v) => $k . ';dur=' . $v, array_keys($tempos), $tempos)));
?><!doctype html>
<html lang="<?= $e($idioma === 'pt' ? 'pt-BR' : $idioma) ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($t('ficha.titulo', ['pt' => 'Ficha do fornecedor', 'en' => 'Supplier profile', 'es' => 'Ficha del proveedor']) . ' - ' . $nome) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2">
<link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2">
<link rel="stylesheet" href="../assets/css/app.css?v=5">
<link rel="stylesheet" href="../assets/css/homologacao-painel.css?v=2">
<?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?>
<style>
body.ficha-pagina{margin:0;background:var(--gp-surface,#f4f8f6)}
.ficha{max-width:1440px;margin:0 auto;padding:14px 18px 28px;display:flex;flex-direction:column;gap:14px;font-size:14px}
.ficha-pane[hidden]{display:none}
.ficha-diag{font-size:11px;color:#5b6f66}
@media print{body.ficha-pagina{background:#fff}.ficha-acoes,.ficha-abas{display:none!important}.ficha-pane[hidden]{display:block!important}.ficha-pane{break-inside:avoid;margin-bottom:12px}.ficha-pane-titulo{display:block!important}.painel-tabela-caixa{overflow:visible}.painel-tabela{min-width:0}.painel-bola,.ficha-caixa{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
.ficha-pane-titulo{display:none;margin:4px 0 6px;font-size:14px;font-weight:700}
</style>
</head>
<body class="ficha-pagina" data-transacao="homologacaofornecedores_ficha">
<?php greenPortalCabecalhoImpressao('Ficha do fornecedor'); ?>
<main class="ficha" data-ficha data-codfornecedor="<?= (int)$cod ?>" data-nome="<?= $e($nome) ?>">
  <div class="ficha-cab">
    <div class="ficha-info">
      <div class="painel-mudo painel-pequeno"><?= $e(implode(' · ', array_filter([(string)($f['CNPJ_CPF'] ?? '') !== '' ? 'CNPJ/CPF ' . $f['CNPJ_CPF'] : '', (string)($f['DSCUNIDADE'] ?? '') !== '' ? $t('ficha.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']) . ' ' . $f['DSCUNIDADE'] : '']))) ?></div>
      <h1 class="ficha-nome"><?= $e($nome) ?></h1>
      <?php if ((string)($f['NOMEFANTASIA'] ?? '') !== '' && $f['NOMEFANTASIA'] !== $nome): ?><div class="painel-mudo"><?= $e($f['NOMEFANTASIA']) ?></div><?php endif; ?>
      <div class="ficha-chips">
        <?php foreach ($tipos as $tp): ?><span class="ficha-chip"><?= $e($tp) ?></span><?php endforeach; ?>
        <?php if (strcasecmp((string)($f['TRABALHA_INSTALACAO_FORNECEDOR'] ?? ''), 'Sim') === 0): ?><span class="ficha-chip"><?= $e($t('ficha.instalacoes', ['pt' => 'Trabalha nas instalações', 'en' => 'Works on site', 'es' => 'Trabaja en las instalaciones'])) ?></span><?php endif; ?>
        <?php if (!$ativo): ?><span class="ficha-chip ficha-chip-alerta"><?= $e($t('ficha.desativado', ['pt' => 'Desativado', 'en' => 'Deactivated', 'es' => 'Desactivado'])) ?></span><?php endif; ?>
      </div>
    </div>
    <div class="ficha-caixas">
      <div class="ficha-caixa <?= $cores['VERMELHO'] ? 'ficha-caixa-vermelha' : ($cores['AMARELO'] ? 'ficha-caixa-amarela' : 'ficha-caixa-verde') ?>">
        <span class="ficha-caixa-rotulo"><?= $e($t('ficha.situacao_geral', ['pt' => 'Situação geral', 'en' => 'Overall status', 'es' => 'Situación general'])) ?></span>
        <span class="ficha-caixa-valor"><?= $e($pend ? $t('ficha.com_pendencias', ['pt' => 'Com pendências', 'en' => 'With pending items', 'es' => 'Con pendientes']) : $t('ficha.em_dia', ['pt' => 'Em dia', 'en' => 'Up to date', 'es' => 'Al día'])) ?></span>
        <span class="ficha-caixa-sub"><?= (int)$cores['VERMELHO'] ?> <?= $e(mb_strtolower($corTexto['VERMELHO'])) ?> · <?= (int)$cores['AMARELO'] ?> <?= $e(mb_strtolower($corTexto['AMARELO'])) ?></span>
      </div>
      <?php if ($ranking): ?><div class="ficha-caixa"><span class="ficha-caixa-rotulo">Ranking</span><span class="ficha-caixa-valor"><?= $e($t('ficha.faixa', ['pt' => 'Faixa', 'en' => 'Band', 'es' => 'Franja']) . ' ' . $ranking['faixa']) ?></span><span class="ficha-caixa-sub"><?= $ranking['nota'] !== '' ? $e($t('ficha.nota', ['pt' => 'nota', 'en' => 'score', 'es' => 'nota']) . ' ' . $ranking['nota']) : '' ?></span></div><?php endif; ?>
      <div class="ficha-caixa"><span class="ficha-caixa-rotulo"><?= $e($t('ficha.atendidos', ['pt' => 'Documentos atendidos', 'en' => 'Documents met', 'es' => 'Documentos atendidos'])) ?></span><span class="ficha-caixa-valor"><?= $atendidos === null ? '—' : $atendidos . '%' ?></span><span class="ficha-caixa-sub"><?= (int)$cores['VERDE'] ?> <?= $e($t('ficha.de', ['pt' => 'de', 'en' => 'of', 'es' => 'de'])) ?> <?= (int)$base ?></span></div>
    </div>
  </div>

  <div class="painel-acoes ficha-acoes">
    <button type="button" class="btn painel-btn painel-btn-primario" data-ficha-documentos><i class="bi bi-folder2-open" aria-hidden="true"></i><?= $e($t('ficha.abrir_documentos', ['pt' => 'Abrir documentos', 'en' => 'Open documents', 'es' => 'Abrir documentos'])) ?></button>
    <button type="button" class="btn painel-btn" data-ficha-imprimir><i class="bi bi-printer" aria-hidden="true"></i><?= $e($t('ficha.imprimir', ['pt' => 'Imprimir ficha (PDF)', 'en' => 'Print profile (PDF)', 'es' => 'Imprimir ficha (PDF)'])) ?></button>
    <button type="button" class="btn painel-btn" data-ficha-recarregar title="<?= $e(IdiomaService::t('comum.botao.recarregar', ['pt' => 'Recarregar', 'en' => 'Reload', 'es' => 'Recargar'])) ?>" aria-label="<?= $e(IdiomaService::t('comum.botao.recarregar', ['pt' => 'Recarregar', 'en' => 'Reload', 'es' => 'Recargar'])) ?>"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i></button>
  </div>

  <div class="ficha-corpo">
    <section class="ficha-principal">
      <?php if ($cats): ?>
      <div class="ficha-abas" role="tablist">
        <?php foreach ($cats as $n => $k): ?><button type="button" class="ficha-aba<?= $n === 0 ? ' ativo' : '' ?>" role="tab" id="aba-<?= $e($k) ?>" aria-controls="pane-<?= $e($k) ?>" aria-selected="<?= $n === 0 ? 'true' : 'false' ?>" data-aba="<?= $e($k) ?>"><?= $e($nomesCat[$k]) ?> <span class="painel-mudo"><?= count($porCat[$k]) ?></span></button><?php endforeach; ?>
      </div>
      <?php foreach ($cats as $n => $k): ?>
      <div class="ficha-pane" id="pane-<?= $e($k) ?>" role="tabpanel" aria-labelledby="aba-<?= $e($k) ?>"<?= $n === 0 ? '' : ' hidden' ?>>
        <h2 class="ficha-pane-titulo"><?= $e($nomesCat[$k]) ?></h2>
        <div class="painel-tabela-caixa"><table class="painel-tabela"><thead><tr>
          <?php foreach ([$t('ficha.col.item', ['pt' => 'Item', 'en' => 'Item', 'es' => 'Ítem']), $t('ficha.col.situacao', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación']), $t('ficha.col.validade', ['pt' => 'Validade', 'en' => 'Expiry', 'es' => 'Vencimiento']), $t('ficha.col.dias', ['pt' => 'Dias', 'en' => 'Days', 'es' => 'Días']), $t('ficha.col.responsavel', ['pt' => 'Responsável', 'en' => 'Owner', 'es' => 'Responsable'])] as $h): ?><th scope="col"><?= $e($h) ?></th><?php endforeach; ?>
        </tr></thead><tbody>
          <?php foreach ($porCat[$k] as $r): ?><tr class="painel-linha linha-<?= $e($r['cor']) ?>" title="<?= $e($corTexto[$r['cor']] ?? $r['cor']) ?>"><td class="painel-forte"><span class="visually-hidden"><?= $e(($corTexto[$r['cor']] ?? $r['cor']) . ': ') ?></span><?= $e($r['item']) ?></td><td><?= $e($r['situacao']) ?></td><td class="painel-nowrap"><?= $e($r['validade']) ?></td><td class="painel-forte<?= $r['dias'] !== '' && (int)$r['dias'] < 0 ? ' painel-negativo' : '' ?>"><?= $e($r['dias']) ?></td><td><?= $e($r['responsavel']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
      <div class="painel-mudo"><?= $e($ativo ? $t('ficha.sem_itens', ['pt' => 'Nenhum item acompanhado para este fornecedor.', 'en' => 'No tracked items for this supplier.', 'es' => 'Ningún ítem acompañado para este proveedor.']) : $t('ficha.desativado_sem_itens', ['pt' => 'Fornecedor desativado: o painel acompanha só fornecedores ativos.', 'en' => 'Deactivated supplier: the dashboard tracks active suppliers only.', 'es' => 'Proveedor desactivado: el panel acompaña solo proveedores activos.'])) ?></div>
      <?php endif; ?>
    </section>

    <aside class="ficha-lado">
      <section class="painel-cartao">
        <h2 class="h6 m-0"><?= $e($t('ficha.falta', ['pt' => 'O que falta', 'en' => 'What is missing', 'es' => 'Qué falta'])) ?></h2>
        <?php if (!$falta): ?><div class="painel-mudo"><?= $e($t('ficha.nada_falta', ['pt' => 'Nada vermelho ou amarelo.', 'en' => 'Nothing red or yellow.', 'es' => 'Nada rojo o amarillo.'])) ?></div>
        <?php else: ?><ul class="ficha-falta"><?php foreach ($falta as $r): ?><li><?= $bola($r['cor']) ?><span><?= $e($r['item'] . ' — ' . $r['situacao'] . ($r['validade'] !== '' ? ' (' . $r['validade'] . ')' : '')) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
      </section>
      <section class="painel-cartao">
        <h2 class="h6 m-0"><?= $e($t('ficha.responsaveis', ['pt' => 'Responsáveis', 'en' => 'Owners', 'es' => 'Responsables'])) ?></h2>
        <?php foreach ([
            [$t('ficha.resp_fornecedor', ['pt' => 'Pelo fornecedor (empresa)', 'en' => 'For the supplier (company)', 'es' => 'Por el proveedor (empresa)']), (string)($f['RESPONSAVEL_FORNECEDOR'] ?? '')],
            [$t('ficha.resp_integracao', ['pt' => 'Integração', 'en' => 'Onboarding', 'es' => 'Integración']), (string)($f['RESPONSAVEL_INTEGRACAO'] ?? '')],
            [$t('ficha.usuarios', ['pt' => 'Usuários do fornecedor', 'en' => 'Supplier users', 'es' => 'Usuarios del proveedor']), implode(', ', $usuariosForn)],
            [$t('ficha.nivel', ['pt' => 'Nível de serviço', 'en' => 'Service level', 'es' => 'Nivel de servicio']), trim((string)($f['NIVEL_DE_SERVICO'] ?? '') . ($proxima !== '' ? ' · ' . $t('ficha.proxima', ['pt' => 'próxima avaliação', 'en' => 'next evaluation', 'es' => 'próxima evaluación']) . ' ' . $proxima : ''), ' ·')],
        ] as [$rot, $val]): if (trim($val) === '') continue; ?><div class="ficha-resp"><span class="painel-mudo"><?= $e($rot) ?>: </span><?= $e($val) ?></div><?php endforeach; ?>
      </section>
      <section class="painel-cartao">
        <h2 class="h6 m-0"><?= $e($t('ficha.historico', ['pt' => 'Últimas alterações nos documentos', 'en' => 'Latest document changes', 'es' => 'Últimos cambios en los documentos'])) ?></h2>
        <?php if (!$historico): ?><div class="painel-mudo"><?= $e($t('ficha.sem_historico', ['pt' => 'Sem alterações registradas.', 'en' => 'No changes recorded.', 'es' => 'Sin cambios registrados.'])) ?></div><?php endif; ?>
        <?php foreach ($historico as $h): ?><div class="ficha-hist"><strong><?= $e($quando((string)($h['DATAALTERACAO'] ?? ''))) ?></strong> · <?= $e(((string)($h['ALTERADO_POR'] ?? '') ?: '—') . ': ' . (string)($h['DSCRITERIO'] ?? '') . ((string)($h['SITUACAO'] ?? '') !== '' ? ' → ' . $h['SITUACAO'] : '')) ?></div><?php endforeach; ?>
      </section>
    </aside>
  </div>
  <?php if ($diag): ?><div class="ficha-diag">Tempos (ms): <?= $e(implode(' · ', array_map(static fn($k, $v) => "$k $v", array_keys($tempos), $tempos))) ?> · <?= count($lista) ?> itens</div><?php endif; ?>
</main>
<script>
(function () {
  'use strict';
  var raiz = document.querySelector('[data-ficha]');
  if (!raiz) return;
  // Abas por categoria (já vêm prontas do servidor; só alterna).
  raiz.querySelectorAll('[data-aba]').forEach(function (b) {
    b.addEventListener('click', function () {
      raiz.querySelectorAll('[data-aba]').forEach(function (o) { var on = o === b; o.classList.toggle('ativo', on); o.setAttribute('aria-selected', on ? 'true' : 'false'); });
      raiz.querySelectorAll('.ficha-pane').forEach(function (p) { p.hidden = p.id !== 'pane-' + b.getAttribute('data-aba'); });
    });
  });
  // Documentos do fornecedor em outro popup do portal (por cima da ficha).
  raiz.querySelector('[data-ficha-documentos]').addEventListener('click', function () {
    var url = new URL('homologacaofornecedor_fornecedorxcriterios.php?CODFORNECEDOR=' + encodeURIComponent(raiz.getAttribute('data-codfornecedor')), window.location.href).toString();
    var titulo = <?= json_encode($t('ficha.documentos_de', ['pt' => 'Documentos', 'en' => 'Documents', 'es' => 'Documentos']), JSON_UNESCAPED_UNICODE) ?> + ' - ' + raiz.getAttribute('data-nome');
    try {
      var host = window.top || window;
      if (host.GreenPortalPopups && host.jQuery) {
        var frame = host.jQuery('<iframe>', { src: url, title: titulo, style: 'width:100%;height:100%;border:0;display:block;' });
        var op = { titulo: titulo, conteudo: frame, amplo: true, redimensionavel: true };
        if (typeof host.GreenPortalPopups.transacaoIndependente === 'function') host.GreenPortalPopups.transacaoIndependente(op); else host.GreenPortalPopups.transacao(op);
        return;
      }
    } catch (e) { }
    window.open(url, '_blank');
  });
  raiz.querySelector('[data-ficha-imprimir]').addEventListener('click', function () { window.print(); });
  raiz.querySelector('[data-ficha-recarregar]').addEventListener('click', function () { window.location.reload(); });
}());
</script>
<script src="../assets/js/ajuda-transacao.js?v=1"></script>
</body>
</html>
