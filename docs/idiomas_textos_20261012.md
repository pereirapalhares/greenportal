# Textos e idiomas da interface (migração 211) — 12/10/2026

Os rótulos, dicas e mensagens das telas passam a ficar em `guniversal_dbp`, em PT/EN/ES, e são mantidos em
**Administração › Textos e idiomas** (`principal/administracao_idiomas.php`). Piloto: **Módulo Legal › Controle**
(`modulolegal_controle.php`) e os textos **comuns** dos grids.

## Decisões
- Textos únicos para todas as empresas.
- Edição em rascunho; só chega às telas depois de **Publicar** (histórico com antes/depois).
- Grupo **COMUM**: botões do grid, paginação, confirmações, carregamento, rótulos dos grupos de botões — vale para todas as telas.
- Somente o perfil ADMINISTRADOR altera (permissão da transação `ADMINISTRACAO_IDIOMAS` + perfil), com CSRF e auditoria no tenant.

## Banco (guniversal_dbp)
| Objeto | Uso |
|---|---|
| `idiomas` | pt, en, es |
| `idioma_grupos` | COMUM, MODULOLEGAL_CONTROLE (um grupo por transação) |
| `idioma_textos` | chave única (`comum.botao.salvar`, `modulolegal_controle.coluna.CODIGO`), tipo, onde aparece |
| `idioma_traducoes_rascunho` | texto em edição, com revisão (concorrência otimista) |
| `idioma_traducoes` | texto publicado (lido pelas telas) |
| `idioma_publicacoes` / `_itens` | histórico: nº, quem, quando, texto anterior e novo |
| `vw_idioma_*` | leitura (publicados, rascunho, admin, grupos, publicações, itens, situação) |
| `sp_idioma_*` | gravação (registrar grupo/texto, carga inicial, salvar rascunho, publicar, descartar) |

Textos com collation binária (`utf8mb4_bin`): "Proxima" ≠ "Próxima".

## Código
- `classes/IdiomaService.php`: `t(chave, padrão)`, `textos(chave, padrão)`, `script(grupos)` (injeta `window.greenPortalTextos` e `window.greenT(chave, padrão)` para o JavaScript).
  O texto do código é sempre o último recurso: sem migração ou sem banco, as telas continuam com os textos do código.
  Prévia do rascunho só para o ADMINISTRADOR (`$_SESSION['idioma_previa_rascunho']`).
- `classes/IdiomaAdminService.php` + `principal/api/administracao_idiomas.php`: carregar, publicacoes, itens, salvar, publicar, descartar, previa.
- `ManutencaoLegalCadastroGrid`: rótulos de coluna `<transacao>.coluna.<CAMPO>` substituem os do código quando cadastrados; textos comuns da busca, filtros, paginação; injeta o dicionário `comum` + transação.
- `components/botoes_crud.php`, `components/toolbar_grupos.php`, `grid-pagination.js`, `manutencao-legal-cadastro.js`, `grid-loading.js`, `controle-documentos-relacionamentos.js`: usam o grupo COMUM / Controle com o texto antigo como padrão.

## Incluir outra transação
1. Acrescentar o grupo e os textos em `database/211_idiomas_textos.php` (ou numa nova carga) e rodar a migração (não sobrescreve textos editados).
2. Nos rótulos de coluna do grid nada muda: basta cadastrar `<transacao>.coluna.<CAMPO>`.
3. Para outros textos, usar `IdiomaService::t('<transacao>.…', [...])` no PHP e `greenT('<transacao>.…', 'padrão')` no JS.

## Aplicar
`E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-211-idiomas.php`

## Migração 212 — menus e colunas automáticas
- **Grupo MENUS** (`menu.<CODIGO>`, tipo MENU): sincronizado com `guniversal_dbp.menus` por `sp_idioma_sincronizar_menus`
  (chamado pela migração e a cada abertura da tela). Menus novos entram sozinhos; nome alterado direto na tabela `menus`
  acompanha, desde que aquele texto não tenha alteração pendente no rascunho.
- **Publicar** copia para `menus.label_pt/label_en/label_es` (e `label`) só os nomes publicados naquela vez; EN/ES vazios ficam nulos
  (o portal mostra o português). Máximo de 150 caracteres. Os usuários veem o nome novo ao recarregar o portal.
- `MenuService` aplica o rascunho do grupo MENUS quando o ADMINISTRADOR liga "Ver rascunho nas telas".
- **Colunas automáticas**: `ManutencaoLegalCadastroGrid` chama `IdiomaService::registrarColunas()`; quando um ADMINISTRADOR abre a
  transação, as colunas sem cadastro entram (uma única vez, `sp_idioma_registrar_lote`) no grupo da transação, cujo nome vem do menu
  (Pai › Filho). Nunca sobrescreve textos já existentes. Outras classes de grid (ex.: `ModuloLegalRequisitosGrid`) ainda não participam.

Aplicar: `E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-212-idiomas-menus-colunas.php`

## Migração 213 — mensagens de erro do servidor
- Grupos: COMUM (sessão, permissão, somente consulta, banco indisponível, duplicado/vínculo/obrigatório/genérico com `{acao}`,
  registro excluído, exportação, página de erro HTTP), MODULOLEGAL_CONTROLE (APIs de correlacionamento e responsáveis) e
  **BANCO** (avisos das procedures; chave `banco.m` + 12 primeiros caracteres do md5 da mensagem original).
- `IdiomaService::erro($e, $acao)`: aviso de procedure (1644) → `mensagemBanco()`; sem conexão (1045/1049/2002/2003/2006/2013) →
  `comum.erro.banco_indisponivel`; demais → `MensagemAplicacao::erro()` (agora lê `comum.erro.*` e `comum.acao.*`).
  `IdiomaService::f()` troca `{marcadores}`.
- `ManutencaoLegalCadastroGrid`: salvar e excluir agora tratam o erro — AJAX recebe JSON 422 com a mensagem; envio normal
  volta para a grade com aviso vermelho (antes caía na página de erro 500). O JS mostra a mensagem do servidor.
- APIs do Controle devolvem JSON 422 com a mensagem traduzida em vez de erro 500.
- `erro.php` (páginas 400–503) no idioma do usuário.
- Para traduzir um novo aviso de procedure: cadastrar `banco.m<md5>` (ver `database/213_idiomas_mensagens.php`).

Aplicar: `E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-213-idiomas-mensagens.php`

## Cadastro automático de qualquer texto + grids próprios (12/10/2026)
- `IdiomaService::t()` / `textos()`: chave ainda sem cadastro entra sozinha em Textos e idiomas quando um ADMINISTRADOR abre a
  tela (fora da prévia), com os textos PT/EN/ES do código. Gravação no fim da requisição (`gravarPendentes`, via
  `sp_idioma_registrar_lote`); nunca sobrescreve. Tipo deduzido da chave (`.coluna.`, `.botao.`, `.titulo.`, `.dica.`,
  `.msg.`/`.erro.`/`.confirmacao.` → MENSAGEM; demais → ROTULO).
- `IdiomaService::script($grupos, $padroes)`: `$padroes` = textos usados só no JavaScript (também entram no cadastro automático).
- `IdiomaService::nomeGrupo($transacao, $titulo)`: nome do grupo quando o menu da transação não é encontrado.
- Grids próprios ligados: `ModuloLegalRequisitosGrid` (colunas, botões, filtros, mensagens, JS `modulo-legal-requisitos.js`) e
  `ModuloLegalSituacaoGrid` (colunas e título).
- Textos próprios convertidos: Requisitos legais, Licenças (botões, mensagens, legenda de vencimento), LAIA, LPRO e LAIS
  (botões de correlacionamento; colunas de LPRO/LAIS ganharam EN/ES).
- Correções: `corrigir-acentuacao.js` apagava "Â"/"Ã" corretos ("Âmbito" → "mbito"); exportação do grid voltou a ter dica.

Converter uma transação: trocar o texto fixo por `IdiomaService::t('<transacao>.<secao>.<item>', ['pt' => ..., 'en' => ..., 'es' => ...])`
(PHP) ou `greenT('<chave>', 'padrão')` (JS, declarando o padrão em `script()`), e abrir a tela uma vez como ADMINISTRADOR.
