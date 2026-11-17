<?php
declare(strict_types=1);

/*
 * Migração 350 — Homologação de Fornecedores: decisões do usuário de 24/10/2026.
 *
 * Cada empresa da homologação (classes/HomologacaoBancos.php):
 *   1. database/350_decisoes_homologacao.sql — gatilhos: fornecedor bloqueado no ranking não tem documentos aprovados (fornecedor e
 *      funcionário); funcionário com documentos ou treinamentos preenchidos não é excluído (use Desligado); view
 *      vw_fo_fornecedores_bloqueados. O sincronismo do legado não passa pelos gatilhos;
 *   2. integração: fo_funcionarios.DATA_VALIDADE passa a ser a data de REALIZAÇÃO (como o legado rotula); a validade = realização +
 *      meses da configuração (sem configuração, 12). Regrava de database/298_painel_homologacao.sql fo_integracao_config,
 *      vw_fo_integracao_validade e vw_homologacao_painel_itens; onde a 348 já foi aplicada (fo_integracoes), regrava a 348
 *      (scripts/aplicar-migracao-348-integracao-funcionarios.php --banco=);
 *   3. regras automáticas (fo_regras_documentos, migração 301): regrava database/301_regras_documentos_homologacao.sql (fornecedor
 *      bloqueado não é aprovado pelas regras), mantendo ligada/desligada cada regra como estava;
 *   4. Portaria: cores pela regra única do Painel e "Fornecedor bloqueado (não liberado)" — roda as migrações 260 e 262 com
 *      --sem-permissoes (as permissões dos menus não mudam);
 *   5. registros_migracao (DECISOES_HOMOLOGACAO_350).
 * O relatório mostra, por banco, as cores da integração antes e depois (na simulação, a estimativa), os fornecedores bloqueados e
 * os funcionários atingidos, e quantos funcionários não poderão mais ser excluídos.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-350-decisoes-homologacao.php [--simular] [--banco=gatlas_dbp]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

@set_time_limit(0);
$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$env = GreenPortalDatabase::env();
$raiz = dirname(__DIR__);
$rel = ['ok' => true, 'migracao' => '350', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => [], 'portaria' => [], 'integracao_348' => []];

$limpar = static fn(string $sql): array => array_values(array_filter(array_map('trim', preg_split('/^-- @@\s*$/m', (string)preg_replace('/^\s*--(?! @@).*$/m', '', $sql))), static fn($b) => $b !== ''));
$ler = static fn(string $arquivo): array => $limpar((string)file_get_contents($raiz . '/database/' . $arquivo));
$blocos350 = $ler('350_decisoes_homologacao.sql');
$blocos298 = $ler('298_painel_homologacao.sql');
$blocos301 = $ler('301_regras_documentos_homologacao.sql');
$pega = static fn(array $blocos, string $trecho): array => array_values(array_filter($blocos, static fn($b) => str_contains($b, $trecho)));
$cfgInt = $pega($blocos298, 'TABLE IF NOT EXISTS `fo_integracao_config`');
$viewValidade = $pega($blocos298, 'VIEW `vw_fo_integracao_validade`');
$viewPainel = $pega($blocos298, 'VIEW `vw_homologacao_painel_itens`');
if (count($cfgInt) !== 1 || count($viewValidade) !== 1 || count($viewPainel) !== 1 || !str_contains($viewPainel[0], 'vw_fo_integracao_validade')
    || !str_contains(implode("\n", $blocos301), 'fo_fornecedor_bloqueios') || count($pega($blocos350, 'CREATE TRIGGER')) !== 3) {
    fwrite(STDERR, "Arquivos 298/301/350 não estão na versão da migração 350.\n"); exit(1);
}

$bancosOk = []; $com348 = [];
foreach (HomologacaoBancos::lista($args) as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $um = static function (string $q, array $p = []) use ($pdo): mixed { $s = $pdo->prepare($q); $s->execute($p); $v = $s->fetchColumn(); $s->closeCursor(); return $v; };
        $tem = static fn(string $tabela): bool => (int)$um('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$tabela]) > 0;
        $faltam = array_values(array_filter(['fo_fornecedores', 'fo_funcionarios', 'fo_fornecedorxcriterios', 'fo_funcionarioxtiposxcriterios', 'fo_funcionarioxtiposxtreinamentos'], static fn($t) => !$tem($t)));
        if ($faltam) throw new RuntimeException('faltam tabelas da homologação: ' . implode(', ', $faltam));
        $ativoF = "LOWER(TRIM(COALESCE(f.ativadofor, ''))) IN ('sim', 's', '1')";
        $ativoFu = "UPPER(TRIM(COALESCE(fu.ativo, 'S'))) NOT IN ('N', 'NAO', 'NÃO', '0')";

        // ---- integração: cores antes / depois
        $cores = static function (string $sql) use ($pdo): array {
            $o = ['VERDE' => 0, 'AMARELO' => 0, 'VERMELHO' => 0, 'BRANCO' => 0];
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $l) $o[(string)$l['COR']] = (int)$l['N'];
            return $o;
        };
        $temPainel = (int)$um("SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vw_homologacao_painel_itens'") > 0;
        $sqlPainel = "SELECT COR, COUNT(*) AS N FROM vw_homologacao_painel_itens WHERE CATEGORIA = 'INTEGRACAO' GROUP BY COR";
        $info['integracao']['antes'] = $temPainel ? $cores($sqlPainel) : 'sem o Painel (migração 298) neste banco';
        $temCfg = $tem('fo_integracao_config');
        $meses = $temCfg
            ? "COALESCE((SELECT ct.VALIDADE_MESES FROM fo_integracao_config ct WHERE ct.CODTIPOFUNCIONARIO = fu.CODTIPOFUNCIONARIO AND ct.ATIVO = 1 LIMIT 1),
                        (SELECT cp.VALIDADE_MESES FROM fo_integracao_config cp WHERE cp.CODTIPOFUNCIONARIO IS NULL AND cp.ATIVO = 1 LIMIT 1), 12)"
            : '12';
        $info['integracao']['meses_de_validade'] = $temCfg ? 'da configuração da integração (sem configuração do tipo: padrão da empresa ou 12)' : '12 (sem configuração da integração neste banco)';

        // ---- bloqueio do ranking
        $temBloq = $tem('fo_fornecedor_bloqueios');
        if ($temBloq) {
            $b = $pdo->query("SELECT COUNT(*) AS fornecedores, SUM($ativoF) AS ativos,
                    (SELECT COUNT(*) FROM fo_funcionarios fu JOIN fo_fornecedor_bloqueios b2 ON b2.codfornecedor = fu.CODFORNECEDOR WHERE $ativoFu) AS funcionarios
                FROM fo_fornecedor_bloqueios b LEFT JOIN fo_fornecedores f ON f.codfornecedor = b.codfornecedor")->fetch(PDO::FETCH_ASSOC) ?: [];
            $info['bloqueio_ranking'] = ['fornecedores_bloqueados' => (int)$b['fornecedores'], 'ativos' => (int)$b['ativos'], 'funcionarios_ativos_nao_liberados' => (int)$b['funcionarios'],
                'documentos_pendentes_que_nao_poderao_ser_aprovados' => (int)$um("SELECT COUNT(*) FROM fo_fornecedorxcriterios x JOIN fo_fornecedor_bloqueios b ON b.codfornecedor = x.codfornecedor
                    WHERE TRIM(COALESCE(x.situacao, '')) NOT IN ('Aprovado', 'Aprovado sem validade')")];
        } else $info['bloqueio_ranking'] = 'sem o ranking (migração 289): a tabela de bloqueios será criada vazia';
        $info['exclusao_funcionario'] = ['funcionarios_que_so_podem_ser_desligados' => (int)$um("SELECT COUNT(*) FROM fo_funcionarios fu WHERE
              EXISTS (SELECT 1 FROM fo_funcionarioxtiposxcriterios x WHERE x.codfuncionario = fu.CODFUNCIONARIO AND (TRIM(COALESCE(x.atendeaocriterio, '')) <> '' OR x.datadevalidade > '1900-01-01'))
           OR EXISTS (SELECT 1 FROM fo_funcionarioxtiposxtreinamentos t WHERE t.codfuncionario = fu.CODFUNCIONARIO AND (TRIM(COALESCE(t.treinamentorealizado, '')) <> '' OR t.datadevalidadetreinamento > '1900-01-01'))")];
        $tem301 = $tem('fo_regras_documentos');
        $tem348 = $tem('fo_integracoes');
        $info['gatilhos_existentes_nas_tabelas'] = array_column($pdo->query("SELECT CONCAT(TRIGGER_NAME, ' (', ACTION_TIMING, ' ', EVENT_MANIPULATION, ' ', EVENT_OBJECT_TABLE, ')') AS t FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE IN ('fo_fornecedorxcriterios', 'fo_funcionarioxtiposxcriterios', 'fo_funcionarios')
              AND TRIGGER_NAME NOT IN ('trg_fo_bloqueio_forn_bu', 'trg_fo_bloqueio_func_bu', 'trg_fo_funcionarios_excluir_bd')")->fetchAll(PDO::FETCH_ASSOC), 't');

        if ($simular) {
            $info['integracao']['depois_estimado'] = $cores("SELECT CASE WHEN i.VAL IS NULL THEN 'BRANCO' WHEN DATEDIFF(i.VAL, CURDATE()) < 0 THEN 'VERMELHO'
                    WHEN DATEDIFF(i.VAL, CURDATE()) <= COALESCE(i.REAC, 30) THEN 'AMARELO' ELSE 'VERDE' END AS COR, COUNT(*) AS N
                FROM (SELECT " . ((int)$um("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fo_funcionarios' AND COLUMN_NAME = 'NRDIAS_REACAO'") ? 'NULLIF(fu.NRDIAS_REACAO, 0)' : 'NULL') . " AS REAC,
                             IF(fu.DATA_VALIDADE IS NULL OR fu.DATA_VALIDADE < '1900-01-01', NULL, DATE_ADD(fu.DATA_VALIDADE, INTERVAL $meses MONTH)) AS VAL
                        FROM fo_funcionarios fu JOIN fo_fornecedores f ON f.codfornecedor = fu.CODFORNECEDOR AND $ativoF
                       WHERE $ativoFu) i GROUP BY 1");
            $info['estrutura'] = 'gatilhos e view serão gravados; validade da integração recalculada' . ($tem348 ? '; a 348 será regravada' : '');
            $info['regras_301'] = $tem301 ? 'serão regravadas (ligada/desligada de cada regra mantida)' : 'sem as regras automáticas da 301 neste banco';
        } else {
            foreach ($blocos350 as $b) $pdo->exec($b);
            $pdo->exec($cfgInt[0]); $pdo->exec($viewValidade[0]);
            if ($temPainel) $pdo->exec($viewPainel[0]);
            $info['estrutura'] = count($blocos350) . ' blocos da 350 gravados; validade da integração recalculada';
            if ($temPainel) $info['integracao']['depois'] = $cores($sqlPainel);
            if ($tem301) {
                $estado = $pdo->query('SELECT codregra, ativo FROM fo_regras_documentos')->fetchAll(PDO::FETCH_KEY_PAIR);
                foreach ($blocos301 as $b) $pdo->exec($b);
                $up = $pdo->prepare('UPDATE fo_regras_documentos SET ativo = ? WHERE codregra = ?');
                foreach ($estado as $c => $a) $up->execute([(int)$a, $c]);
                $info['regras_301'] = 'regravadas (' . implode('; ', array_map(static fn($r) => $r['escopo'] . ': ' . (int)$r['ligadas'] . ' de ' . (int)$r['total'] . ' ligadas',
                    $pdo->query('SELECT escopo, SUM(ativo) AS ligadas, COUNT(*) AS total FROM fo_regras_documentos GROUP BY escopo ORDER BY escopo')->fetchAll(PDO::FETCH_ASSOC))) . ')';
            } else $info['regras_301'] = 'sem as regras automáticas da 301 neste banco';
            if ($tem('registros_migracao'))
                $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
                    VALUES ('DECISOES_HOMOLOGACAO_350', 'Decisões de 24/10/2026', 'trg_fo_bloqueio_*;trg_fo_funcionarios_excluir_bd;vw_fo_integracao_validade;vw_fo_fornecedores_bloqueados', 'ESTRUTURA', '4.0.1', 'CONCLUIDA', 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
                    ON DUPLICATE KEY UPDATE status = VALUES(status), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")->execute([json_encode($info, JSON_UNESCAPED_UNICODE)]);
        }
        $bancosOk[] = $banco;
        if ($tem348) $com348[] = $banco;
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}

// ---- migrações regravadas (subprocessos, só nos bancos que passaram acima)
$rodar = static function (string $script, array $bancos, string $extra) use ($raiz, $simular): array {
    if (!$bancos) return ['situacao' => 'nenhum banco'];
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($raiz . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . $script)
        . ' --banco=' . implode(',', $bancos) . $extra . ($simular ? ' --simular' : '');
    $saida = []; $rc = 0;
    exec($cmd . ' 2>&1', $saida, $rc);
    $texto = implode("\n", $saida);
    $p = strpos($texto, '{');
    $j = $p === false ? null : json_decode(substr($texto, $p), true);
    return ['bancos' => $bancos, 'codigo_saida' => $rc] + (is_array($j) ? ['relatorio' => $j] : ['saida' => mb_substr($texto, -3000)]);
};
foreach (['260' => 'aplicar-migracao-260-portaria-documentacao-fornecedor.php', '262' => 'aplicar-migracao-262-portaria-documentacao-funcionario.php'] as $m => $s) {
    $rel['portaria'][$m] = $rodar($s, $bancosOk, ' --sem-permissoes');
    if ($rel['portaria'][$m]['bancos'] ?? false) if ((int)$rel['portaria'][$m]['codigo_saida'] !== 0) $rel['ok'] = false;
}
$rel['integracao_348'] = $com348 ? $rodar('aplicar-migracao-348-integracao-funcionarios.php', $com348, '') : ['situacao' => 'a 348 ainda não foi aplicada em nenhum banco: ao aplicá-la, já entra com a regra nova'];
if ($com348 && (int)$rel['integracao_348']['codigo_saida'] !== 0) $rel['ok'] = false;

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$logs = $raiz . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logs)) @mkdir($logs, 0775, true);
@file_put_contents($logs . DIRECTORY_SEPARATOR . 'migracao_350_decisoes_homologacao' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
