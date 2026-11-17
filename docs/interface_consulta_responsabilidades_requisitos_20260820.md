# Interface de responsabilidades em Requisitos Legais

## Funcionamento

No grid principal de Requisitos Legais, a legislação e a unidade representam um agrupamento de situações. Por isso, a consulta de responsabilidades foi integrada ao grid filho de **Situações**.

1. Abra Requisitos Legais.
2. Selecione uma legislação/unidade e abra **Situação**.
3. Selecione uma situação específica.
4. Clique no botão **Responsabilidades**.

A consulta usa `legislacaoxsituacao.CODSITUACAO` como registro da responsabilidade.

## Dados exibidos

A consulta apresenta finalidade, usuário ou grupo responsável, origem, situação do vínculo e período de validade. Responsabilidades históricas permanecem visíveis para rastreabilidade.

## Implementação

- View: `vw_responsabilidade_requisitos`.
- Script: `database/103_view_responsabilidades_requisitos.sql`.
- Endpoint: `principal/requisito_responsabilidades.php`.
- Integração: `principal/manutencaolegalrequisitoslegais_situacao.php` e `assets/js/requisitos-responsabilidades.js`.

O endpoint valida sessão, tenant e permissão de visualização da transação `REQUISITOS_LEGAIS`, usando `sp_crud_select`.

## Validação inicial

Em `gmodelo_dbp`, a view retornou 923 vínculos: 388 ativos e 535 históricos. A amostra confirmou a associação correta entre `CODSITUACAO`, legislação, responsável e tipo `REQUISITO_LEGAL`.

## Homologação de integridade

Executada em 20/08/2026, sem alteração de dados:

- nenhuma duplicidade ativa encontrada;
- nenhuma vigência inválida encontrada;
- 923 vínculos na view e 923 atribuições correspondentes na tabela;
- nenhum vínculo sem `CODSITUACAO`;
- PHP da integração aprovado na verificação de sintaxe.

## Manutenção das responsabilidades

Foi adicionada a manutenção por situação em `principal/requisito_responsabilidades_edicao.php`. No grid de Situações existem os botões **Responsabilidades** e **Manter responsabilidades**.

A manutenção permite incluir e alterar vínculos ativos de usuário ou grupo, finalidade, prioridade, principalidade e vigência. A exclusão é lógica, mantendo o registro histórico. As procedures de responsabilidade validam destino, datas e duplicidade.

Os registros históricos não são editados nem removidos pela tela.
