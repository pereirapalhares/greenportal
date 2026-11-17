-- GreenSolutions 4.0.1 — Correção dos menus do LAIS que apontavam para arquivos inexistentes.
-- Executar no guniversal_dbp pelo script scripts/aplicar-migracao-214-corrigir-menus-lais.php. Pode ser executada mais de uma vez.
--
-- Os menus LAIS_REQUISITOS_LEGAIS, LAIS_CONTROLES_DOCUMENTOS e LAIS_OBJETIVOS_METAS apontavam para
-- principal/moduloleglais_*.php, mas as telas se chamam principal/modulolegallais_*.php ("página não encontrada").
-- Só esses três endereços mudam: as telas moduloleglais_partesinteressadas, _aspectossociais, _impactossociais
-- e _revisoes existem com esse nome e não são alteradas.

UPDATE menus SET url = REPLACE(url, 'moduloleglais_requisitoslegais.php', 'modulolegallais_requisitoslegais.php')
 WHERE url LIKE '%moduloleglais\_requisitoslegais.php%';
UPDATE menus SET url = REPLACE(url, 'moduloleglais_controlesdocumentos.php', 'modulolegallais_controlesdocumentos.php')
 WHERE url LIKE '%moduloleglais\_controlesdocumentos.php%';
UPDATE menus SET url = REPLACE(url, 'moduloleglais_objetivosmetas.php', 'modulolegallais_objetivosmetas.php')
 WHERE url LIKE '%moduloleglais\_objetivosmetas.php%';
