# Plano de adaptação — Portal de Legislação

**Data:** 19/08/2026  
**Base:** análise de conformidade do arquivo `analise_conformidade_portal_legislacao_requisitos_20260819.md`.

## Objetivo

Adaptar o Green Portal ao modelo definido em `portal_legislacao_requisitos.md`, preservando o legado já migrado e criando uma base uniforme para responsabilidades, gestão legal, módulos independentes, auditoria e futura utilização de IA.

## Estratégia geral

A adaptação deve começar pela estrutura de responsabilidades e pelo modelo de dados. A implementação da IA deve ocorrer somente depois que os módulos, vínculos e regras de aprovação estiverem definidos e funcionando.

## Fase 1 — Modelo de responsabilidades

Definir e implementar as entidades necessárias para:

- usuários ativos;
- grupos de usuários;
- categorias legais;
- tipos de licença, controle, medição, treinamento, infraestrutura e homologação;
- responsáveis individuais;
- múltiplos responsáveis;
- responsáveis por grupo;
- períodos de validade das responsabilidades;
- delegações.

Este será o núcleo utilizado por todos os módulos.

## Fase 2 — Matriz de responsabilidades

Mapear, para cada módulo, as responsabilidades necessárias:

| Módulo | Responsável direto | Responsável x Tipo | Tela “Meu/Minhas” | Relação com legislação |
|---|---|---|---|---|
| Licenças | Implementar ou padronizar | Implementar | Implementar | Parcialmente existente |
| Medição e Monitoramento | Implementar ou padronizar | Implementar | Implementar | Parcialmente existente |
| Treinamento | Criar | Criar | Criar | Não implementado |
| Infraestrutura | Implementar ou padronizar | Implementar | Implementar | Parcial |
| Controle | Implementar ou padronizar | Implementar | Implementar | Parcial |
| Homologação | Criar | Criar | Criar | Não implementado |

## Fase 3 — Padronização do Módulo Legal

Definir uma estrutura comum para:

- requisito de Controle;
- requisito de Influência;
- requisito de Conhecimento;
- categoria legal;
- unidade relacionada;
- responsável;
- situação de atendimento;
- itens de gestão relacionados.

Depois, revisar views, grids, filtros, popups e relacionamentos para que todos sigam o mesmo padrão.

## Fase 4 — Delegação e usuários inativos

Implementar uma rotina que:

- impeça novos vínculos com usuários inativos;
- identifique responsabilidades atribuídas a usuários desativados;
- apresente essas pendências ao administrador;
- permita delegar a responsabilidade para outro usuário ou grupo;
- registre a alteração no log de auditoria.

## Fase 5 — Módulos independentes de gestão

Implementar ou completar os módulos na seguinte ordem:

1. Licenças;
2. Medição e Monitoramento;
3. Controle;
4. Infraestrutura;
5. Treinamento;
6. Homologação.

Cada módulo deverá possuir:

- CRUD próprio;
- filtros e grid padronizados;
- responsáveis;
- tela “Meu/Minhas”;
- vínculo manual com requisitos legais;
- separação entre ações de Controle e Influência quando aplicável;
- auditoria das operações.

## Fase 6 — Fluxo de potenciais

Depois que as estruturas anteriores estiverem estáveis, implementar o fluxo de potenciais:

1. gerar potenciais de licenças, medições, treinamentos, infraestruturas e controles;
2. permitir revisão humana;
3. aprovar ou rejeitar cada potencial;
4. transformar o potencial aprovado em item de gestão;
5. relacionar o item aprovado ao requisito legal de origem;
6. atribuir responsáveis;
7. registrar todo o processo na auditoria.

A IA deve atuar dentro desse fluxo controlado, sem criar diretamente registros definitivos sem validação.

## Fase 7 — Auditoria completa

Completar a auditoria para registrar:

- inclusão;
- visualização, quando aplicável;
- alteração;
- exclusão;
- delegação;
- aprovação ou rejeição de potencial;
- relacionamento entre lei e item de gestão;
- usuário;
- empresa e banco do tenant;
- transação;
- data e hora;
- resultado e mensagem de erro.

Depois, implementar consulta, exportação, período de retenção e eventual envio automático dos logs por e-mail.

## Fase 8 — Dashboard e navegação

Conectar os elementos do dashboard às informações detalhadas:

- card de licenças para a tela de licenças;
- card de pendências para as pendências do usuário;
- card de tarefas para o módulo correspondente;
- indicadores para detalhes filtrados;
- alertas para as transações relacionadas.

O dashboard deve funcionar como ponto inicial de navegação, conforme definido no relatório.

## Primeira entrega recomendada

A primeira entrega deve conter:

1. modelo de grupos e responsáveis;
2. tabela Responsável x Tipo;
3. tabela Responsável x Categoria;
4. controle de usuário ativo ou inativo;
5. tela inicial de consulta de responsabilidades;
6. fluxo básico de delegação;
7. auditoria dessas operações.

Essa primeira etapa dará sustentação aos módulos de Licenças, Medição, Controle, Infraestrutura, Treinamento e Homologação.

## Ordem de validação

Cada etapa deve ser validada em homologação antes da próxima:

1. estrutura de banco e migrações;
2. permissões e segurança;
3. CRUD;
4. filtros e relacionamentos;
5. responsabilidades;
6. delegação;
7. auditoria;
8. integração com dashboard;
9. carga e desempenho.

## Resultado esperado

Ao final da adaptação, o Green Portal deverá permitir que cada requisito legal origine ou seja relacionado a itens de gestão, com responsáveis ativos, possibilidade de delegação, separação entre Controle e Influência, acompanhamento pelo dashboard e rastreabilidade completa.

Nenhuma alteração de código ou banco foi realizada na elaboração deste plano.
