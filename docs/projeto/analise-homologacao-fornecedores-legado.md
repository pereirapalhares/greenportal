# Engenharia reversa do módulo "Homologação de Fornecedores" (legado PHP/dhtmlx)

**Escopo lido:** os 71 arquivos de `/mnt/user-data/uploads/www--configuracoes/` e os 42 de `/mnt/user-data/uploads/cadastros/`. Do `cadastros_c.php` (58 KB) li só os trechos que tratam do módulo.

**Sobre `/mnt/user-data/uploads/configuracoes/`:** os 7 `.php` dessa pasta são idênticos às versões de `www--configuracoes` (conferido com `diff`). O único arquivo novo ali é `criterios.sql`.

**Arquivos que o código inclui mas não estavam no pacote:** `cadastros_s.php`, `busca.php`, `correlacionadas_c.php`, `upload.js`, `carga_avaliacao_documental.php`, `carga_avaliacao_sistemica.php`, `avaliacaodocumental_s.php`, `avaliacaosistemica_s.php`, `ExcelFiltrado.php`, `excel_por_email.php`, `ev_schedule_c.php`, `nc_responsaveis_planodeacao.php` e `parametros.php`. Também faltam as views e funções do banco e a pasta `cadastros_en/`. Por isso algumas conclusões abaixo dependem de confirmação.

---

## 1. Visão geral do módulo

### 1.1 Duas gerações convivendo
1. **Geração atual, com prefixo `fo_`.** É a que está em uso, centrada em `fo_fornecedores` e no grid "Documentos" (`fo_fornecedorxcriterios`).
2. **Geração legada, sem prefixo.** Usa as tabelas `fornecedor`, `tipofornecedor`, `TIPOFORNECEDORXFORNECEDOR`, `CRITERIOS`, `avaliacao_documental`, `avaliacao_sistemica`, `planodeacao_fornecedores`, `certificados`, `fornecedorxcontatos`, `categoria_fornecedor` etc.
   - Avaliação documental e sistêmica, envio de critérios, perigos/aspectos e categorias existem só nesta geração.
   - Ela não se conecta ao modelo `fo_`: nenhuma tabela legada é referenciada pelas tabelas `fo_` (nem o contrário).

### 1.2 Entidades (geração `fo_`)

| Conceito | Tabela | Observação |
|---|---|---|
| Fornecedor | `fo_fornecedores` | Pertence a uma unidade (`CODUNIDADE`) e tem `ATIVADOFOR` (Sim/Não). Guarda o responsável interno (`CODUSUARIO_RESPONSAVEL_FORNECEDOR`), o responsável pela integração (`CODUSUARIO_RESPONSAVEL_INTEGRACAO`), o nível de serviço e as datas de avaliação |
| Tipo de fornecedor | `fo_tipofornecedores` | `DSCTIPO`, `NECESSITA_AUDITORIA` (Sim/Não) |
| Fornecedor × Tipo (N:N) | `fo_fornecedoresxtipofornecedores` | Também guarda os dados da **auditoria de homologação** por tipo: `datavalidade`, `datareacao`, `nota`, `fornecedoraprovadoauditoria` |
| Critério (documento exigido do fornecedor) | `fo_criteriofornecedores` | `DSCRITERIO`, `DSCRITERIO_EN` |
| Tipo × Critério + nível de exigência | `fo_tipofornecedoresxcriteriofornecedores` | `nivel_de_exigencia` = Obrigatório/Desejável |
| Documento do fornecedor (instância) | `fo_fornecedorxcriterios` | Uma linha por fornecedor × tipo × critério, gerada automaticamente. Guarda situação, validade, protocolo, responsáveis e observações |
| Histórico do documento | `fo_fornecedorxcriterios_historico` | Cópia completa da linha a cada update ou delete |
| Usuários do fornecedor | `fo_fornecedorxusuario` | N:N entre usuário e fornecedor. Define quem pode ser "Responsável do fornecedor" |
| Funcionário do fornecedor | `fo_funcionarios` | Tem `CODFORNECEDOR`, `CODTIPOFUNCIONARIO`, `DATA_VALIDADE` (rotulado "Data de Realização da Integração"), `DATA_DE_INICIO`, `STATUS` |
| Tipo de funcionário | `fo_tipofuncionarios` | |
| Critério (documento) do funcionário | `fo_criteriofuncionarios` | `TIPO_DE_DOCUMENTO` = Competência / Regularização de Ordem Legal |
| Tipo func. × Critério func. | `fo_tipofuncionariosxcriteriofuncionarios` | |
| Documento do funcionário | `fo_funcionarioxtiposxcriterios` | `atendeaocriterio`, validade, responsáveis, observação |
| Tipo de treinamento | `fo_tipotreinamentos` | |
| Tipo func. × Treinamento | `fo_tipofuncionariosxtipotreinamentos` | |
| Treinamento do funcionário | `fo_funcionarioxtiposxtreinamentos` | Gerado automaticamente |
| Tipo func. × Critério × Treinamento | `fo_tipofuncxcriteriofuncxtreinamento` | Só permite editar o treinamento |
| N:N redundantes | `fo_funcionariosxtipofuncionarios`, `fo_funcionariosxfornecedores` | Duplicam colunas que já existem em `fo_funcionarios` |
| Certificados do fornecedor | `fo_certificados` | |
| Contatos | `fo_contatos` | |
| Produtos químicos | `fo_produtoquimico` | |
| Equipamentos | `fo_equipamentos` | |
| Plano de ação | `fo_planodeacao` | Livre, por fornecedor |
| Auditoria (versão antiga) | `fo_fornecedorxauditoria` + `fo_fornecedorxtipoxcriterio` (provável view) | Aparentemente sem uso |
| Critérios por fornecedor (versão antiga) | `fo_fornecedoresxcriterios` (com "es") | Antecessor de `fo_fornecedorxcriterios` |

### 1.3 Fluxo de negócio inferido
1. **Configuração.** Cadastram-se os tipos de fornecedor (dizendo se exigem auditoria) e os critérios/documentos. Depois os critérios são associados aos tipos, com nível de exigência.
2. **Cadastro do fornecedor** (`fo_fornecedor`): unidade, responsáveis, indicadores (trabalha nas instalações? terá produtos químicos? terá equipamentos?) e associação a um ou mais tipos.
3. **Documentos.** Ao abrir a aba "Documentos" (`fo_auditoriacriterios`), o sistema gera em `fo_fornecedorxcriterios` as linhas que faltam para os critérios do tipo. A empresa define o responsável interno e o responsável do fornecedor. O fornecedor faz o upload, informa validade e protocolo e escreve sua observação. A empresa então aprova ou reprova.
4. **Auditoria** (aba "Auditoria de Homologação", `fo_auditoriatipos`): para cada tipo com `necessita_auditoria='Sim'`, registram-se validade, dias de reação, nota, aprovação e cronograma.
5. **Funcionários.** Para cada funcionário, os documentos exigidos vêm do seu tipo, e os treinamentos são gerados automaticamente.
6. **Acompanhamento** por três caminhos:
   - telas de "pendências" por usuário (`*_pendencias_documentos`);
   - árvore de estatísticas (`fo_estatisticas_c.php`) que abre 12 relatórios;
   - NCs por fornecedor (`nc_ocorrencias_por_fornecedor`).

### 1.4 Perfis (variáveis de sessão)
- **Variáveis usadas:**
  - `FORNECEDOR`, `FORNECEDOR_GESTOR`, `codfornecedor1` (lista de IDs de fornecedor do usuário) e `codfornecedor`, que é um **fragmento de SQL** concatenado nos `WHERE` de usuários;
  - `CODUSUARIO`, `USERID`, `nome`;
  - `ADMINISTRADOR`, `ADM DA EMPRESA`, `GRUPO`, `GRUPO_IDIOMA`, `database`.
- **"Fornecedor comum"** = `FORNECEDOR=S` e `FORNECEDOR_GESTOR=N`. Vê só os seus fornecedores e tem colunas e edição restritas.
- **"Fornecedor gestor"** tem os mesmos direitos da empresa nas telas de documentos.

---

## 2. Tabela por página

Abreviaturas: **R** = tabelas lidas, **W** = tabelas gravadas, **Corr.** = correlacionamento pai/filho (tela de associação N:N).

### 2.1 Telas principais do fornecedor

**`fo_fornecedor.php` (config) + `fo_fornecedor_c.php` e `fo_fornecedorxauditoria_c.php` (renderers) → `fo_fornecedores_s.php` (save)**
- **Finalidade:** grid de fornecedores. Rótulos em inglês quando `?locale=en`.
- **Chave:** `CODFORNECEDOR`.
- **Colunas principais:** `CODUNIDADE`/`DSCUNIDADE`, `CNPJ_CPF` (formatado por `green_universal.MASK`), `RAZAOSOCIAL`, `NOMEFANTASIA`, `TIPOFORNECEDOR` (via `getTipoFornecedores(codtipofornecedor)`), `ATIVADOFOR`, `NOME_RESPONSAVEL_FORNECEDOR`, `TRABALHA_INSTALACAO_FORNECEDOR`, `TEM_PRODUTOS_QUIMICOS`, `TEM_EQUIPAMENTOS`, `NOME_RESPONSAVEL_INTEGRACAO`, `NIVEL_DE_SERVICAO`, `DATA_DA_AVALIACAO`, `DATA_DA_PROXIMA_AVALIACAO`, `ANEXO`, endereço e `EMAILS`.
- **R:** `fo_fornecedores`, `unidades`, `<db>_seguranca.usuarios` (u1 e u2), `fo_fornecedoresxtipofornecedores` (left join), `<db>_seguranca.usuariosxunidades`.
- **Limpeza no topo:** `delete from fo_fornecedoresxtipofornecedores where codtipofornecedor not in (select codtipofornecedor from fo_tipofornecedores)`.
- **Filtros:**
  - Fornecedor comum: `CODFORNECEDOR in (codfornecedor1)`, e todas as colunas ficam somente leitura.
  - Não-admin: `CODUNIDADE in (unidades do usuário via usuariosxunidades) and ativadofor='Sim'`. Com `?desativado=S` passa a ser `<>'Sim'`.
  - Admin: só o filtro de ativado, **exceto em `green_crown`**, onde não há filtro algum.
- **Combos:**
  - Sim/Não em `TRABALHA_INSTALACAO_FORNECEDOR`, `TEM_PRODUTOS_QUIMICOS`, `TEM_EQUIPAMENTOS` e `ATIVADOFOR`;
  - Satisfatório/Não Satisfatório em `NIVEL_DE_SERVICAO`;
  - responsáveis por **nome**, de `usuarios where ativado='S' and userid not in('admin') <$_SESSION['codfornecedor']>`;
  - unidades de `unidades`.
- **W (`fo_fornecedores_s.php`):**
  - INSERT em `FO_FORNECEDORES` com `ATIVADOFOR='Sim'` fixo;
  - UPDATE de todos os campos;
  - DELETE só em `fo_fornecedores`.
  - Os responsáveis são convertidos de nome para código com `SELECT CODUSUARIO ... where nome='...'`.
- **Renderers:**
  - `fo_fornecedor_c.php`: abas "Tipos" (Corr. `fo_fornecedoresxtipofornecedores`) e "Funcionários". As abas de contatos, certificados, químicos, equipamentos e plano estão comentadas.
  - `fo_fornecedorxauditoria_c.php` (tela de homologação) tem 8 abas:

    | Aba | Página |
    |---|---|
    | Contatos | `fo_contatos` |
    | Certificados | `fo_certificados` |
    | Auditoria | `fo_auditoriatipos` |
    | **Documentos** (aba ativa) | `fo_auditoriacriterios` |
    | Funcionários | `fo_funcionarios` |
    | Produtos Químicos | `fo_produtoquimico` |
    | Equipamentos | `fo_equipamentos` |
    | Plano de Ação | `fo_planodeacao` |

    Para `FORNECEDOR=S` ficam ocultas as abas Certificados, Auditoria, Químicos, Equipamentos e Plano. O manual muda conforme o cliente: "Manual Green para Fornecedores.pdf" no `green_crown` e "GR_0016_01_Homologacao_de_Fornecedores.pdf" nos demais. Com `GRUPO_IDIOMA="IDIOMA INGLES"` há redirect para `../cadastros_en/`.

**`fo_fornecedores.php` (versão antiga do grid)**
- Mesmas colunas, mas sem unidade, CNPJ e EMAILS.
- O filtro é por `usuarios.codfornecedor` (um único fornecedor por usuário), e os combos usam índices fixos.
- Provavelmente substituída por `fo_fornecedor.php`.

**`fo_fornecedor_s.php` (handler antigo, aparentemente morto)**
- Busca `CODTIPOFORNECEDOR` por `DSCTIPO` e grava `fo_fornecedores.CODTIPOFORNECEDOR` e `DENTRO_EMPRESA`.
- No INSERT há o bug `CODUNIDADE = 0<RAZAOSOCIAL>`.
- O DELETE apaga também `fo_fornecedoresxcriterios` e `fo_fornecedoresxtipofornecedores`.

**`fo_fornecedoresxtipofornecedores.php` (Corr.)**
- Pai: tipos ainda não associados **que já têm critérios** (`codtipofornecedor in (select ... from fo_tipofornecedoresxcriteriofornecedores)`), filtrados com `DSCTIPO like '<filtro>%'`.
- Filho: tipos associados.
- W: `insert into fo_fornecedoresxtipofornecedores(codfornecedor,codtipofornecedor)` e o delete correspondente.

**`fo_fornecedorxusuario.php` (Corr., aberta a partir do usuário)**
- Pai: fornecedores (`dscunidade/razaosocial`) ainda não ligados ao `codusuario`. Filho: os já ligados.
- W: `fo_fornecedorxusuario(codusuario, codfornecedor)`.

**`fo_fornecedores_tree.php` (XML da árvore Unidade/Fornecedor)**
- Lista só fornecedores **que têm funcionários** (`CODFORNECEDOR in (select codfornecedor from fo_funcionarios)`).
- Fornecedor vê só `codfornecedor1`, **exceto o `USERID 'Portaria.CPS'`**, que vê todos.
- Não-admin é filtrado pelas suas unidades.

### 2.2 Documentos do fornecedor (núcleo)

**`fo_auditoriacriterios.php` (aba Documentos) → `fo_auditoriacriterios_s.php`**
- **Chave:** `codfornecedorxcriterios`.
- **Colunas:** `dscriterio`, `nivel_de_exigencia`, `enviadoodocumento`, `codusuario` (responsável interno), `codusuario_fornecedor`, `unidadefornecedor` (calculada por `getDSCUNIDADE(<db>_seguranca.getCODUNIDADE(codusuario_fornecedor))`), `anexo` (link `uploadarquivos('fo_fornecedorxcriterios',id)`), `datadevalidade`, `emprotocolo`, `datadereacao`, `situacao`, `incluidocontrato`, `observacao`, `observacao_do_fornecedor`, `qtddias = datediff(datadevalidade, now())`.
- **R:** `fo_tipofornecedores`, `fo_criteriofornecedores`, `fo_tipofornecedoresxcriteriofornecedores`, `fo_fornecedorxcriterios`, `fo_fornecedoresxtipofornecedores` e, para o fornecedor, `usuarios`.
- **Limpezas no topo:** apaga de `fo_fornecedorxcriterios` as linhas cujo `codtipo` ou `codcriterio` não existe mais em `fo_tipofornecedoresxcriteriofornecedores`.
- **Inserção automática:** pega **o primeiro** `codtipofornecedor` do fornecedor e insere em `fo_fornecedorxcriterios(codfornecedor,codtipo,codcriterio)` os critérios do tipo que faltam, comparando `concat(codtipo,codcriterio)`.
- **Combos (empresa ou fornecedor gestor):**
  - `emprotocolo`: S=Sim, N=Não, R=Renovar?
  - `enviadoodocumento`: Sim/Não
  - `situacao`: Aprovado, Aprovado sem validade, Ciente da regra, Reprovado, Pendente com a empresa, Pendente com o fornecedor, Não enviar ao fornecedor, NA=Não se aplica
  - `incluidocontrato`: Sim/Não
  - `codusuario`: usuários ativos, exceto admin
  - `codusuario_fornecedor`: usuários de `fo_fornecedorxusuario` daquele fornecedor, mais as opções 0="" e 99999="Sem fornecedor responsavel"
- **Combo para o fornecedor comum:** apenas `emprotocolo`.
- **Botões** (`botoes_update_refresh_excel_fo_auditoriacriterios.php`):
  - Confirmar, Recarregar, Excel, Copiar e Colar;
  - Excluir só para `ADM DA EMPRESA`;
  - legenda de cores;
  - link "Histórico de alterações" só para `ADM DA EMPRESA`.
- **W (save):**
  - Fornecedor comum: UPDATE apenas de `datadevalidade`, `emprotocolo`, `observacao_do_fornecedor` e `datadereacao`.
  - Empresa: UPDATE também de `enviadoodocumento`, `situacao`, `incluidocontrato`, `codusuario`, `codusuario_fornecedor` e `observacao`.
  - Regras de protocolo (seção 3).
  - INSERT em `fo_fornecedorxcriterios_historico` após cada update e antes de cada delete.
  - DELETE em `fo_fornecedorxcriterios`.

**`fo_auditoriacriterios_copiar_colar_s.php`** (chamado pelos botões Copiar/Colar do `cadastros_c.php`)
- Copia de uma linha origem para uma linha destino **somente os campos vazios do destino**.
  - Empresa: `codusuario`, `enviadoodocumento`, `incluidocontrato`, `situacao`, `codusuario_fornecedor`, `datadevalidade`, `datadereacao`, `emprotocolo`, `observacao`, `observacao_do_fornecedor`.
  - Fornecedor: um subconjunto desses campos.
- Também copia os arquivos do GED: `E:\projetoGreen\GED\<db>\fo_fornecedorxcriterios\<origem>` para `...\<destino>`, sem sobrescrever.

**`fo_auditoriacriterios_historico.php`** (somente leitura, via `botoes_visualizar_excel.php`)
- Mostra os últimos 10 registros de `fo_fornecedorxcriterios_historico` (`CODHISTORICO`, `CODUSUARIOALTERACAO`, `DATAALTERACAO`, ...) para um `codfornecedorxcriterios`.
- Tem limpezas de órfãos iguais às da aba Documentos.

**`fo_auditoriacriterios_pendencias_documentos.php` / `_crown.php` / `_crown_en.php`** ("minhas pendências" de documentos de fornecedores) → também salva em `fo_auditoriacriterios_s.php`
- **Seleção da variante:** `cadastros_c.php` carrega automaticamente `_crown` quando `database=="green_crown"`. A versão `_crown_en` tem rótulos e combos em inglês.
- **Colunas:** as mesmas da aba Documentos, mais `dscunidade` e `razaosocial`.
- **Inserção automática global:** para **todos** os fornecedores × tipos, insere os critérios que faltam (comparação por `CONCAT_WS(codfornecedor, ...)`).
- **Filtros, visão da empresa:**
  - padrão: `fo_fornecedorxcriterios.codusuario = usuário logado` e fornecedor ativo;
  - crown: `situacao='Pendente com a empresa'`, sem filtro de ativo.
- **Filtros, fornecedor comum:** `codfornecedor in (codfornecedor1)`, `codusuario_fornecedor = usuário logado`, `situacao not in ('Pendente com a empresa')`.
  - Na versão padrão ainda exige `usuarios.codusuario = fo_fornecedorxcriterios.codusuario` e `ativadofor='Sim'`.
- **Combo de situação:** só 5 valores (Aprovado, Reprovado, Pendente com a empresa, Pendente com o fornecedor, NA). Na versão crown, o combo de responsável traz apenas usuários internos (`codfornecedor=0`).
- **Botões:** `botoes_update_refresh_excel_fornecedores.php`, mais a versão `_en`.

### 2.3 Auditoria de homologação

**`fo_auditoriatipos.php` → `fo_auditoriatipos_s.php`**
- **Chave:** `codfornecedorxtipofornecedores`.
- **Colunas:** `DSCTIPO`, `necessita_auditoria` (vem do tipo), `datavalidade` (data zerada aparece vazia), `datareacao`, `fornecedoraprovadoauditoria` (combo Sim/Não, índice 6), `nota`, `anexo` (`uploadarquivos('fo_fornecedorxauditoria',id)`), `cronograma`.
- **Cronograma:** o link aparece só quando `necessita_auditoria='Sim'` e chama `cronograma(id)`, que abre `../cadastros/ev_schedule_c.php?codauditoria=`.
- **R:** `fo_fornecedoresxtipofornecedores`, `fo_tipofornecedores`.
- **W:** só UPDATE em `fo_fornecedoresxtipofornecedores` (`datavalidade`, `datareacao`, `nota`, `fornecedoraprovadoauditoria`), com `where codtipofornecedor=... and codfornecedor=...`.

**`fo_fornecedorxauditoria.php` (provavelmente morto)**
- R: `fo_fornecedorxauditoria`, `fo_fornecedorxtipoxcriterio`.
- Não filtra por fornecedor. Tem a coluna com erro de digitação `datavalidadedocuemnto` e um combo "Sim/Nao".
- O renderer de mesmo nome não usa este arquivo: carrega a config `fo_fornecedor` (`busca.php?tabela=fo_fornecedor`).

### 2.4 Configuração de tipos e critérios

| Config | Renderer / Save | R / W | Detalhes |
|---|---|---|---|
| `fo_tipofornecedores.php` | `fo_tipofornecedores_c.php` → `cadastros_s.php?tabela=fo_tipofornecedores` | `fo_tipofornecedores` (`CODTIPOFORNECEDOR`, `DSCTIPO`, `NECESSITA_AUDITORIA`) | Combo Sim/Não. Limpa órfãos de `fo_tipofornecedoresxcriteriofornecedores`. Abas: Critérios (Corr.) e Nível de exigência. Antes de excluir, avisa para retirar os critérios associados |
| `fo_tipofornecedoresxcriteriofornecedores.php` | Corr. | `fo_criteriofornecedores` ↔ `fo_tipofornecedoresxcriteriofornecedores(codtipofornecedor,codcriteriofornecedor)` | |
| `fo_nivelexigencia.php` | `fo_nivelexigencia_s.php` | R: tipo × critério com joins. W: UPDATE `nivel_de_exigencia` | Combo Obrigatório/Desejável. Chave composta `concat(codtipo,codcriterio)`. Limpa órfãos |
| `fo_criteriofornecedores.php` | generic | `fo_criteriofornecedores` (`DSCRITERIO`, `DSCRITERIO_EN`) | Limpa órfãos |
| `fo_fornecedoresxcriterios.php` | `fo_fornecedoresxcriterios_s.php` | R: `fo_criteriofornecedores`, `fo_tipofornecedoresxcriteriofornecedores`, `fo_fornecedores.codtipofornecedor`. W: `fo_fornecedoresxcriterios` | **Legado.** Insere via loop por critério do tipo único do fornecedor. O save resolve `CODCRITERIO` por `DSCRITERIO` |

### 2.5 Funcionários, treinamentos e documentos de funcionário

**`fo_funcionarios.php`**
- **Renderers:**
  - aba do fornecedor: `cadastros_c` → `fo_funcionarios_s.php?codigo=<codfornecedor>`;
  - tela própria: `fo_funcionarios_c.php` → `cadastros_s.php?tabela=fo_funcionarios`, com abas Corr. `fo_funcionariosxtipofuncionarios` e `fo_funcionariosxfornecedores`;
  - árvore: `fo_gestao_funcionarios_c.php`, que abre as abas Critério e Treinamento.
- **Colunas:** `NOME`, `CELULAR`, `TELEFONE`, `EMAIL`, `NRIDENTIFICACAO` (Nº Registro), `DSCTIPO` (combo de `FO_TIPOFUNCIONARIOS`), `SETOR`, `ANEXO`, `DATA_VALIDADE` (rotulada "Data de Realização da Integração"), `DATA_DE_INICIO`, `CODFORNECEDOR`, `CODTIPOFUNCIONARIO`.
- **R:** `fo_funcionarios`, `fo_tipofuncionarios`, `fo_fornecedores`, `unidades`. Usa `LIMIT 0,300`. Sem `codigo`, filtra `CODFORNECEDOR=0`, ou seja, não traz nada.
- **Fornecedor comum:** várias colunas ficam ocultas. Os botões passam a ser `botoes_insert_update.php` (sem excluir).
- **W (`fo_funcionarios_s.php`):**
  - INSERT com `CODFORNECEDOR=$_GET['codigo']` e `STATUS='Pendente com a empresa'`;
  - UPDATE;
  - DELETE.
  - `CODTIPOFUNCIONARIO` é resolvido por `DSCTIPO`.

**`fo_funcionarioxtiposxcriterios.php` → `fo_funcionarioxtiposxcriterios_s.php`**
- **Chave:** `linha`. O `codigo` real vem numa coluna oculta.
- **Colunas:** `dsctipo`, `dscriterio`, `codusuario`, `atendeaocriterio`, `codusuario_responsavel_fornecedor`, `datadevalidade`, `datadereacao`, `tipo_de_documento`, `ARQUIVO`, `observacao`, `codfuncionario`, `codtipofuncionario`, `codcriteriofuncionario`, `qtddias` (calculado em JS).
- **Montagem do grid** (UNION):
  - primeira parte: critérios do tipo que já têm registro para o funcionário;
  - segunda parte: critérios do tipo sem registro, com `codigo=0`.
- **R:** `fo_tipofuncionariosxcriteriofuncionarios`, `fo_funcionarioxtiposxcriterios`, `fo_tipofuncionarios`, `fo_criteriofuncionarios` e `usuarios` (num trecho com **`green_crown_seguranca` fixo no código**).
- **Combos:**
  - `atendeaocriterio`: Sim, Não, NAP (Não aplicável), Desligado, Afastado;
  - `codusuario`: usuários internos (`codfornecedor=0`), com o sufixo "(Usuario desativado)" quando for o caso;
  - `codusuario_responsavel_fornecedor`: usuários de `fo_fornecedorxusuario` do fornecedor, mais 99999="Sem fornecedor responsavel".
- **W:**
  - `codigo=="0"` → INSERT (sem `observacao`);
  - senão, empresa ou gestor → UPDATE de `codusuario`, `atendeaocriterio`, responsável, validade, reação e observação;
  - fornecedor comum → UPDATE só de `datadevalidade` e `datadereacao`.

**`fo_funcionarioxtiposxcriterios_pendencias_documentos.php`** (mesmo save handler)
- **Visão da empresa:** `codusuario` = usuário logado, fornecedor com `ativadofor<>'Não'`.
- **Visão do fornecedor:** `codfornecedor in (codfornecedor1)` e `codusuario_responsavel_fornecedor` = usuário logado.
- **Colunas extras:** unidade, razão social, funcionário e `responsaveldocumentacaofornecedor` (e-mails).
- **Combo:** Sim/Não/NAP.
- **Caso especial:** em `cadastros_c.php`, para o `USERID=="joice.mota"` vale `$carregar="N"` (o grid não carrega automaticamente).

**`fo_funcionarioxtiposxtreinamentos.php` → `fo_funcionarioxtiposxtreinamentos_s.php`**
- **Limpezas no topo:** `delete ... where codtipofuncionario=0` e `where codtipotreinamento=0` (este último em duplicidade).
- **Inserção automática:** para o tipo do funcionário, insere os treinamentos de `fo_tipofuncionariosxtipotreinamentos` que faltam, com `treinamentorealizado=''` e `codusuario=0`.
- **Colunas:** `dsctipotreinamento`, `codusuario`, `treinamentorealizado` (Sim/Não), `responsaveltreinamento` (e-mail), `datadevalidadetreinamento`, `datadereacaotreinamneto`.
  - Na exibição, `datadereacaotreinamneto` é substituída por `DATEDIFF(datadevalidadetreinamento, CURRENT_DATE())`.
- **W:** UPDATE por `codigo`.

**Configuração de funcionário**

| Config | O que mantém |
|---|---|
| `fo_tipofuncionarios.php` | Tabela simples. Renderer `fo_tipofuncionarios_c.php` com abas Critérios e Treinamentos |
| `fo_tipofuncionariosxcriteriofuncionarios.php` | Corr. tipo func. × critério func. |
| `fo_tipofuncionariosxtipotreinamentos.php` | Corr. tipo func. × treinamento |
| `fo_criteriofuncionarios.php` | Critérios de funcionário (`TIPO_DE_DOCUMENTO`) |
| `fo_tipotreinamentos.php` | Tipos de treinamento |
| `fo_tipofuncxcriteriofuncxtreinamento.php` → `..._s.php` | Grid tipo × documento × treinamento. Só atualiza `codtipotreinamento`, resolvido por `DSCTIPO`. Nenhum arquivo do pacote insere linhas nesta tabela |
| `fo_funcionariosxtipofuncionarios.php`, `fo_funcionariosxfornecedores.php` | Corr. funcionário × tipo e funcionário × fornecedor |

### 2.6 Abas satélite do fornecedor

| Config | Save | Tabela e colunas | Combos |
|---|---|---|---|
| `fo_contatos.php` | generic `cadastros_s` | `FO_CONTATOS` (`CODCONTATO`, `CODFORNECEDOR`, `NOME_CONTATO`, `TELEFONES`, `CELULARES`) | – |
| `fo_certificados.php` | `fo_certificados_s.php` | `FO_CERTIFICADOS` (`CODCERTIFICADO`, `CODFORNECEDOR`, `DSCCERTIFICADO`, `NRDIAS`, `DTVALIDADE`) e anexo GED | – |
| `fo_produtoquimico.php` | `fo_produtoquimico_s.php` | `fo_produtoquimico` (`CODPRODUTO`, `DSCPRODUTO`, `NCAS`, `DATA_VALIDADE`, `NRDIAS_REACAO`, `PRODUTO_CONTROLADO`, FDS=`ARQUIVO`, `CARACTERISTICA`, `APROVADO`) | Controlado: Polícia Civil, Polícia Federal, Exército e as combinações. Característica: inflamável, explosivo, corrosivo, reativo, oxidante, tóxico. Aprovado: Sim/Não |
| `fo_equipamentos.php` | `fo_equipamentos_s.php` | `fo_equipamentos` (`CODEQUIPAMENTO`, `DSCEQUIPAMENTO`, `NRIDENTIFICACAO`, `DATA_VALIDADE`, `NRDIAS_REACAO`, `APROVADO`) | Aprovado: Sim/Não |
| `fo_planodeacao.php` | `fo_planodeacao_s.php` | `fo_planodeacao` (`CODPLANOACAO`, `codfornecedor`, `acao`, `codusuario`, `datainicial`, `datafinal`, `recursos`, `status`) | Status: 0/25/50/75/100%. Responsável por nome, convertido para `codusuario` no save |

Nas inclusões, a chave estrangeira entra porque o `incluir()` do `cadastros_c.php` grava `$codigo` (o `codfornecedor`) na primeira coluna da nova linha.

### 2.7 Relatórios e estatísticas

O renderer `fo_estatisticas_c.php` monta a árvore `fo_estatistica_fo.php`, com 12 nós. Cada nó abre um relatório via `cadastros_c.php?tabela=...&where=N`:

| Nó | Relatório (config) | Filtro |
|---|---|---|
| 1 / 2 Doc. fornecedor válida / expirada | `fo_relatorio_documentosexigidosfornecedor` | `datadevalidade>=now()` / `<now()` e `<>STR_TO_DATE('00/00/0000','%m/%d/%Y')` |
| 11 Documentos aprovados | idem | `situacao='Aprovado'` |
| 12 Documentos reprovados | idem | Situação pendente (com a empresa ou com o fornecedor) **e** algum `situacao='Reprovado'` no `fo_fornecedorxcriterios_historico` |
| 3 / 4 Auditoria válida / expirada | `fo_relatorio_auditoria` | Só tipos com `necessita_auditoria='Sim'`. `datavalidade` (de `fo_fornecedoresxtipofornecedores`) `>=` / `<` now |
| 5 / 6 Doc. funcionário válida / expirada | `fo_relatorio_documentacao_exigida_func` | View `vwfo_funcionarioxtiposxcriterios` com data em texto `dd/mm/yyyy` (`STR_TO_DATE`) |
| 7 / 8 Nível de serviço | `fo_relatorio_fornecedor` | `NIVEL_DE_SERVICAO='Satisfatório'` / `'Não Satisfatório'` |
| 9 / 10 Integração aprovada / necessita nova | `fo_relatorio_integracao` | `fo_funcionarios.data_validade >= now` / `< now` |

Relatórios que existem mas não estão na árvore: `fo_relatorio_treinamento`, `fo_relatorio_equipamentos`, `fo_relatorio_produtosquimicos` e `fo_relatorio_planodeacao`.
- `fo_relatorio_planodeacao` usa `getUsuario` e `concat(cnpj_cpf,'_')`, provavelmente para o Excel tratar o CNPJ como texto.
- O `fo_relatorio_fornecedor` também tem os filtros 11/12 por `fornecedoraprovadoauditoria`, que a árvore nunca aciona.
- Todos filtram fornecedores ativos (`ativadofor='Sim'` ou `in ('',null,'Sim')`).
- Botões: `botoes_recarrgar_excel_excel_por_email.php`.

**Visão de certificados:**
- `fo_situacao_certificados_c.php`: layout com grid alimentado por XML de `fo_certificados_status.php`. A config `fo_situacao_certificados.php` é só um stub copiado de contatos.
- `fo_situacao_dos_certificados.php`: relatório HTML com exportação Excel/PDF por `fo_excel.php` e `fo_pdf.php`. Status = `if(dtvalidade>now(),'No Prazo','Vencida')`, com a linha em vermelho quando vencida.
- `fo_certificados_fornecedor.php` e `fo_funcionarios_fornecedor.php`/`fo_funcionarios_certificados.php`: XML de subgrid.

**Lixo ou cópia de outros módulos:**
- `fo_estatisticas.php`: o `$select` consulta `objetivosmetas`/`objetivosmetas_acoes`. Só os títulos são aproveitados.
- `estatistica_fornecedores.php`, com os nós "Critérios não enviados", "Enviados sem retorno", "Planos em aberto", "Documentação vencida", "Homologados", "Não necessário auditoria" e "Auditorias pendentes". O renderer `estatistica_fornecedores_c.php` carrega `legislacaoxplanoacao`, ou seja, não está ligado a fornecedores.
- `fornecedores_estatisticas.php`: consulta `li_situacao`/`li_licencas`/`li_condicionantes`/`li_planodeacao`, que são do módulo de licenças.
- `fornecedores_itens_estatistica.php`: **erro de sintaxe PHP** (parênteses a mais).

### 2.8 Geração legada (sem `fo_`)

| Config | Renderer / Save | Tabelas |
|---|---|---|
| `fornecedor.php` | `fornecedor_c.php` → `fornecedor_s.php` | `FORNECEDOR`. Combo de UF. Abas: contatos (`fornecedorxcontatos`) e certificados (`certificados`). O DELETE apaga em cascata `FORNECEDORXCONTATOS`, `FORNECEDORXCERTIFICADOS`, `FORNECEDORXTIPOFORNECEDOR`, `planodeacao_fornecedores` (pelos critérios de `avaliacao_documental` e `avaliacao_sistemica`), `avaliacao_documental`, `avaliacao_amostra` e `avaliacao_sistemica`, mas **não** o registro em `FORNECEDOR` |
| `tipofornecedor.php` | `tipofornecedor_c.php` → `cadastros_s?tabela=tipofornecedor` | `tipofornecedor` (`AVALIACAO_DOCUMENTAL`, `AVALIACAO_SISTEMICA`, `INTEGRACAO`: S/N). Abas Corr.: `tipofornecedorxcriterios` (com `CRITERIOS`), `gruposxtipofornecedor_avaliacao_sistemica` (com `grupos_avaliacao_sistemica`), `tipofornecedorxfornecedor` (com `FORNECEDOR`), `tipofornecedorxaspectos` (com `ASPECTOS`) e `tipofornecedorxperigos` (com `PERIGOS`). Cada Corr. inclui `carga_avaliacao_documental.php` ou `carga_avaliacao_sistemica.php` |
| `fornecedoravaliacao.php` / `fornecedoravaliacao_sistemica.php` | `fornecedoravaliacao_c.php` / `fornecedoravaliacao_sistemica_c.php` | R: `fornecedor`, `TIPOFORNECEDORXFORNECEDOR`, `tipofornecedor`, `fornecedorxcontatos`, `usuarios`. Subgrid de avaliação documental (`DSCCRITERIO`, `OBRIGATORIO_DESEJAVEL`, `ATENDE` S/N, `CODUSUARIO`, `DATAREACAO`, `DATAVENCIMENTO`, `OBS`, `DOCUMENTO`), salvo em `avaliacaodocumental_s.php`. Versão sistêmica: `DSCGRUPO`, `DSCPERGUNTA`, `ATENDE`..., salvo em `avaliacaosistemica_s.php`. Abas "Plano de Ação" (`planodeacao_avaliacao_documental`/`_sistemica`) e, só na documental, "Enviar critérios ao fornecedor" |
| `enviar_criterios_fornecedor.php` | `enviar_criterios_fornecedor_s.php` | `enviar_criterios_fornecedor` (`codenvio`, `codavaliacao`, `dataenvio`, `prazoatendimento`). Combo com `avaliacao_documental` + `criterios` do fornecedor. **Nenhum e-mail é enviado aqui**: só ficam registradas as datas |
| `grupos_avaliacao_sistemica_c.php` | `cadastros_s?tabela=grupos_avaliacao_sistemica` | `grupos_avaliacao_sistemica`. Aba Corr. `gruposxperguntas_avaliacao_sistemica` |
| `gruposxtipofornecedor_avaliacao_sistemica.php` | Corr. | `gruposxtipofornecedor_avaliacao_sistemica(CODTIPOFORNECEDOR,CODGRUPO)` |
| `categoria_fornecedor.php` | generic | `categoria_fornecedor` (`CODCATEGORIA`, `DSCCATEGORIA`, `SIGLA`) |
| `fornecedorxcategorias.php` | Corr. | `CATEGORIA_FORNECEDOR` ↔ `FORNECEDORXCATEGORIA_FORNECEDOR`. Define `$database="green_legislacao"`, mas a variável não é usada |
| `fornecedorxcertificados.php` | generic | `FORNECEDORXCERTIFICADOS` (`CODIGO`, `CODFORNECEDOR`, `CODCERTIFICADO`, `ESCOPO`). Combo vindo de `certificados` |
| `fornecedorxcontatos.php` | generic | `FORNECEDORXCONTATOS` (`CODCONTATO`, `CODFORNECEDOR`, `CODUSUARIO`, `TELEFONES`, `CELULAR`) |
| `fornecedorxperigoxaspecto.php` | somente leitura | `fornecedor`, `aspectos`, `perigos`, `tipofornecedorxfornecedor`, `tipofornecedorxaspectos`, `tipofornecedorxperigos` |
| `certificados_s.php` + `upload_certificados.php` | – | `certificados` (`CODFORNECEDOR`, `DSCCERTIFICADO`, `documento`). O upload vai para `e:/wamp/www/Green/Upload/certificados/<cod>` e grava um `<a href>` na coluna `documento` |
| `criterios_c.php` | `cadastros_s?tabela=criterios` | `CRITERIOS`. Aba `criteriosxlegislacao`, que liga ao módulo de legislação. Seed em `configuracoes/criterios.sql`: 52 critérios com `OBRIGATORIO_DESEJAVEL='O'` (ASO, PPRA, PCMSO, ART, AVCB, IBAMA, ANTT, MOPP, licenças PC/PF/Exército, NR-10 etc.) |
| `integracao_s.php` | – | `integracao` (`CODINTEGRACAO`, `NOME`, `CARGO`, `NRREGISTRO`, `DATA_INTEGRACAO`). Não há config nem ligação com fornecedor |

### 2.9 NC por fornecedor

**`nc_classificacaofornecedores.php`**
- Mantém `nc_classificacao_fornecedor` (`codclassificacao`, `dscclassificacao`).
- Registra uso em `taximetro`.

**`nc_ocorrencias_por_fornecedor.php`** (somente leitura, abre o formulário de NC)
- **Correção de dados no topo:** `update nc_indicacao_ocorrencia set etapa='Plano de Ação'` onde a etapa estiver como `'Plano de Acao'` ou com o encoding quebrado.
- **R:**
  - `nc_indicacao_ocorrencia`, `nc_classificacao_fornecedor`, `fo_fornecedores`, `nc_tipo_de_naoconformidade`, `AREAS`, `nc_tipoocorrencia`, `nc_tipoacao`, `nc_origemacao`, `nc_siglagestao`, `unidades`, `nc_listadistribuicao`;
  - no `<db>_seguranca`: `usuarios_emissor`, `usuarios_gestor`, `usuarios_responsavel`, `grupos`, `usuariosxgrupos`, `usuarios`;
  - funções `getNCPrazo`, `getNCDtaPlano`, `getStatusPlanoAcaoNC`.
- **Filtro:** `CODTIPONAOCONFORMIDADE=1 AND codfornecedor=<x>`.
  - Sem fornecedor informado, usa 99999999 (não traz nada).
  - Com `ETAPA=Todos`, traz `codfornecedor>0`.
- **Colunas específicas de fornecedor:** `DATA_DE_CONHECIMENTO_FORNECEDOR`, `DATA_RESPOSTA_FORNECEDOR` e `ENCERRADO`.
- **Permissão no duplo clique** (seção 3.7).

---

## 3. Regras de negócio encontradas

### 3.1 Geração automática ao abrir telas (GET com efeito colateral)
- **Documentos do fornecedor:** são criados em `fo_fornecedorxcriterios` a partir de tipo × critério.
  - Na aba Documentos, só para o **primeiro** tipo do fornecedor.
  - Na tela de pendências, para todos os fornecedores e todos os tipos.
- **Excluir um documento** (só ADM DA EMPRESA) grava o histórico e apaga a linha. Como a linha volta a ser gerada **vazia** na próxima abertura, na prática o "excluir" funciona como um "resetar".
- **Treinamentos do funcionário:** são criados a partir de `fo_tipofuncionariosxtipotreinamentos`.
- **Documentos do funcionário:** não são gravados ao abrir. Aparecem como linhas virtuais (`codigo=0`) e só são inseridos no primeiro salvamento.

### 3.2 Limpezas de órfãos no topo das páginas

| Página | DELETE |
|---|---|
| `fo_fornecedor.php` | `fo_fornecedoresxtipofornecedores` com tipo inexistente |
| `fo_criteriofornecedores.php`, `fo_tipofornecedores.php`, `fo_nivelexigencia.php` | `fo_tipofornecedoresxcriteriofornecedores` com tipo ou critério inexistente |
| `fo_auditoriacriterios.php`, `..._pendencias_documentos*.php` | `fo_fornecedorxcriterios` com `codtipo`/`codcriterio` fora de tipo × critério. **Remover um critério de um tipo apaga os documentos e status já preenchidos de todos os fornecedores** |
| `fo_auditoriacriterios_historico.php` | O mesmo no histórico, ou seja, **o histórico também é apagado** |
| `fo_funcionarioxtiposxtreinamentos.php` | Linhas com `codtipofuncionario=0` ou `codtipotreinamento=0` |
| `nc_ocorrencias_por_fornecedor.php` | UPDATE que normaliza a etapa |

### 3.3 Situação, cores e prazos dos documentos do fornecedor
- **`qtddias`** = `datediff(datadevalidade, now())`, calculado no SQL.
- **`datadereacao`** (Nº de dias antes da validade) é só gravado. Nenhum cálculo do pacote o usa: as cores usam 30 dias fixos.

**Cores na aba Documentos** (`$afterrefresh`, avaliadas na ordem):
1. Se `situacao='NA'`, ou sem responsável interno, ou sem responsável do fornecedor: sem cor.
2. Reprovado ou "Pendente com o fornecedor": vermelho `#FF6347`.
3. Aprovado: verde `#3CB371`.
4. `qtddias<0`: vermelho.
5. Data `00/00/0000`: sem cor (a regra está comentada).
6. Entre 0 e 30 dias: amarelo `#FFD700`.
7. Mais de 30 dias: verde.

Há um comentário no código com as regras pedidas pelo cliente "green_elis".

**Cores nas pendências:** a ordem é diferente.
1. Aprovado com data zerada: verde.
2. `qtddias<0`: vermelho.
3. Pendente com o fornecedor: vermelho. **A variante crown remove esta regra.**
4. Data zerada: vermelho.
5. Menos de 30 dias (`<30`, e não `<=30`): amarelo.
6. Mais de 30 dias: verde.
7. Aprovado: verde.

Consequência: a mesma linha pode ter cor diferente em cada tela.

**Legenda** (`botoes_update_refresh_excel_*`):
- verde = aprovado ou vencimento acima de 30 dias;
- amarelo = pendente ou entre 0 e 30 dias;
- vermelho = vencido, reprovado ou pendente com o fornecedor;
- branco = sem vencimento ou sem responsável.
- O texto da legenda é diferente para o fornecedor.

**Validações no cliente (`$regra`, executado antes de confirmar):**
- Empresa: se a situação for "Reprovado", a observação é obrigatória ("Favor digitar em observação o motivo da reprovação do documento!"). A gravação é bloqueada.
- Fornecedor: aviso quando a validade está `00/00/0000`. **Não bloqueia** (o `return` está comentado). `fechandouploadarquivos` repete o aviso depois do upload.

**Protocolo** (`fo_auditoriacriterios_s.php`):
- Valor "R" (Renovar?) é gravado como "S".
- Com "S": `datacobranca_emprotocolo = now()+30 dias`, só se ainda estiver nula.
- Com "R": recalcula `datacobranca_emprotocolo = now()+30 dias` sempre.
- Nenhum arquivo do pacote usa `datacobranca_emprotocolo`. Provavelmente há um job de cobrança fora do pacote.

**Auditoria trilhada:** cada UPDATE e DELETE gera um snapshot em `fo_fornecedorxcriterios_historico` (`CODUSUARIOALTERACAO`, `DATAALTERACAO`).

### 3.4 Quem vê o quê

| Perfil | Fornecedores | Documentos | Edição |
|---|---|---|---|
| Fornecedor comum | `codfornecedor1` | Nas pendências, só os que têm `codusuario_fornecedor` = ele e situação diferente de "Pendente com a empresa" | Validade, protocolo, dias de reação, observação do fornecedor, upload. Abas Certificados, Auditoria, Químicos, Equipamentos e Plano ocultas. Não exclui funcionário |
| Fornecedor gestor | `codfornecedor1` (filtro do grid de fornecedores) | Mesma visão da empresa | Igual à empresa nas telas de documentos |
| Usuário interno não-admin | Fornecedores das suas unidades (`usuariosxunidades`), só ativos | Pendências: onde ele é o `codusuario` responsável | Tudo, exceto excluir documento |
| ADMINISTRADOR / ADM DA EMPRESA | Todos os ativos. Em `green_crown`, todos, inclusive inativos | – | Excluir documento e ver histórico (ADM DA EMPRESA) |
| visitante / seguranca / grupos VISITANTE ou LEITURA | – | – | Sem botões |

- **"Responsável do fornecedor":** só pode ser escolhido entre usuários de `fo_fornecedorxusuario`.
- **"Responsável" interno:** a regra muda de tela para tela:
  - usuários com `codfornecedor=0` (em histórico, funcionários, treinamentos e na variante crown);
  - "todos os ativos" na pendência padrão;
  - um filtro com bug na aba Documentos (seção 6).
- **Exceções fixas no código:**
  - `Portaria.CPS` vê todos os fornecedores na árvore;
  - `joice.mota` não tem carregamento automático na pendência de funcionários;
  - `green_arys` usa um conjunto de botões sem Excel.

### 3.5 Funcionários
- **Cor da linha:**
  - Desligado, NAP ou Afastado: sem cor;
  - qualquer valor diferente de Sim (e não vazio): vermelho;
  - Sim com data zerada: verde;
  - data zerada: vermelho;
  - mais de 30: verde;
  - entre 0 e 30: amarelo;
  - menor que 0: vermelho.
- **`qtddias`** é calculado em JS como a diferença de inteiros `YYYYMMDD`, e **não em dias** (ver seção 6).
- **Integração:** a "Data de Realização da Integração" fica em `fo_funcionarios.DATA_VALIDADE`. O relatório considera "integração aprovada" quando essa data é maior ou igual a hoje.

### 3.6 Variantes por cliente e idioma
- `_crown`, escolhida em `cadastros_c.php` pelo nome do banco:
  - a visão da empresa mostra só "Pendente com a empresa";
  - a descrição aparece como `tipo/critério`;
  - sem a cor vermelha para "Pendente com o fornecedor".
- `_crown_en`: versão em inglês da anterior.
- `?locale=en` em `fo_fornecedor`.
- `GRUPO_IDIOMA="IDIOMA INGLES"`: redireciona para `cadastros_en/`.
- `DSCRITERIO_EN` em `fo_criteriofornecedores`.

### 3.7 Regras de NC (`nc_ocorrencias_por_fornecedor`)
Ao dar duplo clique numa NC:
- ADM DA EMPRESA pode abrir sempre.
- Etapas "Em criação" e "Recusado pelo Gestor": só o emitente.
- "Com o Gestor" e "Avaliação da Eficacia": só o gestor.
- "Em Analise da Causa Raiz" e "Recusado pela Avaliacao da Eficacia": só o responsável.
- "Plano de Ação": o responsável ou os responsáveis do plano (consulta a `nc_responsaveis_planodeacao.php`).
- Tipo "Planejamento": abre `../nc_planejamento/nc_formulario.php`.
- Nos demais casos, abre `../formulario/nc_formulario.php` em modo `Consulta`.

### 3.8 O que não foi encontrado
- **Plano de ação gerado automaticamente** (a partir de reprovação ou NC): não existe. `fo_planodeacao` é manual. Na geração legada, o plano está ligado ao critério da avaliação.
- **Envio de e-mail ao fornecedor:** nenhum código no pacote envia. O que existe:
  - o campo `fo_fornecedores.EMAILS`;
  - o campo `responsaveltreinamento`/`responsaveldocumentacaofornecedor` com e-mails;
  - o botão `excel_por_email` (`../excel_por_email/excel_por_email.php`, não incluído), cujo alerta fala em "dados das leis" (texto copiado de outro módulo);
  - o registro `enviar_criterios_fornecedor` (legado), que só guarda datas.
- **Cálculo de "homologado":** não existe um status consolidado. A homologação fica implícita nas cores e em `fornecedoraprovadoauditoria`.

---

## 4. Tabelas, views e funções referenciadas

### 4.1 Prefixo `fo_` (banco da empresa, `$_SESSION['database']`)

| Tabela / view | Colunas citadas | Páginas |
|---|---|---|
| `fo_fornecedores` | CODFORNECEDOR, CODUNIDADE, CNPJ_CPF, RAZAOSOCIAL, NOMEFANTASIA, ATIVADOFOR, CODUSUARIO_RESPONSAVEL_FORNECEDOR, CODUSUARIO_RESPONSAVEL_INTEGRACAO, TRABALHA_INSTALACAO_FORNECEDOR, TEM_PRODUTOS_QUIMICOS, TEM_EQUIPAMENTOS, NIVEL_DE_SERVICAO, DATA_DA_AVALIACAO, DATA_DA_PROXIMA_AVALIACAO, ENDERECO, CIDADE, ESTADO, CEP, EMAILS e, no handler antigo, CODTIPOFORNECEDOR e DENTRO_EMPRESA | fo_fornecedor, fo_fornecedores, fo_fornecedor(es)_s, tree, pendências, todos os fo_relatorio_*, fo_certificados_status, fo_situacao_dos_certificados, fo_fornecedorxusuario, fo_funcionariosxfornecedores, fo_funcionarios, nc_ocorrencias_por_fornecedor |
| `fo_tipofornecedores` | CODTIPOFORNECEDOR, DSCTIPO, NECESSITA_AUDITORIA | fo_tipofornecedores, fo_fornecedoresxtipofornecedores, fo_auditoriatipos, fo_auditoriacriterios*, fo_nivelexigencia, relatórios, fo_fornecedor_s |
| `fo_fornecedoresxtipofornecedores` | codfornecedorxtipofornecedores, codfornecedor, codtipofornecedor, datavalidade, datareacao, nota, fornecedoraprovadoauditoria | fo_fornecedor, Corr., fo_auditoriatipos(_s), fo_auditoriacriterios*, relatórios auditoria/fornecedor/documentos, fo_fornecedor_s |
| `fo_criteriofornecedores` | CODCRITERIOFORNECEDOR, DSCRITERIO, DSCRITERIO_EN | fo_criteriofornecedores, Corr., fo_nivelexigencia, fo_auditoriacriterios*, fo_fornecedoresxcriterios(_s), relatório de documentos |
| `fo_tipofornecedoresxcriteriofornecedores` | codtipofornecedor, codcriteriofornecedor, nivel_de_exigencia | Corr., fo_nivelexigencia(_s), fo_auditoriacriterios*, histórico, relatório, cleanups |
| `fo_fornecedorxcriterios` | codfornecedorxcriterios, codfornecedor, codtipo, codcriterio, enviadoodocumento, datadevalidade, emprotocolo, datacobranca_emprotocolo, datadereacao, situacao, incluidocontrato, codusuario, codusuario_fornecedor, observacao, observacao_do_fornecedor (nivel_de_exigencia aparece no SELECT, vindo do join) | fo_auditoriacriterios(_s), copiar_colar_s, pendências*, relatório de documentos |
| `fo_fornecedorxcriterios_historico` | CODHISTORICO, CODUSUARIOALTERACAO, DATAALTERACAO + todas as colunas acima | fo_auditoriacriterios_s, histórico, relatório (where=12) |
| `fo_fornecedoresxcriterios` (legado) | CODFORNECEDORXCRITERIO, CODFORNECEDOR, CODCRITERIO, DATA_VALIDADE, DATA_REACAO, NIVEL_EXIGENCIA | fo_fornecedoresxcriterios(_s), fo_fornecedor_s |
| `fo_fornecedorxusuario` | codusuario, codfornecedor | fo_fornecedorxusuario, combos de auditoriacriterios, histórico, pendências, funcionarioxtiposxcriterios |
| `fo_fornecedorxauditoria` | codfornecedorxauditoria, codfornecedor, datavalidade, datareacao, fornecedoraprovadoauditoria, enviadoodocumento, datavalidadedocuemnto, nrdiasreacao, situacao | fo_fornecedorxauditoria (morto?). A pasta GED `fo_fornecedorxauditoria` é usada como pasta de anexos de `fo_auditoriatipos` |
| `fo_fornecedorxtipoxcriterio` (view?) | codfornecedor, razaosocial, DSCTIPO, necessita_auditoria, DSCRITERIO, nivel_de_exigencia | fo_fornecedorxauditoria |
| `fo_funcionarios` | CODFUNCIONARIO, CODFORNECEDOR, CODTIPOFUNCIONARIO, NOME, CELULAR, TELEFONE, EMAIL, NRIDENTIFICACAO, SETOR, DATA_VALIDADE, DATA_DE_INICIO, STATUS, nrdias_reacao | fo_funcionarios(_s), tree, funcionarioxtipos*, treinamentos, relatórios integração/treinamento/doc. func., fo_funcionarios_fornecedor, situação de certificados |
| `fo_tipofuncionarios` | CODTIPOFUNCIONARIO, DSCTIPO | fo_tipofuncionarios, combos, Corr., relatórios |
| `fo_criteriofuncionarios` | CODCRITERIOFUNCIONARIO, DSCRITERIO, TIPO_DE_DOCUMENTO | fo_criteriofuncionarios, Corr., funcionarioxtipos*, fo_tipofuncxcriteriofuncxtreinamento, certificados de funcionário |
| `fo_tipofuncionariosxcriteriofuncionarios` | codtipofuncionario, codcriteriofuncionario | Corr., fo_funcionarioxtiposxcriterios |
| `fo_funcionarioxtiposxcriterios` | codigo, codfuncionario, codusuario, codtipofuncionario, codcriteriofuncionario, atendeaocriterio, codusuario_responsavel_fornecedor, responsaveldocumentacaofornecedor, datadevalidade, datadereacao, observacao | funcionarioxtipos*(_s), pendências de funcionário, fo_funcionarios_certificados, fo_situacao_dos_certificados |
| `vwfo_funcionarioxtiposxcriterios` (view) | codfuncionario, codtipofuncionario, dsctipo, dscriterio, atendeaocriterio, datadevalidade (texto), datadereacao, tipo_de_documento | fo_relatorio_documentacao_exigida_func (e uma consulta comentada em funcionarioxtiposxcriterios) |
| `fo_tipotreinamentos` | codtipotreinamento, DSCTIPO | fo_tipotreinamentos, Corr., treinamentos, fo_tipofuncxcriteriofuncxtreinamento(_s), relatório |
| `fo_tipofuncionariosxtipotreinamentos` | codtipofuncionario, codtipotreinamento | Corr., geração de treinamentos |
| `fo_funcionarioxtiposxtreinamentos` | codigo, codfuncionario, codtipofuncionario, codtipotreinamento, codusuario, treinamentorealizado, responsaveltreinamento, datadevalidadetreinamento, datadereacaotreinamneto | treinamentos(_s), relatório de treinamento |
| `fo_tipofuncxcriteriofuncxtreinamento` | codigo, codtipofuncionario, codcriteriofuncionario, codtipotreinamento | config e `_s` próprios |
| `fo_funcionariosxtipofuncionarios`, `fo_funcionariosxfornecedores` | codfuncionario, codtipofuncionario / codfornecedor | Corr. de fo_funcionarios_c |
| `fo_certificados` | CODCERTIFICADO, CODFORNECEDOR, DSCCERTIFICADO, NRDIAS, DTVALIDADE | fo_certificados(_s), fo_certificados_status, fo_certificados_fornecedor, fo_situacao_dos_certificados |
| `fo_contatos` | CODCONTATO, CODFORNECEDOR, NOME_CONTATO, TELEFONES, CELULARES | fo_contatos |
| `fo_produtoquimico` | CODPRODUTO, CODFORNECEDOR, DSCPRODUTO, NCAS, DATA_VALIDADE, NRDIAS_REACAO, PRODUTO_CONTROLADO, CARACTERISTICA, APROVADO | config, `_s`, relatório |
| `fo_equipamentos` | CODEQUIPAMENTO, CODFORNECEDOR, DSCEQUIPAMENTO, NRIDENTIFICACAO, DATA_VALIDADE, NRDIAS_REACAO, APROVADO | config, `_s`, relatório |
| `fo_planodeacao` | CODPLANOACAO, codfornecedor, acao, codusuario, datainicial, datafinal, recursos, status | config, `_s`, relatório |

### 4.2 Legado (sem prefixo)
- **Fornecedor e tipo:** `fornecedor`/`FORNECEDOR` (CODFORNECEDOR, RAZAOSOCIAL, NOMEFANTASIA, ENDERECO, CIDADE, ESTADO, CEP), `tipofornecedor` (CODTIPOFORNECEDOR, DSCTIPO, AVALIACAO_DOCUMENTAL, AVALIACAO_SISTEMICA, INTEGRACAO), `TIPOFORNECEDORXFORNECEDOR`/`FORNECEDORXTIPOFORNECEDOR` (os dois nomes aparecem, ver seção 6; tem a coluna codigo_tipofornecedorxfornecedor).
- **Critérios:** `CRITERIOS` (CODCRITERIO, DSCCRITERIO, OBRIGATORIO_DESEJAVEL), `tipofornecedorxcriterios`, `criteriosxlegislacao`.
- **Avaliações:** `avaliacao_documental` (codavaliacao, codfornecedor, codcriterio), `avaliacao_sistemica`, `avaliacao_amostra`, `planodeacao_fornecedores`, `enviar_criterios_fornecedor`.
- **Avaliação sistêmica:** `grupos_avaliacao_sistemica` (CODGRUPO, DSCGRUPO), `gruposxtipofornecedor_avaliacao_sistemica`, `gruposxperguntas_avaliacao_sistemica`.
- **Satélites:** `certificados` (CODCERTIFICADO, CODFORNECEDOR, DSCCERTIFICADO, documento), `FORNECEDORXCERTIFICADOS`, `FORNECEDORXCONTATOS`, `categoria_fornecedor`/`CATEGORIA_FORNECEDOR`, `FORNECEDORXCATEGORIA_FORNECEDOR`.
- **Perigos e aspectos:** `tipofornecedorxaspectos`, `tipofornecedorxperigos`, `ASPECTOS`/`aspectos` (CODASPECTO, DSCASPECTO), `PERIGOS`/`perigos` (CODPERIGO, DSCPERIGO).
- **Integração:** `integracao`.

### 4.3 Tabelas comuns e de outros módulos
- **Cadastro geral e uso:** `unidades` (codunidade, dscunidade), `AREAS`, `taximetro` (data_taximetro, tabela, qtdtransacoes_select/insert/update/delete/excel, userid).
- **Banco `<db>_seguranca`:** `usuarios` (codusuario, nome, userid, ativado, codfornecedor), `usuariosxunidades`, `grupos`, `usuariosxgrupos`, `usuarios_emissor`/`usuarios_gestor`/`usuarios_responsavel` (provavelmente views).
- **NC:** `nc_indicacao_ocorrencia`, `nc_classificacao_fornecedor`, `nc_tipo_de_naoconformidade`, `nc_tipoocorrencia`, `nc_tipoacao`, `nc_origemacao`, `nc_siglagestao`, `nc_listadistribuicao`.
- **Cópia de outros módulos:** `objetivosmetas`, `objetivosmetas_acoes`, `li_situacao`, `li_licencas`, `li_condicionantes`, `li_planodeacao`.
- **Funções armazenadas:** `getTipoFornecedores`, `getDSCUNIDADE`, `<db>_seguranca.getCODUNIDADE`, `<db>_seguranca.getUsuario`, `green_universal.MASK`, `getNCPrazo`, `getNCDtaPlano`, `getStatusPlanoAcaoNC`.
- **Bancos citados pelo nome:** `green_universal` (função MASK e `$databse` em tipofornecedorxcriterios), `green_crown`, `green_crown_seguranca`, `green_legislacao`, `green_arys`, `green_elis` (este só em comentário) e `green_luxottica` (num SQL de exemplo em comentário).

---

## 5. Integrações com outros módulos

| Módulo | Ponto de integração |
|---|---|
| **NC / Não conformidades** | `nc_ocorrencias_por_fornecedor` (NC tipo 1 com `CODFORNECEDOR` → `fo_fornecedores`). Formulários `../formulario/nc_formulario.php` e `../nc_planejamento/nc_formulario.php`. `nc_classificacao_fornecedor`. Datas de conhecimento e resposta do fornecedor |
| **GED / Upload** | `uploadarquivos('<tabela>', id)` (de `upload.js`), com pastas `E:\projetoGreen\GED\<db>\<tabela>\<id>` para fo_fornecedores, fo_fornecedorxcriterios, fo_fornecedorxcriterios_historico, fo_fornecedorxauditoria, fo_certificados, fo_funcionarios, fo_funcionarioxtiposxcriterios, fo_funcionarioxtiposxtreinamentos, fo_produtoquimico, fo_equipamentos e fo_planodeacao. Mecanismo antigo `upload('<tabela>_<id>','<tabela>')` nos relatórios. Upload legado `upload_certificados.php` (`e:/wamp/www/Green/Upload/certificados`) |
| **Agenda / Eventos** | Cronograma de auditoria: `../cadastros/ev_schedule_c.php?codauditoria=<codfornecedorxtipofornecedores>` |
| **Treinamentos** | Só os internos ao módulo (`fo_tipotreinamentos` etc.). Não há ligação com o módulo corporativo de treinamento |
| **Perigos / Aspectos (LAIA/PGR)** | Legado: `tipofornecedorxaspectos`/`tipofornecedorxperigos` e o relatório `fornecedorxperigoxaspecto` |
| **Legislação** | Legado: `criterios_c.php` → `criteriosxlegislacao`. Variável `green_legislacao` em fornecedorxcategorias |
| **E-mail** | Só indireto: `excel_por_email` e os campos de e-mail. Possível job de cobrança por `datacobranca_emprotocolo` (fora do pacote) |
| **Segurança / Usuários** | `_seguranca.usuarios`, `usuarios.codfornecedor`, `fo_fornecedorxusuario`, unidades por usuário, grupos |
| **Exportação** | `ExcelFiltrado.php`, `fo_excel.php` (HTML→xls, registra uso em taximetro), `fo_pdf.php` (`E:/html2fpdf-3.0.2b`) |
| **Telemetria** | `taximetro` gravado em praticamente todos os `_s.php` |

---

## 6. Pontos de atenção, riscos e dúvidas

### 6.1 Segurança
- **SQL montado por concatenação em todo o módulo**, com `$_GET["codigo"]`, `$_POST[...]` e `filtro like '<x>%'` sem escape nem prepared statements. Em vários lugares o número é apenas prefixado com `0` (`codfornecedor=0".$_GET["codigo"]`).
- **`$_SESSION['codfornecedor']` é um fragmento de SQL** colado em `WHERE` (`where ativado='S' <fragmento>`).
- **`cadastros_c.php`, `criterios_c.php`, `fo_*_c.php` fazem `require_once("../configuracoes/" . $_GET["tabela"] . ".php")`**: risco de inclusão de arquivo arbitrário (path traversal).
- **As restrições do fornecedor existem só na interface.** Os `_s.php` não conferem se o `rowId` pertence aos fornecedores do usuário, e o `fo_auditoriacriterios_copiar_colar_s.php` aceita quaisquer IDs de origem e destino. Isso inclui copiar arquivos do GED entre fornecedores (IDOR).
- **SQL gravado em disco:** `fopen("e:\\....sql")` em várias páginas, e `c:\fo.sql` em `fo_funcionarioxtiposxtreinamentos_s.php` e `fo_tipofuncxcriteriofuncxtreinamento_s.php`. Há vazamento de consultas, concorrência entre usuários e falha fora do Windows.
- **Usuários e bancos fixos no código:** `Portaria.CPS`, `joice.mota`, `green_crown`, `green_crown_seguranca`, `green_arys`.

### 6.2 Bugs prováveis
1. **Combo de responsável interno na aba Documentos** (`fo_auditoriacriterios.php`): o filtro é `codfornecedor not in (select codusuario from fo_fornecedorxusuario ...)`, comparando a coluna `codfornecedor` com códigos de usuário. O provável era `codusuario not in`.
2. **Chaves por concatenação ambíguas:** `CONCAT_WS('',codtipo,codcriterio)` e `concat(codtipofornecedor,codcriteriofornecedor)` geram a mesma chave para (1,23) e (12,3). Isso pode juntar documentos errados ou impedir a geração de algumas linhas.
3. **`CONCAT_WS` com só um argumento depois do separador** (`fo_funcionarioxtiposxcriterios.php`): `CONCAT_WS(codtipofuncionario, codcriteriofuncionario)` devolve apenas o critério. O join ignora o tipo e não filtra o funcionário no `ON`.
4. **Aba Documentos gera critérios só do primeiro tipo** do fornecedor (`if($linha = mysqli_fetch_row(...))`). A tela de pendências gera para todos os tipos.
5. **Copiar/colar, ramo da empresa:** `enviadoodocumento=` é concatenado sem aspas (`enviadoodocumento=Sim`), o que gera erro de SQL e faz o UPDATE inteiro falhar quando esse campo está vazio no destino.
   - `mysqli_affected_rows()` é chamado sem a conexão.
   - No ramo do fornecedor, a variável `$datadevalidade` é calculada e não é usada.
   - O teste `enviadoodocumento=="0"` nunca é verdadeiro.
6. **`fo_certificados_s.php` e `certificados_s.php`: no DELETE executam `$conn->query(utf8_encode($sql))` de novo.** Num lote, `$sql` ainda contém o INSERT ou UPDATE da linha anterior, que é re-executado (pode duplicar registros).
7. **Funções sem acesso a `$conn`:** `getnome`, `getcertificadosfornecedor`, `getfuncionariosfornecedor` e `getcertificadosfuncionarios` usam `$conn` sem `global`. Isso dá erro fatal em `fo_certificados_status.php`, `fo_situacao_dos_certificados.php`, `fo_certificados_fornecedor.php`, `fo_funcionarios_fornecedor.php` e `fo_funcionarios_certificados.php`.
   - `fo_excel.php` usa `$conn` sem incluir `database.php`.
8. **`qtddias` dos funcionários calculado errado:** é a diferença de inteiros `YYYYMMDD`. Exemplo: de 31/01 a 01/02 dá 70, não 1. As faixas de cor de 30 dias ficam incorretas.
9. **Treinamentos: o campo de dias de reação é sobrescrito.** A coluna `datadereacaotreinamneto` é exibida como `DATEDIFF(validade, hoje)` e é editável. O save grava esse valor de volta, perdendo o parâmetro original.
10. **Reação a validade nula** em `fo_relatorio_*`: `datadevalidade<>STR_TO_DATE('00/00/0000','%m/%d/%Y')`. Com `NO_ZERO_DATE`/modo estrito, `STR_TO_DATE` devolve NULL, a comparação vira NULL e os relatórios saem vazios.
    - Além disso, `ativadofor in ('',null,'Sim')` nunca casa com NULL.
11. **Grid `fo_fornecedor` duplica linhas** quando o fornecedor tem mais de um tipo (left join em `fo_fornecedoresxtipofornecedores` sem agrupar). A coluna `codtipofornecedor` não qualificada pode dar erro de ambiguidade se `fo_fornecedores.CODTIPOFORNECEDOR` ainda existir.
12. **Exclusões sem cascata e cleanups destrutivos:**
    - `fo_fornecedores_s.php` apaga só `fo_fornecedores`, deixando órfãos em `fo_fornecedorxcriterios`, `fo_fornecedoresxtipofornecedores`, `fo_funcionarios` etc.;
    - `fornecedor_s.php` (legado) apaga os filhos mas **não** o próprio `FORNECEDOR`;
    - os cleanups no topo das páginas apagam documentos e **histórico** quando um critério sai de um tipo (perda de evidência de auditoria).
13. **Exclusão de documento é desfeita na prática:** a linha excluída volta vazia na próxima abertura (a GET recria).
14. **Rótulos inconsistentes no `taximetro`:** `fo_fornecedores_s.php` grava `'fo_produtoquimico'` no INSERT. Os `_s` registram `qtdtransacoes_select` em operações de escrita.
15. **Joins suspeitos:**
    - `fornecedorxperigoxaspecto.php` usa `tipofornecedorxfornecedor.codigo_tipofornecedorxfornecedor = tipofornecedorxaspectos.codtipofornecedor` (o provável é CODTIPOFORNECEDOR);
    - `nc_ocorrencias_por_fornecedor` junta `nc_classificacao_fornecedor.CODCLASSIFICACAO` e `nc_tipoocorrencia` ambos com `CODTIPOOCORRENCIA`.
16. **Diferenças entre combos de situação:** a aba Documentos tem 8 valores. Pendências e histórico têm 5. Valores como "Aprovado sem validade", "Ciente da regra" e "Não enviar ao fornecedor" não têm rótulo nem regra de cor nessas telas.
17. **`fo_funcionarios.php`:** o combo Sim/Não está preso ao índice 7 (`NRIDENTIFICACAO`), sobra de outra versão.
18. **Nome vs. código:**
    - os responsáveis em `fo_fornecedor` e `fo_planodeacao`, e os tipos em `fo_funcionarios` e `fo_tipofuncxcriteriofuncxtreinamento`, são gravados por **nome/descrição**, resolvidos com `where nome='...'`. Homônimos ou acentuação diferente (`utf8Fix`) levam ao código errado ou a 0;
    - os nomes das tabelas aparecem com caixa misturada (`FO_CERTIFICADOS`, `FO_TIPOFUNCIONARIOS`, `FO_FORNECEDORES`, `TIPOFORNECEDORXFORNECEDOR` vs `FORNECEDORXTIPOFORNECEDOR`). Isso só funciona com `lower_case_table_names` ativo (Windows). **Risco alto numa migração para Linux.**
19. **Expressões `function ($msg=null){...}` sem nome** em `fo_equipamentos_s.php` e `fo_produtoquimico_s.php`: código morto.
20. **`fo_funcionarioxtiposxcriterios_s.php`:** o INSERT não grava `observacao`. O fornecedor vê os combos mas só consegue salvar validade e reação.
21. **`fo_funcionarios_c.php` usa `cadastros_s.php?tabela=fo_funcionarios`** (genérico), enquanto a aba do fornecedor usa `fo_funcionarios_s.php`. São duas regras de gravação diferentes, e na tela avulsa o `CODFORNECEDOR` pode não ser gravado.

### 6.3 Páginas aparentemente mortas, antigas ou copiadas
- **Copiadas ou quebradas:**
  - `fo_estatisticas.php` (select de objetivos e metas);
  - `estatistica_fornecedores.php` + `estatistica_fornecedores_c.php` (ligado a legislação);
  - `fornecedores_estatisticas.php` (licenças);
  - `fornecedores_itens_estatistica.php` (erro de sintaxe);
  - `fo_situacao_certificados.php` (stub de contatos).
- **Substituídas:** `fo_fornecedor_s.php`, `fo_fornecedores.php`, `fo_fornecedoresxcriterios.php`/`_s.php`, `fo_fornecedorxauditoria.php`.
- **Legado inteiro, provavelmente sem uso:** fornecedor, tipofornecedor, avaliações, envio de critérios, grupos sistêmicos, categorias, `certificados_s`, `upload_certificados`, `integracao_s`.
- **Pastas:** a pasta `configuracoes/` só tem cópias idênticas dos 7 `.php`, mais `criterios.sql`.

### 6.4 Dúvidas para confirmar com você
1. **Geração legada:** ainda está em uso em algum cliente? Avaliação documental e sistêmica e o "Enviar critérios ao fornecedor" devem entrar no novo sistema?
2. **Como é preenchido `$_SESSION['codfornecedor1']`?** Pela tabela `fo_fornecedorxusuario` ou por `usuarios.codfornecedor`? O que define `FORNECEDOR_GESTOR`?
3. **`datadereacao` e `datacobranca_emprotocolo`:** existe algum job (cron) que envia e-mails de vencimento ou cobrança usando esses campos? Onde fica?
4. **Regra oficial de cores e status:** a das pendências ou a da aba Documentos? Os 8 valores de situação valem para todos os clientes ou são do green_elis/crown?
5. **Integração do funcionário:** `fo_funcionarios.DATA_VALIDADE` é a data de **realização** (como diz o rótulo) ou de **validade** (como usa o relatório 9/10)? Qual é o prazo de validade da integração?
6. **Um fornecedor com vários tipos** deve ter os documentos de todos os tipos na aba Documentos? Hoje só o primeiro tipo gera linhas ali.
7. **Excluir documento:** deve ser exclusão definitiva ou "reset"? O histórico deve ser preservado quando um critério sai de um tipo? Hoje é apagado.
8. **Auditoria:** a "nota" tem faixa (o relatório diz 0 a 100)? Há nota mínima para aprovar? O cronograma (`ev_schedule`) faz parte do escopo?
9. **"Homologado":** existe ou deve existir um status consolidado (documentos aprovados + auditoria aprovada + nível de serviço)? A árvore legada cita "Homologados" e "Auditorias pendentes", mas não há implementação.
10. **Plano de ação:** deve ser gerado automaticamente a partir de um documento reprovado ou de uma NC, ou continua manual?
11. **Variantes por cliente:** quais bancos ativos usam `_crown`/`_en` e `locale=en`? As exceções `Portaria.CPS` e `joice.mota` ainda são necessárias?
12. **Tabelas N:N redundantes:** `fo_funcionariosxtipofuncionarios` e `fo_funcionariosxfornecedores` são usadas (um funcionário pode ter mais de um tipo ou fornecedor), ou valem só as colunas de `fo_funcionarios`?
13. **`fo_tipofuncxcriteriofuncxtreinamento`:** quem insere as linhas (trigger, outro módulo)? Qual é a finalidade do vínculo documento → treinamento?
14. **Cabe pedir os arquivos que faltam:** `cadastros_s.php`, `busca.php`, `correlacionadas_c.php`, `upload.js`, `carga_avaliacao_*.php`, `parametros.php`, as definições das views e funções (`vwfo_funcionarioxtiposxcriterios`, `fo_fornecedorxtipoxcriterio`, `getTipoFornecedores`, `getCODUNIDADE`, `getNCPrazo`...) e a pasta `cadastros_en/`. Com eles dá para confirmar as gravações das páginas genéricas e as regras de sessão.

---

## 7. Teste visual no legado (greensolutions.com.br, Atlas, admin — 16/10/2026)

Só navegação; nada salvo. Aba Documentos, telas de pendências e seleção de linha na Gestão de Fornecedores não foram abertas (gravam ao abrir).

### Menu real "Homologação Fornecedores"
- Cadastro de Critérios de Funcionários: Critérios dos Funcionários, Tipos de Treinamentos, Tipos de Funcionários
- Cadastro de Fornecedor: Critérios dos Fornecedores, Tipos de Fornecedores, Dados Básicos, **Nível Criticidade**, **Avaliação**
- Gestão de Fornecedores (8 abas: Contatos, Certificados, Auditoria de Homologação, Documentos, Funcionários, Produtos Químicos, Equipamentos, Plano de Ação; botão "Gera excel com Fornecedores + Critérios")
- Gestão de Funcionários (árvore Unidade/Fornecedor + grid de funcionários + abas Critério/Treinamento)
- Gestão de Fornecedores desativados
- Relatórios Gerenciais Homologação: **Dashboard**, Gerenciamento de Integração / Documentos Exigidos Funcionários / Treinamento / Auditoria / Documentos Exigidos Fornecedor / Plano de Ação / Produtos Químicos / Equipamentos, **Relatório unificado de funcionários**, **Histórico de alterações de documentos**
- Estatísticas (árvore com os 12 nós previstos no código)
- Home: abas de pendência "Documentação de Fornecedores" e "Documentação de Funcionários"

### Itens que NÃO estavam no código analisado (pedir fonte)
- Tipos de Fornecedores → aba **Palavras Chave** (875 palavras, correlacionadas ao tipo). A aba de critérios já mostra o nível de exigência junto (Correlacionados/Nível de exigência).
- **Nível Criticidade**: grid Critério / Texto do SAP / Peso — vazio na Atlas.
- **Avaliação**: avaliação periódica — Fornecedor, Período inicial/final, Nota qualidade, Nota quantidade, Nota avaliação ambiental, Não conformidade, Tipo de desvio, Classificação, Nível de criticidade, Observação (PT/EN), Divisão, Relatório… — vazio na Atlas.
- Dados Básicos: colunas extras "Avaliação do Nível do Serviço", "Evidência Nível Serviço" (Arquivos).
- **Dashboard** moderno (Dados de 16/10/2026, Gerar PDF), duas visões:
  - Fornecedores: 125 fornecedores ativos, 650 critérios, 286 atendidos, 364 pendentes, 58 vencidos, 8 vencem em 30 dias, 54 vigentes, 248 enviados; por situação (Pendente com o fornecedor 283, Não informada 206, Pendente com a empresa 81, Aprovado 57, Aprovado sem validade 18, NA 5); por nível (Obrigatório 513, Não informada 130, Desejável 7); validade × situação; fornecedores com pendências (102); por unidade (14); por tipo (62 com dados).
  - Funcionários: 718 funcionários, 2.172 avaliações, Sim 1.960, Não 144, Desligado 48, Não informado 9, NAP 6, Afastado 5; 455 vencidos, 31 vencem 30d, 211 vigentes, 1.475 sem data; por tipo de funcionário (211 tipos).

### Dados observados
- 115 tipos de fornecedor, 154 critérios de fornecedor.
- Mesmo CNPJ cadastrado em várias unidades (fornecedor é por unidade).
- Auditoria: tipos "Destinação de Resíduos" com necessita auditoria = Sim, mas nota/validade vazias — auditoria pouco usada.
- Tipos de funcionário são funções prefixadas pela unidade ("MTZ - MOTORISTA - RODORUMO"); Nº registro guarda CPF; Data de início vazia (0000-00-00). 429 funcionários no relatório unificado.
- `/principal/` do servidor tem listagem de diretório aberta (Options -Indexes).

---

## 8. Levantamento do banco + o que o greenportal já tem (16/10/2026)

Fontes: scripts/levantamento-fornecedores-legado.php (JSON em scripts/saida), código do greennew (dashboard, clsGrid), código atual do greenportal.

### Menu legado (green_seguranca.menu) — itens por transação (hpr_configuracoes)
foTiposdeFornecedor (430), foTiposdeFuncionarios (427), foTiposTreinamentos (496), foRelatorioDocumentosexigidosFornecedor (509), foFuncionariosLista (719), foFornecedorxCriteriosHistorico (752), foNivelCriticidade (855), foAvaliacao (856), Portaria: foFornecedorxCriterios(Elis), foFuncionariosXCriterios(Elis); Dashboard (909) = ../greennewd/interface/dashboard.php. Motor: greennew/classes/clsGrid.php (configuracoes_transacoes.CQUERY + configuracoes_grids; só leitura; #WHERE# recebe $_GET cru).

### Dados em uso (green_atlas)
- 144 fornecedores (143 ativos); 137 com 1 tipo, mas um com 121 tipos e outro com 36.
- Documentos (742): Pendente c/ fornecedor 300, Aprovado 62, Pendente c/ empresa 47, Aprovado sem validade 22, NA 5, vazio 26. Protocolo praticamente sem uso (S=2, N=5); cobrança preenchida em 4.
- Nível de exigência: Obrigatório 291, Desejável 9, vazio 31. Auditoria aprovada: nenhum registro preenchido.
- Funcionários 681 (todos STATUS "Pendente com a empresa"); critérios de funcionário: Sim 2505, Não 155, Desligado 48, NAP 10, Afastado 5.
- Histórico de documentos: 4,5 milhões de linhas (snapshot a cada gravação).
- Geração antiga (avaliação documental/sistêmica, critérios, certificados legados, integração): todas as tabelas com 0 linhas → fora do escopo.
- fo_avaliacao, fo_criterioavaliacao, fo_classificacao, fo_frequenciaavaliacao, fo_lote, fo_pedidos, fo_template, *_palavraschave: 0 linhas.

### Já existe no greenportal
Gestão de Fornecedores (8 abas via HomologacaoFornecedorRelacionamentoGrid), Gestão de Funcionários, cadastros de critérios/tipos de funcionário e treinamentos (mestre no novo), Portaria (documentação de fornecedor/funcionário/treinamentos, só leitura), IA/OCR de documentos, Ranking A–D (35/25/25/15) com worker, Bloqueios, Responsabilidades, pendências na Home, sincronização diária legado → novo (scripts/sincronizar-fo-legado.php; legado prevalece; protege fo_sinc_protecoes).

### Gaps principais
Aba Documentos completa (responsáveis, protocolo, reação, contrato, geração de linhas, histórico, copiar/colar, regra de reprovação), histórico de alterações, Tipos/Critérios de fornecedor + nível de exigência (sem tela), desativados, perfil fornecedor externo, Dashboard, relatórios gerenciais e estatísticas, pendências de funcionários, árvore e cores de funcionários, cronograma de auditoria.

### Divergências legado × novo
fo_fornecedores 144×174 (provisórios da 217); tipofunc×critério 1246×822 e tipofunc×treinamento 389×202 (limpeza 249, mestre no novo); histórico 4,5M×2178 (217 separou; backup _bkp217); pequenas diferenças em documentos → sincronismo possivelmente não rodando.

### Decisões em aberto
Dono dos dados/data da virada; gravações no novo sobrescritas pelo sincronismo; definição oficial de "atendido"; regra única de cores/situações; destino do histórico; escopo (criticidade, avaliação, palavras-chave, relatórios, fornecedor externo); efeito do bloqueio; confirmar agendamento do sincronismo.

---

## 9. Transações do legado (hpr_configuracoes) — 16/10/2026

Fonte: scripts/levantamento-transacoes-fornecedores-legado.php (JSON em scripts/saida). Motor: configuracoes_transacoes (cquery, cqueryexcel, selectform, sql_de/sql_para para correlação), configuracoes_grids (colunas, tipo, obrigatório), configuracoes_tabulacoes (abas), configuracoes_atualizacoes (gatilhos APOS INSERT/UPDATE/DELETE em SQL ou PHP), configuracoes_normalizarfiltro (filtros por coluna), configuracoes_grupoxfiltro (filtro por perfil).

### Cadastros em uso
- foTiposdeFornecedor (fo_tipofornecedores: DSCTIPO, NECESSITA_AUDITORIA) com abas: Critérios (correlação foTipoFornecedoresXCriteriofornecedores) e Palavras Chave (foTipofornecedoresxPalavraschave).
- foCriterioXNivelExigencia: nível de exigência por tipo × critério.
- foTiposdeFuncionarios com abas Critérios (rótulo "tipo_de_documento/critério"), Treinamentos e Palavras chave; foTiposTreinamentos.
- Palavras-chave: as 875 vêm do catálogo GREEN_UNIVERSAL.PALAVRAS_CHAVES; as tabelas de vínculo (tipo de fornecedor/funcionário × palavra) estão vazias → recurso nunca usado.

### Relatórios/consultas
- foRelatorioDocumentosexigidosFornecedor: sobre a view vdocumentosexigidosfornecedor; só fornecedores ativos (grupoxfiltro TODOS); colunas unidade, razão social, fantasia, tipo, documento, nível, enviado, validade, protocolo, reação, situação, contrato, usuário validador, usuário fornecedor, observação.
- foRelatorioDocumentosexigidosFuncionarios (vwfo_funcionarioxtiposxcriterios, fornecedor ativo).
- foFuncionariosLista (relatório unificado), foFornecedorxCriteriosHistorico (histórico completo: alterado por, data, todos os campos do documento, data da cobrança).
- Portaria: foFornecedorxCriterios / foFuncionariosXCriterios (+Elis) — só leitura, com evidências; filtro por unidades do usuário para o perfil HOMOLOGACAO.

### Sub-módulo de Avaliação de desempenho (SAP) — desenhado, nunca usado (tabelas com 0 linhas)
- foParametrosSAP (fo_parametros): prazo de entrega, pesos de avaliação ambiental, quantidade, qualidade, NC, tipo de desvio, criticidade ambiental, nível de criticidade, classificação.
- foNivelCriticidade (fo_criterioavaliacao): critério, texto do SAP, peso.
- foPedidos (fo_pedidos): pedido, produto/serviço, data e qtd prevista × entregue, origem, nota de prazo e de nota fiscal, criticidade.
- foLote (fo_lote): lote do pedido, qtd extraída, notas de qualidade/quantidade/ambiental, NC, desvio, classificação.
- foAvaliacao (fo_avaliacao): avaliação por fornecedor e período (notas, NC, desvio, classificação, criticidade, divisão, obs PT/EN); após incluir/alterar gera o "Relatório de Avaliação" (gerarRelatorioAvaliacao).
- foClassificacao (fo_classificacao): faixas de pontuação → classificação + ação.
- foTemplate: sem configuração.
- Indícios de que nunca rodou: cquery de foClassificacao com "ORDEM BY" e de foPedidos com ",," (erros de SQL).
- Sobreposição com o greenportal: o Ranking A–D já cumpre o papel de classificação por pontuação.

### Outros
- gdeFornecedores (GDE-Engenharia): cadastro de fornecedor em formulário + aba Fornecedor × Usuários; após excluir apaga fo_fornecedorxusuario.
