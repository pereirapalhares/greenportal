<?php
declare(strict_types=1);

/*
 * Históricos obrigatórios (migração 359; GER-16 do 01_Mudancas_Gerais / MM-77, CON-93, TRE-108, INF-123, HOM-141).
 * Decisões do usuário (12/11/2026): o próprio banco registra (gatilhos) toda inclusão, alteração e exclusão nas tabelas abaixo,
 * venha de onde vier (tela, IA, procedures, sincronismo); só os campos que mudaram; quem vê a tela vê o histórico.
 * Quem fez: @gp_usuario / @gp_origem, que o portal define em cada conexão (GreenPortalDatabase::initCommand). Sem eles
 * (gravação fora do portal, como o sincronismo com o sistema antigo), a origem fica vazia e aparece como "fora do portal".
 */
final class HistoricoAlteracoes
{
    /** tabela => telas (códigos de menu) que dão acesso ao histórico dela */
    public const TABELAS = [
        'mm_monitoramento' => ['MM_PLANO_MEDICAO_MONITORAMENTO'],
        'documentos' => ['CONTROLE_DOCUMENTOS', 'MEUS_CONTROLES'],
        'ml_treinamentos' => ['MODULOLEGAL_TREINAMENTOS', 'MEUS_TREINAMENTOS'],
        'ml_infraestrutura' => ['MODULOLEGAL_INFRAESTRUTURA', 'MINHAS_INFRAESTRUTURAS'],
        'fo_fornecedores' => ['HOMOLOGACAO_FORNECEDOR_FORNECEDOR', 'HOMOLOGACAO_FORNECEDORES_DESATIVADOS'],
        'fo_funcionarios' => ['HOMOLOGACAO_FORNECEDOR_FUNCIONARIO'],
    ];
    public const TABELA_HISTORICO = 'historico_alteracoes';
    /** tipos de coluna que não entram no histórico */
    private const TIPOS_FORA = ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary', 'geometry', 'point', 'json'];

    private static array $existe = [];

    public static function monitorada(string $tabela): bool
    {
        return isset(self::TABELAS[strtolower($tabela)]);
    }

    public static function disponivel(PDO $pdo): bool
    {
        $banco = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!array_key_exists($banco, self::$existe)) {
            $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $s->execute([self::TABELA_HISTORICO]);
            self::$existe[$banco] = (int)$s->fetchColumn() > 0;
            $s->closeCursor();
        }
        return self::$existe[$banco];
    }

    /** Coluna da chave primária (uma só) da tabela, ou null. */
    public static function chave(PDO $pdo, string $tabela): ?string
    {
        $s = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION");
        $s->execute([$tabela]);
        $c = $s->fetchAll(PDO::FETCH_COLUMN); $s->closeCursor();
        return count($c) === 1 ? (string)$c[0] : null;
    }

    /** Colunas que entram no histórico. */
    public static function colunas(PDO $pdo, string $tabela): array
    {
        $s = $pdo->prepare('SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $s->execute([$tabela]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $c) if (!in_array(strtolower((string)$c['DATA_TYPE']), self::TIPOS_FORA, true)) $out[] = (string)$c['COLUMN_NAME'];
        $s->closeCursor();
        return $out;
    }

    /** Gatilhos de outros nomes já existentes na tabela (não são alterados; só informados). */
    public static function gatilhosExistentes(PDO $pdo, string $tabela): array
    {
        $s = $pdo->prepare('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?');
        $s->execute([$tabela]);
        $r = array_values(array_filter($s->fetchAll(PDO::FETCH_COLUMN), static fn($n) => !str_starts_with((string)$n, 'trg_hist_')));
        $s->closeCursor();
        return $r;
    }

    /** DDL dos três gatilhos (inclusão, alteração, exclusão) da tabela. */
    public static function gatilhos(string $tabela, string $chave, array $colunas): array
    {
        $q = static fn(string $c): string => '`' . str_replace('`', '', $c) . '`';
        $lit = static fn(string $s): string => "'" . str_replace(["\\", "'"], ["\\\\", "''"], $s) . "'";
        $t = $q($tabela); $nome = preg_replace('/[^a-z0-9_]/', '', strtolower($tabela));
        $ins = static fn(string $op, string $reg, string $campo, string $de, string $para): string =>
            'INSERT INTO `' . self::TABELA_HISTORICO . "` (`tabela`, `registro_id`, `operacao`, `campo`, `valor_anterior`, `valor_novo`, `codusuario`, `origem`) VALUES ("
            . $lit($tabela) . ", CAST($reg AS CHAR), '$op', $campo, $de, $para, @gp_usuario, LEFT(@gp_origem, 120));";
        $json = static function (string $alias) use ($colunas, $q, $lit): string {
            $pares = [];
            foreach ($colunas as $c) $pares[] = $lit($c) . ', ' . $alias . '.' . $q($c);
            // JSON_OBJECT aceita no máximo os pares da tabela; colunas de texto longas entram inteiras
            return 'JSON_OBJECT(' . implode(', ', $pares) . ')';
        };
        $upd = [];
        foreach ($colunas as $c) {
            $upd[] = 'IF NOT (OLD.' . $q($c) . ' <=> NEW.' . $q($c) . ') THEN '
                . $ins('ALTERAR', 'NEW.' . $q($chave), $lit($c), 'CAST(OLD.' . $q($c) . ' AS CHAR)', 'CAST(NEW.' . $q($c) . ' AS CHAR)') . ' END IF;';
        }
        return [
            "trg_hist_{$nome}_ai" => "CREATE TRIGGER `trg_hist_{$nome}_ai` AFTER INSERT ON $t FOR EACH ROW BEGIN "
                . $ins('INCLUIR', 'NEW.' . $q($chave), 'NULL', 'NULL', 'CAST(' . $json('NEW') . ' AS CHAR)') . ' END',
            "trg_hist_{$nome}_au" => "CREATE TRIGGER `trg_hist_{$nome}_au` AFTER UPDATE ON $t FOR EACH ROW BEGIN "
                . implode(' ', $upd) . ' END',
            "trg_hist_{$nome}_ad" => "CREATE TRIGGER `trg_hist_{$nome}_ad` AFTER DELETE ON $t FOR EACH ROW BEGIN "
                . $ins('EXCLUIR', 'OLD.' . $q($chave), 'NULL', 'CAST(' . $json('OLD') . ' AS CHAR)', 'NULL') . ' END',
        ];
    }

    /** Rótulo da origem gravada. */
    public static function origem(?string $origem, callable $t): string
    {
        $o = trim((string)$origem);
        if ($o === '') return $t('origem.fora', ['pt' => 'Fora do portal (sincronismo com o sistema antigo ou rotina do banco)', 'en' => 'Outside the portal (legacy sync or database routine)', 'es' => 'Fuera del portal (sincronismo con el sistema anterior o rutina de la base)']);
        if (str_starts_with($o, 'cli:')) {
            $s = substr($o, 4);
            if (stripos($s, 'sincron') !== false) return $t('origem.sincronismo', ['pt' => 'Sincronismo (sistema antigo)', 'en' => 'Sync (legacy system)', 'es' => 'Sincronismo (sistema anterior)']) . ' — ' . $s;
            if (stripos($s, 'ia') !== false || stripos($s, 'worker') !== false) return $t('origem.rotina_ia', ['pt' => 'Rotina automática / IA', 'en' => 'Automatic routine / AI', 'es' => 'Rutina automática / IA']) . ' — ' . $s;
            return $t('origem.rotina', ['pt' => 'Rotina automática', 'en' => 'Automatic routine', 'es' => 'Rutina automática']) . ' — ' . $s;
        }
        if (str_starts_with($o, 'tela:')) return $t('origem.tela', ['pt' => 'Portal', 'en' => 'Portal', 'es' => 'Portal']) . ' — ' . substr($o, 5);
        return $o;
    }
}
