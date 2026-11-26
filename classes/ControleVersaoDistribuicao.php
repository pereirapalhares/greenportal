<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';

/**
 * Revisão G item 88 (migração 421, 26/11/2026): distribuição da nova versão de um documento do Controle.
 * Quando um arquivo é enviado em Arquivos como NOVA VERSÃO (api/gestao_arquivos.php, tipo controles):
 *   - registra a versão em ctrl_documento_versao (versão anterior, nova, arquivo, quem enviou, quantos foram avisados);
 *   - os arquivos que já estavam na pasta passam a obsoletos (ctrl_documento_obsoleto) e aparecem marcados na lista de arquivos;
 *   - com "Notificar alteração?" = Sim, avisa (Home › Alertas) quem responde pelo documento: Responsável, atribuições e regras
 *     de Responsáveis e avisos (vw_resp_acesso, área CONTROLES), menos quem enviou;
 *   - com "Necessita treinamento?" = Sim, o aviso pede o novo treinamento na versão e a versão fica marcada para treinamento.
 * Leitura por sp_crud_select; gravação por sp_crud_insert e sp_alerta_criar. Sem as tabelas (migração 421) não faz nada.
 */
final class ControleVersaoDistribuicao
{
    private static function sel(PDO $pdo, string $view, array $filtros, int $max = 1000): array
    {
        $s = $pdo->prepare('CALL sp_crud_select(:t, :f, NULL, :n, 0)');
        $s->bindValue('t', $view); $s->bindValue('f', json_encode($filtros ?: new stdClass(), JSON_UNESCAPED_UNICODE)); $s->bindValue('n', $max, PDO::PARAM_INT);
        $s->execute(); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
        return array_map(static fn($l) => array_change_key_case($l, CASE_UPPER), $r);
    }

    private static function ins(PDO $pdo, string $tabela, array $dados): void
    {
        $s = $pdo->prepare('CALL sp_crud_insert(:t, :d)');
        $s->execute(['t' => $tabela, 'd' => json_encode($dados, JSON_UNESCAPED_UNICODE)]); $s->closeCursor();
    }

    public static function disponivel(PDO $pdo): bool
    {
        try { self::sel($pdo, 'vw_ctrl_documento_versao', [], 1); return true; } catch (Throwable) { return false; }
    }

    /** [arquivo => versão em que ficou obsoleto] do documento. */
    public static function obsoletos(PDO $pdo, int $coddoc): array
    {
        try {
            $out = [];
            foreach (self::sel($pdo, 'vw_ctrl_documento_obsoleto', ['CODDOCUMENTO' => ['value' => (string)$coddoc, 'exact' => true]]) as $r) $out[(string)$r['ARQUIVO']] = (string)$r['VERSAO'];
            return $out;
        } catch (Throwable) { return []; }
    }

    /**
     * @param string[] $arquivosAntes nomes dos arquivos que já estavam na pasta antes do envio
     * @return array{avisados:int, treinamento:bool, obsoletos:int}
     */
    public static function distribuir(PDO $pdo, int $coddoc, string $versaoAnterior, string $versaoNova, string $arquivoNovo, array $arquivosAntes, int $uid, string $link): array
    {
        $res = ['avisados' => 0, 'treinamento' => false, 'obsoletos' => 0];
        if (!self::disponivel($pdo)) return $res;
        $doc = self::sel($pdo, 'documentos', ['CODDOCUMENTO' => ['value' => (string)$coddoc, 'exact' => true]], 1)[0] ?? [];
        $sim = static fn($v): bool => in_array(mb_strtoupper(trim((string)$v)), ['SIM', 'S', '1', 'YES'], true);
        $notificar = $sim($doc['NOTIFICAR_ALTERACAO'] ?? '');
        $res['treinamento'] = $sim($doc['NECESSITA_TREINAMENTO'] ?? '');
        $jaObsoletos = self::obsoletos($pdo, $coddoc);
        foreach (array_unique(array_map('basename', $arquivosAntes)) as $a) {
            if ($a === '' || $a === basename($arquivoNovo) || isset($jaObsoletos[$a])) continue;
            self::ins($pdo, 'ctrl_documento_obsoleto', ['CODDOCUMENTO' => $coddoc, 'ARQUIVO' => mb_substr($a, 0, 255), 'VERSAO' => mb_substr($versaoAnterior, 0, 30), 'CODUSUARIO' => $uid ?: null]);
            $res['obsoletos']++;
        }
        $usuarios = [];
        if ($notificar) {
            try {
                foreach (self::sel($pdo, 'vw_resp_acesso', ['AREA' => ['value' => 'CONTROLES', 'exact' => true], 'REGISTRO_ID' => ['value' => (string)$coddoc, 'exact' => true]]) as $r) {
                    $u = (int)($r['CODUSUARIO_ACESSO'] ?? 0);
                    if ($u > 0 && $u !== $uid) $usuarios[$u] = true;
                }
            } catch (Throwable) {}
            $codigo = trim((string)($doc['CODIGO'] ?? '')) ?: ('#' . $coddoc);
            $titulo = trim((string)($doc['TITULO'] ?? ''));
            $msg = 'O documento ' . $codigo . ($titulo !== '' ? ' — ' . $titulo : '') . ' passou da versão ' . ($versaoAnterior !== '' ? $versaoAnterior : '00') . ' para a versão ' . $versaoNova
                . '. Use o arquivo novo; o anterior ficou obsoleto.' . ($res['treinamento'] ? ' Este documento exige treinamento: programe o novo treinamento da equipe nesta versão.' : '');
            foreach (array_keys($usuarios) as $u) {
                try {
                    $s = $pdo->prepare('CALL sp_alerta_criar(?, ?, ?, ?, ?)');
                    $s->execute([$u, mb_substr('Nova versão ' . $versaoNova . ' do documento ' . $codigo, 0, 200), mb_substr($msg, 0, 1000), $link, 'NAO']); $s->closeCursor();
                    $res['avisados']++;
                } catch (Throwable $e) { error_log('ControleVersaoDistribuicao aviso: ' . $e->getMessage()); }
            }
        }
        self::ins($pdo, 'ctrl_documento_versao', ['CODDOCUMENTO' => $coddoc, 'VERSAO' => mb_substr($versaoNova, 0, 30), 'VERSAO_ANTERIOR' => mb_substr($versaoAnterior, 0, 30),
            'ARQUIVO' => mb_substr(basename($arquivoNovo), 0, 255), 'CODUSUARIO' => $uid ?: null, 'AVISADOS' => $res['avisados'], 'NECESSITA_TREINAMENTO' => $res['treinamento'] ? 'S' : 'N']);
        return $res;
    }
}
