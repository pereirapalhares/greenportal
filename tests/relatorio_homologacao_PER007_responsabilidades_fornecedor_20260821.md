# Relatório de Homologação — PER-007
## Responsabilidades na Gestão de Fornecedores

**Data do teste:** 21/08/2026
**Roteiro de referência:** roteiro_homologacao_PER007_responsabilidades_fornecedor_20260821.txt
**Ambiente:** Green Portal — instância local (http://localhost/greenportal/), tenant `gmodelo_dbp`
**Responsável pela execução:** QA (Claude, em nome de Paulo)

---

## 1. Objetivo

Validar o fluxo de Responsabilidades na Gestão de Fornecedores, tela contextual acessada a partir do módulo Homologação de Fornecedores (grid "Gestão de Fornecedores"), cobrindo consulta, inclusão, edição, exclusão lógica de vínculos de responsabilidade, preservação de vínculos legados e o comportamento do sistema sob três cenários de permissão restritiva, incluindo a validação server-side independente do estado visual da interface.

## 2. Contas utilizadas

| Conta | Perfil | Observação |
|---|---|---|
| admin | ADMINISTRADOR | Utilizada para configurar permissões e para verificações de estado |
| ueditor | GESTOR | Conta de teste para os cenários de permissão sobre a transação Gestão de Fornecedores |

Senhas reais não foram registradas neste relatório, em conformidade com a instrução do roteiro.

## 3. Permissão utilizada

A tela de Responsabilidades do Fornecedor é uma tela contextual do módulo Homologação de Fornecedores e não possui transação própria — o controle de acesso é feito inteiramente pela permissão da transação **HOMOLOGACAO_FORNECEDOR_FORNECEDOR** (código 496 na grade de Permissões, "Gestão de Fornecedores", perfil GESTOR), com os flags `pode_incluir`, `pode_visualizar`, `pode_editar`, `pode_excluir`. Não deve ser confundida com a transação sibling de código 505 ("Homologação de Fornecedores"), que é uma transação distinta.

## 4. Dados de teste

- **Fornecedor selecionado:** CODFORNECEDOR = 6 ("Rio Limpo Manaus"), unidade Modelo Manaus — escolhido por possuir 0 responsabilidades diretas ativas e 2 vínculos legados (`fo_fornecedorxusuario`) pré-existentes, ideal para testar tanto a inclusão quanto a preservação de legado.
- **Vínculos legados pré-existentes (não tocados durante o teste):** responsável `user_10496` e responsável `user_10497`, ambos Origem=DIRETA, Entidade=`fo_fornecedorxusuario`, Situação=ATIVA, oriundos do CODATRIBUICAO=1162 e correlato.
- **Vínculo de teste criado (Inclusão/Edição/Exclusão — PER-007-B/C/D):** CODATRIBUICAO = 15508, Finalidade = Responsável pelo fornecedor, Destino = Usuário, Responsável = Administrador Modelo, Prioridade = 5 → alterada para 9 na Edição, Principal marcado na Edição, Válido de = data do teste, Válido até = vazio.
- **Vínculo de teste criado no Cenário 2 (PER-007-F):** CODATRIBUICAO = 15509, mesmo padrão de dados, criado via sessão admin para permitir o teste funcional de exclusão sob permissão restritiva.

## 5. Preparação da sessão

| Passo | Esperado | Resultado |
|---|---|---|
| Configurar permissão HOMOLOGACAO_FORNECEDOR_FORNECEDOR (I=1,V=1,E=1,X=1) para GESTOR | Salva com sucesso | PASSOU |
| Abrir grid de Gestão de Fornecedores | Grid abre sem erro | PASSOU |
| Botão "Responsabilidades" sem seleção de linha | Desabilitado | PASSOU |
| Botão "Responsabilidades" com linha selecionada | Habilitado | PASSOU |
| Clique no botão "Responsabilidades" | Abre a consulta do fornecedor correto | **FALHOU — ver achado abaixo** |

### Achado significativo — botão "Responsabilidades" exibe "Fornecedor inválido."

Ao clicar no botão "Responsabilidades" na grid de Gestão de Fornecedores (com um fornecedor selecionado), a tela `fornecedor_responsabilidades.php` é aberta, porém exibe o erro **"Fornecedor inválido."** (HTTP 400) em vez da consulta esperada.

**Causa raiz confirmada:** o botão está marcado com o atributo `data-context-child`, mecanismo genérico tratado por `assets/js/manutencao-legal-cadastro.js` (linhas 19–31), que monta a URL de destino concatenando os filtros como JSON: `...?filtros=%7B%22CODFORNECEDOR%22%3A%226%22%7D`. Porém a tela de destino, `fornecedor_responsabilidades.php`, lê o identificador via:

```php
$id = filter_input(INPUT_GET, 'CODFORNECEDOR', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) { http_response_code(400); exit('Fornecedor inválido.'); }
```

ou seja, espera um parâmetro simples `CODFORNECEDOR=6` na query string, e não um JSON envelopado em `filtros`. Como `filter_input` não encontra `CODFORNECEDOR` diretamente (o valor está aninhado dentro de `filtros`), a validação falha e a tela recusa o acesso.

Os demais botões de relacionamento da mesma grid (Contatos, Certificados, Auditoria, Critérios, Funcionários, Produtos Químicos, Equipamentos, Plano de Ação) usam o mesmo mecanismo `data-context-child` corretamente, porque suas telas de destino são grids genéricas construídas para consumir o JSON `filtros`. A tela "Responsabilidades" é a única do grupo que foi implementada como tela bespoke (com contrato de parâmetro simples, no padrão das telas de Requisitos Legais e Medição e Monitoramento), mas foi cadastrada na grid usando o mecanismo genérico errado — um mismatch de contrato entre o botão e a tela de destino.

**Impacto:** usuários não conseguem abrir a tela de Responsabilidades pelo caminho documentado (grid → botão Responsabilidades), independentemente de suas permissões.

**Workaround utilizado para dar continuidade ao teste:** navegação direta às URLs `fornecedor_responsabilidades.php?CODFORNECEDOR=<id>` e `fornecedor_responsabilidades_edicao.php?CODFORNECEDOR=<id>`, o que funciona porque a sessão e as checagens de permissão (`PermissaoTransacao`, `UsuarioSessao`) são independentes do caminho de navegação. Essa via permitiu validar toda a lógica de CRUD e permissão de forma completa e confiável (Seções 6 a 10 abaixo), mas o bug em si é um bloqueio real para o fluxo documentado e deve ser corrigido antes da liberação.

**Sugestão de correção:** ajustar `fornecedor_responsabilidades.php` (e `fornecedor_responsabilidades_edicao.php`) para também aceitarem o parâmetro `filtros` JSON (padrão das demais telas relacionadas na mesma grid), ou — alternativa mais simples e consistente com o padrão já usado nas telas de Requisitos Legais/Medição e Monitoramento — dar ao botão "Responsabilidades" um handler JS dedicado (nos moldes de `medicao-monitoramento-responsabilidades.js` / `requisitos-responsabilidades.js`) que monte a URL com o parâmetro simples `CODFORNECEDOR=<id>`, em vez do mecanismo genérico `data-context-child`.

## 6. PER-007-A — Consulta e acesso

Com o workaround de URL direta, a consulta abriu corretamente exibindo "Fornecedor 6 — Rio Limpo Manaus" no cabeçalho. Os dois vínculos legados (`fo_fornecedorxusuario`, responsáveis `user_10496` e `user_10497`) apareceram corretamente identificados com Origem=DIRETA, Entidade=`fo_fornecedorxusuario`, Situação=ATIVA. Nenhum SQL, erro técnico ou registro de outro fornecedor foi exibido. O comportamento de habilitação do botão na grid (desabilitado sem seleção, habilitado com seleção) está correto — apenas o clique em si é que falha, conforme documentado na Seção 5.

**Resultado: PASSOU COM RESSALVA** (achado de bug documentado na Seção 5)

## 7. PER-007-B — Inclusão

Novo vínculo criado via tela de Manter (Tipo=Responsável pelo fornecedor, Destino=Usuário, Responsável=Administrador Modelo, Prioridade=5, Principal desmarcado, Válido de=data do teste, Válido até=vazio). Mensagem "Responsabilidades salvas com sucesso." exibida. CODATRIBUICAO=15508 gerado com ENTIDADE=`fo_fornecedores`. Consulta pós-inclusão passou a exibir o novo vínculo como ATIVA, sem alteração dos dois vínculos legados nem de nenhum outro fornecedor.

**Resultado: PASSOU**

## 8. PER-007-C — Edição

Alteração da Prioridade de 5 para 9 e marcação de Principal no vínculo 15508. Mensagem de sucesso consistente. Alterações confirmadas persistidas após fechamento e reabertura completa da tela. Nenhum novo vínculo foi criado (permaneceu um único registro, CODATRIBUICAO=15508) e os vínculos legados e demais fornecedores permaneceram inalterados.

**Resultado: PASSOU**

## 9. PER-007-D — Exclusão lógica

Exclusão do vínculo 15508 via ícone de lixeira + Salvar. Mensagem de sucesso consistente e coerente. O vínculo desapareceu da lista ATIVA e passou a ser exibido como HISTÓRICA na consulta — sem exclusão física do registro (confirmado via phpMyAdmin: CODATRIBUICAO=15508 com ATIVO=0, PRIORIDADE=9, PRINCIPAL=1, preservando o histórico da edição). Os vínculos legados e demais fornecedores não foram afetados.

**Resultado: PASSOU**

## 10. PER-007-E — Vínculos legados

Os dois vínculos legados de `fo_fornecedorxusuario` (responsáveis `user_10496` e `user_10497`, originados do CODATRIBUICAO=1162 e correlato) permaneceram visíveis na consulta durante todo o teste, com Origem, Entidade e Responsável corretamente exibidos e sem qualquer alteração. As operações de inclusão, edição e exclusão do vínculo direto novo (15508) demonstraram-se totalmente independentes desses vínculos legados — nenhuma conversão, exclusão ou reassociação ocorreu. Confirmado por consulta ao vivo na interface e por verificação direta em phpMyAdmin (CODATRIBUICAO=1162, ENTIDADE=`fo_fornecedorxusuario`, REGISTRO_ID=6, ATIVO=1, inalterado do início ao fim do teste).

**Resultado: PASSOU**

## 11. PER-007-F — Permissões restritivas

### Cenário 1 — Visualizar=1, Incluir=0, Editar=0, Excluir=0

**Esperado:** consulta disponível, manutenção bloqueada e campos inacessíveis.

- Consulta (`fornecedor_responsabilidades.php`): acessível normalmente, exibindo os vínculos existentes (legados e, quando presente, o direto).
- Manutenção (`fornecedor_responsabilidades_edicao.php`) via GET: bloqueada com a mensagem "Seu perfil não possui permissão para alterar responsabilidades."
- **Validação server-side:** tentativa de POST direto (`action=save_rows`) ao endpoint de edição, contornando a interface, retornou **HTTP 403**, confirmando que o bloqueio não depende apenas da renderização da tela.

**Resultado: PASSOU**

### Cenário 2 — Visualizar=1, Incluir=0, Editar=0, Excluir=1

**Esperado:** manutenção aberta, campos desabilitados, Adicionar oculto e lixeira habilitada; exclusão lógica permitida.

Verificação via inspeção de DOM (não apenas visual):

- Todos os 8 campos editáveis da linha (selects e inputs, exceto ocultos) confirmados com `disabled=true`.
- Botão "Adicionar" confirmado presente no DOM porém oculto (`hidden=true`, sem `offsetParent`).
- Botão de exclusão (lixeira) confirmado **habilitado** (`disabled=false`).

**Teste funcional:** como o vínculo de teste anterior (15508) já estava em situação histórica neste ponto, foi criado — via sessão admin temporária — um novo vínculo de teste dedicado (CODATRIBUICAO=15509, mesmo padrão de dados da Seção 4) para viabilizar o teste de exclusão sob esta permissão restritiva. Com a sessão ueditor restaurada neste cenário, a exclusão do vínculo 15509 foi realizada com sucesso pelo fluxo real da interface (clique na lixeira + Salvar). Mensagem "Responsabilidades salvas com sucesso." exibida (mensagem limpa, sem qualificação adicional, já que a operação foi inteiramente autorizada — sem tentativa de inclusão/edição não permitida envolvida). Confirmado na Consulta e via phpMyAdmin: o vínculo passou de ATIVA para HISTÓRICA (ATIVO=0).

**Validação server-side (defesa em profundidade):** com a mesma sessão/permissão, foi enviada uma requisição POST direta simulando uma tentativa de inclusão de novo vínculo (contornando a interface, que já oculta o botão Adicionar). A requisição retornou HTTP 200 com a mensagem "Operação concluída. Inclusões e edições não autorizadas foram ignoradas.", porém **nenhum novo registro ativo foi de fato criado** — confirmando que o servidor ignora silenciosamente inclusões quando `pode_incluir=false`, independentemente do que é submetido pelo navegador.

**Resultado: PASSOU**

### Cenário 3 — Visualizar=0, Incluir=0, Editar=0, Excluir=0

**Esperado:** consulta e manutenção bloqueadas; POST direto retorna HTTP 403.

- Consulta (`fornecedor_responsabilidades.php`): bloqueada com **HTTP 403** e mensagem "Sem permissão para consultar responsabilidades."
- Manutenção (`fornecedor_responsabilidades_edicao.php`) via GET: bloqueada com **HTTP 403** e a mesma mensagem (a checagem de `pode_visualizar` ocorre antes de qualquer lógica de renderização condicional).
- **Validação server-side:** tentativa de POST direto ao endpoint de edição retornou igualmente **HTTP 403** com a mesma mensagem de bloqueio, confirmando que nenhuma via de acesso (consulta, renderização de manutenção ou submissão de dados) fica disponível quando `pode_visualizar=false`.

**Resultado: PASSOU**

## 12. Validação das mensagens

Todas as mensagens de sucesso ("Responsabilidades salvas com sucesso."), de sucesso parcial ("Operação concluída. Inclusões e edições não autorizadas foram ignoradas.") e de bloqueio ("Seu perfil não possui permissão para alterar responsabilidades." / "Sem permissão para consultar responsabilidades.") apareceram de forma consistente e correspondente ao cenário testado, em todos os passos executados. Em nenhum momento foi observado sucesso acompanhado de falta de permissão, SQL exposto, stack trace, ou sucesso quando nenhuma operação autorizada de fato ocorreu.

## 13. Limpeza

- Confirmado, via phpMyAdmin, que os dois vínculos de teste (CODATRIBUICAO=15508 e 15509, ENTIDADE=`fo_fornecedores`, REGISTRO_ID=6) estão em situação ATIVO=0 (HISTÓRICA) — nenhuma exclusão física foi realizada.
- Confirmado que o vínculo legado amostrado (CODATRIBUICAO=1162, ENTIDADE=`fo_fornecedorxusuario`, REGISTRO_ID=6) permanece ATIVO=1, íntegro e inalterado.
- Confirmado que o fornecedor de teste (CODFORNECEDOR=6, "Rio Limpo Manaus") e seus dois vínculos legados (`user_10496`, `user_10497`) permanecem íntegros na consulta.
- Permissões da transação HOMOLOGACAO_FORNECEDOR_FORNECEDOR (código 496) para o perfil GESTOR restauradas ao estado base observado no início do teste: Visualizar=1, Incluir=Editar=Excluir=não marcado. A restauração foi confirmada por inspeção direta do DOM da grade de Permissões após recarregamento completo da página.
- Confirmado que Exportar Excel e Gerar PDF permaneceram desmarcados (`false`) durante e após todo o teste — não foram alterados em nenhum momento.
- Confirmado, com a conta ueditor, que o acesso final está de acordo com o baseline restaurado: consulta acessível (via workaround de URL, dado o bug da Seção 5) exibindo corretamente os vínculos legados e os dois vínculos de teste como HISTÓRICA; manutenção bloqueada com a mensagem "Seu perfil não possui permissão para alterar responsabilidades." — comportamento idêntico ao validado no Cenário 1, consistente com o padrão de encerramento utilizado nos roteiros PER-005 e PER-006.

## 14. Critérios de aceite

| Critério | Status |
|---|---|
| Botão contextual abre o fornecedor correto | **Não atendido — bug confirmado (Seção 5)** |
| Vínculos legados são consultados sem alteração | Atendido |
| Inclusão, edição e exclusão lógica direta funcionam | Atendido |
| Histórico é preservado | Atendido |
| Permissões e mensagens funcionam visual e server-side | Atendido |
| Nenhum órfão é apagado ou reassociado | Atendido |
| Limpeza e restauração são concluídas | Atendido |

### Resultado final: **PASSOU COM RESSALVA**

A lógica de CRUD (inclusão, edição, exclusão lógica), a preservação de vínculos legados e o enforcement de permissões (incluindo defesa em profundidade server-side) estão corretos e robustos em todos os cenários testados. Há, no entanto, um bug de interface que bloqueia o fluxo documentado de acesso à tela — o botão "Responsabilidades" na grid de Gestão de Fornecedores exibe "Fornecedor inválido." ao ser clicado, devido a um mismatch entre o mecanismo genérico `data-context-child` (que envia `filtros=<JSON>`) e a tela de destino `fornecedor_responsabilidades.php` (que espera o parâmetro simples `CODFORNECEDOR`). Esse bug deve ser corrigido antes da liberação em produção. Recomenda-se ajustar as telas `fornecedor_responsabilidades.php`/`fornecedor_responsabilidades_edicao.php` para aceitar o parâmetro `filtros`, ou substituir o mecanismo do botão por um handler JS dedicado (nos moldes de `medicao-monitoramento-responsabilidades.js`), conforme detalhado na Seção 5.

**Responsável:** QA (Claude, em nome de Paulo)
**Data/hora:** 21/08/2026
**Evidências:** capturas de tela e verificações de DOM/rede/banco de dados realizadas durante a execução (não anexadas neste documento)
**Pendências:** correção do bug de habilitação/roteamento do botão "Responsabilidades" na grid de Gestão de Fornecedores (Seção 5)
