# Validação dos subitens do Módulo Legal — 23/09/2026

## Escopo

Rodada visual e funcional de acesso direto, carregamento do grid, dados visíveis, ações disponíveis, relacionamentos contextuais e telas direcionadas:

- LAIA;
- LPRO;
- LAIS;
- Controle;
- Meus Controles;
- Minhas Licenças;
- Minhas Infraestruturas.

## Execução

Foi usado o Chrome já iniciado por CDP, com os usuários autorizados `admin`, `ueditor`, `urevisor`, `uaprovador`, `gestor`, `responsavel` e `visitante`. Foram executadas 49 verificações (7 perfis × 7 telas), com captura visual por perfil e tela.

Evidências:

- Resultado: `tests/resultado-validacao-subitens-modulo-legal-20260923.json`;
- Capturas: `tests/evidencias-validacao-subitens-modulo-legal-20260923/`;
- Roteiro reproduzível: `scripts/validar-subitens-modulo-legal-cdp.js`.

## Resultado técnico

- 7/7 logins concluídos;
- 49/49 rotas processadas;
- 14 acessos HTTP 200, conforme as permissões atuais;
- 35 acessos HTTP 403, sem exposição da tela para perfis sem permissão;
- 0 erros PHP, SQL ou HTTP 5xx;
- grids permitidos carregados com dados;
- LAIA: 4 relacionamentos contextuais;
- LPRO: 6 relacionamentos contextuais;
- LAIS: 3 relacionamentos contextuais;
- Controle: 9 relacionamentos e o botão separado de Responsabilidades;
- Meus Controles: somente salvar, recarregar, exportar e configurar colunas;
- Minhas Licenças: escopo direcionado, mantendo edição conforme a permissão, sem inclusão, duplicação ou exclusão;
- Minhas Infraestruturas: escopo direcionado, sem inclusão, duplicação ou exclusão.

Status: **APROVADO_TECNICAMENTE** para a rodada de acesso, grid, escopo visual e permissões.

## Matriz observada

| Perfil | Acessos permitidos observados |
|---|---|
| ADMINISTRADOR | LAIA, LPRO, LAIS, Controle, Meus Controles, Minhas Licenças, Minhas Infraestruturas |
| EDITOR (`ueditor`) | Controle, Meus Controles, Minhas Infraestruturas |
| REVISOR (`urevisor`) | Nenhum dos sete subitens |
| APROVADOR (`uaprovador`) | Nenhum dos sete subitens |
| GESTOR (`gestor`) | Controle, Meus Controles, Minhas Infraestruturas |
| EMITENTE (`responsavel`) | Nenhum dos sete subitens |
| VISITANTE (`visitante`) | Controle |

Os 403 são tecnicamente corretos para a matriz atualmente gravada, mas continuam sujeitos à aprovação funcional caso a regra de negócio deseje liberar LAIA, LPRO, LAIS ou licenças direcionadas para algum perfil.

## Correções realizadas durante a rodada

1. Corrigido o warning `Undefined array key "label"` em `Meus Controles`, adicionando o rótulo do campo técnico oculto `CODUSUARIO`.
2. Ajustada a tela `Minhas Licenças` para impedir inclusão, duplicação e exclusão, preservando a edição sujeita às permissões.

## Pendências para completar a homologação funcional

Esta rodada não criou registros. Portanto, antes do aceite final do comportamento CRUD, ainda devem ser executados com dados temporários controlados e limpeza posterior:

1. inclusão, edição e exclusão em LAIA;
2. inclusão, edição e exclusão em LPRO;
3. inclusão, edição e exclusão em LAIS;
4. inclusão, edição e exclusão em Controle;
5. confirmação dos relacionamentos contextuais de cada tela;
6. persistência das operações diretamente no banco e remoção dos registros temporários;
7. exportação, configuração de colunas e arquivos nas telas direcionadas.

Até essas mutações controladas serem executadas, o resultado deve ser interpretado como homologação técnica de acesso, renderização, escopo e permissões — não como aceite integral de CRUD.

## Complemento CRUD executado

Após a rodada inicial, foi executado CRUD controlado pela interface com o usuário ADMINISTRADOR em LAIA, LPRO, LAIS e Controle. Cada caso realizou inclusão, recarregamento com filtro do marcador, edição, confirmação da persistência no banco, exclusão pela interface e confirmação da remoção.

- LAIA: aprovado;
- LPRO: aprovado;
- LAIS: aprovado;
- Controle: aprovado;
- marcadores temporários restantes no banco: zero.

Resultado detalhado: `tests/resultado-crud-laia-lpro-lais-controle-20260923.json`.

Com esse complemento, o aceite técnico de CRUD dessas quatro telas está aprovado para o perfil ADMINISTRADOR. Permanecem fora deste ciclo os testes de mutação nos perfis não administrativos e os testes dos popups/relacionamentos específicos.
