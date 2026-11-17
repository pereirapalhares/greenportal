# Validação de login da Atlas — 2026-10-03

- Empresa central: `ATLAS` → `gatlas_dbp`, status `ATIVA`.
- Usuário testado: `admin`.
- Resultado HTTP: aprovado.
- URL final: `/principal/main.php`.
- Sessão: criada e reconhecida pelo portal.
- Tenant exibido: Atlas.
- Perfil: `ADMINISTRADOR`, ativo e com `acesso_total`.

O teste de navegador automatizado não foi executado porque o Chromium do ambiente retornou `spawn EPERM`. O fluxo HTTP real foi executado com sucesso.

Pendências identificadas: a senha está em formato legado (`senha_usa_hash=0`) e a matriz dos usuários comuns ainda depende do relacionamento dos 667 `CODMENU` legados com o catálogo funcional atual. A empresa deve permanecer em homologação até essas pendências serem tratadas.
