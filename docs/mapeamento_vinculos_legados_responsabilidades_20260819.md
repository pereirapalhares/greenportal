# Mapeamento dos vínculos legados — Responsabilidades

**Data:** 19/08/2026  
**Tenant analisado:** `gmodelo_dbp`  
**Status:** análise; nenhuma atribuição foi migrada

## 1. Decisões funcionais aprovadas

As seguintes regras foram confirmadas para a empresa Modelo:

1. Responsabilidades de usuários inativos continuarão preservadas como
   histórico. Elas não poderão permanecer como novos vínculos ativos e deverão
   aparecer como pendências para regularização ou delegação.
2. Os campos `codgrupo`, `codgrupo_validade`, `codgrupo_reacao`,
   `codgrupo_protocolo` e `codgrupo_acesso` de `li_licencas` representam grupos
   diferentes para responsabilidades diferentes da mesma licença. Cada campo
   deverá ser convertido em um vínculo separado, com sua finalidade registrada.
3. `planodeacao.codusuario` representa exclusivamente o usuário responsável
   pela ação do plano de ação.
4. Na Homologação, o responsável será o usuário responsável pela homologação,
   pelo fornecedor ou pela documentação do fornecedor, conforme o vínculo
   funcional do registro.
5. Infraestrutura e Controle utilizarão usuários da tabela `usuarios`, por
   meio de campos `CODUSUARIO` ou `codusuario` das respectivas tabelas de
   gestão. Não serão criados responsáveis artificiais.

## 2. Regra de segurança da análise

Este documento apenas identifica possíveis origens de responsabilidade. Os
registros ainda não foram inseridos em `responsabilidade_atribuicoes`.

Durante a futura migração:

- `CODUSUARIO = 0` deverá ser tratado como ausência de responsável;
- campos nulos continuarão sem atribuição;
- usuários inativos serão preservados como histórico, mas não receberão novo
  vínculo ativo;
- códigos inexistentes serão enviados para a fila de órfãos;
- nenhum vínculo legado será apagado ou atualizado automaticamente.

## 2. Mapa candidato

| Origem | Módulo | Tipo candidato | Entidade | Identificador | Destino | Situação |
|---|---|---|---|---|---|---|
| `legislacaoxsituacao.CODUSUARIO` | `REQUISITOS_LEGAIS` | `REQUISITO_LEGAL` | `legislacaoxsituacao` | `CODSITUACAO` | usuário | candidato direto |
| `planodeacao.codusuario` | a confirmar | `REQUISITO_LEGAL` ou `CONTROLE` | `planodeacao` | `codplanoacao` | usuário | responsável direto pela ação; módulo ainda deve ser confirmado |
| `li_licencas.codusuario` | `LICENCAS` | `LICENCA` | `li_licencas` | `CODLICENCA` | usuário | responsável direto da licença |
| `li_licencas.codgrupo` | `LICENCAS` | tipo por finalidade | `li_licencas` | `CODLICENCA` | grupo | responsabilidade de grupo da licença |
| `li_licencas.codgrupo_validade` | `LICENCAS` | `LICENCA_VALIDADE` | `li_licencas` | `CODLICENCA` | grupo | responsabilidade pela validade |
| `li_licencas.codgrupo_reacao` | `LICENCAS` | `LICENCA_REACAO` | `li_licencas` | `CODLICENCA` | grupo | responsabilidade pela reação |
| `li_licencas.codgrupo_protocolo` | `LICENCAS` | `LICENCA_PROTOCOLO` | `li_licencas` | `CODLICENCA` | grupo | responsabilidade pelo protocolo |
| `li_licencas.codgrupo_acesso` | `LICENCAS` | `LICENCA_ACESSO` | `li_licencas` | `CODLICENCA` | grupo | responsabilidade pelo acesso |
| `mm_monitoramento.codusuario` | `MEDICAO_MONITORAMENTO` | `MONITORAMENTO` | `mm_monitoramento` | `CODMONITORAMENTO` | usuário | candidato direto |
| `fo_funcionarioxtiposxtreinamentos.codusuario` | `TREINAMENTO` | `TREINAMENTO` | `fo_funcionarioxtiposxtreinamentos` | `codigo` | usuário | revisar semântica do campo |
| `fo_fornecedorxusuario.codusuario` | `HOMOLOGACAO` | `HOMOLOGACAO` | `fo_fornecedorxusuario` | `CODFORNECEDORXUSUARIO` | usuário | candidato direto |
| `fo_fornecedorxcriterios.codusuario` | `HOMOLOGACAO` | `HOMOLOGACAO` | `fo_fornecedorxcriterios` | `codfornecedorxcriterios` | usuário | revisar duplicidade/escopo |
| `esg_indicador_plano_acao.CODUSUARIO_RESPONSAVEL` | `CONTROLE` | `CONTROLE` | `esg_indicador_plano_acao` | a confirmar | usuário | tabela sem registros |
| `mm_controleequipamento.CODUSUARIO_RESPONSAVEL` | `CONTROLE` | `CONTROLE` | `mm_controleequipamento` | a confirmar | usuário | tabela sem registros |

Os campos `codgrupo`, `codgrupo_validade`, `codgrupo_reacao`,
`codgrupo_protocolo` e `codgrupo_acesso` de `li_licencas` serão convertidos em
atribuições separadas, depois que os tipos de responsabilidade correspondentes
forem cadastrados. Todos apontam para a tabela `grupos` e representam
finalidades diferentes dentro da mesma licença.

## 3. Quantidades encontradas

| Origem | Registros | Com código informado | `0` | Nulos | Usuário inexistente | Usuário inativo |
|---|---:|---:|---:|---:|---:|---:|
| `legislacaoxsituacao.CODUSUARIO` | 925 | 923 | 0 | 2 | 0 | 535 |
| `planodeacao.codusuario` | 1 | 1 | não apurado | não apurado | 0 | 0 |
| `li_licencas.codusuario` | 46 | 45 | 1 | 1 | 0 | 9 |
| `mm_monitoramento.codusuario` | 8 | 8 | 3 | 0 | 0 | 0 |
| `fo_fornecedorxusuario.codusuario` | 1.123 | 1.123 | não apurado | não apurado | 54 | 273 |
| `fo_fornecedorxcriterios.codusuario` | 8.396 | 5.061 | 215 | 3.335 | 6 | 145 |
| `fo_funcionarioxtiposxtreinamentos.codusuario` | 955 | 955 | 953 | 0 | 0 | 0 |
| `esg_indicador_plano_acao.CODUSUARIO_RESPONSAVEL` | 0 | 0 | — | — | — | — |
| `mm_controleequipamento.CODUSUARIO_RESPONSAVEL` | 0 | 0 | — | — | — | — |

## 4. Classificação para a futura migração

### Pode ser migrado após aprovação

- `li_licencas.codusuario` diferente de zero e associado a usuário existente;
- `mm_monitoramento.codusuario` diferente de zero e associado a usuário
  existente;
- `legislacaoxsituacao.CODUSUARIO`, mantendo os inativos como vínculos
  históricos ou pendências, conforme decisão funcional;
- `fo_fornecedorxusuario.codusuario`, após tratar códigos inexistentes e
  usuários inativos.

### Precisa de revisão antes da migração

- `fo_fornecedorxcriterios.codusuario`, pois há muitos nulos, zeros, códigos
  inexistentes e usuários inativos;
- `fo_funcionarioxtiposxtreinamentos.codusuario`, pois 953 registros usam
  `0`, indicando ausência de responsável;
- a finalidade funcional exata dos vínculos de fornecedor e documentação na
  Homologação;
- qualquer vínculo para usuário inativo que, além do histórico, precise de
  delegação para um usuário ativo.

### Ainda sem origem de dados

- `INFRAESTRUTURA` não possui um campo legado de responsável identificado;
- `CONTROLE` não possui registros nas duas tabelas candidatas analisadas;
- grupos de responsabilidade ainda não foram migrados para
  `responsabilidade_grupoxusuario`.

## 5. Regra proposta para a carga futura

Cada vínculo aprovado será convertido para:

- `ORIGEM = 'DIRETA'`;
- módulo correspondente;
- tipo-base correspondente;
- entidade e chave primária da origem;
- `TIPO_DESTINO = 'USUARIO'` ou `TIPO_DESTINO = 'GRUPO'`, conforme a origem;
- `CODUSUARIO` ou `CODGRUPO` preservado;
- `VALIDO_DE` definido conforme a regra do módulo;
- `ATIVO = 1` somente para usuário ativo ou grupo ativo e vínculo válido;
- vínculos históricos de usuários inativos serão preservados com `ATIVO = 0`
  ou em pendência, sem apagar o registro original;
- observação contendo a tabela e o campo de origem.

Vínculos históricos de usuários inativos não devem ser descartados. Eles
deverão ser inseridos como inativos ou registrados em uma estrutura de
pendências, conforme a decisão sobre histórico e delegação.

## 6. Próximo passo

Antes da carga, aprovar funcionalmente:

1. a relação entre `planodeacao` e o módulo de Requisitos Legais ou Controle;
2. o tipo e o módulo dos vínculos de Homologação;
3. a origem dos campos de usuários de Infraestrutura e Controle;
4. a estratégia de delegação dos responsáveis inativos, mantendo o histórico.

Somente depois dessas aprovações deverá ser criado o script de migração dos
vínculos para `responsabilidade_atribuicoes`.

## 7. Resultado da carga aprovada

A carga foi executada no `gmodelo_dbp` pelos scripts 098, 099 e 100. Foram
criados 10.903 vínculos em `responsabilidade_atribuicoes`, incluindo 1.426
vínculos históricos com `ATIVO = 0` para usuários inativos.

Não foram migrados o plano de ação, Infraestrutura, Controle, códigos
inexistentes, zeros ou vínculos sem responsável.
