<?php
declare(strict_types=1);

/*
 * Migração 213 — Textos e idiomas: mensagens de erro e de retorno do servidor.
 * Cadastra no guniversal_dbp (grupos COMUM, MODULOLEGAL_CONTROLE e BANCO) as mensagens que estavam
 * no código e os avisos das procedures do banco, em PT/EN/ES. Requer as migrações 211 e 212.
 * Não sobrescreve textos já editados. Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-213-idiomas-mensagens.php
 */

require_once __DIR__ . '/../classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$universalDb = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$carga = require __DIR__ . '/../database/213_idiomas_mensagens.php';
$universal = GreenPortalDatabase::connect($env, $universalDb, 'GUNIVERSAL');
if ((int)$universal->query("SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = DATABASE() AND routine_name = 'sp_idioma_registrar_lote'")->fetchColumn() === 0) {
    fwrite(STDERR, "Aplique antes as migrações 211 e 212.\n");
    exit(1);
}

foreach ($carga['grupos'] as [$codigo, $pt, $en, $es, $transacao, $ordem]) {
    GreenPortalDatabase::call($universal, 'CALL sp_idioma_registrar_grupo(?, ?, ?, ?, ?, ?)', [$codigo, $pt, $en, $es, $transacao, $ordem]);
}
$grupos = [];
foreach ($universal->query('SELECT codgrupo, nome_pt, nome_en, nome_es, transacao FROM idioma_grupos')->fetchAll(PDO::FETCH_ASSOC) as $g) $grupos[$g['codgrupo']] = $g;

$resultado = ['ok' => true, 'migracao' => '213', 'grupos' => []];
foreach ($carga['textos'] as $grupo => $textos) {
    $itens = [];
    foreach ($textos as $i => [$chave, $tipo, $descricao, $pt, $en, $es]) {
        $itens[] = ['chave' => $chave, 'tipo' => $tipo, 'descricao' => $descricao, 'ordem' => 1000 + ($i + 1) * 10, 'pt' => $pt, 'en' => $en, 'es' => $es];
    }
    $g = $grupos[$grupo] ?? ['nome_pt' => $grupo, 'nome_en' => '', 'nome_es' => '', 'transacao' => ''];
    $novos = (int)(GreenPortalDatabase::call($universal, 'CALL sp_idioma_registrar_lote(?, ?, ?, ?, ?, ?, ?)', [
        $grupo, $g['nome_pt'], (string)$g['nome_en'], (string)$g['nome_es'], (string)($g['transacao'] ?? ''), json_encode($itens, JSON_UNESCAPED_UNICODE), 'Migração 213',
    ])[0]['novos'] ?? 0);
    $chaves = array_column($itens, 'chave');
    $stmt = $universal->prepare('SELECT COUNT(*) FROM vw_idioma_textos_publicados WHERE codidioma = \'pt\' AND chave IN (' . implode(',', array_fill(0, count($chaves), '?')) . ')');
    $stmt->execute($chaves);
    $existentes = (int)$stmt->fetchColumn();
    $resultado['grupos'][$grupo] = ['textos' => count($itens), 'novos' => $novos, 'no_banco' => $existentes];
    if ($existentes < count($itens)) $resultado['ok'] = false;
}

$universal->exec("INSERT INTO green_universal_migrations (codigo_migracao, versao, status, detalhes)
    VALUES ('GREEN_IDIOMAS_MENSAGENS_213', '4.0.1', 'CONCLUIDA', JSON_OBJECT('grupos', JSON_ARRAY('COMUM', 'MODULOLEGAL_CONTROLE', 'BANCO')))
    ON DUPLICATE KEY UPDATE status = VALUES(status), detalhes = VALUES(detalhes), executado_em = CURRENT_TIMESTAMP");
echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($resultado['ok'] ? 0 : 1);
