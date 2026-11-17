<?php
declare(strict_types=1);

/*
 * Migração 354 — item "Permissões" duplicado no menu (decisão do usuário de 11/11/2026: "Liberar para o perfil dela").
 *
 * Achado na verificação da Crown (11/11/2026, usuária Nathalia, ADM da empresa): Segurança › Permissões aparecia no menu,
 * mas a tela respondia 403. O item do menu dela era uma CÓPIA (código SEGURANCA_PERMISSOES_COPIA_<n>, criada pelo
 * "duplicar" de Setup › Menu) e o perfil tinha permissão na cópia; a tela confere o código original SEGURANCA_PERMISSOES.
 *
 * Na produção o original não existia no catálogo (a "cópia" era o único item de Permissões): nesse caso a cópia é renomeada
 * para SEGURANCA_PERMISSOES e as permissões dela continuam valendo (a mesma linha muda de código em cada empresa).
 * Se o original existir no catálogo, o que faz em cada banco de empresa:
 *   1. garante o item original SEGURANCA_PERMISSOES ligado (ativo) no catálogo universal e replicado nas empresas;
 *   2. passa para o original as permissões que cada perfil tem na cópia (junta: o que estiver marcado em qualquer um fica marcado);
 *   3. apaga as permissões da cópia e exclui a cópia do catálogo e das empresas (MenuCatalogoService::excluir).
 * Também lista as outras cópias de menu (código terminado em _COPIA_<n>) que existirem, sem mexer nelas.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-354-permissoes-menu-copia.php            (simulação)
 *      E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-354-permissoes-menu-copia.php --aplicar  (grava)
 * Depois de aplicar, quem estava logado precisa sair e entrar de novo (as permissões ficam na sessão).
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';

const ORIGINAL = 'SEGURANCA_PERMISSOES';
$aplicar = in_array('--aplicar', $argv ?? [], true);
$rel = ['ok' => true, 'migracao' => '354', 'modo' => $aplicar ? 'APLICAR' : 'SIMULACAO'];

$apagar = static function (PDO $p, string $tabela, array $chave): void {
    $s = $p->prepare('CALL sp_crud_delete(?, ?)');
    $s->execute([$tabela, json_encode($chave, JSON_UNESCAPED_UNICODE)]);
    $s->closeCursor();
};

try {
    $cat = new MenuCatalogoService();
    $u = $cat->universal();

    // Cópias no catálogo universal
    $copias = array_values(array_filter(MigracaoCrud::selecionar($u, 'menus'), static fn($m) => preg_match('/_COPIA_\d+$/i', (string)$m['codigo'])));
    $rel['copias_no_catalogo'] = array_map(static fn($m) => $m['codigo'] . ' (codmenu ' . $m['codmenu'] . ', ' . ($m['url'] ?? '') . ', ativo ' . $m['ativo'] . ')', $copias);
    $alvo = array_values(array_filter($copias, static fn($m) => str_starts_with(strtoupper((string)$m['codigo']), ORIGINAL . '_COPIA_')));
    $rel['outras_copias_nao_alteradas'] = array_values(array_map(static fn($m) => (string)$m['codigo'], array_filter($copias, static fn($m) => !in_array($m, $alvo, true))));
    if (!$alvo) { $rel['resultado'] = 'Nenhuma cópia de ' . ORIGINAL . ' no catálogo: nada a fazer.'; throw new RuntimeException('__fim__'); }

    $orig = MigracaoCrud::primeiro($u, 'menus', ['codigo' => ORIGINAL]);

    // Caso da produção (11/11/2026): o original não existe no catálogo e a "cópia" é o único item de Permissões.
    // Então a cópia é renomeada para o código original (MenuCatalogoService::gravar + replicar com o código antigo):
    // nas empresas a mesma linha muda de código e as permissões dela continuam valendo. Se alguma empresa ainda tiver uma
    // linha antiga com o código original, as permissões da cópia são juntadas nela e a linha da cópia sai.
    if (!$orig) {
        if (count($alvo) !== 1) throw new RuntimeException('O original ' . ORIGINAL . ' não existe no catálogo e há ' . count($alvo) . ' cópias: corrigir à mão em Setup › Menu.');
        $c = $alvo[0];
        $codCopia = strtoupper((string)$c['codigo']);
        $rel['acao'] = "renomear $codCopia para " . ORIGINAL . ' (codmenu ' . $c['codmenu'] . ' no catálogo)';
        $empresasComOriginal = [];
        foreach ($cat->empresas() as $banco => $p) {
            $mc = MigracaoCrud::primeiro($p, 'menus', ['codigo' => $codCopia]);
            $mo = MigracaoCrud::primeiro($p, 'menus', ['codigo' => ORIGINAL]);
            $n = $mc && MigracaoCrud::colunas($p, 'permissoes') ? count(MigracaoCrud::selecionar($p, 'permissoes', ['codmenu' => (int)$mc['codmenu']])) : 0;
            $rel['empresas'][$banco] = !$mc ? ($mo ? 'só tem o original (nada muda)' : 'não tem o item (será incluído)')
                : ($mo ? "tem a cópia ($n permissão(ões)) e o original: junta na linha do original e apaga a cópia" : "a linha da cópia passa a ter o código original ($n permissão(ões) mantidas)");
            if ($mc && $mo) $empresasComOriginal[$banco] = true;
        }
        if ($aplicar) {
            $rel['renomeado'] = $cat->gravar(['codigo' => ORIGINAL], (int)$c['codmenu']);
            foreach ($cat->empresas() as $banco => $p) {
                if (!isset($empresasComOriginal[$banco])) continue;
                $mc = MigracaoCrud::primeiro($p, 'menus', ['codigo' => $codCopia]);
                $mo = MigracaoCrud::primeiro($p, 'menus', ['codigo' => ORIGINAL]);
                if (!$mc || !$mo) continue;
                $flags = array_values(array_filter(MigracaoCrud::colunas($p, 'permissoes'), static fn($x) => str_starts_with($x, 'pode_')));
                foreach (MigracaoCrud::selecionar($p, 'permissoes', ['codmenu' => (int)$mc['codmenu']]) as $perm) {
                    $dest = MigracaoCrud::primeiro($p, 'permissoes', ['codperfil' => (int)$perm['codperfil'], 'codmenu' => (int)$mo['codmenu']]);
                    $novos = [];
                    foreach ($flags as $f) $novos[$f] = max((int)($perm[$f] ?? 0), (int)($dest[$f] ?? 0));
                    if ($dest) MigracaoCrud::atualizar($p, 'permissoes', ['codpermissao' => (int)$dest['codpermissao']], $novos);
                    elseif (array_sum($novos) > 0) MigracaoCrud::inserir($p, 'permissoes', ['codperfil' => (int)$perm['codperfil'], 'codmenu' => (int)$mo['codmenu']] + $novos);
                    $apagar($p, 'permissoes', ['codpermissao' => (int)$perm['codpermissao']]);
                }
                $apagar($p, 'menus', ['codmenu' => (int)$mc['codmenu']]);
                $rel['juntado'][$banco] = 'permissões da cópia juntadas no original; linha da cópia apagada';
            }
            $rel['aviso'] = 'Quem estava logado precisa sair e entrar de novo para ver o menu corrigido.';
        }
        throw new RuntimeException('__fim__');
    }

    // 1. Original ligado e presente em todas as empresas
    $rel['original'] = ORIGINAL . ' (codmenu ' . $orig['codmenu'] . ', ' . ($orig['url'] ?? '') . ', ativo ' . $orig['ativo'] . ')';
    if ($aplicar) {
        if ((int)$orig['ativo'] !== 1) $rel['original_acao'] = $cat->gravar(['codigo' => ORIGINAL, 'ativo' => 1], (int)$orig['codmenu']);
        else $rel['original_acao'] = ['replicado' => $cat->replicar(ORIGINAL, null, false)];
    } else {
        $rel['original_acao'] = (int)$orig['ativo'] !== 1 ? 'será ligado (ativo = 1) e replicado' : 'será conferido em todas as empresas (incluído onde faltar)';
    }

    // 2. Permissões da cópia → original, por empresa
    foreach ($cat->empresas() as $banco => $p) {
        $colsPerm = MigracaoCrud::colunas($p, 'permissoes');
        if (!$colsPerm) { $rel['empresas'][$banco] = 'sem tabela permissoes'; continue; }
        $flags = array_values(array_filter($colsPerm, static fn($c) => str_starts_with($c, 'pode_')));
        $perfis = [];
        foreach (MigracaoCrud::selecionar($p, 'perfis') as $pf) $perfis[(int)$pf['codperfil']] = (string)($pf['codigo'] ?? $pf['codperfil']);
        $mo = MigracaoCrud::primeiro($p, 'menus', ['codigo' => ORIGINAL]);
        $linhas = [];
        foreach ($alvo as $c) {
            $mc = MigracaoCrud::primeiro($p, 'menus', ['codigo' => strtoupper((string)$c['codigo'])]);
            if (!$mc) continue;
            foreach (MigracaoCrud::selecionar($p, 'permissoes', ['codmenu' => (int)$mc['codmenu']]) as $perm) {
                $marcados = array_values(array_filter($flags, static fn($f) => (int)($perm[$f] ?? 0) === 1));
                $perfil = $perfis[(int)$perm['codperfil']] ?? ('#' . $perm['codperfil']);
                if (!$marcados) { $linhas[] = "$perfil: sem acesso na cópia (só apaga)"; }
                else {
                    $dest = $mo ? MigracaoCrud::primeiro($p, 'permissoes', ['codperfil' => (int)$perm['codperfil'], 'codmenu' => (int)$mo['codmenu']]) : null;
                    $novos = [];
                    foreach ($flags as $f) $novos[$f] = max((int)($perm[$f] ?? 0), (int)($dest[$f] ?? 0));
                    $linhas[] = "$perfil: " . ($dest ? 'junta ao original' : 'inclui no original') . ' (' . implode(', ', $marcados) . ')';
                    if ($aplicar) {
                        $mo ??= MigracaoCrud::primeiro($p, 'menus', ['codigo' => ORIGINAL]);
                        if (!$mo) throw new RuntimeException("$banco: o original não foi replicado neste banco");
                        if ($dest) MigracaoCrud::atualizar($p, 'permissoes', ['codpermissao' => (int)$dest['codpermissao']], $novos);
                        else MigracaoCrud::inserir($p, 'permissoes', ['codperfil' => (int)$perm['codperfil'], 'codmenu' => (int)$mo['codmenu']] + $novos);
                    }
                }
                if ($aplicar) $apagar($p, 'permissoes', ['codpermissao' => (int)$perm['codpermissao']]);
            }
        }
        $rel['empresas'][$banco] = $linhas ?: 'sem permissões na cópia';
    }

    // 3. Exclui a cópia do catálogo e das empresas
    foreach ($alvo as $c) {
        $rel['exclusao'][(string)$c['codigo']] = $aplicar ? $cat->excluir((int)$c['codmenu']) : 'será excluída do catálogo e das empresas';
    }
    if ($aplicar) $rel['aviso'] = 'Quem estava logado precisa sair e entrar de novo para ver o menu corrigido.';
} catch (Throwable $e) {
    if ($e->getMessage() !== '__fim__') { $rel['ok'] = false; $rel['erro'] = $e->getMessage(); }
}

echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
