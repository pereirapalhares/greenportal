# Interface de consulta de responsabilidades no cadastro de licenças

## Escopo inicial

Foi preparada a primeira seção de consulta de responsabilidades no cadastro de licenças da empresa Modelo (`gmodelo_dbp`). A consulta é somente leitura nesta fase e não altera a atribuição de responsáveis.

## Uso na tela

1. Acesse o cadastro de licenças.
2. Selecione uma linha do grid.
3. Acione o botão **Responsabilidades** (ícone de pessoas).
4. A aplicação abrirá a consulta da licença selecionada.

O botão permanece desabilitado enquanto nenhuma licença estiver selecionada e não é disponibilizado para registros novos ainda não salvos.

## Informações exibidas

Cada linha representa uma atribuição cadastrada para a licença e apresenta:

- finalidade da responsabilidade;
- categoria legal, quando houver;
- responsável, usuário ou grupo;
- tipo de destino;
- origem da atribuição;
- situação (`ATIVA`, `PROGRAMADA`, `EXPIRADA` ou `HISTORICA`);
- período de validade.

Quando o destino for um grupo, a consulta mostra também a quantidade de usuários ativos abrangidos. O grupo especial `TODOS` é exibido como **Todos os usuários ativos**, sem materializar uma linha para cada usuário.

## Regra histórica

Responsabilidades históricas continuam visíveis para rastreabilidade, mas não são tratadas como atribuições ativas. A consulta identifica visualmente essas linhas e informa essa regra ao usuário.

## Implementação

- View: `vw_responsabilidade_licencas`.
- Script de banco: `database/102_view_responsabilidades_licencas.sql`.
- Endpoint protegido: `principal/licenca_responsabilidades.php`.
- Integração do botão: `principal/manutencaolegal_licencas.php` e `assets/js/manutencao-legal-cadastro.js`.

O endpoint valida sessão, tenant e permissão de visualização da transação `LICENCAS`, consultando os dados por `sp_crud_select`.

## Homologação técnica inicial

Validada em `gmodelo_dbp` em 20/08/2026:

- 90 atribuições retornadas para 46 licenças;
- 44 atribuições destinadas a usuários;
- 46 atribuições destinadas ao grupo `TODOS`;
- 9 atribuições históricas preservadas;
- licenças consultadas retornando suas responsabilidades sem duplicação por usuário do grupo;
- arquivos PHP da integração aprovados na verificação de sintaxe.

## Manutenção inicial das atribuições

Foi adicionada a tela `principal/licenca_responsabilidades_edicao.php`, acessível pelo botão **Manter** da consulta. Ela permite criar e alterar atribuições ativas de usuários ou grupos para a licença, definir prioridade, principalidade e vigência.

As regras aplicadas são:

- validação de destino, vigência e duplicidade pelas procedures de responsabilidade;
- exclusão lógica, preservando o histórico;
- somente tipos ativos do módulo `LICENCAS` são aceitos;
- usuários e grupos são carregados pelas views de ativos;
- a tela respeita as permissões de visualizar, incluir, editar e excluir da transação `LICENCAS`.

## Homologação de integridade da manutenção

Executada em `gmodelo_dbp` em 20/08/2026, sem alteração de dados:

- nenhuma duplicidade ativa encontrada;
- nenhuma vigência inválida encontrada;
- 90 atribuições do módulo `LICENCAS` refletidas na view de consulta;
- 81 atribuições ativas e 9 históricas na consulta;
- os 1.426 registros órfãos gerais pertencem exclusivamente a usuários inativos e permanecem preservados como histórico.

## Replicação por tenant

Esta interface deve ser replicada somente quando o tenant possuir a estrutura de responsabilidades. O contrato permanece o mesmo para futuros bancos, como `gcrown_dbp`; o banco de destino não deve ser criado antecipadamente.
