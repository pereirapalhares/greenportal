<?php
declare(strict_types=1);

/*
 * Migração 360 — Configuração do servidor de e-mail (SMTP) pela tela (item 4 da análise do 01_Mudancas_Gerais, parte SMTP).
 * Decisões do usuário (12/11/2026): usar a transação que já existia (Administração › Emails, principal/administracao_emails.php,
 * que estava "Em construção"); uma configuração para todas as empresas; botão liga/desliga (desligado = nada acumula: os e-mails
 * pendentes são cancelados); e-mail de teste.
 *   1. Universal: tabela smtp_configuracao (database/360_smtp_universal.sql) e o menu da tela (o item que aponta para
 *      administracao_emails.php; sem ele, cria ADMINISTRACAO_SMTP em Administração). Só o ADMINISTRADOR usa a tela.
 *   2. Empresas com a fila de e-mails (email_fila): procedure sp_email_cancelar (database/360_email_cancelar.sql).
 * Não grava nenhuma configuração: a tela começa com os valores do .env (sem a senha) e o envio DESLIGADO até alguém gravar e ligar.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-360-smtp.php [--simular]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';

$simular = in_array('--simular', $argv ?? [], true);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$rel = ['ok' => true, 'migracao' => '360', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'universal' => [], 'bancos' => []];
$blocos = static function (PDO $p, string $arquivo): int {
    $n = 0;
    foreach (preg_split('/^-- @@\s*$/m', (string)file_get_contents(dirname(__DIR__) . '/database/' . $arquivo)) as $b) {
        $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $b));
        if ($cmd === '') continue;
        $p->exec($cmd); $n++;
    }
    return $n;
};

try {
    $cat = new MenuCatalogoService($env);
    $u = $cat->universal();
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($simular) $rel['universal']['tabela'] = MigracaoCrud::existe($u, 'smtp_configuracao') ? 'smtp_configuracao já existe' : 'smtp_configuracao será criada';
    else { $blocos($u, '360_smtp_universal.sql'); $rel['universal']['tabela'] = 'smtp_configuracao ok'; }
    $menus = $u->query("SELECT codmenu, codigo, codmenu_pai, url, ativo FROM menus WHERE url LIKE '%administracao_emails.php' OR codigo IN ('ADMINISTRACAO_SMTP', 'ADMINISTRACAO_EMAILS_ENVIADOS', 'ADMINISTRACAO')")->fetchAll(PDO::FETCH_ASSOC);
    $tela = null; $adm = null; $enviados = null;
    foreach ($menus as $m) {
        if (preg_match('#administracao_emails\.php$#i', (string)$m['url']) || strtoupper((string)$m['codigo']) === 'ADMINISTRACAO_SMTP') $tela ??= $m;
        if (strtoupper((string)$m['codigo']) === 'ADMINISTRACAO') $adm = $m;
        if (strtoupper((string)$m['codigo']) === 'ADMINISTRACAO_EMAILS_ENVIADOS') $enviados = $m;
    }
    $dados = ['label' => 'Configuração de e-mail', 'label_pt' => 'Configuração de e-mail', 'label_en' => 'Email settings', 'label_es' => 'Configuración de correo',
        'url' => '/principal/administracao_emails.php', 'icone' => 'bi-envelope-gear', 'ativo' => 1];
    if ($tela) {
        $rel['universal']['menu'] = 'usa o menu existente ' . $tela['codigo'] . ' (codmenu ' . $tela['codmenu'] . ')' . ($simular ? '' : '; rótulo "Configuração de e-mail"');
        if (!$simular) $cat->gravar(['codigo' => (string)$tela['codigo']] + $dados, (int)$tela['codmenu']);
    } elseif (!$adm) { $rel['ok'] = false; $rel['universal']['menu'] = 'ERRO: menu ADMINISTRACAO não encontrado'; }
    elseif ($simular) $rel['universal']['menu'] = 'será criado ADMINISTRACAO_SMTP em Administração';
    else {
        $g = $cat->gravar(['codigo' => 'ADMINISTRACAO_SMTP', 'codmenu_pai' => (int)$adm['codmenu'], 'modulo' => 'ADMINISTRACAO', 'nivel' => 2, 'ordem' => (int)($enviados['ordem'] ?? 7) + 1] + $dados);
        $rel['universal']['menu'] = 'criado ADMINISTRACAO_SMTP (codmenu ' . $g['codmenu'] . ')';
    }
} catch (Throwable $e) { $rel['ok'] = false; $rel['universal']['erro'] = $e->getMessage(); }

$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME = 'email_fila' AND TABLE_SCHEMA LIKE 'g%\\_dbp' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
foreach ($bancos as $banco) {
    if ($banco === $bancoUniversal) continue;
    try {
        $p = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $rel['bancos'][$banco] = $simular ? 'sp_email_cancelar será criada' : ($blocos($p, '360_email_cancelar.sql') ? 'sp_email_cancelar ok' : '?');
    } catch (Throwable $e) { $rel['ok'] = false; $rel['bancos'][$banco] = 'ERRO: ' . $e->getMessage(); }
}

$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
