<?php
declare(strict_types=1);

/*
 * Migração 294 — Homologação de Fornecedores: junta os documentos repetidos do fornecedor
 * (mesmo fornecedor + tipo + critério em fo_fornecedorxcriterios) e impede novas repetições.
 *
 * Em cada banco de cliente (gmodelo_dbp, gatlas_dbp, gmodelo2_dbp se existir), para cada grupo repetido:
 *  - fica o documento MAIS PREENCHIDO (mais campos informados; empate: alteração mais recente no histórico; depois menor código);
 *  - campos vazios do que fica são completados com os dos repetidos (como no Copiar/Colar);
 *  - as evidências (GED, pasta fo_fornecedorxcriterios) dos repetidos são copiadas para o que fica, sem substituir arquivos;
 *  - o histórico e as demais tabelas que apontam para os repetidos (coluna codfornecedorxcriterios) passam a apontar
 *    para o que fica; cada repetido deixa uma cópia final no histórico antes de sair;
 *  - os repetidos são excluídos.
 * Depois cria o índice único uk_fo_fornecedorxcriterios (codfornecedor, codtipo, codcriterio).
 * Cópia de segurança: bkp294_fo_fornecedorxcriterios (uma única vez).
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-294-documentos-repetidos.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-294-documentos-repetidos.php
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';
require_once dirname(__DIR__) . '/classes/GedStorageService.php';

define('M294_BANCOS', HomologacaoBancos::lista($argv ?? [])); // classes/HomologacaoBancos.php (--banco= para só alguns)
const M294_CAMPOS = ['enviadoodocumento', 'datadevalidade', 'datadereacao', 'situacao', 'incluidocontrato', 'codusuario', 'codusuario_fornecedor',
    'observacao', 'observacao_do_fornecedor', 'emprotocolo', 'datacobranca_emprotocolo'];

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$linhas = static function (PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$valor = static function (PDO $pdo, string $sql, array $p = []) use ($linhas): mixed { $r = $linhas($pdo, $sql, $p); return $r ? array_values($r[0])[0] : null; };
$existeTabela = static fn(string $b, string $t): bool => (bool)$valor($geral, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'", [$b, $t]);
$vazio = static fn($v): bool => $v === null || trim((string)$v) === '' || (string)$v === '0' || str_starts_with((string)$v, '0000-00-00');

$relatorio = ['ok' => true, 'migracao' => '294', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
foreach (M294_BANCOS as $banco) {
    $info = [];
    if (!$valor($geral, 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$banco])) {
        if ($banco !== 'gmodelo2_dbp') { $relatorio['ok'] = false; $relatorio['bancos'][$banco] = ['erro' => 'banco não existe']; }
        continue;
    }
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $pdo->exec("SET SESSION sql_mode = REPLACE(REPLACE(@@SESSION.sql_mode, 'NO_ZERO_DATE', ''), 'NO_ZERO_IN_DATE', '')");
        $ged = new GedStorageService($pdo, $env, ['tenant' => ['banco_dados' => $banco]]);
        $info['pasta_ged'] = $ged->folder('fo_fornecedorxcriterios', 0, true);

        // Tabelas que apontam para o documento (exceto cópias de segurança)
        $refs = array_map(static fn($r) => $r['TABLE_NAME'] . '.' . $r['COLUMN_NAME'], $linhas($geral, "SELECT c.TABLE_NAME, c.COLUMN_NAME FROM information_schema.COLUMNS c
            JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
            WHERE c.TABLE_SCHEMA = ? AND LOWER(c.COLUMN_NAME) = 'codfornecedorxcriterios' AND c.TABLE_NAME <> 'fo_fornecedorxcriterios'
              AND c.TABLE_NAME NOT LIKE 'bkp%' AND c.TABLE_NAME NOT LIKE '%\\_bkp%' AND c.TABLE_NAME NOT LIKE 'fo\\_sinc\\_%'", [$banco]));
        $info['tabelas_que_apontam'] = $refs;

        $grupos = $linhas($pdo, 'SELECT codfornecedor, codtipo, codcriterio, COUNT(*) AS qtd FROM fo_fornecedorxcriterios GROUP BY codfornecedor, codtipo, codcriterio HAVING COUNT(*) > 1');
        $info['grupos_repetidos'] = count($grupos);
        $info['documentos_a_excluir'] = array_sum(array_map(static fn($g) => (int)$g['qtd'] - 1, $grupos));
        $temIndice = (bool)$valor($geral, "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'fo_fornecedorxcriterios' AND INDEX_NAME = 'uk_fo_fornecedorxcriterios'", [$banco]);
        $info['indice_unico'] = $temIndice ? 'já existe' : ($simular ? 'será criado' : '');

        $buscar = $pdo->prepare('SELECT x.*, (SELECT MAX(h.DATAALTERACAO) FROM fo_fornecedorxcriterios_historico h WHERE h.codfornecedorxcriterios = x.codfornecedorxcriterios) AS ultima
            FROM fo_fornecedorxcriterios x WHERE x.codfornecedor <=> ? AND x.codtipo <=> ? AND x.codcriterio <=> ?');
        $exemplos = []; $arquivos = 0; $completados = 0; $excluidos = 0;
        if (!$simular) {
            $bkp = 'bkp294_fo_fornecedorxcriterios';
            if (!$existeTabela($banco, $bkp)) { $pdo->exec("CREATE TABLE `$bkp` AS SELECT * FROM fo_fornecedorxcriterios"); $info['copia'] = "$bkp criada"; } else $info['copia'] = "$bkp já existia (mantida)";
        }
        foreach ($grupos as $g) {
            $buscar->execute([$g['codfornecedor'], $g['codtipo'], $g['codcriterio']]);
            $docs = $buscar->fetchAll(PDO::FETCH_ASSOC); $buscar->closeCursor();
            foreach ($docs as &$d) $d['_pontos'] = count(array_filter(M294_CAMPOS, static fn($c) => !$vazio($d[$c] ?? null)));
            unset($d);
            usort($docs, static fn($a, $b) => ($b['_pontos'] <=> $a['_pontos']) ?: (strcmp((string)$b['ultima'], (string)$a['ultima'])) ?: ((int)$a['codfornecedorxcriterios'] <=> (int)$b['codfornecedorxcriterios']));
            $fica = array_shift($docs);
            $saem = array_map(static fn($d) => (int)$d['codfornecedorxcriterios'], $docs);
            if (count($exemplos) < 10) $exemplos[] = ['fornecedor' => $g['codfornecedor'], 'tipo' => $g['codtipo'], 'criterio' => $g['codcriterio'], 'fica' => (int)$fica['codfornecedorxcriterios'], 'saem' => $saem];
            if ($simular) continue;

            // Completa os campos vazios do que fica com os dos repetidos (na ordem de preenchimento)
            $novos = [];
            foreach (M294_CAMPOS as $c) {
                if (!$vazio($fica[$c] ?? null)) continue;
                foreach ($docs as $d) if (!$vazio($d[$c] ?? null)) { $novos[$c] = $d[$c]; break; }
            }
            $pdo->beginTransaction();
            try {
                if ($novos) {
                    $sets = implode(', ', array_map(static fn($c) => "`$c` = ?", array_keys($novos)));
                    $pdo->prepare("UPDATE fo_fornecedorxcriterios SET $sets WHERE codfornecedorxcriterios = ?")->execute([...array_values($novos), $fica['codfornecedorxcriterios']]);
                    $completados++;
                }
                $in = implode(',', $saem);
                // Cópia final de cada repetido no histórico (usuário 0 = migração) e histórico/apontamentos para o que fica
                $pdo->exec("INSERT INTO fo_fornecedorxcriterios_historico (CODUSUARIOALTERACAO, DATAALTERACAO, codfornecedorxcriterios, codfornecedor, codtipo, codcriterio,
                        enviadoodocumento, datadevalidade, datadereacao, situacao, incluidocontrato, codusuario, codusuario_fornecedor, observacao,
                        observacao_do_fornecedor, emprotocolo, datacobranca_emprotocolo)
                    SELECT 0, NOW(), codfornecedorxcriterios, codfornecedor, codtipo, codcriterio, enviadoodocumento, datadevalidade, datadereacao, situacao,
                           incluidocontrato, codusuario, codusuario_fornecedor, observacao, observacao_do_fornecedor, emprotocolo, datacobranca_emprotocolo
                      FROM fo_fornecedorxcriterios WHERE codfornecedorxcriterios IN ($in)");
                foreach ($refs as $ref) {
                    [$t, $c] = explode('.', $ref, 2);
                    $pdo->prepare("UPDATE `$t` SET `$c` = ? WHERE `$c` IN ($in)")->execute([$fica['codfornecedorxcriterios']]);
                }
                $pdo->prepare('INSERT INTO fo_fornecedorxcriterios_historico (CODUSUARIOALTERACAO, DATAALTERACAO, codfornecedorxcriterios, codfornecedor, codtipo, codcriterio,
                        enviadoodocumento, datadevalidade, datadereacao, situacao, incluidocontrato, codusuario, codusuario_fornecedor, observacao,
                        observacao_do_fornecedor, emprotocolo, datacobranca_emprotocolo)
                    SELECT 0, NOW(), codfornecedorxcriterios, codfornecedor, codtipo, codcriterio, enviadoodocumento, datadevalidade, datadereacao, situacao,
                           incluidocontrato, codusuario, codusuario_fornecedor, observacao, observacao_do_fornecedor, emprotocolo, datacobranca_emprotocolo
                      FROM fo_fornecedorxcriterios WHERE codfornecedorxcriterios = ?')->execute([$fica['codfornecedorxcriterios']]);
                $excluidos += $pdo->exec("DELETE FROM fo_fornecedorxcriterios WHERE codfornecedorxcriterios IN ($in)");
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }

            // Evidências dos repetidos para o que fica (sem substituir)
            $destino = $ged->folder('fo_fornecedorxcriterios', (int)$fica['codfornecedorxcriterios'], true);
            foreach ($saem as $s) {
                foreach ($ged->folders('fo_fornecedorxcriterios', $s) as $pasta) {
                    if (!is_dir($pasta)) continue;
                    foreach (new DirectoryIterator($pasta) as $f) {
                        if (!$f->isFile()) continue;
                        if (!is_dir($destino) && !@mkdir($destino, 0770, true) && !is_dir($destino)) continue 3;
                        $alvo = $destino . DIRECTORY_SEPARATOR . $f->getFilename();
                        if (!is_file($alvo) && @copy($f->getPathname(), $alvo)) $arquivos++;
                    }
                }
            }
        }
        $info['exemplos'] = $exemplos;
        if (!$simular) {
            $info['documentos_completados'] = $completados;
            $info['documentos_excluidos'] = $excluidos;
            $info['arquivos_copiados'] = $arquivos;
            if (!$temIndice) { $pdo->exec('ALTER TABLE fo_fornecedorxcriterios ADD UNIQUE KEY uk_fo_fornecedorxcriterios (codfornecedor, codtipo, codcriterio)'); $info['indice_unico'] = 'criado'; }
            $info['documentos_depois'] = (int)$valor($pdo, 'SELECT COUNT(*) FROM fo_fornecedorxcriterios');
            $info['documentos_na_aba'] = (int)$valor($pdo, 'SELECT COUNT(*) FROM vw_homologacao_fornecedor_documentos');
            if ($existeTabela($banco, 'registros_migracao')) {
                $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
                    VALUES ('HOMOLOGACAO_DOCUMENTOS_REPETIDOS_294', 'fo_fornecedorxcriterios', ?, 'DADOS', '4.0.1', 'CONCLUIDA', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
                    ON DUPLICATE KEY UPDATE status = VALUES(status), quantidade_registros = VALUES(quantidade_registros), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
                    ->execute(["$banco: documentos repetidos juntados; índice único", $excluidos, json_encode($info, JSON_UNESCAPED_UNICODE)]);
            }
        }
    } catch (Throwable $e) {
        $relatorio['ok'] = false;
        $info['erro'] = $e->getMessage();
    }
    $relatorio['bancos'][$banco] = $info;
}

$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_294_documentos_repetidos' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
