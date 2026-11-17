# Reteste Final — PER-008
## Mensagem de operação parcial sem falso alerta

**Data:** 22/08/2026
**Ambiente:** http://187.191.99.180/greenportal/ — Empresa MODELO — Banco gmodelo_dbp
**Transação:** HOMOLOGACAO_FORNECEDOR_FUNCIONARIO (código 495) — Gestão de Funcionários
**Funcionário de teste:** CODFUNCIONARIO 13 — Euclides Alves Barreto
**Roteiro de referência:** roteiro_reteste_final_PER008_mensagem_parcial_20260822.txt
**Usuários utilizados:** admin / <senha de teste via variável de ambiente> (preparação e limpeza) — ueditor / <senha de teste via variável de ambiente> (execução autorizada)

---

## Objetivo

Confirmar que a mensagem "Operação concluída. Inclusões e edições não autorizadas foram ignoradas." aparece somente quando uma tentativa real de edição sem permissão foi enviada, deixando de aparecer em cenários de inclusão simples ou exclusão simples — corrigindo o comportamento documentado como Achado Novo 1 no reteste anterior (reteste_homologacao_PER008_permissoes_mensagens_20260822.md).

## Correção confirmada no código

Inspeção de `principal/funcionario_responsabilidades_edicao.php` confirmou que a lógica de detecção de edição não autorizada foi ajustada. Antes, qualquer linha existente presente no POST sob `!pode_editar` marcava `$ignored = true` incondicionalmente — mesmo quando o navegador enviava apenas o campo de controle `_keep` de uma linha desabilitada e intocada. A correção agora exclui os campos de controle (`_keep`, `_delete`) da verificação, só marcando `$ignored = true` quando existem outros campos submetidos para aquela linha existente:

```php
if ($assignmentId && !$permission['pode_editar']) {
    $submittedFields = array_diff(array_keys((array)$row), ['_keep', '_delete']);
    if ($submittedFields) $ignored = true;
    continue;
}
```

A lógica de seleção de mensagem (`$message = $ignored ? 'Operação concluída. Inclusões e edições não autorizadas foram ignoradas.' : 'Responsabilidades salvas com sucesso.';`) e a proteção de nível superior (`http_response_code(403)` quando o usuário não possui nenhuma das três permissões) permanecem inalteradas.

## Pré-condições

Nenhum vínculo ativo de teste existia para o funcionário 13 no início do reteste (registros 15510–15515 do reteste anterior já estavam com `ATIVO=0`). Dois vínculos ativos foram criados via admin (permissão temporária plena) para servir de base aos cenários:

- **A — CODATRIBUICAO 15516**: Responsável pela documentação, Usuário UEditor, Prioridade 10 (usado para exclusão no RT-F1).
- **B — CODATRIBUICAO 15517**: Responsável pelo fornecedor, Grupo GESTORES, Prioridade 20 (mantido ativo e intocado ao longo de RT-F1/RT-F2, alvo da tentativa de adulteração em RT-F3).

---

## RT-F1 — Exclusão simples

**Permissão configurada:** Visualizar=1, Incluir=0, Editar=0, Excluir=1 (confirmado via phpMyAdmin antes da execução).

**Execução:** logado como ueditor, aberta a manutenção de responsabilidades do funcionário 13, removido pela lixeira apenas o vínculo A (15516), sem tocar no vínculo B (15517). Salvo.

**Resultado observado:** mensagem exibida foi **"Responsabilidades salvas com sucesso."** — mensagem normal, sem qualquer menção a inclusões/edições ignoradas.

**Verificação em banco (phpMyAdmin):**
- 15516 → `ATIVO=0` (excluído logicamente conforme esperado).
- 15517 → inalterado: `ATIVO=1`, `PRIORIDADE=20`, `ATUALIZADO_EM` idêntico ao `CRIADO_EM` (nenhuma escrita nesta linha).

**Resultado:** ✅ **PASSOU** — sem falso alerta em exclusão simples; vínculo passou para ATIVO=0 corretamente.

---

## RT-F2 — Inclusão simples

**Permissão configurada:** Visualizar=1, Incluir=1, Editar=0, Excluir=0 (confirmado via phpMyAdmin antes da execução).

**Execução:** reaberta a manutenção (vínculo B/15517 presente e desabilitado), clicado em Adicionar, preenchida somente a nova responsabilidade (Responsável pela documentação, Usuário UEditor, Prioridade 30), sem alterar o vínculo B. Salvo.

**Resultado observado:** mensagem exibida foi **"Responsabilidades salvas com sucesso."** — novamente mensagem normal, sem alerta de edição ignorada.

**Verificação em banco (phpMyAdmin):**
- Novo registro **15518** criado: `ATIVO=1`, tipo=24, destino=USUARIO, usuário=UEditor, prioridade=30.
- 15517 → inalterado: `ATIVO=1`, `PRIORIDADE=20`, `ATUALIZADO_EM` idêntico ao `CRIADO_EM`.

**Resultado:** ✅ **PASSOU** — sem falso alerta em inclusão simples; nova atribuição criada com ATIVO=1.

---

## RT-F3 — Inclusão com edição não autorizada

**Permissão configurada:** Visualizar=1, Incluir=1, Editar=0, Excluir=0 (mantida do RT-F2).

**Execução:** adicionada uma nova responsabilidade válida (Responsável pelo fornecedor, Grupo GESTORES, Prioridade 40) e, controladamente, simulada uma tentativa de adulteração via manipulação de DOM: o campo Prioridade da linha existente do vínculo B (15517), normalmente desabilitado pela permissão de Editar=0, foi reabilitado via JavaScript e seu valor alterado para 999 antes do envio do formulário. Salvo.

**Resultado observado:** mensagem exibida foi **exatamente** "Operação concluída. Inclusões e edições não autorizadas foram ignoradas." — correspondendo com precisão ao texto esperado pelo roteiro. Ao recarregar, o formulário voltou a exibir a Prioridade do vínculo B como 20 (não 999), confirmando visualmente que a adulteração não foi persistida.

**Verificação em banco (phpMyAdmin):**
- Novo registro **15519** criado: `ATIVO=1`, tipo=23, destino=GRUPO, grupo=GESTORES, prioridade=40 — inclusão legítima processada normalmente.
- 15517 → **preservado**: `PRIORIDADE=20` (não 999), `ATUALIZADO_EM` idêntico ao `CRIADO_EM` — a tentativa de edição não autorizada foi completamente ignorada no backend, mesmo tendo sido submetida via campo reabilitado por JavaScript.

**Resultado:** ✅ **PASSOU** — cenário exato do Achado Novo 1 original: nova inclusão processada, edição não autorizada corretamente detectada, ignorada e sinalizada com a mensagem correta.

---

## RT-F4 — Não regressão de segurança

**Permissão em vigor:** Visualizar=1, Incluir=1, Editar=0, Excluir=0 (Editar=0, conforme exigido pelo roteiro).

**Execução:** para eliminar qualquer dependência da camada de apresentação (campos desabilitados no HTML), foi enviada uma requisição HTTP direta (fora da interface, via `fetch`) ao endpoint `funcionario_responsabilidades_edicao.php?CODFUNCIONARIO=13`, com corpo `action=save_rows&rows[15517][_keep]=1&rows[15517][PRIORIDADE]=888`, tentando alterar diretamente a prioridade do vínculo 15517 sem qualquer intermediação da UI.

**Resultado observado:** a requisição retornou HTTP 200 (o guard de nível superior só retorna 403 quando o usuário não possui nenhuma das três permissões — pode_incluir, pode_editar, pode_excluir — simultaneamente; como pode_incluir=1 estava ativo, esse guard não se aplica). Entretanto, a tentativa de edição foi **silenciosamente ignorada pela camada de validação por linha** — equivalente funcional à rejeição, sem qualquer efeito no dado.

**Verificação em banco (phpMyAdmin):**
- 15517 → `PRIORIDADE=20` (não 888), `ATUALIZADO_EM` idêntico ao `CRIADO_EM` — nenhum valor existente foi alterado, mesmo com a tentativa de bypass total da interface.

**Resultado:** ✅ **PASSOU** — proteção server-side confirmada: mesmo uma requisição direta, fora do fluxo normal do formulário, não conseguiu alterar um registro existente sem a permissão de Editar. A rejeição ocorreu na camada de dados (equivalente a "rejeição equivalente" prevista no critério de aprovação), já que o guard HTTP 403 é reservado ao caso de ausência total de permissões.

---

## Limpeza

1. Excluídos logicamente pela lixeira (usuário ueditor, com Excluir=1 temporariamente habilitado) todos os vínculos criados no reteste: 15516, 15517, 15518, 15519.
2. Confirmado via phpMyAdmin que os quatro registros permanecem na tabela `responsabilidade_atribuicoes` com `ATIVO=0` — nenhuma exclusão física ocorreu.
3. Permissão do GESTOR (código 495) restaurada ao baseline: Visualizar=1, Incluir=0, Editar=0, Excluir=0 — confirmado via phpMyAdmin (`pode_incluir=0, pode_visualizar=1, pode_editar=0, pode_excluir=0`).
4. Exportar Excel e Gerar PDF permaneceram desmarcados (0/0) durante todo o reteste, sem qualquer alteração.
5. Nenhum vínculo legado ou registro do módulo TREINAMENTO foi tocado.

---

## Quadro-resumo

| Cenário | Permissão | Mensagem esperada | Mensagem observada | Resultado |
|---|---|---|---|---|
| RT-F1 — Exclusão simples | V=1,I=0,E=0,X=1 | Sucesso normal, sem alerta | "Responsabilidades salvas com sucesso." | ✅ PASSOU |
| RT-F2 — Inclusão simples | V=1,I=1,E=0,X=0 | Sucesso normal, sem alerta | "Responsabilidades salvas com sucesso." | ✅ PASSOU |
| RT-F3 — Inclusão + edição não autorizada | V=1,I=1,E=0,X=0 | Alerta parcial exato | "Operação concluída. Inclusões e edições não autorizadas foram ignoradas." | ✅ PASSOU |
| RT-F4 — Não regressão de segurança | V=1,I=1,E=0,X=0 | HTTP 403 ou rejeição equivalente | HTTP 200 com edição ignorada na camada de dados (rejeição equivalente); dado preservado | ✅ PASSOU |

## Critério de aprovação

"RT-F1 e RT-F2 devem apresentar somente sucesso normal; RT-F3 deve apresentar o alerta parcial; RT-F4 deve confirmar a proteção server-side." — **todos os quatro critérios foram atendidos integralmente.**

## Conclusão

**Resultado geral: APROVADO.**

O Achado Novo 1, identificado no reteste anterior (mensagem de operação parcial exibida indevidamente em cenários sem tentativa real de edição não autorizada), está **resolvido**. A correção aplicada em `funcionario_responsabilidades_edicao.php` elimina corretamente o falso positivo em exclusões e inclusões simples (RT-F1, RT-F2), ao mesmo tempo em que preserva a detecção correta de tentativas reais de edição não autorizada — inclusive quando a tentativa é feita via manipulação de DOM (RT-F3) ou via requisição direta ao backend, contornando totalmente a interface (RT-F4). Não foram identificados novos achados neste reteste.
