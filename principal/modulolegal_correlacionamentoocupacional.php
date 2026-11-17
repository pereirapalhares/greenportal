<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
// Dicas dos botões em Administração › Textos e idiomas (cadastro automático ao abrir como ADMINISTRADOR).
$tt = static fn(string $chave, array $padrao): string => htmlspecialchars(IdiomaService::t($chave, $padrao), ENT_QUOTES, 'UTF-8');

ManutencaoLegalCadastroGrid::render([
    'menu'=>'CORRELACIONAMENTO_OCUPACIONAL',
    'title'=>['pt'=>'Correlacionamento Ocupacional','en'=>'Occupational Correlation','es'=>'Correlacionamiento ocupacional'],
    'transaction'=>'modulolegal_correlacionamentoocupacional',
    'fragment_class'=>'modulolegal-correlacionamento-ocupacional-fragment',
    'pdf_transaction'=>'CORRELACIONAMENTO_OCUPACIONAL',
    'database'=>'gmodelo_dbp','prefix'=>'GPT','table'=>'legislacaoxempresa',
    'view'=>'vw_modulolegal_correlacionamento_ocupacional_grid','primary'=>'CODLEGISLACAO',
    'read_only_grid'=>true,
    'button_options'=>['save'=>false,'new'=>false,'duplicate'=>false,'delete'=>false,'reload'=>true,'export'=>true,'columns'=>true],
    'extras'=>'<button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child data-context-url="/principal/modulolegal_correlacionamentoocupacional_perigos.php" data-context-fields="CODLEGISLACAO,CODUNIDADE" data-context-title="' . $tt('modulolegal_correlacionamentoocupacional.botao.perigos', ['pt' => 'Perigos ocupacionais', 'en' => 'Occupational hazards', 'es' => 'Peligros laborales']) . '" disabled title="' . $tt('modulolegal_correlacionamentoocupacional.botao.perigos', ['pt' => 'Perigos ocupacionais', 'en' => 'Occupational hazards', 'es' => 'Peligros laborales']) . '" aria-label="' . $tt('modulolegal_correlacionamentoocupacional.botao.perigos', ['pt' => 'Perigos ocupacionais', 'en' => 'Occupational hazards', 'es' => 'Peligros laborales']) . '"><i class="bi bi-exclamation-triangle"></i></button><button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child data-context-url="/principal/modulolegal_correlacionamentoocupacional_atividades.php" data-context-fields="CODLEGISLACAO,CODUNIDADE" data-context-title="' . $tt('modulolegal_correlacionamentoocupacional.botao.atividades', ['pt' => 'Atividades ocupacionais', 'en' => 'Occupational activities', 'es' => 'Actividades laborales']) . '" disabled title="' . $tt('modulolegal_correlacionamentoocupacional.botao.atividades', ['pt' => 'Atividades ocupacionais', 'en' => 'Occupational activities', 'es' => 'Actividades laborales']) . '" aria-label="' . $tt('modulolegal_correlacionamentoocupacional.botao.atividades', ['pt' => 'Atividades ocupacionais', 'en' => 'Occupational activities', 'es' => 'Actividades laborales']) . '"><i class="bi bi-list-check"></i></button><button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child data-context-url="/principal/modulolegal_correlacionamentoocupacional_riscos.php" data-context-fields="CODLEGISLACAO,CODUNIDADE" data-context-title="' . $tt('modulolegal_correlacionamentoocupacional.botao.riscos', ['pt' => 'Riscos ocupacionais', 'en' => 'Occupational risks', 'es' => 'Riesgos laborales']) . '" disabled title="' . $tt('modulolegal_correlacionamentoocupacional.botao.riscos', ['pt' => 'Riscos ocupacionais', 'en' => 'Occupational risks', 'es' => 'Riesgos laborales']) . '" aria-label="' . $tt('modulolegal_correlacionamentoocupacional.botao.riscos', ['pt' => 'Riscos ocupacionais', 'en' => 'Occupational risks', 'es' => 'Riesgos laborales']) . '"><i class="bi bi-shield-exclamation"></i></button><button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child data-context-url="/principal/modulolegal_correlacionamentoocupacional_licencas.php" data-context-fields="CODLEGISLACAO,CODUNIDADE" data-context-title="' . $tt('modulolegal_correlacionamentoocupacional.botao.licencas', ['pt' => 'Licenças relacionadas', 'en' => 'Related licenses', 'es' => 'Licencias relacionadas']) . '" disabled title="' . $tt('modulolegal_correlacionamentoocupacional.botao.licencas', ['pt' => 'Licenças relacionadas', 'en' => 'Related licenses', 'es' => 'Licencias relacionadas']) . '" aria-label="' . $tt('modulolegal_correlacionamentoocupacional.botao.licencas', ['pt' => 'Licenças relacionadas', 'en' => 'Related licenses', 'es' => 'Licencias relacionadas']) . '"><i class="bi bi-file-earmark-check"></i></button><button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child data-context-url="/principal/modulolegal_correlacionamentoocupacional_documentos.php" data-context-fields="CODLEGISLACAO,CODUNIDADE" data-context-title="' . $tt('modulolegal_correlacionamentoocupacional.botao.documentos', ['pt' => 'Documentos relacionados', 'en' => 'Related documents', 'es' => 'Documentos relacionados']) . '" disabled title="' . $tt('modulolegal_correlacionamentoocupacional.botao.documentos', ['pt' => 'Documentos relacionados', 'en' => 'Related documents', 'es' => 'Documentos relacionados']) . '" aria-label="' . $tt('modulolegal_correlacionamentoocupacional.botao.documentos', ['pt' => 'Documentos relacionados', 'en' => 'Related documents', 'es' => 'Documentos relacionados']) . '"><i class="bi bi-file-earmark-text"></i></button>',
    'fields'=>[
        'CODLEGISLACAO'=>['label'=>['pt'=>'Código da legislação','en'=>'Legislation code','es'=>'Código de legislación'],'readonly'=>true,'hidden'=>true,'filter'=>'none'],
        'DSCUNIDADE'=>['label'=>['pt'=>'Unidade','en'=>'Unit','es'=>'Unidad'],'readonly'=>true,'filter'=>'select'],
        'NIVEL_DE_CONTROLE'=>['label'=>['pt'=>'Nível de controle','en'=>'Control level','es'=>'Nivel de control'],'readonly'=>true,'filter'=>'select'],
        'CODIGO'=>['label'=>['pt'=>'Código','en'=>'Code','es'=>'Código'],'readonly'=>true,'filter'=>'text'],
        'DSCAMBITO'=>['label'=>['pt'=>'Âmbito','en'=>'Scope','es'=>'Ámbito'],'readonly'=>true,'filter'=>'select'],
        'DSCTIPO'=>['label'=>['pt'=>'Tipo','en'=>'Type','es'=>'Tipo'],'readonly'=>true,'filter'=>'select'],
        'NR'=>['label'=>['pt'=>'Número','en'=>'Number','es'=>'Número'],'readonly'=>true,'filter'=>'select'],
        'ANO'=>['label'=>['pt'=>'Ano','en'=>'Year','es'=>'Año'],'readonly'=>true,'filter'=>'select'],
        'DSCASSUNTO'=>['label'=>['pt'=>'Assunto','en'=>'Subject','es'=>'Asunto'],'readonly'=>true,'filter'=>'text'],
        'ARTIGOS'=>['label'=>['pt'=>'Artigos','en'=>'Articles','es'=>'Artículos'],'readonly'=>true,'filter'=>'text'],
        'MEIO_AMBIENTE'=>['label'=>['pt'=>'Meio Ambiente','en'=>'Environment','es'=>'Medio ambiente'],'readonly'=>true,'filter'=>'select'],
        'SEGURANCA'=>['label'=>['pt'=>'Segurança','en'=>'Safety','es'=>'Seguridad'],'readonly'=>true,'filter'=>'select'],
        'QUALIDADE'=>['label'=>['pt'=>'Qualidade','en'=>'Quality','es'=>'Calidad'],'readonly'=>true,'filter'=>'select'],
        'RESPOSABILIDADE'=>['label'=>['pt'=>'Social','en'=>'Social','es'=>'Social'],'readonly'=>true,'filter'=>'select'],
        'QTD_PERIGOS'=>['label'=>['pt'=>'Perigos relacionados','en'=>'Related hazards','es'=>'Peligros relacionados'],'readonly'=>true],
        'QTD_ATIVIDADES'=>['label'=>['pt'=>'Atividades relacionadas','en'=>'Related activities','es'=>'Actividades relacionadas'],'readonly'=>true],
        'QTD_RISCOS'=>['label'=>['pt'=>'Riscos relacionados','en'=>'Related risks','es'=>'Riesgos relacionados'],'readonly'=>true],
        'QTD_LICENCAS'=>['label'=>['pt'=>'Licenças relacionadas','en'=>'Related licenses','es'=>'Licencias relacionadas'],'readonly'=>true],
        'QTD_DOCUMENTOS'=>['label'=>['pt'=>'Documentos relacionados','en'=>'Related documents','es'=>'Documentos relacionados'],'readonly'=>true],
        'CODUNIDADE'=>['label'=>['pt'=>'Código da unidade','en'=>'Unit code','es'=>'Código de unidad'],'readonly'=>true,'hidden'=>true,'filter'=>'none'],
    ],
]);
