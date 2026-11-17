<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';
require_once __DIR__ . '/IdiomaService.php';

/**
 * Migração 348 — Integração de funcionários: questionário, avaliação e liberação (Onda 2 da apresentação Atlas).
 *
 * 1. Cadastro: o funcionário já está na Gestão de Funcionários; o questionário é o do tipo dele (Configuração; sem um próprio,
 *    o padrão da empresa). Os questionários são os do motor universal (Questionários de Integração de Funcionários).
 * 2. Questionário: quem cuida da integração gera o link (principal/homologacaofornecedores_integracao.php); o funcionário responde
 *    em integracao.php?e=<empresa>&t=<código>, sem senha, até o vencimento do link e uma vez só (no celular, no tablet da portaria
 *    ou no computador da sala de integração).
 * 3. Avaliação: a nota do motor de questionários (pontuação das respostas e pesos) contra a nota mínima sugere Aprovado/Reprovado;
 *    o responsável pela integração do fornecedor (ou quem gerou o link) recebe um alerta.
 * 4. Decisão: uma pessoa confirma. Aprovada: a data de realização vai para o funcionário (fo_funcionarios.DATA_VALIDADE, "Data de
 *    Realização da Integração"); a validade (realização + meses, vw_fo_integracao_validade — migração 350) é a que os relatórios e a
 *    lista da portaria usam; o certificado em PDF (válido até a validade) fica nos arquivos do funcionário (GED fo_funcionarios).
 * O código do link não fica gravado: só o hash (SHA-256). Banco só por procedures e views (database/348_integracao_funcionarios.sql).
 */
final class IntegracaoFuncionario
{
    public const MENU = 'HOMOLOGACAO_INTEGRACAO_FUNCIONARIOS';
    public const TELA = '/principal/homologacaofornecedores_integracao.php';
    public const PUBLICA = 'integracao.php';

    public static function chamar(PDO $pdo, string $sql, array $p = []): array
    {
        $s = $pdo->prepare($sql); $s->execute($p);
        $r = [];
        try { $r = $s->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable) {}
        while ($s->nextRowset()) { try { $s->fetchAll(); } catch (Throwable) {} }
        $s->closeCursor();
        return $r;
    }

    public static function disponivel(PDO $pdo): bool
    {
        try {
            $s = $pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME IN ('sp_fo_integracao_criar', 'sp_fo_integracao_decidir')");
            $n = (int)$s->fetchColumn(); $s->closeCursor();
            return $n === 2;
        } catch (Throwable) { return false; }
    }

    /** Código do link (mostrado uma vez) e o hash que fica no banco. */
    public static function novoToken(): array
    {
        $t = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        return [$t, hash('sha256', $t)];
    }

    public static function hashToken(string $t): string
    {
        return hash('sha256', $t);
    }

    /** Endereço do portal: GREENPORTAL_URL do .env; sem ele, o da requisição atual (http(s)://servidor/greenportal). */
    public static function baseUrl(array $env): string
    {
        $u = rtrim(trim((string)($env['GREENPORTAL_URL'] ?? '')), '/');
        if (preg_match('#^https?://#i', $u)) return $u;
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/greenportal/principal/x.php')));
        $dir = preg_replace('#/principal(/api)?$#', '', $dir) ?? $dir;
        return ($https ? 'https' : 'http') . '://' . $host . rtrim($dir, '/');
    }

    public static function link(array $env, string $empresa, string $token): string
    {
        return self::baseUrl($env) . '/' . self::PUBLICA . '?' . http_build_query(['e' => $empresa, 't' => $token]);
    }

    /** Gera o link de integração do funcionário. Devolve ['codintegracao', 'url', 'expira_em', 'questionario']. */
    public static function gerar(PDO $pdo, array $env, string $empresa, int $codfuncionario, int $usuario): array
    {
        [$token, $hash] = self::novoToken();
        $r = self::chamar($pdo, 'CALL sp_fo_integracao_criar(?, ?, ?)', [$codfuncionario, $hash, $usuario])[0] ?? [];
        return ['codintegracao' => (int)($r['CODINTEGRACAO'] ?? 0), 'url' => self::link($env, $empresa, $token), 'expira_em' => (string)($r['LINK_EXPIRA_EM'] ?? ''),
            'questionario' => (string)($r['CODIGO_QUESTIONARIO'] ?? ''), 'dias' => (int)($r['LINK_DIAS'] ?? 0)];
    }

    /** Mensagem de erro amigável para os SIGNAL das procedures. */
    public static function mensagem(Throwable $e): string
    {
        $m = $e->getMessage();
        $mapa = [
            'SEM_QUESTIONARIO' => ['pt' => 'Nenhum questionário configurado para o tipo deste funcionário nem questionário padrão. Use "Configurar questionários".', 'en' => 'No questionnaire configured for this employee type and no default one. Use "Configure questionnaires".', 'es' => 'Ningún cuestionario configurado para el tipo de este empleado ni cuestionario estándar. Use "Configurar cuestionarios".'],
            'QUESTIONARIO_NAO_PUBLICADO' => ['pt' => 'O questionário configurado ainda não foi publicado (Questionários de Integração de Funcionários).', 'en' => 'The configured questionnaire has not been published yet.', 'es' => 'El cuestionario configurado aún no fue publicado.'],
            'FUNCIONARIO_DESLIGADO' => ['pt' => 'Funcionário desligado: não recebe link de integração.', 'en' => 'Employee dismissed: no induction link.', 'es' => 'Empleado desvinculado: no recibe enlace de integración.'],
            'FUNCIONARIO_INEXISTENTE' => ['pt' => 'Funcionário não encontrado.', 'en' => 'Employee not found.', 'es' => 'Empleado no encontrado.'],
            'MOTIVO_OBRIGATORIO' => ['pt' => 'Escreva o motivo (obrigatório para reprovar ou para aprovar abaixo da nota mínima).', 'en' => 'Write the reason (required to reject or to approve below the minimum score).', 'es' => 'Escriba el motivo (obligatorio para rechazar o para aprobar debajo de la nota mínima).'],
            'INTEGRACAO_NAO_RESPONDIDA' => ['pt' => 'Esta integração não está aguardando decisão.', 'en' => 'This induction is not awaiting a decision.', 'es' => 'Esta integración no está esperando decisión.'],
            'INTEGRACAO_INEXISTENTE' => ['pt' => 'Integração não encontrada.', 'en' => 'Induction not found.', 'es' => 'Integración no encontrada.'],
            'QUESTIONARIO_OBRIGATORIO' => ['pt' => 'Escolha o questionário.', 'en' => 'Choose the questionnaire.', 'es' => 'Elija el cuestionario.'],
            'NOTA_INVALIDA' => ['pt' => 'Nota mínima de 0 a 100.', 'en' => 'Minimum score from 0 to 100.', 'es' => 'Nota mínima de 0 a 100.'],
            'VALIDADE_INVALIDA' => ['pt' => 'Validade de 1 a 120 meses.', 'en' => 'Validity from 1 to 120 months.', 'es' => 'Validez de 1 a 120 meses.'],
            'LINK_INVALIDO' => ['pt' => 'O link vale de 1 a 90 dias.', 'en' => 'The link is valid for 1 to 90 days.', 'es' => 'El enlace vale de 1 a 90 días.'],
            'TIPO_DUPLICADO' => ['pt' => 'Já existe configuração para este tipo de funcionário (ou padrão).', 'en' => 'This employee type (or the default) is already configured.', 'es' => 'Ya existe configuración para este tipo de empleado (o estándar).'],
            'TIPO_INEXISTENTE' => ['pt' => 'Tipo de funcionário não encontrado.', 'en' => 'Employee type not found.', 'es' => 'Tipo de empleado no encontrado.'],
        ];
        foreach ($mapa as $k => $v) if (str_contains($m, $k)) return IdiomaService::t('integracao.erro.' . strtolower($k), $v);
        error_log('[integração de funcionários] ' . $m);
        return IdiomaService::t('integracao.erro.geral', ['pt' => 'Não foi possível concluir a operação.', 'en' => 'The operation could not be completed.', 'es' => 'No fue posible concluir la operación.']);
    }

    /** Alerta da resposta ao responsável pela integração do fornecedor (senão a quem gerou o link). */
    public static function alertarRespondida(PDO $pdo, array $i, float $percentual, string $sugestao): void
    {
        $u = (int)($i['CODUSUARIO_RESPONSAVEL'] ?? 0) ?: (int)($i['CRIADA_POR'] ?? 0);
        if ($u < 1) return;
        $titulo = 'Integração respondida — ' . trim((string)$i['FUNCIONARIO']);
        $msg = trim((string)$i['FUNCIONARIO']) . ' (' . trim((string)$i['RAZAOSOCIAL']) . ') respondeu o questionário de integração: '
            . number_format($percentual, 1, ',', '.') . '%, sugestão ' . ($sugestao === 'APROVADO' ? 'APROVADO' : 'REPROVADO') . '. Confirme a decisão.';
        try {
            self::chamar($pdo, "CALL sp_alerta_criar(?, ?, ?, ?, 'NAO')", [$u, mb_substr($titulo, 0, 180), mb_substr($msg, 0, 1000), self::TELA . '?integracao=' . (int)$i['CODINTEGRACAO']]);
        } catch (Throwable $e) { error_log('[integração] alerta: ' . $e->getMessage()); }
    }

    /**
     * Certificado em PDF (dompdf) nos arquivos do funcionário (GED fo_funcionarios\<código>). Devolve o nome do arquivo.
     * $empresaNome: nome da empresa no cabeçalho (regra do usuário: todo PDF tem o nome da empresa).
     */
    public static function certificado(PDO $pdo, array $env, array $sessao, int $codintegracao, string $empresaNome): string
    {
        require_once dirname(__DIR__) . '/vendor/autoload.php';
        require_once __DIR__ . '/GedStorageService.php';
        $i = self::chamar($pdo, 'SELECT * FROM vw_fo_integracoes WHERE CODINTEGRACAO = ?', [$codintegracao])[0] ?? null;
        if (!$i || (string)$i['STATUS'] !== 'APROVADA') throw new RuntimeException('INTEGRACAO_NAO_APROVADA');
        $e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $d = static fn(?string $v): string => $v ? date('d/m/Y', strtotime($v)) : '—';
        $quem = (string)(self::chamar($pdo, 'SELECT nome_exibicao FROM vw_usuarios_nome WHERE codusuario = ?', [(int)$i['DECIDIDA_POR']])[0]['nome_exibicao'] ?? '');
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>
            @page{margin:28mm 22mm}body{font-family:DejaVu Sans,sans-serif;color:#1f2a24;font-size:11pt}
            .emp{font-size:10pt;color:#1e7b45;font-weight:bold;border-bottom:2px solid #1e7b45;padding-bottom:6px;margin-bottom:28px}
            h1{font-size:22pt;text-align:center;color:#1e7b45;margin:10px 0 24px}.c{text-align:center;line-height:1.7}
            .nome{font-size:16pt;font-weight:bold}.tab{margin:28px auto 0;border-collapse:collapse;width:80%}.tab td{border:1px solid #cfdcd4;padding:6px 10px;font-size:10pt}
            .tab td:first-child{background:#f1f7f3;width:42%}.rod{margin-top:40px;font-size:9pt;color:#6c7a72;text-align:center}</style></head><body>'
            . '<div class="emp">' . $e($empresaNome) . '</div>'
            . '<h1>Certificado de integração</h1>'
            . '<div class="c">Certificamos que<br><span class="nome">' . $e($i['FUNCIONARIO']) . '</span><br>'
            . ($i['RAZAOSOCIAL'] ? 'da empresa <b>' . $e($i['RAZAOSOCIAL']) . '</b><br>' : '')
            . 'concluiu a integração e foi aprovado(a) no questionário <b>' . $e($i['QUESTIONARIO'] ?: $i['CODIGO_QUESTIONARIO']) . '</b>.</div>'
            . '<table class="tab">'
            . '<tr><td>Nº do registro</td><td>' . $e($i['NRIDENTIFICACAO'] ?: '—') . '</td></tr>'
            . '<tr><td>Função / tipo</td><td>' . $e($i['DSCTIPO'] ?: '—') . '</td></tr>'
            . '<tr><td>Unidade</td><td>' . $e($i['DSCUNIDADE'] ?: '—') . '</td></tr>'
            . '<tr><td>Data da resposta</td><td>' . $d($i['RESPONDIDA_EM']) . '</td></tr>'
            . '<tr><td>Nota</td><td>' . $e(number_format((float)$i['PERCENTUAL'], 1, ',', '.')) . '% (mínima ' . $e(number_format((float)($i['NOTA_MINIMA'] ?? 70), 0, ',', '.')) . '%)</td></tr>'
            . '<tr><td>Aprovado por</td><td>' . $e($quem ?: '—') . ' em ' . $d($i['DECIDIDA_EM']) . '</td></tr>'
            . '<tr><td><b>Válido até</b></td><td><b>' . $d($i['VALIDADE_ATE']) . '</b></td></tr></table>'
            . '<div class="rod">Documento gerado pelo GreenSolutions em ' . (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('d/m/Y H:i') . ' · integração nº ' . (int)$i['CODINTEGRACAO'] . '</div></body></html>';
        $opt = new \Dompdf\Options(); $opt->set('isRemoteEnabled', false); $opt->set('defaultFont', 'DejaVu Sans');
        $pdf = new \Dompdf\Dompdf($opt); $pdf->loadHtml($html, 'UTF-8'); $pdf->setPaper('A4', 'portrait'); $pdf->render();
        $ged = new GedStorageService($pdo, $env, $sessao);
        $pasta = $ged->folder('fo_funcionarios', (int)$i['CODFUNCIONARIO'], true);
        if (!is_dir($pasta) && !@mkdir($pasta, 0770, true) && !is_dir($pasta)) throw new RuntimeException('Pasta GED indisponível.');
        $nome = 'certificado_integracao_' . date('Ymd', strtotime((string)$i['DECIDIDA_EM'])) . '_' . (int)$i['CODINTEGRACAO'] . '.pdf';
        if (file_put_contents($pasta . DIRECTORY_SEPARATOR . $nome, $pdf->output()) === false) throw new RuntimeException('Não foi possível gravar o certificado.');
        self::chamar($pdo, 'CALL sp_fo_integracao_certificado(?, ?)', [$codintegracao, $nome]);
        return $nome;
    }

    /** Caminho do certificado gravado (null se não existir). */
    public static function arquivoCertificado(PDO $pdo, array $env, array $sessao, array $i): ?string
    {
        require_once __DIR__ . '/GedStorageService.php';
        if ((string)($i['CERTIFICADO'] ?? '') === '') return null;
        return (new GedStorageService($pdo, $env, $sessao))->resolve('fo_funcionarios', (int)$i['CODFUNCIONARIO'], (string)$i['CERTIFICADO']);
    }

    /** Serviço de documentos da homologação: a última integração aprovada prevalece sobre a data trazida pelo sincronismo. */
    public static function reaplicar(PDO $pdo): int
    {
        if (!self::disponivel($pdo)) return 0;
        return (int)(self::chamar($pdo, 'CALL sp_fo_integracao_reaplicar()')[0]['REAPLICADAS'] ?? 0);
    }
}
