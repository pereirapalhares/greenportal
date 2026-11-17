<?php
declare(strict_types=1);

/*
 * Diagnóstico (SOMENTE LEITURA) da comparação legado × greenportal do módulo Homologação de Fornecedores.
 * Nada é alterado. Compara o banco do legado (padrão green_modelo) com o do greenportal (padrão gmodelo_dbp).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-comparacao-homologacao.php
 * Opções: --legado=green_modelo --novo=gmodelo_dbp --fornecedor=659
 * Saída: storage\logs\comparacao_homologacao.json e o mesmo JSON na tela.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$opcao = static function (string $nome, string $padrao) use ($argv): string {
    foreach ($argv ?? [] as $a) if (str_starts_with($a, "--$nome=")) return trim(substr($a, strlen($nome) + 3));
    return $padrao;
};
$legado = $opcao('legado', 'green_modelo');
$novo = $opcao('novo', 'gmodelo_dbp');
$fornecedor = (int)$opcao('fornecedor', '659');
foreach ([$legado, $novo] as $b) if (!preg_match('/^[A-Za-z0-9_]+$/', $b)) { fwrite(STDERR, "Banco inválido: $b\n"); exit(1); }

$env = GreenPortalDatabase::env();
$conectar = static function (string $banco) use ($env): PDO {
    $erro = '';
    foreach (['LEGADO_SEGURANCA', 'GPT'] as $prefixo) {
        if (empty($env[$prefixo . '_DB_HOST'])) continue;
        try { $p = GreenPortalDatabase::connect($env, $banco, $prefixo); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $p->exec('SET NAMES utf8mb4'); return $p; }
        catch (Throwable $e) { $erro = $e->getMessage(); }
    }
    throw new RuntimeException("sem conexão com $banco: $erro");
};
$linhas = static function (PDO $pdo, string $sql, array $p = []): array {
    try { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; }
    catch (Throwable $e) { return [['erro' => $e->getMessage()]]; }
};

$saida = ['banco_legado' => $legado, 'banco_novo' => $novo, 'fornecedor' => $fornecedor, 'gerado_em' => date('Y-m-d H:i:s')];
try {
    $L = $conectar($legado);
    $N = $conectar($novo);
    $docCols = 'codfornecedorxcriterios, codtipo, codcriterio, enviadoodocumento, codusuario, codusuario_fornecedor, datadevalidade, emprotocolo, datacobranca_emprotocolo, datadereacao, situacao, incluidocontrato, observacao, observacao_do_fornecedor';
    foreach (['legado' => $L, 'novo' => $N] as $lado => $pdo) {
        $saida[$lado]['fornecedor'] = $linhas($pdo, 'SELECT CODFORNECEDOR, CODUNIDADE, CNPJ_CPF, RAZAOSOCIAL, ATIVADOFOR, TRABALHA_INSTALACAO_FORNECEDOR, TEM_PRODUTOS_QUIMICOS, TEM_EQUIPAMENTOS FROM fo_fornecedores WHERE CODFORNECEDOR = ?', [$fornecedor]);
        $saida[$lado]['tipos'] = $linhas($pdo, 'SELECT codtipofornecedor FROM fo_fornecedoresxtipofornecedores WHERE codfornecedor = ?', [$fornecedor]);
        $saida[$lado]['documentos'] = $linhas($pdo, "SELECT $docCols FROM fo_fornecedorxcriterios WHERE codfornecedor = ? ORDER BY codfornecedorxcriterios", [$fornecedor]);
        $saida[$lado]['historico'] = $linhas($pdo, 'SELECT h.codfornecedorxcriterios, COUNT(*) AS n, MAX(h.DATAALTERACAO) AS ultima FROM fo_fornecedorxcriterios_historico h WHERE h.codfornecedor = ? GROUP BY h.codfornecedorxcriterios', [$fornecedor]);
        $saida[$lado]['situacoes_ativos'] = $linhas($pdo, "SELECT COALESCE(NULLIF(TRIM(c.situacao),''),'(vazia)') AS situacao, COUNT(*) AS n FROM fo_fornecedorxcriterios c JOIN fo_fornecedores f ON f.CODFORNECEDOR = c.codfornecedor AND TRIM(COALESCE(f.ATIVADOFOR,'')) = 'Sim' GROUP BY 1 ORDER BY 2 DESC");
        $saida[$lado]['totais'] = $linhas($pdo, "SELECT (SELECT COUNT(*) FROM fo_fornecedores) AS fornecedores, (SELECT COUNT(*) FROM fo_fornecedores WHERE TRIM(COALESCE(ATIVADOFOR,''))='Sim') AS ativos, (SELECT COUNT(DISTINCT c.codfornecedor) FROM fo_fornecedorxcriterios c JOIN fo_fornecedores f ON f.CODFORNECEDOR=c.codfornecedor AND TRIM(COALESCE(f.ATIVADOFOR,''))='Sim') AS ativos_com_documentos, (SELECT COUNT(*) FROM fo_fornecedorxcriterios) AS documentos, (SELECT COUNT(*) FROM fo_funcionarios) AS funcionarios, (SELECT COUNT(*) FROM fo_funcionarioxtiposxcriterios) AS docs_funcionarios, (SELECT MAX(CODFORNECEDOR) FROM fo_fornecedores) AS max_codfornecedor, (SELECT MAX(codfornecedorxcriterios) FROM fo_fornecedorxcriterios) AS max_coddocumento");
        $saida[$lado]['atende_funcionarios'] = $linhas($pdo, "SELECT COALESCE(NULLIF(TRIM(atendeaocriterio),''),'(vazio)') AS atende, COUNT(*) AS n FROM fo_funcionarioxtiposxcriterios GROUP BY 1 ORDER BY 2 DESC");
        $saida[$lado]['gatilhos_fo'] = $linhas($pdo, "SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING, LEFT(ACTION_STATEMENT, 1500) AS acao FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE LIKE 'fo\\_%' ORDER BY EVENT_OBJECT_TABLE, TRIGGER_NAME");
        $saida[$lado]['rotinas_aguardando'] = $linhas($pdo, "SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_DEFINITION LIKE '%Aguardando vencimento%'");
        $saida[$lado]['eventos'] = $linhas($pdo, "SELECT EVENT_NAME, STATUS, INTERVAL_VALUE, INTERVAL_FIELD, LEFT(EVENT_DEFINITION, 600) AS def FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()");
    }
} catch (Throwable $e) {
    $saida['erro'] = $e->getMessage();
}

$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'comparacao_homologacao.json', $json);
echo $json . PHP_EOL;
