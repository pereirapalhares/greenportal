<?php
declare(strict_types=1);

/*
 * Pesquisa de documentos (busca universal) — menu BUSCA_DOCUMENTOS, também aberta pelo campo de pesquisa do cabeçalho.
 * Fase 1: cadastros e nomes de arquivos de Licenças, Controles, Requisitos legais, Treinamentos, Infraestrutura e Portaria
 * (fornecedores, funcionários e treinamentos), em gatlas_dbp e gmodelo_dbp.
 * Dados: principal/api/busca_documentos.php → CALL sp_busca_pesquisar (migração 264), com as permissões do usuário.
 * Índice atualizado pelo serviço BUSCA_WORKER (scripts/busca-worker.php) a cada 10 minutos.
 * Tela: pesquisa + Avançada, Refinar por (tipo, formato, situação, unidade), grid com menu de contexto
 * (Abrir na transação, Visualizar, Arquivos, Download, Propriedades, Copiar link) e paginação.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/TenantContext.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: text/html; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$falhar = static function (int $codigo, string $mensagem): never { http_response_code($codigo); echo htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8'); exit; };
try {
    $pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
    if (!UsuarioSessao::atualizar($pdo, $_SESSION)) throw new RuntimeException('sessao');
} catch (Throwable) {
    $falhar(401, IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.']));
}
if (empty(PermissaoTransacao::obter('BUSCA_DOCUMENTOS')['pode_visualizar'])) $falhar(403, IdiomaService::t('comum.erro.sem_permissao', ['pt' => 'Seu perfil não tem permissão para acessar esta transação.', 'en' => 'Your profile is not allowed to access this transaction.', 'es' => 'Su perfil no tiene permiso para acceder a esta transacción.']));

$podeDicionario = !empty(PermissaoTransacao::obter('BUSCA_DICIONARIO')['pode_visualizar']);
$podeExportar = !empty(PermissaoTransacao::obter('BUSCA_DOCUMENTOS')['pode_exportar_excel']);   // permissão "Exportar Excel" do perfil
$t = static fn(string $chave, array $padrao): string => IdiomaService::t('busca_documentos.' . $chave, $padrao);
// Migração 346: busca pelo sentido (só aparece a chave quando a empresa tem os vetores e a IA está configurada)
require_once dirname(__DIR__) . '/classes/BuscaSemantica.php';
$temSentido = false;
try { $temSentido = BuscaSemantica::disponivel($pdo) && (new BuscaSemantica($pdo))->configurada(); } catch (Throwable) { $temSentido = false; }
$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$T = [
    'titulo' => $t('titulo', ['pt' => 'Pesquisa de documentos', 'en' => 'Document search', 'es' => 'Búsqueda de documentos']),
    'placeholder' => $t('placeholder', ['pt' => 'Pesquisar licenças, controles, requisitos, documentos de fornecedores e funcionários…', 'en' => 'Search licenses, controls, requirements, supplier and employee documents…', 'es' => 'Buscar licencias, controles, requisitos, documentos de proveedores y empleados…']),
    'pesquisar' => $t('pesquisar', ['pt' => 'Pesquisar', 'en' => 'Search', 'es' => 'Buscar']),
    'avancada' => $t('avancada', ['pt' => 'Avançada', 'en' => 'Advanced', 'es' => 'Avanzada']),
    'sentido_chave' => $t('sentido_chave', ['pt' => 'Também pelo sentido', 'en' => 'Also by meaning', 'es' => 'También por sentido']),
    'sentido_chave_dica' => $t('sentido_chave_dica', ['pt' => 'Depois dos resultados pelas palavras, mostra os itens parecidos pelo sentido (mesmo sem as palavras digitadas)', 'en' => 'After the word results, shows items similar in meaning (even without the typed words)', 'es' => 'Después de los resultados por palabras, muestra los ítems parecidos por el sentido']),
    'sentido_titulo' => $t('sentido_titulo', ['pt' => 'Também pelo sentido: parecidos com "{q}", sem as mesmas palavras', 'en' => 'Also by meaning: similar to "{q}", without the same words', 'es' => 'También por sentido: parecidos a "{q}", sin las mismas palabras']),
    'sentido_so' => $t('sentido_so', ['pt' => 'Nenhum resultado com essas palavras. Veja abaixo os parecidos pelo sentido.', 'en' => 'No results with these words. See the items similar in meaning below.', 'es' => 'Ningún resultado con estas palabras. Vea abajo los parecidos por el sentido.']),
    'sentido_pct' => $t('sentido_pct', ['pt' => 'por sentido {n}%', 'en' => 'by meaning {n}%', 'es' => 'por sentido {n}%']),
    'sentido_dica' => $t('sentido_dica', ['pt' => 'Encontrado pelo sentido do texto (semelhança com a pesquisa), não pelas palavras', 'en' => 'Found by the meaning of the text (similarity), not by the words', 'es' => 'Encontrado por el sentido del texto, no por las palabras']),
    'nos_resultados' => $t('nos_resultados', ['pt' => 'Pesquisar nos resultados atuais', 'en' => 'Search within current results', 'es' => 'Buscar en los resultados actuales']),
    'buscar_resultados' => $t('buscar_resultados', ['pt' => 'Buscar nos resultados…', 'en' => 'Search in results…', 'es' => 'Buscar en los resultados…']),
    'limpar' => $t('limpar', ['pt' => 'Limpar', 'en' => 'Clear', 'es' => 'Limpiar']),
    'nova' => $t('nova', ['pt' => 'Nova pesquisa', 'en' => 'New search', 'es' => 'Nueva búsqueda']),
    'refinar' => $t('refinar', ['pt' => 'Refinar por', 'en' => 'Refine by', 'es' => 'Refinar por']),
    'f_tipos' => $t('faceta.tipos', ['pt' => 'Tipo documental', 'en' => 'Document type', 'es' => 'Tipo documental']),
    'f_formatos' => $t('faceta.formatos', ['pt' => 'Formato', 'en' => 'Format', 'es' => 'Formato']),
    'f_situacoes' => $t('faceta.situacoes', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación']),
    'f_unidades' => $t('faceta.unidades', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']),
    'c_nome' => $t('coluna.nome', ['pt' => 'Nome', 'en' => 'Name', 'es' => 'Nombre']),
    'c_titulo' => $t('coluna.titulo', ['pt' => 'Título', 'en' => 'Title', 'es' => 'Título']),
    'c_tipo' => $t('coluna.tipo', ['pt' => 'Tipo', 'en' => 'Type', 'es' => 'Tipo']),
    'c_situacao' => $t('coluna.situacao', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación']),
    'c_unidade' => $t('coluna.unidade', ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']),
    'c_responsavel' => $t('coluna.responsavel', ['pt' => 'Responsável', 'en' => 'Responsible', 'es' => 'Responsable']),
    'exportar' => $t('exportar', ['pt' => 'Excel', 'en' => 'Excel', 'es' => 'Excel']),
    'exportar_titulo' => $t('exportar_planilha', ['pt' => 'Exportar os resultados para o Excel (a planilha chega nos alertas)', 'en' => 'Export the results to Excel (the spreadsheet arrives in the alerts)', 'es' => 'Exportar los resultados a Excel (la planilla llega en las alertas)']),
    'exportar_pedido' => IdiomaService::t('comum.msg.exportacao_pedido', ['pt' => 'A exportação foi solicitada e será disponibilizada nos alertas.', 'en' => 'The export was requested and will be available in the alerts.', 'es' => 'La exportación fue solicitada y estará disponible en las alertas.']),
    'exportando' => $t('exportando', ['pt' => 'Gerando a planilha…', 'en' => 'Generating the spreadsheet…', 'es' => 'Generando la planilla…']),
    'encontrado_em' => $t('encontrado_em', ['pt' => 'Encontrado em', 'en' => 'Found in', 'es' => 'Encontrado en']),
    'c_validade' => $t('coluna.validade', ['pt' => 'Validade', 'en' => 'Expiration', 'es' => 'Validez']),
    'c_modificado' => $t('coluna.modificado', ['pt' => 'Modificado', 'en' => 'Modified', 'es' => 'Modificado']),
    'm_abrir' => $t('menu.abrir', ['pt' => 'Abrir', 'en' => 'Open', 'es' => 'Abrir']),
    'm_visualizar' => $t('menu.visualizar', ['pt' => 'Visualizar', 'en' => 'View', 'es' => 'Ver']),
    'm_arquivos' => $t('menu.arquivos', ['pt' => 'Arquivos do registro', 'en' => 'Record files', 'es' => 'Archivos del registro']),
    'm_download' => $t('menu.download', ['pt' => 'Download', 'en' => 'Download', 'es' => 'Descargar']),
    'm_propriedades' => $t('menu.propriedades', ['pt' => 'Propriedades', 'en' => 'Properties', 'es' => 'Propiedades']),
    'm_copiar_link' => $t('menu.copiar_link', ['pt' => 'Copiar link', 'en' => 'Copy link', 'es' => 'Copiar enlace']),
    'm_copiar_nome' => $t('menu.copiar_nome', ['pt' => 'Copiar nome', 'en' => 'Copy name', 'es' => 'Copiar nombre']),
    'copiado' => $t('copiado', ['pt' => 'Copiado.', 'en' => 'Copied.', 'es' => 'Copiado.']),
    'a_item' => $t('avancada.item', ['pt' => 'Mostrar', 'en' => 'Show', 'es' => 'Mostrar']),
    'a_todos' => $t('avancada.todos', ['pt' => 'Registros e arquivos', 'en' => 'Records and files', 'es' => 'Registros y archivos']),
    'a_registros' => $t('avancada.registros', ['pt' => 'Somente registros', 'en' => 'Records only', 'es' => 'Solo registros']),
    'a_arquivos' => $t('avancada.arquivos', ['pt' => 'Somente arquivos', 'en' => 'Files only', 'es' => 'Solo archivos']),
    'a_validade' => $t('avancada.validade', ['pt' => 'Validade até', 'en' => 'Expiring by', 'es' => 'Validez hasta']),
    'a_ordem' => $t('avancada.ordem', ['pt' => 'Ordenar por', 'en' => 'Sort by', 'es' => 'Ordenar por']),
    'o_relevancia' => $t('ordem.relevancia', ['pt' => 'Relevância', 'en' => 'Relevance', 'es' => 'Relevancia']),
    'o_nome' => $t('ordem.nome', ['pt' => 'Nome', 'en' => 'Name', 'es' => 'Nombre']),
    'o_validade' => $t('ordem.validade', ['pt' => 'Validade', 'en' => 'Expiration', 'es' => 'Validez']),
    'o_modificado' => $t('ordem.modificado', ['pt' => 'Modificação do arquivo', 'en' => 'File modification', 'es' => 'Modificación del archivo']),
    'aplicar' => $t('aplicar', ['pt' => 'Aplicar', 'en' => 'Apply', 'es' => 'Aplicar']),
    'chip_texto' => $t('chip.texto', ['pt' => 'Texto', 'en' => 'Text', 'es' => 'Texto']),
    'registros' => $t('registros', ['pt' => '{de}–{ate} de {total} resultados', 'en' => '{de}–{ate} of {total} results', 'es' => '{de}–{ate} de {total} resultados']),
    'nenhum' => $t('nenhum', ['pt' => 'Nenhum resultado. Confira a grafia, use menos palavras ou retire filtros.', 'en' => 'No results. Check the spelling, use fewer words or remove filters.', 'es' => 'Sin resultados. Revise la ortografía, use menos palabras o quite filtros.']),
    'inicio' => $t('inicio', ['pt' => 'Digite o que procura e clique em Pesquisar. Sem texto, a pesquisa lista tudo o que você pode ver.', 'en' => 'Type what you are looking for and click Search. Without text, the search lists everything you can see.', 'es' => 'Escriba lo que busca y haga clic en Buscar. Sin texto, la búsqueda lista todo lo que puede ver.']),
    'inicio_vazio' => $t('inicio_vazio', ['pt' => 'Digite o que procura e clique em Pesquisar, ou use a pesquisa Avançada.', 'en' => 'Type what you are looking for and click Search, or use the Advanced search.', 'es' => 'Escriba lo que busca y haga clic en Buscar, o use la búsqueda Avanzada.']),
    'sem_criterio' => $t('sem_criterio', ['pt' => 'Informe um texto ou escolha um filtro da pesquisa Avançada.', 'en' => 'Enter a text or choose an Advanced search filter.', 'es' => 'Escriba un texto o elija un filtro de la búsqueda Avanzada.']),
    'pag_anterior' => IdiomaService::t('comum.paginacao.anterior', ['pt' => 'Anterior', 'en' => 'Previous', 'es' => 'Anterior']),
    'pag_proxima' => IdiomaService::t('comum.paginacao.proxima', ['pt' => 'Próxima', 'en' => 'Next', 'es' => 'Siguiente']),
    'pag_pagina' => IdiomaService::t('comum.paginacao.pagina', ['pt' => 'Página', 'en' => 'Page', 'es' => 'Página']),
    'pag_de' => IdiomaService::t('comum.paginacao.de', ['pt' => 'de', 'en' => 'of', 'es' => 'de']),
    'pag_registros' => IdiomaService::t('comum.paginacao.registros', ['pt' => 'registros', 'en' => 'records', 'es' => 'registros']),
    'ordenar' => IdiomaService::t('comum.grid.ordenar', ['pt' => 'Clique para ordenar', 'en' => 'Click to sort', 'es' => 'Haga clic para ordenar']),
    'carregando' => $t('carregando', ['pt' => 'Pesquisando…', 'en' => 'Searching…', 'es' => 'Buscando…']),
    'indice' => $t('indice', ['pt' => 'Índice atualizado em {data}', 'en' => 'Index updated on {data}', 'es' => 'Índice actualizado el {data}']),
    'indice_vazio' => $t('indice_vazio', ['pt' => 'O índice ainda não foi gerado. Aguarde a próxima execução do serviço de indexação (a cada 10 minutos).', 'en' => 'The index has not been built yet. Wait for the next run of the indexing service (every 10 minutes).', 'es' => 'El índice aún no se generó. Espere la próxima ejecución del servicio de indexación (cada 10 minutos).']),
    'registro' => $t('registro', ['pt' => 'Registro', 'en' => 'Record', 'es' => 'Registro']),
    'sem_situacao' => $t('sem_situacao', ['pt' => '(sem situação)', 'en' => '(no status)', 'es' => '(sin situación)']),
    'sem_unidade' => $t('sem_unidade', ['pt' => '(sem unidade)', 'en' => '(no unit)', 'es' => '(sin unidad)']),
    'mais_acoes' => $t('mais_acoes', ['pt' => 'Ações', 'en' => 'Actions', 'es' => 'Acciones']),
    'p_chave' => $t('prop.chave', ['pt' => 'Código', 'en' => 'Code', 'es' => 'Código']),
    'p_descricao' => $t('prop.descricao', ['pt' => 'Descrição', 'en' => 'Description', 'es' => 'Descripción']),
    'p_tamanho' => $t('prop.tamanho', ['pt' => 'Tamanho', 'en' => 'Size', 'es' => 'Tamaño']),
    'p_indexado' => $t('prop.indexado', ['pt' => 'Indexado em', 'en' => 'Indexed on', 'es' => 'Indexado el']),
    'p_transacao' => $t('prop.transacao', ['pt' => 'Transação', 'en' => 'Transaction', 'es' => 'Transacción']),
    'fechar' => $t('fechar', ['pt' => 'Fechar', 'en' => 'Close', 'es' => 'Cerrar']),
    'tipo.licencas' => $t('tipo.licencas', ['pt' => 'Licença', 'en' => 'License', 'es' => 'Licencia']),
    'tipo.controles' => $t('tipo.controles', ['pt' => 'Controle de documento', 'en' => 'Document control', 'es' => 'Control de documento']),
    'tipo.requisitos' => $t('tipo.requisitos', ['pt' => 'Requisito legal', 'en' => 'Legal requirement', 'es' => 'Requisito legal']),
    'tipo.treinamentos' => $t('tipo.treinamentos', ['pt' => 'Treinamento', 'en' => 'Training', 'es' => 'Capacitación']),
    'tipo.infraestrutura' => $t('tipo.infraestrutura', ['pt' => 'Infraestrutura', 'en' => 'Infrastructure', 'es' => 'Infraestructura']),
    'tipo.fornecedorcriterios' => $t('tipo.fornecedorcriterios', ['pt' => 'Documento de fornecedor', 'en' => 'Supplier document', 'es' => 'Documento de proveedor']),
    'tipo.funcionariocriterios' => $t('tipo.funcionariocriterios', ['pt' => 'Documento de funcionário', 'en' => 'Employee document', 'es' => 'Documento de empleado']),
    'tipo.fornecedor' => $t('tipo.fornecedor', ['pt' => 'Fornecedor', 'en' => 'Supplier', 'es' => 'Proveedor']),
    'tipo.funcionario' => $t('tipo.funcionario', ['pt' => 'Funcionário', 'en' => 'Employee', 'es' => 'Empleado']),
    'r_vinculos' => $t('grupo.r_vinculos', ['pt' => '{n}', 'en' => '{n}', 'es' => '{n}']),
    'dicionario' => $t('dicionario.abrir', ['pt' => 'Dicionário da pesquisa', 'en' => 'Search dictionary', 'es' => 'Diccionario de la búsqueda']),
    'dic_resultados' => $t('dicionario.resultados', ['pt' => 'Resultados também para', 'en' => 'Results also for', 'es' => 'Resultados también para']),
    'dic_literal' => $t('dicionario.literal', ['pt' => 'Pesquisar só o texto "{q}"', 'en' => 'Search only the text "{q}"', 'es' => 'Buscar solo el texto "{q}"']),
    'dic_literal_ativo' => $t('dicionario.literal_ativo', ['pt' => 'Pesquisando só o texto digitado, sem o dicionário.', 'en' => 'Searching only the typed text, without the dictionary.', 'es' => 'Buscando solo el texto escrito, sin el diccionario.']),
    'dic_usar' => $t('dicionario.usar', ['pt' => 'Usar o dicionário', 'en' => 'Use the dictionary', 'es' => 'Usar el diccionario']),
    'chip_negocio' => $t('chip.negocio', ['pt' => 'Negócio', 'en' => 'Business item', 'es' => 'Negocio']),
    'm_ver_pesquisa' => $t('menu.ver_pesquisa', ['pt' => 'Ver na pesquisa (com os vínculos dele)', 'en' => 'See in the search (with its links)', 'es' => 'Ver en la búsqueda (con sus vínculos)']),
    'r_documentos' => $t('grupo.r_documentos', ['pt' => '{n} documento(s)', 'en' => '{n} document(s)', 'es' => '{n} documento(s)']),
    'r_arquivos' => $t('grupo.r_arquivos', ['pt' => '{n} arquivo(s)', 'en' => '{n} file(s)', 'es' => '{n} archivo(s)']),
    'r_pendentes' => $t('grupo.r_pendentes', ['pt' => '{n} pendente(s)', 'en' => '{n} pending', 'es' => '{n} pendiente(s)']),
    'r_a_vencer' => $t('grupo.r_a_vencer', ['pt' => '{n} a vencer', 'en' => '{n} expiring', 'es' => '{n} por vencer']),
    'r_em_dia' => $t('grupo.r_em_dia', ['pt' => 'Em dia', 'en' => 'Up to date', 'es' => 'Al día']),
    's_arquivos' => $t('grupo.s_arquivos', ['pt' => 'Arquivos ({n})', 'en' => 'Files ({n})', 'es' => 'Archivos ({n})']),
    's_documentos' => $t('grupo.s_documentos', ['pt' => 'Documentos e treinamentos ({n})', 'en' => 'Documents and training ({n})', 'es' => 'Documentos y capacitaciones ({n})']),
    'mais_arquivos' => $t('grupo.mais_arquivos', ['pt' => 'Ver todos os {n} arquivos', 'en' => 'See all {n} files', 'es' => 'Ver los {n} archivos']),
    'mais_vinculos' => $t('grupo.mais_vinculos', ['pt' => 'Ver todos os {n} na transação', 'en' => 'See all {n} in the transaction', 'es' => 'Ver los {n} en la transacción']),
    'mais_abrir' => $t('grupo.mais_abrir', ['pt' => 'Abrir para ver todos', 'en' => 'Open to see all', 'es' => 'Abrir para ver todos']),
    'expandir' => $t('grupo.expandir', ['pt' => 'Mostrar ou ocultar o conteúdo', 'en' => 'Show or hide the content', 'es' => 'Mostrar u ocultar el contenido']),
    'expandir_todos' => $t('grupo.expandir_todos', ['pt' => 'Expandir tudo', 'en' => 'Expand all', 'es' => 'Expandir todo']),
    'recolher_todos' => $t('grupo.recolher_todos', ['pt' => 'Recolher tudo', 'en' => 'Collapse all', 'es' => 'Contraer todo']),
    'permissoes_aviso' => $t('rodape.permissoes', ['pt' => 'Resultados conforme as permissões do seu perfil', 'en' => 'Results according to your profile permissions', 'es' => 'Resultados según los permisos de su perfil']),
    'permissoes_ajuda' => $t('rodape.permissoes_ajuda', ['pt' => 'Por que não encontro um documento?', 'en' => "Why can't I find a document?", 'es' => '¿Por qué no encuentro un documento?']),
    'tipo.funcionariotreinamentos' => $t('tipo.funcionariotreinamentos', ['pt' => 'Treinamento de funcionário', 'en' => 'Employee training', 'es' => 'Capacitación de empleado']),
];
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 500);
?><!doctype html>
<html lang="<?= IdiomaService::idioma() === 'pt' ? 'pt-BR' : $h(IdiomaService::idioma()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($T['titulo']) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2">
<link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2">
<link rel="stylesheet" href="../assets/css/app.css?v=5">
<link rel="stylesheet" href="../assets/css/main.css?v=18">
<link rel="stylesheet" href="../assets/css/portaria-documentacao-fornecedor.css?v=5">
<link rel="stylesheet" href="../assets/css/busca-documentos.css?v=11">
<?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?>
</head>
<body class="busca-pagina">
<main class="busca" data-busca>
    <form class="busca-topo" data-busca-form role="search">
        <div class="busca-campo">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" class="form-control" name="q" value="<?= $h($q) ?>" placeholder="<?= $h($T['placeholder']) ?>" aria-label="<?= $h($T['titulo']) ?>" autocomplete="off" data-busca-q>
        </div>
        <button type="submit" class="btn btn-primary busca-btn-pesquisar"><?= $h($T['pesquisar']) ?></button>
        <?php if ($temSentido): ?><label class="busca-sentido-chave form-check form-switch" title="<?= $h($T['sentido_chave_dica']) ?>"><input class="form-check-input" type="checkbox" role="switch" data-busca-sentido checked> <span class="form-check-label"><i class="bi bi-stars" aria-hidden="true"></i> <?= $h($T['sentido_chave']) ?></span></label><?php endif; ?>
        <button type="button" class="btn btn-outline-secondary" data-busca-avancada aria-expanded="false"><i class="bi bi-sliders" aria-hidden="true"></i> <?= $h($T['avancada']) ?></button>
        <?php if ($podeExportar): ?><button type="button" class="btn btn-outline-success" data-busca-exportar title="<?= $h($T['exportar_titulo']) ?>" disabled><i class="bi bi-file-earmark-excel" aria-hidden="true"></i> <?= $h($T['exportar']) ?></button><?php endif; ?>
        <?php if ($podeDicionario): ?><button type="button" class="btn btn-outline-secondary" data-busca-abrir-dicionario title="<?= $h($T['dicionario']) ?>" aria-label="<?= $h($T['dicionario']) ?>"><i class="bi bi-book" aria-hidden="true"></i></button><?php endif; ?>
        <button type="button" class="btn btn-outline-secondary" data-busca-limpar><i class="bi bi-eraser" aria-hidden="true"></i> <?= $h($T['limpar']) ?></button>
    </form>
    <div class="busca-avancada" data-busca-painel hidden>
        <label><?= $h($T['a_item']) ?>
            <select class="form-select form-select-sm" data-busca-item>
                <option value=""><?= $h($T['a_todos']) ?></option>
                <option value="REGISTRO"><?= $h($T['a_registros']) ?></option>
                <option value="ARQUIVO"><?= $h($T['a_arquivos']) ?></option>
            </select></label>
        <label><?= $h($T['a_validade']) ?> <input type="date" class="form-control form-control-sm" data-busca-validade></label>
        <label><?= $h($T['a_ordem']) ?>
            <select class="form-select form-select-sm" data-busca-ordem>
                <option value="relevancia"><?= $h($T['o_relevancia']) ?></option>
                <option value="nome"><?= $h($T['o_nome']) ?></option>
                <option value="validade"><?= $h($T['o_validade']) ?></option>
                <option value="modificado"><?= $h($T['o_modificado']) ?></option>
            </select></label>
        <button type="button" class="btn btn-sm btn-primary" data-busca-aplicar><?= $h($T['aplicar']) ?></button>
    </div>
    <div class="busca-dicionario" data-busca-dicionario hidden></div>
    <div class="busca-mensagem" data-busca-mensagem role="status" hidden></div>
    <div class="busca-chips" data-busca-chips hidden>
        <div class="busca-chips-lista" data-busca-chips-lista></div>
    </div>
    <div class="busca-corpo">
        <aside class="busca-refinar" aria-label="<?= $h($T['refinar']) ?>">
            <div class="busca-refinar-titulo"><i class="bi bi-funnel" aria-hidden="true"></i> <?= $h($T['refinar']) ?></div>
            <div data-busca-facetas></div>
        </aside>
        <section class="busca-resultados">
            <?php /* Grid no padrão da aplicação (menu-grid-scroll / menu-batch-grid / grid-pagination de main.css); paginação feita pelo banco. */ ?>
            <div class="menu-grid-scroll busca-grid-caixa">
                <table class="table table-sm menu-batch-grid busca-grid" data-busca-grid>
                    <thead><tr>
                        <?php /* Clique no título ordena (asc/desc, pelo banco); a borda direita arrasta para mudar a largura. */ foreach (['nome', 'titulo', 'tipo', 'situacao', 'unidade', 'responsavel', 'validade', 'modificado'] as $c): ?><th data-busca-col="<?= $c ?>" aria-sort="none"><div class="column-heading"><span class="busca-ordenar" role="button" tabindex="0" title="<?= $h($T['ordenar']) ?>"><?= $h($T['c_' . $c]) ?><i class="bi bi-arrow-down-up column-sort-icon" aria-hidden="true"></i></span></div><span class="column-resizer" data-busca-redimensionar aria-hidden="true"></span></th><?php endforeach; ?>
                        <th class="busca-col-acoes"><span class="visually-hidden"><?= $h($T['mais_acoes']) ?></span></th>
                    </tr></thead>
                    <tbody data-busca-linhas><tr><td colspan="9" class="busca-aviso"><?= $h($T['inicio_vazio']) ?></td></tr></tbody>
                </table>
            </div>
            <div class="busca-rodape">
                <span data-busca-contagem></span>
                <span class="busca-indice" data-busca-indice></span>
                <span class="busca-expandir-todos"><button type="button" class="btn btn-link btn-sm" data-busca-expandir-todos="1" hidden><i class="bi bi-arrows-expand" aria-hidden="true"></i> <?= $h($T['expandir_todos']) ?></button><button type="button" class="btn btn-link btn-sm" data-busca-expandir-todos="0" hidden><i class="bi bi-arrows-collapse" aria-hidden="true"></i> <?= $h($T['recolher_todos']) ?></button></span>
                <span class="busca-permissoes"><i class="bi bi-shield-lock" aria-hidden="true"></i> <?= $h($T['permissoes_aviso']) ?> · <button type="button" class="btn btn-link btn-sm busca-permissoes-ajuda" data-open-transacao-ajuda data-help-transaction="busca_documentos" title="<?= $h($T['permissoes_ajuda']) ?>"><?= $h($T['permissoes_ajuda']) ?></button></span>
                <nav class="grid-pagination busca-paginacao" data-busca-paginas aria-label="<?= $h($T['pag_pagina']) ?>" hidden><button type="button" class="btn btn-sm btn-outline-secondary grid-page-prev" data-busca-pag="-1" title="<?= $h($T['pag_anterior']) ?>" aria-label="<?= $h($T['pag_anterior']) ?>"><i class="bi bi-chevron-left"></i></button><span class="grid-page-status" data-busca-pag-status></span><button type="button" class="btn btn-sm btn-outline-secondary grid-page-next" data-busca-pag="1" title="<?= $h($T['pag_proxima']) ?>" aria-label="<?= $h($T['pag_proxima']) ?>"><i class="bi bi-chevron-right"></i></button></nav>
            </div>
        </section>
    </div>
    <ul class="busca-menu" data-busca-menu role="menu" hidden></ul>
</main>
<script>window.BUSCA_TEXTOS = <?= json_encode($T, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>; window.BUSCA_Q_INICIAL = <?= json_encode($q, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<script src="../assets/vendor/jquery/jquery-3.7.1.min.js?v=2"></script>
<script src="../assets/js/ajuda-transacao.js?v=2"></script>
<script src="../assets/js/busca-documentos.js?v=14"></script>
</body>
</html>
