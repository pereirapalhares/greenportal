<?php
declare(strict_types=1);

/*
 * Migração 413 — Onda 3 da Revisão G (25/11/2026): IA preenchendo os campos novos a partir do arquivo enviado
 * (itens CON-95, TRE-110, INF-125). Acrescenta aos prompts CONTROLE_DOCUMENTO, TREINAMENTO_DOCUMENTO e
 * INFRAESTRUTURA_DOCUMENTO (Administração › Prompts de IA, por empresa) o pedido das chaves dos campos das migrações 409/412.
 * O texto atual do prompt é mantido; o bloco novo vai no fim, marcado com [migração 413], e é trocado se a migração rodar de novo.
 * Grava pela procedure sp_ia_prompt_atualizar (fica no histórico do prompt). --simular mostra o fim do prompt atual sem gravar.
 * A procedure (migração 145) exigia a palavra "potenciais" em todo prompt, o que impedia editar os prompts de extração da 174
 * (inclusive pela tela Prompts de IA). Ela é refeita aqui: a exigência vale só para prompts que já seguem o contrato de potenciais.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-413-ia-campos-pilares.php [--simular] [--banco=gcrown_dbp]
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$soBanco = null;
foreach ($args as $a) if (str_starts_with($a, '--banco=')) $soBanco = substr($a, 8);
$env = GreenPortalDatabase::env();
$bancoUniversal = (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp');
$logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
$rel = ['ok' => true, 'migracao' => '413', 'modo' => 'APLICAR' . ($simular ? ' (SIMULACAO)' : ''), 'bancos' => []];
$MARCA = '[migração 413]';

$BLOCOS = [
    'CONTROLE_DOCUMENTO' => [
        'pt' => "Inclua também no mesmo JSON as chaves abaixo (use null quando o documento não trouxer a informação; não invente):\n"
            . "- \"versao\": versão ou revisão do documento (ex.: \"03\");\n- \"origem\": \"Interno\" ou \"Externo\";\n"
            . "- \"emitente\", \"revisor\", \"aprovador\": nomes de quem elaborou, revisou e aprovou;\n"
            . "- \"documento_superior\": código/título do documento de nível superior citado;\n"
            . "- \"data_emissao\" e \"data_vencimento\": datas no formato AAAA-MM-DD (vencimento = validade ou próxima revisão);\n"
            . "- \"prazo_guarda\": prazo de guarda/retenção (ex.: \"5 anos\");\n- \"forma_descarte\": forma de descarte;\n"
            . "- \"necessita_treinamento\" e \"notificar_alteracao\": \"Sim\" ou \"Não\";\n- \"observacao\": observação curta relevante.",
        'en' => "Also include in the same JSON the keys below (null when the document does not say; do not invent): \"versao\" (version/revision), \"origem\" (\"Interno\" or \"Externo\"), \"emitente\", \"revisor\", \"aprovador\" (who issued, reviewed, approved), \"documento_superior\", \"data_emissao\" and \"data_vencimento\" (YYYY-MM-DD), \"prazo_guarda\" (retention period), \"forma_descarte\", \"necessita_treinamento\" and \"notificar_alteracao\" (\"Sim\" or \"Não\"), \"observacao\".",
        'es' => "Incluya también en el mismo JSON las claves abajo (null cuando el documento no lo indique; no invente): \"versao\", \"origem\" (\"Interno\" o \"Externo\"), \"emitente\", \"revisor\", \"aprovador\", \"documento_superior\", \"data_emissao\" y \"data_vencimento\" (AAAA-MM-DD), \"prazo_guarda\", \"forma_descarte\", \"necessita_treinamento\" y \"notificar_alteracao\" (\"Sim\" o \"Não\"), \"observacao\".",
    ],
    'TREINAMENTO_DOCUMENTO' => [
        'pt' => "Inclua também no mesmo JSON as chaves abaixo (use null quando o documento não trouxer a informação; não invente):\n"
            . "- \"carga_horaria\": carga horária em horas (número, ex.: 8 ou 1.5);\n"
            . "- \"periodo_reciclagem\": período de reciclagem/validade do treinamento em MESES (número inteiro; ex.: \"bienal\" = 24, \"anual\" = 12);\n"
            . "- \"observacao\": observação curta relevante (ex.: norma regulamentadora citada).",
        'en' => "Also include in the same JSON (null when not stated; do not invent): \"carga_horaria\" (hours, number), \"periodo_reciclagem\" (refresher/validity period in MONTHS, integer), \"observacao\".",
        'es' => "Incluya también en el mismo JSON (null cuando no se indique; no invente): \"carga_horaria\" (horas, número), \"periodo_reciclagem\" (reciclaje/validez en MESES, entero), \"observacao\".",
    ],
    'INFRAESTRUTURA_DOCUMENTO' => [
        'pt' => "Inclua também no mesmo JSON as chaves abaixo (use null quando o documento não trouxer a informação; não invente):\n"
            . "- \"setor\": setor ou área onde fica a infraestrutura;\n- \"condicoes\": condições/estado descritos;\n"
            . "- \"classe\": \"A\", \"B\" ou \"C\" (só se o documento classificar);\n- \"propriedade\": \"Própria\", \"Terceiros\" ou \"Alugada\";\n"
            . "- \"situacao\": \"Em operação\", \"Em manutenção\" ou \"Desativada\";\n- \"observacao\": observação curta relevante (ex.: periodicidade de manutenção citada).",
        'en' => "Also include in the same JSON (null when not stated; do not invent): \"setor\", \"condicoes\", \"classe\" (\"A\", \"B\" or \"C\"), \"propriedade\" (\"Própria\", \"Terceiros\" or \"Alugada\"), \"situacao\" (\"Em operação\", \"Em manutenção\" or \"Desativada\"), \"observacao\".",
        'es' => "Incluya también en el mismo JSON (null cuando no se indique; no invente): \"setor\", \"condicoes\", \"classe\" (\"A\", \"B\" o \"C\"), \"propriedade\" (\"Própria\", \"Terceiros\" o \"Alugada\"), \"situacao\" (\"Em operação\", \"Em manutenção\" o \"Desativada\"), \"observacao\".",
    ],
];

$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES WHERE TABLE_NAME IN ('menus', 'usuarios') AND TABLE_SCHEMA LIKE 'g%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 2 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);
$bancos = array_values(array_filter($bancos, static fn($b) => $b !== $bancoUniversal && !str_ends_with((string)$b, '_seguranca_dbp')
    && $b !== 'glegislacao_dbp' && ($soBanco === null || $b === $soBanco)));

$juntar = static function (string $atual, string $bloco) use ($MARCA): string {
    $p = mb_strpos($atual, "\n\n" . $MARCA);
    if ($p === false) $p = mb_strpos($atual, $MARCA);
    $base = $p === false ? rtrim($atual) : rtrim(mb_substr($atual, 0, $p));
    return $base . "\n\n" . $MARCA . "\n" . $bloco;
};

foreach ($bancos as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NAMES utf8mb4');
        if (!$simular) {
            $pdo->exec('DROP PROCEDURE IF EXISTS `sp_ia_prompt_atualizar`');
            $pdo->exec("CREATE PROCEDURE `sp_ia_prompt_atualizar`(IN p_codprompt BIGINT, IN p_prompt_pt LONGTEXT, IN p_prompt_en LONGTEXT, IN p_prompt_es LONGTEXT, IN p_usuario BIGINT)
SQL SECURITY DEFINER
BEGIN
    DECLARE v_versao INT; DECLARE v_pt LONGTEXT; DECLARE v_en LONGTEXT; DECLARE v_es LONGTEXT;
    IF COALESCE(TRIM(p_prompt_pt),'')='' OR COALESCE(TRIM(p_prompt_en),'')='' OR COALESCE(TRIM(p_prompt_es),'')='' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Os prompts PT, EN e ES sao obrigatorios.'; END IF;
    SELECT versao,prompt_pt,prompt_en,prompt_es INTO v_versao,v_pt,v_en,v_es FROM ia_prompts WHERE codprompt=p_codprompt FOR UPDATE;
    IF v_versao IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Prompt nao encontrado.'; END IF;
    -- Migração 413: o contrato com \"potenciais\" vale para os prompts que já o seguem (potenciais da IA); os de extração de documento não o têm.
    IF CHAR_LENGTH(p_prompt_pt)<80 OR CHAR_LENGTH(p_prompt_en)<80 OR CHAR_LENGTH(p_prompt_es)<80
       OR (LOWER(COALESCE(v_pt,'')) LIKE '%potenciais%' AND (LOWER(p_prompt_pt) NOT LIKE '%potenciais%' OR LOWER(p_prompt_en) NOT LIKE '%potenciais%' OR LOWER(p_prompt_es) NOT LIKE '%potenciais%')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Os prompts devem manter o contrato de resposta com potenciais.'; END IF;
    IF v_pt=p_prompt_pt AND v_en=p_prompt_en AND v_es=p_prompt_es THEN SELECT p_codprompt AS codprompt,v_versao AS versao; ELSE
        INSERT INTO ia_prompts_historico(codprompt,versao_anterior,versao_nova,prompt_pt_anterior,prompt_en_anterior,prompt_es_anterior,prompt_pt_novo,prompt_en_novo,prompt_es_novo,alterado_por) VALUES(p_codprompt,v_versao,v_versao+1,v_pt,v_en,v_es,p_prompt_pt,p_prompt_en,p_prompt_es,p_usuario);
        UPDATE ia_prompts SET prompt_pt=p_prompt_pt,prompt_en=p_prompt_en,prompt_es=p_prompt_es,versao=v_versao+1,alterado_por=p_usuario,alterado_em=NOW() WHERE codprompt=p_codprompt;
        SELECT p_codprompt AS codprompt,v_versao+1 AS versao;
    END IF;
END");
            $info['sp_ia_prompt_atualizar'] = 'refeita (contrato de potenciais só para prompts de potenciais)';
        }
        $rows = GreenPortalDatabase::call($pdo, 'CALL sp_crud_select(:t, :f, NULL, 1000, 0)', ['t' => 'vw_ia_prompts_grid', 'f' => '{}']);
        $usuario = (int)$pdo->query("SELECT COALESCE(MIN(codusuario), 1) FROM usuarios")->fetchColumn();
        foreach ($BLOCOS as $codigo => $bloco) {
            $linha = null;
            foreach ($rows as $r) { $r = array_change_key_case($r, CASE_LOWER); if (strcasecmp((string)($r['codigo'] ?? ''), $codigo) === 0) { $linha = $r; break; } }
            if (!$linha) { $info[$codigo] = 'prompt não encontrado nesta empresa'; continue; }
            $id = (int)($linha['codprompt'] ?? $linha['id'] ?? 0);
            $pt = (string)($linha['prompt_pt'] ?? $linha['prompt'] ?? ''); $en = (string)($linha['prompt_en'] ?? ''); $es = (string)($linha['prompt_es'] ?? '');
            $novoPt = $juntar($pt, $bloco['pt']);
            $novo = ['pt' => $novoPt, 'en' => trim($en) !== '' ? $juntar($en, $bloco['en']) : $novoPt, 'es' => trim($es) !== '' ? $juntar($es, $bloco['es']) : $novoPt];
            if ($simular) { $info[$codigo] = ['codprompt' => $id, 'colunas' => array_keys($linha), 'fim_do_prompt_atual' => mb_substr($pt, -400), 'ja_tinha_413' => str_contains($pt, $MARCA)]; continue; }
            if ($id < 1) { $info[$codigo] = 'sem codprompt'; continue; }
            GreenPortalDatabase::call($pdo, 'CALL sp_ia_prompt_atualizar(:id, :pt, :en, :es, :usuario)', ['id' => $id, 'pt' => $novo['pt'], 'en' => $novo['en'], 'es' => $novo['es'], 'usuario' => $usuario]);
            $info[$codigo] = 'atualizado (codprompt ' . $id . (str_contains($pt, $MARCA) ? ', bloco 413 trocado' : ', bloco 413 acrescentado') . ')';
        }
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}
$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents($logDir . DIRECTORY_SEPARATOR . 'migracao_413_ia_campos_pilares' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);
