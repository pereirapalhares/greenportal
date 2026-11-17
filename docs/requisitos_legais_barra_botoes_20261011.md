# Requisitos legais — organização da barra de botões

Data: 11/10/2026 · Transação `modulolegal_requisitoslegais`

A barra foi dividida em três grupos rotulados (PT/EN/ES), separados por uma
linha vertical:

| Grupo | Botões (ordem) |
|---|---|
| Operações | Confirmar alteração · Recarregar · Exportar Excel · Colunas · Ajuda |
| Correlacionamentos | Documentos Licenciatórios · Medição e Monitoramento · Treinamentos · Infraestrutura · Controles · Homologação de Fornecedores |
| Avaliação | Situação · CheckList · Alterações · Questionário Legal · Análise de IA |

A paginação continua no fim da barra, depois dos grupos.

Nenhum comportamento mudou: atributos `data-*`, permissões, habilitação por
linha selecionada e a regra de "Influência" para Homologação de Fornecedores
são os mesmos.

## Arquivos

- `classes/ModuloLegalRequisitosGrid.php` — nova estrutura da barra
  (`data-req-grupo` / `data-req-slot`).
- `assets/css/requisitos-toolbar.css` — layout dos grupos e rótulos.
- `assets/js/requisitos-toolbar-grupos.js` — carregado depois de
  `grid-pagination.js`, que move Recarregar, Excel e Colunas para o fim da barra;
  o script devolve cada botão ao seu grupo, na ordem definida pelo `data-req-slot`.
