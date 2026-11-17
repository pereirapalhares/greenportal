<?php
declare(strict_types=1);

/*
 * Migração 295 — Homologação de Fornecedores, etapa 3: tela Histórico de alterações de documentos
 * (principal/homologacaofornecedores_historicodocumentos.php, menu HOMOLOGACAO_HISTORICO_DOCUMENTOS).
 * Legado: hpr_configuracoes foFornecedorxCriteriosHistorico (codtransacao 32).
 *
 * Em cada banco de cliente (gmodelo_dbp, gatlas_dbp, gmodelo2_dbp se existir):
 *  1. reaplica database/293_documentos_fornecedor.sql (a view do histórico passa a trazer fornecedor e unidade);
 *  2. índice do histórico por data (ordenação da tela);
 *  3. menu (universal e do cliente, abaixo de Homologação de Fornecedores) para Segurança › Permissões;
 *  4. ajuda da tela (universal).
 * Acesso: nenhum perfil é liberado aqui (ADMINISTRADOR por regra). Use a migração 247, por exemplo:
 *   php scripts\aplicar-migracao-247-permissoes-transacao.php --transacao=HOMOLOGACAO_HISTORICO_DOCUMENTOS --perfis=ADM_DA_EMPRESA,HOMOLOGACAO --acoes=visualizar,exportar
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-295-historico-documentos.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-295-historico-documentos.php
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

define('M295_BANCOS', HomologacaoBancos::lista($argv ?? [])); // classes/HomologacaoBancos.php (--banco= para só alguns)
const M295_MENU = ['codigo' => 'HOMOLOGACAO_HISTORICO_DOCUMENTOS', 'transacao' => 'homologacaofornecedores_historicodocumentos',
    'pt' => 'Histórico de alterações de documentos', 'en' => 'Document change history', 'es' => 'Historial de cambios de documentos', 'icone' => 'bi-clock-history'];

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$universal = GreenPortalDatabase::connect($env, $bancoUniversal, 'GUNIVERSAL');
$universal->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$template = (string)file_get_contents(dirname(__DIR__) . '/database/293_documentos_fornecedor.sql');
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

$relatorio = ['ok' => true, 'migracao' => '295', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
$tenants = [];
foreach (M295_BANCOS as $banco) {
    $info = [];
    if (!$valor($geral, 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$banco])) {
        if ($banco !== 'gmodelo2_dbp') { $relatorio['ok'] = false; $relatorio['bancos'][$banco] = ['erro' => 'banco não existe']; }
        continue;
    }
    try {
        if (!$valor($geral, "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? AND ROUTINE_NAME = 'sp_fo_fornecedor_documento_salvar'", [$banco]))
            throw new RuntimeException('rode antes a migração 293 (documentos do fornecedor)');
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $info['historico'] = (int)$valor($pdo, 'SELECT COUNT(*) FROM fo_fornecedorxcriterios_historico');
        $temIdx = (bool)$valor($geral, "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'fo_fornecedorxcriterios_historico' AND INDEX_NAME = 'ix_fo_docs_hist_data'", [$banco]);
        $info['indice_data'] = $temIdx ? 'já existe' : ($simular ? 'será criado' : '');
        if (!$simular) {
            if (!$temIdx) { $pdo->exec('ALTER TABLE fo_fornecedorxcriterios_historico ADD KEY ix_fo_docs_hist_data (DATAALTERACAO)'); $info['indice_data'] = 'criado'; }
            $n = 0;
            foreach (preg_split('/^-- @@\s*$/m', $template) as $bloco) {
                $limpo = trim(preg_replace('/^\s*--.*$/m', '', $bloco) ?? '');
                if ($limpo === '') continue;
                $pdo->exec($limpo); $n++;
            }
            $info['objetos_293_reaplicados'] = $n;
            $info['linhas_na_tela'] = (int)$valor($pdo, 'SELECT COUNT(*) FROM vw_homologacao_fornecedor_documentos_historico');
            $info['ultimas'] = $linhas($pdo, 'SELECT DATAALTERACAO, ALTERADO_POR, RAZAOSOCIAL, DSCRITERIO, SITUACAO FROM vw_homologacao_fornecedor_documentos_historico ORDER BY DATAALTERACAO DESC LIMIT 3');
            $tenants[$banco] = $pdo;
        }
    } catch (Throwable $e) {
        $relatorio['ok'] = false;
        $info['erro'] = $e->getMessage();
    }
    $relatorio['bancos'][$banco] = $info;
}

// Menu (universal + clientes) e ajuda
$m = M295_MENU;
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
                    VALUES ('HOMOLOGACAO_HISTORICO_DOCUMENTOS_295', 'fo_fornecedorxcriterios_historico', ?, 'ESTRUTURA', '4.0.1', 'CONCLUIDA', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
                    ON DUPLICATE KEY UPDATE status = VALUES(status), quantidade_registros = VALUES(quantidade_registros), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
                    ->execute(["$banco: tela de histórico; view; menu", (int)($relatorio['bancos'][$banco]['linhas_na_tela'] ?? 0), json_encode($relatorio['bancos'][$banco], JSON_UNESCAPED_UNICODE)]);
            }
        }
        $ajuda = [
            ['Ajuda — Histórico de alterações de documentos', 'Help — Document change history', 'Ayuda — Historial de cambios de documentos'],
            ['<p>Mostra <strong>todas as alterações</strong> feitas nos documentos dos fornecedores: quem alterou, quando e como o documento ficou (situação, validade, protocolo, responsáveis, contrato e observações).</p>'
                . '<ul><li>Cada gravação na aba Documentos (Gestão de Fornecedores) gera uma linha. Copiar/Colar e Reiniciar também.</li>'
                . '<li>Use os filtros das colunas para localizar um fornecedor, um documento, um período ou quem alterou; clique no título da coluna para ordenar. A mais recente aparece primeiro.</li>'
                . '<li><strong>Exportar</strong> gera a planilha com o que estiver filtrado.</li>'
                . '<li>Linhas com "Migração" em <em>Alterado por</em> foram registradas pelas migrações do sistema (por exemplo, ao juntar documentos repetidos).</li></ul>'
                . '<p>É uma tela de consulta: nada é alterado aqui. O que cada perfil pode ver é definido em Segurança › Permissões.</p>',
             '<p>Shows <strong>every change</strong> made to the supplier documents: who changed it, when, and how the document was left (status, expiry, protocol, owners, contract and notes).</p>'
                . '<ul><li>Each save in the Documents tab (Supplier Management) creates a row. Copy/Paste and Reset do too.</li>'
                . '<li>Use the column filters to find a supplier, a document, a period or who changed it; click a column title to sort. The most recent comes first.</li>'
                . '<li><strong>Export</strong> creates the spreadsheet with what is filtered.</li>'
                . '<li>Rows with "Migração" in <em>Changed by</em> were recorded by system migrations (for example, when duplicate documents were merged).</li></ul>'
                . '<p>This is a read-only screen: nothing is changed here. What each profile can see is defined in Security › Permissions.</p>',
             '<p>Muestra <strong>todos los cambios</strong> realizados en los documentos de los proveedores: quién cambió, cuándo y cómo quedó el documento (situación, vencimiento, protocolo, responsables, contrato y observaciones).</p>'
                . '<ul><li>Cada grabación en la pestaña Documentos (Gestión de Proveedores) genera una fila. Copiar/Pegar y Reiniciar también.</li>'
                . '<li>Use los filtros de las columnas para encontrar un proveedor, un documento, un período o quién cambió; haga clic en el título de la columna para ordenar. El más reciente aparece primero.</li>'
                . '<li><strong>Exportar</strong> genera la planilla con lo filtrado.</li>'
                . '<li>Las filas con "Migração" en <em>Cambiado por</em> fueron registradas por migraciones del sistema (por ejemplo, al unir documentos repetidos).</li></ul>'
                . '<p>Es una pantalla de consulta: aquí no se cambia nada. Lo que cada perfil puede ver se define en Seguridad › Permisos.</p>'],
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
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_295_historico_documentos' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
