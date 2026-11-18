<?php
declare(strict_types=1);

require_once __DIR__ . '/UsuarioSessao.php';
require_once __DIR__ . '/PermissaoTransacao.php';
require_once __DIR__ . '/TransactionSecurity.php';
require_once __DIR__ . '/IdiomaService.php';
require_once __DIR__ . '/ExportWorkerLauncher.php';
require_once __DIR__ . '/RequisitoRisco.php';

final class ModuloLegalRequisitosGrid
{
    public static function render(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
        if (empty($_SESSION['autenticado'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
        $env = self::env();
        $dsn = 'mysql:host=' . ($env['GPT_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['GPT_DB_PORT'] ?? '3306') . ';dbname=' . $_SESSION['tenant']['banco_dados'] . ';charset=utf8mb4';
        $pdo = TransactionSecurity::connect('REQUISITOS_LEGAIS');
        if (!UsuarioSessao::atualizar($pdo, $_SESSION)) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
        $permission = PermissaoTransacao::obter('REQUISITOS_LEGAIS');
        if (!$permission['pode_visualizar']) { http_response_code(403); exit(htmlspecialchars(IdiomaService::t('comum.erro.sem_permissao', ['pt' => 'Seu perfil não tem permissão para acessar esta transação.', 'en' => 'Your profile is not allowed to access this transaction.', 'es' => 'Su perfil no tiene permiso para acceder a esta transacción.']), ENT_QUOTES, 'UTF-8')); }
        $call = static function (PDO $db, string $sql, array $params = []): array { $s = $db->prepare($sql); $s->execute($params); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; };
        $json = static fn(mixed $v): string => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // Situação legal calculada (migração 284): a view do grid ganha a situação calculada, a divergência e a consolidada.
        $fonte = 'vw_modulolegal_requisitos_situacao_grid';
        try { $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => $fonte, 'filtros' => $json([])]); }
        catch (Throwable $semSituacao) { $fonte = 'vw_modulolegal_requisitos_filtro_grid'; }
        // Requisitos de uma licença (Manutenção legal › Licenças › Requisitos e documentos: licenca_requisitos_documentos.php):
        // a mesma tela, só com os requisitos vinculados à licença (vw_modulolegal_requisitos_licenca_grid, migração 306).
        $codLicenca = max(0, (int)($_GET['codlicenca'] ?? 0)); $licencaNome = '';
        if ($codLicenca > 0) {
            try { $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'vw_modulolegal_requisitos_licenca_grid', 'filtros' => $json([])]); $fonte = 'vw_modulolegal_requisitos_licenca_grid'; }
            catch (Throwable $semLicenca) { http_response_code(500); exit(htmlspecialchars(IdiomaService::t('modulolegal_requisitoslegais.erro.migracao_306', ['pt' => 'Tela indisponível: falta aplicar a migração 306 (requisitos legais por licença).', 'en' => 'Screen unavailable: migration 306 (legal requirements by license) has not been applied.', 'es' => 'Pantalla no disponible: falta aplicar la migración 306 (requisitos legales por licencia).']), ENT_QUOTES, 'UTF-8')); }
            $licencaRow = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1,0)', ['tabela' => 'vw_li_licencas_completo', 'filtros' => $json(['CODLICENCA' => $codLicenca])])[0] ?? null;
            $licencaNome = trim((string)($licencaRow['DSCLICENCA'] ?? '')) ?: ('#' . $codLicenca);
        }
        $colunasSituacao = in_array($fonte, ['vw_modulolegal_requisitos_situacao_grid', 'vw_modulolegal_requisitos_licenca_grid'], true) && ($fonte !== 'vw_modulolegal_requisitos_licenca_grid' || !isset($semSituacao)) ? ['SITUACAO_CALCULADA', 'DIVERGENCIA', 'SITUACAO_CONSOLIDADA', 'MOTIVO_CALCULO'] : [];
        // Migração 341: % de atendimento e risco do requisito (duas dimensões, ao lado da situação calculada)
        if ($colunasSituacao && RequisitoRisco::disponivel($pdo)) array_splice($colunasSituacao, 1, 0, ['PCT_ATENDIMENTO', 'RISCO_REQUISITO', 'PENDENCIAS_CRITICAS', 'ITENS_SEM_RISCO']);
        $filters = json_decode((string)($_GET['filtros'] ?? '{}'), true); $filters = is_array($filters) ? $filters : [];
        if ($codLicenca > 0) $filters['CODLICENCA'] = (string)$codLicenca;
        if (!isset($filters['LEI_REVOGADA']) || (string)$filters['LEI_REVOGADA'] === '') $filters['LEI_REVOGADA'] = 'NAO';
        if (!isset($filters['SEM_EVIDENCIA'])) $filters['SEM_EVIDENCIA'] = '';
        $gridFilters = $filters;
        if ((string)($gridFilters['SEM_EVIDENCIA'] ?? '') === '') unset($gridFilters['SEM_EVIDENCIA']);
        $search = trim((string)($_GET['busca'] ?? '')); $page = max(1, (int)($_GET['pagina'] ?? 1));
        $responsaveisRows = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', ['tabela' => 'vw_modulolegal_responsaveis', 'filtros' => $json([])]);
        usort($responsaveisRows, static function (array $a, array $b): int {
            $label = static function (array $row): string { return trim((string)($row['NOME'] ?? '')) ?: (string)($row['USERID'] ?? ''); };
            $normalizar = static function (string $value): string { $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value); return strtolower($converted !== false ? $converted : $value); };
            return strnatcasecmp($normalizar($label($a)), $normalizar($label($b)));
        });
        $responsavelSelecionado = (string)($filters['RESPONSAVEL_IDS'] ?? '');
        $normalizeLegacyValue = static function (string $value): string {
            return match (trim($value)) {
                'N?o', 'Nao' => 'Não',
                'Sim' => 'Sim',
                default => $value,
            };
        };
        $filterRows = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,10000,0)', ['tabela' => 'vw_modulolegal_requisitos_grid', 'filtros' => $json([])]);
        $filterOptions = [];
        foreach (['UNIDADE', 'PODER', 'ATENDIDO', 'DSCAMBITO', 'MEIO_AMBIENTE', 'SEGURANCA', 'QUALIDADE', 'RESPOSABILIDADE'] as $filterField) {
            $values = [];
            foreach ($filterRows as $filterRow) { $value = $normalizeLegacyValue((string)($filterRow[$filterField] ?? '')); if ($value !== '') $values[$value] = $value; }
            uksort($values, static fn(string $a, string $b): int => strnatcasecmp($a, $b));
            $filterOptions[$filterField] = $values;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'count_rows') {
            $f = json_decode((string)($_POST['filtros'] ?? '{}'), true); $f = is_array($f) ? $f : []; if ($codLicenca > 0) $f['CODLICENCA'] = (string)$codLicenca;
            if ((string)($f['SEM_EVIDENCIA'] ?? '') === '') unset($f['SEM_EVIDENCIA']);
            $rows = $call($pdo, 'CALL sp_crud_count(:tabela,:filtros,:busca)', ['tabela' => $fonte, 'filtros' => $json($f), 'busca' => trim((string)($_POST['busca'] ?? ''))]);
            header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'total' => (int)($rows[0]['total_registros'] ?? 0)]); exit;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'save_preferences') {
            $items = json_decode((string)($_POST['preferences'] ?? '[]'), true); $uid = (int)$_SESSION['usuario']['codusuario'];
            foreach (is_array($items) ? $items : [] as $item) { $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($item['coluna'] ?? '')); if (!$column) continue; $oldRows = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,100,0)', ['tabela' => 'vw_preferencias_grid', 'filtros' => $json(['codusuario' => $uid, 'transacao' => 'modulolegal_requisitoslegais', 'coluna' => $column])]); $old = array_values(array_filter($oldRows, static fn(array $row): bool => (string)($row['transacao'] ?? '') === 'modulolegal_requisitoslegais' && strcasecmp((string)($row['coluna'] ?? ''), $column) === 0)); $data = ['codusuario' => $uid, 'transacao' => 'modulolegal_requisitoslegais', 'coluna' => $column, 'ordem' => (int)($item['ordem'] ?? 0), 'visivel' => (int)!empty($item['visivel']), 'largura' => max(60, (int)($item['largura'] ?? 140))]; if ($old) $call($pdo, 'CALL sp_crud_update(:tabela,:chave,:dados)', ['tabela' => 'preferencias_grid', 'chave' => $json(['codpreferenciagrid' => (int)$old[0]['codpreferenciagrid']]), 'dados' => $json($data)]); else $call($pdo, 'CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'preferencias_grid', 'dados' => $json($data)]); }
            header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true]); exit;
        }
        $exportOrder = array_merge(['CODLEGISLACAO','UNIDADE','PADRAO','PODER','ATENDIDO'], $colunasSituacao, ['DSCAMBITO','CODIGO','DSCTIPO','NR','ANO','DSCASSUNTO','ARTIGOS','MEIO_AMBIENTE','SEGURANCA','QUALIDADE','RESPOSABILIDADE','CODLEGISLACAXEMPRESA','CODUNIDADE','CODTIPO','CODAMBITO','CODEMPRESA']);
        $exportTechnical = ['CODLEGISLACAO','CODLEGISLACAXEMPRESA','CODUNIDADE','CODTIPO','CODAMBITO','CODEMPRESA'];
        $rotulosSituacao = [
            'SITUACAO_CALCULADA' => ['pt' => 'Situação calculada', 'en' => 'Calculated status', 'es' => 'Situación calculada'],
            'DIVERGENCIA' => ['pt' => 'Divergência', 'en' => 'Divergence', 'es' => 'Divergencia'],
            'SITUACAO_CONSOLIDADA' => ['pt' => 'Situação consolidada', 'en' => 'Consolidated status', 'es' => 'Situación consolidada'],
            'MOTIVO_CALCULO' => ['pt' => 'Motivo da situação calculada', 'en' => 'Calculated status reason', 'es' => 'Motivo de la situación calculada'],
            'PCT_ATENDIMENTO' => ['pt' => '% atendimento', 'en' => 'Compliance %', 'es' => '% cumplimiento'],
            'RISCO_REQUISITO' => ['pt' => 'Risco', 'en' => 'Risk', 'es' => 'Riesgo'],
            'PENDENCIAS_CRITICAS' => ['pt' => 'Pendências críticas', 'en' => 'Critical pending', 'es' => 'Pendientes críticos'],
            'ITENS_SEM_RISCO' => ['pt' => 'Itens sem risco avaliado', 'en' => 'Items without risk', 'es' => 'Ítems sin riesgo'],
        ];
        $exportLabelMap = array_intersect_key($rotulosSituacao, array_flip($colunasSituacao)) + ['UNIDADE' => ['pt'=>'Unidade...','en'=>'Unit...','es'=>'Unidad...'], 'PADRAO' => ['pt'=>'Padrão','en'=>'Standard','es'=>'Patrón'], 'PODER' => ['pt'=>'Nível de Controle','en'=>'Control level','es'=>'Nivel de control'], 'ATENDIDO' => ['pt'=>'Atendido?','en'=>'Compliant?','es'=>'¿Atendido?'], 'DSCAMBITO' => ['pt'=>'Âmbito','en'=>'Scope','es'=>'Ámbito'], 'CODIGO' => ['pt'=>'Texto da Norma','en'=>'Standard text','es'=>'Texto de la norma'], 'DSCTIPO' => ['pt'=>'Tipo','en'=>'Type','es'=>'Tipo'], 'NR' => ['pt'=>'Número','en'=>'Number','es'=>'Número'], 'ANO' => ['pt'=>'Ano','en'=>'Year','es'=>'Año'], 'DSCASSUNTO' => ['pt'=>'Assuntos agrupados','en'=>'Grouped subjects','es'=>'Asuntos agrupados'], 'ARTIGOS' => ['pt'=>'Artigos','en'=>'Articles','es'=>'Artículos'], 'MEIO_AMBIENTE' => ['pt'=>'Meio Ambiente','en'=>'Environment','es'=>'Medio ambiente'], 'SEGURANCA' => ['pt'=>'Segurança','en'=>'Safety','es'=>'Seguridad'], 'QUALIDADE' => ['pt'=>'Qualidade','en'=>'Quality','es'=>'Calidad'], 'RESPOSABILIDADE' => ['pt'=>'Social','en'=>'Social','es'=>'Social']];
        $exportLanguage = in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt','en','es'], true) ? (string)$_SESSION['idioma'] : 'pt';
            $exportPrefsRows = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,100,0)', ['tabela' => 'vw_preferencias_grid', 'filtros' => $json(['codusuario' => (int)$_SESSION['usuario']['codusuario'], 'transacao' => 'modulolegal_requisitoslegais'])]);
            $exportPrefs = []; foreach ($exportPrefsRows as $exportPref) if ((string)($exportPref['transacao'] ?? '') === 'modulolegal_requisitoslegais') $exportPrefs[(string)$exportPref['coluna']] = $exportPref;
        $exportOriginalOrder = array_flip($exportOrder);
        usort($exportOrder, static function (string $a, string $b) use ($exportPrefs, $exportOriginalOrder): int {
            $orderA = isset($exportPrefs[$a]) ? (int)$exportPrefs[$a]['ordem'] : (int)$exportOriginalOrder[$a];
            $orderB = isset($exportPrefs[$b]) ? (int)$exportPrefs[$b]['ordem'] : (int)$exportOriginalOrder[$b];
            return ($orderA <=> $orderB) ?: ($exportOriginalOrder[$a] <=> $exportOriginalOrder[$b]);
        });
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'save_control') {
            if (!$permission['pode_editar']) { http_response_code(403); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'message' => IdiomaService::t('modulolegal_requisitoslegais.erro.permissao_nivel', ['pt' => 'Seu perfil não tem permissão para alterar o nível de controle.', 'en' => 'Your profile is not allowed to change the control level.', 'es' => 'Su perfil no tiene permiso para modificar el nivel de control.'])], JSON_UNESCAPED_UNICODE); exit; }
            $validPoderes = ['Conhecimento', 'Influência', 'Controle'];
            $validPoderes = ['Conhecimento', 'Influência', 'Controle'];
            try {
                foreach ((array)($_POST['rows'] ?? []) as $row) {
                    $codLegislacao = (int)($row['CODLEGISLACAO'] ?? 0);
                    $codUnidade = (int)($row['CODUNIDADE'] ?? 0);
                    $poderNovo = trim((string)($row['PODER'] ?? ''));
                    $poderAnterior = trim((string)($row['PODER_ANTERIOR'] ?? ''));
                    if ($codLegislacao <= 0 || $codUnidade < 0 || !in_array($poderNovo, $validPoderes, true) || $poderNovo === $poderAnterior) continue;
                    if ($poderAnterior !== '') {
                        $call($pdo, 'CALL sp_crud_update(:tabela,:chave,:dados)', ['tabela' => 'legislacaoxpoder', 'chave' => $json(['CODLEGISLACAO' => $codLegislacao, 'codunidade' => $codUnidade, 'poder' => $poderAnterior]), 'dados' => $json(['poder' => $poderNovo])]);
                    } else {
                        $call($pdo, 'CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'legislacaoxpoder', 'dados' => $json(['CODLEGISLACAO' => $codLegislacao, 'codunidade' => $codUnidade, 'poder' => $poderNovo])]);
                    }
                }
                header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'message' => IdiomaService::t('modulolegal_requisitoslegais.msg.nivel_alterado', ['pt' => 'Nível de controle alterado com sucesso.', 'en' => 'Control level changed successfully.', 'es' => 'Nivel de control modificado correctamente.'])], JSON_UNESCAPED_UNICODE); exit;
            } catch (Throwable $exception) {
                http_response_code(409); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'message' => IdiomaService::t('modulolegal_requisitoslegais.erro.nivel', ['pt' => 'Não foi possível confirmar a alteração do nível de controle. Verifique se a combinação escolhida já existe.', 'en' => 'The control level change could not be confirmed. Check whether the chosen combination already exists.', 'es' => 'No fue posible confirmar el cambio del nivel de control. Verifique si la combinación elegida ya existe.'])], JSON_UNESCAPED_UNICODE); exit;
            }
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'ia_latest_request') {
            $uid = (int)$_SESSION['usuario']['codusuario'];
            $codLegislacao = (int)($_POST['codlegislacao'] ?? 0);
            if ($codLegislacao <= 0) { http_response_code(422); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'message' => IdiomaService::t('modulolegal_requisitoslegais.erro.requisito', ['pt' => 'Requisito legal não identificado.', 'en' => 'Legal requirement not identified.', 'es' => 'Requisito legal no identificado.'])], JSON_UNESCAPED_UNICODE); exit; }
            try {
                $latest = $call($pdo, "SELECT codsolicitacao,status FROM ml_potenciais_solicitacoes WHERE codusuario = :usuario AND CAST(JSON_UNQUOTE(JSON_EXTRACT(contexto_json, '$.CODLEGISLACAO')) AS UNSIGNED) = :legislacao ORDER BY CASE WHEN status IN ('PENDENTE','PROCESSANDO') THEN 0 WHEN status = 'CONCLUIDA' THEN 1 ELSE 2 END, codsolicitacao DESC LIMIT 1", ['usuario' => $uid, 'legislacao' => $codLegislacao]);
                $latest = $latest[0] ?? [];
                $latestId = (int)($latest['codsolicitacao'] ?? 0);
                header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'exists' => $latestId > 0, 'codsolicitacao' => $latestId, 'status' => (string)($latest['status'] ?? ''), 'url' => $latestId > 0 ? 'ia_potenciais_resultado.php?codsolicitacao=' . $latestId : ''], JSON_UNESCAPED_UNICODE); exit;
            } catch (Throwable $exception) { http_response_code(409); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'message' => IdiomaService::t('modulolegal_requisitoslegais.erro.ultima_analise', ['pt' => 'Não foi possível consultar a última análise.', 'en' => 'The latest analysis could not be retrieved.', 'es' => 'No fue posible consultar el último análisis.'])], JSON_UNESCAPED_UNICODE); exit; }
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'request_ia_analysis') {
            $uid = (int)$_SESSION['usuario']['codusuario'];
            $context = json_decode((string)($_POST['contexto'] ?? '{}'), true);
            $context = is_array($context) ? $context : [];
            $context['CODLEGISLACAO'] = (int)($context['CODLEGISLACAO'] ?? 0);
            $context['CODUNIDADE'] = (int)($context['CODUNIDADE'] ?? 0);
            if ($context['CODLEGISLACAO'] <= 0) { http_response_code(422); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'message' => IdiomaService::t('modulolegal_requisitoslegais.erro.requisito', ['pt' => 'Requisito legal não identificado.', 'en' => 'Legal requirement not identified.', 'es' => 'Requisito legal no identificado.'])], JSON_UNESCAPED_UNICODE); exit; }
            $idioma = in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$_SESSION['idioma'] : 'pt';
            try {
                $rowsRequest = $call($pdo, 'CALL sp_ia_potencial_solicitar_com_alerta(:usuario,:idioma,:contexto,:linkstatus)', ['usuario' => $uid, 'idioma' => $idioma, 'contexto' => $json($context), 'linkstatus' => '/principal/ia_potenciais_resultado.php?codsolicitacao={CODSOLICITACAO}']);
                $requestId = (int)($rowsRequest[0]['codsolicitacao'] ?? 0);
                if ($requestId <= 0) throw new RuntimeException(IdiomaService::t('modulolegal_requisitoslegais.erro.solicitacao', ['pt' => 'Não foi possível registrar a solicitação.', 'en' => 'The request could not be registered.', 'es' => 'No fue posible registrar la solicitud.']));
                header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'codsolicitacao' => $requestId, 'message' => IdiomaService::t('modulolegal_requisitoslegais.msg.analise_solicitada', ['pt' => 'Análise solicitada. Acompanhe o andamento nos alertas.', 'en' => 'Analysis requested. Follow its progress in the alerts.', 'es' => 'Análisis solicitado. Siga el progreso en las alertas.'])], JSON_UNESCAPED_UNICODE); exit;
            } catch (Throwable $exception) { http_response_code(409); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'message' => $exception instanceof PDOException ? IdiomaService::erro($exception) : $exception->getMessage()], JSON_UNESCAPED_UNICODE); exit; }
        }
        // Relatório de Atendimento Legal (slide 27): um pedido por formato na fila de exportações, com os filtros do grid
        // Requisitos inativados (migração 286): por unidade, com efeito imediato e justificativa; ADMINISTRADOR, ADM DA EMPRESA
        // ou quem pode excluir em Requisitos legais
        $perfilCodigo = strtoupper(trim((string)($_SESSION['usuario']['codigo_perfil'] ?? '')));
        $perfilCodigo = preg_replace('/\s+/', ' ', preg_replace('/[_-]+/', ' ', $perfilCodigo) ?? $perfilCodigo) ?? $perfilCodigo;
        $podeInativar = in_array($perfilCodigo, ['ADMINISTRADOR', 'ADM DA EMPRESA', 'ADM EMPRESA'], true) || !empty($permission['pode_excluir']);
        $temInativacao = false;
        try { $call($pdo, 'CALL sp_crud_select(:t, NULL, NULL, 1, 0)', ['t' => 'vw_requisitos_inativados_grid']); $temInativacao = true; } catch (Throwable) {}
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'inativar_requisito') {
            header('Content-Type: application/json; charset=utf-8');
            $leg = (int)($_POST['codlegislacao'] ?? 0); $unid = (int)($_POST['codunidade'] ?? 0); $motivo = trim((string)($_POST['motivo'] ?? ''));
            if (!$podeInativar || !$temInativacao) { echo $json(['ok' => false, 'message' => IdiomaService::t('modulolegal_requisitoslegais.inativar.sem_permissao', ['pt' => 'Seu perfil não pode inativar requisitos.', 'en' => 'Your profile cannot deactivate requirements.', 'es' => 'Su perfil no puede inactivar requisitos.'])]); exit; }
            if ($leg <= 0 || $unid <= 0) { echo $json(['ok' => false, 'message' => IdiomaService::t('modulolegal_requisitoslegais.inativar.selecione', ['pt' => 'Selecione um requisito no grid.', 'en' => 'Select a requirement in the grid.', 'es' => 'Seleccione un requisito en la cuadrícula.'])]); exit; }
            try {
                $st = $pdo->prepare('CALL sp_requisito_inativar(:l, :u, :m, :usr, @resultado_inativar)');
                $st->execute(['l' => $leg, 'u' => $unid, 'm' => $motivo, 'usr' => (int)($_SESSION['usuario']['codusuario'] ?? 0)]);
                do { try { $st->fetchAll(); } catch (Throwable) {} } while ($st->nextRowset()); $st->closeCursor();
                $resultado = (string)($pdo->query('SELECT @resultado_inativar')->fetchColumn() ?: '');
            } catch (Throwable $erro) { echo $json(['ok' => false, 'message' => $erro->getMessage()]); exit; }
            $mensagens = ['OK' => ['pt' => 'Requisito inativado nesta unidade. Ele fica em "Requisitos inativados" e pode ser reativado.', 'en' => 'Requirement deactivated for this unit. It is kept in "Deactivated requirements" and can be reactivated.', 'es' => 'Requisito inactivado en esta unidad. Queda en "Requisitos inactivados" y puede reactivarse.'],
                'MOTIVO' => ['pt' => 'Informe a justificativa (pelo menos 10 caracteres).', 'en' => 'Enter the justification (at least 10 characters).', 'es' => 'Informe la justificación (al menos 10 caracteres).'],
                'NAO_ENCONTRADO' => ['pt' => 'Este requisito já não está ativo nesta unidade.', 'en' => 'This requirement is no longer active for this unit.', 'es' => 'Este requisito ya no está activo en esta unidad.']];
            echo $json(['ok' => $resultado === 'OK', 'message' => IdiomaService::t('modulolegal_requisitoslegais.inativar.' . strtolower($resultado ?: 'erro'), $mensagens[$resultado] ?? ['pt' => 'Não foi possível inativar o requisito.', 'en' => 'The requirement could not be deactivated.', 'es' => 'No fue posible inactivar el requisito.'])]);
            exit;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'relatorio_request') {
            header('Content-Type: application/json; charset=utf-8');
            $formatos = array_keys(array_filter(['xlsx' => !empty($permission['pode_exportar_excel']), 'pdf' => !empty($permission['pode_gerar_pdf'])])); // Excel primeiro: sai em segundos
            if (!$formatos || !$colunasSituacao) { echo json_encode(['ok' => false, 'message' => !$formatos ? IdiomaService::t('modulolegal_requisitoslegais.erro.sem_pdf_excel', ['pt' => 'Perfil sem permissão para gerar PDF ou exportar Excel.', 'en' => 'Profile not allowed to generate PDF or export Excel.', 'es' => 'Perfil sin permiso para generar PDF o exportar Excel.']) : IdiomaService::t('modulolegal_requisitoslegais.erro.migracao_284', ['pt' => 'A situação legal calculada (migração 284) não está instalada nesta empresa.', 'en' => 'The calculated legal status (migration 284) is not installed in this company.', 'es' => 'La situación legal calculada (migración 284) no está instalada en esta empresa.'])], JSON_UNESCAPED_UNICODE); exit; }
            $f = json_decode((string)($_POST['filtros'] ?? '{}'), true); $f = is_array($f) ? $f : $filters; if ((string)($f['SEM_EVIDENCIA'] ?? '') === '') unset($f['SEM_EVIDENCIA']); if ($codLicenca > 0) $f['CODLICENCA'] = (string)$codLicenca;
            $b = trim((string)($_POST['busca'] ?? $search)); $uid = (int)$_SESSION['usuario']['codusuario'];
            $empresa = trim((string)($_SESSION['tenant']['empresa_nome'] ?? $_SESSION['tenant']['nome'] ?? '')) ?: (string)($_SESSION['tenant']['banco_dados'] ?? '');
            $ids = [];
            foreach ($formatos as $formato) {
                $r = $call($pdo, 'CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'exportacoes_grid', 'dados' => $json(['codusuario' => $uid, 'transacao' => 'modulolegal_relatorio_atendimento', 'filtros' => $json($f), 'busca' => $b,
                    'colunas' => $json(['__relatorio' => ['formato' => $formato, 'empresa' => $empresa, 'fonte' => $fonte]]), 'status' => 'SOLICITADA'])]);
                $jobId = (int)($r[0]['chave_gerada'] ?? 0);
                if ($jobId <= 0) continue;
                $ids[] = $jobId;
                ExportWorkerLauncher::launch($jobId, (string)$_SESSION['tenant']['banco_dados']);
            }
            if ($ids) try { $call($pdo, "CALL sp_alerta_criar(:u,:t,:m,NULL,'NAO')", ['u' => $uid, 't' => IdiomaService::t('modulolegal_requisitoslegais.alerta.relatorio_titulo', ['pt' => 'Relatório de Atendimento Legal solicitado', 'en' => 'Legal Compliance Report requested', 'es' => 'Informe de Cumplimiento Legal solicitado']), 'm' => str_replace('{formatos}', strtoupper(implode(' + ', $formatos)), IdiomaService::t('modulolegal_requisitoslegais.alerta.relatorio_msg', ['pt' => 'O relatório ({formatos}) está sendo gerado; os arquivos chegam nos alertas.', 'en' => 'The report ({formatos}) is being generated; the files arrive in the alerts.', 'es' => 'El informe ({formatos}) se está generando; los archivos llegan en las alertas.']))]); } catch (Throwable $semAlerta) {}
            echo json_encode(['ok' => (bool)$ids, 'message' => $ids ? str_replace('{formatos}', strtoupper(implode(' + ', $formatos)), IdiomaService::t('modulolegal_requisitoslegais.msg.relatorio_solicitado', ['pt' => 'Relatório solicitado ({formatos}). Os arquivos chegam nos alertas em alguns minutos.', 'en' => 'Report requested ({formatos}). The files arrive in the alerts in a few minutes.', 'es' => 'Informe solicitado ({formatos}). Los archivos llegan en las alertas en algunos minutos.'])) : IdiomaService::t('modulolegal_requisitoslegais.erro.relatorio', ['pt' => 'Não foi possível solicitar o relatório.', 'en' => 'The report could not be requested.', 'es' => 'No fue posible solicitar el informe.'])], JSON_UNESCAPED_UNICODE); exit;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'export_request' && $permission['pode_exportar_excel']) {
            $f = json_decode((string)($_POST['filtros'] ?? '{}'), true); $f = is_array($f) ? $f : $filters; if ((string)($f['SEM_EVIDENCIA'] ?? '') === '') unset($f['SEM_EVIDENCIA']); if ($codLicenca > 0) $f['CODLICENCA'] = (string)$codLicenca; $b = trim((string)($_POST['busca'] ?? $search)); $uid = (int)$_SESSION['usuario']['codusuario'];
            $exportColumns = [];
            foreach ($exportOrder as $exportColumn) {
                if (in_array($exportColumn, $exportTechnical, true)) continue;
                if (isset($exportPrefs[$exportColumn]) && (int)$exportPrefs[$exportColumn]['visivel'] === 0) continue;
                $exportColumns[$exportColumn] = (string)($exportLabelMap[$exportColumn][$exportLanguage] ?? $exportColumn);
            }
            $orderedExportColumns = []; foreach ($exportColumns as $column => $label) $orderedExportColumns[] = ['campo' => (string)$column, 'rotulo' => (string)$label];
            $call($pdo, 'CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'exportacoes_grid', 'dados' => $json(['codusuario' => $uid, 'transacao' => 'modulolegal_requisitoslegais', 'filtros' => $json($f), 'busca' => $b, 'colunas' => $json(['colunas' => $orderedExportColumns, '__config' => ['view' => $fonte, 'database' => 'gmodelo_dbp', 'prefix' => 'GPT', 'base' => 'modulolegal_requisitoslegais', 'tipo' => 'Requisitos legais']]), 'status' => 'SOLICITADA'])]);
            $jobs = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,1000,0)', ['tabela' => 'exportacoes_grid', 'filtros' => $json(['codusuario' => $uid, 'transacao' => 'modulolegal_requisitoslegais', 'status' => 'SOLICITADA'])]); usort($jobs, static fn(array $a, array $b): int => ((int)($b['codexportacaogrid'] ?? 0)) <=> ((int)($a['codexportacaogrid'] ?? 0))); $job = $jobs[0] ?? []; $jobId = (int)($job['codexportacaogrid'] ?? 0);
            if ($jobId) {
                $requestedAt = (string)($job['solicitado_em'] ?? date('Y-m-d H:i:s'));
                $statusLink = '/principal/export_status.php?codexportacaogrid=' . $jobId . '&transacao=modulolegal_requisitoslegais';
                $call($pdo, 'CALL sp_crud_insert(:tabela,:dados)', ['tabela' => 'alertas', 'dados' => $json(['codusuario' => $uid, 'titulo' => IdiomaService::t('modulolegal_requisitoslegais.alerta.exportacao_titulo', ['pt' => 'Exportação iniciada', 'en' => 'Export started', 'es' => 'Exportación iniciada']), 'mensagem' => str_replace('{data}', $requestedAt, IdiomaService::t('modulolegal_requisitoslegais.alerta.exportacao_msg', ['pt' => 'A exportação dos requisitos legais foi iniciada em {data}.', 'en' => 'The legal requirements export started on {data}.', 'es' => 'La exportación de los requisitos legales se inició el {data}.'])), 'link_status' => $statusLink, 'lido' => 0])]);
                ExportWorkerLauncher::launch($jobId, (string)$_SESSION['tenant']['banco_dados']);
            }
            header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'message' => IdiomaService::t('comum.msg.exportacao_pedido', ['pt' => 'A exportação foi solicitada e será disponibilizada nos alertas.', 'en' => 'The export was requested and will be available in the alerts.', 'es' => 'La exportación fue solicitada y estará disponible en las alertas.'])], JSON_UNESCAPED_UNICODE); exit;
        }
        $columns = [
            'PADRAO' => ['label' => ['pt' => html_entity_decode('Padr&atilde;o', ENT_QUOTES, 'UTF-8'), 'en' => 'Pattern', 'es' => 'Patrón']],
            'CODLEGISLACAO' => ['label' => ['pt' => 'Código da legislação', 'en' => 'Legislation code', 'es' => 'Código de legislación']],
            'CODIGO' => ['label' => ['pt' => 'Texto da Norma', 'en' => 'Standard text', 'es' => 'Texto de la norma']], 'UNIDADE' => ['label' => ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']],
            'PODER' => ['label' => ['pt' => 'Nível de controle', 'en' => 'Control level', 'es' => 'Nivel de control'], 'options' => ['' => '', 'Controle' => 'Controle', 'Conhecimento' => 'Conhecimento', 'Influência' => 'Influência']],
            'ATENDIDO' => ['label' => ['pt' => 'Atendido?', 'en' => 'Compliant?', 'es' => '¿Atendido?'], 'options' => ['' => '', 'Sim' => 'Sim', 'Não' => 'Não', 'Parcialmente Atendido' => 'Parcialmente Atendido', 'Atendimento Indefinido!' => 'Atendimento Indefinido!']],
            'DSCAMBITO' => ['label' => ['pt' => 'Âmbito', 'en' => 'Scope', 'es' => 'Ámbito']], 'DSCTIPO' => ['label' => ['pt' => 'Tipo', 'en' => 'Type', 'es' => 'Tipo']], 'NR' => ['label' => ['pt' => 'Número', 'en' => 'Number', 'es' => 'Número']], 'ANO' => ['label' => ['pt' => 'Ano', 'en' => 'Year', 'es' => 'Año']],
            'DSCASSUNTO' => ['label' => ['pt' => 'Assuntos agrupados', 'en' => 'Grouped subjects', 'es' => 'Asuntos agrupados']], 'ARTIGOS' => ['label' => ['pt' => 'Artigos', 'en' => 'Articles', 'es' => 'Artículos']], 'MEIO_AMBIENTE' => ['label' => ['pt' => 'Meio ambiente', 'en' => 'Environment', 'es' => 'Medio ambiente']], 'SEGURANCA' => ['label' => ['pt' => 'Segurança', 'en' => 'Safety', 'es' => 'Seguridad']], 'QUALIDADE' => ['label' => ['pt' => 'Qualidade', 'en' => 'Quality', 'es' => 'Calidad']], 'RESPOSABILIDADE' => ['label' => ['pt' => 'Responsabilidade', 'en' => 'Responsibility', 'es' => 'Responsabilidad']],
            'CODLEGISLACAXEMPRESA' => ['label' => ['pt' => 'Código da relação', 'en' => 'Relation code', 'es' => 'Código de relación']], 'CODUNIDADE' => ['label' => ['pt' => 'Código da unidade', 'en' => 'Unit code', 'es' => 'Código de unidad']], 'CODTIPO' => ['label' => ['pt' => 'Código do tipo', 'en' => 'Type code', 'es' => 'Código de tipo']], 'CODAMBITO' => ['label' => ['pt' => 'Código do Âmbito', 'en' => 'Scope code', 'es' => 'Código de ámbito']], 'CODEMPRESA' => ['label' => ['pt' => 'Código da empresa', 'en' => 'Company code', 'es' => 'Código de empresa']],
        ];
        $opcoesSituacao = ['Atendido' => ['pt' => 'Atendido', 'en' => 'Compliant', 'es' => 'Atendido'], 'Parcialmente atendido' => ['pt' => 'Parcialmente atendido', 'en' => 'Partially compliant', 'es' => 'Parcialmente atendido'], 'Não atendido' => ['pt' => 'Não atendido', 'en' => 'Not compliant', 'es' => 'No atendido']];
        $columns += array_intersect_key([
            'SITUACAO_CALCULADA' => ['label' => $rotulosSituacao['SITUACAO_CALCULADA'], 'options' => $opcoesSituacao + ['Sem evidências vinculadas' => ['pt' => 'Sem evidências vinculadas', 'en' => 'No linked evidence', 'es' => 'Sin evidencias vinculadas']]],
            'DIVERGENCIA' => ['label' => $rotulosSituacao['DIVERGENCIA'], 'options' => ['Sim' => ['pt' => 'Sim', 'en' => 'Yes', 'es' => 'Sí'], 'Não' => ['pt' => 'Não', 'en' => 'No', 'es' => 'No']]],
            'SITUACAO_CONSOLIDADA' => ['label' => $rotulosSituacao['SITUACAO_CONSOLIDADA'], 'options' => $opcoesSituacao + ['Indefinido' => ['pt' => 'Indefinido', 'en' => 'Undefined', 'es' => 'Indefinido']]],
            'MOTIVO_CALCULO' => ['label' => $rotulosSituacao['MOTIVO_CALCULO']],
            'PCT_ATENDIMENTO' => ['label' => $rotulosSituacao['PCT_ATENDIMENTO']],
            'RISCO_REQUISITO' => ['label' => $rotulosSituacao['RISCO_REQUISITO'], 'options' => ['Crítico' => ['pt' => 'Crítico', 'en' => 'Critical', 'es' => 'Crítico'], 'Alto' => ['pt' => 'Alto', 'en' => 'High', 'es' => 'Alto'], 'Moderado' => ['pt' => 'Moderado', 'en' => 'Moderate', 'es' => 'Moderado'], 'Baixo' => ['pt' => 'Baixo', 'en' => 'Low', 'es' => 'Bajo'], 'Não avaliado' => ['pt' => 'Não avaliado', 'en' => 'Not assessed', 'es' => 'No evaluado']]],
            'PENDENCIAS_CRITICAS' => ['label' => $rotulosSituacao['PENDENCIAS_CRITICAS']],
            'ITENS_SEM_RISCO' => ['label' => $rotulosSituacao['ITENS_SEM_RISCO']],
        ], array_flip($colunasSituacao));
        $ordemLegado = array_merge(['UNIDADE','CODLEGISLACAO','PADRAO','PODER','ATENDIDO'], $colunasSituacao, ['DSCAMBITO','CODIGO','DSCTIPO','NR','ANO','DSCASSUNTO','ARTIGOS','MEIO_AMBIENTE','SEGURANCA','QUALIDADE','RESPOSABILIDADE','CODLEGISLACAXEMPRESA','CODUNIDADE','CODTIPO','CODAMBITO','CODEMPRESA']);
        $columns = array_intersect_key($columns, array_flip($ordemLegado));
        $columns = array_replace(array_fill_keys($ordemLegado, []), $columns);
        $legacyLabels = [
            'CODLEGISLACAO' => ['pt' => 'Código da legislação', 'en' => 'Legislation code', 'es' => 'Código de legislación'],
            'UNIDADE' => ['pt' => 'Unidade...', 'en' => 'Unit...', 'es' => 'Unidad...'],
            'PADRAO' => ['pt' => 'Padrão', 'en' => 'Standard', 'es' => 'Patrón'],
            'PODER' => ['pt' => 'Nível de Controle', 'en' => 'Control level', 'es' => 'Nivel de control'],
            'ATENDIDO' => ['pt' => 'Atendido?', 'en' => 'Compliant?', 'es' => '¿Atendido?'],
            'CODIGO' => ['pt' => 'Texto da Norma', 'en' => 'Standard text', 'es' => 'Texto de la norma'],
            'DSCAMBITO' => ['pt' => 'Âmbito', 'en' => 'Scope', 'es' => 'Ámbito'],
            'DSCTIPO' => ['pt' => 'Tipo', 'en' => 'Type', 'es' => 'Tipo'],
            'NR' => ['pt' => 'Número', 'en' => 'Number', 'es' => 'Número'], 'ANO' => ['pt' => 'Ano', 'en' => 'Year', 'es' => 'Año'],
            'DSCASSUNTO' => ['pt' => 'Assuntos agrupados', 'en' => 'Grouped subjects', 'es' => 'Asuntos agrupados'],
            'ARTIGOS' => ['pt' => 'Artigos', 'en' => 'Articles', 'es' => 'Artículos'],
            'MEIO_AMBIENTE' => ['pt' => 'Meio Ambiente', 'en' => 'Environment', 'es' => 'Medio ambiente'],
            'SEGURANCA' => ['pt' => 'Segurança', 'en' => 'Safety', 'es' => 'Seguridad'],
            'QUALIDADE' => ['pt' => 'Qualidade', 'en' => 'Quality', 'es' => 'Calidad'],
            'RESPOSABILIDADE' => ['pt' => 'Social', 'en' => 'Social', 'es' => 'Social'],
            'CODLEGISLACAXEMPRESA' => ['pt' => 'Código da relação', 'en' => 'Relation code', 'es' => 'Código de relación'],
            'CODUNIDADE' => ['pt' => 'Código da unidade', 'en' => 'Unit code', 'es' => 'Código de unidad'],
        ];
        foreach ($legacyLabels as $field => $label) if (isset($columns[$field])) $columns[$field]['label'] = $label;
        // Textos e idiomas: colunas novas entram sozinhas (ADMINISTRADOR) e os rótulos cadastrados valem sobre os do código.
        IdiomaService::nomeGrupo('modulolegal_requisitoslegais', ['pt' => 'Requisitos legais', 'en' => 'Legal requirements', 'es' => 'Requisitos legales']);
        IdiomaService::registrarColunas('modulolegal_requisitoslegais', $columns);
        foreach ($columns as $field => $fieldCfg) {
            $chaveColuna = 'modulolegal_requisitoslegais.coluna.' . $field;
            if (IdiomaService::existe($chaveColuna)) $columns[$field]['label'] = IdiomaService::textos($chaveColuna, $fieldCfg['label'] ?? (string)$field);
        }
        foreach ($filterOptions as $field => $options) if (isset($columns[$field])) $columns[$field]['options'] = $options;
        $legacyWidths = ['CODLEGISLACAO' => 0, 'UNIDADE' => 120, 'PADRAO' => 300, 'PODER' => 120, 'ATENDIDO' => 150, 'SITUACAO_CALCULADA' => 170, 'DIVERGENCIA' => 110, 'SITUACAO_CONSOLIDADA' => 170, 'MOTIVO_CALCULO' => 360, 'PCT_ATENDIMENTO' => 110, 'RISCO_REQUISITO' => 120, 'PENDENCIAS_CRITICAS' => 120, 'ITENS_SEM_RISCO' => 120, 'CODIGO' => 100, 'DSCAMBITO' => 190, 'DSCTIPO' => 250, 'NR' => 130, 'ANO' => 110, 'DSCASSUNTO' => 300, 'ARTIGOS' => 200, 'MEIO_AMBIENTE' => 100, 'SEGURANCA' => 100, 'QUALIDADE' => 100, 'RESPOSABILIDADE' => 100, 'CODLEGISLACAXEMPRESA' => 0, 'CODUNIDADE' => 0, 'CODTIPO' => 0, 'CODAMBITO' => 0, 'CODEMPRESA' => 0];
        $technicalColumns = ['CODLEGISLACAO', 'CODLEGISLACAXEMPRESA', 'CODUNIDADE', 'CODTIPO', 'CODAMBITO', 'CODEMPRESA'];
        foreach ($legacyWidths as $field => $width) if (isset($columns[$field])) { $columns[$field]['width'] = $width; $prefs[$field]['largura'] = $width; }
        foreach ($technicalColumns as $field) if (isset($columns[$field])) { $columns[$field]['hidden'] = true; $prefs[$field]['visivel'] = 0; }
        foreach ($columns as $field => &$fieldCfg) { $fieldCfg['filter'] = in_array($field, $technicalColumns, true) ? 'none' : (isset($fieldCfg['options']) ? 'select' : 'text'); }
        unset($fieldCfg);
        foreach ($ordemLegado as $index => $field) { $prefs[$field]['ordem'] = $index; }
        $rows = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,:busca,25,:offset)', ['tabela' => $fonte, 'filtros' => $json($gridFilters), 'busca' => $search, 'offset' => ($page - 1) * 25]);
        foreach ($rows as &$row) {
            if (trim((string)($row['UNIDADE'] ?? '')) === '' && trim((string)($row['DSCUNIDADE'] ?? '')) !== '') $row['UNIDADE'] = $row['DSCUNIDADE'];
            foreach (['ATENDIDO', 'MEIO_AMBIENTE', 'SEGURANCA', 'QUALIDADE', 'RESPOSABILIDADE'] as $field) if (array_key_exists($field, $row)) $row[$field] = $normalizeLegacyValue((string)$row[$field]);
        }
        unset($row);
        $prefsRows = $call($pdo, 'CALL sp_crud_select(:tabela,:filtros,NULL,100,0)', ['tabela' => 'vw_preferencias_grid', 'filtros' => $json(['codusuario' => (int)$_SESSION['usuario']['codusuario'], 'transacao' => 'modulolegal_requisitoslegais'])]); $prefs = []; foreach ($prefsRows as $pref) if ((string)($pref['transacao'] ?? '') === 'modulolegal_requisitoslegais') $prefs[(string)$pref['coluna']] = $pref;
        $language = in_array((string)($_SESSION['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$_SESSION['idioma'] : 'pt'; $tr = static fn(mixed $v): string => is_array($v) ? (string)($v[$language] ?? $v['pt'] ?? '') : (string)$v; $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); $permissionTitle = ['pt' => 'Perfil sem permissão de acesso!', 'en' => 'Profile does not have access permission!', 'es' => 'El perfil no tiene permiso de acceso!'][$language];
        // Filtros vindos da URL (links dos dashboards): os que têm caixa no grid aparecem nela já preenchidos;
        // os demais ficam como filtro fixo (data-context-filters), mostrados em etiquetas que o usuário pode remover.
        $controlados = ['LEI_REVOGADA', 'SEM_EVIDENCIA', 'RESPONSAVEL_IDS'];
        foreach ($columns as $field => $fieldCfg) if (($fieldCfg['filter'] ?? 'none') !== 'none') $controlados[] = (string)$field;
        $filtrosFixos = [];
        foreach ($filters as $chave => $valor) {
            if (!is_string($chave) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $chave) || in_array($chave, $controlados, true)) continue;
            if (is_array($valor)) $valor = $valor['value'] ?? '';
            if (!is_scalar($valor) || trim((string)$valor) === '') continue;
            $filtrosFixos[$chave] = (string)$valor;
        }
        $valorFiltro = static function (string $campo) use ($filters): string { $v = $filters[$campo] ?? ''; if (is_array($v)) $v = $v['value'] ?? ''; return is_scalar($v) ? (string)$v : ''; };
        $rotuloFixo = static function (string $campo, string $valor) use ($columns, $filterRows, $tr, $licencaNome): array {
            if (strcasecmp($campo, 'CODLICENCA') === 0) return [$tr(['pt' => 'Licença', 'en' => 'License', 'es' => 'Licencia']), $licencaNome !== '' ? $licencaNome : $valor];
            if (strcasecmp($campo, 'CODUNIDADE') === 0) {
                foreach ($filterRows as $fr) if ((string)($fr['CODUNIDADE'] ?? '') === $valor && trim((string)($fr['UNIDADE'] ?? '')) !== '') return [$tr(['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']), (string)$fr['UNIDADE']];
                return [$tr(['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad']), $valor];
            }
            $rot = isset($columns[$campo]['label']) ? rtrim($tr($columns[$campo]['label']), '.?') : $campo;
            return [$rot, $valor];
        };
        $T = static fn(string $chave, array $padrao): string => IdiomaService::t($chave, $padrao);
        // Rótulos de grupo saem com acentos em entidades numéricas, imunes à correção automática de acentuação.
        $TE = static fn(string $chave, array $padrao): string => mb_encode_numericentity(htmlspecialchars(IdiomaService::t($chave, $padrao), ENT_QUOTES, 'UTF-8'), [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
        ?>
<link href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2" rel="stylesheet"><link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2" rel="stylesheet"><link href="../assets/css/app.css?v=2" rel="stylesheet"><link href="../assets/css/main.css?v=16" rel="stylesheet"><link href="../assets/css/design-system.css?v=3" rel="stylesheet"><link href="../assets/css/modulo-legal.css?v=1" rel="stylesheet"><link href="../assets/css/botoes-crud.css?v=4" rel="stylesheet">
<link href="../assets/css/requisitos-toolbar.css?v=2" rel="stylesheet"><style>td.requisitos-risco{font-weight:600}td.requisitos-risco-critico{background:#f8d7da!important;color:#842029}td.requisitos-risco-alto{background:#ffe5d0!important;color:#8a3c00}td.requisitos-risco-moderado{background:#fff3cd!important;color:#664d03}td.requisitos-risco-baixo{background:#d1e7dd!important;color:#0f5132}td.requisitos-risco-nao-avaliado{color:#6c757d;font-style:italic}td.requisitos-pendencia-critica{background:#dc3545!important;color:#fff!important;font-weight:700;text-align:center}.requisitos-filtro-fixo{display:inline-flex;align-items:center;gap:6px;margin:0 6px 4px 0;padding:3px 6px 3px 10px;border:1px solid var(--gp-border,#d5e2da);border-radius:14px;background:var(--gp-primary-soft,#eaf4ee);font-size:11.5px;line-height:1.3}.requisitos-filtro-fixo .btn-close{width:8px;height:8px;padding:3px;background-size:8px}#requisitosLegaisBatchGrid td.requisitos-motivo-calculo{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:360px}.requisitos-guia-label{margin-left:4px}</style>
<section class="setup-fragment menu-grid-fragment modulo-legal-requisitos-fragment" data-context-filters="<?= $e($json((object)$filtrosFixos)) ?>" data-preferences="<?= $e($json($prefs)) ?>" data-grid-config='<?= $e($json(['columns' => $columns])) ?>'>
    <div class="crud-toolbar menu-grid-toolbar">
        <div class="crud-actions requisitos-toolbar-grupos" data-req-toolbar data-help="requisitos-toolbar">
            <div class="req-grupo req-grupo-operacoes" role="group" aria-labelledby="reqGrupo_operacoes" data-req-grupo="operacoes">
                <span class="req-grupo-rotulo" id="reqGrupo_operacoes"><?= $TE('comum.grupo.operacoes', ['pt' => 'Operações', 'en' => 'Operations', 'es' => 'Operaciones']) ?></span>
                <div class="req-grupo-botoes">
                    <button data-req-slot="operacoes-01" class="btn btn-sm btn-success" type="button" data-confirm-requisitos <?= !$permission['pode_editar'] ? 'disabled' : '' ?> title="<?= !$permission['pode_editar'] ? $e($permissionTitle) : $e($T('modulolegal_requisitoslegais.botao.confirmar_alteracao', ['pt' => 'Confirmar alteração', 'en' => 'Confirm change', 'es' => 'Confirmar cambio'])) ?>"><i class="bi bi-check2"></i></button>
                    <button data-req-slot="operacoes-02" class="btn btn-sm btn-outline-secondary" type="button" data-reload-grid title="<?= $e($T('comum.botao.recarregar', ['pt' => 'Recarregar', 'en' => 'Reload', 'es' => 'Recargar'])) ?>"><i class="bi bi-arrow-clockwise"></i></button>
                    <button data-req-slot="operacoes-03" class="btn btn-sm btn-outline-success" type="button" data-export-requisitos data-export-cadastro <?= !$permission['pode_exportar_excel'] ? 'disabled data-permission-locked="1"' : '' ?> title="<?= !$permission['pode_exportar_excel'] ? $e($permissionTitle) : $e($T('comum.botao.exportar', ['pt' => 'Exportar Excel', 'en' => 'Export to Excel', 'es' => 'Exportar a Excel'])) ?>"><i class="bi bi-file-earmark-excel"></i></button>
                    <button data-req-slot="operacoes-04" class="btn btn-sm btn-outline-secondary" type="button" data-columns title="<?= $e($T('comum.botao.colunas', ['pt' => 'Colunas', 'en' => 'Columns', 'es' => 'Columnas'])) ?>"><i class="bi bi-layout-three-columns"></i></button>
                    <button data-req-slot="operacoes-05" class="btn btn-sm btn-outline-info grid-crud-button" type="button" data-open-guide-requisitos title="Guia interativo - Requisitos Legais" aria-label="Guia interativo - Requisitos Legais"><i class="bi bi-question-circle"></i></button>
                    <?php if ($temInativacao): ?><button data-req-slot="operacoes-07" class="btn btn-sm btn-outline-danger grid-crud-button" type="button" data-inativar-requisito disabled<?= $podeInativar ? '' : ' data-permission-locked="1"' ?> title="<?= $podeInativar ? $e($T('modulolegal_requisitoslegais.botao.inativar', ['pt' => 'Inativar requisito nesta unidade', 'en' => 'Deactivate requirement for this unit', 'es' => 'Inactivar requisito en esta unidad'])) : $e($permissionTitle) ?>" aria-label="<?= $e($T('modulolegal_requisitoslegais.botao.inativar', ['pt' => 'Inativar requisito nesta unidade', 'en' => 'Deactivate requirement for this unit', 'es' => 'Inactivar requisito en esta unidad'])) ?>"><i class="bi bi-archive"></i></button>
                    <button data-req-slot="operacoes-08" class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-requisitos-inativados title="<?= $e($T('modulolegal_requisitoslegais.botao.inativados', ['pt' => 'Requisitos inativados', 'en' => 'Deactivated requirements', 'es' => 'Requisitos inactivados'])) ?>" aria-label="<?= $e($T('modulolegal_requisitoslegais.botao.inativados', ['pt' => 'Requisitos inactivados', 'en' => 'Deactivated requirements', 'es' => 'Requisitos inactivados'])) ?>"><i class="bi bi-archive-fill"></i></button><?php endif; ?>
                </div>
            </div>
            <div class="req-grupo req-grupo-correlacionamentos" role="group" aria-labelledby="reqGrupo_correlacionamentos" data-req-grupo="correlacionamentos">
                <span class="req-grupo-rotulo" id="reqGrupo_correlacionamentos"><?= $TE('comum.grupo.associacoes', ['pt' => 'Associações', 'en' => 'Associations', 'es' => 'Asociaciones']) ?></span>
                <div class="req-grupo-botoes">
                    <button data-req-slot="correlacionamentos-01" class="btn btn-sm btn-outline-secondary" type="button" data-open-requisito-popup="licencas" disabled title="<?= $e($T('modulolegal_requisitoslegais.botao.licencas', ['pt' => 'Licenças', 'en' => 'Licenses', 'es' => 'Licencias'])) ?>" aria-label="<?= $e($T('modulolegal_requisitoslegais.botao.licencas', ['pt' => 'Licenças', 'en' => 'Licenses', 'es' => 'Licencias'])) ?>"><i class="bi bi-file-earmark-text"></i></button>
                    <button data-req-slot="correlacionamentos-02" class="btn btn-sm btn-outline-secondary" type="button" data-open-requisito-popup="medicaomonitoramento" disabled title="<?= $e($T('modulolegal_requisitoslegais.botao.medicao_monitoramento', ['pt' => 'Medição e Monitoramento', 'en' => 'Measurement and monitoring', 'es' => 'Medición y monitoreo'])) ?>" aria-label="<?= $e($T('modulolegal_requisitoslegais.botao.medicao_monitoramento', ['pt' => 'Medição e Monitoramento', 'en' => 'Measurement and monitoring', 'es' => 'Medición y monitoreo'])) ?>"><i class="bi bi-graph-up"></i></button>
                    <button data-req-slot="correlacionamentos-03" class="btn btn-sm btn-outline-secondary" type="button" data-open-requisito-popup="treinamentos" disabled title="<?= $e($T('modulolegal_requisitoslegais.botao.treinamentos', ['pt' => 'Treinamentos', 'en' => 'Training', 'es' => 'Capacitaciones'])) ?>" aria-label="<?= $e($T('modulolegal_requisitoslegais.botao.treinamentos', ['pt' => 'Treinamentos', 'en' => 'Training', 'es' => 'Capacitaciones'])) ?>"><i class="bi bi-mortarboard"></i></button>
                    <button data-req-slot="correlacionamentos-04" class="btn btn-sm btn-outline-secondary" type="button" data-open-requisito-popup="infraestrutura" disabled title="<?= $e($T('modulolegal_requisitoslegais.botao.infraestrutura', ['pt' => 'Infraestrutura', 'en' => 'Infrastructure', 'es' => 'Infraestructura'])) ?>" aria-label="<?= $e($T('modulolegal_requisitoslegais.botao.infraestrutura', ['pt' => 'Infraestrutura', 'en' => 'Infrastructure', 'es' => 'Infraestructura'])) ?>"><i class="bi bi-building"></i></button>
                    <button data-req-slot="correlacionamentos-05" class="btn btn-sm btn-outline-secondary" type="button" data-open-requisito-popup="controles" disabled title="<?= $e($T('modulolegal_requisitoslegais.botao.controles', ['pt' => 'Controles', 'en' => 'Controls', 'es' => 'Controles'])) ?>" aria-label="<?= $e($T('modulolegal_requisitoslegais.botao.controles', ['pt' => 'Controles', 'en' => 'Controls', 'es' => 'Controles'])) ?>"><i class="bi bi-sliders"></i></button>
                    <button data-req-slot="correlacionamentos-06" class="btn btn-sm btn-outline-secondary" type="button" data-open-requisito-popup="homologacao" disabled title="<?= $e($T('modulolegal_requisitoslegais.botao.homologacao', ['pt' => 'Homologação de Fornecedores', 'en' => 'Supplier approval', 'es' => 'Homologación de proveedores'])) ?>" aria-label="<?= $e($T('modulolegal_requisitoslegais.botao.homologacao', ['pt' => 'Homologação de Fornecedores', 'en' => 'Supplier approval', 'es' => 'Homologación de proveedores'])) ?>"><i class="bi bi-person-check"></i></button>
                </div>
            </div>
            <div class="req-grupo req-grupo-avaliacao" role="group" aria-labelledby="reqGrupo_avaliacao" data-req-grupo="avaliacao">
                <span class="req-grupo-rotulo" id="reqGrupo_avaliacao"><?= $TE('comum.grupo.avaliacao', ['pt' => 'Avaliação', 'en' => 'Assessment', 'es' => 'Evaluación']) ?></span>
                <div class="req-grupo-botoes">
                    <button data-req-slot="avaliacao-01" class="btn btn-sm btn-outline-secondary" type="button" data-open-requisito-popup="situacao" disabled title="<?= $e($T('modulolegal_requisitoslegais.botao.situacao', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación'])) ?>" aria-label="<?= $e($T('modulolegal_requisitoslegais.botao.situacao', ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación'])) ?>"><i class="bi bi-clipboard-check"></i></button>
                    <button data-req-slot="avaliacao-02" class="btn btn-sm btn-outline-secondary" type="button" data-open-requisito-popup="checklist" disabled title="<?= $e($T('modulolegal_requisitoslegais.botao.checklist', ['pt' => 'CheckList', 'en' => 'Checklist', 'es' => 'Lista de verificación'])) ?>" aria-label="<?= $e($T('modulolegal_requisitoslegais.botao.checklist', ['pt' => 'CheckList', 'en' => 'Checklist', 'es' => 'Lista de verificación'])) ?>"><i class="bi bi-list-check"></i></button>
                    <button data-req-slot="avaliacao-03" class="btn btn-sm btn-outline-secondary" type="button" data-open-requisito-popup="alteracoes" disabled title="<?= $e($T('modulolegal_requisitoslegais.botao.alteracoes', ['pt' => 'Alterações', 'en' => 'Changes', 'es' => 'Cambios'])) ?>" aria-label="<?= $e($T('modulolegal_requisitoslegais.botao.alteracoes', ['pt' => 'Alterações', 'en' => 'Changes', 'es' => 'Cambios'])) ?>"><i class="bi bi-arrow-repeat"></i></button>
                    <button data-req-slot="avaliacao-04" class="btn btn-sm btn-outline-success" type="button" data-open-questionnaire data-questionnaire-context="LEGAL" <?= !$permission['pode_visualizar'] ? 'disabled' : '' ?> title="<?= $e($T('modulolegal_requisitoslegais.botao.questionario', ['pt' => 'Questionário Legal', 'en' => 'Legal questionnaire', 'es' => 'Cuestionario Legal'])) ?>" aria-label="<?= $e($T('modulolegal_requisitoslegais.botao.questionario', ['pt' => 'Questionário Legal', 'en' => 'Legal questionnaire', 'es' => 'Cuestionario Legal'])) ?>"><i class="bi bi-ui-checks-grid"></i></button>
                    <button data-req-slot="avaliacao-05" class="btn btn-sm btn-outline-warning" type="button" data-request-ia-analysis <?= !$permission['pode_visualizar'] ? 'disabled' : '' ?> title="<?= $e($T('modulolegal_requisitoslegais.botao.analise_ia', ['pt' => 'Solicitar análise de IA', 'en' => 'Request AI analysis', 'es' => 'Solicitar análisis de IA'])) ?>" aria-label="<?= $e($T('modulolegal_requisitoslegais.botao.analise_ia', ['pt' => 'Solicitar análise de IA', 'en' => 'Request AI analysis', 'es' => 'Solicitar análisis de IA'])) ?>"><i class="bi bi-stars"></i></button>
                    <?php if ($colunasSituacao): ?><button data-req-slot="avaliacao-06" class="btn btn-sm btn-outline-primary" type="button" data-relatorio-atendimento <?= empty($permission['pode_gerar_pdf']) && empty($permission['pode_exportar_excel']) ? 'disabled data-permission-locked="1"' : '' ?> title="<?= $e($T('modulolegal_requisitoslegais.botao.relatorio_atendimento', ['pt' => 'Relatório de Atendimento Legal (PDF e Excel, com os filtros do grid)', 'en' => 'Legal Compliance Report (PDF and Excel, with the grid filters)', 'es' => 'Informe de Cumplimiento Legal (PDF y Excel, con los filtros del grid)'])) ?>" aria-label="<?= $e($T('modulolegal_requisitoslegais.botao.relatorio_atendimento', ['pt' => 'Relatório de Atendimento Legal (PDF e Excel, com os filtros do grid)', 'en' => 'Legal Compliance Report (PDF and Excel, with the grid filters)', 'es' => 'Informe de Cumplimiento Legal (PDF y Excel, con los filtros del grid)'])) ?>"><i class="bi bi-file-earmark-bar-graph"></i></button><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="crud-search"><i class="bi bi-search"></i><input type="search" placeholder="<?= $e($T('comum.busca.grid', ['pt' => 'Buscar no grid', 'en' => 'Search the grid', 'es' => 'Buscar en la cuadrícula'])) ?>"></div>
    </div>
    <div class="legal-context-filters requisitos-responsavel-filter">
        <div class="legal-context-field">
            <label for="requisitosResponsavel"><?= $e($T('modulolegal_requisitoslegais.filtro.responsavel', ['pt' => 'Responsável', 'en' => 'Responsible', 'es' => 'Responsable'])) ?></label>
            <select class="form-select form-select-sm" id="requisitosResponsavel" data-grid-filter="RESPONSAVEL_IDS" data-help="requisitos-filtro-responsavel">
                <option value=""><?= $e($T('comum.filtro.todos', ['pt' => 'Todos', 'en' => 'All', 'es' => 'Todos'])) ?></option>
                <?php foreach ($responsaveisRows as $responsavel): $responsavelId = (string)($responsavel['CODUSUARIO'] ?? ''); $responsavelValue = ',' . $responsavelId . ','; $responsavelLabel = trim((string)($responsavel['NOME'] ?? '')) ?: (string)($responsavel['USERID'] ?? $responsavelId); ?>
                    <option value="<?= $e($responsavelValue) ?>"<?= $responsavelSelecionado === $responsavelValue ? ' selected' : '' ?>><?= $e($responsavelLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="legal-context-field">
            <label for="requisitosLeiRevogada"><?= $e($T('modulolegal_requisitoslegais.filtro.lei_revogada', ['pt' => 'Lei revogada', 'en' => 'Repealed law', 'es' => 'Ley derogada'])) ?></label>
            <select class="form-select form-select-sm" id="requisitosLeiRevogada" data-grid-filter="LEI_REVOGADA" data-help="requisitos-filtro-lei-revogada">
                <option value="NAO"<?= ($filters['LEI_REVOGADA'] ?? 'NAO') === 'NAO' ? ' selected' : '' ?>><?= $e($T('modulolegal_requisitoslegais.filtro.nao', ['pt' => 'Não', 'en' => 'No', 'es' => 'No'])) ?></option>
                <option value="SIM"<?= ($filters['LEI_REVOGADA'] ?? '') === 'SIM' ? ' selected' : '' ?>><?= $e($T('modulolegal_requisitoslegais.filtro.sim', ['pt' => 'Sim', 'en' => 'Yes', 'es' => 'Sí'])) ?></option>
            </select>
        </div>
        <div class="legal-context-field">
            <label for="requisitosSemEvidencia"><?= $e($T('modulolegal_requisitoslegais.filtro.sem_evidencia', ['pt' => 'Requisitos sem evidência', 'en' => 'Requirements without evidence', 'es' => 'Requisitos sin evidencia'])) ?></label>
            <select class="form-select form-select-sm" id="requisitosSemEvidencia" data-grid-filter="SEM_EVIDENCIA" data-help="requisitos-filtro-sem-evidencia">
                <option value=""<?= ($filters['SEM_EVIDENCIA'] ?? '') === '' ? ' selected' : '' ?>><?= $e($T('comum.filtro.todos', ['pt' => 'Todos', 'en' => 'All', 'es' => 'Todos'])) ?></option>
                <option value="1"<?= ($filters['SEM_EVIDENCIA'] ?? '') === '1' ? ' selected' : '' ?>><?= $e($T('comum.filtro.sim', ['pt' => 'Sim', 'en' => 'Yes', 'es' => 'Sí'])) ?></option>
                <option value="0"<?= ($filters['SEM_EVIDENCIA'] ?? '') === '0' ? ' selected' : '' ?>><?= $e($T('comum.filtro.nao', ['pt' => 'Não', 'en' => 'No', 'es' => 'No'])) ?></option>
            </select>
        </div>
        <?php if ($filtrosFixos): ?><div class="legal-context-field requisitos-filtros-fixos"><label><?= $e($T('modulolegal_requisitoslegais.filtro.aplicados', ['pt' => 'Filtro aplicado', 'en' => 'Applied filter', 'es' => 'Filtro aplicado'])) ?></label><div>
            <?php foreach ($filtrosFixos as $chave => $valor): [$rotFixo, $valFixo] = $rotuloFixo($chave, $valor); ?><span class="requisitos-filtro-fixo" data-filtro-fixo="<?= $e($chave) ?>"><?= $e($rotFixo) ?>: <b><?= $e($valFixo) ?></b><?php if (strcasecmp((string)$chave, 'CODLICENCA') !== 0): ?><button type="button" class="btn-close" data-remover-filtro-fixo title="<?= $e($T('modulolegal_requisitoslegais.filtro.remover', ['pt' => 'Remover filtro', 'en' => 'Remove filter', 'es' => 'Quitar filtro'])) ?>"></button><?php endif; ?></span><?php endforeach; ?>
        </div></div><?php endif; ?>
    </div>
    <form class="menu-grid-form" id="requisitosLegaisGridForm">
        <div class="menu-grid-scroll">
        <table class="table table-sm menu-batch-grid" id="requisitosLegaisBatchGrid" data-help="requisitos-grid">
                <thead><tr>
                    <?php foreach ($columns as $field => $fieldCfg): $options = $fieldCfg['options'] ?? null; ?>
                        <th data-column="<?= $e($field) ?>" style="width:<?= (int)($prefs[$field]['largura'] ?? 150) ?>px">
                            <div class="column-heading"><span><?= $e($tr($fieldCfg['label'])) ?></span>
                                <?php if (is_array($options)): ?>
                                    <select class="column-filter form-select form-select-sm" data-column-filter="<?= $e($field) ?>"><option value=""><?= $e($T('comum.filtro.todos', ['pt' => 'Todos', 'en' => 'All', 'es' => 'Todos'])) ?></option><?php foreach ($options as $option => $label): ?><option value="<?= $e($option) ?>"<?= (string)$option !== '' && $valorFiltro((string)$field) === (string)$option ? ' selected' : '' ?>><?= $e($tr($label)) ?></option><?php endforeach; ?></select>
                                <?php elseif (($fieldCfg['filter'] ?? 'text') !== 'none'): ?><input class="column-filter" type="search" data-column-filter="<?= $e($field) ?>" value="<?= $e($valorFiltro((string)$field)) ?>" placeholder="<?= $e($T('comum.busca.coluna', ['pt' => 'Buscar', 'en' => 'Search', 'es' => 'Buscar'])) ?>"><?php endif; ?>
                            </div>
                        </th>
                    <?php endforeach; ?>
                </tr></thead>
                <tbody>
                    <?php foreach ($rows as $row): ?><tr data-row data-key="<?= (int)($row['CODLEGISLACAXEMPRESA'] ?? 0) ?>" data-help="requisitos-selecionar-linha">
                        <?php foreach ($columns as $field => $fieldCfg): ?><td data-column="<?= $e($field) ?>"<?= $field === 'MOTIVO_CALCULO' ? ' class="requisitos-motivo-calculo" title="' . $e((string)($row[$field] ?? '')) . '"' : '' ?><?= $field === 'RISCO_REQUISITO' && (string)($row[$field] ?? '') !== '' ? ' class="requisitos-risco requisitos-risco-' . $e(strtolower(str_replace(['í', 'ã', ' '], ['i', 'a', '-'], (string)$row[$field]))) . '"' : '' ?><?= $field === 'PENDENCIAS_CRITICAS' && (int)($row[$field] ?? 0) > 0 ? ' class="requisitos-pendencia-critica" title="' . $e($tr(['pt' => 'Item não atendido com risco Crítico: ação imediata', 'en' => 'Item not met with Critical risk: immediate action', 'es' => 'Ítem no cumplido con riesgo Crítico: acción inmediata'])) . '"' : '' ?>><?php if ($field === 'PODER'): ?><select class="grid-input requisitos-poder-input" data-control-field data-original-value="<?= $e((string)($row[$field] ?? '')) ?>" <?= !$permission['pode_editar'] ? 'disabled' : '' ?>><option value="">-</option><?php foreach (['Conhecimento', 'Influência', 'Controle'] as $poder): ?><option value="<?= $e($poder) ?>"<?= (string)($row[$field] ?? '') === $poder ? ' selected' : '' ?>><?= $e($tr(['pt' => $poder, 'en' => $poder === 'Conhecimento' ? 'Knowledge' : ($poder === 'Influência' ? 'Influence' : 'Control'), 'es' => $poder === 'Conhecimento' ? 'Conocimiento' : ($poder === 'Influência' ? 'Influencia' : 'Control')])) ?></option><?php endforeach; ?></select><?php elseif ($field === 'PCT_ATENDIMENTO'): ?><?= (string)($row[$field] ?? '') !== '' ? $e((string)$row[$field]) . '%' : '' ?><?php else: ?><?= $e((string)($row[$field] ?? '')) ?><?php endif; ?></td><?php endforeach; ?>
                    </tr><?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>
    <nav class="grid-pagination" aria-label="<?= $e($T('comum.paginacao.rotulo', ['pt' => 'Paginação', 'en' => 'Pagination', 'es' => 'Paginación'])) ?>"><button type="button" class="btn btn-sm btn-outline-secondary grid-page-prev" title="<?= $e($T('comum.paginacao.anterior', ['pt' => 'Anterior', 'en' => 'Previous', 'es' => 'Anterior'])) ?>"><i class="bi bi-chevron-left"></i></button><span class="grid-page-status"><?= $e($T('comum.paginacao.calculando', ['pt' => 'Calculando total...', 'en' => 'Counting records...', 'es' => 'Calculando total...'])) ?></span><button type="button" class="btn btn-sm btn-outline-secondary grid-page-next" title="<?= $e($T('comum.paginacao.proxima', ['pt' => 'Próxima', 'en' => 'Next', 'es' => 'Siguiente'])) ?>"><i class="bi bi-chevron-right"></i></button></nav>
<script src="../assets/js/ajuda-transacao.js?v=2"></script>
<script src="../assets/js/corrigir-acentuacao.js?v=2"></script>
</section>
<?= IdiomaService::script(['comum', 'modulolegal_requisitoslegais'], [
    'modulolegal_requisitoslegais.dica.ler_padrao' => ['pt' => 'Ler Padrão', 'en' => 'Read standard', 'es' => 'Leer estándar'],
    'modulolegal_requisitoslegais.dica.texto_norma' => ['pt' => 'Texto da Norma', 'en' => 'Standard text', 'es' => 'Texto de la norma'],
    'modulolegal_requisitoslegais.dica.abrir_texto_norma' => ['pt' => 'Abrir Texto da Norma', 'en' => 'Open standard text', 'es' => 'Abrir texto de la norma'],
    'comum.popup.mensagem' => ['pt' => 'Mensagem', 'en' => 'Message', 'es' => 'Mensaje'],
    'comum.popup.sucesso' => ['pt' => 'Sucesso', 'en' => 'Success', 'es' => 'Éxito'],
    'modulolegal_requisitoslegais.erro.confirmar_alteracao' => ['pt' => 'Não foi possível confirmar a alteração.', 'en' => 'The change could not be confirmed.', 'es' => 'No fue posible confirmar el cambio.'],
    'modulolegal_requisitoslegais.msg.alteracao_confirmada' => ['pt' => 'Alteração confirmada.', 'en' => 'Change confirmed.', 'es' => 'Cambio confirmado.'],
    'modulolegal_requisitoslegais.confirmacao.nivel' => ['pt' => 'Confirmar as alterações do nível de controle?', 'en' => 'Confirm the control level changes?', 'es' => '¿Confirmar los cambios del nivel de control?'],
    'modulolegal_requisitoslegais.titulo.requisito' => ['pt' => 'Requisito legal', 'en' => 'Legal requirement', 'es' => 'Requisito legal'],
    'modulolegal_requisitoslegais.msg.selecione_requisito' => ['pt' => 'Selecione um requisito legal.', 'en' => 'Select a legal requirement.', 'es' => 'Seleccione un requisito legal.'],
    'modulolegal_requisitoslegais.erro.consultar_analise' => ['pt' => 'Não foi possível consultar a última análise.', 'en' => 'The latest analysis could not be retrieved.', 'es' => 'No fue posible consultar el último análisis.'],
    'modulolegal_requisitoslegais.erro.solicitar_analise' => ['pt' => 'Não foi possível solicitar a análise.', 'en' => 'The analysis could not be requested.', 'es' => 'No fue posible solicitar el análisis.'],
    'modulolegal_requisitoslegais.erro.exportacao' => ['pt' => 'Não foi possível solicitar a exportação.', 'en' => 'The export could not be requested.', 'es' => 'No fue posible solicitar la exportación.'],
    'comum.msg.exportacao_solicitada' => ['pt' => 'Exportação solicitada; acompanhe os alertas.', 'en' => 'Export requested; follow the alerts.', 'es' => 'Exportación solicitada; siga las alertas.'],
    'modulolegal_requisitoslegais.erro.solicitar_analise_ia' => ['pt' => 'Não foi possível solicitar a análise de IA.', 'en' => 'The AI analysis could not be requested.', 'es' => 'No fue posible solicitar el análisis de IA.'],
]) ?>
<script src="../assets/vendor/jquery/jquery-3.7.1.min.js?v=2"></script><script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=2"></script><script src="../assets/js/visualizador-pdf.js?v=6"></script><script src="../assets/js/requisitos-colunas-fix.js?v=3"></script><script src="../assets/js/modulo-legal-requisitos.js?v=18"></script><script src="../assets/js/requisitos-relacionamentos-extra.js?v=3"></script><script src="../assets/js/grid-sort.js?v=2"></script><script src="../assets/js/grid-pagination.js?v=20"></script><script src="../assets/js/grid-column-filters.js?v=4"></script><script src="../assets/js/grid-loading.js?v=5"></script><script src="../assets/js/requisitos-toolbar-grupos.js?v=1"></script>
<script>
(function ($) {
    $(document).on('click', '.modulo-legal-requisitos-fragment [data-open-guide-requisitos]', function () {
        var url = new URL('guia_interativo_requisitos.php', window.location.href).toString(), host = window;
        try { for (var i = 0; i < 6 && host; i++) { if (host.document && host.document.body && (host === host.top || host.GreenPortalPopups)) break; if (host === host.parent) break; host = host.parent; } } catch (e) { host = window; }
        if (!host || !host.document || !host.document.body) return;
        var old = host.document.getElementById('greenPortalGuideDrawer'); if (old) old.remove();
        var css = host.document.getElementById('greenPortalGuideDrawerCss');
        if (!css) { css = host.document.createElement('style'); css.id = 'greenPortalGuideDrawerCss'; css.textContent = '#greenPortalGuideDrawer{position:fixed;top:0;right:0;bottom:0;width:min(390px,100vw);z-index:2147483000;background:var(--gp-surface,#fff);box-shadow:-8px 0 28px rgba(20,45,65,.18);border-left:1px solid var(--gp-border,#dbe4eb);transition:width .18s ease,height .18s ease,top .18s ease,bottom .18s ease}#greenPortalGuideDrawer iframe{width:100%;height:100%;border:0;display:block}#greenPortalGuideDrawer .guide-drawer-minimize{position:absolute;right:38px;top:8px;z-index:2;border:0;border-radius:5px;background:transparent;color:var(--gp-muted,#71808c);padding:3px 6px;cursor:pointer;font-size:15px}#greenPortalGuideDrawer .guide-drawer-minimize:hover{background:var(--gp-primary-soft,#e8f5ee);color:var(--gp-primary-dark,#185640)}#greenPortalGuideDrawer.is-minimized{top:auto;bottom:20px;width:250px;height:48px;border:1px solid var(--gp-border,#dbe4eb);border-radius:10px;box-shadow:var(--gp-shadow-md,0 10px 28px rgba(22,72,55,.18))}#greenPortalGuideDrawer.is-minimized iframe{display:none}'; host.document.head.appendChild(css); }
        var drawer = host.document.createElement('aside'); drawer.id = 'greenPortalGuideDrawer';
        var frame = host.document.createElement('iframe'); frame.title = 'Ajuda - Requisitos Legais'; frame.src = url;
        var minimize = host.document.createElement('button'); minimize.type = 'button'; minimize.className = 'guide-drawer-minimize'; minimize.setAttribute('aria-label', 'Minimizar ajuda'); minimize.title = 'Minimizar ajuda'; minimize.innerHTML = '<i class="bi bi-dash-lg"></i>';
        minimize.addEventListener('click', function () { var small = drawer.classList.toggle('is-minimized'); minimize.innerHTML = '<i class="bi ' + (small ? 'bi-arrows-angle-expand' : 'bi-dash-lg') + '"></i>'; minimize.setAttribute('aria-label', small ? 'Restaurar ajuda' : 'Minimizar ajuda'); minimize.title = small ? 'Restaurar ajuda' : 'Minimizar ajuda'; });
        drawer.appendChild(minimize); drawer.appendChild(frame); host.document.body.appendChild(drawer);
    });
    (function () {
        var mapa = {
            '[data-open-requisito-popup="licencas"]': 'requisitos-licencas',
            '[data-open-requisito-popup="medicaomonitoramento"]': 'requisitos-medicao-monitoramento',
            '[data-open-requisito-popup="treinamentos"]': 'requisitos-treinamentos',
            '[data-open-requisito-popup="infraestrutura"]': 'requisitos-infraestrutura',
            '[data-open-requisito-popup="controles"]': 'requisitos-controles',
            '[data-open-requisito-popup="homologacao"]': 'requisitos-homologacao',
            '[data-open-requisito-popup="situacao"]': 'requisitos-situacao',
            '[data-open-requisito-popup="checklist"]': 'requisitos-checklist',
            '[data-open-requisito-popup="alteracoes"]': 'requisitos-alteracoes',
            '[data-open-questionnaire]': 'requisitos-questionario',
            '[data-request-ia-analysis]': 'requisitos-ia',
            '[data-relatorio-atendimento]': 'requisitos-relatorio',
            '[data-columns]': 'requisitos-colunas',
            '[data-reload-grid]': 'requisitos-recarregar',
            '[data-confirm-requisitos]': 'requisitos-confirmar'
        };
        Object.keys(mapa).forEach(function (selector) { $(selector).attr('data-help', mapa[selector]); });
    }());
    // Relatório de Atendimento Legal: mesmos filtros e busca do grid (como a exportação)
    $(document).on('click', '.modulo-legal-requisitos-fragment [data-relatorio-atendimento]', function () {
        var $b = $(this), $f = $b.closest('.modulo-legal-requisitos-fragment'), filtros = {};
        try { var fixos = JSON.parse($f.attr('data-context-filters') || '{}'); if (fixos && typeof fixos === 'object' && !Array.isArray(fixos)) filtros = fixos; } catch (e) {}
        $f.find('[data-grid-filter], [data-column-filter]').each(function () { var v = String($(this).val() || '').trim(); if (v !== '') filtros[String($(this).data('grid-filter') || $(this).data('column-filter'))] = v; });
        $b.prop('disabled', true);
        $.post(window.location.href, { action: 'relatorio_request', filtros: JSON.stringify(filtros), busca: String($f.find('.crud-search input').val() || '').trim(), ajax: 1 }, function (r) {
            var host = window.parent && window.parent.GreenPortalPopups ? window.parent : window, msg = (r && r.message) || 'Não foi possível solicitar o relatório.';
            if (host.GreenPortalPopups && host.GreenPortalPopups.mensagem) host.GreenPortalPopups.mensagem({ titulo: 'Relatório de Atendimento Legal', mensagem: msg, icone: r && r.ok ? 'bi-check-circle' : 'bi-exclamation-triangle' }); else window.alert(msg);
        }, 'json').fail(function () { window.alert('Não foi possível solicitar o relatório.'); }).always(function () { $b.prop('disabled', false); });
    });
    // Requisitos inativados (migração 286): inativar o requisito selecionado (com justificativa) e abrir a lista dos inativados
    var textosInativar = <?= $json([
        'titulo' => $T('modulolegal_requisitoslegais.inativar.titulo', ['pt' => 'Inativar requisito nesta unidade', 'en' => 'Deactivate requirement for this unit', 'es' => 'Inactivar requisito en esta unidad']),
        'explica' => $T('modulolegal_requisitoslegais.inativar.explica', ['pt' => 'O requisito sai da base ativa só desta unidade: deixa de aparecer no grid, nos dashboards, no cálculo da situação legal e no relatório. Avaliações, evidências e responsáveis ficam guardados, e ele pode ser reativado em "Requisitos inativados".', 'en' => 'The requirement leaves the active base for this unit only: it no longer shows in the grid, dashboards, legal status calculation and report. Assessments, evidence and owners are kept, and it can be reactivated in "Deactivated requirements".', 'es' => 'El requisito sale de la base activa solo de esta unidad: deja de aparecer en la cuadrícula, los paneles, el cálculo de la situación legal y el informe. Evaluaciones, evidencias y responsables se conservan, y puede reactivarse en "Requisitos inactivados".']),
        'motivo' => $T('modulolegal_requisitoslegais.inativar.motivo', ['pt' => 'Justificativa (obrigatória)', 'en' => 'Justification (required)', 'es' => 'Justificación (obligatoria)']),
        'confirmar' => $T('modulolegal_requisitoslegais.inativar.confirmar', ['pt' => 'Inativar', 'en' => 'Deactivate', 'es' => 'Inactivar']),
        'cancelar' => $T('comum.botao.cancelar', ['pt' => 'Cancelar', 'en' => 'Cancel', 'es' => 'Cancelar']),
        'curto' => $T('modulolegal_requisitoslegais.inativar.curto', ['pt' => 'Informe a justificativa (pelo menos 10 caracteres).', 'en' => 'Enter the justification (at least 10 characters).', 'es' => 'Informe la justificación (al menos 10 caracteres).']),
        'lista' => $T('modulolegal_requisitoslegais.botao.inativados', ['pt' => 'Requisitos inativados', 'en' => 'Deactivated requirements', 'es' => 'Requisitos inactivados']),
        'erro' => $T('modulolegal_requisitoslegais.inativar.erro', ['pt' => 'Não foi possível inativar o requisito.', 'en' => 'The requirement could not be deactivated.', 'es' => 'No fue posible inactivar el requisito.']),
    ]) ?>;
    function hostPopups() { var w = window; for (var i = 0; i < 6 && w; i++) { try { if (w.GreenPortalPopups && w.jQuery) return w; } catch (e) { break; } if (w === w.parent) break; w = w.parent; } return null; }
    function avisar(msg, ok) { var h = hostPopups(); if (h && h.GreenPortalPopups.mensagem) h.GreenPortalPopups.mensagem({ titulo: textosInativar.titulo, mensagem: msg, icone: ok ? 'bi-check-circle' : 'bi-exclamation-triangle' }); }
    $(document).on('click', '.modulo-legal-requisitos-fragment #requisitosLegaisBatchGrid tbody tr', function () {
        var $f = $(this).closest('.modulo-legal-requisitos-fragment');
        window.setTimeout(function () { var $b = $f.find('[data-inativar-requisito]'); if (!$b.is('[data-permission-locked]')) $b.prop('disabled', !$f.find('#requisitosLegaisBatchGrid tbody tr.selected').length); }, 0);
    });
    $(document).on('click', '.modulo-legal-requisitos-fragment [data-inativar-requisito]', function () {
        var $f = $(this).closest('.modulo-legal-requisitos-fragment'), $row = $f.find('#requisitosLegaisBatchGrid tbody tr.selected').first();
        var leg = $.trim($row.find('[data-column="CODLEGISLACAO"]').text()), unid = $.trim($row.find('[data-column="CODUNIDADE"]').text());
        if (!leg || !unid) return;
        var codigo = $.trim($row.find('[data-column="CODIGO"]').text()), unidade = $.trim($row.find('[data-column="UNIDADE"]').text());
        var $m = $('#requisitoInativarModal');
        if (!$m.length) {
            $m = $('<div class="modal fade" id="requisitoInativarModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header py-2"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
                + '<div class="modal-body"><div class="fw-semibold mb-1" data-inativar-alvo></div><p class="small text-muted mb-2" data-inativar-explica></p><label class="form-label small mb-1" data-inativar-rotulo></label><textarea class="form-control" rows="3" maxlength="2000" data-inativar-motivo style="height:auto;padding:6px 8px"></textarea><div class="small text-danger mt-1 d-none" data-inativar-erro></div></div>'
                + '<div class="modal-footer py-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal" data-inativar-cancelar></button><button type="button" class="btn btn-sm btn-danger" data-inativar-confirmar></button></div></div></div></div>').appendTo('body');
            $m.find('.modal-title').text(textosInativar.titulo); $m.find('[data-inativar-explica]').text(textosInativar.explica); $m.find('[data-inativar-rotulo]').text(textosInativar.motivo);
            $m.find('[data-inativar-cancelar]').text(textosInativar.cancelar); $m.find('[data-inativar-confirmar]').text(textosInativar.confirmar);
        }
        $m.find('[data-inativar-alvo]').text((codigo ? codigo + ' · ' : '') + unidade);
        $m.find('[data-inativar-motivo]').val(''); $m.find('[data-inativar-erro]').addClass('d-none');
        $m.find('[data-inativar-confirmar]').off('click').on('click', function () {
            var motivo = $.trim($m.find('[data-inativar-motivo]').val() || ''), $btn = $(this);
            if (motivo.length < 10) { $m.find('[data-inativar-erro]').text(textosInativar.curto).removeClass('d-none'); return; }
            $btn.prop('disabled', true);
            $.post(window.location.href, { action: 'inativar_requisito', codlegislacao: leg, codunidade: unid, motivo: motivo, ajax: 1 }, function (r) {
                if (r && r.ok) { bootstrap.Modal.getOrCreateInstance($m[0]).hide(); avisar(r.message, true); $f.find('[data-reload-grid]').first().trigger('click'); $f.find('[data-inativar-requisito]').prop('disabled', true); }
                else $m.find('[data-inativar-erro]').text((r && r.message) || textosInativar.erro).removeClass('d-none');
            }, 'json').fail(function () { $m.find('[data-inativar-erro]').text(textosInativar.erro).removeClass('d-none'); }).always(function () { $btn.prop('disabled', false); });
        });
        bootstrap.Modal.getOrCreateInstance($m[0]).show();
    });
    $(document).on('click', '.modulo-legal-requisitos-fragment [data-requisitos-inativados]', function () {
        var url = new URL('modulolegal_requisitos_inativados.php', window.location.href).toString(), h = hostPopups();
        if (h) h.GreenPortalPopups.transacao({ titulo: textosInativar.lista, conteudo: h.jQuery('<iframe>', { style: 'width:100%;height:100%;border:0;display:block;' }).attr('src', url) });
        else window.location.href = url;
    });
    // Etiqueta de filtro fixo (link de dashboard): remove o filtro e recarrega o grid
    $(document).on('click', '.modulo-legal-requisitos-fragment [data-remover-filtro-fixo]', function () {
        var $chip = $(this).closest('[data-filtro-fixo]'), $f = $chip.closest('.modulo-legal-requisitos-fragment'), chave = String($chip.data('filtro-fixo')), fixos = {};
        try { fixos = JSON.parse($f.attr('data-context-filters') || '{}') || {}; } catch (e) { fixos = {}; }
        delete fixos[chave]; $f.attr('data-context-filters', JSON.stringify(fixos));
        var $grupo = $chip.closest('.requisitos-filtros-fixos'); $chip.remove(); if (!$grupo.find('[data-filtro-fixo]').length) $grupo.remove();
        $f.find('#requisitosLeiRevogada').trigger('change');
    });
}(jQuery));
</script>
<?php
    }
    private static function env(): array { $r = []; foreach (file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) { $line = trim($line); if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue; [$k, $v] = explode('=', $line, 2); $r[trim($k)] = trim(trim($v), "\"'"); } return $r; }
    private static function options(): array { return [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]; }
}
