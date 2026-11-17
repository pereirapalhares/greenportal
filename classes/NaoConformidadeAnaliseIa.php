<?php
declare(strict_types=1);

require_once __DIR__ . '/NaoConformidadeWorkflow.php';
require_once __DIR__ . '/DocumentAiQueueService.php';

/**
 * Não Conformidades — análise da causa com IA (migração 336, 23/10/2026).
 * Na aba "Análise da causa e plano de ação" o responsável pede uma sugestão: o contexto (ocorrência, análise e causas já
 * cadastradas, tipos de causa da empresa e NCs semelhantes já tratadas que o usuário pode ver) vai para a fila de IA
 * (green_ia_document_jobs, serviço "ia" do Agendador) com o prompt NC_ANALISE_CAUSA, cadastrado em Setup › Prompts de IA.
 * O pedido roda em segundo plano: o serviço "ia" avisa nos Alertas quem pediu quando a resposta chega (sp_nc_ia_analise_concluida);
 * o alerta abre a NC na aba da análise com a revisão aberta. Novo pedido fica bloqueado enquanto houver um na fila.
 * A IA só sugere: o responsável revisa, marca e edita; "Aplicar" grava pelas mesmas procedures da tela (sp_nc_causa_salvar,
 * sp_nc_plano_salvar, sp_nc_analise_salvar), tudo numa transação, e registra no histórico da NC.
 * Leitura por views (vw_nc_*), gravação por procedures (sp_nc_*).
 */
final class NaoConformidadeAnaliseIa
{
    public const PROMPT = 'NC_ANALISE_CAUSA';
    public const MAX_CAUSAS = 4;
    public const MAX_ACOES = 6;
    public const MAX_SEMELHANTES = 5;
    public const PRIORIDADES = ['Alta', 'Média', 'Baixa'];
    private const STOP = ['para', 'como', 'pela', 'pelo', 'pelas', 'pelos', 'com', 'sem', 'que', 'uma', 'umas', 'uns', 'dos', 'das', 'nos', 'nas', 'num', 'numa',
        'este', 'esta', 'esse', 'essa', 'isso', 'isto', 'aquele', 'aquela', 'onde', 'quando', 'qual', 'quais', 'sobre', 'entre', 'apos', 'após', 'ate', 'até',
        'foi', 'foram', 'ser', 'sendo', 'sido', 'esta', 'está', 'estava', 'estavam', 'tem', 'têm', 'tinha', 'havia', 'houve', 'mais', 'menos', 'muito', 'muita',
        'pois', 'porque', 'porém', 'porem', 'mas', 'também', 'tambem', 'ainda', 'apenas', 'cada', 'outro', 'outra', 'outros', 'outras', 'mesmo', 'mesma',
        'seu', 'sua', 'seus', 'suas', 'dele', 'dela', 'ocorrência', 'ocorrencia', 'ocorreu', 'durante', 'conforme', 'feito', 'feita', 'sendo', 'nao', 'não',
        'the', 'and', 'with', 'from', 'that', 'this', 'were', 'was', 'have', 'has', 'para', 'del', 'los', 'las', 'por', 'una', 'fue'];

    /** Pedido sem resposta há mais que isto (serviço de IA parado) deixa de bloquear um novo pedido. */
    public const HORAS_BLOQUEIO = 2;

    /**
     * Situação da IA nesta NC (23/10/2026, decisão do usuário: o pedido roda em segundo plano no Agendador e a resposta chega
     * nos Alertas; um novo pedido fica bloqueado até a resposta chegar):
     *   pendente = pedido de qualquer usuário ainda na fila (['usuario' => nome, 'criado_em' => data]) ou null;
     *   pronta   = CODANALISE da sugestão do usuário já respondida pela IA e ainda não aplicada/descartada (0 se não há).
     */
    public static function situacao(PDO $pdo, int $codigo, int $uid, string $tipo = 'CAUSA'): array
    {
        $r = ['pendente' => null, 'pronta' => 0];
        try {
            $s = $pdo->prepare("SELECT CODANALISE, CODUSUARIO, USUARIO, STATUS, JOB_STATUS, CRIADO_EM, TIMESTAMPDIFF(MINUTE, CRIADO_EM, NOW()) AS IDADE_MIN FROM vw_nc_ia_analises WHERE CODOCORRENCIA = ? AND TIPO = ? AND STATUS IN ('SOLICITADA', 'SUGERIDA', 'EXTRAINDO') ORDER BY CODANALISE DESC");
            $s->execute([$codigo, $tipo]); $linhas = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
        } catch (Throwable) { return $r; } // antes das migrações 336/337
        foreach ($linhas as $l) {
            // EXTRAINDO (migração 338): o pedido ainda espera a leitura (OCR) dos arquivos de evidência
            $naFila = $l['STATUS'] === 'EXTRAINDO' || ($l['STATUS'] === 'SOLICITADA' && in_array((string)$l['JOB_STATUS'], ['PENDENTE', 'PROCESSANDO'], true));
            if ($naFila && !$r['pendente'] && (int)$l['IDADE_MIN'] < self::HORAS_BLOQUEIO * 60) $r['pendente'] = ['usuario' => (string)$l['USUARIO'], 'criado_em' => (string)$l['CRIADO_EM'], 'meu' => (int)$l['CODUSUARIO'] === $uid];
            if (!$naFila && !$r['pronta'] && (int)$l['CODUSUARIO'] === $uid && ($l['STATUS'] === 'SUGERIDA' || (string)$l['JOB_STATUS'] === 'CONCLUIDA')) $r['pronta'] = (int)$l['CODANALISE'];
        }
        return $r;
    }

    /** Termos de busca das NCs semelhantes: palavras significativas do sumário (primeiro) e da descrição. */
    public static function termos(string $sumario, string $descricao, int $maximo = 8): array
    {
        $conta = [];
        foreach ([[$sumario, 3], [$descricao, 1]] as [$texto, $peso]) {
            $texto = mb_strtolower(strip_tags($texto), 'UTF-8');
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $p) {
                if (mb_strlen($p, 'UTF-8') < 4 || ctype_digit($p) || in_array($p, self::STOP, true)) continue;
                $p = mb_substr($p, 0, 40, 'UTF-8');
                $conta[$p] = ($conta[$p] ?? 0) + $peso;
            }
        }
        arsort($conta);
        return array_slice(array_keys($conta), 0, $maximo);
    }

    /** NCs semelhantes que o usuário pode ver, com as causas e ações delas. */
    public static function semelhantes(PDO $pdo, int $uid, int $codigo, array $R, array $permissoes): array
    {
        $termos = self::termos((string)($R['IDENTIFICACAO'] ?? ''), (string)($R['DESCRICAO'] ?? ''));
        if (!$termos) return [];
        $s = $pdo->prepare('CALL sp_nc_ia_semelhantes(?, ?, ?)');
        $s->execute([$codigo, json_encode($termos, JSON_UNESCAPED_UNICODE), 20]);
        $candidatos = $s->fetchAll(PDO::FETCH_ASSOC);
        do { try { $s->fetchAll(); } catch (Throwable) {} } while ($s->nextRowset());
        $s->closeCursor();
        $out = [];
        foreach ($candidatos as $c) {
            if (count($out) >= self::MAX_SEMELHANTES) break;
            $cod = (int)$c['CODOCORRENCIA'];
            if (!NaoConformidadeWorkflow::podeVer($pdo, $uid, $cod, $permissoes)) continue;
            $c['CAUSAS'] = array_map(static fn($x) => trim(((string)$x['DSCTIPOCAUSA'] !== '' ? $x['DSCTIPOCAUSA'] . ': ' : '') . (string)$x['DSCAUSA']), NaoConformidadeWorkflow::listar($pdo, 'vw_nc_causas', $cod, 'CODCAUSA'));
            $c['ACOES'] = array_map(static fn($x) => trim((string)$x['ACAO']) . ((int)$x['STATUS'] === 100 ? ' (concluída)' : ''), NaoConformidadeWorkflow::listar($pdo, 'vw_nc_planodeacao', $cod, 'CODPLANOACAO'));
            $out[] = $c;
        }
        return $out;
    }

    public static function corta(mixed $v, int $n): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$v)) ?? '');
        return mb_strlen($t, 'UTF-8') > $n ? mb_substr($t, 0, $n - 1, 'UTF-8') . '…' : $t;
    }

    /** Texto enviado à IA (mensagem do usuário; o prompt cadastrado vai como instrução do sistema). */
    public static function contexto(array $R, array $causas, array $planos, array $tiposCausa, array $semelhantes): string
    {
        $l = ['OCORRÊNCIA ' . ($R['NRANO'] ?? $R['CODOCORRENCIA'] ?? '')];
        foreach (['DSCTIPONAOCONFORMIDADE' => 'Tipo de não conformidade', 'DSCTIPOOCORRENCIA' => 'Classificação', 'DSCTIPOACAO' => 'Tipo de ocorrência', 'DSCORIGEMACAO' => 'Origem',
            'DSCSIGLAGESTAO' => 'Sistema de gestão', 'DSCUNIDADE' => 'Unidade', 'DSCAREA' => 'Área', 'DSCSETOR' => 'Setor', 'RAZAOSOCIAL' => 'Fornecedor', 'DSCCLIENTE' => 'Cliente',
            'DSCPRODUTO' => 'Produto', 'DSCTIPORECLAMACAO' => 'Tipo de reclamação', 'DATA_OCORRENCIA' => 'Data da ocorrência', 'PARADANALINHA' => 'Parada de linha',
            'QTDENVOLVIDA' => 'Quantidade envolvida', 'TAMANHOLOTEENVOLVIDO' => 'Lote', 'IDENTIFICACAO' => 'Sumário', 'DESCRICAO' => 'Descrição',
            'PROVIDENCIA' => 'Dados complementares', 'ANALISE_DA_PROVIDENCIA' => 'Ação imediata (avaliação do gestor)', 'ANALISE_ABRANGENCIA' => 'Abrangência'] as $c => $rot) {
            $v = self::corta($R[$c] ?? '', in_array($c, ['DESCRICAO', 'PROVIDENCIA', 'ANALISE_DA_PROVIDENCIA', 'ANALISE_ABRANGENCIA'], true) ? 3000 : 300);
            if ($v !== '' && $v !== '0' && !str_starts_with($v, '0000')) $l[] = $rot . ': ' . $v;
        }
        $l[] = '';
        $l[] = 'ANÁLISE JÁ REGISTRADA: ' . (self::corta($R['ANALISE_DA_CAUSA'] ?? '', 3000) ?: '(vazia)');
        $l[] = 'CAUSAS JÁ CADASTRADAS: ' . ($causas ? '' : '(nenhuma)');
        foreach ($causas as $c) $l[] = '- ' . ((string)$c['DSCTIPOCAUSA'] !== '' ? $c['DSCTIPOCAUSA'] . ': ' : '') . self::corta($c['DSCAUSA'], 500);
        $l[] = 'AÇÕES JÁ CADASTRADAS: ' . ($planos ? '' : '(nenhuma)');
        foreach ($planos as $p) $l[] = '- ' . self::corta($p['ACAO'], 400) . ' (status ' . (int)$p['STATUS'] . '%)';
        $l[] = '';
        $l[] = 'TIPOS DE CAUSA DA EMPRESA (use exatamente um destes nomes em tipo_causa): ' . ($tiposCausa ? implode(' | ', array_values($tiposCausa)) : '(nenhum cadastrado: use null)');
        $l[] = '';
        $l[] = 'NCS SEMELHANTES JÁ TRATADAS NA EMPRESA: ' . ($semelhantes ? '' : '(nenhuma encontrada)');
        foreach ($semelhantes as $s) {
            $l[] = '[' . ($s['NRANO'] ?: $s['CODOCORRENCIA']) . '] situação: ' . self::corta(($s['ETAPA'] ?? '') . ((string)($s['SITUACAO'] ?? '') !== '' ? ' / ' . $s['SITUACAO'] : ''), 120);
            $l[] = '  Sumário: ' . self::corta($s['IDENTIFICACAO'], 300);
            if (($d = self::corta($s['DESCRICAO'], 500)) !== '') $l[] = '  Descrição: ' . $d;
            if (($a = self::corta($s['ANALISE_DA_CAUSA'], 500)) !== '') $l[] = '  Análise da causa: ' . $a;
            foreach (array_slice($s['CAUSAS'], 0, 4) as $c) $l[] = '  Causa: ' . self::corta($c, 300);
            foreach (array_slice($s['ACOES'], 0, 4) as $a) $l[] = '  Ação: ' . self::corta($a, 300);
        }
        return implode("\n", $l);
    }

    /** Prompt ativo do cadastro (Setup › Prompts de IA) no idioma do usuário. */
    public static function prompt(PDO $pdo, string $codigo, string $idioma, string $migracao): string
    {
        $prompt = '';
        try {
            $s = $pdo->prepare('CALL sp_ia_prompt_obter(?, ?)'); $s->execute([$codigo, $idioma]);
            $prompt = trim((string)($s->fetch(PDO::FETCH_ASSOC)['prompt'] ?? '')); $s->closeCursor();
        } catch (Throwable) {}
        if ($prompt === '') throw new RuntimeException('O prompt ' . $codigo . ' não está cadastrado ou está inativo (Setup › Prompts de IA; migração ' . $migracao . ').');
        return $prompt;
    }

    /** Bloqueio: um pedido do mesmo tipo ainda na fila para esta NC (de qualquer usuário). */
    public static function exigirSemPendente(PDO $pdo, int $codigo, int $uid, string $tipo): void
    {
        $p = self::situacao($pdo, $codigo, $uid, $tipo)['pendente'];
        if ($p) throw new RuntimeException(($p['meu'] ? 'Você já pediu' : $p['usuario'] . ' já pediu') . ($tipo === 'EFICACIA' ? ' o parecer da IA sobre a eficácia' : ' uma análise da IA')
            . ' para esta NC em ' . date('d/m/Y H:i', strtotime($p['criado_em']) ?: time()) . '. Aguarde a resposta nos Alertas para pedir de novo.');
    }

    /** Põe na fila de IA (serviço "ia" do Agendador) e registra a análise (nc_ia_analises). $tipoJob: NC_ANALISE_CAUSA | NC_EFICACIA. */
    public static function enfileirar(PDO $pdo, int $uid, string $idioma, int $codigo, string $tipo, string $tipoJob, string $prompt, string $texto, array $ref): int
    {
        $hash = hash('sha256', $prompt . "\n" . $texto);
        $job = (new DocumentAiQueueService($pdo))->enqueue(['chave_idempotencia' => 'IA:' . $tipoJob . ':' . $codigo . ':' . substr($hash, 0, 32) . ':' . bin2hex(random_bytes(6)),
            'tipo' => $tipoJob, 'entidade' => 'nc_indicacao_ocorrencia', 'registro_id' => (string)$codigo, 'arquivo' => strtolower($tipoJob),
            'codanalise_ged' => 0, 'codusuario' => $uid, 'idioma' => $idioma, 'prompt' => $prompt, 'texto' => $texto]);
        $r = NaoConformidadeWorkflow::chamar($pdo, 'sp_nc_ia_analise_registrar', [$codigo, $tipo, (int)$job['codjob'], $uid, $hash, json_encode($ref, JSON_UNESCAPED_UNICODE)]);
        return (int)($r['CODANALISE'] ?? 0);
    }

    /** Põe o pedido de análise da causa na fila de IA e registra a análise. */
    public static function solicitar(PDO $pdo, int $uid, string $idioma, int $codigo, array $R, array $causas, array $planos, array $tiposCausa, array $permissoes): array
    {
        $prompt = self::prompt($pdo, self::PROMPT, $idioma, '336');
        self::exigirSemPendente($pdo, $codigo, $uid, 'CAUSA');
        $semelhantes = self::semelhantes($pdo, $uid, $codigo, $R, $permissoes);
        $texto = self::contexto($R, $causas, $planos, $tiposCausa, $semelhantes);
        $ref = array_map(static fn($s) => ['codigo' => (int)$s['CODOCORRENCIA'], 'nrano' => (string)($s['NRANO'] ?: $s['CODOCORRENCIA']), 'sumario' => self::corta($s['IDENTIFICACAO'], 160), 'etapa' => (string)$s['ETAPA'], 'situacao' => (string)($s['SITUACAO'] ?? '')], $semelhantes);
        return ['codanalise' => self::enfileirar($pdo, $uid, $idioma, $codigo, 'CAUSA', 'NC_ANALISE_CAUSA', $prompt, $texto, $ref)];
    }

    /**
     * Estado de uma análise do usuário (qualquer tipo): ['status' => NA_FILA | ERRO | SUGERIDA, 'bruto' => resposta da IA quando
     * SUGERIDA, 'semelhantes' => referências gravadas no pedido, ...]. Guarda a resposta na primeira leitura (sp_nc_ia_analise_resultado).
     */
    public static function estado(PDO $pdo, int $uid, int $codigo, int $codanalise, string $tipo): array
    {
        $s = $pdo->prepare('SELECT * FROM vw_nc_ia_analises WHERE CODANALISE = ? AND CODOCORRENCIA = ? AND CODUSUARIO = ? AND TIPO = ?');
        $s->execute([$codanalise, $codigo, $uid, $tipo]); $a = $s->fetch(PDO::FETCH_ASSOC) ?: null; $s->closeCursor();
        if (!$a) throw new RuntimeException('Resposta da IA não encontrada.');
        $base = ['codanalise' => $codanalise, 'semelhantes' => json_decode((string)$a['SEMELHANTES'], true) ?: [], 'criado_em' => (string)$a['CRIADO_EM']];
        if (in_array($a['STATUS'], ['APLICADA', 'DESCARTADA', 'SUBSTITUIDA'], true)) throw new RuntimeException('Esta resposta da IA já foi encerrada. Peça uma nova.');
        if ($a['STATUS'] === 'ERRO') return $base + ['status' => 'ERRO', 'erro' => (string)$a['ERRO']];
        if ($a['STATUS'] === 'EXTRAINDO') return $base + ['status' => 'NA_FILA', 'job_status' => 'lendo os arquivos de evidência'];
        if ($a['STATUS'] === 'SUGERIDA' && (string)$a['SUGESTAO'] !== '') return $base + ['status' => 'SUGERIDA', 'bruto' => json_decode((string)$a['SUGESTAO'], true) ?: []];
        $job = (string)($a['JOB_STATUS'] ?? '');
        if ($job === 'ERRO' || $job === 'CANCELADA' || $job === '') {
            $erro = $job === '' ? 'O pedido não está mais na fila de IA.' : ((string)$a['JOB_ERRO'] ?: 'Falha na análise da IA.');
            NaoConformidadeWorkflow::chamar($pdo, 'sp_nc_ia_analise_resultado', [$codanalise, $uid, null, mb_substr($erro, 0, 2000)]);
            return $base + ['status' => 'ERRO', 'erro' => $erro];
        }
        if ($job !== 'CONCLUIDA') return $base + ['status' => 'NA_FILA', 'job_status' => $job];
        $r = json_decode((string)$a['JOB_RESULTADO'], true);
        $bruto = is_array($r['sugestao'] ?? null) ? $r['sugestao'] : null;
        if ($bruto === null) {
            NaoConformidadeWorkflow::chamar($pdo, 'sp_nc_ia_analise_resultado', [$codanalise, $uid, null, 'A IA devolveu um resultado inválido.']);
            return $base + ['status' => 'ERRO', 'erro' => 'A IA devolveu um resultado inválido. Peça de novo.'];
        }
        NaoConformidadeWorkflow::chamar($pdo, 'sp_nc_ia_analise_resultado', [$codanalise, $uid, json_encode($bruto, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), null]);
        return $base + ['status' => 'SUGERIDA', 'bruto' => $bruto];
    }

    /** Situação da análise da causa; quando a IA respondeu, a sugestão conferida (tipos de causa e responsáveis só dos cadastrados). */
    public static function consultar(PDO $pdo, int $uid, int $codigo, int $codanalise, array $tiposCausa, array $usuarios, array $causasAtuais): array
    {
        $r = self::estado($pdo, $uid, $codigo, $codanalise, 'CAUSA');
        if (isset($r['bruto'])) { $r['sugestao'] = self::normalizar($r['bruto'], $tiposCausa, $usuarios, $causasAtuais); unset($r['bruto']); }
        return $r;
    }

    public static function chave(string $s): string
    {
        $s = class_exists('Normalizer') ? (string)Normalizer::normalize($s, Normalizer::FORM_D) : $s;
        $s = preg_replace('/\p{Mn}+/u', '', $s) ?? $s;
        return trim(preg_replace('/\s+/u', ' ', mb_strtoupper($s, 'UTF-8')) ?? '');
    }

    /** Sugestão conferida: textos limitados, tipo de causa ligado ao cadastro, prioridades e prazos válidos, ações ligadas às causas. */
    public static function normalizar(array $s, array $tiposCausa, array $usuarios, array $causasAtuais = []): array
    {
        $txt = static fn($v, int $n): string => is_scalar($v) ? self::corta($v, $n) : '';
        $lista = static fn($v, int $max, int $n): array => array_values(array_slice(array_filter(array_map(static fn($x) => is_scalar($x) ? self::corta($x, $n) : '', is_array($v) ? $v : [])), 0, $max));
        $tipos = [];
        foreach ($tiposCausa as $cod => $nome) $tipos[self::chave((string)$nome)] = (string)$cod;
        $nomesUsuarios = [];
        foreach ($usuarios as $cod => $nome) $nomesUsuarios[self::chave((string)$nome)] = (string)$cod;
        $alertas = $lista($s['alertas'] ?? [], 8, 300);
        $causas = [];
        foreach (array_slice(is_array($s['causas'] ?? null) ? $s['causas'] : [], 0, self::MAX_CAUSAS) as $c) {
            if (!is_array($c) || ($d = $txt($c['descricao'] ?? '', 500)) === '') continue;
            $tipoNome = $txt($c['tipo_causa'] ?? '', 120);
            $codTipo = $tipoNome !== '' ? ($tipos[self::chave($tipoNome)] ?? '') : '';
            $conf = (float)($c['confianca'] ?? 0); if ($conf > 0 && $conf <= 1) $conf *= 100;
            $causas[] = ['descricao' => $d, 'tipo_causa' => $tipoNome, 'codtipocausa' => $codTipo, 'justificativa' => $txt($c['justificativa'] ?? '', 600),
                'confianca' => (int)max(0, min(100, round($conf))), 'base' => $lista($c['base'] ?? [], 5, 40)];
            if ($tipoNome !== '' && $codTipo === '') $alertas[] = 'Tipo de causa sugerido "' . $tipoNome . '" não está no cadastro: escolha um tipo na causa ' . count($causas) . '.';
        }
        $acoes = [];
        $duplicadas = array_map(static fn($c) => self::chave((string)$c['DSCAUSA']), $causasAtuais);
        foreach (array_slice(is_array($s['acoes'] ?? null) ? $s['acoes'] : [], 0, self::MAX_ACOES) as $a) {
            if (!is_array($a) || ($t = $txt($a['acao'] ?? '', 2000)) === '') continue;
            $idx = (int)($a['causa'] ?? 1) - 1;
            if ($idx < 0 || $idx >= count($causas)) $idx = $causas ? 0 : -1;
            $prio = self::chave($txt($a['prioridade'] ?? '', 20));
            $prio = match (true) { str_starts_with($prio, 'A') || str_starts_with($prio, 'H') => 'Alta', str_starts_with($prio, 'B') || str_starts_with($prio, 'L') => 'Baixa', $prio !== '' => 'Média', default => '' };
            $prazo = (int)($a['prazo_dias'] ?? 0); $prazo = $prazo > 0 ? min($prazo, 365) : 30;
            $resp = $txt($a['responsavel'] ?? '', 120);
            $acoes[] = ['acao' => $t, 'causa' => $idx, 'prioridade' => $prio, 'prazo_dias' => $prazo, 'responsavel' => $resp,
                'codusuario' => $resp !== '' ? ($nomesUsuarios[self::chave($resp)] ?? '') : '', 'recursos' => $txt($a['recursos'] ?? '', 1000), 'base' => $lista($a['base'] ?? [], 5, 40)];
        }
        foreach ($causas as $i => $c) {
            if (in_array(self::chave($c['descricao']), $duplicadas, true)) $alertas[] = 'A causa ' . ($i + 1) . ' já está cadastrada nesta ocorrência.';
            if (!array_filter($acoes, static fn($a) => $a['causa'] === $i)) $alertas[] = 'A IA não sugeriu ação para a causa ' . ($i + 1) . ': para enviar à eficácia, cada causa precisa de pelo menos 1 ação.';
        }
        return ['resumo_problema' => $txt($s['resumo_problema'] ?? '', 600), 'cinco_porques' => $lista($s['cinco_porques'] ?? [], 5, 300),
            'causas' => $causas, 'acoes' => $acoes, 'analise_da_causa' => $txt($s['analise_da_causa'] ?? '', 4000), 'alertas' => array_values(array_unique($alertas))];
    }

    public static function data(mixed $v): ?string
    {
        $v = substr(trim((string)$v), 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)) ? $v : null;
    }

    /**
     * Grava o que o responsável marcou, numa transação:
     * $dados = ['causas' => [['ref' => 'n0', 'CODTIPOCAUSA' => '1', 'DSCAUSA' => '...'], ...],
     *           'acoes' => [['causa' => 'n0' | '<CODCAUSA existente>', 'ACAO', 'PRIORIDADE', 'CODUSUARIO', 'DATAINICIAL', 'DATAFINAL', 'RECURSOS'], ...],
     *           'analise' => ['modo' => 'manter' | 'acrescentar' | 'substituir', 'texto' => '...']]
     * $responsaveis = usuários que podem ser responsáveis de ação (id => nome; o fornecedor externo tem a lista restrita, c519).
     */
    public static function aplicar(PDO $pdo, int $uid, bool $admin, int $codigo, int $codanalise, array $dados, array $R, array $causasAtuais, array $tiposCausa, array $responsaveis): array
    {
        $causasIn = array_values(array_filter(is_array($dados['causas'] ?? null) ? $dados['causas'] : [], 'is_array'));
        $acoesIn = array_values(array_filter(is_array($dados['acoes'] ?? null) ? $dados['acoes'] : [], 'is_array'));
        $analise = is_array($dados['analise'] ?? null) ? $dados['analise'] : [];
        $modo = in_array($analise['modo'] ?? '', ['acrescentar', 'substituir'], true) ? (string)$analise['modo'] : 'manter';
        $textoAnalise = trim((string)($analise['texto'] ?? ''));
        if (!$causasIn && !$acoesIn && ($modo === 'manter' || $textoAnalise === '')) throw new RuntimeException('Marque pelo menos uma causa, uma ação ou o texto da análise para aplicar.');
        if (count($causasIn) > self::MAX_CAUSAS || count($acoesIn) > self::MAX_ACOES) throw new RuntimeException('Sugestão com itens demais.');
        $existentes = array_map('intval', array_column($causasAtuais, 'CODCAUSA'));
        $ad = $admin ? 1 : 0;
        $feito = ['causas' => [], 'acoes' => [], 'analise' => $modo];
        $pdo->beginTransaction();
        try {
            $ref = [];
            foreach ($causasIn as $i => $c) {
                $d = trim((string)($c['DSCAUSA'] ?? ''));
                if ($d === '') throw new RuntimeException('Descreva a causa ' . ($i + 1) . ' ou desmarque-a.');
                $tipo = (string)($c['CODTIPOCAUSA'] ?? '');
                if ($tipo !== '' && !isset($tiposCausa[$tipo])) throw new RuntimeException('Tipo de causa inválido na causa ' . ($i + 1) . '.');
                $r = NaoConformidadeWorkflow::chamar($pdo, 'sp_nc_causa_salvar', [null, $codigo, (int)$tipo, mb_substr($d, 0, 500), $uid, $ad]);
                $ref[(string)($c['ref'] ?? ('n' . $i))] = (int)($r['CODCAUSA'] ?? 0);
                $feito['causas'][] = ['CODCAUSA' => (int)($r['CODCAUSA'] ?? 0), 'DSCAUSA' => mb_substr($d, 0, 500)];
            }
            foreach ($acoesIn as $i => $a) {
                $t = trim((string)($a['ACAO'] ?? ''));
                if ($t === '') throw new RuntimeException('Descreva a ação ' . ($i + 1) . ' ou desmarque-a.');
                $causa = (string)($a['causa'] ?? '');
                $codCausa = $ref[$causa] ?? (ctype_digit($causa) && in_array((int)$causa, $existentes, true) ? (int)$causa : 0);
                if ($codCausa < 1) throw new RuntimeException('Escolha a causa da ação ' . ($i + 1) . ' (a causa sugerida dela não foi marcada).');
                $resp = (string)(int)($a['CODUSUARIO'] ?? 0);
                if ($resp !== '0' && !isset($responsaveis[$resp])) throw new RuntimeException('Responsável inválido na ação ' . ($i + 1) . '.');
                $ini = self::data($a['DATAINICIAL'] ?? ''); $fim = self::data($a['DATAFINAL'] ?? '');
                if ($ini && $fim && $fim < $ini) throw new RuntimeException('Na ação ' . ($i + 1) . ' o fim é anterior ao início.');
                $plano = ['CODCAUSA' => $codCausa, 'ACAO' => $t, 'PRIORIDADE' => mb_substr(trim((string)($a['PRIORIDADE'] ?? '')), 0, 500), 'CODUSUARIO' => $resp === '0' ? '' : $resp,
                    'DATAINICIAL' => $ini, 'DATAFINAL' => $fim, 'RECURSOS' => trim((string)($a['RECURSOS'] ?? '')), 'STATUS' => '0', 'OBS' => 'Sugerida pela IA e revisada pelo responsável.'];
                $r = NaoConformidadeWorkflow::chamar($pdo, 'sp_nc_plano_salvar', [null, $codigo, json_encode($plano, JSON_UNESCAPED_UNICODE), $uid, $ad]);
                $feito['acoes'][] = ['CODPLANOACAO' => (int)($r['CODPLANOACAO'] ?? 0), 'CODCAUSA' => $codCausa, 'ACAO' => mb_substr($t, 0, 300)];
            }
            if ($modo !== 'manter' && $textoAnalise !== '') {
                $atual = trim((string)($R['ANALISE_DA_CAUSA'] ?? ''));
                $novo = $modo === 'acrescentar' && $atual !== '' ? $atual . "\n\n" . $textoAnalise : $textoAnalise;
                NaoConformidadeWorkflow::chamar($pdo, 'sp_nc_analise_salvar', [$codigo, json_encode(['ANALISE_DA_CAUSA' => $novo], JSON_UNESCAPED_UNICODE), $uid, 0, $ad]);
            } else $feito['analise'] = 'manter';
            $resumo = count($feito['causas']) . ' causa(s) e ' . count($feito['acoes']) . ' ação(ões) incluída(s)' . ($feito['analise'] === 'manter' ? '' : '; texto da análise ' . ($feito['analise'] === 'acrescentar' ? 'complementado' : 'substituído'));
            NaoConformidadeWorkflow::chamar($pdo, 'sp_nc_ia_analise_encerrar', [$codanalise, $codigo, 'APLICADA', json_encode($feito, JSON_UNESCAPED_UNICODE), 'Sugestão da IA revisada: ' . $resumo . '.', $uid]);
            $pdo->commit();
        } catch (Throwable $x) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $x;
        }
        return $feito + ['resumo' => $resumo];
    }

    public static function descartar(PDO $pdo, int $uid, int $codigo, int $codanalise): void
    {
        NaoConformidadeWorkflow::chamar($pdo, 'sp_nc_ia_analise_encerrar', [$codanalise, $codigo, 'DESCARTADA', null, 'Sugestão da IA descartada (nada foi gravado).', $uid]);
    }
}
