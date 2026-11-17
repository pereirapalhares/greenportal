# Modelo lógico v1 — Responsabilidades

**Data:** 19/08/2026  
**Base:** `docs/plano_fase_1_modelo_responsabilidades_20260819.md` e
`docs/inventario_responsabilidades_20260819.md`  
**Status:** desenho lógico; nenhuma alteração de banco executada

## 1. Decisões principais

1. O modelo será aditivo e ficará no banco de cada tenant. O `gmodelo_dbp` é
   apenas a empresa Modelo de referência estrutural; empresas como a Crown
   utilizarão a mesma estrutura em `gcrown_dbp` após a migração de
   `green_crown` e `green_crown_seguranca`.
2. A tabela existente `usuarios` continuará sendo a fonte oficial de usuários.
3. A tabela existente `grupos` continuará sendo a fonte oficial de grupos.
4. A associação existente `usuarios.codgrupo` será preservada, mas não será
   suficiente para o novo modelo. Uma nova tabela permitirá múltiplos grupos
   por usuário com histórico e vigência.
5. Nenhum vínculo legado será apagado ou substituído na primeira migração.
6. Categorias legais serão mantidas no tenant por meio de uma cópia controlada
   da categoria de origem em `glegislacao_dbp`, preservando o código original.
7. A delegação terá uma tabela universal própria. A tabela
   `esg_delegacao_evento` continuará específica do ESG.
8. A resolução de responsáveis efetivos será feita por views e procedures,
   não por duplicação automática dos registros legados.

## 2. Entidades e tabelas

Todas as tabelas novas deverão usar `InnoDB`, `utf8mb4` e a collation oficial
do tenant. As chaves seguirão o padrão `COD<ENTIDADE>`.

### 2.1 Módulos

Tabela: `responsabilidade_modulos`

| Campo | Regra |
|---|---|
| `CODMODULO` | chave primária |
| `CODIGO` | único, por exemplo `LICENCAS` |
| `NOME` | descrição do módulo |
| `DESCRICAO` | texto opcional |
| `ATIVO` | situação |
| `ORDEM` | ordem de apresentação |
| `CRIADO_EM`, `ATUALIZADO_EM` | trilha temporal |

Carga inicial prevista: `REQUISITOS_LEGAIS`, `LICENCAS`,
`MEDICAO_MONITORAMENTO`, `TREINAMENTO`, `INFRAESTRUTURA`, `CONTROLE` e
`HOMOLOGACAO`.

### 2.2 Grupos de responsabilidade

Não será criada uma tabela paralela de grupos. A tabela existente `grupos`
será reutilizada.

A associação múltipla será criada em:

Tabela: `responsabilidade_grupoxusuario`

| Campo | Regra |
|---|---|
| `CODGRUPOXUSUARIO` | chave primária da associação |
| `CODGRUPO` | foreign key para `grupos.codgrupo` |
| `CODUSUARIO` | foreign key para `usuarios.codusuario` |
| `VALIDO_DE` | início da vigência |
| `VALIDO_ATE` | fim opcional |
| `ATIVO` | situação da associação |
| `CRIADO_EM`, `ATUALIZADO_EM` | trilha temporal |
| `CODUSUARIOALTERACAO` | usuário responsável pela alteração |

A associação antiga `usuarios.codgrupo` continuará funcionando para
compatibilidade. A nova resolução de grupos deverá considerar a tabela de
associação e, durante a transição, o grupo legado quando não houver associação
equivalente registrada.

### 2.3 Tipos de responsabilidade

Tabela: `responsabilidade_tipos`

| Campo | Regra |
|---|---|
| `CODTIPORESPONSABILIDADE` | chave primária |
| `CODMODULO` | foreign key para `responsabilidade_modulos` |
| `CODIGO` | único dentro do módulo |
| `NOME` | descrição apresentada ao usuário |
| `DESCRICAO` | texto opcional |
| `ATIVO` | situação |
| `ORDEM` | ordem de apresentação |
| `CRIADO_EM`, `ATUALIZADO_EM` | trilha temporal |

O tipo deve representar uma classificação de responsabilidade do módulo, e
não necessariamente o registro de gestão. O registro específico será
identificado na tabela de atribuições.

### 2.4 Categorias legais do tenant

Tabela: `responsabilidade_categorias`

| Campo | Regra |
|---|---|
| `CODCATEGORIARESPONSABILIDADE` | chave primária |
| `CODCATEGORIAORIGEM` | código em `glegislacao_dbp`, quando aplicável |
| `SCHEMAORIGEM` | banco de origem |
| `CODIGO` | código local estável |
| `NOME` | nome da categoria |
| `DESCRICAO` | texto opcional |
| `ATIVO` | situação |
| `ORDEM` | ordem de apresentação |
| `SINCRONIZADO_EM` | data da última sincronização |
| `CRIADO_EM`, `ATUALIZADO_EM` | trilha temporal |

A cópia local evita que a resolução de responsabilidades dependa de uma
consulta cruzada permanente entre bancos. A sincronização deverá preservar o
código de origem e nunca apagar automaticamente uma categoria já utilizada.

### 2.5 Atribuições de responsabilidade

Tabela: `responsabilidade_atribuicoes`

Esta será a tabela universal para atribuição direta, por tipo, por categoria e
por registro específico.

| Campo | Regra |
|---|---|
| `CODATRIBUICAO` | chave primária |
| `CODMODULO` | foreign key para `responsabilidade_modulos` |
| `ORIGEM` | `DIRETA`, `TIPO`, `CATEGORIA` ou `GRUPO_PADRAO` |
| `CODTIPORESPONSABILIDADE` | obrigatório quando `ORIGEM = TIPO` |
| `CODCATEGORIARESPONSABILIDADE` | obrigatório quando `ORIGEM = CATEGORIA` |
| `ENTIDADE` | tabela lógica do registro, quando aplicável |
| `REGISTRO_ID` | identificador do registro, quando aplicável |
| `TIPO_DESTINO` | `USUARIO` ou `GRUPO` |
| `CODUSUARIO` | preenchido somente para destino usuário |
| `CODGRUPO` | preenchido somente para destino grupo |
| `PRIORIDADE` | prioridade dentro do mesmo escopo |
| `PRINCIPAL` | indica responsável principal, quando aplicável |
| `VALIDO_DE` | início da vigência |
| `VALIDO_ATE` | fim opcional |
| `ATIVO` | situação |
| `OBSERVACAO` | justificativa ou observação |
| `CRIADO_EM`, `ATUALIZADO_EM` | trilha temporal |
| `CODUSUARIOALTERACAO` | usuário responsável pela alteração |

Regras estruturais:

- `TIPO_DESTINO` deve ser exatamente `USUARIO` ou `GRUPO`.
- Exatamente um entre `CODUSUARIO` e `CODGRUPO` deve ser preenchido.
- `ORIGEM = TIPO` exige tipo e não aceita categoria.
- `ORIGEM = CATEGORIA` exige categoria e não aceita tipo.
- `ORIGEM = DIRETA` exige registro específico ou escopo explicitamente
  definido pelo módulo.
- `CODUSUARIO` deve apontar para usuário ativo no momento da criação.
- `CODGRUPO` deve apontar para grupo ativo no momento da criação.
- A validade final não pode ser anterior à validade inicial.

As regras que dependem de datas, módulo e entidade deverão ser validadas por
procedure, além das constraints possíveis no banco.

### 2.6 Delegações

Tabela: `responsabilidade_delegacoes`

| Campo | Regra |
|---|---|
| `CODDELEGACAO` | chave primária |
| `CODATRIBUICAOORIGEM` | atribuição que originou a delegação, quando existir |
| `CODMODULO` | módulo da responsabilidade |
| `ENTIDADE` | entidade lógica, quando aplicável |
| `REGISTRO_ID` | registro específico, quando aplicável |
| `TIPO_DESTINO` | `USUARIO` ou `GRUPO` |
| `CODUSUARIO` | destino usuário, quando aplicável |
| `CODGRUPO` | destino grupo, quando aplicável |
| `VALIDO_DE` | início da delegação |
| `VALIDO_ATE` | fim opcional |
| `STATUS` | `PENDENTE`, `ATIVA`, `REVOGADA`, `EXPIRADA` ou `CANCELADA` |
| `MOTIVO` | justificativa obrigatória |
| `CODUSUARIOSOLICITANTE` | quem solicitou |
| `CODUSUARIOAPROVADOR` | quem aprovou, quando houver aprovação |
| `CRIADO_EM`, `ATUALIZADO_EM` | trilha temporal |
| `REVOGADO_EM` | data da revogação |

A delegação não apaga nem altera a atribuição original. Ela cria uma
responsabilidade efetiva adicional ou substituta durante a sua vigência,
conforme a regra do módulo.

## 3. Resolução do responsável efetivo

A ordem inicial será:

1. delegação vigente;
2. atribuição direta;
3. Responsável x Tipo;
4. Responsável x Categoria;
5. grupo padrão do módulo.

Quando houver mais de um vínculo no mesmo nível, todos os responsáveis válidos
devem ser retornados. `PRIORIDADE` e `PRINCIPAL` servem para ordenar e indicar
o responsável principal, mas não devem ocultar os demais.

Responsáveis efetivos somente serão considerados válidos quando:

```text
ATIVO = 1
VALIDO_DE <= data atual
VALIDO_ATE IS NULL OR VALIDO_ATE >= data atual
```

Para grupos, a resolução deverá expandir os membros ativos e vigentes da
associação `responsabilidade_grupoxusuario`.

## 4. Views e procedures

Views previstas:

- `vw_responsabilidade_usuarios_ativos`;
- `vw_responsabilidade_grupos_ativos`;
- `vw_responsabilidade_grupoxusuario`;
- `vw_responsabilidade_tipos`;
- `vw_responsabilidade_categorias`;
- `vw_responsabilidade_atribuicoes_atuais`;
- `vw_responsabilidade_efetiva`;
- `vw_responsabilidade_orfas`;
- `vw_responsabilidade_delegacoes`;
- `vw_responsabilidade_historico`.

Procedures mínimas:

- `sp_responsabilidade_validar_destino`;
- `sp_responsabilidade_validar_vigencia`;
- `sp_responsabilidade_validar_duplicidade`;
- `sp_responsabilidade_resolver`;
- `sp_responsabilidade_identificar_orfas`;
- `sp_responsabilidade_delegar`;
- `sp_responsabilidade_revogar_delegacao`.

As telas não deverão executar SQL de negócio diretamente. A validação,
duplicidade, auditoria e resolução deverão permanecer nas procedures e views.

## 5. Duplicidade e períodos sobrepostos

Não será suficiente um índice único para controlar vigências. Antes de criar
ou alterar um vínculo, a procedure deverá rejeitar outro vínculo ativo que:

- tenha o mesmo módulo;
- tenha a mesma origem e escopo;
- tenha o mesmo destino;
- possua períodos de validade sobrepostos.

As regras de duplicidade poderão ser diferentes quando `PRIORIDADE` ou
`PRINCIPAL` representarem uma configuração deliberadamente distinta, mas essa
exceção deverá ser explícita.

## 6. Compatibilidade com o legado e replicação por tenant

O legado será tratado em três níveis, repetidos para cada empresa migrada:

1. campos existentes, como `CODUSUARIO` em licenças e planos de ação;
2. associações múltiplas novas em `responsabilidade_atribuicoes`;
3. views de compatibilidade que apresentem o responsável efetivo sem alterar
   as tabelas antigas.

Na primeira migração de cada empresa:

- nenhum campo legado será removido;
- nenhum vínculo será apagado;
- os vínculos migrados receberão origem `DIRETA`;
- a origem da migração será registrada;
- conflitos e órfãos serão marcados para revisão;
- a migração será executada primeiro em homologação.

O mapeamento de origem e destino deve ser explícito e versionado. Exemplos:

| Empresa | Legado principal | Legado de segurança | Novo tenant |
|---|---|---|---|
| Modelo | `green_modelo` | `green_modelo_seguranca` | `gmodelo_dbp` |
| Crown | `green_crown` | `green_crown_seguranca` | `gcrown_dbp` |

Todos os tenants devem receber a mesma estrutura-base de tabelas, views,
procedures, índices, permissões e auditoria. Somente os dados, configurações e
os módulos autorizados devem variar por empresa.

## 7. Auditoria

As alterações utilizarão `logs_auditoria`, registrando pelo menos:

- entidade e identificador;
- operação;
- usuário, perfil e tenant;
- transação;
- valores anteriores e novos em JSON;
- origem da atribuição;
- resultado;
- motivo, quando aplicável;
- data, sessão e requisição, quando disponíveis.

## 8. Sequência de implementação

1. Revisar e aprovar este modelo lógico.
2. Criar script versionado apenas com as tabelas-base.
3. Criar módulos, tipos, associação de grupos e categorias locais.
4. Criar atribuições e delegações.
5. Criar constraints, índices, views e procedures.
6. Criar carga inicial idempotente dos módulos.
7. Criar telas e permissões de grupos.
8. Criar telas de Responsável x Tipo e Responsável x Categoria.
9. Criar consulta de órfãos e delegação.
10. Migrar vínculos legados em homologação.
11. Executar testes e comparar contagens.
12. Documentar o resultado antes de qualquer implantação produtiva.

Este documento define o desenho lógico inicial. A criação das estruturas só
deverá ocorrer após a revisão dos nomes e das regras de prioridade.
