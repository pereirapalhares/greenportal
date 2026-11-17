<?php
declare(strict_types=1);

/*
 * API da Pesquisa de documentos (principal/busca_documentos.php). GET, JSON.
 * Parâmetros: q (texto), tipos[], formatos[], situacoes[], unidades[], item (REGISTRO|ARQUIVO), validade_ate (AAAA-MM-DD),
 *             ordem (relevancia|nome|validade|modificado|<coluna>_asc|<coluna>_desc), pagina (1..), por_pagina (10..100).
 * Banco: CALL sp_busca_pesquisar_negocios (migração 264) com as permissões da sessão: transações com Visualizar
 * (e Visualizar total), ADMINISTRADOR e o usuário (escopo por responsável e por unidade).
 * Resultado agrupado pelo negócio (grupos): o cadastro (licença, controle, requisito, treinamento, infraestrutura) ou, na
 * Portaria, o fornecedor / funcionário; cada grupo traz os seus itens (registros e arquivos) e as seções de vínculos (uma por
 * relação do catálogo busca_relacao, nos dois sentidos). Parâmetros grupo_tipo + grupo_chave: só aquele negócio ("Ver na
 * pesquisa" de um vínculo). total / paginas contam grupos. Cada item volta com os links: abrir (transação no registro), arquivos (janela
 * do GED) e ver/baixar (arquivo).
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once dirname(__DIR__, 2) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__, 2) . '/classes/IdiomaService.php';
require_once dirname(__DIR__, 2) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__, 2) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__, 2) . '/classes/TenantContext.php';

const BUSCA_MENU = 'BUSCA_DOCUMENTOS';
// A pesquisa vale para toda empresa em que a migração 264 foi aplicada (procedure sp_busca_pesquisar no banco da empresa)
/** tipo => campo da chave na janela de arquivos (gestao_arquivos.php) */
const BUSCA_CHAVE_GED = ['licencas' => 'CODLICENCA', 'controles' => 'CODDOCUMENTO', 'treinamentos' => 'CODTREINAMENTO', 'infraestrutura' => 'CODINFRAESTRUTURA',
    'fornecedorcriterios' => 'CODFORNECEDORXCRITERIOS', 'funcionariocriterios' => 'CODIGO', 'funcionariotreinamentos' => 'CODIGO'];

header('Cache-Control: no-store');
$json = static function (array $data, int $status = 200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; };
$sessao = ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.'];
if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', $sessao)], 401);
$env = GreenPortalDatabase::env();
$pdo = TenantContext::connect($env, $_SESSION);
if (!UsuarioSessao::atualizar($pdo, $_SESSION)) $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sessao', $sessao)], 401);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (empty(PermissaoTransacao::obter(BUSCA_MENU)['pode_visualizar'])) $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sem_permissao', ['pt' => 'Seu perfil não tem permissão para acessar esta transação.', 'en' => 'Your profile is not allowed to access this transaction.', 'es' => 'Su perfil no tiene permiso para acceder a esta transacción.'])], 403);
if (!(int)$pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_busca_pesquisar'")->fetchColumn()) $json(['ok' => false, 'message' => IdiomaService::t('busca_documentos.indisponivel', ['pt' => 'A pesquisa de documentos ainda não está disponível para esta empresa.', 'en' => 'Document search is not yet available for this company.', 'es' => 'La búsqueda de documentos aún no está disponible para esta empresa.'])], 409);

// Texto → modo booleano do MATCH (ngram): cada palavra ou "frase" obrigatória; palavras de 1 letra são ignoradas.
$termo = static function (string $q): string {
    $partes = [];
    if (preg_match_all('/"([^"]+)"|(\S+)/u', $q, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $t = trim(preg_replace('/[+\-<>()~*@"\'\\\\]+/u', ' ', $x[1] !== '' ? $x[1] : ($x[2] ?? '')) ?? '');
            $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');
            if (mb_strlen($t) < 2) continue;
            $partes[] = '+"' . $t . '"';
            if (count($partes) >= 12) break;
        }
    }
    return implode(' ', $partes);
};
$lista = static function (string $nome): array {
    $v = $_GET[$nome] ?? [];
    $v = is_array($v) ? $v : [$v];
    return array_values(array_slice(array_unique(array_map(static fn($x) => mb_substr(trim((string)$x), 0, 500), array_filter($v, 'is_scalar'))), 0, 50));
};

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 500);
$filtros = ['tipos' => $lista('tipos'), 'formatos' => $lista('formatos'), 'situacoes' => $lista('situacoes'), 'unidades' => $lista('unidades')];
$item = strtoupper((string)($_GET['item'] ?? ''));
if (in_array($item, ['REGISTRO', 'ARQUIVO'], true)) $filtros['item'] = $item;
$grupoTipo = (string)($_GET['grupo_tipo'] ?? ''); $grupoChave = mb_substr((string)($_GET['grupo_chave'] ?? ''), 0, 100);
if (preg_match('/^[a-z0-9_]{1,40}$/', $grupoTipo) && $grupoChave !== '') $filtros['grupo'] = ['t' => $grupoTipo, 'c' => $grupoChave];
if (!empty($_GET['literal'])) $filtros['literal'] = 1;   // "Pesquisar só o texto": sem o dicionário
$validade = (string)($_GET['validade_ate'] ?? '');
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $validade)) $filtros['validade_ate'] = $validade;
// relevancia | nome | validade | modificado (pesquisa Avançada) ou <coluna>_asc|_desc (clique no cabeçalho do grid)
$ordem = (string)($_GET['ordem'] ?? '');
if (!preg_match('/^(relevancia|(nome|titulo|tipo|situacao|unidade|responsavel|validade|modificado)(_(asc|desc))?)$/', $ordem)) $ordem = 'relevancia';
$porPagina = max(10, min(100, (int)($_GET['por_pagina'] ?? 20)));
$pagina = max(1, (int)($_GET['pagina'] ?? 1));

// Permissões da sessão: transações com Visualizar (t = Visualizar total)
$admin = strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? '')) === 'ADMINISTRADOR';
$permissoes = [];
foreach ((array)($_SESSION['permissoes'] ?? []) as $p) {
    if (empty($p['pode_visualizar'])) continue;
    $permissoes[] = ['m' => strtoupper((string)($p['menu_codigo'] ?? '')), 't' => empty($p['pode_visualizar_total']) ? 0 : 1];
}
$usuario = (int)($_SESSION['usuario']['codusuario'] ?? 0);

// Exportação para o Excel (permissão "Exportar Excel" do perfil na transação BUSCA_DOCUMENTOS), no processo padrão das exportações:
// grava o pedido em exportacoes_grid (SOLICITADA) com a pesquisa e as permissões do usuário, envia o alerta "Exportação iniciada";
// o serviço GreenPortal-ExportWorker (Agendador de Tarefas) gera a planilha (ExportacaoGridWorker, transação busca_documentos) e
// envia o alerta "Exportação concluída" com o link para baixar.
if ((string)($_GET['action'] ?? '') === 'exportar') {
    if (empty(PermissaoTransacao::obter(BUSCA_MENU)['pode_exportar_excel'])) $json(['ok' => false, 'message' => IdiomaService::t('comum.erro.sem_permissao', ['pt' => 'Seu perfil não tem permissão para exportar.', 'en' => 'Your profile is not allowed to export.', 'es' => 'Su perfil no tiene permiso para exportar.'])], 403);
    $crud = static function (string $sql, array $p) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); while ($s->nextRowset()) {} return $r; };
    $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $raiz = $esquema . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''), 3)), '/');
    $pedido = ['termo' => $termo($q), 'q' => $q, 'filtros' => $filtros ?: new stdClass(), 'permissoes' => $permissoes, 'admin' => $admin ? 1 : 0, 'usuario' => $usuario,
        'ordem' => $ordem, 'raiz' => $raiz];
    $tipoInfo = 'Pesquisa de documentos' . ($q !== '' ? ' "' . mb_substr($q, 0, 80) . '"' : '');
    try {
        $crud('CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'exportacoes_grid', 'dados' => json_encode(['codusuario' => $usuario, 'transacao' => 'busca_documentos',
            'filtros' => json_encode($pedido, JSON_UNESCAPED_UNICODE), 'busca' => mb_substr($q, 0, 500),
            'colunas' => json_encode(['colunas' => [], '__config' => ['base' => 'busca_documentos', 'tipo' => $tipoInfo]], JSON_UNESCAPED_UNICODE), 'status' => 'SOLICITADA'], JSON_UNESCAPED_UNICODE)]);
        $jobs = $crud('CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', ['tabela' => 'exportacoes_grid', 'filtros' => json_encode(['codusuario' => $usuario, 'transacao' => 'busca_documentos', 'status' => 'SOLICITADA'])]);
        usort($jobs, static fn(array $a, array $b): int => ((int)($b['codexportacaogrid'] ?? 0)) <=> ((int)($a['codexportacaogrid'] ?? 0)));
        $jobId = (int)($jobs[0]['codexportacaogrid'] ?? 0);
        if ($jobId) {
            $quando = (string)($jobs[0]['solicitado_em'] ?? date('Y-m-d H:i:s'));
            $crud('CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'alertas', 'dados' => json_encode(['codusuario' => $usuario, 'titulo' => 'Exportação iniciada',
                'mensagem' => 'A exportação de ' . $tipoInfo . ' foi iniciada em ' . date('d/m/Y H:i', strtotime($quando) ?: time()) . '.',
                'link_status' => '/principal/export_status.php?codexportacaogrid=' . $jobId . '&transacao=busca_documentos', 'lido' => 0], JSON_UNESCAPED_UNICODE)]);
        }
    } catch (Throwable $e) {
        error_log('busca_documentos exportar: ' . $e->getMessage());
        $json(['ok' => false, 'message' => IdiomaService::t('busca_documentos.erro_exportar', ['pt' => 'Não foi possível solicitar a exportação agora. Tente de novo em instantes.', 'en' => 'The export could not be requested now. Try again shortly.', 'es' => 'No fue posible solicitar la exportación ahora. Intente de nuevo en instantes.'])], 500);
    }
    $json(['ok' => true, 'id' => $jobId ?? 0, 'message' => IdiomaService::t('comum.msg.exportacao_pedido', ['pt' => 'A exportação foi solicitada e será disponibilizada nos alertas.', 'en' => 'The export was requested and will be available in the alerts.', 'es' => 'La exportación fue solicitada y estará disponible en las alertas.'])]);
}

try {
    $s = $pdo->prepare('CALL sp_busca_pesquisar_negocios(?, ?, ?, ?, ?, ?, ?, ?)');
    $s->execute([$termo($q), json_encode($filtros, JSON_UNESCAPED_UNICODE), json_encode($permissoes), $admin ? 1 : 0, $usuario, $ordem, $porPagina, ($pagina - 1) * $porPagina]);
    $conjuntos = [];
    do { $conjuntos[] = $s->fetchAll(PDO::FETCH_ASSOC); } while ($s->nextRowset());
    $s->closeCursor();
    $r = $pdo->query('CALL sp_busca_resumo()'); $resumo = $r->fetchAll(PDO::FETCH_ASSOC); $r->closeCursor(); while ($r->nextRowset()) {}
} catch (Throwable $e) {
    error_log('busca_documentos: ' . $e->getMessage());
    $json(['ok' => false, 'message' => IdiomaService::t('busca_documentos.erro', ['pt' => 'Não foi possível pesquisar agora. Tente de novo em instantes.', 'en' => 'The search could not be completed now. Try again shortly.', 'es' => 'No fue posible buscar ahora. Intente de nuevo en instantes.'])], 500);
}

$link = static fn(string $pagina, string $filtros): string => $pagina . '?filtros=' . rawurlencode($filtros !== '' ? $filtros : '{}');
$janelaArquivos = static function (?string $gedTipo, $gedValor): ?string {
    $gedTipo = (string)$gedTipo; $gedValor = (int)$gedValor;
    return $gedTipo !== '' && $gedValor > 0 && isset(BUSCA_CHAVE_GED[$gedTipo])
        ? '/principal/gestao_arquivos.php?' . http_build_query(['tipo' => $gedTipo, 'chave' => BUSCA_CHAVE_GED[$gedTipo], 'valor' => $gedValor]) : null;
};
// Arquivo: Visualizar abre o visualizador do sistema (sem Salvar / Imprimir); Download só com "Baixar/imprimir arquivos"
// (pode_baixar_arquivos, migração 266), pela mesma regra da janela de Arquivos (GedPermissaoTipo).
require_once dirname(__DIR__, 2) . '/classes/GedPermissaoTipo.php';
$podeBaixarTipo = static function (string $gedTipo): bool {
    static $cache = [];
    return $cache[$gedTipo] ??= !empty(GedPermissaoTipo::obter($gedTipo)['pode_baixar_arquivos']);
};
$item = static function (array $i) use ($link, $janelaArquivos, $podeBaixarTipo): array {
    $arquivos = $janelaArquivos($i['ged_tipo'] ?? null, $i['ged_valor'] ?? 0);
    $api = 'api/gestao_arquivos.php?' . http_build_query(['tipo' => (string)($i['ged_tipo'] ?? ''), 'valor' => (int)($i['ged_valor'] ?? 0), 'arquivo' => (string)($i['arquivo'] ?? '')]);
    $ehArquivo = ($i['item'] ?? '') === 'ARQUIVO' && $arquivos;
    $ver = $ehArquivo ? '/principal/visualizador_arquivo.php?u=' . rawurlencode($api . '&action=visualizar') : null;
    $baixar = $ehArquivo && $podeBaixarTipo((string)$i['ged_tipo']) ? '/principal/' . $api . '&action=download' : null;
    return [
        'id' => (int)$i['codbusca'], 'tipo' => $i['tipo'], 'item' => $i['item'] ?? 'REGISTRO', 'chave' => $i['chave'], 'nome' => $i['nome'], 'arquivo' => $i['arquivo'] ?? '',
        'titulo' => $i['titulo'], 'descricao' => $i['descricao'], 'situacao' => $i['situacao'], 'cor' => $i['situacao_cor'], 'validade' => $i['validade'],
        'unidade' => $i['unidade'], 'formato' => $i['formato'] ?? 'registro', 'tamanho' => isset($i['tamanho']) ? (int)$i['tamanho'] : null,
        'modificado' => $i['modificado_em'] ?? null, 'indexado' => $i['indexado_em'] ?? null, 'transacao' => $i['transacao'],
        'encontrado' => !empty($i['encontrado']), 'responsavel' => $i['responsavel'] ?? null, 'trecho' => $i['trecho'] ?? null,
        'abrir' => $link((string)$i['pagina'], (string)($i['filtros'] ?? '')), 'arquivos' => $arquivos, 'ver' => $ver, 'baixar' => $baixar,
    ];
};
// Negócio da Portaria: abre a tela já filtrada pelo fornecedor / funcionário
$negocioPortaria = [
    'fornecedor' => ['/principal/portaria_documentacaofornecedor.php', 'CODFORNECEDOR', 'PORTARIA_DOCUMENTACAO_FORNECEDOR'],
    'funcionario' => ['/principal/portaria_documentacaofuncionario.php', 'CODFUNCIONARIO', 'PORTARIA_DOCUMENTACAO_FUNCIONARIO'],
];
$porGrupo = static function (array $linhas) use ($item): array {
    $saida = [];
    foreach ($linhas as $l) $saida[$l['grupo_tipo'] . ':' . $l['grupo_chave']][] = $item($l) + (isset($l['total_grupo']) ? ['total_grupo' => (int)$l['total_grupo']] : []);
    return $saida;
};
// Grupos (negócios) de um resultado de sp_busca_pesquisar_negocios (também usado nos resultados pelo sentido, migração 346)
$montarGrupos = static function (array $conjuntos) use ($porGrupo, $item, $link, $janelaArquivos, $negocioPortaria): array {
    $filhos = $porGrupo($conjuntos[2] ?? []);
    // Vínculos por relação do catálogo (seções), nos dois sentidos
    $idioma = IdiomaService::idioma();
    $secoes = [];
    foreach ($conjuntos[3] ?? [] as $v) {
        $gid = $v['grupo_tipo'] . ':' . $v['grupo_chave'];
        $sid = $v['relacao'] . ':' . $v['lado'];
        if (!isset($secoes[$gid][$sid])) {
            $secoes[$gid][$sid] = ['relacao' => $v['relacao'], 'lado' => $v['lado'], 'rotulo' => (string)($v['rotulo_' . $idioma] ?? '') !== '' ? $v['rotulo_' . $idioma] : $v['rotulo_pt'],
                'icone' => $v['icone'] ?: 'bi-link-45deg', 'total' => (int)$v['total_grupo'], 'itens' => []];
        }
        $negocio = ['tipo' => $v['nb_tipo'], 'chave' => $v['nb_chave'], 'nome' => $v['nb_nome'] ?? $v['nome']];
        if ($v['codbusca'] !== null) {
            $it = $item($v);
        } else {
            // Negócio sem cadastro próprio (fornecedor / funcionário da Portaria)
            $np = $negocioPortaria[$v['nb_tipo']] ?? null;
            $it = ['id' => null, 'tipo' => $v['nb_tipo'], 'item' => 'REGISTRO', 'chave' => $v['nb_chave'], 'nome' => $v['nb_nome'], 'arquivo' => '',
                'titulo' => $v['nb_detalhe'], 'descricao' => $v['nb_detalhe'], 'situacao' => null, 'cor' => $v['nb_cor'], 'validade' => null, 'unidade' => null,
                'formato' => 'registro', 'tamanho' => null, 'modificado' => null, 'indexado' => null, 'transacao' => $np[2] ?? null, 'encontrado' => false, 'responsavel' => null, 'trecho' => null,
                'abrir' => $np ? $link($np[0], (string)json_encode([$np[1] => ['value' => (string)$v['nb_chave'], 'exact' => true]])) : null, 'arquivos' => null, 'ver' => null];
        }
        $secoes[$gid][$sid]['itens'][] = $it + ['negocio' => $negocio];
    }
    $grupos = [];
    foreach ($conjuntos[1] ?? [] as $g) {
        $id = $g['grupo_tipo'] . ':' . $g['grupo_chave'];
        if ($g['cab_codbusca'] !== null) {
            $abrir = $link((string)$g['cab_pagina'], (string)($g['cab_filtros'] ?? ''));
            $arquivos = $janelaArquivos($g['cab_ged_tipo'] ?? null, $g['cab_ged_valor'] ?? 0);
            $transacao = $g['cab_transacao'];
        } else {
            $np = $negocioPortaria[$g['grupo_tipo']] ?? null;
            $abrir = $np ? $link($np[0], (string)json_encode([$np[1] => ['value' => (string)$g['grupo_chave'], 'exact' => true]])) : null;
            $arquivos = null;
            $transacao = $np[2] ?? null;
        }
        $vs = array_values($secoes[$id] ?? []);
        $grupos[] = [
            'id' => $id, 'tipo' => $g['grupo_tipo'], 'chave' => $g['grupo_chave'], 'nome' => $g['nome'], 'detalhe' => $g['detalhe'],
            'descricao' => $g['cab_descricao'] ?? null, 'situacao' => $g['situacao'], 'cor' => $g['cor'], 'unidade' => $g['unidade'],
            'validade' => $g['validade'], 'modificado' => $g['modificado'], 'acertos' => (int)$g['acertos'], 'transacao' => $transacao,
            'responsavel' => $g['responsavel'] ?? null, 'trecho' => $g['trecho'] ?? null,
            'registro' => $g['cab_codbusca'] !== null,
            'contagens' => ['registros' => (int)$g['registros'], 'arquivos' => (int)$g['arquivos'], 'pendentes' => (int)$g['pendentes'], 'a_vencer' => (int)$g['a_vencer']],
            'abrir' => $abrir, 'arquivos' => $arquivos,
            'itens' => $filhos[$id] ?? [],
            'secoes' => $vs, 'vinculos_total' => array_sum(array_column($vs, 'total')),
        ];
    }
    return $grupos;
};
$grupos = $montarGrupos($conjuntos);
$total = (int)($conjuntos[0][0]['total'] ?? 0);

// Migração 346: também pelo sentido (chave na tela, parâmetro sentido=1): na 1ª página de uma pesquisa por texto, os negócios
// parecidos pelo sentido que não vieram pelas palavras (até 10), com a semelhança; mesmas permissões e filtros da pesquisa
$semanticos = []; $semanticaAviso = null;
$querSentido = (string)($_GET['sentido'] ?? '1') !== '0';
if ($querSentido && $q !== '' && $pagina === 1 && !isset($filtros['grupo']) && empty($filtros['literal'])) {
    require_once dirname(__DIR__, 2) . '/classes/BuscaSemantica.php';
    try {
        if (BuscaSemantica::disponivel($pdo)) {
            $bs = new BuscaSemantica($pdo, $env);
            $vetor = $bs->vetorConsulta($q, $usuario);
            if ($vetor === null) $semanticaAviso = $bs->configurada() ? 'indisponivel' : null;
            else {
                $parecidos = $bs->parecidos($vetor, 80);
                if ($parecidos) {
                    $fs = $filtros; $fs['semantico'] = $parecidos;
                    $s = $pdo->prepare('CALL sp_busca_pesquisar_negocios(?, ?, ?, ?, ?, ?, ?, ?)');
                    $s->execute(['', json_encode($fs, JSON_UNESCAPED_UNICODE), json_encode($permissoes), $admin ? 1 : 0, $usuario, 'relevancia', 20, 0]);
                    $cs = [];
                    do { $cs[] = $s->fetchAll(PDO::FETCH_ASSOC); } while ($s->nextRowset());
                    $s->closeCursor();
                    $jaVieram = array_flip(array_column($grupos, 'id'));
                    $rel = [];
                    foreach ($cs[1] ?? [] as $g) $rel[$g['grupo_tipo'] . ':' . $g['grupo_chave']] = (float)$g['relevancia'] / 100;
                    foreach ($montarGrupos($cs) as $g) {
                        if (isset($jaVieram[$g['id']])) continue;
                        $g['semelhanca'] = round($rel[$g['id']] ?? 0, 3);
                        $g['por_sentido'] = true;
                        $semanticos[] = $g;
                        if (count($semanticos) >= 10) break;
                    }
                }
            }
        }
    } catch (Throwable $e) { error_log('busca_documentos semântica: ' . $e->getMessage()); $semanticaAviso = 'indisponivel'; }
}
$ultima = null;
foreach ($resumo as $x) if ($x['ultima_execucao'] && ($ultima === null || $x['ultima_execucao'] > $ultima)) $ultima = $x['ultima_execucao'];
$json([
    'ok' => true, 'q' => $q, 'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina, 'paginas' => max(1, (int)ceil($total / $porPagina)),
    'grupos' => $grupos,
    'facetas' => [
        'tipos' => $conjuntos[4] ?? [], 'formatos' => $conjuntos[5] ?? [], 'situacoes' => $conjuntos[6] ?? [], 'unidades' => $conjuntos[7] ?? [],
    ],
    // Termos do dicionário usados (aviso "Resultados para ASO (Atestado de Saúde Ocupacional)")
    'dicionario' => array_map(static fn($d) => ['frase' => $d['frase'], 'termo' => $d['termo'], 'significado' => $d['significado']], $conjuntos[8] ?? []),
    'literal' => !empty($filtros['literal']),
    // Formas a destacar (sigla = só palavra inteira)
    'destaques' => array_map(static fn($d) => ['alt' => (string)$d['alt'], 'sigla' => (int)$d['sigla']], $conjuntos[9] ?? []),
    'indice_atualizado_em' => $ultima,
    'indice_vazio' => !$resumo,
    'semanticos' => $semanticos, 'sentido' => $querSentido, 'sentido_aviso' => $semanticaAviso,
]);
