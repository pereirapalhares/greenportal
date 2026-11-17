<?php
declare(strict_types=1);

/*
 * Migração 235 — ajuda das telas mais usadas (botão "?" e base do assistente "Como posso ajudar?").
 * Textos em database/235_ajuda_telas_principais.php (pt, en, es), gravados em guniversal_dbp.ajuda_transacoes.
 *
 * Segurança:
 *  - Antes de alterar, a ajuda atual vai para ajuda_transacoes_backup_235 (uma cópia por tela, a primeira).
 *  - Ajuda com 400 caracteres ou mais em português é mantida (pode ter sido escrita à mão em
 *    Configurações › Ajuda das transações). Use --forcar para substituir mesmo assim.
 *  - --simular mostra o que seria feito, sem gravar.
 * Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-235-ajuda-telas.php [--simular] [--forcar]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$simular = in_array('--simular', $argv ?? [], true);
$forcar = in_array('--forcar', $argv ?? [], true);
$ajudas = require dirname(__DIR__) . '/database/235_ajuda_telas_principais.php';
$env = GreenPortalDatabase::env();
$universal = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$simples = static fn(?string $html): string => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

if (!$simular) {
    $universal->exec('CREATE TABLE IF NOT EXISTS ajuda_transacoes_backup_235 LIKE ajuda_transacoes');
}
$consulta = $universal->prepare('SELECT * FROM ajuda_transacoes WHERE transacao = ?');
$resultado = ['ok' => true, 'migracao' => '235', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'telas' => []];
foreach ($ajudas as $transacao => $a) {
    $consulta->execute([$transacao]);
    $atual = $consulta->fetch(PDO::FETCH_ASSOC);
    $tamanhoAtual = $atual ? mb_strlen($simples($atual['texto_pt'])) : 0;
    try {
        if (!$atual) {
            $acao = 'INCLUIDA';
            if (!$simular) {
                $universal->prepare('INSERT INTO ajuda_transacoes (transacao, titulo_pt, titulo_en, titulo_es, texto_pt, texto_en, texto_es, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, 1)')
                    ->execute([$transacao, $a['titulo']['pt'], $a['titulo']['en'], $a['titulo']['es'], $a['texto']['pt'], $a['texto']['en'], $a['texto']['es']]);
            }
        } elseif ($tamanhoAtual >= 400 && !$forcar) {
            $acao = 'MANTIDA (ajuda atual com ' . $tamanhoAtual . ' caracteres; use --forcar para substituir)';
        } else {
            $acao = 'ATUALIZADA (antes ' . $tamanhoAtual . ' caracteres)';
            if (!$simular) {
                $universal->prepare('INSERT IGNORE INTO ajuda_transacoes_backup_235 SELECT * FROM ajuda_transacoes WHERE transacao = ?')->execute([$transacao]);
                $universal->prepare('UPDATE ajuda_transacoes SET titulo_pt = ?, titulo_en = ?, titulo_es = ?, texto_pt = ?, texto_en = ?, texto_es = ?, ativo = 1 WHERE transacao = ?')
                    ->execute([$a['titulo']['pt'], $a['titulo']['en'], $a['titulo']['es'], $a['texto']['pt'], $a['texto']['en'], $a['texto']['es'], $transacao]);
            }
        }
        $resultado['telas'][$transacao] = $acao . ' — nova com ' . mb_strlen($simples($a['texto']['pt'])) . ' caracteres';
    } catch (Throwable $e) {
        $resultado['ok'] = false;
        $resultado['telas'][$transacao] = 'ERRO: ' . $e->getMessage();
    }
}
if (!$simular) $resultado['copia_anterior'] = 'guniversal_dbp.ajuda_transacoes_backup_235 (' . (int)$universal->query('SELECT COUNT(*) FROM ajuda_transacoes_backup_235')->fetchColumn() . ' telas)';

echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($resultado['ok'] ? 0 : 1);
