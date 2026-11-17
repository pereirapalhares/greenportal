<?php
declare(strict_types=1);

require_once __DIR__ . '/PermissaoTransacao.php';
require_once __DIR__ . '/PendenciasService.php';

/**
 * c578 (16/11/2026): cards da barra direita › Pendências (principal/painel_pendencias.php) e o número do ícone de Pendências
 * (PainelLateralService::contagens) — o ícone mostra a SOMA dos cards (decisão do usuário), calculados aqui num lugar só.
 * Cada card conta com sp_crud_count na MESMA view e com os MESMOS filtros da tela que abre (o número bate com o rodapé do
 * grid) e só entra se o usuário pode abrir a tela de destino. Aprovações não entra: tem ícone próprio na barra.
 */
final class PendenciasPainel
{
    /** Total pelo sp_crud_count (o mesmo do rodapé "N registros" do grid padrão); null = view indisponível. */
    private static function total(PDO $pdo, string $view, array $filtros): ?int
    {
        try {
            $st = $pdo->prepare('CALL sp_crud_count(:t, :f, :b)');
            $st->execute(['t' => $view, 'f' => json_encode((object)$filtros, JSON_UNESCAPED_UNICODE), 'b' => '']);
            $r = $st->fetchAll(PDO::FETCH_ASSOC); $st->closeCursor();
            return isset($r[0]['total_registros']) ? (int)$r[0]['total_registros'] : null;
        } catch (Throwable) { return null; }
    }

    private static function pode(string $menu): bool { return !empty(PermissaoTransacao::obter($menu)['pode_visualizar']); }

    private static function url(string $pagina, array $filtros): string
    {
        return $pagina . '?filtros=' . rawurlencode((string)json_encode($filtros, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Cards com itens (count > 0): title, description, count, icon, class, url (relativa a principal/), transaction. */
    public static function cards(PDO $pdo, int $uid): array
    {
        $cards = [];
        $em30 = date('Y-m-d', strtotime('+30 days'));
        $eu = ['value' => (string)$uid, 'exact' => true];

        // Requisitos legais pelos quais o usuário responde e que não estão atendidos: um card por situação consolidada,
        // abrindo Minhas atividades › Meus requisitos legais com o filtro de igualdade da coluna
        if (self::pode('REQUISITOS_LEGAIS')) {
            $fonte = null;
            foreach (['vw_modulolegal_requisitos_situacao_grid', 'vw_modulolegal_requisitos_filtro_grid'] as $v) if (self::total($pdo, $v, ['RESPONSAVEL_IDS' => ',' . $uid . ',']) !== null) { $fonte = $v; break; }
            foreach ([
                'Não atendido' => ['Requisitos legais não atendidos', 'Requisitos pelos quais você responde com situação "Não atendido".', 'bi-shield-exclamation', 'orange'],
                'Parcialmente atendido' => ['Requisitos parcialmente atendidos', 'Requisitos pelos quais você responde com situação "Parcialmente atendido".', 'bi-shield-minus', 'orange'],
                'Indefinido' => ['Requisitos com situação indefinida', 'Requisitos pelos quais você responde ainda sem avaliação ou sem evidência.', 'bi-shield-check', 'green'],
            ] as $sit => [$tit, $desc, $ico, $cor]) {
                if ($fonte === null) break;
                $f = ['LEI_REVOGADA' => 'NAO', 'SITUACAO_CONSOLIDADA' => ['value' => $sit, 'exact' => true]];
                $n = (int)self::total($pdo, $fonte, $f + ['RESPONSAVEL_IDS' => ',' . $uid . ',']);
                if ($n > 0) $cards[] = ['title' => $tit, 'description' => $desc, 'count' => $n, 'icon' => $ico, 'class' => $cor,
                    'url' => self::url('meus_requisitos.php', $f), 'transaction' => 'pendencias-requisitos-' . substr(md5($sit), 0, 6)];
            }
        }
        // Não conformidades em andamento em que o usuário está envolvido (Minhas ocorrências, ?abertas=1 tira as finalizadas)
        if (self::pode('NAO_CONFORMIDADES_MINHAS_OCORRENCIAS')) {
            $n = (int)self::total($pdo, 'vminhasocorrenciasnc', ['ETAPA' => ['value' => 'Finalizado', 'operator' => 'not', 'exact' => true], 'CODUSUARIO_ENVOLVIDO' => $eu]);
            if ($n > 0) $cards[] = ['title' => 'Não conformidades', 'description' => 'Ocorrências em andamento que envolvem você.', 'count' => $n,
                'icon' => 'bi-clipboard2-pulse', 'class' => 'orange', 'url' => 'naoconformidades_minhasocorrencias.php?abertas=1', 'transaction' => 'pendencias-nc'];
        }
        // Licenças do usuário vencidas ou vencendo em 30 dias
        if (self::pode('LICENCAS')) {
            $ate = ['value' => $em30, 'operator' => 'lte'];
            $n = (int)self::total($pdo, 'vw_licencas_grid', ['CODUSUARIO' => $eu, 'DATA_VALIDADE' => $ate]);
            if ($n > 0) $cards[] = ['title' => 'Licenças', 'description' => 'Licenças sob sua responsabilidade vencidas ou vencendo em 30 dias.', 'count' => $n,
                'icon' => 'bi-file-earmark-text', 'class' => 'blue', 'url' => self::url('manutencaolegal_licencas.php', ['CODUSUARIO' => $uid, 'DATA_VALIDADE' => $ate]), 'transaction' => 'pendencias-licencas'];
        }
        // Fornecedores pelos quais o usuário responde com avaliação vencida ou vencendo em 30 dias (Meus fornecedores)
        if (self::pode('HOMOLOGACAO_FORNECEDOR_FORNECEDOR')) {
            $f = ['DATA_DA_PROXIMA_AVALIACAO' => ['value' => $em30, 'operator' => 'lte']];
            $n = (int)self::total($pdo, 'vw_meus_fornecedores_grid', $f + ['CODUSUARIO_ACESSO' => $eu]);
            if ($n > 0) $cards[] = ['title' => 'Homologação de Fornecedores', 'description' => 'Seus fornecedores com avaliação vencida ou vencendo em 30 dias.', 'count' => $n,
                'icon' => 'bi-building-check', 'class' => 'green', 'url' => self::url('meus_fornecedores.php', $f), 'transaction' => 'pendencias-fornecedores'];
        }
        // Pendências universais (tarefas, agenda, responsabilidades, alertas não lidos) do próprio usuário
        try { $n = (int)((new PendenciasService($pdo))->contar($uid)['TOTAL'] ?? 0); } catch (Throwable) { $n = 0; }
        if ($n > 0) $cards[] = ['title' => 'Pendências universais', 'description' => 'Tarefas, agenda, responsabilidades e alertas não lidos.', 'count' => $n,
            'icon' => 'bi-inboxes', 'class' => 'green', 'url' => 'painel_pendencias_universal.php', 'transaction' => 'pendencias-universais'];
        return $cards;
    }

    /** Número do ícone de Pendências da barra direita: a soma dos cards. */
    public static function soma(PDO $pdo, int $uid): int
    {
        return array_sum(array_column(self::cards($pdo, $uid), 'count'));
    }
}
