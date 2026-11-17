<?php
declare(strict_types=1);

/*
 * Migração 200 — Ajuda da transação Administração › Guia do usuário.
 * Insere o texto de ajuda (PT/EN/ES) em guniversal_dbp.ajuda_transacoes.
 * Idempotente: não sobrescreve um texto já ajustado em Configurações › Ajuda das transações.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-200-ajuda-guia-usuario.php
 */

require_once __DIR__ . '/../classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$sql = file_get_contents(__DIR__ . '/../database/200_ajuda_administracao_guia_usuario.sql');
if ($sql === false) throw new RuntimeException('Script SQL da migração 200 não encontrado.');

$universal = GreenPortalDatabase::connect($env, $env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp', 'GUNIVERSAL');
$universal->exec($sql);

$row = $universal->query("SELECT ativo, CHAR_LENGTH(texto_pt) pt, CHAR_LENGTH(texto_en) en, CHAR_LENGTH(texto_es) es FROM ajuda_transacoes WHERE transacao = 'administracao_guia_usuario'")->fetch(PDO::FETCH_ASSOC);
$ok = $row && (int)$row['ativo'] === 1 && (int)$row['pt'] > 0 && (int)$row['en'] > 0 && (int)$row['es'] > 0;
echo json_encode(['ok' => $ok, 'migracao' => '200', 'ajuda' => $row ?: null], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($ok ? 0 : 1);
