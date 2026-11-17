<?php
declare(strict_types=1);

/**
 * Planilha dos registros órfãos de uma migração de empresa (Administração › Migração › Log).
 * Executada SOMENTE pelo GreenPortal-ExportWorker (Agendador do Windows) via classes/ExportacaoGridWorker.php.
 * O pedido (exportacoes_grid.filtros) traz o banco de destino e os mesmos filtros da tela
 * (principal/administracao_migracao_log.php); a consulta é a mesma da tela.
 */
final class MigracaoOrfaosExportacao
{
    /** @return list<array<string,mixed>> */
    public static function linhas(array $env, array $job): array
    {
        $p = json_decode((string)($job['filtros'] ?? '{}'), true);
        $p = is_array($p) ? $p : [];
        $banco = (string)($p['banco'] ?? '');
        if (!preg_match('/^g[a-z0-9_]+_dbp$/i', $banco)) throw new RuntimeException('Banco de destino inválido.');
        $target = new PDO('mysql:host=' . ($env['GPT_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['GPT_DB_PORT'] ?? '3306') . ';dbname=' . $banco . ';charset=utf8mb4',
            $env['GPT_DB_USERNAME'] ?? '', $env['GPT_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $q = trim((string)($p['q'] ?? '')); $table = trim((string)($p['tabela'] ?? '')); $reason = trim((string)($p['motivo'] ?? ''));
        $from = trim((string)($p['de'] ?? '')); $to = trim((string)($p['ate'] ?? ''));
        $where = []; $args = [];
        if ($q !== '') { $where[] = 'dados LIKE ?'; $args[] = '%' . $q . '%'; }
        if ($table !== '') { $where[] = 'dados LIKE ?'; $args[] = '%"tabela":"' . $table . '"%'; }
        if ($reason !== '') { $where[] = 'dados LIKE ?'; $args[] = '%"motivo":"' . $reason . '%'; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'encontrado_em >= ?'; $args[] = $from . ' 00:00:00'; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'encontrado_em <= ?'; $args[] = $to . ' 23:59:59'; }
        $s = $target->prepare('SELECT codorfao,dados,encontrado_em FROM migracao_registros_orfaos' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY codorfao DESC');
        $s->execute($args);
        $linhas = [];
        while ($i = $s->fetch(PDO::FETCH_ASSOC)) {
            $d = json_decode((string)$i['dados'], true);
            $linhas[] = ['Código' => (int)$i['codorfao'], 'Encontrado em' => (string)$i['encontrado_em'],
                'Tabela' => is_array($d) ? (string)($d['tabela'] ?? '') : '', 'Motivo' => is_array($d) ? (string)($d['motivo'] ?? '') : '', 'Dados' => (string)$i['dados']];
        }
        return $linhas ?: [['Informação' => 'Nenhum registro órfão corresponde aos filtros.']];
    }
}
