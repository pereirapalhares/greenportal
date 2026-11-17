<?php
declare(strict_types=1);

/*
 * Teste (somente leitura) das consultas das telas Administração › Empresas e migração, Log da migração e Menus de migração.
 * Executa as mesmas procedures/consultas que as páginas fazem ao abrir e mostra qual falha (uma falha fora do try deixa a
 * tela em branco, porque o PHP não mostra o erro na página).
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\testar-telas-migracao.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/AssistenteAplicacaoService.php';

$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'guniversal_dbp', 'GUNIVERSAL');
$r = ['teste' => 'telas de migração', 'gerado_em' => date('c'), 'resultados' => []];
$testar = static function (string $nome, callable $f) use (&$r): mixed {
    try { $v = $f(); $r['resultados'][$nome] = 'ok — ' . (is_array($v) ? count($v) . ' linha(s)' : json_encode($v)); return $v; }
    catch (Throwable $e) { $r['resultados'][$nome] = 'ERRO — ' . get_class($e) . ': ' . $e->getMessage(); return null; }
};
$call = static fn(string $p, array $a) => AssistenteAplicacaoService::chamarProcedure($pdo, $p, $a);

// administracao_migracao_empresas.php
$testar('empresas: sp_migracao_solicitacoes_count', fn() => $call('sp_migracao_solicitacoes_count', ['', '']));
$lista = $testar('empresas: sp_migracao_solicitacoes_listar', fn() => $call('sp_migracao_solicitacoes_listar', ['', '', 500, 0]));
$testar('empresas: vw_empresa_migracao_grid', fn() => $pdo->query("SELECT codempresa,codigo,nome,banco_legado,banco_novo,ged,status_migracao FROM vw_empresa_migracao_grid WHERE banco_novo IS NOT NULL ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC));
if (is_array($lista) && $lista) $r['colunas_da_lista'] = array_keys($lista[0]);
// administracao_migracao_log.php (com a solicitação mais recente)
$id = is_array($lista) && $lista ? (int)max(array_column($lista, 'codsolicitacao')) : 0;
$r['solicitacao_testada'] = $id;
$testar('log: sp_migracao_logs', fn() => $call('sp_migracao_logs', [$id]));
$testar('log: sp_migracao_solicitacao_obter', fn() => $call('sp_migracao_solicitacao_obter', [$id]));
// administracao_migracao_menus.php
$testar('menus: sp_migracao_menus_nao_mapeados_count', fn() => $call('sp_migracao_menus_nao_mapeados_count', ['', '', '']));
$testar('menus: sp_migracao_menus_nao_mapeados_listar', fn() => $call('sp_migracao_menus_nao_mapeados_listar', ['', '', '', 500, 0]));
$testar('menus: menus ativos', fn() => $pdo->query("SELECT codmenu,codigo,label,modulo FROM menus WHERE ativo=1 ORDER BY COALESCE(modulo,''),label")->fetchAll(PDO::FETCH_ASSOC));
$testar('menus: destinos', fn() => $pdo->query("SELECT DISTINCT banco_destino FROM migracao_menus_nao_mapeados ORDER BY banco_destino")->fetchAll(PDO::FETCH_COLUMN));
// Menu do portal
$r['menus_de_migracao'] = $testar('menu: itens de migração no universal', fn() => $pdo->query("SELECT m.codmenu, m.codigo, m.label, m.url, m.nivel, m.ativo, p.codigo AS pai FROM menus m LEFT JOIN menus p ON p.codmenu = m.codmenu_pai WHERE m.codigo LIKE 'ADMINISTRACAO%MIGRA%' OR m.url LIKE '%administracao_migracao%'")->fetchAll(PDO::FETCH_ASSOC));
echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
