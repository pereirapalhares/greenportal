<?php
declare(strict_types=1);

/*
 * Administração › Consumo de IA (migração 340, 23/10/2026): taxímetro de IA da empresa.
 *  - Consulta (ADMINISTRADOR e ADM DA EMPRESA): consumo do mês (R$, chamadas, tokens) contra o limite, por mês, por usuário, por
 *    módulo e chamada a chamada; Excel e PDF (com o nome da empresa).
 *  - Manutenção (só o perfil ADMINISTRADOR): limite mensal, folga antes do bloqueio, aviso, acréscimo de IOF/spread, crédito extra só
 *    deste mês, tabela de preços da OpenAI e cotação do dólar (PTAX automático pelo serviço "iaconsumo"; digitada à mão quando faltar);
 *    aba "Todas as empresas" com uma linha por banco.
 * Leitura só por views (vw_ia_*); gravação por api/ia_consumo.php (procedures sp_ia_*).
 */

require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/NaoConformidadeAdministracao.php';
require_once dirname(__DIR__) . '/classes/IaTaximetro.php';
require_once dirname(__DIR__) . '/components/grid_local.php';

$pdo = TransactionSecurity::connect(IaTaximetro::MENU);
$perm = PermissaoTransacao::obter(IaTaximetro::MENU);
$admin = strtoupper(trim((string)($_SESSION['usuario']['codigo_perfil'] ?? ''))) === 'ADMINISTRADOR';
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$A = NaoConformidadeAdministracao::class;
$T = IaTaximetro::class;
$empresa = trim((string)($_SESSION['tenant']['nome'] ?? $_SESSION['tenant']['empresa_nome'] ?? ''));
$banco = (string)($_SESSION['tenant']['banco_dados'] ?? '');
$num = static fn(float $v, int $casas = 4): string => sprintf('%020.' . $casas . 'f', $v);
$linhas = static function (PDO $p, string $sql, array $a = []): array { $s = $p->prepare($sql); $s->execute($a); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };

// Mês consultado (?mes=2026-10); padrão: o mês atual
$mes = preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', (string)($_GET['mes'] ?? ''), $m) ? $m[0] : date('Y-m');
$ini = $mes . '-01';
$fim = (new DateTimeImmutable($ini))->modify('+1 month')->format('Y-m-d');
$mesTexto = (new DateTimeImmutable($ini))->format('m/Y');
$erro = '';
$sit = null; $meses = []; $porUsuario = []; $porModulo = []; $chamadas = []; $cfg = []; $extras = []; $hist = []; $avisos = []; $totalMes = [];
try {
    $sit = $T::situacao($pdo);
    if ($sit === null) throw new RuntimeException('sem a migração 340');
    $meses = $linhas($pdo, 'SELECT * FROM vw_ia_consumo_mensal ORDER BY MES DESC LIMIT 24');
    $totalMes = $linhas($pdo, 'SELECT COUNT(*) AS CHAMADAS, COALESCE(SUM(CUSTO_BRL), 0) AS CUSTO_BRL, COALESCE(SUM(CUSTO_USD), 0) AS CUSTO_USD, COALESCE(SUM(TOKENS_ENTRADA), 0) AS TOKENS_ENTRADA,
        COALESCE(SUM(TOKENS_SAIDA), 0) AS TOKENS_SAIDA, COALESCE(SUM(ESTIMADO), 0) AS ESTIMADAS, COALESCE(SUM(SUCESSO = 0), 0) AS ERROS, COUNT(DISTINCT CODUSUARIO) AS USUARIOS
        FROM vw_ia_consumo WHERE CRIADO_EM >= ? AND CRIADO_EM < ?', [$ini, $fim])[0] ?? [];
    $porUsuario = $linhas($pdo, 'SELECT CODUSUARIO, NOME_USUARIO, COUNT(*) AS CHAMADAS, SUM(TOKENS_ENTRADA) AS TOKENS_ENTRADA, SUM(TOKENS_SAIDA) AS TOKENS_SAIDA, SUM(CUSTO_BRL) AS CUSTO_BRL,
        SUM(ESTIMADO) AS ESTIMADAS, MAX(CRIADO_EM) AS ULTIMA FROM vw_ia_consumo WHERE CRIADO_EM >= ? AND CRIADO_EM < ? GROUP BY CODUSUARIO, NOME_USUARIO', [$ini, $fim]);
    $porModulo = $linhas($pdo, 'SELECT MODULO, TIPO, COUNT(*) AS CHAMADAS, SUM(TOKENS_ENTRADA) AS TOKENS_ENTRADA, SUM(TOKENS_SAIDA) AS TOKENS_SAIDA, SUM(CUSTO_BRL) AS CUSTO_BRL,
        SUM(ESTIMADO) AS ESTIMADAS, COUNT(DISTINCT CODUSUARIO) AS USUARIOS FROM vw_ia_consumo WHERE CRIADO_EM >= ? AND CRIADO_EM < ? GROUP BY MODULO, TIPO', [$ini, $fim]);
    $chamadas = $linhas($pdo, 'SELECT * FROM vw_ia_consumo WHERE CRIADO_EM >= ? AND CRIADO_EM < ? ORDER BY CRIADO_EM DESC, CODCONSUMO DESC LIMIT 5000', [$ini, $fim]);
    $cfg = $linhas($pdo, 'SELECT * FROM vw_ia_limite_config')[0] ?? [];
    $extras = $linhas($pdo, 'SELECT * FROM vw_ia_limite_extras ORDER BY CRIADO_EM DESC LIMIT 200');
    $hist = $linhas($pdo, 'SELECT * FROM vw_ia_limite_historico ORDER BY CRIADO_EM DESC, CODHISTORICO DESC LIMIT 1000');
    $avisos = $linhas($pdo, 'SELECT * FROM vw_ia_limite_avisos ORDER BY ENVIADO_EM DESC LIMIT 500');
} catch (Throwable $ex) {
    $erro = 'Consumo de IA não disponível neste banco: aplique a migração 340.';
    error_log('[consumo ia] ' . $ex->getMessage());
}
if ($empresa === '' && $banco !== '') {
    try { $s = $T::universal()->prepare('SELECT nome FROM empresa WHERE banco_dados = ? ORDER BY codempresa LIMIT 1'); $s->execute([$banco]); $empresa = trim((string)($s->fetchColumn() ?: '')); } catch (Throwable) {}
}
$precos = $T::precos();
$cambio = array_slice($T::cambio(), 0, 90);
$cotHoje = $T::cotacao(date('Y-m-d'));
$tiposTexto = static fn(string $tipo): string => $T::descricaoTipo($tipo);
$origemTexto = ['FILA' => 'Fila de IA (Agendador)', 'TELA' => 'Na tela', 'HISTORICO' => 'Anterior ao taxímetro'];
$acaoTexto = ['LIMITE' => 'Limite mensal', 'FOLGA' => 'Folga antes do bloqueio', 'AVISO' => 'Aviso (% do limite)', 'ACRESCIMO' => 'Acréscimo IOF/spread', 'EXTRA' => 'Crédito extra do mês'];
$faixaTexto = ['AVISO' => 'Perto do limite', 'LIMITE' => 'Limite atingido', 'BLOQUEIO' => 'Bloqueio'];

// ---------------------------------------------------------------- PDF do mês (o Excel vai pela fila do Agendador: api/ia_consumo.php, acao exportar)
// Aberto no popup do portal (visualização com o botão Baixar); &baixar=1 devolve o arquivo para download.
$formato = (string)($_GET['exportar'] ?? '');
if ($erro === '' && $formato === 'pdf') {
    if (empty($perm['pode_gerar_pdf'])) { http_response_code(403); exit('Seu perfil não tem permissão para gerar o PDF.'); }
    $nomeArq = 'consumo_ia_' . preg_replace('/[^a-z0-9]+/', '_', strtolower($empresa ?: $banco)) . '_' . $mes;
    $tab = static function (array $cab, array $dados) use ($e): string {
        $h = '<table><thead><tr>' . implode('', array_map(static fn($c) => '<th>' . $e($c) . '</th>', $cab)) . '</tr></thead><tbody>';
        foreach ($dados as $d) $h .= '<tr>' . implode('', array_map(static fn($v) => '<td>' . $e($v) . '</td>', $d)) . '</tr>';
        return $h . ($dados ? '' : '<tr><td colspan="' . count($cab) . '">Nenhuma chamada no mês.</td></tr>') . '</tbody></table>';
    };
    usort($porUsuario, static fn($a, $b) => (float)$b['CUSTO_BRL'] <=> (float)$a['CUSTO_BRL']);
    usort($porModulo, static fn($a, $b) => (float)$b['CUSTO_BRL'] <=> (float)$a['CUSTO_BRL']);
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:Arial,sans-serif;font-size:10px;color:#222}h1{font-size:15px;margin:0}h2{font-size:12px;margin:14px 0 4px}'
        . '.cab{border-bottom:2px solid #198754;padding-bottom:6px;margin-bottom:8px}.cab .emp{font-size:13px;font-weight:bold;color:#198754}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ccc;padding:3px 4px;text-align:left}'
        . 'th{background:#e9f5ee}.res td{border:0;padding:2px 8px 2px 0}</style></head><body>'
        . '<div class="cab"><div class="emp">' . $e($empresa ?: $banco) . '</div><h1>Consumo de IA — ' . $e($mesTexto) . '</h1><div>Gerado em ' . date('d/m/Y H:i') . ' por ' . $e((string)($_SESSION['usuario']['nome'] ?? '')) . '</div></div>'
        . '<table class="res"><tr><td><b>Custo do mês:</b> ' . $e($T::moeda((float)($totalMes['CUSTO_BRL'] ?? 0))) . ' (US$ ' . $e(number_format((float)($totalMes['CUSTO_USD'] ?? 0), 4, ',', '.')) . ')</td>'
        . '<td><b>Chamadas:</b> ' . $e($T::numero((float)($totalMes['CHAMADAS'] ?? 0))) . '</td><td><b>Tokens:</b> ' . $e($T::numero((float)($totalMes['TOKENS_ENTRADA'] ?? 0) + (float)($totalMes['TOKENS_SAIDA'] ?? 0))) . '</td>'
        . '<td><b>Limite mensal:</b> ' . $e(($cfg['LIMITE_MENSAL_BRL'] ?? null) === null ? 'sem limite' : $T::moeda((float)$cfg['LIMITE_MENSAL_BRL'])) . '</td></tr></table>'
        . ((int)($totalMes['ESTIMADAS'] ?? 0) ? '<p>' . (int)$totalMes['ESTIMADAS'] . ' chamada(s) anteriores ao taxímetro com tokens estimados pelo tamanho do texto.</p>' : '')
        . '<h2>Por usuário</h2>' . $tab(['Usuário', 'Chamadas', 'Tokens', 'Custo R$'], array_map(static fn($u) => [$u['NOME_USUARIO'], $T::numero((float)$u['CHAMADAS']), $T::numero((float)$u['TOKENS_ENTRADA'] + (float)$u['TOKENS_SAIDA']), $T::moeda((float)$u['CUSTO_BRL'])], $porUsuario))
        . '<h2>Por módulo</h2>' . $tab(['Módulo', 'Pedido', 'Chamadas', 'Usuários', 'Tokens', 'Custo R$'], array_map(static fn($x) => [$x['MODULO'], $tiposTexto((string)$x['TIPO']), $T::numero((float)$x['CHAMADAS']), $T::numero((float)$x['USUARIOS']), $T::numero((float)$x['TOKENS_ENTRADA'] + (float)$x['TOKENS_SAIDA']), $T::moeda((float)$x['CUSTO_BRL'])], $porModulo))
        . '<h2>Últimos meses</h2>' . $tab(['Mês', 'Chamadas', 'Usuários', 'Custo R$'], array_map(static fn($x) => [(new DateTimeImmutable((string)$x['MES']))->format('m/Y'), $T::numero((float)$x['CHAMADAS']), $T::numero((float)$x['USUARIOS']), $T::moeda((float)$x['CUSTO_BRL'])], array_slice($meses, 0, 12)))
        . '</body></html>';
    $auto = dirname(__DIR__) . '/vendor/autoload.php';
    if (is_file($auto)) {
        require_once $auto;
        $o = new Dompdf\Options(); $o->set('isRemoteEnabled', false); $o->set('defaultFont', 'Arial');
        $d = new Dompdf\Dompdf($o); $d->loadHtml($html, 'UTF-8'); $d->setPaper('A4', 'portrait'); $d->render(); $pdf = $d->output();
        header('Content-Type: application/pdf'); header('Content-Disposition: ' . (!empty($_GET['baixar']) ? 'attachment' : 'inline') . '; filename="' . $nomeArq . '.pdf"'); header('Content-Length: ' . strlen($pdf));
        echo $pdf; exit;
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo $html . '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},350);});</script>';
    exit;
}

// ---------------------------------------------------------------- linhas dos grids
$custoTotal = (float)($totalMes['CUSTO_BRL'] ?? 0);
$pctDe = static fn(float $v): string => $custoTotal > 0 ? number_format($v * 100 / $custoTotal, 1, ',', '.') . '%' : '—';
$rowsUsuarios = array_map(static fn($u) => ['cells' => [
    'USUARIO' => ['text' => (string)$u['NOME_USUARIO']],
    'CHAMADAS' => ['text' => $T::numero((float)$u['CHAMADAS']), 'sort' => $num((float)$u['CHAMADAS'], 0)],
    'ENTRADA' => ['text' => $T::numero((float)$u['TOKENS_ENTRADA']), 'sort' => $num((float)$u['TOKENS_ENTRADA'], 0)],
    'SAIDA' => ['text' => $T::numero((float)$u['TOKENS_SAIDA']), 'sort' => $num((float)$u['TOKENS_SAIDA'], 0)],
    'CUSTO' => ['text' => $T::moeda((float)$u['CUSTO_BRL']), 'sort' => $num((float)$u['CUSTO_BRL'])],
    'PCT' => ['text' => $pctDe((float)$u['CUSTO_BRL']), 'sort' => $num((float)$u['CUSTO_BRL'])],
    'ESTIMADAS' => ['text' => (int)$u['ESTIMADAS'] ? (string)(int)$u['ESTIMADAS'] : '—', 'sort' => $num((float)$u['ESTIMADAS'], 0)],
    'ULTIMA' => ['text' => $A::data($u['ULTIMA'], true), 'sort' => $A::ordenacao($u['ULTIMA'])],
]], $porUsuario);
$rowsModulos = array_map(static fn($x) => ['cells' => [
    'MODULO' => ['text' => (string)$x['MODULO']], 'TIPO' => ['text' => $tiposTexto((string)$x['TIPO'])],
    'CHAMADAS' => ['text' => $T::numero((float)$x['CHAMADAS']), 'sort' => $num((float)$x['CHAMADAS'], 0)],
    'USUARIOS' => ['text' => $T::numero((float)$x['USUARIOS']), 'sort' => $num((float)$x['USUARIOS'], 0)],
    'TOKENS' => ['text' => $T::numero((float)$x['TOKENS_ENTRADA'] + (float)$x['TOKENS_SAIDA']), 'sort' => $num((float)$x['TOKENS_ENTRADA'] + (float)$x['TOKENS_SAIDA'], 0)],
    'CUSTO' => ['text' => $T::moeda((float)$x['CUSTO_BRL']), 'sort' => $num((float)$x['CUSTO_BRL'])],
    'PCT' => ['text' => $pctDe((float)$x['CUSTO_BRL']), 'sort' => $num((float)$x['CUSTO_BRL'])],
]], $porModulo);
$linkRegistro = static function (array $c): array {
    $t = strtoupper((string)$c['TIPO']);
    $r = trim((string)$c['REGISTRO']);
    if (str_starts_with($t, 'NC_') && ctype_digit($r)) return ['url' => '/principal/naoconformidades_ocorrencia.php?codigo=' . $r, 'title' => 'Não conformidade ' . $r];
    return [];
};
$rowsChamadas = array_map(static fn($c) => ['open' => $linkRegistro($c), 'cells' => [
    'DATA' => ['text' => $A::data($c['CRIADO_EM'], true), 'sort' => $A::ordenacao($c['CRIADO_EM'])],
    'USUARIO' => ['text' => (string)$c['NOME_USUARIO']], 'MODULO' => ['text' => (string)$c['MODULO']], 'TIPO' => ['text' => $tiposTexto((string)$c['TIPO'])],
    'REGISTRO' => ['text' => (string)$c['REGISTRO']], 'ORIGEM' => ['text' => $origemTexto[(string)$c['ORIGEM']] ?? (string)$c['ORIGEM']],
    'MODELO' => ['text' => (string)$c['MODELO'], 'title' => 'Preço usado: ' . ((string)$c['PRECO_MODELO'] === '*' ? 'padrão (modelo sem preço próprio)' : (string)$c['PRECO_MODELO'])],
    'TOKENS' => ['text' => $T::numero((float)$c['TOKENS_ENTRADA']) . ' / ' . $T::numero((float)$c['TOKENS_SAIDA']), 'sort' => $num((float)$c['TOKENS_TOTAL'], 0),
        'title' => 'Entrada ' . (int)$c['TOKENS_ENTRADA'] . ' (em cache ' . (int)$c['TOKENS_CACHE'] . ') / saída ' . (int)$c['TOKENS_SAIDA']],
    'CUSTO' => ['text' => $T::moeda((float)$c['CUSTO_BRL'], 4), 'sort' => $num((float)$c['CUSTO_BRL']),
        'title' => 'US$ ' . number_format((float)$c['CUSTO_USD'], 6, ',', '.') . ' × ' . number_format((float)$c['COTACAO'], 4, ',', '.') . ((float)$c['ACRESCIMO_PCT'] > 0 ? ' + ' . number_format((float)$c['ACRESCIMO_PCT'], 2, ',', '.') . '%' : '')
            . ($c['COTACAO_DATA'] ? ' (PTAX de ' . $A::data($c['COTACAO_DATA']) . ')' : ' (cotação de reserva)')],
    'SITUACAO' => ['text' => (int)$c['SUCESSO'] ? ((int)$c['ESTIMADO'] ? 'Estimado' : 'OK') : 'Erro', 'title' => (string)($c['ERRO'] ?? ''),
        'html' => (int)$c['SUCESSO'] ? ((int)$c['ESTIMADO'] ? '<span class="badge text-bg-light border">Estimado</span>' : '<span class="badge text-bg-success">OK</span>') : '<span class="badge text-bg-danger">Erro</span>'],
]], $chamadas);
// Valores do histórico: R$ para limite e crédito extra, % para os percentuais (o crédito grava "12.50 em 10/2026")
$valorHist = static function (string $acao, ?string $v) use ($T): string {
    if ($v === null || trim($v) === '') return $acao === 'LIMITE' ? 'sem limite' : '—';
    if (!preg_match('/^(\d+(?:\.\d+)?)(.*)$/', trim($v), $m)) return $v;
    return in_array($acao, ['LIMITE', 'EXTRA'], true) ? $T::moeda((float)$m[1]) . $m[2] : $T::numero((float)$m[1], (float)$m[1] == floor((float)$m[1]) ? 0 : 2) . '%' . $m[2];
};
$rowsHist = array_map(static fn($h) => ['cells' => [
    'DATA' => ['text' => $A::data($h['CRIADO_EM'], true), 'sort' => $A::ordenacao($h['CRIADO_EM'])], 'USUARIO' => ['text' => (string)$h['NOME_USUARIO']],
    'ACAO' => ['text' => $acaoTexto[(string)$h['ACAO']] ?? (string)$h['ACAO']], 'ANTES' => ['text' => (string)$h['ACAO'] === 'EXTRA' ? '—' : $valorHist((string)$h['ACAO'], $h['ANTES'])],
    'DEPOIS' => ['text' => $valorHist((string)$h['ACAO'], $h['DEPOIS'])],
    'MOTIVO' => ['text' => (string)($h['MOTIVO'] ?? ''), 'title' => (string)($h['MOTIVO'] ?? '')],
]], $hist);
$rowsAvisos = array_map(static fn($a) => ['cells' => [
    'DATA' => ['text' => $A::data($a['ENVIADO_EM'], true), 'sort' => $A::ordenacao($a['ENVIADO_EM'])], 'MES' => ['text' => (new DateTimeImmutable((string)$a['MES']))->format('m/Y')],
    'FAIXA' => ['text' => $faixaTexto[(string)$a['FAIXA']] ?? (string)$a['FAIXA']], 'LIMITE' => ['text' => $T::moeda((float)$a['LIMITE_BRL'])],
    'CONSUMO' => ['text' => $T::moeda((float)$a['CONSUMO_BRL'])], 'DEST' => ['text' => (int)$a['DESTINATARIOS'] ? (string)(int)$a['DESTINATARIOS'] : '— (faixa já ultrapassada)'],
]], $avisos);
$rowsPrecos = array_map(static function ($p) use ($e, $admin, $T) {
    $json = json_encode(['codigo' => (int)$p['CODPRECO'], 'modelo' => (string)$p['MODELO'], 'descricao' => (string)$p['DESCRICAO'], 'entrada' => (float)$p['USD_ENTRADA'], 'cache' => (float)$p['USD_ENTRADA_CACHE'],
        'saida' => (float)$p['USD_SAIDA'], 'vigente' => (string)$p['VIGENTE_DESDE'], 'ativo' => (int)$p['ATIVO'], 'fonte' => (string)$p['FONTE']], JSON_UNESCAPED_UNICODE);
    $usd = static fn($v) => 'US$ ' . number_format((float)$v, 4, ',', '.');
    return ['cells' => [
        'MODELO' => ['text' => (string)$p['MODELO'], 'html' => $e($p['MODELO'] === '*' ? '* (padrão)' : $p['MODELO']) . ($admin ? greenPortalGridLocalLinha('<button type="button" data-linha-acao="editar" data-preco=\'' . $e($json) . '\'></button>') : '')],
        'DESCRICAO' => ['text' => (string)$p['DESCRICAO']], 'ENTRADA' => ['text' => $usd($p['USD_ENTRADA'])], 'CACHE' => ['text' => $usd($p['USD_ENTRADA_CACHE'])], 'SAIDA' => ['text' => $usd($p['USD_SAIDA'])],
        'VIGENTE' => ['text' => NaoConformidadeAdministracao::data($p['VIGENTE_DESDE']), 'sort' => (string)$p['VIGENTE_DESDE']],
        'ATIVO' => ['text' => (int)$p['ATIVO'] ? 'Sim' : 'Não'], 'FONTE' => ['text' => (string)$p['FONTE'], 'title' => (string)$p['FONTE']],
        'ALTERADO' => ['text' => trim((string)$p['ALTERADO_POR'] . ' · ' . NaoConformidadeAdministracao::data($p['ALTERADO_EM'], true), ' ·')],
    ]];
}, $precos);
$rowsCambio = array_map(static fn($c) => ['cells' => [
    'DATA' => ['text' => $A::data($c['DATA']), 'sort' => (string)$c['DATA']], 'VENDA' => ['text' => 'R$ ' . number_format((float)$c['COTACAO_VENDA'], 4, ',', '.')],
    'COMPRA' => ['text' => $c['COTACAO_COMPRA'] !== null ? 'R$ ' . number_format((float)$c['COTACAO_COMPRA'], 4, ',', '.') : '—'],
    'FONTE' => ['text' => (string)$c['FONTE'] === 'MANUAL' ? 'Digitada à mão' : 'PTAX (Banco Central)'],
]], $cambio);

// Todas as empresas (só ADMINISTRADOR): uma linha por banco de empresa
$rowsEmpresas = [];
if ($admin && $erro === '') {
    require_once dirname(__DIR__) . '/classes/WindowsServiceRuntime.php';
    $env = GreenPortalDatabase::env();
    $nomes = [];
    try { foreach ($T::universal()->query('SELECT banco_dados, nome FROM empresa ORDER BY codempresa')->fetchAll(PDO::FETCH_ASSOC) as $n) $nomes[strtolower((string)$n['banco_dados'])] ??= (string)$n['nome']; } catch (Throwable) {}
    try { $bancosEmp = (new WindowsServiceRuntime($env, 'consumo-ia'))->databases(); } catch (Throwable) { $bancosEmp = [$banco]; }
    if (!in_array($banco, $bancosEmp, true)) $bancosEmp[] = $banco;
    foreach ($bancosEmp as $b) {
        try {
            $p = $b === $banco ? $pdo : GreenPortalDatabase::connect($env, $b, 'GPT');
            $s = $T::situacao($p);
            if (!$s) { $rowsEmpresas[] = ['cells' => ['EMPRESA' => ['text' => $nomes[strtolower($b)] ?? $b], 'BANCO' => ['text' => $b], 'SITUACAO' => ['text' => 'Sem a migração 340']]]; continue; }
            $sx = IaTaximetro::SITUACOES[(string)$s['SITUACAO']] ?? [(string)$s['SITUACAO'], 'secondary'];
            $rowsEmpresas[] = ['cells' => [
                'EMPRESA' => ['text' => $nomes[strtolower($b)] ?? $b], 'BANCO' => ['text' => $b],
                'CONSUMO' => ['text' => $T::moeda((float)$s['CONSUMO_BRL']), 'sort' => $num((float)$s['CONSUMO_BRL'])],
                'LIMITE' => ['text' => $s['LIMITE_BRL'] === null ? 'sem limite' : $T::moeda((float)$s['LIMITE_BRL']), 'sort' => $num((float)($s['LIMITE_BRL'] ?? 0))],
                'PCT' => ['text' => $s['PCT'] === null ? '—' : number_format((float)$s['PCT'], 1, ',', '.') . '%', 'sort' => $num((float)($s['PCT'] ?? -1), 1)],
                'CHAMADAS' => ['text' => $T::numero((float)$s['CHAMADAS']), 'sort' => $num((float)$s['CHAMADAS'], 0)],
                'SITUACAO' => ['text' => $sx[0], 'html' => '<span class="badge text-bg-' . $sx[1] . '">' . htmlspecialchars($sx[0], ENT_QUOTES, 'UTF-8') . '</span>'],
            ]];
        } catch (Throwable $ex) {
            $rowsEmpresas[] = ['cells' => ['EMPRESA' => ['text' => $nomes[strtolower($b)] ?? $b], 'BANCO' => ['text' => $b], 'SITUACAO' => ['text' => 'Sem acesso: ' . mb_substr($ex->getMessage(), 0, 80)]]];
        }
    }
}

// Resumo do mês atual
$s = $sit ?? [];
$sx = IaTaximetro::SITUACOES[(string)($s['SITUACAO'] ?? 'SEM_LIMITE')] ?? ['—', 'secondary'];
$pct = isset($s['PCT']) && $s['PCT'] !== null ? (float)$s['PCT'] : null;
$barra = $pct === null ? 0 : min(100, $pct);
$corBarra = $pct === null ? 'secondary' : ($pct >= 100 ? 'danger' : ($pct >= (float)($s['AVISO_PCT'] ?? 80) ? 'warning' : 'success'));
$maxMes = max(array_merge([0.0001], array_map(static fn($x) => (float)$x['CUSTO_BRL'], $meses)));
$opcoesMes = [];
for ($i = 0; $i < 12; $i++) $opcoesMes[] = (new DateTimeImmutable(date('Y-m-01')))->modify("-$i month")->format('Y-m');
foreach ($meses as $x) $opcoesMes[] = substr((string)$x['MES'], 0, 7);
$opcoesMes = array_values(array_unique($opcoesMes)); rsort($opcoesMes);
$mesAtual = $mes === date('Y-m');
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Consumo de IA</title>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?>
<?php greenPortalGridLocalHead(); ?>
<style>.ia-cons{padding:12px}.ia-cons .card-kpi .valor{font-size:1.25rem;font-weight:600}.ia-cons .card-kpi .rot{font-size:.75rem;color:var(--bs-secondary-color)}
.ia-cons .table td,.ia-cons .table th{font-size:.8rem;vertical-align:middle}.ia-cons .barra-mes{height:10px;background:var(--bs-success);border-radius:3px;min-width:2px}
.ia-cons .cfg-card{border-left:4px solid var(--bs-success-border-subtle,#a3cfbb)}.ia-cons .ia-barra{margin-bottom:8px}.ia-cons .ia-barra .ia-mes{width:120px;display:inline-block}
.ia-cons .ia-sub{margin-bottom:8px}.ia-cons .ia-sub .nav-link{padding:.25rem .75rem;font-size:.8rem}</style>
</head>
<body class="ia-cons" data-transacao="administracao_consumo_ia" data-csrf="<?= $e($A::csrf()) ?>" data-api="api/ia_consumo.php" data-mes="<?= $e($mes) ?>">
  <h2 class="h5 mb-1"><i class="bi bi-speedometer2 me-1"></i>Consumo de IA<?= $empresa !== '' ? ' — ' . $e($empresa) : '' ?></h2>
  <p class="text-muted small mb-2">Custo das chamadas à OpenAI desta empresa: tokens informados pela OpenAI em cada resposta × preço do modelo × cotação PTAX do dia.<?= $admin ? '' : ' Seu perfil só consulta: limite, preços e cotação são mantidos pelo ADMINISTRADOR.' ?></p>
  <div data-nc-aviso hidden></div>
  <?php if ($erro !== ''): ?><div class="alert alert-warning py-2"><?= $e($erro) ?></div><?php else: ?>
  <!-- barra própria da tela num contêiner só dela (os grupos da barra juntam os botões do contêiner pai) -->
  <div class="ia-barra-fragmento"><div class="crud-toolbar menu-grid-toolbar ia-barra" data-ia-barra>
    <?php greenPortalCrudButtons(['transaction' => 'administracao_consumo_ia', 'save' => false, 'new' => false, 'duplicate' => false, 'delete' => false, 'columns' => false,
        'reload' => true, 'export' => true, 'permission' => ['pode_exportar_excel' => !empty($perm['pode_exportar_excel'])],
        'extras' => '<button type="button" class="btn btn-sm btn-outline-danger grid-crud-button" data-ia-pdf' . (empty($perm['pode_gerar_pdf']) ? ' disabled data-permission-locked="1"' : '')
            . ' title="Relatório do mês em PDF" aria-label="Relatório do mês em PDF"><i class="bi bi-file-earmark-pdf"></i></button>'
            . '<select class="form-select form-select-sm ia-mes" data-ia-mes title="Mês consultado" aria-label="Mês consultado">'
            . implode('', array_map(static fn($o) => '<option value="' . $e($o) . '"' . ($o === $mes ? ' selected' : '') . '>' . $e(substr($o, 5, 2) . '/' . substr($o, 0, 4)) . '</option>', $opcoesMes)) . '</select>']); ?>
  </div></div>
  <?php if ((string)($s['SITUACAO'] ?? '') === 'BLOQUEADO'): ?><div class="alert alert-dark py-2 small"><i class="bi bi-lock me-1"></i><?= $e($T::mensagemBloqueio($s)) ?></div>
  <?php elseif ((string)($s['SITUACAO'] ?? '') === 'EXCEDIDO'): ?><div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-octagon me-1"></i>O limite do mês foi atingido. Os pedidos de IA continuam até <?= $e($T::moeda((float)$s['BLOQUEIO_BRL'])) ?> (folga de <?= $e($T::numero((float)$s['FOLGA_PCT'])) ?>%); depois ficam bloqueados até o limite ser aumentado.</div><?php endif; ?>
  <ul class="nav nav-tabs mb-2" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#abaResumo" type="button" role="tab"><i class="bi bi-bar-chart me-1"></i>Resumo</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#abaUsuarios" type="button" role="tab"><i class="bi bi-people me-1"></i>Por usuário <span class="badge text-bg-light border"><?= count($rowsUsuarios) ?></span></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#abaModulos" type="button" role="tab"><i class="bi bi-grid me-1"></i>Por módulo <span class="badge text-bg-light border"><?= count($rowsModulos) ?></span></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#abaChamadas" type="button" role="tab"><i class="bi bi-list-ul me-1"></i>Chamadas <span class="badge text-bg-light border"><?= count($chamadas) ?></span></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#abaLimite" type="button" role="tab"><i class="bi bi-sliders me-1"></i>Limite</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#abaPrecos" type="button" role="tab"><i class="bi bi-currency-dollar me-1"></i>Preços e câmbio</button></li>
    <?php if ($admin): ?><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#abaEmpresas" type="button" role="tab"><i class="bi bi-buildings me-1"></i>Todas as empresas</button></li><?php endif; ?>
  </ul>
  <div class="tab-content">
    <!-- Resumo: indicadores do mês atual, o mês consultado e os últimos meses -->
    <div class="tab-pane fade show active" id="abaResumo" role="tabpanel">
  <div class="row g-2 mb-3">
    <div class="col-6 col-lg-3"><div class="card card-kpi h-100"><div class="card-body py-2"><div class="rot">Consumo de <?= $e(date('m/Y')) ?></div><div class="valor"><?= $e($T::moeda((float)($s['CONSUMO_BRL'] ?? 0))) ?></div>
      <div class="small text-muted"><?= $e($T::numero((float)($s['CHAMADAS'] ?? 0))) ?> chamada(s)</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-kpi h-100"><div class="card-body py-2"><div class="rot">Limite do mês</div>
      <div class="valor"><?= ($s['LIMITE_BRL'] ?? null) === null ? 'Sem limite' : $e($T::moeda((float)$s['LIMITE_BRL'])) ?></div>
      <div class="small text-muted"><?php if (($s['LIMITE_BRL'] ?? null) !== null): ?><?= (float)$s['EXTRA_BRL'] > 0 ? 'inclui ' . $e($T::moeda((float)$s['EXTRA_BRL'])) . ' de crédito extra · ' : '' ?>bloqueia em <?= $e($T::moeda((float)$s['BLOQUEIO_BRL'])) ?><?php else: ?>o ADMINISTRADOR define o limite<?php endif; ?></div></div></div></div>
    <div class="col-12 col-lg-4"><div class="card card-kpi h-100"><div class="card-body py-2"><div class="d-flex justify-content-between"><span class="rot">Uso do limite</span><span class="badge text-bg-<?= $e($sx[1]) ?>"><?= $e($sx[0]) ?></span></div>
      <div class="valor"><?= $pct === null ? '—' : $e(number_format($pct, 1, ',', '.')) . '%' ?></div>
      <div class="progress" style="height:8px" role="progressbar" aria-valuenow="<?= (int)$barra ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-<?= $e($corBarra) ?>" style="width:<?= (float)$barra ?>%"></div></div>
      <div class="small text-muted mt-1">aviso em <?= $e($T::numero((float)($s['AVISO_PCT'] ?? 80))) ?>% · folga de <?= $e($T::numero((float)($s['FOLGA_PCT'] ?? 10))) ?>% antes do bloqueio</div></div></div></div>
    <div class="col-12 col-lg-2"><div class="card card-kpi h-100"><div class="card-body py-2"><div class="rot">Dólar (PTAX venda)</div><div class="valor">R$ <?= $e(number_format($cotHoje['valor'], 4, ',', '.')) ?></div>
      <div class="small text-muted"><?= $cotHoje['data'] ? 'de ' . $e($A::data($cotHoje['data'])) . ($cotHoje['fonte'] === 'MANUAL' ? ' (digitada)' : '') : 'cotação de reserva: sem PTAX gravado' ?><?= (float)($s['ACRESCIMO_PCT'] ?? 0) > 0 ? ' · +' . $e(number_format((float)$s['ACRESCIMO_PCT'], 2, ',', '.')) . '% IOF/spread' : '' ?></div></div></div></div>
  </div>
      <div class="row g-3">
        <div class="col-lg-7"><div class="card"><div class="card-body py-2">
          <h6 class="small fw-semibold mb-2">Mês de <?= $e($mesTexto) ?></h6>
          <table class="table table-sm mb-2"><tbody>
            <tr><td>Custo</td><td class="text-end fw-semibold"><?= $e($T::moeda($custoTotal)) ?></td><td class="text-end text-muted">US$ <?= $e(number_format((float)($totalMes['CUSTO_USD'] ?? 0), 4, ',', '.')) ?></td></tr>
            <tr><td>Chamadas</td><td class="text-end"><?= $e($T::numero((float)($totalMes['CHAMADAS'] ?? 0))) ?></td><td class="text-end text-muted"><?= (int)($totalMes['ERROS'] ?? 0) ? (int)$totalMes['ERROS'] . ' com erro' : '' ?></td></tr>
            <tr><td>Usuários</td><td class="text-end"><?= $e($T::numero((float)($totalMes['USUARIOS'] ?? 0))) ?></td><td></td></tr>
            <tr><td>Tokens (entrada / saída)</td><td class="text-end"><?= $e($T::numero((float)($totalMes['TOKENS_ENTRADA'] ?? 0))) ?> / <?= $e($T::numero((float)($totalMes['TOKENS_SAIDA'] ?? 0))) ?></td><td></td></tr>
            <tr><td>Custo médio por chamada</td><td class="text-end"><?= (int)($totalMes['CHAMADAS'] ?? 0) ? $e($T::moeda($custoTotal / (int)$totalMes['CHAMADAS'], 4)) : '—' ?></td><td></td></tr>
          </tbody></table>
          <?php if ((int)($totalMes['ESTIMADAS'] ?? 0)): ?><div class="small text-muted"><i class="bi bi-info-circle me-1"></i><?= (int)$totalMes['ESTIMADAS'] ?> chamada(s) deste mês são anteriores ao taxímetro: os tokens foram estimados pelo tamanho do texto (marcadas como "Estimado").</div><?php endif; ?>
        </div></div></div>
        <div class="col-lg-5"><div class="card"><div class="card-body py-2">
          <h6 class="small fw-semibold mb-2">Últimos meses</h6>
          <?php if (!$meses): ?><div class="small text-muted">Nenhuma chamada registrada ainda.</div><?php endif; ?>
          <table class="table table-sm mb-0"><tbody>
          <?php foreach (array_slice($meses, 0, 12) as $x): $mx = substr((string)$x['MES'], 0, 7); ?>
            <tr<?= $mx === $mes ? ' class="table-active"' : '' ?>><td style="width:70px"><a href="?mes=<?= $e($mx) ?>"><?= $e(substr($mx, 5, 2) . '/' . substr($mx, 0, 4)) ?></a></td>
              <td><div class="barra-mes" style="width:<?= max(1, round((float)$x['CUSTO_BRL'] * 100 / $maxMes)) ?>%"></div></td>
              <td class="text-end" style="width:110px"><?= $e($T::moeda((float)$x['CUSTO_BRL'])) ?></td><td class="text-end text-muted" style="width:90px"><?= $e($T::numero((float)$x['CHAMADAS'])) ?> cham.</td></tr>
          <?php endforeach; ?>
          </tbody></table>
        </div></div></div>
      </div>
    </div>
    <div class="tab-pane fade" id="abaUsuarios" role="tabpanel">
      <p class="small text-muted mb-2">Consumo de <?= $e($mesTexto) ?> por usuário (troque o mês na barra).</p>
      <?php greenPortalGridLocal(['transaction' => 'administracao_consumo_ia_usuarios', 'fragment_class' => 'ia-cons-usuarios', 'id' => 'iaConsUsuarios',
          'columns' => ['USUARIO' => ['label' => 'Usuário'], 'CHAMADAS' => ['label' => 'Chamadas', 'filter' => 'none', 'width' => '100px'], 'ENTRADA' => ['label' => 'Tokens de entrada', 'filter' => 'none', 'width' => '140px'],
              'SAIDA' => ['label' => 'Tokens de saída', 'filter' => 'none', 'width' => '130px'], 'CUSTO' => ['label' => 'Custo', 'filter' => 'none', 'width' => '120px'], 'PCT' => ['label' => '% do mês', 'filter' => 'none', 'width' => '90px'],
              'ESTIMADAS' => ['label' => 'Estimadas', 'filter' => 'none', 'width' => '90px'], 'ULTIMA' => ['label' => 'Última chamada', 'filter' => 'none', 'width' => '140px']],
          'cell_boxes' => true, 'rows' => $rowsUsuarios, 'default_sort' => ['CUSTO', 'desc'], 'buttons' => ['reload' => false, 'columns' => true], 'empty' => 'Nenhuma chamada de IA em ' . $mesTexto . '.']); ?>
    </div>
    <div class="tab-pane fade" id="abaModulos" role="tabpanel">
      <p class="small text-muted mb-2">Consumo de <?= $e($mesTexto) ?> por módulo e tipo de pedido.</p>
      <?php greenPortalGridLocal(['transaction' => 'administracao_consumo_ia_modulos', 'fragment_class' => 'ia-cons-modulos', 'id' => 'iaConsModulos',
          'columns' => ['MODULO' => ['label' => 'Módulo', 'filter' => 'select', 'width' => '220px'], 'TIPO' => ['label' => 'Pedido', 'filter' => 'select'], 'CHAMADAS' => ['label' => 'Chamadas', 'filter' => 'none', 'width' => '100px'],
              'USUARIOS' => ['label' => 'Usuários', 'filter' => 'none', 'width' => '90px'], 'TOKENS' => ['label' => 'Tokens', 'filter' => 'none', 'width' => '120px'], 'CUSTO' => ['label' => 'Custo', 'filter' => 'none', 'width' => '120px'],
              'PCT' => ['label' => '% do mês', 'filter' => 'none', 'width' => '90px']],
          'cell_boxes' => true, 'rows' => $rowsModulos, 'default_sort' => ['CUSTO', 'desc'], 'buttons' => ['reload' => false, 'columns' => true], 'empty' => 'Nenhuma chamada de IA em ' . $mesTexto . '.']); ?>
    </div>
    <div class="tab-pane fade" id="abaChamadas" role="tabpanel">
      <p class="small text-muted mb-2">Até 5.000 chamadas de <?= $e($mesTexto) ?>, da mais recente. Passe o mouse no custo para ver a conta (US$ × cotação) e nos tokens para ver os tokens em cache. Chamadas de NC: selecione e clique em Abrir.</p>
      <?php greenPortalGridLocal(['transaction' => 'administracao_consumo_ia_chamadas', 'fragment_class' => 'ia-cons-chamadas', 'id' => 'iaConsChamadas', 'page_size' => 50, 'open_button' => true,
          'columns' => ['DATA' => ['label' => 'Data', 'filter' => 'none', 'width' => '135px'], 'USUARIO' => ['label' => 'Usuário', 'filter' => 'select', 'width' => '170px'], 'MODULO' => ['label' => 'Módulo', 'filter' => 'select', 'width' => '170px'],
              'TIPO' => ['label' => 'Pedido', 'filter' => 'select'], 'REGISTRO' => ['label' => 'Registro', 'width' => '90px'], 'ORIGEM' => ['label' => 'Origem', 'filter' => 'select', 'width' => '150px'],
              'MODELO' => ['label' => 'Modelo', 'filter' => 'select', 'width' => '150px'], 'TOKENS' => ['label' => 'Tokens (entr./saída)', 'filter' => 'none', 'width' => '140px'],
              'CUSTO' => ['label' => 'Custo', 'filter' => 'none', 'width' => '105px'], 'SITUACAO' => ['label' => 'Resultado', 'filter' => 'select', 'width' => '95px']],
          'cell_boxes' => true, 'rows' => $rowsChamadas, 'default_sort' => ['DATA', 'desc'], 'buttons' => ['reload' => false, 'columns' => true], 'empty' => 'Nenhuma chamada de IA em ' . $mesTexto . '.']); ?>
    </div>
    <!-- Limite: sub-abas -->
    <div class="tab-pane fade" id="abaLimite" role="tabpanel">
      <ul class="nav nav-pills ia-sub" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#subLimite" type="button" role="tab"><i class="bi bi-sliders me-1"></i>Limite e crédito extra</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#subHistorico" type="button" role="tab"><i class="bi bi-clock-history me-1"></i>Histórico <span class="badge text-bg-light border"><?= count($rowsHist) ?></span></button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#subAvisos" type="button" role="tab"><i class="bi bi-bell me-1"></i>Avisos enviados <span class="badge text-bg-light border"><?= count($rowsAvisos) ?></span></button></li>
      </ul>
      <div class="tab-content">
        <div class="tab-pane fade show active" id="subLimite" role="tabpanel">
      <div class="row g-3">
        <div class="col-lg-6"><div class="card cfg-card"><div class="card-body py-2">
          <h6 class="small fw-semibold">Limite mensal da empresa</h6>
          <p class="small text-muted mb-2">Ao chegar no aviso, os ADM DA EMPRESA recebem alerta e e-mail; ao atingir o limite e ao bloquear, também o ADMINISTRADOR. Depois do limite, a folga deixa terminar os trabalhos em andamento; passou dela, os <b>novos</b> pedidos de IA ficam bloqueados (os que já estão na fila continuam). O limite volta a contar do zero no dia 1.<?= $admin ? '' : ' Só o ADMINISTRADOR altera.' ?></p>
          <form data-form-limite class="row g-2">
            <div class="col-md-6"><label class="form-label small fw-semibold" for="iaLimite">Limite mensal (R$)</label><input id="iaLimite" class="form-control form-control-sm" name="LIMITE_MENSAL_BRL" inputmode="decimal" placeholder="vazio = sem limite"
              value="<?= ($cfg['LIMITE_MENSAL_BRL'] ?? null) === null ? '' : $e(number_format((float)$cfg['LIMITE_MENSAL_BRL'], 2, ',', '')) ?>" data-original="<?= ($cfg['LIMITE_MENSAL_BRL'] ?? null) === null ? '' : $e(number_format((float)$cfg['LIMITE_MENSAL_BRL'], 2, ',', '')) ?>"<?= $admin ? '' : ' disabled' ?>></div>
            <div class="col-md-6"><label class="form-label small fw-semibold" for="iaFolga">Folga antes do bloqueio (%)</label><input id="iaFolga" class="form-control form-control-sm" name="FOLGA_PCT" inputmode="decimal"
              value="<?= $e(number_format((float)($cfg['FOLGA_PCT'] ?? 10), 0, ',', '')) ?>" data-original="<?= $e(number_format((float)($cfg['FOLGA_PCT'] ?? 10), 0, ',', '')) ?>"<?= $admin ? '' : ' disabled' ?>></div>
            <div class="col-md-6"><label class="form-label small fw-semibold" for="iaAviso">Aviso ao chegar em (% do limite)</label><input id="iaAviso" class="form-control form-control-sm" name="AVISO_PCT" inputmode="decimal"
              value="<?= $e(number_format((float)($cfg['AVISO_PCT'] ?? 80), 0, ',', '')) ?>" data-original="<?= $e(number_format((float)($cfg['AVISO_PCT'] ?? 80), 0, ',', '')) ?>"<?= $admin ? '' : ' disabled' ?>></div>
            <div class="col-md-6"><label class="form-label small fw-semibold" for="iaAcr">Acréscimo IOF/spread (%)</label><input id="iaAcr" class="form-control form-control-sm" name="ACRESCIMO_PCT" inputmode="decimal"
              value="<?= $e(number_format((float)($cfg['ACRESCIMO_PCT'] ?? 0), 2, ',', '')) ?>" data-original="<?= $e(number_format((float)($cfg['ACRESCIMO_PCT'] ?? 0), 2, ',', '')) ?>"<?= $admin ? '' : ' disabled' ?>>
              <div class="small text-muted">Somado ao custo das próximas chamadas, para chegar perto da fatura do cartão.</div></div>
            <?php if ($admin): ?>
            <div class="col-12"><label class="form-label small fw-semibold" for="iaMotivo">Motivo (obrigatório ao mudar o limite)</label><input id="iaMotivo" class="form-control form-control-sm" name="motivo" maxlength="500" placeholder="ex.: aumento negociado com a empresa em 23/10/2026"></div>
            <div class="col-12"><button type="submit" class="btn btn-sm btn-success"><i class="bi bi-floppy me-1"></i>Salvar o limite</button></div>
            <?php endif; ?>
          </form>
        </div></div></div>
        <div class="col-lg-6"><div class="card cfg-card"><div class="card-body py-2">
          <h6 class="small fw-semibold">Crédito extra só deste mês</h6>
          <p class="small text-muted mb-2">Aumento pontual negociado: soma ao limite de <?= $e(date('m/Y')) ?> e acaba no fim do mês (no mês seguinte volta o limite normal).</p>
          <?php if ($admin): ?>
          <form data-form-extra class="row g-2 mb-2">
            <div class="col-md-4"><input class="form-control form-control-sm" name="valor" inputmode="decimal" placeholder="Valor (R$)" aria-label="Valor do crédito extra (R$)" required></div>
            <div class="col-md-8"><input class="form-control form-control-sm" name="motivo" maxlength="500" placeholder="Motivo" aria-label="Motivo do crédito extra" required></div>
            <div class="col-12"><button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-plus-circle me-1"></i>Incluir crédito extra</button></div>
          </form>
          <?php endif; ?>
          <table class="table table-sm mb-0"><thead><tr><th>Mês</th><th>Valor</th><th>Motivo</th><th>Quem</th></tr></thead><tbody>
            <?php foreach (array_slice($extras, 0, 12) as $x): ?><tr><td><?= $e((new DateTimeImmutable((string)$x['MES']))->format('m/Y')) ?></td><td><?= $e($T::moeda((float)$x['VALOR_BRL'])) ?></td><td><?= $e($x['MOTIVO']) ?></td><td class="text-muted"><?= $e($x['NOME_USUARIO']) ?> · <?= $e($A::data($x['CRIADO_EM'], true)) ?></td></tr><?php endforeach; ?>
            <?php if (!$extras): ?><tr><td colspan="4" class="text-muted">Nenhum crédito extra.</td></tr><?php endif; ?>
          </tbody></table>
        </div></div></div>
      </div>
        </div>
        <div class="tab-pane fade" id="subHistorico" role="tabpanel">
      <?php greenPortalGridLocal(['transaction' => 'administracao_consumo_ia_historico', 'fragment_class' => 'ia-cons-hist', 'id' => 'iaConsHist', 'page_size' => 10,
          'columns' => ['DATA' => ['label' => 'Data', 'filter' => 'none', 'width' => '135px'], 'USUARIO' => ['label' => 'Quem alterou', 'filter' => 'select', 'width' => '170px'], 'ACAO' => ['label' => 'Item', 'filter' => 'select', 'width' => '190px'],
              'ANTES' => ['label' => 'Antes', 'width' => '110px'], 'DEPOIS' => ['label' => 'Depois', 'width' => '150px'], 'MOTIVO' => ['label' => 'Motivo']],
          'cell_boxes' => true, 'rows' => $rowsHist, 'default_sort' => ['DATA', 'desc'], 'buttons' => ['reload' => false], 'empty' => 'Nenhuma alteração.']); ?>
        </div>
        <div class="tab-pane fade" id="subAvisos" role="tabpanel">
      <p class="small text-muted mb-2">Avisos do limite enviados pelo serviço Taxímetro de IA (alerta e e-mail): aviso só aos ADM DA EMPRESA; limite atingido e bloqueio também ao ADMINISTRADOR.</p>
      <?php greenPortalGridLocal(['transaction' => 'administracao_consumo_ia_avisos', 'fragment_class' => 'ia-cons-avisos', 'id' => 'iaConsAvisos', 'page_size' => 10,
          'columns' => ['DATA' => ['label' => 'Enviado em', 'filter' => 'none', 'width' => '135px'], 'MES' => ['label' => 'Mês', 'filter' => 'select', 'width' => '90px'], 'FAIXA' => ['label' => 'Aviso', 'filter' => 'select', 'width' => '160px'],
              'LIMITE' => ['label' => 'Limite', 'filter' => 'none', 'width' => '120px'], 'CONSUMO' => ['label' => 'Consumo no aviso', 'filter' => 'none', 'width' => '140px'], 'DEST' => ['label' => 'Destinatários', 'filter' => 'none']],
          'cell_boxes' => true, 'rows' => $rowsAvisos, 'default_sort' => ['DATA', 'desc'], 'buttons' => ['reload' => false], 'empty' => 'Nenhum aviso enviado.']); ?>
        </div>
      </div>
    </div>
    <!-- Preços e câmbio: sub-abas -->
    <div class="tab-pane fade" id="abaPrecos" role="tabpanel">
      <ul class="nav nav-pills ia-sub" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#subPrecos" type="button" role="tab"><i class="bi bi-tags me-1"></i>Preços da OpenAI <span class="badge text-bg-light border"><?= count($rowsPrecos) ?></span></button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#subCambio" type="button" role="tab"><i class="bi bi-currency-exchange me-1"></i>Cotação do dólar</button></li>
      </ul>
      <div class="tab-content">
        <div class="tab-pane fade show active" id="subPrecos" role="tabpanel">
      <p class="small text-muted mb-2">Preços da OpenAI em dólar por 1 milhão de tokens, iguais para todas as empresas. O preço vale para o modelo e para as versões datadas dele (ex.: gpt-4o-mini vale para gpt-4o-mini-2024-07-18); modelo sem preço próprio usa o preço padrão (*). Para mudar um preço a partir de uma data, inclua uma linha nova com a nova vigência: as chamadas antigas ficam com o custo do dia em que foram feitas.</p>      <?php greenPortalGridLocal(['transaction' => 'administracao_consumo_ia_precos', 'fragment_class' => 'ia-cons-precos', 'id' => 'iaConsPrecos', 'double_click_action' => $admin ? 'editar' : '',
          'columns' => ['MODELO' => ['label' => 'Modelo', 'width' => '150px'], 'DESCRICAO' => ['label' => 'Descrição'], 'ENTRADA' => ['label' => 'Entrada', 'filter' => 'none', 'width' => '105px'], 'CACHE' => ['label' => 'Entrada em cache', 'filter' => 'none', 'width' => '125px'],
              'SAIDA' => ['label' => 'Saída', 'filter' => 'none', 'width' => '105px'], 'VIGENTE' => ['label' => 'Vale desde', 'filter' => 'none', 'width' => '100px'], 'ATIVO' => ['label' => 'Ativo', 'filter' => 'select', 'width' => '70px'],
              'FONTE' => ['label' => 'Fonte'], 'ALTERADO' => ['label' => 'Alterado', 'filter' => 'none', 'width' => '190px']],
          'cell_boxes' => true, 'rows' => $rowsPrecos, 'default_sort' => ['MODELO', 'asc'], 'empty' => 'Nenhum preço cadastrado.',
          'buttons' => ['reload' => false, 'columns' => true, 'new' => $admin, 'new_attribute' => 'data-preco-novo'],
          'extras' => $admin ? '<button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-grid-acao-linha="editar" disabled title="Editar o preço selecionado" aria-label="Editar o preço selecionado"><i class="bi bi-pencil"></i></button>' : '']); ?>
        </div>
        <div class="tab-pane fade" id="subCambio" role="tabpanel">
      <div class="d-flex flex-wrap align-items-end gap-2 mb-1">
        <h6 class="small fw-semibold mb-0 me-auto">Últimos 90 dias gravados</h6>
        <?php if ($admin): ?>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-ptax><i class="bi bi-arrow-repeat me-1"></i>Buscar o PTAX agora</button>
        <form data-form-cambio class="d-flex gap-1"><input type="date" class="form-control form-control-sm" name="data" max="<?= $e(date('Y-m-d')) ?>" value="<?= $e(date('Y-m-d')) ?>" aria-label="Data da cotação" required>
          <input class="form-control form-control-sm" style="width:110px" name="cotacao" inputmode="decimal" placeholder="R$ por US$" aria-label="Cotação (R$ por US$)" required>
          <button type="submit" class="btn btn-sm btn-outline-success text-nowrap" title="A cotação digitada não é trocada pelo PTAX daquele dia"><i class="bi bi-pencil-square me-1"></i>Gravar à mão</button></form>
        <?php endif; ?>
      </div>
      <p class="small text-muted mb-2">O serviço <b>Taxímetro de IA</b> do Agendador (Administração › Serviços) grava a PTAX venda do Banco Central todo dia útil; nos fins de semana e feriados vale a do último dia útil.</p>      <?php greenPortalGridLocal(['transaction' => 'administracao_consumo_ia_cambio', 'fragment_class' => 'ia-cons-cambio', 'id' => 'iaConsCambio', 'page_size' => 15,
          'columns' => ['DATA' => ['label' => 'Data', 'filter' => 'none', 'width' => '120px'], 'VENDA' => ['label' => 'Venda (usada)', 'filter' => 'none', 'width' => '140px'], 'COMPRA' => ['label' => 'Compra', 'filter' => 'none', 'width' => '140px'],
              'FONTE' => ['label' => 'Fonte', 'filter' => 'select']],
          'cell_boxes' => true, 'rows' => $rowsCambio, 'default_sort' => ['DATA', 'desc'], 'buttons' => ['reload' => false], 'empty' => 'Nenhuma cotação gravada: o serviço Taxímetro de IA busca o PTAX.']); ?>
        </div>
      </div>
    </div>
    <?php if ($admin): ?>
    <div class="tab-pane fade" id="abaEmpresas" role="tabpanel">
      <p class="small text-muted mb-2">Consumo do mês atual (<?= $e(date('m/Y')) ?>) de cada empresa. O limite de cada uma é alterado entrando na empresa (aba Limite).</p>
      <?php greenPortalGridLocal(['transaction' => 'administracao_consumo_ia_empresas', 'fragment_class' => 'ia-cons-empresas', 'id' => 'iaConsEmpresas',
          'columns' => ['EMPRESA' => ['label' => 'Empresa'], 'BANCO' => ['label' => 'Banco', 'width' => '160px'], 'CONSUMO' => ['label' => 'Consumo do mês', 'filter' => 'none', 'width' => '140px'],
              'LIMITE' => ['label' => 'Limite', 'filter' => 'none', 'width' => '130px'], 'PCT' => ['label' => '% do limite', 'filter' => 'none', 'width' => '100px'],
              'CHAMADAS' => ['label' => 'Chamadas', 'filter' => 'none', 'width' => '100px'], 'SITUACAO' => ['label' => 'Situação', 'filter' => 'select', 'width' => '200px']],
          'cell_boxes' => true, 'rows' => $rowsEmpresas, 'default_sort' => ['CONSUMO', 'desc'], 'buttons' => ['reload' => false], 'empty' => 'Nenhuma empresa.']); ?>
    </div>
    <?php endif; ?>
  </div>
  <?php if ($admin): ?>
  <div class="modal fade" id="iaPreco" tabindex="-1"><div class="modal-dialog"><form class="modal-content" data-form-preco>
    <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-currency-dollar me-1"></i>Preço do modelo</h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
    <div class="modal-body row g-2"><input type="hidden" name="codigo">
      <div class="col-md-6"><label class="form-label small fw-semibold" for="iaPrModelo">Modelo</label><input id="iaPrModelo" class="form-control form-control-sm" name="modelo" maxlength="100" placeholder="gpt-4o-mini" required></div>
      <div class="col-md-6"><label class="form-label small fw-semibold" for="iaPrVig">Vale desde</label><input id="iaPrVig" type="date" class="form-control form-control-sm" name="vigente" required></div>
      <div class="col-12"><label class="form-label small fw-semibold" for="iaPrDesc">Descrição</label><input id="iaPrDesc" class="form-control form-control-sm" name="descricao" maxlength="200"></div>
      <div class="col-md-4"><label class="form-label small fw-semibold" for="iaPrIn">Entrada (US$/1M)</label><input id="iaPrIn" class="form-control form-control-sm" name="entrada" inputmode="decimal" required></div>
      <div class="col-md-4"><label class="form-label small fw-semibold" for="iaPrCa">Em cache (US$/1M)</label><input id="iaPrCa" class="form-control form-control-sm" name="cache" inputmode="decimal"></div>
      <div class="col-md-4"><label class="form-label small fw-semibold" for="iaPrOut">Saída (US$/1M)</label><input id="iaPrOut" class="form-control form-control-sm" name="saida" inputmode="decimal" required></div>
      <div class="col-12"><label class="form-label small fw-semibold" for="iaPrFonte">Fonte</label><input id="iaPrFonte" class="form-control form-control-sm" name="fonte" maxlength="200" placeholder="ex.: página de preços da OpenAI, 23/10/2026"></div>
      <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="ativo" id="iaPrAtivo" checked><label class="form-check-label small" for="iaPrAtivo">Ativo</label></div></div>
      <div class="col-12 small text-muted">Vale para as próximas chamadas a partir da data; as já registradas mantêm o custo calculado no dia.</div>
    </div>
    <div class="modal-footer py-2"><button class="btn btn-sm btn-success" type="submit"><i class="bi bi-floppy me-1"></i>Salvar</button></div>
  </form></div></div>
  <?php endif; ?>
  <?php endif; ?>
<?php greenPortalGridLocalScripts(); ?>
<script src="../assets/js/nc-administracao.js?v=1"></script>
<?php if ($erro === ''): ?>
<script>
(function () {
  'use strict';
  // Barra da tela: mês, recarregar, Excel (fila do Agendador → alerta) e PDF (popup do portal com Baixar)
  var barra = document.querySelector('[data-ia-barra]'), mes = document.body.getAttribute('data-mes');
  var aba = function () { var a = document.querySelector('.nav-tabs .nav-link.active'); return a ? a.getAttribute('data-bs-target') : ''; };
  // Aba ou sub-aba pelo endereço (#abaLimite, #subCambio...): abre também a aba que contém a sub-aba
  if (window.location.hash && window.bootstrap) {
    var bt = document.querySelector('[data-bs-target="' + window.location.hash + '"]');
    var pai = bt && bt.closest('.tab-pane');
    if (pai) { var bp = document.querySelector('[data-bs-target="#' + pai.id + '"]'); if (bp) window.bootstrap.Tab.getOrCreateInstance(bp).show(); }
    if (bt) window.bootstrap.Tab.getOrCreateInstance(bt).show();
  }
  var sel = barra && barra.querySelector('[data-ia-mes]');
  if (sel) sel.addEventListener('change', function () { window.location.href = window.location.pathname + '?mes=' + encodeURIComponent(sel.value) + aba(); });
  var rec = barra && barra.querySelector('[data-crud-action="reload"]');
  if (rec) rec.addEventListener('click', function (ev) { ev.preventDefault(); ev.stopImmediatePropagation(); window.location.href = window.location.pathname + '?mes=' + encodeURIComponent(mes) + aba(); window.location.reload(); }, true);
  var exp = barra && barra.querySelector('[data-crud-action="export"]');
  if (exp) exp.addEventListener('click', function (ev) {
    ev.preventDefault(); ev.stopImmediatePropagation();
    if (exp.disabled) return;
    exp.disabled = true;
    GPNcAdmin.gravar('exportar', { mes: mes }).then(function (r) { exp.disabled = false; GPNcAdmin.aviso(r.mensagem || 'A exportação foi solicitada e será disponibilizada nos alertas.', 'success'); })
      .catch(function (e) { exp.disabled = false; GPNcAdmin.aviso(e.message, 'danger'); });
  }, true);
  var pdf = barra && barra.querySelector('[data-ia-pdf]');
  if (pdf) pdf.addEventListener('click', function () {
    var url = new URL('administracao_consumo_ia.php?mes=' + encodeURIComponent(mes) + '&exportar=pdf', window.location.href).toString(), titulo = 'Consumo de IA — ' + mes.slice(5, 7) + '/' + mes.slice(0, 4);
    var host = null; try { host = window.top && window.top.GreenPortalPopups && window.top.jQuery ? window.top : null; } catch (x) { host = null; }
    if (!host) { window.open(url, '_blank'); return; }
    var $ = host.jQuery, caixa = $('<div>', { style: 'display:flex;flex-direction:column;width:100%;height:100%;' });
    var topo = $('<div>', { style: 'display:flex;justify-content:flex-end;gap:6px;padding:4px 6px;border-bottom:1px solid #dce9e2;' });
    topo.append($('<a>', { href: url + '&baixar=1', download: '', 'class': 'btn btn-sm btn-outline-danger', title: 'Baixar o PDF' }).append($('<i>', { 'class': 'bi bi-download me-1' }), 'Baixar PDF'));
    caixa.append(topo, $('<iframe>', { src: url, title: titulo, style: 'flex:1 1 auto;width:100%;border:0;display:block;' }));
    host.GreenPortalPopups.transacaoIndependente({ titulo: titulo, conteudo: caixa, amplo: true, redimensionavel: true });
  });
}());
</script>
<?php endif; ?>
<?php if ($admin && $erro === ''): ?>
<script>
(function () {
  'use strict';
  var recarregar = function (aba) { window.setTimeout(function () { window.location.href = window.location.pathname + window.location.search + (aba ? '#' + aba : ''); window.location.reload(); }, 1000); };
  var dec = function (v) { v = String(v || '').trim().replace(/\s|R\$/g, ''); return v.indexOf(',') >= 0 ? v.replace(/\./g, '').replace(',', '.') : v; };
  // Limite: grava só o que mudou
  var fl = document.querySelector('[data-form-limite]');
  if (fl) fl.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var motivo = fl.elements.motivo.value.trim();
    var mudou = ['LIMITE_MENSAL_BRL', 'FOLGA_PCT', 'AVISO_PCT', 'ACRESCIMO_PCT'].map(function (k) { var x = fl.elements[k]; return { chave: k, valor: dec(x.value), original: dec(x.getAttribute('data-original')) }; })
      .filter(function (p) { return p.valor !== p.original && !(p.valor !== '' && p.original !== '' && Number(p.valor) === Number(p.original)); });
    if (!mudou.length) { GPNcAdmin.aviso('Nada foi alterado.', 'info'); return; }
    var bt = fl.querySelector('[type="submit"]'); bt.disabled = true;
    mudou.reduce(function (pr, p) { return pr.then(function () { return GPNcAdmin.gravar('limite_salvar', { chave: p.chave, valor: p.valor, motivo: motivo }); }); }, Promise.resolve())
      .then(function () { GPNcAdmin.aviso('Limite gravado.', 'success'); recarregar('abaLimite'); })
      .catch(function (e) { bt.disabled = false; GPNcAdmin.aviso(e.message, 'danger'); });
  });
  var fx = document.querySelector('[data-form-extra]');
  if (fx) fx.addEventListener('submit', function (ev) {
    ev.preventDefault();
    GPNcAdmin.gravar('extra_incluir', { valor: dec(fx.elements.valor.value), motivo: fx.elements.motivo.value.trim() })
      .then(function () { GPNcAdmin.aviso('Crédito extra incluído no limite deste mês.', 'success'); recarregar('abaLimite'); })
      .catch(function (e) { GPNcAdmin.aviso(e.message, 'danger'); });
  });
  // Preços
  var modalEl = document.getElementById('iaPreco'), fp = document.querySelector('[data-form-preco]');
  var num = function (v) { return v === null || v === undefined || v === '' ? '' : String(v).replace('.', ','); };
  function abrirPreco(p) {
    fp.reset();
    fp.elements.codigo.value = p.codigo || 0; fp.elements.modelo.value = p.modelo || ''; fp.elements.descricao.value = p.descricao || ''; fp.elements.entrada.value = num(p.entrada);
    fp.elements.cache.value = num(p.cache); fp.elements.saida.value = num(p.saida); fp.elements.vigente.value = p.vigente || new Date().toISOString().slice(0, 10);
    fp.elements.fonte.value = p.fonte || ''; fp.elements.ativo.checked = p.ativo === undefined ? true : !!p.ativo;
    window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
  }
  if (fp) fp.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var f = fp.elements;
    GPNcAdmin.gravar('preco_salvar', { codigo: Number(f.codigo.value) || 0, modelo: f.modelo.value.trim(), descricao: f.descricao.value.trim(), entrada: dec(f.entrada.value), cache: dec(f.cache.value),
      saida: dec(f.saida.value), vigente: f.vigente.value, ativo: f.ativo.checked ? 1 : 0, fonte: f.fonte.value.trim() })
      .then(function () { window.bootstrap.Modal.getInstance(modalEl).hide(); GPNcAdmin.aviso('Preço gravado.', 'success'); recarregar('abaPrecos'); })
      .catch(function (e) { GPNcAdmin.aviso(e.message, 'danger'); });
  });
  var novo = document.querySelector('[data-preco-novo]');
  if (novo) novo.addEventListener('click', function () { abrirPreco({}); });
  document.querySelectorAll('[data-preco]').forEach(function (b) { b.addEventListener('click', function () { abrirPreco(JSON.parse(b.getAttribute('data-preco'))); }); });
  // Câmbio
  var fc = document.querySelector('[data-form-cambio]');
  if (fc) fc.addEventListener('submit', function (ev) {
    ev.preventDefault();
    GPNcAdmin.gravar('cambio_salvar', { data: fc.elements.data.value, cotacao: dec(fc.elements.cotacao.value) })
      .then(function () { GPNcAdmin.aviso('Cotação gravada.', 'success'); recarregar('subCambio'); })
      .catch(function (e) { GPNcAdmin.aviso(e.message, 'danger'); });
  });
  var bp = document.querySelector('[data-ptax]');
  if (bp) bp.addEventListener('click', function () {
    bp.disabled = true;
    GPNcAdmin.gravar('ptax_atualizar', {}).then(function (r) { GPNcAdmin.aviso((r.gravadas || 0) + ' cotação(ões) do PTAX gravada(s); última: ' + (r.ultima || '—') + '.', 'success'); recarregar('subCambio'); })
      .catch(function (e) { bp.disabled = false; GPNcAdmin.aviso(e.message, 'danger'); });
  });
}());
</script>
<?php endif; ?>
</body></html>
