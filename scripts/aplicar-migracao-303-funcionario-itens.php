<?php
declare(strict_types=1);

/*
 * Migração 303 — Homologação de Fornecedores: critérios e treinamentos do funcionário gerados pelo tipo de funcionário
 * (como o legado ao abrir as abas Critério/Treinamento da Gestão de Funcionários).
 * Em cada banco de cliente (gmodelo_dbp, gatlas_dbp, gmodelo2_dbp se existir) aplica database/303_funcionario_itens_gerar.sql:
 * procedure sp_fo_funcionario_itens_gerar e gatilhos (funcionário novo, troca de tipo, critério/treinamento novo no tipo).
 * As telas Critérios e Treinamentos do funcionário chamam a procedure ao abrir (classes/HomologacaoFornecedorFuncionarioRelacionamentoGrid.php).
 * Os funcionários que já existem só recebem os itens que faltam quando a tela for aberta, ou de uma vez com --gerar-todos.
 * Depois, o serviço das regras (migração 301) marca cada item novo conforme o GED (sem arquivo = Não / Pendente com o fornecedor).
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-303-funcionario-itens.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-303-funcionario-itens.php
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-303-funcionario-itens.php --gerar-todos
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-303-funcionario-itens.php --limpar
 * --limpar remove itens em branco (nunca preenchidos) de critério/treinamento inexistente no cadastro ou de funcionário excluído
 * (sp_fo_funcionario_itens_limpar); itens com qualquer dado são mantidos. Só gera itens que existem no cadastro (o tipo pode ter
 * vínculos órfãos vindos da carga do legado).
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

define('M303_BANCOS', HomologacaoBancos::lista($argv ?? [])); // classes/HomologacaoBancos.php (--banco= para só alguns)

$simular = in_array('--simular', $argv ?? [], true);
$gerarTodos = in_array('--gerar-todos', $argv ?? [], true) && !$simular;
$limpar = in_array('--limpar', $argv ?? [], true) && !$simular;
$env = GreenPortalDatabase::env();
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$template = (string)file_get_contents(dirname(__DIR__) . '/database/303_funcionario_itens_gerar.sql');
$linhas = static function (PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) { } $s->closeCursor(); return $r; };
$valor = static function (PDO $pdo, string $sql, array $p = []) use ($linhas): mixed { $r = $linhas($pdo, $sql, $p); return $r ? array_values($r[0])[0] : null; };

$relatorio = ['ok' => true, 'migracao' => '303', 'modo' => $simular ? 'SIMULACAO' : trim('APLICAR' . ($gerarTodos ? ' + GERAR TODOS' : '') . ($limpar ? ' + LIMPAR' : '')), 'bancos' => []];
foreach (M303_BANCOS as $banco) {
    $info = [];
    if (!$valor($geral, 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$banco])) {
        if ($banco !== 'gmodelo2_dbp') { $relatorio['ok'] = false; $relatorio['bancos'][$banco] = ['erro' => 'banco não existe']; }
        continue;
    }
    try {
        foreach (['fo_funcionarios', 'fo_funcionarioxtiposxcriterios', 'fo_funcionarioxtiposxtreinamentos', 'fo_tipofuncionariosxcriteriofuncionarios', 'fo_tipofuncionariosxtipotreinamentos'] as $t)
            if (!$valor($geral, 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$banco, $t])) throw new RuntimeException("tabela ausente: $t");
        // Os inserts gravam só funcionário, tipo e critério/treinamento: as demais colunas obrigatórias precisam ter valor padrão
        $semPadrao = $linhas($geral, "SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?
            AND TABLE_NAME IN ('fo_funcionarioxtiposxcriterios', 'fo_funcionarioxtiposxtreinamentos') AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL
            AND EXTRA NOT LIKE '%auto_increment%' AND LOWER(COLUMN_NAME) NOT IN ('codfuncionario', 'codtipofuncionario', 'codcriteriofuncionario', 'codtipotreinamento')", [$banco]);
        if ($semPadrao) throw new RuntimeException('colunas obrigatórias sem valor padrão: ' . implode(', ', array_column($semPadrao, 'c')));
        $gatilhos = array_column($linhas($geral, "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND EVENT_OBJECT_TABLE IN ('fo_funcionarios', 'fo_tipofuncionariosxcriteriofuncionarios', 'fo_tipofuncionariosxtipotreinamentos')", [$banco]), 'TRIGGER_NAME');
        $info['gatilhos_existentes'] = $gatilhos;
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
            $info['objetos_303'] = $n;
            $l = $linhas($pdo, 'CALL sp_fo_funcionario_itens_limpar(?)', [$limpar ? 1 : 0])[0] ?? [];
            $info[$limpar ? 'itens_em_branco_removidos' : 'itens_em_branco_a_limpar (use --limpar)'] = ['criterios' => (int)($l['CRITERIOS'] ?? 0), 'treinamentos' => (int)($l['TREINAMENTOS'] ?? 0)];
            $r = $linhas($pdo, 'CALL sp_fo_funcionario_itens_gerar(0, ?)', [$gerarTodos ? 1 : 0])[0] ?? [];
            $info[$gerarTodos ? 'gerados_agora' : 'faltam_nos_funcionarios_ativos (use --gerar-todos ou abra a tela)'] = ['criterios' => (int)($r['CRITERIOS'] ?? 0), 'treinamentos' => (int)($r['TREINAMENTOS'] ?? 0)];
        }
    } catch (Throwable $e) {
        $relatorio['ok'] = false;
        $info['erro'] = $e->getMessage();
    }
    $relatorio['bancos'][$banco] = $info;
}

$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_303_funcionario_itens' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
