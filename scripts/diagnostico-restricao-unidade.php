<?php
declare(strict_types=1);

/*
 * Diagnóstico (SÓ LEITURA) — Homologação de Fornecedores: restrição por unidade do usuário interno (19/10/2026).
 * Legado: Gestão de Fornecedores (fo_fornecedor.php), árvore da Gestão de Funcionários e Portaria filtram
 * CODUNIDADE in (usuariosxunidades do usuário) para quem não é ADMINISTRADOR. Greenportal: só a Portaria filtra
 * (vw_portaria_usuario_unidades, migração 260); Gestão, Painel, Relatórios, Ficha e Funcionários mostram tudo.
 * Para cada banco: perfis dos usuários; internos (não ADMINISTRADOR e não fornecedor externo) com e sem unidade;
 * unidades do usuário no greenportal × legado (<legado>_seguranca.usuariosxunidades); fornecedores ativos sem unidade
 * válida; quantos fornecedores cada interno passaria a ver; colunas do usuário no legado que definem o administrador.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-restricao-unidade.php [--banco=gatlas_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

try { $alvos = HomologacaoBancos::lista($argv ?? []); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
$env = GreenPortalDatabase::env();
$pdo = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$v = static fn(string $sql, array $p = []) => ($r = $q($sql, $p)) ? array_values($r[0])[0] : null;
$existe = static fn(string $db, string $t): bool => (int)$v('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db, $t]) > 0;
$colunas = static fn(string $db, string $t): array => array_column($q('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$db, $t]), 'COLUMN_NAME');
$tenta = static function (callable $f) { try { return $f(); } catch (Throwable $e) { return ['erro' => mb_substr($e->getMessage(), 0, 200)]; } };

$saida = ['gerado_em' => date('c'), 'bancos' => []];
foreach ($alvos as $banco) {
    if (!$existe($banco, 'usuarios')) { $saida['bancos'][$banco] = ['situacao' => 'banco ou tabela usuarios não existe']; continue; }
    $leg = (string)HomologacaoBancos::legado($banco); $seg = $leg . '_seguranca';
    $G = "`$banco`"; $S = "`$seg`";
    $r = ['legado' => $leg];
    $temVw = $existe($banco, 'vw_portaria_usuario_unidades');
    $uu = $temVw ? "$G.vw_portaria_usuario_unidades" : null;
    $perfilExpr = "UPPER(REPLACE(REPLACE(TRIM(COALESCE(p.codigo, '')), ' ', '_'), '-', '_'))";
    $interno = "$perfilExpr NOT IN ('ADMINISTRADOR', 'FORNECEDOR', 'FORNECEDOR_GESTOR')";

    // 1. Perfis dos usuários ativos
    $r['perfis_usuarios_ativos'] = $tenta(fn() => array_column($q("SELECT COALESCE(NULLIF(TRIM(p.codigo), ''), '(sem perfil)') AS perfil, COUNT(*) AS n
        FROM $G.usuarios u LEFT JOIN $G.perfis p ON p.codperfil = u.codperfil WHERE u.ativo = 1 GROUP BY 1 ORDER BY n DESC"), 'n', 'perfil'));

    // 2. Internos ativos com e sem unidade
    if ($uu) {
        $r['internos_ativos'] = $tenta(fn() => $q("SELECT COUNT(*) AS total,
                SUM(EXISTS (SELECT 1 FROM $uu x WHERE x.CODUSUARIO = u.codusuario)) AS com_unidade,
                SUM(NOT EXISTS (SELECT 1 FROM $uu x WHERE x.CODUSUARIO = u.codusuario)) AS sem_unidade
              FROM $G.usuarios u LEFT JOIN $G.perfis p ON p.codperfil = u.codperfil WHERE u.ativo = 1 AND $interno")[0]);
        $r['internos_sem_unidade_por_perfil'] = $tenta(fn() => array_column($q("SELECT COALESCE(NULLIF(TRIM(p.codigo), ''), '(sem perfil)') AS perfil, COUNT(*) AS n
              FROM $G.usuarios u LEFT JOIN $G.perfis p ON p.codperfil = u.codperfil
             WHERE u.ativo = 1 AND $interno AND NOT EXISTS (SELECT 1 FROM $uu x WHERE x.CODUSUARIO = u.codusuario) GROUP BY 1 ORDER BY n DESC"), 'n', 'perfil'));
        // responsáveis por documento hoje (são os que mais usam a homologação) sem unidade
        $r['responsaveis_documento_sem_unidade'] = $tenta(fn() => (int)$v("SELECT COUNT(DISTINCT u.codusuario) FROM $G.fo_fornecedorxcriterios d JOIN $G.usuarios u ON u.codusuario = d.codusuario
              LEFT JOIN $G.perfis p ON p.codperfil = u.codperfil
             WHERE u.ativo = 1 AND $interno AND NOT EXISTS (SELECT 1 FROM $uu x WHERE x.CODUSUARIO = u.codusuario)"));
        $r['origem_unidades'] = $tenta(fn() => $q("SELECT VIEW_DEFINITION LIKE '%usuariosxunidades%' AS usa_usuariosxunidades, VIEW_DEFINITION LIKE '%codunidade%' AS usa_usuarios_codunidade FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'vw_portaria_usuario_unidades'", [$banco])[0] ?? []);
    } else $r['internos_ativos'] = 'vw_portaria_usuario_unidades não existe (migração 260 não aplicada)';

    // 3. Unidades do usuário: greenportal × legado
    $r['usuariosxunidades'] = $tenta(function () use ($existe, $banco, $seg, $G, $S, $v, $colunas) {
        $o = ['greenportal' => $existe($banco, 'usuariosxunidades') ? (int)$v("SELECT COUNT(*) FROM $G.usuariosxunidades") : 'não existe',
              'legado' => $existe($seg, 'usuariosxunidades') ? (int)$v("SELECT COUNT(*) FROM $S.usuariosxunidades") : 'não existe (ou sem acesso)'];
        if (is_int($o['greenportal']) && is_int($o['legado'])) {
            $o['so_no_legado'] = (int)$v("SELECT COUNT(*) FROM (SELECT DISTINCT codusuario, codunidade FROM $S.usuariosxunidades) s WHERE NOT EXISTS (SELECT 1 FROM $G.usuariosxunidades g WHERE g.codusuario = s.codusuario AND g.codunidade = s.codunidade)");
            $o['so_no_greenportal'] = (int)$v("SELECT COUNT(*) FROM (SELECT DISTINCT codusuario, codunidade FROM $G.usuariosxunidades) g WHERE NOT EXISTS (SELECT 1 FROM $S.usuariosxunidades s WHERE s.codusuario = g.codusuario AND s.codunidade = g.codunidade)");
            $o['usuarios_legado_com_unidade'] = (int)$v("SELECT COUNT(DISTINCT codusuario) FROM $S.usuariosxunidades");
            $o['usuarios_legado_sem_usuario_no_greenportal'] = (int)$v("SELECT COUNT(DISTINCT s.codusuario) FROM $S.usuariosxunidades s WHERE NOT EXISTS (SELECT 1 FROM $G.usuarios u WHERE u.codusuario = s.codusuario)");
        }
        if ($existe($seg, 'usuarios')) $o['colunas_usuario_legado'] = array_values(array_filter($colunas($seg, 'usuarios'), static fn($c) => preg_match('/admin|grupo|perfil|unidade|fornecedor|ativ/i', $c)));
        return $o;
    });
    // Administrador no legado: colunas com "admin" em <legado>_seguranca.usuarios (contagem por valor, só usuários ativos se houver "ativado")
    $r['administrador_legado'] = $tenta(function () use ($existe, $seg, $S, $q, $colunas) {
        if (!$existe($seg, 'usuarios')) return 'sem acesso a ' . $seg . '.usuarios';
        $cols = $colunas($seg, 'usuarios'); $o = [];
        $ativo = in_array('ativado', array_map('strtolower', $cols), true) ? " WHERE ativado = 'S'" : '';
        foreach ($cols as $c) if (preg_match('/admin/i', $c)) $o[$c] = array_column($q("SELECT CAST(`$c` AS CHAR) AS valor, COUNT(*) AS n FROM $S.usuarios$ativo GROUP BY 1 ORDER BY n DESC LIMIT 10"), 'n', 'valor');
        if ($existe($seg, 'grupos')) {
            try { $o['grupos_com_adm_no_nome'] = $q("SELECT g.* FROM $S.grupos g WHERE CONCAT_WS(' ', g.dscgrupo) LIKE '%ADM%' LIMIT 10"); }
            catch (Throwable $e) { $o['grupos_colunas'] = $colunas($seg, 'grupos'); }
        }
        return $o ?: 'nenhuma coluna com "admin" em usuarios';
    });

    // 4. Fornecedores ativos e unidade
    $ativo = "LOWER(TRIM(COALESCE(f.ativadofor, ''))) IN ('sim', 's', '1')";
    $r['fornecedores_ativos'] = $tenta(fn() => $q("SELECT COUNT(*) AS total,
            SUM(f.codunidade IS NULL OR f.codunidade = 0) AS sem_unidade,
            SUM(f.codunidade > 0 AND NOT EXISTS (SELECT 1 FROM $G.unidades un WHERE un.codunidade = f.codunidade)) AS unidade_inexistente,
            COUNT(DISTINCT f.codunidade) AS unidades_distintas
          FROM $G.fo_fornecedores f WHERE $ativo")[0]);
    if ($uu) {
        $r['fornecedores_ativos_sem_nenhum_interno_da_unidade'] = $tenta(fn() => (int)$v("SELECT COUNT(*) FROM $G.fo_fornecedores f WHERE $ativo
            AND NOT EXISTS (SELECT 1 FROM $uu x JOIN $G.usuarios u ON u.codusuario = x.CODUSUARIO AND u.ativo = 1 LEFT JOIN $G.perfis p ON p.codperfil = u.codperfil WHERE x.CODUNIDADE = f.codunidade AND $interno)"));
        // 5. Simulação: quantos fornecedores ativos cada interno com unidade veria
        $r['simulacao_internos_com_unidade'] = $tenta(fn() => $q("SELECT COUNT(*) AS usuarios, MIN(n) AS minimo, ROUND(AVG(n), 1) AS media, MAX(n) AS maximo, SUM(n = 0) AS veriam_nenhum,
                (SELECT COUNT(*) FROM $G.fo_fornecedores f WHERE $ativo) AS total_ativos
              FROM (SELECT u.codusuario, (SELECT COUNT(*) FROM $G.fo_fornecedores f WHERE $ativo AND f.codunidade IN (SELECT x.CODUNIDADE FROM $uu x WHERE x.CODUSUARIO = u.codusuario)) AS n
                      FROM $G.usuarios u LEFT JOIN $G.perfis p ON p.codperfil = u.codperfil
                     WHERE u.ativo = 1 AND $interno AND EXISTS (SELECT 1 FROM $uu x WHERE x.CODUSUARIO = u.codusuario)) t")[0]);
        // responsáveis de documentos que deixariam de ver documentos pelos quais respondem (fornecedor fora das unidades deles)
        $r['documentos_de_responsavel_fora_da_unidade'] = $tenta(fn() => $q("SELECT COUNT(*) AS documentos, COUNT(DISTINCT d.codusuario) AS usuarios
              FROM $G.fo_fornecedorxcriterios d JOIN $G.fo_fornecedores f ON f.codfornecedor = d.codfornecedor AND $ativo
              JOIN $G.usuarios u ON u.codusuario = d.codusuario AND u.ativo = 1 LEFT JOIN $G.perfis p ON p.codperfil = u.codperfil
             WHERE $interno AND NOT EXISTS (SELECT 1 FROM $uu x WHERE x.CODUSUARIO = d.codusuario AND x.CODUNIDADE = f.codunidade)")[0]);
    }
    $saida['bancos'][$banco] = $r;
}

$json = json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'diagnostico_restricao_unidade.json', $json);
echo $json . PHP_EOL;
