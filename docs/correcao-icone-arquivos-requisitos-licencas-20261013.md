# Correção do ícone de arquivos em requisitos legais / licenças

Na view `vw_modulolegal_requisitos_licencas_grid`, o campo `ARQUIVOS` é legado e retorna valores como `licencas;760`. Esse valor não deve ser exibido diretamente ao usuário.

Correção aplicada:

- a coluna `ARQUIVOS` foi marcada explicitamente como controle GED do tipo `licencas`;
- o JavaScript de arquivos foi atualizado para repetir a conversão após a montagem tardia do grid e após paginação;
- a versão do asset foi incrementada para evitar cache antigo (`v=11`);
- o botão mantém o identificador da licença e abre `gestao_arquivos.php` para upload, consulta e exclusão;
- o contador continua sendo atualizado pelo endpoint de contagem do GED.

Validação: sintaxe PHP e JavaScript aprovadas; a view foi conferida e confirmou o formato legado `licencas;ID` que causava o problema.
