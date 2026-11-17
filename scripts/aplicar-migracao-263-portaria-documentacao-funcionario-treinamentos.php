<?php
declare(strict_types=1);

/*
 * Migração 263 — Portaria › Documentação de Funcionário › Treinamentos (botão "Treinamentos" em Associações).
 * Legado: aba Treinamento, transação foFuncionariosxTreinamentos (hpr_configuracoes, codtransacao 196).
 * Pré-requisito: migração 260. Usa a permissão da Documentação de Funcionário (PORTARIA_DOCUMENTACAO_FUNCIONARIO):
 * nada muda em Segurança › Permissões.
 *
 * Em cada banco de cliente: views vw_portaria_documentacao_treinamento_grid / _acesso e a procedure
 * sp_portaria_doctreinamento_permitido (database/263_portaria_documentacao_funcionario_treinamentos.sql), e confere
 * o resultado (treinamentos, cores, funcionários com treinamento, pastas do GED de registros da tela).
 *
 * Uso:
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-263-portaria-documentacao-funcionario-treinamentos.php --simular
 *   E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-263-portaria-documentacao-funcionario-treinamentos.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$sql = (string)preg_replace('/^--(?! @@).*$/m', '', (string)file_get_contents(dirname(__DIR__) . '/database/263_portaria_documentacao_funcionario_treinamentos.sql'));
$relatorio = ['ok' => true, 'migracao' => '263', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'bancos' => []];

foreach (HomologacaoBancos::legados($argv ?? []) as $banco => $legado) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $um = static function (string $q, array $p = []) use ($pdo): mixed { $s = $pdo->prepare($q); $s->execute($p); $v = $s->fetchColumn(); $s->closeCursor(); return $v; };
        if (!(int)$um("SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vw_portaria_usuario_unidades'")) throw new RuntimeException('rode antes a migração 260');
        $info['registros_tabela'] = (int)$um('SELECT COUNT(*) FROM fo_funcionarioxtiposxtreinamentos');
        if (!$simular) {
            foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) { $cmd = trim($bloco); if ($cmd !== '') $pdo->exec($cmd); }
            $info['estrutura'] = 'views e procedure gravadas';
            $info['linhas_na_view'] = (int)$um('SELECT COUNT(*) FROM vw_portaria_documentacao_treinamento_grid');
            $info['funcionarios_com_treinamento'] = (int)$um('SELECT COUNT(DISTINCT CODFUNCIONARIO) FROM vw_portaria_documentacao_treinamento_grid');
            $info['sem_unidade'] = (int)$um('SELECT COUNT(*) FROM vw_portaria_documentacao_treinamento_grid WHERE CODUNIDADE IS NULL');
            $s = $pdo->query('SELECT SITUACAO_COR c, COUNT(*) n FROM vw_portaria_documentacao_treinamento_grid GROUP BY SITUACAO_COR ORDER BY n DESC');
            $info['cores'] = array_column($s->fetchAll(PDO::FETCH_ASSOC), 'n', 'c');
            $rep = (int)$um('SELECT COUNT(*) FROM (SELECT CODIGO FROM vw_portaria_documentacao_treinamento_grid GROUP BY CODIGO HAVING COUNT(*) > 1) x');
            if ($rep) { $relatorio['ok'] = false; $info['erro_repetidos'] = "$rep códigos repetidos"; }
            // Funcionário de exemplo da Documentação de Funcionário que tem treinamentos
            $ex = $pdo->query('SELECT d.CODIGO, d.FUNCIONARIO, COUNT(t.CODIGO) n FROM vw_portaria_documentacao_funcionario_grid d JOIN vw_portaria_documentacao_treinamento_grid t ON t.CODFUNCIONARIO = d.CODFUNCIONARIO GROUP BY d.CODIGO, d.FUNCIONARIO ORDER BY n DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            $info['exemplo'] = $ex ? ['linha_documentacao' => (int)$ex['CODIGO'], 'funcionario' => $ex['FUNCIONARIO'], 'treinamentos' => (int)$ex['n']] : 'nenhum';
            $raiz = rtrim((string)($env['GGED_LEGACY_ROOT'] ?? 'E:\\projetoGreen\\GED'), '\\/') . DIRECTORY_SEPARATOR . $legado . DIRECTORY_SEPARATOR . 'fo_funcionarioxtiposxtreinamentos';
            $pastas = 0; $daTela = 0;
            foreach (glob($raiz . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $d) { $id = (int)basename($d); if ($id < 1) continue; $pastas++; if ((int)$um('SELECT COUNT(*) FROM vw_portaria_documentacao_treinamento_grid WHERE CODIGO = ?', [$id])) $daTela++; }
            $info['ged'] = is_dir($raiz) ? "$pastas pastas, $daTela de treinamentos da tela" : 'pasta não existe';
        }
    } catch (Throwable $e) { $relatorio['ok'] = false; $info['erro'] = $e->getMessage(); }
    $relatorio['bancos'][$banco] = $info;
}
$json = json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents(dirname(__DIR__) . '/storage/logs/migracao_263_portaria_documentacao_funcionario_treinamentos' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($relatorio['ok'] ? 0 : 1);
