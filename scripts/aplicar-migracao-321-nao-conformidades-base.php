<?php
declare(strict_types=1);

/*
 * Migração 321 — Não Conformidades: base do fluxo (etapa 1 da reconstrução, 20/10/2026).
 * Grava as views (vw_nc_*) e procedures (sp_nc_*) de database/321_nao_conformidades_base.sql em cada banco de cliente que
 * tem NC, e retira o gatilho tr_nc_indicacao_ocorrencia_workflow_audit (duplicava o histórico). Não altera dados.
 * Antes confere o que o fluxo precisa: tabelas de NC, chaves com AUTO_INCREMENT, função getStatusPlanoAcaoNC e o catálogo
 * green_universal.md_tabelasxcampos. Cria nc_workflow_historico onde não existir (mesma estrutura da migração 111).
 * Migração 322 (21/10/2026): rodar de novo grava fn_nc_faixa e as procedures que numeram o que é criado no greenportal
 * na faixa própria (ocorrências e causas a partir de 50001, ações a partir de 500001; ver SincronismoLegado::FAIXAS_NOVO).
 * Migração 323 (21/10/2026): rodar de novo grava sp_nc_alertar — cada passagem de etapa avisa os envolvidos em Home › Alertas
 * (mesmos destinatários dos e-mails do legado; precisa de alertas e nc_listadistribuicaoxusuarios).
 * Migração 325 (21/10/2026, etapa 3 — tipo Fornecedor): rodar de novo grava as listas do tipo Fornecedor, o aviso "Sr. Fornecedor",
 *   a passagem para "Plano de Ação" ao incluir causa/ação (legado) e cria o menu Não Conformidades › Nova ocorrência do fornecedor
 *   (NAO_CONFORMIDADES_NOVA_OCORRENCIA_FORNECEDOR), com as mesmas permissões da Nova ocorrência da empresa, exceto perfis de fornecedor.
 * Migração 326 (21/10/2026, etapa 3 — Planejamento): rodar de novo grava o fluxo do Planejamento (Plano de Ação direto, ações sem
 *   causa, avanço automático com as ações a 100%) e cria o menu Não Conformidades › Nova ocorrência de planejamento
 *   (NAO_CONFORMIDADES_NOVA_OCORRENCIA_PLANEJAMENTO), com as permissões da Nova ocorrência da empresa, exceto perfis de fornecedor.
 * Migração 327 (21/10/2026): no legado (Empresa, Fornecedor, Cliente) a ação só aponta para a causa (nc_planodeacao.CODOCORRENCIA
 *   NULL; Crown 16.466 ações). Rodar de novo grava fn_nc_plano_ocorrencia e passa vw_nc_planodeacao, vw_nc_envolvidos e as procedures
 *   de ação a achar a ocorrência pela causa; e tira de vminhasocorrenciasnc (Minhas ocorrências) a condição que escondia essas
 *   ações do responsável (cópia da definição anterior em storage/logs/migracao_327_vminhas_<banco>.sql, gravada uma vez).
 * Migração 327 — Cliente (21/10/2026, etapa 3, decisões do usuário): listas do tipo Cliente em vw_nc_opcoes, campos mais usados
 *   obrigatórios no envio, menu Não Conformidades › Nova ocorrência de cliente (NAO_CONFORMIDADES_NOVA_OCORRENCIA_CLIENTE) e o grupo
 *   Não Conformidades › Cadastros com as 7 listas do Cliente (classes/NaoConformidadeCadastros.php): cria a tabela que faltar no banco,
 *   põe o AUTO_INCREMENT na faixa do greenportal (50001) e grava view, validação de exclusão e gatilho; permissão inicial só para
 *   ADMINISTRADOR e ADM_DA_EMPRESA (os demais perfis em Segurança › Permissões). Menus pelo catálogo universal.
 * Migração 328 — Assistência Técnica (22/10/2026, etapa 3, decisões do usuário): fluxo do tipo 6 nas procedures e o menu Não Conformidades ›
 *   Nova ocorrência de assistência técnica (NAO_CONFORMIDADES_NOVA_OCORRENCIA_ASSISTENCIA), com as permissões da Nova ocorrência da empresa e
 *   também dos perfis de fornecedor que veem Minhas ocorrências (no legado o fornecedor externo cria a NC de Assistência Técnica).
 * Migração 329 (22/10/2026, decisões do usuário): fornecedor externo escolhe o responsável da ação só entre os usuários do próprio
 *   fornecedor e os da empresa que participam da NC (vw_nc_usuarios_fornecedor); data de resposta do fornecedor gravada quando ele envia a
 *   análise ou atualiza uma ação (sp_nc_resposta_fornecedor; cria data_de_reposta_do_fornecedor onde faltar); permissões: USUARIOS cria
 *   NCs (Nova ocorrência de todos os tipos + Minhas ocorrências) e VISITANTE só consulta (Lista geral com visualizar total + Minhas
 *   ocorrências, sem incluir/editar). As permissões só são acrescentadas (nunca retiradas).
 * Migração 331 (22/10/2026, decisões do usuário): tipo 4 Acidente/Incidente com o fluxo da Empresa — menu Não Conformidades › Nova
 *   ocorrência de acidente/incidente (NAO_CONFORMIDADES_NOVA_OCORRENCIA_ACIDENTE, catálogo universal), com as permissões da Nova ocorrência
 *   da empresa (exceto perfis de fornecedor) e também para USUARIOS. Etapa gravada pelo legado com acentuação quebrada ("Plano de AÃ§Ã£o"):
 *   fn_nc_etapa corrige só na leitura — vw_nc_historico, avisos e as views das listas (vlistadeocorrenciasnc e vminhasocorrenciasnc passam a
 *   mostrar fn_nc_etapa(ETAPA); cópia da definição anterior em storage/logs/migracao_331_<view>_<banco>.sql, gravada uma vez). Nada é
 *   alterado no legado nem nos dados.
 * Migração 332 (22/10/2026, Administração de NC — fase 1): cadastros gerais (Classificação, Classificação de fornecedores, Tipo de
 *   ocorrência, Origem, Sistema de gestão, Setores, Tipos de causa) no grupo Não Conformidades › Cadastros, com a faixa do greenportal;
 *   coluna ATIVO em todos os cadastros (item desativado some das escolhas e continua nas NCs que o usam); nome repetido bloqueado.
 * Migração 333 (22/10/2026): sp_nc_alertar passa a pôr na fila de e-mail o aviso de criação para os participantes externos da
 *   lista de distribuição (sp_nc_email_participantes, gravada pela migração 333 — scripts/aplicar-migracao-333-nc-administracao.php).
 * Pode ser executada mais de uma vez. --simular só confere.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-321-nao-conformidades-base.php [--simular] [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeCadastros.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeMenus.php';

$simular = in_array('--simular', $argv ?? [], true);
try { $alvos = HomologacaoBancos::lista($argv ?? []); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
$env = GreenPortalDatabase::env();
$is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$is->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$valor = static function (PDO $pdo, string $sql, array $p = []): mixed { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchColumn(); $s->closeCursor(); return $r; };
$tabela = static fn(string $db, string $t): bool => (int)$valor($is, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?", [$db, $t]) > 0;
$coluna = static fn(string $db, string $t, string $c): bool => (int)$valor($is, 'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND LOWER(COLUMN_NAME) = LOWER(?)', [$db, $t, $c]) > 0;
$auto = static fn(string $db, string $t, string $c): bool => (int)$valor($is, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND LOWER(COLUMN_NAME) = LOWER(?) AND EXTRA LIKE '%auto_increment%'", [$db, $t, $c]) > 0;

$blocos = [];
foreach (preg_split('/^-- @@\s*$/m', (string)file_get_contents(dirname(__DIR__) . '/database/321_nao_conformidades_base.sql')) as $b) {
    $limpo = trim(preg_replace('/^\s*--.*$/m', '', $b) ?? '');
    if ($limpo !== '') $blocos[] = $limpo;
}
if (count($blocos) < 30) { fwrite(STDERR, "database/321_nao_conformidades_base.sql incompleto.\n"); exit(1); }

const M321_HISTORICO = "CREATE TABLE IF NOT EXISTS `nc_workflow_historico` (
  `CODHISTORICO` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `CODOCORRENCIA` INT NOT NULL, `ACAO` VARCHAR(50) NOT NULL,
  `ETAPA_ANTERIOR` VARCHAR(500) NULL, `ETAPA_NOVA` VARCHAR(500) NULL, `CODUSUARIO` BIGINT UNSIGNED NULL, `MOTIVO` TEXT NULL,
  `CRIADO_EM` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`CODHISTORICO`), KEY `ix_nc_workflow_historico_ocorrencia` (`CODOCORRENCIA`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";
const M321_TABELAS = ['nc_indicacao_ocorrencia', 'nc_causas', 'nc_tipos_causas', 'nc_planodeacao', 'nc_planodeacao_historico', 'nc_tipo_de_naoconformidade',
    'nc_tipo_de_naoconformidadexcampos', 'nc_tipoocorrencia', 'nc_tipoacao', 'nc_origemacao', 'nc_siglagestao', 'nc_listadistribuicao', 'nc_setores',
    'usuarios', 'unidades', 'areas', 'alertas', 'nc_listadistribuicaoxusuarios',
    'fo_fornecedores', 'fo_fornecedorxusuario', 'nc_classificacao_fornecedor', 'nc_categorias', 'nc_listadistribuicaoxcategoria', 'menus', 'permissoes', 'perfis'];

/** Menu Nova ocorrência do fornecedor: copia o da empresa (mesmo pai e colunas) e as permissões, sem os perfis de fornecedor. */
function m325Menu(PDO $pdo, string $codigo = 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_FORNECEDOR', array $rotulo = ['Nova ocorrência do fornecedor', 'New supplier occurrence', 'Nueva ocurrencia del proveedor'], string $url = '/principal/naoconformidades_novaocorrenciafornecedor.php', int $ordem = 1, bool $fornecedorCria = false): array
{
    $colunas = static function (string $t) use ($pdo): array { $s = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'); $s->execute([$t]); return $s->fetchAll(PDO::FETCH_COLUMN); };
    $base = $pdo->query("SELECT codmenu FROM menus WHERE codigo = 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_EMPRESA'")->fetchColumn();
    if (!$base) return ['resultado' => 'sem o menu da empresa: nada a fazer'];
    $buscar = static function () use ($pdo, $codigo) { $s = $pdo->prepare('SELECT codmenu FROM menus WHERE codigo = ?'); $s->execute([$codigo]); return $s->fetchColumn(); };
    // Migração 326: o menu nasce no catálogo universal (de onde vem o menu lateral) e é replicado em todas as empresas
    $catalogo = new MenuCatalogoService();
    $baseUniversal = MigracaoCrud::primeiro($catalogo->universal(), 'menus', ['codigo' => 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_EMPRESA']);
    if ($baseUniversal && !MigracaoCrud::primeiro($catalogo->universal(), 'menus', ['codigo' => $codigo])) {
        $dadosMenu = array_change_key_case($baseUniversal);
        unset($dadosMenu['codmenu']);
        $catalogo->gravar(['codigo' => $codigo, 'label' => $rotulo[0], 'label_pt' => $rotulo[0], 'label_en' => $rotulo[1], 'label_es' => $rotulo[2],
            'url' => $url, 'ordem' => (int)$dadosMenu['ordem'] + $ordem, 'ativo' => 1] + $dadosMenu);
        $universalCriado = true;
    } elseif ($baseUniversal) {
        $catalogo->replicar($codigo, null, false);
    } else {
        return ['resultado' => 'o universal não tem o menu da empresa (NAO_CONFORMIDADES_NOVA_OCORRENCIA_EMPRESA): menu não criado (não há menu só da empresa)'];
    }
    $novo = $buscar();
    if (!$novo) {
        $troca = ['codigo' => $pdo->quote($codigo), 'label' => $pdo->quote($rotulo[0]), 'label_pt' => $pdo->quote($rotulo[0]),
            'label_en' => $pdo->quote($rotulo[1]), 'label_es' => $pdo->quote($rotulo[2]), 'url' => $pdo->quote($url), 'ordem' => 'ordem + ' . $ordem];
        $cols = array_values(array_filter($colunas('menus'), static fn($c) => strtolower($c) !== 'codmenu'));
        $sel = array_map(static fn($c) => $troca[strtolower($c)] ?? '`' . $c . '`', $cols);
        $pdo->exec('INSERT INTO menus (`' . implode('`,`', $cols) . '`) SELECT ' . implode(',', $sel) . ' FROM menus WHERE codmenu = ' . (int)$base);
        $novo = $buscar();
        $criado = true;
    }
    $flags = array_values(array_filter($colunas('permissoes'), static fn($c) => str_starts_with(strtolower($c), 'pode_')));
    $s = $pdo->prepare('INSERT IGNORE INTO permissoes (codperfil, codmenu, `' . implode('`,`', $flags) . '`)
        SELECT p.codperfil, ?, p.`' . implode('`, p.`', $flags) . '` FROM permissoes p JOIN perfis f ON f.codperfil = p.codperfil
         WHERE p.codmenu = ? AND UPPER(f.codigo) NOT IN (\'FORNECEDOR\', \'FORNECEDOR_GESTOR\', \'FORNECEDORES\')');
    $s->execute([(int)$novo, (int)$base]);
    $copiadas = $s->rowCount();
    // Assistência Técnica (migração 328): o fornecedor externo também cria — perfis de fornecedor com acesso a Minhas ocorrências
    if ($fornecedorCria) {
        $f = $pdo->prepare('INSERT IGNORE INTO permissoes (codperfil, codmenu, pode_visualizar, pode_incluir, pode_editar)
            SELECT p.codperfil, ?, 1, 1, 1 FROM permissoes p JOIN perfis f ON f.codperfil = p.codperfil JOIN menus m ON m.codmenu = p.codmenu
             WHERE m.codigo = \'NAO_CONFORMIDADES_MINHAS_OCORRENCIAS\' AND (p.pode_visualizar = 1 OR p.pode_visualizar_total = 1)
               AND UPPER(f.codigo) IN (\'FORNECEDOR\', \'FORNECEDOR_GESTOR\', \'FORNECEDORES\')');
        $f->execute([(int)$novo]);
        $copiadas += $f->rowCount();
    }
    return ['resultado' => !empty($criado) ? 'criado' : (!empty($universalCriado) ? 'criado no universal e replicado' : 'já existia'), 'permissoes_copiadas' => $copiadas,
        'perfis_com_acesso' => (int)$pdo->query('SELECT COUNT(*) FROM permissoes WHERE codmenu = ' . (int)$novo . ' AND pode_visualizar = 1')->fetchColumn()];
}

/** Migração 327: Minhas ocorrências passa a mostrar ao responsável as ações ligadas só pela causa (legado). */
function m327Minhas(PDO $pdo, string $banco, bool $simular): string
{
    $s = $pdo->prepare("SELECT VIEW_DEFINITION, SECURITY_TYPE FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vminhasocorrenciasnc'");
    $s->execute(); $v = $s->fetch(PDO::FETCH_ASSOC); $s->closeCursor();
    if (!$v) return 'view não existe';
    $def = (string)$v['VIEW_DEFINITION'];
    $nova = preg_replace('/\s+and\s+\(`p`\.`CODOCORRENCIA`\s*=\s*`c`\.`CODOCORRENCIA`\)/i', '', $def, -1, $n);
    if (!$n) return 'já estava certa';
    if ($simular) return 'será corrigida';
    $arq = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'migracao_327_vminhas_' . $banco . '.sql';
    if (!is_file($arq)) { @mkdir(dirname($arq), 0775, true); @file_put_contents($arq, "CREATE OR REPLACE SQL SECURITY {$v['SECURITY_TYPE']} VIEW `vminhasocorrenciasnc` AS " . $def . ";\n"); }
    $pdo->exec("CREATE OR REPLACE SQL SECURITY {$v['SECURITY_TYPE']} VIEW `vminhasocorrenciasnc` AS " . $nova);
    return 'corrigida';
}

/** Migração 327: tabelas dos cadastros do Cliente (cria a que faltar; AUTO_INCREMENT na faixa do greenportal). */
function m327Tabelas(PDO $pdo, bool $simular): array
{
    $r = [];
    foreach (NaoConformidadeCadastros::LISTA as $chave => $c) {
        [$t, $pk, $dsc] = [$c['tabela'], $c['pk'], $c['dsc']];
        $s = $pdo->prepare('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $s->execute([$t]); $ai = $s->fetchColumn(); $s->closeCursor();
        if ($ai === false) {
            if (!$simular) $pdo->exec("CREATE TABLE `$t` (`$pk` INT NOT NULL AUTO_INCREMENT, `$dsc` VARCHAR(255) NULL, `ATIVO` TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY (`$pk`)) ENGINE=InnoDB AUTO_INCREMENT=" . NaoConformidadeCadastros::FAIXA . ' DEFAULT CHARSET=utf8mb4');
            $r[$t] = $simular ? 'será criada' : 'criada';
            continue;
        }
        $s = $pdo->prepare("SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND LOWER(COLUMN_NAME) = ?");
        $s->execute([$t, strtolower($pk)]); $extra = (string)$s->fetchColumn(); $s->closeCursor();
        $max = (int)$pdo->query("SELECT COALESCE(MAX(`$pk`), 0) FROM `$t`")->fetchColumn();
        $info = [];
        if (!str_contains(strtolower($extra), 'auto_increment')) { if (!$simular) $pdo->exec("ALTER TABLE `$t` MODIFY `$pk` INT NOT NULL AUTO_INCREMENT"); $info[] = 'chave passou a AUTO_INCREMENT'; }
        if ($max >= NaoConformidadeCadastros::FAIXA) $info[] = "ATENÇÃO: o legado já usa códigos da faixa (maior $max)";
        elseif ((int)$ai < NaoConformidadeCadastros::FAIXA) { if (!$simular) $pdo->exec("ALTER TABLE `$t` AUTO_INCREMENT = " . NaoConformidadeCadastros::FAIXA); $info[] = 'novos a partir de ' . NaoConformidadeCadastros::FAIXA; }
        // Migração 332: coluna ATIVO (só no greenportal; o legado não tem e o sincronismo não a altera)
        $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'ATIVO'");
        $s->execute([$t]); $temAtivo = (int)$s->fetchColumn(); $s->closeCursor();
        if (!$temAtivo) { if (!$simular) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `ATIVO` TINYINT(1) NOT NULL DEFAULT 1"); $info[] = 'coluna Ativo ' . ($simular ? 'será criada' : 'criada'); }
        $r[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn() . ' registro(s)' . ($info ? '; ' . implode('; ', $info) : '');
    }
    return $r;
}

/** Migração 327: view, validação e gatilho de cada cadastro; permissão inicial dos menus para os perfis de administração. */
function m327Cadastros(PDO $pdo): array
{
    $r = [];
    foreach (array_keys(NaoConformidadeCadastros::LISTA) as $chave) foreach (NaoConformidadeCadastros::ddl($chave) as $ddl) $pdo->exec($ddl);
    $flags = array_values(array_filter(array_column($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'permissoes'")->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME'), static fn($c) => str_starts_with(strtolower($c), 'pode_')));
    $menus = array_column(NaoConformidadeCadastros::LISTA, 'menu');
    $s = $pdo->prepare('INSERT IGNORE INTO permissoes (codperfil, codmenu, `' . implode('`, `', $flags) . '`) SELECT p.codperfil, m.codmenu' . str_repeat(', 1', count($flags))
        . " FROM perfis p JOIN menus m ON m.codigo = ? WHERE UPPER(p.codigo) IN ('ADMINISTRADOR', 'ADM_DA_EMPRESA')");
    $n = 0;
    foreach ($menus as $m) { $s->execute([$m]); $n += $s->rowCount(); }
    $r['views_e_gatilhos'] = count(NaoConformidadeCadastros::LISTA) . ' cadastros';
    $r['permissoes_incluidas'] = $n;
    return $r;
}

/** Migração 327: grupo Não Conformidades › Cadastros e os 7 itens no catálogo universal (replicados nas empresas). */
function m327Menus(): array
{
    $cat = new MenuCatalogoService();
    $u = $cat->universal();
    $raiz = MigracaoCrud::primeiro($u, 'menus', ['codigo' => 'NAO_CONFORMIDADES']);
    if (!$raiz) return ['erro' => 'o universal não tem o menu NAO_CONFORMIDADES'];
    $raiz = array_change_key_case($raiz);
    $grupo = MigracaoCrud::primeiro($u, 'menus', ['codigo' => NaoConformidadeCadastros::MENU_GRUPO]);
    if ($grupo) $codGrupo = (int)$grupo['codmenu'];
    else {
        $ordem = 0;
        foreach (MigracaoCrud::selecionar($u, 'menus', ['codmenu_pai' => (int)$raiz['codmenu']]) as $f) $ordem = max($ordem, (int)$f['ordem']);
        $codGrupo = $cat->gravar(['codmenu_pai' => (int)$raiz['codmenu'], 'modulo' => 'NAO_CONFORMIDADES', 'codigo' => NaoConformidadeCadastros::MENU_GRUPO,
            // Migração 333: o grupo se chama Administração (cadastros, listas de distribuição, participantes...)
            'label' => 'Administração', 'label_pt' => 'Administração', 'label_en' => 'Administration', 'label_es' => 'Administración', 'url' => null,
            'nivel' => (int)$raiz['nivel'] + 1, 'ordem' => $ordem + 10, 'icone' => 'bi-sliders', 'ativo' => 1])['codmenu'];
    }
    $r = ['grupo' => $codGrupo];
    $o = 0;
    foreach (NaoConformidadeCadastros::LISTA as $c) {
        $o += 10;
        if ($existente = MigracaoCrud::primeiro($u, 'menus', ['codigo' => $c['menu']])) {
            // Migração 335: lugar e ordem ficam com NaoConformidadeMenus::organizar (subgrupos Cadastros gerais / do cliente)
            $cat->replicar($c['menu'], null, false); $r[$c['menu']] = 'já existia';
            continue;
        }
        $g = $cat->gravar(['codmenu_pai' => $codGrupo, 'modulo' => 'NAO_CONFORMIDADES', 'codigo' => $c['menu'], 'label' => $c['rotulo'][0], 'label_pt' => $c['rotulo'][0],
            'label_en' => $c['rotulo'][1], 'label_es' => $c['rotulo'][2], 'url' => $c['url'], 'nivel' => (int)$raiz['nivel'] + 2, 'ordem' => $o, 'icone' => $c['icone'], 'ativo' => 1]);
        $r[$c['menu']] = 'criado (codmenu ' . $g['codmenu'] . ')';
    }
    // Migração 335: Administração organizada em subgrupos (menus de 2º nível)
    $r['organizacao_335'] = NaoConformidadeMenus::organizar();
    return $r;
}

/** Migração 331: as listas (Lista geral e Minhas ocorrências) mostram a etapa corrigida (fn_nc_etapa), sem alterar o valor gravado. */
function m331Etapa(PDO $pdo, string $banco): array
{
    $r = [];
    // A etapa corrigida usa a mesma collation da coluna original (sem isso o MySQL recusa comparar com o filtro da lista:
    // "Illegal mix of collations", visto na Lista geral em 22/10/2026)
    $s = $pdo->query("SELECT CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nc_indicacao_ocorrencia' AND COLUMN_NAME = 'ETAPA'");
    [$cs, $coll] = array_map('strval', $s->fetch(PDO::FETCH_NUM) ?: ['', '']); $s->closeCursor();
    if (!preg_match('/^[a-z0-9_]+$/i', $cs) || !preg_match('/^[a-z0-9_]+$/i', $coll)) return ['erro' => 'collation da coluna ETAPA não encontrada: views não alteradas'];
    $r['collation_etapa'] = "$cs / $coll";
    foreach (['vlistadeocorrenciasnc', 'vminhasocorrenciasnc'] as $view) {
        $s = $pdo->prepare('SELECT VIEW_DEFINITION, SECURITY_TYPE FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $s->execute([$view]); $v = $s->fetch(PDO::FETCH_ASSOC); $s->closeCursor();
        if (!$v) { $r[$view] = 'view não existe'; continue; }
        $def = (string)$v['VIEW_DEFINITION'];
        // a chamada pode vir como `fn_nc_etapa`(...), `banco`.`fn_nc_etapa`(...) ou fn_nc_etapa(...), com o argumento qualificado ou não
        $fn = '(?:`?[A-Za-z0-9_]+`?\.)?`?fn_nc_etapa`?\s*';
        $arg = '((?:`?[A-Za-z0-9_]+`?\.){0,2}`?ETAPA`?)';
        if (preg_match('/' . $fn . '\(\s*' . $arg . '\s*\)[^,]{0,80}collate/i', $def)) { $r[$view] = 'já mostra a etapa corrigida'; continue; }
        $troca = '(CONVERT(`fn_nc_etapa`($1) USING ' . $cs . ') COLLATE ' . $coll . ')$2';
        $nova = preg_replace('/' . $fn . '\(\s*' . $arg . '\s*\)(\s+AS\s+`?ETAPA`?)/i', $troca, $def, 1, $n);  // 1ª versão (sem collation)
        if (!$n) $nova = preg_replace('/' . $arg . '(\s+AS\s+`?ETAPA`?)/i', $troca, $def, 1, $n);
        $arq = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'migracao_331_' . $view . '_' . $banco . '.sql';
        // Sem achar na definição atual: parte da definição original guardada na 1ª execução (onde a coluna foi achada)
        if (!$n && is_file($arq) && preg_match('/^CREATE OR REPLACE SQL SECURITY \w+ VIEW `[^`]+` AS (.*);\s*$/s', (string)file_get_contents($arq), $orig)) {
            $nova = preg_replace('/' . $arg . '(\s+AS\s+`?ETAPA`?)/i', $troca, $orig[1], 1, $n);
            if ($n) $r[$view . '_origem'] = 'definição original (' . basename($arq) . ')';
        }
        if (!$n) {
            $pos = stripos($def, 'ETAPA');
            $r[$view] = ['resultado' => 'coluna ETAPA não encontrada na definição: não alterada',
                'trecho' => $pos === false ? '(sem ETAPA)' : substr($def, max(0, $pos - 120), 260)];
            continue;
        }
        if (!is_file($arq)) { @mkdir(dirname($arq), 0775, true); @file_put_contents($arq, "CREATE OR REPLACE SQL SECURITY {$v['SECURITY_TYPE']} VIEW `$view` AS " . $def . ";\n"); }
        try {
            $pdo->exec("CREATE OR REPLACE SQL SECURITY {$v['SECURITY_TYPE']} VIEW `$view` AS " . $nova);
            // confere a consulta da lista (filtro de etapa como o grid faz)
            $pdo->query("SELECT COUNT(*) FROM `$view` WHERE ETAPA <> 'Finalizado'")->closeCursor();
            $r[$view] = 'passa a mostrar a etapa corrigida (conferida com filtro de etapa)';
        } catch (Throwable $e) {
            // falhou: volta a view à definição original (antes da migração 331), para a lista não ficar fora do ar
            $original = is_file($arq) ? trim((string)file_get_contents($arq)) : '';
            if ($original !== '') $pdo->exec(rtrim($original, ';'));
            $r[$view] = ['resultado' => 'ERRO ao corrigir: view ' . ($original !== '' ? 'devolvida à definição original' : 'sem cópia da definição original'), 'erro' => mb_substr($e->getMessage(), 0, 300)];
        }
    }
    $r['etapas_corrigidas_na_leitura'] = $pdo->query("SELECT HEX(ETAPA) AS hex, MIN(ETAPA) AS gravada, MIN(fn_nc_etapa(ETAPA)) AS mostrada, COUNT(*) AS n FROM nc_indicacao_ocorrencia WHERE CONVERT(ETAPA USING utf8mb4) COLLATE utf8mb4_bin <> CONVERT(fn_nc_etapa(ETAPA) USING utf8mb4) COLLATE utf8mb4_bin GROUP BY HEX(ETAPA)")->fetchAll(PDO::FETCH_ASSOC);
    return $r;
}

/** Migração 329: NC para os perfis USUARIOS (cria) e VISITANTE (só consulta). Só acrescenta permissão. */
function m329Permissoes(PDO $pdo): array
{
    $novas = ['NAO_CONFORMIDADES_NOVA_OCORRENCIA_EMPRESA', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_FORNECEDOR', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_PLANEJAMENTO',
        'NAO_CONFORMIDADES_NOVA_OCORRENCIA_CLIENTE', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_ASSISTENCIA', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_ACIDENTE'];
    // perfil => [menu => [pode_visualizar, pode_visualizar_total, pode_incluir, pode_editar]]
    $plano = ['USUARIOS' => ['NAO_CONFORMIDADES_MINHAS_OCORRENCIAS' => [1, 0, 0, 0]] + array_fill_keys($novas, [1, 0, 1, 1]),
        'VISITANTE' => ['NAO_CONFORMIDADES_MINHAS_OCORRENCIAS' => [1, 0, 0, 0], 'NAO_CONFORMIDADES_LISTA_OCORRENCIAS' => [1, 1, 0, 0]]];
    $s = $pdo->prepare('INSERT INTO permissoes (codperfil, codmenu, pode_visualizar, pode_visualizar_total, pode_incluir, pode_editar)
        SELECT p.codperfil, m.codmenu, ?, ?, ?, ? FROM perfis p JOIN menus m ON m.codigo = ? WHERE UPPER(p.codigo) = ?
        ON DUPLICATE KEY UPDATE pode_visualizar = GREATEST(pode_visualizar, VALUES(pode_visualizar)), pode_visualizar_total = GREATEST(pode_visualizar_total, VALUES(pode_visualizar_total)),
          pode_incluir = GREATEST(pode_incluir, VALUES(pode_incluir)), pode_editar = GREATEST(pode_editar, VALUES(pode_editar))');
    $r = [];
    foreach ($plano as $perfil => $menus) {
        $n = 0;
        foreach ($menus as $menu => $f) { $s->execute([...$f, $menu, $perfil]); $n += $s->rowCount() > 0 ? 1 : 0; }
        $existe = (int)$pdo->query("SELECT COUNT(*) FROM perfis WHERE UPPER(codigo) = " . $pdo->quote($perfil))->fetchColumn();
        $r[$perfil] = $existe ? "$n menu(s) com permissão acrescentada" : 'perfil não existe neste banco';
    }
    return $r;
}

$rel = ['ok' => true, 'migracao' => '321', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
$catalogo = $tabela('green_universal', 'md_tabelasxcampos');
if (!$catalogo) { $rel['ok'] = false; $rel['erro'] = 'green_universal.md_tabelasxcampos não encontrado (campos por tipo de NC)'; }
if (!$simular && $catalogo) {
    try { $rel['menus_cadastros_327'] = m327Menus(); } catch (Throwable $e) { $rel['ok'] = false; $rel['menus_cadastros_327'] = ['erro' => $e->getMessage()]; }
}
foreach ($alvos as $banco) {
    if (!$tabela($banco, 'nc_indicacao_ocorrencia')) { $rel['bancos'][$banco] = 'sem Não Conformidades (nc_indicacao_ocorrencia não existe): nada a fazer'; continue; }
    $info = [];
    try {
        $faltam = array_values(array_filter(M321_TABELAS, static fn($t) => !$tabela($banco, $t)));
        if ($faltam) throw new RuntimeException('faltam as tabelas ' . implode(', ', $faltam));
        foreach ([['nc_indicacao_ocorrencia', 'CODOCORRENCIA'], ['nc_causas', 'CODCAUSA'], ['nc_planodeacao', 'CODPLANOACAO']] as [$t, $c])
            if (!$auto($banco, $t, $c)) throw new RuntimeException("$t.$c sem AUTO_INCREMENT (as procedures incluem registros novos)");
        foreach ([['areas', 'DSCAREA'], ['unidades', 'DSCUNIDADE'], ['nc_setores', 'dscsetor']] as [$t, $c])
            if (!$coluna($banco, $t, $c)) throw new RuntimeException("$t sem a coluna $c");
        if (!(int)$valor($is, "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? AND ROUTINE_NAME = 'getStatusPlanoAcaoNC'", [$banco]))
            throw new RuntimeException('função getStatusPlanoAcaoNC não existe (regra do legado para enviar à eficácia)');
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $info['ocorrencias'] = (int)$valor($pdo, 'SELECT COUNT(*) FROM nc_indicacao_ocorrencia');
        $info['em_aberto'] = (int)$valor($pdo, "SELECT COUNT(*) FROM nc_indicacao_ocorrencia WHERE ETAPA <> 'Finalizado'");
        $info['gatilho_duplicava_historico'] = (int)$valor($is, "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND TRIGGER_NAME = 'tr_nc_indicacao_ocorrencia_workflow_audit'", [$banco]) ? ($simular ? 'seria retirado' : 'retirado') : 'não existia';
        if (!$tabela($banco, 'nc_workflow_historico')) { $info['nc_workflow_historico'] = $simular ? 'seria criada' : 'criada'; if (!$simular) $pdo->exec(M321_HISTORICO); }
        $info['cadastros_cliente'] = m327Tabelas($pdo, $simular);
        if (!$coluna($banco, 'nc_indicacao_ocorrencia', 'data_de_reposta_do_fornecedor')) {
            if (!$simular) $pdo->exec('ALTER TABLE nc_indicacao_ocorrencia ADD COLUMN data_de_reposta_do_fornecedor DATETIME NULL');
            $info['data_de_reposta_do_fornecedor'] = $simular ? 'coluna será criada' : 'coluna criada';
        }
        if (!$simular) {
            foreach ($blocos as $b) $pdo->exec($b);
            $info['cadastros_cliente_objetos'] = m327Cadastros($pdo);
            $info['estrutura'] = 'views e procedures gravadas';
            $info['vw_nc_ocorrencia'] = (int)$valor($pdo, 'SELECT COUNT(*) FROM vw_nc_ocorrencia');
            $info['campos_por_tipo'] = (int)$valor($pdo, 'SELECT COUNT(*) FROM vw_nc_tipo_campos');
            $info['opcoes'] = (int)$valor($pdo, 'SELECT COUNT(*) FROM vw_nc_opcoes');
            $info['faixa_greenportal'] = ['ocorrencias_a_partir_de' => (int)$valor($pdo, "SELECT fn_nc_faixa('ocorrencia')"), 'ja_na_faixa' => (int)$valor($pdo, "SELECT COUNT(*) FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA >= fn_nc_faixa('ocorrencia')"),
                'maior_codigo_fora_da_faixa' => (int)$valor($pdo, "SELECT COALESCE(MAX(CODOCORRENCIA), 0) FROM nc_indicacao_ocorrencia WHERE CODOCORRENCIA < fn_nc_faixa('ocorrencia')")];
            $info['menu_nova_ocorrencia_fornecedor'] = m325Menu($pdo);
            $info['menu_nova_ocorrencia_planejamento'] = m325Menu($pdo, 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_PLANEJAMENTO', ['Nova ocorrência de planejamento', 'New planning occurrence', 'Nueva ocurrencia de planificación'], '/principal/naoconformidades_novaocorrenciaplanejamento.php', 2);
            $info['menu_nova_ocorrencia_cliente'] = m325Menu($pdo, 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_CLIENTE', ['Nova ocorrência de cliente', 'New customer occurrence', 'Nueva ocurrencia de cliente'], '/principal/naoconformidades_novaocorrenciacliente.php', 3);
            $info['menu_nova_ocorrencia_assistencia'] = m325Menu($pdo, 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_ASSISTENCIA', ['Nova ocorrência de assistência técnica', 'New technical assistance occurrence', 'Nueva ocurrencia de asistencia técnica'], '/principal/naoconformidades_novaocorrenciaassistencia.php', 4, true);
            $info['menu_nova_ocorrencia_acidente_331'] = m325Menu($pdo, 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_ACIDENTE', ['Nova ocorrência de acidente/incidente', 'New accident/incident occurrence', 'Nueva ocurrencia de accidente/incidente'], '/principal/naoconformidades_novaocorrenciaacidente.php', 5);
            $info['tipo_4_acidente_cadastrado'] = (int)$valor($pdo, 'SELECT COUNT(*) FROM nc_tipo_de_naoconformidade WHERE CODTIPONAOCONFORMIDADE = 4') ? 'sim' : 'NÃO (o menu abre, mas o tipo 4 não existe neste banco)';
            $info['permissoes_usuarios_visitante_329'] = m329Permissoes($pdo);
            $info['etapa_corrigida_na_leitura_331'] = m331Etapa($pdo, $banco);
            $info['acoes_ligadas_so_pela_causa'] = (int)$valor($pdo, 'SELECT COUNT(*) FROM nc_planodeacao p JOIN nc_causas c ON c.CODCAUSA = p.CODCAUSA WHERE p.CODOCORRENCIA IS NULL');
            $info['acoes_visiveis_na_tela'] = (int)$valor($pdo, 'SELECT COUNT(*) FROM vw_nc_planodeacao WHERE CODOCORRENCIA IS NOT NULL');
            $info['minhas_ocorrencias_view'] = m327Minhas($pdo, $banco, false);
            $info['alertas_de_nc_ja_criados'] = (int)$valor($pdo, "SELECT COUNT(*) FROM alertas WHERE link LIKE '/principal/naoconformidades_ocorrencia.php?%'");
        }
        $rel['bancos'][$banco] = $info;
    } catch (Throwable $e) { $rel['ok'] = false; $rel['bancos'][$banco] = $info + ['erro' => $e->getMessage()]; }
}
$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_321_nao_conformidades_base' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
