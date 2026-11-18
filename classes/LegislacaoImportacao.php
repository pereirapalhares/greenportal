<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';
require_once __DIR__ . '/LegislacaoEmpresas.php';
require_once __DIR__ . '/PlanilhaLeitor.php';

/**
 * Migração 397 — importação da planilha mensal de Legislação x Empresas (substitui o legado legislacaoxempresax_v2.php).
 *
 * A planilha: coluna A = código da lei; linha 1 = nomes das unidades (uma por coluna, de qualquer empresa);
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

    private static function letraColuna(int $c): string
    {
        $s = ''; while ($c > 0) { $m = ($c - 1) % 26; $s = chr(65 + $m) . $s; $c = intdiv($c - 1, 26); } return $s;
    }

    // ================================================================== 1. conferência (não grava nada no cadastro)
    /**
     * Lê a planilha, compara com o portal e grava o pedido (status CONFERIDA) com os itens. Devolve o codimportacao.
     * @param array{codusuario:int,nome:string,codempresa:int,banco:string} $quem
     */
    public static function conferir(string $arquivo, string $nomeOriginal, array $quem): int
    {
        $hash = hash_file('sha256', $arquivo);
        $u = self::universal();
        // A mesma planilha (mesmo conteúdo) não pode ser enviada de novo enquanto houver pedido dela que não foi descartado
        foreach (self::ler($u, 'vw_legislacao_importacoes', ['arquivo_hash' => $hash]) as $p) {
            if ((string)$p['arquivo_hash'] !== $hash || $p['status'] === 'DESCARTADA') continue;
            throw new PlanilhaInvalida('Esta planilha já foi enviada no pedido ' . (int)$p['codimportacao'] . ' (' . $p['arquivo_nome'] . ', ' . self::statusTexto((string)$p['status']) . '). Abra o pedido na lista ou, para enviar de novo, descarte-o primeiro.');
        }
        $linhas = PlanilhaLeitor::ler($arquivo, $nomeOriginal);
        $c = self::comparar($linhas);
        $ref = null;
        if (preg_match('/(?<!\d)(0[1-9]|1[0-2])[-_.]?(20\d\d)(?!\d)/', $nomeOriginal, $m)) $ref = $m[2] . '-' . $m[1];
        if ($ref !== null) {
            foreach (self::ler($u, 'vw_legislacao_importacoes', ['referencia' => $ref]) as $p) {
                if ($p['status'] === 'DESCARTADA') continue;
                $c['problemas'][] = ['tipo' => 'MES_JA_ENVIADO', 'aviso' => 1, 'texto' => 'Já existe pedido do mês ' . substr($ref, 5, 2) . '/' . substr($ref, 0, 4) . ': pedido ' . (int)$p['codimportacao'] . ' (' . $p['arquivo_nome'] . ', ' . self::statusTexto((string)$p['status']) . '). Confira se esta é mesmo a planilha certa.'];
            }
        }
        $dados = [
            'arquivo_nome' => mb_substr($nomeOriginal !== '' ? $nomeOriginal : basename($arquivo), 0, 255), 'arquivo_hash' => $hash,
            'referencia' => $ref, 'status' => 'CONFERIDA',
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
        self::anotar($id, 'Planilha conferida por ' . $quem['nome'] . '.');
        return $id;
    }

    /**
     * Compara as linhas da planilha com o portal. Não grava nada.
     * @return array{itens:array,problemas:array,padroes:array,totais:array,resumo:array}
     */
    public static function comparar(array $linhas): array
    {
        $problemas = [];
        $prob = static function (string $tipo, string $texto, array $extra = []) use (&$problemas): void { $problemas[] = ['tipo' => $tipo, 'texto' => $texto] + $extra; };
        if (!$linhas) throw new RuntimeException('A planilha está vazia.');
        $nLinhaCab = (int)array_key_first($linhas);
        $cab = $linhas[$nLinhaCab];
        unset($linhas[$nLinhaCab]);

        // ---------------------------------------------------- formato (antes de consultar o banco)
        $motivos = [];
        if ($nLinhaCab !== 1) $motivos[] = 'a linha 1 está vazia: ela precisa ter "Codigo" na coluna A e o nome das unidades nas colunas seguintes';
        if (self::chave((string)($cab[1] ?? '')) !== 'CODIGO') $motivos[] = 'a célula A1 precisa ser "Codigo" (está "' . mb_substr((string)($cab[1] ?? ''), 0, 40) . '")';
        $nomesCab = array_filter($cab, static fn($v, $col) => $col >= 2 && trim((string)$v) !== '', ARRAY_FILTER_USE_BOTH);
        if (!$nomesCab) $motivos[] = 'a linha 1 não tem nomes de unidades a partir da coluna B';
        $numericos = array_filter($nomesCab, static fn($v) => is_numeric(str_replace([',', '.'], '', (string)$v)));
        if ($nomesCab && count($numericos) > count($nomesCab) / 2) $motivos[] = 'a linha 1 tem números no lugar dos nomes das unidades';
        $preenchidas = 0; $validas = 0; $comCodigo = 0; $invalidosEx = [];
        foreach ($linhas as $cells) {
            if (trim((string)($cells[1] ?? '')) !== '') $comCodigo++;
            foreach ($cells as $col => $v) {
                if ($col < 2) continue;
                $preenchidas++;
                if (in_array(strtoupper(trim((string)$v)), self::TIPOS, true)) $validas++;
                elseif (count($invalidosEx) < 3) $invalidosEx[] = '"' . mb_substr((string)$v, 0, 20) . '"';
            }
        }
        if ($comCodigo === 0) $motivos[] = 'a coluna A não tem códigos de lei';
        if ($preenchidas === 0) $motivos[] = 'nenhuma célula preenchida com MATRIZ ou letra a partir da coluna B';
        elseif ($validas / $preenchidas < self::MIN_CELULAS_VALIDAS) $motivos[] = sprintf('só %d de %d células preenchidas são MATRIZ ou uma letra de A a Z (mínimo %d%%); exemplos de outros valores: %s', $validas, $preenchidas, (int)(self::MIN_CELULAS_VALIDAS * 100), implode(', ', $invalidosEx));
        if ($motivos) throw new PlanilhaInvalida(self::recusa($motivos));

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
        if ($codigosPlanilha > 0 && $existentes / $codigosPlanilha < self::MIN_LEIS_EXISTENTES) $motivos[] = sprintf('só %d dos %d códigos da coluna A existem no cadastro da Legislação (mínimo %d%%)', $existentes, $codigosPlanilha, (int)(self::MIN_LEIS_EXISTENTES * 100));
        if (!$colunas) $motivos[] = 'nenhuma unidade da linha 1 existe no portal';
        if ($motivos) throw new PlanilhaInvalida(self::recusa($motivos));

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

    private static function recusa(array $motivos): string
    {
        return "Esta não parece ser a planilha de Legislação x Empresas, e nada foi gravado:\n- " . implode(";\n- ", $motivos)
            . ".\nFormato esperado: A1 = Codigo; coluna A = código da lei; linha 1 = nome das unidades; células = MATRIZ ou uma letra.";
    }

    public static function statusTexto(string $s): string
    {
        return ['CONFERIDA' => 'aguardando autorização', 'AUTORIZADA' => 'autorizado, na fila', 'APLICANDO' => 'aplicando', 'APLICADA' => 'aplicado', 'APLICADA_COM_ERROS' => 'aplicado com erros', 'DESCARTADA' => 'descartado'][$s] ?? $s;
    }

    // ================================================================== consultas da tela
    public static function pedidos(int $limite = 50): array
    {
        $r = self::chamar(self::universal(), 'CALL sp_crud_select(?, NULL, NULL, ?, 0)', ['vw_legislacao_importacoes', 1000]);
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
