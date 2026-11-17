# Validação de prontidão — Medição e Monitoramento

**Data:** 21/08/2026  
**Tenant:** `gmodelo_dbp`  
**Status:** implementação concluída e SQL aplicado; ainda não homologado visualmente

## 1. Escopo identificado

O módulo está representado no modelo por `MEDICAO_MONITORAMENTO`, com o tipo
`MONITORAMENTO`. A origem legada aprovada para análise é:

| Origem | Registro | Destino | Regra |
|---|---|---|---|
| `mm_monitoramento.codusuario` | `CODMONITORAMENTO` | usuário | responsável pelo monitoramento |

O inventário registrou 8 registros, sendo 8 com código informado, 3 com valor
zero e nenhum usuário inexistente ou inativo entre os códigos válidos.

## 2. Resultado da análise de prontidão

| Verificação | Resultado |
|---|---|
| módulo cadastrado em `responsabilidade_modulos` | confirmado no script-base |
| tipo `MONITORAMENTO` cadastrado | confirmado no catálogo |
| entidade e chave de origem definidas | confirmado |
| regra de usuário responsável definida | confirmado |
| view específica de consulta | implementada em `database/111_responsabilidades_monitoramento.sql` |
| endpoint de consulta | `principal/monitoramento_responsabilidades.php` |
| endpoint de manutenção | `principal/monitoramento_responsabilidades_edicao.php` |
| interface visual | ação integrada ao grid existente |
| roteiro visual CRUD | próximo passo: PER-006 |

## 3. Próximas ações técnicas

1. ~~Identificar a tela atual de `mm_monitoramento` e sua transação de segurança.~~ concluído.
2. ~~Definir a consulta contextual de responsabilidades por `CODMONITORAMENTO`.~~ concluído.
3. Reutilizar o contrato já homologado em Licenças e Requisitos Legais:
   consulta, manutenção, permissões CRUD, histórico e mensagens.
4. ~~Criar a view de responsabilidades do módulo.~~ concluído.
5. ~~Criar a manutenção com validação server-side e bloqueio client-side.~~ concluído.
6. ~~Executar validação técnica PHP/JavaScript.~~ PHP concluído; homologação visual pendente.
7. Criar o roteiro visual PER-006 para o módulo.
8. Homologar inclusão, edição, exclusão lógica e permissões.

## 4. Critério para iniciar o teste visual

O script SQL 111 foi aplicado no `gmodelo_dbp` e a view foi confirmada. O teste
visual pode ser iniciado com uma linha de teste controlada e configuração
temporária das permissões CRUD do perfil de teste na transação contextual
`REQUISITOS_LEGAIS`.
