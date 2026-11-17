<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura) — complemento do diagnostico-tipos-provisorios-217.php.
 * O mesmo código de tipo em OUTRO cliente só vale como nome original se aquele banco tiver sido copiado do Atlas
 * (ou o Atlas dele). Para cada banco candidato verifica:
 *  - parentesco: entre os códigos que existem nos dois, quantos têm o MESMO nome de tipo que green_atlas;
 *  - funcionários: para cada código provisório, se os funcionários do Atlas com esse código também existem lá com o
 *    mesmo código (mesmo nome de pessoa) — prova de que é a mesma função.
 * Saída: tela + storage/logs/diagnostico_tipos_provisorios_217_origem.json
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-tipos-provisorios-217-origem.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'gatlas_dbp', 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
$linhas = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };

$codigos = array_map('intval', array_column($linhas("SELECT codtipofuncionario c FROM gatlas_dbp.fo_tipofuncionarios WHERE COALESCE(DSCTIPO,'') LIKE '%- migra%o 217' ORDER BY 1"), 'c'));
$lista = implode(',', $codigos ?: [0]);
$candidatos = ['green_modelo', 'green_crown', 'green_homologacao_crown', 'green_elis', 'green_luxottica'];
$saida = ['diagnostico' => 'origem dos nomes dos tipos provisórios 217', 'data' => date('c'), 'bancos' => []];

foreach ($candidatos as $b) {
    $r = [];
    try {
        $par = $linhas("SELECT COUNT(*) comuns, SUM(CONVERT(TRIM(a.DSCTIPO) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci = CONVERT(TRIM(o.DSCTIPO) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci) iguais FROM green_atlas.fo_tipofuncionarios a JOIN `$b`.fo_tipofuncionarios o ON o.codtipofuncionario = a.codtipofuncionario")[0];
        $r['parentesco_tipos'] = ['codigos_em_comum' => (int)$par['comuns'], 'com_mesmo_nome' => (int)$par['iguais'],
            'exemplos_diferentes' => array_map(static fn($x) => "{$x['c']}: atlas=\"{$x['a']}\" / $b=\"{$x['o']}\"", $linhas("SELECT a.codtipofuncionario c, a.DSCTIPO a, o.DSCTIPO o FROM green_atlas.fo_tipofuncionarios a JOIN `$b`.fo_tipofuncionarios o ON o.codtipofuncionario = a.codtipofuncionario WHERE NOT (CONVERT(TRIM(a.DSCTIPO) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci <=> CONVERT(TRIM(o.DSCTIPO) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci) ORDER BY 1 LIMIT 5"))];
        $fun = $linhas("SELECT COUNT(*) comuns, SUM(CONVERT(TRIM(a.NOME) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci = CONVERT(TRIM(o.NOME) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci) iguais FROM green_atlas.fo_funcionarios a JOIN `$b`.fo_funcionarios o ON o.CODFUNCIONARIO = a.CODFUNCIONARIO")[0];
        $r['parentesco_funcionarios'] = ['codigos_em_comum' => (int)$fun['comuns'], 'com_mesmo_nome' => (int)$fun['iguais']];
        // Por código provisório: funcionários do Atlas (mesmo nome) que lá têm o mesmo código de tipo
        $por = $linhas("SELECT a.CODTIPOFUNCIONARIO cod, COUNT(DISTINCT a.CODFUNCIONARIO) atlas, COUNT(DISTINCT o.CODFUNCIONARIO) mesmos, MAX(t.DSCTIPO) nome
            FROM green_atlas.fo_funcionarios a
            LEFT JOIN `$b`.fo_funcionarios o ON CONVERT(TRIM(o.NOME) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci = CONVERT(TRIM(a.NOME) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AND o.CODTIPOFUNCIONARIO = a.CODTIPOFUNCIONARIO
            LEFT JOIN `$b`.fo_tipofuncionarios t ON t.codtipofuncionario = a.CODTIPOFUNCIONARIO
            WHERE a.CODTIPOFUNCIONARIO IN ($lista) GROUP BY a.CODTIPOFUNCIONARIO HAVING mesmos > 0 ORDER BY 1");
        $r['codigos_confirmados_por_funcionarios'] = array_map(static fn($x) => "{$x['cod']}: \"{$x['nome']}\" — {$x['mesmos']} de {$x['atlas']} funcionários iguais", $por);
    } catch (Throwable $e) { $r['erro'] = $e->getMessage(); }
    $saida['bancos'][$b] = $r;
}

$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents(dirname(__DIR__) . '/storage/logs/diagnostico_tipos_provisorios_217_origem.json', $json);
echo $json . PHP_EOL;
