<?php
declare(strict_types=1);

require_once __DIR__ . '/HomologacaoRelatorios.php';

/**
 * Planilha dos Relatórios de Homologação (transação homologacaofornecedores_relatorios, migração 314).
 * Executada SOMENTE pelo GreenPortal-ExportWorker (Agendador do Windows) via classes/ExportacaoGridWorker.php.
 * O pedido (exportacoes_grid.filtros) traz o pedido da tela (relatório, filtros, filtro rápido, filtros por coluna, busca,
 * agrupamento, ordem), o idioma, se o solicitante é fornecedor externo e se é a "Lista para a portaria" do unificado.
 * Linhas e regras: classes/HomologacaoRelatorios.php (as mesmas da tela), com o código do solicitante (mesmo escopo).
 */
final class HomologacaoRelatoriosExportacao
{
    /** @return list<array<string,mixed>> linhas já rotuladas para createProfessionalXlsx */
    public static function linhas(PDO $pdo, array $job): array
    {
        $dados = json_decode((string)($job['filtros'] ?? '{}'), true);
        $dados = is_array($dados) ? $dados : [];
        $externo = (int)($dados['externo'] ?? 0) === 1;
        // Migração 316: modo de acesso do solicitante (0 tudo, 1 externo, 2 interno restrito por unidade); pedidos antigos só têm "externo".
        $modo = isset($dados['modo']) ? HomologacaoRelatorios::modoAcesso((int)$dados['modo']) : ($externo ? 1 : 0);
        $idioma = in_array((string)($dados['idioma'] ?? 'pt'), ['pt', 'en', 'es'], true) ? (string)$dados['idioma'] : 'pt';
        $usuario = (int)($job['codusuario'] ?? 0);
        if ($usuario <= 0) throw new RuntimeException('Pedido sem usuário.');
        $R = new HomologacaoRelatorios($idioma);
        $pedido = HomologacaoRelatorios::normalizarPedido(is_array($dados['pedido'] ?? null) ? $dados['pedido'] : [], $externo);
        $res = $R->executar($pdo, $usuario, $modo, $pedido);

        if ($res['unificado']) {
            $portaria = (int)($dados['portaria'] ?? 0) === 1;
            $saida = [];
            foreach ($res['linhas'] as $l) {
                if ($portaria) {
                    if ($l['situacao'] !== 'lib') continue;
                    $saida[] = ['Unidade' => $l['unidade'], 'Fornecedor' => $l['fornecedor'], 'CNPJ/CPF' => $l['cnpj'], 'Funcionário' => $l['nome'], 'Nº do registro' => $l['nrregistro'],
                        'Tipo de funcionário' => $l['tipofunc'], 'Setor' => $l['setor'], 'Integração válida até' => $l['integracao'], 'Itens em dia' => $l['emdia']];
                    continue;
                }
                $linha = ['Unidade' => $l['unidade'], 'Fornecedor' => $l['fornecedor'], 'Funcionário' => $l['nome'], 'Nº do registro' => $l['nrregistro'], 'Setor' => $l['setor'],
                    'Tipo de funcionário' => $l['tipofunc'], 'Data de início' => $l['datainicio'], 'Situação' => $l['situacao_rotulo'], 'Em dia' => $l['emdia']];
                foreach ($res['colunas'] as $i => $c) {
                    $cel = $l['celulas'][$i] ?? ['s' => 'X', 't' => '—'];
                    $rot = $c['grupo'] . ': ' . $c['nome'];
                    while (isset($linha[$rot])) $rot .= ' ';
                    $linha[$rot] = $cel['s'] === 'X' || $cel['s'] === 'N' ? '—' : $cel['t'];
                }
                $linha['Pendências'] = $l['pendencias'];
                $saida[] = $linha;
            }
            return $saida ?: [['Informação' => $portaria ? 'Nenhum funcionário liberado para os filtros escolhidos.' : 'Nenhum funcionário encontrado para os filtros escolhidos.']];
        }

        $def = HomologacaoRelatorios::relatorios()[$pedido['relatorio']];
        $rot = HomologacaoRelatorios::rotulosColunas();
        $linhas = $res['linhas'];
        $temEvidencia = in_array('evidencia', HomologacaoRelatorios::chavesColunas($pedido['relatorio'], true), true);
        if ($temEvidencia) {
            try {
                require_once __DIR__ . '/GedStorageService.php';
                require_once __DIR__ . '/GreenPortalDatabase.php';
                $banco = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
                HomologacaoRelatorios::contarEvidencias($linhas, new GedStorageService($pdo, GreenPortalDatabase::env(), ['tenant' => ['banco_dados' => $banco]]));
            } catch (Throwable) { /* sem GED, a coluna fica vazia */ }
        }
        $cores = ['VERMELHO' => 'Vermelho', 'AMARELO' => 'Amarelo', 'VERDE' => 'Verde', 'NEUTRO' => 'Cinza', 'BRANCO' => 'Branco'];
        $saida = [];
        foreach ($linhas as $l) {
            $linha = [];
            if ($res['agrupar'] !== '') $linha['Grupo'] = (string)$l[$res['agrupar']];
            $linha['Cor'] = $cores[$l['cor']] ?? $l['cor'];
            foreach ($def['colunas'] as [$k, $r]) {
                $titulo = $R->L($rot[$r] ?? ['pt' => $r]);
                $linha[$titulo] = $k === 'evidencia' ? ($temEvidencia ? (int)$l['evidencia'] : '') : (string)$l[$k];
                if ($k === 'fornecedor' && !in_array('cnpj', array_column($def['colunas'], 0), true)) $linha['CNPJ/CPF'] = (string)$l['cnpj'];
                // Migração 318: documentos do fornecedor com as colunas do Excel "Fornecedores + Critérios" do legado.
                if ($k === 'fornecedor' && $def['categoria'] === 'DOCUMENTO') { $linha['Nome fantasia'] = (string)($l['_fantasia'] ?? ''); $linha['Responsável pelo fornecedor (cadastro)'] = (string)($l['_resp_cadastro'] ?? ''); }
            }
            if ($def['categoria'] === 'DOCUMENTO') {
                $linha['Responsável pelo critério'] = (string)($l['_resp_empresa'] ?? '');
                $linha['Responsável do fornecedor'] = (string)($l['_resp_fornecedor'] ?? '');
                $linha['Observação da empresa'] = (string)($l['_obs_empresa'] ?? '');
                $linha['Observação do fornecedor'] = (string)($l['_obs_fornecedor'] ?? '');
                $linha['Data de cobrança do protocolo'] = (string)($l['_cobranca'] ?? '');
            }
            $saida[] = $linha;
        }
        return $saida ?: [['Informação' => 'Nenhum item encontrado para o relatório e os filtros escolhidos.']];
    }
}
