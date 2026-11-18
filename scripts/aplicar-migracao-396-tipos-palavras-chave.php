<?php
declare(strict_types=1);

/*
 * Migração 396 — Manutenção Legal › Tipos e Palavras-chave (pedido do usuário de 18/11/2026).
 * Junta as telas Tipos de palavras-chave (TIPOS_PALAVRAS_CHAVE) e Tipos × Palavras-chave (TIPOS_X_PALAVRAS_CHAVE) numa só,
 * com abas — mesmo modelo da migração 395 (Perfis e Permissões):
 *   1. inclui no catálogo universal o menu MANUTENCAOLEGAL_TIPOS_PALAVRAS (Tipos e Palavras-chave / Types and Keywords /
 *      Tipos y Palabras clave), no mesmo grupo e na posição de Tipos de palavras-chave, e replica em todas as empresas;
 *   2. dá ao menu novo, em cada empresa, as permissões que cada perfil tinha em qualquer uma das duas telas (junta);
 *   3. desliga (ativo = 0) os dois itens antigos. Os endereços antigos continuam abrindo; as permissões antigas ficam.
 * Gravação só por procedures (MigracaoCrud / MenuCatalogoService usam sp_crud_*). Pode ser executada mais de uma vez.
 *
 * Uso: php scripts\aplicar-migracao-396-tipos-palavras-chave.php            (simulação)
 *      php scripts\aplicar-migracao-396-tipos-palavras-chave.php --aplicar  (grava)
 * Depois de aplicar, quem estava logado precisa sair e entrar de novo (o menu e as permissões ficam na sessão).
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';

const NOVO = 'MANUTENCAOLEGAL_TIPOS_PALAVRAS';
const ANTIGOS = ['TIPOS_PALAVRAS_CHAVE', 'TIPOS_X_PALAVRAS_CHAVE'];
$aplicar = in_array('--aplicar', $argv ?? [], true);
$rel = ['ok' => true, 'migracao' => '396', 'modo' => $aplicar ? 'APLICAR' : 'SIMULACAO'];

try {
    $cat = new MenuCatalogoService();
    $u = $cat->universal();
    $colsMenu = MigracaoCrud::colunas($u, 'menus');

    // ------------------------------------------------------------ 1) menu novo no catálogo
    $base = [];
    foreach (ANTIGOS as $c) { $m = MigracaoCrud::primeiro($u, 'menus', ['codigo' => $c]); if ($m) $base[$c] = $m; }
    if (!$base) throw new RuntimeException('Os itens ' . implode(' e ', ANTIGOS) . ' não existem no catálogo universal.');
    $modelo = $base['TIPOS_PALAVRAS_CHAVE'] ?? $base['TIPOS_X_PALAVRAS_CHAVE'];
    $urlBase = (string)($modelo['url'] ?? '');
    $url = preg_match('/manutencaolegal_tipos(_palavras_chave|xpalavras_chave)\.php/i', $urlBase) ? (string)preg_replace('/manutencaolegal_tipos(_palavras_chave|xpalavras_chave)\.php/i', 'manutencaolegal_tipos_e_palavras_chave.php', $urlBase) : '/principal/manutencaolegal_tipos_e_palavras_chave.php';
    $dados = ['codigo' => NOVO, 'label' => 'Tipos e Palavras-chave', 'label_pt' => 'Tipos e Palavras-chave', 'label_en' => 'Types and Keywords', 'label_es' => 'Tipos y Palabras clave',
        'url' => $url, 'icone' => 'bi-tags', 'ativo' => 1];
    foreach (['codmenu_pai', 'modulo', 'nivel', 'ordem'] as $c) if (array_key_exists($c, $modelo)) $dados[$c] = $modelo[$c];
    $dados = array_intersect_key($dados, array_flip($colsMenu));
    $existe = MigracaoCrud::primeiro($u, 'menus', ['codigo' => NOVO]);
    $rel['menu_novo'] = ($existe ? 'já existe (codmenu ' . $existe['codmenu'] . '): será conferido' : 'será incluído') . ' — url ' . $url . ', pai ' . ($dados['codmenu_pai'] ?? '-') . ', ordem ' . ($dados['ordem'] ?? '-');
    if ($aplicar) $rel['menu_novo_resultado'] = $cat->gravar($dados, $existe ? (int)$existe['codmenu'] : null);

    // ------------------------------------------------------------ 2) permissões, por empresa
    foreach ($cat->empresas() as $banco => $p) {
        $colsPerm = MigracaoCrud::colunas($p, 'permissoes');
        if (!$colsPerm) { $rel['empresas'][$banco] = 'sem tabela permissoes'; continue; }
        $flags = array_values(array_filter($colsPerm, static fn($c) => str_starts_with($c, 'pode_')));
        $perfis = [];
        foreach (MigracaoCrud::selecionar($p, 'perfis') as $pf) $perfis[(int)$pf['codperfil']] = (string)($pf['codigo'] ?? $pf['codperfil']);
        $soma = [];
        foreach (ANTIGOS as $c) {
            $m = MigracaoCrud::primeiro($p, 'menus', ['codigo' => $c]);
            if (!$m) continue;
            foreach (MigracaoCrud::selecionar($p, 'permissoes', ['codmenu' => (int)$m['codmenu']]) as $perm) {
                $k = (int)$perm['codperfil'];
                foreach ($flags as $f) $soma[$k][$f] = max((int)($soma[$k][$f] ?? 0), (int)($perm[$f] ?? 0));
            }
        }
        $soma = array_filter($soma, static fn($v) => array_sum($v) > 0);
        $novo = MigracaoCrud::primeiro($p, 'menus', ['codigo' => NOVO]);
        $linhas = [];
        foreach ($soma as $codperfil => $valores) {
            $marcados = array_keys(array_filter($valores));
            $dest = $novo ? MigracaoCrud::primeiro($p, 'permissoes', ['codperfil' => $codperfil, 'codmenu' => (int)$novo['codmenu']]) : null;
            $linhas[] = ($perfis[$codperfil] ?? '#' . $codperfil) . ': ' . ($dest ? 'junta' : 'inclui') . ' (' . implode(', ', $marcados) . ')';
            if (!$aplicar) continue;
            if (!$novo) throw new RuntimeException("$banco: o menu " . NOVO . ' não foi replicado neste banco');
            if ($dest) {
                $juntos = [];
                foreach ($flags as $f) $juntos[$f] = max((int)($dest[$f] ?? 0), (int)($valores[$f] ?? 0));
                MigracaoCrud::atualizar($p, 'permissoes', ['codpermissao' => (int)$dest['codpermissao']], $juntos);
            } else {
                MigracaoCrud::inserir($p, 'permissoes', ['codperfil' => $codperfil, 'codmenu' => (int)$novo['codmenu']] + $valores);
            }
        }
        $rel['empresas'][$banco] = $linhas ?: 'nenhum perfil com acesso às duas telas antigas (só o ADMINISTRADOR, que vê tudo)';
    }

    // ------------------------------------------------------------ 3) desliga os itens antigos
    foreach ($base as $c => $m) {
        if ((int)($m['ativo'] ?? 1) === 0) { $rel['antigos'][$c] = 'já desligado'; continue; }
        $rel['antigos'][$c] = $aplicar ? $cat->gravar(['codigo' => $c, 'ativo' => 0], (int)$m['codmenu']) : 'será desligado (ativo = 0) no catálogo e nas empresas';
    }
    if ($aplicar) $rel['aviso'] = 'Quem estava logado precisa sair e entrar de novo para ver o menu novo.';
} catch (Throwable $e) {
    $rel['ok'] = false;
    $rel['erro'] = $e->getMessage();
}

$logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (is_dir($logDir)) @file_put_contents($logDir . DIRECTORY_SEPARATOR . 'migracao_396_tipos_palavras_chave' . ($aplicar ? '' : '_simulacao') . '.json', json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
