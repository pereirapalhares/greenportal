<?php
declare(strict_types=1);

/*
 * Migração 301 — Homologação de Fornecedores: regras automáticas da situação dos documentos de fornecedor e de funcionário,
 * adaptadas do serviço do legado E:\projetoGreen\processos\enviadoodocumento\enviadoodocumento.php.
 *
 * Em cada banco de cliente (gmodelo_dbp, gatlas_dbp, gmodelo2_dbp se existir) aplica database/301_regras_documentos_homologacao.sql:
 * tabela de regras (fo_regras_documentos, cada regra pode ser desligada), log (fo_regras_documentos_log) e as procedures
 * sp_fo_regras_documentos_listar / sp_fo_regras_documentos_aplicar. Nenhum documento é alterado aqui.
 * Quem altera é o serviço do Agendador do Windows scripts\homologacao-documentos-worker.php:
 *   1. php scripts\homologacao-documentos-worker.php --simular        (mostra o que mudaria, por regra)
 *   2. php scripts\homologacao-documentos-worker.php --sem-alertas    (primeira gravação, sem disparar alertas em massa)
 *   3. powershell -ExecutionPolicy Bypass -File scripts\criar-agendamento-documentos-homologacao.ps1   (a cada 60 minutos)
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-301-regras-documentos-homologacao.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-301-regras-documentos-homologacao.php
 * Também registra no catálogo de serviços (banco universal, tela Serviços) os três serviços da homologação no Agendador:
 * regras dos documentos (301), protocolo (300) e resumo semanal (298).
 * Pode ser executada mais de uma vez (as regras já existentes mantêm o "ativo" escolhido).
 *
 * Bancos novos na homologação (fora de MODELO, Atlas e MODELO2 — classes/HomologacaoBancos.php): na primeira instalação as
 * regras ficam DESLIGADAS, para o serviço de hora em hora não mudar a situação dos documentos antes da revisão. Depois de ver
 *   php scripts\homologacao-documentos-worker.php --simular --banco=gcrown_dbp
 * ligue com:  php scripts\aplicar-migracao-301-regras-documentos-homologacao.php --banco=gcrown_dbp --ligar-regras
 * (--desligar-regras desliga de novo). Uma regra só pode ser ligada/desligada pela tabela fo_regras_documentos.ativo.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';

define('M301_BANCOS', HomologacaoBancos::lista($argv ?? [])); // classes/HomologacaoBancos.php (--banco= para só alguns)

$simular = in_array('--simular', $argv ?? [], true);
$ligar = in_array('--ligar-regras', $argv ?? [], true);
$desligar = in_array('--desligar-regras', $argv ?? [], true);
if ($ligar && $desligar) { fwrite(STDERR, 'Use --ligar-regras ou --desligar-regras, não os dois.' . PHP_EOL); exit(1); }
$env = GreenPortalDatabase::env();
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$template = (string)file_get_contents(dirname(__DIR__) . '/database/301_regras_documentos_homologacao.sql');
$linhas = static function (PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) { } $s->closeCursor(); return $r; };
$valor = static function (PDO $pdo, string $sql, array $p = []) use ($linhas): mixed { $r = $linhas($pdo, $sql, $p); return $r ? array_values($r[0])[0] : null; };
$colunas = static function (string $b, string $t) use ($linhas, $geral): array {
    $out = [];
    foreach ($linhas($geral, 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$b, $t]) as $c) $out[strtolower($c['COLUMN_NAME'])] = true;
    return $out;
};
$exigir = [
    'fo_fornecedorxcriterios' => ['codfornecedorxcriterios', 'codfornecedor', 'situacao', 'enviadoodocumento', 'emprotocolo', 'datadevalidade', 'codusuario', 'codusuario_fornecedor'],
    'fo_fornecedorxcriterios_historico' => ['codusuarioalteracao', 'dataalteracao', 'codfornecedorxcriterios', 'codfornecedor'],
    'fo_fornecedorxusuario' => ['codfornecedor', 'codusuario'],
    'fo_funcionarioxtiposxcriterios' => ['codigo', 'codfuncionario', 'codcriteriofuncionario', 'atendeaocriterio', 'situacao', 'datadevalidade', 'codusuario', 'codusuario_responsavel_fornecedor'],
    'fo_funcionarios' => ['codfuncionario', 'codfornecedor', 'nome', 'ativo'],
];

$relatorio = ['ok' => true, 'migracao' => '301', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
foreach (M301_BANCOS as $banco) {
    $info = [];
    if (!$valor($geral, 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$banco])) {
        if ($banco !== 'gmodelo2_dbp') { $relatorio['ok'] = false; $relatorio['bancos'][$banco] = ['erro' => 'banco não existe']; }
        continue;
    }
    try {
        if (!$valor($geral, "SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'vw_homologacao_fornecedor_documentos'", [$banco]))
            throw new RuntimeException('rode antes a migração 293');
        if (!$valor($geral, "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? AND ROUTINE_NAME = 'sp_fo_protocolo_vencidos_processar'", [$banco]))
            throw new RuntimeException('rode antes a migração 300');
        $faltam = [];
        foreach ($exigir as $tabela => $cols) { $tem = $colunas($banco, $tabela); foreach ($cols as $c) if (!isset($tem[$c])) $faltam[] = "$tabela.$c"; }
        if ($faltam) throw new RuntimeException('colunas ausentes: ' . implode(', ', $faltam));
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $info['hoje'] = [
            'documentos_fornecedor_por_situacao' => array_column($linhas($pdo, "SELECT COALESCE(NULLIF(TRIM(SITUACAO), ''), '(sem situação)') AS s, COUNT(*) AS n FROM vw_homologacao_fornecedor_documentos GROUP BY 1 ORDER BY 2 DESC"), 'n', 's'),
            'documentos_funcionario_por_atende' => array_column($linhas($pdo, "SELECT COALESCE(NULLIF(TRIM(atendeaocriterio), ''), '(vazio)') AS s, COUNT(*) AS n FROM fo_funcionarioxtiposxcriterios GROUP BY 1 ORDER BY 2 DESC"), 'n', 's'),
        ];
        $jaInstalado = (bool)$valor($geral, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'fo_regras_documentos'", [$banco]);
        if ($simular) $info['regras_ao_instalar'] = $jaInstalado ? 'mantém o ligado/desligado atual' : (HomologacaoBancos::original($banco) ? 'ligadas' : 'DESLIGADAS (banco novo; ligar com --ligar-regras)');
        if (!$simular) {
            $n = 0;
            foreach (preg_split('/^-- @@\s*$/m', $template) as $bloco) {
                $limpo = trim(preg_replace('/^\s*--.*$/m', '', $bloco) ?? '');
                if ($limpo === '') continue;
                $pdo->exec($limpo); $n++;
            }
            $info['objetos_301'] = $n;
            // Banco novo: regras desligadas até a revisão; --ligar-regras / --desligar-regras mudam todas as regras do banco
            $novoAtivo = $ligar ? 1 : ($desligar || (!$jaInstalado && !HomologacaoBancos::original($banco)) ? 0 : null);
            if ($novoAtivo !== null) {
                MigracaoCrud::garantir($pdo);
                foreach ($linhas($pdo, 'SELECT CODREGRA FROM vw_fo_regras_documentos') as $r)
                    MigracaoCrud::atualizar($pdo, 'fo_regras_documentos', ['codregra' => $r['CODREGRA']], ['ativo' => $novoAtivo]);
                $info['regras_alteradas_para'] = $novoAtivo ? 'LIGADAS' : 'DESLIGADAS';
            }
            $info['regras'] = array_map(static fn($r) => $r['CODREGRA'] . ($r['ATIVO'] ? '' : ' (desligada)') . ' — ' . $r['RESULTADO'],
                $linhas($pdo, 'SELECT CODREGRA, ATIVO, RESULTADO FROM vw_fo_regras_documentos ORDER BY ORDEM'));
            $info['listar'] = ['FORNECEDOR' => count($linhas($pdo, "CALL sp_fo_regras_documentos_listar('FORNECEDOR')")), 'FUNCIONARIO' => count($linhas($pdo, "CALL sp_fo_regras_documentos_listar('FUNCIONARIO')"))];
            if ($colunas($banco, 'registros_migracao')) {
                $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
                    VALUES ('HOMOLOGACAO_REGRAS_DOCUMENTOS_301', 'enviadoodocumento.php', ?, 'ESTRUTURA', '4.0.1', 'CONCLUIDA', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
                    ON DUPLICATE KEY UPDATE status = VALUES(status), quantidade_registros = VALUES(quantidade_registros), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
                    ->execute(["$banco: regras automáticas dos documentos", $n, json_encode($info, JSON_UNESCAPED_UNICODE)]);
            }
        }
    } catch (Throwable $e) {
        $relatorio['ok'] = false;
        $info['erro'] = $e->getMessage();
    }
    $relatorio['bancos'][$banco] = $info;
}
// Catálogo de serviços (tela Serviços): os executores PHP da homologação no Agendador do Windows
$servicos = [
    'HOMOLOGACAO_DOCUMENTOS' => [14, 'scripts/homologacao-documentos-worker.php', ['Homologação — regras dos documentos', 'Supplier approval — document rules', 'Homologación — reglas de documentos'],
        ['Confere os arquivos no GED e ajusta a situação dos documentos de fornecedor e de funcionário (a cada 60 minutos).', 'Checks the files in the GED and updates supplier and employee document status (every 60 minutes).', 'Verifica los archivos en el GED y ajusta la situación de los documentos de proveedor y de empleado (cada 60 minutos).']],
    'HOMOLOGACAO_PROTOCOLO' => [15, 'scripts/homologacao-protocolo-worker.php', ['Homologação — protocolo vencido', 'Supplier approval — expired protocol', 'Homologación — protocolo vencido'],
        ['Na data de cobrança devolve o documento em protocolo para pendente com o fornecedor (todo dia).', 'On the follow-up date returns the document under protocol to pending with the supplier (daily).', 'En la fecha de cobro devuelve el documento en protocolo a pendiente con el proveedor (diario).']],
    'HOMOLOGACAO_RESUMO' => [16, 'scripts/homologacao-resumo-worker.php', ['Homologação — resumo semanal', 'Supplier approval — weekly summary', 'Homologación — resumen semanal'],
        ['Envia às segundas-feiras o resumo das pendências de cada responsável em Home › Alertas.', 'Sends each owner a summary of pending items in Home › Alerts every Monday.', 'Envía los lunes el resumen de pendientes de cada responsable en Inicio › Alertas.']],
];
$relatorio['catalogo_servicos'] = $simular ? 'será atualizado: ' . implode(', ', array_keys($servicos)) : [];
if (!$simular) {
    try {
        $u = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
        $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (MigracaoCrud::colunas($u, 'servicos')) {
            foreach ($servicos as $codigo => [$ordem, $script, $nome, $descricao]) {
                MigracaoCrud::salvar($u, 'servicos', ['codigo' => $codigo],
                    ['nome_pt' => $nome[0], 'nome_en' => $nome[1], 'nome_es' => $nome[2], 'descricao_pt' => $descricao[0], 'descricao_en' => $descricao[1], 'descricao_es' => $descricao[2],
                     'host' => 'TASK_SCHEDULER', 'script' => $script, 'ativo' => 1], ['porta' => 0, 'ordem' => $ordem]);
                $relatorio['catalogo_servicos'][] = $codigo;
            }
        }
    } catch (Throwable $e) { $relatorio['catalogo_servicos_erro'] = $e->getMessage(); }
}
$relatorio['proximos_passos'] = [
    'php scripts\\homologacao-documentos-worker.php --simular',
    'php scripts\\homologacao-documentos-worker.php --sem-alertas',
    'powershell -ExecutionPolicy Bypass -File scripts\\criar-agendamento-documentos-homologacao.ps1 (PowerShell como Administrador)',
];

$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_301_regras_documentos_homologacao' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
