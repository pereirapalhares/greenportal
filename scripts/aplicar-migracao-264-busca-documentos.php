<?php
declare(strict_types=1);

/*
 * Migração 264 — Pesquisa de Documentos (busca universal), Fase 1: cadastros e nomes de arquivos.
 * Pré-requisitos: migrações 260, 262 e 263 (views da Portaria e vw_portaria_usuario_unidades).
 *
 * Em gatlas_dbp e gmodelo_dbp:
 *  1. tabela busca_indice (FULLTEXT ngram), view vw_busca_fonte_registros, catálogo de relações busca_relacao, busca_vinculo e
 *     procedures sp_busca_* (database/264_busca_documentos.sql); relações iniciais Requisito legal ↔ Licença / Controle /
 *     Treinamento / Infraestrutura com as views vw_busca_rel_* (criadas aqui só se a tabela legislacaox* existir no banco);
 *  2. menu BUSCA_DOCUMENTOS (Pesquisa de documentos, /principal/busca_documentos.php) e Visualizar para TODOS os perfis;
 *  3. registra em registros_migracao (BUSCA_DOCUMENTOS_264).
 * No universal: menu (mesmo codmenu nos clientes), ajuda da tela e o serviço BUSCA_WORKER (tela Serviços).
 * Dicionário da pesquisa (database/264_busca_dicionario.sql) no universal (base comum + carga inicial de siglas do setor, como
 * SUGESTAO) e em cada empresa (termos da empresa); menu BUSCA_DICIONARIO (Dicionário da pesquisa, junto da Administração) com
 * acesso para ADMINISTRADOR e ADM_DA_EMPRESA.
 * Opção --indexar: roda a primeira indexação logo depois (o mesmo que scripts\busca-worker.php).
 *
 * Dados sempre por procedure (classes/MigracaoCrud.php: sp_crud_select / sp_crud_insert / sp_crud_update); só a estrutura
 * (tabelas, views, procedures) é feita por DDL direto.
 *
 * Outras empresas: --banco=gcrown_dbp (pode repetir) ou --todas (todas as empresas migradas). O serviço de indexação passa a incluir a empresa sozinho.
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-264-busca-documentos.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-264-busca-documentos.php --indexar
 * Pode ser executada mais de uma vez. Os usuários veem o menu no próximo login.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';

// Bancos: gatlas_dbp e gmodelo_dbp, mais os informados com --banco= (pode repetir), ex.: --banco=gcrown_dbp
const M264_BANCOS_PADRAO = ['gatlas_dbp', 'gmodelo_dbp'];
const M264_MENU = 'BUSCA_DOCUMENTOS';
const M264_URL = '/principal/busca_documentos.php';
const M264_MENU_DIC = 'BUSCA_DICIONARIO';
const M264_URL_DIC = '/principal/busca_dicionario.php';
const M264_PERFIS_DIC = ['ADMINISTRADOR', 'ADM_DA_EMPRESA'];
// Carga inicial da base comum (SUGESTAO, origem SETOR): termo, significado, sinônimos (;), contexto (tipos da pesquisa)
const M264_SST = 'funcionariocriterios,funcionariotreinamentos,treinamentos,requisitos,controles';
const M264_AMB = 'licencas,requisitos,controles';
const M264_GES = 'controles,requisitos';
const M264_DICIONARIO = [
    ['ASO', 'Atestado de Saúde Ocupacional', 'exame ocupacional', 'funcionariocriterios,funcionariotreinamentos,requisitos'],
    ['PCMSO', 'Programa de Controle Médico de Saúde Ocupacional', '', M264_SST],
    ['PGR', 'Programa de Gerenciamento de Riscos', 'gerenciamento de riscos ocupacionais; GRO', M264_SST],
    ['GRO', 'Gerenciamento de Riscos Ocupacionais', '', M264_SST],
    ['PPRA', 'Programa de Prevenção de Riscos Ambientais', '', M264_SST],
    ['LTCAT', 'Laudo Técnico das Condições Ambientais do Trabalho', '', M264_SST],
    ['PPP', 'Perfil Profissiográfico Previdenciário', '', M264_SST],
    ['NR', 'Norma Regulamentadora', '', 'requisitos,treinamentos,funcionariotreinamentos,controles'],
    ['EPI', 'Equipamento de Proteção Individual', '', M264_SST . ',fornecedorcriterios'],
    ['EPC', 'Equipamento de Proteção Coletiva', '', M264_SST],
    ['CA', 'Certificado de Aprovação', 'certificado de aprovação do EPI', M264_SST . ',fornecedorcriterios'],
    ['CIPA', 'Comissão Interna de Prevenção de Acidentes', 'comissão interna de prevenção de acidentes e de assédio', M264_SST],
    ['SESMT', 'Serviço Especializado em Segurança e em Medicina do Trabalho', '', M264_SST],
    ['CAT', 'Comunicação de Acidente de Trabalho', '', M264_SST],
    ['APR', 'Análise Preliminar de Risco', 'análise preliminar de riscos', M264_SST],
    ['PT', 'Permissão de Trabalho', '', M264_SST],
    ['OS', 'Ordem de Serviço', 'ordem de serviço de segurança', M264_SST],
    ['FISPQ', 'Ficha de Informações de Segurança de Produtos Químicos', 'FDS; ficha com dados de segurança', M264_SST . ',fornecedorcriterios'],
    ['PCA', 'Programa de Conservação Auditiva', '', M264_SST],
    ['PPR', 'Programa de Proteção Respiratória', '', M264_SST],
    ['NHO', 'Norma de Higiene Ocupacional', '', M264_SST],
    ['LP', 'Licença Prévia', '', M264_AMB],
    ['LI', 'Licença de Instalação', '', M264_AMB],
    ['LO', 'Licença de Operação', 'licença de funcionamento ambiental', M264_AMB],
    ['RLO', 'Renovação de Licença de Operação', '', M264_AMB],
    ['LAS', 'Licença Ambiental Simplificada', '', M264_AMB],
    ['LAU', 'Licença Ambiental Única', '', M264_AMB],
    ['CTF', 'Cadastro Técnico Federal', 'CTF/APP; cadastro técnico federal do IBAMA', M264_AMB],
    ['CADRI', 'Certificado de Movimentação de Resíduos de Interesse Ambiental', '', M264_AMB],
    ['MTR', 'Manifesto de Transporte de Resíduos', '', M264_AMB . ',fornecedorcriterios'],
    ['CDF', 'Certificado de Destinação Final', '', M264_AMB . ',fornecedorcriterios'],
    ['PGRS', 'Plano de Gerenciamento de Resíduos Sólidos', '', M264_AMB],
    ['PGRSS', 'Plano de Gerenciamento de Resíduos de Serviços de Saúde', '', M264_AMB],
    ['EIA', 'Estudo de Impacto Ambiental', '', M264_AMB],
    ['RIMA', 'Relatório de Impacto Ambiental', '', M264_AMB],
    ['RAP', 'Relatório Ambiental Preliminar', '', M264_AMB],
    ['TAC', 'Termo de Ajustamento de Conduta', '', M264_AMB],
    ['APP', 'Área de Preservação Permanente', '', M264_AMB],
    ['ETE', 'Estação de Tratamento de Efluentes', '', M264_AMB . ',infraestrutura'],
    ['ETA', 'Estação de Tratamento de Água', '', M264_AMB . ',infraestrutura'],
    ['CONAMA', 'Conselho Nacional do Meio Ambiente', '', 'requisitos'],
    ['IBAMA', 'Instituto Brasileiro do Meio Ambiente e dos Recursos Naturais Renováveis', '', 'requisitos,licencas'],
    ['CETESB', 'Companhia Ambiental do Estado de São Paulo', '', 'requisitos,licencas'],
    ['AVCB', 'Auto de Vistoria do Corpo de Bombeiros', '', 'licencas,requisitos,infraestrutura'],
    ['CLCB', 'Certificado de Licença do Corpo de Bombeiros', '', 'licencas,requisitos,infraestrutura'],
    ['PPCI', 'Plano de Prevenção e Proteção Contra Incêndio', 'PPCIP', 'licencas,requisitos,infraestrutura'],
    ['SPDA', 'Sistema de Proteção contra Descargas Atmosféricas', 'para-raios', 'infraestrutura,requisitos,licencas'],
    ['ART', 'Anotação de Responsabilidade Técnica', '', 'licencas,controles,fornecedorcriterios,infraestrutura'],
    ['RRT', 'Registro de Responsabilidade Técnica', '', 'licencas,controles,fornecedorcriterios,infraestrutura'],
    ['SGA', 'Sistema de Gestão Ambiental', '', M264_GES],
    ['SGI', 'Sistema de Gestão Integrado', '', M264_GES],
    ['NC', 'Não Conformidade', '', M264_GES],
    ['RNC', 'Relatório de Não Conformidade', '', M264_GES],
    ['POP', 'Procedimento Operacional Padrão', '', 'controles'],
    ['IT', 'Instrução de Trabalho', '', 'controles'],
    ['CNPJ', 'Cadastro Nacional da Pessoa Jurídica', '', 'fornecedorcriterios'],
    ['CND', 'Certidão Negativa de Débitos', 'certidão negativa', 'fornecedorcriterios'],
    ['FGTS', 'Fundo de Garantia do Tempo de Serviço', 'CRF; certificado de regularidade do FGTS', 'fornecedorcriterios,funcionariocriterios'],
    ['CNH', 'Carteira Nacional de Habilitação', '', 'funcionariocriterios'],
    ['CTPS', 'Carteira de Trabalho e Previdência Social', 'carteira de trabalho', 'funcionariocriterios'],
]
;

$simular = in_array('--simular', $argv ?? [], true);
$m264Bancos = M264_BANCOS_PADRAO;
foreach ($argv ?? [] as $a) if (preg_match('/^--banco=(g[a-z0-9_]+_dbp)$/', $a, $mb) && !in_array($mb[1], $m264Bancos, true)) $m264Bancos[] = $mb[1];
// --todas: todas as empresas migradas (bancos g*_dbp com menus, perfis, permissoes e usuarios; fora o universal e os *_seguranca_dbp)
if (in_array('--todas', $argv ?? [], true)) {
    $envT = GreenPortalDatabase::env(); $uniT = (string)($envT['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
    $isT = GreenPortalDatabase::connect($envT, 'information_schema', 'GPT');
    $achados = $isT->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'perfis', 'permissoes', 'usuarios') AND TABLE_SCHEMA LIKE 'g%\\_dbp' GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 4 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($achados as $bT) if ($bT !== $uniT && !str_ends_with((string)$bT, '_seguranca_dbp') && preg_match('/^g[a-z0-9_]+_dbp$/', (string)$bT) && !in_array($bT, $m264Bancos, true)) $m264Bancos[] = $bT;
}
$indexar = in_array('--indexar', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$sql = (string)preg_replace('/^--(?! @@).*$/m', '', (string)file_get_contents(dirname(__DIR__) . '/database/264_busca_documentos.sql'));
$um = static function (PDO $pdo, string $q, array $p = []): mixed { $s = $pdo->prepare($q); $s->execute($p); $v = $s->fetchColumn(); $s->closeCursor(); return $v; };
$todos = static function (PDO $pdo, string $q, array $p = []): array { $s = $pdo->prepare($q); $s->execute($p); $v = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $v; };
$colunas = static fn(PDO $pdo, string $t): array => array_map('strtolower', array_column($todos($pdo, 'SELECT COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]), 'c'));
// Relações entre negócios (catálogo busca_relacao + views vw_busca_rel_*). As iniciais ligam o Requisito legal às evidências;
// a view só é criada se a tabela de ligação existir no banco (sem a view, o serviço ignora a relação).
// Módulo novo: criar a view vw_busca_rel_<nome> (chave_a, chave_b = grupo_chave dos dois lados) e incluir a relação aqui ou direto no catálogo.
$relacoes = static function (PDO $pdo) use ($colunas, $um): array {
    $c = static fn(string $e): string => "CONVERT($e USING utf8mb4) COLLATE utf8mb4_0900_ai_ci";
    $requisito = static fn(string $x): string => "JOIN `busca_indice` r ON r.tipo = 'requisitos' AND r.item = 'REGISTRO' AND r.ref_legislacao = $x.CODLEGISLACAO"
        . " AND (COALESCE($x.codunidade, 0) = 0 OR r.ref_unidade = $x.codunidade)";
    $evid = ['pt' => 'Atende aos requisitos legais', 'en' => 'Meets the legal requirements', 'es' => 'Atiende a los requisitos legales'];
    $def = [
        ['REQUISITO_LICENCA', 'licencas', 'legislacaoxlicencas', 'codlicenca', 10, 'bi-patch-check',
            ['pt' => 'Licenças vinculadas (evidências)', 'en' => 'Linked licenses (evidence)', 'es' => 'Licencias vinculadas (evidencias)']],
        ['REQUISITO_CONTROLE', 'controles', 'legislacaoxdocumentos', 'coddocumento', 20, 'bi-file-earmark-text',
            ['pt' => 'Controles de documentos vinculados (evidências)', 'en' => 'Linked document controls (evidence)', 'es' => 'Controles de documentos vinculados (evidencias)']],
        ['REQUISITO_TREINAMENTO', 'treinamentos', 'legislacaoxtreinamentos', 'codtreinamento', 30, 'bi-mortarboard',
            ['pt' => 'Treinamentos vinculados (evidências)', 'en' => 'Linked training (evidence)', 'es' => 'Capacitaciones vinculadas (evidencias)']],
        ['REQUISITO_INFRAESTRUTURA', 'infraestrutura', 'legislacaoxinfraestruturas', 'codinfraestrutura', 40, 'bi-building',
            ['pt' => 'Infraestrutura vinculada (evidências)', 'en' => 'Linked infrastructure (evidence)', 'es' => 'Infraestructura vinculada (evidencias)']],
    ];
    $rel = [];
    foreach ($def as [$codigo, $tipo, $tabela, $col, $ordem, $icone, $rotulo]) {
        $view = 'vw_busca_rel_' . strtolower(substr($codigo, strlen('REQUISITO_'))) . '_requisito';
        $cols = $colunas($pdo, $tabela);
        $ok = $cols && in_array('codlegislacao', $cols, true) && in_array($col, $cols, true);
        if ($ok) {
            $unid = in_array('codunidade', $cols, true) ? 'x' : null;
            $juntaReq = $unid ? $requisito('x') : "JOIN `busca_indice` r ON r.tipo = 'requisitos' AND r.item = 'REGISTRO' AND r.ref_legislacao = x.CODLEGISLACAO";
            if ($tipo === 'licencas') {
                // Licenças com versões: o vínculo vale para a pasta (CODLICENCA_BASE), a mesma usada pelo índice
                $base = in_array('codlicenca_base', $colunas($pdo, 'li_licencas'), true);
                $pasta = $base ? 'COALESCE(NULLIF(l.CODLICENCA_BASE, 0), x.CODLICENCA)' : 'x.CODLICENCA';
                $sql = "SELECT r.chave AS chave_a, e.chave AS chave_b FROM `$tabela` x" . ($base ? ' LEFT JOIN `li_licencas` l ON l.CODLICENCA = x.CODLICENCA' : '')
                    . " $juntaReq JOIN `busca_indice` e ON e.tipo = 'licencas' AND e.item = 'REGISTRO' AND e.pasta = $pasta";
            } else {
                $sql = "SELECT r.chave AS chave_a, e.chave AS chave_b FROM `$tabela` x $juntaReq"
                    . " JOIN `busca_indice` e ON e.tipo = '$tipo' AND e.item = 'REGISTRO' AND e.chave = " . $c("CAST(x.`$col` AS CHAR)");
            }
            $pdo->exec("CREATE OR REPLACE VIEW `$view` AS $sql");
        } else {
            $pdo->exec("DROP VIEW IF EXISTS `$view`");
        }
        // Rótulos, ícones, ordem e ativo só na inclusão (podem ser ajustados no catálogo); lados e view sempre
        MigracaoCrud::salvar($pdo, 'busca_relacao', ['codigo' => $codigo], ['tipo_a' => 'requisitos', 'tipo_b' => $tipo, 'fonte' => $view],
            ['rotulo_a_pt' => $rotulo['pt'], 'rotulo_a_en' => $rotulo['en'], 'rotulo_a_es' => $rotulo['es'], 'rotulo_b_pt' => $evid['pt'], 'rotulo_b_en' => $evid['en'],
             'rotulo_b_es' => $evid['es'], 'icone_a' => $icone, 'icone_b' => 'bi-journal-bookmark', 'ordem' => $ordem, 'ativo' => 1]);
        $rel[$codigo] = $ok ? ['view' => $view, 'pares_com_o_indice_atual' => (int)$um($pdo, "SELECT COUNT(*) FROM `$view`")] : "sem a tabela $tabela neste banco (relação sem dados)";
    }
    return $rel;
};
// Responsáveis das telas de Responsabilidades (atribuições ATIVAS) para requisitos, treinamentos e infraestrutura: um SELECT por
// view vw_responsabilidade_* que existir no banco (marcador {{RESPONSAVEIS}}). As outras telas trazem o responsável na própria view.
$montarResponsaveis = static function (PDO $pdo) use ($colunas): array {
    $c = static fn(string $e): string => "CONVERT($e USING utf8mb4) COLLATE utf8mb4_0900_ai_ci";
    $nome = "LEFT(GROUP_CONCAT(DISTINCT v.RESPONSAVEL_EXIBICAO ORDER BY v.RESPONSAVEL_EXIBICAO SEPARATOR '; '), 500)";
    $partes = []; $usadas = [];
    $req = $colunas($pdo, 'vw_responsabilidade_requisitos');
    if ($req && !array_diff(['codlegislacao', 'codunidade', 'responsavel_exibicao', 'situacao'], $req)) {
        $partes[] = 'SELECT ' . $c("'requisitos'") . ' AS tipo, ' . $c('CAST(NULL AS CHAR(100))') . ' AS chave, CAST(v.CODLEGISLACAO AS SIGNED) AS ref_legislacao,'
            . " CAST(v.CODUNIDADE AS SIGNED) AS ref_unidade, " . $c($nome) . " AS responsavel FROM `vw_responsabilidade_requisitos` v WHERE " . $c('v.SITUACAO') . " = 'ATIVA'"
            . ' GROUP BY v.CODLEGISLACAO, v.CODUNIDADE';
        $usadas[] = 'vw_responsabilidade_requisitos';
    }
    foreach ([['vw_responsabilidade_ml_treinamentos', 'treinamentos', 'codtreinamento'], ['vw_responsabilidade_ml_infraestrutura', 'infraestrutura', 'codinfraestrutura']] as [$view, $tipo, $col]) {
        $cols = $colunas($pdo, $view);
        if (!$cols || array_diff([$col, 'responsavel_exibicao', 'situacao'], $cols)) continue;
        $partes[] = 'SELECT ' . $c("'$tipo'") . ', ' . $c("CAST(v.`$col` AS CHAR)") . ", NULL, NULL, " . $c($nome) . " FROM `$view` v WHERE " . $c('v.SITUACAO') . " = 'ATIVA' GROUP BY v.`$col`";
        $usadas[] = $view;
    }
    if (!$partes) return ['SELECT ' . $c('CAST(NULL AS CHAR(40))') . ' AS tipo, ' . $c('CAST(NULL AS CHAR(100))') . ' AS chave, CAST(NULL AS SIGNED) AS ref_legislacao,'
        . ' CAST(NULL AS SIGNED) AS ref_unidade, ' . $c('CAST(NULL AS CHAR(500))') . ' AS responsavel FROM DUAL WHERE 1 = 0', []];
    return [implode("\nUNION ALL\n", $partes), $usadas];
};
// Menu: inclui ou altera pelo codmenu (sp_crud_*); o codmenu não pode estar em uso por outro código
$gravarMenu = static function (PDO $pdo, array $linha) use ($colunas): void {
    // Empresa nova ainda sem o menu pai (ex.: Administração): o menu vai para o topo e é movido na próxima execução
    if (!empty($linha['codmenu_pai']) && !MigracaoCrud::primeiro($pdo, 'menus', ['codmenu' => $linha['codmenu_pai']])) {
        $linha['codmenu_pai'] = null;
        $linha['nivel'] = 1;
    }
    $dados = array_intersect_key($linha, array_flip($colunas($pdo, 'menus')));
    $atual = MigracaoCrud::primeiro($pdo, 'menus', ['codmenu' => $linha['codmenu']]);
    if ($atual && strtoupper((string)($atual['codigo'] ?? '')) !== strtoupper((string)$linha['codigo'])) throw new RuntimeException("codmenu {$linha['codmenu']} já usado por {$atual['codigo']}");
    if ($atual) MigracaoCrud::atualizar($pdo, 'menus', ['codmenu' => $linha['codmenu']], array_diff_key($dados, ['codmenu' => 1]));
    else MigracaoCrud::inserir($pdo, 'menus', $dados);
};
// Permissão do perfil no menu (permissoes / perfil_menus): liga as flags, incluindo a linha se não existir
$liberarMenu = static function (PDO $pdo, string $tabela, int $codperfil, int $codmenu, array $flags): void {
    MigracaoCrud::salvar($pdo, $tabela, ['codperfil' => $codperfil, 'codmenu' => $codmenu], array_fill_keys($flags, 1));
};

$relatorio = ['ok' => true, 'migracao' => '264', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
$u = GreenPortalDatabase::connect($env, $bancoUniversal, 'GUNIVERSAL');
$u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$clientes = [];
foreach ($m264Bancos as $b) { $p = GreenPortalDatabase::connect($env, $b, 'GPT'); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $clientes[$b] = $p; }

$sqlDic = (string)preg_replace('/^--(?! @@).*$/m', '', (string)file_get_contents(dirname(__DIR__) . '/database/264_busca_dicionario.sql'));
$aplicarSql = static function (PDO $pdo, string $sqlTexto): void { foreach (preg_split('/^-- @@\s*$/m', $sqlTexto) as $bloco) { $cmd = trim($bloco); if ($cmd !== '') $pdo->exec($cmd); } };
if (!preg_match('/^[A-Za-z0-9_]+$/', $bancoUniversal)) throw new RuntimeException('Nome do banco universal inválido');
$sql = strtr($sql, ['{{UNIVERSAL}}' => $bancoUniversal]);

// Dicionário: base comum no universal (antes das empresas, que usam a view com as duas bases) e carga inicial
try {
    if (!$simular) {
        if ($criadas = MigracaoCrud::garantir($u)) $relatorio['universal']['procedures_crud_criadas'] = $criadas;
        $aplicarSql($u, $sqlDic);
        $novos = 0;
        foreach (M264_DICIONARIO as [$termo, $significado, $sinonimos, $contexto]) {
            if (MigracaoCrud::primeiro($u, 'busca_dicionario', ['termo' => $termo])) continue;   // já existe (qualquer situação): não mexe
            MigracaoCrud::inserir($u, 'busca_dicionario', ['termo' => $termo, 'significado' => $significado, 'sinonimos' => $sinonimos !== '' ? $sinonimos : null,
                'sigla' => 1, 'contexto' => $contexto, 'status' => 'SUGESTAO', 'origem' => 'SETOR']);
            $novos++;
        }
        $relatorio['universal']['dicionario'] = ['carga_inicial_incluidos' => $novos, 'por_situacao' => MigracaoCrud::contarPor($u, 'busca_dicionario', 'status')];
    } else $relatorio['universal']['dicionario'] = 'carga inicial de ' . count(M264_DICIONARIO) . ' termos (SUGESTAO)';
} catch (Throwable $e) { $relatorio['ok'] = false; $relatorio['universal']['dicionario_erro'] = $e->getMessage(); }

// Menu do dicionário: junto da Administração (mesmo pai da tela de Idiomas), mesmo codmenu no universal e nos clientes
$codmenuDic = (int)(MigracaoCrud::primeiro($u, 'menus', ['codigo' => M264_MENU_DIC])['codmenu'] ?? 0);
$paiDic = (int)(MigracaoCrud::primeiro($u, 'menus', ['url' => '/principal/administracao_idiomas.php'])['codmenu_pai'] ?? 0);
$paiDic = $paiDic < 1 ? null : $paiDic;
if ($codmenuDic < 1) {
    $codmenuDic = MigracaoCrud::maximo($u, 'menus', 'codmenu');
    foreach ($clientes as $p) $codmenuDic = max($codmenuDic, MigracaoCrud::maximo($p, 'menus', 'codmenu'));
    $codmenuDic++;
    $relatorio['menu_dicionario'] = "será criado com codmenu $codmenuDic" . ($paiDic ? " (Administração, codmenu_pai $paiDic)" : ' (no topo do menu)');
} else $relatorio['menu_dicionario'] = "já existe (codmenu $codmenuDic)";
$ordemDic = 99;
if ($paiDic) {
    $ordemDic = 1;
    foreach (MigracaoCrud::selecionar($u, 'menus', ['codmenu_pai' => $paiDic]) as $m) if ((int)$m['codmenu'] !== $codmenuDic) $ordemDic = max($ordemDic, (int)$m['ordem'] + 1);
}
$linhaMenuDic = ['codmenu' => $codmenuDic, 'codmenu_pai' => $paiDic, 'modulo' => 'BUSCA', 'codigo' => M264_MENU_DIC, 'label' => 'Dicionário da pesquisa',
    'label_pt' => 'Dicionário da pesquisa', 'label_en' => 'Search dictionary', 'label_es' => 'Diccionario de la búsqueda', 'url' => M264_URL_DIC,
    'nivel' => $paiDic ? 2 : 1, 'ordem' => max(1, $ordemDic), 'icone' => 'bi-book', 'ativo' => 1];

// Menu: mesmo codmenu no universal e nos clientes
$codmenu = (int)(MigracaoCrud::primeiro($u, 'menus', ['codigo' => M264_MENU])['codmenu'] ?? 0);
if ($codmenu < 1) {
    $codmenu = MigracaoCrud::maximo($u, 'menus', 'codmenu');
    foreach ($clientes as $p) $codmenu = max($codmenu, MigracaoCrud::maximo($p, 'menus', 'codmenu'));
    $codmenu++;
    $relatorio['menu'] = "será criado com codmenu $codmenu (no topo do menu)";
} else $relatorio['menu'] = "já existe (codmenu $codmenu)";
$linhaMenu = ['codmenu' => $codmenu, 'codmenu_pai' => null, 'modulo' => 'BUSCA', 'codigo' => M264_MENU, 'label' => 'Pesquisa de documentos',
    'label_pt' => 'Pesquisa de documentos', 'label_en' => 'Document search', 'label_es' => 'Búsqueda de documentos', 'url' => M264_URL,
    'nivel' => 1, 'ordem' => 1, 'icone' => 'bi-search', 'ativo' => 1];

foreach ($clientes as $banco => $pdo) {
    $info = [];
    try {
        foreach (['vw_portaria_usuario_unidades', 'vw_portaria_documentacao_funcionario_grid', 'vw_portaria_documentacao_treinamento_grid', 'vw_licencas_grid', 'vw_controle_documentos_grid', 'vw_modulolegal_requisitos_grid'] as $v)
            if (!(int)$um($pdo, 'SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$v])) throw new RuntimeException("view $v não existe (rode antes as migrações 260, 262 e 263)");
        if (!$simular) {
            if ($criadas = MigracaoCrud::garantir($pdo)) $info['procedures_crud_criadas'] = $criadas;
            $aplicarSql($pdo, $sqlDic);
            [$responsaveisSql, $responsaveisViews] = $montarResponsaveis($pdo);
            $aplicarSql($pdo, strtr($sql, ['{{RESPONSAVEIS}}' => $responsaveisSql]));
            $info['responsaveis_de'] = array_merge(['licenças, controles e Portaria: a própria view do grid'], $responsaveisViews ?: ['(sem views de Responsabilidades: requisitos, treinamentos e infraestrutura sem responsável)']);
            $info['estrutura'] = 'tabelas, views e procedures gravadas';
            $info['dicionario_da_empresa'] = MigracaoCrud::contarPor($pdo, 'busca_dicionario', 'status') ?: 'vazio (o serviço sugere termos a partir dos cadastros)';
            $info['itens_na_fonte'] = array_column($todos($pdo, 'SELECT tipo, COUNT(*) n FROM vw_busca_fonte_registros GROUP BY tipo'), 'n', 'tipo');
            $info['relacoes'] = $relacoes($pdo);
            $gravarMenu($pdo, $linhaMenu);
            // Visualizar para todos os perfis (cada um vê na pesquisa só o que pode ver nas telas)
            $perfis = MigracaoCrud::selecionar($pdo, 'perfis');
            $liberados = 0;
            foreach (['permissoes', 'perfil_menus'] as $t) {
                $cols = $colunas($pdo, $t);
                if (!$cols) continue;
                foreach ($perfis as $pf) {
                    $liberarMenu($pdo, $t, (int)$pf['codperfil'], $codmenu, ['pode_visualizar']);
                    if ($t === 'permissoes') $liberados++;
                }
            }
            $info['perfis_liberados'] = $liberados;
            // Dicionário: menu e acesso completo para ADMINISTRADOR e ADM_DA_EMPRESA
            $gravarMenu($pdo, $linhaMenuDic);
            $dicPerfis = [];
            foreach (['permissoes', 'perfil_menus'] as $t) {
                $cols = $colunas($pdo, $t);
                if (!$cols) continue;
                $flags = array_values(array_intersect(['pode_visualizar', 'pode_incluir', 'pode_editar', 'pode_excluir'], $cols));
                foreach ($perfis as $pf) {
                    if (!in_array(strtoupper((string)$pf['codigo']), M264_PERFIS_DIC, true)) continue;
                    $liberarMenu($pdo, $t, (int)$pf['codperfil'], $codmenuDic, $flags);
                    if ($t === 'permissoes') $dicPerfis[] = $pf['codigo'];
                }
            }
            $info['dicionario_perfis'] = $dicPerfis;
            if ($colunas($pdo, 'registros_migracao')) {
                MigracaoCrud::salvar($pdo, 'registros_migracao', ['codigo_migracao' => 'BUSCA_DOCUMENTOS_264'],
                    ['status' => 'CONCLUIDA', 'quantidade_registros' => array_sum($info['itens_na_fonte']), 'concluida_em' => date('Y-m-d H:i:s'), 'detalhes' => json_encode($info, JSON_UNESCAPED_UNICODE)],
                    ['origem' => 'views dos grids + GED', 'destino' => 'busca_indice; sp_busca_*', 'tipo' => 'ESTRUTURA', 'versao' => '4.0.1', 'iniciada_em' => date('Y-m-d H:i:s')]);
            }
        } else {
            $info['perfis_a_liberar'] = count(MigracaoCrud::selecionar($pdo, 'perfis'));
        }
    } catch (Throwable $e) { $relatorio['ok'] = false; $info['erro'] = $e->getMessage(); }
    $relatorio['bancos'][$banco] = $info;
}

// Universal: menu, ajuda, serviço
try {
    if (!$simular) {
        $gravarMenu($u, $linhaMenu);
        $gravarMenu($u, $linhaMenuDic);
        if ($colunas($u, 'ajuda_transacoes')) {
        // A tela de ajuda (setup_ajuda.php) mostra o texto como HTML: parágrafos, títulos de seção (bloco que começa com
        // uma pergunta) e listas (linhas com "• ")
        $ajudaHtml = static function (string $t): string {
            $e = static fn(string $x): string => htmlspecialchars($x, ENT_QUOTES, 'UTF-8');
            $html = '';
            foreach (preg_split('/\n{2,}/', trim($t)) as $bloco) {
                $linhas = explode("\n", $bloco); $lista = false;
                foreach ($linhas as $n => $l) {
                    if (str_starts_with($l, '• ')) { if (!$lista) { $html .= '<ul>'; $lista = true; } $html .= '<li>' . $e(substr($l, strlen('• '))) . '</li>'; continue; }
                    if ($lista) { $html .= '</ul>'; $lista = false; }
                    $html .= ($n === 0 && count($linhas) > 1 && str_ends_with($l, '?')) ? '<h2 class="h5 mt-4">' . $e($l) . '</h2>' : '<p>' . $e($l) . '</p>';
                }
                if ($lista) $html .= '</ul>';
            }
            return $html;
        };
            $titulo = ['Pesquisa de documentos', 'Document search', 'Búsqueda de documentos'];
            $texto = [
                "Pesquisa em um só lugar os cadastros e os arquivos do GED de Licenças, Controles de documentos, Requisitos legais, Treinamentos, Infraestrutura e Portaria (documentação de fornecedores, de funcionários e treinamentos).\n\nClique na lupa do cabeçalho e digite o que procura. Use Refinar por (tipo, formato, situação, unidade) para reduzir os resultados e Avançada para filtrar só registros ou só arquivos e a validade.\n\nOs resultados vêm agrupados pelo negócio: a licença, o controle, o requisito, o treinamento ou a infraestrutura com os seus arquivos e, na Portaria, o fornecedor ou o funcionário com os seus documentos. Abra o grupo (seta) para ver os arquivos e os vínculos com Requisitos legais: no requisito, as evidências (licenças, controles, treinamentos, infraestrutura); na evidência, os requisitos que ela atende.\n\nNo menu de cada resultado: Abrir (abre a transação já no registro), Visualizar e Download (arquivo), Arquivos (janela de arquivos do registro), Propriedades e Copiar link.\n\nPor que não encontro um documento?\nA pesquisa respeita as mesmas permissões das telas do sistema: você só encontra o que veria abrindo a própria tela.\n• Aparecem apenas itens das telas liberadas no seu perfil (permissão Visualizar).\n• Licenças: sem \"Visualizar total\", aparecem só as licenças em que você é o responsável.\n• Portaria: aparecem só as unidades a que você tem acesso.\n• Nos vínculos de um item (requisitos atendidos, evidências) aparecem só os que você pode ver; por isso um colega pode ver mais vínculos que você.\n• Registros excluídos ou que não aparecem na tela de origem também não aparecem na pesquisa.\n• A pesquisa é atualizada periodicamente pelo serviço de indexação (a cada 10 minutos); um item recém-alterado pode levar alguns minutos para aparecer.\nSe precisar de acesso a mais itens, peça ao administrador do sistema para revisar o seu perfil.",
                "Searches in one place the records and GED files of Licenses, Document controls, Legal requirements, Training, Infrastructure and Gatehouse (supplier documents, employee documents and training).\n\nClick the magnifier in the header and type what you are looking for. Use Refine by (type, format, status, unit) to narrow the results and Advanced to filter only records or only files and the expiration date.\n\nResults are grouped by business item: the license, document control, requirement, training or infrastructure with its files and, in Gatehouse, the supplier or the employee with their documents. Open the group (arrow) to see the files and the links with Legal requirements: on a requirement, its evidence (licenses, controls, training, infrastructure); on the evidence, the requirements it meets.\n\nIn each result menu: Open (opens the transaction on the record), View and Download (file), Files (record file window), Properties and Copy link.\n\nWhy can't I find a document?\nThe search follows the same permissions as the system screens: you only find what you would see by opening the screen itself.\n• Only items from screens enabled in your profile (View permission) appear.\n• Licenses: without \"View all\", only the licenses you are responsible for appear.\n• Gatehouse: only the units you have access to appear.\n• In an item's links (requirements met, evidence) only the ones you can see appear; that is why a colleague may see more links than you.\n• Deleted records, or records not shown on the source screen, do not appear in the search either.\n• The search is updated periodically by the indexing service (every 10 minutes); a recently changed item may take a few minutes to appear.\nIf you need access to more items, ask the system administrator to review your profile.",
                "Busca en un solo lugar los registros y archivos del GED de Licencias, Controles de documentos, Requisitos legales, Capacitaciones, Infraestructura y Portería (documentación de proveedores, de empleados y capacitaciones).\n\nHaga clic en la lupa del encabezado y escriba lo que busca. Use Refinar por (tipo, formato, situación, unidad) para reducir los resultados y Avanzada para filtrar solo registros o solo archivos y la validez.\n\nLos resultados vienen agrupados por negocio: la licencia, el control, el requisito, la capacitación o la infraestructura con sus archivos y, en Portería, el proveedor o el empleado con sus documentos. Abra el grupo (flecha) para ver los archivos y los vínculos con Requisitos legales: en el requisito, sus evidencias (licencias, controles, capacitaciones, infraestructura); en la evidencia, los requisitos que atiende.\n\nEn el menú de cada resultado: Abrir (abre la transacción en el registro), Ver y Descargar (archivo), Archivos (ventana de archivos del registro), Propiedades y Copiar enlace.\n\n¿Por qué no encuentro un documento?\nLa búsqueda respeta los mismos permisos de las pantallas del sistema: solo encuentra lo que vería abriendo la propia pantalla.\n• Solo aparecen ítems de las pantallas habilitadas en su perfil (permiso Ver).\n• Licencias: sin \"Ver todo\", solo aparecen las licencias de las que usted es el responsable.\n• Portería: solo aparecen las unidades a las que usted tiene acceso.\n• En los vínculos de un ítem (requisitos atendidos, evidencias) solo aparecen los que usted puede ver; por eso un colega puede ver más vínculos que usted.\n• Los registros eliminados o que no aparecen en la pantalla de origen tampoco aparecen en la búsqueda.\n• La búsqueda se actualiza periódicamente por el servicio de indexación (cada 10 minutos); un ítem modificado recientemente puede tardar algunos minutos en aparecer.\nSi necesita acceso a más ítems, pida al administrador del sistema que revise su perfil.",
            ];
            $gravarAjuda = static fn(string $transacao, array $titulos, array $textos): string => MigracaoCrud::salvar($u, 'ajuda_transacoes', ['transacao' => $transacao],
                array_combine(['titulo_pt', 'titulo_en', 'titulo_es'], $titulos) + array_combine(['texto_pt', 'texto_en', 'texto_es'], array_map($ajudaHtml, $textos)) + ['ativo' => 1]);
            $relatorio['universal']['ajuda'] = $gravarAjuda('busca_documentos', $titulo, $texto) === 'alterado' ? 'atualizada' : 'incluída';
            $tituloDic = ['Dicionário da pesquisa', 'Search dictionary', 'Diccionario de la búsqueda'];
            $textoDic = [
                "Termos do negócio que a Pesquisa de documentos entende: siglas, significados e sinônimos. Ex.: ASO = Atestado de Saúde Ocupacional — quem pesquisa ASO também encontra \"Atestado de Saúde Ocupacional\", e os documentos de funcionário (o contexto do termo) aparecem primeiro.\n\nBase comum: termos do setor, valem para todas as empresas (só o ADMINISTRADOR altera). Empresa: termos próprios desta empresa.\n\nSugestões: a carga inicial e os termos que o serviço de indexação encontra nos cadastros (\"SIGLA - descrição\" e \"descrição (SIGLA)\") entram como Sugestão. Revise e Aprove (ou Rejeite); só os aprovados são usados na pesquisa. Sigla = vale só como palavra inteira (ASO não acha \"caso\"). Contexto = onde o termo é mais relevante; esses resultados sobem na lista.",
                "Business terms the Document search understands: acronyms, meanings and synonyms. E.g. ASO = Occupational Health Certificate — searching ASO also finds the full name, and employee documents (the term context) come first.\n\nCommon base: industry terms for all companies (only ADMINISTRADOR changes them). Company: this company's own terms.\n\nSuggestions: the initial load and the terms the indexing service finds in the records (\"ACRONYM - description\" and \"description (ACRONYM)\") come in as Suggestion. Review and Approve (or Reject); only approved terms are used by the search. Acronym = whole word only. Context = where the term matters most; those results move up.",
                "Términos del negocio que la Búsqueda de documentos entiende: siglas, significados y sinónimos. Ej.: ASO = Certificado de Salud Ocupacional — quien busca ASO también encuentra el nombre completo, y los documentos de empleado (el contexto del término) aparecen primero.\n\nBase común: términos del sector, valen para todas las empresas (solo el ADMINISTRADOR los cambia). Empresa: términos propios de esta empresa.\n\nSugerencias: la carga inicial y los términos que el servicio de indexación encuentra en los registros (\"SIGLA - descripción\" y \"descripción (SIGLA)\") entran como Sugerencia. Revise y Apruebe (o Rechace); solo los aprobados se usan en la búsqueda. Sigla = solo palabra entera. Contexto = donde el término es más relevante; esos resultados suben.",
            ];
            $gravarAjuda('busca_dicionario', $tituloDic, $textoDic);
        }
        if ($colunas($u, 'servicos')) {
            $dados = ['Indexação da pesquisa', 'Search indexing', 'Indexación de la búsqueda',
                'Atualiza o índice da Pesquisa de documentos (cadastros e arquivos do GED) a cada 10 minutos.',
                'Updates the Document search index (records and GED files) every 10 minutes.',
                'Actualiza el índice de la Búsqueda de documentos (registros y archivos del GED) cada 10 minutos.'];
            $r = MigracaoCrud::salvar($u, 'servicos', ['codigo' => 'BUSCA_WORKER'],
                array_combine(['nome_pt', 'nome_en', 'nome_es', 'descricao_pt', 'descricao_en', 'descricao_es'], $dados) + ['host' => 'TASK_SCHEDULER', 'script' => 'scripts/busca-worker.php', 'ativo' => 1],
                ['porta' => 0, 'ordem' => 12]);
            $relatorio['universal']['servico'] = $r === 'alterado' ? 'BUSCA_WORKER atualizado' : 'BUSCA_WORKER incluído';
        }
    }
    $relatorio['universal']['menu'] = $relatorio['menu'];
} catch (Throwable $e) { $relatorio['ok'] = false; $relatorio['universal']['erro'] = $e->getMessage(); }
unset($relatorio['menu']);

$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents(dirname(__DIR__) . '/storage/logs/migracao_264_busca_documentos' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;

if ($indexar && !$simular && $relatorio['ok']) {
    echo PHP_EOL . '== Primeira indexação ==' . PHP_EOL;
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'busca-worker.php'), $rc);
    if ($rc !== 0) $relatorio['ok'] = false;
}
exit($relatorio['ok'] ? 0 : 1);
