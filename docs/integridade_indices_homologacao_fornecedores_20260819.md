# Integridade e índices — Homologação de Fornecedores

**Data:** 19/08/2026  
**Banco:** `gmodelo_dbp`  
**Script:** `database/083_integridade_indices_fo.sql`

## Escopo

Corrigir a ausência de chaves primárias e índices nas tabelas migradas com prefixo `fo_`, preservando os registros legados e as duplicidades existentes.

## Resultado da auditoria

Foram identificadas 11 tabelas sem chave primária. Nove também não possuíam nenhum índice. Duas tabelas possuíam índices, mas não uma chave primária:

- `fo_tipofornecedoresxcriteriofornecedores`;
- `fo_tipofuncionariosxcriteriofuncionarios`.

As combinações de colunas de negócio não foram consideradas automaticamente como únicas porque foram encontradas duplicidades nos dados.

## Estratégia aplicada

Foi adotado um identificador técnico `BIGINT UNSIGNED AUTO_INCREMENT` nas tabelas sem identidade segura. Essa estratégia:

- não exclui registros;
- não elimina duplicidades;
- permite CRUD futuro por registro;
- cria uma chave estável para auditoria;
- permite adicionar foreign keys posteriormente, depois da validação dos dados.

Também foram criados índices para as colunas de relacionamento e consulta, sem impor unicidade onde ela não foi comprovada.

## Tabelas alteradas

- `fo_equipamentosxpalavraschave`;
- `fo_fornecedorxtipoxcriterio`;
- `fo_fornecedorxusuario_aux`;
- `fo_funcionariosxfornecedores`;
- `fo_funcionariosxtipofuncionarios`;
- `fo_produtoquimicoxpalavraschave`;
- `fo_tipofornecedoresxcriteriofornecedores`;
- `fo_tipofornecedoresxpalavraschave`;
- `fo_tipofuncionariosxcriteriofuncionarios`;
- `fo_tipofuncionariosxpalavraschave`;
- `fo_tipofuncionariosxtipotreinamentos`.

## Cuidados

A tabela `fo_fornecedorxtipoxcriterio` possui colunas com nomes descritivos, porém tipos `TINYINT`, e apresenta muitos valores repetidos. Ela deve ser analisada funcionalmente antes de ser usada como fonte de um grid ou relacionamento de negócio.

As foreign keys não foram criadas nesta etapa. Antes disso, devem ser auditados os registros órfãos entre fornecedores, funcionários, tipos, critérios, treinamentos, equipamentos, produtos químicos e palavras-chave.

## Próxima etapa

Depois da validação das chaves e índices:

1. converter as tabelas `fo_` para `InnoDB`;
2. converter `latin1_swedish_ci` para `utf8mb4_0900_ai_ci`;
3. validar textos acentuados;
4. auditar órfãos;
5. criar foreign keys em homologação;
6. executar `ANALYZE TABLE`;
7. criar views e grids do módulo.

## Execução realizada

O script foi executado em `gmodelo_dbp` após a criação do backup:

- backup: `database/backups/20260819_fo_integridade_pre_083.sql`;
- script executado: `database/083_integridade_indices_fo.sql`;
- tabelas alteradas: 11;
- registros preservados nas tabelas alteradas: 7.998;
- tabelas `fo_` sem chave primária após a execução: 0;
- tabelas `fo_` sem índice após a execução: 0;
- `CHECK TABLE` das 11 tabelas: `OK`.

## Registro oficial

A execução foi registrada em `gmodelo_dbp.registros_migracao`:

- `codregistromigracao`: `41`;
- `codigo_migracao`: `INTEGRIDADE_INDICES_FO_083`;
- `tipo`: `CORRECAO`;
- `status`: `CONCLUIDA`;
- `quantidade_registros`: `7998`.

As foreign keys e a normalização de engine/collation continuam pendentes para etapas posteriores, após auditoria de órfãos e validação dos textos.
