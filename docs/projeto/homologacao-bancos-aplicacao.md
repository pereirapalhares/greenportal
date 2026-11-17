# Homologação de Fornecedores — aplicação nos bancos de cliente (17–18/10/2026)

## Bancos (decisão do usuário)
gmodelo_dbp, gatlas_dbp, gmodelo2_dbp, gcrown_dbp, ggdamericana_dbp, gganchieta_dbp.
Fora: gluxottica_dbp e as empresas sem banco novo (cerca de 40, ainda não migradas). Lista e banco legado de cada um em classes/HomologacaoBancos.php.

## Como aplicar
- Cadeia 216 → 303 (sem a 217, saneamento específico de Atlas/MODELO): scripts/aplicar-homologacao-bancos.php --banco=... [--simular] [--de=N] [--ate=N]; para na primeira falha.
- Cada migração aceita --banco= (vários, por vírgula). As que chamam outra migração repassam os seus bancos: 260/261/262 → 247 (c419), 292 → 248 (c420).
- Bancos novos: 301 instala as regras automáticas DESLIGADAS (ligar com aplicar-migracao-301 --banco=X --ligar-regras depois de ver o worker --simular --banco=X); 302 não converte (só com --converter).
- Serviço de documentos usa a pasta do GED do legado de cada banco (ex.: green_gd_americana, green_goodyear_anchieta).
- Diagnóstico somente leitura: scripts/diagnostico-homologacao-bancos.php --homologacao [--detalhe] — resumo por banco, objetos que faltam, definições diferentes da MODELO (e o que difere) e regras ligadas.

## Situação (diagnóstico 18/10 09:30) — os 6 bancos com a cadeia completa
- MODELO (referência), MODELO2 em dia; regras 9/9 ligadas em MODELO, Atlas, MODELO2.
- Atlas: 2 diferenças esperadas (tela e validação de exclusão dos tipos de fornecedor conferem também avaliacao_documental, avaliacao_sistemica, gruposxtipofornecedor_avaliacao_sistemica, que só a Atlas tem).
- Crown, Goodyear Americana, Goodyear Anchieta: só o gatilho trg_fo_tipotreinamentos_antes_excluir difere (BEGIN…END em volta do mesmo comando — mesmo efeito). Regras 0/9 (desligadas).
- Crown: estrutura aplicada; cadastros (188 critérios e 55 tipos de fornecedor, 28 critérios de funcionário, 14 tipos de treinamento, 128 tipos de funcionário) vieram do green_crown. Continua com 0 fornecedores e 0 funcionários (legado: 541 e 1.110) e 6.469 documentos — carga pendente de decisão.

## Correção de acentos na view de fornecedores (18/10)
Na MODELO, Atlas e MODELO2 a view base da Gestão de Fornecedores (216, renomeada pela 289 para vw_homologacao_fornecedores_grid_base) tinha os acentos gravados como "?" ('não', 'satisfatório'), da primeira aplicação. Reaplicadas 216 e 289 nos três bancos; o diagnóstico ficou igual aos bancos novos.

## Incidentes (corrigidos)
1. A 260 chamou a 247 em todos os bancos e liberou o menu Portaria › Documentação de Fornecedor (codmenu 120) para PORTARIA e ADM_DA_EMPRESA na gluxottica_dbp; desfeito com 247 --remover --banco=gluxottica_dbp (ações zeradas). Na gcrown_dbp o ADM_DA_EMPRESA ganhou o vínculo perfil_menus (permissões já existiam) — mantido.
2. A 292 chamou a 248 sem --banco, então os cadastros de critérios e tipos de fornecedor (estrutura + cópia do legado, só inclusão) rodaram nos 6 bancos: na Crown entraram 188 critérios e 55 tipos vindos do green_crown (comparação final igual ao legado); MODELO/Atlas/MODELO2 só reaplicaram a estrutura (dados já iguais). Luxottica não foi tocada. Corrigido em c420.
