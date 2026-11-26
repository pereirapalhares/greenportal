<?php
declare(strict_types=1);

require_once __DIR__ . '/PermissaoTransacao.php';
require_once __DIR__ . '/GreenPortalDatabase.php';

/*
 * Permissão do GED por tipo de anexo.
 *
 * Regra geral: Gestão de Arquivos (GESTAO_ARQUIVOS) decide ver / enviar / excluir.
 * Tipos ligados a uma tela própria também aceitam a permissão dessa tela, para que o perfil
 * não precise de acesso à Gestão de Arquivos inteira:
 *   fornecedorcriterios → PORTARIA_DOCUMENTACAO_FORNECEDOR (Portaria › Documentação de Fornecedor)
 *     ou HOMOLOGACAO_FORNECEDOR_FORNECEDOR (Homologação › Fornecedores › Critérios e Documentos, coluna Evidências)
 *     ver     = Visualizar ou Visualizar arquivos
 *     enviar  = Importar arquivos ou Incluir
 *     excluir = Excluir
 *     baixar / imprimir = Baixar/imprimir arquivos (pode_baixar_arquivos, migração 266)
 *   funcionariocriterios → PORTARIA_DOCUMENTACAO_FUNCIONARIO (Portaria › Documentação de Funcionário), mesmas regras
 *   e o registro precisa ser de uma unidade do usuário (menos ADMINISTRADOR), conferido pelas procedures
 *   sp_portaria_docfornecedor_permitido (migração 260) e sp_portaria_docfuncionario_permitido (migração 262).
 * Não Conformidades (ncocorrencia, ncanalise, ncplano): telas do módulo (Lista geral, Minhas ocorrências, Nova ocorrência);
 *   o registro só para quem pode ver a ocorrência (NaoConformidadeWorkflow::podeVer: administrador, Lista geral com visualizar
 *   total ou envolvido — emitente, gestor, responsáveis). Ocorrência finalizada: arquivos só para consulta (registroEditavel).
 * Perfis do fornecedor externo (FORNECEDOR / FORNECEDOR_GESTOR, migração 296): só anexos de documentos e funcionários
 *   dos fornecedores ligados ao usuário (sp_fo_acesso_registro); os demais tipos ficam bloqueados para eles.
 */
final class GedPermissaoTipo
{
    private const TRANSACAO_DO_TIPO = [
        'fornecedorcriterios' => 'PORTARIA_DOCUMENTACAO_FORNECEDOR',
        'funcionariocriterios' => 'PORTARIA_DOCUMENTACAO_FUNCIONARIO',
        'funcionariotreinamentos' => 'PORTARIA_DOCUMENTACAO_FUNCIONARIO',
        // Migração 297: anexos do funcionário pela Gestão de Funcionários
        'funcionarios' => 'HOMOLOGACAO_FORNECEDOR_FUNCIONARIO',
        // Migração 300: anexos das abas do cadastro do fornecedor (Certificados, Produtos químicos/FDS, Equipamentos,
        // Plano de ação e evidência da Auditoria de homologação), como no legado. Fornecedor externo: bloqueado (como no legado).
        'fornecedorcertificados' => 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR',
        'fornecedorquimicos' => 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR',
        'fornecedorequipamentos' => 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR',
        'fornecedorplanos' => 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR',
        'fornecedorauditoria' => 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR',
        'ncocorrencia' => 'NAO_CONFORMIDADES_LISTA_OCORRENCIAS',
        'ncanalise' => 'NAO_CONFORMIDADES_LISTA_OCORRENCIAS',
        'ncplano' => 'NAO_CONFORMIDADES_LISTA_OCORRENCIAS',
        // Migração 344: comprovante da taxa da licença (Taxas das licenças e o botão Taxas do cadastro de licenças)
        'licencataxas' => 'LICENCAS_TAXAS',
        // 26/11/2026 Revisão G item 73: Evidência da medição
        'medicao' => 'MM_PLANO_MEDICAO_MONITORAMENTO',
        // Revisão G itens 149/150 (migração 421): documentos dos veículos e equipamentos do fornecedor
        'homativo' => 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR',
    ];

    /** Outras telas que também dão acesso aos anexos do tipo (mesmas regras de ver / enviar / excluir). */
    private const OUTRAS_TRANSACOES = [
        'fornecedorcriterios' => ['HOMOLOGACAO_FORNECEDOR_FORNECEDOR'],
        // Migração 297: evidências de critérios e treinamentos do funcionário também pela Gestão de Funcionários
        'funcionariocriterios' => ['HOMOLOGACAO_FORNECEDOR_FUNCIONARIO'],
        'funcionariotreinamentos' => ['HOMOLOGACAO_FORNECEDOR_FUNCIONARIO'],
        'licencataxas' => ['LICENCAS'],
        'ncocorrencia' => ['NAO_CONFORMIDADES_MINHAS_OCORRENCIAS', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_EMPRESA', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_FORNECEDOR', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_PLANEJAMENTO', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_CLIENTE', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_ASSISTENCIA', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_ACIDENTE'],
        'ncanalise' => ['NAO_CONFORMIDADES_MINHAS_OCORRENCIAS', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_EMPRESA', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_FORNECEDOR', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_PLANEJAMENTO', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_CLIENTE', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_ASSISTENCIA', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_ACIDENTE'],
        'ncplano' => ['NAO_CONFORMIDADES_MINHAS_OCORRENCIAS', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_EMPRESA', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_FORNECEDOR', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_PLANEJAMENTO', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_CLIENTE', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_ASSISTENCIA', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_ACIDENTE'],
    ];

    private const TIPOS_NC = ['ncocorrencia', 'ncanalise', 'ncplano'];

    /** Ocorrência de NC do anexo (no ncplano, a ocorrência da ação). 0 = não encontrada. */
    private static function ocorrenciaNc(PDO $pdo, string $tipo, int $id): int
    {
        if ($tipo !== 'ncplano') return $id;
        $s = $pdo->prepare('SELECT CODOCORRENCIA FROM vw_nc_planodeacao WHERE CODPLANOACAO = ?');
        $s->execute([$id]); $cod = (int)$s->fetchColumn(); $s->closeCursor();
        return $cod;
    }

    /** O registro aceita enviar / excluir arquivos? Não conformidade finalizada fica só para consulta (como no legado). */
    public static function registroEditavel(PDO $pdo, string $tipo, int $id): bool
    {
        $tipo = strtolower(trim($tipo));
        if (!in_array($tipo, self::TIPOS_NC, true)) return true;
        try {
            $cod = self::ocorrenciaNc($pdo, $tipo, $id);
            $s = $pdo->prepare('SELECT ETAPA FROM vw_nc_ocorrencia WHERE CODOCORRENCIA = ?');
            $s->execute([$cod]); $etapa = $s->fetchColumn(); $s->closeCursor();
            return $etapa !== false && trim((string)$etapa) !== 'Finalizado';
        } catch (Throwable) { return false; }
    }

    /** Transações da tela própria do tipo, na ordem de preferência. */
    private static function telas(string $tipo): array
    {
        $tipo = strtolower(trim($tipo));
        $codigo = self::TRANSACAO_DO_TIPO[$tipo] ?? '';
        return $codigo === '' ? [] : array_merge([$codigo], self::OUTRAS_TRANSACOES[$tipo] ?? []);
    }

    /** Procedure que confere se o registro é de uma unidade do usuário (migrações 260 e 262). */
    private const PROCEDURE_UNIDADE = [
        'fornecedorcriterios' => 'sp_portaria_docfornecedor_permitido',
        'funcionariocriterios' => 'sp_portaria_docfuncionario_permitido',
        'funcionariotreinamentos' => 'sp_portaria_doctreinamento_permitido',
    ];

    /** Permissão efetiva (pode_visualizar, pode_incluir, pode_excluir, ...) para o tipo de anexo. */
    public static function obter(string $tipo): array
    {
        $geral = PermissaoTransacao::obter('GESTAO_ARQUIVOS');
        foreach (self::telas($tipo) as $codigo) {
            $tela = PermissaoTransacao::obter($codigo);
            $geral['pode_visualizar'] = !empty($geral['pode_visualizar']) || !empty($tela['pode_visualizar']) || !empty($tela['pode_visualizar_arquivos']);
            $geral['pode_incluir'] = !empty($geral['pode_incluir']) || !empty($tela['pode_importar_arquivos']) || !empty($tela['pode_incluir']);
            $geral['pode_excluir'] = !empty($geral['pode_excluir']) || !empty($tela['pode_excluir']);
            $geral['pode_baixar_arquivos'] = !empty($geral['pode_baixar_arquivos']) || !empty($tela['pode_baixar_arquivos']);
        }
        return $geral;
    }

    /**
     * Arquivos do registro enviados pelo usuário da sessão (o dono do arquivo), como [nome => true].
     * Regra de 22/10/2026: o dono tem acesso total ao arquivo que enviou — ver, baixar/imprimir e excluir —, mesmo sem essas
     * permissões no perfil. Os demais usuários seguem o perfil. Registro encerrado (NC finalizada) continua só para consulta.
     * O dono vem do registro do envio (content_documents / content_versions, gravado por GedStorageService::store), lido
     * pela procedure sp_ged_arquivos_do_usuario (migração 330). Arquivos enviados pelo legado não têm dono registrado.
     */
    public static function arquivosDoUsuario(PDO $pdo, string $tipo, int $id): array
    {
        $usuario = (int)($_SESSION['usuario']['codusuario'] ?? 0);
        if ($usuario < 1 || $id < 1) return [];
        try {
            $s = $pdo->prepare('CALL sp_ged_arquivos_do_usuario(?, ?, ?)');
            $s->execute([strtoupper(trim($tipo)), (string)$id, $usuario]);
            $nomes = $s->fetchAll(PDO::FETCH_COLUMN); $s->closeCursor();
        } catch (Throwable) {
            return []; // sem a migração 330 vale só o perfil
        }
        $r = [];
        foreach ($nomes as $n) $r[basename((string)$n)] = true;
        return $r;
    }

    /** Transação usada no registro de acesso (auditoria) da janela de arquivos. */
    public static function transacao(string $tipo): string
    {
        foreach (self::telas($tipo) as $codigo) {
            $tela = PermissaoTransacao::obter($codigo);
            if (!empty($tela['pode_visualizar']) || !empty($tela['pode_visualizar_arquivos'])) return $codigo;
        }
        return 'GESTAO_ARQUIVOS';
    }

    /** Ação conferida por TransactionSecurity::connect na transação acima. */
    public static function acao(string $tipo): string
    {
        $codigo = self::transacao($tipo);
        if ($codigo === 'GESTAO_ARQUIVOS') return 'pode_visualizar';
        return !empty(PermissaoTransacao::obter($codigo)['pode_visualizar']) ? 'pode_visualizar' : 'pode_visualizar_arquivos';
    }

    /** O registro pode ser visto por este usuário? (restrição por unidade; ADMINISTRADOR vê tudo) */
    public static function registroPermitido(PDO $pdo, string $tipo, int $id): bool
    {
        // Migração 296: o fornecedor externo só acessa anexos de registros dos fornecedores ligados a ele.
        $tipoNormal = strtolower(trim($tipo));
        if (in_array($tipoNormal, self::TIPOS_NC, true)) {
            require_once __DIR__ . '/NaoConformidadeWorkflow.php';
            try {
                $cod = self::ocorrenciaNc($pdo, $tipoNormal, $id);
                if ($cod < 1) return false;
                $permissoes = [];
                foreach (NaoConformidadeWorkflow::MENUS as $m) $permissoes[$m] = PermissaoTransacao::obter($m);
                return NaoConformidadeWorkflow::podeVer($pdo, (int)($_SESSION['usuario']['codusuario'] ?? 0), $cod, $permissoes);
            } catch (Throwable) { return false; }
        }
        require_once __DIR__ . '/HomologacaoFornecedorAcesso.php';
        // Revisão G itens 149/150: documento do veículo/equipamento segue o acesso ao fornecedor dono (externo e interno restrito)
        if ($tipoNormal === 'homativo') {
            try { $s = $pdo->prepare('SELECT CODFORNECEDOR FROM vw_hom_ativo_doc WHERE CODATIVODOC = ?'); $s->execute([$id]); $forn = (int)$s->fetchColumn(); $s->closeCursor(); } catch (Throwable) { return false; }
            if ($forn < 1) return false;
            return HomologacaoFornecedorAcesso::modoBanco($pdo) === 0 || HomologacaoFornecedorAcesso::pode($pdo, 'fornecedor', $forn);
        }
        $tipoFornecedor = ['fornecedorcriterios' => 'documento', 'funcionarios' => 'funcionario', 'funcionariocriterios' => 'funcionariocriterio', 'funcionariotreinamentos' => 'funcionariotreinamento'][strtolower(trim($tipo))] ?? '';
        if (HomologacaoFornecedorAcesso::externo()) return $tipoFornecedor !== '' && HomologacaoFornecedorAcesso::pode($pdo, $tipoFornecedor, $id);
        // Migração 316: anexos da homologação seguem a mesma regra das telas — ADMINISTRADOR e ADM_DA_EMPRESA veem todos; o interno
        // restrito, os de fornecedores das suas unidades ou pelos quais responde (antes: só a unidade da Portaria, e o ADM_DA_EMPRESA
        // e o responsável de outra unidade ficavam sem ver as evidências).
        if ($tipoFornecedor !== '' && HomologacaoFornecedorAcesso::restricaoUnidadeAtiva($pdo)) return HomologacaoFornecedorAcesso::pode($pdo, $tipoFornecedor, $id);
        $procedure = self::PROCEDURE_UNIDADE[strtolower(trim($tipo))] ?? '';
        if ($procedure === '') return true;
        if (strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? '')) === 'ADMINISTRADOR') return true;
        try {
            $r = GreenPortalDatabase::call($pdo, 'CALL ' . $procedure . '(:id, :usuario)', ['id' => $id, 'usuario' => (int)($_SESSION['usuario']['codusuario'] ?? 0)]);
            return (int)($r[0]['PERMITIDO'] ?? 0) > 0;
        } catch (Throwable) {
            return false; // sem a migração (260/262) ninguém (exceto ADMINISTRADOR) vê esses anexos
        }
    }
}
