<?php
declare(strict_types=1);

/*
 * Teste não destrutivo da migração 199 (Guia do usuário editável).
 * Não grava no guia: valida estrutura, leitura, normalização, regras de publicação
 * e o bloqueio por conflito de revisão (que não altera dados).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\testar-guia-usuario-199.php
 */

require_once __DIR__ . '/../classes/GreenPortalDatabase.php';
require_once __DIR__ . '/../classes/GuiaUsuarioService.php';

$env = GreenPortalDatabase::env();
$universal = GreenPortalDatabase::connect($env, $env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp', 'GUNIVERSAL');
$service = new GuiaUsuarioService($universal);
$resultados = [];
$check = static function (string $nome, bool $ok, string $detalhe = '') use (&$resultados): void {
    $resultados[] = ['teste' => $nome, 'ok' => $ok, 'detalhe' => $detalhe];
};

$publicado = $service->publicado();
$check('Existe versão publicada', $publicado !== null, $publicado ? 'versão ' . $publicado['versao']['numero'] : '');
$rascunho = $service->rascunho();
$check('Rascunho inicializado', $rascunho !== null, $rascunho ? 'revisão ' . $rascunho['revisao'] : '');
$check('Histórico de versões', count($service->versoes()) >= 1);
$menu = (int)$universal->query("SELECT COUNT(*) FROM menus WHERE codigo='ADMINISTRACAO_GUIA_USUARIO' AND ativo=1 AND codmenu_pai=(SELECT codmenu FROM menus WHERE codigo='ADMINISTRACAO' LIMIT 1)")->fetchColumn();
$check('Menu em Administração', $menu === 1);

$normal = $service->normalizar([
    'perfis' => ['adm' => ['icone' => 'bi-person', 'nome' => ['pt' => 'Adm <script>x</script>']]],
    'modulos' => [['id' => 'Teste Inválido', 'nome' => 'Aba', 'campo_extra' => 1, 'fases' => [['nome' => 'F', 'passos' => [['tipo' => 'Z', 'perfil' => 'inexistente', 'titulo' => 'T']]]]]],
]);
$check('Remove HTML dos textos', $normal['perfis']['adm']['nome']['pt'] === 'Adm x');
$check('Cria perfil sistema', isset($normal['perfis']['sistema']));
$check('Corrige código inválido da aba', $normal['modulos'][0]['id'] === 'modulo_1');
$check('Ignora campos desconhecidos', !array_key_exists('campo_extra', $normal['modulos'][0]));
$check('Tipo inválido vira M', $normal['modulos'][0]['fases'][0]['passos'][0]['tipo'] === 'M');

try {
    $service->validarParaPublicacao($service->normalizar(['modulos' => [['id' => 'x', 'ativo' => true, 'nome' => '']]]));
    $check('Bloqueia publicação com aba sem nome', false);
} catch (InvalidArgumentException) {
    $check('Bloqueia publicação com aba sem nome', true);
}
try {
    $service->validarParaPublicacao($service->normalizar(['modulos' => [['id' => 'a', 'ativo' => true, 'nome' => 'A'], ['id' => 'b', 'ativo' => false, 'nome' => '']]]));
    $check('Aba oculta não bloqueia publicação', true);
} catch (InvalidArgumentException $e) {
    $check('Aba oculta não bloqueia publicação', false, $e->getMessage());
}

if ($rascunho) {
    try {
        $service->salvarRascunho($rascunho['conteudo'], $rascunho['revisao'] + 1000, ['nome' => 'Teste 199']);
        $check('Conflito de revisão bloqueado', false);
    } catch (Throwable $e) {
        $check('Conflito de revisão bloqueado', GuiaUsuarioService::mensagemErro($e)[0] === 409);
    }
    $depois = $service->rascunho();
    $check('Rascunho intacto após conflito', $depois !== null && $depois['revisao'] === $rascunho['revisao']);
}

$ok = !in_array(false, array_column($resultados, 'ok'), true);
echo json_encode(['ok' => $ok, 'resultados' => $resultados], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($ok ? 0 : 1);
