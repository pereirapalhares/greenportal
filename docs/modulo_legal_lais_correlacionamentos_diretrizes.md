# LAIS — grids dos três correlacionamentos

Os três grids contextuais do LAIS foram comparados com `E:/wamp/www/cadastros/lais_c.php` e com:

- `E:/wamp/www/configuracoes/lais_vlaisxlegislacao.php`;
- `E:/wamp/www/configuracoes/lais_vlaisxdocumentos.php`;
- `E:/wamp/www/configuracoes/lais_vlaisxobjetivosemetas.php`.

## Requisitos Legais Aplicáveis

O grid visualiza `Legislação` e `Textos da lei`. O relacionamento pode vir de aspecto social ou impacto social e deve ser filtrado pela unidade. Os campos de relacionamento e a chave técnica ficam ocultos.

## Controles Existentes

O grid visualiza `Código`, `Título` e `Arquivo`. Documentos associados a aspectos e documentos associados a impactos são unidos na mesma view. Aspecto ou impacto usa contexto OR; a unidade é filtro adicional.

## Objetivos e Metas

O grid visualiza, na ordem legada: Código do Objetivo, Item Atendido da Política, Objetivo, Meta, Indicador, Líder e Estratégico/Tático. O filtro contextual é por aspecto social e unidade.

## Chaves e implementação

Os popups recebem da linha principal as chaves `CODLAIS`, `CODUNIDADE`, `CODASPECTOSOCIAL`, `CODIMPACTOSOCIAL` e `CODPARTE`. Cada popup normaliza somente as chaves pertencentes à sua view antes de consultar o banco.

As telas utilizam `ManutencaoLegalCadastroGrid`, com `legacy_order` e `legacy_fixed`, mantendo ordem e visibilidade do legado. A correção das views está em `database/080_corrigir_grids_lais_legado.sql`.
