# Homologação de Fornecedores — comparação legado × greenportal na Crown (18/10/2026)

Pedido do usuário: continuar a comparação do módulo usando a Crown (admin, legado greensolutions.com.br e greenportal). Complementa `claude/homologacao-comparacao-legado-greenportal.md` (MODELO).
Ferramentas (somente leitura): scripts/diagnostico-homologacao-empresa.php e scripts/diagnostico-homologacao-diferencas.php [--banco=gcrown_dbp].

## Telas
- Greenportal (Crown): as 13 telas do módulo abrem sem erro — Painel, Gestão de Fornecedores, Fornecedores desativados, Gestão de Funcionários, Portaria (fornecedor/funcionário), Histórico de documentos, Tipos/Critérios de fornecedores, Tipos/Critérios de funcionários, Tipos de treinamento, Ranking.
- Legado (Crown): Cadastro de Critérios de Funcionários; Cadastro de Fornecedor (Critérios, Tipos, Dados Básicos — sem Nível Criticidade/Avaliação); Gestão de Fornecedores; Gestão de Funcionários; desativados; Relatórios Gerenciais (Dashboard, Gerenciamento de Integração / Docs Funcionários / Treinamento / Auditoria / Docs Fornecedor / Produtos Químicos / Equipamentos, Relatório unificado de funcionários, Histórico); Estatísticas. Home própria da Crown (home_crown.php): abas Dashboard, NC/Plano, Requisitos/Atendimento, Documentos Licenciatórios, Documentação de Fornecedores (fo_auditoriacriterios_pendencias_documentos_crown), Documentação de Funcionários.
- Gestão de Fornecedores: legado mostra 627 linhas para 541 fornecedores (repete o fornecedor por tipo — bug conhecido) e ativos+desativados juntos (admin Crown); greenportal separa ativos e desativados e tem colunas a mais (Código, Faixa/Nota do ranking, Bloqueado, Motivo). Mesmas 8 abas.
- Não abertos no legado (gravam ao abrir): aba Documentos e pendência de fornecedores da Home.

## Dados (legado → greenportal)
| Item | Legado | Greenportal | Situação |
|---|---|---|---|
| Fornecedores ativos / desativados | 390 / 151 | 390 / 151 (telas idem) | ✅ nenhum só de um lado |
| Tipos / critérios de fornecedor | 55 / 188 | 55 / 188 | ✅ |
| Tipo × critério (nível) | 660 | 654 | ✅ 6 repetidos no legado |
| Fornecedor × tipo (auditoria) | 717 | 717 | ✅ |
| Documentos do fornecedor | 9.284 | 8.926 (tela 5.962) | ✅ 358 repetidos no legado; tela = mesmo filtro da aba Documentos do legado (tipo no fornecedor e critério no tipo); 1.170 de fornecedor inexistente; 1.037 de fornecedores ativos com tipo/critério retirado (o legado apaga esses ao abrir a aba; o greenportal guarda) |
| Valores dos documentos (mesma chave) | — | — | ✅ nenhuma diferença |
| Histórico | 1.064.028 | 1.063.986 | ✅ (42 conhecidos) |
| Usuários do fornecedor | 1.181 | 1.141 | ✅ 20 pares repetidos e 62 sem pai no legado; nenhum par faltando |
| Contatos / certificados / plano | 14 / 2 / 1 | iguais | ✅ |
| Funcionários | 1.110 | 1.110 (tela 1.080) | ✅ 30 de fornecedor inexistente |
| Tipos func. / critérios func. / treinamentos | 128 / 28 / 14 | iguais | ✅ |
| Tipo func. × critério / × treinamento | 1.364 / 234 | 1.284 / 212 | ✅ diferença = repetidos e sem pai no legado; nenhum par faltando |
| Documentos de funcionário | 9.813 | 9.813 (Portaria 9.404) | ✅ valores iguais; 409 de funcionário/fornecedor inexistente |
| Treinamentos de funcionário | 949 | 949 | ✅ |

## Home — pendências (c451 + c452, migração 310)
- Legado Crown: "Documentação de Fornecedores" = documentos com responsável = usuário e situação "Pendente com a empresa"; "Documentação de Funcionários" = documentos de funcionário com responsável = usuário, fornecedor não desativado.
- Greenportal antes: documentos do responsável em qualquer situação (inclusive aprovados e de desativados) e nenhum documento de funcionário.
- Decisões do usuário (18/10):
  - documentos do fornecedor: só os que pedem ação da empresa (fornecedor ativo, visível na aba Documentos, "Pendente com a empresa", sem situação ou aprovado vencido/vencendo nos dias de reação — 30 se não informado);
  - documentos de funcionário: incluir (fornecedor ativo, sem Desligado/Afastado/NAP), com link para a Gestão de Funcionários;
  - 2ª decisão (após ver 3.834 linhas para 2 responsáveis na Crown): funcionário só os que pedem ação — Atende = Não ou vazio, ou Sim vencido/vencendo em 30 dias (mesma regra de cores do Painel). Feito no c452 (mesma migração 310, CREATE OR REPLACE).
- Views vw_homologacao_pendencias_documento_interno e vw_homologacao_pendencias_funcionario_interno; principal/home_pendencias.php usa as views (sp_crud_select).

| Banco | Docs fornecedor (responsáveis) | Docs funcionário — 1ª aplicação | Docs funcionário — após c452 |
|---|---|---|---|
| gcrown_dbp | 102 (7) | 3.834 (2) | 877 (2) |
| gatlas_dbp | 47 (12) | 1.232 (30) | 283 (23) |
| gmodelo_dbp / gmodelo2_dbp | 10 (4) | 3.848 (2) | 3.848 (2) — sem queda: provavelmente dados de teste com Atende vazio/Não (não verificado) |
| gganchieta, ggdamericana, gluxottica | 0 | 0 | 0 |

## Pendente
- Conferir na Home de um responsável da Crown (877 docs de funcionário em 2 responsáveis).
- Relatórios Gerenciais e Estatísticas do legado × Painel de Homologação (a janela do legado no Chrome estava pequena demais para ver).
- Relatório unificado de funcionários: conferir se o Painel cobre.
