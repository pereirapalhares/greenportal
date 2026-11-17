-- Green Portal
-- Foreign keys validadas do módulo Homologação de Fornecedores.
--
-- Somente relacionamentos sem registros órfãos ou com tabelas filhas vazias
-- são criados nesta etapa. Relacionamentos com órfãos permanecem documentados
-- para saneamento posterior.

USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

ALTER TABLE `fo_avaliacao`
    ADD CONSTRAINT `fk_fo_avaliacao_fornecedor`
        FOREIGN KEY (`codfornecedor`) REFERENCES `fo_fornecedores` (`codfornecedor`),
    ADD CONSTRAINT `fk_fo_avaliacao_criterio`
        FOREIGN KEY (`codcriterioavaliacao`) REFERENCES `fo_criterioavaliacao` (`codcriterioavaliacao`);

ALTER TABLE `fo_certificados`
    ADD CONSTRAINT `fk_fo_certificados_fornecedor`
        FOREIGN KEY (`codfornecedor`) REFERENCES `fo_fornecedores` (`codfornecedor`);

ALTER TABLE `fo_equipamentos`
    ADD CONSTRAINT `fk_fo_equipamentos_fornecedor`
        FOREIGN KEY (`codfornecedor`) REFERENCES `fo_fornecedores` (`codfornecedor`);

ALTER TABLE `fo_fornecedoresxtipofornecedores`
    ADD CONSTRAINT `fk_fo_fornecedoresxtipo_tipo`
        FOREIGN KEY (`codtipofornecedor`) REFERENCES `fo_tipofornecedores` (`codtipofornecedor`);

ALTER TABLE `fo_fornecedorxcriterios`
    ADD CONSTRAINT `fk_fo_fornecedorcriterios_tipo`
        FOREIGN KEY (`codtipo`) REFERENCES `fo_tipofornecedores` (`codtipofornecedor`),
    ADD CONSTRAINT `fk_fo_fornecedorcriterios_criterio`
        FOREIGN KEY (`codcriterio`) REFERENCES `fo_criteriofornecedores` (`codcriteriofornecedor`);

ALTER TABLE `fo_funcionariosxcriterios`
    ADD CONSTRAINT `fk_fo_funcionarioscriterios_funcionario`
        FOREIGN KEY (`CODFUNCIONARIO`) REFERENCES `fo_funcionarios` (`CODFUNCIONARIO`),
    ADD CONSTRAINT `fk_fo_funcionarioscriterios_criterio`
        FOREIGN KEY (`CODCRITERIO`) REFERENCES `fo_criteriofuncionarios` (`codcriteriofuncionario`);

ALTER TABLE `fo_frequenciaavaliacao`
    ADD CONSTRAINT `fk_fo_frequencia_classificacao`
        FOREIGN KEY (`codclassificacao`) REFERENCES `fo_classificacao` (`codclassificacao`);

ALTER TABLE `fo_lote`
    ADD CONSTRAINT `fk_fo_lote_fornecedor`
        FOREIGN KEY (`codfornecedor`) REFERENCES `fo_fornecedores` (`codfornecedor`),
    ADD CONSTRAINT `fk_fo_lote_pedido`
        FOREIGN KEY (`codpedido`) REFERENCES `fo_pedidos` (`codpedido`);

ALTER TABLE `fo_pedidos`
    ADD CONSTRAINT `fk_fo_pedidos_fornecedor`
        FOREIGN KEY (`codfornecedor`) REFERENCES `fo_fornecedores` (`codfornecedor`);

ALTER TABLE `fo_produtoquimico`
    ADD CONSTRAINT `fk_fo_produtoquimico_fornecedor`
        FOREIGN KEY (`codfornecedor`) REFERENCES `fo_fornecedores` (`codfornecedor`);

ALTER TABLE `fo_tipofornecedoresxcriteriofornecedores`
    ADD CONSTRAINT `fk_fo_tipofornecedorcriterio_tipo`
        FOREIGN KEY (`codtipofornecedor`) REFERENCES `fo_tipofornecedores` (`codtipofornecedor`),
    ADD CONSTRAINT `fk_fo_tipofornecedorcriterio_criterio`
        FOREIGN KEY (`codcriteriofornecedor`) REFERENCES `fo_criteriofornecedores` (`codcriteriofornecedor`);

ALTER TABLE `fo_funcionariosxtipofuncionarios`
    ADD CONSTRAINT `fk_fo_funcionariotipo_funcionario`
        FOREIGN KEY (`codfuncionario`) REFERENCES `fo_funcionarios` (`CODFUNCIONARIO`),
    ADD CONSTRAINT `fk_fo_funcionariotipo_tipo`
        FOREIGN KEY (`codtipofuncionario`) REFERENCES `fo_tipofuncionarios` (`codtipofuncionario`);

ALTER TABLE `fo_fornecedorxtipofornecedoresxcriteriofornecedores`
    ADD CONSTRAINT `fk_fo_fornecedortipocriterio_fornecedor`
        FOREIGN KEY (`codfornecedor`) REFERENCES `fo_fornecedores` (`codfornecedor`),
    ADD CONSTRAINT `fk_fo_fornecedortipocriterio_tipo`
        FOREIGN KEY (`codtipofornecedor`) REFERENCES `fo_tipofornecedores` (`codtipofornecedor`),
    ADD CONSTRAINT `fk_fo_fornecedortipocriterio_criterio`
        FOREIGN KEY (`codcriteriofornecedor`) REFERENCES `fo_criteriofornecedores` (`codcriteriofornecedor`);

