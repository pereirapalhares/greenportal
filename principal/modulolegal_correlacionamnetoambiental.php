<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
// Dicas dos botões em Administração › Textos e idiomas (cadastro automático ao abrir como ADMINISTRADOR).
$tt = static fn(string $chave, array $padrao): string => htmlspecialchars(IdiomaService::t($chave, $padrao), ENT_QUOTES, 'UTF-8');

ManutencaoLegalCadastroGrid::render([
    'menu' => 'CORRELACIONAMENTO_AMBIENTAL',
    'title' => ['pt' => 'Correlacionamento Ambiental', 'en' => 'Environmental Correlation', 'es' => 'Correlacionamiento ambiental'],
    'transaction' => 'modulolegal_correlacionamnetoambiental',
    'fragment_class' => 'modulolegal-correlacionamento-ambiental-fragment',
    'pdf_transaction' => 'CORRELACIONAMENTO_AMBIENTAL',
    'database' => 'gmodelo_dbp',
    'prefix' => 'GPT',
    'table' => 'legislacaoxempresa',
    'view' => 'vw_modulolegal_correlacionamento_ambiental_grid',
    'primary' => 'CODLEGISLACAO',
    'legacy_order' => ['DSCUNIDADE', 'CODLEGISLACAO', 'NIVEL_DE_CONTROLE', 'CODIGO', 'DSCAMBITO', 'DSCTIPO', 'NR', 'ANO', 'DSCASSUNTO', 'ARTIGOS', 'MEIO_AMBIENTE', 'SEGURANCA', 'QUALIDADE', 'RESPOSABILIDADE', 'CODUNIDADE'],
    'button_options' => ['save' => false, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true],
    'extras' => '<button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child data-context-url="/principal/modulolegal_correlacionamentoambiental_aspectos.php" data-context-fields="CODLEGISLACAO,CODUNIDADE" data-context-title="' . $tt('modulolegal_correlacionamnetoambiental.botao.aspectos', ['pt' => 'Aspectos', 'en' => 'Aspects', 'es' => 'Aspectos']) . '" disabled title="' . $tt('modulolegal_correlacionamnetoambiental.botao.aspectos', ['pt' => 'Aspectos', 'en' => 'Aspects', 'es' => 'Aspectos']) . '" aria-label="' . $tt('modulolegal_correlacionamnetoambiental.botao.aspectos', ['pt' => 'Aspectos', 'en' => 'Aspects', 'es' => 'Aspectos']) . '"><i class="bi bi-diagram-3"></i></button><button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child data-context-url="/principal/modulolegal_correlacionamentoambiental_atividades.php" data-context-fields="CODLEGISLACAO,CODUNIDADE" data-context-title="' . $tt('modulolegal_correlacionamnetoambiental.botao.atividades', ['pt' => 'Atividades', 'en' => 'Activities', 'es' => 'Actividades']) . '" disabled title="' . $tt('modulolegal_correlacionamnetoambiental.botao.atividades', ['pt' => 'Atividades', 'en' => 'Activities', 'es' => 'Actividades']) . '" aria-label="' . $tt('modulolegal_correlacionamnetoambiental.botao.atividades', ['pt' => 'Atividades', 'en' => 'Activities', 'es' => 'Actividades']) . '"><i class="bi bi-list-check"></i></button><button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child data-context-url="/principal/modulolegal_correlacionamentoambiental_impactos.php" data-context-fields="CODLEGISLACAO,CODUNIDADE" data-context-title="' . $tt('modulolegal_correlacionamnetoambiental.botao.impactos', ['pt' => 'Impactos', 'en' => 'Impacts', 'es' => 'Impactos']) . '" disabled title="' . $tt('modulolegal_correlacionamnetoambiental.botao.impactos', ['pt' => 'Impactos', 'en' => 'Impacts', 'es' => 'Impactos']) . '" aria-label="' . $tt('modulolegal_correlacionamnetoambiental.botao.impactos', ['pt' => 'Impactos', 'en' => 'Impacts', 'es' => 'Impactos']) . '"><i class="bi bi-graph-up-arrow"></i></button><button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child data-context-url="/principal/modulolegal_correlacionamentoambiental_licencas.php" data-context-fields="CODLEGISLACAO,CODUNIDADE" data-context-title="' . $tt('modulolegal_correlacionamnetoambiental.botao.licencas', ['pt' => 'Licenças', 'en' => 'Licenses', 'es' => 'Licencias']) . '" disabled title="' . $tt('modulolegal_correlacionamnetoambiental.botao.licencas', ['pt' => 'Licenças', 'en' => 'Licenses', 'es' => 'Licencias']) . '" aria-label="' . $tt('modulolegal_correlacionamnetoambiental.botao.licencas', ['pt' => 'Licenças', 'en' => 'Licenses', 'es' => 'Licencias']) . '"><i class="bi bi-file-earmark-check"></i></button><button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child data-context-url="/principal/modulolegal_correlacionamentoambiental_documentos.php" data-context-fields="CODLEGISLACAO,CODUNIDADE" data-context-title="' . $tt('modulolegal_correlacionamnetoambiental.botao.documentos', ['pt' => 'Documentos', 'en' => 'Documents', 'es' => 'Documentos']) . '" disabled title="' . $tt('modulolegal_correlacionamnetoambiental.botao.documentos', ['pt' => 'Documentos', 'en' => 'Documents', 'es' => 'Documentos']) . '" aria-label="' . $tt('modulolegal_correlacionamnetoambiental.botao.documentos', ['pt' => 'Documentos', 'en' => 'Documents', 'es' => 'Documentos']) . '"><i class="bi bi-file-earmark-text"></i></button>',
    'fields' => [
        'CODLEGISLACAO' => ['label' => ['pt' => 'Código da legislação', 'en' => 'Legislation code', 'es' => 'Código de legislación'], 'readonly' => true, 'hidden' => true, 'filter' => 'none'],
        'DSCUNIDADE' => ['label' => ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'], 'readonly' => true, 'filter' => 'select'],
        'NIVEL_DE_CONTROLE' => ['label' => ['pt' => 'Nível de controle', 'en' => 'Control level', 'es' => 'Nivel de control'], 'readonly' => true, 'filter' => 'select'],
        'CODIGO' => ['label' => ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código'], 'readonly' => true, 'filter' => 'text'],
        'DSCAMBITO' => ['label' => ['pt' => 'Âmbito', 'en' => 'Scope', 'es' => 'Ámbito'], 'readonly' => true, 'filter' => 'select'],
        'DSCTIPO' => ['label' => ['pt' => 'Tipo', 'en' => 'Type', 'es' => 'Tipo'], 'readonly' => true, 'filter' => 'select'],
        'NR' => ['label' => ['pt' => 'Número', 'en' => 'Number', 'es' => 'Número'], 'readonly' => true, 'filter' => 'select'],
        'ANO' => ['label' => ['pt' => 'Ano', 'en' => 'Year', 'es' => 'Año'], 'readonly' => true, 'filter' => 'select'],
        'DSCASSUNTO' => ['label' => ['pt' => 'Assunto', 'en' => 'Subject', 'es' => 'Asunto'], 'readonly' => true, 'filter' => 'text'],
        'ARTIGOS' => ['label' => ['pt' => 'Artigos', 'en' => 'Articles', 'es' => 'Artículos'], 'readonly' => true, 'filter' => 'text'],
        'MEIO_AMBIENTE' => ['label' => ['pt' => 'Meio Ambiente', 'en' => 'Environment', 'es' => 'Medio ambiente'], 'readonly' => true, 'filter' => 'select'],
        'SEGURANCA' => ['label' => ['pt' => 'Segurança', 'en' => 'Safety', 'es' => 'Seguridad'], 'readonly' => true, 'filter' => 'select'],
        'QUALIDADE' => ['label' => ['pt' => 'Qualidade', 'en' => 'Quality', 'es' => 'Calidad'], 'readonly' => true, 'filter' => 'select'],
        'RESPOSABILIDADE' => ['label' => ['pt' => 'Social', 'en' => 'Social', 'es' => 'Social'], 'readonly' => true, 'filter' => 'select'],
        'CODUNIDADE' => ['label' => ['pt' => 'Código da unidade', 'en' => 'Unit code', 'es' => 'Código de unidad'], 'readonly' => true, 'hidden' => true, 'filter' => 'none'],
    ],
]);
