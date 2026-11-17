# Regras de negócio — Plataforma (recursos comuns)

Atualizado em 16/11/2026. Documento de referência: o que o sistema faz hoje e as decisões do usuário. Legenda: [FEITO] em produção, [PENDENTE] decidido mas ainda não aplicado/configurado, [A CONFIRMAR] dúvida em aberto.

Escopo: recursos usados por todos os módulos (Módulo Legal, Homologação de Fornecedores, Não Conformidades etc.). As regras de cada módulo ficam nos documentos próprios desta pasta.

---

## 1. Multiempresa e bancos

- **RN-PLT-01** [FEITO] — Cada empresa (tenant) tem o seu próprio banco de dados, no padrão `g<empresa>_dbp` (ex.: `gatlas_dbp`, `gmodelo_dbp`, `gmodelo2_dbp`), com usuários, grupos, perfis, permissões, logs, filas e dados operacionais próprios.
  Onde: `classes/TenantContext.php`, `classes/GreenPortalDatabase.php`; origem: `docs/diretrizes-projeto.txt` (2.2), `docs/multitenant_idiomas_diretrizes.txt`.
- **RN-PLT-02** [FEITO] — O banco universal `guniversal_dbp` guarda o que vale para todas as empresas: cadastro de empresas e setup central, catálogo de menus, módulos por empresa, textos e idiomas, ajuda das transações, guia do usuário, catálogo e status dos serviços, preços de IA e cotação do dólar.
  Onde: `guniversal_dbp`; origem: migração 032 (`EMPRESA_UNIVERSAL`), migrações 197, 198, 211, 265, 340 universal.
- **RN-PLT-03** [FEITO] — O banco do tenant nunca vem do navegador (URL, formulário ou AJAX). Ele sai do cadastro central de empresas no login, fica na sessão (`$_SESSION['tenant']['banco_dados']`) e só aceita letras, números e `_`.
  Onde: `credenciais/logar.php`, `classes/TenantContext.php`; origem: `docs/diretrizes-projeto.txt` (2.2, 3.2).
- **RN-PLT-04** [FEITO] — Toda tela e API usa exclusivamente o banco da sessão. Configuração de banco gravada na página (ex.: `database => gmodelo_dbp`) é só metadado e não abre conexão.
  Onde: `classes/ManutencaoLegalCadastroGrid.php`; origem: `docs/auditoria_isolamento_tenants_20261004.md` (correção de 04/10/2026, em que um usuário Atlas via dados do Modelo).
- **RN-PLT-05** [FEITO] — Nenhuma consulta, sessão ou resposta pode misturar dados de empresas diferentes. Filas, tarefas e logs nunca são compartilhados entre empresas.
  Onde: todas as transações; origem: `docs/diretrizes-projeto.txt` (7), `docs/green-tasks-task-scheduler.md`.
- **RN-PLT-06** [FEITO] — A empresa Modelo (`gmodelo_dbp`) é a referência de estrutura. Novas empresas copiam só a estrutura e os dados de template (catálogo `artefatos_replicacao`); usuários, senhas, logs, preferências e dados operacionais do Modelo não são copiados.
  Onde: `artefatos_replicacao`, `registros_migracao`; origem: `docs/diretrizes-projeto.txt` (2.6, 2.7, 13).
- **RN-PLT-07** [FEITO] — Mudança de estrutura no Modelo não altera empresas existentes sozinha: vira migração versionada, idempotente, aplicada por script, testada primeiro em homologação e registrada em `registros_migracao`.
  Onde: `database/NNN_*.sql|php`, `scripts/aplicar-migracao-*.php`; origem: `docs/diretrizes-projeto.txt` (2.2.1, 13).
- **RN-PLT-08** [FEITO] — Migração do legado preserva as chaves `COD<ENTIDADE>` (sem coluna `id` artificial) e converte os textos de latin1 para `utf8mb4` / `utf8mb4_0900_ai_ci`. Nenhum dado novo pode ser gravado com acentuação quebrada.
  Onde: scripts de migração; origem: `docs/diretrizes-projeto.txt` (14, 14.1, 15), `docs/multitenant_idiomas_diretrizes.txt`.
- **RN-PLT-09** [FEITO] — O cadastro central tem 29 empresas migradas do legado; só as com status `ATIVA` e banco provisionado aparecem no login.
  Onde: `guniversal_dbp.empresa`, `vw_empresas_login_migradas`; origem: `docs/diretrizes-projeto.txt` (3.2, 3.3), migrações 005, 006.
- **RN-PLT-10** [PENDENTE] — A criação de empresa nova deve ser feita por uma transação de provisionamento no Modelo, com estados (pendente, em processamento, concluído, erro), keyusers iniciais e auditoria. Uma empresa não pode ficar "concluída" sem os artefatos obrigatórios.
  Onde: (não localizada no código); origem: `docs/diretrizes-projeto.txt` (2.6, 13).

## 2. Módulos por empresa

- **RN-PLT-11** [FEITO] — Os módulos comerciais são liberados por empresa: Atlas tem Módulo Legal/Gestão Ambiental e Homologação de Fornecedores; Modelo e Modelo2 têm também Não Conformidades.
  Onde: `guniversal_dbp.modulos_sistema`, `empresa_modulos`, `classes/ModuloEmpresaService.php`; origem: migração 265, `docs/modulos-por-empresa-20261014.md`.
- **RN-PLT-12** [FEITO] — Com o módulo desligado na empresa, o menu some e a URL direta da tela é recusada. Administração, Segurança e Configurações não dependem de módulo, só de permissão.
  Onde: `classes/PermissaoTransacao.php`, `MenuService`; origem: migração 265.
- **RN-PLT-13** [FEITO] — Módulo Legal e Manutenção Legal contam como o mesmo módulo comercial. Homologação inclui todos os menus abaixo da raiz Homologação, inclusive Portaria.
  Onde: `ModuloEmpresaService::moduloDoMenu`; origem: `docs/modulos-por-empresa-20261014.md`.
- **RN-PLT-14** [FEITO] — Só o ADMINISTRADOR liga e desliga módulos (Segurança › Módulos por empresa) e ele vê todos os módulos em qualquer empresa.
  Onde: `principal/seguranca_modulos_empresa.php`; origem: migração 265.

## 3. Login e sessão

- **RN-PLT-15** [FEITO] — A única entrada é a tela de login. Ela pede empresa, usuário, senha e idioma (PT padrão, EN e ES). Os três primeiros são obrigatórios.
  Onde: `index.php`, `credenciais/logar.php`; origem: `docs/login_diretrizes.txt`.
- **RN-PLT-16** [FEITO] — O login só é processado por POST em `credenciais/logar.php`. A empresa é localizada no cadastro central; o usuário e a senha são conferidos no banco dessa empresa; usuário inativo é recusado.
  Onde: `credenciais/logar.php`; origem: `docs/diretrizes-projeto.txt` (2.2, 3.2).
- **RN-PLT-17** [FEITO] — Depois do login a sessão é regenerada e guarda usuário, perfil, idioma e empresa (código, nome, banco).
  Onde: `credenciais/logar.php`; origem: `docs/diretrizes-projeto.txt` (6).
- **RN-PLT-18** [FEITO] — Em desenvolvimento a senha é conferida sem hash (`senha_usa_hash = 0`); com o indicador ligado, usa hash. Senha nunca aparece em tela, log, AJAX, mensagem de erro ou exportação.
  Onde: `credenciais/logar.php`, `UsuarioContaService`; origem: `docs/diretrizes-projeto.txt` (2.4, 6), `docs/sessao_acesso_diretrizes.txt`.
- **RN-PLT-19** [PENDENTE] — Antes de homologação/produção a política de senha (hash, tamanho, expiração, troca no primeiro acesso) deve ser revista formalmente.
  Onde: setup da empresa; origem: `docs/diretrizes-projeto.txt` (2.3, 2.4).
- **RN-PLT-20** [FEITO] — O navegador pode lembrar a última empresa, usuário e idioma. A senha nunca é lembrada.
  Onde: tela de login, `IdiomaService::lembrarIdioma`; origem: `docs/diretriz-personalizacao-login.md`.
- **RN-PLT-21** [FEITO] — Página interna sem sessão volta para `index.php`; endpoint AJAX sem sessão responde 401 sem dados internos; sem permissão responde 403.
  Onde: `classes/TransactionSecurity.php`; origem: `docs/sessao_acesso_diretrizes.txt`.
- **RN-PLT-22** [FEITO] — A cada requisição o contexto do usuário é relido do banco. Usuário desativado ou removido tem a sessão encerrada e volta ao login, inclusive nas abas já abertas.
  Onde: `classes/UsuarioSessao.php`; origem: `docs/diretrizes-projeto.txt` (16.5).
- **RN-PLT-23** [FEITO] — O tempo de inatividade é configurado pelo ADMINISTRADOR em Setup › Main (`sessao_timeout_minutos`): padrão 30, mínimo 1, máximo 1440 minutos. Só ações do usuário renovam o tempo; consultas automáticas (ex.: sino de alertas) não renovam. Ao expirar, sai para o login.
  Onde: `setup_main.php`, `main.js`; origem: `docs/sessao_acesso_diretrizes.txt`, `docs/diretrizes-projeto.txt` (Timeout).
- **RN-PLT-24** [FEITO] — Em Minha conta cada usuário altera só a própria senha (confirmando a atual e a nova), o próprio tema e a própria transação inicial. Ninguém altera senha ou preferência de outro usuário.
  Onde: `minha_conta.php`, `UsuarioContaService.php`; origem: `docs/sessao_acesso_diretrizes.txt`.
- **RN-PLT-25** [FEITO] — Após o login abre a Home como primeira aba e, na segunda, a transação inicial do usuário. Se ela não estiver mais disponível, abre o Dashboard.
  Onde: `main.php`, `home.php`; origem: `docs/diretrizes-projeto.txt` (16.2.1), `docs/sessao_acesso_diretrizes.txt`.
- **RN-PLT-26** [FEITO] — Os textos da tela de login (marca, formulário, painel direito, benefícios, rodapés) são editados só pelo ADMINISTRADOR em Setup › Login, com Salvar e Preview, gravados em `setup_empresa` do tenant, com valor padrão para o que não foi cadastrado.
  Onde: `principal/setup_login.php`; origem: `docs/diretriz-personalizacao-login.md`, `docs/login_diretrizes.txt`.
- **RN-PLT-27** [FEITO] — O tema da empresa (verde, azul ou cinza; padrão verde) vale no login. Depois de entrar vale a escolha individual do usuário, se houver.
  Onde: `setup_layout.php`, `preferencias_usuario.layout_tema`; origem: `docs/diretrizes-projeto.txt` (Layout), `docs/padrao-visual-global-20261014.md`.

## 4. Perfis e permissões (Segurança › Permissões)

- **RN-PLT-28** [FEITO] — Cada empresa tem os perfis padrão ADMINISTRADOR, ADM_DA_EMPRESA, GESTOR, EMITENTE, REVISOR, APROVADOR e FORNECEDOR. O usuário `admin` de cada empresa tem perfil ADMINISTRADOR.
  Onde: tabela `perfis` do tenant; origem: `docs/seguranca_diretrizes.txt`, `docs/diretrizes-projeto.txt` (2.3).
- **RN-PLT-29** [FEITO] — Permissão pertence ao perfil, nunca ao usuário. Cadeia: usuário → perfil → menu/transação → ações.
  Onde: `permissoes`, `perfil_menus`; origem: `docs/seguranca_diretrizes.txt`, `docs/diretriz-isolamento-transacoes.md`.
- **RN-PLT-30** [FEITO] — Por transação, o perfil tem as ações: incluir, visualizar, visualizar total, editar, excluir, exportar Excel, gerar PDF, importar arquivos, visualizar arquivos e baixar/imprimir arquivos. "Visualizar total" já inclui "visualizar".
  Onde: `classes/PermissaoTransacao.php`, `seguranca_permissoes.php`; origem: `docs/diretrizes-projeto.txt` (6.1), migração 266 (baixar arquivos).
- **RN-PLT-31** [FEITO] — O ADMINISTRADOR tem todas as ações em todas as transações. Sem associação explícita, a permissão é negada.
  Onde: `PermissaoTransacao::obter`; origem: `docs/diretrizes-projeto.txt` (6.1).
- **RN-PLT-32** [FEITO] — Ao abrir Segurança › Permissões, perfis e menus novos ganham as linhas que faltam: ADMINISTRADOR com acesso total, demais perfis sem nada marcado. Permissões já configuradas nunca são sobrescritas.
  Onde: `principal/seguranca_permissoes.php`; origem: `docs/diretrizes-projeto.txt` (Inicialização das permissões), scripts 017 e 018.
- **RN-PLT-33** [FEITO] — Menu existir no catálogo universal não concede acesso: a concessão é feita no perfil de cada empresa.
  Onde: `seguranca_permissoes.php`; origem: `docs/diretriz-catalogo-menus-universal.md`.
- **RN-PLT-34** [FEITO] — Botões sem permissão ficam ocultos ou desabilitados com a dica "Perfil sem permissão de acesso!" (PT/EN/ES). O servidor valida de novo cada operação; esconder no JavaScript não basta, e a URL direta sem permissão é recusada.
  Onde: `grid-pagination.js`, `TransactionSecurity::connect`; origem: `docs/diretrizes-projeto.txt` (6.1, Controle de permissões nos grids).
- **RN-PLT-35** [FEITO] — Mapa das ações no grid: visualizar mostra o grid; incluir libera Novo e Duplicar; editar libera os campos e o Salvar; excluir libera Excluir; exportar libera Exportar Excel.
  Onde: `vw_permissoes_grid`, `grid-pagination.js`; origem: `docs/diretrizes-projeto.txt`.
- **RN-PLT-36** [FEITO] — ADM_DA_EMPRESA pode alterar as configurações permitidas da própria empresa. Ele vê filas e estado dos serviços, mas não controla tarefas globais.
  Onde: `servicos.php`, setups; origem: `docs/seguranca_diretrizes.txt`, `docs/servicos_gestao_diretrizes.md`.
- **RN-PLT-37** [FEITO] — Os perfis do fornecedor externo (FORNECEDOR / FORNECEDOR_GESTOR) ficam fora das telas só da empresa, mesmo que um perfil associado do legado dê acesso.
  Onde: `HomologacaoFornecedorAcesso::menuNegado`; origem: migração 297.
- **RN-PLT-38** [FEITO] — Setup (Login, Main, Layout, Menu) é visível e acessível só ao ADMINISTRADOR, inclusive por URL direta.
  Onde: `setup_*.php`; origem: `docs/diretrizes-projeto.txt` (16.3).
- **RN-PLT-39** [FEITO] — Segurança › Formulário define, por perfil, se cada campo do formulário pode ser visto, editado ou informado.
  Onde: `seguranca_formulario.php`, `formularios_campos`, `permissoes_campos`; origem: script 020.
- **RN-PLT-144** [FEITO 11/11/2026: 354 aplicada; a cópia (codmenu 25) virou SEGURANCA_PERMISSOES no catálogo e nas 7 empresas, com as permissões mantidas] — O item de menu de uma tela tem de ter o **mesmo código** que a tela confere (`TransactionSecurity::connect('<CÓDIGO>')`).
  - Uma cópia (`_COPIA_<n>`, RN-PLT-45) aponta para a mesma tela, mas a tela confere o código original. Por isso o menu aparece e a tela nega.
  - Caso real: na Crown, o ADM da empresa via Segurança › Permissões pela cópia `SEGURANCA_PERMISSOES_COPIA_072122` e recebia 403.
  - Na produção o original não existia no catálogo universal: a 354 renomeou a cópia para `SEGURANCA_PERMISSOES` (a mesma linha em cada empresa, então as permissões continuaram valendo). Se o original existir, ela junta as permissões da cópia nele (o que estiver marcado em qualquer um fica marcado) e exclui a cópia.
  - As outras cópias de menu que existirem só são listadas, sem alteração.
  - Depois de aplicar, quem estava logado precisa sair e entrar.
  Onde: `scripts/aplicar-migracao-354-permissoes-menu-copia.php` (`MenuCatalogoService::excluir`). Origem: verificação da Crown com a usuária Nathalia (11/11/2026); Decisão do usuário (11/11/2026): "Liberar para o perfil dela".
- **RN-PLT-145** [FEITO no código 11/11/2026] — Segurança › Grupos também abre para o ADM da empresa (antes só o ADMINISTRADOR).
  - Os grupos ficam no banco da empresa, então ele só vê os grupos da empresa dele.
  - Incluir, alterar, excluir e exportar seguem as permissões do perfil em `SEGURANCA_GRUPOS` (Segurança › Permissões). Antes a tela não conferia, porque só o ADMINISTRADOR entrava. O ADMINISTRADOR continua podendo tudo.
  - Os outros perfis continuam sem acesso.
  Onde: `principal/seguranca_grupos.php`. Origem: Decisão do usuário (11/11/2026): "Liberar para o ADM da empresa".

## 5. Catálogo universal de menus

- **RN-PLT-40** [FEITO] — O catálogo de menus fica em `guniversal_dbp.menus`, fonte única de código, hierarquia, rótulos PT/EN/ES, URL, ordem, ícone e situação. Os tenants guardam só as autorizações, ligadas ao `codmenu` global.
  Onde: `classes/MenuService.php`; origem: `docs/diretriz-catalogo-menus-universal.md`, registro `CATALOGO_MENUS_UNIVERSAL`.
- **RN-PLT-41** [FEITO] — Durante a homologação o MenuService ainda aceita o catálogo local do tenant como reserva, só para instalações antigas.
  Onde: `MenuService`; origem: `docs/diretriz-catalogo-menus-universal.md`.
- **RN-PLT-42** [FEITO] — O menu tem até quatro níveis. A URL é um caminho interno simples (ex.: `/principal/usuarios.php`); URL externa, `javascript:` ou caminho do servidor não são aceitos.
  Onde: `setup_menu.php`; origem: `docs/menu_diretrizes.txt`, `docs/diretrizes-projeto.txt` (2.7).
- **RN-PLT-43** [FEITO] — Item com URL abre a tela em nova aba da main; item sem tela mostra "Em construção", sem erro técnico.
  Onde: `main.php`, `menu.php`; origem: `docs/menu_diretrizes.txt`.
- **RN-PLT-44** [FEITO] — O menu mostra só as transações liberadas ao perfil (e aos módulos da empresa). Inicia recolhido, só com ícones de nível 0, e tem busca que aceita texto com ou sem acento.
  Onde: `MenuService`, `main.php`; origem: `docs/diretrizes-projeto.txt` (3.3, Layout).
- **RN-PLT-45** [FEITO] — Ao duplicar um menu, o código recebe o sufixo `_COPIA_<n>`; código repetido digitado à mão gera mensagem amigável.
  Onde: `setup_menu.php`; origem: `docs/diretrizes-projeto.txt` (Regra de duplicação do Setup / Menu).
- Decisão do usuário (17/10/2026): os menus "E-mails enviados" e "Helpdesk" continuam ativos mesmo sem tela pronta (E-mails enviados passou a existir depois).

## 6. Isolamento de transações e acesso a dados

- **RN-PLT-46** [FEITO] — Cada transação tem um PHP próprio (`/principal/<transacao>.php`) e APIs com o mesmo nome (`/principal/api/<transacao>[_<acao>].php`). Não existe endpoint genérico que execute tabela, view ou procedure recebida do navegador.
  Onde: `principal/`; origem: `docs/diretriz-isolamento-transacoes.md`.
- **RN-PLT-47** [FEITO] — Toda transação valida sessão, tenant e o próprio código de permissão antes de tudo; operações que alteram dados exigem CSRF.
  Onde: `TransactionSecurity`; origem: `docs/diretriz-isolamento-transacoes.md`, `docs/diretrizes-projeto.txt` (6).
- **RN-PLT-48** [FEITO] — Leitura só por views (ou `sp_crud_select`) e gravação só por procedures; o PHP não deve ter SELECT/INSERT/UPDATE/DELETE direto. As procedures genéricas (`sp_crud_insert/select/update/delete/count`) validam tabela e campos no dicionário do banco.
  Onde: `database/004_procedures_crud_views_modelo.sql`, migração 024; origem: `docs/diretrizes-projeto.txt` (5.1, 16).
- **RN-PLT-49** [PENDENTE] — Ainda há SQL direto em alguns arquivos (ex.: `seguranca_modulos_empresa.php`, `administracao_migracao_log.php`, editor de eventos de `eventos.php`, `AgendaService.php`). A troca segue por etapas: 1 (auditoria/alertas) e 2 (GED, licenças, IA) concluídas em 14/10/2026.
  Onde: ver levantamento; origem: `levantamento-sql-direto.md`, `claude/padrao-layout-telas.md`.
- **RN-PLT-50** [FEITO] — Mensagens ao usuário são simples, sem SQLSTATE, nomes de tabela, procedure ou caminho interno: duplicidade pede outro código; vínculo pede revisar dependências; obrigatório pede revisar o formulário.
  Onde: `classes/MensagemAplicacao.php`, `IdiomaService::erro`; origem: `docs/diretrizes-projeto.txt` (Padrão de mensagens), migração 213.

## 7. Idiomas e textos

- **RN-PLT-51** [FEITO] — A interface existe em PT (padrão), EN e ES. Sem tradução, mostra o português; sem banco ou sem migração, mostra o texto do código. Dados digitados pelo usuário não são traduzidos.
  Onde: `classes/IdiomaService.php`; origem: `docs/diretrizes-projeto.txt` (2.1), `docs/idiomas_textos_20261012.md`.
- **RN-PLT-52** [FEITO] — Os textos ficam em `guniversal_dbp` e são únicos para todas as empresas. São editados em Administração › Textos e idiomas em rascunho e só chegam às telas depois de Publicar, com histórico (quem, quando, antes e depois).
  Onde: `principal/administracao_idiomas.php`, `IdiomaAdminService`; origem: migração 211.
  Decisão do usuário (12/10/2026): textos únicos para todas as empresas; edição em rascunho com publicação.
- **RN-PLT-53** [FEITO] — Só o ADMINISTRADOR altera textos (transação `ADMINISTRACAO_IDIOMAS`), com CSRF e auditoria; só ele vê a prévia do rascunho nas telas.
  Onde: `IdiomaAdminService`; origem: migração 211.
- **RN-PLT-54** [FEITO] — Nomes de menu entram sozinhos no grupo MENUS. Publicar copia para `menus.label_*` só os nomes publicados (até 150 caracteres; EN/ES vazios mostram o português).
  Onde: `sp_idioma_sincronizar_menus`; origem: migração 212.
- **RN-PLT-55** [FEITO] — Colunas de grid sem texto cadastrado entram uma única vez no grupo da transação quando um ADMINISTRADOR abre a tela; textos já existentes nunca são sobrescritos (só nos grids do `ManutencaoLegalCadastroGrid`).
  Onde: `IdiomaService::registrarColunas`; origem: migração 212.
- **RN-PLT-56** [FEITO] — Datas aparecem no formato do idioma: DD/MM/AAAA HH:MM em PT/ES e MM/DD/AAAA HH:MM em EN; o banco mantém datetime.
  Onde: grids e formulários; origem: `docs/diretrizes-projeto.txt` (Formato de datas).
- **RN-PLT-57** [A CONFIRMAR] — A diretriz antiga prevê o ADM_DA_EMPRESA personalizando rótulos no banco da própria empresa, por idioma; a decisão de 12/10/2026 deixou os textos únicos no universal e só o ADMINISTRADOR edita.
  Onde: —; origem: `docs/diretrizes-projeto.txt` (2.3) x `docs/idiomas_textos_20261012.md`.

## 8. Ajuda das transações, ajuda interativa e assistente

- **RN-PLT-58** [FEITO] — Cada transação tem um texto de ajuda em PT/EN/ES em `guniversal_dbp.ajuda_transacoes`, aberto pelo botão Ajuda da barra e editado em Configurações › Ajuda das transações (transação `CONFIG_AJUDA_TRANSACOES`).
  Onde: `components/botoes_crud.php`, `setup_ajuda.php`, `configuracoes_ajuda.php`; origem: `docs/ajuda_transacoes_diretrizes.md`, migrações 066/067.
- **RN-PLT-59** [FEITO] — O editor da ajuda aceita só negrito, itálico, listas e links `http(s)`; o servidor remove o resto do HTML e os links abrem em nova janela.
  Onde: `configuracoes_ajuda.php`; origem: `docs/ajuda_transacoes_diretrizes.md`.
- **RN-PLT-60** [FEITO] — A ajuda interativa (botão ? / F1) mostra guias passo a passo da tela aberta, "Sobre esta tela" e "Esta ajuda foi útil?" (gravado em auditoria `AJUDA_INTERATIVA`). Mostra só guias de menus que o usuário pode abrir; a fonte é o Guia do usuário publicado.
  Onde: `assets/js/ajuda.js`, `principal/api/ajuda.php`, `GuiaUsuarioService`; origem: migração 309.
- **RN-PLT-61** [FEITO] — O Guia do usuário fica no universal, com rascunho e versões publicadas, e é mantido em Administração › Guia do usuário.
  Onde: `GuiaUsuarioService` (`ADMINISTRACAO_GUIA_USUARIO`); origem: migração 199.
- **RN-PLT-62** [FEITO] — O assistente "Como posso ajudar?" é liberado por perfil (transação `ASSISTENTE_IA`). A IA recebe só o catálogo das telas que o usuário pode abrir, a ajuda e o guia; nenhum registro do cliente vai para a IA. CPF, CNPJ, e-mail e telefone são retirados da pergunta antes de gravar e enviar.
  Onde: `classes/AssistenteAplicacaoService.php`; origem: migrações 225, 236, 246.

- **RN-PLT-128** [FEITO] — O modo guiado mostra o passo, "Você deve ver" e "Mostre-me" (destaca o botão, campo, coluna ou menu do passo) e avança sozinho quando a pessoa clica no item certo; o progresso fica no navegador e continua após trocar de tela. O assistente pode indicar até 2 guias que o usuário pode abrir.
  Onde: `assets/js/ajuda.js`, `AssistenteAplicacaoService`; origem: migração 309 (18/10/2026).
- **RN-PLT-129** [FEITO] — A carga de guias pelo runner da 309 não sobrescreve guias já cadastrados e para se houver rascunho pendente. Depois que alguém editar os guias no editor, não se roda a carga com --substituir (perderia a edição).
  Onde: `scripts/aplicar-migracao-309-ajuda-interativa.php`; origem: migração 309. [PENDENTE] escrever os guias de Homologação, NC e Portaria no editor.

## 9. Padrão de telas, grids e popups

- **RN-PLT-63** [FEITO] — Tela de grid abre em aba (iframe) da main, sem título próprio; a primeira linha é a barra de botões só com ícone e dica, na ordem Salvar, Novo, Duplicar, Excluir, Recarregar, Exportar Excel, Colunas, + Ajuda, com a busca à direita.
  Onde: `components/botoes_crud.php`, `toolbar-grupos.js`; origem: `docs/diretrizes-projeto.txt`, `claude/padrao-layout-telas.md` (referência aprovada: Administração › Unidades).
- **RN-PLT-64** [FEITO] — Nenhum botão de ação fica dentro das linhas: ações da linha selecionada ficam na barra.
  Onde: `grid-local.js` v5, `ManutencaoLegalCadastroGrid`; origem: `claude/padrao-layout-telas.md` (c537), `docs/ia_prompts_crud_diretrizes.md`.
- **RN-PLT-65** [FEITO] — Grids têm 25 linhas por página, busca e filtros enviados ao banco após 1 segundo sem digitar, volta à página 1 ao filtrar e rodapé com o total que atende ao filtro ("N registros - Página X").
  Onde: `grid-pagination.js`, `sp_crud_count`; origem: `docs/diretrizes-projeto.txt` (Paginação, Layout).
- **RN-PLT-66** [FEITO] — Todo grid tem ordenação por clique no título, filtro por coluna (lista para campos de lista/relacionamento/sim-não), colunas visíveis, ordem e largura guardadas por usuário/empresa/transação e chave primária oculta por padrão.
  Onde: `preferencias_grid`, `grid-column-filters.js`, `grid-sort.js`; origem: `docs/diretrizes-projeto.txt` (2.5).
- **RN-PLT-67** [FEITO] — Duplicar cria o novo registro sempre inativo. Incluir, alterar, duplicar e excluir pedem confirmação em popup; enquanto carrega, aparece "aguarde" e o grid só aparece com a classe `grid-ready`.
  Onde: `grid-loading.js`; origem: `docs/diretrizes-projeto.txt`.
- **RN-PLT-68** [FEITO] — Popups usam os componentes comuns (mensagem e transação). Popup aberto de outro popup é independente: fechar o filho não recarrega o pai. Popup ocupa até 90% da janela que chamou.
  Onde: `assets/js/popups.js`, `popupmensagem.php`, `popuptransacao.php`; origem: `docs/popups_diretrizes.txt`, `docs/popup_independentes_diretrizes.md`.
- **RN-PLT-69** [FEITO] — Popup de associação mostra primeiro os itens associados (marcados), depois os demais; listas grandes buscam no servidor 25 por página e o servidor impede duplicidade.
  Onde: `popups.js`; origem: `docs/popup_independentes_diretrizes.md`.
- **RN-PLT-70** [FEITO] — O visual vem de `assets/css/design-system.css` (tokens `--gp-*`); temas verde, azul e cinza mudam só as variáveis.
  Onde: `design-system.css`, camadas por módulo; origem: `docs/padrao-visual-global-20261014.md`.

## 10. Exportação Excel

- **RN-PLT-71** [FEITO] — A tela nunca gera planilha: grava o pedido em `exportacoes_grid` (status SOLICITADA) e cria o alerta "Exportação iniciada". Só o `scripts/export-worker.php` (tarefa `GreenPortal-ExportWorker`, a cada 1 minuto) gera o arquivo; tentativa de rodar o gerador pelo Apache é recusada.
  Onde: `classes/ExportacaoSolicitacao.php`, `classes/ExportacaoGridWorker.php`; origem: `docs/servico_exportacao_excel_diretrizes.md`.
- **RN-PLT-72** [FEITO] — A planilha respeita filtros, busca, ordem, colunas visíveis e rótulos do grid no momento do pedido e traz todos os registros (lotes de 1000), não só a página.
  Onde: `ExportacaoGridWorker`; origem: `docs/regra-exportacao-grid-20261013.md`, `docs/exportacao_diretrizes.txt`.
- **RN-PLT-73** [FEITO] — O campo `senha` de usuários nunca é exportado, mesmo se o navegador pedir.
  Onde: `ExportacaoGridWorker`; origem: `docs/regra-exportacao-grid-20261013.md`.
- **RN-PLT-74** [FEITO] — No máximo dois processamentos de planilha ao mesmo tempo (travas em `runtime/libreoffice-slots`).
  Onde: `ExportacaoGridWorker`; origem: `docs/diretrizes-projeto.txt` (Exportação de grids).
- **RN-PLT-75** [FEITO] — Ao concluir, o usuário recebe alerta com início, fim e link do XLSX; com erro, alerta com as datas e a mensagem. O alerta tem "Ver status" (popup que acompanha SOLICITADA/PROCESSANDO).
  Onde: `export_status.php`; origem: `docs/exportacao_diretrizes.txt`.
- **RN-PLT-76** [FEITO] — O botão Exportar Excel só aparece com a permissão "exportar Excel" da transação.
  Onde: `PermissaoTransacao`; origem: `docs/diretrizes-projeto.txt` (6.1).
- **RN-PLT-77** [A CONFIRMAR] — A diretriz proíbe o mesmo usuário pedir outra exportação do mesmo grid com uma em andamento; não foi achado esse bloqueio em `ExportacaoSolicitacao::solicitar`.
  Onde: `ExportacaoSolicitacao.php`; origem: `docs/exportacao_diretrizes.txt`.

## 11. Serviços no Agendador do Windows

- **RN-PLT-78** [FEITO] — Todo processamento recorrente ou pesado (filas, notificações, agenda, responsabilidades, workflow, GED, IA, OCR, Excel, e-mail, pesquisa) roda em PHP CLI por tarefas `GreenPortal-*` na pasta `\GreenSolutions\` do Agendador do Windows. A web só registra pedidos.
  Onde: `scripts/*-worker.php`, `scripts/windows-service.php`; origem: `docs/diretriz-servicos-php-schedule-windows.md`.
- **RN-PLT-79** [FEITO] — Não há mais serviços HTTP residentes: as portas 8787 (Excel) e 8788 (IA) e o serviço OCR da porta 8091 saíram do fluxo; a aplicação web não inicia nem consulta portas.
  Onde: `servicos.php`, `OcrQueueService`; origem: `docs/servicos_gestao_diretrizes.md`, `docs/servico_exportacao_excel_diretrizes.md`.
- **RN-PLT-80** [FEITO] — Cada worker tem trava por serviço (sem execução simultânea, política `IgnoreNew`), é idempotente, aceita `--dry-run` e `--database=` só com nome `g[a-z0-9]+_dbp`, e grava cada execução em `green_service_runs` do tenant.
  Onde: `classes/WindowsServiceRuntime.php`; origem: `docs/diretriz-servicos-php-schedule-windows.md`, migração 190.
- **RN-PLT-81** [FEITO] — Intervalos: tarefas/notificações, IA, OCR e Excel a cada 1 minuto; e-mail a cada 2; agenda e responsabilidades a cada 5; workflow, conteúdo, GED e Pesquisa de documentos a cada 10.
  Onde: `scripts/criar-agendamentos-greenportal.ps1`; origem: `docs/diretriz-servicos-php-schedule-windows.md`, `docs/busca_documentos.md`, migração 343.
- **RN-PLT-82** [FEITO] — Tarefas da fila geral passam por PENDENTE → PROCESSANDO → CONCLUIDA/ERRO, com chave de idempotência única no tenant, trava de 10 minutos e até 3 tentativas.
  Onde: `TaskService`, `task-worker.php`; origem: `docs/green-tasks-task-scheduler.md`.
- **RN-PLT-83** [SUBSTITUÍDA em 11/11/2026 pela RN-PLT-143] — `GreenPortal-ServiceMonitor` gravava a cada 5 minutos o estado das tarefas no universal. A tela Administração › Serviços só lê esse retrato e mostra "Atualização pendente" quando a última coleta passa de 10 minutos.
  Onde: `principal/servicos.php`, `servicos_task_status`; origem: `docs/monitoramento-servicos-task-scheduler.md`, migração 198.
- **RN-PLT-84** [SUBSTITUÍDA em 11/11/2026 pela RN-PLT-143: a tela não liga nem desliga mais tarefas do Windows] — Só o ADMINISTRADOR enviava Iniciar/Parar às tarefas, e só a nomes fixos do catálogo; não há comando livre. Cada comando é auditado em `servicos_auditoria`.
  Onde: `servicos.php`; origem: `docs/servicos_gestao_diretrizes.md`.

## 12. GED (arquivos dos registros)

- **RN-PLT-85** [FEITO] — Os arquivos ficam fora da área pública, por empresa, pasta lógica e chave do registro (`...\GED\<banco legado>\<pasta>\<chave>`). A pasta do registro é criada no primeiro envio. Algumas pastas (licenças, fornecedores, funcionários, NC) ficam obrigatoriamente no GED do legado.
  Onde: `classes/GedStorageService.php`; origem: `docs/ged_arquivos_diretrizes.md`.
- **RN-PLT-86** [FEITO] — Todos os módulos usam a mesma janela de arquivos (`principal/gestao_arquivos.php`) e o mesmo endpoint; o grid mostra ícone com a quantidade de arquivos, não o caminho.
  Onde: `gestao_arquivos.php`, `api/gestao_arquivos.php`; origem: `docs/ged_arquivos_diretrizes.md`.
- **RN-PLT-87** [FEITO] — O nome físico é seguro e único (data, código aleatório e nome original limpo). Cada envio grava um registro de versão com hash SHA-256 e o usuário que enviou (dono). Reenviar o mesmo conteúdo cria outro arquivo.
  Onde: `GedStorageService::store`, `content_versions`; origem: migração 330.
- **RN-PLT-88** [FEITO] — O servidor valida sessão, tenant, permissão, chave do registro e o caminho real; não aceita caminho do usuário, `..` ou outra pasta.
  Onde: `GedStorageService::resolve`; origem: `docs/ged_arquivos_diretrizes.md`.
- **RN-PLT-89** [FEITO] — Permissão do GED: Gestão de Arquivos (`GESTAO_ARQUIVOS`) decide ver, enviar (incluir) e excluir; tipos ligados a uma tela também aceitam a permissão dessa tela. Excluir pede confirmação. A permissão do GED é independente do CRUD do registro.
  Onde: `classes/GedPermissaoTipo.php`; origem: `docs/ged_arquivos_diretrizes.md`.
- **RN-PLT-90** [FEITO] — Baixar ou imprimir exige "Baixar/imprimir arquivos". Sem ela, o arquivo abre só no visualizador do sistema, sem salvar nem imprimir; formato sem visualização fica bloqueado. Cada visualização e download é auditado.
  Onde: `api/gestao_arquivos.php`, `visualizador_arquivo.php`; origem: migração 266.
- **RN-PLT-91** [FEITO] — O dono do arquivo (quem enviou) tem acesso total a ele (ver, baixar/imprimir, excluir), mesmo sem essas permissões no perfil. Arquivos do legado não têm dono. Registro encerrado (ex.: NC finalizada) fica só para consulta.
  Onde: `GedPermissaoTipo::arquivosDoUsuario`, `sp_ged_arquivos_do_usuario`; origem: regra de 22/10/2026, migração 330.
- **RN-PLT-92** [FEITO] — Anexos com restrição de registro: Portaria só nas unidades do usuário; NC só para quem pode ver a ocorrência; fornecedor externo só nos fornecedores ligados a ele; ADMINISTRADOR vê tudo.
  Onde: `GedPermissaoTipo::registroPermitido`; origem: migrações 260, 262, 296, 316.
- **RN-PLT-93** [A CONFIRMAR] — A matriz inicial do GED (ADMINISTRADOR e ADM_DA_EMPRESA veem, enviam e excluem; GESTOR vê e envia; demais sem acesso) é a configurada hoje em todas as empresas?
  Onde: `permissoes` (`GESTAO_ARQUIVOS`); origem: `docs/ged_arquivos_diretrizes.md`.

## 13. Visualizador e OCR

- **RN-PLT-94** [FEITO] — O visualizador mostra PDF (PDF.js), imagem, texto, áudio e vídeo direto; Office e desenhos (DWG/DXF) são convertidos para PDF e planilhas para HTML por um serviço agendado, com cache. Outros formatos só têm Baixar, com permissão.
  Onde: `classes/VisualizadorPrevia.php`, `scripts/visualizador-worker.php`; origem: código (classe VisualizadorPrevia).
- **RN-PLT-95** [FEITO] — A leitura de texto de PDF, imagem e Word é enfileirada em `green_ocr_jobs` (chave de idempotência obrigatória) e feita pelo `GreenPortal-OcrWorker`, local (pdftotext, Tesseract, LibreOffice). A API web só enfileira e a tela consulta o status.
  Onde: `classes/OcrQueueService.php`, `documentos_ocr_status.php`; origem: `docs/servico_exportacao_excel_diretrizes.md`.
- **RN-PLT-96** [FEITO] — Texto obtido por OCR que segue para a IA traz o aviso para revisar a sugestão com atenção.
  Onde: telas de análise; origem: `docs/ocr_documentos_20261004.md`.

## 14. Alertas e e-mail

- **RN-PLT-97** [FEITO] — O sino mostra os alertas do usuário logado (título, mensagem, link opcional, lido, data/hora no formato do idioma), com contador de não lidos. A consulta automática não recarrega o grid nem renova a sessão.
  Onde: `AlertasService.php`, `alertas.js`, `home_alertas.php`; origem: `docs/alertas_diretrizes.txt`.
- **RN-PLT-98** [FEITO] — Limpar alertas pede confirmação e apaga só os do próprio usuário. Clicar numa ação do alerta marca como lido.
  Onde: `alertas_limpar.php`, `alertas_lido.php`; origem: `docs/alertas_diretrizes.txt`, `claude/padrao-layout-telas.md`.
- **RN-PLT-99** [FEITO] — Em Minha conta › Alertas por e-mail cada usuário escolhe: na hora (padrão), resumo diário às 7h (só os ainda não lidos) ou não receber.
  Onde: `alertas_email_pref`, `AlertaEmailService`; origem: migração 343.
  Decisão do usuário (23/10/2026): três modos de recebimento; "Exportação iniciada" não vai por e-mail; o e-mail traz "Abrir no portal".
- **RN-PLT-100** [FEITO] — O serviço de e-mail só considera alertas novos (depois da marca de controle); o histórico não é enviado. Alerta com mais de 1 dia e "Exportação iniciada" são ignorados; os alertas da mesma pessoa no mesmo ciclo saem num único e-mail.
  Onde: `sp_alertas_email_novos`, `AlertaEmailService::gerar`; origem: migração 343.
- **RN-PLT-101** [FEITO] — "Abrir no portal" leva a `/principal/abrir_alerta.php` (pede login se preciso, abre a tela do alerta como aba e marca o alerta como lido). O endereço do portal vem da variável `GREENPORTAL_URL`.
  Onde: `abrir_alerta.php`; origem: migração 343. A tela principal só abre alerta do próprio usuário e só link relativo do portal.
- **RN-PLT-102** [FEITO] — Os e-mails passam pela fila `email_fila` do tenant, saem com o nome da empresa no assunto e o aviso de mensagem automática. Falha temporária tenta de novo (espera de 10 min × tentativa, até 5 tentativas → ERRO); endereço inválido ou recusa definitiva (5xx) vai direto para ERRO; falha de conexão interrompe o lote e devolve o resto à fila.
  Onde: `classes/EmailFilaService.php`, `sp_email_reivindicar`, `sp_email_resultado`; origem: migrações 333 e 343.
- **RN-PLT-103** [PENDENTE] — O SMTP ainda não está configurado (variáveis `SMTP_HOST` e `SMTP_REMETENTE` vazias): nada é enviado. Decisão do usuário (23/10/2026): o SMTP só será configurado depois, pelo próprio usuário no .env, para não correr o risco de enviar e-mails de teste a usuários reais. E-mails já na fila ficam PENDENTE; para os alertas, a marca só avança, e o que acontecer no período sem SMTP não é enviado depois.
  Onde: `SmtpCliente::configurado`, `AlertaEmailService`; origem: migração 343, `classes/EmailFilaService.php`.

## 15. IA e taxímetro

- **RN-PLT-104** [FEITO] — A IA é sempre assíncrona ou sob pedido e nunca cria nem altera registros operacionais sozinha: só grava o resultado da análise e emite os alertas previstos. Resposta fora do contrato termina em ERRO.
  Onde: `ia-worker.php`, `InteligenciaArtificial*`; origem: `docs/estabilidade_servico_ia_diretrizes.md`.
- **RN-PLT-105** [FEITO] — Pedido de IA parado em PROCESSANDO há mais de 30 minutos volta para PENDENTE; falha temporária (rede, HTTP 408/409/425/429/5xx) tem até 3 tentativas com espera crescente. O log não grava a chave da IA, o prompt completo nem dados sensíveis.
  Onde: `sp_ia_potencial_recuperar_expiradas`; origem: `docs/estabilidade_servico_ia_diretrizes.md`.
- **RN-PLT-106** [FEITO] — Prompts de IA só podem ser alterados (sem incluir/excluir), com os três idiomas obrigatórios e histórico de cada alteração.
  Onde: `SETUP_IA_PROMPT`, `sp_ia_prompt_atualizar`; origem: `docs/ia_prompts_crud_diretrizes.md`.
- **RN-PLT-107** [FEITO] — Toda chamada à OpenAI grava uma linha em `ia_consumo` do tenant: módulo, tipo, usuário, registro, modelo, tokens de entrada/cache/saída, custo em US$ e em R$, sucesso, duração. Erro no taxímetro nunca interrompe a análise.
  Onde: `classes/IaTaximetro.php`, `sp_ia_consumo_registrar`; origem: migração 340.
- **RN-PLT-108** [FEITO] — Custo = tokens × preço do modelo por 1 milhão (tokens em cache pagam o preço de cache), convertido pela PTAX venda do dia (ou do último dia com cotação) mais o acréscimo da empresa (IOF/spread, 0 a 50%). Preços e câmbio ficam no universal; só o ADMINISTRADOR altera preços; o serviço `iaconsumo` busca a PTAX no Banco Central e não troca cotações digitadas à mão.
  Onde: `IaTaximetro::custo`, `ia_precos`, `ia_cambio`; origem: migração 340 universal.
- **RN-PLT-109** [FEITO] — Cada empresa tem limite mensal em R$ (vazio = sem limite), aviso (padrão 80% do limite) e folga (padrão 10%). Créditos extras valem só no mês. Situações: Normal, Perto do limite (aviso), Excedido (usando a folga), Bloqueado (consumo ≥ limite + extras + folga).
  Onde: `vw_ia_limite_situacao`; origem: migração 340.
- **RN-PLT-110** [FEITO] — Bloqueado, novos pedidos de IA são recusados com mensagem ao usuário; os pedidos já na fila continuam.
  Onde: `IaTaximetro::exigirDisponivel` (`IaLimiteExcedido`); origem: migração 340.
- **RN-PLT-111** [FEITO] — Só o ADMINISTRADOR altera limite, folga, aviso e acréscimo e dá crédito extra (Administração › Consumo de IA). Mudar o limite e dar crédito exigem motivo; tudo vai para o histórico. Crédito extra exige limite definido.
  Onde: `principal/administracao_consumo_ia.php`, `sp_ia_limite_salvar`, `sp_ia_limite_extra_incluir`; origem: migração 340.
- **RN-PLT-130** [FEITO] — O ADM DA EMPRESA só consulta o consumo e o limite da sua empresa; a aba "Todas as empresas" é só do ADMINISTRADOR. Os pedidos anteriores ao taxímetro foram estimados uma vez (caracteres ÷ 4) e aparecem marcados como "estimado". O OCR é local e não tem custo.
  Onde: `principal/administracao_consumo_ia.php`; origem: Decisões do usuário (23/10/2026), migração 340.
- **RN-PLT-112** [FEITO] — O serviço `iaconsumo` avisa uma vez por mês e por faixa (aviso, limite, bloqueio) os ADM DA EMPRESA ativos por alerta e e-mail; o ADMINISTRADOR recebe só as faixas de limite e bloqueio.
  Onde: `sp_ia_limite_avisar`; origem: migração 340.

## 16. Pesquisa de documentos e busca semântica

- **RN-PLT-113** [FEITO] — A Pesquisa de documentos (lupa do cabeçalho, `BUSCA_DOCUMENTOS`) busca cadastros e arquivos de licenças, controles, requisitos, treinamentos, infraestrutura e documentos da Portaria, num índice por empresa (`busca_indice`) atualizado a cada 10 minutos. Arquivo enviado aparece em até 10 minutos.
  Onde: `principal/busca_documentos.php`, `scripts/busca-worker.php`; origem: `docs/busca_documentos.md`, migração 264.
- **RN-PLT-114** [FEITO] — Cada resultado só aparece para quem pode ver o registro na tela de origem (ex.: licenças sem "Visualizar total" só as do responsável; Portaria só unidades do usuário); vínculos só mostram o vizinho que o usuário pode ver. ADMINISTRADOR vê tudo.
  Onde: `sp_busca_pesquisar_negocios`; origem: `docs/busca_documentos.md`.
- **RN-PLT-115** [FEITO] — O resultado agrupa por negócio (cadastro, fornecedor ou funcionário) com vínculos nos dois sentidos, definidos pelo catálogo `busca_relacao` (ligar um módulo novo = view + linha no catálogo).
  Onde: `busca_relacao`, `vw_busca_rel_*`; origem: `docs/busca_documentos.md`.
- **RN-PLT-116** [FEITO] — O dicionário (comum no universal, alterado só pelo ADMINISTRADOR, e da empresa) amplia a pesquisa com siglas e sinônimos; só termos APROVADOS valem; sigla casa só palavra inteira. O serviço sugere termos novos como SUGESTAO e nunca altera aprovados ou rejeitados. A tela do dicionário é para ADMINISTRADOR e ADM_DA_EMPRESA.
  Onde: `busca_dicionario`, `sp_busca_termos`, `busca_dicionario.php`; origem: `docs/busca_documentos.md`, migração 264.
- **RN-PLT-117** [FEITO] — Exportar a pesquisa exige "Exportar Excel" em `BUSCA_DOCUMENTOS` e segue a fila padrão de exportação, guardando as permissões do usuário no momento do pedido.
  Onde: `api/busca_documentos.php?action=exportar`; origem: `docs/busca_documentos.md`.
- **RN-PLT-118** [FEITO] — Busca semântica: entram os cadastros e o texto já lido dos arquivos (OCR); arquivo sem texto lido fica só na busca por palavra. Os parecidos pelo sentido aparecem depois dos resultados por palavra, marcados "por sentido", com chave para ligar/desligar. A permissão é aplicada pela pesquisa normal.
  Onde: `classes/BuscaSemantica.php`; origem: migração 346.
  Decisão do usuário (23/10/2026): esse escopo e a apresentação na mesma tela da Pesquisa de documentos, com a chave "Também pelo sentido" (lembrada por usuário). Só na 1ª página de uma pesquisa por texto entram até 10 resultados por sentido que não vieram pelas palavras.
- **RN-PLT-119** [FEITO] — Os vetores (modelo e dimensões em `GPT_AI_EMBEDDING_MODEL` / `GPT_AI_EMBEDDING_DIMENSOES`; semelhança mínima `BUSCA_SEMANTICA_MINIMO`, padrão 0,35) são refeitos só quando o texto muda. Cada chamada passa pelo taxímetro (tipo `BUSCA_SEMANTICA`) e respeita o limite mensal. Sem chave de IA, a busca semântica fica desligada sem erro.
  Onde: `BuscaSemantica`, `busca_embedding`; origem: migração 346.
- **RN-PLT-120** [PENDENTE] — Registrar pesquisas sem resultado para sugerir termos ao dicionário.
  Onde: —; origem: `docs/busca_documentos.md` (fase seguinte).

## 17. Auditoria

- **RN-PLT-121** [FEITO] — Acesso às transações e operações relevantes geram registro em `logs_auditoria` do próprio tenant (usuário, transação, ação, entidade, registro, resultado, detalhes com identificador da requisição e banco), pela procedure `sp_auditoria_registrar`.
  Onde: `classes/AuditService.php`, `TransactionSecurity`; origem: `docs/seguranca_diretrizes.txt`, `docs/diretrizes-projeto.txt` (7.1), migração 281.
- **RN-PLT-122** [FEITO] — Alterações de setup, textos, prompts, limite de IA e comandos de serviço guardam antes/depois ou histórico próprio.
  Onde: `idioma_publicacoes`, `ia_limite_historico`, `servicos_auditoria`; origem: migrações 211, 340, 153.
- **RN-PLT-123** [FEITO] — Logs não podem ser alterados por usuários comuns; consultar e exportar logs depende de permissão da transação e respeita a empresa.
  Onde: transações de log; origem: `docs/diretrizes-projeto.txt` (7.1).
- **RN-PLT-124** [A CONFIRMAR] — A diretriz diz que falha de auditoria deve virar erro da operação quando a rastreabilidade for obrigatória; hoje o `AuditService` só registra a falha no log do PHP e a operação segue.
  Onde: `AuditService::record`; origem: `docs/diretrizes-projeto.txt` (7.1).

## 18. Backup e retenção

- **RN-PLT-125** [FEITO] — O backup abrange a aplicação e todos os bancos `*_dbp` (estrutura, dados, procedures, eventos, triggers, utf8mb4, transação consistente), com manifesto de tamanho e SHA-256, sem as pastas `backups` e `runtime`.
  Onde: pasta `backups/`; origem: `docs/backup_diretrizes.txt` (últimos registrados: 17, 24 e 25/08/2026).
- **RN-PLT-126** [PENDENTE] — Os backups devem ficar em local protegido e ser testados periodicamente por restauração em ambiente separado.
  Onde: —; origem: `docs/backup_diretrizes.txt`.
- **RN-PLT-127** [FEITO] — Retenção aprovada: exportações concluídas ou com erro, 30 dias após a conclusão (arquivo só é apagado dentro de `exports/`); exportações em andamento nunca são apagadas; alertas lidos, 90 dias; não lidos ficam. Roda por tenant, fora do pico, primeiro em simulação; apagar exige `--execute`.
  Onde: `scripts/manutencao-retencao-exportacoes-alertas.php`; origem: `docs/retencao_exportacoes_alertas_diretrizes.md`.
- **RN-PLT-131** [PENDENTE: migração 351 aplicada e serviço instalado em 26/10/2026; falta o primeiro backup completo] — Backup automático diário no horário de Administração › Backup (padrão 02:00, antes do sincronismo das 05:00), pelo serviço `GreenPortal-Backup` do Agendador do Windows (a cada 15 minutos verifica o horário e os pedidos "Fazer backup agora"). Cada backup numa pasta `greenportal_AAAAMMDD_HHMMSS`: todos os bancos `*_dbp` (mysqldump com `--single-transaction`, rotinas, gatilhos e eventos, compactado em .sql.gz e conferido pelo "Dump completed"), a aplicação em `aplicacao.zip` (com o .env; sem backups, exports, runtime, storage, .git e node_modules), `MANIFEST.txt` com tamanho e SHA-256 e `resumo.json`. As credenciais vão ao mysqldump por arquivo temporário, nunca na linha de comando.
  Onde: `scripts/backup-worker.php`, `scripts/criar-agendamento-backup.ps1`, `backup_config`/`backup_execucoes` e `sp_backup_*` no universal (migração 351); origem: Decisão do usuário (25/10/2026): diário às 02:00, bancos + aplicação + GED.
- **RN-PLT-132** [PENDENTE: migração 351 aplicada e serviço instalado em 26/10/2026; falta o primeiro backup completo] — GED: espelho incremental em `<destino>\ged_espelho\portal` (GED do portal, sem `logs`) e `\legado` (GED do sistema anterior, só leitura): a cada backup copia só o que é novo ou mudou; arquivo apagado no GED continua no espelho, e o espelho nunca é apagado pela rotação.
  Onde: `backupEspelhar()` em `scripts/backup-worker.php`; origem: Decisão do usuário (25/10/2026).
- **RN-PLT-133** [PENDENTE: migração 351 aplicada e serviço instalado em 26/10/2026; falta o primeiro backup completo] — Destino: `BACKUP_DIR` no .env (outro disco ou compartilhamento de rede; o serviço roda como SYSTEM e precisa de permissão de gravação). Sem ele, ou no mesmo disco da aplicação, o backup vai para `backups\automatico` / o disco da aplicação e a tela avisa que não está protegido. Antes de começar, confere o espaço livre (pelo menos 1,2 × o último backup).
  Onde: `backupDestino()`, `principal/administracao_backup.php`; origem: Decisão do usuário (25/10/2026): "Pasta configurável no .env".
- **RN-PLT-134** [PENDENTE: migração 351 aplicada e serviço instalado em 26/10/2026; falta o primeiro backup completo] — Rotação: ficam os backups diários mais recentes (padrão 14, um por dia) e o último de cada uma das últimas semanas (padrão 8); as demais pastas `greenportal_*` são apagadas e marcadas no histórico. A rotação só roda depois de um backup sem erro nos bancos. Backup com erro tem a pasta apagada (não serve para restaurar). Backups manuais antigos da pasta `backups` (outros nomes) não são tocados.
  Onde: `backupManter()`, `sp_backup_guardados`, `sp_backup_marcar_removido`; origem: Decisão do usuário (25/10/2026): "14 diários + 8 semanais".
- **RN-PLT-135** [PENDENTE: migração 351 aplicada e serviço instalado em 26/10/2026; falta o primeiro backup completo] — Retenção (RN-PLT-127) agendada: depois de cada backup, roda com `--execute` em todas as empresas ativas com banco existente e as procedures da migração 281. Pedidos de exportação `SOLICITADA` nunca são apagados (a procedure só lista `CONCLUIDA` e `ERRO`).
  Onde: `scripts/backup-worker.php` (passo 6), `sp_backup_bancos_retencao`, `sp_exportacoes_retencao_listar`; origem: Decisão do usuário (25/10/2026): "Agendar em todas as empresas".
- **RN-PLT-136** [PENDENTE: migração 351 aplicada e serviço instalado em 26/10/2026; falta o primeiro backup completo] — Avisos: backup com erro alerta os ADMINISTRADORES ativos de cada empresa; também uma vez por dia se o último backup concluído tiver mais de 26 horas. A tela mostra o último backup, o próximo, o destino, o serviço parado (mais de 30 minutos sem ciclo) e o histórico (status, duração, tamanho, bancos, GED copiados, retenção, pasta e se foi apagado na rotação). Só ADMINISTRADOR acessa a tela.
  Onde: `backupAlertar()`, `principal/administracao_backup.php` (menu `ADMINISTRACAO_BACKUP`); origem: Decisão do usuário (25/10/2026).
- **RN-PLT-137** [PENDENTE: worker corrigido gravado em 26/10/2026; falta um novo backup] — O mysqldump do backup roda com a sessão em `utf8mb4_general_ci`, a mesma collation da conexão do portal (`--init-command`; `BACKUP_COLLATION` no .env troca, se for preciso). Na collation padrão do MySQL 8 (`utf8mb4_0900_ai_ci`), o SHOW CREATE de uma view que converte número em texto e o compara com um texto fixo falha com "Illegal mix of collations", e o mysqldump para no meio do banco. Isso aconteceu no primeiro backup (26/10/2026, 08:37) com `vw_fornecedor_ranking_calculo` em 7 bancos. A view também foi corrigida no runner da 289 (NOTA convertida em texto com collation explícita), e vale para a próxima execução dele.
  Onde: `backupInitCommand()` em `scripts/backup-worker.php`, `scripts/aplicar-migracao-289-ranking-fornecedores.php`; origem: primeiro backup manual (26/10/2026).
- **RN-PLT-138** [PENDENTE: 351 rodada de novo e worker gravado em 26/10/2026; falta o primeiro backup completo, com BACKUP_DIR fora do servidor] — Proteções do backup em andamento:
  - **Espaço livre:** o backup não começa, e para no meio, se o destino ficar com menos de `BACKUP_MIN_LIVRE_GB` (padrão 20 GB) livres. A conferência é feita a cada 30 segundos durante os dumps e a cópia do GED. Termina em ERRO, com o motivo. A cópia do GED feita até ali fica no espelho, porque ele é incremental.
  - **Cancelar em execução:** o botão Cancelar de Administração › Backup também vale para backup em execução (situação "Cancelando"). O serviço confere a cada 30 segundos, encerra o mysqldump ou a cópia, apaga a pasta do dia e grava CANCELADO, sem alerta de erro. Se o serviço tiver parado, o próximo ciclo grava CANCELADO.
  - **Banco com erro:** se algum banco falhar no dump, o backup não copia a aplicação nem o GED, porque já não serve para restaurar.
  Onde: `backupMinimoLivre()` e `$vigiar` em `scripts/backup-worker.php`; `sp_backup_cancelar`, `sp_backup_worker_situacao` e `sp_backup_worker_reivindicar` (runner da 351); `principal/administracao_backup.php`. Origem: primeiro backup manual (26/10/2026), que copiava o GED do legado para o disco E: com 10,9 GB livres e não podia ser cancelado pela tela.
- **RN-PLT-139** [FEITO 28/10/2026: script aplicado] — Otimização das tarefas do Agendador. Foram desativadas as duas tarefas repetidas. `GreenPortalTarefas` iniciava o task-worker pelo `.vbs` sem esperar o fim, e as cópias se empilhavam. `GreenPortal-TaskWorker` refazia o trabalho de Agenda, Responsibility e Notification. As frequências passaram a ser: Migração e Sincronismo a cada 5 min; IA, OCR e Notificações a cada 2 min; E-mail a cada 5 min (enquanto o SMTP estiver desligado); Workflow, Conteúdo e GED a cada 1 h. Os tempos máximos ficaram entre 5 e 15 min. Exportação e Visualizador continuam a cada 1 min. O Xdebug foi tirado do PHP de linha de comando (`php.ini` do php8.4.15; o Apache usa o `phpForApache.ini`).
  Onde: `scripts/otimizar-agendamentos-greenportal.ps1` (cópia das tarefas antigas em `storage\runtime\agendamentos_backup_20261028_072210`). Origem: Decisão do usuário (28/10/2026): "sim vamos para fase 1". Motivo: em 27/10 e na madrugada de 28/10 o Windows deixou de iniciar processos em segundo plano depois de ~8 h de funcionamento. Eram ~880 processos PHP por hora, e os que não fechavam se acumulavam.
- **RN-PLT-140** [FEITO 11/11/2026: 352 aplicada, tarefas recriadas e trilhas em EXECUTAR. O `-Ativar` de 28/10 deixou as trilhas paradas até 11/11 porque as tarefas tinham sido registradas sem o `--` antes de `--trilha`; ver RN-PLT-142] — Orquestrador dos serviços, com 3 trilhas no lugar das ~20 tarefas soltas. Cada trilha é uma tarefa do Agendador que roda `scripts/orquestrador.php --trilha=N`, e só um ciclo de cada trilha roda por vez.
  - **Trilha 1, rápida** (1 min): Exportação Excel → Visualizador.
  - **Trilha 2, filas e regras** (1 min): OCR → IA → Agenda → Responsabilidades → Notificações → E-mail → Lembretes de NC → Taxímetro de IA → Documentos, Protocolo, Resumo e Relatórios da homologação → Monitor → Workflow, Conteúdo e GED.
  - **Trilha 3, pesada** (5 min, um serviço por vez): Sincronismo → Busca → Ranking → Migração de empresas → Backup (desligado).
  - **Hierarquia:** a ordem dentro da trilha segue as dependências. O OCR roda antes da IA, os alertas antes do e-mail e o sincronismo antes da busca e do ranking. Um serviço pesado nunca atrasa a exportação do usuário, porque as trilhas são processos separados.
  - **Cadastro** (`orq_servicos`): trilha, ordem, ligado/desligado, frequência em minutos, horário fixo (diário), dias da semana (1 = segunda … 7 = domingo), janela de horário e tempo máximo. Os horários são os do MySQL (Brasília). Cada execução fica em `orq_execucoes` por 30 dias.
  - **Contra travamento:**
    - cada serviço roda como processo filho; se passar do tempo máximo, o processo e tudo o que ele abriu são encerrados (TEMPO_ESGOTADO);
    - 3 falhas seguidas (erro, tempo esgotado ou abandonada) suspendem o serviço por 30 min, e os outros seguem;
    - se um ciclo morrer, o próximo encerra o processo que ficou órfão (ABANDONADA);
    - cada trilha grava um sinal de vida a cada minuto (`orq_trilhas`).
  - **Modo SIMULAR:** a trilha só registra o que rodaria. Serve para conferir o calendário antes de desligar as tarefas antigas. `criar-agendamento-orquestrador.ps1 -Ativar` passa para EXECUTAR e desativa as tarefas antigas, com cópia delas; `-Voltar` desfaz.
  Onde: `scripts/aplicar-migracao-352-orquestrador.php` (universal: `orq_trilhas`, `orq_servicos`, `orq_execucoes`, `vw_orq_servicos`, `sp_orq_*`), `scripts/orquestrador.php`, `scripts/criar-agendamento-orquestrador.ps1`. Origem: Decisão do usuário (28/10/2026): "quero otimizar os processo e quero um controle para não travamento e tambem uma hierarquia de ordem de rodar os processos". A tela Administração › Serviços passa a mostrar as trilhas numa próxima entrega.
- **RN-PLT-141** [FEITO 11/11/2026: em uso com o orquestrador] — Proteções comuns dos serviços:
  - a conexão ao MySQL desiste depois de 10 s (`DB_CONNECT_TIMEOUT` no .env muda), em vez de esperar para sempre;
  - os serviços de `windows-service.php` encerram sozinhos depois de 20 min (`SERVICE_MAX_SECONDS`), porque no Windows o PHP conta o tempo real;
  - **disjuntor por empresa:** 3 erros seguidos de um serviço numa empresa deixam o serviço sem rodar nela por 30 min, e as outras seguem (`storage\runtime\disjuntor-<serviço>.json`). Foi o caso da Luxottica, com o erro da IA a cada minuto;
  - o início das execuções em `green_service_runs` passa a ser gravado pelo relógio do MySQL, o que corrige as durações negativas de 3 h (o PHP dos serviços está em UTC);
  - o `task-worker.php` ganhou trava de uma execução por vez.
  Onde: `classes/GreenPortalDatabase.php` (`PDO::ATTR_TIMEOUT`), `classes/WindowsServiceRuntime.php` (`run()`, `disjuntor()`), `scripts/windows-service.php`, `scripts/task-worker.php`. Origem: Decisão do usuário (28/10/2026), a mesma da RN-PLT-140.
- **RN-PLT-142** [PENDENTE: migração 353 gravada em 11/11/2026; falta aplicar e recriar as tarefas] — Vigia dos serviços e avisos aos administradores.
  - **Vigia:** tarefa própria `GreenPortal-Orquestrador-Vigia`, a cada 10 min, fora das trilhas (`scripts/orquestrador-vigia.php`). Se uma trilha morre, o vigia continua rodando.
  - **O que ele confere:**
    - trilha ligada sem sinal de vida há mais de 15 min (ou que nunca rodou);
    - tarefa da trilha desativada ou não encontrada no Agendador;
    - serviço suspenso por 3 falhas seguidas (RN-PLT-140);
    - empresa suspensa pelo disjuntor (RN-PLT-141), com o último erro gravado em `green_service_runs`;
    - trilha em SIMULAR ou desligada (aviso, não erro).
  - **Alertas:** vão aos ADMINISTRADORES ativos, com link para Administração › Serviços. Problema de uma empresa só vai aos ADMINISTRADORES dela; os outros vão a todas as empresas.
    - O alerta sai quando o problema aparece, de novo a cada 24 h enquanto continuar, e quando ele é resolvido.
    - Vários problemas no mesmo ciclo viram um alerta só.
    - Serviço ou empresa suspensa só conta como resolvida depois de 60 min sem aparecer, para uma suspensão que volta logo não gerar alerta a cada meia hora.
  - **Aviso no portal:** ao abrir o portal, o ADMINISTRADOR vê um quadro no canto da tela com os problemas atuais e um botão para a tela Serviços. Ele não depende de nenhuma tarefa do Windows: aparece mesmo com o vigia e o e-mail parados. Fechar esconde o quadro até a lista de problemas mudar.
  - **E-mail:** os alertas viram e-mail pelo serviço de E-mail (trilha 2). Com a trilha 2 parada, ficam no sino e no quadro do portal.
  Onde: `scripts/aplicar-migracao-353-orquestrador-vigia.php` (universal: `orq_vigia`, `orq_avisos`, colunas `tarefa_*` em `orq_trilhas`, `sp_orq_saude`, `sp_orq_aviso_abrir`, `sp_orq_aviso_resolver_ausentes`, `sp_orq_avisos_listar`, `sp_orq_tarefa_windows`, `sp_orq_vigia_sinal`), `scripts/orquestrador-vigia.php`, `classes/AgendadorWindows.php`, `components/aviso_servicos.php` (chamado em `principal/main.php`), `scripts/criar-agendamento-orquestrador.ps1` (cria a tarefa do vigia). Origem: Decisão do usuário (11/11/2026): "sim vamos seguir conforme sua orientação", depois de descobrir que as 3 trilhas ficaram paradas de 28/10 a 11/11/2026 sem ninguém ser avisado.
- **RN-PLT-143** [PENDENTE: gravada em 11/11/2026; falta aplicar a 353] — Tela Administração › Serviços sobre o orquestrador.
  - **Problemas atuais** no topo, os mesmos do vigia.
  - **Um quadro por trilha:** modo (EXECUTAR/SIMULAR), sinal de vida (verde até 3 min, amarelo até 15, vermelho acima), serviço rodando agora, último ciclo, situação da tarefa do Windows, serviços ligados e suspensos, falhas nas últimas 24 h. Há também um quadro do vigia.
  - **Grid dos serviços, agrupado por trilha:** ordem, frequência (ou horário fixo e dias), tempo máximo, última execução (situação e duração), próxima prevista, falhas seguidas e suspensão, fila e última mensagem. Tem filtros por trilha e situação.
  - **Histórico:** clicar num serviço mostra as últimas 100 execuções dele; o botão do relógio mostra as de todos (`orq_execucoes`, 30 dias).
  - **Só o ADMINISTRADOR altera:**
    - cadastro do serviço: ligado, trilha, ordem, frequência, horário fixo, dias e tempo máximo (`sp_orq_servico_alterar`; salvar também tira a suspensão);
    - botão Liberar: tira a suspensão e zera as falhas (`sp_orq_servico_liberar`).
    - Cada alteração é auditada em `servicos_auditoria`. O ADM DA EMPRESA só consulta.
  - **O que a tela não faz:** não liga nem desliga tarefas do Windows e não muda o modo das trilhas; isso é feito pelo PowerShell (`criar-agendamento-orquestrador.ps1`, `orquestrador.php --modo=`).
  - `GreenPortal-ServiceMonitor` (dentro da trilha 2) passa a coletar só as 4 tarefas do orquestrador, além das filas.
  Onde: `principal/servicos.php`, `scripts/service-monitor.php`, `classes/AgendadorWindows.php`, procedures `sp_orq_trilhas_situacao`, `sp_orq_vigia_situacao`, `sp_orq_saude`, `sp_orq_situacao`, `sp_orq_execucoes_listar`, `sp_orq_servico_alterar`, `sp_orq_servico_liberar`. Origem: a mesma decisão da RN-PLT-142; a RN-PLT-140 previa a tela "numa próxima entrega".

- **RN-PLT-144** [GRAVADO 12/11/2026; falta o teste com usuário ADM] — Idioma do cadastro do usuário (GER-15 do 01_Mudancas_Gerais).
  - O idioma fica em `preferencias_usuario` (chave `idioma`: pt, en ou es).
  - Depois do login vale o idioma do cadastro. O seletor da tela de login só traduz a própria tela de login.
  - O usuário troca em Profile (campo "Idioma do portal") ou no menu de idioma do cabeçalho; as duas formas gravam o cadastro. Ao trocar em Profile, o portal recarrega no novo idioma.
  - O ADMINISTRADOR e o ADM DA EMPRESA trocam em Segurança › Usuários › botão "Idioma e foto" (linha selecionada). Vale no próximo login do usuário. O ADM DA EMPRESA não altera usuários ADMINISTRADOR.
  - Usuário sem idioma no cadastro: continua valendo o escolhido no login.
  - Auditoria: PERFIL_USUARIO/IDIOMA e SEGURANCA_USUARIOS/IDIOMA (de/para).
  Onde: `classes/UsuarioImagem.php` (`idioma`, `salvarIdioma`), `credenciais/logar.php`, `principal/api/idioma.php`, `principal/meu_perfil.php`, `principal/seguranca_usuario_preferencias.php`.
- **RN-PLT-145** [GRAVADO 12/11/2026; falta o teste com usuário ADM] — Logo do cliente (GER-1).
  - Enviado pelo ADMINISTRADOR e pelo ADM DA EMPRESA, clicando no nome da empresa no cabeçalho. PNG, JPG ou WebP até 1 MB.
  - A imagem é regravada como PNG (no máximo 480×160, transparência mantida) em `storage/empresa_logos/<banco>.png`. A pasta não é acessível pela web; a imagem só sai pelos PHP.
  - Aparece no cabeçalho, ao lado do nome da empresa, e na tela de login quando a empresa é escolhida (`logo_empresa.php?e=<código>`, só empresas da lista do login). Sem logo, nada muda.
  - Auditoria: EMPRESA_LOGO/ENVIAR e REMOVER.
  Onde: `principal/empresa_logo.php`, `principal/empresa_logo_img.php`, `logo_empresa.php`, `index.php`, `principal/main.php`, `assets/js/cabecalho-imagens.js`.
- **RN-PLT-146** [GRAVADO 12/11/2026] — Foto do usuário (GER-18).
  - Só o próprio usuário envia, em Profile › "Trocar foto": JPG, PNG ou WebP até 2 MB. A imagem é cortada no centro em quadrado e regravada como JPEG 256×256 em `storage/usuarios_fotos/<banco>/<codusuario>.jpg` (pasta fechada para a web; sai por `principal/usuario_foto.php`, com sessão).
  - O usuário remove em Profile. O ADMINISTRADOR e o ADM DA EMPRESA removem em Segurança › Usuários › "Idioma e foto"; eles não enviam foto por outra pessoa.
  - Aparece no cabeçalho (no lugar da inicial) e no Painel do gestor › Minha equipe › Por pessoa. Nas listas de responsáveis dos módulos ainda não aparece.
  - Auditoria: PERFIL_USUARIO/FOTO_ENVIAR, FOTO_REMOVER e SEGURANCA_USUARIOS/FOTO_REMOVER.
  Onde: `principal/meu_perfil.php`, `principal/usuario_foto.php`, `principal/dashboard_gestor.php`, `principal/seguranca_usuario_preferencias.php`.
- **RN-PLT-147** [GRAVADO 12/11/2026] — Sino com duas abas: Alertas e Pendências (GER-1).
  - **Alertas:** a lista de sempre.
  - **Pendências:** resumo das Minhas pendências (as 8 mais urgentes, prazo mais antigo primeiro), com o total de vencidas e a vencer. Clicar abre a tela do item; "Ver todas" abre Home › Minhas pendências.
  - O número do sino soma os alertas não lidos e as pendências vencidas (acima de 99 mostra 99+).
  - As pendências são lidas ao abrir o sino e a cada 5 minutos.
  Onde: `principal/api/pendencias_resumo.php` (usa `MinhasPendencias`), `assets/js/sino-pendencias.js`, `assets/js/alertas.js`, `principal/main.php`.

- **RN-PLT-148** [GRAVADO 12/11/2026, migração 358; testado como Nathalia] — Minhas atividades (GER-5.1 e GER-5.2 do 01_Mudancas_Gerais).
  - Aba da Home (não é item do menu): uma sub-aba por módulo, cada uma é a tela do módulo filtrada para o usuário logado. Salvar sim; incluir, duplicar e excluir não.
  - "Meu" = responsável direto no registro ou por atribuição de usuário/grupo (vw_responsabilidade_efetiva). Requisitos legais: responsável da situação ou atribuição (o RESPONSAVEL_IDS de vw_modulolegal_requisitos_grid passou a incluir as atribuições, o que vale também para o filtro Responsável da tela geral). Fornecedores: responsável pelo fornecedor, pela integração ou atribuição. Funcionários: os dos meus fornecedores e os atribuídos a mim.
  - Sub-abas: Requisitos legais, Licenças, Condicionantes, Medições e monitoramentos, Controles, Treinamentos, Infraestruturas, Fornecedores, Funcionários de fornecedores, Ocorrências (NC) e Ações. Cada uma só aparece se o perfil pode ver a tela do módulo (inclui a contratação do módulo); Fornecedores e Funcionários não aparecem para o fornecedor externo.
  - Ao lado do título, a quantidade de registros (a mesma contagem da tela; em Ocorrências, só as não finalizadas; em Ações, só as não concluídas). As sub-abas ficam em ordem decrescente dessa quantidade.
  - **Ações:** ações dos planos de Condicionantes, Fornecedores e NC em que o usuário é o responsável (só responsável direto: os planos não têm grupo). Situação pelo % (100 = concluída) e pelo prazo: Atrasada, Vence em até 7 dias, No prazo, Sem prazo, Concluída. Abre mostrando as não concluídas; filtros por módulo, situação e texto. Clicar abre o plano no módulo (a NC pelo Número/Ano). Ações de NC cuja ocorrência não existe mais ficam de fora. O Módulo Legal não tem plano de ação nos requisitos.
  - Observação: Controles, Treinamentos e Infraestruturas (telas anteriores) consideram só as atribuições; na Crown, que não tem atribuições, ficam vazias.
  Onde: `principal/home.php`, `principal/minhas_atividades.php`, `principal/minhas_acoes.php`, `principal/meus_requisitos.php`, `principal/minhas_condicionantes.php`, `principal/minhas_medicoes.php`, `principal/meus_fornecedores.php`, `principal/meus_funcionarios.php`; views em `database/358_minhas_atividades.sql` e `scripts/aplicar-migracao-358-minhas-atividades.php`.

- **RN-PLT-149** [GRAVADO 12/11/2026] — Segurança › Permissões: o que o ADM DA EMPRESA pode mudar (pedido do usuário: restringir ao máximo fora do ADMINISTRADOR).
  - Os acessos dos perfis de ADM da empresa (ADM_DA_EMPRESA / ADM DA EMPRESA / ADM EMPRESA) só são alterados pelo ADMINISTRADOR; o ADM da empresa só consulta (sem criar as linhas que faltam).
  - Os menus dos ramos Administração, Segurança e Configurações (Setup) não aparecem (grade, filtro, contador, matriz) e não podem ser alterados por quem não é ADMINISTRADOR.
  - Fora o ADMINISTRADOR, a tela só altera as marcações das permissões existentes: incluir, duplicar e excluir negados (botões escondidos; o servidor recusa; trocar o perfil ou o menu de uma linha também é recusado).
  - Ao abrir um perfil permitido, a tela continua criando, sem acesso marcado, as linhas que faltam dos menus permitidos (para o ADM poder liberar uma tela nova).
  Onde: `principal/seguranca_permissoes.php` (backup em `backups/pre-permissoes-adm_20261112`).
- **RN-PLT-150** [GRAVADO 12/11/2026, migração 359; testado como Nathalia] — Históricos obrigatórios (GER-16; MM-77, CON-93, TRE-108, INF-123, HOM-141).
  - Tabelas: mm_monitoramento, documentos (Controles), ml_treinamentos, ml_infraestrutura, fo_fornecedores, fo_funcionarios. Gatilhos trg_hist_<tabela>_ai/_au/_ad gravam em `historico_alteracoes` toda inclusão (todos os campos), alteração (só os campos que mudaram, antes e depois) e exclusão (todos os campos), de qualquer origem (tela, IA, procedures, sincronismo). Regravação sem mudança não gera linha.
  - Quem/onde: o portal define @gp_usuario e @gp_origem ("tela:<arquivo>" / "cli:<script>") em toda conexão (GreenPortalDatabase::initCommand); gravação fora do portal (sincronismo com o sistema antigo, rotina do banco) fica sem usuário e aparece como "Fora do portal".
  - Consulta: botão Histórico (relógio) nas grades dessas tabelas, ativo com uma linha selecionada (também nas abas de Minhas atividades); janela com eventos (data/hora, usuário, origem, operação) e campos com o nome da tela, códigos de usuário/unidade com o nome, filtro por período e campo, exportação CSV. Quem vê a tela vê o histórico (conferido pela tela, não registro a registro); o fornecedor externo não vê o de fornecedores/funcionários.
  - Não há histórico anterior a 12/11/2026. Retenção: sem limite (retenção configurável e envio por e-mail ficam com os logs opcionais).
  - Rodar a migração de novo depois de criar colunas nessas tabelas (os gatilhos usam as colunas atuais). Os gatilhos de outros nomes das tabelas foram mantidos.
  - Correção junto (12/11/2026): incluir em Treinamentos e Infraestrutura falhava com "Campo nao autorizado na operacao de inclusao" (o campo oculto Responsável era enviado e as tabelas não têm essa coluna); passou a somente leitura.
  Onde: `classes/HistoricoAlteracoes.php`, `classes/GreenPortalDatabase.php`, `classes/ManutencaoLegalCadastroGrid.php`, `principal/historico_registro.php`, `assets/js/historico-registro.js`, `database/359_historico_alteracoes.sql`, `scripts/aplicar-migracao-359-historico.php`.

- **RN-PLT-151** [FEITO 13/11/2026, migração 361; testado como Nathalia na Crown; ajustado em 16/11/2026] — Responsáveis e avisos por módulo (GER-3; LIC-50/51, MM-72/78, CON-91/94, TRE-106/109, INF-121/124).
  - Menu "Responsáveis e avisos (<módulo>)" logo depois da tela de Licenças, Plano de Medição e monitoramento, Controles, Treinamentos e Infraestrutura (RESP_REGRAS_<ÁREA>); permissões iniciais = as da tela do módulo.
  - Regra = para quais registros (Licenças: Tipo documental e Unidade; Medição: Tipo de medição e Unidade; Controles, Treinamentos e Infraestrutura: Unidade; "Todos/Todas" = sem filtro) → um usuário ou um grupo (valem os usuários ativos do grupo). Decisões do usuário de 12/11/2026: a regra é **dinâmica** (vale na hora para registros novos e antigos; nada é copiado para o registro) e **soma** com o Responsável do registro e as atribuições. Licença: só a versão em vigor. Regra repetida (mesmo tipo, unidade e pessoa/grupo) não é aceita. Usuário ou grupo inativo deixa de valer sem apagar a regra.
  - Um registro só: botão Responsáveis (ícone de pessoas) nas grades dos cinco módulos e nas telas Minhas, com a linha selecionada; mostra quem responde hoje e por quê (Responsável do registro, Atribuição, Regra do módulo, Responsável deste registro) e permite incluir usuários ou grupos só para o registro (resolve o grupo na Medição). Permissão: a da tela do módulo, da tela Minhas ou do menu novo.
  - A lista de pessoas da tela não mostra as contas de fornecedor (perfil FORNECEDOR, FORNECEDOR_GESTOR ou FORNECEDORES) — 16/11/2026.
  - Telas Minhas (Minhas licenças, Minhas medições, Meus controles, Meus treinamentos, Minhas infraestruturas e as abas de Home › Minhas atividades) = Responsável do registro + atribuições (usuário e grupo) + regras. As telas principais dos módulos não mudaram: sem "Visualizar total", o usuário continua vendo só os registros em que é o Responsável direto.
  - vw_responsabilidade_efetiva inclui as regras (ORIGEM 'REGRA'); a definição anterior fica em vw_responsabilidade_efetiva_base e em storage/logs. Responsáveis automáticos (RN da 345): registro coberto por regra conta como "com responsável".
  - Avisos (serviço de Responsabilidades; um alerta por pessoa, módulo e tipo, com a lista dos registros; o mesmo aviso não se repete; viram e-mail conforme Minha conta › Alertas por e-mail):
    - Prazos (Licenças e Medição): validade em até 30 dias, data de reação atingida nos últimos 7 dias, medição prevista em até 7 dias. **Decisão do usuário de 16/11/2026:** o Responsável do registro e quem tem atribuição recebem **sempre**; a marcação Prazos da regra inclui as pessoas dela. Na primeira execução depois da mudança, cada responsável recebe tudo o que já está na janela (decisão do usuário).
    - Atribuição: quando a pessoa passa a responder por registros pela regra (marcação da regra).
    - Alteração (Medição, Controles, Treinamentos, Infraestrutura; histórico da RN-PLT-150): quando outra pessoa altera o registro (marcação da regra); quem alterou não é avisado; alterações anteriores à primeira execução não viram aviso. Licenças não tem histórico.
  - Antes da 361 o portal não avisava vencimento/reação de Licenças nem Medição (só as taxas).
  Onde: `classes/ResponsaveisRegras.php`, `principal/responsaveis_regras.php` (+ `responsaveis_regras_<área>.php`), `assets/js/responsaveis-registro.js`, `classes/ManutencaoLegalCadastroGrid.php` (`resp_area`, `resp_minhas`), `classes/ResponsavelAutomatico.php`, `scripts/windows-service.php` (`responsaveisRegrasAvisos`), `database/361_responsaveis_regras.sql` (tabelas resp_regras, resp_regras_avisos, resp_regras_controle; sp_resp_regras_avisar), `database/361_ajuda_responsaveis_regras.php` (Ajuda da transação responsaveis_regras), `scripts/aplicar-migracao-361-responsaveis-regras.php` (`--simular`, `--banco=`, `--remover`).

- **RN-PLT-152** [FEITO 16/11/2026, migração 362; testado como Nathalia na Crown] — Avaliação da eficácia nos planos de ação (GER-5 do 01_Mudancas_Gerais). A NC já tinha ("Passar pela análise da eficácia?", por ocorrência); entram os planos de ação das Condicionantes de licenças (li_planodeacao) e dos Fornecedores (fo_planodeacao).
  - Decisões do usuário (16/11/2026): marcação **em cada ação** (colunas no fim da grade: Avaliar eficácia?, Avaliador, Eficácia, Data da avaliação, Observação da avaliação, Situação da eficácia); ação marcada a 100% vira **pendência com alerta**; o **avaliador é escolhido na ação** (vazio = o Responsável da condicionante / do fornecedor; sem ninguém, o responsável da ação).
  - Regras no banco (gatilhos trg_eficacia_<tabela>_bi/_bu; valem para a grade, a IA e qualquer gravação): ação nova ou duplicada começa sem resultado; o resultado (Eficaz / Não eficaz) só com "Avaliar eficácia?" = Sim e a ação a 100%, e só pelo avaliador, ADMINISTRADOR ou ADM DA EMPRESA (gravação fora do portal passa); data vazia = hoje; grava quem registrou e uma linha em plano_eficacia_historico; **Não eficaz reabre a ação (volta para 75%)** e o resultado continua visível; quando a ação reaberta volta a 100%, o resultado sai e a avaliação fica pendente de novo. Durante o sincronismo com o sistema antigo (@gp_sinc_legado = 1) os gatilhos não agem — o legado ainda manda em li_planodeacao/fo_planodeacao até a virada (pode alterar/excluir ações; as colunas da eficácia só existem no portal).
  - Pendência "Avaliar eficácia" do avaliador: Home › Minhas pendências, sino e Minhas atividades › Ações (situação nova, primeira na ordem; conta como não concluída). Alerta (serviço de Responsabilidades, sp_eficacia_avisar): um por avaliador e módulo, com a lista das ações; o mesmo ciclo não é avisado de novo (plano_eficacia_avisos).
  - Views: vw_eficacia_planos, vw_eficacia_pendentes (CODUSUARIO = avaliador); as views das grades ganham as colunas (a anterior fica em <view>_base362 e storage/logs). `--remover` tira gatilhos, views e procedure e volta as grades; colunas e histórico ficam.
  Onde: `classes/EficaciaPlanos.php`, `principal/manutencaolegal_licencas_condicionantesplanodeacao.php`, `classes/HomologacaoFornecedorRelacionamentoGrid.php`, `classes/MinhasPendencias.php`, `principal/minhas_acoes.php`, `scripts/windows-service.php` (`eficaciaAvisos`), `database/362_eficacia_planos.sql`, `database/362_ajuda_eficacia_planos.php` (Ajuda de liPlanodeAcao e homologacaofornecedor_fornecedorxplanodeacao), `scripts/aplicar-migracao-362-eficacia-planos.php` (`--simular`, `--banco=`, `--remover`).

- **RN-PLT-153** [FEITO 16/11/2026, migração 365; testado como Nathalia na Crown] — Gestão de pendências (GER-18.1 deletar, 18.2 não gerar, 18.3 limpar pendências, com log). Home › aba **Gestão de pendências** (`principal/gestao_pendencias.php`).
  - Decisões do usuário (16/11/2026): usam **ADMINISTRADOR e ADM DA EMPRESA (todos os usuários ativos) e gestores (ele e a equipe: codusuario_superior em todos os níveis)**; a aba só aparece para quem tem pelo menos mais uma pessoa no escopo. **Ocultar volta se a situação mudar** (a regra guarda o prazo da pendência; prazo diferente = a pendência aparece de novo; sem prazo, fica oculta até reativar). **Não gerar** por **registro** (vale para todos os responsáveis do registro, até reativar) e por **usuário** (data de volta opcional; vazia = até reativar; data passada não é aceita). **Motivo obrigatório** (mínimo 5 letras) e **aviso ao responsável** (alerta com link para a Home; sem aviso quando a pessoa mexe nas próprias pendências).
  - Ações: Ocultar selecionadas (18.1), Limpar todas as filtradas (18.3; uma linha LIMPAR no log com a quantidade e um lote), Não gerar para o registro e Não gerar para a pessoa (18.2), Reativar (com motivo; aba Ocultas e desligadas). Aba Log: quem, quando, ação, de quem, quantas, motivo e detalhe.
  - O registro no módulo não muda; somem só as pendências calculadas: Home › Minhas pendências, sino (api/pendencias_resumo.php), Agenda (via MinhasPendencias) e, na barra lateral, Próximos prazos e Calendário (vw_painel_prazos / vw_painel_calendario: TIPO+CODIGO → licença, monitoramento, treinamento, infraestrutura, controle, avaliação de fornecedor). A pendência é identificada pela tela e pelo filtro do link (registro) e pelo título (chave sha1). Fora desta fase: alertas já gerados, tarefas (green_tasks) e os avisos dos serviços da RN-PLT-151/152 (continuam sendo enviados).
  - A lista da pessoa usa as permissões de quem está usando a tela (módulos que o perfil não vê não aparecem).
  - Banco (cada empresa): tabelas pendencias_gestao (TIPO OCULTAR / NAO_GERAR_REGISTRO / NAO_GERAR_USUARIO, ATIVO, ATE, ASSINATURA, MOTIVO, autor e reativação) e pendencias_gestao_log; views vw_pendencias_gestao_ativas, vw_pendencias_gestao_grid, vw_pendencias_gestao_log. Gravação só por sp_crud_insert/sp_crud_update; leitura por sp_crud_select.
  Onde: `classes/PendenciasGestao.php`, `classes/MinhasPendencias.php` (`itens($todas)`), `classes/PainelLateralService.php`, `principal/gestao_pendencias.php`, `principal/home.php`, `database/365_gestao_pendencias.sql`, `database/365_ajuda_gestao_pendencias.php` (Ajuda da tela), `principal/guia_gestao_pendencias.php` (F1, assunto Início), `scripts/aplicar-migracao-365-gestao-pendencias.php` (`--simular`, `--banco=`, `--publicar-pendente`).

- **RN-PLT-154** [FEITO 16/11/2026, migração 366; testado como Nathalia na Crown] — Não Conformidades e Fornecedores nos dashboards (pedido do usuário: "não aparece nada sobre NC e Fornecedores").
  - **Quem age em cada etapa da NC** (regra única, view vw_dash_nc_abertas): Em criação / Recusado pelo Gestor → emitente; Com o Gestor e Avaliação da Eficácia → gestor; Análise da Causa Raiz, Plano de Ação e Recusado pela Avaliação da Eficácia → responsável (Planejamento e Assistência Técnica: gestor; sem gestor, emitente). "Parada" = dias desde a última passagem de etapa (nc_workflow_historico) ou desde a abertura. A etapa é lida com LIKE por causa da acentuação quebrada do legado.
  - **Dashboard da empresa:** aba **Não conformidades** (cartões por etapa, abertas há mais de 1 ano, data prevista vencida, ações de plano vencidas; tipo × etapa × tempo em aberto; abertas × finalizadas em 12 meses (data_de_criacao / data_de_finalizacao); por unidade; com quem estão, com paradas > 90 dias e usuários inativos; as 100 paradas há mais tempo). Aba **Fornecedores**: antes do ranking, a situação da homologação dos fornecedores ativos (sem responsável ou com responsável inativo, avaliação vencida/30 dias/sem data, documentos vencidos/reprovados, a vencer e sem situação, pendências de funcionários — mesmas cores do Painel de Homologação — e os que mais precisam de atenção). Visão geral: resumos de NC e de Fornecedores.
  - **Dashboard pessoal:** aba **Minhas não conformidades** (o que espera a minha ação por etapa, com o que fazer e dias parada; clique abre a NC na aba da fase; as que abri e estão com outras pessoas; minhas ações de plano vencidas) e aba **Meus fornecedores** (pendências de Homologação de Minhas pendências — respeitando a Gestão de pendências — e os fornecedores em que sou o responsável). Visão geral: cartões das duas abas. Fornecedor externo não vê Meus fornecedores.
  - As abas só aparecem se as views existirem na empresa (Luxottica sem NC/Painel de Homologação fica de fora).
  Onde: `classes/DashboardNcFornecedores.php`, `principal/dashboard.php`, `principal/dashboard_admin.php`, `database/366_dashboards_nc_fornecedores.sql` (vw_dash_nc_abertas, vw_dash_nc_mes, vw_dash_nc_resumo, vw_dash_fornecedores, vw_dash_fornecedor_itens, vw_dash_fornecedor_resumo), `database/366_ajuda_dashboards.php`, `scripts/aplicar-migracao-366-dashboards-nc-fornecedores.php` (`--simular`, `--banco=`; usa a collation do banco na conexão).

- **RN-PLT-155** [FEITO 16/11/2026, migração 380; testado como Nathalia na Crown, com o usuário de teste] — Transferência de responsabilidades (GER-8). Decisão do usuário: **redirecionar, sem alterar os registros** (até a virada o sincronismo com o legado desfaria a troca do responsável).
  - **Quem usa:** Home › Transferências — ADMINISTRADOR e ADM DA EMPRESA (todos os usuários ativos) e gestores (a equipe, mesmo escopo da Gestão de pendências).
  - **Como:** quem passa (prévia com a quantidade por módulo), módulos (Requisitos legais, Licenças/condicionantes/taxas, Medição, Controles, Treinamentos, Infraestrutura, Fornecedores, NC, Agenda; todos = também o que não tem módulo), quem assume, temporária (último dia; volta sozinha) ou definitiva (até ser encerrada), início, motivo obrigatório (≥ 5 letras). Encerrar devolve na hora. Não pode haver duas transferências da mesma pessoa nos mesmos módulos e período, nem passar para quem está com esses módulos transferidos para outra pessoa.
  - **Efeito enquanto vale:** o que é de quem passa, nesses módulos, aparece e é cobrado de quem assume — Minhas pendências, sino, Agenda (motivo "Responsabilidade de X (transferida para você)"), Próximos prazos e calendário da barra direita ("(de X)"), tarefas e eventos das pendências universais, alertas e e-mails (gatilho na tabela alertas: troca o destinatário e acrescenta "Responsabilidade de X, transferida para você."; alerta sem módulo só quando a transferência é de todos; exportações não). Quem passa deixa de receber.
  - **Não muda:** os registros; Minhas atividades, dashboards e os cards do painel de Pendências (mostram só o próprio); telas de decisão que conferem o usuário dono (ex.: NC "Com o Gestor" — para NC do greenportal vale a transferência de NC da migração 334). Sem divisão por unidade.
  - **Registro e avisos:** log (transferencias_responsabilidade_log); alerta para quem assume, quem passa (se não foi quem registrou) e o gestor de quem passa; no encerramento, os dois.
  Onde: `classes/TransferenciaResponsabilidade.php`, `principal/transferencia_responsabilidades.php`, `classes/MinhasPendencias.php`, `classes/PainelLateralService.php`, `classes/PendenciasService.php`, `database/380_transferencia_responsabilidades.sql` (transferencias_responsabilidade, _log, vw_transferencias_responsabilidade_ativas, _grid, _log, trg_alertas_bi_transferencia).

---

## Pontos a confirmar

1. ~~Agendamento do backup e da retenção.~~ Decidido em 25/10/2026 (RN-PLT-131 a 136). Falta a restauração de teste periódica em servidor separado (RN-PLT-126), que continua manual.
2. Provisionamento de empresa nova (RN-PLT-10): a transação prevista nas diretrizes não foi localizada; hoje as empresas entram por migração.
3. Personalização de rótulos pelo ADM_DA_EMPRESA (RN-PLT-57): fica revogada pela decisão de 12/10/2026 (textos únicos no universal)?
4. Bloqueio de exportação repetida do mesmo grid pelo mesmo usuário (RN-PLT-77): a regra continua valendo?
5. Falha de auditoria (RN-PLT-124): deve barrar a operação em algum caso?
6. Matriz de permissões do GED (RN-PLT-93): é a configurada hoje nas três empresas?
7. ~~Status SOLICITADA na retenção.~~ Conferido em 25/10/2026: `sp_exportacoes_retencao_listar` só lista CONCLUIDA e ERRO; SOLICITADA nunca é apagada (RN-PLT-135).
8. Configuração do SMTP (RN-PLT-103): quando será feita e qual o remetente. Até lá, os alertas desse período não serão enviados por e-mail nem depois (é intencional: evita e-mail em massa quando o SMTP for ligado). Os e-mails que já estão na fila (ex.: participantes de NC, lembretes) ficam PENDENTE.
9. Política de senha para homologação/produção (RN-PLT-19): hash, tamanho mínimo, expiração e troca no primeiro acesso.
10. Reserva para o catálogo local de menus (RN-PLT-41): quando será retirada.

## Fontes

Documentos do projeto (cópias locais lidas):
- `claude/padrao-layout-telas.md` (cópia em `/home/claude/docs/padrao-layout-telas.md`)
- `claude/levantamento-sql-direto.md` (cópia em `/home/claude/levantamento-sql-direto.md`)
- `claude/ia-taximetro.md`, `claude/alertas-email.md`, `claude/ajuda-interativa.md`, `claude/busca-semantica.md` (conferidos na revisão de 24/10/2026).

Pasta `docs` do portal (`E:\wamp\apps\greenportal\docs`):
- `diretrizes-projeto.txt`, `diretriz-catalogo-menus-universal.md`, `diretriz-isolamento-transacoes.md`, `diretriz-personalizacao-login.md`, `diretriz-servicos-php-schedule-windows.md`
- `seguranca_diretrizes.txt`, `sessao_acesso_diretrizes.txt`, `login_diretrizes.txt`, `menu_diretrizes.txt`, `multitenant_idiomas_diretrizes.txt`, `idiomas_textos_20261012.md`
- `popups_diretrizes.txt`, `popup_independentes_diretrizes.md`, `padrao-visual-global-20261014.md`
- `exportacao_diretrizes.txt`, `servico_exportacao_excel_diretrizes.md`, `regra-exportacao-grid-20261013.md`
- `ged_arquivos_diretrizes.md`, `ocr_documentos_20261004.md`, `busca_documentos.md`
- `monitoramento-servicos-task-scheduler.md`, `servicos_gestao_diretrizes.md`, `green-tasks-task-scheduler.md`
- `retencao_exportacoes_alertas_diretrizes.md`, `backup_diretrizes.txt`, `modulos-por-empresa-20261014.md`, `auditoria_isolamento_tenants_20261004.md`
- `ajuda_transacoes_diretrizes.md`, `alertas_diretrizes.txt`, `estabilidade_servico_ia_diretrizes.md`, `ia_prompts_crud_diretrizes.md`

Código e migrações (só leitura):
- `classes/`: `IaTaximetro.php`, `AlertaEmailService.php`, `EmailFilaService.php`, `SmtpCliente.php`, `BuscaSemantica.php`, `AssistenteAplicacaoService.php`, `GuiaUsuarioService.php`, `GedPermissaoTipo.php`, `GedStorageService.php`, `VisualizadorPrevia.php`, `OcrQueueService.php`, `PermissaoTransacao.php`, `TenantContext.php`, `TransactionSecurity.php`, `AuditService.php`, `ModuloEmpresaService.php`, `ExportacaoSolicitacao.php`, `ExportacaoGridWorker.php`
- `credenciais/logar.php`, `scripts/windows-service.php`, `principal/api/gestao_arquivos.php`, `principal/api/ajuda.php`, `assets/js/ajuda.js`
- `database/340_ia_consumo.sql`, `database/340_ia_consumo_universal.sql`, `database/343_alertas_email.sql`, `database/343_fila_email.sql`, `database/346_busca_semantica.sql`
