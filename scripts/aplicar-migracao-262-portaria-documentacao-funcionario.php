<?php
declare(strict_types=1);

/*
 * Migração 262 — Portaria › Documentação de Funcionário (principal/portaria_documentacaofuncionario.php).
 * Legado: foFuncionariosXCriterios (hpr_configuracoes, codtransacao 195). Tela somente de consulta, com envio de
 * evidências no GED (E:\projetoGreen\GED\green_<empresa>\fo_funcionarioxtiposxcriterios\<codigo>) e análise por IA.
 * Pré-requisito: migração 260 (vw_portaria_usuario_unidades).
 *
 * Em cada banco de cliente (gatlas_dbp, gmodelo_dbp, gmodelo2_dbp):
 *  1. views vw_portaria_documentacao_funcionario_grid (com SITUACAO_COR) e _acesso (restrição por unidade),
 *     procedure sp_portaria_docfuncionario_permitido, tabela fo_funcionarioxtiposxcriterios_documentos_ia e
 *     procedures sp_docfuncionario_ia_* (database/262_portaria_documentacao_funcionario.sql);
 *  2. menu PORTARIA_DOCUMENTACAO_FUNCIONARIO aponta para /principal/portaria_documentacaofuncionario.php;
 *  3. registra em registros_migracao (PORTARIA_DOCUMENTACAO_FUNCIONARIO_262).
 * No universal: URL do menu e ajuda da tela. A análise por IA usa o prompt FUNCIONARIO_DOCUMENTO (migração 221).
 * Por último, a migração 247:
 *   PORTARIA                     → visualizar, exportar, ver arquivos, enviar arquivos
 *   HOMOLOGACAO, ADM_DA_EMPRESA  → os mesmos + excluir arquivos + editar (= usar a IA e atualizar o critério)
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-262-portaria-documentacao-funcionario.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-262-portaria-documentacao-funcionario.php
 * Revisão c566 (migração 350, decisão de 24/10/2026): cores pela regra única do Painel e "Fornecedor bloqueado (não liberado)";
 *   a ajuda passa a descrever essas cores. A 350 roda esta migração com --sem-permissoes.
 * Opções: --sem-permissoes. Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

define('M262_BANCOS', HomologacaoBancos::lista($argv ?? [])); // classes/HomologacaoBancos.php (--banco= para só alguns)
const M262_MENU = 'PORTARIA_DOCUMENTACAO_FUNCIONARIO';
const M262_URL = '/principal/portaria_documentacaofuncionario.php';

$simular = in_array('--simular', $argv ?? [], true);
$semPermissoes = in_array('--sem-permissoes', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$sql = (string)preg_replace('/^--(?! @@).*$/m', '', (string)file_get_contents(dirname(__DIR__) . '/database/262_portaria_documentacao_funcionario.sql'));
$um = static function (PDO $pdo, string $q, array $p = []): mixed { $s = $pdo->prepare($q); $s->execute($p); $v = $s->fetchColumn(); $s->closeCursor(); return $v; };
$todos = static function (PDO $pdo, string $q, array $p = []): array { $s = $pdo->prepare($q); $s->execute($p); $v = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $v; };

$relatorio = ['ok' => true, 'migracao' => '262', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
foreach (M262_BANCOS as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (!(int)$um($pdo, "SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vw_portaria_usuario_unidades'")) throw new RuntimeException('rode antes a migração 260');
        $info['registros_tabela'] = (int)$um($pdo, 'SELECT COUNT(*) FROM fo_funcionarioxtiposxcriterios');
        $info['prompt_FUNCIONARIO_DOCUMENTO'] = (int)$um($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ia_prompts'")
            && (int)$um($pdo, "SELECT COUNT(*) FROM ia_prompts WHERE codigo = 'FUNCIONARIO_DOCUMENTO'") ? 'existe' : 'NÃO EXISTE (rode a migração 221)';
        if (!$simular) {
            foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) { $cmd = trim($bloco); if ($cmd !== '') $pdo->exec($cmd); }
            $info['estrutura'] = 'views, tabela e procedures gravadas';
            $info['linhas_no_grid'] = (int)$um($pdo, 'SELECT COUNT(*) FROM vw_portaria_documentacao_funcionario_grid');
            $info['cores'] = array_column($todos($pdo, 'SELECT SITUACAO_COR c, COUNT(*) n FROM vw_portaria_documentacao_funcionario_grid GROUP BY SITUACAO_COR ORDER BY n DESC'), 'n', 'c');
            $rep = (int)$um($pdo, 'SELECT COUNT(*) FROM (SELECT CODIGO FROM vw_portaria_documentacao_funcionario_grid GROUP BY CODIGO HAVING COUNT(*) > 1) x');
            if ($rep) { $relatorio['ok'] = false; $info['erro_repetidos'] = "$rep códigos repetidos no grid"; }
        }
        $menu = $todos($pdo, 'SELECT codmenu, url FROM menus WHERE UPPER(codigo) = ?', [M262_MENU]);
        if (!$menu) $info['menu'] = 'NÃO EXISTE no cliente (rode a 257 para trazer do universal)';
        else {
            $info['menu'] = "codmenu {$menu[0]['codmenu']}, url atual " . ($menu[0]['url'] ?: '(vazia)');
            if (!$simular && $menu[0]['url'] !== M262_URL) { $pdo->prepare('UPDATE menus SET url = ? WHERE codmenu = ?')->execute([M262_URL, (int)$menu[0]['codmenu']]); $info['menu'] .= ' → ' . M262_URL; }
        }
        if (!$simular && (int)$um($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registros_migracao'")) {
            $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
                VALUES ('PORTARIA_DOCUMENTACAO_FUNCIONARIO_262', 'hpr_configuracoes.195 foFuncionariosXCriterios', ?, 'ESTRUTURA', '4.0.1', 'CONCLUIDA', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), quantidade_registros = VALUES(quantidade_registros), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
                ->execute(["$banco.vw_portaria_documentacao_funcionario_grid", (int)($info['linhas_no_grid'] ?? 0), json_encode($info, JSON_UNESCAPED_UNICODE)]);
        }
    } catch (Throwable $e) { $relatorio['ok'] = false; $info['erro'] = $e->getMessage(); }
    $relatorio['bancos'][$banco] = $info;
}

try {
    $u = GreenPortalDatabase::connect($env, $bancoUniversal, 'GUNIVERSAL');
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $menu = $todos($u, 'SELECT codmenu, url FROM menus WHERE UPPER(codigo) = ?', [M262_MENU]);
    $relatorio['universal']['menu'] = $menu ? "codmenu {$menu[0]['codmenu']}, url atual " . ($menu[0]['url'] ?: '(vazia)') : 'NÃO EXISTE';
    if ($menu && !$simular && $menu[0]['url'] !== M262_URL) { $u->prepare('UPDATE menus SET url = ? WHERE codmenu = ?')->execute([M262_URL, (int)$menu[0]['codmenu']]); $relatorio['universal']['menu'] .= ' → ' . M262_URL; }
    $titulo = ['Documentação de Funcionário', 'Employee Documentation', 'Documentación de Empleado'];
    $texto = [
        "Consulta dos critérios exigidos de cada funcionário dos fornecedores (ASO, treinamentos e demais documentos), com \"Atende ao critério?\", situação, validade e observações.\n\nA tela é somente de consulta. Na coluna Evidências, clique no botão para ver os arquivos e enviar novos. Quem tem autorização (Editar) pode analisar o arquivo com IA e, depois de revisar, atualizar o critério (validade, atende, situação e observação).\n\nCores da linha (mesma regra do Painel da homologação):\n- Vermelho escuro: fornecedor bloqueado no ranking (não liberado; os documentos não podem ser aprovados enquanto durar o bloqueio).\n- Vermelho: não atende, validade vencida ou pendente com o fornecedor.\n- Amarelo: pendente com a empresa, ou vence dentro dos dias de reação (sem dias de reação, 30 dias).\n- Verde: atende.\n- Cinza: desligado, afastado ou não se aplica (NAP).\n- Sem cor: sem informação.\n\nO ADMINISTRADOR vê todas as unidades; os demais perfis veem só os fornecedores das unidades do seu usuário.",
        "Lists the criteria required from each supplier employee (ASO, training and other documents), with \"Meets the criterion?\", status, expiration date and notes.\n\nThe screen is read-only. In the Evidence column, click the button to see the files and upload new ones. Authorized users (Edit) can analyze the file with AI and, after reviewing, update the criterion (expiration, meets, status and notes).\n\nRow colors (same rule as the approval Dashboard):\n- Dark red: supplier blocked in the ranking (not released; documents cannot be approved while the block lasts).\n- Red: does not meet, expired or pending with the supplier.\n- Yellow: pending with the company, or expires within the reaction days (no reaction days: 30 days).\n- Green: meets.\n- Gray: dismissed, on leave or not applicable (NAP).\n- No color: no information.\n\nADMINISTRATOR sees all units; other profiles see only suppliers from their user's units.",
        "Consulta de los criterios exigidos a cada empleado de los proveedores (ASO, capacitaciones y otros documentos), con \"¿Cumple el criterio?\", situación, validez y observaciones.\n\nLa pantalla es solo de consulta. En la columna Evidencias, haga clic en el botón para ver los archivos y enviar nuevos. Quien tiene autorización (Editar) puede analizar el archivo con IA y, después de revisar, actualizar el criterio (validez, cumple, situación y observación).\n\nColores de la fila (misma regla del Panel de la homologación):\n- Rojo oscuro: proveedor bloqueado en el ranking (no liberado; los documentos no pueden aprobarse mientras dure el bloqueo).\n- Rojo: no cumple, validez vencida o pendiente con el proveedor.\n- Amarillo: pendiente con la empresa, o vence dentro de los días de reacción (sin días de reacción, 30 días).\n- Verde: cumple.\n- Gris: desvinculado, con licencia o no aplica (NAP).\n- Sin color: sin información.\n\nEl ADMINISTRADOR ve todas las unidades; los demás perfiles solo los proveedores de las unidades de su usuario.",
    ];
    if ((int)$um($u, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ajuda_transacoes'")) {
        $existe = (int)$um($u, 'SELECT COUNT(*) FROM ajuda_transacoes WHERE transacao = ?', ['portaria_documentacaofuncionario']);
        if (!$simular) $u->prepare($existe
            ? 'UPDATE ajuda_transacoes SET titulo_pt = ?, titulo_en = ?, titulo_es = ?, texto_pt = ?, texto_en = ?, texto_es = ?, ativo = 1 WHERE transacao = ?'
            : 'INSERT INTO ajuda_transacoes (titulo_pt, titulo_en, titulo_es, texto_pt, texto_en, texto_es, ativo, transacao) VALUES (?, ?, ?, ?, ?, ?, 1, ?)')
            ->execute([...$titulo, ...$texto, 'portaria_documentacaofuncionario']);
        $relatorio['universal']['ajuda'] = $existe ? 'atualizada' : 'incluída';
    }
} catch (Throwable $e) { $relatorio['ok'] = false; $relatorio['universal']['erro'] = $e->getMessage(); }

$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents(dirname(__DIR__) . '/storage/logs/migracao_262_portaria_documentacao_funcionario' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;

if (!$semPermissoes) {
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'aplicar-migracao-247-permissoes-transacao.php';
    foreach (['PORTARIA' => 'visualizar,exportar,arquivos,importar', 'HOMOLOGACAO,ADM_DA_EMPRESA' => 'visualizar,exportar,arquivos,importar,excluir,editar'] as $perfis => $acoes) {
        echo PHP_EOL . "== Permissões: $perfis ($acoes) ==" . PHP_EOL;
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --transacao=' . M262_MENU . " --perfis=$perfis --acoes=$acoes" . ' --banco=' . implode(',', M262_BANCOS) . ($simular ? ' --simular' : ''), $rc); // só os bancos desta migração
        if ($rc !== 0) $relatorio['ok'] = false;
    }
}
exit($relatorio['ok'] ? 0 : 1);
