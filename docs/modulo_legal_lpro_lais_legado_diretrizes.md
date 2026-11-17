# Módulo Legal — LPRO e LAIS: migração dos grids legados

Esta documentação registra as correções aplicadas aos grids principais de LPRO e LAIS, mantendo `docs` como diretório oficial das diretrizes.

## Fontes do legado

### LPRO

- `E:/wamp/www/newlegislacao/configuracoes/LPRO.php`
- `E:/wamp/www/configuracoes/vlpro.php`
- Transação atual: `principal/modulolegal_lpro.php`
- View atual: `gmodelo_dbp.vw_lpro_grid`
- Tabela atual: `gmodelo_dbp.lpro`
- Chave: `CODLPRO`

### LAIS

- `E:/wamp/www/cadastros/lais_c.php`
- `E:/wamp/www/configuracoes/vlais.php`
- Transação atual: `principal/modulolegal_lais.php`
- View atual: `gmodelo_dbp.vw_lais_grid`
- Tabela atual: `gmodelo_dbp.lais`
- Chave: `CODLAIS`

## Regra de migração dos grids

Os atributos do legado devem ser preservados na transação moderna: ordem, rótulo, busca, largura, ordenação e tipo de edição. O componente comum é `ManutencaoLegalCadastroGrid`, usando `legacy_order` e `legacy_fixed` para garantir que preferências antigas do usuário não alterem a sequência nem ocultem as colunas oficiais do legado.

Campos técnicos, como `linha`, `CODLPRO` e `CODLAIS`, permanecem ocultos. Eles continuam disponíveis para identificação, CRUD e contexto dos popups.

## LPRO

A ordem oficial é:

`linha, CODUNIDADE, CODAREA, CODATIVIDADE, detalhamento, CODPERIGO, TIPO_DE_ATIVIDADE, SITUACAO, ABRANGENCIA, NATUREZA_DO_PERIGO, CODRISCO, SEVERIDADE, OCORRENCIA, DETECCAO, TOTAL, TOLERANCIA, ELIMINAR_RISCO, ANALISAR_SUBST_RISCO, DISPOSICAO_ATENUANTE, APLICAVEL_MEDIDAS_ENG, POSSUI_MEDIDAS_ENG, APLICAVEL_MEDIDAS_ADM, POSSUI_MEDIDAS_ADM, APLICAVEL_ADOCAO_EPIS, ADOTAR_EPIS, NECESSIDADE_NOVOS_CONTROLES, FILTROB, FILTROC, FILTROD`

O campo `detalhamento` deve usar exatamente a chave retornada por `vw_lpro_grid`. O legado usa a grafia conceitual `DETALHAMENTO`, mas a view retorna a chave em minúsculas; utilizar a chave incorreta faz a célula aparecer vazia.

Os relacionamentos usam combobox ordenado pela descrição: `CODUNIDADE` → `DSCUNIDADE`, `CODAREA` → `DSCAREA`, `CODATIVIDADE` → `DSCATIVIDADE`, `CODPERIGO` → `DSCPERIGO` e `CODRISCO` → `DSCRISCO`.

`TOTAL` e `TOLERANCIA` são somente leitura. As opções de situação, abrangência, natureza, tipo de atividade e respostas Sim/Não mantêm os códigos gravados e exibem os rótulos humanos.

Os botões contextuais do LPRO devem enviar somente a chave composta legada:

`CODPERIGO, CODRISCO, CODATIVIDADE, CODUNIDADE`

`CODLPRO` não substitui essa chave contextual.

## LAIS

A ordem visual correspondente ao legado é:

`Unidade, Partes Interessadas, Modo de Ocorrência, Aspecto Social, Situação, Tempo, Influência, Classificação, Impacto Social, Escala, Severidade, Duração, Resultado, Disposição ou Atenuante, Aspectos Significativos, Atendimento Legislação, Visão Partes Interessadas`

Os códigos técnicos da unidade, parte interessada, aspecto social e impacto social são usados internamente para o CRUD, mas a célula exibe a descrição por meio de combobox: `CODUNIDADE` → `DSCUNIDADE`, `CODPARTE` → `DSCPARTE`, `CODASPECTOSOCIAL` → `DSCASPECTOSOCIAL` e `CODIMPACTOSOCIAL` → `DSCIMPACTOSOCIAL`.

## Carregamento inicial dos comboboxes

Nos grids editáveis de LAIA, LPRO e LAIS, todos os comboboxes de relacionamentos devem ser carregados durante a montagem inicial do grid. O primeiro clique do usuário não pode disparar uma busca assíncrona, exibir “Carregando...” ou reconstruir o grid.

O código técnico continua sendo o valor enviado ao CRUD, enquanto a descrição é o texto exibido ao usuário. Para isso, os campos relacionais devem declarar `lazy_foreign => false`, com `foreign.value` apontando para o código e `foreign.label`/`description` apontando para a descrição.

Essa regra se aplica, respectivamente, a:

- LAIA: unidade, área, atividade, aspecto e impacto;
- LPRO: unidade, área, atividade, perigo e risco;
- LAIS: unidade, parte interessada, aspecto social e impacto social.

O carregamento sob demanda somente pode ser usado quando houver justificativa de volume documentada e aceite visual/funcional, sem alterar a experiência de edição do usuário.

As opções do legado foram preservadas: Situação Normal/Anormal/Acidental; Tempo Atual/Passado/Futuro; Influência Direto/Indireto; Classificação Adverso/Benéfico; Escala, Severidade e Duração de 1 a 3; Filtros C e D vazios ou `X`.

`RESULTADO` e `FILTROB` são somente leitura, calculados pela view conforme a regra legada. `MODO_DA_OCORRENCIA` e `DISPOSICAOATENUANTE` são campos de texto longo.

Os popups do LAIS recebem:

`CODLAIS, CODUNIDADE, CODASPECTOSOCIAL, CODIMPACTOSOCIAL, CODPARTE`

## Validação

Após as alterações, os arquivos PHP foram validados com `php -l`. A tela deve ser recarregada com `Ctrl + F5` quando houver uma versão anterior do JavaScript no cache do navegador.

## Regra para futuras alterações

Qualquer alteração de coluna em LPRO ou LAIS deve primeiro ser comparada com os arrays `buscas`, `labels`, `campos`, `tamanho`, `sort` e `types` do legado. A implementação moderna pode usar nomes técnicos internos para permitir CRUD seguro, mas não pode mudar a ordem ou o significado das colunas visíveis.
