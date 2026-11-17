# Análise de responsabilidades — Treinamento

Data: 22/08/2026  
Tenant: `gmodelo_dbp` — Empresa Modelo  
Origem: `fo_funcionarioxtiposxtreinamentos`

## Decisão funcional

`fo_funcionarioxtiposxtreinamentos.codusuario` representa o usuário
responsável pelo registro específico de treinamento. A responsabilidade será
contextual ao treinamento, não ao funcionário.

| Campo | Regra |
|---|---|
| Módulo | `TREINAMENTO` |
| Tipo | `TREINAMENTO` |
| Entidade | `fo_funcionarioxtiposxtreinamentos` |
| Identificador | `codigo` |
| Destino | usuário |

O funcionário e o tipo de funcionário serão exibidos como contexto auxiliar,
mas não definirão o identificador da responsabilidade.

## Auditoria inicial

| Verificação | Resultado |
|---|---:|
| Registros na tabela de treinamento | 955 |
| Registros com `codusuario` preenchido | 955 |
| Responsáveis encontrados em `gmodelo_dbp.usuarios` | 2 |
| Registros sem usuário correspondente no tenant novo | 953 |

Os 953 registros sem correspondência serão classificados como órfãos e ficarão
fora da carga inicial, conforme a decisão geral do projeto. Nenhum registro
órfão será apagado ou alterado automaticamente.

## Estratégia de implementação acelerada

1. Criar a view contextual dos vínculos válidos de treinamento.
2. Cargar somente os dois vínculos com usuário existente, de forma idempotente.
3. Adicionar o botão Responsabilidades ao grid de Treinamentos do Funcionário.
4. Reutilizar a consulta/manutenção já homologada, adaptando apenas a entidade,
   o título e a permissão da transação.
5. Homologar em um único roteiro PER-009: acesso, contexto, legado válido,
   manutenção, permissões e preservação dos órfãos.

## Fora do escopo

- saneamento dos 953 órfãos;
- alteração da tabela legada de treinamento;
- responsabilidade no nível do funcionário;
- replicação para outros tenants.
