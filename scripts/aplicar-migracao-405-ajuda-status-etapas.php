<?php
declare(strict_types=1);

/*
 * Migração 405 — Ajuda dos ícones de status e da barra de etapas (onda 1 da Revisão G, 24/11/2026).
 * Só o universal: em ajuda_transacoes, acrescenta (ou troca, ao rodar de novo) o trecho <!-- ml405 --> nas telas dos módulos
 * listadas em database/405_ajuda_status_etapas.php. Tela sem ajuda ganha uma com o título da lista e só este trecho.
 * Nada muda nas tabelas das empresas.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-405-ajuda-status-etapas.php [--simular]
 *      ... --remover   tira o trecho <!-- ml405 --> (as ajudas criadas só com ele ficam vazias e inativas)
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$remover = in_array('--remover', $args, true);
$env = GreenPortalDatabase::env();
$def = require dirname(__DIR__) . '/database/405_ajuda_status_etapas.php';
$rel = ['ok' => true, 'migracao' => '405', 'modo' => ($remover ? 'REMOVER' : 'APLICAR') . ($simular ? ' (SIMULACAO)' : ''), 'ajuda' => []];

try {
    $u = (new MenuCatalogoService($env))->universal();
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if (!MigracaoCrud::existe($u, 'ajuda_transacoes')) throw new RuntimeException('sem a tabela ajuda_transacoes no universal');
    foreach ($def['telas'] as $transacao => [$titulo, $extra]) {
        try {
            $atual = null;
            foreach (MigracaoCrud::selecionar($u, 'ajuda_transacoes', ['transacao' => $transacao]) as $r) if (strcasecmp((string)($r['transacao'] ?? ''), $transacao) === 0) { $atual = $r; break; }
            if ($atual === null && $remover) { $rel['ajuda'][$transacao] = 'sem ajuda'; continue; }
            $dados = [];
            $vazia = true;
            foreach (['pt', 'en', 'es'] as $lg) {
                $velho = trim((string)preg_replace('/\s*<!-- ml405 -->.*?<!-- \/ml405 -->/s', '', (string)($atual['texto_' . $lg] ?? '')));
                $dados['texto_' . $lg] = $remover ? $velho : ($velho === '' ? '' : $velho . "\n") . "<!-- ml405 -->\n" . $def['comum'][$lg] . $extra[$lg] . "\n<!-- /ml405 -->";
                if ($dados['texto_' . $lg] !== '') $vazia = false;
                if (!$remover && ($atual === null || trim((string)($atual['titulo_' . $lg] ?? '')) === '')) $dados['titulo_' . $lg] = $titulo[$lg];
            }
            if ($atual !== null) {
                $igual = true;
                foreach ($dados as $k => $v) if ((string)($atual[$k] ?? '') !== $v) $igual = false;
                if ($igual) { $rel['ajuda'][$transacao] = 'igual'; continue; }
            }
            $acao = $atual === null ? 'incluída' : ($remover ? 'trecho retirado' : 'atualizada');
            if ($simular) { $rel['ajuda'][$transacao] = 'será ' . $acao; continue; }
            if ($atual === null) MigracaoCrud::inserir($u, 'ajuda_transacoes', ['transacao' => $transacao] + $dados + ['ativo' => 1]);
            else {
                $chave = ['transacao' => (string)$atual['transacao']];
                foreach (['id', 'codajuda', 'codigo', 'codajudatransacao'] as $pk) if (isset($atual[$pk]) && $atual[$pk] !== '') { $chave = [$pk => $atual[$pk]]; break; }
                MigracaoCrud::atualizar($u, 'ajuda_transacoes', $chave, $dados + ($remover && $vazia ? ['ativo' => 0] : []));
            }
            $rel['ajuda'][$transacao] = $acao;
        } catch (Throwable $e) { $rel['ok'] = false; $rel['ajuda'][$transacao] = 'ERRO: ' . $e->getMessage(); }
    }
} catch (Throwable $e) { $rel['ok'] = false; $rel['erro'] = $e->getMessage(); }

$logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (is_dir($logDir)) @file_put_contents($logDir . DIRECTORY_SEPARATOR . 'migracao_405_ajuda_status_etapas' . ($simular ? '_simulacao' : '') . '.json', json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
