<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';

$pendingMode = !empty($_GET['minhas_pendencias']);

ManutencaoLegalCadastroGrid::render([
    // Migração 341: Severidade × Probabilidade = Risco do item (obrigatório ao incluir; ver Ajuda de Requisitos legais)
    'risk_area' => 'MEDICAO',
    'menu' => 'MM_PLANO_MEDICAO_MONITORAMENTO',
    'title' => ['pt' => 'Plano de Medição e Monitoramento', 'en' => 'Measurement and Monitoring Plan', 'es' => 'Plan de Medición y Monitoreo'],
    'transaction' => 'medicaoemonitoramento_mm_monitoramento',
    'database' => 'gmodelo_dbp', 'prefix' => 'GPT',
    'fragment_class' => 'medicao-monitoramento-grid-fragment',
    'table' => 'mm_monitoramento', 'view' => $pendingMode ? 'vw_minhas_monitoramentos_grid' : 'vw_medicao_monitoramento_monitoramento_grid',
    'primary' => 'CODMONITORAMENTO', 'primary_auto' => true, 'primary_editable' => false,
    'user_scope_field' => 'CODUSUARIO', 'force_user_scope' => $pendingMode,
    // Migração 361: botão Responsáveis (registro selecionado; resolve o grupo na Medição); Minhas medições = Responsável + atribuições + regras
    'resp_area' => 'MEDICAO', 'resp_minhas' => $pendingMode ? 'MEDICAO' : '',
    'legacy_order' => ['CODUNIDADE','CODMEDICAO','CODEQUIPAMENTO','CODAREA','CODASPECTO','CODPERIGO','PARAMETROS','DATA_MEDICAO','DATA_REACAO','CODUSUARIO','cronograma','OBS','CODMONITORAMENTO'],
    'legacy_search' => ['#select_filter','#select_filter','#select_filter','#select_filter','#select_filter','#select_filter','#text_filter','#text_filter','#text_filter','#select_filter','','#text_filter',''],
    'legacy_labels' => ['Unidade','Tipo de Medição','Equipamento de Medição','Área','Aspecto','Perigo','Parâmetros','Data da Medição','Data de Reação','Responsável','Cronograma','Observações','Código'],
    'legacy_widths' => [190,220,220,190,210,210,240,160,160,200,120,300,0],
    'legacy_sort' => ['strc','strc','strc','strc','strc','strc','str','str','str','strc','str','str','str'],
    'legacy_types' => ['coro','coro','coro','coro','coro','coro','txt','ed','ed','coro','ro','txt','cntr'],
    'legacy_fixed' => true,
    'row_enricher' => static function (array $rows, PDO $pdo): array {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int)($row['CODMONITORAMENTO'] ?? 0);
            if ($id > 0) $ids[$id] = $id;
        }
        foreach ($rows as &$row) $row['cronograma_eventos_pendentes'] = 0;
        unset($row);
        if (!$ids) return $rows;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $pdo->prepare('SELECT codmonitoramento, COUNT(*) AS quantidade FROM ev_eventos WHERE codmonitoramento IN (' . $placeholders . ') AND datafim >= CURRENT_TIMESTAMP GROUP BY codmonitoramento');
        $statement->execute(array_values($ids));
        $counts = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $countRow) $counts[(int)$countRow['codmonitoramento']] = (int)$countRow['quantidade'];
        foreach ($rows as &$row) $row['cronograma_eventos_pendentes'] = $counts[(int)($row['CODMONITORAMENTO'] ?? 0)] ?? 0;
        unset($row);
        return $rows;
    },
    'button_options' => $pendingMode ? ['save'=>true,'new'=>false,'duplicate'=>false,'delete'=>false,'reload'=>true,'export'=>true,'columns'=>true] : ['save'=>true,'new'=>true,'duplicate'=>true,'delete'=>true,'reload'=>true,'export'=>true,'columns'=>true],
    'fields' => [
        'CODUNIDADE' => ['label'=>['pt'=>'Unidade','en'=>'Unit','es'=>'Unidad'],'filter'=>'select','width'=>190,'lazy_foreign'=>false,'foreign'=>['view'=>'vw_unidades_grid','value'=>'CODUNIDADE','label'=>'DSCUNIDADE','filters'=>['usado_em_manutencao_legal'=>'Sim'],'description'=>'DSCUNIDADE']],
        'CODMEDICAO' => ['label'=>['pt'=>'Tipo de medição','en'=>'Measurement type','es'=>'Tipo de medición'],'filter'=>'select','width'=>220,'lazy_foreign'=>false,'foreign'=>['view'=>'mm_medicao','value'=>'codmedicao','label'=>'dscmedicao','description'=>'DSCMEDICAO']],
        'CODEQUIPAMENTO' => ['label'=>['pt'=>'Equipamento de medição','en'=>'Measuring equipment','es'=>'Equipo de medición'],'filter'=>'select','width'=>220,'lazy_foreign'=>false,'foreign'=>['view'=>'mm_equipamento','value'=>'CODEQUIPAMENTO','label'=>'DSCEQUIPAMENTO','description'=>'DSCEQUIPAMENTO']],
        'CODAREA' => ['label'=>['pt'=>'Área','en'=>'Area','es'=>'Área'],'filter'=>'select','width'=>190,'lazy_foreign'=>false,'foreign'=>['view'=>'vw_areas_grid','value'=>'CODAREA','label'=>'DSCAREA','description'=>'DSCAREA']],
        'CODASPECTO' => ['label'=>['pt'=>'Aspecto','en'=>'Aspect','es'=>'Aspecto'],'filter'=>'select','width'=>210,'lazy_foreign'=>false,'foreign'=>['view'=>'vw_aspectos_grid','value'=>'CODASPECTO','label'=>'DSCASPECTO','description'=>'DSCASPECTO']],
        'CODPERIGO' => ['label'=>['pt'=>'Perigo','en'=>'Hazard','es'=>'Peligro'],'filter'=>'select','width'=>210,'lazy_foreign'=>false,'foreign'=>['view'=>'vw_perigos_grid','value'=>'CODPERIGO','label'=>'DSCPERIGO','description'=>'DSCPERIGO']],
        'PARAMETROS' => ['label'=>['pt'=>'Parâmetros','en'=>'Parameters','es'=>'Parámetros'],'type'=>'textarea','filter'=>'text','width'=>240],
        'DATA_MEDICAO' => ['label'=>['pt'=>'Data da medição','en'=>'Measurement date','es'=>'Fecha de medición'],'type'=>'date','display_format'=>'date','filter'=>'text','width'=>160],
        'DATA_REACAO' => ['label'=>['pt'=>'Data de reação','en'=>'Reaction date','es'=>'Fecha de reacción'],'type'=>'date','display_format'=>'date','filter'=>'text','width'=>160],
        'CODUSUARIO' => ['label'=>['pt'=>'Responsável','en'=>'Responsible','es'=>'Responsable'],'filter'=>'select','width'=>200,'lazy_foreign'=>false,'foreign'=>['view'=>'vw_usuarios_grid','value'=>'codusuario','label'=>'nome','filters'=>['ativo'=>1],'description'=>'NOME_RESPONSAVEL']],
        'cronograma' => ['label'=>['pt'=>'Cronograma','en'=>'Schedule','es'=>'Cronograma'],'virtual'=>true,'count_field'=>'cronograma_eventos_pendentes','count_label'=>['pt'=>'Eventos ainda não vencidos','en'=>'Events not yet expired','es'=>'Eventos aún vigentes'],'action_key'=>'cronograma','action_label'=>['pt'=>'Abrir cronograma','en'=>'Open schedule','es'=>'Abrir cronograma'],'action_icon'=>'bi-calendar3','filter'=>'none','width'=>120],
        'OBS' => ['label'=>['pt'=>'Observações','en'=>'Notes','es'=>'Observaciones'],'type'=>'textarea','filter'=>'text','width'=>300],
        'CODMONITORAMENTO' => ['label'=>['pt'=>'Código','en'=>'Code','es'=>'Código'],'hidden'=>true,'readonly'=>true,'filter'=>'none'],
    ],
]);
