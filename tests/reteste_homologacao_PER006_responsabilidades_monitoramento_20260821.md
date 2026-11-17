# Reteste de Homologação — PER-006
## Responsabilidades de Medição e Monitoramento

**Data do reteste:** 21/08/2026
**Roteiro de referência:** roteiro_homologacao_PER006_responsabilidades_monitoramento_20260821.txt
**Relatório anterior:** relatorio_homologacao_PER006_responsabilidades_monitoramento_20260821.txt (mantido sem alterações — registra corretamente a falha original)
**Ambiente:** Green Portal — instância local (http://localhost/greenportal/), tenant `gmodelo_dbp`
**Motivo do reteste:** verificar a correção aplicada ao bug do botão "Responsabilidades" (Seção 5 do relatório anterior), com foco especial em: (1) o botão habilitar após seleção de linha; (2) o acesso funcionar pelo fluxo normal da interface, sem navegação direta por URL; (3) a mensagem exibida em uma tentativa de inclusão sem permissão; (4) uso de um vínculo de teste novo, sem excluir novamente o vínculo 1150.

---

## 1. O que foi corrigido desde o relatório anterior

Confirmado por inspeção do código-fonte atualizado (staged do dispositivo) antes do reteste em navegador:

- `classes/ManutencaoLegalCadastroGrid.php` passou a renderizar o wrapper `<section class="setup-fragment menu-grid-fragment cadastro-legal-fragment...">` em volta da grid.
- `assets/js/medicao-monitoramento-responsabilidades.js` foi ajustado para procurar `.modulo-legal-requisitos-fragment, .menu-grid-fragment` (filtrado por `.has('[data-open-monitoramento-responsabilidades]')`), cobrindo o novo wrapper.
- `principal/monitoramento_responsabilidades_edicao.php` ganhou uma mensagem de retorno mais específica para operações parcialmente rejeitadas por falta de permissão (ver Seção 3).

## 2. PER-006 — Preparação da sessão e botão "Responsabilidades" (reteste)

Login como ueditor (perfil GESTOR, com permissão total temporária em REQUISITOS_LEGAIS para este passo). Acesso pelo menu: Módulo Legal → Requisitos legais → seleção de um requisito (código EA0003, requisito 71) → botão "Medição e Monitoramento" na barra de ferramentas → seleção do registro "asas" (CODMONITORAMENTO=1) na grid do popup.

| Verificação | Esperado | Resultado |
|---|---|---|
| Botão "Responsabilidades" sem linha selecionada | Desabilitado | PASSOU |
| Botão "Responsabilidades" com linha selecionada | Habilitado | **PASSOU — bug original corrigido** |

### Novo achado — clique no botão não abre nada (falha silenciosa)

Com o botão corretamente habilitado, o clique (testado tanto via interação real quanto via disparo do evento `click` no elemento) **não abre a tela de Responsabilidades**. Não há erro no console do navegador; a interface simplesmente não reage.

**Causa raiz confirmada:** o handler de clique em `medicao-monitoramento-responsabilidades.js` extrai o identificador do monitoramento assim:

```js
var $row = $table.find('tbody tr.selected'),
    id = $.trim($row.find('[data-column="CODMONITORAMENTO"]').text());
if (!$row.length || !id) return;
```

Porém a célula `[data-column="CODMONITORAMENTO"]` não contém texto — ela contém um `<input class="grid-input" value="1" readonly>` (mesmo padrão usado nos campos "somente leitura" do restante da grid). `.text()` em uma célula cujo conteúdo é um `<input>` retorna string vazia, então `id` fica vazio e o `return` antecipado aborta a operação silenciosamente, mesmo com uma linha corretamente selecionada e o botão habilitado.

Confirmado via inspeção do DOM: a célula tem 1 filho (`<input>`), `textContent` de comprimento 0, e o input tem `value="1"` — o dado correto existe, só não é lido da forma como o script tenta lê-lo.

**Sugestão de correção:** trocar `.text()` por algo que resolva tanto texto quanto input, por exemplo `($row.find('[data-column="CODMONITORAMENTO"] input').val() || $row.find('[data-column="CODMONITORAMENTO"]').text()).trim()`.

**Impacto:** o fluxo documentado (grid → seleção → botão Responsabilidades) continua bloqueado para o usuário final, agora por um motivo diferente e mais discreto que o anterior — o botão parece funcional (habilitado, clicável, sem erro visível), mas não faz nada.

**Workaround mantido para viabilizar o restante do reteste:** navegação direta às URLs `monitoramento_responsabilidades.php?CODMONITORAMENTO=1` e `monitoramento_responsabilidades_edicao.php?CODMONITORAMENTO=1`, exatamente como no relatório anterior.

## 3. PER-006-A/B/C/D (reteste, com vínculo de teste novo)

Executado via workaround de URL direta (fluxo normal segue bloqueado pelo achado da Seção 2), usando um vínculo de teste **novo** (CODATRIBUICAO=15506), sem tocar no vínculo 1150 (Paulo de Tarso, mantido ativo e intacto durante todo este bloco).

- **Consulta:** acessível, refletindo corretamente o estado dos vínculos existentes. PASSOU.
- **Inclusão:** novo vínculo criado (Responsável=Administrador Modelo, Prioridade=5, Válido de=21/08/2026). Mensagem "Responsabilidades salvas com sucesso." CODATRIBUICAO=15506 gerado. PASSOU.
  - *Nota de execução:* o campo Finalidade não foi definido explicitamente para "Monitoramento" como no roteiro original (ficou no valor padrão do formulário, "Medição") — desvio no procedimento de teste, não uma falha da aplicação; o campo funcionou normalmente.
- **Edição:** Prioridade alterada de 5 para 9, Principal marcado. Persistência confirmada após recarregamento completo da página. PASSOU.
- **Exclusão lógica:** vínculo 15506 excluído via lixeira + Salvar. Mensagem de sucesso consistente. Vínculo passou a HISTÓRICA; vínculo 1150 permaneceu ATIVA e inalterado durante toda a operação (confirmado antes e depois do envio do formulário). PASSOU.

## 4. PER-006-E — Cenários de permissão restritiva (reteste)

### Cenário 1 — Incluir=0, Visualizar=1, Editar=0, Excluir=0

Comportamento idêntico ao relatório anterior: consulta acessível, manutenção bloqueada com a mensagem "Seu perfil não possui permissão para alterar responsabilidades.", tentativa de POST direto retornando HTTP 403. **PASSOU.**

### Cenário 2 — Incluir=0, Visualizar=1, Editar=0, Excluir=1

Estados de DOM confirmados como no relatório anterior (campos desabilitados, Adicionar oculto, lixeira habilitada).

**Mensagem da tentativa de inclusão sem permissão — achado principal deste reteste:**

A mensagem retornada pelo servidor mudou desde o relatório anterior. Antes, uma tentativa de inclusão não autorizada resultava na mesma mensagem genérica de sucesso ("Responsabilidades salvas com sucesso."), sem qualquer indicação de que a inclusão havia sido silenciosamente ignorada. **Agora**, o servidor retorna uma mensagem específica e transparente:

> "Operação concluída. Exclusões autorizadas foram processadas; inclusões e edições não autorizadas foram ignoradas."

Isso foi confirmado tanto por uma chamada POST direta simulando uma inclusão (nenhum vínculo foi de fato criado — contagem de vínculos ativos permaneceu em 0 para a tentativa isolada) quanto pela leitura do código-fonte (`monitoramento_responsabilidades_edicao.php`, variável `$ignoredUnauthorized`, linhas 39–55).

**Observação sobre precisão da mensagem:** a lógica atual marca `$ignoredUnauthorized = true` sempre que uma linha existente é submetida sem permissão de edição — o que inclui o caso normal de uma linha apenas "mantida" (não editada) via o campo oculto `_keep` inserido por `responsabilidades-permissoes.js` para preservar linhas não tocadas durante uma exclusão. Ou seja, mesmo em uma operação de exclusão legítima sem nenhuma tentativa real de inclusão/edição, a mensagem "inclusões e edições não autorizadas foram ignoradas" aparece — o que é tecnicamente inofensivo (nada é de fato ignorado indevidamente) mas pode confundir um usuário que só excluiu um registro. Não é um bug de segurança, apenas uma imprecisão de texto que vale considerar ajustar.

**Teste funcional de exclusão (com vínculo novo, não o 1150):** um vínculo de teste dedicado (CODATRIBUICAO=15507, criado como admin) foi excluído com sucesso via o botão de lixeira e envio normal do formulário completo, preservando corretamente o vínculo 1150 (que permaneceu no formulário graças ao campo oculto `_keep`, mesmo com seus campos desabilitados). Confirmado por consulta pós-operação: 15507 passou a HISTÓRICA; 1150 permaneceu ATIVA. **PASSOU.**

**Incidente durante o teste (transparência):** na primeira tentativa de verificar a mensagem de inclusão sem permissão, foi usada uma chamada `fetch` manual contendo apenas a linha fabricada de teste, sem incluir o campo `_keep` da linha 1150. Como o endpoint desativa qualquer vínculo ativo não presente no POST quando `pode_excluir=true`, essa chamada **excluiu logicamente o vínculo 1150** — exatamente o que este reteste deveria evitar. O erro foi identificado, comunicado imediatamente, e corrigido restaurando `ATIVO=1` para CODATRIBUICAO=1150 (correção aplicada pelo usuário, já que o ambiente bloqueou a tentativa da IA de rodar o UPDATE diretamente). Nenhum dado foi perdido — o registro nunca foi fisicamente removido. O teste funcional de exclusão foi refeito corretamente em seguida (parágrafo anterior), desta vez usando o vínculo 15507 e o formulário completo, sem repetir o erro.

**Resultado do Cenário 2: PASSOU** (com o incidente acima documentado por transparência).

### Cenário 3 — Incluir=0, Visualizar=0, Editar=0, Excluir=0

Idêntico ao relatório anterior: consulta bloqueada (403), manutenção via GET bloqueada (403), manutenção via POST bloqueada (403) — todas com a mensagem "Sem permissão para consultar responsabilidades." **PASSOU.**

## 5. Limpeza

- Estado final de CODMONITORAMENTO=1: vínculo 1150 (Paulo de Tarso) ATIVA — seu estado original, restaurado após o incidente da Seção 4; vínculos 15505, 15506 e 15507 (todos criados/usados durante os testes desta e da rodada anterior) corretamente HISTÓRICOS. Nenhum vínculo de teste ficou órfão em situação ATIVA.
- Permissões da transação REQUISITOS_LEGAIS (código 306, perfil GESTOR) restauradas à linha de base: Incluir=Visualizar=Editar=Excluir não marcados, Excel e PDF inalterados (permaneceram desmarcados durante todo o reteste).
- Confirmado, com a conta ueditor, que o acesso final está bloqueado (consulta e manutenção retornando HTTP 403), consistente com o padrão de encerramento dos relatórios anteriores.

## 6. Resumo comparativo com o relatório anterior

| Item | Relatório anterior | Reteste |
|---|---|---|
| Botão "Responsabilidades" habilita após seleção | Falhava (nunca habilitava) | **Corrigido** |
| Acesso pelo fluxo normal (sem URL direta) | Bloqueado (botão nunca habilitava) | **Ainda bloqueado** — novo bug: clique não lê o ID do monitoramento (`.text()` em vez de `.val()`), falha silenciosa |
| Mensagem de inclusão sem permissão | Genérica, não indicava rejeição | **Melhorada** — mensagem específica informando o que foi ignorado (com uma imprecisão menor quando não há tentativa real de inclusão) |
| Lógica de CRUD e enforcement de permissões | Correta | Correta, reconfirmada com vínculo de teste novo |

## 7. Critérios de aceite

| Critério | Status |
|---|---|
| Botão habilita corretamente na grid | Atendido |
| Acesso à tela de Responsabilidades pelo fluxo documentado, sem URL direta | **Não atendido** — novo bug bloqueia o clique |
| Mensagem transparente em tentativa de inclusão sem permissão | Atendido |
| CRUD e permissões (Consulta/Inclusão/Edição/Exclusão, 3 cenários) | Atendido |

### Resultado final: **PASSOU COM RESSALVA**

O bug original (botão nunca habilitava) foi corrigido. Um segundo bug, mais discreto, ainda impede o fluxo documentado de acesso à tela de Responsabilidades: o clique no botão, agora habilitado, não abre a tela por uma falha na leitura do identificador do monitoramento (`.text()` em vez de `.val()` sobre uma célula que contém um input, não texto). A lógica de CRUD e o enforcement de permissões seguem corretos, e a transparência das mensagens de erro/rejeição melhorou visivelmente desde o relatório anterior.

**Responsável:** QA (Claude, em nome de Paulo)
**Data/hora:** 21/08/2026
**Evidências:** verificações de DOM, rede (fetch/status HTTP) e leitura de código-fonte realizadas durante a execução (não anexadas neste documento)
**Pendências:** corrigir a leitura do `CODMONITORAMENTO` em `medicao-monitoramento-responsabilidades.js` (usar `.val()` para o input, não `.text()`); considerar refinar a condição de `$ignoredUnauthorized` em `monitoramento_responsabilidades_edicao.php` para não disparar em exclusões legítimas sem tentativa real de inclusão/edição.
