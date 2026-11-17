# Resultado do CRUD de Condicionantes — 22/09/2026

## Diagnóstico

- O salvamento é um POST tradicional do formulário dentro do iframe contextual.
- O primeiro roteiro aguardava confirmação no documento principal, mas o modal `greenPortalCadastroConfirm` é criado dentro do iframe.
- Após corrigir o seletor, o teste recebeu POST HTTP 302 e confirmou o recarregamento do grid.

## Execução

- ADMINISTRADOR autenticado.
- Grid contextual aberto a partir de uma Licença existente.
- Registro temporário `TESTE CONDICIONANTE 20260922` incluído.
- Grid passou de 1 para 2 registros após o salvamento.
- Registro consultado diretamente no banco com `CODCONDICIONANTE=18`.
- Edição para `OBS=Registro temporario editado` confirmada no banco.
- A tentativa automatizada de exclusão pelo modal contextual excedeu o timeout do roteiro; o registro foi removido imediatamente por rotina de limpeza restrita ao texto exato do teste.
- Consulta posterior confirmou zero registros temporários restantes.

## Evidências

- `tests/resultado-crud-condicionantes-cdp-v2-20260922.json`
- `tests/resultado-edicao-exclusao-condicionante-cdp-20260922.json`
- `scripts/teste-crud-condicionantes-cdp-v2.js`
- `scripts/consultar-condicionantes-teste.php`
- `scripts/limpar-condicionante-teste.php`

## Estado

Inclusão e edição: **aprovadas tecnicamente**.  
Exclusão pela interface: **pendente de ajuste do roteiro de automação**.  
Limpeza do dado temporário: **concluída**, sem registros de teste remanescentes.
