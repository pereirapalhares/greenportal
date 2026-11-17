# Correção do popup de alterações dos Requisitos Legais

## Problema identificado

O popup **Alterações** da tela de Requisitos Legais usava a view
`vw_modulolegal_requisitos_alteracoes_grid`, mas essa view estava criada
somente em `gmodelo_dbp`. A rota do popup consulta `glegislacao_dbp`, o que
provocava o erro “Tabela ou view não autorizada ou inexistente”.

## Correção aplicada

Foi criada a migration `database/118_corrigir_view_requisitos_alteracoes.sql`.
Ela cria a view no banco correto, `glegislacao_dbp`, expondo:

- `codalteracao`;
- `CODLEGISLACAO`;
- `tipo`;
- `descricao`;
- `data_da_alteracao`;
- `DSCTIPODEALTERACAO`.

A view relaciona `legislacaoxalteracao` com `tipo_de_alteracao` e usa
`SQL SECURITY INVOKER`.

## Regra de contexto

O arquivo `principal/manutencaolegalrequisitoslegais_alteracoes.php` recebe o
`CODLEGISLACAO` do requisito selecionado e o envia como filtro para o grid.
Assim, o popup mostra somente as alterações do requisito escolhido. Não deve
ser exibida uma lista geral de alterações de todas as legislações.

## Validação técnica

Após a aplicação da migration:

- a view existe em `glegislacao_dbp`;
- a view possui 29.263 registros disponíveis para consulta contextual;
- o filtro `CODLEGISLACAO=38548` retornou somente registros desse requisito;
- PHP e JavaScript passaram na verificação de sintaxe.

O teste visual deve ser executado com:

```powershell
.\scripts\executar-teste-requisitos-legais.ps1 -Empresa Modelo -Usuario admin -Senha "<senha de teste via variável de ambiente>" -Visivel
```

O roteiro também valida dois requisitos distintos na mesma página e confirma
que o popup de alterações do segundo requisito mantém o `CODLEGISLACAO`
selecionado e não mistura registros do primeiro requisito.
