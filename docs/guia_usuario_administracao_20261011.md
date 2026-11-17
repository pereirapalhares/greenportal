# Guia do usuário editável — Administração › Guia do usuário

Versão: GreenSolutions 4.0.1 · Migração 199 · 11/10/2026

## Objetivo

O guia aberto pelo ícone do livro (cabeçalho) passa a ter conteúdo mantido pela
própria aplicação. O guia é **único para todas as empresas**, fica no banco
`guniversal_dbp` e funciona com **rascunho + publicação**: alterações só chegam
aos usuários depois de "Publicar".

## Transação

| Item | Valor |
|---|---|
| Menu | Administração › Guia do usuário |
| Código | `ADMINISTRACAO_GUIA_USUARIO` |
| Tela | `principal/administracao_guia_usuario.php` |
| API | `principal/api/administracao_guia_usuario.php` |
| Serviço | `classes/GuiaUsuarioService.php` |
| JS / CSS | `assets/js/administracao-guia-usuario.js`, `assets/css/administracao-guia-usuario.css` |
| Leitura pelo usuário | `principal/guia_usuario.php` |

## Permissões

- A migração concede a transação somente ao perfil `ADMINISTRADOR` em cada tenant.
- Salvar, publicar e restaurar exigem **perfil ADMINISTRADOR** além da permissão
  `pode_editar`, porque o conteúdo vale para todas as empresas. Outro perfil que
  receber a transação abre a tela somente para leitura.
- Mutações exigem POST e token CSRF (`$_SESSION['guia_usuario_csrf']`).
- Toda ação é registrada em `logs_auditoria` do tenant do usuário
  (`SALVAR`, `PUBLICAR`, `RESTAURAR`, inclusive tentativas negadas e erros).

## Banco de dados (guniversal_dbp)

| Objeto | Uso |
|---|---|
| `guia_usuario_rascunho` | Uma única linha com o rascunho (JSON) e o número de revisão. |
| `guia_usuario_versoes` | Versões publicadas, imutáveis, com autor, data, observação e hash. |
| `vw_guia_usuario_publicado` | Versão vigente (maior número). |
| `vw_guia_usuario_versoes` | Histórico sem o conteúdo completo. |
| `vw_guia_usuario_rascunho` | Rascunho + indicador `pendente` (diferente do publicado). |
| `sp_guia_usuario_salvar_rascunho` | Grava o rascunho com controle de revisão. |
| `sp_guia_usuario_publicar` | Cria nova versão a partir do rascunho. |
| `sp_guia_usuario_restaurar_rascunho` | Copia uma versão para o rascunho (Restaurar / Descartar). |
| `sp_guia_usuario_carga_inicial` | Carga inicial idempotente. |

**Concorrência:** cada gravação informa a revisão que o administrador abriu. Se
outro administrador salvou antes, a procedure recusa (`CONFLITO_REVISAO`) e a
tela pede para recarregar — nenhuma alteração é sobrescrita sem aviso.

**Publicação:** só publica o que foi salvo e se o rascunho for diferente da
versão vigente. Abas marcadas como ocultas podem ficar incompletas sem bloquear
a publicação das demais.

## Conteúdo (JSON)

```text
{ formato: 1,
  perfis:  { <chave>: { icone, nome{pt,en,es} } },          // "sistema" é obrigatório
  modulos: [ { id, icone, ativo, nome{}, resumo{}, perfis[], perfis_nota{},
               fases:   [ { nome{}, passos: [ { tipo: M|A, perfil, titulo{}, texto{}, onde{} } ] } ],
               tarefas: [ { titulo{}, passos: [ {pt,en,es} ] } ],
               menu:    [ { caminho{}, texto{} } ],
               termos:  [ { termo{}, texto{}, dica } ] } ] }
```

O servidor normaliza todo conteúdo recebido (lista fechada de campos, limites de
tamanho, remoção de HTML, códigos validados). Textos vazios em EN/ES são exibidos
em português.

Os textos fixos da tela do guia (botões, legendas) continuam em
`principal/guia_usuario_conteudo.php`. Esse arquivo também é a **reserva**: se o
banco universal estiver indisponível ou sem versão publicada, o guia mostra o
conteúdo padrão do arquivo.

## Links diretos para as transações (migração 201)

Etapas do fluxo, itens de "Como fazer" e de "Onde encontrar" têm o campo opcional
`transacao`, com o **código do menu** (`guniversal_dbp.menus.codigo`). No guia, o
item mostra o botão **Abrir tela**:

- o link só é exibido se o menu existir, estiver ativo, tiver URL e o usuário
  tiver `pode_visualizar` na transação (`PermissaoTransacao`); sem permissão,
  aparece apenas o texto;
- dentro do portal, o clique chama `window.top.greenPortalOpenTab('menu-<codigo>', url, título)`
  — a mesma aba aberta pelo menu é reaproveitada — e fecha o popup do guia;
  fora do portal, abre a tela em nova janela;
- no editor, o campo **Link direto para a tela** lista os menus ativos com URL
  (ação `menus` da API). Códigos que não existem mais aparecem com aviso.

`scripts/aplicar-migracao-201-guia-usuario-links.php` preenche os links sugeridos
no conteúdo padrão apenas em itens ainda sem link e com o mesmo título em
português. Se o rascunho estava igual ao publicado, publica uma nova versão; se
havia alterações pendentes, grava só no rascunho. O resultado lista os códigos
que não foram encontrados no menu, para revisão. A migração também acrescenta a
seção 15 (Link direto) à ajuda da tela, sem alterar o restante do texto.

## Ajuda da tela

O botão **Ajuda** da barra superior segue o padrão do portal
(`data-open-transacao-ajuda`, `assets/js/ajuda-transacao.js`) e abre
`setup_ajuda.php?transacao=administracao_guia_usuario` no popup comum. O texto
detalhado (PT/EN/ES, 15 seções) fica em `guniversal_dbp.ajuda_transacoes` e é
carregado pela migração 200. Ele pode ser ajustado em
**Configurações › Ajuda das transações**; reaplicar a migração 200 não
sobrescreve um texto já ajustado.

## Pré-visualização

- `guia_usuario.php?preview=rascunho` — rascunho atual.
- `guia_usuario.php?preview=versao&codversao=N` — uma versão do histórico.

Disponível apenas para quem tem a transação; os demais usuários sempre veem a
versão publicada.

## Implantação

```powershell
E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-199-guia-usuario.php
E:\wamp\bin\php\php8.4.15\php.exe scripts\testar-guia-usuario-199.php
E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-200-ajuda-guia-usuario.php
E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-201-guia-usuario-links.php
```

A migração é idempotente; a carga inicial só acontece quando ainda não existe
versão publicada. Os tenants são lidos de `guniversal_dbp.empresa`
(fallback: `gatlas_dbp`, `gmodelo_dbp`, `gmodelo2_dbp`). Após aplicar, o usuário
precisa sair e entrar de novo para o menu aparecer (permissões ficam na sessão).

O teste 199 é não destrutivo: não grava nem publica no guia.

## Roteiro de teste manual

1. Entrar como ADMINISTRADOR › Administração › Guia do usuário.
2. Alterar o título de uma etapa em PT e EN, salvar (Ctrl+S) e conferir o aviso
   "Rascunho com alterações não publicadas".
3. Pré-visualizar: o popup mostra a faixa amarela de pré-visualização.
4. Abrir o guia pelo ícone do livro: ainda mostra a versão anterior.
5. Publicar com observação; abrir o guia pelo livro: mostra a alteração.
6. Versões › Restaurar a versão anterior no rascunho; publicar para desfazer.
7. Abrir a mesma tela em duas abas, salvar em uma e depois na outra: a segunda
   deve receber o aviso de conflito.
8. Entrar com um perfil diferente de ADMINISTRADOR: o menu não aparece e a API
   recusa alterações (HTTP 403).
