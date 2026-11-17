<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$target = GreenPortalDatabase::connect($env, 'ggdamericana_dbp', 'GPT');

$result = [
    'licencas' => (int) $target->query('SELECT COUNT(*) FROM li_licencas')->fetchColumn(),
    'relacionamentos' => (int) $target->query('SELECT COUNT(*) FROM legislacaoxlicencas')->fetchColumn(),
    'orfaos_legislacao' => (int) $target->query('SELECT COUNT(*) FROM legislacaoxlicencas x LEFT JOIN glegislacao_dbp.legislacao l ON l.CODLEGISLACAO=x.CODLEGISLACAO WHERE l.CODLEGISLACAO IS NULL')->fetchColumn(),
    'orfaos_licenca' => (int) $target->query('SELECT COUNT(*) FROM legislacaoxlicencas x LEFT JOIN li_licencas l ON l.CODLICENCA=x.CODLICENCA WHERE l.CODLICENCA IS NULL')->fetchColumn(),
    'orfaos_unidade' => (int) $target->query('SELECT COUNT(*) FROM legislacaoxlicencas x LEFT JOIN unidades u ON u.CODUNIDADE=x.codunidade WHERE x.codunidade IS NOT NULL AND u.CODUNIDADE IS NULL')->fetchColumn(),
    'usuarios_invalidos' => (int) $target->query('SELECT COUNT(*) FROM li_licencas l LEFT JOIN usuarios u ON u.codusuario=l.codusuario WHERE l.codusuario IS NOT NULL AND u.codusuario IS NULL')->fetchColumn(),
    'grupos_invalidos' => 0,
];

foreach (['codgrupo', 'codgrupo_validade', 'codgrupo_reacao', 'codgrupo_protocolo', 'codgrupo_acesso'] as $column) {
    $result['grupos_invalidos'] += (int) $target->query("SELECT COUNT(*) FROM li_licencas l LEFT JOIN grupos g ON g.codgrupo=l.`$column` WHERE l.`$column` IS NOT NULL AND g.codgrupo IS NULL")->fetchColumn();
}

$result['auditoria'] = $target->query("SELECT codigo_migracao,status,quantidade_registros,detalhes FROM registros_migracao WHERE codigo_migracao='GREEN_GOODYEAR_LICENCAS_269'")->fetch(PDO::FETCH_ASSOC);
$result['ok'] = $result['licencas'] === 199 && $result['relacionamentos'] === 27 && $result['orfaos_legislacao'] === 0 && $result['orfaos_licenca'] === 0 && $result['orfaos_unidade'] === 0 && $result['usuarios_invalidos'] === 0 && $result['grupos_invalidos'] === 0;

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
