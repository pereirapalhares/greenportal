<?php
declare(strict_types=1);

/**
 * Requisitos legais — nível de atendimento (%) e risco (migração 341, 23/10/2026; docs/Detalhamento Cálculo do Nível do
 * Atendimento do Requisito Legal.doc). Regras, critérios e rótulos usados pela migração, pelos cadastros dos pilares
 * (ManutencaoLegalCadastroGrid, opção 'risk_area'), pela tela de situação do requisito e pelos painéis.
 *
 * Risco do item = Severidade (1–4, critério do pilar) × Probabilidade (1–4, critério comum):
 *   1–3 Baixo · 4–6 Moderado · 8–9 Alto · 12–16 ou Severidade 4 = Crítico.
 * Requisito: % de atendimento = itens aplicáveis atendidos (Ok ou Atenção) ÷ itens aplicáveis ("não se aplica" fica de fora);
 * risco do requisito = pior risco entre os itens aplicáveis; pendência crítica = item aplicável não atendido (Falha ou Vencido)
 * com risco Crítico; item aplicável sem severidade/probabilidade = "risco não avaliado".
 */
final class RequisitoRisco
{
    /** Áreas (pilares) => [rótulo, rótulo plural, tabela do item, chave]. */
    public const AREAS = [
        'LICENCAS' => ['Licenças e autorizações', 'Licença', 'li_licencas', 'CODLICENCA'],
        'MEDICAO' => ['Medição e monitoramento', 'Medição e monitoramento', 'mm_monitoramento', 'CODMONITORAMENTO'],
        'TREINAMENTOS' => ['Treinamento e capacitação', 'Treinamento', 'ml_treinamentos', 'CODTREINAMENTO'],
        'INFRAESTRUTURA' => ['Infraestrutura', 'Infraestrutura', 'ml_infraestrutura', 'CODINFRAESTRUTURA'],
        'CONTROLES' => ['Controles operacionais e administrativos', 'Controle', 'documentos', 'CODDOCUMENTO'],
        'HOMOLOGACAO' => ['Fornecedores (homologação)', 'Fornecedor', 'fo_fornecedores', 'CODFORNECEDOR'],
    ];

    public const ROTULOS_SEVERIDADE = [1 => 'Baixa', 2 => 'Moderada', 3 => 'Alta', 4 => 'Crítica'];
    public const ROTULOS_PROBABILIDADE = [1 => 'Improvável', 2 => 'Possível', 3 => 'Provável', 4 => 'Quase certa'];

    /** Critérios de severidade por área e de probabilidade (texto do documento; fornecedores: tabela própria, a validar). */
    public const CRITERIOS = [
        'LICENCAS' => [
            1 => 'Irregularidade documental de efeito mínimo, sem comprometimento da operação e com possibilidade de regularização simples.',
            2 => 'Possibilidade de advertência, exigência administrativa ou penalidade de pequeno impacto, sem interrupção das atividades.',
            3 => 'Possibilidade de multa relevante, restrição pontual da atividade ou necessidade de regularização com custos e esforços significativos.',
            4 => 'Possibilidade de interdição total, perda de autorização indispensável à atividade ou comprometimento da continuidade do negócio, prejuízos severos para a imagem e reputação da empresa.',
        ],
        'MEDICAO' => [
            1 => 'Falha pontual sem comprometimento relevante das condições monitoradas.',
            2 => 'Falha que resulta em condições inadequadas, com consequências relevantes, mas controláveis das condições monitoradas.',
            3 => 'Falha que resulta em condições inadequadas, com consequências graves e fora de controle das condições monitoradas.',
            4 => 'Falha capaz de resultar em acidentes fatais, danos ambientais irreversíveis ou paralisação total das atividades das condições monitoradas, prejuízos severos para a imagem e reputação da empresa.',
        ],
        'TREINAMENTOS' => [
            1 => 'Deficiência de conhecimento em atividade sem riscos relevantes, com consequências facilmente corrigíveis.',
            2 => 'Falha de capacitação que pode causar erros operacionais simples, pequenos prejuízos ou incidentes leves.',
            3 => 'Falha de capacitação capaz de provocar erros operacionais significativos, acidentes com afastamento ou danos materiais moderados.',
            4 => 'Falha de capacitação capaz de resultar em uma ou mais fatalidades, incapacidades permanentes, acidentes de grandes proporções ou consequências irreversíveis.',
        ],
        'INFRAESTRUTURA' => [
            1 => 'Deficiência da infraestrutura sem consequências relevantes para pessoas, meio ambiente ou operação.',
            2 => 'Deficiência da infraestrutura que pode provocar pequenos incidentes, danos materiais limitados, mas sem interrupções das atividades.',
            3 => 'Falha da infraestrutura capaz de causar acidentes de gravidade intermediária, danos patrimoniais relevantes ou paralisações localizadas.',
            4 => 'Falha da infraestrutura capaz de provocar fatalidades, colapso de instalações, acidentes catastróficos ou paralisação integral e prolongada da operação, prejuízos severos para a imagem e reputação da empresa.',
        ],
        'CONTROLES' => [
            1 => 'Falha de controle sem consequências relevantes e de correção simples.',
            2 => 'Falha de controle que pode provocar pequenos desvios, incidentes leves ou prejuízos limitados.',
            3 => 'Falha de controle capaz de resultar em desvios relevantes, acidentes de gravidade intermediária ou perdas operacionais significativas.',
            4 => 'Falha nos controles capaz de resultar em acidentes graves ou fatais, desastres ambientais ou comprometimento da continuidade da operação, prejuízos severos para a imagem e reputação da empresa.',
        ],
        'HOMOLOGACAO' => [
            1 => 'Falha documental ou cadastral do fornecedor sem impacto na operação, regularizável de forma simples.',
            2 => 'Falha do fornecedor que pode gerar exigência administrativa, atraso ou pequeno prejuízo, sem interrupção das atividades.',
            3 => 'Falha do fornecedor capaz de gerar multa relevante ou corresponsabilidade da empresa (ex.: transporte ou destinação de resíduos), acidente com afastamento ou interrupção localizada.',
            4 => 'Falha do fornecedor capaz de resultar em acidente grave ou fatal, dano ambiental relevante com corresponsabilidade da empresa, interdição ou prejuízos severos para a imagem e reputação da empresa.',
        ],
        'PROBABILIDADE' => [
            1 => 'Pouco provável de ocorrer, mas plausível. Existem controles adequados e pequenas possibilidades de falha.',
            2 => 'Consequência que pode ocorrer nas condições normais ou anormais previsíveis. Há exposição relevante, histórico de desvios ou limitações nos controles.',
            3 => 'Consequência com elevada possibilidade de ocorrência. Existem falhas recorrentes, exposição frequente ou controles claramente insuficientes.',
            4 => 'Consequência iminente ou esperada se a situação permanecer inalterada. Há exposição contínua e ausência de controles eficazes, ou o evento já ocorre repetidamente.',
        ],
    ];

    /** Classe => [rótulo, cor Bootstrap, tratamento]. */
    public const CLASSES = [
        'BAIXO' => ['Baixo', 'success', 'Manter controles e acompanhamento de rotina.'],
        'MODERADO' => ['Moderado', 'warning', 'Planejar melhorias e ações preventivas.'],
        'ALTO' => ['Alto', 'orange', 'Priorizar ações corretivas e reforçar controles.'],
        'CRITICO' => ['Crítico', 'danger', 'Adotar medidas imediatas de redução do risco.'],
        'NAO_AVALIADO' => ['Não avaliado', 'secondary', 'Informe a severidade e a probabilidade nos cadastros dos itens.'],
    ];

    /** Situação do item no cálculo (o código CRITICO da 284 é mostrado como "Vencido"). */
    public const STATUS = [
        'OK' => ['Ok', 'success', true], 'ATENCAO' => ['Atenção', 'warning', true],
        'FALHA' => ['Falha', 'danger', false], 'CRITICO' => ['Vencido', 'danger', false],
    ];

    public static function pontos(int $severidade, int $probabilidade): int
    {
        return $severidade * $probabilidade;
    }

    /** Classe do risco: Crítico com Severidade 4 ou pontuação 12–16; Alto 8–9; Moderado 4–6; Baixo 1–3. */
    public static function classe(int $severidade, int $probabilidade): string
    {
        $p = $severidade * $probabilidade;
        return $severidade === 4 || $p >= 12 ? 'CRITICO' : ($p >= 8 ? 'ALTO' : ($p >= 4 ? 'MODERADO' : 'BAIXO'));
    }

    public static function rotuloClasse(?string $classe): string
    {
        return self::CLASSES[(string)$classe][0] ?? '';
    }

    /** Selo HTML da classe (laranja do Alto com estilo próprio, o Bootstrap não tem). */
    public static function selo(?string $classe, ?int $pontos = null): string
    {
        if ($classe === null || $classe === '') return '<span class="badge text-bg-light border">' . htmlspecialchars(self::CLASSES['NAO_AVALIADO'][0], ENT_QUOTES, 'UTF-8') . '</span>';
        [$rot, $cor] = self::CLASSES[$classe] ?? [$classe, 'secondary'];
        $txt = htmlspecialchars($rot . ($pontos ? ' (' . $pontos . ')' : ''), ENT_QUOTES, 'UTF-8');
        return $cor === 'orange' ? '<span class="badge" style="background:#fd7e14;color:#fff">' . $txt . '</span>' : '<span class="badge text-bg-' . $cor . '">' . $txt . '</span>';
    }

    /** Opções de severidade da área para listas ("3 - Alta"). */
    public static function opcoesSeveridade(): array
    {
        $o = [];
        foreach (self::ROTULOS_SEVERIDADE as $n => $r) $o[(string)$n] = ['pt' => $n . ' - ' . $r, 'en' => $n . ' - ' . ['Low', 'Moderate', 'High', 'Critical'][$n - 1], 'es' => $n . ' - ' . ['Baja', 'Moderada', 'Alta', 'Crítica'][$n - 1]];
        return $o;
    }

    public static function opcoesProbabilidade(): array
    {
        $o = [];
        foreach (self::ROTULOS_PROBABILIDADE as $n => $r) $o[(string)$n] = ['pt' => $n . ' - ' . $r, 'en' => $n . ' - ' . ['Unlikely', 'Possible', 'Likely', 'Almost certain'][$n - 1], 'es' => $n . ' - ' . ['Improbable', 'Posible', 'Probable', 'Casi segura'][$n - 1]];
        return $o;
    }

    /** Texto de dica com os critérios (title das colunas Severidade e Probabilidade). */
    public static function dica(string $area): string
    {
        $l = [];
        if ($area === 'PROBABILIDADE') foreach (self::CRITERIOS['PROBABILIDADE'] as $n => $c) $l[] = $n . ' ' . self::ROTULOS_PROBABILIDADE[$n] . ': ' . $c;
        else foreach (self::CRITERIOS[$area] ?? [] as $n => $c) $l[] = $n . ' ' . self::ROTULOS_SEVERIDADE[$n] . ': ' . $c;
        return implode("\n", $l);
    }

    /** O banco tem a migração 341 (tabela de risco dos itens)? */
    public static function disponivel(PDO $pdo): bool
    {
        static $cache = [];
        $k = spl_object_id($pdo);
        if (!isset($cache[$k])) {
            try { $pdo->query('SELECT 1 FROM vw_ml_risco_itens LIMIT 1')->fetchAll(); $cache[$k] = true; }
            catch (Throwable) { $cache[$k] = false; }
        }
        return $cache[$k];
    }

    /** Riscos gravados dos itens da área: [ITEM_ID (como gravado) => linha]. Licença: pela licença original (CHAVE). */
    public static function riscos(PDO $pdo, string $area, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        if (!$ids || !self::disponivel($pdo)) return [];
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        if ($area === 'LICENCAS') {
            $s = $pdo->prepare("SELECT k.CODLICENCA AS ID, r.* FROM vw_ml_licenca_chave k JOIN vw_ml_risco_itens r ON r.AREA = 'LICENCAS' AND r.ITEM_ID = k.CHAVE WHERE k.CODLICENCA IN ($marcas)");
        } else {
            $s = $pdo->prepare("SELECT r.ITEM_ID AS ID, r.* FROM vw_ml_risco_itens r WHERE r.AREA = ? AND r.ITEM_ID IN ($marcas)");
            array_unshift($ids, $area);
        }
        $s->execute($ids);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['ID']] = $r;
        $s->closeCursor();
        return $out;
    }

    /** Grava o risco do item (sp_ml_risco_salvar). */
    public static function salvar(PDO $pdo, string $area, int $item, int $severidade, int $probabilidade, int $usuario, ?string $justificativa = null): array
    {
        $s = $pdo->prepare('CALL sp_ml_risco_salvar(?, ?, ?, ?, ?, ?)');
        $s->execute([$area, $item, $severidade, $probabilidade, $justificativa, $usuario]);
        $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        while ($s->nextRowset()) {}
        $s->closeCursor();
        return $r;
    }
}
