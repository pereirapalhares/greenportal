# Homologação de Fornecedores — comparação de resultados legado × greenportal (admin, empresa MODELO)

Pedido do usuário (17/10/2026): toda operação que o admin executa no legado deve ter, no greenportal, resultado igual ou melhor (não precisa ser igual operacionalmente). Referência: empresa MODELO nos dois sistemas (dados de teste; pode gravar/excluir). Comparação por similaridade, sem igualar os dados.
Fora: Avaliação de desempenho/SAP; geração antiga de critérios (inclui avaliação documental/sistêmica). Dentro: etapa 6 (palavras-chave nos tipos).
Legenda: ✅ igual · ⭐ melhor no greenportal · ⚠️ diferente (motivo) · ❌ falta · ⏳ a verificar

Ambiente do teste: legado = greensolutions.com.br (produção), MODELO, admin. Greenportal = 187.191.99.180/greenportal, MODELO (gmodelo_dbp), admin.
Fornecedor de teste: código 659 nos dois ("TESTE COMPARACAO LEGADO" / "TESTE COMPARACAO PORTAL"), tipo Calibração, documentos 13188–13193.

Decisões do usuário (17/10):
- O GED do legado é o mesmo do greenportal (MODELO: E:\projetoGreen\GED\green_modelo); os anexos usam as mesmas pastas.
- A base do greenportal não precisa estar 100% sincronizada com o legado — registrar para a virada.
- Números do dashboard do legado podem ser desconsiderados: os painéis do greenportal são a referência.

## Matriz

| # | Área | Operação no legado | Greenportal | Situação |
|---|---|---|---|---|
| 1 | Cadastros | Tipos de fornecedor (incluir/alterar/excluir, necessita auditoria) | Cadastros › Tipos de fornecedores | ✅ 53 tipos nos dois. ⭐ greenportal grava "Necessita auditoria" já na inclusão e mostra contadores. ⭐ Exclusão: greenportal tira os vínculos junto e bloqueia tipo em uso; **o legado deixa os vínculos órfãos** |
| 2 | Cadastros | Tipo × critério | Tipos de fornecedores › Critérios | ✅ mesmo catálogo (188 critérios) |
| 3 | Cadastros | Nível de exigência por tipo × critério | Critérios (nível) | ✅; ⭐ nível junto ao vincular e em lote |
| 4 | Cadastros | Palavras-chave do tipo de fornecedor (etapa 6) | Tipos de fornecedores › Palavras-chave | ✅ mesmo catálogo (875) |
| 5 | Cadastros | Critérios de fornecedor (PT/EN) | Cadastros › Critérios dos fornecedores | ✅ 188; ⭐ colunas Tipos e Em uso |
| 6 | Cadastros | Tipos de funcionário + Critérios, Treinamentos, Palavras-chave | Cadastros › Tipos de funcionários | ✅ 128 tipos; tipo 1 (Ajudante de Carga): 9 critérios e 5 treinamentos iguais. Obs.: no greenportal o tipo 1 ainda tem vínculos órfãos (3 critérios e 1 treinamento que não existem no cadastro), vindos da carga — ignorados na geração desde a c415 |
| 7 | Cadastros | Critérios de funcionário | Cadastros › Critérios dos funcionários | ✅ 28; ⭐ filtro por tipo de documento e Em uso |
| 8 | Cadastros | Tipos de treinamento | Cadastros › Tipos de treinamento | ✅ 14; ⭐ Em uso |
| 9 | Fornecedor | Dados básicos (incluir) | Gestão de Fornecedores | ✅; ⭐ ranking e bloqueio |
| 10 | Fornecedor | Vincular tipos | Tipos do fornecedor | ✅; ⭐ gera para todos os tipos. c413: contadores corrigidos |
| 11 | Fornecedor | Contatos | aba Contatos | ✅ após c408 |
| 12 | Fornecedor | Certificados + anexo | aba Certificados | ✅ dados + anexos no GED (c409); upload ainda não testado |
| 13 | Fornecedor | Auditoria por tipo | aba Auditoria | ✅; ⏳ Cronograma (legado abre ev_schedule) |
| 14 | Documentos | Geração das linhas | Documentos | ✅ (legado só ao abrir a aba e só o 1º tipo; greenportal por gatilho) |
| 15 | Documentos | Editar situação, validade, reação, contrato, observações, responsável | Documentos | ✅ |
| 15b | Documentos | Protocolo Sim/Renovar | Documentos | ✅ migrações 300/302 aplicadas; ⭐ serviço diário com alerta |
| 15c | Documentos | Situação automática (enviadoodocumento.php) | homologacao-documentos-worker.php (migração 301) | ✅ aplicado (ver claude/regras-automaticas-documentos-legado.md) |
| 16 | Documentos | Reprovar exige observação | Documentos | ✅ |
| 17 | Documentos | Copiar/colar | Documentos | ⭐ legado falha com erro SQL |
| 18 | Documentos | Excluir (reset) | Reiniciar | ⭐ |
| 19 | Documentos | Histórico | Histórico | ✅ |
| 20 | Documentos | Evidências (GED) | Documentos | ⏳ |
| 21 | Fornecedor | Funcionários (aba) | aba Funcionários | ✅ após c408 |
| 22–24 | Fornecedor | Químicos/FDS, Equipamentos, Plano de ação + arquivos | abas | ✅ dados + GED (c409) |
| 25 | Fornecedor | Excel "Fornecedores + Critérios" (legado: por e-mail) | Painel › Exportar Excel (fila, alerta com link) | ✅/⭐ mesmo conteúdo; colunas a conferir |
| 26 | Fornecedor | Desativar/reativar; desativados | Gestão de Fornecedores / desativados | ✅ testado com o 659 nos dois |
| 27 | Fornecedor | Usuários do fornecedor (legado: só pela tela do usuário) | Gestão de Fornecedores › Usuários | ✅; ⭐ pelo fornecedor. c413: contadores corrigidos |
| 28 | Funcionários | Critérios do funcionário | Gestão de Funcionários › Critérios | ✅ após migração 303 (c415 reaplicada em 17/10): itens gerados pelo tipo (gatilhos + ao abrir a tela), só de critérios existentes no cadastro. Testado com funcionário novo 1230: critério ASO gravado (Sim, 31/12/2026) |
| 29 | Funcionários | Treinamentos do funcionário | Gestão de Funcionários › Treinamentos | ✅ após 303 + **c416: a tela não gravava nenhuma alteração** (coluna "Dias antes da validade" era apelido da view; agora só leitura, como no legado, onde é calculada). Testado: treinamento de EPI gravado (Sim, 31/12/2026) |
| 30 | Funcionários | Excluir funcionário | Excluir | ⚠️ igual nos dois: apaga só o funcionário; itens ficam órfãos. Greenportal: --limpar remove os itens em branco; os preenchidos ficam (como no legado). Proposta pendente: bloquear exclusão com documentos e usar "Desligado" |
| 31 | Acompanhamento | Pendências na Home | Home › Pendências | ⏳ |
| 32 | Acompanhamento | Dashboard | Painel de Homologação | ⭐ decisão do usuário |
| 33–35 | Acompanhamento | Estatísticas, relatórios gerenciais, relatório unificado | Painel / Relatórios | ⏳ |
| 36 | Portaria | Documentação fornecedor/funcionário | Portaria | ⏳ |
| 37 | NC | Ocorrências por fornecedor | ⏳ | ⏳ |

## Regra do protocolo (migrações 300 e 302 — aplicadas em 17/10)
- Em protocolo = Sim/Renovar → "Aguardando vencimento do protocolo", cobrança +30 dias; tirar do protocolo → "Pendente com o fornecedor".
- Serviço diário (06:40): na cobrança volta para "Pendente com o fornecedor", encerra o protocolo, histórico e alerta.
- 302: protocolo sem cobrança → aguardando (2 na MODELO, 2 na MODELO2, cobrança 16/11/2026). Protocolos com cobrança vencida seguem as regras 301 (c412).

## Números (17/10)
- Dashboard do legado desconsiderado (decisão do usuário).
- Fornecedores: legado 428 ativos / 165 desativados; greenportal 370 / 148 (base não sincronizada).
- Migração 303 (c415 reaplicada com --limpar em 17/10). Itens em branco órfãos removidos: MODELO 12 critérios / 9 treinamentos; Atlas 0 / 1.279; MODELO2 1 / 4. Faltam nos funcionários ativos: MODELO 1.968 critérios / 1.160 treinamentos (antes 4.046 / 1.849, contagem que incluía órfãos); MODELO2 1.968 / 1.160; Atlas 158 / 4 (antes 158 / 43). Não gerados em massa (só ao abrir a tela ou com --gerar-todos).

## Para a virada (registrar)
- **Base não sincronizada:** carga final na virada.
- **Códigos iguais nos dois sistemas:** fornecedor 659, documentos 13188–13193, funcionário 1229, critério de funcionário 9819 e treinamentos 4135–4139 receberam os mesmos códigos nos dois sistemas para registros diferentes. Decidir faixa de códigos ou mapeamento. Tipos de fornecedor novos no greenportal já usam faixa própria (100000).
- **GED compartilhado:** a mesma colisão vale para as pastas de anexos.
- **Órfãos:** legado — vínculos de tipos excluídos e itens de funcionários excluídos; greenportal — vínculos tipo de funcionário × critério/treinamento inexistente vindos da carga. Na carga, ignorar registros cujo pai não existe (ou rodar a 303 com --limpar depois da carga).
- **GED com os arquivos:** as regras 301 dependem dos arquivos no GED.

## Correções feitas durante a comparação
- c406–c407: diagnóstico. c408: código automático nas abas; aviso de código; auditoria com opção vazia.
- c409 (300): protocolo; anexos GED; Necessita auditoria; copiar/colar; consulta Em protocolo.
- c410/c412 (301): regras automáticas; c411 (302): protocolo sem cobrança.
- c413: contadores Usuários/Tipos do fornecedor.
- c414 (303): itens do funcionário pelo tipo. c415 (reaplicada): só itens existentes no cadastro + sp_fo_funcionario_itens_limpar (--limpar). c416: tela Treinamentos do funcionário volta a gravar.

## Testes de gravação (17/10) — já desfeitos
- Tipos de fornecedor (legado 100 / greenportal 100000) excluídos.
- Fornecedor 659 desativado e reativado nos dois; usuário ligado e desligado nos dois.
- Funcionário: legado 1229 (ASO 9819 e treinamento 4137) excluído — órfãos 9819 e 4135–4139 ficaram no legado. Greenportal 1229 excluído; 1230 criado (gerou itens), ASO e EPI gravados, excluído — itens em branco removidos pelo --limpar; os 2 preenchidos ficaram órfãos (como no legado).

## Pendente
- Decidir se roda a 303 com --gerar-todos (o serviço 301 marcará os itens novos sem arquivo como "Pendente com o fornecedor").
- Decidir: bloquear exclusão de funcionário com documentos (usar Desligado).
- Auditoria: cronograma. Continuar: pendências da Home, relatórios/estatísticas, Portaria, NC, evidências do documento.
