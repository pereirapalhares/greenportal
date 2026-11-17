# Homologação de Fornecedores — Relatórios gerenciais e Estatísticas (legado × novo)

Pedido do usuário (19/10/2026): começar pelo item 1 da análise (relatórios gerenciais, estatísticas, relatório unificado) e **melhorar**: visão muito mais moderna e prática para o usuário do greenportal.

## O que o legado tem (MODELO, admin, lido pelo Chrome em 19/10 — só leitura)

Menu Homologação Fornecedores › **Relatórios Gerenciais Homologação** (grids dhtmlx/grid_tabulacao só com Recarregar, Exportar e "Excel por e-mail"; sem gráfico nem indicador):

| Relatório (transação) | Colunas | Linhas (MODELO) |
|---|---|---|
| Dashboard (greennewd) | — (substituído pelo Painel de Homologação, decisão do usuário) | — |
| Gerenciamento de Integração (fo_relatorio_integracao) | Unidade, Razão social, Nome fantasia, Funcionário, Setor, Data de realização da integração, Nº dias para reação | 1.024 |
| Documentos exigidos funcionários (fo_relatorio_documentacao_exigida_func) | Unidade, Razão social, Fantasia, Funcionário, Tipo de funcionário, Documento, Atende?, Validade, Dias de reação, Tipo de documento | 4.740 |
| Treinamento (fo_relatorio_treinamento) | Unidade, Razão social, Fantasia, Funcionário, Tipo de treinamento, Nome, Realizado?, Validade, Dias de reação | 1.454 |
| Auditoria (fo_relatorio_auditoria) | Unidade, Razão social, Fantasia, Tipo, Necessita auditoria?, Nota (0 a 100), Validade, Dias de reação, Aprovado?, Evidência | 1 |
| Documentos exigidos fornecedor (foRelatorioDocumentosexigidosFornecedor) | Unidade, Razão social, Fantasia, Tipo, Documento, Nível, Enviado?, Validade, Em protocolo?, Reação, Situação, No contrato?, Validador, Usuário fornecedor, Observação | 1.825 |
| Plano de ação (fo_relatorio_planodeacao) | Razão social, Fantasia, CNPJ, Ação, Responsável, Início, Fim, Recursos, Status, Arquivos | 1 |
| Produtos químicos (fo_relatorio_produtosquimicos) | Unidade, Razão social, Fantasia, Produto, CAS, Validade, Dias antes, Controlado?, FDS, Característica, Aprovado? | 373 (lista todos os fornecedores, a maioria sem produto) |
| Equipamentos (fo_relatorio_equipamentos) | Unidade, Razão social, Fantasia, Tipo, Identificação, Validade, Dias antes, Anexo, Aprovado? | 373 (idem) |
| Relatório unificado de funcionários (foFuncionariosLista) | Unidade, Empresa terceira, Nº do registro, Colaborador, Setor, Função, Data de início — **só a lista**, sem documentos | 696 |
| Histórico de alterações de documentos (foFornecedorxCriteriosHistorico) | já existe no greenportal (menu 900127) | — |

**Estatísticas** (fo_estatisticas_c): árvore com 12 nós sem números, cada um abre um relatório filtrado: documentação do fornecedor válida/expirada, auditoria válida/expirada, documentação do funcionário válida/expirada, nível de serviço satisfatório/não satisfatório, integração aprovada/necessita nova, documentos do fornecedor aprovados/reprovados.

## Decisões do usuário (19/10)
- **Bloqueado** no unificado = qualquer item exigido vencido, "Não atende" ou não avaliado (sem situação ou sem registro). Vence em breve = algum amarelo. Liberado = tudo verde. Cinza (NAP/desligado/afastado) e itens não exigidos pelo tipo ("—") não contam.
- **Envio semanal** das visões salvas: por alerta em Home › Alertas (sem SMTP), como o resumo do Painel.

## Implementado — c460 / migração 314 (19/10/2026)
Tela **Relatórios de Homologação** — `principal/homologacaofornecedores_relatorios.php`, menu `HOMOLOGACAO_RELATORIOS_GERENCIAIS` (codmenu 900134) dentro de Homologação de Fornecedores › Relatórios (ordem 5, antes do Ranking). Permissões copiadas do HOMOLOGACAO_PAINEL perfil a perfil (só se o menu novo não tiver nenhuma); Anchieta e GD Americana: ADM_DA_EMPRESA liberado pela 247 (Relatórios e Painel). Visualizar = ver e salvar os próprios relatórios; exportar = Excel.

- **Catálogo** à esquerda (Fornecedores: documentos, auditoria, nível de serviço, químicos, equipamentos, plano de ação · Funcionários: documentos, integração, treinamentos, unificado · Rastreabilidade: histórico, abre em popup) com o nº de itens vermelhos (`sp_fo_relatorio_contagens`) e busca.
- Cada relatório: descrição + "Substitui no sistema anterior", 4 números clicáveis, **barra de distribuição** por cor (clicável), **filtros rápidos** (os 12 nós das Estatísticas e mais).
- **Grid padrão**, paginação à esquerda da busca, filtro e ordenação por coluna no servidor, linhas pastel pela regra única, Ficha do fornecedor (popup), **Evidência** = nº de arquivos no GED com link para gestao_arquivos.php.
- **Agrupar por** com subtotais; **Relatório unificado** (matriz, Lista para a portaria); **Meus relatórios** com envio semanal por alerta (`scripts/homologacao-relatorios-worker.php`, tarefa `\GreenSolutions\GreenPortal-RelatoriosHomologacao`, segundas 07:20); **Excel** pela fila (`classes/HomologacaoRelatoriosExportacao.php`).
- Regras únicas em `classes/HomologacaoRelatorios.php`.
- Aplicada em 19/10 nos 6 bancos (Crown: documentos 377 ms, unificado 700 funcionários 293 ms).

## Validação legado × greenportal na Crown (19/10, só leitura no legado)
| Relatório | Legado | Greenportal | Resultado |
|---|---|---|---|
| Integração | 698 funcionários: 583 vencidas, 115 "00/00/0000" | 700: 583 precisam de nova, 117 sem data | ✅ (2 funcionários sem nome) |
| Treinamentos | 801, nenhum com Realizado?/validade | 801 "Sem situação" | ✅ (dados vazios no legado) |
| Auditoria | 1 (VIP Ponta Grossa, nota 0) | 1 pendente | ✅ |
| Químicos / Equipamentos | 390 linhas sem item | 0 | ✅ |
| Documentos de funcionários | 4.733 / 573 func. | 5.832 → **4.732** após a 315 | ✅ após correção |
| Documentos do fornecedor, Unificado, Plano | não carregaram no legado da Crown (tela em branco) | 3.943 / 700 / 0 | não comparados |
| Excel | — | pedido → "Exportação concluída" nos alertas | ✅ |
- Unificado: 700/700 bloqueados = correto (nenhuma integração em dia na Crown). Setor do relatório de integração do legado mostra Sim/Não (bug do legado).

## Migração 315 (c463) — documentos de funcionário com a regra do legado
Diagnóstico `scripts/diagnostico-relatorio-docfunc.php` (regras A–G): **G** = documento do tipo atual do funcionário + critério exigido pelo tipo (fo_tipofuncionariosxcriteriofuncionarios) + critério existente reproduz o legado (Crown 4.732 × 4.733; Não 314, Desligado 1.768, vazio 18 iguais). Aplicada em vw_homologacao_painel_itens (DOCFUNC; database/298), pendências da Home (310/312) e itens exigidos do unificado (314). Nada apagado.
Resultado (19/10): MODELO 5.846→4.739 (legado MODELO 4.740), Atlas 1.284→1.192, MODELO2 5.846→4.739, Crown 5.832→4.732. Colunas sem descrição no unificado: "Documento N"/"Treinamento N".
