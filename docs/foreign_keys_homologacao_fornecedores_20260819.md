# Foreign keys — Homologação de Fornecedores

**Data:** 19/08/2026  
**Banco:** `gmodelo_dbp`  
**Script:** `database/085_foreign_keys_fo_validas.sql`

## Critério de aplicação

As foreign keys foram criadas somente quando:

- a tabela pai existia no mesmo banco;
- os tipos das colunas eram compatíveis;
- não havia registros órfãos; ou
- a tabela filha estava vazia.

## Foreign keys aplicadas

Foram adicionadas constraints para:

- avaliações x fornecedores e critérios;
- certificados x fornecedores;
- equipamentos x fornecedores;
- fornecedores x tipos de fornecedor;
- fornecedores x critérios;
- funcionários x critérios;
- frequência de avaliação x classificação;
- lotes x fornecedores e pedidos;
- pedidos x fornecedores;
- produtos químicos x fornecedores;
- tipos de fornecedores x critérios;
- funcionários x tipos de funcionários;
- fornecedor x tipo x critério.

## Relacionamentos bloqueados por órfãos

As seguintes relações não receberam foreign key porque possuem dados órfãos no legado:

- `fo_contatos.codfornecedor` → `fo_fornecedores`: 3 órfãos;
- `fo_fornecedoresxtipofornecedores.codfornecedor` → `fo_fornecedores`: 160 órfãos;
- `fo_fornecedorxcriterios.codfornecedor` → `fo_fornecedores`: 1.352 órfãos;
- `fo_fornecedorxusuario.CODFORNECEDOR` → `fo_fornecedores`: 62 órfãos;
- `fo_funcionarios.CODFORNECEDOR` → `fo_fornecedores`: 30 órfãos;
- `fo_funcionarios.CODTIPOFUNCIONARIO` → `fo_tipofuncionarios`: 5 órfãos;
- `fo_funcionarioxtiposxcriterios.codfuncionario` → `fo_funcionarios`: 296 órfãos;
- `fo_funcionarioxtiposxcriterios.codcriteriofuncionario` → `fo_criteriofuncionarios`: 835 órfãos;
- `fo_funcionarioxtiposxtreinamentos.codfuncionario` → `fo_funcionarios`: 4 órfãos;
- `fo_funcionarioxtiposxtreinamentos.codtipofuncionario` → `fo_tipofuncionarios`: 6 órfãos;
- `fo_funcionarioxtiposxtreinamentos.codtipotreinamento` → `fo_tipotreinamentos`: 41 órfãos;
- `fo_planodeacao.codfornecedor` → `fo_fornecedores`: 1 órfão.

Esses registros devem ser saneados ou classificados antes de qualquer tentativa de criar as constraints pendentes.

## Execução realizada

As foreign keys validadas foram aplicadas com sucesso:

- foreign keys criadas: 21;
- foreign keys bloqueadas por órfãos: 12;
- tabelas verificadas com `CHECK TABLE`: status `OK`;
- registro oficial: `gmodelo_dbp.registros_migracao`, código `FOREIGN_KEYS_FO_085`, ID `43`.

As relações bloqueadas permanecem sem constraint até que os registros órfãos sejam saneados ou formalmente classificados.
