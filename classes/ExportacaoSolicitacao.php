<?php
declare(strict_types=1);

/**
 * Pedido de exportação para o Excel (padrão único da aplicação).
 *
 * A tela NUNCA gera a planilha: grava o pedido na fila exportacoes_grid (status SOLICITADA) e cria o alerta
 * "Exportação iniciada" com o link de acompanhamento. A planilha é gerada por scripts/export-worker.php, executado
 * pela tarefa GreenPortal-ExportWorker no Agendador do Windows (classes/ExportacaoGridWorker.php), que envia o alerta
 * "Exportação concluída" com o link do arquivo (ou "Erro na exportação").
 */
final class ExportacaoSolicitacao
{
    /**
     * @param array{filtros?:mixed,busca?:string,colunas?:mixed} $pedido filtros/colunas podem ser array ou JSON.
     * @return int código do pedido (codexportacaogrid) ou 0 se não foi possível gravar.
     */
    public static function solicitar(PDO $pdo, int $usuario, string $transacao, string $tipo, array $pedido = []): int
    {
        if ($usuario <= 0 || !preg_match('/^[A-Za-z0-9_]+$/', $transacao)) return 0;
        $json = static fn(mixed $v): string => is_string($v) ? $v : (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $dados = ['codusuario' => $usuario, 'transacao' => $transacao, 'status' => 'SOLICITADA'];
        if (array_key_exists('filtros', $pedido)) $dados['filtros'] = $json($pedido['filtros'] ?? []);
        if (array_key_exists('busca', $pedido)) $dados['busca'] = trim((string)$pedido['busca']);
        if (array_key_exists('colunas', $pedido)) $dados['colunas'] = $json($pedido['colunas'] ?? []);
        $r = self::call($pdo, 'CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'exportacoes_grid', 'dados' => $json($dados)]);
        $id = (int)($r[0]['chave_gerada'] ?? 0);
        if ($id <= 0) {
            // Procedimento sem chave_gerada: o pedido mais recente do usuário nesta transação.
            $jobs = self::call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', ['tabela' => 'exportacoes_grid', 'filtros' => $json(['codusuario' => $usuario, 'transacao' => $transacao, 'status' => 'SOLICITADA'])]);
            foreach ($jobs as $job) $id = max($id, (int)($job['codexportacaogrid'] ?? 0));
        }
        if ($id <= 0) return 0;
        // Horário do pedido gravado pelo banco (o PHP pode estar em outro fuso).
        $quando = '';
        try { $quando = (string)(self::call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'exportacoes_grid', 'filtros' => $json(['codexportacaogrid' => $id])])[0]['solicitado_em'] ?? ''); } catch (Throwable) { }
        $quando = ($ts = strtotime($quando)) ? date('d/m/Y H:i', $ts) : date('d/m/Y H:i');
        $link = '/principal/export_status.php?codexportacaogrid=' . $id . '&transacao=' . rawurlencode($transacao);
        try {
            self::call($pdo, 'CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'alertas', 'dados' => $json([
                'codusuario' => $usuario, 'titulo' => 'Exportação iniciada',
                'mensagem' => 'A exportação de ' . ($tipo !== '' ? $tipo : $transacao) . ' foi iniciada em ' . $quando . '. A planilha chegará nos alertas assim que for gerada.',
                'link_status' => $link, 'lido' => 0])]);
        } catch (Throwable) {
            // Sem o alerta de início o pedido continua na fila; o alerta de conclusão é enviado pelo worker.
        }
        return $id;
    }

    /** Mensagem padrão devolvida à tela depois do pedido. */
    public static function mensagem(): string
    {
        $pt = 'A exportação foi solicitada e será disponibilizada nos alertas.';
        if (!class_exists('IdiomaService')) return $pt;
        return IdiomaService::t('comum.msg.exportacao_pedido', ['pt' => $pt, 'en' => 'The export was requested and will be available in the alerts.', 'es' => 'La exportación fue solicitada y estará disponible en las alertas.']);
    }

    private static function call(PDO $pdo, string $sql, array $params): array
    {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        while ($s->nextRowset()) { /* descarta result sets extras */ }
        $s->closeCursor();
        return $rows;
    }
}
