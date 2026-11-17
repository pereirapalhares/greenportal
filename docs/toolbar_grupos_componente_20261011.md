# Barra de botões em grupos — componente reutilizável

Data: 11/10/2026 · GreenSolutions 4.0.1

Separa, na barra de um grid, os botões **operacionais** (CRUD, recarregar,
Excel, colunas, ajuda) dos **correlacionamentos** e da **avaliação**, com um
rótulo pequeno sobre cada grupo (PT/EN/ES) e uma linha vertical entre eles.

## Arquivos

- `assets/js/toolbar-grupos.js` — move os botões existentes para os grupos.
  Não cria nem altera botões: atributos, eventos e permissões continuam os
  mesmos. Roda depois de `grid-pagination.js` e a cada recarga do grid.
  Botões não previstos na configuração vão para um grupo sem rótulo no fim,
  para nunca sumirem. Grupos vazios ficam ocultos.
- `assets/css/toolbar-grupos.css` — visual dos grupos.

## Como aplicar em uma tela

No fim da página da transação:

```php
<link href="../assets/css/toolbar-grupos.css?v=1" rel="stylesheet">
<script type="application/json" data-toolbar-grupos-config><?= json_encode([
    'fragmento' => '.minha-tela-grid-fragment',
    'grupos' => [
        ['id' => 'operacoes', 'rotulo' => 'Operações', 'botoes' => ['[data-crud-action="save"]', '[data-crud-action="new"]', ...]],
        ['id' => 'correlacionamentos', 'rotulo' => 'Correlacionamentos', 'botoes' => ['[data-...]']],
        ['id' => 'avaliacao', 'rotulo' => 'Avaliação', 'botoes' => ['[data-context-id^="questionario-"]']],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="../assets/js/toolbar-grupos.js?v=1"></script>
```

## Telas que usam

| Tela | Operações | Correlacionamentos | Avaliação |
|---|---|---|---|
| Licenças / Minhas Licenças (`manutencaolegal_licencas.php`) | Salvar · Novo · Duplicar · Excluir · Recarregar · Excel · Colunas · Ajuda | Condicionantes · Palavras-chave · Responsabilidades · Arquivos | Questionário SGA |

Em Minhas Licenças, Novo, Duplicar e Excluir não existem e simplesmente não aparecem.

Requisitos legais usa uma versão própria anterior (`requisitos-toolbar-grupos.js`),
com o mesmo visual.
