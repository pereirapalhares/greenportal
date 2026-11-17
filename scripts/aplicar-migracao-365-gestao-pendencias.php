<?php
declare(strict_types=1);

/*
 * Migração 365 — Gestão de pendências (GER-18.1 deletar, 18.2 não gerar, 18.3 limpar pendências, com log).
 * Decisões do usuário (16/11/2026): ADM (todos) e gestores (a equipe); ocultar volta se a situação (o prazo) mudar;
 * "não gerar" por registro e por usuário (data de volta opcional); motivo obrigatório e aviso ao responsável.
 *
 * Cada empresa: database/365_gestao_pendencias.sql (pendencias_gestao, pendencias_gestao_log e as views).
 * Universal: Ajuda da tela (ajuda_transacoes gestao_pendencias, trecho <!-- ml365 -->) e, no Guia do usuário (assunto
 * "inicio"), os guias e o "Sobre esta tela" de principal/guia_gestao_pendencias.php (nova versão publicada).
 * Telas e classes (já na pasta): principal/gestao_pendencias.php (aba da Home), classes/PendenciasGestao.php,
 * classes/MinhasPendencias.php e classes/PainelLateralService.php (filtro), principal/home.php (aba).
 * Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-365-gestao-pendencias.php [--simular] [--banco=gcrown_dbp] [--publicar-pendente]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/GuiaUsuarioService.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$publicarPendente = in_array('--publicar-pendente', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$rel = ['ok' => true, 'migracao' => '365', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'universal' => [], 'bancos' => []];
if (!defined('GREEN_GUIA_USUARIO')) define('GREEN_GUIA_USUARIO', true);

// ---------------------------------------------------------------- 1) empresas
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'usuarios') AND TABLE_SCHEMA LIKE 'g%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 2 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp')
    && $b !== 'glegislacao_dbp' && ($soBanco === null || $b === $soBanco)));
if (!$bancos) $rel['bancos'] = ['aviso' => 'nenhuma empresa encontrada' . ($soBanco ? " ($soBanco)" : '')];
$sql = (string)file_get_contents(dirname(__DIR__) . '/database/365_gestao_pendencias.sql');
$blocos = array_values(array_filter(array_map(static fn($b) => trim((string)preg_replace('/^--(?! @@).*$/m', '', $b)), preg_split('/^-- @@\s*$/m', $sql)), static fn($b) => $b !== ''));

foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tem = static function (string $t) use ($pdo): bool { $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'); $s->execute([$t]); $n = (int)$s->fetchColumn(); $s->closeCursor(); return $n > 0; };
        $info['antes'] = $tem('pendencias_gestao') ? 'tabelas já existiam' : 'novo';
        if ($simular) { $info['acao'] = count($blocos) . ' bloco(s) seriam executados'; $rel['bancos'][$banco] = $info; continue; }
        foreach ($blocos as $b) $pdo->exec($b);
        $c = $pdo->query("SELECT (SELECT COUNT(*) FROM pendencias_gestao WHERE ATIVO = 1) a, (SELECT COUNT(*) FROM pendencias_gestao_log) l")->fetch(PDO::FETCH_ASSOC);
        $info['acao'] = count($blocos) . ' bloco(s): tabelas pendencias_gestao e pendencias_gestao_log, views vw_pendencias_gestao_ativas, _grid e _log';
        $info['conferencia'] = (int)$c['a'] . ' regra(s) em vigor, ' . (int)$c['l'] . ' registro(s) no log';
    } catch (Throwable $e) {
        $rel['ok'] = false;
        $info['erro'] = $e->getMessage();
    }
    $rel['bancos'][$banco] = $info;
}

// ---------------------------------------------------------------- 2) universal: Ajuda da tela
try {
    $u = GreenPortalDatabase::connect($env, $bancoUniversal, 'GUNIVERSAL');
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if (!MigracaoCrud::existe($u, 'ajuda_transacoes')) throw new RuntimeException('sem a tabela ajuda_transacoes no universal');
    foreach (require dirname(__DIR__) . '/database/365_ajuda_gestao_pendencias.php' as $transacao => $d) {
        $atual = null;
        foreach (MigracaoCrud::selecionar($u, 'ajuda_transacoes', ['transacao' => $transacao]) as $r) if (strcasecmp((string)($r['transacao'] ?? ''), $transacao) === 0) { $atual = $r; break; }
        $dados = [];
        foreach (['pt', 'en', 'es'] as $lg) {
            $velho = trim((string)preg_replace('/\s*<!-- ml365 -->.*?<!-- \/ml365 -->/s', '', (string)($atual['texto_' . $lg] ?? '')));
            $dados['texto_' . $lg] = ($velho === '' ? '' : $velho . "\n") . "<!-- ml365 -->\n" . $d['texto'][$lg] . "\n<!-- /ml365 -->";
            if ($atual === null || trim((string)($atual['titulo_' . $lg] ?? '')) === '') $dados['titulo_' . $lg] = $d['titulo'][$lg];
        }
        if ($atual !== null) {
            $igual = (int)($atual['ativo'] ?? 1) === 1;
            foreach ($dados as $k => $v) if ((string)($atual[$k] ?? '') !== $v) $igual = false;
            if ($igual) { $rel['universal']['ajuda'][$transacao] = 'igual'; continue; }
        }
        if ($simular) { $rel['universal']['ajuda'][$transacao] = $atual === null ? 'será incluída' : 'será atualizada'; continue; }
        if ($atual === null) MigracaoCrud::inserir($u, 'ajuda_transacoes', ['transacao' => $transacao] + $dados + ['ativo' => 1]);
        else {
            $chave = ['transacao' => (string)$atual['transacao']];
            foreach (['id', 'codajuda', 'codigo', 'codajudatransacao'] as $pk) if (isset($atual[$pk]) && $atual[$pk] !== '') { $chave = [$pk => $atual[$pk]]; break; }
            MigracaoCrud::atualizar($u, 'ajuda_transacoes', $chave, $dados + ['ativo' => 1]);
        }
        $rel['universal']['ajuda'][$transacao] = $atual === null ? 'incluída' : 'atualizada';
    }

    // ------------------------------------------------------------ 3) universal: Guia do usuário (F1)
    $servico = new GuiaUsuarioService($u);
    $rascunho = $servico->rascunho();
    if (!$rascunho) throw new RuntimeException('O Guia do usuário ainda não tem rascunho no banco (rode antes a 363).');
    if ($rascunho['pendente'] && !$publicarPendente) throw new RuntimeException('O rascunho do Guia do usuário tem alterações não publicadas (' . $rascunho['alterado_por'] . ', ' . $rascunho['alterado_em'] . '). Publique ou descarte em Administração › Guia do usuário, ou rode com --publicar-pendente.');
    $doc = $servico->normalizar($rascunho['conteudo']);
    $antes = json_encode($doc, JSON_UNESCAPED_UNICODE);
    foreach (require dirname(__DIR__) . '/principal/guia_gestao_pendencias.php' as $idModulo => $carga) {
        $idx = null;
        foreach ($doc['modulos'] as $i => $m) if ($m['id'] === $idModulo) $idx = $i;
        if ($idx === null) throw new RuntimeException('O Guia do usuário não tem o assunto "' . $idModulo . '" (rode antes a 363).');
        $novo = $servico->normalizar(['perfis' => $doc['perfis'], 'modulos' => [['id' => $idModulo, 'nome' => 'x', 'guias' => $carga['guias'], 'telas' => $carga['telas']]]])['modulos'][0];
        foreach ($novo['guias'] as $g) {
            $achou = false;
            foreach ($doc['modulos'][$idx]['guias'] as $k => $a) if ($a['id'] === $g['id']) { $doc['modulos'][$idx]['guias'][$k] = $g; $achou = true; }
            if (!$achou) $doc['modulos'][$idx]['guias'][] = $g;
            $rel['universal']['guia'][$g['id']] = $achou ? 'atualizado' : 'incluído';
        }
        foreach ($novo['telas'] as $tl) {
            $achou = false;
            foreach ($doc['modulos'][$idx]['telas'] as $k => $a) if (($a['chaves'][0] ?? '') === $tl['chaves'][0]) { $doc['modulos'][$idx]['telas'][$k] = $tl; $achou = true; }
            if (!$achou) $doc['modulos'][$idx]['telas'][] = $tl;
            $rel['universal']['guia']['tela ' . $tl['chaves'][0]] = $achou ? 'atualizada' : 'incluída';
        }
    }
    $doc = $servico->normalizar($doc);
    $servico->validarParaPublicacao($doc);
    if (json_encode($doc, JSON_UNESCAPED_UNICODE) === $antes) $rel['universal']['guia']['acao'] = 'nada a fazer';
    elseif ($simular) $rel['universal']['guia']['acao'] = 'será salvo no rascunho e publicado como nova versão';
    else {
        $autor = ['nome' => 'Migração 365', 'codusuario' => 0, 'banco' => $bancoUniversal];
        $rev = $servico->salvarRascunho($doc, (int)$rascunho['revisao'], $autor);
        $pub = $servico->publicar($rev, 'Migração 365: ajuda da Gestão de pendências', $autor);
        $rel['universal']['guia']['acao'] = 'publicada a versão ' . $pub['numero'];
    }
} catch (Throwable $e) {
    $rel['ok'] = false;
    $rel['universal']['erro'] = $e->getMessage();
}

$logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (is_dir($logDir)) @file_put_contents($logDir . DIRECTORY_SEPARATOR . 'migracao_365_gestao_pendencias' . ($simular ? '_simulacao' : '') . '.json', json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
