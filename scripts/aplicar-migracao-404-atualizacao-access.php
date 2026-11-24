<?php
declare(strict_types=1);

/*
 * Migração 404 — Manutenção Legal › Atualização do Access (24/11/2026). Substitui o clsLegislacao.exe (.NET) e as telas
 * mlExcel/mlAccess do legado. Ver classes/AccessAtualizacao.php.
 *   - guniversal_dbp: access_arquivos (leis_modelo.mdb e .mdb das unidades enviados pelo operador), access_processamentos
 *     (pedidos SIMULAR/APLICAR), views vw_access_arquivos / vw_access_processamentos, procedure sp_access_reservar e o
 *     serviço ACCESS_ATUALIZACAO do orquestrador (trilha 1, a cada 1 min);
 *   - glegislacao_dbp: view vw_legislacao_access (dados da lei no formato das colunas do Access);
 *   - menu ATUALIZACAO_ACCESS em Manutenção Legal (replicado nas empresas; permissões = as do menu LEGISLACAO; só o
 *     ADMINISTRADOR — PermissaoTransacao::MENUS_SO_ADMIN).
 * Uso: php scripts\aplicar-migracao-404-atualizacao-access.php [--aplicar]   (pode rodar mais de uma vez)
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';

$aplicar = in_array('--aplicar', $argv ?? [], true);
$rel = ['ok' => true, 'migracao' => '404', 'modo' => $aplicar ? 'APLICAR' : 'SIMULACAO', 'universal' => [], 'legislacao' => [], 'bancos' => []];

$ddl = [];
$ddl['access_arquivos'] = "CREATE TABLE IF NOT EXISTS access_arquivos (
 codarquivo INT UNSIGNED NOT NULL AUTO_INCREMENT,
 tipo VARCHAR(10) NOT NULL, codempresa INT NOT NULL DEFAULT 0, codunidade INT NOT NULL DEFAULT 0, unidade_nome VARCHAR(150) NULL,
 nome_arquivo VARCHAR(255) NOT NULL, bytes BIGINT NULL, sha256 CHAR(64) NULL, leis INT NULL,
 enviado_por INT NULL, enviado_por_nome VARCHAR(150) NULL, enviado_em DATETIME NULL,
 atualizado_em DATETIME NULL, atualizado_processamento INT NULL,
 PRIMARY KEY (codarquivo), UNIQUE KEY ux_access_arquivo (tipo, codempresa, codunidade)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$ddl['access_processamentos'] = "CREATE TABLE IF NOT EXISTS access_processamentos (
 codprocessamento INT UNSIGNED NOT NULL AUTO_INCREMENT,
 modo VARCHAR(10) NOT NULL DEFAULT 'SIMULAR', fonte VARCHAR(10) NOT NULL DEFAULT 'PORTAL', empresas TEXT NULL, pedidos TEXT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'SOLICITADO',
 solicitado_por INT NULL, solicitado_por_nome VARCHAR(150) NULL, banco_origem VARCHAR(64) NULL, solicitado_em DATETIME NULL,
 inicio DATETIME NULL, fim DATETIME NULL, total_arquivos INT NULL, total_erros INT NULL,
 resumo LONGTEXT NULL, log LONGTEXT NULL,
 PRIMARY KEY (codprocessamento), KEY ix_access_proc_status (status, codprocessamento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$views = [];
$views['vw_access_arquivos'] = "CREATE OR REPLACE VIEW vw_access_arquivos AS
 SELECT codarquivo, tipo, codempresa, codunidade, unidade_nome, nome_arquivo, bytes, sha256, leis, enviado_por, enviado_por_nome, enviado_em,
        atualizado_em, atualizado_processamento FROM access_arquivos";
$views['vw_access_processamentos'] = "CREATE OR REPLACE VIEW vw_access_processamentos AS
 SELECT codprocessamento, modo, fonte, empresas, pedidos, status, solicitado_por, solicitado_por_nome, banco_origem, solicitado_em,
        inicio, fim, total_arquivos, total_erros, resumo, log FROM access_processamentos";
$sp = [];
$sp['sp_access_reservar'] = "CREATE PROCEDURE sp_access_reservar()
SQL SECURITY INVOKER
BEGIN
  DECLARE v_id INT DEFAULT NULL;
  IF EXISTS (SELECT 1 FROM access_processamentos WHERE status = 'PROCESSANDO' AND inicio >= DATE_SUB(NOW(), INTERVAL 3 HOUR)) THEN
    SELECT NULL AS codprocessamento LIMIT 0;
  ELSE
    SELECT codprocessamento INTO v_id FROM access_processamentos
     WHERE status = 'SOLICITADO' OR (status = 'PROCESSANDO' AND inicio < DATE_SUB(NOW(), INTERVAL 3 HOUR))
     ORDER BY codprocessamento LIMIT 1;
    IF v_id IS NOT NULL THEN
      UPDATE access_processamentos SET status = 'PROCESSANDO', inicio = NOW()
       WHERE codprocessamento = v_id AND (status = 'SOLICITADO' OR (status = 'PROCESSANDO' AND inicio < DATE_SUB(NOW(), INTERVAL 3 HOUR)));
      IF ROW_COUNT() = 1 THEN SELECT * FROM vw_access_processamentos WHERE codprocessamento = v_id;
      ELSE SELECT NULL AS codprocessamento LIMIT 0; END IF;
    ELSE
      SELECT NULL AS codprocessamento LIMIT 0;
    END IF;
  END IF;
END";
$vwLeg = "CREATE OR REPLACE VIEW vw_legislacao_access AS
 SELECT l.CODLEGISLACAO, l.CODIGO, t.DSCTIPO AS TIPO, l.NR, l.ANO, a.DSCAMBITO AS AMBITO,
        (SELECT GROUP_CONCAT(s.DSCASSUNTO ORDER BY s.DSCASSUNTO SEPARATOR '; ') FROM legislacaoxassunto x JOIN assuntos s ON s.CODASSUNTO = x.CODASSUNTO
          WHERE x.CODLEGISLACAO = l.CODLEGISLACAO) AS ASSUNTOS,
        l.ARTIGOS, l.MEIO_AMBIENTE, l.SEGURANCA, l.QUALIDADE, l.RESPOSABILIDADE, l.PADRAO
   FROM legislacao l LEFT JOIN tipos t ON t.CODTIPO = l.CODTIPO LEFT JOIN ambitos a ON a.CODAMBITO = l.CODAMBITO";
$servico = ['ACCESS_ATUALIZACAO', 'Atualização do Access (Manutenção Legal)', 1, 31, 1, 'scripts/access-atualizacao-worker.php', null, 1, null, null, 3600,
    'Atualiza os .mdb das unidades com as importações aplicadas (Legislação x Empresas, x Controle e Alterações). Substitui o clsLegislacao.exe.'];
$menu = ['ATUALIZACAO_ACCESS', 'Atualização do Access', 'Access update', 'Actualización de Access', '/principal/manutencaolegal_atualizacao_access.php', 'bi-database-up'];

$env = GreenPortalDatabase::env();
try {
    $u = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $u->exec('SET NAMES utf8mb4');
    if ($aplicar) { $criadas = MigracaoCrud::garantir($u); if ($criadas) $rel['universal']['sp_crud'] = 'criadas: ' . implode(', ', $criadas); }
    foreach ($ddl as $nome => $sql) {
        $existe = MigracaoCrud::existe($u, $nome);
        if (!$aplicar) { $rel['universal'][$nome] = $existe ? 'já existe' : 'será criada'; continue; }
        $u->exec($sql); $rel['universal'][$nome] = $existe ? 'já existia' : 'criada';
    }
    foreach ($views as $nome => $sql) { if ($aplicar) $u->exec($sql); $rel['universal'][$nome] = $aplicar ? 'ok' : 'será gravada'; }
    foreach ($sp as $nome => $sql) { if ($aplicar) { $u->exec("DROP PROCEDURE IF EXISTS `$nome`"); $u->exec($sql); } $rel['universal'][$nome] = $aplicar ? 'ok' : 'será gravada'; }
    if (!MigracaoCrud::existe($u, 'orq_servicos')) $rel['avisos'][] = 'orq_servicos não existe: o serviço ACCESS_ATUALIZACAO não foi cadastrado.';
    elseif (MigracaoCrud::primeiro($u, 'orq_servicos', ['codigo' => $servico[0]])) $rel['universal']['orq_servicos'] = 'ACCESS_ATUALIZACAO já cadastrado (mantido)';
    elseif (!$aplicar) $rel['universal']['orq_servicos'] = 'ACCESS_ATUALIZACAO será cadastrado (trilha 1, ordem 31, a cada 1 min)';
    else {
        MigracaoCrud::inserir($u, 'orq_servicos', array_combine(['codigo', 'nome', 'trilha', 'ordem', 'ativo', 'script', 'argumentos', 'intervalo_min', 'horario', 'dias_semana', 'limite_seg', 'descricao'], $servico));
        $rel['universal']['orq_servicos'] = 'ACCESS_ATUALIZACAO cadastrado (trilha 1, ordem 31, a cada 1 min)';
    }
} catch (Throwable $e) { $rel['ok'] = false; $rel['universal']['erro'] = $e->getMessage(); }

try {
    $leg = GreenPortalDatabase::connect($env, (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp'), 'GLEGISLACAO');
    $leg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($aplicar) {
        $leg->exec($vwLeg);
        $leg->exec('SET SESSION group_concat_max_len = 65535');
        $rel['legislacao']['vw_legislacao_access'] = 'ok: ' . (int)$leg->query('SELECT COUNT(*) FROM vw_legislacao_access')->fetchColumn() . ' lei(s)';
        $rel['legislacao']['exemplo'] = $leg->query("SELECT CODIGO, TIPO, NR, ANO, AMBITO, LEFT(ASSUNTOS, 80) ASSUNTOS FROM vw_legislacao_access WHERE CODIGO IN ('RES1729', 'AL0366')")->fetchAll(PDO::FETCH_ASSOC);
    } else $rel['legislacao']['vw_legislacao_access'] = 'será gravada';
} catch (Throwable $e) { $rel['ok'] = false; $rel['legislacao']['erro'] = $e->getMessage(); }

$cat = new MenuCatalogoService($env);
try {
    $u2 = $cat->universal();
    $u2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    [$codigo, $pt, $en, $es, $url, $icone] = $menu;
    $irmao = MigracaoCrud::primeiro($u2, 'menus', ['codigo' => 'LEGISLACAO']);
    if (!$irmao) throw new RuntimeException('menu LEGISLACAO (Manutenção Legal) não encontrado no universal');
    $irmao = array_change_key_case($irmao);
    $existe = MigracaoCrud::primeiro($u2, 'menus', ['codigo' => $codigo]);
    if ($existe) { if ($aplicar) $cat->replicar($codigo, null, false); $rel['universal']['menu'] = 'já existia (codmenu ' . $existe['codmenu'] . ')'; }
    elseif (!$aplicar) $rel['universal']['menu'] = 'será criado em Manutenção Legal: ' . $pt;
    else {
        $ordem = 0;
        foreach (MigracaoCrud::selecionar($u2, 'menus', ['codmenu_pai' => (int)$irmao['codmenu_pai']]) as $m) $ordem = max($ordem, (int)(array_change_key_case($m)['ordem'] ?? 0));
        $g = $cat->gravar(['codmenu_pai' => (int)$irmao['codmenu_pai'], 'modulo' => $irmao['modulo'] ?? null, 'codigo' => $codigo, 'label' => $pt, 'label_pt' => $pt,
            'label_en' => $en, 'label_es' => $es, 'url' => $url, 'nivel' => (int)($irmao['nivel'] ?? 2), 'ordem' => $ordem + 1, 'icone' => $icone, 'ativo' => 1]);
        $erros = array_filter($g['empresas'], static fn($r) => str_starts_with((string)$r, 'ERRO'));
        $rel['universal']['menu'] = 'criado (codmenu ' . $g['codmenu'] . ', ' . count($g['empresas']) . ' empresa(s)' . ($erros ? ', com erro em: ' . implode(', ', array_keys($erros)) : '') . ')';
        if ($erros) $rel['ok'] = false;
    }
} catch (Throwable $e) { $rel['ok'] = false; $rel['universal']['menu_erro'] = $e->getMessage(); }

foreach ($cat->empresas() as $banco => $pdo) {
    try {
        $origem = MigracaoCrud::primeiro($pdo, 'menus', ['codigo' => 'LEGISLACAO']);
        $novo = MigracaoCrud::primeiro($pdo, 'menus', ['codigo' => $menu[0]]);
        if (!$origem) { $rel['bancos'][$banco] = 'sem o menu LEGISLACAO: nada a copiar'; continue; }
        if (!$novo) { $rel['bancos'][$banco] = $aplicar ? 'menu ainda não replicado neste banco' : 'menu será replicado; permissões = as de Legislação'; continue; }
        $cont = 0;
        foreach (['permissoes', 'perfil_menus'] as $t) {
            if (!MigracaoCrud::existe($pdo, $t)) continue;
            foreach (MigracaoCrud::selecionar($pdo, $t, ['codmenu' => (int)$origem['codmenu']]) as $p) {
                $p = array_change_key_case($p);
                $flags = array_filter($p, static fn($k) => str_starts_with((string)$k, 'pode_'), ARRAY_FILTER_USE_KEY);
                if ($flags && !array_filter($flags)) continue;
                if (MigracaoCrud::primeiro($pdo, $t, ['codperfil' => (int)$p['codperfil'], 'codmenu' => (int)$novo['codmenu']])) continue;
                if ($aplicar) MigracaoCrud::inserir($pdo, $t, ['codperfil' => (int)$p['codperfil'], 'codmenu' => (int)$novo['codmenu']] + $flags);
                $cont++;
            }
        }
        $rel['bancos'][$banco] = $cont . ' permissão(ões) ' . ($aplicar ? 'criada(s)' : 'seriam criadas') . ' (as mesmas de Legislação)';
    } catch (Throwable $e) { $rel['ok'] = false; $rel['bancos'][$banco] = 'erro: ' . $e->getMessage(); }
}
$rel['depois'] = 'o menu aparece ao ADMINISTRADOR no próximo login (ou ao abrir outra tela, quando a sessão é atualizada)';
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
