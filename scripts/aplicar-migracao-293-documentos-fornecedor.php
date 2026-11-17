<?php
declare(strict_types=1);

/*
 * Migração 293 — Homologação de Fornecedores, etapa 2: aba Documentos do fornecedor completa
 * (tela principal/homologacaofornecedor_fornecedorxcriterios.php, aberta pelo botão "Documentos" da Gestão de Fornecedores)
 * e Tipos do fornecedor (principal/homologacaofornecedor_fornecedorxtipos.php).
 *
 * Em cada banco de cliente (gmodelo_dbp, gatlas_dbp, gmodelo2_dbp se existir):
 *  1. confere as tabelas e informa documentos repetidos (fornecedor + tipo + critério) — só informa, não apaga;
 *  2. índice do histórico por documento e data (consulta do histórico);
 *  3. views, procedures e gatilhos de database/293_documentos_fornecedor.sql;
 *  4. gera os documentos que faltam para TODOS os tipos de cada fornecedor (o legado gerava só do primeiro tipo);
 *  5. ajuda da tela (banco universal) e registro em registros_migracao (HOMOLOGACAO_DOCUMENTOS_FORNECEDOR_293).
 * O sincronismo de FO deixa de sincronizar documentos, histórico e tipos dos fornecedores (SINC_MESTRE_NOVO).
 * Acesso: o mesmo da Gestão de Fornecedores (HOMOLOGACAO_FORNECEDOR_FORNECEDOR): visualizar = ver; editar = gravar,
 * copiar/colar, gerar e tipos; excluir = reiniciar documento.
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-293-documentos-fornecedor.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-293-documentos-fornecedor.php
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

define('M293_BANCOS', HomologacaoBancos::lista($argv ?? [])); // classes/HomologacaoBancos.php (--banco= para só alguns)
const M293_TABELAS = ['fo_fornecedores', 'fo_fornecedorxcriterios', 'fo_fornecedorxcriterios_historico', 'fo_fornecedoresxtipofornecedores',
    'fo_tipofornecedores', 'fo_criteriofornecedores', 'fo_tipofornecedoresxcriteriofornecedores', 'fo_fornecedorxusuario', 'usuarios'];

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$template = (string)file_get_contents(dirname(__DIR__) . '/database/293_documentos_fornecedor.sql');
$linhas = static function (PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$valor = static function (PDO $pdo, string $sql, array $p = []) use ($linhas): mixed { $r = $linhas($pdo, $sql, $p); return $r ? array_values($r[0])[0] : null; };
$existeTabela = static fn(string $b, string $t): bool => (bool)$valor($geral, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'", [$b, $t]);

$relatorio = ['ok' => true, 'migracao' => '293', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
foreach (M293_BANCOS as $banco) {
    $info = [];
    if (!$valor($geral, 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$banco])) {
        if ($banco !== 'gmodelo2_dbp') { $relatorio['ok'] = false; $relatorio['bancos'][$banco] = ['erro' => 'banco não existe']; }
        continue;
    }
    try {
        foreach (M293_TABELAS as $t) if (!$existeTabela($banco, $t)) throw new RuntimeException("tabela $t não existe");
        if (!$valor($geral, "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? AND ROUTINE_NAME = 'sp_fo_tipofornecedor_vinculos_listar'", [$banco]))
            throw new RuntimeException('rode antes a migração 292 (tipos e critérios dos fornecedores)');
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');

        $info['documentos'] = (int)$valor($pdo, 'SELECT COUNT(*) FROM fo_fornecedorxcriterios');
        $info['historico'] = (int)$valor($pdo, 'SELECT COUNT(*) FROM fo_fornecedorxcriterios_historico');
        $info['repetidos_so_informados'] = (int)$valor($pdo, 'SELECT COALESCE(SUM(q - 1), 0) FROM (SELECT COUNT(*) q FROM fo_fornecedorxcriterios GROUP BY codfornecedor, codtipo, codcriterio HAVING COUNT(*) > 1) r');
        $info['a_gerar'] = (int)$valor($pdo, 'SELECT COUNT(*) FROM (SELECT DISTINCT ft.codfornecedor, ft.codtipofornecedor, tc.codcriteriofornecedor FROM fo_fornecedoresxtipofornecedores ft
            JOIN fo_tipofornecedoresxcriteriofornecedores tc ON tc.codtipofornecedor = ft.codtipofornecedor JOIN fo_fornecedores f ON f.codfornecedor = ft.codfornecedor
            WHERE NOT EXISTS (SELECT 1 FROM fo_fornecedorxcriterios x WHERE x.codfornecedor = ft.codfornecedor AND x.codtipo = ft.codtipofornecedor AND x.codcriterio = tc.codcriteriofornecedor)) g');
        $info['fornecedores_com_mais_de_um_tipo'] = (int)$valor($pdo, 'SELECT COUNT(*) FROM (SELECT codfornecedor FROM fo_fornecedoresxtipofornecedores GROUP BY codfornecedor HAVING COUNT(DISTINCT codtipofornecedor) > 1) m');
        $info['situacoes_fora_da_lista'] = $linhas($pdo, "SELECT COALESCE(situacao, '(vazio)') AS situacao, COUNT(*) AS qtd FROM fo_fornecedorxcriterios
            WHERE COALESCE(situacao, '') NOT IN ('', 'Aprovado', 'Aprovado sem validade', 'Ciente da regra', 'Reprovado', 'Pendente com a empresa', 'Pendente com o fornecedor', 'Não enviar ao fornecedor', 'NA')
            GROUP BY 1");
        $temIdx = (bool)$valor($geral, "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'fo_fornecedorxcriterios_historico' AND INDEX_NAME = 'ix_fo_docs_hist_doc_data'", [$banco]);
        $info['indice_historico'] = $temIdx ? 'já existe' : ($simular ? 'será criado' : '');
        if (!$simular) {
            if (!$temIdx) { $pdo->exec('ALTER TABLE fo_fornecedorxcriterios_historico ADD KEY ix_fo_docs_hist_doc_data (codfornecedorxcriterios, DATAALTERACAO)'); $info['indice_historico'] = 'criado'; }
            $n = 0;
            foreach (preg_split('/^-- @@\s*$/m', $template) as $bloco) {
                $limpo = trim(preg_replace('/^\s*--.*$/m', '', $bloco) ?? '');
                if ($limpo === '') continue;
                $pdo->exec($limpo); $n++;
            }
            $info['objetos'] = ['comandos' => $n, 'views' => ['vw_homologacao_fornecedor_documentos', 'vw_homologacao_fornecedor_documentos_historico', 'vw_homologacao_fornecedor_usuarios'],
                'procedures' => ['sp_fo_fornecedor_documentos_gerar', 'sp_fo_fornecedor_documento_salvar', 'sp_fo_fornecedor_documento_copiar', 'sp_fo_fornecedor_documento_reiniciar',
                    'sp_fo_fornecedor_documento_historico_gravar', 'sp_fo_fornecedor_tipos_listar', 'sp_fo_fornecedor_tipos_gravar'],
                'gatilhos' => ['trg_fo_fornecedoresxtipo_docs_ai', 'trg_fo_tipoxcriterio_docs_ai']];
            $s = $pdo->prepare('CALL sp_fo_fornecedor_documentos_gerar(NULL)'); $s->execute(); $info['gerados_agora'] = (int)($s->fetchColumn() ?: 0); $s->closeCursor();
            $info['documentos_na_aba'] = (int)$valor($pdo, 'SELECT COUNT(*) FROM vw_homologacao_fornecedor_documentos');
            $info['por_cor'] = $linhas($pdo, 'SELECT COR, COUNT(*) AS qtd FROM vw_homologacao_fornecedor_documentos GROUP BY COR ORDER BY 2 DESC');
            if ($existeTabela($banco, 'registros_migracao')) {
                $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
                    VALUES ('HOMOLOGACAO_DOCUMENTOS_FORNECEDOR_293', 'fo_fornecedorxcriterios', ?, 'ESTRUTURA', '4.0.1', 'CONCLUIDA', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
                    ON DUPLICATE KEY UPDATE status = VALUES(status), quantidade_registros = VALUES(quantidade_registros), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
                    ->execute(["$banco: documentos do fornecedor; views; procedures; gatilhos", (int)$info['gerados_agora'], json_encode($info, JSON_UNESCAPED_UNICODE)]);
            }
        }
    } catch (Throwable $e) {
        $relatorio['ok'] = false;
        $info['erro'] = $e->getMessage();
    }
    $relatorio['bancos'][$banco] = $info;
}

// Ajuda da tela (universal)
$ajuda = [
    'homologacaofornecedor_fornecedorxcriterios' => [
        'titulo' => ['Ajuda — Documentos do fornecedor', 'Help — Supplier documents', 'Ayuda — Documentos del proveedor'],
        'texto' => [
            '<p>Os <strong>documentos</strong> do fornecedor vêm dos critérios dos tipos dele (Tipos de Fornecedores). Um tipo novo no fornecedor ou um critério novo no tipo já criam o documento.</p>'
            . '<ul><li><strong>Dê dois cliques</strong> numa linha (ou use <em>Editar</em>) para preencher: enviado, responsável da empresa, responsável do fornecedor, validade, em protocolo, dias de reação, situação, incluído no contrato e observações. Reprovar exige a observação da empresa com o motivo.</li>'
            . '<li><strong>Em protocolo</strong>: Sim marca a cobrança para daqui a 30 dias; <em>Renovar</em> marca de novo a cobrança para daqui a 30 dias.</li>'
            . '<li><strong>Cores</strong>: vermelho = reprovado, vencido ou pendente com o fornecedor; amarelo = pendente com a empresa ou vence dentro dos dias de reação (30 se não informado); verde = aprovado / aprovado sem validade / ciente da regra; cinza = não se aplica ou não enviar; branco = sem situação. Clique numa cor da legenda para filtrar.</li>'
            . '<li><strong>Copiar / Colar</strong>: selecione o documento de origem e clique em Copiar; marque os documentos de destino e clique em Colar. Só os campos vazios do destino são preenchidos, e as evidências são copiadas sem substituir arquivos.</li>'
            . '<li><strong>Evidências</strong> abre os arquivos do documento. <strong>Histórico</strong> mostra todas as alterações (quem e quando). <strong>Reiniciar</strong> limpa os dados do documento (o histórico guarda o que havia).</li>'
            . '<li><strong>Tipos do fornecedor</strong> define os tipos; ao retirar um tipo, os documentos dele ficam guardados e voltam se o tipo for incluído de novo.</li></ul>'
            . '<p>O que cada perfil pode fazer é definido em Segurança › Permissões (Gestão de Fornecedores).</p>',
            '<p>The supplier\'s <strong>documents</strong> come from the criteria of its types (Supplier types). A new type on the supplier or a new criterion on the type already creates the document.</p>'
            . '<ul><li><strong>Double-click</strong> a row (or use <em>Edit</em>) to fill in: sent, company owner, supplier owner, expiry date, in protocol, reaction days, status, included in the contract and notes. Rejecting requires the company note with the reason.</li>'
            . '<li><strong>In protocol</strong>: Yes sets the follow-up 30 days ahead; <em>Renew</em> sets it again 30 days ahead.</li>'
            . '<li><strong>Colors</strong>: red = rejected, expired or pending with the supplier; yellow = pending with the company or expiring within the reaction days (30 if not set); green = approved / approved without expiry / aware of the rule; grey = not applicable or do not send; white = no status. Click a legend color to filter.</li>'
            . '<li><strong>Copy / Paste</strong>: select the source document and click Copy; check the target documents and click Paste. Only empty fields of the target are filled, and the evidence files are copied without replacing existing files.</li>'
            . '<li><strong>Evidence</strong> opens the document files. <strong>History</strong> shows every change (who and when). <strong>Reset</strong> clears the document data (the history keeps the previous data).</li>'
            . '<li><strong>Supplier types</strong> defines the types; removing a type keeps its documents, which come back if the type is added again.</li></ul>'
            . '<p>What each profile can do is defined in Security › Permissions (Supplier Management).</p>',
            '<p>Los <strong>documentos</strong> del proveedor vienen de los criterios de sus tipos (Tipos de proveedores). Un tipo nuevo en el proveedor o un criterio nuevo en el tipo ya crean el documento.</p>'
            . '<ul><li><strong>Haga doble clic</strong> en una fila (o use <em>Editar</em>) para completar: enviado, responsable de la empresa, responsable del proveedor, vencimiento, en protocolo, días de reacción, situación, incluido en el contrato y observaciones. Reprobar exige la observación de la empresa con el motivo.</li>'
            . '<li><strong>En protocolo</strong>: Sí programa el cobro para dentro de 30 días; <em>Renovar</em> lo programa de nuevo para dentro de 30 días.</li>'
            . '<li><strong>Colores</strong>: rojo = reprobado, vencido o pendiente con el proveedor; amarillo = pendiente con la empresa o vence dentro de los días de reacción (30 si no se informa); verde = aprobado / aprobado sin vencimiento / consciente de la regla; gris = no aplica o no enviar; blanco = sin situación. Haga clic en un color de la leyenda para filtrar.</li>'
            . '<li><strong>Copiar / Pegar</strong>: seleccione el documento de origen y haga clic en Copiar; marque los documentos de destino y haga clic en Pegar. Solo se completan los campos vacíos del destino, y las evidencias se copian sin reemplazar archivos.</li>'
            . '<li><strong>Evidencias</strong> abre los archivos del documento. <strong>Historial</strong> muestra todos los cambios (quién y cuándo). <strong>Reiniciar</strong> limpia los datos del documento (el historial guarda lo que había).</li>'
            . '<li><strong>Tipos del proveedor</strong> define los tipos; al quitar un tipo, sus documentos se guardan y vuelven si el tipo se incluye de nuevo.</li></ul>'
            . '<p>Lo que cada perfil puede hacer se define en Seguridad › Permisos (Gestión de Proveedores).</p>',
        ],
    ],
];
if (!$simular) {
    try {
        $universal = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
        $universal->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ($ajuda as $transacao => $a) {
            $existe = (int)$valor($universal, 'SELECT COUNT(*) FROM ajuda_transacoes WHERE transacao = ?', [$transacao]);
            $universal->prepare($existe
                ? 'UPDATE ajuda_transacoes SET titulo_pt = ?, titulo_en = ?, titulo_es = ?, texto_pt = ?, texto_en = ?, texto_es = ?, ativo = 1 WHERE transacao = ?'
                : 'INSERT INTO ajuda_transacoes (titulo_pt, titulo_en, titulo_es, texto_pt, texto_en, texto_es, ativo, transacao) VALUES (?, ?, ?, ?, ?, ?, 1, ?)')
                ->execute([...$a['titulo'], ...$a['texto'], $transacao]);
            $relatorio['ajuda'][$transacao] = $existe ? 'atualizada' : 'incluída';
        }
    } catch (Throwable $e) { $relatorio['ok'] = false; $relatorio['ajuda'] = 'ERRO: ' . $e->getMessage(); }
}

$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_293_documentos_fornecedor' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
