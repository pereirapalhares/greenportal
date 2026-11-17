# Registro das correções indicadas pelos relatórios

Este documento é o registro oficial, dentro de `docs/`, das correções aplicadas em
`2026-08-19` a partir de `tests/greenportal_ajustes_necessarios.md` e
`tests/relatorio_performance_bancos_20260819.md`.

## Correções funcionais e arquiteturais concluídas

### Banco e configuração da aplicação

- O banco padrão efetivo de `.env` foi corrigido para `gmodelo_dbp`.
- Os blocos duplicados `GPT_DB_*` foram eliminados/organizados para não haver
  sobrescrita silenciosa do primeiro valor.
- A transação de Licenças e o `ExportacaoGridWorker` usam `gmodelo_dbp`,
  mantendo a tela e a exportação sobre a mesma view.
- Os acessos SQL diretos identificados no fluxo de login foram substituídos pelo
  fluxo padronizado de credenciais/procedures. A validação de sessão, tenant e
  permissões permanece obrigatória.

### Ajuda das transações e páginas órfãs

- Foi mantida apenas `classes/AjudaTransacoesCrudGridV2.php` como implementação
  da manutenção da ajuda.
- `principal/configuracoes_ajuda.php` e `principal/setup_ajuda.php` usam a
  versão V2 e o endpoint oficial da ajuda.
- As implementações antigas `AjudaTransacoesGrid.php` e
  `AjudaTransacoesCrudGrid.php` foram removidas.
- A página órfã `principal/index.php` foi removida; a área autenticada oficial é
  `principal/main.php`.
- A visualização da ajuda foi centralizada em `principal/setup_ajuda.php`.

### Exportações e componentes de grid

- A exportação assíncrona de Legislação foi implementada em
  `principal/manutencaolegal_legislacao.php` e registrada no mapa de
  `classes/ExportacaoGridWorker.php`, usando `glegislacao_dbp.vw_legislacao_grid`.
- As telas Legislação x Empresas e Legislação x Poder passaram a usar
  `greenPortalCrudButtons()`.
- Nessas telas matriciais, Novo, Duplicar e Excluir ficam desabilitados por não se
  aplicarem ao relacionamento; Salvar, Recarregar, Exportar Excel e Colunas seguem
  o componente padrão e as permissões do perfil.
- As exportações matriciais foram incluídas no worker e preservam busca e filtros.
- O texto corrompido `Código do mbito` em
  `classes/ModuloLegalRequisitosGrid.php` foi corrigido para UTF-8.

### Cache e visualização de PDF

- `principal/dashboard.php` passou a enviar `Cache-Control`, `Pragma` e
  `Expires` para impedir o uso de conteúdo autenticado obsoleto.
- Todas as transações com abertura de legislação usam o endpoint
  `principal/visualizadopdf.php` e o componente `assets/js/visualizador-pdf.js`.
- A renderização utiliza PDF.js local dentro da modal, sem depender do leitor de
  PDF instalado no computador do usuário.
- A permissão do módulo de correlacionamento ambiental foi corrigida para usar o
  código de menu `CORRELACIONAMENTO_AMBIENTAL`, separado do nome técnico da
  transação. Isso elimina o erro genérico de carregamento do PDF nessa tela.

## Correções de integridade e desempenho do banco

As migrações abaixo foram criadas e aplicadas no ambiente do projeto, sempre com
backup prévio e validação dos nomes reais das tabelas:

- `database/073_indices_integridade_relacionamentos.sql`: criou chaves técnicas
  e índices de relacionamento nas tabelas sem índices, priorizando
  `glegislacao_dbp.legislacaoxpalavraschave`. A tabela de medições correta é
  `gmodelo_dbp.mm_medicaoxpalavraschave`; ela foi incluída na migração.
- `database/074_converter_myisam_para_innodb.sql`: converteu para InnoDB
  `documentosxunidades`, `legislacaoxchecklist`, `legislacaoxdocumentos`,
  `planodeacao`, `vlaiaxlegislacao` e `legislacaoxempresa`.
- `database/075_normalizar_collations_gmodelo.sql`: converteu as nove tabelas
  legadas de `latin1_swedish_ci` para `utf8mb4_0900_ai_ci`.
- O `slow_query_log` do MySQL foi ativado e o `long_query_time` foi ajustado
  para aproximadamente 2 segundos, permitindo observar consultas lentas antes de
  novos ajustes de índices ou SQL.

## Validações ainda necessárias

As correções de estrutura não substituem a validação operacional contínua. Devem
ser executados e registrados em homologação:

- `ANALYZE TABLE` nas tabelas grandes de `glegislacao_dbp`.
- `EXPLAIN` das consultas reais das telas de Legislação, Assuntos e Perguntas.
- Conferência de textos acentuados após conversões de charset/collation.
- Auditoria de órfãos e duplicidades antes de adicionar `FOREIGN KEY` ou índices
  `UNIQUE` adicionais.
- Revisão de `innodb_redo_log_capacity`, buffer pool e tabelas temporárias após
  coleta de métricas reais.

## Regra de manutenção da documentação

Novas alterações destas correções devem ser registradas neste arquivo ou na
diretriz específica correspondente dentro de `docs/`. Não criar ou atualizar
cópias de diretrizes na raiz do projeto.

## Reavaliação do Correlacionamento Ambiental

Na comparação com os arquivos legados `legislacaoxambiental_c.php` e
`legislacaoxambiental.php`, foi identificada uma perda funcional na primeira
implementação moderna: a tela apresentava apenas contadores de Impactos,
Licenças e Documentos, sem os cinco relacionamentos contextuais disponíveis no
legado.

A correção foi registrada e aplicada por
`database/078_corrigir_correlacionamento_ambiental.sql`:

- migração de `legislacaoxaspecto` para `gmodelo_dbp`;
- criação das views contextuais de Aspectos, Atividades, Impactos, Licenças e
  Documentos;
- inclusão dos cinco botões contextuais na transação principal;
- registro das exportações dos relacionamentos no `ExportacaoGridWorker`;
- correção da contagem de Documentos para considerar simultaneamente
  `CODLEGISLACAO` e `CODUNIDADE`, em vez de todos os documentos da unidade;
- atualização das diretrizes específicas do módulo.

A migração foi executada com status `CONCLUIDA`. A view principal passou a
retornar 1.279 legislações ambientais, e os endpoints PHP envolvidos foram
validados com `php -l`.

## Padronização de chaves estrangeiras nos grids

Foi implementado no componente central `classes/ManutencaoLegalCadastroGrid.php`
o tratamento padronizado de chaves estrangeiras editáveis.

- A unidade usa `vw_unidades_grid`, exibindo `DSCUNIDADE` e salvando
  `CODUNIDADE`.
- O combo de unidades considera somente registros com
  `usado_em_manutencao_legal='Sim'` e ordena os itens alfabeticamente pela
  descrição.
- Quando o código da unidade é editável, a coluna de descrição duplicada é
  ocultada.
- O mesmo mecanismo reconhece áreas, atividades, perigos, riscos, aspectos,
  impactos, partes interessadas, aspectos sociais e impactos sociais.
- As listas são carregadas por `sp_crud_select`, sem SQL CRUD direto nas telas.
- A configuração pode ser sobrescrita por campo usando `foreign` com view,
  coluna de valor, coluna de descrição, filtros e campo descritivo.

A regra foi aplicada aos grids dos módulos LAIA, LPRO e LAIS e documentada em
`docs/manutencao_legal_cadastros_diretrizes.txt`.
