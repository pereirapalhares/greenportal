<?php
declare(strict_types=1);

/*
 * Responsáveis automáticos pelo perfil de atribuições (migração 345; Gestão de tarefas, etapa 3).
 *
 * Quem executa: o perfil de atribuições vigente (aprovado pelo gestor) diz, por módulo e pilar, quem EXECUTA, APROVA ou
 * ACOMPANHA, e em quais unidades (sem unidade marcada = todas). vw_resp_auto_candidatos traz os candidatos com as tarefas
 * abertas de cada um.
 * Escolha: entre os que executam o pilar na unidade, quem tem menos tarefas abertas (empate: menor código). Os demais que
 * executam e os que acompanham recebem o alerta de acompanhamento; os que aprovam, o alerta quando a tarefa é concluída.
 * Ninguém no perfil: a tarefa fica com quem pediu a análise (como antes) e o ADM DA EMPRESA recebe um alerta; o item fica
 * sem responsável e o ADM recebe o alerta. Empresa sem nenhum perfil aprovado: sem esse alerta (administradores() vazio).
 * Usado por AiApprovedTaskService (tarefas dos potenciais aprovados), pelo serviço de responsabilidades (itens novos dos
 * pilares sem responsável: processarItens) e por api/tarefa_concluir.php (aviso aos que aprovam).
 * Leitura por views e procedures (vw_resp_auto_*, sp_resp_auto_*).
 */
final class ResponsavelAutomatico
{
    /** Pilares do perfil por área do item: [módulo do perfil, pilar, módulo da camada de responsabilidades, rótulo] */
    public const AREAS = [
        'LICENCAS' => ['MODULO_LEGAL', 'LICENCAS', 'LICENCAS', 'Licenças'],
        'MONITORAMENTO' => ['MODULO_LEGAL', 'MONITORAMENTO', 'MEDICAO_MONITORAMENTO', 'Medição e monitoramento'],
        'TREINAMENTOS' => ['MODULO_LEGAL', 'TREINAMENTOS', 'TREINAMENTO', 'Treinamentos'],
        'INFRAESTRUTURA' => ['MODULO_LEGAL', 'INFRAESTRUTURA', 'INFRAESTRUTURA', 'Infraestrutura'],
        'CONTROLES' => ['MODULO_LEGAL', 'CONTROLES', 'CONTROLE', 'Controles'],
    ];

    /** Tipo do potencial da IA → [módulo do perfil, pilar] (influência = fornecedores) */
    public const POTENCIAL = [
        'LICENCA' => ['MODULO_LEGAL', 'LICENCAS'], 'MONITORAMENTO' => ['MODULO_LEGAL', 'MONITORAMENTO'], 'TREINAMENTO' => ['MODULO_LEGAL', 'TREINAMENTOS'],
        'INFRAESTRUTURA' => ['MODULO_LEGAL', 'INFRAESTRUTURA'], 'CONTROLE' => ['MODULO_LEGAL', 'CONTROLES'], 'INFLUENCIA' => ['HOMOLOGACAO_FORNECEDORES', 'HOMOLOGACAO'],
    ];

    /** Itens de cada área: tabela, chave, descrição, tela, entidade da responsabilidade */
    public const TABELAS = [
        'LICENCAS' => ['li_licencas', 'CODLICENCA', 'DSCLICENCA', 'manutencaolegal_licencas.php', 'li_licencas'],
        'MONITORAMENTO' => ['mm_monitoramento', 'CODMONITORAMENTO', 'PARAMETROS', 'medicaoemonitoramento_mm_monitoramento.php', 'mm_monitoramento'],
        'TREINAMENTOS' => ['ml_treinamentos', 'CODTREINAMENTO', 'DSCTREINAMENTO', 'modulolegal_treinamentos.php', 'ml_treinamentos'],
        'INFRAESTRUTURA' => ['ml_infraestrutura', 'CODINFRAESTRUTURA', 'DSCINFRAESTRUTURA', 'modulolegal_InfraEstrutura.php', 'ml_infraestrutura'],
        'CONTROLES' => ['documentos', 'CODDOCUMENTO', 'TITULO', 'modulolegal_controle.php', 'documentos'],
    ];

    /** Área deste serviço → área das regras de "Responsáveis e avisos" (migração 361). */
    private const AREA_361 = ['MONITORAMENTO' => 'MEDICAO'];

    private ?array $candidatos = null;

    public function __construct(private readonly PDO $pdo) {}

    public static function disponivel(PDO $pdo): bool
    {
        try { $s = $pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME IN ('sp_resp_auto_pendentes', 'sp_resp_auto_tarefa', 'sp_resp_auto_alerta')"); $n = (int)$s->fetchColumn(); $s->closeCursor(); return $n === 3; }
        catch (Throwable) { return false; }
    }

    // ------------------------------------------------------------------ escolha
    private function candidatos(): array
    {
        if ($this->candidatos === null) {
            $s = $this->pdo->query('SELECT CODUSUARIO, NOME, MODULO, PILAR, PAPEL, CODUNIDADE, TAREFAS_ABERTAS FROM vw_resp_auto_candidatos');
            $this->candidatos = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
        }
        return $this->candidatos;
    }

    /**
     * Quem executa, acompanha e aprova o pilar na unidade.
     * @return array{executor: ?array, outros: int[], acompanham: int[], aprovam: int[], motivo: string}
     */
    public function escolher(string $modulo, string $pilar, int $unidade, ?string $rotulo = null): array
    {
        $papeis = ['EXECUTA' => [], 'APROVA' => [], 'ACOMPANHA' => []];
        foreach ($this->candidatos() as $c) {
            if (strcasecmp((string)$c['MODULO'], $modulo) !== 0 || strcasecmp((string)$c['PILAR'], $pilar) !== 0) continue;
            $un = $c['CODUNIDADE'] === null ? 0 : (int)$c['CODUNIDADE'];
            if ($un !== 0 && $unidade > 0 && $un !== $unidade) continue;
            $papel = strtoupper((string)$c['PAPEL']);
            if (!isset($papeis[$papel])) continue;
            $papeis[$papel][(int)$c['CODUSUARIO']] = ['CODUSUARIO' => (int)$c['CODUSUARIO'], 'NOME' => (string)$c['NOME'], 'TAREFAS' => (int)$c['TAREFAS_ABERTAS']];
        }
        $exec = array_values($papeis['EXECUTA']);
        usort($exec, static fn($a, $b) => [$a['TAREFAS'], $a['CODUSUARIO']] <=> [$b['TAREFAS'], $b['CODUSUARIO']]);
        $escolhido = $exec[0] ?? null;
        $rotulo = $rotulo ?? $pilar;
        $motivo = $escolhido
            ? sprintf('Executa "%s"%s no perfil de atribuições; %s', $rotulo, $unidade > 0 ? ' nesta unidade' : '',
                count($exec) > 1 ? sprintf('entre %d pessoas que executam, é quem tem menos tarefas abertas (%d).', count($exec), $escolhido['TAREFAS']) : sprintf('única pessoa que executa (%d tarefa(s) aberta(s)).', $escolhido['TAREFAS']))
            : sprintf('Ninguém executa "%s"%s no perfil de atribuições vigente.', $rotulo, $unidade > 0 ? ' nesta unidade' : '');
        $outros = array_map(static fn($e) => $e['CODUSUARIO'], array_slice($exec, 1));
        $acomp = array_values(array_diff(array_unique(array_merge($outros, array_keys($papeis['ACOMPANHA']))), [$escolhido['CODUSUARIO'] ?? 0]));
        $aprov = array_values(array_diff(array_keys($papeis['APROVA']), [$escolhido['CODUSUARIO'] ?? 0]));
        return ['executor' => $escolhido, 'outros' => $outros, 'acompanham' => $acomp, 'aprovam' => $aprov, 'motivo' => $motivo];
    }

    // ------------------------------------------------------------------ alertas
    public function alertar(int $usuario, string $titulo, string $mensagem, string $link): int
    {
        if ($usuario <= 0) return 0;
        $r = $this->call('CALL sp_resp_auto_alerta(?, ?, ?, ?)', [$usuario, mb_substr($titulo, 0, 180), $mensagem, mb_substr($link, 0, 500)]);
        return (int)($r[0]['CODALERTA'] ?? 0) > 0 ? 1 : 0;
    }

    /**
     * ADM DA EMPRESA ativos (sem nenhum, o ADMINISTRADOR). Decisão do usuário (23/10/2026): enquanto a empresa não tiver nenhum
     * perfil de atribuições aprovado com pilar, o aviso de "ninguém no perfil" fica desligado (seria um alerta por item/tarefa);
     * liga sozinho quando o primeiro perfil for aprovado.
     */
    public function administradores(): array
    {
        if (!$this->candidatos()) return [];
        $s = $this->pdo->query('SELECT CODUSUARIO, ADMINISTRADOR FROM vw_resp_auto_adm');
        $l = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor();
        $adm = array_map(static fn($r) => (int)$r['CODUSUARIO'], array_filter($l, static fn($r) => (int)$r['ADMINISTRADOR'] === 0));
        return $adm ?: array_map(static fn($r) => (int)$r['CODUSUARIO'], $l);
    }

    // ------------------------------------------------------------------ itens novos sem responsável
    /** Serviço de responsabilidades: itens novos dos pilares sem responsável há mais de 1 hora. Devolve quantos foram decididos. */
    public function processarItens(): int
    {
        $n = 0;
        foreach ($this->call('CALL sp_resp_auto_pendentes(200)') as $it) {
            $area = (string)$it['AREA'];
            if (!isset(self::AREAS[$area])) continue;
            [$mod, $pilar, , $rotulo] = self::AREAS[$area];
            $unidade = (int)($it['CODUNIDADE'] ?? 0);
            $e = $this->escolher($mod, $pilar, $unidade, $rotulo);
            $item = trim((string)$it['ITEM']) !== '' ? trim((string)$it['ITEM']) : $rotulo . ' nº ' . $it['ITEM_ID'];
            // Abre o item já filtrado (resp_auto_abrir.php monta o filtro: o link do alerta não pode levar JSON)
            $link = '/principal/resp_auto_abrir.php?' . http_build_query(['area' => $area, 'id' => (int)$it['ITEM_ID']]);
            $exec = (int)($e['executor']['CODUSUARIO'] ?? 0);
            $this->call('CALL sp_resp_auto_atribuir(?, ?, ?, ?, ?, ?, ?, ?)', [$area, (int)$it['ITEM_ID'], (string)$it['ENTIDADE'], (string)$it['MODULO_RESP'],
                $exec ?: null, implode(',', $e['acompanham']), implode(',', $e['aprovam']), mb_substr($e['motivo'], 0, 1000)]);
            if ($exec > 0) {
                $this->alertar($exec, 'Você foi indicado responsável: ' . mb_strimwidth($item, 0, 110, '…'), $rotulo . ': "' . $item . '" foi cadastrado sem responsável e você foi indicado pelo seu perfil de atribuições. ' . $e['motivo'] . ' Se não for com você, ajuste as responsabilidades do item.', $link . '&resp_auto=executa');
                foreach ($e['acompanham'] as $u) $this->alertar($u, 'Acompanhamento: ' . mb_strimwidth($item, 0, 120, '…'), $rotulo . ': "' . $item . '" ficou com ' . $e['executor']['NOME'] . ' (indicação pelo perfil de atribuições). Você acompanha este pilar.', $link . '&resp_auto=acompanha');
            } else {
                foreach ($this->administradores() as $u) $this->alertar($u, 'Item sem responsável: ' . mb_strimwidth($item, 0, 120, '…'), $rotulo . ': "' . $item . '" foi cadastrado sem responsável e ' . lcfirst($e['motivo']) . ' Indique o responsável no item ou complete os perfis de atribuições (Administração › Perfis de atribuições).', $link . '&resp_auto=sem');
            }
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------------ tarefas
    /**
     * Tarefa de potencial da IA: escolhe o executor (ou fica com quem pediu, $padrao) e registra a indicação.
     * @return array{usuario: int, encontrado: bool, motivo: string, acompanham: int[], aprovam: int[]}
     */
    public function paraTarefa(string $tipoPotencial, int $unidade, int $padrao): array
    {
        [$mod, $pilar] = self::POTENCIAL[strtoupper(trim($tipoPotencial))] ?? ['MODULO_LEGAL', 'REQUISITOS'];
        $rotulo = ['LICENCAS' => 'Licenças', 'MONITORAMENTO' => 'Medição e monitoramento', 'TREINAMENTOS' => 'Treinamentos', 'INFRAESTRUTURA' => 'Infraestrutura',
            'CONTROLES' => 'Controles', 'HOMOLOGACAO' => 'Homologação de fornecedores', 'REQUISITOS' => 'Requisitos legais'][$pilar] ?? $pilar;
        $e = $this->escolher($mod, $pilar, $unidade, $rotulo);
        $exec = (int)($e['executor']['CODUSUARIO'] ?? 0);
        return ['usuario' => $exec ?: $padrao, 'encontrado' => $exec > 0, 'motivo' => $e['motivo'] . ($exec > 0 ? '' : ' A tarefa ficou com quem pediu a análise.'),
            'acompanham' => $e['acompanham'], 'aprovam' => $e['aprovam'], 'modulo' => $mod, 'pilar' => $pilar, 'rotulo' => $rotulo,
            'nome' => (string)($e['executor']['NOME'] ?? '')];
    }

    /** Registra a indicação da tarefa e manda os alertas de acompanhamento (e o do ADM, sem ninguém no perfil). */
    public function registrarTarefa(int $tarefa, string $titulo, int $unidade, array $esc, int $solicitante): void
    {
        $this->call('CALL sp_resp_auto_tarefa(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$tarefa, mb_substr($titulo, 0, 300), $unidade, $esc['modulo'], $esc['pilar'], $esc['usuario'],
            $esc['encontrado'] ? 1 : 0, implode(',', $esc['acompanham']), implode(',', $esc['aprovam']), mb_substr($esc['motivo'], 0, 1000)]);
        $link = '/principal/painel_pendencias.php?codtarefa=' . $tarefa;
        if ($esc['encontrado']) {
            foreach ($esc['acompanham'] as $u) $this->alertar($u, 'Acompanhamento: ' . mb_strimwidth($titulo, 0, 120, '…'), 'A tarefa "' . $titulo . '" foi criada para ' . $esc['nome'] . ' (indicação pelo perfil de atribuições, ' . $esc['rotulo'] . '). Você acompanha este pilar.', $link . '&resp_auto=acompanha');
            if ($solicitante > 0 && $solicitante !== $esc['usuario']) $this->alertar($solicitante, 'Tarefa encaminhada: ' . mb_strimwidth($titulo, 0, 120, '…'), 'O potencial que você aprovou virou a tarefa "' . $titulo . '", encaminhada a ' . $esc['nome'] . ' pelo perfil de atribuições. ' . $esc['motivo'], $link . '&resp_auto=solicitante');
        } else {
            foreach ($this->administradores() as $u) $this->alertar($u, 'Falta responsável no perfil: ' . $esc['rotulo'], 'A tarefa "' . $titulo . '" ficou com quem pediu a análise porque ' . lcfirst($esc['motivo']) . ' Complete os perfis de atribuições (Administração › Perfis de atribuições).', $link . '&resp_auto=sem');
        }
    }

    /** Conclusão da tarefa (api/tarefa_concluir.php): alerta aos que aprovam o pilar. */
    public function avisarAprovadores(array $tarefa, string $quem): int
    {
        $n = 0;
        foreach (array_filter(array_map('intval', explode(',', (string)($tarefa['APROVADORES'] ?? '')))) as $u)
            $n += $this->alertar($u, 'Tarefa concluída para conferir: ' . mb_strimwidth((string)$tarefa['TITULO'], 0, 110, '…'), $quem . ' concluiu a tarefa "' . $tarefa['TITULO'] . '". Você aprova este pilar no perfil de atribuições: confira o que foi feito.', '/principal/painel_pendencias.php?codtarefa=' . (int)$tarefa['CODTAREFA'] . '&resp_auto=aprova');
        return $n;
    }

    // ------------------------------------------------------------------ views (migração)
    /** Colunas (minúsculas) de uma tabela do banco atual. */
    private static function colunas(PDO $pdo, string $t): array
    {
        $s = $pdo->prepare('SELECT LOWER(COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $s->execute([$t]); $c = $s->fetchAll(PDO::FETCH_COLUMN); $s->closeCursor();
        return array_flip($c);
    }

    /**
     * DDL da view vw_resp_auto_itens (uma parte por área que existe no banco) e o código mais alto de cada área (marca inicial).
     * @return array{0: string, 1: array<string,string>} [ddl, área => situação]
     */
    public static function viewItens(PDO $pdo): array
    {
        $temAtrib = isset(self::colunas($pdo, 'responsabilidade_atribuicoes')['registro_id']);
        $temRegras = isset(self::colunas($pdo, 'vw_resp_regras_registros')['registro_id']);
        $partes = []; $sit = [];
        foreach (self::TABELAS as $area => [$tabela, $chave, $nome, $pagina, $entidade]) {
            $c = self::colunas($pdo, $tabela);
            if (!isset($c[strtolower($chave)])) { $sit[$area] = 'sem a tabela ' . $tabela; continue; }
            [$mod, $pilar, $modResp] = self::AREAS[$area];
            $desc = isset($c[strtolower($nome)]) ? "x.`$nome`" : "CONCAT('#', x.`$chave`)";
            $unid = isset($c['codunidade']) ? 'x.`codunidade`' : 'NULL';
            $id = "x.`$chave`";
            // Licença com versões: a responsabilidade de qualquer versão da mesma licença vale
            $familia = $area === 'LICENCAS' && isset($c['codlicenca_base'])
                ? "IN (SELECT CAST(v.`CODLICENCA` AS CHAR) FROM `li_licencas` v WHERE COALESCE(v.`CODLICENCA_BASE`, v.`CODLICENCA`) = COALESCE(x.`CODLICENCA_BASE`, x.`CODLICENCA`))"
                : "= CAST($id AS CHAR)";
            $resp = [];
            foreach (['codusuario', 'codusuario_responsavel', 'codresponsavel'] as $col) if (isset($c[$col])) {
                // Licença com versões: o responsável de qualquer versão da mesma licença vale (a renovação herda)
                $resp[] = $area === 'LICENCAS' && isset($c['codlicenca_base'])
                    ? "EXISTS (SELECT 1 FROM `li_licencas` f WHERE COALESCE(f.`CODLICENCA_BASE`, f.`CODLICENCA`) = COALESCE(x.`CODLICENCA_BASE`, x.`CODLICENCA`) AND COALESCE(f.`$col`, 0) > 0)"
                    : "COALESCE(x.`$col`, 0) > 0";
                break;
            }
            if ($temAtrib) $resp[] = "EXISTS (SELECT 1 FROM `responsabilidade_atribuicoes` a WHERE a.`ENTIDADE` COLLATE utf8mb4_unicode_ci = '$entidade' COLLATE utf8mb4_unicode_ci
                AND a.`REGISTRO_ID` COLLATE utf8mb4_unicode_ci $familia AND a.`ATIVO` = 1 AND (a.`VALIDO_ATE` IS NULL OR a.`VALIDO_ATE` >= CURDATE()))";
            // Migração 361: registro coberto por uma regra de "Responsáveis e avisos" do módulo também tem responsável
            // (só quando já havia como saber o responsável; sem isso o item continua "com responsável", como antes)
            if ($temRegras && $resp) $resp[] = "EXISTS (SELECT 1 FROM `vw_resp_regras_registros` rr WHERE rr.`AREA` = '" . (self::AREA_361[$area] ?? $area) . "' AND rr.`REGISTRO_ID` = $id)";
            $tem = $resp ? '(CASE WHEN ' . implode(' OR ', $resp) . ' THEN 1 ELSE 0 END)' : '1';
            $onde = $area === 'LICENCAS' && isset($c['status_versao']) ? " WHERE COALESCE(x.`STATUS_VERSAO`, 'ATIVA') = 'ATIVA'" : '';
            $partes[] = "SELECT CAST('$area' AS CHAR(20)) COLLATE utf8mb4_unicode_ci AS `AREA`, CAST($id AS SIGNED) AS `ITEM_ID`,
       CAST(LEFT(COALESCE($desc, ''), 300) AS CHAR(300)) COLLATE utf8mb4_unicode_ci AS `ITEM`, CAST($unid AS SIGNED) AS `CODUNIDADE`,
       CAST('$mod' AS CHAR(60)) COLLATE utf8mb4_unicode_ci AS `MODULO`, CAST('$pilar' AS CHAR(100)) COLLATE utf8mb4_unicode_ci AS `PILAR`,
       CAST('$entidade' AS CHAR(100)) COLLATE utf8mb4_unicode_ci AS `ENTIDADE`, CAST('$modResp' AS CHAR(80)) COLLATE utf8mb4_unicode_ci AS `MODULO_RESP`,
       CAST('$pagina' AS CHAR(120)) COLLATE utf8mb4_unicode_ci AS `PAGINA`, CAST('$chave' AS CHAR(40)) COLLATE utf8mb4_unicode_ci AS `CHAVE`, $tem AS `TEM_RESPONSAVEL`
  FROM `$tabela` x$onde";
            $sit[$area] = 'ok' . ($resp ? '' : ' (sem coluna de responsável nem responsabilidades: considerado com responsável)');
        }
        $ddl = 'CREATE OR REPLACE VIEW `vw_resp_auto_itens` AS ' . ($partes ? implode("\nUNION ALL\n", $partes)
            : "SELECT CAST('' AS CHAR(20)) AS `AREA`, 0 AS `ITEM_ID`, '' AS `ITEM`, NULL AS `CODUNIDADE`, '' AS `MODULO`, '' AS `PILAR`, '' AS `ENTIDADE`, '' AS `MODULO_RESP`, '' AS `PAGINA`, '' AS `CHAVE`, 1 AS `TEM_RESPONSAVEL` FROM DUAL WHERE 1 = 0");
        return [$ddl, $sit];
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
