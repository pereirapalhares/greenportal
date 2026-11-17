<?php
declare(strict_types=1);

/**
 * Relatório de Atendimento Legal (slide 27 da apresentação Atlas Schindler 2026), em PDF ou Excel.
 *
 * Gerado em segundo plano pela fila de exportações (classes/ExportacaoGridWorker.php, transação
 * modulolegal_relatorio_atendimento). O pedido (exportacoes_grid) traz os filtros e a busca do grid de Requisitos legais e,
 * em colunas.__relatorio, o formato (pdf | xlsx) e o nome da empresa.
 *
 * Dados, sempre por procedure/view (sp_crud_select):
 *   vw_modulolegal_requisitos_situacao_grid  requisitos do grid com a situação calculada e a consolidada (migração 284)
 *   vw_situacao_legal_grid                    decisão do responsável, justificativa e origem da situação consolidada
 *   vw_situacao_legal_itens                   itens de gestão considerados (evidências) com status e motivo
 *   vw_dash_licencas                          licenças válidas x válidas (em protocolo) x vencidas (migrações 285/288),
 *                                             das unidades do relatório
 *
 *   vw_dash_legal_pendencias_criticas          pendências críticas com o responsável do item (migração 341)
 *
 * Migração 341: cada requisito também tem o % de atendimento (itens aplicáveis atendidos ÷ aplicáveis) e o risco (pior risco
 * entre os itens aplicáveis); o relatório mostra as duas dimensões, a matriz risco × % e as pendências críticas.
 *
 * Seções: resumo (por situação, unidade e área), não atendidos, divergências, requisitos com evidências (detalhe com os
 * itens) e a lista completa dos requisitos sem evidências vinculadas.
 */
final class RelatorioAtendimentoLegal
{
    private const LOTE = 1000;
    private const ROT_SIT = ['S' => 'Atendido', 'PA' => 'Parcialmente atendido', 'N' => 'Não atendido', 'AI' => 'Indefinido'];
    private const COR_SIT = ['S' => '#198754', 'PA' => '#c98a00', 'N' => '#c62f3d', 'AI' => '#6c757d'];
    private const ROT_AREA_ITEM = ['LICENCAS' => 'Licença', 'MEDICAO' => 'Medição e monitoramento', 'TREINAMENTOS' => 'Treinamento',
        'INFRAESTRUTURA' => 'Infraestrutura', 'CONTROLES' => 'Controle', 'HOMOLOGACAO' => 'Fornecedor'];
    // CRITICO = licença vencida sem protocolo: mostrado como "Vencido" (migração 341), para não confundir com o risco Crítico
    private const ROT_STATUS = ['OK' => 'Ok', 'ATENCAO' => 'Atenção', 'FALHA' => 'Falha', 'CRITICO' => 'Vencido'];
    private const ROT_RISCO = ['CRITICO' => 'Crítico', 'ALTO' => 'Alto', 'MODERADO' => 'Moderado', 'BAIXO' => 'Baixo', 'NAO_AVALIADO' => 'Não avaliado'];
    private const COR_RISCO = ['CRITICO' => '#c62f3d', 'ALTO' => '#e8590c', 'MODERADO' => '#c98a00', 'BAIXO' => '#198754', 'NAO_AVALIADO' => '#8a9a93'];
    private const COR_STATUS = ['OK' => '#198754', 'ATENCAO' => '#c98a00', 'FALHA' => '#c62f3d', 'CRITICO' => '#8b0f1c'];
    private const AREAS_LEGAIS = ['MEIO_AMBIENTE' => 'Meio ambiente', 'SEGURANCA' => 'Segurança', 'QUALIDADE' => 'Qualidade', 'RESPOSABILIDADE' => 'Social'];

    /** Gera o arquivo e devolve o caminho. */
    public static function gerar(PDO $pdo, array $job, string $dir, string $base, string $userName, string $root): string
    {
        @ini_set('memory_limit', '2048M');
        @set_time_limit(0);
        $payload = json_decode((string)($job['colunas'] ?? '{}'), true);
        $cfg = is_array($payload['__relatorio'] ?? null) ? $payload['__relatorio'] : [];
        $formato = ($cfg['formato'] ?? 'pdf') === 'xlsx' ? 'xlsx' : 'pdf';
        $fonte = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($cfg['fonte'] ?? 'vw_modulolegal_requisitos_situacao_grid')) ?: 'vw_modulolegal_requisitos_situacao_grid';
        $filtros = json_decode((string)($job['filtros'] ?? '{}'), true);
        $filtros = is_array($filtros) ? $filtros : [];
        $busca = trim((string)($job['busca'] ?? ''));
        $d = self::dados($pdo, $fonte, $filtros, $busca);
        $meta = [
            'empresa' => trim((string)($cfg['empresa'] ?? '')) ?: 'Empresa',
            'emitido_em' => date('d/m/Y H:i'),
            'emitido_por' => $userName,
            'filtros' => self::textoFiltros($filtros, $busca),
        ];
        $arquivo = $dir . DIRECTORY_SEPARATOR . $base . '.' . $formato;
        if ($formato === 'xlsx') self::excel($arquivo, $d, $meta);
        else self::pdf($arquivo, $d, $meta, $root);
        return $arquivo;
    }

    // ------------------------------------------------------------------ dados
    private static function selecionar(PDO $pdo, string $tabela, array $filtros, ?string $busca): array
    {
        $linhas = [];
        for ($offset = 0; ; $offset += self::LOTE) {
            $s = $pdo->prepare('CALL sp_crud_select(:tabela, :filtros, :busca, :limite, :offset)');
            $s->execute(['tabela' => $tabela, 'filtros' => json_encode($filtros, JSON_UNESCAPED_UNICODE), 'busca' => $busca === '' ? null : $busca,
                'limite' => self::LOTE, 'offset' => $offset]);
            $lote = $s->fetchAll(PDO::FETCH_ASSOC);
            $s->closeCursor();
            array_push($linhas, ...$lote);
            if (count($lote) < self::LOTE) break;
        }
        return $linhas;
    }

    private static function codigo(string $texto): string
    {
        $t = mb_strtolower(trim($texto));
        if ($t === 'atendido' || $t === 'sim') return 'S';
        if (str_starts_with($t, 'parcial')) return 'PA';
        if ($t === 'não atendido' || $t === 'nao atendido' || $t === 'não' || $t === 'nao' || $t === 'n?o') return 'N';
        return 'AI';
    }

    private const ROT_LIC = ['VALIDA' => 'Válida', 'PROTOCOLO' => 'Válida (em protocolo)', 'PROT_SEM_PRAZO' => 'Em protocolo (prazo não informado)', 'VENCIDA' => 'Vencida', 'SEM_DATA' => 'Sem data de vencimento'];
    private const COR_LIC = ['VALIDA' => '#2f7d5b', 'PROTOCOLO' => '#2f6fae', 'PROT_SEM_PRAZO' => '#c77c0e', 'VENCIDA' => '#c0392b', 'SEM_DATA' => '#8a979e'];

    private static function dados(PDO $pdo, string $fonte, array $filtros, string $busca): array
    {
        $requisitos = self::selecionar($pdo, $fonte, $filtros, $busca);
        $situacoes = [];
        try { foreach (self::selecionar($pdo, 'vw_situacao_legal_grid', [], null) as $s) $situacoes[$s['CODLEGISLACAO'] . ':' . $s['CODUNIDADE']] = $s; }
        catch (Throwable) { }
        $itens = [];
        try { foreach (self::selecionar($pdo, 'vw_situacao_legal_itens', [], null) as $i) $itens[$i['CODLEGISLACAO'] . ':' . $i['CODUNIDADE']][] = $i; }
        catch (Throwable) { }
        $ordemStatus = ['CRITICO' => 0, 'FALHA' => 1, 'ATENCAO' => 2, 'OK' => 3];
        $lista = [];
        foreach ($requisitos as $r) {
            $chave = $r['CODLEGISLACAO'] . ':' . $r['CODUNIDADE'];
            $s = $situacoes[$chave] ?? null;
            $manual = self::codigo((string)($r['ATENDIDO'] ?? ''));
            $calc = $s ? (string)$s['SITUACAO_CALCULADA'] : 'AI';
            $final = array_key_exists('SITUACAO_CONSOLIDADA', $r) ? self::codigo((string)$r['SITUACAO_CONSOLIDADA']) : ($s ? (string)$s['SITUACAO_FINAL'] : $manual);
            $ev = $itens[$chave] ?? [];
            usort($ev, static fn($a, $b) => [$ordemStatus[$a['STATUS']] ?? 9, $a['AREA'], $a['ITEM']] <=> [$ordemStatus[$b['STATUS']] ?? 9, $b['AREA'], $b['ITEM']]);
            $areas = [];
            foreach (self::AREAS_LEGAIS as $col => $rot) if (strcasecmp(trim((string)($r[$col] ?? '')), 'Sim') === 0) $areas[] = $rot;
            $lista[] = [
                'leg' => (int)$r['CODLEGISLACAO'], 'unid' => (int)$r['CODUNIDADE'],
                'unidade' => trim((string)($r['UNIDADE'] ?? $r['DSCUNIDADE'] ?? '')) ?: 'Unidade ' . $r['CODUNIDADE'],
                'codigo' => trim((string)($r['CODIGO'] ?? '')),
                'norma' => trim(preg_replace('/\s+/', ' ', trim((string)($r['DSCTIPO'] ?? '')) . ' ' . trim((string)($r['NR'] ?? '')) . (trim((string)($r['ANO'] ?? '')) !== '' ? '/' . trim((string)$r['ANO']) : '')) ?? ''),
                'ambito' => trim((string)($r['DSCAMBITO'] ?? '')), 'artigos' => trim((string)($r['ARTIGOS'] ?? '')),
                'padrao' => trim(strip_tags((string)($r['PADRAO'] ?? ''))), 'poder' => trim((string)($r['PODER'] ?? '')),
                'areas' => $areas, 'manual' => $manual, 'calc' => $calc, 'final' => $final,
                'origem' => $s ? ((string)$s['ORIGEM_FINAL'] === 'AVALIACAO' ? 'Avaliação do responsável' : 'Calculada') : 'Avaliação do responsável',
                'divergente' => ($r['DIVERGENCIA'] ?? '') === 'Sim' || ($s && (int)$s['DIVERGENTE'] === 1),
                'decisao' => $s && (int)$s['DECISAO_VALIDA'] === 1 ? ((string)$s['DECISAO'] === 'MANTIDA' ? 'Avaliação mantida' : 'Calculada aceita') : '',
                'decisao_por' => $s && (int)$s['DECISAO_VALIDA'] === 1 ? trim((string)$s['DECISAO_USUARIO'] . ' ' . (($s['DECISAO_EM'] ?? '') ? date('d/m/Y', strtotime((string)$s['DECISAO_EM'])) : '')) : '',
                'justificativa' => $s && (int)$s['DECISAO_VALIDA'] === 1 ? trim((string)$s['DECISAO_JUSTIFICATIVA']) : '',
                'motivo' => $s ? trim((string)$s['MOTIVO']) : '',
                'itens' => $ev,
                // Migração 341
                'pct' => $s && ($s['PCT_ATENDIMENTO'] ?? null) !== null && $s['PCT_ATENDIMENTO'] !== '' ? (float)$s['PCT_ATENDIMENTO'] : null,
                'risco' => $s ? (string)($s['RISCO'] ?? '') : '',
                'pend' => $s ? (int)($s['PENDENCIAS_CRITICAS'] ?? 0) : 0,
                'sem_risco' => $s ? (int)($s['ITENS_SEM_RISCO'] ?? 0) : 0,
                'aplic' => $s ? (int)($s['ITENS_APLICAVEIS'] ?? 0) : 0, 'atend' => $s ? (int)($s['ITENS_ATENDIDOS'] ?? 0) : 0, 'na' => $s ? (int)($s['ITENS_NA'] ?? 0) : 0,
            ];
        }
        usort($lista, static fn($a, $b) => [$a['unidade'], $a['codigo'], $a['leg']] <=> [$b['unidade'], $b['codigo'], $b['leg']]);
        // Resumos
        $tot = ['S' => 0, 'PA' => 0, 'N' => 0, 'AI' => 0];
        $porUnidade = []; $porArea = []; $semEv = 0; $comEv = 0; $div = 0;
        foreach ($lista as $q) {
            $tot[$q['final']]++;
            $porUnidade[$q['unidade']] ??= ['S' => 0, 'PA' => 0, 'N' => 0, 'AI' => 0];
            $porUnidade[$q['unidade']][$q['final']]++;
            foreach ($q['areas'] ?: ['Sem área'] as $a) { $porArea[$a] ??= ['S' => 0, 'PA' => 0, 'N' => 0, 'AI' => 0]; $porArea[$a][$q['final']]++; }
            if ($q['calc'] === 'AI') $semEv++; else $comEv++;
            if ($q['divergente'] || $q['decisao'] !== '') $div++;
        }
        ksort($porUnidade, SORT_NATURAL | SORT_FLAG_CASE);
        // Licenças: válidas x em protocolo x vencidas (só com a migração 288; com filtros, só as unidades do relatório)
        $licencas = null;
        try {
            $todas = self::selecionar($pdo, 'vw_dash_licencas', [], null);
            if (!$todas || array_key_exists('SITUACAO_LICENCA', $todas[0])) {
                $unids = $filtros || $busca !== '' ? array_flip(array_map(static fn($q) => $q['unid'], $lista)) : null;
                $licencas = ['tot' => array_fill_keys(['VALIDA', 'PROTOCOLO', 'PROT_SEM_PRAZO', 'VENCIDA', 'SEM_DATA'], 0), 'unidades' => [], 'lista' => []];
                foreach ($todas as $l) {
                    $sit = (string)($l['SITUACAO_LICENCA'] ?? '');
                    if (!isset($licencas['tot'][$sit]) || ($unids !== null && !isset($unids[(int)$l['CODUNIDADE']]))) continue;
                    $u = trim((string)$l['UNIDADE']) ?: 'Unidade ' . $l['CODUNIDADE'];
                    $licencas['tot'][$sit]++;
                    $licencas['unidades'][$u] ??= array_fill_keys(['VALIDA', 'PROTOCOLO', 'PROT_SEM_PRAZO', 'VENCIDA', 'SEM_DATA'], 0);
                    $licencas['unidades'][$u][$sit]++;
                    $licencas['lista'][] = ['unidade' => $u, 'licenca' => trim((string)$l['LICENCA']), 'tipo' => trim((string)($l['TIPO_DOCUMENTAL'] ?? '')),
                        'validade' => ($l['DATA_VALIDADE'] ?? '') ? date('d/m/Y', strtotime((string)$l['DATA_VALIDADE'])) : '', 'situacao' => $sit,
                        'protocolado_em' => ($l['PROTOCOLADO_EM'] ?? '') ? date('d/m/Y', strtotime((string)$l['PROTOCOLADO_EM'])) : '',
                        'numero' => trim((string)($l['NUMERO_PROTOCOLO'] ?? '')), 'no_prazo' => (string)($l['PROTOCOLO_NO_PRAZO'] ?? ''),
                        'motivo' => trim((string)($l['MOTIVO_SITUACAO'] ?? '')), 'responsavel' => trim((string)($l['RESPONSAVEL'] ?? ''))];
                }
                ksort($licencas['unidades'], SORT_NATURAL | SORT_FLAG_CASE);
                $ordemSit = ['VENCIDA' => 0, 'PROT_SEM_PRAZO' => 1, 'PROTOCOLO' => 2, 'SEM_DATA' => 3, 'VALIDA' => 4];
                usort($licencas['lista'], static fn($a, $b) => [$ordemSit[$a['situacao']], $a['unidade'], $a['licenca']] <=> [$ordemSit[$b['situacao']], $b['unidade'], $b['licenca']]);
            }
        } catch (Throwable) { $licencas = null; }
        // Migração 341: as duas dimensões (% de atendimento e risco), a matriz e as pendências críticas das linhas do relatório
        $risco = null;
        if ($situacoes && array_key_exists('PCT_ATENDIMENTO', reset($situacoes))) {
            $risco = ['tot' => array_fill_keys(array_keys(self::ROT_RISCO), 0), 'matriz' => [], 'aplic' => 0, 'atend' => 0, 'pend' => [], 'sem_itens' => 0];
            $chaves = [];
            foreach ($lista as $q) {
                $chaves[$q['leg'] . ':' . $q['unid']] = $q;
                $risco['aplic'] += $q['aplic']; $risco['atend'] += $q['atend'];
                if ($q['risco'] === '') { $risco['sem_itens']++; continue; }
                $risco['tot'][$q['risco']] = ($risco['tot'][$q['risco']] ?? 0) + 1;
                $f = $q['pct'] === null ? 'SEM' : ($q['pct'] >= 100 ? 'F100' : ($q['pct'] >= 50 ? 'F50' : ($q['pct'] > 0 ? 'F1' : 'F0')));
                $risco['matriz'][$q['risco']][$f] = ($risco['matriz'][$q['risco']][$f] ?? 0) + 1;
            }
            try {
                foreach (self::selecionar($pdo, 'vw_dash_legal_pendencias_criticas', [], null) as $p) {
                    $k = $p['CODLEGISLACAO'] . ':' . $p['CODUNIDADE'];
                    if (!isset($chaves[$k])) continue;
                    $ki = $k . ':' . $p['AREA'] . ':' . $p['ITEM_ID'];
                    $risco['pend'][$ki] ??= ['unidade' => $chaves[$k]['unidade'], 'codigo' => $chaves[$k]['codigo'], 'norma' => $chaves[$k]['norma'],
                        'area' => self::ROT_AREA_ITEM[(string)$p['AREA']] ?? (string)$p['AREA'], 'item' => (string)$p['ITEM'], 'status' => (string)$p['STATUS'], 'motivo' => (string)$p['MOTIVO'],
                        'sxp' => (int)$p['SEVERIDADE'] . ' × ' . (int)$p['PROBABILIDADE'], 'resp' => []];
                    if (trim((string)$p['RESPONSAVEL_ITEM']) !== '') $risco['pend'][$ki]['resp'][trim((string)$p['RESPONSAVEL_ITEM'])] = true;
                }
            } catch (Throwable) { }
            $risco['pend'] = array_values($risco['pend']);
            usort($risco['pend'], static fn($a, $b) => [$a['unidade'], $a['codigo'], $a['item']] <=> [$b['unidade'], $b['codigo'], $b['item']]);
        }
        return ['lista' => $lista, 'tot' => $tot, 'unidades' => $porUnidade, 'areas' => $porArea, 'sem_ev' => $semEv, 'com_ev' => $comEv, 'divergencias' => $div, 'licencas' => $licencas, 'risco' => $risco];
    }

    private static function textoFiltros(array $filtros, string $busca): string
    {
        $nomes = ['UNIDADE' => 'Unidade', 'PODER' => 'Nível de controle', 'ATENDIDO' => 'Avaliação', 'SITUACAO_CALCULADA' => 'Situação calculada',
            'DIVERGENCIA' => 'Divergência', 'SITUACAO_CONSOLIDADA' => 'Situação consolidada', 'DSCAMBITO' => 'Âmbito', 'LEI_REVOGADA' => 'Lei revogada',
            'SEM_EVIDENCIA' => 'Sem evidência', 'RESPONSAVEL_IDS' => 'Responsável', 'MEIO_AMBIENTE' => 'Meio ambiente', 'SEGURANCA' => 'Segurança',
            'QUALIDADE' => 'Qualidade', 'RESPOSABILIDADE' => 'Social'];
        $p = [];
        foreach ($filtros as $k => $v) {
            if (is_array($v)) $v = (string)($v['value'] ?? json_encode($v, JSON_UNESCAPED_UNICODE));
            $v = trim((string)$v); if ($v === '') continue;
            if ($k === 'LEI_REVOGADA') $v = strtoupper($v) === 'NAO' ? 'Não' : (strtoupper($v) === 'SIM' ? 'Sim' : $v);
            $p[] = ($nomes[$k] ?? $k) . ': ' . $v;
        }
        if ($busca !== '') $p[] = 'Busca: "' . $busca . '"';
        return $p ? implode(' · ', $p) : 'Todos os requisitos aplicáveis';
    }

    private static function pct(int $parte, int $total): string { return $total > 0 ? number_format(100 * $parte / $total, 1, ',', '.') . '%' : '–'; }

    // ------------------------------------------------------------------ PDF
    private static function pdf(string $arquivo, array $d, array $meta, string $root): void
    {
        require_once $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        $html = self::html($d, $meta);
        $opt = new Dompdf\Options();
        $opt->set('isRemoteEnabled', false);
        $opt->set('defaultFont', 'DejaVu Sans');
        $opt->set('isFontSubsettingEnabled', true);
        $pdf = new Dompdf\Dompdf($opt);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $fonte = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        $rodape = 'Relatório de Atendimento Legal · ' . $meta['empresa'] . ' · emitido em ' . $meta['emitido_em'];
        $canvas->page_text(34, 818, $rodape, $fonte, 6.5, [0.42, 0.51, 0.47]);
        $canvas->page_text(510, 818, 'Página {PAGE_NUM} de {PAGE_COUNT}', $fonte, 6.5, [0.42, 0.51, 0.47]);
        if (file_put_contents($arquivo, $pdf->output()) === false) throw new RuntimeException('Não foi possível gravar o PDF.');
    }

    private static function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

    private static function selo(string $cod): string
    {
        return '<span class="selo" style="background:' . self::COR_SIT[$cod] . '">' . self::e(self::ROT_SIT[$cod]) . '</span>';
    }

    private static function seloRisco(string $r): string
    {
        if ($r === '') return '';
        return '<span class="selo" style="background:' . (self::COR_RISCO[$r] ?? '#6c757d') . '">' . self::e(self::ROT_RISCO[$r] ?? $r) . '</span>';
    }

    private static function pctTexto(?float $v): string { return $v === null ? '–' : rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',') . '%'; }

    /** Seção das duas dimensões (migração 341): cartões, matriz risco × % de atendimento e pendências críticas. */
    private static function htmlRisco(array $r): string
    {
        $e = [self::class, 'e'];
        $h = '<h3>Atendimento e risco (duas dimensões)</h3><table class="cartoes" style="width:100%;border-collapse:collapse"><tr>'
            . '<td><div class="n">' . self::pctTexto($r['aplic'] > 0 ? 100 * $r['atend'] / $r['aplic'] : null) . '</div><div class="l">% de atendimento (' . $r['atend'] . ' de ' . $r['aplic'] . ' itens aplicáveis)</div></td>'
            . '<td><div class="n" style="color:#c62f3d">' . count($r['pend']) . '</div><div class="l">pendências críticas (ação imediata)</div></td>';
        foreach (['CRITICO', 'ALTO', 'MODERADO', 'BAIXO', 'NAO_AVALIADO'] as $k) $h .= '<td><div class="n" style="color:' . self::COR_RISCO[$k] . '">' . (int)$r['tot'][$k] . '</div><div class="l">risco ' . $e(mb_strtolower(self::ROT_RISCO[$k])) . '</div></td>';
        $h .= '</tr></table>';
        $faixas = ['F100' => '100%', 'F50' => '50 a 99%', 'F1' => '1 a 49%', 'F0' => '0%'];
        $h .= '<table class="grade" style="width:70%"><thead><tr><th class="esq">Risco do requisito</th>';
        foreach ($faixas as $f) $h .= '<th>' . $e($f) . '</th>';
        $h .= '<th>Total</th></tr></thead><tbody>';
        foreach (self::ROT_RISCO as $k => $rot) {
            $l = $r['matriz'][$k] ?? []; $tl = array_sum(array_intersect_key($l, $faixas));
            $h .= '<tr><td class="esq">' . self::seloRisco($k) . '</td>';
            foreach (array_keys($faixas) as $f) { $n = (int)($l[$f] ?? 0); $h .= '<td' . ($n > 0 && $k === 'CRITICO' && $f !== 'F100' ? ' style="background:#f8d7da;font-weight:bold"' : '') . '>' . ($n ?: '·') . '</td>'; }
            $h .= '<td><b>' . $tl . '</b></td></tr>';
        }
        $h .= '</tbody></table><p class="nota">% de atendimento = itens aplicáveis atendidos (Ok ou Atenção) ÷ itens aplicáveis (sem os marcados como "não se aplica"). Risco do requisito = pior risco entre os itens aplicáveis (Severidade × Probabilidade de cada item: 1–3 Baixo, 4–6 Moderado, 8–9 Alto, 12–16 ou Severidade 4 Crítico). As duas dimensões são independentes: um requisito pode estar 100% atendido e ter risco Crítico. Pendência crítica = item aplicável não atendido (Falha ou Vencido) com risco Crítico.'
            . ($r['sem_itens'] ? ' ' . $r['sem_itens'] . ' requisito(s) sem itens aplicáveis.' : '') . '</p>';
        return $h;
    }

    private static function seloStatus(string $st): string
    {
        return '<span class="selo" style="background:' . (self::COR_STATUS[$st] ?? '#6c757d') . '">' . self::e(self::ROT_STATUS[$st] ?? $st) . '</span>';
    }

    private static function barra(array $c): string
    {
        $t = array_sum($c);
        if ($t === 0) return '';
        $h = '<div class="barra">';
        foreach (['S', 'PA', 'N', 'AI'] as $k) if ($c[$k] > 0) $h .= '<div style="width:' . number_format(100 * $c[$k] / $t, 2, '.', '') . '%;background:' . self::COR_SIT[$k] . '"></div>';
        return $h . '</div>';
    }

    private static function tabelaContagem(string $titulo, array $grupos): string
    {
        $h = '<table class="grade"><thead><tr><th class="esq">' . self::e($titulo) . '</th><th>Requisitos</th><th>Atendido</th><th>Parcial</th><th>Não atendido</th><th>Indefinido</th><th>% atendido*</th><th class="esq" style="width:22%">Distribuição</th></tr></thead><tbody>';
        foreach ($grupos as $nome => $c) {
            $t = array_sum($c);
            $h .= '<tr><td class="esq">' . self::e((string)$nome) . '</td><td>' . $t . '</td><td>' . $c['S'] . '</td><td>' . $c['PA'] . '</td><td>' . $c['N'] . '</td><td>' . $c['AI'] . '</td><td>'
                . self::pct($c['S'], $t - $c['AI']) . '</td><td class="esq">' . self::barra($c) . '</td></tr>';
        }
        return $h . '</tbody></table>';
    }

    private static function html(array $d, array $meta): string
    {
        $e = [self::class, 'e'];
        $tot = $d['tot']; $total = array_sum($tot);
        $css = '@page{margin:28px 32px 40px 32px}body{font-family:"DejaVu Sans";font-size:7.6pt;color:#1f2d27}'
            . 'h1{font-size:17pt;color:#1b5e3f;margin:0 0 2px}h2{font-size:11.5pt;color:#1b5e3f;border-bottom:1.5px solid #2f7d5b;padding-bottom:3px;margin:16px 0 6px}'
            . 'h3{font-size:9pt;margin:8px 0 3px}.sub{color:#5f7169;font-size:8.5pt}.meta td{padding:1px 8px 1px 0;font-size:8pt}.meta td.r{color:#5f7169}'
            . 'table.grade{width:100%;border-collapse:collapse;margin:4px 0 8px}table.grade th{background:#e7f1ec;color:#24493c;font-weight:bold;padding:3px 4px;border:0.5px solid #c9dbd2;text-align:center}'
            . 'table.grade td{padding:2.5px 4px;border:0.5px solid #dbe6e0;text-align:center;vertical-align:top}table.grade .esq{text-align:left}'
            . '.selo{color:#fff;padding:1px 5px;border-radius:3px;font-size:6.8pt;font-weight:bold;white-space:nowrap}'
            . '.cartoes td{width:20%;border:0.5px solid #c9dbd2;padding:6px;text-align:center}.cartoes .n{font-size:15pt;font-weight:bold}.cartoes .l{font-size:7pt;color:#5f7169}'
            . '.barra{height:8px;width:100%;background:#eef2f0}.barra div{height:8px;float:left}.req{border:0.6px solid #cfdcd5;border-left:3px solid #2f7d5b;padding:4px 6px;margin:0 0 6px;page-break-inside:avoid}'
            . '.req .cab{font-weight:bold;font-size:8pt}.req .linha{color:#46574f;margin-top:1px}.nota{color:#5f7169;font-size:7pt}.quebra{page-break-before:always}.lista{line-height:1.45;font-size:7.2pt}';
        $h = '<html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>';
        // Capa / cabeçalho
        $h .= '<h1>Relatório de Atendimento Legal</h1><div class="sub">' . $e($meta['empresa']) . '</div>'
            . '<table class="meta" style="margin-top:6px"><tr><td class="r">Emitido em</td><td>' . $e($meta['emitido_em']) . '</td><td class="r">Por</td><td>' . $e($meta['emitido_por']) . '</td></tr>'
            . '<tr><td class="r">Filtros</td><td colspan="3">' . $e($meta['filtros']) . '</td></tr></table>';
        // 1. Resumo
        $h .= '<h2>1. Resumo</h2><table class="cartoes" style="width:100%;border-collapse:collapse"><tr>'
            . '<td><div class="n">' . $total . '</div><div class="l">requisitos</div></td>';
        foreach (['S', 'PA', 'N', 'AI'] as $k) $h .= '<td><div class="n" style="color:' . self::COR_SIT[$k] . '">' . $tot[$k] . '</div><div class="l">' . $e(self::ROT_SIT[$k]) . ' (' . self::pct($tot[$k], $total) . ')</div></td>';
        $h .= '</tr></table>';
        $h .= '<p>' . self::barra($tot) . '</p><p class="nota">Situação consolidada: a calculada a partir dos itens de gestão vinculados (licenças, medições, treinamentos, infraestrutura, controles e fornecedores) ou, quando não há itens vinculados ou o responsável manteve a avaliação com justificativa, a avaliação do responsável. '
            . $d['com_ev'] . ' requisito(s) com evidências vinculadas e ' . $d['sem_ev'] . ' sem evidências; ' . $d['divergencias'] . ' com divergência ou decisão registrada. * % atendido sobre os requisitos com situação definida.</p>';
        $h .= '<h3>Por unidade</h3>' . self::tabelaContagem('Unidade', $d['unidades']);
        $h .= '<h3>Por área</h3>' . self::tabelaContagem('Área', $d['areas']);
        if ($d['risco'] !== null) $h .= self::htmlRisco($d['risco']);
        // 2. Licenças: válidas x em protocolo x vencidas
        if ($d['licencas'] !== null) {
            $lic = $d['licencas']; $lt = array_sum($lic['tot']);
            $h .= '<h2 class="quebra">2. Licenças: válidas, em protocolo e vencidas (' . $lt . ')</h2><table class="cartoes" style="width:100%;border-collapse:collapse"><tr>';
            foreach (self::ROT_LIC as $k => $rot) $h .= '<td><div class="n" style="color:' . self::COR_LIC[$k] . '">' . $lic['tot'][$k] . '</div><div class="l">' . $e($rot) . ' (' . self::pct($lic['tot'][$k], $lt) . ')</div></td>';
            $h .= '</tr></table><p class="nota">Versão ativa de cada licença, sem as entregas periódicas. Válida (em protocolo): vencida, mas com a renovação protocolada no órgão dentro do prazo; continua valendo até a resposta do órgão. Protocolada fora do prazo conta como vencida. Em protocolo (prazo não informado): marcada em protocolo, mas sem as datas para saber se foi no prazo; fica em atenção até as datas serem informadas.</p>';
            if ($lic['unidades']) {
                $h .= '<table class="grade"><thead><tr><th class="esq">Unidade</th><th>Licenças</th><th>Válidas</th><th>Em protocolo</th><th>Em protocolo (sem prazo)</th><th>Vencidas</th><th>Sem data</th></tr></thead><tbody>';
                foreach ($lic['unidades'] as $u => $c) $h .= '<tr><td class="esq">' . $e((string)$u) . '</td><td>' . array_sum($c) . '</td><td>' . $c['VALIDA'] . '</td><td>' . $c['PROTOCOLO'] . '</td><td>' . $c['PROT_SEM_PRAZO'] . '</td><td>' . $c['VENCIDA'] . '</td><td>' . $c['SEM_DATA'] . '</td></tr>';
                $h .= '</tbody></table>';
            }
            $acao = array_values(array_filter($lic['lista'], static fn($l) => in_array($l['situacao'], ['VENCIDA', 'PROT_SEM_PRAZO', 'PROTOCOLO'], true)));
            $h .= '<h3>Licenças vencidas e em protocolo (' . count($acao) . ')</h3>';
            $h .= $acao ? self::tabela(['Unidade', 'Licença', 'Vencimento', 'Situação', 'Protocolo', 'Motivo'], array_map(static fn($l) => [
                    $l['unidade'], $l['licenca'], $l['validade'], ['html' => '<span class="selo" style="background:' . self::COR_LIC[$l['situacao']] . '">' . self::e(self::ROT_LIC[$l['situacao']]) . '</span>'],
                    trim(($l['numero'] !== '' ? 'Nº ' . $l['numero'] : '') . ($l['protocolado_em'] !== '' ? ' em ' . $l['protocolado_em'] : '')), $l['motivo']], $acao), [13, 25, 9, 12, 14, 27])
                : '<p class="nota">Nenhuma licença vencida ou em protocolo.</p>';
        }
        // Pendências críticas (migração 341): ação imediata, com os responsáveis dos itens em evidência
        if ($d['risco'] !== null) {
            $pend = $d['risco']['pend'];
            $h .= '<h2 class="quebra">Pendências críticas — ação imediata (' . count($pend) . ')</h2>';
            $h .= $pend ? self::tabela(['Unidade', 'Requisito', 'Item de gestão', 'Status', 'Sev. × Prob.', 'Motivo', 'Responsável'], array_map(static fn($p) => [
                    $p['unidade'], trim($p['codigo'] . ($p['norma'] !== '' ? ' · ' . $p['norma'] : '')), $p['area'] . ': ' . $p['item'], ['html' => self::seloStatus($p['status'])], $p['sxp'], $p['motivo'],
                    ['html' => $p['resp'] ? '<b>' . self::e(implode(', ', array_keys($p['resp']))) . '</b>' : '<span style="color:#c62f3d">sem responsável</span>']], $pend), [11, 15, 20, 8, 8, 24, 14])
                : '<p class="nota">Nenhum item não atendido com risco Crítico.</p>';
        }
        // 3. Não atendidos
        $nao = array_values(array_filter($d['lista'], static fn($q) => $q['final'] === 'N'));
        $h .= '<h2 class="quebra">3. Requisitos não atendidos (' . count($nao) . ')</h2>';
        $h .= $nao ? self::tabela(['Unidade', 'Requisito', 'Norma', 'Origem', 'Motivo / evidência'], array_map(static fn($q) => [
                $q['unidade'], $q['codigo'], $q['norma'], $q['origem'], $q['motivo'] !== '' && $q['calc'] !== 'AI' ? $q['motivo'] : 'Avaliação do responsável: não atendido'], $nao), [12, 10, 16, 11, 51])
            : '<p class="nota">Nenhum requisito não atendido com os filtros aplicados.</p>';
        // 4. Divergências
        $div = array_values(array_filter($d['lista'], static fn($q) => $q['divergente'] || $q['decisao'] !== ''));
        $h .= '<h2>4. Divergências entre a avaliação do responsável e a situação calculada (' . count($div) . ')</h2>';
        $h .= $div ? self::tabela(['Unidade', 'Requisito', 'Avaliação', 'Calculada', 'Decisão', 'Justificativa'], array_map(static fn($q) => [
                $q['unidade'], $q['codigo'], ['html' => self::selo($q['manual'])], ['html' => self::selo($q['calc'])],
                $q['decisao'] !== '' ? $q['decisao'] . ($q['decisao_por'] !== '' ? ' (' . $q['decisao_por'] . ')' : '') : 'Pendente', $q['justificativa']], $div), [12, 11, 13, 13, 18, 33])
            : '<p class="nota">Nenhuma divergência.</p>';
        // 5. Detalhe com evidências
        $com = array_values(array_filter($d['lista'], static fn($q) => $q['calc'] !== 'AI'));
        $h .= '<h2 class="quebra">5. Requisitos com evidências de atendimento (' . count($com) . ')</h2>';
        foreach ($com as $q) {
            $h .= '<div class="req" style="border-left-color:' . self::COR_SIT[$q['final']] . '"><div class="cab">' . $e($q['codigo'] !== '' ? $q['codigo'] : '#' . $q['leg']) . ' · ' . $e($q['norma'])
                . ' <span style="float:right">' . self::selo($q['final']) . '</span></div>'
                . '<div class="linha">' . $e($q['unidade']) . ($q['ambito'] !== '' ? ' · ' . $e($q['ambito']) : '') . ($q['areas'] ? ' · ' . $e(implode(', ', $q['areas'])) : '') . ($q['poder'] !== '' ? ' · Nível de controle: ' . $e($q['poder']) : '') . '</div>'
                . '<div class="linha">Calculada: ' . $e(self::ROT_SIT[$q['calc']]) . ' · Avaliação do responsável: ' . $e(self::ROT_SIT[$q['manual']]) . ' · Vale: ' . $e($q['origem'])
                . ($q['decisao'] !== '' ? ' · ' . $e($q['decisao']) . ($q['justificativa'] !== '' ? ': ' . $e($q['justificativa']) : '') : '') . '</div>';
            if ($d['risco'] !== null) $h .= '<div class="linha">Atendimento: <b>' . self::pctTexto($q['pct']) . '</b> (' . $q['atend'] . ' de ' . $q['aplic'] . ' itens aplicáveis' . ($q['na'] ? '; ' . $q['na'] . ' não se aplica' : '') . ') · Risco: ' . ($q['risco'] !== '' ? self::seloRisco($q['risco']) : '–')
                . ($q['pend'] ? ' · <b style="color:#c62f3d">' . $q['pend'] . ' pendência(s) crítica(s)</b>' : '') . ($q['sem_risco'] ? ' · ' . $q['sem_risco'] . ' item(ns) com risco não avaliado' : '') . '</div>';
            if ($q['itens'] && $d['risco'] !== null) {
                $h .= self::tabela(['Área', 'Item de gestão', 'Status', 'Sev. × Prob.', 'Risco', 'Motivo'], array_map(static fn($i) => [
                    self::ROT_AREA_ITEM[(string)$i['AREA']] ?? (string)$i['AREA'], ['html' => (int)($i['NA'] ?? 0) === 1 ? '<s>' . self::e((string)$i['ITEM']) . '</s>' : self::e((string)$i['ITEM'])], ['html' => self::seloStatus((string)$i['STATUS'])],
                    (int)($i['SEVERIDADE'] ?? 0) ? (int)$i['SEVERIDADE'] . ' × ' . (int)$i['PROBABILIDADE'] : '–', ['html' => (int)($i['NA'] ?? 0) === 1 ? 'Não se aplica' : self::seloRisco((string)($i['RISCO'] ?? '') ?: 'NAO_AVALIADO')],
                    (string)$i['MOTIVO'] . ((int)($i['NA'] ?? 0) === 1 ? ' — Não se aplica: ' . (string)($i['NA_JUSTIFICATIVA'] ?? '') : '')], $q['itens']), [13, 28, 8, 8, 9, 34]);
            } elseif ($q['itens']) {
                $h .= self::tabela(['Área', 'Item de gestão', 'Status', 'Motivo'], array_map(static fn($i) => [
                    self::ROT_AREA_ITEM[(string)$i['AREA']] ?? (string)$i['AREA'], (string)$i['ITEM'], ['html' => self::seloStatus((string)$i['STATUS'])], (string)$i['MOTIVO']], $q['itens']), [14, 34, 9, 43]);
            }
            $h .= '</div>';
        }
        if (!$com) $h .= '<p class="nota">Nenhum requisito com itens de gestão vinculados.</p>';
        // 6. Sem evidências (lista completa)
        $sem = array_values(array_filter($d['lista'], static fn($q) => $q['calc'] === 'AI'));
        $h .= '<h2 class="quebra">6. Requisitos sem evidências vinculadas (' . count($sem) . ')</h2><p class="nota">Requisitos sem nenhum item de gestão vinculado; vale a avaliação do responsável. Vincular licenças, medições, treinamentos, infraestrutura, controles ou fornecedores permite calcular o atendimento.</p>';
        // Lista longa (milhares de linhas): texto corrido por unidade, sem tabela, que o Dompdf monta muito mais rápido
        $porUnidade = [];
        foreach ($sem as $q) $porUnidade[$q['unidade']][] = $q;
        foreach ($porUnidade as $unidade => $qs) {
            $h .= '<h3>' . $e($unidade) . ' (' . count($qs) . ')</h3>';
            foreach (array_chunk($qs, 400) as $bloco) {
                $linhas = [];
                foreach ($bloco as $q) $linhas[] = '<b>' . $e($q['codigo'] !== '' ? $q['codigo'] : '#' . $q['leg']) . '</b> ' . $e($q['norma']) . ($q['ambito'] !== '' ? ' · ' . $e($q['ambito']) : '')
                    . ' · <span style="color:' . self::COR_SIT[$q['manual']] . '">' . $e(self::ROT_SIT[$q['manual']]) . '</span>';
                $h .= '<div class="lista">' . implode('<br>', $linhas) . '</div>';
            }
        }
        return $h . '</body></html>';
    }

    /** Tabela em blocos de 250 linhas (o Dompdf fica bem mais rápido com tabelas menores). */
    private static function tabela(array $cab, array $linhas, array $larguras): string
    {
        $th = '<thead><tr>';
        foreach ($cab as $i => $c) $th .= '<th class="esq" style="width:' . ($larguras[$i] ?? 10) . '%">' . self::e($c) . '</th>';
        $th .= '</tr></thead>';
        $h = '';
        foreach (array_chunk($linhas, 250) as $bloco) {
            $h .= '<table class="grade">' . $th . '<tbody>';
            foreach ($bloco as $l) {
                $h .= '<tr>';
                foreach ($l as $v) $h .= '<td class="esq">' . (is_array($v) ? $v['html'] : self::e((string)$v)) . '</td>';
                $h .= '</tr>';
            }
            $h .= '</tbody></table>';
        }
        return $h;
    }

    // ------------------------------------------------------------------ Excel
    private static function excel(string $arquivo, array $d, array $meta): void
    {
        $tot = $d['tot']; $total = array_sum($tot);
        $resumo = [
            ['Relatório de Atendimento Legal'], [$meta['empresa']], [],
            ['Emitido em', $meta['emitido_em']], ['Por', $meta['emitido_por']], ['Filtros', $meta['filtros']], [],
            ['Situação consolidada', 'Requisitos', '%'],
        ];
        foreach (['S', 'PA', 'N', 'AI'] as $k) $resumo[] = [self::ROT_SIT[$k], $tot[$k], self::pct($tot[$k], $total)];
        $resumo[] = ['Total', $total, ''];
        $resumo[] = [];
        $resumo[] = ['Com evidências vinculadas', $d['com_ev']];
        $resumo[] = ['Sem evidências vinculadas', $d['sem_ev']];
        $resumo[] = ['Com divergência ou decisão', $d['divergencias']];
        $resumo[] = [];
        $cabUnidade = count($resumo) + 1;
        $resumo[] = ['Unidade', 'Requisitos', 'Atendido', 'Parcial', 'Não atendido', 'Indefinido', '% atendido'];
        foreach ($d['unidades'] as $u => $c) { $t = array_sum($c); $resumo[] = [$u, $t, $c['S'], $c['PA'], $c['N'], $c['AI'], self::pct($c['S'], $t - $c['AI'])]; }
        $resumo[] = [];
        $cabArea = count($resumo) + 1;
        $resumo[] = ['Área', 'Requisitos', 'Atendido', 'Parcial', 'Não atendido', 'Indefinido', '% atendido'];
        foreach ($d['areas'] as $a => $c) { $t = array_sum($c); $resumo[] = [$a, $t, $c['S'], $c['PA'], $c['N'], $c['AI'], self::pct($c['S'], $t - $c['AI'])]; }
        if ($d['licencas'] !== null) {
            $resumo[] = [];
            $resumo[] = ['Licenças (sem entregas periódicas)', 'Quantidade', '%'];
            $cabLic = count($resumo);
            $lt = array_sum($d['licencas']['tot']);
            foreach (self::ROT_LIC as $k => $rot) $resumo[] = [$rot, $d['licencas']['tot'][$k], self::pct($d['licencas']['tot'][$k], $lt)];
            $resumo[] = ['Total', $lt, ''];
        }
        $r341 = $d['risco'] !== null;
        if ($r341) {
            $r = $d['risco'];
            $resumo[] = [];
            $cabRisco = count($resumo) + 1;
            $resumo[] = ['Risco do requisito', '100%', '50 a 99%', '1 a 49%', '0%', 'Total'];
            foreach (self::ROT_RISCO as $k => $rot) { $l = $r['matriz'][$k] ?? []; $resumo[] = [$rot, (int)($l['F100'] ?? 0), (int)($l['F50'] ?? 0), (int)($l['F1'] ?? 0), (int)($l['F0'] ?? 0), (int)$r['tot'][$k]]; }
            $resumo[] = ['% de atendimento (itens aplicáveis)', self::pctTexto($r['aplic'] > 0 ? 100 * $r['atend'] / $r['aplic'] : null), $r['atend'] . ' de ' . $r['aplic']];
            $resumo[] = ['Pendências críticas (ação imediata)', count($r['pend'])];
        }
        $req = [array_merge(['Unidade', 'Requisito', 'Norma', 'Âmbito', 'Áreas', 'Nível de controle', 'Artigos', 'Situação consolidada', 'Origem', 'Situação calculada'],
            $r341 ? ['% atendimento', 'Risco', 'Pendências críticas', 'Itens aplicáveis', 'Itens atendidos', 'Itens não se aplica', 'Itens sem risco avaliado'] : [],
            ['Avaliação do responsável', 'Divergência', 'Decisão', 'Decidido por', 'Justificativa', 'Itens de gestão', 'Motivo da situação calculada', 'Código da legislação', 'Código da unidade'])];
        $ev = [array_merge(['Unidade', 'Requisito', 'Norma', 'Área', 'Item de gestão', 'Status'], $r341 ? ['Severidade', 'Probabilidade', 'Pontos', 'Risco', 'Não se aplica', 'Justificativa (não se aplica)'] : [], ['Motivo', 'Situação consolidada do requisito'])];
        foreach ($d['lista'] as $q) {
            $req[] = array_merge([$q['unidade'], $q['codigo'], $q['norma'], $q['ambito'], implode(', ', $q['areas']), $q['poder'], $q['artigos'], self::ROT_SIT[$q['final']], $q['origem'],
                $q['calc'] === 'AI' ? 'Sem evidências vinculadas' : self::ROT_SIT[$q['calc']]],
                $r341 ? [self::pctTexto($q['pct']), self::ROT_RISCO[$q['risco']] ?? '', $q['pend'], $q['aplic'], $q['atend'], $q['na'], $q['sem_risco']] : [],
                [self::ROT_SIT[$q['manual']], $q['divergente'] ? 'Sim' : 'Não', $q['decisao'], $q['decisao_por'], $q['justificativa'], count($q['itens']), $q['motivo'], $q['leg'], $q['unid']]);
            foreach ($q['itens'] as $i) {
                $na = (int)($i['NA'] ?? 0) === 1;
                $ev[] = array_merge([$q['unidade'], $q['codigo'], $q['norma'], self::ROT_AREA_ITEM[(string)$i['AREA']] ?? (string)$i['AREA'], (string)$i['ITEM'], self::ROT_STATUS[(string)$i['STATUS']] ?? (string)$i['STATUS']],
                    $r341 ? [(int)($i['SEVERIDADE'] ?? 0) ?: '', (int)($i['PROBABILIDADE'] ?? 0) ?: '', (int)($i['PONTOS'] ?? 0) ?: '', self::ROT_RISCO[(string)($i['RISCO'] ?? '') ?: 'NAO_AVALIADO'], $na ? 'Sim' : 'Não', $na ? (string)($i['NA_JUSTIFICATIVA'] ?? '') : ''] : [],
                    [(string)$i['MOTIVO'], self::ROT_SIT[$q['final']]]);
            }
        }
        $planilhas = [
            'Resumo' => ['linhas' => $resumo, 'larguras' => [34, 16, 12, 12, 14, 12, 12], 'cabecalhos' => array_values(array_filter([8, $cabUnidade, $cabArea, $cabLic ?? null, $cabRisco ?? null])), 'titulo' => true],
            'Requisitos' => ['linhas' => $req, 'larguras' => array_merge([18, 14, 26, 18, 22, 14, 18, 20, 20, 22], $r341 ? [12, 12, 12, 12, 12, 12, 14] : [], [20, 11, 16, 20, 40, 12, 70, 12, 12]), 'cabecalhos' => [1], 'filtro' => true],
            'Evidências' => ['linhas' => $ev, 'larguras' => array_merge([18, 14, 26, 20, 40, 10], $r341 ? [11, 11, 8, 12, 12, 40] : [], [70, 20]), 'cabecalhos' => [1], 'filtro' => true],
        ];
        if ($r341) {
            $pend = [['Unidade', 'Requisito', 'Norma', 'Área', 'Item de gestão', 'Status', 'Severidade × Probabilidade', 'Motivo', 'Responsável']];
            foreach ($d['risco']['pend'] as $p) $pend[] = [$p['unidade'], $p['codigo'], $p['norma'], $p['area'], $p['item'], self::ROT_STATUS[$p['status']] ?? $p['status'], $p['sxp'], $p['motivo'], $p['resp'] ? implode(', ', array_keys($p['resp'])) : 'sem responsável'];
            $planilhas['Pendências críticas'] = ['linhas' => $pend, 'larguras' => [18, 14, 26, 18, 40, 10, 14, 60, 30], 'cabecalhos' => [1], 'filtro' => true];
        }
        if ($d['licencas'] !== null) {
            $lic = [['Unidade', 'Licença', 'Tipo documental', 'Vencimento', 'Situação', 'Número do protocolo', 'Protocolado em', 'Protocolado no prazo', 'Motivo', 'Responsável']];
            foreach ($d['licencas']['lista'] as $l) $lic[] = [$l['unidade'], $l['licenca'], $l['tipo'], $l['validade'], self::ROT_LIC[$l['situacao']], $l['numero'], $l['protocolado_em'],
                $l['no_prazo'] === 'S' ? 'Sim' : ($l['no_prazo'] === 'N' ? 'Não' : ''), $l['motivo'], $l['responsavel']];
            $planilhas['Licenças'] = ['linhas' => $lic, 'larguras' => [18, 40, 22, 12, 20, 18, 14, 12, 70, 24], 'cabecalhos' => [1], 'filtro' => true];
        }
        self::xlsx($arquivo, $planilhas);
    }

    private static function col(int $n): string { $s = ''; while ($n > 0) { $n--; $s = chr(65 + $n % 26) . $s; $n = intdiv($n, 26); } return $s; }

    private static function xlsx(string $arquivo, array $planilhas): void
    {
        $x = static fn(string $v): string => htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $zip = new ZipArchive();
        if ($zip->open($arquivo, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Não foi possível criar a planilha.');
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $wbr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $defs = '';
        $i = 0;
        foreach ($planilhas as $nome => $p) {
            $i++;
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wb .= '<sheet name="' . $x($nome) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
            $wbr .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
            $linhas = $p['linhas'];
            $maxCol = max(array_map('count', $linhas) ?: [1]);
            $sh = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
            if (!empty($p['filtro'])) $sh .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
            $sh .= '<cols>';
            foreach ($p['larguras'] as $c => $w) $sh .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"/>';
            $sh .= '</cols><sheetData>';
            foreach ($linhas as $r => $l) {
                $rn = $r + 1;
                $estilo = in_array($rn, $p['cabecalhos'] ?? [], true) ? 1 : (!empty($p['titulo']) && $rn === 1 ? 2 : 3);
                $sh .= '<row r="' . $rn . '">';
                foreach ($l as $c => $v) {
                    $ref = self::col($c + 1) . $rn;
                    if (is_int($v) || is_float($v)) $sh .= '<c r="' . $ref . '" s="' . $estilo . '"><v>' . $v . '</v></c>';
                    else $sh .= '<c r="' . $ref . '" t="inlineStr" s="' . $estilo . '"><is><t xml:space="preserve">' . $x((string)$v) . '</t></is></c>';
                }
                $sh .= '</row>';
            }
            $sh .= '</sheetData>';
            if (!empty($p['filtro']) && count($linhas) > 1) {
                $sh .= '<autoFilter ref="A1:' . self::col($maxCol) . count($linhas) . '"/>';
                $defs .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . ($i - 1) . '" hidden="1">\'' . $x($nome) . '\'!$A$1:$' . self::col($maxCol) . '$' . count($linhas) . '</definedName>';
            }
            $sh .= '</worksheet>';
            $zip->addFromString('xl/worksheets/sheet' . $i . '.xml', $sh);
        }
        $ct .= '</Types>';
        $wb .= '</sheets>' . ($defs !== '' ? '<definedNames>' . $defs . '</definedNames>' : '') . '</workbook>';
        $wbr .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="3"><font><sz val="10"/><name val="Calibri"/></font><font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="14"/><color rgb="FF1B5E3F"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF2F7D5B"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFD7E3DC"/></left><right style="thin"><color rgb="FFD7E3DC"/></right><top style="thin"><color rgb="FFD7E3DC"/></top><bottom style="thin"><color rgb="FFD7E3DC"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment vertical="top"/></xf></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
        $zip->addFromString('[Content_Types].xml', $ct);
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $wb);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbr);
        $zip->addFromString('xl/styles.xml', $styles);
        if (!$zip->close()) throw new RuntimeException('Não foi possível gravar a planilha.');
    }
}
