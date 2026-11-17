# Homologação de Fornecedores — etapas implementadas no greenportal

Complementa `claude/analise-homologacao-fornecedores-legado.md` (engenharia reversa). Esta sessão é a dona do módulo.

| Etapa | Situação | Migrações / commit |
|---|---|---|
| 1. Tipos e Critérios dos Fornecedores + nível de exigência | Aplicada (menus 900125/900126) | 292 (c385) |
| 2. Aba Documentos completa | Aplicada | 293, 294 (c386–c388) |
| 3. Histórico de alterações de documentos | Aplicada (menu 900127) | 295 (c389–c390) |
| 4. Fornecedores desativados + fornecedor externo | Aplicada e testada 17/10/2026 (menu 900128) | 296, 297 (c391–c396) |
| 5. Painel de Homologação (relatórios, estatísticas, dashboard) + regra única de cores | Aplicada e testada 17/10/2026 (menu 900129) | 298 (c397) |
| Revisão: exportação para o Excel só pela fila do Agendador do Windows (toda a aplicação) | Aplicada e testada 17/10/2026 | c398–c399 |
| Organização do menu do módulo | Aplicada e conferida 17/10/2026 (grupos 900130/900131) | 299 (c400) |
| Ficha do fornecedor como página própria (popup/iframe) + índices por fornecedor | Aplicada 19/10/2026 | 313 (c455) |
| 6. Avaliação documental/sistêmica antiga + palavras-chave em tipos de funcionário | Pendente | — |

Fora do escopo: Avaliação de Desempenho/SAP. Virada legado → novo: não tratar agora. FORNECEDOR_GESTOR: sem necessidade de teste (decisão do usuário).

## Menu do módulo (migração 299, aprovada pelo usuário em 17/10)

```
Homologação de Fornecedores
  Painel de Homologação · Gestão de Fornecedores · Fornecedores desativados · Gestão de Funcionários
  Portaria ▸ Documentação de fornecedor · Documentação de funcionário
  Relatórios (HOMOLOGACAO_RELATORIOS, 900130) ▸ Ranking de fornecedores · Histórico de alterações de documentos
  Cadastros (HOMOLOGACAO_CADASTROS, 900131) ▸ Tipos de fornecedores · Critérios dos fornecedores · Tipos de funcionários ·
             Critérios dos funcionários · Tipos de treinamento · Pesos do ranking (ADMINISTRACAO_PESOS, saiu da Administração)
```
- Script `scripts/aplicar-migracao-299-menu-homologacao.php` (universal + todo banco de cliente com o menu raiz): só pai, ordem, nível, nomes PT/EN/ES e ícones. Permissões são pelo código do menu e não mudaram; grupos sem permissão própria (aparecem quando há item visível). Corrigiu também os nomes com "?" do gmodelo2.
- Para novas telas do módulo: colocar no grupo certo (relatório → HOMOLOGACAO_RELATORIOS; cadastro → HOMOLOGACAO_CADASTROS), nível 3, ordem de 10 em 10.
- Crown, Goodyear Anchieta, Goodyear Americana e Luxottica não têm as telas das migrações 292–298 (só foram aplicadas em gmodelo, gatlas, gmodelo2); nesses bancos a 299 reorganizou só o que existe.
- Conferido no localhost como admin (Atlas). Fornecedor externo não testado (a senha de teste da Beatriz não estava disponível); pela regra, não vê Relatórios nem Cadastros (telas em MENUS_SO_EMPRESA).

## Padrão de exportação para o Excel (c398–c399)

Regra do usuário: toda exportação para o Excel usa o serviço de exportação do Agendador do Windows. O usuário pede → alerta "Exportação iniciada" (com link de acompanhamento) → o GreenPortal-ExportWorker (`scripts/export-worker.php` → `classes/ExportacaoGridWorker.php`) gera o .xlsx → alerta "Exportação concluída" com o link do arquivo (ou "Erro na exportação"). Nenhuma tela gera planilha nem inicia processo.

- Classe única do pedido: `classes/ExportacaoSolicitacao.php` (`solicitar(pdo, usuario, transacao, tipo, [filtros, busca, colunas])` grava em `exportacoes_grid` e cria o alerta de início com a hora do banco; `mensagem()` devolve o texto padrão).
- Corrigidas para o padrão: Painel de Homologação (era CSV direto), Documentos do fornecedor (era CSV gerado no navegador), Log de migração / órfãos (era CSV direto), Segurança (Usuários, Grupos, Perfis, Permissões, Listas, Formulário), Setup / Menu, Legislação, Legislação x Empresas, Legislação x Poder (sem alerta de início ou alerta sem link).
- Já estavam no padrão: grids de `ManutencaoLegalCadastroGrid`, Requisitos legais, Relatório de Atendimento Legal, Pesquisa de documentos.
- Geradores especiais no worker: `HomologacaoPainelExportacao` (usa `HomologacaoPainelConsultas.php`, as mesmas regras da tela), `HomologacaoDocumentosExportacao` (documentos visíveis na ordem da tela, relidos da view), `MigracaoOrfaosExportacao`.
- c399: `scripts/export-worker.php` passava ao worker só `$_ENV` (vazio no Windows) e todas as exportações falhavam com MySQL 2002 desde 16/10; agora passa `getenv()`.
- Testado no localhost (admin, Atlas): Painel (2.599 linhas), Documentos do 405 (6 linhas), Cadastro de grupos — alertas de início e de conclusão com link. Log de migração não testado (precisa de uma migração com registros órfãos).
- Fica fora (não é Excel): PDF de impressão do painel, downloads de arquivos do GED, PDFs de legislação/não conformidade.

## Etapa 5 — Painel de Homologação (migração 298)

Protótipo aprovado pelo usuário (canvas "Painel de Homologação — protótipo", 17/10). Tela `principal/homologacaofornecedores_painel.php`, menu `HOMOLOGACAO_PAINEL` (codmenu 900129).

**Regra única de cores** (`vw_homologacao_painel_itens`): VERMELHO reprovado/não atende, vencido, pendente com o fornecedor; AMARELO pendente com a empresa ou vence nos dias de reação (30 se não informado); VERDE aprovado/sem validade/ciente/atende; NEUTRO (cinza) NA, não enviar, funcionário desligado/afastado; BRANCO sem situação. "% atendido" = verdes ÷ (total − cinzas). Só fornecedores ativos.

**Itens acompanhados**: DOCUMENTO, AUDITORIA (tipos que exigem), NIVEL (nível de serviço / próxima avaliação), INTEGRACAO, DOCFUNC, TREINAMENTO (reação fixa 30: o campo do legado é sobrescrito), EQUIPAMENTO, QUIMICO, PLANO (status %).

**Tela**: filtros (fornecedor/CNPJ, unidade, tipo, responsável Eu/Sem, vencimento; "Salvar filtro" em localStorage); 8 números clicáveis; 16 consultas prontas (inclui treinamentos, equipamentos, químicos, plano); lista paginada (50) ordenada por gravidade; gráficos clicáveis (situação, 12 semanas, top fornecedores, unidade × situação); Ficha do fornecedor (ver abaixo).
- Exportar Excel (lista ou todo o filtro): pedido na fila do Agendador do Windows, planilha nos alertas (c398). PDF: impressão do navegador com CSS de impressão.
- Cobrar responsáveis: alertas em Home › Alertas (sp_alerta_criar) para os responsáveis dos itens da lista. Só empresa com permissão editar.
- Resumo semanal: `scripts/homologacao-resumo-worker.php` pelo Agendador de Tarefas (`\GreenSolutions\GreenPortal-ResumoHomologacao`, segundas 07:10; `scripts/criar-agendamento-resumo-homologacao.ps1`), alerta por responsável, uma vez por semana ISO (`fo_painel_resumo_execucoes`); cada usuário liga/desliga no botão (`fo_painel_resumo_preferencias`). **Sem e-mail**: o portal não tem SMTP configurado.
- Fornecedor externo: só os fornecedores ligados a ele, sem filtro de tipo, sem ranking, sem cobrança.
- Dados: `CALL sp_fo_painel_itens(usuario, externo, unidade, tipo, busca, responsavel, vencimento, fornecedor)`; agregação em PHP (`classes/HomologacaoPainelConsultas.php`, compartilhada com a exportação).
- Permissões (247): ADM_DA_EMPRESA, HOMOLOGACAO → visualizar, editar, exportar; FORNECEDOR, FORNECEDOR_GESTOR → visualizar, exportar.

**Resultado da aplicação (17/10)**: Atlas 2.599 itens, painel sem filtro 83 ms; modelo/modelo2 10.829 itens, 800 ms.
Atlas (admin): 147 ativos / 26 desativados; atendidos 12% (63 de 536); vencidos 57; vencem nos dias de reação 16; pendentes com o fornecedor 284 (80 fornecedores); pendentes com a empresa 44; funcionários com pendência 221 (261 documentos); sem responsável 409; nível de serviço atrasado 42; treinamentos 92.

**Teste no localhost (17/10)**: admin — página, números, consultas, gráficos, ficha (ranking B 72,6; 6 alterações), cobrar (consulta integração, 0 responsáveis), liga/desliga resumo. Beatriz (FORNECEDOR) — só os 4 fornecedores dela, selo "Somente os seus fornecedores", sem tipo/cobrança, ficha do 527 OK, ficha do 405 403, cobrar 403, exportar OK.

**Observações**: token inválido devolve 419, que o Apache local transforma em 500 (a mensagem chega certa); "Sem responsável" é alto na Atlas (409 documentos).

### Ficha do fornecedor (c455, migração 313, 19/10/2026)
- Pedido do usuário: abrir em popup, mas com a ficha resolvida numa página própria em iframe. Página `principal/homologacaofornecedores_ficha.php?CODFORNECEDOR=N` (montada no servidor; abas só alternam; Abrir documentos em outro popup; Imprimir só a ficha, todas as categorias). Permissão HOMOLOGACAO_PAINEL; externo só os seus. O Painel abre em `GreenPortalPopups.transacaoIndependente` (amplo); o modal e a ação JSON `?acao=ficha` saíram (homologacao-painel.js v3).
- Lentidão encontrada: o histórico (`vw_homologacao_fornecedor_documentos_historico`, ~1 milhão de linhas na Crown) sem índice por fornecedor e lido com 1.000 linhas por chamada (MigracaoCrud) → **8,4 s**. Agora cada leitura pede só o que mostra (fornecedor 1, histórico 6), a sessão é liberada logo e não há checagem de procedures.
- Migração 313 (`scripts/aplicar-migracao-313-indices-ficha-fornecedor.php`): índices por fornecedor/funcionário onde faltavam (histórico (codfornecedor, DATAALTERACAO, CODHISTORICO), fornecedor×tipo, funcionários, treinamentos de funcionário, plano; fo_fornecedorxusuario na Luxottica). Aplicada nos 7 bancos (histórico da Crown em 3,7 s). O sincronismo não recria tabelas, os índices ficam.
- Medido na Crown (fornecedor 474, 269 itens), `scripts/diagnostico-ficha-fornecedor.php`: antes ~8,5 s (histórico 8.419 ms); depois ~50 ms frio / ~23 ms quente (fornecedor 22, itens 25, histórico 4, usuários 1). Painel inteiro sem filtro: 922 → 676 ms frio, 884 → 376 ms quente.
- Tempos de cada etapa no cabeçalho Server-Timing; `&diag=1` mostra no rodapé.

## Etapa 4 — o que foi feito (migrações 296 e 297)

**Desativados**
- Gestão de Fornecedores lê `vw_homologacao_fornecedores_ativos_grid` (Ativado? = Sim).
- Tela `homologacaofornecedores_fornecedoresdesativados.php`, menu `HOMOLOGACAO_FORNECEDORES_DESATIVADOS` (codmenu 900128), sobre `vw_homologacao_fornecedores_desativados_grid` (Não ou vazio; vazio aparece em branco).
- Gatilho `trg_fo_fornecedores_ativo_bi`: fornecedor novo sem Ativado? entra como Sim.

**Fornecedor externo (perfis FORNECEDOR e FORNECEDOR_GESTOR)**
- Classe `HomologacaoFornecedorAcesso` + `sp_fo_acesso_registro(usuario, tipo, id)`: só fornecedores **ativos** ligados ao usuário em `fo_fornecedorxusuario`.
- Gestão de Fornecedores: `vw_homologacao_fornecedores_usuario_grid`, só consulta. Gestão de Funcionários (297): `vw_homologacao_funcionarios_usuario_grid`, gravação conferida no servidor, FORNECEDOR não exclui.
- FORNECEDOR: sem Certificados, Auditoria, Químicos, Equipamentos e Plano; nos Documentos grava só campos do fornecedor. FORNECEDOR_GESTOR edita os documentos como a empresa.
- Telas só da empresa (`MENUS_SO_EMPRESA`): Ranking, cadastros, histórico, desativados, Portaria — negadas em `PermissaoTransacao` e retiradas da sessão em `UsuarioSessao`.
- GED pelo vínculo; popup "Usuários do fornecedor" (só empresa); Home: pendências de fornecedor externo e só de telas permitidas (c393–c394).
- `fo_fornecedorxusuario` em SINC_MESTRE_NOVO.

**Teste (17/10, beatriz.rocha)**: tudo restrito aos fornecedores 524, 527, 528, 573; pendência CIPA (6140) na Home; Beatriz colocada como responsável do fornecedor nos documentos 6140 e 6110 do 527.

**Pontos em aberto**
- Perfil FORNECEDOR (Atlas) tem "Excluir" na Gestão de Fornecedores (vem do legado): recomendado desmarcar.
- Função do funcionário lista todas as funções (nomes trazem unidade e fornecedor).
- Vínculos órfãos em `fo_fornecedorxusuario` não aparecem nas telas.
- API de respostas do questionário não revisada para o externo.
- Restrição por unidade do usuário interno (legado: usuariosxunidades) não aplicada.
- Envio de e-mail (resumo/cobrança) depende de configurar SMTP.
- Crown, Goodyear e Luxottica sem as migrações 292–298 do módulo (a confirmar: na comparação de 18/10 as telas abriram na Crown).
