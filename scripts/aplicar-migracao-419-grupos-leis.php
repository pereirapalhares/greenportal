<?php
declare(strict_types=1);

/*
 * Migração 419 — Grupos de leis (Revisão G itens 33 e 34, 26/11/2026).
 *   33  Grupo de leis criado a partir de uma lei: a IA gera as palavras-chave, o sistema lista as leis da empresa que as
 *       contêm e o usuário escolhe quais entram no grupo (palavras e nome editáveis).
 *   34  Compartilhar documentos no grupo: os vínculos de uma lei (licenças, medições, treinamentos, infraestrutura,
 *       controles e fornecedores) numa unidade são copiados para as demais leis do grupo na mesma unidade.
 * Em cada empresa com o módulo legal: tabelas legislacao_grupo e legislacao_grupoxlei, views vw_legislacao_grupos e
 * vw_legislacao_grupoxlei, e o prompt LEGISLACAO_GRUPO_PALAVRAS (Administração › Prompts de IA), só se faltar.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-419-grupos-leis.php [--simular] [--banco=gcrown_dbp]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$rel = ['ok' => true, 'migracao' => '419', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];

$prompt = [
    'codigo' => 'LEGISLACAO_GRUPO_PALAVRAS',
    'nome_pt' => 'Palavras-chave para grupo de leis', 'nome_en' => 'Keywords for a law group', 'nome_es' => 'Palabras clave para grupo de leyes',
    'prompt_pt' => 'Você recebe o código, os artigos, os assuntos e o requisito de uma lei ambiental, de saúde e segurança ou de qualidade. Gere palavras-chave curtas (1 a 3 palavras cada) que identifiquem o TEMA da obrigação, '
        . 'para encontrar outras leis parecidas (ex.: "resíduos sólidos", "outorga", "efluentes", "ruído", "NR-10", "caldeiras", "emissões atmosféricas"). Evite palavras genéricas como "lei", "artigo", "empresa", "deve". '
        . 'Retorne exclusivamente JSON válido: {"nome_grupo":"nome curto do tema","palavras":["...","..."],"confianca":0}, com 3 a 8 palavras.',
    'prompt_en' => 'You receive the code, articles, subjects and requirement of an environmental, health and safety or quality law. Generate short keywords (1 to 3 words) that identify the TOPIC of the obligation, to find similar laws. '
        . 'Avoid generic words. Return exclusively valid JSON: {"nome_grupo":"short topic name","palavras":["...","..."],"confianca":0}, with 3 to 8 keywords, in Portuguese when the law is Brazilian.',
    'prompt_es' => 'Usted recibe el código, los artículos, los asuntos y el requisito de una ley ambiental, de salud y seguridad o de calidad. Genere palabras clave cortas (1 a 3 palabras) que identifiquen el TEMA de la obligación, para encontrar leyes parecidas. '
        . 'Evite palabras genéricas. Devuelva exclusivamente JSON válido: {"nome_grupo":"nombre corto del tema","palavras":["...","..."],"confianca":0}, con 3 a 8 palabras, en portugués cuando la ley es brasileña.',
];

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
        $pdo->exec('SET NAMES utf8mb4');
        if (!MigracaoCrud::existe($pdo, 'legislacaoxempresa')) { $rel['bancos'][$banco] = ['ignorado' => 'sem módulo legal']; continue; }
        if (!$simular) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `legislacao_grupo` (
  `CODGRUPO` INT NOT NULL AUTO_INCREMENT,
  `NOME` VARCHAR(150) NOT NULL,
  `PALAVRAS_CHAVE` TEXT NULL,
  `ORIGEM` VARCHAR(10) NOT NULL DEFAULT 'MANUAL',
  `CODUSUARIO` INT NULL,
  `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`CODGRUPO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS `legislacao_grupoxlei` (
  `CODGRUPOXLEI` INT NOT NULL AUTO_INCREMENT,
  `CODGRUPO` INT NOT NULL,
  `CODLEGISLACAO` INT NOT NULL,
  PRIMARY KEY (`CODGRUPOXLEI`),
  UNIQUE KEY `ux_grupo_lei` (`CODGRUPO`, `CODLEGISLACAO`),
  KEY `ix_grupo_lei_lei` (`CODLEGISLACAO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE OR REPLACE VIEW `vw_legislacao_grupos` AS
SELECT g.`CODGRUPO`, g.`NOME`, g.`PALAVRAS_CHAVE`, g.`ORIGEM`, g.`CODUSUARIO`, g.`CRIADO_EM`,
       (SELECT COUNT(*) FROM `legislacao_grupoxlei` x WHERE x.`CODGRUPO` = g.`CODGRUPO`) AS `QTD_LEIS`
  FROM `legislacao_grupo` g");
            $pdo->exec("CREATE OR REPLACE VIEW `vw_legislacao_grupoxlei` AS
SELECT x.`CODGRUPOXLEI`, x.`CODGRUPO`, x.`CODLEGISLACAO`, g.`NOME` AS `GRUPO`,
       COALESCE((SELECT lg.`CODIGO` FROM `glegislacao_dbp`.`legislacao` lg WHERE lg.`CODLEGISLACAO` = x.`CODLEGISLACAO` LIMIT 1), CONCAT('Lei ', x.`CODLEGISLACAO`)) AS `CODIGO`
  FROM `legislacao_grupoxlei` x JOIN `legislacao_grupo` g ON g.`CODGRUPO` = x.`CODGRUPO`");
            MigracaoCrud::garantir($pdo);
        }
        $info['tabelas_e_views'] = $simular ? 'a criar (se faltarem)' : 'ok';
        if (MigracaoCrud::existe($pdo, 'ia_prompts')) {
            if (MigracaoCrud::primeiro($pdo, 'ia_prompts', ['codigo' => $prompt['codigo']])) $info['prompt'] = 'já existe (mantido)';
            else { if (!$simular) MigracaoCrud::inserir($pdo, 'ia_prompts', $prompt); $info['prompt'] = $simular ? 'a criar' : 'criado'; }
        }
        $vinc = [];
        foreach (['legislacaoxlicencas', 'mm_medicaoxlegislacao', 'legislacaoxtreinamentos', 'legislacaoxinfraestruturas', 'legislacaoxdocumentos', 'legislacaoxfornecedores'] as $t)
            $vinc[$t] = MigracaoCrud::existe($pdo, $t) ? implode(',', MigracaoCrud::colunas($pdo, $t)) : 'não existe';
        $info['vinculos'] = $vinc;
        if (!$simular) $info['grupos'] = (int)$pdo->query('SELECT COUNT(*) FROM `legislacao_grupo`')->fetchColumn();
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
