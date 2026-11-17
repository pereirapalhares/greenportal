# Diretriz oficial — isolamento de transações

Versão: 1.0  
Aplicação: GreenSolutions 4.0.1  
Escopo: páginas PHP, APIs, procedures, views, permissões, testes e documentação.

## Regra principal

Cada transação funcional deve possuir um arquivo PHP próprio, nomeado pelo
identificador da transação:

```text
/principal/<nome_da_transacao>.php
```

O arquivo é o ponto de entrada exclusivo da tela, grid, formulário, filtros e
ações daquela transação. Ele não deve executar ações de outra transação nem
receber livremente o nome de uma tabela, view, procedure ou arquivo vindo do
navegador.

## Limite de responsabilidade

Uma transação pode utilizar serviços compartilhados de infraestrutura, como
sessão, tenant, autorização, auditoria, i18n, conexão e componentes visuais.
Esses serviços não podem misturar regras de negócio de transações diferentes.

Regras de negócio específicas devem permanecer na transação, em uma classe de
serviço explicitamente nomeada para ela ou em uma procedure associada ao seu
domínio.

## Contrato por camada

### Arquivo PHP da transação

Cada arquivo deve:

1. Validar sessão e `TenantContext`.
2. Validar o código de permissão da própria transação.
3. Aceitar somente os parâmetros documentados para a própria transação.
4. Delegar CRUD às views e procedures autorizadas.
5. Registrar operações relevantes na auditoria.
6. Retornar HTML, JSON ou redirecionamento conforme seu contrato.
7. Não alterar registros pertencentes a outra transação sem uma associação
   explícita e auditada.

### APIs

Uma API deve possuir nome correspondente à transação ou à ação específica:

```text
/principal/api/<nome_da_transacao>.php
/principal/api/<nome_da_transacao>_<acao>.php
```

Exemplos de ações permitidas: `salvar`, `aprovar`, `rejeitar`, `exportar` ou
`recarregar`. A API deve validar método HTTP, CSRF quando aplicável, tenant,
usuário, permissão, registro e payload. Não é permitido um endpoint genérico
que execute qualquer procedure ou tabela recebida por parâmetro.

### Banco de dados

Views e procedures devem possuir escopo claro de domínio e transação. O nome
deve indicar a entidade ou a operação relacionada. Procedures universais de
infraestrutura, como CRUD controlado, somente podem ser chamadas depois que a
transação validar tabela, campos, chave e permissão por uma lista permitida.

Toda alteração deve ocorrer em transação do banco, com rollback em erro,
idempotência quando houver retry e registro em `logs_auditoria`.

### Permissões

Cada transação deve possuir um código estável de menu/permissão. A cadeia
oficial é:

```text
usuário -> perfil/grupo -> menu/transação -> ações autorizadas
```

As ações devem ser separadas em visualizar, incluir, editar, excluir,
exportar e gerar PDF, quando aplicável. Uma transação não pode reutilizar a
permissão de outra apenas para liberar uma ação semelhante.

### Testes

Cada transação deve possuir testes identificados pelo mesmo código ou nome do
arquivo, cobrindo:

- carregamento e autenticação;
- permissão de visualização e acesso direto;
- cada ação autorizada e não autorizada;
- isolamento por tenant, empresa, unidade e registro;
- validação de payload e CSRF;
- commit, rollback e reexecução segura;
- auditoria antes/depois quando houver alteração;
- labels, acentuação e responsividade quando aplicável.

### Documentação

Cada transação deve ser documentada com nome do arquivo, código do menu,
permissões, tabelas/views/procedures, APIs, parâmetros, dependências,
isolamento, auditoria e roteiro de testes. A documentação deve ser atualizada
na mesma alteração funcional.

## Prevenção de corrupção de dados

É proibido:

- misturar duas transações em um único arquivo PHP sem justificativa formal;
- usar uma tabela ou procedure de outra transação sem contrato explícito;
- aceitar tabela, view, procedure ou banco livremente pelo cliente;
- confiar somente em bloqueios do JavaScript;
- executar SQL CRUD direto no navegador ou concatenar nomes sem whitelist;
- atualizar dados sem validar tenant, unidade, registro e permissão;
- reaproveitar um endpoint para mutações de domínios diferentes.

## Exceções

Exceções somente podem ocorrer para componentes de infraestrutura ou uma
operação transversal formalmente documentada. A exceção deve registrar motivo,
transações envolvidas, contrato, permissões, testes e plano de reversão.

## Aplicação gradual

Novas transações devem cumprir esta diretriz desde a criação. Transações
existentes devem ser migradas por prioridade: segurança e dados, APIs de
mutação, procedures, permissões, testes e documentação. A migração não deve
alterar chaves legadas nem quebrar URLs compatíveis.
