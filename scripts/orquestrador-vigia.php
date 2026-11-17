<?php
declare(strict_types=1);

/*
 * Vigia do orquestrador (migração 353; decisão do usuário de 11/11/2026).
 * Roda na tarefa \GreenSolutions\GreenPortal-Orquestrador-Vigia (a cada 10 min), FORA das trilhas: se uma trilha morre,
 * o vigia continua rodando e avisa. Motivo: de 28/10 a 11/11/2026 as 3 trilhas ficaram paradas sem ninguém saber.
 *
 * Cada execução:
 *   1. grava a situação das 4 tarefas do orquestrador no Agendador (sp_orq_tarefa_windows);
 *   2. lê os disjuntores por empresa (storage\runtime\disjuntor-<serviço>.json) e abre aviso para cada empresa suspensa;
 *   3. lê os problemas atuais (sp_orq_saude): trilha sem sinal de vida há mais de 15 min, trilha em SIMULAR ou desligada,
 *      tarefa desativada ou não encontrada, serviço suspenso por 3 falhas seguidas;
 *   4. alerta os ADMINISTRADORES ativos quando o problema aparece, de novo a cada 24 h enquanto continuar, e quando ele é
 *      resolvido. Problema de uma empresa só vai para os ADMINISTRADORES dessa empresa; os outros, para todas;
 *   5. grava o próprio sinal de vida (sp_orq_vigia_sinal), que a tela Serviços e o aviso do portal conferem.
 * Os alertas viram e-mail pelo serviço de E-mail (trilha 2); se a trilha 2 estiver parada, ficam no sino do portal, e o
 * ADMINISTRADOR também vê o aviso em vermelho ao abrir o portal (components/aviso_servicos.php).
 *
 * Uso: php scripts\orquestrador-vigia.php [--detalhes]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/AgendadorWindows.php';
require_once dirname(__DIR__) . '/classes/WindowsServiceRuntime.php';

date_default_timezone_set('America/Sao_Paulo');
@set_time_limit(240);

$detalhes = in_array('--detalhes', $argv ?? [], true);
$raiz = dirname(__DIR__);
$dirRuntime = $raiz . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'runtime';
$dirLog = $raiz . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'orquestrador';
foreach ([$dirRuntime, $dirLog] as $d) if (!is_dir($d)) @mkdir($d, 0770, true);
$log = static function (string $texto) use ($dirLog): void {
    @file_put_contents($dirLog . DIRECTORY_SEPARATOR . 'vigia-' . date('Ym') . '.log', date('Y-m-d H:i:s') . ' ' . $texto . PHP_EOL, FILE_APPEND);
};

$trava = fopen($dirRuntime . DIRECTORY_SEPARATOR . 'green-orquestrador-vigia.lock', 'c');
if ($trava === false || !flock($trava, LOCK_EX | LOCK_NB)) exit(0);

$env = GreenPortalDatabase::env();
try {
    $u = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
} catch (Throwable $e) { $log('ERRO conexão com o universal: ' . $e->getMessage()); fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
$call = static function (string $sql, array $p = []) use ($u): array {
    $st = $u->prepare($sql); $st->execute($p);
    $r = [];
    do { try { $x = $st->fetchAll(PDO::FETCH_ASSOC); if ($x && !$r) $r = $x; } catch (Throwable) {} } while ($st->nextRowset());
    $st->closeCursor();
    return $r;
};

$notas = [];
$resumo = ['tarefas' => [], 'empresas_suspensas' => 0, 'problemas' => 0, 'novos' => 0, 'lembretes' => 0, 'resolvidos' => 0, 'alertas' => 0];

// 1. Tarefas do orquestrador no Agendador do Windows
try {
    $tarefas = AgendadorWindows::situacao(array_keys(AgendadorWindows::TAREFAS_ORQUESTRADOR));
    foreach (AgendadorWindows::TAREFAS_ORQUESTRADOR as $nome => $trilha) {
        $t = $tarefas[$nome] ?? null;
        $estado = $t ? (string)$t['State'] : 'NAO_INSTALADO';
        $call('CALL sp_orq_tarefa_windows(?, ?, ?, ?, ?)', [$trilha, $estado, $t ? (int)$t['LastTaskResult'] : null, $t['LastRunTime'] ?? null, $t['NextRunTime'] ?? null]);
        $resumo['tarefas'][$nome] = $estado;
    }
} catch (Throwable $e) { $notas[] = 'Agendador: ' . $e->getMessage(); }

// 2. Empresas suspensas pelo disjuntor (WindowsServiceRuntime::disjuntor)
$atuais = [];      // chave => true (o que está aberto agora)
$enviar = [];      // [banco|null, título, mensagem]
$nomeEmpresa = static function (string $banco) use ($u): string {
    try { $s = $u->prepare('SELECT nome FROM empresa WHERE banco_dados = ? LIMIT 1'); $s->execute([$banco]); $n = (string)($s->fetchColumn() ?: ''); $s->closeCursor(); return $n !== '' ? $n : $banco; }
    catch (Throwable) { return $banco; }
};
foreach (glob($dirRuntime . DIRECTORY_SEPARATOR . 'disjuntor-*.json') ?: [] as $arq) {
    $servico = substr(basename($arq, '.json'), strlen('disjuntor-'));
    $dados = json_decode((string)@file_get_contents($arq), true);
    if (!is_array($dados)) continue;
    foreach ($dados as $banco => $item) {
        $ate = (int)($item['ate'] ?? 0);
        if ($ate <= time() || !preg_match('/^g[a-z0-9_]+_dbp$/i', (string)$banco)) continue;
        $resumo['empresas_suspensas']++;
        $ultimoErro = '';
        try {
            $pdo = GreenPortalDatabase::connect($env, (string)$banco, 'GPT');
            $s = $pdo->prepare("SELECT detalhes FROM green_service_runs WHERE servico = ? AND status = 'ERRO' ORDER BY inicio_em DESC LIMIT 1");
            $s->execute([$servico]);
            $det = json_decode((string)($s->fetchColumn() ?: ''), true);
            $ultimoErro = is_array($det) ? (string)($det['erro'] ?? '') : '';
            $s->closeCursor();
        } catch (Throwable) {}
        $empresa = $nomeEmpresa((string)$banco);
        $chave = substr('EMPRESA_' . strtoupper(preg_replace('/[^a-z0-9]/i', '_', $servico)) . '_' . strtoupper((string)$banco), 0, 120);
        $titulo = "Serviço $servico suspenso na empresa $empresa";
        $msg = "Deu erro 3 vezes seguidas nesta empresa e fica sem rodar nela até " . date('d/m/Y H:i', $ate) . '; nas outras empresas segue normal.'
            . ($ultimoErro !== '' ? ' Último erro: ' . mb_substr($ultimoErro, 0, 500) : '');
        $atuais[$chave] = true;
        try {
            $acao = (string)($call('CALL sp_orq_aviso_abrir(?, ?, ?, ?, ?, ?)', [$chave, 'EMPRESA', 'ERRO', (string)$banco, $titulo, $msg])[0]['acao'] ?? 'NADA');
            if ($acao === 'NOVO' || $acao === 'LEMBRAR') { $enviar[] = [(string)$banco, ($acao === 'LEMBRAR' ? 'Continua: ' : '') . $titulo, $msg]; $resumo[$acao === 'NOVO' ? 'novos' : 'lembretes']++; }
        } catch (Throwable $e) { $notas[] = "aviso $chave: " . $e->getMessage(); }
    }
}

// 3. Problemas atuais (trilhas, tarefas, serviços suspensos). Os de EMPRESA já foram tratados; o do vigia não se aplica a ele mesmo.
try {
    foreach ($call('CALL sp_orq_saude()') as $p) {
        if (in_array($p['tipo'], ['EMPRESA', 'VIGIA'], true)) continue;
        $resumo['problemas']++;
        $atuais[(string)$p['chave']] = true;
        $acao = (string)($call('CALL sp_orq_aviso_abrir(?, ?, ?, ?, ?, ?)', [$p['chave'], $p['tipo'], $p['gravidade'], null, $p['titulo'], $p['mensagem']])[0]['acao'] ?? 'NADA');
        if ($acao === 'NOVO' || $acao === 'LEMBRAR') { $enviar[] = [null, ($acao === 'LEMBRAR' ? 'Continua: ' : '') . $p['titulo'], (string)$p['mensagem']]; $resumo[$acao === 'NOVO' ? 'novos' : 'lembretes']++; }
    }
    $resumo['problemas'] += $resumo['empresas_suspensas'];
    // 4. O que estava aberto e não aparece mais foi resolvido
    foreach ($call('CALL sp_orq_aviso_resolver_ausentes(?)', [implode(',', array_keys($atuais))]) as $r) {
        $enviar[] = [$r['banco'] ?: null, 'Resolvido: ' . $r['titulo'], 'O problema aberto em ' . date('d/m/Y H:i', strtotime((string)$r['aberto_em'])) . ' foi resolvido; o vigia não o encontrou mais em ' . date('d/m/Y H:i') . '.'];
        $resumo['resolvidos']++;
    }
} catch (Throwable $e) { $notas[] = 'saúde: ' . $e->getMessage(); }

// 5. Alertas aos ADMINISTRADORES ativos (sino do portal; o serviço de E-mail manda por e-mail conforme a preferência de cada um)
if ($enviar) {
    try { $bancos = (new WindowsServiceRuntime($env, 'orquestrador-vigia'))->databases(); }
    catch (Throwable $e) { $bancos = []; $notas[] = 'empresas: ' . $e->getMessage(); }
    $porBanco = [];
    foreach ($enviar as [$banco, $titulo, $msg]) {
        foreach ($banco !== null ? [$banco] : $bancos as $b) $porBanco[$b][] = [$titulo, $msg];
    }
    foreach ($porBanco as $b => $itens) {
        // Vários problemas no mesmo ciclo (ex.: as 3 trilhas paradas) viram um alerta só, com a lista
        if (count($itens) > 1) {
            $itens = [[count($itens) . ' avisos: ' . $itens[0][0] . '…',
                implode('; ', array_map(static fn($i) => $i[0], $itens)) . '. Detalhes em Administração › Serviços.']];
        }
        $itens = array_map(static fn($i) => [mb_substr('Serviços — ' . $i[0], 0, 150), mb_substr($i[1], 0, 1000)], $itens);
        if (!preg_match('/^g[a-z0-9_]+_dbp$/i', (string)$b)) continue;
        try {
            $pdo = GreenPortalDatabase::connect($env, (string)$b, 'GPT');
            $adm = $pdo->query("SELECT u.codusuario FROM usuarios u JOIN perfis p ON p.codperfil = u.codperfil WHERE COALESCE(u.ativo, 0) = 1 AND UPPER(TRIM(p.codigo)) = 'ADMINISTRADOR'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($adm as $cod) {
                foreach ($itens as [$titulo, $msg]) {
                    $q = $pdo->prepare("CALL sp_alerta_criar(?, ?, ?, ?, 'NAO')");
                    $q->execute([(int)$cod, $titulo, $msg, '/principal/servicos.php']);
                    $q->closeCursor();
                    $resumo['alertas']++;
                }
            }
        } catch (Throwable $e) { $notas[] = "alertas $b: " . $e->getMessage(); }
    }
}

// 6. Sinal de vida do vigia
$texto = sprintf('%d problema(s); %d novo(s), %d lembrete(s), %d resolvido(s); %d alerta(s)', $resumo['problemas'], $resumo['novos'], $resumo['lembretes'], $resumo['resolvidos'], $resumo['alertas'])
    . ($notas ? ' | ' . implode(' | ', $notas) : '');
try { $call('CALL sp_orq_vigia_sinal(?)', [mb_substr($texto, 0, 500)]); } catch (Throwable $e) { $notas[] = 'sinal: ' . $e->getMessage(); }
$log($texto);

flock($trava, LOCK_UN);
fclose($trava);
if ($detalhes) echo json_encode(['resumo' => $resumo, 'notas' => $notas], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(0);
