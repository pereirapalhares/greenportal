<?php
declare(strict_types=1);

/*
 * Migração 364 — Demonstrações animadas da Não Conformidade (modo demonstração do assets/js/ajuda.js).
 * Junta ao assunto "nc" do Guia do usuário (guniversal_dbp) os guias de principal/guia_demos_nc.php, pelo id
 * (inclui os que faltam e atualiza os desta migração), e publica uma nova versão.
 * O ícone "Ver como fazer" (data-ajuda-demo) da tela da ocorrência toca o guia sozinho.
 * Se o rascunho do Guia tiver alterações não publicadas, para e avisa, a não ser com --publicar-pendente.
 * Gravação só por procedures (sp_guia_usuario_*). Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-364-demo-nc.php [--simular] [--publicar-pendente]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/GuiaUsuarioService.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$publicarPendente = in_array('--publicar-pendente', $args, true);
$env = GreenPortalDatabase::env();
$universal = GreenPortalDatabase::connect($env, (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp'), 'GUNIVERSAL');
$universal->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$servico = new GuiaUsuarioService($universal);
if (!defined('GREEN_GUIA_USUARIO')) define('GREEN_GUIA_USUARIO', true);
$autor = ['nome' => 'Migração 364', 'codusuario' => 0, 'banco' => 'guniversal_dbp'];
$rel = ['ok' => true, 'migracao' => '364', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'guia' => []];

try {
    $rascunho = $servico->rascunho();
    if (!$rascunho) throw new RuntimeException('O Guia do usuário ainda não tem rascunho no banco (rode antes as migrações 199/309/356).');
    if ($rascunho['pendente'] && !$publicarPendente) throw new RuntimeException('O rascunho do Guia do usuário tem alterações não publicadas (' . $rascunho['alterado_por'] . ', ' . $rascunho['alterado_em'] . '). Publique ou descarte em Administração › Guia do usuário, ou rode com --publicar-pendente.');
    $doc = $servico->normalizar($rascunho['conteudo']);
    $revisao = (int)$rascunho['revisao'];
    $rel['guia']['base'] = 'rascunho revisão ' . $revisao . ($rascunho['publicado'] ? ' (publicada a versão ' . $rascunho['publicado']['numero'] . ')' : '');
    $antes = json_encode($doc, JSON_UNESCAPED_UNICODE);

    foreach (require dirname(__DIR__) . '/principal/guia_demos_nc.php' as $idModulo => $guias) {
        $idx = null;
        foreach ($doc['modulos'] as $i => $m) if ($m['id'] === $idModulo) $idx = $i;
        if ($idx === null) throw new RuntimeException('O Guia do usuário não tem o assunto "' . $idModulo . '" (rode antes a migração 356).');
        // normaliza os guias novos com os perfis do documento (descarta perfil inexistente)
        $novos = $servico->normalizar(['perfis' => $doc['perfis'], 'modulos' => [['id' => $idModulo, 'nome' => 'x', 'guias' => $guias]]])['modulos'][0]['guias'];
        foreach ($novos as $g) {
            $achou = false;
            foreach ($doc['modulos'][$idx]['guias'] as $k => $atual) if ($atual['id'] === $g['id']) { $doc['modulos'][$idx]['guias'][$k] = $g; $achou = true; }
            if (!$achou) {
                if (count($doc['modulos'][$idx]['guias']) >= 60) throw new RuntimeException('O assunto "' . $idModulo . '" já tem 60 guias (limite do Guia).');
                $doc['modulos'][$idx]['guias'][] = $g;
            }
            $rel['guia'][$g['id']] = ($achou ? 'atualizado' : 'incluído') . ' (' . count($g['passos']) . ' passos)';
        }
    }

    $doc = $servico->normalizar($doc);
    $servico->validarParaPublicacao($doc);
    if (json_encode($doc, JSON_UNESCAPED_UNICODE) === $antes) $rel['guia']['acao'] = 'nada a fazer';
    elseif ($simular) $rel['guia']['acao'] = 'será salvo no rascunho e publicado como nova versão';
    else {
        $revisao = $servico->salvarRascunho($doc, $revisao, $autor);
        $pub = $servico->publicar($revisao, 'Migração 364: demonstração animada da NC de Empresa (criar e enviar ao gestor)', $autor);
        $rel['guia']['acao'] = 'publicada a versão ' . $pub['numero'];
    }
} catch (Throwable $e) {
    $rel['ok'] = false;
    $rel['guia']['erro'] = $e->getMessage();
}

echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
