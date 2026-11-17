<?php
declare(strict_types=1);

/*
 * Migração 333 — Não Conformidades › Administração, fase 1b (22/10/2026, decisões do usuário).
 *  1. Catálogo universal: o grupo Não Conformidades › Cadastros passa a se chamar "Administração" (mesmo código
 *     NAO_CONFORMIDADES_CADASTROS, as permissões continuam) e ganha os menus Listas de distribuição e Participantes externos;
 *     serviço "E-mails" (EMAIL_WORKER) em Administração › Serviços.
 *  2. Em cada banco com NC: tabelas nc_participantes, nc_admin_historico e email_fila; views e procedures de
 *     database/333_nc_administracao.sql; permissão dos menus novos para ADMINISTRADOR e ADM_DA_EMPRESA (e de Administração ›
 *     E-mails enviados, onde ainda não houver); carga dos participantes externos do legado (<legado>_seguranca.usuarios com
 *     ativado = 'X'), que depois vêm a cada sincronismo.
 * Migração 334 (mesma execução, 22/10/2026 — fases 2 e 3): menus Correção de ocorrência, Transferência de responsabilidades e
 *   Painel de saneamento; views e procedures de database/334_nc_administracao_correcoes.sql (só NCs do greenportal são alteradas).
 * Depois desta, rodar a migração 321 (grava sp_nc_alertar chamando o e-mail aos participantes) e instalar o serviço de e-mail:
 *   powershell -ExecutionPolicy Bypass -File scripts\criar-agendamento-email.ps1 (como Administrador), com SMTP_* no .env.
 * Não altera nada no legado. Pode ser executada mais de uma vez. --simular só confere.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-333-nc-administracao.php [--simular] [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeCadastros.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeAdministracao.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeParticipantes.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeMenus.php';
require_once dirname(__DIR__) . '/classes/SmtpCliente.php';

$simular = in_array('--simular', $argv ?? [], true);
try { $pares = HomologacaoBancos::pares($argv ?? []); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
$env = GreenPortalDatabase::env();
$is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$is->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$is->exec('SET NAMES utf8mb4');
$valor = static function (PDO $pdo, string $sql, array $p = []): mixed { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchColumn(); $s->closeCursor(); return $r; };
$tabela = static fn(string $db, string $t): bool => (int)$valor($is, 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db, $t]) > 0;

$blocos = [];
// 333: listas, participantes e fila de e-mail; 334: correção, transferência e saneamento
foreach (['333_nc_administracao.sql' => 25, '334_nc_administracao_correcoes.sql' => 15] as $arquivo => $minimo) {
    $n = 0;
    foreach (preg_split('/^-- @@\s*$/m', (string)@file_get_contents(dirname(__DIR__) . '/database/' . $arquivo)) as $b) {
        $limpo = trim(preg_replace('/^\s*--.*$/m', '', $b) ?? '');
        if ($limpo !== '') { $blocos[] = $limpo; $n++; }
    }
    if ($n < $minimo) { fwrite(STDERR, "database/$arquivo incompleto.\n"); exit(1); }
}

/** Universal: grupo renomeado, menus novos e serviço de e-mail. */
function m333Universal(bool $simular): array
{
    $cat = new MenuCatalogoService();
    $u = $cat->universal();
    $r = [];
    $raiz = MigracaoCrud::primeiro($u, 'menus', ['codigo' => 'NAO_CONFORMIDADES']);
    $grupo = MigracaoCrud::primeiro($u, 'menus', ['codigo' => NaoConformidadeCadastros::MENU_GRUPO]);
    if (!$raiz || !$grupo) return ['erro' => 'o universal não tem NAO_CONFORMIDADES / ' . NaoConformidadeCadastros::MENU_GRUPO . ' (rodar antes a migração 321)'];
    $raiz = array_change_key_case($raiz); $grupo = array_change_key_case($grupo);
    if ((string)$grupo['label_pt'] !== 'Administração' || (string)$grupo['label'] !== 'Administração') {
        if (!$simular) $cat->gravar(['codigo' => NaoConformidadeCadastros::MENU_GRUPO, 'label' => 'Administração', 'label_pt' => 'Administração', 'label_en' => 'Administration', 'label_es' => 'Administración', 'icone' => 'bi-sliders'], (int)$grupo['codmenu']);
        $r['grupo'] = ($simular ? 'será renomeado' : 'renomeado') . ': "' . $grupo['label_pt'] . '" → "Administração"';
    } else $r['grupo'] = 'já se chama Administração';
    foreach (NaoConformidadeAdministracao::MENUS as $codigo => [$url, $rotulo, $icone, $ordem, $subgrupo]) {
        if ($existente = MigracaoCrud::primeiro($u, 'menus', ['codigo' => $codigo])) {
            if (!$simular) $cat->replicar($codigo, null, false);
            $r[$codigo] = 'já existia (codmenu ' . $existente['codmenu'] . ')';
            continue;
        }
        if ($simular) { $r[$codigo] = 'será criado'; continue; }
        // Migração 335: dentro do subgrupo (Ocorrências / Listas e participantes) quando ele já existe
        $pai = NaoConformidadeMenus::paiNoUniversal($u, $subgrupo) ?? (int)$grupo['codmenu'];
        $g = $cat->gravar(['codmenu_pai' => $pai, 'modulo' => 'NAO_CONFORMIDADES', 'codigo' => $codigo, 'label' => $rotulo[0], 'label_pt' => $rotulo[0],
            'label_en' => $rotulo[1], 'label_es' => $rotulo[2], 'url' => $url, 'nivel' => (int)$raiz['nivel'] + ($pai === (int)$grupo['codmenu'] ? 2 : 3), 'ordem' => $ordem, 'icone' => $icone, 'ativo' => 1]);
        $r[$codigo] = 'criado (codmenu ' . $g['codmenu'] . ')';
    }
    // Migração 335: Administração organizada em subgrupos (menus de 2º nível)
    $r['organizacao_335'] = NaoConformidadeMenus::organizar($simular);
    // Serviço na tela Administração › Serviços (o monitor lê o estado da tarefa GreenPortal-EmailWorker)
    try {
        if ((int)$u->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'servicos'")->fetchColumn() > 0) {
            $cols = array_map('strtolower', $u->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'servicos'")->fetchAll(PDO::FETCH_COLUMN));
            $dados = array_intersect_key(['nome_pt' => 'E-mails', 'nome_en' => 'E-mails', 'nome_es' => 'Correos',
                'descricao_pt' => 'Envia a fila de e-mails das empresas (avisos de NC aos participantes externos) pelo SMTP configurado no .env.',
                'descricao_en' => 'Sends the companies e-mail queue through the SMTP server configured in .env.',
                'descricao_es' => 'Envía la cola de correos de las empresas por el SMTP configurado en .env.',
                'host' => 'TASK_SCHEDULER', 'script' => 'scripts/email-worker.php', 'ativo' => 1], array_flip($cols));
            $existe = (int)$u->query("SELECT COUNT(*) FROM servicos WHERE codigo = 'EMAIL_WORKER'")->fetchColumn();
            if ($simular) $r['servico'] = $existe ? 'será atualizado' : 'será incluído';
            elseif ($existe) { $u->prepare('UPDATE servicos SET ' . implode(', ', array_map(static fn($c) => "`$c` = ?", array_keys($dados))) . " WHERE codigo = 'EMAIL_WORKER'")->execute(array_values($dados)); $r['servico'] = 'atualizado'; }
            else {
                $todos = ['codigo' => 'EMAIL_WORKER'] + $dados + array_intersect_key(['porta' => 0, 'ordem' => 15], array_flip($cols));
                $u->prepare('INSERT INTO servicos (`' . implode('`, `', array_keys($todos)) . '`) VALUES (' . implode(', ', array_fill(0, count($todos), '?')) . ')')->execute(array_values($todos));
                $r['servico'] = 'incluído';
            }
        }
    } catch (Throwable $e) { $r['servico'] = 'aviso: ' . $e->getMessage(); }
    return $r;
}

/** Permissão inicial (só acrescenta): menus novos para ADMINISTRADOR e ADM_DA_EMPRESA; E-mails enviados idem, onde não houver. */
function m333Permissoes(PDO $pdo): array
{
    $flags = array_values(array_filter($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'permissoes'")->fetchAll(PDO::FETCH_COLUMN), static fn($c) => str_starts_with(strtolower((string)$c), 'pode_')));
    $s = $pdo->prepare('INSERT IGNORE INTO permissoes (codperfil, codmenu, `' . implode('`, `', $flags) . '`) SELECT p.codperfil, m.codmenu' . str_repeat(', 1', count($flags))
        . " FROM perfis p JOIN menus m ON m.codigo = ? WHERE UPPER(p.codigo) IN ('ADMINISTRADOR', 'ADM_DA_EMPRESA')");
    $r = [];
    foreach ([...array_keys(NaoConformidadeAdministracao::MENUS), NaoConformidadeAdministracao::MENU_EMAILS] as $menu) {
        $existe = (int)$pdo->query('SELECT COUNT(*) FROM menus WHERE codigo = ' . $pdo->quote($menu))->fetchColumn();
        if (!$existe) { $r[$menu] = 'menu não existe neste banco'; continue; }
        $s->execute([$menu]);
        $r[$menu] = $s->rowCount() . ' permissão(ões) incluída(s)';
    }
    return $r;
}

$rel = ['ok' => true, 'migracao' => '333', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR'];
try { $rel['universal'] = m333Universal($simular); if (isset($rel['universal']['erro'])) $rel['ok'] = false; }
catch (Throwable $e) { $rel['ok'] = false; $rel['universal'] = ['erro' => $e->getMessage()]; }

foreach ($pares as [$legado, $banco]) {
    if (!$tabela($banco, 'nc_indicacao_ocorrencia') || !$tabela($banco, 'nc_listadistribuicao')) { $rel['bancos'][$banco] = 'sem Não Conformidades: nada a fazer'; continue; }
    $info = [];
    try {
        if (!(int)$valor($is, "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? AND ROUTINE_NAME = 'fn_nc_etapa'", [$banco]))
            throw new RuntimeException('falta fn_nc_etapa: rodar antes a migração 321');
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $info['listas'] = ['total' => (int)$valor($pdo, 'SELECT COUNT(*) FROM nc_listadistribuicao'), 'do_greenportal' => (int)$valor($pdo, 'SELECT COUNT(*) FROM nc_listadistribuicao WHERE CODLISTA >= 50001'),
            'maior_codigo_legado' => (int)$valor($pdo, 'SELECT COALESCE(MAX(CODLISTA), 0) FROM nc_listadistribuicao WHERE CODLISTA < 50001')];
        if ($simular) { $info['estrutura'] = 'tabelas, views e procedures seriam gravadas'; }
        else {
            // Colunas de usuarios que nem todo banco tem (ex.: gmodelo2_dbp sem emails): a view usa só as que existem
            $colsU = array_map('strtolower', $is->query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ' . $is->quote($banco) . " AND TABLE_NAME = 'usuarios'")->fetchAll(PDO::FETCH_COLUMN));
            $trocas = [];
            foreach (['email', 'emails', 'userid'] as $c) if (!in_array($c, $colsU, true)) { $trocas["NULLIF(TRIM(u.$c), '')"] = 'NULL'; $trocas["u.$c,"] = 'NULL,'; $info['usuarios_sem_coluna'][] = $c; }
            foreach ($blocos as $b) $pdo->exec($trocas ? strtr($b, $trocas) : $b);
            $info['estrutura'] = count($blocos) . ' blocos gravados (tabelas, views e procedures)';
            $info['permissoes'] = m333Permissoes($pdo);
        }
        // Participantes externos do legado
        $origem = $tabela($legado . '_seguranca', 'usuarios') ? $legado : NaoConformidadeParticipantes::origemDoBanco($is, $banco);
        if (!$origem) $info['participantes'] = 'legado não identificado: carga feita pelo próximo sincronismo';
        elseif ($simular && !$tabela($banco, 'nc_participantes')) $info['participantes'] = ['a_incluir' => (int)$valor($is, 'SELECT COUNT(*) FROM `' . $origem . "_seguranca`.usuarios WHERE ativado = 'X'"), 'origem' => $origem . '_seguranca'];
        else $info['participantes'] = NaoConformidadeParticipantes::sincronizar($is, $origem, $banco, !$simular);
        if (!$simular) {
            $info['situacao'] = [
                'participantes' => (int)$valor($pdo, 'SELECT COUNT(*) FROM nc_participantes'),
                'participantes_em_listas' => (int)$valor($pdo, 'SELECT COUNT(DISTINCT p.CODUSUARIO) FROM nc_participantes p JOIN nc_listadistribuicaoxusuarios x ON x.CODUSUARIO = p.CODUSUARIO'),
                'vinculos_de_lista' => (int)$valor($pdo, 'SELECT COUNT(*) FROM nc_listadistribuicaoxusuarios'),
                'vinculos_sem_pessoa' => (int)$valor($pdo, "SELECT COUNT(*) FROM vw_nc_lista_membros WHERE TIPO = 'NAO_CADASTRADO'"),
                'listas_com_inativos' => (int)$valor($pdo, 'SELECT COUNT(*) FROM vw_nc_listas WHERE QTD_INATIVOS > 0'),
                'emails_na_fila' => (int)$valor($pdo, "SELECT COUNT(*) FROM email_fila WHERE status IN ('PENDENTE', 'PROCESSANDO')"),
            ];
            $def = (string)$valor($pdo, "SELECT ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_nc_alertar'");
            $info['aviso_por_email_na_criacao'] = str_contains($def, 'sp_nc_email_participantes') ? 'ligado' : 'AINDA NÃO: rodar a migração 321 (scripts/aplicar-migracao-321-nao-conformidades-base.php)';
        }
        $rel['bancos'][$banco] = $info;
    } catch (Throwable $e) { $rel['ok'] = false; $rel['bancos'][$banco] = $info + ['erro' => $e->getMessage()]; }
}
$rel['smtp_configurado'] = SmtpCliente::configurado($env) ? 'sim' : 'não (SMTP_HOST e SMTP_REMETENTE no .env; os e-mails ficam na fila até lá)';
$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_333_nc_administracao' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
