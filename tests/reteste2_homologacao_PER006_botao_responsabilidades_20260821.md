# Nota de Reteste — PER-006
## Confirmação de acesso pelo botão "Responsabilidades"

**Data do teste:** 21/08/2026
**Roteiro de referência:** roteiro_homologacao_PER006_responsabilidades_monitoramento_20260821.txt
**Ambiente:** Green Portal — instância local (http://localhost/greenportal/), tenant `gmodelo_dbp`
**Responsável pela execução:** QA (Claude, em nome de Paulo)
**Escopo:** verificação focada, solicitada após uma segunda correção do desenvolvedor — não substitui nem revalida a totalidade do roteiro PER-006, já coberta pelos relatórios anteriores.

---

## 1. Objetivo

Confirmar, exclusivamente pelo fluxo normal da interface (sem uso de URL direta), que o botão "Responsabilidades" da grid de Medição e Monitoramento agora abre corretamente a tela de consulta contextual "Responsabilidades do monitoramento", após a segunda correção aplicada pelo desenvolvedor.

## 2. Contexto

Os dois retestes anteriores identificaram, em sequência, dois bugs distintos que impediam esse acesso:

1. **Botão nunca habilitava** (relatório original) — corrigido pelo desenvolvedor com a adição da classe `menu-grid-fragment` ao wrapper da tela e ajuste do seletor em `medicao-monitoramento-responsabilidades.js`.
2. **Clique não abria nada mesmo com o botão habilitado** (primeiro reteste) — causado pela leitura de `.text()` em uma célula que contém apenas um `<input readonly>` (identificador `CODMONITORAMENTO`), sempre retornando vazio. Corrigido pelo desenvolvedor conforme sugestão registrada no reteste anterior.

Esta nota documenta a verificação pontual de que a combinação das duas correções restabelece o fluxo completo.

## 3. Confirmação por inspeção de código

Antes do teste em navegador, o arquivo `assets/js/medicao-monitoramento-responsabilidades.js` foi reobtido do ambiente e inspecionado. A leitura do identificador do monitoramento agora é feita como:

```js
var $cell = $row.find('[data-column="CODMONITORAMENTO"]'),
    id = $.trim(String($cell.find('input').val() || $cell.text() || ''));
```

Ou seja, o valor é lido do `input` da célula, com `.text()` apenas como reserva — exatamente a correção sugerida no reteste anterior.

## 4. Teste em navegador (fluxo real, sem URL direta)

Executado com a conta `admin` (perfil ADMINISTRADOR, acesso irrestrito), pois o objetivo aqui é mecânico — confirmar a abertura da tela — e não repetir a matriz de permissões, já coberta nos relatórios anteriores com a conta `ueditor`.

| Passo | Ação | Resultado |
|---|---|---|
| 1 | Menu lateral → Módulo Legal → Requisitos legais | Grid principal aberta normalmente |
| 2 | Localizar e selecionar o requisito Código = EA0003 (requisito 71) | Registro localizado e selecionado |
| 3 | Clicar no ícone de toolbar "Medição e Monitoramento" | Popup "Medição e Monitoramento" aberto, exibindo os monitoramentos vinculados (incluindo "asas", CODMONITORAMENTO=1) |
| 4 | Selecionar a linha "asas" na grid do popup | Linha destacada como selecionada |
| 5 | Verificar estado do botão "Responsabilidades" (inspeção de DOM) | `disabled=false` — botão habilitado corretamente após a seleção |
| 6 | Clicar no botão "Responsabilidades" | Tela contextual **"Responsabilidades do monitoramento"** abriu corretamente, exibindo o cabeçalho "Monitoramento 1 — asas" e a listagem de responsabilidades (3 históricas + 1 ativa) |

**Resultado: PASSOU**

O fluxo documentado — selecionar o monitoramento na grid, clicar em "Responsabilidades", abrir a consulta contextual — funciona corretamente de ponta a ponta, sem necessidade de URL direta ou qualquer contorno.

## 5. Observação incidental

A consulta aberta no passo 6 confirmou, como efeito colateral positivo, que o vínculo **CODATRIBUICAO = 1150** (Responsável = Paulo de Tarso) permanece em situação **ATIVA**, consistente com o estado esperado e não tocado durante este teste, em conformidade com a instrução do usuário de não excluí-lo novamente.

## 6. Limpeza

Teste puramente de consulta (nenhum dado foi incluído, editado ou excluído). O popup foi fechado ao final; nenhuma limpeza adicional foi necessária.

## 7. Conclusão

| Critério | Status |
|---|---|
| Botão "Responsabilidades" habilita após seleção da linha | Confirmado (já validado no reteste anterior, reconfirmado aqui) |
| Clique no botão abre a consulta contextual pelo fluxo normal | **Confirmado nesta verificação** |
| Vínculo CODATRIBUICAO=1150 preservado | Confirmado |

### Resultado final: **PASSOU**

Com esta confirmação, os dois bugs de interface registrados nos relatórios anteriores (`relatorio_homologacao_PER006_...md` e `reteste_homologacao_PER006_...md`) estão sanados. Este documento não substitui nem revalida a matriz completa de CRUD e permissões restritivas do roteiro PER-006, já coberta nos dois relatórios anteriores — trata-se de uma verificação pontual do ponto de acesso à tela, conforme solicitado.

**Responsável:** QA (Claude, em nome de Paulo)
**Data/hora:** 21/08/2026
**Evidências:** captura de tela e verificação de DOM realizadas durante a execução (não anexadas neste documento)
