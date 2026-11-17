# Diretriz de personalização da tela de login

O usuário com perfil `ADMINISTRADOR` pode editar, pela transação `setup_login.php`, os textos da tela de login. A configuração inclui a marca, textos do formulário, conteúdo do painel direito, benefícios e rodapés.

As chaves do painel direito são:

- `login_marketing_top_note`
- `login_marketing_eyebrow`
- `login_marketing_titulo`
- `login_marketing_texto`
- `login_marketing_texto_secundario`
- `login_marketing_feature_1`, `login_marketing_feature_2`, `login_marketing_feature_3`
- `login_marketing_rodape_esquerdo`, `login_marketing_rodape_direito`

Os valores são armazenados em `setup_empresa`, respeitando o banco do tenant. A tela possui valores padrão para campos ainda não cadastrados e o preview deve refletir todos os campos editáveis antes do salvamento.

O navegador pode lembrar o último `empresa` e `userid` utilizados em armazenamento local. A senha nunca é armazenada; a cada novo acesso ela deve ser informada novamente. A identificação lembrada é restrita ao navegador e pode ser substituída quando o usuário selecionar outra empresa ou informar outro usuário.
