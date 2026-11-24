<?php
declare(strict_types=1);

/*
 * Fatos de um registro para a barra de etapas ("fluxo do estágio" tipo entrega do Mercado Livre) — onda 1 da Revisão G,
 * itens 18.4, 47.1, 65.1, 82.1, 98.1, 113.1, 128.1, 154 e 164 (24/11/2026). As etapas de cada módulo e os textos ficam em
 * assets/js/fluxo-etapas.js; aqui só o que não está na linha do grid: evidências no GED, risco do item, condicionantes,
 * planos de ação, situação do requisito legal, critérios do fornecedor, dados da ocorrência.
 * Só leitura (sp_crud_select com filtro exato, ou SELECT em view). Cada fato que não puder ser lido volta null
 * ("não sei") — a barra não marca a etapa como feita nem como pendente.
 * GET: modulo = legal|licencas|medicao|controles|treinamentos|infraestrutura|fornecedor|rnc; id; leg e unid (legal).
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) { http_response_code(401); echo json_encode(['ok' => false]); exit; }

$modulo = strtolower(trim((string)($_GET['modulo'] ?? '')));
$id = max(0, (int)($_GET['id'] ?? 0));
$leg = max(0, (int)($_GET['leg'] ?? 0));
$unid = max(0, (int)($_GET['unid'] ?? 0));
if (!in_array($modulo, ['legal', 'licencas', 'medicao', 'controles', 'treinamentos', 'infraestrutura', 'fornecedor', 'rnc'], true)
    || ($modulo === 'legal' ? ($leg < 1 || $unid < 1) : $id < 1)) { http_response_code(400); echo json_encode(['ok' => false]); exit; }

require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';

try {
    $env = GreenPortalDatabase::env();
    $pdo = TenantContext::connect($env, $_SESSION);
    if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { http_response_code(401); echo json_encode(['ok' => false]); exit; }

    /** Linhas de uma tabela/view com filtros de igualdade (sp_crud_select); null quando não dá para ler. */
    $ler = static function (string $fonte, array $iguais, int $max = 500) use ($pdo): ?array {
        try {
            $f = [];
            foreach ($iguais as $c => $v) $f[$c] = ['value' => (string)$v, 'exact' => true];
            $s = $pdo->prepare('CALL sp_crud_select(:t, :f, NULL, :n, 0)');
            $s->bindValue('t', $fonte);
            $s->bindValue('f', json_encode($f ?: new stdClass(), JSON_UNESCAPED_UNICODE));
            $s->bindValue('n', $max, PDO::PARAM_INT);
            $s->execute();
            $r = $s->fetchAll(PDO::FETCH_ASSOC);
            $s->closeCursor();
            // Confere a igualdade (o filtro do sp_crud_select pode ser "contém" em alguns bancos).
            return array_values(array_filter($r, static function (array $l) use ($iguais): bool {
                $l = array_change_key_case($l, CASE_LOWER);
                foreach ($iguais as $c => $v) if ((string)($l[strtolower($c)] ?? '') !== (string)$v) return false;
                return true;
            }));
        } catch (Throwable) { return null; }
    };
    $col = static fn(array $l, string $c) => array_change_key_case($l, CASE_LOWER)[strtolower($c)] ?? null;
    $vazio = static fn($v): bool => $v === null || trim((string)$v) === '' || (string)$v === '0' || str_starts_with((string)$v, '0000-00-00');

    /** Quantidade de arquivos do registro no GED (mesma regra de api/ged_contagens.php); null sem permissão ou sem acesso. */
    $arquivos = static function (string $tipo, string $pasta, int $registro) use ($pdo, $env): ?int {
        try {
            require_once dirname(__DIR__, 2) . '/classes/GedPermissaoTipo.php';
            require_once dirname(__DIR__, 2) . '/classes/GedStorageService.php';
            if (empty(GedPermissaoTipo::obter($tipo)['pode_visualizar'])) return null;
            $st = new GedStorageService($pdo, $env, $_SESSION);
            $dir = $st->legacyRoot() . DIRECTORY_SEPARATOR . $st->legacyDatabase() . DIRECTORY_SEPARATOR . $pasta . DIRECTORY_SEPARATOR . $registro;
            if (!is_dir($dir)) return 0;
            $n = 0;
            foreach (new DirectoryIterator($dir) as $a) if (!$a->isDot() && $a->isFile()) $n++;
            return $n;
        } catch (Throwable) { return null; }
    };
    $risco = static function (string $area, int $item) use ($ler, $col): ?string {
        $r = $ler('vw_ml_risco_itens', ['AREA' => $area, 'ITEM_ID' => $item], 5);
        if ($r === null) return null;
        return $r ? (string)($col($r[0], 'CLASSE_TEXTO') ?: $col($r[0], 'CLASSE')) : '';
    };
    /** Planos de ação: [total, concluídos (STATUS = 100)]. */
    $planos = static function (?array $linhas) use ($col): ?array {
        if ($linhas === null) return null;
        $ok = 0;
        foreach ($linhas as $l) if ((int)$col($l, 'STATUS') >= 100) $ok++;
        return [count($linhas), $ok];
    };

    $f = [];
    switch ($modulo) {
        case 'legal':
            $sit = $ler('legislacaoxsituacao', ['CODLEGISLACAO' => $leg, 'codunidade' => $unid], 5);
            $s = $sit[0] ?? null;
            $f['avaliada'] = $sit === null ? null : ($s !== null && !$vazio($col($s, 'atendido')));
            $f['atendido'] = $s !== null ? strtoupper(trim((string)$col($s, 'atendido'))) : '';
            $f['responsavel'] = $sit === null ? null : ($s !== null && !$vazio($col($s, 'CODUSUARIO')));
            $f['proxima'] = $sit === null ? null : ($s !== null && !$vazio($col($s, 'data_de_vencimento_avaliacao')));
            $chk = $ler('legislacaoxchecklist', ['codlegislacao' => $leg, 'codunidade' => $unid], 500);
            $f['checklist'] = $chk === null ? null : count(array_filter($chk, static fn($l) => !$vazio($col($l, 'ATENDE'))));
            // Condicionantes: das licenças vinculadas ao requisito nesta unidade.
            $lic = $ler('legislacaoxlicencas', ['CODLEGISLACAO' => $leg, 'codunidade' => $unid], 200);
            if ($lic === null) { $f['condicionantes'] = null; $f['condicionantes_atendidas'] = null; }
            else {
                $tot = 0; $at = 0; $falhou = false;
                foreach ($lic as $l) {
                    $c = $ler('li_condicionantes', ['CODLICENCA' => (int)$col($l, 'CODLICENCA')], 500);
                    if ($c === null) { $falhou = true; break; }
                    $tot += count($c);
                    foreach ($c as $x) if (!$vazio($col($x, 'ATENDIDO'))) $at++;
                }
                $f['condicionantes'] = $falhou ? null : $tot;
                $f['condicionantes_atendidas'] = $falhou ? null : $at;
            }
            $codSit = $s !== null ? (int)$col($s, 'CODSITUACAO') : 0;
            $f['planos'] = $codSit > 0 ? $planos($ler('planodeacao', ['codsituacao' => $codSit], 200)) : ($sit === null ? null : [0, 0]);
            break;

        case 'licencas':
            $l = ($ler('li_licencas', ['CODLICENCA' => $id], 2) ?? [null])[0];
            $f['protocolo'] = $l === null ? null : in_array(strtoupper(trim((string)$col($l, 'emprotocolo'))), ['S', 'SIM'], true);
            $f['protocolado_em'] = $l === null ? null : !$vazio($col($l, 'protocolado_em'));
            $f['arquivos'] = $arquivos('licencas', 'licencas', $id);
            $f['risco'] = $risco('LICENCAS', $id);
            break;

        case 'medicao':
            $m = ($ler('mm_monitoramento', ['CODMONITORAMENTO' => $id], 2) ?? [null])[0];
            if ($m === null) { $f['cronograma'] = null; $f['equipamento'] = null; }
            else {
                $cr = false;
                foreach (['janeiro', 'fevereiro', 'marco', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'] as $mes) if (!$vazio($col($m, 'cronograma_' . $mes))) { $cr = true; break; }
                $f['cronograma'] = $cr;
                $f['equipamento'] = !$vazio($col($m, 'CODEQUIPAMENTO'));
            }
            $f['risco'] = $risco('MEDICAO', $id);
            $f['planos'] = $planos($ler('mm_planodeacao', ['codmonitoramento' => $id], 200));
            break;

        case 'controles':
            $d = ($ler('documentos', ['CODDOCUMENTO' => $id], 2) ?? [null])[0];
            $ged = $arquivos('controles', 'documentos', $id);
            $f['arquivos'] = $ged === null && $d === null ? null : (int)$ged + ($d !== null && !$vazio($col($d, 'ARQUIVO')) ? 1 : 0);
            $f['risco'] = $risco('CONTROLES', $id);
            break;

        case 'treinamentos':
            $f['arquivos'] = $arquivos('treinamentos', 'treinamentos', $id);
            $f['risco'] = $risco('TREINAMENTOS', $id);
            break;

        case 'infraestrutura':
            $f['arquivos'] = $arquivos('infraestrutura', 'infraestrutura', $id);
            $f['risco'] = $risco('INFRAESTRUTURA', $id);
            break;

        case 'fornecedor':
            $fo = ($ler('fo_fornecedores', ['codfornecedor' => $id], 2) ?? [null])[0];
            $f['responsavel'] = $fo === null ? null : !$vazio($col($fo, 'codusuario_responsavel_fornecedor'));
            $f['proxima'] = $fo === null ? null : !$vazio($col($fo, 'data_da_proxima_avaliacao'));
            $f['avaliado'] = $fo === null ? null : !$vazio($col($fo, 'data_da_avaliacao'));
            $tipos = $ler('fo_fornecedoresxtipofornecedores', ['codfornecedor' => $id], 100);
            $f['tipos'] = $tipos === null ? null : count($tipos);
            $f['auditoria'] = $tipos === null ? null : count(array_filter($tipos, static fn($t) => !$vazio($col($t, 'fornecedoraprovadoauditoria'))));
            $cri = $ler('fo_fornecedorxcriterios', ['codfornecedor' => $id], 2000);
            if ($cri === null) { $f['criterios'] = null; }
            else {
                $ativos = array_filter($cri, static fn($c) => !in_array(strtoupper(trim((string)$col($c, 'situacao'))), ['NA', 'NÃO ENVIAR AO FORNECEDOR'], true));
                $f['criterios'] = count($ativos);
                $f['com_validade'] = count(array_filter($ativos, static fn($c) => !$vazio($col($c, 'datadevalidade')) || stripos((string)$col($c, 'situacao'), 'sem validade') !== false));
                $f['enviados'] = count(array_filter($ativos, static fn($c) => strtoupper(trim((string)$col($c, 'enviadoodocumento'))) === 'SIM'));
                $f['aprovados'] = count(array_filter($ativos, static fn($c) => stripos((string)$col($c, 'situacao'), 'Aprovado') === 0));
            }
            $f['risco'] = $risco('HOMOLOGACAO', $id);
            $f['planos'] = $planos($ler('fo_planodeacao', ['codfornecedor' => $id], 200));
            break;

        case 'rnc':
            $o = ($ler('nc_indicacao_ocorrencia', ['CODOCORRENCIA' => $id], 2) ?? [null])[0];
            if ($o !== null) {
                foreach (['CODUSUARIO_GESTOR' => 'gestor', 'CODTIPONAOCONFORMIDADE' => 'classificada', 'PROVIDENCIA' => 'acao_imediata', 'ANALISE_ABRANGENCIA' => 'abrangencia',
                    'CODUSUARIO_RESPONSAVEL' => 'responsavel', 'ANALISE_DA_CAUSA' => 'causa', 'VERIFICACAO_DA_EFICACIA' => 'eficacia'] as $c => $k) $f[$k] = !$vazio($col($o, $c));
                $f['etapa'] = (string)$col($o, 'ETAPA');
                $f['situacao'] = (string)$col($o, 'SITUACAO');
            } else $f['etapa'] = null;
            $f['planos'] = $planos($ler('nc_planodeacao', ['CODOCORRENCIA' => $id], 500));
            break;
    }
    session_write_close();
    echo json_encode(['ok' => true, 'fatos' => $f], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('fluxo_etapas: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
