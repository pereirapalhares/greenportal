# Padrão de layout das telas do greenportal (auditoria 17/10/2026)

Referência aprovada pelo usuário: o padrão dos grids atuais (ex.: Administração › Unidades). Não existe documento escrito; o padrão está nos componentes.

## Grid (cadastros e listas)
- Página em iframe de aba; sem título próprio (o título é o da aba).
- `section.setup-fragment.menu-grid-fragment` → `div.crud-toolbar.menu-grid-toolbar` → `div.crud-actions.crud-actions-standard` (components/botoes_crud.php, `greenPortalCrudButtons`).
- Botões só com ícone e `title`, com `data-crud-action` (save, new, duplicate, delete, reload, export, columns) + Ajuda (`data-open-transacao-ajuda`, ajuda-transacao.js). toolbar-grupos.js agrupa em Operações | Associações | Avaliação | Outros pela ordem dos seletores.
- Busca "Buscar no grid" à direita (`.crud-search`); paginação na barra (grid-pagination.js); filtro por coluna (grid-column-filters.js), ordenação (grid-sort.js), carregando (grid-loading.js).
- main.css esconde `.menu-grid-fragment` até ganhar a classe `grid-ready` (quem monta o grid sem grid-loading.js precisa pôr a classe).
- Datas no formato do idioma (dd/mm/aaaa; mm/dd/aaaa em inglês), como o `display_format => 'date'` do ManutencaoLegalCadastroGrid.
- CSS: app.css v5, main.css v18, design-system.css v3, botoes-crud.css v4, toolbar-grupos.css. Fonte DM Sans 12px; grid 11px.
- Exportação Excel só pela fila do Agendador (ver homologacao-fornecedores-etapas.md).

## Grid local (linhas vindas de várias fontes) — c454
- Para telas que juntam várias fontes no servidor (não dá para usar o ManutencaoLegalCadastroGrid nem o grid-pagination.js do servidor).
- components/grid_local.php: `greenPortalGridLocalHead()` no head, `greenPortalGridLocal([...])` no body (colunas com filtro text|select|none, linhas, coluna de ações, ordem inicial, aviso), `greenPortalGridLocalScripts()` no fim; auxiliares `greenPortalGridLocalPrazo()` (data com sinal vermelho/amarelo/verde) e `greenPortalGridLocalAbrir()` (botão Abrir em popup).
- assets/js/grid-local.js: busca, filtro por coluna, ordenação (datas por data-sort-value AAAA-MM-DD), paginação 25/pág. na barra (indicador 1/N), Recarregar, Abrir em popup, `grid-ready`.
- Usado por: Home › Minhas pendências (home_pendencias.php), Agenda (eventos.php?modo=agenda → eventos_agenda_universal.php) e Alertas (home_alertas.php).
- sp_crud_select: filtro de texto é LIKE; para o usuário usar `{"CODUSUARIO": {"value": "7", "exact": true}}` (migração 110).

## Páginas especiais (painéis, configurações, ranking)
- `greenPortalEstiloPagina()` (components/pagina_padrao.php) no `<head>`: html.gp-pagina, DM Sans 12px, títulos Manrope.
- Cabeçalho: `h2` com ícone (16px) + subtítulo `text-muted`; conteúdo em cartões. Ex.: Pesos do ranking, Ranking, Solicitações, dashboards (dashboard.php, dashboard_gestor.php, dashboard_admin.php com dashboard-abas.js).

## Popups
- popups.js: 90% da janela que chamou, sempre na frente, altura até o último campo com rolagem.

## Correções da auditoria (c401–c405)
- c401: Tipos, Âmbitos e Assuntos de legislação davam erro fatal (ManutencaoLegalCadastroGrid forçava o banco do cliente; prefixo GLEGISLACAO agora usa o banco da configuração).
- c402–c404: Segurança (Usuários, Grupos, Perfis, Permissões, Listas, Formulário) e Setup › Menu: barra padrão com data-crud-action, ordem padrão, Ajuda, CSS padrão.
- c404–c405: Setup Login/Main/Layout com barra de ícones + Ajuda; Módulos por empresa e Solicitações (gestão e minhas) no padrão de página especial; Serviços com Ajuda; design-system.css em Ajuda das transações, Prompts de IA, Serviços, Legislação x Empresas/Poder; erro.php com a fonte do portal.

## Home (c453–c454, 18/10/2026)
- **Minhas pendências** (c453): grid local com coluna "Motivo / o que fazer" (textos home.motivo.* em Administração › Textos e idiomas) e sinal de prazo. Migração 311 (views de fornecedor responsável e plano de ação).
- **classes/MinhasPendencias.php** (c454): monta as pendências (pilares + homologação) para Minhas pendências e Agenda, só por views, filtro exato do usuário, sem duplicar item (mesma tela + título). Inclui agora treinamentos de funcionário vencidos/vencendo em 30 dias, documentos de funcionário do responsável do fornecedor (externo) e responsabilidades da camada nova (vw_responsabilidade_efetiva).
- **Agenda** (c454): grid local (Data, Tipo, Ação, Descrição/motivo, Situação, Abrir), ordem cronológica (atrasados primeiro, sem data no fim). Eventos e tarefas por views; as pendências passam a seguir a regra de "pede ação" (antes mostrava todos os documentos de fornecedor e funcionário do responsável, inclusive aprovados em dia). eventos.php sem SQL direto no modo agenda (o editor de eventos do monitoramento, modo normal, ainda usa SQL direto).
- **Alertas** (c454): grid local (Data, Alerta, Mensagem, Situação Novo/Lido/Aguardando sua confirmação, Ações por ícone: status da exportação, resultado da IA, baixar Excel, abrir). Clicar numa ação marca como lido (alertas_lido.php). IA pela view vw_ged_analise_ia_ultima.
- **Painel do gestor** (c454): CSS v5/v18 e datas no formato do idioma (já lia só por views).
- Migração 312: vw_agenda_eventos_usuario, vw_agenda_tarefas_usuario, vw_homologacao_pendencias_treinamento_interno, vw_homologacao_pendencias_funcionario_externo, vw_homologacao_responsabilidade_fornecedor, vw_ged_analise_ia_ultima.
- assets/js/home-pendencias.js (c453) ficou sem uso (substituído por grid-local.js); pode ser apagado.

## Grids de Não Conformidades (c537, 23/10/2026 — pedido: "todos os grid de NC fora do padrão")
- grid-local.js v5 / grid_local.php: **ações da linha selecionada na barra** — botão com `data-grid-acao-linha="nome"` (em `extras`, ou o Novo/Excluir padrão com `'buttons' => ['delete' => true, 'delete_attribute' => 'data-grid-acao-linha="excluir"']`) fica habilitado quando a linha selecionada tem `[data-linha-acao="nome"]` (escondido na célula com `greenPortalGridLocalLinha()`) e aciona esse elemento; `'double_click_action' => 'editar'`; `'select_all' => true` põe no cabeçalho da coluna de ações a caixa que marca as `[data-grid-marcar]` das linhas visíveis (seleção em lote). Compatível com Home (pendências, agenda, alertas).
- grid_local.php: anula nas páginas `gp-pagina` o `.form-control` do app.css (tela de login: altura 49px e recuo de 45px).
- Convertidos para o grid padrão (antes eram tabelas HTML soltas ou com ações dentro das linhas): aba Análise (Causas e Plano de ação — Nova/Editar/Excluir na barra; Arquivos da ação continua na coluna, como os Relacionamentos), aba Histórico da NC; Lista de distribuição (Pessoas, Histórico), Participante (Listas, Histórico), Correção de ocorrência (busca com várias NCs, Responsáveis das ações, Correções), Transferência (itens com seleção), Painel de saneamento (Abrir, Atribuir gestor e Lembrar na barra; seleção em lote), E-mails enviados (Reenviar na barra). Lista de distribuição sem faixa de aviso permanente.
- Exclusão de causa/ação: confirmação pela mensagem padrão do portal (GreenPortalPopups.mensagem), no lugar do "clique de novo no botão vermelho".
- Lista geral, Minhas ocorrências e os 14 cadastros já usavam o ManutencaoLegalCadastroGrid (padrão).

## Pendências
- Menus "E-mails enviados" e "Helpdesk" apontam para telas que ainda não existem (404). **Decisão do usuário (17/10): manter os menus ativos — não desativar.** (c533: E-mails enviados passou a existir — fila de e-mails.)
- Muitas telas ainda referenciam versões antigas de app.css/main.css (?v=1..4 / v15..17): só cache, sem efeito visual hoje (home.php, dashboard.php, dashboard_admin.php inclusive).
- Telas filhas abertas em popup (vínculos, abas de fornecedor etc.) não entraram nesta auditoria (precisam de parâmetros); a regra de popup é aplicada pelo portal.
- SQL direto ainda em: seguranca_modulos_empresa.php, administracao_migracao_log.php, eventos.php (editor de eventos do monitoramento) e classes/AgendaService.php.
