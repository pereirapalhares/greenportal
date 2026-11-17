<?php
declare(strict_types=1);

/*
 * Migração 357 — Ajuda inteligente de Homologação de Fornecedores (botão ? / F1, "Mostre-me").
 * 1) Guia do usuário (guniversal_dbp): junta ao assunto "fornecedores" os guias passo a passo e os "Sobre esta tela"
 *    de principal/guia_interativo_conteudo_fornecedores.php e publica uma nova versão.
 *    - Só preenche guias/telas vazios (não sobrescreve o que foi editado no editor), a não ser com --substituir.
 *    - Se o rascunho tiver alterações não publicadas, para e avisa, a não ser com --publicar-pendente.
 * 2) ajuda_transacoes: textos das telas que ainda não têm (database/357_ajuda_fornecedores.php). Texto já escrito pela
 *    empresa (sem a marca <!-- forn357 -->) é mantido.
 * Gravação só por procedures (sp_guia_usuario_*, sp_crud_*). Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-357-ajuda-fornecedores.php [--simular] [--substituir] [--publicar-pendente]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/GuiaUsuarioService.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$substituir = in_array('--substituir', $args, true);
$publicarPendente = in_array('--publicar-pendente', $args, true);
$env = GreenPortalDatabase::env();
$universal = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$universal->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$servico = new GuiaUsuarioService($universal);
if (!defined('GREEN_GUIA_USUARIO')) define('GREEN_GUIA_USUARIO', true);
$carga = require dirname(__DIR__) . '/principal/guia_interativo_conteudo_fornecedores.php';
$autor = ['nome' => 'Migração 357', 'codusuario' => 0, 'banco' => 'guniversal_dbp'];
$rel = ['ok' => true, 'migracao' => '357', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'guia' => [], 'ajuda_transacoes' => []];

// ---------------------------------------------------------------- 1) Guia do usuário
try {
    $rascunho = $servico->rascunho();
    if (!$rascunho) {
        $base = require dirname(__DIR__) . '/principal/guia_usuario_conteudo.php';
        $doc = ['perfis' => $base['perfis'], 'modulos' => $base['modulos']];
        $revisao = 0;
        $rel['guia']['base'] = 'arquivo guia_usuario_conteudo.php (sem rascunho no banco)';
    } else {
        if ($rascunho['pendente'] && !$publicarPendente) throw new RuntimeException('O rascunho do Guia do usuário tem alterações não publicadas (' . $rascunho['alterado_por'] . ', ' . $rascunho['alterado_em'] . '). Publique ou descarte em Administração › Guia do usuário, ou rode com --publicar-pendente.');
        $doc = $rascunho['conteudo'];
        $revisao = (int)$rascunho['revisao'];
        $rel['guia']['base'] = 'rascunho revisão ' . $revisao . ($rascunho['publicado'] ? ' (publicada a versão ' . $rascunho['publicado']['numero'] . ')' : '');
    }
    $doc = $servico->normalizar($doc);
    $mudou = false;
    $idModulo = 'fornecedores';
    $novo = $carga[$idModulo];
    $achou = false;
    foreach ($doc['modulos'] as &$modulo) {
        if ($modulo['id'] !== $idModulo) continue;
        $achou = true;
        foreach (['guias', 'telas'] as $campo) {
            $atual = count($modulo[$campo] ?? []);
            if ($atual > 0 && !$substituir) { $rel['guia'][$campo] = "mantido ($atual já cadastrados)"; continue; }
            $modulo[$campo] = $novo[$campo];
            $rel['guia'][$campo] = count($novo[$campo]) . ($atual ? " (substituiu $atual)" : ' incluído(s)');
            $mudou = true;
        }
    }
    unset($modulo);
    if (!$achou) throw new RuntimeException('O Guia do usuário não tem o assunto "fornecedores" (Homologação de Fornecedores).');
    // textos maiores que o limite do guia seriam cortados pela normalização: avisa antes de gravar
    $antes = $novo;
    $doc = $servico->normalizar($doc);
    $depois = array_values(array_filter($doc['modulos'], static fn(array $m): bool => $m['id'] === $idModulo))[0] ?? null;
    $cortes = [];
    if ($depois && $mudou) {
        if (!str_starts_with((string)($rel['guia']['guias'] ?? 'mantido'), 'mantido')) foreach ($antes['guias'] as $i => $g) foreach ($g['passos'] as $j => $p) foreach (['texto', 'ver'] as $k) foreach (['pt', 'en', 'es'] as $lg)
            if (mb_strlen((string)($p[$k][$lg] ?? '')) !== mb_strlen((string)($depois['guias'][$i]['passos'][$j][$k][$lg] ?? ''))) $cortes[] = $g['id'] . " passo " . ($j + 1) . " $k/$lg";
        if (!str_starts_with((string)($rel['guia']['telas'] ?? 'mantido'), 'mantido')) foreach ($antes['telas'] as $i => $t) foreach (['oque', 'antes', 'depois'] as $k) foreach (['pt', 'en', 'es'] as $lg)
            if (mb_strlen((string)($t[$k][$lg] ?? '')) !== mb_strlen((string)($depois['telas'][$i][$k][$lg] ?? ''))) $cortes[] = 'tela ' . ($t['chaves'][0] ?? $i) . " $k/$lg";
    }
    if ($cortes) $rel['guia']['aviso_textos_cortados'] = $cortes;
    $servico->validarParaPublicacao($doc);

    if (!$mudou) $rel['guia']['acao'] = 'nada a fazer';
    elseif ($simular) $rel['guia']['acao'] = 'será salvo no rascunho e publicado como nova versão';
    else {
        if (!$rascunho) {
            $servico->cargaInicial($doc, $autor);
            $r = $servico->rascunho();
            $revisao = (int)($r['revisao'] ?? 0);
        } else {
            $revisao = $servico->salvarRascunho($doc, $revisao, $autor);
        }
        $pub = $servico->publicar($revisao, 'Migração 357: ajuda inteligente de Homologação de Fornecedores (guias passo a passo e Sobre esta tela)', $autor);
        $rel['guia']['acao'] = 'publicada a versão ' . $pub['numero'];
    }
} catch (Throwable $e) {
    $rel['ok'] = false;
    $rel['guia']['erro'] = $e->getMessage();
}

// ---------------------------------------------------------------- 2) ajuda_transacoes
try {
    $textos = require dirname(__DIR__) . '/database/357_ajuda_fornecedores.php';
    if (!MigracaoCrud::existe($universal, 'ajuda_transacoes')) throw new RuntimeException('sem a tabela ajuda_transacoes no universal');
    $cont = ['incluida' => 0, 'atualizada' => 0, 'igual' => 0, 'mantida' => 0];
    foreach ($textos as $transacao => $d) {
        // busca exata (o filtro de texto do MigracaoCrud é "contém")
        $atual = null;
        foreach (MigracaoCrud::selecionar($universal, 'ajuda_transacoes', ['transacao' => $transacao]) as $r) if (strcasecmp((string)($r['transacao'] ?? ''), $transacao) === 0) { $atual = $r; break; }
        $escrito = $atual !== null && trim(strip_tags((string)($atual['texto_pt'] ?? ''))) !== '';
        $nosso = $atual !== null && str_contains((string)($atual['texto_pt'] ?? ''), '<!-- forn357 -->');
        if ($escrito && !$nosso && !$substituir) { $rel['ajuda_transacoes'][$transacao] = 'mantida (texto da empresa)'; $cont['mantida']++; continue; }
        $dados = [];
        foreach (['pt', 'en', 'es'] as $lg) {
            $dados['titulo_' . $lg] = $d['titulo'][$lg];
            $dados['texto_' . $lg] = "<!-- forn357 -->\n" . $d['texto'][$lg];
        }
        if ($atual !== null) {
            $igual = true;
            foreach ($dados as $k => $v) if ((string)($atual[$k] ?? '') !== $v) $igual = false;
            if ($igual && (int)($atual['ativo'] ?? 1) === 1) { $cont['igual']++; continue; }
        }
        $acao = $atual === null ? 'incluida' : 'atualizada';
        $cont[$acao]++;
        if ($simular) continue;
        if ($atual === null) MigracaoCrud::inserir($universal, 'ajuda_transacoes', ['transacao' => $transacao] + $dados + ['ativo' => 1]);
        else {
            // atualiza pela chave primária da linha quando houver (evita o "contém" do filtro por texto)
            $chave = ['transacao' => (string)$atual['transacao']];
            foreach (['id', 'codajuda', 'codigo', 'codajudatransacao'] as $pk) if (isset($atual[$pk]) && $atual[$pk] !== '') { $chave = [$pk => $atual[$pk]]; break; }
            MigracaoCrud::atualizar($universal, 'ajuda_transacoes', $chave, $dados + ['ativo' => 1]);
        }
    }
    $rel['ajuda_transacoes']['resumo'] = count($textos) . ' textos: ' . $cont['incluida'] . ($simular ? ' seriam incluídos, ' : ' incluídos, ') . $cont['atualizada'] . ($simular ? ' seriam atualizados, ' : ' atualizados, ') . $cont['igual'] . ' iguais, ' . $cont['mantida'] . ' mantidos (texto da empresa)';
} catch (Throwable $e) {
    $rel['ok'] = false;
    $rel['ajuda_transacoes']['erro'] = $e->getMessage();
}

echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
