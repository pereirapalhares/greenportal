<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$sql = file_get_contents(dirname(__DIR__) . '/database/226_tipos_documentos_licenciatarios.sql');
if ($sql === false) throw new RuntimeException('Migração 226 não localizada.');

$databases = ['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'];
$result = [];

foreach ($databases as $database) {
    $pdo = GreenPortalDatabase::connect($env, $database, 'GPT');
    try {
        // A tabela é criada diretamente; o nome li_tipos_documentos é o catálogo.
        $pdo->exec(str_replace("CREATE OR REPLACE VIEW `li_tipos_documentos` AS\nSELECT `CODTIPODOCUMENTO`, `DSCTIPODOCUMENTO`, `ATIVO`, `ORDEM`\nFROM `li_tipos_documentos`;", '', $sql));

        $hasColumn = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='li_licencas' AND column_name='CODTIPODOCUMENTO'")->fetchColumn();
        if (!$hasColumn) {
            $pdo->exec("ALTER TABLE li_licencas ADD COLUMN CODTIPODOCUMENTO INT NULL AFTER DSCLICENCA");
            $pdo->exec("ALTER TABLE li_licencas ADD INDEX ix_li_licencas_tipo (CODTIPODOCUMENTO)");
        }

        $pdo->exec("INSERT INTO li_tipos_documentos (DSCTIPODOCUMENTO, ATIVO, ORDEM) VALUES ('Não classificado', 1, 0) ON DUPLICATE KEY UPDATE ATIVO=1");
        $typeId = (int)$pdo->query("SELECT CODTIPODOCUMENTO FROM li_tipos_documentos WHERE DSCTIPODOCUMENTO='Não classificado' LIMIT 1")->fetchColumn();
        $fill = $pdo->prepare("UPDATE li_licencas SET CODTIPODOCUMENTO=:tipo WHERE CODTIPODOCUMENTO IS NULL OR CODTIPODOCUMENTO=0");
        $fill->execute(['tipo' => $typeId]);

        $pdo->exec("CREATE OR REPLACE VIEW vw_licencas_grid AS
            SELECT l.CODLICENCA, l.codunidade AS CODUNIDADE, u.DSCUNIDADE, l.DSCLICENCA,
                   l.CODTIPODOCUMENTO, COALESCE(t.DSCTIPODOCUMENTO, '') AS DSCTIPODOCUMENTO,
                   NULLIF(l.DATA_VALIDADE, '0000-00-00') AS DATA_VALIDADE,
                   NULLIF(l.DATA_REACAO, '0000-00-00') AS DATA_REACAO,
                   NULLIF(l.data_protocolo, '0000-00-00 00:00:00') AS DATA_PROTOCOLO,
                   l.codusuario AS CODUSUARIO, COALESCE(us.nome, '') AS RESPONSAVEL,
                   CONCAT_WS(';', 'licencas', l.CODLICENCA) AS ARQUIVOS, l.codlista AS CODLISTA,
                   l.emprotocolo AS EMPROTOCOLO, NULLIF(l.protocolado_em, '0000-00-00') AS PROTOCOLADO_EM,
                   l.protocolado_no_prazo AS PROTOCOLADO_NO_PRAZO,
                   TO_DAYS(NOW()) - TO_DAYS(l.protocolado_em) AS QTD_DIAS_EM_PROTOCOLO,
                   l.CODSTATUS, l.observacao AS OBSERVACAO, l.CODCLASSIFICACAO, l.CODFREQUENCIA,
                   TO_DAYS(l.DATA_VALIDADE) - TO_DAYS(NOW()) AS QTDDIAS,
                   TO_DAYS(l.DATA_REACAO) - TO_DAYS(NOW()) AS QTDDIAS2
              FROM li_licencas l
              LEFT JOIN unidades u ON u.CODUNIDADE=l.codunidade
              LEFT JOIN usuarios us ON us.codusuario=l.codusuario
              LEFT JOIN li_tipos_documentos t ON t.CODTIPODOCUMENTO=l.CODTIPODOCUMENTO");

        $pdo->exec("INSERT INTO content_document_links (coddocumento,tipo_entidade,entidade_id,codunidade,papel,origem_vinculo,status)
            SELECT d.coddocumento,'LICENCA',CAST(d.origem_id AS UNSIGNED),COALESCE(l.codunidade,0),'ARQUIVO','MIGRACAO','ATIVO'
              FROM content_documents d LEFT JOIN li_licencas l ON l.CODLICENCA=CAST(d.origem_id AS UNSIGNED)
             WHERE d.origem_entidade IN ('licencas','li_licencas','GED_licencas') AND d.origem_id IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM content_document_links x WHERE x.coddocumento=d.coddocumento AND x.tipo_entidade='LICENCA' AND x.entidade_id=CAST(d.origem_id AS UNSIGNED) AND x.codunidade=COALESCE(l.codunidade,0) AND x.papel='ARQUIVO')");

        $pdo->exec("INSERT INTO registros_migracao (codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes)
            VALUES ('GREEN_LICENSE_DOCUMENT_TYPES_226','li_licencas;content_documents','li_tipos_documentos;content_document_links','ESTRUTURA','4.0.1','CONCLUIDA',(SELECT COUNT(*) FROM li_licencas),CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,JSON_OBJECT('fase',3,'tipo_separado_da_licenca',TRUE,'documentos_reutilizaveis',TRUE))
            ON DUPLICATE KEY UPDATE status=VALUES(status),quantidade_registros=VALUES(quantidade_registros),concluida_em=VALUES(concluida_em),detalhes=VALUES(detalhes)");
        $result[$database] = [
            'tipos' => (int)$pdo->query('SELECT COUNT(*) FROM li_tipos_documentos')->fetchColumn(),
            'licencas_classificadas' => (int)$pdo->query('SELECT COUNT(*) FROM li_licencas WHERE CODTIPODOCUMENTO IS NOT NULL')->fetchColumn(),
            'vinculos_licenca' => (int)$pdo->query("SELECT COUNT(*) FROM content_document_links WHERE tipo_entidade='LICENCA'")->fetchColumn(),
        ];
    } catch (Throwable $error) {
        throw new RuntimeException($database . ': ' . $error->getMessage(), 0, $error);
    }
}

echo json_encode(['ok' => true, 'migration' => 226, 'targets' => $result], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
