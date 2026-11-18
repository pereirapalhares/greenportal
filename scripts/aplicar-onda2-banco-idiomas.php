<?php
declare(strict_types=1);
/*
 * Onda 2 de idiomas (18/11/2026): mensagens das procedures e gatilhos (SIGNAL) em inglês e espanhol.
 * Lê database/onda2_banco_mensagens.json ([{pt, en, es}]) e registra no grupo BANCO de Textos e idiomas, com a mesma chave
 * que IdiomaService::mensagemBanco procura ('banco.m' + 12 primeiros caracteres do md5 do texto em português).
 * Gravação só por sp_idioma_registrar_lote (a do cadastro automático): textos novos entram publicados; os que já existem
 * NÃO são alterados (o script lista os que já existiam e se estão sem EN/ES, para revisar em Administração › Textos e idiomas).
 * Também registra os textos dos relatórios, planilhas e alertas (grupos relatorio_legal, exportacao, alertas, legislacao_rcl e
 * naoconformidades rel.*), para ficarem editáveis em Textos e idiomas (sem o registro, valem os padrões do código).
 * Uso: php scripts\aplicar-onda2-banco-idiomas.php [--simular]
 */
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$u = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$lista = json_decode((string)file_get_contents(dirname(__DIR__) . '/database/onda2_banco_mensagens.json'), true) ?: [];
$existentes = IdiomaService::grupo('BANCO');
$novos = []; $ja = []; $jaSemTraducao = [];
foreach ($lista as $i => $m) {
    $pt = trim((string)$m['pt']);
    $chave = 'banco.m' . substr(md5($pt), 0, 12);
    if (isset($existentes[$chave])) {
        $ja[] = $chave;
        $x = $existentes[$chave];
        if (trim((string)($x['en'] ?? '')) === '' || trim((string)($x['es'] ?? '')) === '' || (string)($x['en'] ?? '') === (string)($x['pt'] ?? '')) $jaSemTraducao[] = $chave . ' — ' . $pt;
        continue;
    }
    $novos[] = ['chave' => $chave, 'tipo' => 'MENSAGEM', 'ordem' => 8000 + $i, 'descricao' => mb_substr('Mensagem do banco: ' . $pt, 0, 300),
        'pt' => mb_substr($pt, 0, 1000), 'en' => mb_substr(trim((string)$m['en']), 0, 1000), 'es' => mb_substr(trim((string)$m['es']), 0, 1000)];
}
echo ($simular ? '[SIMULAÇÃO] ' : ''), count($lista), " mensagens no arquivo; ", count($novos), " novas; ", count($ja), " já existiam (", count($jaSemTraducao), " delas sem EN/ES completos).\n";
foreach ($jaSemTraducao as $l) echo "  já existia sem tradução completa: $l\n";
$total = 0;
if (!$simular && $novos) {
foreach (array_chunk($novos, 100) as $lote) {
    $rows = GreenPortalDatabase::call($u, 'CALL sp_idioma_registrar_lote(?, ?, ?, ?, ?, ?, ?)', ['BANCO', 'BANCO', '', '', '', json_encode($lote, JSON_UNESCAPED_UNICODE), 'Onda 2 de idiomas (script)']);
    $total += (int)($rows[0]['novos'] ?? 0);
}
echo "BANCO: gravadas $total.\n";
}

// Textos dos relatórios, planilhas e alertas
foreach (['RelatorioAtendimentoLegal', 'ExportacaoTextos', 'AlertaTextos', 'LegislacaoRcl', 'NaoConformidadeTextos'] as $c) require_once dirname(__DIR__) . '/classes/' . $c . '.php';
$grupos = [
    ['relatorio_legal', ['Relatório de Atendimento Legal', 'Legal Compliance Report', 'Informe de Cumplimiento Legal'], RelatorioAtendimentoLegal::itensIdioma()],
    ['exportacao', ['Exportação para Excel', 'Excel export', 'Exportación a Excel'], ExportacaoTextos::itensIdioma()],
    ['alertas', ['Alertas', 'Alerts', 'Alertas'], AlertaTextos::itensIdioma()],
    ['legislacao_rcl', ['Planilha RCL', 'RCL spreadsheet', 'Planilla RCL'], LegislacaoRcl::itensIdioma()],
    ['naoconformidades', ['Não Conformidades', 'Nonconformities', 'No Conformidades'], NaoConformidadeTextos::itensIdioma('rel.')],
];
foreach ($grupos as [$grupo, $titulo, $itens]) {
    // a procedure só aceita os tipos TITULO, COLUNA, BOTAO, DICA, MENSAGEM e ROTULO (com TEXTO ignorava tudo)
    $itens = array_map(static fn(array $i): array => ['tipo' => 'ROTULO'] + $i, array_map(static function (array $i): array { unset($i['tipo']); return $i; }, $itens));
    $existentes = IdiomaService::grupo($grupo);
    $faltam = array_values(array_filter($itens, static fn(array $i): bool => !isset($existentes[$i['chave']])));
    echo ($simular ? '[SIMULAÇÃO] ' : ''), "$grupo: ", count($itens), ' textos, ', count($faltam), " novos.\n";
    if ($simular || !$faltam) continue;
    $n = 0;
    foreach (array_chunk($faltam, 100) as $lote) {
        $rows = GreenPortalDatabase::call($u, 'CALL sp_idioma_registrar_lote(?, ?, ?, ?, ?, ?, ?)', [strtoupper($grupo), $titulo[0], $titulo[1], $titulo[2], $grupo, json_encode($lote, JSON_UNESCAPED_UNICODE), 'Onda 2 de idiomas (script)']);
        $n += (int)($rows[0]['novos'] ?? 0);
    }
    echo "  gravados: $n.\n";
}
if (!$simular) IdiomaService::limparCache();
