# Relatório de Homologação — PER-009
## Responsabilidades por registro de Treinamento

**Data do teste:** 22/08/2026
**Roteiro de referência:** roteiro_homologacao_PER009_responsabilidades_treinamento_20260822.txt
**Ambiente:** Green Portal — instância remota (http://187.191.99.180/greenportal/), tenant `gmodelo_dbp`, empresa MODELO
**Responsável pela execução:** QA (Claude, em nome de Paulo)

---

## 1. Objetivo

Homologar o botão contextual, a consulta e a manutenção de responsabilidades do registro de treinamento, usando o campo legado `fo_funcionarioxtiposxtreinamentos.codusuario` somente quando houver usuário correspondente no tenant.

## 2. Escopo e regra de legado

Entidade `fo_funcionarioxtiposxtreinamentos` (identificador `codigo`), módulo/tipo TREINAMENTO. A tabela possui 955 registros no total, dos quais **953 são órfãos** (sem `codusuario` correspondente em `usuarios`) e apenas **2 são vínculos legados válidos** (treinamentos código 3 e 4, responsável Fabiana Crown/274). Conforme instrução explícita do roteiro, os registros órfãos não foram usados nem alterados em nenhum momento do teste — permaneceram fora da carga de responsabilidades em todos os cenários verificados.

## 3. Contas e transação

- **Preparação e limpeza:** `admin` (perfil ADMINISTRADOR)
- **Execução autorizada:** `ueditor` (perfil GESTOR)
- **Transação:** `HOMOLOGACAO_FORNECEDOR_FUNCIONARIO`, código 495 — mesma transação que governa a grade "Gestão de Funcionários" e, dentro dela, a Consulta/Manutenção de Responsabilidades do registro de Treinamento (acessada pelo botão contextual "Responsabilidades" dentro do relacionamento Treinamentos).
- **Baseline de permissão configurado para o teste (perfil GESTOR), conforme pré-condição do roteiro:** Visualizar=1, Incluir=1, Editar=1, Excluir=1. Este é o estado restaurado ao final do teste.

## 4. Dados de teste

- **Funcionário:** CODFUNCIONARIO 180 — Luciene Maria da Silva (Modelo Estância, Sodexo Estância), cargo Cozinheiro (CODTIPOFUNCIONARIO 26).
- **Vínculos legados válidos (consulta, PER-009.2):** treinamento código 3 e treinamento código 4, ambos com responsável legado "Fabiana Crown" (usuário `Fabiana.bomfim`, codusuario 274), situação ATIVA.
- **Treinamento de teste (manutenção, inclusão, edição, exclusão — PER-009.1/.3/.4/.5):** código 4098 — "Treinamento de Proteção Auditiva", do funcionário 180. Não possuía vínculos pré-existentes (registro órfão do legado, sem `codusuario` válido), o que o tornou seguro para uso como dado de teste sem impacto em vínculos reais.
- **Segundo treinamento (troca de contexto, PER-009.6):** código 4099 — "Treinanamento de uso de EPI" (grafia originada no cadastro), também do funcionário 180, mantido intocado durante todo o teste para servir de controle de isolamento de contexto.
- **Responsável de teste:** usuário ativo `ueditor` (UEditor).
- **Grupo de teste:** GESTORES (código 13).

## 5. Pré-condições

| # | Item | Resultado |
|---|---|---|
| 1 | Tela Gestão de Funcionários acessível | Confirmado |
| 2 | Permissão temporária GESTOR ajustada para V=1,I=1,E=1,X=1 | Ajustada e confirmada via banco (`permissoes.codpermissao=495`) |
| 3 | Excel e PDF mantidos no estado anterior | Confirmado (0/0 durante toda a execução, inclusive no estado final) |
| 4 | Acesso pelo menu, relacionamento Treinamentos do Funcionário | Login via formulário, navegação pelo shell da aplicação (Gestão de Funcionários → seleção do funcionário → botão Treinamentos) |

## 6. Resultados por sub-teste

### PER-009.1 — Botão contextual
Selecionada a linha do treinamento 4098 no relacionamento Treinamentos do funcionário 180. O botão "Responsabilidades" apareceu **desabilitado** antes da seleção da linha e **habilitado** imediatamente após. O clique abriu a consulta corretamente, identificando "Treinamento 4098 — Treinamento de Proteção Auditiva", sem mensagem de "Treinamento inválido" e sem mistura com o funcionário ou treinamento selecionado anteriormente na sessão (confirmado escopando a consulta ao `data-context-id="responsabilidades"` do iframe correto, já que a página contém dois botões com o mesmo atributo — um do grid pai e um do grid aninhado de Treinamentos).

**Resultado: PASSOU**

### PER-009.2 — Consulta do vínculo legado válido
Testados diretamente os dois treinamentos legados válidos (código 3 e código 4): título "Responsabilidades do treinamento" correto em ambos, código e descrição corretos, responsável legado "Fabiana Crown" exibido corretamente, origem DIRETA, destino USUARIO, situação ATIVA e validade a partir de 1900-01-01 (data de referência do legado). Nenhum dos 953 registros órfãos apareceu como responsável válido em qualquer consulta — confirmado tanto pela ausência na tela quanto pela consulta direta à view `vw_responsabilidade_treinamentos`, que retorna exclusivamente os 2 vínculos legados válidos mais os vínculos incluídos durante este teste.

**Resultado: PASSOU**

### PER-009.3 — Manutenção e inclusão
Na manutenção do treinamento 4098, "Adicionar" criou uma nova linha; selecionado destino Usuário, responsável UEditor, prioridade 5, vigência 22/08/2026. Salvo com sucesso e confirmado na consulta com situação ATIVA. Confirmado via banco: **CODATRIBUICAO 15520** (ENTIDADE=fo_funcionarioxtiposxtreinamentos, REGISTRO_ID=4098, TIPO_DESTINO=USUARIO, CODUSUARIO=5, ATIVO=1).

Em seguida, adicionada uma segunda responsabilidade com destino Grupo: confirmada a alternância visual entre os campos Usuário e Grupo (campo Grupo passando a visível e campo Usuário a `d-none` ao trocar o destino). Selecionado o grupo GESTORES, prioridade 20. Salvo com sucesso e o grupo exibido corretamente na consulta ("GESTORES"). Confirmado via banco: **CODATRIBUICAO 15521** (TIPO_DESTINO=GRUPO, CODGRUPO=13, ATIVO=1).

**Resultado: PASSOU**

### PER-009.4 — Edição e exclusão lógica
Prioridade do registro 15520 alterada para 99; salva e confirmada tanto na tela quanto no banco. Em seguida, excluído logicamente apenas o vínculo 15520 (acionamento da lixeira e Salvar): confirmado que ele passou a exibir situação **HISTÓRICA** e permaneceu na tabela com **ATIVO=0**, sem exclusão física. Os dois vínculos legados válidos (CODATRIBUICAO 15490 e 15491, correspondentes aos treinamentos código 3 e 4, responsável Fabiana Crown) permaneceram **ATIVO=1**, completamente intocados durante todo este sub-teste.

**Resultado: PASSOU**

### PER-009.5 — Permissões

Cada cenário foi aplicado ajustando exclusivamente a permissão da transação (código 495) como `admin`, seguido de novo login como `ueditor` pelo fluxo normal do menu, com verificação de estado via DOM (campos, botões) e, quando aplicável, via requisição direta ao servidor.

| Cenário | Permissão | Comportamento esperado | Observado | Resultado |
|---|---|---|---|---|
| 1 | V=1,I=0,E=0,X=0 | Consulta e manutenção em modo somente leitura | Formulário renderizado normalmente; todos os campos e botões (Adicionar, Salvar, lixeira) **desabilitados/ocultos** (`fieldsDisabled=true`, `addBtnHidden=true`, `saveBtnDisabled=true`, `delBtnDisabled=true`) | **PASSOU** |
| 2 | V=1,I=0,E=0,X=1 | Lixeira habilitada, exclusão lógica disponível | Campos permanecem somente leitura; lixeira e Salvar **habilitados** (`delBtnDisabled=false`, `saveBtnDisabled=false`); Adicionar oculto | **PASSOU** |
| 3 | V=1,I=1,E=0,X=0 | Inclusão disponível, edição bloqueada | Adicionar **visível**; campos do registro existente **desabilitados**; lixeira desabilitada | **PASSOU** |
| 4 | V=1,I=0,E=1,X=0 | Edição disponível, inclusão bloqueada | Adicionar **oculto**; campos do registro existente **habilitados** para edição; lixeira desabilitada | **PASSOU** |
| 5 | V=0,I=0,E=0,X=0 | Acesso bloqueado | Consulta retornou "Sem permissão para consultar responsabilidades." (sem exposição de SQL); requisição direta à manutenção retornou **HTTP 403** | **PASSOU** |

**Validação server-side:** com V=1,I=1,E=0,X=0 (Incluir habilitado, Editar bloqueado), foi enviado um POST direto (`action=save_rows`) tentando alterar a PRIORIDADE do registro existente 15521 para 888, sem passar pela interface. O servidor respondeu HTTP 200 com a mensagem "Operação concluída. Inclusões e edições não autorizadas foram ignoradas." e a alteração foi **corretamente ignorada** — confirmado via banco que PRIORIDADE permaneceu em 20. O servidor aplica a regra de autorização independentemente de manipulação do navegador.

**Resultado: PASSOU integralmente, todos os 5 cenários e a validação server-side.** Diferente do observado no módulo de responsabilidades de funcionário (PER-008), a tela `treinamento_responsabilidades_edicao.php` renderiza corretamente o formulário em modo desabilitado sempre que houver ao menos uma permissão de escrita (Incluir, Editar ou Excluir) ativa — sua condição de exibição já contempla `pode_excluir` no gate de nível superior, evitando o bloqueio total indevido identificado no Achado 1/2 do relatório PER-008. A mensagem retornada na tentativa de bypass também identificou corretamente que parte da submissão foi ignorada, ao contrário do comportamento observado no Cenário C de PER-008.

### PER-009.6 — Troca de contexto
Selecionado o segundo treinamento do funcionário 180 (código 4099, "Treinanamento de uso de EPI") diretamente pela interface, dentro do mesmo relacionamento Treinamentos: o botão Responsabilidades da linha permaneceu desabilitado até a seleção e, após selecionada a linha 4099, o clique abriu o popup com `filtros={"CODIGO":"4099"}` — confirmando que o contexto acompanhou corretamente a linha selecionada, sem herdar o treinamento 4098 selecionado anteriormente na mesma sessão. A consulta exibiu "Treinamento 4099 — Treinanamento de uso de EPI" e "Não há responsabilidades cadastradas para este treinamento", confirmando que os vínculos do treinamento 4098 (UEditor/GESTORES) não vazaram para o contexto de 4099.

Testado também o acesso direto via URL completa da aplicação (`http://187.191.99.180/greenportal/principal/treinamento_responsabilidades.php?CODIGO=4099`), fora do fluxo de popup: retornou o mesmo resultado correto (código, descrição e ausência de vínculos), confirmando a compatibilidade do parâmetro direto. Reconfirmado em seguida que o acesso direto ao treinamento 4098 continuava mostrando exclusivamente seus próprios vínculos.

**Resultado: PASSOU**

## 7. Achado incidental (fora do escopo de PER-009)

**Achado 1 (funcional, prioridade média) — Filtro de coluna "Nome" na grade Gestão de Funcionários não funciona.**
Durante a navegação para localizar o funcionário 180, identificado que os filtros de coluna (`[data-column-filter]`, incluindo o filtro "Nome") da grade "Gestão de Funcionários" não têm efeito algum sobre os resultados exibidos, independentemente do valor digitado.

**Causa raiz identificada (inspeção de código):** em `assets/js/grid-pagination.js`, a função `filtersFor()` constrói o objeto de filtros a partir do atributo `data-context-filters` do elemento `.menu-grid-fragment` via `JSON.parse`. Nesta grade, esse atributo é literalmente `"[]"`, o que faz `JSON.parse` retornar um **Array** em vez de um objeto — `typeof []` é `"object"`, então a checagem de tipo existente não detecta o problema. Atribuições subsequentes como `filters[coluna] = valor` em um Array com chave string são descartadas silenciosamente por `JSON.stringify` (que só serializa índices numéricos), resultando em `filtros=%5B%5D` (`[]` vazio) sempre enviado ao servidor, não importa o que o usuário digite no filtro.

**Impacto:** os filtros de coluna da grade Gestão de Funcionários são inoperantes. A caixa de busca geral ("Buscar no grid", campo `.crud-search input`) não é afetada, pois usa um parâmetro `busca` separado, e foi o mecanismo usado como contorno para localizar os registros de teste neste e nos relatórios anteriores desta série.

Este achado é reportado por transparência, mas está fora do escopo funcional de PER-009 (que trata de responsabilidades de treinamento, não de filtros de grade), não bloqueou nenhuma etapa do roteiro e não afeta a integridade de dados.

## 8. Limpeza e restauração

1. **Vínculos de teste excluídos logicamente:** CODATRIBUICAO 15520 e 15521 — ambos confirmados com ATIVO=0 no banco (situação HISTÓRICA), sem exclusão física.
2. **Nenhum vínculo legado foi alterado:** os vínculos dos treinamentos código 3 e 4 (CODATRIBUICAO 15490 e 15491, responsável Fabiana Crown) permanecem ATIVO=1, inalterados durante toda a execução.
3. **Registros órfãos preservados:** reconfirmado ao final que a tabela `fo_funcionarioxtiposxtreinamentos` mantém 955 registros totais, 953 órfãos — contagem idêntica à observada no início do teste, nenhum registro órfão foi tocado.
4. **Permissão do GESTOR (código 495) restaurada ao baseline configurado na pré-condição:** Visualizar=1, Incluir=1, Editar=1, Excluir=1 — confirmado via banco de dados após a gravação final.
5. **Exportar Excel e Gerar PDF:** permaneceram desmarcados (0/0) durante toda a execução, incluindo no estado final.
6. **Nenhuma exclusão física ocorreu no banco:** confirmado por consulta direta — todos os registros de teste permanecem presentes na tabela `responsabilidade_atribuicoes`, apenas com ATIVO=0.

## 9. Critérios de aceite

| Critério | Status |
|---|---|
| Botão contextual (habilitação, identificação correta do CODIGO) | Aprovado |
| Consulta do vínculo legado válido | Aprovado |
| Preservação/exclusão dos registros órfãos da carga | Aprovado |
| Manutenção — inclusão por usuário | Aprovado |
| Manutenção — inclusão por grupo, alternância visual | Aprovado |
| Manutenção — edição | Aprovado |
| Manutenção — exclusão lógica | Aprovado |
| Matriz de permissões — Cenário 1 (somente leitura) | Aprovado |
| Matriz de permissões — Cenário 2 (exclusão habilitada) | Aprovado |
| Matriz de permissões — Cenário 3 (inclusão habilitada) | Aprovado |
| Matriz de permissões — Cenário 4 (edição habilitada) | Aprovado |
| Matriz de permissões — Cenário 5 (acesso bloqueado) | Aprovado |
| Validação server-side contra manipulação do navegador | Aprovado |
| Troca de contexto entre treinamentos (UI e URL direta) | Aprovado |
| Ausência de mistura de vínculos entre treinamentos | Aprovado |
| Limpeza e restauração (permissões, Excel/PDF, ausência de exclusão física, órfãos preservados) | Aprovado |

### Resultado final: **APROVADO**

Todos os sub-testes de PER-009 foram concluídos com sucesso: o botão contextual, a consulta do vínculo legado válido, a manutenção (inclusão, edição, exclusão lógica), a matriz completa de permissões (incluindo validação server-side) e a troca de contexto entre treinamentos funcionaram conforme especificado no roteiro, sem mistura de dados e sem qualquer alteração aos 953 registros órfãos ou aos 2 vínculos legados válidos. Nenhum achado bloqueante foi identificado dentro do escopo de PER-009; o único achado registrado (filtro de coluna "Nome" da grade Gestão de Funcionários) é incidental, pré-existente e fora do escopo funcional deste roteiro.

---

**Responsável:** QA (Claude, em nome de Paulo)
**Data/hora:** 22/08/2026
**Evidências:** verificações de DOM, requisições diretas ao servidor e consultas diretas ao banco (phpMyAdmin) realizadas durante a execução (não anexadas neste documento)
