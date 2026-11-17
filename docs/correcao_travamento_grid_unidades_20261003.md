# Correção do travamento na abertura do grid de Unidades

Data: 03/10/2026  
Módulo: Administração / Unidades e grids baseados em `ManutencaoLegalCadastroGrid`  
Ambiente: GreenPortal, tenant Modelo (`gmodelo_dbp`)

## Sintoma

Ao abrir a transação de Unidades, o navegador travava durante a montagem do grid. O bloqueio era completo: a tela deixava de responder e não era possível abrir as ferramentas de desenvolvedor com F12.

## Diagnóstico

O arquivo `assets/js/manutencao-legal-cadastro.js` mantinha um `MutationObserver` observando alterações de texto (`characterData`) em todo o documento. Cada alteração fazia o script percorrer novamente todo o `document.body` para corrigir acentuação.

Durante a criação do grid, diversos textos, filtros, linhas e componentes eram alterados. Isso provocava varreduras repetidas e acumuladas do documento, bloqueando o ciclo de execução do navegador.

Também foi confirmado que o carregamento do campo Unidade não era o gargalo do banco:

- `vw_unidades_grid`: 16 registros;
- unidades elegíveis para Manutenção Legal: 9;
- consulta das opções: aproximadamente 4 ms.

## Correção aplicada

Em `assets/js/manutencao-legal-cadastro.js`:

- removida a observação de `characterData`;
- removida a revarredura completa do `document.body` a cada mutação;
- a normalização passou a ocorrer somente nos nós adicionados;
- as alterações são agrupadas com `requestAnimationFrame`;
- cache da biblioteca atualizado para `manutencao-legal-cadastro.js?v=50`.

Separadamente, os campos relacionados dos grids passaram a carregar suas opções sob demanda, incluindo Unidade, sem alterar o processamento da IA.

## Validação

Validações técnicas executadas em 03/10/2026:

- PHP sem erros de sintaxe;
- JavaScript sem erros de sintaxe;
- grid de Licenças retornando HTTP 200;
- 25 linhas e 20 colunas carregadas;
- tempo de resposta do grid: 58 ms;
- contagem AJAX: 34 ms;
- ausência de erro PHP;
- ausência de combo pesado de Responsável;
- Unidade apresentada com filtro textual.

## Impacto na IA de Licenças

O processamento da IA não foi alterado. O campo `DADOS_DOCUMENTO_IA`, o prompt de extração e o serviço de IA permanecem inalterados. A correção foi exclusivamente no comportamento do observador de alterações do grid e no carregamento sob demanda dos relacionamentos.

## Evidência

Após atualização forçada do navegador (`Ctrl+F5`), a transação de Unidades voltou a responder normalmente, sem bloquear a tela ou as ferramentas de desenvolvedor.
