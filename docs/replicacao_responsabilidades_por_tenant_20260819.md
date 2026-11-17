# Replicação do modelo de responsabilidades por tenant

**Data:** 19/08/2026  
**Estrutura de referência:** `gmodelo_dbp`  
**Script-base aplicado:** `database/093_modelo_responsabilidades_base.sql`

## 1. Regra geral

O modelo de responsabilidades deve ser replicado como estrutura para cada
novo tenant. A replicação não deve copiar automaticamente atribuições,
delegações, grupos, usuários, categorias locais ou dados operacionais da
empresa Modelo.

O novo tenant deve receber:

- as mesmas tabelas `responsabilidade_*`;
- as mesmas chaves, índices e constraints;
- os mesmos módulos-base;
- as mesmas views e procedures universais, quando forem criadas;
- as permissões e menus previstos para o módulo.

O novo tenant não deve receber, salvo carga inicial explicitamente definida:

- usuários da Modelo;
- grupos da Modelo;
- associações de grupos da Modelo;
- atribuições de responsabilidades da Modelo;
- delegações da Modelo;
- categorias locais da Modelo;
- logs e dados operacionais da Modelo.

## 2. Exemplo de criação da Crown

Mapeamento de origem:

- `green_crown`;
- `green_crown_seguranca`.

Destino:

- `gcrown_dbp`.

Sequência futura:

1. cadastrar a empresa no catálogo central;
2. criar `gcrown_dbp` com a mesma configuração de charset e collation;
3. aplicar a estrutura-base do tenant Modelo;
4. aplicar `database/093_modelo_responsabilidades_base.sql` com o schema de
   destino ajustado para `gcrown_dbp`;
5. migrar usuários e grupos de `green_crown_seguranca`;
6. migrar os dados operacionais de `green_crown`;
7. sincronizar categorias legais e preservar os códigos de origem;
8. migrar vínculos de responsabilidade somente após o inventário da Crown;
9. criar menus e permissões conforme os módulos autorizados;
10. registrar cada etapa em `registros_migracao` do próprio tenant.

## 3. Regra de isolamento

O script de replicação nunca deve copiar dados por meio de consultas genéricas
da Modelo para o novo tenant. Cada carga deverá indicar explicitamente:

- banco de origem;
- banco de destino;
- empresa;
- versão do script;
- quantidade de registros;
- status;
- conflitos encontrados.

As atribuições migradas devem receber a origem correta, normalmente `DIRETA`,
e manter a referência ao identificador legado quando aplicável.

## 4. Pré-requisitos para um novo tenant

Antes da replicação, validar:

- banco de destino criado;
- charset `utf8mb4` e collation oficial;
- tabelas `usuarios`, `grupos` e `registros_migracao` existentes;
- chaves legadas migradas;
- usuário administrador inicial criado;
- permissões mínimas de administração disponíveis;
- backup dos bancos de origem e destino;
- execução em homologação antes do ambiente produtivo.

## 5. Estado atual

Neste momento:

- o modelo de responsabilidades foi aplicado somente em `gmodelo_dbp`;
- `gcrown_dbp` ainda não existe;
- nenhum banco legado da Crown foi alterado;
- nenhuma atribuição ou delegação foi migrada;
- a replicação está preparada documentalmente, aguardando o processo oficial
  de criação da empresa Crown.
