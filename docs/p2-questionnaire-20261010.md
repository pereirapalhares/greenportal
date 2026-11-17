# P2 — Questionários universais

## Escopo implementado

O primeiro incremento do P2 separa o catálogo e a publicação universal das execuções por empresa:

- `guniversal_dbp` guarda questionários, versões, blocos, perguntas, opções, regras e publicações.
- Cada tenant guarda execuções, respostas, resultados e evidências em seu próprio banco.
- Uma versão publicada não é editada; a nova versão é publicada e a anterior é arquivada.
- A execução possui chave de idempotência e referências estáveis ao catálogo universal.
- O serviço aceita integração opcional com `WorkflowService`.

## Inventário legado

Foi pesquisado o código, os scripts e os bancos ativos. Não foram encontradas tabelas ativas `amb_questionario_*` no legado disponível; as ocorrências encontradas estão em referências/backups. Portanto, esta etapa cria a estrutura compatível, sem inventar dados de migração. Quando o legado real for disponibilizado, a carga deverá preservar códigos, versões e respostas, registrando a origem.

## Arquivos

- `database/193_questionnaire_universal.sql`
- `database/193_questionnaire_tenant.sql`
- `classes/QuestionnaireService.php`
- `scripts/aplicar-migracao-193-questionnaire.php`
- `scripts/testar-questionnaire-service-p2.php`

## Validação

Executar com PHP CLI:

```powershell
E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-193-questionnaire.php
E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-193-questionnaire.php
E:\wamp\bin\php\php8.4.15\php.exe scripts\testar-questionnaire-service-p2.php
```

A segunda aplicação deve manter o mesmo estado. O teste funcional verifica publicação, carregamento da pergunta, idempotência de abertura, gravação de resposta, cálculo de resultado e bloqueio de reenvio. Os dados criados pelo teste são removidos ao final.

## Próxima etapa P2

Foi criada a transação administrativa `questionarios.php`, com API própria, permissão `QUESTIONARIOS` para o perfil `ADMINISTRADOR`, editor de rascunho, inclusão de perguntas/opções e publicação imutável. O menu é universal e as autorizações continuam nos três tenants.

Teste administrativo:

```powershell
E:\wamp\bin\php\php8.4.15\php.exe scripts\testar-questionarios-admin-p2.php
```

Próxima etapa: criar as transações de execução por módulo e integrar questionários publicados a Fornecedores, Legal, SGA, Qualidade e Workflow, sem duplicar o catálogo universal.

Foi iniciado esse incremento com `questionario_execucao.php` e `api/questionario_execucao.php`. A homologação de fornecedores abre a execução pelo fornecedor selecionado, armazena as respostas no tenant e inicia o workflow `SUPPLIER_QUESTIONNAIRE`, mantendo a aprovação humana separada do catálogo universal.

```powershell
E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-195-questionnaire-workflow.php
E:\wamp\bin\php\php8.4.15\php.exe scripts\testar-questionario-fornecedor-p2.php
```
