<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';
require_once __DIR__ . '/IdiomaService.php';

/*
 * Acesso do fornecedor externo à Homologação de Fornecedores (migração 296).
 *
 * Perfis (tabela perfis, código em $_SESSION['usuario']['codigo_perfil']):
 *   FORNECEDOR         — vê só os fornecedores ativos ligados a ele (Fornecedor x Usuários); nos documentos edita só
 *                        validade, protocolo, dias de reação, observação do fornecedor e evidências; não vê
 *                        Certificados, Auditoria, Produtos químicos, Equipamentos e Plano de ação.
 *   FORNECEDOR_GESTOR  — vê só os fornecedores ativos ligados a ele; edita os documentos como a empresa.
 * A ligação é conferida no servidor por CALL sp_fo_acesso_registro(usuario, tipo, id).
 *
 * Migração 316 — restrição por unidade do usuário interno (como o legado, decisões do usuário em 19/10/2026):
 *   ADMINISTRADOR e ADM_DA_EMPRESA veem todos os fornecedores (modo 0);
 *   os demais perfis internos (modo 2) veem os fornecedores das suas unidades (usuariosxunidades, vw_portaria_usuario_unidades)
 *   e os fornecedores pelos quais respondem (vw_homologacao_fornecedores_acesso_interno); sem unidade, só esses.
 *   Conferido no servidor por CALL sp_fo_acesso_interno_registro(usuario, tipo, id). Mesma regra em SQL:
 *   vw_homologacao_usuario_acesso (usada sem sessão pelo envio semanal dos relatórios).
 */
final class HomologacaoFornecedorAcesso
{
    public const FORNECEDOR = 'FORNECEDOR';
    public const FORNECEDOR_GESTOR = 'FORNECEDOR_GESTOR';
    /** Abas da Gestão de Fornecedores que o perfil FORNECEDOR não vê (chaves de HomologacaoFornecedorRelacionamentoGrid). */
    public const ABAS_OCULTAS_FORNECEDOR = ['certificados', 'auditoria', 'produtosquimicos', 'equipamentos', 'planodeacao'];
    /**
     * Telas só da empresa: negadas aos dois perfis do fornecedor externo em PermissaoTransacao::obter, mesmo que um perfil
     * associado (grupos do legado) dê acesso. Cadastros, ranking, histórico, desativados e Portaria (migração 297).
     */
    public const MENUS_SO_EMPRESA = [
        'HOMOLOGACAO_FORNECEDOR_RANKING', 'HOMOLOGACAO_FORNECEDORES_DESATIVADOS', 'HOMOLOGACAO_HISTORICO_DOCUMENTOS',
        'HOMOLOGACAO_TIPOS_FORNECEDORES', 'HOMOLOGACAO_CRITERIOS_FORNECEDORES',
        'HOMOLOGACAO_TIPOS_FUNCIONARIOS', 'HOMOLOGACAO_CRITERIOS_FUNCIONARIOS', 'HOMOLOGACAO_TIPOS_TREINAMENTOS',
        'PORTARIA_DOCUMENTACAO_FORNECEDOR', 'PORTARIA_DOCUMENTACAO_FUNCIONARIO',
    ];

    /**
     * Perfis de cliente que equivalem aos do fornecedor externo. Crown: FORNECEDORES = usuários de fornecedor (decisão do usuário
     * em 20/10/2026; 691 de 692 estão em Fornecedor x Usuários e no grupo FORNECEDORES do legado). GESTORES não entra: na Crown são
     * usuários internos (só 1 de 335 ligado a fornecedor). Mesma regra em SQL: vw_homologacao_usuario_acesso (migração 319).
     */
    public const PERFIS_EQUIVALENTES = ['FORNECEDORES' => self::FORNECEDOR];

    /** Código do perfil normalizado (maiúsculas, espaços e hífens viram "_"; equivalências de PERFIS_EQUIVALENTES). */
    public static function perfil(): string
    {
        $p = strtoupper(trim((string)($_SESSION['usuario']['codigo_perfil'] ?? '')));
        $p = (string)preg_replace('/[\s\-]+/', '_', $p);
        return self::PERFIS_EQUIVALENTES[$p] ?? $p;
    }

    /** Usuário de fornecedor (FORNECEDOR ou FORNECEDOR_GESTOR)? */
    public static function externo(): bool
    {
        return in_array(self::perfil(), [self::FORNECEDOR, self::FORNECEDOR_GESTOR], true);
    }

    /** Perfis que veem todos os fornecedores (sem restrição por unidade). */
    public const PERFIS_TODAS_UNIDADES = ['ADMINISTRADOR', 'ADM_DA_EMPRESA'];

    /** Modo de acesso: 0 = vê tudo, 1 = fornecedor externo, 2 = interno restrito por unidade (migração 316). */
    public static function modo(): int
    {
        if (self::externo()) return 1;
        return in_array(self::perfil(), self::PERFIS_TODAS_UNIDADES, true) ? 0 : 2;
    }

    /** A migração 316 está aplicada no banco? (antes dela o interno continua vendo tudo, como antes) */
    public static function restricaoUnidadeAtiva(PDO $pdo): bool
    {
        static $cache = [];
        $chave = spl_object_id($pdo);
        if (!isset($cache[$chave])) {
            try { $cache[$chave] = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_fo_acesso_interno_registro'")->fetchColumn() > 0; }
            catch (Throwable) { $cache[$chave] = false; }
        }
        return $cache[$chave];
    }

    /** Modo efetivo neste banco: o interno restrito (2) só vale depois da migração 316. Para os procedimentos do Painel e dos Relatórios. */
    public static function modoBanco(PDO $pdo): int
    {
        $m = self::modo();
        return $m === 2 && !self::restricaoUnidadeAtiva($pdo) ? 0 : $m;
    }

    /** Interno restrito às suas unidades (e aos fornecedores pelos quais responde)? */
    public static function unidade(): bool
    {
        return self::modo() === 2;
    }

    /** Usuário com alguma restrição de fornecedores (externo ou interno por unidade)? */
    public static function restrito(): bool
    {
        return self::modo() !== 0;
    }

    /** Fornecedor comum (edição restrita)? */
    public static function comum(): bool
    {
        return self::perfil() === self::FORNECEDOR;
    }

    /** Escopo de gravação dos documentos: FORNECEDOR (campos do fornecedor) ou EMPRESA. */
    public static function escopo(): string
    {
        return self::comum() ? 'FORNECEDOR' : 'EMPRESA';
    }

    /** O usuário logado pode acessar o registro? ADMINISTRADOR e ADM_DA_EMPRESA, sempre (a permissão do menu decide). */
    public static function pode(PDO $pdo, string $tipo, int $id): bool
    {
        $modo = self::modoBanco($pdo);
        if ($modo === 0) return true;
        if ($id < 1) return false;
        $proc = $modo === 1 ? 'sp_fo_acesso_registro' : 'sp_fo_acesso_interno_registro';
        try {
            $r = GreenPortalDatabase::call($pdo, 'CALL ' . $proc . '(:usuario, :tipo, :id)', ['usuario' => (int)($_SESSION['usuario']['codusuario'] ?? 0), 'tipo' => $tipo, 'id' => $id]);
            return (int)($r[0]['PERMITIDO'] ?? 0) === 1;
        } catch (Throwable) {
            return false; // sem a migração 296 (externo) / 316 (interno restrito) não acessa
        }
    }

    /** Fornecedor da requisição (CODFORNECEDOR na URL ou em filtros JSON), 0 se não houver. */
    public static function fornecedorDaRequisicao(): int
    {
        return self::idDaRequisicao('CODFORNECEDOR');
    }

    /** Código de um campo da requisição (na URL ou em filtros JSON), 0 se não houver. */
    public static function idDaRequisicao(string $campo): int
    {
        $id = null;
        foreach ($_GET as $k => $v) if (strcasecmp((string)$k, $campo) === 0 && is_scalar($v)) { $id = $v; break; }
        if ($id === null) {
            $filtros = json_decode((string)($_GET['filtros'] ?? '{}'), true);
            if (is_array($filtros)) foreach ($filtros as $k => $v) if (strcasecmp((string)$k, $campo) === 0 && is_scalar($v)) { $id = $v; break; }
        }
        $n = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $n === false ? 0 : (int)$n;
    }

    /** Tela só da empresa? (usado por PermissaoTransacao::obter) */
    public static function menuNegado(string $menu): bool
    {
        return in_array(strtoupper(trim($menu)), self::MENUS_SO_EMPRESA, true) && self::externo();
    }

    /** Mensagem padrão de acesso negado ao fornecedor. */
    public static function mensagemNegado(): string
    {
        if (self::unidade()) return IdiomaService::t('homologacao.fornecedor.sem_acesso_unidade', ['pt' => 'Este fornecedor não é de uma unidade sua.', 'en' => 'This supplier does not belong to one of your units.', 'es' => 'Este proveedor no es de una de sus unidades.']);
        return IdiomaService::t('homologacao.fornecedor.sem_acesso', ['pt' => 'Este fornecedor não está ligado ao seu usuário.', 'en' => 'This supplier is not linked to your user.', 'es' => 'Este proveedor no está vinculado a su usuario.']);
    }

    /** Encerra com 403 se o usuário externo não puder acessar o fornecedor (ou se a aba for oculta para ele). */
    public static function exigirFornecedor(PDO $pdo, int $codfornecedor, string $aba = ''): void
    {
        if (self::modoBanco($pdo) === 0) return;
        $negar = static function (string $msg): never {
            http_response_code(403);
            if (isset($_GET['acao']) || !empty($_POST['ajax'])) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE); }
            else { header('Content-Type: text/html; charset=utf-8'); echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); }
            exit;
        };
        if ($aba !== '' && self::comum() && in_array($aba, self::ABAS_OCULTAS_FORNECEDOR, true))
            $negar(IdiomaService::t('comum.permissao.negada', ['pt' => 'Perfil sem permissão de acesso!', 'en' => 'Profile does not have access permission!', 'es' => '¡El perfil no tiene permiso de acceso!']));
        if (!self::pode($pdo, 'fornecedor', $codfornecedor)) $negar(self::mensagemNegado());
    }

    /** Encerra com 403 se o usuário externo não puder acessar o funcionário (do fornecedor ligado a ele). */
    public static function exigirFuncionario(PDO $pdo, int $codfuncionario): void
    {
        if (self::modoBanco($pdo) === 0 || self::pode($pdo, 'funcionario', $codfuncionario)) return;
        http_response_code(403);
        $msg = self::unidade()
            ? IdiomaService::t('homologacao.funcionario.sem_acesso_unidade', ['pt' => 'Este funcionário não é de um fornecedor de uma unidade sua.', 'en' => 'This employee does not belong to a supplier of one of your units.', 'es' => 'Este empleado no es de un proveedor de una de sus unidades.'])
            : IdiomaService::t('homologacao.funcionario.sem_acesso', ['pt' => 'Este funcionário não é de um fornecedor ligado ao seu usuário.', 'en' => 'This employee does not belong to a supplier linked to your user.', 'es' => 'Este empleado no es de un proveedor vinculado a su usuario.']);
        if (isset($_GET['acao']) || !empty($_POST['ajax'])) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE); }
        else { header('Content-Type: text/html; charset=utf-8'); echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); }
        exit;
    }
}
