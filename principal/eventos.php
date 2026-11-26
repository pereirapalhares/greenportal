<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/PermissaoTransacao.php';
require_once dirname(__DIR__) . '/classes/TransactionSecurity.php';
require_once dirname(__DIR__) . '/classes/AgendaService.php';
require_once dirname(__DIR__) . '/classes/IdiomaService.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
// Textos em Administração › Textos e idiomas (grupo EVENTOS; cadastro automático ao abrir como ADMINISTRADOR).
$h = static fn(string $chave, array $padrao): string => htmlspecialchars(IdiomaService::t('eventos.' . $chave, $padrao), ENT_QUOTES, 'UTF-8');
$j = static fn(string $chave, array $padrao): string => (string)json_encode(IdiomaService::t('eventos.' . $chave, $padrao), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if (empty($_SESSION['autenticado'])) { http_response_code(401); exit(IdiomaService::t('eventos.erro.sessao', ['pt' => 'Sessão expirada.', 'en' => 'Your session has expired.', 'es' => 'Su sesión expiró.'])); }

$env = GreenPortalDatabase::env();
$dbName = (string)($_SESSION['tenant']['banco_dados'] ?? '');
if ($dbName === '') { http_response_code(400); exit(IdiomaService::t('eventos.erro.tenant', ['pt' => 'Empresa não identificada.', 'en' => 'Company not identified.', 'es' => 'Empresa no identificada.'])); }
if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) { http_response_code(400); exit(IdiomaService::t('eventos.erro.tenant_invalido', ['pt' => 'Empresa inválida.', 'en' => 'Invalid company.', 'es' => 'Empresa inválida.'])); }
$agendaMode = in_array(strtolower((string)($_GET['modo'] ?? '')), ['agenda', 'universal'], true);
// Migração 317: cronograma da auditoria de homologação (aba Auditoria da Gestão de Fornecedores), como o legado
// cadastros/ev_schedule_c.php?codauditoria=<codfornecedorxtipofornecedores>. Leitura e gravação só pelos procedimentos sp_fo_cronograma_*.
$auditId = $agendaMode ? 0 : ((int)(filter_input(INPUT_GET, 'codauditoria', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0) ?: (int)($_POST['codauditoria'] ?? 0));
$auditMode = $auditId > 0;
$eventsTransaction = $auditMode ? 'HOMOLOGACAO_FORNECEDOR_FORNECEDOR' : 'MM_PLANO_MEDICAO_MONITORAMENTO';
$pdo = $agendaMode
    ? TransactionSecurity::connectAuthenticated('HOME_AGENDA')
    : TransactionSecurity::connect($eventsTransaction);
$agendaService = new AgendaService($pdo, new TaskService($pdo));
$permission = $agendaMode
    ? ['pode_incluir' => false, 'pode_visualizar' => true, 'pode_visualizar_total' => false, 'pode_editar' => false, 'pode_excluir' => false]
    : PermissaoTransacao::obter($eventsTransaction);
if (!$agendaMode && !$permission['pode_visualizar']) { http_response_code(403); exit(IdiomaService::t('eventos.erro.sem_permissao', ['pt' => 'Sem permissão para consultar eventos.', 'en' => 'Not allowed to view events.', 'es' => 'Sin permiso para consultar eventos.'])); }

$monitoringId = filter_input(INPUT_GET, 'codmonitoramento', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: (int)($_POST['codmonitoramento'] ?? 0);
$unitFromRequest = filter_input(INPUT_GET, 'codunidade', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: (int)($_POST['codunidade'] ?? 0);
if ($monitoringId < 1 && !$agendaMode && !$auditMode) { http_response_code(400); exit(IdiomaService::t('eventos.erro.monitoramento_invalido', ['pt' => 'Monitoramento inválido.', 'en' => 'Invalid monitoring.', 'es' => 'Monitoreo inválido.'])); }
$monitoring = null;
$unit = 0;
$audit = null;
$auditCall = static function (PDO $pdo, string $sql, array $params): array {
    $st = $pdo->prepare($sql); $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    while ($st->nextRowset()) { }
    $st->closeCursor();
    return $rows;
};
if ($auditMode) {
    require_once dirname(__DIR__) . '/classes/HomologacaoFornecedorAcesso.php';
    try { $audit = $auditCall($pdo, 'CALL sp_fo_cronograma_contexto(?)', [$auditId])[0] ?? null; }
    catch (Throwable) { http_response_code(503); exit(IdiomaService::t('eventos.erro.migracao_317', ['pt' => 'Cronograma indisponível: falta aplicar a migração 317.', 'en' => 'Schedule unavailable: migration 317 has not been applied.', 'es' => 'Cronograma no disponible: falta aplicar la migración 317.'])); }
    if (!$audit) { http_response_code(404); exit(IdiomaService::t('eventos.erro.auditoria_nao_localizada', ['pt' => 'Auditoria não localizada.', 'en' => 'Audit not found.', 'es' => 'Auditoría no encontrada.'])); }
    // Fornecedor externo (só os ligados a ele; FORNECEDOR não vê a aba Auditoria) e interno restrito por unidade (migração 316).
    HomologacaoFornecedorAcesso::exigirFornecedor($pdo, (int)$audit['CODFORNECEDOR'], 'auditoria');
    $unit = (int)($audit['CODUNIDADE'] ?? 0);
}
if (!$agendaMode && !$auditMode) {
    $monitoringStatement = $pdo->prepare('SELECT CODMONITORAMENTO, CODUNIDADE FROM mm_monitoramento WHERE CODMONITORAMENTO = :id');
    $monitoringStatement->execute(['id' => $monitoringId]);
    $monitoring = $monitoringStatement->fetch(PDO::FETCH_ASSOC);
    if (!$monitoring) { http_response_code(404); exit(IdiomaService::t('eventos.erro.monitoramento_nao_localizado', ['pt' => 'Monitoramento não localizado.', 'en' => 'Monitoring not found.', 'es' => 'Monitoreo no encontrado.'])); }
    $unit = (int)$monitoring['CODUNIDADE'];
    if ($unitFromRequest > 0 && $unitFromRequest !== $unit) { http_response_code(403); exit(IdiomaService::t('eventos.erro.unidade', ['pt' => 'Unidade incompatível com o monitoramento.', 'en' => 'Unit does not match the monitoring.', 'es' => 'Unidad incompatible con el monitoreo.'])); }
}

if (empty($_SESSION['eventos_csrf'])) $_SESSION['eventos_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['eventos_csrf'];
$message = ''; $error = '';
$initialTab = 'agenda';
$e = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$language = strtolower(substr((string)($_SESSION['idioma'] ?? 'pt'), 0, 2));
if (!in_array($language, ['pt', 'en', 'es'], true)) $language = 'pt';
$dateDisplayFormat = $language === 'en' ? 'm/d/Y H:i' : 'd/m/Y H:i';

if ($agendaMode) {
    // Home › Agenda (c454): eventos da agenda, tarefas e as mesmas pendências da aba Minhas pendências (classes/MinhasPendencias.php),
    // lidos só por views (migrações 210, 296, 310, 311 e 312) e mostrados no padrão de grid (eventos_agenda_universal.php).
    require_once dirname(__DIR__) . '/classes/MinhasPendencias.php';
    require_once dirname(__DIR__) . '/classes/HomologacaoFornecedorAcesso.php';
    $userId = (int)($_SESSION['usuario']['codusuario'] ?? 0);
    $labels = static function (array $values) use ($language): string {
        return (string)($values[$language] ?? $values['pt'] ?? reset($values));
    };
    $agendaItems = [];
    $quando = static function (mixed $valor): ?DateTimeImmutable {
        $valor = trim((string)$valor);
        if ($valor === '' || $valor <= '1900-01-01') return null;
        try { return new DateTimeImmutable($valor); } catch (Throwable) { return null; }
    };
    $addAgendaItem = static function (array $item) use (&$agendaItems): void {
        $item['title'] = trim((string)($item['title'] ?? '')) ?: 'Compromisso';
        $item['description'] = trim((string)($item['description'] ?? ''));
        $item['type'] = trim((string)($item['type'] ?? 'Agenda')) ?: 'Agenda';
        $item['status'] = trim((string)($item['status'] ?? 'ATIVO')) ?: 'ATIVO';
        $item['url'] = trim((string)($item['url'] ?? ''));
        $agendaItems[] = $item;
    };
    $pendencias = new MinhasPendencias($pdo, $userId, HomologacaoFornecedorAcesso::externo());

    // 1. Eventos da agenda (agenda_eventos): o link abre a tela de origem do evento.
    $responsibilityPages = [
        'LICENCAS' => ['manutencaolegal_licencas.php', 'CODLICENCA'],
        'MEDICAO_MONITORAMENTO' => ['medicaoemonitoramento_mm_monitoramento.php', 'CODMONITORAMENTO'],
        'TREINAMENTO' => ['modulolegal_treinamentos.php', 'CODTREINAMENTO'],
        'INFRAESTRUTURA' => ['modulolegal_InfraEstrutura.php', 'CODINFRAESTRUTURA'],
        'CONTROLE' => ['modulolegal_controle.php', 'CODDOCUMENTO'],
    ];
    foreach ($pendencias->ler('vw_agenda_eventos_usuario', 1000) as $event) {
        $url = '';
        $sourceKey = (string)($event['CHAVE'] ?? '');
        if (preg_match('/^EV_EVENTO:(\d+):/i', $sourceKey, $match)) {
            $url = 'eventos.php?codmonitoramento=' . (int)$match[1];
        } elseif (preg_match('/^EV_AUDITORIA:(\d+):/i', $sourceKey, $match)) {
            $url = 'eventos.php?codauditoria=' . (int)$match[1]; // migração 317: cronograma da auditoria de homologação
        } elseif (preg_match('/^RESPONSABILIDADE_PILAR:([^:]+):(\d+):\d+$/i', $sourceKey, $match) && isset($responsibilityPages[strtoupper((string)$match[1])])) {
            [$page, $field] = $responsibilityPages[strtoupper((string)$match[1])];
            $url = $page . '?minhas_pendencias=1&filtros=' . rawurlencode((string)json_encode([$field => (int)$match[2]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $addAgendaItem(['date' => $quando($event['INICIO'] ?? ''), 'time' => true, 'type' => $labels(['pt' => 'Evento', 'en' => 'Event', 'es' => 'Evento']),
            'title' => $event['TITULO'] ?? '', 'description' => $event['DESCRICAO'] ?? '', 'status' => $event['STATUS'] ?? 'ATIVO', 'url' => $url]);
    }
    // 2. Tarefas pendentes (green_tasks); aprovação de IA abre o resultado da solicitação.
    foreach ($pendencias->ler('vw_agenda_tarefas_usuario', 300) as $task) {
        $ia = strtoupper((string)($task['TIPO'] ?? '')) === 'IA_APROVACAO';
        $url = $ia && (int)($task['CODSOLICITACAO'] ?? 0) > 0 ? 'ia_potenciais_resultado.php?codsolicitacao=' . (int)$task['CODSOLICITACAO'] : 'painel_pendencias.php?codtarefa=' . (int)$task['CODTAREFA'];
        $addAgendaItem(['date' => $quando($task['DATA_LIMITE'] ?? ''), 'time' => true, 'type' => $ia ? 'IA' : $labels(['pt' => 'Tarefa', 'en' => 'Task', 'es' => 'Tarea']),
            'title' => $task['TITULO'] ?? '', 'description' => $task['DESCRICAO'] ?? '', 'status' => $task['STATUS'] ?? 'PENDENTE', 'url' => $url,
            // Migração 345: o responsável conclui a tarefa pela Agenda (quem aprova o pilar recebe o aviso para conferir)
            'codtarefa' => (int)($task['CODTAREFA'] ?? 0)]);
    }
    // 3. Pendências dos pilares e da homologação (mesmas regras e motivos da aba Minhas pendências).
    foreach ($pendencias->itens() as $item) {
        $addAgendaItem(['date' => $item['prazo'], 'time' => false, 'type' => $item['pilar'], 'title' => $item['titulo'], 'description' => $item['motivo'],
            'status' => MinhasPendencias::sinal($item['prazo']) === 'vencido' ? 'ATRASADO' : 'PENDENTE', 'url' => $item['url']]);
    }
    // Ordem cronológica: o mais antigo (atrasado) primeiro; sem data no fim.
    usort($agendaItems, static fn(array $a, array $b): int => [$a['date'] === null, $a['date']?->format('Y-m-d H:i:s') ?? '', $a['type'], $a['title']] <=> [$b['date'] === null, $b['date']?->format('Y-m-d H:i:s') ?? '', $b['type'], $b['title']]);
    $agendaMissing = array_values(array_unique(array_map(static fn(string $v): string => MinhasPendencias::VIEWS_MIGRACAO[$v], $pendencias->faltando())));
    sort($agendaMissing);
    $locale = $language === 'en' ? 'en-US' : ($language === 'es' ? 'es-ES' : 'pt-BR');
    $agendaTitle = $labels(['pt' => 'Agenda', 'en' => 'Agenda', 'es' => 'Agenda']);
    $emptyLabel = $labels(['pt' => 'Nenhuma ação programada ou pendente.', 'en' => 'No scheduled or pending actions.', 'es' => 'No hay acciones programadas o pendientes.']);
    require __DIR__ . '/eventos_agenda_universal.php';
    exit;
}

$parseDate = static function (string $value) use ($language): ?string {
    $value = trim($value);
    if ($value === '') return null;
    $value = str_replace('T', ' ', $value);
    $formats = ['Y-m-d H:i', 'Y-m-d H:i:s'];
    if ($language === 'en') {
        $formats = array_merge($formats, ['m/d/Y H:i', 'm/d/Y H:i:s']);
    } else {
        $formats = array_merge($formats, ['d/m/Y H:i', 'd/m/Y H:i:s']);
    }
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $value);
        $errors = DateTime::getLastErrors();
        if ($date && ($errors === false || ($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0)) {
            return $date->format('Y-m-d H:i:s');
        }
    }
    return null;
};

$auditSourceKey = static fn(int $eventId): string => 'EV_AUDITORIA:' . $auditId . ':' . $eventId;
$auditIds = static function (mixed $input): string {
    $list = is_array($input) ? $input : explode(',', (string)$input);
    return implode(',', array_values(array_unique(array_filter(array_map('intval', $list), static fn(int $n): bool => $n > 0))));
};
if ($auditMode && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Token de segurança inválido.');
        $action = (string)($_POST['event_action'] ?? '');
        $eventId = (int)($_POST['codevento'] ?? 0);
        if ($action === 'delete') {
            if (empty($permission['pode_excluir'])) throw new RuntimeException(IdiomaService::t('eventos.erro.sem_permissao_excluir', ['pt' => 'Seu perfil não pode excluir eventos.', 'en' => 'Your profile cannot delete events.', 'es' => 'Su perfil no puede eliminar eventos.']));
            $deleted = (int)($auditCall($pdo, 'CALL sp_fo_cronograma_excluir(?, ?)', [$eventId, $auditId])[0]['EXCLUIDOS'] ?? 0);
            if ($deleted < 1) throw new RuntimeException(IdiomaService::t('eventos.erro.evento_outra_auditoria', ['pt' => 'Evento não pertence a esta auditoria.', 'en' => 'The event does not belong to this audit.', 'es' => 'El evento no pertenece a esta auditoría.']));
            $agendaService->cancelSource($auditSourceKey($eventId));
            $message = IdiomaService::t('eventos.msg.excluido', ['pt' => 'Evento excluído.', 'en' => 'Event deleted.', 'es' => 'Evento eliminado.']);
        } else {
            if ($eventId > 0 && empty($permission['pode_editar'])) throw new RuntimeException(IdiomaService::t('eventos.erro.sem_permissao_alterar', ['pt' => 'Seu perfil não pode alterar eventos.', 'en' => 'Your profile cannot change events.', 'es' => 'Su perfil no puede modificar eventos.']));
            if ($eventId === 0 && empty($permission['pode_incluir'])) throw new RuntimeException(IdiomaService::t('eventos.erro.operacao', ['pt' => 'Operação não permitida para este evento.', 'en' => 'Operation not allowed for this event.', 'es' => 'Operación no permitida para este evento.']));
            $start = $parseDate((string)($_POST['datainicial'] ?? ''));
            $end = $parseDate((string)($_POST['datafim'] ?? ''));
            $description = trim((string)($_POST['dscevento'] ?? ''));
            if ($description === '' || !$start || !$end || strtotime($end) < strtotime($start)) throw new RuntimeException(IdiomaService::t('eventos.erro.periodo', ['pt' => 'Informe descrição e um período válido.', 'en' => 'Enter a description and a valid period.', 'es' => 'Informe descripción y un período válido.']));
            $email = in_array((string)($_POST['enviaremail'] ?? 'N'), ['S', 'N'], true) ? (string)$_POST['enviaremail'] : 'N';
            $auditCall($pdo, 'CALL sp_fo_cronograma_gravar(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $eventId, $auditId, (int)($_SESSION['usuario']['codusuario'] ?? 0), $description, mb_substr(trim((string)($_POST['dsclocalisacao'] ?? '')), 0, 200),
                (int)($_POST['lembrar'] ?? 15), $email, $start, $end, $auditIds($_POST['codusuario_envolvidos'] ?? []),
                trim((string)($_POST['auditoria_facilidades_necessarias'] ?? '')), $auditIds($_POST['codusuarios_auditores'] ?? []), $auditIds($_POST['codchecklists'] ?? [])]);
            $message = $eventId > 0
                ? IdiomaService::t('eventos.msg.atualizado', ['pt' => 'Evento atualizado.', 'en' => 'Event updated.', 'es' => 'Evento actualizado.'])
                : IdiomaService::t('eventos.msg.incluido', ['pt' => 'Evento incluído.', 'en' => 'Event added.', 'es' => 'Evento incluido.']);
        }
    } catch (Throwable $exception) {
        $msg = $exception->getMessage();
        if (preg_match('/1644\s+(.*)$/s', $msg, $m)) $msg = IdiomaService::mensagemBanco(trim($m[1])); // mensagem do procedimento (SIGNAL)
        $error = $msg; $initialTab = 'form';
    }
}

if (!$auditMode && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Token de segurança inválido.');
        $action = (string)($_POST['event_action'] ?? '');
        $canEdit = !empty($permission['pode_editar']) || !empty($permission['pode_incluir']);
        if ($action === 'delete' && empty($permission['pode_excluir'])) throw new RuntimeException(IdiomaService::t('eventos.erro.sem_permissao_excluir', ['pt' => 'Seu perfil não pode excluir eventos.', 'en' => 'Your profile cannot delete events.', 'es' => 'Su perfil no puede eliminar eventos.']));
        if ($action !== 'delete' && !$canEdit) throw new RuntimeException(IdiomaService::t('eventos.erro.sem_permissao_alterar', ['pt' => 'Seu perfil não pode alterar eventos.', 'en' => 'Your profile cannot change events.', 'es' => 'Su perfil no puede modificar eventos.']));
        $eventId = (int)($_POST['codevento'] ?? 0);
        if ($eventId > 0) {
            $check = $pdo->prepare('SELECT codevento FROM ev_eventos WHERE codevento=:event AND codmonitoramento=:monitoring');
            $check->execute(['event' => $eventId, 'monitoring' => $monitoringId]);
            if (!$check->fetchColumn()) throw new RuntimeException(IdiomaService::t('eventos.erro.evento_outro', ['pt' => 'Evento não pertence ao monitoramento selecionado.', 'en' => 'The event does not belong to the selected monitoring.', 'es' => 'El evento no pertenece al monitoreo seleccionado.']));
        }
        if ($action === 'delete') {
            $statement = $pdo->prepare('DELETE FROM ev_eventos WHERE codevento=:event AND codmonitoramento=:monitoring');
            $statement->execute(['event' => $eventId, 'monitoring' => $monitoringId]);
            $agendaService->cancelSource('EV_EVENTO:' . $monitoringId . ':' . $eventId);
            $message = IdiomaService::t('eventos.msg.excluido', ['pt' => 'Evento excluído.', 'en' => 'Event deleted.', 'es' => 'Evento eliminado.']);
        } else {
            $description = trim((string)($_POST['dscevento'] ?? ''));
            $start = $parseDate((string)($_POST['datainicial'] ?? ''));
            $end = $parseDate((string)($_POST['datafim'] ?? ''));
            if ($description === '' || !$start || !$end || strtotime($end) < strtotime($start)) throw new RuntimeException(IdiomaService::t('eventos.erro.periodo', ['pt' => 'Informe descrição e um período válido.', 'en' => 'Enter a description and a valid period.', 'es' => 'Informe descripción y un período válido.']));
            $userId = (int)($_SESSION['usuario']['codusuario'] ?? 0);
            $involvedInput = $_POST['codusuario_envolvidos'] ?? [];
            $involved = is_array($involvedInput) ? implode(',', array_map('intval', $involvedInput)) : trim((string)$involvedInput);
            $data = ['cat'=>3,'user'=>$userId,'description'=>$description,'location'=>trim((string)($_POST['dsclocalisacao'] ?? '')),'remember'=>(int)($_POST['lembrar'] ?? 15),'email'=>in_array((string)($_POST['enviaremail'] ?? 'N'), ['S','N'], true) ? (string)$_POST['enviaremail'] : 'N','start'=>$start,'end'=>$end,'unit'=>$unit,'involved'=>$involved,'monitoring'=>$monitoringId];
            if ($eventId > 0 && !empty($permission['pode_editar'])) {
                $sql = 'UPDATE ev_eventos SET dscevento=:description, dsclocalisacao=:location, lembrar=:remember, enviaremail=:email, datainicial=:start, datafim=:end, codusuario_envolvidos=:involved WHERE codevento=:event AND codmonitoramento=:monitoring';
                $data += ['event'=>$eventId]; $pdo->prepare($sql)->execute($data); $agendaService->syncSource('EV_EVENTO:' . $monitoringId . ':' . $eventId, ['codusuario_responsavel'=>$userId,'titulo'=>$description,'descricao'=>$description,'localizacao'=>$data['location'],'inicio'=>$start,'fim'=>$end,'origem_transacao'=>'eventos.php','origem_entidade'=>'ev_eventos','origem_id'=>$eventId]); $message = IdiomaService::t('eventos.msg.atualizado', ['pt' => 'Evento atualizado.', 'en' => 'Event updated.', 'es' => 'Evento actualizado.']);
            } elseif ($eventId === 0 && !empty($permission['pode_incluir']) && (int)($_POST['repetir_meses'] ?? 0) > 0) {
                // 26/11/2026 Revisão G item 67: cronograma recorrente (início, fim e período) — cria a série de medições de uma vez,
                // por sp_crud_insert; cada evento é uma medição prevista (pendência do responsável até a data).
                $meses = (int)$_POST['repetir_meses'];
                if (!in_array($meses, [1, 2, 3, 6, 12], true)) throw new RuntimeException('Período de recorrência inválido.');
                $ateTxt = trim((string)($_POST['repetir_ate'] ?? '')); $ateD = DateTime::createFromFormat('!Y-m-d', $ateTxt) ?: DateTime::createFromFormat('!d/m/Y', $ateTxt);
                $ate = $ateD ? $ateD->format('Y-m-d') : null;
                if (!$ate || strtotime(substr($ate, 0, 10) . ' 23:59:59') < strtotime($start)) throw new RuntimeException(IdiomaService::t('eventos.erro.repetir_ate', ['pt' => 'Informe em "Repetir até" uma data igual ou posterior ao Início.', 'en' => 'Enter a "Repeat until" date on or after the start.', 'es' => 'Informe en "Repetir hasta" una fecha igual o posterior al inicio.']));
                $i0 = new DateTimeImmutable($start); $duracao = strtotime($end) - strtotime($start); $limite = new DateTimeImmutable(substr($ate, 0, 10) . ' 23:59:59');
                $datas = [];
                for ($k = 0; $k < 120; $k++) { $d = $i0->modify('+' . ($k * $meses) . ' months'); if ($d > $limite) break; $datas[] = $d; }
                if (count($datas) > 60) throw new RuntimeException(IdiomaService::t('eventos.erro.repetir_max', ['pt' => 'A recorrência geraria mais de 60 medições: reduza o período "Repetir até".', 'en' => 'The recurrence would create more than 60 events.', 'es' => 'La recurrencia generaría más de 60 eventos.']));
                $total = count($datas);
                $ins = $pdo->prepare('CALL sp_crud_insert(?, ?)');
                foreach ($datas as $n => $d) {
                    $ins->execute(['ev_eventos', json_encode(['codcategoria' => 3, 'codusuario' => $userId, 'dscevento' => $total > 1 ? $description . ' (' . ($n + 1) . '/' . $total . ')' : $description,
                        'dsclocalisacao' => $data['location'], 'lembrar' => $data['remember'], 'enviaremail' => $data['email'], 'datainicial' => $d->format('Y-m-d H:i:s'),
                        'datafim' => date('Y-m-d H:i:s', $d->getTimestamp() + $duracao), 'codunidade' => $unit, 'codusuario_envolvidos' => $involved, 'codmonitoramento' => $monitoringId], JSON_UNESCAPED_UNICODE)]);
                    do { try { $ins->fetchAll(); } catch (Throwable $ignorar) {} } while ($ins->nextRowset());
                    $ins->closeCursor();
                }
                $message = IdiomaService::t('eventos.msg.serie', ['pt' => '{n} medição(ões) incluída(s) no cronograma.', 'en' => '{n} event(s) added to the schedule.', 'es' => '{n} evento(s) incluido(s) en el cronograma.']);
                $message = str_replace('{n}', (string)$total, $message);
            } elseif ($eventId === 0 && !empty($permission['pode_incluir'])) {
                $sql = 'INSERT INTO ev_eventos (codcategoria,codusuario,dscevento,dsclocalisacao,lembrar,enviaremail,datainicial,datafim,codunidade,codusuario_envolvidos,codmonitoramento) VALUES (:cat,:user,:description,:location,:remember,:email,:start,:end,:unit,:involved,:monitoring)';
                $pdo->prepare($sql)->execute($data); $message = IdiomaService::t('eventos.msg.incluido', ['pt' => 'Evento incluído.', 'en' => 'Event added.', 'es' => 'Evento incluido.']);
            } else throw new RuntimeException(IdiomaService::t('eventos.erro.operacao', ['pt' => 'Operação não permitida para este evento.', 'en' => 'Operation not allowed for this event.', 'es' => 'Operación no permitida para este evento.']));
        }
    } catch (Throwable $exception) { $error = $exception->getMessage(); $initialTab = 'form'; }
}

if ($auditMode) {
    $events = $auditCall($pdo, 'CALL sp_fo_cronograma_listar(?)', [$auditId]);
} else {
    $eventsStatement = $pdo->prepare('SELECT e.*, u.nome AS responsavel_nome FROM ev_eventos e LEFT JOIN usuarios u ON u.codusuario=e.codusuario WHERE e.codmonitoramento=:monitoring ORDER BY e.datainicial, e.codevento');
    $eventsStatement->execute(['monitoring' => $monitoringId]);
    $events = $eventsStatement->fetchAll(PDO::FETCH_ASSOC);
}
foreach ($events as $event) {
    $agendaService->syncSource($auditMode ? $auditSourceKey((int)$event['codevento']) : 'EV_EVENTO:' . $monitoringId . ':' . (int)$event['codevento'], ['codusuario_responsavel'=>(int)$event['codusuario'],'titulo'=>(string)$event['dscevento'],'descricao'=>(string)$event['dscevento'],'localizacao'=>(string)$event['dsclocalisacao'],'inicio'=>(string)$event['datainicial'],'fim'=>(string)$event['datafim'],'origem_transacao'=>'eventos.php','origem_entidade'=>'ev_eventos','origem_id'=>(int)$event['codevento']]);
}
$calendarEvents = array_map(static function (array $event): array {
    return [
        'codevento' => (int)($event['codevento'] ?? 0),
        'dscevento' => (string)($event['dscevento'] ?? ''),
        'dsclocalisacao' => (string)($event['dsclocalisacao'] ?? ''),
        'datainicial' => (string)($event['datainicial'] ?? ''),
        'datafim' => (string)($event['datafim'] ?? ''),
        'lembrar' => (int)($event['lembrar'] ?? 15),
        'enviaremail' => (string)($event['enviaremail'] ?? 'N'),
        'codusuario_envolvidos' => (string)($event['codusuario_envolvidos'] ?? ''),
        'auditoria_facilidades_necessarias' => (string)($event['auditoria_facilidades_necessarias'] ?? ''),
        'codusuarios_auditores' => (string)($event['codusuarios_auditores'] ?? ''),
        'codchecklists' => (string)($event['codchecklists'] ?? ''),
    ];
}, $events);
// Check list da auditoria (pa_checklist do legado), quando o banco tem a tabela (view criada pela migração 317).
$auditChecklists = [];
if ($auditMode) {
    try { $auditChecklists = $pdo->query('SELECT CODCHECKLIST, REQUISITO FROM vw_fo_cronograma_checklists ORDER BY REQUISITO')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable) { $auditChecklists = []; }
}
$users = $pdo->query('SELECT codusuario,nome FROM usuarios WHERE ativo=1 ORDER BY nome')->fetchAll(PDO::FETCH_ASSOC);
$userNamesById = [];
foreach ($users as $user) {
    $userNamesById[(string)$user['codusuario']] = (string)$user['nome'];
}
$participantNames = static function (string $value) use ($userNamesById): string {
    $names = [];
    foreach (array_filter(array_map('trim', explode(',', $value)), static fn(string $id): bool => $id !== '') as $id) {
        if (isset($userNamesById[$id])) $names[] = $userNamesById[$id];
    }
    return $names ? implode(', ', $names) : '—';
};
?><!doctype html>
<html lang="<?= $e($language === 'en' ? 'en' : ($language === 'es' ? 'es' : 'pt-BR')) ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $h('titulo', ['pt' => 'Eventos', 'en' => 'Events', 'es' => 'Eventos']) ?></title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2"><link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2"><link rel="stylesheet" href="../assets/css/app.css?v=5"><link rel="stylesheet" href="../assets/css/main.css?v=17">
<style>
html,body{height:100%}body{background:#f5f7fa;overflow:hidden}.eventos-scroll{max-height:calc(100vh - 285px);overflow:auto}.event-pane{display:none}.event-pane.active{display:block}.event-row{cursor:pointer}.event-row:hover{background:#eef6ff}.event-tabs .nav-link{display:flex;align-items:center;gap:.4rem}.event-grid-fragment .crud-toolbar{align-items:center;margin-bottom:8px}.event-grid-fragment .crud-actions{display:flex;align-items:center;gap:8px;min-height:0;padding:0;border:0}.event-grid-fragment .crud-actions>button{width:30px;min-width:30px;height:30px;padding:0;display:inline-flex;align-items:center;justify-content:center;font-size:0}.event-grid-fragment .crud-actions>button i{font-size:13px}.event-grid-fragment .crud-search{width:min(100%,300px);margin-left:auto}.event-grid-fragment .card{border:0!important;border-radius:0!important;box-shadow:none!important;background:transparent!important}.event-grid-fragment .card-body{padding:0}.event-grid-fragment .menu-grid-scroll{min-width:0;border:1px solid var(--gp-border);border-radius:8px;background:#fff}.event-grid-fragment .menu-batch-grid{width:max-content;min-width:100%;table-layout:fixed;margin:0;font-size:11px}.event-grid-fragment .menu-batch-grid th{white-space:nowrap}.event-grid-fragment .menu-batch-grid td{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.event-grid-fragment .menu-batch-grid tr.selected td{background:var(--gp-primary-soft)}.event-grid-fragment .grid-pagination{display:inline-flex;align-items:center;justify-content:flex-start;gap:5px;margin:0;padding:0;color:var(--gp-muted);font-size:10px}.event-grid-fragment .grid-pagination button{width:28px;min-width:28px;height:28px;padding:0;display:inline-flex;align-items:center;justify-content:center;font-size:0}.event-grid-fragment .grid-pagination button i{font-size:13px}.event-grid-fragment .grid-pagination .grid-page-status{min-width:105px;text-align:center;white-space:nowrap}
.event-calendar-pane{display:none}.event-calendar-pane.active{display:block}.calendar-toolbar{display:flex;align-items:center;gap:8px;margin-bottom:10px;min-height:44px;padding:8px 0;border-top:1px solid var(--gp-border);border-bottom:1px solid var(--gp-border)}.calendar-toolbar .crud-actions{display:flex;align-items:center;gap:8px;border:0;padding:0;min-height:0}.calendar-title{flex:1;text-align:center;color:var(--gp-primary-dark);font-weight:700;text-transform:capitalize}.event-calendar{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));border:1px solid var(--gp-border);border-radius:8px;overflow:hidden;background:#fff}.calendar-weekday{padding:8px 10px;background:var(--gp-primary-soft);border-bottom:1px solid var(--gp-border);color:var(--gp-muted);font-size:11px;font-weight:700;text-align:center}.calendar-day{min-height:118px;padding:7px;border-right:1px solid var(--gp-border);border-bottom:1px solid var(--gp-border);background:#fff}.calendar-day:nth-child(7n){border-right:0}.calendar-day.is-other-month{background:var(--gp-surface);color:var(--gp-muted)}.calendar-day.is-today{background:var(--gp-primary-soft);box-shadow:inset 0 0 0 2px var(--gp-primary)}.calendar-day-number{display:block;margin-bottom:5px;color:var(--gp-muted);font-size:11px;font-weight:700}.calendar-day.is-today .calendar-day-number{color:var(--gp-primary-dark)}.calendar-event{display:block;width:100%;margin:3px 0;padding:4px 6px;border:0;border-radius:4px;background:var(--gp-primary);color:#fff;font-size:10px;line-height:1.25;text-align:left;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer}.calendar-event:hover{background:var(--gp-primary)}.calendar-empty{grid-column:1/-1;padding:40px;text-align:center;color:var(--gp-muted)}@media(max-width:768px){.calendar-day{min-height:86px;padding:4px}.calendar-event{font-size:10px;padding:3px}.calendar-day-number{font-size:10px}.calendar-toolbar{flex-wrap:wrap}.calendar-title{order:-1;flex-basis:100%;text-align:left}}
.event-agenda-pane{display:none}.event-agenda-pane.active{display:block}.agenda-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px;min-height:44px;padding:8px 0;border-top:1px solid var(--gp-border);border-bottom:1px solid var(--gp-border)}.agenda-toolbar .crud-actions{display:flex;align-items:center;gap:8px;border:0;padding:0;min-height:0}.agenda-toolbar .crud-search{width:min(100%,300px)}.agenda-scroll{max-height:calc(100vh - 285px);overflow:auto;border:1px solid var(--gp-border);border-radius:8px;background:#fff}.agenda-table{width:100%;margin:0;font-size:11px}.agenda-table th{position:sticky;top:0;z-index:2;padding:8px;background:var(--gp-primary-soft);color:var(--gp-muted);border-bottom:1px solid var(--gp-border)}.agenda-table td{padding:8px;border-color:var(--gp-border)}.agenda-table .event-row{cursor:pointer}.agenda-table .event-row:hover{background:#eef6ff}.agenda-table .event-row.selected td{background:var(--gp-primary-soft)}.calendar-scroll{width:100%;max-height:calc(100vh - 285px);overflow:auto;border:1px solid var(--gp-border);border-radius:8px;background:#fff}.event-calendar{display:grid;grid-template-columns:repeat(7,minmax(126px,1fr));min-width:900px;min-height:650px;border:0;border-radius:0;overflow:visible;background:#fff}
 </style>
<style>
.event-pane[data-event-pane="form"] .card{background:var(--gp-page)!important;border:1px solid var(--gp-border)!important;border-radius:8px!important;box-shadow:none!important}
.event-pane[data-event-pane="form"] .card-body{padding:14px}
.event-form-toolbar{display:flex;align-items:center;gap:8px;margin-bottom:10px;padding:8px 10px;border:1px solid var(--gp-border);border-radius:8px;background:var(--gp-page)}
.event-form-toolbar button,.agenda-toolbar .crud-actions>button,.calendar-toolbar .crud-actions>button{width:30px;min-width:30px;height:30px;padding:0;display:inline-flex;align-items:center;justify-content:center;font-size:0}
.event-form-toolbar button i,.agenda-toolbar .crud-actions>button i,.calendar-toolbar .crud-actions>button i{margin:0!important;font-size:13px;line-height:1}
.event-form-toolbar button:hover,.event-form-toolbar button:focus,.agenda-toolbar .crud-actions>button:hover,.agenda-toolbar .crud-actions>button:focus,.calendar-toolbar .crud-actions>button:hover,.calendar-toolbar .crud-actions>button:focus{transform:translateY(-1px);box-shadow:0 2px 5px rgba(31,105,84,.12)}
.agenda-toolbar,.calendar-toolbar,.event-form-toolbar{box-sizing:border-box;height:48px;min-height:48px;padding:8px 10px}
.event-pane[data-event-pane="form"] .card-body{padding:0}
.event-pane[data-event-pane="form"] #eventForm{padding:0 14px 14px}
.event-pane[data-event-pane="form"] .form-label{display:block;margin-bottom:2px;color:var(--gp-muted);font-size:10px;font-weight:700}
.event-pane[data-event-pane="form"] .form-control,.event-pane[data-event-pane="form"] .form-select{min-height:30px;height:30px;padding:4px 7px;border-color:var(--gp-border);border-radius:4px;font-size:11px;line-height:1.2}
.event-pane[data-event-pane="form"] textarea.form-control{height:auto;min-height:64px}
.event-pane[data-event-pane="form"] .form-text{margin-top:2px;color:var(--gp-muted);font-size:10px}
.event-pane[data-event-pane="form"] select[name="codusuario_envolvidos[]"]{height:86px;min-height:86px;padding:4px 7px;font-size:11px;line-height:1.2;background:#fff}
.event-date-field{position:relative;display:flex;align-items:center}
.event-date-field .event-date-input{padding-right:34px!important}
.event-calendar-picker{position:absolute;top:50%;right:4px;width:24px;height:24px;padding:0;border:0;border-radius:4px;color:var(--gp-muted);background:transparent;transform:translateY(-50%);font-size:13px;line-height:1}
.event-calendar-picker:hover,.event-calendar-picker:focus{color:var(--gp-primary-dark);background:var(--gp-primary-soft);outline:0}
.event-native-date-picker{position:absolute!important;right:5px;bottom:1px;width:1px!important;height:1px!important;padding:0!important;border:0!important;opacity:0;pointer-events:none}
</style>
<?php require_once dirname(__DIR__) . '/components/pagina_padrao.php'; greenPortalEstiloPagina(); ?></head>
<body class="p-3">
<main class="container-fluid">
<?php if($auditMode && $audit): ?><div class="d-flex align-items-center gap-2 mb-2 small text-muted"><i class="bi bi-clipboard-check"></i><span><strong><?= $h('auditoria.titulo', ['pt' => 'Cronograma da auditoria', 'en' => 'Audit schedule', 'es' => 'Cronograma de la auditoría']) ?>:</strong> <?= $e($audit['RAZAOSOCIAL']) ?> · <?= $e($audit['DSCTIPO'] ?? '') ?><?php if (($audit['NECESSITA_AUDITORIA'] ?? '') !== 'Sim'): ?> · <span class="text-danger"><?= $h('auditoria.nao_exige', ['pt' => 'este tipo não exige auditoria (só consulta e exclusão)', 'en' => 'this type does not require an audit (view and delete only)', 'es' => 'este tipo no exige auditoría (solo consulta y eliminación)']) ?></span><?php endif; ?></span></div><?php endif; ?>
<?php if($message): ?><div class="alert alert-success py-2"><?= $e($message) ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-danger py-2"><?= $e($error) ?></div><?php endif; ?>
<ul class="nav nav-tabs event-tabs mb-3" role="tablist">
  <li class="nav-item" role="presentation"><button class="nav-link<?= $initialTab === 'agenda' ? ' active' : '' ?>" type="button" data-event-tab="agenda"><i class="bi bi-list-ul"></i><?= $h('aba.agenda', ['pt' => 'Agenda', 'en' => 'Agenda', 'es' => 'Agenda']) ?></button></li>
  <li class="nav-item" role="presentation"><button class="nav-link<?= $initialTab === 'calendar' ? ' active' : '' ?>" type="button" data-event-tab="calendar"><i class="bi bi-calendar3"></i><?= $h('aba.calendario', ['pt' => 'Calendário', 'en' => 'Calendar', 'es' => 'Calendario']) ?></button></li>
  <?php if(!empty($permission['pode_incluir']) || !empty($permission['pode_editar'])): ?><li class="nav-item" role="presentation"><button class="nav-link<?= $initialTab === 'form' ? ' active' : '' ?>" type="button" data-event-tab="form"><i class="bi bi-pencil-square"></i><span data-form-tab-label><?= $h('novo', ['pt' => 'Novo evento', 'en' => 'New event', 'es' => 'Nuevo evento']) ?></span></button></li><?php endif; ?>
</ul>
<section class="event-pane event-agenda-pane<?= $initialTab === 'agenda' ? ' active' : '' ?>" data-event-pane="agenda">
  <div class="agenda-toolbar"><div class="crud-actions"><?php if(!empty($permission['pode_incluir'])):?><button class="btn btn-primary grid-icon-button" type="button" id="newAgendaEvent" title="<?= $h('novo', ['pt' => 'Novo evento', 'en' => 'New event', 'es' => 'Nuevo evento']) ?>" aria-label="<?= $h('novo', ['pt' => 'Novo evento', 'en' => 'New event', 'es' => 'Nuevo evento']) ?>"><i class="bi bi-plus-lg"></i></button><?php endif;?><button class="btn btn-outline-secondary grid-icon-button" type="button" id="reloadAgenda" title="<?= $h('recarregar', ['pt' => 'Recarregar', 'en' => 'Reload', 'es' => 'Recargar']) ?>" aria-label="<?= $h('recarregar', ['pt' => 'Recarregar', 'en' => 'Reload', 'es' => 'Recargar']) ?>"><i class="bi bi-arrow-clockwise"></i></button></div><div class="crud-search"><i class="bi bi-search"></i><input type="search" id="agendaSearch" placeholder="<?= $h('buscar_agenda', ['pt' => 'Buscar na agenda', 'en' => 'Search the agenda', 'es' => 'Buscar en la agenda']) ?>" aria-label="<?= $h('buscar_agenda', ['pt' => 'Buscar na agenda', 'en' => 'Search the agenda', 'es' => 'Buscar en la agenda']) ?>"></div></div>
  <div class="agenda-scroll"><table class="table table-sm table-hover align-middle agenda-table"><thead><tr><th><?= $h('coluna.inicio', ['pt' => 'Data/hora inicial', 'en' => 'Start date/time', 'es' => 'Fecha/hora inicial']) ?></th><th><?= $h('coluna.fim', ['pt' => 'Data/hora final', 'en' => 'End date/time', 'es' => 'Fecha/hora final']) ?></th><th><?= $h('coluna.descricao', ['pt' => 'Descrição', 'en' => 'Description', 'es' => 'Descripción']) ?></th><th><?= $h('coluna.localizacao', ['pt' => 'Localização', 'en' => 'Location', 'es' => 'Ubicación']) ?></th><th><?= $h('coluna.participantes', ['pt' => 'Participantes', 'en' => 'Participants', 'es' => 'Participantes']) ?></th></tr></thead><tbody>
  <?php foreach($events as $event): ?><tr class="event-row" data-event-row data-event='<?= $e(json_encode($event, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'><td><?= $e(date($dateDisplayFormat,strtotime((string)$event['datainicial']))) ?></td><td><?= $e(date($dateDisplayFormat,strtotime((string)$event['datafim']))) ?></td><td><?= $e($event['dscevento']) ?></td><td><?= $e($event['dsclocalisacao']) ?></td><td><?= $e($participantNames((string)($event['codusuario_envolvidos'] ?? ''))) ?></td></tr><?php endforeach; ?>
  <?php if(!$events): ?><tr><td colspan="5" class="text-center text-muted py-4"><?= $auditMode ? $h('msg.vazio_auditoria', ['pt' => 'Nenhum evento no cronograma desta auditoria.', 'en' => 'No events in this audit schedule.', 'es' => 'No hay eventos en el cronograma de esta auditoría.']) : $h('msg.vazio', ['pt' => 'Nenhum evento cadastrado para este monitoramento.', 'en' => 'No events registered for this monitoring.', 'es' => 'No hay eventos registrados para este monitoreo.']) ?></td></tr><?php endif; ?></tbody></table></div>
</section>
<section class="event-pane event-calendar-pane<?= $initialTab === 'calendar' ? ' active' : '' ?>" data-event-pane="calendar">
  <div class="calendar-toolbar">
    <div class="crud-actions">
      <?php if(!empty($permission['pode_incluir'])):?><button class="btn btn-primary grid-icon-button" type="button" id="newEvent" title="<?= $h('novo', ['pt' => 'Novo evento', 'en' => 'New event', 'es' => 'Nuevo evento']) ?>" aria-label="<?= $h('novo', ['pt' => 'Novo evento', 'en' => 'New event', 'es' => 'Nuevo evento']) ?>"><i class="bi bi-plus-lg"></i></button><?php endif;?>
      <button class="btn btn-outline-secondary grid-icon-button" type="button" id="calendarToday" title="<?= $h('hoje', ['pt' => 'Hoje', 'en' => 'Today', 'es' => 'Hoy']) ?>" aria-label="<?= $h('hoje', ['pt' => 'Hoje', 'en' => 'Today', 'es' => 'Hoy']) ?>"><i class="bi bi-calendar-day"></i></button>
      <button class="btn btn-outline-secondary grid-icon-button" type="button" id="calendarPrevious" title="<?= $h('mes_anterior', ['pt' => 'Mês anterior', 'en' => 'Previous month', 'es' => 'Mes anterior']) ?>" aria-label="<?= $h('mes_anterior', ['pt' => 'Mês anterior', 'en' => 'Previous month', 'es' => 'Mes anterior']) ?>"><i class="bi bi-chevron-left"></i></button>
      <button class="btn btn-outline-secondary grid-icon-button" type="button" id="calendarNext" title="<?= $h('mes_proximo', ['pt' => 'Próximo mês', 'en' => 'Next month', 'es' => 'Mes siguiente']) ?>" aria-label="<?= $h('mes_proximo', ['pt' => 'Próximo mês', 'en' => 'Next month', 'es' => 'Mes siguiente']) ?>"><i class="bi bi-chevron-right"></i></button>
    </div>
    <h5 class="calendar-title mb-0" id="calendarTitle"></h5>
  </div>
  <div class="calendar-scroll"><div class="event-calendar" id="eventCalendar" aria-label="<?= $h('calendario', ['pt' => 'Calendário de eventos', 'en' => 'Event calendar', 'es' => 'Calendario de eventos']) ?>"></div></div>
</section>
<?php if(!empty($permission['pode_incluir']) || !empty($permission['pode_editar'])): ?><section class="event-pane<?= $initialTab === 'form' ? ' active' : '' ?>" data-event-pane="form"><div class="card shadow-sm border-0"><div class="card-body"><form method="post"><input type="hidden" name="csrf" value="<?=$e($csrf)?>"><input type="hidden" name="event_action" id="eventAction" value="save"><input type="hidden" name="codevento" value="0"><input type="hidden" name="codmonitoramento" value="<?=$e($monitoringId)?>"><?php if($auditMode): ?><input type="hidden" name="codauditoria" value="<?=$e($auditId)?>"><?php endif; ?><input type="hidden" name="codunidade" value="<?=$e($unit)?>"><div class="row g-2"><div class="col-md-6"><label class="form-label"><?= $h('campo.descricao', ['pt' => 'Descrição *', 'en' => 'Description *', 'es' => 'Descripción *']) ?></label><input class="form-control" name="dscevento" required maxlength="1000"></div><div class="col-md-6"><label class="form-label"><?= $h('coluna.localizacao', ['pt' => 'Localização', 'en' => 'Location', 'es' => 'Ubicación']) ?></label><input class="form-control" name="dsclocalisacao" maxlength="200"></div><div class="col-md-3"><label class="form-label"><?= $h('campo.inicio', ['pt' => 'Início *', 'en' => 'Start *', 'es' => 'Inicio *']) ?></label><input class="form-control" type="datetime-local" name="datainicial" required></div><div class="col-md-3"><label class="form-label"><?= $h('campo.fim', ['pt' => 'Fim *', 'en' => 'End *', 'es' => 'Fin *']) ?></label><input class="form-control" type="datetime-local" name="datafim" required></div><div class="col-md-3" data-ev-repetir><label class="form-label"><?= $h('campo.repetir', ['pt' => 'Repetir (recorrência)', 'en' => 'Repeat (recurrence)', 'es' => 'Repetir (recurrencia)']) ?></label><select class="form-select" name="repetir_meses" title="<?= $h('campo.repetir_dica', ['pt' => 'Item 67: cria de uma vez as próximas medições do cronograma, de Início até "Repetir até", a cada período escolhido. Cada uma vira pendência do responsável.', 'en' => 'Creates the next measurements from Start to "Repeat until", every chosen period.', 'es' => 'Crea las próximas mediciones desde Inicio hasta "Repetir hasta", en cada período.']) ?>"><option value="0"><?= $h('repetir.nao', ['pt' => 'Não repetir', 'en' => 'Do not repeat', 'es' => 'No repetir']) ?></option><option value="1"><?= $h('repetir.1', ['pt' => 'Mensal', 'en' => 'Monthly', 'es' => 'Mensual']) ?></option><option value="2"><?= $h('repetir.2', ['pt' => 'Bimestral', 'en' => 'Every 2 months', 'es' => 'Bimestral']) ?></option><option value="3"><?= $h('repetir.3', ['pt' => 'Trimestral', 'en' => 'Quarterly', 'es' => 'Trimestral']) ?></option><option value="6"><?= $h('repetir.6', ['pt' => 'Semestral', 'en' => 'Every 6 months', 'es' => 'Semestral']) ?></option><option value="12"><?= $h('repetir.12', ['pt' => 'Anual', 'en' => 'Yearly', 'es' => 'Anual']) ?></option></select></div><div class="col-md-3" data-ev-repetir><label class="form-label"><?= $h('campo.repetir_ate', ['pt' => 'Repetir até', 'en' => 'Repeat until', 'es' => 'Repetir hasta']) ?></label><input class="form-control" type="date" name="repetir_ate"></div><div class="col-md-3"><label class="form-label"><?= $h('campo.lembrete', ['pt' => 'Lembrete', 'en' => 'Reminder', 'es' => 'Recordatorio']) ?></label><select class="form-select" name="lembrar"><option value="15"><?= $h('lembrete.15', ['pt' => '15 minutos antes', 'en' => '15 minutes before', 'es' => '15 minutos antes']) ?></option><option value="30"><?= $h('lembrete.30', ['pt' => '30 minutos antes', 'en' => '30 minutes before', 'es' => '30 minutos antes']) ?></option><option value="60"><?= $h('lembrete.60', ['pt' => '1 hora antes', 'en' => '1 hour before', 'es' => '1 hora antes']) ?></option><option value="1440"><?= $h('lembrete.1440', ['pt' => '1 dia antes', 'en' => '1 day before', 'es' => '1 día antes']) ?></option></select></div><div class="col-md-3"><label class="form-label"><?= $h('campo.email', ['pt' => 'Enviar e-mail', 'en' => 'Send e-mail', 'es' => 'Enviar correo']) ?></label><select class="form-select" name="enviaremail"><option value="S"><?= $h('sim', ['pt' => 'Sim', 'en' => 'Yes', 'es' => 'Sí']) ?></option><option value="N"><?= $h('nao', ['pt' => 'Não', 'en' => 'No', 'es' => 'No']) ?></option></select></div><div class="col-12"><label class="form-label"><?= $h('campo.envolvidos', ['pt' => 'Envolvidos', 'en' => 'People involved', 'es' => 'Involucrados']) ?></label><select class="form-select" name="codusuario_envolvidos[]" multiple><?php foreach($users as $user):?><option value="<?=$e($user['codusuario'])?>"><?=$e($user['nome'])?></option><?php endforeach;?></select><div class="form-text"><?= $h('dica.envolvidos', ['pt' => 'Use Ctrl ou Command para selecionar mais de uma pessoa.', 'en' => 'Use Ctrl or Command to select more than one person.', 'es' => 'Use Ctrl o Command para seleccionar más de una persona.']) ?></div></div><?php if($auditMode): ?><div class="col-12"><label class="form-label"><?= $h('campo.facilidades', ['pt' => 'Facilidades necessárias', 'en' => 'Required facilities', 'es' => 'Facilidades necesarias']) ?></label><textarea class="form-control" name="auditoria_facilidades_necessarias" rows="3" maxlength="4000"></textarea></div><div class="col-md-6"><label class="form-label"><?= $h('campo.auditores', ['pt' => 'Auditores', 'en' => 'Auditors', 'es' => 'Auditores']) ?></label><select class="form-select" name="codusuarios_auditores[]" multiple style="height:86px"><?php foreach($users as $user):?><option value="<?=$e($user['codusuario'])?>"><?=$e($user['nome'])?></option><?php endforeach;?></select></div><?php if($auditChecklists): ?><div class="col-md-6"><label class="form-label"><?= $h('campo.checklist', ['pt' => 'Check list', 'en' => 'Checklist', 'es' => 'Lista de verificación']) ?></label><select class="form-select" name="codchecklists[]" multiple style="height:86px"><?php foreach($auditChecklists as $ck):?><option value="<?=$e($ck['CODCHECKLIST'])?>" title="<?=$e($ck['REQUISITO'])?>"><?=$e($ck['REQUISITO'])?></option><?php endforeach;?></select></div><?php endif; ?><?php endif; ?></div><div class="mt-3 d-flex gap-2"><button class="btn btn-success" type="submit" title="<?= $h('salvar_evento', ['pt' => 'Salvar evento', 'en' => 'Save event', 'es' => 'Guardar evento']) ?>"><i class="bi bi-save me-1"></i><?= $h('salvar', ['pt' => 'Salvar', 'en' => 'Save', 'es' => 'Guardar']) ?></button><button class="btn btn-outline-danger" type="button" id="deleteEvent"<?= empty($permission['pode_excluir']) ? ' hidden' : '' ?>><i class="bi bi-trash me-1"></i><?= $h('excluir', ['pt' => 'Excluir', 'en' => 'Delete', 'es' => 'Eliminar']) ?></button><button class="btn btn-outline-secondary" type="button" id="cancelEvent"><?= $h('cancelar', ['pt' => 'Cancelar', 'en' => 'Cancel', 'es' => 'Cancelar']) ?></button></div></form></div></div></section><?php endif; ?>
</main>
<script>
(function(){
  var form=document.querySelector('[data-event-pane="form"] form');
  if(!form)return;
  form.id='eventForm';
  var actions=form.querySelector('.mt-3.d-flex');
  if(!actions)return;
  actions.classList.remove('mt-3');
  actions.classList.add('event-form-toolbar');
  form.parentNode.insertBefore(actions,form);
  var submit=actions.querySelector('button[type="submit"]');
  if(submit)submit.setAttribute('form',form.id);
  var cancel=actions.querySelector('#cancelEvent');
  if(cancel){cancel.title=<?= $j('cancelar', ['pt' => 'Cancelar', 'en' => 'Cancel', 'es' => 'Cancelar']) ?>;cancel.setAttribute('aria-label',<?= $j('cancelar', ['pt' => 'Cancelar', 'en' => 'Cancel', 'es' => 'Cancelar']) ?>);cancel.innerHTML='<i class="bi bi-x-lg"></i>';}
}());
</script>
<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script><script src="../assets/js/grid-sort.js?v=3"></script>
<script>
(function(){
  var grid=document.querySelector('[data-event-pane="list"] table');
  var toolbar=document.querySelector('[data-event-pane="list"] > .d-flex');
  if(grid&&toolbar){
    grid.classList.add('menu-batch-grid');
    var scroll=grid.closest('.eventos-scroll');if(scroll)scroll.classList.add('menu-grid-scroll');
    toolbar.className='crud-toolbar menu-grid-toolbar crud-actions';
    var newButton=document.getElementById('newEvent');
    toolbar.style.display='flex';toolbar.style.alignItems='center';toolbar.style.gap='8px';toolbar.style.marginBottom='8px';toolbar.style.border='0';toolbar.style.padding='0';
    toolbar.querySelectorAll('button').forEach(function(button){button.style.width='30px';button.style.minWidth='30px';button.style.height='30px';button.style.padding='0';button.style.fontSize='0';button.style.display='inline-flex';button.style.alignItems='center';button.style.justifyContent='center';});
    if(newButton)newButton.classList.add('grid-icon-button');
    var reload=document.createElement('button');reload.type='button';reload.className='btn btn-sm btn-outline-secondary grid-icon-button';reload.title=<?= $j('recarregar', ['pt' => 'Recarregar', 'en' => 'Reload', 'es' => 'Recargar']) ?>;reload.setAttribute('aria-label',<?= $j('recarregar', ['pt' => 'Recarregar', 'en' => 'Reload', 'es' => 'Recargar']) ?>);reload.innerHTML='<i class="bi bi-arrow-clockwise"></i>';reload.addEventListener('click',function(){window.location.reload();});toolbar.appendChild(reload);
    var search=document.createElement('div');search.className='crud-search';search.innerHTML='<i class="bi bi-search"></i><input type="search" data-event-search placeholder="<?= $h('buscar_grid', ['pt' => 'Buscar no grid', 'en' => 'Search the grid', 'es' => 'Buscar en la tabla']) ?>" aria-label="<?= $h('buscar_grid', ['pt' => 'Buscar no grid', 'en' => 'Search the grid', 'es' => 'Buscar en la tabla']) ?>">';toolbar.appendChild(search);
    Array.from(grid.querySelectorAll('thead th')).forEach(function(th){if(th.querySelector('.column-heading'))return;var label=th.textContent.trim(),column=th.dataset.column||(th.classList.contains('text-end')?'ACOES':label);th.dataset.column=column;var heading=document.createElement('div');heading.className='column-heading';var span=document.createElement('span');span.textContent=label;heading.appendChild(span);if(column!=='ACOES'){var input=document.createElement('input');input.type='search';input.className='column-filter';input.placeholder=<?= $j('buscar', ['pt' => 'Buscar', 'en' => 'Search', 'es' => 'Buscar']) ?>;input.setAttribute('aria-label',<?= $j('buscar', ['pt' => 'Buscar', 'en' => 'Search', 'es' => 'Buscar']) ?>+' '+label);input.dataset.eventFilter=column;heading.appendChild(input);}th.textContent='';th.appendChild(heading);});
    var pagination=document.createElement('nav');pagination.className='grid-pagination';pagination.setAttribute('aria-label',<?= $j('paginacao', ['pt' => 'Paginação do grid', 'en' => 'Grid pagination', 'es' => 'Paginación de la tabla']) ?>);pagination.innerHTML='<button type="button" class="btn btn-sm btn-outline-secondary" data-event-prev title="<?= $h('anterior', ['pt' => 'Anterior', 'en' => 'Previous', 'es' => 'Anterior']) ?>" aria-label="<?= $h('anterior', ['pt' => 'Anterior', 'en' => 'Previous', 'es' => 'Anterior']) ?>"><i class="bi bi-chevron-left"></i></button><span class="grid-page-status" data-event-page-status></span><button type="button" class="btn btn-sm btn-outline-secondary" data-event-next title="<?= $h('proxima', ['pt' => 'Próxima', 'en' => 'Next', 'es' => 'Siguiente']) ?>" aria-label="<?= $h('proxima', ['pt' => 'Próxima', 'en' => 'Next', 'es' => 'Siguiente']) ?>"><i class="bi bi-chevron-right"></i></button>';toolbar.insertBefore(pagination,search);
    var columns=Array.from(grid.querySelectorAll('thead th')).map(function(th){return th.dataset.column||'';});
    grid.querySelectorAll('tbody tr.event-row').forEach(function(row){Array.from(row.children).forEach(function(cell,index){if(columns[index])cell.dataset.column=columns[index];});});
    var rows=Array.from(grid.querySelectorAll('tbody tr.event-row')),page=1,pageSize=25;
    function renderEvents(){var text=String(search.querySelector('input').value||'').toLowerCase(),filters={};grid.querySelectorAll('[data-event-filter]').forEach(function(input){filters[input.dataset.eventFilter]=String(input.value||'').toLowerCase();});var visible=rows.filter(function(row){var all=String(row.innerText||'').toLowerCase();if(text&&all.indexOf(text)<0)return false;return Object.keys(filters).every(function(key){if(!filters[key])return true;var cell=row.querySelector('[data-column="'+key+'"]');return cell&&String(cell.innerText||'').toLowerCase().indexOf(filters[key])>=0;});});var total=visible.length,pages=Math.max(1,Math.ceil(total/pageSize));page=Math.min(page,pages);rows.forEach(function(row){row.style.display='none';});visible.slice((page-1)*pageSize,page*pageSize).forEach(function(row){row.style.display='table-row';});pagination.querySelector('[data-event-page-status]').textContent=<?= $j('registros_pagina', ['pt' => '{total} registros · Página {pagina}', 'en' => '{total} records · Page {pagina}', 'es' => '{total} registros · Página {pagina}']) ?>.replace('{total}',total).replace('{pagina}',page);pagination.querySelector('[data-event-prev]').disabled=page<=1;pagination.querySelector('[data-event-next]').disabled=page>=pages;}
    search.querySelector('input').addEventListener('input',function(){page=1;renderEvents();});grid.querySelectorAll('[data-event-filter]').forEach(function(input){input.addEventListener('input',function(){page=1;renderEvents();});});pagination.querySelector('[data-event-prev]').addEventListener('click',function(){if(page>1){page--;renderEvents();}});pagination.querySelector('[data-event-next]').addEventListener('click',function(){page++;renderEvents();});renderEvents();
  }
  var formPane=document.querySelector('[data-event-pane="form"]'),listPane=document.querySelector('[data-event-pane="list"]'),form=formPane?formPane.querySelector('form'):null;
  var eventLanguage=<?= json_encode($language, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var eventDatePlaceholder=eventLanguage==='en'?'mm/dd/yyyy hh:mm':'dd/mm/yyyy hh:mm';
  var eventCalendarLabel=eventLanguage==='en'?'Open calendar':(eventLanguage==='es'?'Abrir calendario':'Abrir calendário');
  function eventDateParts(value){
    var text=String(value||'').trim().replace(' ','T'),match=text.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
    return match?{year:match[1],month:match[2],day:match[3],hour:match[4],minute:match[5]}:null;
  }
  function eventFormatDateTime(value){
    var parts=eventDateParts(value);if(!parts)return '';
    return eventLanguage==='en'?parts.month+'/'+parts.day+'/'+parts.year+' '+parts.hour+':'+parts.minute:parts.day+'/'+parts.month+'/'+parts.year+' '+parts.hour+':'+parts.minute;
  }
  function eventToNativeDateTime(value){
    var text=String(value||'').trim(),iso=eventDateParts(text);if(iso)return iso.year+'-'+iso.month+'-'+iso.day+'T'+iso.hour+':'+iso.minute;
    var match=text.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})[ ]+(\d{1,2}):(\d{2})$/);
    if(!match)return '';
    var month=eventLanguage==='en'?match[1]:match[2],day=eventLanguage==='en'?match[2]:match[1];
    return match[3]+'-'+String(month).padStart(2,'0')+'-'+String(day).padStart(2,'0')+'T'+String(match[4]).padStart(2,'0')+':'+match[5];
  }
  function eventEnhanceDateInput(input){
    if(!input||input.dataset.eventDateEnhanced)return;
    input.dataset.eventDateEnhanced='1';
    var original=input.value, shell=document.createElement('div'), button=document.createElement('button'), native=document.createElement('input');
    shell.className='event-date-field';
    input.type='text';input.classList.add('event-date-input');input.placeholder=eventDatePlaceholder;input.inputMode='numeric';input.autocomplete='off';
    button.type='button';button.className='event-calendar-picker';button.title=eventCalendarLabel;button.setAttribute('aria-label',eventCalendarLabel);button.innerHTML='<i class="bi bi-calendar3"></i>';
    native.type='datetime-local';native.className='event-native-date-picker';native.tabIndex=-1;native.setAttribute('aria-hidden','true');native.value=eventToNativeDateTime(original);
    input.value=eventFormatDateTime(native.value);
    input.parentNode.insertBefore(shell,input);shell.appendChild(input);shell.appendChild(button);shell.appendChild(native);
    button.addEventListener('click',function(){try{if(typeof native.showPicker==='function'){native.showPicker();return;}}catch(error){}native.focus();native.click();});
    native.addEventListener('change',function(){input.value=eventFormatDateTime(native.value);});
    input.addEventListener('blur',function(){var value=eventToNativeDateTime(input.value);if(value)native.value=value;});
  }
  if(form){form.querySelectorAll('input[name="datainicial"],input[name="datafim"]').forEach(eventEnhanceDateInput);}
  function eventSetDateInput(name,value){var input=form&&form.querySelector('[name="'+name+'"]');if(!input)return;var native=input.parentNode?input.parentNode.querySelector('.event-native-date-picker'):null;var iso=eventToNativeDateTime(value);input.value=eventFormatDateTime(iso);if(native)native.value=iso;}
  window.eventSetDateInput=eventSetDateInput;
  function tab(name){document.querySelectorAll('[data-event-tab]').forEach(function(b){b.classList.toggle('active',b.dataset.eventTab===name);});document.querySelectorAll('[data-event-pane]').forEach(function(p){p.classList.toggle('active',p.dataset.eventPane===name);});}
  function clearForm(){if(!form)return;form.reset();form.querySelectorAll('[data-ev-repetir]').forEach(function(x){x.style.display='';});form.querySelector('[name="codevento"]').value='0';eventSetDateInput('datainicial','');eventSetDateInput('datafim','');var multi=form.querySelector('[name="codusuario_envolvidos[]"]');if(multi)Array.from(multi.options).forEach(function(o){o.selected=false;});['codusuarios_auditores[]','codchecklists[]'].forEach(function(n){var m=form.querySelector('[name="'+n+'"]');if(m)Array.from(m.options).forEach(function(o){o.selected=false;});});var label=document.querySelector('[data-form-tab-label]');if(label)label.textContent=<?= $j('novo', ['pt' => 'Novo evento', 'en' => 'New event', 'es' => 'Nuevo evento']) ?>;}
  function editEvent(data){if(!form)return;form.querySelectorAll('[data-ev-repetir]').forEach(function(x){x.style.display='none';});form.querySelector('[name="codevento"]').value=data.codevento||0;form.querySelector('[name="dscevento"]').value=data.dscevento||'';form.querySelector('[name="dsclocalisacao"]').value=data.dsclocalisacao||'';eventSetDateInput('datainicial',data.datainicial||'');eventSetDateInput('datafim',data.datafim||'');form.querySelector('[name="lembrar"]').value=data.lembrar||15;form.querySelector('[name="enviaremail"]').value=data.enviaremail||'N';var selected=String(data.codusuario_envolvidos||'').split(',');var multi=form.querySelector('[name="codusuario_envolvidos[]"]');if(multi)Array.from(multi.options).forEach(function(o){o.selected=selected.indexOf(o.value)>=0;});var fac=form.querySelector('[name="auditoria_facilidades_necessarias"]');if(fac)fac.value=data.auditoria_facilidades_necessarias||'';[['codusuarios_auditores[]','codusuarios_auditores'],['codchecklists[]','codchecklists']].forEach(function(p){var m=form.querySelector('[name="'+p[0]+'"]');if(!m)return;var sel=String(data[p[1]]||'').split(',');Array.from(m.options).forEach(function(o){o.selected=sel.indexOf(o.value)>=0;});});var action=form.querySelector('#eventAction');if(action)action.value='save';var remove=document.getElementById('deleteEvent');if(remove)remove.hidden=false;var label=document.querySelector('[data-form-tab-label]');if(label)label.textContent=<?= $j('editar', ['pt' => 'Editar evento', 'en' => 'Edit event', 'es' => 'Editar evento']) ?>+' ('+(data.dscevento||<?= $j('sem_titulo', ['pt' => 'Sem título', 'en' => 'Untitled', 'es' => 'Sin título']) ?>)+')';tab('form');}
  window.editEvent=editEvent;
  document.querySelectorAll('[data-event-tab]').forEach(function(button){button.addEventListener('click',function(){tab(button.dataset.eventTab);});});
  document.querySelectorAll('[data-event-row]').forEach(function(row){row.addEventListener('click',function(event){if(event.target.closest('button,form,a'))return;editEvent(JSON.parse(row.dataset.event));});});
  document.getElementById('newEvent')?.addEventListener('click',function(){clearForm();var action=form&&form.querySelector('#eventAction');if(action)action.value='save';var remove=document.getElementById('deleteEvent');if(remove)remove.hidden=true;tab('form');});
  document.getElementById('newAgendaEvent')?.addEventListener('click',function(){document.getElementById('newEvent')?.click();});
  document.getElementById('reloadAgenda')?.addEventListener('click',function(){window.location.reload();});
  document.getElementById('agendaSearch')?.addEventListener('input',function(){var value=this.value.toLowerCase();document.querySelectorAll('.agenda-table tbody tr.event-row').forEach(function(row){row.style.display=String(row.innerText||'').toLowerCase().indexOf(value)>=0?'':'none';});});
  document.getElementById('deleteEvent')?.addEventListener('click',function(){if(!form||!form.querySelector('[name="codevento"]').value||form.querySelector('[name="codevento"]').value==='0')return;if(!window.confirm(<?= $j('confirmar_exclusao', ['pt' => 'Confirma a exclusão do evento?', 'en' => 'Delete this event?', 'es' => '¿Confirma la eliminación del evento?']) ?>))return;form.querySelector('#eventAction').value='delete';form.submit();});
  document.getElementById('cancelEvent')?.addEventListener('click',function(){tab('agenda');});
}());
</script>
<script>
(function(){
  var calendar=document.getElementById('eventCalendar');if(!calendar)return;
  var events=<?= json_encode($calendarEvents, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  var title=document.getElementById('calendarTitle'), cursor=new Date(), weekdays=(function(){var f=new Intl.DateTimeFormat(<?= json_encode($language === 'en' ? 'en-US' : ($language === 'es' ? 'es-ES' : 'pt-BR')) ?>,{weekday:'long'});return [0,1,2,3,4,5,6].map(function(d){var t=f.format(new Date(2024,0,7+d));return t.charAt(0).toUpperCase()+t.slice(1);});}());
  function dateKey(value){return String(value||'').replace(' ','T').slice(0,10);}
  function formatTime(value){var text=String(value||'').replace(' ','T');return text.length>=16?text.slice(11,16):'';}
  function render(){
    var year=cursor.getFullYear(),month=cursor.getMonth(),first=new Date(year,month,1),last=new Date(year,month+1,0),today=new Date(),todayKey=today.getFullYear()+'-'+String(today.getMonth()+1).padStart(2,'0')+'-'+String(today.getDate()).padStart(2,'0');
    title.textContent=new Intl.DateTimeFormat(<?= json_encode($language === 'en' ? 'en-US' : ($language === 'es' ? 'es-ES' : 'pt-BR')) ?>,{month:'long',year:'numeric'}).format(first);
    calendar.innerHTML='';weekdays.forEach(function(day){var header=document.createElement('div');header.className='calendar-weekday';header.textContent=day.slice(0,3);calendar.appendChild(header);});
    var start=first.getDay(),total=Math.ceil((start+last.getDate())/7)*7;
    for(var index=0;index<total;index++){var dayNumber=index-start+1,cellDate=new Date(year,month,dayNumber),key=cellDate.getFullYear()+'-'+String(cellDate.getMonth()+1).padStart(2,'0')+'-'+String(cellDate.getDate()).padStart(2,'0'),cell=document.createElement('div');const eventDayKey=key;cell.className='calendar-day'+(cellDate.getMonth()!==month?' is-other-month':'')+(key===todayKey?' is-today':'');var number=document.createElement('span');number.className='calendar-day-number';number.textContent=cellDate.getDate();cell.appendChild(number);cell.addEventListener('dblclick',function(event){if(event.target.closest('.calendar-event'))return;var create=document.getElementById('newEvent');if(!create)return;create.click();if(window.eventSetDateInput){window.eventSetDateInput('datainicial',eventDayKey+'T08:00');window.eventSetDateInput('datafim',eventDayKey+'T09:00');}});events.filter(function(event){return dateKey(event.datainicial)===key;}).forEach(function(event){var button=document.createElement('button');button.type='button';button.className='calendar-event';button.title=(formatTime(event.datainicial)+' '+event.dscevento).trim();button.textContent=(formatTime(event.datainicial)?formatTime(event.datainicial)+' ':'')+event.dscevento;button.addEventListener('click',function(){if(window.editEvent)window.editEvent(event);});cell.appendChild(button);});calendar.appendChild(cell);}
  }
  document.getElementById('calendarToday')?.addEventListener('click',function(){cursor=new Date();render();});
  document.getElementById('calendarPrevious')?.addEventListener('click',function(){cursor.setMonth(cursor.getMonth()-1);render();});
  document.getElementById('calendarNext')?.addEventListener('click',function(){cursor.setMonth(cursor.getMonth()+1);render();});
  render();
}());
</script>
</body></html>
