<?php
declare(strict_types=1);

/*
 * Migração 291 — versões de questionário por empresa (banco universal, guniversal_dbp).
 *
 *   amb_questionario_versao ganha:
 *     codempresa                0 = versão universal (ADMINISTRADOR); > 0 = versão própria da empresa (ADM DA EMPRESA)
 *     origem                    UNIVERSAL / EMPRESA / MERGE
 *     codversao_base_universal  versão universal de onde a versão veio (base do merge de três vias)
 *     codversao_base_empresa    versão anterior da empresa (no merge)
 *     conflitos_json            {"conflitos": [...], "avisos": [...]} do merge
 *     observacao                histórico curto (de onde veio, merge, descarte)
 *   A chave única passa a ser (codquestionario, codempresa, versao): cada empresa numera as suas versões.
 *
 *   vw_amb_questionario_catalogo   só versões universais (como antes, para quem ainda a lê)
 *   vw_amb_questionario_publicadas versões publicadas de todos os escopos (com codempresa), para achar a vigente
 *                                  de cada empresa: a dela, senão a universal
 *   vw_amb_questionario_versoes    todas as versões, com o escopo e a origem (tela de administração)
 *
 * Também garante as procedures sp_crud_* no universal. Não mexe nos bancos das empresas.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-291-questionarios-empresa.php [--simular]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$banco = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$rel = ['ok' => true, 'migracao' => '291', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'banco' => $banco, 'passos' => []];

try {
    $u = GreenPortalDatabase::connect($env, $banco, 'GUNIVERSAL');
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (['amb_questionario', 'amb_questionario_versao', 'amb_questionario_pergunta', 'amb_questionario_opcao', 'amb_questionario_publicacao'] as $t)
        if (!MigracaoCrud::existe($u, $t)) throw new RuntimeException("tabela $t não existe (rode antes a migração 193 dos questionários)");
    $cols = MigracaoCrud::colunas($u, 'amb_questionario_versao');
    $novas = [
        'codempresa' => "INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = universal; > 0 = versão própria da empresa' AFTER `codquestionario`",
        'origem' => "VARCHAR(20) NOT NULL DEFAULT 'UNIVERSAL' COMMENT 'UNIVERSAL, EMPRESA ou MERGE'",
        'codversao_base_universal' => "BIGINT UNSIGNED NULL COMMENT 'Versão universal de onde esta veio (base do merge)'",
        'codversao_base_empresa' => "BIGINT UNSIGNED NULL COMMENT 'Versão anterior da empresa (merge)'",
        'conflitos_json' => "JSON NULL COMMENT 'Conflitos e avisos do merge com a universal'",
        'observacao' => "VARCHAR(500) NULL",
    ];
    $faltam = array_diff_key($novas, array_flip($cols));
    $rel['passos']['colunas_novas'] = array_keys($faltam);

    $idx = [];
    foreach ($u->query("SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) c FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'amb_questionario_versao' GROUP BY INDEX_NAME")->fetchAll(PDO::FETCH_KEY_PAIR) as $n => $c) $idx[$n] = strtolower((string)$c);
    $rel['passos']['chave_unica'] = isset($idx['ux_amb_questionario_versao_escopo']) ? 'já por empresa' : 'passa a (codquestionario, codempresa, versao)';

    $views = [
        'vw_amb_questionario_catalogo' => "CREATE OR REPLACE VIEW vw_amb_questionario_catalogo AS
SELECT q.codquestionario, q.codigo, q.nome_pt, q.nome_en, q.nome_es, q.modulo, q.ativo,
       v.codversao, v.versao, v.status, v.percentual_minimo, p.codpublicacao, p.codigo_publicacao, p.publicado_em
  FROM amb_questionario q
  LEFT JOIN amb_questionario_versao v ON v.codquestionario = q.codquestionario AND v.status = 'PUBLICADA' AND v.codempresa = 0
  LEFT JOIN amb_questionario_publicacao p ON p.codversao = v.codversao AND p.ativo = 1",
        'vw_amb_questionario_publicadas' => "CREATE OR REPLACE VIEW vw_amb_questionario_publicadas AS
SELECT q.codquestionario, q.codigo, q.nome_pt, q.nome_en, q.nome_es, q.modulo, q.ativo,
       v.codversao, v.versao, v.codempresa, v.origem, v.status, v.percentual_minimo, p.codpublicacao, p.codigo_publicacao, p.publicado_em
  FROM amb_questionario q
  JOIN amb_questionario_versao v ON v.codquestionario = q.codquestionario AND v.status = 'PUBLICADA'
  LEFT JOIN amb_questionario_publicacao p ON p.codversao = v.codversao AND p.ativo = 1",
        'vw_amb_questionario_versoes' => "CREATE OR REPLACE VIEW vw_amb_questionario_versoes AS
SELECT v.codversao, v.codquestionario, q.codigo, q.nome_pt, q.nome_en, q.nome_es, q.modulo, v.versao, v.codempresa, v.origem, v.status,
       v.codversao_base_universal, v.codversao_base_empresa, v.observacao, v.criado_em, v.publicado_em
  FROM amb_questionario_versao v JOIN amb_questionario q ON q.codquestionario = v.codquestionario",
    ];
    $rel['passos']['views'] = array_keys($views);

    if (!$simular) {
        if ($criadas = MigracaoCrud::garantir($u)) $rel['passos']['procedures_crud_criadas'] = $criadas;
        foreach ($faltam as $c => $def) $u->exec("ALTER TABLE `amb_questionario_versao` ADD COLUMN `$c` $def");
        if (!isset($idx['ux_amb_questionario_versao_escopo'])) {
            $u->exec('ALTER TABLE `amb_questionario_versao` ADD UNIQUE KEY `ux_amb_questionario_versao_escopo` (`codquestionario`, `codempresa`, `versao`)');
            if (isset($idx['ux_amb_questionario_versao'])) $u->exec('ALTER TABLE `amb_questionario_versao` DROP INDEX `ux_amb_questionario_versao`');
        }
        if (!isset($idx['ix_amb_questionario_versao_empresa']))
            $u->exec('ALTER TABLE `amb_questionario_versao` ADD KEY `ix_amb_questionario_versao_empresa` (`codquestionario`, `codempresa`, `status`)');
        foreach ($views as $sql) $u->exec($sql);
        // Conferência: as views respondem pela procedure de leitura
        foreach (array_keys($views) as $v) { $s = $u->prepare('CALL sp_crud_select(?, NULL, NULL, 1, 0)'); $s->execute([$v]); $s->fetchAll(); $s->closeCursor(); }
        MigracaoCrud::salvar($u, 'green_universal_migrations', ['codigo_migracao' => 'QUESTIONARIOS_EMPRESA_291'],
            ['versao' => '4.0.1', 'status' => 'CONCLUIDA', 'detalhes' => json_encode(['escopo' => 'codempresa', 'merge' => 'tres_vias'], JSON_UNESCAPED_UNICODE)]);
    }
    $porEscopo = [];
    foreach (MigracaoCrud::selecionar($u, 'amb_questionario_versao') as $v) {
        $k = (int)($v['codempresa'] ?? 0) === 0 ? 'universal' : 'empresa ' . (int)$v['codempresa'];
        $porEscopo[$k][(string)$v['status']] = ($porEscopo[$k][(string)$v['status']] ?? 0) + 1;
    }
    $rel['versoes'] = $porEscopo;
    $q = MigracaoCrud::primeiro($u, 'amb_questionario', ['codigo' => 'PERFIL_ATRIBUICOES']);
    $rel['perfil_atribuicoes'] = $q ? ['codquestionario' => (int)$q['codquestionario'], 'modulo' => $q['modulo']] : 'questionário PERFIL_ATRIBUICOES não encontrado (migração 268)';
} catch (Throwable $e) {
    $rel['ok'] = false; $rel['erro'] = $e->getMessage();
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
