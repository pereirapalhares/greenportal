<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/UsuarioSessao.php';

$env = GreenPortalDatabase::env();
$central = GreenPortalDatabase::connect($env, 'guniversal_dbp', 'GUNIVERSAL');
$target = GreenPortalDatabase::connect($env, 'ggdamericana_dbp', 'GPT');
$legacy = GreenPortalDatabase::connect($env, 'green_gd_americana_seguranca', 'LEGADO_SEGURANCA');
$row = $central->query("SELECT e.codempresa,e.codigo,e.nome,e.status,c.status_migracao FROM empresa e JOIN empresa_migracao_config c ON c.codempresa=e.codempresa WHERE c.banco_novo='ggdamericana_dbp'")->fetch(PDO::FETCH_ASSOC);
if (!$row) throw new RuntimeException('Mapeamento Goodyear nao encontrado.');
$originalStatus = (string)$row['status'];
$result = ['empresa'=>$row['codigo'],'status_original'=>$originalStatus,'status_final'=>$originalStatus];
try {
    $central->exec("UPDATE empresa SET status='ATIVA' WHERE codempresa=" . (int)$row['codempresa']);
    $legacyAdmin = $legacy->query("SELECT USERID,SENHA FROM usuarios WHERE USERID='admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $targetAdmin = $target->prepare("SELECT u.*,p.codigo AS perfil_codigo FROM usuarios u JOIN perfis p ON p.codperfil=u.codperfil WHERE u.userid=:userid LIMIT 1");
    $targetAdmin->execute(['userid'=>'admin']);
    $admin = $targetAdmin->fetch(PDO::FETCH_ASSOC);
    if (!$legacyAdmin || !$admin) throw new RuntimeException('Usuario admin ausente na origem ou destino.');
    $result['login'] = [
        'empresa_localizada'=>true,
        'usuario_ativo'=>(bool)$admin['ativo'],
        'senha_compativel'=>(int)$admin['senha_usa_hash'] === 1 ? password_verify((string)$legacyAdmin['SENHA'], (string)$admin['senha']) : hash_equals((string)$admin['senha'], (string)$legacyAdmin['SENHA']),
        'perfil'=>(string)$admin['perfil_codigo'],
    ];
    if (!$result['login']['usuario_ativo'] || !$result['login']['senha_compativel'] || $result['login']['perfil'] !== 'ADMINISTRADOR') throw new RuntimeException('Falha na autenticacao controlada do admin.');
    $sessao = ['autenticado'=>true,'usuario'=>['userid'=>'admin']];
    $sessionOk = UsuarioSessao::atualizar($target, $sessao);
    $result['sessao'] = ['atualizada'=>$sessionOk,'codusuario'=>(int)($sessao['usuario']['codusuario']??0),'permissoes'=>count((array)($sessao['permissoes']??[]))];
    $result['permissoes_admin'] = (int)$target->query("SELECT COUNT(*) FROM permissoes p JOIN perfis f ON f.codperfil=p.codperfil WHERE f.codigo='ADMINISTRADOR' AND p.pode_visualizar=1")->fetchColumn();
    $result['menus_admin'] = (int)$target->query("SELECT COUNT(*) FROM menus WHERE ativo=1")->fetchColumn();
    $result['usuarios_migrados'] = (int)$target->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
    $result['grupos_migrados'] = (int)$target->query('SELECT COUNT(*) FROM grupos')->fetchColumn();
    $result['ok'] = $sessionOk && $result['permissoes_admin'] > 0 && $result['usuarios_migrados'] === 56 && $result['grupos_migrados'] === 40;
} finally {
    $central->prepare('UPDATE empresa SET status=:status WHERE codempresa=:id')->execute(['status'=>$originalStatus,'id'=>(int)$row['codempresa']]);
    $result['status_final'] = $originalStatus;
}
echo json_encode($result, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . PHP_EOL;
