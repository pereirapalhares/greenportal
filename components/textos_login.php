<?php
declare(strict_types=1);

/*
 * Textos da tela de login (grupo LOGIN em Administração › Textos e idiomas).
 * Usado por index.php (exibição) e por principal/main.php (cadastro automático quando um ADMINISTRADOR entra,
 * já que na tela de login ninguém está identificado).
 * Os textos de Configurações › Login (por empresa) continuam valendo sobre estes.
 */

return [
    'login.titulo_pagina' => ['pt' => 'Acesso', 'en' => 'Sign in', 'es' => 'Acceso'],
    'login.eyebrow' => ['pt' => 'Bem-vindo de volta', 'en' => 'Welcome back', 'es' => 'Bienvenido de nuevo'],
    'login.titulo' => ['pt' => 'Acesse seu portal', 'en' => 'Access your portal', 'es' => 'Acceda a su portal'],
    'login.subtitulo' => ['pt' => 'Entre com seus dados para continuar.', 'en' => 'Sign in with your details to continue.', 'es' => 'Ingrese sus datos para continuar.'],
    'login.label_empresa' => ['pt' => 'Empresa', 'en' => 'Company', 'es' => 'Empresa'],
    'login.selecione_empresa' => ['pt' => 'Selecione sua empresa', 'en' => 'Select your company', 'es' => 'Seleccione su empresa'],
    'login.label_usuario' => ['pt' => 'Usuário', 'en' => 'User', 'es' => 'Usuario'],
    'login.placeholder_usuario' => ['pt' => 'Informe seu usuário', 'en' => 'Enter your user name', 'es' => 'Informe su usuario'],
    'login.label_senha' => ['pt' => 'Senha', 'en' => 'Password', 'es' => 'Contraseña'],
    'login.placeholder_senha' => ['pt' => 'Informe sua senha', 'en' => 'Enter your password', 'es' => 'Informe su contraseña'],
    'login.mostrar' => ['pt' => 'Mostrar', 'en' => 'Show', 'es' => 'Mostrar'],
    'login.ocultar' => ['pt' => 'Ocultar', 'en' => 'Hide', 'es' => 'Ocultar'],
    'login.mostrar_senha' => ['pt' => 'Mostrar senha', 'en' => 'Show password', 'es' => 'Mostrar contraseña'],
    'login.ocultar_senha' => ['pt' => 'Ocultar senha', 'en' => 'Hide password', 'es' => 'Ocultar contraseña'],
    'login.label_idioma' => ['pt' => 'Idioma', 'en' => 'Language', 'es' => 'Idioma'],
    'login.dica_idioma' => ['pt' => 'Idioma de trabalho', 'en' => 'Working language', 'es' => 'Idioma de trabajo'],
    'login.lembrar' => ['pt' => 'Lembrar meu acesso', 'en' => 'Remember me', 'es' => 'Recordar mi acceso'],
    'login.esqueci' => ['pt' => 'Esqueci minha senha', 'en' => 'Forgot my password', 'es' => 'Olvidé mi contraseña'],
    'login.botao_entrar' => ['pt' => 'Entrar', 'en' => 'Sign in', 'es' => 'Entrar'],
    'login.erro' => ['pt' => 'Não foi possível realizar o acesso.', 'en' => 'Sign-in failed.', 'es' => 'No fue posible acceder.'],
    'login.erro_dados_obrigatorios' => ['pt' => 'Informe a empresa, o usuário e a senha.', 'en' => 'Enter the company, user name and password.', 'es' => 'Informe la empresa, el usuario y la contraseña.'],
    'login.erro_empresa_invalida' => ['pt' => 'A empresa selecionada não está disponível para acesso.', 'en' => 'The selected company is not available for access.', 'es' => 'La empresa seleccionada no está disponible para el acceso.'],
    'login.erro_usuario_nao_encontrado' => ['pt' => 'Usuário não encontrado para a empresa selecionada.', 'en' => 'User not found for the selected company.', 'es' => 'Usuario no encontrado para la empresa seleccionada.'],
    'login.erro_usuario_inativo' => ['pt' => 'O usuário está inativo para a empresa selecionada.', 'en' => 'The user is inactive for the selected company.', 'es' => 'El usuario está inactivo para la empresa seleccionada.'],
    'login.erro_senha_incorreta' => ['pt' => 'A senha informada está incorreta.', 'en' => 'The entered password is incorrect.', 'es' => 'La contraseña informada es incorrecta.'],
    'login.erro_banco_indisponivel' => ['pt' => 'O banco de dados da empresa está indisponível.', 'en' => 'The company database is unavailable.', 'es' => 'La base de datos de la empresa no está disponible.'],
    'login.erro_indisponivel' => ['pt' => 'O serviço de autenticação está temporariamente indisponível.', 'en' => 'The authentication service is temporarily unavailable.', 'es' => 'El servicio de autenticación no está disponible temporalmente.'],
    'login.erro_sessao' => ['pt' => 'Sua sessão expirou. Entre novamente.', 'en' => 'Your session has expired. Please sign in again.', 'es' => 'Su sesión expiró. Vuelva a entrar.'],
    'login.direitos' => ['pt' => 'Todos os direitos reservados.', 'en' => 'All rights reserved.', 'es' => 'Todos los derechos reservados.'],
    'login.marketing_top_note' => ['pt' => 'Gestão inteligente para o seu negócio', 'en' => 'Smart management for your business', 'es' => 'Gestión inteligente para su negocio'],
    'login.marketing_eyebrow' => ['pt' => 'Um novo jeito de gerenciar', 'en' => 'A new way to manage', 'es' => 'Una nueva forma de gestionar'],
    'login.marketing_titulo' => ['pt' => 'Sistemas de gestão. Mais conformidade.', 'en' => 'Management systems. More compliance.', 'es' => 'Sistemas de gestión. Más cumplimiento.'],
    'login.marketing_texto' => ['pt' => 'Sistemas de Gestão em conformidade com as normas ISO 14001, OHSAS 18001 e ISO 16001, incluindo a integração destes diversos sistemas.', 'en' => 'Management systems compliant with ISO 14001, OHSAS 18001 and ISO 16001, including the integration of these systems.', 'es' => 'Sistemas de gestión en conformidad con las normas ISO 14001, OHSAS 18001 e ISO 16001, incluida la integración de estos sistemas.'],
    'login.marketing_texto_secundario' => ['pt' => 'Completo suporte ao gerenciamento dos resíduos e efluentes industriais existentes, levantamento da legislação ambiental e trabalhista aplicável e orientação para adequar a empresa aos requisitos que não estejam sendo plenamente atendidos.', 'en' => 'Full support for managing existing industrial waste and effluents, surveying the applicable environmental and labor legislation, and guidance to bring the company in line with requirements that are not fully met.', 'es' => 'Soporte completo para la gestión de los residuos y efluentes industriales existentes, el relevamiento de la legislación ambiental y laboral aplicable y orientación para adecuar la empresa a los requisitos que no se cumplen plenamente.'],
    'login.marketing_feature_1' => ['pt' => 'Integração dos sistemas de gestão', 'en' => 'Integrated management systems', 'es' => 'Integración de los sistemas de gestión'],
    'login.marketing_feature_2' => ['pt' => 'Gestão de resíduos e efluentes industriais', 'en' => 'Industrial waste and effluent management', 'es' => 'Gestión de residuos y efluentes industriales'],
    'login.marketing_feature_3' => ['pt' => 'Orientação ambiental e trabalhista', 'en' => 'Environmental and labor guidance', 'es' => 'Orientación ambiental y laboral'],
    'login.marketing_rodape_direito' => ['pt' => 'GESTÃO QUE TRANSFORMA', 'en' => 'MANAGEMENT THAT TRANSFORMS', 'es' => 'GESTIÓN QUE TRANSFORMA'],
];
