<?php
declare(strict_types=1);

/*
 * Migração 340 — Taxímetro de IA (23/10/2026).
 *  1. Universal: tabelas de preços da OpenAI (ia_precos, já com os preços de hoje) e de cotação do dólar (ia_cambio); menu
 *     Administração › Consumo de IA (ADMINISTRACAO_CONSUMO_IA, principal/administracao_consumo_ia.php); serviço "Taxímetro de IA"
 *     (IA_CONSUMO) em Administração › Serviços; cotações PTAX desde o pedido de IA mais antigo (Banco Central).
 *  2. Em cada banco de empresa: tabelas, views e procedures de database/340_ia_consumo.sql; parâmetros do limite (sem limite, folga de
 *     10%, aviso em 80%, acréscimo 0%); permissão do menu: ADMINISTRADOR (manutenção) e ADM DA EMPRESA (consulta, Excel e PDF);
 *     estimativa dos pedidos anteriores ao taxímetro: fila de IA pelo tamanho do texto (marcada "estimado") e assistente pelos
 *     tokens que ele já gravava.
 * Depois: instalar a tarefa (PowerShell como Administrador): powershell -ExecutionPolicy Bypass -File scripts\criar-agendamento-ia-consumo.ps1
 * Não altera nada no legado. Pode ser executada mais de uma vez (a estimativa não se repete). --simular só confere.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-340-ia-consumo.php [--simular] [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';
require_once dirname(__DIR__) . '/classes/IaTaximetro.php';

$simular = in_array('--simular', $argv ?? [], true);
try { $bancos = HomologacaoBancos::lista($argv ?? []); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
$env = GreenPortalDatabase::env();
$is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$is->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$valor = static function (PDO $pdo, string $sql, array $p = []): mixed { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchColumn(); $s->closeCursor(); return $r; };
$tabela = static fn(string $db, string $t): bool => (int)$valor($is, 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db, $t]) > 0;
$colunas = static fn(string $db, string $t): array => array_map('strtolower', array_column($is->query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ' . $is->quote($db) . ' AND TABLE_NAME = ' . $is->quote($t))->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME'));
$blocos = static function (string $arquivo, int $minimo): array {
    $out = [];
    foreach (preg_split('/^-- @@\s*$/m', (string)@file_get_contents(dirname(__DIR__) . '/database/' . $arquivo)) as $b) {
        $limpo = trim(preg_replace('/^\s*--.*$/m', '', $b) ?? '');
        if ($limpo !== '') $out[] = $limpo;
    }
    if (count($out) < $minimo) { fwrite(STDERR, "database/$arquivo incompleto.\n"); exit(1); }
    return $out;
};
$blocosEmpresa = $blocos('340_ia_consumo.sql', 20);
$blocosUniversal = $blocos('340_ia_consumo_universal.sql', 7);

/** Preços de 23/10/2026 (US$ por 1 milhão de tokens; entrada, entrada em cache, saída). O ADMINISTRADOR corrige na tela. */
const M340_PRECOS = [
    ['*', 'Preço padrão (modelo sem preço próprio)', 0.15, 0.075, 0.60, 'igual ao gpt-4o-mini'],
    ['gpt-4o-mini', 'GPT-4o mini', 0.15, 0.075, 0.60, 'developers.openai.com/api/docs/models/gpt-4o-mini (23/10/2026)'],
    ['gpt-4o', 'GPT-4o', 2.50, 1.25, 10.00, 'tabela de preços da OpenAI; conferir'],
    ['gpt-4.1-nano', 'GPT-4.1 nano', 0.10, 0.025, 0.40, 'tabela de preços da OpenAI; conferir'],
    ['gpt-4.1-mini', 'GPT-4.1 mini', 0.40, 0.10, 1.60, 'tabela de preços da OpenAI; conferir'],
    ['gpt-4.1', 'GPT-4.1', 2.00, 0.50, 8.00, 'tabela de preços da OpenAI; conferir'],
    ['gpt-5-nano', 'GPT-5 nano', 0.05, 0.005, 0.40, 'tabela de preços da OpenAI; conferir'],
    ['gpt-5-mini', 'GPT-5 mini', 0.25, 0.025, 2.00, 'tabela de preços da OpenAI; conferir'],
    ['gpt-5', 'GPT-5', 1.25, 0.125, 10.00, 'tabela de preços da OpenAI; conferir'],
];
$perfilNormal = static fn(string $c): string => trim((string)preg_replace('/[\s_\-]+/', ' ', strtoupper(trim($c))));

$rel = ['ok' => true, 'migracao' => '340', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR'];

// ---------------------------------------------------------------- 1. universal
$cat = new MenuCatalogoService($env);
try {
    $u = $cat->universal();
    $r = [];
    $temPrecos = (int)$valor($is, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'ia_precos'", [$cat->bancoUniversal()]) > 0;
    if ($simular) $r['estrutura'] = $temPrecos ? 'tabelas de preços e câmbio já existem (seriam atualizadas)' : 'tabelas de preços e câmbio seriam criadas';
    else { foreach ($blocosUniversal as $b) $u->exec($b); $r['estrutura'] = count($blocosUniversal) . ' blocos gravados (preços e câmbio)'; }
    // Preços: só quando a tabela está vazia (depois, quem cuida é o ADMINISTRADOR na tela)
    $qtdPrecos = $temPrecos || !$simular ? (int)$u->query('SELECT COUNT(*) FROM ia_precos')->fetchColumn() : 0;
    if ($qtdPrecos > 0) $r['precos'] = $qtdPrecos . ' preço(s) já cadastrado(s) (mantidos)';
    elseif ($simular) $r['precos'] = count(M340_PRECOS) . ' preços seriam cadastrados';
    else {
        $s = $u->prepare("CALL sp_ia_preco_salvar(0, ?, ?, ?, ?, ?, '2024-01-01', 1, ?, 'migração 340')");
        foreach (M340_PRECOS as [$m, $d, $in, $ca, $out, $fonte]) { $s->execute([$m, $d, $in, $ca, $out, $fonte]); while ($s->nextRowset()) {} $s->closeCursor(); }
        $r['precos'] = count(M340_PRECOS) . ' preços cadastrados (vigentes desde 01/01/2024)';
    }
    // Menu Administração › Consumo de IA
    $pai = MigracaoCrud::primeiro($u, 'menus', ['codigo' => 'ADMINISTRACAO']);
    if ($existente = MigracaoCrud::primeiro($u, 'menus', ['codigo' => IaTaximetro::MENU])) {
        if (!$simular) $cat->replicar(IaTaximetro::MENU, null, false);
        $r['menu'] = 'já existia (codmenu ' . $existente['codmenu'] . ')';
    } elseif (!$pai) { $r['menu'] = 'ERRO: menu ADMINISTRACAO não encontrado no universal'; $rel['ok'] = false; }
    elseif ($simular) $r['menu'] = 'será criado: Administração › Consumo de IA';
    else {
        $pai = array_change_key_case($pai);
        $ordem = (int)$valor($u, 'SELECT COALESCE(MAX(ordem), 0) FROM menus WHERE codmenu_pai = ?', [(int)$pai['codmenu']]) + 1;
        $g = $cat->gravar(['codmenu_pai' => (int)$pai['codmenu'], 'modulo' => 'ADMINISTRACAO', 'codigo' => IaTaximetro::MENU, 'label' => 'Consumo de IA', 'label_pt' => 'Consumo de IA',
            'label_en' => 'AI usage', 'label_es' => 'Consumo de IA', 'url' => '/principal/administracao_consumo_ia.php', 'nivel' => (int)($pai['nivel'] ?? 0) + 1, 'ordem' => $ordem,
            'icone' => 'bi-speedometer2', 'ativo' => 1]);
        $r['menu'] = 'criado (codmenu ' . $g['codmenu'] . ')';
    }
    // Serviço em Administração › Serviços
    try {
        if ((int)$u->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'servicos'")->fetchColumn() > 0) {
            $cols = array_map('strtolower', $u->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'servicos'")->fetchAll(PDO::FETCH_COLUMN));
            $dados = array_intersect_key(['nome_pt' => 'Taxímetro de IA', 'nome_en' => 'AI usage meter', 'nome_es' => 'Taxímetro de IA',
                'descricao_pt' => 'Grava a cotação PTAX do dia e avisa os administradores quando o consumo de IA da empresa chega a 80%, ao limite e ao bloqueio.',
                'descricao_en' => 'Stores the daily PTAX rate and warns administrators when the company AI usage reaches 80%, the limit and the block.',
                'descricao_es' => 'Graba la cotización PTAX del día y avisa a los administradores cuando el consumo de IA llega al 80%, al límite y al bloqueo.',
                'host' => 'TASK_SCHEDULER', 'script' => 'scripts/ia-consumo-worker.php', 'ativo' => 1], array_flip($cols));
            $existe = (int)$u->query("SELECT COUNT(*) FROM servicos WHERE codigo = 'IA_CONSUMO'")->fetchColumn();
            if ($simular) $r['servico'] = $existe ? 'será atualizado' : 'será incluído';
            elseif ($existe) { $u->prepare('UPDATE servicos SET ' . implode(', ', array_map(static fn($c) => "`$c` = ?", array_keys($dados))) . " WHERE codigo = 'IA_CONSUMO'")->execute(array_values($dados)); $r['servico'] = 'atualizado'; }
            else {
                $todos = ['codigo' => 'IA_CONSUMO'] + $dados + array_intersect_key(['porta' => 0, 'ordem' => 17], array_flip($cols));
                $u->prepare('INSERT INTO servicos (`' . implode('`, `', array_keys($todos)) . '`) VALUES (' . implode(', ', array_fill(0, count($todos), '?')) . ')')->execute(array_values($todos));
                $r['servico'] = 'incluído';
            }
        }
    } catch (Throwable $e) { $r['servico'] = 'aviso: ' . $e->getMessage(); }
    $rel['universal'] = $r;
} catch (Throwable $e) { $rel['ok'] = false; $rel['universal'] = ['erro' => $e->getMessage()]; }

// ---------------------------------------------------------------- 2. cotações PTAX desde o pedido de IA mais antigo
$maisAntigo = null;
foreach ($bancos as $banco) {
    try {
        if ($tabela($banco, 'green_ia_document_jobs')) { $d = (string)$valor($is, "SELECT DATE(MIN(criado_em)) FROM `$banco`.green_ia_document_jobs WHERE status = 'CONCLUIDA'"); if ($d !== '' && ($maisAntigo === null || $d < $maisAntigo)) $maisAntigo = $d; }
        if ($tabela($banco, 'ia_assistente_mensagens')) { $d = (string)$valor($is, "SELECT DATE(MIN(criado_em)) FROM `$banco`.ia_assistente_mensagens WHERE tokens_entrada IS NOT NULL"); if ($d !== '' && ($maisAntigo === null || $d < $maisAntigo)) $maisAntigo = $d; }
    } catch (Throwable) {}
}
$desde = $maisAntigo ? (new DateTimeImmutable($maisAntigo))->modify('-7 days')->format('Y-m-d') : (new DateTimeImmutable())->modify('-15 days')->format('Y-m-d');
if ($simular) $rel['ptax'] = 'cotações seriam buscadas no Banco Central desde ' . $desde;
else {
    try {
        $temAntes = (string)($cat->universal()->query('SELECT MIN(DATA) FROM vw_ia_cambio')->fetchColumn() ?: '');
        $p = IaTaximetro::ptaxAtualizar($temAntes !== '' && $temAntes <= $desde ? null : $desde, 'migração 340');
        $rel['ptax'] = $p['gravadas'] . ' cotação(ões) gravada(s); última: ' . ($p['ultima'] ?? '—');
    } catch (Throwable $e) { $rel['ptax'] = 'AVISO: ' . $e->getMessage() . ' (o serviço tenta de novo a cada execução; dá para digitar a cotação na tela)'; }
}
IaTaximetro::recarregar();
$semCotacao = !$simular && !IaTaximetro::cambio();
$modelo = IaTaximetro::modeloPadrao();
$rel['modelo_da_estimativa'] = $modelo;

// ---------------------------------------------------------------- 3. bancos das empresas
foreach ($bancos as $banco) {
    $info = [];
    try {
        if (!$tabela($banco, 'usuarios') || !$tabela($banco, 'alertas')) { $rel['bancos'][$banco] = 'banco sem usuários/alertas: nada a fazer'; continue; }
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $temJobs = $tabela($banco, 'green_ia_document_jobs');
        $temMsgs = $tabela($banco, 'ia_assistente_mensagens') && in_array('tokens_entrada', $colunas($banco, 'ia_assistente_mensagens'), true);
        $ja = $tabela($banco, 'ia_consumo');
        if ($simular) {
            $info['estrutura'] = $ja ? 'tabelas já existem (views e procedures seriam regravadas)' : 'tabelas, views e procedures seriam criadas';
            $info['estimativa'] = [
                'pedidos_da_fila' => $temJobs ? (int)$valor($pdo, "SELECT COUNT(*) FROM green_ia_document_jobs WHERE status = 'CONCLUIDA'") : 0,
                'respostas_do_assistente' => $temMsgs ? (int)$valor($pdo, "SELECT COUNT(*) FROM ia_assistente_mensagens WHERE papel = 'ASSISTENTE' AND tokens_entrada IS NOT NULL") : 0,
            ];
            $rel['bancos'][$banco] = $info;
            continue;
        }
        // Colunas de usuarios que nem todo banco tem: a view usa só as que existem
        $colsU = $colunas($banco, 'usuarios');
        $trocas = [];
        if (!in_array('userid', $colsU, true)) $trocas['u.userid'] = 'NULL';
        if (!in_array('emails', $colsU, true)) $trocas["NULLIF(TRIM(u.emails), '')"] = 'NULL';
        if (!in_array('email', $colsU, true)) $trocas["NULLIF(TRIM(u.email), '')"] = 'NULL';
        if ($trocas) $info['usuarios_sem_coluna'] = array_keys($trocas);
        $semPerfis = !$tabela($banco, 'perfis') || !in_array('codperfil', $colsU, true);
        foreach ($blocosEmpresa as $b) {
            if ($semPerfis && str_contains($b, 'vw_ia_limite_destinatarios') && str_contains($b, 'JOIN perfis')) {
                $b = "CREATE OR REPLACE VIEW `vw_ia_limite_destinatarios` AS SELECT CAST(0 AS SIGNED) AS CODUSUARIO, CAST('' AS CHAR(1)) AS NOME, CAST('' AS CHAR(1)) AS EMAIL, CAST(0 AS SIGNED) AS ADMINISTRADOR FROM DUAL WHERE 1 = 0";
                $info['avisos'] = 'banco sem perfis: os avisos do limite ficam sem destinatário';
            }
            $pdo->exec($trocas ? strtr($b, $trocas) : $b);
        }
        $info['estrutura'] = count($blocosEmpresa) . ' blocos gravados (tabelas, views e procedures)';
        // Parâmetros do limite (só os que faltam): sem limite até o ADMINISTRADOR definir
        $s = $pdo->prepare('INSERT IGNORE INTO ia_limite_config (CHAVE, VALOR) VALUES (?, ?)');
        foreach (['LIMITE_MENSAL_BRL' => '', 'FOLGA_PCT' => '10', 'AVISO_PCT' => '80', 'ACRESCIMO_PCT' => '0'] as $k => $v) $s->execute([$k, $v]);
        $cfg = $pdo->query('SELECT * FROM vw_ia_limite_config')->fetch(PDO::FETCH_ASSOC);
        $info['limite'] = ($cfg['LIMITE_MENSAL_BRL'] === null ? 'sem limite' : IaTaximetro::moeda((float)$cfg['LIMITE_MENSAL_BRL'])) . '; folga ' . (float)$cfg['FOLGA_PCT'] . '%; aviso em ' . (float)$cfg['AVISO_PCT'] . '%; acréscimo ' . (float)$cfg['ACRESCIMO_PCT'] . '%';
        // Permissões: ADMINISTRADOR (tudo) e ADM DA EMPRESA (consulta, Excel e PDF)
        $codmenu = (int)$valor($pdo, 'SELECT codmenu FROM menus WHERE codigo = ?', [IaTaximetro::MENU]);
        if ($codmenu < 1) $info['permissao'] = 'menu ainda não replicado neste banco';
        elseif ($tabela($banco, 'perfis')) {
            $cont = [];
            foreach ($pdo->query('SELECT codperfil, codigo FROM perfis')->fetchAll(PDO::FETCH_ASSOC) as $perfil) {
                $n = $perfilNormal((string)$perfil['codigo']);
                $adm = $n === 'ADMINISTRADOR';
                if (!$adm && !in_array($n, ['ADM DA EMPRESA', 'ADM EMPRESA'], true)) continue;
                foreach (['permissoes', 'perfil_menus'] as $t) {
                    $cols = $tabela($banco, $t) ? $colunas($banco, $t) : [];
                    if (!$cols) continue;
                    $flags = [];
                    foreach ($cols as $c) if (str_starts_with($c, 'pode_')) $flags[$c] = $adm || in_array($c, ['pode_visualizar', 'pode_visualizar_total', 'pode_exportar_excel', 'pode_gerar_pdf'], true) ? 1 : 0;
                    $q = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE codperfil = ? AND codmenu = ?"); $q->execute([(int)$perfil['codperfil'], $codmenu]);
                    if ((int)$q->fetchColumn() > 0) {
                        if ($flags) $pdo->prepare("UPDATE `$t` SET " . implode(', ', array_map(static fn($c) => "`$c` = GREATEST(`$c`, ?)", array_keys($flags))) . ' WHERE codperfil = ? AND codmenu = ?')->execute([...array_values($flags), (int)$perfil['codperfil'], $codmenu]);
                    } else {
                        $campos = ['codperfil', 'codmenu', ...array_keys($flags)];
                        $pdo->prepare("INSERT INTO `$t` (`" . implode('`, `', $campos) . '`) VALUES (' . implode(', ', array_fill(0, count($campos), '?')) . ')')->execute([(int)$perfil['codperfil'], $codmenu, ...array_values($flags)]);
                    }
                }
                $cont[] = $perfil['codigo'] . ($adm ? ' (manutenção)' : ' (consulta)');
            }
            $info['permissao'] = $cont ? implode(', ', $cont) : 'nenhum perfil ADMINISTRADOR / ADM DA EMPRESA neste banco';
        }
        // Estimativa dos pedidos anteriores ao taxímetro (uma vez: até o último pedido existente na primeira execução)
        if ($semCotacao) $info['estimativa'] = 'adiada: o universal ainda não tem cotação do dólar (rode a migração de novo quando o PTAX responder, ou digite a cotação na tela)';
        else {
            $marca = static function (string $chave, int $atual) use ($pdo, $valor): int {
                $v = (string)($valor($pdo, 'SELECT VALOR FROM ia_limite_config WHERE CHAVE = ?', [$chave]) ?: '');
                if ($v === '') { $pdo->prepare('INSERT INTO ia_limite_config (CHAVE, VALOR) VALUES (?, ?) ON DUPLICATE KEY UPDATE VALOR = VALUES(VALOR)')->execute([$chave, (string)$atual]); return $atual; }
                return (int)$v;
            };
            $est = ['pedidos_da_fila' => 0, 'respostas_do_assistente' => 0, 'custo_estimado' => 0.0];
            if ($temJobs) {
                $ate = $marca('ESTIMATIVA_ATE_JOB', (int)$valor($pdo, 'SELECT COALESCE(MAX(codjob), 0) FROM green_ia_document_jobs'));
                $q = $pdo->prepare("SELECT j.codjob, j.tipo, j.entidade, j.registro_id, j.codusuario, COALESCE(j.concluida_em, j.criado_em) AS data,
                        CHAR_LENGTH(j.prompt) + CHAR_LENGTH(j.texto) AS entrada, COALESCE(CHAR_LENGTH(j.resultado_json), 0) AS saida
                    FROM green_ia_document_jobs j
                   WHERE j.status = 'CONCLUIDA' AND j.codjob <= ? AND NOT EXISTS (SELECT 1 FROM ia_consumo c WHERE c.CODJOB = j.codjob)
                   ORDER BY j.codjob");
                $q->execute([$ate]);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $j) {
                    $in = (int)ceil(((int)$j['entrada'] + 200) / IaTaximetro::CARACTERES_POR_TOKEN);
                    $out = (int)ceil((int)$j['saida'] / IaTaximetro::CARACTERES_POR_TOKEN);
                    $dia = substr((string)$j['data'], 0, 10);
                    $c = IaTaximetro::custo($modelo, $in, 0, $out, $dia);
                    $tipo = strtoupper((string)$j['tipo']);
                    $est['pedidos_da_fila'] += IaTaximetro::gravar($pdo, ['criado' => (string)$j['data'], 'empresa' => 0, 'usuario' => (int)$j['codusuario'], 'modulo' => IaTaximetro::modulo($tipo),
                        'tipo' => $tipo, 'entidade' => (string)$j['entidade'], 'registro' => (string)$j['registro_id'], 'codjob' => (int)$j['codjob'], 'origem' => 'HISTORICO', 'modelo' => $modelo,
                        'preco_modelo' => $c['preco_modelo'], 'entrada' => $in, 'cache' => 0, 'saida' => $out, 'usd' => $c['usd'], 'cotacao' => $c['cotacao'], 'cotacao_data' => $c['cotacao_data'],
                        'estimado' => 1, 'sucesso' => 1, 'ms' => null, 'erro' => null, 'referencia' => 'JOB:' . (int)$j['codjob']]);
                }
            }
            if ($temMsgs) {
                $ate = $marca('ESTIMATIVA_ATE_MSG', (int)$valor($pdo, 'SELECT COALESCE(MAX(codmensagem), 0) FROM ia_assistente_mensagens'));
                $q = $pdo->prepare("SELECT codmensagem, codconversa, codusuario, criado_em, modelo, COALESCE(tokens_entrada, 0) AS entrada, COALESCE(tokens_saida, 0) AS saida, duracao_ms
                    FROM ia_assistente_mensagens WHERE papel = 'ASSISTENTE' AND tokens_entrada IS NOT NULL AND codmensagem <= ? ORDER BY codmensagem");
                $q->execute([$ate]);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $m) {
                    $mod = trim((string)$m['modelo']) ?: $modelo;
                    $c = IaTaximetro::custo($mod, (int)$m['entrada'], 0, (int)$m['saida'], substr((string)$m['criado_em'], 0, 10));
                    $est['respostas_do_assistente'] += IaTaximetro::gravar($pdo, ['criado' => (string)$m['criado_em'], 'empresa' => 0, 'usuario' => (int)$m['codusuario'], 'modulo' => IaTaximetro::modulo('ASSISTENTE'),
                        'tipo' => 'ASSISTENTE', 'entidade' => 'ia_assistente_conversas', 'registro' => (string)$m['codconversa'], 'codjob' => 0, 'origem' => 'HISTORICO', 'modelo' => $mod,
                        'preco_modelo' => $c['preco_modelo'], 'entrada' => (int)$m['entrada'], 'cache' => 0, 'saida' => (int)$m['saida'], 'usd' => $c['usd'], 'cotacao' => $c['cotacao'], 'cotacao_data' => $c['cotacao_data'],
                        'estimado' => 0, 'sucesso' => 1, 'ms' => $m['duracao_ms'] !== null ? (int)$m['duracao_ms'] : null, 'erro' => null, 'referencia' => 'MSG:' . (int)$m['codmensagem']]);
                }
            }
            $est['custo_estimado'] = IaTaximetro::moeda((float)$valor($pdo, "SELECT COALESCE(SUM(CUSTO_BRL), 0) FROM ia_consumo WHERE ORIGEM = 'HISTORICO'"));
            $info['estimativa'] = $est + ['observacao' => 'pedidos da fila com tokens estimados pelo tamanho do texto; assistente com os tokens que ele já gravava'];
        }
        $sit = IaTaximetro::situacao($pdo) ?? [];
        $info['mes_atual'] = ['consumo' => IaTaximetro::moeda((float)($sit['CONSUMO_BRL'] ?? 0)), 'chamadas' => (int)($sit['CHAMADAS'] ?? 0), 'situacao' => (string)($sit['SITUACAO'] ?? '')];
        $rel['bancos'][$banco] = $info;
    } catch (Throwable $e) { $rel['ok'] = false; $rel['bancos'][$banco] = $info + ['erro' => $e->getMessage()]; }
}
$rel['proximo_passo'] = 'instalar a tarefa: powershell -ExecutionPolicy Bypass -File scripts\\criar-agendamento-ia-consumo.ps1 (PowerShell como Administrador); sair e entrar de novo no portal para ver Administração › Consumo de IA';
$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_340_ia_consumo' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
