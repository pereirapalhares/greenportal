<?php
declare(strict_types=1);

final class PermissaoTransacao
{
    private static array $modulosPermitidos = [];

    /** Migração 384: grupo Manutenção Legal (cadastros compartilhados de legislação) só para o perfil ADMINISTRADOR. */
    public const MENUS_SO_ADMIN = ['MANUTENCAO_LEGAL', 'LEGISLACAO', 'LEGISLACAO_X_EMPRESAS', 'LEGISLACAO_X_PODER', 'TIPOS', 'AMBITOS', 'PERIGOS', 'RISCOS', 'ASSUNTOS'];

    public static function obter(string $codigo): array
    {
        $admin = (($_SESSION['usuario']['codigo_perfil'] ?? '') === 'ADMINISTRADOR');
        // Migração 297: telas só da empresa ficam fechadas para o fornecedor externo (FORNECEDOR / FORNECEDOR_GESTOR),
        // mesmo quando um perfil associado do legado dá acesso.
        if (!$admin && in_array(strtoupper(trim($codigo)), self::MENUS_SO_ADMIN, true)) return self::negada();
        if (!$admin) {
            require_once __DIR__ . '/HomologacaoFornecedorAcesso.php';
            if (HomologacaoFornecedorAcesso::menuNegado($codigo)) return self::negada();
        }
        require_once __DIR__ . '/ModuloEmpresaService.php';
        $modulo = ModuloEmpresaService::moduloDoMenu($codigo);
        if (!$admin && $modulo !== null) {
            if (!array_key_exists($modulo, self::$modulosPermitidos)) self::$modulosPermitidos[$modulo] = ModuloEmpresaService::empresaTem($modulo);
            if (!self::$modulosPermitidos[$modulo]) return self::negada();
        }
        foreach ((array)($_SESSION['permissoes'] ?? []) as $permission) {
            if (strtoupper((string)($permission['menu_codigo'] ?? '')) === strtoupper($codigo)) {
                return [
                    'pode_incluir' => $admin || !empty($permission['pode_incluir']),
                    // "Visualizar Total" inclui "Visualizar Parcial": quem vê tudo também abre a tela
                    'pode_visualizar' => $admin || !empty($permission['pode_visualizar']) || !empty($permission['pode_visualizar_total']),
                    'pode_visualizar_total' => $admin || !empty($permission['pode_visualizar_total']),
                    'pode_editar' => $admin || !empty($permission['pode_editar']),
                    'pode_excluir' => $admin || !empty($permission['pode_excluir']),
                    'pode_exportar_excel' => $admin || !empty($permission['pode_exportar_excel']),
                    'pode_gerar_pdf' => $admin || !empty($permission['pode_gerar_pdf']),
                    'pode_importar_arquivos' => $admin || !empty($permission['pode_importar_arquivos']),
                    'pode_visualizar_arquivos' => $admin || !empty($permission['pode_visualizar_arquivos']),
                    'pode_baixar_arquivos' => $admin || !empty($permission['pode_baixar_arquivos']),
                ];
            }
        }
        return self::negada($admin);
    }

    private static function negada(bool $admin = false): array
    {
        return ['pode_incluir' => $admin, 'pode_visualizar' => $admin, 'pode_visualizar_total' => $admin, 'pode_editar' => $admin, 'pode_excluir' => $admin, 'pode_exportar_excel' => $admin, 'pode_gerar_pdf' => $admin, 'pode_importar_arquivos' => $admin, 'pode_visualizar_arquivos' => $admin, 'pode_baixar_arquivos' => $admin];
    }
}
