# Navegação Friendly da Home

Atualização: 26/08/2026.

Os cards da `home.php` são pontos de entrada para transações independentes.
Ao clicar em um card, a transação correspondente deve ser aberta pela API de
abas da `main.php`, mantendo a aba Home e as demais abas já abertas.

Os destinos devem receber o contexto necessário pela URL, sem compartilhar
formulários ou variáveis com a Home. Pendências e licenças do usuário recebem
filtros com seu código; a agenda consulta o usuário pela sessão; e os alertas
abrem o painel próprio. Cada destino continua validando sessão, tenant,
permissão e filtros no servidor.
