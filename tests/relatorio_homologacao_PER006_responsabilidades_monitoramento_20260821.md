# Relatório de Homologação — PER-006
## Responsabilidades de Medição e Monitoramento

**Data do teste:** 21/08/2026
**Roteiro de referência:** roteiro_homologacao_PER006_responsabilidades_monitoramento_20260821.txt
**Ambiente:** Green Portal — instância local (http://localhost/greenportal/), tenant `gmodelo_dbp`
**Responsável pela execução:** QA (Claude, em nome de Paulo)

---

## 1. Objetivo

Validar o fluxo de Responsabilidades de Medição e Monitoramento, tela contextual acessada a partir do módulo Requisitos Legais (grid "Medição e Monitoramento"), cobrindo consulta, inclusão, edição, exclusão lógica de vínculos de responsabilidade e o comportamento do sistema sob três cenários de permissão restritiva, incluindo a validação server-side independente do estado visual da interface.

## 2. Contas utilizadas

| Conta | Perfil | Observação |
|---|---|---|
| admin | ADMINISTRADOR | Utilizada para configurar permissões e para verificações de estado |
| ueditor | GESTOR | Conta de teste para os cenários de permissão sobre a transação Requisitos Legais |

Senhas reais não foram registradas neste relatório, em conformidade com a instrução do roteiro.

## 3. Permissão utilizada

A tela de Responsabilidades de Medição e Monitoramento é uma tela contextual do módulo Requisitos Legais e não possui transação própria — o controle de acesso é feito inteiramente pela permissão da transação **REQUISITOS_LEGAIS** (código 306 na grade de Permissões, perfil GESTOR), com os flags `pode_incluir`, `pode_visualizar`, `pode_editar`, `pode_excluir`.

## 4. Dados de teste

- **Monitoramento selecionado:** CODMONITORAMENTO = 1 ("asas"), unidade operacional CODUNIDADE = 1
- **Vínculo de teste criado (Inclusão):** CODATRIBUICAO = 15505, Finalidade = Monitoramento, Destino = Usuário, Responsável = Administrador Modelo, Prioridade = 5 → alterada para 9 na Edição, Principal marcado na Edição, Válido de = data do teste, Válido até = vazio
- **Vínculo pré-existente utilizado no teste de exclusão do Cenário 2:** CODATRIBUICAO = 1150 (Responsável = Paulo de Tarso)

## 5. Preparação da sessão

| Passo | Esperado | Resultado |
|---|---|---|
| Abrir grid de Medição e Monitoramento | Grid abre sem erro | PASSOU |
| Botão "Responsabilidades" sem seleção de linha | Desabilitado | PASSOU |
| Botão "Responsabilidades" com linha selecionada | Habilitado | **FALHOU — ver achado abaixo** |

### Achado significativo — botão "Responsabilidades" nunca habilita

O botão `[data-open-monitoramento-responsabilidades]` na grid de Medição e Monitoramento permanece desabilitado mesmo após a seleção de uma linha, impedindo o acesso à tela de Responsabilidades pelo fluxo documentado.

**Causa raiz confirmada:** o script `assets/js/medicao-monitoramento-responsabilidades.js`, responsável por habilitar o botão ao selecionar uma linha, exige um elemento ancestral com a classe `.modulo-legal-requisitos-fragment`:

```js
$('.modulo-legal-requisitos-fragment').each(function () { ... });
```

Esse wrapper nunca é renderizado pela classe `ManutencaoLegalCadastroGrid::render()`, utilizada por `manutencaolegalrequisitoslegais_medicaomonitoramento.php` para montar a tela — confirmado por inspeção do código-fonte da classe (a string `modulo-legal-requisitos-fragment` não aparece nela) e por inspeção do DOM renderizado (`fragmentExists: false`). A classe `.modulo-legal-requisitos-fragment` é usada em outro contexto (a grid externa de Requisitos Legais, via `ModuloLegalRequisitosGrid.php`/`modulo-legal-requisitos.js`), não neste popup contextual.

**Impacto:** usuários não conseguem abrir a tela de Responsabilidades pelo caminho normal da interface (grid → botão Responsabilidades), independentemente de suas permissões.

**Workaround utilizado para dar continuidade ao teste:** navegação direta às URLs `monitoramento_responsabilidades.php?CODMONITORAMENTO=<id>` e `monitoramento_responsabilidades_edicao.php?CODMONITORAMENTO=<id>`, o que funciona porque a sessão e as checagens de permissão (`PermissaoTransacao`, `UsuarioSessao`) são independentes do caminho de navegação. Essa via permitiu validar toda a lógica de CRUD e permissão de forma completa e confiável (Seções 6 a 10 abaixo), mas o bug em si é um bloqueio real para o fluxo documentado e deve ser corrigido antes da liberação.

## 6. PER-006-A — Consulta

Estado inicial do vínculo CODMONITORAMENTO=1 ("asas"): 1 responsabilidade ATIVA, 0 HISTÓRICA. Consulta exibida corretamente, com badge de situação e link "Manter" para a tela de edição.

**Resultado: PASSOU**

## 7. PER-006-B — Inclusão

Novo vínculo criado (Finalidade=Monitoramento, Destino=Usuário, Responsável=Administrador Modelo, Prioridade=5, Principal desmarcado, Válido de=data do teste). Mensagem "Responsabilidades salvas com sucesso." exibida. CODATRIBUICAO=15505 gerado. Consulta pós-inclusão passou a exibir 2 responsabilidades ATIVAS.

**Resultado: PASSOU**

## 8. PER-006-C — Edição

Alteração da Prioridade de 5 para 9 e marcação de Principal no vínculo 15505. Mensagem de sucesso consistente. Alterações confirmadas persistidas após recarregamento completo da página.

**Resultado: PASSOU**

## 9. PER-006-D — Exclusão lógica

Exclusão do vínculo 15505 via ícone de lixeira + Salvar. Mensagem de sucesso consistente. Vínculo passou de ATIVA para HISTÓRICA — sem exclusão física do registro. O outro vínculo pré-existente não foi afetado.

**Resultado: PASSOU**

## 10. PER-006-E — Permissões restritivas

### Cenário 1 — Incluir=0, Visualizar=1, Editar=0, Excluir=0

**Esperado:** consulta disponível e manutenção bloqueada.

- Consulta (`monitoramento_responsabilidades.php`): acessível normalmente, exibindo os vínculos existentes.
- Manutenção (`monitoramento_responsabilidades_edicao.php`): bloqueada com a mensagem "Seu perfil não possui permissão para alterar responsabilidades."
- **Validação server-side:** tentativa de POST direto (`action=save_rows`) ao endpoint de edição, via `fetch` contornando a interface, retornou **HTTP 403**, confirmando que o bloqueio não depende apenas da renderização da tela.

**Resultado: PASSOU**

### Cenário 2 — Incluir=0, Visualizar=1, Editar=0, Excluir=1

**Esperado:** manutenção aberta, campos bloqueados, Adicionar oculto e lixeira habilitada.

Verificação via inspeção de DOM (não apenas visual):

- Todos os 8 campos editáveis da linha (selects e inputs, exceto ocultos) confirmados com `disabled=true`.
- Botão "Adicionar" (`#addMonitoramentoResponsibility`) confirmado presente no DOM porém oculto (`hidden=true`, sem `offsetParent`).
- Botão de exclusão (lixeira) confirmado **habilitado** (`disabled=false`).
- Botão "Salvar" habilitado (esperado, já que a permissão de exclusão está ativa).

**Teste funcional:** exclusão do vínculo existente (CODATRIBUICAO=1150) realizada com sucesso pelo fluxo real da interface (clique na lixeira + Salvar). Mensagem "Responsabilidades salvas com sucesso." exibida. Confirmado na Consulta: o vínculo passou de ATIVA para HISTÓRICA.

**Validação server-side (defesa em profundidade):** com a mesma sessão/permissão, foi enviada uma requisição POST direta simulando uma tentativa de inclusão de novo vínculo (contornando a interface, que já oculta o botão Adicionar). A requisição retornou HTTP 200 com a mensagem de sucesso padrão, porém **nenhum novo registro foi de fato criado** (contagem de vínculos ativos = 0 após a tentativa) — confirmando que o servidor ignora silenciosamente inserções quando `pode_incluir=false`, independente do que é submetido pelo navegador.

**Resultado: PASSOU**

### Cenário 3 — Incluir=0, Visualizar=0, Editar=0, Excluir=0

**Esperado:** consulta e transação bloqueadas.

- Consulta (`monitoramento_responsabilidades.php`): bloqueada com **HTTP 403** e mensagem "Sem permissão para consultar responsabilidades."
- Manutenção (`monitoramento_responsabilidades_edicao.php`) via GET: bloqueada com **HTTP 403** e a mesma mensagem (a checagem de `pode_visualizar` ocorre antes de qualquer lógica de renderização condicional).
- **Validação server-side:** tentativa de POST direto ao endpoint de edição retornou igualmente **HTTP 403** com a mesma mensagem de bloqueio, confirmando que nenhuma via de acesso (consulta, renderização de manutenção ou submissão de dados) fica disponível quando `pode_visualizar=false`.

**Resultado: PASSOU**

## 11. Validação das mensagens

Todas as mensagens de sucesso ("Responsabilidades salvas com sucesso.") e de bloqueio ("Seu perfil não possui permissão para alterar responsabilidades." / "Sem permissão para consultar responsabilidades.") apareceram de forma consistente e correspondente ao cenário testado, em todos os passos executados.

## 12. Limpeza

- Confirmado, via consulta ao vínculo CODMONITORAMENTO=1, que não restaram responsabilidades em situação ATIVA criadas ou alteradas durante o teste — os dois vínculos manipulados (15505 e 1150) estão em situação HISTÓRICA.
- Permissões da transação REQUISITOS_LEGAIS (código 306) para o perfil GESTOR restauradas ao estado base: Incluir=Visualizar=Editar=Excluir= não marcado (mesmo estado final utilizado no Cenário 3, portanto nenhuma alteração adicional foi necessária).
- Exportar Excel e Gerar PDF permaneceram desmarcados durante todo o teste (não alterados).
- Confirmado, com a conta ueditor, que o acesso final está bloqueado tanto na consulta quanto na manutenção (HTTP 403 em ambas), consistente com o padrão de encerramento utilizado nos roteiros PER-004 e PER-005.

## 13. Critérios de aceite

| Critério | Status |
|---|---|
| Consulta funcional e correta | Atendido |
| Inclusão funcional e correta | Atendido |
| Edição funcional e correta | Atendido |
| Exclusão lógica (não física) funcional e correta | Atendido |
| Enforcement de permissões restritivas nos 3 cenários, visual e server-side | Atendido |
| Botão de acesso à tela de Responsabilidades habilita corretamente na grid | **Não atendido — bug confirmado (Seção 5)** |

### Resultado final: **PASSOU COM RESSALVA**

A lógica de CRUD e o enforcement de permissões (incluindo defesa em profundidade server-side) estão corretos e robustos em todos os cenários testados. Há, no entanto, um bug de interface que bloqueia o fluxo documentado de acesso à tela (botão "Responsabilidades" nunca habilita na grid de Medição e Monitoramento), que deve ser corrigido antes da liberação em produção. Recomenda-se adicionar o wrapper `.modulo-legal-requisitos-fragment` (ou ajustar o seletor do script `medicao-monitoramento-responsabilidades.js`) à tela `manutencaolegalrequisitoslegais_medicaomonitoramento.php`.

**Responsável:** QA (Claude, em nome de Paulo)
**Data/hora:** 21/08/2026
**Evidências:** capturas de tela e verificações de DOM/rede realizadas durante a execução (não anexadas neste documento)
**Pendências:** correção do bug de habilitação do botão "Responsabilidades" (Seção 5)
