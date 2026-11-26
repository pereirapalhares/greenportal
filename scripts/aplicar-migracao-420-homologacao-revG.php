<?php
declare(strict_types=1);

/*
 * Migração 420 — Homologação (Revisão G, bloco D, 26/11/2026):
 *   145  Portaria dentro da Homologação: o menu Portaria (com Documentação de Fornecedor e de Funcionário) passa a ficar dentro
 *        de "Homologação de Fornecedores" no catálogo de menus (guniversal_dbp.menus). As telas, os códigos e as permissões
 *        não mudam.
 *   146/147  Mensagens entre a empresa e o fornecedor sobre os documentos do fornecedor e dos funcionários: tabela
 *        hom_chat_mensagem e view vw_hom_chat_mensagem (a conversa começa sozinha quando a IA reprova o documento).
 *   152  Aprovadores dos documentos de fornecedor: tabela hom_documento_aprovador e view vw_hom_documento_aprovador
 *        (documento exigido × pessoa ou grupo); o painel de Aprovações mostra cada documento "Pendente com a empresa" só a
 *        quem aprova aquele documento (sem regra para o documento, continua para todos que têm a permissão).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-420-homologacao-revG.php [--simular] [--banco=gcrown_dbp]
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
$rel = ['ok' => true, 'migracao' => '420', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'menus' => [], 'bancos' => []];

// ---------------------------------------------------------------- 145: menu Portaria dentro da Homologação
try {
    $u = GreenPortalDatabase::connect($env, $bancoUniversal, 'GUNIVERSAL');
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $hom = MigracaoCrud::primeiro($u, 'menus', ['codigo' => 'HOMOLOGACAO_FORNECEDORES']);
    $por = MigracaoCrud::primeiro($u, 'menus', ['codigo' => 'PORTARIA']);
    if (!$hom || !$por) $rel['menus']['portaria'] = 'menu HOMOLOGACAO_FORNECEDORES ou PORTARIA não encontrado';
    else {
        $pai = (int)$hom['codmenu'];
        $ordem = 0;
        foreach (MigracaoCrud::selecionar($u, 'menus', ['codmenu_pai' => $pai]) as $f) $ordem = max($ordem, (int)$f['ordem']);
        if ((int)$por['codmenu_pai'] === $pai) $rel['menus']['portaria'] = 'já está dentro da Homologação (codmenu ' . $por['codmenu'] . ')';
        else {
            if (!$simular) {
                MigracaoCrud::atualizar($u, 'menus', ['codmenu' => (int)$por['codmenu']], ['codmenu_pai' => $pai, 'nivel' => (int)$hom['nivel'] + 1, 'ordem' => $ordem + 10]);
                foreach (MigracaoCrud::selecionar($u, 'menus', ['codmenu_pai' => (int)$por['codmenu']]) as $f)
                    MigracaoCrud::atualizar($u, 'menus', ['codmenu' => (int)$f['codmenu']], ['nivel' => (int)$hom['nivel'] + 2]);
            }
            $rel['menus']['portaria'] = ($simular ? 'irá para' : 'movido para') . ' dentro de HOMOLOGACAO_FORNECEDORES (codmenu ' . $pai . '), no fim da lista';
        }
    }
} catch (Throwable $e) { $rel['ok'] = false; $rel['menus']['erro'] = $e->getMessage(); }

// ---------------------------------------------------------------- 146/147 e 152: bancos das empresas
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
        if (!MigracaoCrud::existe($pdo, 'fo_fornecedorxcriterios')) { $rel['bancos'][$banco] = ['ignorado' => 'sem homologação']; continue; }
        if (!$simular) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `hom_chat_mensagem` (
  `CODMENSAGEM` INT NOT NULL AUTO_INCREMENT,
  `CONTEXTO` VARCHAR(30) NOT NULL,
  `CODREGISTRO` INT NOT NULL,
  `CODUSUARIO` INT NULL,
  `LADO` VARCHAR(10) NOT NULL DEFAULT 'EMPRESA',
  `TEXTO` TEXT NOT NULL,
  `AUTOMATICA` TINYINT NOT NULL DEFAULT 0,
  `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `LIDA_EM` DATETIME NULL,
  PRIMARY KEY (`CODMENSAGEM`),
  KEY `ix_chat_registro` (`CONTEXTO`, `CODREGISTRO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE OR REPLACE VIEW `vw_hom_chat_mensagem` AS
SELECT m.`CODMENSAGEM`, m.`CONTEXTO`, m.`CODREGISTRO`, m.`CODUSUARIO`, COALESCE(NULLIF(TRIM(u.`nome`), ''), '') AS `USUARIO`, m.`LADO`, m.`TEXTO`, m.`AUTOMATICA`, m.`CRIADO_EM`, m.`LIDA_EM`
  FROM `hom_chat_mensagem` m LEFT JOIN `usuarios` u ON u.`codusuario` = m.`CODUSUARIO`");
            $pdo->exec("CREATE TABLE IF NOT EXISTS `hom_documento_aprovador` (
  `CODAPROVADOR` INT NOT NULL AUTO_INCREMENT,
  `CODCRITERIOFORNECEDOR` INT NULL COMMENT 'documento exigido; NULL = todos os documentos',
  `CODUSUARIO` INT NULL,
  `CODGRUPO` INT NULL,
  `ATIVO` CHAR(1) NOT NULL DEFAULT 'S',
  `CODUSUARIO_INCLUSAO` INT NULL,
  `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`CODAPROVADOR`),
  KEY `ix_aprovador_criterio` (`CODCRITERIOFORNECEDOR`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE OR REPLACE VIEW `vw_hom_documento_aprovador` AS
SELECT a.`CODAPROVADOR`, a.`CODCRITERIOFORNECEDOR`, a.`CODUSUARIO`, a.`CODGRUPO`, a.`ATIVO`, a.`CODUSUARIO_INCLUSAO`, a.`CRIADO_EM`
  FROM `hom_documento_aprovador` a");
            MigracaoCrud::garantir($pdo);
        }
        $info['hom_chat_mensagem'] = $simular ? 'a criar (se faltar)' : 'ok';
        $info['hom_documento_aprovador'] = $simular ? 'a criar (se faltar)' : 'ok';
        // colunas para a tela de aprovadores (conferência)
        $info['colunas_criterios'] = MigracaoCrud::existe($pdo, 'vw_homologacao_criterios_fornecedores_grid') ? implode(',', array_slice(MigracaoCrud::colunas($pdo, 'vw_homologacao_criterios_fornecedores_grid'), 0, 12)) : 'sem vw_homologacao_criterios_fornecedores_grid';
        $info['colunas_doc'] = implode(',', array_slice(MigracaoCrud::colunas($pdo, 'fo_fornecedorxcriterios'), 0, 12));
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
