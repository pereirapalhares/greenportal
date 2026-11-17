# Decisões funcionais — Responsabilidades e Licenças

**Data:** 20/08/2026  
**Tenant de referência:** `gmodelo_dbp`  
**Status:** aprovado para preparação do preview de migração

## 1. Licença e responsabilidade são conceitos diferentes

`li_licencas` continua sendo a tabela operacional das licenças. Ela armazena
os dados da licença, como descrição, validade, status e unidade.

`responsabilidade_tipos` e `responsabilidade_atribuicoes` não substituem
`li_licencas`. Eles registram quem responde por cada finalidade relacionada a
uma licença.

Uma licença pode possuir simultaneamente:

- um usuário responsável pela licença;
- um grupo responsável pela validade;
- um grupo responsável pela reação;
- um grupo responsável pelo protocolo;
- um grupo responsável pelo acesso.

Não haverá duplicação do registro da licença.

## 2. Exemplo funcional

Para a licença `CODLICENCA = 1001`, os campos legados podem indicar:

| Campo legado | Responsável | Finalidade |
|---|---|---|
| `codusuario` | usuário 25 | responsável principal |
| `codgrupo` | grupo 3 | responsabilidade geral |
| `codgrupo_validade` | grupo 8 | acompanhar a validade |
| `codgrupo_reacao` | grupo 12 | tomar providências |
| `codgrupo_protocolo` | grupo 15 | cuidar do protocolo |
| `codgrupo_acesso` | grupo 20 | acessar ou acompanhar |

Na nova camada, a licença 1001 terá vínculos separados para cada finalidade,
todos apontando para `li_licencas / 1001`.

## 3. Tipos de responsabilidade de licença

Os tipos representam papéis, e não tipos cadastrais de licença:

- `LICENCA_RESPONSAVEL`;
- `LICENCA_VALIDADE`;
- `LICENCA_REACAO`;
- `LICENCA_PROTOCOLO`;
- `LICENCA_ACESSO`.

## 4. Comportamento da interface

O grid de licenças continuará exibindo os dados operacionais de
`li_licencas`. Na visualização ou edição de uma licença, haverá uma seção ou
aba `Responsabilidades`.

Essa seção deverá exibir:

| Finalidade | Responsável | Origem |
|---|---|---|
| Licença | João da Silva | Usuário |
| Validade | Grupo Compliance | Grupo |
| Reação | Grupo Operacional | Grupo |
| Protocolo | Grupo Documentação | Grupo |
| Acesso | Grupo Consulta | Grupo |

Ao selecionar um grupo, o usuário poderá consultar seus membros ativos.

As consultas deverão permitir:

- consultar todos os responsáveis de uma licença;
- filtrar por finalidade;
- filtrar por usuário ou grupo;
- consultar somente as responsabilidades do usuário logado;
- consultar responsabilidades pendentes;
- consultar responsabilidades de usuários inativos;
- abrir o histórico e as delegações.

A tela `Minhas licenças` deverá considerar responsabilidades diretas,
responsabilidades herdadas de grupos e delegações vigentes.

## 5. Usuários inativos

Responsabilidades de usuários inativos continuarão preservadas como histórico.
Elas não poderão ser criadas como novos vínculos ativos. A interface deverá
exibir uma pendência indicando que o vínculo precisa ser regularizado ou
delegado.

## 6. Regras confirmadas para os módulos

- `planodeacao.codusuario` representa exclusivamente o usuário responsável
  pela ação do plano de ação. A classificação definitiva do módulo será
  confirmada no preview funcional.
- Homologação usará usuários responsáveis pelo fornecedor ou pela documentação
  do fornecedor.
- Infraestrutura e Controle usarão usuários da tabela `usuarios`, por meio
  dos campos de responsável existentes nas tabelas de gestão.
- Grupos da licença serão tratados como destinos `GRUPO`, sem conversão para
  usuários individuais na carga inicial.

## 7. Próximo passo

Criar e revisar um preview de migração contendo origem, módulo, tipo,
entidade, registro, destino e situação do vínculo. O preview não deverá criar
linhas em `responsabilidade_atribuicoes`.

## 8. Preview executado

O preview foi criado pela view `vw_responsabilidade_migracao_preview` no
`gmodelo_dbp`, pelo script `database/097_preview_migracao_responsabilidades.sql`.

Resultado resumido:

- 10.977 candidatos identificados;
- 0 atribuições criadas;
- 388 requisitos legais com usuário ativo;
- 535 requisitos legais com usuário inativo, preservados como histórico;
- 35 licenças com usuário responsável ativo;
- 9 licenças com usuário responsável inativo;
- 46 responsabilidades de acesso por grupo;
- 5 monitoramentos com usuário ativo;
- 2 treinamentos com usuário ativo;
- vínculos de homologação separados entre fornecedor e documentação;
- 1 plano de ação aguardando confirmação do módulo;
- nenhum vínculo de Infraestrutura ou Controle encontrado nas tabelas
  analisadas.

O preview é somente de leitura e será a base para revisar os candidatos antes
da criação do script de migração efetiva.

## 9. Carga efetiva realizada

Após a revisão do preview, a carga foi executada pelos scripts:

- `database/098_tipos_responsabilidade_homologacao.sql`;
- `database/099_migrar_responsabilidades_aprovadas.sql`;
- `database/100_corrigir_constraint_responsabilidades.sql`.

Resultado no `gmodelo_dbp`:

- 10.903 atribuições criadas;
- 1.426 atribuições mantidas como históricas inativas;
- 46 atribuições de grupo para acesso a licenças;
- 35 atribuições ativas de usuário para licenças;
- 388 requisitos legais com usuário ativo;
- 535 requisitos legais históricos com usuário inativo;
- 4.306 responsabilidades ativas de fornecedor na Homologação;
- 4.695 responsabilidades ativas de documentação na Homologação;
- 5 responsabilidades ativas de monitoramento;
- 2 responsabilidades ativas de treinamento.

Foram deliberadamente adiados:

- o plano de ação, enquanto o módulo funcional não for confirmado;
- Infraestrutura e Controle, pois não havia registros nas tabelas analisadas;
- códigos legados de usuários ou grupos inexistentes;
- registros com `CODUSUARIO = 0` ou sem responsável.

O backup utilizado antes da carga foi
`backups/gmodelo_dbp_pre_responsabilidades_20260820.sql`.

## 10. Validação da resolução de responsáveis

As views foram testadas sobre os vínculos migrados:

- 9.431 responsáveis efetivos foram resolvidos;
- 1.426 vínculos de usuários inativos aparecem como históricos/órfãos;
- 46 vínculos de grupo aparecem como `GRUPO_SEM_MEMBRO_ATIVO`;
- nenhum vínculo de grupo foi expandido para responsável efetivo.

Os 46 vínculos de grupo apontam para o grupo legado `CODGRUPO = 99999`,
chamado `TODOS`, que está ativo, mas não possui membros na tabela `usuarios`
nem em `responsabilidade_grupoxusuario`.

Antes de corrigir a resolução, deve ser definida a semântica do grupo
`TODOS`: se ele representa todos os usuários ativos, deverá ser tratado como
grupo especial na view; caso contrário, deverá receber membros explícitos na
associação de grupos.

## 11. Regra do grupo `TODOS` aplicada

Foi aprovada e aplicada a regra de que `CODGRUPO = 99999 / TODOS` representa
todos os usuários ativos. A regra é resolvida na view e não materializa 1.242
associações individuais em `responsabilidade_grupoxusuario`.

Após a correção:

- 1.242 usuários ativos são expandidos pelo grupo especial;
- os 46 vínculos de acesso das licenças passam a gerar 57.132 responsáveis
  efetivos de grupo;
- não há mais pendências `GRUPO_SEM_MEMBRO_ATIVO`;
- permanecem 1.426 pendências de usuários inativos, preservadas como
  histórico.
