<?php
declare(strict_types=1);

/**
 * reset_teste.php — utilitário de linha de comando para restaurar o
 * ambiente de teste do GreenPortal entre rodadas de QA.
 *
 * Não é acessível via navegador (verifica PHP_SAPI === 'cli') e não
 * depende de sessão — conecta direto no banco do tenant informado.
 *
 * MODO 1 — zerar permissões de um perfil em uma ou mais transações
 * (Incluir/Visualizar/Editar/Excluir voltam a 0; Exportar Excel e Gerar
 * PDF são preservados como estavam, pois normalmente ficam fora do
 * escopo dos roteiros de permissão):
 *
 *   php reset_teste.php permissoes --db=gmodelo_dbp --perfil=GESTOR --transacoes=LICENCAS,REQUISITOS_LEGAIS
 *   php reset_teste.php permissoes --db=gmodelo_dbp --perfil=GESTOR --transacoes=LICENCAS --dry-run
 *
 * MODO 2 — remover logicamente (ATIVO=0) vínculos de responsabilidade
 * criados especificamente para um teste, por CODATRIBUICAO. Nunca
 * exclui fisicamente — o registro continua existindo como HISTORICA:
 *
 *   php reset_teste.php responsabilidades --db=gmodelo_dbp --ids=1024,1087
 *   php reset_teste.php responsabilidades --db=gmodelo_dbp --ids=1024 --dry-run
 *
 * Use --dry-run em qualquer modo para ver o que seria alterado sem
 * gravar nada.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script só pode ser executado via linha de comando (CLI).\n");
    exit(1);
}

function argValue(array $argv, string $name, ?string $default = null): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--' . $name . '=')) {
            return substr($arg, strlen('--' . $name . '='));
        }
    }
    return $default;
}

function hasFlag(array $argv, string $name): bool
{
    return in_array('--' . $name, $argv, true);
}

function envLoad(string $root): array
{
    $values = [];
    foreach (file($root . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        if (!isset($values[trim($key)])) {
            $values[trim($key)] = trim(trim($value), "\"'");
        }
    }
    return $values;
}

function dbConnect(array $env, string $database): PDO
{
    $dsn = 'mysql:host=' . ($env['GPT_DB_HOST'] ?? '127.0.0.1') .
        ';port=' . ($env['GPT_DB_PORT'] ?? '3306') .
        ';dbname=' . $database . ';charset=utf8mb4';
    return new PDO($dsn, $env['GPT_DB_USERNAME'] ?? '', $env['GPT_DB_PASSWORD'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function call(PDO $pdo, string $sql, array $params = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $statement->closeCursor();
    return $rows;
}

function j(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$root = dirname(__DIR__);
$env = envLoad($root);
$mode = $argv[1] ?? '';
$dryRun = hasFlag($argv, 'dry-run');
$database = argValue($argv, 'db', 'gmodelo_dbp');

if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$database)) {
    fwrite(STDERR, "Nome de banco inválido: {$database}\n");
    exit(1);
}

if (!in_array($mode, ['permissoes', 'responsabilidades'], true)) {
    fwrite(STDOUT, "Uso:\n");
    fwrite(STDOUT, "  php reset_teste.php permissoes --db=gmodelo_dbp --perfil=GESTOR --transacoes=LICENCAS,REQUISITOS_LEGAIS [--dry-run]\n");
    fwrite(STDOUT, "  php reset_teste.php responsabilidades --db=gmodelo_dbp --ids=1024,1087 [--dry-run]\n");
    exit(1);
}

try {
    $pdo = dbConnect($env, (string)$database);
} catch (Throwable $exception) {
    fwrite(STDERR, "Não foi possível conectar ao banco '{$database}': " . $exception->getMessage() . "\n");
    exit(1);
}

if ($dryRun) {
    fwrite(STDOUT, "[DRY-RUN] Nenhuma alteração será gravada.\n");
}

if ($mode === 'permissoes') {
    $perfilCodigo = strtoupper(trim((string)argValue($argv, 'perfil', '')));
    $transacoesArg = trim((string)argValue($argv, 'transacoes', ''));
    if ($perfilCodigo === '' || $transacoesArg === '') {
        fwrite(STDERR, "Informe --perfil=CODIGO e --transacoes=COD1,COD2,...\n");
        exit(1);
    }
    $transacoes = array_filter(array_map('trim', explode(',', $transacoesArg)));

    $profileRows = call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', [
        'tabela' => 'vw_perfis_grid', 'filtros' => j(['codigo' => $perfilCodigo]),
    ]);
    if (!$profileRows) {
        fwrite(STDERR, "Perfil '{$perfilCodigo}' não encontrado em {$database}.\n");
        exit(1);
    }
    $codPerfil = (int)$profileRows[0]['codperfil'];

    $exitCode = 0;
    foreach ($transacoes as $transacaoCodigo) {
        $transacaoCodigo = strtoupper($transacaoCodigo);
        $menuRows = call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', [
            'tabela' => 'vw_menus_grid', 'filtros' => j(['codigo' => $transacaoCodigo]),
        ]);
        if (!$menuRows) {
            fwrite(STDERR, "  [AVISO] Transação '{$transacaoCodigo}' não encontrada — pulando.\n");
            $exitCode = 2;
            continue;
        }
        $codMenu = (int)$menuRows[0]['codmenu'];

        $permissionRows = call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', [
            'tabela' => 'vw_permissoes_grid', 'filtros' => j(['codperfil' => $codPerfil, 'codmenu' => $codMenu]),
        ]);
        if (!$permissionRows) {
            fwrite(STDOUT, "  [{$perfilCodigo} / {$transacaoCodigo}] Nenhuma permissão cadastrada — nada a fazer.\n");
            continue;
        }
        $row = $permissionRows[0];
        $before = [
            'incluir' => (int)$row['pode_incluir'], 'visualizar' => (int)$row['pode_visualizar'],
            'editar' => (int)$row['pode_editar'], 'excluir' => (int)$row['pode_excluir'],
            'excel' => (int)$row['pode_exportar_excel'], 'pdf' => (int)$row['pode_gerar_pdf'],
        ];
        fwrite(STDOUT, "  [{$perfilCodigo} / {$transacaoCodigo}] antes: incluir={$before['incluir']} visualizar={$before['visualizar']} editar={$before['editar']} excluir={$before['excluir']} (excel={$before['excel']} pdf={$before['pdf']} preservados)\n");

        if ($before['incluir'] === 0 && $before['visualizar'] === 0 && $before['editar'] === 0 && $before['excluir'] === 0) {
            fwrite(STDOUT, "    já estava zerado.\n");
            continue;
        }

        if (!$dryRun) {
            $data = j([
                'codperfil' => $codPerfil, 'codmenu' => $codMenu,
                'pode_incluir' => 0, 'pode_visualizar' => 0, 'pode_editar' => 0, 'pode_excluir' => 0,
                'pode_exportar_excel' => $before['excel'], 'pode_gerar_pdf' => $before['pdf'],
            ]);
            call($pdo, 'CALL sp_crud_update(:tabela,:chave,:dados)', [
                'tabela' => 'permissoes', 'chave' => j(['codpermissao' => (int)$row['codpermissao']]), 'dados' => $data,
            ]);
        }
        fwrite(STDOUT, "    " . ($dryRun ? '[DRY-RUN] seria zerado.' : 'zerado com sucesso.') . "\n");
    }
    exit($exitCode);
}

if ($mode === 'responsabilidades') {
    $idsArg = trim((string)argValue($argv, 'ids', ''));
    if ($idsArg === '') {
        fwrite(STDERR, "Informe --ids=CODATRIBUICAO1,CODATRIBUICAO2,...\n");
        exit(1);
    }
    $ids = array_filter(array_map('intval', explode(',', $idsArg)));
    if (!$ids) {
        fwrite(STDERR, "Nenhum ID válido informado.\n");
        exit(1);
    }

    $adminRows = call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', [
        'tabela' => 'usuarios', 'filtros' => j(['userid' => 'admin']),
    ]);
    $codUsuarioAlteracao = $adminRows ? (int)$adminRows[0]['codusuario'] : 0;

    foreach ($ids as $id) {
        $rows = call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', [
            'tabela' => 'responsabilidade_atribuicoes', 'filtros' => j(['CODATRIBUICAO' => $id]),
        ]);
        if (!$rows) {
            fwrite(STDERR, "  [AVISO] CODATRIBUICAO {$id} não encontrado — pulando.\n");
            continue;
        }
        $row = $rows[0];
        $ativo = (int)($row['ATIVO'] ?? 0);
        fwrite(STDOUT, "  [CODATRIBUICAO {$id}] entidade={$row['ENTIDADE']} registro={$row['REGISTRO_ID']} ativo={$ativo}\n");
        if ($ativo === 0) {
            fwrite(STDOUT, "    já estava HISTORICA (ATIVO=0).\n");
            continue;
        }
        if (!$dryRun) {
            call($pdo, 'CALL sp_crud_update(:tabela,:chave,:dados)', [
                'tabela' => 'responsabilidade_atribuicoes',
                'chave' => j(['CODATRIBUICAO' => $id]),
                'dados' => j(['ATIVO' => 0, 'CODUSUARIOALTERACAO' => $codUsuarioAlteracao]),
            ]);
        }
        fwrite(STDOUT, "    " . ($dryRun ? '[DRY-RUN] seria marcado como HISTORICA.' : 'marcado como HISTORICA (remoção lógica, sem exclusão física).') . "\n");
    }
    exit(0);
}
