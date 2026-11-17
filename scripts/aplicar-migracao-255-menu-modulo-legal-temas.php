<?php
declare(strict_types=1);

/*
 * Migração 255 — Módulo Legal organizado por temas no menu lateral (subgrupos recolhíveis):
 *   Requisitos | Licenças | Levantamentos (Ambiental, Ocupacional, Social) | Controles e evidências | Meu espaço.
 * Só muda a navegação (pai e ordem dos menus); permissões não mudam. Os grupos são visíveis para quem tem acesso a
 * alguma tela deles (MenuService mostra o pai de toda tela permitida).
 *
 * Também:
 *  - correlacionamentos vão para dentro do levantamento do mesmo tema;
 *  - Requisitos legais / Controles e documentos / Objetivos e metas do LAIS saem do menu (ativo = 0 no catálogo
 *    universal), como já é no LAIA: abrem pelos botões da tela LAIS; o acesso por permissão é preservado;
 *  - Perigos e Riscos saem de Manutenção Legal (ficam só em Levantamento Ocupacional);
 *  - ordens renumeradas de 10 em 10.
 * Universal (guniversal_dbp.menus): grupos, pai, ordem e ativo. Clientes (menus de cada banco, usados em Segurança ›
 * Permissões): grupos, pai e ordem (ativo não muda). Cópia: bkp255_menus em cada banco, uma única vez.
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-255-menu-modulo-legal-temas.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-255-menu-modulo-legal-temas.php
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

const M255_CLIENTES = ['gatlas_dbp', 'gmodelo_dbp', 'gmodelo2_dbp'];
// Grupos novos: código => [pt, en, es, ícone, ordem]
const M255_GRUPOS = [
    'ML_GRUPO_REQUISITOS' => ['Requisitos', 'Requirements', 'Requisitos', 'bi-journal-check', 10],
    'ML_GRUPO_LICENCAS' => ['Licenças', 'Licenses', 'Licencias', 'bi-file-earmark-check', 20],
    'ML_GRUPO_LEVANTAMENTOS' => ['Levantamentos', 'Surveys', 'Levantamientos', 'bi-clipboard-data', 30],
    'ML_GRUPO_CONTROLES' => ['Controles e evidências', 'Controls and evidence', 'Controles y evidencias', 'bi-shield-check', 40],
    'ML_GRUPO_MEU_ESPACO' => ['Meu espaço', 'My space', 'Mi espacio', 'bi-person-workspace', 50],
];
// Posições: código => [código do pai, ordem]
const M255_POSICOES = [
    'REQUISITOS_LEGAIS' => ['ML_GRUPO_REQUISITOS', 10], 'SITUACAO_CUMPRIMENTO' => ['ML_GRUPO_REQUISITOS', 20],
    'DOCUMENTOS_COMPARTILHADOS' => ['ML_GRUPO_REQUISITOS', 40],
    'LICENCAS' => ['ML_GRUPO_LICENCAS', 10], 'TIPOS_DOCUMENTOS_LICENCAS' => ['ML_GRUPO_LICENCAS', 20],
    'LEVANTAMENTO_AMBIENTAL' => ['ML_GRUPO_LEVANTAMENTOS', 10], 'LEVANTAMENTO_OCUPACIONAL' => ['ML_GRUPO_LEVANTAMENTOS', 20],
    'LEVANTAMENTO_SOCIAL' => ['ML_GRUPO_LEVANTAMENTOS', 30],
    'LAIA' => ['LEVANTAMENTO_AMBIENTAL', 10], 'LAIA_ATIVIDADES' => ['LEVANTAMENTO_AMBIENTAL', 20], 'ASPECTOS' => ['LEVANTAMENTO_AMBIENTAL', 30],
    'LAIA_DESTINOS' => ['LEVANTAMENTO_AMBIENTAL', 40], 'IMPACTOS' => ['LEVANTAMENTO_AMBIENTAL', 50], 'LAIA_REVISAO' => ['LEVANTAMENTO_AMBIENTAL', 60],
    'CORRELACIONAMENTO_AMBIENTAL' => ['LEVANTAMENTO_AMBIENTAL', 70],
    'LPRO' => ['LEVANTAMENTO_OCUPACIONAL', 10], 'LPRO_ATIVIDADES' => ['LEVANTAMENTO_OCUPACIONAL', 20], 'LPRO_PERIGOS' => ['LEVANTAMENTO_OCUPACIONAL', 30],
    'LPRO_RISCOS' => ['LEVANTAMENTO_OCUPACIONAL', 40], 'LPRO_REVISAO' => ['LEVANTAMENTO_OCUPACIONAL', 50],
    'CORRELACIONAMENTO_OCUPACIONAL' => ['LEVANTAMENTO_OCUPACIONAL', 60],
    'LAIS' => ['LEVANTAMENTO_SOCIAL', 10], 'LAIS_ATIVIDADES' => ['LEVANTAMENTO_SOCIAL', 20], 'LAIS_PARTES_INTERESSADAS' => ['LEVANTAMENTO_SOCIAL', 30],
    'LAIS_ASPECTOS_SOCIAIS' => ['LEVANTAMENTO_SOCIAL', 40], 'LAIS_IMPACTOS_SOCIAIS' => ['LEVANTAMENTO_SOCIAL', 50], 'LAIS_REVISOES' => ['LEVANTAMENTO_SOCIAL', 60],
    'CORRELACIONAMENTO_SOCIAL' => ['LEVANTAMENTO_SOCIAL', 70],
    // Telas que abrem pelos botões do LAIA/LAIS (fora do menu): ficam dentro do levantamento, ao fim
    'LAIA_REQUISITOS_LEGAIS' => ['LEVANTAMENTO_AMBIENTAL', 910], 'LAIA_CONTROLES_DOCUMENTOS' => ['LEVANTAMENTO_AMBIENTAL', 920],
    'LAIA_OBJETIVOS_METAS' => ['LEVANTAMENTO_AMBIENTAL', 930],
    'LAIS_REQUISITOS_LEGAIS' => ['LEVANTAMENTO_SOCIAL', 910], 'LAIS_CONTROLES_DOCUMENTOS' => ['LEVANTAMENTO_SOCIAL', 920],
    'LAIS_OBJETIVOS_METAS' => ['LEVANTAMENTO_SOCIAL', 930],
    'CONTROLE_DOCUMENTOS' => ['ML_GRUPO_CONTROLES', 10], 'MODULOLEGAL_TREINAMENTOS' => ['ML_GRUPO_CONTROLES', 20],
    'MODULOLEGAL_INFRAESTRUTURA' => ['ML_GRUPO_CONTROLES', 30], 'MEDICAO_MONITORAMENTO' => ['ML_GRUPO_CONTROLES', 40],
    'MODULOLEGAL_POTENCIAIS' => ['ML_GRUPO_CONTROLES', 900],
    'MM_EQUIPAMENTOS' => ['MEDICAO_MONITORAMENTO', 10], 'MM_MEDICOES' => ['MEDICAO_MONITORAMENTO', 20],
    'MM_CONTROLE_CALIBRACAO' => ['MEDICAO_MONITORAMENTO', 30], 'MM_PLANO_MEDICAO_MONITORAMENTO' => ['MEDICAO_MONITORAMENTO', 40],
    'MINHAS_LICENCAS' => ['ML_GRUPO_MEU_ESPACO', 10], 'MEUS_TREINAMENTOS' => ['ML_GRUPO_MEU_ESPACO', 20],
    'MINHAS_INFRAESTRUTURAS' => ['ML_GRUPO_MEU_ESPACO', 30], 'MEUS_CONTROLES' => ['ML_GRUPO_MEU_ESPACO', 40],
    // Manutenção Legal: cadastros da legislação
    'LEGISLACAO' => ['MANUTENCAO_LEGAL', 10], 'LEGISLACAO_X_EMPRESAS' => ['MANUTENCAO_LEGAL', 20], 'LEGISLACAO_X_PODER' => ['MANUTENCAO_LEGAL', 30],
    'TIPOS' => ['MANUTENCAO_LEGAL', 40], 'AMBITOS' => ['MANUTENCAO_LEGAL', 50], 'ASSUNTOS' => ['MANUTENCAO_LEGAL', 60],
    'PERIGOS' => ['MANUTENCAO_LEGAL', 910], 'RISCOS' => ['MANUTENCAO_LEGAL', 920],
];
// Fora do menu lateral (só no catálogo universal; o acesso pelas telas e as permissões continuam)
const M255_OCULTAR = ['LAIS_REQUISITOS_LEGAIS', 'LAIS_CONTROLES_DOCUMENTOS', 'LAIS_OBJETIVOS_METAS', 'PERIGOS', 'RISCOS'];

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$universal = GreenPortalDatabase::connect($env, $bancoUniversal, 'GUNIVERSAL');
$universal->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$valor = static function (PDO $pdo, string $sql, array $p = []): mixed { $s = $pdo->prepare($sql); $s->execute($p); $v = $s->fetchColumn(); $s->closeCursor(); return $v; };
$colunas = static fn(PDO $pdo): array => array_column($pdo->query('SHOW COLUMNS FROM menus')->fetchAll(PDO::FETCH_ASSOC), 'Field');

$relatorio = ['ok' => true, 'migracao' => '255', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'universal' => [], 'clientes' => []];

// Códigos existentes
$porCodigo = [];
foreach ($universal->query('SELECT codmenu, codmenu_pai, codigo, ordem, ativo FROM menus')->fetchAll(PDO::FETCH_ASSOC) as $m) $porCodigo[strtoupper((string)$m['codigo'])] = $m;
$ml = $porCodigo['MODULO_LEGAL'] ?? null;
if (!$ml) { echo json_encode(['ok' => false, 'erro' => 'menu MODULO_LEGAL não encontrado'], JSON_UNESCAPED_UNICODE) . PHP_EOL; exit(1); }
$faltando = array_values(array_diff(array_keys(M255_POSICOES), array_keys($porCodigo), array_keys(M255_GRUPOS)));
$relatorio['universal']['codigos_inexistentes_ignorados'] = $faltando;

// Clientes conectados (para reservar codmenu livre em todos)
$clientes = [];
foreach (M255_CLIENTES as $b) {
    try { $p = GreenPortalDatabase::connect($env, $b, 'GPT'); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $clientes[$b] = $p; }
    catch (Throwable $e) { if ($b !== 'gmodelo2_dbp') { $relatorio['ok'] = false; $relatorio['clientes'][$b] = ['erro' => $e->getMessage()]; } }
}

// Códigos dos grupos (codmenu)
$proximo = (int)$valor($universal, 'SELECT COALESCE(MAX(codmenu), 0) FROM menus');
foreach ($clientes as $p) $proximo = max($proximo, (int)$valor($p, 'SELECT COALESCE(MAX(codmenu), 0) FROM menus'));
$grupos = [];
foreach (M255_GRUPOS as $cod => [$pt, $en, $es, $icone, $ordem]) {
    $codmenu = isset($porCodigo[$cod]) ? (int)$porCodigo[$cod]['codmenu'] : ++$proximo;
    $grupos[$cod] = ['codmenu' => $codmenu, 'codmenu_pai' => (int)$ml['codmenu'], 'modulo' => 'MODULO_LEGAL', 'codigo' => $cod, 'label' => $pt, 'label_pt' => $pt,
        'label_en' => $en, 'label_es' => $es, 'url' => null, 'nivel' => 2, 'ordem' => $ordem, 'icone' => $icone, 'ativo' => 1];
    $porCodigo[$cod] = ['codmenu' => $codmenu];
}
$relatorio['universal']['grupos'] = array_map(static fn($g) => $g['codmenu'] . ' ' . $g['label_pt'], array_values($grupos));

$aplicarEm = static function (PDO $pdo, string $banco, bool $universalDb) use ($grupos, $porCodigo, $colunas, $valor, $simular): array {
    $r = ['movidos' => 0, 'ocultados' => 0];
    $cols = $colunas($pdo);
    if ($simular) {
        $r['simulacao'] = 'nada gravado';
        return $r;
    }
    if (!(bool)$valor($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bkp255_menus'")) {
        $pdo->exec('CREATE TABLE bkp255_menus AS SELECT * FROM menus');
        $r['copia'] = 'bkp255_menus criada';
    } else $r['copia'] = 'bkp255_menus já existia (mantida)';
    $pdo->beginTransaction();
    try {
        foreach ($grupos as $g) {
            $outro = $valor($pdo, 'SELECT codigo FROM menus WHERE codmenu = ?', [$g['codmenu']]);
            if ($outro !== false && $outro !== null && strtoupper((string)$outro) !== $g['codigo']) throw new RuntimeException("codmenu {$g['codmenu']} já usado por $outro em $banco");
            $paiLocal = $universalDb ? $g['codmenu_pai'] : ((int)$valor($pdo, "SELECT codmenu FROM menus WHERE codigo = 'MODULO_LEGAL' LIMIT 1") ?: $g['codmenu_pai']);
            $dados = array_intersect_key(['codmenu_pai' => $paiLocal] + $g, array_flip($cols));
            $campos = array_keys($dados);
            $upd = implode(', ', array_map(static fn($c) => "`$c` = VALUES(`$c`)", array_diff($campos, ['codmenu'])));
            $pdo->prepare('INSERT INTO menus (`' . implode('`, `', $campos) . '`) VALUES (' . implode(', ', array_fill(0, count($campos), '?')) . ") ON DUPLICATE KEY UPDATE $upd")->execute(array_values($dados));
        }
        $mov = $pdo->prepare('UPDATE menus SET codmenu_pai = (SELECT codmenu FROM (SELECT codmenu, codigo FROM menus) x WHERE x.codigo = ? LIMIT 1), ordem = ? WHERE codigo = ?');
        foreach (M255_POSICOES as $cod => [$pai, $ordem]) {
            if (!isset($porCodigo[$pai])) continue;
            $mov->execute([$pai, $ordem, $cod]);
            $r['movidos'] += $mov->rowCount();
        }
        if ($universalDb) {
            $oc = $pdo->prepare('UPDATE menus SET ativo = 0 WHERE codigo = ? AND ativo <> 0');
            foreach (M255_OCULTAR as $cod) { $oc->execute([$cod]); $r['ocultados'] += $oc->rowCount(); }
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return $r;
};

try { $relatorio['universal'] += $aplicarEm($universal, $bancoUniversal, true); }
catch (Throwable $e) { $relatorio['ok'] = false; $relatorio['universal']['erro'] = $e->getMessage(); }
foreach ($clientes as $b => $p) {
    try { $relatorio['clientes'][$b] = $aplicarEm($p, $b, false); }
    catch (Throwable $e) { $relatorio['ok'] = false; $relatorio['clientes'][$b] = ['erro' => $e->getMessage()]; }
}

// Árvore resultante do Módulo Legal (universal, só ativos)
$todos = $universal->query('SELECT codmenu, codmenu_pai, codigo, COALESCE(label_pt, label) AS rotulo, ordem, ativo FROM menus ORDER BY ordem, codmenu')->fetchAll(PDO::FETCH_ASSOC);
$filhos = [];
foreach ($todos as $m) $filhos[(int)$m['codmenu_pai']][] = $m;
$linhas = [];
$desenhar = static function (int $pai, int $nivel) use (&$desenhar, $filhos, &$linhas): void {
    foreach ($filhos[$pai] ?? [] as $m) {
        if (!(int)$m['ativo']) continue;
        $linhas[] = str_repeat('  ', $nivel) . $m['rotulo'] . ' (' . $m['codigo'] . ', ' . $m['ordem'] . ')';
        $desenhar((int)$m['codmenu'], $nivel + 1);
    }
};
foreach (['MANUTENCAO_LEGAL', 'MODULO_LEGAL'] as $raiz) {
    foreach ($todos as $m) if (strtoupper((string)$m['codigo']) === $raiz) { $linhas[] = $m['rotulo']; $desenhar((int)$m['codmenu'], 1); }
}
$relatorio['arvore_' . ($simular ? 'atual' : 'nova')] = $linhas;

$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents(dirname(__DIR__) . '/storage/logs/migracao_255_menu_modulo_legal' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
