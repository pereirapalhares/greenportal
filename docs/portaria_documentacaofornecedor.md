# Portaria › Documentação de Fornecedor

Tela: `principal/portaria_documentacaofornecedor.php` · menu `PORTARIA_DOCUMENTACAO_FORNECEDOR`
Legado: transação `foFornecedorxCriterios` (hpr_configuracoes, codtransacao 194).

## O que faz
Consulta dos documentos exigidos de cada fornecedor (critérios do tipo de fornecedor): unidade, fornecedor,
tipo/documento, documento enviado, responsável, evidências, validade, em protocolo, situação e observações.
Somente consulta (sem salvar/incluir/duplicar/excluir). Exportação para Excel continua disponível.

## Evidências (GED)
Coluna **Evidências**: botão com a quantidade de arquivos; abre a janela de arquivos para ver e enviar.
- Tipo GED `fornecedorcriterios` → tabela `fo_fornecedorxcriterios`, chave `CODFORNECEDORXCRITERIOS`.
- Pasta (a mesma do legado): `E:\projetoGreen\GED\green_<empresa>\fo_fornecedorxcriterios\<código>`.
- Permissão (`classes/GedPermissaoTipo.php`): Gestão de Arquivos **ou** a própria tela
  (ver = Visualizar/Visualizar arquivos, enviar = Importar arquivos/Incluir, excluir = Excluir).
- Fora do ADMINISTRADOR, o registro tem de ser de uma unidade do usuário (`sp_portaria_docfornecedor_permitido`),
  também na contagem e no envio.

## Restrição por unidade
- ADMINISTRADOR: `vw_portaria_documentacao_fornecedor_grid` (todas as unidades).
- Demais perfis: `vw_portaria_documentacao_fornecedor_acesso` filtrada por `CODUSUARIO_ACESSO` = usuário logado
  (vale mesmo com "Visualizar total").
- Unidades do usuário: `vw_portaria_usuario_unidades` = `usuariosxunidades` (quando existe no banco) + `usuarios.codunidade`.

## Cores (situação do documento) — `SITUACAO_COR`
| Cor | Regra |
|---|---|
| Vermelho | Reprovado, ou Aprovado com validade vencida |
| Laranja | Aprovado, vence em até 30 dias |
| Verde | Aprovado / Aprovado sem validade |
| Amarelo | Pendente com o fornecedor |
| Azul | Pendente com a empresa |
| Cinza | NA, Não enviar ao fornecedor, Ciente da regra |
| Sem cor | Sem situação |

A legenda acima do grid mostra quantas linhas da página há em cada cor; clicar num item mostra só aquela cor.

## Acesso
Migração 260 chama a 247:
- PORTARIA: visualizar, exportar, ver arquivos, enviar arquivos.
- HOMOLOGACAO, ADM_DA_EMPRESA: os mesmos + excluir arquivos.

## Migração e teste
```
E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-260-portaria-documentacao-fornecedor.php --simular
E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-260-portaria-documentacao-fornecedor.php
E:\wamp\bin\php\php8.4.15\php.exe scripts\testar-portaria-documentacao-fornecedor.php
```
Arquivos: `database/260_portaria_documentacao_fornecedor.sql`, `assets/js/portaria-documentacao-fornecedor.js`,
`assets/css/portaria-documentacao-fornecedor.css`; ajustes em `principal/api/gestao_arquivos.php`,
`principal/api/ged_contagens.php`, `principal/gestao_arquivos.php`, `classes/GedStorageService.php`,
`assets/js/modulo-legal-arquivos.js`, `scripts/aplicar-migracao-247-permissoes-transacao.php` (ações `total`, `arquivos`, `importar`).

## Análise por IA das evidências (migração 261)
Na janela de arquivos (coluna Evidências), selecione o arquivo e clique em **Analisar com IA**:
1. O texto do arquivo é extraído no servidor (PDF, imagem com OCR, Word) e enviado à IA com o prompt
   `FORNECEDOR_DOCUMENTO` (Setup › Prompts de IA), junto com o **documento exigido** e o **fornecedor** cadastrados.
2. A IA devolve tipo, se corresponde ao exigido (Sim/Não/Incerto), número, órgão emissor, razão social, CNPJ,
   emissão, validade e alertas. O sistema confere razão social, raiz do CNPJ e vencimento.
3. Na aba **Revisão dos dados** o usuário revisa e, com "Atualizar o documento do fornecedor", grava em
   `fo_fornecedorxcriterios`: validade, situação (sugerida pela leitura: Aprovado / Aprovado sem validade / Reprovado se vencido),
   documento enviado e obs. da empresa. Campos gravados ficam protegidos contra o sincronismo legado (`fo_sinc_protecoes`).
4. O grid recarrega com a nova situação e cor.

- Autorização: **Editar** na transação Documentação de Fornecedor (Segurança › Permissões). A 261 libera para
  HOMOLOGACAO e ADM_DA_EMPRESA (`--perfis-ia=` muda a lista). Sem Editar o perfil vê e envia arquivos, mas não usa a IA.
- Mesmo documento já lido (mesmo conteúdo) não é reenviado à IA; reanálise só para ADM da empresa/ADMINISTRADOR.
- Banco: `fo_fornecedorxcriterios_documentos_ia`; procedures `sp_docfornecedor_ia_registro/_gravar/_obter/_descartar/_confirmar`.
- API: `principal/api/fornecedorcriterios_ia.php` (analyze, review, confirm, discard).
```
E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-261-portaria-documentacao-fornecedor-ia.php --simular
E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-261-portaria-documentacao-fornecedor-ia.php
```

## Coluna e filtro de situação
- Coluna **Situação** ao lado do nome (fornecedor / funcionário / treinamento): ícone da cor + texto (ex.: ● Reprovado); a linha mantém a cor.
- Filtro de situação ao lado da paginação: lista com ícone + texto; filtra no servidor pelo campo `SITUACAO_COR` (vale para todas as páginas e para o Excel).
- A legenda acima do grid foi retirada. A situação gravada no banco (coluna SITUACAO) fica oculta; aparece em Colunas e no Excel.
