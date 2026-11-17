<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$e = GreenPortalDatabase::env();
$u = GreenPortalDatabase::connect($e, (string)($e['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$u->exec("CREATE TABLE IF NOT EXISTS modulos_sistema (
    codigo_modulo VARCHAR(60) NOT NULL PRIMARY KEY,
    nome_pt VARCHAR(150) NOT NULL,
    nome_en VARCHAR(150) NOT NULL,
    nome_es VARCHAR(150) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$u->exec("CREATE TABLE IF NOT EXISTS empresa_modulos (
    codempresa BIGINT UNSIGNED NOT NULL,
    codigo_modulo VARCHAR(60) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 0,
    atualizado_por BIGINT NULL,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (codempresa, codigo_modulo),
    INDEX ix_empresa_modulos_modulo (codigo_modulo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$modulos = [
    ['MODULO_LEGAL','Módulo Legal / Gestão Ambiental','Legal Module / Environmental Management','Módulo Legal / Gestión Ambiental',10],
    ['HOMOLOGACAO_FORNECEDORES','Homologação de Fornecedores','Supplier Homologation','Homologación de Proveedores',20],
    ['NAO_CONFORMIDADES','Não Conformidades','Nonconformities','No conformidades',30],
];
$q = $u->prepare('INSERT INTO modulos_sistema (codigo_modulo,nome_pt,nome_en,nome_es,ordem) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE nome_pt=VALUES(nome_pt),nome_en=VALUES(nome_en),nome_es=VALUES(nome_es),ordem=VALUES(ordem),ativo=1');
foreach ($modulos as $m) $q->execute($m);
$empresas = $u->query("SELECT codempresa,codigo FROM empresa WHERE UPPER(codigo) IN ('ATLAS','MODELO','MODELO2')")->fetchAll(PDO::FETCH_ASSOC);
$liberacoes = ['ATLAS' => ['MODULO_LEGAL','HOMOLOGACAO_FORNECEDORES'], 'MODELO' => ['MODULO_LEGAL','HOMOLOGACAO_FORNECEDORES','NAO_CONFORMIDADES'], 'MODELO2' => ['MODULO_LEGAL','HOMOLOGACAO_FORNECEDORES','NAO_CONFORMIDADES']];
$ins = $u->prepare('INSERT INTO empresa_modulos (codempresa,codigo_modulo,ativo) VALUES (?,?,?) ON DUPLICATE KEY UPDATE ativo=VALUES(ativo)');
foreach ($empresas as $empresa) foreach ($modulos as $m) $ins->execute([(int)$empresa['codempresa'], $m[0], in_array(strtoupper((string)$empresa['codigo']), array_keys($liberacoes), true) && in_array($m[0], $liberacoes[strtoupper((string)$empresa['codigo'])], true) ? 1 : 0]);
$parent = (int)$u->query("SELECT codmenu FROM menus WHERE codigo='SEGURANCA' LIMIT 1")->fetchColumn();
$menuIdExisting = (int)$u->query("SELECT codmenu FROM menus WHERE codigo='SEGURANCA_MODULOS_EMPRESA' LIMIT 1")->fetchColumn();
$menuId = $menuIdExisting > 0 ? $menuIdExisting : ((int)$u->query('SELECT COALESCE(MAX(codmenu),0)+1 FROM menus')->fetchColumn());
$menu = $u->prepare("INSERT INTO menus (codmenu,codmenu_pai,modulo,codigo,label,label_pt,label_en,label_es,url,nivel,ordem,icone,ativo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE codmenu=VALUES(codmenu),codmenu_pai=VALUES(codmenu_pai),modulo=VALUES(modulo),label=VALUES(label),label_pt=VALUES(label_pt),label_en=VALUES(label_en),label_es=VALUES(label_es),url=VALUES(url),nivel=VALUES(nivel),ordem=VALUES(ordem),icone=VALUES(icone),ativo=1");
$menu->execute([$menuId,$parent,'SEGURANCA','SEGURANCA_MODULOS_EMPRESA','Módulos por empresa','Módulos por empresa','Modules by company','Módulos por empresa','/principal/seguranca_modulos_empresa.php',2,15,'bi-grid-3x3-gap']);
$menuId = (int)$u->query("SELECT codmenu FROM menus WHERE codigo='SEGURANCA_MODULOS_EMPRESA' LIMIT 1")->fetchColumn();
foreach (['gatlas_dbp','gmodelo_dbp','gmodelo2_dbp'] as $db) { try { $p=GreenPortalDatabase::connect($e,$db,'GPT'); $old=(int)$p->query("SELECT codmenu FROM menus WHERE codigo='SEGURANCA_MODULOS_EMPRESA' LIMIT 1")->fetchColumn(); $localId=$old>0?$old:((int)$p->query('SELECT COALESCE(MAX(codmenu),0)+1 FROM menus')->fetchColumn()); $p->prepare("INSERT INTO menus (codmenu,codmenu_pai,modulo,codigo,label,label_pt,label_en,label_es,url,nivel,ordem,ativo) VALUES (?,?,?,?,?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE codmenu=VALUES(codmenu),codmenu_pai=VALUES(codmenu_pai),modulo=VALUES(modulo),label=VALUES(label),label_pt=VALUES(label_pt),label_en=VALUES(label_en),label_es=VALUES(label_es),url=VALUES(url),nivel=VALUES(nivel),ordem=VALUES(ordem),ativo=1")->execute([$localId,(int)$p->query("SELECT codmenu FROM menus WHERE codigo='SEGURANCA' LIMIT 1")->fetchColumn(),'SEGURANCA','SEGURANCA_MODULOS_EMPRESA','Módulos por empresa','Módulos por empresa','Modules by company','Módulos por empresa','/principal/seguranca_modulos_empresa.php',2,15]); } catch (Throwable $x) { if ($db !== 'gmodelo2_dbp') throw $x; } }
echo json_encode(['ok'=>true,'migracao'=>265,'empresas'=>$empresas,'menu_id'=>$menuId],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
