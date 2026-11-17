<?php
declare(strict_types=1);

/**
 * Envio de e-mail por SMTP (migração 333), sem biblioteca externa. Usado só pelo serviço de e-mail (scripts/email-worker.php).
 * Configuração no .env do greenportal (a senha fica só lá):
 *   SMTP_HOST, SMTP_PORT (587), SMTP_SEGURANCA (tls = STARTTLS | ssl = SMTPS 465 | nenhuma), SMTP_USUARIO, SMTP_SENHA,
 *   SMTP_REMETENTE (e-mail do remetente), SMTP_REMETENTE_NOME (padrão GreenSolutions), SMTP_TIMEOUT (segundos, padrão 30),
 *   SMTP_VERIFICAR_CERTIFICADO (1 padrão; 0 só para servidor interno com certificado próprio), SMTP_EHLO (nome do servidor; padrão o host).
 * Sem SMTP_HOST ou SMTP_REMETENTE o serviço não envia (os e-mails ficam na fila).
 * Migração 360: a configuração gravada na tela Administração › Configuração de e-mail (SmtpConfiguracao) vale no lugar do .env;
 * com o envio desligado lá, configurado() responde não.
 */
require_once __DIR__ . '/SmtpConfiguracao.php';

final class SmtpCliente
{
    /** @var resource|null */
    private $socket = null;
    private array $cfg;

    /** $aplicarTela = false: usa o $env como veio (e-mail de teste com os valores digitados na tela, antes de gravar). */
    public function __construct(array $env, bool $aplicarTela = true)
    {
        if ($aplicarTela) $env = SmtpConfiguracao::aplicar($env);
        $seg = strtolower(trim((string)($env['SMTP_SEGURANCA'] ?? 'tls')));
        $this->cfg = [
            'host' => trim((string)($env['SMTP_HOST'] ?? '')),
            'porta' => (int)($env['SMTP_PORT'] ?? ($seg === 'ssl' ? 465 : 587)),
            'seguranca' => in_array($seg, ['tls', 'ssl'], true) ? $seg : 'nenhuma',
            'usuario' => (string)($env['SMTP_USUARIO'] ?? ''),
            'senha' => (string)($env['SMTP_SENHA'] ?? ''),
            'remetente' => trim((string)($env['SMTP_REMETENTE'] ?? '')),
            'remetente_nome' => trim((string)($env['SMTP_REMETENTE_NOME'] ?? 'GreenSolutions')),
            'timeout' => max(5, (int)($env['SMTP_TIMEOUT'] ?? 30)),
            'verificar' => (string)($env['SMTP_VERIFICAR_CERTIFICADO'] ?? '1') !== '0',
            'ehlo' => trim((string)($env['SMTP_EHLO'] ?? '')) ?: (gethostname() ?: 'greenportal'),
        ];
    }

    public static function configurado(array $env): bool
    {
        $env = SmtpConfiguracao::aplicar($env);
        if (($env['SMTP_ATIVO'] ?? '1') === '0') return false;
        return self::parametrosOk($env);
    }

    /** Servidor e remetente preenchidos (sem olhar o liga/desliga; usado pelo e-mail de teste). */
    public static function parametrosOk(array $env): bool
    {
        return trim((string)($env['SMTP_HOST'] ?? '')) !== '' && filter_var(trim((string)($env['SMTP_REMETENTE'] ?? '')), FILTER_VALIDATE_EMAIL) !== false;
    }

    /** Destinatários válidos de um campo com um ou mais e-mails (separados por ; , ou espaço). */
    public static function destinatarios(string $campo): array
    {
        $r = [];
        foreach (preg_split('/[;,\s]+/', $campo) ?: [] as $e) { $e = trim($e); if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $r[strtolower($e)] = $e; }
        return array_values($r);
    }

    public function conectar(): void
    {
        if ($this->socket) return;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $this->cfg['verificar'], 'verify_peer_name' => $this->cfg['verificar'], 'allow_self_signed' => !$this->cfg['verificar'], 'SNI_enabled' => true, 'peer_name' => $this->cfg['host']]]);
        $alvo = ($this->cfg['seguranca'] === 'ssl' ? 'ssl://' : 'tcp://') . $this->cfg['host'] . ':' . $this->cfg['porta'];
        $s = @stream_socket_client($alvo, $errno, $errstr, $this->cfg['timeout'], STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) throw new RuntimeException("SMTP: não conectou em {$this->cfg['host']}:{$this->cfg['porta']} ($errstr)");
        stream_set_timeout($s, $this->cfg['timeout']);
        $this->socket = $s;
        $this->esperar([220]);
        $ext = $this->comando('EHLO ' . $this->cfg['ehlo'], [250]);
        if ($this->cfg['seguranca'] === 'tls') {
            if (!preg_match('/STARTTLS/i', $ext)) throw new RuntimeException('SMTP: o servidor não oferece STARTTLS (ajuste SMTP_SEGURANCA)');
            $this->comando('STARTTLS', [220]);
            $metodo = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $metodo |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $metodo |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            if (@stream_socket_enable_crypto($this->socket, true, $metodo) !== true) throw new RuntimeException('SMTP: falha ao iniciar TLS (certificado?)');
            $ext = $this->comando('EHLO ' . $this->cfg['ehlo'], [250]);
        }
        if ($this->cfg['usuario'] !== '') {
            if (preg_match('/AUTH[ =][^\r\n]*PLAIN/i', $ext) && !preg_match('/AUTH[ =][^\r\n]*LOGIN/i', $ext)) {
                $this->comando('AUTH PLAIN ' . base64_encode("\0" . $this->cfg['usuario'] . "\0" . $this->cfg['senha']), [235], true);
            } else {
                $this->comando('AUTH LOGIN', [334]);
                $this->comando(base64_encode($this->cfg['usuario']), [334], true);
                $this->comando(base64_encode($this->cfg['senha']), [235], true);
            }
        }
    }

    /** Envia uma mensagem HTML (com alternativa em texto). Lança exceção com a resposta do servidor em caso de recusa. */
    public function enviar(array $para, string $nomePara, string $assunto, string $html, string $texto): void
    {
        if (!$para) throw new InvalidArgumentException('sem destinatário válido');
        $this->conectar();
        try {
            $this->comando('MAIL FROM:<' . $this->cfg['remetente'] . '>', [250]);
            // vários endereços: basta um aceito (os recusados ficam de fora); nenhum aceito = recusa do servidor
            $aceitos = []; $recusa = null;
            foreach ($para as $e) {
                try { $this->comando('RCPT TO:<' . $e . '>', [250, 251]); $aceitos[] = $e; }
                catch (RuntimeException $x) { $recusa = $x; if (!str_starts_with($x->getMessage(), 'SMTP: 5')) throw $x; }
            }
            if (!$aceitos) throw $recusa ?? new RuntimeException('SMTP: nenhum destinatário aceito');
            $para = $aceitos;
            $this->comando('DATA', [354]);
            $limite = 'gp' . bin2hex(random_bytes(12));
            $cab = [
                'Date: ' . date(DATE_RFC2822),
                'From: ' . $this->endereco($this->cfg['remetente_nome'], $this->cfg['remetente']),
                'To: ' . implode(', ', array_map(fn($e) => count($para) === 1 && $nomePara !== '' ? $this->endereco($nomePara, $e) : '<' . $e . '>', $para)),
                'Subject: ' . $this->cabecalho($assunto),
                'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . (substr(strrchr($this->cfg['remetente'], '@') ?: '@greenportal', 1)) . '>',
                'MIME-Version: 1.0',
                'Auto-Submitted: auto-generated',
                'Content-Type: multipart/alternative; boundary="' . $limite . '"',
            ];
            $corpo = implode("\r\n", $cab) . "\r\n\r\n"
                . "--$limite\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($texto), 76, "\r\n")
                . "--$limite\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html), 76, "\r\n")
                . "--$limite--\r\n";
            // base64 não tem linhas começando com ponto; o terminador é a linha com "."
            fwrite($this->socket, $corpo . ".\r\n");
            $this->esperar([250]);
        } catch (Throwable $e) {
            // deixa a conexão pronta para a próxima mensagem
            try { $this->comando('RSET', [250]); } catch (Throwable) { $this->fechar(); }
            throw $e;
        }
    }

    public function fechar(): void
    {
        if (!$this->socket) return;
        try { fwrite($this->socket, "QUIT\r\n"); } catch (Throwable) {}
        @fclose($this->socket);
        $this->socket = null;
    }

    public function __destruct() { $this->fechar(); }

    private function endereco(string $nome, string $email): string
    {
        return $nome === '' ? '<' . $email . '>' : $this->cabecalho($nome) . ' <' . $email . '>';
    }

    private function cabecalho(string $v): string
    {
        $v = str_replace(["\r", "\n"], ' ', $v);
        return preg_match('/[^\x20-\x7E]/', $v) ? '=?UTF-8?B?' . base64_encode($v) . '?=' : $v;
    }

    private function comando(string $linha, array $codigos, bool $sigiloso = false): string
    {
        if (!$this->socket) throw new RuntimeException('SMTP: sem conexão');
        fwrite($this->socket, $linha . "\r\n");
        try { return $this->esperar($codigos); }
        catch (RuntimeException $e) { throw $sigiloso ? new RuntimeException('SMTP: autenticação recusada (' . preg_replace('/^SMTP: /', '', $e->getMessage()) . ')') : $e; }
    }

    private function esperar(array $codigos): string
    {
        $resposta = '';
        while (true) {
            $l = fgets($this->socket, 2048);
            if ($l === false) { $meta = stream_get_meta_data($this->socket); throw new RuntimeException('SMTP: conexão encerrada' . (!empty($meta['timed_out']) ? ' (tempo esgotado)' : '')); }
            $resposta .= $l;
            if (strlen($l) < 4 || $l[3] !== '-') break;
        }
        $cod = (int)substr($resposta, 0, 3);
        if (!in_array($cod, $codigos, true)) throw new RuntimeException('SMTP: ' . trim(mb_substr($resposta, 0, 300)));
        return $resposta;
    }
}
