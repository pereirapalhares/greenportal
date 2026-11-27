<?php
declare(strict_types=1);

/*
 * Regras do que sai do legado para o greenportal (migração e sincronismo diário scripts\sincronizar-fo-legado.php).
 * Decisões do usuário registradas aqui para os processos não tentarem migrar ou sincronizar de novo o que foi descartado.
 */
final class SincronismoLegado
{
    /** Tabelas do Módulo Legal de cada empresa sincronizadas legado -> novo (scripts\sincronizar-fo-legado.php). */
    public const TABELAS_LEGAL = ['unidades', 'areas', 'atividades', 'aspectos', 'impactos', 'destinos', 'perigos', 'riscos', 'legislacaoxempresa', 'legislacaoxunidade', 'legislacaoxaspecto', 'legislacaoxatividade', 'legislacaoximpacto', 'legislacaoxperigo', 'legislacaoxrisco', 'legislacaoxpoder', 'legislacaoxsituacao', 'legislacaoxchecklist', 'legislacaoxdocumentos', 'legislacaoxinfraestruturas', 'infraestrutura', 'infraestruturaxunidades', 'legislacaoxtreinamentos', 'legislacaoxfornecedores', 'legislacaoxlicencas', 'mm_monitoramento', 'mm_monitoramentoxlegislacao', 'mm_medicaoxlegislacao', 'atividadesxinfraestrutura', 'laia', 'laia_inventario', 'lais', 'lais_aspectossociais', 'lais_documentosxaspectos', 'lais_documentosximpactos', 'lais_impactossociais', 'lais_legislacaoxaspectossociais', 'lais_legislacaoximpactossociais', 'lais_partes_interessadas', 'lais_revisao', 'lpro', 'lpro_abrangencia', 'lpro_natureza_do_perigo', 'lpro_situacao', 'lpro_tipo_de_atividade', 'li_licencas', 'li_licencasxgrupos', 'li_licencasxpalavraschave', 'li_condicionantes', 'li_planodeacao', 'licencasxcondicionantes', 'licencasxresponsaveis'];

    /** Prefixos de tabelas sincronizadas legado -> novo (tabelas que existem nos dois bancos). */
    // Migração 423 (27/11/2026): auditoria_ (Módulo de Auditoria do Green 3; decisão do usuário: sincronizar todo dia)
    public const PREFIXOS = ['fo_', 'nc_', 'auditoria_'];

    /**
     * Tabelas do legado DESCONSIDERADAS (não são migradas, nem criadas, nem sincronizadas).
     * nome em minúsculas => motivo / decisão
     */
    public const DESCONSIDERADAS = [
        'nc_indicacao_ocorrencia_temp' => 'sobra do legado; desconsiderada pelo usuário em 18/10/2026 (349 linhas só nela, sem importância)',
        'nc_planodeacao_aux' => 'sobra do legado; desconsiderada pelo usuário em 18/10/2026 (50 linhas só nela, sem importância)',
        'nc_indicacao_ocorrencia_backup' => 'sobra do legado; desconsiderada pelo usuário em 18/10/2026 (15 linhas só nela, sem importância)',
        'nc_indicacao_ocorrencia_finalizados' => 'sobra do legado; desconsiderada pelo usuário em 18/10/2026 (13 linhas só nela, sem importância)',
        'nc_indicacao_ocorrencia_aux' => 'sobra do legado (vazia); desconsiderada pelo usuário em 18/10/2026',
        // nc_tiporeclamacao do legado é subconjunto de nc_tipo_de_reclamacao (Crown: 81 de 183 códigos; a view de ocorrências do
        // legado usa nc_tipo_de_reclamacao). O nc_tiporeclamacao do greenportal é preenchido por TABELAS_MAPEADAS (18/10/2026).
        'nc_tiporeclamacao' => 'no legado é subconjunto de nc_tipo_de_reclamacao; o greenportal recebe nc_tipo_de_reclamacao (ver TABELAS_MAPEADAS)',
    ];

    /**
     * Tabelas do legado gravadas no greenportal com outro nome/colunas (legado prevalece; o resto da tabela do greenportal fica).
     * tabela do legado => [destino => tabela do greenportal, colunas => [coluna do legado => coluna do greenportal], a 1ª é a chave]
     */
    public const TABELAS_MAPEADAS = [
        'nc_tipo_de_reclamacao' => ['destino' => 'nc_tiporeclamacao',
            'colunas' => ['codtipodereclamacao' => 'codtiporeclamacao', 'dsctipodereclamacao' => 'dsctiporeclamacao'],
            'motivo' => 'decisão do usuário em 18/10/2026: 581 ocorrências da Crown usam códigos que só existem em nc_tipo_de_reclamacao'],
    ];

    /**
     * Prefixos mantidos no greenportal (não sincronizados) em alguns bancos, enquanto o usuário não confirmar que ali
     * o legado prevalece. prefixo => bancos
     */
    public const PREFIXOS_MANTIDOS_NO_NOVO = [
        // nc_: MODELO e Atlas entram no sincronismo de NC (decisão do usuário em 18/10/2026).
    ];

    /**
     * Colunas do legado desconsideradas (não são criadas no greenportal nem contam como informação perdida).
     * Expressão regular sobre o nome da coluna em minúsculas => motivo.
     */
    public const COLUNAS_DESCONSIDERADAS = [
        '/_en$/' => 'tradução em inglês do legado; o greenportal tem tratamento próprio de idiomas (decisão do usuário em 18/10/2026)',
    ];

    /**
     * Faixas de códigos do greenportal (decisão do usuário em 21/10/2026, migração 322): registros criados no greenportal ficam
     * a partir destes códigos (procedures sp_nc_*, função fn_nc_faixa) e o sincronismo não os apaga, não os altera e não os conta
     * como divergência. Se o legado chegar à faixa, o sincronismo não traz essas linhas e avisa (conflito_faixa_legado).
     * tabela => [coluna da chave, primeiro código]
     */
    public const FAIXAS_NOVO = [
        'nc_indicacao_ocorrencia' => ['codocorrencia', 50001],
        'nc_causas' => ['codcausa', 50001],
        'nc_planodeacao' => ['codplanoacao', 500001],
        // Migração 327 (21/10/2026): cadastros do tipo Cliente com tela no greenportal (classes/NaoConformidadeCadastros.php)
        'nc_clientes' => ['codcliente', 50001],
        'nc_unidadesdocliente' => ['codunidade', 50001],
        'nc_produtos' => ['codproduto', 50001],
        'nc_tipodedocumento' => ['codtipodedocumento', 50001],
        'nc_tiporeclamacao' => ['codtiporeclamacao', 50001],
        'nc_tipo_de_tampa' => ['codtipodetampa', 50001],
        'nc_tamanho_da_lata' => ['codtamanhodalata', 50001],
        // Migração 332 (22/10/2026): cadastros gerais de NC com tela no greenportal (Administração de NC, fase 1)
        'nc_tipoocorrencia' => ['codtipoocorrencia', 50001],
        'nc_classificacao_fornecedor' => ['codclassificacao', 50001],
        'nc_tipoacao' => ['codtipoacao', 50001],
        'nc_origemacao' => ['codorigemacao', 50001],
        'nc_siglagestao' => ['codsiglagestao', 50001],
        'nc_setores' => ['codsetor', 50001],
        'nc_tipos_causas' => ['codtipocausa', 50001],
        // Migração 333 (22/10/2026): listas de distribuição criadas no greenportal (Administração de NC › Listas de distribuição),
        // com suas pessoas e categorias. As do legado continuam vindo do legado.
        'nc_listadistribuicao' => ['codlista', 50001],
        'nc_listadistribuicaoxusuarios' => ['codlistaxusuarios', 50001],
        'nc_listadistribuicaoxcategoria' => ['codlistacategoria', 50001],
        // Migração 423 (27/11/2026): auditorias criadas no greenportal (AUTO_INCREMENT a partir de 50001)
        'auditoria_planejamento' => ['codauditoria', 50001],
        'auditoria_planejamentoxavaliacao' => ['codavaliacao', 50001],
        'auditoria_planejamentoxexecucao' => ['codexecucao', 50001],
        'auditoria_planodeacao' => ['codplanoacao', 50001],
        'auditoria_workflow' => ['codworkflow', 50001],
    ];

    /** [coluna, primeiro código] da faixa do greenportal na tabela, ou null. */
    public static function faixa(string $tabela): ?array
    {
        return self::FAIXAS_NOVO[strtolower($tabela)] ?? null;
    }

    public static function colunaDesconsiderada(string $coluna): ?string
    {
        foreach (self::COLUNAS_DESCONSIDERADAS as $re => $motivo) if (preg_match($re, strtolower($coluna))) return $motivo;
        return null;
    }

    public static function desconsiderada(string $tabela): ?string
    {
        return self::DESCONSIDERADAS[strtolower($tabela)] ?? null;
    }

    /** A tabela entra no sincronismo deste banco? (prefixo sincronizado, não desconsiderada, não mantida no novo) */
    public static function sincronizada(string $tabela, string $banco): bool
    {
        $t = strtolower($tabela);
        if (self::desconsiderada($t) !== null) return false;
        foreach (self::PREFIXOS as $p) {
            if (!str_starts_with($t, $p)) continue;
            return !in_array(strtolower($banco), self::PREFIXOS_MANTIDOS_NO_NOVO[$p] ?? [], true);
        }
        return false;
    }

    /** Condição SQL (LIKE) dos prefixos sincronizados, para a coluna informada. */
    public static function condicaoPrefixos(string $coluna): string
    {
        return '(' . implode(' OR ', array_map(static fn($p) => $coluna . " LIKE '" . str_replace('_', '\\\\_', $p) . "%'", self::PREFIXOS)) . ')';
    }
}
