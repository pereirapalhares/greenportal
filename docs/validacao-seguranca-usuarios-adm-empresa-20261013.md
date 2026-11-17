# Validação da tela de usuários — ADM DA EMPRESA

Data: 13/10/2026
Empresa: Atlas
Transação: `principal/seguranca_usuarios.php`

## Resultado

Os testes visuais e operacionais foram executados no Firefox com um usuário real do perfil `ADM_DA_EMPRESA`, habilitado temporariamente apenas durante o teste. Ao final, o usuário e a permissão original foram restaurados.

- Usuários/perfis `ADMINISTRADOR` não aparecem na grade: aprovado.
- Paginação: aprovada; navegação da página 1/7 para 2/7 executada.
- Tentativa de atribuir o perfil `ADMINISTRADOR`: bloqueada pelo servidor.
- Tentativa de editar o usuário `ADMINISTRADOR`: bloqueada pelo servidor.
- Novo usuário com campo `Usuário superior`: aprovado visualmente.
- Solicitação de exportação: aprovada.
- Arquivo Excel gerado: aprovado.
- Coluna/campo `senha` no XLSX: não encontrado.

Evidência visual: `docs/teste-seguranca-usuarios-adm-empresa-firefox.png`.

## Observação operacional

O disparo automático do worker de exportação deixou o job inicialmente em `SOLICITADA`. O worker foi executado controladamente para concluir a validação; o arquivo foi gerado corretamente. O acionamento automático do worker deve ser tratado como próximo ajuste operacional do serviço de exportação.
