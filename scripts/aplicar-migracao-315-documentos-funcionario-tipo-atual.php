<?php
declare(strict_types=1);

/*
 * Migração 315 — Homologação de Fornecedores: documentos de funcionário com a regra do legado (19/10/2026).
 * Comparação na Crown (relatório "Gerenciamento de Documentos Exigidos Funcionários", fo_relatorio_documentacao_exigida_func):
 * legado 4.733 linhas; greenportal 5.832. Diagnóstico (scripts/diagnostico-relatorio-docfunc.php, regra G) = 4.732, com Não,
 * Desligado e sem situação iguais. O legado só considera o documento quando:
 *   - é do tipo ATUAL do funcionário (fo_funcionarioxtiposxcriterios.codtipofuncionario = fo_funcionarios.CODTIPOFUNCIONARIO);
 *   - o critério é exigido por esse tipo (fo_tipofuncionariosxcriteriofuncionarios);
 *   - o critério ainda existe no cadastro (fo_criteriofuncionarios).
 * Os demais registros continuam no banco (nada é apagado), só deixam de entrar nas telas.
 *
 * Em cada banco de cliente da homologação:
 *  1. recria vw_homologacao_painel_itens (database/298_painel_homologacao.sql, categoria DOCFUNC) — vale para o Painel,
 *     a Ficha do fornecedor, os Relatórios de Homologação e o resumo semanal;
 *  2. recria sp_fo_relatorio_unificado (database/314_relatorios_homologacao.sql: itens exigidos só com critério/treinamento existente);
 *  3. reaplica as migrações 310 e 312 (pendências de documentos de funcionário na Home, interno e fornecedor externo).
 * Só views e procedimento (não mexe em dados). Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-315-documentos-funcionario-tipo-atual.php [--simular] [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$simular = in_array('--simular', $argv ?? [], true);
try { $alvos = HomologacaoBancos::lista($argv ?? []); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
$env = GreenPortalDatabase::env();
$is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$is->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$blocos = static function (string $arquivo): array {
    $out = [];
    foreach (preg_split('/^-- @@\s*$/m', (string)file_get_contents(dirname(__DIR__) . '/database/' . $arquivo)) as $b) {
        $limpo = trim(preg_replace('/^\s*--.*$/m', '', $b) ?? '');
        if ($limpo !== '') $out[] = $limpo;
    }
    return $out;
};
$view = array_values(array_filter($blocos('298_painel_homologacao.sql'), static fn($b) => str_contains($b, 'VIEW `vw_homologacao_painel_itens`')));
// Desde a migração 350 a view do painel lê a validade da integração de vw_fo_integracao_validade (e esta, fo_integracao_config): grava antes
$apoio = array_values(array_filter($blocos('298_painel_homologacao.sql'), static fn($b) => str_contains($b, 'TABLE IF NOT EXISTS `fo_integracao_config`') || str_contains($b, 'VIEW `vw_fo_integracao_validade`')));
$unificado = array_values(array_filter($blocos('314_relatorios_homologacao.sql'), static fn($b) => str_contains($b, '`sp_fo_relatorio_unificado`')));
if (count($view) !== 1 || !str_contains($view[0], 'fo_tipofuncionariosxcriteriofuncionarios') || count($unificado) !== 2) {
    fwrite(STDERR, "Arquivos 298/314 não estão na versão da migração 315.\n"); exit(1);
}
$contar = static fn(PDO $pdo): array => $pdo->query("SELECT COUNT(*) AS itens, COUNT(DISTINCT CODFUNCIONARIO) AS funcionarios, SUM(COR = 'VERMELHO') AS vermelhos, SUM(COR = 'BRANCO') AS sem_situacao
    FROM vw_homologacao_painel_itens WHERE CATEGORIA = 'DOCFUNC'")->fetch(PDO::FETCH_ASSOC) ?: [];

$rel = ['ok' => true, 'migracao' => '315', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
foreach ($alvos as $banco) {
    if (!(int)$is->query('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ' . $is->quote($banco))->fetchColumn()) {
        if ($banco !== 'gmodelo2_dbp') { $rel['ok'] = false; $rel['bancos'][$banco] = ['erro' => 'banco não existe']; }
        continue;
    }
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $info = ['antes' => array_map('intval', $contar($pdo))];
        if ($simular) {
            $info['depois_estimado'] = array_map('intval', $pdo->query("SELECT COUNT(*) AS itens, COUNT(DISTINCT x.codfuncionario) AS funcionarios
                FROM fo_funcionarioxtiposxcriterios x
                JOIN fo_funcionarios fu ON fu.CODFUNCIONARIO = x.codfuncionario AND x.codtipofuncionario = fu.CODTIPOFUNCIONARIO
                JOIN fo_criteriofuncionarios cf ON cf.codcriteriofuncionario = x.codcriteriofuncionario
                JOIN fo_fornecedores f ON f.codfornecedor = fu.CODFORNECEDOR AND LOWER(TRIM(COALESCE(f.ativadofor, ''))) IN ('sim', 's', '1')
               WHERE UPPER(TRIM(COALESCE(fu.ativo, 'S'))) NOT IN ('N', 'NAO', 'NÃO', '0')
                 AND EXISTS (SELECT 1 FROM fo_tipofuncionariosxcriteriofuncionarios t WHERE t.codtipofuncionario = fu.CODTIPOFUNCIONARIO AND t.codcriteriofuncionario = x.codcriteriofuncionario)")->fetch(PDO::FETCH_ASSOC));
        } else {
            foreach ($apoio as $b) $pdo->exec($b);
            $pdo->exec($view[0]);
            foreach ($unificado as $b) $pdo->exec($b);
            $info['depois'] = array_map('intval', $contar($pdo));
        }
        $rel['bancos'][$banco] = $info;
    } catch (Throwable $e) { $rel['ok'] = false; $rel['bancos'][$banco] = ['erro' => $e->getMessage()]; }
}

// Pendências da Home (310 e 312) com a mesma regra: os próprios scripts recriam as views (idempotentes).
$filtro = array_values(array_filter($argv ?? [], static fn($a) => str_starts_with((string)$a, '--banco=') || $a === '--simular'));
foreach (['aplicar-migracao-310-pendencias-homologacao-home.php', 'aplicar-migracao-312-agenda-alertas-views.php'] as $script) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . $script) . ($filtro ? ' ' . implode(' ', array_map('escapeshellarg', $filtro)) : '');
    $saida = []; $codigo = 0;
    exec($cmd . ' 2>&1', $saida, $codigo);
    $json = json_decode(implode("\n", $saida), true);
    $rel['reaplicadas'][$script] = $codigo === 0 ? (is_array($json) ? ['ok' => $json['ok'] ?? true] : 'OK') : ['erro' => mb_substr(implode(' ', $saida), 0, 400)];
    if ($codigo !== 0) $rel['ok'] = false;
}

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_315_documentos_funcionario' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
