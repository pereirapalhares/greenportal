<?php
declare(strict_types=1);

final class UsuarioSessao
{
    private static function call(PDO $pdo, string $sql, array $params): PDOStatement
    {
        // sp_crud_select monta SQL dinamicamente e pode invalidar o metadata
        // de um prepared statement nativo quando views/tabelas são recompiladas.
        // A emulação evita o erro 1615 nesse cenário.
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $statement = $pdo->prepare($sql);
            try {
                $statement->execute($params);
                return $statement;
            } catch (PDOException $exception) {
                $statement->closeCursor();
                $mysqlCode = (int)($exception->errorInfo[1] ?? 0);
                $isReprepareError = $mysqlCode === 1615 || str_contains($exception->getMessage(), '1615');
                if (!$isReprepareError || $attempt === 1) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Não foi possível executar a consulta da sessão.');
    }

    public static function atualizar(PDO $pdo, array &$sessao): bool
    {
        if (empty($sessao['autenticado']) || empty($sessao['usuario']['userid'])) {
            return false;
        }

        $statement = self::call($pdo, 'CALL sp_crud_select(:tabela, :filtros, NULL, 1, 0)', [
            'tabela' => 'vw_usuarios_grid',
            'filtros' => json_encode(['userid' => (string)$sessao['usuario']['userid']], JSON_UNESCAPED_UNICODE),
        ]);
        $usuario = $statement->fetch(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        if (!$usuario || !(bool)$usuario['ativo']) {
            self::encerrar($sessao);
            return false;
        }

        $codPerfil = (int)($usuario['codperfil'] ?? ($sessao['usuario']['codperfil'] ?? 0));
        $codPerfis = $codPerfil > 0 ? [$codPerfil] : [];
        $overrideProfiles = [];
        // Usuários migrados podem possuir vários grupos/perfis legados. O
        // campo usuarios.codperfil permanece como perfil principal, mas a
        // autorização efetiva deve unir os perfis associados ao usuário.
        try {
            $perfisLegado = static function (int $efetivo) use ($pdo, $usuario): array {
                $s = $pdo->prepare('CALL sp_usuario_perfis_legado(?, ?)');
                $s->execute([(int)$usuario['codusuario'], $efetivo]);
                $r = $s->fetchAll(PDO::FETCH_COLUMN);
                $s->closeCursor();
                return $r;
            };
            foreach ($perfisLegado(1) as $associatedProfile) {
                $associatedProfile = (int)$associatedProfile;
                if ($associatedProfile > 0 && !in_array($associatedProfile, $overrideProfiles, true)) $overrideProfiles[] = $associatedProfile;
            }
            if ($overrideProfiles) {
                // Perfil composto da migração + o perfil principal do usuário: o que for definido em
                // Segurança › Permissões para o perfil principal também vale (antes o principal era ignorado)
                $codPerfis = array_values(array_unique(array_merge($codPerfis, $overrideProfiles)));
            } else {
                foreach ($perfisLegado(0) as $associatedProfile) {
                    $associatedProfile = (int)$associatedProfile;
                    if ($associatedProfile > 0 && !in_array($associatedProfile, $codPerfis, true)) $codPerfis[] = $associatedProfile;
                }
            }
        } catch (PDOException) {
            // Bancos anteriores à migração da associação continuam usando o
            // único codperfil da tabela usuarios.
        }
        $sessao['usuario'] = [
            'codusuario' => (int)$usuario['codusuario'],
            'userid' => (string)$usuario['userid'],
            'nome' => (string)$usuario['nome'],
            'email' => (string)($usuario['email'] ?? ''),
            'codperfil' => $codPerfil,
            'codigo_perfil' => (string)($usuario['perfil_codigo'] ?? ''),
            'perfil_nome' => (string)($usuario['perfil_nome'] ?? ''),
            'ativo' => true,
        ];
        $permissionsByMenu = [];
        foreach ($codPerfis as $profileId) {
            $permissionStatement = self::call($pdo, 'CALL sp_crud_select(:tabela, :filtros, NULL, 1000, 0)', [
                'tabela' => 'vw_permissoes_grid',
                'filtros' => json_encode(['codperfil' => $profileId], JSON_UNESCAPED_UNICODE),
            ]);
            foreach ($permissionStatement->fetchAll(PDO::FETCH_ASSOC) as $permission) {
                $menuId = (int)($permission['codmenu'] ?? 0);
                if (!$menuId) continue;
                if (!isset($permissionsByMenu[$menuId])) $permissionsByMenu[$menuId] = $permission;
                else foreach (['pode_incluir','pode_visualizar','pode_visualizar_total','pode_editar','pode_excluir','pode_exportar_excel','pode_gerar_pdf','pode_importar_arquivos','pode_visualizar_arquivos','pode_baixar_arquivos'] as $flag) $permissionsByMenu[$menuId][$flag] = max((int)($permissionsByMenu[$menuId][$flag] ?? 0), (int)($permission[$flag] ?? 0));
            }
            $permissionStatement->closeCursor();
        }
        // Migração 297: o fornecedor externo (FORNECEDOR / FORNECEDOR_GESTOR) não recebe as telas só da empresa, mesmo que um
        // perfil associado do legado as libere (some do menu; PermissaoTransacao::obter também nega).
        $perfilSessao = (string)preg_replace('/[\s\-]+/', '_', strtoupper(trim((string)$sessao['usuario']['codigo_perfil'])));
        // Migração 319: FORNECEDORES (Crown) equivale a FORNECEDOR (HomologacaoFornecedorAcesso::PERFIS_EQUIVALENTES).
        if (in_array($perfilSessao, ['FORNECEDOR', 'FORNECEDOR_GESTOR', 'FORNECEDORES'], true)) {
            require_once __DIR__ . '/HomologacaoFornecedorAcesso.php';
            $permissionsByMenu = array_filter($permissionsByMenu, static fn(array $p): bool => !in_array(strtoupper((string)($p['menu_codigo'] ?? '')), HomologacaoFornecedorAcesso::MENUS_SO_EMPRESA, true));
        }
        // Migração 384: Manutenção Legal só para o ADMINISTRADOR (some do menu dos outros perfis, mesmo com permissão gravada)
        if ($sessao['usuario']['codigo_perfil'] !== 'ADMINISTRADOR') {
            require_once __DIR__ . '/PermissaoTransacao.php';
            $permissionsByMenu = array_filter($permissionsByMenu, static fn(array $p): bool => !in_array(strtoupper((string)($p['menu_codigo'] ?? '')), PermissaoTransacao::MENUS_SO_ADMIN, true));
        }
        $sessao['permissoes'] = array_values($permissionsByMenu);
        $sessao['contexto_atualizado_em'] = date('c');
        return true;
    }

    public static function encerrar(array &$sessao): void
    {
        $sessao = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
