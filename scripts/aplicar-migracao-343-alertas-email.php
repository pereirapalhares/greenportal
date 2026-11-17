<?php
declare(strict_types=1);

/*
 * Migração 343 — Alertas do portal por e-mail, com link direto "Abrir no portal".
 * Em cada empresa (a que ainda não tem a fila de e-mails da migração 333 — empresa sem NC, ex.: Luxottica — recebe a fila
 * e, se faltar, a tabela de alertas: database/343_fila_email.sql):
 *   1. database/343_alertas_email.sql — escolha de cada usuário (alertas_email_pref: na hora, resumo diário às 7h ou não
 *      receber; sem escolha = na hora), controle e resumo do dia, procedures e views;
 *   2. a marca do último alerta: só os alertas criados depois desta migração vão por e-mail (o histórico não);
 *   3. relatório de quantos usuários ativos têm e-mail válido.
 * Universal: Ajuda de Minha conta › Alertas por e-mail e nota na Ajuda dos Alertas.
 * O envio é feito pelo serviço de e-mail do Agendador (GreenPortal-EmailWorker, scripts\criar-agendamento-email.ps1), com o
 * SMTP do .env (SMTP_HOST, SMTP_PORT, SMTP_SEGURANCA, SMTP_USUARIO, SMTP_SENHA, SMTP_REMETENTE, SMTP_REMETENTE_NOME) e o
 * endereço do portal para o link (GREENPORTAL_URL, ex.: http://servidor/greenportal). O relatório só diz se estão preenchidos.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-343-alertas-email.php [--simular] [--banco=gatlas_dbp]
 * Pode ser executada mais de uma vez (a marca não volta).
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/SmtpCliente.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$url = rtrim(trim((string)($env['GREENPORTAL_URL'] ?? '')), '/');
$rel = ['ok' => true, 'migracao' => '343', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR',
    'configuracao' => [
        'smtp' => SmtpCliente::configurado($env) ? 'configurado (SMTP_HOST e SMTP_REMETENTE preenchidos)' : 'NÃO configurado: preencha SMTP_* no .env (até lá os alertas não viram e-mail)',
        'link' => preg_match('#^https?://#i', $url) ? 'GREENPORTAL_URL preenchido: os e-mails terão "Abrir no portal"' : 'GREENPORTAL_URL vazio: os e-mails saem sem o botão "Abrir no portal" (preencha no .env, ex.: http://servidor/greenportal)',
    ], 'universal' => [], 'bancos' => []];

$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
// Empresas (bancos g*_dbp com menus, perfis, permissoes e usuarios); a que não tem a fila de e-mails (empresa sem o módulo de NC,
// ex.: Luxottica) recebe a fila e, se faltar, a tabela de alertas (database/343_fila_email.sql)
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'perfis', 'permissoes', 'usuarios') AND TABLE_SCHEMA LIKE 'g%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 4 ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
$sqlFila = (string)file_get_contents(dirname(__DIR__) . '/database/343_fila_email.sql');
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp') && ($soBanco === null || $b === $soBanco)));
$sql = (string)file_get_contents(dirname(__DIR__) . '/database/343_alertas_email.sql');
foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $colsU = MigracaoCrud::colunas($pdo, 'usuarios');
        if (!in_array('email', $colsU, true)) { $info['ignorado'] = 'usuarios sem a coluna email'; $rel['bancos'][$banco] = $info; continue; }
        $s = $pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_TYPE = 'PROCEDURE'
            AND ROUTINE_NAME IN ('sp_email_enfileirar', 'sp_email_reivindicar', 'sp_email_resultado', 'sp_email_recusado', 'sp_email_devolver', 'sp_email_reenviar')");
        $procsFila = (int)$s->fetchColumn(); $s->closeCursor();
        $faltaFila = $procsFila < 6 || !MigracaoCrud::existe($pdo, 'email_fila') || !MigracaoCrud::existe($pdo, 'alertas');
        if ($faltaFila) {
            $info['fila_email'] = $simular ? 'será criada (a empresa não tem a fila de e-mails e/ou os alertas)' : 'criada (a empresa não tinha a fila de e-mails e/ou os alertas)';
            if (!$simular) {
                foreach (preg_split('/^-- @@\s*$/m', $sqlFila) as $bloco) {
                    $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
                    if ($cmd !== '') $pdo->exec($cmd);
                }
                if (!MigracaoCrud::existe($pdo, 'vw_alertas_grid')) $pdo->exec('CREATE OR REPLACE VIEW `vw_alertas_grid` AS SELECT `codalerta`, `codusuario`, `titulo`, `mensagem`, `link`, `lido`, `criado_em` FROM `alertas`');
            }
        }
        if ($simular && $faltaFila) { $info['modo'] = 'simulação: nada gravado'; $rel['bancos'][$banco] = $info; continue; }
        $ativos = 0; $comEmail = 0;
        foreach (MigracaoCrud::selecionar($pdo, 'usuarios', ['ativo' => 1]) as $u) { $ativos++; if (filter_var(trim((string)($u['email'] ?? '')), FILTER_VALIDATE_EMAIL) !== false) $comEmail++; }
        $info['usuarios'] = "$comEmail de $ativos usuário(s) ativo(s) com e-mail válido";
        if ($simular) { $info['modo'] = 'simulação: nada gravado'; $rel['bancos'][$banco] = $info; continue; }
        $n = 0;
        foreach (preg_split('/^-- @@\s*$/m', $sql) as $bloco) {
            $cmd = trim((string)preg_replace('/^--(?! @@).*$/m', '', $bloco));
            if ($cmd === '') continue;
            $pdo->exec($cmd); $n++;
        }
        $info['blocos'] = $n;
        // Marca: o primeiro uso grava o último alerta existente (o histórico não vai por e-mail)
        $s = $pdo->prepare('CALL sp_alertas_email_novos(0)'); $s->execute(); do { try { $s->fetchAll(); } catch (Throwable) {} } while ($s->nextRowset()); $s->closeCursor();
        $m = MigracaoCrud::primeiro($pdo, 'vw_alertas_email_controle', ['CHAVE' => 'ULTIMO_CODALERTA']);
        $info['marca'] = 'alertas a partir do nº ' . ((int)($m['VALOR'] ?? 0) + 1) . ' vão por e-mail';
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}

// Ajuda
try {
    $u = GreenPortalDatabase::connect($env, $bancoUniversal, 'GPT');
    $u->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $textos = require dirname(__DIR__) . '/database/343_ajuda_alertas_email.php';
    foreach ($textos as $transacao => $d) {
        if (!MigracaoCrud::existe($u, 'ajuda_transacoes')) { $rel['universal']['ajuda'] = 'sem ajuda_transacoes'; break; }
        $atual = MigracaoCrud::primeiro($u, 'ajuda_transacoes', ['transacao' => $transacao]);
        $dados = [];
        foreach (['pt', 'en', 'es'] as $lg) {
            $velho = trim((string)preg_replace('/\s*<!-- ml343 -->.*?<!-- \/ml343 -->/s', '', (string)($atual['texto_' . $lg] ?? '')));
            $dados['texto_' . $lg] = ($velho === '' ? '' : $velho . "\n") . "<!-- ml343 -->\n" . $d['texto'][$lg] . "\n<!-- /ml343 -->";
            if ($atual === null || trim((string)($atual['titulo_' . $lg] ?? '')) === '') $dados['titulo_' . $lg] = $d['titulo'][$lg];
        }
        if ($simular) { $rel['universal']['ajuda'][$transacao] = $atual ? 'será atualizada' : 'será incluída'; continue; }
        $r = MigracaoCrud::salvar($u, 'ajuda_transacoes', ['transacao' => $transacao], $dados + ['ativo' => 1]);
        $rel['universal']['ajuda'][$transacao] = $r === 'alterado' ? 'atualizada' : 'incluída';
    }
} catch (Throwable $e) { $rel['ok'] = false; $rel['universal']['erro'] = $e->getMessage(); }

echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
