# Relatório de Diagnóstico — Grids em Lote (Menu "Segurança")
**Aplicação:** GreenPortal — http://187.191.99.180/greenportal
**Usuário de teste:** admin (perfil ADMINISTRADOR, Empresa Modelo)
**Data:** 15/08/2026
**Diretriz aplicada:** `grids_em_lote_diretrizes.txt` (5 seções: Padrão, Paginação e Filtros, Colunas, Operações, Permissões)
**Telas testadas:** Menu, Grupos, Usuários, Formulário, Perfis, Lista, Permissões (todos os 7 itens sob "Segurança")

---

## 1. Resumo executivo

Das 5 seções da diretriz, **3 estão bem implementadas de forma consistente** em todas as 7 telas (Paginação e Filtros, a maior parte de Operações, e Chave Primária Oculta dentro de Colunas). Foram encontrados **4 problemas reais**, sendo um deles crítico (persistência de colunas não funciona em nenhuma tela testada) e um de gravidade alta (botão de exportação travado permanentemente em pelo menos duas transações). Também foi identificada uma inconsistência de padrão visual (ordem dos botões da toolbar) entre as telas, e uma limitação de teste (não foi possível validar a diretriz de Permissões com um perfil restrito, por falta de credenciais de um usuário não-administrador).

---

## 2. Resultado por seção da diretriz

### 2.1 PADRÃO — parcialmente conforme
| Item da diretriz | Status | Observação |
|---|---|---|
| Título/contexto da tela | ✅ | Nome da aba reflete a transação em todas as telas |
| Busca geral | ✅ | Campo "Buscar no grid" presente em todas as 7 telas |
| Busca por coluna | ✅ | Filtros individuais por coluna presentes em todas as telas |
| Botões de operação | ⚠️ | Presentes em todas as telas, mas **a ordem varia entre telas** (ver item 3.1) |
| Colunas configuráveis | ✅ | Botão "Colunas" presente em todas as telas |
| Modal de carregamento | ⚠️ | Não testado de forma conclusiva nesta rodada (recomenda-se revalidar) |

### 2.2 PAGINAÇÃO E FILTROS — conforme
| Item | Status | Evidência |
|---|---|---|
| 25 registros por página | ✅ | Confirmado exatamente em "Grupos" (25 na página 1, 10 na página 2, de 35 totais) e em "Usuários" (25 de 2.526 totais) |
| Total exibido no rodapé | ✅ | "35 registros · Página 1", "2.526 registros · Página 1", etc., presente em todas as telas |
| Filtros consultam o banco (não é filtro local) | ✅ | Confirmado via inspeção de rede: busca por "segur" gerou `GET setup_menu.php?busca=segur&pagina=1`, retornando 8 registros corretos do servidor |
| Busca aguarda pausa de digitação (debounce) | ✅ | Confirmado: digitar "segur" letra a letra gerou apenas 1 requisição ao servidor com o termo completo, não uma por tecla |

### 2.3 COLUNAS — problema crítico encontrado
| Item | Status | Evidência |
|---|---|---|
| Colunas ordenáveis | ✅ | Ícones de ordenação (↕) presentes em todos os cabeçalhos |
| Colunas redimensionáveis | ⚠️ | Não testado de forma conclusiva nesta rodada |
| Colunas ocultáveis | ✅ | Modal "Colunas do grid" com checkboxes funcional para exibir/ocultar |
| Chave primária oculta por padrão | ✅ | Confirmado em "Grupos" e "Usuários": coluna "Código" vem desmarcada por padrão no seletor de colunas |
| **Persistência de preferência por usuário+transação** | ❌ | **Não funciona.** Ver item 3.2 |

### 2.4 OPERAÇÕES — majoritariamente conforme, com uma inconsistência
| Item | Status | Evidência |
|---|---|---|
| Novo/Editar/Duplicar/Salvar/Excluir agem na linha do grid | ✅ | Edição inline testada com sucesso (duplo-clique em célula) |
| Excluir só habilita após seleção | ✅ | Confirmado em "Menu" e "Grupos": `disabled: true` sem seleção, `disabled: false` após clicar em uma linha |
| Salvar só habilita após incluir/alterar | ✅ | Confirmado: `disabled: true` no estado inicial, `disabled: false` após editar um campo |
| Confirmação antes de operações relevantes | ✅ | Modal "Confirmação — Confirma a gravação das alterações?" testado e funcionando em "Menu" |
| Mensagens simples, traduzidas, não técnicas | ✅ | "Menus salvos com sucesso." — direto e sem jargão técnico |
| **"Duplicar" não segue o mesmo padrão de "Excluir"** | ⚠️ | Ver item 3.3 |

### 2.5 PERMISSÕES — não foi possível validar completamente
| Item | Status | Evidência |
|---|---|---|
| Cada grid valida permissão do perfil (incluir/editar/excluir/exportar) | ⚠️ | A tela "Permissões" confirma que o sistema **modela** essa granularidade (ex.: perfil ADM_DA_EMPRESA está com todas as permissões desmarcadas para a transação SEGURANCA) — mas não foi possível confirmar em tempo de execução, por não haver credenciais disponíveis de um usuário com esse perfil restrito |
| Botões sem permissão ficam desabilitados com tooltip (PT/EN/ES) | ❌ | **Nenhum botão testado tem `title`/`aria-label` configurado.** Isso não pôde ser atribuído com certeza a "falta de permissão" (o admin tem acesso total), mas a ausência de qualquer atributo de tooltip nos botões, mesmo quando desabilitados por outro motivo (seleção/exportação travada), é um indício de que a infraestrutura de tooltip pode não estar implementada de forma alguma nesses componentes |

---

## 3. Detalhamento dos problemas encontrados

### 3.1 [MÉDIO] Inconsistência na ordem dos botões da toolbar entre telas
**Descrição:** a ordem dos botões de operação varia entre as 7 telas testadas, mesmo sendo o mesmo conjunto de ações:
- **Menu, Grupos, Usuários:** Duplicar → Salvar → Novo → Excluir → Recarregar → Exportar Excel → Colunas
- **Formulário, Perfis, Permissões:** Salvar → Novo → Duplicar → Excluir → Recarregar → Exportar Excel → Colunas
- **Lista:** Salvar → Novo → Duplicar → Excluir → Exportar Excel → Colunas → Recarregar

**Impacto:** viola o princípio de padrão consistente esperado pela diretriz (seção "PADRAO"). Usuários que trabalham em múltiplas telas precisam reaprender a posição dos botões a cada transação, aumentando o risco de clique acidental no botão errado (por exemplo, "Salvar" e "Duplicar" trocam de posição relativa entre os dois grupos de telas).

**Recomendação:** padronizar a ordem de renderização dos botões da toolbar em um único componente/configuração compartilhada, em vez de cada tela declarar sua própria ordem.

### 3.2 [CRÍTICO] Preferência de colunas não é persistida
**Descrição:** ao abrir o modal "Colunas do grid" (testado em "Grupos"), marcar a coluna "Código" para exibição, e clicar em "Salvar colunas":
- O modal **não fecha** (permanece aberto mesmo após múltiplos cliques no botão).
- **Nenhuma requisição de rede é disparada** ao clicar em "Salvar colunas" (confirmado via inspeção do tráfego de rede, log limpo imediatamente antes do clique).
- **Nenhuma chave de `localStorage`** relacionada a preferências de colunas do GreenPortal foi encontrada (as únicas chaves presentes eram resíduos de outra aplicação testada anteriormente na mesma origem).

**Impacto:** a funcionalidade de personalização de colunas, exigida explicitamente pela diretriz ("persistência por usuário+transação"), está **completamente não-funcional** em pelo menos a tela testada ("Grupos"). Como o mesmo componente de "Colunas" é usado nas 7 telas, é provável que o mesmo problema afete todas elas.

**Recomendação:** investigar o handler do botão "Salvar colunas" — a ausência total de requisição de rede sugere que o evento de clique não está conectado a nenhuma lógica de persistência (nem local, nem remota), possivelmente um problema de binding de evento semelhante a outros já identificados em investigações anteriores desta mesma família de aplicações.

### 3.3 [ALTO] Botão "Exportar Excel" travado permanentemente desabilitado em algumas transações
**Descrição:** nas telas "Menu" e "Usuários", o botão "Exportar Excel" aparece desabilitado desde o carregamento inicial, sem qualquer ação do usuário. Investigando a rede, identificou-se que o frontend faz polling constante ao endpoint:
```
GET /principal/export_status.php?transacao={nome_da_transacao}
→ {"ok":true,"active":true}
```
O campo `active: true` nunca muda para `false`, mantendo o botão bloqueado indefinidamente. Em contraste, as telas "Grupos", "Formulário", "Perfis", "Lista" e "Permissões" retornam esse mesmo endpoint com um estado que permite o botão ficar habilitado.

**Impacto:** os usuários dessas duas transações específicas ficam permanentemente impedidos de exportar dados para Excel, sem nenhuma mensagem explicando o motivo (o botão simplesmente aparece cinza, sem tooltip).

**Hipótese de causa:** provavelmente há um registro de "job de exportação" no backend que ficou marcado como "ativo" para essas transações (talvez de um teste ou uso anterior que falhou ou não finalizou corretamente) e nunca foi limpo/expirado. Isso é consistente com um padrão observado em outras investigações desta aplicação: processos assíncronos (exportação, neste caso) que não tratam corretamente o caminho de erro/timeout, deixando o estado "preso" indefinidamente.

**Recomendação:**
1. Adicionar um mecanismo de expiração/timeout para o status de exportação "ativo" (ex.: se não houver atualização em X minutos, considerar a exportação anterior como abandonada e liberar o botão).
2. Investigar e limpar manualmente os registros travados nas transações `setup_menu` e `seguranca_usuarios` para desbloquear os usuários afetados imediatamente.
3. Adicionar uma mensagem visível (tooltip ou texto auxiliar) explicando por que o botão está desabilitado, em vez de deixá-lo simplesmente cinza sem explicação.

### 3.4 [BAIXO] "Duplicar" não segue o mesmo padrão visual de "Excluir"/"Salvar"
**Descrição:** diferente de "Salvar" e "Excluir" (que ficam com o atributo `disabled` ativo quando a ação não é aplicável), o botão "Duplicar" permanece sempre com aparência habilitada. Ao clicar sem uma linha selecionada, um diálogo de confirmação aparece com a mensagem "Selecione uma linha para duplicar." em vez do botão simplesmente não fazer nada ou estar desabilitado.

**Impacto:** funcionalmente a ação é bloqueada corretamente (não há risco de erro), mas a experiência é inconsistente — o usuário não tem como saber, olhando a tela, que "Duplicar" também depende de uma seleção prévia, já que sua aparência não muda como a de "Excluir".

**Recomendação:** por consistência com o restante da interface, considerar desabilitar visualmente o botão "Duplicar" da mesma forma que "Excluir", em vez de depender de um diálogo de alerta reativo ao clique.

---

## 4. Limitações desta rodada de teste
- Não foi possível testar a diretriz de "Permissões" (seção 5) com um usuário de perfil restrito, por não haver credenciais de outro usuário disponíveis nesta sessão. A tela "Permissões" confirma que o sistema modela essa granularidade corretamente no cadastro, mas o comportamento em tempo de execução (botões desabilitados com tooltip específico) não pôde ser confirmado.
- Redimensionamento de colunas e o comportamento exato do "modal de carregamento" mencionados na diretriz não foram testados de forma conclusiva nesta rodada.

---

## 5. Recomendações consolidadas, por prioridade

| # | Recomendação | Prioridade |
|---|---|---|
| 1 | Corrigir o botão "Salvar colunas" para efetivamente persistir a preferência (nem local nem remotamente está funcionando hoje) | Crítica |
| 2 | Investigar e corrigir o estado "travado" de exportação nas transações `setup_menu` e `seguranca_usuarios`, e adicionar expiração automática para esse estado em geral | Alta |
| 3 | Padronizar a ordem dos botões da toolbar entre todas as telas de grid | Média |
| 4 | Adicionar tooltips (`title`/`aria-label`) a todos os botões da toolbar, cobrindo tanto os casos de "sem seleção" quanto "sem permissão" — hoje nenhum botão testado tem esse atributo | Média |
| 5 | Uniformizar o comportamento de "Duplicar" com o padrão de desabilitação visual já usado em "Excluir" | Baixa |
| 6 | Em uma próxima rodada, obter credenciais de um usuário com perfil restrito (ex.: ADM_DA_EMPRESA, que já está configurado sem permissões para a transação SEGURANCA) para validar em tempo de execução a diretriz de Permissões | — (ação de preparação para teste, não de código) |

---

## 6. Conclusão
A base estrutural da diretriz de grids em lote está bem implementada nas 7 telas testadas — paginação, filtros server-side, debounce de busca, e a maior parte do fluxo de confirmação/mensagens funcionam de forma consistente e correta. Os problemas encontrados são pontuais e concentrados em duas áreas específicas (persistência de colunas e status de exportação), que parecem compartilhar a característica de serem funcionalidades "secundárias" da grid — sugerindo que a atenção de desenvolvimento e teste priorizou corretamente o fluxo principal de CRUD, mas deixou lacunas nas funcionalidades auxiliares de personalização e exportação.

## 7. Revalidação técnica posterior

Foi realizada uma auditoria do código atual após este diagnóstico. Os seguintes ajustes foram aplicados:

- A barra de ações dos grids passou a ser reorganizada para manter a ordem Salvar, Novo, Duplicar, Excluir, Recarregar, Exportar Excel e Colunas.
- O botão Duplicar passou a iniciar desabilitado quando nenhuma linha estiver selecionada, seguindo o mesmo comportamento do botão Excluir.
- Os botões dependentes de seleção passaram a apresentar orientação no idioma ativo.
- O endpoint de status da exportação passou a expirar solicitações SOLICITADA ou PROCESSANDO após 30 minutos, evitando que uma falha deixe a exportação bloqueada indefinidamente.
- Os handlers de persistência de colunas foram confirmados nas sete transações. A confirmação final no navegador ainda deve ser repetida no ambiente publicado, pois o relatório original foi executado em uma URL remota e o código local pode estar em versão diferente.

Permanecem como testes recomendados: redimensionamento real das colunas, validação visual do modal de carregamento e execução com um usuário de perfil restrito.
