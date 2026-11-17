# Teste não visual do CRUD

```powershell
.\scripts\executar-teste-nao-visual-crud-requisitos-treinamentos-infraestrutura.ps1 -Empresa Modelo -Usuario admin -Senha "<senha de teste via variável de ambiente>"
```

O teste seleciona o requisito `LB0001`, abre os dois relacionamentos, inclui
uma associação usando o combobox, confirma a gravação, verifica a persistência,
exclui a linha e confirma a exclusão. O resultado fica no arquivo JSON
informado pelo parâmetro `-Saida`.
