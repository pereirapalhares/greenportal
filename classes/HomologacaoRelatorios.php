<?php
declare(strict_types=1);

/**
 * Relatórios de Homologação (migração 314) — regras da tela principal/homologacaofornecedores_relatorios.php, da planilha
 * (classes/HomologacaoRelatoriosExportacao.php, gerada pelo GreenPortal-ExportWorker) e do envio semanal das visões salvas
 * (scripts/homologacao-relatorios-worker.php). Tela, planilha e alerta usam exatamente as mesmas regras.
 *
 * Dados: CALL sp_fo_relatorio_itens (itens de uma categoria do Painel + colunas do relatório do legado) e
 * CALL sp_fo_relatorio_unificado. As cores são as da regra única do Painel (vw_homologacao_painel_itens, migração 298).
 * Os filtros rápidos substituem os 12 nós da árvore de Estatísticas do legado (fo_estatisticas_c).
 */
final class HomologacaoRelatorios
{
    public const POR_PAGINA = 50;
    public const CORES = ['VERMELHO', 'AMARELO', 'VERDE', 'BRANCO', 'NEUTRO'];
    /** Pasta do GED legado e tipo da tela de arquivos (gestao_arquivos.php) de cada categoria; código = CODREGISTRO. */
    public const GED = [
        'DOCUMENTO' => ['fo_fornecedorxcriterios', 'fornecedorcriterios', 'CODFORNECEDORXCRITERIOS'],
        'AUDITORIA' => ['fo_fornecedorxauditoria', 'fornecedorauditoria', 'CODFORNECEDORXTIPOFORNECEDORES'],
        'INTEGRACAO' => ['fo_funcionarios', 'funcionarios', 'CODFUNCIONARIO'],
        'DOCFUNC' => ['fo_funcionarioxtiposxcriterios', 'funcionariocriterios', 'CODIGO'],
        'TREINAMENTO' => ['fo_funcionarioxtiposxtreinamentos', 'funcionariotreinamentos', 'CODIGO'],
        'EQUIPAMENTO' => ['fo_equipamentos', 'fornecedorequipamentos', 'CODEQUIPAMENTO'],
        'QUIMICO' => ['fo_produtoquimico', 'fornecedorquimicos', 'CODPRODUTO'],
        'PLANO' => ['fo_planodeacao', 'fornecedorplanos', 'CODPLANOACAO'],
    ];

    private string $idioma;

    public function __construct(string $idioma = 'pt')
    {
        $this->idioma = in_array($idioma, ['pt', 'en', 'es'], true) ? $idioma : 'pt';
    }

    public function L(array $p): string { return (string)($p[$this->idioma] ?? $p['pt'] ?? ''); }

    // ------------------------------------------------------------------ catálogo

    /** Rótulos das colunas (chave da linha => pt/en/es). */
    public static function rotulosColunas(): array
    {
        return [
            'unidade' => ['pt' => 'Unidade', 'en' => 'Unit', 'es' => 'Unidad'],
            'fornecedor' => ['pt' => 'Fornecedor', 'en' => 'Supplier', 'es' => 'Proveedor'],
            'cnpj' => ['pt' => 'CNPJ/CPF', 'en' => 'Tax ID', 'es' => 'CNPJ/CPF'],
            'tipo' => ['pt' => 'Tipo', 'en' => 'Type', 'es' => 'Tipo'],
            'documento' => ['pt' => 'Documento', 'en' => 'Document', 'es' => 'Documento'],
            'nivel' => ['pt' => 'Nível', 'en' => 'Level', 'es' => 'Nivel'],
            'enviado' => ['pt' => 'Enviado?', 'en' => 'Sent?', 'es' => '¿Enviado?'],
            'situacao' => ['pt' => 'Situação', 'en' => 'Status', 'es' => 'Situación'],
            'validade' => ['pt' => 'Validade', 'en' => 'Expiry', 'es' => 'Vencimiento'],
            'dias' => ['pt' => 'Dias', 'en' => 'Days', 'es' => 'Días'],
            'protocolo' => ['pt' => 'Em protocolo?', 'en' => 'Under protocol?', 'es' => '¿En protocolo?'],
            'contrato' => ['pt' => 'No contrato?', 'en' => 'In the contract?', 'es' => '¿En el contrato?'],
            'responsavel' => ['pt' => 'Validador / usuário do fornecedor', 'en' => 'Reviewer / supplier user', 'es' => 'Validador / usuario del proveedor'],
            'responsavel_plano' => ['pt' => 'Responsável', 'en' => 'Owner', 'es' => 'Responsable'],
            'observacao' => ['pt' => 'Observação', 'en' => 'Note', 'es' => 'Observación'],
            'nota' => ['pt' => 'Nota (0 a 100)', 'en' => 'Score (0 to 100)', 'es' => 'Nota (0 a 100)'],
            'aprovado' => ['pt' => 'Aprovado?', 'en' => 'Approved?', 'es' => '¿Aprobado?'],
            'funcionario' => ['pt' => 'Funcionário', 'en' => 'Employee', 'es' => 'Empleado'],
            'nrregistro' => ['pt' => 'Nº do registro', 'en' => 'Record no.', 'es' => 'Nº de registro'],
            'setor' => ['pt' => 'Setor', 'en' => 'Department', 'es' => 'Sector'],
            'datainicio' => ['pt' => 'Data de início', 'en' => 'Start date', 'es' => 'Fecha de inicio'],
            'tipofunc' => ['pt' => 'Tipo de funcionário', 'en' => 'Employee type', 'es' => 'Tipo de empleado'],
            'tipodoc' => ['pt' => 'Tipo de documento', 'en' => 'Document type', 'es' => 'Tipo de documento'],
            'atende' => ['pt' => 'Atende?', 'en' => 'Met?', 'es' => '¿Atiende?'],
            'realizado' => ['pt' => 'Realizado?', 'en' => 'Done?', 'es' => '¿Realizado?'],
            'treinamento' => ['pt' => 'Treinamento', 'en' => 'Training', 'es' => 'Capacitación'],
            'produto' => ['pt' => 'Produto', 'en' => 'Product', 'es' => 'Producto'],
            'cas' => ['pt' => 'Nº CAS', 'en' => 'CAS no.', 'es' => 'Nº CAS'],
            'caracteristica' => ['pt' => 'Característica', 'en' => 'Characteristic', 'es' => 'Característica'],
            'controlado' => ['pt' => 'Controlado?', 'en' => 'Controlled?', 'es' => '¿Controlado?'],
            'equipamento' => ['pt' => 'Equipamento', 'en' => 'Equipment', 'es' => 'Equipo'],
            'identificacao' => ['pt' => 'Identificação', 'en' => 'Identification', 'es' => 'Identificación'],
            'acao' => ['pt' => 'Ação', 'en' => 'Action', 'es' => 'Acción'],
            'inicio' => ['pt' => 'Início', 'en' => 'Start', 'es' => 'Inicio'],
            'prazo' => ['pt' => 'Prazo', 'en' => 'Deadline', 'es' => 'Plazo'],
            'recursos' => ['pt' => 'Recursos', 'en' => 'Resources', 'es' => 'Recursos'],
            'andamento' => ['pt' => 'Andamento', 'en' => 'Progress', 'es' => 'Avance'],
            'nivelservico' => ['pt' => 'Nível de serviço', 'en' => 'Service level', 'es' => 'Nivel de servicio'],
            'ultima' => ['pt' => 'Última avaliação', 'en' => 'Last evaluation', 'es' => 'Última evaluación'],
            'proxima' => ['pt' => 'Próxima avaliação', 'en' => 'Next evaluation', 'es' => 'Próxima evaluación'],
            'integracao' => ['pt' => 'Integração (validade)', 'en' => 'Induction (expiry)', 'es' => 'Integración (vencimiento)'],
            'evidencia' => ['pt' => 'Evidência', 'en' => 'Evidence', 'es' => 'Evidencia'],
            'arquivos' => ['pt' => 'Arquivos', 'en' => 'Files', 'es' => 'Archivos'],
            'fds' => ['pt' => 'FDS', 'en' => 'SDS', 'es' => 'FDS'],
            'anexo' => ['pt' => 'Anexo', 'en' => 'Attachment', 'es' => 'Anexo'],
        ];
    }

    /**
     * Relatórios: categoria do Painel, grupo do catálogo, textos, colunas [chave da linha, rótulo], filtros rápidos,
     * agrupamentos e rótulos da barra de distribuição.
     */
    public static function relatorios(): array
    {
        $T = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];
        $col = static fn(string $k, ?string $rotulo = null): array => [$k, $rotulo ?? $k];
        return [
            'docfor' => ['categoria' => 'DOCUMENTO', 'grupo' => 'fornecedores',
                'nome' => $T('Documentos do fornecedor', 'Supplier documents', 'Documentos del proveedor'),
                'sub' => $T('validade, situação, responsáveis', 'expiry, status, owners', 'vencimiento, situación, responsables'),
                'desc' => $T('Todos os documentos exigidos dos fornecedores ativos, com nível, situação, validade, protocolo e responsáveis.', 'All documents required from active suppliers, with level, status, expiry, protocol and owners.', 'Todos los documentos exigidos a los proveedores activos, con nivel, situación, vencimiento, protocolo y responsables.'),
                'legado' => $T('Estatísticas › Documentação do fornecedor válida, expirada, aprovados e reprovados; Relatório Documentos exigidos fornecedor', 'Statistics › Supplier documentation valid, expired, approved and rejected; Required supplier documents report', 'Estadísticas › Documentación del proveedor válida, vencida, aprobados y reprobados; Informe Documentos exigidos proveedor'),
                'colunas' => [$col('unidade'), $col('fornecedor'), $col('tipo'), $col('documento'), $col('nivel'), $col('enviado'), $col('situacao'), $col('validade'), $col('dias'), $col('protocolo'), $col('contrato'), $col('responsavel'), $col('observacao'), $col('evidencia')],
                'chips' => ['todos', 'validos', 'vencidos', 'vence30', 'pendforn', 'pendemp', 'protocolo', 'aprovados', 'reprovados', 'semresp', 'semsituacao'],
                'agrupar' => ['unidade', 'fornecedor', 'tipo']],
            'auditoria' => ['categoria' => 'AUDITORIA', 'grupo' => 'fornecedores',
                'nome' => $T('Auditoria de homologação', 'Approval audit', 'Auditoría de homologación'),
                'sub' => $T('tipos que exigem auditoria', 'types requiring audit', 'tipos que exigen auditoría'),
                'desc' => $T('Auditoria por tipo de fornecedor que exige auditoria: validade, nota e aprovação.', 'Audit per supplier type requiring audit: expiry, score and approval.', 'Auditoría por tipo de proveedor que exige auditoría: vencimiento, nota y aprobación.'),
                'legado' => $T('Estatísticas › Auditoria válida e expirada; Relatório Auditoria', 'Statistics › Audit valid and expired; Audit report', 'Estadísticas › Auditoría válida y vencida; Informe Auditoría'),
                'colunas' => [$col('unidade'), $col('fornecedor'), $col('tipo'), $col('nota'), $col('aprovado'), $col('situacao'), $col('validade'), $col('dias'), $col('evidencia')],
                'chips' => ['todos', 'validas', 'vencidas', 'vence30', 'reprovadas', 'semdata'],
                'agrupar' => ['unidade', 'fornecedor', 'tipo']],
            'nivel' => ['categoria' => 'NIVEL', 'grupo' => 'fornecedores',
                'nome' => $T('Nível de serviço', 'Service level', 'Nivel de servicio'),
                'sub' => $T('avaliações e próximas', 'evaluations and next ones', 'evaluaciones y próximas'),
                'desc' => $T('Nível de serviço de cada fornecedor (última avaliação) e a data da próxima avaliação.', 'Service level of each supplier (last evaluation) and the next evaluation date.', 'Nivel de servicio de cada proveedor (última evaluación) y la fecha de la próxima.'),
                'legado' => $T('Estatísticas › Nível de serviço satisfatório e não satisfatório', 'Statistics › Satisfactory and unsatisfactory service level', 'Estadísticas › Nivel de servicio satisfactorio y no satisfactorio'),
                'colunas' => [$col('unidade'), $col('fornecedor'), $col('cnpj'), $col('situacao', 'nivelservico'), $col('ultima'), $col('validade', 'proxima'), $col('dias'), $col('responsavel', 'responsavel_plano')],
                'chips' => ['todos', 'satisfatorio', 'naosatisfatorio', 'atrasada', 'semavaliacao'],
                'agrupar' => ['unidade']],
            'quimicos' => ['categoria' => 'QUIMICO', 'grupo' => 'fornecedores',
                'nome' => $T('Produtos químicos', 'Chemicals', 'Productos químicos'),
                'sub' => $T('FDS, controle e aprovação', 'SDS, control and approval', 'FDS, control y aprobación'),
                'desc' => $T('Produtos químicos dos fornecedores (só os cadastrados): CAS, característica, controle, validade, FDS e aprovação.', 'Supplier chemicals (registered ones only): CAS, characteristic, control, expiry, SDS and approval.', 'Productos químicos de los proveedores (solo los registrados): CAS, característica, control, vencimiento, FDS y aprobación.'),
                'legado' => $T('Relatório Gerenciamento de produtos químicos (lá listava também os fornecedores sem produto)', 'Chemicals management report (it also listed suppliers without products)', 'Informe Gestión de productos químicos (allí listaba también los proveedores sin producto)'),
                'colunas' => [$col('unidade'), $col('fornecedor'), $col('documento', 'produto'), $col('cas'), $col('caracteristica'), $col('controlado'), $col('validade'), $col('dias'), $col('aprovado'), $col('situacao'), $col('evidencia', 'fds')],
                'chips' => ['todos', 'vencidos', 'vence30', 'controlados', 'reprovados', 'aprovados'],
                'agrupar' => ['unidade', 'fornecedor']],
            'equipamentos' => ['categoria' => 'EQUIPAMENTO', 'grupo' => 'fornecedores',
                'nome' => $T('Equipamentos', 'Equipment', 'Equipos'),
                'sub' => $T('inspeção e calibração', 'inspection and calibration', 'inspección y calibración'),
                'desc' => $T('Equipamentos dos fornecedores (só os cadastrados) com validade de inspeção ou calibração e aprovação.', 'Supplier equipment (registered only) with inspection or calibration expiry and approval.', 'Equipos de los proveedores (solo los registrados) con vencimiento de inspección o calibración y aprobación.'),
                'legado' => $T('Relatório Gerenciamento de equipamentos (lá listava também os fornecedores sem equipamento)', 'Equipment management report (it also listed suppliers without equipment)', 'Informe Gestión de equipos (allí listaba también los proveedores sin equipo)'),
                'colunas' => [$col('unidade'), $col('fornecedor'), $col('documento', 'equipamento'), $col('identificacao'), $col('validade'), $col('dias'), $col('aprovado'), $col('situacao'), $col('evidencia', 'anexo')],
                'chips' => ['todos', 'vencidos', 'vence30', 'validos', 'reprovados'],
                'agrupar' => ['unidade', 'fornecedor']],
            'plano' => ['categoria' => 'PLANO', 'grupo' => 'fornecedores',
                'nome' => $T('Plano de ação', 'Action plan', 'Plan de acción'),
                'sub' => $T('ações com fornecedores', 'actions with suppliers', 'acciones con proveedores'),
                'desc' => $T('Ações abertas com os fornecedores: responsável, início, prazo, recursos e andamento.', 'Open actions with suppliers: owner, start, deadline, resources and progress.', 'Acciones abiertas con los proveedores: responsable, inicio, plazo, recursos y avance.'),
                'legado' => $T('Relatório Gerenciamento de plano de ação', 'Action plan management report', 'Informe Gestión de plan de acción'),
                'colunas' => [$col('unidade'), $col('fornecedor'), $col('cnpj'), $col('documento', 'acao'), $col('responsavel', 'responsavel_plano'), $col('inicio'), $col('validade', 'prazo'), $col('dias'), $col('recursos'), $col('situacao', 'andamento'), $col('evidencia', 'arquivos')],
                'chips' => ['todos', 'atrasadas', 'vence30', 'abertas', 'concluidas'],
                'agrupar' => ['unidade', 'fornecedor']],
            'docfunc' => ['categoria' => 'DOCFUNC', 'grupo' => 'funcionarios',
                'nome' => $T('Documentos de funcionários', 'Employee documents', 'Documentos de empleados'),
                'sub' => $T('ASO, NRs, certificados', 'health certificates, safety rules, certificates', 'exámenes, normas, certificados'),
                'desc' => $T('Documentos exigidos de cada funcionário pelo tipo de funcionário: atende, validade e responsáveis.', 'Documents required from each employee by employee type: met, expiry and owners.', 'Documentos exigidos a cada empleado por tipo de empleado: atiende, vencimiento y responsables.'),
                'legado' => $T('Estatísticas › Documentação do funcionário válida e expirada; Relatório Documentos exigidos funcionários', 'Statistics › Employee documentation valid and expired; Required employee documents report', 'Estadísticas › Documentación del empleado válida y vencida; Informe Documentos exigidos empleados'),
                'colunas' => [$col('unidade'), $col('fornecedor'), $col('funcionario'), $col('tipofunc'), $col('documento'), $col('tipodoc'), $col('atende'), $col('situacao'), $col('validade'), $col('dias'), $col('responsavel'), $col('evidencia')],
                'chips' => ['todos', 'validos', 'vencidos', 'vence30', 'naoatende', 'semavaliacao', 'naoaplica'],
                'agrupar' => ['unidade', 'fornecedor', 'tipofunc', 'funcionario']],
            'integracao' => ['categoria' => 'INTEGRACAO', 'grupo' => 'funcionarios',
                'nome' => $T('Integração', 'Induction', 'Integración'),
                'sub' => $T('em dia ou a renovar', 'up to date or to renew', 'al día o a renovar'),
                'desc' => $T('Funcionários com a integração em dia ou que precisam de nova integração.', 'Employees whose induction is up to date or needs renewal.', 'Empleados con la integración al día o que necesitan una nueva.'),
                'legado' => $T('Estatísticas › Integração aprovada e necessita nova; Relatório Gerenciamento de integração', 'Statistics › Induction approved and renewal needed; Induction management report', 'Estadísticas › Integración aprobada y necesita nueva; Informe Gestión de integración'),
                'colunas' => [$col('unidade'), $col('fornecedor'), $col('funcionario'), $col('nrregistro'), $col('setor'), $col('tipofunc'), $col('validade', 'integracao'), $col('dias'), $col('situacao'), $col('evidencia', 'anexo')],
                'chips' => ['todos', 'emdia', 'renovar', 'vence30', 'semdata'],
                'agrupar' => ['unidade', 'fornecedor', 'tipofunc', 'setor']],
            'treinamento' => ['categoria' => 'TREINAMENTO', 'grupo' => 'funcionarios',
                'nome' => $T('Treinamentos', 'Training', 'Capacitaciones'),
                'sub' => $T('realizados e validade', 'done and expiry', 'realizadas y vencimiento'),
                'desc' => $T('Treinamentos exigidos pelo tipo de funcionário: realizado, validade e dias para vencer.', 'Training required by employee type: done, expiry and days to expire.', 'Capacitaciones exigidas por tipo de empleado: realizada, vencimiento y días para vencer.'),
                'legado' => $T('Relatório Gerenciamento de treinamento', 'Training management report', 'Informe Gestión de capacitación'),
                'colunas' => [$col('unidade'), $col('fornecedor'), $col('funcionario'), $col('tipofunc'), $col('documento', 'treinamento'), $col('atende', 'realizado'), $col('situacao'), $col('validade'), $col('dias'), $col('responsavel', 'responsavel_plano'), $col('evidencia')],
                'chips' => ['todos', 'validos', 'vencidos', 'vence30', 'naorealizado', 'semsituacao'],
                'agrupar' => ['unidade', 'fornecedor', 'documento', 'funcionario']],
        ];
    }

    /** Catálogo (grupos com os relatórios, o unificado e o histórico). */
    public function catalogo(): array
    {
        $grupos = [
            'fornecedores' => ['pt' => 'Fornecedores', 'en' => 'Suppliers', 'es' => 'Proveedores'],
            'funcionarios' => ['pt' => 'Funcionários', 'en' => 'Employees', 'es' => 'Empleados'],
            'rastreabilidade' => ['pt' => 'Rastreabilidade', 'en' => 'Traceability', 'es' => 'Trazabilidad'],
        ];
        $out = [];
        foreach ($grupos as $g => $nome) $out[$g] = ['id' => $g, 'nome' => $this->L($nome), 'itens' => []];
        foreach (self::relatorios() as $id => $r)
            $out[$r['grupo']]['itens'][] = ['id' => $id, 'nome' => $this->L($r['nome']), 'sub' => $this->L($r['sub']), 'categoria' => $r['categoria']];
        $out['funcionarios']['itens'][] = ['id' => 'unificado', 'nome' => $this->L(['pt' => 'Relatório unificado', 'en' => 'Unified report', 'es' => 'Informe unificado']),
            'sub' => $this->L(['pt' => 'uma linha por funcionário', 'en' => 'one row per employee', 'es' => 'una fila por empleado']), 'categoria' => 'UNIFICADO'];
        $out['rastreabilidade']['itens'][] = ['id' => 'historico', 'nome' => $this->L(['pt' => 'Histórico de alterações', 'en' => 'Change history', 'es' => 'Historial de cambios']),
            'sub' => $this->L(['pt' => 'quem mudou, quando e o quê', 'en' => 'who changed what and when', 'es' => 'quién cambió qué y cuándo']), 'categoria' => 'HISTORICO',
            'url' => 'homologacaofornecedores_historicodocumentos.php'];
        return array_values($out);
    }

    /** Filtros rápidos: id => [rótulo, cor da bolinha, função sobre a linha já montada]. */
    public function filtrosRapidos(): array
    {
        $T = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];
        $d = static fn(array $l): ?int => $l['dias'] === '' ? null : (int)$l['dias'];
        $venc = static fn(array $l): bool => $d($l) !== null && $d($l) < 0 && $l['cor'] !== 'NEUTRO';
        $em30 = static fn(array $l): bool => $d($l) !== null && $d($l) >= 0 && $d($l) <= ($l['_reacao'] ?: 30) && $l['cor'] !== 'NEUTRO';
        $sit = static fn(array $l, string ...$s): bool => in_array($l['_situacao'], $s, true);
        $nao = static fn(string $v): bool => in_array(mb_strtolower(trim($v)), ['não', 'nao', 'n'], true);
        $sim = static fn(string $v): bool => in_array(mb_strtolower(trim($v)), ['sim', 's'], true);
        return [
            'todos' => [$T('Todos', 'All', 'Todos'), 'BRANCO', static fn(array $l): bool => true],
            'validos' => [$T('Válidos (em dia)', 'Valid (up to date)', 'Válidos (al día)'), 'VERDE', static fn(array $l): bool => $l['cor'] === 'VERDE'],
            'vencidos' => [$T('Vencidos', 'Expired', 'Vencidos'), 'VERMELHO', $venc],
            'vence30' => [$T('Vencem nos dias de reação', 'Expiring within reaction days', 'Vencen en los días de reacción'), 'AMARELO', $em30],
            'pendforn' => [$T('Pendentes com o fornecedor', 'Pending with the supplier', 'Pendientes con el proveedor'), 'VERMELHO', static fn(array $l): bool => $sit($l, 'Pendente com o fornecedor')],
            'pendemp' => [$T('Pendentes com a empresa', 'Pending with the company', 'Pendientes con la empresa'), 'AMARELO', static fn(array $l): bool => $sit($l, 'Pendente com a empresa')],
            'protocolo' => [$T('Em protocolo', 'Under protocol', 'En protocolo'), 'AMARELO', static fn(array $l): bool => $sit($l, 'Aguardando vencimento do protocolo')],
            'aprovados' => [$T('Aprovados', 'Approved', 'Aprobados'), 'VERDE', static fn(array $l): bool => $l['categoria'] === 'DOCUMENTO' ? $sit($l, 'Aprovado', 'Aprovado sem validade', 'Ciente da regra') : $sim($l['aprovado'])],
            'reprovados' => [$T('Reprovados', 'Rejected', 'Reprobados'), 'VERMELHO', static fn(array $l): bool => $l['categoria'] === 'DOCUMENTO' ? $sit($l, 'Reprovado') : $nao($l['aprovado'])],
            'semresp' => [$T('Sem responsável', 'No owner', 'Sin responsable'), 'NEUTRO', static fn(array $l): bool => $l['cor'] !== 'NEUTRO' && $l['_semresp']],
            'semsituacao' => [$T('Sem situação', 'No status', 'Sin situación'), 'BRANCO', static fn(array $l): bool => $l['cor'] === 'BRANCO'],
            'validas' => [$T('Válidas', 'Valid', 'Válidas'), 'VERDE', static fn(array $l): bool => $l['cor'] === 'VERDE' || ($d($l) !== null && $d($l) >= 0 && $l['cor'] !== 'VERMELHO')],
            'vencidas' => [$T('Vencidas', 'Expired', 'Vencidas'), 'VERMELHO', $venc],
            'reprovadas' => [$T('Reprovadas', 'Failed', 'Reprobadas'), 'VERMELHO', static fn(array $l): bool => $sit($l, 'Reprovado na auditoria')],
            'semdata' => [$T('Sem data', 'No date', 'Sin fecha'), 'BRANCO', static fn(array $l): bool => $l['validade'] === ''],
            'satisfatorio' => [$T('Satisfatório', 'Satisfactory', 'Satisfactorio'), 'VERDE', static fn(array $l): bool => $sit($l, 'Satisfatório')],
            'naosatisfatorio' => [$T('Não satisfatório', 'Unsatisfactory', 'No satisfactorio'), 'VERMELHO', static fn(array $l): bool => $sit($l, 'Não satisfatório')],
            'atrasada' => [$T('Avaliação atrasada', 'Overdue evaluation', 'Evaluación atrasada'), 'AMARELO', static fn(array $l): bool => $d($l) !== null && $d($l) < 0],
            'semavaliacao' => [$T('Sem avaliação', 'Not evaluated', 'Sin evaluación'), 'BRANCO', static fn(array $l): bool => $l['cor'] === 'BRANCO'],
            'controlados' => [$T('Controlados', 'Controlled', 'Controlados'), 'AMARELO', static fn(array $l): bool => trim($l['controlado']) !== '' && !$nao($l['controlado'])],
            'atrasadas' => [$T('Atrasadas', 'Overdue', 'Atrasadas'), 'VERMELHO', static fn(array $l): bool => $l['cor'] === 'VERMELHO'],
            'abertas' => [$T('Em aberto', 'Open', 'Abiertas'), 'BRANCO', static fn(array $l): bool => $l['cor'] !== 'VERDE'],
            'concluidas' => [$T('Concluídas', 'Completed', 'Concluidas'), 'VERDE', static fn(array $l): bool => $l['cor'] === 'VERDE'],
            'naoatende' => [$T('Não atende', 'Not met', 'No atiende'), 'VERMELHO', static fn(array $l): bool => $sit($l, 'Não atende')],
            'naoaplica' => [$T('Não se aplica / desligado / afastado', 'Not applicable / dismissed / on leave', 'No aplica / desvinculado / de baja'), 'NEUTRO', static fn(array $l): bool => $l['cor'] === 'NEUTRO'],
            'emdia' => [$T('Integração em dia', 'Induction up to date', 'Integración al día'), 'VERDE', static fn(array $l): bool => $l['cor'] === 'VERDE'],
            'renovar' => [$T('Precisa de nova integração', 'Needs new induction', 'Necesita nueva integración'), 'VERMELHO', static fn(array $l): bool => $l['cor'] === 'VERMELHO'],
            'naorealizado' => [$T('Não realizados', 'Not done', 'No realizadas'), 'VERMELHO', static fn(array $l): bool => $sit($l, 'Não realizado')],
        ];
    }

    /** Filtro rápido ou faixa da barra ("cor:VERMELHO"). */
    public function filtro(string $id): ?array
    {
        if (preg_match('/^cor:(VERMELHO|AMARELO|VERDE|BRANCO|NEUTRO)$/', $id, $m)) {
            $nome = $this->nomeCor($m[1]);
            return [['pt' => $nome, 'en' => $nome, 'es' => $nome], $m[1], static fn(array $l): bool => $l['cor'] === $m[1]];
        }
        return $this->filtrosRapidos()[$id] ?? null;
    }

    public function nomeCor(string $cor): string
    {
        return $this->L([
            'VERMELHO' => ['pt' => 'Pedem ação (vermelho)', 'en' => 'Need action (red)', 'es' => 'Requieren acción (rojo)'],
            'AMARELO' => ['pt' => 'Vencem em breve / com a empresa (amarelo)', 'en' => 'Expiring soon / with the company (yellow)', 'es' => 'Vencen pronto / con la empresa (amarillo)'],
            'VERDE' => ['pt' => 'Em dia (verde)', 'en' => 'Up to date (green)', 'es' => 'Al día (verde)'],
            'BRANCO' => ['pt' => 'Sem situação', 'en' => 'No status', 'es' => 'Sin situación'],
            'NEUTRO' => ['pt' => 'Não se aplica', 'en' => 'Not applicable', 'es' => 'No aplica'],
        ][$cor] ?? ['pt' => $cor]);
    }

    // ------------------------------------------------------------------ dados

    /**
     * Modo de acesso passado aos procedimentos (p_externo): 0 = vê tudo, 1 = fornecedor externo, 2 = interno restrito por
     * unidade (migração 316). true/false = 1/0 (chamadas antigas). Ver HomologacaoFornecedorAcesso::modoBanco().
     */
    public static function modoAcesso(bool|int $externo): int
    {
        if (is_bool($externo)) return $externo ? 1 : 0;
        return in_array($externo, [0, 1, 2], true) ? $externo : 1;
    }

    /** @return list<array<string,mixed>> linhas cruas de CALL sp_fo_relatorio_itens */
    public static function lerItens(PDO $pdo, int $usuario, bool|int $externo, array $filtros, string $categoria): array
    {
        $m = self::modoAcesso($externo);
        $s = $pdo->prepare('CALL sp_fo_relatorio_itens(?, ?, ?, ?, ?, ?)');
        $s->execute([$usuario, $m, $filtros['unidade'] ?? null, $m === 1 ? null : ($filtros['tipo'] ?? null), (string)($filtros['busca'] ?? ''), $categoria]);
        $r = $s->fetchAll(PDO::FETCH_ASSOC);
        while ($s->nextRowset()) { }
        $s->closeCursor();
        return $r;
    }

    /** @return array<string,array<string,int>> CATEGORIA => COR => quantidade */
    public static function lerContagens(PDO $pdo, int $usuario, bool|int $externo, array $filtros): array
    {
        $m = self::modoAcesso($externo);
        $s = $pdo->prepare('CALL sp_fo_relatorio_contagens(?, ?, ?, ?, ?)');
        $s->execute([$usuario, $m, $filtros['unidade'] ?? null, $m === 1 ? null : ($filtros['tipo'] ?? null), (string)($filtros['busca'] ?? '')]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $l) $out[(string)$l['CATEGORIA']][(string)$l['COR']] = (int)$l['N'];
        while ($s->nextRowset()) { }
        $s->closeCursor();
        return $out;
    }

    /** @return array{0:list<array>,1:list<array>,2:list<array>,3:list<array>} os 4 resultados de CALL sp_fo_relatorio_unificado */
    public static function lerUnificado(PDO $pdo, int $usuario, bool|int $externo, array $filtros): array
    {
        $m = self::modoAcesso($externo);
        $s = $pdo->prepare('CALL sp_fo_relatorio_unificado(?, ?, ?, ?, ?, NULL)');
        $s->execute([$usuario, $m, $filtros['unidade'] ?? null, $m === 1 ? null : ($filtros['tipo'] ?? null), (string)($filtros['busca'] ?? '')]);
        $c = [];
        do { try { $c[] = $s->fetchAll(PDO::FETCH_ASSOC); } catch (PDOException) { } } while ($s->nextRowset());
        $s->closeCursor();
        return [$c[0] ?? [], $c[1] ?? [], $c[2] ?? [], $c[3] ?? []];
    }

    /** Fornecedores bloqueados no ranking (migração 350): [codfornecedor => motivo]. Sem a view, nenhum. */
    public static function lerBloqueados(PDO $pdo): array
    {
        try {
            $out = [];
            foreach ($pdo->query('SELECT CODFORNECEDOR, MOTIVO FROM vw_fo_fornecedores_bloqueados')->fetchAll(PDO::FETCH_ASSOC) as $b) $out[(int)$b['CODFORNECEDOR']] = trim((string)$b['MOTIVO']);
            return $out;
        } catch (Throwable) { return []; }
    }

    public static function data(mixed $v): string
    {
        return $v && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string)$v, $m) && $m[1] > '1900' ? "$m[3]/$m[2]/$m[1]" : '';
    }

    /** Linha crua do procedimento → linha do relatório (chaves das colunas + campos internos com "_"). */
    public static function linha(array $r): array
    {
        $v = static fn(string $k): string => trim((string)($r[$k] ?? ''));
        // Campos Sim/Não do legado gravados como S/N (ex.: emprotocolo é varchar(1)).
        $sn = static function (string $k) use ($v): string { $x = $v($k); return match (mb_strtoupper($x)) { 'S' => 'Sim', 'N' => 'Não', default => $x }; };
        $cat = (string)($r['CATEGORIA'] ?? '');
        $respE = $v('RESPONSAVEL_EMPRESA'); $respF = $v('RESPONSAVEL_FORNECEDOR');
        $obs = $v('OBSERVACAO');
        if ($v('OBSERVACAO_FORNECEDOR') !== '') $obs = trim($obs . ($obs !== '' ? ' · ' : '') . 'Fornecedor: ' . $v('OBSERVACAO_FORNECEDOR'));
        $protocolo = $sn('PROTOCOLO');
        if ($protocolo !== '' && self::data($r['COBRANCA_PROTOCOLO'] ?? null) !== '') $protocolo .= ' (cobrança ' . self::data($r['COBRANCA_PROTOCOLO']) . ')';
        $situacao = $v('SITUACAO');
        $uF = (int)($r['CODUSUARIO_FORNECEDOR'] ?? 0);
        return [
            'categoria' => $cat, 'codregistro' => (int)($r['CODREGISTRO'] ?? 0), 'codfornecedor' => (int)($r['CODFORNECEDOR'] ?? 0),
            'codfuncionario' => (int)($r['CODFUNCIONARIO'] ?? 0), 'cor' => in_array((string)($r['COR'] ?? ''), self::CORES, true) ? (string)$r['COR'] : 'BRANCO',
            'unidade' => $v('DSCUNIDADE'), 'fornecedor' => $v('RAZAOSOCIAL'), 'cnpj' => $v('CNPJ_CPF'),
            'tipo' => $v('TIPO'), 'documento' => $v('DOCUMENTO') !== '' ? $v('DOCUMENTO') : $v('ITEM'),
            'nivel' => $v('NIVEL'), 'enviado' => $sn('ENVIADO'), 'situacao' => $situacao !== '' ? $situacao : 'Sem situação',
            'validade' => self::data($r['VALIDADE'] ?? null), 'dias' => ($r['QTDDIAS'] ?? null) === null || $r['QTDDIAS'] === '' ? '' : (string)(int)$r['QTDDIAS'],
            'protocolo' => $protocolo, 'contrato' => $sn('CONTRATO'),
            'responsavel' => $cat === 'DOCUMENTO' || $cat === 'DOCFUNC' ? ($respE !== '' || $respF !== '' ? ($respE !== '' ? $respE : '—') . ' / ' . ($respF !== '' ? $respF : '—') : '') : $respE,
            'observacao' => $obs, 'nota' => $v('NOTA'), 'aprovado' => $sn('APROVADO'),
            'funcionario' => $v('FUNCIONARIO'), 'nrregistro' => $v('NRREGISTRO'), 'setor' => $v('SETOR'), 'tipofunc' => $v('TIPO_FUNCIONARIO'),
            'datainicio' => self::data($r['DATA_INICIO'] ?? null), 'tipodoc' => $v('TIPO_DOCUMENTO'), 'atende' => $sn('ATENDE'),
            'cas' => $v('CAS'), 'controlado' => $sn('CONTROLADO'), 'caracteristica' => $v('CARACTERISTICA'), 'identificacao' => $v('IDENTIFICACAO'),
            'inicio' => self::data($r['INICIO'] ?? null), 'recursos' => $v('RECURSOS'), 'ultima' => self::data($r['ULTIMA_AVALIACAO'] ?? null),
            'evidencia' => '',
            // Planilha (Excel "Fornecedores + Critérios" do legado): fantasia, responsáveis separados, observações e cobrança separadas.
            '_fantasia' => $v('NOMEFANTASIA'), '_resp_cadastro' => $v('RESPONSAVEL_CADASTRO'), '_resp_empresa' => $respE, '_resp_fornecedor' => $respF,
            '_obs_empresa' => $v('OBSERVACAO'), '_obs_fornecedor' => $v('OBSERVACAO_FORNECEDOR'), '_cobranca' => self::data($r['COBRANCA_PROTOCOLO'] ?? null),
            '_situacao' => $situacao, '_reacao' => (int)($r['REACAO'] ?? 0) > 0 ? (int)$r['REACAO'] : 30,
            '_semresp' => (int)($r['CODUSUARIO_EMPRESA'] ?? 0) === 0 || in_array($uF, [0, 99999], true),
        ];
    }

    /** Linhas do relatório (já montadas), na ordem padrão: vermelho, amarelo, branco, verde, cinza; depois dias e fornecedor. */
    public function linhasRelatorio(array $cruas): array
    {
        $linhas = array_map([self::class, 'linha'], $cruas);
        $peso = ['VERMELHO' => 0, 'AMARELO' => 1, 'BRANCO' => 2, 'VERDE' => 3, 'NEUTRO' => 4];
        usort($linhas, static fn($a, $b) => ($peso[$a['cor']] ?? 9) <=> ($peso[$b['cor']] ?? 9)
            ?: (($a['dias'] === '' ? PHP_INT_MAX : (int)$a['dias']) <=> ($b['dias'] === '' ? PHP_INT_MAX : (int)$b['dias']))
            ?: strcasecmp($a['fornecedor'], $b['fornecedor']) ?: strcasecmp($a['funcionario'], $b['funcionario']) ?: strcasecmp($a['documento'], $b['documento']));
        return $linhas;
    }

    public static function semAcento(string $v): string
    {
        $v = mb_strtolower($v);
        if (class_exists('Normalizer')) { $n = preg_replace('/\p{Mn}/u', '', (string)Normalizer::normalize($v, Normalizer::FORM_D)); if (is_string($n)) return $n; }
        return strtr($v, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c']);
    }

    /** Chaves de coluna do relatório (sem a evidência, que é contada só na página). */
    public static function chavesColunas(string $relatorio, bool $comEvidencia = false): array
    {
        $def = self::relatorios()[$relatorio] ?? null;
        if (!$def) return [];
        $out = [];
        foreach ($def['colunas'] as [$k]) if ($comEvidencia || $k !== 'evidencia') $out[] = $k;
        return $out;
    }

    /**
     * Aplica o pedido da tela (filtro rápido, filtros por coluna, busca no grid, agrupamento e ordenação) às linhas do relatório.
     * @param array{chip?:string,colunas?:array,busca_grid?:string,agrupar?:string,ordem_col?:string,ordem_dir?:string} $p
     * @return array{linhas:list<array>,chip:string,agrupar:string,ordem:array{col:string,dir:string}}
     */
    public function aplicar(string $relatorio, array $linhas, array $p): array
    {
        $def = self::relatorios()[$relatorio];
        $chip = (string)($p['chip'] ?? 'todos');
        $f = in_array($chip, $def['chips'], true) || str_starts_with($chip, 'cor:') ? $this->filtro($chip) : null;
        if (!$f) { $chip = 'todos'; $f = $this->filtro('todos'); }
        $linhas = array_values(array_filter($linhas, $f[2]));
        $chaves = self::chavesColunas($relatorio);
        $colunas = is_array($p['colunas'] ?? null) ? $p['colunas'] : [];
        $colunas = array_intersect_key(array_filter(array_map(static fn($v) => is_scalar($v) ? trim((string)$v) : '', $colunas), 'strlen'), array_flip($chaves));
        if ($colunas) {
            $colunas = array_map([self::class, 'semAcento'], $colunas);
            $linhas = array_values(array_filter($linhas, static function (array $l) use ($colunas): bool {
                foreach ($colunas as $c => $v) if (!str_contains(self::semAcento((string)$l[$c]), $v)) return false;
                return true;
            }));
        }
        $busca = self::semAcento(mb_substr(trim((string)($p['busca_grid'] ?? '')), 0, 100));
        if ($busca !== '') $linhas = array_values(array_filter($linhas, static function (array $l) use ($busca, $chaves): bool {
            foreach ($chaves as $c) if (str_contains(self::semAcento((string)$l[$c]), $busca)) return true;
            return false;
        }));
        $col = in_array((string)($p['ordem_col'] ?? ''), $chaves, true) ? (string)$p['ordem_col'] : '';
        $dir = (string)($p['ordem_dir'] ?? '') === 'desc' ? 'desc' : 'asc';
        $agrupar = in_array((string)($p['agrupar'] ?? ''), $def['agrupar'], true) ? (string)$p['agrupar'] : '';
        $chave = static function (array $l, string $c) {
            if ($c === 'dias') return $l['dias'] === '' ? PHP_INT_MAX : (int)$l['dias'];
            if (in_array($c, ['validade', 'inicio', 'ultima', 'datainicio'], true)) return preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', (string)$l[$c], $m) ? "$m[3]$m[2]$m[1]" : '99999999';
            return self::semAcento((string)$l[$c]);
        };
        $cmp = static fn($a, $b): int => is_int($a) && is_int($b) ? $a <=> $b : strnatcmp((string)$a, (string)$b);
        if ($col !== '' || $agrupar !== '') {
            // usort não é estável para chaves iguais em todas as versões: usa a posição original como desempate.
            foreach ($linhas as $i => &$l) $l['_pos'] = $i;
            unset($l);
            usort($linhas, static function ($a, $b) use ($col, $dir, $agrupar, $chave, $cmp): int {
                if ($agrupar !== '' && ($g = $cmp($chave($a, $agrupar), $chave($b, $agrupar))) !== 0) return $g;
                if ($col !== '' && ($o = $cmp($chave($a, $col), $chave($b, $col))) !== 0) return $dir === 'desc' ? -$o : $o;
                return $a['_pos'] <=> $b['_pos'];
            });
        }
        return ['linhas' => $linhas, 'chip' => $chip, 'agrupar' => $agrupar, 'ordem' => ['col' => $col, 'dir' => $dir]];
    }

    /** Números, filtros rápidos com quantidades e barra de distribuição (sobre o relatório inteiro, sem o filtro rápido). */
    public function resumo(string $relatorio, array $linhas): array
    {
        $def = self::relatorios()[$relatorio];
        $porCor = array_fill_keys(self::CORES, 0);
        foreach ($linhas as $l) $porCor[$l['cor']] = ($porCor[$l['cor']] ?? 0) + 1;
        $total = count($linhas);
        $base = $total - $porCor['NEUTRO'];
        $fornecedores = count(array_unique(array_map(static fn($l) => $l['codfornecedor'], $linhas)));
        $funcionarios = count(array_unique(array_filter(array_map(static fn($l) => $l['codfuncionario'], $linhas))));
        $pct = static fn(int $n, int $b): string => $b > 0 ? (string)round($n / $b * 100) . '%' : '—';
        $kpis = [
            ['id' => 'todos', 'cor' => 'PRIMARIA', 'rotulo' => $this->L(['pt' => 'Itens no relatório', 'en' => 'Items in the report', 'es' => 'Ítems en el informe']), 'valor' => number_format($total, 0, ',', '.'),
                'sub' => $fornecedores . ' ' . $this->L(['pt' => 'fornecedor(es)', 'en' => 'supplier(s)', 'es' => 'proveedor(es)']) . ($funcionarios ? ' · ' . $funcionarios . ' ' . $this->L(['pt' => 'funcionário(s)', 'en' => 'employee(s)', 'es' => 'empleado(s)']) : '')],
            ['id' => 'cor:VERMELHO', 'cor' => 'VERMELHO', 'rotulo' => $this->L(['pt' => 'Pedem ação', 'en' => 'Need action', 'es' => 'Requieren acción']), 'valor' => number_format($porCor['VERMELHO'], 0, ',', '.'),
                'sub' => $pct($porCor['VERMELHO'], $base) . ' ' . $this->L(['pt' => 'do relatório', 'en' => 'of the report', 'es' => 'del informe'])],
            ['id' => 'cor:AMARELO', 'cor' => 'AMARELO', 'rotulo' => $this->L(['pt' => 'Vencem em breve ou com a empresa', 'en' => 'Expiring soon or with the company', 'es' => 'Vencen pronto o con la empresa']), 'valor' => number_format($porCor['AMARELO'], 0, ',', '.'),
                'sub' => $this->L(['pt' => 'dias de reação (30 quando não informado)', 'en' => 'reaction days (30 when not set)', 'es' => 'días de reacción (30 si no se informa)'])],
            ['id' => 'cor:VERDE', 'cor' => 'VERDE', 'rotulo' => $this->L(['pt' => '% em dia', 'en' => '% up to date', 'es' => '% al día']), 'valor' => $pct($porCor['VERDE'], $base),
                'sub' => $porCor['VERDE'] . ' ' . $this->L(['pt' => 'de', 'en' => 'of', 'es' => 'de']) . ' ' . $base . ' ' . $this->L(['pt' => '(sem os cinzas)', 'en' => '(without grays)', 'es' => '(sin los grises)'])],
        ];
        $chips = [];
        foreach ($def['chips'] as $id) {
            $f = $this->filtro($id);
            if (!$f) continue;
            $chips[] = ['id' => $id, 'rotulo' => $this->L($f[0]), 'cor' => $f[1], 'n' => count(array_filter($linhas, $f[2]))];
        }
        $dist = [];
        foreach (self::CORES as $c) if ($porCor[$c] > 0) $dist[] = ['id' => 'cor:' . $c, 'cor' => $c, 'rotulo' => $this->nomeCor($c), 'n' => $porCor[$c], 'pct' => $total ? round($porCor[$c] / $total * 100, 1) : 0];
        return ['kpis' => $kpis, 'chips' => $chips, 'dist' => $dist, 'total' => $total, 'vermelhos' => $porCor['VERMELHO'], 'amarelos' => $porCor['AMARELO']];
    }

    /** Subtotais de cada grupo (todas as linhas filtradas, não só a página). */
    public static function subtotais(array $linhas, string $agrupar): array
    {
        if ($agrupar === '') return [];
        $g = [];
        foreach ($linhas as $l) {
            $k = (string)$l[$agrupar] !== '' ? (string)$l[$agrupar] : '—';
            $g[$k] ??= ['n' => 0, 'VERMELHO' => 0, 'AMARELO' => 0, 'VERDE' => 0];
            $g[$k]['n']++;
            if (isset($g[$k][$l['cor']])) $g[$k][$l['cor']]++;
        }
        return $g;
    }

    /** Quantidade de arquivos no GED de cada linha (só nas linhas informadas: a página ou a planilha). */
    public static function contarEvidencias(array &$linhas, ?GedStorageService $ged): void
    {
        if (!$ged) return;
        foreach ($linhas as &$l) {
            $pasta = self::GED[$l['categoria']][0] ?? null;
            if (!$pasta || $l['codregistro'] < 1) continue;
            $n = 0; $vistos = [];
            foreach ($ged->folders($pasta, $l['codregistro']) as $dir) {
                if (!is_dir($dir)) continue;
                foreach (new DirectoryIterator($dir) as $f) if (!$f->isDot() && $f->isFile() && !isset($vistos[$f->getFilename()])) { $vistos[$f->getFilename()] = true; $n++; }
            }
            $l['evidencia'] = (string)$n;
        }
        unset($l);
    }

    /** Linha para a tela (só as colunas do relatório + o que o grid precisa). */
    public static function saida(string $relatorio, array $l): array
    {
        $o = ['cor' => $l['cor'], 'codfornecedor' => $l['codfornecedor'], 'codregistro' => $l['codregistro'], 'categoria' => $l['categoria'], 'codfuncionario' => $l['codfuncionario']];
        foreach (self::chavesColunas($relatorio, true) as $k) $o[$k] = (string)$l[$k];
        return $o;
    }

    // ------------------------------------------------------------------ relatório unificado

    /**
     * Matriz funcionário × integração/documentos/treinamentos.
     * Bloqueado = algum item exigido vencido, "Não atende" (vermelho) ou não avaliado (sem situação / sem registro);
     * Vence em breve = algum item amarelo; Liberado = todos verdes. Cinza (não se aplica, desligado, afastado) e itens
     * que o tipo de funcionário não exige ("—") não contam.
     * Fornecedor bloqueado no ranking (decisão de 24/10/2026): todos os funcionários dele ficam Bloqueado, com o motivo nas pendências.
     * @return array{colunas:list<array>,linhas:list<array>,tipos:list<string>}
     */
    public function unificado(array $funcionarios, array $exigeDoc, array $exigeTrein, array $situacoes, array $bloqueados = []): array
    {
        $col = ['I:0' => ['id' => 'I:0', 'grupo' => $this->L(['pt' => 'Integração', 'en' => 'Induction', 'es' => 'Integración']), 'nome' => $this->L(['pt' => 'Integração', 'en' => 'Induction', 'es' => 'Integración']), 'cat' => 'INTEGRACAO']];
        $docs = []; $treins = [];
        $exige = [];
        foreach ($exigeDoc as $e) { $docs['D:' . (int)$e['CODITEM']] = (string)$e['DSCITEM']; $exige[(int)$e['CODTIPOFUNCIONARIO']]['D:' . (int)$e['CODITEM']] = true; }
        foreach ($exigeTrein as $e) { $treins['T:' . (int)$e['CODITEM']] = (string)$e['DSCITEM']; $exige[(int)$e['CODTIPOFUNCIONARIO']]['T:' . (int)$e['CODITEM']] = true; }
        $peso = ['V' => 4, 'B' => 3, 'A' => 2, 'G' => 1, 'N' => 0];
        $letra = ['VERMELHO' => 'V', 'BRANCO' => 'B', 'AMARELO' => 'A', 'VERDE' => 'G', 'NEUTRO' => 'N'];
        $sit = [];
        foreach ($situacoes as $s) {
            $cat = (string)$s['CATEGORIA'];
            $k = $cat === 'INTEGRACAO' ? 'I:0' : ($cat === 'DOCFUNC' ? 'D:' : 'T:') . (int)$s['CODITEM'];
            if ($cat === 'DOCFUNC' && !isset($docs[$k])) $docs[$k] = (string)$s['DSCITEM'];
            if ($cat === 'TREINAMENTO' && !isset($treins[$k])) $treins[$k] = (string)$s['DSCITEM'];
            $l = $letra[(string)$s['COR']] ?? 'B';
            $f = (int)$s['CODFUNCIONARIO'];
            if (isset($sit[$f][$k]) && $peso[$sit[$f][$k]['s']] >= $peso[$l]) continue;
            $sit[$f][$k] = ['s' => $l, 'situacao' => (string)$s['SITUACAO'], 'validade' => self::data($s['VALIDADE'] ?? null), 'dias' => $s['QTDDIAS'] === null ? null : (int)$s['QTDDIAS'], 'reg' => (int)$s['CODREGISTRO'], 'cat' => $cat];
        }
        asort($docs, SORT_NATURAL | SORT_FLAG_CASE); asort($treins, SORT_NATURAL | SORT_FLAG_CASE);
        $gDoc = $this->L(['pt' => 'Documento', 'en' => 'Document', 'es' => 'Documento']);
        $gTre = $this->L(['pt' => 'Treinamento', 'en' => 'Training', 'es' => 'Capacitación']);
        foreach ($docs as $k => $n) $col[$k] = ['id' => $k, 'grupo' => $gDoc, 'nome' => trim($n) !== '' ? $n : $gDoc . ' ' . substr($k, 2), 'cat' => 'DOCFUNC'];
        foreach ($treins as $k => $n) $col[$k] = ['id' => $k, 'grupo' => $gTre, 'nome' => trim($n) !== '' ? $n : $gTre . ' ' . substr($k, 2), 'cat' => 'TREINAMENTO'];
        $avaliar = $this->L(['pt' => 'avaliar', 'en' => 'evaluate', 'es' => 'evaluar']);
        $rotSit = ['bloq' => $this->L(['pt' => 'Bloqueado', 'en' => 'Blocked', 'es' => 'Bloqueado']), 'vence' => $this->L(['pt' => 'Vence em breve', 'en' => 'Expiring soon', 'es' => 'Vence pronto']), 'lib' => $this->L(['pt' => 'Liberado', 'en' => 'Cleared', 'es' => 'Liberado'])];
        $linhas = []; $usadas = ['I:0' => true]; $tipos = [];
        foreach ($funcionarios as $fu) {
            $f = (int)$fu['CODFUNCIONARIO'];
            $tipo = (int)$fu['CODTIPOFUNCIONARIO'];
            if ((string)$fu['TIPO_FUNCIONARIO'] !== '') $tipos[(string)$fu['TIPO_FUNCIONARIO']] = true;
            $cel = []; $exigidos = 0; $emDia = 0; $bloq = false; $vence = false; $pend = [];
            foreach ($col as $k => $c) {
                $s = $sit[$f][$k] ?? null;
                $exigido = $k === 'I:0' || isset($exige[$tipo][$k]) || ($s && $s['s'] !== 'N');
                if (!$exigido) { $cel[$k] = ['s' => $s ? 'N' : 'X', 't' => '—', 'd' => $s ? $s['situacao'] : '', 'reg' => $s['reg'] ?? 0, 'cat' => $c['cat']]; continue; }
                $usadas[$k] = true;
                if (!$s) { $s = ['s' => 'B', 'situacao' => $this->L(['pt' => 'Não avaliado', 'en' => 'Not evaluated', 'es' => 'No evaluado']), 'validade' => '', 'dias' => null, 'reg' => 0, 'cat' => $c['cat']]; }
                if ($s['s'] === 'N') { $cel[$k] = ['s' => 'N', 't' => '—', 'd' => $s['situacao'], 'reg' => $s['reg'], 'cat' => $c['cat']]; continue; }
                $exigidos++;
                if ($s['s'] === 'G') $emDia++;
                if ($s['s'] === 'V' || $s['s'] === 'B') { $bloq = true; $pend[] = $c['nome'] . ': ' . $s['situacao'] . ($s['validade'] !== '' ? ' (' . $s['validade'] . ')' : ''); }
                if ($s['s'] === 'A') $vence = true;
                $texto = match ($s['s']) {
                    'B' => $avaliar,
                    'V' => $s['dias'] !== null && $s['dias'] < 0 && $s['validade'] !== '' ? $s['validade'] : $this->L(['pt' => 'Não', 'en' => 'No', 'es' => 'No']),
                    default => $s['validade'] !== '' ? $s['validade'] : $this->L(['pt' => 'Sim', 'en' => 'Yes', 'es' => 'Sí']),
                };
                $cel[$k] = ['s' => $s['s'], 't' => $texto, 'd' => $s['situacao'] . ($s['validade'] !== '' ? ' · ' . $s['validade'] : ''), 'reg' => $s['reg'], 'cat' => $c['cat']];
            }
            if (isset($bloqueados[(int)$fu['CODFORNECEDOR']])) {
                $bloq = true;
                array_unshift($pend, $this->L(['pt' => 'Fornecedor bloqueado no ranking', 'en' => 'Supplier blocked in ranking', 'es' => 'Proveedor bloqueado en el ranking'])
                    . ($bloqueados[(int)$fu['CODFORNECEDOR']] !== '' ? ': ' . $bloqueados[(int)$fu['CODFORNECEDOR']] : ''));
            }
            $chave = $bloq ? 'bloq' : ($vence ? 'vence' : 'lib');
            $linhas[] = ['codfuncionario' => $f, 'nome' => (string)$fu['NOME'], 'nrregistro' => (string)$fu['NRREGISTRO'], 'setor' => (string)$fu['SETOR'], 'datainicio' => self::data($fu['DATA_INICIO'] ?? null),
                'tipofunc' => (string)$fu['TIPO_FUNCIONARIO'], 'codfornecedor' => (int)$fu['CODFORNECEDOR'], 'fornecedor' => (string)$fu['RAZAOSOCIAL'], 'cnpj' => (string)$fu['CNPJ_CPF'],
                'unidade' => (string)$fu['DSCUNIDADE'], 'situacao' => $chave, 'situacao_rotulo' => $rotSit[$chave], 'emdia' => $emDia . '/' . $exigidos, 'pct' => $exigidos ? (int)round($emDia / $exigidos * 100) : 100,
                'integracao' => (string)($sit[$f]['I:0']['validade'] ?? ''), 'pendencias' => implode('; ', $pend), 'celulas' => $cel];
        }
        // Só as colunas exigidas de algum funcionário do filtro.
        $col = array_intersect_key($col, $usadas);
        foreach ($linhas as &$l) $l['celulas'] = array_values(array_intersect_key($l['celulas'], $col));
        unset($l);
        $tipos = array_keys($tipos); sort($tipos, SORT_NATURAL | SORT_FLAG_CASE);
        return ['colunas' => array_values($col), 'linhas' => $linhas, 'tipos' => $tipos];
    }

    /** Pedido da tela sobre o unificado: tipo de funcionário, filtro rápido, busca, ordenação. */
    public function aplicarUnificado(array $u, array $p): array
    {
        $linhas = $u['linhas'];
        $tipo = trim((string)($p['tipofunc'] ?? ''));
        if ($tipo !== '') {
            $linhas = array_values(array_filter($linhas, static fn($l) => $l['tipofunc'] === $tipo));
            // Com um tipo escolhido, ficam só as colunas que algum funcionário desse tipo tem como exigidas.
            $manter = [];
            foreach ($linhas as $l) foreach ($l['celulas'] as $i => $c) if (!in_array($c['s'], ['X', 'N'], true)) $manter[$i] = true;
            $manter[0] = true;
            $u['colunas'] = array_values(array_intersect_key($u['colunas'], $manter));
            foreach ($linhas as &$l) $l['celulas'] = array_values(array_intersect_key($l['celulas'], $manter));
            unset($l);
        }
        $contagem = ['todos' => count($linhas), 'bloq' => 0, 'vence' => 0, 'lib' => 0];
        foreach ($linhas as $l) $contagem[$l['situacao']]++;
        $chip = in_array((string)($p['chip'] ?? ''), ['bloq', 'vence', 'lib'], true) ? (string)$p['chip'] : 'todos';
        if ($chip !== 'todos') $linhas = array_values(array_filter($linhas, static fn($l) => $l['situacao'] === $chip));
        $busca = self::semAcento(mb_substr(trim((string)($p['busca_grid'] ?? '')), 0, 100));
        if ($busca !== '') $linhas = array_values(array_filter($linhas, static function ($l) use ($busca): bool {
            foreach (['nome', 'fornecedor', 'nrregistro', 'setor', 'tipofunc', 'unidade'] as $c) if (str_contains(self::semAcento($l[$c]), $busca)) return true;
            return false;
        }));
        $col = in_array((string)($p['ordem_col'] ?? ''), ['nome', 'fornecedor', 'situacao', 'emdia'], true) ? (string)$p['ordem_col'] : '';
        $dir = (string)($p['ordem_dir'] ?? '') === 'desc' ? -1 : 1;
        $peso = ['bloq' => 0, 'vence' => 1, 'lib' => 2];
        usort($linhas, static fn($a, $b) => match ($col) {
            'situacao' => ($peso[$a['situacao']] <=> $peso[$b['situacao']]) * $dir,
            'emdia' => ($a['pct'] <=> $b['pct']) * $dir,
            'fornecedor' => strnatcasecmp(self::semAcento($a['fornecedor']), self::semAcento($b['fornecedor'])) * $dir ?: strnatcasecmp($a['nome'], $b['nome']),
            'nome' => strnatcasecmp(self::semAcento($a['nome']), self::semAcento($b['nome'])) * $dir,
            default => ($peso[$a['situacao']] <=> $peso[$b['situacao']]) ?: strnatcasecmp($a['fornecedor'], $b['fornecedor']) ?: strnatcasecmp($a['nome'], $b['nome']),
        });
        return ['colunas' => $u['colunas'], 'linhas' => $linhas, 'contagem' => $contagem, 'chip' => $chip, 'ordem' => ['col' => $col, 'dir' => $dir === -1 ? 'desc' : 'asc'],
            'fornecedores' => count(array_unique(array_map(static fn($l) => $l['codfornecedor'], $u['linhas'])))];
    }

    // ------------------------------------------------------------------ pedido (tela, planilha e envio semanal)

    /** Normaliza os parâmetros de um pedido (tela, visão salva ou planilha). */
    public static function normalizarPedido(array $e, bool $externo): array
    {
        $inteiro = static fn($v): ?int => ($n = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) === false ? null : (int)$n;
        $rel = (string)($e['relatorio'] ?? 'docfor');
        if ($rel !== 'unificado' && !isset(self::relatorios()[$rel])) $rel = 'docfor';
        $colunas = $e['colunas'] ?? [];
        if (is_string($colunas)) $colunas = json_decode($colunas, true);
        $colunas = is_array($colunas) ? array_slice(array_filter(array_map(static fn($v) => is_scalar($v) ? mb_substr(trim((string)$v), 0, 100) : '', $colunas), 'strlen'), 0, 20, true) : [];
        return [
            'relatorio' => $rel,
            'filtros' => [
                'unidade' => $inteiro($e['unidade'] ?? ($e['filtros']['unidade'] ?? null)),
                'tipo' => $externo ? null : $inteiro($e['tipo'] ?? ($e['filtros']['tipo'] ?? null)),
                'busca' => mb_substr(trim((string)($e['busca'] ?? ($e['filtros']['busca'] ?? ''))), 0, 100),
            ],
            'chip' => preg_match('/^(cor:)?[A-Za-z0-9]{1,20}$/', (string)($e['chip'] ?? '')) ? (string)$e['chip'] : 'todos',
            'colunas' => $colunas,
            'busca_grid' => mb_substr(trim((string)($e['busca_grid'] ?? '')), 0, 100),
            'agrupar' => preg_match('/^[a-z]{0,20}$/', (string)($e['agrupar'] ?? '')) ? (string)($e['agrupar'] ?? '') : '',
            'ordem_col' => preg_match('/^[a-z_]{0,20}$/', (string)($e['ordem_col'] ?? '')) ? (string)($e['ordem_col'] ?? '') : '',
            'ordem_dir' => (string)($e['ordem_dir'] ?? '') === 'desc' ? 'desc' : 'asc',
            'tipofunc' => mb_substr(trim((string)($e['tipofunc'] ?? '')), 0, 120),
        ];
    }

    /** Executa um pedido inteiro (sem paginação): usado pela planilha e pelo envio semanal. */
    public function executar(PDO $pdo, int $usuario, bool|int $externo, array $pedido): array
    {
        if ($pedido['relatorio'] === 'unificado') {
            [$f, $d, $t, $s] = self::lerUnificado($pdo, $usuario, $externo, $pedido['filtros']);
            return ['unificado' => true] + $this->aplicarUnificado($this->unificado($f, $d, $t, $s, self::lerBloqueados($pdo)), $pedido);
        }
        $def = self::relatorios()[$pedido['relatorio']];
        $todas = $this->linhasRelatorio(self::lerItens($pdo, $usuario, $externo, $pedido['filtros'], $def['categoria']));
        return ['unificado' => false, 'resumo' => $this->resumo($pedido['relatorio'], $todas)] + $this->aplicar($pedido['relatorio'], $todas, $pedido);
    }

    /** Nome do relatório para títulos e alertas. */
    public function nome(string $relatorio): string
    {
        if ($relatorio === 'unificado') return $this->L(['pt' => 'Relatório unificado de funcionários', 'en' => 'Unified employee report', 'es' => 'Informe unificado de empleados']);
        return $this->L(self::relatorios()[$relatorio]['nome'] ?? ['pt' => $relatorio]);
    }
}
