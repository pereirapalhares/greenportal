# Análise de conformidade — Portal de Legislação

**Relatório analisado:** `docs/portal_legislacao_requisitos.md`  
**Data da análise:** 19/08/2026  
**Escopo:** avaliação da aplicação Green Portal em relação aos requisitos do relatório.

## Conclusão geral

O Green Portal atende parcialmente aos requisitos. O maior avanço está no dashboard e na base do Módulo Legal. Ainda não estão implementados de forma completa os recursos de IA, potenciais, homologação, responsabilidades por tipo e categoria, telas “Minhas”, delegação e auditoria operacional completa.

## Avaliação por requisito

| Requisito | Situação | Evidência ou divergência |
|---|---|---|
| Cards na Home | Conforme | Existem cards para licenças, minhas licenças, pendências, tarefas e alertas em `principal/dashboard.php`. |
| Indicadores por período | Conforme parcial | Há períodos de 30, 90 e 365 dias, gráfico em anel e tabela de indicadores. |
| Pendências do usuário logado | Parcial | São consideradas situações legais e planos de ação, mas não todas as responsabilidades dos demais módulos. |
| Próximas tarefas em formato schedule | Parcial | Existe agenda no dashboard, limitada a situações legais e planos de ação. |
| Navegação Friendly pela Home | Não conforme | O dashboard é carregado no `main.php`, porém cards e indicadores não navegam para os registros correspondentes. A navegação principal continua ocorrendo pelo menu lateral. |
| Módulo Legal como pivô | Parcial | Existem relacionamentos com licenças, medição/monitoramento, controles, LAIA, LAIS e LPRO. |
| Licenças como módulo independente | Conforme parcial | Existe `principal/manutencaolegal_licencas.php` com grid e CRUD. |
| Medição e monitoramento independente | Parcial | Existem estruturas e telas relacionadas, principalmente em popups de requisitos legais, mas não uma área independente completa equivalente ao requisito. |
| Treinamento | Não evidenciado | Não foram encontrados módulo, tabelas, telas ou relacionamentos específicos de treinamento. |
| Infraestrutura independente | Parcial | Infraestrutura aparece nos relacionamentos do LPRO, mas não como módulo de gestão independente completo. |
| Controle independente | Parcial | Existe `principal/modulolegal_controles.php` e relacionamentos contextuais, porém sem a estrutura completa de responsabilidades prevista. |
| Homologação | Não evidenciado | Não foram localizados módulo de homologação nem relacionamento entre leis de influência e homologação. |
| IA gerando potenciais | Não conforme | Não foram encontrados componentes de IA, tabelas de potenciais, fluxo de validação ou transferência automática para tabelas de gestão. |
| Separação Controle x Influência | Parcial | O sistema usa `Controle`, `Conhecimento` e `Influência`, mas não há evidência de tabelas de gestão separadas entre ações do cliente e de fornecedores. |
| Relacionamento automático requisito x gestão | Parcial | Existem views, popups e vínculos contextuais para licenças, controles e monitoramento. Não existe o fluxo completo baseado em potenciais validados para todos os módulos. |
| Cadastro e relacionamento manual | Parcial | Alguns grids permitem CRUD e vínculos manuais, mas o fluxo não cobre todos os módulos previstos. |
| Responsável diretamente na gestão | Parcial | Há `CODUSUARIO` em situações legais e planos de ação, mas não estrutura uniforme em todos os módulos. |
| Responsável x Tipo | Não evidenciado | Não foram encontradas tabelas e telas completas para Responsável x Tipo de Licença, Medição, Treinamento, Infraestrutura e Controle. |
| Telas “Minhas...” | Não conforme | Foi encontrada referência a “Minhas licenças” no dashboard, mas não telas completas para os demais módulos. |
| Responsável x Categoria | Não conforme | Existem categorias de legislação, mas não foi localizada estrutura de responsabilidade por categoria. |
| Responsável individual, múltiplo ou grupo | Parcial | O grid de requisitos agrega IDs de responsáveis em `RESPONSAVEL_IDS`, mas não há gestão completa de grupos e regras por tipo ou categoria. |
| Logs de tudo | Parcial/insuficiente | Existem `logs_auditoria` e `vw_logs_auditoria_grid`. Porém não há evidência de registro automático de todas as operações, download por período, retenção contratada ou envio automático por e-mail. |
| Delegação de responsabilidades | Não conforme | Não foram encontrados fluxo, tabela ou tela de delegação. |
| Usuários inativos não permanecerem responsáveis | Parcial | `classes/UsuarioSessao.php` impede acesso de usuários inativos, mas não há rotina para substituir ou delegar automaticamente suas responsabilidades. |

## Pontos adicionais encontrados

- O relatório e alguns textos do dashboard apresentam caracteres corrompidos, como `Período`, `Licenças` e `Situaçao`.
- Os cards do dashboard apresentam dados, mas ainda não funcionam plenamente como pontos de entrada para os detalhes.
- A infraestrutura atual está mais orientada a grids de relacionamento do LAIA/LPRO do que a um módulo independente de gestão.
- A auditoria possui uma estrutura inicial, mas ainda não atende integralmente à exigência de rastrear tudo com exportação e retenção controlada.

## Classificação consolidada

- **Dashboard visual:** aproximadamente conforme.
- **Módulo Legal e relacionamentos atuais:** parcialmente conforme.
- **Responsabilidades e telas “Minhas”:** insuficiente.
- **IA e potenciais:** não implementado.
- **Homologação:** não evidenciada.
- **Delegação e substituição de responsáveis:** não implementado.
- **Auditoria completa:** parcialmente implementada.

## Prioridades sugeridas

1. Implementar o modelo de responsabilidades, incluindo Responsável x Tipo, Responsável x Categoria, múltiplos usuários e grupos.
2. Criar o fluxo de delegação e substituição automática de responsáveis inativos.
3. Definir e implementar os módulos independentes de Treinamento, Homologação, Medição/Monitoramento e Infraestrutura.
4. Implementar o fluxo de potenciais gerados por IA, validação e transferência para as tabelas de gestão.
5. Completar a auditoria operacional, incluindo registro de CRUD, consulta, exportação, retenção e envio de logs.
6. Tornar os cards, indicadores, pendências e tarefas da Home navegáveis.
7. Corrigir os textos com caracteres corrompidos.

## Resultado

A aplicação está em uma base funcional de migração do legado, mas ainda não atende integralmente ao modelo descrito em `portal_legislacao_requisitos.md`.

Esta análise foi somente documental e de código. Nenhum arquivo da aplicação ou banco de dados foi alterado.
