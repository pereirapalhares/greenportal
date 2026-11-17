# Análise e migração do módulo Controle — documentos

**Data:** 24/08/2026  
**Origem:** `green_modelo`  
**Destino:** `gmodelo_dbp`  
**Migration:** `database/121_migrar_controle_documentos_20260824.sql`

## 1. Escopo funcional

O cadastro principal do módulo Controle é `documentos`. As tabelas
`documentosx*` representam os relacionamentos do documento com o contexto
ambiental, ocupacional e operacional:

- `documentosxaspectos` — aspectos;
- `documentosxatividades` — atividades;
- `documentosximpactos` — impactos;
- `documentosxpalavraschave` — palavras-chave;
- `documentosxperigos` — perigos;
- `documentosxriscos` — riscos;
- `documentosxunidades` — unidades.

A relação `legislacaoxdocumentos` também foi considerada porque liga o
documento de Controle ao requisito legal e já é utilizada pelos popups e
relacionamentos do Módulo Legal.

## 2. Inventário encontrado

| Tabela | `green_modelo` | `gmodelo_dbp` antes da migration | Decisão |
|---|---:|---:|---|
| `documentos` | 37 | 37 | preservar e validar |
| `documentosxaspectos` | 461 | 448 equivalentes completos | inserir 13 vínculos com unidade nula |
| `documentosxatividades` | 100 | 100 | validar |
| `documentosximpactos` | 3 | 0 equivalentes completos | inserir 3 vínculos com unidade nula |
| `documentosxpalavraschave` | 1 | ausente | criar tabela e migrar 1 vínculo |
| `documentosxperigos` | 100 | 100 | validar e normalizar |
| `documentosxriscos` | 100 | 100 | validar e normalizar |
| `documentosxunidades` | 55 | 55 | validar e normalizar |
| `legislacaoxdocumentos` | 36 | 36 | validar e sincronizar |

Os 13 vínculos de aspectos e os 3 vínculos de impactos não são órfãos. Eles
possuem `CODDOCUMENTO` e o respectivo aspecto/impacto válido, mas têm
`codunidade = NULL`; essa informação foi preservada.

## 3. Dependências confirmadas

Os relacionamentos utilizam os seguintes cadastros do tenant:

- `aspectos`;
- `atividades`;
- `impactos`;
- `perigos`;
- `riscos`;
- `unidades`.

Todos esses cadastros existem em `gmodelo_dbp` e não apresentaram órfãos nas
relações analisadas.

Não foram encontradas chaves estrangeiras formais no legado. A integridade é
mantida por códigos lógicos, por isso a migration preserva os valores legados
e usa verificações `NOT EXISTS` para ser repetível.

## 4. Estruturas relacionadas excluídas

`ged_documentos` e as tabelas `ged_*` pertencem ao subsistema legado de Gestão
Eletrônica de Documentos, com modelo próprio, workflow, permissões, histórico
e arquivos físicos. Não são a tabela `documentos` do módulo Controle e não
foram misturadas nesta migration.

`documentos_indexados` também ficou fora do escopo porque é uma tabela MyISAM
de indexação e não participa dos relacionamentos funcionais do Controle.

## 5. Normalização aplicada

As tabelas do escopo foram mantidas em InnoDB e convertidas para `utf8mb4`
com `utf8mb4_0900_ai_ci`, incluindo `documentosxperigos` e
`documentosxriscos`, que ainda estavam em `latin1_swedish_ci` no destino.

As chaves `CODDOCUMENTO` e os códigos dos relacionamentos foram preservados.
As chaves técnicas adicionais existentes no destino para
`documentosxaspectos` e `documentosximpactos` foram mantidas; os dados são
inseridos explicitamente pelas colunas legadas.

## 6. Backup e execução

Foi criado backup antes da aplicação:

`backups/gmodelo_dbp_pre_controle_documentos_20260824.sql`

A execução deve ser registrada em `registros_migracao` com o código
`CONTROLE_DOCUMENTOS_LEGADO_121`.

## 7. Limites desta etapa

Esta etapa migra e normaliza a base de dados do Controle. Ela ainda não cria:

- tela independente de gestão do Controle;
- tela `Meus Controles`;
- atribuições de responsabilidade para documentos;
- separação operacional entre Controle e Influência;
- fluxo de potenciais gerados por IA;
- delegação de responsáveis.

Esses itens dependem da definição funcional do módulo Controle e serão tratados
após a homologação da estrutura migrada.
