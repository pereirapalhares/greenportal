# Permissão de visualização de Licenças

## Regra de negócio

Na transação **Licenças**, a permissão **Visualizar** permite consultar somente os registros direcionados ao usuário logado. O escopo inicial é o campo `CODUSUARIO` da licença.

A permissão **Visualizar Total** permite consultar todas as licenças cadastradas no banco da empresa. Ela não substitui a permissão **Visualizar**: o usuário continua precisando ter acesso à transação.

## Perfis liberados

- `ADMINISTRADOR`: visualização total por regra administrativa.
- `ADM_DA_EMPRESA`: visualização e visualização total para Licenças.
- Outros perfis: seguem as marcações configuradas em **Segurança > Permissões**.

## Onde o escopo é aplicado

O filtro do usuário é aplicado no servidor, antes da consulta ao banco, quando o perfil não possui `pode_visualizar_total`. A mesma regra é aplicada a:

- carregamento do grid;
- contagem de registros e paginação;
- solicitação de exportação Excel.

Filtros enviados pelo navegador não podem remover ou substituir esse escopo.

## Configuração técnica

- Campo de permissão: `permissoes.pode_visualizar_total`.
- View de configuração: `vw_permissoes_grid`.
- Transação inicialmente contemplada: `LICENCAS`.
- Campo de escopo da transação: `CODUSUARIO`.
- Migração: `database/148_permissao_visualizacao_total_licencas.sql`.

A extensão da mesma regra para Treinamentos, Infraestrutura e demais transações será feita separadamente, após a definição do respectivo campo ou regra de direcionamento.
