# P2 — Catálogo universal de contextos de questionários

## Implementação

O `QuestionnaireService` utiliza as mesmas tabelas universais `amb_questionario_*` para todos os módulos. Não foram criadas tabelas físicas duplicadas por tipo de questionário.

Foram habilitados os contextos:

- `LEGAL` — requisitos e legislação;
- `SGA` — licenças, controles, treinamentos e aspectos ambientais;
- `QUALITY` — auditorias, não conformidades e eficácia;
- `WORKFLOW` — checklists dentro de processos;
- `SUPPLIER` — homologação e avaliação de fornecedores;
- `ESG` — indicadores ambientais, sociais e de governança;
- `PROJECTS` — fases, riscos, entregáveis e revisões;
- `EMPLOYEE_INTEGRATION` — integração e capacitação de funcionários.

## Questionários-base

A migração `202` cadastrou, de forma idempotente, um questionário-base e uma versão `RASCUNHO` para cada contexto. Nenhum questionário vazio foi publicado e nenhuma pergunta foi inventada.

Os questionários legais serão preenchidos a partir de `glegislacao_dbp.perguntas` e `glegislacao_dbp.legislacaoxpergunta`, preservando os códigos legados e seus vínculos.

## Workflows

Os três novos workflows (`ESG_QUESTIONNAIRE`, `PROJECTS_QUESTIONNAIRE` e `EMPLOYEE_INTEGRATION_QUESTIONNAIRE`) foram criados nos bancos Atlas, Modelo e Modelo2. Os contextos anteriores permanecem idempotentes e compatíveis.

## Validação

- `guniversal_dbp`: 8 questionários-base e 8 versões em rascunho;
- `gatlas_dbp`: 8 workflows ativos;
- `gmodelo_dbp`: 8 workflows ativos;
- `gmodelo2_dbp`: 8 workflows ativos;
- reexecução da migração sem duplicidade;
- publicação permanece bloqueada até existirem perguntas válidas e revisão funcional.

## Acesso de manutenção

A manutenção é realizada na transação `principal/questionarios.php`, disponível no
menu universal **Administração › Questionários**. A migração também corrige o vínculo
hierárquico do menu (`codmenu_pai` apontando para `ADMINISTRACAO`), preservando o
`codmenu` estável `116` e evitando uma cópia exclusiva do catálogo nos tenants.

Cada tipo de questionário possui uma transação PHP própria, carregada em iframe
independente: `legal_questionario.php`, `sga_questionario.php`,
`quality_questionario.php`, `workflow_questionario.php`, `supplier_questionario.php`,
`esg_questionario.php`, `projects_questionario.php` e
`employee_integration_questionario.php`. Assim, filtros, paginação, editor e
requisições de uma categoria não interferem nas demais.
