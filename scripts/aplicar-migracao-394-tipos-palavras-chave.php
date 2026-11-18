<?php
declare(strict_types=1);

/*
 * Migração 394 — Manutenção Legal › Tipos de palavras-chave e Tipos × Palavras-chave (18/11/2026), as transações
 * "Tipos de palavras chave" e "Tipos X Palavras Chaves" do legado.
 *
 * glegislacao_dbp (compartilhado):
 *   1. chaves codtipopalavrachave e CODTIPOXPALAVRACHAVE passam a AUTO_INCREMENT (para incluir pela tela);
 *   2. índice único (CODTIPOPALAVRACHAVE, CODPALAVRACHAVE) em tiposxpalavras_chaves, só se não houver repetidos;
 *   3. database/394_tipos_palavras_chave.sql: view vw_tipos_palavras_chaves_grid e gatilhos (nome obrigatório e sem repetir;
 *      não exclui tipo que ainda tem palavras).
 * Universal: menus TIPOS_PALAVRAS_CHAVE e TIPOS_X_PALAVRAS_CHAVE dentro de Manutenção Legal (replicados nas empresas) e a Ajuda.
 * Cada empresa: permissão dos menus novos = a do menu TIPOS (Manutenção Legal › Tipos) em cada perfil. Os dois menus ficam
 * também em PermissaoTransacao::MENUS_SO_ADMIN (como todo o grupo Manutenção Legal, migração 384).
 * Telas (já na pasta): principal/manutencaolegal_tipos_palavras_chave.php e principal/manutencaolegal_tiposxpalavras_chave.php,
 * classes/TiposPalavrasChave.php.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-394-tipos-palavras-chave.php [--simular] [--banco=gcrown_dbp]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoLeg = (string)($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp');
$rel = ['ok' => true, 'migracao' => '394', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'legislacao' => [], 'universal' => [], 'bancos' => []];
$menus = [
    'TIPOS_PALAVRAS_CHAVE' => ['Tipos de palavras-chave', 'Keyword types', 'Tipos de palabras clave', '/principal/manutencaolegal_tipos_palavras_chave.php', 'bi-tags'],
    'TIPOS_X_PALAVRAS_CHAVE' => ['Tipos × Palavras-chave', 'Types × Keywords', 'Tipos × Palabras clave', '/principal/manutencaolegal_tiposxpalavras_chave.php', 'bi-diagram-3'],
];

// ---------------------------------------------------------------- 1) glegislacao_dbp
try {
    $leg = GreenPortalDatabase::connect($env, $bancoLeg, 'GLEGISLACAO');
    $leg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $leg->exec('SET NAMES utf8mb4 COLLATE ' . (string)$leg->query('SELECT @@collation_database')->fetchColumn());
    $info = ['banco' => $bancoLeg];
    $extra = static function (string $t, string $c) use ($leg): string {
        $s = $leg->prepare('SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'); $s->execute([$t, $c]);
        return strtolower((string)$s->fetchColumn());
    };
    $info['tipos'] = $leg->query('SELECT codtipopalavrachave, dsctipopalavrachave FROM tipos_palavras_chaves ORDER BY 1')->fetchAll(PDO::FETCH_KEY_PAIR);
    $info['vinculos'] = (int)$leg->query('SELECT COUNT(*) FROM tiposxpalavras_chaves')->fetchColumn();
    $repetidos = (int)$leg->query('SELECT COUNT(*) FROM (SELECT 1 FROM tiposxpalavras_chaves GROUP BY CODTIPOPALAVRACHAVE, CODPALAVRACHAVE HAVING COUNT(*) > 1) z')->fetchColumn();
    $nomesRepetidos = $leg->query('SELECT dsctipopalavrachave, COUNT(*) n FROM tipos_palavras_chaves GROUP BY dsctipopalavrachave HAVING COUNT(*) > 1')->fetchAll(PDO::FETCH_KEY_PAIR);
    if ($nomesRepetidos) $info['aviso_nomes_repetidos'] = $nomesRepetidos;
    foreach ([['tipos_palavras_chaves', 'codtipopalavrachave'], ['tiposxpalavras_chaves', 'CODTIPOXPALAVRACHAVE']] as [$t, $c]) {
        if (str_contains($extra($t, $c), 'auto_increment')) { $info['auto_increment'][$t] = 'já era'; continue; }
        if ($simular) { $info['auto_increment'][$t] = 'será ativado'; continue; }
        $leg->exec("ALTER TABLE `$t` MODIFY `$c` INT NOT NULL AUTO_INCREMENT");
        $info['auto_increment'][$t] = str_contains($extra($t, $c), 'auto_increment') ? 'ativado' : 'FALHOU';
        if ($info['auto_increment'][$t] === 'FALHOU') $rel['ok'] = false;
    }
    $temUnico = (bool)$leg->query("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tiposxpalavras_chaves' AND INDEX_NAME = 'ux_tpc_tipo_palavra'")->fetchColumn();
    if ($temUnico) $info['indice_unico'] = 'já existia';
    elseif ($repetidos) $info['indice_unico'] = "NÃO criado: $repetidos par(es) tipo/palavra repetido(s) (a tela já impede novos)";
    elseif ($simular) $info['indice_unico'] = 'será criado';
    else { $leg->exec('ALTER TABLE `tiposxpalavras_chaves` ADD UNIQUE KEY `ux_tpc_tipo_palavra` (`CODTIPOPALAVRACHAVE`, `CODPALAVRACHAVE`)'); $info['indice_unico'] = 'criado'; }
    $sql = (string)file_get_contents(dirname(__DIR__) . '/database/394_tipos_palavras_chave.sql');
    $blocos = array_values(array_filter(array_map(static fn($b) => trim((string)preg_replace('/^--(?! @@).*$/m', '', $b)), preg_split('/^-- @@\s*$/m', $sql)), static fn($b) => $b !== ''));
    if ($simular) $info['blocos'] = count($blocos) . ' bloco(s) seriam executados (view e gatilhos)';
    else {
        foreach ($blocos as $i => $b) {
            try { $leg->exec($b); }
            catch (Throwable $e) { throw new RuntimeException('bloco ' . ($i + 1) . ' (' . mb_substr((string)preg_replace('/\s+/', ' ', $b), 0, 70) . '…): ' . $e->getMessage()); }
        }
        $info['blocos'] = count($blocos) . ' executado(s)';
        $info['conferencia'] = $leg->query('SELECT dsctipopalavrachave, QTD_PALAVRAS FROM vw_tipos_palavras_chaves_grid ORDER BY 1')->fetchAll(PDO::FETCH_KEY_PAIR);
        $info['gatilhos'] = (int)$leg->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'tipos_palavras_chaves' AND TRIGGER_NAME LIKE 'trg_tipos_palavras_chaves_%'")->fetchColumn() . ' de 3';
        if ($info['gatilhos'] !== '3 de 3') $rel['ok'] = false;
    }
    $rel['legislacao'] = $info;
} catch (Throwable $e) { $rel['ok'] = false; $rel['legislacao']['erro'] = $e->getMessage(); }

// ---------------------------------------------------------------- 2) universal: menus e ajuda
$cat = new MenuCatalogoService($env);
try {
    $u = $cat->universal();
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $irmao = MigracaoCrud::primeiro($u, 'menus', ['codigo' => 'TIPOS']);
    if (!$irmao) throw new RuntimeException('menu TIPOS (Manutenção Legal › Tipos) não encontrado no universal');
    $irmao = array_change_key_case($irmao);
    $ordem = 0;
    foreach (MigracaoCrud::selecionar($u, 'menus', ['codmenu_pai' => (int)$irmao['codmenu_pai']]) as $m) $ordem = max($ordem, (int)(array_change_key_case($m)['ordem'] ?? 0));
    foreach ($menus as $codigo => [$pt, $en, $es, $url, $icone]) {
        $existe = MigracaoCrud::primeiro($u, 'menus', ['codigo' => $codigo]);
        if ($existe) {
            if (!$simular) $cat->replicar($codigo, null, false);
            $rel['universal']['menus'][$codigo] = 'já existia (codmenu ' . $existe['codmenu'] . ')';
            continue;
        }
        if ($simular) { $rel['universal']['menus'][$codigo] = 'será criado em Manutenção Legal: ' . $pt; continue; }
        $g = $cat->gravar(['codmenu_pai' => (int)$irmao['codmenu_pai'], 'modulo' => $irmao['modulo'] ?? null, 'codigo' => $codigo,
            'label' => $pt, 'label_pt' => $pt, 'label_en' => $en, 'label_es' => $es, 'url' => $url, 'nivel' => (int)($irmao['nivel'] ?? 2),
            'ordem' => ++$ordem, 'icone' => $icone, 'ativo' => 1]);
        $erros = array_filter($g['empresas'], static fn($r) => str_starts_with((string)$r, 'ERRO'));
        $rel['universal']['menus'][$codigo] = 'criado (codmenu ' . $g['codmenu'] . ', ' . count($g['empresas']) . ' empresa(s)' . ($erros ? ', com erro em: ' . implode(', ', array_keys($erros)) : '') . ')';
        if ($erros) $rel['ok'] = false;
    }
    // Ajuda
    if (!MigracaoCrud::existe($u, 'ajuda_transacoes')) $rel['universal']['ajuda'] = 'sem ajuda_transacoes no universal';
    else foreach (require dirname(__DIR__) . '/database/394_ajuda_tipos_palavras_chave.php' as $transacao => $d) {
        $atual = MigracaoCrud::primeiro($u, 'ajuda_transacoes', ['transacao' => $transacao]);
        $dados = [];
        foreach (['pt', 'en', 'es'] as $lg) {
            $velho = trim((string)preg_replace('/\s*<!-- ml394 -->.*?<!-- \/ml394 -->/s', '', (string)($atual['texto_' . $lg] ?? '')));
            $dados['texto_' . $lg] = ($velho === '' ? '' : $velho . "\n") . "<!-- ml394 -->\n" . $d['texto'][$lg] . "\n<!-- /ml394 -->";
            if ($atual === null || trim((string)($atual['titulo_' . $lg] ?? '')) === '') $dados['titulo_' . $lg] = $d['titulo'][$lg];
        }
        if ($atual !== null) { $igual = (int)($atual['ativo'] ?? 1) === 1; foreach ($dados as $k => $v) if ((string)($atual[$k] ?? '') !== $v) $igual = false; if ($igual) { $rel['universal']['ajuda'][$transacao] = 'igual'; continue; } }
        if ($simular) { $rel['universal']['ajuda'][$transacao] = $atual === null ? 'será incluída' : 'será atualizada'; continue; }
        if ($atual === null) MigracaoCrud::inserir($u, 'ajuda_transacoes', ['transacao' => $transacao] + $dados + ['ativo' => 1]);
        else {
            $chave = ['transacao' => (string)$atual['transacao']];
            foreach (['id', 'codajuda', 'codigo', 'codajudatransacao'] as $pk) if (isset($atual[$pk]) && $atual[$pk] !== '') { $chave = [$pk => $atual[$pk]]; break; }
            MigracaoCrud::atualizar($u, 'ajuda_transacoes', $chave, $dados + ['ativo' => 1]);
        }
        $rel['universal']['ajuda'][$transacao] = $atual === null ? 'incluída' : 'atualizada';
    }
} catch (Throwable $e) { $rel['ok'] = false; $rel['universal']['erro'] = $e->getMessage(); }

// ---------------------------------------------------------------- 3) empresas: permissões = as do menu TIPOS
foreach ($cat->empresas() as $banco => $pdo) {
    if ($soBanco !== null && $banco !== $soBanco) continue;
    $info = [];
    try {
        $origem = MigracaoCrud::primeiro($pdo, 'menus', ['codigo' => 'TIPOS']);
        if (!$origem) { $info['permissao'] = 'sem o menu TIPOS neste banco: nada a copiar'; $rel['bancos'][$banco] = $info; continue; }
        foreach (array_keys($menus) as $codigo) {
            $novo = MigracaoCrud::primeiro($pdo, 'menus', ['codigo' => $codigo]);
            if (!$novo) { $info[$codigo] = $simular ? 'menu será replicado; permissões = as de Tipos' : 'menu ainda não replicado neste banco'; continue; }
            $cont = 0;
            foreach (['permissoes', 'perfil_menus'] as $t) {
                if (!MigracaoCrud::existe($pdo, $t)) continue;
                $cols = MigracaoCrud::colunas($pdo, $t);
                if (!in_array('codmenu', $cols, true) || !in_array('codperfil', $cols, true)) continue;
                foreach (MigracaoCrud::selecionar($pdo, $t, ['codmenu' => (int)$origem['codmenu']]) as $p) {
                    $p = array_change_key_case($p);
                    $flags = array_filter($p, static fn($k) => str_starts_with((string)$k, 'pode_'), ARRAY_FILTER_USE_KEY);
                    if ($flags && !array_filter($flags)) continue;
                    if (MigracaoCrud::primeiro($pdo, $t, ['codperfil' => (int)$p['codperfil'], 'codmenu' => (int)$novo['codmenu']])) continue;
                    if (!$simular) MigracaoCrud::inserir($pdo, $t, ['codperfil' => (int)$p['codperfil'], 'codmenu' => (int)$novo['codmenu']] + $flags);
                    $cont++;
                }
            }
            $info[$codigo] = $cont . ' permissão(ões) ' . ($simular ? 'seriam criadas' : 'criada(s)') . ' (as mesmas de Tipos)';
        }
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}

$rel['depois'] = 'os menus aparecem ao administrador no próximo login (ou ao abrir outra tela, quando a sessão é atualizada)';
$logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (is_dir($logDir)) @file_put_contents($logDir . DIRECTORY_SEPARATOR . 'migracao_394_tipos_palavras_chave' . ($simular ? '_simulacao' : '') . '.json', json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
