<?php
declare(strict_types=1);

/*
 * Migração 260 — Portaria › Documentação de Fornecedor (principal/portaria_documentacaofornecedor.php).
 * Legado: foFornecedorxCriterios (hpr_configuracoes, codtransacao 194). Tela somente de consulta, com envio
 * de evidências (arquivos) no GED: E:\projetoGreen\GED\green_<empresa>\fo_fornecedorxcriterios\<código>.
 *
 * Em cada banco de cliente (gatlas_dbp, gmodelo_dbp, gmodelo2_dbp se existir):
 *  1. views vw_portaria_usuario_unidades, vw_portaria_documentacao_fornecedor_grid (com SITUACAO_COR) e
 *     vw_portaria_documentacao_fornecedor_acesso, e a procedure sp_portaria_docfornecedor_permitido
 *     (database/260_portaria_documentacao_fornecedor.sql; colunas conferidas em cada banco);
 *  2. menu PORTARIA_DOCUMENTACAO_FORNECEDOR aponta para /principal/portaria_documentacaofornecedor.php
 *     (no catálogo universal e no cliente);
 *  3. registra em registros_migracao (PORTARIA_DOCUMENTACAO_FORNECEDOR_260).
 * No banco universal: ajuda da tela (ajuda_transacoes).
 * Por último chama a migração 247 para liberar a tela:
 *   PORTARIA                     → visualizar, exportar, ver arquivos, enviar arquivos
 *   HOMOLOGACAO, ADM_DA_EMPRESA  → os mesmos + excluir arquivos
 * (ADMINISTRADOR já tem acesso por regra e vê todas as unidades; os demais só as unidades do usuário.)
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-260-portaria-documentacao-fornecedor.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-260-portaria-documentacao-fornecedor.php
 * Revisão c566 (migração 350, decisão de 24/10/2026): cores pela regra única do Painel e "Fornecedor bloqueado (não liberado)";
 *   a ajuda passa a descrever essas cores. A 350 roda esta migração com --sem-permissoes.
 * Opção: --sem-permissoes (não chama a 247). Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

define('M260_BANCOS', HomologacaoBancos::lista($argv ?? [])); // classes/HomologacaoBancos.php (--banco= para só alguns)
const M260_MENU = 'PORTARIA_DOCUMENTACAO_FORNECEDOR';
const M260_URL = '/principal/portaria_documentacaofornecedor.php';

$simular = in_array('--simular', $argv ?? [], true);
$semPermissoes = in_array('--sem-permissoes', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$geral->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$template = (string)file_get_contents(dirname(__DIR__) . '/database/260_portaria_documentacao_fornecedor.sql');
// Comentários saem antes da troca dos marcadores (o cabeçalho cita os marcadores; a troca por várias linhas quebraria o comentário).
$template = (string)preg_replace('/^--(?! @@).*$/m', '', $template);

$linhas = static function (PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$valor = static function (PDO $pdo, string $sql, array $p = []) use ($linhas): mixed { $r = $linhas($pdo, $sql, $p); return $r ? array_values($r[0])[0] : null; };
$tabela = static fn(string $b, string $t): bool => (bool)$valor($geral, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'", [$b, $t]);
$coluna = static function (string $b, string $t, array $opcoes) use ($valor, $geral): ?string {
    foreach ($opcoes as $c) { $r = $valor($geral, 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND LOWER(COLUMN_NAME) = LOWER(?)', [$b, $t, $c]); if ($r !== null) return (string)$r; }
    return null;
};
$q = static fn(string $n): string => '`' . str_replace('`', '``', $n) . '`';

$relatorio = ['ok' => true, 'migracao' => '260', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];

foreach (M260_BANCOS as $banco) {
    $info = [];
    try {
        if (!(int)$valor($geral, 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$banco])) { $info['aviso'] = 'banco não existe'; $relatorio['bancos'][$banco] = $info; continue; }
        foreach (['fo_fornecedorxcriterios', 'fo_fornecedores', 'unidades', 'fo_tipofornecedores', 'fo_criteriofornecedores', 'fo_fornecedoresxtipofornecedores', 'fo_tipofornecedoresxcriteriofornecedores', 'usuarios'] as $t) {
            if (!$tabela($banco, $t)) throw new RuntimeException("tabela $t não existe");
        }
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Descrição da unidade
        $dscUnidade = $coluna($banco, 'unidades', ['dscunidade', 'descricao', 'nome', 'dsc_unidade']);
        $exprUnidade = $dscUnidade ? 'u.' . $q($dscUnidade) : 'CAST(u.codunidade AS CHAR)';
        // Nível de exigência: vínculo tipo x critério, senão o critério
        $exprNivel = 'NULL'; $origemNivel = 'não existe (coluna vazia)';
        if ($c = $coluna($banco, 'fo_tipofornecedoresxcriteriofornecedores', ['nivel_de_exigencia', 'nivelexigencia', 'nivel_exigencia'])) {
            $exprNivel = "(SELECT MAX(tn.{$q($c)}) FROM `fo_tipofornecedoresxcriteriofornecedores` tn WHERE tn.codtipofornecedor = fc.codtipo AND tn.codcriteriofornecedor = fc.codcriterio)";
            $origemNivel = "fo_tipofornecedoresxcriteriofornecedores.$c";
        } elseif ($c = $coluna($banco, 'fo_criteriofornecedores', ['nivel_de_exigencia', 'nivelexigencia', 'nivel_exigencia'])) {
            $exprNivel = 'c.' . $q($c); $origemNivel = "fo_criteriofornecedores.$c";
        }
        // Responsável
        $nomeUsuario = $coluna($banco, 'usuarios', ['nome', 'nome_usuario', 'nomeusuario', 'login']);
        $exprResp = $nomeUsuario ? "COALESCE(NULLIF(TRIM(us.{$q($nomeUsuario)}), ''), CAST(fc.codusuario AS CHAR))" : 'CAST(fc.codusuario AS CHAR)';
        $joinUsuario = $nomeUsuario ? 'LEFT JOIN `usuarios` us ON us.codusuario = fc.codusuario' : '';
        // Unidades do usuário
        $partes = ['SELECT us2.codusuario AS CODUSUARIO, us2.codunidade AS CODUNIDADE FROM `usuarios` us2 WHERE us2.codunidade IS NOT NULL AND us2.codunidade > 0'];
        $origemUnidades = ['usuarios.codunidade'];
        if ($tabela($banco, 'usuariosxunidades') && ($cu = $coluna($banco, 'usuariosxunidades', ['codusuario'])) && ($cun = $coluna($banco, 'usuariosxunidades', ['codunidade']))) {
            array_unshift($partes, "SELECT x.{$q($cu)} AS CODUSUARIO, x.{$q($cun)} AS CODUNIDADE FROM `usuariosxunidades` x");
            array_unshift($origemUnidades, 'usuariosxunidades');
        }
        if (!$coluna($banco, 'usuarios', ['codunidade'])) { array_pop($partes); array_pop($origemUnidades); }
        if (!$partes) {
            // Sem fonte de unidade: a view fica vazia (só o ADMINISTRADOR vê documentos neste cliente).
            $partes = ['SELECT CAST(NULL AS SIGNED) AS CODUSUARIO, CAST(NULL AS SIGNED) AS CODUNIDADE FROM DUAL WHERE 1 = 0'];
            $origemUnidades = ['NENHUMA (sem usuariosxunidades e sem usuarios.codunidade): só o ADMINISTRADOR vê documentos'];
            $info['aviso'] = 'sem unidades de usuário neste cliente; os perfis diferentes de ADMINISTRADOR não verão documentos';
        }
        $info['colunas'] = ['unidade' => $dscUnidade ?? '(código)', 'nivel_de_exigencia' => $origemNivel, 'responsavel' => $nomeUsuario ? "usuarios.$nomeUsuario" : '(código)', 'unidades_do_usuario' => implode(' + ', $origemUnidades)];

        $sql = strtr($template, [
            '{{DSCUNIDADE}}' => $exprUnidade, '{{NIVEL}}' => $exprNivel, '{{JOIN_NIVEL}}' => '',
            '{{RESPONSAVEL}}' => $exprResp, '{{JOIN_USUARIO}}' => $joinUsuario,
            '{{USUARIO_UNIDADES}}' => implode("\nUNION\n", $partes),
        ]);
        $info['registros_tabela'] = (int)$valor($pdo, 'SELECT COUNT(*) FROM fo_fornecedorxcriterios');
        if (!$simular) {
            foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) {
                $cmd = trim(preg_replace('/^--.*$/m', '', $bloco));
                if ($cmd !== '') $pdo->exec($cmd);
            }
            $info['estrutura'] = 'views e procedure gravadas';
            $info['linhas_no_grid'] = (int)$valor($pdo, 'SELECT COUNT(*) FROM vw_portaria_documentacao_fornecedor_grid');
            $info['cores'] = array_column($linhas($pdo, 'SELECT SITUACAO_COR c, COUNT(*) n FROM vw_portaria_documentacao_fornecedor_grid GROUP BY SITUACAO_COR ORDER BY n DESC'), 'n', 'c');
            $info['usuarios_com_unidade'] = (int)$valor($pdo, 'SELECT COUNT(DISTINCT CODUSUARIO) FROM vw_portaria_usuario_unidades');
            $repetidos = (int)$valor($pdo, 'SELECT COUNT(*) FROM (SELECT CODFORNECEDORXCRITERIOS FROM vw_portaria_documentacao_fornecedor_grid GROUP BY CODFORNECEDORXCRITERIOS HAVING COUNT(*) > 1) x');
            if ($repetidos) { $relatorio['ok'] = false; $info['erro_repetidos'] = "$repetidos códigos repetidos no grid"; }
        }

        // Menu do cliente
        $menu = $linhas($pdo, 'SELECT codmenu, url FROM menus WHERE UPPER(codigo) = ?', [M260_MENU]);
        if (!$menu) $info['menu'] = 'NÃO EXISTE no cliente (rode a 257 para trazer do universal)';
        else {
            $info['menu'] = "codmenu {$menu[0]['codmenu']}, url atual " . ($menu[0]['url'] ?: '(vazia)');
            if (!$simular && $menu[0]['url'] !== M260_URL) { $pdo->prepare('UPDATE menus SET url = ? WHERE codmenu = ?')->execute([M260_URL, (int)$menu[0]['codmenu']]); $info['menu'] .= ' → ' . M260_URL; }
        }
        if (!$simular && $tabela($banco, 'registros_migracao')) {
            $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
                VALUES ('PORTARIA_DOCUMENTACAO_FORNECEDOR_260', 'hpr_configuracoes.194 foFornecedorxCriterios', ?, 'ESTRUTURA', '4.0.1', 'CONCLUIDA', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), quantidade_registros = VALUES(quantidade_registros), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
                ->execute(["$banco.vw_portaria_documentacao_fornecedor_grid", (int)($info['linhas_no_grid'] ?? 0), json_encode($info, JSON_UNESCAPED_UNICODE)]);
        }
    } catch (Throwable $e) {
        $relatorio['ok'] = false;
        $info['erro'] = $e->getMessage();
    }
    $relatorio['bancos'][$banco] = $info;
}

// Universal: menu e ajuda
try {
    $u = GreenPortalDatabase::connect($env, $bancoUniversal, 'GUNIVERSAL');
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $menu = $linhas($u, 'SELECT codmenu, url FROM menus WHERE UPPER(codigo) = ?', [M260_MENU]);
    $relatorio['universal']['menu'] = $menu ? "codmenu {$menu[0]['codmenu']}, url atual " . ($menu[0]['url'] ?: '(vazia)') : 'NÃO EXISTE';
    if ($menu && !$simular && $menu[0]['url'] !== M260_URL) { $u->prepare('UPDATE menus SET url = ? WHERE codmenu = ?')->execute([M260_URL, (int)$menu[0]['codmenu']]); $relatorio['universal']['menu'] .= ' → ' . M260_URL; }
    $ajuda = [
        'titulo' => ['Documentação de Fornecedor', 'Supplier Documentation', 'Documentación de Proveedor'],
        'texto' => [
            "Consulta dos documentos exigidos de cada fornecedor (critérios do tipo de fornecedor), com a situação, a validade e as observações da empresa e do fornecedor.\n\nA tela é somente de consulta. Na coluna Evidências, clique no botão para ver os arquivos e enviar novos.\n\nCores da linha (mesma regra do Painel da homologação):\n- Vermelho escuro: fornecedor bloqueado no ranking (não liberado; os documentos não podem ser aprovados enquanto durar o bloqueio).\n- Vermelho: reprovado, validade vencida ou pendente com o fornecedor.\n- Amarelo: pendente com a empresa, ou vence dentro dos dias de reação do documento (sem dias de reação, 30 dias).\n- Verde: aprovado, aprovado sem validade ou ciente da regra.\n- Cinza: não se aplica (NA, não enviar ao fornecedor).\n- Sem cor: sem situação informada.\n\nO ADMINISTRADOR vê todas as unidades; os demais perfis veem só os fornecedores das unidades do seu usuário.",
            "Lists the documents required from each supplier (criteria of the supplier type), with status, expiration date and the company's and supplier's notes.\n\nThe screen is read-only. In the Evidence column, click the button to see the files and upload new ones.\n\nRow colors (same rule as the approval Dashboard):\n- Dark red: supplier blocked in the ranking (not released; documents cannot be approved while the block lasts).\n- Red: rejected, expired or pending with the supplier.\n- Yellow: pending with the company, or expires within the document's reaction days (no reaction days: 30 days).\n- Green: approved, approved without expiration or rule acknowledged.\n- Gray: not applicable (NA, do not send to supplier).\n- No color: no status.\n\nADMINISTRATOR sees all units; other profiles see only suppliers from their user's units.",
            "Consulta de los documentos exigidos a cada proveedor (criterios del tipo de proveedor), con la situación, la validez y las observaciones de la empresa y del proveedor.\n\nLa pantalla es solo de consulta. En la columna Evidencias, haga clic en el botón para ver los archivos y enviar nuevos.\n\nColores de la fila (misma regla del Panel de la homologación):\n- Rojo oscuro: proveedor bloqueado en el ranking (no liberado; los documentos no pueden aprobarse mientras dure el bloqueo).\n- Rojo: reprobado, validez vencida o pendiente con el proveedor.\n- Amarillo: pendiente con la empresa, o vence dentro de los días de reacción del documento (sin días de reacción, 30 días).\n- Verde: aprobado, aprobado sin validez o consciente de la regla.\n- Gris: no aplica (NA, no enviar al proveedor).\n- Sin color: sin situación.\n\nEl ADMINISTRADOR ve todas las unidades; los demás perfiles solo los proveedores de las unidades de su usuario.",
        ],
    ];
    if ($tabela($bancoUniversal, 'ajuda_transacoes')) {
        $existe = (int)$valor($u, 'SELECT COUNT(*) FROM ajuda_transacoes WHERE transacao = ?', ['portaria_documentacaofornecedor']);
        if (!$simular) {
            $u->prepare($existe
                ? 'UPDATE ajuda_transacoes SET titulo_pt = ?, titulo_en = ?, titulo_es = ?, texto_pt = ?, texto_en = ?, texto_es = ?, ativo = 1 WHERE transacao = ?'
                : 'INSERT INTO ajuda_transacoes (titulo_pt, titulo_en, titulo_es, texto_pt, texto_en, texto_es, ativo, transacao) VALUES (?, ?, ?, ?, ?, ?, 1, ?)')
                ->execute([...$ajuda['titulo'], ...$ajuda['texto'], 'portaria_documentacaofornecedor']);
        }
        $relatorio['universal']['ajuda'] = $existe ? 'atualizada' : 'incluída';
    }
} catch (Throwable $e) { $relatorio['ok'] = false; $relatorio['universal']['erro'] = $e->getMessage(); }

$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents(dirname(__DIR__) . '/storage/logs/migracao_260_portaria_documentacao_fornecedor' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;

// Permissões (migração 247)
if (!$semPermissoes) {
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'aplicar-migracao-247-permissoes-transacao.php';
    foreach (['PORTARIA' => 'visualizar,exportar,arquivos,importar', 'HOMOLOGACAO,ADM_DA_EMPRESA' => 'visualizar,exportar,arquivos,importar,excluir'] as $perfis => $acoes) {
        echo PHP_EOL . "== Permissões: $perfis ($acoes) ==" . PHP_EOL;
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --transacao=' . M260_MENU . " --perfis=$perfis --acoes=$acoes" . ' --banco=' . implode(',', M260_BANCOS) . ($simular ? ' --simular' : ''), $rc); // só os bancos desta migração
        if ($rc !== 0) $relatorio['ok'] = false;
    }
}
exit($relatorio['ok'] ? 0 : 1);
