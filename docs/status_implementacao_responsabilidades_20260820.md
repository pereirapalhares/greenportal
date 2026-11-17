# Status da implementação de responsabilidades

Data de referência: 20/08/2026  
Tenant aplicado: `gmodelo_dbp` — Empresa Modelo

## 1. Escopo e decisões confirmadas

O modelo de responsabilidades foi aplicado inicialmente somente à empresa Modelo. O banco `gmodelo_dbp` é o banco operacional da Modelo e possui como legados `green_modelo` e `green_modelo_seguranca`.

O banco `gcrown_dbp` ainda não existe e não deve ser criado antecipadamente. Quando a empresa Crown for criada, seus bancos legados `green_crown` e `green_crown_seguranca` serão migrados futuramente para um banco com a mesma estrutura de `gmodelo_dbp`.

A tabela operacional `li_licencas` permanece existindo. A estrutura de responsabilidades não substitui essa tabela; ela registra a finalidade e o destino de cada responsável.

## 2. Modelo de dados aplicado

Foram criadas as estruturas de módulos, tipos, categorias, atribuições, grupos de usuários e delegações:

- `responsabilidade_modulos`;
- `responsabilidade_tipos`;
- `responsabilidade_categorias`;
- `responsabilidade_atribuicoes`;
- `responsabilidade_grupoxusuario`;
- `responsabilidade_delegacoes`.

As regras principais são:

- destino pode ser usuário ou grupo;
- atribuições possuem vigência, prioridade, principalidade e origem;
- exclusão funcional é tratada como inativação, preservando histórico;
- usuários inativos permanecem como históricos e não recebem novos vínculos ativos;
- o grupo especial `TODOS` representa todos os usuários ativos sem materializar milhares de linhas;
- duplicidades e vigências sobrepostas são validadas por procedure.

## 3. Tipos e módulos trabalhados

### Licenças

Tipos cadastrados:

- responsável pela licença;
- responsável pela validade;
- responsável pela reação;
- responsável pelo protocolo;
- responsável pelo acesso.

Campos legados relacionados: `codusuario`, `codgrupo`, `codgrupo_validade`, `codgrupo_reacao`, `codgrupo_protocolo` e `codgrupo_acesso`.

### Requisitos Legais

Tipo utilizado na primeira etapa:

- `REQUISITO_LEGAL`.

O vínculo é feito por `legislacaoxsituacao.CODSITUACAO`, e não apenas pela legislação ou pela unidade agrupada.

## 4. Migração realizada na Modelo

Foram migrados somente vínculos aprovados e correspondentes sem ambiguidade. Não foram migrados automaticamente os vínculos classificados como pendentes de decisão.

Situação atual no `gmodelo_dbp`:

- `responsabilidade_atribuicoes`: 10.903 registros;
- registros ativos: 9.477;
- registros históricos: 1.426;
- responsabilidades de licenças: 90;
- responsabilidades de requisitos legais: 923;
- responsabilidades ativas de requisitos legais: 388;
- responsabilidades históricas de requisitos legais: 535.

Os 1.426 registros órfãos identificados são exclusivamente vínculos históricos de usuários inativos. Não há órfãos ativos de grupos ou usuários para os vínculos considerados válidos.

## 5. Interfaces entregues

### Licenças

No cadastro de licenças foi incluído o botão **Responsabilidades** para a linha selecionada.

A consulta apresenta:

- finalidade;
- responsável usuário ou grupo;
- origem;
- situação;
- validade;
- quantidade de usuários ativos quando o destino é grupo.

Também foi criada a manutenção de atribuições ativas, com inclusão, alteração e exclusão lógica.

Arquivos:

- `principal/licenca_responsabilidades.php`;
- `principal/licenca_responsabilidades_edicao.php`;
- `assets/js/manutencao-legal-cadastro.js`;
- `database/102_view_responsabilidades_licencas.sql`.

### Requisitos Legais

No grid de Situações foram incluídos os botões **Responsabilidades** e **Manter responsabilidades**.

A consulta e a manutenção usam `CODSITUACAO` como registro de referência. A manutenção permite usuário ou grupo, finalidade, prioridade, principalidade e vigência, com preservação dos históricos.

Arquivos:

- `principal/requisito_responsabilidades.php`;
- `principal/requisito_responsabilidades_edicao.php`;
- `assets/js/requisitos-responsabilidades.js`;
- `database/103_view_responsabilidades_requisitos.sql`.

## 6. Validações executadas

Foram realizadas validações técnicas sem alteração de dados:

- PHP sem erros de sintaxe nos endpoints e componentes alterados;
- nenhuma duplicidade ativa;
- nenhuma vigência inválida;
- correspondência entre tabelas de atribuição e views de consulta;
- nenhum vínculo de requisito legal sem `CODSITUACAO`;
- separação entre registros ativos e históricos confirmada.

## 7. Backup e segurança operacional

Foi criado backup antes da migração efetiva:

`backups/gmodelo_dbp_pre_responsabilidades_20260820.sql`

O backup corresponde ao banco da empresa Modelo antes da carga efetiva de responsabilidades.

## 8. Pendências e próximos passos

- homologação manual das telas com perfis reais;
- testar inclusão, alteração e exclusão lógica em uma situação de teste controlada;
- ampliar tipos funcionais de Requisitos Legais, se necessário;
- preparar interfaces dos próximos módulos;
- revisar e replicar a estrutura somente quando novos tenants forem criados;
- não criar `gcrown_dbp` antes do cadastro da empresa Crown e da aprovação da migração.

## 9. Documentos detalhados relacionados

- `modelo_logico_responsabilidades_20260819.md`;
- `inventario_responsabilidades_20260819.md`;
- `mapeamento_vinculos_legados_responsabilidades_20260819.md`;
- `decisoes_funcionais_responsabilidades_20260820.md`;
- `replicacao_responsabilidades_por_tenant_20260819.md`;
- `interface_consulta_responsabilidades_licencas_20260820.md`;
- `interface_consulta_responsabilidades_requisitos_20260820.md`.

## 10. Homologação técnica dos fluxos

Executada sem alteração de dados:

- licença de exemplo `CODLICENCA=1`: 2 vínculos ativos;
- requisito de exemplo `CODSITUACAO=6728`: 1 vínculo ativo;
- nenhuma duplicidade ativa em Licenças;
- nenhuma duplicidade ativa em Requisitos Legais;
- endpoints PHP de consulta e manutenção aprovados na verificação de sintaxe;
- integração JavaScript confirmada para abertura dos popups de consulta e manutenção.

Permanece pendente somente a conferência visual manual no navegador, incluindo testes com perfis de diferentes permissões.

## 11. Correções após o relatório visual

Com base no relatório `tests/relatorio_teste_responsabilidades_20260820.txt`, foram aplicadas as seguintes correções:

- mensagens de exceção SQL foram substituídas por mensagens amigáveis nos endpoints de manutenção de Licenças e Requisitos Legais;
- a tela de manutenção de Requisitos Legais passou a buscar a descrição diretamente em `legislacaoxsituacao`, evitando o texto “Sem descrição” quando não há vínculo ativo;
- o perfil `GESTOR` recebeu visualização, inclusão, edição, exclusão, exportação e geração de PDF para `LICENCAS` e `REQUISITOS_LEGAIS`, exclusivamente para permitir a repetição da homologação;
- script de configuração: `database/104_permissoes_teste_responsabilidades.sql`.

Validações pós-correção:

- `principal/licenca_responsabilidades_edicao.php` sem erros de sintaxe;
- `principal/requisito_responsabilidades_edicao.php` sem erros de sintaxe;
- permissões do perfil `GESTOR` confirmadas no banco com todas as flags habilitadas nas duas transações;
- descrição da situação `CODSITUACAO=39` disponível diretamente no cadastro da situação.

## 12. Correção da falha PER-004

Após o relatório de permissões, a entrada da manutenção foi ajustada nos dois módulos:

- `Excluir=1` agora permite abrir a manutenção mesmo com `Editar=0` e `Incluir=0`;
- campos de usuário, grupo, prioridade, principalidade e vigência ficam bloqueados sem `Editar`;
- o botão **Adicionar** fica oculto sem `Incluir`;
- a lixeira fica disponível somente com `Excluir`;
- exclusões são enviadas por marcador explícito, evitando interpretar campos desabilitados como remoção em massa;
- o bloqueio continua sendo aplicado no servidor, além do controle visual.

Arquivos envolvidos:

- `principal/licenca_responsabilidades_edicao.php`;
- `principal/requisito_responsabilidades_edicao.php`;
- `assets/js/responsabilidades-permissoes.js`.

Validação técnica pós-ajuste: PHP e JavaScript sem erros de sintaxe. A homologação visual foi concluída conforme a seção 13.

## 13. Encerramento da homologação PER-004

O relatório `tests/relatorio_homologacao_correcoes_PER004_20260821.txt` registrou a repetição visual completa do PER-004 nos módulos **Licenças** e **Requisitos Legais**.

Resultado final: **PASSOU SEM RESSALVAS**.

Foram confirmados nos dois módulos:

- abertura da manutenção com `Visualizar=1` e `Excluir=1`, mesmo sem `Incluir` e `Editar`;
- campos de manutenção visualmente bloqueados quando `Editar=0`;
- botão **Adicionar** oculto quando `Incluir=0`;
- lixeira habilitada somente quando `Excluir=1`;
- exclusão lógica com preservação do histórico;
- mensagem única e coerente após a operação;
- ausência de erros SQL ou alterações indevidas em outros vínculos;
- restauração das permissões do perfil `GESTOR` ao estado original após os testes.

O item **PER-004 está homologado e encerrado**, sem pendências abertas.

Próxima etapa: executar o **PER-005 — manutenção completa de responsabilidades**, validando inclusão, edição, exclusão lógica e mensagens para um perfil autorizado nos dois módulos.

## 14. Encerramento provisório da homologação PER-005

O relatório `tests/relatorio_homologacao_PER005_responsabilidades_20260821.txt` registrou a homologação visual da manutenção completa de responsabilidades nos módulos **Licenças** e **Requisitos Legais**.

Resultado funcional: **PASSOU**.

Foram confirmados nos dois módulos:

- inclusão de responsabilidade por perfil autorizado;
- edição com persistência dos valores alterados;
- exclusão lógica sem remoção física;
- preservação dos registros históricos;
- mensagens únicas e sem contradição;
- ausência de erros SQL ou mensagens técnicas;
- isolamento dos demais vínculos;
- restauração das permissões do perfil `GESTOR` ao estado anterior.

O item **PER-005 está encerrado provisoriamente, sem pendência funcional**.

Nota de rastreabilidade: o relatório apresenta divergência nas contagens totais informadas durante a limpeza, embora confirme a presença dos vínculos 15503 e 15504 como `HISTORICA` e a preservação dos dados. A divergência fica registrada para eventual conferência administrativa futura, sem bloquear o encerramento funcional do PER-005.

Próxima etapa recomendada: consolidar a matriz geral de permissões e iniciar a validação dos próximos módulos que utilizarão o modelo de responsabilidades.

## 15. Matriz geral e início da próxima validação

Foi consolidada a matriz geral em:

- `docs/matriz_geral_permissoes_responsabilidades_20260821.md`;

O documento consolida os resultados dos testes PER-001 a PER-005, as combinações
de permissões CRUD e o status dos módulos do modelo.

A validação de prontidão foi iniciada para `MEDICAO_MONITORAMENTO`, conforme:

- `docs/validacao_prontida_medicao_monitoramento_responsabilidades_20260821.md`.

Esse módulo possui origem legada definida em `mm_monitoramento.codusuario`.
A view, os endpoints e a interface contextual foram implementados conforme a
seção 16. Nenhuma nova migração ou alteração de dados foi feita nesta etapa.

## 16. Implementação de responsabilidades em Medição e Monitoramento

Foi implementada a primeira versão da consulta e manutenção contextual por
`CODMONITORAMENTO`:

- view `vw_responsabilidade_monitoramentos`, criada pelo script
  `database/111_responsabilidades_monitoramento.sql`;
- consulta em `principal/monitoramento_responsabilidades.php`;
- manutenção em `principal/monitoramento_responsabilidades_edicao.php`;
- ação **Responsabilidades** integrada ao grid existente de Medição e
  Monitoramento;
- inclusão, edição e exclusão lógica usando `ENTIDADE = 'mm_monitoramento'`;
- permissões server-side e bloqueio visual reutilizado do modelo homologado;
- tipo funcional `MONITORAMENTO` carregado pelo catálogo existente.

O código PHP passou na verificação de sintaxe nos três arquivos envolvidos.
O script SQL 111 foi aplicado no `gmodelo_dbp` e a view foi confirmada. O
roteiro visual PER-006 foi criado em
`tests/roteiro_homologacao_PER006_responsabilidades_monitoramento_20260821.txt`.

## 17. Correções após o PER-006

Com base no relatório `tests/relatorio_homologacao_PER006_responsabilidades_monitoramento_20260821.md`, foram aplicadas as seguintes correções:

- o botão **Responsabilidades** passou a ser inicializado no grid real de
  Medição e Monitoramento, sem depender do wrapper inexistente
  `.modulo-legal-requisitos-fragment`;
- inclusões e edições submetidas sem permissão deixam de retornar mensagem de
  sucesso enganosa; o endpoint informa que essas alterações foram ignoradas,
  mantendo a exclusão autorizada disponível;
- o vínculo preexistente `CODATRIBUICAO=1150`, do monitoramento 1, foi
  confirmado no banco e restaurado para `ATIVO=1`, pois havia sido usado no
  teste de exclusão e não pertencia aos dados criados para homologação.

Validação técnica: os três arquivos PHP de Medição e Monitoramento passaram
novamente no `php -l`. Ainda é necessário repetir visualmente o PER-006,
principalmente o habilitamento do botão e a mensagem de inclusão não
autorizada.
