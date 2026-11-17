<?php
declare(strict_types=1);

require_once __DIR__ . '/UsuarioSessao.php';
require_once __DIR__ . '/PermissaoTransacao.php';
require_once __DIR__ . '/GreenPortalDatabase.php';
require_once __DIR__ . '/TenantContext.php';
require_once dirname(__DIR__) . '/components/botoes_crud.php';
require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/IdiomaService.php';
require_once __DIR__ . '/ExportWorkerLauncher.php';
require_once __DIR__ . '/RequisitoRisco.php';
require_once __DIR__ . '/RequisitoRiscoIa.php';
require_once __DIR__ . '/HistoricoAlteracoes.php';

final class ManutencaoLegalCadastroGrid
{
    public static function render(array $cfg): void
    {
        $gridStartedAt = microtime(true);
        if (in_array((string)($cfg['table'] ?? ''), ['legislacaoxtreinamentos', 'legislacaoxinfraestruturas'], true)) {
            $cfg['primary_auto'] = true;
            $cfg['primary_editable'] = false;
        }
        if (($cfg['transaction'] ?? '') === 'manutencaolegalrequisitoslegais_medicaomonitoramento' && ($cfg['table'] ?? '') === 'mm_monitoramentoxlegislacao') {
            $cfg['primary'] = 'codmonitoramentoxlegislacao';
            $cfg['primary_auto'] = true;
            $cfg['primary_editable'] = false;
            $cfg['fields']['codmonitoramentoxlegislacao'] = ['label' => ['pt' => 'Chave técnica', 'en' => 'Technical key', 'es' => 'Clave técnica'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'];
        }
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Content-Type: text/html; charset=utf-8');
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['autenticado'])) {
            header('Location: ../index.php?erro=sessao_expirada');
            exit;
        }
        $env = self::env();
        $dsn = static function (string $database, string $prefix) use ($env): string {
            return 'mysql:host=' . ($env[$prefix . '_DB_HOST'] ?? '127.0.0.1') .
                ';port=' . ($env[$prefix . '_DB_PORT'] ?? '3306') .
                ';dbname=' . $database . ';charset=utf8mb4';
        };
        $tenant = TenantContext::connect($env, $_SESSION);
        if (!UsuarioSessao::atualizar($tenant, $_SESSION)) {
            header('Location: ../index.php?erro=sessao_expirada');
            exit;
        }
        $permission = PermissaoTransacao::obter((string)$cfg['menu']);
        AuditService::record($tenant, (string)($cfg['transaction'] ?? $cfg['menu']), 'ACESSAR', 'SUCESSO', 'transacao', (string)($cfg['transaction'] ?? $cfg['menu']));
        if (!$permission['pode_visualizar']) {
            http_response_code(403);
            exit(htmlspecialchars(IdiomaService::t('comum.erro.sem_permissao', ['pt' => 'Seu perfil não tem permissão para acessar esta transação.', 'en' => 'Your profile is not allowed to access this transaction.', 'es' => 'Su perfil no tiene permiso para acceder a esta transacción.']), ENT_QUOTES, 'UTF-8'));
        }
        // Grades contextuais seguem a permissão da transação principal. Sem
        // incluir, editar ou excluir, a consulta deve ser efetivamente
        // somente leitura, inclusive nos campos renderizados.
        $crudPermission = $permission;
        if (!empty($cfg['association_include_with_edit']) && !empty($permission['pode_editar'])) $crudPermission['pode_incluir'] = 1;
        if (empty($permission['pode_incluir']) && empty($permission['pode_editar']) && empty($permission['pode_excluir'])) {
            $cfg['read_only_grid'] = true;
        }
        $permission = $crudPermission;
        // A configuração histórica da transação pode manter o banco Modelo
        // como referência, mas a conexão efetiva precisa sempre respeitar o
        // tenant autenticado na sessão.
        $tenantDatabase = (string)($_SESSION['tenant']['banco_dados'] ?? '');
        if ($tenantDatabase === '' || !preg_match('/^[A-Za-z0-9_]+$/', $tenantDatabase)) {
            http_response_code(401);
            exit(htmlspecialchars(IdiomaService::t('comum.erro.sessao', ['pt' => 'Sua sessão expirou. Entre novamente no portal.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar al portal.']), ENT_QUOTES, 'UTF-8'));
        }
        // Cadastros da base compartilhada de legislação (prefixo GLEGISLACAO: tipos, âmbitos, assuntos) ficam no banco
        // da própria configuração; os demais (prefixo GPT) sempre no banco do cliente da sessão.
        if (strtoupper((string)$cfg['prefix']) !== 'GPT' && preg_match('/^[A-Za-z0-9_]+$/', (string)($cfg['database'] ?? ''))) $tenantDatabase = (string)$cfg['database'];
        $target = new PDO($dsn($tenantDatabase, (string)$cfg['prefix']), $env[$cfg['prefix'] . '_DB_USERNAME'] ?? '', $env[$cfg['prefix'] . '_DB_PASSWORD'] ?? '', self::pdoOptions());
        // Migração 361 (Responsáveis e avisos): 'resp_minhas' => área — a tela "Minhas" usa vw_resp_minhas_<área> (Responsável do
        // registro + atribuições de usuário e grupo + regras do módulo), filtrada por CODUSUARIO_ACESSO. Sem a 361, fica como estava.
        $respMinhas = strtoupper((string)($cfg['resp_minhas'] ?? ''));
        if ($respMinhas !== '') {
            require_once __DIR__ . '/ResponsaveisRegras.php';
            try { $viewMinhas361 = ResponsaveisRegras::viewMinhas($target, $respMinhas); } catch (Throwable) { $viewMinhas361 = null; }
            if ($viewMinhas361 !== null) {
                $escopoAnterior = (string)($cfg['user_scope_field'] ?? '');
                if ($escopoAnterior !== '' && isset($cfg['default_filters'][$escopoAnterior])) unset($cfg['default_filters'][$escopoAnterior]);
                $cfg['view'] = $viewMinhas361;
                $cfg['user_scope_field'] = 'CODUSUARIO_ACESSO';
                $cfg['force_user_scope'] = true;
            }
        }
        // 'risk_area' (migração 341): colunas Severidade, Probabilidade e Risco do item (ml_risco_itens, fora da tabela do cadastro)
        $riskArea = strtoupper((string)($cfg['risk_area'] ?? ''));
        if ($riskArea !== '' && (!isset(RequisitoRisco::AREAS[$riskArea]) || !RequisitoRisco::disponivel($target))) $riskArea = '';
        if ($riskArea !== '') $cfg = self::camposRisco($cfg, $riskArea);
        // Migração 342: botão "Sugerir risco com IA" (linha selecionada) para quem pode editar o cadastro
        if ($riskArea !== '' && !empty($permission['pode_editar']) && empty($cfg['read_only_grid']) && RequisitoRiscoIa::disponivel($target)) {
            $rotuloIa = IdiomaService::t('legal.risco.ia_sugerir', ['pt' => 'Sugerir risco com IA (linha selecionada): a IA propõe a Severidade e a Probabilidade; a resposta chega nos Alertas e nada é gravado sem você aceitar', 'en' => 'Suggest risk with AI (selected row): the AI proposes Severity and Probability; the answer arrives in the Alerts and nothing is saved without your decision', 'es' => 'Sugerir riesgo con IA (fila seleccionada): la IA propone Severidad y Probabilidad; la respuesta llega a las Alertas y no se graba nada sin su decisión']);
            $cfg['extras'] = (string)($cfg['extras'] ?? '') . '<button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-risco-ia-sugerir data-area="' . htmlspecialchars($riskArea, ENT_QUOTES, 'UTF-8') . '" data-csrf="' . htmlspecialchars(RequisitoRiscoIa::csrf(), ENT_QUOTES, 'UTF-8') . '" disabled title="' . htmlspecialchars($rotuloIa, ENT_QUOTES, 'UTF-8') . '" aria-label="' . htmlspecialchars($rotuloIa, ENT_QUOTES, 'UTF-8') . '"><i class="bi bi-stars"></i></button>';
        }
        $call = static function (PDO $pdo, string $sql, array $params = []): array {
            $statement = $pdo->prepare($sql);
            $statement->execute($params);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement->closeCursor();
            return $rows;
        };
        $json = static fn(mixed $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $filters = json_decode((string)($_GET['filtros'] ?? '{}'), true);
        $filters = is_array($filters) ? $filters : [];
        // Popups contextuais recebem as chaves do requisito diretamente na
        // URL (por exemplo, CODLEGISLACAO e CODUNIDADE). Converta esses
        // parâmetros para os filtros internos antes de consultar a view;
        // sem isso o CRUD grava a relação, mas o grid recarrega sem contexto.
        foreach ((array)($cfg['context_crud_keys'] ?? []) as $contextField) {
            $contextField = (string)$contextField;
            if ($contextField === '') continue;
            foreach ($_GET as $queryField => $queryValue) {
                if (strcasecmp((string)$queryField, $contextField) !== 0) continue;
                if (is_scalar($queryValue) && trim((string)$queryValue) !== '') $filters[$contextField] = trim((string)$queryValue);
                break;
            }
        }
        foreach ((array)($cfg['default_filters'] ?? []) as $defaultField => $defaultValue) {
            if (!array_key_exists((string)$defaultField, $filters)) $filters[(string)$defaultField] = $defaultValue;
        }
        $userScopeField = trim((string)($cfg['user_scope_field'] ?? ''));
        $forceUserScope = !empty($cfg['force_user_scope']);
        if ($userScopeField !== '' && ($forceUserScope || empty($permission['pode_visualizar_total']))) {
            $filters[$userScopeField] = (int)($_SESSION['usuario']['codusuario'] ?? 0);
        }
        $forcedContextFields = array_values(array_filter(array_map('strval', (array)($cfg['context_fields'] ?? []))));
        foreach ($forcedContextFields as $contextField) {
            $contextValue = '';
            foreach ($filters as $filterField => $filterValue) {
                if (strcasecmp((string)$filterField, $contextField) === 0) { $contextValue = trim((string)$filterValue); break; }
            }
            if ($contextValue === '') $contextValue = '__SEM_CONTEXTO__';
            $filters[$contextField] = $contextValue;
            if (isset($cfg['fields'][$contextField])) {
                $cfg['fields'][$contextField]['hidden'] = true;
                $cfg['fields'][$contextField]['readonly'] = true;
                $cfg['fields'][$contextField]['filter'] = 'none';
            }
        }
        $mainCorrelationTransactions = ['modulolegal_correlacionamnetoambiental', 'modulolegal_correlacionamentoocupacional', 'modulolegal_correlacionamentosocial'];
        $isCorrelationChild = str_starts_with((string)($cfg['transaction'] ?? ''), 'modulolegal_correlacionamento') && !in_array((string)($cfg['transaction'] ?? ''), $mainCorrelationTransactions, true);
        $isContextCrud = !empty($cfg['context_crud']);
        $contextKeyFields = $isCorrelationChild ? ['CODLEGISLACAO', 'codunidade'] : ($isContextCrud ? array_values(array_filter(array_map('strval', (array)($cfg['context_crud_keys'] ?? [])))) : []);
        foreach ($contextKeyFields as $index => $contextField) {
            foreach ((array)($cfg['fields'] ?? []) as $configuredField => $_fieldConfig) {
                if (strcasecmp((string)$configuredField, (string)$contextField) === 0) { $contextKeyFields[$index] = (string)$configuredField; break; }
            }
        }
        if ($isCorrelationChild || $isContextCrud) {
            $normalizedFilters = $filters;
            foreach ($contextKeyFields as $contextField) {
                foreach ($filters as $filterField => $filterValue) {
                    if (strcasecmp((string)$filterField, $contextField) !== 0) continue;
                    if ((string)$filterField !== $contextField) unset($normalizedFilters[$filterField]);
                    $normalizedFilters[$contextField] = $filterValue;
                }
            }
            $filters = $normalizedFilters;
        }
        $visibleContextKeys = array_map('strtolower', array_map('strval', (array)($cfg['context_key_visible'] ?? [])));
        foreach ($contextKeyFields as $contextField) {
            if (!isset($cfg['fields'][$contextField])) continue;
            $cfg['fields'][$contextField]['readonly'] = true;
            if (!in_array(strtolower((string)$contextField), $visibleContextKeys, true)) {
                $cfg['fields'][$contextField]['hidden'] = true;
                $cfg['fields'][$contextField]['filter'] = 'none';
            }
        }
        if (($isCorrelationChild || $isContextCrud) && empty($cfg['read_only_grid'])) {
            if (isset($cfg['fields'][$cfg['primary']]) && empty($cfg['association_read_only'])) $cfg['fields'][$cfg['primary']]['readonly'] = false;
            if (isset($cfg['fields'][$cfg['primary']]) && empty($cfg['association_read_only'])) $cfg['fields'][$cfg['primary']]['inline_edit'] = true;
            $cfg['button_options'] = array_merge((array)($cfg['button_options'] ?? []), ['save' => true, 'new' => true, 'duplicate' => true, 'delete' => true, 'reload' => true, 'export' => true, 'columns' => true]);
            if (!empty($cfg['association_read_only'])) $cfg['button_options']['duplicate'] = false;
        }
        if (!empty($cfg['primary_auto']) && isset($cfg['fields'][$cfg['primary']])) {
            $cfg['fields'][$cfg['primary']]['readonly'] = true;
            $cfg['fields'][$cfg['primary']]['hidden'] = true;
            $cfg['fields'][$cfg['primary']]['filter'] = 'none';
        }
        if (!empty($cfg['association_read_only']) && isset($cfg['fields'][$cfg['primary']])) {
            $cfg['fields'][$cfg['primary']]['force_visible'] = true;
        }
        $search = trim((string)($_GET['busca'] ?? ''));
        $page = max(1, (int)($_GET['pagina'] ?? 1));
        $size = 25;
        $language = in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$_SESSION['idioma'] : 'pt';
        $translate = static function (mixed $value) use ($language): string { if (is_array($value)) return (string)($value[$language] ?? $value['pt'] ?? reset($value) ?? ''); return (string)$value; };
        $permissionTitle = IdiomaService::t('comum.permissao.negada', ['pt' => 'Perfil sem permissão de acesso!', 'en' => 'Profile does not have access permission!', 'es' => '¡El perfil no tiene permiso de acceso!']);

        // Foreign keys editáveis declaram sua fonte no próprio campo. Os
        // relacionamentos legados conhecidos recebem o mesmo padrão automaticamente.
        if (!empty($cfg['read_only_grid'])) {
            foreach ((array)($cfg['fields'] ?? []) as $field => $fieldCfg) {
                $cfg['fields'][$field]['readonly'] = true;
            }
        }
        if (($cfg['transaction'] ?? '') === 'manutencaolegal_licencas_condicionantes') {
            foreach (['DSCCONDICIONANTE', 'FREQUENCIA', 'ATENDIDO', 'OBS'] as $field) {
                if (isset($cfg['fields'][$field])) $cfg['fields'][$field]['readonly'] = false;
            }
            if (isset($cfg['fields']['RESPONSAVEL'])) {
                $cfg['fields']['RESPONSAVEL']['hidden'] = true;
                $cfg['fields']['RESPONSAVEL']['filter'] = 'none';
            }
            // A tela pode declarar o próprio Responsável (lista, display_from, vazio permitido); senão vale o padrão.
            if (empty($cfg['fields']['CODUSUARIO']['foreign'])) $cfg['fields']['CODUSUARIO'] = [
                'label' => ['pt' => 'Responsavel', 'en' => 'Responsible', 'es' => 'Responsable'],
                'filter' => 'select', 'width' => 180,
                'foreign' => ['view' => 'vw_usuarios_grid', 'value' => 'codusuario', 'label' => 'nome', 'filters' => ['ativo' => 1], 'description' => 'nome'],
            ];
        }
        if (!empty($cfg['primary_editable']) && isset($cfg['fields'][$cfg['primary']])) {
            $cfg['fields'][$cfg['primary']]['force_visible'] = true;
        }
        $foreignDefaults = [
            'CODUNIDADE' => ['view' => 'vw_unidades_grid', 'value' => 'CODUNIDADE', 'label' => 'DSCUNIDADE', 'filters' => ['usado_em_manutencao_legal' => 'Sim'], 'description' => 'DSCUNIDADE'],
            'CODAREA' => ['view' => 'vw_areas_grid', 'value' => 'CODAREA', 'label' => 'DSCAREA', 'description' => 'DSCAREA'],
            'CODATIVIDADE' => ['view' => 'vw_atividades_grid', 'value' => 'CODATIVIDADE', 'label' => 'DSCATIVIDADE', 'description' => 'DSCATIVIDADE'],
            'CODPERIGO' => ['view' => 'vw_perigos_grid', 'value' => 'CODPERIGO', 'label' => 'DSCPERIGO', 'description' => 'DSCPERIGO'],
            'CODRISCO' => ['view' => 'vw_riscos_grid', 'value' => 'CODRISCO', 'label' => 'DSCRISCO', 'description' => 'DSCRISCO'],
            'CODASPECTO' => ['view' => 'vw_aspectos_grid', 'value' => 'CODASPECTO', 'label' => 'DSCASPECTO', 'description' => 'DSCASPECTO'],
            'CODIMPACTO' => ['view' => 'vw_impactos_grid', 'value' => 'CODIMPACTO', 'label' => 'DSCIMPACTO', 'description' => 'DSCIMPACTO'],
            'CODPARTE' => ['view' => 'lais_partes_interessadas', 'value' => 'CODPARTE', 'label' => 'DSCPARTE', 'description' => 'DSCPARTE'],
            'CODASPECTOSOCIAL' => ['view' => 'lais_aspectossociais', 'value' => 'CODASPECTOSOCIAL', 'label' => 'DSCASPECTOSOCIAL', 'description' => 'DSCASPECTOSOCIAL'],
            'CODIMPACTOSOCIAL' => ['view' => 'lais_impactossociais', 'value' => 'CODIMPACTOSOCIAL', 'label' => 'DSCIMPACTOSOCIAL', 'description' => 'DSCIMPACTOSOCIAL'],
            'CODLICENCA' => ['view' => 'li_licencas', 'value' => 'CODLICENCA', 'label' => 'DSCLICENCA', 'description' => 'DSCLICENCA'],
            'CODDOCUMENTO' => ['view' => 'documentos', 'value' => 'CODDOCUMENTO', 'label' => 'TITULO', 'description' => 'TITULO'],
        ];
        foreach ($foreignDefaults as $field => $foreignDefault) {
            if (!isset($cfg['fields'][$field]) || !empty($cfg['fields'][$field]['readonly'])) continue;
            if (empty($cfg['fields'][$field]['foreign'])) $cfg['fields'][$field]['foreign'] = $foreignDefault;
            $description = (string)($cfg['fields'][$field]['foreign']['description'] ?? $foreignDefault['description']);
            if (isset($cfg['fields'][$description]) && empty($cfg['fields'][$description]['show_foreign_description'])) $cfg['fields'][$description]['hidden'] = true;
        }
        // Foreign keys editáveis declaram sua fonte no próprio campo. A lista é carregada por procedure, ordenada pela descrição e sem SQL CRUD escrito na tela.
        $foreignOptionsStartedAt = microtime(true);
        foreach ((array)($cfg['fields'] ?? []) as $field => $fieldCfg) {
            $foreign = $fieldCfg['foreign'] ?? null;
            if (!is_array($foreign)) continue;
            $sourceView = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($foreign['view'] ?? ''));
            $valueField = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($foreign['value'] ?? $field));
            $labelField = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($foreign['label'] ?? 'DSC' . $field));
            if ($sourceView === '' || $valueField === '' || $labelField === '') continue;
            $sourceFilters = is_array($foreign['filters'] ?? null) ? $foreign['filters'] : [];
            // Combos relacionados não devem bloquear a renderização do grid.
            // A exceção são chaves de associação que precisam montar o
            // template completo para inclusão contextual.
            if (!array_key_exists('lazy_foreign', $cfg['fields'][$field]) && empty($fieldCfg['readonly'])) {
                $isContextPrimary = ((string)$field === (string)($cfg['primary'] ?? '')) && ($isContextCrud || $isCorrelationChild || !empty($cfg['association_read_only']));
                $cfg['fields'][$field]['lazy_foreign'] = !$isContextPrimary;
                $fieldCfg['lazy_foreign'] = !$isContextPrimary;
            }
            if (!empty($fieldCfg['lazy_foreign'])) {
                $cfg['fields'][$field]['options'] = null;
                // A lista é buscada depois por api/grid_opcoes_lazy.php, que só aceita fontes autorizadas.
                if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['grid_lazy_views'][$sourceView] = true;
                continue;
            }
            $optionRows = $call($target, 'CALL sp_crud_select(:tabela,:filtros,:busca,10000,0)', ['tabela' => $sourceView, 'filtros' => $json($sourceFilters), 'busca' => '']);
            usort($optionRows, static fn(array $a, array $b): int => strnatcasecmp(trim((string)($a[$labelField] ?? '')), trim((string)($b[$labelField] ?? ''))));
            $options = [];
            $optionRecords = [];
            foreach ($optionRows as $optionRow) { $value = (string)($optionRow[$valueField] ?? ''); $label = trim((string)($optionRow[$labelField] ?? '')); if ($value !== '' && $label !== '') { $options[$value] = $label; $optionRecords[$value] = $optionRow; } }
            if (!empty($fieldCfg['allow_empty'])) $options = ['' => ''] + $options;
            $cfg['fields'][$field]['options'] = $options;
            if ($optionRecords) $cfg['fields'][$field]['option_records'] = $optionRecords;
        }
        $foreignOptionsElapsedMs = round((microtime(true) - $foreignOptionsStartedAt) * 1000, 2);
        foreach ((array)($cfg['fields'] ?? []) as $field => $fieldCfg) {
            $sources = (array)($fieldCfg['options_sources'] ?? []);
            if (!$sources) continue;
            $options = [];
            foreach ($sources as $source) {
                $sourceTable = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($source['table'] ?? ''));
                $sourceField = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($source['field'] ?? $field));
                if ($sourceTable === '' || $sourceField === '') continue;
                $sourceStatement = $target->query('SELECT DISTINCT `' . $sourceField . '` AS option_value FROM `' . $sourceTable . '` WHERE `' . $sourceField . '` IS NOT NULL AND LENGTH(TRIM(`' . $sourceField . '`)) > 0 ORDER BY `' . $sourceField . '`');
                $sourceRows = $sourceStatement->fetchAll(PDO::FETCH_ASSOC);
                foreach ($sourceRows as $sourceRow) {
                    $value = trim((string)($sourceRow['option_value'] ?? ''));
                    if ($value !== '') $options[$value] = $value;
                }
            }
            if ($options) {
                uksort($options, static fn(string $a, string $b): int => strnatcasecmp($a, $b));
                // Lista fixa do campo (ex.: frequências do legado) primeiro; valores já gravados que não estão nela entram
                // depois, para a tela não trocar um valor existente ao salvar.
                $fixas = is_array($fieldCfg['options'] ?? null) ? $fieldCfg['options'] : [];
                $cfg['fields'][$field]['options'] = $fixas + $options;
            }
        }
        foreach ((array)($cfg['fields'] ?? []) as $field => $fieldCfg) {
            if (empty($fieldCfg['options_from_view_distinct']) || isset($cfg['fields'][$field]['options'])) continue;
            $safeView = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$cfg['view']);
            $safeField = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$field);
            if ($safeView === '' || $safeField === '') continue;
            $optionSql = 'SELECT DISTINCT `' . $safeField . '` AS option_value FROM `' . $safeView . '` WHERE `' . $safeField . '` IS NOT NULL AND LENGTH(TRIM(`' . $safeField . '`)) > 0';
            $optionParams = [];
            if (!empty($fieldCfg['options_from_view_context'])) {
                foreach ($filters as $filterField => $filterValue) {
                    $safeFilter = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$filterField);
                    if ($safeFilter === '' || is_array($filterValue) || is_object($filterValue) || (string)$filterValue === '__SEM_CONTEXTO__') continue;
                    $paramName = ':option_filter_' . count($optionParams);
                    $optionSql .= ' AND `' . $safeFilter . '` = ' . $paramName;
                    $optionParams[$paramName] = $filterValue;
                }
            }
            $optionSql .= ' ORDER BY `' . $safeField . '`';
            $optionStatement = $target->prepare($optionSql);
            $optionStatement->execute($optionParams);
            $options = [];
            foreach ($optionStatement->fetchAll(PDO::FETCH_ASSOC) as $optionRow) {
                $value = trim((string)($optionRow['option_value'] ?? ''));
                if ($value !== '') $options[$value] = $value;
            }
            uksort($options, static fn(string $a, string $b): int => strnatcasecmp($a, $b));
            $cfg['fields'][$field]['options'] = $options;
        }
        // Rótulo de uma chave estrangeira carregada sob demanda (lazy_foreign): busca na fonte do combo, sem o filtro
        // da lista (responsável inativo continua com o nome). O filtro da procedure pode ser parcial: vale o valor exato.
        $lazyLabelCache = [];
        $lazyLabelFor = static function (array $fieldCfg, string $raw) use (&$lazyLabelCache, $call, $target, $json): string {
            $view = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($fieldCfg['foreign']['view'] ?? ''));
            $valueField = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($fieldCfg['foreign']['value'] ?? ''));
            $labelField = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($fieldCfg['foreign']['label'] ?? ''));
            if ($view === '' || $valueField === '' || $labelField === '' || $raw === '') return '';
            $k = $view . '|' . $valueField . '|' . $raw;
            if (array_key_exists($k, $lazyLabelCache)) return $lazyLabelCache[$k];
            if (count($lazyLabelCache) >= 300) return '';
            $lazyLabelCache[$k] = '';
            try {
                foreach ($call($target, 'CALL sp_crud_select(:tabela,:filtros,:busca,100,0)', ['tabela' => $view, 'filtros' => $json([$valueField => $raw]), 'busca' => '']) as $found) {
                    if ((string)($found[$valueField] ?? '') !== $raw) continue;
                    $lazyLabelCache[$k] = trim((string)($found[$labelField] ?? ''));
                    break;
                }
            } catch (Throwable $lazyError) {}
            return $lazyLabelCache[$k];
        };
        // Filtros legados do tipo select usam os valores distintos da propria
        // view, sempre carregados pela procedure padrao do grid. Chave estrangeira mostra o nome, não o código.
        foreach ((array)($cfg['fields'] ?? []) as $field => $fieldCfg) {
            if (($fieldCfg['filter'] ?? 'text') !== 'select' || isset($fieldCfg['options'])) continue;
            $rowsForOptions = $call($target, 'CALL sp_crud_select(:tabela,:filtros,:busca,10000,0)', [
                'tabela' => (string)$cfg['view'],
                'filtros' => '{}',
                'busca' => '',
            ]);
            $isForeign = !empty($fieldCfg['lazy_foreign']) && is_array($fieldCfg['foreign'] ?? null);
            $displayFrom = (string)($fieldCfg['display_from'] ?? '');
            $descriptionFrom = $isForeign ? (string)($fieldCfg['foreign']['description'] ?? '') : '';
            $values = [];
            foreach ($rowsForOptions as $optionRow) {
                $value = trim((string)($optionRow[$field] ?? ''));
                if ($value === '' || ($isForeign && $value === '0')) continue;
                if (!$isForeign) { $values[$value] = $value; continue; }
                if (isset($values[$value]) && $values[$value] !== $value) continue;
                $label = $displayFrom !== '' ? trim((string)($optionRow[$displayFrom] ?? '')) : '';
                if ($label === '' && $descriptionFrom !== '') $label = trim((string)($optionRow[$descriptionFrom] ?? ''));
                $values[$value] = $label !== '' ? $label : $value;
            }
            if ($isForeign) {
                foreach ($values as $value => $label) if ($label === (string)$value) { $nome = $lazyLabelFor($fieldCfg, (string)$value); $values[$value] = $nome !== '' ? $nome : $value . ' (não cadastrado)'; }
                uasort($values, static fn(string $a, string $b): int => strnatcasecmp($a, $b));
            } else {
                uksort($values, static fn(string $a, string $b): int => strnatcasecmp($a, $b));
            }
            $cfg['fields'][$field]['options'] = $values;
        }
        // Larguras do grid legado; preferencias salvas pelo usuario continuam
        // tendo precedencia sobre estes valores iniciais.
        $legacyWidths = [
            'CODLEGISLACAO' => 0, 'DSCUNIDADE' => 100, 'NIVEL_DE_CONTROLE' => 100,
            'CODIGO' => 100, 'DSCAMBITO' => 190, 'DSCTIPO' => 250, 'NR' => 130,
            'ANO' => 110, 'DSCASSUNTO' => 300, 'ARTIGOS' => 200,
            'MEIO_AMBIENTE' => 100, 'SEGURANCA' => 100, 'QUALIDADE' => 100,
            'RESPOSABILIDADE' => 100, 'CODUNIDADE' => 0,
        ];
        foreach ($legacyWidths as $field => $width) {
            if (isset($cfg['fields'][$field]) && !isset($cfg['fields'][$field]['width'])) $cfg['fields'][$field]['width'] = $width;
        }
        $contextOrFields = array_values(array_filter(array_map('strval', (array)($cfg['context_or_fields'] ?? []))));
        // Correlacionamentos usam chave composta (legislação + unidade): as
        // duas partes devem restringir a consulta simultaneamente. O modo OR
        // continua disponível para os grids que realmente usam contexto OR.
        if (str_starts_with((string)($cfg['transaction'] ?? ''), 'modulolegal_correlacionamento')) $contextOrFields = [];
        if (!$contextOrFields && in_array((string)($cfg['view'] ?? ''), ['vw_laia_requisitos_legais_grid', 'vw_laia_controles_documentos_grid'], true)) $contextOrFields = ['CODASPECTO', 'CODIMPACTO', 'CODATIVIDADE'];
        $contextQuery = static function (string $view, array $activeFilters, string $activeSearch, int $offset, int $limit, bool $countOnly) use ($target, $contextOrFields, $call, $json): array {
            $procedure = $countOnly ? 'CALL sp_crud_context_count(:tabela,:filtros,:contexto,:busca)' : 'CALL sp_crud_context_select(:tabela,:filtros,:contexto,:busca,:limite,:offset)';
            $params = [
                'tabela' => $view,
                'filtros' => $json($activeFilters),
                'contexto' => $json($contextOrFields),
                'busca' => $activeSearch,
            ];
            if (!$countOnly) { $params['limite'] = $limit; $params['offset'] = $offset; }
            return $call($target, $procedure, $params);
        };
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'export_request' && $permission['pode_exportar_excel']) {
            $postedFilters = json_decode((string)($_POST['filtros'] ?? '{}'), true);
            $postedFilters = is_array($postedFilters) ? $postedFilters : $filters;
            if ($userScopeField !== '' && ($forceUserScope || empty($permission['pode_visualizar_total']))) {
                $postedFilters[$userScopeField] = (int)($_SESSION['usuario']['codusuario'] ?? 0);
            }
            $postedSearch = trim((string)($_POST['busca'] ?? $search));
            $postedColumns = json_decode((string)($_POST['colunas'] ?? '[]'), true);
            $postedColumns = is_array($postedColumns) ? array_values(array_unique(array_map('strval', $postedColumns))) : [];
            $gridColumns = [];
            $preferenceRows = $call($tenant, 'CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', ['tabela' => 'vw_preferencias_grid', 'filtros' => $json(['codusuario' => (int)$_SESSION['usuario']['codusuario'], 'transacao' => $cfg['transaction']])]);
            $gridPreferences = [];
            foreach ($preferenceRows as $preference) $gridPreferences[(string)$preference['coluna']] = $preference;
            $gridFieldOrder = (array)($cfg['legacy_order'] ?? []);
            if (!$gridFieldOrder) {
                $gridFieldOrder = array_keys((array)($cfg['fields'] ?? []));
                $originalOrder = array_flip($gridFieldOrder);
                usort($gridFieldOrder, static function (string $a, string $b) use ($gridPreferences, $originalOrder): int {
                    $orderA = isset($gridPreferences[$a]) ? (int)$gridPreferences[$a]['ordem'] : (int)$originalOrder[$a];
                    $orderB = isset($gridPreferences[$b]) ? (int)$gridPreferences[$b]['ordem'] : (int)$originalOrder[$b];
                    return ($orderA <=> $orderB) ?: ($originalOrder[$a] <=> $originalOrder[$b]);
                });
            }
            if ($postedColumns) {
                foreach ($postedColumns as $column) {
                    if (isset($cfg['fields'][$column]) && empty($cfg['fields'][$column]['hidden'])) $gridColumns[$column] = $translate($cfg['fields'][$column]['label'] ?? $column);
                }
            } else foreach ($gridFieldOrder as $column) {
                if (isset($cfg['fields'][$column]) && empty($cfg['fields'][$column]['hidden']) && (!isset($gridPreferences[(string)$column]) || (int)$gridPreferences[(string)$column]['visivel'] !== 0)) $gridColumns[(string)$column] = $translate($cfg['fields'][$column]['label'] ?? $column);
            }
            if (!$postedColumns && !$gridColumns) foreach ((array)($cfg['fields'] ?? []) as $column => $fieldConfig) if (empty($fieldConfig['hidden']) && (!isset($gridPreferences[(string)$column]) || (int)$gridPreferences[(string)$column]['visivel'] !== 0)) $gridColumns[(string)$column] = $translate($fieldConfig['label'] ?? $column);
            // Colunas marcadas com 'export' => true saem na planilha mesmo quando ficam ocultas na tela
            // (por exemplo, Unidade e Razão Social em Gestão de Funcionários). Entram no início, na ordem dos campos.
            $exportOnly = [];
            foreach ((array)($cfg['fields'] ?? []) as $column => $fieldConfig) {
                if (!empty($fieldConfig['export']) && !isset($gridColumns[(string)$column])) $exportOnly[(string)$column] = $translate($fieldConfig['label'] ?? $column);
            }
            if ($exportOnly) $gridColumns = $exportOnly + $gridColumns;
            $orderedColumns = [];
            foreach ($gridColumns as $column => $label) {
                $columnConfig = (array)($cfg['fields'][$column] ?? []);
                // 'export' => false: a coluna aparece na tela, mas não sai na planilha (ex.: código repetido em outra coluna).
                if (array_key_exists('export', $columnConfig) && $columnConfig['export'] === false) continue;
                $item = ['campo' => (string)$column, 'rotulo' => (string)$label];
                $exportSource = (string)($columnConfig['export_from'] ?? '');
                if ($exportSource === '' && is_array($columnConfig['foreign'] ?? null)) {
                    $exportSource = (string)($columnConfig['foreign']['description'] ?? $columnConfig['foreign']['label'] ?? '');
                }
                if (preg_match('/^[A-Za-z0-9_]+$/', $exportSource) && strcasecmp($exportSource, (string)$column) !== 0) $item['origem'] = $exportSource;
                // 'export_from' => 'COLUNA': a planilha mostra o valor de outra coluna da view (ex.: nome no lugar do código).
                if (preg_match('/^[A-Za-z0-9_]+$/', (string)($columnConfig['export_from'] ?? ''))) $item['origem'] = (string)$columnConfig['export_from'];
                // 'export_ged' => 'pasta': quantidade de arquivos do registro no GED (chave = campo primário).
                if (preg_match('/^[a-z0-9_]+$/', (string)($columnConfig['export_ged'] ?? ''))) $item['ged'] = ['pasta' => (string)$columnConfig['export_ged'], 'chave' => (string)$cfg['primary']];
                if (is_array($columnConfig['options'] ?? null) && $columnConfig['options']) {
                    $item['mapa'] = [];
                    foreach ($columnConfig['options'] as $optionValue => $optionLabel) $item['mapa'][(string)$optionValue] = (string)$translate($optionLabel);
                }
                if (($columnConfig['type'] ?? '') === 'date' || ($columnConfig['display_format'] ?? '') === 'date' || !empty($columnConfig['export_date'])) $item['formato_data'] = $language === 'en' ? 'm/d/Y' : 'd/m/Y';
                $orderedColumns[] = $item;
            }
            $jobData = ['codusuario' => (int)$_SESSION['usuario']['codusuario'], 'transacao' => $cfg['transaction'], 'filtros' => $json($postedFilters), 'busca' => $postedSearch, 'colunas' => $json(['colunas' => $orderedColumns, '__config' => ['view' => (string)$cfg['view'], 'database' => $tenantDatabase, 'prefix' => (string)$cfg['prefix'], 'base' => (string)$cfg['transaction'], 'tipo' => (trim((string)$translate($cfg['title'] ?? '')) ?: (string)$cfg['transaction'])]]), 'status' => 'SOLICITADA'];
            $call($tenant, 'CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'exportacoes_grid', 'dados' => $json($jobData)]);
            $jobs = $call($tenant, 'CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', ['tabela' => 'exportacoes_grid', 'filtros' => $json(['codusuario' => (int)$_SESSION['usuario']['codusuario'], 'transacao' => $cfg['transaction'], 'status' => 'SOLICITADA'])]);
            usort($jobs, static fn(array $a, array $b): int => ((int)($b['codexportacaogrid'] ?? 0)) <=> ((int)($a['codexportacaogrid'] ?? 0)));
            $job = $jobs[0] ?? [];
            $jobId = (int)($job['codexportacaogrid'] ?? 0);
            if ($jobId) {
                $requestedAt = (string)($job['solicitado_em'] ?? date('Y-m-d H:i:s'));
                $statusLink = '/principal/export_status.php?codexportacaogrid=' . $jobId . '&transacao=' . rawurlencode((string)$cfg['transaction']);
                $call($tenant, 'CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'alertas', 'dados' => $json(['codusuario' => (int)$_SESSION['usuario']['codusuario'], 'titulo' => 'Exportação iniciada', 'mensagem' => 'A exportação de ' . (trim((string)$translate($cfg['title'] ?? '')) ?: (string)$cfg['transaction']) . ' foi iniciada em ' . date('d/m/Y H:i', strtotime($requestedAt) ?: time()) . '.', 'link_status' => $statusLink, 'lido' => 0])]);
                ExportWorkerLauncher::launch($jobId, (string)$_SESSION['tenant']['banco_dados']);
            }
            header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'message' => IdiomaService::t('comum.msg.exportacao_pedido', ['pt' => 'A exportação foi solicitada e será disponibilizada nos alertas.', 'en' => 'The export was requested and will be available in the alerts.', 'es' => 'La exportación fue solicitada y estará disponible en las alertas.'])], JSON_UNESCAPED_UNICODE); exit;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'count_rows') {
            $countFilters = json_decode((string)($_POST['filtros'] ?? '{}'), true);
            $countFilters = is_array($countFilters) ? $countFilters : [];
            if ($userScopeField !== '' && ($forceUserScope || empty($permission['pode_visualizar_total']))) {
                $countFilters[$userScopeField] = (int)($_SESSION['usuario']['codusuario'] ?? 0);
            }
            $rows = $contextOrFields ? $contextQuery((string)$cfg['view'], $countFilters, trim((string)($_POST['busca'] ?? '')), 0, 1, true) : $call($target, 'CALL sp_crud_count(:tabela,:filtros,:busca)', ['tabela' => $cfg['view'], 'filtros' => $json($countFilters), 'busca' => trim((string)($_POST['busca'] ?? ''))]);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'total' => (int)($rows[0]['total_registros'] ?? 0)]);
            exit;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'save_preferences') {
            $items = json_decode((string)($_POST['preferences'] ?? '[]'), true);
            foreach (is_array($items) ? $items : [] as $item) {
                $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($item['coluna'] ?? ''));
                if (!$column) continue;
                $old = $call($tenant, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'vw_preferencias_grid', 'filtros' => $json(['codusuario' => (int)$_SESSION['usuario']['codusuario'], 'transacao' => $cfg['transaction'], 'coluna' => $column])]);
                $data = ['codusuario' => (int)$_SESSION['usuario']['codusuario'], 'transacao' => $cfg['transaction'], 'coluna' => $column, 'ordem' => (int)($item['ordem'] ?? 0), 'visivel' => (int)!empty($item['visivel']), 'largura' => max(60, (int)($item['largura'] ?? 140))];
                if ($old) $call($tenant, 'CALL sp_crud_update(:tabela,:chave,:dados)', ['tabela' => 'preferencias_grid', 'chave' => $json(['codpreferenciagrid' => (int)$old[0]['codpreferenciagrid']]), 'dados' => $json($data)]);
                else $call($tenant, 'CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'preferencias_grid', 'dados' => $json($data)]);
            }
            header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true]); exit;
        }
        // Falha ao gravar ou excluir: mensagem no idioma do usuário (Administração › Textos e idiomas).
        // AJAX recebe JSON 422; o envio normal volta para a grade com o aviso em vermelho.
        $falhaGravacao = static function (Throwable $erro, string $acao) use ($tenant, $cfg, $page, $filters, $json): never {
            $mensagem = IdiomaService::erro($erro, $acao);
            error_log('ManutencaoLegalCadastroGrid ' . (string)($cfg['transaction'] ?? '') . ': ' . $erro->getMessage());
            AuditService::record($tenant, (string)($cfg['transaction'] ?? $cfg['menu']), $acao === 'excluir' ? 'EXCLUIR' : 'SALVAR', 'ERRO', (string)($cfg['table'] ?? ''), null, ['mensagem' => mb_substr($erro->getMessage(), 0, 300)]);
            if (!empty($_POST['ajax'])) {
                http_response_code(422);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => $mensagem], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $_SESSION['grid_mensagem_erro'][(string)($cfg['transaction'] ?? '')] = $mensagem;
            header('Location: ' . strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') . '?' . http_build_query(['pagina' => $page, 'filtros' => $json($filters), '_atualizado' => (string)microtime(true)]));
            exit;
        };
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'save_rows') {
            if (!empty($cfg['read_only_grid'])) { http_response_code(405); exit(htmlspecialchars(IdiomaService::t('comum.erro.somente_consulta', ['pt' => 'Esta grade é somente para consulta.', 'en' => 'This grid is read-only.', 'es' => 'Esta cuadrícula es solo de consulta.']), ENT_QUOTES, 'UTF-8')); }
            try {
            // 'before_save' (migração 344): a tela ajusta as linhas recebidas antes da validação (ex.: valor 1.234,56 → 1234.56);
            // exceção com '1644 mensagem' aparece para o usuário como as demais validações
            if (is_callable($cfg['before_save'] ?? null)) $_POST['rows'] = ($cfg['before_save'])((array)($_POST['rows'] ?? []));
            // Campos obrigatórios ('required' => true): valida todas as linhas antes de gravar qualquer uma.
            foreach ((array)($_POST['rows'] ?? []) as $key => $row) {
                $isNew = str_starts_with((string)$key, 'novo_');
                // 'legacy_below' (migração 327): registros com chave abaixo deste código vêm do sistema anterior pelo sincronismo
                // (o legado prevalece até a virada) e só podem ser consultados aqui; os criados no greenportal ficam acima.
                // 'legacy_editable' (migração 332): colunas que também podem ser alteradas nos registros do legado (existem só no
                // greenportal e o sincronismo não as altera, ex.: Ativo); as demais colunas desses registros continuam só consulta.
                if (!$isNew && !empty($cfg['legacy_below']) && (int)$key > 0 && (int)$key < (int)$cfg['legacy_below']) {
                    $permitidas = array_flip(array_map('strval', (array)($cfg['legacy_editable'] ?? [])));
                    if (!$permitidas || !array_intersect_key((array)$row, $permitidas)) throw new RuntimeException('1644 ' . self::mensagemLegado());
                    $_POST['rows'][$key] = array_intersect_key((array)$row, $permitidas);
                    continue;
                }
                foreach ($cfg['fields'] as $field => $fieldCfg) {
                    if (empty($fieldCfg['required'])) continue;
                    if (!$isNew && !array_key_exists($field, (array)$row)) continue;
                    if (trim((string)($row[$field] ?? '')) !== '') continue;
                    $rotulo = is_array($fieldCfg['label'] ?? null) ? $translate($fieldCfg['label']) : (string)($fieldCfg['label'] ?? $field);
                    throw new RuntimeException('1644 ' . IdiomaService::f('comum.erro.campo_obrigatorio', ['pt' => 'Informe o campo {campo}.', 'en' => 'Fill in the {campo} field.', 'es' => 'Complete el campo {campo}.'], ['campo' => $rotulo]));
                }
            }
            $riscosPostados = $riskArea !== '' ? self::validarRisco($target, $riskArea, (array)($_POST['rows'] ?? []), $permission) : [];
            foreach ((array)($_POST['rows'] ?? []) as $key => $row) {
                $data = [];
                foreach ($cfg['fields'] as $field => $fieldCfg) {
                    if (!empty($fieldCfg['virtual']) || !empty($fieldCfg['risk'])) continue;
                    if (!empty($fieldCfg['readonly']) && (strpos((string)$key, 'novo_') !== 0 || empty($fieldCfg['new_editable']))) continue;
            if (!$isCorrelationChild && !$isContextCrud && empty($cfg['primary_editable']) && !str_starts_with((string)$key, 'novo_') && $field === $cfg['primary']) continue;
                    if (!array_key_exists($field, $row)) continue;
                    // O campo pode ser um alias da view. Quando informado, envia\n+                    // para o CRUD a coluna f\u00edsica correspondente da tabela.
                    $dataField = (string)($fieldCfg['db_column'] ?? $field);
                    $data[$dataField] = trim((string)($row[$field] ?? ''));
                }
                foreach ($contextKeyFields as $contextField) {
                    if (!isset($cfg['fields'][$contextField])) continue;
                    $contextValue = '';
                    foreach ($filters as $filterField => $filterValue) {
                        if (strcasecmp((string)$filterField, $contextField) === 0) { $contextValue = trim((string)$filterValue); break; }
                    }
                    if ($contextValue !== '') $data[$contextField] = $contextValue;
                }
                foreach ($forcedContextFields as $contextField) {
                    $contextValue = trim((string)($filters[$contextField] ?? ''));
                    if ($contextValue !== '' && $contextValue !== '__SEM_CONTEXTO__') $data[$contextField] = $contextValue;
                }
                $keyData = [(string)$cfg['primary'] => (int)$key];
                foreach ($contextKeyFields as $contextField) {
                    if (!isset($cfg['fields'][$contextField])) continue;
                    foreach ($filters as $filterField => $filterValue) {
                        if (strcasecmp((string)$filterField, $contextField) === 0) { $keyData[$contextField] = trim((string)$filterValue); break; }
                    }
                }
                foreach ($forcedContextFields as $contextField) {
                    $contextValue = trim((string)($filters[$contextField] ?? ''));
                    if ($contextValue !== '' && $contextValue !== '__SEM_CONTEXTO__') $keyData[$contextField] = $contextValue;
                }
                // Toda referência editável precisa existir na fonte declarada.
                // Isso impede que novas condicionantes, planos ou vínculos
                // gravem responsáveis inexistentes no banco do tenant.
                foreach ((array)($cfg['fields'] ?? []) as $foreignField => $foreignConfig) {
                    $foreign = $foreignConfig['foreign'] ?? null;
                    if (!is_array($foreign) || !array_key_exists($foreignField, $data)) continue;
                    $foreignValue = trim((string)$data[$foreignField]);
                    if ($foreignValue === '') continue;
                    $foreignView = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($foreign['view'] ?? ''));
                    $foreignValueField = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($foreign['value'] ?? $foreignField));
                    if ($foreignView === '' || $foreignValueField === '') continue;
                    $referenceRows = $call($target, 'CALL sp_crud_select(:tabela,:filtros,NULL,2,0)', [
                        'tabela' => $foreignView,
                        'filtros' => $json([$foreignValueField => $foreignValue]),
                    ]);
                    if (!$referenceRows) {
                        $label = is_array($foreignConfig['label'] ?? null) ? $translate($foreignConfig['label']) : (string)($foreignConfig['label'] ?? $foreignField);
                        throw new RuntimeException('Referência inválida: ' . $label . ' não foi encontrada no cadastro de origem.');
                    }
                }
                if (str_starts_with((string)$key, 'novo_')) {
                    if (!$permission['pode_incluir']) continue;
                    if (trim((string)($data[$cfg['primary']] ?? '')) === '' && empty($cfg['primary_auto'])) {
                        // Linha nova sem código: antes era ignorada em silêncio ("salvo com sucesso" sem gravar nada).
                        $preenchidos = array_filter($data, static fn($v, $k) => $k !== $cfg['primary'] && trim((string)$v) !== '' && !in_array($k, $forcedContextFields, true), ARRAY_FILTER_USE_BOTH);
                        if (!$preenchidos) continue;
                        $rotuloChave = is_array($cfg['fields'][$cfg['primary']]['label'] ?? null) ? $translate($cfg['fields'][$cfg['primary']]['label']) : (string)$cfg['primary'];
                        throw new RuntimeException('1644 ' . IdiomaService::f('comum.erro.campo_obrigatorio', ['pt' => 'Informe o campo {campo}.', 'en' => 'Fill in the {campo} field.', 'es' => 'Complete el campo {campo}.'], ['campo' => $rotuloChave]));
                    }
                    if (!empty($cfg['primary_auto']) && trim((string)($data[$cfg['primary']] ?? '')) === '') unset($data[$cfg['primary']]);
                    $inserido = $call($target, 'CALL sp_crud_insert(:tabela,:dados)', ['tabela' => $cfg['table'], 'dados' => $json($data)]);
                    if (isset($riscosPostados[$key])) {
                        $novaChave = (int)($inserido[0]['chave_gerada'] ?? 0) ?: (int)($data[$cfg['primary']] ?? 0);
                        if ($novaChave > 0) RequisitoRisco::salvar($target, $riskArea, $novaChave, $riscosPostados[$key][0], $riscosPostados[$key][1], (int)($_SESSION['usuario']['codusuario'] ?? 0));
                    }
                } elseif ($permission['pode_editar']) {
                    if (isset($riscosPostados[$key])) RequisitoRisco::salvar($target, $riskArea, (int)$key, $riscosPostados[$key][0], $riscosPostados[$key][1], (int)($_SESSION['usuario']['codusuario'] ?? 0));
                    $newPrimary = (string)($data[$cfg['primary']] ?? (string)$key);
                    if (($isCorrelationChild || !empty($cfg['primary_editable'])) && (string)$key !== $newPrimary) {
                        $call($target, 'CALL sp_crud_delete(:tabela,:chave)', ['tabela' => $cfg['table'], 'chave' => $json($keyData)]);
                        $call($target, 'CALL sp_crud_insert(:tabela,:dados)', ['tabela' => $cfg['table'], 'dados' => $json($data)]);
                    } else {
                        $updateData = $data;
                        foreach (array_keys($keyData) as $keyField) unset($updateData[$keyField]);
                        if (!$updateData) continue;
                        $call($target, 'CALL sp_crud_update(:tabela,:chave,:dados)', ['tabela' => $cfg['table'], 'chave' => $json($keyData), 'dados' => $json($data)]);
                    }
                }
            }
            // 'after_save' (migração 344): rotina da tela depois de gravar as linhas (ex.: taxas recorrentes pagas criam a próxima)
            if (is_callable($cfg['after_save'] ?? null)) ($cfg['after_save'])($target);
            } catch (Throwable $erroGravacao) { $falhaGravacao($erroGravacao, 'salvar'); }
            $redirectQuery = ['pagina' => $page, 'filtros' => $json($filters), 'salvo' => 1, '_atualizado' => (string)microtime(true)];
            if (!empty($_POST['ajax'])) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'message' => IdiomaService::t('comum.msg.registro_salvo', ['pt' => 'Registro salvo com sucesso.', 'en' => 'Record saved successfully.', 'es' => 'Registro guardado correctamente.'])], JSON_UNESCAPED_UNICODE);
                exit;
            }
            header('Location: ' . strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') . '?' . http_build_query($redirectQuery)); exit;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'delete_row' && $permission['pode_excluir']) {
            if (!empty($cfg['read_only_grid'])) { http_response_code(405); exit(htmlspecialchars(IdiomaService::t('comum.erro.somente_consulta', ['pt' => 'Esta grade é somente para consulta.', 'en' => 'This grid is read-only.', 'es' => 'Esta cuadrícula es solo de consulta.']), ENT_QUOTES, 'UTF-8')); }
            $key = (int)($_POST['key'] ?? 0);
            $deleteKey = [(string)$cfg['primary'] => $key];
            foreach ($contextKeyFields as $contextField) {
                foreach ($filters as $filterField => $filterValue) {
                    if (strcasecmp((string)$filterField, $contextField) === 0) { $deleteKey[$contextField] = trim((string)$filterValue); break; }
                }
            }
            foreach ($forcedContextFields as $contextField) {
                $contextValue = trim((string)($filters[$contextField] ?? ''));
                if ($contextValue !== '' && $contextValue !== '__SEM_CONTEXTO__') $deleteKey[$contextField] = $contextValue;
            }
            if ($key > 0 && !empty($cfg['legacy_below']) && $key < (int)$cfg['legacy_below']) $falhaGravacao(new RuntimeException('1644 ' . self::mensagemLegado()), 'excluir');
            if ($key > 0) { try { $call($target, 'CALL sp_crud_delete(:tabela,:chave)', ['tabela' => $cfg['table'], 'chave' => $json($deleteKey)]); } catch (Throwable $erroGravacao) { $falhaGravacao($erroGravacao, 'excluir'); } }
            $redirectQuery = ['pagina' => $page, 'filtros' => $json($filters), 'excluido' => 1, '_atualizado' => (string)microtime(true)];
            if (!empty($_POST['ajax'])) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'message' => IdiomaService::t('comum.msg.registro_excluido', ['pt' => 'Registro excluído com sucesso.', 'en' => 'Record deleted successfully.', 'es' => 'Registro eliminado correctamente.'])], JSON_UNESCAPED_UNICODE);
                exit;
            }
            header('Location: ' . strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') . '?' . http_build_query($redirectQuery)); exit;
        }

        $rowsStartedAt = microtime(true);
        $rows = $contextOrFields ? $contextQuery((string)$cfg['view'], $filters, $search, ($page - 1) * $size, $size, false) : $call($target, 'CALL sp_crud_select(:tabela,:filtros,:busca,25,:offset)', ['tabela' => $cfg['view'], 'filtros' => $json($filters), 'busca' => $search, 'offset' => ($page - 1) * $size]);
        if (is_callable($cfg['row_enricher'] ?? null)) {
            $rows = (array)($cfg['row_enricher'])($rows, $target);
        }
        if ($riskArea !== '') $rows = self::preencherRisco($rows, $target, $riskArea, (string)$cfg['primary']);
        $rowsElapsedMs = round((microtime(true) - $rowsStartedAt) * 1000, 2);
        foreach ($rows as &$displayRow) {
            foreach ((array)($cfg['fields'] ?? []) as $displayField => $displayConfig) {
                if (($displayConfig['display_format'] ?? '') !== 'date' || !isset($displayRow[$displayField])) continue;
                $rawDate = trim((string)$displayRow[$displayField]);
                $date = DateTime::createFromFormat('!Y-m-d', substr($rawDate, 0, 10));
                $dateErrors = DateTime::getLastErrors();
                $validDate = $date instanceof DateTime && ($dateErrors === false || (($dateErrors['warning_count'] ?? 0) === 0 && ($dateErrors['error_count'] ?? 0) === 0));
                if (!$validDate) {
                    // Valores legados como 0000-00-00 não podem chegar ao
                    // input type=date, pois o navegador os transforma em
                    // datas negativas (por exemplo, 30/11/-0001).
                    $displayRow[$displayField] = '';
                    continue;
                }
                if (($displayConfig['type'] ?? '') === 'date' && empty($displayConfig['readonly'])) {
                    // O controle HTML date exige o formato ISO, sem
                    // conversão para d/m/Y ou m/d/Y.
                    $displayRow[$displayField] = $date->format('Y-m-d');
                } else {
                    $displayRow[$displayField] = $language === 'en' ? $date->format('m/d/Y') : $date->format('d/m/Y');
                }
            }
        }
        unset($displayRow);
        // Grades contextuais somente leitura ainda precisam apresentar a
        // descrição das chaves estrangeiras. O valor bruto continua sendo
        // preservado para o relacionamento; apenas a célula exibida recebe
        // o rótulo resolvido pela fonte configurada.
        foreach ($rows as &$displayRow) {
            foreach ((array)($cfg['fields'] ?? []) as $displayField => $displayConfig) {
                if (empty($displayConfig['readonly']) || empty($displayConfig['foreign']) || !array_key_exists($displayField, $displayRow)) continue;
                $rawValue = (string)$displayRow[$displayField];
                if ($rawValue === '') continue;
                $descriptionField = (string)($displayConfig['foreign']['description'] ?? '');
                $optionRecord = (array)($displayConfig['option_records'][$rawValue] ?? []);
                if ($descriptionField !== '' && array_key_exists($descriptionField, $optionRecord) && trim((string)$optionRecord[$descriptionField]) !== '') {
                    $displayRow[$displayField] = (string)$optionRecord[$descriptionField];
                } elseif (isset($displayConfig['options'][$rawValue]) && trim($translate($displayConfig['options'][$rawValue])) !== '') {
                    $displayRow[$displayField] = $translate($displayConfig['options'][$rawValue]);
                }
            }
        }
        unset($displayRow);
        // Combos carregados sob demanda (lazy_foreign) mostram, antes de abrir a lista, o rótulo do valor gravado.
        // Quando a view do grid não traz a descrição (display_from / description), o rótulo vem de $lazyLabelFor.
        $lazyLabels = [];
        foreach ((array)($cfg['fields'] ?? []) as $lazyField => $lazyConfig) {
            if (empty($lazyConfig['lazy_foreign']) || !is_array($lazyConfig['foreign'] ?? null)) continue;
            $lazyDisplay = (string)($lazyConfig['display_from'] ?? '');
            $lazyDescription = (string)($lazyConfig['foreign']['description'] ?? '');
            foreach ($rows as $lazyRow) {
                $raw = trim((string)($lazyRow[$lazyField] ?? ''));
                if ($raw === '' || $raw === '0' || isset($lazyLabels[$lazyField][$raw])) continue;
                if ($lazyDisplay !== '' && trim((string)($lazyRow[$lazyDisplay] ?? '')) !== '') continue;
                if ($lazyDescription !== '' && trim((string)($lazyRow[$lazyDescription] ?? '')) !== '') continue;
                $nome = is_array($lazyConfig['options'] ?? null) && isset($lazyConfig['options'][$raw]) && $lazyConfig['options'][$raw] !== $raw ? (string)$lazyConfig['options'][$raw] : $lazyLabelFor($lazyConfig, $raw);
                if ($nome !== '') $lazyLabels[$lazyField][$raw] = $nome;
            }
        }
        $prefRows = $call($tenant, 'CALL sp_crud_select(:tabela,:filtros,NULL,100,0)', ['tabela' => 'vw_preferencias_grid', 'filtros' => $json(['codusuario' => (int)$_SESSION['usuario']['codusuario'], 'transacao' => $cfg['transaction']])]);
        $prefs = []; foreach ($prefRows as $pref) $prefs[(string)$pref['coluna']] = $pref;
        if (!empty($cfg['legacy_fixed'])) {
            foreach ((array)($cfg['legacy_order'] ?? []) as $legacyColumn) {
                if (isset($cfg['fields'][$legacyColumn])) $cfg['fields'][$legacyColumn]['force_legacy_visible'] = true;
            }
        }
        // Textos e idiomas (guniversal_dbp): rótulos das colunas cadastrados para esta transação
        // substituem os do código (chave <transacao>.coluna.<CAMPO>). Sem cadastro, vale o código.
        $i18nGrupo = strtolower((string)preg_replace('/[^A-Za-z0-9_]/', '', (string)($cfg['transaction'] ?? '')));
        if ($i18nGrupo !== '') {
            // Colunas ainda não cadastradas entram sozinhas quando um ADMINISTRADOR abre a transação.
            IdiomaService::registrarColunas($i18nGrupo, (array)$cfg['fields'], $cfg['title'] ?? null);
            foreach ((array)$cfg['fields'] as $i18nCampo => $i18nCfg) {
                $i18nChave = $i18nGrupo . '.coluna.' . $i18nCampo;
                $i18nRotulo = is_array($i18nCfg['label'] ?? null) ? (string)($i18nCfg['label']['pt'] ?? '') : (string)($i18nCfg['label'] ?? '');
                if (trim($i18nRotulo) !== '' && IdiomaService::existe($i18nChave)) $cfg['fields'][$i18nCampo]['label'] = IdiomaService::textos($i18nChave, is_array($i18nCfg['label'] ?? null) ? $i18nCfg['label'] : (string)($i18nCfg['label'] ?? $i18nCampo));
            }
        }
        $t = static fn(string $chave, array $padrao): string => IdiomaService::t($chave, $padrao);
        $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $formId = $cfg['transaction'] . 'GridForm';
        $gridId = $cfg['transaction'] . 'BatchGrid';
        $fragmentClass = preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)($cfg['fragment_class'] ?? ''));
        echo '<script>window.greenPortalCrudPermission=' . json_encode(['include' => !empty($permission['pode_incluir']), 'edit' => !empty($permission['pode_editar']), 'delete' => !empty($permission['pode_excluir'])], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';
        ?>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet">
<link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet">
<link href="../assets/css/app.css?v=5" rel="stylesheet"><link href="../assets/css/main.css?v=18" rel="stylesheet"><link href="../assets/css/design-system.css?v=3" rel="stylesheet"><link href="../assets/css/modulo-legal.css?v=1" rel="stylesheet"><link href="../assets/css/homologacao-fornecedores.css?v=1" rel="stylesheet"><link href="../assets/css/nao-conformidades.css?v=1" rel="stylesheet">
        <section class="setup-fragment menu-grid-fragment cadastro-legal-fragment<?= $fragmentClass !== '' ? ' ' . $e($fragmentClass) : '' ?>" data-context-crud="<?= !empty($cfg['context_crud']) ? '1' : '0' ?>" data-preferences="<?= $e($json($prefs)) ?>" data-context-filters="<?= $e($json($filters)) ?>" data-default-sort-column="<?= $e((string)($cfg['default_sort']['column'] ?? '')) ?>" data-default-sort-direction="<?= $e((string)($cfg['default_sort']['direction'] ?? 'asc')) ?>" data-relacionamentos-endpoint="<?= $e((string)($cfg['relacionamentos_endpoint'] ?? '')) ?>" data-grid-timing="<?= $e($json(['rows_ms' => $rowsElapsedMs, 'foreign_options_ms' => $foreignOptionsElapsedMs, 'total_server_ms' => round((microtime(true) - $gridStartedAt) * 1000, 2)])) ?>" data-cadastro-config='<?= $e($json(['legacy_below' => (int)($cfg['legacy_below'] ?? 0), 'legacy_editable' => array_values((array)($cfg['legacy_editable'] ?? [])), 'primary' => $cfg['primary'], 'primary_editable' => !empty($cfg['primary_editable']), 'context_primary_select' => !empty($cfg['context_crud']) && !empty($cfg['association_read_only']), 'transaction' => (string)($cfg['transaction'] ?? ''), 'pdf_transaction' => (string)($cfg['pdf_transaction'] ?? ''), 'legacy_order' => (array)($cfg['legacy_order'] ?? []), 'legacy_fixed' => !empty($cfg['legacy_fixed']), 'fields' => $cfg['fields'], 'unique_context_field' => (string)($cfg['unique_context_field'] ?? '')])) ?>'>
        <?php if (!empty($cfg['questionnaire_context'])):
            $questionnaireContext = strtoupper((string)$cfg['questionnaire_context']);
            $questionnaireFields = implode(',', array_map('strval', (array)($cfg['questionnaire_origin_fields'] ?? [$cfg['primary'] ?? ''])));
            $questionnaireTitles = ['LEGAL' => 'Questionário Legal', 'SGA' => 'Questionário SGA', 'QUALITY' => 'Questionário de Qualidade', 'WORKFLOW' => 'Questionário Workflow'];
            $questionnaireTitle = $questionnaireTitles[$questionnaireContext] ?? 'Questionário';
            $questionnaireButton = '<button class="btn btn-sm btn-outline-success grid-crud-button" type="button" data-context-child data-context-id="questionario-' . htmlspecialchars($questionnaireContext, ENT_QUOTES, 'UTF-8') . '" data-context-url="/principal/questionario_execucao.php?contexto=' . rawurlencode($questionnaireContext) . '" data-context-fields="' . htmlspecialchars($questionnaireFields, ENT_QUOTES, 'UTF-8') . '" data-context-title="' . htmlspecialchars($questionnaireTitle, ENT_QUOTES, 'UTF-8') . '" disabled title="' . htmlspecialchars($questionnaireTitle, ENT_QUOTES, 'UTF-8') . '" aria-label="' . htmlspecialchars($questionnaireTitle, ENT_QUOTES, 'UTF-8') . '"><i class="bi bi-ui-checks-grid"></i></button>';
            $cfg['extras'] = $questionnaireButton . (string)($cfg['extras'] ?? '');
        endif; ?>
        <?php
        // Migração 359 (históricos obrigatórios): botão "Histórico" do registro selecionado nas tabelas com histórico
        $historicoAtivo = false;
        try { $historicoAtivo = HistoricoAlteracoes::monitorada((string)($cfg['table'] ?? '')) && HistoricoAlteracoes::disponivel($target); } catch (Throwable) { $historicoAtivo = false; }
        if ($historicoAtivo) {
            $rotulosHist = [];
            foreach ((array)$cfg['fields'] as $campoHist => $cfgHist) {
                if (!empty($cfgHist['virtual'])) continue;
                $rot = is_array($cfgHist['label'] ?? null) ? (string)$translate($cfgHist['label']) : (string)($cfgHist['label'] ?? $campoHist);
                $rotulosHist[strtolower((string)($cfgHist['db_column'] ?? $campoHist))] = $rot;
            }
            $tituloHist = IdiomaService::t('comum.historico.botao', ['pt' => 'Histórico de alterações do registro selecionado', 'en' => 'Change history of the selected record', 'es' => 'Historial de cambios del registro seleccionado']);
            $cfg['extras'] = (string)($cfg['extras'] ?? '') . '<button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-historico-registro disabled'
                . ' data-tabela="' . $e((string)$cfg['table']) . '" data-menu="' . $e((string)$cfg['menu']) . '" data-titulo="' . $e((string)$translate($cfg['title'] ?? '')) . '"'
                . " data-rotulos='" . htmlspecialchars((string)json_encode($rotulosHist, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . "'"
                . ' title="' . $e($tituloHist) . '" aria-label="' . $e($tituloHist) . '"><i class="bi bi-clock-history"></i></button>';
        }
        ?>
        <?php
        // Migração 361: botão "Responsáveis" do registro selecionado ('resp_area' => área; só com a 361 aplicada na empresa)
        $respAtivo = false;
        if (!empty($cfg['resp_area'])) {
            require_once __DIR__ . '/ResponsaveisRegras.php';
            try { $botaoResp = ResponsaveisRegras::botao($target, (string)$cfg['resp_area'], (string)$translate($cfg['title'] ?? '')); } catch (Throwable) { $botaoResp = ''; }
            if ($botaoResp !== '') { $cfg['extras'] = (string)($cfg['extras'] ?? '') . $botaoResp; $respAtivo = true; }
        }
        ?>
        <div class="crud-toolbar menu-grid-toolbar"><?php greenPortalCrudButtons(['form_id' => $formId, 'language' => $language, 'permission' => $permission, 'permission_title' => $permissionTitle, 'transaction' => (string)($cfg['transaction'] ?? ''), 'extras' => (string)($cfg['extras'] ?? ''), ...((array)($cfg['button_options'] ?? []))]); ?><div class="crud-search"><i class="bi bi-search"></i><input type="search" placeholder="<?= $e($t('comum.busca.grid', ['pt' => 'Buscar no grid', 'en' => 'Search the grid', 'es' => 'Buscar en la cuadrícula'])) ?>" aria-label="<?= $e($t('comum.busca.grid', ['pt' => 'Buscar no grid', 'en' => 'Search the grid', 'es' => 'Buscar en la cuadrícula'])) ?>" value="<?= $e((string)$search) ?>"></div></div>
  <?php $erroGrid = (string)($_SESSION['grid_mensagem_erro'][(string)($cfg['transaction'] ?? '')] ?? ''); unset($_SESSION['grid_mensagem_erro'][(string)($cfg['transaction'] ?? '')]); if ($erroGrid !== ''): ?><div class="alert alert-danger py-2 mb-2" role="alert" data-grid-erro><?= $e($erroGrid) ?></div><?php endif; ?>
  <?php if (isset($_GET['salvo'])): ?><div class="alert alert-success py-2 mb-2"><?= $e($t('comum.msg.registro_salvo', ['pt' => 'Registro salvo com sucesso.', 'en' => 'Record saved successfully.', 'es' => 'Registro guardado correctamente.'])) ?></div><?php endif; ?>
  <form method="post" id="<?= $e($formId) ?>" class="setup-form menu-grid-form"><input type="hidden" name="action" value="save_rows">
    <div class="menu-grid-scroll"><table class="table table-sm menu-batch-grid" id="<?= $e($gridId) ?>"><thead><tr>
      <?php /* Valor atual do filtro de cada coluna (texto ou {value, exact} das listas), para a caixa reaparecer preenchida. */ $filtroAtual = static function (string $campo) use ($filters): string { $v = $filters[$campo] ?? ''; if (is_array($v)) $v = strtolower((string)($v['operator'] ?? '')) === 'not' ? '' : ($v['value'] ?? ''); /* filtro "diferente de" (ex.: Lista geral sem as finalizadas) não cabe na caixa: mostrar o valor faria o filtro local esconder todas as linhas */ return is_scalar($v) ? (string)$v : ''; }; ?>
      <?php foreach ($cfg['fields'] as $field => $fieldCfg): $label = $translate($fieldCfg['label']); $options = $fieldCfg['options'] ?? null; $width = (int)($prefs[$field]['largura'] ?? $fieldCfg['width'] ?? 180); ?><th data-column="<?= $e($field) ?>" style="width:<?= $width ?>px"<?= !empty($fieldCfg['risk']) ? ' data-risk-column="' . $e((string)$fieldCfg['risk']) . '"' : '' ?>><div class="column-heading"><span<?= ($fieldCfg['tooltip'] ?? '') !== '' ? ' title="' . $e($translate($fieldCfg['tooltip'])) . '" class="grid-column-tooltip"' : '' ?>><?= $e($label) ?><?= ($fieldCfg['tooltip'] ?? '') !== '' ? ' <i class="bi bi-info-circle text-muted"></i>' : '' ?></span><?php if (($fieldCfg['filter'] ?? 'text') === 'none'): ?><?php elseif (is_array($options)): ?><select class="column-filter form-select form-select-sm" data-column-filter="<?= $e($field) ?>"><option value=""><?= $e($t('comum.filtro.todos', ['pt' => 'Todos', 'en' => 'All', 'es' => 'Todos'])) ?></option><?php foreach ($options as $optionValue => $optionLabel): ?><option value="<?= $e((string)$optionValue) ?>"<?= $filtroAtual($field) !== '' && $filtroAtual($field) === (string)$optionValue ? ' selected' : '' ?>><?= $e($translate($optionLabel)) ?></option><?php endforeach; ?></select><?php else: ?><input class="column-filter" type="search" data-column-filter="<?= $e($field) ?>" value="<?= $e($filtroAtual($field)) ?>" placeholder="<?= $e($t('comum.busca.coluna', ['pt' => 'Buscar', 'en' => 'Search', 'es' => 'Buscar'])) ?>"><?php endif; ?></div></th><?php endforeach; ?><?php if (!empty($cfg['row_actions'])): ?><th class="grid-row-actions-heading"><span>Relacionamentos</span></th><?php endif; ?>
    </tr></thead><tbody><?php foreach ($rows as $row): $key = (int)$row[$cfg['primary']]; ?>
      <tr data-row data-key="<?= $key ?>"><?php foreach ($cfg['fields'] as $field => $fieldCfg): $options = $fieldCfg['options'] ?? null; $displayField = (string)($fieldCfg['display_from'] ?? $field); $displayValue = $row[$displayField] ?? ($field === $cfg['primary'] ? $key : ''); $fieldType = (string)($fieldCfg['type'] ?? 'text'); $inputType = in_array($fieldType, ['date', 'email', 'number', 'text'], true) ? $fieldType : 'text'; ?><td data-column="<?= $e($field) ?>"><?php if (!empty($fieldCfg['virtual'])): $virtualCountField = (string)($fieldCfg['count_field'] ?? ''); $virtualCount = $virtualCountField !== '' ? (int)($row[$virtualCountField] ?? 0) : null; ?><span class="grid-virtual-action d-inline-flex align-items-center gap-1"><button type="button" class="btn btn-sm btn-outline-primary grid-crud-button" data-control-relacionamento="<?= $e((string)($fieldCfg['action_key'] ?? $field)) ?>" title="<?= $e($translate($fieldCfg['action_label'] ?? $fieldCfg['label'])) ?>" aria-label="<?= $e($translate($fieldCfg['action_label'] ?? $fieldCfg['label'])) ?>"><i class="bi <?= $e((string)($fieldCfg['action_icon'] ?? 'bi-calendar3')) ?>"></i></button><?php if ($virtualCount !== null): ?><span class="badge rounded-pill text-bg-light grid-virtual-count" title="<?= $e($translate($fieldCfg['count_label'] ?? ['pt' => 'Eventos pendentes', 'en' => 'Pending events', 'es' => 'Eventos pendientes'])) ?>"><?= $virtualCount ?></span><?php endif; ?></span><?php elseif (($field === $cfg['primary'] && empty($cfg['primary_editable'])) || !empty($fieldCfg['readonly'])): ?><input class="grid-input" name="rows[<?= $key ?>][<?= $e($field) ?>]" value="<?= $e((string)$displayValue) ?>" data-raw-value="<?= $e((string)($row[$field] ?? '')) ?>" readonly><?php elseif (!empty($fieldCfg['lazy_foreign'])): ?><select class="grid-input" name="rows[<?= $key ?>][<?= $e($field) ?>]" data-lazy-foreign="1" data-lazy-view="<?= $e((string)$fieldCfg['foreign']['view']) ?>" data-lazy-value="<?= $e((string)$fieldCfg['foreign']['value']) ?>" data-lazy-label="<?= $e((string)$fieldCfg['foreign']['label']) ?>" data-lazy-filters="<?= $e($json((array)($fieldCfg['foreign']['filters'] ?? []))) ?>"><?php $lazyRaw = (string)($row[$field] ?? ''); $lazyText = trim((string)($fieldCfg['display_from'] ?? '') !== '' ? (string)($row[$fieldCfg['display_from']] ?? '') : ''); if ($lazyText === '') $lazyText = trim((string)($row[$fieldCfg['foreign']['description'] ?? ''] ?? '')); if ($lazyText === '') $lazyText = (string)($lazyLabels[$field][$lazyRaw] ?? (($lazyRaw === '0' || $lazyRaw === '') ? '' : $lazyRaw . ' (não cadastrado)')); ?><?php if (!empty($fieldCfg['allow_empty']) && $lazyRaw !== ''): ?><option value=""></option><?php endif; ?><option value="<?= $e($lazyRaw) ?>" selected><?= $e($lazyText) ?></option></select><?php elseif (is_array($options)): ?><select class="grid-input" name="rows[<?= $key ?>][<?= $e($field) ?>]"><?php foreach ($options as $optionValue => $optionLabel): ?><option value="<?= $e((string)$optionValue) ?>" <?= (string)($row[$field] ?? '') === (string)$optionValue ? 'selected' : '' ?>><?= $e($translate($optionLabel)) ?></option><?php endforeach; ?></select><?php elseif ($fieldType === 'textarea'): ?><textarea class="grid-input" name="rows[<?= $key ?>][<?= $e($field) ?>]" rows="2"><?= $e((string)$displayValue) ?></textarea><?php else: ?><input class="grid-input" type="<?= $e($inputType) ?>" name="rows[<?= $key ?>][<?= $e($field) ?>]" value="<?= $e((string)$displayValue) ?>"><?php endif; ?></td><?php endforeach; ?><?php if (!empty($cfg['row_actions'])): ?><td class="grid-row-actions" data-grid-row-actions><?php foreach ((array)($cfg['row_actions'] ?? []) as $action): ?><button type="button" class="btn btn-sm btn-outline-secondary grid-crud-button" data-control-relacionamento="<?= $e((string)($action['key'] ?? '')) ?>" title="<?= $e($translate($action['label'] ?? 'Relacionamentos')) ?>" aria-label="<?= $e($translate($action['label'] ?? 'Relacionamentos')) ?>"><i class="bi <?= $e((string)($action['icon'] ?? 'bi-link-45deg')) ?>"></i></button><?php endforeach; ?></td><?php endif; ?></tr>
    <?php endforeach; ?></tbody></table></div>
  </form><nav class="grid-pagination" aria-label="<?= $e($t('comum.paginacao.rotulo', ['pt' => 'Paginação', 'en' => 'Pagination', 'es' => 'Paginación'])) ?>"><button type="button" class="btn btn-sm btn-outline-secondary grid-page-prev" title="<?= $e($t('comum.paginacao.anterior', ['pt' => 'Anterior', 'en' => 'Previous', 'es' => 'Anterior'])) ?>"><i class="bi bi-chevron-left"></i></button><span class="grid-page-status"><?= $e($t('comum.paginacao.calculando', ['pt' => 'Calculando total...', 'en' => 'Counting records...', 'es' => 'Calculando total...'])) ?></span><button type="button" class="btn btn-sm btn-outline-secondary grid-page-next" title="<?= $e($t('comum.paginacao.proxima', ['pt' => 'Próxima', 'en' => 'Next', 'es' => 'Siguiente'])) ?>"><i class="bi bi-chevron-right"></i></button></nav>
</section>
<?php if ((!empty($cfg['primary_editable']) || !empty($cfg['association_read_only'])) && isset($cfg['fields'][$cfg['primary']]['options']) && is_array($cfg['fields'][$cfg['primary']]['options'])): ?><select class="context-crud-primary-template" data-primary="<?= $e((string)$cfg['primary']) ?>" style="display:none" aria-hidden="true"><option value=""><?= $e($t('comum.lista.selecione', ['pt' => 'Selecione...', 'en' => 'Select...', 'es' => 'Seleccione...'])) ?></option><?php foreach ($cfg['fields'][$cfg['primary']]['options'] as $optionValue => $optionLabel): ?><option value="<?= $e((string)$optionValue) ?>"><?= $e($translate($optionLabel)) ?></option><?php endforeach; ?></select><?php endif; ?>
<?= IdiomaService::script(array_values(array_unique(array_filter(['comum', $i18nGrupo]))), [
    // Títulos de popups abertos pelo JavaScript (manutencao-legal-cadastro.js).
    'comum.popup.plano_acao' => ['pt' => 'Plano de ação', 'en' => 'Action plan', 'es' => 'Plan de acción'],
    'comum.popup.condicionantes' => ['pt' => 'Condicionantes', 'en' => 'Conditions', 'es' => 'Condicionantes'],
    'comum.popup.palavras_chave' => ['pt' => 'Palavras-chave', 'en' => 'Keywords', 'es' => 'Palabras clave'],
    'comum.popup.responsabilidades' => ['pt' => 'Responsabilidades', 'en' => 'Responsibilities', 'es' => 'Responsabilidades'],
]) ?>
<script src="../assets/js/context-crud-primary.js?v=2"></script><?php if (!empty($historicoAtivo)): ?><script src="../assets/js/historico-registro.js?v=2"></script><?php endif; ?><?php if (!empty($respAtivo)): ?><script src="../assets/js/responsaveis-registro.js?v=1"></script><?php endif; ?>
<script src="../assets/vendor/jquery/jquery-3.7.1.min.js?v=2"></script><script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script>
 <?php if (empty($cfg['lightweight_assets'])): ?><script src="../assets/js/visualizador-pdf.js?v=4"></script><?php endif; ?><script src="../assets/js/acentuacao.js?v=2"></script><script src="../assets/js/date-native-sanitize.js?v=2"></script><script src="../assets/js/manutencao-legal-cadastro.js?v=61"></script><?php if (empty($cfg['lightweight_assets'])): ?><script src="../assets/js/controle-documentos-relacionamentos.js?v=4"></script><script src="../assets/js/cadastro-legal-fixes.js?v=2"></script><?php endif; ?><script src="../assets/js/grid-sort.js?v=3"></script><script src="../assets/js/grid-pagination.js?v=23"></script><script src="../assets/js/grid-column-filters.js?v=5"></script><script src="../assets/js/grid-loading.js?v=5"></script><?php if ($riskArea !== ''): ?><script src="../assets/js/risco-item.js?v=2"></script><?php endif; ?>
<?php
    }

    /** Colunas do risco do item (migração 341), depois de 'risk_after' (ou no fim); não vão para a tabela do cadastro nem para o filtro. */
    private static function camposRisco(array $cfg, string $area): array
    {
        $dicaSev = RequisitoRisco::dica($area);
        $dicaProb = RequisitoRisco::dica('PROBABILIDADE');
        $classes = RequisitoRisco::CLASSES;
        $novos = [
            'RISCO_SEVERIDADE' => ['label' => ['pt' => 'Severidade', 'en' => 'Severity', 'es' => 'Severidad'], 'options' => ['' => ''] + RequisitoRisco::opcoesSeveridade(),
                'risk' => 'severidade', 'filter' => 'none', 'export' => false, 'width' => 130, 'tooltip' => ['pt' => "Severidade (obrigatória)\n" . $dicaSev, 'en' => "Severity (required)\n" . $dicaSev, 'es' => "Severidad (obligatoria)\n" . $dicaSev]],
            'RISCO_PROBABILIDADE' => ['label' => ['pt' => 'Probabilidade', 'en' => 'Probability', 'es' => 'Probabilidad'], 'options' => ['' => ''] + RequisitoRisco::opcoesProbabilidade(),
                'risk' => 'probabilidade', 'filter' => 'none', 'export' => false, 'width' => 140, 'tooltip' => ['pt' => "Probabilidade (obrigatória)\n" . $dicaProb, 'en' => "Probability (required)\n" . $dicaProb, 'es' => "Probabilidad (obligatoria)\n" . $dicaProb]],
            'RISCO_CLASSE' => ['label' => ['pt' => 'Risco', 'en' => 'Risk', 'es' => 'Riesgo'], 'readonly' => true, 'risk' => 'classe', 'filter' => 'none', 'export' => false, 'width' => 130,
                'tooltip' => ['pt' => "Severidade × Probabilidade\n1–3 Baixo · 4–6 Moderado · 8–9 Alto · 12–16 ou Severidade 4 = Crítico", 'en' => "Severity × Probability\n1–3 Low · 4–6 Moderate · 8–9 High · 12–16 or Severity 4 = Critical", 'es' => "Severidad × Probabilidad\n1–3 Bajo · 4–6 Moderado · 8–9 Alto · 12–16 o Severidad 4 = Crítico"]],
        ];
        $depois = (string)($cfg['risk_after'] ?? '');
        $campos = [];
        foreach ((array)$cfg['fields'] as $k => $v) {
            $campos[$k] = $v;
            if ($depois !== '' && strcasecmp((string)$k, $depois) === 0) $campos += $novos;
        }
        $cfg['fields'] = $campos + $novos;
        if (!empty($cfg['legacy_order'])) foreach (array_keys($novos) as $k) if (!in_array($k, (array)$cfg['legacy_order'], true)) $cfg['legacy_order'][] = $k;
        // Registros do sistema anterior: o risco existe só no greenportal, então também pode ser informado neles
        if (!empty($cfg['legacy_below'])) $cfg['legacy_editable'] = array_values(array_unique(array_merge((array)($cfg['legacy_editable'] ?? []), ['RISCO_SEVERIDADE', 'RISCO_PROBABILIDADE'])));
        $cfg['risk_classes'] = $classes;
        return $cfg;
    }

    private static function preencherRisco(array $rows, PDO $pdo, string $area, string $primary): array
    {
        $riscos = RequisitoRisco::riscos($pdo, $area, array_map(static fn($r) => (int)($r[$primary] ?? 0), $rows));
        $origens = RequisitoRiscoIa::origens($pdo, $area, array_map(static fn($x) => (int)$x['ITEM_ID'], $riscos));
        foreach ($rows as &$r) {
            $x = $riscos[(int)($r[$primary] ?? 0)] ?? null;
            $r['RISCO_SEVERIDADE'] = $x ? (string)$x['SEVERIDADE'] : '';
            $r['RISCO_PROBABILIDADE'] = $x ? (string)$x['PROBABILIDADE'] : '';
            $origem = $x ? (string)($origens[(int)$x['ITEM_ID']] ?? 'MANUAL') : 'MANUAL';
            $r['RISCO_CLASSE'] = $x ? RequisitoRisco::rotuloClasse((string)$x['CLASSE']) . ' (' . (int)$x['PONTOS'] . ')' . ($origem !== 'MANUAL' ? ' · IA' : '') : RequisitoRisco::rotuloClasse('NAO_AVALIADO');
            $r['RISCO_CLASSE_CODIGO'] = $x ? (string)$x['CLASSE'] : '';
        }
        unset($r);
        return $rows;
    }

    /**
     * Severidade e probabilidade das linhas enviadas: obrigatórias na inclusão e na alteração de um item que ainda não tem risco
     * (linha marcada pelo grid como alterada); uma sem a outra não vale. @return array chave => [severidade, probabilidade]
     */
    private static function validarRisco(PDO $pdo, string $area, array $rows, array $permission): array
    {
        $ok = [];
        $existentes = [];
        foreach ($rows as $key => $row) if (!str_starts_with((string)$key, 'novo_') && (int)$key > 0) $existentes[] = (int)$key;
        $atuais = RequisitoRisco::riscos($pdo, $area, $existentes);
        $nome = (string)(RequisitoRisco::AREAS[$area][0] ?? $area);
        foreach ($rows as $key => $row) {
            $isNew = str_starts_with((string)$key, 'novo_');
            if ($isNew && empty($permission['pode_incluir'])) continue;
            if (!$isNew && empty($permission['pode_editar'])) continue;
            $sev = trim((string)($row['RISCO_SEVERIDADE'] ?? ''));
            $prob = trim((string)($row['RISCO_PROBABILIDADE'] ?? ''));
            $linhaVazia = $isNew && !array_filter((array)$row, static fn($v, $k) => !in_array($k, ['RISCO_SEVERIDADE', 'RISCO_PROBABILIDADE', '__alterada'], true) && trim((string)$v) !== '', ARRAY_FILTER_USE_BOTH);
            if ($isNew && $linhaVazia && $sev === '' && $prob === '') continue;
            $exigir = $isNew || $sev !== '' || $prob !== '' || (!empty($row['__alterada']) && !isset($atuais[(int)$key]));
            if (!$exigir) continue;
            if (!preg_match('/^[1-4]$/', $sev) || !preg_match('/^[1-4]$/', $prob)) {
                throw new RuntimeException('1644 ' . IdiomaService::f('legal.risco.obrigatorio', [
                    'pt' => 'Informe a Severidade e a Probabilidade (1 a 4): o risco é obrigatório ao incluir e ao alterar um registro que ainda não tem risco ({item}).',
                    'en' => 'Fill in the Severity and the Probability (1 to 4): the risk is required when adding a record or changing one without risk ({item}).',
                    'es' => 'Complete la Severidad y la Probabilidad (1 a 4): el riesgo es obligatorio al incluir y al modificar un registro sin riesgo ({item}).'], ['item' => $nome]));
            }
            $atual = $atuais[(int)$key] ?? null;
            if ($atual && (int)$atual['SEVERIDADE'] === (int)$sev && (int)$atual['PROBABILIDADE'] === (int)$prob) continue;
            $ok[$key] = [(int)$sev, (int)$prob];
        }
        return $ok;
    }

    private static function env(): array
    {
        $result = [];
        foreach (file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) { $line = trim($line); if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue; [$key, $value] = explode('=', $line, 2); $result[trim($key)] = trim(trim($value), "\"'"); }
        return $result;
    }
    private static function pdoOptions(): array { return [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, GreenPortalDatabase::atributoInit() => GreenPortalDatabase::initCommand()]; }

    /** Mensagem para registro do sistema anterior (cfg 'legacy_below'). */
    private static function mensagemLegado(): string
    {
        return IdiomaService::t('comum.erro.registro_legado', ['pt' => 'Este registro veio do sistema anterior e é atualizado por ele até a virada: altere-o lá. Registros novos podem ser incluídos aqui.', 'en' => 'This record comes from the previous system and is updated by it until the cutover: change it there. New records can be added here.', 'es' => 'Este registro viene del sistema anterior y se actualiza desde él hasta el cambio: modifíquelo allí. Los registros nuevos pueden incluirse aquí.']);
    }
}
