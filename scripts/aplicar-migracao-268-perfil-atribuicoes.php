<?php
declare(strict_types=1);

/*
 * Migração 268 — Perfil de atribuições do usuário.
 *
 * Universal (guniversal_dbp):
 *   - questionário PERFIL_ATRIBUICOES no catálogo de questionários (amb_questionario_*), versão 1 PUBLICADA, com os blocos
 *     GERAL, MODULO_LEGAL, HOMOLOGACAO_FORNECEDORES e NAO_CONFORMIDADES (só é criado se ainda não existir versão publicada:
 *     alterações feitas depois pela Administração › Questionários são preservadas);
 *   - views vw_amb_questionario_blocos, vw_perfil_atrib_menus e vw_perfil_atrib_empresa_modulos;
 *   - menu PERFIL_ATRIBUICOES_GESTAO (Perfis de atribuições, em Administração) e ajuda das duas telas.
 * Em cada empresa (bancos *_dbp com menus, perfis e permissoes): database/268_perfil_atribuicoes.sql (tabelas, views e
 * procedures), o menu e o acesso completo à gestão para ADMINISTRADOR e ADM_DA_EMPRESA. "Meu perfil de atribuições" não
 * precisa de permissão: cada usuário responde o próprio (popup no login e Minha conta).
 *
 * Perfis: coluna perfis.aplicar_questionario ("Aplicar questionário" em Segurança › Perfil). Ao ser criada: marcada para todos os
 * perfis, menos ADMINISTRADOR e ADM_DA_EMPRESA (os dois são gestores de todos e não respondem). Nos bancos em que a coluna já
 * existia, o ADM_DA_EMPRESA é desmarcado uma única vez (registro PERFIL_ATRIB_SEM_ADM_EMPRESA em registros_migracao); depois
 * vale o que estiver marcado na tela. Só responde o questionário quem tem o perfil de acesso principal marcado.
 *
 * Dados sempre por procedure (classes/MigracaoCrud.php: sp_crud_select / sp_crud_insert / sp_crud_update); só a estrutura
 * (tabelas, colunas, views, procedures) é feita por DDL direto.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-268-perfil-atribuicoes.php [--simular]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';

const M268_MENU = 'PERFIL_ATRIBUICOES_GESTAO';
const M268_URL = '/principal/perfil_atribuicoes_gestao.php';
const M268_PERFIS = ['ADMINISTRADOR', 'ADM_DA_EMPRESA'];
const M268_QUESTIONARIO = 'PERFIL_ATRIBUICOES';

// Papéis (opções das perguntas de pilar)
const M268_PAPEIS = [
    ['EXECUTA', 'Executo', 'I perform it', 'Lo ejecuto'],
    ['APROVA', 'Aprovo', 'I approve it', 'Lo apruebo'],
    ['ACOMPANHA', 'Acompanho', 'I follow it up', 'Le doy seguimiento'],
];
// Blocos: código (= módulo), títulos, perguntas [código, tipo, obrigatória, pt, en, es, opções [código, pt, en, es] | 'PAPEIS' | null]
const M268_BLOCOS = [
    ['GERAL', 'Informações gerais', 'General information', 'Información general', [
        ['GERAL.UNIDADES', 'MULTIPLA_ESCOLHA', 1, 'Em quais unidades você atua?', 'Which units do you work at?', '¿En qué unidades trabaja?', null],
        ['GERAL.AREA', 'UNICA_ESCOLHA', 1, 'Qual é a sua área de atuação?', 'What is your area?', '¿Cuál es su área de actuación?', [
            ['MEIO_AMBIENTE', 'Meio ambiente', 'Environment', 'Medio ambiente'],
            ['SEGURANCA_TRABALHO', 'Segurança do trabalho', 'Occupational safety', 'Seguridad laboral'],
            ['SAUDE_OCUPACIONAL', 'Saúde ocupacional', 'Occupational health', 'Salud ocupacional'],
            ['QUALIDADE', 'Qualidade', 'Quality', 'Calidad'],
            ['SUPRIMENTOS', 'Suprimentos / compras', 'Procurement', 'Suministros / compras'],
            ['MANUTENCAO_ENGENHARIA', 'Manutenção / engenharia', 'Maintenance / engineering', 'Mantenimiento / ingeniería'],
            ['PRODUCAO_OPERACAO', 'Produção / operação', 'Production / operations', 'Producción / operación'],
            ['RECURSOS_HUMANOS', 'Recursos humanos', 'Human resources', 'Recursos humanos'],
            ['JURIDICO', 'Jurídico', 'Legal department', 'Jurídico'],
            ['PORTARIA_FACILITIES', 'Portaria / facilities', 'Gatehouse / facilities', 'Portería / servicios generales'],
            ['GESTAO_DIRETORIA', 'Gestão / diretoria', 'Management / board', 'Gestión / dirección'],
            ['OUTRA', 'Outra', 'Other', 'Otra'],
        ]],
        ['GERAL.GESTOR_EQUIPE', 'SIM_NAO', 1, 'Você tem pessoas sob a sua gestão?', 'Do you manage other people?', '¿Tiene personas a su cargo?', null],
    ]],
    ['MODULO_LEGAL', 'Módulo Legal / Gestão Ambiental', 'Legal Module / Environmental Management', 'Módulo Legal / Gestión Ambiental', [
        ['MODULO_LEGAL.PILAR.REQUISITOS', 'MULTIPLA_ESCOLHA', 0, 'Acompanhamento dos requisitos legais (aplicabilidade e atendimento)', 'Legal requirements follow-up (applicability and compliance)', 'Seguimiento de los requisitos legales (aplicabilidad y cumplimiento)', 'PAPEIS'],
        ['MODULO_LEGAL.PILAR.LICENCAS', 'MULTIPLA_ESCOLHA', 0, 'Licenças (renovação, protocolo, condicionantes e taxas)', 'Licenses (renewal, filing, conditions and fees)', 'Licencias (renovación, protocolo, condicionantes y tasas)', 'PAPEIS'],
        ['MODULO_LEGAL.PILAR.MONITORAMENTO', 'MULTIPLA_ESCOLHA', 0, 'Medições e monitoramentos', 'Measurements and monitoring', 'Mediciones y monitoreos', 'PAPEIS'],
        ['MODULO_LEGAL.PILAR.TREINAMENTOS', 'MULTIPLA_ESCOLHA', 0, 'Treinamentos legais (organização ou realização)', 'Legal training (organizing or delivering)', 'Capacitaciones legales (organización o realización)', 'PAPEIS'],
        ['MODULO_LEGAL.PILAR.INFRAESTRUTURA', 'MULTIPLA_ESCOLHA', 0, 'Infraestrutura e equipamentos de controle (manutenção e inspeção)', 'Infrastructure and control equipment (maintenance and inspection)', 'Infraestructura y equipos de control (mantenimiento e inspección)', 'PAPEIS'],
        ['MODULO_LEGAL.PILAR.CONTROLES', 'MULTIPLA_ESCOLHA', 0, 'Procedimentos, controles e documentos (elaboração ou aprovação)', 'Procedures, controls and documents (drafting or approval)', 'Procedimientos, controles y documentos (elaboración o aprobación)', 'PAPEIS'],
        ['MODULO_LEGAL.TEMAS', 'MULTIPLA_ESCOLHA', 0, 'Quais temas fazem parte do seu trabalho?', 'Which topics are part of your work?', '¿Qué temas forman parte de su trabajo?', [
            ['RESIDUOS', 'Resíduos', 'Waste', 'Residuos'],
            ['EFLUENTES', 'Efluentes', 'Effluents', 'Efluentes'],
            ['EMISSOES', 'Emissões atmosféricas', 'Air emissions', 'Emisiones atmosféricas'],
            ['RUIDO', 'Ruído', 'Noise', 'Ruido'],
            ['RECURSOS_HIDRICOS', 'Água / recursos hídricos', 'Water resources', 'Agua / recursos hídricos'],
            ['PRODUTOS_QUIMICOS', 'Produtos químicos', 'Chemicals', 'Productos químicos'],
            ['AREAS_CONTAMINADAS', 'Áreas contaminadas', 'Contaminated sites', 'Áreas contaminadas'],
            ['FAUNA_FLORA', 'Fauna e flora', 'Fauna and flora', 'Fauna y flora'],
            ['SEGURANCA_TRABALHO', 'Segurança do trabalho', 'Occupational safety', 'Seguridad laboral'],
            ['SAUDE_OCUPACIONAL', 'Saúde ocupacional', 'Occupational health', 'Salud ocupacional'],
            ['INCENDIO_EMERGENCIA', 'Incêndio e emergências', 'Fire and emergencies', 'Incendios y emergencias'],
            ['SOCIAL_COMUNIDADE', 'Social / comunidade', 'Social / community', 'Social / comunidad'],
        ]],
    ]],
    ['HOMOLOGACAO_FORNECEDORES', 'Homologação de Fornecedores', 'Supplier Homologation', 'Homologación de Proveedores', [
        ['HOMOLOGACAO_FORNECEDORES.PILAR.HOMOLOGACAO', 'MULTIPLA_ESCOLHA', 0, 'Homologação de fornecedores (cadastro, avaliação e aprovação)', 'Supplier qualification (registration, assessment and approval)', 'Homologación de proveedores (registro, evaluación y aprobación)', 'PAPEIS'],
        ['HOMOLOGACAO_FORNECEDORES.PILAR.DOC_FORNECEDOR', 'MULTIPLA_ESCOLHA', 0, 'Análise de documentos de fornecedores', 'Supplier document review', 'Análisis de documentos de proveedores', 'PAPEIS'],
        ['HOMOLOGACAO_FORNECEDORES.PILAR.DOC_FUNCIONARIO', 'MULTIPLA_ESCOLHA', 0, 'Análise de documentos de funcionários de terceiros (ASO, treinamentos)', 'Contractor employee document review (health certificates, training)', 'Análisis de documentos de empleados de terceros (ASO, capacitaciones)', 'PAPEIS'],
        ['HOMOLOGACAO_FORNECEDORES.PILAR.PORTARIA', 'MULTIPLA_ESCOLHA', 0, 'Liberação de acesso na portaria', 'Gatehouse access release', 'Liberación de acceso en portería', 'PAPEIS'],
        ['HOMOLOGACAO_FORNECEDORES.PILAR.INTEGRACAO', 'MULTIPLA_ESCOLHA', 0, 'Integração de funcionários de terceiros', 'Contractor employee onboarding', 'Integración de empleados de terceros', 'PAPEIS'],
        ['HOMOLOGACAO_FORNECEDORES.CATEGORIAS', 'MULTIPLA_ESCOLHA', 0, 'Com quais categorias de fornecedores você trabalha?', 'Which supplier categories do you work with?', '¿Con qué categorías de proveedores trabaja?', [
            ['RESIDUOS', 'Gestão de resíduos', 'Waste management', 'Gestión de residuos'],
            ['TRANSPORTE', 'Transporte', 'Transportation', 'Transporte'],
            ['SERVICOS_INSTALACOES', 'Serviços nas instalações', 'On-site services', 'Servicios en las instalaciones'],
            ['PRODUTOS_QUIMICOS', 'Produtos químicos', 'Chemicals', 'Productos químicos'],
            ['MANUTENCAO', 'Manutenção', 'Maintenance', 'Mantenimiento'],
            ['OBRAS', 'Obras e construção', 'Construction', 'Obras y construcción'],
            ['LABORATORIOS', 'Laboratórios e consultorias', 'Laboratories and consultants', 'Laboratorios y consultorías'],
            ['OUTROS', 'Outros', 'Other', 'Otros'],
        ]],
    ]],
    ['NAO_CONFORMIDADES', 'Não Conformidades', 'Nonconformities', 'No conformidades', [
        ['NAO_CONFORMIDADES.PILAR.REGISTRO', 'MULTIPLA_ESCOLHA', 0, 'Registro de ocorrências', 'Occurrence registration', 'Registro de ocurrencias', 'PAPEIS'],
        ['NAO_CONFORMIDADES.PILAR.ANALISE_CAUSA', 'MULTIPLA_ESCOLHA', 0, 'Análise de causa raiz', 'Root cause analysis', 'Análisis de causa raíz', 'PAPEIS'],
        ['NAO_CONFORMIDADES.PILAR.PLANO_ACAO', 'MULTIPLA_ESCOLHA', 0, 'Planos de ação', 'Action plans', 'Planes de acción', 'PAPEIS'],
        ['NAO_CONFORMIDADES.PILAR.EFICACIA', 'MULTIPLA_ESCOLHA', 0, 'Avaliação de eficácia', 'Effectiveness evaluation', 'Evaluación de eficacia', 'PAPEIS'],
    ]],
];

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$u = GreenPortalDatabase::connect($env, $bancoUniversal, 'GUNIVERSAL');
$u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$todos = static function (PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$um = static function (PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); $v = $s->fetchColumn(); $s->closeCursor(); return $v; };
$colunas = static fn(PDO $pdo, string $t): array => array_map('strtolower', array_column($todos($pdo, 'SELECT COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]), 'c'));
$existe = static fn(PDO $pdo, string $t): bool => (bool)$um($pdo, 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]);
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
// Desmarca "Aplicar questionário" dos perfis com estes códigos; devolve quantos mudaram
$desmarcarPerfis = static function (PDO $pdo, array $codigos): int {
    $n = 0;
    foreach (MigracaoCrud::selecionar($pdo, 'perfis') as $pf)
        if (in_array(strtoupper((string)$pf['codigo']), $codigos, true) && (int)($pf['aplicar_questionario'] ?? 1) === 1)
            { MigracaoCrud::atualizar($pdo, 'perfis', ['codperfil' => (int)$pf['codperfil']], ['aplicar_questionario' => 0]); $n++; }
    return $n;
};
$aplicarSql = static function (PDO $pdo, string $texto): void { foreach (preg_split('/^-- @@\s*$/m', $texto) as $b) { $c = trim((string)preg_replace('/^--(?! @@).*$/m', '', $b)); if ($c !== '') $pdo->exec($c); } };

$relatorio = ['ok' => true, 'migracao' => '268', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'universal' => [], 'bancos' => []];

// ---------------------------------------------------------------- Universal
try {
    if (!$simular && ($criadas = MigracaoCrud::garantir($u))) $relatorio['universal']['procedures_crud_criadas'] = $criadas;
    foreach (['amb_questionario', 'amb_questionario_versao', 'amb_questionario_bloco', 'amb_questionario_pergunta', 'amb_questionario_opcao', 'amb_questionario_publicacao', 'menus'] as $t)
        if (!$existe($u, $t)) throw new RuntimeException("tabela $t não existe no universal (rode antes a migração 193 dos questionários)");
    if (!$simular) {
        $u->exec('CREATE OR REPLACE VIEW vw_amb_questionario_blocos AS
            SELECT codbloco, codversao, codigo, titulo_pt, titulo_en, titulo_es, ordem, ativo FROM amb_questionario_bloco');
        $u->exec('CREATE OR REPLACE VIEW vw_perfil_atrib_menus AS SELECT codmenu, codmenu_pai, codigo FROM menus');
        if ($existe($u, 'empresa_modulos')) $u->exec('CREATE OR REPLACE VIEW vw_perfil_atrib_empresa_modulos AS SELECT codempresa, codigo_modulo FROM empresa_modulos WHERE ativo = 1');
        else $relatorio['universal']['modulos_por_empresa'] = 'sem empresa_modulos (migração 265): todas as empresas respondem os três módulos';
    }

    // Questionário (só cria se não houver versão publicada)
    $q = MigracaoCrud::primeiro($u, 'amb_questionario', ['codigo' => M268_QUESTIONARIO]);
    $publicada = $q ? count(MigracaoCrud::selecionar($u, 'amb_questionario_versao', ['codquestionario' => (int)$q['codquestionario'], 'status' => 'PUBLICADA'])) : 0;
    if ($publicada) $relatorio['universal']['questionario'] = 'já publicado (mantido como está)';
    elseif ($simular) $relatorio['universal']['questionario'] = 'será criado e publicado (versão 1)';
    else {
        $u->beginTransaction();
        $codq = $q ? (int)$q['codquestionario'] : MigracaoCrud::inserir($u, 'amb_questionario', ['codigo' => M268_QUESTIONARIO,
            'nome_pt' => 'Perfil de atribuições do usuário', 'nome_en' => 'User responsibilities profile', 'nome_es' => 'Perfil de atribuciones del usuario',
            'descricao_pt' => 'Atribuições do usuário na empresa: unidades, área, atividades por módulo e papel (executa, aprova, acompanha). Base para indicar responsáveis pelas tarefas.',
            'descricao_en' => 'User responsibilities in the company: units, area, activities per module and role (performs, approves, follows up). Basis for assigning task owners.',
            'descricao_es' => 'Atribuciones del usuario en la empresa: unidades, área, actividades por módulo y papel (ejecuta, aprueba, acompaña). Base para indicar responsables de las tareas.',
            'modulo' => 'USER_PROFILE', 'ativo' => 1]);
        $versao = MigracaoCrud::maximo($u, 'amb_questionario_versao', 'versao', ['codquestionario' => $codq]) + 1;
        $codv = MigracaoCrud::inserir($u, 'amb_questionario_versao', ['codquestionario' => $codq, 'versao' => $versao, 'status' => 'RASCUNHO',
            'instrucoes_pt' => 'Marque o que faz parte do seu trabalho. As respostas vão para a aprovação do seu gestor.',
            'instrucoes_en' => 'Check what is part of your work. Your answers go to your manager for approval.',
            'instrucoes_es' => 'Marque lo que forma parte de su trabajo. Las respuestas van a la aprobación de su gestor.']);
        $nPerg = 0;
        foreach (M268_BLOCOS as $ob => [$codBloco, $tpt, $ten, $tes, $perguntas]) {
            $codb = MigracaoCrud::inserir($u, 'amb_questionario_bloco', ['codversao' => $codv, 'codigo' => $codBloco, 'titulo_pt' => $tpt, 'titulo_en' => $ten, 'titulo_es' => $tes, 'ordem' => ($ob + 1) * 10]);
            foreach ($perguntas as $op => [$codP, $tipo, $obrig, $ppt, $pen, $pes, $opcoes]) {
                $codp = MigracaoCrud::inserir($u, 'amb_questionario_pergunta', ['codversao' => $codv, 'codbloco' => $codb, 'codigo' => $codP,
                    'texto_pt' => $ppt, 'texto_en' => $pen, 'texto_es' => $pes, 'tipo' => $tipo, 'obrigatoria' => $obrig, 'ordem' => ($op + 1) * 10]);
                $nPerg++;
                $lista = $opcoes === 'PAPEIS' ? M268_PAPEIS : ($opcoes ?? []);
                foreach ($lista as $oo => [$codO, $opt, $oen, $oes])
                    MigracaoCrud::inserir($u, 'amb_questionario_opcao', ['codpergunta' => $codp, 'codigo' => $codO, 'rotulo_pt' => $opt, 'rotulo_en' => $oen, 'rotulo_es' => $oes, 'valor' => $codO, 'ordem' => ($oo + 1) * 10]);
            }
        }
        MigracaoCrud::atualizar($u, 'amb_questionario_versao', ['codversao' => $codv], ['status' => 'PUBLICADA', 'publicado_em' => date('Y-m-d H:i:s')]);
        MigracaoCrud::inserir($u, 'amb_questionario_publicacao', ['codversao' => $codv, 'codigo_publicacao' => M268_QUESTIONARIO . '-V' . $versao]);
        $u->commit();
        $relatorio['universal']['questionario'] = "criado e publicado (versão $versao, " . count(M268_BLOCOS) . " blocos, $nPerg perguntas)";
    }
} catch (Throwable $e) {
    if ($u->inTransaction()) $u->rollBack();
    $relatorio['ok'] = false; $relatorio['universal']['erro'] = $e->getMessage();
}

// Menu da gestão: em Administração, mesmo codmenu no universal e nos clientes
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'perfis', 'permissoes', 'usuarios') AND TABLE_SCHEMA LIKE '%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 4 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
// Bancos de segurança das empresas migradas (ex.: ggdamericana_seguranca_dbp) não são bancos de trabalho: ficam de fora
// (e, se uma execução anterior criou ali a estrutura do perfil, ela é removida enquanto estiver vazia)
$seguranca = array_values(array_filter($bancos, static fn($b) => str_ends_with((string)$b, '_seguranca_dbp')));
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp') && preg_match('/^g[a-z0-9_]+_dbp$/', (string)$b)));
$clientes = [];
foreach ($bancos as $b) { $p = GreenPortalDatabase::connect($env, $b, 'GPT'); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $clientes[$b] = $p; }
$codmenu = (int)(MigracaoCrud::primeiro($u, 'menus', ['codigo' => M268_MENU])['codmenu'] ?? 0);
$pai = (int)(MigracaoCrud::primeiro($u, 'menus', ['codigo' => 'ADMINISTRACAO'])['codmenu'] ?? 0);
$pai = $pai < 1 ? null : $pai;
if ($codmenu < 1) {
    $codmenu = MigracaoCrud::maximo($u, 'menus', 'codmenu');
    foreach ($clientes as $p) $codmenu = max($codmenu, MigracaoCrud::maximo($p, 'menus', 'codmenu'));
    $codmenu++;
    $relatorio['menu'] = ($simular ? 'será criado' : 'criado') . " com codmenu $codmenu" . ($pai ? ' (Administração)' : ' (no topo do menu)');
} else $relatorio['menu'] = "já existe (codmenu $codmenu)";
$ordem = 99;
if ($pai) {
    $ordem = 1;
    foreach (MigracaoCrud::selecionar($u, 'menus', ['codmenu_pai' => $pai]) as $m) if ((int)$m['codmenu'] !== $codmenu) $ordem = max($ordem, (int)$m['ordem'] + 1);
}
$linhaMenu = ['codmenu' => $codmenu, 'codmenu_pai' => $pai, 'modulo' => 'ADMINISTRACAO', 'codigo' => M268_MENU, 'label' => 'Perfis de atribuições',
    'label_pt' => 'Perfis de atribuições', 'label_en' => 'Responsibility profiles', 'label_es' => 'Perfiles de atribuciones', 'url' => M268_URL,
    'nivel' => $pai ? 2 : 1, 'ordem' => max(1, $ordem), 'icone' => 'bi-person-check', 'ativo' => 1];

// Ajuda das telas (HTML)
$ajudas = [
    'perfil_atribuicoes' => [['Meu perfil de atribuições', 'My responsibilities profile', 'Mi perfil de atribuciones'], [
        '<p>O perfil de atribuições descreve o que você faz na empresa: as unidades em que atua, a sua área e, em cada módulo que você usa, as atividades das quais participa e o seu papel (<strong>executo</strong>, <strong>aprovo</strong> ou <strong>acompanho</strong>).</p><p>Com ele o sistema passa a indicar você como responsável pelas tarefas certas (licenças, monitoramentos, treinamentos, documentos de fornecedores...), montando a sua agenda, pendências e alertas.</p><h2 class="h5 mt-4">Como funciona</h2><ul><li>O questionário aparece ao entrar no sistema enquanto o perfil estiver pendente. Use <strong>Lembrar depois</strong> para responder em outro momento; ele volta no próximo acesso.</li><li>Aparecem só os módulos que a empresa usa e que o seu perfil de acesso vê.</li><li>Ao enviar, as respostas vão para a aprovação do seu gestor (cadastrado como superior em Segurança › Usuários). Ele pode aprovar ou devolver com uma observação.</li><li>O perfil aprovado vale por 12 meses. Ele é pedido de novo quando vence, quando o questionário muda ou quando você passa a ter acesso a um novo módulo.</li><li>Você pode revisar e reenviar as respostas a qualquer momento em Minha conta.</li></ul>',
        '<p>The responsibilities profile describes what you do in the company: the units you work at, your area and, in each module you use, the activities you take part in and your role (<strong>perform</strong>, <strong>approve</strong> or <strong>follow up</strong>).</p><p>With it the system can assign you the right tasks (licenses, monitoring, training, supplier documents...), building your agenda, pending items and alerts.</p><h2 class="h5 mt-4">How it works</h2><ul><li>The questionnaire opens when you sign in while your profile is pending. Use <strong>Remind me later</strong> to answer another time; it comes back at the next sign-in.</li><li>Only the modules your company uses and your access profile can see are shown.</li><li>When you submit, the answers go to your manager (registered as superior in Security › Users), who approves or returns them with a note.</li><li>An approved profile is valid for 12 months. It is requested again when it expires, when the questionnaire changes or when you get access to a new module.</li><li>You can review and resubmit your answers at any time in My account.</li></ul>',
        '<p>El perfil de atribuciones describe lo que usted hace en la empresa: las unidades en que actúa, su área y, en cada módulo que usa, las actividades en que participa y su papel (<strong>ejecuto</strong>, <strong>apruebo</strong> o <strong>acompaño</strong>).</p><p>Con él el sistema le asigna las tareas correctas (licencias, monitoreos, capacitaciones, documentos de proveedores...), armando su agenda, pendientes y alertas.</p><h2 class="h5 mt-4">Cómo funciona</h2><ul><li>El cuestionario aparece al entrar al sistema mientras el perfil esté pendiente. Use <strong>Recordar después</strong> para responder en otro momento; vuelve en el próximo acceso.</li><li>Solo aparecen los módulos que la empresa usa y que su perfil de acceso ve.</li><li>Al enviar, las respuestas van a la aprobación de su gestor (registrado como superior en Seguridad › Usuarios), que puede aprobar o devolver con una observación.</li><li>El perfil aprobado vale 12 meses. Se pide de nuevo cuando vence, cuando el cuestionario cambia o cuando usted obtiene acceso a un nuevo módulo.</li><li>Puede revisar y reenviar las respuestas en cualquier momento en Mi cuenta.</li></ul>',
    ]],
    'perfil_atribuicoes_gestao' => [['Perfis de atribuições', 'Responsibility profiles', 'Perfiles de atribuciones'], [
        '<p>Acompanhe o perfil de atribuições de cada usuário: quem nunca respondeu, quem está aguardando a sua aprovação, quem foi devolvido e quando cada perfil vence.</p><ul><li><strong>Gestor:</strong> vê a sua equipe (usuários que têm você como superior) e aprova ou devolve as respostas. Na devolução, escreva o que precisa ser corrigido; o usuário recebe um alerta.</li><li><strong>Administrador da empresa:</strong> faz também o papel de gestor: vê todos os usuários da empresa e aprova ou devolve qualquer perfil, inclusive de quem não tem gestor cadastrado.</li><li>O perfil <strong>Gestor</strong> nunca faz o papel do administrador da empresa: mesmo com acesso a esta tela, vê e decide somente a própria equipe.</li><li>Um perfil aprovado vale por 12 meses. Ninguém aprova o próprio perfil.</li><li>Cadastre o superior de cada usuário em Segurança › Usuários para que os envios cheguem ao gestor certo.</li></ul>',
        '<p>Follow each user\'s responsibilities profile: who never answered, who is waiting for your approval, who was returned and when each profile expires.</p><ul><li><strong>Manager:</strong> sees the team (users who have you as superior) and approves or returns the answers. When returning, write what needs to be fixed; the user gets an alert.</li><li><strong>Company administrator:</strong> also acts as manager: sees every user in the company and approves or returns any profile, including users with no manager.</li><li>The <strong>Manager</strong> profile never acts as company administrator: even with access to this screen, it sees and decides only its own team.</li><li>An approved profile is valid for 12 months. Nobody approves their own profile.</li><li>Register each user\'s superior in Security › Users so submissions reach the right manager.</li></ul>',
        '<p>Acompañe el perfil de atribuciones de cada usuario: quién nunca respondió, quién espera su aprobación, quién fue devuelto y cuándo vence cada perfil.</p><ul><li><strong>Gestor:</strong> ve su equipo (usuarios que lo tienen como superior) y aprueba o devuelve las respuestas. Al devolver, escriba lo que debe corregirse; el usuario recibe una alerta.</li><li><strong>Administrador de la empresa:</strong> también hace el papel de gestor: ve a todos los usuarios de la empresa y aprueba o devuelve cualquier perfil, incluso de quien no tiene gestor.</li><li>El perfil <strong>Gestor</strong> nunca hace el papel del administrador de la empresa: aunque tenga acceso a esta pantalla, ve y decide solo su propio equipo.</li><li>Un perfil aprobado vale 12 meses. Nadie aprueba su propio perfil.</li><li>Registre el superior de cada usuario en Seguridad › Usuarios para que los envíos lleguen al gestor correcto.</li></ul>',
    ]],
];

try {
    if (!$simular) {
        $gravarMenu($u, $linhaMenu);
        if ($existe($u, 'ajuda_transacoes')) {
            foreach ($ajudas as $transacao => [$titulos, $textos]) {
                MigracaoCrud::salvar($u, 'ajuda_transacoes', ['transacao' => $transacao], array_combine(['titulo_pt', 'titulo_en', 'titulo_es'], $titulos)
                    + array_combine(['texto_pt', 'texto_en', 'texto_es'], $textos) + ['ativo' => 1]);
            }
            $relatorio['universal']['ajuda'] = 'gravada';
        }
    }
} catch (Throwable $e) { $relatorio['ok'] = false; $relatorio['universal']['erro_menu_ajuda'] = $e->getMessage(); }

foreach ($seguranca as $b) {
    try {
        $p = GreenPortalDatabase::connect($env, $b, 'GPT'); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (!$existe($p, 'perfil_atribuicoes')) continue;
        if (MigracaoCrud::selecionar($p, 'perfil_atribuicoes', [], 1)) { $relatorio['bancos_ignorados'][$b] = 'banco de segurança: estrutura do perfil mantida (já tem dados)'; continue; }
        if (!$simular) {
            foreach (['DROP PROCEDURE IF EXISTS sp_perfil_atrib_enviar', 'DROP PROCEDURE IF EXISTS sp_perfil_atrib_decidir', 'DROP VIEW IF EXISTS vw_perfil_atribuicoes_itens_vigentes',
                      'DROP VIEW IF EXISTS vw_perfil_atribuicoes_situacao', 'DROP VIEW IF EXISTS vw_perfil_atribuicoes_grid', 'DROP TABLE IF EXISTS perfil_atribuicoes_itens', 'DROP TABLE IF EXISTS perfil_atribuicoes'] as $cmd) $p->exec($cmd);
        }
        $relatorio['bancos_ignorados'][$b] = 'banco de segurança: ignorado (estrutura criada por engano removida)';
    } catch (Throwable $e) { $relatorio['bancos_ignorados'][$b] = 'banco de segurança: ignorado (' . $e->getMessage() . ')'; }
}

// ---------------------------------------------------------------- Empresas
$sql = (string)file_get_contents(dirname(__DIR__) . '/database/268_perfil_atribuicoes.sql');
foreach ($clientes as $banco => $pdo) {
    $info = [];
    try {
        if (!in_array('codusuario_superior', $colunas($pdo, 'usuarios'), true)) throw new RuntimeException('usuarios.codusuario_superior não existe (rode antes a migração 232)');
        if (!$simular) {
            if ($criadas = MigracaoCrud::garantir($pdo)) $info['procedures_crud_criadas'] = $criadas;
            // Perfis: "Aplicar questionário" (nova: todos marcados, menos ADMINISTRADOR) e a coluna na view do grid de perfis
            if (!in_array('aplicar_questionario', $colunas($pdo, 'perfis'), true)) {
                $pdo->exec("ALTER TABLE `perfis` ADD COLUMN `aplicar_questionario` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Usuários deste perfil respondem o perfil de atribuições'");
                $desmarcarPerfis($pdo, ['ADMINISTRADOR', 'ADM_DA_EMPRESA']);
                $info['perfis'] = 'coluna Aplicar questionário criada (ADMINISTRADOR e ADM_DA_EMPRESA desmarcados)';
            }
            // Uma vez por banco: ADM_DA_EMPRESA também não responde o questionário (depois, vale o que for marcado na tela)
            if (MigracaoCrud::existe($pdo, 'registros_migracao') && !MigracaoCrud::primeiro($pdo, 'registros_migracao', ['codigo_migracao' => 'PERFIL_ATRIB_SEM_ADM_EMPRESA'])) {
                $n = $desmarcarPerfis($pdo, ['ADM_DA_EMPRESA']);
                MigracaoCrud::inserir($pdo, 'registros_migracao', ['codigo_migracao' => 'PERFIL_ATRIB_SEM_ADM_EMPRESA', 'origem' => 'perfis', 'destino' => 'perfis.aplicar_questionario',
                    'tipo' => 'DADOS', 'versao' => '4.0.1', 'status' => 'CONCLUIDA', 'quantidade_registros' => $n, 'iniciada_em' => date('Y-m-d H:i:s'),
                    'concluida_em' => date('Y-m-d H:i:s'), 'detalhes' => json_encode(['adm_da_empresa' => 'Aplicar questionário desmarcado', 'perfis_alterados' => $n], JSON_UNESCAPED_UNICODE)]);
                $info['adm_da_empresa'] = $n ? 'Aplicar questionário desmarcado' : 'já estava desmarcado';
            }
            $def = $existe($pdo, 'vw_perfis_grid') ? (string)($todos($pdo, 'SHOW CREATE VIEW `vw_perfis_grid`')[0]['Create View'] ?? '') : '';
            if ($def !== '' && stripos($def, 'aplicar_questionario') === false) {
                $select = (string)preg_replace('/^.*?\bVIEW\s+`?vw_perfis_grid`?\s+AS\s+/is', '', $def);
                $pdo->exec('CREATE OR REPLACE VIEW `vw_perfis_grid` AS SELECT v.*, COALESCE(px.`aplicar_questionario`, 1) AS `aplicar_questionario` FROM (' . $select . ') v LEFT JOIN `perfis` px ON px.`codperfil` = v.`codperfil`');
            }
            $info['perfis_que_aplicam'] = array_column(MigracaoCrud::selecionar($pdo, 'perfis', ['aplicar_questionario' => 1]), 'codigo');
            sort($info['perfis_que_aplicam']);
            $aplicarSql($pdo, $sql);
            $info['estrutura'] = 'tabelas, views e procedures gravadas';
            $gravarMenu($pdo, $linhaMenu);
            $liberados = [];
            $perfis = MigracaoCrud::selecionar($pdo, 'perfis');
            foreach (['permissoes', 'perfil_menus'] as $t) {
                $cols = MigracaoCrud::colunas($pdo, $t);
                if (!$cols) continue;
                $flags = array_fill_keys(array_values(array_intersect(['pode_visualizar', 'pode_editar'], $cols)), 1);
                foreach ($perfis as $pf) {
                    if (!in_array(strtoupper((string)$pf['codigo']), M268_PERFIS, true)) continue;
                    MigracaoCrud::salvar($pdo, $t, ['codperfil' => (int)$pf['codperfil'], 'codmenu' => $codmenu], $flags);
                    if ($t === 'permissoes') $liberados[] = $pf['codigo'];
                }
            }
            $info['gestao_liberada_para'] = $liberados;
            $situacao = MigracaoCrud::selecionar($pdo, 'vw_perfil_atribuicoes_situacao', ['aplicar_questionario' => 1]);
            $info['usuarios_que_respondem'] = count($situacao);
            $info['usuarios_sem_gestor'] = count(array_filter($situacao, static fn($s) => empty($s['codgestor'])));
            if (MigracaoCrud::existe($pdo, 'registros_migracao')) {
                MigracaoCrud::salvar($pdo, 'registros_migracao', ['codigo_migracao' => 'PERFIL_ATRIBUICOES_268'],
                    ['status' => 'CONCLUIDA', 'concluida_em' => date('Y-m-d H:i:s'), 'detalhes' => json_encode($info, JSON_UNESCAPED_UNICODE)],
                    ['origem' => 'questionário PERFIL_ATRIBUICOES', 'destino' => 'perfil_atribuicoes; sp_perfil_atrib_*', 'tipo' => 'ESTRUTURA', 'versao' => '4.0.1',
                     'quantidade_registros' => 0, 'iniciada_em' => date('Y-m-d H:i:s')]);
            }
        } else $info['simulacao'] = 'estrutura, menu e permissões serão aplicados';
    } catch (Throwable $e) { $relatorio['ok'] = false; $info['erro'] = $e->getMessage(); }
    $relatorio['bancos'][$banco] = $info;
}
echo json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
