<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';

/** Controle de habilitação dos módulos comerciais por empresa. */
final class ModuloEmpresaService
{
    public const MODULO_LEGAL = 'MODULO_LEGAL';
    public const HOMOLOGACAO = 'HOMOLOGACAO_FORNECEDORES';
    public const NAO_CONFORMIDADES = 'NAO_CONFORMIDADES';

    public static function catalogo(): array
    {
        return [
            self::MODULO_LEGAL => ['pt' => 'Módulo Legal / Gestão Ambiental', 'en' => 'Legal Module / Environmental Management', 'es' => 'Módulo Legal / Gestión Ambiental'],
            self::HOMOLOGACAO => ['pt' => 'Homologação de Fornecedores', 'en' => 'Supplier Homologation', 'es' => 'Homologación de Proveedores'],
            self::NAO_CONFORMIDADES => ['pt' => 'Não Conformidades', 'en' => 'Nonconformities', 'es' => 'No conformidades'],
        ];
    }

    public static function ehAdministrador(): bool
    {
        return strtoupper((string)($_SESSION['usuario']['codigo_perfil'] ?? '')) === 'ADMINISTRADOR';
    }

    /** Retorna o módulo comercial do menu, considerando a raiz da árvore. */
    public static function moduloDoMenu(string $codigo): ?string
    {
        try {
            $e = GreenPortalDatabase::env();
            $u = GreenPortalDatabase::connect($e, (string)($e['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
            $rows = $u->query('SELECT codmenu,codmenu_pai,codigo FROM vw_menus_arvore')->fetchAll(PDO::FETCH_ASSOC);
            $byId = [];
            foreach ($rows as $row) $byId[(int)$row['codmenu']] = $row;
            $byCode = [];
            foreach ($rows as $row) $byCode[strtoupper((string)$row['codigo'])] = $row;
            $current = $byCode[strtoupper($codigo)] ?? null;
            if (!$current) return null;
            $guard = 0;
            while ($current && $guard++ < 50) {
                $root = strtoupper((string)$current['codigo']);
                if ($root === self::MODULO_LEGAL || $root === 'MANUTENCAO_LEGAL') return self::MODULO_LEGAL;
                if ($root === self::HOMOLOGACAO) return self::HOMOLOGACAO;
                if ($root === self::NAO_CONFORMIDADES) return self::NAO_CONFORMIDADES;
                $current = $byId[(int)($current['codmenu_pai'] ?? 0)] ?? null;
            }
        } catch (Throwable) {
            return '__MODULO_INDISPONIVEL__';
        }
        return null;
    }

    public static function empresaTem(string $modulo): bool
    {
        if (self::ehAdministrador()) return true;
        $codempresa = (int)($_SESSION['tenant']['codempresa'] ?? 0);
        if (empty($_SESSION['tenant']['banco_dados']) || $codempresa < 0 || !isset(self::catalogo()[$modulo])) return false;
        try {
            $e = GreenPortalDatabase::env();
            $u = GreenPortalDatabase::connect($e, (string)($e['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
            $s = $u->prepare('SELECT ativo FROM vw_empresa_modulos_situacao WHERE codempresa = :empresa AND codigo_modulo = :modulo LIMIT 1');
            $s->execute(['empresa' => $codempresa, 'modulo' => $modulo]);
            return (int)$s->fetchColumn() === 1;
        } catch (Throwable) { return false; }
    }

    public static function permitidoParaMenu(string $codigo): bool
    {
        $modulo = self::moduloDoMenu($codigo);
        return $modulo === null || self::empresaTem($modulo);
    }
}
