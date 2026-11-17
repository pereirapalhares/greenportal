<?php
declare(strict_types=1);

require_once __DIR__ . '/ManutencaoLegalCadastroGrid.php';
require_once __DIR__ . '/HomologacaoFornecedorAcesso.php';
require_once __DIR__ . '/TenantContext.php';

final class HomologacaoFornecedorFuncionarioRelacionamentoGrid
{
    public static function render(string $relation): void
    {
        $u = static fn(string $s): string => (string)json_decode('"' . $s . '"');
        $yesNo = ['Sim' => 'Sim', $u('N\u00e3o') => $u('N\u00e3o')];
        $responsibilityButton = '<button class="btn btn-sm btn-outline-secondary grid-crud-button" type="button" data-context-child data-context-id="responsabilidades" data-context-url="/principal/treinamento_responsabilidades.php" data-context-fields="CODIGO" data-context-title="Responsabilidades" disabled title="Responsabilidades" aria-label="Responsabilidades"><i class="bi bi-people"></i></button>';
        $base = ['menu' => 'HOMOLOGACAO_FORNECEDOR_FUNCIONARIO', 'database' => 'gmodelo_dbp', 'prefix' => 'GPT', 'context_fields' => ['CODFUNCIONARIO','CODTIPOFUNCIONARIO'], 'legacy_fixed' => true];
        $configs = [
            'criterios' => ['title' => 'Critérios do Funcionário', 'transaction' => 'homologacaofornecedor_funcionarioxcriterios', 'table' => 'fo_funcionarioxtiposxcriterios', 'view' => 'vw_homologacao_funcionario_criterios_grid', 'primary' => 'CODIGO', 'order' => ['CODIGO','CODFUNCIONARIO','CODTIPOFUNCIONARIO','DSCTIPO','DSCRITERIO','CODUSUARIO','ATENDEAOCRITERIO','RESPONSAVELDOCUMENTACAOFORNECEDOR','DATADEVALIDADE','DATADEINICIO','OBSERVACAO'], 'fields' => [
                'CODIGO' => ['label' => ['pt' => $u('C\u00f3digo'), 'en' => 'Code', 'es' => $u('C\u00f3digo')], 'hidden' => true, 'readonly' => true, 'filter' => 'none'], 'CODFUNCIONARIO' => ['label' => ['pt' => 'Funcionário', 'en' => 'Employee', 'es' => 'Empleado'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'], 'CODTIPOFUNCIONARIO' => ['label' => ['pt' => $u('Tipo de Funcion\u00e1rio'), 'en' => 'Employee Type', 'es' => 'Tipo de Empleado'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'], 'DSCTIPO' => ['label' => ['pt' => 'Tipo de Funcionário', 'en' => 'Employee Type', 'es' => 'Tipo de Empleado'], 'readonly' => true, 'filter' => 'select', 'width' => 180], 'DSCRITERIO' => ['label' => ['pt' => $u('Documenta\u00e7\u00e3o Exigida'), 'en' => 'Required Documentation', 'es' => 'Documentaci\u00f3n Requerida'], 'readonly' => true, 'filter' => 'text', 'width' => 320], 'CODUSUARIO' => ['label' => ['pt' => 'Responsável', 'en' => 'Responsible', 'es' => 'Responsable'], 'filter' => 'select', 'width' => 180, 'foreign' => ['view' => 'vw_usuarios_grid', 'value' => 'codusuario', 'label' => 'nome', 'filters' => ['ativo' => 1], 'description' => 'nome']], 'ATENDEAOCRITERIO' => ['label' => ['pt' => $u('Atende o crit\u00e9rio?'), 'en' => 'Meets the criterion?', 'es' => '¿Cumple el criterio?'], 'options' => $yesNo, 'filter' => 'select', 'width' => 150], 'RESPONSAVELDOCUMENTACAOFORNECEDOR' => ['label' => ['pt' => $u('Respons\u00e1vel do Fornecedor'), 'en' => 'Supplier Responsible', 'es' => 'Responsable del Proveedor'], 'filter' => 'text', 'width' => 180], 'DATADEVALIDADE' => ['label' => ['pt' => 'Data de Validade', 'en' => 'Validity Date', 'es' => 'Fecha de Validez'], 'filter' => 'text', 'width' => 130], 'DATADEINICIO' => ['label' => ['pt' => 'Data de Início', 'en' => 'Start Date', 'es' => 'Fecha de Inicio'], 'filter' => 'text', 'width' => 130], 'OBSERVACAO' => ['label' => ['pt' => 'Observação', 'en' => 'Notes', 'es' => 'Observación'], 'type' => 'textarea', 'filter' => 'text', 'width' => 280],]],
            'treinamentos' => ['title' => 'Treinamentos do Funcionário', 'transaction' => 'homologacaofornecedor_funcionarioxtreinamentos', 'table' => 'fo_funcionarioxtiposxtreinamentos', 'view' => 'vw_homologacao_funcionario_treinamentos_grid', 'primary' => 'CODIGO', 'extras' => $responsibilityButton, 'order' => ['CODIGO','CODFUNCIONARIO','CODTIPOFUNCIONARIO','DSCTIPO','DSCTIPOTREINAMENTO','CODUSUARIO','TREINAMENTOREALIZADO','RESPONSAVELTREINAMENTO','DATADEVALIDADETREINAMENTO','DATADE_REACAO'], 'fields' => [
                'CODIGO' => ['label' => ['pt' => $u('C\u00f3digo'), 'en' => 'Code', 'es' => $u('C\u00f3digo')], 'hidden' => true, 'readonly' => true, 'filter' => 'none'], 'CODFUNCIONARIO' => ['label' => ['pt' => 'Funcionário', 'en' => 'Employee', 'es' => 'Empleado'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'], 'CODTIPOFUNCIONARIO' => ['label' => ['pt' => $u('Tipo de Funcion\u00e1rio'), 'en' => 'Employee Type', 'es' => 'Tipo de Empleado'], 'hidden' => true, 'readonly' => true, 'filter' => 'none'], 'DSCTIPO' => ['label' => ['pt' => 'Tipo de Funcionário', 'en' => 'Employee Type', 'es' => 'Tipo de Empleado'], 'readonly' => true, 'filter' => 'select', 'width' => 180], 'DSCTIPOTREINAMENTO' => ['label' => ['pt' => 'Treinamento', 'en' => 'Training', 'es' => 'Capacitación'], 'readonly' => true, 'filter' => 'select', 'width' => 300], 'CODUSUARIO' => ['label' => ['pt' => 'Usuário Responsável', 'en' => 'Responsible User', 'es' => 'Usuario Responsable'], 'filter' => 'select', 'width' => 180, 'foreign' => ['view' => 'vw_usuarios_grid', 'value' => 'codusuario', 'label' => 'nome', 'filters' => ['ativo' => 1], 'description' => 'nome']], 'TREINAMENTOREALIZADO' => ['label' => ['pt' => $u('Treinamento Realizado?'), 'en' => 'Training Completed?', 'es' => '¿Capacitación Realizada?'], 'options' => $yesNo, 'filter' => 'select', 'width' => 160], 'RESPONSAVELTREINAMENTO' => ['label' => ['pt' => $u('E-mail do Responsável pelo Treinamento'), 'en' => 'Training Responsible Email', 'es' => 'Correo del Responsable'], 'filter' => 'text', 'width' => 240], 'DATADEVALIDADETREINAMENTO' => ['label' => ['pt' => 'Data de Validade', 'en' => 'Validity Date', 'es' => 'Fecha de Validez'], 'filter' => 'text', 'width' => 130], 'DATADE_REACAO' => ['label' => ['pt' => $u('Dias antes da Validade'), 'en' => 'Days Before Validity', 'es' => 'Días Antes de la Validez'], 'readonly' => true, 'filter' => 'text', 'width' => 140],]],
        ];
        if (!isset($configs[$relation])) { http_response_code(404); exit('Relacionamento nao encontrado.'); }
        // Migração 297: o fornecedor externo só abre critérios e treinamentos de funcionários dos fornecedores ligados a ele.
        // Migração 316: o interno restrito, só os de fornecedores das suas unidades (ou pelos quais responde).
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (!empty($_SESSION['autenticado']) && HomologacaoFornecedorAcesso::restrito()) {
            try { $acessoPdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION); }
            catch (Throwable) { http_response_code(401); exit('Sessão expirada.'); }
            HomologacaoFornecedorAcesso::exigirFuncionario($acessoPdo, HomologacaoFornecedorAcesso::idDaRequisicao('CODFUNCIONARIO'));
            $acessoPdo = null;
        }
        // DATADE_REACAO é apelido da view (datadereacaotreinamneto): só leitura; antes impedia gravar qualquer alteração do treinamento.
        // Migração 303 (como o legado ao abrir as abas Critério/Treinamento): cria os itens do tipo do funcionário que ainda faltam.
        if (!empty($_SESSION['autenticado']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $codFuncionario = (int)HomologacaoFornecedorAcesso::idDaRequisicao('CODFUNCIONARIO');
            if ($codFuncionario > 0) {
                try {
                    $geraPdo = TenantContext::connect(GreenPortalDatabase::env(), $_SESSION);
                    $st = $geraPdo->prepare('CALL sp_fo_funcionario_itens_gerar(?, 1)');
                    $st->execute([$codFuncionario]);
                    do { try { $st->fetchAll(); } catch (Throwable) { } } while ($st->nextRowset());
                    $st->closeCursor();
                } catch (Throwable) { /* sem a migração 303 a tela abre como antes */ }
                $geraPdo = null;
            }
        }
        $cfg = $configs[$relation];
        $cfg['title'] = ['pt' => $cfg['title'], 'en' => $cfg['title'], 'es' => $cfg['title']];
        $cfg['legacy_order'] = $cfg['order'];
        unset($cfg['order']);
        ManutencaoLegalCadastroGrid::render($base + $cfg);
    }
}
