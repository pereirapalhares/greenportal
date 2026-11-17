<?php
declare(strict_types=1);
require_once __DIR__ . '/UsuarioSessao.php';
require_once __DIR__ . '/PermissaoTransacao.php';
require_once __DIR__ . '/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/components/botoes_crud.php';

final class AjudaTransacoesCrudGridV2
{
    public static function render(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (empty($_SESSION['autenticado'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
        $env = GreenPortalDatabase::env();
        $tenant = GreenPortalDatabase::connect($env, (string)$_SESSION['tenant']['banco_dados'], 'GPT');
        if (!UsuarioSessao::atualizar($tenant, $_SESSION)) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
        $permission = PermissaoTransacao::obter('CONFIG_AJUDA_TRANSACOES');
        if (empty($permission['pode_visualizar'])) { http_response_code(403); exit('Acesso negado.'); }
        $universal = GreenPortalDatabase::connect($env, $env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp', 'GUNIVERSAL');
        $search = trim((string)($_POST['busca'] ?? $_GET['busca'] ?? ''));
        $json = static fn(mixed $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'count_rows') {
            $count = GreenPortalDatabase::call($universal, 'CALL sp_crud_count(:tabela,:filtros,:busca)', ['tabela' => 'ajuda_transacoes', 'filtros' => $json(['ativo' => 1]), 'busca' => $search]);
            header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'total' => (int)($count[0]['total_registros'] ?? 0)]); exit;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'save' && !empty($permission['pode_editar'])) {
            foreach ((array)($_POST['rows'] ?? []) as $id => $row) {
                if (!ctype_digit((string)$id)) continue;
                GreenPortalDatabase::call($universal, 'CALL sp_crud_update(:tabela,:chave,:dados)', [
                    'tabela' => 'ajuda_transacoes',
                    'chave' => $json(['codajudatransacao' => (int)$id]),
                    'dados' => $json(['titulo_pt' => trim((string)($row['titulo_pt'] ?? '')), 'titulo_en' => trim((string)($row['titulo_en'] ?? '')), 'titulo_es' => trim((string)($row['titulo_es'] ?? '')), 'texto_pt' => self::clean((string)($row['texto_pt'] ?? '')), 'texto_en' => self::clean((string)($row['texto_en'] ?? '')), 'texto_es' => self::clean((string)($row['texto_es'] ?? ''))]),
                ]);
            }
            header('Location: ' . strtok((string)$_SERVER['REQUEST_URI'], '?') . '?salvo=1'); exit;
        }
        $page = max(1, (int)($_GET['pagina'] ?? 1)); $size = 25;
        $rows = GreenPortalDatabase::call($universal, 'CALL sp_crud_select(:tabela,:filtros,:busca,:limite,:offset)', ['tabela' => 'ajuda_transacoes', 'filtros' => $json(['ativo' => 1]), 'busca' => $search, 'limite' => $size, 'offset' => ($page - 1) * $size]);
        $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        ?>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet"><link href="../assets/css/app.css?v=5" rel="stylesheet"><link href="../assets/css/main.css?v=18" rel="stylesheet"><link href="../assets/css/design-system.css?v=3" rel="stylesheet">
<style>.ajuda-idioma-cell{min-width:280px;vertical-align:top}.ajuda-editable{min-height:76px;cursor:pointer;white-space:pre-wrap}.ajuda-editable:hover{outline:2px solid #b8d8c8;outline-offset:-2px}</style>
<section class="setup-fragment menu-grid-fragment grid-ready" data-grid-transaction="CONFIG_AJUDA_TRANSACOES">
<div class="crud-toolbar menu-grid-toolbar"><?php greenPortalCrudButtons(['form_id' => 'ajudaTransacoesGridForm', 'language' => (string)($_SESSION['idioma'] ?? 'pt'), 'permission' => $permission, 'permission_title' => 'Perfil sem permissão de acesso!', 'transaction' => 'CONFIG_AJUDA_TRANSACOES']); ?><div class="crud-search"><i class="bi bi-search"></i><input type="search" placeholder="Buscar no grid" aria-label="Buscar no grid"></div></div>
<?php if (isset($_GET['salvo'])): ?><div class="alert alert-success py-2">Ajuda salva com sucesso.</div><?php endif; ?>
<form method="post" id="ajudaTransacoesGridForm" class="setup-form menu-grid-form"><input type="hidden" name="action" value="save"><div class="menu-grid-scroll"><table class="table table-sm menu-batch-grid" id="ajudaTransacoesBatchGrid"><thead><tr>
<th data-column="menu"><div class="column-heading"><span>Menu</span><input class="column-filter" type="search" data-column-filter="menu" placeholder="Buscar" aria-label="Buscar Menu"></div></th>
<th data-column="pt"><div class="column-heading"><span>Português</span><input class="column-filter" type="search" data-column-filter="pt" placeholder="Buscar" aria-label="Buscar Português"></div></th>
<th data-column="en"><div class="column-heading"><span>English</span><input class="column-filter" type="search" data-column-filter="en" placeholder="Buscar" aria-label="Buscar English"></div></th>
<th data-column="es"><div class="column-heading"><span>Español</span><input class="column-filter" type="search" data-column-filter="es" placeholder="Buscar" aria-label="Buscar Español"></div></th>
</tr></thead><tbody><?php foreach ($rows as $row): $id=(int)$row['codajudatransacao']; $menu=self::menuLabel((string)$row['transacao']); ?><tr data-row data-key="<?= $id ?>">
<td data-column="menu"><input class="grid-input" value="<?= $e($menu) ?>" readonly data-menu-key="<?= $e((string)$row['transacao']) ?>"></td>
<?php foreach (['pt','en','es'] as $lang): ?><td data-column="<?= $lang ?>" class="ajuda-idioma-cell"><div class="grid-input ajuda-editable" tabindex="0" data-help-edit data-help-id="<?= $id ?>" data-help-lang="<?= $lang ?>" data-help-title="<?= $e((string)$row['titulo_'.$lang]) ?>" data-help-text="<?= $e((string)$row['texto_'.$lang]) ?>"><?= $e((string)$row['titulo_'.$lang]) ?><br><small><?= $e(strip_tags((string)$row['texto_'.$lang])) ?></small></div></td><?php endforeach; ?>
</tr><?php endforeach; ?></tbody></table></div></form><nav class="grid-pagination" aria-label="Paginação"><button type="button" class="btn btn-sm btn-outline-secondary grid-page-prev" title="Anterior"><i class="bi bi-chevron-left"></i></button><span class="grid-page-status">Calculando total...</span><button type="button" class="btn btn-sm btn-outline-secondary grid-page-next" title="Próxima"><i class="bi bi-chevron-right"></i></button></nav></section>
<script src="../assets/vendor/jquery/jquery-3.7.1.min.js?v=2"></script><script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script><script src="../assets/js/ajuda-html-edicao.js?v=2"></script><script src="../assets/js/grid-sort.js?v=2"></script><script src="../assets/js/grid-pagination.js?v=17"></script><script src="../assets/js/grid-column-filters.js?v=4"></script><script src="../assets/js/grid-loading.js?v=4"></script>
<?php
    }
    private static function menuLabel(string $value): string { $map=['administracao'=>'Administração','areas'=>'Áreas','manutencaolegal'=>'Manutenção Legal','legislacao'=>'Legislação','requisitoslegais'=>'Requisitos legais','situacaocumprimento'=>'Situação quanto ao cumprimento','configuracoes'=>'Configurações','usuarios'=>'Usuários','permissoes'=>'Permissões','transacoes'=>'Transações']; $parts=preg_split('/[_\-.]+/',strtolower(trim($value)),-1,PREG_SPLIT_NO_EMPTY)?:[]; return implode('/',array_map(static fn(string $part):string=>$map[$part]??mb_convert_case($part,MB_CASE_TITLE,'UTF-8'),$parts)); }
    private static function clean(string $html): string { $html=strip_tags($html,'<p><br><strong><b><em><i><ul><ol><li><a><h1><h2><h3><span><font><img>'); return preg_replace_callback('/<(a|img)\b[^>]*>/i', static function(array $m): string { $tag=strtolower($m[1]); if(!preg_match("~(?:href|src)\s*=\s*['\"](https?://[^'\"]+)['\"]~i",$m[0],$u)) return ''; $attr=$tag==='a'?'href':'src'; return '<'.$tag.' '.$attr.'="'.htmlspecialchars($u[1],ENT_QUOTES,'UTF-8').'"'.($tag==='a'?' target="_blank" rel="noopener noreferrer"':'').'>'; }, $html) ?? ''; }
}
