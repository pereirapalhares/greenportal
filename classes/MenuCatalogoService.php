<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';
require_once __DIR__ . '/MigracaoCrud.php';

/**
 * Catálogo único de menus (regra de 21/10/2026: não há menu específico de empresa).
 * O menu lateral vem do catálogo universal (guniversal_dbp.menus, classes/MenuService.php) e casa as permissões da
 * sessão pelo código; as permissões ficam no banco de cada empresa (permissoes.codmenu → menus da empresa).
 * Por isso todo menu é gravado primeiro no universal e replicado, pelo código, em todos os bancos de empresa
 * (mesmo codmenu quando ele está livre ali). Setup › Menu e as migrações que criam menus usam esta classe.
 * Acesso a dados por sp_crud_* (classes/MigracaoCrud.php).
 */
final class MenuCatalogoService
{
    /** Campos copiados do universal para as empresas (os que existirem em cada banco). */
    private const CAMPOS = ['codmenu_pai', 'modulo', 'codigo', 'label', 'label_pt', 'label_en', 'label_es', 'url', 'nivel', 'ordem', 'icone', 'ativo'];

    private ?PDO $universal = null;
    /** @var array<string, PDO>|null */
    private ?array $empresas = null;

    public function __construct(private ?array $env = null)
    {
        $this->env ??= GreenPortalDatabase::env();
    }

    public function universal(): PDO
    {
        if ($this->universal) return $this->universal;
        $u = GreenPortalDatabase::connect($this->env, $this->bancoUniversal(), 'GUNIVERSAL');
        $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $u->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $u->exec('SET NAMES utf8mb4');
        return $this->universal = $u;
    }

    public function bancoUniversal(): string
    {
        return (string)($this->env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
    }

    /** Bancos de empresa com a tabela menus (todos os g*_dbp, menos o universal e o da legislação). */
    public function empresas(): array
    {
        if ($this->empresas !== null) return $this->empresas;
        $is = GreenPortalDatabase::connect($this->env, 'information_schema', 'GPT');
        $s = $is->prepare("SELECT TABLE_SCHEMA FROM information_schema.COLUMNS WHERE TABLE_NAME = 'menus' AND TABLE_SCHEMA LIKE 'g%\\_dbp'
            AND TABLE_SCHEMA NOT IN (?, 'glegislacao_dbp') AND COLUMN_NAME IN ('codigo', 'codmenu_pai') GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT COLUMN_NAME) = 2 ORDER BY 1");
        $s->execute([$this->bancoUniversal()]);
        $this->empresas = [];
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $banco) {
            try {
                $p = GreenPortalDatabase::connect($this->env, (string)$banco, 'GPT');
                $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $p->exec('SET NAMES utf8mb4');
                $this->empresas[(string)$banco] = $p;
            } catch (Throwable) {
                // banco sem acesso: fica de fora (o diagnóstico de permissões aponta o menu que faltar)
            }
        }
        return $this->empresas;
    }

    /** Próximo codmenu livre em todos os bancos (universal e empresas), para o mesmo codmenu valer em todos. */
    public function proximoCodmenu(): int
    {
        $m = MigracaoCrud::maximo($this->universal(), 'menus', 'codmenu');
        foreach ($this->empresas() as $p) $m = max($m, MigracaoCrud::maximo($p, 'menus', 'codmenu'));
        return $m + 1;
    }

    /**
     * Grava o menu no universal ($codmenu = linha existente do universal; null = novo, ou o mesmo código se já existir)
     * e replica em todas as empresas. Retorna ['codmenu' => ..., 'empresas' => [banco => resultado]].
     */
    public function gravar(array $dados, ?int $codmenu = null): array
    {
        $u = $this->universal();
        $dados['codigo'] = strtoupper(trim((string)($dados['codigo'] ?? '')));
        if ($dados['codigo'] === '') throw new InvalidArgumentException('o código do menu é obrigatório');
        $cols = MigracaoCrud::colunas($u, 'menus');
        $dados = array_intersect_key($dados, array_flip(array_diff($cols, ['codmenu'])));
        $mesmoCodigo = MigracaoCrud::primeiro($u, 'menus', ['codigo' => $dados['codigo']]);
        if ($codmenu === null && $mesmoCodigo) $codmenu = (int)$mesmoCodigo['codmenu'];
        if ($mesmoCodigo && (int)$mesmoCodigo['codmenu'] !== $codmenu) throw new DomainException('uk_menus_codigo: o código ' . $dados['codigo'] . ' já existe no catálogo');
        $antes = $codmenu ? MigracaoCrud::primeiro($u, 'menus', ['codmenu' => $codmenu]) : null;
        if ($antes) {
            MigracaoCrud::atualizar($u, 'menus', ['codmenu' => $codmenu], $dados);
        } else {
            $codmenu = $this->proximoCodmenu();
            MigracaoCrud::inserir($u, 'menus', ['codmenu' => $codmenu] + $dados);
        }
        $codigoAntigo = $antes ? strtoupper((string)$antes['codigo']) : null;
        return ['codmenu' => $codmenu, 'empresas' => $this->replicar($dados['codigo'], $codigoAntigo !== $dados['codigo'] ? $codigoAntigo : null)];
    }

    /**
     * Copia o menu do universal (pelo código) para todas as empresas. $codigoAntigo: código anterior, quando foi renomeado
     * no universal (a linha da empresa com o código antigo é a que muda, para manter as permissões).
     */
    public function replicar(string $codigo, ?string $codigoAntigo = null, bool $atualizarExistente = true): array
    {
        $codigo = strtoupper($codigo);
        $linha = MigracaoCrud::primeiro($this->universal(), 'menus', ['codigo' => $codigo]);
        if (!$linha) throw new RuntimeException("menu $codigo não existe no universal");
        $linha = array_change_key_case($linha);
        $paiCodigo = null;
        if (!empty($linha['codmenu_pai'])) $paiCodigo = strtoupper((string)(MigracaoCrud::primeiro($this->universal(), 'menus', ['codmenu' => (int)$linha['codmenu_pai']])['codigo'] ?? ''));
        $r = [];
        foreach ($this->empresas() as $banco => $p) {
            try { $r[$banco] = $this->replicarEm($p, $linha, $paiCodigo, $codigoAntigo, $atualizarExistente); }
            catch (Throwable $e) { $r[$banco] = 'ERRO: ' . mb_substr($e->getMessage(), 0, 200); }
        }
        return $r;
    }

    private function replicarEm(PDO $p, array $linha, ?string $paiCodigo, ?string $codigoAntigo, bool $atualizarExistente): string
    {
        $cols = MigracaoCrud::colunas($p, 'menus');
        $dados = array_intersect_key($linha, array_flip(array_intersect(self::CAMPOS, $cols)));
        $dados['codmenu_pai'] = null;
        if ($paiCodigo) {
            $pai = MigracaoCrud::primeiro($p, 'menus', ['codigo' => $paiCodigo]);
            $dados['codmenu_pai'] = $pai ? (int)$pai['codmenu'] : null;
        }
        $atual = MigracaoCrud::primeiro($p, 'menus', ['codigo' => $linha['codigo']]);
        if (!$atual && $codigoAntigo) $atual = MigracaoCrud::primeiro($p, 'menus', ['codigo' => $codigoAntigo]);
        if ($atual) {
            if (!$atualizarExistente) return 'já existia (codmenu ' . $atual['codmenu'] . ')';
            MigracaoCrud::atualizar($p, 'menus', ['codmenu' => (int)$atual['codmenu']], $dados);
            return 'alterado (codmenu ' . $atual['codmenu'] . ')';
        }
        $cod = (int)$linha['codmenu'];
        $ocupado = MigracaoCrud::primeiro($p, 'menus', ['codmenu' => $cod]);
        $obs = '';
        if ($ocupado) { $obs = " (codmenu $cod já é {$ocupado['codigo']} neste banco)"; $cod = MigracaoCrud::maximo($p, 'menus', 'codmenu') + 1; }
        MigracaoCrud::inserir($p, 'menus', ['codmenu' => $cod] + $dados);
        return 'incluído com codmenu ' . $cod . $obs;
    }

    /**
     * Exclui o menu do universal e das empresas. Não exclui se tiver itens dentro ou se alguma empresa der acesso a ele
     * (as permissões sem nenhum acesso, criadas pela tela de Permissões, saem junto).
     */
    public function excluir(int $codmenu): array
    {
        $u = $this->universal();
        $linha = MigracaoCrud::primeiro($u, 'menus', ['codmenu' => $codmenu]);
        if (!$linha) throw new DomainException('menu não encontrado no catálogo');
        $codigo = strtoupper((string)$linha['codigo']);
        if (MigracaoCrud::primeiro($u, 'menus', ['codmenu_pai' => $codmenu])) throw new DomainException("o menu $codigo tem itens dentro: mova ou exclua os itens antes");
        $plano = [];
        foreach ($this->empresas() as $banco => $p) {
            $m = MigracaoCrud::primeiro($p, 'menus', ['codigo' => $codigo]);
            if (!$m) continue;
            if (MigracaoCrud::primeiro($p, 'menus', ['codmenu_pai' => (int)$m['codmenu']])) throw new DomainException("o menu $codigo tem itens dentro no banco $banco");
            $perms = MigracaoCrud::colunas($p, 'permissoes') ? MigracaoCrud::selecionar($p, 'permissoes', ['codmenu' => (int)$m['codmenu']]) : [];
            foreach ($perms as $perm) foreach ($perm as $campo => $valor) if (str_starts_with(strtolower((string)$campo), 'pode_') && (int)$valor === 1)
                throw new DomainException("o menu $codigo ainda dá acesso a perfis no banco $banco: retire as permissões antes de excluir");
            $plano[$banco] = [(int)$m['codmenu'], $perms];
        }
        $r = [];
        foreach ($plano as $banco => [$cod, $perms]) {
            $p = $this->empresas()[$banco];
            foreach ($perms as $perm) $this->apagar($p, 'permissoes', ['codpermissao' => (int)$perm['codpermissao']]);
            $this->apagar($p, 'menus', ['codmenu' => $cod]);
            $r[$banco] = 'excluído (codmenu ' . $cod . ', ' . count($perms) . ' permissão(ões) sem acesso)';
        }
        $this->apagar($u, 'menus', ['codmenu' => $codmenu]);
        return ['codigo' => $codigo, 'empresas' => $r];
    }

    private function apagar(PDO $pdo, string $tabela, array $chave): void
    {
        $s = $pdo->prepare('CALL sp_crud_delete(?, ?)');
        $s->execute([$tabela, json_encode($chave, JSON_UNESCAPED_UNICODE)]);
        $s->closeCursor();
    }
}
