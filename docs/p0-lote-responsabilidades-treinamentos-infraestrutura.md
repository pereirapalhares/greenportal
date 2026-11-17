# P0 - Lote de responsabilidades, treinamentos e infraestrutura

Este lote aplica a diretriz de transacoes por arquivo e centraliza a
autenticacao, o tenant, o usuario e a autorizacao nas telas de edicao de:

- responsabilidades de requisitos, licencas e monitoramentos;
- treinamentos e fornecedores;
- infraestrutura e responsabilidades do Modulo Legal.

Tambem foram abrangidas APIs de relacionamentos, responsabilidades, contagens
GED, opcoes de listas, IA, OCR e gestao de arquivos. As APIs passam a obter a
conexao pelo `TenantContext` antes de consultar ou alterar dados.

As permissoes especificas continuam sendo obtidas pelo codigo da transacao,
preservando os controles de visualizar, incluir, editar e excluir ja usados
no legado. O nome do banco continua vindo exclusivamente da sessao validada;
nenhum endpoint aceita banco livre no request.

## Validacao realizada

- 238 arquivos PHP inventariados;
- 34 transacoes classificadas como migradas;
- 204 transacoes ainda pendentes;
- lint PHP sem erros nos arquivos alterados.

O P0 permanece aberto ate a homologacao funcional de cada transacao pendente.
