<?php
declare(strict_types=1);

/**
 * Painel de Homologação (migração 298): consultas prontas, regra única de cores, ordenação, linhas da lista e resumo.
 * Incluído por principal/homologacaofornecedores_painel.php (tela) e por classes/HomologacaoPainelExportacao.php
 * (planilha gerada pelo GreenPortal-ExportWorker no Agendador do Windows), para que a tela e a planilha usem
 * exatamente as mesmas regras.
 *
 * Espera no escopo: $t (callable chave, padrões) e $idioma ('pt'|'en'|'es').
 * Define: $reacao, $dias, $eh, $consultas, $L, $filtroConsulta, $ordenar, $aplicarConsulta, $categoriaNome, $corNome,
 *         $data, $linhaSaida, $resumir, $marcarOutras.
 */
// ---------- Consultas prontas (regra única de cores) ----------
$reacao = static fn(array $i): int => (int)($i['REACAO'] ?? 0) > 0 ? (int)$i['REACAO'] : 30;
$dias = static fn(array $i): ?int => $i['QTDDIAS'] === null || $i['QTDDIAS'] === '' ? null : (int)$i['QTDDIAS'];
$eh = static fn(array $i, string $c): bool => $i['CATEGORIA'] === $c;
$consultas = [
    'documentos' => ['grupo' => ['pt' => 'Documentos', 'en' => 'Documents', 'es' => 'Documentos'], 'itens' => [
        'vencidos' => [['pt' => 'Vencidos', 'en' => 'Expired', 'es' => 'Vencidos'], ['pt' => 'Documentos com validade anterior a hoje.', 'en' => 'Documents whose expiry date has passed.', 'es' => 'Documentos con vencimiento anterior a hoy.'],
            static fn($i) => $eh($i, 'DOCUMENTO') && $dias($i) !== null && $dias($i) < 0 && $i['COR'] !== 'NEUTRO'],
        'vencem30' => [['pt' => 'Vencem nos dias de reação', 'en' => 'Expiring within the reaction days', 'es' => 'Vencen en los días de reacción'], ['pt' => 'Vencem dentro dos dias de reação do documento (30 quando não informado).', 'en' => 'Expiring within the document reaction days (30 when not set).', 'es' => 'Vencen dentro de los días de reacción del documento (30 si no se informa).'],
            static fn($i) => $eh($i, 'DOCUMENTO') && $dias($i) !== null && $dias($i) >= 0 && $dias($i) <= $reacao($i) && $i['COR'] !== 'NEUTRO'],
        'pendforn' => [['pt' => 'Pendentes com o fornecedor', 'en' => 'Pending with the supplier', 'es' => 'Pendientes con el proveedor'], ['pt' => 'Aguardando envio ou correção pelo fornecedor.', 'en' => 'Waiting for the supplier to send or fix.', 'es' => 'Esperando envío o corrección del proveedor.'],
            static fn($i) => $eh($i, 'DOCUMENTO') && $i['SITUACAO'] === 'Pendente com o fornecedor'],
        'pendemp' => [['pt' => 'Pendentes com a empresa', 'en' => 'Pending with the company', 'es' => 'Pendientes con la empresa'], ['pt' => 'Aguardando análise da empresa.', 'en' => 'Waiting for the company review.', 'es' => 'Esperando análisis de la empresa.'],
            static fn($i) => $eh($i, 'DOCUMENTO') && $i['SITUACAO'] === 'Pendente com a empresa'],
        // Migração 300: documentos em protocolo, aguardando a data de cobrança (depois voltam para "Pendente com o fornecedor")
        'protocolo' => [['pt' => 'Em protocolo (aguardando vencimento)', 'en' => 'Under protocol (waiting for the deadline)', 'es' => 'En protocolo (esperando vencimiento)'], ['pt' => 'Documentos em protocolo; na data de cobrança voltam para pendente com o fornecedor.', 'en' => 'Documents under protocol; on the follow-up date they return to pending with the supplier.', 'es' => 'Documentos en protocolo; en la fecha de cobro vuelven a pendiente con el proveedor.'],
            static fn($i) => $eh($i, 'DOCUMENTO') && $i['SITUACAO'] === 'Aguardando vencimento do protocolo'],
        'reprovados' => [['pt' => 'Reprovados', 'en' => 'Rejected', 'es' => 'Reprobados'], ['pt' => 'Reprovados pela empresa (o motivo está na observação).', 'en' => 'Rejected by the company (reason in the note).', 'es' => 'Reprobados por la empresa (motivo en la observación).'],
            static fn($i) => $eh($i, 'DOCUMENTO') && $i['SITUACAO'] === 'Reprovado'],
        'aprovados' => [['pt' => 'Atendidos (verdes)', 'en' => 'Met (green)', 'es' => 'Atendidos (verdes)'], ['pt' => 'Aprovados, aprovados sem validade ou ciente da regra, dentro do prazo.', 'en' => 'Approved, approved without expiry or aware of the rule, within the deadline.', 'es' => 'Aprobados, sin vencimiento o conscientes de la regla, dentro del plazo.'],
            static fn($i) => $eh($i, 'DOCUMENTO') && $i['COR'] === 'VERDE'],
        'semsituacao' => [['pt' => 'Sem situação', 'en' => 'No status', 'es' => 'Sin situación'], ['pt' => 'Documentos sem situação informada.', 'en' => 'Documents without a status.', 'es' => 'Documentos sin situación.'],
            static fn($i) => $eh($i, 'DOCUMENTO') && $i['COR'] === 'BRANCO'],
        'semresp' => [['pt' => 'Sem responsável', 'en' => 'No owner', 'es' => 'Sin responsable'], ['pt' => 'Documentos sem responsável da empresa ou do fornecedor.', 'en' => 'Documents without a company or supplier owner.', 'es' => 'Documentos sin responsable de la empresa o del proveedor.'],
            static fn($i) => $eh($i, 'DOCUMENTO') && $i['COR'] !== 'NEUTRO' && ((int)($i['CODUSUARIO_EMPRESA'] ?? 0) === 0 || in_array((int)($i['CODUSUARIO_FORNECEDOR'] ?? 0), [0, 99999], true))],
    ]],
    'fornecedores' => ['grupo' => ['pt' => 'Fornecedores', 'en' => 'Suppliers', 'es' => 'Proveedores'], 'itens' => [
        'auditoria' => [['pt' => 'Auditoria vencida ou reprovada', 'en' => 'Audit expired or failed', 'es' => 'Auditoría vencida o reprobada'], ['pt' => 'Tipos que exigem auditoria com a validade vencida ou reprovados.', 'en' => 'Types requiring audit, expired or failed.', 'es' => 'Tipos que exigen auditoría, vencidos o reprobados.'],
            static fn($i) => $eh($i, 'AUDITORIA') && $i['COR'] === 'VERMELHO'],
        'nivel' => [['pt' => 'Nível de serviço insatisfatório ou atrasado', 'en' => 'Unsatisfactory or overdue service level', 'es' => 'Nivel de servicio insatisfactorio o atrasado'], ['pt' => 'Última avaliação não satisfatória ou próxima avaliação atrasada.', 'en' => 'Last evaluation unsatisfactory or next evaluation overdue.', 'es' => 'Última evaluación no satisfactoria o próxima atrasada.'],
            static fn($i) => $eh($i, 'NIVEL') && in_array($i['COR'], ['VERMELHO', 'AMARELO'], true)],
    ]],
    'funcionarios' => ['grupo' => ['pt' => 'Funcionários', 'en' => 'Employees', 'es' => 'Empleados'], 'itens' => [
        'integracao' => [['pt' => 'Integração vencida', 'en' => 'Expired induction', 'es' => 'Integración vencida'], ['pt' => 'Funcionários com a integração vencida.', 'en' => 'Employees with expired induction.', 'es' => 'Empleados con integración vencida.'],
            static fn($i) => $eh($i, 'INTEGRACAO') && $i['COR'] === 'VERMELHO'],
        'docfunc' => [['pt' => 'Documentos de funcionário vencidos ou não atendidos', 'en' => 'Employee documents expired or not met', 'es' => 'Documentos de empleado vencidos o no atendidos'], ['pt' => 'Critérios do funcionário vencidos ou "Não atende".', 'en' => 'Employee criteria expired or "Not met".', 'es' => 'Criterios del empleado vencidos o "No atiende".'],
            static fn($i) => $eh($i, 'DOCFUNC') && $i['COR'] === 'VERMELHO'],
        'treinamentos' => [['pt' => 'Treinamentos vencidos ou a vencer', 'en' => 'Training expired or expiring', 'es' => 'Capacitaciones vencidas o por vencer'], ['pt' => 'Treinamentos do funcionário fora do prazo ou vencendo em 30 dias.', 'en' => 'Employee training overdue or expiring in 30 days.', 'es' => 'Capacitaciones fuera de plazo o que vencen en 30 días.'],
            static fn($i) => $eh($i, 'TREINAMENTO') && in_array($i['COR'], ['VERMELHO', 'AMARELO'], true)],
    ]],
    'outros' => ['grupo' => ['pt' => 'Outros', 'en' => 'Other', 'es' => 'Otros'], 'itens' => [
        'equipamentos' => [['pt' => 'Equipamentos vencidos ou a vencer', 'en' => 'Equipment expired or expiring', 'es' => 'Equipos vencidos o por vencer'], ['pt' => 'Equipamentos dos fornecedores reprovados, vencidos ou vencendo.', 'en' => 'Supplier equipment failed, expired or expiring.', 'es' => 'Equipos reprobados, vencidos o por vencer.'],
            static fn($i) => $eh($i, 'EQUIPAMENTO') && in_array($i['COR'], ['VERMELHO', 'AMARELO'], true)],
        'quimicos' => [['pt' => 'Produtos químicos vencidos ou a vencer', 'en' => 'Chemicals expired or expiring', 'es' => 'Productos químicos vencidos o por vencer'], ['pt' => 'Produtos químicos reprovados, vencidos ou vencendo.', 'en' => 'Chemicals failed, expired or expiring.', 'es' => 'Productos químicos reprobados, vencidos o por vencer.'],
            static fn($i) => $eh($i, 'QUIMICO') && in_array($i['COR'], ['VERMELHO', 'AMARELO'], true)],
        'plano' => [['pt' => 'Planos de ação em aberto', 'en' => 'Open action plans', 'es' => 'Planes de acción abiertos'], ['pt' => 'Ações dos fornecedores ainda não concluídas.', 'en' => 'Supplier actions not yet completed.', 'es' => 'Acciones de los proveedores aún no concluidas.'],
            static fn($i) => $eh($i, 'PLANO') && $i['COR'] !== 'VERDE'],
    ]],
];
$L = static fn(array $p): string => $p[$idioma] ?? $p['pt'];
$filtroConsulta = static function (string $id) use ($consultas, $dias, $reacao, $L, $t): array {
    foreach ($consultas as $g) if (isset($g['itens'][$id])) return ['label' => $L($g['itens'][$id][0]), 'descricao' => $L($g['itens'][$id][1]), 'fn' => $g['itens'][$id][2]];
    if (preg_match('/^semana:(\d{1,2})$/', $id, $m)) {
        $w = (int)$m[1];
        $ini = (new DateTimeImmutable('today'))->modify('+' . ($w * 7) . ' days');
        return ['label' => $t('consulta.semana', ['pt' => 'Vencem na semana de', 'en' => 'Expiring in the week of', 'es' => 'Vencen en la semana del']) . ' ' . $ini->format('d/m'), 'descricao' => $t('consulta.semana_desc', ['pt' => 'Documentos do fornecedor que vencem nesta semana.', 'en' => 'Supplier documents expiring this week.', 'es' => 'Documentos del proveedor que vencen esta semana.']),
            'fn' => static fn($i) => $i['CATEGORIA'] === 'DOCUMENTO' && $dias($i) !== null && $dias($i) >= $w * 7 && $dias($i) < ($w + 1) * 7 && $i['COR'] !== 'NEUTRO'];
    }
    if (preg_match('/^mat:(\d+|outras):(vencido|pendforn|pendemp|avencer|emdia)$/', $id, $m)) {
        $col = $m[2];
        $rot = ['vencido' => 'Vencido', 'pendforn' => 'Pendente com o fornecedor', 'pendemp' => 'Pendente com a empresa', 'avencer' => 'A vencer', 'emdia' => 'Em dia'][$col];
        return ['label' => $rot, 'descricao' => $t('consulta.matriz_desc', ['pt' => 'Documentos da unidade escolhida no quadro Unidade × situação.', 'en' => 'Documents of the unit chosen in the Unit × status table.', 'es' => 'Documentos de la unidad elegida en el cuadro Unidad × situación.']), 'unidade' => $m[1],
            'fn' => static function ($i) use ($col, $dias, $reacao) {
                if ($i['CATEGORIA'] !== 'DOCUMENTO' || $i['COR'] === 'NEUTRO') return false;
                $d = $dias($i);
                return match ($col) {
                    'vencido' => $d !== null && $d < 0,
                    'pendforn' => $i['SITUACAO'] === 'Pendente com o fornecedor',
                    'pendemp' => $i['SITUACAO'] === 'Pendente com a empresa',
                    'avencer' => $d !== null && $d >= 0 && $d <= $reacao($i),
                    'emdia' => $i['COR'] === 'VERDE',
                };
            }];
    }
    if ($id === 'todos') return ['label' => $t('consulta.todos', ['pt' => 'Todos os itens do filtro', 'en' => 'All filtered items', 'es' => 'Todos los ítems del filtro']), 'descricao' => '', 'fn' => static fn($i) => true];
    return ['label' => '', 'descricao' => '', 'fn' => null];
};
$ordenar = static function (array &$linhas): void {
    $peso = ['VERMELHO' => 0, 'AMARELO' => 1, 'BRANCO' => 2, 'VERDE' => 3, 'NEUTRO' => 4];
    usort($linhas, static fn($a, $b) => ($peso[$a['COR']] ?? 9) <=> ($peso[$b['COR']] ?? 9)
        ?: (($a['QTDDIAS'] === null ? PHP_INT_MAX : (int)$a['QTDDIAS']) <=> ($b['QTDDIAS'] === null ? PHP_INT_MAX : (int)$b['QTDDIAS']))
        ?: strcasecmp((string)$a['RAZAOSOCIAL'], (string)$b['RAZAOSOCIAL']) ?: strcasecmp((string)$a['ITEM'], (string)$b['ITEM']));
};
$aplicarConsulta = static function (array $lista, string $id) use ($filtroConsulta): array {
    $c = $filtroConsulta($id);
    if (!$c['fn']) return [$c, []];
    $sel = array_values(array_filter($lista, $c['fn']));
    if (isset($c['unidade'])) $sel = array_values(array_filter($sel, static fn($i) => $c['unidade'] === 'outras' ? !empty($i['_OUTRA_UNIDADE']) : (string)(int)$i['CODUNIDADE'] === $c['unidade']));
    return [$c, $sel];
};
$categoriaNome = ['DOCUMENTO' => 'Documento', 'AUDITORIA' => 'Auditoria', 'NIVEL' => 'Nível de serviço', 'INTEGRACAO' => 'Integração', 'DOCFUNC' => 'Documento de funcionário',
    'TREINAMENTO' => 'Treinamento', 'EQUIPAMENTO' => 'Equipamento', 'QUIMICO' => 'Produto químico', 'PLANO' => 'Plano de ação'];
$corNome = ['VERMELHO' => 'Vermelho', 'AMARELO' => 'Amarelo', 'VERDE' => 'Verde', 'NEUTRO' => 'Cinza', 'BRANCO' => 'Branco'];
$data = static fn($v): string => $v && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string)$v, $m) ? "$m[3]/$m[2]/$m[1]" : '';
$linhaSaida = static fn(array $i): array => [
    'categoria' => $categoriaNome[$i['CATEGORIA']] ?? $i['CATEGORIA'], 'cor' => $i['COR'], 'fornecedor' => (string)$i['RAZAOSOCIAL'], 'codfornecedor' => (int)$i['CODFORNECEDOR'],
    'unidade' => (string)$i['DSCUNIDADE'], 'item' => (string)$i['ITEM'], 'situacao' => (string)$i['SITUACAO'] !== '' ? (string)$i['SITUACAO'] : 'Sem situação',
    'validade' => $data($i['VALIDADE']), 'dias' => $i['QTDDIAS'] === null ? '' : (string)(int)$i['QTDDIAS'],
    'responsavel' => trim((string)$i['RESPONSAVEL_EMPRESA']) . ((string)$i['RESPONSAVEL_FORNECEDOR'] !== '' ? ' / ' . $i['RESPONSAVEL_FORNECEDOR'] : ''),
    'categoria_codigo' => $i['CATEGORIA'], 'codregistro' => (int)$i['CODREGISTRO'],
];

// ---------- Resumo (números, consultas, gráficos) ----------
$resumir = static function (array $lista, array $forn) use ($consultas, $dias, $reacao, $L): array {
    $docs = array_values(array_filter($lista, static fn($i) => $i['CATEGORIA'] === 'DOCUMENTO'));
    $cnt = static fn(array $xs, callable $f): int => count(array_filter($xs, $f));
    $verdes = $cnt($docs, static fn($i) => $i['COR'] === 'VERDE');
    $base = $cnt($docs, static fn($i) => $i['COR'] !== 'NEUTRO');
    $contagens = [];
    $grupos = [];
    foreach ($consultas as $g) {
        $itensG = [];
        foreach ($g['itens'] as $id => $def) { $n = $cnt($lista, $def[2]); $contagens[$id] = $n; $itensG[] = ['id' => $id, 'label' => $L($def[0]), 'n' => $n]; }
        $grupos[] = ['nome' => $L($g['grupo']), 'itens' => $itensG];
    }
    $pendFornFornecedores = count(array_unique(array_map(static fn($i) => (int)$i['CODFORNECEDOR'], array_filter($docs, $consultas['documentos']['itens']['pendforn'][2]))));
    $funcPend = count(array_unique(array_map(static fn($i) => (int)$i['CODFUNCIONARIO'], array_filter($lista, static fn($i) => in_array($i['CATEGORIA'], ['INTEGRACAO', 'DOCFUNC', 'TREINAMENTO'], true) && $i['COR'] === 'VERMELHO'))));
    $kpis = [
        ['id' => '', 'label' => 'Fornecedores ativos', 'valor' => (string)(int)($forn['ATIVOS'] ?? 0), 'sub' => (int)($forn['DESATIVADOS'] ?? 0) . ' desativados', 'cor' => 'PRIMARIA'],
        ['id' => 'aprovados', 'label' => 'Documentos atendidos', 'valor' => $base > 0 ? round($verdes / $base * 100) . '%' : '—', 'sub' => "$verdes de $base (sem os cinzas)", 'cor' => 'VERDE'],
        ['id' => 'vencidos', 'label' => 'Vencidos', 'valor' => (string)$contagens['vencidos'], 'sub' => 'documentos de fornecedor', 'cor' => 'VERMELHO'],
        ['id' => 'vencem30', 'label' => 'Vencem nos dias de reação', 'valor' => (string)$contagens['vencem30'], 'sub' => '30 dias quando não informado', 'cor' => 'AMARELO'],
        ['id' => 'pendforn', 'label' => 'Pendentes com o fornecedor', 'valor' => (string)$contagens['pendforn'], 'sub' => "em $pendFornFornecedores fornecedor(es)", 'cor' => 'VERMELHO'],
        ['id' => 'pendemp', 'label' => 'Pendentes com a empresa', 'valor' => (string)$contagens['pendemp'], 'sub' => 'aguardando análise', 'cor' => 'AMARELO'],
        ['id' => 'docfunc', 'label' => 'Funcionários com pendência', 'valor' => (string)$funcPend, 'sub' => $contagens['docfunc'] . ' documento(s) de funcionário', 'cor' => 'VERMELHO'],
        ['id' => 'auditoria', 'label' => 'Auditorias vencidas', 'valor' => (string)$contagens['auditoria'], 'sub' => 'ou reprovadas', 'cor' => 'VERMELHO'],
    ];
    // Documentos por situação
    $porSit = [];
    foreach ($docs as $i) { $s = (string)$i['SITUACAO'] !== '' ? (string)$i['SITUACAO'] : 'Sem situação'; $porSit[$s] = ($porSit[$s] ?? 0) + 1; }
    arsort($porSit);
    $corSit = ['Pendente com o fornecedor' => 'VERMELHO', 'Reprovado' => 'VERMELHO', 'Pendente com a empresa' => 'AMARELO', 'Aguardando vencimento do protocolo' => 'AMARELO', 'Aprovado' => 'VERDE', 'Aprovado sem validade' => 'VERDE', 'Ciente da regra' => 'VERDE', 'NA' => 'NEUTRO', 'Não enviar ao fornecedor' => 'NEUTRO', 'Sem situação' => 'BRANCO'];
    $max = $porSit ? max($porSit) : 1;
    $situacoes = [];
    foreach ($porSit as $s => $n) $situacoes[] = ['label' => $s === 'NA' ? 'Não se aplica' : $s, 'n' => $n, 'pct' => round($n / $max * 100), 'cor' => $corSit[$s] ?? 'BRANCO'];
    // Vencimentos nas próximas 12 semanas
    $semanas = [];
    for ($w = 0; $w < 12; $w++) $semanas[$w] = ['n' => 0, 'reacao' => 0, 'label' => (new DateTimeImmutable('today'))->modify('+' . ($w * 7) . ' days')->format('d/m')];
    foreach ($docs as $i) {
        $d = $dias($i); if ($d === null || $d < 0 || $d >= 84 || $i['COR'] === 'NEUTRO') continue;
        $w = intdiv($d, 7); $semanas[$w]['n']++; if ($d <= $reacao($i)) $semanas[$w]['reacao']++;
    }
    $maxW = max(1, max(array_column($semanas, 'n')));
    foreach ($semanas as $w => &$s) { $s['id'] = 'semana:' . $w; $s['h'] = $s['n'] ? max(6, (int)round($s['n'] / $maxW * 100)) : 0; $s['amarelo'] = $s['reacao'] > 0; }
    unset($s);
    // Fornecedores com mais pendências (documentos vermelhos e amarelos)
    $porForn = [];
    foreach ($docs as $i) {
        if (!in_array($i['COR'], ['VERMELHO', 'AMARELO'], true)) continue;
        $k = (int)$i['CODFORNECEDOR'];
        $porForn[$k] ??= ['codfornecedor' => $k, 'nome' => (string)$i['RAZAOSOCIAL'], 'vermelho' => 0, 'amarelo' => 0];
        $porForn[$k][$i['COR'] === 'VERMELHO' ? 'vermelho' : 'amarelo']++;
    }
    usort($porForn, static fn($a, $b) => ($b['vermelho'] + $b['amarelo']) <=> ($a['vermelho'] + $a['amarelo']) ?: $b['vermelho'] <=> $a['vermelho']);
    $top = array_slice($porForn, 0, 8);
    $maxF = max(1, ...array_map(static fn($f) => $f['vermelho'] + $f['amarelo'], $top ?: [['vermelho' => 1, 'amarelo' => 0]]));
    foreach ($top as &$f) { $f['n'] = $f['vermelho'] + $f['amarelo']; $f['pv'] = round($f['vermelho'] / $maxF * 100); $f['pa'] = round($f['amarelo'] / $maxF * 100); }
    unset($f);
    // Unidade × situação
    $porUni = [];
    foreach ($docs as $i) {
        if ($i['COR'] === 'NEUTRO') continue;
        $k = (string)(int)$i['CODUNIDADE'];
        $porUni[$k] ??= ['id' => $k, 'unidade' => (string)$i['DSCUNIDADE'] !== '' ? (string)$i['DSCUNIDADE'] : 'Sem unidade', 'vencido' => 0, 'pendforn' => 0, 'pendemp' => 0, 'avencer' => 0, 'emdia' => 0, 'total' => 0];
        $d = $dias($i);
        $porUni[$k]['total']++;
        if ($d !== null && $d < 0) $porUni[$k]['vencido']++;
        if ($i['SITUACAO'] === 'Pendente com o fornecedor') $porUni[$k]['pendforn']++;
        if ($i['SITUACAO'] === 'Pendente com a empresa') $porUni[$k]['pendemp']++;
        if ($d !== null && $d >= 0 && $d <= $reacao($i)) $porUni[$k]['avencer']++;
        if ($i['COR'] === 'VERDE') $porUni[$k]['emdia']++;
    }
    uasort($porUni, static fn($a, $b) => $b['total'] <=> $a['total']);
    $matriz = array_values(array_slice($porUni, 0, 8, true));
    if (count($porUni) > 8) {
        $o = ['id' => 'outras', 'unidade' => 'Outras', 'vencido' => 0, 'pendforn' => 0, 'pendemp' => 0, 'avencer' => 0, 'emdia' => 0, 'total' => 0];
        foreach (array_slice($porUni, 8, null, true) as $u) foreach (['vencido', 'pendforn', 'pendemp', 'avencer', 'emdia', 'total'] as $c) $o[$c] += $u[$c];
        $matriz[] = $o;
    }
    return ['kpis' => $kpis, 'grupos' => $grupos, 'situacoes' => $situacoes, 'semanas' => array_values($semanas), 'top' => $top, 'matriz' => $matriz, 'unidadesPrincipais' => array_map(static fn($m) => $m['id'], array_slice($matriz, 0, 8))];
};
$marcarOutras = static function (array $lista, array $principais): array {
    foreach ($lista as &$i) $i['_OUTRA_UNIDADE'] = !in_array((string)(int)$i['CODUNIDADE'], $principais, true);
    unset($i);
    return $lista;
};
