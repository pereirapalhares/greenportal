# Relatório de Diagnóstico — Busca em "Manutenção Legal / Legislação"
**Aplicação:** GreenPortal — http://187.191.99.180/greenportal
**Usuário de teste:** admin (perfil ADMINISTRADOR, Empresa Modelo)
**Tela testada:** Manutenção Legal → Legislação (`manutencaolegal_legislacao.php`)
**Volume de dados:** 37.945 registros — grid de grande volume, ideal para stress-test de busca/paginação
**Data:** 16/08/2026

---

## 1. Resumo executivo

A busca em si funciona corretamente no backend — tanto a busca geral quanto a busca por coluna retornam resultados precisos e corretos. Porém, foram encontrados **três problemas sérios e interligados** na camada de exibição/paginação que se manifestam justamente quando a busca é usada em uma grid deste volume: o **contador de registros no rodapé nunca reflete o resultado da busca** (sempre mostra o total da base inteira), a **paginação não é reiniciada para a página 1 ao aplicar um novo filtro** (podendo deixar a grid completamente vazia sem explicação), e **cliques rápidos e sucessivos no botão "Próxima" disparam requisições concorrentes** que podem travar a tela e fazer o filtro de busca ser perdido silenciosamente.

---

## 2. Problemas encontrados

### 2.1 [ALTO] Contador de registros no rodapé não reflete o resultado da busca
**Passos para reproduzir:**
1. Na tela "Legislação", digitar "ambiental" no campo "Buscar no grid".
2. Aguardar o resultado.

**Resultado esperado:** o rodapé deveria mostrar o número real de registros que contêm "ambiental" (uma fração pequena dos 37.945 totais).

**Resultado observado:** o rodapé continua mostrando **"37945 registros · Página 1"** — o total da base inteira, sem qualquer filtro aplicado — mesmo com a grid exibindo corretamente apenas resultados que contêm "ambiental" (confirmado: **25 de 25 linhas exibidas continham "ambient"** no texto).

**Evidência adicional:** o mesmo problema se repete com uma busca ainda mais específica — filtrando a coluna "Código" por "LB0001" (que retorna exatamente **1 registro real**), o rodapé continua mostrando "37945 registros".

**Impacto:** o usuário não tem como saber quantos resultados sua busca realmente encontrou, o que é especialmente enganoso em uma base deste tamanho — ele pode achar que sua busca falhou ou não filtrou nada, quando na verdade funcionou perfeitamente.

**Hipótese de causa:** o componente de grid provavelmente busca a contagem total uma única vez no carregamento inicial da tela (sem filtro) e nunca a atualiza a cada nova busca/filtro — o valor de "37945" parece estar fixado desde o primeiro carregamento da página, independente das requisições subsequentes com `busca=...` que claramente estão sendo enviadas e processadas corretamente pelo backend (a lista de resultados muda, mas o contador não acompanha).

### 2.2 [CRÍTICO] Paginação não é reiniciada para a página 1 ao aplicar um novo filtro
**Passos para reproduzir:**
1. Buscar "ambiental" e navegar até uma página avançada (ex.: página 7, usando "Próxima" repetidamente).
2. Sem voltar à página 1, aplicar um novo filtro diferente (ex.: buscar "LB0001" na coluna "Código").

**Resultado esperado:** ao aplicar um novo filtro, a grid deveria automaticamente voltar para a página 1 dos novos resultados.

**Resultado observado:** a grid permanece na **página 7** — como o novo filtro ("LB0001") só tem 1 resultado real, a página 7 não existe para esse conjunto, e **a grid fica completamente vazia**, sem nenhuma mensagem explicando o motivo. Só foi possível ver o resultado correto após clicar manualmente em "Anterior" para retornar à página 1.

**Impacto:** este é o problema mais grave encontrado — qualquer usuário que aplique uma busca mais específica depois de já ter navegado algumas páginas em uma busca anterior (um fluxo de uso natural: "busquei algo amplo, agora vou refinar") corre o risco de ver uma tela vazia e concluir, erroneamente, que não há resultados — quando na verdade os dados existem, só não estão na página em que o usuário "ficou parado".

**Recomendação:** ao detectar que o termo de busca (geral ou por coluna) mudou, resetar o parâmetro de página para 1 antes de disparar a nova consulta.

### 2.3 [ALTO] Cliques rápidos em "Próxima" disparam requisições concorrentes e podem travar a tela
**Passos para reproduzir:**
1. Com uma busca ativa ("ambiental"), clicar em "Próxima" repetidas vezes em sequência rápida (sem aguardar cada página carregar completamente).

**Resultado observado:** a inspeção de rede confirmou que a **página 5 foi solicitada 4 vezes** (2 requisições GET + 2 POST) devido aos cliques não terem sido bloqueados enquanto a requisição anterior ainda estava em andamento. Isso fez a tela ficar temporariamente **em branco, presa em estado de "Carregando..." indefinidamente**, mesmo depois de aguardar vários segundos — só voltou a responder após uma nova interação.

**Impacto:** além do desperdício de requisições ao servidor (múltiplas chamadas idênticas), esse comportamento parece ser o gatilho que, combinado com o problema 2.2, faz o filtro de busca ser perdido de forma ainda mais confusa (a tela "recupera" mostrando dados de uma página sem filtro nenhum).

**Recomendação:** desabilitar o botão "Próxima"/"Anterior" (e, de forma mais ampla, qualquer ação de navegação/busca) enquanto uma requisição da mesma natureza já está em andamento, evitando o disparo de chamadas concorrentes redundantes.

---

## 3. O que funciona corretamente (positivo a destacar)

- **A busca geral em si é precisa**: confirmado por amostragem completa (25/25 linhas continham o termo buscado).
- **A busca por coluna individual também é precisa**: confirmado com "LB0001" no campo "Código", retornando exatamente o registro correto.
- **Tooltips nos botões de ação estão implementados corretamente** nesta tela — diferente do que foi observado nas telas de "Segurança" em rodada anterior de testes, aqui os botões desabilitados (Duplicar, Excluir) mostram tooltips claros como "Selecione uma linha para duplicar"/"Selecione uma linha para excluir".
- A consulta ao banco (não filtro local) foi reconfirmada mesmo neste volume de 37.945 registros, com tempo de resposta aceitável para cada requisição individual.

---

## 4. Recomendações consolidadas, por prioridade

| # | Recomendação | Prioridade |
|---|---|---|
| 1 | Resetar a página atual para 1 sempre que um novo termo de busca (geral ou por coluna) for aplicado, antes de disparar a consulta | Crítica |
| 2 | Bloquear/debounce nos botões de paginação ("Próxima"/"Anterior") enquanto uma requisição da mesma natureza estiver em andamento, para evitar chamadas concorrentes duplicadas | Alta |
| 3 | Corrigir o contador de registros no rodapé para refletir o total real de resultados da busca/filtro ativo, não o total fixo da base completa | Alta |
| 4 | Ao encontrar uma página vazia como resultado de uma navegação inválida (ex.: página além do total de resultados), exibir uma mensagem clara ao usuário ("Nenhum resultado nesta página" ou redirecionar automaticamente para a última página válida), em vez de simplesmente mostrar a grid em branco | Média |

---

## 5. Conclusão
A camada de busca desta tela está tecnicamente correta em sua função central (consultar e filtrar os dados certos), mas a camada de paginação que a envolve tem falhas que comprometem a experiência de uso justamente no cenário mais comum e valioso desta tela: uma base com quase 38 mil registros, onde busca e paginação são essenciais para o dia a dia do usuário. Os três problemas encontrados (contador incorreto, página não resetada, requisições concorrentes) têm uma causa provável em comum — a lógica de paginação não trata adequadamente o "estado da busca" como algo que deve reiniciar a navegação, e não possui proteção contra múltiplas interações rápidas do usuário. Corrigir o item 1 (resetar a página ao buscar) provavelmente resolve a maior parte do impacto percebido pelo usuário, mesmo que os itens 2 e 3 também mereçam atenção.
