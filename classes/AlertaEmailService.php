<?php
declare(strict_types=1);

require_once __DIR__ . '/SmtpCliente.php';
require_once __DIR__ . '/EmailFilaService.php';
require_once __DIR__ . '/GreenPortalDatabase.php';

/**
 * Alertas do portal por e-mail (migração 343). Roda no serviço de e-mail do Agendador (windows-service.php --service=email),
 * antes do envio da fila, para cada empresa:
 *   - alertas novos (sp_alertas_email_novos, depois da marca): IMEDIATO vira e-mail (os da mesma pessoa no ciclo juntos),
 *     RESUMO entra no resumo do dia, NAO / sem e-mail / "Exportação iniciada" / mais de 1 dia só avançam a marca;
 *   - às 7h (uma vez por dia) o resumo dos alertas ainda não lidos de quem escolheu RESUMO;
 *   - cada e-mail traz o botão "Abrir no portal": /principal/abrir_alerta.php?e=EMPRESA&a=ALERTA (login, se preciso, e a tela
 *     do alerta abre como aba; o alerta fica lido). Endereço do portal no .env: GREENPORTAL_URL (ex.: http://servidor/greenportal).
 * Sem SMTP configurado (.env SMTP_HOST e SMTP_REMETENTE) a marca só avança: nada do período sem SMTP é enviado depois.
 * Gravação só por procedures (sp_email_enfileirar, sp_alertas_email_*).
 */
final class AlertaEmailService
{
    public const HORA_RESUMO = 7;
    private const LOTE = 500;

    public function __construct(private readonly PDO $pdo, private readonly array $env, private readonly string $banco) {}

    /** @return int e-mails postos na fila */
    public function gerar(): int
    {
        if (!$this->instalado()) return 0;
        $smtp = SmtpCliente::configurado($this->env);
        $n = 0;
        $alertas = $this->call('CALL sp_alertas_email_novos(?)', [self::LOTE]);
        if ($alertas) {
            $porUsuario = [];
            foreach ($alertas as $a) {
                $acao = (string)$a['ACAO'];
                if (!$smtp || $acao === 'IGNORAR' || $acao === 'NAO' || filter_var(trim((string)$a['EMAIL']), FILTER_VALIDATE_EMAIL) === false) continue;
                if ($acao === 'RESUMO') { $this->executar('CALL sp_alerta_email_resumo_incluir(?, ?)', [(int)$a['CODALERTA'], (int)$a['CODUSUARIO']]); continue; }
                $porUsuario[(int)$a['CODUSUARIO']][] = $a;
            }
            foreach ($porUsuario as $lista) { $this->enfileirar($lista, false); $n++; }
            $this->executar('CALL sp_alertas_email_marca(?)', [(int)end($alertas)['CODALERTA']]);
        }
        if ($smtp && (int)date('G') >= self::HORA_RESUMO && $this->controle('RESUMO_DIA') !== date('Y-m-d')) {
            $porUsuario = [];
            foreach ($this->call('CALL sp_alertas_email_resumo_pendentes()') as $a) if (filter_var(trim((string)$a['EMAIL']), FILTER_VALIDATE_EMAIL) !== false) $porUsuario[(int)$a['CODUSUARIO']][] = $a;
            foreach ($porUsuario as $lista) { $this->enfileirar($lista, true); $n++; }
            $this->executar('CALL sp_alertas_email_resumo_concluir(?)', [date('Y-m-d')]);
        }
        return $n;
    }

    /** Um e-mail para a pessoa com um ou mais alertas. */
    private function enfileirar(array $lista, bool $resumo): void
    {
        $p = $lista[0];
        $qtd = count($lista);
        $assunto = $resumo ? 'Resumo do dia: ' . $qtd . ($qtd === 1 ? ' alerta não lido' : ' alertas não lidos') . ' no portal'
            : ($qtd === 1 ? (string)$p['TITULO'] : $qtd . ' novos alertas no portal');
        $nome = trim((string)$p['NOME']);
        $t = 'Olá' . ($nome !== '' ? ', ' . strtok($nome, ' ') : '') . ".\n\n";
        $t .= $resumo ? "Estes alertas do portal ainda não foram lidos:\n\n" : ($qtd === 1 ? '' : "Você tem novos alertas no portal:\n\n");
        foreach (array_slice($lista, 0, 30) as $a) {
            $quando = $a['CRIADO_EM'] ? date('d/m/Y H:i', strtotime((string)$a['CRIADO_EM']) ?: time()) : '';
            $t .= ($qtd > 1 ? '• ' : '') . trim((string)$a['TITULO']) . ($quando !== '' && $qtd > 1 ? ' (' . $quando . ')' : '') . "\n";
            $msg = trim(EmailFilaService::textoPlano((string)$a['MENSAGEM']));
            if ($msg !== '') $t .= mb_substr($msg, 0, 1500) . "\n";
            $url = $this->link((int)$a['CODALERTA']);
            if ($url !== '') $t .= '{{botao:Abrir no portal|' . $url . "}}\n";
            $t .= "\n";
        }
        if ($qtd > 30) $t .= '… e mais ' . ($qtd - 30) . " alerta(s): veja todos em Home › Alertas.\n\n";
        if ($this->base() === '') $t .= "Entre no portal para ver os detalhes (Home › Alertas).\n\n";
        $t .= $resumo ? 'Você recebe um resumo por dia porque escolheu essa opção' : 'Você recebe os alertas por e-mail na hora';
        $t .= ' em Minha conta › Alertas por e-mail (lá dá para mudar para ' . ($resumo ? '"na hora" ou "não receber"' : '"resumo diário" ou "não receber"') . ').';
        $this->executar('CALL sp_email_enfileirar(?, ?, ?, ?, ?, ?)', [trim((string)$p['EMAIL']), $nome, mb_substr($assunto, 0, 255), $t,
            $resumo ? 'ALERTA_RESUMO' : 'ALERTA', 'ALERTA:' . (int)end($lista)['CODALERTA']]);
    }

    /** Link "Abrir no portal" do alerta (vazio sem GREENPORTAL_URL no .env). */
    public function link(int $codalerta): string
    {
        $base = $this->base();
        $empresa = $this->codigoEmpresa();
        if ($base === '' || $empresa === '') return '';
        return $base . '/principal/abrir_alerta.php?' . http_build_query(['e' => $empresa, 'a' => $codalerta]);
    }

    private function base(): string
    {
        $u = rtrim(trim((string)(SmtpConfiguracao::aplicar($this->env)['GREENPORTAL_URL'] ?? '')), '/');
        return preg_match('#^https?://#i', $u) ? $u : '';
    }

    private function codigoEmpresa(): string
    {
        static $cache = [];
        if (isset($cache[$this->banco])) return $cache[$this->banco];
        try {
            $u = GreenPortalDatabase::connect($this->env, (string)($this->env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
            $s = $u->prepare('CALL sp_crud_select(?, ?, NULL, 5, 0)');
            $s->execute(['empresa', json_encode(['banco_dados' => $this->banco])]);
            $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
            foreach ($r as $x) if (strcasecmp((string)$x['banco_dados'], $this->banco) === 0) return $cache[$this->banco] = (string)$x['codigo'];
        } catch (Throwable) {}
        return $cache[$this->banco] = '';
    }

    private function instalado(): bool
    {
        try { $this->pdo->query('SELECT 1 FROM vw_alertas_email_controle LIMIT 1')->fetchAll(); return true; } catch (Throwable) { return false; }
    }

    private function controle(string $chave): string
    {
        $s = $this->pdo->prepare('SELECT VALOR FROM vw_alertas_email_controle WHERE CHAVE = ?');
        $s->execute([$chave]); $v = (string)($s->fetchColumn() ?: ''); $s->closeCursor();
        return $v;
    }

    private function call(string $sql, array $p = []): array
    {
        $s = $this->pdo->prepare($sql); $s->execute($p);
        $r = [];
        do { try { $x = $s->fetchAll(PDO::FETCH_ASSOC); if ($x) $r = $x; } catch (Throwable) {} } while ($s->nextRowset());
        $s->closeCursor();
        return $r;
    }

    private function executar(string $sql, array $p): void
    {
        $s = $this->pdo->prepare($sql); $s->execute($p);
        do { try { $s->fetchAll(); } catch (Throwable) {} } while ($s->nextRowset());
        $s->closeCursor();
    }
}
