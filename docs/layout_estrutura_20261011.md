# Estrutura visual do portal — cabeçalho, menu lateral e rodapé

Data: 11/10/2026 · GreenSolutions 4.0.1

## Decisão

Adotado o estilo **"Cabeçalho colorido"** (opção B) para as três paletas do
Setup › Layout (`verde`, `azul`, `cinza`):

- **Cabeçalho:** degradê na cor do tema, textos e ícones brancos, empresa e
  usuário em destaque claro.
- **Menu lateral:** fundo claro tingido da cor do tema; item ativo com fundo
  sólido na cor do tema e texto branco.
- **Rodapé:** mesmo tom claro do menu lateral.
- As telas internas (transações em abas e popups) não mudam.

## Implementação

- `assets/css/layout-estrutura.css` — camada visual carregada **depois** de
  `main.css`; não altera os arquivos existentes. As cores de cada tema ficam em
  variáveis `--ge-*` no início do arquivo.
- `principal/main.php` — inclui o novo CSS.
- `principal/setup_layout.php` — inclui o novo CSS para que a prévia
  ("Visualizar") mostre a nova estrutura.

## Reverter

Remover a linha `layout-estrutura.css` de `main.php` e `setup_layout.php`.

## Ajustar cores

Editar as variáveis `--ge-cab-ini`, `--ge-cab-fim` (degradê do cabeçalho),
`--ge-cor` (item ativo), `--ge-tinta` e `--ge-tinta-forte` (menu e rodapé) do
tema desejado e incrementar `?v=` no link do CSS para limpar o cache.

## Ícones do cabeçalho (11/10/2026)

- Ícones do menu, alertas e guia apresentados soltos, sem moldura, em branco;
  ao passar o mouse ficam mais claros e levemente ampliados.
- Dica com o nome do ícone ao passar o mouse, gerada pelo `aria-label`
  (Alertas / Guia do usuário, em PT, EN e ES, definidos em `main.php`).
- Selo de alertas maior, com borda na cor do cabeçalho e um pulso curto quando
  há alertas novos (desligado para quem prefere menos animação).
- Logotipo no próprio formato circular, sem moldura quadrada; nome do produto mais forte; texto do
  cabeçalho separado por uma linha fina.
- Avatares da empresa e do usuário com anel branco; área do usuário destacada
  ao passar o mouse.
