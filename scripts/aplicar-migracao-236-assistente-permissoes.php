<?php
declare(strict_types=1);

/*
 * Migração 236 — o uso do assistente de IA ("Como posso ajudar?") passa a ser liberado em Segurança › Permissões.
 *
 * 1. Cria a transação ASSISTENTE_IA ("Assistente de IA (Como posso ajudar?)") em Administração:
 *    - na tabela menus de cada cliente, ativa, para aparecer em Permissões;
 *    - no guniversal_dbp, inativa, para não aparecer como item na barra lateral (o assistente abre pelo cabeçalho).
 * 2. Permissões iniciais: Visualizar = Sim para os perfis que já usavam o assistente (ia_assistente_config.perfis_liberados),
 *    Não para os demais. Permissões já existentes não são alteradas.
 * 3. ia_assistente_config.controle_acesso = PERMISSOES (a lista perfis_liberados deixa de ser usada).
 * Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-236-assistente-permissoes.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$codigo = 'ASSISTENTE_IA';
$menu = ['pt' => 'Assistente de IA (Como posso ajudar?)', 'en' => 'AI assistant (How can I help?)', 'es' => 'Asistente de IA (¿Cómo puedo ayudar?)', 'url' => '/principal/main.php#assistente', 'icone' => 'bi-stars'];
$normalizar = static fn(string $p): string => trim(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', mb_strtoupper(trim($p), 'UTF-8'))) ?? '');
$colunas = static function (PDO $pdo, string $tabela): array {
    $q = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $q->execute([$tabela]);
    return array_map('strtolower', $q->fetchAll(PDO::FETCH_COLUMN));
};
$gravarMenu = static function (PDO $pdo, array $cols, array $linha): void {
    $dados = array_intersect_key($linha, array_flip($cols));
    $campos = array_keys($dados);
    $update = implode(', ', array_map(static fn(string $c): string => "`$c` = VALUES(`$c`)", array_diff($campos, ['codmenu'])));
    $pdo->prepare('INSERT INTO menus (`' . implode('`, `', $campos) . '`) VALUES (' . implode(', ', array_fill(0, count($campos), '?')) . ') ON DUPLICATE KEY UPDATE ' . $update)->execute(array_values($dados));
};

$resultado = ['ok' => true, 'migracao' => '236', 'bancos' => []];
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES
    WHERE TABLE_NAME IN ('menus', 'perfis', 'permissoes', 'ia_assistente_config') AND TABLE_SCHEMA LIKE '%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 4 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$conexoes = [];
foreach ($bancos as $banco) $conexoes[$banco] = GreenPortalDatabase::connect($env, $banco, 'GPT');

// Código do menu: o mesmo em todos os bancos, livre no universal e em cada cliente.
$universal = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$q = $universal->prepare('SELECT codmenu FROM menus WHERE codigo = ?');
$q->execute([$codigo]);
$codmenu = (int)$q->fetchColumn();
if ($codmenu < 1) {
    $codmenu = (int)$universal->query('SELECT COALESCE(MAX(codmenu), 0) FROM menus')->fetchColumn();
    foreach ($conexoes as $pdo) $codmenu = max($codmenu, (int)$pdo->query('SELECT COALESCE(MAX(codmenu), 0) FROM menus')->fetchColumn());
    $codmenu++;
}
$pai = (int)$universal->query("SELECT codmenu FROM menus WHERE codigo = 'ADMINISTRACAO' LIMIT 1")->fetchColumn();
if ($pai < 1) throw new RuntimeException('Menu ADMINISTRACAO não encontrado em guniversal_dbp.menus.');
$linha = ['codmenu' => $codmenu, 'codmenu_pai' => $pai, 'modulo' => 'ADMINISTRACAO', 'codigo' => $codigo, 'label' => $menu['pt'], 'label_pt' => $menu['pt'], 'label_en' => $menu['en'], 'label_es' => $menu['es'],
    'url' => $menu['url'], 'nivel' => 2, 'ordem' => 999, 'icone' => $menu['icone']];
$gravarMenu($universal, $colunas($universal, 'menus'), $linha + ['ativo' => 0]);
$resultado['menu'] = ['codigo' => $codigo, 'codmenu' => $codmenu, 'universal' => 'inativo (fora da barra lateral)'];

foreach ($conexoes as $banco => $pdo) {
    try {
        $q = $pdo->prepare('SELECT codigo FROM menus WHERE codmenu = ?');
        $q->execute([$codmenu]);
        $outro = $q->fetchColumn();
        if ($outro !== false && strtoupper((string)$outro) !== $codigo) throw new RuntimeException("codmenu $codmenu já usado por $outro neste banco");
        $paiCliente = (int)$pdo->query("SELECT codmenu FROM menus WHERE codigo = 'ADMINISTRACAO' LIMIT 1")->fetchColumn() ?: $pai;
        $gravarMenu($pdo, $colunas($pdo, 'menus'), ['codmenu_pai' => $paiCliente] + $linha + ['ativo' => 1]);

        $liberados = array_filter(array_map($normalizar, explode(',', (string)$pdo->query("SELECT valor FROM ia_assistente_config WHERE chave = 'perfis_liberados'")->fetchColumn())));
        $todos = in_array('*', $liberados, true);
        $contagem = ['liberados' => [], 'nao_liberados' => 0, 'ja_existiam' => 0];
        foreach ($pdo->query('SELECT codperfil, codigo FROM perfis')->fetchAll(PDO::FETCH_ASSOC) as $perfil) {
            $usa = $todos || in_array($normalizar((string)$perfil['codigo']), $liberados, true) || strtoupper((string)$perfil['codigo']) === 'ADMINISTRADOR';
            foreach (['permissoes', 'perfil_menus'] as $tabela) {
                $cols = $colunas($pdo, $tabela);
                if (!$cols) continue;
                $q = $pdo->prepare("SELECT COUNT(*) FROM `$tabela` WHERE codperfil = ? AND codmenu = ?");
                $q->execute([(int)$perfil['codperfil'], $codmenu]);
                if ((int)$q->fetchColumn() > 0) { if ($tabela === 'permissoes') $contagem['ja_existiam']++; continue; }
                $flags = array_intersect_key(['pode_visualizar' => $usa ? 1 : 0, 'pode_incluir' => 0, 'pode_editar' => 0, 'pode_excluir' => 0, 'pode_exportar_excel' => 0, 'pode_gerar_pdf' => 0], array_flip($cols));
                $campos = ['codperfil', 'codmenu', ...array_keys($flags)];
                $pdo->prepare("INSERT INTO `$tabela` (`" . implode('`, `', $campos) . '`) VALUES (' . implode(', ', array_fill(0, count($campos), '?')) . ')')
                    ->execute([(int)$perfil['codperfil'], $codmenu, ...array_values($flags)]);
                if ($tabela === 'permissoes') { if ($usa) $contagem['liberados'][] = (string)$perfil['codigo']; else $contagem['nao_liberados']++; }
            }
        }
        $pdo->exec("INSERT INTO ia_assistente_config (chave, valor, descricao) VALUES ('controle_acesso', 'PERMISSOES', 'PERMISSOES: liberado em Segurança › Permissões (transação ASSISTENTE_IA, Visualizar). PERFIS: lista perfis_liberados.')
            ON DUPLICATE KEY UPDATE valor = 'PERMISSOES', descricao = VALUES(descricao)");
        $detalhes = ['transacao' => $codigo, 'codmenu' => $codmenu, 'perfis_com_acesso' => $contagem['liberados'], 'perfis_sem_acesso' => $contagem['nao_liberados'], 'permissoes_ja_existentes' => $contagem['ja_existiam'], 'controle_acesso' => 'PERMISSOES'];
        if ($colunas($pdo, 'registros_migracao')) {
            $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
                VALUES ('GREEN_ASSISTENTE_PERMISSOES_236', 'ia_assistente_config.perfis_liberados', 'menus;permissoes;ia_assistente_config', 'CONFIGURACAO', '4.0.1', 'CONCLUIDA', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
                ->execute([count($contagem['liberados']), json_encode($detalhes, JSON_UNESCAPED_UNICODE)]);
        }
        $resultado['bancos'][$banco] = $detalhes;
    } catch (Throwable $e) {
        $resultado['ok'] = false;
        $resultado['bancos'][$banco] = ['erro' => $e->getMessage()];
    }
}
if (!$bancos) $resultado['ok'] = false;

echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($resultado['ok'] ? 0 : 1);
