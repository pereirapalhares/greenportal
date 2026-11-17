<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';
require_once __DIR__ . '/UsuarioSessao.php';
require_once __DIR__ . '/PermissaoTransacao.php';
require_once __DIR__ . '/TenantContext.php';
require_once __DIR__ . '/HomologacaoBancos.php';

/**
 * Não Conformidades — apoio às telas do fluxo (c491, migração 321).
 * Leitura só por views (vw_nc_*), gravação só por procedures (sp_nc_*). Quem age em cada etapa (regras do legado,
 * formulario/nc_s.php): emitente (Em criação / Recusado pelo Gestor), gestor (Com o Gestor, Avaliação da Eficácia) e
 * responsável (análise da causa e plano de ação). ADMINISTRADOR e ADM_DA_EMPRESA podem agir em qualquer etapa.
 */
final class NaoConformidadeWorkflow
{
    public const MENUS = ['NAO_CONFORMIDADES_LISTA_OCORRENCIAS', 'NAO_CONFORMIDADES_MINHAS_OCORRENCIAS', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_EMPRESA', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_FORNECEDOR', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_PLANEJAMENTO', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_CLIENTE', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_ASSISTENCIA', 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_ACIDENTE'];
    /** Menu que inclui cada tipo de NC (3 = Empresa, 1 = Fornecedor, 5 = Planejamento, 2 = Cliente, 6 = Assistência Técnica, 4 = Acidente/Incidente). */
    public const MENU_NOVA = [3 => 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_EMPRESA', 1 => 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_FORNECEDOR', 5 => 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_PLANEJAMENTO', 2 => 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_CLIENTE', 6 => 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_ASSISTENCIA', 4 => 'NAO_CONFORMIDADES_NOVA_OCORRENCIA_ACIDENTE'];
    public const ETAPAS_EMISSOR = ['Em criação', 'Recusado pelo Gestor'];
    public const ETAPAS_ANALISE = ['Em Analise da Causa Raiz', 'Plano de Ação', 'Plano de Acao', 'Recusado pela Avaliacao da Eficacia'];

    /** Sessão, conexão do tenant e permissões das transações de NC. Encerra com 401/403 quando não há acesso ao módulo. */
    public static function iniciar(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (empty($_SESSION['autenticado']) || empty($_SESSION['tenant']['banco_dados'])) self::falhar(401, 'Sua sessão expirou. Entre novamente no portal.');
        try {
            $pdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
            if (!UsuarioSessao::atualizar($pdo, $_SESSION)) throw new RuntimeException('sessao');
        } catch (Throwable) { self::falhar(401, 'Sua sessão expirou. Entre novamente no portal.'); }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $permissoes = [];
        foreach (self::MENUS as $m) $permissoes[$m] = PermissaoTransacao::obter($m);
        $acesso = false;
        foreach ($permissoes as $p) if (!empty($p['pode_visualizar'])) $acesso = true;
        if (!$acesso) self::falhar(403, 'Seu perfil não tem permissão para acessar esta transação.');
        return [$pdo, (int)($_SESSION['usuario']['codusuario'] ?? 0), $permissoes];
    }

    public static function falhar(int $codigo, string $mensagem): never
    {
        http_response_code($codigo);
        if (isset($_GET['acao'])) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'message' => $mensagem], JSON_UNESCAPED_UNICODE); }
        else { header('Content-Type: text/html; charset=utf-8'); echo htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8'); }
        exit;
    }

    /** Etapa da análise da causa e plano de ação (inclui "Plano de Ação" gravado com acentuação quebrada pelo legado). */
    public static function etapaAnalise(string $etapa): bool
    {
        return in_array($etapa, self::ETAPAS_ANALISE, true) || str_starts_with($etapa, 'Plano de A');
    }

    /** Usuário de fornecedor (FORNECEDOR, FORNECEDOR_GESTOR ou o equivalente da empresa, ex.: FORNECEDORES na Crown). Como no
     *  legado ($_SESSION['FORNECEDOR'] = 'S'), só age na análise da causa e no plano de ação quando é o responsável. */
    public static function fornecedorExterno(): bool
    {
        require_once __DIR__ . '/HomologacaoFornecedorAcesso.php';
        return HomologacaoFornecedorAcesso::externo();
    }

    /** Crown: a NC de Fornecedor só oferece as listas de distribuição da categoria Fornecedores, os outros tipos as demais (legado).
     *  Migração 339: parâmetro LISTAS_FORNECEDOR_SEPARADAS da Configuração do módulo (padrão = a regra da Crown). */
    public static function separaListasFornecedor(?PDO $pdo = null): bool
    {
        if ($pdo) { require_once __DIR__ . '/NaoConformidadeConfig.php'; return NaoConformidadeConfig::sim($pdo, 'LISTAS_FORNECEDOR_SEPARADAS'); }
        return in_array(HomologacaoBancos::legado((string)($_SESSION['tenant']['banco_dados'] ?? '')), ['green_crown', 'green_homologacao_crown'], true);
    }

    /** ADMINISTRADOR ou ADM_DA_EMPRESA (podem agir em qualquer etapa, como o ADM do legado). */
    public static function admin(): bool
    {
        $p = (string)preg_replace('/[\s\-]+/', '_', strtoupper(trim((string)($_SESSION['usuario']['codigo_perfil'] ?? ''))));
        return in_array($p, ['ADMINISTRADOR', 'ADM_DA_EMPRESA'], true);
    }

    public static function ocorrencia(PDO $pdo, int $id): array
    {
        $s = $pdo->prepare('SELECT * FROM vw_nc_ocorrencia WHERE CODOCORRENCIA = ?');
        $s->execute([$id]); $r = $s->fetch(PDO::FETCH_ASSOC) ?: []; $s->closeCursor();
        foreach (['ETAPA', 'etapa'] as $k) if (isset($r[$k])) $r[$k] = self::etapa((string)$r[$k]);
        return $r;
    }

    /**
     * Etapa como deve aparecer (migração 331): desfaz a acentuação quebrada gravada pelo legado ("Plano de AÃ§Ã£o" → "Plano de Ação"),
     * a mesma regra de fn_nc_etapa nas views. Só leitura: o valor gravado não muda.
     */
    public static function etapa(string $etapa): string
    {
        if (!str_contains($etapa, 'Ã')) return $etapa;
        $c = @mb_convert_encoding($etapa, 'Windows-1252', 'UTF-8');
        return is_string($c) && mb_check_encoding($c, 'UTF-8') && substr_count($c, '?') <= substr_count($etapa, '?') ? $c : $etapa;
    }

    /** Vê a ocorrência: administrador, quem vê a lista geral inteira ou quem participa dela (emitente, gestor, responsáveis). */
    public static function podeVer(PDO $pdo, int $uid, int $id, array $permissoes): bool
    {
        if (self::admin() || !empty($permissoes['NAO_CONFORMIDADES_LISTA_OCORRENCIAS']['pode_visualizar_total'])) return true;
        $s = $pdo->prepare('SELECT 1 FROM vw_nc_envolvidos WHERE CODOCORRENCIA = ? AND CODUSUARIO = ? LIMIT 1');
        $s->execute([$id, $uid]); $ok = (bool)$s->fetchColumn(); $s->closeCursor();
        return $ok;
    }

    /** Usuários ativos (gestor, responsável, responsável de ação). O legado aceita qualquer usuário ativo. */
    public static function usuarios(PDO $pdo, array $incluir = []): array
    {
        $out = [];
        foreach ($pdo->query('SELECT CODUSUARIO, NOME, ATIVO FROM vw_nc_usuarios ORDER BY NOME')->fetchAll(PDO::FETCH_ASSOC) as $u)
            if ((int)$u['ATIVO'] === 1 || in_array((int)$u['CODUSUARIO'], array_map('intval', $incluir), true)) $out[(string)$u['CODUSUARIO']] = (string)$u['NOME'];
        return $out;
    }

    /**
     * Responsáveis que o fornecedor externo pode escolher numa ação (decisão do usuário 22/10/2026, c519): os usuários do
     * próprio fornecedor e os da empresa que já participam da NC (vw_nc_envolvidos), além dele mesmo e de quem já é
     * responsável de alguma ação. $usuarios = lista de usuarios() (id => nome).
     */
    public static function responsaveisAcaoExterno(PDO $pdo, int $uid, int $codigo, array $usuarios, array $atuais = []): array
    {
        $ids = [$uid];
        $s = $pdo->prepare('SELECT f.CODUSUARIO FROM vw_nc_usuarios_fornecedor f WHERE f.CODFORNECEDOR IN (SELECT m.CODFORNECEDOR FROM vw_nc_usuarios_fornecedor m WHERE m.CODUSUARIO = ?)');
        $s->execute([$uid]); array_push($ids, ...array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN))); $s->closeCursor();
        if ($codigo) {
            $s = $pdo->prepare('SELECT e.CODUSUARIO FROM vw_nc_envolvidos e WHERE e.CODOCORRENCIA = ? AND NOT EXISTS (SELECT 1 FROM vw_nc_usuarios_fornecedor f WHERE f.CODUSUARIO = e.CODUSUARIO)');
            $s->execute([$codigo]); array_push($ids, ...array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN))); $s->closeCursor();
        }
        foreach ($atuais as $a) if ((int)$a > 0) $ids[] = (int)$a;
        $ids = array_flip(array_unique($ids));
        return array_filter($usuarios, static fn($nome, $id) => isset($ids[(int)$id]), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Listas de opções (unidades, áreas, classificação...) agrupadas por LISTA. Migração 332: por padrão só os itens ativos (para
     * escolher); $todos = true inclui os desativados (para mostrar o que já está gravado nas NCs).
     */
    public static function opcoes(PDO $pdo, bool $todos = false): array
    {
        try { $linhas = $pdo->query('SELECT LISTA, CODIGO, DESCRICAO, ATIVO FROM vw_nc_opcoes ORDER BY LISTA, DESCRICAO')->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable) { $linhas = $pdo->query('SELECT LISTA, CODIGO, DESCRICAO, 1 AS ATIVO FROM vw_nc_opcoes ORDER BY LISTA, DESCRICAO')->fetchAll(PDO::FETCH_ASSOC); } // antes da 332
        $out = [];
        foreach ($linhas as $o)
            if (trim((string)$o['DESCRICAO']) !== '' && ($todos || (int)$o['ATIVO'] === 1)) $out[$o['LISTA']][(string)$o['CODIGO']] = (string)$o['DESCRICAO'];
        return $out;
    }

    /** Campos que o tipo de NC usa (nc_tipo_de_naoconformidadexcampos, como o legado). */
    public static function campos(PDO $pdo, int $tipo): array
    {
        $s = $pdo->prepare('SELECT CAMPO FROM vw_nc_tipo_campos WHERE CODTIPONAOCONFORMIDADE = ?');
        $s->execute([$tipo]); $r = array_map('strtoupper', $s->fetchAll(PDO::FETCH_COLUMN)); $s->closeCursor();
        return $r;
    }

    public static function listar(PDO $pdo, string $view, int $id, string $ordem): array
    {
        if (!preg_match('/^vw_nc_[a-z_]+$/', $view) || !preg_match('/^[A-Z_]+( DESC)?$/', $ordem)) throw new InvalidArgumentException('consulta');
        $s = $pdo->prepare("SELECT * FROM `$view` WHERE CODOCORRENCIA = ? ORDER BY $ordem");
        $s->execute([$id]); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
        return $r;
    }

    /** Executa uma procedure sp_nc_* e devolve a 1ª linha do 1º resultado. */
    public static function chamar(PDO $pdo, string $proc, array $params): array
    {
        if (!preg_match('/^sp_nc_[a-z_]+$/', $proc)) throw new InvalidArgumentException('procedure');
        // Tudo ou nada (c511): validação que falha depois de gravar (ex.: "Salvar e enviar" de uma NC nova com campo obrigatório
        // vazio) não deixa ocorrência criada pela metade — como o legado, que validava antes de gravar.
        $transacao = !$pdo->inTransaction() && $pdo->beginTransaction();
        try {
            $s = $pdo->prepare('CALL ' . $proc . '(' . implode(', ', array_fill(0, count($params), '?')) . ')');
            $s->execute(array_values($params));
            $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
            do { try { $s->fetchAll(); } catch (Throwable) {} } while ($s->nextRowset());
            $s->closeCursor();
            if ($transacao) $pdo->commit();
        } catch (Throwable $x) {
            if ($transacao && $pdo->inTransaction()) $pdo->rollBack();
            throw $x;
        }
        return $r;
    }

    /** Texto do SIGNAL (validação das procedures) ou mensagem genérica. */
    public static function erro(Throwable $e): string
    {
        if ($e instanceof PDOException && preg_match('/1644\s*(.+)$/s', $e->getMessage(), $m)) return trim($m[1]);
        if ($e instanceof RuntimeException) return $e->getMessage();
        error_log('NC: ' . $e->getMessage());
        return 'Não foi possível concluir a operação. Tente novamente.';
    }

    /** Crown: a avaliação da eficácia exige a data da análise e o responsável pela pendência (como no legado, chamado 15652015).
     *  Migração 339: parâmetro EFICACIA_EXIGE_PRAZO da Configuração do módulo (padrão = a regra da Crown). */
    public static function exigePrazoEficacia(?PDO $pdo = null): bool
    {
        if ($pdo) { require_once __DIR__ . '/NaoConformidadeConfig.php'; return NaoConformidadeConfig::sim($pdo, 'EFICACIA_EXIGE_PRAZO'); }
        return HomologacaoBancos::legado((string)($_SESSION['tenant']['banco_dados'] ?? '')) === 'green_crown';
    }

    public static function csrf(): string
    {
        if (empty($_SESSION['nc_workflow_csrf'])) $_SESSION['nc_workflow_csrf'] = bin2hex(random_bytes(32));
        return (string)$_SESSION['nc_workflow_csrf'];
    }

    public static function exigirCsrf(): void
    {
        $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? ''));
        if (!hash_equals(self::csrf(), $token)) self::falhar(419, 'Token de segurança inválido. Recarregue a tela.');
    }

    public static function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}
