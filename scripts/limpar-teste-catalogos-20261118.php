<?php
declare(strict_types=1);

/*
 * Teste CRUD de 18/11/2026 (Nova / Editar / Sugerir com IA nos catálogos da Legislação): tira dos catálogos os 3 itens criados
 * pelo teste — palavras-chave 876 e 877 (guniversal_dbp.palavras_chaves) e a pergunta 12686 (glegislacao_dbp.perguntas).
 * Só apaga se o texto começar com "ZZ Teste Claude" e se o item não tiver nenhum vínculo (leis, tipos ou checklist das empresas).
 * Sem --aplicar: só mostra.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\limpar-teste-catalogos-20261118.php [--aplicar]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/LegislacaoEmpresas.php';

$aplicar = in_array('--aplicar', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$leg = GreenPortalDatabase::connect($env, (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp'), 'GLEGISLACAO');
$uni = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$empresas = [];
foreach (LegislacaoEmpresas::migradas() as $cod => $e) { try { $empresas[(string)$e['banco']] = LegislacaoEmpresas::pdo((int)$cod); } catch (Throwable) {} }
foreach ([$leg, $uni, ...array_values($empresas)] as $p) $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$call = static function (PDO $pdo, string $sql, array $p = []): array {
    $s = $pdo->prepare($sql); $s->execute($p);
    $r = $s->fetchAll(PDO::FETCH_ASSOC); while ($s->nextRowset()) {} $s->closeCursor();
    return $r;
};
$existe = static function (PDO $pdo, string $tabela): bool {
    $s = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'); $s->execute([$tabela]);
    return (bool)$s->fetchColumn();
};
$vinculos = static function (array $bancos, array $tabelas, string $col, int $cod) use ($call, $existe): array {
    $out = [];
    foreach ($bancos as $nome => $pdo) foreach ($tabelas as $t) {
        if (!$existe($pdo, $t)) continue;
        $n = count($call($pdo, 'CALL sp_crud_select(?, ?, NULL, 10, 0)', [$t, json_encode([$col => $cod])]));
        if ($n) $out[] = "$nome.$t: $n";
    }
    return $out;
};

echo $aplicar ? "APLICAR\n" : "SIMULACAO (nada é gravado; use --aplicar)\n";
$itens = [
    ['banco' => $uni, 'tabela' => 'palavras_chaves', 'pk' => 'codpalavrachave', 'txt' => 'dscpalavrachave', 'cod' => 876,
     'vinc' => [['glegislacao_dbp' => $leg, 'guniversal_dbp' => $uni] + $empresas, ['legislacaoxpalavraschave', 'tiposxpalavras_chaves'], 'codpalavrachave']],
    ['banco' => $uni, 'tabela' => 'palavras_chaves', 'pk' => 'codpalavrachave', 'txt' => 'dscpalavrachave', 'cod' => 877,
     'vinc' => [['glegislacao_dbp' => $leg, 'guniversal_dbp' => $uni] + $empresas, ['legislacaoxpalavraschave', 'tiposxpalavras_chaves'], 'codpalavrachave']],
    ['banco' => $leg, 'tabela' => 'perguntas', 'pk' => 'CODPERGUNTA', 'txt' => 'DSCPERGUNTA', 'cod' => 12686,
     'vinc' => [['glegislacao_dbp' => $leg] + $empresas, ['legislacaoxpergunta', 'legislacaoxchecklist'], 'CODPERGUNTA']],
];
foreach ($itens as $i) {
    $r = $call($i['banco'], 'CALL sp_crud_select(?, ?, NULL, 1, 0)', [$i['tabela'], json_encode([$i['pk'] => $i['cod']])]);
    $rotulo = $i['tabela'] . ' #' . $i['cod'];
    if (!$r) { echo "$rotulo: não existe (já apagado)\n"; continue; }
    $texto = (string)(array_change_key_case($r[0], CASE_LOWER)[strtolower($i['txt'])] ?? '');
    if (!str_starts_with($texto, 'ZZ Teste Claude')) { echo "$rotulo: NÃO apagado — o texto não é do teste: \"$texto\"\n"; continue; }
    $v = $vinculos($i['vinc'][0], $i['vinc'][1], $i['vinc'][2], $i['cod']);
    if ($v) { echo "$rotulo: NÃO apagado — ainda tem vínculos: " . implode(', ', $v) . "\n"; continue; }
    if ($aplicar) $call($i['banco'], 'CALL sp_crud_delete(?, ?)', [$i['tabela'], json_encode([$i['pk'] => $i['cod']])]);
    echo "$rotulo \"$texto\": " . ($aplicar ? 'APAGADO' : 'seria apagado') . "\n";
}
