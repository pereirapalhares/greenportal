<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';

/*
 * Configuração do servidor de e-mail (SMTP) pela tela Administração › Configuração de e-mail (migração 360, 12/11/2026).
 * Decisões do usuário: uma configuração para todas as empresas (banco universal, tabela smtp_configuracao, linha 1), só o
 * ADMINISTRADOR altera; botão liga/desliga o envio — desligado, nada novo entra na fila e o que estiver na fila é cancelado
 * (ao religar não sai uma avalanche de e-mails antigos); e-mail de teste para um endereço digitado.
 * Com a linha gravada, ela vale no lugar das chaves SMTP_* / GREENPORTAL_URL do .env; sem linha, continua valendo o .env.
 * A senha fica cifrada (AES-256-GCM) com a chave SMTP_CHAVE do .env ou, sem ela, com storage/chaves/smtp.key (criada na
 * primeira gravação; a pasta é fechada para a web). A tela nunca mostra a senha gravada.
 */
final class SmtpConfiguracao
{
    public const TABELA = 'smtp_configuracao';
    private static ?array $linha = null;
    private static bool $lida = false;

    /** Linha gravada (ou null), lida uma vez por requisição. */
    public static function linha(?array $env = null, bool $recarregar = false): ?array
    {
        if (self::$lida && !$recarregar) return self::$linha;
        self::$lida = true; self::$linha = null;
        try {
            $env ??= GreenPortalDatabase::env();
            $u = self::universal($env);
            $s = $u->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::TABELA . "'");
            if ((int)$s->fetchColumn() === 0) return null;
            $s = $u->prepare('CALL sp_crud_select(?, ?, NULL, 1, 0)');
            $s->execute([self::TABELA, json_encode(['codconfiguracao' => ['value' => '1', 'exact' => true]])]);
            $r = $s->fetch(PDO::FETCH_ASSOC) ?: null; $s->closeCursor();
            self::$linha = $r ? array_change_key_case($r) : null;
        } catch (Throwable $e) {
            error_log('SmtpConfiguracao: ' . $e->getMessage());
        }
        return self::$linha;
    }

    /** O .env com as chaves SMTP_* (e GREENPORTAL_URL) da tela por cima, quando houver configuração gravada. */
    public static function aplicar(array $env): array
    {
        $r = self::linha($env);
        if (!$r) return $env;
        $map = ['host' => 'SMTP_HOST', 'porta' => 'SMTP_PORT', 'seguranca' => 'SMTP_SEGURANCA', 'usuario' => 'SMTP_USUARIO', 'remetente' => 'SMTP_REMETENTE',
            'remetente_nome' => 'SMTP_REMETENTE_NOME', 'timeout' => 'SMTP_TIMEOUT', 'ehlo' => 'SMTP_EHLO', 'url_portal' => 'GREENPORTAL_URL'];
        foreach ($map as $col => $chave) $env[$chave] = (string)($r[$col] ?? '');
        $env['SMTP_VERIFICAR_CERTIFICADO'] = (int)($r['verificar_certificado'] ?? 1) === 1 ? '1' : '0';
        $env['SMTP_SENHA'] = self::decifrar((string)($r['senha_cifrada'] ?? ''), $env);
        $env['SMTP_ATIVO'] = (int)($r['ativo'] ?? 0) === 1 ? '1' : '0';
        return $env;
    }

    /** Envio desligado na tela (só quando há configuração gravada). */
    public static function desligado(?array $env = null): bool
    {
        $r = self::linha($env);
        return $r !== null && (int)($r['ativo'] ?? 0) !== 1;
    }

    public static function universal(array $env): PDO
    {
        $p = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
        $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $p;
    }

    public static function cifrar(string $senha, array $env): string
    {
        if ($senha === '') return '';
        $iv = random_bytes(12); $tag = '';
        $c = openssl_encrypt($senha, 'aes-256-gcm', self::chave($env, true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($c === false) throw new RuntimeException('não foi possível cifrar a senha');
        return 'v1:' . base64_encode($iv . $tag . $c);
    }

    public static function decifrar(string $valor, array $env): string
    {
        if (!str_starts_with($valor, 'v1:')) return '';
        $b = base64_decode(substr($valor, 3), true);
        if ($b === false || strlen($b) < 29) return '';
        $chave = self::chave($env, false);
        if ($chave === '') return '';
        $p = openssl_decrypt(substr($b, 28), 'aes-256-gcm', $chave, OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
        return $p === false ? '' : $p;
    }

    private static function chave(array $env, bool $criar): string
    {
        $k = trim((string)($env['SMTP_CHAVE'] ?? ''));
        if ($k !== '') return hash('sha256', $k, true);
        $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'chaves';
        $arq = $dir . DIRECTORY_SEPARATOR . 'smtp.key';
        if (!is_file($arq)) {
            if (!$criar) return '';
            if (!is_dir($dir)) mkdir($dir, 0700, true);
            @file_put_contents($dir . DIRECTORY_SEPARATOR . '.htaccess', "Require all denied\n");
            if (file_put_contents($arq, base64_encode(random_bytes(32)), LOCK_EX) === false) throw new RuntimeException('não foi possível criar a chave de cifragem');
        }
        $b = base64_decode(trim((string)file_get_contents($arq)), true);
        return $b === false ? '' : hash('sha256', $b, true);
    }
}
