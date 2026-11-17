# Migração dos módulos `fo_` e `esg_`

**Data:** 19/08/2026  
**Origem:** `green_modelo`  
**Destino:** `gmodelo_dbp`  
**Script:** `database/082_migrar_modulos_fo_esg.sql`

## Identificação dos módulos

- Todas as tabelas com prefixo `fo_` pertencem ao módulo de Homologação de Fornecedores.
- As tabelas com prefixo `esg_` pertencem ao módulo de ESG.
- A migração deve manter os nomes e as chaves legadas nesta primeira etapa.

## Resultado da análise do legado

Foram encontradas diversas referências `fo_` no código legado, incluindo fornecedores, funcionários, critérios, auditorias, certificados, equipamentos, produtos químicos, treinamentos, planos de ação, pedidos e relacionamentos com usuários.

No código disponível, foi encontrada apenas uma referência explícita a `esg_`, relacionada a `esg_relatorio_template_item`. O catálogo definitivo de tabelas ESG deve ser obtido diretamente do `INFORMATION_SCHEMA` do banco `green_modelo` no momento da execução.

## Estratégia da migração

O script:

1. consulta o catálogo do banco `green_modelo`;
2. seleciona somente tabelas físicas iniciadas por `fo_` ou `esg_`;
3. cria a tabela correspondente em `gmodelo_dbp` com `CREATE TABLE LIKE`;
4. copia os dados com `INSERT IGNORE`;
5. informa as contagens de origem, destino antes e destino depois;
6. classifica cada tabela como `OK` ou `VALIDAR`.

## O que não será migrado nesta etapa

Não serão migrados automaticamente:

- views;
- procedures;
- triggers;
- menus;
- configurações de transações;
- configurações de grids;
- filtros e queries do banco legado;
- telas PHP;
- permissões;
- relacionamentos funcionais com o novo modelo de usuários e grupos.

Esses elementos deverão ser analisados depois da migração estrutural.

## Pré-requisitos

Antes da execução:

1. confirmar acesso ao banco `green_modelo`;
2. confirmar que o destino correto é `gmodelo_dbp`;
3. fazer backup completo dos dois bancos;
4. executar em homologação;
5. verificar tabelas com o mesmo nome já existentes no destino;
6. comparar estruturas, chaves e índices;
7. identificar dependências externas entre tabelas;
8. validar quantidade de registros;
9. testar textos acentuados;
10. registrar a execução no histórico de migrações.

## Riscos identificados

- `CREATE TABLE LIKE` copia a estrutura e os índices, mas não deve ser tratado como migração completa de triggers, views ou regras de aplicação.
- `INSERT IGNORE` evita interrupção por duplicidade, mas qualquer diferença de estrutura deve ser analisada antes da execução.
- Chaves estrangeiras que dependam de tabelas fora dos prefixos `fo_` e `esg_` precisarão ser recriadas ou ajustadas separadamente.
- As tabelas podem estar em MyISAM ou com collations antigas. A conversão para InnoDB e `utf8mb4_0900_ai_ci` deve ocorrer após backup e validação textual, em etapa controlada.
- As referências legadas podem usar tabelas de segurança, unidades, empresas ou usuários com nomes e chaves diferentes do modelo atual.

## Validações após a migração

Executar, para cada tabela migrada:

- comparação de quantidade de linhas;
- comparação de chaves primárias;
- comparação de índices;
- verificação de registros duplicados;
- verificação de registros órfãos;
- verificação de caracteres acentuados;
- `CHECK TABLE`;
- `ANALYZE TABLE` após a estabilização;
- consulta de amostras do legado e do destino.

## Próximas etapas após a migração estrutural

1. gerar inventário final das tabelas migradas;
2. mapear as tabelas de segurança, empresas, unidades e usuários relacionadas;
3. definir o modelo de menus e permissões;
4. revisar engine e collations;
5. criar views padronizadas para os grids;
6. migrar configurações de transações e grids quando aplicável;
7. criar telas do módulo Homologação de Fornecedores;
8. criar telas do módulo ESG;
9. integrar responsabilidades e delegação;
10. testar CRUD, filtros, exportações e auditoria.

## Execução realizada

O script foi executado no banco `gmodelo_dbp` em 19/08/2026.

Resultado validado:

- tabelas físicas encontradas no legado: 114;
- tabelas `fo_` encontradas no legado: 38;
- tabelas `esg_` encontradas no legado: 76;
- tabelas físicas criadas ou confirmadas no destino: 114;
- tabelas `fo_` no destino: 38;
- tabelas `esg_` no destino: 76;
- tabelas ausentes no destino: nenhuma;
- tabelas com status de migração diferente de `OK`: nenhuma.

As contagens de registros foram comparadas durante a execução e todas as tabelas foram classificadas como `OK`.

## Registro oficial da migração

A execução foi registrada na tabela `gmodelo_dbp.registros_migracao`:

- `codregistromigracao`: `40`;
- `codigo_migracao`: `MODULOS_FO_ESG_082`;
- `origem`: `green_modelo.fo_*; green_modelo.esg_*`;
- `destino`: `gmodelo_dbp.fo_*; gmodelo_dbp.esg_*`;
- `tipo`: `DADOS`;
- `versao`: `1.0.0`;
- `status`: `CONCLUIDA`;
- `quantidade_registros`: `32292`;
- `script`: `database/082_migrar_modulos_fo_esg.sql`.

## Estado pós-migração

A migração estrutural e de dados foi concluída. O script não converteu engine ou collation, conforme definido no escopo inicial.

Na validação pós-migração foram identificadas as seguintes condições:

- tabelas `esg_`: `InnoDB`, em geral com `utf8mb4_unicode_ci`;
- tabelas `fo_`: combinação de `InnoDB` e `MyISAM`;
- tabelas `fo_`: `latin1_swedish_ci`.

Essas condições devem ser tratadas em uma etapa posterior, com backup, testes em homologação, verificação de chaves, análise de dependências e validação dos textos acentuados.

Nenhum menu, tela PHP, view funcional, permissão ou configuração de grid foi migrado nesta etapa.
