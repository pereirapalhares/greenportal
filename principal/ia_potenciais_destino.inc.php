<?php
declare(strict_types=1);

/*
 * Migração 355 (Fase 4 da sugestão do Ricardo): aprovar potencial de LICENÇA ou de DOCUMENTO DE CONTROLE escolhendo o destino.
 * Incluído por ia_potenciais_categoria.php depois da sessão, da permissão e da conexão ($pdo, $permission, $uid, $id, $tipo, $query).
 *   GET  ?acao=destinos&codsolicitacao=N[&todas=1][&busca=..][&codtipo=..] → JSON com as licenças ou documentos para escolher;
 *   POST action=aprovar_destino (codpotencial, modo NOVO|EXISTENTE, ids[], codtipodocumento, outros[]) → sp_ia_potencial_aprovar_destino.
 * Sem a migração 355 no banco, $iaDestinoAtivo fica falso e a aba continua como antes (aprovar cria registro novo).
 */

$iaDestinoAtivo = false;
$iaDestinoUnidade = 0;
$iaDestinoTipos = [];
$iaDestinoErro = '';
if (in_array($tipo, ['LICENCA', 'CONTROLE'], true)) {
    try {
        $r = $query($pdo, "SELECT COUNT(*) AS N FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_ia_potencial_aprovar_destino'");
        $iaDestinoAtivo = (int)($r[0]['N'] ?? 0) === 1;
    } catch (Throwable) { $iaDestinoAtivo = false; }
}
if ($iaDestinoAtivo) {
    // Unidade do requisito (contexto da solicitação)
    try {
        $ctx = $query($pdo, 'SELECT contexto_json FROM ml_potenciais_solicitacoes WHERE codsolicitacao = :id LIMIT 1', ['id' => $id]);
        $ctxJson = json_decode((string)($ctx[0]['contexto_json'] ?? '{}'), true);
        $iaDestinoUnidade = (int)(is_array($ctxJson) ? ($ctxJson['CODUNIDADE'] ?? 0) : 0);
    } catch (Throwable) { $iaDestinoUnidade = 0; }
    if ($tipo === 'LICENCA') {
        try { $iaDestinoTipos = $query($pdo, 'SELECT CODTIPODOCUMENTO, DSCTIPODOCUMENTO FROM li_tipos_documentos WHERE ATIVO = 1 ORDER BY ORDEM, DSCTIPODOCUMENTO'); }
        catch (Throwable) { $iaDestinoTipos = []; }
    }

    // Lista para escolher (JSON)
    if (($_GET['acao'] ?? '') === 'destinos') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $busca = trim((string)($_GET['busca'] ?? ''));
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $busca) . '%';
            if ($tipo === 'LICENCA') {
                $sql = 'SELECT CODLICENCA AS id, DSCLICENCA AS titulo, CODUNIDADE, DSCUNIDADE, CODTIPODOCUMENTO, DSCTIPODOCUMENTO, NUMERO_LICENCA, DATA_VALIDADE, QTD_REQUISITOS,
                               (SELECT COUNT(*) FROM legislacaoxlicencas x WHERE x.CODLICENCA = v.CODLICENCA AND x.CODLEGISLACAO = :leg AND x.codunidade = :uni2) AS JA_VINCULADA
                          FROM vw_ia_destino_licencas v WHERE 1 = 1';
                $p = ['leg' => 0, 'uni2' => $iaDestinoUnidade];
                if (empty($_GET['todas'])) { $sql .= ' AND CODUNIDADE = :uni'; $p['uni'] = $iaDestinoUnidade; }
                if ((int)($_GET['codtipo'] ?? 0) > 0) { $sql .= ' AND CODTIPODOCUMENTO = :tp'; $p['tp'] = (int)$_GET['codtipo']; }
                if ($busca !== '') { $sql .= ' AND (DSCLICENCA LIKE :b OR NUMERO_LICENCA LIKE :b2 OR DSCTIPODOCUMENTO LIKE :b3)'; $p += ['b' => $like, 'b2' => $like, 'b3' => $like]; }
                $sql .= ' ORDER BY DSCUNIDADE, DSCTIPODOCUMENTO, DSCLICENCA LIMIT 300';
            } else {
                $sql = 'SELECT CODDOCUMENTO AS id, TITULO AS titulo, CODIGO, QTD_REQUISITOS,
                               (SELECT COUNT(*) FROM legislacaoxdocumentos x WHERE x.CODDOCUMENTO = v.CODDOCUMENTO AND x.CODLEGISLACAO = :leg AND x.CODUNIDADE = :uni2) AS JA_VINCULADA
                          FROM vw_ia_destino_documentos v WHERE 1 = 1';
                $p = ['leg' => 0, 'uni2' => $iaDestinoUnidade];
                if ($busca !== '') { $sql .= ' AND (TITULO LIKE :b OR CODIGO LIKE :b2)'; $p += ['b' => $like, 'b2' => $like]; }
                $sql .= ' ORDER BY QTD_REQUISITOS DESC, TITULO LIMIT 300';
            }
            // Requisito do contexto (para marcar o que já está vinculado)
            $ctxLeg = $query($pdo, 'SELECT contexto_json FROM ml_potenciais_solicitacoes WHERE codsolicitacao = :id LIMIT 1', ['id' => $id]);
            $ctxLegJson = json_decode((string)($ctxLeg[0]['contexto_json'] ?? '{}'), true);
            $p['leg'] = (int)(is_array($ctxLegJson) ? ($ctxLegJson['CODLEGISLACAO'] ?? 0) : 0);
            echo json_encode(['ok' => true, 'itens' => $query($pdo, $sql, $p)], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // Aprovação com destino
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'aprovar_destino') {
        require_once dirname(__DIR__) . '/classes/AuditService.php';
        try {
            if (!$permission['pode_editar']) throw new RuntimeException('Seu perfil não possui permissão para validar potenciais.');
            $modo = strtoupper((string)($_POST['modo'] ?? ''));
            if (!in_array($modo, ['NOVO', 'EXISTENTE'], true)) throw new RuntimeException('Escolha criar um novo registro ou vincular a um existente.');
            $sugerido = static function (int $p) use ($query, $pdo, $id, $tipo): bool {
                return (bool)$query($pdo, "SELECT codpotencial FROM vw_ml_potenciais_grid WHERE codpotencial = :p AND codsolicitacao = :r AND tipo_potencial = :t AND status = 'SUGERIDO' LIMIT 1", ['p' => $p, 'r' => $id, 't' => $tipo]);
            };
            $principal = (int)($_POST['codpotencial'] ?? 0);
            if (!$sugerido($principal)) throw new RuntimeException('Potencial inválido para esta aba ou já validado.');
            $outros = [];
            if ($tipo === 'CONTROLE') foreach ((array)($_POST['outros'] ?? []) as $o) { $o = (int)$o; if ($o > 0 && $o !== $principal && $sugerido($o)) $outros[] = $o; }
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), static fn($v) => $v > 0)));
            if ($modo === 'EXISTENTE') {
                if (!$ids) throw new RuntimeException($tipo === 'LICENCA' ? 'Marque pelo menos uma licença.' : 'Escolha o documento de controle.');
                if ($tipo === 'CONTROLE') $ids = [$ids[0]];
                $lista = $tipo === 'LICENCA' ? 'vw_ia_destino_licencas' : 'vw_ia_destino_documentos';
                $chave = $tipo === 'LICENCA' ? 'CODLICENCA' : 'CODDOCUMENTO';
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $st = $pdo->prepare("SELECT COUNT(*) FROM $lista WHERE $chave IN ($ph)"); $st->execute($ids); $n = (int)$st->fetchColumn(); $st->closeCursor();
                if ($n !== count($ids)) throw new RuntimeException('Registro escolhido não encontrado.');
            }
            $codTipo = $tipo === 'LICENCA' ? max(0, (int)($_POST['codtipodocumento'] ?? 0)) : 0;
            $r = $query($pdo, 'CALL sp_ia_potencial_aprovar_destino(:p, :u, :m, :ids, :tp)', ['p' => $principal, 'u' => $uid, 'm' => $modo, 'ids' => implode(',', $ids), 'tp' => $codTipo]);
            $destino = (int)($r[0]['codregistro_destino'] ?? 0);
            $qtd = (int)($r[0]['qtd_destinos'] ?? 0);
            $alvo = $modo === 'NOVO' ? [$destino] : $ids;
            $aprovados = [$principal];
            $falhas = [];
            foreach ($outros as $o) {
                try { $query($pdo, 'CALL sp_ia_potencial_aprovar_destino(:p, :u, :m, :ids, 0)', ['p' => $o, 'u' => $uid, 'm' => 'EXISTENTE', 'ids' => (string)$destino]); $aprovados[] = $o; }
                catch (Throwable $e) { $falhas[] = '#' . $o . ': ' . $e->getMessage(); }
            }
            AuditService::record($pdo, 'ia_potenciais_resultado', 'APROVAR', $falhas ? 'PARCIAL' : 'SUCESSO', 'ml_potenciais', (string)$principal,
                ['tipo' => $tipo, 'modo' => $modo, 'destinos' => $alvo, 'tipo_documental' => $codTipo ?: null, 'potenciais_aprovados' => $aprovados, 'falhas' => $falhas]);
            if ($tipo === 'LICENCA') $message = $modo === 'NOVO' ? 'Potencial aprovado: licença nova criada e vinculada ao requisito.' : "Potencial aprovado: requisito vinculado a $qtd licença(s) existente(s).";
            else $message = ($modo === 'NOVO' ? 'Potencial aprovado: documento de controle novo criado e vinculado ao requisito.' : 'Potencial aprovado: vinculado ao documento de controle existente.')
                . (count($aprovados) > 1 ? ' ' . count($aprovados) . ' potenciais aprovados para o mesmo documento.' : '');
            if ($falhas) $error = 'Não foi possível aprovar: ' . implode('; ', $falhas);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (preg_match('/1644\s+(.*)$/s', $msg, $m)) $msg = trim($m[1]);
            $error = $msg;
            try { AuditService::record($pdo, 'ia_potenciais_resultado', 'APROVAR', 'ERRO', 'ml_potenciais', (string)(int)($_POST['codpotencial'] ?? 0), ['tipo' => $tipo, 'erro' => $msg]); } catch (Throwable) {}
        }
    }
}
