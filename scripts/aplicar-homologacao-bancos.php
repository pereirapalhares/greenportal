<?php
declare(strict_types=1);

/*
 * Aplica, na ordem, a cadeia de migrações da Homologação de Fornecedores (216 → 303) nos bancos informados.
 * Lista de bancos e legado de cada um: classes/HomologacaoBancos.php (gmodelo, gatlas, gmodelo2, gcrown, ggdamericana, gganchieta).
 *
 * Uso (em E:\wamp\apps\greenportal):
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-homologacao-bancos.php --banco=gcrown_dbp,ggdamericana_dbp,gganchieta_dbp --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-homologacao-bancos.php --banco=gganchieta_dbp
 *   Opcional: --de=293 (começa nesta migração)  --ate=298 (para nesta)
 * --banco é obrigatório (pode repetir ou separar por vírgula). Cada migração recebe os mesmos --banco.
 *
 * --simular: roda só as migrações que têm simulação; as de estrutura sem simulação (216, 218–221) aparecem como "não simulada".
 *   Num banco novo a simulação das etapas seguintes acusa "rode antes a migração N": é esperado, porque nada foi gravado.
 * Sem --simular: grava, uma migração por vez, e PARA na primeira que falhar (corrija e rode de novo com --de=N).
 * A 217 (saneamento da carga da Atlas e da MODELO) não faz parte da cadeia. Na 301 as regras dos bancos novos ficam desligadas;
 * na 302 nada é convertido nos bancos novos (ver os comentários de cada script).
 * Saída completa de cada migração em storage\logs\homologacao_bancos\<migração>.json.
 */

require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

// migração => [script, tem --simular]
const AHB_CADEIA = [
    '216' => ['aplicar-migracao-216-homologacao-fornecedores.php', false],
    '218' => ['aplicar-migracao-218-funcionarios-somente-com-fornecedor.php', false],
    '219' => ['aplicar-migracao-219-funcionarios-grid-codunidade.php', false],
    '220' => ['aplicar-migracao-220-funcionarios-grid-busca-collation.php', false],
    '221' => ['aplicar-migracao-221-funcionarios-documentos-ia.php', false],
    '245' => ['aplicar-migracao-245-criterios-funcionarios.php', true],
    '248' => ['aplicar-migracao-248-cadastros-homologacao.php', true],
    '249' => ['aplicar-migracao-249-tipos-funcionarios.php', true],
    '251' => ['aplicar-migracao-251-tipos-funcionarios-igual-legado.php', true],
    '260' => ['aplicar-migracao-260-portaria-documentacao-fornecedor.php', true],
    '261' => ['aplicar-migracao-261-portaria-documentacao-fornecedor-ia.php', true],
    '262' => ['aplicar-migracao-262-portaria-documentacao-funcionario.php', true],
    '263' => ['aplicar-migracao-263-portaria-documentacao-funcionario-treinamentos.php', true],
    '289' => ['aplicar-migracao-289-ranking-fornecedores.php', true],
    '292' => ['aplicar-migracao-292-tipos-criterios-fornecedores.php', true],
    '293' => ['aplicar-migracao-293-documentos-fornecedor.php', true],
    '294' => ['aplicar-migracao-294-documentos-repetidos.php', true],
    '295' => ['aplicar-migracao-295-historico-documentos.php', true],
    '296' => ['aplicar-migracao-296-fornecedores-desativados-externo.php', true],
    '297' => ['aplicar-migracao-297-funcionarios-fornecedor-externo.php', true],
    '298' => ['aplicar-migracao-298-painel-homologacao.php', true],
    '299' => ['aplicar-migracao-299-menu-homologacao.php', true],
    '300' => ['aplicar-migracao-300-protocolo-ged-homologacao.php', true],
    '301' => ['aplicar-migracao-301-regras-documentos-homologacao.php', true],
    '302' => ['aplicar-migracao-302-protocolo-sem-cobranca.php', true],
    '303' => ['aplicar-migracao-303-funcionario-itens.php', true],
];

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$de = null; $ate = null;
foreach ($args as $a) {
    if (preg_match('/^--de=(\d{3})$/', $a, $m)) $de = $m[1];
    if (preg_match('/^--ate=(\d{3})$/', $a, $m)) $ate = $m[1];
}
try {
    if (!array_filter($args, static fn($a) => str_starts_with((string)$a, '--banco='))) throw new InvalidArgumentException('Informe os bancos com --banco= (ex.: --banco=gcrown_dbp,gganchieta_dbp).');
    $bancos = HomologacaoBancos::lista($args);
    foreach ([$de, $ate] as $n) if ($n !== null && !isset(AHB_CADEIA[$n])) throw new InvalidArgumentException("Migração $n não faz parte da cadeia.");
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }

$raiz = dirname(__DIR__);
$logs = $raiz . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'homologacao_bancos';
if (!is_dir($logs)) @mkdir($logs, 0775, true);
$repassar = ['--banco=' . implode(',', $bancos)];
$resumo = ['cadeia' => 'homologacao 216-303', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => $bancos, 'inicio' => date('c'), 'migracoes' => []];

$ativo = $de === null;
foreach (AHB_CADEIA as $num => [$script, $temSimulacao]) {
    $num = (string)$num;
    if (!$ativo && $num === $de) $ativo = true;
    if (!$ativo) continue;
    if ($simular && !$temSimulacao) { $resumo['migracoes'][$num] = 'não simulada (só estrutura; grava direto)'; if ($num === $ate) break; continue; }
    $cmd = array_merge([PHP_BINARY, 'scripts' . DIRECTORY_SEPARATOR . $script], $repassar, $simular ? ['--simular'] : []);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $raiz);
    if (!is_resource($proc)) { $resumo['migracoes'][$num] = 'ERRO: não foi possível executar'; break; }
    $saida = (string)stream_get_contents($pipes[1]); $erro = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $codigo = proc_close($proc);
    @file_put_contents($logs . DIRECTORY_SEPARATOR . $num . ($simular ? '_simulacao' : '') . '.json', $saida . ($erro !== '' ? PHP_EOL . '--- stderr ---' . PHP_EOL . $erro : ''));
    // Resultado por banco: erro/aviso de cada banco pedido (procura no JSON da migração)
    // Algumas migrações imprimem mais de um JSON (ex.: 260 chama a 247 no fim): vale o primeiro (do início até a primeira linha "}")
    $json = preg_match('/^\{.*?^\}\s*$/ms', $saida, $mj) ? json_decode($mj[0], true) : null;
    $porBanco = [];
    if (is_array($json)) {
        $walk = static function ($no, string $chave = '') use (&$walk, &$porBanco, $bancos): void {
            if (!is_array($no)) return;
            foreach ($no as $k => $v) {
                $b = is_string($k) && in_array($k, $bancos, true) ? $k : $chave;
                if ($b !== '' && in_array($k, ['erro', 'aviso'], true) && is_scalar($v)) $porBanco[$b][] = "$k: $v";
                $walk($v, $b);
            }
        };
        $walk($json);
    }
    $falhou = $codigo !== 0 || !is_array($json) || (($json['ok'] ?? true) === false);
    $resumo['migracoes'][$num] = ($falhou ? 'ERRO' : 'ok') . ($porBanco ? ' — ' . json_encode($porBanco, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '')
        . ($falhou && !$porBanco ? ' — ' . mb_substr(trim($erro !== '' ? $erro : $saida), 0, 400) : '');
    if ($falhou && !$simular) { $resumo['parou_em'] = $num; $resumo['continuar'] = "corrija e rode de novo com --de=$num"; break; }
    if ($num === $ate) break;
}
$resumo['fim'] = date('c');
$resumo['logs'] = $logs;
echo json_encode($resumo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
exit(isset($resumo['parou_em']) ? 1 : 0);
