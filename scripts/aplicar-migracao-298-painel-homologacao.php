<?php
declare(strict_types=1);

/*
 * Migração 298 — Homologação de Fornecedores, etapa 5: Painel de Homologação (relatórios gerenciais, estatísticas,
 * dashboard) e regra única de cores (principal/homologacaofornecedores_painel.php, menu HOMOLOGACAO_PAINEL).
 *
 * Em cada banco de cliente (gmodelo_dbp, gatlas_dbp, gmodelo2_dbp se existir):
 *  1. aplica database/298_painel_homologacao.sql (view vw_homologacao_painel_itens, sp_fo_painel_itens,
 *     preferências e execuções do resumo semanal);
 *  2. menu "Painel de Homologação" (universal e do cliente, abaixo de Homologação de Fornecedores);
 *  3. ajuda da tela (universal).
 * O resumo semanal roda pelo Agendador de Tarefas do Windows: scripts\criar-agendamento-resumo-homologacao.ps1.
 * Acesso: nenhum perfil é liberado aqui (ADMINISTRADOR por regra). Use a migração 247, por exemplo:
 *   php scripts\aplicar-migracao-247-permissoes-transacao.php --transacao=HOMOLOGACAO_PAINEL --perfis=ADM_DA_EMPRESA,HOMOLOGACAO --acoes=visualizar,editar,exportar
 *   php scripts\aplicar-migracao-247-permissoes-transacao.php --transacao=HOMOLOGACAO_PAINEL --perfis=FORNECEDOR,FORNECEDOR_GESTOR --acoes=visualizar,exportar
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-298-painel-homologacao.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-298-painel-homologacao.php
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

define('M298_BANCOS', HomologacaoBancos::lista($argv ?? [])); // classes/HomologacaoBancos.php (--banco= para só alguns)
const M298_MENU = ['codigo' => 'HOMOLOGACAO_PAINEL', 'transacao' => 'homologacaofornecedores_painel',
    'pt' => 'Painel de Homologação', 'en' => 'Supplier Approval Dashboard', 'es' => 'Panel de Homologación', 'icone' => 'bi-speedometer2'];

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$universal = GreenPortalDatabase::connect($env, $bancoUniversal, 'GUNIVERSAL');
$universal->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$template = (string)file_get_contents(dirname(__DIR__) . '/database/298_painel_homologacao.sql');
$linhas = static function (PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$valor = static function (PDO $pdo, string $sql, array $p = []) use ($linhas): mixed { $r = $linhas($pdo, $sql, $p); return $r ? array_values($r[0])[0] : null; };
$colunas = static function (string $b, string $t) use ($linhas, $geral): array {
    $out = [];
    foreach ($linhas($geral, 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$b, $t]) as $c) $out[strtolower($c['COLUMN_NAME'])] = true;
    return $out;
};
$gravarMenu = static function (PDO $pdo, string $banco, array $linha) use ($colunas, $valor): void {
    $dados = array_intersect_key($linha, $colunas($banco, 'menus'));
    $outro = $valor($pdo, 'SELECT codigo FROM menus WHERE codmenu = ?', [$linha['codmenu']]);
    if ($outro !== null && strtoupper((string)$outro) !== $linha['codigo']) throw new RuntimeException("codmenu {$linha['codmenu']} já usado por $outro em $banco");
    $campos = array_keys($dados);
    $upd = implode(', ', array_map(static fn($c) => "`$c` = VALUES(`$c`)", array_diff($campos, ['codmenu'])));
    $pdo->prepare('INSERT INTO menus (`' . implode('`, `', $campos) . '`) VALUES (' . implode(', ', array_fill(0, count($campos), '?')) . ") ON DUPLICATE KEY UPDATE $upd")->execute(array_values($dados));
};

$relatorio = ['ok' => true, 'migracao' => '298', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
$tenants = [];
foreach (M298_BANCOS as $banco) {
    $info = [];
    if (!$valor($geral, 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$banco])) {
        if ($banco !== 'gmodelo2_dbp') { $relatorio['ok'] = false; $relatorio['bancos'][$banco] = ['erro' => 'banco não existe']; }
        continue;
    }
    try {
        foreach (['vw_homologacao_fornecedor_documentos' => 'rode antes a migração 293', 'vw_homologacao_fornecedores_do_usuario' => 'rode antes a migração 296'] as $view => $erro)
            if (!$valor($geral, 'SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$banco, $view])) throw new RuntimeException($erro);
        $faltam = [];
        foreach (['fo_fornecedoresxtipofornecedores', 'fo_tipofornecedores', 'fo_funcionarios', 'fo_funcionarioxtiposxcriterios', 'fo_funcionarioxtiposxtreinamentos', 'fo_criteriofuncionarios', 'fo_tipotreinamentos', 'fo_equipamentos', 'fo_produtoquimico', 'fo_planodeacao', 'alertas'] as $tabela)
            if (!$colunas($banco, $tabela)) $faltam[] = $tabela;
        if ($faltam) throw new RuntimeException('tabelas ausentes: ' . implode(', ', $faltam));
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        if (!$simular) {
            $n = 0;
            foreach (preg_split('/^-- @@\s*$/m', $template) as $bloco) {
                $limpo = trim(preg_replace('/^\s*--.*$/m', '', $bloco) ?? '');
                if ($limpo === '') continue;
                $pdo->exec($limpo); $n++;
            }
            $info['objetos_298'] = $n;
            $info['itens_por_categoria_e_cor'] = [];
            foreach ($linhas($pdo, 'SELECT CATEGORIA, COR, COUNT(*) AS N FROM vw_homologacao_painel_itens GROUP BY CATEGORIA, COR ORDER BY CATEGORIA, COR') as $l)
                $info['itens_por_categoria_e_cor'][$l['CATEGORIA']][$l['COR']] = (int)$l['N'];
            $t0 = microtime(true);
            $s = $pdo->prepare('CALL sp_fo_painel_itens(0, 0, NULL, NULL, \'\', \'\', \'\', NULL)'); $s->execute(); $qtd = count($s->fetchAll()); while ($s->nextRowset()) { } $s->closeCursor();
            $info['painel_sem_filtro'] = ['itens' => $qtd, 'tempo_ms' => (int)round((microtime(true) - $t0) * 1000)];
            $tenants[$banco] = $pdo;
        }
    } catch (Throwable $e) {
        $relatorio['ok'] = false;
        $info['erro'] = $e->getMessage();
    }
    $relatorio['bancos'][$banco] = $info;
}

$m = M298_MENU;
try {
    $codmenu = (int)$valor($universal, 'SELECT codmenu FROM menus WHERE codigo = ?', [$m['codigo']]);
    $pai = (int)$valor($universal, "SELECT codmenu FROM menus WHERE codigo = 'HOMOLOGACAO_FORNECEDORES' LIMIT 1");
    $relatorio['menu_universal'] = $codmenu ? "já existe (codmenu $codmenu)" : 'será criado';
    if (!$simular) {
        if ($pai < 1) throw new RuntimeException('menu HOMOLOGACAO_FORNECEDORES não encontrado');
        if ($codmenu < 1) {
            $codmenu = (int)$valor($universal, 'SELECT COALESCE(MAX(codmenu), 0) FROM menus');
            foreach ($tenants as $pdo) $codmenu = max($codmenu, (int)$valor($pdo, 'SELECT COALESCE(MAX(codmenu), 0) FROM menus'));
            $codmenu++;
            $ordem = (int)$valor($universal, 'SELECT COALESCE(MAX(ordem), 0) + 1 FROM menus WHERE codmenu_pai = ?', [$pai]);
        } else {
            $ordem = (int)$valor($universal, 'SELECT ordem FROM menus WHERE codmenu = ?', [$codmenu]);
        }
        $linha = ['codmenu' => $codmenu, 'codmenu_pai' => $pai, 'modulo' => 'HOMOLOGACAO_FORNECEDORES', 'codigo' => $m['codigo'], 'label' => $m['pt'], 'label_pt' => $m['pt'], 'label_en' => $m['en'], 'label_es' => $m['es'],
            'url' => '/principal/' . $m['transacao'] . '.php', 'nivel' => 2, 'ordem' => $ordem, 'icone' => $m['icone'], 'ativo' => 1];
        $gravarMenu($universal, $bancoUniversal, $linha);
        $relatorio['menu_universal'] = "gravado (codmenu $codmenu, ordem $ordem)";
        foreach ($tenants as $banco => $pdo) {
            $paiCliente = (int)$valor($pdo, "SELECT codmenu FROM menus WHERE codigo = 'HOMOLOGACAO_FORNECEDORES' LIMIT 1") ?: $pai;
            $gravarMenu($pdo, $banco, ['codmenu_pai' => $paiCliente] + $linha);
            $relatorio['bancos'][$banco]['menu_cliente'] = "gravado (codmenu $codmenu); acesso: migração 247 ou Segurança › Permissões";
            if ($colunas($banco, 'registros_migracao')) {
                $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
                    VALUES ('HOMOLOGACAO_PAINEL_298', 'fo_*', ?, 'ESTRUTURA', '4.0.1', 'CONCLUIDA', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
                    ON DUPLICATE KEY UPDATE status = VALUES(status), quantidade_registros = VALUES(quantidade_registros), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
                    ->execute(["$banco: painel de homologação", (int)($relatorio['bancos'][$banco]['painel_sem_filtro']['itens'] ?? 0), json_encode($relatorio['bancos'][$banco], JSON_UNESCAPED_UNICODE)]);
            }
        }
        $ajuda = [
            ['Ajuda — Painel de Homologação', 'Help — Supplier Approval Dashboard', 'Ayuda — Panel de Homologación'],
            ['<p>Reúne num só lugar o acompanhamento dos fornecedores: documentos, auditoria, nível de serviço, funcionários (integração, documentos e treinamentos), equipamentos, produtos químicos e planos de ação.</p>'
                . '<ul><li><strong>Filtros</strong> (fornecedor/CNPJ, unidade, tipo, responsável, vencimento) valem para tudo na tela. "Salvar filtro" guarda o filtro neste navegador.</li>'
                . '<li>Clique num <strong>número</strong> do topo, numa <strong>consulta pronta</strong>, numa semana do gráfico de vencimentos ou numa célula de Unidade × situação para abrir a lista daquilo.</li>'
                . '<li><strong>Exportar esta lista</strong> gera a planilha da lista; <strong>Exportar Excel</strong> exporta todos os itens do filtro; <strong>Gerar PDF</strong> imprime a tela.</li>'
                . '<li><strong>Cobrar responsáveis</strong> avisa em Home › Alertas os responsáveis (empresa e fornecedor) dos itens da lista.</li>'
                . '<li><strong>Abrir ficha</strong> mostra tudo de um fornecedor: situação geral, o que falta, documentos, funcionários, auditoria, responsáveis e últimas alterações.</li>'
                . '<li><strong>Resumo semanal</strong>: toda segunda-feira um resumo das suas pendências chega em Home › Alertas (ligue ou desligue no botão).</li></ul>'
                . '<p><strong>Regra única de cores</strong> — vermelho: reprovado, vencido ou pendente com o fornecedor; amarelo: pendente com a empresa ou vence nos dias de reação (30 quando não informado); verde: aprovado, aprovado sem validade ou ciente; cinza: não se aplica / não enviar; branco: sem situação. "% atendido" = verdes ÷ (total − cinzas). Só fornecedores ativos entram no painel.</p>'
                . '<p>O fornecedor externo vê só os fornecedores ligados a ele.</p>',
             '<p>Brings supplier follow-up into one place: documents, audit, service level, employees (induction, documents and training), equipment, chemicals and action plans.</p>'
                . '<ul><li><strong>Filters</strong> (supplier/tax ID, unit, type, owner, expiry) apply to the whole screen. "Save filter" stores it in this browser.</li>'
                . '<li>Click a <strong>number</strong> at the top, a <strong>ready-made query</strong>, a week in the expiry chart or a cell of Unit × status to open that list.</li>'
                . '<li><strong>Export this list</strong> creates the list spreadsheet; <strong>Export to Excel</strong> exports every filtered item; <strong>Create PDF</strong> prints the screen.</li>'
                . '<li><strong>Remind owners</strong> notifies the owners (company and supplier) of the listed items in Home › Alerts.</li>'
                . '<li><strong>Open profile</strong> shows everything about a supplier: overall status, what is missing, documents, employees, audit, owners and recent changes.</li>'
                . '<li><strong>Weekly summary</strong>: every Monday a summary of your pending items arrives in Home › Alerts (turn it on or off with the button).</li></ul>'
                . '<p><strong>Single color rule</strong> — red: rejected, expired or pending with the supplier; yellow: pending with the company or expiring within the reaction days (30 when not set); green: approved, approved without expiry or aware; gray: not applicable / do not send; white: no status. "% met" = greens ÷ (total − grays). Only active suppliers are included.</p>'
                . '<p>External supplier users only see the suppliers linked to them.</p>',
             '<p>Reúne en un solo lugar el seguimiento de los proveedores: documentos, auditoría, nivel de servicio, empleados (integración, documentos y capacitaciones), equipos, productos químicos y planes de acción.</p>'
                . '<ul><li>Los <strong>filtros</strong> (proveedor/CNPJ, unidad, tipo, responsable, vencimiento) valen para toda la pantalla. "Guardar filtro" lo guarda en este navegador.</li>'
                . '<li>Haga clic en un <strong>número</strong> superior, en una <strong>consulta lista</strong>, en una semana del gráfico de vencimientos o en una celda de Unidad × situación para abrir esa lista.</li>'
                . '<li><strong>Exportar esta lista</strong> genera la planilla de la lista; <strong>Exportar Excel</strong> exporta todos los ítems del filtro; <strong>Generar PDF</strong> imprime la pantalla.</li>'
                . '<li><strong>Cobrar responsables</strong> avisa en Inicio › Alertas a los responsables (empresa y proveedor) de los ítems de la lista.</li>'
                . '<li><strong>Abrir ficha</strong> muestra todo de un proveedor: situación general, lo que falta, documentos, empleados, auditoría, responsables y últimos cambios.</li>'
                . '<li><strong>Resumen semanal</strong>: cada lunes un resumen de sus pendientes llega a Inicio › Alertas (actívelo o desactívelo con el botón).</li></ul>'
                . '<p><strong>Regla única de colores</strong> — rojo: reprobado, vencido o pendiente con el proveedor; amarillo: pendiente con la empresa o vence en los días de reacción (30 si no se informa); verde: aprobado, sin vencimiento o consciente; gris: no aplica / no enviar; blanco: sin situación. "% atendido" = verdes ÷ (total − grises). Solo proveedores activos.</p>'
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

$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_298_painel_homologacao' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
