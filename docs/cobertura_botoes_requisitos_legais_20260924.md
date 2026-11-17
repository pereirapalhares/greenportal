# Cobertura dos botões — `modulolegal_requisitoslegais.php`

## Botões já validados

- `PODER`: edição, persistência e restauração do valor original.
- `Licenças`: popup e CRUD completo.
- `Controles`: popup e CRUD completo.
- `Medição/Monitoramento`: popup e CRUD completo.
- `Checklist`: checkbox de associação, persistência da marcação e remoção ao desmarcar.
- `Potenciais IA`: solicitação, processamento completo, alertas e tela de resultado.
- `Exportar Excel`: solicitação, job, geração e validação do XLSX.
- `Colunas`: configuração, ordem e visibilidade.
- Grade, filtros, paginação e ordenação.
- Permissões dos sete usuários autorizados.

## Botões ainda pendentes de validação funcional/visual completa

- `Treinamentos`: **Aprovado nesta rodada**; popup abriu com `CODLEGISLACAO` e `CODUNIDADE` corretos.
- `Infraestrutura`: **Aprovado nesta rodada**; popup abriu com `CODLEGISLACAO` e `CODUNIDADE` corretos.
- `Homologação de Fornecedores`: **Aprovado nesta rodada**; popup abriu com o contexto correto.
- `Padrão`: **Aprovado nesta rodada**; modal abriu com conteúdo não vazio.
- `Texto da Norma/PDF`: o endpoint já respondeu HTTP 200 com PDF válido; falta corrigir ou confirmar a abertura visual do modal e o download pelo botão.
- `Ajuda`: **Aprovado nesta rodada**; abriu `setup_ajuda.php?transacao=modulolegal_requisitoslegais`.
- `Recarregar`: **Aprovado nesta rodada**; manteve um iframe, o contexto e a grade carregada.

## Observação

O PDF permanece pendente somente na abertura visual do modal: o endpoint e o arquivo PDF são válidos, mas o clique automatizado não criou o modal esperado. Não há aprovação formal de go-live neste registro.
