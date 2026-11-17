<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$targets = ['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'];
$menuPdo = GreenPortalDatabase::connect($env, $env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp', 'GUNIVERSAL');
$result = [];

foreach ($targets as $database) {
    $pdo = GreenPortalDatabase::connect($env, $database, 'GPT');
    $pdo->exec("CREATE OR REPLACE VIEW vw_documentos_compartilhados_grid AS
        SELECT d.coddocumento, d.codigo, d.titulo, d.tipo_documento, d.status,
               d.versao_atual, COUNT(DISTINCT l.codvinculodocumento) AS QTD_VINCULOS,
               COUNT(DISTINCT l.tipo_entidade) AS QTD_CONTEXTOS,
               GROUP_CONCAT(DISTINCT l.tipo_entidade ORDER BY l.tipo_entidade SEPARATOR ', ') AS CONTEXTOS,
               GROUP_CONCAT(DISTINCT COALESCE(u.DSCUNIDADE, CONCAT('Unidade ', l.codunidade)) ORDER BY u.DSCUNIDADE SEPARATOR ', ') AS UNIDADES
          FROM content_documents d
          INNER JOIN content_document_links l ON l.coddocumento=d.coddocumento AND l.status='ATIVO'
          LEFT JOIN unidades u ON u.CODUNIDADE=l.codunidade
         GROUP BY d.coddocumento,d.codigo,d.titulo,d.tipo_documento,d.status,d.versao_atual
        HAVING COUNT(DISTINCT l.codvinculodocumento) > 1");

    $pdo->exec("CREATE OR REPLACE VIEW vw_requisitos_sem_evidencia_grid AS
        SELECT CONCAT(CAST(r.CODLEGISLACAO AS CHAR), '-', CAST(r.codunidade AS CHAR)) AS CODRELATORIO,
               r.CODLEGISLACAO, r.codunidade AS CODUNIDADE,
               COALESCE(l.CODIGO, CONCAT('Requisito ', r.CODLEGISLACAO)) AS CODIGO,
               COALESCE(l.NR, '') AS NUMERO, COALESCE(l.ANO, '') AS ANO,
               COALESCE(u.DSCUNIDADE, CONCAT('Unidade ', r.codunidade)) AS DSCUNIDADE,
               COUNT(DISTINCT r.CODLICENCA) AS QTD_LICENCAS
          FROM legislacaoxlicencas r
          LEFT JOIN glegislacao_dbp.legislacao l ON l.CODLEGISLACAO=r.CODLEGISLACAO
          LEFT JOIN unidades u ON u.CODUNIDADE=r.codunidade
          LEFT JOIN content_document_links v ON v.codrequisitolegal=r.CODLEGISLACAO
             AND v.codunidade=r.codunidade AND v.status='ATIVO'
             AND v.tipo_entidade IN ('LICENCA_REQUISITO','CONTROLE_REQUISITO','REQUISITO_LEGAL')
         WHERE v.codvinculodocumento IS NULL
         GROUP BY r.CODLEGISLACAO,r.codunidade,l.CODIGO,l.NR,l.ANO,u.DSCUNIDADE");

    $parent = (int)$menuPdo->query("SELECT codmenu FROM menus WHERE codigo='MODULO_LEGAL' LIMIT 1")->fetchColumn();
    $nextMenu = ((int)$menuPdo->query('SELECT COALESCE(MAX(codmenu),0) FROM menus')->fetchColumn()) + 1;
    $insertMenu = $menuPdo->prepare("INSERT INTO menus(codmenu,codmenu_pai,modulo,codigo,label,label_pt,label_en,label_es,url,nivel,ordem,ativo)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,1)
        ON DUPLICATE KEY UPDATE codmenu_pai=VALUES(codmenu_pai),modulo=VALUES(modulo),label=VALUES(label),label_pt=VALUES(label_pt),label_en=VALUES(label_en),label_es=VALUES(label_es),url=VALUES(url),nivel=VALUES(nivel),ordem=VALUES(ordem),ativo=1");
    $menus = [
        ['DOCUMENTOS_COMPARTILHADOS','Documentos compartilhados','Shared documents','Documentos compartidos','/principal/documentos_compartilhados.php',5],
        ['REQUISITOS_SEM_EVIDENCIA','Requisitos sem evidência','Requirements without evidence','Requisitos sin evidencia','/principal/requisitos_sem_evidencia.php',6],
    ];
    $menus = array_values(array_filter($menus, static fn(array $menu): bool => (string)($menu[0] ?? '') !== 'REQUISITOS_SEM_EVIDENCIA'));
    foreach ($menus as [$code,$pt,$en,$es,$url,$order]) {
        $existingMenu = $menuPdo->prepare('SELECT codmenu FROM menus WHERE codigo=? LIMIT 1');
        $existingMenu->execute([$code]); $menuId = (int)$existingMenu->fetchColumn();
        if ($menuId <= 0) {
            $menuId = $nextMenu++;
            $repair = $menuPdo->prepare('UPDATE menus SET codmenu=? WHERE codigo=? AND codmenu=0');
            $repair->execute([$menuId,$code]);
        }
        $insertMenu->execute([$menuId,$parent,'MANUTENCAO_LEGAL',$code,$pt,$pt,$en,$es,$url,2,$order]);
        $menuId = (int)$menuPdo->query("SELECT codmenu FROM menus WHERE codigo=" . $menuPdo->quote($code) . " LIMIT 1")->fetchColumn();
        $profile = $pdo->prepare('SELECT codperfil FROM perfis WHERE codigo=? AND ativo=1 LIMIT 1');
        $profile->execute(['ADMINISTRADOR']); $profileId=(int)$profile->fetchColumn();
        if ($profileId > 0) {
            $grant = $pdo->prepare("INSERT INTO perfil_menus(codperfil,codmenu,pode_incluir,pode_visualizar,pode_editar,pode_excluir,pode_exportar_excel,pode_gerar_pdf) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE pode_visualizar=1,pode_exportar_excel=1,pode_gerar_pdf=1");
            $grant->execute([$profileId,$menuId,0,1,0,0,1,1]);
            $grant2 = $pdo->prepare("INSERT INTO permissoes(codperfil,codmenu,pode_incluir,pode_visualizar,pode_editar,pode_excluir,pode_exportar_excel,pode_gerar_pdf) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE pode_visualizar=1,pode_exportar_excel=1,pode_gerar_pdf=1");
            $grant2->execute([$profileId,$menuId,0,1,0,0,1,1]);
        }
    }
    $migration = $pdo->prepare("INSERT INTO registros_migracao(codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes) VALUES('GREEN_PHASE5_REPORTS_231','content_documents;content_document_links;legislacaoxlicencas','vw_documentos_compartilhados_grid;vw_requisitos_sem_evidencia_grid','ESTRUTURA','4.0.1','CONCLUIDA',?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,?) ON DUPLICATE KEY UPDATE status='CONCLUIDA',quantidade_registros=VALUES(quantidade_registros),concluida_em=CURRENT_TIMESTAMP,detalhes=VALUES(detalhes)");
    $migration->execute([(int)$pdo->query('SELECT COUNT(*) FROM content_document_links WHERE status=\'ATIVO\'')->fetchColumn(), json_encode(['fase'=>5,'relatorios'=>['documentos_compartilhados','requisitos_sem_evidencia'],'idempotente'=>true], JSON_UNESCAPED_UNICODE)]);
    $result[$database] = ['documentos_compartilhados'=>(int)$pdo->query('SELECT COUNT(*) FROM vw_documentos_compartilhados_grid')->fetchColumn(),'requisitos_sem_evidencia'=>(int)$pdo->query('SELECT COUNT(*) FROM vw_requisitos_sem_evidencia_grid')->fetchColumn()];
}

echo json_encode(['ok'=>true,'migration'=>231,'targets'=>$result],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
