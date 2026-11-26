<?php
declare(strict_types=1);

/**
 * Revisão G item 9 (migração 421, 26/11/2026): o usuário continua válido e atuante na empresa?
 * Regras (Segurança › Validade dos usuários, guardadas em portal_config):
 *   USUARIO_DIAS_SEM_ACESSO (padrão 90): usuário ativo sem entrar há mais dias que isso (ou que nunca entrou e foi criado há mais
 *     tempo) precisa de revisão;
 *   USUARIO_REVALIDAR_DIAS (padrão 0 = desligado): com valor, a confirmação ("continua na empresa") vale por esses dias; depois,
 *     todo usuário ativo volta para revisão (confirmação periódica, como a do AD da Crown).
 * A revisão é confirmar (usuario_validade, CONFIRMADO) ou inativar o usuário. O ADMINISTRADOR e o ADM DA EMPRESA recebem a pendência
 * "Usuários a revisar" (MinhasPendencias). Leitura por sp_crud_select.
 */
final class UsuarioValidade
{
    public const PADRAO = ['USUARIO_DIAS_SEM_ACESSO' => 90, 'USUARIO_REVALIDAR_DIAS' => 0];

    private static function sel(PDO $pdo, string $view, array $filtros = []): array
    {
        $out = [];
        for ($off = 0; $off < 50000; $off += 1000) {
            $s = $pdo->prepare('CALL sp_crud_select(:t, :f, NULL, 1000, :o)');
            $s->bindValue('t', $view); $s->bindValue('f', json_encode($filtros ?: new stdClass())); $s->bindValue('o', $off, PDO::PARAM_INT);
            $s->execute(); $l = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
            foreach ($l as $r) $out[] = array_change_key_case($r, CASE_UPPER);
            if (count($l) < 1000) break;
        }
        return $out;
    }

    public static function config(PDO $pdo): array
    {
        $c = self::PADRAO;
        try { foreach (self::sel($pdo, 'vw_portal_config') as $r) if (isset($c[(string)$r['CHAVE']]) && (int)$r['VALOR'] >= 0 && trim((string)$r['VALOR']) !== '') $c[(string)$r['CHAVE']] = (int)$r['VALOR']; } catch (Throwable) {}
        return $c;
    }

    /** Motivo da revisão, ou null se o usuário está em dia. */
    public static function motivo(array $u, array $cfg): ?array
    {
        if ((int)($u['ATIVO'] ?? 0) !== 1) return null;
        $hoje = new DateTimeImmutable('today');
        $d = static fn($v): ?DateTimeImmutable => !empty($v) ? new DateTimeImmutable(substr((string)$v, 0, 10)) : null;
        $conf = $d($u['CONFIRMADO_EM'] ?? null); $ult = $d($u['ULTIMO_ACESSO'] ?? null); $criado = $d($u['CRIADO_EM'] ?? null);
        $revalidar = (int)$cfg['USUARIO_REVALIDAR_DIAS'];
        $confValida = $conf !== null && ($revalidar <= 0 || $conf->diff($hoje)->days <= $revalidar);
        $referencia = $ult ?? $criado;
        $semAcesso = $referencia !== null ? $referencia->diff($hoje)->days : 99999;
        $confirmouDepois = $confValida && ($referencia === null || $conf >= $referencia);
        if ($semAcesso > (int)$cfg['USUARIO_DIAS_SEM_ACESSO'] && !$confirmouDepois) return ['tipo' => 'SEM_ACESSO', 'dias' => $ult === null ? null : $semAcesso];
        if ($revalidar > 0 && !$confValida && ($criado === null || $criado->diff($hoje)->days > $revalidar)) return ['tipo' => $conf === null ? 'NUNCA_CONFIRMADO' : 'CONFIRMACAO_VENCIDA', 'dias' => $conf === null ? null : $conf->diff($hoje)->days];
        return null;
    }

    /** Usuários ativos (sem os do fornecedor externo) com o motivo da revisão. */
    public static function lista(PDO $pdo, array $cfg): array
    {
        $out = [];
        foreach (self::sel($pdo, 'vw_usuarios_validade') as $u) {
            if ((int)($u['CODFORNECEDOR'] ?? 0) > 0) continue;
            $u['MOTIVO'] = self::motivo($u, $cfg);
            $out[] = $u;
        }
        return $out;
    }

    public static function aRevisar(PDO $pdo): int
    {
        try { $cfg = self::config($pdo); return count(array_filter(self::lista($pdo, $cfg), static fn($u) => $u['MOTIVO'] !== null)); }
        catch (Throwable) { return 0; }
    }
}
