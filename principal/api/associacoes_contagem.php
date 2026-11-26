<?php
declare(strict_types=1);

/*
 * Contagem das associações de um registro (26/11/2026) — números nos botões de associação e o resumo
 * "Associações: x de y" na barra de etapas. Uma chamada por linha selecionada.
 * GET modulo=controles&id=<CODDOCUMENTO>  →  {ok, itens: [{chave, n}], responsaveis: n|null}
 * Leitura só por sp_crud_select (tabelas de ligação) e pela view vw_resp_acesso_<área> (responsáveis: no registro,
 * por atribuição e por regra — as mesmas pessoas da janela Responsáveis).
 */
require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';
require_once dirname(__DIR__, 2) . '/classes/IdiomaService.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=utf-8');
$json = static function (array $d, int $s = 200): never { http_response_code($s); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.'])], 401);

$MODULOS = [
    'controles' => [
        'transacao' => 'CONTROLE_DOCUMENTOS', 'area' => 'CONTROLES', 'tabela' => 'documentos', 'chave' => 'CODDOCUMENTO',
        'ligacoes' => [
            'unidades' => ['documentosxunidades', 'CODDOCUMENTO', 'CODUNIDADE'],
            'atividades' => ['documentosxatividades', 'CODDOCUMENTO', 'CODATIVIDADE'],
            'aspectos' => ['documentosxaspectos', 'CODDOCUMENTO', 'CODASPECTO'],
            'impactos' => ['documentosximpactos', 'CODDOCUMENTO', 'CODIMPACTO'],
            'perigos' => ['documentosxperigos', 'CODDOCUMENTO', 'CODPERIGO'],
            'riscos' => ['documentosxriscos', 'CODDOCUMENTO', 'CODRISCO'],
            'palavras_chaves' => ['documentosxpalavraschave', 'coddocumento', 'codpalavrachave'],
            'aspectos_sociais' => ['lais_documentosxaspectos', 'CODDOCUMENTO', 'CODASPECTOSOCIAL'],
            'impactos_sociais' => ['lais_documentosximpactos', 'CODDOCUMENTO', 'CODIMPACTOSOCIAL'],
        ],
    ],
    // Fase 1 (26/11/2026): Treinamento e Infraestrutura — chaves iguais às do data-ml-relacionamento dos botões.
    'treinamentos' => [
        'transacao' => 'MODULOLEGAL_TREINAMENTOS', 'area' => 'TREINAMENTOS',
        'ligacoes' => [
            'treinamentos_unidades' => ['ml_treinamentosxunidades', 'CODTREINAMENTO', 'CODUNIDADE'],
            'treinamentos_palavras_chaves' => ['ml_treinamentosxpalavraschave', 'codtreinamento', 'codpalavrachave'],
        ],
    ],
    'infraestrutura' => [
        'transacao' => 'MODULOLEGAL_INFRAESTRUTURA', 'area' => 'INFRAESTRUTURA',
        'ligacoes' => [
            'infraestrutura_unidades' => ['ml_infraestruturaxunidades', 'CODINFRAESTRUTURA', 'CODUNIDADE'],
            'infraestrutura_palavras_chaves' => ['ml_infraestruturaxpalavraschave', 'codinfraestrutura', 'codpalavrachave'],
            'infraestrutura_atributos' => ['ml_infraestruturaxatributos', 'codinfraestrutura', 'codatributo'],
        ],
    ],
    // Fase 2 (26/11/2026): Licenças — chaves = sufixo do data-license-* do botão; sem 3º campo = conta as linhas.
    'licencas' => [
        'transacao' => 'LICENCAS', 'area' => 'LICENCAS',
        'ligacoes' => [
            'condicionantes' => ['li_condicionantes', 'CODLICENCA', null],
            'palavras-chave' => ['li_licencasxpalavraschave', 'codlicenca', 'codpalavrachave'],
            'requisitos' => ['vw_modulolegal_requisitos_licenca_grid', 'CODLICENCA', null],
            'responsabilidades' => ['vw_responsabilidade_licencas', 'CODLICENCA', null],
            'taxas' => ['li_taxas', 'CODLICENCA', null],
            // Arquivos do GED (mesmas pastas da lista de arquivos): tipo e pasta do GED
            'arquivos' => ['__ged', 'licencas', 'licencas'],
        ],
    ],
    // Fase 3 (26/11/2026): Requisitos legais — a linha é legislação × unidade; as telas de associação filtram por
    // CODLEGISLACAO e CODUNIDADE (mesmas views das janelas). Chaves = data-open-requisito-popup do botão.
    'requisitos' => [
        'transacao' => 'REQUISITOS_LEGAIS', 'area' => null, 'parametros' => ['CODLEGISLACAO' => 'leg', 'CODUNIDADE' => 'unid'],
        'ligacoes' => [
            'licencas' => ['vw_modulolegal_requisitos_licencas_grid', null, null],
            'medicaomonitoramento' => ['vw_modulolegal_requisitos_medicao_grid', null, null],
            'treinamentos' => ['vw_modulolegal_requisitos_treinamentos_grid', null, null],
            'controles' => ['vw_modulolegal_requisitos_controles_grid', null, null],
            'infraestrutura' => ['vw_modulolegal_requisitos_infraestrutura_grid', null, null],
            'homologacao' => ['vw_modulolegal_requisitos_homologacao_grid', null, null],
        ],
    ],
    // Fase 3: Legislação (base compartilhada glegislacao_dbp; botões data-assoc-open). 'base' => 'legislacao' = lê pelo
    // LegislacaoAssociacoes::ler (mesma conexão das janelas de associação).
    'legislacao' => [
        'transacao' => 'LEGISLACAO', 'area' => null, 'base' => 'legislacao',
        'ligacoes' => [
            'urls' => ['vw_legislacao_assoc_contagem', 'CODLEGISLACAO', '#QTD_URLS'],
            'filhas' => ['vw_legislacao_assoc_contagem', 'CODLEGISLACAO', '#QTD_FILHAS'],
            'alteracoes' => ['legislacaoxalteracao', 'codlegislacao', null],
            'checklist' => ['legislacaoxpergunta', 'CODLEGISLACAO', 'CODPERGUNTA'],
            'palavras' => ['legislacaoxpalavraschave', 'codlegislacao', 'codpalavrachave'],
        ],
    ],
    // Fase 3: Correlacionamentos (linha = legislação × unidade; chave = nome da tela filha do botão data-context-url)
    'corr_ambiental' => [
        'transacao' => 'CORRELACIONAMENTO_AMBIENTAL', 'area' => null, 'parametros' => ['CODLEGISLACAO' => 'leg', 'codunidade' => 'unid'],
        'ligacoes' => [
            'modulolegal_correlacionamentoambiental_aspectos' => ['vw_modulolegal_correlacionamento_ambiental_aspectos', null, null],
            'modulolegal_correlacionamentoambiental_atividades' => ['vw_modulolegal_correlacionamento_ambiental_atividades', null, null],
            'modulolegal_correlacionamentoambiental_impactos' => ['vw_modulolegal_correlacionamento_ambiental_impactos', null, null],
            'modulolegal_correlacionamentoambiental_licencas' => ['vw_modulolegal_correlacionamento_ambiental_licencas', null, null],
            'modulolegal_correlacionamentoambiental_documentos' => ['vw_modulolegal_correlacionamento_ambiental_documentos', null, null],
        ],
    ],
    'corr_ocupacional' => [
        'transacao' => 'CORRELACIONAMENTO_OCUPACIONAL', 'area' => null, 'parametros' => ['CODLEGISLACAO' => 'leg', 'codunidade' => 'unid'],
        'ligacoes' => [
            'modulolegal_correlacionamentoocupacional_perigos' => ['vw_modulolegal_correlacionamento_ocupacional_perigos', null, null],
            'modulolegal_correlacionamentoocupacional_atividades' => ['vw_modulolegal_correlacionamento_ocupacional_atividades', null, null],
            'modulolegal_correlacionamentoocupacional_riscos' => ['vw_modulolegal_correlacionamento_ocupacional_riscos', null, null],
            'modulolegal_correlacionamentoocupacional_licencas' => ['vw_modulolegal_correlacionamento_ocupacional_licencas', null, null],
            'modulolegal_correlacionamentoocupacional_documentos' => ['vw_modulolegal_correlacionamento_ocupacional_documentos', null, null],
        ],
    ],
    'corr_social' => [
        'transacao' => 'CORRELACIONAMENTO_SOCIAL', 'area' => null, 'parametros' => ['CODLEGISLACAO' => 'leg', 'codunidade' => 'unid'],
        'ligacoes' => [
            'modulolegal_correlacionamentosocial_aspectos' => ['vw_modulolegal_correlacionamento_social_aspectos', null, null],
            'modulolegal_correlacionamentosocial_impactos' => ['vw_modulolegal_correlacionamento_social_impactos', null, null],
            'modulolegal_correlacionamentosocial_documentos' => ['vw_modulolegal_correlacionamento_social_documentos', null, null],
        ],
    ],
];
$modulo = strtolower(trim((string)($_GET['modulo'] ?? '')));
$id = max(0, (int)($_GET['id'] ?? 0));
if (!isset($MODULOS[$modulo])) $json(['ok' => false], 400);
$m = $MODULOS[$modulo];
// Telas cuja linha tem chave composta (ex.: legislação × unidade): filtros vindos dos parâmetros.
$filtrosLinha = [];
foreach (($m['parametros'] ?? []) as $colunaFiltro => $param) { $v = max(0, (int)($_GET[$param] ?? 0)); if ($v < 1) $json(['ok' => false], 400); $filtrosLinha[$colunaFiltro] = $v; }
if (!$filtrosLinha && $id < 1) $json(['ok' => false], 400);
try {
    $env = GreenPortalDatabase::env();
    $pdo = TenantContext::connect($env, $_SESSION);
    if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $json(['ok' => false], 401);
    if (empty(PermissaoTransacao::obter($m['transacao'])['pode_visualizar'])) $json(['ok' => false], 403);
    $lerVarios = static function (string $fonte, array $iguais) use ($pdo): ?array {
        try {
            $f = []; foreach ($iguais as $c => $v) $f[$c] = ['value' => (string)$v, 'exact' => true];
            $s = $pdo->prepare('CALL sp_crud_select(:t, :f, NULL, 10000, 0)');
            $s->execute(['t' => $fonte, 'f' => json_encode($f)]);
            $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
            // confere a igualdade (em alguns bancos o filtro é "contém")
            return array_values(array_filter($r, static function (array $l) use ($iguais): bool { $l = array_change_key_case($l, CASE_LOWER); foreach ($iguais as $c => $v) if ((string)($l[strtolower($c)] ?? '') !== (string)$v) return false; return true; }));
        } catch (Throwable) { return null; }
    };
    if (($m['base'] ?? '') === 'legislacao') {
        require_once dirname(__DIR__, 2) . '/classes/LegislacaoAssociacoes.php';
        $lerVarios = static function (string $fonte, array $iguais): ?array {
            try {
                $r = LegislacaoAssociacoes::ler($fonte, $iguais, '', 10000);
                return array_values(array_filter($r, static function (array $l) use ($iguais): bool { $l = array_change_key_case($l, CASE_LOWER); foreach ($iguais as $c => $v) if ((string)($l[strtolower($c)] ?? '') !== (string)$v) return false; return true; }));
            } catch (Throwable) { return null; }
        };
    }
    $ler = static fn(string $fonte, string $campo, int $valor): ?array => $lerVarios($fonte, [$campo => $valor]);
    $itens = [];
    foreach ($m['ligacoes'] as $chave => [$tabela, $campoReg, $campoItem]) {
        if ($tabela === '__ged') {
            try {
                require_once dirname(__DIR__, 2) . '/classes/GedPermissaoTipo.php';
                require_once dirname(__DIR__, 2) . '/classes/GedStorageService.php';
                $n = empty(GedPermissaoTipo::obter($campoReg)['pode_visualizar']) ? null : count((new GedStorageService($pdo, $env, $_SESSION))->list($campoItem, $id, ''));
            } catch (Throwable) { $n = null; }
            $itens[] = ['chave' => $chave, 'n' => $n];
            continue;
        }
        $linhas = $campoReg === null ? $lerVarios($tabela, $filtrosLinha) : $ler($tabela, $campoReg, $id);
        if ($linhas === null) { $itens[] = ['chave' => $chave, 'n' => null]; continue; }
        if ($campoItem === null) { $itens[] = ['chave' => $chave, 'n' => count($linhas)]; continue; }
        // '#COLUNA' = a fonte já traz a quantidade pronta (ex.: vw_legislacao_assoc_contagem)
        if ($campoItem[0] === '#') { $itens[] = ['chave' => $chave, 'n' => $linhas ? (int)(array_change_key_case($linhas[0], CASE_LOWER)[strtolower(substr($campoItem, 1))] ?? 0) : 0]; continue; }
        $dist = [];
        foreach ($linhas as $l) { $v = (string)(array_change_key_case($l, CASE_LOWER)[strtolower($campoItem)] ?? ''); if ($v !== '' && $v !== '0') $dist[$v] = true; }
        $itens[] = ['chave' => $chave, 'n' => count($dist)];
    }
    $resp = empty($m['area']) || $id < 1 ? null : $ler('vw_resp_acesso_' . strtolower($m['area']), 'REGISTRO_ID', $id);
    $pessoas = null;
    if ($resp !== null) { $pessoas = []; foreach ($resp as $l) { $u = (int)(array_change_key_case($l, CASE_LOWER)['codusuario_acesso'] ?? 0); if ($u > 0) $pessoas[$u] = true; } $pessoas = count($pessoas); }
    $json(['ok' => true, 'itens' => $itens, 'responsaveis' => $pessoas]);
} catch (Throwable $e) {
    error_log('associacoes_contagem: ' . $e->getMessage());
    $json(['ok' => false], 500);
}
