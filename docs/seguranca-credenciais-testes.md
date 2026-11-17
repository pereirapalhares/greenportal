# Segurança de credenciais de testes

Os testes não devem armazenar senhas em scripts, documentação, evidências ou logs.

Configure as credenciais somente no ambiente de execução:

```powershell
$env:GREEN_TEST_PASSWORD = '<senha temporária>'
$env:GREEN_CROWN_TEST_USER = 'admin'
$env:GREEN_CROWN_TEST_PASSWORD = '<senha temporária Crown>'
$env:GREEN_MIGRATION_ADMIN_PASSWORD = '<senha técnica de migração>'
```

Antes de publicar, execute:

## Exceção Crown

A senha atual do usuário administrador da Crown deve ser mantida. Não executar rotação automática nem alteração no banco `gcrown_dbp`; fornecê-la somente por variável de ambiente durante testes autorizados.

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\verificar-credenciais-testes.ps1
```

O resultado esperado é `"ok": true` e `findings` vazio. As senhas de teste devem ser temporárias e rotacionadas após a homologação.
