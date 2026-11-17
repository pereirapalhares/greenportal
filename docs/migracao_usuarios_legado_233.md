# Migração dos campos legados de usuários — 233

Data da execução: 13/10/2026.

## Origem e destino

- `green_atlas_seguranca.usuarios` → `gatlas_dbp.usuarios`
- `green_modelo_seguranca.usuarios` → `gmodelo_dbp.usuarios`

## Diagnóstico

As tabelas atuais do GreenPortal já possuíam o modelo canônico (`userid`, `nome`, `email`, `senha`, `codperfil`, `codgrupo`, `ativo`, `criado_em` e `ultimo_acesso`). Os bancos legados possuíam campos adicionais que não estavam presentes nos bancos atuais, principalmente:

`DTINCLUSAO`, `USUARIO_SUPERIOR`, `EMAILS`, `ativado`, `logado`, `ultimoacesso`, `ultimologin`, `codfornecedor`, `usuario_licensa`, `nrcelular`, `codunidade`, `dataultimatrocasenha`, `codusuario_criador`, `token`, `token_data_criacao`, `token_validade_minutos`, `USERID_AD`, `TIPO_AUTH`, `NOME_AD` e `EMAIL_AD`.

## Ações executadas

1. Os campos ausentes foram adicionados às tabelas atuais sem remover os campos canônicos do GreenPortal.
2. A correspondência dos registros foi feita por `USERID`. Quando o legado possuía duplicidade, foi utilizada a linha com maior `CODUSUARIO`, garantindo uma origem determinística.
3. `EMAILS` foi preservado e utilizado como fallback do campo `email`.
4. `ativado` foi convertido para `ativo` quando havia valor no legado.
5. `ultimo_acesso` passou a considerar o valor atual e, quando ausente, `ultimologin`/`ultimoacesso`.
6. `USUARIO_SUPERIOR` foi preservado no campo legado `usuario_superior` e convertido também para `codusuario_superior` usando o `USERID` do superior, evitando depender de códigos diferentes entre bancos.
7. A view `vw_usuarios_grid` foi atualizada para expor o nome do usuário superior e os campos compatíveis da estrutura legada.
8. A migração foi registrada em `registros_migracao` com o código `SEGURANCA_USUARIOS_LEGADO_233`.

## Resultado

- `gatlas_dbp`: 176 usuários; 176 correspondências migradas.
- `gmodelo_dbp`: 2.526 usuários; 2.526 correspondências migradas.

O legado possui duplicidades de `USERID` — três casos no Atlas e dez casos no Modelo, além de 84 linhas com `USERID` vazio no Modelo. Essas linhas não geraram novos usuários; foi aplicada a regra determinística do maior `CODUSUARIO`. A migração atualiza somente registros já existentes no banco destino.

## Aplicação

A tela `principal/seguranca_usuarios.php` passa a utilizar a view atualizada, mantendo a ordem:

`Perfil | Grupos | Usuário | Usuário superior | Senha | Nome | E-mails | Último login | Ativo`.

A senha continua protegida na tela e não é exportada para Excel.
