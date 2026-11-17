<?php
declare(strict_types=1);

/*
 * Migração 320 — Crown: perfil GESTORES com as transações que o grupo GESTORES tem no legado (20/10/2026).
 * Motivo: adriano.justino (GESTORES) é responsável por 54 licenças, recebe-as na Agenda e não as abre
 * ("Seu perfil não tem permissão para acessar esta transação."). A migração de segurança só levou 9 dos 84 menus
 * do grupo GESTORES do legado (green_crown_seguranca.gruposxmenus, codgrupo 15). Levantamento:
 * scripts/diagnostico-acesso-perfil-usuario.php --banco=gcrown_dbp --userid=adriano.justino
 * Decisões do usuário (20/10): ver tudo como no legado (Visualizar total) em Licenças e Requisitos; ações
 * incluir/editar/excluir/exportar, como a migração deu nas telas que ele já tinha.
 * Usa a migração 247 (mesmo que marcar as caixas em Segurança › Permissões), só no gcrown_dbp e só no perfil GESTORES.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-320-permissoes-gestores-crown.php [--simular]
 * Os usuários veem a mudança no próximo login.
 */

$simular = in_array('--simular', $argv ?? [], true);
$base = 'visualizar,incluir,editar,excluir,exportar';
// transação do greenportal => [ações, menus do legado que ela substitui]
const M320_TRANSACOES = [
    'LICENCAS' => ['total', '747 Documentos Licenciatórios CROWN'],
    'REQUISITOS_LEGAIS' => ['total', '343 Requisitos Legais Lista · 300 por Árvore · 659 Lista de requisitos legais · 716 revogados · 910 Meus Requisitos Legais'],
    'SITUACAO_CUMPRIMENTO' => ['total', '434–437 Requisitos por árvore por situação quanto ao cumprimento'],
    'NAO_CONFORMIDADES_LISTA_OCORRENCIAS' => ['total', '398 Lista Geral de Ocorrências'],
    'NAO_CONFORMIDADES_NOVA_OCORRENCIA_EMPRESA' => ['', '420 Nova Ocorrência da Empresa'],
    'HOMOLOGACAO_FORNECEDORES_DESATIVADOS' => ['', '718 Gestão de Fornecedores desativados'],
    'HOMOLOGACAO_CRITERIOS_FUNCIONARIOS' => ['', '428 Critérios dos Funcionários'],
    'HOMOLOGACAO_TIPOS_TREINAMENTOS' => ['', '496 Tipos de Treinamentos'],
    'HOMOLOGACAO_CRITERIOS_FORNECEDORES' => ['', '431 Critérios dos Fornecedores'],
    'HOMOLOGACAO_TIPOS_FORNECEDORES' => ['', '430 Tipos de Fornecedores'],
    'HOMOLOGACAO_RELATORIOS_GERENCIAIS' => ['', '505–511 Relatórios gerenciais da homologação · 719 Relatório unificado'],
    'HOMOLOGACAO_PAINEL' => ['', '504 Estatísticas da homologação'],
];
$php = PHP_BINARY;
$script = __DIR__ . DIRECTORY_SEPARATOR . 'aplicar-migracao-247-permissoes-transacao.php';
$rel = ['ok' => true, 'migracao' => '320', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'banco' => 'gcrown_dbp', 'perfil' => 'GESTORES', 'transacoes' => []];
foreach (M320_TRANSACOES as $transacao => [$extra, $legado]) {
    $acoes = $base . ($extra !== '' ? ',' . $extra : '');
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --banco=gcrown_dbp --perfis=GESTORES'
        . ' --transacao=' . $transacao . ' --acoes=' . $acoes . ($simular ? ' --simular' : '');
    $saida = []; $codigo = 0;
    exec($cmd . ' 2>&1', $saida, $codigo);
    $json = json_decode(implode("\n", $saida), true);
    $resultado = is_array($json) ? ($json['bancos']['gcrown_dbp'] ?? $json) : implode(' ', $saida);
    if ($codigo !== 0) $rel['ok'] = false;
    $rel['transacoes'][$transacao] = ['legado' => $legado, 'acoes' => $acoes, 'resultado' => $resultado];
}
$rel['sem_tela_no_greenportal'] = 'Estatísticas do Módulo Legal (406, 342, 407, 376, 418), Lista de Documentos e Correlacionamentos (387, 386), '
    . 'Relatórios gerenciais do Módulo Legal (328, 423), Revisões de requisitos (305), NC: nova ocorrência do fornecedor/cliente/planejamento (421, 422, 637), '
    . 'estatísticas (404), relatório de pendências (658), ocorrências por fornecedor (723); Planejamentos, Assistência Técnica e NC de Clientes (694–696, 742–744, 834–842). '
    . 'Sem cadastro em green_seguranca.menus: 329, 366, 367, 388, 389, 392, 408, 419, 433, 446, 516, 580.';
$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_320_permissoes_gestores_crown' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
