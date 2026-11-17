<?php
declare(strict_types=1);

require_once __DIR__ . '/MigracaoCrud.php';

/**
 * Planilha da tela Documentos do fornecedor (transação homologacaofornecedor_fornecedorxcriterios).
 * Executada SOMENTE pelo GreenPortal-ExportWorker (Agendador do Windows) via classes/ExportacaoGridWorker.php.
 * O pedido traz o fornecedor (validado na tela por HomologacaoFornecedorAcesso), os documentos visíveis na ordem da
 * tela (filtros e ordenação aplicados) e as colunas/rótulos; os dados são relidos de vw_homologacao_fornecedor_documentos
 * e formatados como na tela (assets/js/homologacao-documentos.js, função valor).
 */
final class HomologacaoDocumentosExportacao
{
    /** @return list<array<string,mixed>> */
    public static function linhas(PDO $pdo, array $job): array
    {
        $pedido = json_decode((string)($job['filtros'] ?? '{}'), true);
        $pedido = is_array($pedido) ? $pedido : [];
        $layout = json_decode((string)($job['colunas'] ?? '{}'), true);
        $layout = is_array($layout) ? $layout : [];
        $fornecedor = (int)($pedido['CODFORNECEDOR'] ?? 0);
        if ($fornecedor <= 0) throw new RuntimeException('Pedido sem fornecedor.');
        $idioma = in_array((string)($pedido['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$pedido['idioma'] : 'pt';
        $mapas = is_array($layout['mapas'] ?? null) ? $layout['mapas'] : [];
        $mapa = static fn(string $n): array => is_array($mapas[$n] ?? null) ? $mapas[$n] : [];
        $rotulos = $mapa('colunas'); $situacoes = $mapa('situacoes'); $simNao = $mapa('simNao'); $cores = $mapa('cores'); $textos = $mapa('textos');
        $colunas = array_values(array_filter((array)($layout['colunas'] ?? []), static fn($c) => is_string($c) && preg_match('/^[A-Z_]{1,40}$/', $c) && $c !== 'ARQUIVOS'));

        $porCodigo = [];
        foreach (MigracaoCrud::selecionar($pdo, 'vw_homologacao_fornecedor_documentos', ['CODFORNECEDOR' => $fornecedor]) as $r) $porCodigo[(int)$r['CODFORNECEDORXCRITERIOS']] = $r;
        $codigos = array_values(array_filter(array_map('intval', (array)($pedido['codigos'] ?? [])), static fn($n) => $n > 0));
        // Só documentos deste fornecedor, na ordem da tela.
        $docs = [];
        foreach ($codigos as $c) if (isset($porCodigo[$c])) $docs[] = $porCodigo[$c];

        $txt = static fn($v): string => $v === null ? '' : (string)$v;
        $rotulo = static fn(array $m, $v): string => $v !== null && $v !== '' && array_key_exists((string)$v, $m) ? (string)$m[(string)$v] : $txt($v);
        $dataBR = static function ($v) use ($idioma): string {
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string)$v, $m)) return '';
            return $idioma === 'en' ? "$m[2]/$m[3]/$m[1]" : "$m[3]/$m[2]/$m[1]";
        };
        $valor = static function (array $r, string $col) use ($txt, $rotulo, $dataBR, $situacoes, $simNao, $textos): string {
            switch ($col) {
                case 'DOCUMENTO': return $txt($r['DSCTIPO'] ?? '') . ' / ' . $txt($r['DSCRITERIO'] ?? '');
                case 'SITUACAO': return $rotulo($situacoes, $r['SITUACAO'] ?? '');
                case 'DATADEVALIDADE': return $dataBR($r['DATADEVALIDADE'] ?? '');
                case 'QTDDIAS':
                    if (($r['QTDDIAS'] ?? null) === null || $r['QTDDIAS'] === '') return '';
                    $n = (int)$r['QTDDIAS'];
                    return $n < 0 ? str_replace('{n}', (string)-$n, (string)($textos['vencido'] ?? 'vencido há {n} dias')) : str_replace('{n}', (string)$n, (string)($textos['dias'] ?? '{n} dias'));
                case 'EMPROTOCOLO':
                    $v = (string)($r['EMPROTOCOLO'] ?? '');
                    return $v === 'S' ? (string)($simNao['Sim'] ?? 'Sim') : ($v === 'N' ? (string)($simNao['Não'] ?? 'Não') : $v);
                case 'ENVIADOODOCUMENTO': case 'INCLUIDOCONTRATO': return $rotulo($simNao, $r[$col] ?? '');
                case 'RESPONSAVEL_FORNECEDOR': return (int)($r['CODUSUARIO_FORNECEDOR'] ?? 0) === 99999 ? (string)($textos['sem_resp_forn'] ?? '') : $txt($r['RESPONSAVEL_FORNECEDOR'] ?? '');
                default: return $txt($r[$col] ?? '');
            }
        };

        $linhas = [];
        foreach ($docs as $r) {
            $l = [];
            foreach ($colunas as $c) $l[(string)($rotulos[$c] ?? $c)] = $valor($r, $c);
            $l['Cor'] = (string)($cores[(string)($r['COR'] ?? '')] ?? ($r['COR'] ?? ''));
            $linhas[] = $l;
        }
        return $linhas ?: [['Informação' => 'Nenhum documento encontrado para os filtros escolhidos.']];
    }
}
