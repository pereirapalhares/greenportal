<?php
declare(strict_types=1);

/*
 * Backup dos bancos do GreenPortal num repositório Git PRIVADO separado do código (17/11/2026).
 * Padrão: gcrown_dbp, gatlas_dbp, guniversal_dbp, glegislacao_dbp, gluxottica_dbp.
 *
 * Cada banco vira <repo>\<banco>\<banco>.sql.gz (mysqldump completo: estrutura + dados, rotinas, gatilhos e eventos),
 * dividido em partes de 90 MB (<banco>.sql.gz.part001, ...) quando passa disso — o GitHub recusa arquivos acima de 100 MB.
 * MANIFEST.json por banco (data, tamanho, sha256, partes). Depois: git add, commit e push no repositório de backups.
 * Mesmo jeito de chamar o mysqldump do backup do portal (scripts/backup-worker.php): credenciais GPT_DB_* do .env num
 * arquivo temporário (a senha não aparece na linha de comando), --single-transaction, collation da sessão (BACKUP_COLLATION).
 *
 * Uso (PowerShell, na pasta do greenportal):
 *   php scripts\backup-bancos-git.php --simular                       tamanho de cada banco, nada é gravado
 *   php scripts\backup-bancos-git.php                                 dump + commit + push
 *   php scripts\backup-bancos-git.php --sem-push                      dump + commit, sem enviar
 *   php scripts\backup-bancos-git.php --bancos=gcrown_dbp,gatlas_dbp  só esses bancos
 *   php scripts\backup-bancos-git.php --extrair=gcrown_dbp --destino=E:\restaurar
 *        junta as partes e descompacta em E:\restaurar\gcrown_dbp.sql (depois: mysql -u USUARIO -p gcrown_dbp < arquivo)
 * Repositório: --repo=PASTA, ou BACKUP_GIT_DIR no .env, ou E:\backups\greenportal_dbp (precisa ser um clone do
 * repositório privado de backups). Git: GIT_PATH no .env, ou "git" no PATH, ou C:\Program Files\Git\cmd\git.exe.
 */

date_default_timezone_set('America/Sao_Paulo');

const BANCOS_PADRAO = ['gcrown_dbp', 'gatlas_dbp', 'guniversal_dbp', 'glegislacao_dbp', 'gluxottica_dbp'];
const PARTE_BYTES = 90 * 1048576;

$raiz = dirname(__DIR__);
$args = $argv ?? [];
$opt = static function (string $nome) use ($args): ?string {
    foreach ($args as $a) if (str_starts_with($a, '--' . $nome . '=')) return substr($a, strlen($nome) + 3);
    return null;
};
$flag = static fn(string $nome): bool => in_array('--' . $nome, $args, true);
$sair = static function (string $msg, int $codigo = 1): never { fwrite(STDERR, $msg . PHP_EOL); exit($codigo); };

// .env (só leitura; nada dele é impresso)
$env = [];
foreach (@file($raiz . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linha) {
    $linha = trim($linha);
    if ($linha === '' || $linha[0] === '#' || !str_contains($linha, '=')) continue;
    [$k, $v] = explode('=', $linha, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$repo = rtrim((string)($opt('repo') ?? ($env['BACKUP_GIT_DIR'] ?? 'E:\\backups\\greenportal_dbp')), '\\/');
$bancos = $opt('bancos') !== null ? array_values(array_filter(array_map('trim', explode(',', strtolower((string)$opt('bancos')))))) : BANCOS_PADRAO;
foreach ($bancos as $b) if (!preg_match('/^g[a-z0-9_]+_dbp$/', $b)) $sair("Banco inválido: $b");

// ---------------------------------------------------------------- extrair (restauração)
if (($ext = $opt('extrair')) !== null) {
    $ext = strtolower($ext);
    if (!preg_match('/^g[a-z0-9_]+_dbp$/', $ext)) $sair("Banco inválido: $ext");
    $destino = rtrim((string)($opt('destino') ?? ($raiz . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'restaurar')), '\\/');
    $pasta = $repo . DIRECTORY_SEPARATOR . $ext;
    $partes = glob($pasta . DIRECTORY_SEPARATOR . $ext . '.sql.gz.part*') ?: [];
    sort($partes, SORT_STRING);
    if (!$partes && is_file($pasta . DIRECTORY_SEPARATOR . $ext . '.sql.gz')) $partes = [$pasta . DIRECTORY_SEPARATOR . $ext . '.sql.gz'];
    if (!$partes) $sair("Nenhum backup de $ext em $pasta");
    if (!is_dir($destino) && !mkdir($destino, 0775, true)) $sair("Não foi possível criar $destino");
    $gzJunto = $destino . DIRECTORY_SEPARATOR . $ext . '.sql.gz';
    $out = fopen($gzJunto, 'wb');
    foreach ($partes as $p) { $in = fopen($p, 'rb'); stream_copy_to_stream($in, $out); fclose($in); }
    fclose($out);
    $man = json_decode((string)@file_get_contents($pasta . DIRECTORY_SEPARATOR . 'MANIFEST.json'), true) ?: [];
    if (!empty($man['sha256']) && hash_file('sha256', $gzJunto) !== $man['sha256']) $sair('As partes juntas não conferem com o MANIFEST (sha256 diferente).');
    $sql = $destino . DIRECTORY_SEPARATOR . $ext . '.sql';
    $gz = gzopen($gzJunto, 'rb'); $o = fopen($sql, 'wb');
    while (!gzeof($gz)) fwrite($o, (string)gzread($gz, 1048576));
    gzclose($gz); fclose($o); @unlink($gzJunto);
    echo json_encode(['ok' => true, 'banco' => $ext, 'arquivo' => $sql, 'bytes' => filesize($sql), 'backup_de' => $man['criado_em'] ?? null,
        'restaurar' => 'mysql -u USUARIO -p --default-character-set=utf8mb4 ' . $ext . ' < "' . $sql . '"  (crie o banco antes, se não existir)'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

// ---------------------------------------------------------------- conferências
$exe = (string)($env['MYSQLDUMP_PATH'] ?? 'E:\\wamp\\bin\\mysql\\mysql8.4.7\\bin\\mysqldump.exe');
if (!is_file($exe)) $sair("mysqldump não encontrado em $exe (configure MYSQLDUMP_PATH no .env)");
$git = (string)($env['GIT_PATH'] ?? '');
if ($git === '') {
    $w = trim((string)@shell_exec('where git 2>NUL'));
    $git = $w !== '' ? strtok($w, "\r\n") : 'C:\\Program Files\\Git\\cmd\\git.exe';
}
if (!$flag('simular')) {
    if (!is_file($git)) $sair("git não encontrado ($git). Configure GIT_PATH no .env.");
    if (!is_dir($repo . DIRECTORY_SEPARATOR . '.git')) $sair("$repo não é um repositório Git. Clone antes o repositório privado de backups:\n  git clone https://github.com/USUARIO/greenportal-backups.git \"$repo\"");
}
$pdo = new PDO('mysql:host=' . ($env['GPT_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['GPT_DB_PORT'] ?? '3306') . ';charset=utf8mb4',
    (string)($env['GPT_DB_USERNAME'] ?? ''), (string)($env['GPT_DB_PASSWORD'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tam = $pdo->query("SELECT TABLE_SCHEMA b, ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1048576, 1) mb, COUNT(*) t FROM information_schema.TABLES
    WHERE TABLE_SCHEMA IN ('" . implode("','", $bancos) . "') GROUP BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_ASSOC);
$info = [];
foreach ($tam as $r) $info[$r['b']] = ['tamanho_mb_no_mysql' => (float)$r['mb'], 'tabelas_e_views' => (int)$r['t']];
foreach ($bancos as $b) if (!isset($info[$b])) $sair("Banco $b não existe ou o usuário GPT_DB_USERNAME não tem acesso a ele.");
if ($flag('simular')) {
    echo json_encode(['ok' => true, 'modo' => 'SIMULACAO', 'repositorio' => $repo, 'repositorio_ok' => is_dir($repo . DIRECTORY_SEPARATOR . '.git'),
        'git' => is_file($git) ? $git : 'NÃO ENCONTRADO', 'bancos' => $info,
        'observacao' => 'O .sql.gz costuma ficar com 10% a 20% do tamanho no MySQL. Cada execução acrescenta isso ao histórico do repositório.'],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

// ---------------------------------------------------------------- dump de cada banco
$col = trim((string)($env['BACKUP_COLLATION'] ?? ''));
if (!preg_match('/^utf8mb4_[a-z0-9_]+$/', $col)) $col = 'utf8mb4_general_ci';
$rel = ['ok' => true, 'repositorio' => $repo, 'inicio' => date('Y-m-d H:i:s'), 'bancos' => []];
foreach ($bancos as $banco) {
    $t0 = microtime(true);
    $pasta = $repo . DIRECTORY_SEPARATOR . $banco;
    if (!is_dir($pasta)) mkdir($pasta, 0775, true);
    $tmp = $pasta . DIRECTORY_SEPARATOR . $banco . '.sql.gz.tmp';
    $cnf = tempnam(sys_get_temp_dir(), 'gpbk');
    $q = static fn(string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    file_put_contents($cnf, "[client]\nuser=" . $q((string)($env['GPT_DB_USERNAME'] ?? '')) . "\npassword=" . $q((string)($env['GPT_DB_PASSWORD'] ?? ''))
        . "\nhost=" . $q((string)($env['GPT_DB_HOST'] ?? '127.0.0.1')) . "\nport=" . (int)($env['GPT_DB_PORT'] ?? 3306) . "\n");
    $erroArq = $tmp . '.stderr';
    $erro = '';
    try {
        $cmd = [$exe, '--defaults-extra-file=' . $cnf, '--single-transaction', '--quick', '--routines', '--triggers', '--events', '--hex-blob',
            '--default-character-set=utf8mb4', "--init-command=SET SESSION collation_connection = '$col'", '--no-tablespaces', '--set-gtid-purged=OFF',
            '--skip-dump-date', '--databases', $banco];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', $erroArq, 'w']], $pipes);
        if (!is_resource($proc)) throw new RuntimeException('não foi possível iniciar o mysqldump');
        $gz = gzopen($tmp, 'wb6');
        $fim = '';
        while (!feof($pipes[1])) {
            $bloco = fread($pipes[1], 1048576);
            if ($bloco === false || $bloco === '') continue;
            gzwrite($gz, $bloco);
            $fim = substr($fim . $bloco, -400);
        }
        fclose($pipes[1]); gzclose($gz);
        $codigo = proc_close($proc);
        $stderr = trim((string)preg_replace('/^.*(Warning: A partial dump|GTID|Using a password).*$/mi', '', (string)@file_get_contents($erroArq)));
        if ($codigo !== 0) $erro = 'mysqldump terminou com código ' . $codigo . ($stderr !== '' ? ': ' . mb_substr($stderr, 0, 400) : '');
        elseif (!str_contains($fim, 'Dump completed')) $erro = 'o dump não terminou (sem "Dump completed" no fim)' . ($stderr !== '' ? ': ' . mb_substr($stderr, 0, 400) : '');
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    } finally {
        @unlink($cnf); @unlink($erroArq);
    }
    if ($erro !== '') {
        @unlink($tmp);
        $rel['ok'] = false;
        $rel['bancos'][$banco] = ['erro' => $erro, 'observacao' => 'backup anterior deste banco mantido no repositório'];
        continue;
    }
    // troca o backup anterior: apaga as partes antigas e grava as novas
    foreach (glob($pasta . DIRECTORY_SEPARATOR . $banco . '.sql.gz*') ?: [] as $velho) if ($velho !== $tmp) @unlink($velho);
    $bytes = filesize($tmp);
    $sha = hash_file('sha256', $tmp);
    $partes = [];
    if ($bytes <= PARTE_BYTES) {
        rename($tmp, $pasta . DIRECTORY_SEPARATOR . $banco . '.sql.gz');
        $partes[] = $banco . '.sql.gz';
    } else {
        $in = fopen($tmp, 'rb');
        for ($n = 1; !feof($in); $n++) {
            $nome = sprintf('%s.sql.gz.part%03d', $banco, $n);
            $out = fopen($pasta . DIRECTORY_SEPARATOR . $nome, 'wb');
            $copiado = stream_copy_to_stream($in, $out, PARTE_BYTES);
            fclose($out);
            if ($copiado === 0) { @unlink($pasta . DIRECTORY_SEPARATOR . $nome); break; }
            $partes[] = $nome;
        }
        fclose($in); @unlink($tmp);
    }
    $man = ['banco' => $banco, 'criado_em' => date(DATE_ATOM), 'bytes_gz' => $bytes, 'sha256' => $sha, 'partes' => $partes,
        'tamanho_mb_no_mysql' => $info[$banco]['tamanho_mb_no_mysql'], 'tabelas_e_views' => $info[$banco]['tabelas_e_views'],
        'conteudo' => 'mysqldump completo: estrutura, dados, rotinas, gatilhos e eventos (utf8mb4)',
        'restaurar' => 'php scripts\\backup-bancos-git.php --extrair=' . $banco . ' --destino=PASTA (no greenportal)'];
    file_put_contents($pasta . DIRECTORY_SEPARATOR . 'MANIFEST.json', json_encode($man, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    $rel['bancos'][$banco] = ['mb_compactado' => round($bytes / 1048576, 1), 'partes' => count($partes), 'segundos' => round(microtime(true) - $t0)];
}

// README do repositório (uma vez)
$readme = $repo . DIRECTORY_SEPARATOR . 'README.md';
if (!is_file($readme)) file_put_contents($readme, "# Backups dos bancos do GreenPortal\n\nRepositório PRIVADO. Contém dados de clientes: não tornar público.\n\n"
    . "Gerado por `scripts/backup-bancos-git.php` (no repositório greenportal). Cada pasta é um banco: `<banco>.sql.gz` ou partes `.part001`, `.part002`... "
    . "(o GitHub recusa arquivos acima de 100 MB) e `MANIFEST.json` com data, tamanho e sha256.\n\n"
    . "## Restaurar\n\n```\nphp scripts\\backup-bancos-git.php --extrair=gcrown_dbp --destino=E:\\restaurar\n"
    . "mysql -u USUARIO -p --default-character-set=utf8mb4 < E:\\restaurar\\gcrown_dbp.sql\n```\n\n"
    . "O dump usa `--databases`, então o `.sql` cria o banco se ele não existir. Versões anteriores: `git log` / `git checkout <commit> -- <banco>`.\n");

// ---------------------------------------------------------------- git
$rodar = static function (array $a) use ($git, $repo): array {
    $p = proc_open(array_merge([$git, '-C', $repo], $a), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $o = stream_get_contents($pipes[1]); $e = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), trim($o . "\n" . $e)];
};
$rodar(['add', '-A']);
[$c, $saida] = $rodar(['commit', '-m', 'Backup ' . date('Y-m-d H:i') . ' — ' . implode(', ', array_keys(array_filter($rel['bancos'], static fn($x) => !isset($x['erro']))))]);
$rel['commit'] = $c === 0 ? 'feito' : (str_contains($saida, 'nothing to commit') ? 'nada mudou' : 'ERRO: ' . mb_substr($saida, 0, 400));
if ($c !== 0 && !str_contains($saida, 'nothing to commit')) $rel['ok'] = false;
if (!$flag('sem-push') && $c === 0) {
    [$p, $saida] = $rodar(['push', '-u', 'origin', 'HEAD']);
    $rel['push'] = $p === 0 ? 'enviado' : 'ERRO: ' . mb_substr($saida, 0, 400);
    if ($p !== 0) $rel['ok'] = false;
}
[, $tamRepo] = $rodar(['count-objects', '-vH']);
if (preg_match('/size-pack:\s*(.+)/', $tamRepo, $m)) $rel['tamanho_historico_git'] = trim($m[1]);
$rel['fim'] = date('Y-m-d H:i:s');
$log = $raiz . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (is_dir($log)) @file_put_contents($log . DIRECTORY_SEPARATOR . 'backup_bancos_git.json', json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
