<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/ManutencaoLegalCadastroGrid.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';
// Dicas dos botões em Administração › Textos e idiomas (cadastro automático ao abrir como ADMINISTRADOR).
$tt = static fn(string $chave, array $padrao): string => htmlspecialchars(IdiomaService::t($chave, $padrao), ENT_QUOTES, 'UTF-8');

ManutencaoLegalCadastroGrid::render([
    'menu' => 'CORRELACIONAMENTO_SOCIAL',
    'title' => ['pt' => 'Correlacionamento Social', 'en' => 'Social Correlation', 'es' => 'Correlacionamiento social'],
    'transaction' => 'modulolegal_correlacionamentosocial',
    'fragment_class' => 'modulolegal-correlacionamento-social-fragment',
    'pdf_transaction' => 'CORRELACIONAMENTO_SOCIAL',
    'database' => 'gmodelo_dbp',
    'prefix' => 'GPT',
    'table' => 'legislacaoxempresa',
    'view' => 'vw_modulolegal_correlacionamento_social_grid',
    'primary' => 'CODLEGISLACAO',
    'read_only_grid' => true,
    'button_options' => ['save' => false, 'new' => false, 'duplicate' => false, 'delete' => false, 'reload' => true, 'export' => true, 'columns' => true],
    'extras' => '<button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child data-context-url="/principal/modulolegal_correlacionamentosocial_aspectos.php" data-context-fields="CODLEGISLACAO,CODUNIDADE" data-context-title="' . $tt('modulolegal_correlacionamentosocial.botao.aspectos', ['pt' => 'Aspectos sociais', 'en' => 'Social aspects', 'es' => 'Aspectos sociales']) . '" disabled title="' . $tt('modulolegal_correlacionamentosocial.botao.aspectos', ['pt' => 'Aspectos sociais', 'en' => 'Social aspects', 'es' => 'Aspectos sociales']) . '" aria-label="' . $tt('modulolegal_correlacionamentosocial.botao.aspectos', ['pt' => 'Aspectos sociais', 'en' => 'Social aspects', 'es' => 'Aspectos sociales']) . '"><i class="bi bi-people"></i></button><button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child data-context-url="/principal/modulolegal_correlacionamentosocial_impactos.php" data-context-fields="CODLEGISLACAO,CODUNIDADE" data-context-title="' . $tt('modulolegal_correlacionamentosocial.botao.impactos', ['pt' => 'Impactos sociais', 'en' => 'Social impacts', 'es' => 'Impactos sociales']) . '" disabled title="' . $tt('modulolegal_correlacionamentosocial.botao.impactos', ['pt' => 'Impactos sociais', 'en' => 'Social impacts', 'es' => 'Impactos sociales']) . '" aria-label="' . $tt('modulolegal_correlacionamentosocial.botao.impactos', ['pt' => 'Impactos sociais', 'en' => 'Social impacts', 'es' => 'Impactos sociales']) . '"><i class="bi bi-graph-up-arrow"></i></button><button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child data-context-url="/principal/modulolegal_correlacionamentosocial_documentos.php" data-context-fields="CODLEGISLACAO,CODUNIDADE" data-context-title="' . $tt('modulolegal_correlacionamentosocial.botao.documentos', ['pt' => 'Documentos relacionados', 'en' => 'Related documents', 'es' => 'Documentos relacionados']) . '" disabled title="' . $tt('modulolegal_correlacionamentosocial.botao.documentos', ['pt' => 'Documentos relacionados', 'en' => 'Related documents', 'es' => 'Documentos relacionados']) . '" aria-label="' . $tt('modulolegal_correlacionamentosocial.botao.documentos', ['pt' => 'Documentos relacionados', 'en' => 'Related documents', 'es' => 'Documentos relacionados']) . '"><i class="bi bi-file-earmark-text"></i></button>',
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
        'QTD_ASPECTOS_SOCIAIS' => ['label' => ['pt' => 'Aspectos sociais relacionados', 'en' => 'Related social aspects', 'es' => 'Aspectos sociales relacionados'], 'readonly' => true, 'filter' => 'text'],
        'QTD_IMPACTOS_SOCIAIS' => ['label' => ['pt' => 'Impactos sociais relacionados', 'en' => 'Related social impacts', 'es' => 'Impactos sociales relacionados'], 'readonly' => true, 'filter' => 'text'],
        'QTD_DOCUMENTOS' => ['label' => ['pt' => 'Documentos relacionados', 'en' => 'Related documents', 'es' => 'Documentos relacionados'], 'readonly' => true, 'filter' => 'text'],
        'CODUNIDADE' => ['label' => ['pt' => 'Código da unidade', 'en' => 'Unit code', 'es' => 'Código de unidad'], 'readonly' => true, 'hidden' => true, 'filter' => 'none'],
    ],
]);
