# Homologação de Fornecedores — transações migradas

Data: 2026-08-19

## Transações

- Gestão de Fornecedores: `principal/homologacaofornecedor_fornecedor.php`
- Gestão de Funcionários: `principal/homologacaofornecedor_funcionario.php`
- Menu: `Homologação de Fornecedores`
- Banco de dados: `gmodelo_dbp`

## Origem funcional

As colunas, filtros e ordem foram baseados em `fo_fornecedor.php`, `fo_fornecedorxauditoria_c.php`, `fo_funcionarios.php` e `fo_gestao_funcionarios_c.php`.

As chaves estrangeiras são apresentadas por descrição e, quando editáveis, por combobox ordenado alfabeticamente. Unidades usam `vw_unidades_grid`, usuários usam `vw_usuarios_grid`, tipos de funcionários usam `vw_homologacao_tipos_funcionarios_grid` e fornecedores usam `vw_homologacao_fornecedores_grid`.

## Estrutura criada

O script `database/087_homologacao_fornecedores_menu_views.sql` criou:

- `vw_homologacao_fornecedores_grid`;
- `vw_homologacao_funcionarios_grid`;
- `vw_homologacao_tipos_funcionarios_grid`;
- menu e submenus na tabela `gmodelo_dbp.menus`;
- permissões na tabela `gmodelo_dbp.permissoes`;
- registro `HOMOLOGACAO_FORNECEDORES_087` em `gmodelo_dbp.registros_migracao`.

## Validação inicial

- Fornecedores retornados pela view: 514.
- Funcionários retornados pela view: 1.108.
- As três views foram criadas no `gmodelo_dbp`.
- O submenu de funcionários permite associar uma nova linha a um fornecedor por combobox.

## Acentuação

O script `database/088_corrigir_acentuacao_fo.sql` corrige textos migrados com interpretação incorreta de UTF-8/latin1 nas colunas textuais das tabelas `fo_`. A execução utiliza o backup `database/backups/20260819_fo_pre_088.sql` e foi registrada em `registros_migracao` como `CORRECAO_ACENTUACAO_FO_088`.

Os relacionamentos legados de auditoria, contatos, certificados, produtos químicos, equipamentos, critérios, treinamentos e plano de ação permanecem representados nas tabelas `fo_` migradas e deverão receber telas específicas CRUD quando forem disponibilizados como transações do módulo.

## Relacionamentos implementados

Os oito relacionamentos do fornecedor foram implementados com botões específicos na barra da tela principal:

- `homologacaofornecedor_fornecedorxcontatos.php`;
- `homologacaofornecedor_fornecedorxcertificados.php`;
- `homologacaofornecedor_fornecedorxauditoria.php`;
- `homologacaofornecedor_fornecedorxcriterios.php`;
- `homologacaofornecedor_fornecedorxfuncionarios.php`;
- `homologacaofornecedor_fornecedorxprodutosquimicos.php`;
- `homologacaofornecedor_fornecedorxequipamentos.php`;
- `homologacaofornecedor_fornecedorxplanodeacao.php`.

Cada popup recebe o `CODFORNECEDOR` da linha selecionada. O contexto é aplicado na consulta, inclusão, alteração e exclusão; sem fornecedor selecionado o grid não retorna registros. As views e a integração foram registradas no banco pelo script `database/089_homologacao_fornecedores_relacionamentos.sql`, com o código `HOMOLOGACAO_FORNECEDORES_RELACIONAMENTOS_089`.

## Relacionamentos dos funcionários

Foram adicionados à tela `homologacaofornecedor_funcionario.php` os botões para:

- `homologacaofornecedor_funcionarioxcriterios.php`;
- `homologacaofornecedor_funcionarioxtreinamentos.php`.

Esses grids recebem simultaneamente `CODFUNCIONARIO` e `CODTIPOFUNCIONARIO`, conforme o legado `fo_gestao_funcionarios_c.php`. O filtro combinado evita apresentar critérios ou treinamentos de outro funcionário ou de outro tipo funcional. As views foram criadas pelo script `database/090_homologacao_fornecedores_funcionarios_relacionamentos.sql`, registrado como `HOMOLOGACAO_FORNECEDORES_FUNCIONARIOS_RELACIONAMENTOS_090`.
