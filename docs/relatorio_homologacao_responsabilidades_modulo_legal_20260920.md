# Relatório de homologação — Responsabilidades do Módulo Legal

Data: 20/09/2026  
Empresa: Modelo (`gmodelo_dbp`)

## Escopo

Foram validadas as transações de Treinamentos, Infraestrutura e Controle de Documentos, conforme as diretrizes do Módulo Legal e do modelo de permissões CRUD.

## Resultado

Resultado geral: **APROVADO**.

Foram confirmados:

- abertura das transações e uso dos filtros;
- CRUD com o perfil administrativo `ADM_DA_EMPRESA`;
- acesso somente para leitura com o perfil `GESTOR` (`ueditor`);
- inclusão e remoção controladas de responsabilidades;
- exibição das atribuições em `Meus Treinamentos` e `Minhas Infraestruturas`;
- bloqueio visual de inclusão, edição e exclusão sem a permissão correspondente;
- bloqueio server-side de acesso direto sem visualização, com HTTP 403.

O bloqueio direto foi confirmado para o usuário `visitante` nas três URLs:

- `/principal/modulolegal_treinamentos.php`;
- `/principal/modulolegal_InfraEstrutura.php`;
- `/principal/modulolegal_controle.php`.

## Evidências

- `tests/evidencias-permissao-treinamentos-infraestrutura-20260920/resultado.json`;
- `tests/evidencias-meus-treinamentos-20260920.json`;
- `tests/evidencias-minhas-infraestruturas-20260920.json`;
- `tests/evidencias-permissao-controle-documentos-20260920/resultado.json`;
- `tests/evidencias-acesso-direto-responsabilidades-20260920.json`.

Os perfis temporários e os registros criados para os testes foram restaurados ao final da execução.

## PER-006 — Medição e Monitoramento

O teste confirmou que o botão **Responsabilidades** habilita após a seleção de uma linha e abre a consulta contextual com o identificador correto:

`/principal/monitoramento_responsabilidades.php?CODMONITORAMENTO=1`

Foi corrigida uma falha no JavaScript: a coluna `CODMONITORAMENTO` usa um `<select>`, e a implementação anterior lia o texto concatenado das opções, gerando uma URL inválida. A leitura agora utiliza o valor do controle selecionado.

Evidência: `tests/evidencias-per006-monitoramento-20260920.json`.

## Próxima pendência

A próxima pendência priorizada é a **homologação definitiva do módulo Controle**, incluindo responsabilidades por usuário e grupo, os relacionamentos documentais, permissões e integração com `Meus Controles`.

## PER-010 — Responsabilidades em Infraestrutura

Resultado: **APROVADO**.

Foram confirmados o menu, o grid, a abertura contextual por `CODINFRAESTRUTURA`, o CRUD administrativo, o modo somente leitura para `ueditor` e o bloqueio direto para `visitante` com HTTP 403. O registro temporário criado durante o CRUD foi removido logicamente ao final.

Evidência: `tests/evidencias-per010-infraestrutura-20260920.json`.
