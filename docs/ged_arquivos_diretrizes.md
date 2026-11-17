# Diretrizes de arquivos físicos do GED

Todos os arquivos físicos do portal devem seguir o mesmo padrão de armazenamento:

```text
E:\projetoGreen\GED\<banco de dados legado>\<pasta lógica>\<chave do registro>
```

Exemplos:

- Controle/documentos: `E:\projetoGreen\GED\green_modelo\documentos\<CODDOCUMENTO>`;
- Licenças: `E:\projetoGreen\GED\green_modelo\licencas\<CODLICENCA>`;
- Legislação: `E:\projetoGreen\GED\green_modelo\legislacao\<CODIGO>`.

Cada registro pode possuir zero, um ou vários arquivos. A pasta do registro deve ser criada automaticamente no primeiro upload.

## Interface

A gestão deve ser centralizada em `principal/gestao_arquivos.php`, aberta em um `iframe` dentro do popup do portal. A chamada deve informar `tipo`, `chave` e `valor`, por exemplo `gestao_arquivos.php?tipo=documentos&chave=CODDOCUMENTO&valor=151` ou `gestao_arquivos.php?tipo=licencas&chave=CODLICENCA&valor=1001`. Cada módulo deve reutilizar essa tela e o endpoint genérico, sem criar um popup próprio de upload.

As colunas de arquivo dos grids devem exibir um ícone acompanhado da quantidade de arquivos carregados, e não o caminho físico. O ícone abre um popup com um grid contendo nome físico, data de criação e tamanho. Os botões `Upload` e `Excluir arquivo` ficam na barra acima do grid do popup; não deve haver botões de ação dentro das linhas.

O nome físico deve ser seguro e único, preservando o nome original apenas como parte informativa. O popup deve permitir abrir/baixar o arquivo pelo nome exibido.

## Segurança e permissões

O endpoint deve validar sessão, tenant, permissão da transação, chave numérica ou identificador permitido e o caminho real resolvido. Não são permitidos caminhos enviados pelo usuário, `..`, barras ou acesso a outra pasta de registro.

Visualização permite listar e abrir arquivos. Para o GED, o upload usa a permissão de inclusão da transação `GESTAO_ARQUIVOS`; exclusão exige a permissão de excluir e confirmação explícita. A matriz inicial é:

| Perfil | Visualizar | Upload | Excluir |
|---|---:|---:|---:|
| `ADMINISTRADOR` | Sim | Sim | Sim |
| `ADM_DA_EMPRESA` | Sim | Sim | Sim |
| `GESTOR` | Sim | Sim | Não |
| Demais perfis | Não | Não | Não |

O backend deve repetir todas as validações; o estado dos botões no navegador é apenas auxiliar. A permissão do GED é independente da permissão do CRUD do registro relacionado.

O código do módulo deve receber somente a pasta lógica e a chave do registro. A montagem do caminho, criação da pasta, upload, listagem e exclusão devem ser centralizados para que Controle, Licenças e demais módulos mantenham o mesmo comportamento.
