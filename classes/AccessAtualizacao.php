<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';
require_once __DIR__ . '/LegislacaoEmpresas.php';
require_once __DIR__ . '/LegislacaoImportacao.php';

/*
 * Manutenção Legal › Atualização do Access (migração 404, 24/11/2026). Substitui o clsLegislacao.exe (.NET) do legado.
 *
 * Cada unidade das empresas migradas tem um banco Access (.mdb) com a tabela [Legislação Ambiental Aplicável]. O operador
 * (ADMINISTRADOR) sobe os .mdb das unidades (nome = coluna Nome MDB de Administração › Unidades, migração 403) e o
 * leis_modelo.mdb; cada upload sobrepõe o anterior (o antigo vai para o histórico). O processamento roda DEPOIS das três
 * importações mensais do portal — Legislação x Empresas (397), Legislação x Controle (398) e Alterações (402) — e aplica nos
 * .mdb as mesmas regras do .NET:
 *   1. lei vinculada à unidade (legislacaoxempresa) que NÃO está no .mdb → incluída com os dados da fonte (lei que já está
 *      não é mexida; lei retirada no portal NÃO sai do .mdb — decisão do usuário: igual ao .NET);
 *   2. controle da unidade (legislacaoxpoder: Conhecimento / Controle / Influência) → [DIRETA / INDIRETA] da lei que está no .mdb;
 *   3. alterações da última importação de Alterações aplicada: Lei Revogada → apagada do .mdb; Lei Alterada → regravada.
 * Fonte dos dados das leis (escolha do operador a cada processamento):
 *   - PORTAL: cadastro de Legislação (glegislacao_dbp, view vw_legislacao_access); o TEXTO DO REQUISITO, que o portal não
 *     tem, vem do leis_modelo.mdb quando a lei está nele; ÁREA RESPONSÁVEL montada pelas marcações (Meio Ambiente,
 *     Segurança e Saúde Ocupacional, Qualidade, Responsabilidade Social, unidas por " & ", como no legado). Na lei alterada só
 *     os campos que vêm do portal mudam; o resto fica como a unidade tinha (decisão do usuário).
 *   - MODELO: leis_modelo.mdb (como o .NET). Sem os defeitos do .NET ("..." no fim, espaços sobrando, SQL concatenado) e
 *     sem apagar a avaliação da unidade (SITUAÇÃO ATUAL, ATENDO, RESPONSAVEL, DATA) quando o modelo traz esses campos vazios.
 * Gravação nos .mdb por COM/ADODB (Microsoft.ACE.OLEDB 16/12, 64 bits; testado em 24/11) sobre uma CÓPIA; só no fim a cópia
 * substitui o arquivo, e o anterior vai para storage/access/historico. Erro numa unidade não para as outras.
 * Pedido: guniversal_dbp.access_processamentos (SIMULAR = só conta; APLICAR = grava). Serviço ACCESS_ATUALIZACAO do orquestrador.
 */
final class AccessAtualizacao
{
    public const TABELA_MDB = 'Legislação Ambiental Aplicável';
    public const IMPORTACOES = ['EMPRESA' => 'Legislação x Empresas', 'PODER' => 'Legislação x Controle', 'ALTERACAO' => 'Alterações da legislação'];
    public const FONTES = ['PORTAL' => 'Cadastro de Legislação do portal (texto do requisito do leis_modelo.mdb)', 'MODELO' => 'leis_modelo.mdb'];
    public const AREAS = ['MEIO_AMBIENTE' => 'Meio Ambiente', 'SEGURANCA' => 'Segurança e Saúde Ocupacional', 'QUALIDADE' => 'Qualidade', 'RESPOSABILIDADE' => 'Responsabilidade Social'];
    /** Colunas que o .NET acrescenta aos .mdb antigos quando faltam. */
    private const COLUNAS_EXTRAS = ['MEIO_AMBIENTE' => 'TEXT(3)', 'SEGURANCA' => 'TEXT(3)', 'QUALIDADE' => 'TEXT(3)', 'RESPOSABILIDADE' => 'TEXT(3)',
        'DSCUNIDADE' => 'TEXT(100)', 'RESPONSAVEL' => 'TEXT(100)', 'CITADAS EM NORMAS' => 'MEMO', 'NORMAS CITADAS' => 'MEMO',
        'DATA DE AVALIAÇÃO' => 'TEXT(50)', 'ATENDO ? (SIM / NÃO)' => 'TEXT(10)', 'SITUAÇÃO ATUAL' => 'MEMO'];
    /** Campos de avaliação da unidade: nunca apagados por um valor vazio da fonte. */
    private const CAMPOS_AVALIACAO = ['SITUAÇÃO ATUAL', 'ATENDO ? (SIM / NÃO)', 'RESPONSAVEL', 'DATA DE AVALIAÇÃO', 'CITADAS EM NORMAS', 'NORMAS CITADAS'];

    // ================================================================== pastas e arquivos
    public static function pasta(string ...$partes): string
    {
        $base = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'access';
        if (!is_dir($base)) { @mkdir($base, 0770, true); @file_put_contents($base . DIRECTORY_SEPARATOR . '.htaccess', "Require all denied\nDeny from all\n"); }
        $p = $base . ($partes ? DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $partes) : '');
        if (!is_dir($p)) @mkdir($p, 0770, true);
        return $p;
    }

    private static function u(): PDO { return LegislacaoImportacao::universal(); }
    private static function json(mixed $v): string { return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); }
    private static function chamar(PDO $pdo, string $sql, array $p = []): array { return LegislacaoImportacao::chamar($pdo, $sql, $p); }

    /** Arquivos cadastrados: ['MODELO' => linha|null, 'UNIDADES' => [codempresa => [codunidade => linha]]]. */
    public static function arquivos(): array
    {
        $out = ['MODELO' => null, 'UNIDADES' => []];
        foreach (LegislacaoEmpresas::ler(self::u(), 'vw_access_arquivos') as $a) {
            if ($a['tipo'] === 'MODELO') $out['MODELO'] = $a;
            else $out['UNIDADES'][(int)$a['codempresa']][(int)$a['codunidade']] = $a;
        }
        return $out;
    }

    public static function caminho(array $arq): string
    {
        return $arq['tipo'] === 'MODELO' ? self::pasta('arquivos', 'modelo') . DIRECTORY_SEPARATOR . 'leis_modelo.mdb'
            : self::pasta('arquivos', (string)(int)$arq['codempresa']) . DIRECTORY_SEPARATOR . self::nomeSeguro((string)$arq['nome_arquivo']);
    }

    private static function nomeSeguro(string $n): string
    {
        $n = basename(str_replace('\\', '/', $n));
        return (string)preg_replace('/[\x00-\x1F<>:"\/\\\\|?*]/u', '_', $n);
    }

    /** Unidades da empresa com o Nome MDB (view vw_unidades_admin da migração 403; sem ela, calcula pelo banco do legado). */
    public static function unidades(int $codempresa): array
    {
        $pdo = LegislacaoEmpresas::pdo($codempresa);
        $out = [];
        try { $linhas = LegislacaoEmpresas::ler($pdo, 'vw_unidades_admin'); } catch (Throwable) { $linhas = LegislacaoEmpresas::ler($pdo, 'unidades'); }
        foreach ($linhas as $u) {
            $u = array_change_key_case($u, CASE_UPPER);
            $out[(int)$u['CODUNIDADE']] = ['nome' => trim((string)$u['DSCUNIDADE']), 'mdb' => trim((string)($u['NOME_MDB'] ?? '')),
                'manutencao' => mb_strtoupper(trim((string)($u['USADO_EM_MANUTENCAO_LEGAL'] ?? ''))) === 'SIM'];
        }
        uasort($out, static fn($a, $b) => strnatcasecmp($a['nome'], $b['nome']));
        return $out;
    }

    /** Recebe um upload (modelo ou unidade), confere o nome e a tabela e sobrepõe o anterior (que vai para o histórico). */
    public static function receber(string $tmp, string $nomeOriginal, string $tipo, int $codempresa, int $codunidade, int $usuario, string $nomeUsuario): array
    {
        $nomeOriginal = self::nomeSeguro($nomeOriginal);
        if (strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION)) !== 'mdb') throw new InvalidArgumentException('Envie um arquivo .mdb.');
        $unidadeNome = null;
        if ($tipo === 'MODELO') {
            $codempresa = 0; $codunidade = 0;
        } else {
            $tipo = 'UNIDADE';
            $uns = self::unidades($codempresa);
            $un = $uns[$codunidade] ?? null;
            if ($un === null) throw new InvalidArgumentException('Unidade não encontrada nesta empresa.');
            if ($un['mdb'] === '') throw new InvalidArgumentException('A unidade não tem Nome MDB (rode a migração 403).');
            if (mb_strtolower($nomeOriginal) !== mb_strtolower($un['mdb'])) throw new InvalidArgumentException('O nome do arquivo precisa ser igual ao Nome MDB da unidade: ' . $un['mdb'] . ' (enviado: ' . $nomeOriginal . ').');
            $nomeOriginal = $un['mdb']; $unidadeNome = $un['nome'];
        }
        // confere se é um Access com a tabela de leis (numa cópia)
        $teste = self::pasta('tmp') . DIRECTORY_SEPARATOR . 'up_' . bin2hex(random_bytes(6)) . '.mdb';
        if (!@copy($tmp, $teste)) throw new RuntimeException('Não foi possível ler o arquivo enviado.');
        try {
            $cn = self::abrir($teste, true);
            $n = (int)self::valor($cn, 'SELECT COUNT(*) FROM [' . self::TABELA_MDB . ']');
            $cn->Close();
        } catch (Throwable $e) {
            @unlink($teste);
            throw new InvalidArgumentException('O arquivo não é um Access com a tabela [' . self::TABELA_MDB . ']: ' . self::erroCom($e));
        }
        @unlink($teste);
        $arq = ['tipo' => $tipo, 'codempresa' => $codempresa, 'codunidade' => $codunidade, 'nome_arquivo' => $nomeOriginal];
        $destino = self::caminho($arq);
        if (is_file($destino)) {
            $hist = self::pasta('historico', 'upload_' . date('Ymd_His') . '_' . $usuario, (string)$codempresa);
            @rename($destino, $hist . DIRECTORY_SEPARATOR . basename($destino));
        }
        if (!@copy($tmp, $destino)) throw new RuntimeException('Não foi possível gravar o arquivo no servidor.');
        $dados = ['tipo' => $tipo, 'codempresa' => $codempresa, 'codunidade' => $codunidade, 'unidade_nome' => $unidadeNome, 'nome_arquivo' => $nomeOriginal,
            'bytes' => (int)filesize($destino), 'sha256' => hash_file('sha256', $destino), 'leis' => $n,
            'enviado_por' => $usuario, 'enviado_por_nome' => mb_substr($nomeUsuario, 0, 150), 'enviado_em' => date('Y-m-d H:i:s'), 'atualizado_em' => null, 'atualizado_processamento' => null];
        $ja = array_values(array_filter(LegislacaoEmpresas::ler(self::u(), 'vw_access_arquivos', ['tipo' => $tipo]), static fn($a) => (int)$a['codempresa'] === $codempresa && (int)$a['codunidade'] === $codunidade));
        if ($ja) self::chamar(self::u(), 'CALL sp_crud_update(?, ?, ?)', ['access_arquivos', self::json(['codarquivo' => (int)$ja[0]['codarquivo']]), self::json(array_diff_key($dados, ['tipo' => 1, 'codempresa' => 1, 'codunidade' => 1]))]);
        else self::chamar(self::u(), 'CALL sp_crud_insert(?, ?)', ['access_arquivos', self::json($dados)]);
        return ['nome' => $nomeOriginal, 'leis' => $n, 'bytes' => $dados['bytes']];
    }

    // ================================================================== dependências (importações do mês)
    /**
     * Situação das três importações: a última de cada tipo que não foi descartada. Bloqueia se alguma não existe ou ainda não
     * foi aplicada (CONFERIDA, AUTORIZADA, APLICANDO). Devolve ['ok' => bool, 'itens' => [tipo => [...]], 'pedidos' => [tipo => id]].
     */
    public static function dependencias(): array
    {
        $todos = LegislacaoEmpresas::ler(self::u(), 'vw_legislacao_importacoes');
        $out = ['ok' => true, 'itens' => [], 'pedidos' => []];
        foreach (self::IMPORTACOES as $tipo => $nome) {
            $doTipo = array_values(array_filter($todos, static fn($p) => LegislacaoImportacao::tipoPedido($p['tipo'] ?? null) === $tipo && $p['status'] !== 'DESCARTADA'));
            usort($doTipo, static fn($a, $b) => (int)$b['codimportacao'] <=> (int)$a['codimportacao']);
            $p = $doTipo[0] ?? null;
            $aplicada = $p && in_array($p['status'], ['APLICADA', 'APLICADA_COM_ERROS'], true);
            $out['itens'][$tipo] = ['nome' => $nome, 'pedido' => $p ? (int)$p['codimportacao'] : null, 'arquivo' => $p['arquivo_nome'] ?? null,
                'status' => $p['status'] ?? null, 'enviado_em' => $p['enviado_em'] ?? null, 'aplicado_em' => $p['aplicacao_fim'] ?? null, 'ok' => $aplicada];
            if ($aplicada) $out['pedidos'][$tipo] = (int)$p['codimportacao']; else $out['ok'] = false;
        }
        return $out;
    }

    // ================================================================== pedidos de processamento
    public static function solicitar(string $modo, string $fonte, array $empresas, int $usuario, string $nomeUsuario, string $bancoOrigem): int
    {
        $modo = $modo === 'APLICAR' ? 'APLICAR' : 'SIMULAR';
        if (!isset(self::FONTES[$fonte])) throw new InvalidArgumentException('Escolha a fonte dos dados das leis.');
        $dep = self::dependencias();
        if (!$dep['ok']) {
            $falta = array_map(static fn($i) => $i['nome'], array_filter($dep['itens'], static fn($i) => !$i['ok']));
            throw new InvalidArgumentException('A atualização do Access depende das importações do mês. Aplique antes: ' . implode(', ', $falta) . '.');
        }
        $arqs = self::arquivos();
        if ($fonte === 'MODELO' && !$arqs['MODELO']) throw new InvalidArgumentException('Envie o leis_modelo.mdb antes de usar essa fonte.');
        $migradas = LegislacaoEmpresas::migradas();
        $empresas = array_values(array_unique(array_filter(array_map('intval', $empresas), static fn($c) => isset($migradas[$c]) && !empty($arqs['UNIDADES'][$c]))));
        if (!$empresas) throw new InvalidArgumentException('Escolha ao menos uma empresa com arquivos .mdb enviados.');
        $aberto = array_filter(LegislacaoEmpresas::ler(self::u(), 'vw_access_processamentos'), static fn($p) => in_array($p['status'], ['SOLICITADO', 'PROCESSANDO'], true));
        if ($aberto) throw new InvalidArgumentException('Já existe um processamento em andamento (nº ' . (int)array_values($aberto)[0]['codprocessamento'] . '). Aguarde terminar.');
        $r = self::chamar(self::u(), 'CALL sp_crud_insert(?, ?)', ['access_processamentos', self::json([
            'modo' => $modo, 'fonte' => $fonte, 'empresas' => self::json($empresas), 'pedidos' => self::json($dep['pedidos']), 'status' => 'SOLICITADO',
            'solicitado_por' => $usuario, 'solicitado_por_nome' => mb_substr($nomeUsuario, 0, 150), 'banco_origem' => $bancoOrigem, 'solicitado_em' => date('Y-m-d H:i:s'),
            'log' => date('d/m/Y H:i') . ' ' . ($modo === 'APLICAR' ? 'Atualização' : 'Simulação') . ' solicitada por ' . $nomeUsuario . '.'])]);
        $id = (int)($r[0]['id'] ?? $r[0]['ultimo_id'] ?? $r[0]['LAST_INSERT_ID()'] ?? 0);
        if ($id <= 0) {
            $meus = array_filter(LegislacaoEmpresas::ler(self::u(), 'vw_access_processamentos', ['solicitado_por' => $usuario]), static fn($p) => $p['status'] === 'SOLICITADO');
            $id = $meus ? max(array_map(static fn($p) => (int)$p['codprocessamento'], $meus)) : 0;
        }
        return $id;
    }

    public static function processamentos(int $limite = 30): array
    {
        $r = LegislacaoEmpresas::ler(self::u(), 'vw_access_processamentos');
        usort($r, static fn($a, $b) => (int)$b['codprocessamento'] <=> (int)$a['codprocessamento']);
        return array_slice($r, 0, $limite);
    }

    public static function processamento(int $id): ?array
    {
        foreach (LegislacaoEmpresas::ler(self::u(), 'vw_access_processamentos', ['codprocessamento' => $id], 5) as $p) if ((int)$p['codprocessamento'] === $id) return $p;
        return null;
    }

    public static function reservar(): ?array
    {
        $r = self::chamar(self::u(), 'CALL sp_access_reservar()');
        return ($r && !empty($r[0]['codprocessamento'])) ? $r[0] : null;
    }

    private static function atualizarPedido(int $id, array $dados, ?string $log = null): void
    {
        if ($log !== null) {
            $atual = self::processamento($id);
            $dados['log'] = trim((string)($atual['log'] ?? '') . "\n" . date('d/m/Y H:i:s') . ' ' . $log);
        }
        self::chamar(self::u(), 'CALL sp_crud_update(?, ?, ?)', ['access_processamentos', self::json(['codprocessamento' => $id]), self::json($dados)]);
    }

    // ================================================================== processamento
    /** Executa um processamento reservado. Devolve o resumo. */
    public static function executar(array $p): array
    {
        $id = (int)$p['codprocessamento'];
        $aplicar = $p['modo'] === 'APLICAR';
        $fonte = (string)$p['fonte'];
        $empresas = json_decode((string)$p['empresas'], true) ?: [];
        $pedidos = json_decode((string)$p['pedidos'], true) ?: [];
        @set_time_limit(0);
        $log = static function (string $t) use ($id): void { self::atualizarPedido($id, [], $t); };
        $log('Início: ' . ($aplicar ? 'ATUALIZAR' : 'SIMULAR') . ', fonte ' . ($fonte === 'MODELO' ? 'leis_modelo.mdb' : 'portal') . '.');
        $arqs = self::arquivos();
        // ------------------------------------------------ fontes
        $modelo = [];
        if ($arqs['MODELO'] && is_file(self::caminho($arqs['MODELO']))) {
            $modelo = self::lerModelo(self::caminho($arqs['MODELO']));
            $log('leis_modelo.mdb: ' . count($modelo) . ' lei(s).');
        } else {
            // 24/11/2026 (decisão do usuário): as inclusões são só as leis do mês (as do leis_modelo.mdb), como no .NET,
            // nas duas fontes; sem o modelo não há o que incluir
            throw new RuntimeException('leis_modelo.mdb não encontrado. Envie o arquivo na aba leis_modelo.mdb.');
        }
        $idsCodigo = self::codigosPorId();
        // alterações da última importação aplicada
        $alteracoes = [];
        if (!empty($pedidos['ALTERACAO'])) {
            foreach (LegislacaoImportacao::itens((int)$pedidos['ALTERACAO']) as $it) {
                $sit = (string)($it['tipo_novo'] ?? '');
                if (!in_array($sit, ['Lei Revogada', 'Lei Alterada'], true) || in_array($it['status'], ['ERRO'], true)) continue;
                $alteracoes[strtoupper(trim((string)$it['codigo_lei']))] = $sit;
            }
        }
        $log('Alterações (importação nº ' . ($pedidos['ALTERACAO'] ?? '—') . '): ' . count(array_filter($alteracoes, static fn($s) => $s === 'Lei Revogada')) . ' revogada(s), ' . count(array_filter($alteracoes, static fn($s) => $s === 'Lei Alterada')) . ' alterada(s).');
        $migradas = LegislacaoEmpresas::migradas();
        $resumo = ['unidades' => [], 'totais' => ['arquivos' => 0, 'incluidas' => 0, 'controles' => 0, 'revogadas' => 0, 'alteradas' => 0, 'sem_fonte' => 0, 'erros' => 0]];
        $portalCache = [];
        $fonteDe = function (string $codigo) use ($fonte, $modelo, &$portalCache): ?array {
            if ($fonte === 'MODELO') return $modelo[$codigo] ?? null;
            return $portalCache[$codigo] ?? null;
        };
        foreach ($empresas as $codemp) {
            $codemp = (int)$codemp;
            if (!isset($migradas[$codemp])) continue;
            $emp = $migradas[$codemp];
            try {
                $uns = self::unidades($codemp);
                $pdo = LegislacaoEmpresas::pdo($codemp);
                $vinc = LegislacaoEmpresas::ler($pdo, 'legislacaoxempresa', ['CODEMPRESA' => $codemp]);
                $poder = LegislacaoEmpresas::ler($pdo, 'legislacaoxpoder');
            } catch (Throwable $e) {
                $log($emp['nome'] . ': erro ao ler os vínculos — ' . $e->getMessage());
                $resumo['totais']['erros']++;
                continue;
            }
            // leis por unidade (código base: o vínculo de padrão "código + letra" vira o código da lei principal, como no .NET)
            $leisUn = []; $poderUn = [];
            foreach ($vinc as $v) {
                $v = array_change_key_case($v, CASE_UPPER);
                $cod = $idsCodigo[(int)$v['CODLEGISLACAO']] ?? null;
                if ($cod === null) continue;
                $t = strtoupper(trim((string)($v['TIPO_DE_LEI'] ?? '')));
                if (strlen($t) === 1 && ctype_alpha($t) && str_ends_with($cod, $t) && isset($idsCodigo['#' . substr($cod, 0, -1)])) $cod = substr($cod, 0, -1);
                $leisUn[(int)$v['CODUNIDADE']][strtoupper($cod)] = true;
            }
            foreach ($poder as $v) {
                $v = array_change_key_case($v, CASE_UPPER);
                $cod = $idsCodigo[(int)$v['CODLEGISLACAO']] ?? null;
                $val = trim((string)($v['PODER'] ?? ''));
                if ($cod !== null && $val !== '') $poderUn[(int)$v['CODUNIDADE']][strtoupper($cod)] = $val;
            }
            if ($fonte === 'PORTAL') {
                $precisa = array_keys($alteracoes);
                foreach ($leisUn as $ls) $precisa = array_merge($precisa, array_keys($ls));
                $portalCache += self::lerPortal(array_diff(array_unique($precisa), array_keys($portalCache)), $modelo);
            }
            foreach (($arqs['UNIDADES'][$codemp] ?? []) as $codun => $arq) {
                $un = $uns[$codun] ?? ['nome' => (string)$arq['unidade_nome'], 'mdb' => (string)$arq['nome_arquivo']];
                $chaveUn = $emp['nome'] . ' › ' . $un['nome'];
                $caminho = self::caminho($arq);
                $r = ['empresa' => $emp['nome'], 'unidade' => $un['nome'], 'arquivo' => $arq['nome_arquivo'], 'incluidas' => 0, 'controles' => 0, 'revogadas' => 0, 'alteradas' => 0, 'sem_fonte' => 0, 'leis_antes' => null, 'leis_depois' => null, 'erro' => null];
                if (!is_file($caminho)) { $r['erro'] = 'arquivo não encontrado no servidor'; $resumo['unidades'][] = $r; $resumo['totais']['erros']++; continue; }
                $trabalho = self::pasta('trabalho', (string)$id) . DIRECTORY_SEPARATOR . basename($caminho);
                try {
                    if (!@copy($caminho, $trabalho)) throw new RuntimeException('não foi possível copiar o arquivo');
                    $cn = self::abrir($trabalho, !$aplicar);
                    if ($aplicar) self::garantirColunas($cn);
                    $ctlAtual = [];
                    $tem = self::codigosNoMdb($cn, $ctlAtual);
                    $colunas = self::colunasMdb($cn);
                    $rsNovo = null;
                    $r['leis_antes'] = count($tem);
                    $semFonte = [];
                    // 1. inclusões
                    $foraDoMes = 0;
                    foreach (array_keys($leisUn[$codun] ?? []) as $cod) {
                        if (isset($tem[$cod])) continue;
                        // só as leis do mês (leis_modelo.mdb); as vinculadas antigas que a unidade não tem ficam como estão
                        if (!isset($modelo[$cod])) { $foraDoMes++; continue; }
                        $f = $fonteDe($cod);
                        if ($f === null) { $r['sem_fonte']++; $semFonte[] = $cod; continue; }
                        if ($aplicar) {
                            if ($rsNovo === null) { $rsNovo = new COM('ADODB.Recordset', null, CP_UTF8); $rsNovo->Open('SELECT * FROM [' . self::TABELA_MDB . '] WHERE 1 = 0', $cn, 1 /* adOpenKeyset */, 3 /* adLockOptimistic */); }
                            try {
                                $rsNovo->AddNew();
                                self::preencher($rsNovo, $colunas, $f, $un['nome'], true, $fonte);
                                $rsNovo->Update();
                            } catch (Throwable $e) { try { $rsNovo->CancelUpdate(); } catch (Throwable) {} throw new RuntimeException('lei ' . $cod . ' (inclusão): ' . self::erroCom($e), 0, $e); }
                        }
                        $tem[$cod] = true; $r['incluidas']++;
                    }
                    if ($rsNovo !== null) { $rsNovo->Close(); $rsNovo = null; }
                    // 2. controles
                    foreach (($poderUn[$codun] ?? []) as $cod => $val) {
                        if (!isset($tem[$cod])) continue;
                        // 24/11/2026: só grava (e conta) quando o valor muda; lei incluída agora sempre recebe o controle
                        $novoCtl = mb_substr($val, 0, 12);
                        if (isset($ctlAtual[$cod]) && mb_strtolower($ctlAtual[$cod]) === mb_strtolower($novoCtl)) continue;
                        if ($aplicar) self::executarSql($cn, 'UPDATE [' . self::TABELA_MDB . '] SET [DIRETA / INDIRETA] = ' . self::lit(mb_substr($val, 0, 12)) . ' WHERE [N°] = ' . self::lit($cod));
                        $r['controles']++;
                    }
                    // 3. alterações
                    foreach ($alteracoes as $cod => $sit) {
                        if (!isset($tem[$cod])) continue;
                        if ($sit === 'Lei Revogada') {
                            if ($aplicar) self::executarSql($cn, 'DELETE FROM [' . self::TABELA_MDB . '] WHERE [N°] = ' . self::lit($cod));
                            unset($tem[$cod]); $r['revogadas']++;
                            continue;
                        }
                        $f = $fonteDe($cod);
                        if ($f === null) continue;
                        if ($aplicar) {
                            try { self::alterar($cn, $colunas, $cod, $f, $un['nome'], $fonte); }
                            catch (Throwable $e) { throw new RuntimeException('lei ' . $cod . ' (alteração): ' . self::erroCom($e), 0, $e); }
                        }
                        $r['alteradas']++;
                    }
                    $r['leis_depois'] = count($tem);
                    $r['vinculadas_fora_do_mes'] = $foraDoMes;
                    if ($semFonte) $r['sem_fonte_exemplos'] = array_slice($semFonte, 0, 15);
                    $cn->Close();
                    $cn = null;
                    if ($aplicar) {
                        $hist = self::pasta('historico', 'proc_' . $id, (string)$codemp);
                        if (!@copy($caminho, $hist . DIRECTORY_SEPARATOR . basename($caminho))) throw new RuntimeException('não foi possível guardar a cópia anterior');
                        if (!@rename($trabalho, $caminho) && !(@copy($trabalho, $caminho) && @unlink($trabalho))) throw new RuntimeException('não foi possível substituir o arquivo');
                        self::chamar(self::u(), 'CALL sp_crud_update(?, ?, ?)', ['access_arquivos', self::json(['codarquivo' => (int)$arq['codarquivo']]),
                            self::json(['atualizado_em' => date('Y-m-d H:i:s'), 'atualizado_processamento' => $id, 'bytes' => (int)filesize($caminho), 'sha256' => hash_file('sha256', $caminho), 'leis' => $r['leis_depois']])]);
                    }
                    $resumo['totais']['arquivos']++;
                } catch (Throwable $e) {
                    if (isset($rsNovo) && $rsNovo) { try { $rsNovo->Close(); } catch (Throwable) {} $rsNovo = null; }
                    if (isset($cn) && $cn) { try { $cn->Close(); } catch (Throwable) {} $cn = null; }
                    $r['erro'] = self::erroCom($e);
                    $resumo['totais']['erros']++;
                }
                @unlink($trabalho);
                foreach (['incluidas', 'controles', 'revogadas', 'alteradas', 'sem_fonte'] as $k) $resumo['totais'][$k] += $r[$k];
                $resumo['unidades'][] = $r;
                $log($chaveUn . ': ' . ($r['erro'] ? 'ERRO — ' . $r['erro'] : sprintf('%d incluída(s), %d controle(s), %d revogada(s), %d alterada(s)%s', $r['incluidas'], $r['controles'], $r['revogadas'], $r['alteradas'], $r['sem_fonte'] ? ', ' . $r['sem_fonte'] . ' sem dados na fonte' : '') . (!empty($r['vinculadas_fora_do_mes']) ? '; ' . $r['vinculadas_fora_do_mes'] . ' vinculada(s) que não são do mês ficaram de fora' : '')));
                self::atualizarPedido($id, ['resumo' => self::json($resumo)]);
            }
        }
        @rmdir(self::pasta('trabalho', (string)$id));
        $status = $resumo['totais']['erros'] > 0 ? 'CONCLUIDO_COM_ERROS' : 'CONCLUIDO';
        self::atualizarPedido($id, ['status' => $status, 'fim' => date('Y-m-d H:i:s'), 'resumo' => self::json($resumo),
            'total_arquivos' => $resumo['totais']['arquivos'], 'total_erros' => $resumo['totais']['erros']], 'Fim: ' . $status . '.');
        return $resumo + ['status' => $status];
    }

    public static function falhar(int $id, string $erro): void
    {
        self::atualizarPedido($id, ['status' => 'ERRO', 'fim' => date('Y-m-d H:i:s')], 'ERRO: ' . $erro);
    }

    public static function alertar(array $p, string $titulo, string $mensagem): void
    {
        $banco = (string)($p['banco_origem'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $banco) || (int)($p['solicitado_por'] ?? 0) <= 0) return;
        try {
            $pdo = GreenPortalDatabase::connect(GreenPortalDatabase::env(), $banco, 'GPT');
            self::chamar($pdo, 'CALL sp_crud_insert(?, ?)', ['alertas', self::json(['codusuario' => (int)$p['solicitado_por'], 'titulo' => $titulo, 'mensagem' => $mensagem,
                'link_status' => '/principal/manutencaolegal_atualizacao_access.php?codprocessamento=' . (int)$p['codprocessamento'], 'lido' => 0])]);
        } catch (Throwable) { /* o resultado continua na tela */ }
    }

    // ================================================================== fontes
    /** [CODLEGISLACAO => CODIGO] e ['#CODIGO' => CODLEGISLACAO] do cadastro de Legislação. */
    private static function codigosPorId(): array
    {
        $out = [];
        foreach (LegislacaoImportacao::legislacao()->query('SELECT CODLEGISLACAO, CODIGO FROM vw_legislacao_access') as $r) {
            $c = strtoupper(trim((string)$r['CODIGO']));
            $out[(int)$r['CODLEGISLACAO']] = $c; $out['#' . $c] = (int)$r['CODLEGISLACAO'];
        }
        return $out;
    }

    /** Dados do portal para os códigos pedidos, já no formato das colunas do Access. */
    private static function lerPortal(array $codigos, array $modelo): array
    {
        $out = [];
        $leg = LegislacaoImportacao::legislacao();
        $leg->exec('SET SESSION group_concat_max_len = 65535');
        foreach (array_chunk(array_values($codigos), 400) as $lote) {
            if (!$lote) continue;
            $s = $leg->prepare('SELECT * FROM vw_legislacao_access WHERE CODIGO IN (' . implode(',', array_fill(0, count($lote), '?')) . ')');
            $s->execute($lote);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $cod = strtoupper(trim((string)$l['CODIGO']));
                $flag = static fn($v) => mb_strtoupper(trim((string)$v)) === 'SIM' ? '-1' : '0';
                $areas = [];
                foreach (self::AREAS as $col => $nome) if ($flag($l[$col] ?? '') === '-1') $areas[] = $nome;
                $out[$cod] = [
                    'N°' => $cod, 'TIPO DA NORMA' => (string)($l['TIPO'] ?? ''), 'N° DA NORMA' => (string)($l['NR'] ?? ''), 'ANO' => (string)($l['ANO'] ?? ''),
                    'ÂMBITO' => (string)($l['AMBITO'] ?? ''), 'TEMA / ASSUNTO RELACIONADO' => (string)($l['ASSUNTOS'] ?? ''), 'ARTIGOS' => (string)($l['ARTIGOS'] ?? ''),
                    'ÁREA RESPONSÁVEL' => implode(' & ', $areas), 'PADRÃO DE DESEMPENHO' => (string)($l['PADRAO'] ?? ''),
                    'MEIO_AMBIENTE' => $flag($l['MEIO_AMBIENTE'] ?? ''), 'SEGURANCA' => $flag($l['SEGURANCA'] ?? ''), 'QUALIDADE' => $flag($l['QUALIDADE'] ?? ''), 'RESPOSABILIDADE' => $flag($l['RESPOSABILIDADE'] ?? ''),
                ];
                if (isset($modelo[$cod]['TEXTO DO REQUISITO LEGAL APLICÁVEL']) && trim((string)$modelo[$cod]['TEXTO DO REQUISITO LEGAL APLICÁVEL']) !== '')
                    $out[$cod]['TEXTO DO REQUISITO LEGAL APLICÁVEL'] = (string)$modelo[$cod]['TEXTO DO REQUISITO LEGAL APLICÁVEL'];
            }
        }
        return $out;
    }

    /** leis_modelo.mdb inteiro: [N° => [coluna => valor]] (lido numa cópia). */
    private static function lerModelo(string $arquivo): array
    {
        $copia = self::pasta('tmp') . DIRECTORY_SEPARATOR . 'modelo_' . bin2hex(random_bytes(6)) . '.mdb';
        copy($arquivo, $copia);
        $out = [];
        try {
            $cn = self::abrir($copia, true);
            $rs = $cn->Execute('SELECT * FROM [' . self::TABELA_MDB . ']');
            $nomes = [];
            for ($i = 0; $i < $rs->Fields->Count; $i++) $nomes[] = (string)$rs->Fields($i)->Name;
            while (!$rs->EOF) {
                $l = [];
                foreach ($nomes as $i => $n) { $v = $rs->Fields($i)->Value; $l[$n] = $v === null ? null : (is_bool($v) ? $v : (is_object($v) ? (string)$v : $v)); }
                $cod = strtoupper(trim((string)($l['N°'] ?? '')));
                if ($cod !== '') $out[$cod] = $l;
                $rs->MoveNext();
            }
            $rs->Close(); $cn->Close();
        } finally { @unlink($copia); }
        return $out;
    }

    // ================================================================== Access (COM/ADODB)
    public static function abrir(string $arquivo, bool $leitura): object
    {
        if (!class_exists('COM')) throw new RuntimeException('Extensão com_dotnet do PHP não carregada.');
        $ultimo = null;
        foreach (['Microsoft.ACE.OLEDB.16.0', 'Microsoft.ACE.OLEDB.12.0'] as $prov) {
            try {
                $cn = new COM('ADODB.Connection', null, CP_UTF8);
                $cn->Open('Provider=' . $prov . ';Data Source=' . $arquivo . ';' . ($leitura ? 'Mode=Read;' : ''));
                return $cn;
            } catch (Throwable $e) { $ultimo = $e; }
        }
        throw new RuntimeException('Não foi possível abrir o Access: ' . self::erroCom($ultimo));
    }

    private static function erroCom(?Throwable $e): string
    {
        if ($e === null) return '';
        $m = trim((string)preg_replace('/\s+/', ' ', strip_tags($e->getMessage())));
        $m = (string)preg_replace("/'?[A-Za-z]:\\\\[^']*'?/", 'o arquivo', $m); // sem o caminho do servidor
        if (preg_match('/Description:\s*(.+)$/i', $m, $x)) $m = $x[1];
        return mb_substr($m, 0, 300);
    }

    private static function valor(object $cn, string $sql): mixed
    {
        $rs = $cn->Execute($sql);
        $v = $rs->EOF ? null : $rs->Fields(0)->Value;
        $rs->Close();
        return $v;
    }

    private static function executarSql(object $cn, string $sql): void
    {
        $cn->Execute($sql);
    }

    /** Literal de texto do Access (aspas simples dobradas). */
    private static function lit(string $v): string { return "'" . str_replace("'", "''", $v) . "'"; }

    /** Teste no servidor (scripts/teste-access-gravacao.php): inclui, altera e apaga uma lei fictícia numa cópia. */
    public static function testarGravacao(string $copia): array
    {
        $cn = self::abrir($copia, false);
        $out = [];
        try {
            self::garantirColunas($cn);
            $tem = self::colunasMdb($cn);
            $out['colunas'] = count($tem);
            $cod = 'ZZTESTE' . date('His');
            $f = ['N°' => $cod, 'Manter' => false, 'TIPO DA NORMA' => 'Teste', 'N° DA NORMA' => '1', 'ANO' => date('Y'), 'ÂMBITO' => 'Teste ção',
                'TEXTO DO REQUISITO LEGAL APLICÁVEL' => str_repeat("Texto com acentuação e apóstrofo d'água. ", 3000), 'PADRÃO DE DESEMPENHO' => 'Padrão ção',
                'ÁREA RESPONSÁVEL' => 'Meio Ambiente & Qualidade', 'MEIO_AMBIENTE' => '-1', 'SEGURANCA' => '0', 'QUALIDADE' => '-1', 'RESPOSABILIDADE' => '0'];
            $rs = new COM('ADODB.Recordset', null, CP_UTF8);
            $rs->Open('SELECT * FROM [' . self::TABELA_MDB . '] WHERE 1 = 0', $cn, 1, 3);
            $rs->AddNew(); self::preencher($rs, $tem, $f, 'Unidade Teste', true, 'MODELO'); $rs->Update(); $rs->Close();
            $out['incluida'] = isset(self::codigosNoMdb($cn)[$cod]);
            self::executarSql($cn, 'UPDATE [' . self::TABELA_MDB . '] SET [DIRETA / INDIRETA] = ' . self::lit('Influência') . ' WHERE [N°] = ' . self::lit($cod));
            self::alterar($cn, $tem, $cod, ['N°' => $cod, 'PADRÃO DE DESEMPENHO' => 'Padrão alterado', 'ANO' => '1999'], 'Unidade Teste', 'PORTAL');
            $rs = $cn->Execute('SELECT [ANO], [PADRÃO DE DESEMPENHO], [DIRETA / INDIRETA], [ÁREA RESPONSÁVEL], [DSCUNIDADE], LEN([TEXTO DO REQUISITO LEGAL APLICÁVEL]) FROM [' . self::TABELA_MDB . '] WHERE [N°] = ' . self::lit($cod));
            $out['lido'] = $rs->EOF ? null : ['ano' => (string)$rs->Fields(0)->Value, 'padrao' => (string)$rs->Fields(1)->Value, 'controle' => (string)$rs->Fields(2)->Value, 'area' => (string)$rs->Fields(3)->Value, 'unidade' => (string)$rs->Fields(4)->Value, 'tamanho_texto' => (int)$rs->Fields(5)->Value];
            $rs->Close();
            self::executarSql($cn, 'DELETE FROM [' . self::TABELA_MDB . '] WHERE [N°] = ' . self::lit($cod));
            $out['apagada'] = !isset(self::codigosNoMdb($cn)[$cod]);
        } finally { $cn->Close(); }
        return $out;
    }

    private static function codigosNoMdb(object $cn, ?array &$controle = null): array
    {
        $tem = []; $controle = [];
        $comCtl = true;
        try { $rs = $cn->Execute('SELECT [N°], [DIRETA / INDIRETA] FROM [' . self::TABELA_MDB . ']'); }
        catch (Throwable) { $comCtl = false; $rs = $cn->Execute('SELECT [N°] FROM [' . self::TABELA_MDB . ']'); } // .mdb antigo sem a coluna
        while (!$rs->EOF) { $c = strtoupper(trim((string)$rs->Fields(0)->Value)); if ($c !== '') { $tem[$c] = true; if ($comCtl) $controle[$c] = trim((string)$rs->Fields(1)->Value); } $rs->MoveNext(); }
        $rs->Close();
        return $tem;
    }

    private static function colunasMdb(object $cn): array
    {
        $rs = $cn->Execute('SELECT TOP 1 * FROM [' . self::TABELA_MDB . ']');
        $c = [];
        for ($i = 0; $i < $rs->Fields->Count; $i++) $c[mb_strtoupper((string)$rs->Fields($i)->Name)] = (string)$rs->Fields($i)->Name;
        $rs->Close();
        return $c;
    }

    private static function garantirColunas(object $cn): void
    {
        $tem = self::colunasMdb($cn);
        foreach (self::COLUNAS_EXTRAS as $col => $tipo) {
            if (isset($tem[mb_strtoupper($col)])) continue;
            try { self::executarSql($cn, 'ALTER TABLE [' . self::TABELA_MDB . '] ADD COLUMN [' . $col . '] ' . $tipo); } catch (Throwable) { /* como no .NET: segue */ }
        }
        try { self::executarSql($cn, 'CREATE INDEX INR ON [' . self::TABELA_MDB . ']([N°])'); } catch (Throwable) { /* já existe */ }
    }

    /** Valor da fonte para a coluna do .mdb (trim; datas e booleanos convertidos). */
    private static function valorColuna(array $f, string $col): mixed
    {
        $orig = $col === 'DATA DE AVALIAÇÃO' ? ($f['DATA DE AVALIAÇÃO'] ?? $f['DATA_DE_AVALIACAO'] ?? null) : ($f[$col] ?? null);
        if ($orig === null) return null;
        if ($col === 'Manter') return (bool)$orig && (string)$orig !== '0' && strtolower((string)$orig) !== 'false';
        if (is_bool($orig)) return $orig ? '-1' : '0';
        if (in_array($col, ['MEIO_AMBIENTE', 'SEGURANCA', 'QUALIDADE', 'RESPOSABILIDADE'], true)) { $s = strtolower(trim((string)$orig)); return in_array($s, ['-1', 'true', 'sim', '1'], true) ? '-1' : '0'; }
        $s = trim((string)$orig);
        if ($col === 'DATA DE AVALIAÇÃO' && ($t = strtotime($s))) $s = date('d/m/Y', $t);
        return $s;
    }

    /** Grava os campos num registro aberto do Recordset (só as colunas que existem no .mdb). */
    private static function preencher(object $rs, array $tem, array $f, string $unidade, bool $inclusao, string $fonte): void
    {
        $campos = ['N°', 'Manter', 'TIPO DA NORMA', 'N° DA NORMA', 'ANO', 'ÂMBITO', 'TEMA / ASSUNTO RELACIONADO', 'ARTIGOS', 'TEXTO DO REQUISITO LEGAL APLICÁVEL',
            'ÁREA RESPONSÁVEL', 'PADRÃO DE DESEMPENHO', 'SITUAÇÃO ATUAL', 'ATENDO ? (SIM / NÃO)', 'CITADAS EM NORMAS', 'NORMAS CITADAS', 'DATA DE AVALIAÇÃO',
            'DIRETA / INDIRETA', 'MEIO_AMBIENTE', 'SEGURANCA', 'QUALIDADE', 'RESPOSABILIDADE', 'RESPONSAVEL'];
        foreach ($campos as $col) {
            $real = $tem[mb_strtoupper($col)] ?? null;
            if ($real === null) continue;
            if (!$inclusao && $col === 'N°') continue;
            if (!$inclusao && $col === 'DIRETA / INDIRETA') continue;                       // o .NET não muda o controle na alteração
            $temNaFonte = array_key_exists($col, $f) || ($col === 'DATA DE AVALIAÇÃO' && array_key_exists('DATA_DE_AVALIACAO', $f));
            if (!$inclusao && $fonte === 'PORTAL' && !$temNaFonte) continue;                // portal: só os campos que vêm dele
            $v = self::valorColuna($f, $col);
            // Valor vazio na fonte: não grava. Na alteração mantém o que a unidade já tem (o Access também recusa texto vazio
            // nos campos com "Permitir comprimento zero = Não", ex.: N° DA NORMA — erro do processamento nº 3, 24/11/2026)
            if ($v === null || (is_string($v) && trim($v) === '')) {
                if ($inclusao && $col === 'Manter') $v = false; else continue;
            }
            $tamanho = (int)$rs->Fields($real)->DefinedSize;
            if (is_string($v) && $tamanho > 0 && $tamanho < 65535 && mb_strlen($v) > $tamanho) $v = mb_substr($v, 0, $tamanho);
            $rs->Fields($real)->Value = $v;
        }
        $realUn = $tem['DSCUNIDADE'] ?? null;
        if ($realUn !== null) $rs->Fields($realUn)->Value = mb_substr($unidade, 0, 100);
    }

    private static function alterar(object $cn, array $tem, string $cod, array $f, string $unidade, string $fonte): void
    {
        $rs = new COM('ADODB.Recordset', null, CP_UTF8);
        $rs->Open('SELECT * FROM [' . self::TABELA_MDB . '] WHERE [N°] = ' . self::lit($cod), $cn, 1, 3);
        while (!$rs->EOF) {
            self::preencher($rs, $tem, $f, $unidade, false, $fonte);
            $rs->Update();
            $rs->MoveNext();
        }
        $rs->Close();
    }
}
