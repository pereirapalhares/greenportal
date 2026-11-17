<?php
declare(strict_types=1);

/*
 * Migração 299 — Homologação de Fornecedores: organização dos itens de menu (aprovada pelo usuário em 17/10/2026).
 *
 *   Homologação de Fornecedores
 *     Painel de Homologação
 *     Gestão de Fornecedores
 *     Fornecedores desativados
 *     Gestão de Funcionários
 *     Portaria ▸ Documentação de fornecedor · Documentação de funcionário
 *     Relatórios ▸ Ranking de fornecedores · Histórico de alterações de documentos            (grupo novo)
 *     Cadastros ▸ Tipos de fornecedores · Critérios dos fornecedores · Tipos de funcionários ·
 *                 Critérios dos funcionários · Tipos de treinamento · Pesos do ranking         (grupo novo)
 *
 * Só muda pai, ordem, nível, nomes (PT/EN/ES) e ícones. As permissões são pelo código do menu e não mudam; os grupos
 * novos não têm tela nem permissão própria (aparecem quando o usuário vê algum item dentro deles).
 * "Pesos do ranking" (ADMINISTRACAO_PESOS) sai da Administração e passa ao módulo Homologação de Fornecedores.
 * Grava no catálogo universal e em todo banco de cliente que tem o menu Homologação de Fornecedores.
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-299-menu-homologacao.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-299-menu-homologacao.php
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

const M299_RAIZ = 'HOMOLOGACAO_FORNECEDORES';
// código => [pai, ordem, nível, pt, en, es, ícone (null = mantém), novo grupo?]
const M299_MENUS = [
    'HOMOLOGACAO_PAINEL' => [M299_RAIZ, 10, 2, 'Painel de Homologação', 'Supplier Approval Dashboard', 'Panel de Homologación', 'bi-speedometer2'],
    'HOMOLOGACAO_FORNECEDOR_FORNECEDOR' => [M299_RAIZ, 20, 2, 'Gestão de Fornecedores', 'Supplier Management', 'Gestión de Proveedores', 'bi-building-check'],
    'HOMOLOGACAO_FORNECEDORES_DESATIVADOS' => [M299_RAIZ, 30, 2, 'Fornecedores desativados', 'Deactivated suppliers', 'Proveedores desactivados', 'bi-building-x'],
    'HOMOLOGACAO_FORNECEDOR_FUNCIONARIO' => [M299_RAIZ, 40, 2, 'Gestão de Funcionários', 'Employee Management', 'Gestión de Empleados', 'bi-person-vcard'],
    'PORTARIA' => [M299_RAIZ, 50, 2, 'Portaria', 'Gatehouse', 'Portería', 'bi-door-open'],
    'PORTARIA_DOCUMENTACAO_FORNECEDOR' => ['PORTARIA', 10, 3, 'Documentação de fornecedor', 'Supplier documentation', 'Documentación de proveedor', 'bi-file-earmark-person'],
    'PORTARIA_DOCUMENTACAO_FUNCIONARIO' => ['PORTARIA', 20, 3, 'Documentação de funcionário', 'Employee documentation', 'Documentación de empleado', 'bi-file-earmark-text'],
    'HOMOLOGACAO_RELATORIOS' => [M299_RAIZ, 60, 2, 'Relatórios', 'Reports', 'Informes', 'bi-bar-chart-line', true],
    'HOMOLOGACAO_FORNECEDOR_RANKING' => ['HOMOLOGACAO_RELATORIOS', 10, 3, 'Ranking de fornecedores', 'Supplier ranking', 'Ranking de proveedores', 'bi-trophy'],
    'HOMOLOGACAO_HISTORICO_DOCUMENTOS' => ['HOMOLOGACAO_RELATORIOS', 20, 3, 'Histórico de alterações de documentos', 'Document change history', 'Historial de cambios de documentos', 'bi-clock-history'],
    'HOMOLOGACAO_CADASTROS' => [M299_RAIZ, 70, 2, 'Cadastros', 'Registers', 'Registros', 'bi-journal-bookmark', true],
    'HOMOLOGACAO_TIPOS_FORNECEDORES' => ['HOMOLOGACAO_CADASTROS', 10, 3, 'Tipos de fornecedores', 'Supplier types', 'Tipos de proveedores', 'bi-diagram-3'],
    'HOMOLOGACAO_CRITERIOS_FORNECEDORES' => ['HOMOLOGACAO_CADASTROS', 20, 3, 'Critérios dos fornecedores', 'Supplier criteria', 'Criterios de los proveedores', 'bi-file-earmark-check'],
    'HOMOLOGACAO_TIPOS_FUNCIONARIOS' => ['HOMOLOGACAO_CADASTROS', 30, 3, 'Tipos de funcionários', 'Employee types', 'Tipos de empleados', 'bi-person-badge'],
    'HOMOLOGACAO_CRITERIOS_FUNCIONARIOS' => ['HOMOLOGACAO_CADASTROS', 40, 3, 'Critérios dos funcionários', 'Employee criteria', 'Criterios de los empleados', 'bi-card-checklist'],
    'HOMOLOGACAO_TIPOS_TREINAMENTOS' => ['HOMOLOGACAO_CADASTROS', 50, 3, 'Tipos de treinamento', 'Training types', 'Tipos de capacitación', 'bi-mortarboard'],
    'ADMINISTRACAO_PESOS' => ['HOMOLOGACAO_CADASTROS', 60, 3, 'Pesos do ranking', 'Ranking weights', 'Pesos del ranking', 'bi-sliders'],
];

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$geral->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$linhas = static function (PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$valor = static function (PDO $pdo, string $sql, array $p = []) use ($linhas): mixed { $r = $linhas($pdo, $sql, $p); return $r ? array_values($r[0])[0] : null; };
$colunas = static function (string $b, string $t) use ($linhas, $geral): array {
    $out = [];
    foreach ($linhas($geral, 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$b, $t]) as $c) $out[strtolower($c['COLUMN_NAME'])] = true;
    return $out;
};

// Bancos: universal + clientes que têm o menu raiz do módulo.
$bancos = [$bancoUniversal => GreenPortalDatabase::connect($env, $bancoUniversal, 'GUNIVERSAL')];
foreach ($linhas($geral, "SELECT TABLE_SCHEMA AS b FROM information_schema.TABLES WHERE TABLE_NAME = 'menus' AND TABLE_SCHEMA LIKE 'g%\\_dbp' AND TABLE_SCHEMA NOT IN (?, 'glegislacao_dbp') ORDER BY 1", [$bancoUniversal]) as $l) {
    $pdo = GreenPortalDatabase::connect($env, (string)$l['b'], 'GPT');
    if ((int)$valor($pdo, 'SELECT COUNT(*) FROM menus WHERE codigo = ?', [M299_RAIZ]) > 0) $bancos[(string)$l['b']] = $pdo;
}
foreach ($bancos as $pdo) { $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->exec('SET NAMES utf8mb4'); }

$relatorio = ['ok' => true, 'migracao' => '299', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];

// Código único para cada grupo novo (o mesmo em todos os bancos, como na 298).
$novos = [];
foreach (M299_MENUS as $codigo => $m) {
    if (empty($m[7])) continue;
    $existente = (int)$valor($bancos[$bancoUniversal], 'SELECT codmenu FROM menus WHERE codigo = ?', [$codigo]);
    if ($existente < 1) {
        $existente = 0;
        foreach ($bancos as $pdo) $existente = max($existente, (int)$valor($pdo, 'SELECT COALESCE(MAX(codmenu), 0) FROM menus'), ...array_values($novos));
        $existente++;
    }
    $novos[$codigo] = $existente;
}
$relatorio['grupos_novos'] = $novos;

// Grava só no universal e nos bancos da homologação (classes/HomologacaoBancos.php; --banco= para só alguns).
// Os códigos dos grupos novos acima continuam calculados sobre todos os bancos, para não colidir quando outra empresa receber o módulo.
$alvos = HomologacaoBancos::lista($argv ?? []);
foreach ($bancos as $banco => $pdo) {
    if ($banco !== $bancoUniversal && !in_array($banco, $alvos, true)) continue;
    $info = ['antes' => [], 'depois' => []];
    try {
        $cols = $colunas($banco, 'menus');
        foreach ($linhas($pdo, 'SELECT m.codigo, p.codigo AS pai, m.ordem, m.label_pt FROM menus m LEFT JOIN menus p ON p.codmenu = m.codmenu_pai WHERE m.codigo IN (' . implode(',', array_fill(0, count(M299_MENUS), '?')) . ')', array_keys(M299_MENUS)) as $l)
            $info['antes'][$l['codigo']] = ($l['pai'] ?? '-') . ' / ' . $l['ordem'] . ' / ' . $l['label_pt'];
        if (!$simular) {
            $pdo->beginTransaction();
            // 1. grupos novos
            foreach ($novos as $codigo => $codmenu) {
                $m = M299_MENUS[$codigo];
                $pai = (int)$valor($pdo, 'SELECT codmenu FROM menus WHERE codigo = ?', [$m[0]]);
                $outro = $valor($pdo, 'SELECT codigo FROM menus WHERE codmenu = ?', [$codmenu]);
                if ($outro !== null && strtoupper((string)$outro) !== $codigo) throw new RuntimeException("codmenu $codmenu já usado por $outro");
                if ((int)$valor($pdo, 'SELECT COUNT(*) FROM menus WHERE codigo = ?', [$codigo]) > 0) continue;
                $dados = array_intersect_key(['codmenu' => $codmenu, 'codmenu_pai' => $pai, 'modulo' => M299_RAIZ, 'codigo' => $codigo, 'label' => $m[3], 'label_pt' => $m[3], 'label_en' => $m[4], 'label_es' => $m[5],
                    'url' => null, 'nivel' => $m[2], 'ordem' => $m[1], 'icone' => $m[6], 'ativo' => 1], $cols);
                $pdo->prepare('INSERT INTO menus (`' . implode('`, `', array_keys($dados)) . '`) VALUES (' . implode(', ', array_fill(0, count($dados), '?')) . ')')->execute(array_values($dados));
            }
            // 2. pai, ordem, nível, nomes e ícone de cada item (pelo código)
            $faltando = [];
            foreach (M299_MENUS as $codigo => $m) {
                $pai = (int)$valor($pdo, 'SELECT codmenu FROM menus WHERE codigo = ?', [$m[0]]);
                if ($pai < 1) throw new RuntimeException("menu pai {$m[0]} não encontrado");
                if ((int)$valor($pdo, 'SELECT COUNT(*) FROM menus WHERE codigo = ?', [$codigo]) < 1) { $faltando[] = $codigo; continue; }
                $dados = array_intersect_key(['codmenu_pai' => $pai, 'modulo' => M299_RAIZ, 'label' => $m[3], 'label_pt' => $m[3], 'label_en' => $m[4], 'label_es' => $m[5], 'nivel' => $m[2], 'ordem' => $m[1], 'icone' => $m[6]], $cols);
                $pdo->prepare('UPDATE menus SET ' . implode(', ', array_map(static fn($c) => "`$c` = ?", array_keys($dados))) . ' WHERE codigo = ?')->execute([...array_values($dados), $codigo]);
            }
            if ($faltando) $info['sem_o_menu_neste_banco'] = $faltando;
            $pdo->commit();
            foreach ($linhas($pdo, 'SELECT m.codigo, p.codigo AS pai, m.ordem, m.label_pt FROM menus m LEFT JOIN menus p ON p.codmenu = m.codmenu_pai WHERE m.codigo IN (' . implode(',', array_fill(0, count(M299_MENUS), '?')) . ') ORDER BY p.codigo, m.ordem', array_keys(M299_MENUS)) as $l)
                $info['depois'][$l['codigo']] = ($l['pai'] ?? '-') . ' / ' . $l['ordem'] . ' / ' . $l['label_pt'];
            if ($banco !== $bancoUniversal && $colunas($banco, 'registros_migracao')) {
                $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
                    VALUES ('HOMOLOGACAO_MENU_299', 'menus', ?, 'ESTRUTURA', '4.0.1', 'CONCLUIDA', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
                    ON DUPLICATE KEY UPDATE status = VALUES(status), quantidade_registros = VALUES(quantidade_registros), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
                    ->execute(["$banco: menu da homologação", count($info['depois']), json_encode($info['depois'], JSON_UNESCAPED_UNICODE)]);
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $relatorio['ok'] = false;
        $info['erro'] = $e->getMessage();
    }
    $relatorio['bancos'][$banco] = $info;
}

$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_299_menu_homologacao' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
