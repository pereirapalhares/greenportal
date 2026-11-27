<?php
declare(strict_types=1);

/*
 * Diagnóstico (somente leitura) de green_universal.emails_enviados para a migração/saneamento para guniversal_dbp — 27/11/2026.
 * Poucas varreduras da tabela (5,4 milhões de linhas, MyISAM): perfil por empresa em uma passada, repetições em outra.
 * Só números: não mostra endereços, nomes nem corpo.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\diagnostico-emails-enviados.php > storage\logs\diag_emails_enviados.json
 * Demora alguns minutos.
 */
require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
set_time_limit(0);
$t0 = microtime(true);
$env = GreenPortalDatabase::env();
$uni = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$p = GreenPortalDatabase::connect($env, $uni, 'GUNIVERSAL');
$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = static function (string $sql, array $a = []) use ($p): array { try { $s = $p->prepare($sql); $s->execute($a); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; } catch (Throwable $e) { return [['erro' => mb_substr($e->getMessage(), 0, 300)]]; } };
$T = 'green_universal.emails_enviados';
$out = ['gerado_em' => date('c')];

$out['indices_legado'] = $q("SELECT INDEX_NAME i, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) c, MAX(NON_UNIQUE) nao_unico FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = 'green_universal' AND TABLE_NAME = 'emails_enviados' GROUP BY INDEX_NAME");
$out['charset_colunas'] = $q("SELECT COLUMN_NAME c, CHARACTER_SET_NAME cs, COLLATION_NAME co FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'green_universal' AND TABLE_NAME = 'emails_enviados' AND CHARACTER_SET_NAME IS NOT NULL");

// guniversal: o que já existe e como as empresas do legado se ligam ao greenportal
$out['guniversal'] = [
    'emails_enviados_existe' => $q("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'emails_enviados'", [$uni])[0]['n'] ?? null,
    'tabelas_empresa' => $q("SELECT TABLE_NAME t, GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND (COLUMN_NAME LIKE '%banco%' OR COLUMN_NAME LIKE '%database%')) GROUP BY TABLE_NAME", [$uni, $uni]),
];
$out['legado_empresas'] = $q("SELECT codempresa, BANCO_DE_DADOS banco, bloqueada FROM green_seguranca.empresas ORDER BY 2");

// 1) perfil por empresa, numa passada
$email = "'^[A-Za-z0-9._%+''-]+@[A-Za-z0-9.-]+\\\\.[A-Za-z]{2,}$'";
$out['perfil_por_empresa'] = $q("SELECT EMPRESA empresa, COUNT(*) total,
    SUM(ENVIADO = 'S') enviado_s, SUM(COALESCE(ENVIADO, '') <> 'S') enviado_outro,
    SUM(STATUSENVIO LIKE 'Enviado com sucesso%') status_ok, SUM(STATUSENVIO = '' OR STATUSENVIO IS NULL) status_vazio,
    SUM(STATUSENVIO LIKE '%ja foi enviado%') status_ja_enviado, SUM(STATUSENVIO LIKE 'Erro%' OR STATUSENVIO LIKE '%invalido%' OR STATUSENVIO LIKE '%desativado%') status_erro,
    SUM(TRIM(COALESCE(para, '')) = '') para_vazio, SUM(para LIKE '%;%') para_varios,
    SUM(TRIM(COALESCE(para, '')) <> '' AND para NOT LIKE '%;%' AND TRIM(para) NOT REGEXP $email) para_invalido,
    SUM(para LIKE '%gmail.com.br%') para_gmail_com_br,
    SUM(assunto LIKE '%Email sem dados%' OR transacao = 'Email sem dados') sem_dados,
    SUM(repeticao LIKE 'TESTE%') teste,
    SUM(DATAHORAENVIO IS NULL OR DATAHORAENVIO < '1990-01-01') sem_datahora, SUM(DATA_ENVIO IS NULL OR DATA_ENVIO < '1990-01-01') sem_data,
    SUM(CAST(corpo AS BINARY) LIKE CONCAT('%', UNHEX('C383'), '%') OR CAST(assunto AS BINARY) LIKE CONCAT('%', UNHEX('C383'), '%')) mojibake_utf8_duplo,
    SUM(corpo LIKE '%N?o%' OR corpo LIKE '%?%o %' OR assunto LIKE '%?%') com_interrogacao,
    SUM(corpo LIKE '%<%>%') corpo_html, ROUND(AVG(LENGTH(corpo))) corpo_medio, MAX(LENGTH(corpo)) corpo_max,
    SUM(LENGTH(corpo) >= 5000) corpo_cortado,
    MIN(DATA_ENVIO) primeiro, MAX(DATA_ENVIO) ultimo
  FROM $T GROUP BY EMPRESA ORDER BY total DESC");

// 2) códigos repetidos e repetições (mesma empresa, destinatário, assunto e corpo)
$out['codemail'] = $q("SELECT COUNT(*) linhas, COUNT(DISTINCT CODEMAIL) codigos, SUM(CODEMAIL IS NULL OR CODEMAIL = 0) sem_codigo FROM $T");
$out['repeticoes'] = $q("SELECT empresa, SUM(n) linhas, COUNT(*) unicos, SUM(n > 1) grupos_repetidos, MAX(n) maior_repeticao, SUM(exatos - 1) duplicados_exatos
  FROM (SELECT EMPRESA empresa, COUNT(*) n, COUNT(DISTINCT DATAHORAENVIO) exatos FROM $T GROUP BY EMPRESA, para, assunto, MD5(corpo)) x GROUP BY empresa ORDER BY linhas DESC");
// envios no mesmo dia para a mesma pessoa com o mesmo assunto e corpo (o legado deveria ter barrado)
$out['mesmo_dia'] = $q("SELECT SUM(n - 1) extras FROM (SELECT COUNT(*) n FROM $T WHERE DATA_ENVIO >= '2025-01-01' GROUP BY EMPRESA, para, assunto, MD5(corpo), DATA_ENVIO HAVING COUNT(*) > 1) x");
$out['por_ano'] = $q("SELECT YEAR(DATA_ENVIO) ano, COUNT(*) n FROM $T GROUP BY 1 ORDER BY 1");
$out['tempo_seg'] = round(microtime(true) - $t0, 1);
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
