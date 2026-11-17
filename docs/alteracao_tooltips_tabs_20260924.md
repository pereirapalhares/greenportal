# Alteração de tooltips das tabs — 24/09/2026

## Objetivo

Remover somente as mensagens flutuantes exibidas sobre as tabs abertas, especialmente a mensagem `Fechar aba` exibida sobre o botão `X`.

Os tooltips dos demais componentes da aplicação foram preservados.

## Alteração realizada

Arquivo alterado:

- `assets/js/main.js`

Mudanças:

- removido o atributo `title` do botão de fechamento das tabs;
- mantido o `aria-label="Fechar aba"` para acessibilidade;
- excluída a área `.workspace-tabs` da inicialização global de tooltips.

O fechamento e a navegação das tabs permanecem inalterados.

## Validação

- Sintaxe JavaScript validada com `node --check assets/js/main.js`.
- Teste realizado no Chrome via CDP em `http://127.0.0.1:9222`.
- O botão de fechamento apresentou `title=null` e manteve `aria-label="Fechar aba"`.
- Após passar o mouse sobre o botão, a quantidade de tooltips visíveis nas tabs foi `0`.

## Escopo e impacto

A alteração é restrita às tabs do workspace. Tooltips de botões, ícones, campos e demais telas continuam disponíveis.
