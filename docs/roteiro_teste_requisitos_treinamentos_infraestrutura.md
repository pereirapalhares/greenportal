# Teste visual dos relacionamentos do requisito legal

Comando:

```powershell
.\scripts\executar-teste-visual-requisitos-treinamentos-infraestrutura.ps1 -Empresa Modelo -Usuario admin -Senha "<senha de teste via variável de ambiente>" -Visual
```

O teste valida login, seleção do requisito `LB0001`, habilitação dos botões,
abertura dos popups, ausência de erros, barra de ações acima do grid e ausência
de botões operacionais dentro das linhas para Treinamentos e Infraestrutura.
