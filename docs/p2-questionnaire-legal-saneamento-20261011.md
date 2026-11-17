# Saneamento e migração das perguntas legais legadas

## Escopo

Foi analisada a origem `glegislacao_dbp.perguntas` e seus vínculos em
`glegislacao_dbp.legislacaoxpergunta`. A migração foi direcionada para a versão
1, em rascunho, do questionário universal `LEGAL_REQUIREMENTS` em
`guniversal_dbp`.

O legado não foi apagado nem alterado.

## Critérios automáticos

- `MIGRAR`: texto não vazio, com tamanho mínimo, vínculo com legislação e sem
  repetição do texto normalizado.
- `DUPLICADA`: texto normalizado igual ao de uma pergunta canônica já migrada;
  mantém a identificação da pergunta original e aponta para o destino canônico.
- `REVISAR_TEXTO_CURTO`: texto menor que 10 caracteres.
- `REVISAR_SEM_VINCULO`: pergunta existente sem registro em
  `legislacaoxpergunta`.
- `REVISAR_VAZIA` e `REVISAR_PLACEHOLDER`: reservados para textos vazios ou
  marcadores/testes encontrados em futuras reexecuções.

O texto original é mantido no saneamento. A normalização serve apenas para
detectar duplicatas; ela não substitui o texto apresentado ao usuário.

## Resultado da execução 203

| Item | Quantidade |
|---|---:|
| Perguntas na origem | 9.797 |
| Vínculos na origem | 30.608 |
| Perguntas migradas | 9.621 |
| Duplicatas | 13 |
| Textos muito curtos | 2 |
| Sem vínculo | 161 |
| Vínculos com destino | 30.528 |
| Vínculos sem destino | 80 |

As perguntas migradas receberam o código estável
`LEGADO_PERGUNTA_<CODPERGUNTA>`. O identificador legado continua registrado em
`amb_questionario_pergunta_saneamento`; o `codpergunta` do catálogo universal é
apenas a chave técnica da nova tabela.

## Tabelas de rastreabilidade

- `amb_questionario_pergunta_saneamento`: análise, classificação, motivo,
  texto original, hash e destino.
- `amb_questionario_pergunta_legado_vinculo`: vínculo entre
  `CODPERGUNTA`/`CODLEGISLACAO` do legado e a pergunta destino, quando existir.

## Homologação necessária

O questionário permanece como `RASCUNHO` e não deve ser publicado antes da
revisão dos itens classificados para revisão, validação dos textos migrados e
definição dos tipos de resposta. A migração inicial usa `TEXTO` e deixa as
perguntas não obrigatórias para evitar impor comportamento sem validação
funcional.

O script `scripts/aplicar-migracao-203-perguntas-legais.php` é idempotente:
reexecuções atualizam a análise e os vínculos sem criar perguntas ou vínculos
duplicados.
