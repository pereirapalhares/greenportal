<?php
declare(strict_types=1);

/*
 * Diagnóstico para a migração 361 (item 4 do 01_Mudancas_Gerais: Tipo × Responsável por módulo e política de e-mail).
 * Só LÊ: estrutura das tabelas dos módulos, unidades dos registros, quem gera alertas (procedures, eventos, gatilhos e PHP),
 * colunas da vw_responsabilidade_efetiva e quantidades. Grava o resultado em storage/logs/diagnostico-361.json.
 * Uso: & $php scripts\diagnostico-361-responsaveis-tipo.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$raiz = dirname(__DIR__);
$rel = ['gerado_em' => date('c'), 'bancos' => [], 'php_alertas' => []];
$tabelas = ['li_licencas', 'li_tipos_documentos', 'li_classificacao', 'mm_monitoramento', 'mm_medicao', 'documentos', 'ml_treinamentos',
    'ml_infraestrutura', 'alertas', 'grupos', 'responsabilidade_modulos', 'responsabilidade_tipos', 'responsabilidade_atribuicoes',
    'vw_responsabilidade_efetiva', 'vw_responsabilidade_grupoxusuario', 'unidades', 'li_lista_distribuicao', 'listas_distribuicao'];

$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT DISTINCT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME = 'responsabilidade_atribuicoes' AND TABLE_SCHEMA LIKE 'g%\\_dbp' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
$detalhado = in_array('gcrown_dbp', $bancos, true) ? 'gcrown_dbp' : ($bancos[0] ?? '');

foreach ($bancos as $banco) {
    $b = ['tabelas' => [], 'contagens' => []];
    $q = $geral->prepare('SELECT TABLE_NAME, GROUP_CONCAT(CONCAT(COLUMN_NAME, " ", COLUMN_TYPE) ORDER BY ORDINAL_POSITION SEPARATOR ", ") FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (' . implode(',', array_fill(0, count($tabelas), '?')) . ') GROUP BY TABLE_NAME');
    $q->execute(array_merge([$banco], $tabelas));
    foreach ($q->fetchAll(PDO::FETCH_NUM) as [$t, $cols]) $b['tabelas'][$t] = $banco === $detalhado ? $cols : strlen((string)$cols) . ' bytes';
    // Tabelas de ligação com unidade (documento/treinamento/infraestrutura × unidade)
    $q = $geral->prepare("SELECT TABLE_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (
        SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND LOWER(COLUMN_NAME) = 'codunidade') AND TABLE_NAME IN (
        SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND LOWER(COLUMN_NAME) IN ('coddocumento', 'codtreinamento', 'codinfraestrutura', 'codmonitoramento', 'codlicenca'))
        GROUP BY TABLE_NAME");
    $q->execute([$banco, $banco, $banco]);
    $b['ligacao_unidade'] = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    if ($banco === $detalhado) {
        // Quem insere alertas no banco
        $q = $geral->prepare("SELECT ROUTINE_TYPE, ROUTINE_NAME, ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? AND ROUTINE_DEFINITION LIKE '%alertas%'");
        $q->execute([$banco]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $def = (string)$r['ROUTINE_DEFINITION'];
            $mods = array_values(array_filter(['li_licencas', 'li_condicionantes', 'mm_monitoramento', 'documentos', 'ml_treinamentos', 'ml_infraestrutura', 'responsabilidade', 'lista'], static fn($m) => stripos($def, $m) !== false));
            $b['rotinas_alertas'][] = $r['ROUTINE_TYPE'] . ' ' . $r['ROUTINE_NAME'] . ' [' . implode(',', $mods) . '] ' . (preg_match('/insert\s+into\s+`?alertas`?/i', $def) ? 'INSERE' : 'le');
        }
        $q = $geral->prepare('SELECT EVENT_NAME, STATUS, INTERVAL_VALUE, INTERVAL_FIELD, LEFT(EVENT_DEFINITION, 300) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?');
        $q->execute([$banco]);
        $b['eventos'] = $q->fetchAll(PDO::FETCH_NUM);
        $q = $geral->prepare("SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND ACTION_STATEMENT LIKE '%alertas%'");
        $q->execute([$banco]);
        $b['gatilhos_alertas'] = $q->fetchAll(PDO::FETCH_NUM);
        $q = $geral->prepare("SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND (TABLE_NAME LIKE 'vw\\_home\\_pend%' OR TABLE_NAME LIKE 'vw\\_pendencia%' OR TABLE_NAME LIKE '%vencimento%')");
        $q->execute([$banco]);
        $b['views_pendencias'] = $q->fetchAll(PDO::FETCH_COLUMN);
        try {
            $p = GreenPortalDatabase::connect($env, $banco, 'GPT');
            foreach (['li_tipos_documentos', 'mm_medicao', 'grupos', 'responsabilidade_modulos', 'responsabilidade_tipos', 'responsabilidade_atribuicoes', 'li_licencas', 'mm_monitoramento', 'documentos', 'ml_treinamentos', 'ml_infraestrutura', 'unidades'] as $t) {
                try { $b['contagens'][$t] = (int)$p->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); } catch (Throwable $e) { $b['contagens'][$t] = 'n/d'; }
            }
            try { $b['responsabilidade_modulos'] = $p->query('SELECT CODMODULO, CODIGO, ATIVO FROM responsabilidade_modulos')->fetchAll(PDO::FETCH_NUM); } catch (Throwable) {}
            try { $b['responsabilidade_tipos'] = $p->query('SELECT CODTIPORESPONSABILIDADE, CODMODULO, CODIGO, NOME FROM responsabilidade_tipos LIMIT 40')->fetchAll(PDO::FETCH_NUM); } catch (Throwable) {}
            try { $b['atribuicoes_por_origem'] = $p->query('SELECT ENTIDADE, ORIGEM, TIPO_DESTINO, COUNT(*) FROM responsabilidade_atribuicoes GROUP BY 1,2,3')->fetchAll(PDO::FETCH_NUM); } catch (Throwable) {}
            try { $b['efetiva_definicao_bytes'] = strlen((string)$p->query("SELECT VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vw_responsabilidade_efetiva'")->fetchColumn()); } catch (Throwable) {}
            try { $t0 = microtime(true); $p->query('SELECT COUNT(*) FROM vw_responsabilidade_efetiva')->fetchColumn(); $b['efetiva_ms'] = (int)round((microtime(true) - $t0) * 1000); } catch (Throwable $e) { $b['efetiva_ms'] = $e->getMessage(); }
        } catch (Throwable $e) { $b['erro'] = $e->getMessage(); }
    }
    $rel['bancos'][$banco] = $b;
}

// PHP que cria alertas
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $caminho = str_replace('\\', '/', substr($f->getPathname(), strlen($raiz) + 1));
    if ($f->getExtension() !== 'php' || preg_match('#^(vendor|backups|storage|node_modules)/#', $caminho)) continue;
    $txt = (string)file_get_contents($f->getPathname());
    if (preg_match_all('/insert\s+into\s+`?alertas`?|sp_alerta\w*|sp_resp_auto_alerta|AlertasService|->alertar\(|criarAlerta|inserirAlerta/i', $txt, $m)) $rel['php_alertas'][$caminho] = array_values(array_unique(array_map('strtolower', $m[0])));
}

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@mkdir($raiz . '/storage/logs', 0775, true);
file_put_contents($raiz . '/storage/logs/diagnostico-361.json', $json);
echo $json . PHP_EOL;
