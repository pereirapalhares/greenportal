<?php
declare(strict_types=1);

/*
 * Migração 312 — Home › Agenda e Alertas no padrão de grid (18/10/2026).
 * A agenda (eventos.php?modo=agenda) e os alertas (home_alertas.php) liam tabelas com SQL direto; passam a ler views
 * (regra do projeto: acesso ao banco só por procedures e views). Cria, em cada banco de empresa, as views cujas tabelas existem:
 *  - vw_agenda_eventos_usuario: eventos ATIVOS da agenda (agenda_eventos) do responsável ou do criador, até 180 dias à frente;
 *  - vw_agenda_tarefas_usuario: tarefas PENDENTE/PROCESSANDO (green_tasks) do usuário, com a solicitação de IA (ml_potenciais);
 *  - vw_homologacao_pendencias_treinamento_interno: treinamentos de funcionário vencidos ou vencendo em 30 dias, em que a
 *    pessoa é a responsável (mesma regra de "pede ação" da migração 310);
 *  - vw_homologacao_pendencias_funcionario_externo: documentos de funcionário que pedem ação, em que a pessoa é o responsável
 *    do fornecedor (Atende = Não ou vazio, ou Sim vencido/vencendo em 30 dias);
 *  - vw_homologacao_responsabilidade_fornecedor: responsabilidades da camada nova (vw_responsabilidade_efetiva, módulo
 *    HOMOLOGACAO) ligadas a fornecedores ativos;
 *  - vw_ged_analise_ia_ultima: situação da última análise de IA de cada arquivo (ged_analises_ia).
 * Só views (não mexe em dados). Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-312-agenda-alertas-views.php [--simular] [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$simular = in_array('--simular', $argv ?? [], true);
$soBanco = null;
foreach ($argv ?? [] as $a) if (str_starts_with($a, '--banco=')) $soBanco = array_filter(array_map('trim', explode(',', substr($a, 8))));
$env = GreenPortalDatabase::env();
$is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$is->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$bancos = $is->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME = 'usuarios' AND TABLE_SCHEMA LIKE 'g%\\_dbp' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
if ($soBanco) $bancos = array_values(array_intersect($bancos, $soBanco));
$existe = static function (string $banco, string $objeto) use ($is): bool {
    $s = $is->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $s->execute([$banco, $objeto]);
    return (int)$s->fetchColumn() > 0;
};

$nomeForn = "COALESCE(NULLIF(TRIM(f.`NOMEFANTASIA`), ''), NULLIF(TRIM(f.`RAZAOSOCIAL`), ''), CONCAT('Fornecedor ', f.`CODFORNECEDOR`))";
$views = [
    'vw_agenda_eventos_usuario' => [['agenda_eventos'], "CREATE OR REPLACE VIEW `vw_agenda_eventos_usuario` AS
SELECT a.`codusuario_responsavel` AS `CODUSUARIO`, a.`codeventoagenda` AS `CODEVENTOAGENDA`, a.`chave_idempotencia` AS `CHAVE`,
       a.`titulo` AS `TITULO`, a.`descricao` AS `DESCRICAO`, a.`inicio` AS `INICIO`, a.`status` AS `STATUS`
  FROM `agenda_eventos` a
 WHERE a.`status` = 'ATIVO' AND a.`codusuario_responsavel` IS NOT NULL AND a.`inicio` < CURRENT_DATE + INTERVAL 180 DAY
UNION
SELECT a.`codusuario_criador`, a.`codeventoagenda`, a.`chave_idempotencia`, a.`titulo`, a.`descricao`, a.`inicio`, a.`status`
  FROM `agenda_eventos` a
 WHERE a.`status` = 'ATIVO' AND a.`codusuario_criador` IS NOT NULL AND a.`inicio` < CURRENT_DATE + INTERVAL 180 DAY"],
    'vw_agenda_tarefas_usuario' => [['green_tasks'], null], // montada abaixo (com ou sem ml_potenciais)
    'vw_homologacao_pendencias_treinamento_interno' => [['fo_funcionarioxtiposxtreinamentos', 'fo_funcionarios', 'fo_fornecedores', 'fo_tipotreinamentos'], "CREATE OR REPLACE VIEW `vw_homologacao_pendencias_treinamento_interno` AS
SELECT r.`codusuario` AS `CODUSUARIO`, r.`codigo` AS `CODIGO`, u.`CODFUNCIONARIO` AS `CODFUNCIONARIO`, TRIM(u.`NOME`) AS `FUNCIONARIO`,
       $nomeForn AS `FORNECEDOR`, COALESCE(NULLIF(TRIM(t.`DSCTIPO`), ''), 'Treinamento') AS `TREINAMENTO`,
       r.`datadevalidadetreinamento` AS `DATADEVALIDADE`
  FROM `fo_funcionarioxtiposxtreinamentos` r
  JOIN `fo_funcionarios` u ON u.`CODFUNCIONARIO` = r.`codfuncionario` AND TRIM(COALESCE(u.`NOME`, '')) <> ''
  JOIN `fo_fornecedores` f ON f.`CODFORNECEDOR` = u.`CODFORNECEDOR` AND f.`ATIVADOFOR` = 'Sim' AND COALESCE(f.`RAZAOSOCIAL`, '') NOT LIKE '%- migra%o 217'
  LEFT JOIN `fo_tipotreinamentos` t ON t.`codtipotreinamento` = r.`codtipotreinamento`
 WHERE COALESCE(r.`codusuario`, 0) > 0
   AND r.`datadevalidadetreinamento` > '1900-01-01' AND r.`datadevalidadetreinamento` <= CURDATE() + INTERVAL 30 DAY"],
    'vw_homologacao_pendencias_funcionario_externo' => [['fo_funcionarioxtiposxcriterios', 'fo_funcionarios', 'fo_fornecedores', 'fo_criteriofuncionarios', 'fo_tipofuncionariosxcriteriofuncionarios'], "CREATE OR REPLACE VIEW `vw_homologacao_pendencias_funcionario_externo` AS
SELECT r.`codusuario_responsavel_fornecedor` AS `CODUSUARIO`, r.`codigo` AS `CODIGO`, u.`CODFUNCIONARIO` AS `CODFUNCIONARIO`, TRIM(u.`NOME`) AS `FUNCIONARIO`,
       $nomeForn AS `FORNECEDOR`,
       COALESCE(NULLIF(TRIM(c.`DSCRITERIO`), ''), CONCAT('Critério ', r.`codcriteriofuncionario`)) AS `DSCRITERIO`,
       NULLIF(TRIM(r.`atendeaocriterio`), '') AS `ATENDE`,
       CASE WHEN r.`datadevalidade` > '1900-01-01' THEN r.`datadevalidade` END AS `DATADEVALIDADE`
  FROM `fo_funcionarioxtiposxcriterios` r
  JOIN `fo_funcionarios` u ON u.`CODFUNCIONARIO` = r.`codfuncionario` AND TRIM(COALESCE(u.`NOME`, '')) <> '' AND r.`codtipofuncionario` = u.`CODTIPOFUNCIONARIO`
  JOIN `fo_fornecedores` f ON f.`CODFORNECEDOR` = u.`CODFORNECEDOR` AND f.`ATIVADOFOR` = 'Sim' AND COALESCE(f.`RAZAOSOCIAL`, '') NOT LIKE '%- migra%o 217'
  JOIN `fo_criteriofuncionarios` c ON c.`CODCRITERIOFUNCIONARIO` = r.`codcriteriofuncionario`
 WHERE EXISTS (SELECT 1 FROM `fo_tipofuncionariosxcriteriofuncionarios` tcf WHERE tcf.`codtipofuncionario` = u.`CODTIPOFUNCIONARIO` AND tcf.`codcriteriofuncionario` = r.`codcriteriofuncionario`)
   AND COALESCE(r.`codusuario_responsavel_fornecedor`, 0) NOT IN (0, 99999)
   AND COALESCE(r.`atendeaocriterio`, '') NOT IN ('Desligado', 'Afastado', 'NAP')
   AND (COALESCE(TRIM(r.`atendeaocriterio`), '') IN ('', 'Não')
        OR (r.`atendeaocriterio` = 'Sim' AND r.`datadevalidade` > '1900-01-01' AND r.`datadevalidade` <= CURDATE() + INTERVAL 30 DAY))"],
    'vw_homologacao_responsabilidade_fornecedor' => [['vw_responsabilidade_efetiva', 'fo_fornecedores', 'fo_fornecedorxusuario'], "CREATE OR REPLACE VIEW `vw_homologacao_responsabilidade_fornecedor` AS
SELECT r.`CODUSUARIO_DESTINO` AS `CODUSUARIO`, r.`ENTIDADE` AS `ENTIDADE`, r.`REGISTRO_ID` AS `REGISTRO_ID`, r.`VALIDO_ATE` AS `VALIDO_ATE`,
       r.`MODULO_NOME` AS `MODULO_NOME`, f.`CODFORNECEDOR` AS `CODFORNECEDOR`, $nomeForn AS `FORNECEDOR`
  FROM `vw_responsabilidade_efetiva` r
  JOIN `fo_fornecedores` f
    ON (r.`ENTIDADE` = 'fo_fornecedores' AND CAST(r.`REGISTRO_ID` AS UNSIGNED) = f.`CODFORNECEDOR`)
    OR (r.`ENTIDADE` = 'fo_fornecedorxusuario' AND EXISTS (SELECT 1 FROM `fo_fornecedorxusuario` fxu
         WHERE fxu.`CODFORNECEDORXUSUARIO` = CAST(r.`REGISTRO_ID` AS UNSIGNED) AND fxu.`CODFORNECEDOR` = f.`CODFORNECEDOR`))
 WHERE r.`MODULO_CODIGO` = 'HOMOLOGACAO' AND f.`ATIVADOFOR` = 'Sim' AND COALESCE(r.`CODUSUARIO_DESTINO`, 0) > 0"],
    'vw_ged_analise_ia_ultima' => [['ged_analises_ia'], "CREATE OR REPLACE VIEW `vw_ged_analise_ia_ultima` AS
SELECT a.`tipo` AS `TIPO`, a.`codigo_registro` AS `CODIGO_REGISTRO`, a.`arquivo` AS `ARQUIVO`, a.`status` AS `STATUS`, a.`codanalise` AS `CODANALISE`
  FROM `ged_analises_ia` a
 WHERE a.`codanalise` = (SELECT MAX(b.`codanalise`) FROM `ged_analises_ia` b
                          WHERE b.`tipo` = a.`tipo` AND b.`codigo_registro` = a.`codigo_registro` AND b.`arquivo` = a.`arquivo`)"],
];
$tarefas = static fn(bool $comPotenciais): string => "CREATE OR REPLACE VIEW `vw_agenda_tarefas_usuario` AS
SELECT t.`codusuario_destino` AS `CODUSUARIO`, t.`codtarefa` AS `CODTAREFA`, t.`titulo` AS `TITULO`, t.`descricao` AS `DESCRICAO`,
       t.`data_limite` AS `DATA_LIMITE`, t.`status` AS `STATUS`, t.`tipo` AS `TIPO`, " . ($comPotenciais ? 'p.`codsolicitacao`' : 'CAST(NULL AS SIGNED)') . " AS `CODSOLICITACAO`
  FROM `green_tasks` t" . ($comPotenciais ? "
  LEFT JOIN `ml_potenciais` p ON t.`tipo` = 'IA_APROVACAO' AND t.`origem_entidade` = 'ml_potenciais' AND p.`codpotencial` = CAST(t.`origem_id` AS UNSIGNED)" : '') . "
 WHERE t.`status` IN ('PENDENTE', 'PROCESSANDO') AND COALESCE(t.`codusuario_destino`, 0) > 0";

$rel = ['ok' => true, 'migracao' => '312', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = $simular ? null : GreenPortalDatabase::connect($env, (string)$banco, 'GPT');
        $pdo?->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ($views as $view => [$precisa, $sql]) {
            $faltam = array_values(array_filter($precisa, static fn(string $t): bool => !$existe((string)$banco, $t)));
            if ($faltam) { $info[$view] = 'não criada (faltam ' . implode(', ', $faltam) . ')'; continue; }
            if ($view === 'vw_agenda_tarefas_usuario') $sql = $tarefas($existe((string)$banco, 'ml_potenciais'));
            if ($simular) { $info[$view] = 'será criada'; continue; }
            try {
                $pdo->exec($sql);
                $info[$view] = (int)$pdo->query("SELECT COUNT(*) FROM `$view`")->fetchColumn() . ' linha(s)';
            } catch (Throwable $e) { $rel['ok'] = false; $info[$view] = 'ERRO: ' . $e->getMessage(); }
        }
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}
if (!$bancos) $rel['bancos'] = 'nenhum banco de empresa encontrado';
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
