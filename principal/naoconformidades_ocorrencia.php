<?php
declare(strict_types=1);

/*
 * Não Conformidades › Ocorrência (c491, migração 321) — tela única da ocorrência, como o formulário do legado
 * (formulario/nc_formulario.php): abas Ocorrência, Avaliação do gestor, Análise da causa e plano de ação, Verificação da
 * eficácia e Histórico. Cada aba só fica editável para quem age naquela etapa (NaoConformidadeWorkflow).
 * Leitura por views (vw_nc_*), gravação por procedures (sp_nc_*), com as mesmas validações do legado.
 * Anexos (c495): botões Anexos (cabeçalho), Evidências da análise e arquivos de cada ação abrem a Gestão de arquivos nas
 * pastas do legado (GED tipos ncocorrencia, ncanalise, ncplano). Passagens de etapa avisam os envolvidos em Home › Alertas.
 * Uso: ?codigo=N[&aba=gestor|analise|eficacia|historico]   ·   nova: ?tipo=3
 * Gravam a NC da Empresa (tipo 3) e, desde c501, a do Fornecedor (tipo 1: fornecedor, lote, quantidade, retenção, devolução e
 * classificação do fornecedor; usuário de fornecedor só age na análise quando é o responsável) e, desde c504, a de Planejamento (tipo 5:
 * envio direto ao Plano de Ação, o gestor monta as ações sem causas, o responsável de cada ação atualiza o status; avança sozinha).
 * Desde c510, a do Cliente (tipo 2: mesmo fluxo da Empresa, com cliente, unidade do cliente, produto, documento, reclamação, tampa, lata,
 * procedência e segurança de alimentos; os mais usados são obrigatórios no envio) e, desde c515, a de Assistência Técnica (tipo 6: o emitente,
 * que pode ser fornecedor externo, monta as ações Em criação e envia direto ao Plano de Ação; emitente e gestor mantêm as ações; o responsável
 * de cada ação atualiza o status; com todas a 100% avança como no Planejamento).
 */

require_once dirname(__DIR__) . '/classes/NaoConformidadeWorkflow.php';
require_once dirname(__DIR__) . '/classes/AuditService.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeAnaliseIa.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeEficaciaIa.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeConfig.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeTextos.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
[$pdo, $uid, $permissoes] = NaoConformidadeWorkflow::iniciar();
$W = NaoConformidadeWorkflow::class;
$e = [$W, 'e'];
$admin = $W::admin();
$podeGravar = false;
foreach ($permissoes as $p) if (!empty($p['pode_editar']) || !empty($p['pode_incluir'])) $podeGravar = true;
$tiposComFluxo = [3, 1, 5, 2, 6, 4]; // 3 = Empresa (c491), 1 = Fornecedor (c501), 5 = Planejamento (c504), 2 = Cliente (c510), 6 = Assistência Técnica (c515), 4 = Acidente/Incidente (c523, fluxo da Empresa)
$externo = $W::fornecedorExterno();

$codigo = filter_var($_GET['codigo'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$tipoNovo = filter_var($_GET['tipo'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$row = [];
if ($codigo) {
    $row = $W::ocorrencia($pdo, $codigo);
    if (!$row) $W::falhar(404, ncT('oc.nao_encontrada'));
    if (!$W::podeVer($pdo, $uid, $codigo, $permissoes)) $W::falhar(403, ncT('oc.nao_participa'));
} else {
    if (!$tipoNovo) $tipoNovo = 3;
    $menuNova = $W::MENU_NOVA[$tipoNovo] ?? '';
    // Assistência Técnica: o fornecedor externo também cria (como no legado; decisão do usuário 22/10/2026)
    if (($externo && $tipoNovo !== 6) || !in_array($tipoNovo, $tiposComFluxo, true) || $menuNova === '' || empty($permissoes[$menuNova]['pode_incluir'])) $W::falhar(403, ncT('oc.nao_inclui_tipo'));
}
$R = array_change_key_case($row, CASE_UPPER);
// Revisão G item 148: nova ocorrência aberta de outra tela (ex.: plano de ação do fornecedor na Homologação) chega com campos
// preenchidos; só estes campos, e só na inclusão. Nada é gravado até o usuário salvar.
if (!$codigo && isset($_GET['prefill'])) {
    $pf = json_decode((string)$_GET['prefill'], true);
    if (is_array($pf)) foreach (['CODFORNECEDOR' => 20, 'IDENTIFICACAO' => 500, 'DESCRICAO' => 5000, 'CODUNIDADE' => 20, 'PRODUTO' => 500] as $k => $max)
        if (isset($pf[$k]) && is_scalar($pf[$k]) && trim((string)$pf[$k]) !== '') $R[$k] = mb_substr(trim((string)$pf[$k]), 0, $max);
}
$tipo = $codigo ? (int)($R['CODTIPONAOCONFORMIDADE'] ?? 0) : $tipoNovo;
$etapa = (string)($R['ETAPA'] ?? '');
$comFluxo = in_array($tipo, $tiposComFluxo, true) && $podeGravar;
$eu = static fn(string $col): bool => (int)($R[$col] ?? 0) === $uid;
// Usuário de fornecedor (legado: $_SESSION['FORNECEDOR'] = 'S'): ocorrência, avaliação do gestor e eficácia só para consulta;
// age só na análise da causa e no plano de ação quando é o responsável.
// Planejamento e Assistência Técnica: plano de ação sem causas e sem a etapa do gestor
$assistencia = $tipo === 6;
$planejamento = $tipo === 5 || $assistencia;
$externoAge = !$externo || $assistencia; // na Assistência Técnica o fornecedor externo age como emitente/gestor
$pode = [
    'ocorrencia' => $comFluxo && $externoAge && (!$codigo || (in_array($etapa, $W::ETAPAS_EMISSOR, true) && ($eu('CODUSUARIO_EMISSOR') || $admin))),
    'gestor' => $comFluxo && !$planejamento && !$externo && $codigo && $etapa === 'Com o Gestor' && ($eu('CODUSUARIO_GESTOR') || $admin),
    // Planejamento (legado nc_planejamento): o gestor monta o plano de ação; nos outros tipos, o responsável pela análise
    'analise' => $assistencia
        // Assistência Técnica: emitente monta as ações Em criação; no Plano de Ação emitente e gestor
        ? ($comFluxo && $codigo && ($W::etapaAnalise($etapa) || $etapa === 'Em criação') && ($eu('CODUSUARIO_EMISSOR') || $eu('CODUSUARIO_GESTOR') || ($admin && !$externo)))
        : ($comFluxo && $codigo && $W::etapaAnalise($etapa) && ($planejamento ? (($eu('CODUSUARIO_GESTOR') && !$externo) || ($admin && !$externo)) : ($eu('CODUSUARIO_RESPONSAVEL') || ($admin && !$externo)))),
    'eficacia' => $comFluxo && $externoAge && $codigo && (bool)preg_match('/^Avalia.*Eficacia$/u', $etapa) && ($eu('CODUSUARIO_GESTOR') || $admin),
];
$exigePrazo = $W::exigePrazoEficacia($pdo); // migração 339: parâmetro da Configuração do módulo
// Planejamento: o responsável de uma ação atualiza status e observação dela (decisão do usuário 21/10/2026; conferido na procedure)
$minhasAcoes = [];
if ($planejamento && $codigo && $comFluxo && $W::etapaAnalise($etapa)) {
    $sAcoes = $pdo->prepare('SELECT CODPLANOACAO FROM vw_nc_planodeacao WHERE CODOCORRENCIA = ? AND CODUSUARIO = ?');
    $sAcoes->execute([$codigo, $uid]); $minhasAcoes = array_map('intval', $sAcoes->fetchAll(PDO::FETCH_COLUMN)); $sAcoes->closeCursor();
}
$acaoPropria = !$pode['analise'] && $minhasAcoes !== [];

// ---------- Gravação (POST ?acao=...) ----------
if (isset($_GET['acao'])) {
    header('Content-Type: application/json; charset=utf-8');
    $acao = (string)$_GET['acao'];
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException(ncT('comum.use_post'));
        $W::exigirCsrf();
        $req = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($req)) throw new RuntimeException(ncT('comum.req_invalida'));
        $dados = is_array($req['dados'] ?? null) ? $req['dados'] : [];
        $sub = (string)($req['sub'] ?? 'salvar');
        $json = json_encode($dados, JSON_UNESCAPED_UNICODE);
        $ad = $admin ? 1 : 0;
        $exigir = static function (string $aba) use ($pode): void { if (!$pode[$aba]) throw new RuntimeException(ncT('oc.nao_altera_aba')); };
        // Migração 336: análise da causa com IA (só sugere; "aplicar" grava pelas procedures da tela, numa transação)
        if ($acao === 'ia') {
            $exigir('analise');
            if ($planejamento) throw new RuntimeException(ncT('oc.ia_sem_causas'));
            if (!NaoConformidadeConfig::sim($pdo, 'IA_ANALISE_CAUSA')) throw new RuntimeException(ncT('oc.ia_desligada'));
            $IA = NaoConformidadeAnaliseIa::class;
            $tiposCausa = $W::opcoes($pdo)['TIPOCAUSA'] ?? [];
            $causasAtuais = $W::listar($pdo, 'vw_nc_causas', $codigo, 'CODCAUSA');
            $planosAtuais = $W::listar($pdo, 'vw_nc_planodeacao', $codigo, 'CODPLANOACAO');
            $todos = $W::usuarios($pdo, array_column($planosAtuais, 'CODUSUARIO'));
            $responsaveis = $externo ? $W::responsaveisAcaoExterno($pdo, $uid, $codigo, $todos, array_column($planosAtuais, 'CODUSUARIO')) : $todos;
            $codanalise = (int)($dados['codanalise'] ?? 0);
            switch ($sub) {
                case 'solicitar':
                    $idioma = in_array($_SESSION['idioma'] ?? 'pt', ['pt', 'en', 'es'], true) ? (string)$_SESSION['idioma'] : 'pt';
                    $out = $IA::solicitar($pdo, $uid, $idioma, $codigo, $R, $causasAtuais, $planosAtuais, $tiposCausa, $permissoes);
                    $out['message'] = ncT('oc.ia_pedido');
                    AuditService::record($pdo, 'naoconformidades_ocorrencia', 'IA_SOLICITAR', 'SUCESSO', 'nc_ia_analises', (string)$codigo);
                    break;
                case 'consultar': $out = $IA::consultar($pdo, $uid, $codigo, $codanalise, $tiposCausa, $responsaveis, $causasAtuais); break;
                case 'aplicar':
                    $out = $IA::aplicar($pdo, $uid, $admin, $codigo, $codanalise, is_array($dados['itens'] ?? null) ? $dados['itens'] : [], $R, $causasAtuais, $tiposCausa, $responsaveis);
                    AuditService::record($pdo, 'naoconformidades_ocorrencia', 'IA_APLICAR', 'SUCESSO', 'nc_ia_analises.' . $codanalise, (string)$codigo);
                    $out['message'] = ncT('oc.ia_aplicada', ['resumo' => $out['resumo']]);
                    break;
                case 'descartar':
                    $IA::descartar($pdo, $uid, $codigo, $codanalise);
                    $out = ['message' => ncT('oc.ia_descartada')];
                    break;
                default: throw new RuntimeException(ncT('comum.acao_invalida'));
            }
            echo json_encode(['ok' => true, 'codigo' => $codigo] + $out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        // Migração 337: avaliação da eficácia com IA (parecer em segundo plano; o resultado continua sendo aplicado pelo gestor)
        if ($acao === 'iaeficacia') {
            $exigir('eficacia');
            if (!NaoConformidadeConfig::sim($pdo, 'IA_EFICACIA')) throw new RuntimeException(ncT('oc.iaef_desligada'));
            $EF = NaoConformidadeEficaciaIa::class;
            $planosAtuais = $W::listar($pdo, 'vw_nc_planodeacao', $codigo, 'CODPLANOACAO');
            $codanalise = (int)($dados['codanalise'] ?? 0);
            switch ($sub) {
                case 'solicitar':
                    $idioma = in_array($_SESSION['idioma'] ?? 'pt', ['pt', 'en', 'es'], true) ? (string)$_SESSION['idioma'] : 'pt';
                    $out = $EF::solicitar($pdo, $uid, $idioma, $codigo, $R, $W::listar($pdo, 'vw_nc_causas', $codigo, 'CODCAUSA'), $planosAtuais, $permissoes);
                    $out['message'] = ncT('oc.iaef_pedido')
                        . (!empty($out['arquivos']) ? ' ' . ncT('oc.iaef_arquivos', ['n' => $out['arquivos']]) : '')
                        . ' ' . ncT('oc.iaef_aviso');
                    AuditService::record($pdo, 'naoconformidades_ocorrencia', 'IA_EFICACIA_SOLICITAR', 'SUCESSO', 'nc_ia_analises', (string)$codigo);
                    break;
                case 'consultar': $out = $EF::consultar($pdo, $uid, $codigo, $codanalise, $planosAtuais); break;
                case 'aplicar':
                    $out = $EF::aplicar($pdo, $uid, $admin, $codigo, $codanalise, is_array($dados['itens'] ?? null) ? $dados['itens'] : [], $R, $exigePrazo);
                    AuditService::record($pdo, 'naoconformidades_ocorrencia', 'IA_EFICACIA_APLICAR', 'SUCESSO', 'nc_ia_analises.' . $codanalise, (string)$codigo);
                    break;
                case 'descartar':
                    $EF::descartar($pdo, $uid, $codigo, $codanalise);
                    $out = ['message' => ncT('oc.iaef_descartado')];
                    break;
                default: throw new RuntimeException(ncT('comum.acao_invalida'));
            }
            echo json_encode(['ok' => true, 'codigo' => $codigo] + $out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        switch ($acao) {
            case 'ocorrencia':
                $exigir('ocorrencia');
                // Migração 339: no envio, os campos que a Configuração do módulo exige para o tipo (mesma transação: falta algo, nada é gravado)
                $validarConfig = $sub === 'enviar' && (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_nc_config_validar_envio'")->fetchColumn();
                if ($validarConfig) $pdo->beginTransaction();
                try {
                    $r = $W::chamar($pdo, 'sp_nc_ocorrencia_salvar', [$codigo ?: null, $tipo, $json, $uid, $sub === 'enviar' ? 1 : 0, $ad]);
                    if ($validarConfig) { $W::chamar($pdo, 'sp_nc_config_validar_envio', [(int)($r['CODOCORRENCIA'] ?? $codigo)]); $pdo->commit(); }
                } catch (Throwable $x) { if ($validarConfig && $pdo->inTransaction()) $pdo->rollBack(); throw $x; }
                $msg = $sub === 'enviar' ? ($tipo === 6 ? ncT('oc.msg_plano_enviado') : ($tipo === 5 ? ncT('oc.msg_enviada_plano_gestor') : ncT('oc.msg_enviada_gestor'))) : ncT('oc.msg_gravada'); break;
            case 'gestor':
                $exigir('gestor');
                if (!in_array($sub, ['salvar', 'enviar', 'recusar', 'improcedente'], true)) throw new RuntimeException(ncT('comum.acao_invalida'));
                $r = $W::chamar($pdo, 'sp_nc_gestor_salvar', [$codigo, $json, $uid, $sub, $ad]);
                $msg = ['salvar' => ncT('oc.msg_avaliacao_gravada'), 'enviar' => ncT('oc.msg_enviada_resp'), 'recusar' => ncT('oc.msg_devolvida_emitente'), 'improcedente' => ncT('oc.msg_improcedente')][$sub]; break;
            case 'causa':
                $exigir('analise');
                $r = $sub === 'excluir'
                    ? $W::chamar($pdo, 'sp_nc_causa_excluir', [(int)($dados['CODCAUSA'] ?? 0), $codigo, $uid, $ad])
                    : $W::chamar($pdo, 'sp_nc_causa_salvar', [(int)($dados['CODCAUSA'] ?? 0) ?: null, $codigo, (int)($dados['CODTIPOCAUSA'] ?? 0), (string)($dados['DSCAUSA'] ?? ''), $uid, $ad]);
                $msg = $sub === 'excluir' ? ncT('oc.msg_causa_excluida') : ncT('oc.msg_causa_gravada'); break;
            case 'plano':
                if (!$pode['analise'] && !($acaoPropria && $sub !== 'excluir' && in_array((int)($dados['CODPLANOACAO'] ?? 0), $minhasAcoes, true))) $exigir('analise');
                if (!$pode['analise']) $dados = ['CODPLANOACAO' => $dados['CODPLANOACAO'] ?? 0, 'STATUS' => $dados['STATUS'] ?? '0', 'OBS' => $dados['OBS'] ?? ''];
                // Fornecedor externo (c519): responsável da ação só entre os usuários do próprio fornecedor e os da empresa que participam da NC
                elseif ($externo && $sub !== 'excluir' && (int)($dados['CODUSUARIO'] ?? 0) > 0) {
                    $permitidos = $W::responsaveisAcaoExterno($pdo, $uid, $codigo, $W::usuarios($pdo), array_column($W::listar($pdo, 'vw_nc_planodeacao', $codigo, 'CODPLANOACAO'), 'CODUSUARIO'));
                    if (!isset($permitidos[(string)(int)$dados['CODUSUARIO']])) throw new RuntimeException(ncT('oc.resp_externo'));
                }
                $json = json_encode($dados, JSON_UNESCAPED_UNICODE);
                $r = $sub === 'excluir'
                    ? $W::chamar($pdo, 'sp_nc_plano_excluir', [(int)($dados['CODPLANOACAO'] ?? 0), $codigo, $uid, $ad])
                    : $W::chamar($pdo, 'sp_nc_plano_salvar', [(int)($dados['CODPLANOACAO'] ?? 0) ?: null, $codigo, $json, $uid, $ad]);
                $msg = $sub === 'excluir' ? ncT('oc.msg_acao_excluida') : ncT('oc.msg_acao_gravada'); break;
            case 'analise':
                $exigir('analise');
                $r = $W::chamar($pdo, 'sp_nc_analise_salvar', [$codigo, $json, $uid, $sub === 'enviar' ? 1 : 0, $ad]);
                $msg = $sub === 'enviar' ? ncT('oc.msg_enviada_eficacia') : ncT('oc.msg_analise_gravada'); break;
            case 'eficacia':
                $exigir('eficacia');
                if (!in_array($sub, ['salvar', 'finalizar', 'recusar'], true)) throw new RuntimeException(ncT('comum.acao_invalida'));
                $r = $W::chamar($pdo, 'sp_nc_eficacia_salvar', [$codigo, $json, $uid, $sub, $exigePrazo ? 1 : 0, $ad]);
                $msg = ['salvar' => ncT('oc.msg_verif_gravada'), 'finalizar' => ncT('oc.msg_resultado_aplicado'), 'recusar' => ncT('oc.msg_devolvida_resp')][$sub]; break;
            default: throw new RuntimeException(ncT('comum.acao_invalida'));
        }
        AuditService::record($pdo, 'naoconformidades_ocorrencia', 'SALVAR', 'SUCESSO', 'nc_indicacao_ocorrencia.' . $acao . '.' . $sub, (string)($r['CODOCORRENCIA'] ?? $codigo));
        echo json_encode(['ok' => true, 'message' => $msg, 'codigo' => (int)($r['CODOCORRENCIA'] ?? $codigo)], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $x) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => $W::erro($x)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ---------- Leitura ----------
$op = $W::opcoes($pdo);
$campos = $W::campos($pdo, $tipo);
$tem = static fn(string $c): bool => in_array(strtoupper($c), $campos, true);
$usuarios = $W::usuarios($pdo, array_filter([(int)($R['CODUSUARIO_GESTOR'] ?? 0), (int)($R['CODUSUARIO_RESPONSAVEL'] ?? 0), (int)($R['CODUSUARIO_PRAZO_ANALISE'] ?? 0)]));
$causas = $codigo ? $W::listar($pdo, 'vw_nc_causas', $codigo, 'CODCAUSA') : [];
$planos = $codigo ? $W::listar($pdo, 'vw_nc_planodeacao', $codigo, 'CODPLANOACAO') : [];
$historico = $codigo ? $W::listar($pdo, 'vw_nc_historico', $codigo, 'CODHISTORICO DESC') : [];
// Responsável da ação: o fornecedor externo só vê os usuários do próprio fornecedor e os da empresa que participam da NC (c519)
$usuariosAcao = $externo ? $W::responsaveisAcaoExterno($pdo, $uid, $codigo, $usuarios, array_column($planos, 'CODUSUARIO')) : $usuarios;
$tipoNome = (string)($R['DSCTIPONAOCONFORMIDADE'] ?? ($op['TIPONC'][(string)$tipo] ?? ''));
if ($codigo) AuditService::record($pdo, 'naoconformidades_ocorrencia', 'ACESSAR', 'SUCESSO', 'nc_indicacao_ocorrencia', (string)$codigo);

$v = static fn(string $c): string => (string)($R[strtoupper($c)] ?? '');
$data = static fn(string $c, bool $hora = false): string => ($x = $v($c)) !== '' && !str_starts_with($x, '0000') ? substr(str_replace(' ', 'T', $x), 0, $hora ? 16 : 10) : '';
$opcoesHtml = static function (array $lista, string $atual, bool $vazio = true) use ($e): string {
    $h = $vazio ? '<option value=""></option>' : '';
    if ($atual !== '' && !isset($lista[$atual])) $h .= '<option value="' . $e($atual) . '" selected>' . (ctype_digit($atual) ? '#' : '') . $e($atual) . '</option>';
    foreach ($lista as $k => $t) $h .= '<option value="' . $e($k) . '"' . ((string)$k === $atual ? ' selected' : '') . '>' . $e($t) . '</option>';
    return $h;
};
$simNao = ['S' => ncT('comum.sim'), 'N' => ncT('comum.nao')];
$simNaoLongo = ['SIM' => ncT('comum.sim'), 'NÃO' => ncT('comum.nao')];
// Campos da aba Ocorrência: [rótulo, tipo, lista/opções, sempre (independe do tipo de NC), coluna da view]
$fornecedores = $op['FORNECEDOR'] ?? [];
$codForn = (string)($R['CODFORNECEDOR'] ?? '');
if ($codForn !== '' && $codForn !== '0' && !isset($fornecedores[$codForn])) $fornecedores[$codForn] = trim((string)($R['RAZAOSOCIAL'] ?? '') ?: '#' . $codForn) . ' ' . ncT('comum.desativado');
// Listas de distribuição: na Crown a NC de Fornecedor só oferece as da categoria Fornecedores e os outros tipos as demais (legado)
$listas = $op['LISTA'] ?? [];
if ($W::separaListasFornecedor($pdo)) {
    $daCategoria = $op['LISTA_FORN'] ?? [];
    $listaAtual = (string)($R['LISTADEDISTRIBUICAO'] ?? '');
    $listas = array_filter($listas, static fn($nome, $cod) => ((string)$cod === $listaAtual) || ($tipo === 1 ? isset($daCategoria[(string)$cod]) : !isset($daCategoria[(string)$cod])), ARRAY_FILTER_USE_BOTH);
}
$camposOcorrencia = [
    'CODUNIDADE' => [ncT('campo.codunidade'), 'select', $op['UNIDADE'] ?? [], true],
    'LISTADEDISTRIBUICAO' => [ncT('campo.listadedistribuicao'), 'select', $listas, true],
    'CODUSUARIO_GESTOR' => [ncT('campo.codusuario_gestor'), 'select', $usuarios, true],
    'DATA_OCORRENCIA' => [ncT('campo.data_ocorrencia'), 'datetime', null, true],
    'CODSETOR' => [ncT('campo.codsetor'), 'select', $op['SETOR'] ?? [], false],
    'CODAREA' => [ncT('campo.codarea'), 'select', $op['AREA'] ?? [], false],
    'CODTIPOOCORRENCIA' => [ncT('campo.codtipoocorrencia'), 'select', $tipo === 1 ? ($op['CLASSIF_FORN'] ?? []) : ($op['CLASSIFICACAO'] ?? []), false],
    'PARADANALINHA' => [ncT('campo.paradanalinha'), 'select', $simNao, false, 'CODTIPOOCORRENCIA'],
    'CODTIPOACAO' => [ncT('campo.codtipoacao'), 'select', $op['TIPOACAO'] ?? [], false],
    'CODORIGEMACAO' => [ncT('campo.codorigemacao'), 'select', $op['ORIGEM'] ?? [], false],
    'CODSIGLAGESTAO' => [ncT('campo.codsiglagestao'), 'select', $op['SISTEMA'] ?? [], false],
    // Tipo Fornecedor (campos de nc_tipo_de_naoconformidadexcampos, como no legado formulario/nc_indicacao_ocorrencia.php)
    'CODFORNECEDOR' => [ncT('campo.codfornecedor'), 'select', $fornecedores, false],
    'QTDENVOLVIDA' => [ncT('campo.qtdenvolvida'), 'short', null, false],
    'TAMANHOLOTEENVOLVIDO' => [ncT('campo.tamanholoteenvolvido'), 'short', null, false],
    'HOUVE_RETENCAO_PRODUTO' => [ncT('campo.houve_retencao_produto'), 'select', $simNao, false],
    'HAVERA_DEVOLUCAO_MATERIAL' => [ncT('campo.havera_devolucao_material'), 'select', $simNao, false],
    // Tipo Cliente (legado nc_cliente/nc_indicacao_ocorrencia.php; cadastros em Não Conformidades › Cadastros)
    'DSCCLIENTE' => [ncT('campo.dsccliente'), 'select', $op['CLIENTE'] ?? [], false],
    'CODUNIDADE_CLIENTE' => [ncT('campo.codunidade_cliente'), 'select', $op['UNIDADE_CLIENTE'] ?? [], false],
    'CODIGODOPRODUTO' => [ncT('campo.codigodoproduto'), 'select', $op['PRODUTO'] ?? [], false],
    'CODTIPODEDOCUMENTO' => [ncT('campo.codtipodedocumento'), 'select', $op['TIPODOC'] ?? [], false],
    'NRNDOCUMENTO' => [ncT('campo.nrndocumento'), 'short', null, false],
    'CODTIPODERECLAMACAO' => [ncT('campo.codtipodereclamacao'), 'select', $op['RECLAMACAO'] ?? [], false],
    'CODTIPODETAMPA' => [ncT('campo.codtipodetampa'), 'select', $op['TAMPA'] ?? [], false],
    'CODTAMANHODALATA' => [ncT('campo.codtamanhodalata'), 'select', $op['LATA'] ?? [], false],
    'PROCEDENCIA_CLIENTE' => [ncT('campo.procedencia_cliente'), 'select', ['Procedente' => ncT('valor.procedente'), 'Improcedente' => ncT('valor.improcedente')], false],
    'AFETASEGURANCAALIMENTOS' => [ncT('campo.afetasegurancaalimentos'), 'select', $simNaoLongo, false],
    'QTDDEVOLVIDA' => [ncT('campo.qtddevolvida'), 'short', null, false],
    'PASS_PELA_NANALISE_EFICACIA' => [ncT('campo.pass_pela_nanalise_eficacia'), 'select', $simNao, false],
    'IDENTIFICACAO' => [ncT('campo.identificacao'), 'text', null, false],
    'DESCRICAO' => [ncT('campo.descricao'), 'textarea', null, false],
    'PROVIDENCIA' => [ncT('campo.providencia'), 'textarea', null, false],
];
// Migração 339: campos que a Configuração do módulo exige no envio deste tipo ganham * no rótulo
try {
    $sObrig = $pdo->prepare('SELECT CAMPO FROM vw_nc_config_obrigatorios WHERE CODTIPONAOCONFORMIDADE = ?');
    $sObrig->execute([$tipo]);
    foreach ($sObrig->fetchAll(PDO::FETCH_COLUMN) as $cObrig) if (isset($camposOcorrencia[strtoupper((string)$cObrig)])) $camposOcorrencia[strtoupper((string)$cObrig)][0] .= ' *';
    $sObrig->closeCursor();
} catch (Throwable) {} // antes da migração 339
$campoHtml = static function (string $nome, array $def, string $atual, bool $editavel) use ($e, $opcoesHtml): string {
    [$rotulo, $tipoCampo, $lista] = $def;
    $id = 'f' . $nome; $dis = $editavel ? '' : ' disabled';
    $largura = in_array($tipoCampo, ['textarea', 'text'], true) ? 'col-12' : 'col-md-4';
    $h = '<div class="' . $largura . '"><label class="form-label" for="' . $id . '">' . $e($rotulo) . '</label>';
    if ($tipoCampo === 'select') $h .= '<select class="form-select form-select-sm" id="' . $id . '" name="' . $nome . '"' . $dis . '>' . $opcoesHtml((array)$lista, $atual) . '</select>';
    elseif ($tipoCampo === 'textarea') $h .= '<textarea class="form-control form-control-sm" rows="4" id="' . $id . '" name="' . $nome . '"' . $dis . '>' . $e($atual) . '</textarea>';
    elseif ($tipoCampo === 'datetime') $h .= '<input type="datetime-local" class="form-control form-control-sm" id="' . $id . '" name="' . $nome . '" value="' . $e($atual) . '"' . $dis . '>';
    elseif ($tipoCampo === 'short') $h .= '<input type="text" maxlength="100" class="form-control form-control-sm" id="' . $id . '" name="' . $nome . '" value="' . $e($atual) . '"' . $dis . '>';
    elseif ($tipoCampo === 'date') $h .= '<input type="date" class="form-control form-control-sm" id="' . $id . '" name="' . $nome . '" value="' . $e($atual) . '"' . $dis . '>';
    else $h .= '<input type="text" maxlength="500" class="form-control form-control-sm" id="' . $id . '" name="' . $nome . '" value="' . $e($atual) . '"' . $dis . '>';
    return $h . '</div>';
};
$valorCampo = static function (string $nome) use ($v, $data): string {
    // Cliente: o legado grava o nome com espaço no fim, 0 nas listas sem escolha e "NÃO" com a acentuação corrompida
    if (in_array($nome, ['CODUNIDADE_CLIENTE', 'CODIGODOPRODUTO', 'CODTIPODEDOCUMENTO', 'CODTIPODERECLAMACAO', 'CODTIPODETAMPA', 'CODTAMANHODALATA'], true)) return trim($v($nome)) === '0' ? '' : trim($v($nome));
    if ($nome === 'DSCCLIENTE') return trim($v($nome));
    if ($nome === 'AFETASEGURANCAALIMENTOS') { $x = strtoupper(trim($v($nome))); return $x === 'SIM' ? 'SIM' : ($x !== '' && $x[0] === 'N' ? 'NÃO' : ''); }
    return match ($nome) { 'CODUNIDADE' => $v('CODUNIDADE'), 'LISTADEDISTRIBUICAO' => $v('LISTADEDISTRIBUICAO'), 'DATA_OCORRENCIA' => $data('DATA_OCORRENCIA', true), default => $v($nome) };
};
// Migração 332: as listas oferecem só os itens ativos; o item desativado que a NC já usa continua aparecendo, marcado
$opTodos = $W::opcoes($pdo, true);
$listaDoCampo = ['CODUNIDADE' => 'UNIDADE', 'CODSETOR' => 'SETOR', 'CODAREA' => 'AREA', 'CODTIPOOCORRENCIA' => $tipo === 1 ? 'CLASSIF_FORN' : 'CLASSIFICACAO',
    'CODTIPOACAO' => 'TIPOACAO', 'CODORIGEMACAO' => 'ORIGEM', 'CODSIGLAGESTAO' => 'SISTEMA', 'DSCCLIENTE' => 'CLIENTE', 'CODUNIDADE_CLIENTE' => 'UNIDADE_CLIENTE',
    'CODIGODOPRODUTO' => 'PRODUTO', 'CODTIPODEDOCUMENTO' => 'TIPODOC', 'CODTIPODERECLAMACAO' => 'RECLAMACAO', 'CODTIPODETAMPA' => 'TAMPA', 'CODTAMANHODALATA' => 'LATA'];
foreach ($listaDoCampo as $campoLista => $nomeLista) {
    if (!isset($camposOcorrencia[$campoLista]) || !is_array($camposOcorrencia[$campoLista][2])) continue;
    $atualLista = $valorCampo($campoLista);
    if ($atualLista !== '' && !isset($camposOcorrencia[$campoLista][2][$atualLista]) && isset($opTodos[$nomeLista][$atualLista]))
        $camposOcorrencia[$campoLista][2][$atualLista] = $opTodos[$nomeLista][$atualLista] . ' ' . ncT('comum.desativado');
}
foreach ($causas as $causaAtual) {
    $tc = (string)($causaAtual['CODTIPOCAUSA'] ?? '');
    if ($tc !== '' && !isset($op['TIPOCAUSA'][$tc]) && isset($opTodos['TIPOCAUSA'][$tc])) $op['TIPOCAUSA'][$tc] = $opTodos['TIPOCAUSA'][$tc] . ' ' . ncT('comum.desativado');
}
$abaInicial = (string)($_GET['aba'] ?? '');
if (!in_array($abaInicial, ['ocorrencia', 'gestor', 'analise', 'eficacia', 'historico'], true)) {
    $abaInicial = 'ocorrencia';
    if ($etapa === 'Com o Gestor') $abaInicial = 'gestor';
    elseif ($W::etapaAnalise($etapa)) $abaInicial = 'analise';
    elseif (preg_match('/^Avalia.*Eficacia$/u', $etapa) || $etapa === 'Finalizado') $abaInicial = 'eficacia';
}
$badge = match (true) { $etapa === 'Finalizado' => 'text-bg-success', str_starts_with($etapa, 'Recusado') => 'text-bg-danger', $etapa === '' => 'text-bg-secondary', default => 'text-bg-warning' };
$status = [0 => '0%', 25 => '25%', 50 => '50%', 75 => '75%', 100 => '100%'];
$dataBR = static fn(?string $d): string => $d && !str_starts_with($d, '0000') ? date(IdiomaService::idioma() === 'en' ? 'm/d/Y' : 'd/m/Y', strtotime($d)) : '';
$acoesHistorico = ['CRIACAO' => ncT('hist.criacao'), 'ALTERACAO' => ncT('hist.alteracao'), 'ENVIO_GESTOR' => ncT('hist.envio_gestor'), 'REENVIO_GESTOR' => ncT('hist.reenvio_gestor'),
    'CORRECAO_EMITENTE' => ncT('hist.correcao_emitente'), 'APROVACAO_GESTOR' => ncT('hist.aprovacao_gestor'), 'RECUSA_GESTOR' => ncT('hist.recusa_gestor'), 'ALTERACAO_GESTOR' => ncT('hist.alteracao_gestor'),
    'ENVIO_RESPONSAVEL' => ncT('hist.envio_responsavel'), 'IMPROCEDENTE' => ncT('hist.improcedente'), 'ALTERACAO_ANALISE' => ncT('hist.alteracao_analise'),
    'CAUSA_INCLUIDA' => ncT('hist.causa_incluida'), 'CAUSA_ALTERADA' => ncT('hist.causa_alterada'), 'CAUSA_EXCLUIDA' => ncT('hist.causa_excluida'), 'PLANO_INCLUIDO' => ncT('hist.plano_incluido'),
    'PLANO_ALTERADO' => ncT('hist.plano_alterado'), 'PLANO_EXCLUIDO' => ncT('hist.plano_excluido'), 'ENVIO_EFICACIA' => ncT('hist.envio_eficacia'),
    'ALTERACAO_EFICACIA' => ncT('hist.alteracao_eficacia'), 'FINALIZADA_EFICAZ' => ncT('hist.finalizada_eficaz'), 'FINALIZADA_ENCERRADA' => ncT('hist.finalizada_encerrada'),
    'NAO_EFICAZ' => ncT('hist.nao_eficaz'), 'RECUSA_EFICACIA' => ncT('hist.recusa_eficacia'), 'MUDANCA_ETAPA' => ncT('hist.mudanca_etapa'), 'PLANO_DE_ACAO' => ncT('hist.plano_de_acao'),
    'PLANO_STATUS' => ncT('hist.plano_status'), 'FINALIZADA_SEM_EFICACIA' => ncT('hist.finalizada_sem_eficacia'),
    // Migração 334: ações da Administração de NC (Correção de ocorrência, Transferência, Painel de saneamento)
    'CORRECAO_ADMIN' => ncT('hist.correcao_admin'), 'ADMIN_REABRIR' => ncT('hist.admin_reabrir'), 'ADMIN_DEVOLVER' => ncT('hist.admin_devolver'),
    'ADMIN_ENCERRAR' => ncT('hist.admin_encerrar'), 'TRANSFERENCIA_ADMIN' => ncT('hist.transferencia_admin'), 'GESTOR_ATRIBUIDO_ADMIN' => ncT('hist.gestor_atribuido_admin'),
    // Migração 336: análise da causa com IA
    'IA_ANALISE_APLICADA' => ncT('hist.ia_analise_aplicada'), 'IA_ANALISE_DESCARTADA' => ncT('hist.ia_analise_descartada'),
    // Migração 337: avaliação da eficácia com IA
    'IA_EFICACIA_APLICADA' => ncT('hist.ia_eficacia_aplicada'), 'IA_EFICACIA_DESCARTADA' => ncT('hist.ia_eficacia_descartada')];
// Planejamento e Assistência Técnica vão direto ao Plano de Ação no envio (não passam pelo gestor)
if ($assistencia) { $acoesHistorico['ENVIO_GESTOR'] = ncT('hist.envio_assist'); $acoesHistorico['REENVIO_GESTOR'] = ncT('hist.reenvio_assist'); }
elseif ($planejamento) { $acoesHistorico['ENVIO_GESTOR'] = ncT('hist.envio_planej'); }
$cfg = ['codigo' => $codigo, 'tipo' => $tipo, 'csrf' => $W::csrf(), 'url' => basename(__FILE__)];
// Migração 336: análise da causa com IA (listas como pares [código, nome] para manter a ordem alfabética no navegador)
$iaAtiva = $codigo && $pode['analise'] && !$planejamento && NaoConformidadeConfig::sim($pdo, 'IA_ANALISE_CAUSA'); // 339: parâmetro
if ($iaAtiva) {
    $pares = static fn(array $l): array => array_map(static fn($k, $n) => [(string)$k, (string)$n], array_keys($l), array_values($l));
    $iaSituacao = NaoConformidadeAnaliseIa::situacao($pdo, $codigo, $uid);
    $iaPendente = $iaSituacao['pendente'];
    // Vindo do alerta "a sugestão da IA está pronta" (&ia=CODANALISE): a revisão abre sozinha
    $iaDoAlerta = ($abaInicial === 'analise' ? filter_var($_GET['ia'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : 0) ?: 0;
    $respNc = (string)($R['CODUSUARIO_RESPONSAVEL'] ?? '');
    $cfg['ia'] = ['pronta' => $iaSituacao['pronta'], 'abrir' => $iaDoAlerta && $iaDoAlerta === $iaSituacao['pronta'] ? $iaDoAlerta : 0, 'pendente' => (bool)$iaPendente, 'tipos' => $pares($op['TIPOCAUSA'] ?? []), 'responsaveis' => $pares($usuariosAcao),
        'responsavel_padrao' => isset($usuariosAcao[$respNc]) ? $respNc : '', 'prioridades' => NaoConformidadeAnaliseIa::PRIORIDADES, 'prioridades_rotulos' => NaoConformidadeAnaliseIa::prioridadesRotulos(),
        'causas' => array_map(static fn($c) => [(string)(int)$c['CODCAUSA'], mb_strimwidth((string)$c['DSCAUSA'], 0, 90, '…')], $causas),
        'analise_atual' => $v('ANALISE_DA_CAUSA') !== '', 'prevista' => $data('DATA_DE_CONCLUSAO_PREVISTA'), 'hoje' => date('Y-m-d')];
}
// Migração 337: avaliação da eficácia com IA (gestor na etapa de avaliação)
$iaEfAtiva = $codigo && $pode['eficacia'] && NaoConformidadeConfig::sim($pdo, 'IA_EFICACIA'); // 339: parâmetro
$iaEfPreenche = [];
if ($iaEfAtiva) {
    $iaEfSituacao = NaoConformidadeAnaliseIa::situacao($pdo, $codigo, $uid, NaoConformidadeEficaciaIa::TIPO);
    $iaEfPendente = $iaEfSituacao['pendente'];
    $iaEfDoAlerta = ($abaInicial === 'eficacia' ? filter_var($_GET['ia'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : 0) ?: 0;
    $cfg['iaef'] = ['pronta' => $iaEfSituacao['pronta'], 'abrir' => $iaEfDoAlerta && $iaEfDoAlerta === $iaEfSituacao['pronta'] ? $iaEfDoAlerta : 0, 'pendente' => (bool)$iaEfPendente,
        'verificacao_atual' => $v('VERIFICACAO_DA_EFICACIA') !== '', 'pareceres' => NaoConformidadeEficaciaIa::pareceres(), 'avaliacoes' => NaoConformidadeEficaciaIa::avaliacoes()];
    // Depois de "Levar para a verificação": resultado sugerido e motivo da devolução já preenchidos (o gestor aplica)
    $iaEf = filter_var($_GET['ia_ef'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    if ($iaEf) $iaEfPreenche = NaoConformidadeEficaciaIa::preenchimento($pdo, $uid, $codigo, $iaEf);
}
?><!doctype html>
<html lang="<?= $e(IdiomaService::idioma() === 'pt' ? 'pt-BR' : IdiomaService::idioma()) ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $codigo ? $e(ncT('oc.titulo_nc', ['n' => $v('NRANO') ?: $codigo])) : $e(ncT('oc.titulo_nova') . ' ' . $tipoNome) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2">
<link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2">
<link rel="stylesheet" href="../assets/css/app.css?v=5">
<?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?>
<?php require_once dirname(__DIR__) . '/components/grid_local.php'; greenPortalGridLocalHead(); /* c537: grids das abas no padrão do portal */ ?>
<?php /* 11/11/2026: botões de ação das abas no padrão da barra dos grids (ícone + dica, grupos rotulados), acima do formulário */
$ncBotao = static function (string $icone, string $titulo, string $classe, string $attrs, string $extra = '') use ($e): string {
    return '<button type="button" class="btn btn-sm ' . $classe . ' grid-crud-button" ' . $attrs . ' title="' . $e($titulo) . '" aria-label="' . $e($titulo) . '"><i class="bi ' . $icone . '"></i>' . $extra . '</button>';
}; ?>
<link rel="stylesheet" href="../assets/css/botoes-crud.css?v=4">
<style>
body{background:var(--bs-body-bg,#fff)}.nc-main{padding:.75rem 1rem}.nc-cab{display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start}
.nc-pessoas{font-size:.8rem;color:var(--bs-secondary-color)}.nc-pessoas b{color:var(--bs-body-color);font-weight:600}
.nc-subabas .nav-link{padding:.3rem .8rem;font-size:.8rem}.nc-barra{display:flex;gap:.4rem;flex-wrap:wrap;align-items:center;margin:0 0 .6rem}.nc-barra-frag{margin:0 0 .6rem}.nc-barra-frag .crud-toolbar{margin:0}.nc-aba{padding:.75rem 0}
.nc-tabela{font-size:.8rem}.nc-tabela td{vertical-align:top}.nc-leitura{background:#f6f8f7;border-radius:.5rem;padding:.5rem .75rem;font-size:.8rem}
#ncStatus:empty{display:none}
</style>
</head>
<body>
<main class="nc-main" data-nc>
  <span hidden data-help-transaction="nc_ocorrencia_<?= $e($abaInicial) ?>" data-nc-ajuda-aba></span><?php /* migração 356: chave da Ajuda (F1) da aba aberta */ ?>
  <header class="nc-cab">
    <div>
      <h5 class="mb-1"><i class="bi bi-exclamation-diamond me-2"></i><?= $codigo ? $e(ncT('oc.titulo_nc', ['n' => $v('NRANO') ?: $codigo])) : $e(ncT('oc.titulo_nova')) ?> <span class="text-muted fs-6"><?= $e($tipoNome) ?></span><?php
      // migração 364: demonstração animada (assets/js/ajuda.js, modo demonstração) de como criar a NC e enviar ao gestor
      // por fase: só para quem age na etapa atual (emitente, gestor, responsável pela análise, gestor na eficácia)
      $demoNc = $tipo !== 3 ? null : ($pode['ocorrencia'] ? ['nc-demo-empresa', ncT('demo.empresa')]
          : ($pode['gestor'] ? ['nc-demo-gestor', ncT('demo.gestor')]
          : ($pode['analise'] ? ['nc-demo-analise', ncT('demo.analise')]
          : ($pode['eficacia'] ? ['nc-demo-eficacia', ncT('demo.eficacia')] : null))));
      if ($demoNc): ?> <button type="button" class="btn btn-sm btn-link p-0 ms-2 align-baseline text-decoration-none nc-demo" data-ajuda-demo="<?= $e($demoNc[0]) ?>" title="<?= $e(ncT('demo.ver_como', ['acao' => $demoNc[1]])) ?>"><i class="bi bi-play-circle-fill me-1"></i><span class="small"><?= $e(ncT('demo.botao')) ?></span></button><?php endif; ?></h5>
      <?php if ($codigo): ?><div class="nc-pessoas"><?= $e(ncT('oc.emitente')) ?> <b><?= $e($v('EMISSOR') ?: '—') ?></b> · <?= $e(ncT('oc.gestor')) ?> <b><?= $e($v('GESTOR') ?: '—') ?></b> · <?= $e(ncT('oc.responsavel')) ?> <b><?= $e($v('RESPONSAVEL') ?: '—') ?></b><?= $v('SITUACAO') !== '' ? ' · ' . $e(ncT('oc.situacao')) . ' <b>' . $e(NaoConformidadeTextos::valor($v('SITUACAO'))) . '</b>' : '' ?></div><?php endif; ?>
    </div>
    <div class="d-flex gap-2 align-items-center">
      <?php if ($etapa !== ''): ?><span class="badge <?= $badge ?>"><?= $e(NaoConformidadeTextos::valor($etapa)) ?></span><?php endif; ?>
      <?php if (!$codigo && $pode['ocorrencia']): /* migração 364: o lugar dos Anexos já aparece na NC nova (ativa depois de salvar) */ ?><span class="d-inline-block" tabindex="0" title="<?= $e(ncT('oc.salve_para_anexar')) ?>"><button type="button" class="btn btn-sm btn-outline-secondary" data-nc-anexos-novo disabled style="pointer-events:none"><i class="bi bi-paperclip me-1"></i><?= $e(ncT('oc.anexos')) ?></button></span><?php endif; ?>
      <?php if ($codigo): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-ged="ncocorrencia" data-chave="CODOCORRENCIA" data-valor="<?= (int)$codigo ?>" data-titulo="<?= $e(ncT('oc.anexos_da_nc', ['n' => $v('NRANO') ?: $codigo])) ?>" title="<?= $e(ncT('oc.anexos_ocorrencia')) ?>"><i class="bi bi-paperclip me-1"></i><?= $e(ncT('oc.anexos')) ?><span data-ged-qtd></span></button><?php endif; ?>
      <?php if ($codigo): ?><a class="btn btn-sm btn-outline-secondary" href="naoconformidade_ocorrenciapdf.php?codigo=<?= (int)$codigo ?>" target="_blank" data-nc-pdf data-titulo="<?= $e(ncT('oc.pdf_da_nc', ['n' => $v('NRANO') ?: $codigo])) ?>" title="<?= $e(ncT('oc.pdf_ocorrencia')) ?>"><i class="bi bi-file-earmark-pdf"></i></a><?php endif; ?>
    </div>
  </header>
  <?php if ($codigo && !in_array($tipo, $tiposComFluxo, true)): ?><div class="alert alert-info py-2 my-2 small"><?= $e(ncT('oc.so_consulta_tipo')) ?></div><?php endif; ?>
  <?php if (!$podeGravar): ?><div class="alert alert-secondary py-2 my-2 small"><?= $e(ncT('oc.so_consulta_perfil')) ?></div><?php endif; ?>
  <div id="ncStatus" class="my-2" aria-live="polite"></div>

  <ul class="nav nav-tabs mt-2" role="tablist">
    <?php foreach (['ocorrencia' => ncT('aba.ocorrencia'), 'gestor' => ncT('aba.gestor'), 'analise' => $planejamento ? ncT('aba.plano') : ncT('aba.analise'), 'eficacia' => ncT('aba.eficacia'), 'historico' => ncT('aba.historico')] as $k => $t): if ((!$codigo && $k !== 'ocorrencia') || ($planejamento && $k === 'gestor')) continue; ?>
    <li class="nav-item"><button class="nav-link<?= $k === $abaInicial ? ' active' : '' ?>" data-bs-toggle="tab" data-bs-target="#aba-<?= $k ?>" type="button"><?= $e($t) ?><?= (!empty($pode[$k]) || ($k === 'analise' && $acaoPropria)) ? ' <i class="bi bi-pencil-square text-success" title="' . $e(ncT('oc.voce_age')) . '"></i>' : '' ?></button></li>
    <?php endforeach; ?>
  </ul>
  <div class="tab-content">
    <!-- Ocorrência -->
    <section class="tab-pane fade nc-aba<?= $abaInicial === 'ocorrencia' ? ' show active' : '' ?>" id="aba-ocorrencia">
      <?php if ($pode['ocorrencia']): ?>
      <?php if ($assistencia): ?><div class="small text-muted mt-2"><?= $e(ncT('oc.dica_assistencia')) ?></div><?php endif; ?>
      <div class="nc-barra-frag"><div class="crud-toolbar menu-grid-toolbar"><div class="crud-actions crud-actions-standard">
        <?= $ncBotao('bi-check2', ncT('comum.salvar'), 'btn-success', 'data-enviar="ocorrencia" data-sub="salvar"') ?>
        <?= $ncBotao('bi-send', $assistencia ? ncT('oc.btn_enviar_plano') : ($etapa === 'Recusado pelo Gestor' ? ncT('oc.btn_reenviar_gestor') : ncT('oc.btn_enviar_gestor')), 'btn-outline-primary', 'data-enviar="ocorrencia" data-sub="enviar"') ?>
      </div></div></div>
      <?php endif; ?>
      <form data-form="ocorrencia" class="row g-2">
        <?php foreach ($camposOcorrencia as $nome => $def) {
            $depende = $def[4] ?? null;
            if (!$def[3] && !$tem($depende ?? $nome) && !($planejamento && $nome === 'PASS_PELA_NANALISE_EFICACIA')) continue;
            if ($tipo === 2 && $nome === 'PARADANALINHA') continue; // não usado no Cliente (0 de 2.401 no legado)
            echo $campoHtml($nome, $def, $valorCampo($nome), $pode['ocorrencia']);
        } ?>
      </form>
      <?php if ($v('RECUSA_DO_GESTOR') !== '' && $etapa === 'Recusado pelo Gestor'): ?><div class="alert alert-warning py-2 mt-2 small"><b><?= $e(ncT('oc.motivo_recusa_gestor')) ?></b> <?= nl2br($e($v('RECUSA_DO_GESTOR'))) ?></div><?php endif; ?>
    </section>

    <?php if ($codigo): ?>
    <!-- Avaliação do gestor -->
    <section class="tab-pane fade nc-aba<?= $abaInicial === 'gestor' ? ' show active' : '' ?>" id="aba-gestor">
      <?php if ($pode['gestor']): ?>
      <div class="nc-barra-frag"><div class="crud-toolbar menu-grid-toolbar"><div class="crud-actions crud-actions-standard">
        <?= $ncBotao('bi-check2', ncT('comum.salvar'), 'btn-success', 'data-enviar="gestor" data-sub="salvar"') ?>
        <?= $ncBotao('bi-send', ncT('oc.btn_enviar_resp'), 'btn-outline-primary', 'data-enviar="gestor" data-sub="enviar"') ?>
        <?= $ncBotao('bi-arrow-return-left', ncT('oc.btn_recusar_emitente'), 'btn-outline-danger', 'data-enviar="gestor" data-sub="recusar"') ?>
        <?php if ($tem('IMPROCEDENTE')) echo $ncBotao('bi-x-octagon', ncT('oc.btn_improcedente'), 'btn-outline-secondary', 'data-enviar="gestor" data-sub="improcedente" data-confirmar="' . $e(ncT('oc.conf_improcedente')) . '"'); ?>
      </div></div></div>
      <?php endif; ?>
      <form data-form="gestor" class="row g-2">
        <?= $campoHtml('ANALISE_DA_PROVIDENCIA', [ncT('campo.analise_da_providencia'), 'textarea', null], $v('ANALISE_DA_PROVIDENCIA'), $pode['gestor']) ?>
        <?= $campoHtml('ANALISE_ABRANGENCIA', [ncT('campo.analise_abrangencia'), 'textarea', null], $v('ANALISE_ABRANGENCIA'), $pode['gestor']) ?>
        <?= $campoHtml('ATUALIZAR_RISCOS', [ncT('campo.atualizar_riscos'), 'select', $simNao], $v('ATUALIZAR_RELACAO_DE_RISCOS_E_OPORTUNIDADES'), $pode['gestor']) ?>
        <?php // Revisão G item 158 (migração 417): Risco da ocorrência, avaliado pelo gestor (alimenta a lente de risco da Home de NC)
        if ($tem('RISCO') || array_key_exists('RISCO', (array)$R)) echo $campoHtml('RISCO', [IdiomaService::t('nc.campo.risco', ['pt' => 'Risco da ocorrência', 'en' => 'Occurrence risk', 'es' => 'Riesgo de la ocurrencia']), 'select', ['Baixo' => IdiomaService::t('nc.risco.baixo', ['pt' => 'Baixo', 'en' => 'Low', 'es' => 'Bajo']), 'Moderado' => IdiomaService::t('nc.risco.moderado', ['pt' => 'Moderado', 'en' => 'Moderate', 'es' => 'Moderado']), 'Alto' => IdiomaService::t('nc.risco.alto', ['pt' => 'Alto', 'en' => 'High', 'es' => 'Alto']), 'Crítico' => IdiomaService::t('nc.risco.critico', ['pt' => 'Crítico', 'en' => 'Critical', 'es' => 'Crítico'])]], $v('RISCO'), $pode['gestor']); ?>
        <?= $campoHtml('ABRIR_MUDANCA', [ncT('campo.abrir_mudanca'), 'select', $simNao], $v('ABRIR_NOVA_AVALIACAO_DE_MUDANCA'), $pode['gestor']) ?>
        <?php if ($tem('IMPROCEDENTE')) echo $campoHtml('IMPROCEDENTE', [ncT('campo.improcedente'), 'select', $simNaoLongo], $v('IMPROCEDENTE'), $pode['gestor']); ?>
        <?= $campoHtml('CODUSUARIO_RESPONSAVEL', [ncT('campo.codusuario_responsavel'), 'select', $usuarios], $v('CODUSUARIO_RESPONSAVEL'), $pode['gestor']) ?>
        <?= $campoHtml('RECUSA_DO_GESTOR', [ncT('campo.recusa_emitente'), 'textarea', null], $etapa === 'Com o Gestor' ? '' : $v('RECUSA_DO_GESTOR'), $pode['gestor']) ?>
      </form>
    </section>

    <!-- Análise da causa e plano de ação -->
    <section class="tab-pane fade nc-aba<?= $abaInicial === 'analise' ? ' show active' : '' ?>" id="aba-analise">
      <?php if ($etapa === 'Recusado pela Avaliacao da Eficacia' && $v('RECUSA_DO_GESTOR') !== ''): ?><div class="alert alert-warning py-2 small"><b><?= $e(ncT('oc.devolvida_eficacia')) ?></b> <?= nl2br($e($v('RECUSA_DO_GESTOR'))) ?></div><?php endif; ?>
      <?php if ($pode['analise'] && !$planejamento): ?>
      <div class="nc-barra-frag"><div class="crud-toolbar menu-grid-toolbar"><div class="crud-actions crud-actions-standard">
        <?= $ncBotao('bi-check2', ncT('comum.salvar'), 'btn-success', 'data-enviar="analise" data-sub="salvar"') ?>
        <?= $ncBotao('bi-send', ncT('oc.btn_enviar_eficacia'), 'btn-outline-primary', 'data-enviar="analise" data-sub="enviar"') ?>
      </div></div></div>
      <?php endif; ?>
      <?php if ($planejamento): ?>
      <?php if ($W::etapaAnalise($etapa) && $v('RECUSA_DO_GESTOR') !== '' && $v('VERIFICACAO_DA_EFICACIA') !== ''): ?><div class="alert alert-warning py-2 small"><b><?= $e(ncT('oc.devolvida_eficacia')) ?></b> <?= nl2br($e($v('RECUSA_DO_GESTOR'))) ?></div><?php endif; ?>
      <div class="small text-muted mb-1"><?= $assistencia ? ncT('oc.dica_assist_plano') : ncT('oc.dica_planej') ?> <?= $e(ncT('oc.dica_100')) ?><?= $v('PASS_PELA_NANALISE_EFICACIA') === 'N' ? ' — ' . $e(ncT('oc.dica_100_sem')) : '' ?>.</div>
      <?php else:
      // Sub-abas (pedido do usuário 23/10/2026): Análise | Causas | Plano de ação, em vez de formulário + 2 grids empilhados
      $subAba = in_array($_GET['sub'] ?? '', ['analise', 'causas', 'plano'], true) ? (string)$_GET['sub'] : 'analise';
      $semAcao = count(array_filter($causas, static fn($c) => (int)$c['PLANOS'] === 0)); ?>
      <ul class="nav nav-pills nc-subabas mb-2" role="tablist">
        <?php foreach (['analise' => [ncT('sub.analise'), 'bi-journal-text', ''], 'causas' => [ncT('sub.causas'), 'bi-diagram-3', (string)count($causas)], 'plano' => [ncT('aba.plano'), 'bi-list-check', (string)count($planos)]] as $k => [$rot, $ic, $qtd]): ?>
        <li class="nav-item" role="presentation"><button type="button" class="nav-link<?= $subAba === $k ? ' active' : '' ?>" data-bs-toggle="tab" data-bs-target="#sub-<?= $k ?>" data-nc-sub="<?= $k ?>" role="tab"><i class="bi <?= $ic ?> me-1"></i><?= $e($rot) ?><?php if ($qtd !== ''): ?> <span class="badge <?= $qtd === '0' ? 'text-bg-secondary' : 'text-bg-light border' ?>"><?= $qtd ?></span><?php endif; ?><?php if ($k === 'causas' && $semAcao): ?> <i class="bi bi-exclamation-triangle-fill text-warning" title="<?= $e(ncT('oc.causas_sem_acao', ['n' => $semAcao])) ?>"></i><?php endif; ?></button></li>
        <?php endforeach; ?>
      </ul>
      <div class="tab-content">
      <div class="tab-pane fade<?= $subAba === 'analise' ? ' show active' : '' ?>" id="sub-analise" role="tabpanel">
      <div class="nc-barra-frag"><div class="crud-toolbar menu-grid-toolbar"><div class="crud-actions crud-actions-standard">
        <?= $ncBotao('bi-paperclip', ncT('oc.evidencias'), 'btn-outline-secondary', 'data-ged="ncanalise" data-chave="CODOCORRENCIA" data-valor="' . (int)$codigo . '" data-titulo="' . $e(ncT('oc.evidencias_titulo', ['n' => $v('NRANO') ?: $codigo])) . '"', '<span data-ged-qtd></span>') ?>
        <?php if ($iaAtiva):
          $iaTitulo = $iaPendente ? ncT('ia.pedida_por', ['usuario' => $iaPendente['usuario'], 'data' => date(IdiomaService::idioma() === 'en' ? 'm/d/Y H:i' : 'd/m/Y H:i', strtotime($iaPendente['criado_em']) ?: time())])
              : ($cfg['ia']['pronta'] ? ncT('ia.chegou') : ncT('ia.explica'));
          $iaRotulo = $iaPendente ? ncT('ia.processando') : ($cfg['ia']['pronta'] ? ncT('ia.ver') : ncT('ia.sugerir')); ?>
          <span data-disabled-tooltip-wrapper title="<?= $e($iaRotulo . ' — ' . $iaTitulo) ?>"><?= $ncBotao($iaPendente ? 'bi-hourglass-split' : 'bi-stars', $iaRotulo, 'btn-outline-primary', 'data-nc-ia' . ($iaPendente ? ' disabled' : '')) ?></span><?php endif; ?>
      </div></div></div>
      <form data-form="analise" class="row g-2">
        <?= $campoHtml('DATA_DE_CONCLUSAO_PREVISTA', [ncT('campo.data_conclusao'), 'date', null], $data('DATA_DE_CONCLUSAO_PREVISTA'), $pode['analise']) ?>
        <?= $campoHtml('ANALISE_DA_CAUSA', [ncT('campo.analise_da_causa'), 'textarea', null], $v('ANALISE_DA_CAUSA'), $pode['analise']) ?>
      </form>
      </div>
      <div class="tab-pane fade<?= $subAba === 'causas' ? ' show active' : '' ?>" id="sub-causas" role="tabpanel">
      <?php
      // c537: grid no padrão do portal (components/grid_local.php): Nova/Editar/Excluir na barra, para a linha selecionada
      $linhasCausas = [];
      foreach ($causas as $c) {
          $acoesLinha = $pode['analise'] ? '<button type="button" data-linha-acao="editar" data-causa-editar=\'' . $e(json_encode(['CODCAUSA' => (int)$c['CODCAUSA'], 'CODTIPOCAUSA' => (string)$c['CODTIPOCAUSA'], 'DSCAUSA' => (string)$c['DSCAUSA']], JSON_UNESCAPED_UNICODE)) . '\'></button>'
              . '<button type="button" data-linha-acao="excluir" data-excluir="causa" data-cod="' . (int)$c['CODCAUSA'] . '"></button>' : '';
          $linhasCausas[] = ['cells' => [
              'DSCTIPOCAUSA' => ['text' => (string)$c['DSCTIPOCAUSA'], 'html' => $e($c['DSCTIPOCAUSA']) . greenPortalGridLocalLinha($acoesLinha)],
              'DSCAUSA' => ['text' => (string)$c['DSCAUSA'], 'title' => (string)$c['DSCAUSA']],
              'PLANOS' => ['text' => (string)(int)$c['PLANOS']],
          ]];
      }
      greenPortalGridLocal([
          'transaction' => 'naoconformidades_ocorrencia_causas', 'fragment_class' => 'nc-grid-causas', 'id' => 'ncCausasGrid', 'page_size' => 10,
          'columns' => ['DSCTIPOCAUSA' => ['label' => ncT('col.tipo_causa'), 'filter' => 'select', 'width' => '220px'], 'DSCAUSA' => ['label' => ncT('col.causa')], 'PLANOS' => ['label' => ncT('col.acoes'), 'filter' => 'none', 'width' => '80px']],
          'cell_boxes' => true, 'rows' => $linhasCausas, 'empty' => ncT('oc.nenhuma_causa'), 'double_click_action' => 'editar',
          'buttons' => ['reload' => false, 'new' => (bool)$pode['analise'], 'new_attribute' => 'data-causa-nova', 'delete' => (bool)$pode['analise'], 'delete_attribute' => 'data-grid-acao-linha="excluir"'],
          'extras' => $pode['analise'] ? '<button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-grid-acao-linha="editar" disabled title="' . $e(ncT('oc.editar_causa')) . '" aria-label="' . $e(ncT('oc.editar_causa')) . '"><i class="bi bi-pencil"></i></button>' : '',
      ]);
      ?>
      <?php if ($semAcao): ?><div class="small text-warning mt-1"><i class="bi bi-exclamation-triangle me-1"></i><?= $e(ncT('oc.causas_sem_acao_aviso', ['n' => $semAcao])) ?></div><?php endif; ?>
      </div>
      <div class="tab-pane fade<?= $subAba === 'plano' ? ' show active' : '' ?>" id="sub-plano" role="tabpanel">
      <?php endif; ?>
      <?php if ($planejamento): ?><h6 class="mt-3 mb-1"><?= $e(ncT('aba.plano')) ?></h6><?php endif; ?>
      <?php
      // c537: grid no padrão do portal: Nova/Editar/Excluir na barra (linha selecionada); Arquivos da ação na coluna, como os
      // Relacionamentos do grid padrão
      $jsonPlano = static fn(array $p): string => json_encode(['CODPLANOACAO' => (int)$p['CODPLANOACAO'], 'CODCAUSA' => (string)$p['CODCAUSA'], 'ACAO' => (string)$p['ACAO'], 'PRIORIDADE' => (string)$p['PRIORIDADE'], 'CODUSUARIO' => (string)$p['CODUSUARIO'], 'DATAINICIAL' => substr((string)$p['DATAINICIAL'], 0, 10), 'DATAFINAL' => substr((string)$p['DATAFINAL'], 0, 10), 'RECURSOS' => (string)$p['RECURSOS'], 'STATUS' => (string)(int)$p['STATUS'], 'OBS' => (string)$p['OBS']], JSON_UNESCAPED_UNICODE);
      $ymd = static fn(?string $d): string => $d && !str_starts_with($d, '0000') ? substr($d, 0, 10) : '';
      $linhasPlanos = []; $editaAlguma = false;
      foreach ($planos as $p) {
          $acoesLinha = '';
          if ($pode['analise']) {
              $acoesLinha = '<button type="button" data-linha-acao="editar" data-plano-editar=\'' . $e($jsonPlano($p)) . '\'></button><button type="button" data-linha-acao="excluir" data-excluir="plano" data-cod="' . (int)$p['CODPLANOACAO'] . '"></button>';
              $editaAlguma = true;
          } elseif (in_array((int)$p['CODPLANOACAO'], $minhasAcoes, true)) {
              $acoesLinha = '<button type="button" data-linha-acao="editar" data-so-status data-plano-editar=\'' . $e($jsonPlano($p)) . '\'></button>';
              $editaAlguma = true;
          }
          $celulas = [];
          if (!$planejamento) $celulas['DSCAUSA'] = ['text' => (string)$p['DSCAUSA'], 'title' => (string)$p['DSCAUSA']];
          $celulas += [
              'ACAO' => ['text' => (string)$p['ACAO'], 'title' => (string)$p['ACAO'], 'html' => $e($p['ACAO']) . greenPortalGridLocalLinha($acoesLinha)],
              'PRIORIDADE' => ['text' => (string)$p['PRIORIDADE']],
              'RESPONSAVEL' => ['text' => (string)$p['RESPONSAVEL']],
              'DATAINICIAL' => ['text' => $dataBR($p['DATAINICIAL']), 'sort' => $ymd($p['DATAINICIAL'])],
              'DATAFINAL' => ['text' => $dataBR($p['DATAFINAL']), 'sort' => $ymd($p['DATAFINAL'])],
              'STATUS' => ['text' => (int)$p['STATUS'] . '%', 'sort' => str_pad((string)(int)$p['STATUS'], 3, '0', STR_PAD_LEFT)],
              'OBS' => ['text' => (string)$p['OBS'], 'title' => (string)$p['OBS']],
          ];
          $linhasPlanos[] = ['cells' => $celulas, 'actions' => '<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-ged="ncplano" data-chave="CODPLANOACAO" data-valor="' . (int)$p['CODPLANOACAO'] . '" data-titulo="' . $e(ncT('oc.arquivos_acao') . ' - ' . mb_substr((string)$p['ACAO'], 0, 80)) . '" title="' . $e(ncT('oc.arquivos_acao')) . '" aria-label="' . $e(ncT('oc.arquivos_acao')) . '"><i class="bi bi-paperclip"></i><span data-ged-qtd></span></button>'];
      }
      $colunasPlano = $planejamento ? [] : ['DSCAUSA' => ['label' => ncT('col.causa'), 'width' => '18%']];
      $colunasPlano += ['ACAO' => ['label' => ncT('col.acao'), 'width' => '24%'], 'PRIORIDADE' => ['label' => ncT('col.prioridade'), 'filter' => 'select', 'width' => '100px'], 'RESPONSAVEL' => ['label' => ncT('col.responsavel'), 'filter' => 'select'],
          'DATAINICIAL' => ['label' => ncT('col.inicio'), 'filter' => 'none', 'width' => '96px'], 'DATAFINAL' => ['label' => ncT('col.fim'), 'filter' => 'none', 'width' => '96px'],
          'STATUS' => ['label' => ncT('col.status'), 'filter' => 'select', 'width' => '80px'], 'OBS' => ['label' => ncT('col.obs')]];
      $novaAcao = $pode['analise'] && ($causas || $planejamento);
      greenPortalGridLocal([
          'transaction' => 'naoconformidades_ocorrencia_plano', 'fragment_class' => 'nc-grid-plano', 'id' => 'ncPlanoGrid', 'page_size' => 10,
          'columns' => $colunasPlano, 'actions_label' => ncT('col.arquivos'), 'cell_boxes' => true, 'rows' => $linhasPlanos, 'empty' => ncT('oc.nenhuma_acao'),
          'double_click_action' => 'editar',
          'buttons' => ['reload' => false, 'columns' => true, 'new' => (bool)$novaAcao, 'new_attribute' => 'data-plano-novo', 'delete' => (bool)$pode['analise'], 'delete_attribute' => 'data-grid-acao-linha="excluir"'],
          'extras' => $editaAlguma ? '<button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-grid-acao-linha="editar" disabled title="' . $e($pode['analise'] ? ncT('oc.editar_acao') : ncT('oc.atualizar_status')) . '" aria-label="' . $e(ncT('oc.editar_acao')) . '"><i class="bi bi-pencil"></i></button>' : '',
      ]);
      ?>
      <?php if (!$planejamento): ?><?php if (!$causas && $pode['analise']): ?><div class="small text-muted mt-1"><?= $e(ncT('oc.cadastre_causas')) ?></div><?php endif; ?></div></div><?php endif; ?>
    </section>

    <!-- Verificação da eficácia -->
    <section class="tab-pane fade nc-aba<?= $abaInicial === 'eficacia' ? ' show active' : '' ?>" id="aba-eficacia">
      <?php if ($pode['eficacia']): ?>
      <div class="nc-barra-frag"><div class="crud-toolbar menu-grid-toolbar"><div class="crud-actions crud-actions-standard">
        <?= $ncBotao('bi-check2', ncT('comum.salvar'), 'btn-success', 'data-enviar="eficacia" data-sub="salvar"') ?>
        <?= $ncBotao('bi-check2-circle', ncT('oc.btn_aplicar_resultado'), 'btn-outline-primary', 'data-enviar="eficacia" data-sub="finalizar"') ?>
        <?= $ncBotao('bi-arrow-return-left', ncT('oc.btn_recusar_resp'), 'btn-outline-danger', 'data-enviar="eficacia" data-sub="recusar"') ?>
        <?php if ($iaEfAtiva):
          $iaEfTitulo = $iaEfPendente ? ncT('iaef.pedido_por', ['usuario' => $iaEfPendente['usuario'], 'data' => date(IdiomaService::idioma() === 'en' ? 'm/d/Y H:i' : 'd/m/Y H:i', strtotime($iaEfPendente['criado_em']) ?: time())])
              : ($cfg['iaef']['pronta'] ? ncT('iaef.chegou') : ncT('iaef.explica'));
          $iaEfRotulo = $iaEfPendente ? ncT('iaef.processando') : ($cfg['iaef']['pronta'] ? ncT('iaef.ver') : ncT('iaef.avaliar')); ?>
          <span data-disabled-tooltip-wrapper title="<?= $e($iaEfRotulo . ' — ' . $iaEfTitulo) ?>"><?= $ncBotao($iaEfPendente ? 'bi-hourglass-split' : 'bi-stars', $iaEfRotulo, 'btn-outline-primary', 'data-nc-iaef' . ($iaEfPendente ? ' disabled' : '')) ?></span><?php endif; ?>
      </div></div></div>
      <?php endif; ?>
      <form data-form="eficacia" class="row g-2">
        <?= $campoHtml('VERIFICACAO_DA_EFICACIA', [ncT('campo.verificacao'), 'textarea', null], $v('VERIFICACAO_DA_EFICACIA'), $pode['eficacia']) ?>
        <?php $resultado = ['EFICAZ' => ncT('valor.eficaz'), 'ENCERRADO' => ncT('valor.encerrada_sem'), 'NAO_EFICAZ' => ncT('oc.nao_eficaz_volta')];
        if ($etapa === 'Finalizado' || !$pode['eficacia']): ?><div class="col-md-4"><label class="form-label"><?= $e(ncT('campo.resultado')) ?></label><div class="nc-leitura"><?= $e($etapa === 'Finalizado' ? NaoConformidadeTextos::valor($v('SITUACAO')) : '—') ?></div></div>
        <?php else: echo $campoHtml('RESULTADO', [ncT('campo.resultado'), 'select', $resultado], (string)($iaEfPreenche['RESULTADO'] ?? ''), true); endif; ?>
        <?php if ($exigePrazo || $v('PRAZO_ANALIZE') !== ''): ?>
          <?= $campoHtml('PRAZO_ANALIZE', [ncT('campo.prazo_analize'), 'date', null], $data('PRAZO_ANALIZE'), $pode['eficacia']) ?>
          <?= $campoHtml('CODUSUARIO_PRAZO_ANALISE', [ncT('campo.resp_pendencia'), 'select', $usuarios], $v('CODUSUARIO_PRAZO_ANALISE'), $pode['eficacia']) ?>
        <?php endif; ?>
        <?= $campoHtml('RECUSA_DO_GESTOR', [ncT('campo.recusa_resp'), 'textarea', null], (string)($iaEfPreenche['RECUSA_DO_GESTOR'] ?? ''), $pode['eficacia']) ?>
      </form>
      <?php if (!empty($iaEfPreenche['RESULTADO'])): ?><div class="alert alert-info py-2 small mt-2 mb-0"><i class="bi bi-stars me-1"></i><?= ncT('iaef.preenchido') ?></div><?php endif; ?>
    </section>

    <!-- Histórico -->
    <section class="tab-pane fade nc-aba<?= $abaInicial === 'historico' ? ' show active' : '' ?>" id="aba-historico">
      <?php
      // c537: histórico no grid padrão do portal (busca, filtro e ordenação por coluna, paginação)
      $linhasHist = [];
      foreach ($historico as $h) {
          $quando = strtotime((string)$h['CRIADO_EM']);
          $linhasHist[] = ['cells' => [
              'CRIADO_EM' => ['text' => $quando ? date(IdiomaService::idioma() === 'en' ? 'm/d/Y H:i' : 'd/m/Y H:i', $quando) : '', 'sort' => $quando ? date('Y-m-d H:i:s', $quando) : ''],
              'USUARIO' => ['text' => (string)$h['USUARIO']],
              'ACAO' => ['text' => $acoesHistorico[(string)$h['ACAO']] ?? ucfirst(strtolower(str_replace('_', ' ', (string)$h['ACAO'])))],
              'ETAPA_ANTERIOR' => ['text' => NaoConformidadeTextos::valor((string)$h['ETAPA_ANTERIOR'])],
              'ETAPA_NOVA' => ['text' => NaoConformidadeTextos::valor((string)$h['ETAPA_NOVA'])],
              'MOTIVO' => ['text' => (string)$h['MOTIVO'], 'title' => (string)$h['MOTIVO']],
          ]];
      }
      greenPortalGridLocal([
          'transaction' => 'naoconformidades_ocorrencia_historico', 'fragment_class' => 'nc-grid-historico', 'id' => 'ncHistoricoGrid',
          'columns' => ['CRIADO_EM' => ['label' => ncT('col.data'), 'filter' => 'none', 'width' => '130px'], 'USUARIO' => ['label' => ncT('col.usuario'), 'filter' => 'select', 'width' => '170px'],
              'ACAO' => ['label' => ncT('col.acao'), 'filter' => 'select', 'width' => '210px'], 'ETAPA_ANTERIOR' => ['label' => ncT('col.de'), 'filter' => 'select', 'width' => '150px'],
              'ETAPA_NOVA' => ['label' => ncT('col.para'), 'filter' => 'select', 'width' => '150px'], 'MOTIVO' => ['label' => ncT('col.detalhe')]],
          'cell_boxes' => true, 'rows' => $linhasHist, 'default_sort' => ['CRIADO_EM', 'desc'], 'buttons' => ['reload' => false, 'columns' => true],
          'empty' => ncT('oc.hist_vazio'),
      ]);
      ?>
    </section>
    <?php endif; ?>
  </div>
</main>

<?php if ($codigo && ($pode['analise'] || $acaoPropria)): ?>
<?php if (!$planejamento): ?>
<div class="modal fade" id="ncCausa" tabindex="-1"><div class="modal-dialog"><form class="modal-content" data-modal-form="causa">
  <div class="modal-header py-2"><h6 class="modal-title"><?= $e(ncT('col.causa')) ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body row g-2"><input type="hidden" name="CODCAUSA">
    <div class="col-12"><label class="form-label"><?= $e(ncT('col.tipo_causa')) ?></label><select class="form-select form-select-sm" name="CODTIPOCAUSA"><?= $opcoesHtml($op['TIPOCAUSA'] ?? [], '') ?></select></div>
    <div class="col-12"><label class="form-label"><?= $e(ncT('col.causa')) ?></label><textarea class="form-control form-control-sm" name="DSCAUSA" rows="3" maxlength="500" required></textarea></div></div>
  <div class="modal-footer py-2"><button class="btn btn-sm btn-success" type="submit"><i class="bi bi-floppy me-1"></i><?= $e(ncT('comum.salvar')) ?></button></div></form></div></div>
<?php endif; ?>
<div class="modal fade" id="ncPlano" tabindex="-1"><div class="modal-dialog modal-lg"><form class="modal-content" data-modal-form="plano">
  <div class="modal-header py-2"><h6 class="modal-title"><?= $e(ncT('oc.acao_do_plano')) ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body row g-2"><input type="hidden" name="CODPLANOACAO">
    <?php if (!$planejamento): ?><div class="col-md-6"><label class="form-label"><?= $e(ncT('col.causa')) ?></label><select class="form-select form-select-sm" name="CODCAUSA" required><?php foreach ($causas as $c) echo '<option value="' . (int)$c['CODCAUSA'] . '">' . $e(mb_strimwidth((string)$c['DSCAUSA'], 0, 80, '…')) . '</option>'; ?></select></div><?php endif; ?>
    <div class="col-md-6"><label class="form-label"><?= $e(ncT('col.responsavel')) ?></label><select class="form-select form-select-sm" name="CODUSUARIO"><?= $opcoesHtml($usuariosAcao, '') ?></select></div>
    <div class="col-12"><label class="form-label"><?= $e(ncT('col.acao')) ?></label><textarea class="form-control form-control-sm" name="ACAO" rows="3" required></textarea></div>
    <div class="col-md-3"><label class="form-label"><?= $e(ncT('col.prioridade')) ?></label><input class="form-control form-control-sm" name="PRIORIDADE" maxlength="500"></div>
    <div class="col-md-3"><label class="form-label"><?= $e(ncT('col.inicio')) ?></label><input type="date" class="form-control form-control-sm" name="DATAINICIAL"></div>
    <div class="col-md-3"><label class="form-label"><?= $e(ncT('col.fim')) ?></label><input type="date" class="form-control form-control-sm" name="DATAFINAL"></div>
    <div class="col-md-3"><label class="form-label"><?= $e(ncT('col.status')) ?></label><select class="form-select form-select-sm" name="STATUS"><?= $opcoesHtml($status, '0', false) ?></select></div>
    <div class="col-md-6"><label class="form-label"><?= $e(ncT('col.recursos')) ?></label><textarea class="form-control form-control-sm" name="RECURSOS" rows="2"></textarea></div>
    <div class="col-md-6"><label class="form-label"><?= $e(ncT('col.obs')) ?></label><textarea class="form-control form-control-sm" name="OBS" rows="2"></textarea></div></div>
  <div class="modal-footer py-2"><button class="btn btn-sm btn-success" type="submit"><i class="bi bi-floppy me-1"></i><?= $e(ncT('comum.salvar')) ?></button></div></form></div></div>
<?php endif; ?>

<?php if ($iaEfAtiva): ?>
<!-- Migração 337: parecer da IA sobre a eficácia (preenchido por assets/js/nc-eficacia-ia.js) -->
<div class="modal fade" id="ncIaEf" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-stars me-1"></i><?= $e(ncT('iaef.titulo')) ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $e(ncT('comum.fechar')) ?>"></button></div>
  <div class="modal-body" data-iaef-corpo style="min-height:60vh"></div>
  <div class="modal-footer py-2 justify-content-between">
    <span class="small text-muted"><?= $e(ncT('iaef.rodape', ['prompt' => NaoConformidadeEficaciaIa::PROMPT])) ?></span>
    <div class="d-flex gap-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-iaef-nova><i class="bi bi-arrow-repeat me-1"></i><?= $e(ncT('iaef.pedir_outro')) ?></button>
      <button type="button" class="btn btn-sm btn-outline-danger" data-iaef-descartar><i class="bi bi-x-circle me-1"></i><?= $e(ncT('comum.descartar')) ?></button>
      <button type="button" class="btn btn-sm btn-success" data-iaef-aplicar><i class="bi bi-box-arrow-in-down me-1"></i><?= $e(ncT('iaef.levar')) ?></button></div>
  </div>
</div></div></div>
<?php endif; ?>
<?php if ($iaAtiva): ?>
<!-- Migração 336: sugestão da IA para a análise da causa (preenchida por assets/js/nc-analise-ia.js) -->
<div class="modal fade" id="ncIa" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-stars me-1"></i><?= $e(ncT('ia.titulo')) ?></h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $e(ncT('comum.fechar')) ?>"></button></div>
  <div class="modal-body" data-ia-corpo style="min-height:60vh"></div>
  <div class="modal-footer py-2 justify-content-between">
    <span class="small text-muted"><?= $e(ncT('ia.rodape', ['prompt' => NaoConformidadeAnaliseIa::PROMPT])) ?></span>
    <div class="d-flex gap-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-ia-nova><i class="bi bi-arrow-repeat me-1"></i><?= $e(ncT('ia.gerar_outra')) ?></button>
      <button type="button" class="btn btn-sm btn-outline-danger" data-ia-descartar><i class="bi bi-x-circle me-1"></i><?= $e(ncT('comum.descartar')) ?></button>
      <button type="button" class="btn btn-sm btn-success" data-ia-aplicar><i class="bi bi-check2-circle me-1"></i><?= $e(ncT('ia.aplicar_marcado')) ?></button></div>
  </div>
</div></div></div>
<?php endif; ?>
<?= NaoConformidadeTextos::script() /* textos do JavaScript (greenT) */ ?>
<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script>
<script src="../assets/vendor/jquery/jquery-3.7.1.min.js?v=2"></script><script src="../assets/js/grid-local.js?v=5"></script>
<?php require_once dirname(__DIR__) . '/components/toolbar_grupos.php'; $ncRot = greenPortalToolbarGruposRotulos();
greenPortalToolbarGruposEmitir(['fragmento' => '.nc-barra-frag', 'auto' => false, 'rotuloOutros' => $ncRot['outros'], 'grupos' => [
    ['id' => 'operacoes', 'rotulo' => $ncRot['operacoes'], 'botoes' => ['[data-sub="salvar"]']],
    ['id' => 'fluxo', 'rotulo' => ncT('oc.grupo_fluxo'), 'botoes' => ['[data-sub="enviar"]', '[data-sub="finalizar"]', '[data-sub="recusar"]', '[data-sub="improcedente"]']],
    ['id' => 'outros', 'rotulo' => $ncRot['outros'], 'botoes' => ['[data-ged]', '[data-nc-ia]', '[data-nc-iaef]']],
]]); ?>
<?php if ($iaAtiva): ?><script src="../assets/js/nc-analise-ia.js?v=5"></script><?php endif; ?>
<?php if ($iaEfAtiva): ?><script src="../assets/js/nc-eficacia-ia.js?v=3"></script><?php endif; ?>
<script>
(function () {
  'use strict';
  function N(k, p) { return typeof window.greenT === 'function' ? window.greenT('naoconformidades.js.' + k, p) : p; }
  var C = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>, statusEl = document.getElementById('ncStatus'), ocupado = false;
  function aviso(texto, tipo) { statusEl.innerHTML = ''; if (!texto) return; var d = document.createElement('div'); d.className = 'alert py-2 small mb-0 alert-' + (tipo || 'info'); d.textContent = texto; statusEl.appendChild(d); }
  function dadosDe(form) {
    var o = {}; if (!form) return o;
    Array.prototype.forEach.call(form.elements, function (el) { if (el.name && !el.disabled) o[el.name] = el.type === 'datetime-local' && el.value ? el.value.replace('T', ' ') + ':00' : el.value; });
    return o;
  }
  function enviar(acao, sub, dados) {
    if (ocupado) return Promise.reject(new Error(N('aguarde', 'Aguarde.'))); ocupado = true; aviso(N('gravando', 'Gravando...'), 'secondary');
    return fetch(C.url + '?' + (C.codigo ? 'codigo=' + C.codigo : 'tipo=' + C.tipo) + '&acao=' + acao, {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': C.csrf, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ sub: sub, dados: dados || {} })
    }).then(function (r) { return r.json().catch(function () { return { ok: false, message: N('resposta_invalida', 'Resposta inválida do servidor.') }; }); })
      .then(function (res) { ocupado = false; if (!res.ok) throw new Error(res.message || N('nao_concluiu', 'Não foi possível concluir.')); return res; })
      .catch(function (e) { ocupado = false; throw e; });
  }
  // Migração 336: análise da causa com IA (o texto da análise que está na tela é gravado antes de aplicar, como nas janelas)
  // Migração 337: parecer da IA sobre a eficácia
  if (C.iaef && window.GPNcEficaciaIa) window.GPNcEficaciaIa.iniciar(C, { aviso: aviso, recarregar: function (res, codanalise) {
    aviso(res.message, 'success');
    setTimeout(function () { window.location.href = C.url + '?codigo=' + C.codigo + '&aba=eficacia' + (codanalise ? '&ia_ef=' + codanalise : ''); }, 900); },
    antes: function () { var f = document.querySelector('[data-form="eficacia"]'); if (!f) return Promise.resolve(); var d = dadosDe(f); delete d.RECUSA_DO_GESTOR; return enviar('eficacia', 'salvar', d); } });
  if (C.ia && window.GPNcIa) window.GPNcIa.iniciar(C, { aviso: aviso, recarregar: function (res) { recarregar(res, 'analise', res.acoes && res.acoes.length ? 'plano' : (res.causas && res.causas.length ? 'causas' : '')); },
    antes: function () { var analise = document.querySelector('[data-form="analise"]'); return analise ? enviar('analise', 'salvar', dadosDe(analise)) : Promise.resolve(); } });
  // Volta para a aba (e sub-aba da análise: analise | causas | plano) em que o usuário estava
  function recarregar(res, aba, sub) {
    aviso(res.message, 'success');
    var cod = res.codigo || C.codigo;
    if (aba === 'analise' && !sub) { var at = document.querySelector('[data-nc-sub].active'); if (at) sub = at.getAttribute('data-nc-sub'); }
    setTimeout(function () { window.location.href = C.url + '?codigo=' + cod + (aba ? '&aba=' + aba : '') + (sub ? '&sub=' + sub : ''); }, 700);
  }
  document.querySelectorAll('[data-enviar]').forEach(function (b) {
    b.addEventListener('click', function (ev) {
      ev.preventDefault();
      var acao = b.getAttribute('data-enviar'), sub = b.getAttribute('data-sub'), conf = b.getAttribute('data-confirmar');
      if (conf && !b.hasAttribute('data-armado')) { b.setAttribute('data-armado', '1'); aviso(conf + ' ' + N('clique_confirmar', 'Clique de novo para confirmar.'), 'warning'); setTimeout(function () { b.removeAttribute('data-armado'); }, 6000); return; }
      var dados = dadosDe(document.querySelector('[data-form="' + acao + '"]'));
      enviar(acao, sub, dados).then(function (res) { recarregar(res, sub === 'salvar' ? acao : ''); }).catch(function (e) { aviso(e.message, 'danger'); });
    });
  });
  // Causas e plano de ação (janelas)
  function modal(id, valores) {
    var m = document.getElementById(id); if (!m) return; var f = m.querySelector('form'); f.reset();
    Object.keys(valores || {}).forEach(function (k) { if (f.elements[k]) f.elements[k].value = valores[k] === null ? '' : valores[k]; });
    bootstrap.Modal.getOrCreateInstance(m).show();
  }
  var bc = document.querySelector('[data-causa-nova]'); if (bc) bc.addEventListener('click', function () { modal('ncCausa', { CODCAUSA: '' }); });
  var bp = document.querySelector('[data-plano-novo]'); if (bp) bp.addEventListener('click', function () { modal('ncPlano', { CODPLANOACAO: '', STATUS: '0' }); });
  document.querySelectorAll('[data-causa-editar]').forEach(function (b) { b.addEventListener('click', function () { modal('ncCausa', JSON.parse(b.getAttribute('data-causa-editar'))); }); });
  // Responsável de uma ação do Planejamento: só status e observação ficam editáveis (a procedure confere)
  function soStatus(ativo) { var f = document.querySelector('#ncPlano form'); if (!f) return; Array.prototype.forEach.call(f.elements, function (el) { if (el.name && ['CODPLANOACAO', 'STATUS', 'OBS'].indexOf(el.name) < 0) el.disabled = !!ativo; }); }
  if (bp) bp.addEventListener('click', function () { soStatus(false); });
  document.querySelectorAll('[data-plano-editar]').forEach(function (b) { b.addEventListener('click', function () { soStatus(b.hasAttribute('data-so-status')); modal('ncPlano', JSON.parse(b.getAttribute('data-plano-editar'))); }); });
  document.querySelectorAll('[data-modal-form]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      ev.preventDefault(); var tipo = f.getAttribute('data-modal-form'), analise = document.querySelector('[data-form="analise"]');
      var antes = analise ? enviar('analise', 'salvar', dadosDe(analise)) : Promise.resolve();
      antes.then(function () { return enviar(tipo, 'salvar', dadosDe(f)); }).then(function (res) { bootstrap.Modal.getInstance(f.closest('.modal')).hide(); recarregar(res, 'analise', tipo === 'causa' ? 'causas' : 'plano'); }).catch(function (e) { aviso(e.message, 'danger'); });
    });
  });
  document.querySelectorAll('[data-excluir]').forEach(function (b) {
    b.addEventListener('click', function () {
      var tipo = b.getAttribute('data-excluir'), d = {}; d[tipo === 'causa' ? 'CODCAUSA' : 'CODPLANOACAO'] = b.getAttribute('data-cod');
      var excluir = function () {
        var analise = document.querySelector('[data-form="analise"]'), antes = analise ? enviar('analise', 'salvar', dadosDe(analise)) : Promise.resolve();
        antes.then(function () { return enviar(tipo, 'excluir', d); }).then(function (res) { recarregar(res, 'analise'); }).catch(function (e) { aviso(e.message, 'danger'); });
      };
      // c537: o Excluir fica na barra do grid; a confirmação é a mensagem padrão do portal (GreenPortalPopups.mensagem)
      var host = window.top || window;
      try {
        if (host.GreenPortalPopups && typeof host.GreenPortalPopups.mensagem === 'function' && host.document.getElementById('greenPortalMensagemModal')) {
          host.GreenPortalPopups.mensagem({ titulo: N('excluir', 'Excluir'), mensagem: tipo === 'causa' ? N('excluir_causa', 'Excluir a causa selecionada? As ações dela também serão excluídas.') : N('excluir_acao', 'Excluir a ação selecionada?'), icone: 'bi-exclamation-triangle', confirmar: excluir });
          return;
        }
      } catch (e) { }
      if (window.confirm(tipo === 'causa' ? N('excluir_causa', 'Excluir a causa selecionada? As ações dela também serão excluídas.') : N('excluir_acao', 'Excluir a ação selecionada?'))) excluir();
    });
  });

  // Anexos no GED, nas mesmas pastas do legado (ocorrência, evidências da análise, arquivos de cada ação). Ver / enviar / excluir
  // seguem GedPermissaoTipo (quem pode ver a ocorrência); ocorrência finalizada fica só para consulta.
  var gedBotoes = Array.prototype.slice.call(document.querySelectorAll('[data-ged]'));
  function contarGed() {
    var porTipo = {};
    gedBotoes.forEach(function (b) { (porTipo[b.dataset.ged] = porTipo[b.dataset.ged] || []).push(b.dataset.valor); });
    Object.keys(porTipo).forEach(function (tipo) {
      fetch('api/ged_contagens.php?tipo=' + encodeURIComponent(tipo) + '&ids=' + encodeURIComponent(JSON.stringify(porTipo[tipo])), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); }).then(function (res) {
          if (!res || !res.ok) return;
          gedBotoes.forEach(function (b) { if (b.dataset.ged !== tipo) return; var q = b.querySelector('[data-ged-qtd]'), n = (res.counts || {})[b.dataset.valor]; if (q) q.textContent = n ? ' (' + n + ')' : ''; });
        }).catch(function () {});
    });
  }
  gedBotoes.forEach(function (b) {
    b.addEventListener('click', function (ev) {
      ev.preventDefault();
      var host = window.top || window, url = new URL('gestao_arquivos.php?tipo=' + encodeURIComponent(b.dataset.ged) + '&chave=' + encodeURIComponent(b.dataset.chave) + '&valor=' + encodeURIComponent(b.dataset.valor), window.location.href).toString();
      if (host.GreenPortalPopups && host.jQuery) {
        var frame = host.jQuery('<iframe>', { src: url, style: 'width:100%;height:100%;border:0;display:block;' });
        frame.on('load', contarGed);
        var opcoes = { titulo: b.dataset.titulo || N('arquivos', 'Arquivos'), conteudo: frame, compacto: true, redimensionavel: true };
        if (typeof host.GreenPortalPopups.transacaoIndependente === 'function') host.GreenPortalPopups.transacaoIndependente(opcoes); else host.GreenPortalPopups.transacao(opcoes);
      } else window.open(url, '_blank');
    });
  });
  if (gedBotoes.length) contarGed();
  // PDF da ocorrência no popup padrão do portal (c528); sem o portal, abre o link normalmente
  document.querySelectorAll('[data-nc-pdf]').forEach(function (a) {
    a.addEventListener('click', function (ev) {
      var host = null; try { host = window.top && window.top.GreenPortalPopups && window.top.jQuery ? window.top : null; } catch (x) { host = null; }
      if (!host) return;
      ev.preventDefault();
      var url = new URL(a.getAttribute('href'), window.location.href).toString(), titulo = a.dataset.titulo || N('pdf_nc', 'PDF da não conformidade');
      host.GreenPortalPopups.transacaoIndependente({ titulo: titulo, conteudo: host.jQuery('<iframe>', { src: url, title: titulo, style: 'width:100%;height:100%;border:0;display:block;' }), redimensionavel: true });
    });
  });
}());
</script>
<script>
// Migração 356: a Ajuda (F1) acompanha a aba aberta da NC (chave nc_ocorrencia_<aba> em data-help-transaction)
(function () {
  var marca = document.querySelector('[data-nc-ajuda-aba]');
  if (!marca) return;
  document.querySelectorAll('[data-bs-toggle="tab"][data-bs-target^="#aba-"]').forEach(function (b) {
    b.addEventListener('shown.bs.tab', function () { marca.setAttribute('data-help-transaction', 'nc_ocorrencia_' + b.getAttribute('data-bs-target').substring(5)); });
  });
}());
</script>
</body>
</html>
