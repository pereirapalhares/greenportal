<?php
declare(strict_types=1);

/*
 * Migração 201 — Links diretos do Guia do usuário para as transações.
 *
 * Completa o guia já gravado no banco (migração 199) com os links sugeridos no
 * conteúdo padrão (principal/guia_usuario_conteudo.php). Só preenche itens que
 * ainda não têm link e que tenham o mesmo título em português do conteúdo padrão;
 * nada que o administrador tenha escrito é alterado.
 *
 * - Se o rascunho estava igual ao publicado, publica automaticamente uma nova versão.
 * - Se havia alterações pendentes no rascunho, só grava no rascunho; o administrador
 *   revisa e publica em Administração › Guia do usuário.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-201-guia-usuario-links.php
 */

require_once __DIR__ . '/../classes/GreenPortalDatabase.php';
require_once __DIR__ . '/../classes/GuiaUsuarioService.php';

$env = GreenPortalDatabase::env();
$universalDb = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$universal = GreenPortalDatabase::connect($env, $universalDb, 'GUNIVERSAL');
$service = new GuiaUsuarioService($universal);
$autor = ['nome' => 'Migração 201', 'codusuario' => 0, 'banco' => $universalDb];

if (!defined('GREEN_GUIA_USUARIO')) define('GREEN_GUIA_USUARIO', true);
$padraoBruto = require __DIR__ . '/../principal/guia_usuario_conteudo.php';
$padrao = $service->normalizar(['perfis' => $padraoBruto['perfis'], 'modulos' => $padraoBruto['modulos']]);

$chave = static fn(array $texto): string => mb_strtolower(trim((string)($texto['pt'] ?? '')));
$sugestoes = [];
foreach ($padrao['modulos'] as $m) {
    foreach ($m['fases'] as $fase) foreach ($fase['passos'] as $p) if ($p['transacao'] !== '') $sugestoes[$m['id']]['passo'][$chave($p['titulo'])] = $p['transacao'];
    foreach ($m['tarefas'] as $x) if ($x['transacao'] !== '') $sugestoes[$m['id']]['tarefa'][$chave($x['titulo'])] = $x['transacao'];
    foreach ($m['menu'] as $x) if ($x['transacao'] !== '') $sugestoes[$m['id']]['menu'][$chave($x['caminho'])] = $x['transacao'];
}

$rascunho = $service->rascunho();
if (!$rascunho) {
    echo json_encode(['ok' => false, 'mensagem' => 'Guia não inicializado. Execute antes a migração 199.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
$pendenteAntes = (bool)$rascunho['pendente'];
$doc = $service->normalizar($rascunho['conteudo']);
$preenchidos = 0;
foreach ($doc['modulos'] as &$m) {
    $s = $sugestoes[$m['id']] ?? [];
    foreach ($m['fases'] as &$fase) foreach ($fase['passos'] as &$p) {
        $c = $s['passo'][$chave($p['titulo'])] ?? '';
        if ($p['transacao'] === '' && $c !== '') { $p['transacao'] = $c; $preenchidos++; }
    }
    unset($fase, $p);
    foreach ($m['tarefas'] as &$x) { $c = $s['tarefa'][$chave($x['titulo'])] ?? ''; if ($x['transacao'] === '' && $c !== '') { $x['transacao'] = $c; $preenchidos++; } }
    unset($x);
    foreach ($m['menu'] as &$x) { $c = $s['menu'][$chave($x['caminho'])] ?? ''; if ($x['transacao'] === '' && $c !== '') { $x['transacao'] = $c; $preenchidos++; } }
    unset($x);
}
unset($m);

$resultado = ['ok' => true, 'migracao' => '201', 'links_preenchidos' => $preenchidos, 'acao' => 'NENHUMA'];
if ($preenchidos > 0) {
    $revisao = $service->salvarRascunho($doc, (int)$rascunho['revisao'], $autor);
    if (!$pendenteAntes) {
        $versao = $service->publicar($revisao, 'Links diretos para as transações (migração 201).', $autor);
        $resultado['acao'] = 'PUBLICADO';
        $resultado['versao'] = $versao['numero'];
    } else {
        $resultado['acao'] = 'GRAVADO_NO_RASCUNHO';
        $resultado['mensagem'] = 'O rascunho tinha alterações pendentes: revise e publique em Administração › Guia do usuário.';
    }
}

// Ajuda da tela de administração: acrescenta a seção sobre links se ainda não existir
// (não altera o restante do texto, que pode ter sido ajustado em Configurações › Ajuda das transações).
$secoesAjuda = [
    'pt' => '<h2>15. Link direto para a tela</h2><p>Etapas do fluxo, itens de "Como fazer" e itens de "Onde encontrar" podem ter um <strong>Link direto para a tela</strong>. No guia, aparece o botão <strong>Abrir tela</strong>: ao clicar, a transação abre em uma aba do portal, como se o usuário tivesse usado o menu, e o guia se fecha.</p><ul><li>Escolha a tela na lista do campo <strong>Link direto para a tela</strong>. A lista mostra as transações ativas do menu, agrupadas pelo menu principal. Para tirar o link, escolha "Sem link".</li><li>O botão só aparece para quem tem permissão de acessar aquela tela. Quem não tem permissão vê apenas o texto, sem o botão.</li><li>Na árvore, o ícone de corrente indica os itens que têm link.</li><li>Se aparecer o aviso de que a transação não existe ou está inativa, o menu foi alterado: escolha outra tela ou retire o link.</li><li>Use o link quando ele economizar tempo de verdade, normalmente nas etapas manuais. Etapas automáticas raramente precisam de link.</li></ul>',
    'en' => '<h2>15. Direct link to the screen</h2><p>Flow steps, How to items and Where to find items can have a <strong>Direct link to the screen</strong>. In the guide, an <strong>Open screen</strong> button is shown: when clicked, the transaction opens in a portal tab, as if the user had used the menu, and the guide closes.</p><ul><li>Choose the screen in the <strong>Direct link to the screen</strong> list. It shows the active menu transactions, grouped by main menu. To remove the link, choose "No link".</li><li>The button only appears for users allowed to access that screen. Users without permission see only the text.</li><li>In the tree, the chain icon marks items that have a link.</li><li>If a warning says the transaction does not exist or is inactive, the menu was changed: choose another screen or remove the link.</li><li>Use links when they really save time, usually in manual steps. Automatic steps rarely need a link.</li></ul>',
    'es' => '<h2>15. Enlace directo a la pantalla</h2><p>Las etapas del flujo, los ítems de Cómo hacer y los de Dónde encontrar pueden tener un <strong>Enlace directo a la pantalla</strong>. En la guía aparece el botón <strong>Abrir pantalla</strong>: al hacer clic, la transacción se abre en una pestaña del portal, como si el usuario hubiera usado el menú, y la guía se cierra.</p><ul><li>Elija la pantalla en la lista del campo <strong>Enlace directo a la pantalla</strong>. Muestra las transacciones activas del menú, agrupadas por el menú principal. Para quitar el enlace, elija "Sin enlace".</li><li>El botón solo aparece para quien tiene permiso de acceder a esa pantalla. Quien no tiene permiso ve solo el texto.</li><li>En el árbol, el ícono de cadena indica los ítems con enlace.</li><li>Si aparece el aviso de que la transacción no existe o está inactiva, el menú fue modificado: elija otra pantalla o quite el enlace.</li><li>Use el enlace cuando realmente ahorre tiempo, normalmente en las etapas manuales. Las etapas automáticas rara vez necesitan enlace.</li></ul>',
];
$resultado['ajuda'] = 'SEM_AJUDA_CADASTRADA';
try {
    $ajuda = $universal->query("SELECT codajudatransacao, texto_pt, texto_en, texto_es FROM ajuda_transacoes WHERE transacao = 'administracao_guia_usuario'")->fetch(PDO::FETCH_ASSOC);
    if ($ajuda) {
        $resultado['ajuda'] = 'JA_ATUALIZADA';
        $marcas = ['pt' => 'Link direto para a tela</h2>', 'en' => 'Direct link to the screen</h2>', 'es' => 'Enlace directo a la pantalla</h2>'];
        $novo = [];
        foreach ($secoesAjuda as $l => $html) $novo[$l] = str_contains((string)$ajuda['texto_' . $l], $marcas[$l]) ? (string)$ajuda['texto_' . $l] : (string)$ajuda['texto_' . $l] . $html;
        if ($novo['pt'] !== $ajuda['texto_pt'] || $novo['en'] !== $ajuda['texto_en'] || $novo['es'] !== $ajuda['texto_es']) {
            $universal->prepare('UPDATE ajuda_transacoes SET texto_pt = ?, texto_en = ?, texto_es = ? WHERE codajudatransacao = ?')
                ->execute([$novo['pt'], $novo['en'], $novo['es'], (int)$ajuda['codajudatransacao']]);
            $resultado['ajuda'] = 'SECAO_LINKS_ACRESCENTADA';
        }
    }
} catch (Throwable $e) {
    $resultado['ajuda'] = 'NAO_ATUALIZADA: ' . $e->getMessage();
}

$universal->prepare("INSERT INTO green_universal_migrations (codigo_migracao, versao, status, detalhes) VALUES ('GREEN_GUIA_USUARIO_LINKS_201', '4.0.1', 'CONCLUIDA', ?)
    ON DUPLICATE KEY UPDATE status = VALUES(status), detalhes = VALUES(detalhes), executado_em = CURRENT_TIMESTAMP")
    ->execute([json_encode(['links_preenchidos' => $preenchidos, 'acao' => $resultado['acao']], JSON_UNESCAPED_UNICODE)]);

$menus = $service->menusComUrl();
$naoEncontrados = [];
foreach ($doc['modulos'] as $m) {
    $codigos = [];
    foreach ($m['fases'] as $f) foreach ($f['passos'] as $p) $codigos[] = $p['transacao'];
    foreach (array_merge($m['tarefas'], $m['menu']) as $x) $codigos[] = $x['transacao'];
    foreach (array_unique(array_filter($codigos)) as $c) if (!isset($menus[$c])) $naoEncontrados[] = $c;
}
$resultado['transacoes_nao_encontradas_no_menu'] = array_values(array_unique($naoEncontrados));
echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
