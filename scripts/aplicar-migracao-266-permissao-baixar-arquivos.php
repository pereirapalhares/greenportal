<?php
declare(strict_types=1);

/*
 * Migração 266 — permissão "Baixar/imprimir arquivos" (pode_baixar_arquivos) por perfil e transação.
 *
 * Sem ela o usuário só VÊ o arquivo no visualizador do sistema (principal/visualizador_arquivo.php): sem Salvar,
 * sem Imprimir e sem Download. Com ela aparecem os botões Baixar e Imprimir. Vale na Pesquisa de documentos e na
 * janela de Arquivos (GED) de todas as telas; a regra é a mesma do "Visualizar arquivos" (GedPermissaoTipo):
 * Gestão de Arquivos ou a transação da tela do anexo (Portaria).
 *
 * Em cada cliente (bancos *_dbp com menus, perfis e permissoes):
 *   - coluna pode_baixar_arquivos em permissoes (e em perfil_menus, quando ela tem as colunas de arquivos);
 *   - vw_permissoes_grid passa a trazer a coluna;
 *   - carga inicial (só quando a coluna é criada agora): ADM_DA_EMPRESA recebe a permissão nas transações em que
 *     já vê (Visualizar ou Visualizar arquivos). ADMINISTRADOR tem tudo por regra. Os demais perfis começam sem,
 *     e o administrador libera em Segurança › Permissões.
 * Os usuários veem a mudança no próximo login.
 *
 * Dados sempre por procedure (classes/MigracaoCrud.php: sp_crud_select / sp_crud_update); só a estrutura (coluna e view)
 * é feita por DDL direto.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-266-permissao-baixar-arquivos.php [--simular]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'perfis', 'permissoes') AND TABLE_SCHEMA LIKE '%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 3 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);

$relatorio = ['ok' => true, 'migracao' => '266', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];
foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $colunas = static function (string $tabela) use ($pdo): array {
            $q = $pdo->prepare('SELECT LOWER(COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $q->execute([$tabela]);
            return $q->fetchAll(PDO::FETCH_COLUMN);
        };

        if (!$simular && ($criadas = MigracaoCrud::garantir($pdo))) $info['procedures_crud_criadas'] = $criadas;
        // 1. Coluna
        $novaEmPermissoes = false;
        foreach (['permissoes', 'perfil_menus'] as $tabela) {
            $cols = $colunas($tabela);
            if (!$cols) continue;
            if (in_array('pode_baixar_arquivos', $cols, true)) { $info['coluna'][$tabela] = 'já existe'; continue; }
            if ($tabela === 'perfil_menus' && !in_array('pode_visualizar_arquivos', $cols, true)) { $info['coluna'][$tabela] = 'sem colunas de arquivos: não alterada'; continue; }
            $depois = in_array('pode_visualizar_arquivos', $cols, true) ? ' AFTER `pode_visualizar_arquivos`' : '';
            if (!$simular) $pdo->exec("ALTER TABLE `$tabela` ADD COLUMN `pode_baixar_arquivos` TINYINT(1) NOT NULL DEFAULT 0$depois");
            $info['coluna'][$tabela] = 'criada';
            if ($tabela === 'permissoes') $novaEmPermissoes = true;
        }

        // 2. View do grid de permissões (mantém a definição atual e acrescenta a coluna)
        $def = $pdo->query('SHOW CREATE VIEW `vw_permissoes_grid`')->fetch(PDO::FETCH_ASSOC);
        $sql = (string)($def['Create View'] ?? '');
        if ($sql === '') $info['view'] = 'vw_permissoes_grid não existe';
        elseif (stripos($sql, 'pode_baixar_arquivos') !== false) $info['view'] = 'já tem a coluna';
        else {
            $select = (string)preg_replace('/^.*?\bVIEW\s+`?vw_permissoes_grid`?\s+AS\s+/is', '', $sql);
            $item = '/(`?(\w+)`?\.`pode_visualizar_arquivos`(?:\s+AS\s+`pode_visualizar_arquivos`)?)/i';
            if (preg_match($item, $select, $m)) {
                $nova = (string)preg_replace($item, '$1, `' . $m[2] . '`.`pode_baixar_arquivos` AS `pode_baixar_arquivos`', $select, 1);
                $info['view'] = 'coluna acrescentada';
            } else {
                $nova = 'SELECT v.*, COALESCE(px.`pode_baixar_arquivos`, 0) AS `pode_baixar_arquivos` FROM (' . $select . ') v LEFT JOIN `permissoes` px ON px.`codpermissao` = v.`codpermissao`';
                $info['view'] = 'coluna acrescentada (por junção)';
            }
            if (!$simular) $pdo->exec('CREATE OR REPLACE VIEW `vw_permissoes_grid` AS ' . $nova);
        }

        // 3. Carga inicial
        if ($novaEmPermissoes) {
            if ($simular) $info['carga_inicial_adm_da_empresa'] = 'na aplicação';
            else {
                $n = 0;
                foreach (MigracaoCrud::selecionar($pdo, 'perfis') as $pf) {
                    if (strtoupper((string)$pf['codigo']) !== 'ADM_DA_EMPRESA') continue;
                    foreach (MigracaoCrud::selecionar($pdo, 'permissoes', ['codperfil' => (int)$pf['codperfil']]) as $pm)
                        if ((int)($pm['pode_visualizar'] ?? 0) === 1 || (int)($pm['pode_visualizar_arquivos'] ?? 0) === 1)
                            { MigracaoCrud::atualizar($pdo, 'permissoes', ['codpermissao' => (int)$pm['codpermissao']], ['pode_baixar_arquivos' => 1]); $n++; }
                }
                $info['carga_inicial_adm_da_empresa'] = $n . ' transações';
            }
        }
        if (!$simular || !$novaEmPermissoes) {
            $lista = [];
            foreach (MigracaoCrud::selecionar($pdo, 'perfis') as $pf) {
                $n = count(MigracaoCrud::selecionar($pdo, 'permissoes', ['codperfil' => (int)$pf['codperfil'], 'pode_baixar_arquivos' => 1]));
                if ($n) $lista[(string)$pf['codigo']] = $n;
            }
            ksort($lista);
            $info['perfis_com_a_permissao'] = $lista;
        }
    } catch (Throwable $e) {
        $relatorio['ok'] = false;
        $info['ERRO'] = $e->getMessage();
    }
    $relatorio['bancos'][$banco] = $info;
}
echo json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
