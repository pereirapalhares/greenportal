# Nota de Reteste — PER-007
## Confirmação de acesso pelo botão "Responsabilidades"

**Data do teste:** 21/08/2026
**Roteiro de referência:** roteiro_reteste_PER007_botao_responsabilidades_fornecedor_20260821.txt
**Ambiente:** Green Portal — instância local (http://localhost/greenportal/), tenant `gmodelo_dbp`
**Responsável pela execução:** QA (Claude, em nome de Paulo)
**Escopo:** verificação focada, solicitada após a correção do desenvolvedor para o bug "Fornecedor inválido." registrado no relatório original do PER-007 — não substitui nem revalida a totalidade do roteiro PER-007, já coberta pelo relatório anterior.

---

## 1. Objetivo

Confirmar, exclusivamente pelo fluxo normal da interface (menu → Gestão de Fornecedores → botão "Responsabilidades", sem uso de URL direta), que o acesso contextual à consulta e à manutenção de responsabilidades do fornecedor funciona corretamente após a correção aplicada pelo desenvolvedor, sem regressão.

## 2. Contexto

O relatório original do PER-007 identificou que o botão "Responsabilidades" da grid de Gestão de Fornecedores exibia "Fornecedor inválido." (HTTP 400) ao ser clicado, porque a tela de destino (`fornecedor_responsabilidades.php`) esperava o parâmetro simples `CODFORNECEDOR` na query string, enquanto o botão — usando o mecanismo genérico `data-context-child` — enviava o identificador embrulhado em `filtros=<JSON>`.

## 3. Confirmação por inspeção de código

Antes do teste em navegador, os arquivos `principal/fornecedor_responsabilidades.php` e `principal/fornecedor_responsabilidades_edicao.php` foram reobtidos do ambiente e inspecionados. Ambos agora leem o identificador do fornecedor com um fallback para o parâmetro `filtros`:

```php
$contextFilters = json_decode((string)($_GET['filtros'] ?? '{}'), true);
if (!is_array($contextFilters)) { $contextFilters = []; }
$id = filter_input(INPUT_GET, 'CODFORNECEDOR', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id && isset($contextFilters['CODFORNECEDOR'])) {
    $id = filter_var($contextFilters['CODFORNECEDOR'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
}
if (!$id) { http_response_code(400); exit('Fornecedor inválido.'); }
```

Ou seja: o parâmetro simples `CODFORNECEDOR` continua sendo aceito (compatibilidade com favoritos/integrações existentes) e, quando ausente, o valor é extraído do JSON `filtros` enviado pelo botão — exatamente a correção sugerida no relatório original.

## 4. Teste em navegador (fluxo real, sem URL direta)

Executado com a conta `ueditor` (perfil GESTOR), com a permissão HOMOLOGACAO_FORNECEDOR_FORNECEDOR (código 496) temporariamente ajustada para Visualizar=1, Incluir=1, Editar=1, Excluir=0, para permitir também a verificação da tela de manutenção (RT-002).

**Observação de metodologia:** a primeira tentativa de reteste, navegando diretamente para a URL do módulo (`principal/homologacaofornecedor_fornecedor.php`, fora do shell da aplicação), reproduziu um erro adicional — HTTP 404 por ausência do prefixo `/greenportal/` na URL montada pelo botão. Esse comportamento ocorre porque a variável `window.top.greenPortalBaseUrl`, usada pelo script `manutencao-legal-cadastro.js` para montar a URL de destino, só é definida pela página-shell (`principal/main.php`), não estando disponível quando o módulo é aberto isoladamente. Como o roteiro original (seção "Ambiente e usuário") especifica o fluxo pelo menu ("Homologação de Fornecedores > Gestão de Fornecedores"), o teste foi refeito a partir do shell da aplicação, que é como um usuário real sempre acessa a tela — nesse fluxo o erro de prefixo não ocorre. Este ponto está registrado na Seção 6 como observação incidental, por ser tecnicamente um comportamento frágil (dependência de contexto de execução), embora fora do caminho normal de uso.

| Passo | Ação | Resultado |
|---|---|---|
| 1 | Login como ueditor → menu lateral → Administração → Homologação de Fornecedores → Gestão de Fornecedores | Grid principal aberta normalmente, dentro do shell da aplicação |
| 2 | Selecionar a linha do fornecedor CODFORNECEDOR=6 ("Rio Limpo Manaus") | Linha destacada como selecionada; confirmado via DOM (`data-key="6"`) |
| 3 | Verificar estado do botão "Responsabilidades" (inspeção de DOM) | `disabled=false` — botão habilitado corretamente após a seleção |
| 4 (RT-001) | Ativar o botão "Responsabilidades" | Popup "Responsabilidades" aberto corretamente, sem HTTP 400 e sem "Fornecedor inválido.". Título "Responsabilidades do fornecedor", cabeçalho "Fornecedor 6 — Rio Limpo Manaus", grade carregada com os 2 vínculos legados (ATIVA) e os 2 vínculos de teste do PER-007 original (HISTORICA) |
| 5 (RT-002) | Clicar em "Manter" dentro do popup | Tela de manutenção "Manter responsabilidades" aberta sem "Fornecedor inválido.", mesmo fornecedor (Fornecedor 6 — Rio Limpo Manaus), botões "Adicionar" e "Salvar" presentes conforme as permissões do usuário (Incluir=1, Editar=1) |
| 6 (RT-003) | Acessar diretamente `fornecedor_responsabilidades.php?CODFORNECEDOR=6` e, em seguida, `fornecedor_responsabilidades_edicao.php?CODFORNECEDOR=6`, via URL completa com o prefixo correto | Ambas as telas abriram normalmente, confirmando que o parâmetro direto (`CODFORNECEDOR`) continua funcionando, sem quebrar integrações ou favoritos existentes |
| 7 (RT-004) | Voltar à grid, selecionar um segundo fornecedor (CODFORNECEDOR=11, "Cromos Cabreuva") e ativar "Responsabilidades" novamente | Popup abriu corretamente com o cabeçalho "Fornecedor 11 — Cromos Cabreuva" e sua própria listagem de responsabilidades (CROMOS.RNCF, CROMOS.DOCUMENTOS, user_10401) — distinta da consulta anterior, confirmando que o botão não fica preso ao primeiro fornecedor selecionado |
| 8 | Fechar o popup e conferir a grid | Grid permaneceu íntegra: 514 registros, Página 1, paginação e filtros operacionais |

**Resultado: PASSOU** (RT-001, RT-002, RT-003 e RT-004 todos aprovados)

## 5. Ausência de regressão

Nenhum vínculo foi incluído, editado ou excluído durante este reteste — apenas consultas e navegação foram realizadas, conforme instrução de limpeza do roteiro. Os vínculos legados dos dois fornecedores consultados (Rio Limpo Manaus e Cromos Cabreuva) permaneceram inalterados. O comportamento de habilitação do botão (desabilitado sem seleção, habilitado com seleção) e a independência entre seleções sucessivas de fornecedores diferentes foram confirmados sem indícios de regressão.

## 6. Observação incidental — dependência de `greenPortalBaseUrl`

Como registrado na Seção 4, o botão "Responsabilidades" (e os demais botões `data-context-child` da mesma grid, como Contatos e Auditoria) monta a URL de destino usando `window.top.greenPortalBaseUrl`. Essa variável só é populada pela página-shell da aplicação (`principal/main.php`); se um módulo como `homologacaofornecedor_fornecedor.php` for aberto fora do shell (por exemplo, por navegação direta ou por um link salvo apontando só para o módulo), os botões contextuais montam a URL sem o prefixo `/greenportal/`, resultando em HTTP 404. Isso não afeta o fluxo documentado no roteiro (menu → Gestão de Fornecedores), que sempre passa pelo shell, mas é uma fragilidade que pode surpreender em cenários de acesso não convencionais (ex.: um módulo reaberto em nova aba pelo próprio navegador após um F5, dependendo de como a aplicação trata esse caso). Registrada aqui apenas como observação técnica; não bloqueia a aprovação deste reteste, que segue estritamente o fluxo do roteiro.

## 7. Limpeza

Nenhum dado foi incluído, editado ou excluído durante este reteste. A permissão HOMOLOGACAO_FORNECEDOR_FORNECEDOR (código 496) do perfil GESTOR, elevada temporariamente para Incluir=1/Editar=1 a fim de viabilizar o RT-002, foi restaurada ao baseline vigente desde o encerramento do PER-007 original (Visualizar=1, Incluir=0, Editar=0, Excluir=0) — confirmado por inspeção de DOM após recarregamento completo da página de permissões. Exportar Excel e Gerar PDF permaneceram desmarcados durante e após o teste.

## 8. Conclusão

| Critério | Status |
|---|---|
| RT-001 — botão contextual abre a consulta sem "Fornecedor inválido." | **Aprovado** |
| RT-002 — manutenção recebe o mesmo contexto do fornecedor | **Aprovado** |
| RT-003 — parâmetro direto (`CODFORNECEDOR`) continua funcionando | **Aprovado** |
| RT-004 — ausência de regressão visual/funcional entre seleções | **Aprovado** |

### Resultado final: **PASSOU**

Com esta confirmação, o bug de interface registrado no relatório original (`relatorio_homologacao_PER007_responsabilidades_fornecedor_20260821.md`, Seção 5 — "Fornecedor inválido.") está sanado. O PER-007 é considerado corrigido, conforme o critério de aprovação do roteiro deste reteste. Este documento não substitui nem revalida a matriz completa de CRUD e permissões restritivas do roteiro PER-007 original, já coberta no relatório anterior — trata-se de uma verificação pontual do ponto de acesso à tela, conforme solicitado. Fica registrada, como melhoria futura não bloqueante, a observação da Seção 6 sobre a dependência de `greenPortalBaseUrl` fora do contexto do shell da aplicação.

**Responsável:** QA (Claude, em nome de Paulo)
**Data/hora:** 21/08/2026
**Evidências:** capturas de tela e verificações de DOM realizadas durante a execução (não anexadas neste documento)
