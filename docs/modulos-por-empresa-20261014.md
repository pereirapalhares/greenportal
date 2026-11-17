# Módulos por empresa

Implementada a migração 265 para controlar os módulos comerciais no banco universal.

## Regra aplicada

- Atlas: Módulo Legal/Gestão Ambiental e Homologação de Fornecedores.
- Modelo e Modelo2: Módulo Legal/Gestão Ambiental, Homologação de Fornecedores e Não Conformidades.
- Administração, Segurança e Configurações continuam dependendo das permissões do usuário.
- O perfil `ADMINISTRADOR` tem acesso a todos os módulos.

## Implementação

- Catálogo universal `modulos_sistema`.
- Associação por empresa em `empresa_modulos`.
- Nova transação `seguranca_modulos_empresa.php`, restrita ao `ADMINISTRADOR`.
- Menu dos módulos comerciais é filtrado conforme a empresa.
- `PermissaoTransacao` bloqueia também a URL direta quando o módulo está desabilitado.
- Módulo Legal e Manutenção Legal são tratados como o mesmo módulo comercial.
- Homologação inclui os menus descendentes da raiz Homologação, inclusive Portaria.

## Migração

Executada com sucesso nos bancos universal e clientes da aplicação. A alteração é idempotente e pode ser reaplicada pelo arquivo `scripts/aplicar-migracao-265-modulos-empresa.php`.
