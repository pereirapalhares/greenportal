# Relacionamento do requisito legal com Homologação de Fornecedores

## Regra operacional

O grid de Requisitos Legais possui o botão **Homologação de Fornecedores** na barra de ações acima do grid. O botão somente fica habilitado quando existe uma linha selecionada e o campo `PODER` do requisito está classificado como `Influência`. Para `Conhecimento`, `Controle` ou classificação vazia, o botão permanece desabilitado.

## Tela de relacionamento

O botão abre a tela independente `principal/manutencaolegalrequisitoslegais_homologacao.php` em popup com iframe. O contexto é formado por `CODLEGISLACAO` e `CODUNIDADE` do requisito selecionado.

O grid exibe somente a coluna **Razão Social**. Ao incluir uma linha, essa coluna apresenta um combobox em ordem alfabética contendo somente fornecedores ainda não associados ao requisito e à unidade. O usuário pode incluir ou excluir associações e confirmar/salvar a operação; não há edição de dados do fornecedor, duplicação, exportação ou personalização de colunas nessa tela. Os botões permanecem acima do grid, sem ações dentro das linhas. A inclusão e a exclusão utilizam exclusivamente as procedures genéricas de CRUD; a leitura utiliza a view contextual.

Linhas já associadas exibem a Razão Social em modo somente leitura e não permitem alteração do fornecedor. O combobox é criado somente para linhas novas; após a confirmação, a associação deixa de ser editável e pode apenas ser excluída.

As operações de inclusão e exclusão são enviadas por AJAX. Após a resposta positiva da procedure, somente o tbody é recarregado dentro do iframe, preservando explicitamente `CODLEGISLACAO` e `CODUNIDADE`; o popup permanece aberto.

## Banco de dados

- Tabela: `gmodelo_dbp.legislacaoxfornecedores`;
- View: `gmodelo_dbp.vw_modulolegal_requisitos_homologacao_grid`;
- Chave técnica: `CODLEGISLACAOXFORNECEDOR`;
- Chave contextual: `CODLEGISLACAO`, `CODUNIDADE`;
- Fornecedor: `CODFORNECEDOR`, exibido pela view de homologação;
- Migração: `database/141_modulo_legal_homologacao_influencia.sql`.

## Idiomas e segurança

Os títulos, rótulos e tooltips foram definidos em PT, EN e ES. A permissão de visualização e manutenção segue a transação `REQUISITOS_LEGAIS`; a regra de `Influência` é adicional e obrigatória para abrir o relacionamento.
