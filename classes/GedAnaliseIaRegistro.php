<?php
declare(strict_types=1);

require_once __DIR__ . '/IdiomaService.php';

/**
 * Histórico das análises de documentos por IA feitas na Gestão de Arquivos (gestao_arquivos.php),
 * para todos os tipos (licenças, controles, infraestrutura, treinamentos, funcionários).
 *
 * Cada solicitação gera um registro em ged_analises_ia e um alerta para o usuário ("iniciada");
 * o resultado (concluída ou erro) gera outro alerta, com link para a aba "Análises de IA" do arquivo.
 * Se a tabela ainda não existir (migração 222 não aplicada), nada é gravado e a análise segue normalmente.
 */
final class GedAnaliseIaRegistro
{
    private const ROTULOS = [
        'licencas' => ['pt' => 'Licença', 'en' => 'License', 'es' => 'Licencia'],
        'controles' => ['pt' => 'Controle', 'en' => 'Control', 'es' => 'Control'],
        'documentos' => ['pt' => 'Documento', 'en' => 'Document', 'es' => 'Documento'],
        'infraestrutura' => ['pt' => 'Infraestrutura', 'en' => 'Infrastructure', 'es' => 'Infraestructura'],
        'treinamentos' => ['pt' => 'Treinamento', 'en' => 'Training', 'es' => 'Capacitación'],
        'funcionarios' => ['pt' => 'Funcionário', 'en' => 'Employee', 'es' => 'Empleado'],
        'fornecedorcriterios' => ['pt' => 'Documento do fornecedor', 'en' => 'Supplier document', 'es' => 'Documento del proveedor'],
        'funcionariocriterios' => ['pt' => 'Documento do funcionário', 'en' => 'Employee document', 'es' => 'Documento del empleado'],
        'planodeacao' => ['pt' => 'Plano de ação da condicionante', 'en' => 'Condition action plan', 'es' => 'Plan de acción de la condicionante'],
    ];

    /** Campos usados no resumo, na ordem em que aparecem. */
    private const CAMPOS_RESUMO = ['tipo_documento', 'aso_resultado', 'aso_data_exame', 'aso_validade', 'treinamento_nome', 'treinamento_validade',
        'descricao', 'titulo', 'codigo', 'numero_licenca', 'numero_documento', 'orgao_emissor', 'data_validade', 'atividade', 'acao', 'data_final', 'status'];

    public function __construct(private readonly PDO $pdo, private readonly int $usuario) {}

    public static function link(string $tipo, string $chave, int $valor, string $arquivo): string
    {
        return '/principal/gestao_arquivos.php?' . http_build_query(['tipo' => $tipo, 'chave' => $chave, 'valor' => $valor, 'aba' => 'analises', 'arquivo' => $arquivo]);
    }

    /** Perfis que podem mandar à IA, de novo, um documento já analisado (com confirmação na tela). */
    private const PERFIS_REANALISE = ['ADM DA EMPRESA', 'ADM EMPRESA', 'ADMINISTRADOR'];

    public static function podeReanalisar(array $sessao): bool
    {
        $perfil = strtoupper(trim((string)($sessao['usuario']['codigo_perfil'] ?? '')));
        $perfil = preg_replace('/\s+/', ' ', preg_replace('/[_-]+/', ' ', $perfil) ?? $perfil) ?? $perfil;
        return in_array($perfil, self::PERFIS_REANALISE, true);
    }

    /** Impressão digital do conteúdo do arquivo: o mesmo documento é reconhecido mesmo com outro nome. */
    public static function hash(string $caminho): string
    {
        return is_file($caminho) ? (string)hash_file('sha256', $caminho) : '';
    }

    /**
     * Controle de duplicatas, chamado antes de qualquer envio à IA. Devolve a decisão:
     *   DUPLICADO     — o documento já foi analisado neste registro (concluída, confirmada ou descartada): não envia;
     *   EM_ANDAMENTO  — já há uma análise deste documento em andamento (menos de 10 minutos): não envia;
     *   REAPROVEITAR  — o mesmo documento já foi lido pela IA em outro registro do mesmo tipo: reaproveita a leitura,
     *                   sem novo envio (as conferências do registro são refeitas por quem chama);
     *   ENVIAR        — documento novo (ou reanálise forçada por perfil autorizado).
     * Em REAPROVEITAR e ENVIAR a nova análise já fica registrada (codanalise) e o alerta de início é criado.
     */
    public function preparar(string $tipo, string $chave, int $valor, string $arquivo, string $caminho, bool $forcar, array $sessao, string $descricao = ''): array
    {
        $hash = self::hash($caminho);
        if ($forcar && !self::podeReanalisar($sessao)) throw new RuntimeException(IdiomaService::t('ged_ia.reanalise_negada', ['pt' => 'Somente o perfil ADM da empresa pode enviar novamente um documento já analisado.', 'en' => 'Only the company ADM profile can resend an already analyzed document.', 'es' => 'Solo el perfil ADM de la empresa puede reenviar un documento ya analizado.']));
        if ($hash !== '' && !$forcar) {
            try {
                $q = $this->pdo->prepare("SELECT * FROM vw_ged_analises_ia WHERE tipo = ? AND codigo_registro = ? AND hash_arquivo = ?
                    AND (status IN ('CONCLUIDA','CONFIRMADA','DESCARTADA') OR (status = 'PROCESSANDO' AND solicitado_em >= NOW() - INTERVAL 10 MINUTE))
                    ORDER BY codanalise DESC LIMIT 1");
                $q->execute([$tipo, $valor, $hash]);
                if ($a = $q->fetch(PDO::FETCH_ASSOC)) return ['acao' => $a['status'] === 'PROCESSANDO' ? 'EM_ANDAMENTO' : 'DUPLICADO', 'analise' => $this->completar($a)];
                $q = $this->pdo->prepare("SELECT * FROM vw_ged_analises_ia WHERE tipo = ? AND hash_arquivo = ? AND dados_ia IS NOT NULL AND status IN ('CONCLUIDA','CONFIRMADA','DESCARTADA') ORDER BY codanalise DESC LIMIT 1");
                $q->execute([$tipo, $hash]);
                if ($origem = $q->fetch(PDO::FETCH_ASSOC)) {
                    $codigo = $this->iniciar($tipo, $chave, $valor, $arquivo, $descricao, $hash, (int)$origem['codanalise']);
                    return ['acao' => 'REAPROVEITAR', 'codanalise' => $codigo, 'origem' => $this->completar($origem)];
                }
            } catch (Throwable $e) {
                error_log('GedAnaliseIaRegistro::preparar ' . $e->getMessage()); // sem a migração 223: segue sem controle
            }
        }
        return ['acao' => 'ENVIAR', 'codanalise' => $this->iniciar($tipo, $chave, $valor, $arquivo, $descricao, $hash)];
    }

    /** Resposta padrão da API quando o documento já foi analisado ou está em análise. */
    public static function respostaDuplicado(array $decisao): array
    {
        $a = $decisao['analise'];
        $quando = !empty($a['solicitado_em']) ? date('d/m/Y H:i', strtotime((string)$a['solicitado_em'])) : '';
        $mensagem = $decisao['acao'] === 'EM_ANDAMENTO'
            ? IdiomaService::t('ged_ia.em_andamento', ['pt' => 'Este documento já está sendo analisado. Aguarde a conclusão.', 'en' => 'This document is already being analyzed. Please wait.', 'es' => 'Este documento ya se está analizando. Espere la conclusión.'])
            : str_replace(['{data}', '{usuario}'], [$quando, (string)($a['usuario'] ?? '')], IdiomaService::t('ged_ia.ja_analisado', ['pt' => 'Este documento já foi analisado pela IA em {data} por {usuario}. Ele não é enviado novamente.', 'en' => 'This document was already analyzed by AI on {data} by {usuario}. It is not sent again.', 'es' => 'Este documento ya fue analizado por la IA el {data} por {usuario}. No se envía nuevamente.']));
        return ['ok' => true, 'duplicado' => true, 'situacao' => $decisao['acao'], 'message' => $mensagem, 'analise' => $a];
    }

    /** Linha de ged_analises_ia com dados decodificados e nome do usuário, no formato da aba Análises de IA. */
    private function completar(array $a): array
    {
        $a['dados_ia'] = json_decode((string)($a['dados_ia'] ?? ''), true);
        try {
            $q = $this->pdo->prepare("SELECT nome_exibicao FROM vw_usuarios_nome WHERE codusuario = ?");
            $q->execute([(int)($a['codusuario'] ?? 0)]);
            $a['usuario'] = (string)$q->fetchColumn();
        } catch (Throwable) { $a['usuario'] = ''; }
        return $a;
    }

    /** Registra a solicitação e avisa o usuário. Devolve o código da análise (0 se não foi possível registrar). */
    public function iniciar(string $tipo, string $chave, int $valor, string $arquivo, string $descricao = '', string $hash = '', ?int $origem = null): int
    {
        try {
            $q = $this->pdo->prepare('CALL sp_ged_analise_ia_iniciar(?, ?, ?, ?, ?, ?, ?, ?)');
            $q->execute([$tipo, $chave, $valor, $arquivo, mb_substr($descricao, 0, 255), (int)$this->usuario, $hash, $origem]);
            $codigo = (int)($q->fetchColumn() ?: 0);
            $q->closeCursor();
        } catch (Throwable $e) {
            error_log('GedAnaliseIaRegistro::iniciar ' . $e->getMessage());
            return 0;
        }
        $this->alerta(
            IdiomaService::t('ged_ia.alerta.iniciada', ['pt' => 'Análise de IA iniciada', 'en' => 'AI analysis started', 'es' => 'Análisis de IA iniciado']),
            $this->origem($tipo, $valor, $arquivo, $descricao) . ($origem ? ' — ' . IdiomaService::t('ged_ia.reaproveitada', ['pt' => 'leitura reaproveitada de análise anterior do mesmo documento, sem novo envio à IA', 'en' => 'reading reused from a previous analysis of the same document, not sent to the AI again', 'es' => 'lectura reaprovechada de un análisis anterior del mismo documento, sin nuevo envío a la IA']) : ''),
            self::link($tipo, $chave, $valor, $arquivo)
        );
        return $codigo;
    }

    public function concluir(int $codigo, array $sugestao, ?float $confianca = null, string $fonte = '', ?int $referencia = null): void
    {
        if ($codigo < 1) return;
        if ($confianca === null && isset($sugestao['confianca'])) $confianca = (float)$sugestao['confianca'];
        if ($confianca !== null && $confianca > 0 && $confianca <= 1) $confianca *= 100;
        $resumo = self::resumo($sugestao);
        try {
            $q = $this->pdo->prepare('CALL sp_ged_analise_ia_concluir(?, ?, ?, ?, ?, ?)');
            $q->execute([$codigo, $resumo, json_encode($sugestao, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $confianca, $fonte, $referencia]);
            $q->closeCursor();
            $a = $this->registro($codigo);
        } catch (Throwable $e) { error_log('GedAnaliseIaRegistro::concluir ' . $e->getMessage()); return; }
        if (!$a) return;
        $msg = $this->origem((string)$a['tipo'], (int)$a['codigo_registro'], (string)$a['arquivo'], (string)($a['descricao'] ?? '')) . ($resumo !== '' ? ' — ' . $resumo : '')
            . ($confianca !== null ? ' · ' . IdiomaService::t('ged_ia.confianca', ['pt' => 'confiança', 'en' => 'confidence', 'es' => 'confianza']) . ' ' . round($confianca) . '%' : '')
            . '. ' . IdiomaService::t('ged_ia.revise', ['pt' => 'Revise e confirme os dados.', 'en' => 'Review and confirm the data.', 'es' => 'Revise y confirme los datos.']);
        $this->alerta(IdiomaService::t('ged_ia.alerta.concluida', ['pt' => 'Análise de IA concluída', 'en' => 'AI analysis completed', 'es' => 'Análisis de IA concluido']), $msg,
            self::link((string)$a['tipo'], (string)$a['chave'], (int)$a['codigo_registro'], (string)$a['arquivo']));
    }

    public function falhar(int $codigo, string $mensagem): void
    {
        if ($codigo < 1) return;
        try {
            $q = $this->pdo->prepare('CALL sp_ged_analise_ia_falhar(?, ?)');
            $q->execute([$codigo, mb_substr($mensagem, 0, 1000)]);
            $q->closeCursor();
            $a = $this->registro($codigo);
        } catch (Throwable $e) { error_log('GedAnaliseIaRegistro::falhar ' . $e->getMessage()); return; }
        if (!$a) return;
        $this->alerta(IdiomaService::t('ged_ia.alerta.erro', ['pt' => 'Análise de IA não concluída', 'en' => 'AI analysis failed', 'es' => 'Análisis de IA no concluido']),
            $this->origem((string)$a['tipo'], (int)$a['codigo_registro'], (string)$a['arquivo'], (string)($a['descricao'] ?? '')) . ' — ' . $mensagem,
            self::link((string)$a['tipo'], (string)$a['chave'], (int)$a['codigo_registro'], (string)$a['arquivo']));
    }

    /** Confirmação ou descarte da sugestão pelo usuário (sem novo alerta). */
    public function encerrar(int $codigo, string $status): void
    {
        if ($codigo < 1 || !in_array($status, ['CONFIRMADA', 'DESCARTADA'], true)) return;
        try {
            $q = $this->pdo->prepare('CALL sp_ged_analise_ia_encerrar(?, ?, ?)');
            $q->execute([$codigo, $status, (int)$this->usuario]);
            $q->closeCursor();
        } catch (Throwable $e) { error_log('GedAnaliseIaRegistro::encerrar ' . $e->getMessage()); }
    }

    public function encerrarPorReferencia(string $tipo, int $referencia, string $status): void
    {
        try {
            $q = $this->pdo->prepare('SELECT codanalise FROM vw_ged_analises_ia WHERE tipo = ? AND referencia = ? ORDER BY codanalise DESC LIMIT 1');
            $q->execute([$tipo, $referencia]);
            $this->encerrar((int)$q->fetchColumn(), $status);
        } catch (Throwable $e) { error_log('GedAnaliseIaRegistro::encerrarPorReferencia ' . $e->getMessage()); }
    }

    public static function resumo(array $s): string
    {
        $partes = [];
        foreach (self::CAMPOS_RESUMO as $campo) {
            $v = $s[$campo] ?? null;
            if (is_scalar($v) && trim((string)$v) !== '') $partes[] = trim((string)$v);
            if (count($partes) >= 5) break;
        }
        $texto = implode(' · ', $partes);
        return mb_strlen($texto) > 400 ? mb_substr($texto, 0, 397) . '…' : $texto;
    }

    private function origem(string $tipo, int $valor, string $arquivo, string $descricao): string
    {
        $rotulo = isset(self::ROTULOS[$tipo]) ? IdiomaService::t('ged_ia.tipo.' . $tipo, self::ROTULOS[$tipo]) : $tipo;
        return $arquivo . ' (' . $rotulo . ' ' . ($descricao !== '' ? $descricao : (string)$valor) . ')';
    }

    private function registro(int $codigo): ?array
    {
        $q = $this->pdo->prepare('SELECT tipo, chave, codigo_registro, arquivo, descricao FROM vw_ged_analises_ia WHERE codanalise = ?');
        $q->execute([$codigo]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function alerta(string $titulo, string $mensagem, string $link): void
    {
        if ($this->usuario < 1) return;
        try {
            $q = $this->pdo->prepare("CALL sp_alerta_criar(?, ?, ?, ?, 'NAO')");
            $q->execute([$this->usuario, mb_substr($titulo, 0, 180), $mensagem, mb_substr($link, 0, 500)]);
            $q->closeCursor();
        } catch (Throwable $e) { error_log('GedAnaliseIaRegistro::alerta ' . $e->getMessage()); }
    }
}
