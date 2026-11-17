<?php
declare(strict_types=1);

require_once __DIR__ . '/../classes/GreenPortalDatabase.php';

/**
 * Analisa e migra perguntas de glegislacao_dbp sem destruir o legado.
 * A rotina e idempotente e deixa a decisao das excecoes registrada no universal.
 */
function normalizarPergunta(string $texto): string
{
    $texto = trim(preg_replace('/\s+/u', ' ', mb_strtolower($texto, 'UTF-8')) ?? $texto);
    $transliterado = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
    if (is_string($transliterado) && $transliterado !== '') {
        $texto = $transliterado;
    }
    return trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);
}

function classificarPergunta(string $texto, int $vinculos, string $normalizado): array
{
    if ($normalizado === '') {
        return ['REVISAR_VAZIA', 'Texto vazio ou composto apenas por espacos.'];
    }
    if (mb_strlen($texto, 'UTF-8') < 10) {
        return ['REVISAR_TEXTO_CURTO', 'Texto muito curto para ser migrado automaticamente.'];
    }
    if (preg_match('/^(pergunta|perguntas|teste|test|xxx|asdf|n\/?a|nao se aplica)$/u', $normalizado)) {
        return ['REVISAR_PLACEHOLDER', 'Texto identificado como marcador ou teste.'];
    }
    if ($vinculos < 1) {
        return ['REVISAR_SEM_VINCULO', 'Pergunta sem vinculo em legislacaoxpergunta.'];
    }
    return ['MIGRAR', 'Pergunta valida, vinculada a legislacao e sem duplicata conhecida.'];
}

$env = GreenPortalDatabase::env();
$universal = GreenPortalDatabase::connect($env, 'guniversal_dbp', 'GUNIVERSAL');
$legislacao = GreenPortalDatabase::connect($env, $env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp', 'GLEGISLACAO');
$schema = file_get_contents(__DIR__ . '/../database/203_questionnaire_legal_saneamento.sql');
if ($schema === false) {
    throw new RuntimeException('Schema da migracao 203 nao encontrado.');
}

$universal->exec($schema);
$versao = $universal->query(
    "SELECT v.codversao
       FROM amb_questionario_versao v
       JOIN amb_questionario q ON q.codquestionario=v.codquestionario
      WHERE q.codigo='LEGAL_REQUIREMENTS' AND v.versao=1
      LIMIT 1"
)->fetchColumn();
if (!$versao) {
    throw new RuntimeException('A versao 1 do questionario LEGAL_REQUIREMENTS nao existe.');
}

$perguntas = $legislacao->query(
    "SELECT p.CODPERGUNTA, p.DSCPERGUNTA, COALESCE(v.qtd_vinculos,0) AS qtd_vinculos
       FROM perguntas p
       LEFT JOIN (
           SELECT CODPERGUNTA, COUNT(*) AS qtd_vinculos
             FROM legislacaoxpergunta
            GROUP BY CODPERGUNTA
       ) v ON v.CODPERGUNTA=p.CODPERGUNTA
      ORDER BY p.CODPERGUNTA"
)->fetchAll(PDO::FETCH_ASSOC);

$vinculos = $legislacao->query(
    'SELECT CODLEGISLACAO, CODPERGUNTA FROM legislacaoxpergunta ORDER BY CODPERGUNTA,CODLEGISLACAO'
)->fetchAll(PDO::FETCH_ASSOC);

$alvosPorOrigem = [];
$canonicosPorHash = [];
$analise = [];
$stats = [
    'origem_perguntas' => count($perguntas),
    'origem_vinculos' => count($vinculos),
    'migradas' => 0,
    'duplicadas' => 0,
    'revisar_vazia' => 0,
    'revisar_texto_curto' => 0,
    'revisar_placeholder' => 0,
    'revisar_sem_vinculo' => 0,
    'vinculos_com_destino' => 0,
    'vinculos_sem_destino' => 0,
];

$universal->beginTransaction();
try {
    $buscarDestino = $universal->prepare(
        'SELECT codpergunta FROM amb_questionario_pergunta WHERE codversao=:versao AND codigo=:codigo LIMIT 1'
    );
    $inserirDestino = $universal->prepare(
        'INSERT INTO amb_questionario_pergunta
            (codversao,codigo,texto_pt,tipo,obrigatoria,ordem,peso,ativo)
         VALUES (:versao,:codigo,:texto,\'TEXTO\',0,:ordem,1,1)'
    );
    $upsertAnalise = $universal->prepare(
        'INSERT INTO amb_questionario_pergunta_saneamento
            (banco_origem,tabela_origem,codigo_origem,texto_original,texto_normalizado,hash_texto,
             quantidade_vinculos,codpergunta_canonica_origem,classificacao,motivo,codpergunta_destino,analisado_em)
         VALUES (:banco,:tabela,:codigo,:texto,:normalizado,:hash,:vinculos,:canonico,:classificacao,:motivo,:destino,NOW())
         ON DUPLICATE KEY UPDATE
            texto_original=VALUES(texto_original),texto_normalizado=VALUES(texto_normalizado),
            hash_texto=VALUES(hash_texto),quantidade_vinculos=VALUES(quantidade_vinculos),
            codpergunta_canonica_origem=VALUES(codpergunta_canonica_origem),
            classificacao=VALUES(classificacao),motivo=VALUES(motivo),
            codpergunta_destino=VALUES(codpergunta_destino),analisado_em=NOW()'
    );

    foreach ($perguntas as $posicao => $row) {
        $codigoOrigem = (int)$row['CODPERGUNTA'];
        $texto = trim((string)($row['DSCPERGUNTA'] ?? ''));
        $normalizado = normalizarPergunta($texto);
        $hash = hash('sha256', $normalizado);
        $qtdVinculos = (int)$row['qtd_vinculos'];
        [$classificacao, $motivo] = classificarPergunta($texto, $qtdVinculos, $normalizado);
        $canonicoOrigem = null;
        $destino = null;

        if ($classificacao === 'MIGRAR' && isset($canonicosPorHash[$hash])) {
            $classificacao = 'DUPLICADA';
            $motivo = 'Mesmo texto normalizado da pergunta canonica ' . $canonicosPorHash[$hash] . '.';
            $canonicoOrigem = $canonicosPorHash[$hash];
            $destino = $alvosPorOrigem[$canonicoOrigem] ?? null;
            $stats['duplicadas']++;
        } elseif ($classificacao === 'MIGRAR') {
            $codigoDestino = 'LEGADO_PERGUNTA_' . $codigoOrigem;
            $buscarDestino->execute(['versao' => $versao, 'codigo' => $codigoDestino]);
            $destino = (int)($buscarDestino->fetchColumn() ?: 0);
            if ($destino < 1) {
                $inserirDestino->execute([
                    'versao' => $versao,
                    'codigo' => $codigoDestino,
                    'texto' => $texto,
                    'ordem' => $posicao + 1,
                ]);
                $destino = (int)$universal->lastInsertId();
            }
            $canonicosPorHash[$hash] = $codigoOrigem;
            $stats['migradas']++;
        } else {
            $stats[strtolower($classificacao)] = ($stats[strtolower($classificacao)] ?? 0) + 1;
        }

        $alvosPorOrigem[$codigoOrigem] = $destino ?: null;
        $analise[] = [
            'codigo' => $codigoOrigem,
            'destino' => $destino ?: null,
            'classificacao' => $classificacao,
        ];
        $upsertAnalise->execute([
            'banco' => $env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp',
            'tabela' => 'perguntas',
            'codigo' => $codigoOrigem,
            'texto' => $texto,
            'normalizado' => $normalizado,
            'hash' => $hash,
            'vinculos' => $qtdVinculos,
            'canonico' => $canonicoOrigem,
            'classificacao' => $classificacao,
            'motivo' => $motivo,
            'destino' => $destino ?: null,
        ]);
    }

    $upsertVinculo = $universal->prepare(
        'INSERT INTO amb_questionario_pergunta_legado_vinculo
            (banco_origem,tabela_origem,codigo_pergunta_origem,codigo_legislacao_origem,codpergunta_destino)
         VALUES (:banco,:tabela,:pergunta,:legislacao,:destino)
         ON DUPLICATE KEY UPDATE codpergunta_destino=VALUES(codpergunta_destino)'
    );
    foreach ($vinculos as $vinculo) {
        $origemPergunta = (int)$vinculo['CODPERGUNTA'];
        $destino = $alvosPorOrigem[$origemPergunta] ?? null;
        $upsertVinculo->execute([
            'banco' => $env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp',
            'tabela' => 'legislacaoxpergunta',
            'pergunta' => $origemPergunta,
            'legislacao' => (int)$vinculo['CODLEGISLACAO'],
            'destino' => $destino,
        ]);
        if ($destino) $stats['vinculos_com_destino']++;
        else $stats['vinculos_sem_destino']++;
    }

    $detalhes = [
        'origem' => 'glegislacao_dbp.perguntas + legislacaoxpergunta',
        'destino' => 'guniversal_dbp.amb_questionario_pergunta',
        'questionario' => 'LEGAL_REQUIREMENTS',
        'versao' => 1,
        'regras' => [
            'validas_vinculadas_sem_duplicata' => 'MIGRAR',
            'texto_vazio_ou_curto' => 'REVISAR',
            'marcador_ou_teste' => 'REVISAR',
            'sem_vinculo' => 'REVISAR',
            'texto_normalizado_repetido' => 'DUPLICADA',
        ],
        'estatisticas' => $stats,
        'revisao_manual_necessaria' => true,
        'legado_preservado' => true,
        'idempotente' => true,
    ];
    $migration = $universal->prepare(
        'INSERT INTO green_universal_migrations(codigo_migracao,versao,status,detalhes)
         VALUES (:codigo,\'4.0.1\',\'CONCLUIDA\',:detalhes)
         ON DUPLICATE KEY UPDATE status=VALUES(status),detalhes=VALUES(detalhes),executado_em=CURRENT_TIMESTAMP'
    );
    $migration->execute([
        'codigo' => 'GREEN_QUESTIONNAIRE_LEGAL_SANITIZATION_203',
        'detalhes' => json_encode($detalhes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $universal->commit();
} catch (Throwable $error) {
    if ($universal->inTransaction()) $universal->rollBack();
    throw $error;
}

$stats['perguntas_destino'] = (int)$universal->query(
    'SELECT COUNT(*) FROM amb_questionario_pergunta WHERE codversao=' . (int)$versao . ' AND codigo LIKE \'LEGADO_PERGUNTA_%\''
)->fetchColumn();
$stats['saneamentos_registrados'] = (int)$universal->query(
    'SELECT COUNT(*) FROM amb_questionario_pergunta_saneamento WHERE tabela_origem=\'perguntas\''
)->fetchColumn();
$stats['vinculos_registrados'] = (int)$universal->query(
    'SELECT COUNT(*) FROM amb_questionario_pergunta_legado_vinculo WHERE tabela_origem=\'legislacaoxpergunta\''
)->fetchColumn();

echo json_encode([
    'ok' => true,
    'migration' => '203',
    'status' => 'CONCLUIDA_COM_REVISAO_MANUAL',
    'questionario' => 'LEGAL_REQUIREMENTS',
    'versao' => 1,
    'estatisticas' => $stats,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
