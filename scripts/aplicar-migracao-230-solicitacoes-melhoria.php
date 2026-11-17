<?php
declare(strict_types=1);

/*
 * Migração 230 — Solicitações de melhoria (etapa 2 do assistente da aplicação).
 * 1. Em cada banco de cliente: tabelas solicitacoes_melhoria e solicitacoes_melhoria_historico e o prompt
 *    SOLICITACAO_MELHORIA (redige o pedido a partir da conversa; editável em Setup › Prompts de IA).
 * 2. Menus em Administração (guniversal_dbp e na cópia de menus de cada cliente):
 *      ADMINISTRACAO_MINHAS_SOLICITACOES   — Minhas solicitações (todos os perfis)
 *      ADMINISTRACAO_SOLICITACOES_MELHORIA — Gestão de solicitações (ADMINISTRADOR e ADM DA EMPRESA)
 * 3. Permissões desses menus para os perfis acima.
 * Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-230-solicitacoes-melhoria.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$sql = file_get_contents(dirname(__DIR__) . '/database/230_solicitacoes_melhoria.sql');
if ($sql === false) throw new RuntimeException('Script SQL da migração 230 não encontrado.');

$normalizarPerfil = static fn(string $p): string => trim(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', mb_strtoupper(trim($p), 'UTF-8'))) ?? '');
$perfisGestao = ['ADMINISTRADOR', 'ADM DA EMPRESA', 'ADM EMPRESA'];

$menus = [
    'ADMINISTRACAO_MINHAS_SOLICITACOES' => ['pt' => 'Minhas solicitações', 'en' => 'My requests', 'es' => 'Mis solicitudes', 'url' => '/principal/minhas_solicitacoes.php', 'icone' => 'bi-lightbulb'],
    'ADMINISTRACAO_SOLICITACOES_MELHORIA' => ['pt' => 'Gestão de solicitações', 'en' => 'Request management', 'es' => 'Gestión de solicitudes', 'url' => '/principal/administracao_solicitacoes.php', 'icone' => 'bi-kanban'],
];

$formato = '{"titulo":"título curto do pedido","descricao":"descrição clara do que o usuário precisa","beneficio":"para que o usuário precisa disso","categoria":"FUNCIONALIDADE_INEXISTENTE|MELHORIA|INTEGRACAO|RELATORIO|IA|DIFICULDADE_USO|OUTRO"}';
$prompt = [
    'pt' => 'Você redige solicitações de melhoria para a equipe do produto GreenSolutions a partir do que o usuário pediu ao assistente. '
        . 'Escreva em português do Brasil, de forma objetiva e sem inventar detalhes que o usuário não disse. '
        . 'titulo: até 90 caracteres, começando por um verbo (ex.: "Enviar aviso de vencimento do ASO por WhatsApp"). '
        . 'descricao: 2 a 4 frases no formato "Como <perfil>, quero <o quê>, para <benefício>." seguido do contexto (tela, situação). '
        . 'beneficio: uma frase. Nunca inclua nomes de pessoas, CPF, CNPJ, e-mails ou telefones. '
        . 'Retorne exclusivamente JSON válido no formato ' . $formato,
    'en' => 'You write improvement requests for the GreenSolutions product team based on what the user asked the assistant. '
        . 'Write in English, objectively, without inventing details the user did not say. '
        . 'titulo: up to 90 characters, starting with a verb (e.g. "Send ASO expiry notice by WhatsApp"). '
        . 'descricao: 2 to 4 sentences in the format "As a <profile>, I want <what>, so that <benefit>." followed by the context (screen, situation). '
        . 'beneficio: one sentence. Never include people\'s names, tax IDs, e-mails or phone numbers. '
        . 'Return only valid JSON in the format ' . $formato,
    'es' => 'Usted redacta solicitudes de mejora para el equipo del producto GreenSolutions a partir de lo que el usuario pidió al asistente. '
        . 'Escriba en español, de forma objetiva y sin inventar detalles que el usuario no dijo. '
        . 'titulo: hasta 90 caracteres, comenzando con un verbo (ej.: "Enviar aviso de vencimiento del ASO por WhatsApp"). '
        . 'descricao: 2 a 4 frases en el formato "Como <perfil>, quiero <qué>, para <beneficio>." seguido del contexto (pantalla, situación). '
        . 'beneficio: una frase. Nunca incluya nombres de personas, documentos, correos ni teléfonos. '
        . 'Devuelva exclusivamente JSON válido en el formato ' . $formato,
];

$colunas = static function (PDO $pdo, string $tabela): array {
    $q = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $q->execute([$tabela]);
    return array_map('strtolower', $q->fetchAll(PDO::FETCH_COLUMN));
};

$resultado = ['ok' => true, 'migracao' => '230', 'menus' => [], 'bancos' => []];

// ---------------------------------------------------------------- bancos de clientes
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES
    WHERE TABLE_NAME IN ('usuarios', 'registros_migracao', 'perfis', 'permissoes') AND TABLE_SCHEMA LIKE '%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 4 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$conexoes = [];
foreach ($bancos as $banco) $conexoes[$banco] = GreenPortalDatabase::connect($env, $banco, 'GPT');

// ---------------------------------------------------------------- menus (universal + cópia de cada cliente)
$universal = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$pai = (int)$universal->query("SELECT codmenu FROM menus WHERE codigo = 'ADMINISTRACAO' LIMIT 1")->fetchColumn();
if ($pai < 1) throw new RuntimeException('Menu ADMINISTRACAO não encontrado em guniversal_dbp.menus.');
$proximo = (int)$universal->query('SELECT COALESCE(MAX(codmenu), 0) FROM menus')->fetchColumn();
foreach ($conexoes as $pdo) {
    if (in_array('codmenu', $colunas($pdo, 'menus'), true)) $proximo = max($proximo, (int)$pdo->query('SELECT COALESCE(MAX(codmenu), 0) FROM menus')->fetchColumn());
}
$ordem = (int)$universal->query('SELECT COALESCE(MAX(ordem), 0) FROM menus WHERE codmenu_pai = ' . $pai)->fetchColumn();
$colunasUniversal = $colunas($universal, 'menus');
$codigosMenu = [];
foreach ($menus as $codigo => $m) {
    $q = $universal->prepare('SELECT codmenu, ordem FROM menus WHERE codigo = ?');
    $q->execute([$codigo]);
    $existente = $q->fetch(PDO::FETCH_ASSOC);
    $codmenu = $existente ? (int)$existente['codmenu'] : ++$proximo;
    $linha = ['codmenu' => $codmenu, 'codmenu_pai' => $pai, 'modulo' => 'ADMINISTRACAO', 'codigo' => $codigo, 'label' => $m['pt'], 'label_pt' => $m['pt'], 'label_en' => $m['en'], 'label_es' => $m['es'],
        'url' => $m['url'], 'nivel' => 2, 'ordem' => $existente ? (int)$existente['ordem'] : ++$ordem, 'icone' => $m['icone'], 'ativo' => 1];
    $gravarMenu = static function (PDO $pdo, array $cols) use ($linha): void {
        $dados = array_intersect_key($linha, array_flip($cols));
        $campos = array_keys($dados);
        $update = implode(', ', array_map(static fn(string $c): string => "`$c` = VALUES(`$c`)", array_diff($campos, ['codmenu'])));
        $pdo->prepare('INSERT INTO menus (`' . implode('`, `', $campos) . '`) VALUES (' . implode(', ', array_fill(0, count($campos), '?')) . ') ON DUPLICATE KEY UPDATE ' . $update)
            ->execute(array_values($dados));
    };
    $gravarMenu($universal, $colunasUniversal);
    $codigosMenu[$codigo] = $codmenu;
    $resultado['menus'][$codigo] = $codmenu;
    foreach ($conexoes as $banco => $pdo) {
        $cols = $colunas($pdo, 'menus');
        if (!in_array('codmenu', $cols, true)) continue;
        $q = $pdo->prepare('SELECT codigo FROM menus WHERE codmenu = ?');
        $q->execute([$codmenu]);
        $outro = $q->fetchColumn();
        if ($outro !== false && $outro !== $codigo) { $resultado['ok'] = false; $resultado['bancos'][$banco]['erro_menu'] = "codmenu $codmenu já usado por $outro"; continue; }
        $gravarMenu($pdo, $cols);
    }
}

// ---------------------------------------------------------------- tabelas, permissões e prompt
foreach ($conexoes as $banco => $pdo) {
    try {
        $pdo->exec($sql);
        $perfis = $pdo->query('SELECT codperfil, codigo FROM perfis')->fetchAll(PDO::FETCH_ASSOC);
        $permitir = static function (string $tabela, int $codperfil, int $codmenu, bool $gestao) use ($pdo, $colunas): void {
            $cols = $colunas($pdo, $tabela);
            if (!$cols) return;
            $flags = ['pode_visualizar' => 1, 'pode_incluir' => 1, 'pode_editar' => $gestao ? 1 : 0, 'pode_exportar_excel' => $gestao ? 1 : 0, 'pode_visualizar_total' => $gestao ? 1 : 0];
            $flags = array_intersect_key($flags, array_flip($cols));
            $q = $pdo->prepare("SELECT COUNT(*) FROM `$tabela` WHERE codperfil = ? AND codmenu = ?");
            $q->execute([$codperfil, $codmenu]);
            if ((int)$q->fetchColumn() > 0) {
                $pdo->prepare("UPDATE `$tabela` SET " . implode(', ', array_map(static fn(string $c): string => "`$c` = GREATEST(`$c`, ?)", array_keys($flags))) . ' WHERE codperfil = ? AND codmenu = ?')
                    ->execute([...array_values($flags), $codperfil, $codmenu]);
            } else {
                $campos = ['codperfil', 'codmenu', ...array_keys($flags)];
                $pdo->prepare("INSERT INTO `$tabela` (`" . implode('`, `', $campos) . '`) VALUES (' . implode(', ', array_fill(0, count($campos), '?')) . ')')
                    ->execute([$codperfil, $codmenu, ...array_values($flags)]);
            }
        };
        $contagem = ['minhas' => 0, 'gestao' => 0];
        foreach ($perfis as $perfil) {
            $codperfil = (int)$perfil['codperfil'];
            foreach (['permissoes', 'perfil_menus'] as $tabela) $permitir($tabela, $codperfil, $codigosMenu['ADMINISTRACAO_MINHAS_SOLICITACOES'], false);
            $contagem['minhas']++;
            if (in_array($normalizarPerfil((string)$perfil['codigo']), $perfisGestao, true)) {
                foreach (['permissoes', 'perfil_menus'] as $tabela) $permitir($tabela, $codperfil, $codigosMenu['ADMINISTRACAO_SOLICITACOES_MELHORIA'], true);
                $contagem['gestao']++;
            }
        }
        $promptSituacao = 'tabela ia_prompts inexistente neste banco';
        if ($colunas($pdo, 'ia_prompts')) {
            if ((int)$pdo->query("SELECT COUNT(*) FROM ia_prompts WHERE codigo = 'SOLICITACAO_MELHORIA'")->fetchColumn() > 0) {
                $promptSituacao = 'já existia (mantido, pode ter sido editado)';
            } else {
                $pdo->prepare('INSERT INTO ia_prompts (codigo, nome_pt, nome_en, nome_es, prompt_pt, prompt_en, prompt_es) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute(['SOLICITACAO_MELHORIA', 'Redação de solicitação de melhoria', 'Improvement request writing', 'Redacción de solicitud de mejora', $prompt['pt'], $prompt['en'], $prompt['es']]);
                $promptSituacao = 'cadastrado';
            }
        }
        $detalhes = ['tabelas' => ['solicitacoes_melhoria', 'solicitacoes_melhoria_historico'], 'menus' => $codigosMenu, 'perfis_minhas_solicitacoes' => $contagem['minhas'], 'perfis_gestao' => $contagem['gestao'], 'prompt' => 'SOLICITACAO_MELHORIA', 'prompt_situacao' => $promptSituacao];
        $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
            VALUES ('GREEN_SOLICITACOES_MELHORIA_230', 'assistente_ia', 'solicitacoes_melhoria;solicitacoes_melhoria_historico;menus;permissoes;ia_prompts', 'ESTRUTURA', '4.0.1', 'CONCLUIDA', 2, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
            ->execute([json_encode($detalhes, JSON_UNESCAPED_UNICODE)]);
        $resultado['bancos'][$banco] = ($resultado['bancos'][$banco] ?? []) + $detalhes;
    } catch (Throwable $e) {
        $resultado['ok'] = false;
        $resultado['bancos'][$banco] = ['erro' => $e->getMessage()];
    }
}
if (!$bancos) $resultado['ok'] = false;

echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($resultado['ok'] ? 0 : 1);
