# Pesquisa de documentos (busca universal) — Fase 1

Tela: `principal/busca_documentos.php` · menu `BUSCA_DOCUMENTOS` (no topo do menu) · lupa **Pesquisar documentos** no cabeçalho
(lupa: `assets/js/busca-cabecalho.js`, abre ou mostra a aba "Pesquisa de documentos"). Clientes: **gatlas_dbp** e **gmodelo_dbp**.

## O que entra no índice (`busca_indice`)
| tipo | Origem (view do grid) | Abre em | Arquivos (GED) | Acesso |
|---|---|---|---|---|
| licencas | vw_licencas_grid | manutencaolegal_licencas.php (CODLICENCA) | licencas\<CODLICENCA_BASE> | Visualizar em LICENCAS; sem "Visualizar total", só as do responsável |
| controles | vw_controle_documentos_grid | modulolegal_controle.php (CODDOCUMENTO) | documentos\<cod> | Visualizar em CONTROLE_DOCUMENTOS |
| requisitos | vw_modulolegal_requisitos_grid | modulolegal_requisitoslegais.php (CODLEGISLACAO + CODUNIDADE) | — | Visualizar em REQUISITOS_LEGAIS |
| treinamentos | vw_modulolegal_treinamentos_grid | modulolegal_treinamentos.php | treinamentos\<cod> | Visualizar em MODULOLEGAL_TREINAMENTOS |
| infraestrutura | vw_modulolegal_infraestrutura_grid | modulolegal_InfraEstrutura.php | infraestrutura\<cod> | Visualizar em MODULOLEGAL_INFRAESTRUTURA |
| fornecedorcriterios | vw_portaria_documentacao_fornecedor_grid | portaria_documentacaofornecedor.php | fo_fornecedorxcriterios\<cod> | Portaria: unidades do usuário |
| funcionariocriterios | vw_portaria_documentacao_funcionario_grid | portaria_documentacaofuncionario.php | fo_funcionarioxtiposxcriterios\<cod> | Portaria: unidades do usuário |
| funcionariotreinamentos | vw_portaria_documentacao_treinamento_grid | portaria_documentacaofuncionario_treinamentos.php | fo_funcionarioxtiposxtreinamentos\<cod> | Portaria: unidades do usuário |

ADMINISTRADOR vê tudo. Item REGISTRO = o cadastro; item ARQUIVO = cada arquivo da pasta do GED do registro (só nome, tamanho e data — o conteúdo fica para a Fase 2).
Busca por texto: FULLTEXT com parser ngram (acha partes de palavras; palavras de 1 letra são ignoradas).

## Serviço de indexação — BUSCA_WORKER
`scripts/busca-worker.php`, tarefa `\GreenSolutions\GreenPortal-BuscaWorker` a cada **10 minutos** (`scripts/criar-agendamento-busca.ps1`;
também incluída em `criar-agendamentos-greenportal.ps1`). Em cada banco: `sp_busca_indexar_registros` (só o que mudou, por hash; remove o que sumiu)
e, por tipo, lê as pastas do GED (as duas raízes) e chama `sp_busca_sincronizar_arquivos`. Execuções em `green_service_runs` (serviço `busca`) e na tela Serviços.
Arquivo enviado pela tela aparece na pesquisa na próxima execução (até 10 minutos).

## Resultado agrupado pelo negócio
Cada item do índice tem o seu **negócio** (`grupo_tipo` + `grupo_chave`): o próprio cadastro (licença, controle, requisito, treinamento,
infraestrutura) — com os seus arquivos — ou, na Portaria, o **fornecedor** (documentos de fornecedor) e o **funcionário** (documentos e
treinamentos do funcionário, com o fornecedor dele). A pesquisa (`sp_busca_pesquisar_negocios`) devolve uma linha por negócio, que expande e mostra:
- **Vínculos** (uma seção por relação, nos dois sentidos): no requisito, as licenças / controles / treinamentos / infraestrutura
  vinculados (evidências); na licença, "Atende aos requisitos legais"; e qualquer relação nova do catálogo. Só aparece o negócio
  vizinho que o usuário pode ver. Cada vínculo tem **Ver na pesquisa** (pesquisa só aquele negócio, com os vínculos dele — para
  seguir a cadeia requisito → licença → funcionário...). Mostra um nível de vínculo por vez (até 30 por seção).
- **Itens**: os arquivos do cadastro ou, na Portaria, os documentos / treinamentos com os seus arquivos (até 40 por grupo; os que bateram
  com a pesquisa primeiro; "Ver todos" abre a janela de arquivos ou a transação).
Pesquisar um requisito traz as evidências dele; pesquisar uma licença (ou um arquivo dela) mostra os requisitos que ela atende.

### Catálogo de relações (`busca_relacao`) — como ligar um módulo novo
Uma linha por relação: `codigo`, lados `tipo_a` / `tipo_b` (grupo_tipo: licencas, controles, requisitos, treinamentos, infraestrutura,
fornecedor, funcionario ou o tipo do módulo novo), `rotulo_a_pt/en/es` (título da seção no negócio A, que lista os do lado B),
`rotulo_b_*` (no negócio B), `icone_a` / `icone_b` (bootstrap-icons), `fonte` (view), `ordem`, `ativo`.
1. Criar a view `vw_busca_rel_<nome>` com as colunas `chave_a` e `chave_b` (o grupo_chave de cada lado: o código do cadastro;
   fornecedor = CODFORNECEDOR; funcionário = CODFUNCIONARIO).
2. Incluir a linha em `busca_relacao` (ex.: `LICENCA_FUNCIONARIO`, licencas ↔ funcionario, "Funcionários vinculados" / "Licenças vinculadas").
3. Na próxima execução do serviço (até 10 minutos) os vínculos aparecem nos dois lados. Não muda tela, API nem procedure.
Relações iniciais (criadas pela migração, se a tabela existir): REQUISITO_LICENCA (legislacaoxlicencas, pela pasta CODLICENCA_BASE),
REQUISITO_CONTROLE (legislacaoxdocumentos), REQUISITO_TREINAMENTO (legislacaoxtreinamentos), REQUISITO_INFRAESTRUTURA
(legislacaoxinfraestruturas); unidade 0 na ligação = todas as unidades do requisito. `ativo = 0` desliga uma relação.
Fornecedor / funcionário abre a tela da Portaria filtrada (CODFORNECEDOR / CODFUNCIONARIO). Paginação e total contam negócios.

## Dicionário da pesquisa
Termos do negócio (sigla / termo, significado, sinônimos `;`, **sigla** = só palavra inteira, **contexto** = tipos onde é mais relevante).
Duas bases, tabela `busca_dicionario` (mesma estrutura, `database/264_busca_dicionario.sql`): **comum** em `guniversal_dbp` (todas as
empresas; só o ADMINISTRADOR altera) e **da empresa** (banco do cliente). `vw_busca_dicionario` junta as duas. Só os **APROVADOS** valem.
- Pesquisa (`sp_busca_termos`): cada termo vira um grupo de alternativas — o texto digitado (e sem acento) + termo, significado e sinônimos
  do dicionário em que o texto é termo, significado ou sinônimo (a frase inteira também: "atestado de saude ocupacional" = ASO). O item
  precisa ter alguma alternativa de cada grupo (sigla com borda de palavra: "ASO" não acha "caso"). Ordem: contexto do termo (+1000),
  termo visível no nome / título / descrição (+100), relevância do FULLTEXT. Aviso na tela: "Resultados também para ASO (Atestado de Saúde
  Ocupacional) · Pesquisar só o texto 'aso'" (parâmetro `literal=1`: sem dicionário).
- Carga inicial: ~60 siglas de SST, meio ambiente, bombeiros e gestão no universal como **SUGESTAO** (origem SETOR).
- Serviço de indexação (`sp_busca_dicionario_sugerir`, a cada execução): pares "SIGLA - descrição" / "SIGLA: descrição" e
  "descrição (SIGLA)" dos cadastros, com a sigla começando pela letra da descrição, viram **SUGESTAO** (origem DADOS) no dicionário da
  empresa se o termo ainda não existe em nenhuma base (aprovados e rejeitados nunca são alterados). Saída do serviço: `sugestoes_dicionario`.
- Tela `principal/busca_dicionario.php` (menu BUSCA_DICIONARIO, Administração; ADMINISTRADOR e ADM_DA_EMPRESA): filtros Situação / Base /
  texto, seleção em lote (Aprovar, Rejeitar, Excluir), Novo termo e edição (duplo clique). API `principal/api/busca_dicionario.php`
  (CSRF; base UNIVERSAL pela conexão GUNIVERSAL). Atalho (ícone de livro) na tela de pesquisa para quem tem acesso.
- Pendente (fase seguinte): registro das pesquisas sem resultado para sugerir termos.

## Tela
Lupa no cabeçalho abre a aba (vazia). Pesquisar / **Avançada** (registros ou arquivos, validade até, ordenação) / **Limpar**, caixas dos filtros,
**Refinar por** (tipo documental, formato, situação, unidade, com contagens), grid no padrão da aplicação (ordenação clicando no título da
coluna, feita pelo banco; largura arrastando a borda, guardada no navegador), **Expandir tudo / Recolher tudo**, menu de contexto (⋮ ou botão
direito): **Abrir**, **Visualizar**, **Arquivos do registro**, **Download**, **Propriedades**, **Copiar link**, **Copiar nome**; duplo clique = Abrir.
API: `principal/api/busca_documentos.php` → `sp_busca_pesquisar_negocios` (`sp_busca_pesquisar` continua disponível: lista simples de itens).

## Responsável, trecho encontrado e exportação
- **Responsável** (coluna do grid, ordenável, e no Excel): licenças (RESPONSAVEL), controles (RESPONSAVEIS) e Portaria vêm da view do grid;
  requisitos, treinamentos e infraestrutura vêm das telas de Responsabilidades (atribuições ATIVAS) — `vw_busca_fonte_responsaveis`,
  montada pela migração com as views `vw_responsabilidade_*` que existem no banco (marcador {{RESPONSAVEIS}}). No negócio da Portaria:
  os responsáveis dos itens encontrados.
- **Trecho**: quando a palavra não está no nome / título / descrição (está no padrão, observação, palavras-chave...), o grid mostra o
  trecho do texto onde ela aparece (coluna Título, em itálico, com a palavra destacada).
- **Excel** (processo padrão das exportações): botão na barra da pesquisa, só com **Exportar Excel** na transação BUSCA_DOCUMENTOS
  (perfil; ADMINISTRADOR sempre). `api/busca_documentos.php?action=exportar` grava o pedido em `exportacoes_grid` (transação
  `busca_documentos`, SOLICITADA; em `filtros`: termo, filtros, ordem e as permissões do usuário no momento do pedido) e o alerta
  "Exportação iniciada". O serviço **GreenPortal-ExportWorker** (Agendador de Tarefas, a cada minuto) roda `ExportacaoGridWorker`, que
  para essa transação lê `sp_busca_pesquisar` em lotes de 1000 + `sp_busca_vinculos_texto` e gera a planilha com tudo o que a pesquisa
  mostra: negócio, nome e detalhe do negócio, tipo, item, nome, título, trecho encontrado, formato, situação, unidade, responsável,
  validade, modificado, vínculos (por relação, com os nomes), código e link; e envia o alerta "Exportação concluída" com o link.

## Diagnóstico de cobertura
`scripts/diagnostico-busca-cobertura.php` (somente leitura): ligações Requisito legal ↔ evidência que não entraram (legislação fora
dos Requisitos legais, requisito em outra unidade, evidência fora da tela) e arquivos do GED sem cadastro (registro excluído ou fora
da tela), com exemplos.

## Instalação
```
E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-264-busca-documentos.php --simular
E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-264-busca-documentos.php --indexar
powershell -ExecutionPolicy Bypass -File E:\wamp\apps\greenportal\scripts\criar-agendamento-busca.ps1 -ExecutarAgora   (como Administrador)
E:\wamp\bin\php\php8.4.15\php.exe scripts\testar-busca-documentos.php
```
