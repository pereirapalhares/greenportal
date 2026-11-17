# Dashboard Administrativo — 2026-10-04

Foi criado o Dashboard Administrativo em `principal/dashboard_admin.php`.

## Acesso

- `principal/dashboard.php` direciona o perfil `ADMINISTRADOR` para o painel
  administrativo;
- `dashboard_admin.php` valida novamente a sessão e o código exato do perfil;
- qualquer outro perfil recebe HTTP 403, inclusive em acesso direto à URL.

## Informações exibidas

- usuários ativos;
- unidades;
- perfis ativos;
- permissões cadastradas;
- requisitos legais;
- licenças em atenção;
- jobs pendentes;
- alertas não lidos;
- situações pendentes;
- requisitos sem tratamento ou sem unidade;
- licenças vencidas;
- status das solicitações de IA;
- quantidade e arquivo mais recente de backup.

As consultas usam exclusivamente o banco do tenant presente na sessão. O painel
não mostra pendências pessoais nem mistura dados de outras empresas.
