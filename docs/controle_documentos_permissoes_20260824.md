# Permissões do Controle

A matriz de permissões da transação `CONTROLE_DOCUMENTOS` foi ajustada pela migração `CONTROLE_DOCUMENTOS_PERMISSOES_126`.

| Perfil | Visualizar | Incluir | Editar | Excluir | Exportar | PDF |
|---|---:|---:|---:|---:|---:|---:|
| ADMINISTRADOR | 1 | 1 | 1 | 1 | 1 | 0 |
| ADM_DA_EMPRESA | 1 | 1 | 1 | 1 | 0 | 0 |
| GESTOR | 1 | 0 | 0 | 0 | 0 | 0 |
| Demais perfis | 0 | 0 | 0 | 0 | 0 | 0 |

O `ADM_DA_EMPRESA` pode criar, alterar e excluir documentos e associações. O `GESTOR` pode consultar o grid e abrir os relacionamentos em modo somente leitura. As validações permanecem no servidor; esconder ou desabilitar botões no navegador não substitui a autorização backend.
