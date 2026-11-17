<?php
declare(strict_types=1);

/**
 * Não Conformidades › Administração › Configuração do módulo (migração 339, 23/10/2026; fase 4 da administração de NC).
 * Parâmetros da empresa (vw_nc_config_parametros), campos exigidos no envio por tipo (vw_nc_config_obrigatorios) e regras de
 * lembrete (vw_nc_lembrete_regras). Sem a migração (ou sem um parâmetro gravado) valem os padrões, que repetem o comportamento
 * anterior (ex.: Crown exige data e responsável na eficácia e separa as listas de fornecedor).
 */
final class NaoConformidadeConfig
{
    public const MENU = 'NAO_CONFORMIDADES_CONFIGURACAO';
    public const SUB_CONFIGURACAO = 'NAO_CONFORMIDADES_ADM_CONFIGURACAO';

    /** Parâmetros: chave => [rótulo, ajuda, tipo (sn | data)]. */
    public const PARAMETROS = [
        'EFICACIA_EXIGE_PRAZO' => ['Eficácia exige data da análise e responsável pela pendência', 'Ao aplicar o resultado da eficácia, obriga a data da análise da eficácia e o responsável por esta pendência (regra da Crown no legado).', 'sn'],
        'LISTAS_FORNECEDOR_SEPARADAS' => ['Listas de distribuição de fornecedor separadas', 'A NC de Fornecedor só oferece as listas da categoria Fornecedores e os outros tipos as demais (regra da Crown no legado).', 'sn'],
        'IA_ANALISE_CAUSA' => ['IA na análise da causa', 'Mostra o botão "Sugerir com IA" na aba Análise da causa e plano de ação.', 'sn'],
        'IA_EFICACIA' => ['IA na avaliação da eficácia', 'Mostra o botão "Avaliar com IA" na aba Verificação da eficácia.', 'sn'],
        'LEMBRETES_INCLUIR_LEGADO' => ['Lembretes também para as NCs do sistema anterior', 'Não: só as NCs criadas no greenportal (o sistema anterior tem a própria política de e-mails até a virada). Sim: todas as NCs em aberto.', 'sn'],
        'LEMBRETES_DESDE' => ['Lembretes só para NCs criadas a partir de', 'Deixe vazio para todas. Útil para não lembrar NCs antigas.', 'data'],
    ];

    /** Situações de lembrete: evento => [rótulo, destinatário, o que os dias medem, nome padrão, dias, repetir, superior]. */
    public const EVENTOS = [
        'GESTOR_PENDENTE' => ['NC aguardando o gestor', 'Gestor', 'dias sem movimento', 5, 7, 2],
        'EMITENTE_RECUSADA' => ['NC devolvida ao emitente', 'Emitente', 'dias sem movimento', 5, 7, 0],
        'ANALISE_PARADA' => ['Análise da causa parada', 'Responsável pela análise', 'dias sem movimento', 10, 7, 2],
        'CONCLUSAO_VENCIDA' => ['Data prevista de conclusão vencida', 'Responsável pela análise', 'dias de atraso', 1, 7, 2],
        'ACAO_VENCENDO' => ['Ação vencendo', 'Responsável pela ação', 'dias até vencer (até)', 3, 0, 0],
        'ACAO_VENCIDA' => ['Ação vencida', 'Responsável pela ação', 'dias de atraso', 1, 7, 2],
        'EFICACIA_PENDENTE' => ['Eficácia aguardando o gestor', 'Gestor', 'dias sem movimento', 10, 7, 2],
    ];

    /**
     * Campos da ocorrência que podem ser exigidos no envio (coluna => rótulo da tela). "sempre" = aparece em todo tipo; os outros só
     * quando o tipo usa o campo (nc_tipo_de_naoconformidadexcampos, do legado).
     */
    public const CAMPOS = [
        'CODUNIDADE' => ['Unidade', true], 'LISTADEDISTRIBUICAO' => ['Lista de distribuição', true], 'DATA_OCORRENCIA' => ['Data da ocorrência', true],
        'CODSETOR' => ['Setor', false], 'CODAREA' => ['Área', false], 'CODTIPOOCORRENCIA' => ['Classificação', false], 'PARADANALINHA' => ['Ocorreu parada de linha?', false],
        'CODTIPOACAO' => ['Tipo de ocorrência', false], 'CODORIGEMACAO' => ['Origem da ocorrência', false], 'CODSIGLAGESTAO' => ['Sistema de gestão', false],
        'CODFORNECEDOR' => ['Fornecedor', false], 'QTDENVOLVIDA' => ['Quantidade envolvida', false], 'TAMANHOLOTEENVOLVIDO' => ['Lote envolvido', false],
        'HOUVE_RETENCAO_PRODUTO' => ['Houve retenção de produto?', false], 'HAVERA_DEVOLUCAO_MATERIAL' => ['Haverá devolução de material?', false],
        'DSCCLIENTE' => ['Cliente', false], 'CODUNIDADE_CLIENTE' => ['Unidade do cliente', false], 'CODIGODOPRODUTO' => ['Produto', false],
        'CODTIPODEDOCUMENTO' => ['Tipo de documento', false], 'NRNDOCUMENTO' => ['Nº do documento', false], 'CODTIPODERECLAMACAO' => ['Tipo de reclamação', false],
        'CODTIPODETAMPA' => ['Tipo de tampa', false], 'CODTAMANHODALATA' => ['Tamanho da lata', false], 'PROCEDENCIA_CLIENTE' => ['Procedência', false],
        'AFETASEGURANCAALIMENTOS' => ['Afeta a segurança de alimentos?', false], 'QTDDEVOLVIDA' => ['Quantidade devolvida', false],
        'PASS_PELA_NANALISE_EFICACIA' => ['Passar pela análise da eficácia?', false], 'IDENTIFICACAO' => ['Sumário da ocorrência', false],
        'DESCRICAO' => ['Descrição da ocorrência', false], 'PROVIDENCIA' => ['Dados complementares', false],
    ];

    /** Regras fixas do envio (sp_nc_ocorrencia_salvar, como no legado): tipo => campos sempre exigidos (0 = todos os tipos). */
    public const FIXOS = [
        0 => ['CODUNIDADE', 'CODUSUARIO_GESTOR'],
        1 => ['CODFORNECEDOR'],
        2 => ['DSCCLIENTE', 'CODUNIDADE_CLIENTE', 'CODIGODOPRODUTO', 'CODTIPODEDOCUMENTO', 'NRNDOCUMENTO', 'CODTIPODERECLAMACAO', 'PROCEDENCIA_CLIENTE', 'AFETASEGURANCAALIMENTOS'],
        5 => ['PASS_PELA_NANALISE_EFICACIA'], 6 => ['PASS_PELA_NANALISE_EFICACIA'],
    ];
    /** Sumário e descrição: exigidos pelo envio quando o tipo usa o campo. */
    public const FIXOS_SE_USA = ['IDENTIFICACAO', 'DESCRICAO'];

    private static array $cache = [];

    /** Valor do parâmetro (S/N ou data), com o padrão da empresa quando não gravado ou antes da migração 339. */
    public static function valor(PDO $pdo, string $chave): string
    {
        $banco = (string)($_SESSION['tenant']['banco_dados'] ?? '');
        if (!isset(self::$cache[$banco])) {
            self::$cache[$banco] = [];
            try {
                foreach ($pdo->query('SELECT CHAVE, VALOR FROM vw_nc_config_parametros')->fetchAll(PDO::FETCH_ASSOC) as $p) self::$cache[$banco][(string)$p['CHAVE']] = (string)$p['VALOR'];
            } catch (Throwable) {} // antes da migração 339
        }
        return self::$cache[$banco][$chave] ?? self::padrao($chave, $banco);
    }

    public static function sim(PDO $pdo, string $chave): bool { return self::valor($pdo, $chave) === 'S'; }

    /** Padrões = comportamento anterior à configuração. */
    public static function padrao(string $chave, string $banco = ''): string
    {
        require_once __DIR__ . '/HomologacaoBancos.php';
        $legado = $banco !== '' ? HomologacaoBancos::legado($banco) : '';
        return match ($chave) {
            'EFICACIA_EXIGE_PRAZO' => $legado === 'green_crown' ? 'S' : 'N',
            'LISTAS_FORNECEDOR_SEPARADAS' => in_array($legado, ['green_crown', 'green_homologacao_crown'], true) ? 'S' : 'N',
            'IA_ANALISE_CAUSA', 'IA_EFICACIA' => 'S',
            'LEMBRETES_INCLUIR_LEGADO' => 'N',
            default => '',
        };
    }

    public static function limparCache(): void { self::$cache = []; }
}
