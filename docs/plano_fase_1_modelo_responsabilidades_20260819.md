# Fase 1 — Plano do modelo de responsabilidades

**Data:** 19/08/2026  
**Projeto:** Green Portal  
**Objetivo:** criar a base comum de responsabilidades utilizada pelos módulos de Licenças, Medição e Monitoramento, Treinamento, Infraestrutura, Controle, Homologação e Requisitos Legais.

## 1. Objetivo da fase

Implementar um modelo único para atribuir responsabilidades a:

- um usuário;
- vários usuários;
- um grupo de usuários;
- um tipo de item;
- uma categoria legal;
- um registro específico de gestão;
- um período de validade;
- uma delegação temporária ou definitiva.

O modelo deve aceitar usuários ativos e impedir novos vínculos com usuários inativos.

## 2. Escopo da Fase 1

Esta fase deverá entregar:

1. catálogo de tipos de responsabilidade;
2. cadastro e manutenção de grupos;
3. relacionamento de usuários com grupos;
4. relacionamento Responsável x Tipo;
5. relacionamento Responsável x Categoria Legal;
6. responsáveis diretos em registros de gestão;
7. controle de usuário ativo ou inativo;
8. identificação de responsabilidades órfãs;
9. estrutura inicial de delegação;
10. auditoria das alterações;
11. permissões específicas para consultar e alterar responsabilidades.

## 3. Entidades propostas

Os nomes definitivos devem ser confirmados antes da criação das tabelas. A proposta inicial é:

### 3.1 Grupos

Tabela sugerida: `responsabilidade_grupos`

Campos principais:

- `codgruporesponsabilidade`;
- `nome`;
- `descricao`;
- `ativo`;
- `criado_em`;
- `atualizado_em`;
- campos de trilha do usuário responsável pela alteração.

### 3.2 Usuários do grupo

Tabela sugerida: `responsabilidade_grupoxusuario`

Campos principais:

- identificador da associação;
- identificador do grupo;
- identificador do usuário;
- início da validade;
- fim da validade;
- `ativo`;
- campos de auditoria.

Deve existir uma restrição para evitar a duplicidade do mesmo usuário no mesmo grupo dentro da mesma vigência.

### 3.3 Catálogo de módulos

Tabela sugerida: `responsabilidade_modulos`

Módulos previstos:

- `REQUISITOS_LEGAIS`;
- `LICENCAS`;
- `MEDICAO_MONITORAMENTO`;
- `TREINAMENTO`;
- `INFRAESTRUTURA`;
- `CONTROLE`;
- `HOMOLOGACAO`.

Campos principais:

- código do módulo;
- nome;
- descrição;
- ativo;
- ordem de apresentação.

### 3.4 Tipos de responsabilidade

Tabela sugerida: `responsabilidade_tipos`

Campos principais:

- identificador do tipo;
- módulo;
- código;
- descrição;
- ativo;
- ordem.

Exemplos:

- tipo de licença;
- tipo de medição;
- tipo de treinamento;
- tipo de infraestrutura;
- tipo de controle;
- tipo de homologação.

### 3.5 Responsável x Tipo

Tabela sugerida: `responsabilidade_responsavelxtipo`

Campos principais:

- identificador do vínculo;
- módulo;
- tipo de responsabilidade;
- usuário responsável ou grupo responsável;
- prioridade;
- início da validade;
- fim da validade;
- ativo;
- observação;
- dados de auditoria.

Regra: o vínculo deve apontar para um usuário ou para um grupo, nunca para os dois simultaneamente.

### 3.6 Categorias legais

As categorias existentes no banco legislativo devem ser avaliadas antes de criar uma nova tabela. Caso não atendam ao modelo do relatório, criar uma tabela de categorias no banco apropriado, preservando os códigos do legado quando necessário.

Campos esperados:

- identificador da categoria;
- descrição;
- código legado, quando existente;
- ativo;
- ordem;
- dados de auditoria.

### 3.7 Responsável x Categoria

Tabela sugerida: `responsabilidade_responsavelxcategoria`

Campos principais:

- identificador do vínculo;
- categoria legal;
- usuário responsável ou grupo responsável;
- prioridade;
- início da validade;
- fim da validade;
- ativo;
- observação;
- dados de auditoria.

Esse vínculo será usado para atribuir responsabilidades aos requisitos legais classificados em uma ou mais categorias.

### 3.8 Responsáveis diretos

Para não quebrar as tabelas legadas, deve ser avaliado se os responsáveis serão mantidos diretamente nas tabelas existentes ou se será criada uma tabela genérica de vínculos.

Proposta preferencial: tabela genérica de associação, com campos como:

- módulo;
- entidade;
- identificador do registro;
- usuário ou grupo;
- origem da atribuição: `DIRETA`, `TIPO`, `CATEGORIA` ou `DELEGACAO`;
- início e fim da validade;
- ativo;
- dados de auditoria.

Antes de adotá-la, deve ser verificada a compatibilidade com o CRUD, as views e os códigos legados.

## 4. Regras de negócio

### 4.1 Usuários ativos

- Somente usuários ativos podem receber novas responsabilidades.
- Usuários inativos não podem ser selecionados em comboboxes de responsáveis.
- A desativação de um usuário não deve apagar seu histórico.
- Responsabilidades de usuários inativos devem aparecer como pendências de regularização.

### 4.2 Usuários e grupos

- Um grupo pode possuir um ou vários usuários.
- Um usuário pode participar de vários grupos.
- Grupo inativo não pode receber novas atribuições.
- A remoção de um usuário do grupo deve preservar o histórico da associação.

### 4.3 Usuário ou grupo

Todo vínculo de responsabilidade deve ter exatamente um destino:

- usuário; ou
- grupo.

Não deve ser permitido salvar um vínculo sem responsável nem com usuário e grupo preenchidos simultaneamente.

### 4.4 Vigência

- A data inicial deve ser obrigatória quando o vínculo tiver controle temporal.
- A data final não pode ser anterior à data inicial.
- Vínculos fora da vigência não devem aparecer como responsáveis atuais.
- O histórico deve permanecer consultável.

### 4.5 Prioridade

Quando houver responsabilidades por tipo, categoria e atribuição direta, a aplicação deverá exibir claramente a origem e a prioridade do vínculo.

Sugestão inicial de prioridade:

1. atribuição direta;
2. delegação vigente;
3. Responsável x Tipo;
4. Responsável x Categoria;
5. grupo padrão do módulo.

Essa prioridade deve ser validada com o responsável funcional antes da implementação.

### 4.6 Duplicidades

O banco e a aplicação devem impedir vínculos duplicados ativos para o mesmo módulo, tipo, categoria, registro e responsável dentro da mesma vigência.

## 5. Migração e compatibilidade com o legado

Antes de criar qualquer tabela:

1. inventariar campos atuais de responsáveis;
2. identificar tabelas que usam `CODUSUARIO`;
3. localizar vínculos existentes em situações, planos de ação, licenças e demais módulos;
4. verificar tabelas de grupos já existentes;
5. verificar categorias legais existentes em `glegislacao_dbp`;
6. identificar registros órfãos ou usuários inativos já vinculados;
7. criar backup dos bancos envolvidos;
8. preparar scripts versionados de migração;
9. executar primeiro em homologação;
10. comparar contagens e vínculos antes e depois da migração.

Nenhum vínculo legado deve ser apagado automaticamente durante a primeira migração.

## 6. Views e serviços necessários

Criar ou padronizar views para:

- usuários ativos;
- grupos ativos;
- membros ativos de grupos;
- tipos por módulo;
- responsáveis atuais por registro;
- responsabilidades por usuário;
- responsabilidades órfãs;
- histórico de delegações;
- categorias e seus responsáveis.

Criar serviços de aplicação para:

- validar usuário ativo;
- resolver responsabilidades herdadas de tipo e categoria;
- listar responsáveis efetivos;
- identificar conflitos;
- impedir vínculos inválidos;
- registrar alterações.

## 7. Telas da primeira entrega

### 7.1 Grupos de responsabilidade

Deve possuir:

- grid padrão;
- CRUD;
- inclusão de membros;
- filtro por ativo;
- ordenação alfabética;
- seleção somente de usuários ativos;
- exportação conforme permissão;
- auditoria.

### 7.2 Responsável x Tipo

Deve possuir:

- seleção do módulo;
- seleção do tipo;
- seleção de usuário ou grupo;
- vigência;
- prioridade;
- situação ativo/inativo;
- validação de duplicidade;
- CRUD padrão.

### 7.3 Responsável x Categoria

Deve possuir:

- categoria legal;
- usuário ou grupo;
- vigência;
- prioridade;
- situação;
- CRUD padrão;
- filtro por categoria e responsável.

### 7.4 Pendências de responsabilidades

Deve listar:

- usuário inativo ainda responsável;
- grupo inativo utilizado em vínculos ativos;
- vínculo sem responsável válido;
- vínculo vencido;
- duplicidades ou conflitos.

## 8. Permissões

Criar permissões específicas para:

- visualizar grupos;
- incluir grupos;
- editar grupos;
- excluir ou inativar grupos;
- visualizar Responsável x Tipo;
- alterar Responsável x Tipo;
- visualizar Responsável x Categoria;
- alterar Responsável x Categoria;
- corrigir responsabilidades órfãs;
- delegar responsabilidades;
- exportar dados;
- consultar auditoria.

As permissões devem seguir o padrão já utilizado pelo `PermissaoTransacao` e pelo menu do sistema.

## 9. Auditoria obrigatória

Registrar pelo menos:

- criação e alteração de grupo;
- inclusão e remoção de membro;
- criação e alteração de vínculo;
- ativação e inativação;
- mudança de responsável;
- delegação;
- correção de vínculo órfão;
- usuário, perfil, tenant, transação, data e resultado.

O histórico não deve ser apagado junto com a inativação do vínculo.

## 10. Testes da Fase 1

### Banco de dados

- chaves primárias existentes;
- índices para filtros e relacionamentos;
- restrições de duplicidade;
- integridade entre usuários, grupos, tipos e categorias;
- comportamento com registros inativos;
- preservação do histórico.

### Aplicação

- comboboxes exibem somente usuários ativos;
- grupos exibem somente membros ativos;
- usuário e grupo não podem ser preenchidos simultaneamente;
- datas inválidas são rejeitadas;
- duplicidades são rejeitadas;
- filtros retornam os responsáveis corretos;
- permissões são respeitadas;
- auditoria é gerada em cada alteração.

### Cenários funcionais

1. atribuir responsabilidade a um usuário ativo;
2. atribuir responsabilidade a um grupo;
3. consultar os membros do grupo;
4. inativar um usuário responsável;
5. localizar a responsabilidade órfã;
6. delegar para outro usuário ativo;
7. tentar delegar para usuário inativo;
8. atribuir responsabilidade por tipo;
9. atribuir responsabilidade por categoria;
10. verificar a prioridade entre vínculos;
11. consultar o histórico;
12. exportar conforme permissão.

## 11. Critérios de aceite

A Fase 1 será considerada concluída quando:

- o modelo de dados estiver criado em homologação;
- os vínculos legados estiverem preservados;
- usuários inativos não puderem receber novas responsabilidades;
- grupos e múltiplos responsáveis funcionarem;
- Responsável x Tipo funcionar para os módulos definidos;
- Responsável x Categoria funcionar para requisitos legais;
- responsabilidades órfãs forem identificadas;
- a delegação básica estiver disponível;
- as permissões estiverem configuradas;
- as operações estiverem auditadas;
- os testes funcionais forem aprovados;
- a migração for documentada em `docs`.

## 12. Ordem prática de implementação

1. Levantar o modelo atual de usuários, grupos, categorias e responsáveis.
2. Definir nomes finais das tabelas e chaves.
3. Criar backup e script de migração em homologação.
4. Criar tabelas-base de grupos, módulos, tipos e vínculos.
5. Criar views de usuários e grupos ativos.
6. Migrar os vínculos atuais sem apagar dados.
7. Implementar CRUD de grupos.
8. Implementar Responsável x Tipo.
9. Implementar Responsável x Categoria.
10. Implementar identificação de órfãos.
11. Implementar delegação básica.
12. Integrar auditoria e permissões.
13. Executar testes.
14. Corrigir inconsistências.
15. Documentar e só então preparar a implantação no ambiente produtivo.

## Resultado esperado

Ao concluir esta fase, o Green Portal terá uma base única, auditável e reutilizável para atribuir responsabilidades por usuário, grupo, tipo e categoria, com controle de usuários ativos, identificação de vínculos inválidos e delegação preparada para os módulos de gestão.

Este arquivo descreve o plano. Nenhuma alteração de código ou banco de dados foi realizada.


