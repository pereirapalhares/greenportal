# Padrão visual global

## Primeira etapa implementada

- `assets/css/design-system.css` passou a concentrar tokens de fonte, tamanhos, cores semânticas, foco, bordas, sombras e transições.
- Botões, formulários, tabelas, cards, alertas e modais receberam uma base visual comum.
- `assets/css/app.css` passou a consumir o design system.
- O login e o layout principal passaram a carregar a mesma camada visual.
- `components/pagina_padrao.php` passou a emitir primeiro o design system e depois os estilos específicos da página.
- `pagina-padrao.css` continua responsável apenas pela composição das páginas independentes e pelos temas.

## Próximas etapas

### Grupo Segurança, Administração e Configurações

- Segurança passou a receber o design system por meio de `app.css`.
- Unidades, Áreas e Atividades recebem o padrão pelo renderizador de grids.
- Textos e idiomas e Guia do usuário carregam diretamente o design system.
- Os tokens próprios desses dois módulos administrativos agora apontam para `--gp-*`.
- O layout principal foi ajustado para carregar o design system depois dos estilos legados, garantindo que os componentes comuns prevaleçam.
- Foram geradas evidências visuais em `docs/evidencias_css_grupo/`.

### Correção de tema do login

O login utilizava variáveis legadas `--green-*` e um tema separado. Ele agora recebe também a classe `portal-shell theme-*` e usa os mesmos tokens `--gp-*` do `main.php`. Como a preferência individual só existe depois da autenticação, o login usa o tema-base `verde`; o `main.php` aplica a preferência do usuário após entrar no portal. O `design-system.css` é carregado por último no login para prevalecer sobre os estilos antigos.

As telas legadas restantes deverão ser migradas por grupos: Módulo Legal, Homologação, Não Conformidades e popups/iframes. Os estilos próprios desses grupos serão mantidos somente quando forem específicos da funcionalidade e deverão usar as variáveis `--gp-*`.
### Grupo Módulo Legal/Gestão Ambiental

- `assets/css/modulo-legal.css` centraliza a camada visual dos grids, filtros de requisitos, grupos de operações e popups legais.
- `ManutencaoLegalCadastroGrid` e `ModuloLegalRequisitosGrid` carregam `design-system.css` e `modulo-legal.css` depois dos estilos legados.
- `pagina_padrao.php` também carrega a camada do Módulo Legal para telas auxiliares, mantendo fontes, tabelas, cards, alertas e modais nos mesmos tokens.
- A tela de Legislação foi ajustada para carregar explicitamente as duas camadas globais.
- Evidências visuais do Firefox: `docs/evidencias_modulo_legal/`.
- Teste visual pelo geckodriver: login Atlas, Legislação, Licenças e Requisitos legais; todos os checks aprovados.
### Grupo Homologação de Fornecedores

- `assets/css/homologacao-fornecedores.css` centraliza os tokens visuais dos grids de fornecedores, funcionários, tipos, critérios, treinamentos, vínculos e responsabilidades.
- Os grids legados passaram a carregar essa camada depois de `design-system.css`.
- As telas independentes de responsabilidades e vínculos recebem a mesma camada por `pagina_padrao.php`.
- Teste visual pelo geckodriver: fornecedores, funcionários, tipos de funcionários, critérios e tipos de treinamentos; todos os checks aprovados.
- Evidências visuais: `docs/evidencias_homologacao/`.
### Grupo Não Conformidades

- `assets/css/nao-conformidades.css` centraliza a camada visual da lista geral, minhas ocorrências, cadastro/edição, causas, planos de ação, avaliação de eficácia, aprovação e workflow.
- `components/pagina_padrao.php` carrega a camada para telas independentes; `ManutencaoLegalCadastroGrid` carrega a camada para os grids do módulo.
- A nova camada usa exclusivamente tokens `--gp-*` e não introduz cores hexadecimais fixas.
- A diagramação dos templates PDF permanece isolada, pois é CSS específico de impressão.
- Validação estática concluída: PHP sem erros de sintaxe nas telas do módulo e teste JavaScript preparado em `scripts/teste-css-nao-conformidades-gecko-temp.js`.
- O teste visual Firefox ficou pendente de reinicialização do GeckoDriver porque a primeira sessão de teste foi encerrada pelo ambiente como sessão órfã.
### Dimensionamento dos popups

- `assets/js/popups.js` passou a calcular largura e altura conforme o conteúdo e os limites do viewport.
- Iframes são medidos novamente após o carregamento para ajustar o popup sem exigir redimensionamento manual.
- Popups abertos sobre outro popup recebem limite máximo de 90% das dimensões do popup pai.
- `assets/css/popups.css` centraliza rolagem interna, limites responsivos, estados minimizado/maximizado e redimensionamento.
