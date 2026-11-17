<?php
declare(strict_types=1);

/*
 * Migração 363 — Ajuda das telas Minhas atividades, Ações (Minhas atividades › Ações) e Histórico de alterações.
 * 1) Guia do usuário (guniversal_dbp, Administração › Guia do usuário), publicado como nova versão:
 *    - assunto "inicio" (Home e minhas atividades) de principal/guia_interativo_conteudo_inicio.php: manual (etapas,
 *      "Como fazer", menu, termos), guias passo a passo com "Mostre-me" e "Sobre esta tela". Se o assunto já existe, os guias
 *      (pelo id) e as telas (pela primeira chave) desta migração são atualizados; etapas, Como fazer, menu e termos só
 *      são preenchidos se estiverem vazios (o que foi editado no editor fica);
 *    - 362 (eficácia): "Sobre esta tela" do plano de ação da condicionante (assunto legal) e o passo do plano de ação no
 *      guia "Registrar auditoria e plano de ação" (assunto fornecedores) — só se o texto ainda for o original.
 *    - Se o rascunho tiver alterações não publicadas, para e avisa, a não ser com --publicar-pendente.
 * 2) ajuda_transacoes: minhas_atividades, minhas_acoes e historico_registro (database/363_ajuda_telas_home.php), entre
 *    <!-- ml363 --> e <!-- /ml363 -->; texto da empresa fora da marca é mantido.
 * Gravação só por procedures (sp_guia_usuario_*, sp_crud_*). Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-363-ajuda-home.php [--simular] [--publicar-pendente]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/GuiaUsuarioService.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$publicarPendente = in_array('--publicar-pendente', $args, true);
$env = GreenPortalDatabase::env();
$universal = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$universal->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$servico = new GuiaUsuarioService($universal);
if (!defined('GREEN_GUIA_USUARIO')) define('GREEN_GUIA_USUARIO', true);
$autor = ['nome' => 'Migração 363', 'codusuario' => 0, 'banco' => 'guniversal_dbp'];
$rel = ['ok' => true, 'migracao' => '363', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'guia' => [], 'ajuda_transacoes' => []];

// textos originais (antes da 362) que a migração pode trocar sem perder edição feita no editor
const ORIGINAL_PLANO_CONDICIONANTE = 'O plano de ação da condicionante: cada linha é uma ação com responsável, datas, recursos, status (0 a 100%) e evidências.';
const ORIGINAL_PASSO_PLANO_FORNECEDOR = 'Para cada falha, clique em **Plano de ação** e inclua a ação, o responsável e o prazo.';

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
    $antes = json_encode($doc, JSON_UNESCAPED_UNICODE);

    // 1a) assunto "inicio"
    $novo = $servico->normalizar(['perfis' => $doc['perfis'], 'modulos' => [(require dirname(__DIR__) . '/principal/guia_interativo_conteudo_inicio.php')['inicio']]])['modulos'][0];
    $idx = null;
    foreach ($doc['modulos'] as $i => $m) if ($m['id'] === 'inicio') $idx = $i;
    if ($idx === null) {
        if (count($doc['modulos']) >= 30) throw new RuntimeException('O Guia do usuário já tem 30 assuntos (limite); não há lugar para "Home e minhas atividades".');
        array_unshift($doc['modulos'], $novo);
        $rel['guia']['inicio'] = 'assunto incluído (' . count($novo['guias']) . ' guias, ' . count($novo['telas']) . ' telas, ' . count($novo['tarefas']) . ' Como fazer)';
    } else {
        $m = &$doc['modulos'][$idx];
        $feito = [];
        foreach (['fases', 'tarefas', 'menu', 'termos'] as $campo) if (!$m[$campo] && $novo[$campo]) { $m[$campo] = $novo[$campo]; $feito[] = $campo . ' preenchido'; }
        foreach ($novo['guias'] as $g) {
            $achou = false;
            foreach ($m['guias'] as $k => $atual) if ($atual['id'] === $g['id']) { $m['guias'][$k] = $g; $achou = true; }
            if (!$achou) $m['guias'][] = $g;
        }
        foreach ($novo['telas'] as $t) {
            $achou = false;
            foreach ($m['telas'] as $k => $atual) if (($atual['chaves'][0] ?? '') === $t['chaves'][0]) { $m['telas'][$k] = $t; $achou = true; }
            if (!$achou) $m['telas'][] = $t;
        }
        unset($m);
        $rel['guia']['inicio'] = 'assunto atualizado (guias e telas da 363' . ($feito ? '; ' . implode(', ', $feito) : '') . ')';
    }

    // 1b) 362: plano de ação da condicionante (legal) e passo do plano no guia de auditoria (fornecedores)
    $legal = (require dirname(__DIR__) . '/principal/guia_interativo_conteudo.php')['legal'] ?? [];
    $telaPlano = null;
    foreach ($legal['telas'] ?? [] as $t) if (in_array('liPlanodeAcao', $t['chaves'], true)) $telaPlano = $t;
    $rel['guia']['plano_condicionante'] = 'assunto legal ou tela não encontrados';
    foreach ($doc['modulos'] as &$m) {
        if ($m['id'] !== 'legal' || !$telaPlano) continue;
        foreach ($m['telas'] as &$t) {
            if (!in_array('liPlanodeAcao', $t['chaves'], true)) continue;
            if ($t['oque']['pt'] === ORIGINAL_PLANO_CONDICIONANTE) { $t['oque'] = $telaPlano['oque']; $rel['guia']['plano_condicionante'] = 'atualizado (avaliação da eficácia)'; }
            elseif (str_contains($t['oque']['pt'], 'eficácia')) $rel['guia']['plano_condicionante'] = 'já tinha a eficácia';
            else $rel['guia']['plano_condicionante'] = 'mantido (texto editado no Guia do usuário; inclua a eficácia à mão)';
        }
        unset($t);
    }
    unset($m);
    $forn = (require dirname(__DIR__) . '/principal/guia_interativo_conteudo_fornecedores.php')['fornecedores'] ?? [];
    $passoNovo = null;
    foreach ($forn['guias'] ?? [] as $g) if ($g['id'] === 'forn-auditoria-plano') foreach ($g['passos'] as $p) if (str_starts_with($p['texto']['pt'], 'Para cada falha, clique em **Plano de ação**')) $passoNovo = $p['texto'];
    $rel['guia']['plano_fornecedor'] = 'guia "forn-auditoria-plano" não encontrado';
    foreach ($doc['modulos'] as &$m) {
        if ($m['id'] !== 'fornecedores' || !$passoNovo) continue;
        foreach ($m['guias'] as &$g) {
            if ($g['id'] !== 'forn-auditoria-plano') continue;
            $rel['guia']['plano_fornecedor'] = 'passo não encontrado (guia editado)';
            foreach ($g['passos'] as &$p) {
                if ($p['texto']['pt'] === ORIGINAL_PASSO_PLANO_FORNECEDOR) { $p['texto'] = $passoNovo; $rel['guia']['plano_fornecedor'] = 'atualizado (avaliação da eficácia)'; }
                elseif (str_starts_with($p['texto']['pt'], 'Para cada falha') && str_contains($p['texto']['pt'], 'eficácia')) $rel['guia']['plano_fornecedor'] = 'já tinha a eficácia';
            }
            unset($p);
        }
        unset($g);
    }
    unset($m);

    $doc = $servico->normalizar($doc);
    $servico->validarParaPublicacao($doc);
    $mudou = json_encode($doc, JSON_UNESCAPED_UNICODE) !== $antes;
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
        $pub = $servico->publicar($revisao, 'Migração 363: ajuda de Minhas atividades, Ações e Histórico de alterações; eficácia nos planos de ação (362)', $autor);
        $rel['guia']['acao'] = 'publicada a versão ' . $pub['numero'];
    }
} catch (Throwable $e) {
    $rel['ok'] = false;
    $rel['guia']['erro'] = $e->getMessage();
}

// ---------------------------------------------------------------- 2) ajuda_transacoes
try {
    $textos = require dirname(__DIR__) . '/database/363_ajuda_telas_home.php';
    if (!MigracaoCrud::existe($universal, 'ajuda_transacoes')) throw new RuntimeException('sem a tabela ajuda_transacoes no universal');
    foreach ($textos as $transacao => $d) {
        // busca exata (o filtro de texto do MigracaoCrud é "contém")
        $atual = null;
        foreach (MigracaoCrud::selecionar($universal, 'ajuda_transacoes', ['transacao' => $transacao]) as $r) if (strcasecmp((string)($r['transacao'] ?? ''), $transacao) === 0) { $atual = $r; break; }
        $dados = [];
        foreach (['pt', 'en', 'es'] as $lg) {
            $velho = trim((string)preg_replace('/\s*<!-- ml363 -->.*?<!-- \/ml363 -->/s', '', (string)($atual['texto_' . $lg] ?? '')));
            $dados['texto_' . $lg] = ($velho === '' ? '' : $velho . "\n") . "<!-- ml363 -->\n" . $d['texto'][$lg] . "\n<!-- /ml363 -->";
            if ($atual === null || trim((string)($atual['titulo_' . $lg] ?? '')) === '') $dados['titulo_' . $lg] = $d['titulo'][$lg];
        }
        if ($atual !== null) {
            $igual = (int)($atual['ativo'] ?? 1) === 1;
            foreach ($dados as $k => $v) if ((string)($atual[$k] ?? '') !== $v) $igual = false;
            if ($igual) { $rel['ajuda_transacoes'][$transacao] = 'igual'; continue; }
        }
        if ($simular) { $rel['ajuda_transacoes'][$transacao] = $atual === null ? 'será incluída' : 'será atualizada'; continue; }
        if ($atual === null) {
            MigracaoCrud::inserir($universal, 'ajuda_transacoes', ['transacao' => $transacao] + $dados + ['ativo' => 1]);
            $rel['ajuda_transacoes'][$transacao] = 'incluída';
        } else {
            $chave = ['transacao' => (string)$atual['transacao']];
            foreach (['id', 'codajuda', 'codigo', 'codajudatransacao'] as $pk) if (isset($atual[$pk]) && $atual[$pk] !== '') { $chave = [$pk => $atual[$pk]]; break; }
            MigracaoCrud::atualizar($universal, 'ajuda_transacoes', $chave, $dados + ['ativo' => 1]);
            $rel['ajuda_transacoes'][$transacao] = 'atualizada';
        }
    }
} catch (Throwable $e) {
    $rel['ok'] = false;
    $rel['ajuda_transacoes']['erro'] = $e->getMessage();
}

echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
