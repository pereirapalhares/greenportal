# Reteste de Homologação — PER-008
## Permissões parciais e mensagens em Responsabilidades de Funcionários

**Data:** 22/08/2026
**Roteiro base:** `roteiro_reteste_PER008_permissoes_mensagens_20260822.txt`
**Relatório original:** `relatorio_homologacao_PER008_responsabilidades_funcionario_20260822.md` (22/08/2026) — resultado "APROVADO COM RESSALVAS", 3 achados

---

## 1. Objetivo

Confirmar as três correções apontadas no relatório original PER-008:

1. Modo somente leitura quando o perfil possui apenas **Visualizar** (Achado 1).
2. Disponibilidade da exclusão lógica quando o perfil possui apenas **Excluir** (Achado 2).
3. Mensagem clara quando uma submissão contém inclusão permitida e edição não autorizada (Achado 3).

## 2. Ambiente e usuários

| Item | Valor |
|---|---|
| URL | http://187.191.99.180/greenportal/ |
| Empresa | MODELO |
| Banco | gmodelo_dbp |
| Preparação/limpeza | admin / <senha de teste via variável de ambiente> |
| Execução autorizada | ueditor / <senha de teste via variável de ambiente> |
| Transação | HOMOLOGACAO_FORNECEDOR_FUNCIONARIO, código 495 |
| Funcionário de teste | CODFUNCIONARIO 13 — Euclides Alves Barreto |
| Funcionário de controle (não regressão) | CODFUNCIONARIO 14 — Evertom Vieira dos Santos Junior |

## 3. Correção confirmada no código-fonte

Antes da execução em navegador, o código de `principal/funcionario_responsabilidades_edicao.php` foi inspecionado e comparado com a versão pré-correção usada no relatório original:

- Nova variável `$canMaintain = !empty($permission['pode_visualizar']);`.
- Condição de exibição da tela de manutenção alterada de `!$permission['pode_editar'] && !$permission['pode_incluir']` para `!$canMaintain` — a tela agora é exibida sempre que houver Visualizar, delegando o bloqueio fino de campos/botões ao script `assets/js/responsabilidades-permissoes.js` (não alterado, e já correto).
- Condição da mensagem de submissão parcial simplificada de `$ignored && !pode_editar && !pode_incluir` para `$ignored` puro.

A lógica de autorização no POST (guarda geral e validações por linha para incluir/editar/excluir) permanece textualmente inalterada e já estava correta.

## 4. Pré-condições

Como os dois vínculos de teste do relatório original (15510 e 15511) estavam com `ATIVO=0` (desativados na limpeza anterior), foi necessário criar novos vínculos de teste ativos para o funcionário 13 antes de iniciar os testes de permissão restritiva, conforme previsto na pré-condição 2 do roteiro. Essa criação foi feita com o perfil GESTOR temporariamente em acesso total (V=1, I=1, E=1, X=1), etapa que também serviu para validar a manutenção completa e a ausência de mistura entre funcionários (seção 6).

Foram criados dois vínculos:

| CODATRIBUICAO | Finalidade | Destino | Prioridade |
|---|---|---|---|
| 15512 | Responsável pela documentação | Usuário (UEditor) | 10 |
| 15513 | Responsável pelo fornecedor | Grupo (GESTORES) | 20 |

## 5. Execução em navegador

### RT-A — Somente Visualizar (V=1, I=0, E=0, X=0)

1. Permissão do GESTOR ajustada e persistência confirmada via phpMyAdmin (I=0, V=1, E=0, X=0).
2. Login como ueditor, navegação por menu até Homologação de Fornecedores > Gestão de Funcionários > funcionário 13 > Responsabilidades > Manter.
3. A manutenção **abriu normalmente**, identificando corretamente "Funcionário 13 — Euclides Alves Barreto", sem a mensagem de bloqueio total observada no teste original.
4. Os dois registros ativos (15512, 15513) permaneceram visíveis.
5. Verificação via DOM confirmou: todos os campos (tipo, destino, usuário/grupo, prioridade, principal, vigência) com `disabled=true` em ambas as linhas; botão "Adicionar" oculto (`hidden=true`); botões de lixeira desabilitados; botão "Salvar" desabilitado.

**Resultado: APROVADO.** Modo somente leitura funcionando, sem bloqueio total indevido. Achado 1 do relatório original confirmado como corrigido.

### RT-E — Somente Excluir (V=1, I=0, E=0, X=1)

1. Permissão ajustada e persistência confirmada (I=0, V=1, E=0, X=1).
2. Reabertura da manutenção como ueditor: tela e os dois registros exibidos normalmente (sem bloqueio total).
3. Verificação via DOM: campos desabilitados em ambas as linhas, "Adicionar" oculto, botões de lixeira **habilitados** (`disabled=false`), botão "Salvar" **habilitado**.
4. Exclusão lógica do vínculo 15512 (Responsável pela documentação) via lixeira, seguida de Salvar.
5. Confirmado via Consulta: o vínculo passou para situação "HISTÓRICA"; o vínculo 15513 permaneceu "ATIVA", sem alteração de seus dados.
6. Confirmado via phpMyAdmin: `15512` com `ATIVO=0`; `15513` inalterado (`PRIORIDADE=20`, `ATIVO=1`); nenhuma linha removida fisicamente da tabela.

**Resultado: APROVADO.** Exclusão lógica disponível pela interface, sem exclusão física e sem alteração indevida de outros registros. Achado 2 do relatório original confirmado como corrigido.

**Observação:** a mensagem de retorno exibida após salvar foi "Operação concluída. Inclusões e edições não autorizadas foram ignoradas.", em vez de uma mensagem de sucesso simples — ver Achado Novo 1 na seção 7.

### RT-C — Inclusão permitida e edição não autorizada (V=1, I=1, E=0, X=0)

1. Permissão ajustada e persistência confirmada (I=1, V=1, E=0, X=0).
2. Reabertura da manutenção: registro existente (15513) com campos desabilitados e lixeira desabilitada; botão "Adicionar" disponível.
3. Adicionada uma responsabilidade de teste (Tipo=24 "Responsável pela documentação", Destino=Usuário UEditor, Prioridade=30) e salva. Inclusão processada com sucesso, gerando o vínculo 15514 (`ATIVO=1`).
4. Repetição da submissão incluindo uma tentativa controlada de alterar o registro existente 15513 (campo Prioridade habilitado via manipulação do DOM e alterado para 999, simulando uma tentativa de burla client-side) junto com uma nova inclusão válida (Tipo=23, Destino=Grupo GESTORES, Prioridade=40).
5. Confirmado via phpMyAdmin: a nova inclusão foi processada (vínculo 15515, `PRIORIDADE=40`, `ATIVO=1`); a tentativa de alteração do vínculo 15513 foi **ignorada** — o valor permaneceu `PRIORIDADE=20`, sem qualquer alteração.
6. A mensagem exibida foi exatamente: **"Operação concluída. Inclusões e edições não autorizadas foram ignoradas."**

**Resultado: APROVADO.** A inclusão válida foi processada, a edição não autorizada foi corretamente ignorada sem corromper os dados, e a mensagem correta foi exibida no cenário que o roteiro define como alvo (inclusão permitida com tentativa de edição bloqueada). Achado 3 do relatório original confirmado como corrigido **para o cenário testado**.

## 6. Validação de não regressão

Executada no início dos testes, com o perfil GESTOR em acesso total (V=1, I=1, E=1, X=1):

- A manutenção do funcionário 13 abriu corretamente, com "Adicionar" e "Salvar" disponíveis; os dois vínculos de teste foram criados com sucesso por essa via.
- Ao trocar para o funcionário 14 (Evertom Vieira dos Santos Junior), a consulta exibiu corretamente "Não há responsabilidades cadastradas para este funcionário" e a manutenção abriu vazia e correta, sem qualquer mistura com os dados do funcionário 13.

**Resultado: APROVADO.** Acesso completo continua funcionando normalmente e não há mistura de responsabilidades entre funcionários.

## 7. Achado novo identificado durante o reteste

**Achado Novo 1 — Mensagem de "inclusões/edições ignoradas" exibida mesmo sem tentativa de ação não autorizada (severidade: baixa/cosmética).**

A correção da mensagem parcial (simplificação da condição para `$ignored` puro) resolve corretamente o cenário-alvo do Achado 3 original (RT-C, passo 4: inclusão válida + tentativa real de edição não autorizada). Entretanto, foi observado que a mesma mensagem — "Operação concluída. Inclusões e edições não autorizadas foram ignoradas." — também aparece em pelo menos dois cenários em que o usuário **não** tentou nenhuma ação não autorizada:

- RT-E, passo de exclusão lógica pura (apenas um registro existente foi excluído; nenhuma edição foi tentada em nenhum campo).
- RT-C, passo 3, inclusão válida isolada (nenhuma tentativa de edição de registro existente).

Em ambos os casos os dados permaneceram íntegros — não há corrupção nem perda de dados —, mas a mensagem pode confundir o usuário, sugerindo que parte de sua submissão foi bloqueada quando, na realidade, ele realizou apenas ações dentro de sua permissão. A causa provável é que campos desabilitados de linhas existentes não são enviados pelo navegador no POST; o backend, ao comparar os valores ausentes com os valores atuais no banco, aparentemente interpreta essa ausência como uma tentativa de edição bloqueada, mesmo quando o usuário não tocou no campo.

Recomenda-se ao time de desenvolvimento revisar a lógica que define `$ignored` no backend, distinguindo explicitamente "campo ausente por estar desabilitado/fora do escopo de edição do usuário" de "campo efetivamente alterado e rejeitado por falta de permissão", de forma que a mensagem de alerta apareça apenas quando uma alteração de fato foi tentada e bloqueada.

Este achado não compromete a integridade dos dados nem a segurança da funcionalidade, e não impede a aprovação do reteste, pois o critério de aprovação do roteiro (mensagem correta no cenário de inclusão permitida com edição bloqueada) foi cumprido.

## 8. Limpeza e restauração

- Todos os vínculos de teste criados nesta rodada (15512, 15513, 15514, 15515) foram **logicamente excluídos** (`ATIVO=0`) via interface, com a permissão do GESTOR temporariamente ajustada para permitir a exclusão.
- Confirmado via phpMyAdmin que os seis registros de teste relacionados ao funcionário 13 (15510 a 15515) estão todos com `ATIVO=0` e nenhum foi fisicamente removido da tabela `responsabilidade_atribuicoes`.
- Permissão do GESTOR para código 495 restaurada ao baseline original: **Incluir=0, Visualizar=1, Editar=0, Excluir=0**, confirmado via phpMyAdmin.
- Exportar Excel e Gerar PDF permaneceram inalterados (0/0) durante todo o reteste.
- Nenhum vínculo legado foi alterado — não existiam vínculos legados para os funcionários 13 ou 14 antes do início dos testes, e nenhum outro funcionário foi manipulado além dos dois usados para a checagem de não regressão (13 e 14, este último apenas consultado, sem escrita).

## 9. Quadro-resumo

| Item | Cenário | Resultado |
|---|---|---|
| RT-A | Somente Visualizar (V=1,I=0,E=0,X=0) | **Aprovado** |
| RT-E | Somente Excluir (V=1,I=0,E=0,X=1) | **Aprovado** (com observação — Achado Novo 1) |
| RT-C | Inclusão permitida, edição bloqueada (V=1,I=1,E=0,X=0) | **Aprovado** |
| Não regressão | Acesso total + troca de funcionário | **Aprovado** |
| Limpeza | Exclusão lógica, restauração de baseline | **Concluída, sem exclusão física, sem alteração de dados legados** |

## 10. Conclusão

Os três achados do relatório de homologação original (`relatorio_homologacao_PER008_responsabilidades_funcionario_20260822.md`) foram **confirmados como corrigidos**:

1. **Achado 1 (Cenário E — exclusão inacessível):** RESOLVIDO. A exclusão lógica está disponível e funcional quando o perfil possui apenas a permissão Excluir.
2. **Achado 2 (Cenário A — sem modo leitura):** RESOLVIDO. A manutenção abre corretamente em modo somente leitura quando o perfil possui apenas a permissão Visualizar, sem bloqueio total indevido.
3. **Achado 3 (mensagem não sinaliza edição ignorada):** RESOLVIDO para o cenário-alvo. A mensagem "Operação concluída. Inclusões e edições não autorizadas foram ignoradas." é exibida corretamente quando uma submissão contém uma inclusão válida e uma tentativa real de edição não autorizada, sem corrupção de dados.

Um novo achado de severidade baixa (Achado Novo 1) foi identificado: a mesma mensagem de alerta também é exibida em submissões legítimas sem qualquer tentativa de ação não autorizada, o que pode gerar confusão para o usuário, embora não comprometa a integridade dos dados.

**Resultado final: APROVADO.**

Todos os critérios de aprovação do roteiro de reteste foram atendidos: RT-A, RT-E e RT-C aprovados, sem alteração indevida de dados em nenhum momento, e com a mensagem parcial correta no cenário de inclusão permitida com edição bloqueada. Recomenda-se avaliação do Achado Novo 1 pelo time de desenvolvimento como melhoria de UX, sem caráter bloqueante.
