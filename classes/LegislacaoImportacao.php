<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';
require_once __DIR__ . '/LegislacaoEmpresas.php';
require_once __DIR__ . '/PlanilhaLeitor.php';

/**
 * Migração 397 — importação da planilha mensal de Legislação x Empresas (substitui o legado legislacaoxempresax_v2.php).
 *
 * A planilha: coluna A = código da lei; linha de cabeçalho com "Codigo" em A (linha 1, ou 2 abaixo do título "Green Solutions")
 *   = nomes das unidades (uma por coluna, de qualquer empresa; colunas vazias são puladas);
 * cada célula: vazia, MATRIZ (vincula a própria lei) ou uma letra A–Z (vincula o padrão: código + letra, criado se não existir).
 *
 * Regras decididas pelo usuário em 18/11/2026:
 *   - a planilha é o retrato completo do mês: vínculo da planilha que falta é INCLUÍDO; tipo diferente é TROCADO
 *     (ex.: MATRIZ → A); vínculo que existe no portal e não está na planilha é só LISTADO (RETIRAR), e sai apenas se o
 *     usuário marcar. O que conta como "não está na planilha": lei da planilha × unidade da planilha com a célula vazia;
 *   - a lei que recebe vínculo incluído ou trocado fica com data_atualizacao = agora (entra em "Normas Inseridas" da RCL);
 *   - o próprio ADMINISTRADOR que enviou pode autorizar; nada é gravado antes da autorização;
 *   - a sincronização legado → portal continua como está (o portal ainda não é produção).
 *
 * Unidades: procuradas pelo nome (sem diferença de maiúsculas/acentos/espaços) nas empresas migradas, menos as de modelo.
 * Nome que não existe ou que existe em mais de uma empresa: a coluna fica de fora (aparece em Problemas), como no legado.
 * Leitura por sp_crud_select e gravação por procedures (sp_crud_*, sp_legimp_*).
 */
/** Planilha recusada na entrada (não é a planilha de Legislação x Empresas ou já foi enviada): nada é gravado. */
final class PlanilhaInvalida extends RuntimeException
{
}

final class LegislacaoImportacao
{
    /** Mínimos para aceitar a planilha (validação de formato, pedido do usuário de 18/11/2026). */
    public const MIN_LEIS_EXISTENTES = 0.8;   // dos códigos da coluna A, quantos precisam existir no cadastro
    public const MIN_CELULAS_VALIDAS = 0.9;   // das células preenchidas (colunas B em diante), quantas precisam ser MATRIZ ou letra
    /** Tipos de pedido: EMPRESA = Legislação x Empresas (397); PODER = Legislação x Controle (398). */
    public const PEDIDOS = ['EMPRESA' => 'Legislação x Empresas', 'PODER' => 'Legislação x Controle', 'ALTERACAO' => 'Alterações da legislação'];
    /** Valores aceitos na planilha de Legislação x Controle (gravados como na tela: com acento). */
    public const PODERES = ['CONHECIMENTO' => 'Conhecimento', 'CONTROLE' => 'Controle', 'INFLUENCIA' => 'Influência'];
    public const TIPOS = ['MATRIZ', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'];
    /** Tabelas do banco da legislação copiadas da lei principal para o padrão novo (as mesmas do grid). */
    private const TABELAS_PADRAO = ['legislacaoxalteracao', 'legislacaoxassunto', 'legislacaoxcategoria', 'legislacaoxmes', 'legislacaoxpalavraschave', 'legislacaoxpergunta', 'legislacaoxtreinamentos', 'legislacaoxdocumentos', 'legislacaoxchecklist', 'legislacaoxaspecto', 'legislacaoxatividade', 'legislacaoximpacto', 'legislacaoxperigo', 'legislacaoxrisco', 'legislacaoxinfraestruturas', 'legislacaoxlicencas', 'legislacaoxpoder', 'legislacaoxsituacao', 'mm_medicaoxlegislacao', 'mm_monitoramentoxlegislacao', 'vlaiaxlegislacao', 'lais_legislacaoxaspectossociais', 'lais_legislacaoximpactossociais'];

    private static ?PDO $universal = null;
    /** Diferença (s) entre o relógio do MySQL e o do PHP, que podem estar em fusos diferentes: as datas gravadas seguem o banco. */
    private static ?int $desvio = null;
    private static ?PDO $legislacao = null;

    // ================================================================== conexões e acesso
    public static function universal(): PDO
    {
        if (self::$universal === null) {
            $env = GreenPortalDatabase::env();
            self::$universal = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
            self::$universal->exec('SET NAMES utf8mb4');
        }
        return self::$universal;
    }

    public static function legislacao(): PDO
    {
        if (self::$legislacao === null) {
            $env = GreenPortalDatabase::env();
            self::$legislacao = GreenPortalDatabase::connect($env, (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp'), 'GLEGISLACAO');
        }
        return self::$legislacao;
    }

    public static function chamar(PDO $pdo, string $sql, array $p = []): array
    {
        // As sp_crud_* montam o comando com GROUP_CONCAT: o padrão do MySQL (1024) corta textos longos (resumo, problemas, log)
        static $ajustadas = [];
        $k = spl_object_id($pdo);
        if (!isset($ajustadas[$k])) { $pdo->exec('SET SESSION group_concat_max_len = 16777216'); $ajustadas[$k] = true; }
        $s = $pdo->prepare($sql); $s->execute($p);
        $r = $s->columnCount() > 0 ? $s->fetchAll(PDO::FETCH_ASSOC) : [];
        while ($s->nextRowset()) { /* descarta */ }
        $s->closeCursor();
        return $r;
    }

    /** Data e hora pelo relógio do banco (acerta o desvio a partir de uma data gravada pelo NOW() do MySQL). */
    private static function agora(string $formato = 'Y-m-d H:i:s'): string
    {
        return date($formato, time() + (self::$desvio ?? 0));
    }

    private static function acertarRelogio(?string $dataDoBanco): void
    {
        if ($dataDoBanco && ($t = strtotime($dataDoBanco)) && abs(time() - $t) < 86400) self::$desvio = (int)(round(($t - time()) / 900) * 900);
    }

    private static function json(mixed $v): string
    {
        return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function ler(PDO $pdo, string $tabela, array $filtros = [], int $maximo = 0): array
    {
        return LegislacaoEmpresas::ler($pdo, $tabela, $filtros, $maximo);
    }

    /** Nome sem acento, maiúsculo, com um espaço só (mesma comparação do legado: latin1_swedish_ci). */
    public static function chave(string $v): string
    {
        $v = str_replace("\xC2\xA0", ' ', $v);
        if (class_exists('Normalizer')) $v = (string)preg_replace('/\p{Mn}/u', '', (string)Normalizer::normalize($v, Normalizer::FORM_D));
        return mb_strtoupper(trim((string)preg_replace('/\s+/u', ' ', $v)), 'UTF-8');
    }

    /** Valor da célula já normalizado para o tipo do pedido, ou null se não for válido. */
    public static function valorPlanilha(string $tipo, string $v): ?string
    {
        $v = trim($v);
        if ($v === '') return null;
        if ($tipo === 'PODER') return self::PODERES[self::chave($v)] ?? null;
        $v = strtoupper($v);
        return in_array($v, self::TIPOS, true) ? $v : null;
    }

    private static function valoresTexto(string $tipo): string
    {
        if ($tipo === 'ALTERACAO') return 'situação Lei Inclusa, Lei Alterada ou Lei Revogada';
        return $tipo === 'PODER' ? 'Conhecimento, Controle ou Influência' : 'MATRIZ ou uma letra de A a Z';
    }

    public static function tipoPedido(?string $t): string
    {
        $t = strtoupper((string)$t);
        return isset(self::PEDIDOS[$t]) ? $t : 'EMPRESA';
    }

    private static function letraColuna(int $c): string
    {
        $s = ''; while ($c > 0) { $m = ($c - 1) % 26; $s = chr(65 + $m) . $s; $c = intdiv($c - 1, 26); } return $s;
    }

    // ================================================================== 1. conferência (não grava nada no cadastro)
    /**
     * Lê a planilha, compara com o portal e grava o pedido (status CONFERIDA) com os itens. Devolve o codimportacao.
     * @param array{codusuario:int,nome:string,codempresa:int,banco:string} $quem
     */
    public static function conferir(string $arquivo, string $nomeOriginal, array $quem, string $tipo = 'EMPRESA'): int
    {
        $tipo = self::tipoPedido($tipo);
        $hash = hash_file('sha256', $arquivo);
        $u = self::universal();
        // A mesma planilha (mesmo conteúdo) não pode ser enviada de novo enquanto houver pedido dela que não foi descartado
        foreach (self::ler($u, 'vw_legislacao_importacoes', ['arquivo_hash' => $hash]) as $p) {
            if ((string)$p['arquivo_hash'] !== $hash || $p['status'] === 'DESCARTADA' || self::tipoPedido($p['tipo'] ?? null) !== $tipo) continue;
            throw new PlanilhaInvalida('Esta planilha já foi enviada no pedido ' . (int)$p['codimportacao'] . ' (' . $p['arquivo_nome'] . ', ' . self::statusTexto((string)$p['status']) . '). Abra o pedido na lista ou, para enviar de novo, descarte-o primeiro.');
        }
        $linhas = PlanilhaLeitor::ler($arquivo, $nomeOriginal);
        $c = self::comparar($linhas, $tipo);
        $ref = null;
        if (preg_match('/(?<!\d)(0[1-9]|1[0-2])[-_.]?(20\d\d)(?!\d)/', $nomeOriginal, $m)) $ref = $m[2] . '-' . $m[1];
        if ($ref !== null) {
            foreach (self::ler($u, 'vw_legislacao_importacoes', ['referencia' => $ref]) as $p) {
                if ($p['status'] === 'DESCARTADA' || self::tipoPedido($p['tipo'] ?? null) !== $tipo) continue;
                $c['problemas'][] = ['tipo' => 'MES_JA_ENVIADO', 'aviso' => 1, 'texto' => 'Já existe pedido do mês ' . substr($ref, 5, 2) . '/' . substr($ref, 0, 4) . ': pedido ' . (int)$p['codimportacao'] . ' (' . $p['arquivo_nome'] . ', ' . self::statusTexto((string)$p['status']) . '). Confira se esta é mesmo a planilha certa.'];
            }
        }
        $dados = [
            'arquivo_nome' => mb_substr($nomeOriginal !== '' ? $nomeOriginal : basename($arquivo), 0, 255), 'arquivo_hash' => $hash,
            'tipo' => $tipo, 'referencia' => $ref, 'status' => 'CONFERIDA',
            'codempresa_origem' => $quem['codempresa'], 'banco_origem' => $quem['banco'],
            'enviado_por' => $quem['codusuario'], 'enviado_por_nome' => mb_substr($quem['nome'], 0, 150),
            'total_incluir' => $c['totais']['INCLUIR'], 'total_trocar' => $c['totais']['TROCAR'], 'total_retirar' => $c['totais']['RETIRAR'],
            'total_manter' => $c['totais']['MANTER'], 'total_padroes' => count($c['padroes']), 'total_problemas' => count($c['problemas']),
            'resumo' => self::json($c['resumo']), 'problemas' => self::json($c['problemas']),
        ];
        $r = self::chamar($u, 'CALL sp_crud_insert(?, ?)', ['legislacao_importacoes', self::json($dados)]);
        $id = (int)($r[0]['chave_gerada'] ?? 0);
        if ($id <= 0) {
            foreach (self::ler($u, 'vw_legislacao_importacoes', ['enviado_por' => $quem['codusuario'], 'status' => 'CONFERIDA']) as $p) $id = max($id, (int)$p['codimportacao']);
        }
        if ($id <= 0) throw new RuntimeException('Não foi possível gravar o pedido de importação.');
        foreach (array_chunk($c['itens'], 300) as $lote) self::chamar($u, 'CALL sp_legimp_itens_incluir(?, ?)', [$id, self::json($lote)]);
        self::acertarRelogio((string)(self::pedido($id)['enviado_em'] ?? ''));
        self::anotar($id, 'Planilha de ' . self::PEDIDOS[$tipo] . ' conferida por ' . $quem['nome'] . '.');
        return $id;
    }

    /**
     * Compara as linhas da planilha com o portal. Não grava nada.
     * @return array{itens:array,problemas:array,padroes:array,totais:array,resumo:array}
     */
    public static function comparar(array $linhas, string $tipo = 'EMPRESA'): array
    {
        $tipo = self::tipoPedido($tipo);
        $problemas = [];
        $prob = static function (string $tipo, string $texto, array $extra = []) use (&$problemas): void { $problemas[] = ['tipo' => $tipo, 'texto' => $texto] + $extra; };
        if (!$linhas) throw new RuntimeException('A planilha está vazia.');
        // Cabeçalho: a primeira linha (entre as 10 primeiras) com "Codigo" na coluna A. Aceita os dois layouts usados:
        // o antigo (.xls: cabeçalho na linha 1, unidades a partir da coluna B) e o novo (.xlsx: título "Green Solutions" na
        // linha 1, cabeçalho na linha 2, colunas B–D vazias e unidades a partir da E). Linhas acima do cabeçalho são ignoradas.
        $nLinhaCab = null;
        foreach (array_slice(array_keys($linhas), 0, 10) as $n) {
            if ($n > 10) break;
            if (self::chave((string)($linhas[$n][1] ?? '')) === 'CODIGO') { $nLinhaCab = (int)$n; break; }
        }
        if ($nLinhaCab === null) {
            $primeira = (int)array_key_first($linhas);
            throw new PlanilhaInvalida(self::recusa(['não foi encontrada, nas 10 primeiras linhas, a linha de cabeçalho com "Codigo" na coluna A (a coluna A da primeira linha preenchida tem "' . mb_substr((string)($linhas[$primeira][1] ?? ''), 0, 40) . '")'], $tipo));
        }
        $cab = $linhas[$nLinhaCab];
        foreach (array_keys($linhas) as $n) { if ($n > $nLinhaCab) break; unset($linhas[$n]); }

        if ($tipo === 'ALTERACAO') return self::compararAlteracao($linhas, $nLinhaCab, $cab);
        // ---------------------------------------------------- formato (antes de consultar o banco)
        $motivos = [];
        $nomesCab = array_filter($cab, static fn($v, $col) => $col >= 2 && trim((string)$v) !== '', ARRAY_FILTER_USE_BOTH);
        if (!$nomesCab) $motivos[] = 'a linha ' . $nLinhaCab . ' (cabeçalho) não tem nomes de unidades a partir da coluna B';
        $numericos = array_filter($nomesCab, static fn($v) => is_numeric(str_replace([',', '.'], '', (string)$v)));
        if ($nomesCab && count($numericos) > count($nomesCab) / 2) $motivos[] = 'a linha ' . $nLinhaCab . ' (cabeçalho) tem números no lugar dos nomes das unidades';
        $preenchidas = 0; $validas = 0; $comCodigo = 0; $invalidosEx = [];
        foreach ($linhas as $cells) {
            if (trim((string)($cells[1] ?? '')) !== '') $comCodigo++;
            foreach ($cells as $col => $v) {
                if ($col < 2) continue;
                $preenchidas++;
                if (self::valorPlanilha($tipo, (string)$v) !== null) $validas++;
                elseif (count($invalidosEx) < 3) $invalidosEx[] = '"' . mb_substr((string)$v, 0, 20) . '"';
            }
        }
        if ($comCodigo === 0) $motivos[] = 'a coluna A não tem códigos de lei';
        if ($preenchidas === 0) $motivos[] = 'nenhuma célula preenchida com ' . self::valoresTexto($tipo) . ' a partir da coluna B';
        elseif ($validas / $preenchidas < self::MIN_CELULAS_VALIDAS) $motivos[] = sprintf('só %d de %d células preenchidas são %s (mínimo %d%%); exemplos de outros valores: %s', $validas, $preenchidas, self::valoresTexto($tipo), (int)(self::MIN_CELULAS_VALIDAS * 100), implode(', ', $invalidosEx));
        if ($motivos) throw new PlanilhaInvalida(self::recusa($motivos, $tipo));

        // ---------------------------------------------------- unidades de todas as empresas migradas (menos modelos)
        $porNome = [];
        $empresas = [];
        foreach (LegislacaoEmpresas::migradas() as $cod => $e) {
            if (preg_match('/modelo/i', $e['banco']) || self::chave($e['codigo']) === 'MODELO') continue;
            try { $unidades = self::ler(LegislacaoEmpresas::pdo($cod), 'unidades'); }
            catch (Throwable $err) { $prob('EMPRESA_INDISPONIVEL', 'Empresa ' . $e['nome'] . ': não foi possível ler as unidades (' . $err->getMessage() . ').'); continue; }
            $empresas[$cod] = $e;
            foreach ($unidades as $un) {
                $un = array_change_key_case($un, CASE_LOWER);
                $nome = (string)($un['dscunidade'] ?? '');
                if (trim($nome) === '') continue;
                $rcl = self::chave((string)($un['usado_em_manutencao_legal'] ?? '')) === 'SIM' && in_array(self::chave((string)($un['tipo_de_rcl'] ?? '')), ['RCL COMPLETA', 'RCL PADRAO'], true);
                $porNome[self::chave($nome)][] = ['codempresa' => $cod, 'empresa' => $e['nome'], 'codunidade' => (int)$un['codunidade'], 'nome' => $nome, 'rcl' => $rcl];
            }
        }

        // ---------------------------------------------------- colunas → unidades
        $colunas = [];
        $ignoradas = [];
        $usadas = [];
        $semRcl = [];
        foreach ($cab as $col => $nome) {
            if ($col < 2) continue;
            $letra = self::letraColuna((int)$col);
            $achou = $porNome[self::chave((string)$nome)] ?? [];
            // Decisão do usuário (18/11/2026): nem todas as unidades estão no portal; as que não existem são desconsideradas
            // (não são problema). Ficam só listadas no Resumo, para conferência.
            if (!$achou) { $ignoradas[] = ['coluna' => $letra, 'unidade' => (string)$nome]; continue; }
            if (count($achou) > 1) { $prob('UNIDADE_REPETIDA', 'Coluna ' . $letra . ' "' . $nome . '": o nome existe em mais de uma unidade (' . implode('; ', array_map(static fn($a) => $a['empresa'] . ' / ' . $a['nome'], $achou)) . '). Coluna ignorada.', ['coluna' => $letra, 'unidade' => $nome]); continue; }
            $a = $achou[0];
            $k = $a['codempresa'] . ':' . $a['codunidade'];
            if (isset($usadas[$k])) { $prob('COLUNA_REPETIDA', 'Coluna ' . $letra . ' "' . $nome . '": a mesma unidade já está na coluna ' . $usadas[$k] . '. Coluna ignorada.', ['coluna' => $letra, 'unidade' => $nome]); continue; }
            $usadas[$k] = $letra;
            if (!$a['rcl']) $semRcl[] = $a['empresa'] . ' / ' . $a['nome'];
            $colunas[(int)$col] = $a + ['letra' => $letra];
        }
        if ($semRcl) $prob('UNIDADE_SEM_RCL', 'Unidades que não estão marcadas como "usada em manutenção legal" com tipo de RCL (os vínculos serão gravados, mas a unidade não aparece nas telas e na RCL até ser marcada): ' . implode('; ', $semRcl) . '.', ['aviso' => 1]);

        // ---------------------------------------------------- leis
        $leg = self::legislacao();
        $idPorCodigo = []; $codigoPorId = [];
        foreach (self::ler($leg, 'legislacao') as $l) {
            $l = array_change_key_case($l, CASE_UPPER);
            $cod = strtoupper(trim((string)($l['CODIGO'] ?? '')));
            if ($cod === '') continue;
            $id = (int)$l['CODLEGISLACAO'];
            $codigoPorId[$id] = $cod;
            if (!isset($idPorCodigo[$cod]) || $id < $idPorCodigo[$cod]) $idPorCodigo[$cod] = $id;
        }
        $leis = []; // linha => código
        $linhaDoCodigo = [];
        foreach ($linhas as $n => $cells) {
            $cod = strtoupper(trim((string)($cells[1] ?? '')));
            if ($cod === '') {
                if (count(array_filter($cells, static fn($v, $c) => $c > 1 && isset($colunas[$c]), ARRAY_FILTER_USE_BOTH))) $prob('LINHA_SEM_CODIGO', 'Linha ' . $n . ': células preenchidas sem o código da lei na coluna A. Linha ignorada.', ['linha' => $n]);
                continue;
            }
            if (!isset($idPorCodigo[$cod])) { $prob('LEI_NAO_ENCONTRADA', 'Linha ' . $n . ': a lei ' . $cod . ' não existe no cadastro da Legislação. Linha ignorada.', ['linha' => $n, 'codigo' => $cod]); continue; }
            if (isset($linhaDoCodigo[$cod])) { $prob('LEI_REPETIDA', 'Linha ' . $n . ': a lei ' . $cod . ' já está na linha ' . $linhaDoCodigo[$cod] . '. Vale a primeira; esta linha foi ignorada.', ['linha' => $n, 'codigo' => $cod]); continue; }
            $linhaDoCodigo[$cod] = $n;
            $leis[$n] = $cod;
        }
        $naPlanilha = array_flip($leis);
        $codigosPlanilha = count(array_unique(array_filter(array_map(static fn($c) => strtoupper(trim((string)($c[1] ?? ''))), $linhas))));
        $existentes = count(array_filter(array_unique(array_filter(array_map(static fn($c) => strtoupper(trim((string)($c[1] ?? ''))), $linhas))), static fn($c) => isset($idPorCodigo[$c])));
        if ($codigosPlanilha > 0 && $existentes / $codigosPlanilha < self::MIN_LEIS_EXISTENTES) {
            $faltam = array_slice(array_values(array_filter(array_unique(array_filter(array_map(static fn($c) => strtoupper(trim((string)($c[1] ?? ''))), $linhas))), static fn($c) => !isset($idPorCodigo[$c]))), 0, 5);
            $motivos[] = sprintf('só %d dos %d códigos da coluna A existem no cadastro da Legislação (mínimo %d%%). As leis precisam estar cadastradas na Legislação antes da importação (o sincronismo só traz do sistema antigo as que já existem lá). Exemplos que faltam: %s', $existentes, $codigosPlanilha, (int)(self::MIN_LEIS_EXISTENTES * 100), implode(', ', $faltam));
        }
        if (!$colunas) $motivos[] = 'nenhuma unidade do cabeçalho existe no portal';
        if ($motivos) throw new PlanilhaInvalida(self::recusa($motivos, $tipo));
        if ($tipo === 'PODER') {
            $lerProblemas = function () use (&$problemas): array { return $problemas; };
            return self::compararPoder($linhas, $leis, $naPlanilha, $colunas, $ignoradas, $idPorCodigo, $empresas, $cab, $prob, $lerProblemas);
        }

        // ---------------------------------------------------- vínculos atuais das unidades da planilha
        $atuais = []; // "codigoBase|codempresa:codunidade" => [vínculos]
        $porEmpresa = [];
        foreach ($colunas as $col => $a) $porEmpresa[$a['codempresa']][$a['codunidade']] = true;
        foreach ($porEmpresa as $codemp => $uns) {
            try { $vinculos = self::ler(LegislacaoEmpresas::pdo($codemp), 'legislacaoxempresa', ['CODEMPRESA' => $codemp]); }
            catch (Throwable $err) { $prob('EMPRESA_INDISPONIVEL', 'Empresa ' . ($empresas[$codemp]['nome'] ?? $codemp) . ': não foi possível ler os vínculos (' . $err->getMessage() . ').'); continue; }
            foreach ($vinculos as $v) {
                $v = array_change_key_case($v, CASE_UPPER);
                $un = (int)($v['CODUNIDADE'] ?? 0);
                if ((int)($v['CODEMPRESA'] ?? 0) !== $codemp || !isset($uns[$un])) continue;
                $cod = $codigoPorId[(int)($v['CODLEGISLACAO'] ?? 0)] ?? null;
                if ($cod === null) continue;
                $tipo = strtoupper(trim((string)($v['TIPO_DE_LEI'] ?? '')));
                $base = null;
                if (strlen($tipo) === 1 && ctype_alpha($tipo) && str_ends_with($cod, $tipo) && isset($naPlanilha[substr($cod, 0, -1)])) $base = substr($cod, 0, -1);
                elseif (isset($naPlanilha[$cod])) $base = $cod;
                if ($base === null) continue;
                $atuais[$base . '|' . $codemp . ':' . $un][] = ['id' => (int)($v['CODLEGISLACAXEMPRESA'] ?? 0), 'codlegislacao' => (int)$v['CODLEGISLACAO'], 'codigo' => $cod, 'tipo' => $tipo === '' ? 'MATRIZ' : $tipo];
            }
        }

        // ---------------------------------------------------- comparação célula a célula
        $itens = []; $padroes = []; $totais = ['INCLUIR' => 0, 'TROCAR' => 0, 'RETIRAR' => 0, 'MANTER' => 0];
        $porEmpresaResumo = [];
        $alvo = static fn(string $base, string $tipo): string => $tipo === 'MATRIZ' ? $base : $base . $tipo;
        foreach ($leis as $n => $base) {
            foreach ($colunas as $col => $a) {
                $bruto = trim((string)($linhas[$n][$col] ?? ''));
                $v = strtoupper($bruto);
                if ($v !== '' && !in_array($v, self::TIPOS, true)) {
                    $prob('VALOR_INVALIDO', 'Linha ' . $n . ', coluna ' . $a['letra'] . ' (' . $base . ' × ' . $a['nome'] . '): valor "' . $bruto . '" inválido; use MATRIZ ou uma letra de A a Z. Célula ignorada (nada muda nela).', ['linha' => $n, 'coluna' => $a['letra'], 'codigo' => $base, 'valor' => $bruto]);
                    continue;
                }
                $lista = $atuais[$base . '|' . $a['codempresa'] . ':' . $a['codunidade']] ?? [];
                $comum = ['codempresa' => $a['codempresa'], 'empresa_nome' => $a['empresa'], 'codunidade' => $a['codunidade'], 'unidade_nome' => $a['nome'],
                    'linha' => $n, 'coluna' => $a['letra'], 'codigo_lei' => $base, 'codlegislacao_base' => $idPorCodigo[$base]];
                $retirar = static function (array $x) use (&$itens, &$totais, $comum): void {
                    $itens[] = $comum + ['acao' => 'RETIRAR', 'codlegxemp' => $x['id'], 'codlegislacao_atual' => $x['codlegislacao'], 'codigo_atual' => $x['codigo'], 'tipo_atual' => $x['tipo']];
                    $totais['RETIRAR']++;
                };
                $empKey = $a['empresa'];
                $porEmpresaResumo[$empKey] ??= ['INCLUIR' => 0, 'TROCAR' => 0, 'RETIRAR' => 0, 'MANTER' => 0];
                if ($v === '') { foreach ($lista as $x) { $retirar($x); $porEmpresaResumo[$empKey]['RETIRAR']++; } continue; }
                $codAlvo = $alvo($base, $v);
                $novoPadrao = $v !== 'MATRIZ' && !isset($idPorCodigo[$codAlvo]);
                if ($novoPadrao) $padroes[$codAlvo] = $base;
                $igual = null;
                foreach ($lista as $i => $x) if ($x['tipo'] === $v && $x['codigo'] === $codAlvo) { $igual = $i; break; }
                if ($igual !== null) {
                    $totais['MANTER']++; $porEmpresaResumo[$empKey]['MANTER']++;
                    foreach ($lista as $i => $x) if ($i !== $igual) { $retirar($x); $porEmpresaResumo[$empKey]['RETIRAR']++; }
                    continue;
                }
                if ($lista) {
                    $x = array_shift($lista);
                    $itens[] = $comum + ['acao' => 'TROCAR', 'codlegxemp' => $x['id'], 'codlegislacao_atual' => $x['codlegislacao'], 'codigo_atual' => $x['codigo'], 'tipo_atual' => $x['tipo'], 'tipo_novo' => $v, 'codigo_novo' => $codAlvo, 'padrao_novo' => $novoPadrao ? 1 : 0];
                    $totais['TROCAR']++; $porEmpresaResumo[$empKey]['TROCAR']++;
                    foreach ($lista as $x) { $retirar($x); $porEmpresaResumo[$empKey]['RETIRAR']++; }
                    continue;
                }
                $itens[] = $comum + ['acao' => 'INCLUIR', 'tipo_novo' => $v, 'codigo_novo' => $codAlvo, 'padrao_novo' => $novoPadrao ? 1 : 0];
                $totais['INCLUIR']++; $porEmpresaResumo[$empKey]['INCLUIR']++;
            }
        }
        ksort($padroes);
        ksort($porEmpresaResumo, SORT_NATURAL | SORT_FLAG_CASE);
        $resumo = [
            'linhas_planilha' => count($linhas), 'leis_validas' => count($leis), 'colunas_planilha' => max(0, count($cab) - 1), 'unidades_validas' => count($colunas),
            'padroes' => $padroes, 'por_empresa' => $porEmpresaResumo,
            'unidades' => array_values(array_map(static fn($a) => ['coluna' => $a['letra'], 'empresa' => $a['empresa'], 'unidade' => $a['nome']], $colunas)),
            'unidades_desconsideradas' => $ignoradas,
        ];
        return ['itens' => $itens, 'problemas' => $problemas, 'padroes' => $padroes, 'totais' => $totais, 'resumo' => $resumo];
    }

    /**
     * Legislação x Controle (migração 398). Regras do legado legislacaoxcontrolesx_v2.php, com as decisões da 397:
     *   - a célula (Conhecimento, Controle ou Influência) vale para a lei da linha e para os padrões dela (código + uma
     *     letra) que existem no cadastro e NÃO têm linha própria na planilha (a linha própria do padrão prevalece).
     *     O legado usava "código%", que pegava também leis de código mais longo (ex.: LB749 → LB7490): aqui não;
     *   - falta → INCLUIR; valor diferente → TROCAR; poder que existe e a célula está vazia → RETIRAR (só se marcar);
     *   - não cria padrão e não muda a data de atualização da lei (o legado também não).
     */
    private static function compararPoder(array $linhas, array $leis, array $naPlanilha, array $colunas, array $ignoradas, array $idPorCodigo, array $empresas, array $cab, callable $prob, callable $lerProblemas): array
    {
        // Leis alvo: a da linha e os padrões herdados
        $alvos = []; // código alvo => [linha, código da linha]
        foreach ($leis as $n => $base) {
            $alvos[$base] = ['linha' => $n, 'origem' => $base];
            foreach (range('A', 'Z') as $l) {
                $pad = $base . $l;
                if (isset($idPorCodigo[$pad]) && !isset($naPlanilha[$pad]) && !isset($alvos[$pad])) $alvos[$pad] = ['linha' => $n, 'origem' => $base];
            }
        }
        $codigoDoId = [];
        foreach ($alvos as $cod => $_) $codigoDoId[$idPorCodigo[$cod]] = $cod;

        // Poder atual das unidades da planilha (tabela da empresa, sem codempresa: a unidade define a empresa)
        $atuais = [];
        $porEmpresa = [];
        foreach ($colunas as $a) $porEmpresa[$a['codempresa']][$a['codunidade']] = true;
        foreach ($porEmpresa as $codemp => $uns) {
            try { $linhasPoder = self::ler(LegislacaoEmpresas::pdo($codemp), 'legislacaoxpoder'); }
            catch (Throwable $err) { $prob('EMPRESA_INDISPONIVEL', 'Empresa ' . ($empresas[$codemp]['nome'] ?? $codemp) . ': não foi possível ler os controles (' . $err->getMessage() . ').'); continue; }
            foreach ($linhasPoder as $v) {
                $v = array_change_key_case($v, CASE_LOWER);
                $un = (int)($v['codunidade'] ?? 0);
                $cod = $codigoDoId[(int)($v['codlegislacao'] ?? 0)] ?? null;
                if ($cod === null || !isset($uns[$un])) continue;
                $atuais[$cod . '|' . $codemp . ':' . $un][] = (string)($v['poder'] ?? '');
            }
        }

        $itens = []; $totais = ['INCLUIR' => 0, 'TROCAR' => 0, 'RETIRAR' => 0, 'MANTER' => 0]; $porEmpresaResumo = []; $herdados = 0;
        $invalidas = [];
        foreach ($alvos as $cod => $orig) {
            $n = $orig['linha'];
            foreach ($colunas as $col => $a) {
                $bruto = trim((string)($linhas[$n][$col] ?? ''));
                $v = $bruto === '' ? '' : self::valorPlanilha('PODER', $bruto);
                if ($v === null) {
                    if (!isset($invalidas[$n . ':' . $col])) {
                        $invalidas[$n . ':' . $col] = true;
                        $prob('VALOR_INVALIDO', 'Linha ' . $n . ', coluna ' . $a['letra'] . ' (' . $orig['origem'] . ' × ' . $a['nome'] . '): valor "' . $bruto . '" inválido; use Conhecimento, Controle ou Influência. Célula ignorada (nada muda nela nem nos padrões).', ['linha' => $n, 'coluna' => $a['letra'], 'codigo' => $orig['origem'], 'valor' => $bruto]);
                    }
                    continue;
                }
                $lista = $atuais[$cod . '|' . $a['codempresa'] . ':' . $a['codunidade']] ?? [];
                $herdado = $cod !== $orig['origem'];
                $comum = ['codempresa' => $a['codempresa'], 'empresa_nome' => $a['empresa'], 'codunidade' => $a['codunidade'], 'unidade_nome' => $a['nome'],
                    'linha' => $n, 'coluna' => $a['letra'], 'codigo_lei' => $cod, 'codlegislacao_base' => $idPorCodigo[$cod], 'codlegislacao_atual' => $idPorCodigo[$cod],
                    'codigo_novo' => $herdado ? 'padrão de ' . $orig['origem'] : null];
                $emp = $a['empresa'];
                $porEmpresaResumo[$emp] ??= ['INCLUIR' => 0, 'TROCAR' => 0, 'RETIRAR' => 0, 'MANTER' => 0];
                $add = static function (string $acao, array $extra) use (&$itens, &$totais, &$porEmpresaResumo, &$herdados, $comum, $emp, $herdado): void {
                    $itens[] = $comum + ['acao' => $acao] + $extra;
                    $totais[$acao]++; $porEmpresaResumo[$emp][$acao]++;
                    if ($herdado) $herdados++;
                };
                $lista = array_values(array_unique($lista));
                if ($v === '') { foreach ($lista as $x) $add('RETIRAR', ['tipo_atual' => $x]); continue; }
                if (in_array($v, $lista, true)) {
                    $totais['MANTER']++; $porEmpresaResumo[$emp]['MANTER']++;
                    foreach ($lista as $x) if ($x !== $v) $add('RETIRAR', ['tipo_atual' => $x]);
                    continue;
                }
                if ($lista) {
                    $add('TROCAR', ['tipo_atual' => $lista[0], 'tipo_novo' => $v]);
                    foreach (array_slice($lista, 1) as $x) $add('RETIRAR', ['tipo_atual' => $x]);
                    continue;
                }
                $add('INCLUIR', ['tipo_novo' => $v]);
            }
        }
        ksort($porEmpresaResumo, SORT_NATURAL | SORT_FLAG_CASE);
        $resumo = [
            'linhas_planilha' => count($linhas), 'leis_validas' => count($leis), 'colunas_planilha' => max(0, count($cab) - 1), 'unidades_validas' => count($colunas),
            'padroes' => [], 'padroes_herdados' => count($alvos) - count($leis), 'itens_herdados' => $herdados, 'por_empresa' => $porEmpresaResumo,
            'unidades' => array_values(array_map(static fn($a) => ['coluna' => $a['letra'], 'empresa' => $a['empresa'], 'unidade' => $a['nome']], $colunas)),
            'unidades_desconsideradas' => $ignoradas,
        ];
        return ['itens' => $itens, 'problemas' => $lerProblemas(), 'padroes' => [], 'totais' => $totais, 'resumo' => $resumo];
    }

    /** Aplica um pedido de Legislação x Controle: poder por lei × unidade na tabela legislacaoxpoder da empresa. */
    private static function aplicarPoder(array $pedido): array
    {
        $id = (int)$pedido['codimportacao'];
        $u = self::universal();
        self::acertarRelogio((string)($pedido['aplicacao_inicio'] ?? ''));
        self::anotar($id, 'Aplicação iniciada.');
        $tot = ['APLICADO' => 0, 'IGNORADO' => 0, 'ERRO' => 0];
        $marcar = static function (int $coditem, string $status, string $msg) use ($u, &$tot): void {
            $tot[$status]++;
            self::chamar($u, 'CALL sp_crud_update(?, ?, ?)', ['legislacao_importacao_itens', self::json(['coditem' => $coditem]), self::json(['status' => $status, 'mensagem' => mb_substr($msg, 0, 500), 'aplicado_em' => self::agora()])]);
        };
        foreach (self::itens($id) as $it) {
            if ($it['status'] !== 'PENDENTE') continue;
            $coditem = (int)$it['coditem'];
            if ($it['acao'] === 'RETIRAR' && (int)$it['selecionado'] !== 1) { $marcar($coditem, 'IGNORADO', 'Retirada não marcada: controle mantido.'); continue; }
            try {
                $pdo = LegislacaoEmpresas::pdo((int)$it['codempresa']);
                $lei = (int)$it['codlegislacao_base'];
                $un = (int)$it['codunidade'];
                $hoje = array_values(array_unique(array_map(static fn($r) => (string)(array_change_key_case($r, CASE_LOWER)['poder'] ?? ''),
                    array_filter(self::ler($pdo, 'legislacaoxpoder', ['CODLEGISLACAO' => $lei, 'codunidade' => $un]), static fn($r) => (int)(array_change_key_case($r, CASE_LOWER)['codunidade'] ?? 0) === $un && (int)(array_change_key_case($r, CASE_LOWER)['codlegislacao'] ?? 0) === $lei))));
                $tirar = static function (string $poder) use ($pdo, $lei, $un): void {
                    self::chamar($pdo, 'CALL sp_crud_delete(?, ?)', ['legislacaoxpoder', self::json(['CODLEGISLACAO' => $lei, 'poder' => $poder, 'codunidade' => $un])]);
                };
                $leiTxt = $it['codigo_lei'] . ($it['codigo_novo'] ? ' (' . $it['codigo_novo'] . ')' : '');
                if ($it['acao'] === 'RETIRAR') {
                    if (!in_array((string)$it['tipo_atual'], $hoje, true)) { $marcar($coditem, 'IGNORADO', 'O controle ' . $it['tipo_atual'] . ' já não existia.'); continue; }
                    $tirar((string)$it['tipo_atual']);
                    $marcar($coditem, 'APLICADO', $leiTxt . ': ' . $it['tipo_atual'] . ' retirado.');
                    continue;
                }
                $novo = (string)$it['tipo_novo'];
                if (in_array($novo, $hoje, true) && count($hoje) === 1) { $marcar($coditem, 'IGNORADO', $leiTxt . ': já estava ' . $novo . '.'); continue; }
                $antes = array_values(array_filter($hoje, static fn($x) => $x !== $novo));
                foreach ($antes as $x) $tirar($x);
                if (!in_array($novo, $hoje, true)) self::chamar($pdo, 'CALL sp_crud_insert(?, ?)', ['legislacaoxpoder', self::json(['CODLEGISLACAO' => $lei, 'poder' => $novo, 'codunidade' => $un])]);
                $marcar($coditem, 'APLICADO', $leiTxt . ': ' . ($antes ? implode('/', $antes) . ' trocado para ' : '') . $novo . ($antes ? '' : ' incluído') . '.');
            } catch (Throwable $e) {
                $marcar($coditem, 'ERRO', 'Erro: ' . $e->getMessage());
            }
        }
        $status = $tot['ERRO'] > 0 ? 'APLICADA_COM_ERROS' : 'APLICADA';
        self::anotar($id, sprintf('Aplicação concluída: %d aplicado(s), %d ignorado(s), %d erro(s).', $tot['APLICADO'], $tot['IGNORADO'], $tot['ERRO']), [
            'status' => $status, 'aplicacao_fim' => self::agora(), 'total_aplicados' => $tot['APLICADO'], 'total_ignorados' => $tot['IGNORADO'], 'total_erros' => $tot['ERRO'],
        ]);
        return $tot + ['status' => $status, 'padroes' => 0];
    }

    // ================================================================== Alterações da legislação (migração 402)
    /** Situações aceitas na coluna "situação da Lei" (gravadas como no legado). */
    public const SITUACOES = ['LEI INCLUSA' => 'Lei Inclusa', 'LEI ALTERADA' => 'Lei Alterada', 'LEI REVOGADA' => 'Lei Revogada'];

    /** Normalização do script legado (v3.2) para comparar alterações: sem NUL, NBSP, quebras e espaços repetidos; sem caixa. */
    public static function chaveAlteracao(?string $t): string
    {
        $t = str_replace(["\0", "\xC2\xA0"], ['', ' '], (string)$t);
        $t = trim((string)preg_replace('/\s+/u', ' ', $t));
        return mb_strtolower($t, 'UTF-8');
    }

    private static function dataPlanilha(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') return null;
        if (is_numeric($v) && (float)$v > 20000 && (float)$v < 80000) return gmdate('Y-m-d', (int)round(((float)$v - 25569) * 86400)); // série do Excel
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $v, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return "$m[1]-$m[2]-$m[3]";
        return null;
    }

    /**
     * Planilha mensal de alterações (substitui migracao_legislacaoxalteracoes_v3_2.php). Colunas pelo cabeçalho: Código,
     * devido, descrição, tipo de alteração, situação da Lei, Data de Alteração. Regras do legado:
     *  - cada linha atua SÓ na lei de código exato (não nos padrões);
     *  - lei que não existe → criada (código, situação, devido, PADRAO vazio, data de atualização = hoje) — NOVA_LEI;
     *  - lei que existe → data de atualização = hoje, devido e situação da planilha — ATUALIZAR;
     *  - Lei Alterada/Revogada com tipo → alteração (tipo, descrição, data = coluna F ou hoje), se não existir uma igual para a
     *    mesma lei (comparação sem espaços extras, quebras e caixa) — ALTERACAO. Lei Inclusa não gera alteração.
     */
    private static function compararAlteracao(array $linhas, int $nLinhaCab, array $cab): array
    {
        $problemas = [];
        $prob = static function (string $tipo, string $texto, array $extra = []) use (&$problemas): void { $problemas[] = ['tipo' => $tipo, 'texto' => $texto] + $extra; };
        // colunas pelo nome do cabeçalho (senão a posição do modelo)
        $col = ['codigo' => 1, 'devido' => 2, 'descricao' => 3, 'tipo' => 4, 'situacao' => 5, 'data' => 6];
        foreach ($cab as $c => $nome) {
            $k = self::chave((string)$nome);
            if ($k === 'CODIGO') $col['codigo'] = (int)$c;
            elseif (str_starts_with($k, 'DEVIDO')) $col['devido'] = (int)$c;
            elseif (str_starts_with($k, 'DESCRI')) $col['descricao'] = (int)$c;
            elseif (str_starts_with($k, 'TIPO')) $col['tipo'] = (int)$c;
            elseif (str_starts_with($k, 'SITUA')) $col['situacao'] = (int)$c;
            elseif (str_starts_with($k, 'DATA')) $col['data'] = (int)$c;
        }
        // formato
        $motivos = []; $comCodigo = 0; $sitValidas = 0; $sitPreenchidas = 0; $exSit = [];
        foreach ($linhas as $cells) {
            if (trim((string)($cells[$col['codigo']] ?? '')) === '') continue;
            $comCodigo++;
            $s = trim((string)($cells[$col['situacao']] ?? ''));
            if ($s === '') continue;
            $sitPreenchidas++;
            if (isset(self::SITUACOES[self::chave($s)])) $sitValidas++; elseif (count($exSit) < 3) $exSit[] = '"' . mb_substr($s, 0, 25) . '"';
        }
        if ($comCodigo === 0) $motivos[] = 'a coluna "Código" não tem códigos de lei';
        if ($sitPreenchidas === 0) $motivos[] = 'a coluna "situação da Lei" está vazia (use Lei Inclusa, Lei Alterada ou Lei Revogada)';
        elseif ($sitValidas / $sitPreenchidas < self::MIN_CELULAS_VALIDAS) $motivos[] = sprintf('só %d de %d situações são Lei Inclusa, Lei Alterada ou Lei Revogada; exemplos de outros valores: %s', $sitValidas, $sitPreenchidas, implode(', ', $exSit));
        if ($motivos) throw new PlanilhaInvalida(self::recusa($motivos, 'ALTERACAO'));

        // leis (código exato)
        $leg = self::legislacao();
        $lei = []; // código => [id, situacao, devido]
        foreach (self::ler($leg, 'legislacao') as $l) {
            $l = array_change_key_case($l, CASE_LOWER);
            $cod = strtoupper(trim((string)($l['codigo'] ?? '')));
            if ($cod === '' || (isset($lei[$cod]) && $lei[$cod]['id'] < (int)$l['codlegislacao'])) continue;
            $lei[$cod] = ['id' => (int)$l['codlegislacao'], 'situacao' => (string)($l['situacao_da_lei'] ?? ''), 'devido' => (string)($l['devido_da_lei'] ?? '')];
        }
        $itens = []; $vistos = []; $existentes = []; $novas = 0; $atualizadas = 0; $alteracoes = 0;
        $altCache = [];
        $altExistentes = static function (int $id) use (&$altCache, $leg): array {
            if (!isset($altCache[$id])) {
                $altCache[$id] = [];
                foreach (self::ler($leg, 'legislacaoxalteracao', ['codlegislacao' => $id]) as $a) {
                    $a = array_change_key_case($a, CASE_LOWER);
                    if ((int)($a['codlegislacao'] ?? 0) === $id) $altCache[$id][self::chaveAlteracao((string)($a['tipo'] ?? '')) . '|' . self::chaveAlteracao((string)($a['descricao'] ?? ''))] = true;
                }
            }
            return $altCache[$id];
        };
        foreach ($linhas as $n => $cells) {
            $cod = strtoupper(trim((string)($cells[$col['codigo']] ?? '')));
            if ($cod === '') { if (array_filter($cells, static fn($v) => trim((string)$v) !== '')) $prob('LINHA_SEM_CODIGO', 'Linha ' . $n . ': sem código da lei. Linha ignorada.', ['linha' => $n]); continue; }
            if (isset($vistos[$cod])) { $prob('LEI_REPETIDA', 'Linha ' . $n . ': a lei ' . $cod . ' já está na linha ' . $vistos[$cod] . '. Vale a primeira; esta linha foi ignorada.', ['linha' => $n, 'codigo' => $cod]); continue; }
            $vistos[$cod] = $n;
            $sitBruta = trim((string)($cells[$col['situacao']] ?? ''));
            $sit = self::SITUACOES[self::chave($sitBruta)] ?? null;
            if ($sit === null) { $prob('SITUACAO_INVALIDA', 'Linha ' . $n . ' (' . $cod . '): situação "' . $sitBruta . '" inválida; use Lei Inclusa, Lei Alterada ou Lei Revogada. Linha ignorada.', ['linha' => $n, 'codigo' => $cod]); continue; }
            $devido = trim((string)($cells[$col['devido']] ?? ''));
            $tipoAlt = trim(str_replace("\0", '', (string)($cells[$col['tipo']] ?? '')));
            $descricao = trim(str_replace("\0", '', (string)($cells[$col['descricao']] ?? '')));
            $dataBruta = trim((string)($cells[$col['data']] ?? ''));
            $data = self::dataPlanilha($dataBruta);
            if ($dataBruta !== '' && $data === null) $prob('DATA_INVALIDA', 'Linha ' . $n . ' (' . $cod . '): data de alteração "' . $dataBruta . '" não reconhecida; será usada a data da aplicação.', ['linha' => $n, 'aviso' => 1]);
            $comum = ['codempresa' => 0, 'empresa_nome' => '', 'codunidade' => 0, 'unidade_nome' => '', 'linha' => $n, 'coluna' => 'A', 'codigo_lei' => $cod];
            $existe = $lei[$cod] ?? null;
            if ($existe === null) {
                if ($sit !== 'Lei Inclusa') $prob('LEI_NOVA_ALTERADA', 'Linha ' . $n . ': a lei ' . $cod . ' não existe e está como ' . $sit . '; será criada (como no legado) e receberá a alteração.', ['linha' => $n, 'codigo' => $cod, 'aviso' => 1]);
                $itens[] = $comum + ['acao' => 'NOVA_LEI', 'tipo_novo' => $sit, 'codigo_novo' => mb_substr($devido, 0, 90)];
                $novas++;
            } else {
                $itens[] = $comum + ['acao' => 'ATUALIZAR', 'codlegislacao_base' => $existe['id'], 'tipo_atual' => mb_substr($existe['situacao'], 0, 20), 'codigo_atual' => mb_substr($existe['devido'], 0, 90), 'tipo_novo' => $sit, 'codigo_novo' => mb_substr($devido, 0, 90)];
                $atualizadas++;
            }
            if ($sit === 'Lei Inclusa') continue;
            if ($tipoAlt === '') { $prob('ALTERACAO_SEM_TIPO', 'Linha ' . $n . ' (' . $cod . ', ' . $sit . '): sem tipo de alteração; a lei é ' . ($existe ? 'atualizada' : 'criada') . ', mas a alteração não é incluída.', ['linha' => $n, 'codigo' => $cod, 'aviso' => 1]); continue; }
            if ($existe !== null && isset($altExistentes($existe['id'])[self::chaveAlteracao($tipoAlt) . '|' . self::chaveAlteracao($descricao)])) { $existentes[] = ['linha' => $n, 'codigo' => $cod, 'tipo' => $tipoAlt]; continue; }
            $itens[] = $comum + ['acao' => 'ALTERACAO', 'codlegislacao_base' => $existe['id'] ?? null, 'alt_tipo' => mb_substr($tipoAlt, 0, 250), 'alt_descricao' => $descricao, 'alt_data' => $data, 'tipo_novo' => $sit];
            $alteracoes++;
        }
        $resumo = ['linhas_planilha' => count($linhas), 'leis_validas' => count($vistos), 'colunas_planilha' => count($cab), 'unidades_validas' => 0, 'padroes' => [], 'por_empresa' => [],
            'unidades' => [], 'unidades_desconsideradas' => [], 'alteracoes_existentes' => $existentes, 'leis_novas' => $novas];
        return ['itens' => $itens, 'problemas' => $problemas, 'padroes' => [],
            'totais' => ['INCLUIR' => $novas, 'TROCAR' => $atualizadas, 'RETIRAR' => 0, 'MANTER' => count($existentes), 'ALTERACAO' => $alteracoes], 'resumo' => $resumo];
    }

    /** Aplica um pedido de alterações: tudo numa transação no banco da legislação (erro em qualquer item desfaz tudo, como no legado). */
    private static function aplicarAlteracao(array $pedido): array
    {
        $id = (int)$pedido['codimportacao'];
        $u = self::universal(); $leg = self::legislacao();
        self::acertarRelogio((string)($pedido['aplicacao_inicio'] ?? ''));
        self::anotar($id, 'Aplicação iniciada.');
        $agora = self::agora();
        $itens = array_values(array_filter(self::itens($id), static fn($i) => $i['status'] === 'PENDENTE'));
        usort($itens, static fn($a, $b) => [array_search($a['acao'], ['NOVA_LEI', 'ATUALIZAR', 'ALTERACAO'], true), (int)$a['linha']] <=> [array_search($b['acao'], ['NOVA_LEI', 'ATUALIZAR', 'ALTERACAO'], true), (int)$b['linha']]);
        $res = []; $criadas = [];
        $leiPorCodigo = static function (string $codigo) use ($leg): ?int {
            $menor = null;
            foreach (self::ler($leg, 'legislacao', ['CODIGO' => $codigo]) as $l) { $l = array_change_key_case($l, CASE_LOWER); if (strtoupper(trim((string)$l['codigo'])) === strtoupper($codigo)) $menor = $menor === null ? (int)$l['codlegislacao'] : min($menor, (int)$l['codlegislacao']); }
            return $menor;
        };
        $erro = null;
        $leg->beginTransaction();
        try {
            foreach ($itens as $it) {
                $cod = (string)$it['codigo_lei']; $c = (int)$it['coditem'];
                if ($it['acao'] === 'NOVA_LEI') {
                    $ja = $leiPorCodigo($cod);
                    if ($ja !== null) {
                        self::chamar($leg, 'CALL sp_crud_update(?, ?, ?)', ['legislacao', self::json(['CODLEGISLACAO' => $ja]), self::json(['data_atualizacao' => $agora, 'devido_da_lei' => (string)$it['codigo_novo'], 'situacao_da_lei' => (string)$it['tipo_novo']])]);
                        $criadas[$cod] = $ja; $res[$c] = ['APLICADO', 'A lei já existia (criada depois da conferência): atualizada.']; continue;
                    }
                    $r = self::chamar($leg, 'CALL sp_crud_insert(?, ?)', ['legislacao', self::json(['CODIGO' => $cod, 'PADRAO' => '', 'data_atualizacao' => $agora, 'situacao_da_lei' => (string)$it['tipo_novo'], 'devido_da_lei' => (string)$it['codigo_novo']])]);
                    $novo = (int)($r[0]['chave_gerada'] ?? 0) ?: (int)$leiPorCodigo($cod);
                    if ($novo <= 0) throw new RuntimeException("a lei $cod não foi criada");
                    $criadas[$cod] = $novo; $res[$c] = ['APLICADO', "Lei criada (codlegislacao $novo). Complete o cadastro em Legislação."];
                } elseif ($it['acao'] === 'ATUALIZAR') {
                    self::chamar($leg, 'CALL sp_crud_update(?, ?, ?)', ['legislacao', self::json(['CODLEGISLACAO' => (int)$it['codlegislacao_base']]), self::json(['data_atualizacao' => $agora, 'devido_da_lei' => (string)$it['codigo_novo'], 'situacao_da_lei' => (string)$it['tipo_novo']])]);
                    $res[$c] = ['APLICADO', 'Situação: ' . ($it['tipo_novo'] ?? '') . '; data de atualização = hoje.'];
                } else {
                    $leiId = (int)($it['codlegislacao_base'] ?? 0) ?: (int)($criadas[$cod] ?? $leiPorCodigo($cod) ?? 0);
                    if ($leiId <= 0) throw new RuntimeException("lei $cod não encontrada para a alteração");
                    $chave = self::chaveAlteracao((string)$it['alt_tipo']) . '|' . self::chaveAlteracao((string)$it['alt_descricao']);
                    $dup = false;
                    foreach (self::ler($leg, 'legislacaoxalteracao', ['codlegislacao' => $leiId]) as $a) { $a = array_change_key_case($a, CASE_LOWER); if ((int)$a['codlegislacao'] === $leiId && self::chaveAlteracao((string)($a['tipo'] ?? '')) . '|' . self::chaveAlteracao((string)($a['descricao'] ?? '')) === $chave) { $dup = true; break; } }
                    if ($dup) { $res[$c] = ['IGNORADO', 'A alteração já existia.']; continue; }
                    self::chamar($leg, 'CALL sp_crud_insert(?, ?)', ['legislacaoxalteracao', self::json(['codlegislacao' => $leiId, 'tipo' => (string)$it['alt_tipo'], 'descricao' => (string)$it['alt_descricao'], 'data_da_alteracao' => $it['alt_data'] ?: $agora])]);
                    $res[$c] = ['APLICADO', 'Alteração incluída (' . $it['alt_tipo'] . ').'];
                }
            }
            $leg->commit();
        } catch (Throwable $e) {
            if ($leg->inTransaction()) $leg->rollBack();
            $erro = $e->getMessage();
        }
        $tot = ['APLICADO' => 0, 'IGNORADO' => 0, 'ERRO' => 0];
        foreach ($itens as $it) {
            [$st, $msg] = $erro === null ? ($res[(int)$it['coditem']] ?? ['IGNORADO', '']) : ['ERRO', 'Nada foi gravado (a aplicação foi desfeita): ' . $erro];
            $tot[$st]++;
            self::chamar($u, 'CALL sp_crud_update(?, ?, ?)', ['legislacao_importacao_itens', self::json(['coditem' => (int)$it['coditem']]), self::json(['status' => $st, 'mensagem' => mb_substr($msg, 0, 500), 'aplicado_em' => self::agora()])]);
        }
        $status = $erro === null ? 'APLICADA' : 'APLICADA_COM_ERROS';
        self::anotar($id, $erro === null ? sprintf('Aplicação concluída: %d aplicado(s), %d ignorado(s); %d lei(s) criada(s).', $tot['APLICADO'], $tot['IGNORADO'], count($criadas)) : 'Aplicação desfeita por erro: ' . $erro,
            ['status' => $status, 'aplicacao_fim' => self::agora(), 'total_aplicados' => $tot['APLICADO'], 'total_ignorados' => $tot['IGNORADO'], 'total_erros' => $tot['ERRO']]);
        return $tot + ['status' => $status, 'padroes' => 0];
    }

    private static function recusa(array $motivos, string $tipo = 'EMPRESA'): string
    {
        $inicio = 'A planilha não pode ser importada em ' . self::PEDIDOS[$tipo] . ", e nada foi gravado:\n- " . implode(";\n- ", $motivos);
        if ($tipo === 'ALTERACAO') return $inicio . ".\nFormato esperado: cabeçalho com \"Código\" na coluna A e as colunas devido, descrição, tipo de alteração, situação da Lei e Data de Alteração; situação = Lei Inclusa, Lei Alterada ou Lei Revogada.";
        return $inicio
            . ".\nFormato esperado: uma linha de cabeçalho (linha 1, ou linha 2 abaixo do título) com \"Codigo\" na coluna A e o nome das unidades nas colunas seguintes; abaixo dela, o código da lei na coluna A e, nas células, " . self::valoresTexto($tipo) . '.';
    }

    public static function statusTexto(string $s): string
    {
        return ['CONFERIDA' => 'aguardando autorização', 'AUTORIZADA' => 'autorizado, na fila', 'APLICANDO' => 'aplicando', 'APLICADA' => 'aplicado', 'APLICADA_COM_ERROS' => 'aplicado com erros', 'DESCARTADA' => 'descartado'][$s] ?? $s;
    }

    // ================================================================== consultas da tela
    public static function pedidos(int $limite = 50, string $tipo = 'EMPRESA'): array
    {
        $r = array_values(array_filter(self::chamar(self::universal(), 'CALL sp_crud_select(?, NULL, NULL, ?, 0)', ['vw_legislacao_importacoes', 1000]), static fn($p) => self::tipoPedido($p['tipo'] ?? null) === $tipo));
        usort($r, static fn($a, $b) => (int)$b['codimportacao'] <=> (int)$a['codimportacao']);
        return array_slice($r, 0, $limite);
    }

    public static function pedido(int $id): ?array
    {
        return self::ler(self::universal(), 'vw_legislacao_importacoes', ['codimportacao' => $id], 1)[0] ?? null;
    }

    public static function itens(int $id): array
    {
        $r = self::ler(self::universal(), 'vw_legislacao_importacao_itens', ['codimportacao' => $id]);
        usort($r, static fn($a, $b) => [$a['empresa_nome'], $a['unidade_nome'], $a['codigo_lei']] <=> [$b['empresa_nome'], $b['unidade_nome'], $b['codigo_lei']]);
        return $r;
    }

    public static function marcarRetiradas(int $id, array $itens): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $itens), static fn($v) => $v > 0)));
        return (int)(self::chamar(self::universal(), 'CALL sp_legimp_marcar_retiradas(?, ?)', [$id, self::json($ids)])[0]['marcadas'] ?? 0);
    }

    public static function autorizar(int $id, int $usuario, string $nome, array $retiradas): void
    {
        self::marcarRetiradas($id, $retiradas);
        self::chamar(self::universal(), 'CALL sp_legimp_autorizar(?, ?, ?)', [$id, $usuario, mb_substr($nome, 0, 150)]);
        self::anotar($id, 'Autorizado por ' . $nome . ' (' . count($retiradas) . ' retirada(s) marcada(s)). Aguardando o serviço de aplicação.');
    }

    public static function descartar(int $id, int $usuario, string $nome): void
    {
        self::chamar(self::universal(), 'CALL sp_legimp_descartar(?, ?, ?)', [$id, $usuario, mb_substr($nome, 0, 150)]);
        self::anotar($id, 'Descartado por ' . $nome . '. Nada foi gravado.');
    }

    private static function anotar(int $id, string $texto, array $outros = []): void
    {
        $p = self::pedido($id);
        if (self::$desvio === null) self::acertarRelogio((string)($p['enviado_em'] ?? ''));
        $log = (string)($p['log'] ?? '') . '[' . self::agora('d/m/Y H:i:s') . '] ' . $texto . "\n";
        self::chamar(self::universal(), 'CALL sp_crud_update(?, ?, ?)', ['legislacao_importacoes', self::json(['codimportacao' => $id]), self::json(['log' => $log] + $outros)]);
    }

    // ================================================================== 2. aplicação (worker)
    /** Reserva o próximo pedido autorizado (ou null). */
    public static function reservar(): ?array
    {
        $r = self::chamar(self::universal(), 'CALL sp_legimp_reservar()');
        return isset($r[0]['codimportacao']) ? $r[0] : null;
    }

    /** Aplica os itens PENDENTE do pedido (INCLUIR, TROCAR e as RETIRAR marcadas). Devolve os totais. */
    public static function aplicar(array $pedido): array
    {
        if (self::tipoPedido($pedido['tipo'] ?? null) === 'PODER') return self::aplicarPoder($pedido);
        if (self::tipoPedido($pedido['tipo'] ?? null) === 'ALTERACAO') return self::aplicarAlteracao($pedido);
        $id = (int)$pedido['codimportacao'];
        $u = self::universal();
        $leg = self::legislacao();
        self::acertarRelogio((string)($pedido['aplicacao_inicio'] ?? ''));
        self::anotar($id, 'Aplicação iniciada.');
        $tot = ['APLICADO' => 0, 'IGNORADO' => 0, 'ERRO' => 0];
        $agora = self::agora();
        $leiPorCodigo = static function (string $codigo) use ($leg): ?int {
            $menor = null;
            foreach (self::ler($leg, 'legislacao', ['CODIGO' => $codigo]) as $l) { // filtro de texto é LIKE: confere o código exato
                $l = array_change_key_case($l, CASE_UPPER);
                if (strtoupper(trim((string)$l['CODIGO'])) === strtoupper($codigo)) $menor = $menor === null ? (int)$l['CODLEGISLACAO'] : min($menor, (int)$l['CODLEGISLACAO']);
            }
            return $menor;
        };
        $criados = [];
        $datadas = [];
        $marcar = static function (int $coditem, string $status, string $msg) use ($u, &$tot): void {
            $tot[$status]++;
            self::chamar($u, 'CALL sp_crud_update(?, ?, ?)', ['legislacao_importacao_itens', self::json(['coditem' => $coditem]), self::json(['status' => $status, 'mensagem' => mb_substr($msg, 0, 500), 'aplicado_em' => self::agora()])]);
        };
        foreach (self::itens($id) as $it) {
            if ($it['status'] !== 'PENDENTE') continue;
            $coditem = (int)$it['coditem'];
            if ($it['acao'] === 'RETIRAR' && (int)$it['selecionado'] !== 1) { $marcar($coditem, 'IGNORADO', 'Retirada não marcada: vínculo mantido.'); continue; }
            try {
                $emp = (int)$it['codempresa'];
                $pdo = LegislacaoEmpresas::pdo($emp);
                $unidade = (int)$it['codunidade'];
                $vinculosLei = static fn(int $codleg): array => array_values(array_filter(self::ler($pdo, 'legislacaoxempresa', ['CODLEGISLACAO' => $codleg, 'CODEMPRESA' => $emp]), static fn($v) => (int)(array_change_key_case($v, CASE_LOWER)['codunidade'] ?? 0) === $unidade));
                if ($it['acao'] === 'RETIRAR') {
                    $atual = array_filter($vinculosLei((int)$it['codlegislacao_atual']), static fn($v) => (int)(array_change_key_case($v, CASE_UPPER)['CODLEGISLACAXEMPRESA'] ?? 0) === (int)$it['codlegxemp']);
                    if (!$atual) { $marcar($coditem, 'IGNORADO', 'O vínculo já não existia.'); continue; }
                    self::chamar($pdo, 'CALL sp_crud_delete(?, ?)', ['legislacaoxempresa', self::json(['CODLEGISLACAXEMPRESA' => (int)$it['codlegxemp']])]);
                    $marcar($coditem, 'APLICADO', 'Vínculo ' . $it['codigo_atual'] . ' retirado.');
                    continue;
                }
                // INCLUIR / TROCAR: lei de destino (cria o padrão se ainda não existir)
                $tipo = (string)$it['tipo_novo'];
                $codAlvo = (string)$it['codigo_novo'];
                $destino = $tipo === 'MATRIZ' ? (int)$it['codlegislacao_base'] : ($criados[$codAlvo] ?? $leiPorCodigo($codAlvo));
                $msgPadrao = '';
                if ($destino === null) {
                    $destino = self::criarPadrao((int)$it['codlegislacao_base'], $codAlvo);
                    $criados[$codAlvo] = $destino;
                    $msgPadrao = ' Padrão ' . $codAlvo . ' criado.';
                    self::anotar($id, 'Padrão ' . $codAlvo . ' criado (codlegislacao ' . $destino . ').');
                }
                $jaTem = array_filter($vinculosLei($destino), static fn($v) => (strtoupper(trim((string)(array_change_key_case($v, CASE_LOWER)['tipo_de_lei'] ?? ''))) ?: 'MATRIZ') === $tipo);
                if ($it['acao'] === 'INCLUIR') {
                    if ($jaTem) { $marcar($coditem, 'IGNORADO', 'O vínculo já existia.' . $msgPadrao); continue; }
                    self::chamar($pdo, 'CALL sp_crud_insert(?, ?)', ['legislacaoxempresa', self::json(['CODLEGISLACAO' => $destino, 'CODEMPRESA' => $emp, 'codunidade' => $unidade, 'tipo_de_lei' => $tipo])]);
                    $msg = 'Vínculo ' . $codAlvo . ' incluído.' . $msgPadrao;
                } else {
                    $atual = array_filter($vinculosLei((int)$it['codlegislacao_atual']), static fn($v) => (int)(array_change_key_case($v, CASE_UPPER)['CODLEGISLACAXEMPRESA'] ?? 0) === (int)$it['codlegxemp']);
                    if (!$atual) {
                        if ($jaTem) { $marcar($coditem, 'IGNORADO', 'O vínculo ' . $codAlvo . ' já existia.' . $msgPadrao); continue; }
                        self::chamar($pdo, 'CALL sp_crud_insert(?, ?)', ['legislacaoxempresa', self::json(['CODLEGISLACAO' => $destino, 'CODEMPRESA' => $emp, 'codunidade' => $unidade, 'tipo_de_lei' => $tipo])]);
                        $msg = 'O vínculo antigo (' . $it['codigo_atual'] . ') já não existia: ' . $codAlvo . ' incluído.' . $msgPadrao;
                    } elseif ($jaTem) {
                        self::chamar($pdo, 'CALL sp_crud_delete(?, ?)', ['legislacaoxempresa', self::json(['CODLEGISLACAXEMPRESA' => (int)$it['codlegxemp']])]);
                        $msg = $codAlvo . ' já estava vinculado: o vínculo antigo (' . $it['codigo_atual'] . ', ' . $it['tipo_atual'] . ') foi retirado.' . $msgPadrao;
                    } else {
                        self::chamar($pdo, 'CALL sp_crud_update(?, ?, ?)', ['legislacaoxempresa', self::json(['CODLEGISLACAXEMPRESA' => (int)$it['codlegxemp']]), self::json(['CODLEGISLACAO' => $destino, 'tipo_de_lei' => $tipo])]);
                        $msg = 'Tipo trocado: ' . $it['codigo_atual'] . ' (' . $it['tipo_atual'] . ') para ' . $codAlvo . ' (' . $tipo . ').' . $msgPadrao;
                    }
                }
                // Data de atualização = agora (como o legado): a lei entra em "Normas Inseridas" da RCL do mês
                if (!isset($datadas[$destino])) {
                    self::chamar($leg, 'CALL sp_crud_update(?, ?, ?)', ['legislacao', self::json(['CODLEGISLACAO' => $destino]), self::json(['data_atualizacao' => $agora])]);
                    $datadas[$destino] = true;
                }
                $marcar($coditem, 'APLICADO', $msg);
            } catch (Throwable $e) {
                $marcar($coditem, 'ERRO', 'Erro: ' . $e->getMessage());
            }
        }
        $status = $tot['ERRO'] > 0 ? 'APLICADA_COM_ERROS' : 'APLICADA';
        self::anotar($id, sprintf('Aplicação concluída: %d aplicado(s), %d ignorado(s), %d erro(s); %d padrão(ões) criado(s).', $tot['APLICADO'], $tot['IGNORADO'], $tot['ERRO'], count($criados)), [
            'status' => $status, 'aplicacao_fim' => self::agora(), 'total_aplicados' => $tot['APLICADO'], 'total_ignorados' => $tot['IGNORADO'], 'total_erros' => $tot['ERRO'],
        ]);
        return $tot + ['status' => $status, 'padroes' => count($criados)];
    }

    /** Falha geral da aplicação (o worker chama): status APLICADA_COM_ERROS com o motivo no log. */
    public static function falhar(int $id, string $erro): void
    {
        self::anotar($id, 'Aplicação interrompida: ' . $erro, ['status' => 'APLICADA_COM_ERROS', 'aplicacao_fim' => self::agora()]);
    }

    /**
     * Padrão novo: cópia da lei principal com o código + letra (PADRAO vazio, como no legado: o texto do padrão é de cada
     * unidade) e as associações da lei principal no banco da legislação (as mesmas que o grid copia).
     */
    public static function criarPadrao(int $principal, string $codigo): int
    {
        $leg = self::legislacao();
        $orig = self::ler($leg, 'legislacao', ['CODLEGISLACAO' => $principal], 1)[0] ?? null;
        if (!$orig) throw new RuntimeException('Lei principal ' . $principal . ' não encontrada.');
        $colunas = self::colunasInclusao($leg, 'legislacao');
        $dados = [];
        foreach ($orig as $c => $v) {
            if (!isset($colunas[strtolower($c)])) continue;
            $u = strtoupper($c);
            $dados[$c] = $u === 'CODIGO' ? $codigo : ($u === 'PADRAO' ? '' : $v);
        }
        $r = self::chamar($leg, 'CALL sp_crud_insert(?, ?)', ['legislacao', self::json($dados)]);
        $novo = (int)($r[0]['chave_gerada'] ?? 0);
        if ($novo <= 0) {
            foreach (self::ler($leg, 'legislacao', ['CODIGO' => $codigo]) as $l) { $l = array_change_key_case($l, CASE_UPPER); if (strtoupper(trim((string)$l['CODIGO'])) === strtoupper($codigo)) $novo = max($novo, (int)$l['CODLEGISLACAO']); }
        }
        if ($novo <= 0) throw new RuntimeException('O padrão ' . $codigo . ' não foi criado.');
        foreach (self::TABELAS_PADRAO as $t) {
            $cols = self::colunasInclusao($leg, $t);
            $chaveLei = null; foreach ($cols as $lc => $real) if ($lc === 'codlegislacao') $chaveLei = $real;
            if ($chaveLei === null) continue;
            foreach (self::ler($leg, $t, [$chaveLei => $principal]) as $linha) {
                if ((int)(array_change_key_case($linha, CASE_LOWER)['codlegislacao'] ?? 0) !== $principal) continue;
                $d = [];
                foreach ($linha as $c => $v) if (isset($cols[strtolower($c)])) $d[$c] = strtolower($c) === 'codlegislacao' ? $novo : $v;
                self::chamar($leg, 'CALL sp_crud_insert(?, ?)', [$t, self::json($d)]);
            }
        }
        return $novo;
    }

    /** Colunas que entram numa inclusão (sem a de auto incremento): [minúscula => nome real]. Metadado, lido do information_schema. */
    private static function colunasInclusao(PDO $pdo, string $tabela): array
    {
        static $cache = [];
        $k = spl_object_id($pdo) . ':' . $tabela;
        if (isset($cache[$k])) return $cache[$k];
        $s = $pdo->prepare("SELECT COLUMN_NAME, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
        $s->execute([$tabela]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $c) if (stripos((string)$c['EXTRA'], 'auto_increment') === false && stripos((string)$c['EXTRA'], 'generated') === false) $out[strtolower((string)$c['COLUMN_NAME'])] = (string)$c['COLUMN_NAME'];
        $s->closeCursor();
        return $cache[$k] = $out;
    }

    // ================================================================== alerta
    /** Alerta para quem enviou (e quem autorizou, se outro), no banco da empresa de origem. */
    public static function alertar(array $pedido, string $titulo, string $mensagem): void
    {
        $banco = (string)($pedido['banco_origem'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $banco)) return;
        try {
            $pdo = GreenPortalDatabase::connect(GreenPortalDatabase::env(), $banco, 'GPT');
            $link = '/principal/manutencaolegal_legislacaoxempresa_importacao.php?codimportacao=' . (int)$pedido['codimportacao'];
            foreach (array_unique(array_filter([(int)($pedido['enviado_por'] ?? 0), (int)($pedido['autorizado_por'] ?? 0)])) as $usuario) {
                self::chamar($pdo, 'CALL sp_crud_insert(?, ?)', ['alertas', self::json(['codusuario' => $usuario, 'titulo' => $titulo, 'mensagem' => $mensagem, 'link_status' => $link, 'lido' => 0])]);
            }
        } catch (Throwable) {
            // sem alerta, o resultado continua na tela de importação
        }
    }
}
