<?php
declare(strict_types=1);

require_once __DIR__ . '/SmtpCliente.php';

/**
 * Fila de e-mails de cada empresa (tabela email_fila, migração 333), enviada pelo serviço do Agendador do Windows
 * (scripts/email-worker.php → windows-service.php --service=email). Acesso só pelas procedures sp_email_reivindicar e
 * sp_email_resultado. Cada e-mail sai com o nome da empresa no cabeçalho. Sem SMTP configurado no .env não envia nada
 * (os e-mails ficam PENDENTE até a configuração).
 */
final class EmailFilaService
{
    public function __construct(private readonly PDO $pdo, private readonly array $env, private readonly string $banco) {}

    public function processar(): int
    {
        // Migração 360: envio desligado na tela de configuração → os e-mails da fila são cancelados (não acumulam)
        if (SmtpConfiguracao::desligado($this->env)) {
            if ((int)$this->valor("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_email_cancelar'") > 0) {
                $n = (int)($this->call("CALL sp_email_cancelar('Envio de e-mails desligado em Administração › Configuração de e-mail')")[0]['CANCELADOS'] ?? 0);
                if ($n > 0) $this->log($n . ' e-mail(s) cancelado(s): envio desligado.');
            }
            return 0;
        }
        if (!SmtpCliente::configurado($this->env)) {
            $this->log('SMTP não configurado no .env (SMTP_HOST e SMTP_REMETENTE): e-mails ficam na fila.', true);
            return 0;
        }
        if ((int)$this->valor("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_email_reivindicar'") === 0) return 0;
        $lote = $this->call('CALL sp_email_reivindicar()');
        if (!$lote) return 0;
        $empresa = $this->nomeEmpresa();
        $smtp = new SmtpCliente($this->env);
        $enviados = 0;
        foreach ($lote as $i => $m) {
            $ok = 0; $erro = null;
            try {
                $para = SmtpCliente::destinatarios((string)$m['destinatario']);
                if (!$para) throw new InvalidArgumentException('destinatário sem e-mail válido: ' . mb_substr((string)$m['destinatario'], 0, 120));
                $texto = self::textoPlano((string)$m['corpo']);
                $smtp->enviar($para, (string)($m['nome_destinatario'] ?? ''), ($empresa !== '' ? "[$empresa] " : '') . (string)$m['assunto'], self::html($empresa, (string)$m['assunto'], $texto), self::semMarcas($texto) . "\n\n-- \nMensagem automática do GreenSolutions" . ($empresa !== '' ? " — $empresa" : '') . '. Não responda.');
                $ok = 1; $enviados++;
            } catch (Throwable $e) {
                $erro = mb_substr($e->getMessage(), 0, 1000);
                // endereço inválido ou recusa definitiva do servidor (5xx): não adianta tentar de novo
                if ($e instanceof InvalidArgumentException || preg_match('/^SMTP: 5\d\d/', $erro)) {
                    $this->executar('CALL sp_email_recusado(?, ?)', [(int)$m['codemail'], $erro]);
                    continue;
                }
                // falha de conexão/autenticação: as próximas também falhariam; marca esta e devolve o resto à fila no próximo ciclo
                if (str_contains($erro, 'não conectou') || str_contains($erro, 'autenticação') || str_contains($erro, 'TLS')) {
                    $this->resultado((int)$m['codemail'], 0, $erro);
                    foreach (array_slice($lote, $i + 1) as $resto) $this->executar('CALL sp_email_devolver(?)', [(int)$resto['codemail']]);
                    $this->log('Envio interrompido: ' . $erro);
                    $smtp->fechar();
                    return $enviados;
                }
            }
            $this->resultado((int)$m['codemail'], $ok, $erro);
        }
        $smtp->fechar();
        return $enviados;
    }

    /** Texto do e-mail: a fila guarda texto; tira marcação que venha dos campos da NC (descrições com HTML do legado). */
    public static function textoPlano(string $corpo): string
    {
        $t = preg_replace('#<\s*br\s*/?\s*>|</\s*p\s*>|</\s*div\s*>|</\s*li\s*>#i', "\n", $corpo) ?? $corpo;
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $t)) ?? $t);
    }

    /** Migração 343: marca {{botao:Texto|https://...}} vira botão no HTML e "Texto: endereço" no texto simples. */
    public const BOTAO = '/\{\{botao:([^|}]{1,80})\|(https?:\/\/[^}\s]{1,1000})\}\}/u';

    public static function semMarcas(string $texto): string
    {
        return (string)preg_replace(self::BOTAO, '$1: $2', $texto);
    }

    public static function html(string $empresa, string $assunto, string $texto): string
    {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $paragrafos = '';
        foreach (preg_split("/\n{2,}/", $texto) ?: [] as $p) {
            $partes = preg_split(self::BOTAO, $p, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$p];
            $h = '';
            for ($i = 0; $i < count($partes); $i += 3) {
                $h .= nl2br($e(trim($partes[$i], "\n") === '' && isset($partes[$i + 1]) ? '' : $partes[$i]));
                if (isset($partes[$i + 1], $partes[$i + 2])) $h .= '<a href="' . $e($partes[$i + 2]) . '" style="display:inline-block;margin:6px 0 2px;padding:8px 16px;background:#1f6b4f;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600">' . $e($partes[$i + 1]) . '</a>';
            }
            $paragrafos .= '<p style="margin:0 0 12px">' . $h . '</p>';
        }
        return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>' . $e($assunto) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f3f7f5;font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#1f3a30">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f7f5;padding:24px 0"><tr><td align="center">'
            . '<table role="presentation" width="640" cellspacing="0" cellpadding="0" style="max-width:640px;width:100%;background:#ffffff;border:1px solid #dce9e2;border-radius:8px">'
            . '<tr><td style="padding:16px 24px;background:#1f6b4f;color:#ffffff;border-radius:8px 8px 0 0;font-size:16px;font-weight:600">' . $e($empresa !== '' ? $empresa : 'GreenSolutions') . '</td></tr>'
            . '<tr><td style="padding:20px 24px">' . $paragrafos . '</td></tr>'
            . '<tr><td style="padding:12px 24px;border-top:1px solid #dce9e2;color:#71877d;font-size:12px">Mensagem automática do GreenSolutions. Não responda a este e-mail.</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    private function nomeEmpresa(): string
    {
        try {
            $u = GreenPortalDatabase::connect($this->env, (string)($this->env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
            $s = $u->prepare('SELECT nome FROM empresa WHERE banco_dados = ? ORDER BY codempresa LIMIT 1');
            $s->execute([$this->banco]); $n = (string)($s->fetchColumn() ?: ''); $s->closeCursor();
            return trim($n);
        } catch (Throwable) { return ''; }
    }

    private function resultado(int $codigo, int $ok, ?string $erro): void
    {
        $this->executar('CALL sp_email_resultado(?, ?, ?)', [$codigo, $ok, $erro]);
    }

    private function executar(string $sql, array $p): void
    {
        $s = $this->pdo->prepare($sql); $s->execute($p); $s->closeCursor();
    }

    private function call(string $sql): array
    {
        $s = $this->pdo->prepare($sql); $s->execute(); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
        return $r;
    }

    private function valor(string $sql): mixed
    {
        $s = $this->pdo->query($sql); $v = $s->fetchColumn(); $s->closeCursor(); return $v;
    }

    /** Log em storage/logs/email-worker.log; $umaVezPorHora evita repetir o mesmo aviso a cada ciclo. */
    private function log(string $msg, bool $umaVezPorHora = false): void
    {
        $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $arq = $dir . DIRECTORY_SEPARATOR . 'email-worker.log';
        if ($umaVezPorHora && is_file($arq) && filemtime($arq) > time() - 3600 && str_contains((string)@file_get_contents($arq, false, null, max(0, (int)filesize($arq) - 400)), $msg)) return;
        @file_put_contents($arq, '[' . date('Y-m-d H:i:s') . "] {$this->banco}: $msg" . PHP_EOL, FILE_APPEND);
    }
}
