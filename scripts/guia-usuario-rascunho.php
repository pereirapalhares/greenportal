<?php
declare(strict_types=1);

/*
 * Guia do usuário (Administração › Guia do usuário): exporta e importa o RASCUNHO pela linha de comando,
 * para revisar o conteúdo fora da tela. Não publica: a publicação continua na tela, pelo perfil ADMINISTRADOR,
 * depois de pré-visualizar.
 *
 *   --exportar            grava storage\runtime\guia_usuario_rascunho.json (rascunho + revisão) e
 *                         storage\runtime\guia_usuario_menus.json (transações que aceitam link direto)
 *   --importar=ARQUIVO    grava o conteúdo do arquivo como novo rascunho (mesma normalização da tela).
 *                         A revisão do arquivo precisa ser a atual: se alguém alterou o rascunho depois da
 *                         exportação, nada é gravado (conflito de revisão).
 *   --simular             com --importar: só mostra o que mudaria
 *
 * Uso (PowerShell):
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\guia-usuario-rascunho.php --exportar
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\guia-usuario-rascunho.php --importar=storage\runtime\guia_usuario_novo.json --simular
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/GuiaUsuarioService.php';

$opcoes = ['exportar' => false, 'importar' => '', 'simular' => false];
foreach ($argv ?? [] as $a) {
    if ($a === '--exportar') $opcoes['exportar'] = true;
    if ($a === '--simular') $opcoes['simular'] = true;
    if (str_starts_with($a, '--importar=')) $opcoes['importar'] = substr($a, 11);
}
$saida = static function (array $r): never { echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL; exit(!empty($r['ok']) ? 0 : 1); };

$env = GreenPortalDatabase::env();
$servico = new GuiaUsuarioService(GreenPortalDatabase::connect($env, $env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp', 'GUNIVERSAL'));
$pasta = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'runtime';
if (!is_dir($pasta)) @mkdir($pasta, 0775, true);

$resumo = static function (array $doc): array {
    $r = [];
    foreach ((array)($doc['modulos'] ?? []) as $m) {
        $passos = 0; foreach ((array)($m['fases'] ?? []) as $f) $passos += count((array)($f['passos'] ?? []));
        $r[(string)($m['id'] ?? '?')] = sprintf('%s | fases %d, etapas %d, como fazer %d, menu %d, termos %d%s', $m['nome']['pt'] ?? '', count((array)($m['fases'] ?? [])), $passos,
            count((array)($m['tarefas'] ?? [])), count((array)($m['menu'] ?? [])), count((array)($m['termos'] ?? [])), empty($m['ativo']) ? ' (oculta)' : '');
    }
    return $r;
};

$rascunho = $servico->rascunho();
if (!$rascunho) $saida(['ok' => false, 'mensagem' => 'Rascunho do guia não inicializado (migração 199).']);

if ($opcoes['exportar']) {
    $arquivo = $pasta . DIRECTORY_SEPARATOR . 'guia_usuario_rascunho.json';
    file_put_contents($arquivo, json_encode(['revisao' => $rascunho['revisao'], 'pendente' => $rascunho['pendente'], 'publicado' => $rascunho['publicado'], 'conteudo' => $rascunho['conteudo']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $menus = $pasta . DIRECTORY_SEPARATOR . 'guia_usuario_menus.json';
    file_put_contents($menus, json_encode(array_values($servico->menusComUrl()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $saida(['ok' => true, 'exportado' => $arquivo, 'menus' => $menus, 'revisao' => $rascunho['revisao'], 'pendente_de_publicacao' => $rascunho['pendente'], 'publicado' => $rascunho['publicado'], 'abas' => $resumo($rascunho['conteudo'])]);
}

if ($opcoes['importar'] !== '') {
    $caminho = $opcoes['importar'];
    if (!is_file($caminho)) $caminho = dirname(__DIR__) . DIRECTORY_SEPARATOR . $caminho;
    $dados = is_file($caminho) ? json_decode((string)file_get_contents($caminho), true) : null;
    if (!is_array($dados) || !is_array($dados['conteudo'] ?? null)) $saida(['ok' => false, 'mensagem' => 'Arquivo inválido: ' . $opcoes['importar']]);
    $novo = $servico->normalizar($dados['conteudo']);
    $erros = [];
    try { $servico->validarParaPublicacao($novo); } catch (Throwable $e) { $erros[] = $e->getMessage(); }
    $r = ['ok' => true, 'modo' => $opcoes['simular'] ? 'SIMULACAO' : 'GRAVAR', 'revisao_arquivo' => (int)($dados['revisao'] ?? 0), 'revisao_atual' => $rascunho['revisao'],
          'antes' => $resumo($rascunho['conteudo']), 'depois' => $resumo($novo), 'pronto_para_publicar' => $erros ? $erros : 'sim'];
    if ((int)($dados['revisao'] ?? 0) !== $rascunho['revisao']) { $r['ok'] = false; $r['mensagem'] = 'O rascunho foi alterado depois da exportação. Exporte de novo antes de importar.'; $saida($r); }
    if (!$opcoes['simular']) {
        try {
            $r['revisao_nova'] = $servico->salvarRascunho($novo, $rascunho['revisao'], ['nome' => 'Atualização do guia (script)', 'codusuario' => 0, 'banco' => '']);
            $r['mensagem'] = 'Rascunho gravado. Confira em Administração › Guia do usuário (Pré-visualizar) e publique pela tela.';
        } catch (Throwable $e) {
            [, $msg] = GuiaUsuarioService::mensagemErro($e);
            $r['ok'] = false; $r['mensagem'] = $msg;
        }
    }
    $saida($r);
}

$saida(['ok' => false, 'mensagem' => 'Use --exportar ou --importar=ARQUIVO [--simular].']);
