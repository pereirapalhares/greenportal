<?php
declare(strict_types=1);

/*
 * Migração 302 — Homologação de Fornecedores: documentos "Em protocolo" = Sim sem data de cobrança (carga antiga) passam para
 * "Aguardando vencimento do protocolo", com cobrança em 30 dias (decisão do usuário em 17/10/2026). NA e "Não enviar ao fornecedor"
 * não mudam. Grava histórico do documento e o log das regras (codregra PROTOCOLO_SEM_COBRANCA). Depois, o serviço do protocolo
 * (migração 300) trata esses documentos normalmente.
 * Em cada banco de cliente (gmodelo_dbp, gatlas_dbp, gmodelo2_dbp se existir): instala sp_fo_protocolo_sem_cobranca_converter
 * (database/302_protocolo_sem_cobranca.sql) e a executa.
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-302-protocolo-sem-cobranca.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-302-protocolo-sem-cobranca.php
 * Pode ser executada mais de uma vez (só converte o que ainda estiver sem data de cobrança).
 * A decisão de converter vale para MODELO, Atlas e MODELO2. Nos bancos novos da homologação (classes/HomologacaoBancos.php)
 * a procedure é instalada e só mostra quantos seriam convertidos; para converter, rode com --banco=<banco> --converter.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

define('M302_BANCOS', HomologacaoBancos::lista($argv ?? [])); // classes/HomologacaoBancos.php (--banco= para só alguns)

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$template = (string)file_get_contents(dirname(__DIR__) . '/database/302_protocolo_sem_cobranca.sql');
$linhas = static function (PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) { } $s->closeCursor(); return $r; };
$valor = static function (PDO $pdo, string $sql, array $p = []) use ($linhas): mixed { $r = $linhas($pdo, $sql, $p); return $r ? array_values($r[0])[0] : null; };

$relatorio = ['ok' => true, 'migracao' => '302', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'cobranca' => date('Y-m-d', strtotime('+30 days')), 'bancos' => []];
foreach (M302_BANCOS as $banco) {
    $info = [];
    if (!$valor($geral, 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$banco])) {
        if ($banco !== 'gmodelo2_dbp') { $relatorio['ok'] = false; $relatorio['bancos'][$banco] = ['erro' => 'banco não existe']; }
        continue;
    }
    try {
        if (!$valor($geral, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'fo_regras_documentos_log'", [$banco]))
            throw new RuntimeException('rode antes a migração 301');
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        foreach (preg_split('/^-- @@\s*$/m', $template) as $bloco) {
            $limpo = trim(preg_replace('/^\s*--.*$/m', '', $bloco) ?? '');
            if ($limpo !== '') $pdo->exec($limpo);
        }
        $converter = !$simular && (HomologacaoBancos::original($banco) || in_array('--converter', $argv ?? [], true));
        $r = $linhas($pdo, 'CALL sp_fo_protocolo_sem_cobranca_converter(?, 0)', [$converter ? 1 : 0]);
        $info[$converter ? 'convertidos' : 'seriam_convertidos'] = array_column($r, 'QTD', 'SITUACAO_ANTES') ?: 0;
        if (!$simular && !$converter) $info['aviso'] = 'banco novo: nada convertido; para converter use --banco=' . $banco . ' --converter';
    } catch (Throwable $e) {
        $relatorio['ok'] = false;
        $info['erro'] = $e->getMessage();
    }
    $relatorio['bancos'][$banco] = $info;
}

$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_302_protocolo_sem_cobranca' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
