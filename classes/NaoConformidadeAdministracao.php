<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';

/**
 * Não Conformidades › Administração (migração 333, 22/10/2026). Apoio das telas de administração:
 * leitura só por views (sp_crud_select, em páginas de 1000) e gravação só por procedures (sp_nc_lista_*, sp_nc_participante_*,
 * sp_email_*). Mensagens das procedures (SIGNAL 45000) chegam ao usuário como estão.
 */
final class NaoConformidadeAdministracao
{
    public const MENU_LISTAS = 'NAO_CONFORMIDADES_LISTAS_DISTRIBUICAO';
    public const MENU_PARTICIPANTES = 'NAO_CONFORMIDADES_PARTICIPANTES';
    public const MENU_EMAILS = 'ADMINISTRACAO_EMAILS_ENVIADOS';
    public const MENU_CORRECAO = 'NAO_CONFORMIDADES_CORRECAO';
    public const MENU_TRANSFERENCIA = 'NAO_CONFORMIDADES_TRANSFERENCIA';
    public const MENU_SANEAMENTO = 'NAO_CONFORMIDADES_SANEAMENTO';
    public const FAIXA_LISTA = 50001;

    /**
     * Migração 335 (22/10/2026, pedido do usuário: organizar Não Conformidades › Administração em menus de 2º nível):
     * subgrupos dentro do grupo NAO_CONFORMIDADES_CADASTROS ("Administração"): código => [rótulos pt/en/es, ícone, ordem].
     */
    public const SUB_OCORRENCIAS = 'NAO_CONFORMIDADES_ADM_OCORRENCIAS';
    public const SUB_PESSOAS = 'NAO_CONFORMIDADES_ADM_PESSOAS';
    public const SUB_CADASTROS = 'NAO_CONFORMIDADES_ADM_CADASTROS';
    public const SUB_CADASTROS_CLIENTE = 'NAO_CONFORMIDADES_ADM_CADASTROS_CLIENTE';
    public const SUB_CONFIGURACAO = 'NAO_CONFORMIDADES_ADM_CONFIGURACAO';
    public const SUBGRUPOS = [
        self::SUB_OCORRENCIAS => [['Ocorrências', 'Occurrences', 'Ocurrencias'], 'bi-clipboard-check', 1],
        self::SUB_PESSOAS => [['Listas e participantes', 'Lists and participants', 'Listas y participantes'], 'bi-people', 2],
        self::SUB_CADASTROS => [['Cadastros gerais', 'General registers', 'Registros generales'], 'bi-journal-bookmark', 3],
        self::SUB_CADASTROS_CLIENTE => [['Cadastros do cliente', 'Customer registers', 'Registros del cliente'], 'bi-buildings', 4],
        // Migração 339: Configuração do módulo (só ADMINISTRADOR; o menu é criado pela migração 339)
        self::SUB_CONFIGURACAO => [['Configuração', 'Settings', 'Configuración'], 'bi-sliders2', 5],
    ];
    /** Cadastros do tipo Cliente (chaves de NaoConformidadeCadastros::LISTA); os demais ficam em Cadastros gerais. */
    public const CADASTROS_CLIENTE = ['clientes', 'unidades_cliente', 'produtos', 'tipos_documento', 'tipos_reclamacao', 'tipos_tampa', 'tamanhos_lata'];

    /**
     * Menus da administração: código => [url, rótulos pt/en/es, ícone, ordem no subgrupo, subgrupo].
     */
    public const MENUS = [
        self::MENU_CORRECAO => ['/principal/naoconformidades_correcao.php', ['Correção de ocorrência', 'Occurrence correction', 'Corrección de ocurrencia'], 'bi-pencil-square', 1, self::SUB_OCORRENCIAS],
        self::MENU_TRANSFERENCIA => ['/principal/naoconformidades_transferencia.php', ['Transferência de responsabilidades', 'Responsibility transfer', 'Transferencia de responsabilidades'], 'bi-arrow-left-right', 2, self::SUB_OCORRENCIAS],
        self::MENU_SANEAMENTO => ['/principal/naoconformidades_saneamento.php', ['Painel de saneamento', 'Clean-up panel', 'Panel de saneamiento'], 'bi-clipboard2-pulse', 3, self::SUB_OCORRENCIAS],
        self::MENU_LISTAS => ['/principal/naoconformidades_listasdistribuicao.php', ['Listas de distribuição', 'Distribution lists', 'Listas de distribución'], 'bi-people', 1, self::SUB_PESSOAS],
        self::MENU_PARTICIPANTES => ['/principal/naoconformidades_participantes.php', ['Participantes externos', 'External participants', 'Participantes externos'], 'bi-person-badge', 2, self::SUB_PESSOAS],
    ];

    /** Subgrupo do menu de um cadastro (chave de NaoConformidadeCadastros::LISTA). */
    public static function subgrupoCadastro(string $chave): string
    {
        return in_array($chave, self::CADASTROS_CLIENTE, true) ? self::SUB_CADASTROS_CLIENTE : self::SUB_CADASTROS;
    }

    /** Rótulos das ações da administração no histórico da NC (aba Histórico e PDF). */
    public const ACOES_HISTORICO = [
        'CORRECAO_ADMIN' => 'Corrigida pela administração', 'ADMIN_REABRIR' => 'Reaberta pela administração', 'ADMIN_DEVOLVER' => 'Devolvida uma etapa pela administração',
        'ADMIN_ENCERRAR' => 'Encerrada administrativamente', 'TRANSFERENCIA_ADMIN' => 'Responsabilidade transferida pela administração', 'GESTOR_ATRIBUIDO_ADMIN' => 'Gestor atribuído pela administração',
    ];

    /** Linhas de uma view (sp_crud_select; filtros exatos com ['CAMPO' => ['value' => 'x', 'exact' => true]]). */
    public static function ler(PDO $pdo, string $view, array $filtros = [], int $maximo = 20000): array
    {
        if (!preg_match('/^vw?_[a-z0-9_]+$/', $view)) throw new InvalidArgumentException('view inválida');
        $linhas = [];
        for ($offset = 0; $offset < $maximo; $offset += 1000) {
            $lote = GreenPortalDatabase::call($pdo, 'CALL sp_crud_select(?, ?, NULL, 1000, ?)', [$view, $filtros ? json_encode($filtros, JSON_UNESCAPED_UNICODE) : null, $offset]);
            foreach ($lote as $l) $linhas[] = $l;
            if (count($lote) < 1000) break;
        }
        return $linhas;
    }

    public static function exato(string|int $valor): array { return ['value' => (string)$valor, 'exact' => true]; }

    /** Chama a procedure e devolve as linhas; erro de regra (SIGNAL) vira DomainException com a mensagem da procedure. */
    public static function proc(PDO $pdo, string $procedure, array $parametros = []): array
    {
        if (!preg_match('/^sp_[a-z0-9_]+$/', $procedure)) throw new InvalidArgumentException('procedure inválida');
        try {
            $s = $pdo->prepare('CALL ' . $procedure . '(' . implode(', ', array_fill(0, count($parametros), '?')) . ')');
            $s->execute(array_values($parametros));
            $r = $s->columnCount() > 0 ? $s->fetchAll(PDO::FETCH_ASSOC) : [];
            while ($s->nextRowset()) { /* resultados de procedures chamadas por dentro */ }
            $s->closeCursor();
            return $r;
        } catch (PDOException $e) {
            if (preg_match('/1644\s+(.+)$/su', $e->getMessage(), $m)) throw new DomainException(trim($m[1]), 1644, $e);
            throw $e;
        }
    }

    public static function csrf(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (empty($_SESSION['nc_admin_csrf'])) $_SESSION['nc_admin_csrf'] = bin2hex(random_bytes(16));
        return (string)$_SESSION['nc_admin_csrf'];
    }

    public static function csrfValido(?string $token): bool
    {
        return is_string($token) && $token !== '' && !empty($_SESSION['nc_admin_csrf']) && hash_equals((string)$_SESSION['nc_admin_csrf'], $token);
    }

    public static function data(mixed $valor, bool $hora = false): string
    {
        $v = trim((string)$valor);
        if ($v === '' || str_starts_with($v, '0000')) return '';
        try { $d = new DateTimeImmutable($v); } catch (Throwable) { return $v; }
        $en = class_exists('IdiomaService') && IdiomaService::idioma() === 'en';
        return $d->format(($en ? 'm/d/Y' : 'd/m/Y') . ($hora ? ' H:i' : ''));
    }

    public static function ordenacao(mixed $valor): string
    {
        $v = trim((string)$valor);
        return ($v === '' || str_starts_with($v, '0000')) ? '0000-00-00' : substr($v, 0, 19);
    }
}
