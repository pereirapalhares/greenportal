<?php
declare(strict_types=1);

/*
 * Migração 296 — Homologação de Fornecedores, etapa 4: fornecedores desativados e acesso do fornecedor externo.
 *
 * Em cada banco de cliente (gmodelo_dbp, gatlas_dbp, gmodelo2_dbp se existir):
 *  1. junta vínculos repetidos de Fornecedor x Usuários (fo_fornecedorxusuario; cópia em bkp296_fo_fornecedorxusuario)
 *     e cria o índice único uk_fo_fornecedorxusuario (CODFORNECEDOR, CODUSUARIO);
 *  2. aplica database/296_fornecedores_desativados_externo.sql (views de ativos / desativados / do usuário,
 *     pendências do fornecedor externo, procedures de acesso e de Fornecedor x Usuários, gatilho "ativo por padrão");
 *  3. perfis FORNECEDOR e FORNECEDOR_GESTOR (só cria se não existirem; não muda perfis já cadastrados);
 *  4. menu "Fornecedores desativados" (universal e do cliente, abaixo de Homologação de Fornecedores);
 *  5. ajudas das telas (universal).
 * Também informa os vínculos de Fornecedor x Usuários com usuário ou fornecedor que não existe no banco.
 *
 * Acesso: nenhum perfil é liberado aqui (ADMINISTRADOR por regra). Use a migração 247, por exemplo:
 *   php scripts\aplicar-migracao-247-permissoes-transacao.php --transacao=HOMOLOGACAO_FORNECEDORES_DESATIVADOS --perfis=ADM_DA_EMPRESA,HOMOLOGACAO --acoes=visualizar,editar,exportar
 *   php scripts\aplicar-migracao-247-permissoes-transacao.php --transacao=HOMOLOGACAO_FORNECEDOR_FORNECEDOR --perfis=FORNECEDOR --acoes=visualizar,incluir,editar,arquivos,importar
 *   php scripts\aplicar-migracao-247-permissoes-transacao.php --transacao=HOMOLOGACAO_FORNECEDOR_FORNECEDOR --perfis=FORNECEDOR_GESTOR --acoes=visualizar,incluir,editar,excluir,arquivos,importar
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-296-fornecedores-desativados-externo.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-296-fornecedores-desativados-externo.php
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

define('M296_BANCOS', HomologacaoBancos::lista($argv ?? [])); // classes/HomologacaoBancos.php (--banco= para só alguns)
const M296_MENU = ['codigo' => 'HOMOLOGACAO_FORNECEDORES_DESATIVADOS', 'transacao' => 'homologacaofornecedores_fornecedoresdesativados',
    'pt' => 'Fornecedores desativados', 'en' => 'Deactivated suppliers', 'es' => 'Proveedores desactivados', 'icone' => 'bi-building-slash'];
const M296_PERFIS = [
    'FORNECEDOR' => 'Fornecedor (acesso externo)',
    'FORNECEDOR_GESTOR' => 'Fornecedor gestor (acesso externo)',
];

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$universal = GreenPortalDatabase::connect($env, $bancoUniversal, 'GUNIVERSAL');
$universal->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$template = (string)file_get_contents(dirname(__DIR__) . '/database/296_fornecedores_desativados_externo.sql');
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

$relatorio = ['ok' => true, 'migracao' => '296', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
$tenants = [];
foreach (M296_BANCOS as $banco) {
    $info = [];
    if (!$valor($geral, 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$banco])) {
        if ($banco !== 'gmodelo2_dbp') { $relatorio['ok'] = false; $relatorio['bancos'][$banco] = ['erro' => 'banco não existe']; }
        continue;
    }
    try {
        if (!$valor($geral, "SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'vw_homologacao_fornecedor_documentos'", [$banco]))
            throw new RuntimeException('rode antes a migração 293 (documentos do fornecedor)');
        if (!$valor($geral, "SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'vw_homologacao_fornecedores_grid'", [$banco]))
            throw new RuntimeException('view vw_homologacao_fornecedores_grid não existe (migração 216)');
        foreach (['perfis' => ['codperfil', 'codigo', 'nome'], 'usuarios' => ['codusuario', 'userid', 'nome', 'ativo', 'codperfil'], 'fo_fornecedorxusuario' => ['codfornecedor', 'codusuario']] as $tabela => $precisa) {
            $tem = $colunas($banco, $tabela);
            $faltam = array_values(array_filter($precisa, static fn($c) => !isset($tem[$c])));
            if ($faltam) throw new RuntimeException("tabela $tabela sem as colunas: " . implode(', ', $faltam));
        }
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');

        // Situação atual
        $info['fornecedores'] = $linhas($pdo, "SELECT COALESCE(NULLIF(TRIM(ATIVADOFOR), ''), '(vazio)') AS ativado, COUNT(*) AS qtd FROM vw_homologacao_fornecedores_grid GROUP BY 1 ORDER BY 1");
        $info['fornecedor_x_usuario'] = [
            'vinculos' => (int)$valor($pdo, 'SELECT COUNT(*) FROM fo_fornecedorxusuario'),
            'repetidos' => (int)$valor($pdo, 'SELECT COALESCE(SUM(n - 1), 0) FROM (SELECT COUNT(*) n FROM fo_fornecedorxusuario GROUP BY CODFORNECEDOR, CODUSUARIO HAVING COUNT(*) > 1) x'),
            'usuario_inexistente' => (int)$valor($pdo, 'SELECT COUNT(*) FROM fo_fornecedorxusuario fu WHERE NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.codusuario = fu.CODUSUARIO)'),
            'fornecedor_inexistente' => (int)$valor($pdo, 'SELECT COUNT(*) FROM fo_fornecedorxusuario fu WHERE NOT EXISTS (SELECT 1 FROM fo_fornecedores f WHERE f.codfornecedor = fu.CODFORNECEDOR)'),
            'usuarios_distintos' => (int)$valor($pdo, 'SELECT COUNT(DISTINCT CODUSUARIO) FROM fo_fornecedorxusuario'),
            'exemplos_usuario_inexistente' => array_map('intval', array_column($linhas($pdo, 'SELECT DISTINCT fu.CODUSUARIO FROM fo_fornecedorxusuario fu WHERE NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.codusuario = fu.CODUSUARIO) ORDER BY 1 LIMIT 10'), 'CODUSUARIO')),
        ];
        $perfisExistentes = [];
        foreach (array_keys(M296_PERFIS) as $codigo) {
            $p = $linhas($pdo, 'SELECT codperfil, nome FROM perfis WHERE UPPER(codigo) = ?', [$codigo])[0] ?? null;
            $perfisExistentes[$codigo] = $p;
            $info['perfis'][$codigo] = $p ? ['situacao' => 'já existe (mantido)', 'codperfil' => (int)$p['codperfil'], 'nome' => $p['nome'], 'usuarios' => (int)$valor($pdo, 'SELECT COUNT(*) FROM usuarios WHERE codperfil = ?', [(int)$p['codperfil']])] : ['situacao' => $simular ? 'será criado' : ''];
        }
        $temUnico = (bool)$valor($geral, "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'fo_fornecedorxusuario' AND INDEX_NAME = 'uk_fo_fornecedorxusuario'", [$banco]);
        $info['indice_unico'] = $temUnico ? 'já existe' : ($simular ? 'será criado' : '');

        if (!$simular) {
            // 1. vínculos repetidos
            if (!$temUnico) {
                $rep = $info['fornecedor_x_usuario']['repetidos'];
                if ($rep > 0) {
                    $pdo->exec('CREATE TABLE IF NOT EXISTS bkp296_fo_fornecedorxusuario LIKE fo_fornecedorxusuario');
                    $pdo->exec('INSERT IGNORE INTO bkp296_fo_fornecedorxusuario SELECT * FROM fo_fornecedorxusuario');
                    $pdo->exec('DELETE a FROM fo_fornecedorxusuario a JOIN fo_fornecedorxusuario b ON b.CODFORNECEDOR <=> a.CODFORNECEDOR AND b.CODUSUARIO <=> a.CODUSUARIO AND b.CODFORNECEDORXUSUARIO < a.CODFORNECEDORXUSUARIO');
                }
                $pdo->exec('ALTER TABLE fo_fornecedorxusuario ADD UNIQUE KEY uk_fo_fornecedorxusuario (CODFORNECEDOR, CODUSUARIO)');
                $info['indice_unico'] = 'criado' . ($rep > 0 ? " ($rep repetidos removidos; cópia em bkp296_fo_fornecedorxusuario)" : '');
            }
            // 2. objetos
            $n = 0;
            foreach (preg_split('/^-- @@\s*$/m', $template) as $bloco) {
                $limpo = trim(preg_replace('/^\s*--.*$/m', '', $bloco) ?? '');
                if ($limpo === '') continue;
                $pdo->exec($limpo); $n++;
            }
            $info['objetos_296'] = $n;
            // 3. perfis
            $colPerfis = $colunas($banco, 'perfis');
            foreach (M296_PERFIS as $codigo => $nome) {
                if ($perfisExistentes[$codigo]) continue;
                $dados = array_intersect_key(['codigo' => $codigo, 'nome' => $nome, 'acesso_total' => 0, 'ativo' => 1], $colPerfis);
                $pdo->prepare('INSERT INTO perfis (`' . implode('`, `', array_keys($dados)) . '`) VALUES (' . implode(', ', array_fill(0, count($dados), '?')) . ')')->execute(array_values($dados));
                $info['perfis'][$codigo] = ['situacao' => 'criado', 'codperfil' => (int)$valor($pdo, 'SELECT codperfil FROM perfis WHERE codigo = ?', [$codigo]), 'nome' => $nome, 'usuarios' => 0];
            }
            $info['na_tela'] = [
                'ativos' => (int)$valor($pdo, 'SELECT COUNT(*) FROM vw_homologacao_fornecedores_ativos_grid'),
                'desativados' => (int)$valor($pdo, 'SELECT COUNT(*) FROM vw_homologacao_fornecedores_desativados_grid'),
                'acessos_fornecedor_usuario' => (int)$valor($pdo, 'SELECT COUNT(*) FROM vw_homologacao_fornecedores_usuario_grid'),
                'pendencias_fornecedor_externo' => (int)$valor($pdo, 'SELECT COUNT(*) FROM vw_homologacao_pendencias_fornecedor_externo'),
            ];
            $tenants[$banco] = $pdo;
        }
    } catch (Throwable $e) {
        $relatorio['ok'] = false;
        $info['erro'] = $e->getMessage();
    }
    $relatorio['bancos'][$banco] = $info;
}

// Menu (universal + clientes) e ajudas
$m = M296_MENU;
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
                    VALUES ('HOMOLOGACAO_DESATIVADOS_EXTERNO_296', 'fo_fornecedores/fo_fornecedorxusuario', ?, 'ESTRUTURA', '4.0.1', 'CONCLUIDA', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
                    ON DUPLICATE KEY UPDATE status = VALUES(status), quantidade_registros = VALUES(quantidade_registros), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
                    ->execute(["$banco: desativados; fornecedor externo", (int)($relatorio['bancos'][$banco]['na_tela']['desativados'] ?? 0), json_encode($relatorio['bancos'][$banco], JSON_UNESCAPED_UNICODE)]);
            }
        }
        $ajudas = [
            $m['transacao'] => [
                ['Ajuda — Fornecedores desativados', 'Help — Deactivated suppliers', 'Ayuda — Proveedores desactivados'],
                ['<p>Mostra os fornecedores com <strong>Ativado? = Não</strong> (ou sem informação). A Gestão de Fornecedores mostra só os ativos.</p>'
                    . '<ul><li>Para reativar, mude <em>Ativado?</em> para Sim e salve: o fornecedor sai desta tela e volta para a Gestão de Fornecedores.</li>'
                    . '<li>Os botões de contatos, tipos, documentos e demais abas funcionam como na Gestão de Fornecedores.</li>'
                    . '<li>Fornecedor desativado não aparece para o fornecedor externo nem nas pendências dele.</li></ul>',
                 '<p>Shows suppliers with <strong>Activated? = No</strong> (or not filled in). Supplier Management shows only the active ones.</p>'
                    . '<ul><li>To reactivate, set <em>Activated?</em> to Yes and save: the supplier leaves this screen and returns to Supplier Management.</li>'
                    . '<li>The contacts, types, documents and other tab buttons work as in Supplier Management.</li>'
                    . '<li>A deactivated supplier is not shown to the external supplier user or in their pending items.</li></ul>',
                 '<p>Muestra los proveedores con <strong>¿Activado? = No</strong> (o sin información). La Gestión de Proveedores muestra solo los activos.</p>'
                    . '<ul><li>Para reactivar, cambie <em>¿Activado?</em> a Sí y guarde: el proveedor sale de esta pantalla y vuelve a la Gestión de Proveedores.</li>'
                    . '<li>Los botones de contactos, tipos, documentos y demás pestañas funcionan como en la Gestión de Proveedores.</li>'
                    . '<li>El proveedor desactivado no aparece para el usuario externo del proveedor ni en sus pendientes.</li></ul>'],
            ],
            'homologacaofornecedor_fornecedorxusuarios' => [
                ['Ajuda — Usuários do fornecedor', 'Help — Supplier users', 'Ayuda — Usuarios del proveedor'],
                ['<p>Liga ao fornecedor os usuários que respondem por ele (Fornecedor x Usuários).</p>'
                    . '<ul><li>Usuários com perfil <strong>FORNECEDOR</strong> ou <strong>FORNECEDOR_GESTOR</strong> só veem os fornecedores ligados a eles (e só os ativos).</li>'
                    . '<li>FORNECEDOR edita nos documentos a validade, o protocolo, os dias de reação, a observação do fornecedor e as evidências; não vê Certificados, Auditoria, Produtos químicos, Equipamentos e Plano de ação.</li>'
                    . '<li>FORNECEDOR_GESTOR edita os documentos como a empresa.</li>'
                    . '<li>Só usuários ligados aqui podem ser escolhidos como <em>Responsável do fornecedor</em> nos documentos e recebem as pendências na Home.</li></ul>',
                 '<p>Links to the supplier the users who answer for it (Supplier x Users).</p>'
                    . '<ul><li>Users with the <strong>FORNECEDOR</strong> or <strong>FORNECEDOR_GESTOR</strong> profile only see the suppliers linked to them (active ones only).</li>'
                    . '<li>FORNECEDOR edits in the documents the expiry, protocol, reaction days, supplier note and evidence; does not see Certificates, Audit, Chemical products, Equipment and Action plan.</li>'
                    . '<li>FORNECEDOR_GESTOR edits the documents like the company.</li>'
                    . '<li>Only users linked here can be chosen as <em>Supplier owner</em> in the documents and get the pending items on the Home.</li></ul>',
                 '<p>Vincula al proveedor los usuarios que responden por él (Proveedor x Usuarios).</p>'
                    . '<ul><li>Los usuarios con perfil <strong>FORNECEDOR</strong> o <strong>FORNECEDOR_GESTOR</strong> solo ven los proveedores vinculados a ellos (solo los activos).</li>'
                    . '<li>FORNECEDOR edita en los documentos el vencimiento, el protocolo, los días de reacción, la observación del proveedor y las evidencias; no ve Certificados, Auditoría, Productos químicos, Equipos y Plan de acción.</li>'
                    . '<li>FORNECEDOR_GESTOR edita los documentos como la empresa.</li>'
                    . '<li>Solo los usuarios vinculados aquí pueden elegirse como <em>Responsable del proveedor</em> en los documentos y reciben los pendientes en el Inicio.</li></ul>'],
            ],
        ];
        foreach ($ajudas as $transacao => $ajuda) {
            $existe = (int)$valor($universal, 'SELECT COUNT(*) FROM ajuda_transacoes WHERE transacao = ?', [$transacao]);
            $universal->prepare($existe
                ? 'UPDATE ajuda_transacoes SET titulo_pt = ?, titulo_en = ?, titulo_es = ?, texto_pt = ?, texto_en = ?, texto_es = ?, ativo = 1 WHERE transacao = ?'
                : 'INSERT INTO ajuda_transacoes (titulo_pt, titulo_en, titulo_es, texto_pt, texto_en, texto_es, ativo, transacao) VALUES (?, ?, ?, ?, ?, ?, 1, ?)')
                ->execute([...$ajuda[0], ...$ajuda[1], $transacao]);
            $relatorio['ajuda'][$transacao] = $existe ? 'atualizada' : 'incluída';
        }
    }
} catch (Throwable $e) { $relatorio['ok'] = false; $relatorio['menu_universal'] = 'ERRO: ' . $e->getMessage(); }

$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_296_fornecedores_desativados_externo' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
