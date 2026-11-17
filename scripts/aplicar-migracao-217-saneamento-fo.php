<?php
declare(strict_types=1);

/*
 * Migração 217 — Saneamento das tabelas fo_* (Homologação de Fornecedores) migradas do legado.
 *
 *   Etapa 2 (Atlas):  cria cadastros provisórios para fornecedores e funções que são usados por
 *                     funcionários e relacionamentos, mas não existem (já não existiam no legado
 *                     green_atlas). Nenhum registro existente é alterado: o provisório recebe o
 *                     mesmo código que já está gravado nos funcionários.
 *   Etapa 3 (Modelo): recupera de green_modelo os registros que existem na origem e faltam em
 *                     gmodelo_dbp (fornecedores, funcionários, certificados, equipamentos,
 *                     produtos químicos, critérios e tipos do fornecedor). Só insere; nunca sobrescreve.
 *   Etapa 4 (Atlas):  separa o histórico fo_fornecedorxcriterios_historico. A tabela original é
 *                     preservada inteira como fo_fornecedorxcriterios_historico_bkp217 e a tabela
 *                     ativa passa a conter só as linhas de fornecedores existentes.
 *
 * Por padrão APENAS SIMULA (as etapas 2 e 3 são executadas dentro de uma transação desfeita no fim)
 * e mostra o que mudaria. Para gravar, use --aplicar. Pode ser executada mais de uma vez.
 *
 * Uso (em E:\wamp\apps\greenportal):
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-217-saneamento-fo.php
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-217-saneamento-fo.php --aplicar
 *   Opcional: --etapas=2,3,4   (padrão: todas)
 *             --provisorios=funcionarios | todos | nenhum   (etapa 2; padrão: funcionarios =
 *               cria provisórios só para os códigos usados em fo_funcionarios)
 *
 * Recomendado: fazer backup de gatlas_dbp e gmodelo_dbp antes do --aplicar e rodar a etapa 4
 * fora do horário de uso (ela copia o histórico do Atlas, com cerca de 4,5 milhões de linhas).
 */

require_once __DIR__ . '/../classes/GreenPortalDatabase.php';

$aplicar = in_array('--aplicar', $argv, true);
$etapas = [2, 3, 4];
$provisorios = 'funcionarios';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--provisorios=')) $provisorios = substr($arg, 14);
    if (str_starts_with($arg, '--etapas=')) {
        $etapas = array_values(array_intersect([2, 3, 4], array_map('intval', explode(',', substr($arg, 9)))));
    }
}

const MIGRACAO = '217';
const VERSAO = '4.0.1';
const ATLAS_DESTINO = 'gatlas_dbp';
const MODELO_ORIGEM = 'green_modelo';
const MODELO_DESTINO = 'gmodelo_dbp';
const SUFIXO_BACKUP = '_bkp217';

$env = GreenPortalDatabase::env();
$pdo = new PDO(
    'mysql:host=' . ($env['GPT_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['GPT_DB_PORT'] ?? '3306') . ';charset=utf8mb4',
    $env['GPT_DB_USERNAME'] ?? '',
    $env['GPT_DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$pdo->exec("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_TRANS_TABLES')");
@set_time_limit(0);

/* ---------- utilitários ---------- */

function q(string $nome): string
{
    return '`' . str_replace('`', '``', $nome) . '`';
}

function linhas(PDO $pdo, string $sql, array $p = []): array
{
    $s = $pdo->prepare($sql);
    $s->execute($p);
    $r = $s->fetchAll();
    $s->closeCursor();
    return $r;
}

function valor(PDO $pdo, string $sql, array $p = []): mixed
{
    $r = linhas($pdo, $sql, $p);
    return $r ? array_values($r[0])[0] : null;
}

function tabelaExiste(PDO $pdo, string $db, string $tabela): bool
{
    return (bool)valor($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'", [$db, $tabela]);
}

/** @return array<string,array> colunas indexadas pelo nome em minúsculas */
function colunas(PDO $pdo, string $db, string $tabela): array
{
    $out = [];
    foreach (linhas($pdo, 'SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$db, $tabela]) as $c) {
        $out[strtolower($c['COLUMN_NAME'])] = $c;
    }
    return $out;
}

function chavePrimaria(PDO $pdo, string $db, string $tabela): array
{
    return array_column(linhas($pdo, "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION", [$db, $tabela]), 'COLUMN_NAME');
}

/** Valor neutro para colunas obrigatórias sem padrão. */
function valorNeutro(array $c): mixed
{
    $t = strtolower($c['DATA_TYPE']);
    if (in_array($t, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'bit', 'year'], true)) return 0;
    if ($t === 'date') return date('Y-m-d');
    if (in_array($t, ['datetime', 'timestamp'], true)) return date('Y-m-d H:i:s');
    if ($t === 'time') return '00:00:00';
    if ($t === 'enum' && preg_match("/^enum\\('((?:[^']|'')*)'/i", $c['COLUMN_TYPE'], $m)) return str_replace("''", "'", $m[1]);
    if ($t === 'json') return '{}';
    return '';
}

/** Monta e executa um INSERT de cadastro provisório preenchendo as colunas obrigatórias. */
function inserirProvisorio(PDO $pdo, string $db, string $tabela, array $cols, string $colChave, int $codigo, array $textos): void
{
    $valores = [$cols[strtolower($colChave)]['COLUMN_NAME'] => $codigo];
    foreach ($textos as $colunaMinuscula => $texto) {
        if (!isset($cols[$colunaMinuscula])) continue;
        $max = (int)($cols[$colunaMinuscula]['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
        $valores[$cols[$colunaMinuscula]['COLUMN_NAME']] = $max > 0 ? mb_substr($texto, 0, $max) : $texto;
    }
    foreach ($cols as $c) {
        if (array_key_exists($c['COLUMN_NAME'], $valores)) continue;
        if ($c['IS_NULLABLE'] === 'YES' || $c['COLUMN_DEFAULT'] !== null) continue;
        if (stripos($c['EXTRA'], 'auto_increment') !== false || stripos($c['EXTRA'], 'GENERATED') !== false) continue;
        $valores[$c['COLUMN_NAME']] = valorNeutro($c);
    }
    $sql = 'INSERT INTO ' . q($db) . '.' . q($tabela) . ' (' . implode(', ', array_map('q', array_keys($valores))) . ') VALUES (' . implode(', ', array_fill(0, count($valores), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($valores));
}

function registrarMigracao(PDO $pdo, string $db, string $codigo, string $origem, string $destino, int $quantidade, array $detalhes, string $inicio): void
{
    if (!tabelaExiste($pdo, $db, 'registros_migracao')) return;
    $tipo = 'SANEAMENTO';
    $tipoColuna = (string)valor($pdo, "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'registros_migracao' AND COLUMN_NAME = 'tipo'", [$db]);
    if (str_starts_with(strtolower($tipoColuna), 'enum(')) {
        foreach (['SANEAMENTO', 'DADOS', 'ESTRUTURA'] as $opcao) {
            if (stripos($tipoColuna, "'" . $opcao . "'") !== false) { $tipo = $opcao; break; }
        }
    }
    $json = json_encode($detalhes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $existe = valor($pdo, 'SELECT codregistromigracao FROM ' . q($db) . '.registros_migracao WHERE codigo_migracao = ? ORDER BY codregistromigracao DESC LIMIT 1', [$codigo]);
    if ($existe && $quantidade === 0) return; // reexecução sem mudanças: mantém o registro da execução que alterou dados
    if ($existe) {
        $pdo->prepare('UPDATE ' . q($db) . ".registros_migracao SET status = 'CONCLUIDA', quantidade_registros = ?, iniciada_em = ?, concluida_em = CURRENT_TIMESTAMP, detalhes = ? WHERE codregistromigracao = ?")
            ->execute([$quantidade, $inicio, $json, $existe]);
    } else {
        $pdo->prepare('INSERT INTO ' . q($db) . ".registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes) VALUES (?, ?, ?, ?, ?, 'CONCLUIDA', ?, ?, CURRENT_TIMESTAMP, ?)")
            ->execute([$codigo, $origem, $destino, $tipo, VERSAO, $quantidade, $inicio, $json]);
    }
}

/** Tabelas fo_* do banco que têm a coluna informada (exceto históricos e cópias de backup). */
function tabelasComColuna(PDO $pdo, string $db, string $coluna, string $exceto): array
{
    return array_column(linhas($pdo, "SELECT c.TABLE_NAME, c.COLUMN_NAME FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
        WHERE c.TABLE_SCHEMA = ? AND LOWER(c.COLUMN_NAME) = ? AND c.TABLE_NAME LIKE 'fo\\_%' AND c.TABLE_NAME <> ? AND c.TABLE_NAME NOT LIKE '%historico%' AND c.TABLE_NAME NOT LIKE '%\\_bkp%' ORDER BY c.TABLE_NAME", [$db, strtolower($coluna), $exceto]), 'COLUMN_NAME', 'TABLE_NAME');
}

/* ---------- etapa 2: cadastros provisórios no Atlas ---------- */

function etapa2(PDO $pdo, bool $aplicar, string $escopo): array
{
    $db = ATLAS_DESTINO;
    $config = [
        'fornecedores' => ['tabela' => 'fo_fornecedores', 'chave' => 'codfornecedor', 'textos' => static fn(int $c) => [
            'razaosocial' => "Fornecedor não identificado (código $c) - migração " . MIGRACAO,
            'nomefantasia' => "Fornecedor não identificado (código $c)",
        ]],
        'funcoes' => ['tabela' => 'fo_tipofuncionarios', 'chave' => 'codtipofuncionario', 'textos' => static fn(int $c) => [
            'dsctipo' => "Função não identificada (código $c) - migração " . MIGRACAO,
        ]],
    ];
    $resultado = [];
    $pdo->beginTransaction();
    try {
        foreach ($config as $nome => $cfg) {
            $tabela = $cfg['tabela'];
            if (!tabelaExiste($pdo, $db, $tabela)) { $resultado[$nome] = ['erro' => "$db.$tabela não existe"]; continue; }
            $cols = colunas($pdo, $db, $tabela);
            $chave = $cols[$cfg['chave']]['COLUMN_NAME'];
            $maximo = (int)valor($pdo, 'SELECT COALESCE(MAX(' . q($chave) . '), 0) FROM ' . q($db) . '.' . q($tabela));
            $referencias = [];
            $codigos = [];
            $codigosFuncionarios = [];
            foreach (tabelasComColuna($pdo, $db, $cfg['chave'], $tabela) as $tab => $col) {
                $rows = linhas($pdo, 'SELECT r.' . q($col) . ' codigo, COUNT(*) n FROM ' . q($db) . '.' . q($tab) . ' r WHERE r.' . q($col) . ' > 0 AND NOT EXISTS (SELECT 1 FROM ' . q($db) . '.' . q($tabela) . ' p WHERE p.' . q($chave) . ' = r.' . q($col) . ') GROUP BY r.' . q($col));
                if (!$rows) continue;
                $referencias[$tab] = ['linhas' => array_sum(array_column($rows, 'n')), 'codigos' => count($rows)];
                foreach ($rows as $r) {
                    $codigos[(int)$r['codigo']] = true;
                    if ($tab === 'fo_funcionarios') $codigosFuncionarios[(int)$r['codigo']] = true;
                }
            }
            $alvo = match ($escopo) { 'todos' => $codigos, 'nenhum' => [], default => $codigosFuncionarios };
            ksort($alvo);
            $criar = array_values(array_filter(array_keys($alvo), static fn(int $c) => $c <= $maximo));
            $ignorados = array_values(array_filter(array_keys($alvo), static fn(int $c) => $c > $maximo));
            foreach ($criar as $c) inserirProvisorio($pdo, $db, $tabela, $cols, $chave, $c, ($cfg['textos'])($c));
            $resultado[$nome] = [
                'tabela' => "$db.$tabela",
                'referencias_sem_cadastro' => $referencias,
                'provisorios_criados' => $criar,
                'nao_criados_codigo_acima_do_maior_existente' => $ignorados,
                'codigos_sem_cadastro_que_continuam_sem_provisorio' => count(array_diff_key($codigos, array_flip($criar))),
            ];
        }
        $semFuncao = (int)valor($pdo, 'SELECT COUNT(*) FROM ' . q($db) . '.fo_funcionarios WHERE COALESCE(codtipofuncionario, 0) = 0');
        $resultado['funcionarios_sem_funcao_informada'] = $semFuncao;
        $resultado['funcionarios_ainda_sem_fornecedor_valido'] = (int)valor($pdo, 'SELECT COUNT(*) FROM ' . q($db) . '.fo_funcionarios f WHERE f.codfornecedor > 0 AND NOT EXISTS (SELECT 1 FROM ' . q($db) . '.fo_fornecedores p WHERE p.codfornecedor = f.codfornecedor)');
        if ($aplicar) $pdo->commit(); else $pdo->rollBack();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['erro' => $e->getMessage()];
    }
    return $resultado;
}

/* ---------- etapa 3: recuperar registros do Modelo ---------- */

function etapa3(PDO $pdo, bool $aplicar): array
{
    $origem = MODELO_ORIGEM;
    $destino = MODELO_DESTINO;
    // Ordem respeita as dependências (pais antes dos filhos).
    $tabelas = ['fo_fornecedores', 'fo_funcionarios', 'fo_certificados', 'fo_equipamentos', 'fo_produtoquimico', 'fo_fornecedorxcriterios', 'fo_fornecedoresxtipofornecedores'];
    $pais = ['codfornecedor' => ['fo_fornecedores', 'codfornecedor'], 'codfuncionario' => ['fo_funcionarios', 'codfuncionario']];
    $resultado = [];
    $pdo->beginTransaction();
    try {
        foreach ($tabelas as $tabela) {
            if (!tabelaExiste($pdo, $origem, $tabela) || !tabelaExiste($pdo, $destino, $tabela)) { $resultado[$tabela] = ['erro' => 'tabela ausente na origem ou no destino']; continue; }
            $colsO = colunas($pdo, $origem, $tabela);
            $colsD = colunas($pdo, $destino, $tabela);
            $comuns = [];
            foreach ($colsD as $k => $c) {
                if (isset($colsO[$k]) && stripos($c['EXTRA'], 'GENERATED') === false) $comuns[$k] = $c['COLUMN_NAME'];
            }
            $pk = chavePrimaria($pdo, $destino, $tabela);
            $comparar = $pk ?: array_values($comuns);
            $cond = implode(' AND ', array_map(static fn(string $c) => 'd.' . q($c) . ' <=> s.' . q($colsO[strtolower($c)]['COLUMN_NAME']), $comparar));
            $base = ' FROM ' . q($origem) . '.' . q($tabela) . ' s WHERE NOT EXISTS (SELECT 1 FROM ' . q($destino) . '.' . q($tabela) . " d WHERE $cond)";
            $candidatos = (int)valor($pdo, 'SELECT COUNT(*)' . $base);
            $filtro = '';
            foreach ($pais as $colPai => [$tabPai, $chavePai]) {
                if ($tabPai === $tabela || !isset($comuns[$colPai])) continue;
                $filtro .= ' AND (COALESCE(s.' . q($colsO[$colPai]['COLUMN_NAME']) . ', 0) = 0 OR EXISTS (SELECT 1 FROM ' . q($destino) . '.' . q($tabPai) . ' p WHERE p.' . q($chavePai) . ' = s.' . q($colsO[$colPai]['COLUMN_NAME']) . '))';
            }
            $ids = [];
            if (count($pk) === 1) {
                $ids = array_map('intval', array_column(linhas($pdo, 'SELECT s.' . q($colsO[strtolower($pk[0])]['COLUMN_NAME']) . ' id' . $base . $filtro . ' ORDER BY 1'), 'id'));
            }
            $listaD = implode(', ', array_map('q', $comuns));
            $listaO = implode(', ', array_map(static fn(string $k) => 's.' . q($colsO[$k]['COLUMN_NAME']), array_keys($comuns)));
            $inseridos = $pdo->exec('INSERT INTO ' . q($destino) . '.' . q($tabela) . " ($listaD) SELECT $listaO" . $base . $filtro);
            $naoRecuperados = [];
            if ($filtro !== '' && $candidatos > $inseridos && count($pk) === 1) {
                $sel = ['s.' . q($colsO[strtolower($pk[0])]['COLUMN_NAME']) . ' id'];
                foreach ($pais as $colPai => [$tabPai, $chavePai]) {
                    if ($tabPai === $tabela || !isset($comuns[$colPai])) continue;
                    $c = 's.' . q($colsO[$colPai]['COLUMN_NAME']);
                    $sel[] = "$c $colPai";
                    $sel[] = '(SELECT COUNT(*) FROM ' . q($origem) . '.' . q($tabPai) . ' o WHERE o.' . q($chavePai) . " = $c) {$colPai}_existe_na_origem";
                }
                $naoRecuperados = linhas($pdo, 'SELECT ' . implode(', ', $sel) . ' FROM ' . q($origem) . '.' . q($tabela) . ' s WHERE NOT EXISTS (SELECT 1 FROM ' . q($destino) . '.' . q($tabela) . " d WHERE $cond) ORDER BY 1 LIMIT 50");
            }
            $resultado[$tabela] = [
                'faltando_no_destino' => $candidatos,
                'detalhe_nao_recuperados' => $naoRecuperados,
                'recuperados' => $inseridos,
                'nao_recuperados_por_dependencia' => $candidatos - $inseridos,
                'codigos' => $ids,
            ];
        }
        if ($aplicar) $pdo->commit(); else $pdo->rollBack();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['erro' => $e->getMessage(), 'parcial_desfeito' => $resultado];
    }
    return $resultado;
}

/* ---------- etapa 4: separar o histórico órfão do Atlas ---------- */

function etapa4(PDO $pdo, bool $aplicar): array
{
    $db = ATLAS_DESTINO;
    $tabela = 'fo_fornecedorxcriterios_historico';
    $backup = $tabela . SUFIXO_BACKUP;
    $nova = $tabela . '_novo217';
    $t = q($db) . '.' . q($tabela);
    if (tabelaExiste($pdo, $db, $backup)) {
        return ['situacao' => "já aplicada: $db.$backup existe", 'linhas_ativas' => (int)valor($pdo, "SELECT COUNT(*) FROM $t"), 'linhas_no_backup' => (int)valor($pdo, 'SELECT COUNT(*) FROM ' . q($db) . '.' . q($backup))];
    }
    if (!tabelaExiste($pdo, $db, $tabela)) return ['erro' => "$db.$tabela não existe"];
    $gatilhos = array_column(linhas($pdo, 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA = ? AND EVENT_OBJECT_TABLE = ?', [$db, $tabela]), 'TRIGGER_NAME');
    $fksEntrando = array_column(linhas($pdo, 'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME = ?', [$db, $tabela]), 'CONSTRAINT_NAME');
    if ($gatilhos || $fksEntrando) return ['erro' => 'a tabela tem gatilhos ou chaves estrangeiras apontando para ela; separação não feita', 'gatilhos' => $gatilhos, 'chaves_estrangeiras' => $fksEntrando];

    // Fornecedores provisórios criados pela etapa 2 não contam como existentes: o histórico deles também é separado.
    $valida = '(h.codfornecedor IS NULL OR EXISTS (SELECT 1 FROM ' . q($db) . ".fo_fornecedores p WHERE p.codfornecedor = h.codfornecedor AND COALESCE(p.razaosocial, '') NOT LIKE '%migração " . MIGRACAO . "'))";
    $total = (int)valor($pdo, "SELECT COUNT(*) FROM $t");
    $validas = (int)valor($pdo, "SELECT COUNT(*) FROM $t h WHERE $valida");
    $resultado = [
        'tabela' => "$db.$tabela",
        'linhas_total' => $total,
        'linhas_de_fornecedores_existentes' => $validas,
        'linhas_de_fornecedores_inexistentes' => $total - $validas,
        'fornecedores_inexistentes_distintos' => (int)valor($pdo, "SELECT COUNT(DISTINCT h.codfornecedor) FROM $t h WHERE NOT $valida"),
    ];
    if (!$aplicar) return $resultado + ['situacao' => 'simulação: nada alterado'];

    $pk = chavePrimaria($pdo, $db, $tabela);
    $n = q($db) . '.' . q($nova);
    $pdo->exec("DROP TABLE IF EXISTS $n");
    $pdo->exec("CREATE TABLE $n LIKE $t");
    $copiadas = $pdo->exec("INSERT INTO $n SELECT h.* FROM $t h WHERE $valida");
    // Linhas que chegaram durante a cópia.
    if (count($pk) === 1) {
        $c = q($pk[0]);
        $copiadas += $pdo->exec("INSERT INTO $n SELECT h.* FROM $t h WHERE $valida AND NOT EXISTS (SELECT 1 FROM $n x WHERE x.$c = h.$c)");
        $ai = linhas($pdo, 'SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$db, $tabela, $pk[0]]);
        if ($ai && stripos($ai[0]['EXTRA'], 'auto_increment') !== false) {
            $proximo = (int)valor($pdo, "SELECT COALESCE(MAX($c), 0) + 1 FROM $t");
            $pdo->exec("ALTER TABLE $n AUTO_INCREMENT = $proximo");
        }
    }
    $pdo->exec('RENAME TABLE ' . $t . ' TO ' . q($db) . '.' . q($backup) . ', ' . $n . ' TO ' . $t);
    $resultado['linhas_ativas_depois'] = (int)valor($pdo, "SELECT COUNT(*) FROM $t");
    $resultado['linhas_no_backup'] = (int)valor($pdo, 'SELECT COUNT(*) FROM ' . q($db) . '.' . q($backup));
    $resultado['backup'] = "$db.$backup (cópia integral da tabela original)";
    $resultado['copiadas'] = $copiadas;
    $resultado['situacao'] = $resultado['linhas_no_backup'] >= $total && $resultado['linhas_ativas_depois'] === $copiadas ? 'aplicada' : 'aplicada com divergência de contagem: conferir';
    return $resultado;
}

/* ---------- execução ---------- */

$inicio = date('Y-m-d H:i:s');
$saida = ['migracao' => MIGRACAO, 'modo' => $aplicar ? 'APLICAR' : 'SIMULACAO (nada foi gravado; use --aplicar para gravar)', 'provisorios' => $provisorios, 'etapas' => []];
$ok = true;
foreach ($etapas as $etapa) {
    try {
        $r = match ($etapa) { 2 => etapa2($pdo, $aplicar, $provisorios), 3 => etapa3($pdo, $aplicar), 4 => etapa4($pdo, $aplicar) };
    } catch (Throwable $e) {
        $r = ['erro' => $e->getMessage()];
    }
    $saida['etapas'][$etapa] = $r;
    if (isset($r['erro'])) { $ok = false; continue; }
    if (!$aplicar) continue;
    if ($etapa === 2) {
        $qtd = count($r['fornecedores']['provisorios_criados'] ?? []) + count($r['funcoes']['provisorios_criados'] ?? []);
        registrarMigracao($pdo, ATLAS_DESTINO, 'SANEAMENTO_FO_217_PROVISORIOS', ATLAS_DESTINO . '.fo_*', ATLAS_DESTINO . '.fo_fornecedores/fo_tipofuncionarios', $qtd, $r + ['desfazer' => 'excluir os códigos listados em provisorios_criados (fornecedores e funções) cujo nome termina com "migração 217"'], $inicio);
    } elseif ($etapa === 3) {
        $qtd = array_sum(array_map(static fn($x) => (int)($x['recuperados'] ?? 0), $r));
        registrarMigracao($pdo, MODELO_DESTINO, 'SANEAMENTO_FO_217_RECUPERACAO', MODELO_ORIGEM . '.fo_*', MODELO_DESTINO . '.fo_*', $qtd, $r, $inicio);
    } elseif ($etapa === 4 && ($r['situacao'] ?? '') !== '' && !str_starts_with($r['situacao'], 'já')) {
        registrarMigracao($pdo, ATLAS_DESTINO, 'SANEAMENTO_FO_217_HISTORICO', ATLAS_DESTINO . '.fo_fornecedorxcriterios_historico', ATLAS_DESTINO . '.fo_fornecedorxcriterios_historico' . SUFIXO_BACKUP, (int)($r['linhas_de_fornecedores_inexistentes'] ?? 0), $r + ['desfazer' => 'RENAME TABLE fo_fornecedorxcriterios_historico TO fo_fornecedorxcriterios_historico_descartar, fo_fornecedorxcriterios_historico_bkp217 TO fo_fornecedorxcriterios_historico'], $inicio);
    }
}
if ($aplicar && $ok) {
    $universal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
    if (tabelaExiste($pdo, $universal, 'green_universal_migrations')) {
        $det = json_encode(['etapas' => $etapas, 'bancos' => [ATLAS_DESTINO, MODELO_DESTINO]], JSON_UNESCAPED_UNICODE);
        $existe = valor($pdo, 'SELECT COUNT(*) FROM ' . q($universal) . ".green_universal_migrations WHERE codigo_migracao = 'SANEAMENTO_FO_217'");
        if ($existe) $pdo->prepare('UPDATE ' . q($universal) . ".green_universal_migrations SET status = 'CONCLUIDA', detalhes = ? WHERE codigo_migracao = 'SANEAMENTO_FO_217'")->execute([$det]);
        else $pdo->prepare('INSERT INTO ' . q($universal) . ".green_universal_migrations (codigo_migracao, versao, status, detalhes) VALUES ('SANEAMENTO_FO_217', ?, 'CONCLUIDA', ?)")->execute([VERSAO, $det]);
    }
}
$saida['ok'] = $ok;
echo json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
exit($ok ? 0 : 1);
