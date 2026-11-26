<?php
declare(strict_types=1);

require_once __DIR__ . '/PermissaoTransacao.php';
require_once __DIR__ . '/PendenciasService.php';
require_once __DIR__ . '/PendenciasPainel.php';

/*
 * Barra lateral de pendências (principal/main_pendencias.php) e as telas Próximos prazos (painel_prazos.php) e
 * Aprovações (painel_aprovacoes.php). Leitura só por views (migração 290: vw_painel_prazos, vw_painel_aprovacoes) e pelo
 * serviço do painel de pendências, para os números baterem com as telas que os ícones abrem.
 */
final class PainelLateralService
{
    /** Tipo => [rótulo pt/en/es, ícone, transações que dão acesso ao link (qualquer uma)] */
    public const TIPOS_PRAZO = [
        'REQUISITO' => [['pt' => 'Requisito legal', 'en' => 'Legal requirement', 'es' => 'Requisito legal'], 'bi-journal-check', ['REQUISITOS_LEGAIS']],
        'LICENCA' => [['pt' => 'Licença', 'en' => 'License', 'es' => 'Licencia'], 'bi-patch-check', ['LICENCAS', 'MINHAS_LICENCAS']],
        'MONITORAMENTO' => [['pt' => 'Medição e monitoramento', 'en' => 'Measurement and monitoring', 'es' => 'Medición y monitoreo'], 'bi-speedometer2', ['MM_PLANO_MEDICAO_MONITORAMENTO']],
        'PLANO_NC' => [['pt' => 'Plano de ação (NC)', 'en' => 'Action plan (NC)', 'es' => 'Plan de acción (NC)'], 'bi-clipboard-check', ['NAO_CONFORMIDADES_MINHAS_OCORRENCIAS', 'NAO_CONFORMIDADES_LISTA_OCORRENCIAS']],
        'TAREFA' => [['pt' => 'Tarefa', 'en' => 'Task', 'es' => 'Tarea'], 'bi-check2-circle', []],
        'RESPONSABILIDADE' => [['pt' => 'Responsabilidade', 'en' => 'Responsibility', 'es' => 'Responsabilidad'], 'bi-person-check', ['RESPONSABILIDADE_SUBSTITUICAO']],
        'AVALIACAO_FORNECEDOR' => [['pt' => 'Avaliação de fornecedor', 'en' => 'Supplier evaluation', 'es' => 'Evaluación de proveedor'], 'bi-truck', ['HOMOLOGACAO_FORNECEDOR_FORNECEDOR']],
        'AGENDA' => [['pt' => 'Agenda', 'en' => 'Calendar event', 'es' => 'Agenda'], 'bi-calendar-event', []],
    ];
    /**
     * Evento da agenda: origem (agenda_eventos.origem_entidade) => tipo cujo link abre o registro. Mesma regra da Agenda do
     * portal (principal/eventos.php); origem desconhecida ou sem registro abre a própria Agenda (AGENDA_GERAL).
     */
    private const AGENDA_ORIGEM = [
        'li_licencas' => 'LICENCA', 'mm_monitoramento' => 'MONITORAMENTO', 'green_tasks' => 'TAREFA', 'ev_eventos' => 'EVENTO',
        'nc_planodeacao' => 'PLANO_NC', 'nc_indicacao_ocorrencia' => 'PLANO_NC', 'fo_fornecedores' => 'AVALIACAO_FORNECEDOR',
        'ml_potenciais' => 'IA', 'ml_treinamentos' => 'TREINAMENTO', 'ml_infraestrutura' => 'INFRAESTRUTURA', 'documentos' => 'CONTROLE',
    ];
    /** Transações exigidas pelos links que não são tipos de prazo (qualquer uma; vazio = a própria tela confere) */
    private const LINK_TRANSACOES = ['EVENTO' => ['MM_PLANO_MEDICAO_MONITORAMENTO'], 'IA' => ['MODULOLEGAL_POTENCIAIS']];
    public const TIPOS_APROVACAO = [
        'IA' => [['pt' => 'Sugestões da IA', 'en' => 'AI suggestions', 'es' => 'Sugerencias de la IA'], 'bi-stars'],
        'NC_GESTOR' => [['pt' => 'Não conformidade', 'en' => 'Nonconformity', 'es' => 'No conformidad'], 'bi-exclamation-octagon'],
        'PERFIL_ATRIBUICOES' => [['pt' => 'Perfil de atribuições', 'en' => 'Assignment profile', 'es' => 'Perfil de atribuciones'], 'bi-person-lines-fill'],
        'SUBSTITUICAO' => [['pt' => 'Substituição de responsabilidade', 'en' => 'Responsibility substitution', 'es' => 'Sustitución de responsabilidad'], 'bi-arrow-left-right'],
        'DELEGACAO' => [['pt' => 'Delegação de responsabilidade', 'en' => 'Responsibility delegation', 'es' => 'Delegación de responsabilidad'], 'bi-share'],
        'FORNECEDOR_DOC' => [['pt' => 'Documentos de fornecedor', 'en' => 'Supplier documents', 'es' => 'Documentos de proveedor'], 'bi-file-earmark-check'],
        'WORKFLOW' => [['pt' => 'Fluxo de aprovação', 'en' => 'Approval flow', 'es' => 'Flujo de aprobación'], 'bi-diagram-3'],
    ];

    public function __construct(private readonly PDO $pdo, private readonly int $usuario) {}

    private function linhas(string $view, array $filtros = [], int $paginas = 1): array
    {
        $todas = [];
        try {
            for ($pg = 0; $pg < $paginas; $pg++) {   // sp_crud_select devolve no máximo 1000 linhas por chamada
                $s = $this->pdo->prepare('CALL sp_crud_select(:t, :f, NULL, 1000, :o)');
                $s->bindValue('t', $view); $s->bindValue('f', $filtros ? json_encode($filtros, JSON_UNESCAPED_UNICODE) : null);
                $s->bindValue('o', $pg * 1000, PDO::PARAM_INT); $s->execute();
                $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
                while ($s->nextRowset()) {}
                array_push($todas, ...$r);
                if (count($r) < 1000) break;
            }
        } catch (Throwable) {}   // sem a migração 290: nada a mostrar
        return $todas;
    }

    private static function pode(array $transacoes, string $acao = 'pode_visualizar'): bool
    {
        if (!$transacoes) return true;
        foreach ($transacoes as $t) if (!empty(PermissaoTransacao::obter($t)[$acao])) return true;
        return false;
    }

    private static function filtro(array $f): string { return rawurlencode(json_encode($f, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
    private static function exato(string $v): array { return ['value' => $v, 'exact' => true]; }

    /** Link (relativo a /principal) para abrir o item em popup; '' quando não há tela ou o perfil não pode abrir */
    public static function link(string $tipo, string $codigo, string $chave2): string
    {
        return match ($tipo) {
            'REQUISITO' => 'modulolegal_requisitoslegais.php?filtros=' . self::filtro(['CODLEGISLACAO' => self::exato($codigo), 'CODUNIDADE' => self::exato($chave2)]),
            'LICENCA' => 'manutencaolegal_licencas.php?filtros=' . self::filtro(['CODLICENCA' => self::exato($codigo)]),
            'MONITORAMENTO' => 'medicaoemonitoramento_mm_monitoramento.php?filtros=' . self::filtro(['CODMONITORAMENTO' => self::exato($codigo)]),
            'PLANO_NC' => 'naoconformidades_minhasocorrencias.php?filtros=' . self::filtro(['CODOCORRENCIA' => self::exato($codigo)]),
            // Tarefa vinda de um potencial da IA (CHAVE2 = solicitação) abre a análise; as demais, o painel de pendências
            'TAREFA' => $chave2 !== '' ? 'ia_potenciais_resultado.php?codsolicitacao=' . rawurlencode($chave2) : 'painel_pendencias.php?codtarefa=' . rawurlencode($codigo),
            'EVENTO' => 'eventos.php?codmonitoramento=' . rawurlencode($codigo),
            'TREINAMENTO' => 'modulolegal_treinamentos.php?minhas_pendencias=1&filtros=' . self::filtro(['CODTREINAMENTO' => (int)$codigo]),
            'INFRAESTRUTURA' => 'modulolegal_InfraEstrutura.php?minhas_pendencias=1&filtros=' . self::filtro(['CODINFRAESTRUTURA' => (int)$codigo]),
            'CONTROLE' => 'modulolegal_controle.php?minhas_pendencias=1&filtros=' . self::filtro(['CODDOCUMENTO' => (int)$codigo]),
            'AGENDA_GERAL' => 'eventos.php?modo=agenda',
            'RESPONSABILIDADE', 'SUBSTITUICAO', 'DELEGACAO' => 'responsabilidade_substituicao.php',
            'AVALIACAO_FORNECEDOR' => 'homologacaofornecedor_fornecedor.php?filtros=' . self::filtro(['CODFORNECEDOR' => self::exato($codigo)]),
            'IA' => 'ia_potenciais_resultado.php?codsolicitacao=' . rawurlencode($codigo),
            'NC_GESTOR' => ($chave2 === '3' ? 'naoconformidades_aprovacao.php' : 'naoconformidades_comogestor.php') . '?codigo=' . rawurlencode($codigo),
            'PERFIL_ATRIBUICOES' => 'perfil_atribuicoes_gestao.php?id=' . rawurlencode($codigo),
            'FORNECEDOR_DOC' => 'homologacaofornecedor_fornecedorxcriterios.php?filtros=' . self::filtro(['CODFORNECEDOR' => $codigo]),
            default => '',
        };
    }

    /** Prazos do usuário (atrasados, hoje e próximos 30 dias), ordenados pelo prazo */
    public function prazos(): array
    {
        $itens = [];
        foreach ($this->comTransferencias('vw_painel_prazos', []) as $r) {
            $tipo = (string)$r['TIPO'];
            $acesso = self::pode($tipo === 'TAREFA' && (string)$r['CHAVE2'] !== '' ? self::LINK_TRANSACOES['IA'] : (self::TIPOS_PRAZO[$tipo][2] ?? []));
            $itens[] = $r + ['LINK' => $acesso ? self::link($tipo, (string)$r['CODIGO'], (string)$r['CHAVE2']) : ''];
        }
        usort($itens, static fn($a, $b) => strcmp((string)$a['PRAZO'], (string)$b['PRAZO']));
        return self::semOcultas($this->pdo, $itens, $this->usuario);
    }

    /**
     * Migração 380 (GER-8): linhas do usuário numa view da barra (vw_painel_prazos / vw_painel_calendario) com as
     * transferências de responsabilidade em vigor: sem o que ele transferiu e com o que foi transferido para ele (pelo
     * módulo da tela de cada linha, a mesma regra de TransferenciaResponsabilidade::moduloDaUrl).
     */
    private function comTransferencias(string $view, array $filtros, int $paginas = 10): array
    {
        $proprias = $this->linhas($view, ['CODUSUARIO' => self::exato((string)$this->usuario)] + $filtros, $paginas);
        if (!is_file(__DIR__ . '/TransferenciaResponsabilidade.php')) return $proprias;
        require_once __DIR__ . '/TransferenciaResponsabilidade.php';
        try {
            if (!TransferenciaResponsabilidade::ativas($this->pdo)) return $proprias;
            $modulo = static fn(array $r): string => TransferenciaResponsabilidade::moduloDaUrl(self::link((string)$r['TIPO'], (string)$r['CODIGO'], (string)($r['CHAVE2'] ?? '')) ?: (string)$r['TIPO']);
            $out = array_values(array_filter($proprias, fn($r) => TransferenciaResponsabilidade::destinoDe($this->pdo, $this->usuario, $modulo($r)) === null));
            foreach (TransferenciaResponsabilidade::recebidas($this->pdo, $this->usuario) as $t) {
                $mods = TransferenciaResponsabilidade::modulosDe($t); $todos = strtoupper((string)$t['MODULOS']) === 'TODOS';
                foreach ($this->linhas($view, ['CODUSUARIO' => self::exato((string)$t['CODUSUARIO_ORIGEM'])] + $filtros, $paginas) as $r) {
                    if (!$todos && !in_array($modulo($r), $mods, true)) continue;
                    $r['TITULO'] = (string)$r['TITULO'] . ' (de ' . $t['ORIGEM'] . ')';
                    $out[] = $r;
                }
            }
            return $out;
        } catch (Throwable $e) { error_log('PainelLateral transferencias: ' . $e->getMessage()); return $proprias; }
    }

    /** Migração 365 (GER-18): tira os prazos ocultados ou desligados na Gestão de pendências. */
    private static function semOcultas(PDO $pdo, array $linhas, int $dono = 0): array
    {
        if (!is_file(__DIR__ . '/PendenciasGestao.php')) return $linhas;
        require_once __DIR__ . '/PendenciasGestao.php';
        try { return PendenciasGestao::filtrarBarra($pdo, $linhas, $dono); } catch (Throwable $e) { error_log('PainelLateral gestao: ' . $e->getMessage()); return $linhas; }
    }

    /**
     * Calendário: pendências do usuário com prazo no ano informado (vw_painel_calendario, janela de 3 anos para cada lado).
     * Cada item: data, situação, tipo (rótulo e ícone), título, detalhe e o link que abre a transação em popup.
     */
    public function calendario(int $ano, string $idioma = 'pt', bool $equipe = false): array
    {
        $itens = [];
        $pessoas = $equipe ? $this->equipe() : [$this->usuario => ''];
        $porAno = ['ANO' => self::exato((string)$ano)];
        if (count($pessoas) > 15 || self::admEmpresa() && $equipe) {
            $linhas = array_filter($this->linhas('vw_painel_calendario', $porAno, 50), static fn($r) => isset($pessoas[(int)$r['CODUSUARIO']]));
        } elseif (!$equipe) {
            $linhas = $this->comTransferencias('vw_painel_calendario', $porAno, 20);   // migração 380: com as transferências
        } else {
            $linhas = [];
            foreach (array_keys($pessoas) as $u) array_push($linhas, ...$this->linhas('vw_painel_calendario', ['CODUSUARIO' => self::exato((string)$u)] + $porAno, 20));
        }
        $linhas = self::semOcultas($this->pdo, array_values($linhas));
        foreach ($linhas as $r) {
            $tipo = (string)$r['TIPO']; $codigo = (string)$r['CODIGO']; $chave2 = (string)$r['CHAVE2'];
            $alvo = $tipo === 'AGENDA' ? (self::AGENDA_ORIGEM[strtolower($chave2)] ?? 'AGENDA_GERAL') : $tipo;
            if ($alvo !== 'AGENDA_GERAL' && $alvo !== 'TAREFA' && !ctype_digit($codigo)) $alvo = 'AGENDA_GERAL';   // origem sem registro numérico
            $exige = $alvo === 'TAREFA' && $chave2 !== '' ? self::LINK_TRANSACOES['IA'] : (self::TIPOS_PRAZO[$alvo][2] ?? self::LINK_TRANSACOES[$alvo] ?? []);
            $link = self::pode($exige) ? self::link($alvo, $codigo, $tipo === 'AGENDA' ? '' : $chave2) : '';
            $tp = self::TIPOS_PRAZO[$tipo] ?? [[$idioma => $tipo], 'bi-dot'];
            $itens[] = ['data' => substr((string)$r['PRAZO'], 0, 10), 'situacao' => (string)$r['SITUACAO'], 'tipo' => $tipo,
                'rotulo' => $tp[0][$idioma] ?? $tp[0]['pt'] ?? $tipo, 'icone' => $tp[1], 'titulo' => (string)$r['TITULO'],
                'detalhe' => (string)$r['DETALHE'], 'link' => $link, 'usuario' => (int)$r['CODUSUARIO'], 'responsavel' => $pessoas[(int)$r['CODUSUARIO']] ?? ''];
        }
        usort($itens, static fn($a, $b) => [$a['data'], $a['tipo'], $a['titulo']] <=> [$b['data'], $b['tipo'], $b['titulo']]);
        return $itens;
    }

    /** Aprovações do usuário: as dele e as que dependem de permissão (CODUSUARIO = 0) quando o perfil pode decidir */
    public function aprovacoes(): array
    {
        $itens = [];
        foreach (array_merge($this->linhas('vw_painel_aprovacoes', ['CODUSUARIO' => self::exato((string)$this->usuario)]),
                             $this->linhas('vw_painel_aprovacoes', ['CODUSUARIO' => self::exato('0')])) as $r) {
            $tx = trim((string)($r['TRANSACAO'] ?? '')); $acao = trim((string)($r['ACAO'] ?? '')) ?: 'pode_visualizar';
            if ($tx !== '' && !self::pode([$tx], $acao)) continue;
            $itens[] = $r + ['LINK' => self::link((string)$r['TIPO'], (string)$r['CODIGO'], (string)$r['CHAVE2'])];
        }
        $itens = $this->aprovadoresDocumentos($itens);
        usort($itens, static fn($a, $b) => strcmp((string)($b['DESDE'] ?? ''), (string)($a['DESDE'] ?? '')));
        return $itens;
    }

    /**
     * Migração 420 (Revisão G item 152): aprovação dos documentos de fornecedor por pessoas ou grupos. Com regras em
     * vw_hom_documento_aprovador, cada documento "Pendente com a empresa" vai para quem aprova aquele documento exigido (ou
     * todos os documentos); documento sem regra continua com o responsável pelo fornecedor. O ADMINISTRADOR vê todos.
     */
    private function aprovadoresDocumentos(array $itens): array
    {
        $regras = $this->linhas('vw_hom_documento_aprovador', ['ATIVO' => self::exato('S')]);
        if (!$regras || !self::pode(['HOMOLOGACAO_FORNECEDOR_FORNECEDOR'])) return $itens;
        $admin = (($_SESSION['usuario']['codigo_perfil'] ?? '') === 'ADMINISTRADOR');
        $meusGrupos = [];
        foreach ($this->linhas('vw_responsabilidade_grupoxusuario', ['CODUSUARIO' => self::exato((string)$this->usuario)]) as $g) $meusGrupos[(int)($g['CODGRUPO'] ?? $g['codgrupo'] ?? 0)] = true;
        $col = static function (array $r, array $nomes) { foreach ($r as $k => $v) foreach ($nomes as $n) if (strcasecmp((string)$k, $n) === 0) return $v; return null; };
        $porForn = []; $semRegra = [];
        foreach ($this->linhas('fo_fornecedorxcriterios', ['SITUACAO' => self::exato('Pendente com a empresa')], 5) as $d) {
            $crit = (int)$col($d, ['CODCRITERIOFORNECEDOR', 'CODCRITERIO']);
            $aplicaveis = array_filter($regras, static fn($r) => (int)($r['CODCRITERIOFORNECEDOR'] ?? 0) === 0 || (int)$r['CODCRITERIOFORNECEDOR'] === $crit);
            $forn = (int)$col($d, ['CODFORNECEDOR']);
            if ($aplicaveis) {
                $meu = $admin;
                foreach ($aplicaveis as $r) if ((int)($r['CODUSUARIO'] ?? 0) === $this->usuario || isset($meusGrupos[(int)($r['CODGRUPO'] ?? 0)])) { $meu = true; break; }
                if (!$meu) continue;
                $porForn[$forn] = ($porForn[$forn] ?? 0) + 1;
            } else $semRegra[$forn] = true; // sem regra: decide o responsável pelo fornecedor (linha original da view)
        }
        // Linhas originais (responsável pelo fornecedor) só ficam quando o fornecedor tem documento sem regra
        $saida = [];
        foreach ($itens as $i) {
            if ((string)$i['TIPO'] !== 'FORNECEDOR_DOC' || isset($semRegra[(int)$i['CODIGO']])) $saida[] = $i;
        }
        foreach ($porForn as $f => $n) {
            if ($n <= 0) continue;
            $fr = array_change_key_case($this->linhas('fo_fornecedores', ['CODFORNECEDOR' => self::exato((string)$f)])[0] ?? [], CASE_UPPER);
            $saida[] = ['CODUSUARIO' => $this->usuario, 'TIPO' => 'FORNECEDOR_DOC', 'CODIGO' => (string)$f, 'CHAVE2' => '', 'TITULO' => trim((string)($fr['RAZAOSOCIAL'] ?? '')) ?: '#' . $f,
                'DETALHE' => $n . ($n === 1 ? ' documento para você aprovar' : ' documentos para você aprovar'), 'DESDE' => null, 'TRANSACAO' => 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR', 'ACAO' => 'pode_visualizar',
                'LINK' => self::link('FORNECEDOR_DOC', (string)$f, '')];
        }
        return $saida;
    }

    /** ADM DA EMPRESA (perfil do usuário logado) vê o calendário de todos os usuários da empresa */
    public static function admEmpresa(): bool
    {
        $p = strtoupper(trim((string)($_SESSION['usuario']['codigo_perfil'] ?? '')));
        return in_array(trim((string)preg_replace('/[\s_-]+/', ' ', $p)), ['ADM DA EMPRESA', 'ADM EMPRESA'], true);
    }

    /**
     * Pessoas cujas pendências o usuário vê no calendário (codusuario => nome), o próprio usuário primeiro:
     * ADM DA EMPRESA = todos os usuários da empresa; os demais = ele e os subordinados em todos os níveis
     * (usuarios.codusuario_superior: Camila é superior do João, João da Maria → Camila vê os três).
     */
    public function equipe(): array
    {
        $usuarios = [];
        foreach ($this->linhas('vw_usuarios_grid', [], 50) as $u) {
            $usuarios[(int)$u['codusuario']] = ['nome' => trim((string)($u['nome'] ?? '')) ?: (string)($u['userid'] ?? ''), 'superior' => (int)($u['codusuario_superior'] ?? 0)];
        }
        $eu = [$this->usuario => $usuarios[$this->usuario]['nome'] ?? ''];
        if (self::admEmpresa()) {
            $outros = array_map(static fn($u) => $u['nome'], array_diff_key($usuarios, $eu));
        } else {
            $outros = []; $fila = [$this->usuario];
            while ($fila) {   // desce a hierarquia; o isset evita laço quando o cadastro tem ciclo
                $chefe = array_shift($fila);
                foreach ($usuarios as $id => $u) if ($u['superior'] === $chefe && !isset($outros[$id]) && !isset($eu[$id])) { $outros[$id] = $u['nome']; $fila[] = $id; }
            }
        }
        asort($outros, SORT_NATURAL | SORT_FLAG_CASE);
        return $eu + $outros;
    }

    /** O usuário pode ver o que pertence a $dono (ele mesmo ou alguém da sua equipe, pela regra de equipe()) */
    public static function podeVer(PDO $pdo, int $usuario, int $dono): bool
    {
        return $dono > 0 && ($dono === $usuario || isset((new self($pdo, $usuario))->equipe()[$dono]));
    }

    private function hoje(array $prazos): int
    {
        $hoje = date('Y-m-d');
        $cal = array_filter($this->calendario((int)date('Y')), static fn($i) => $i['data'] === $hoje);
        return $cal ? count($cal) : count(array_filter($prazos, static fn($p) => $p['SITUACAO'] === 'HOJE'));   // sem vw_painel_calendario: o de Próximos prazos
    }

    /** Números da barra lateral */
    public function contagens(): array
    {
        $prazos = $this->prazos();
        $pend = 0;
        // c578: o ícone de Pendências mostra a soma dos cards do painel que ele abre (classes/PendenciasPainel.php)
        try { $pend = PendenciasPainel::soma($this->pdo, $this->usuario); } catch (Throwable) {}
        return [
            'pendencias' => $pend,
            'aprovacoes' => count($this->aprovacoes()),
            'prazos' => count(array_filter($prazos, static fn($p) => in_array($p['SITUACAO'], ['HOJE', 'PROXIMO'], true))),
            'atrasados' => count(array_filter($prazos, static fn($p) => $p['SITUACAO'] === 'ATRASADO')),
            // Pendências do dia = o que o calendário mostra em hoje (inclui os eventos da agenda)
            'hoje' => $this->hoje($prazos),
        ];
    }
}
