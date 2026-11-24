<?php
declare(strict_types=1);

/*
 * Migração 361 — Responsáveis por regra e avisos por módulo (item 4 do 01_Mudancas_Gerais, GER-3, depois do SMTP da 360;
 * LIC-50/51, MM-72/78, CON-91/94, TRE-106/109, INF-121/124).
 * Decisões do usuário (12/11/2026): regra dinâmica (vale na hora para registros novos e antigos; nada é copiado para o registro);
 * os responsáveis da regra SOMAM com o Responsável do registro e com as atribuições; Licenças por Tipo documental + unidade,
 * Medição por Tipo de medição + unidade, Controles / Treinamentos / Infraestrutura por unidade; avisos escolhidos em cada regra.
 *
 * Universal: menu "Responsáveis e avisos (<módulo>)" ao lado da tela de cada módulo (RESP_REGRAS_<ÁREA>), replicado nas empresas.
 * Cada empresa:
 *   1. database/361_responsaveis_regras.sql: tabelas resp_regras, resp_regras_avisos, resp_regras_controle e sp_resp_regras_avisar;
 *   2. views das regras (classes/ResponsaveisRegras.php, conforme as colunas do banco): itens, usuários, registros, cobertura;
 *   3. vw_responsabilidade_efetiva passa a incluir as regras (ORIGEM 'REGRA'); a anterior fica em vw_responsabilidade_efetiva_base
 *      e em storage/logs; desfaz sozinha se a consulta ficar muito mais lenta;
 *   4. vw_resp_acesso_<área> / vw_resp_acesso e vw_resp_minhas_<área> (telas Minhas: Responsável do registro + atribuições + regras)
 *      e vw_resp_regras_avisos_pendentes (avisos do serviço de Responsabilidades);
 *   5. responsáveis automáticos (345): registro coberto por regra conta como "com responsável" (vw_resp_auto_itens refeita);
 *   6. permissões dos menus novos = as da tela do módulo, em cada perfil.
 * Ajuda da tela (transação responsaveis_regras) no universal: database/361_ajuda_responsaveis_regras.php.
 * 16/11/2026: avisos de prazo também para o Responsável do registro e as atribuições (decisão do usuário); lista de pessoas
 * da tela sem as contas de fornecedor (vw_resp_regras_pessoas). Rodar de novo para aplicar.
 * 24/11/2026 (migração 406, onda 2 da Revisão G): área HOMOLOGACAO (fornecedores por Tipo de fornecedor + unidade) e restrição
 * da tabela pronta para LEGAL; rodar de novo (ou scripts\aplicar-migracao-406-responsaveis-homologacao.php).
 * 24/11/2026 (migração 407): área LEGAL (requisitos por Categoria + unidade; database/407_legislacao_categorias_empresa.sql) e
 * vw_ml_requisito_responsaveis com as pessoas das regras do Legal (scripts\aplicar-migracao-407-responsaveis-legal.php).
 * Pode ser executada mais de uma vez. Depois: sair e entrar no portal (o menu fica na sessão).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-361-responsaveis-regras.php [--simular] [--banco=gcrown_dbp]
 *      ... --remover   desfaz tudo (as regras gravadas vão para storage/logs antes de apagar as tabelas)
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';
require_once dirname(__DIR__) . '/classes/ResponsaveisRegras.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$remover = in_array('--remover', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$rel = ['ok' => true, 'migracao' => '361', 'modo' => ($remover ? 'REMOVER' : 'APLICAR') . ($simular ? ' (SIMULACAO)' : ''), 'universal' => [], 'bancos' => []];
$logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);

$apagar = static function (PDO $p, string $tabela, array $chave): void {
    $s = $p->prepare('CALL sp_crud_delete(?, ?)');
    $s->execute([$tabela, json_encode($chave, JSON_UNESCAPED_UNICODE)]);
    $s->closeCursor();
};

// ---------------------------------------------------------------- 1) universal: um menu ao lado da tela de cada módulo
$cat = new MenuCatalogoService($env);
$menusUniversal = [];
try {
    $u = $cat->universal();
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (ResponsaveisRegras::AREAS as $area => $a) {
        $existe = menuExato361($u, $a['menu_regras']);
        if ($existe) $menusUniversal[$area] = (int)$existe['codmenu'];
        if ($remover) { $rel['universal']['menus'][$area] = !$existe ? 'não existe' : ($soBanco !== null ? 'mantido (--banco)' : ($simular ? 'será excluído' : 'a excluir')); continue; }
        $mod = menuExato361($u, $a['menu']);
        if (!$mod) { $rel['universal']['menus'][$area] = 'ignorado: menu ' . $a['menu'] . ' não existe no catálogo'; continue; }
        $mod = array_change_key_case($mod);
        $nome = $a['nome'];
        $dados = ['codmenu_pai' => $mod['codmenu_pai'] !== null ? (int)$mod['codmenu_pai'] : null, 'modulo' => $mod['modulo'] ?? null, 'codigo' => $a['menu_regras'],
            'label' => 'Responsáveis e avisos (' . $nome['pt'] . ')', 'label_pt' => 'Responsáveis e avisos (' . $nome['pt'] . ')',
            'label_en' => 'People responsible and alerts (' . $nome['en'] . ')', 'label_es' => 'Responsables y avisos (' . $nome['es'] . ')',
            'url' => $a['tela'], 'nivel' => (int)($mod['nivel'] ?? 1), 'icone' => 'bi-people', 'ativo' => 1];
        if ($simular) { $rel['universal']['menus'][$area] = $existe ? 'já existe (codmenu ' . $existe['codmenu'] . ')' : 'será criado ao lado de ' . $a['menu']; continue; }
        // ordem: logo depois da tela do módulo (na reaplicação, mantém a ordem que alguém tenha mudado em Setup › Menu)
        if (!$existe) $dados['ordem'] = (int)($mod['ordem'] ?? 0) + 1;
        $g = $cat->gravar($dados, $existe ? (int)$existe['codmenu'] : null);
        $menusUniversal[$area] = (int)$g['codmenu'];
        $rel['universal']['menus'][$area] = ($existe ? 'atualizado' : 'criado') . ' (codmenu ' . $g['codmenu'] . ')';
    }
} catch (Throwable $e) { $rel['ok'] = false; $rel['universal']['erro'] = $e->getMessage(); }

// Ajuda da tela (transação responsaveis_regras), no universal; não mexe se o texto foi editado na tela de ajuda
if (!$remover) {
    try { $rel['universal']['ajuda'] = gravarAjuda361($cat->universal(), require dirname(__DIR__) . '/database/361_ajuda_responsaveis_regras.php', $simular); }
    catch (Throwable $e) { $rel['universal']['ajuda'] = 'ERRO: ' . $e->getMessage(); }
}

// ---------------------------------------------------------------- 2) empresas
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'usuarios') AND TABLE_SCHEMA LIKE 'g%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 2 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp')
    && $b !== 'glegislacao_dbp' && ($soBanco === null || $b === $soBanco)));
if (!$bancos) $rel['bancos'] = ['aviso' => 'nenhuma empresa encontrada' . ($soBanco ? " ($soBanco)" : '')];
$sql = (string)file_get_contents(dirname(__DIR__) . '/database/361_responsaveis_regras.sql');

foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4');
        ResponsaveisRegras::limparCache();
        $areas = ResponsaveisRegras::areas($pdo);
        if (!array_filter($areas, static fn($s) => $s === 'ok')) { $rel['bancos'][$banco] = ['ignorado' => 'sem nenhum dos módulos']; continue; }

        if ($remover) { $rel['bancos'][$banco] = remover361($pdo, $banco, $logDir, $simular, $apagar); continue; }

        if ($simular) {
            $info['areas'] = $areas;
            foreach (ResponsaveisRegras::AREAS as $area => $a) if ($areas[$area] === 'ok') {
                $info['registros'][$area] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $a['tabela'] . '`')->fetchColumn();
                if ($a['tipo']) $info['tipos'][$area] = ResponsaveisRegras::existe($pdo, $a['tipo'][1]) ? (int)$pdo->query('SELECT COUNT(*) FROM `' . $a['tipo'][1] . '`')->fetchColumn() . ' tipo(s) em ' . $a['tipo'][1] : 'sem ' . $a['tipo'][1] . ': só por unidade';
                if (!ResponsaveisRegras::existe($pdo, $a['grid'])) $info['telas_minhas'][$area] = 'sem a view ' . $a['grid'] . ': tela Minhas continua como está';
            }
            $info['tabela'] = ResponsaveisRegras::existe($pdo, 'resp_regras') ? 'resp_regras já existe (' . (int)$pdo->query('SELECT COUNT(*) FROM resp_regras')->fetchColumn() . ' regra(s))' : 'resp_regras será criada';
            $info['efetiva'] = !ResponsaveisRegras::existe($pdo, ResponsaveisRegras::EFETIVA) ? 'sem vw_responsabilidade_efetiva: só regras e Responsável do registro'
                : (ResponsaveisRegras::efetivaTemRegras($pdo) ? 'já inclui as regras' : 'passará a incluir as regras (a anterior vai para vw_responsabilidade_efetiva_base e storage/logs)');
            $info['historico_359'] = ResponsaveisRegras::existe($pdo, 'historico_alteracoes') ? 'ok (aviso de alteração)' : 'sem historico_alteracoes: sem o aviso de alteração (rode a 359)';
            $info['alertas'] = ResponsaveisRegras::existe($pdo, 'alertas') ? 'ok' : 'sem a tabela alertas: os avisos não serão criados';
            $info['responsaveis_automaticos_345'] = ResponsaveisRegras::existe($pdo, 'vw_resp_auto_itens') ? 'vw_resp_auto_itens será refeita (regra conta como responsável)' : 'sem a 345';
            $info['modo'] = 'simulação: nada gravado';
            $rel['bancos'][$banco] = $info;
            continue;
        }

        // 1. parte fixa
        $n = 0;
        foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) {
            $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
            if ($cmd === '') continue;
            $pdo->exec($cmd); $n++;
        }
        ResponsaveisRegras::limparCache();
        $info['fixa'] = $n . ' bloco(s): tabelas e sp_resp_regras_avisar';
        // Migração 407 (24/11/2026): vínculo lei × categoria da empresa e views das categorias (para a área LEGAL).
        $sql407 = dirname(__DIR__) . '/database/407_legislacao_categorias_empresa.sql';
        if (is_file($sql407) && ResponsaveisRegras::existe($pdo, 'legislacaoxempresa')) {
            $ok407 = 0;
            foreach (preg_split('/^-- @@\s*$/m', (string)file_get_contents($sql407)) as $bloco) {
                $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
                if ($cmd === '') continue;
                try { $pdo->exec($cmd); $ok407++; } catch (Throwable $e) { $rel['ok'] = false; $info['categorias_407'][] = 'ERRO: ' . mb_substr($e->getMessage(), 0, 200); }
            }
            ResponsaveisRegras::limparCache();
            $info['categorias_407'][] = $ok407 . ' bloco(s): legislacaoxcategoria_empresa, vw_legislacao_categorias, vw_legislacao_categorias_empresa';
        }
        $info['areas'] = $areas;
        // 2. views das regras
        foreach (ResponsaveisRegras::viewsRegras($pdo) as $nome => $ddl) { $pdo->exec($ddl); $info['views'][$nome] = 'ok'; }
        ResponsaveisRegras::limparCache();
        // 3. efetiva com as regras
        $info['efetiva'] = ResponsaveisRegras::efetivaAplicar($pdo, $logDir, $banco);
        ResponsaveisRegras::limparCache();
        // 4. acesso, telas Minhas e avisos
        [$ddls, $sit] = ResponsaveisRegras::viewsAcesso($pdo, ResponsaveisRegras::viewAtribuicoes($pdo));
        foreach ($ddls as $nome => $ddl) {
            try { $pdo->exec($ddl); $info['views'][$nome] = 'ok'; }
            catch (Throwable $e) { $rel['ok'] = false; $info['views'][$nome] = 'ERRO: ' . mb_substr($e->getMessage(), 0, 220); }
        }
        $info['telas_minhas'] = $sit;
        // conferência: as views respondem
        foreach (['vw_resp_regras_registros', 'vw_resp_acesso', 'vw_resp_regras_avisos_pendentes'] as $v) {
            $t = microtime(true);
            try { $q = (int)$pdo->query("SELECT COUNT(*) FROM `$v`")->fetchColumn(); $info['conferencia'][$v] = sprintf('%d linha(s), %.2fs', $q, microtime(true) - $t); }
            catch (Throwable $e) { $rel['ok'] = false; $info['conferencia'][$v] = 'ERRO: ' . mb_substr($e->getMessage(), 0, 220); }
        }
        // 5. responsáveis automáticos (345)
        $info['responsaveis_automaticos_345'] = refazer345($pdo);
        // 6. permissões dos menus novos = as da tela do módulo
        $info['permissoes'] = permissoes361($pdo);
        if (!ResponsaveisRegras::existe($pdo, 'alertas')) $info['alertas'] = 'sem a tabela alertas: os avisos não serão criados (rode a migração 281/343)';
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}

// ---------------------------------------------------------------- 3) remover: menus do catálogo (depois das permissões das empresas)
if ($remover && !$simular && $soBanco !== null) $rel['universal']['menus'] = 'mantidos no catálogo (--banco: só esta empresa foi desfeita)';
elseif ($remover && !$simular) {
    foreach ($menusUniversal as $area => $cod) {
        try { $x = $cat->excluir($cod); $rel['universal']['menus'][$area] = 'excluído (' . count($x['empresas']) . ' empresa(s))'; }
        catch (Throwable $e) { $rel['ok'] = false; $rel['universal']['menus'][$area] = 'ERRO ao excluir: ' . $e->getMessage(); }
    }
}

/** Menu pelo código exato (24/11/2026: o filtro de texto é por trecho — LICENCAS também acha HOME_LICENCAS, da migração 381). */
function menuExato361(PDO $p, string $codigo): ?array
{
    foreach (MigracaoCrud::selecionar($p, 'menus', ['codigo' => $codigo]) as $m) if (strcasecmp((string)(array_change_key_case($m)['codigo'] ?? ''), $codigo) === 0) return $m;
    return null;
}

/** Ajuda: trecho entre <!-- ml361 --> e <!-- /ml361 --> no texto da transação (só esse trecho é trocado ao rodar de novo). */
function gravarAjuda361(PDO $u, array $textos, bool $simular): array
{
    if (!MigracaoCrud::existe($u, 'ajuda_transacoes')) return ['aviso' => 'sem ajuda_transacoes no universal'];
    $out = [];
    foreach ($textos as $transacao => $d) {
        $atual = MigracaoCrud::primeiro($u, 'ajuda_transacoes', ['transacao' => $transacao]);
        $dados = [];
        foreach (['pt', 'en', 'es'] as $lg) {
            $velho = trim((string)preg_replace('/\s*<!-- ml361 -->.*?<!-- \/ml361 -->/s', '', (string)($atual['texto_' . $lg] ?? '')));
            $dados['texto_' . $lg] = ($velho === '' ? '' : $velho . "\n") . "<!-- ml361 -->\n" . $d['texto'][$lg] . "\n<!-- /ml361 -->";
            if ($atual === null || trim((string)($atual['titulo_' . $lg] ?? '')) === '') $dados['titulo_' . $lg] = $d['titulo'][$lg];
        }
        if ($atual !== null) { $igual = true; foreach ($dados as $k => $v) if ((string)($atual[$k] ?? '') !== $v) $igual = false; if ($igual) { $out[$transacao] = 'igual'; continue; } }
        if ($simular) { $out[$transacao] = $atual === null ? 'será incluída' : 'será atualizada'; continue; }
        $r = MigracaoCrud::salvar($u, 'ajuda_transacoes', ['transacao' => $transacao], $dados + ['ativo' => 1]);
        $out[$transacao] = $r === 'alterado' ? 'atualizada' : 'incluída';
    }
    return $out;
}

/** vw_resp_auto_itens (345) refeita: com a 361, registro coberto por regra conta como "com responsável". */
function refazer345(PDO $pdo): string
{
    // pelas tabelas, não pelas colunas: depois do --remover a view da 345 fica inválida (aponta para vw_resp_regras_registros) até ser refeita
    $s = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vw_resp_auto_itens'");
    $tem = (int)$s->fetchColumn() > 0; $s->closeCursor();
    if (!$tem) return 'sem a 345 (nada a fazer)';
    require_once dirname(__DIR__) . '/classes/ResponsavelAutomatico.php';
    try {
        [$ddl] = ResponsavelAutomatico::viewItens($pdo);
        $pdo->exec($ddl);
        return 'vw_resp_auto_itens refeita' . (str_contains($ddl, 'vw_resp_regras_registros') ? ' (regra conta como responsável)' : '');
    } catch (Throwable $e) { return 'ERRO ao refazer vw_resp_auto_itens: ' . mb_substr($e->getMessage(), 0, 200); }
}

/** Permissão de cada menu novo = a da tela do módulo (todas as marcas), por perfil; não mexe em permissão já existente. */
function permissoes361(PDO $pdo): array
{
    $out = [];
    foreach (ResponsaveisRegras::AREAS as $area => $a) {
        $novo = menuExato361($pdo, $a['menu_regras']);
        $orig = menuExato361($pdo, $a['menu']);
        if (!$novo || !$orig) { $out[$area] = !$novo ? 'menu ainda não replicado neste banco' : 'sem o menu ' . $a['menu']; continue; }
        $cont = 0;
        foreach (['permissoes', 'perfil_menus'] as $t) {
            if (!MigracaoCrud::existe($pdo, $t)) continue;
            $cols = MigracaoCrud::colunas($pdo, $t);
            if (!in_array('codmenu', $cols, true) || !in_array('codperfil', $cols, true)) continue;
            foreach (MigracaoCrud::selecionar($pdo, $t, ['codmenu' => (int)$orig['codmenu']]) as $p) {
                $p = array_change_key_case($p);
                $flags = array_filter($p, static fn($k) => str_starts_with((string)$k, 'pode_'), ARRAY_FILTER_USE_KEY);
                if (!array_filter($flags)) continue;
                if (MigracaoCrud::primeiro($pdo, $t, ['codperfil' => (int)$p['codperfil'], 'codmenu' => (int)$novo['codmenu']])) continue;
                MigracaoCrud::inserir($pdo, $t, ['codperfil' => (int)$p['codperfil'], 'codmenu' => (int)$novo['codmenu']] + $flags);
                $cont++;
            }
        }
        $out[$area] = $cont . ' permissão(ões) criada(s) (as mesmas de ' . $a['menu'] . ')';
    }
    return $out;
}

/** --remover: guarda as regras em storage/logs, apaga views, procedure e tabelas, restaura a efetiva e a 345, tira as permissões. */
function remover361(PDO $pdo, string $banco, string $logDir, bool $simular, callable $apagar): array
{
    $info = [];
    $tem = ResponsaveisRegras::existe($pdo, 'resp_regras');
    $info['regras'] = $tem ? (int)$pdo->query('SELECT COUNT(*) FROM resp_regras')->fetchColumn() . ' regra(s)' : 'sem a tabela';
    $info['efetiva'] = ResponsaveisRegras::existe($pdo, ResponsaveisRegras::EFETIVA_BASE) ? ($simular ? 'será restaurada' : '') : 'não foi alterada';
    if ($simular) { $info['modo'] = 'simulação: nada removido'; return $info; }
    if ($tem) {
        $dump = MigracaoCrud::selecionar($pdo, 'resp_regras');
        @file_put_contents($logDir . DIRECTORY_SEPARATOR . "migracao_361_regras_removidas_{$banco}_" . date('Ymd_His') . '.json', json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    if (ResponsaveisRegras::existe($pdo, ResponsaveisRegras::EFETIVA_BASE)) $info['efetiva'] = ResponsaveisRegras::efetivaRestaurar($pdo);
    foreach (ResponsaveisRegras::todasViews() as $v) $pdo->exec("DROP VIEW IF EXISTS `$v`");
    $pdo->exec('DROP PROCEDURE IF EXISTS `sp_resp_regras_avisar`');
    foreach (['resp_regras_avisos', 'resp_regras_controle', 'resp_regras'] as $t) $pdo->exec("DROP TABLE IF EXISTS `$t`");
    ResponsaveisRegras::limparCache();
    $info['estrutura'] = 'views, procedure e tabelas removidas' . ($tem ? ' (regras guardadas em storage/logs)' : '');
    $info['responsaveis_automaticos_345'] = refazer345($pdo);
    $cont = 0;
    foreach (ResponsaveisRegras::AREAS as $a) {
        $m = menuExato361($pdo, $a['menu_regras']);
        if (!$m) continue;
        foreach (['permissoes', 'perfil_menus'] as $t) {
            if (!MigracaoCrud::existe($pdo, $t)) continue;
            $cols = MigracaoCrud::colunas($pdo, $t);
            $pk = in_array('codpermissao', $cols, true) ? 'codpermissao' : null;
            foreach (MigracaoCrud::selecionar($pdo, $t, ['codmenu' => (int)$m['codmenu']]) as $p) {
                $p = array_change_key_case($p);
                $apagar($pdo, $t, $pk ? [$pk => (int)$p[$pk]] : ['codperfil' => (int)$p['codperfil'], 'codmenu' => (int)$m['codmenu']]);
                $cont++;
            }
        }
    }
    $info['permissoes'] = $cont . ' permissão(ões) dos menus da 361 retirada(s)';
    return $info;
}

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents($logDir . DIRECTORY_SEPARATOR . 'migracao_361_responsaveis_regras' . ($remover ? '_remover' : '') . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
