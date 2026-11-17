# Portaria › Documentação de Funcionário

Tela: `principal/portaria_documentacaofuncionario.php` · menu `PORTARIA_DOCUMENTACAO_FUNCIONARIO`
Legado: transação `foFuncionariosXCriterios` (hpr_configuracoes, codtransacao 195). Mesmo padrão da Documentação de Fornecedor.

## O que faz
Consulta dos critérios exigidos de cada funcionário dos fornecedores (`fo_funcionarioxtiposxcriterios`): unidade, razão social,
funcionário, responsável, nº do registro, critério, atende ao critério?, situação, validade, evidências e observações.
Somente consulta; exportação para Excel disponível. Mesma regra do legado: funcionário com nome e fornecedor com unidade.

## Evidências (GED) e IA
- Tipo GED `funcionariocriterios` → `fo_funcionarioxtiposxcriterios`, chave `CODIGO`, pasta do legado
  `E:\projetoGreen\GED\green_<empresa>\fo_funcionarioxtiposxcriterios\<codigo>`.
- Ver/enviar/excluir arquivos: Gestão de Arquivos **ou** a própria tela (Visualizar arquivos / Importar arquivos / Excluir).
- **Analisar com IA** (exige **Editar** nesta transação): prompt `FUNCIONARIO_DOCUMENTO` (o mesmo da Gestão de Funcionários),
  com o critério exigido e o funcionário; dados de saúde removidos antes do envio (`classes/FuncionarioDocumentoTexto.php`).
  Confere nome, CPF, tipo de documento x critério (ASO / NR) e validade. Na revisão, grava no critério:
  validade, atende ao critério? (sugerido pela aptidão do ASO ou pelo treinamento; "Não" se vencido), situação
  ("Não alterar" / "Sem pendência" / pendente) e observações. Campos gravados ficam protegidos do sincronismo legado.
- API `principal/api/funcionariocriterios_ia.php`; tabela `fo_funcionarioxtiposxcriterios_documentos_ia`; procedures `sp_docfuncionario_ia_*`.

## Restrição por unidade
ADMINISTRADOR: `vw_portaria_documentacao_funcionario_grid`. Demais perfis: `vw_portaria_documentacao_funcionario_acesso`
filtrada por `CODUSUARIO_ACESSO` (unidade do fornecedor do funcionário; `vw_portaria_usuario_unidades` da migração 260).

## Cores — `SITUACAO_COR`
| Cor | Regra |
|---|---|
| Cinza | Atende = Desligado, Afastado ou NAP |
| Vermelho | Atende = Sim com validade vencida, ou Atende = Não sem pendência |
| Laranja | Atende = Sim, vence em até 30 dias |
| Verde | Atende = Sim |
| Amarelo / Azul | Pendente com o fornecedor / com a empresa |
| Sem cor | Sem informação |

## Acesso (migração 262 → 247)
PORTARIA: visualizar, exportar, ver e enviar arquivos. HOMOLOGACAO e ADM_DA_EMPRESA: os mesmos + excluir arquivos + editar (IA).

```
E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-262-portaria-documentacao-funcionario.php --simular
E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-262-portaria-documentacao-funcionario.php
E:\wamp\bin\php\php8.4.15\php.exe scripts\testar-portaria-documentacao-funcionario.php
```

## Associações › Treinamentos (migração 263)
Botão **Treinamentos** (grupo Associações), como a aba "Treinamento" do legado (`foFuncionariosxTreinamentos`, codtransacao 196):
abre `principal/portaria_documentacaofuncionario_treinamentos.php` com os treinamentos do funcionário da linha escolhida
(`fo_funcionarioxtiposxtreinamentos`, filtro CODFUNCIONARIO), somente consulta: tipo de funcionário, treinamento,
realizado?, e-mail do responsável, validade, dias para vencer e evidências (GED tipo `funcionariotreinamentos`,
pasta `fo_funcionarioxtiposxtreinamentos\<codigo>`).
- Permissão: a da Documentação de Funcionário; mesma restrição por unidade (views `vw_portaria_documentacao_treinamento_grid` / `_acesso`,
  procedure `sp_portaria_doctreinamento_permitido`).
- Cores: realizado com validade vencida ou não realizado (vermelho), vence em até 30 dias (laranja), realizado (verde), sem informação.
```
E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-263-portaria-documentacao-funcionario-treinamentos.php
```

## Coluna e filtro de situação
- Coluna **Situação** ao lado do nome (fornecedor / funcionário / treinamento): ícone da cor + texto (ex.: ● Reprovado); a linha mantém a cor.
- Filtro de situação ao lado da paginação: lista com ícone + texto; filtra no servidor pelo campo `SITUACAO_COR` (vale para todas as páginas e para o Excel).
- A legenda acima do grid foi retirada. A situação gravada no banco (coluna SITUACAO) fica oculta; aparece em Colunas e no Excel.
