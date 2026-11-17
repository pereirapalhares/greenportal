<?php
declare(strict_types=1);

/*
 * Migração 339 — Não Conformidades › Administração › Configuração do módulo (fase 4, 23/10/2026).
 *  1. Catálogo universal: subgrupo "Configuração" (NAO_CONFORMIDADES_ADM_CONFIGURACAO) em Não Conformidades › Administração e o menu
 *     "Configuração do módulo" (NAO_CONFORMIDADES_CONFIGURACAO, principal/naoconformidades_configuracao.php); serviço "Lembretes de NC"
 *     (NC_LEMBRETES) em Administração › Serviços.
 *  2. Em cada banco com NC: tabelas, views e procedures de database/339_nc_configuracao.sql; parâmetros com o valor de hoje
 *     (Crown: eficácia exige data/responsável e listas de fornecedor separadas, como antes); as 7 regras de lembrete DESLIGADAS;
 *     permissão do menu só para o perfil ADMINISTRADOR.
 * Depois: instalar a tarefa dos lembretes (PowerShell como Administrador):
 *   powershell -ExecutionPolicy Bypass -File scripts\criar-agendamento-nc-lembretes.ps1
 * Não altera nada no legado (os campos por tipo continuam vindo de lá). Pode ser executada mais de uma vez. --simular só confere.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-339-nc-configuracao.php [--simular] [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeCadastros.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeAdministracao.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeMenus.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeConfig.php';

$simular = in_array('--simular', $argv ?? [], true);
try { $pares = HomologacaoBancos::pares($argv ?? []); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
$env = GreenPortalDatabase::env();
$is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$is->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$is->exec('SET NAMES utf8mb4');
$valor = static function (PDO $pdo, string $sql, array $p = []): mixed { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchColumn(); $s->closeCursor(); return $r; };
$tabela = static fn(string $db, string $t): bool => (int)$valor($is, 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db, $t]) > 0;

$blocos = [];
foreach (preg_split('/^-- @@\s*$/m', (string)@file_get_contents(dirname(__DIR__) . '/database/339_nc_configuracao.sql')) as $b) {
    $limpo = trim(preg_replace('/^\s*--.*$/m', '', $b) ?? '');
    if ($limpo !== '') $blocos[] = $limpo;
}
if (count($blocos) < 20) { fwrite(STDERR, "database/339_nc_configuracao.sql incompleto.\n"); exit(1); }

/** Universal: subgrupo, menu e serviço. */
function m339Universal(bool $simular): array
{
    $cat = new MenuCatalogoService();
    $u = $cat->universal();
    $r = [];
    $grupo = MigracaoCrud::primeiro($u, 'menus', ['codigo' => NaoConformidadeCadastros::MENU_GRUPO]);
    if (!$grupo) return ['erro' => 'o universal não tem o grupo ' . NaoConformidadeCadastros::MENU_GRUPO . ' (rodar antes as migrações 321 e 333)'];
    $grupo = array_change_key_case($grupo);
    // Subgrupo "Configuração" (e os demais subgrupos, se faltarem)
    $r['subgrupos'] = NaoConformidadeMenus::organizar($simular)['subgrupos'] ?? [];
    $sub = MigracaoCrud::primeiro($u, 'menus', ['codigo' => NaoConformidadeConfig::SUB_CONFIGURACAO]);
    $codigo = NaoConformidadeConfig::MENU;
    if ($existente = MigracaoCrud::primeiro($u, 'menus', ['codigo' => $codigo])) {
        if (!$simular) $cat->replicar($codigo, null, false);
        $r['menu'] = 'já existia (codmenu ' . $existente['codmenu'] . ')';
    } elseif ($simular) $r['menu'] = 'será criado: Configuração do módulo';
    elseif (!$sub) $r['menu'] = 'AVISO: subgrupo Configuração não encontrado';
    else {
        $sub = array_change_key_case($sub);
        $g = $cat->gravar(['codmenu_pai' => (int)$sub['codmenu'], 'modulo' => 'NAO_CONFORMIDADES', 'codigo' => $codigo, 'label' => 'Configuração do módulo', 'label_pt' => 'Configuração do módulo',
            'label_en' => 'Module settings', 'label_es' => 'Configuración del módulo', 'url' => '/principal/naoconformidades_configuracao.php', 'nivel' => (int)$sub['nivel'] + 1, 'ordem' => 1,
            'icone' => 'bi-sliders2', 'ativo' => 1]);
        $r['menu'] = 'criado (codmenu ' . $g['codmenu'] . ')';
    }
    // Serviço em Administração › Serviços (o monitor lê o estado da tarefa GreenPortal-NcLembretes)
    try {
        if ((int)$u->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'servicos'")->fetchColumn() > 0) {
            $cols = array_map('strtolower', $u->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'servicos'")->fetchAll(PDO::FETCH_COLUMN));
            $dados = array_intersect_key(['nome_pt' => 'Lembretes de NC', 'nome_en' => 'NC reminders', 'nome_es' => 'Recordatorios de NC',
                'descricao_pt' => 'Aplica as regras de lembrete das Não Conformidades (Administração › Configuração do módulo): alertas e e-mails.',
                'descricao_en' => 'Applies the nonconformity reminder rules (module settings): alerts and e-mails.',
                'descricao_es' => 'Aplica las reglas de recordatorio de las No Conformidades: alertas y correos.',
                'host' => 'TASK_SCHEDULER', 'script' => 'scripts/nc-lembretes-worker.php', 'ativo' => 1], array_flip($cols));
            $existe = (int)$u->query("SELECT COUNT(*) FROM servicos WHERE codigo = 'NC_LEMBRETES'")->fetchColumn();
            if ($simular) $r['servico'] = $existe ? 'será atualizado' : 'será incluído';
            elseif ($existe) { $u->prepare('UPDATE servicos SET ' . implode(', ', array_map(static fn($c) => "`$c` = ?", array_keys($dados))) . " WHERE codigo = 'NC_LEMBRETES'")->execute(array_values($dados)); $r['servico'] = 'atualizado'; }
            else {
                $todos = ['codigo' => 'NC_LEMBRETES'] + $dados + array_intersect_key(['porta' => 0, 'ordem' => 16], array_flip($cols));
                $u->prepare('INSERT INTO servicos (`' . implode('`, `', array_keys($todos)) . '`) VALUES (' . implode(', ', array_fill(0, count($todos), '?')) . ')')->execute(array_values($todos));
                $r['servico'] = 'incluído';
            }
        }
    } catch (Throwable $e) { $r['servico'] = 'aviso: ' . $e->getMessage(); }
    return $r;
}

$rel = ['ok' => true, 'migracao' => '339', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR'];
try { $rel['universal'] = m339Universal($simular); if (isset($rel['universal']['erro'])) $rel['ok'] = false; }
catch (Throwable $e) { $rel['ok'] = false; $rel['universal'] = ['erro' => $e->getMessage()]; }

foreach ($pares as [$legado, $banco]) {
    if (!$tabela($banco, 'nc_indicacao_ocorrencia') || !$tabela($banco, 'nc_listadistribuicao')) { $rel['bancos'][$banco] = 'sem Não Conformidades: nada a fazer'; continue; }
    $info = [];
    try {
        if (!$tabela($banco, 'nc_admin_historico') || !$tabela($banco, 'email_fila')) throw new RuntimeException('rodar antes a migração 333');
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        if ($simular) $info['estrutura'] = 'tabelas, views e procedures seriam gravadas';
        else {
            // Colunas de usuarios que nem todo banco tem: a view usa só as que existem
            $colsU = array_map('strtolower', $is->query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ' . $is->quote($banco) . " AND TABLE_NAME = 'usuarios'")->fetchAll(PDO::FETCH_COLUMN));
            $trocas = [];
            if (!in_array('userid', $colsU, true)) { $trocas['u.userid'] = 'NULL'; $info['usuarios_sem_coluna'][] = 'userid'; }
            if (!in_array('codusuario_superior', $colsU, true)) { $trocas['COALESCE(u.codusuario_superior, 0)'] = '0'; $info['usuarios_sem_coluna'][] = 'codusuario_superior (sem aviso ao superior)'; }
            foreach ($blocos as $b) $pdo->exec($trocas ? strtr($b, $trocas) : $b);
            $info['estrutura'] = count($blocos) . ' blocos gravados (tabelas, views e procedures)';
            // Parâmetros: o comportamento de hoje (só grava os que faltam)
            $s = $pdo->prepare('INSERT IGNORE INTO nc_config_parametros (CHAVE, VALOR) VALUES (?, ?)');
            $novos = 0;
            foreach (array_keys(NaoConformidadeConfig::PARAMETROS) as $chave) { $s->execute([$chave, NaoConformidadeConfig::padrao($chave, $banco)]); $novos += $s->rowCount(); }
            $info['parametros'] = $novos . ' incluído(s); ' . implode(', ', array_map(static fn($p) => $p['CHAVE'] . '=' . ($p['VALOR'] === '' ? '(vazio)' : $p['VALOR']), $pdo->query('SELECT CHAVE, VALOR FROM nc_config_parametros ORDER BY CHAVE')->fetchAll(PDO::FETCH_ASSOC)));
            // Regras de lembrete: as 7 situações, desligadas (só quando ainda não há nenhuma)
            if ((int)$valor($pdo, 'SELECT COUNT(*) FROM nc_lembrete_regras') === 0) {
                $s = $pdo->prepare('INSERT INTO nc_lembrete_regras (EVENTO, NOME, ATIVO, DIAS, REPETIR_DIAS, ESCALAR_APOS, ENVIAR_EMAIL) VALUES (?, ?, 0, ?, ?, ?, 1)');
                foreach (NaoConformidadeConfig::EVENTOS as $ev => [$nome, , , $dias, $rep, $esc]) $s->execute([$ev, $nome, $dias, $rep, $esc]);
                $info['regras'] = count(NaoConformidadeConfig::EVENTOS) . ' regras criadas, desligadas';
            } else $info['regras'] = (int)$valor($pdo, 'SELECT COUNT(*) FROM nc_lembrete_regras') . ' regra(s) já existiam (' . (int)$valor($pdo, 'SELECT COUNT(*) FROM nc_lembrete_regras WHERE ATIVO = 1') . ' ligada(s))';
            // Permissão do menu: só ADMINISTRADOR
            $flags = array_values(array_filter($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'permissoes'")->fetchAll(PDO::FETCH_COLUMN), static fn($c) => str_starts_with(strtolower((string)$c), 'pode_')));
            if ((int)$valor($pdo, 'SELECT COUNT(*) FROM menus WHERE codigo = ?', [NaoConformidadeConfig::MENU]) === 0) $info['permissao'] = 'menu ainda não replicado neste banco';
            else {
                $s = $pdo->prepare('INSERT IGNORE INTO permissoes (codperfil, codmenu, `' . implode('`, `', $flags) . '`) SELECT p.codperfil, m.codmenu' . str_repeat(', 1', count($flags))
                    . " FROM perfis p JOIN menus m ON m.codigo = ? WHERE UPPER(p.codigo) = 'ADMINISTRADOR'");
                $s->execute([NaoConformidadeConfig::MENU]);
                $info['permissao'] = $s->rowCount() . ' permissão(ões) incluída(s) (perfil ADMINISTRADOR)';
            }
            // Conferência (só leitura): quantos lembretes as regras enviariam se fossem ligadas hoje
            $info['situacoes_hoje'] = [];
            foreach ($pdo->query("SELECT EVENTO, ORIGEM, COUNT(*) AS QTD FROM vw_nc_lembrete_candidatos GROUP BY EVENTO, ORIGEM ORDER BY EVENTO, ORIGEM")->fetchAll(PDO::FETCH_ASSOC) as $c)
                $info['situacoes_hoje'][$c['EVENTO']][$c['ORIGEM'] === 'GREENPORTAL' ? 'greenportal' : 'sistema_anterior'] = (int)$c['QTD'];
        }
        $rel['bancos'][$banco] = $info;
    } catch (Throwable $e) { $rel['ok'] = false; $rel['bancos'][$banco] = $info + ['erro' => $e->getMessage()]; }
}
$rel['proximo_passo'] = 'instalar a tarefa: powershell -ExecutionPolicy Bypass -File scripts\\criar-agendamento-nc-lembretes.ps1 (como Administrador); sair e entrar de novo no portal para ver o menu';
$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_339_nc_configuracao' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
