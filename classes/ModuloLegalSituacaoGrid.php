<?php
declare(strict_types=1);

require_once __DIR__ . '/UsuarioSessao.php';
require_once __DIR__ . '/PermissaoTransacao.php';
require_once __DIR__ . '/GreenPortalDatabase.php';
require_once __DIR__ . '/TenantContext.php';
require_once __DIR__ . '/IdiomaService.php';
require_once __DIR__ . '/RequisitoRisco.php';
require_once __DIR__ . '/RequisitoRiscoIa.php';
require_once __DIR__ . '/InteligenciaArtificialDocumento.php';
require_once dirname(__DIR__) . '/components/botoes_crud.php';

final class ModuloLegalSituacaoGrid
{
    public static function render(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); header('Pragma: no-cache'); header('Expires: 0'); session_start();
        if (empty($_SESSION['autenticado'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
        $env = self::env(); $db = (string)($_SESSION['tenant']['banco_dados'] ?? '');
        $pdo = TenantContext::connect($env, $_SESSION);
        if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
        $permission = PermissaoTransacao::obter('REQUISITOS_LEGAIS');
        // A situação é criada pelo fluxo de requisitos legais; nesta tela o
        // usuário pode somente alterar ou excluir registros existentes.
        if (!$permission['pode_visualizar']) { http_response_code(403); exit(htmlspecialchars(IdiomaService::t('comum.erro.sem_permissao', ['pt' => 'Seu perfil não tem permissão para acessar esta transação.', 'en' => 'Your profile is not allowed to access this transaction.', 'es' => 'Su perfil no tiene permiso para acceder a esta transacción.']), ENT_QUOTES, 'UTF-8')); }
        $call = static function (PDO $db, string $sql, array $params = []): array { $s = $db->prepare($sql); $s->execute($params); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
        $json = static fn(mixed $v): string => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); $leg = max(1, (int)($_GET['CODLEGISLACAO'] ?? 0)); $unit = max(0, (int)($_GET['CODUNIDADE'] ?? 0));
        if ($leg <= 0) { http_response_code(400); exit(htmlspecialchars(IdiomaService::t('manutencaolegalrequisitoslegais_situacao.erro.legislacao', ['pt' => 'Legislação não informada.', 'en' => 'Legislation not informed.', 'es' => 'Legislación no informada.']), ENT_QUOTES, 'UTF-8')); }
        $language = in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$_SESSION['idioma'] : 'pt'; $tr = static fn(array $v): string => (string)($v[$language] ?? $v['pt']); $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $users = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', ['tabela' => 'vw_modulolegal_responsaveis', 'filtros' => $json([])]);
        $userOptions = [];
        foreach ($users as $user) {
            $userId = strtolower(trim((string)($user['USERID'] ?? '')));
            if ($userId === 'admin') continue;
            $userCode = (string)($user['CODUSUARIO'] ?? '');
            $userName = trim((string)($user['NOME'] ?? $user['USERID'] ?? ''));
            if ($userCode !== '' && $userName !== '') $userOptions[$userCode] = $userName;
        }
        foreach ($userOptions as $code => $label) {
            if (!is_array($label)) $userOptions[$code] = ['pt' => (string)$label, 'en' => (string)$label, 'es' => (string)$label];
        }
        uasort($userOptions, static fn(array $a, array $b): int => strnatcasecmp((string)($a['pt'] ?? ''), (string)($b['pt'] ?? '')));
        $userOptions = ['' => ['pt' => 'Selecione um responsável', 'en' => 'Select a responsible user', 'es' => 'Seleccione un responsable']] + $userOptions;
        $legislationPdo = new PDO('mysql:host=' . ($env['GLEGISLACAO_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['GLEGISLACAO_DB_PORT'] ?? '3306') . ';dbname=' . ($env['GLEGISLACAO_DB_DATABASE'] ?? 'glegislacao_dbp') . ';charset=utf8mb4', $env['GLEGISLACAO_DB_USERNAME'] ?? '', $env['GLEGISLACAO_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $lawRows = $call($legislationPdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'vw_legislacao_grid', 'filtros' => $json(['CODLEGISLACAO' => $leg])]);
        $lawCode = trim((string)($lawRows[0]['CODIGO'] ?? ''));
        $loggedUser = (int)($_SESSION['usuario']['codusuario'] ?? 0);
        $registerPreviousState = static function (array $previous) use ($call, $pdo, $json, $loggedUser): void {
            $call($pdo, 'CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'situacaoxhistorico', 'dados' => $json([
                'CODSITUACAO' => (int)($previous['CODSITUACAO'] ?? 0),
                'DATAHISTORICO' => date('Y-m-d'),
                'DESCRICAO' => (string)($previous['dscsituacao'] ?? ''),
                'codusuario' => $loggedUser ?: null,
                'registroanterior' => $previous,
            ])]);
        };
        $voltar = static function (string $extra = '') use ($leg, $unit): never { header('Location: ' . strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') . '?CODLEGISLACAO=' . $leg . '&CODUNIDADE=' . $unit . $extra); exit; };
        // Situação legal calculada (migração 284): decisão do responsável quando a avaliação diverge da calculada
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'decidir_situacao_calculada') {
            if (!$permission['pode_editar']) { http_response_code(403); exit('Perfil sem permissão para alterar situações.'); }
            $decisao = strtoupper((string)($_POST['decisao'] ?? ''));
            $justificativa = trim((string)($_POST['justificativa'] ?? ''));
            try {
                $call($pdo, 'CALL sp_situacao_legal_decidir(:leg,:unidade,:decisao,:justificativa,:usuario)', ['leg' => $leg, 'unidade' => $unit, 'decisao' => $decisao, 'justificativa' => $justificativa, 'usuario' => $loggedUser]);
                try { $call($pdo, 'CALL sp_auditoria_registrar(:u,:t,:a,:en,:r,:res,:d)', ['u' => $loggedUser, 't' => 'manutencaolegalrequisitoslegais_situacao', 'a' => 'DECIDIR_SITUACAO_CALCULADA', 'en' => 'situacao_legal_calculada', 'r' => $leg . ':' . $unit, 'res' => 'SUCESSO', 'd' => $json(['decisao' => $decisao, 'justificativa' => $justificativa])]); } catch (Throwable $semAuditoria) {}
                $voltar('&decidido=1');
            } catch (Throwable $erroDecisao) {
                $mensagem = $erroDecisao instanceof PDOException && isset($erroDecisao->errorInfo[2]) ? (string)$erroDecisao->errorInfo[2] : 'Não foi possível registrar a decisão.';
                $voltar('&erro_decisao=' . rawurlencode($mensagem));
            }
        }
        // Migração 341: "Não se aplica" de um item de gestão neste requisito e unidade (justificativa obrigatória), com histórico
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'item_nao_se_aplica') {
            if (!$permission['pode_editar']) { http_response_code(403); exit('Perfil sem permissão para alterar situações.'); }
            $area = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($_POST['area'] ?? '')) ?? '');
            $item = (int)($_POST['item'] ?? 0);
            $na = (int)($_POST['na'] ?? 0) === 1 ? 1 : 0;
            $justificativa = trim((string)($_POST['justificativa'] ?? ''));
            try {
                $call($pdo, 'CALL sp_ml_requisito_na(:leg,:unidade,:area,:item,:na,:justificativa,:usuario)', ['leg' => $leg, 'unidade' => $unit, 'area' => $area, 'item' => $item, 'na' => $na, 'justificativa' => $justificativa, 'usuario' => $loggedUser]);
                try { $call($pdo, 'CALL sp_auditoria_registrar(:u,:t,:a,:en,:r,:res,:d)', ['u' => $loggedUser, 't' => 'manutencaolegalrequisitoslegais_situacao', 'a' => $na ? 'ITEM_NAO_SE_APLICA' : 'ITEM_VOLTA_A_SE_APLICAR', 'en' => 'ml_requisito_item_na', 'r' => $leg . ':' . $unit . ':' . $area . ':' . $item, 'res' => 'SUCESSO', 'd' => $json(['justificativa' => $justificativa])]); } catch (Throwable $semAuditoria) {}
                $voltar('&na_gravado=' . $na);
            } catch (Throwable $erroNa) {
                $mensagem = $erroNa instanceof PDOException && isset($erroNa->errorInfo[2]) ? (string)$erroNa->errorInfo[2] : 'Não foi possível gravar o "não se aplica".';
                $voltar('&erro_decisao=' . rawurlencode($mensagem));
            }
        }
        // Migração 418 (Revisão G item 31): a IA sugere a situação de atendimento a partir das evidências; o responsável aceita
        // (podendo alterar) e a sugestão vira uma avaliação, ou ignora. A avaliação continua sendo do responsável.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array(($_POST['action'] ?? ''), ['ia_sugerir_situacao', 'ia_aceitar_situacao'], true)) {
            header('Content-Type: application/json; charset=utf-8');
            $saida = static function (array $d, int $st = 200): never { http_response_code($st); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
            if (!$permission['pode_editar'] && !$permission['pode_incluir']) $saida(['ok' => false, 'message' => 'Perfil sem permissão para alterar situações.'], 403);
            try {
                if ($_POST['action'] === 'ia_aceitar_situacao') {
                    $at = strtoupper(trim((string)($_POST['atendido'] ?? '')));
                    if (!in_array($at, ['S', 'N', 'PA'], true)) $saida(['ok' => false, 'message' => 'Escolha Sim, Não ou Parcialmente.'], 422);
                    $next = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'vw_modulolegal_situacao_proximo', 'filtros' => $json([])]);
                    $cod = (int)($next[0]['PROXIMO_CODSITUACAO'] ?? 0);
                    if ($cod <= 0) throw new RuntimeException('Sem próximo código de situação.');
                    $call($pdo, 'CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'legislacaoxsituacao', 'dados' => $json(['CODSITUACAO' => $cod, 'CODLEGISLACAO' => $leg, 'codunidade' => $unit,
                        'CODUSUARIO' => $loggedUser ?: null, 'dscsituacao' => mb_substr(trim((string)($_POST['dscsituacao'] ?? '')), 0, 5000), 'atendido' => $at,
                        'OBS' => IdiomaService::t('situacao.ia.obs_aceita', ['pt' => 'Avaliação a partir da sugestão da IA (revisada pelo responsável).', 'en' => 'Assessment from the AI suggestion (reviewed by the owner).', 'es' => 'Evaluación a partir de la sugerencia de la IA (revisada por el responsable).']),
                        'data_de_avaliacao' => date('Y-m-d'), 'data_de_vencimento_avaliacao' => null])]);
                    try { $call($pdo, 'CALL sp_auditoria_registrar(:u,:t,:a,:en,:r,:res,:d)', ['u' => $loggedUser, 't' => 'manutencaolegalrequisitoslegais_situacao', 'a' => 'IA_SITUACAO_ACEITA', 'en' => 'legislacaoxsituacao', 'r' => $leg . ':' . $unit, 'res' => 'SUCESSO', 'd' => $json(['atendido' => $at, 'sugerido' => (string)($_POST['sugerido'] ?? '')])]); } catch (Throwable) {}
                    $saida(['ok' => true]);
                }
                $sitIa = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'vw_situacao_legal_grid', 'filtros' => $json(['CODLEGISLACAO' => $leg, 'CODUNIDADE' => $unit])])[0] ?? [];
                $itensIa = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,200,0)', ['tabela' => 'vw_situacao_legal_itens', 'filtros' => $json(['CODLEGISLACAO' => $leg, 'CODUNIDADE' => $unit])]);
                $lei = $lawRows[0] ?? [];
                $ctx = "LEI: " . trim((string)($lei['CODIGO'] ?? '')) . "\nARTIGOS: " . trim((string)($lei['ARTIGOS'] ?? '')) . "\nREQUISITO (o que a empresa deve cumprir): " . mb_substr(trim((string)($lei['PADRAO'] ?? '')), 0, 4000)
                    . "\nSITUAÇÃO CALCULADA PELO SISTEMA: " . (string)($sitIa['SITUACAO_CALCULADA'] ?? 'AI') . ' — ' . (string)($sitIa['MOTIVO'] ?? '')
                    . "\nEVIDÊNCIAS VINCULADAS (área | item | status | motivo):\n";
                foreach ($itensIa as $it) $ctx .= '- ' . implode(' | ', array_map(static fn($k) => mb_substr(trim((string)($it[$k] ?? '')), 0, 300), ['AREA', 'ITEM', 'STATUS', 'MOTIVO'])) . "\n";
                if (!$itensIa) $ctx .= "- (nenhuma evidência vinculada a este requisito na unidade)\n";
                $prompt = '';
                try { $prompt = trim((string)($call($pdo, 'CALL sp_ia_prompt_obter(:c,:i)', ['c' => 'SITUACAO_LEGAL_SUGESTAO', 'i' => (string)($_SESSION['idioma'] ?? 'pt')])[0]['prompt'] ?? '')); } catch (Throwable) {}
                if ($prompt === '') $prompt = 'Você é um auditor de conformidade legal. Com base no requisito da lei e nas evidências vinculadas (licenças, medições, treinamentos, infraestrutura, controles e fornecedores, com o status de cada uma), '
                    . 'sugira a situação de atendimento do requisito na unidade. Retorne exclusivamente JSON válido: {"atendido":"S"|"N"|"PA","descricao":"texto curto da situação, citando as evidências que sustentam","faltas":["o que falta para atender"],"confianca":0}. '
                    . 'Use "S" só quando as evidências cobrem o requisito e estão em dia; "PA" quando há evidência mas com falhas ou incompleta; "N" quando não há evidência ou ela está vencida/crítica. Não invente evidências.';
                $r = InteligenciaArtificialDocumento::analisar($ctx, $prompt, (string)($_SESSION['idioma'] ?? 'pt'));
                $r = is_array($r['sugestao'] ?? null) ? $r['sugestao'] : $r;
                $at = strtoupper(trim((string)($r['atendido'] ?? '')));
                if (!in_array($at, ['S', 'N', 'PA'], true)) $at = '';
                $saida(['ok' => true, 'atendido' => $at, 'descricao' => trim((string)($r['descricao'] ?? '')), 'faltas' => array_values(array_filter(array_map('strval', (array)($r['faltas'] ?? [])))), 'confianca' => (float)($r['confianca'] ?? 0)]);
            } catch (Throwable $erroIa) { $saida(['ok' => false, 'message' => $erroIa->getMessage()], 500); }
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'save_rows') {
            if (!$permission['pode_editar'] && !$permission['pode_incluir']) { http_response_code(403); exit('Perfil sem permissão para alterar situações.'); }
            foreach ((array)($_POST['rows'] ?? []) as $key => $row) {
                $data = ['CODLEGISLACAO' => $leg, 'codunidade' => $unit, 'CODUSUARIO' => (int)($row['CODUSUARIO'] ?? 0) ?: null, 'dscsituacao' => trim((string)($row['dscsituacao'] ?? '')), 'atendido' => trim((string)($row['atendido'] ?? '')) ?: null, 'OBS' => trim((string)($row['OBS'] ?? '')), 'data_de_avaliacao' => trim((string)($row['data_de_avaliacao'] ?? '')) ?: null, 'data_de_vencimento_avaliacao' => trim((string)($row['data_de_vencimento_avaliacao'] ?? '')) ?: null];
                if (str_starts_with((string)$key, 'novo_')) {
                    if (!$permission['pode_incluir']) continue;
                    $next = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'vw_modulolegal_situacao_proximo', 'filtros' => $json([])]);
                    $data['CODSITUACAO'] = (int)($next[0]['PROXIMO_CODSITUACAO'] ?? 0);
                    if ($data['CODSITUACAO'] > 0) $call($pdo, 'CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'legislacaoxsituacao', 'dados' => $json($data)]);
                } elseif ($permission['pode_editar']) {
                    $previousRows = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'legislacaoxsituacao', 'filtros' => $json(['CODSITUACAO' => (int)$key])]);
                    $previous = $previousRows[0] ?? [];
                    $changed = false;
                    foreach ($data as $field => $value) {
                        if ((string)($previous[$field] ?? '') !== (string)$value) { $changed = true; break; }
                    }
                    if ($previous && $changed) $registerPreviousState($previous);
                    $call($pdo, 'CALL sp_crud_update(:tabela,:chave,:dados)', ['tabela' => 'legislacaoxsituacao', 'chave' => $json(['CODSITUACAO' => (int)$key]), 'dados' => $json($data)]);
                }
            }
            header('Location: ' . strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') . '?CODLEGISLACAO=' . $leg . '&CODUNIDADE=' . $unit . '&salvo=1'); exit;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'delete_row' && $permission['pode_excluir']) { $key = (int)($_POST['key'] ?? 0); if ($key > 0) { $previousRows = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'legislacaoxsituacao', 'filtros' => $json(['CODSITUACAO' => $key])]); $previous = $previousRows[0] ?? []; if ($previous) $registerPreviousState($previous); $call($pdo, 'CALL sp_crud_delete(:tabela,:chave)', ['tabela' => 'legislacaoxsituacao', 'chave' => $json(['CODSITUACAO' => $key])]); } header('Location: ' . strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') . '?CODLEGISLACAO=' . $leg . '&CODUNIDADE=' . $unit . '&excluido=1'); exit; }
        $rows = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', ['tabela' => 'vw_modulolegal_situacao_grid', 'filtros' => $json(['CODLEGISLACAO' => $leg, 'codunidade' => $unit])]);
        $fields = ['CODSITUACAO'=>['label'=>['pt'=>'Código','en'=>'Code','es'=>'Código'],'hidden'=>true], 'CODLEGISLACAO'=>['label'=>['pt'=>'Legislação','en'=>'Legislation','es'=>'Legislación'],'hidden'=>true], 'codunidade'=>['label'=>['pt'=>'Unidade','en'=>'Unit','es'=>'Unidad'],'hidden'=>true], 'CODUSUARIO'=>['label'=>['pt'=>'Responsável','en'=>'Responsible','es'=>'Responsable'],'options'=>$users], 'dscsituacao'=>['label'=>['pt'=>'Descrição da situação','en'=>'Situation description','es'=>'Descripción de la situación'],'type'=>'textarea'], 'atendido'=>['label'=>['pt'=>'Atendido','en'=>'Compliant','es'=>'Atendido'],'options'=>[''=>['pt'=>'','en'=>'','es'=>''],'S'=>['pt'=>'Sim','en'=>'Yes','es'=>'Sí'],'N'=>['pt'=>'Não','en'=>'No','es'=>'No'],'PA'=>['pt'=>'Parcialmente','en'=>'Partially','es'=>'Parcialmente']]], 'OBS'=>['label'=>['pt'=>'Observação','en'=>'Notes','es'=>'Observación'],'type'=>'textarea'], 'data_de_avaliacao'=>['label'=>['pt'=>'Data de avaliação','en'=>'Evaluation date','es'=>'Fecha de evaluación'],'type'=>'date'], 'data_de_vencimento_avaliacao'=>['label'=>['pt'=>'Vencimento da avaliação','en'=>'Evaluation due date','es'=>'Vencimiento de evaluación'],'type'=>'date']];
        $title = IdiomaService::t('manutencaolegalrequisitoslegais_situacao.titulo', ['pt'=>'Situação da legislação','en'=>'Legislation status','es'=>'Situación de la legislación']); $permissionTitle=$tr(['pt'=>'Perfil sem permissão de acesso!','en'=>'Profile does not have access permission!','es'=>'¡El perfil no tiene permiso de acceso!']);
        $fields = ['dscsituacao'=>['label'=>['pt'=>'Situação','en'=>'Situation','es'=>'Situación'],'type'=>'textarea'], 'atendido'=>['label'=>['pt'=>'Atendido?','en'=>'Attended?','es'=>'¿Atendido?'],'options'=>['S'=>['pt'=>'Sim','en'=>'Yes','es'=>'Sí'],'N'=>['pt'=>'Não','en'=>'No','es'=>'No'],'AI'=>['pt'=>'Atendimento Indefinido','en'=>'Undefined attendance','es'=>'Atención indefinida'],'PA'=>['pt'=>'Parcialmente atendido!','en'=>'Partially attended!','es'=>'¡Atendido parcialmente!']]], 'CODUSUARIO'=>['label'=>['pt'=>'Responsável','en'=>'Responsible','es'=>'Responsable'],'options'=>$userOptions], 'data_de_avaliacao'=>['label'=>['pt'=>'Data de Avaliação','en'=>'Evaluation Date','es'=>'Fecha de evaluación'],'type'=>'date'], 'data_de_vencimento_avaliacao'=>['label'=>['pt'=>'Data de Vencimento','en'=>'Due Date','es'=>'Fecha de vencimiento'],'type'=>'date'], 'OBS'=>['label'=>['pt'=>'Observação','en'=>'Notes','es'=>'Observación'],'type'=>'textarea'], 'CODSITUACAO'=>['label'=>['pt'=>'Código','en'=>'Code','es'=>'Código'],'hidden'=>true], 'CODLEGISLACAO'=>['label'=>['pt'=>'Legislação','en'=>'Legislation','es'=>'Legislación'],'hidden'=>true], 'codunidade'=>['label'=>['pt'=>'Unidade','en'=>'Unit','es'=>'Unidad'],'hidden'=>true]];
        // Textos e idiomas: colunas novas entram sozinhas (ADMINISTRADOR) e os rótulos cadastrados valem sobre os do código.
        IdiomaService::nomeGrupo('manutencaolegalrequisitoslegais_situacao', ['pt' => 'Requisitos legais › Situação', 'en' => 'Legal requirements › Status', 'es' => 'Requisitos legales › Situación']);
        IdiomaService::registrarColunas('manutencaolegalrequisitoslegais_situacao', $fields);
        foreach ($fields as $campoIdioma => $cfgIdioma) {
            $chaveIdioma = 'manutencaolegalrequisitoslegais_situacao.coluna.' . $campoIdioma;
            if (IdiomaService::existe($chaveIdioma)) $fields[$campoIdioma]['label'] = IdiomaService::textos($chaveIdioma, $cfgIdioma['label'] ?? (string)$campoIdioma);
        }
        if ($lawCode !== '') $title .= ' - ' . $lawCode;
        echo '<span class="d-none situacao-law-context" data-situacao-law-code="' . $e($lawCode) . '" data-situacao-law-id="' . $leg . '"></span>';
        echo self::painelSituacaoCalculada($pdo, $call, $json, $leg, $unit, $tr, $e, (bool)$permission['pode_editar']);
        ?>
<style>.situacao-grid-fragment [data-close-situacao] { display: none !important; }.situacao-grid-fragment .menu-batch-grid{width:100% !important;table-layout:fixed}.situacao-grid-fragment .menu-batch-grid th[data-column="dscsituacao"],.situacao-grid-fragment .menu-batch-grid td[data-column="dscsituacao"]{width:40% !important;min-width:40% !important}.situacao-grid-fragment td[data-column="dscsituacao"] .grid-input{min-height:96px;line-height:1.45;white-space:pre-wrap;overflow-wrap:anywhere;resize:vertical}.situacao-grid-fragment td[data-column="dscsituacao"]{vertical-align:top}</style>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet"><link href="../assets/css/app.css?v=2" rel="stylesheet"><link href="../assets/css/main.css?v=15" rel="stylesheet">
<section class="setup-fragment menu-grid-fragment cadastro-legal-fragment situacao-grid-fragment" data-preferences="{}" data-cadastro-config='<?= $e($json(['primary' => 'CODSITUACAO', 'fields' => $fields])) ?>'><div class="crud-toolbar menu-grid-toolbar"><?php greenPortalCrudButtons(['form_id' => 'situacaoGridForm', 'language' => $language, 'permission' => $permission, 'permission_title' => $permissionTitle, 'transaction' => 'manutencaolegalrequisitoslegais_situacao', 'export' => false, 'columns' => false, 'extras' => '<button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-close-situacao title="' . $e(IdiomaService::t('comum.botao.fechar', ['pt' => 'Fechar', 'en' => 'Close', 'es' => 'Cerrar'])) . '" aria-label="' . $e(IdiomaService::t('comum.botao.fechar', ['pt' => 'Fechar', 'en' => 'Close', 'es' => 'Cerrar'])) . '"><i class="bi bi-x-lg"></i></button>']); ?><div class="crud-actions legacy-situacao-actions d-none"><button class="btn btn-sm btn-success" type="submit" form="situacaoGridForm" <?= !$permission['pode_editar'] && !$permission['pode_incluir'] ? 'disabled' : '' ?> title="<?= $e($permission['pode_editar'] || $permission['pode_incluir'] ? IdiomaService::t('comum.botao.salvar', ['pt' => 'Salvar', 'en' => 'Save', 'es' => 'Guardar']) : $permissionTitle) ?>"><i class="bi bi-check2"></i></button><button class="btn btn-sm btn-outline-success" type="button" data-new-cadastro <?= !$permission['pode_incluir'] ? 'disabled' : '' ?> title="<?= $e(IdiomaService::t('comum.botao.novo', ['pt' => 'Novo', 'en' => 'New', 'es' => 'Nuevo'])) ?>"><i class="bi bi-plus-lg"></i></button><button class="btn btn-sm btn-outline-danger" type="button" data-delete-cadastro <?= !$permission['pode_excluir'] ? 'disabled' : '' ?> title="<?= $e(IdiomaService::t('comum.botao.excluir', ['pt' => 'Excluir', 'en' => 'Delete', 'es' => 'Eliminar'])) ?>"><i class="bi bi-trash"></i></button></div></div><form method="post" id="situacaoGridForm" class="setup-form menu-grid-form"><input type="hidden" name="action" value="save_rows"><div class="menu-grid-scroll"><table class="table table-sm menu-batch-grid"><thead><tr><?php foreach($fields as $field=>$cfg): ?><th data-column="<?= $e($field) ?>"<?= !empty($cfg['hidden']) ? ' style="display:none"' : '' ?>><div class="column-heading"><span><?= $e($tr($cfg['label'])) ?></span></div></th><?php endforeach; ?></tr></thead><tbody><?php foreach($rows as $row): $key=(int)$row['CODSITUACAO']; ?><tr data-row data-key="<?= $key ?>"><?php foreach($fields as $field=>$cfg): $value=(string)($row[$field] ?? ''); ?><td data-column="<?= $e($field) ?>"<?= !empty($cfg['hidden']) ? ' style="display:none"' : '' ?>><?php if($field==='CODSITUACAO'): ?><input class="grid-input" value="<?= $key ?>" readonly><?php elseif(isset($cfg['options'])): ?><select class="grid-input" name="rows[<?= $key ?>][<?= $e($field) ?>]"><?php foreach($cfg['options'] as $opt=>$label): $optionValue=is_array($label)?$opt:$label; ?><option value="<?= $e((string)$optionValue) ?>"<?= (string)$value===(string)$optionValue?' selected':'' ?>><?= $e(is_array($label)?$tr($label):(string)$label) ?></option><?php endforeach; ?></select><?php elseif(($cfg['type']??'')==='textarea'): ?><textarea class="grid-input" name="rows[<?= $key ?>][<?= $e($field) ?>]" rows="2"><?= $e($value) ?></textarea><?php else: ?><input class="grid-input" type="<?= $cfg['type']??'text' ?>" name="rows[<?= $key ?>][<?= $e($field) ?>]" value="<?= $e($value) ?>"<?= in_array($field,['CODLEGISLACAO','codunidade'],true)?' readonly':'' ?>><?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div></form></section>
<script src="../assets/vendor/jquery/jquery-3.7.1.min.js?v=2"></script><script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script><script src="../assets/js/manutencao-legal-cadastro.js?v=54"></script><script src="../assets/js/grid-loading.js?v=4"></script><script>(function(){if(!window.jQuery)return;var $=window.jQuery;$(function(){var $heading=$('.situacao-grid-fragment .crud-actions');if(!$heading.length)return;var label=<?= json_encode($tr(['pt'=>'Fechar','en'=>'Close','es'=>'Cerrar']), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;$heading.addClass('d-flex align-items-center justify-content-between').find('h1').addClass('mb-0');$heading.append($('<button>',{type:'button',class:'btn btn-sm btn-outline-secondary',title:label,'aria-label':label}).attr('data-close-situacao','').append($('<i>',{class:'bi bi-x-lg'})));$(document).on('click','[data-close-situacao]',function(){try{var w=window.parent||window;if(w.bootstrap&&w.document){var fe=window.frameElement,el=(fe&&fe.closest&&fe.closest('.modal'))||w.document.getElementById('greenPortalTransacaoModal');if(el)(w.bootstrap.Modal.getInstance(el)||w.bootstrap.Modal.getOrCreateInstance(el)).hide();}else{window.close();}}catch(e){window.close();}});});})();</script>
<?php
    }
    /**
     * Situação legal calculada (migração 284): recalcula o requisito na hora e mostra a situação, os itens de gestão
     * considerados e, quando a avaliação manual diverge, a decisão do responsável. Sem a migração, não mostra nada.
     */
    private static function painelSituacaoCalculada(PDO $pdo, callable $call, callable $json, int $leg, int $unit, callable $tr, callable $e, bool $podeEditar): string
    {
        try {
            $call($pdo, 'CALL sp_situacao_legal_recalcular(:leg,:unidade)', ['leg' => $leg, 'unidade' => $unit]);
            $filtro = $json(['CODLEGISLACAO' => $leg, 'CODUNIDADE' => $unit]);
            $sit = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'vw_situacao_legal_grid', 'filtros' => $filtro])[0] ?? null;
            $itens = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,500,0)', ['tabela' => 'vw_situacao_legal_itens', 'filtros' => $filtro]);
        } catch (Throwable $semSituacao) { return ''; }
        if (!$sit) return '';
        $rotSit = ['S' => ['pt' => 'Atendido', 'en' => 'Compliant', 'es' => 'Atendido'], 'PA' => ['pt' => 'Parcialmente atendido', 'en' => 'Partially compliant', 'es' => 'Parcialmente atendido'],
            'N' => ['pt' => 'Não atendido', 'en' => 'Not compliant', 'es' => 'No atendido'], 'AI' => ['pt' => 'Indefinido', 'en' => 'Undefined', 'es' => 'Indefinido']];
        $corSit = ['S' => 'success', 'PA' => 'warning', 'N' => 'danger', 'AI' => 'secondary'];
        $rotArea = ['LICENCAS' => ['pt' => 'Licença', 'en' => 'License', 'es' => 'Licencia'], 'MEDICAO' => ['pt' => 'Medição e monitoramento', 'en' => 'Measurement', 'es' => 'Medición'],
            'TREINAMENTOS' => ['pt' => 'Treinamento', 'en' => 'Training', 'es' => 'Capacitación'], 'INFRAESTRUTURA' => ['pt' => 'Infraestrutura', 'en' => 'Infrastructure', 'es' => 'Infraestructura'],
            'CONTROLES' => ['pt' => 'Controle', 'en' => 'Control', 'es' => 'Control'], 'HOMOLOGACAO' => ['pt' => 'Fornecedor', 'en' => 'Supplier', 'es' => 'Proveedor']];
        $rotStatus = ['OK' => ['Ok', 'success'], 'ATENCAO' => [$tr(['pt' => 'Atenção', 'en' => 'Attention', 'es' => 'Atención']), 'warning'],
            'FALHA' => [$tr(['pt' => 'Falha', 'en' => 'Failure', 'es' => 'Falla']), 'danger'], 'CRITICO' => [$tr(['pt' => 'Vencido', 'en' => 'Expired', 'es' => 'Vencido']), 'danger']];
        $calc = (string)$sit['SITUACAO_CALCULADA']; $manual = (string)$sit['SITUACAO_MANUAL']; $final = (string)$sit['SITUACAO_FINAL'];
        $badge = static fn(string $c): string => '<span class="badge text-bg-' . ($corSit[$c] ?? 'secondary') . '">' . $e($tr($rotSit[$c] ?? $rotSit['AI'])) . '</span>';
        $data = static fn(?string $d): string => $d ? date('d/m/Y H:i', strtotime($d)) : '';
        $h = '<style>.sl-painel{margin:8px 12px 0;border:1px solid #dfe9e4;border-left:4px solid var(--sl-cor,#6c757d);border-radius:8px;background:#fbfdfc;padding:8px 12px;font-size:12px}'
            . '.sl-painel .sl-linha{display:flex;flex-wrap:wrap;align-items:center;gap:6px 16px}.sl-painel .sl-rotulo{color:#6b8279}.sl-painel .sl-motivo{margin-top:4px;color:#24493c}'
            . '.sl-painel details{margin-top:6px}.sl-painel summary{cursor:pointer;color:#2f7d5b;font-weight:600}.sl-painel table{margin:6px 0 0;font-size:11.5px}'
            . '.sl-painel .sl-decisao{margin-top:8px;padding-top:8px;border-top:1px dashed #cfe0d7}.sl-painel textarea.form-control{font-size:12px;height:auto;min-height:48px;padding:.25rem .5rem;border-radius:var(--bs-border-radius-sm)}'
            . '.sl-painel .sl-dimensoes{display:flex;flex-wrap:wrap;gap:8px 18px;align-items:center;margin-top:6px;padding:6px 8px;background:#fff;border:1px solid #e5eee9;border-radius:6px}'
            . '.sl-painel .sl-barra{display:inline-block;width:120px;height:8px;background:#e9ecef;border-radius:4px;vertical-align:middle;overflow:hidden}.sl-painel .sl-barra>span{display:block;height:100%;background:#198754}'
            . '.sl-painel tr.sl-na td{color:#8a9a93}.sl-painel tr.sl-na td:nth-child(2){text-decoration:line-through}.sl-painel .sl-na-just{margin-top:2px;color:#5c6f67;font-style:italic}'
            . '.sl-painel tr.sl-pendencia td{background:#fdf0f1}.text-bg-orange{background:#fd7e14!important;color:#fff!important}</style>';
        $cores = ['success' => '#198754', 'warning' => '#d39e00', 'danger' => '#dc3545', 'secondary' => '#6c757d'];
        $h .= '<div class="sl-painel" style="--sl-cor:' . $cores[$corSit[$final] ?? 'secondary'] . '" data-situacao-calculada="' . $e($calc) . '">';
        if (isset($_GET['erro_decisao'])) $h .= '<div class="alert alert-danger py-1 px-2 mb-2 small">' . $e((string)$_GET['erro_decisao']) . '</div>';
        $h .= '<div class="sl-linha"><span><span class="sl-rotulo">' . $e($tr(['pt' => 'Situação calculada', 'en' => 'Calculated status', 'es' => 'Situación calculada'])) . ':</span> '
            . ($calc === 'AI' ? '<span class="badge text-bg-secondary">' . $e($tr(['pt' => 'Sem evidências vinculadas', 'en' => 'No linked evidence', 'es' => 'Sin evidencias vinculadas'])) . '</span>' : $badge($calc)) . '</span>'
            . '<span><span class="sl-rotulo">' . $e($tr(['pt' => 'Avaliação do responsável', 'en' => 'Owner assessment', 'es' => 'Evaluación del responsable'])) . ':</span> ' . $badge($manual) . '</span>'
            . '<span><span class="sl-rotulo">' . $e($tr(['pt' => 'Vale para relatórios', 'en' => 'Used in reports', 'es' => 'Vale para informes'])) . ':</span> ' . $badge($final)
            . ' <small class="sl-rotulo">(' . $e($tr((string)$sit['ORIGEM_FINAL'] === 'AVALIACAO' ? ['pt' => 'avaliação', 'en' => 'assessment', 'es' => 'evaluación'] : ['pt' => 'calculada', 'en' => 'calculated', 'es' => 'calculada'])) . ')</small></span>'
            . ((int)$sit['DIVERGENTE'] === 1 ? '<span class="badge text-bg-warning"><i class="bi bi-exclamation-triangle me-1"></i>' . $e($tr(['pt' => 'Divergente', 'en' => 'Divergent', 'es' => 'Divergente'])) . '</span>' : '')
            . '<small class="sl-rotulo ms-auto">' . $e($tr(['pt' => 'Calculado em', 'en' => 'Calculated at', 'es' => 'Calculado en'])) . ' ' . $e($data((string)$sit['CALCULADO_EM'])) . '</small></div>';
        $h .= '<div class="sl-motivo">' . $e((string)$sit['MOTIVO']) . '</div>';
        if ($podeEditar) $h .= '<div class="mt-2"><button type="button" class="btn btn-sm btn-outline-success" data-ia-sugerir-situacao><i class="bi bi-stars me-1"></i>'
            . $e($tr(['pt' => 'Sugerir a situação com IA', 'en' => 'Suggest the status with AI', 'es' => 'Sugerir la situación con IA'])) . '</button> <small class="sl-rotulo">'
            . $e($tr(['pt' => 'a IA lê o requisito e as evidências; você aceita, altera ou ignora', 'en' => 'AI reads the requirement and evidence; you accept, change or ignore', 'es' => 'la IA lee el requisito y las evidencias; usted acepta, cambia o ignora'])) . '</small></div>'
            . '<script>window.addEventListener(\'load\',function(){var b=document.querySelector(\'[data-ia-sugerir-situacao]\');if(!b||!window.bootstrap)return;var T=function(k,p){return typeof window.greenT===\'function\'?window.greenT(k,p):p;};var esc=function(v){var d=document.createElement(\'div\');d.textContent=v==null?\'\':String(v);return d.innerHTML;};
b.addEventListener(\'click\',function(){b.disabled=true;var o=b.innerHTML;b.innerHTML=\'<span class="spinner-border spinner-border-sm me-1"></span>\'+esc(T(\'situacao.ia.analisando\',\'A IA está analisando...\'));
fetch(location.href,{method:\'POST\',credentials:\'same-origin\',body:new URLSearchParams({action:\'ia_sugerir_situacao\'})}).then(function(r){return r.json();}).then(function(r){b.disabled=false;b.innerHTML=o;
var id=\'gpIaSituacao\',old=document.getElementById(id);if(old)old.remove();var corpo;
if(!r.ok){corpo=\'<div class="alert alert-danger mb-0">\'+esc(r.message||\'Erro\')+\'</div>\';}
else{corpo=\'<div class="alert alert-info py-2 small">\'+esc(T(\'situacao.ia.aviso\',\'Sugestão da IA a partir do requisito e das evidências vinculadas. Confira antes de aceitar: a avaliação fica registrada em seu nome.\'))+\'</div>\'
+\'<label class="form-label small mb-0">\'+esc(T(\'situacao.ia.atendido\',\'Atendido\'))+\'</label><select class="form-select form-select-sm mb-2" data-ia-at><option value=""></option><option value="S">\'+esc(T(\'comum.sim\',\'Sim\'))+\'</option><option value="N">\'+esc(T(\'comum.nao\',\'Não\'))+\'</option><option value="PA">\'+esc(T(\'situacao.ia.parcial\',\'Parcialmente\'))+\'</option></select>\'
+\'<label class="form-label small mb-0">\'+esc(T(\'situacao.ia.descricao\',\'Descrição da situação\'))+\'</label><textarea class="form-control form-control-sm" rows="5" data-ia-dsc></textarea>\'
+((r.faltas&&r.faltas.length)?\'<div class="small mt-2"><b>\'+esc(T(\'situacao.ia.faltas\',\'O que falta, segundo a IA\'))+\':</b><ul class="mb-0">\'+r.faltas.map(function(f){return \'<li>\'+esc(f)+\'</li>\';}).join(\'\')+\'</ul></div>\':\'\')
+\'<div class="small text-muted mt-1">\'+esc(T(\'situacao.ia.confianca\',\'Confiança\'))+\': \'+esc(Math.round((r.confianca>0&&r.confianca<=1?r.confianca*100:r.confianca)||0))+\'%</div><div class="alert alert-danger py-1 mt-2 mb-0 small d-none" data-ia-erro></div>\';}
document.body.insertAdjacentHTML(\'beforeend\',\'<div class="modal fade" id="\'+id+\'" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-header py-2"><h5 class="modal-title"><i class="bi bi-stars me-1"></i>\'+esc(T(\'situacao.ia.titulo\',\'Sugestão de situação pela IA\'))+\'</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">\'+corpo+\'</div><div class="modal-footer py-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">\'+esc(T(\'situacao.ia.ignorar\',\'Ignorar\'))+\'</button>\'+(r.ok?\'<button type="button" class="btn btn-sm btn-success" data-ia-aceitar><i class="bi bi-check2 me-1"></i>\'+esc(T(\'situacao.ia.aceitar\',\'Aceitar como avaliação\'))+\'</button>\':\'\')+\'</div></div></div></div>\');
var m=document.getElementById(id);if(r.ok){m.querySelector(\'[data-ia-at]\').value=r.atendido||\'\';m.querySelector(\'[data-ia-dsc]\').value=r.descricao||\'\';
m.querySelector(\'[data-ia-aceitar]\').addEventListener(\'click\',function(){var at=m.querySelector(\'[data-ia-at]\').value,er=m.querySelector(\'[data-ia-erro]\');if(!at){er.classList.remove(\'d-none\');er.textContent=T(\'situacao.ia.escolha\',\'Escolha o atendimento.\');return;}this.disabled=true;
fetch(location.href,{method:\'POST\',credentials:\'same-origin\',body:new URLSearchParams({action:\'ia_aceitar_situacao\',atendido:at,sugerido:r.atendido||\'\',dscsituacao:m.querySelector(\'[data-ia-dsc]\').value})}).then(function(x){return x.json();}).then(function(x){if(x.ok){location.href=location.pathname+location.search.replace(/&?salvo=1/,\'\')+(location.search?\'&\':\'?\')+\'salvo=1\';}else{er.classList.remove(\'d-none\');er.textContent=x.message||\'Erro\';}});});}
bootstrap.Modal.getOrCreateInstance(m).show();}).catch(function(){b.disabled=false;b.innerHTML=o;});});});</script>';
        // Migração 341: % de atendimento e risco (duas dimensões independentes) e a conta item a item
        $com341 = array_key_exists('PCT_ATENDIMENTO', $sit);
        if ($com341) $h .= self::linhaAtendimentoRisco($sit, $tr, $e);
        if ($itens) {
            $naInfo = [];
            if ($com341) {
                try {
                    foreach ($call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,500,0)', ['tabela' => 'vw_ml_requisito_item_na', 'filtros' => $json(['CODLEGISLACAO' => $leg, 'CODUNIDADE' => $unit])]) as $n)
                        $naInfo[(string)$n['AREA'] . ':' . (string)$n['ITEM_ID']] = $n;
                } catch (Throwable $semNa) {}
            }
            // Migração 342: risco que veio de uma sugestão da IA (aceita ou ajustada) ganha o selo "IA"
            $origemIa = [];
            if ($com341) {
                $porArea = [];
                foreach ($itens as $i) if ((string)($i['RISCO'] ?? '') !== '') $porArea[(string)$i['AREA']][] = (int)($i['CHAVE'] ?? $i['ITEM_ID']);
                foreach ($porArea as $a => $ch) foreach (RequisitoRiscoIa::origens($pdo, $a, $ch) as $k => $o) if ($o !== 'MANUAL') $origemIa[$a . ':' . $k] = $o;
            }
            $ordemRisco = static fn(array $i): int => (int)($i['NA'] ?? 0) === 1 ? 9 : ((in_array((string)$i['STATUS'], ['FALHA', 'CRITICO'], true) && (string)($i['RISCO'] ?? '') === 'CRITICO') ? 0 : 1);
            usort($itens, static fn(array $a, array $b): int => [$ordemRisco($a), array_search($a['STATUS'], ['CRITICO', 'FALHA', 'ATENCAO', 'OK'], true), $a['AREA'], $a['ITEM']] <=> [$ordemRisco($b), array_search($b['STATUS'], ['CRITICO', 'FALHA', 'ATENCAO', 'OK'], true), $b['AREA'], $b['ITEM']]);
            $abrir = (int)$sit['ITENS_FALHA'] + (int)$sit['ITENS_CRITICOS'] > 0 || (int)($sit['PENDENCIAS_CRITICAS'] ?? 0) > 0 || isset($_GET['na_gravado']);
            $h .= '<details' . ($abrir ? ' open' : '') . '><summary>' . $e($tr(['pt' => 'Itens de gestão considerados', 'en' => 'Management items considered', 'es' => 'Ítems de gestión considerados'])) . ' (' . count($itens) . ')</summary>'
                . '<table class="table table-sm mb-0 sl-itens"><thead><tr><th>' . $e($tr(['pt' => 'Área', 'en' => 'Area', 'es' => 'Área'])) . '</th><th>' . $e($tr(['pt' => 'Item', 'en' => 'Item', 'es' => 'Ítem'])) . '</th><th>Status</th><th>' . $e($tr(['pt' => 'Motivo', 'en' => 'Reason', 'es' => 'Motivo'])) . '</th>'
                . ($com341 ? '<th title="' . $e($tr(['pt' => 'Severidade × Probabilidade (informadas no cadastro do item)', 'en' => 'Severity × Probability (set in the item register)', 'es' => 'Severidad × Probabilidad (informadas en el registro del ítem)'])) . '">' . $e($tr(['pt' => 'Sev. × Prob.', 'en' => 'Sev. × Prob.', 'es' => 'Sev. × Prob.'])) . '</th><th>' . $e($tr(['pt' => 'Risco', 'en' => 'Risk', 'es' => 'Riesgo'])) . '</th>' . ($podeEditar ? '<th></th>' : '') : '')
                . '</tr></thead><tbody>';
            foreach ($itens as $i) {
                [$st, $cor] = $rotStatus[(string)$i['STATUS']] ?? [(string)$i['STATUS'], 'secondary'];
                $na = (int)($i['NA'] ?? 0) === 1;
                $risco = (string)($i['RISCO'] ?? '');
                $pend = !$na && $risco === 'CRITICO' && in_array((string)$i['STATUS'], ['FALHA', 'CRITICO'], true);
                $h .= '<tr class="' . ($na ? 'sl-na' : ($pend ? 'sl-pendencia' : '')) . '"><td>' . $e($tr($rotArea[(string)$i['AREA']] ?? ['pt' => (string)$i['AREA']])) . '</td><td>' . $e((string)$i['ITEM'])
                    . ($pend ? ' <span class="badge text-bg-danger" title="' . $e($tr(['pt' => 'Item não atendido com risco Crítico: ação imediata', 'en' => 'Item not met with Critical risk: immediate action', 'es' => 'Ítem no cumplido con riesgo Crítico: acción inmediata'])) . '"><i class="bi bi-exclamation-octagon me-1"></i>' . $e($tr(['pt' => 'Pendência crítica', 'en' => 'Critical pending', 'es' => 'Pendiente crítico'])) . '</span>' : '')
                    . '</td><td><span class="badge text-bg-' . $cor . '">' . $e($st) . '</span></td><td>' . $e((string)$i['MOTIVO']);
                if ($na) {
                    $ni = $naInfo[(string)$i['AREA'] . ':' . (string)($i['CHAVE'] ?? $i['ITEM_ID'])] ?? null;
                    $h .= '<div class="sl-na-just"><i class="bi bi-slash-circle me-1"></i><strong>' . $e($tr(['pt' => 'Não se aplica', 'en' => 'Not applicable', 'es' => 'No se aplica'])) . ':</strong> ' . $e((string)($i['NA_JUSTIFICATIVA'] ?? ''))
                        . ($ni ? ' <small class="sl-rotulo">(' . $e((string)$ni['NOME_USUARIO']) . ', ' . $e($data((string)$ni['MARCADO_EM'])) . ')</small>' : '') . '</div>';
                }
                $h .= '</td>';
                if ($com341) {
                    $sev = (int)($i['SEVERIDADE'] ?? 0); $prob = (int)($i['PROBABILIDADE'] ?? 0);
                    $h .= '<td>' . ($sev && $prob ? $sev . ' × ' . $prob . ' = ' . ($sev * $prob) : '<span class="sl-rotulo">—</span>') . '</td><td>' . ($na ? '<span class="sl-rotulo">—</span>' : RequisitoRisco::selo($risco !== '' ? $risco : 'NAO_AVALIADO')
                        . (isset($origemIa[(string)$i['AREA'] . ':' . (int)($i['CHAVE'] ?? $i['ITEM_ID'])]) ? ' <span class="badge text-bg-light border" title="' . $e($tr($origemIa[(string)$i['AREA'] . ':' . (int)($i['CHAVE'] ?? $i['ITEM_ID'])] === 'IA' ? ['pt' => 'Sugerido pela IA e aceito', 'en' => 'Suggested by the AI and accepted', 'es' => 'Sugerido por la IA y aceptado'] : ['pt' => 'Sugerido pela IA e ajustado', 'en' => 'Suggested by the AI and adjusted', 'es' => 'Sugerido por la IA y ajustado'])) . '"><i class="bi bi-stars"></i> IA</span>' : '')) . '</td>';
                    if ($podeEditar) {
                        $chave = (int)($i['CHAVE'] ?? $i['ITEM_ID']);
                        $h .= '<td class="text-nowrap">' . ($na
                            ? '<form method="post" class="d-inline"><input type="hidden" name="action" value="item_nao_se_aplica"><input type="hidden" name="area" value="' . $e((string)$i['AREA']) . '"><input type="hidden" name="item" value="' . $chave . '"><input type="hidden" name="na" value="0"><button class="btn btn-link btn-sm p-0" type="submit"><i class="bi bi-arrow-counterclockwise me-1"></i>' . $e($tr(['pt' => 'Volta a se aplicar', 'en' => 'Applies again', 'es' => 'Vuelve a aplicarse'])) . '</button></form>'
                            : '<button class="btn btn-link btn-sm p-0" type="button" data-sl-na-abrir><i class="bi bi-slash-circle me-1"></i>' . $e($tr(['pt' => 'Não se aplica', 'en' => 'Not applicable', 'es' => 'No se aplica'])) . '</button>')
                            . '</td>';
                    }
                }
                $h .= '</tr>';
                if ($com341 && $podeEditar && !$na) {
                    $h .= '<tr class="d-none sl-na-form-linha"><td colspan="7"><form method="post" class="d-flex flex-wrap gap-2 align-items-start" data-sl-na-form><input type="hidden" name="action" value="item_nao_se_aplica"><input type="hidden" name="area" value="' . $e((string)$i['AREA']) . '"><input type="hidden" name="item" value="' . (int)($i['CHAVE'] ?? $i['ITEM_ID']) . '"><input type="hidden" name="na" value="1">'
                        . '<textarea class="form-control form-control-sm flex-grow-1" name="justificativa" rows="2" maxlength="1000" style="min-width:260px" placeholder="' . $e($tr(['pt' => 'Por que este item não se aplica a este requisito nesta unidade? (obrigatório, pelo menos 10 caracteres)', 'en' => 'Why does this item not apply to this requirement in this unit? (required, at least 10 characters)', 'es' => '¿Por qué este ítem no se aplica a este requisito en esta unidad? (obligatorio, al menos 10 caracteres)'])) . '"></textarea>'
                        . '<button class="btn btn-sm btn-success" type="submit"><i class="bi bi-check2 me-1"></i>' . $e($tr(['pt' => 'Confirmar', 'en' => 'Confirm', 'es' => 'Confirmar'])) . '</button><button class="btn btn-sm btn-outline-secondary" type="button" data-sl-na-cancelar>' . $e($tr(['pt' => 'Cancelar', 'en' => 'Cancel', 'es' => 'Cancelar'])) . '</button>'
                        . '<span class="text-danger small d-none" data-sl-na-aviso>' . $e($tr(['pt' => 'Informe a justificativa (pelo menos 10 caracteres).', 'en' => 'Enter the justification (at least 10 characters).', 'es' => 'Informe la justificación (al menos 10 caracteres).'])) . '</span></form></td></tr>';
                }
            }
            $h .= '</tbody></table></details>';
            if ($com341 && $podeEditar) $h .= '<script>(function(){document.querySelectorAll("[data-sl-na-abrir]").forEach(function(b){b.addEventListener("click",function(){var l=b.closest("tr").nextElementSibling;if(l&&l.classList.contains("sl-na-form-linha")){l.classList.remove("d-none");var t=l.querySelector("textarea");if(t)t.focus();}});});'
                . 'document.querySelectorAll("[data-sl-na-cancelar]").forEach(function(b){b.addEventListener("click",function(){b.closest("tr").classList.add("d-none");});});'
                . 'document.querySelectorAll("[data-sl-na-form]").forEach(function(f){f.addEventListener("submit",function(ev){if(f.elements.justificativa.value.trim().length<10){ev.preventDefault();f.querySelector("[data-sl-na-aviso]").classList.remove("d-none");f.elements.justificativa.focus();}});});})();</script>';
        }
        $decisaoValida = (int)$sit['DECISAO_VALIDA'] === 1;
        if ($decisaoValida) {
            $h .= '<div class="sl-decisao"><i class="bi bi-person-check me-1"></i>' . $e($tr((string)$sit['DECISAO'] === 'MANTIDA' ? ['pt' => 'Avaliação mantida', 'en' => 'Assessment kept', 'es' => 'Evaluación mantenida'] : ['pt' => 'Situação calculada aceita', 'en' => 'Calculated status accepted', 'es' => 'Situación calculada aceptada']))
                . ' ' . $e($tr(['pt' => 'por', 'en' => 'by', 'es' => 'por'])) . ' ' . $e((string)$sit['DECISAO_USUARIO']) . ' ' . $e($tr(['pt' => 'em', 'en' => 'on', 'es' => 'el'])) . ' ' . $e($data((string)$sit['DECISAO_EM']))
                . ((string)$sit['DECISAO_JUSTIFICATIVA'] !== '' ? ': <em>' . $e((string)$sit['DECISAO_JUSTIFICATIVA']) . '</em>' : '');
            if ($podeEditar) $h .= ' <form method="post" class="d-inline"><input type="hidden" name="action" value="decidir_situacao_calculada"><input type="hidden" name="decisao" value="LIMPAR"><button class="btn btn-link btn-sm p-0 ms-2" type="submit">' . $e($tr(['pt' => 'Desfazer decisão', 'en' => 'Undo decision', 'es' => 'Deshacer decisión'])) . '</button></form>';
            $h .= '</div>';
        } elseif ((int)$sit['DIVERGENTE'] === 1 && $podeEditar) {
            $h .= '<form method="post" class="sl-decisao" data-sl-decisao><input type="hidden" name="action" value="decidir_situacao_calculada"><input type="hidden" name="decisao" value="">'
                . '<div class="mb-1">' . $e($tr(['pt' => 'A avaliação do responsável diverge da situação calculada. Qual deve valer?', 'en' => 'The owner assessment differs from the calculated status. Which one should apply?', 'es' => 'La evaluación del responsable difiere de la situación calculada. ¿Cuál debe valer?'])) . '</div>'
                . '<textarea class="form-control form-control-sm mb-1" name="justificativa" rows="2" maxlength="2000" placeholder="' . $e($tr(['pt' => 'Justificativa (obrigatória para manter a avaliação)', 'en' => 'Justification (required to keep the assessment)', 'es' => 'Justificación (obligatoria para mantener la evaluación)'])) . '"></textarea>'
                . '<button class="btn btn-sm btn-success me-2" type="submit" data-sl-valor="ACEITAR"><i class="bi bi-check2 me-1"></i>' . $e($tr(['pt' => 'Aceitar a calculada', 'en' => 'Accept calculated', 'es' => 'Aceptar la calculada'])) . '</button>'
                . '<button class="btn btn-sm btn-outline-secondary" type="submit" data-sl-valor="MANTER"><i class="bi bi-person-lock me-1"></i>' . $e($tr(['pt' => 'Manter a avaliação', 'en' => 'Keep assessment', 'es' => 'Mantener la evaluación'])) . '</button>'
                . '<span class="text-danger small ms-2 d-none" data-sl-aviso>' . $e($tr(['pt' => 'Informe a justificativa (pelo menos 10 caracteres).', 'en' => 'Enter the justification (at least 10 characters).', 'es' => 'Informe la justificación (al menos 10 caracteres).'])) . '</span></form>'
                . '<script>document.querySelectorAll("[data-sl-decisao]").forEach(function(f){f.querySelectorAll("[data-sl-valor]").forEach(function(b){b.addEventListener("click",function(){f.elements.decisao.value=b.getAttribute("data-sl-valor");});});f.addEventListener("submit",function(ev){var aviso=f.querySelector("[data-sl-aviso]");if(f.elements.decisao.value==="MANTER"&&f.elements.justificativa.value.trim().length<10){ev.preventDefault();aviso.classList.remove("d-none");f.elements.justificativa.focus();}});});</script>';
        }
        return $h . '</div>';
    }
    /** Linha das duas dimensões do requisito (migração 341): % de atendimento com a conta, risco, pendências críticas e itens sem risco. */
    private static function linhaAtendimentoRisco(array $sit, callable $tr, callable $e): string
    {
        $aplic = (int)($sit['ITENS_APLICAVEIS'] ?? 0); $atend = (int)($sit['ITENS_ATENDIDOS'] ?? 0); $na = (int)($sit['ITENS_NA'] ?? 0);
        $pct = $sit['PCT_ATENDIMENTO'] === null || $sit['PCT_ATENDIMENTO'] === '' ? null : (float)$sit['PCT_ATENDIMENTO'];
        $pend = (int)($sit['PENDENCIAS_CRITICAS'] ?? 0); $semRisco = (int)($sit['ITENS_SEM_RISCO'] ?? 0);
        $fmt = static fn(float $v): string => rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',');
        $corPct = $pct === null ? '#6c757d' : ($pct >= 100 ? '#198754' : ($pct >= 50 ? '#d39e00' : '#dc3545'));
        $h = '<div class="sl-dimensoes" data-sl-pct="' . ($pct === null ? '' : $e((string)$pct)) . '" data-sl-risco="' . $e((string)($sit['RISCO'] ?? '')) . '">';
        $h .= '<span title="' . $e($tr(['pt' => 'Itens aplicáveis atendidos (Ok ou Atenção) ÷ itens aplicáveis (sem os marcados como não se aplica)', 'en' => 'Applicable items met (Ok or Attention) ÷ applicable items (without the not applicable ones)', 'es' => 'Ítems aplicables cumplidos (Ok o Atención) ÷ ítems aplicables (sin los no aplicables)'])) . '"><span class="sl-rotulo">' . $e($tr(['pt' => '% de atendimento', 'en' => 'Compliance %', 'es' => '% de cumplimiento'])) . ':</span> ';
        if ($pct === null) $h .= '<span class="sl-rotulo">' . $e($tr(['pt' => 'sem itens aplicáveis', 'en' => 'no applicable items', 'es' => 'sin ítems aplicables'])) . '</span>';
        else $h .= '<strong style="color:' . $corPct . '">' . $e($fmt($pct)) . '%</strong> <span class="sl-barra"><span style="width:' . max(0, min(100, $pct)) . '%;background:' . $corPct . '"></span></span> <small class="sl-rotulo">(' . $atend . ' ' . $e($tr(['pt' => 'de', 'en' => 'of', 'es' => 'de'])) . ' ' . $aplic . ($na > 0 ? '; ' . $na . ' ' . $e($tr(['pt' => 'não se aplica', 'en' => 'not applicable', 'es' => 'no se aplica'])) : '') . ')</small>';
        $h .= '</span>';
        $risco = (string)($sit['RISCO'] ?? '');
        $h .= '<span title="' . $e($tr(['pt' => 'Pior risco entre os itens aplicáveis (Severidade × Probabilidade de cada item)', 'en' => 'Worst risk among the applicable items (Severity × Probability of each item)', 'es' => 'Peor riesgo entre los ítems aplicables (Severidad × Probabilidad de cada ítem)'])) . '"><span class="sl-rotulo">' . $e($tr(['pt' => 'Risco do requisito', 'en' => 'Requirement risk', 'es' => 'Riesgo del requisito'])) . ':</span> ' . ($risco !== '' ? RequisitoRisco::selo($risco) : '<span class="sl-rotulo">—</span>') . '</span>';
        if ($pend > 0) $h .= '<span class="badge text-bg-danger"><i class="bi bi-exclamation-octagon me-1"></i>' . $pend . ' ' . $e($tr($pend === 1 ? ['pt' => 'pendência crítica — ação imediata', 'en' => 'critical pending item — immediate action', 'es' => 'pendiente crítico — acción inmediata'] : ['pt' => 'pendências críticas — ação imediata', 'en' => 'critical pending items — immediate action', 'es' => 'pendientes críticos — acción inmediata'])) . '</span>';
        if ($semRisco > 0) $h .= '<span class="badge text-bg-light border" title="' . $e($tr(['pt' => 'Informe a severidade e a probabilidade no cadastro desses itens', 'en' => 'Fill in severity and probability in these items\' registers', 'es' => 'Informe la severidad y la probabilidad en el registro de esos ítems'])) . '"><i class="bi bi-question-diamond me-1"></i>' . $semRisco . ' ' . $e($tr($semRisco === 1 ? ['pt' => 'item com risco não avaliado', 'en' => 'item with risk not assessed', 'es' => 'ítem con riesgo no evaluado'] : ['pt' => 'itens com risco não avaliado', 'en' => 'items with risk not assessed', 'es' => 'ítems con riesgo no evaluado'])) . '</span>';
        return $h . '</div>';
    }
    private static function env(): array { $r=[]; foreach(file(dirname(__DIR__).DIRECTORY_SEPARATOR.'.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){$line=trim($line);if($line===''||$line[0]==='#'||!str_contains($line,'='))continue;[$k,$v]=explode('=',$line,2);$r[trim($k)]=trim(trim($v),'"\'');}return $r; }
}
