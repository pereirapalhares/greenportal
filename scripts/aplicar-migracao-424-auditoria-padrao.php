<?php
declare(strict_types=1);

/*
 * Migração 424 — Módulo de Auditoria no padrão de telas do portal (27/11/2026).
 *
 * Em cada empresa (g*_dbp):
 *   vw_aud_lista ... a lista de auditorias do grid padrão (Gestão de Auditorias): nomes da unidade, do tipo, do auditor e do avaliador
 *                    (usuário ou grupo), do fornecedor e do criador; checklist "respondidas/perguntas"; NC; RNCs "abertas/total".
 *   vw_aud_minhas .. a mesma lista com CODUSUARIO_ENVOLVIDO (uma linha por pessoa que participa: criador, avaliador, auditor e os membros
 *                    ativos dos grupos de avaliadores e de auditores) — Minhas Auditorias filtra por ela.
 * --limpar-teste: apaga a auditoria de teste do Claude no Crown (título "TESTE CLAUDE%") com o checklist, as constatações, a avaliação,
 *   a execução e o histórico, e as RNCs abertas por ela ("[AUD <nº>/C…" no sumário) com as linhas filhas (tabelas nc_* com CODOCORRENCIA).
 *   Gravação por sp_crud_delete.
 * --ajuda: grava a ajuda das três telas (auditoria_gestao, minhas_auditorias, home_auditoria) com os botões novos, de
 *   database/424_ajuda_auditoria.php, em ajuda_transacoes (universal) entre <!-- ml424 --> e <!-- /ml424 -->, no lugar do bloco ml423.
 *   Sozinha (só --ajuda) não mexe nas views das empresas.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-424-auditoria-padrao.php [--simular] [--banco=gcrown_dbp] [--limpar-teste] [--ajuda]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$limpar = in_array('--limpar-teste', $args, true);
$ajuda = in_array('--ajuda', $args, true);
$soAjuda = $ajuda && !$limpar && !array_filter($args, static fn($a) => str_starts_with((string)$a, '--banco='));
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$rel = ['ok' => true, 'migracao' => '424', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];

/** Coluna existente (sem diferenciar maiúsculas) dentre as candidatas, com o nome real. */
$coluna = static function (PDO $pdo, string $obj, array $cands): ?string {
    if (!MigracaoCrud::existe($pdo, $obj)) return null;
    $cols = MigracaoCrud::colunas($pdo, $obj);
    foreach ($cands as $c) foreach ($cols as $real) if (strcasecmp($real, $c) === 0) return $real;
    return null;
};
$del = static function (PDO $pdo, string $tabela, array $chave): void {
    $s = $pdo->prepare('CALL sp_crud_delete(:t, :k)'); $s->execute(['t' => $tabela, 'k' => json_encode($chave)]); $s->closeCursor();
};
$pkDe = static function (PDO $pdo, string $tabela): ?string {
    $s = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION");
    $s->execute([$tabela]); $c = $s->fetchAll(PDO::FETCH_COLUMN); $s->closeCursor();
    return count($c) === 1 ? (string)$c[0] : null;
};

$cat = new MenuCatalogoService($env);
foreach ($soAjuda ? [] : $cat->empresas() as $banco => $pdo) {
    if ($soBanco !== null && $banco !== $soBanco) continue;
    $info = [];
    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (!MigracaoCrud::existe($pdo, 'vw_aud_auditorias_grid')) { $info['aviso'] = 'sem a migração 423'; $rel['bancos'][$banco] = $info; continue; }

        // nomes: unidade, grupo, fornecedor (colunas variam entre as bases migradas)
        $unNome = $coluna($pdo, 'unidades', ['DSCUNIDADE']);
        $unCod = $coluna($pdo, 'unidades', ['CODUNIDADE']);
        $joinUn = $unNome && $unCod ? "LEFT JOIN `unidades` un ON un.`$unCod` = a.`CODUNIDADE`" : '';
        $selUn = $joinUn ? "un.`$unNome`" : 'NULL';
        $grpObj = MigracaoCrud::existe($pdo, 'vw_grupos_grid') ? 'vw_grupos_grid' : (MigracaoCrud::existe($pdo, 'grupos') ? 'grupos' : null);
        $grpCod = $grpObj ? $coluna($pdo, $grpObj, ['CODGRUPO']) : null;
        $grpNome = $grpObj ? $coluna($pdo, $grpObj, ['NOME', 'DSCGRUPO', 'DESCRICAO', 'NOMEGRUPO']) : null;
        $grp = static fn(string $al, string $col) => $grpCod && $grpNome ? "(SELECT MAX(g.`$grpNome`) FROM `$grpObj` g WHERE g.`$grpCod` = a.`$col`)" : 'NULL';
        $foCod = $coluna($pdo, 'fo_fornecedores', ['CODFORNECEDOR']);
        $foNome = $coluna($pdo, 'fo_fornecedores', ['RAZAOSOCIAL', 'NOMEFANTASIA', 'NOME']);
        $foFant = $coluna($pdo, 'fo_fornecedores', ['NOMEFANTASIA']);
        $joinFo = $foCod && $foNome ? "LEFT JOIN `fo_fornecedores` fo ON fo.`$foCod` = a.`CODFORNECEDOR`" : '';
        $selFo = $joinFo ? "COALESCE(NULLIF(TRIM(fo.`$foNome`), '')" . ($foFant && $foFant !== $foNome ? ", NULLIF(TRIM(fo.`$foFant`), '')" : '') . ", CONCAT('#', a.`CODFORNECEDOR`))" : "IF(a.`CODFORNECEDOR` > 0, CONCAT('#', a.`CODFORNECEDOR`), NULL)";

        $lista = "CREATE OR REPLACE VIEW `vw_aud_lista` AS
            SELECT a.`CODAUDITORIA`, a.`CODAUDITORIA` AS `NUMERO`, a.`STATUS`, a.`SITUACAO`, a.`DSCAUDITORIA`, a.`NORMA`, a.`OBJETIVO`, a.`ESCOPO`, a.`LOCALIZACAO`,
                   a.`CODUNIDADE`, $selUn AS `DSCUNIDADE`, a.`TIPO`,
                   CASE a.`TIPO` WHEN 'INTERNA' THEN 'Interna' WHEN 'EXTERNA' THEN 'Externa (certificação)' WHEN 'FORNECEDOR' THEN 'De fornecedor' WHEN 'CLIENTE' THEN 'De cliente' ELSE a.`TIPO` END AS `TIPO_DSC`,
                   a.`DATADEINICIO`, a.`DATADEFIM`, a.`DATAEXECUCAO`, a.`DATAENCERRAMENTO`, a.`DATADECRIACAO`,
                   a.`CODUSUARIO_AUDITOR`, a.`CODGRUPO_AUDITORES`, COALESCE(IF(a.`CODUSUARIO_AUDITOR` > 0, a.`AUDITOR`, NULL), " . $grp('a', 'CODGRUPO_AUDITORES') . ") AS `AUDITOR_NOME`,
                   a.`CODUSUARIO_AVALIADOR`, a.`CODGRUPO_AVALIADORES`, COALESCE(IF(a.`CODUSUARIO_AVALIADOR` > 0, a.`AVALIADOR`, NULL), " . $grp('a', 'CODGRUPO_AVALIADORES') . ") AS `AVALIADOR_NOME`,
                   a.`CODUSUARIO_CRIADOR`, a.`CRIADOR`, a.`CODFORNECEDOR`, $selFo AS `FORNECEDOR`, a.`CODORIGEMACAO`,
                   a.`QTD_PERGUNTAS`, a.`QTD_RESPONDIDAS`, IF(a.`QTD_PERGUNTAS` > 0, CONCAT(a.`QTD_RESPONDIDAS`, '/', a.`QTD_PERGUNTAS`), '') AS `CHECKLIST`,
                   a.`QTD_CONSTATACOES`, a.`QTD_NC`, a.`QTD_RNC`, a.`QTD_RNC_ABERTAS`, IF(a.`QTD_RNC` > 0, CONCAT(a.`QTD_RNC_ABERTAS`, '/', a.`QTD_RNC`), '') AS `RNC_ABERTAS`,
                   IF(a.`STATUS` IN ('P', 'A', 'R', 'X') AND a.`DATADEFIM` IS NOT NULL AND a.`DATADEFIM` < CURDATE(), 'Sim', 'Não') AS `ATRASADA`,
                   a.`ULTIMO_MOVIMENTO`
              FROM `vw_aud_auditorias_grid` a $joinUn $joinFo";

        // participantes (Minhas Auditorias): criador, avaliador, auditor e membros ativos dos grupos
        $membros = '';
        if (MigracaoCrud::existe($pdo, 'responsabilidade_grupoxusuario')) {
            $cols = array_map('strtolower', MigracaoCrud::colunas($pdo, 'responsabilidade_grupoxusuario'));
            $cond = (in_array('ativo', $cols, true) ? ' AND g.`ATIVO` = 1' : '') . (in_array('valido_ate', $cols, true) ? ' AND (g.`VALIDO_ATE` IS NULL OR g.`VALIDO_ATE` >= CURDATE())' : '');
            foreach (['codgrupo_avaliadores', 'codgrupo_auditores'] as $cg)
                $membros .= " UNION SELECT p.`CODAUDITORIA`, g.`CODUSUARIO` FROM `auditoria_planejamento` p JOIN `responsabilidade_grupoxusuario` g ON g.`CODGRUPO` = p.`$cg` WHERE p.`$cg` > 0$cond";
        }
        $minhas = "CREATE OR REPLACE VIEW `vw_aud_minhas` AS
            SELECT l.*, x.`CODUSUARIO_ENVOLVIDO` FROM `vw_aud_lista` l JOIN (
                SELECT p.`CODAUDITORIA`, p.`codusuario_criador` AS `CODUSUARIO_ENVOLVIDO` FROM `auditoria_planejamento` p WHERE p.`codusuario_criador` > 0
                UNION SELECT p.`CODAUDITORIA`, p.`codusuario_avaliador` FROM `auditoria_planejamento` p WHERE p.`codusuario_avaliador` > 0
                UNION SELECT p.`CODAUDITORIA`, p.`codusuario_auditor` FROM `auditoria_planejamento` p WHERE p.`codusuario_auditor` > 0$membros
            ) x ON x.`CODAUDITORIA` = l.`CODAUDITORIA`";
        if (!$simular) { $pdo->exec($lista); $pdo->exec($minhas); }
        $info['views'] = $simular ? 'vw_aud_lista e vw_aud_minhas a criar' : 'vw_aud_lista e vw_aud_minhas criadas';
        $info['nomes'] = ['unidade' => $selUn !== 'NULL', 'grupo' => (bool)($grpCod && $grpNome), 'fornecedor' => $joinFo !== '' ? "$foNome" : 'sem fo_fornecedores'];
        if (!$simular) $info['auditorias_na_lista'] = (int)$pdo->query('SELECT COUNT(*) FROM `vw_aud_lista`')->fetchColumn();

        // ------------------------------------------------ limpeza dos dados de teste
        if ($limpar) {
            $s = $pdo->query("SELECT `CODAUDITORIA` FROM `auditoria_planejamento` WHERE `DSCAUDITORIA` LIKE 'TESTE CLAUDE%' AND `CODAUDITORIA` >= 50001");
            $testes = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)); $s->closeCursor();
            $feito = [];
            foreach ($testes as $cod) {
                // RNCs abertas pela auditoria de teste (sumário "[AUD <cod>/C…") e as linhas filhas (tabelas nc_* com CODOCORRENCIA)
                $s = $pdo->prepare("SELECT `CODOCORRENCIA` FROM `nc_indicacao_ocorrencia` WHERE `codauditoria` = ? AND `IDENTIFICACAO` LIKE ?");
                $s->execute([$cod, '[AUD ' . $cod . '/C%']); $ocs = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)); $s->closeCursor();
                $filhas = $pdo->query("SELECT DISTINCT c.TABLE_NAME FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
                    WHERE c.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE' AND LOWER(c.COLUMN_NAME) = 'codocorrencia' AND LOWER(c.TABLE_NAME) LIKE 'nc\\_%' AND LOWER(c.TABLE_NAME) <> 'nc_indicacao_ocorrencia'")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($ocs as $oc) {
                    foreach ($filhas as $t) {
                        $pk = $pkDe($pdo, $t); $colOc = $coluna($pdo, $t, ['CODOCORRENCIA']);
                        $s = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE `$colOc` = ?"); $s->execute([$oc]); $n = (int)$s->fetchColumn(); $s->closeCursor();
                        if (!$n) continue;
                        if (!$pk) { $feito['avisos'][] = "$t sem chave primária simples ($n linha(s) da RNC $oc ficaram)"; continue; }
                        if (!$simular) { $s = $pdo->prepare("SELECT `$pk` FROM `$t` WHERE `$colOc` = ?"); $s->execute([$oc]); foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $k) $del($pdo, $t, [$pk => $k]); $s->closeCursor(); }
                        $feito['rnc'][$oc][$t] = $n;
                    }
                    if (!$simular) $del($pdo, 'nc_indicacao_ocorrencia', ['CODOCORRENCIA' => $oc]);
                    $feito['rnc'][$oc]['nc_indicacao_ocorrencia'] = 1;
                }
                foreach ([['aud_constatacao', 'CODCONSTATACAO'], ['aud_checklist', 'CODCHECKLIST'], ['auditoria_planejamentoxavaliacao', 'CODAVALIACAO'], ['auditoria_planejamentoxexecucao', 'CODEXECUCAO'], ['auditoria_planodeacao', 'CODPLANOACAO'], ['auditoria_workflow', 'CODWORKFLOW']] as [$t, $pk]) {
                    $s = $pdo->prepare("SELECT `$pk` FROM `$t` WHERE `CODAUDITORIA` = ?"); $s->execute([$cod]); $ks = $s->fetchAll(PDO::FETCH_COLUMN); $s->closeCursor();
                    if (!$simular) foreach ($ks as $k) $del($pdo, $t, [$pk => $k]);
                    if ($ks) $feito['auditoria'][$cod][$t] = count($ks);
                }
                if (!$simular) { $del($pdo, 'aud_info', ['CODAUDITORIA' => $cod]); $del($pdo, 'auditoria_planejamento', ['CODAUDITORIA' => $cod]); }
                $feito['auditoria'][$cod]['auditoria_planejamento'] = 1;
                // agenda (fontes auditoria:<cod>:auditor / :avaliador) — o cancelamento já tirou; aqui só confere
                if (MigracaoCrud::existe($pdo, 'agenda_eventos') && ($colF = $coluna($pdo, 'agenda_eventos', ['FONTE', 'ORIGEM', 'SOURCE', 'source_key', 'CHAVE_ORIGEM']))) {
                    $s = $pdo->prepare("SELECT COUNT(*) FROM `agenda_eventos` WHERE `$colF` LIKE ?"); $s->execute(['auditoria:' . $cod . ':%']); $feito['auditoria'][$cod]['agenda_eventos_restantes'] = (int)$s->fetchColumn(); $s->closeCursor();
                }
            }
            $info['limpeza'] = $testes ? $feito : 'nenhuma auditoria de teste';
        }
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}

// ---------------------------------------------------------------- ajuda das telas (padrão novo)
if ($ajuda) {
    try {
        $u = $cat->universal();
        $textos = require dirname(__DIR__) . '/database/424_ajuda_auditoria.php';
        if (!MigracaoCrud::existe($u, 'ajuda_transacoes')) throw new RuntimeException('sem a tabela ajuda_transacoes no universal');
        foreach ($textos as $transacao => $d) {
            $atual = null;
            foreach (MigracaoCrud::selecionar($u, 'ajuda_transacoes', ['transacao' => $transacao]) as $r) if (strcasecmp((string)($r['transacao'] ?? ''), $transacao) === 0) { $atual = $r; break; }
            $dados = [];
            foreach (['pt', 'en', 'es'] as $lg) {
                $velho = (string)($atual['texto_' . $lg] ?? '');
                $velho = trim((string)preg_replace(['/\s*<!-- ml423 -->.*?<!-- \/ml423 -->/s', '/\s*<!-- ml424 -->.*?<!-- \/ml424 -->/s'], '', $velho));
                $dados['texto_' . $lg] = ($velho === '' ? '' : $velho . "\n") . "<!-- ml424 -->\n" . $d['texto'][$lg] . "\n<!-- /ml424 -->";
                if ($atual === null || trim((string)($atual['titulo_' . $lg] ?? '')) === '') $dados['titulo_' . $lg] = $d['titulo'][$lg];
            }
            if ($simular) { $rel['ajuda'][$transacao] = $atual === null ? 'será incluída' : 'será atualizada (bloco ml423 trocado pelo ml424)'; continue; }
            if ($atual === null) { MigracaoCrud::inserir($u, 'ajuda_transacoes', ['transacao' => $transacao] + $dados + ['ativo' => 1]); $rel['ajuda'][$transacao] = 'incluída'; }
            else {
                $chave = ['transacao' => (string)$atual['transacao']];
                foreach (['id', 'codajuda', 'codigo', 'codajudatransacao'] as $pk) if (isset($atual[$pk]) && $atual[$pk] !== '') { $chave = [$pk => $atual[$pk]]; break; }
                MigracaoCrud::atualizar($u, 'ajuda_transacoes', $chave, $dados + ['ativo' => 1]);
                $rel['ajuda'][$transacao] = 'atualizada';
            }
        }
    } catch (Throwable $e) { $rel['ok'] = false; $rel['ajuda']['erro'] = $e->getMessage(); }
}

echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
