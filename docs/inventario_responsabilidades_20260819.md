# Inventário técnico — Modelo de responsabilidades

**Data:** 19/08/2026  
**Escopo:** preparação da Fase 1 do modelo de responsabilidades  
**Bancos analisados:** `gmodelo_dbp` e `glegislacao_dbp`

## 1. Resultado executivo

O banco `gmodelo_dbp` já possui usuários, grupos, auditoria e diversos
vínculos legados baseados em `CODUSUARIO`. Não foram encontradas tabelas com
prefixo `responsabilidade_`, portanto o modelo da Fase 1 ainda não foi criado.

Também existe uma estrutura específica de delegação do módulo ESG,
`esg_delegacao_evento`, mas ela não possui registros e não representa ainda o
modelo comum de delegação da aplicação.

## 2. Estruturas existentes

### 2.1 Usuários e grupos

- `usuarios`: 2.526 registros.
- `grupos`: 35 registros.
- `usuarios.codusuario` é a chave primária legada oficial.
- `grupos.codgrupo` é a chave primária legada oficial.
- `usuarios.codgrupo` possui foreign key para `grupos.codgrupo`.
- Usuários possuem os campos `ativo`, `codperfil`, `codgrupo`, `criado_em` e
  `ultimo_acesso`.
- Grupos possuem `nome` e `ativo`.

Conclusão: grupos existentes não devem ser substituídos automaticamente por
`responsabilidade_grupos`. Primeiro deve ser definido se serão reutilizados
como grupos de responsabilidade ou se haverá uma camada complementar.

### 2.2 Auditoria

Existe a tabela `logs_auditoria`, com os principais campos:

- usuário;
- transação;
- ação;
- entidade;
- identificador do registro;
- resultado;
- detalhes em JSON;
- data e hora.

Há apenas 1 registro no momento do inventário. A estrutura é reutilizável, mas
será necessário confirmar se atende ao registro de valores anteriores e novos,
delegação e correção de órfãos.

### 2.3 Categorias legais

No banco `glegislacao_dbp` existem:

- `legislacao_categoria`: 18 categorias;
- `legislacaoxcategoria`: 8.556 relacionamentos.

As chaves oficiais são `CODCATEGORIALEGISLACAO` e
`CODLEGISLACAOXCATEGORIA`. Ainda é necessário decidir se as categorias serão
replicadas para cada tenant ou consultadas diretamente no banco legislativo.

### 2.4 Delegação existente

`esg_delegacao_evento` possui os campos:

- `CODDELEGACAO`;
- usuário solicitante;
- usuário de origem;
- usuário de destino;
- escopo;
- referências a relatório, indicador e papel;
- motivo;
- status;
- validade inicial e final.

A tabela possui 0 registros. Ela deve ser avaliada como possível origem
conceitual, mas não deve ser usada diretamente como tabela universal sem
revisão, pois está acoplada ao módulo ESG e usa referências específicas.

## 3. Vínculos legados localizados

Foram encontrados campos relacionados a usuários em:

- situações legais e planos de ação;
- licenças;
- medição e monitoramento;
- planos de ação;
- homologação de fornecedores;
- contatos e fornecedores;
- eventos e relatórios ESG;
- alertas, exportações e preferências;
- auditoria.

Entre as tabelas com dados de responsabilidade estão `li_licencas`,
`mm_monitoramento`, `planodeacao`, `fo_fornecedorxusuario` e
`esg_relatorio_item_usuario`.

Esses vínculos devem ser preservados durante a primeira migração. A migração
deve apenas criar uma representação complementar quando necessário, sem
alterar ou apagar os campos legados.

## 4. Pontos que precisam de decisão

1. Reutilizar `grupos` ou criar grupos de responsabilidade separados.
2. Definir a tabela universal de vínculos e sua estratégia de validação.
3. Criar uma tabela universal de delegação ou adaptar a estrutura ESG.
4. Definir a prioridade entre delegação, atribuição direta, tipo e categoria.
5. Definir se categorias serão replicadas para o tenant.
6. Definir como serão identificados módulos, entidades e registros na tabela
   genérica.
7. Definir como impedir períodos de validade sobrepostos.
8. Definir o conceito de responsável principal quando houver vários usuários
   ou grupos.

## 5. Próximo passo técnico

Antes de criar tabelas, elaborar o modelo lógico final contendo:

- nomes definitivos;
- chaves primárias;
- foreign keys possíveis;
- regras de vigência;
- regras de duplicidade;
- resolução de responsáveis efetivos;
- delegação;
- auditoria;
- estratégia de compatibilidade com os vínculos legados.

Nenhuma tabela, procedure, view, dado ou vínculo foi alterado neste inventário.
