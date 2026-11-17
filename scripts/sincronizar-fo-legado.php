<?php
declare(strict_types=1);

/*
 * Sincronismo legado -> novo das tabelas fo_* (Homologação de Fornecedores) e nc_* (Não conformidades, desde 18/10/2026),
 * do Módulo Legal das empresas e da legislação central (desde a migração 393 a legislação central vem por OPERAÇÕES: só o que
 * foi incluído/alterado/excluído no legado desde a última execução — classes/SincronismoLegislacaoOperacoes.php). Prefixos, tabelas desconsideradas e prefixos mantidos no greenportal
 * por banco: classes/SincronismoLegado.php.
 *
 * Até a virada definitiva, o cadastro é feito SOMENTE no sistema legado. Este script deixa o
 * banco novo igual ao legado nas tabelas fo_* que existem nos dois lados:
 *   - inclui no novo o que foi cadastrado no legado;
 *   - atualiza no novo o que foi alterado no legado (o legado prevalece);
 *   - exclui no novo o que foi excluído no legado, guardando antes uma cópia em fo_sinc_exclusoes.
 *
 * Proteções:
 *   - cadastros provisórios da migração 217 ("... - migração 217") nunca são excluídos;
 *   - registros com chave estrangeira para algo inexistente no novo não são incluídos/alterados
 *     (são contados no relatório), e registros ainda referenciados não são excluídos;
 *   - campos confirmados no sistema novo e registrados em fo_sinc_protecoes (ex.: validade de ASO e de
 *     treinamentos confirmada pela análise de documentos por IA, migração 221) NÃO são mais protegidos
 *     (SINC_RESPEITAR_PROTECOES = false, decisão do usuário em 18/10/2026: "o legado continua mandando até a virada";
 *     o registro em fo_sinc_protecoes é mantido; para voltar a proteger, trocar a constante para true);
 *   - fo_fornecedorxcriterios_historico só recebe inclusões, e só de fornecedores reais (ver migração 217);
 *   - uma execução por banco de cada vez (GET_LOCK);
 *   - tabelas de SINC_MESTRE_NOVO (ex.: fo_criteriofuncionarios, migração 245) são mantidas no sistema novo e não são sincronizadas,
 *     SÓ nos bancos de SINC_MESTRE_NOVO_BANCOS (MODELO, Atlas, MODELO2). Nas demais empresas (ex.: Crown) o legado prevalece em
 *     todas as tabelas fo_* (decisão do usuário em 18/10/2026: "o que está no legado tem que estar no greenportal");
 *   - durante o sincronismo os gatilhos que GERAM documentos/itens (migrações 293 e 303) ficam parados (@gp_sinc_legado = 1):
 *     os documentos e itens vêm prontos do legado; sem isso o gatilho criaria cópias com outro código;
 *   - inclusão que repetiria um índice único do greenportal (ex.: documento repetido fornecedor+tipo+critério, juntado pela 294)
 *     é ignorada e contada em ignorados_duplicidade;
 *   - migração 333: participantes externos das listas de NC (<legado>_seguranca.usuarios com ativado = 'X' -> nc_participantes,
 *     classes/NaoConformidadeParticipantes.php); listas criadas no greenportal (código a partir de 50001) não são apagadas;
 *   - migração 316: unidades de cada usuário (<legado>_seguranca.usuariosxunidades -> usuariosxunidades do greenportal), base da
 *     restrição por unidade da Homologação e da Portaria; o legado prevalece (inclui o que falta e exclui o que saiu, com cópia
 *     em fo_sinc_exclusoes). Só nos bancos em que a migração 316 criou a tabela;
 *
 * Por padrão APENAS SIMULA (tudo roda numa transação desfeita no fim). Para gravar: --aplicar.
 *
 * Validação: depois do --aplicar, o relatório traz "validacao" por banco (legado × greenportal, ver validarReplica).
 * Só a validação, sem sincronizar: --validar (relatório em storage\logs\sincronismo_validacao_*.json).
 *
 * Uso (em E:\wamp\apps\greenportal):
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\sincronizar-fo-legado.php              (simulação)
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\sincronizar-fo-legado.php --aplicar    (grava)
 *   Opcional: --empresa=atlas | modelo   (padrão: todas)
 * Agendamento diário: scripts\sincronizar-fo-legado.bat (Agendador de Tarefas do Windows).
 */

date_default_timezone_set('America/Sao_Paulo'); // horários dos relatórios no fuso do servidor (o php.ini do CLI está em UTC)
require_once __DIR__ . '/../classes/GreenPortalDatabase.php';
require_once __DIR__ . '/../classes/SincronismoLegado.php'; // prefixos (fo_, nc_), tabelas desconsideradas e mantidas no novo
require_once __DIR__ . '/../classes/NaoConformidadeParticipantes.php'; // migração 333: participantes externos das listas de NC
require_once __DIR__ . '/../classes/SincronismoLegislacaoOperacoes.php'; // migração 393: legislação central por operações (de-para)

const SINC_SOMENTE_INCLUSAO = ['fo_fornecedorxcriterios_historico'];
// Migração 393 (17/11/2026, decisão do usuário): a legislação central deixa de ser ESPELHO do legado. Agora vêm só as
// operações feitas no legado desde a última execução (inclusões, alterações das colunas que mudaram, exclusões); o que foi
// feito no greenportal fica; se o mesmo registro mudou dos dois lados, vale o legado. 'espelho' volta ao comportamento antigo.
const SINC_LEGISLACAO_MODO = 'operacoes';
// Proteção de campos confirmados no greenportal (fo_sinc_protecoes). Desligada em 18/10/2026 por decisão do usuário:
// até a virada o legado prevalece também nesses campos (homologação, condicionantes e plano de ação).
const SINC_RESPEITAR_PROTECOES = false;
const SINC_PROVISORIOS = ['fo_fornecedores' => 'razaosocial', 'fo_tipofuncionarios' => 'dsctipo'];
// Tabelas mantidas no sistema novo (não são sincronizadas a partir do legado). Migração 245: os critérios dos
// funcionários são cadastrados na transação homologacaofornecedores_criteriosfuncionarios; o sincronismo não
// pode desfazer inclusões, alterações e exclusões feitas nela.
// 245: critérios dos funcionários; 248: tipos de treinamento; 249: tipos de funcionário e seus vínculos.
const SINC_MESTRE_NOVO = ['fo_criteriofuncionarios', 'fo_tipotreinamentos', 'fo_tipofuncionarios',
    'fo_tipofuncionariosxcriteriofuncionarios', 'fo_tipofuncionariosxtipotreinamentos', 'fo_tipofuncionariosxpalavraschave',
    // Migração 292: tipos e critérios de fornecedor, nível de exigência e palavras-chave mantidos no sistema novo.
    'fo_tipofornecedores', 'fo_criteriofornecedores', 'fo_tipofornecedoresxcriteriofornecedores', 'fo_tipofornecedoresxpalavraschave',
    // Migração 293: documentos do fornecedor, histórico e tipos do fornecedor (com a auditoria por tipo) mantidos no sistema novo.
    'fo_fornecedorxcriterios', 'fo_fornecedorxcriterios_historico', 'fo_fornecedoresxtipofornecedores',
    // Migração 296: Fornecedor x Usuários (acesso do fornecedor externo) mantido no sistema novo.
    'fo_fornecedorxusuario'];
// Bancos em que as tabelas acima são mantidas no greenportal. Nos outros, o sincronismo traz também essas tabelas do legado.
const SINC_MESTRE_NOVO_BANCOS = ['gmodelo_dbp', 'gatlas_dbp', 'gmodelo2_dbp'];

$aplicar = in_array('--aplicar', $argv, true);

$env = GreenPortalDatabase::env();
$pdo = new PDO('mysql:host=' . ($env['GPT_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['GPT_DB_PORT'] ?? '3306') . ';charset=utf8mb4', $env['GPT_DB_USERNAME'] ?? '', $env['GPT_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
// Cópia fiel do legado (datas 0000-00-00 etc.), como na migração original das tabelas fo_*.
$pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
// Gatilhos de geração (293/303) não agem nas gravações do sincronismo: os registros vêm prontos do legado.
$pdo->exec('SET @gp_sinc_legado = 1');
@set_time_limit(0);
$sincPares = paresMigrados($pdo);
$empresas = array_keys($sincPares);
$empresaFiltro = null;
foreach ($argv as $arg) if (str_starts_with($arg, '--empresa=')) $empresaFiltro = strtolower(substr($arg, 10));
if ($empresaFiltro !== null) {
    $sincPares = array_intersect_key($sincPares, [$empresaFiltro => true]);
    $empresas = array_keys($sincPares);
}

function q(string $n): string { return '`' . str_replace('`', '``', $n) . '`'; }
function linhas(PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(); $s->closeCursor(); return $r; }
function valor(PDO $pdo, string $sql, array $p = []): mixed { $r = linhas($pdo, $sql, $p); return $r ? array_values($r[0])[0] : null; }
function existeBanco(PDO $pdo, string $db): bool { return (bool)valor($pdo, 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$db]); }
function prepararBackupExclusoes(PDO $pdo, string $db, string $prefix): void { $pdo->exec('CREATE TABLE IF NOT EXISTS '.q($db).'.'.q($prefix.'_sinc_exclusoes').' (codexclusao BIGINT AUTO_INCREMENT PRIMARY KEY,tabela VARCHAR(128) NOT NULL,chave JSON NULL,dados JSON NOT NULL,excluido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'); }

// O registro em registros_migracao é só informativo: se a tabela do banco tiver outro formato (ex.: glegislacao_dbp sem
// quantidade_registros), avisa no stderr e segue — antes um erro aqui derrubava o relatório depois de tudo já gravado.
function registrarSincronismo(PDO $pdo, string $db, string $codigo, string $origem, string $destino, array $resultado, string $inicio, string $status = 'CONCLUIDA'): void
{
    try { registrarSincronismoTabela($pdo, $db, $codigo, $origem, $destino, $resultado, $inicio, $status); }
    catch (Throwable $e) { fwrite(STDERR, "Aviso: registros_migracao de $db não atualizado ($codigo): " . $e->getMessage() . PHP_EOL); }
}

function registrarSincronismoTabela(PDO $pdo, string $db, string $codigo, string $origem, string $destino, array $resultado, string $inicio, string $status): void
{
    if (!existeBanco($pdo, $db) || (int)valor($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'registros_migracao'", [$db]) === 0) return;
    $totais = $resultado['totais'] ?? [];
    $quantidade = (int)($totais['afetados'] ?? 0);
    $erro = isset($resultado['erro']) ? (string)$resultado['erro'] : null;
    $id = valor($pdo, 'SELECT codregistromigracao FROM '.q($db).'.registros_migracao WHERE codigo_migracao = ? LIMIT 1', [$codigo]);
    $detalhes = json_encode(['ultima_execucao'=>$inicio,'origem'=>$origem,'totais'=>$totais,'tabelas'=>$resultado['tabelas'] ?? [],'erro'=>$erro], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $concluida = $status === 'EM_EXECUCAO' ? 'NULL' : 'CURRENT_TIMESTAMP';
    if ($id) {
        $pdo->prepare('UPDATE '.q($db).'.registros_migracao SET status=?, quantidade_registros=?, iniciada_em=?, concluida_em='.$concluida.', detalhes=? WHERE codregistromigracao=?')->execute([$status,$quantidade,$inicio,$detalhes,$id]);
    } else {
        $pdo->prepare('INSERT INTO '.q($db).'.registros_migracao (codigo_migracao,origem,destino,tipo,versao,status,quantidade_registros,iniciada_em,concluida_em,detalhes) VALUES (?,?,?,\'ESTRUTURA\',\'4.0.1\',?,?,?,'.$concluida.',?)')->execute([$codigo,$origem,$destino,$status,$quantidade,$inicio,$detalhes]);
    }
}

function paresMigrados(PDO $pdo): array
{
    $pares = [];
    // Decisão do usuário (24/10/2026): empresa com o sincronismo desligado (empresa_migracao_config.sincronismo_ativo = 0, ex.: legado
    // congelado) fica fora. Vale depois que a migração 305 revisada liga a regra (sincronismo_config.respeitar_ativo = 1).
    $desligadas = [];
    try {
        if ((int)valor($pdo, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='guniversal_dbp' AND TABLE_NAME='sincronismo_config' AND COLUMN_NAME='respeitar_ativo'") > 0
            && (int)valor($pdo, 'SELECT COALESCE(MAX(respeitar_ativo), 0) FROM guniversal_dbp.sincronismo_config WHERE codconfig = 1') === 1)
            foreach (linhas($pdo, 'SELECT codempresa FROM guniversal_dbp.empresa_migracao_config WHERE sincronismo_ativo = 0') as $d) $desligadas[(int)$d['codempresa']] = true;
    } catch (Throwable) { $desligadas = []; }
    foreach (linhas($pdo, "SELECT codempresa,codigo,nome,banco_dados,status FROM guniversal_dbp.empresa WHERE banco_dados IS NOT NULL AND status='ATIVA'") as $empresa) {
        if (isset($desligadas[(int)$empresa['codempresa']])) continue;
        $destino = (string)$empresa['banco_dados']; if (!preg_match('/^g[a-z0-9_]+_dbp$/i', $destino) || !existeBanco($pdo, $destino)) continue;
        $req = linhas($pdo, 'SELECT status,bancos_origem FROM guniversal_dbp.migracao_solicitacoes WHERE banco_destino=? ORDER BY codsolicitacao DESC LIMIT 1', [$destino]);
        if ($req && (string)$req[0]['status'] !== 'FINALIZADO_COM_SUCESSO') continue;
        $origens = $req ? (json_decode((string)$req[0]['bancos_origem'], true) ?: []) : [];
        if (!$origens) {
            try { foreach (linhas($pdo, 'SELECT origem FROM ' . q($destino) . ".registros_migracao WHERE status='CONCLUIDA' AND origem LIKE 'green_%' ORDER BY codregistromigracao DESC") as $registro) foreach (preg_split('/[^A-Za-z0-9_]+/', (string)$registro['origem']) as $possivel) if (str_starts_with($possivel, 'green_')) $origens[] = $possivel; } catch (Throwable) {}
        }
        $origens = array_values(array_unique($origens));
        foreach ($origens as $origem) if (is_string($origem) && preg_match('/^[A-Za-z0-9_]+$/', $origem) && existeBanco($pdo, $origem) && linhas($pdo, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME LIKE 'fo\\_%' AND TABLE_TYPE='BASE TABLE' LIMIT 1", [$origem])) { $pares[strtolower((string)$empresa['codigo'])][] = [$origem, $destino]; break; }
    }
    return $pares;
}

function sincronizarLegislacao(PDO $pdo, bool $aplicar): array
{
    if (SINC_LEGISLACAO_MODO !== 'operacoes') return sincronizarLegislacaoEspelho($pdo, $aplicar);
    $limite = 500;
    try { $limite = (int)(valor($pdo, 'SELECT limite_exclusoes FROM guniversal_dbp.sincronismo_config WHERE codconfig = 1') ?? 500) ?: 500; } catch (Throwable) {}
    return SincronismoLegislacaoOperacoes::executar($pdo, $aplicar, $limite);
}

/** Comportamento antigo (até a migração 393): deixa a legislação central igual ao legado (sobrescreve e exclui). */
function sincronizarLegislacaoEspelho(PDO $pdo, bool $aplicar): array
{
    $resultado = ['origem'=>'green_legislacao','destino'=>'glegislacao_dbp','tabelas'=>[],'totais'=>['processados'=>0,'afetados'=>0,'excluidos'=>0]];
    $lock = 'sinc_legislacao_central';
    if ((int)valor($pdo, 'SELECT GET_LOCK(?, 5)', [$lock]) !== 1) return $resultado + ['erro'=>'outra sincronizacao da legislacao central esta em execucao'];
    if (!existeBanco($pdo, 'green_legislacao') || !existeBanco($pdo, 'glegislacao_dbp')) return $resultado + ['erro'=>'banco central de legislação não existe'];
    $pdo->exec("SET SESSION collation_connection = 'utf8mb4_bin'");
    $tabelas = array_column(linhas($pdo, "SELECT s.TABLE_NAME FROM information_schema.TABLES s JOIN information_schema.TABLES d ON d.TABLE_SCHEMA='glegislacao_dbp' AND d.TABLE_NAME=s.TABLE_NAME AND d.TABLE_TYPE='BASE TABLE' WHERE s.TABLE_SCHEMA='green_legislacao' AND s.TABLE_TYPE='BASE TABLE' AND (s.TABLE_NAME LIKE 'legislacao%' OR s.TABLE_NAME IN ('tipos','ambitos','assuntos','perguntas')) ORDER BY s.TABLE_NAME"), 'TABLE_NAME');
    // A tabela de backup é criada ANTES da transação: CREATE TABLE faz commit implícito no MySQL
    // (era a causa de "There is no active transaction" no --aplicar).
    if($aplicar) prepararBackupExclusoes($pdo,'glegislacao_dbp','legislacao');
    $pdo->beginTransaction();
    try {
        foreach ($tabelas as $table) {
            $sourceColumns=colunas($pdo,'green_legislacao',$table);$targetColumns=colunas($pdo,'glegislacao_dbp',$table);$fields=array_values(array_intersect_key($targetColumns,$sourceColumns));if(!$fields)continue;
            $pk=array_column(linhas($pdo,'SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND CONSTRAINT_NAME=\'PRIMARY\' ORDER BY ORDINAL_POSITION',['glegislacao_dbp',$table]),'COLUMN_NAME');
            // Sem chave primária (legislacaoxassunto, legislacaoxpergunta): compara pelo conteúdo (antes era pulada sem aviso).
            if(!$pk){$expr=[];foreach($fields as $c)$expr[$c]='s.'.q($sourceColumns[strtolower($c)]);$plan=sincronizarSemChave($pdo,'green_legislacao','glegislacao_dbp',$table,$expr,$aplicar,'legislacao_sinc_exclusoes');$resultado['tabelas'][$table]=$plan;$resultado['totais']['processados']+=$plan['origem'];$resultado['totais']['afetados']+=$plan['afetados'];$resultado['totais']['excluidos']+=$plan['excluidos'];continue;}
            if(array_diff(array_map('strtolower',$pk),array_map('strtolower',$fields))){$expr=[];foreach($fields as $c)$expr[$c]='s.'.q($sourceColumns[strtolower($c)]);$plan=sincronizarSemChaveComum($pdo,'green_legislacao','glegislacao_dbp',$table,$expr,$pk,$aplicar,'legislacao_sinc_exclusoes');$resultado['tabelas'][$table]=$plan;$resultado['totais']['processados']+=$plan['origem'];$resultado['totais']['afetados']+=$plan['afetados'];$resultado['totais']['excluidos']+=$plan['duplicados_removidos'];continue;}
            $fieldSql=implode(',',array_map('q',$fields));$sourceSql=implode(',',array_map(static fn($c)=>'s.'.q($c),$fields));$updates=array_values(array_diff($fields,$pk));$plan=['origem'=>(int)valor($pdo,"SELECT COUNT(*) FROM `green_legislacao`.`$table`"),'afetados'=>0];
            $updateSql=$updates?implode(',',array_map(static fn($c)=>q($c).'=VALUES('.q($c).')',$updates)):q($pk[0]).'=VALUES('.q($pk[0]).')';
            $plan['afetados']=(int)$pdo->exec("INSERT INTO `glegislacao_dbp`.`$table` ($fieldSql) SELECT $sourceSql FROM `green_legislacao`.`$table` s ON DUPLICATE KEY UPDATE $updateSql");
            $plan['excluidos']=0;$plan['protegidos']=0;$pkJoin=implode(' AND ',array_map(static fn($field)=>'d.'.q($field).' <=> s.'.q($field),array_intersect($pk,$fields)));$origemCount=$plan['origem'];
            if($origemCount>0&&$pkJoin){
                $where='NOT EXISTS (SELECT 1 FROM `green_legislacao`.`'.$table.'` s WHERE '.$pkJoin.')';
                $incoming=[];
                foreach(linhas($pdo,'SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND REFERENCED_TABLE_SCHEMA=? AND REFERENCED_TABLE_NAME=? AND TABLE_NAME IS NOT NULL AND COLUMN_NAME IS NOT NULL AND REFERENCED_COLUMN_NAME IS NOT NULL',['glegislacao_dbp','glegislacao_dbp',$table]) as $fk) if(is_string($fk['TABLE_NAME'])&&is_string($fk['COLUMN_NAME'])&&is_string($fk['REFERENCED_COLUMN_NAME'])) $incoming[]='NOT EXISTS (SELECT 1 FROM `glegislacao_dbp`.'.q($fk['TABLE_NAME']).' f WHERE f.'.q($fk['COLUMN_NAME']).'=d.'.q($fk['REFERENCED_COLUMN_NAME']).')';
                if($incoming) $where.=' AND '.implode(' AND ',$incoming);
                $candidatos=(int)valor($pdo,'SELECT COUNT(*) FROM `glegislacao_dbp`.`'.$table.'` d WHERE '.$where);
                $possiveis=(int)valor($pdo,'SELECT COUNT(*) FROM `glegislacao_dbp`.`'.$table.'` d WHERE NOT EXISTS (SELECT 1 FROM `green_legislacao`.`'.$table.'` s WHERE '.$pkJoin.')');
                if($aplicar&&$candidatos){$json='JSON_OBJECT('.implode(',',array_map(static fn($field)=>$pdo->quote($field).',d.'.q($field),$fields)).')';$key='JSON_OBJECT('.implode(',',array_map(static fn($field)=>$pdo->quote($field).',d.'.q($field),$pk)).')';$pdo->exec('INSERT INTO `glegislacao_dbp`.legislacao_sinc_exclusoes (tabela,chave,dados) SELECT '.$pdo->quote($table).','.$key.','.$json.' FROM `glegislacao_dbp`.`'.$table.'` d WHERE '.$where);$plan['excluidos']=(int)$pdo->exec('DELETE FROM `glegislacao_dbp`.`'.$table.'` d WHERE '.$where);}elseif(!$aplicar){$plan['excluidos']=$candidatos;}
                $plan['protegidos']=$possiveis-$plan['excluidos'];
            }
            $resultado['tabelas'][$table]=$plan;$resultado['totais']['processados']+=$plan['origem'];$resultado['totais']['afetados']+=$plan['afetados'];$resultado['totais']['excluidos']+=$plan['excluidos'];
        }
        if ($aplicar) $pdo->commit(); else $pdo->rollBack(); return $resultado;
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$resultado['erro']=$e->getMessage();return $resultado;} finally { valor($pdo, 'SELECT RELEASE_LOCK(?)', [$lock]); }
}

/**
 * Tabela cuja chave primária no greenportal não existe no legado (ex.: código autoincremento do greenportal em
 * legislacaoxpalavraschave). O INSERT ... ON DUPLICATE KEY nunca encontrava a linha e gravava tudo de novo a cada
 * execução (duplicava). Aqui: inclui só as linhas do legado que ainda não existem (comparando todas as colunas comuns)
 * e remove as cópias a mais que o erro antigo deixou (fica a de menor código; quantidade igual à do legado), com backup.
 * $expr: coluna do greenportal => expressão SQL do legado (alias s).
 */
/**
 * Tabela SEM chave primária no greenportal (vínculos legislação × aspecto/atividade/poder/documento/licença, assunto, pergunta).
 * Deixa o conteúdo igual ao do legado comparando todas as colunas em comum e contando repetições (o legado prevalece):
 *  - linhas do greenportal a mais (que não existem no legado, ou repetidas além da quantidade do legado) são excluídas, com cópia
 *    na tabela de backup ($backup, só no --aplicar);
 *  - linhas do legado que faltam (ou faltam repetições) são incluídas.
 * Na simulação tudo roda na transação desfeita no fim.
 * Se o greenportal tem índice único na tabela (ex.: legislacaoxatividade uq_lxat_legislacao_atividade_unidade), o legado é
 * comparado sem as repetições (cada conteúdo uma vez) e a inclusão é INSERT IGNORE: a repetição do legado não entra e é
 * contada em ignorados_duplicidade (como nas tabelas fo_*).
 * $expr: coluna do greenportal => expressão SQL do legado (alias s).
 */
function sincronizarSemChave(PDO $pdo, string $origem, string $destino, string $table, array $expr, bool $aplicar, string $backup): array
{
    $T = q($destino) . '.' . q($table); $S = q($origem) . '.' . q($table);
    $cols = array_keys($expr);
    $hD = 'SHA1(JSON_ARRAY(' . implode(',', array_map(static fn($c) => 'CAST(x.' . q($c) . ' AS CHAR)', $cols)) . '))';
    $hS = 'SHA1(JSON_ARRAY(' . implode(',', array_map(static fn($e) => "CAST($e AS CHAR)", array_values($expr))) . '))';
    $plan = ['modo' => 'sem chave primária (compara todas as colunas)', 'origem' => (int)valor($pdo, "SELECT COUNT(*) FROM $S"), 'afetados' => 0, 'excluidos' => 0, 'protegidos' => 0];
    $unico = temIndiceUnico($pdo, $destino, $table);
    $nO = $unico ? 'LEAST(COUNT(*), 1)' : 'COUNT(*)'; // com índice único, cada conteúdo do legado vale uma vez
    // 1. a mais no greenportal: um representante de cada conteúdo (todas as colunas iguais) e quantas cópias sobram
    $rep = implode(',', array_map(static fn($c, $i) => 'MIN(x.' . q($c) . ') AS c' . $i, $cols, array_keys($cols)));
    $sobra = linhas($pdo, "SELECT g.*, g.n - COALESCE(o.n, 0) AS excesso FROM (SELECT $hD AS h, COUNT(*) AS n, $rep FROM $T x GROUP BY 1) g"
        . " LEFT JOIN (SELECT $hS AS h, $nO AS n FROM $S s GROUP BY 1) o ON o.h = g.h WHERE g.n > COALESCE(o.n, 0)");
    $cond = implode(' AND ', array_map(static fn($c) => q($c) . ' <=> ?', $cols));
    $del = $pdo->prepare("DELETE FROM $T WHERE $cond LIMIT ?");
    $bkp = $aplicar ? $pdo->prepare('INSERT INTO ' . q($destino) . '.' . q($backup) . ' (tabela, chave, dados) VALUES (?, ?, ?)') : null;
    foreach ($sobra as $l) {
        $valores = []; $dados = [];
        foreach ($cols as $i => $c) { $valores[] = $l['c' . $i]; $dados[$c] = $l['c' . $i]; }
        $n = (int)$l['excesso'];
        if ($bkp) $bkp->execute([$table . ' (sem chave)', json_encode(['repeticoes_excluidas' => $n], JSON_UNESCAPED_UNICODE), json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
        $p = 1; foreach ($valores as $v) $del->bindValue($p++, $v, $v === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $del->bindValue($p, $n, PDO::PARAM_INT);
        $del->execute();
        $plan['excluidos'] += $del->rowCount();
    }
    // 2. faltam no greenportal: cada conteúdo do legado na quantidade do legado
    $alias = implode(',', array_map(static fn($c, $e) => "$e AS " . q($c), $cols, array_values($expr)));
    $lista = implode(',', array_map('q', $cols));
    $plan['afetados'] = (int)$pdo->exec('INSERT ' . ($unico ? 'IGNORE ' : '') . "INTO $T ($lista) SELECT " . implode(',', array_map(static fn($c) => 'z.' . q($c), $cols))
        . " FROM (SELECT $alias, $hS AS h, ROW_NUMBER() OVER (PARTITION BY $hS ORDER BY $hS) AS rn FROM $S s) z"
        . " LEFT JOIN (SELECT $hD AS h, COUNT(*) AS n FROM $T x GROUP BY 1) d ON d.h = z.h WHERE z.rn > COALESCE(d.n, 0)" . ($unico ? ' AND z.rn = 1' : ''));
    if ($unico) $plan['ignorados_duplicidade'] = max(0, $plan['origem'] - (int)valor($pdo, "SELECT COUNT(*) FROM $T"));
    return $plan;
}

/** A tabela do greenportal tem índice único (fora a chave primária)? */
function temIndiceUnico(PDO $pdo, string $db, string $table): bool
{
    return (int)valor($pdo, "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY'", [$db, $table]) > 0;
}

function sincronizarSemChaveComum(PDO $pdo, string $origem, string $destino, string $table, array $expr, array $pk, bool $aplicar, string $backup): array
{
    $T = q($destino) . '.' . q($table); $TMP = q($destino) . '.tmp_sinc_dup'; $S = q($origem) . '.' . q($table);
    $hD = 'SHA1(JSON_ARRAY(' . implode(',', array_map(static fn($c) => 'CAST(x.' . q($c) . ' AS CHAR)', array_keys($expr))) . '))';
    $hS = 'SHA1(JSON_ARRAY(' . implode(',', array_map(static fn($e) => "CAST($e AS CHAR)", array_values($expr))) . '))';
    $plan = ['modo' => 'sem chave comum (compara todas as colunas)', 'origem' => (int)valor($pdo, "SELECT COUNT(*) FROM $S"), 'afetados' => 0, 'excluidos' => 0, 'protegidos' => 0, 'duplicados_removidos' => 0];
    // cópias a mais no greenportal (linha igual à do legado repetida além da quantidade do legado)
    $pkSel = implode(',', array_map(static fn($c) => 'x.' . q($c), $pk));
    $dupSql = "SELECT " . implode(',', array_map(static fn($c) => 'z.' . q($c), $pk)) . " FROM (SELECT $pkSel, $hD AS h, ROW_NUMBER() OVER (PARTITION BY $hD ORDER BY $pkSel) AS rn FROM $T x) z"
        . " JOIN (SELECT $hS AS h, COUNT(*) AS qtd FROM $S s GROUP BY h) g ON g.h = z.h WHERE z.rn > g.qtd";
    $pdo->exec("DROP TEMPORARY TABLE IF EXISTS $TMP");
    $pdo->exec("CREATE TEMPORARY TABLE $TMP AS $dupSql");
    $plan['duplicados_removidos'] = (int)valor($pdo, "SELECT COUNT(*) FROM $TMP");
    if ($plan['duplicados_removidos'] > 0) {
        $on = implode(' AND ', array_map(static fn($c) => 'd.' . q($c) . ' = t.' . q($c), $pk));
        $json = 'JSON_OBJECT(' . implode(',', array_map(static fn($c) => $pdo->quote($c) . ',d.' . q($c), array_merge($pk, array_keys($expr)))) . ')';
        $key = 'JSON_OBJECT(' . implode(',', array_map(static fn($c) => $pdo->quote($c) . ',d.' . q($c), $pk)) . ')';
        if ($aplicar) $pdo->exec("INSERT INTO " . q($destino) . '.' . q($backup) . " (tabela, chave, dados) SELECT " . $pdo->quote($table . ' (duplicado)') . ", $key, $json FROM $T d JOIN $TMP t ON $on");
        // forma de uma tabela só (DELETE alias FROM ... exige banco padrão na conexão)
        $lista = implode(',', array_map('q', $pk));
        $pdo->exec("DELETE FROM $T WHERE ($lista) IN (SELECT $lista FROM $TMP)");
    }
    $pdo->exec("DROP TEMPORARY TABLE IF EXISTS $TMP");
    // linhas do legado que ainda não estão no greenportal
    $cols = implode(',', array_map('q', array_keys($expr)));
    $sel = implode(',', array_values($expr));
    $plan['afetados'] = (int)$pdo->exec("INSERT INTO $T ($cols) SELECT $sel FROM $S s LEFT JOIN (SELECT DISTINCT $hD AS h FROM $T x) e ON e.h = $hS WHERE e.h IS NULL");
    return $plan;
}

function sincronizarLegalTenant(PDO $pdo, string $origem, string $destino, bool $aplicar): array
{
    $resultado=['origem'=>$origem,'destino'=>$destino,'tabelas'=>[],'totais'=>['processados'=>0,'afetados'=>0,'excluidos'=>0,'protegidos'=>0]];
    $lock = 'sinc_legal_' . $destino;
    if ((int)valor($pdo, 'SELECT GET_LOCK(?, 5)', [$lock]) !== 1) return $resultado + ['erro'=>'outra sincronizacao legal esta em execucao para este banco'];
    $tabelas=SincronismoLegado::TABELAS_LEGAL; // lista em classes/SincronismoLegado.php
    if(!existeBanco($pdo,$origem)||!existeBanco($pdo,$destino))return $resultado+['erro'=>'banco legal de origem ou destino não existe'];
    if($aplicar) prepararBackupExclusoes($pdo,$destino,'legal'); // antes da transação (CREATE TABLE faz commit implícito)
    $pdo->beginTransaction();
    try{
        foreach($tabelas as $table){
            $sourceColumns=colunas($pdo,$origem,$table);$targetColumns=colunas($pdo,$destino,$table);if(!$sourceColumns||!$targetColumns)continue;$common=array_intersect_key($targetColumns,$sourceColumns);if(!$common)continue;
            $pk=array_column(linhas($pdo,'SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND CONSTRAINT_NAME=\'PRIMARY\' ORDER BY ORDINAL_POSITION',[$destino,$table]),'COLUMN_NAME');
            // Sem chave primária no greenportal (ex.: legislacaoxaspecto, legislacaoxpoder): antes era pulada sem aviso (validação de 19/10/2026 na Atlas).
            if(!$pk){$expr=[];foreach($common as $lc=>$c)$expr[$c]=$lc==='codempresa'?'(SELECT codempresa FROM guniversal_dbp.empresa WHERE banco_dados='.$pdo->quote($destino).' LIMIT 1)':'s.'.q($sourceColumns[$lc]);$plan=sincronizarSemChave($pdo,$origem,$destino,$table,$expr,$aplicar,'legal_sinc_exclusoes');$resultado['tabelas'][$table]=$plan;$resultado['totais']['processados']+=$plan['origem'];$resultado['totais']['afetados']+=$plan['afetados'];$resultado['totais']['excluidos']+=$plan['excluidos'];continue;}
            if(array_diff(array_map('strtolower',$pk),array_keys($common))){$expr=[];foreach($common as $lc=>$c)$expr[$c]=$lc==='codempresa'?'(SELECT codempresa FROM guniversal_dbp.empresa WHERE banco_dados='.$pdo->quote($destino).' LIMIT 1)':'s.'.q($sourceColumns[$lc]);$plan=sincronizarSemChaveComum($pdo,$origem,$destino,$table,$expr,$pk,$aplicar,'legal_sinc_exclusoes');$resultado['tabelas'][$table]=$plan;$resultado['totais']['processados']+=$plan['origem'];$resultado['totais']['afetados']+=$plan['afetados'];$resultado['totais']['excluidos']+=$plan['duplicados_removidos'];continue;}
            $targetFields=array_values($common);$sourceFields=array_map(static fn($targetField)=>$sourceColumns[strtolower($targetField)],$targetFields);$targetList=implode(',',array_map('q',$targetFields));$selectParts=[];foreach($targetFields as $index=>$targetField){$selectParts[]=strtolower($targetField)==='codempresa'?'(SELECT codempresa FROM guniversal_dbp.empresa WHERE banco_dados='.$pdo->quote($destino).' LIMIT 1) AS '.q($sourceFields[$index]):'s.'.q($sourceFields[$index]);}$selectList=implode(',',$selectParts);$updates=array_values(array_diff($targetFields,$pk));$updateSql=$updates?implode(',',array_map(static fn($field)=>q($field).'=VALUES('.q($field).')',$updates)):q($pk[0]).'=VALUES('.q($pk[0]).')';
            $sourceCount=(int)valor($pdo,'SELECT COUNT(*) FROM '.q($origem).'.'.q($table));$affected=(int)$pdo->exec('INSERT INTO '.q($destino).'.'.q($table).' ('.$targetList.') SELECT '.$selectList.' FROM '.q($origem).'.'.q($table).' s ON DUPLICATE KEY UPDATE '.$updateSql);
            $deleted=0;$protected=0;
            $pkComparable=array_values(array_filter($pk,static fn($field)=>isset($sourceColumns[strtolower((string)$field)])));
            if($sourceCount>0&&$updates&&count($pkComparable)===count($pk)){$join=implode(' AND ',array_map(static fn($field)=>'d.'.q($field).' <=> s.'.q($sourceColumns[strtolower($field)]),$pkComparable));$incoming=[];foreach(linhas($pdo,'SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND REFERENCED_TABLE_SCHEMA=? AND REFERENCED_TABLE_NAME=? AND TABLE_NAME IS NOT NULL AND COLUMN_NAME IS NOT NULL AND REFERENCED_COLUMN_NAME IS NOT NULL',[$destino,$destino,$table]) as $fk)if(is_string($fk['TABLE_NAME'])&&is_string($fk['COLUMN_NAME'])&&is_string($fk['REFERENCED_COLUMN_NAME']))$incoming[]='NOT EXISTS (SELECT 1 FROM '.q($destino).'.'.q($fk['TABLE_NAME']).' f WHERE f.'.q($fk['COLUMN_NAME']).'=d.'.q($fk['REFERENCED_COLUMN_NAME']).')';$notExists='NOT EXISTS (SELECT 1 FROM '.q($origem).'.'.q($table).' s WHERE '.$join.')';$where=$notExists.($incoming?' AND '.implode(' AND ',$incoming):'');$candidates=(int)valor($pdo,'SELECT COUNT(*) FROM '.q($destino).'.'.q($table).' d WHERE '.$where);if($aplicar&&$candidates){$json='JSON_OBJECT('.implode(',',array_map(static fn($field)=>$pdo->quote($field).',d.'.q($field),$targetFields)).')';$key='JSON_OBJECT('.implode(',',array_map(static fn($field)=>$pdo->quote($field).',d.'.q($field),$pk)).')';$pdo->exec('INSERT INTO '.q($destino).'.legal_sinc_exclusoes (tabela,chave,dados) SELECT '.$pdo->quote($table).','.$key.','.$json.' FROM '.q($destino).'.'.q($table).' d WHERE '.$where);$deleted=(int)$pdo->exec('DELETE FROM '.q($destino).'.'.q($table).' d WHERE '.$where);}elseif(!$aplicar){$deleted=$candidates;/* simulação: mostra o que seria excluído (antes aparecia como protegido) */}$protected=$candidates-$deleted;}
            $resultado['tabelas'][$table]=['origem'=>$sourceCount,'afetados'=>$affected,'excluidos'=>$deleted,'protegidos'=>$protected];$resultado['totais']['processados']+=$sourceCount;$resultado['totais']['afetados']+=$affected;$resultado['totais']['excluidos']+=$deleted;$resultado['totais']['protegidos']+=$protected;
        }
        if($aplicar)$pdo->commit();else $pdo->rollBack();return $resultado;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$resultado['erro']=$e->getMessage();return $resultado;} finally { valor($pdo, 'SELECT RELEASE_LOCK(?)', [$lock]); }
}

/**
 * Migração 316: usuariosxunidades do legado (<legado>_seguranca) -> greenportal, por par usuário × unidade (o legado prevalece).
 * Exclusões guardadas em fo_sinc_exclusoes. Sem a tabela num dos lados, não faz nada.
 */
function sincronizarUsuariosUnidades(PDO $pdo, string $origem, string $destino, bool $aplicar): array
{
    $seg = $origem . '_seguranca';
    $r = ['origem' => $seg . '.usuariosxunidades', 'destino' => $destino . '.usuariosxunidades', 'incluidos' => 0, 'excluidos' => 0];
    $existe = static fn(string $db): bool => (int)valor($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'usuariosxunidades' AND TABLE_TYPE = 'BASE TABLE'", [$db]) > 0;
    if (!$existe($seg) || !$existe($destino)) return $r + ['status' => 'IGNORADA (tabela não existe num dos lados; aplicar a migração 316)'];
    $col = static function (string $db, string $c) use ($pdo): string { $n = valor($pdo, "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'usuariosxunidades' AND LOWER(COLUMN_NAME) = ?", [$db, $c]); if (!$n) throw new RuntimeException("$db.usuariosxunidades sem $c"); return (string)$n; };
    try {
        [$su, $sun, $du, $dun] = [$col($seg, 'codusuario'), $col($seg, 'codunidade'), $col($destino, 'codusuario'), $col($destino, 'codunidade')];
        $S = q($seg) . '.usuariosxunidades'; $D = q($destino) . '.usuariosxunidades';
        $faltam = "FROM (SELECT DISTINCT s." . q($su) . " AS cu, s." . q($sun) . " AS cun FROM $S s WHERE s." . q($su) . " > 0 AND s." . q($sun) . " > 0) o
            WHERE NOT EXISTS (SELECT 1 FROM $D d WHERE d." . q($du) . " = o.cu AND d." . q($dun) . " = o.cun)";
        $sobram = "FROM $D d WHERE NOT EXISTS (SELECT 1 FROM $S s WHERE s." . q($su) . " = d." . q($du) . " AND s." . q($sun) . " = d." . q($dun) . ")";
        if (!$aplicar) return ['incluidos' => (int)valor($pdo, "SELECT COUNT(*) $faltam"), 'excluidos' => (int)valor($pdo, "SELECT COUNT(*) $sobram")] + $r;
        $pdo->beginTransaction();
        $r['incluidos'] = (int)$pdo->exec("INSERT INTO $D (" . q($du) . ', ' . q($dun) . ") SELECT o.cu, o.cun $faltam");
        $pdo->exec("INSERT INTO " . q($destino) . ".fo_sinc_exclusoes (tabela, chave, dados) SELECT 'usuariosxunidades', JSON_OBJECT('codusuario', d." . q($du) . ", 'codunidade', d." . q($dun) . "), JSON_OBJECT('codusuario', d." . q($du) . ", 'codunidade', d." . q($dun) . ") $sobram");
        // DELETE de uma tabela só (a conexão do sincronismo não tem banco padrão; DELETE com apelido falharia).
        $r['excluidos'] = (int)$pdo->exec("DELETE FROM $D WHERE NOT EXISTS (SELECT 1 FROM $S s WHERE s." . q($su) . " = $D." . q($du) . " AND s." . q($sun) . " = $D." . q($dun) . ")");
        $pdo->commit();
        return $r;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); return $r + ['erro' => $e->getMessage()]; }
}

/** @return array<string,string> nome em minúsculas => nome real (sem colunas geradas) */
function colunas(PDO $pdo, string $db, string $t): array
{
    $out = [];
    foreach (linhas($pdo, "SELECT COLUMN_NAME, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION", [$db, $t]) as $c) {
        if (stripos((string)$c['EXTRA'], 'GENERATED') === false) $out[strtolower($c['COLUMN_NAME'])] = $c['COLUMN_NAME'];
    }
    return $out;
}

function sincronizar(PDO $pdo, string $origem, string $destino, bool $aplicar): array
{
    $resultado = ['origem' => $origem, 'destino' => $destino, 'tabelas' => [], 'totais' => ['incluidos' => 0, 'alterados' => 0, 'excluidos' => 0, 'ignorados_dependencia' => 0]];
    if (!existeBanco($pdo, $origem) || !existeBanco($pdo, $destino)) return $resultado + ['erro' => 'banco de origem ou destino não existe'];
    if ((int)valor($pdo, 'SELECT GET_LOCK(?, 5)', ['sinc_fo_' . $destino]) !== 1) return $resultado + ['erro' => 'outro sincronismo está em execução para este banco'];

    try {
        $tabelas = array_column(linhas($pdo, "SELECT d.TABLE_NAME FROM information_schema.TABLES d JOIN information_schema.TABLES s ON s.TABLE_SCHEMA = ? AND s.TABLE_NAME = d.TABLE_NAME AND s.TABLE_TYPE = 'BASE TABLE'
            WHERE d.TABLE_SCHEMA = ? AND d.TABLE_TYPE = 'BASE TABLE' AND " . SincronismoLegado::condicaoPrefixos('d.TABLE_NAME') . " AND d.TABLE_NAME NOT LIKE '%\\_bkp%' ORDER BY d.TABLE_NAME", [$origem, $destino]), 'TABLE_NAME');
        $tabelas = array_values(array_filter($tabelas, static fn($t) => SincronismoLegado::sincronizada((string)$t, $destino)));
        if (in_array($destino, SINC_MESTRE_NOVO_BANCOS, true)) $tabelas = array_values(array_diff($tabelas, SINC_MESTRE_NOVO));

        // Chaves estrangeiras do destino entre tabelas fo_* (para ordem e proteções).
        $fks = [];
        foreach (linhas($pdo, "SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL", [$destino, $destino]) as $fk) $fks[] = $fk;
        // Ordem: pais antes dos filhos (inclusão/alteração); filhos antes dos pais (exclusão).
        $ordem = []; $visitado = [];
        $visitar = function (string $t) use (&$visitar, &$ordem, &$visitado, $fks, $tabelas): void {
            if (isset($visitado[$t])) return; $visitado[$t] = true;
            foreach ($fks as $fk) if ($fk['TABLE_NAME'] === $t && $fk['REFERENCED_TABLE_NAME'] !== $t && in_array($fk['REFERENCED_TABLE_NAME'], $tabelas, true)) $visitar($fk['REFERENCED_TABLE_NAME']);
            $ordem[] = $t;
        };
        foreach ($tabelas as $t) $visitar($t);

        if ($aplicar) {
            // Valores anteriores de cada registro alterado pelo sincronismo (para conferência e reversão).
            $pdo->exec('CREATE TABLE IF NOT EXISTS ' . q($destino) . ".fo_sinc_alteracoes (
                codalteracao BIGINT AUTO_INCREMENT PRIMARY KEY, tabela VARCHAR(128) NOT NULL, chave JSON NULL, antes JSON NOT NULL, depois JSON NOT NULL,
                alterado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_sinc_alteracoes_tabela (tabela, alterado_em)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec('CREATE TABLE IF NOT EXISTS ' . q($destino) . ".fo_sinc_exclusoes (
                codexclusao BIGINT AUTO_INCREMENT PRIMARY KEY, tabela VARCHAR(128) NOT NULL, chave JSON NULL, dados JSON NOT NULL,
                excluido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_sinc_exclusoes_tabela (tabela, excluido_em)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        $temProtecoes = SINC_RESPEITAR_PROTECOES && (int)valor($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'fo_sinc_protecoes'", [$destino]) > 0;
        $planos = [];
        foreach ($ordem as $t) {
            $cO = colunas($pdo, $origem, $t); $cD = colunas($pdo, $destino, $t);
            $comuns = array_intersect_key($cD, $cO);
            if (!$comuns) continue;
            $pk = array_column(linhas($pdo, "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION", [$destino, $t]), 'COLUMN_NAME');
            $pkOk = $pk && !array_diff(array_map('strtolower', $pk), array_keys($comuns));
            $chave = $pkOk ? array_map('strtolower', $pk) : array_keys($comuns);
            $T = q($destino) . '.' . q($t); $S = q($origem) . '.' . q($t);
            $igual = static fn(array $cols, string $a, string $b) => implode(' AND ', array_map(static fn($c) => "$a." . q($comuns[$c]) . " <=> $b." . q($comuns[$c]), $cols));
            // Condições de dependência: referências de saída (inclusão/alteração) e de entrada (exclusão).
            $saida = []; $entrada = [];
            foreach ($fks as $fk) {
                if ($fk['TABLE_NAME'] === $t && isset($comuns[strtolower($fk['COLUMN_NAME'])])) {
                    $c = 's.' . q($comuns[strtolower($fk['COLUMN_NAME'])]);
                    $saida[] = "($c IS NULL OR EXISTS (SELECT 1 FROM " . q($destino) . '.' . q($fk['REFERENCED_TABLE_NAME']) . ' r WHERE r.' . q($fk['REFERENCED_COLUMN_NAME']) . " = $c))";
                }
                if ($fk['REFERENCED_TABLE_NAME'] === $t) {
                    $entrada[] = 'NOT EXISTS (SELECT 1 FROM ' . q($destino) . '.' . q($fk['TABLE_NAME']) . ' f WHERE f.' . q($fk['COLUMN_NAME']) . ' = d.' . q($fk['REFERENCED_COLUMN_NAME']) . ')';
                }
            }
            $somenteInclusao = in_array($t, SINC_SOMENTE_INCLUSAO, true);
            if ($somenteInclusao && isset($comuns['codfornecedor'])) {
                $saida[] = '(s.' . q($comuns['codfornecedor']) . ' IS NULL OR EXISTS (SELECT 1 FROM ' . q($destino) . ".fo_fornecedores p WHERE p.codfornecedor = s." . q($comuns['codfornecedor']) . " AND COALESCE(p.razaosocial, '') NOT LIKE '%- migra%o 217'))";
            }
            $protecao = isset(SINC_PROVISORIOS[$t], $comuns[SINC_PROVISORIOS[$t]]) ? ["COALESCE(d." . q($comuns[SINC_PROVISORIOS[$t]]) . ", '') NOT LIKE '%- migra%o 217'"] : [];
            // Migração 322: faixa de códigos do greenportal (SincronismoLegado::FAIXAS_NOVO) — o que está nela não é apagado nem
            // alterado, e linhas do legado que chegarem à faixa não são trazidas (conflito avisado no relatório).
            $faixa = SincronismoLegado::faixa($t);
            if ($faixa && $pkOk && isset($comuns[$faixa[0]])) {
                $protecao[] = 'd.' . q($comuns[$faixa[0]]) . ' < ' . (int)$faixa[1];
                $saida[] = 's.' . q($comuns[$faixa[0]]) . ' < ' . (int)$faixa[1];
            } else $faixa = null;
            $planos[$t] = compact('comuns', 'pkOk', 'chave', 'T', 'S', 'igual', 'saida', 'entrada', 'somenteInclusao', 'protecao', 'faixa');
        }

        $pdo->beginTransaction();
        // 1) Exclusões: filhos antes dos pais.
        foreach (array_reverse(array_keys($planos)) as $t) {
            $p = $planos[$t]; $r = &$resultado['tabelas'][$t];
            $r = ['modo' => $p['somenteInclusao'] ? 'somente inclusão' : ($p['pkOk'] ? 'chave primária' : 'comparação de todas as colunas'), 'incluidos' => 0, 'alterados' => 0, 'excluidos' => 0, 'ignorados_dependencia' => 0];
            if ($p['faixa']) {
                $colFaixa = q($p['comuns'][$p['faixa'][0]]);
                $r['faixa_greenportal'] = ['a_partir_de' => (int)$p['faixa'][1], 'linhas_mantidas' => (int)valor($pdo, 'SELECT COUNT(*) FROM ' . $p['T'] . " WHERE $colFaixa >= " . (int)$p['faixa'][1])];
                $conflito = (int)valor($pdo, 'SELECT COUNT(*) FROM ' . $p['S'] . " WHERE $colFaixa >= " . (int)$p['faixa'][1]);
                if ($conflito) $r['conflito_faixa_legado'] = $conflito . ' linha(s) do legado já usam códigos da faixa do greenportal e não foram trazidas: aumentar a faixa (SincronismoLegado::FAIXAS_NOVO e fn_nc_faixa)';
            }
            if ($p['somenteInclusao']) { unset($r); continue; }
            $faltaNaOrigem = 'NOT EXISTS (SELECT 1 FROM ' . $p['S'] . ' s WHERE ' . ($p['igual'])($p['chave'], 's', 'd') . ')';
            $cond = implode(' AND ', array_merge([$faltaNaOrigem], $p['protecao'], $p['entrada']));
            $candidatos = (int)valor($pdo, 'SELECT COUNT(*) FROM ' . $p['T'] . " d WHERE $faltaNaOrigem" . ($p['protecao'] ? ' AND ' . implode(' AND ', $p['protecao']) : ''));
            if ($candidatos > 0) {
                if ($aplicar) {
                    $json = static fn(array $cols) => 'JSON_OBJECT(' . implode(', ', array_map(static fn($c) => "'" . $p['comuns'][$c] . "', d." . q($p['comuns'][$c]), $cols)) . ')';
                    $pdo->prepare('INSERT INTO ' . q($destino) . '.fo_sinc_exclusoes (tabela, chave, dados) SELECT ?, ' . ($p['pkOk'] ? $json($p['chave']) : 'NULL') . ', ' . $json(array_keys($p['comuns'])) . ' FROM ' . $p['T'] . " d WHERE $cond")->execute([$t]);
                }
                $r['excluidos'] = $pdo->exec('DELETE FROM ' . $p['T'] . " AS d WHERE $cond");
                $r['ignorados_dependencia'] += $candidatos - $r['excluidos'];
            }
            unset($r);
        }
        // 2) Inclusões e alterações: pais antes dos filhos.
        foreach ($planos as $t => $p) {
            $r = &$resultado['tabelas'][$t];
            $faltaNoDestino = 'NOT EXISTS (SELECT 1 FROM ' . $p['T'] . ' d WHERE ' . ($p['igual'])($p['chave'], 'd', 's') . ')';
            $dep = $p['saida'] ? ' AND ' . implode(' AND ', $p['saida']) : '';
            $listaD = implode(', ', array_map('q', $p['comuns']));
            $listaS = implode(', ', array_map(static fn($c) => 's.' . q($c), $p['comuns']));
            $candidatos = (int)valor($pdo, 'SELECT COUNT(*) FROM ' . $p['S'] . " s WHERE $faltaNoDestino" . ($p['somenteInclusao'] ? $dep : ''));
            if ($candidatos > 0) {
                // IGNORE só quando a tabela tem índice único além da chave (ex.: uk de documento repetido): a linha repetida fica de fora
                $unicoExtra = (int)valor($pdo, "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY'", [$destino, $t]) > 0;
                $r['incluidos'] = $pdo->exec('INSERT ' . ($unicoExtra ? 'IGNORE ' : '') . 'INTO ' . $p['T'] . " ($listaD) SELECT $listaS FROM " . $p['S'] . " s WHERE $faltaNoDestino$dep");
                if ($unicoExtra) { $semDep = (int)valor($pdo, 'SELECT COUNT(*) FROM ' . $p['S'] . " s WHERE $faltaNoDestino$dep"); $r['ignorados_duplicidade'] = $semDep; $candidatos -= $semDep; }
                if (!$p['somenteInclusao']) $r['ignorados_dependencia'] += $candidatos - $r['incluidos'];
            }
            if ($p['pkOk'] && !$p['somenteInclusao']) {
                $outras = array_diff(array_keys($p['comuns']), $p['chave']);
                if ($outras) {
                    // Campos protegidos (fo_sinc_protecoes: confirmados no sistema novo, ex. análise de documentos por IA)
                    // não são sobrescritos pelo legado.
                    $protegidas = [];
                    if ($temProtecoes) {
                        $protegidas = array_flip(array_column(linhas($pdo, 'SELECT DISTINCT LOWER(coluna) c FROM ' . q($destino) . '.fo_sinc_protecoes WHERE tabela = ?', [$t]), 'c'));
                    }
                    $chaveTxt = 'CONCAT_WS(\'|\', ' . implode(', ', array_map(static fn($c) => 'd.' . q($p['comuns'][$c]), $p['chave'])) . ')';
                    $protegido = static fn(string $c) => 'EXISTS (SELECT 1 FROM ' . q($destino) . '.fo_sinc_protecoes pr WHERE pr.tabela = ' . $pdo->quote($t) . ' AND pr.coluna = ' . $pdo->quote($c) . " AND pr.chave = $chaveTxt)";
                    $difColuna = static fn(string $c) => '(NOT (d.' . q($p['comuns'][$c]) . ' <=> s.' . q($p['comuns'][$c]) . ')' . (isset($protegidas[$c]) ? ' AND NOT ' . $protegido($c) : '') . ')';
                    $diferente = implode(' OR ', array_map($difColuna, $outras));
                    $set = implode(', ', array_map(static fn($c) => 'd.' . q($p['comuns'][$c]) . ' = ' . (isset($protegidas[$c]) ? 'IF(' . $protegido($c) . ', d.' . q($p['comuns'][$c]) . ', s.' . q($p['comuns'][$c]) . ')' : 's.' . q($p['comuns'][$c])), $outras));
                    if ($protegidas) $r['campos_protegidos'] = array_values(array_intersect_key(array_combine($outras, array_map(static fn($c) => $p['comuns'][$c], $outras)), $protegidas));
                    $candidatos = (int)valor($pdo, 'SELECT COUNT(*) FROM ' . $p['T'] . ' d JOIN ' . $p['S'] . ' s ON ' . ($p['igual'])($p['chave'], 'd', 's') . " WHERE ($diferente)");
                    if ($candidatos > 0) {
                        // Quais colunas mudam (contagem por coluna), para o relatório.
                        $porColuna = [];
                        foreach ($outras as $c) {
                            $n = (int)valor($pdo, 'SELECT COUNT(*) FROM ' . $p['T'] . ' d JOIN ' . $p['S'] . ' s ON ' . ($p['igual'])($p['chave'], 'd', 's') . ' WHERE ' . $difColuna($c));
                            if ($n > 0) $porColuna[$p['comuns'][$c]] = $n;
                        }
                        $r['colunas_alteradas'] = $porColuna;
                    }
                    if ($candidatos > 0) {
                        if ($aplicar) {
                            $obj = static fn(string $a, array $cols) => 'JSON_OBJECT(' . implode(', ', array_map(static fn($c) => "'" . $p['comuns'][$c] . "', $a." . q($p['comuns'][$c]), $cols)) . ')';
                            $pdo->prepare('INSERT INTO ' . q($destino) . '.fo_sinc_alteracoes (tabela, chave, antes, depois) SELECT ?, ' . $obj('d', $p['chave']) . ', ' . $obj('d', $outras) . ', ' . $obj('s', $outras) . ' FROM ' . $p['T'] . ' d JOIN ' . $p['S'] . ' s ON ' . ($p['igual'])($p['chave'], 'd', 's') . " WHERE ($diferente)$dep")->execute([$t]);
                        }
                        $r['alterados'] = $pdo->exec('UPDATE ' . $p['T'] . ' d JOIN ' . $p['S'] . ' s ON ' . ($p['igual'])($p['chave'], 'd', 's') . " SET $set WHERE ($diferente)$dep");
                        $r['ignorados_dependencia'] += $candidatos - $r['alterados'];
                    }
                }
            }
            foreach (['incluidos', 'alterados', 'excluidos', 'ignorados_dependencia'] as $k) $resultado['totais'][$k] += $r[$k];
            if (!$r['incluidos'] && !$r['alterados'] && !$r['excluidos'] && !$r['ignorados_dependencia'] && empty($r['ignorados_duplicidade']) && empty($r['faixa_greenportal']['linhas_mantidas']) && empty($r['conflito_faixa_legado'])) unset($resultado['tabelas'][$t]);
            unset($r);
        }
        $resultado['tabelas_comparadas'] = count($planos);
        if (!$resultado['tabelas']) $resultado['tabelas'] = new stdClass(); // sem diferenças: objeto vazio no relatório
        if ($aplicar) $pdo->commit(); else $pdo->rollBack();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $resultado['erro'] = $e->getMessage();
    } finally {
        valor($pdo, 'SELECT RELEASE_LOCK(?)', ['sinc_fo_' . $destino]);
    }
    return $resultado;
}

/**
 * Tabelas mapeadas (SincronismoLegado::TABELAS_MAPEADAS): legado com outro nome/colunas -> tabela do greenportal.
 * Inclui, altera e exclui pela 1ª coluna (chave); backup em fo_sinc_alteracoes / fo_sinc_exclusoes. Roda antes do sincronismo
 * das tabelas de mesmo nome (os cadastros chegam antes das ocorrências).
 */
function sincronizarMapeadas(PDO $pdo, string $origem, string $destino, bool $aplicar): array
{
    $res = ['tabelas' => [], 'totais' => ['incluidos' => 0, 'alterados' => 0, 'excluidos' => 0]];
    if ($aplicar) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . q($destino) . ".fo_sinc_alteracoes (codalteracao BIGINT AUTO_INCREMENT PRIMARY KEY, tabela VARCHAR(128) NOT NULL, chave JSON NULL, antes JSON NOT NULL, depois JSON NOT NULL, alterado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_sinc_alteracoes_tabela (tabela, alterado_em)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . q($destino) . ".fo_sinc_exclusoes (codexclusao BIGINT AUTO_INCREMENT PRIMARY KEY, tabela VARCHAR(128) NOT NULL, chave JSON NULL, dados JSON NOT NULL, excluido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_sinc_exclusoes_tabela (tabela, excluido_em)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $pdo->beginTransaction();
    try {
        foreach (SincronismoLegado::TABELAS_MAPEADAS as $tOrig => $m) {
            $tDest = $m['destino'];
            $cO = colunas($pdo, $origem, $tOrig); $cD = colunas($pdo, $destino, $tDest);
            if (!$cO || !$cD) continue;
            $mapa = [];
            foreach ($m['colunas'] as $co => $cd) if (isset($cO[strtolower($co)], $cD[strtolower($cd)])) $mapa[$cO[strtolower($co)]] = $cD[strtolower($cd)];
            if (!$mapa) continue;
            $kO = array_key_first($mapa); $kD = $mapa[$kO];
            $S = q($origem) . '.' . q($tOrig); $T = q($destino) . '.' . q($tDest);
            $outras = array_slice($mapa, 1, null, true);
            $dif = $outras ? implode(' OR ', array_map(static fn($o, $d) => 'NOT (d.' . q($d) . ' <=> s.' . q($o) . ')', array_keys($outras), $outras)) : '0';
            // Migração 327: faixa do greenportal (SincronismoLegado::FAIXAS_NOVO) também nas tabelas mapeadas — o que está nela não
            // é apagado nem alterado, e linhas do legado que chegarem à faixa não são trazidas.
            $fx = SincronismoLegado::faixa($tDest);
            $fxS = ($fx && strtolower($kD) === $fx[0]) ? ' AND s.' . q($kO) . ' < ' . (int)$fx[1] : '';
            $fxD = ($fx && strtolower($kD) === $fx[0]) ? ' AND d.' . q($kD) . ' < ' . (int)$fx[1] : '';
            $fxT = ($fx && strtolower($kD) === $fx[0]) ? " AND $T." . q($kD) . ' < ' . (int)$fx[1] : '';
            $dif = "($dif)$fxD";
            $r = ['origem' => "$tOrig -> $tDest",
                'incluidos' => (int)valor($pdo, "SELECT COUNT(*) FROM $S s WHERE NOT EXISTS (SELECT 1 FROM $T d WHERE d." . q($kD) . ' <=> s.' . q($kO) . ')' . $fxS),
                'alterados' => (int)valor($pdo, "SELECT COUNT(*) FROM $T d JOIN $S s ON d." . q($kD) . ' <=> s.' . q($kO) . " WHERE $dif"),
                'excluidos' => (int)valor($pdo, "SELECT COUNT(*) FROM $T d WHERE NOT EXISTS (SELECT 1 FROM $S s WHERE s." . q($kO) . ' <=> d.' . q($kD) . ')' . $fxD)];
            if ($aplicar && $r['alterados']) {
                $antes = 'JSON_OBJECT(' . implode(', ', array_map(static fn($d) => "'$d', d." . q($d), $outras)) . ')';
                $depois = 'JSON_OBJECT(' . implode(', ', array_map(static fn($o, $d) => "'$d', s." . q($o), array_keys($outras), $outras)) . ')';
                $pdo->prepare('INSERT INTO ' . q($destino) . ".fo_sinc_alteracoes (tabela, chave, antes, depois) SELECT ?, JSON_OBJECT('$kD', d." . q($kD) . "), $antes, $depois FROM $T d JOIN $S s ON d." . q($kD) . ' <=> s.' . q($kO) . " WHERE $dif")->execute([$tDest]);
            }
            if ($r['alterados']) $pdo->exec("UPDATE $T d JOIN $S s ON d." . q($kD) . ' <=> s.' . q($kO) . ' SET ' . implode(', ', array_map(static fn($o, $d) => 'd.' . q($d) . ' = s.' . q($o), array_keys($outras), $outras)) . " WHERE $dif");
            if ($r['incluidos']) $pdo->exec("INSERT INTO $T (" . implode(', ', array_map('q', $mapa)) . ') SELECT ' . implode(', ', array_map(static fn($o) => 's.' . q($o), array_keys($mapa))) . " FROM $S s WHERE NOT EXISTS (SELECT 1 FROM $T d WHERE d." . q($kD) . ' <=> s.' . q($kO) . ')' . $fxS);
            if ($r['excluidos']) {
                $cond = "NOT EXISTS (SELECT 1 FROM $S s WHERE s." . q($kO) . ' <=> d.' . q($kD) . ')' . $fxD;
                if ($aplicar) $pdo->prepare('INSERT INTO ' . q($destino) . ".fo_sinc_exclusoes (tabela, chave, dados) SELECT ?, JSON_OBJECT('$kD', d." . q($kD) . '), JSON_OBJECT(' . implode(', ', array_map(static fn($d) => "'$d', d." . q($d), $mapa)) . ") FROM $T d WHERE $cond")->execute([$tDest]);
                // DELETE de uma tabela só (DELETE alias FROM banco.tabela exige banco padrão na conexão)
                $pdo->exec("DELETE FROM $T WHERE NOT EXISTS (SELECT 1 FROM $S s WHERE s." . q($kO) . " <=> $T." . q($kD) . ')' . $fxT);
            }
            foreach (['incluidos', 'alterados', 'excluidos'] as $k) $res['totais'][$k] += $r[$k];
            if ($r['incluidos'] || $r['alterados'] || $r['excluidos']) $res['tabelas'][$tDest] = $r;
        }
        if ($aplicar) $pdo->commit(); else $pdo->rollBack();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $res['erro'] = $e->getMessage();
    }
    return $res;
}

/**
 * Validação da réplica (depois da gravação, ou sozinha com --validar): para cada tabela sincronizada (fo_*, nc_*, Módulo Legal
 * e legislação central), sem as desconsideradas, compara legado × greenportal: linhas dos dois lados (contagem exata) e, pela
 * chave primária, quantas linhas do legado faltam no greenportal e quantas sobram no greenportal. Para fo_* e nc_* acrescenta o que
 * uma nova simulação ainda mudaria (diferenças de valor que não foram replicadas, ex.: dependência ou duplicidade).
 * Só lê. Tabelas "somente inclusão" (histórico) não contam o que sobra no greenportal (o greenportal grava o próprio histórico).
 */
function validarTabelas(PDO $pdo, string $origem, string $destino, array $tabelas): array
{
    $r = ['tabelas_verificadas' => 0, 'divergentes' => []];
    foreach ($tabelas as $t) {
        if (SincronismoLegado::desconsiderada($t) !== null) continue;
        $cO = colunas($pdo, $origem, $t); $cD = colunas($pdo, $destino, $t);
        if (!$cO) continue;
        if (!$cD) { $r['divergentes'][$t] = ['situacao' => 'tabela não existe no greenportal', 'linhas_legado' => (int)valor($pdo, 'SELECT COUNT(*) FROM ' . q($origem) . '.' . q($t))]; continue; }
        $r['tabelas_verificadas']++;
        $leg = (int)valor($pdo, 'SELECT COUNT(*) FROM ' . q($origem) . '.' . q($t));
        $novo = (int)valor($pdo, 'SELECT COUNT(*) FROM ' . q($destino) . '.' . q($t));
        $pk = array_column(linhas($pdo, "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION", [$destino, $t]), 'COLUMN_NAME');
        $pkOk = $pk && !array_diff(array_map('strtolower', $pk), array_keys($cO));
        $item = ['linhas_legado' => $leg, 'linhas_greenportal' => $novo];
        if ($pkOk) {
            $join = implode(' AND ', array_map(static fn($c) => 'd.' . q($c) . ' <=> s.' . q($cO[strtolower($c)]), $pk));
            $item['faltam_no_greenportal'] = (int)valor($pdo, 'SELECT COUNT(*) FROM ' . q($origem) . '.' . q($t) . ' s WHERE NOT EXISTS (SELECT 1 FROM ' . q($destino) . '.' . q($t) . " d WHERE $join)");
            $faixa = SincronismoLegado::faixa($t);
            $foraFaixa = $faixa && isset($cD[$faixa[0]]) ? ' AND d.' . q($cD[$faixa[0]]) . ' < ' . (int)$faixa[1] : '';
            $item['sobram_no_greenportal'] = in_array($t, SINC_SOMENTE_INCLUSAO, true) ? 0
                : (int)valor($pdo, 'SELECT COUNT(*) FROM ' . q($destino) . '.' . q($t) . ' d WHERE NOT EXISTS (SELECT 1 FROM ' . q($origem) . '.' . q($t) . " s WHERE $join)$foraFaixa");
            if ($foraFaixa !== '') {
                $mantidas = (int)valor($pdo, 'SELECT COUNT(*) FROM ' . q($destino) . '.' . q($t) . ' d WHERE d.' . q($cD[$faixa[0]]) . ' >= ' . (int)$faixa[1]);
                if ($mantidas) $item['faixa_greenportal_mantidas'] = $mantidas;
            }
            if ($item['faltam_no_greenportal'] || $item['sobram_no_greenportal']) $r['divergentes'][$t] = $item;
        } elseif (!in_array($t, SINC_SOMENTE_INCLUSAO, true)) {
            // Sem chave primária comum: compara o conteúdo (todas as colunas em comum, repetições contadas).
            $comum = array_values(array_filter(array_keys(array_intersect_key($cD, $cO)), static fn($c) => !in_array($c, array_map('strtolower', $pk), true) || isset($cO[$c])));
            if ($comum) {
                $hs = static fn(string $a, array $m) => 'SHA1(JSON_ARRAY(' . implode(',', array_map(static fn($c) => "CAST($a." . q($m[$c]) . ' AS CHAR)', $comum)) . '))';
                $c = linhas($pdo, 'SELECT SUM(GREATEST(COALESCE(o.n,0)-COALESCE(d.n,0),0)) AS faltam, SUM(GREATEST(COALESCE(d.n,0)-COALESCE(o.n,0),0)) AS sobram FROM ('
                    . 'SELECT ' . $hs('s', $cO) . ' AS h FROM ' . q($origem) . '.' . q($t) . ' s UNION SELECT ' . $hs('x', $cD) . ' FROM ' . q($destino) . '.' . q($t) . ' x) k'
                    . ' LEFT JOIN (SELECT ' . $hs('s', $cO) . ' AS h, ' . (temIndiceUnico($pdo, $destino, $t) ? 'LEAST(COUNT(*), 1)' : 'COUNT(*)') . ' AS n FROM ' . q($origem) . '.' . q($t) . ' s GROUP BY 1) o ON o.h = k.h'
                    . ' LEFT JOIN (SELECT ' . $hs('x', $cD) . ' AS h, COUNT(*) AS n FROM ' . q($destino) . '.' . q($t) . ' x GROUP BY 1) d ON d.h = k.h')[0] ?? [];
                $item['faltam_no_greenportal'] = (int)($c['faltam'] ?? 0); $item['sobram_no_greenportal'] = (int)($c['sobram'] ?? 0);
                if (temIndiceUnico($pdo, $destino, $t)) $item['repetidos_no_legado_ignorados'] = $leg - (int)valor($pdo, 'SELECT COUNT(DISTINCT ' . $hs('s', $cO) . ') FROM ' . q($origem) . '.' . q($t) . ' s');
                if ($item['faltam_no_greenportal'] || $item['sobram_no_greenportal']) $r['divergentes'][$t] = $item + ['obs' => 'sem chave primária comum: comparado o conteúdo'];
            } elseif ($leg !== $novo) $r['divergentes'][$t] = $item + ['obs' => 'sem chave primária comum: comparada só a contagem'];
        }
    }
    return $r;
}

function validarReplica(PDO $pdo, string $origem, string $destino): array
{
    $sinc = array_values(array_filter(array_column(linhas($pdo, "SELECT d.TABLE_NAME FROM information_schema.TABLES d JOIN information_schema.TABLES s ON s.TABLE_SCHEMA = ? AND s.TABLE_NAME = d.TABLE_NAME AND s.TABLE_TYPE = 'BASE TABLE'
        WHERE d.TABLE_SCHEMA = ? AND d.TABLE_TYPE = 'BASE TABLE' AND " . SincronismoLegado::condicaoPrefixos('d.TABLE_NAME') . " AND d.TABLE_NAME NOT LIKE '%\\_bkp%'", [$origem, $destino]), 'TABLE_NAME'),
        static fn($t) => SincronismoLegado::sincronizada((string)$t, $destino) && !(in_array($destino, SINC_MESTRE_NOVO_BANCOS, true) && in_array($t, SINC_MESTRE_NOVO, true))));
    // Tabelas do legado com o prefixo sincronizado que não existem no greenportal (não são replicadas: decidir ou desconsiderar)
    $soLegado = array_values(array_filter(array_column(linhas($pdo, "SELECT s.TABLE_NAME FROM information_schema.TABLES s WHERE s.TABLE_SCHEMA = ? AND s.TABLE_TYPE = 'BASE TABLE' AND " . SincronismoLegado::condicaoPrefixos('s.TABLE_NAME') . "
        AND NOT EXISTS (SELECT 1 FROM information_schema.TABLES d WHERE d.TABLE_SCHEMA = ? AND d.TABLE_NAME = s.TABLE_NAME)", [$origem, $destino]), 'TABLE_NAME'),
        static fn($t) => SincronismoLegado::desconsiderada((string)$t) === null && !isset(SincronismoLegado::TABELAS_MAPEADAS[strtolower((string)$t)])));
    $r = ['fo_nc' => validarTabelas($pdo, $origem, $destino, $sinc), 'legal' => validarTabelas($pdo, $origem, $destino, SincronismoLegado::TABELAS_LEGAL)];
    $mapeadas = sincronizarMapeadas($pdo, $origem, $destino, false); // simulação: o que ainda falta replicar nas mapeadas
    if (isset($mapeadas['erro']) || $mapeadas['tabelas']) $r['fo_nc']['divergentes'] += isset($mapeadas['erro']) ? ['tabelas_mapeadas' => ['erro' => $mapeadas['erro']]] : $mapeadas['tabelas'];
    if ($soLegado) $r['tabelas_do_legado_sem_correspondente'] = $soLegado;
    // Migração 316: unidades de cada usuário (o que ainda falta/sobra em relação ao legado)
    $uu = sincronizarUsuariosUnidades($pdo, $origem, $destino, false);
    if (isset($uu['erro']) || $uu['incluidos'] || $uu['excluidos']) $r['legal']['divergentes']['usuariosxunidades'] = isset($uu['erro']) ? ['erro' => $uu['erro']] : ['faltam' => $uu['incluidos'], 'sobram' => $uu['excluidos']];
    $residuo = sincronizar($pdo, $origem, $destino, false);
    $r['diferencas_restantes'] = isset($residuo['erro']) ? ['erro' => $residuo['erro']] : array_filter($residuo['totais']);
    // Motivo conhecido das linhas que faltam (o sincronismo não grava: dependência inexistente ou repetida no legado)
    foreach ($r['fo_nc']['divergentes'] as $t => &$d) {
        $x = is_array($residuo['tabelas'] ?? null) ? ($residuo['tabelas'][$t] ?? []) : [];
        if (!empty($x['ignorados_duplicidade'])) $d['motivo'][] = $x['ignorados_duplicidade'] . ' repetida(s) no legado (índice único do greenportal)';
        if (!empty($x['ignorados_dependencia'])) $d['motivo'][] = $x['ignorados_dependencia'] . ' dependem de registro que não existe no greenportal';
    }
    unset($d);
    $div = count($r['fo_nc']['divergentes']) + count($r['legal']['divergentes']);
    $r['status'] = $div === 0 && !$soLegado && !array_filter($r['diferencas_restantes']) ? 'OK: legado replicado no greenportal'
        : "DIVERGENTE: $div tabela(s) com linhas faltando/sobrando" . ($soLegado ? ', ' . count($soLegado) . ' tabela(s) do legado sem correspondente' : '');
    return $r;
}

$soValidar = in_array('--validar', $argv, true);
if ($soValidar) {
    $v = ['validacao' => 'legado -> greenportal (somente leitura)', 'inicio' => date('Y-m-d H:i:s'), 'bancos' => []];
    foreach ($sincPares as $lista) foreach ($lista as [$origem, $destino]) $v['bancos'][$destino] = validarReplica($pdo, $origem, $destino);
    $central = array_column(linhas($pdo, "SELECT s.TABLE_NAME FROM information_schema.TABLES s JOIN information_schema.TABLES d ON d.TABLE_SCHEMA='glegislacao_dbp' AND d.TABLE_NAME=s.TABLE_NAME AND d.TABLE_TYPE='BASE TABLE' WHERE s.TABLE_SCHEMA='green_legislacao' AND s.TABLE_TYPE='BASE TABLE' AND (s.TABLE_NAME LIKE 'legislacao%' OR s.TABLE_NAME IN ('tipos','ambitos','assuntos','perguntas'))"), 'TABLE_NAME');
    if (existeBanco($pdo, 'green_legislacao') && existeBanco($pdo, 'glegislacao_dbp')) {
        $v['legislacao_central'] = validarTabelas($pdo, 'green_legislacao', 'glegislacao_dbp', $central);
        // migração 393: por operações, o greenportal pode ter mudanças próprias — as diferenças são esperadas (informativo)
        if (SINC_LEGISLACAO_MODO === 'operacoes') $v['legislacao_central'] = ['modo' => 'OPERACOES: diferenças esperadas (o greenportal mantém o que foi feito aqui); só informativo'] + $v['legislacao_central'];
    }
    $v['fim'] = date('Y-m-d H:i:s');
    $json = json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $dirLog = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
    if (is_dir($dirLog) || @mkdir($dirLog, 0775, true)) @file_put_contents($dirLog . DIRECTORY_SEPARATOR . 'sincronismo_validacao_' . date('Ymd_His') . '.json', $json);
    echo $json . PHP_EOL;
    exit(0);
}

if ($aplicar) {
    $preflight = ['ok'=>true,'bancos'=>[],'legal_empresas'=>[]];
    foreach ($empresas as $empresa) foreach ($sincPares[$empresa] as [$origem,$destino]) {
        $mapPreflight=sincronizarMapeadas($pdo,$origem,$destino,false); if(isset($mapPreflight['erro'])){$preflight['ok']=false;$preflight['mapeadas'][$destino]=['erro'=>'tabelas mapeadas: '.$mapPreflight['erro']];}
        $foPreflight=sincronizar($pdo,$origem,$destino,false);$legalPreflight=sincronizarLegalTenant($pdo,$origem,$destino,false);$preflight['bancos'][$destino]=$foPreflight;$preflight['legal_empresas'][$destino]=$legalPreflight;if(isset($foPreflight['erro'])||isset($legalPreflight['erro']))$preflight['ok']=false;
    }
    if ($sincPares) { $centralPreflight=sincronizarLegislacao($pdo,false);$preflight['legislacao_central']=$centralPreflight;if(isset($centralPreflight['erro']))$preflight['ok']=false; }
    // Banco com erro na pré-validação fica de fora desta execução; os outros seguem (antes, um erro cancelava todos).
    $foraDaExecucao = [];
    foreach ($preflight['bancos'] as $b => $x) if (isset($x['erro']) || isset($preflight['legal_empresas'][$b]['erro']) || isset($preflight['mapeadas'][$b]['erro'])) $foraDaExecucao[$b] = $x['erro'] ?? $preflight['legal_empresas'][$b]['erro'] ?? $preflight['mapeadas'][$b]['erro'];
    if (!$preflight['ok']) fwrite(STDERR, "Pre-validacao com erro; bancos fora desta execucao: " . json_encode($foraDaExecucao, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL);
}

$modoAplicar = $aplicar;
$foraDaExecucao = $foraDaExecucao ?? [];
$inicio = date('Y-m-d H:i:s');
$saida = ['sincronismo' => 'legado -> novo', 'modo' => $aplicar ? 'APLICAR' : 'SIMULACAO (nada foi gravado; use --aplicar para gravar)', 'inicio' => $inicio, 'bancos' => [], 'legal_empresas' => [], 'pares_migrados' => $sincPares];
$ok = true;
$falhou = false;
foreach ($empresas as $empresa) {
    foreach ($sincPares[$empresa] as [$origem, $destino]) {
        if (isset($foraDaExecucao[$destino])) { $ok = false; $saida['bancos'][$destino] = ['erro' => 'pré-validação: ' . $foraDaExecucao[$destino]]; continue; }
        if ($aplicar) {
            @registrarSincronismo($pdo, $destino, 'SINCRONISMO_FO_LEGADO', $origem . '.fo_*', $destino . '.fo_*', ['totais'=>[]], $inicio, 'EM_EXECUCAO');
            @registrarSincronismo($pdo, $destino, 'SINCRONISMO_LEGAL_LEGADO', $origem . '.legal', $destino . '.legal', ['totais'=>[]], $inicio, 'EM_EXECUCAO');
        }
        // Tabelas mapeadas (ex.: nc_tipo_de_reclamacao -> nc_tiporeclamacao) antes das tabelas de mesmo nome
        $map = sincronizarMapeadas($pdo, $origem, $destino, $aplicar);
        if ($map['tabelas'] || isset($map['erro'])) $saida['mapeadas'][$destino] = $map;
        if (isset($map['erro'])) { $ok = false; continue; }
        $r = sincronizar($pdo, $origem, $destino, $aplicar);
        if (isset($r['erro'])) { $ok = false; $falhou = true; $saida['bancos'][$destino] = $r; if ($aplicar) @registrarSincronismo($pdo, $destino, 'SINCRONISMO_FO_LEGADO', $origem . '.fo_*', $destino . '.fo_*', $r, $inicio, 'ERRO'); continue; }
        $saida['bancos'][$destino] = $r;
        $legal = sincronizarLegalTenant($pdo, $origem, $destino, $aplicar); $saida['legal_empresas'][$destino] = $legal;
        if (isset($legal['erro'])) { $ok = false; $falhou = true; if ($aplicar) @registrarSincronismo($pdo, $destino, 'SINCRONISMO_LEGAL_LEGADO', $origem . '.legal', $destino . '.legal', $legal, $inicio, 'ERRO'); continue; }
        // Registro da última execução no controle de migrações do cliente.
        if ($aplicar && !isset($r['erro']) && (int)valor($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'registros_migracao'", [$destino]) > 0) {
            $total = array_sum($r['totais']) - $r['totais']['ignorados_dependencia'];
            $det = json_encode(['ultima_execucao' => $inicio, 'origem' => $origem, 'totais' => $r['totais'], 'tabelas' => $r['tabelas']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $existe = valor($pdo, 'SELECT codregistromigracao FROM ' . q($destino) . ".registros_migracao WHERE codigo_migracao = 'SINCRONISMO_FO_LEGADO' LIMIT 1");
            if ($existe) $pdo->prepare('UPDATE ' . q($destino) . ".registros_migracao SET status = 'CONCLUIDA', quantidade_registros = ?, iniciada_em = ?, concluida_em = CURRENT_TIMESTAMP, detalhes = ? WHERE codregistromigracao = ?")->execute([$total, $inicio, $det, $existe]);
            else $pdo->prepare('INSERT INTO ' . q($destino) . ".registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes) VALUES ('SINCRONISMO_FO_LEGADO', ?, ?, 'ESTRUTURA', '4.0.1', 'CONCLUIDA', ?, ?, CURRENT_TIMESTAMP, ?)")->execute([$origem . '.fo_*', $destino . '.fo_*', $total, $inicio, $det]);
        }
        if ($aplicar) registrarSincronismo($pdo, $destino, 'SINCRONISMO_LEGAL_LEGADO', $origem . '.legal', $destino . '.legal', $legal, $inicio);
        // Migração 316: unidades de cada usuário (restrição por unidade da Homologação e da Portaria).
        $saida['usuarios_unidades'][$destino] = sincronizarUsuariosUnidades($pdo, $origem, $destino, $aplicar);
        if (isset($saida['usuarios_unidades'][$destino]['erro'])) $ok = false;
        // Migração 333: participantes externos das listas de distribuição de NC (<legado>_seguranca.usuarios ativado = 'X' ->
        // nc_participantes; nome e e-mail do legado, Ativo do greenportal). Banco sem a migração 333: ignorado.
        $saida['participantes_nc'][$destino] = NaoConformidadeParticipantes::sincronizar($pdo, $origem, $destino, $aplicar);
        if (isset($saida['participantes_nc'][$destino]['erro'])) $ok = false;
        // Depois de gravar: confere se o que está no legado ficou replicado no greenportal
        if ($aplicar) { try { $saida['validacao'][$destino] = validarReplica($pdo, $origem, $destino); } catch (Throwable $e) { $saida['validacao'][$destino] = ['erro' => $e->getMessage()]; } }
    }
}
// A legislação central não depende das empresas: é aplicada mesmo que alguma empresa tenha falhado
// (antes, qualquer falha deixava a central só em simulação).
$saida['legislacao_central'] = empty($sincPares) ? ['status'=>'IGNORADA','motivo'=>'nenhuma empresa migrada elegível'] : sincronizarLegislacao($pdo, $aplicar);
if (isset($saida['legislacao_central']['erro'])) $ok = false;
if ($aplicar && $ok && isset($saida['legislacao_central']['totais'])) registrarSincronismo($pdo, 'glegislacao_dbp', 'SINCRONISMO_LEGISLACAO_CENTRAL', 'green_legislacao', 'glegislacao_dbp', $saida['legislacao_central'], $inicio);
$saida['fim'] = date('Y-m-d H:i:s');
$saida['ok'] = $ok;
if ($foraDaExecucao) $saida['fora_da_execucao'] = $foraDaExecucao;
$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
// Guarda o relatório de cada execução em storage\logs (o agendador não mostra a saída na tela).
$dirLog = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (is_dir($dirLog) || @mkdir($dirLog, 0775, true)) @file_put_contents($dirLog . DIRECTORY_SEPARATOR . 'sincronismo_fo_' . date('Ymd_His') . ($modoAplicar ? '' : '_simulacao') . '.json', $json);
echo $json . PHP_EOL;
exit($ok ? 0 : 1);
