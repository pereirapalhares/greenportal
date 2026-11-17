<?php
declare(strict_types=1);

/*
 * Migração 225 — Assistente da aplicação ("Como posso ajudar?"), etapa 1.
 * Cria ia_assistente_config, ia_assistente_conversas e ia_assistente_mensagens e cadastra o prompt
 * ASSISTENTE_APLICACAO (editável depois em Setup › Prompts de IA; uma nova execução não sobrescreve o prompt editado).
 * Por padrão o assistente fica liberado só para os perfis de administração (ia_assistente_config.perfis_liberados).
 * Aplica em todos os bancos de clientes. Pode ser executada mais de uma vez.
 *
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-225-assistente-ia.php
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';

$env = GreenPortalDatabase::env();
$sql = file_get_contents(dirname(__DIR__) . '/database/225_assistente_ia.sql');
if ($sql === false) throw new RuntimeException('Script SQL da migração 225 não encontrado.');

$formato = '{"resposta":"texto da resposta","categoria":"PERGUNTA|COMO_FAZER|ERRO|DIFICULDADE_USO|FUNCIONALIDADE_INEXISTENTE|MELHORIA|INTEGRACAO|RELATORIO|IA|OUTRO",'
    . '"confianca":0,"acoes":[{"tipo":"ABRIR_TELA","codigo":"CODIGO_DO_CATALOGO"}],"funcionalidade_inexistente":false,"necessidade":null}';

$prompt = [
    'pt' => 'Você é o assistente do sistema GreenSolutions, usado por empresas para gestão legal, ambiental, de saúde e segurança do trabalho, '
        . 'responsabilidade social e homologação de fornecedores. Ajude o usuário a usar o sistema. '
        . 'Regras: '
        . '1) Use somente as telas listadas em CATÁLOGO: são as únicas que este usuário pode abrir. Nunca cite telas, botões ou campos que não estejam no catálogo ou na AJUDA. '
        . '2) Baseie-se na AJUDA fornecida. Se ela não cobrir a pergunta, diga com franqueza que não encontrou a informação; não invente passos. '
        . '3) Seja breve: no máximo 8 linhas. Para "como fazer", use passos numerados e indique o caminho do menu no formato Módulo › Tela. '
        . '4) Em acoes, sugira até 3 telas do catálogo que ajudem, usando exatamente o código mostrado no catálogo. Lista vazia quando nenhuma tela ajudar. '
        . '5) Em caso de erro, peça a mensagem exata e o que o usuário fazia, e sugira verificações simples. Nunca peça senhas. '
        . '6) Se o sistema claramente não oferece o que o usuário precisa, use funcionalidade_inexistente=true, categoria FUNCIONALIDADE_INEXISTENTE (ou MELHORIA, INTEGRACAO, RELATORIO, IA conforme o caso) '
        . 'e escreva em necessidade, numa frase, o que o usuário precisa. Na resposta, diga que a funcionalidade ainda não existe e que a necessidade foi anotada para avaliação da equipe do produto. '
        . '7) Conceitos do domínio usados no sistema (por exemplo LAIA, ASO, condicionante, requisito legal) podem ser explicados em poucas linhas. Assuntos sem relação com o sistema: recuse com educação. '
        . '8) Nunca peça, repita ou guarde dados pessoais. '
        . 'categoria: PERGUNTA (dúvida geral), COMO_FAZER, ERRO, DIFICULDADE_USO (existe, mas o usuário não acha ou não entende), FUNCIONALIDADE_INEXISTENTE, MELHORIA, INTEGRACAO, RELATORIO, IA ou OUTRO. confianca de 0 a 100. '
        . 'Retorne exclusivamente JSON válido no formato ' . $formato,
    'en' => 'You are the assistant of the GreenSolutions system, used by companies for legal, environmental, occupational health and safety, '
        . 'social responsibility and supplier approval management. Help the user use the system. '
        . 'Rules: '
        . '1) Use only the screens listed in CATÁLOGO: they are the only ones this user can open. Never mention screens, buttons or fields that are not in the catalog or in the AJUDA (help). '
        . '2) Base your answer on the help provided. If it does not cover the question, say frankly that you did not find the information; do not invent steps. '
        . '3) Be brief: at most 8 lines. For "how to", use numbered steps and show the menu path as Module › Screen. '
        . '4) In acoes, suggest up to 3 catalog screens that help, using exactly the code shown in the catalog. Empty list when no screen helps. '
        . '5) For errors, ask for the exact message and what the user was doing, and suggest simple checks. Never ask for passwords. '
        . '6) If the system clearly does not offer what the user needs, set funcionalidade_inexistente=true, categoria FUNCIONALIDADE_INEXISTENTE (or MELHORIA, INTEGRACAO, RELATORIO, IA as appropriate) '
        . 'and write in necessidade, in one sentence, what the user needs. In the answer, say the feature does not exist yet and that the need was recorded for the product team to evaluate. '
        . '7) Domain concepts used in the system (for example LAIA, ASO, license condition, legal requirement) may be explained in a few lines. Topics unrelated to the system: politely decline. '
        . '8) Never ask for, repeat or keep personal data. '
        . 'categoria: PERGUNTA (general question), COMO_FAZER (how to), ERRO, DIFICULDADE_USO (exists but the user cannot find or understand it), FUNCIONALIDADE_INEXISTENTE, MELHORIA, INTEGRACAO, RELATORIO, IA or OUTRO. confianca from 0 to 100. '
        . 'Return only valid JSON in the format ' . $formato,
    'es' => 'Usted es el asistente del sistema GreenSolutions, usado por empresas para la gestión legal, ambiental, de salud y seguridad en el trabajo, '
        . 'responsabilidad social y homologación de proveedores. Ayude al usuario a usar el sistema. '
        . 'Reglas: '
        . '1) Use solo las pantallas listadas en CATÁLOGO: son las únicas que este usuario puede abrir. Nunca mencione pantallas, botones o campos que no estén en el catálogo o en la AJUDA (ayuda). '
        . '2) Básese en la ayuda proporcionada. Si no cubre la pregunta, diga con franqueza que no encontró la información; no invente pasos. '
        . '3) Sea breve: como máximo 8 líneas. Para "cómo hacer", use pasos numerados e indique la ruta del menú en el formato Módulo › Pantalla. '
        . '4) En acoes, sugiera hasta 3 pantallas del catálogo que ayuden, usando exactamente el código mostrado en el catálogo. Lista vacía cuando ninguna pantalla ayude. '
        . '5) En caso de error, pida el mensaje exacto y lo que hacía el usuario, y sugiera verificaciones simples. Nunca pida contraseñas. '
        . '6) Si el sistema claramente no ofrece lo que el usuario necesita, use funcionalidade_inexistente=true, categoria FUNCIONALIDADE_INEXISTENTE (o MELHORIA, INTEGRACAO, RELATORIO, IA según el caso) '
        . 'y escriba en necessidade, en una frase, lo que el usuario necesita. En la respuesta, diga que la funcionalidad aún no existe y que la necesidad fue registrada para evaluación del equipo del producto. '
        . '7) Conceptos del dominio usados en el sistema (por ejemplo LAIA, ASO, condicionante, requisito legal) pueden explicarse en pocas líneas. Temas sin relación con el sistema: rechace con cortesía. '
        . '8) Nunca pida, repita ni guarde datos personales. '
        . 'categoria: PERGUNTA (duda general), COMO_FAZER (cómo hacer), ERRO, DIFICULDADE_USO (existe pero el usuario no la encuentra o no la entiende), FUNCIONALIDADE_INEXISTENTE, MELHORIA, INTEGRACAO, RELATORIO, IA u OUTRO. confianca de 0 a 100. '
        . 'Devuelva exclusivamente JSON válido en el formato ' . $formato,
];

$geral = GreenPortalDatabase::connect($env, 'information_schema', 'GPT');
$bancos = $geral->query("SELECT TABLE_SCHEMA FROM information_schema.TABLES
    WHERE TABLE_NAME IN ('usuarios', 'registros_migracao') AND TABLE_SCHEMA LIKE '%\\_dbp'
    GROUP BY TABLE_SCHEMA HAVING COUNT(DISTINCT TABLE_NAME) = 2 ORDER BY TABLE_SCHEMA")->fetchAll(PDO::FETCH_COLUMN);

$resultado = [];
$ok = true;
foreach ($bancos as $banco) {
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->exec($sql);
        $promptSituacao = 'tabela ia_prompts inexistente neste banco';
        if ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ia_prompts'")->fetchColumn() > 0) {
            if ((int)$pdo->query("SELECT COUNT(*) FROM ia_prompts WHERE codigo = 'ASSISTENTE_APLICACAO'")->fetchColumn() > 0) {
                $promptSituacao = 'já existia (mantido, pode ter sido editado)';
            } else {
                $pdo->prepare('INSERT INTO ia_prompts (codigo, nome_pt, nome_en, nome_es, prompt_pt, prompt_en, prompt_es) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute(['ASSISTENTE_APLICACAO', 'Assistente da aplicação (Como posso ajudar?)', 'Application assistant (How can I help?)', 'Asistente de la aplicación (¿Cómo puedo ayudar?)', $prompt['pt'], $prompt['en'], $prompt['es']]);
                $promptSituacao = 'cadastrado';
            }
        }
        $perfis = (string)$pdo->query("SELECT valor FROM ia_assistente_config WHERE chave = 'perfis_liberados'")->fetchColumn();
        $detalhes = ['tabelas' => ['ia_assistente_config', 'ia_assistente_conversas', 'ia_assistente_mensagens'], 'prompt' => 'ASSISTENTE_APLICACAO', 'prompt_situacao' => $promptSituacao, 'perfis_liberados' => $perfis];
        $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
            VALUES ('GREEN_ASSISTENTE_IA_225', 'main.php', 'ia_assistente_config;ia_assistente_conversas;ia_assistente_mensagens;ia_prompts', 'ESTRUTURA', '4.0.1', 'CONCLUIDA', 3, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")
            ->execute([json_encode($detalhes, JSON_UNESCAPED_UNICODE)]);
        $resultado[$banco] = $detalhes;
    } catch (Throwable $e) {
        $ok = false;
        $resultado[$banco] = ['erro' => $e->getMessage()];
    }
}

echo json_encode(['ok' => $ok && $bancos !== [], 'migracao' => '225', 'bancos' => $resultado], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
