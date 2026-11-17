<?php
declare(strict_types=1);

/*
 * Migração 330 — dono do arquivo no GED (22/10/2026).
 * Regra: o dono do arquivo (quem enviou) tem acesso total a ele — ver, baixar/imprimir e excluir —, mesmo sem essas
 * permissões no perfil; os demais usuários seguem o perfil. Registro encerrado (NC finalizada) continua só para consulta.
 * O dono vem do registro do envio que o greenportal já grava (content_documents / content_versions, GedStorageService::store).
 * Cria em cada banco de empresa a procedure sp_ged_arquivos_do_usuario(tipo, código do registro, usuário), usada por
 * classes/GedPermissaoTipo.php::arquivosDoUsuario. Só leitura sobre os dados; não altera arquivos nem o legado.
 * Pode ser executada mais de uma vez.
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-330-ged-dono-arquivo.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';

$sql = <<<'PROC'
CREATE PROCEDURE `sp_ged_arquivos_do_usuario`(IN p_tipo VARCHAR(60), IN p_registro VARCHAR(60), IN p_usuario BIGINT)
SQL SECURITY INVOKER
READS SQL DATA
BEGIN
  -- Arquivos do registro enviados pelo usuário (dono do arquivo). Origem gravada pelo upload: GED_<TIPO> / código do registro.
  SELECT DISTINCT v.nome_arquivo AS ARQUIVO
    FROM content_versions v
    JOIN content_documents d ON d.coddocumento = v.coddocumento
   WHERE d.origem_entidade = CONCAT('GED_', UPPER(p_tipo))
     AND d.origem_id = p_registro
     AND v.codusuario = p_usuario
     AND v.nome_arquivo IS NOT NULL AND v.nome_arquivo <> '';
END
PROC;

$rel = ['ok' => true, 'migracao' => '330', 'bancos' => []];
try {
    $cat = new MenuCatalogoService();
    foreach ($cat->empresas() as $banco => $p) {
        try {
            if (!MigracaoCrud::existe($p, 'content_versions') || !MigracaoCrud::existe($p, 'content_documents')) {
                $rel['bancos'][$banco] = 'sem content_versions/content_documents: procedure não criada (vale só o perfil)';
                continue;
            }
            $p->exec('DROP PROCEDURE IF EXISTS `sp_ged_arquivos_do_usuario`');
            $p->exec($sql);
            $s = $p->query("SELECT COUNT(*) FROM content_versions v JOIN content_documents d ON d.coddocumento = v.coddocumento WHERE d.origem_entidade LIKE 'GED\\_%' AND v.codusuario IS NOT NULL");
            $rel['bancos'][$banco] = 'procedure gravada; arquivos com dono registrado: ' . (int)$s->fetchColumn();
            $s->closeCursor();
        } catch (Throwable $e) {
            $rel['ok'] = false;
            $rel['bancos'][$banco] = 'ERRO: ' . mb_substr($e->getMessage(), 0, 300);
        }
    }
} catch (Throwable $e) {
    $rel['ok'] = false;
    $rel['erro'] = $e->getMessage();
}

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_330_ged_dono_arquivo.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
