<?php
declare(strict_types=1);

/*
 * Serviço LEGISLACAO_IMPORTACAO (migração 397) — trilha 1 do orquestrador, a cada 1 minuto.
 * Aplica os pedidos de importação da planilha de Legislação x Empresas que o ADMINISTRADOR AUTORIZOU
 * (Manutenção Legal › Legislação x Empresas › Importar planilha), um pedido por vez, e manda o alerta com o resultado
 * para quem enviou e quem autorizou. Sem pedido autorizado, não faz nada.
 * Uso: php scripts\legislacao-importacao-worker.php
 */

require_once dirname(__DIR__) . '/classes/LegislacaoImportacao.php';

$runtime = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'runtime';
if (!is_dir($runtime)) @mkdir($runtime, 0770, true);
$trava = fopen($runtime . DIRECTORY_SEPARATOR . 'green-legislacao-importacao.lock', 'c');
if ($trava === false || !flock($trava, LOCK_EX | LOCK_NB)) exit(0);   // execução anterior ainda em andamento

$saida = ['servico' => 'legislacao-importacao', 'inicio' => date('c'), 'pedidos' => []];
$fim = time() + 50 * 60;
try {
    while (time() < $fim && ($pedido = LegislacaoImportacao::reservar()) !== null) {
        $id = (int)$pedido['codimportacao'];
        try {
            $r = LegislacaoImportacao::aplicar($pedido);
            $saida['pedidos'][$id] = $r;
            $titulo = $r['ERRO'] > 0 ? 'Importação Legislação x Empresas com erros' : 'Importação Legislação x Empresas concluída';
            $msg = sprintf('Planilha %s (pedido %d): %d alteração(ões) aplicada(s), %d ignorada(s), %d erro(s)%s. Veja o resultado e o log no link.',
                (string)$pedido['arquivo_nome'], $id, $r['APLICADO'], $r['IGNORADO'], $r['ERRO'], $r['padroes'] > 0 ? '; ' . $r['padroes'] . ' padrão(ões) criado(s)' : '');
            LegislacaoImportacao::alertar($pedido, $titulo, $msg);
        } catch (Throwable $e) {
            LegislacaoImportacao::falhar($id, $e->getMessage());
            LegislacaoImportacao::alertar($pedido, 'Erro na importação Legislação x Empresas', 'Planilha ' . (string)$pedido['arquivo_nome'] . ' (pedido ' . $id . '): a aplicação foi interrompida. Veja o log no link.');
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
