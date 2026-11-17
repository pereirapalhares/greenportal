# Fase 6 — Homologação e preparação para produção

## Objetivo

Validar de forma repetível a implementação das Fases 1 a 5 antes da liberação para uso real.

## Verificação automatizada

Executar:

```powershell
php scripts\homologar-fase6.php
```

O verificador não altera dados. Ele valida:

- menus universais da Fase 5 e seus textos PT, EN e ES;
- tabelas, views, índices e migrações das Fases 1 a 5;
- permissões de visualização total, importação e visualização de arquivos;
- vínculos documentais sem registros órfãos;
- unicidade de versões ativas de licenças;
- regras dos relatórios de documentos compartilhados e requisitos sem evidência;
- existência de registros de auditoria.

## Homologação visual

Deve ser realizada no Firefox com os perfis administrador e usuário comum, verificando login, menu, filtros, paginação, exportação e mensagens de permissão nas telas de requisitos, controles, licenças, GED e nos relatórios da Fase 5.
