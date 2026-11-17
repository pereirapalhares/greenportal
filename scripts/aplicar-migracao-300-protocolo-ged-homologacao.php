<?php
declare(strict_types=1);

/*
 * Migração 300 — Homologação de Fornecedores: regra do protocolo (como no legado) e anexos (GED) das abas do fornecedor.
 *
 * Em cada banco de cliente (gmodelo_dbp, gatlas_dbp, gmodelo2_dbp se existir) aplica database/300_protocolo_ged_homologacao.sql:
 *  - Em protocolo = Sim (antes não estava) ou Renovar → situação "Aguardando vencimento do protocolo", cobrança em 30 dias;
 *    tirar do protocolo com a situação aguardando → "Pendente com o fornecedor";
 *  - na data de cobrança o serviço scripts\homologacao-protocolo-worker.php (Agendador do Windows, todo dia) volta o documento
 *    para "Pendente com o fornecedor" e avisa os responsáveis em Home › Alertas
 *    (crie a tarefa com scripts\criar-agendamento-protocolo-homologacao.ps1);
 *  - cor: aguardando = amarelo até a data de cobrança;
 *  - aba Auditoria: coluna "Necessita auditoria?" (do tipo).
 * Os anexos de Certificados, Produtos químicos (FDS), Equipamentos, Plano de ação e Auditoria (evidência) não usam banco: ficam no
 * GED do legado, E:\projetoGreen\GED\green_<empresa>\<pasta>\<código> (mesmas pastas do legado).
 * Nada de dados é convertido: o relatório mostra os documentos em protocolo hoje, para conferência.
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-300-protocolo-ged-homologacao.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-300-protocolo-ged-homologacao.php
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

define('M300_BANCOS', HomologacaoBancos::lista($argv ?? [])); // classes/HomologacaoBancos.php (--banco= para só alguns)
const M300_AGUARDANDO = 'Aguardando vencimento do protocolo';

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$template = (string)file_get_contents(dirname(__DIR__) . '/database/300_protocolo_ged_homologacao.sql');
$linhas = static function (PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) { } $s->closeCursor(); return $r; };
$valor = static function (PDO $pdo, string $sql, array $p = []) use ($linhas): mixed { $r = $linhas($pdo, $sql, $p); return $r ? array_values($r[0])[0] : null; };
$colunas = static function (string $b, string $t) use ($linhas, $geral): array {
    $out = [];
    foreach ($linhas($geral, 'SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$b, $t]) as $c) $out[strtolower($c['COLUMN_NAME'])] = $c['CHARACTER_MAXIMUM_LENGTH'];
    return $out;
};
$retrato = static function (PDO $pdo) use ($linhas): array {
    $r = ['em_protocolo_por_situacao' => []];
    foreach ($linhas($pdo, "SELECT COALESCE(NULLIF(TRIM(situacao), ''), '(vazia)') AS s, COUNT(*) AS n FROM fo_fornecedorxcriterios WHERE UPPER(COALESCE(emprotocolo, '')) = 'S' GROUP BY 1 ORDER BY 2 DESC") as $l)
        $r['em_protocolo_por_situacao'][$l['s']] = (int)$l['n'];
    $a = $linhas($pdo, "SELECT COUNT(*) AS total, SUM(datacobranca_emprotocolo IS NULL) AS sem_data, SUM(datacobranca_emprotocolo <= CURDATE()) AS vencidos
                          FROM fo_fornecedorxcriterios WHERE situacao = ?", [M300_AGUARDANDO])[0];
    $r['aguardando_vencimento'] = ['total' => (int)$a['total'], 'cobranca_ate_hoje (o serviço volta para pendente com o fornecedor)' => (int)$a['vencidos'],
        'sem_data_de_cobranca (o serviço não altera; ajuste na tela)' => (int)$a['sem_data']];
    return $r;
};

$relatorio = ['ok' => true, 'migracao' => '300', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
foreach (M300_BANCOS as $banco) {
    $info = [];
    if (!$valor($geral, 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$banco])) {
        if ($banco !== 'gmodelo2_dbp') { $relatorio['ok'] = false; $relatorio['bancos'][$banco] = ['erro' => 'banco não existe']; }
        continue;
    }
    try {
        if (!$valor($geral, "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? AND ROUTINE_NAME = 'sp_fo_fornecedor_documento_historico_gravar'", [$banco]))
            throw new RuntimeException('rode antes a migração 293');
        if (!$valor($geral, "SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'vw_homologacao_painel_itens'", [$banco]))
            throw new RuntimeException('rode antes a migração 298');
        $doc = $colunas($banco, 'fo_fornecedorxcriterios');
        foreach (['situacao', 'emprotocolo', 'datacobranca_emprotocolo'] as $c) if (!array_key_exists($c, $doc)) throw new RuntimeException("fo_fornecedorxcriterios sem a coluna $c");
        if ($doc['situacao'] !== null && (int)$doc['situacao'] < mb_strlen(M300_AGUARDANDO)) throw new RuntimeException('fo_fornecedorxcriterios.situacao tem só ' . $doc['situacao'] . ' caracteres (precisa de ' . mb_strlen(M300_AGUARDANDO) . ')');
        $tipo = $colunas($banco, 'fo_tipofornecedores');
        if (!array_key_exists('necessita_auditoria', $tipo)) throw new RuntimeException('fo_tipofornecedores sem a coluna necessita_auditoria');
        // A view da aba Auditoria só é trocada se ainda tiver as colunas da migração 089 (não apaga colunas de outra migração)
        $vAud = array_keys($colunas($banco, 'vw_homologacao_fornecedor_auditoria_grid'));
        $esperadas = ['codfornecedorxtipofornecedores', 'codfornecedor', 'codtipofornecedor', 'dsctipo', 'datavalidade', 'datareacao', 'fornecedoraprovadoauditoria', 'nota', 'necessita_auditoria'];
        if ($extras = array_diff($vAud, $esperadas)) throw new RuntimeException('vw_homologacao_fornecedor_auditoria_grid tem colunas não previstas: ' . implode(', ', $extras));

        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $info['antes'] = $retrato($pdo);
        if (!$simular) {
            $n = 0;
            foreach (preg_split('/^-- @@\s*$/m', $template) as $bloco) {
                $limpo = trim(preg_replace('/^\s*--.*$/m', '', $bloco) ?? '');
                if ($limpo === '') continue;
                $pdo->exec($limpo); $n++;
            }
            $info['objetos_300'] = $n;
            $info['cores_documentos'] = [];
            foreach ($linhas($pdo, 'SELECT COR, COUNT(*) AS N FROM vw_homologacao_fornecedor_documentos GROUP BY COR ORDER BY COR') as $l) $info['cores_documentos'][$l['COR']] = (int)$l['N'];
            $info['servico_listaria_hoje'] = count($linhas($pdo, 'CALL sp_fo_protocolo_vencidos_processar(0, 0)'));
            $info['auditoria_necessita'] = [];
            foreach ($linhas($pdo, "SELECT COALESCE(NECESSITA_AUDITORIA, '(vazio)') AS n, COUNT(*) AS q FROM vw_homologacao_fornecedor_auditoria_grid GROUP BY 1") as $l) $info['auditoria_necessita'][$l['n']] = (int)$l['q'];
            if ($colunas($banco, 'registros_migracao')) {
                $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
                    VALUES ('HOMOLOGACAO_PROTOCOLO_GED_300', 'fo_*', ?, 'ESTRUTURA', '4.0.1', 'CONCLUIDA', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
                    ON DUPLICATE KEY UPDATE status = VALUES(status), quantidade_registros = VALUES(quantidade_registros), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
                    ->execute(["$banco: protocolo e GED das abas do fornecedor", $n, json_encode($info, JSON_UNESCAPED_UNICODE)]);
            }
        }
    } catch (Throwable $e) {
        $relatorio['ok'] = false;
        $info['erro'] = $e->getMessage();
    }
    $relatorio['bancos'][$banco] = $info;
}
$relatorio['proximo_passo'] = 'Crie a tarefa diária (PowerShell como Administrador): powershell -ExecutionPolicy Bypass -File scripts\criar-agendamento-protocolo-homologacao.ps1';

$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_300_protocolo_ged_homologacao' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
