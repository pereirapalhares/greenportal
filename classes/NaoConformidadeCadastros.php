<?php
declare(strict_types=1);

/**
 * Não Conformidades › Cadastros (migração 327, 21/10/2026 — decisão do usuário: telas nesta etapa).
 * Listas do tipo Cliente que no legado não tinham tela no greenportal. Cada cadastro é uma tabela nc_* do legado com chave
 * numérica e descrição, sincronizada legado → greenportal (o legado prevalece até a virada). Por isso:
 *  - registros do legado (chave abaixo de FAIXA) só são consultados aqui ('legacy_below' do grid);
 *  - registros incluídos no greenportal recebem chave a partir de FAIXA (AUTO_INCREMENT) e o sincronismo não os apaga
 *    nem altera (SincronismoLegado::FAIXAS_NOVO);
 *  - exclusão de registro do greenportal em uso é bloqueada no banco (gatilho → sp_<tabela>_validar_exclusao).
 * Leitura: vw_nc_cad_<chave> (sp_crud_select). Gravação: sp_crud_insert / sp_crud_update / sp_crud_delete.
 * Objetos criados por scripts/aplicar-migracao-321-nao-conformidades-base.php (m327Cadastros).
 *
 * Migração 332 (22/10/2026, Administração de NC — fase 1, decisões do usuário): entram os cadastros gerais (Classificação,
 * Classificação de fornecedores, Tipo de ocorrência, Origem, Sistema de gestão, Setores, Tipos de causa) e, em todos:
 *  - coluna ATIVO (só no greenportal; o legado não tem): item desativado some das listas de escolha (vw_nc_opcoes) e continua
 *    aparecendo nas NCs que já o usam. Pode ser alterada também nos registros do legado (o sincronismo não mexe nela);
 *  - nome repetido bloqueado ao incluir/alterar no greenportal (gatilho; o sincronismo, @gp_sinc_legado = 1, não é bloqueado).
 * Migração 333: o grupo do menu (MENU_GRUPO) passa a se chamar "Administração" e reúne também Listas de distribuição e
 * Participantes externos (classes/NaoConformidadeAdministracao.php); o código do grupo continua o mesmo (permissões mantidas).
 */
final class NaoConformidadeCadastros
{
    public const FAIXA = 50001;
    public const MENU_GRUPO = 'NAO_CONFORMIDADES_CADASTROS';

    /**
     * chave => tabela, chave primária, descrição, menu, url, rótulos [pt, en, es] do cadastro e do campo, ícone,
     * expressão de uso (referências em nc_indicacao_ocorrencia; c = linha do cadastro).
     */
    public const LISTA = [
        // Cadastros gerais (migração 332). 'uso': em quantas NCs/causas o item aparece.
        'classificacao' => ['tabela' => 'nc_tipoocorrencia', 'pk' => 'CODTIPOOCORRENCIA', 'dsc' => 'DSCTIPOOCORRENCIA', 'menu' => 'NAO_CONFORMIDADES_CAD_CLASSIFICACAO',
            'url' => '/principal/naoconformidades_cadclassificacao.php', 'rotulo' => ['Classificação', 'Classification', 'Clasificación'], 'campo' => ['Classificação', 'Classification', 'Clasificación'], 'icone' => 'bi-tags',
            'uso' => 'SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE o.CODTIPOOCORRENCIA = c.CODTIPOOCORRENCIA AND o.CODTIPONAOCONFORMIDADE <> 1'],
        'classificacao_fornecedor' => ['tabela' => 'nc_classificacao_fornecedor', 'pk' => 'codclassificacao', 'dsc' => 'dscclassificacao', 'menu' => 'NAO_CONFORMIDADES_CAD_CLASSIFICACAO_FORNECEDOR',
            'url' => '/principal/naoconformidades_cadclassificacaofornecedor.php', 'rotulo' => ['Classificação de fornecedores', 'Supplier classification', 'Clasificación de proveedores'], 'campo' => ['Classificação do fornecedor', 'Supplier classification', 'Clasificación del proveedor'], 'icone' => 'bi-truck',
            'uso' => 'SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE o.CODTIPOOCORRENCIA = c.codclassificacao AND o.CODTIPONAOCONFORMIDADE = 1'],
        'tipos_ocorrencia' => ['tabela' => 'nc_tipoacao', 'pk' => 'CODTIPOACAO', 'dsc' => 'DSCTIPOACAO', 'menu' => 'NAO_CONFORMIDADES_CAD_TIPOS_OCORRENCIA',
            'url' => '/principal/naoconformidades_cadtiposdeocorrencia.php', 'rotulo' => ['Tipos de ocorrência', 'Occurrence types', 'Tipos de ocurrencia'], 'campo' => ['Tipo de ocorrência', 'Occurrence type', 'Tipo de ocurrencia'], 'icone' => 'bi-diagram-2',
            'uso' => 'SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE o.CODTIPOACAO = c.CODTIPOACAO'],
        'origens' => ['tabela' => 'nc_origemacao', 'pk' => 'CODORIGEMACAO', 'dsc' => 'DSCORIGEMACAO', 'menu' => 'NAO_CONFORMIDADES_CAD_ORIGENS',
            'url' => '/principal/naoconformidades_cadorigens.php', 'rotulo' => ['Origens da ocorrência', 'Occurrence sources', 'Orígenes de la ocurrencia'], 'campo' => ['Origem da ocorrência', 'Occurrence source', 'Origen de la ocurrencia'], 'icone' => 'bi-signpost-split',
            'uso' => 'SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE o.CODORIGEMACAO = c.CODORIGEMACAO'],
        'sistemas_gestao' => ['tabela' => 'nc_siglagestao', 'pk' => 'CODSIGLAGESTAO', 'dsc' => 'DSCSIGLAGESTAO', 'menu' => 'NAO_CONFORMIDADES_CAD_SISTEMAS_GESTAO',
            'url' => '/principal/naoconformidades_cadsistemasdegestao.php', 'rotulo' => ['Sistemas de gestão', 'Management systems', 'Sistemas de gestión'], 'campo' => ['Sistema de gestão', 'Management system', 'Sistema de gestión'], 'icone' => 'bi-diagram-3',
            'uso' => 'SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE o.CODSIGLAGESTAO = c.CODSIGLAGESTAO'],
        'setores' => ['tabela' => 'nc_setores', 'pk' => 'codsetor', 'dsc' => 'dscsetor', 'menu' => 'NAO_CONFORMIDADES_CAD_SETORES',
            'url' => '/principal/naoconformidades_cadsetores.php', 'rotulo' => ['Setores', 'Sectors', 'Sectores'], 'campo' => ['Setor', 'Sector', 'Sector'], 'icone' => 'bi-grid-3x3-gap',
            'uso' => 'SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE o.CODSETOR = c.codsetor'],
        'tipos_causa' => ['tabela' => 'nc_tipos_causas', 'pk' => 'CODTIPOCAUSA', 'dsc' => 'DSCTIPOCAUSA', 'menu' => 'NAO_CONFORMIDADES_CAD_TIPOS_CAUSA',
            'url' => '/principal/naoconformidades_cadtiposdecausa.php', 'rotulo' => ['Tipos de causa', 'Cause types', 'Tipos de causa'], 'campo' => ['Tipo de causa', 'Cause type', 'Tipo de causa'], 'icone' => 'bi-diagram-3-fill',
            'uso' => 'SELECT COUNT(*) FROM nc_causas x WHERE x.CODTIPOCAUSA = c.CODTIPOCAUSA'],
        // Cadastros do tipo Cliente (migração 327)
        'clientes' => ['tabela' => 'nc_clientes', 'pk' => 'codcliente', 'dsc' => 'dsccliente', 'menu' => 'NAO_CONFORMIDADES_CAD_CLIENTES',
            'url' => '/principal/naoconformidades_cadclientes.php', 'rotulo' => ['Clientes', 'Customers', 'Clientes'], 'campo' => ['Cliente', 'Customer', 'Cliente'], 'icone' => 'bi-buildings',
            'uso' => "SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE CONVERT(TRIM(o.DSCCLIENTE) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci = CONVERT(TRIM(REPLACE(REPLACE(c.dsccliente, CHAR(13), ''), CHAR(10), '')) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci"],
        'unidades_cliente' => ['tabela' => 'nc_unidadesdocliente', 'pk' => 'codunidade', 'dsc' => 'dscunidade', 'menu' => 'NAO_CONFORMIDADES_CAD_UNIDADES_CLIENTE',
            'url' => '/principal/naoconformidades_cadunidadesdocliente.php', 'rotulo' => ['Unidades do cliente', 'Customer sites', 'Unidades del cliente'], 'campo' => ['Unidade do cliente', 'Customer site', 'Unidad del cliente'], 'icone' => 'bi-geo-alt',
            'uso' => 'SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE o.codunidade_cliente = c.codunidade'],
        'produtos' => ['tabela' => 'nc_produtos', 'pk' => 'codproduto', 'dsc' => 'dscproduto', 'menu' => 'NAO_CONFORMIDADES_CAD_PRODUTOS',
            'url' => '/principal/naoconformidades_cadprodutos.php', 'rotulo' => ['Produtos', 'Products', 'Productos'], 'campo' => ['Produto', 'Product', 'Producto'], 'icone' => 'bi-box-seam',
            'uso' => "SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE o.CODIGODOPRODUTO = CAST(c.codproduto AS CHAR)"],
        'tipos_documento' => ['tabela' => 'nc_tipodedocumento', 'pk' => 'codtipodedocumento', 'dsc' => 'dsctipodedocumento', 'menu' => 'NAO_CONFORMIDADES_CAD_TIPOS_DOCUMENTO',
            'url' => '/principal/naoconformidades_cadtiposdedocumento.php', 'rotulo' => ['Tipos de documento', 'Document types', 'Tipos de documento'], 'campo' => ['Tipo de documento', 'Document type', 'Tipo de documento'], 'icone' => 'bi-file-earmark-text',
            'uso' => 'SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE o.codtipodedocumento = c.codtipodedocumento'],
        'tipos_reclamacao' => ['tabela' => 'nc_tiporeclamacao', 'pk' => 'codtiporeclamacao', 'dsc' => 'dsctiporeclamacao', 'menu' => 'NAO_CONFORMIDADES_CAD_TIPOS_RECLAMACAO',
            'url' => '/principal/naoconformidades_cadtiposdereclamacao.php', 'rotulo' => ['Tipos de reclamação', 'Complaint types', 'Tipos de reclamo'], 'campo' => ['Tipo de reclamação', 'Complaint type', 'Tipo de reclamo'], 'icone' => 'bi-chat-left-text',
            'uso' => 'SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE o.codtipodereclamacao = c.codtiporeclamacao'],
        'tipos_tampa' => ['tabela' => 'nc_tipo_de_tampa', 'pk' => 'codtipodetampa', 'dsc' => 'dsctipodetampa', 'menu' => 'NAO_CONFORMIDADES_CAD_TIPOS_TAMPA',
            'url' => '/principal/naoconformidades_cadtiposdetampa.php', 'rotulo' => ['Tipos de tampa', 'Lid types', 'Tipos de tapa'], 'campo' => ['Tipo de tampa', 'Lid type', 'Tipo de tapa'], 'icone' => 'bi-circle',
            'uso' => 'SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE o.codtipodetampa = c.codtipodetampa'],
        'tamanhos_lata' => ['tabela' => 'nc_tamanho_da_lata', 'pk' => 'codtamanhodalata', 'dsc' => 'dsctamanhodalata', 'menu' => 'NAO_CONFORMIDADES_CAD_TAMANHOS_LATA',
            'url' => '/principal/naoconformidades_cadtamanhosdalata.php', 'rotulo' => ['Tamanhos de lata', 'Can sizes', 'Tamaños de lata'], 'campo' => ['Tamanho da lata', 'Can size', 'Tamaño de lata'], 'icone' => 'bi-cup-straw',
            'uso' => 'SELECT COUNT(*) FROM nc_indicacao_ocorrencia o WHERE o.codtamanhodalata = c.codtamanhodalata'],
    ];

    public static function view(string $chave): string
    {
        return 'vw_nc_cad_' . $chave;
    }

    /** DDL do cadastro (view do grid, validação de exclusão e gatilho), em comandos separados. */
    public static function ddl(string $chave): array
    {
        $c = self::LISTA[$chave];
        [$t, $pk, $dsc] = [$c['tabela'], $c['pk'], $c['dsc']];
        $PK = strtoupper($pk); $DSC = strtoupper($dsc); $faixa = self::FAIXA;
        $rotulo = str_replace("'", "''", $c['campo'][0]);
        return [
            "CREATE OR REPLACE VIEW `" . self::view($chave) . "` AS SELECT c.`$pk` AS `$PK`, TRIM(REPLACE(REPLACE(c.`$dsc`, CHAR(13), ''), CHAR(10), '')) AS `$DSC`,"
                . " c.`ATIVO` AS `ATIVO`, IF(c.`$pk` < $faixa, 'Sistema anterior', 'greenportal') AS `ORIGEM`, ({$c['uso']}) AS `QTD_EM_USO` FROM `$t` c",
            // Migração 332: nome repetido não entra pelo greenportal (o sincronismo do legado, @gp_sinc_legado = 1, passa)
            "DROP PROCEDURE IF EXISTS `sp_{$t}_validar_nome`",
            "CREATE PROCEDURE `sp_{$t}_validar_nome`(IN p_codigo BIGINT, IN p_nome TEXT)\nSQL SECURITY INVOKER\nBEGIN\n"
                . "  IF COALESCE(@gp_sinc_legado, 0) = 0 AND p_nome IS NOT NULL THEN\n"
                . "    IF TRIM(COALESCE(p_nome, '')) = '' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Informe o nome.'; END IF;\n"
                . "    IF EXISTS (SELECT 1 FROM `$t` x WHERE x.`$pk` <> COALESCE(p_codigo, 0) AND LOWER(TRIM(REPLACE(REPLACE(x.`$dsc`, CHAR(13), ''), CHAR(10), ''))) = LOWER(TRIM(p_nome))) THEN\n"
                . "      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Já existe um item com este nome neste cadastro.'; END IF;\n  END IF;\nEND",
            "DROP TRIGGER IF EXISTS `trg_{$t}_antes_incluir`",
            "CREATE TRIGGER `trg_{$t}_antes_incluir` BEFORE INSERT ON `$t` FOR EACH ROW CALL `sp_{$t}_validar_nome`(NEW.`$pk`, COALESCE(NEW.`$dsc`, ''))",
            "DROP TRIGGER IF EXISTS `trg_{$t}_antes_alterar`",
            "CREATE TRIGGER `trg_{$t}_antes_alterar` BEFORE UPDATE ON `$t` FOR EACH ROW CALL `sp_{$t}_validar_nome`(NEW.`$pk`, IF(NEW.`$dsc` <=> OLD.`$dsc`, NULL, NEW.`$dsc`))",
            "DROP PROCEDURE IF EXISTS `sp_{$t}_validar_exclusao`",
            // Só os registros do greenportal (faixa): os do legado são apagados pelo sincronismo quando saem do legado.
            "CREATE PROCEDURE `sp_{$t}_validar_exclusao`(IN p_codigo BIGINT)\nSQL SECURITY INVOKER\nBEGIN\n  DECLARE v_qtd BIGINT DEFAULT 0;\n"
                . "  IF p_codigo >= $faixa THEN\n    SELECT COALESCE(MAX(QTD_EM_USO), 0) INTO v_qtd FROM `" . self::view($chave) . "` WHERE `$PK` = p_codigo;\n"
                . "    IF v_qtd > 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '$rotulo em uso em não conformidades, não pode ser excluído.'; END IF;\n  END IF;\nEND",
            "DROP TRIGGER IF EXISTS `trg_{$t}_antes_excluir`",
            "CREATE TRIGGER `trg_{$t}_antes_excluir` BEFORE DELETE ON `$t` FOR EACH ROW CALL `sp_{$t}_validar_exclusao`(OLD.`$pk`)",
        ];
    }

    /** Tela de cadastro (ManutencaoLegalCadastroGrid). */
    public static function tela(string $chave): void
    {
        require_once __DIR__ . '/ManutencaoLegalCadastroGrid.php';
        $c = self::LISTA[$chave];
        $PK = strtoupper($c['pk']); $DSC = strtoupper($c['dsc']);
        $u = static fn(string $s): string => (string)json_decode('"' . $s . '"');
        ManutencaoLegalCadastroGrid::render([
            'menu' => $c['menu'],
            'title' => ['pt' => $c['rotulo'][0], 'en' => $c['rotulo'][1], 'es' => $c['rotulo'][2]],
            'transaction' => basename($c['url'], '.php'), 'database' => 'gmodelo_dbp', 'prefix' => 'GPT',
            'fragment_class' => 'nc-cadastro-' . str_replace('_', '-', $chave) . '-grid-fragment',
            'table' => $c['tabela'], 'view' => self::view($chave),
            'primary' => $PK, 'primary_auto' => true, 'primary_editable' => false,
            'legacy_below' => self::FAIXA,
            // Migração 332: nos registros do legado só a coluna Ativo pode ser alterada (fica só no greenportal)
            'legacy_editable' => ['ATIVO'],
            'default_sort' => ['column' => $DSC, 'direction' => 'asc'],
            'fields' => [
                $PK => ['label' => ['pt' => $u('Código'), 'en' => 'Code', 'es' => $u('Código')], 'readonly' => true, 'filter' => 'none', 'width' => 90],
                $DSC => ['label' => ['pt' => $c['campo'][0], 'en' => $c['campo'][1], 'es' => $c['campo'][2]], 'required' => true, 'filter' => 'text', 'width' => 420],
                'ATIVO' => ['label' => ['pt' => 'Ativo', 'en' => 'Active', 'es' => 'Activo'], 'options' => ['1' => 'Sim', '0' => $u('Não')], 'filter' => 'select', 'width' => 90],
                'ORIGEM' => ['label' => ['pt' => 'Origem', 'en' => 'Source', 'es' => 'Origen'], 'readonly' => true, 'filter' => 'none', 'width' => 140],
                'QTD_EM_USO' => ['label' => ['pt' => 'Em uso', 'en' => 'In use', 'es' => 'En uso'], 'readonly' => true, 'filter' => 'none', 'width' => 90],
            ],
        ]);
        require_once dirname(__DIR__) . '/components/toolbar_grupos.php';
        greenPortalToolbarGrupos('.nc-cadastro-' . str_replace('_', '-', $chave) . '-grid-fragment', []);
    }
}
