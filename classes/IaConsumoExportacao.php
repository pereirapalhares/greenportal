<?php
declare(strict_types=1);

require_once __DIR__ . '/IaTaximetro.php';

/**
 * Planilha do Consumo de IA (transação administracao_consumo_ia, migração 340): as chamadas do mês pedido, da mais recente.
 * Executada SOMENTE pelo GreenPortal-ExportWorker (Agendador do Windows) via classes/ExportacaoGridWorker.php; a tela só grava o
 * pedido (ExportacaoSolicitacao, filtros = {"mes": "AAAA-MM", "empresa": "..."}) e o alerta de início. Leitura pela view vw_ia_consumo.
 */
final class IaConsumoExportacao
{
    public const TRANSACAO = 'administracao_consumo_ia';

    /** Título da planilha: "Consumo de IA — <empresa> — mm/aaaa". */
    public static function titulo(array $job): string
    {
        $f = self::filtros($job);
        return 'Consumo de IA' . ($f['empresa'] !== '' ? ' — ' . $f['empresa'] : '') . ' — ' . substr($f['mes'], 5, 2) . '/' . substr($f['mes'], 0, 4);
    }

    /** @return list<array<string,string>> linhas já rotuladas para createProfessionalXlsx */
    public static function linhas(PDO $pdo, array $job): array
    {
        $f = self::filtros($job);
        $ini = $f['mes'] . '-01';
        $fim = (new DateTimeImmutable($ini))->modify('+1 month')->format('Y-m-d');
        $s = $pdo->prepare('SELECT * FROM vw_ia_consumo WHERE CRIADO_EM >= ? AND CRIADO_EM < ? ORDER BY CRIADO_EM DESC, CODCONSUMO DESC LIMIT 200000');
        $s->execute([$ini, $fim]);
        $origem = ['FILA' => 'Fila de IA (Agendador)', 'TELA' => 'Na tela', 'HISTORICO' => 'Anterior ao taxímetro'];
        $dec = static fn($v, int $c): string => number_format((float)$v, $c, ',', '.');
        $out = [];
        while ($c = $s->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'Data' => ($t = strtotime((string)$c['CRIADO_EM'])) ? date('d/m/Y H:i', $t) : (string)$c['CRIADO_EM'],
                'Usuário' => (string)$c['NOME_USUARIO'], 'Módulo' => (string)$c['MODULO'], 'Pedido' => IaTaximetro::descricaoTipo((string)$c['TIPO']),
                'Registro' => (string)$c['REGISTRO'], 'Origem' => $origem[(string)$c['ORIGEM']] ?? (string)$c['ORIGEM'], 'Modelo' => (string)$c['MODELO'],
                'Tokens de entrada' => (string)(int)$c['TOKENS_ENTRADA'], 'Tokens em cache' => (string)(int)$c['TOKENS_CACHE'], 'Tokens de saída' => (string)(int)$c['TOKENS_SAIDA'],
                'Custo US$' => $dec($c['CUSTO_USD'], 6), 'Cotação (R$/US$)' => $dec($c['COTACAO'], 4), 'Acréscimo %' => $dec($c['ACRESCIMO_PCT'], 2), 'Custo R$' => $dec($c['CUSTO_BRL'], 4),
                'Estimado' => (int)$c['ESTIMADO'] ? 'Sim' : 'Não', 'Resultado' => (int)$c['SUCESSO'] ? 'OK' : 'Erro: ' . (string)$c['ERRO'],
            ];
        }
        $s->closeCursor();
        return $out ?: [['Informação' => 'Nenhuma chamada de IA em ' . substr($f['mes'], 5, 2) . '/' . substr($f['mes'], 0, 4) . '.']];
    }

    private static function filtros(array $job): array
    {
        $d = json_decode((string)($job['filtros'] ?? '{}'), true);
        $d = is_array($d) ? $d : [];
        $mes = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($d['mes'] ?? '')) ? (string)$d['mes'] : date('Y-m');
        return ['mes' => $mes, 'empresa' => mb_substr(trim((string)($d['empresa'] ?? '')), 0, 120)];
    }
}
