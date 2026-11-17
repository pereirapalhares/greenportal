<?php
declare(strict_types=1);

/*
 * Migração 355 — Fase 4 da sugestão do Ricardo (docs/Ricardo_sugestao1.txt; avaliação em docs/IA_analise_sugestao_ricardo1.txt):
 * na aprovação de um potencial da IA, escolher o destino em vez de sempre criar um registro novo.
 *   - Licença: vincular o requisito a uma ou várias licenças já cadastradas (ex.: várias "Licença Ambiental") ou criar uma nova
 *     já com o tipo documental.
 *   - Controle: vincular a um documento de controle já cadastrado (o mesmo procedimento atende vários potenciais) ou criar um novo.
 *
 * Cada empresa com potenciais da IA (ml_potenciais), licenças e documentos de controle:
 *   1. database/355_ia_potencial_destino.sql — tabela ml_potenciais_destinos, procedure sp_ia_potencial_aprovar_destino e
 *      vw_ml_potenciais_destinos (sem a coluna de tipo documental ou sem a camada content_* o trecho correspondente é retirado);
 *   2. vw_ia_destino_licencas e vw_ia_destino_documentos (listas da tela de aprovação), montadas conforme as colunas do banco.
 * Universal: Ajuda da tela Resultado da análise de IA (trecho entre <!-- ml355 -->).
 * A procedure antiga (sp_ia_potencial_validar) continua igual: monitoramento, treinamento e infraestrutura seguem como antes.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-355-ia-potencial-destino.php [--simular] [--banco=gcrown_dbp]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$rel = ['ok' => true, 'migracao' => '355', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'universal' => [], 'bancos' => []];

// ---------------------------------------------------------------- universal: ajuda
try {
    $u = GreenPortalDatabase::connect($env, $bancoUniversal, 'GPT');
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $rel['universal']['ajuda'] = gravarAjuda355($u, require dirname(__DIR__) . '/database/355_ajuda_ia_potencial_destino.php', $simular);
} catch (Throwable $e) { $rel['ok'] = false; $rel['universal']['erro'] = $e->getMessage(); }

// ---------------------------------------------------------------- empresas
$necessarias = ['ml_potenciais', 'ml_potenciais_solicitacoes', 'li_licencas', 'legislacaoxlicencas', 'documentos', 'legislacaoxdocumentos'];
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$s = $geral->prepare("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME IN (" . implode(',', array_fill(0, count($necessarias), '?')) . ") AND TABLE_SCHEMA LIKE 'g%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = " . count($necessarias) . " ORDER BY 1");
$s->execute($necessarias);
$bancos = $s->fetchAll(PDO::FETCH_COLUMN); $s->closeCursor();
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp') && ($soBanco === null || $b === $soBanco)));
if (!$bancos) $rel['bancos'] = ['aviso' => 'nenhuma empresa com potenciais da IA, licenças e documentos de controle'];
$sqlBase = (string)file_get_contents(dirname(__DIR__) . '/database/355_ia_potencial_destino.sql');

foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $col = static function (string $tabela, string $coluna) use ($pdo): bool {
            $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $q->execute([$tabela, $coluna]); $n = (int)$q->fetchColumn(); $q->closeCursor(); return $n > 0;
        };
        $tem = static fn(string $t): bool => MigracaoCrud::existe($pdo, $t);
        $temTipo = $col('li_licencas', 'CODTIPODOCUMENTO') && $tem('li_tipos_documentos');
        $temContent = $tem('content_documents') && $tem('content_document_links');
        $temVersao = $col('li_licencas', 'STATUS_VERSAO');
        $temNumero = $col('li_licencas', 'NUMERO_LICENCA');
        $temValidade = $col('li_licencas', 'DATA_VALIDADE');
        $temUnidades = $tem('unidades');
        $info['estrutura'] = [
            'tipo_documental' => $temTipo ? 'sim (licença nova já sai com o tipo escolhido)' : 'não (migração 226 não aplicada: licença nova sem tipo)',
            'vinculos_documentais' => $temContent ? 'sim (controle grava em content_document_links, origem IA_CONFIRMADA)' : 'não (migração 224/225 não aplicada)',
            'versoes_de_licenca' => $temVersao ? 'sim (lista só a versão ativa)' : 'não',
        ];
        $q = $pdo->query("SELECT tipo_potencial, status, COUNT(*) AS N FROM ml_potenciais WHERE tipo_potencial IN ('LICENCA','CONTROLE') GROUP BY 1, 2 ORDER BY 1, 2");
        $pot = []; foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $pot[$r['tipo_potencial']][$r['status']] = (int)$r['N']; $q->closeCursor();
        $info['potenciais_licenca_controle'] = $pot ?: 'nenhum';
        if ($simular) { $info['modo'] = 'simulação: nada gravado'; $rel['bancos'][$banco] = $info; continue; }

        // 1. Parte fixa (com os ajustes da estrutura deste banco)
        $sql = str_replace(['{TIPODOC_COL}', '{TIPODOC_VAL}'], $temTipo ? [', CODTIPODOCUMENTO', ', NULLIF(p_codtipodocumento, 0)'] : ['', ''], $sqlBase);
        if (!$temContent) $sql = (string)preg_replace('/^\s*-- CONTENT_INI.*?-- CONTENT_FIM\s*$/ms', '', $sql);
        $n = 0;
        foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) {
            $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
            if ($cmd === '') continue;
            $pdo->exec($cmd); $n++;
        }
        $info['blocos'] = $n;

        // 2. Listas da tela de aprovação
        $campos = ['l.CODLICENCA', 'l.codunidade AS CODUNIDADE', ($temUnidades ? 'COALESCE(u.DSCUNIDADE, \'\')' : "''") . ' AS DSCUNIDADE', 'l.DSCLICENCA',
            ($temTipo ? 'l.CODTIPODOCUMENTO' : 'NULL') . ' AS CODTIPODOCUMENTO', ($temTipo ? 'COALESCE(t.DSCTIPODOCUMENTO, \'\')' : "''") . ' AS DSCTIPODOCUMENTO',
            ($temNumero ? 'l.NUMERO_LICENCA' : "''") . ' AS NUMERO_LICENCA', ($temValidade ? "NULLIF(l.DATA_VALIDADE, '0000-00-00')" : 'NULL') . ' AS DATA_VALIDADE',
            '(SELECT COUNT(*) FROM legislacaoxlicencas x WHERE x.CODLICENCA = l.CODLICENCA) AS QTD_REQUISITOS'];
        $pdo->exec('CREATE OR REPLACE VIEW vw_ia_destino_licencas AS SELECT ' . implode(', ', $campos) . ' FROM li_licencas l'
            . ($temUnidades ? ' LEFT JOIN unidades u ON u.CODUNIDADE = l.codunidade' : '')
            . ($temTipo ? ' LEFT JOIN li_tipos_documentos t ON t.CODTIPODOCUMENTO = l.CODTIPODOCUMENTO' : '')
            . ($temVersao ? " WHERE COALESCE(l.STATUS_VERSAO, 'ATIVA') = 'ATIVA'" : ''));
        $pdo->exec("CREATE OR REPLACE VIEW vw_ia_destino_documentos AS SELECT d.CODDOCUMENTO, COALESCE(d.CODIGO, '') AS CODIGO, COALESCE(d.TITULO, '') AS TITULO,
            (SELECT COUNT(*) FROM legislacaoxdocumentos x WHERE x.CODDOCUMENTO = d.CODDOCUMENTO) AS QTD_REQUISITOS FROM documentos d");
        $info['listas'] = 'vw_ia_destino_licencas e vw_ia_destino_documentos gravadas';

        $q = $pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_ia_potencial_aprovar_destino'");
        $ok = (int)$q->fetchColumn() === 1; $q->closeCursor();
        $info['procedure'] = $ok ? 'gravada' : 'FALTOU';
        if (!$ok) $rel['ok'] = false;
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);

/** Ajuda: trecho entre <!-- ml355 --> e <!-- /ml355 --> no fim do texto atual (só esse trecho é trocado ao rodar de novo). */
function gravarAjuda355(PDO $u, array $textos, bool $simular): array
{
    if (!MigracaoCrud::existe($u, 'ajuda_transacoes')) return ['aviso' => 'sem ajuda_transacoes no universal'];
    $out = [];
    foreach ($textos as $transacao => $d) {
        // Busca exata (o filtro de texto do MigracaoCrud é "contém")
        $atual = null;
        foreach (MigracaoCrud::selecionar($u, 'ajuda_transacoes', ['transacao' => $transacao]) as $r) if ((string)($r['transacao'] ?? '') === $transacao) { $atual = $r; break; }
        $dados = [];
        foreach (['pt', 'en', 'es'] as $lg) {
            $velho = trim((string)preg_replace('/\s*<!-- ml355 -->.*?<!-- \/ml355 -->/s', '', (string)($atual['texto_' . $lg] ?? '')));
            $dados['texto_' . $lg] = ($velho === '' ? '' : $velho . "\n") . "<!-- ml355 -->\n" . $d['texto'][$lg] . "\n<!-- /ml355 -->";
            if ($atual === null || trim((string)($atual['titulo_' . $lg] ?? '')) === '') $dados['titulo_' . $lg] = $d['titulo'][$lg];
        }
        if ($atual !== null) { $igual = true; foreach ($dados as $k => $v) if ((string)($atual[$k] ?? '') !== $v) $igual = false; if ($igual) { $out[$transacao] = 'igual'; continue; } }
        if ($simular) { $out[$transacao] = $atual === null ? 'seria incluída' : 'seria atualizada'; continue; }
        if ($atual === null) MigracaoCrud::inserir($u, 'ajuda_transacoes', ['transacao' => $transacao] + $dados + ['ativo' => 1]);
        else MigracaoCrud::atualizar($u, 'ajuda_transacoes', ['transacao' => $transacao], $dados);
        $out[$transacao] = $atual === null ? 'incluída' : 'atualizada';
    }
    return $out;
}
