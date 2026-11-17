USE `guniversal_dbp`;
SET NAMES utf8mb4;

UPDATE `ajuda_transacoes`
SET `titulo_pt` = 'Ajuda - Correlacionamento Ocupacional',
    `texto_pt` = 'Consulte as legislações relacionadas a perigos, atividades, riscos, licenças e documentos ocupacionais. Selecione uma linha para habilitar os botões contextuais.'
WHERE `transacao` = 'modulolegal_correlacionamentoocupacional';

UPDATE `ajuda_transacoes`
SET `titulo_pt` = 'Ajuda - Correlacionamento Social',
    `texto_pt` = 'Consulte as legislações relacionadas a aspectos sociais, impactos sociais e documentos. Selecione uma linha para habilitar os botões contextuais.'
WHERE `transacao` = 'modulolegal_correlacionamentosocial';
