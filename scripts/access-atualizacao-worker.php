<?php
declare(strict_types=1);

/*
 * Serviço ACCESS_ATUALIZACAO (migração 404) — trilha 1 do orquestrador, a cada 1 minuto.
 * Executa os pedidos da tela Manutenção Legal › Atualização do Access (SIMULAR ou APLICAR), um por vez, e manda o alerta
 * para quem pediu. Sem pedido, não faz nada. Substitui o clsLegislacao.exe (.NET) do legado.
 * Uso: php scripts\access-atualizacao-worker.php
 */

require_once dirname(__DIR__) . '/classes/AccessAtualizacao.php';

$runtime = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'runtime';
if (!is_dir($runtime)) @mkdir($runtime, 0770, true);
$trava = fopen($runtime . DIRECTORY_SEPARATOR . 'green-access-atualizacao.lock', 'c');
if ($trava === false || !flock($trava, LOCK_EX | LOCK_NB)) exit(0);

$saida = ['servico' => 'access-atualizacao', 'inicio' => date('c'), 'pedidos' => []];
try {
    while (($p = AccessAtualizacao::reservar()) !== null) {
        $id = (int)$p['codprocessamento'];
        $acao = $p['modo'] === 'APLICAR' ? 'Atualização do Access' : 'Simulação da atualização do Access';
        try {
            $r = AccessAtualizacao::executar($p);
            $t = $r['totais'];
            $saida['pedidos'][$id] = ['status' => $r['status'], 'totais' => $t];
            AccessAtualizacao::alertar($p, $acao . ($t['erros'] > 0 ? ' com erros' : ' concluída'), sprintf(
                '%s nº %d: %d arquivo(s); %d lei(s) incluída(s), %d controle(s), %d revogada(s), %d alterada(s)%s%s. Veja o resultado no link.',
                $acao, $id, $t['arquivos'], $t['incluidas'], $t['controles'], $t['revogadas'], $t['alteradas'],
                $t['sem_fonte'] ? ', ' . $t['sem_fonte'] . ' sem dados na fonte' : '', $t['erros'] ? ', ' . $t['erros'] . ' erro(s)' : ''));
        } catch (Throwable $e) {
            AccessAtualizacao::falhar($id, $e->getMessage());
            AccessAtualizacao::alertar($p, 'Erro na ' . mb_strtolower($acao), $acao . ' nº ' . $id . ' interrompida: ' . mb_substr($e->getMessage(), 0, 300));
            $saida['pedidos'][$id] = ['status' => 'ERRO', 'erro' => $e->getMessage()];
        }
    }
} catch (Throwable $e) {
    $saida['erro'] = $e->getMessage();
    echo json_encode($saida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
echo json_encode($saida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);
