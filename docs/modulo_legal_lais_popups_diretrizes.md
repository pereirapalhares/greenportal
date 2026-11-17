# LAIS — popup de Controles e Documentos

O popup `principal/moduloleglais_controlesdocumentos.php` usa a view `vw_lais_controles_documentos_grid`.

A view reúne dois relacionamentos: documentos associados a aspectos sociais e documentos associados a impactos sociais. Por isso, os filtros `CODASPECTOSOCIAL` e `CODIMPACTOSOCIAL` devem ser aplicados em modo OR, enquanto `codunidade` permanece filtro obrigatório adicional.

O popup normaliza as chaves recebidas da linha do LAIS, descarta `CODLAIS` e `CODPARTE` quando não pertencem à view e converte `CODUNIDADE` para `codunidade`, que é o nome retornado pela view.

Essa normalização evita que o grid fique vazio quando a linha selecionada possuir simultaneamente aspecto e impacto social.
