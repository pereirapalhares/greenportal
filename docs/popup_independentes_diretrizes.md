# Popups independentes

Popups abertos a partir de outro popup devem ser independentes. O fechamento do
popup filho deve remover somente o filho e preservar o popup pai no estado em
que estava.

## Regras

- Ajuda, histórico, detalhes e consultas secundárias devem usar
  `GreenPortalPopups.transacaoIndependente()`.
- Cada popup independente deve receber identificador único.
- O conteúdo deve ser carregado em iframe ou componente próprio sem substituir
  o conteúdo do popup pai.
- Ao fechar, somente o popup filho e seus recursos devem ser removidos.
- O popup pai não pode ser recarregado, ocultado ou recriado.
- Os textos devem respeitar o idioma atual (PT, EN e ES).

`GreenPortalPopups.transacao()` permanece para o popup principal da transação.

## Popup de associação em lista

Popups independentes usados para associar itens a um registro devem consultar e
exibir os itens associados e não associados. Os itens já associados aparecem
primeiro e permanecem marcados; os demais aparecem em seguida e permanecem
desmarcados. O filtro deve pesquisar sobre as duas partes da lista sem alterar
essa prioridade.

O botão `Confirmar` deve ficar na barra superior, antes do filtro. Não deve
haver botão `Cancelar` nem cabeçalho interno com título e botão de fechamento;
o fechamento deve ser realizado pelo popup pai após a confirmação ou pelo
controle padrão externo da modal.

O documento do popup deve impedir rolagem no `html` e no `body`, evitando uma
barra de rolagem no iframe. A rolagem vertical deve existir somente na área
das linhas da lista/grid, que deve ocupar o espaço disponível da modal.

## Listas extensas de associação

Quando o catálogo de associação possuir muitos itens, o popup não deve carregar
todo o catálogo no navegador. A consulta deve ser server-side, com limite de
25 itens por página, busca no banco e retorno do total de registros e da página
atual. A consulta deve excluir itens já associados quando o usuário estiver
selecionando uma nova associação e deve impedir duplicidades no servidor.

A navegação entre páginas deve ocorrer dentro do componente da lista, sem
recarregar o popup inteiro. O filtro deve retornar à primeira página. O
carregamento inicial e cada busca devem trazer somente o lote solicitado.
