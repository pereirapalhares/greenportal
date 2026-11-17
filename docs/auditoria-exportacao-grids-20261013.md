# Auditoria de exportação dos grids

Data: 13/10/2026

## Resultado

Foi revisado o fluxo central de exportação utilizado pelos grids com o botão **Exportar Excel**:

- `classes/ManutencaoLegalCadastroGrid.php`, usado pelos cadastros administrativos e de manutenção legal;
- `classes/ModuloLegalRequisitosGrid.php`, que possui um fluxo específico para Requisitos legais;
- `classes/ExportacaoGridWorker.php`, responsável por consultar a view, normalizar os dados e gerar o XLSX;
- `assets/js/grid-pagination.js`, responsável por enviar busca, filtros, colunas visíveis e ordem atual do grid.

Também foram conferidas as transações declaradas no worker, incluindo Segurança, Administração, Módulo Legal, licenças, controles, legislação e grids pessoais.

## Problema encontrado

Nos grids genéricos, vários campos relacionais tinham `foreign.description` ou `foreign.label` configurado para mostrar o nome na tela, mas a solicitação da planilha não informava essa origem ao worker. Assim, o grid mostrava, por exemplo, o nome da unidade, enquanto o Excel podia receber `CODUNIDADE`.

## Correção aplicada

O exportador genérico agora aplica esta regra:

1. usa `export_from` quando a tela informar uma origem explícita;
2. caso não exista, usa automaticamente `foreign.description`;
3. se a descrição não existir, usa `foreign.label`;
4. só aplica a origem quando ela é uma coluna válida e diferente da coluna do código.

Dessa forma, a coluna exportada preserva o mesmo valor textual apresentado no grid, sem exigir alteração individual em cada tela.

As regras específicas já existentes para `seguranca_usuarios` foram preservadas: a coluna `senha` nunca é exportada e perfil, grupo e usuário superior usam seus nomes de apresentação.

## Validações realizadas

- Sintaxe PHP validada em `ManutencaoLegalCadastroGrid.php`.
- Sintaxe PHP validada em `ExportacaoGridWorker.php`.
- Sintaxe PHP validada em `ModuloLegalRequisitosGrid.php`.
- Exportação sintética de usuários validada anteriormente: perfil e grupo saem com nomes e `senha` não aparece.
- O fluxo do botão e o payload de busca, filtros, colunas visíveis e ordem do grid foram validados no Firefox.

## Observação

O fluxo específico de Requisitos legais trabalha com colunas de apresentação já existentes na view, como `UNIDADE`, `DSCAMBITO` e `DSCTIPO`; seus campos técnicos permanecem ocultos e não são incluídos quando o usuário não os visualiza.

Ainda é recomendável executar uma exportação real em cada módulo que tenha dados relacionais específicos após a atualização do PHP/Apache, pois o servidor pode manter código antigo em cache até o reload do processo.
