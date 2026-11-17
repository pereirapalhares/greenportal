<?php
declare(strict_types=1);

require_once __DIR__ . '/MigracaoCrud.php';
require_once __DIR__ . '/RequisitoRisco.php';

/**
 * Migração 341 (nível de atendimento e risco dos requisitos legais) num banco de empresa. Usada pelo runner da 341 e, depois
 * dela aplicada, também pelo runner da 284 (que recria sp_situacao_legal_calcular_alvo e as views da situação: sem reaplicar
 * a 341, rodar a 284 de novo voltaria o cálculo antigo).
 */
final class Migracao341RequisitosRisco
{
    public const PROCEDURES = ['sp_ml_risco_salvar', 'sp_ml_requisito_na', 'sp_situacao_legal_calcular_alvo'];
    public const COLUNAS = [
        'itens_na' => 'INT NOT NULL DEFAULT 0',
        'itens_aplicaveis' => 'INT NOT NULL DEFAULT 0',
        'itens_atendidos' => 'INT NOT NULL DEFAULT 0',
        'pct_atendimento' => 'DECIMAL(5,1) NULL',
        'risco' => "VARCHAR(12) NULL COMMENT 'BAIXO, MODERADO, ALTO, CRITICO ou NAO_AVALIADO (pior risco entre os itens aplicáveis)'",
        'risco_pontos' => 'TINYINT NULL',
        'pendencias_criticas' => 'INT NOT NULL DEFAULT 0',
        'itens_sem_risco' => 'INT NOT NULL DEFAULT 0',
    ];
    /** Entidade das responsabilidades (migrações 093 e seguintes) => área do item. */
    public const ENTIDADES = ['li_licencas' => 'LICENCAS', 'mm_monitoramento' => 'MEDICAO', 'ml_treinamentos' => 'TREINAMENTOS',
        'ml_infraestrutura' => 'INFRAESTRUTURA', 'documentos' => 'CONTROLES', 'fo_fornecedores' => 'HOMOLOGACAO'];

    public static function sqlArquivo(): string
    {
        return dirname(__DIR__) . '/database/341_requisitos_atendimento_risco.sql';
    }

    /** Aplicada no banco? (tabela ml_risco_itens existe) */
    public static function aplicada(PDO $pdo): bool
    {
        return MigracaoCrud::existe($pdo, 'ml_risco_itens');
    }

    /** @return array relatório do banco; lança exceção em erro */
    public static function aplicar(PDO $pdo, bool $simular, bool $calcular = true): array
    {
        $info = [];
        $cols = static fn(string $t): array => array_map('strtolower', MigracaoCrud::colunas($pdo, $t));
        if (!MigracaoCrud::existe($pdo, 'situacao_legal_calculada') || !MigracaoCrud::existe($pdo, 'situacao_legal_fila')) {
            return ['ignorado' => 'sem a situação legal calculada (rode antes a migração 284)'];
        }

        // 1. Colunas novas da situação calculada
        $tem = $cols('situacao_legal_calculada');
        $novas = [];
        foreach (self::COLUNAS as $c => $def) if (!in_array($c, $tem, true)) $novas[] = "ADD COLUMN `$c` $def";
        if ($novas && !$simular) $pdo->exec('ALTER TABLE `situacao_legal_calculada` ' . implode(', ', $novas));
        $info['colunas_situacao'] = $novas ? count($novas) . ($simular ? ' a criar' : ' criadas') : 'já existiam';

        // 2. Views auxiliares que dependem do banco
        $lic = MigracaoCrud::existe($pdo, 'li_licencas') ? $cols('li_licencas') : [];
        if (in_array('codlicenca_base', $lic, true)) {
            $vLic = 'SELECT `CODLICENCA`, COALESCE(`CODLICENCA_BASE`, `CODLICENCA`) AS `CHAVE` FROM `li_licencas`';
            $info['licencas'] = 'com versões: risco e "não se aplica" na licença original';
        } elseif (in_array('codlicenca', $lic, true)) {
            $vLic = 'SELECT `CODLICENCA`, `CODLICENCA` AS `CHAVE` FROM `li_licencas`';
            $info['licencas'] = 'sem versionamento';
        } else {
            $vLic = 'SELECT CAST(0 AS SIGNED) AS `CODLICENCA`, CAST(0 AS SIGNED) AS `CHAVE` FROM DUAL WHERE FALSE';
            $info['licencas'] = 'sem li_licencas';
        }
        $resp = MigracaoCrud::existe($pdo, 'vw_responsabilidade_efetiva') ? $cols('vw_responsabilidade_efetiva') : [];
        if (!array_diff(['entidade', 'registro_id', 'codusuario_destino', 'nome_responsavel'], $resp)) {
            $casos = '';
            foreach (self::ENTIDADES as $ent => $area) $casos .= " WHEN '$ent' THEN '$area'";
            $lista = "'" . implode("', '", array_keys(self::ENTIDADES)) . "'";
            $vResp = "SELECT CAST(CASE CONVERT(`ENTIDADE` USING utf8mb4) COLLATE utf8mb4_unicode_ci$casos END AS CHAR(20) CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS `AREA`,
       CAST(CASE WHEN TRIM(COALESCE(`REGISTRO_ID`, '')) = '' THEN 0 ELSE `REGISTRO_ID` END AS UNSIGNED) AS `ITEM_ID`,
       `CODUSUARIO_DESTINO` AS `CODUSUARIO`, CONVERT(MAX(`NOME_RESPONSAVEL`) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS `NOME`
  FROM `vw_responsabilidade_efetiva`
 WHERE CONVERT(`ENTIDADE` USING utf8mb4) COLLATE utf8mb4_unicode_ci IN ($lista) AND TRIM(COALESCE(`REGISTRO_ID`, '')) REGEXP '^[0-9]*$'
   AND `CODUSUARIO_DESTINO` IS NOT NULL
 GROUP BY 1, 2, 3";
            $info['responsaveis'] = 'responsabilidades efetivas (por registro e por tabela)';
        } else {
            $vResp = "SELECT CAST('' AS CHAR(20) CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS `AREA`, CAST(0 AS UNSIGNED) AS `ITEM_ID`,
       CAST(NULL AS SIGNED) AS `CODUSUARIO`, CAST(NULL AS CHAR(150) CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS `NOME` FROM DUAL WHERE FALSE";
            $info['responsaveis'] = 'sem vw_responsabilidade_efetiva: as pendências críticas aparecem sem responsável';
        }
        if (!$simular) {
            $pdo->exec("CREATE OR REPLACE VIEW `vw_ml_licenca_chave` AS $vLic");
            $pdo->exec("CREATE OR REPLACE VIEW `vw_ml_risco_responsaveis` AS $vResp");
        }

        // 3. Blocos do arquivo
        $pulados = [];
        $sql = (string)file_get_contents(self::sqlArquivo());
        foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) {
            $requer = preg_match('/^-- requer:\s*(.+)$/m', $bloco, $m) ? array_map('trim', explode(',', $m[1])) : [];
            $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
            if ($cmd === '') continue;
            $faltam = array_values(array_filter($requer, static fn($t) => !MigracaoCrud::existe($pdo, $t)));
            if ($faltam) {
                if (preg_match('/(VIEW|PROCEDURE)\s+`(\w+)`/', $cmd, $n) && !str_starts_with($cmd, 'DROP')) $pulados[$n[2]] = 'sem ' . implode(', ', $faltam);
                continue;
            }
            if (!$simular) $pdo->exec($cmd);
        }
        if ($pulados) $info['nao_criados'] = $pulados;
        if ($simular) return $info + ['modo' => 'simulação: nada gravado'];

        // 3b. Views do dashboard (aba Atendimento legal), sobre o grid de requisitos, sem as leis revogadas
        $info['dashboard'] = self::viewsDashboard($pdo);

        // 4. Critérios (texto do documento; tabela de fornecedores proposta)
        $n = 0;
        foreach (RequisitoRisco::CRITERIOS as $area => $niveis) {
            $rot = $area === 'PROBABILIDADE' ? RequisitoRisco::ROTULOS_PROBABILIDADE : RequisitoRisco::ROTULOS_SEVERIDADE;
            foreach ($niveis as $nivel => $criterio) {
                MigracaoCrud::salvar($pdo, 'ml_risco_criterios', ['AREA' => $area, 'NIVEL' => $nivel], ['ROTULO' => $rot[$nivel], 'CRITERIO' => $criterio]);
                $n++;
            }
        }
        $info['criterios'] = $n;

        $s = $pdo->query("SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_TYPE = 'PROCEDURE'");
        $faltamP = array_values(array_diff(self::PROCEDURES, $s->fetchAll(PDO::FETCH_COLUMN)));
        $s->closeCursor();
        $info['procedures'] = $faltamP ? ['faltam' => $faltamP] : 'gravadas';
        if ($faltamP) throw new RuntimeException('procedures não gravadas: ' . implode(', ', $faltamP));

        // 5. Recálculo completo (preenche % e risco de todos os requisitos)
        if ($calcular) {
            $t0 = microtime(true);
            $st = $pdo->prepare('CALL sp_situacao_legal_recalcular(NULL, NULL)');
            $st->execute();
            $r = $st->fetchAll(PDO::FETCH_ASSOC);
            $st->closeCursor();
            $tot = ['requisitos' => 0, 'itens_aplicaveis' => 0, 'itens_atendidos' => 0, 'pendencias_criticas' => 0, 'itens_sem_risco' => 0,
                'risco' => ['CRITICO' => 0, 'ALTO' => 0, 'MODERADO' => 0, 'BAIXO' => 0, 'NAO_AVALIADO' => 0]];
            foreach (MigracaoCrud::selecionar($pdo, 'vw_situacao_legal_painel_risco') as $l) {
                $tot['requisitos'] += (int)$l['REQUISITOS'];
                $tot['itens_aplicaveis'] += (int)$l['ITENS_APLICAVEIS'];
                $tot['itens_atendidos'] += (int)$l['ITENS_ATENDIDOS'];
                $tot['pendencias_criticas'] += (int)$l['PENDENCIAS_CRITICAS'];
                $tot['itens_sem_risco'] += (int)$l['ITENS_SEM_RISCO'];
                foreach (['CRITICO', 'ALTO', 'MODERADO', 'BAIXO', 'NAO_AVALIADO'] as $k) $tot['risco'][$k] += (int)$l['RISCO_' . $k];
            }
            $tot['pct_atendimento'] = $tot['itens_aplicaveis'] > 0 ? round($tot['itens_atendidos'] * 100 / $tot['itens_aplicaveis'], 1) : null;
            $tot['itens_distintos_sem_risco'] = count(MigracaoCrud::selecionar($pdo, 'vw_ml_risco_nao_avaliados'));
            $info['calculo'] = ['pares_calculados' => (int)($r[0]['requisitos'] ?? 0), 'situacoes_alteradas' => (int)($r[0]['alterados'] ?? 0)]
                + $tot + ['segundos' => round(microtime(true) - $t0, 1)];
        }
        return $info;
    }

    /**
     * vw_dash_legal_risco_unidade (por unidade: % de atendimento dos itens, requisitos por risco, pendências críticas),
     * vw_dash_legal_risco_matriz (requisitos por risco × faixa de % de atendimento) e vw_dash_legal_pendencias_criticas
     * (pendência crítica com o requisito, a unidade e o responsável do item).
     */
    private static function viewsDashboard(PDO $pdo): string
    {
        $g = 'vw_modulolegal_requisitos_situacao_grid';
        $cols = array_map('strtolower', MigracaoCrud::colunas($pdo, $g));
        $tem = static fn(string ...$c): bool => !array_diff(array_map('strtolower', $c), $cols);
        if (!$tem('CODLEGISLACAO', 'CODUNIDADE', 'PCT_ATENDIMENTO', 'RISCO_REQUISITO')) return 'não criadas: grid de requisitos sem as colunas da 341';
        $txt = static fn(string $c): string => "CONVERT(COALESCE(g.`$c`, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci";
        $revogada = $tem('LEI_REVOGADA') ? "WHERE UPPER(" . $txt('LEI_REVOGADA') . ") NOT IN ('SIM', 'S')" : '';
        $unidade = $tem('UNIDADE') ? 'g.`UNIDADE`' : ($tem('DSCUNIDADE') ? 'g.`DSCUNIDADE`' : "CONCAT('Unidade ', g.`CODUNIDADE`)");
        $risco = "COALESCE(c.`risco`, '')";
        $pdo->exec("CREATE OR REPLACE VIEW `vw_dash_legal_risco_unidade` AS
SELECT g.`CODUNIDADE`, MAX($unidade) AS `UNIDADE`, COUNT(*) AS `REQUISITOS`,
       CAST(COALESCE(SUM(c.`itens_aplicaveis`), 0) AS SIGNED) AS `ITENS_APLICAVEIS`, CAST(COALESCE(SUM(c.`itens_atendidos`), 0) AS SIGNED) AS `ITENS_ATENDIDOS`,
       CAST(COALESCE(SUM(c.`itens_na`), 0) AS SIGNED) AS `ITENS_NA`,
       CASE WHEN SUM(c.`itens_aplicaveis`) > 0 THEN ROUND(SUM(c.`itens_atendidos`) * 100 / SUM(c.`itens_aplicaveis`), 1) END AS `PCT_ATENDIMENTO`,
       CAST(SUM($risco = 'CRITICO') AS SIGNED) AS `RISCO_CRITICO`, CAST(SUM($risco = 'ALTO') AS SIGNED) AS `RISCO_ALTO`,
       CAST(SUM($risco = 'MODERADO') AS SIGNED) AS `RISCO_MODERADO`, CAST(SUM($risco = 'BAIXO') AS SIGNED) AS `RISCO_BAIXO`,
       CAST(SUM($risco = 'NAO_AVALIADO') AS SIGNED) AS `RISCO_NAO_AVALIADO`, CAST(SUM($risco = '') AS SIGNED) AS `SEM_ITENS`,
       CAST(COALESCE(SUM(c.`pendencias_criticas`), 0) AS SIGNED) AS `PENDENCIAS_CRITICAS`,
       CAST(SUM(COALESCE(c.`pendencias_criticas`, 0) > 0) AS SIGNED) AS `REQUISITOS_PENDENCIA_CRITICA`
  FROM `$g` g LEFT JOIN `situacao_legal_calculada` c ON c.`CODLEGISLACAO` = g.`CODLEGISLACAO` AND c.`CODUNIDADE` = g.`CODUNIDADE`
 $revogada
 GROUP BY g.`CODUNIDADE`");
        $pdo->exec("CREATE OR REPLACE VIEW `vw_dash_legal_risco_matriz` AS
SELECT g.`CODUNIDADE`, CAST(CASE WHEN $risco = '' THEN 'SEM_ITENS' ELSE $risco END AS CHAR(12) CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS `RISCO`,
       CAST(CASE WHEN c.`pct_atendimento` IS NULL THEN 'SEM' WHEN c.`pct_atendimento` >= 100 THEN 'F100' WHEN c.`pct_atendimento` >= 50 THEN 'F50'
                 WHEN c.`pct_atendimento` > 0 THEN 'F1' ELSE 'F0' END AS CHAR(4) CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS `FAIXA`,
       COUNT(*) AS `TOTAL`
  FROM `$g` g LEFT JOIN `situacao_legal_calculada` c ON c.`CODLEGISLACAO` = g.`CODLEGISLACAO` AND c.`CODUNIDADE` = g.`CODUNIDADE`
 $revogada
 GROUP BY g.`CODUNIDADE`, 2, 3");
        $extra = '';
        foreach (['CODIGO', 'DSCTIPO', 'NR', 'ANO'] as $c) $extra .= $tem($c) ? ", g.`$c`" : ", '' AS `$c`";
        $pdo->exec("CREATE OR REPLACE VIEW `vw_dash_legal_pendencias_criticas` AS
SELECT p.`CODLEGISLACAO`, p.`CODUNIDADE`, $unidade AS `UNIDADE`$extra, p.`AREA`, p.`ITEM_ID`, p.`ITEM`, p.`STATUS`, p.`MOTIVO`, p.`SEVERIDADE`, p.`PROBABILIDADE`, p.`PONTOS`,
       p.`CODUSUARIO_ITEM`, COALESCE(p.`RESPONSAVEL_ITEM`, '') AS `RESPONSAVEL_ITEM`
  FROM `vw_ml_pendencias_criticas` p
  JOIN `$g` g ON g.`CODLEGISLACAO` = p.`CODLEGISLACAO` AND g.`CODUNIDADE` = p.`CODUNIDADE`
 $revogada");
        return 'vw_dash_legal_risco_unidade, vw_dash_legal_risco_matriz, vw_dash_legal_pendencias_criticas';
    }

    /**
     * Ajuda (universal, ajuda_transacoes): põe o trecho entre <!-- ml341 --> e <!-- /ml341 --> no fim do texto atual, sem apagar o
     * resto; rodando de novo, só o trecho entre as marcas é trocado. @return array transacao => incluída | atualizada | igual
     */
    public static function gravarAjuda(PDO $universal, bool $simular): array
    {
        if (!MigracaoCrud::existe($universal, 'ajuda_transacoes')) return ['aviso' => 'sem ajuda_transacoes no universal'];
        $textos = require dirname(__DIR__) . '/database/341_ajuda_requisitos.php';
        $out = [];
        foreach ($textos as $transacao => $d) {
            $atual = MigracaoCrud::primeiro($universal, 'ajuda_transacoes', ['transacao' => $transacao]);
            $dados = [];
            // Texto salvo depois na tela Ajuda das transações perde as marcas (o editor limpa comentários): não duplica o trecho
            $pt = (string)($atual['texto_pt'] ?? '');
            $assinatura = trim(html_entity_decode(strip_tags((string)preg_replace('/^.*?<strong>(.*?)<\/strong>.*$/s', '$1', $d['texto']['pt'])), ENT_QUOTES, 'UTF-8'));
            if ($atual !== null && !str_contains($pt, '<!-- ml341 -->') && $assinatura !== '' && str_contains(html_entity_decode(strip_tags($pt), ENT_QUOTES, 'UTF-8'), $assinatura)) {
                $out[$transacao] = 'já tem o texto (editado na tela Ajuda das transações): não alterada';
                continue;
            }
            foreach (['pt', 'en', 'es'] as $lg) {
                $trecho = "<!-- ml341 -->\n" . $d['texto'][$lg] . "\n<!-- /ml341 -->";
                $velho = (string)($atual['texto_' . $lg] ?? '');
                $velho = trim((string)preg_replace('/\s*<!-- ml341 -->.*?<!-- \/ml341 -->/s', '', $velho));
                $dados['texto_' . $lg] = ($velho === '' ? '' : $velho . "\n") . $trecho;
                if ($atual === null || trim((string)($atual['titulo_' . $lg] ?? '')) === '') $dados['titulo_' . $lg] = $d['titulo'][$lg];
            }
            if ($atual !== null) {
                $igual = true;
                foreach ($dados as $k => $v) if ((string)($atual[$k] ?? '') !== $v) $igual = false;
                if ($igual) { $out[$transacao] = 'igual'; continue; }
            }
            if ($simular) { $out[$transacao] = $atual === null ? 'será incluída' : 'será atualizada'; continue; }
            $r = MigracaoCrud::salvar($universal, 'ajuda_transacoes', ['transacao' => $transacao], $dados + ['ativo' => 1]);
            $out[$transacao] = $r === 'alterado' ? 'atualizada' : 'incluída';
        }
        return $out;
    }
}
