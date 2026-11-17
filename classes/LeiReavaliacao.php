<?php
declare(strict_types=1);

require_once __DIR__ . '/BuscaSemantica.php';
require_once __DIR__ . '/TaskService.php';
require_once __DIR__ . '/IaTaximetro.php';

/*
 * Reavaliação quando uma lei muda (migração 346; LEG-006 da apresentação Atlas).
 * O serviço da Pesquisa de documentos (scripts/busca-worker.php) lê as alterações novas da base de legislação
 * (vw_lei_alteracoes: legislacaoxalteracao — alteração, revogação... — com a lei e se ela está nos requisitos da empresa) e,
 * para cada uma:
 *   1. requisitos da própria lei na empresa (uma linha por unidade), quando a lei é requisito;
 *   2. itens parecidos pelo sentido (requisitos de outras leis, licenças, controles, treinamentos, infraestrutura) com o texto
 *      "lei + ementa + alteração" (BuscaSemantica, semelhança mínima 0,45, até 8);
 *   3. uma tarefa "Revisar" (green_tasks, tipo LEI_REAVALIACAO, prazo de 15 dias) para cada responsável dos requisitos da lei
 *      (legislacaoxsituacao) e alerta aos responsáveis dos itens parecidos; lei fora dos requisitos com item muito parecido
 *      (0,55): tarefa ao ADM DA EMPRESA; sem nada parecido e fora dos requisitos: só registra (SEM_IMPACTO).
 * A marca (lei_reav_controle) começa na última alteração existente na migração: o histórico não gera tarefas.
 * Tela: Módulo Legal › Reavaliação por mudança de lei (principal/modulolegal_reavaliacao_leis.php).
 */
final class LeiReavaliacao
{
    public const MENU = 'MODULOLEGAL_REAVALIACAO_LEIS';
    public const TELA = '/principal/modulolegal_reavaliacao_leis.php';
    private const TIPOS_ITENS = ['requisitos', 'licencas', 'controles', 'treinamentos', 'infraestrutura'];
    private const ROTULO = ['requisitos' => 'Requisito legal', 'licencas' => 'Licença', 'controles' => 'Controle', 'treinamentos' => 'Treinamento', 'infraestrutura' => 'Infraestrutura'];

    public function __construct(private readonly PDO $pdo, private readonly array $env) {}

    public static function disponivel(PDO $pdo): bool
    {
        try { $s = $pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME IN ('sp_lei_reav_novas', 'sp_lei_reav_registrar')"); $n = (int)$s->fetchColumn(); $s->closeCursor(); return $n === 2; }
        catch (Throwable) { return false; }
    }

    /** @return array{alteracoes:int, tarefas:int, alertas:int, aviso?:string} */
    public function processar(int $limite = 30): array
    {
        $r = ['alteracoes' => 0, 'tarefas' => 0, 'alertas' => 0];
        $marca = (int)($this->primeiro('SELECT VALOR FROM vw_lei_reav_controle WHERE CHAVE = ?', ['ULTIMA_ALTERACAO'])['VALOR'] ?? 0);
        $novas = $this->call('CALL sp_lei_reav_novas(?, ?)', [$marca, $limite]);
        if (!$novas) return $r;
        $sem = new BuscaSemantica($this->pdo, $this->env);
        $vetores = [];
        if ($sem->configurada()) {
            $s = IaTaximetro::situacao($this->pdo);
            if (!$s || (string)$s['SITUACAO'] !== 'BLOQUEADO') {
                IaTaximetro::contexto($this->pdo, ['tipo' => 'LEI_REAVALIACAO', 'entidade' => 'legislacaoxalteracao', 'origem' => 'FILA', 'modulo' => 'Módulo Legal']);
                try { $vetores = $sem->vetores(array_map([$this, 'texto'], $novas)); }
                catch (Throwable $e) { $r['aviso'] = 'sem a comparação pelo sentido nesta execução: ' . $e->getMessage(); $vetores = []; }
                finally { IaTaximetro::limpar(); }
            } else $r['aviso'] = 'limite mensal de IA atingido: só os requisitos da própria lei';
        } else $r['aviso'] = 'sem chave da IA: só os requisitos da própria lei';
        $tarefas = new TaskService($this->pdo);
        foreach ($novas as $i => $a) {
          // Cada alteração numa transação: se a tarefa ou o alerta falhar, nada fica gravado e ela é tentada na próxima execução
          $this->pdo->beginTransaction();
          try {
            $cod = (int)$a['CODALTERACAO']; $lei = (string)$a['CODLEGISLACAO'];
            $req = array_map(static fn($x) => (int)$x['codbusca'], $this->call('CALL sp_lei_reav_requisitos(?)', [(int)$lei]));
            $par = isset($vetores[$i]) ? $sem->parecidos($vetores[$i], 8, 0.45, self::TIPOS_ITENS, $req) : [];
            $dados = $this->itens(array_merge($req, array_map(static fn($p) => $p['id'], $par)));
            $sc = []; foreach ($par as $p) $sc[$p['id']] = $p['s'];
            $itens = [];
            foreach ($req as $id) if (isset($dados[$id])) $itens[] = $this->item($dados[$id], null, 'REQUISITO');
            foreach ($par as $p) if (isset($dados[$p['id']])) $itens[] = $this->item($dados[$p['id']], $p['s'], 'SENTIDO');
            $ehReq = (int)($a['EH_REQUISITO'] ?? 0) === 1 || $req;
            $maior = $par ? max(array_column($par, 's')) : 0.0;
            $nomeLei = trim((string)$a['LEI']) ?: 'Lei nº ' . $lei;
            // Quem revisa: responsáveis dos requisitos da lei; fora dos requisitos e muito parecido: ADM DA EMPRESA
            $resp = $ehReq ? array_map(static fn($x) => (int)$x['CODUSUARIO'], $this->call('SELECT DISTINCT CODUSUARIO FROM vw_lei_reav_responsaveis WHERE CODLEGISLACAO = ?', [(int)$lei])) : [];
            if (($ehReq && !$resp) || (!$ehReq && $maior >= 0.55)) $resp = $this->adms();
            $situacao = (!$ehReq && $maior < 0.55) ? 'SEM_IMPACTO' : 'ABERTA';
            $motivo = $ehReq ? 'A lei está nos requisitos legais da empresa' . ($par ? ' e há ' . count($par) . ' item(ns) parecido(s).' : '.')
                : ($situacao === 'SEM_IMPACTO' ? ($par ? 'Fora dos requisitos e sem item muito parecido (maior semelhança ' . number_format($maior * 100, 0) . '%).' : 'Fora dos requisitos e sem item parecido.')
                    : 'Fora dos requisitos, mas parecida com ' . count($par) . ' item(ns) da empresa (até ' . number_format($maior * 100, 0) . '%).');
            $primeiraTarefa = 0;
            $codreav = (int)($this->call('CALL sp_lei_reav_registrar(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$cod, (int)$lei, mb_substr($nomeLei, 0, 500), mb_substr((string)$a['TIPO'], 0, 200),
                (string)$a['DESCRICAO'], $a['DATA_ALTERACAO'] ?: null, $ehReq ? 1 : 0, json_encode($itens, JSON_UNESCAPED_UNICODE), 0, 0, $situacao, mb_substr($motivo, 0, 1000)])[0]['CODREAV'] ?? 0);
            if ($situacao === 'ABERTA' && $codreav > 0) {
                $titulo = mb_substr('Revisar: ' . $nomeLei . ' — ' . (trim((string)$a['TIPO']) ?: 'alteração'), 0, 180);
                $desc = $this->descricao($a, $nomeLei, $itens, $motivo);
                foreach (array_unique(array_filter($resp)) as $u) {
                    $t = $tarefas->enqueue(['chave_idempotencia' => 'LEI_REAV:' . $cod . ':' . $u, 'tipo' => 'LEI_REAVALIACAO', 'origem_transacao' => 'modulolegal_reavaliacao_leis.php',
                        'origem_entidade' => 'lei_reavaliacao', 'origem_id' => (string)$codreav, 'titulo' => $titulo, 'descricao' => $desc, 'prioridade' => $ehReq ? 7 : 4,
                        'codusuario_destino' => $u, 'data_limite' => date('Y-m-d 23:59:59', strtotime('+15 days')), 'criado_por' => null]);
                    $primeiraTarefa = $primeiraTarefa ?: (int)$t['codtarefa'];
                    $r['tarefas']++;
                    $r['alertas'] += $this->alerta($u, $titulo, 'A ' . $nomeLei . ' teve ' . (trim((string)$a['TIPO']) ?: 'alteração') . '. ' . $motivo . ' Revise os requisitos e itens indicados (Módulo Legal › Reavaliação por mudança de lei).', self::TELA . '?codreav=' . $codreav);
                }
                // Responsáveis dos itens parecidos (licenças etc.): só o aviso
                foreach ($par as $p) {
                    $u = (int)($dados[$p['id']]['codusuario'] ?? 0);
                    if ($u > 0 && !in_array($u, $resp, true)) $r['alertas'] += $this->alerta($u, mb_substr('Lei alterada pode afetar: ' . ($dados[$p['id']]['nome'] ?? ''), 0, 180), 'A ' . $nomeLei . ' teve ' . (trim((string)$a['TIPO']) ?: 'alteração') . ' e parece relacionada (' . number_format($p['s'] * 100, 0) . '%) com "' . ($dados[$p['id']]['nome'] ?? '') . '", de que você é responsável.', self::TELA . '?codreav=' . $codreav . '&item=' . $p['id']);
                }
                if ($primeiraTarefa) $this->call('CALL sp_lei_reav_tarefa(?, ?, ?)', [$codreav, $primeiraTarefa, (int)($resp[0] ?? 0)]);
            }
            $this->call('CALL sp_lei_reav_marca(?)', [(string)max($marca, $cod)]);
            $this->pdo->commit();
            $marca = max($marca, $cod);
            $r['alteracoes']++;
          } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
          }
        }
        return $r;
    }

    /** Texto vetorizado da alteração. */
    public function texto(array $a): string
    {
        return mb_substr(trim(implode('. ', array_filter([(string)$a['LEI'], (string)($a['EMENTA'] ?? ''), 'Alteração: ' . trim((string)$a['TIPO'] . ' ' . (string)$a['DESCRICAO'])]))), 0, 6000);
    }

    private function descricao(array $a, string $lei, array $itens, string $motivo): string
    {
        $t = $lei . ' — ' . (trim((string)$a['TIPO']) ?: 'alteração') . ($a['DATA_ALTERACAO'] ? ' em ' . date('d/m/Y', strtotime((string)$a['DATA_ALTERACAO']) ?: time()) : '') . ".\n";
        if (trim((string)$a['DESCRICAO']) !== '') $t .= trim((string)$a['DESCRICAO']) . "\n";
        $t .= "\n" . $motivo . "\n";
        if ($itens) {
            $t .= "\nRevisar:\n";
            foreach ($itens as $i) $t .= '• ' . (self::ROTULO[$i['tipo']] ?? $i['tipo']) . ': ' . $i['nome'] . ($i['unidade'] ? ' (' . $i['unidade'] . ')' : '') . ($i['m'] === 'SENTIDO' ? ' — parecido ' . number_format((float)$i['s'] * 100, 0) . '%' : ' — requisito da lei') . "\n";
        }
        return $t . "\nVeja em Módulo Legal › Reavaliação por mudança de lei.";
    }

    private function item(array $d, ?float $s, string $m): array
    {
        return ['id' => (int)$d['codbusca'], 'tipo' => (string)$d['tipo'], 'nome' => trim((string)$d['nome']) ?: ('#' . $d['chave']), 'unidade' => (string)($d['unidade'] ?? ''),
            'pagina' => (string)$d['pagina'], 'filtros' => (string)($d['filtros'] ?? ''), 's' => $s, 'm' => $m];
    }

    /** Dados dos itens do índice. @return array<int, array> */
    private function itens(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $o = [];
        foreach ($this->call('CALL sp_lei_reav_itens_busca(?)', [json_encode($ids)]) as $l) $o[(int)$l['codbusca']] = $l;
        return $o;
    }

    private function adms(): array
    {
        try {
            $l = $this->pdo->query('SELECT CODUSUARIO, ADMINISTRADOR FROM vw_resp_auto_adm')->fetchAll(PDO::FETCH_ASSOC);
            $a = array_map(static fn($r) => (int)$r['CODUSUARIO'], array_filter($l, static fn($r) => (int)$r['ADMINISTRADOR'] === 0));
            return $a ?: array_map(static fn($r) => (int)$r['CODUSUARIO'], $l);
        } catch (Throwable) { return []; }
    }

    private function alerta(int $u, string $titulo, string $msg, string $link): int
    {
        try { $r = $this->call('CALL sp_resp_auto_alerta(?, ?, ?, ?)', [$u, mb_substr($titulo, 0, 180), $msg, mb_substr($link, 0, 500)]); return (int)($r[0]['CODALERTA'] ?? 0) > 0 ? 1 : 0; }
        catch (Throwable) { return 0; }
    }

    private function primeiro(string $sql, array $p): ?array
    {
        try { $s = $this->pdo->prepare($sql); $s->execute($p); $r = $s->fetch(PDO::FETCH_ASSOC) ?: null; $s->closeCursor(); return $r; } catch (Throwable) { return null; }
    }

    private function call(string $sql, array $p = []): array
    {
        $s = $this->pdo->prepare($sql); $s->execute($p);
        $r = [];
        do { try { $x = $s->fetchAll(PDO::FETCH_ASSOC); if ($x) $r = $x; } catch (Throwable) {} } while ($s->nextRowset());
        $s->closeCursor();
        return $r;
    }
}
