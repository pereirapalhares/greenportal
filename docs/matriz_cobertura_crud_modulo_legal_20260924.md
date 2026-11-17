# Matriz de cobertura CRUD — Módulo Legal

**Data:** 24/09/2026  
**Empresa:** Modelo  
**Banco:** `gmodelo_dbp`  
**Status:** execução técnica concluída, com itens de escopo somente leitura e pendências de aceite formal.

## Resultado consolidado

| Item | Tipo de validação | Resultado |
|---|---|---|
| Revisão do LAIA | CRUD completo | Aprovado |
| Revisão do LPRO | CRUD completo | Aprovado |
| Meus Controles | Edição, persistência e restauração | Aprovado |
| Minhas Licenças | Edição, persistência e restauração | Aprovado |
| Minhas Infraestruturas | Edição, persistência e restauração | Aprovado |
| Meus Treinamentos | Escopo e responsabilidade temporária | Aprovado |
| Potenciais IA | Solicitar, aprovar, rejeitar e permissões | Aprovado |

## Detalhamento

### Revisões LAIA e LPRO

Foram confirmadas, para as duas telas, inclusão, persistência na tabela/view, edição, duplicação, exclusão e limpeza dos registros temporários.

Evidência: `tests/resultado-crud-revisoes-20260924.json`.

### Telas direcionadas

Em cada tela foi alterado um campo editável do primeiro registro visível. O valor foi salvo, confirmado após recarga e restaurado ao conteúdo original:

- Meus Controles: `TITULO`;
- Minhas Licenças: `DSCLICENCA`;
- Minhas Infraestruturas: `DSCINFRAESTRUTURA`.

As telas direcionadas continuam sem inclusão, duplicação e exclusão, conforme a regra funcional. Evidência: `tests/resultado-edicao-telas-direcionadas-20260924.json`.

### Meus Treinamentos

Foi criada uma responsabilidade temporária, confirmada no popup, verificada na tela direcionada e removida ao final. O escopo do usuário foi preservado e não restaram fixtures.

Evidência: `tests/resultado-meus-treinamentos-20260924.json`.

### Potenciais IA

O ciclo completo foi executado com os perfis `admin`, `ueditor` e `gestor`:

- solicitação de potencial;
- aprovação pelo Administrador;
- rejeição pelo ADM da Empresa;
- bloqueio das ações de alteração para o Gestor;
- limpeza dos registros temporários.

O menu foi ativado temporariamente porque estava `ativo=0` no banco. Após o teste, retornou para `ativo=0`; não restaram potenciais ou solicitações de fixture.

Evidência: `tests/resultado-potenciais-ia-20260924.json`.

## Submenus contextuais de LAIA, LPRO e LAIS

Os submenus foram classificados como somente leitura. A implementação configura `save`, `new`, `duplicate` e `delete` como `false` nos grids contextuais.

| Grupo | Submenus auditados | CRUD de mutação |
|---|---|---|
| LAIA | Requisitos legais, Controles e documentos, Objetivos e metas, Inventário | Não aplicável na configuração atual |
| LPRO | Requisitos legais, Controles administrativos, Controles e documentos, EPIs, Infraestrutura, Objetivos e metas | Não aplicável na configuração atual |
| LAIS | Requisitos legais, Controles e documentos, Objetivos e metas, Inventário | Não aplicável na configuração atual |

Para esses submenus, a cobertura adequada é acesso, contexto, filtros, relacionamentos e isolamento de dados. Não devem ser criados marcadores CRUD enquanto a regra funcional não autorizar mutação.

## Limpeza e estado final

- Modelo2 permanece `INATIVA`.
- Menu Potenciais IA retornou a `INATIVO`.
- Fixtures de Potenciais IA restantes: zero.
- Registros temporários das edições direcionadas: zero.
- Registros temporários das revisões: zero.

## Pendências

1. Registrar aceite formal dos responsáveis de negócio.
2. Decidir formalmente se algum submenu contextual deverá permitir mutação.
3. Repetir a matriz no ambiente oficial ou semelhante à produção.

Resultado estruturado: `tests/resultado-cobertura-crud-modulo-legal-20260924.json`.
