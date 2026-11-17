# Assistente de IA e Solicitações de melhoria — acesso ao banco por views e procedures

Data: 13/10/2026 · Migração 246

## Regra

Toda interação da aplicação com o banco é feita por **views** (leitura) e **procedures** (leitura e gravação).
As classes `AssistenteAplicacaoService` e `SolicitacaoMelhoriaService`, a API `principal/api/assistente_ia.php`
e o componente `components/solicitacoes_pagina.php` não têm mais SQL escrito: chamam somente procedures, por
`AssistenteAplicacaoService::chamarProcedure()` (nome validado `sp_*`, parâmetros posicionais; mensagens de regra do
banco — SIGNAL 45000 / erro 1644 — viram mensagem para a tela).

Tabelas, dados e comportamento não mudaram (migrações 225, 230 e 236).

## Objetos — banco de cada cliente (`database/246_assistente_procedures.sql`)

| Objeto | Uso |
|---|---|
| `vw_ia_assistente_mensagens` | mensagens com a solicitação de melhoria gerada (`codsolicitacao`) |
| `vw_solicitacoes_melhoria` | solicitações com o nome de quem pediu |
| `vw_solicitacoes_melhoria_historico` | histórico com o nome de quem alterou |
| `sp_ia_assistente_config_obter` | configuração do assistente |
| `sp_ia_assistente_recursos` | etapa 2 (solicitações) instalada? |
| `sp_ia_assistente_usados_hoje` | perguntas respondidas hoje (limite diário) |
| `sp_ia_assistente_conversa_atual` / `_garantir` / `_encerrar` | conversa do usuário |
| `sp_ia_assistente_mensagens` | mensagens visíveis de uma conversa |
| `sp_ia_assistente_mensagem_gravar` | grava pergunta/resposta (dados opcionais em JSON) |
| `sp_ia_assistente_mensagem_sem_resposta` | marca a pergunta que ficou sem resposta |
| `sp_ia_assistente_mensagem_classificar` | categoria/necessidade da pergunta |
| `sp_ia_assistente_avaliar` | "ajudou / não ajudou" |
| `sp_ia_assistente_mensagem_origem` | resposta + pergunta que a originou (para registrar solicitação) |
| `sp_ia_assistente_sinais` / `_sinais_resumo` | Gestão › Sinais do assistente |
| `sp_solicitacao_melhoria_registrar` | solicitação + 1º histórico, numa transação; recusa sugestão já registrada |
| `sp_solicitacoes_melhoria_minhas` / `_listar` / `_totais` | consultas |
| `sp_solicitacao_melhoria_obter` / `_historico` / `_da_mensagem` | consultas |
| `sp_solicitacao_melhoria_atualizar` | situação/escopo/resposta + histórico, numa transação; valida a original quando DUPLICADA e protege contra alteração simultânea |
| `sp_alerta_registrar` | alerta para quem pediu |
| `sp_auditoria_registrar` | registro em `logs_auditoria` |

## Objetos — `guniversal_dbp` (`database/246_assistente_procedures_universal.sql`)

| Procedure | Uso |
|---|---|
| `sp_assistente_ajuda_transacoes(idioma)` | ajuda das telas (botão "?") para a base do assistente |
| `sp_assistente_menus_urls` | telas que são menu |
| `sp_guia_usuario_publicado_obter` | guia do usuário publicado |
| `sp_menus_nomes_telas(idioma)` | "Menu › Tela" para exibir a origem das solicitações |

## Execução

```
E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-246-assistente-procedures.php --simular
E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-246-assistente-procedures.php
```

Registrada em `registros_migracao` como `ASSISTENTE_PROCEDURES_246`. Pode ser executada mais de uma vez.
As classes desta versão dependem dos objetos: sem a migração o assistente fica oculto e as telas de solicitações
mostram "não instalado".

## Testes realizados (ambiente local)

Estado, pergunta (CPF mascarado, ações, categoria, tokens), avaliação, falha da IA (pergunta marcada
SEM_RESPOSTA e fora do limite), rascunho e registro de solicitação pelo assistente, recusa de sugestão já registrada,
criação pela tela (com e sem título), Minhas solicitações, Gestão (filtros, busca por texto e número, totais),
mudança de situação com histórico, alerta e auditoria, DUPLICADA com original inexistente (recusada), detalhe e
sinais.

## Fora do escopo

Scripts de manutenção (`scripts/*.php`, rodados pelo terminal) e serviços de infraestrutura compartilhados
(ex.: `AuditService`) ainda usam SQL próprio.
