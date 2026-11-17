# Ajuda interativa — guia do usuário como instrutor (18/10/2026, c447–c449 + migração 309)

Referência: docs/help.txt (ContentAI EngViewer). Decisões do usuário: evoluir o protótipo (guia_interativo_requisitos.php / guia-drawer.js, agora substituído); 1ª entrega = motor + Módulo Legal/Manutenção Legal; "Perguntar ao assistente" ligado à IA; conteúdo mantido em Administração › Guia do usuário.

## Situação (18/10, 18:08)
- Migração 309 aplicada: Guia do usuário versão 4 (com --publicar-pendente: publicou junto o rascunho de 16/10 — aba "Uso do portal", ranking de fornecedores, novos caminhos de menu) e versão 5 (--substituir, textos dos guias com os nomes novos do menu: Requisitos › Requisitos legais, Licenças › Licenças, Manutenção Legal › X Empresas).
- Falta testar no portal (sessão expirada no Chrome).
- Ferramenta: scripts/diagnostico-guia-rascunho.php (o que o rascunho tem de diferente da versão publicada).

## Como funciona
- Painel de Ajuda (assets/js/ajuda.js, só na janela principal): botão ? do cabeçalho, ? da barra de cada tela (data-open-transacao-ajuda / data-open-guide-requisitos são interceptados), F1 ou ?. Fecha com Esc/X. Fica fora da trava de foco das janelas Bootstrap.
  - Tela aberta = janela (popup) mais alta aberta, senão a aba ativa. Chaves da tela: body[data-transacao] (código do menu), [data-help-transaction] (transação do botão ?), arquivo:nome.php.
  - Seções: Guias passo a passo da tela (Começar/Continuar, Ver os passos; sem guia → guias do mesmo assunto), Ver todos os guias (central com busca por assunto), Sobre esta tela (o que é, o que pode fazer + "mostrar onde", antes, depois, dúvidas), Nesta tela agora (linhas do grid, linha selecionada, botões que dependem de seleção, busca e filtros em uso, janela aberta), Ajuda da transação (ajuda_transacoes), Palavras do assunto (termos do guia), "Esta ajuda foi útil?" (auditoria AJUDA_INTERATIVA), manual (guia_usuario.php) e assistente.
  - Mostrar ajuda como: perfis do guia (localStorage; ADMINISTRADOR começa em "adm").
- Modo guiado (cartão no canto): título, barra de progresso, Parte X de Y (quando os passos têm "quem"), Passo N de M com o nome do botão em **negrito**, "Você deve ver", Mostre-me (anel pulsando por cima de janelas e iframes, acompanha a rolagem; expande o menu lateral), Ir para a tela (abre o menu do passo), aviso quando a pessoa está em outra tela, Voltar/Próximo/Concluir, minimizar, X. Avança sozinho quando a pessoa clica no item certo ("Muito bem!"). Estado no navegador (gp.ajuda.guia): continua após trocar de tela ou recarregar. Ao concluir: "O que acontece depois" + útil?.
- Alvo do Mostre-me: menu:CODIGO · acao:new|save|delete|export|reload|columns · texto:Nome · campo:COLUNA · coluna:COLUNA · filtro:COLUNA · aba:Nome · linha · busca · css:seletor. Sem alvo, usa o último nome em **negrito** (procura texto, title, aria-label, tooltip).
- Assistente (AssistenteAplicacaoService): recebe a lista de guias que o usuário pode abrir e a orientação de responder como instrutor; devolve "guias" (até 2) e o assistente mostra o botão "Começar o guia: …".

## Conteúdo
- Guia do usuário (guniversal_dbp, sp_guia_usuario_*): cada aba (módulo) ganhou `guias` [{id, titulo, resumo, antes, depois, perfis[], telas[], passos[{quem, texto, ver, alvo, tela, auto}]}] e `telas` [{chaves[], oque, pode[{texto, alvo}], antes, depois, duvidas[{p, r}]}] (GuiaUsuarioService::normalizar/validarParaPublicacao).
- Editor (Administração › Guia do usuário): na árvore de cada aba, "Guias passo a passo (ajuda ?)" (guia → passos) e "Sobre esta tela (ajuda ?)"; campos com ajuda de preenchimento; PT/EN/ES.
- Os "Como fazer" existentes viram guias simples (sem Mostre-me) nas telas da transação.
- Carga inicial: principal/guia_interativo_conteudo.php — Módulo Legal: 10 guias / 57 passos (avaliar requisito, IA do requisito, ligar licença ao requisito, cadastrar licença, licença por PDF com IA, condicionantes, plano de ação com evidência e IA, minhas licenças, exportar Excel, X Empresas) e 4 telas (Requisitos legais, Licenças, Condicionantes, Plano de ação).
- Migração 309 (scripts/aplicar-migracao-309-ajuda-interativa.php [--simular] [--substituir] [--publicar-pendente]): junta a carga ao rascunho e publica nova versão; sem --substituir não sobrescreve guias já cadastrados; para se houver rascunho pendente. ATENÇÃO: depois que alguém editar os guias no editor, não rodar --substituir (perderia a edição).

## Arquivos
principal/api/ajuda.php (dados/transacao/feedback), assets/js/ajuda.js, principal/guia_interativo_conteudo.php, classes/GuiaUsuarioService.php, classes/AssistenteAplicacaoService.php, assets/js/assistente.js (v5), assets/js/administracao-guia-usuario.js (v4), principal/main.php (botão ? e script).

## Próximos passos
- Testar no portal (Requisitos legais, Licenças › Condicionantes › Plano de ação).
- Escrever os guias dos outros módulos (Homologação de Fornecedores, NC, Portaria...) no editor.
- Conferir os nomes exatos dos campos das janelas (Situação, associações) e ajustar os alvos onde o Mostre-me não achar.
