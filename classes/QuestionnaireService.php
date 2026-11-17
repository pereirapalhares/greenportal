<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkflowService.php';
require_once __DIR__ . '/TaskService.php';
require_once __DIR__ . '/QuestionarioVersaoService.php';

/**
 * Motor P2 de questionários: catálogo universal e execução isolada por tenant.
 * Versões por empresa (migração 291): a empresa usa a versão publicada dela, senão a universal publicada
 * (vw_amb_questionario_publicadas). Criação de versão, merge e publicação: classes/QuestionarioVersaoService.php.
 */
final class QuestionnaireService
{
    public function __construct(
        private readonly PDO $tenantPdo,
        private readonly PDO $universalPdo,
        private readonly ?int $codempresa = null
    ) {
    }

    private function empresa(): int
    {
        return $this->codempresa ?? (int)($_SESSION['tenant']['codempresa'] ?? 0);
    }

    /** Publicadas vigentes para a empresa: uma por questionário (a da empresa, senão a universal) */
    private function vigentes(string $where, array $params): array
    {
        $emp = $this->empresa();
        try {
            $query = $this->universalPdo->prepare('SELECT * FROM vw_amb_questionario_publicadas
                WHERE ativo=1 AND codpublicacao IS NOT NULL AND codempresa IN (0, ' . $emp . ')' . $where . '
                ORDER BY modulo, nome_pt, codempresa DESC, versao DESC');
            $query->execute($params);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {   // sem a migração 291: só a universal
            $query = $this->universalPdo->prepare('SELECT * FROM vw_amb_questionario_catalogo WHERE ativo=1 AND codpublicacao IS NOT NULL' . $where . ' ORDER BY modulo, nome_pt, versao DESC');
            $query->execute($params);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        }
        $vistos = []; $r = [];
        foreach ($rows as $row) { if (isset($vistos[$row['codquestionario']])) continue; $vistos[$row['codquestionario']] = true; $r[] = $row; }
        return $r;
    }

    public function catalog(?string $module = null): array
    {
        $params = [];
        $where = '';
        if ($module !== null && trim($module) !== '') {
            $where = ' AND modulo=:modulo';
            $params['modulo'] = trim($module);
        }
        return $this->vigentes($where, $params);
    }

    /** Catálogo administrativo: inclui a versão mais recente, inclusive rascunhos. */
    public function catalogAdmin(?string $module = null): array
    {
        $sql = 'SELECT v.codversao,v.versao,v.status,v.percentual_minimo,
                       q.codquestionario,q.codigo,q.nome_pt,q.nome_en,q.nome_es,q.descricao_pt,q.modulo,
                       CASE WHEN v.status="PUBLICADA" THEN v.codversao ELSE NULL END AS codpublicacao
                  FROM amb_questionario q
                  JOIN amb_questionario_versao v ON v.codquestionario=q.codquestionario
                 WHERE q.ativo=1
                   AND v.versao=(SELECT MAX(v2.versao) FROM amb_questionario_versao v2 WHERE v2.codquestionario=q.codquestionario)';
        $params = [];
        if ($module !== null && trim($module) !== '') {
            $sql .= ' AND UPPER(COALESCE(q.modulo,\'\'))=:modulo';
            $params['modulo'] = strtoupper(trim($module));
        }
        $sql .= ' ORDER BY q.modulo, q.nome_pt, v.versao DESC';
        $query = $this->universalPdo->prepare($sql);
        $query->execute($params);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Catálogo publicado filtrado pelo domínio que iniciou a execução. */
    public function catalogForContext(string $context): array
    {
        $modules = [
            'LEGAL' => ['LEGAL', 'GREEN_LEGAL'],
            'SGA' => ['SGA', 'GREEN_SGA'],
            'QUALITY' => ['QUALITY', 'QUALIDADE', 'GREEN_QUALITY'],
            'WORKFLOW' => ['WORKFLOW', 'GREEN_WORKFLOW'],
            'SUPPLIER' => ['SUPPLIER', 'GREEN_SUPPLIER', 'FORNECEDOR'],
            'ESG' => ['ESG', 'GREEN_ESG'],
            'PROJECTS' => ['PROJECTS', 'PROJECT', 'GREEN_PROJECTS'],
            'EMPLOYEE_INTEGRATION' => ['EMPLOYEE_INTEGRATION', 'EMPLOYEE', 'GREEN_EMPLOYEE'],
        ];
        $context = strtoupper(trim($context));
        if (!isset($modules[$context])) throw new InvalidArgumentException('Contexto de questionário inválido.');
        $placeholders = implode(',', array_fill(0, count($modules[$context]), '?'));
        return $this->vigentes(' AND UPPER(COALESCE(modulo, \'\')) IN (' . $placeholders . ')', $modules[$context]);
    }

    public function contextWorkflow(string $context): string
    {
        $context = strtoupper(trim($context));
        return match ($context) {
            'LEGAL' => 'LEGAL_QUESTIONNAIRE',
            'SGA' => 'SGA_QUESTIONNAIRE',
            'QUALITY' => 'QUALITY_QUESTIONNAIRE',
            'WORKFLOW' => 'WORKFLOW_QUESTIONNAIRE',
            'SUPPLIER' => 'SUPPLIER_QUESTIONNAIRE',
            'ESG' => 'ESG_QUESTIONNAIRE',
            'PROJECTS' => 'PROJECTS_QUESTIONNAIRE',
            'EMPLOYEE_INTEGRATION' => 'EMPLOYEE_INTEGRATION_QUESTIONNAIRE',
            default => throw new InvalidArgumentException('Contexto de questionário inválido.'),
        };
    }

    public function createDraft(array $data, int $userId): array
    {
        $code = strtoupper(trim((string)($data['codigo'] ?? '')));
        $name = trim((string)($data['nome_pt'] ?? ''));
        if (!preg_match('/^[A-Z0-9_]{3,100}$/', $code)) {
            throw new InvalidArgumentException('O código deve conter somente letras, números e sublinhado.');
        }
        if ($name === '') {
            throw new InvalidArgumentException('O nome do questionário é obrigatório.');
        }
        $this->universalPdo->beginTransaction();
        try {
            $insert = $this->universalPdo->prepare(
                'INSERT INTO amb_questionario(codigo,nome_pt,nome_en,nome_es,descricao_pt,modulo,criado_por)
                 VALUES (:codigo,:nome_pt,:nome_en,:nome_es,:descricao,:modulo,:usuario)'
            );
            $insert->execute([
                'codigo' => $code,
                'nome_pt' => substr($name, 0, 180),
                'nome_en' => $this->nullable((string)($data['nome_en'] ?? ''), 180),
                'nome_es' => $this->nullable((string)($data['nome_es'] ?? ''), 180),
                'descricao' => $this->nullable((string)($data['descricao_pt'] ?? ''), 4000),
                'modulo' => $this->nullable((string)($data['modulo'] ?? ''), 80),
                'usuario' => $userId,
            ]);
            $questionnaireId = (int)$this->universalPdo->lastInsertId();
            $version = $this->universalPdo->prepare(
                'INSERT INTO amb_questionario_versao(codquestionario,versao,status,percentual_minimo,criado_por)
                 VALUES (:questionario,1,\'RASCUNHO\',:minimo,:usuario)'
            );
            $version->execute([
                'questionario' => $questionnaireId,
                'minimo' => !isset($data['percentual_minimo']) || $data['percentual_minimo'] === '' ? null : (float)$data['percentual_minimo'],
                'usuario' => $userId,
            ]);
            $versionId = (int)$this->universalPdo->lastInsertId();
            $this->universalPdo->commit();
            $this->audit($userId, 'CRIACAO', 'amb_questionario', (string)$questionnaireId, ['codigo' => $code, 'codversao' => $versionId]);
            return ['codquestionario' => $questionnaireId, 'codversao' => $versionId, 'codigo' => $code, 'status' => 'RASCUNHO'];
        } catch (Throwable $exception) {
            if ($this->universalPdo->inTransaction()) $this->universalPdo->rollBack();
            throw $exception;
        }
    }

    public function builder(int $versionId): array
    {
        $query = $this->universalPdo->prepare(
            'SELECT v.codversao,v.versao,v.status,v.percentual_minimo,q.codquestionario,q.codigo,q.nome_pt,q.modulo
             FROM amb_questionario_versao v JOIN amb_questionario q ON q.codquestionario=v.codquestionario
             WHERE v.codversao=:versao'
        );
        $query->execute(['versao' => $versionId]);
        $version = $query->fetch(PDO::FETCH_ASSOC);
        if (!$version) throw new InvalidArgumentException('Versão não localizada.');
        $blocks = [];
        try {
            $b = $this->universalPdo->prepare('SELECT codbloco,codigo,titulo_pt,ordem FROM vw_amb_questionario_blocos WHERE codversao=:versao AND ativo=1 ORDER BY ordem,codbloco');
            $b->execute(['versao' => $versionId]);
            foreach ($b->fetchAll(PDO::FETCH_ASSOC) as $row) $blocks[(int)$row['codbloco']] = ['codbloco' => (int)$row['codbloco'], 'codigo' => (string)$row['codigo'], 'titulo_pt' => (string)$row['titulo_pt']];
        } catch (Throwable) {}
        $version['blocos'] = array_values($blocks);
        $fixed = (new QuestionarioVersaoService($this->universalPdo))->codigosFixos($versionId);
        $questions = $this->universalPdo->prepare(
            'SELECT * FROM vw_amb_questionario_perguntas WHERE codversao=:versao ORDER BY ordem,codpergunta,ordem_opcao,codopcao'
        );
        $questions->execute(['versao' => $versionId]);
        $grouped = [];
        foreach ($questions->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)$row['codpergunta'];
            if (!isset($grouped[$id])) {
                $grouped[$id] = [
                    'codpergunta' => $id,
                    'codigo' => (string)$row['codigo'],
                    'codigo_hierarquico' => ($row['codigo_hierarquico'] ?? null) === null ? null : (string)$row['codigo_hierarquico'],
                    'codbloco' => $row['codbloco'] === null ? null : (int)$row['codbloco'],
                    'bloco' => $row['codbloco'] !== null && isset($blocks[(int)$row['codbloco']]) ? $blocks[(int)$row['codbloco']]['titulo_pt'] : '',
                    'peso' => (float)$row['peso'],
                    'codigo_fixo' => isset($fixed[strtoupper((string)$row['codigo'])]) || preg_match('/(^|\.)PILAR\.|\.TEMAS$|\.CATEGORIAS$|^GERAL\.UNIDADES$/', strtoupper((string)$row['codigo'])) === 1,
                    'texto_pt' => (string)$row['texto_pt'],
                    'tipo' => (string)$row['tipo'],
                    'obrigatoria' => (int)$row['obrigatoria'],
                    'ordem' => (int)$row['ordem'],
                    'opcoes' => [],
                ];
            }
            if ($row['codopcao'] !== null) $grouped[$id]['opcoes'][] = [
                'codopcao' => (int)$row['codopcao'], 'codigo' => (string)$row['codigo_opcao'],
                'rotulo_pt' => (string)$row['rotulo_pt'], 'valor' => $row['valor'],
                'pontuacao' => $row['pontuacao'] === null ? null : (float)$row['pontuacao'],
            ];
        }
        $version['perguntas'] = array_values($grouped);
        return $version;
    }

    public function addQuestion(int $versionId, array $data, int $userId): int
    {
        $this->assertDraft($versionId);
        $code = strtoupper(trim((string)($data['codigo'] ?? '')));
        if ($code === '') $code = $this->newQuestionCode($versionId, $data['codbloco'] ?? null);   // código é interno: a tela não mostra
        $text = trim((string)($data['texto_pt'] ?? ''));
        $type = strtoupper(trim((string)($data['tipo'] ?? 'TEXTO')));
        $hierarchy = $this->validateHierarchy($data['codigo_hierarquico'] ?? '');
        $allowed = ['TEXTO','NUMERO','DATA','SIM_NAO','UNICA_ESCOLHA','MULTIPLA_ESCOLHA','ANEXO'];
        if (!preg_match('/^[A-Z0-9_.]{2,100}$/', $code) || $text === '' || !in_array($type, $allowed, true)) {
            throw new InvalidArgumentException('Código, texto ou tipo da pergunta inválido.');
        }
        $query = $this->universalPdo->prepare(
            'INSERT INTO amb_questionario_pergunta(codversao,codbloco,codigo,codigo_hierarquico,texto_pt,tipo,obrigatoria,ordem,peso)
             VALUES (:versao,:bloco,:codigo,:hierarquia,:texto,:tipo,:obrigatoria,:ordem,:peso)'
        );
        $query->execute([
            'versao' => $versionId, 'bloco' => $this->blockOf($versionId, $data['codbloco'] ?? null), 'codigo' => $code, 'hierarquia' => $hierarchy, 'texto' => $text, 'tipo' => $type,
            'obrigatoria' => !empty($data['obrigatoria']) ? 1 : 0,
            'ordem' => (int)($data['ordem'] ?? 0), 'peso' => (float)($data['peso'] ?? 1),
        ]);
        $id = (int)$this->universalPdo->lastInsertId();
        $this->audit($userId, 'INCLUSAO', 'amb_questionario_pergunta', (string)$id, ['codversao' => $versionId, 'codigo' => $code]);
        return $id;
    }

    public function updateQuestion(int $questionId, array $data, int $userId): bool
    {
        $versionId = $this->questionVersion($questionId);
        $this->assertDraft($versionId);
        [$code, $text, $type] = $this->validateQuestion($data);
        $hierarchy = $this->validateHierarchy($data['codigo_hierarquico'] ?? '');
        $old = $this->universalPdo->prepare('SELECT codigo FROM amb_questionario_pergunta WHERE codpergunta=:pergunta');
        $old->execute(['pergunta' => $questionId]);
        (new QuestionarioVersaoService($this->universalPdo))->validarTrocaCodigo($versionId, (string)$old->fetchColumn(), $code);
        $update = $this->universalPdo->prepare(
            'UPDATE amb_questionario_pergunta
                SET codigo=:codigo,codbloco=:bloco,codigo_hierarquico=:hierarquia,texto_pt=:texto,tipo=:tipo,obrigatoria=:obrigatoria,ordem=:ordem,peso=:peso
              WHERE codpergunta=:pergunta AND codversao=:versao'
        );
        $update->execute([
            'codigo' => $code, 'bloco' => $this->blockOf($versionId, $data['codbloco'] ?? null), 'hierarquia' => $hierarchy, 'texto' => $text, 'tipo' => $type,
            'obrigatoria' => !empty($data['obrigatoria']) ? 1 : 0,
            'ordem' => (int)($data['ordem'] ?? 0), 'peso' => (float)($data['peso'] ?? 1),
            'pergunta' => $questionId, 'versao' => $versionId,
        ]);
        $this->audit($userId, 'ALTERACAO', 'amb_questionario_pergunta', (string)$questionId, ['codversao' => $versionId, 'codigo' => $code]);
        return $update->rowCount() >= 0;
    }

    public function deleteQuestion(int $questionId, int $userId): bool
    {
        $versionId = $this->questionVersion($questionId);
        $this->assertDraft($versionId);
        $delete = $this->universalPdo->prepare('UPDATE amb_questionario_pergunta SET ativo=0 WHERE codpergunta=:pergunta AND codversao=:versao');
        $delete->execute(['pergunta' => $questionId, 'versao' => $versionId]);
        $this->audit($userId, 'EXCLUSAO', 'amb_questionario_pergunta', (string)$questionId, ['codversao' => $versionId]);
        return true;
    }

    private function questionVersion(int $questionId): int
    {
        $query = $this->universalPdo->prepare('SELECT codversao FROM amb_questionario_pergunta WHERE codpergunta=:pergunta AND ativo=1');
        $query->execute(['pergunta' => $questionId]);
        $versionId = (int)$query->fetchColumn();
        if ($versionId <= 0) throw new InvalidArgumentException('Pergunta não localizada.');
        return $versionId;
    }

    private function validateQuestion(array $data): array
    {
        $code = strtoupper(trim((string)($data['codigo'] ?? '')));
        $text = trim((string)($data['texto_pt'] ?? ''));
        $type = strtoupper(trim((string)($data['tipo'] ?? 'TEXTO')));
        $allowed = ['TEXTO','NUMERO','DATA','SIM_NAO','UNICA_ESCOLHA','MULTIPLA_ESCOLHA','ANEXO'];
        if (!preg_match('/^[A-Z0-9_.]{2,100}$/', $code) || $text === '' || !in_array($type, $allowed, true)) {
            throw new InvalidArgumentException('Código, texto ou tipo da pergunta inválido.');
        }
        return [$code, $text, $type];
    }

    /**
     * Código interno de pergunta nova: <BLOCO>.P<n> (ou P<n> sem bloco), único em todas as versões do questionário, para
     * não colidir no merge com uma pergunta de outra versão.
     */
    private function newQuestionCode(int $versionId, mixed $block): string
    {
        $prefix = '';
        if ((int)$block > 0) {
            $b = $this->universalPdo->prepare('SELECT codigo FROM vw_amb_questionario_blocos WHERE codbloco=:bloco AND codversao=:versao');
            $b->execute(['bloco' => (int)$block, 'versao' => $versionId]);
            $prefix = strtoupper((string)$b->fetchColumn());
            $prefix = $prefix !== '' ? substr((string)preg_replace('/[^A-Z0-9_.]/', '_', $prefix), 0, 80) . '.' : '';
        }
        $used = array_flip(array_map('strtoupper', array_keys((new QuestionarioVersaoService($this->universalPdo))->codigosFixos($versionId))));
        $mine = $this->universalPdo->prepare('SELECT codigo FROM vw_amb_questionario_perguntas WHERE codversao=:versao');
        $mine->execute(['versao' => $versionId]);
        foreach ($mine->fetchAll(PDO::FETCH_COLUMN) as $c) $used[strtoupper((string)$c)] = true;
        for ($n = 1; ; $n++) { $code = $prefix . 'P' . $n; if (!isset($used[$code])) return $code; }
    }

    /** Bloco da pergunta: precisa ser da mesma versão (vazio = sem bloco) */
    private function blockOf(int $versionId, mixed $block): ?int
    {
        $block = (int)$block;
        if ($block <= 0) return null;
        $q = $this->universalPdo->prepare('SELECT codbloco FROM vw_amb_questionario_blocos WHERE codbloco=:bloco AND codversao=:versao');
        $q->execute(['bloco' => $block, 'versao' => $versionId]);
        if (!$q->fetchColumn()) throw new InvalidArgumentException('Bloco não pertence a esta versão.');
        return $block;
    }

    private function validateHierarchy(mixed $value): ?string
    {
        $hierarchy = trim((string)$value);
        if ($hierarchy === '') return null;
        if (!preg_match('/^\d+(?:\.\d+)*\.?$/', $hierarchy)) {
            throw new InvalidArgumentException('A classificação deve usar apenas números e pontos, por exemplo 1. ou 1.1.');
        }
        return $hierarchy;
    }

    public function addOption(int $questionId, array $data, int $userId): int
    {
        $query = $this->universalPdo->prepare(
            "SELECT p.codversao FROM amb_questionario_pergunta p JOIN amb_questionario_versao v ON v.codversao=p.codversao
             WHERE p.codpergunta=:pergunta AND v.status='RASCUNHO'"
        );
        $query->execute(['pergunta' => $questionId]);
        $code = strtoupper(trim((string)($data['codigo'] ?? '')));
        $label = trim((string)($data['rotulo_pt'] ?? ''));
        if (!preg_match('/^[A-Z0-9_]{1,100}$/', $code) || $label === '') throw new InvalidArgumentException('Código e rótulo da opção são obrigatórios.');
        if (!$query->fetchColumn()) throw new DomainException('Opções só podem ser incluídas em versões em rascunho.');
        $insert = $this->universalPdo->prepare(
            'INSERT INTO amb_questionario_opcao(codpergunta,codigo,rotulo_pt,valor,pontuacao,ordem)
             VALUES (:pergunta,:codigo,:rotulo,:valor,:pontuacao,:ordem)'
        );
        $insert->execute([
            'pergunta' => $questionId,
            'codigo' => $code,
            'rotulo' => $label,
            'valor' => $this->nullable((string)($data['valor'] ?? ''), 180),
            'pontuacao' => ($data['pontuacao'] ?? '') === '' ? null : (float)$data['pontuacao'],
            'ordem' => (int)($data['ordem'] ?? 0),
        ]);
        $id = (int)$this->universalPdo->lastInsertId();
        $this->audit($userId, 'INCLUSAO', 'amb_questionario_opcao', (string)$id, ['codpergunta' => $questionId]);
        return $id;
    }

    public function start(
        string $code,
        int $userId,
        ?int $unitId = null,
        ?string $originEntity = null,
        ?string $originId = null,
        ?string $idempotencyKey = null,
        ?string $workflowCode = null
    ): array {
        $published = $this->published($code);
        if ($published === null) {
            throw new InvalidArgumentException('Questionário não publicado: ' . $code);
        }
        $key = trim((string)$idempotencyKey);
        if ($key === '') {
            $key = sprintf(
                'QUESTIONNAIRE:%d:%s:%s:%d',
                (int)$published['codpublicacao'],
                trim((string)$originEntity),
                trim((string)$originId),
                $userId
            );
        }
        $key = substr($key, 0, 191);

        $existing = $this->findByKey($key);
        $insert = $this->tenantPdo->prepare(
            "INSERT INTO amb_questionario_execucoes
                (chave_idempotencia,codpublicacao,codquestionario,codversao,codigo_questionario,
                 codusuario,codunidade,origem_entidade,origem_id,status)
             VALUES (:chave,:publicacao,:questionario,:versao,:codigo,:usuario,:unidade,:entidade,:origem,'ABERTA')
             ON DUPLICATE KEY UPDATE codexecucao=LAST_INSERT_ID(codexecucao)"
        );
        $insert->execute([
            'chave' => $key,
            'publicacao' => (int)$published['codpublicacao'],
            'questionario' => (int)$published['codquestionario'],
            'versao' => (int)$published['codversao'],
            'codigo' => (string)$published['codigo'],
            'usuario' => $userId,
            'unidade' => $unitId,
            'entidade' => $this->nullable($originEntity, 100),
            'origem' => $this->nullable($originId, 100),
        ]);
        $execution = $this->findByKey($key);
        if ($execution === null) {
            throw new RuntimeException('Execução do questionário não localizada após gravação.');
        }

        if ($workflowCode !== null && trim($workflowCode) !== '') {
            $workflow = new WorkflowService($this->tenantPdo, new TaskService($this->tenantPdo));
            $workflow->start(
                trim($workflowCode),
                'amb_questionario_execucoes',
                (string)$execution['codexecucao'],
                $userId,
                $userId
            );
        }
        if ($existing === null) {
            $this->audit($userId, 'INCLUSAO', 'amb_questionario_execucoes', (string)$execution['codexecucao'], [
                'codigo_questionario' => $published['codigo'],
                'codversao' => (int)$published['codversao'],
            ]);
        }
        return $execution;
    }

    public function questions(int $executionId, string $language = 'pt'): array
    {
        $execution = $this->execution($executionId);
        if ($execution === null) {
            throw new InvalidArgumentException('Execução do questionário não localizada.');
        }
        $query = $this->universalPdo->prepare(
            'SELECT * FROM vw_amb_questionario_perguntas
             WHERE codversao=:versao ORDER BY codbloco,ordem,codpergunta,ordem_opcao,codopcao'
        );
        $query->execute(['versao' => (int)$execution['codversao']]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $questions = [];
        foreach ($rows as $row) {
            $id = (int)$row['codpergunta'];
            if (!isset($questions[$id])) {
                $text = $this->localized($row, 'texto', $language);
                $questions[$id] = [
                    'codpergunta' => $id,
                    'codversao' => (int)$row['codversao'],
                    'codbloco' => $row['codbloco'] === null ? null : (int)$row['codbloco'],
                    'codigo' => (string)$row['codigo'],
                    'texto' => $text,
                    'tipo' => (string)$row['tipo'],
                    'obrigatoria' => (bool)$row['obrigatoria'],
                    'ordem' => (int)$row['ordem'],
                    'peso' => (float)$row['peso'],
                    'opcoes' => [],
                ];
            }
            if ($row['codopcao'] !== null) {
                $questions[$id]['opcoes'][] = [
                    'codopcao' => (int)$row['codopcao'],
                    'codigo' => (string)$row['codigo_opcao'],
                    'rotulo' => $this->localized($row, 'rotulo', $language),
                    'valor' => $row['valor'],
                    'pontuacao' => $row['pontuacao'] === null ? null : (float)$row['pontuacao'],
                    'ordem' => (int)$row['ordem_opcao'],
                ];
            }
        }
        return array_values($questions);
    }

    public function submit(int $executionId, int $userId, array $answers, string $language = 'pt'): array
    {
        $this->tenantPdo->beginTransaction();
        try {
            $query = $this->tenantPdo->prepare(
                'SELECT * FROM amb_questionario_execucoes WHERE codexecucao=:id FOR UPDATE'
            );
            $query->execute(['id' => $executionId]);
            $execution = $query->fetch(PDO::FETCH_ASSOC);
            if (!$execution) {
                throw new InvalidArgumentException('Execução do questionário não localizada.');
            }
            if ((int)$execution['codusuario'] !== $userId) {
                throw new DomainException('A execução pertence a outro usuário.');
            }
            if ((string)$execution['status'] !== 'ABERTA') {
                throw new DomainException('A execução não está aberta para respostas.');
            }

            $published = $this->published((string)$execution['codigo_questionario']);
            if ($published === null || (int)$published['codversao'] !== (int)$execution['codversao']) {
                throw new DomainException('A versão publicada do questionário não está disponível.');
            }
            $questions = $this->questions((int)$executionId, $language);
            $byKey = [];
            foreach ($questions as $question) {
                $byKey[(string)$question['codpergunta']] = $question;
                $byKey[(string)$question['codigo']] = $question;
            }

            $normalized = [];
            foreach ($answers as $key => $value) {
                $question = $byKey[(string)$key] ?? null;
                if ($question === null) {
                    throw new InvalidArgumentException('Pergunta não pertence ao questionário: ' . $key);
                }
                $normalized[(int)$question['codpergunta']] = $this->normalizeValue($value);
            }
            foreach ($questions as $question) {
                $value = $normalized[(int)$question['codpergunta']] ?? null;
                if ((bool)$question['obrigatoria'] && $this->isEmptyValue($value)) {
                    throw new InvalidArgumentException('Pergunta obrigatória sem resposta: ' . $question['codigo']);
                }
                if ($value !== null) {
                    $save = $this->tenantPdo->prepare(
                        'INSERT INTO amb_questionario_respostas
                            (codexecucao,codpergunta,codigo_pergunta,valor_json,respondido_por)
                         VALUES (:execucao,:pergunta,:codigo,:valor,:usuario)
                         ON DUPLICATE KEY UPDATE valor_json=VALUES(valor_json),respondido_por=VALUES(respondido_por),respondido_em=CURRENT_TIMESTAMP'
                    );
                    $save->execute([
                        'execucao' => $executionId,
                        'pergunta' => (int)$question['codpergunta'],
                        'codigo' => (string)$question['codigo'],
                        'valor' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'usuario' => $userId,
                    ]);
                }
            }

            [$score, $maxScore, $scoredCount] = $this->score($questions, $normalized);
            $answered = count(array_filter($normalized, fn(mixed $value): bool => !$this->isEmptyValue($value)));
            $total = count($questions);
            $percent = $maxScore > 0
                ? round(($score / $maxScore) * 100, 3)
                : ($total > 0 ? round(($answered / $total) * 100, 3) : 0.0);
            $minimum = $published['percentual_minimo'] === null ? null : (float)$published['percentual_minimo'];
            $approved = $minimum === null ? null : (int)($percent >= $minimum);
            $details = [
                'codpublicacao' => (int)$published['codpublicacao'],
                'codversao' => (int)$published['codversao'],
                'questoes_pontuadas' => $scoredCount,
                'percentual_minimo' => $minimum,
            ];
            $result = $this->tenantPdo->prepare(
                'INSERT INTO amb_questionario_resultados
                    (codexecucao,total_perguntas,total_respondidas,pontuacao,percentual,aprovado,detalhes_json)
                 VALUES (:execucao,:total,:respondidas,:pontuacao,:percentual,:aprovado,:detalhes)
                 ON DUPLICATE KEY UPDATE total_perguntas=VALUES(total_perguntas),total_respondidas=VALUES(total_respondidas),
                    pontuacao=VALUES(pontuacao),percentual=VALUES(percentual),aprovado=VALUES(aprovado),
                    detalhes_json=VALUES(detalhes_json),calculado_em=CURRENT_TIMESTAMP'
            );
            $result->execute([
                'execucao' => $executionId,
                'total' => $total,
                'respondidas' => $answered,
                'pontuacao' => $score,
                'percentual' => $percent,
                'aprovado' => $approved,
                'detalhes' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $close = $this->tenantPdo->prepare(
                "UPDATE amb_questionario_execucoes SET status='CONCLUIDA',concluida_em=CURRENT_TIMESTAMP WHERE codexecucao=:id"
            );
            $close->execute(['id' => $executionId]);
            $this->audit($userId, 'CONCLUSAO', 'amb_questionario_execucoes', (string)$executionId, $details);
            $this->tenantPdo->commit();
            $this->advanceWorkflowAfterSubmit($executionId, $userId);
            return [
                'codexecucao' => $executionId,
                'status' => 'CONCLUIDA',
                'total_perguntas' => $total,
                'total_respondidas' => $answered,
                'pontuacao' => $score,
                'percentual' => $percent,
                'aprovado' => $approved,
            ];
        } catch (Throwable $exception) {
            if ($this->tenantPdo->inTransaction()) {
                $this->tenantPdo->rollBack();
            }
            throw $exception;
        }
    }

    public function details(int $executionId, int $userId): array
    {
        $execution = $this->execution($executionId);
        if ($execution === null || (int)$execution['codusuario'] !== $userId) {
            throw new DomainException('Execução não pertence ao usuário autenticado.');
        }
        $result = $this->tenantPdo->prepare('SELECT * FROM amb_questionario_resultados WHERE codexecucao=:id');
        $result->execute(['id' => $executionId]);
        $answers = $this->tenantPdo->prepare('SELECT * FROM amb_questionario_respostas WHERE codexecucao=:id ORDER BY codpergunta');
        $answers->execute(['id' => $executionId]);
        $evidence = $this->tenantPdo->prepare('SELECT * FROM amb_questionario_evidencias WHERE codexecucao=:id ORDER BY codevidencia');
        $evidence->execute(['id' => $executionId]);
        return [
            'execution' => $execution,
            'result' => $result->fetch(PDO::FETCH_ASSOC) ?: null,
            'answers' => $answers->fetchAll(PDO::FETCH_ASSOC),
            'evidences' => $evidence->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public function addEvidence(int $executionId, int $userId, ?int $questionId, string $entity, string $recordId): array
    {
        $execution = $this->execution($executionId);
        if ($execution === null || (int)$execution['codusuario'] !== $userId) {
            throw new DomainException('Execução não pertence ao usuário autenticado.');
        }
        $entity = strtoupper(trim($entity));
        $recordId = trim($recordId);
        if (!preg_match('/^[A-Z0-9_.:-]{2,100}$/', $entity) || $recordId === '') {
            throw new InvalidArgumentException('Evidência inválida.');
        }
        $find = $this->tenantPdo->prepare(
            'SELECT * FROM amb_questionario_evidencias WHERE codexecucao=:execucao
             AND ((codpergunta=:pergunta) OR (codpergunta IS NULL AND :pergunta_nulo IS NULL))
             AND entidade=:entidade AND registro_id=:registro LIMIT 1'
        );
        $find->execute(['execucao' => $executionId, 'pergunta' => $questionId, 'pergunta_nulo' => $questionId, 'entidade' => $entity, 'registro' => substr($recordId, 0, 100)]);
        $existing = $find->fetch(PDO::FETCH_ASSOC);
        if ($existing) return $existing;
        $insert = $this->tenantPdo->prepare(
            'INSERT INTO amb_questionario_evidencias(codexecucao,codpergunta,entidade,registro_id,criado_por)
             VALUES (:execucao,:pergunta,:entidade,:registro,:usuario)'
        );
        $insert->execute(['execucao' => $executionId, 'pergunta' => $questionId, 'entidade' => $entity, 'registro' => substr($recordId, 0, 100), 'usuario' => $userId]);
        $id = (int)$this->tenantPdo->lastInsertId();
        $this->audit($userId, 'INCLUSAO', 'amb_questionario_evidencias', (string)$id, ['codexecucao' => $executionId, 'entidade' => $entity, 'registro_id' => $recordId]);
        $find->execute(['execucao' => $executionId, 'pergunta' => $questionId, 'pergunta_nulo' => $questionId, 'entidade' => $entity, 'registro' => substr($recordId, 0, 100)]);
        return $find->fetch(PDO::FETCH_ASSOC) ?: ['codevidencia' => $id];
    }

    public function publish(int $versionId, int $publishedBy): array
    {
        if (isset($_SESSION['usuario'])) {
            return (new QuestionarioVersaoService($this->universalPdo))->publicar($versionId, QuestionarioVersaoService::escopo($_SESSION), $publishedBy);
        }
        return $this->publishLegacy($versionId, $publishedBy);
    }

    /** Publicação antiga (sem escopo de empresa): só para chamadas fora de sessão */
    private function publishLegacy(int $versionId, int $publishedBy): array
    {
        $this->universalPdo->beginTransaction();
        try {
            $query = $this->universalPdo->prepare(
                'SELECT v.*,q.codigo FROM amb_questionario_versao v
                 JOIN amb_questionario q ON q.codquestionario=v.codquestionario
                 WHERE v.codversao=:id FOR UPDATE'
            );
            $query->execute(['id' => $versionId]);
            $version = $query->fetch(PDO::FETCH_ASSOC);
            if (!$version) {
                throw new InvalidArgumentException('Versão do questionário não localizada.');
            }
            if ((string)$version['status'] !== 'RASCUNHO') {
                throw new DomainException('Somente versões em rascunho podem ser publicadas.');
            }
            $count = $this->universalPdo->prepare(
                'SELECT COUNT(*) FROM amb_questionario_pergunta WHERE codversao=:id AND ativo=1'
            );
            $count->execute(['id' => $versionId]);
            if ((int)$count->fetchColumn() === 0) {
                throw new DomainException('A versão precisa ter ao menos uma pergunta.');
            }
            $archive = $this->universalPdo->prepare(
                "UPDATE amb_questionario_versao SET status='ARQUIVADA' WHERE codquestionario=:questionario AND status='PUBLICADA'"
            );
            $archive->execute(['questionario' => (int)$version['codquestionario']]);
            $update = $this->universalPdo->prepare(
                "UPDATE amb_questionario_versao SET status='PUBLICADA',publicado_em=CURRENT_TIMESTAMP,publicado_por=:usuario WHERE codversao=:id"
            );
            $update->execute(['usuario' => $publishedBy, 'id' => $versionId]);
            $code = sprintf('Q:%s:V:%d', $version['codigo'], (int)$version['versao']);
            $insert = $this->universalPdo->prepare(
                'INSERT INTO amb_questionario_publicacao(codversao,codigo_publicacao,publicado_por)
                 VALUES (:versao,:codigo,:usuario)'
            );
            $insert->execute(['versao' => $versionId, 'codigo' => $code, 'usuario' => $publishedBy]);
            $this->universalPdo->commit();
            return ['codversao' => $versionId, 'codigo_publicacao' => $code, 'status' => 'PUBLICADA'];
        } catch (Throwable $exception) {
            if ($this->universalPdo->inTransaction()) {
                $this->universalPdo->rollBack();
            }
            throw $exception;
        }
    }

    private function published(string $code): ?array
    {
        return $this->vigentes(' AND codigo=:codigo', ['codigo' => strtoupper(trim($code))])[0] ?? null;
    }

    private function execution(int $id): ?array
    {
        $query = $this->tenantPdo->prepare('SELECT * FROM amb_questionario_execucoes WHERE codexecucao=:id');
        $query->execute(['id' => $id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function assertDraft(int $versionId): void
    {
        $query = $this->universalPdo->prepare("SELECT status FROM amb_questionario_versao WHERE codversao=:id");
        $query->execute(['id' => $versionId]);
        if ($query->fetchColumn() !== 'RASCUNHO') throw new DomainException('A versão publicada é imutável.');
    }

    private function findByKey(string $key): ?array
    {
        $query = $this->tenantPdo->prepare('SELECT * FROM amb_questionario_execucoes WHERE chave_idempotencia=:chave');
        $query->execute(['chave' => $key]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function advanceWorkflowAfterSubmit(int $executionId, int $userId): void
    {
        try {
            $query = $this->tenantPdo->prepare("SELECT codinstancia FROM workflow_instancias WHERE origem_entidade='amb_questionario_execucoes' AND origem_id=:id ORDER BY codinstancia DESC LIMIT 1");
            $query->execute(['id' => $executionId]);
            $instance = (int)$query->fetchColumn();
            if ($instance <= 0) return;
            $workflow = new WorkflowService($this->tenantPdo, new TaskService($this->tenantPdo));
            $workflow->transition($instance, 'ENVIAR', $userId, 'Questionário concluído e enviado para revisão.');
        } catch (Throwable $exception) {
            // Fluxos legados de fornecedor podem não possuir a ação ENVIAR.
            // A conclusão do questionário permanece válida e auditada.
        }
    }

    private function score(array $questions, array $answers): array
    {
        $score = 0.0;
        $max = 0.0;
        $scored = 0;
        foreach ($questions as $question) {
            $options = array_values(array_filter($question['opcoes'], fn(array $option): bool => $option['pontuacao'] !== null));
            if (!$options) {
                continue;
            }
            $scored++;
            $max += max(array_map(fn(array $option): float => (float)$option['pontuacao'], $options));
            $selected = $answers[(int)$question['codpergunta']] ?? null;
            foreach ((array)$selected as $value) {
                foreach ($question['opcoes'] as $option) {
                    if ((string)$value === (string)$option['codopcao']
                        || (string)$value === (string)$option['codigo']
                        || (string)$value === (string)$option['valor']) {
                        $score += (float)($option['pontuacao'] ?? 0);
                        break;
                    }
                }
            }
        }
        return [$score, $max, $scored];
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_values(array_map(fn(mixed $item): mixed => is_string($item) ? trim($item) : $item, $value));
        }
        return is_string($value) ? trim($value) : $value;
    }

    private function isEmptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || (is_array($value) && count(array_filter($value, fn(mixed $item): bool => $item !== '' && $item !== null)) === 0);
    }

    private function localized(array $row, string $prefix, string $language): string
    {
        $language = in_array(strtolower($language), ['en', 'es'], true) ? strtolower($language) : 'pt';
        return (string)($row[$prefix . '_' . $language] ?? $row[$prefix . '_pt'] ?? '');
    }

    private function nullable(?string $value, int $length): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : substr($value, 0, $length);
    }

    private function audit(int $userId, string $action, string $entity, string $recordId, array $details): void
    {
        try {
            $query = $this->tenantPdo->prepare(
                'CALL sp_auditoria_registrar(:usuario,:transacao,:acao,:entidade,:registro,:resultado,:detalhes)'
            );
            $details['application'] = 'GreenSolutions';
            $details['version'] = '4.0.1';
            $query->execute([
                'usuario' => $userId,
                'transacao' => 'questionarios',
                'acao' => $action,
                'entidade' => $entity,
                'registro' => $recordId,
                'resultado' => 'SUCESSO',
                'detalhes' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $query->closeCursor();
        } catch (Throwable $exception) {
            error_log('QuestionnaireService audit error: ' . $exception->getMessage());
        }
    }
}
