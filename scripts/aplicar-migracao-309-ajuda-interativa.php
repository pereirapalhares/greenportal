<?php
declare(strict_types=1);

/*
 * Migração 309 — Ajuda interativa (botão ? / F1, modo guiado "Mostre-me").
 * Junta ao Guia do usuário (guniversal_dbp, Administração › Guia do usuário) os guias passo a passo e os textos
 * "Sobre esta tela" do Módulo Legal / Manutenção Legal (principal/guia_interativo_conteudo.php) e publica uma nova versão.
 * - Só preenche guias/telas do módulo que ainda não tem nenhum (não sobrescreve o que o administrador editou),
 *   a não ser com --substituir.
 * - Se o rascunho tiver alterações não publicadas, para e avisa (para não publicar o trabalho de outra pessoa),
 *   a não ser com --publicar-pendente.
 * Gravação só pelas procedures sp_guia_usuario_* (GuiaUsuarioService). Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-309-ajuda-interativa.php [--simular] [--substituir] [--publicar-pendente]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/GuiaUsuarioService.php';

$simular = in_array('--simular', $argv ?? [], true);
$substituir = in_array('--substituir', $argv ?? [], true);
$publicarPendente = in_array('--publicar-pendente', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$universal = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$universal->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$servico = new GuiaUsuarioService($universal);
define('GREEN_GUIA_USUARIO', true);
$carga = require dirname(__DIR__) . '/principal/guia_interativo_conteudo.php';
$autor = ['nome' => 'Migração 309', 'codusuario' => 0, 'banco' => 'guniversal_dbp'];
$rel = ['ok' => true, 'migracao' => '309', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'modulos' => []];

try {
    $rascunho = $servico->rascunho();
    if (!$rascunho) {
        // sem rascunho: parte do conteúdo do arquivo (carga inicial do guia)
        $base = require dirname(__DIR__) . '/principal/guia_usuario_conteudo.php';
        $doc = ['perfis' => $base['perfis'], 'modulos' => $base['modulos']];
        $revisao = 0;
        $rel['base'] = 'arquivo guia_usuario_conteudo.php (sem rascunho no banco)';
    } else {
        if ($rascunho['pendente'] && !$publicarPendente) throw new RuntimeException('O rascunho do Guia do usuário tem alterações não publicadas (' . $rascunho['alterado_por'] . ', ' . $rascunho['alterado_em'] . '). Publique ou descarte em Administração › Guia do usuário, ou rode com --publicar-pendente.');
        $doc = $rascunho['conteudo'];
        $revisao = $rascunho['revisao'];
        $rel['base'] = 'rascunho revisão ' . $revisao . ($rascunho['publicado'] ? ' (publicada a versão ' . $rascunho['publicado']['numero'] . ')' : '');
    }
    $doc = $servico->normalizar($doc);
    $mudou = false;
    foreach ($carga as $idModulo => $conteudo) {
        $achou = false;
        foreach ($doc['modulos'] as &$modulo) {
            if ($modulo['id'] !== $idModulo) continue;
            $achou = true;
            foreach (['guias', 'telas'] as $campo) {
                $atual = count($modulo[$campo] ?? []);
                if ($atual > 0 && !$substituir) { $rel['modulos'][$idModulo][$campo] = "mantido ($atual já cadastrados)"; continue; }
                $modulo[$campo] = $conteudo[$campo] ?? [];
                $rel['modulos'][$idModulo][$campo] = count($modulo[$campo]) . ($atual ? " (substituiu $atual)" : ' incluído(s)');
                $mudou = true;
            }
        }
        unset($modulo);
        if (!$achou) $rel['modulos'][$idModulo] = 'módulo não existe no guia (não incluído)';
    }
    $doc = $servico->normalizar($doc);
    $servico->validarParaPublicacao($doc);
    $legal = array_values(array_filter($doc['modulos'], static fn(array $m): bool => $m['id'] === 'legal'))[0] ?? null;
    if ($legal) $rel['resultado_legal'] = count($legal['guias']) . ' guias, ' . array_sum(array_map(static fn(array $g): int => count($g['passos']), $legal['guias'])) . ' passos, ' . count($legal['telas']) . ' telas';
    if (!$mudou) $rel['acao'] = 'nada a fazer';
    elseif ($simular) $rel['acao'] = 'será salvo no rascunho e publicado como nova versão';
    else {
        if (!$rascunho) {
            $servico->cargaInicial($doc, $autor);
            $rel['acao'] = 'carga inicial gravada';
            $rascunho = $servico->rascunho();
            $revisao = (int)($rascunho['revisao'] ?? 0);
        } else {
            $revisao = $servico->salvarRascunho($doc, $revisao, $autor);
        }
        if (!($rel['acao'] ?? '') || ($rascunho && $rascunho['pendente'])) {
            $pub = $servico->publicar($revisao, 'Migração 309: ajuda interativa (guias passo a passo e Sobre esta tela) do Módulo Legal', $autor);
            $rel['acao'] = 'publicada a versão ' . $pub['numero'];
        }
    }
} catch (Throwable $e) {
    $rel['ok'] = false;
    $rel['erro'] = $e->getMessage();
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
