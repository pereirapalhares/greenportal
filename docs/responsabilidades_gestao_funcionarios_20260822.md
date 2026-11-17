# Responsabilidades — Gestão de Funcionários

Data: 22/08/2026  
Tenant: `gmodelo_dbp` — Empresa Modelo  
Transação: `HOMOLOGACAO_FORNECEDOR_FUNCIONARIO`

## Escopo inicial

Foi criada a consulta e a manutenção de atribuições diretas para registros de
`fo_funcionarios`. O acesso é contextual ao funcionário selecionado na grade
de Gestão de Funcionários e usa a mesma regra visual e de permissões aplicada
à Gestão de Fornecedores.

## Regra de legado

Não foi migrado `fo_funcionarioxtiposxtreinamentos.codusuario`. Esse campo
representa a responsabilidade por um treinamento e está classificado no
módulo `TREINAMENTO`, com semântica ainda pendente de revisão. Ele não será
apresentado como responsabilidade do funcionário até decisão funcional
específica.

Registros órfãos e a diferença de cinco funcionários entre os bancos legado e
novo permanecem fora desta implementação e serão tratados em saneamento
posterior.

## Componentes

- `database/113_responsabilidades_funcionarios.sql`: view e registro da
  alteração estrutural;
- `principal/funcionario_responsabilidades.php`: consulta contextual;
- `principal/funcionario_responsabilidades_edicao.php`: manutenção de
  atribuições diretas;
- `principal/homologacaofornecedor_funcionario.php`: botão contextual;
- `assets/js/responsabilidades-permissoes.js`: habilitação e aplicação das
  permissões na tela.

O próximo passo após aplicar o SQL é preparar e executar o roteiro visual
PER-008.
