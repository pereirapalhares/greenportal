<?php
declare(strict_types=1);

/*
 * Responsáveis por regra e avisos por módulo (migração 361; item 4 do 01_Mudancas_Gerais, GER-3 — LIC-50/51, MM-72/78,
 * CON-91/94, TRE-106/109, INF-121/124).
 *
 * Decisões do usuário (12/11/2026):
 *   - a regra é dinâmica: vale na hora para registros novos e antigos; nada é copiado para o registro;
 *   - os responsáveis da regra SOMAM com o Responsável do registro e com as atribuições (responsabilidade_atribuicoes);
 *   - Licenças: Tipo documental + unidade; Medição: Tipo de medição + unidade; Controles, Treinamentos e Infraestrutura: unidade;
 *   - 24/11/2026 (onda 2): Homologação de fornecedores por Tipo de fornecedor + unidade (migração 406);
 *     Requisitos legais por Categoria + unidade (migração 407);
 *   - os avisos (prazos, atribuição, alteração) são escolhidos em cada regra.
 *
 * Tabelas (database/361_responsaveis_regras.sql): resp_regras (AREA, REGISTRO_ID opcional = regra de um registro só, CODTIPO,
 * CODUNIDADE, usuário ou grupo, AVISO_PRAZO / AVISO_ATRIBUICAO / AVISO_ALTERACAO, ATIVO), resp_regras_avisos, resp_regras_controle.
 *
 * Views montadas aqui conforme as colunas de cada banco (views()):
 *   vw_resp_regras_itens          registro × tipo × unidade de cada área (Controles/Treinamentos/Infraestrutura: unidades das ligações)
 *   vw_resp_regras_usuarios       regras ativas com o grupo expandido nos usuários ativos
 *   vw_resp_regras_registros      registro × pessoa × regra (a regra aplicada aos registros)
 *   vw_resp_regras_cobertura      quantos registros e pessoas cada regra cobre (tela)
 *   vw_resp_acesso_<área>         quem responde por cada registro: Responsável do registro + atribuições (usuário e grupo) + regras
 *   vw_resp_acesso                as cinco juntas (AREA, REGISTRO_ID, CODUSUARIO_ACESSO, ORIGEM)
 *   vw_resp_minhas_<área>         colunas da view da tela do módulo + CODUSUARIO_ACESSO (telas "Minhas")
 *   vw_resp_regras_avisos_pendentes  avisos a dar (sp_resp_regras_avisar, serviço de Responsabilidades)
 * E vw_responsabilidade_efetiva passa a incluir as regras (ORIGEM 'REGRA'): a definição anterior fica em
 * vw_responsabilidade_efetiva_base (e em storage/logs), e é restaurada se a consulta ficar lenta ou com --remover.
 *
 * Textos das views em utf8mb4_unicode_ci explícito (como a 345), para não misturar collations entre bancos.
 */
final class ResponsaveisRegras
{
    public const TABELA = 'resp_regras';
    public const COLLATE = 'utf8mb4_unicode_ci';
    public const EFETIVA = 'vw_responsabilidade_efetiva';
    public const EFETIVA_BASE = 'vw_responsabilidade_efetiva_base';

    /**
     * Áreas: tabela, chave, descrição, coluna/unidade, tipo [coluna, tabela, valor, rótulo], menu do módulo, menu "Minhas",
     * menu novo (Responsáveis e avisos), view da tela do módulo, página das telas Minhas, entidades das atribuições,
     * ligações com unidade [tabela, chave, unidade], módulo da camada de responsabilidades, prazos, histórico (359).
     */
    public const AREAS = [
        'LICENCAS' => [
            'nome' => ['pt' => 'Licenças', 'en' => 'Licenses', 'es' => 'Licencias'],
            'tabela' => 'li_licencas', 'chave' => 'CODLICENCA', 'descricao' => 'DSCLICENCA', 'unidade' => 'codunidade',
            'tipo' => ['CODTIPODOCUMENTO', 'li_tipos_documentos', 'CODTIPODOCUMENTO', 'DSCTIPODOCUMENTO'],
            'tipo_rotulo' => ['pt' => 'Tipo documental', 'en' => 'Document type', 'es' => 'Tipo documental'],
            'menu' => 'LICENCAS', 'menu_minhas' => 'MINHAS_LICENCAS', 'menu_regras' => 'RESP_REGRAS_LICENCAS',
            'grid' => 'vw_licencas_grid', 'pagina' => '/principal/minhas_licencas.php', 'tela' => '/principal/responsaveis_regras_licencas.php',
            'entidades' => ['li_licencas'], 'ligacoes' => [], 'modulo_resp' => 'LICENCAS', 'prazos' => true, 'historico' => false,
            'responsavel' => ['codusuario'],
        ],
        'MEDICAO' => [
            'nome' => ['pt' => 'Medição e monitoramento', 'en' => 'Measurement and monitoring', 'es' => 'Medición y monitoreo'],
            'tabela' => 'mm_monitoramento', 'chave' => 'CODMONITORAMENTO', 'descricao' => 'PARAMETROS', 'unidade' => 'codunidade',
            'tipo' => ['codmedicao', 'mm_medicao', 'codmedicao', 'dscmedicao'],
            'tipo_rotulo' => ['pt' => 'Tipo de medição', 'en' => 'Measurement type', 'es' => 'Tipo de medición'],
            'menu' => 'MM_PLANO_MEDICAO_MONITORAMENTO', 'menu_minhas' => 'MM_PLANO_MEDICAO_MONITORAMENTO', 'menu_regras' => 'RESP_REGRAS_MEDICAO',
            'grid' => 'vw_medicao_monitoramento_monitoramento_grid', 'pagina' => '/principal/minhas_medicoes.php', 'tela' => '/principal/responsaveis_regras_medicao.php',
            'entidades' => ['mm_monitoramento'], 'ligacoes' => [], 'modulo_resp' => 'MEDICAO_MONITORAMENTO', 'prazos' => true, 'historico' => true,
            'responsavel' => ['codusuario'],
        ],
        'CONTROLES' => [
            'nome' => ['pt' => 'Controles', 'en' => 'Controls', 'es' => 'Controles'],
            'tabela' => 'documentos', 'chave' => 'CODDOCUMENTO', 'descricao' => 'TITULO', 'unidade' => null, 'tipo' => null, 'tipo_rotulo' => null,
            'menu' => 'CONTROLE_DOCUMENTOS', 'menu_minhas' => 'MEUS_CONTROLES', 'menu_regras' => 'RESP_REGRAS_CONTROLES',
            'grid' => 'vw_controle_documentos_grid', 'pagina' => '/principal/meus_controles.php', 'tela' => '/principal/responsaveis_regras_controles.php',
            'entidades' => ['documentos'], 'ligacoes' => [['documentosxunidades', 'CODDOCUMENTO', 'CODUNIDADE']],
            'modulo_resp' => 'CONTROLE', 'prazos' => false, 'historico' => true, 'responsavel' => ['codusuario', 'codusuario_responsavel', 'codresponsavel'],
        ],
        'TREINAMENTOS' => [
            'nome' => ['pt' => 'Treinamentos', 'en' => 'Training', 'es' => 'Capacitaciones'],
            'tabela' => 'ml_treinamentos', 'chave' => 'CODTREINAMENTO', 'descricao' => 'DSCTREINAMENTO', 'unidade' => null, 'tipo' => null, 'tipo_rotulo' => null,
            'menu' => 'MODULOLEGAL_TREINAMENTOS', 'menu_minhas' => 'MEUS_TREINAMENTOS', 'menu_regras' => 'RESP_REGRAS_TREINAMENTOS',
            'grid' => 'vw_modulolegal_treinamentos_grid', 'pagina' => '/principal/meus_treinamentos.php', 'tela' => '/principal/responsaveis_regras_treinamentos.php',
            'entidades' => ['ml_treinamentos'], 'ligacoes' => [['legislacaoxtreinamentos', 'CODTREINAMENTO', 'CODUNIDADE']],
            'modulo_resp' => 'TREINAMENTO', 'prazos' => false, 'historico' => true, 'responsavel' => ['codusuario', 'codusuario_responsavel', 'codresponsavel'],
        ],
        'INFRAESTRUTURA' => [
            'nome' => ['pt' => 'Infraestrutura', 'en' => 'Infrastructure', 'es' => 'Infraestructura'],
            'tabela' => 'ml_infraestrutura', 'chave' => 'CODINFRAESTRUTURA', 'descricao' => 'DSCINFRAESTRUTURA', 'unidade' => null, 'tipo' => null, 'tipo_rotulo' => null,
            'menu' => 'MODULOLEGAL_INFRAESTRUTURA', 'menu_minhas' => 'MINHAS_INFRAESTRUTURAS', 'menu_regras' => 'RESP_REGRAS_INFRAESTRUTURA',
            'grid' => 'vw_modulolegal_infraestrutura_grid', 'pagina' => '/principal/minhas_infraestruturas.php', 'tela' => '/principal/responsaveis_regras_infraestrutura.php',
            'entidades' => ['ml_infraestrutura', 'infraestrutura'],
            'ligacoes' => [['infraestruturaxunidades', 'CODINFRAESTRUTURA', 'CODUNIDADE'], ['legislacaoxinfraestruturas', 'CODINFRAESTRUTURA', 'CODUNIDADE']],
            'modulo_resp' => 'INFRAESTRUTURA', 'prazos' => false, 'historico' => true, 'responsavel' => ['codusuario', 'codusuario_responsavel', 'codresponsavel'],
        ],
        // Onda 2 da Revisão G (24/11/2026; HOM-132, HOM-138, HOM-140): fornecedores por Tipo de fornecedor (um fornecedor pode ter
        // vários tipos: fo_fornecedoresxtipofornecedores) + unidade. Só fornecedores ativados. As regras entram na
        // vw_responsabilidade_efetiva (ENTIDADE fo_fornecedores, módulo HOMOLOGACAO) e por ela chegam a Meus fornecedores, Meus
        // funcionários, pendências, agenda e ao acesso do usuário interno restrito (vw_homologacao_responsabilidade_fornecedor).
        'HOMOLOGACAO' => [
            'nome' => ['pt' => 'Homologação de fornecedores', 'en' => 'Supplier approval', 'es' => 'Homologación de proveedores'],
            'tabela' => 'fo_fornecedores', 'chave' => 'CODFORNECEDOR', 'descricao' => 'RAZAOSOCIAL', 'unidade' => 'codunidade',
            'tipo' => ['codtipofornecedor', 'fo_tipofornecedores', 'CODTIPOFORNECEDOR', 'DSCTIPO'],
            'tipo_ligacao' => ['fo_fornecedoresxtipofornecedores', 'codfornecedor', 'codtipofornecedor'],
            'onde' => "COALESCE(x.`ativadofor`, 'Sim') = 'Sim'",
            'tipo_rotulo' => ['pt' => 'Tipo de fornecedor', 'en' => 'Supplier type', 'es' => 'Tipo de proveedor'],
            'menu' => 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR', 'menu_minhas' => 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR', 'menu_regras' => 'RESP_REGRAS_HOMOLOGACAO',
            'grid' => 'vw_homologacao_fornecedores_grid', 'pagina' => '/principal/meus_fornecedores.php', 'tela' => '/principal/responsaveis_regras_homologacao.php',
            'entidades' => ['fo_fornecedores'], 'ligacoes' => [], 'modulo_resp' => 'HOMOLOGACAO', 'prazos' => true, 'historico' => true,
            'responsavel' => ['codusuario_responsavel_fornecedor'],
            // Prazos: a política de e-mails do legado já avisa o Responsável do fornecedor; aqui vão para atribuições e regras.
            'prazo_sem_registro' => true,
        ],
        // Onda 2 da Revisão G (24/11/2026; LEG-26, LEG-27, LEG-28 — migração 407): requisitos legais (lei × unidade da empresa,
        // legislacaoxempresa) por Categoria + unidade. Categorias: catálogo central glegislacao_dbp.legislacao_categoria (só o
        // ADMINISTRADOR mantém); vínculo lei × categoria da empresa (legislacaoxcategoria_empresa, a lei sem vínculo próprio usa o
        // central legislacaoxcategoria) — vw_legislacao_categorias_empresa. Uma lei com várias categorias entra na regra de cada uma.
        // As pessoas das regras entram em vw_ml_requisito_responsaveis (358) → Meus requisitos (RESPONSAVEL_IDS) e pendências.
        'LEGAL' => [
            'nome' => ['pt' => 'Requisitos legais', 'en' => 'Legal requirements', 'es' => 'Requisitos legales'],
            'tabela' => 'legislacaoxempresa', 'chave' => 'CODLEGISLACAXEMPRESA', 'descricao' => 'CODLEGISLACAO', 'unidade' => 'codunidade',
            'descricao_sql' => "COALESCE((SELECT CONCAT(lg.`CODIGO`, '') FROM `glegislacao_dbp`.`legislacao` lg WHERE lg.`CODLEGISLACAO` = x.`CODLEGISLACAO` LIMIT 1), CONCAT('Lei ', x.`CODLEGISLACAO`))",
            'tipo' => ['codcategorialegislacao', 'vw_legislacao_categorias', 'CODCATEGORIALEGISLACAO', 'NOME'],
            'tipo_ligacao' => ['vw_legislacao_categorias_empresa', 'CODLEGISLACAO', 'CODCATEGORIALEGISLACAO', 'CODLEGISLACAO'],
            'tipo_rotulo' => ['pt' => 'Categoria', 'en' => 'Category', 'es' => 'Categoría'],
            'menu' => 'REQUISITOS_LEGAIS', 'menu_minhas' => 'REQUISITOS_LEGAIS', 'menu_regras' => 'RESP_REGRAS_LEGAL',
            'grid' => 'vw_modulolegal_requisitos_grid', 'pagina' => '/principal/meus_requisitos.php', 'tela' => '/principal/responsaveis_regras_legal.php',
            'entidades' => ['legislacaoxempresa'], 'ligacoes' => [], 'modulo_resp' => 'REQUISITOS_LEGAIS', 'prazos' => true, 'historico' => false,
            'responsavel' => [], 'prazo_sem_registro' => true,
            // Muitos requisitos: as linhas do Legal só entram em vw_resp_regras_itens quando há regra ativa do Legal
            // (a view é usada por toda a vw_responsabilidade_efetiva).
            'so_com_regra' => true,
        ],
    ];

    /** Área do 345 (ResponsavelAutomatico::AREAS) → área da 361. */
    public const AREA_345 = ['LICENCAS' => 'LICENCAS', 'MONITORAMENTO' => 'MEDICAO', 'CONTROLES' => 'CONTROLES', 'TREINAMENTOS' => 'TREINAMENTOS', 'INFRAESTRUTURA' => 'INFRAESTRUTURA'];

    private static array $cacheCol = [];

    // ------------------------------------------------------------------ uso nas telas

    /** A migração 361 está aplicada no banco (tabela e views principais). */
    public static function disponivel(PDO $pdo): bool
    {
        return self::existe($pdo, self::TABELA) && self::existe($pdo, 'vw_resp_regras_registros');
    }

    /** View da tela "Minhas" da área (vw_resp_minhas_<área>) ou null se a 361 não estiver aplicada / a área não existir no banco. */
    public static function viewMinhas(PDO $pdo, string $area): ?string
    {
        $area = strtoupper($area);
        if (!isset(self::AREAS[$area])) return null;
        $v = 'vw_resp_minhas_' . strtolower($area);
        return self::existe($pdo, $v) && isset(self::colunas($pdo, $v)['codusuario_acesso']) ? $v : null;
    }

    public static function nome(string $area, string $idioma = 'pt'): string
    {
        $n = self::AREAS[strtoupper($area)]['nome'] ?? null;
        return $n ? (string)($n[$idioma] ?? $n['pt']) : $area;
    }

    /** Botão "Responsáveis" da grade (registro selecionado), ou '' quando a 361 não está aplicada. */
    public static function botao(PDO $pdo, string $area, string $titulo): string
    {
        $area = strtoupper($area);
        if (!isset(self::AREAS[$area]) || !self::disponivel($pdo)) return '';
        require_once __DIR__ . '/IdiomaService.php';
        $rot = IdiomaService::t('resp_regras.botao', ['pt' => 'Responsáveis do registro selecionado (somam com o Responsável e as regras do módulo)', 'en' => 'People responsible for the selected record (added to the Responsible and the module rules)', 'es' => 'Responsables del registro seleccionado (se suman al Responsable y a las reglas del módulo)']);
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return '<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-resp-registro disabled data-area="' . $h($area) . '" data-titulo="' . $h($titulo) . '"'
            . ' title="' . $h($rot) . '" aria-label="' . $h($rot) . '"><i class="bi bi-people"></i></button>';
    }

    // ------------------------------------------------------------------ metadados

    public static function existe(PDO $pdo, string $t): bool
    {
        return self::colunas($pdo, $t) !== [];
    }

    /** Colunas (minúsculas => nome real) de uma tabela/view do banco atual. */
    public static function colunas(PDO $pdo, string $t, bool $recarregar = false): array
    {
        $banco = spl_object_id($pdo) . ':' . strtolower($t);
        if (!$recarregar && isset(self::$cacheCol[$banco])) return self::$cacheCol[$banco];
        $s = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $s->execute([$t]);
        $r = [];
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $c) $r[strtolower((string)$c)] = (string)$c;
        $s->closeCursor();
        return self::$cacheCol[$banco] = $r;
    }

    public static function limparCache(): void
    {
        self::$cacheCol = [];
    }

    private static function q(string $c): string
    {
        return '`' . str_replace('`', '', $c) . '`';
    }

    private static function txt(string $expr, int $n = 300): string
    {
        return "CONVERT(LEFT(COALESCE($expr, ''), $n) USING utf8mb4) COLLATE " . self::COLLATE;
    }

    private static function lit(string $s): string
    {
        return "CONVERT('" . str_replace("'", "''", $s) . "' USING utf8mb4) COLLATE " . self::COLLATE;
    }

    /** Filtro da versão ativa das licenças (alias da tabela), ou '' quando a tabela não tem versões. */
    private static function licencaAtiva(PDO $pdo, string $alias): string
    {
        return isset(self::colunas($pdo, 'li_licencas')['status_versao']) ? "COALESCE($alias.`STATUS_VERSAO`, '') IN ('', 'ATIVA')" : '';
    }

    /** Áreas com a tabela principal no banco: área => situação ('ok' ou motivo). */
    public static function areas(PDO $pdo): array
    {
        $r = [];
        foreach (self::AREAS as $area => $a) {
            $c = self::colunas($pdo, $a['tabela']);
            $r[$area] = isset($c[strtolower($a['chave'])]) ? 'ok' : 'sem a tabela ' . $a['tabela'];
        }
        return $r;
    }

    // ------------------------------------------------------------------ views

    /**
     * DDL das views da 361 que não dependem da efetiva (ordem de criação), conforme as colunas do banco.
     * @return array<string,string> nome => CREATE OR REPLACE VIEW
     */
    public static function viewsRegras(PDO $pdo): array
    {
        $areas = array_keys(array_filter(self::areas($pdo), static fn($s) => $s === 'ok'));
        $v = [];
        // 1. itens
        $partes = [];
        foreach ($areas as $area) {
            $a = self::AREAS[$area];
            $c = self::colunas($pdo, $a['tabela']);
            $id = 'x.' . self::q($c[strtolower($a['chave'])]);
            $desc = isset($c[strtolower($a['descricao'])]) ? 'x.' . self::q($c[strtolower($a['descricao'])]) : "CONCAT('#', $id)";
            if (!empty($a['descricao_sql'])) $desc = $a['descricao_sql'];
            if ($area === 'CONTROLES' && isset($c['codigo'], $c['titulo'])) $desc = "COALESCE(NULLIF(TRIM(x.`TITULO`), ''), x.`CODIGO`)";
            $tipo = 'NULL';
            if ($a['tipo'] && isset($c[strtolower($a['tipo'][0])])) $tipo = 'x.' . self::q($c[strtolower($a['tipo'][0])]);
            $onde = $area === 'LICENCAS' ? self::licencaAtiva($pdo, 'x') : '';
            if (!empty($a['onde']) && isset($c['ativadofor'])) $onde = $a['onde'];
            if (!empty($a['so_com_regra'])) $onde = ($onde !== '' ? "($onde) AND " : '') . "EXISTS (SELECT 1 FROM `resp_regras` rg WHERE rg.`AREA` = '$area' AND rg.`ATIVO` = 1)";
            if ($a['unidade'] !== null) {
                $un = isset($c[strtolower($a['unidade'])]) ? 'x.' . self::q($c[strtolower($a['unidade'])]) : 'NULL';
                $de = 'FROM ' . self::q($a['tabela']) . ' x';
                // Tipo numa tabela de ligação (um registro com vários tipos): uma linha por tipo; sem tipo, uma linha com tipo NULL.
                if (!empty($a['tipo_ligacao'])) {
                    [$lt, $lk, $lv] = $a['tipo_ligacao'];
                    $lx = $a['tipo_ligacao'][3] ?? null; // coluna do registro ligada à tabela de tipos (padrão: a chave)
                    $lc = self::colunas($pdo, $lt);
                    if (isset($lc[strtolower($lk)], $lc[strtolower($lv)]) && ($lx === null || isset($c[strtolower($lx)]))) {
                        $de .= ' LEFT JOIN ' . self::q($lt) . ' tl ON tl.' . self::q($lc[strtolower($lk)]) . ' = ' . ($lx === null ? $id : 'x.' . self::q($c[strtolower($lx)]));
                        $tipo = 'tl.' . self::q($lc[strtolower($lv)]);
                    }
                }
            } else {
                $lig = [];
                foreach ($a['ligacoes'] as [$t, $k, $u]) {
                    $lc = self::colunas($pdo, $t);
                    if (isset($lc[strtolower($k)], $lc[strtolower($u)])) $lig[] = 'SELECT ' . self::q($lc[strtolower($k)]) . ' AS `K`, ' . self::q($lc[strtolower($u)]) . ' AS `U` FROM ' . self::q($t) . ' WHERE ' . self::q($lc[strtolower($u)]) . ' IS NOT NULL';
                }
                $un = $lig ? 'lu.`U`' : 'NULL';
                $de = 'FROM ' . self::q($a['tabela']) . ' x' . ($lig ? ' LEFT JOIN (' . implode(' UNION ', $lig) . ") lu ON lu.`K` = $id" : '');
            }
            $partes[] = 'SELECT ' . self::lit($area) . " AS `AREA`, CAST($id AS SIGNED) AS `REGISTRO_ID`, CAST($tipo AS SIGNED) AS `CODTIPO`, CAST($un AS SIGNED) AS `CODUNIDADE`, "
                . self::txt($desc) . " AS `DESCRICAO` $de" . ($onde !== '' ? " WHERE $onde" : '');
        }
        $v['vw_resp_regras_itens'] = 'CREATE OR REPLACE VIEW `vw_resp_regras_itens` AS ' . ($partes ? implode("\nUNION ALL\n", $partes)
            : 'SELECT ' . self::lit('') . ' AS `AREA`, CAST(0 AS SIGNED) AS `REGISTRO_ID`, CAST(NULL AS SIGNED) AS `CODTIPO`, CAST(NULL AS SIGNED) AS `CODUNIDADE`, ' . self::lit('') . ' AS `DESCRICAO` FROM DUAL WHERE 1 = 0');

        // 2. regras × usuários ativos (grupo expandido)
        $u = self::colunas($pdo, 'usuarios');
        $ativo = isset($u['ativo']) ? ' AND u.`ativo` = 1' : '';
        $cols = 'r.`CODREGRA`, r.`AREA`, r.`REGISTRO_ID`, r.`CODTIPO`, r.`CODUNIDADE`, r.`TIPO_DESTINO`, r.`CODGRUPO`, r.`AVISO_PRAZO`, r.`AVISO_ATRIBUICAO`, r.`AVISO_ALTERACAO`';
        $porGrupo = self::existe($pdo, 'vw_responsabilidade_grupoxusuario')
            ? "SELECT $cols, CAST(gx.`CODUSUARIO` AS SIGNED) AS `CODUSUARIO` FROM `resp_regras` r JOIN `vw_responsabilidade_grupoxusuario` gx ON gx.`CODGRUPO` = r.`CODGRUPO`
   JOIN `usuarios` u ON u.`codusuario` = gx.`CODUSUARIO`$ativo WHERE r.`ATIVO` = 1 AND r.`TIPO_DESTINO` = 'GRUPO'"
            : "SELECT $cols, CAST(u.`codusuario` AS SIGNED) AS `CODUSUARIO` FROM `resp_regras` r JOIN `usuarios` u ON u.`codgrupo` = r.`CODGRUPO`$ativo"
              . (isset(self::colunas($pdo, 'grupos')['ativo']) ? ' JOIN `grupos` g ON g.`codgrupo` = r.`CODGRUPO` AND g.`ativo` = 1' : '')
              . " WHERE r.`ATIVO` = 1 AND r.`TIPO_DESTINO` = 'GRUPO'";
        $v['vw_resp_regras_usuarios'] = "CREATE OR REPLACE VIEW `vw_resp_regras_usuarios` AS
SELECT $cols, CAST(r.`CODUSUARIO` AS SIGNED) AS `CODUSUARIO` FROM `resp_regras` r JOIN `usuarios` u ON u.`codusuario` = r.`CODUSUARIO`$ativo
 WHERE r.`ATIVO` = 1 AND r.`TIPO_DESTINO` = 'USUARIO'
UNION
$porGrupo";

        // 2b. pessoas que podem ser escolhidas na tela (usuários ativos da empresa; sem as contas de fornecedor)
        $v['vw_resp_regras_pessoas'] = self::viewPessoas($pdo);

        // 3. registros cobertos
        $v['vw_resp_regras_registros'] = "CREATE OR REPLACE VIEW `vw_resp_regras_registros` AS
SELECT DISTINCT ru.`CODREGRA`, i.`AREA`, i.`REGISTRO_ID`, ru.`CODUSUARIO`, ru.`CODGRUPO`, ru.`AVISO_PRAZO`, ru.`AVISO_ATRIBUICAO`, ru.`AVISO_ALTERACAO`, i.`DESCRICAO`
  FROM `vw_resp_regras_usuarios` ru
  JOIN `vw_resp_regras_itens` i ON i.`AREA` = ru.`AREA`
   AND (ru.`REGISTRO_ID` IS NULL OR i.`REGISTRO_ID` = ru.`REGISTRO_ID`)
   AND (ru.`CODTIPO` IS NULL OR i.`CODTIPO` = ru.`CODTIPO`)
   AND (ru.`CODUNIDADE` IS NULL OR i.`CODUNIDADE` = ru.`CODUNIDADE`)";
        $v['vw_resp_regras_cobertura'] = "CREATE OR REPLACE VIEW `vw_resp_regras_cobertura` AS
SELECT `CODREGRA`, COUNT(DISTINCT `REGISTRO_ID`) AS `REGISTROS`, COUNT(DISTINCT `CODUSUARIO`) AS `PESSOAS` FROM `vw_resp_regras_registros` GROUP BY `CODREGRA`";
        return $v;
    }

    /**
     * DDL das views de acesso, telas "Minhas" e avisos (depois da efetiva). $atribuicoes = view com as atribuições SEM as regras
     * (vw_responsabilidade_efetiva_base, ou a própria efetiva quando ela não foi alterada) ou null se o banco não tem a camada.
     * @return array{0: array<string,string>, 1: array<string,string>} [nome => DDL, área => situação]
     */
    public static function viewsAcesso(PDO $pdo, ?string $atribuicoes): array
    {
        $v = []; $sit = []; $todas = [];
        $areas = array_keys(array_filter(self::areas($pdo), static fn($s) => $s === 'ok'));
        foreach ($areas as $area) {
            $a = self::AREAS[$area];
            $c = self::colunas($pdo, $a['tabela']);
            $chave = $c[strtolower($a['chave'])];
            $partes = [];
            $resp = null;
            foreach ($a['responsavel'] as $col) if (isset($c[$col])) { $resp = $c[$col]; break; }
            if ($resp !== null) $partes[] = 'SELECT CAST(x.' . self::q($chave) . ' AS SIGNED) AS `REGISTRO_ID`, CAST(x.' . self::q($resp) . ' AS SIGNED) AS `CODUSUARIO_ACESSO`, '
                . self::lit('REGISTRO') . ' AS `ORIGEM` FROM ' . self::q($a['tabela']) . ' x WHERE COALESCE(x.' . self::q($resp) . ', 0) > 0';
            if ($atribuicoes !== null) {
                $ent = implode(', ', array_map(static fn($e) => "'" . $e . "'", $a['entidades']));
                $partes[] = "SELECT CAST(e.`REGISTRO_ID` AS SIGNED) AS `REGISTRO_ID`, CAST(e.`CODUSUARIO_DESTINO` AS SIGNED) AS `CODUSUARIO_ACESSO`, " . self::lit('ATRIBUICAO') . ' AS `ORIGEM` FROM ' . self::q($atribuicoes)
                    . " e WHERE e.`ENTIDADE` IN ($ent) AND e.`REGISTRO_ID` REGEXP '^[0-9]+$' AND COALESCE(e.`CODUSUARIO_DESTINO`, 0) > 0";
            }
            $partes[] = "SELECT r.`REGISTRO_ID` AS `REGISTRO_ID`, r.`CODUSUARIO` AS `CODUSUARIO_ACESSO`, " . self::lit('REGRA') . " AS `ORIGEM` FROM `vw_resp_regras_registros` r WHERE r.`AREA` = '$area'";
            $corpo = implode("\nUNION\n", $partes);
            if ($area === 'LICENCAS' && ($ativa = self::licencaAtiva($pdo, 'l')) !== '')
                $corpo = "SELECT a.`REGISTRO_ID`, a.`CODUSUARIO_ACESSO`, a.`ORIGEM` FROM ($corpo) a JOIN `li_licencas` l ON l.`CODLICENCA` = a.`REGISTRO_ID` WHERE $ativa";
            $nome = 'vw_resp_acesso_' . strtolower($area);
            $v[$nome] = "CREATE OR REPLACE VIEW `$nome` AS\n$corpo";
            $todas[] = 'SELECT ' . self::lit($area) . " AS `AREA`, `REGISTRO_ID`, `CODUSUARIO_ACESSO`, `ORIGEM` FROM `$nome`";
            // tela "Minhas": colunas da view da tela do módulo + CODUSUARIO_ACESSO
            $g = self::colunas($pdo, $a['grid']);
            if (!isset($g[strtolower($a['chave'])])) { $sit[$area] = 'acesso ok; sem a view ' . $a['grid'] . ' (tela Minhas continua como estava)'; continue; }
            $lista = implode(', ', array_map(static fn($col) => 'g.' . self::q($col), array_values(array_filter($g, static fn($col, $k) => $k !== 'codusuario_acesso', ARRAY_FILTER_USE_BOTH))));
            $minhas = 'vw_resp_minhas_' . strtolower($area);
            $v[$minhas] = "CREATE OR REPLACE VIEW `$minhas` AS
SELECT $lista, a.`CODUSUARIO_ACESSO`
  FROM " . self::q($a['grid']) . " g
  JOIN (SELECT DISTINCT `REGISTRO_ID`, `CODUSUARIO_ACESSO` FROM `$nome`) a ON a.`REGISTRO_ID` = g." . self::q($g[strtolower($a['chave'])]);
            $sit[$area] = 'ok' . ($resp === null ? ' (sem coluna de Responsável no registro)' : '') . ($atribuicoes === null ? ' (sem atribuições)' : '');
        }
        $v['vw_resp_acesso'] = 'CREATE OR REPLACE VIEW `vw_resp_acesso` AS ' . ($todas ? implode("\nUNION ALL\n", $todas)
            : 'SELECT ' . self::lit('') . ' AS `AREA`, CAST(0 AS SIGNED) AS `REGISTRO_ID`, CAST(0 AS SIGNED) AS `CODUSUARIO_ACESSO`, ' . self::lit('') . ' AS `ORIGEM` FROM DUAL WHERE 1 = 0');
        $v['vw_resp_regras_avisos_pendentes'] = self::viewAvisos($pdo, $areas);
        // Requisitos legais (migração 407): as pessoas das regras do Legal entram em vw_ml_requisito_responsaveis (migração 358),
        // de onde saem o RESPONSAVEL_IDS de vw_modulolegal_requisitos_grid (Meus requisitos) e as pendências.
        if (in_array('LEGAL', $areas, true) && self::existe($pdo, 'vw_ml_requisito_responsaveis') && self::existe($pdo, 'legislacaoxsituacao')) {
            $efetiva = self::existe($pdo, self::EFETIVA) ? "
UNION
SELECT s.`CODLEGISLACAO`, s.`codunidade`, e.`CODUSUARIO_DESTINO`
  FROM `vw_responsabilidade_efetiva` e
  JOIN `legislacaoxsituacao` s ON s.`CODSITUACAO` = CAST(e.`REGISTRO_ID` AS UNSIGNED)
 WHERE e.`ENTIDADE` = 'legislacaoxsituacao' AND COALESCE(e.`CODUSUARIO_DESTINO`, 0) > 0" : '';
            $v['vw_ml_requisito_responsaveis'] = "CREATE OR REPLACE VIEW `vw_ml_requisito_responsaveis` AS
SELECT s.`CODLEGISLACAO`, s.`codunidade` AS `CODUNIDADE`, s.`CODUSUARIO`
  FROM `legislacaoxsituacao` s
 WHERE COALESCE(s.`CODUSUARIO`, 0) > 0$efetiva
UNION
SELECT x.`CODLEGISLACAO`, x.`codunidade`, ru.`CODUSUARIO`
  FROM `vw_resp_regras_usuarios` ru
  JOIN `legislacaoxempresa` x ON (ru.`REGISTRO_ID` IS NULL OR x.`CODLEGISLACAXEMPRESA` = ru.`REGISTRO_ID`)
   AND (ru.`CODUNIDADE` IS NULL OR x.`codunidade` = ru.`CODUNIDADE`)
 WHERE ru.`AREA` = 'LEGAL'
   AND (ru.`CODTIPO` IS NULL" . (self::existe($pdo, 'vw_legislacao_categorias_empresa') ? " OR EXISTS (SELECT 1 FROM `vw_legislacao_categorias_empresa` c
         WHERE c.`CODLEGISLACAO` = x.`CODLEGISLACAO` AND c.`CODCATEGORIALEGISLACAO` = ru.`CODTIPO`)" : '') . ")";
        }
        return [$v, $sit];
    }

    /** Usuários ativos que não são contas de fornecedor (perfil FORNECEDOR, FORNECEDOR_GESTOR ou FORNECEDORES). */
    private static function viewPessoas(PDO $pdo): string
    {
        $u = self::colunas($pdo, 'usuarios');
        $onde = isset($u['ativo']) ? ['u.`ativo` = 1'] : [];
        $pf = self::colunas($pdo, 'perfis');
        $colPerfil = $pf['codigo'] ?? ($pf['nome'] ?? null);
        if ($colPerfil !== null && isset($u['codperfil'], $pf['codperfil']))
            $onde[] = "NOT EXISTS (SELECT 1 FROM `perfis` p WHERE p.`codperfil` = u.`codperfil`
     AND UPPER(REPLACE(REPLACE(TRIM(p." . self::q($colPerfil) . "), ' ', '_'), '-', '_')) IN ('FORNECEDOR', 'FORNECEDOR_GESTOR', 'FORNECEDORES'))";
        // (só pelo perfil: funcionários da empresa também ficam ligados a fornecedores na Homologação)
        $nome = isset($u['nome']) ? "COALESCE(NULLIF(TRIM(u.`nome`), ''), u.`userid`)" : 'u.`userid`';
        return 'CREATE OR REPLACE VIEW `vw_resp_regras_pessoas` AS SELECT CAST(u.`codusuario` AS SIGNED) AS `CODUSUARIO`, ' . self::txt($nome, 200) . ' AS `NOME` FROM `usuarios` u'
            . ($onde ? ' WHERE ' . implode(' AND ', $onde) : '');
    }

    /** Avisos a dar: atribuição; prazos (vencimento em 30 dias, reação nos últimos 7 dias, medição prevista em 7 dias); alteração (359). */
    private static function viewAvisos(PDO $pdo, array $areas): string
    {
        $p = [];
        $sel = static fn(string $tipo, string $chave, string $detalhe): string => 'SELECT r.`CODUSUARIO`, r.`AREA`, r.`REGISTRO_ID`, ' . self::lit($tipo) . ' AS `TIPO`, '
            . self::txt($chave, 40) . ' AS `CHAVE`, r.`DESCRICAO`, ' . self::txt($detalhe, 500) . ' AS `DETALHE`';
        $p[] = $sel('ATRIBUICAO', "''", "''") . ' FROM `vw_resp_regras_registros` r WHERE r.`AVISO_ATRIBUICAO` = 1';
        // Prazos (decisão de 16/11/2026): vão SEMPRE para quem responde pelo registro (Responsável do registro e atribuições,
        // de usuário ou grupo) e também para as pessoas das regras com "Prazos" marcado.
        $quem = static fn(string $area): string => "(SELECT `REGISTRO_ID`, `CODUSUARIO` FROM `vw_resp_regras_registros` WHERE `AREA` = '$area' AND `AVISO_PRAZO` = 1
   UNION SELECT `REGISTRO_ID`, `CODUSUARIO_ACESSO` FROM `vw_resp_acesso_" . strtolower($area) . "` WHERE `ORIGEM` IN (" . (empty(self::AREAS[$area]['prazo_sem_registro']) ? "'REGISTRO', " : '') . "'ATRIBUICAO'))";
        $prazo = static function (string $area, string $tipo, string $tabela, string $chave, string $desc, string $col, string $detalhe, string $janela) use ($quem): string {
            return 'SELECT CAST(d.`CODUSUARIO` AS SIGNED) AS `CODUSUARIO`, ' . self::lit($area) . ' AS `AREA`, CAST(d.`REGISTRO_ID` AS SIGNED) AS `REGISTRO_ID`, '
                . self::lit($tipo) . ' AS `TIPO`, ' . self::txt("DATE_FORMAT(x.`$col`, '%Y-%m-%d')", 40) . ' AS `CHAVE`, ' . self::txt($desc) . ' AS `DESCRICAO`, '
                . self::txt("CONCAT('$detalhe ', DATE_FORMAT(x.`$col`, '%d/%m/%Y'))", 500) . ' AS `DETALHE`'
                . "
  FROM " . $quem($area) . " d JOIN `$tabela` x ON x.`$chave` = d.`REGISTRO_ID`
 WHERE $janela";
        };
        if (in_array('LICENCAS', $areas, true)) {
            $c = self::colunas($pdo, 'li_licencas');
            $desc = isset($c['dsclicenca']) ? 'x.`DSCLICENCA`' : "CONCAT('#', x.`CODLICENCA`)";
            if (isset($c['data_validade']))
                $p[] = $prazo('LICENCAS', 'VENCIMENTO', 'li_licencas', 'CODLICENCA', $desc, 'DATA_VALIDADE', 'vence em', 'x.`DATA_VALIDADE` BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY');
            if (isset($c['data_reacao']))
                $p[] = $prazo('LICENCAS', 'REACAO', 'li_licencas', 'CODLICENCA', $desc, 'DATA_REACAO', 'reação em', 'DATE(x.`DATA_REACAO`) BETWEEN CURDATE() - INTERVAL 7 DAY AND CURDATE()');
        }
        if (in_array('MEDICAO', $areas, true)) {
            $c = self::colunas($pdo, 'mm_monitoramento');
            $desc = isset($c['parametros']) ? 'x.`PARAMETROS`' : "CONCAT('#', x.`CODMONITORAMENTO`)";
            if (isset($c['data_reacao']))
                $p[] = $prazo('MEDICAO', 'REACAO', 'mm_monitoramento', 'CODMONITORAMENTO', $desc, 'DATA_REACAO', 'reação em', 'DATE(x.`DATA_REACAO`) BETWEEN CURDATE() - INTERVAL 7 DAY AND CURDATE()');
            if (isset($c['data_medicao']))
                $p[] = $prazo('MEDICAO', 'MEDICAO', 'mm_monitoramento', 'CODMONITORAMENTO', $desc, 'DATA_MEDICAO', 'medição prevista em', 'DATE(x.`DATA_MEDICAO`) BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY');
        }
        if (in_array('HOMOLOGACAO', $areas, true)) {
            $c = self::colunas($pdo, 'fo_fornecedores');
            $desc = "COALESCE(NULLIF(TRIM(x.`NOMEFANTASIA`), ''), NULLIF(TRIM(x.`RAZAOSOCIAL`), ''), CONCAT('Fornecedor ', x.`CODFORNECEDOR`))";
            $ativo = isset($c['ativadofor']) ? " AND COALESCE(x.`ativadofor`, 'Sim') = 'Sim'" : '';
            if (isset($c['data_da_proxima_avaliacao']))
                $p[] = $prazo('HOMOLOGACAO', 'AVALIACAO', 'fo_fornecedores', 'CODFORNECEDOR', $desc, 'data_da_proxima_avaliacao', 'próxima avaliação em', 'x.`data_da_proxima_avaliacao` BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY' . $ativo);
            // Homologação por tipo de fornecedor (validade do tipo) e documentos (critérios) que vencem em até 30 dias
            $lt = self::colunas($pdo, 'fo_fornecedoresxtipofornecedores');
            if (isset($lt['datavalidade'], $lt['codfornecedor'])) {
                $p[] = 'SELECT CAST(d.`CODUSUARIO` AS SIGNED) AS `CODUSUARIO`, ' . self::lit('HOMOLOGACAO') . ' AS `AREA`, CAST(d.`REGISTRO_ID` AS SIGNED) AS `REGISTRO_ID`, '
                    . self::lit('VENCIMENTO') . ' AS `TIPO`, ' . self::txt("CONCAT(t.`codtipofornecedor`, '|', DATE_FORMAT(t.`datavalidade`, '%Y-%m-%d'))", 40) . ' AS `CHAVE`, ' . self::txt($desc) . ' AS `DESCRICAO`, '
                    . self::txt("CONCAT('homologação', IF(tf.`DSCTIPO` IS NULL, '', CONCAT(' (', tf.`DSCTIPO`, ')')), ' vence em ', DATE_FORMAT(t.`datavalidade`, '%d/%m/%Y'))", 500) . " AS `DETALHE`
  FROM " . $quem('HOMOLOGACAO') . " d JOIN `fo_fornecedores` x ON x.`CODFORNECEDOR` = d.`REGISTRO_ID`
  JOIN `fo_fornecedoresxtipofornecedores` t ON t.`codfornecedor` = x.`CODFORNECEDOR`
  LEFT JOIN `fo_tipofornecedores` tf ON tf.`CODTIPOFORNECEDOR` = t.`codtipofornecedor`
 WHERE t.`datavalidade` BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY$ativo";
            }
            $cr = self::colunas($pdo, 'fo_fornecedorxcriterios');
            if (isset($cr['datadevalidade'], $cr['codfornecedor'], $cr['codfornecedorxcriterios'])) {
                $p[] = 'SELECT CAST(d.`CODUSUARIO` AS SIGNED) AS `CODUSUARIO`, ' . self::lit('HOMOLOGACAO') . ' AS `AREA`, CAST(d.`REGISTRO_ID` AS SIGNED) AS `REGISTRO_ID`, '
                    . self::lit('DOCUMENTOS') . ' AS `TIPO`, ' . self::txt("CONCAT(k.`codfornecedorxcriterios`, '|', DATE_FORMAT(k.`datadevalidade`, '%Y-%m-%d'))", 40) . ' AS `CHAVE`, ' . self::txt($desc) . ' AS `DESCRICAO`, '
                    . self::txt("CONCAT('documento vence em ', DATE_FORMAT(k.`datadevalidade`, '%d/%m/%Y'))", 500) . " AS `DETALHE`
  FROM " . $quem('HOMOLOGACAO') . " d JOIN `fo_fornecedores` x ON x.`CODFORNECEDOR` = d.`REGISTRO_ID`
  JOIN `fo_fornecedorxcriterios` k ON k.`codfornecedor` = x.`CODFORNECEDOR`
 WHERE k.`datadevalidade` BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY
   AND COALESCE(k.`situacao`, '') NOT IN ('NA', 'Não enviar ao fornecedor')$ativo";
            }
        }
        if (in_array('LEGAL', $areas, true) && isset(self::colunas($pdo, 'legislacaoxsituacao')['data_de_vencimento_avaliacao'])) {
            $desc = self::AREAS['LEGAL']['descricao_sql'];
            $p[] = 'SELECT CAST(d.`CODUSUARIO` AS SIGNED) AS `CODUSUARIO`, ' . self::lit('LEGAL') . ' AS `AREA`, CAST(d.`REGISTRO_ID` AS SIGNED) AS `REGISTRO_ID`, '
                . self::lit('AVALIACAO') . ' AS `TIPO`, ' . self::txt("DATE_FORMAT(s.`data_de_vencimento_avaliacao`, '%Y-%m-%d')", 40) . ' AS `CHAVE`, ' . self::txt($desc) . ' AS `DESCRICAO`, '
                . self::txt("CONCAT('próxima avaliação em ', DATE_FORMAT(s.`data_de_vencimento_avaliacao`, '%d/%m/%Y'))", 500) . " AS `DETALHE`
  FROM " . $quem('LEGAL') . " d JOIN `legislacaoxempresa` x ON x.`CODLEGISLACAXEMPRESA` = d.`REGISTRO_ID`
  JOIN `legislacaoxsituacao` s ON s.`CODLEGISLACAO` = x.`CODLEGISLACAO` AND s.`codunidade` = x.`codunidade`
 WHERE s.`data_de_vencimento_avaliacao` BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY";
        }
        // Alteração feita por outra pessoa (histórico obrigatório da 359), entre a última marca (HIST) e o limite desta execução (HIST_ATE)
        if (self::existe($pdo, 'historico_alteracoes')) {
            $tabs = [];
            foreach ($areas as $area) if (self::AREAS[$area]['historico']) $tabs[$area] = self::AREAS[$area]['tabela'];
            if ($tabs) {
                $caso = 'CASE r.`AREA`'; foreach ($tabs as $ar => $t) $caso .= " WHEN '$ar' THEN '$t'"; $caso .= ' END';
                $maximo = '(SELECT COALESCE(MAX(`codhistorico`), 0) FROM `historico_alteracoes`)';
                $marca = static fn(string $k): string => "(SELECT CAST(c.`VALOR` AS UNSIGNED) FROM `resp_regras_controle` c WHERE c.`CHAVE` = '$k')";
                $p[] = 'SELECT r.`CODUSUARIO`, r.`AREA`, r.`REGISTRO_ID`, ' . self::lit('ALTERACAO') . ' AS `TIPO`, ' . self::txt('CAST(MAX(h.`codhistorico`) AS CHAR)', 40) . ' AS `CHAVE`, r.`DESCRICAO`, '
                    . self::txt("CONCAT('alterado em ', DATE_FORMAT(MAX(h.`data_hora`), '%d/%m/%Y %H:%i'))", 500) . " AS `DETALHE`
  FROM `vw_resp_regras_registros` r
  JOIN `historico_alteracoes` h ON h.`tabela` = ($caso) AND CAST(h.`registro_id` AS UNSIGNED) = r.`REGISTRO_ID`
   AND h.`codhistorico` > COALESCE(" . $marca('HIST') . ", $maximo) AND h.`codhistorico` <= COALESCE(" . $marca('HIST_ATE') . ", $maximo)
   AND COALESCE(h.`codusuario`, 0) <> r.`CODUSUARIO`
 WHERE r.`AVISO_ALTERACAO` = 1 AND r.`AREA` IN ('" . implode("', '", array_keys($tabs)) . "')
 GROUP BY r.`CODUSUARIO`, r.`AREA`, r.`REGISTRO_ID`, r.`DESCRICAO`";
            }
        }
        $u = self::colunas($pdo, 'usuarios');
        $ativo = isset($u['ativo']) ? ' JOIN `usuarios` u ON u.`codusuario` = p.`CODUSUARIO` AND u.`ativo` = 1' : '';
        return "CREATE OR REPLACE VIEW `vw_resp_regras_avisos_pendentes` AS
SELECT p.`CODUSUARIO`, p.`AREA`, p.`REGISTRO_ID`, p.`TIPO`, p.`CHAVE`, p.`DESCRICAO`, p.`DETALHE` FROM (\n" . implode("\nUNION ALL\n", $p) . "\n) p$ativo";
    }

    // ------------------------------------------------------------------ vw_responsabilidade_efetiva

    /** Corpo (depois de " AS ") da definição atual de uma view, ou null. */
    public static function corpoView(PDO $pdo, string $view): ?string
    {
        if (!self::existe($pdo, $view)) return null;
        $def = (string)($pdo->query('SHOW CREATE VIEW ' . self::q($view))->fetch(PDO::FETCH_ASSOC)['Create View'] ?? '');
        if (!preg_match('/\sVIEW\s+`[^`]+`\s+AS\s+(.*)$/is', $def, $m)) return null;
        return trim($m[1]);
    }

    /** As regras já estão na efetiva? */
    public static function efetivaTemRegras(PDO $pdo): bool
    {
        $c = self::corpoView($pdo, self::EFETIVA);
        return $c !== null && stripos($c, 'vw_resp_regras_registros') !== false && self::existe($pdo, self::EFETIVA_BASE);
    }

    /** SELECT das regras com as mesmas colunas (e collations) da efetiva base. */
    private static function selectRegrasEfetiva(PDO $pdo): string
    {
        $s = $pdo->prepare('SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $s->execute([self::EFETIVA_BASE]);
        $cols = $s->fetchAll(PDO::FETCH_NUM); $s->closeCursor();
        $mod = self::existe($pdo, 'responsabilidade_modulos') && isset(self::colunas($pdo, 'responsabilidade_modulos')['codigo']);
        $casoEnt = 'CASE r.`AREA`'; $casoMod = 'CASE r.`AREA`'; $casoNome = 'CASE r.`AREA`';
        foreach (self::AREAS as $ar => $a) {
            $casoEnt .= " WHEN '$ar' THEN '" . $a['entidades'][0] . "'";
            $casoMod .= " WHEN '$ar' THEN '" . $a['modulo_resp'] . "'";
            $casoNome .= " WHEN '$ar' THEN '" . str_replace("'", "''", $a['nome']['pt']) . "'";
        }
        $casoEnt .= ' END'; $casoMod .= ' END'; $casoNome .= ' END';
        $expr = [
            'codorigem' => 'r.`CODREGRA`', 'tipo_origem' => "'REGRA'", 'codmodulo' => $mod ? 'm.`CODMODULO`' : 'NULL',
            'modulo_codigo' => $mod ? "COALESCE(m.`CODIGO`, $casoMod)" : $casoMod, 'modulo_nome' => $mod ? "COALESCE(m.`NOME`, $casoNome)" : $casoNome,
            'origem' => "'REGRA'", 'entidade' => $casoEnt, 'registro_id' => 'CAST(r.`REGISTRO_ID` AS CHAR)', 'codusuario_destino' => 'r.`CODUSUARIO`',
            'userid' => 'u.`userid`', 'nome_responsavel' => 'u.`nome`', 'codgrupo_origem' => 'r.`CODGRUPO`', 'prioridade' => '0', 'principal' => '0',
            'valido_de' => 'NULL', 'valido_ate' => 'NULL',
        ];
        $lista = [];
        foreach ($cols as [$col, $coll]) {
            $e = $expr[strtolower((string)$col)] ?? 'NULL';
            if ($coll !== null && $e !== 'NULL') $e = "CONVERT($e USING utf8mb4) COLLATE $coll";
            $lista[] = $e . ' AS ' . self::q((string)$col);
        }
        return 'SELECT ' . implode(', ', $lista) . ' FROM `vw_resp_regras_registros` r JOIN `usuarios` u ON u.`codusuario` = r.`CODUSUARIO`'
            . ($mod ? " LEFT JOIN `responsabilidade_modulos` m ON m.`CODIGO` = ($casoMod) AND m.`ATIVO` = 1" : '');
    }

    /**
     * Inclui as regras na vw_responsabilidade_efetiva (ORIGEM 'REGRA'). A definição anterior vira vw_responsabilidade_efetiva_base
     * e vai para storage/logs. Desfaz se a consulta ficar muito mais lenta (> 5 s e > 3×).
     */
    public static function efetivaAplicar(PDO $pdo, string $logDir, string $banco): string
    {
        if (!self::existe($pdo, self::EFETIVA)) return 'sem vw_responsabilidade_efetiva (empresa sem a camada de responsabilidades): só regras e Responsável do registro';
        $medir = static function () use ($pdo): float { $t = microtime(true); $pdo->query('SELECT COUNT(*) FROM `' . self::EFETIVA . '`')->fetchColumn(); return microtime(true) - $t; };
        if (self::efetivaTemRegras($pdo)) {
            $pdo->exec('CREATE OR REPLACE VIEW `' . self::EFETIVA . '` AS SELECT * FROM `' . self::EFETIVA_BASE . "`\nUNION ALL\n" . self::selectRegrasEfetiva($pdo));
            return sprintf('já incluía as regras (refeita; consulta %.2fs)', $medir());
        }
        $corpo = self::corpoView($pdo, self::EFETIVA);
        if ($corpo === null) return 'NÃO alterada: definição da efetiva em formato inesperado (as telas Minhas usam as regras mesmo assim)';
        @file_put_contents($logDir . DIRECTORY_SEPARATOR . "migracao_361_vw_responsabilidade_efetiva_anterior_$banco.sql", 'CREATE OR REPLACE VIEW `' . self::EFETIVA . '` AS ' . $corpo . ";\n");
        $antes = $medir();
        $pdo->exec('CREATE OR REPLACE VIEW `' . self::EFETIVA_BASE . '` AS ' . $corpo);
        self::limparCache();
        try {
            $pdo->exec('CREATE OR REPLACE VIEW `' . self::EFETIVA . '` AS SELECT * FROM `' . self::EFETIVA_BASE . "`\nUNION ALL\n" . self::selectRegrasEfetiva($pdo));
        } catch (Throwable $e) {
            $pdo->exec('CREATE OR REPLACE VIEW `' . self::EFETIVA . '` AS ' . $corpo);
            $pdo->exec('DROP VIEW IF EXISTS `' . self::EFETIVA_BASE . '`');
            return 'NÃO alterada (' . mb_substr($e->getMessage(), 0, 160) . '): as telas Minhas usam as regras mesmo assim';
        }
        $depois = $medir();
        if ($depois > 5 && $depois > 3 * $antes) {
            self::efetivaRestaurar($pdo);
            return sprintf('DESFEITO: a consulta passou de %.1fs para %.1fs; as telas Minhas usam as regras mesmo assim', $antes, $depois);
        }
        return sprintf('ok: inclui as regras (ORIGEM REGRA; consulta %.2fs → %.2fs); definição anterior em storage/logs e em %s', $antes, $depois, self::EFETIVA_BASE);
    }

    /** Volta a efetiva para a definição anterior (vw_responsabilidade_efetiva_base) e apaga a base. */
    public static function efetivaRestaurar(PDO $pdo): string
    {
        $base = self::corpoView($pdo, self::EFETIVA_BASE);
        if ($base === null) return 'nada a restaurar';
        $pdo->exec('CREATE OR REPLACE VIEW `' . self::EFETIVA . '` AS ' . $base);
        $pdo->exec('DROP VIEW IF EXISTS `' . self::EFETIVA_BASE . '`');
        self::limparCache();
        return 'restaurada a definição anterior';
    }

    /** View com as atribuições sem as regras (para vw_resp_acesso_*). */
    public static function viewAtribuicoes(PDO $pdo): ?string
    {
        if (self::existe($pdo, self::EFETIVA_BASE)) return self::EFETIVA_BASE;
        return self::existe($pdo, self::EFETIVA) && !self::efetivaTemRegras($pdo) ? self::EFETIVA : null;
    }

    /** Views criadas pela 361 (para --remover), na ordem inversa de dependência. */
    public static function todasViews(): array
    {
        $v = ['vw_resp_regras_avisos_pendentes', 'vw_resp_acesso', 'vw_resp_regras_pessoas'];
        foreach (array_keys(self::AREAS) as $a) { $v[] = 'vw_resp_minhas_' . strtolower($a); $v[] = 'vw_resp_acesso_' . strtolower($a); }
        return array_merge($v, ['vw_resp_regras_cobertura', 'vw_resp_regras_registros', 'vw_resp_regras_usuarios', 'vw_resp_regras_itens']);
    }
}
