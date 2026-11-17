# Auditoria de isolamento entre tenants — 2026-10-04

## Problema identificado

As transações que utilizam `ManutencaoLegalCadastroGrid` mantinham `gmodelo_dbp` na configuração da página e a classe usava esse valor para abrir a conexão efetiva do grid. Assim, um usuário autenticado no tenant Atlas podia consultar dados do Modelo.

## Correção aplicada

`classes/ManutencaoLegalCadastroGrid.php` passou a usar exclusivamente `$_SESSION['tenant']['banco_dados']` para a conexão efetiva do grid. O mesmo banco da sessão também é gravado na configuração das exportações, evitando que o worker processe a planilha em outro tenant.

As configurações históricas `database => gmodelo_dbp` permanecem apenas como metadados da transação; não controlam mais a conexão da tela.

## Evidências da validação

- Cadastro central: Atlas → `gatlas_dbp`; Modelo → `gmodelo_dbp`.
- Atlas e Modelo possuem administradores distintos.
- Quantidade de licenças: Atlas 200; Modelo 46.
- Login visual no Firefox como `admin`/Atlas exibiu `Administrador Atlas` e empresa `Atlas`.
- A tela de Licenças após o login Atlas carregou o grid do tenant Atlas, com 25 registros na página e dados próprios do Atlas.
- Sessão WebDriver encerrada após o teste.
- PHP validado sem erros de sintaxe.

## Escopo revisado

As páginas CRUD baseadas na classe corrigida passam a respeitar o tenant da sessão, incluindo grids administrativos, contextuais, relacionamentos e exportações. Rotinas de diagnóstico temporárias que possuem banco fixo não fazem parte do fluxo funcional da aplicação e não são utilizadas pela navegação normal.

## Pendência recomendada

Reexecutar a matriz visual de menus e grids com Atlas e Modelo, incluindo popups e downloads, para confirmar cada rota específica após a correção.
