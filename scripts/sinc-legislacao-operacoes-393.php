<?php
declare(strict_types=1);

/*
 * Migração 393 — Sincronismo da legislação central por OPERAÇÕES (classes/SincronismoLegislacaoOperacoes.php).
 * Roda SÓ a parte da legislação central (as empresas continuam no sincronismo diário de sempre).
 *   sem --aplicar : simulação (mostra o que viria do legado; nada é gravado)
 *   --aplicar     : grava. Na 1ª vez só cria a linha de base (estado atual do legado) e não muda nada no greenportal;
 *                   depois, traz só o que mudou no legado desde a execução anterior.
 * O sincronismo diário (sincronizar-fo-legado.php) já usa este modo (SINC_LEGISLACAO_MODO = 'operacoes').
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\sinc-legislacao-operacoes-393.php [--aplicar]
 */

date_default_timezone_set('America/Sao_Paulo');
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/SincronismoLegislacaoOperacoes.php';
@set_time_limit(0);

$aplicar = in_array('--aplicar', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$pdo = new PDO('mysql:host=' . ($env['GPT_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['GPT_DB_PORT'] ?? '3306') . ';charset=utf8mb4', $env['GPT_DB_USERNAME'] ?? '', $env['GPT_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
$limite = 500;
try { $limite = (int)($pdo->query('SELECT limite_exclusoes FROM guniversal_dbp.sincronismo_config WHERE codconfig = 1')->fetchColumn() ?: 500); } catch (Throwable) {}
$t0 = microtime(true);
$r = ['modo_execucao' => $aplicar ? 'APLICAR' : 'SIMULACAO (nada foi gravado)', 'inicio' => date('Y-m-d H:i:s')] + SincronismoLegislacaoOperacoes::executar($pdo, $aplicar, $limite);
$r['segundos'] = round(microtime(true) - $t0, 1);
$json = json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . '/storage/logs/sincronismo';
if (is_dir($dir) || @mkdir($dir, 0775, true)) @file_put_contents($dir . '/legislacao_operacoes_' . date('Ymd_His') . ($aplicar ? '' : '_simulacao') . '.json', $json);
echo $json, PHP_EOL;
exit(isset($r['erro']) ? 1 : 0);
