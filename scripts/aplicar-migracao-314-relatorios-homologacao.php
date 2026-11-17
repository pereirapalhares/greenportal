<?php
declare(strict_types=1);

/*
 * Migração 314 — Homologação de Fornecedores: Relatórios de Homologação (19/10/2026).
 * Tela principal/homologacaofornecedores_relatorios.php (menu HOMOLOGACAO_RELATORIOS_GERENCIAIS, dentro de Relatórios):
 * reúne os relatórios gerenciais do legado (fo_relatorio_*), as Estatísticas (fo_estatisticas_c, viram filtros rápidos) e o
 * Relatório unificado de funcionários (foFuncionariosLista, vira matriz funcionário × integração/documentos/treinamentos).
 *
 * Em cada banco de cliente da homologação (classes/HomologacaoBancos.php):
 *  1. aplica database/314_relatorios_homologacao.sql (procedimentos dos relatórios e "Meus relatórios");
 *  2. menu "Relatórios de Homologação" (universal e do cliente, abaixo de Homologação de Fornecedores › Relatórios);
 *  3. permissões: copia, perfil a perfil, as do Painel de Homologação (HOMOLOGACAO_PAINEL), só quando o menu novo ainda não
 *     tem nenhuma permissão (não sobrescreve o que já foi ajustado em Segurança › Permissões);
 *  4. ajuda da tela (universal).
 * O envio semanal das visões salvas roda pelo Agendador de Tarefas do Windows: scripts\criar-agendamento-relatorios-homologacao.ps1.
 * Requer as migrações 298 e 299.
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-314-relatorios-homologacao.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-314-relatorios-homologacao.php [--banco=gcrown_dbp]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

const M314_MENU = ['codigo' => 'HOMOLOGACAO_RELATORIOS_GERENCIAIS', 'transacao' => 'homologacaofornecedores_relatorios', 'pai' => 'HOMOLOGACAO_RELATORIOS',
    'pt' => 'Relatórios de Homologação', 'en' => 'Supplier Approval Reports', 'es' => 'Informes de Homologación', 'icone' => 'bi-clipboard-data', 'ordem' => 5];
const M314_PERMISSAO_ORIGEM = 'HOMOLOGACAO_PAINEL';

$simular = in_array('--simular', $argv ?? [], true);
try { $alvos = HomologacaoBancos::lista($argv ?? []); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
$env = GreenPortalDatabase::env();
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$geral->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$universal = GreenPortalDatabase::connect($env, $bancoUniversal, 'GUNIVERSAL');
$universal->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$universal->exec('SET NAMES utf8mb4');
$template = (string)file_get_contents(dirname(__DIR__) . '/database/314_relatorios_homologacao.sql');
$linhas = static function (PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) { } $s->closeCursor(); return $r; };
$valor = static function (PDO $pdo, string $sql, array $p = []) use ($linhas): mixed { $r = $linhas($pdo, $sql, $p); return $r ? array_values($r[0])[0] : null; };
$colunas = static function (string $b, string $t) use ($linhas, $geral): array {
    $out = [];
    foreach ($linhas($geral, 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$b, $t]) as $c) $out[strtolower($c['COLUMN_NAME'])] = true;
    return $out;
};
// Colunas do legado lidas pelos relatórios (para avisar com clareza se algum banco tiver estrutura diferente).
$exigidas = [
    'fo_fornecedorxcriterios' => ['codtipo', 'codcriterio', 'enviadoodocumento', 'emprotocolo', 'datacobranca_emprotocolo', 'incluidocontrato', 'observacao', 'observacao_do_fornecedor'],
    'fo_fornecedoresxtipofornecedores' => ['nota', 'fornecedoraprovadoauditoria'],
    'fo_fornecedores' => ['nivel_de_servicao', 'data_da_avaliacao'],
    'fo_funcionarios' => ['nome', 'nridentificacao', 'setor', 'data_de_inicio', 'codtipofuncionario', 'integracao', 'ativo'],
    'fo_funcionarioxtiposxcriterios' => ['codtipofuncionario', 'codcriteriofuncionario', 'atendeaocriterio', 'observacao'],
    'fo_funcionarioxtiposxtreinamentos' => ['codtipofuncionario', 'codtipotreinamento', 'treinamentorealizado'],
    'fo_criteriofuncionarios' => ['dscriterio', 'tipo_de_documento'],
    'fo_tipofuncionarios' => ['dsctipo'],
    'fo_tipofuncionariosxcriteriofuncionarios' => ['codtipofuncionario', 'codcriteriofuncionario'],
    'fo_tipofuncionariosxtipotreinamentos' => ['codtipofuncionario', 'codtipotreinamento'],
    'fo_tipofornecedoresxcriteriofornecedores' => ['nivel_de_exigencia'],
    'fo_produtoquimico' => ['ncas', 'produto_controlado', 'caracteristica', 'aprovado'],
    'fo_equipamentos' => ['nridentificacao', 'aprovado'],
    'fo_planodeacao' => ['datainicial', 'recursos', 'status'],
];
$gravarMenu = static function (PDO $pdo, string $banco, array $linha) use ($colunas, $valor): void {
    $dados = array_intersect_key($linha, $colunas($banco, 'menus'));
    $outro = $valor($pdo, 'SELECT codigo FROM menus WHERE codmenu = ?', [$linha['codmenu']]);
    if ($outro !== null && strtoupper((string)$outro) !== $linha['codigo']) throw new RuntimeException("codmenu {$linha['codmenu']} já usado por $outro em $banco");
    $campos = array_keys($dados);
    $upd = implode(', ', array_map(static fn($c) => "`$c` = VALUES(`$c`)", array_diff($campos, ['codmenu'])));
    $pdo->prepare('INSERT INTO menus (`' . implode('`, `', $campos) . '`) VALUES (' . implode(', ', array_fill(0, count($campos), '?')) . ") ON DUPLICATE KEY UPDATE $upd")->execute(array_values($dados));
};

$relatorio = ['ok' => true, 'migracao' => '314', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
$tenants = [];
foreach ($alvos as $banco) {
    $info = [];
    if (!$valor($geral, 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$banco])) {
        if ($banco !== 'gmodelo2_dbp') { $relatorio['ok'] = false; $relatorio['bancos'][$banco] = ['erro' => 'banco não existe']; }
        continue;
    }
    try {
        if (!$valor($geral, "SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'vw_homologacao_painel_itens'", [$banco])) throw new RuntimeException('rode antes a migração 298');
        $faltam = [];
        foreach ($exigidas as $tabela => $cols) {
            $existentes = $colunas($banco, $tabela);
            if (!$existentes) { $faltam[] = $tabela; continue; }
            foreach ($cols as $c) if (!isset($existentes[$c])) $faltam[] = "$tabela.$c";
        }
        if ($faltam) throw new RuntimeException('estrutura diferente do legado, faltam: ' . implode(', ', $faltam));
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $tenants[$banco] = $pdo;
        if (!$simular) {
            $n = 0;
            foreach (preg_split('/^-- @@\s*$/m', $template) as $bloco) {
                $limpo = trim(preg_replace('/^\s*--.*$/m', '', $bloco) ?? '');
                if ($limpo === '') continue;
                $pdo->exec($limpo); $n++;
            }
            $info['objetos_314'] = $n;
            // Teste: um relatório pequeno e o unificado sem filtro (tempo e quantidade).
            foreach (['DOCUMENTO', 'DOCFUNC'] as $cat) {
                $t0 = microtime(true);
                $qtd = count($linhas($pdo, 'CALL sp_fo_relatorio_itens(0, 0, NULL, NULL, \'\', ?)', [$cat]));
                $info['teste'][$cat] = ['itens' => $qtd, 'tempo_ms' => (int)round((microtime(true) - $t0) * 1000)];
            }
            $t0 = microtime(true);
            $s = $pdo->prepare('CALL sp_fo_relatorio_unificado(0, 0, NULL, NULL, \'\', NULL)'); $s->execute();
            $funcionarios = count($s->fetchAll()); while ($s->nextRowset()) { } $s->closeCursor();
            $info['teste']['UNIFICADO'] = ['funcionarios' => $funcionarios, 'tempo_ms' => (int)round((microtime(true) - $t0) * 1000)];
        } else {
            $info['simulacao'] = 'estrutura conferida; nada gravado';
        }
    } catch (Throwable $e) {
        $relatorio['ok'] = false;
        $info['erro'] = $e->getMessage();
        unset($tenants[$banco]);
    }
    $relatorio['bancos'][$banco] = $info;
}

$m = M314_MENU;
try {
    $codmenu = (int)$valor($universal, 'SELECT codmenu FROM menus WHERE codigo = ?', [$m['codigo']]);
    $pai = (int)$valor($universal, 'SELECT codmenu FROM menus WHERE codigo = ? LIMIT 1', [$m['pai']]);
    $relatorio['menu_universal'] = $codmenu ? "já existe (codmenu $codmenu)" : 'será criado';
    if ($pai < 1) throw new RuntimeException('grupo ' . $m['pai'] . ' não encontrado: rode antes a migração 299');
    if (!$simular) {
        if ($codmenu < 1) {
            // Código único em todos os bancos com menu (como nas migrações 298 e 299).
            $codmenu = (int)$valor($universal, 'SELECT COALESCE(MAX(codmenu), 0) FROM menus');
            foreach ($linhas($geral, "SELECT TABLE_SCHEMA AS b FROM information_schema.TABLES WHERE TABLE_NAME = 'menus' AND TABLE_SCHEMA LIKE 'g%\\_dbp' AND TABLE_SCHEMA <> ?", [$bancoUniversal]) as $l) {
                $outro = $tenants[(string)$l['b']] ?? GreenPortalDatabase::connect($env, (string)$l['b'], 'GPT');
                $codmenu = max($codmenu, (int)$valor($outro, 'SELECT COALESCE(MAX(codmenu), 0) FROM menus'));
            }
            $codmenu++;
        }
        $linha = ['codmenu' => $codmenu, 'codmenu_pai' => $pai, 'modulo' => 'HOMOLOGACAO_FORNECEDORES', 'codigo' => $m['codigo'], 'label' => $m['pt'], 'label_pt' => $m['pt'], 'label_en' => $m['en'], 'label_es' => $m['es'],
            'url' => '/principal/' . $m['transacao'] . '.php', 'nivel' => 3, 'ordem' => $m['ordem'], 'icone' => $m['icone'], 'ativo' => 1];
        $gravarMenu($universal, $bancoUniversal, $linha);
        $relatorio['menu_universal'] = "gravado (codmenu $codmenu)";
        foreach ($tenants as $banco => $pdo) {
            $paiCliente = (int)$valor($pdo, 'SELECT codmenu FROM menus WHERE codigo = ? LIMIT 1', [$m['pai']]);
            if ($paiCliente < 1) { $relatorio['bancos'][$banco]['menu_cliente'] = 'grupo ' . $m['pai'] . ' não existe neste cliente: rode a migração 299'; $relatorio['ok'] = false; continue; }
            $gravarMenu($pdo, $banco, ['codmenu_pai' => $paiCliente] + $linha);
            $relatorio['bancos'][$banco]['menu_cliente'] = "gravado (codmenu $codmenu)";
            // Permissões copiadas do Painel de Homologação (só se o menu novo ainda não tiver nenhuma).
            $origem = (int)$valor($pdo, 'SELECT codmenu FROM menus WHERE codigo = ?', [M314_PERMISSAO_ORIGEM]);
            $copiadas = [];
            foreach (['permissoes', 'perfil_menus'] as $tabela) {
                $cols = $colunas($banco, $tabela);
                if (!$cols || !isset($cols['codperfil'], $cols['codmenu'])) continue;
                if ($origem < 1) { $copiadas[$tabela] = 'menu ' . M314_PERMISSAO_ORIGEM . ' não existe'; continue; }
                if ((int)$valor($pdo, "SELECT COUNT(*) FROM `$tabela` WHERE codmenu = ?", [$codmenu]) > 0) { $copiadas[$tabela] = 'já tinha permissões (mantidas)'; continue; }
                $flags = array_values(array_filter(array_keys($cols), static fn($c) => str_starts_with($c, 'pode_')));
                $lista = implode(', ', array_map(static fn($c) => "`$c`", ['codperfil', ...$flags]));
                $pdo->prepare("INSERT INTO `$tabela` (`codmenu`, $lista) SELECT ?, $lista FROM `$tabela` WHERE codmenu = ?")->execute([$codmenu, $origem]);
                $copiadas[$tabela] = (int)$valor($pdo, "SELECT COUNT(*) FROM `$tabela` WHERE codmenu = ?", [$codmenu]) . ' perfil(is) copiados do ' . M314_PERMISSAO_ORIGEM;
            }
            $relatorio['bancos'][$banco]['permissoes'] = $copiadas;
            if ($colunas($banco, 'registros_migracao')) {
                $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
                    VALUES ('HOMOLOGACAO_RELATORIOS_314', 'fo_*', ?, 'ESTRUTURA', '4.0.1', 'CONCLUIDA', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
                    ON DUPLICATE KEY UPDATE status = VALUES(status), quantidade_registros = VALUES(quantidade_registros), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
                    ->execute(["$banco: relatórios de homologação", (int)($relatorio['bancos'][$banco]['teste']['UNIFICADO']['funcionarios'] ?? 0), json_encode($relatorio['bancos'][$banco], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
            }
        }
        $ajuda = [
            ['Ajuda — Relatórios de Homologação', 'Help — Supplier Approval Reports', 'Ayuda — Informes de Homologación'],
            ['<p>Todos os relatórios da homologação numa tela: escolha o relatório no catálogo à esquerda (Fornecedores, Funcionários, Rastreabilidade). O número ao lado de cada um é a quantidade de itens vermelhos.</p>'
                . '<ul><li><strong>Filtros</strong> (fornecedor/CNPJ, unidade, tipo) valem para o relatório e para os números do catálogo.</li>'
                . '<li><strong>Filtros rápidos</strong> (Válidos, Vencidos, Vencem em 30 dias, Reprovados...) substituem a árvore de Estatísticas do sistema anterior; clique também numa faixa da barra de distribuição.</li>'
                . '<li><strong>Grid</strong>: filtro e ordenação por coluna, busca no grid, paginação; a cor da linha segue a regra única de cores do Painel.</li>'
                . '<li><strong>Agrupar por</strong> unidade, fornecedor ou tipo mostra subtotais de cada grupo.</li>'
                . '<li><strong>Evidência</strong>: quantidade de arquivos no GED; clique para abrir.</li>'
                . '<li><strong>Exportar Excel</strong> gera a planilha com os filtros da tela; ela chega em Home › Alertas.</li>'
                . '<li><strong>Salvar como meu relatório</strong> guarda relatório, filtros, agrupamento e ordem em "Meus relatórios". Com o <strong>envio semanal</strong> ligado, toda segunda-feira um resumo chega em Home › Alertas.</li>'
                . '<li><strong>Relatório unificado</strong>: uma linha por funcionário com integração, documentos e treinamentos exigidos pelo tipo de funcionário. <em>Bloqueado</em> = algum item exigido vencido, "Não atende" ou não avaliado; <em>Vence em breve</em> = algum item vence nos dias de reação; <em>Liberado</em> = tudo em dia. "—" = não exigido para o tipo. "Lista para a portaria" exporta os liberados.</li></ul>'
                . '<p>O fornecedor externo vê só os fornecedores ligados a ele.</p>',
             '<p>All supplier approval reports on one screen: choose the report in the catalog on the left (Suppliers, Employees, Traceability). The number next to each one is the count of red items.</p>'
                . '<ul><li><strong>Filters</strong> (supplier/tax ID, unit, type) apply to the report and to the catalog numbers.</li>'
                . '<li><strong>Quick filters</strong> (Valid, Expired, Expiring in 30 days, Rejected...) replace the Statistics tree of the previous system; you can also click a band of the distribution bar.</li>'
                . '<li><strong>Grid</strong>: filter and sort by column, grid search, pagination; the row color follows the single color rule of the Dashboard.</li>'
                . '<li><strong>Group by</strong> unit, supplier or type shows subtotals for each group.</li>'
                . '<li><strong>Evidence</strong>: number of files in the document management; click to open.</li>'
                . '<li><strong>Export to Excel</strong> creates the spreadsheet with the screen filters; it arrives in Home › Alerts.</li>'
                . '<li><strong>Save as my report</strong> stores report, filters, grouping and order in "My reports". With the <strong>weekly delivery</strong> on, a summary arrives in Home › Alerts every Monday.</li>'
                . '<li><strong>Unified report</strong>: one row per employee with induction, documents and training required by the employee type. <em>Blocked</em> = some required item expired, "Not met" or not evaluated; <em>Expiring soon</em> = some item expires within the reaction days; <em>Cleared</em> = everything up to date. "—" = not required for the type. "Gatehouse list" exports the cleared employees.</li></ul>'
                . '<p>External supplier users only see the suppliers linked to them.</p>',
             '<p>Todos los informes de la homologación en una pantalla: elija el informe en el catálogo a la izquierda (Proveedores, Empleados, Trazabilidad). El número al lado de cada uno es la cantidad de ítems rojos.</p>'
                . '<ul><li>Los <strong>filtros</strong> (proveedor/CNPJ, unidad, tipo) valen para el informe y para los números del catálogo.</li>'
                . '<li>Los <strong>filtros rápidos</strong> (Válidos, Vencidos, Vencen en 30 días, Reprobados...) reemplazan el árbol de Estadísticas del sistema anterior; también puede hacer clic en una franja de la barra de distribución.</li>'
                . '<li><strong>Cuadrícula</strong>: filtro y orden por columna, búsqueda, paginación; el color de la fila sigue la regla única de colores del Panel.</li>'
                . '<li><strong>Agrupar por</strong> unidad, proveedor o tipo muestra subtotales de cada grupo.</li>'
                . '<li><strong>Evidencia</strong>: cantidad de archivos en el GED; haga clic para abrir.</li>'
                . '<li><strong>Exportar Excel</strong> genera la planilla con los filtros de la pantalla; llega a Inicio › Alertas.</li>'
                . '<li><strong>Guardar como mi informe</strong> guarda informe, filtros, agrupación y orden en "Mis informes". Con el <strong>envío semanal</strong> activado, cada lunes llega un resumen a Inicio › Alertas.</li>'
                . '<li><strong>Informe unificado</strong>: una fila por empleado con integración, documentos y capacitaciones exigidos por el tipo de empleado. <em>Bloqueado</em> = algún ítem exigido vencido, "No atiende" o no evaluado; <em>Vence pronto</em> = algún ítem vence en los días de reacción; <em>Liberado</em> = todo al día. "—" = no exigido para el tipo. "Lista para la portería" exporta los liberados.</li></ul>'
                . '<p>El proveedor externo ve solo los proveedores vinculados a él.</p>'],
        ];
        $existe = (int)$valor($universal, 'SELECT COUNT(*) FROM ajuda_transacoes WHERE transacao = ?', [$m['transacao']]);
        $universal->prepare($existe
            ? 'UPDATE ajuda_transacoes SET titulo_pt = ?, titulo_en = ?, titulo_es = ?, texto_pt = ?, texto_en = ?, texto_es = ?, ativo = 1 WHERE transacao = ?'
            : 'INSERT INTO ajuda_transacoes (titulo_pt, titulo_en, titulo_es, texto_pt, texto_en, texto_es, ativo, transacao) VALUES (?, ?, ?, ?, ?, ?, 1, ?)')
            ->execute([...$ajuda[0], ...$ajuda[1], $m['transacao']]);
        $relatorio['ajuda'] = $existe ? 'atualizada' : 'incluída';
    }
} catch (Throwable $e) { $relatorio['ok'] = false; $relatorio['menu_universal'] = 'ERRO: ' . $e->getMessage(); }
$relatorio['proximo_passo'] = 'Envio semanal das visões salvas: PowerShell como Administrador → scripts\\criar-agendamento-relatorios-homologacao.ps1';

$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_314_relatorios_homologacao' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
