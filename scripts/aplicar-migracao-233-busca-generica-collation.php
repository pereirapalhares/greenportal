<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$procedures = [
    'sp_crud_select' => <<<'SQL'
CREATE PROCEDURE sp_crud_select(IN p_tabela VARCHAR(128), IN p_filtros JSON, IN p_busca VARCHAR(255), IN p_limite INT, IN p_offset INT)
BEGIN
    DECLARE v_where LONGTEXT DEFAULT '1 = 1';
    DECLARE v_condition LONGTEXT;
    DECLARE v_sql LONGTEXT;
    SET SESSION group_concat_max_len = 1048576;
    IF p_filtros IS NOT NULL AND JSON_LENGTH(p_filtros) > 0 THEN
        SELECT GROUP_CONCAT(CASE
            WHEN JSON_TYPE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo)))) = 'OBJECT' AND JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.operator'))) IN ('lt','lte','gt','gte')
                THEN CONCAT('`', k.campo, '` ', CASE JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.operator'))) WHEN 'lt' THEN '<' WHEN 'lte' THEN '<=' WHEN 'gt' THEN '>' ELSE '>=' END, ' ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.value')))))
            WHEN JSON_TYPE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo)))) = 'OBJECT' AND JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.operator'))) = 'not'
                THEN CONCAT('`', k.campo, '` <> ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.value')))))
            WHEN JSON_TYPE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo)))) = 'OBJECT' AND JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.exact'))) = 'true'
                THEN CONCAT('`', k.campo, '` = ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.value')))))
            WHEN c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext')
                THEN CONCAT('CONVERT(`', k.campo, '` USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE ', QUOTE(CONCAT('%', JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo)))), '%')))
            ELSE CONCAT('`', k.campo, '` = ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo))))))
        END ORDER BY k.campo SEPARATOR ' AND ') INTO v_condition
        FROM JSON_TABLE(JSON_KEYS(p_filtros), '$[*]' COLUMNS (campo VARCHAR(128) PATH '$')) k
        INNER JOIN INFORMATION_SCHEMA.COLUMNS c ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = p_tabela AND c.COLUMN_NAME = k.campo;
        IF v_condition IS NOT NULL THEN SET v_where = CONCAT(v_where, ' AND ', v_condition); END IF;
    END IF;
    IF p_busca IS NOT NULL AND p_busca <> '' THEN
        SELECT GROUP_CONCAT(CONCAT('CONVERT(`', REPLACE(c.COLUMN_NAME,'`','``'), '` USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE ', QUOTE(CONCAT('%', p_busca, '%'))) SEPARATOR ' OR ') INTO v_condition
        FROM INFORMATION_SCHEMA.COLUMNS c WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = p_tabela AND c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext');
        IF v_condition IS NOT NULL THEN SET v_where = CONCAT(v_where, ' AND (', v_condition, ')'); END IF;
    END IF;
    SET v_sql = CONCAT('SELECT * FROM `', REPLACE(p_tabela, '`', '``'), '` WHERE ', v_where, ' LIMIT ', LEAST(GREATEST(COALESCE(p_limite,50),1),1000), ' OFFSET ', GREATEST(COALESCE(p_offset,0),0));
    SET @crud_sql = v_sql; PREPARE stmt FROM @crud_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
END
SQL,
    'sp_crud_count' => <<<'SQL'
CREATE PROCEDURE sp_crud_count(IN p_tabela VARCHAR(128), IN p_filtros JSON, IN p_busca VARCHAR(255))
BEGIN
    DECLARE v_where LONGTEXT DEFAULT '1 = 1';
    DECLARE v_condition LONGTEXT;
    DECLARE v_sql LONGTEXT;
    SET SESSION group_concat_max_len = 1048576;
    IF p_filtros IS NOT NULL AND JSON_LENGTH(p_filtros) > 0 THEN
        SELECT GROUP_CONCAT(CASE
            WHEN JSON_TYPE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo)))) = 'OBJECT' AND JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.operator'))) IN ('lt','lte','gt','gte') THEN CONCAT('`', k.campo, '` ', CASE JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.operator'))) WHEN 'lt' THEN '<' WHEN 'lte' THEN '<=' WHEN 'gt' THEN '>' ELSE '>=' END, ' ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.value')))))
            WHEN JSON_TYPE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo)))) = 'OBJECT' AND JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.operator'))) = 'not' THEN CONCAT('`', k.campo, '` <> ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.value')))))
            WHEN JSON_TYPE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo)))) = 'OBJECT' AND JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.exact'))) = 'true' THEN CONCAT('`', k.campo, '` = ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo), '.value')))))
            WHEN c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext') THEN CONCAT('CONVERT(`', k.campo, '` USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE ', QUOTE(CONCAT('%', JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo)))), '%')))
            ELSE CONCAT('`', k.campo, '` = ', QUOTE(JSON_UNQUOTE(JSON_EXTRACT(p_filtros, CONCAT('$.' , JSON_QUOTE(k.campo))))))
        END ORDER BY k.campo SEPARATOR ' AND ') INTO v_condition
        FROM JSON_TABLE(JSON_KEYS(p_filtros), '$[*]' COLUMNS (campo VARCHAR(128) PATH '$')) k
        INNER JOIN INFORMATION_SCHEMA.COLUMNS c ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = p_tabela AND c.COLUMN_NAME = k.campo;
        IF v_condition IS NOT NULL THEN SET v_where = CONCAT(v_where, ' AND ', v_condition); END IF;
    END IF;
    IF p_busca IS NOT NULL AND p_busca <> '' THEN
        SELECT GROUP_CONCAT(CONCAT('CONVERT(`', REPLACE(c.COLUMN_NAME,'`','``'), '` USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE ', QUOTE(CONCAT('%', p_busca, '%'))) SEPARATOR ' OR ') INTO v_condition
        FROM INFORMATION_SCHEMA.COLUMNS c WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = p_tabela AND c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext');
        IF v_condition IS NOT NULL THEN SET v_where = CONCAT(v_where, ' AND (', v_condition, ')'); END IF;
    END IF;
    SET v_sql = CONCAT('SELECT COUNT(*) AS total_registros FROM `', REPLACE(p_tabela, '`', '``'), '` WHERE ', v_where);
    SET @crud_count_sql = v_sql; PREPARE stmt FROM @crud_count_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
END
SQL,
];

$env = GreenPortalDatabase::env();
$targets = ['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'];
$result = [];
foreach ($targets as $database) {
    try {
        $pdo = GreenPortalDatabase::connect($env, $database, 'GPT');
        foreach ($procedures as $name => $definition) {
            $pdo->exec('DROP PROCEDURE IF EXISTS `' . $name . '`');
            $pdo->exec($definition);
        }
        $probe = $pdo->prepare('CALL sp_crud_select(:tabela,:filtros,:busca,25,0)');
        $probe->execute(['tabela'=>'vw_homologacao_fornecedores_grid','filtros'=>'{}','busca'=>'SODEXO']);
        $rows = $probe->fetchAll(PDO::FETCH_ASSOC); $probe->closeCursor();
        $migration = $pdo->prepare("INSERT INTO registros_migracao(codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes) VALUES('GREEN_GENERIC_SEARCH_COLLATION_233','sp_crud_select;sp_crud_count;views legadas','procedimentos de busca normalizados','CORRECAO','4.0.2','CONCLUIDA',?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,?) ON DUPLICATE KEY UPDATE status='CONCLUIDA',quantidade_registros=VALUES(quantidade_registros),concluida_em=CURRENT_TIMESTAMP,detalhes=VALUES(detalhes)");
        $migration->execute([count($rows), json_encode(['collation'=>'utf8mb4_0900_ai_ci','normalizacao'=>'CONVERT USING utf8mb4 antes do LIKE','procedimentos'=>array_keys($procedures)], JSON_UNESCAPED_UNICODE)]);
        $result[$database] = ['ok'=>true, 'fornecedores_sodexo'=>count($rows)];
    } catch (Throwable $e) { $result[$database] = ['ok'=>false, 'erro'=>$e->getMessage()]; }
}
echo json_encode(['ok'=>!array_filter($result, static fn(array $r): bool => empty($r['ok'])), 'migration'=>233, 'targets'=>$result], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . PHP_EOL;
