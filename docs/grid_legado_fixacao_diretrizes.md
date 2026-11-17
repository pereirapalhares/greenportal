# Fixação dos grids conforme o legado

Quando uma transação moderna precisa reproduzir um grid legado, a configuração legada deve ter prioridade sobre as preferências individuais salvas no navegador ou no banco.

O componente `ManutencaoLegalCadastroGrid` recebe `legacy_order` para ordenar as colunas e `legacy_fixed` para impedir que preferências antigas alterem a sequência, a visibilidade e as larguras oficiais. Campos técnicos, como `linha` e a chave primária técnica, permanecem ocultos.

Essa regra foi aplicada ao grid principal do LAIA em `principal/modulolegal_laia.php`. A versão do JavaScript `assets/js/manutencao-legal-cadastro.js` deve ser incrementada quando a lógica de ordenação ou cache for modificada, permitindo que o navegador carregue a correção atualizada.

Após uma alteração de grid, deve-se validar a sintaxe PHP e recarregar a tela com `Ctrl + F5` antes de comparar o resultado visual com o legado.
