# Relatório de Homologação — PER-008
## Responsabilidades em Gestão de Funcionários

**Data do teste:** 22/08/2026
**Roteiro de referência:** roteiro_homologacao_PER008_responsabilidades_funcionario_20260822.txt
**Ambiente:** Green Portal — instância remota (http://187.191.99.180/greenportal/), tenant `gmodelo_dbp`, empresa MODELO
**Responsável pela execução:** QA (Claude, em nome de Paulo)

---

## 1. Objetivo

Homologar a consulta e a manutenção de responsabilidades diretas para um funcionário selecionado em Homologação de Fornecedores > Gestão de Funcionários, validando contexto, permissões, inclusão, edição, exclusão lógica e mensagens.

## 2. Escopo e regra de legado

O escopo desta etapa é a entidade `fo_funcionarios` no tenant `gmodelo_dbp`. Conforme instrução do roteiro, `fo_funcionarioxtiposxtreinamentos.codusuario` **não** foi migrado nem interpretado como responsabilidade do funcionário — esse vínculo pertence ao módulo TREINAMENTO e permanece pendente de revisão semântica. Órfãos e a diferença de cinco registros entre o legado e o banco novo permaneceram fora deste teste, conforme instrução.

## 3. Contas e transação

- **Preparação e limpeza:** `admin` (perfil ADMINISTRADOR)
- **Execução autorizada:** `ueditor` (perfil GESTOR)
- **Transação:** `HOMOLOGACAO_FORNECEDOR_FUNCIONARIO`, código 495 (confirmado no cadastro de Permissões), governando tanto o acesso à grade "Gestão de Funcionários" quanto à consulta/manutenção de responsabilidades do funcionário.
- **Baseline de permissão observado no início do teste (perfil GESTOR):** Visualizar=1, Incluir=0, Editar=0, Excluir=0, Exportar Excel=0, Gerar PDF=0. Este é o estado restaurado ao final do teste.

## 4. Dados de teste

- **Funcionário principal:** código 6 (sugerido pelo roteiro) não estava disponível na primeira página da grade (1103 registros); conforme fallback previsto no próprio roteiro, foi usado o primeiro registro válido: **CODFUNCIONARIO 13 — Euclides Alves Barreto** (Modelo Ponta Grossa, Agrograss Ponta Grossa).
- **Segundo funcionário (troca de contexto, PER-008.8):** CODFUNCIONARIO 14 — Evertom Vieira dos Santos Junior (Modelo CA, Cromos Cabreuva).
- **Responsável de teste:** usuário ativo `ueditor` (UEditor).
- **Grupo de teste:** GESTORES (código 13).
- **Tipos de responsabilidade utilizados:** "Responsável pela documentação" (código 24) e "Responsável pelo fornecedor" (código 23), ambos do módulo HOMOLOGACAO.

## 5. Pré-condições

| # | Item | Resultado |
|---|---|---|
| 1 | HOMOLOGACAO_FORNECEDOR_FUNCIONARIO ativa | Confirmado |
| 2 | Permissão temporária GESTOR ajustada para V=1,I=1,E=1,X=1 | Ajustada e confirmada via reload |
| 3 | Excel e PDF mantidos no estado anterior | Confirmado (0/0 durante toda a execução) |
| 4 | Acesso via `ueditor` pelo fluxo normal do menu, nunca por PHP direto | Login via formulário, navegação Administração/Homologação de Fornecedores/Gestão de Funcionários pelo shell da aplicação |

## 6. Resultados por sub-teste

### PER-008.1 — Acesso e botão contextual
Grade carregou com 1103 registros, cabeçalho fixo e rolagem vertical/horizontal. O botão "Responsabilidades" apareceu na barra de ações, **desabilitado** antes da seleção de qualquer linha e **habilitado** imediatamente após selecionar o funcionário 13. O clique abriu a consulta sem HTTP 400 e sem "Funcionário inválido.", identificando corretamente "Funcionário 13 — Euclides Alves Barreto".

**Resultado: PASSOU**

### PER-008.2 — Consulta
Título "Responsabilidades do funcionário" e cabeçalho "Funcionário 13 — Euclides Alves Barreto" corretos. Como o funcionário não possuía vínculos, a mensagem "Não há responsabilidades cadastradas para este funcionário" foi exibida corretamente (mesma mensagem reconfirmada depois para o funcionário 14 no PER-008.8). O botão "Manter" abriu a manutenção preservando o mesmo contexto (CODFUNCIONARIO=13).

Quanto à exclusão de vínculos de treinamento do legado (`fo_funcionarioxtiposxtreinamentos`): a consulta é alimentada pela view `vw_responsabilidade_funcionarios`, filtrada por `ENTIDADE='fo_funcionarios'`; nenhum registro do módulo TREINAMENTO apareceu na listagem do funcionário 13 nem do funcionário 14 (ambos vazios inicialmente), consistente com a regra de escopo do roteiro.

**Resultado: PASSOU**

### PER-008.3 — Inclusão de responsabilidade por usuário
Na manutenção, "Adicionar" criou uma nova linha; selecionado o tipo "Responsável pela documentação", destino Usuário, responsável UEditor, prioridade 5, vigência inicial 22/08/2026. "Salvar" retornou "Responsabilidades salvas com sucesso.", e o registro apareceu na grade com situação ATIVA. Confirmado via banco: **CODATRIBUICAO 15510** (ENTIDADE=fo_funcionarios, REGISTRO_ID=13, TIPO_DESTINO=USUARIO, CODUSUARIO=5, PRIORIDADE=5, ATIVO=1).

**Resultado: PASSOU**

### PER-008.4 — Edição
Prioridade alterada de 5 para 9 e "Principal" marcado no registro 15510. Salvo com sucesso; valores confirmados após recarregamento e via banco (PRIORIDADE=9, PRINCIPAL=1).

**Resultado: PASSOU**

### PER-008.5 — Destino por grupo
Adicionada uma segunda responsabilidade ("Responsável pelo fornecedor"); ao alterar o campo Destino para "Grupo", o campo Grupo passou a `display:block` e o campo Usuário passou a `display:none` com a classe `d-none` (verificado via DOM), confirmando visualmente e estruturalmente a alternância esperada. Selecionado o grupo GESTORES (código 13), prioridade 7. Salvo com sucesso; o registro apareceu na consulta com Destino "Grupo" e Responsável "GESTORES" — nome do grupo corretamente exibido. Confirmado via banco: **CODATRIBUICAO 15511** (TIPO_DESTINO=GRUPO, CODGRUPO=13, PRIORIDADE=7, ATIVO=1).

**Resultado: PASSOU**

*Observação de execução:* durante este sub-teste ocorreu um incidente de automação (não relacionado à aplicação) em que múltiplos iframes de popups anteriores permaneceram acumulados no DOM do navegador, levando a uma tentativa de edição direcionada a um iframe desatualizado. O incidente foi identificado antes de qualquer gravação e o banco foi conferido e confirmado íntegro (registro 15510 permaneceu com os valores corretos do PER-008.4) antes de prosseguir. Não houve impacto nos dados da aplicação; registrado aqui apenas por transparência do processo de teste.

### PER-008.6 — Exclusão lógica
Acionada a lixeira do vínculo 15511 na manutenção e salvo. Voltando à consulta, o vínculo passou a exibir situação **HISTÓRICA**, deixando de aparecer como ativo, enquanto o registro 15510 permaneceu ATIVA e inalterado. Confirmado via banco que o registro **permanece na tabela com ATIVO=0** — nenhuma exclusão física ocorreu.

**Resultado: PASSOU**

### PER-008.7 — Permissões restritivas

Cada cenário foi aplicado ajustando exclusivamente a permissão da transação (código 495) como `admin`, seguido de novo login como `ueditor` pelo fluxo normal do menu. Todos os ajustes de permissão foram confirmados via banco (`permissoes.codpermissao=495`) antes de cada teste.

**Cenário A — Visualizar=1, Incluir=0, Editar=0, Excluir=0**
- Consulta: permitida, exibindo corretamente os dois vínculos (histórico e ativo) do funcionário 13. **Conforme esperado.**
- Manter: **NÃO conforme o esperado.** Em vez de abrir a tela com Adicionar/campos/lixeira/Salvar bloqueados ou ocultos (conforme especifica o roteiro), a tela de manutenção exibiu a mensagem "Seu perfil não possui permissão para alterar responsabilidades." e nenhuma parte do formulário (nem em modo leitura) foi renderizada.
- Servidor: uma tentativa de POST direto (`action=save_rows`) para alterar o registro 15510 foi corretamente rejeitada com **HTTP 403** e sem alterar o banco (prioridade permaneceu 9). **Conforme esperado quanto à rejeição server-side.**

**Cenário B — Visualizar=0, Incluir=0, Editar=0, Excluir=0**
- Acesso à transação "Gestão de Funcionários" (grade completa) bloqueado com a mensagem "Seu perfil não tem permissão para acessar esta transação.", sem qualquer vestígio de SQL.
- Acesso direto à consulta de responsabilidades (`funcionario_responsabilidades.php`) também bloqueado, com **HTTP 403** e mensagem curta ("Sem permissão para consultar responsabilidades."), sem exposição de SQL.

**Resultado: PASSOU integralmente**

**Cenário C — Visualizar=1, Incluir=1, Editar=0, Excluir=0**
- "Adicionar" disponível (botão habilitado e visível).
- Registros existentes (15510) e todos os seus campos (tipo, destino, responsável/grupo, prioridade, principal, vigência) corretamente **desabilitados** no formulário (`disabled=true` confirmado via DOM em todos os campos).
- Nova inclusão permitida (formulário e Salvar habilitados).
- Testado também o comportamento server-side: um POST combinando uma tentativa de alteração do registro 15510 (prioridade para 777) com uma nova inclusão retornou HTTP 200 e mensagem de sucesso; verificado no banco que a alteração ao registro existente **foi corretamente ignorada** (prioridade permaneceu 9), confirmando que o servidor aplica a mesma regra de proteção mesmo que o formulário tivesse sido manipulado no cliente.

**Resultado: PASSOU integralmente**

*Observação secundária (não bloqueante):* quando uma tentativa de edição não autorizada é silenciosamente ignorada pelo servidor neste cenário (Incluir=1), a mensagem de retorno é sempre "Responsabilidades salvas com sucesso." — a mensagem alternativa "Operação concluída. Inclusões e edições não autorizadas foram ignoradas." só é usada quando tanto Incluir quanto Editar estão ambos desativados. Isso significa que, no Cenário C, o usuário não é avisado de que parte de sua submissão (a edição do registro existente, que estava desabilitado na tela mas poderia ser reativado via ferramentas do navegador) foi descartada. Não há risco de integridade de dados — apenas uma mensagem de sucesso incompleta.

**Cenário D — Visualizar=1, Incluir=0, Editar=1, Excluir=0**
- "Adicionar" **oculto** (elemento presente no DOM mas não visível — `offsetParent === null`).
- Edição de registros existentes **permitida**: todos os campos do registro 15510 renderizados sem o atributo `disabled`.
- Lixeira desabilitada (coerente com Excluir=0).
- Testado o comportamento server-side com um POST combinando uma edição legítima do registro 15510 (prioridade para 42) e uma tentativa de inclusão de novo registro sem permissão: a edição foi persistida corretamente (confirmado no banco: PRIORIDADE=42) e a tentativa de inclusão foi **corretamente ignorada** — nenhum registro novo foi criado.

**Resultado: PASSOU integralmente**

**Cenário E — Visualizar=1, Incluir=0, Editar=0, Excluir=1**
- **NÃO conforme o esperado.** O roteiro especifica que, neste cenário, "exclusão lógica" deve estar disponível e apenas inclusão/edição bloqueadas. Na prática, a tela de manutenção exibiu a mesma mensagem de bloqueio total observada no Cenário A ("Seu perfil não possui permissão para alterar responsabilidades."), tornando a lixeira/exclusão **inacessível pela interface**, apesar do usuário possuir Excluir=1.
- Para isolar se tratava-se de um problema de interface ou de uma restrição também presente no backend, foi enviado um POST direto de "exclusão apenas" (`action=save_rows` sem nenhum campo `rows[...]`, simulando o comportamento que a tela produziria se o vínculo existente fosse removido da lista antes do envio). O servidor **aceitou e processou a operação normalmente** (HTTP 200, mensagem de sucesso, sem erro de permissão), e o registro 15510 foi corretamente desativado no banco (ATIVO=0). Isso confirma que a lógica de autorização no backend está correta e já suporta o cenário "somente exclusão" — o problema está isolado à camada de apresentação.

**Causa raiz identificada (inspeção de código):** em `principal/funcionario_responsabilidades_edicao.php`, o formulário de manutenção (incluindo a tabela, os botões Adicionar/Salvar e a lixeira de cada linha) só é renderizado quando a condição `!$permission['pode_editar'] && !$permission['pode_incluir']` é falsa:

```php
<?php if (!$permission['pode_editar'] && !$permission['pode_incluir']): ?>
<div class="alert alert-warning">Seu perfil não possui permissão para alterar responsabilidades.</div>
<?php else: ?>
<form method="post">...</form>
<?php endif; ?>
```

Essa condição não leva em conta `pode_excluir`. Assim, sempre que Incluir e Editar estiverem ambos desativados — independentemente de Excluir estar ativo — o formulário inteiro (e, com ele, a única forma de acionar a lixeira) desaparece, mesmo que o usuário tenha permissão legítima de exclusão. O mesmo mecanismo explica o comportamento do Cenário A: com Visualizar=1 mas Incluir=0 e Editar=0, o roteiro esperava uma tela em modo leitura (campos desabilitados, como ocorre corretamente no Cenário C para os registros existentes), mas o gate acima impede que qualquer parte do formulário seja renderizada, produzindo o bloqueio total observado.

Este é um achado a ser corrigido: a condição de exibição do formulário deveria também considerar `pode_excluir` (e, para o caso de Visualizar-apenas, renderizar o formulário em modo somente leitura em vez de ocultá-lo por completo), de forma equivalente ao tratamento já existente no módulo de responsabilidades de fornecedor (`fornecedor_responsabilidades_edicao.php`), que possui um ajuste específico de permissão para o método GET nesse mesmo tipo de situação.

**Resultado: FALHOU quanto à disponibilidade de exclusão lógica pela interface (Achado registrado abaixo); backend corretamente seguro e funcional.**

### PER-008.8 — Troca de funcionário e compatibilidade
Selecionado o segundo funcionário (CODFUNCIONARIO 14 — Evertom Vieira dos Santos Junior) na grade e acionado "Responsabilidades": a consulta abriu corretamente com "Funcionário 14 — Evertom Vieira dos Santos Junior" e a mensagem de ausência de vínculos, confirmando que o contexto não ficou preso ao funcionário 13. O acesso direto via URL completa (`.../principal/funcionario_responsabilidades.php?CODFUNCIONARIO=14`) retornou HTTP 200 com título e cabeçalho corretos, confirmando a compatibilidade do parâmetro direto.

**Resultado: PASSOU**

## 7. Validação das mensagens

| Mensagem | Contexto | Resultado |
|---|---|---|
| "Não há responsabilidades cadastradas para este funcionário" | Consulta sem vínculos (funcionários 13 e 14) | Correta |
| "Responsabilidades salvas com sucesso." | Inclusão/edição/exclusão bem-sucedidas | Correta, porém não distingue quando parte da submissão foi silenciosamente ignorada (ver observação do Cenário C) |
| "Seu perfil não possui permissão para alterar responsabilidades." | Tela de manutenção quando Incluir=0 e Editar=0 | Correta na redação, porém aplicada de forma mais ampla do que o esperado pelo roteiro (bloqueia também quando apenas Excluir deveria estar disponível — Cenário E) |
| "Seu perfil não tem permissão para acessar esta transação." | Cenário B, acesso à grade | Correta, sem exposição de SQL |
| "Sem permissão para consultar responsabilidades." | Cenário B, acesso direto à consulta (HTTP 403) | Correta, sem exposição de SQL |
| "Funcionário inválido." / "Funcionário não localizado." | Não disparadas neste teste (funcionários válidos usados em todos os cenários) | N/A |

## 8. Achados

**Achado 1 (funcional, prioridade alta) — Exclusão lógica inacessível quando apenas Excluir está habilitado (Cenário E).**
Em `principal/funcionario_responsabilidades_edicao.php`, a condição que decide se o formulário de manutenção é exibido (`!$permission['pode_editar'] && !$permission['pode_incluir']`) ignora `pode_excluir`. Um usuário com permissão exclusiva de exclusão (V=1, I=0, E=0, X=1) não consegue acionar a lixeira pela interface, pois o formulário inteiro é substituído pela mensagem de bloqueio. O backend já processa corretamente uma exclusão nessas condições (confirmado via requisição direta), portanto a correção é restrita à condição de renderização da view.

**Achado 2 (funcional, prioridade média) — Cenário "somente Visualizar" não oferece tela em modo leitura (Cenário A).**
Quando o usuário tem apenas Visualizar=1 (sem Incluir nem Editar), o roteiro espera que "Manter" abra a tela com os controles de edição bloqueados/ocultos — comportamento que a aplicação já implementa corretamente quando Incluir=1 e Editar=0 (Cenário C, campos desabilitados). Por decorrência do mesmo gate do Achado 1, esse modo somente-leitura nunca é alcançado nesta combinação específica de permissões, resultando na mesma mensagem de bloqueio total do Cenário E.

**Achado 3 (UX, prioridade baixa) — Mensagem de sucesso não sinaliza edição/inclusão parcialmente ignorada (Cenário C).**
Quando Incluir=1 mas Editar=0, uma submissão que tenta alterar um registro existente é corretamente rejeitada no servidor (dado não é corrompido), mas a mensagem retornada é sempre "Responsabilidades salvas com sucesso.", sem informar ao usuário que parte da operação foi descartada. A mensagem alternativa já existente no código ("Operação concluída. Inclusões e edições não autorizadas foram ignoradas.") só é usada quando Incluir e Editar estão ambos desativados.

Nenhum dos achados acima representa risco de integridade ou de segurança dos dados: em todos os casos, a camada de persistência (backend) aplicou corretamente as regras de autorização, mesmo quando a interface não refletia com precisão a matriz de permissões esperada pelo roteiro.

## 9. Limpeza e restauração

1. **Vínculos de teste excluídos logicamente:** CODATRIBUICAO 15510 e 15511 — ambos confirmados com ATIVO=0 no banco, sem exclusão física.
2. **Nenhum vínculo legado foi alterado:** os funcionários 13 e 14 não possuíam vínculos pré-existentes; nenhum outro registro do módulo foi tocado durante o teste.
3. **Permissão do GESTOR (código 495) restaurada ao baseline:** Visualizar=1, Incluir=0, Editar=0, Excluir=0 — confirmado via banco de dados após a gravação final.
4. **Exportar Excel e Gerar PDF:** permaneceram desmarcados (0/0) durante toda a execução, incluindo no estado final.
5. **Nenhuma exclusão física ocorreu no banco:** confirmado por consulta direta — todos os registros de teste permanecem presentes, apenas com ATIVO=0.

## 10. Critérios de aceite

| Critério | Status |
|---|---|
| Contexto do funcionário preservado em toda a navegação (consulta, manutenção, troca de funcionário) | Aprovado |
| Consulta (título, cabeçalho, mensagem de vazio, exclusão de vínculos de treinamento do legado) | Aprovado |
| Manutenção — inclusão | Aprovado |
| Manutenção — edição | Aprovado |
| Manutenção — destino por grupo | Aprovado |
| Manutenção — exclusão lógica (fluxo padrão, permissão completa) | Aprovado |
| Mensagens de sucesso/erro | Aprovado com ressalva (ver Achado 3) |
| Matriz de permissões — Cenário A | **Reprovado** (Achado 2) |
| Matriz de permissões — Cenário B | Aprovado |
| Matriz de permissões — Cenário C | Aprovado |
| Matriz de permissões — Cenário D | Aprovado |
| Matriz de permissões — Cenário E | **Reprovado** (Achado 1) |
| Troca de funcionário e compatibilidade de URL direta | Aprovado |
| Ausência de mistura de vínculos entre funcionários ou com o módulo de treinamento | Aprovado |
| Limpeza e restauração (permissões, Excel/PDF, ausência de exclusão física) | Aprovado |

### Resultado final: **APROVADO COM RESSALVAS**

O fluxo de consulta, inclusão, edição, exclusão lógica (via fluxo padrão) e troca de contexto entre funcionários funciona corretamente e sem mistura de dados. A matriz de permissões restritivas apresenta dois achados funcionais (Cenários A e E) decorrentes da mesma condição de exibição no código-fonte, que devem ser corrigidos antes da liberação plena do módulo para perfis com combinações parciais de permissão. Em nenhum dos casos houve risco à integridade dos dados, pois o backend aplicou corretamente as regras de autorização mesmo quando a interface não as refletia.

---

**Responsável:** QA (Claude, em nome de Paulo)
**Data/hora:** 22/08/2026
**Evidências:** capturas de tela, verificações de DOM e consultas diretas ao banco (phpMyAdmin) realizadas durante a execução (não anexadas neste documento)
