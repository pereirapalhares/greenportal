<?php
declare(strict_types=1);

/**
 * Planilha do Painel de Homologação (transação homologacaofornecedores_painel).
 * Executada SOMENTE pelo GreenPortal-ExportWorker (Agendador do Windows) via classes/ExportacaoGridWorker.php.
 * O pedido (exportacoes_grid.filtros) traz os filtros da tela, a consulta escolhida, o idioma e se o solicitante é
 * usuário do fornecedor; os dados vêm de sp_fo_painel_itens com o código do solicitante (mesmo escopo da tela) e
 * as regras de consulta/cores são as de classes/HomologacaoPainelConsultas.php (as mesmas da tela).
 */
final class HomologacaoPainelExportacao
{
    /** @return list<array<string,mixed>> linhas já rotuladas para createProfessionalXlsx */
    public static function linhas(PDO $pdo, array $job): array
    {
        $pedido = json_decode((string)($job['filtros'] ?? '{}'), true);
        $pedido = is_array($pedido) ? $pedido : [];
        $f = is_array($pedido['filtros'] ?? null) ? $pedido['filtros'] : [];
        $inteiro = static fn($v): ?int => ($n = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) === false ? null : (int)$n;
        $externo = (int)($pedido['externo'] ?? 0) === 1;
        // Migração 316: modo de acesso do solicitante (0 tudo, 1 externo, 2 interno restrito por unidade); pedidos antigos só têm "externo".
        $modo = isset($pedido['modo']) && in_array((int)$pedido['modo'], [0, 1, 2], true) ? (int)$pedido['modo'] : ($externo ? 1 : 0);
        $filtros = [
            'unidade' => $inteiro($f['unidade'] ?? null),
            'tipo' => $externo ? null : $inteiro($f['tipo'] ?? null),
            'busca' => mb_substr(trim((string)($f['busca'] ?? '')), 0, 100),
            'responsavel' => in_array((string)($f['responsavel'] ?? ''), ['EU', 'SEM'], true) ? (string)$f['responsavel'] : '',
            'vencimento' => in_array((string)($f['vencimento'] ?? ''), ['VENCIDOS', '30', '90'], true) ? (string)$f['vencimento'] : '',
        ];
        $consulta = preg_match('/^[a-z0-9:]{1,40}$/', (string)($pedido['consulta'] ?? '')) ? (string)$pedido['consulta'] : 'todos';
        $idioma = in_array((string)($pedido['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$pedido['idioma'] : 'pt';
        $t = static fn(string $chave, array $padrao): string => (string)($padrao[$idioma] ?? $padrao['pt'] ?? '');
        $usuario = (int)($job['codusuario'] ?? 0);
        if ($usuario <= 0) throw new RuntimeException('Pedido sem usuário.');

        require __DIR__ . '/HomologacaoPainelConsultas.php';

        $s = $pdo->prepare('CALL sp_fo_painel_itens(?, ?, ?, ?, ?, ?, ?, ?)');
        $s->execute([$usuario, $modo, $filtros['unidade'], $filtros['tipo'], $filtros['busca'], $filtros['responsavel'], $filtros['vencimento'], null]);
        $conjuntos = [];
        do { try { $conjuntos[] = $s->fetchAll(PDO::FETCH_ASSOC); } catch (PDOException) { } } while ($s->nextRowset());
        $s->closeCursor();
        $lista = $conjuntos[0] ?? [];
        $forn = $conjuntos[1][0] ?? ['ATIVOS' => 0, 'DESATIVADOS' => 0];

        $res = $resumir($lista, $forn);
        [$c, $sel] = $aplicarConsulta($marcarOutras($lista, $res['unidadesPrincipais']), $consulta);
        if (!$c['fn']) [$c, $sel] = $aplicarConsulta($lista, 'todos');
        $ordenar($sel);

        $linhas = [];
        foreach ($sel as $i) {
            $linhas[] = [
                'Categoria' => $categoriaNome[$i['CATEGORIA']] ?? $i['CATEGORIA'],
                'Cor' => $corNome[$i['COR']] ?? $i['COR'],
                'Fornecedor' => (string)$i['RAZAOSOCIAL'],
                'CNPJ/CPF' => (string)($i['CNPJ_CPF'] ?? ''),
                'Unidade' => (string)$i['DSCUNIDADE'],
                'Item' => (string)$i['ITEM'],
                'Situação' => (string)$i['SITUACAO'] !== '' ? (string)$i['SITUACAO'] : 'Sem situação',
                'Validade' => $data($i['VALIDADE']),
                'Dias' => $i['QTDDIAS'] === null ? '' : (string)(int)$i['QTDDIAS'],
                'Responsável (empresa)' => (string)($i['RESPONSAVEL_EMPRESA'] ?? ''),
                'Responsável (fornecedor)' => (string)($i['RESPONSAVEL_FORNECEDOR'] ?? ''),
            ];
        }
        return $linhas ?: [['Informação' => 'Nenhum item encontrado para os filtros e a consulta escolhidos.']];
    }
}
