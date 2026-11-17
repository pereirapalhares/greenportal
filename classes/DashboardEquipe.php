<?php
declare(strict_types=1);

/**
 * Dados por pessoa usados pelos dashboards (pessoal e painel do gestor). Tudo lido por views com o $sel da página
 * (sp_crud_select); nenhuma consulta direta.
 */
final class DashboardEquipe
{
    /** Pessoas ativas: codusuario => linha de vw_perfil_atribuicoes_situacao */
    public static function pessoas(callable $sel): array
    {
        $out = [];
        foreach ($sel('vw_perfil_atribuicoes_situacao', [], 20000) ?? [] as $r) $out[(int)$r['codusuario']] = $r;
        return $out;
    }

    /** Equipe do gestor: subordinados diretos e indiretos (gestor do gestor), pelo campo gestor do cadastro de usuários */
    public static function membros(callable $sel, int $gestor, ?array $pessoas = null): array
    {
        $pessoas ??= self::pessoas($sel);
        $filhos = [];
        foreach ($pessoas as $id => $r) { $g = (int)($r['codgestor'] ?? 0); if ($g > 0 && $g !== $id) $filhos[$g][] = $id; }
        $out = []; $fila = [$gestor];
        while ($fila) {
            $g = array_shift($fila);
            foreach ($filhos[$g] ?? [] as $f) { if ($f === $gestor || isset($out[$f])) continue; $out[$f] = $pessoas[$f]; $fila[] = $f; }
        }
        uasort($out, static fn($a, $b) => strnatcasecmp(self::nome($a), self::nome($b)));
        return $out;
    }

    public static function nome(array $p): string
    {
        return trim((string)($p['usuario_nome'] ?? '')) ?: (string)($p['usuario_login'] ?? '');
    }

    /** Situação do perfil de atribuições: [código, rótulo pt] */
    public static function perfil(array $p): string
    {
        if ((string)($p['aplicar_questionario'] ?? '1') === '0') return 'NAO_APLICA';
        if (!empty($p['vigente_codperfilatrib']) && !empty($p['valido_ate']) && substr((string)$p['valido_ate'], 0, 10) < date('Y-m-d')) return 'VENCIDO';
        $st = strtoupper(trim((string)($p['ultimo_status'] ?? '')));
        if ($st === 'DEVOLVIDO') return 'DEVOLVIDO';
        if ($st === 'PENDENTE_APROVACAO') return 'PENDENTE_APROVACAO';
        if (!empty($p['vigente_codperfilatrib'])) return 'APROVADO';
        return 'SEM_ENVIO';
    }

    /**
     * Licenças (versão ativa, com a faixa de vw_dash_licencas) por pessoa: codusuario => [linhas de vw_dash_licencas].
     * Atribuição direta, por responsabilidade ou por grupo (vw_minhas_licencas_grid). $usuarios limita às pessoas pedidas.
     */
    public static function licencas(callable $sel, ?array $usuarios = null): ?array
    {
        $filtro = $usuarios !== null && count($usuarios) === 1 ? ['CODUSUARIO' => (int)array_key_first($usuarios)] : [];
        $minhas = $sel('vw_minhas_licencas_grid', $filtro, 50000);
        $todas = $sel('vw_dash_licencas', [], 20000);
        if ($minhas === null || $todas === null) return null;
        $porCod = [];
        foreach ($todas as $l) $porCod[(int)$l['CODLICENCA']] = $l;
        $out = []; $visto = [];
        foreach ($minhas as $m) {
            $u = (int)$m['CODUSUARIO']; $c = (int)$m['CODLICENCA'];
            if ($usuarios !== null && !isset($usuarios[$u])) continue;
            if (!isset($porCod[$c]) || isset($visto[$u][$c])) continue;
            $visto[$u][$c] = true; $out[$u][] = $porCod[$c];
        }
        return $out;
    }

    /** Medições por pessoa: codusuario => [COD, NOME, UNIDADE, ULTIMA, REACAO, DIAS, FAIXA (ATRASADA, PROXIMA, EM_DIA, SEM_DATA)] */
    public static function medicoes(callable $sel, ?array $usuarios = null): ?array
    {
        $filtro = $usuarios !== null && count($usuarios) === 1 ? ['CODUSUARIO' => (int)array_key_first($usuarios)] : [];
        $rows = $sel('vw_minhas_monitoramentos_grid', $filtro, 50000);
        if ($rows === null) return null;
        $nomesMed = []; foreach ($sel('mm_medicao', [], 5000) ?? [] as $m) $nomesMed[(string)($m['codmedicao'] ?? $m['CODMEDICAO'] ?? '')] = (string)($m['dscmedicao'] ?? $m['DSCMEDICAO'] ?? '');
        $nomesUni = []; foreach ($sel('vw_unidades_grid', [], 5000) ?? [] as $u) $nomesUni[(string)($u['CODUNIDADE'] ?? '')] = (string)($u['DSCUNIDADE'] ?? '');
        $hoje = date('Y-m-d'); $out = []; $visto = [];
        foreach ($rows as $r) {
            $u = (int)$r['CODUSUARIO']; $cod = (int)$r['CODMONITORAMENTO'];
            if ($usuarios !== null && !isset($usuarios[$u])) continue;
            if (isset($visto[$u][$cod])) continue; $visto[$u][$cod] = true;
            $reacao = self::data($r['DATA_REACAO'] ?? null);
            $dias = $reacao === null ? null : (int)round((strtotime($reacao) - strtotime($hoje)) / 86400);
            $faixa = $reacao === null ? 'SEM_DATA' : ($reacao < $hoje ? 'ATRASADA' : ($dias <= 30 ? 'PROXIMA' : 'EM_DIA'));
            $nome = trim(($nomesMed[(string)($r['CODMEDICAO'] ?? '')] ?? '') . ' ' . (string)($r['PARAMETROS'] ?? ''));
            $out[$u][] = ['COD' => $cod, 'NOME' => $nome !== '' ? $nome : ('#' . $cod), 'UNIDADE' => $nomesUni[(string)($r['CODUNIDADE'] ?? '')] ?? '',
                'ULTIMA' => self::data($r['DATA_MEDICAO'] ?? null), 'REACAO' => $reacao, 'DIAS' => $dias, 'FAIXA' => $faixa];
        }
        return $out;
    }

    /** Ids de responsáveis de um requisito (coluna RESPONSAVEL_IDS = ",12,34,") */
    public static function responsaveis(array $requisito): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string)($requisito['RESPONSAVEL_IDS'] ?? '')))));
    }

    /**
     * Lê uma view inteira com sp_crud_select, que devolve no máximo 1000 linhas por chamada; descarta repetidos de
     * requisito + unidade quando a view tem essas colunas. null se a view não existir.
     */
    public static function lerTudo(PDO $pdo, string $view, array $filtros = [], int $limite = 100000): ?array
    {
        $out = []; $chaves = [];
        try {
            for ($offset = 0; $offset < $limite; $offset += 1000) {
                $st = $pdo->prepare('CALL sp_crud_select(:t, :f, NULL, 1000, :o)');
                $st->execute(['t' => $view, 'f' => json_encode((object)$filtros, JSON_UNESCAPED_UNICODE), 'o' => $offset]);
                $lote = $st->fetchAll(PDO::FETCH_ASSOC); $st->closeCursor();
                foreach ($lote as $r) {
                    if (isset($r['CODLEGISLACAO'], $r['CODUNIDADE'])) { $k = $r['CODLEGISLACAO'] . ':' . $r['CODUNIDADE']; if (isset($chaves[$k])) continue; $chaves[$k] = true; }
                    $out[] = $r;
                }
                if (count($lote) < 1000) break;
            }
        } catch (Throwable) { return null; }
        return $out;
    }

    public static function data(mixed $v): ?string
    {
        $v = substr(trim((string)$v), 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && $v !== '0000-00-00' ? $v : null;
    }
}
