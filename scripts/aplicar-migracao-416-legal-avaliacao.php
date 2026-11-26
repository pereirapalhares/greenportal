<?php
declare(strict_types=1);

/*
 * Migração 416 — Requisitos legais e Legislação (Revisão G, bloco B, 26/11/2026):
 *   item 25  "Não avaliado" como padrão: vw_modulolegal_requisitos_situacao_grid ganha AVALIACAO_LEGAL ('Não avaliado' quando
 *            o requisito não tem avaliação manual Sim/Não/Parcial — sem avaliação ou "Atendimento Indefinido!"; 'Avaliado' nos demais) e a view vw_minhas_leis_nao_avaliadas leva a
 *            pendência "Lei não analisada" a quem responde pelo requisito (vw_ml_requisito_responsaveis);
 *   item 39  Nível de controle (Controle/Influência/Conhecimento) com justificativa obrigatória e registro de quem mudou:
 *            tabela legislacaoxpoder_historico e view vw_legislacaoxpoder_historico;
 *   item 38  "Razão para inclusão" da lei: coluna legislacao.RAZAO_INCLUSAO no banco da legislação (glegislacao_dbp) e
 *            sp_legislacao_select_ids recriada (database/383_legislacao_filtro_empresa.sql + a coluna nova).
 *   Os itens 30, 32 e 40 (avaliação em lote, replicar para outras unidades, próxima avaliação em lote) usam as tabelas que
 *   já existem (legislacaoxsituacao e situacaoxhistorico): não precisam de estrutura nova.
 *
 * vw_modulolegal_requisitos_situacao_grid vem das migrações 284/341; esta migração guarda a definição atual em
 * vw_modulolegal_requisitos_situacao_base e refaz a do grid por cima dela (se a 284 ou a 341 forem rodadas de novo,
 * rode esta também). vw_modulolegal_requisitos_licenca_grid (g.*) é recriada para trazer a coluna nova.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-416-legal-avaliacao.php [--simular] [--banco=gcrown_dbp]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$relatorio = ['ok' => true, 'migracao' => '416', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'legislacao' => [], 'bancos' => []];

// ---------------------------------------------------------------- 38: banco da legislação
try {
    $leg = GreenPortalDatabase::connect($env, (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp'), 'GLEGISLACAO');
    $leg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $tem = (int)$leg->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'legislacao' AND COLUMN_NAME = 'RAZAO_INCLUSAO'")->fetchColumn() > 0;
    if (!$tem && !$simular) $leg->exec("ALTER TABLE `legislacao` ADD COLUMN `RAZAO_INCLUSAO` TEXT NULL");
    $relatorio['legislacao']['coluna_RAZAO_INCLUSAO'] = $tem ? 'já existe' : ($simular ? 'a criar' : 'criada');
    $sql = (string)file_get_contents(dirname(__DIR__) . '/database/383_legislacao_filtro_empresa.sql');
    $blocos = preg_split('/^-- @@\s*$/m', $sql);
    $trocas = 0;
    foreach ($blocos as &$bloco) {
        if (!str_contains($bloco, 'CREATE PROCEDURE `sp_legislacao_select_ids`')) continue;
        $bloco = str_replace('l.`devido_da_lei`,', 'l.`devido_da_lei`, l.`RAZAO_INCLUSAO`,', $bloco, $trocas);
    }
    unset($bloco);
    if ($trocas !== 1) throw new RuntimeException('database/383: não encontrei o ponto da coluna devido_da_lei em sp_legislacao_select_ids');
    if (!$simular) {
        foreach ($blocos as $bloco) {
            $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
            if ($cmd !== '') $leg->exec($cmd);
        }
        $s = $leg->prepare('CALL sp_legislacao_select_ids(?, NULL, NULL, NULL, ?, 1, 0)'); $s->execute(['[1]', '{}']);
        $linha = $s->fetch(PDO::FETCH_ASSOC) ?: []; $s->closeCursor();
        $relatorio['legislacao']['sp_legislacao_select_ids'] = array_key_exists('RAZAO_INCLUSAO', $linha) || $linha === [] ? 'recriada com RAZAO_INCLUSAO' : 'SEM A COLUNA';
        if ($linha !== [] && !array_key_exists('RAZAO_INCLUSAO', $linha)) $relatorio['ok'] = false;
    } else $relatorio['legislacao']['sp_legislacao_select_ids'] = 'será recriada com RAZAO_INCLUSAO';
} catch (Throwable $e) { $relatorio['ok'] = false; $relatorio['legislacao']['erro'] = $e->getMessage(); }

// ---------------------------------------------------------------- 25 e 39: bancos das empresas
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'perfis', 'permissoes', 'usuarios') AND TABLE_SCHEMA LIKE '%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 4 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp')
    && preg_match('/^g[a-z0-9_]+_dbp$/', (string)$b) && ($soBanco === null || $b === $soBanco)));

foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $existe = static function (string $obj) use ($pdo): bool {
            $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'); $s->execute([$obj]);
            $n = (int)$s->fetchColumn(); $s->closeCursor(); return $n > 0;
        };
        $colunas = static function (string $obj) use ($pdo): array {
            $s = $pdo->prepare('SELECT UPPER(COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'); $s->execute([$obj]);
            $r = array_flip($s->fetchAll(PDO::FETCH_COLUMN)); $s->closeCursor(); return $r;
        };
        if (!$existe('legislacaoxempresa')) { $relatorio['bancos'][$banco] = ['ignorado' => 'sem módulo legal']; continue; }

        // 39: histórico do nível de controle
        if (!$existe('legislacaoxpoder_historico')) {
            if (!$simular) $pdo->exec("CREATE TABLE `legislacaoxpoder_historico` (
  `CODHISTORICO` INT NOT NULL AUTO_INCREMENT,
  `CODLEGISLACAO` INT NOT NULL,
  `CODUNIDADE` INT NOT NULL,
  `PODER_ANTERIOR` VARCHAR(30) NULL,
  `PODER_NOVO` VARCHAR(30) NOT NULL,
  `JUSTIFICATIVA` TEXT NOT NULL,
  `CODUSUARIO` INT NULL,
  `DATA_ALTERACAO` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`CODHISTORICO`),
  KEY `ix_poder_hist_lei_unidade` (`CODLEGISLACAO`, `CODUNIDADE`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $info['legislacaoxpoder_historico'] = $simular ? 'a criar' : 'criada';
        } else $info['legislacaoxpoder_historico'] = 'já existe';
        if (!$simular) {
            $pdo->exec("CREATE OR REPLACE VIEW `vw_legislacaoxpoder_historico` AS
SELECT h.`CODHISTORICO`, h.`CODLEGISLACAO`, h.`CODUNIDADE`, h.`PODER_ANTERIOR`, h.`PODER_NOVO`, h.`JUSTIFICATIVA`, h.`CODUSUARIO`,
       COALESCE(NULLIF(TRIM(u.`nome`), ''), CONCAT('#', h.`CODUSUARIO`)) AS `USUARIO`, h.`DATA_ALTERACAO`
  FROM `legislacaoxpoder_historico` h
  LEFT JOIN `usuarios` u ON u.`codusuario` = h.`CODUSUARIO`");
            $info['vw_legislacaoxpoder_historico'] = 'ok';
        }

        // 25: Não avaliado no grid de requisitos
        if ($existe('vw_modulolegal_requisitos_situacao_grid')) {
            $def = (string)$pdo->query("SELECT VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vw_modulolegal_requisitos_situacao_grid'")->fetchColumn();
            $jaEmbrulhada = str_contains($def, 'vw_modulolegal_requisitos_situacao_base');
            if (!$simular) {
                if (!$jaEmbrulhada) $pdo->exec('CREATE OR REPLACE VIEW `vw_modulolegal_requisitos_situacao_base` AS ' . $def);
                $pdo->exec("CREATE OR REPLACE VIEW `vw_modulolegal_requisitos_situacao_grid` AS
SELECT b.*, CAST(CASE WHEN b.`SL_MANUAL` = 'AI' THEN 'Não avaliado' ELSE 'Avaliado' END AS CHAR(20) CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS `AVALIACAO_LEGAL`
  FROM `vw_modulolegal_requisitos_situacao_base` b");
                if ($existe('vw_modulolegal_requisitos_licenca_grid') && $existe('legislacaoxlicencas'))
                    $pdo->exec("CREATE OR REPLACE VIEW `vw_modulolegal_requisitos_licenca_grid` AS
 SELECT g.*, r.`CODLICENCA` AS `CODLICENCA`
   FROM `vw_modulolegal_requisitos_situacao_grid` g
   JOIN `legislacaoxlicencas` r ON r.`CODLEGISLACAO` = g.`CODLEGISLACAO` AND r.`codunidade` = g.`CODUNIDADE`");
                $info['nao_avaliados'] = (int)$pdo->query("SELECT COUNT(*) FROM `vw_modulolegal_requisitos_situacao_grid` WHERE `AVALIACAO_LEGAL` = 'Não avaliado' AND COALESCE(`LEI_REVOGADA`, 'NAO') = 'NAO'")->fetchColumn();
            }
            $info['vw_modulolegal_requisitos_situacao_grid'] = $simular ? ($jaEmbrulhada ? 'será refeita' : 'será embrulhada (AVALIACAO_LEGAL)') : 'com AVALIACAO_LEGAL';
        } else $info['vw_modulolegal_requisitos_situacao_grid'] = 'não existe (rode a 284)';

        // 25: pendência "Lei não analisada"
        $filtro = $colunas('vw_modulolegal_requisitos_filtro_grid');
        if ($filtro && $existe('vw_ml_requisito_responsaveis') && isset($filtro['ATENDIDO'], $filtro['CODLEGISLACAO'], $filtro['CODUNIDADE'])) {
            $rev = isset($filtro['LEI_REVOGADA']) ? " AND COALESCE(g.`LEI_REVOGADA`, 'NAO') = 'NAO'" : '';
            $cod = isset($filtro['CODIGO']) ? 'g.`CODIGO`' : "CONCAT('Lei ', g.`CODLEGISLACAO`)";
            $uni = isset($filtro['UNIDADE']) ? 'g.`UNIDADE`' : "CONCAT('Unidade ', g.`CODUNIDADE`)";
            if (!$simular) {
                $pdo->exec("CREATE OR REPLACE VIEW `vw_minhas_leis_nao_avaliadas` AS
SELECT DISTINCT r.`CODUSUARIO`, g.`CODLEGISLACAO`, g.`CODUNIDADE`, $cod AS `CODIGO`, $uni AS `UNIDADE`
  FROM `vw_modulolegal_requisitos_filtro_grid` g
  JOIN `vw_ml_requisito_responsaveis` r ON r.`CODLEGISLACAO` = g.`CODLEGISLACAO` AND r.`CODUNIDADE` = g.`CODUNIDADE`
 WHERE NOT (COALESCE(CONVERT(g.`ATENDIDO` USING utf8mb4) COLLATE utf8mb4_unicode_ci, '') = 'Sim'
            OR CONVERT(g.`ATENDIDO` USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE 'Parcial%'
            OR CONVERT(g.`ATENDIDO` USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE 'N%')$rev AND COALESCE(r.`CODUSUARIO`, 0) > 0");
                $info['vw_minhas_leis_nao_avaliadas'] = (int)$pdo->query('SELECT COUNT(*) FROM `vw_minhas_leis_nao_avaliadas`')->fetchColumn() . ' pendência(s)';
            } else $info['vw_minhas_leis_nao_avaliadas'] = 'será criada';
        } else $info['vw_minhas_leis_nao_avaliadas'] = 'ignorada (falta vw_ml_requisito_responsaveis ou colunas do grid)';
    } catch (Throwable $e) { $relatorio['ok'] = false; $info['erro'] = $e->getMessage(); }
    $relatorio['bancos'][$banco] = $info;
}
echo json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
