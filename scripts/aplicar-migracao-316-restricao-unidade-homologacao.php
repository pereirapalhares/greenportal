<?php
declare(strict_types=1);

/*
 * Migração 316 — Homologação de Fornecedores: restrição por unidade do usuário interno (19/10/2026).
 * Regra (legado fo_fornecedor.php / fo_fornecedores_tree.php; decisões do usuário em 19/10/2026):
 *   ADMINISTRADOR e ADM_DA_EMPRESA veem todos os fornecedores; os demais perfis internos veem os fornecedores das suas
 *   unidades (usuariosxunidades) e os fornecedores pelos quais respondem; sem unidade, só esses. Fornecedor externo: 296.
 * Vale para Gestão de Fornecedores, Fornecedores desativados, Gestão de Funcionários (e abas), Painel, Ficha, Relatórios,
 * envio semanal dos relatórios, planilhas e evidências (GED).
 *
 * Em cada banco de cliente da homologação:
 *  1. `usuariosxunidades`: cria no greenportal quando não existe (só a Atlas tinha) e copia do legado
 *     (<legado>_seguranca.usuariosxunidades) os pares usuário × unidade que faltam. Nada é apagado. Depois, o sincronismo
 *     diário (scripts/sincronizar-fo-legado.php) mantém a tabela igual à do legado até a virada;
 *  2. reaplica a migração 260 (vw_portaria_usuario_unidades passa a ler usuariosxunidades; antes, sem a tabela, só
 *     usuarios.codunidade — vazio na Crown, MODELO2, Americana e Anchieta, e a Portaria não mostrava nada aos não administradores);
 *  3. views e procedimento de database/316_restricao_unidade_homologacao.sql;
 *  4. recria sp_fo_painel_itens (298) e sp_fo_relatorio_itens / _contagens / _unificado (314), que aceitam p_externo = 2.
 * Pode ser executada mais de uma vez. Com --simular, só mostra o que faria (nada é gravado).
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-316-restricao-unidade-homologacao.php [--simular] [--banco=gatlas_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$simular = in_array('--simular', $argv ?? [], true);
try { $alvos = HomologacaoBancos::lista($argv ?? []); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
$env = GreenPortalDatabase::env();
$is = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$is->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = static fn(string $n): string => '`' . str_replace('`', '``', $n) . '`';
$valor = static function (PDO $pdo, string $sql, array $p = []): mixed { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchColumn(); $s->closeCursor(); return $r; };
$linhas = static function (PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
$objeto = static fn(string $db, string $nome): bool => (int)$valor($is, 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db, $nome]) > 0;
$baseTable = static fn(string $db, string $nome): bool => (int)$valor($is, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'", [$db, $nome]) > 0;
$coluna = static function (string $db, string $t, string $c) use ($valor, $is): ?string {
    $r = $valor($is, 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND LOWER(COLUMN_NAME) = LOWER(?)', [$db, $t, $c]);
    return $r === false || $r === null ? null : (string)$r;
};

$blocos = static function (string $arquivo): array {
    $out = [];
    foreach (preg_split('/^-- @@\s*$/m', (string)file_get_contents(dirname(__DIR__) . '/database/' . $arquivo)) as $b) {
        $limpo = trim(preg_replace('/^\s*--.*$/m', '', $b) ?? '');
        if ($limpo !== '') $out[] = $limpo;
    }
    return $out;
};
$sql316 = $blocos('316_restricao_unidade_homologacao.sql');
$pega = static fn(array $bs, string $nome): array => array_values(array_filter($bs, static fn($b) => str_contains($b, '`' . $nome . '`')));
$b298 = $blocos('298_painel_homologacao.sql'); $b314 = $blocos('314_relatorios_homologacao.sql');
$procs = array_merge($pega($b298, 'sp_fo_painel_itens'), $pega($b314, 'sp_fo_relatorio_itens'), $pega($b314, 'sp_fo_relatorio_contagens'), $pega($b314, 'sp_fo_relatorio_unificado'));
if (count($procs) !== 8 || !str_contains(implode("\n", $procs), 'vw_homologacao_fornecedores_acesso_interno') || count($sql316) < 8) {
    fwrite(STDERR, "Arquivos 298/314/316 não estão na versão da migração 316 (procedimentos encontrados: " . count($procs) . ").\n"); exit(1);
}

$rel = ['ok' => true, 'migracao' => '316', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
foreach ($alvos as $banco) {
    if (!(int)$valor($is, 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$banco])) {
        if ($banco !== 'gmodelo2_dbp') { $rel['ok'] = false; $rel['bancos'][$banco] = ['erro' => 'banco não existe']; }
        continue;
    }
    $info = [];
    try {
        foreach (['usuarios', 'perfis', 'fo_fornecedores', 'fo_fornecedorxcriterios', 'fo_funcionarios', 'fo_funcionarioxtiposxcriterios', 'fo_funcionarioxtiposxtreinamentos', 'fo_planodeacao',
                  'vw_unidades_grid', 'vw_homologacao_fornecedores_ativos_grid', 'vw_homologacao_fornecedores_desativados_grid', 'vw_homologacao_funcionarios_grid', 'vw_homologacao_painel_itens'] as $t)
            if (!$objeto($banco, $t)) throw new RuntimeException("$t não existe (aplique antes as migrações 260, 296 e 298)");
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci');

        // 1. usuariosxunidades do legado
        $seg = HomologacaoBancos::legado($banco) . '_seguranca';
        $temLegado = $baseTable($seg, 'usuariosxunidades');
        $temNovo = $baseTable($banco, 'usuariosxunidades');
        $u = ['legado' => $temLegado ? (int)$valor($is, "SELECT COUNT(*) FROM {$q($seg)}.usuariosxunidades") : "$seg.usuariosxunidades não existe (ou sem acesso)",
              'greenportal_antes' => $temNovo ? (int)$valor($pdo, 'SELECT COUNT(*) FROM usuariosxunidades') : 'não existe'];
        if (!$temNovo) {
            $u['tabela'] = $simular ? 'seria criada' : 'criada';
            if (!$simular) $pdo->exec('CREATE TABLE IF NOT EXISTS `usuariosxunidades` (`codusuario` INT NOT NULL, `codunidade` INT NOT NULL, PRIMARY KEY (`codusuario`, `codunidade`), KEY `ix_usuariosxunidades_unidade` (`codunidade`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
        }
        if ($temLegado) {
            $lu = $coluna($seg, 'usuariosxunidades', 'codusuario'); $lun = $coluna($seg, 'usuariosxunidades', 'codunidade');
            if (!$lu || !$lun) throw new RuntimeException("$seg.usuariosxunidades sem codusuario/codunidade");
            $gu = $temNovo ? ($coluna($banco, 'usuariosxunidades', 'codusuario') ?? 'codusuario') : 'codusuario';
            $gun = $temNovo ? ($coluna($banco, 'usuariosxunidades', 'codunidade') ?? 'codunidade') : 'codunidade';
            $origem = "SELECT DISTINCT s.{$q($lu)} AS cu, s.{$q($lun)} AS cun FROM {$q($seg)}.usuariosxunidades s WHERE s.{$q($lu)} > 0 AND s.{$q($lun)} > 0";
            if ($simular && !$temNovo) $u['seriam_copiados'] = (int)$valor($pdo, "SELECT COUNT(*) FROM ($origem) o");
            else {
                $faltam = "FROM ($origem) o WHERE NOT EXISTS (SELECT 1 FROM `usuariosxunidades` g WHERE g.{$q($gu)} = o.cu AND g.{$q($gun)} = o.cun)";
                if ($simular) $u['seriam_copiados'] = (int)$valor($pdo, "SELECT COUNT(*) $faltam");
                else $u['copiados'] = (int)$pdo->exec("INSERT INTO `usuariosxunidades` ({$q($gu)}, {$q($gun)}) SELECT o.cu, o.cun $faltam");
            }
            if (!$simular || $temNovo) $u['so_no_greenportal'] = (int)$valor($pdo, "SELECT COUNT(*) FROM `usuariosxunidades` g WHERE NOT EXISTS (SELECT 1 FROM {$q($seg)}.usuariosxunidades s WHERE s.{$q($lu)} = g.{$q($gu)} AND s.{$q($lun)} = g.{$q($gun)})");
            $u['usuarios_do_legado_sem_cadastro_no_greenportal'] = (int)$valor($pdo, "SELECT COUNT(DISTINCT o.cu) FROM ($origem) o WHERE NOT EXISTS (SELECT 1 FROM `usuarios` x WHERE x.codusuario = o.cu)");
        }
        if (!$simular) $u['greenportal_depois'] = (int)$valor($pdo, 'SELECT COUNT(*) FROM usuariosxunidades');
        $info['usuariosxunidades'] = $u;

        // 2. migração 260 (vw_portaria_usuario_unidades com usuariosxunidades)
        if (!$simular) {
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'aplicar-migracao-260-portaria-documentacao-fornecedor.php') . ' --sem-permissoes --banco=' . escapeshellarg($banco);
            $saida = []; $rc = 0;
            exec($cmd . ' 2>&1', $saida, $rc);
            $j = json_decode(implode("\n", $saida), true);
            $b260 = is_array($j) ? ($j['bancos'][$banco] ?? []) : [];
            $info['migracao_260'] = $rc === 0 ? ['unidades_do_usuario' => $b260['colunas']['unidades_do_usuario'] ?? '?', 'usuarios_com_unidade' => $b260['usuarios_com_unidade'] ?? null]
                : ['erro' => mb_substr(implode(' ', $saida), 0, 400)];
            if ($rc !== 0) throw new RuntimeException('migração 260 falhou: ' . mb_substr(implode(' ', $saida), 0, 300));
        } else $info['migracao_260'] = 'seria reaplicada (vw_portaria_usuario_unidades passa a ler usuariosxunidades)';

        // 3. views e procedimento da 316; 4. procedimentos do Painel e dos Relatórios
        $resp = $objeto($banco, 'vw_homologacao_responsabilidade_fornecedor')
            ? 'UNION SELECT r.CODUSUARIO, r.CODFORNECEDOR FROM `vw_homologacao_responsabilidade_fornecedor` r WHERE r.CODUSUARIO > 0' : '';
        $info['responsabilidades_atribuidas'] = $resp !== '' ? 'incluídas (vw_homologacao_responsabilidade_fornecedor)' : 'view 312 não existe: não incluídas';
        if (!$simular) {
            foreach ($sql316 as $b) $pdo->exec(str_replace('{{RESPONSABILIDADES}}', $resp, $b));
            foreach ($procs as $b) $pdo->exec($b);
            $info['estrutura'] = 'views, procedimento e procedimentos do Painel/Relatórios gravados';
        }

        // Resumo do efeito (depois de aplicar)
        if (!$simular) {
            $info['usuarios_ativos_por_modo'] = array_column($linhas($pdo, "SELECT CASE a.MODO WHEN 0 THEN 'vê tudo (ADMINISTRADOR, ADM_DA_EMPRESA)' WHEN 1 THEN 'fornecedor externo' ELSE 'interno restrito por unidade' END AS modo, COUNT(*) AS n
                FROM vw_homologacao_usuario_acesso a JOIN usuarios u ON u.codusuario = a.CODUSUARIO WHERE u.ativo = 1 GROUP BY a.MODO ORDER BY a.MODO"), 'n', 'modo');
            $info['internos_restritos'] = array_map('intval', $linhas($pdo, "SELECT COUNT(*) AS usuarios,
                    SUM(EXISTS (SELECT 1 FROM vw_portaria_usuario_unidades x WHERE x.CODUSUARIO = a.CODUSUARIO)) AS com_unidade,
                    SUM(NOT EXISTS (SELECT 1 FROM vw_homologacao_fornecedores_acesso_interno x WHERE x.CODUSUARIO = a.CODUSUARIO)) AS sem_nenhum_fornecedor
                  FROM vw_homologacao_usuario_acesso a JOIN usuarios u ON u.codusuario = a.CODUSUARIO WHERE u.ativo = 1 AND a.MODO = 2")[0] ?? []);
            $info['fornecedores_ativos_sem_interno_restrito'] = (int)$valor($pdo, "SELECT COUNT(*) FROM vw_homologacao_fornecedores_ativos_grid g WHERE NOT EXISTS (
                    SELECT 1 FROM vw_homologacao_fornecedores_acesso_interno x JOIN vw_homologacao_usuario_acesso a ON a.CODUSUARIO = x.CODUSUARIO AND a.MODO = 2
                      JOIN usuarios u ON u.codusuario = x.CODUSUARIO AND u.ativo = 1 WHERE x.CODFORNECEDOR = g.CODFORNECEDOR)");
            $info['obs'] = 'fornecedores_ativos_sem_interno_restrito: só ADMINISTRADOR / ADM_DA_EMPRESA os veem (ex.: fornecedor sem unidade)';
        }
        $rel['bancos'][$banco] = $info;
    } catch (Throwable $e) { $rel['ok'] = false; $rel['bancos'][$banco] = $info + ['erro' => $e->getMessage()]; }
}

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
@file_put_contents($dir . DIRECTORY_SEPARATOR . 'migracao_316_restricao_unidade' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
