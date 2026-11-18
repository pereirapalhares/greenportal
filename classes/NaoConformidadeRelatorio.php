<?php
declare(strict_types=1);

require_once __DIR__ . '/NaoConformidadeWorkflow.php';
require_once __DIR__ . '/NaoConformidadeTextos.php';

/**
 * Relatório completo de uma não conformidade (PDF da ocorrência, 22/10/2026 — pedido do usuário: "o PDF tem que vir completo com
 * todas as informações de uma ocorrência"). Monta, por views, as mesmas informações das abas da tela
 * (principal/naoconformidades_ocorrencia.php), com os mesmos rótulos e as mesmas regras de quais campos o tipo de NC usa:
 *   Ocorrência (campos do tipo, com a descrição das listas), Avaliação do gestor, Análise da causa (causas), Plano de ação (todas as
 *   ações — também as sem causa, do Planejamento e da Assistência Técnica —, com reprogramação), Verificação da eficácia, Datas,
 *   Anexos (nomes dos arquivos no GED: ocorrência, evidências da análise e arquivos de cada ação), Histórico do fluxo e
 *   Histórico das ações.
 * Não grava nada. Quem chama já conferiu NaoConformidadeWorkflow::podeVer.
 */
final class NaoConformidadeRelatorio
{
    /** Rótulos do histórico do fluxo (os mesmos da aba Histórico). */
    public const ACOES_HISTORICO = ['CRIACAO' => 'Criação', 'ALTERACAO' => 'Ocorrência alterada', 'ENVIO_GESTOR' => 'Enviada ao gestor', 'REENVIO_GESTOR' => 'Reenviada ao gestor',
        'CORRECAO_EMITENTE' => 'Correção do emitente', 'APROVACAO_GESTOR' => 'Aprovada pelo gestor', 'RECUSA_GESTOR' => 'Recusada pelo gestor', 'ALTERACAO_GESTOR' => 'Avaliação do gestor alterada',
        'ENVIO_RESPONSAVEL' => 'Enviada ao responsável', 'IMPROCEDENTE' => 'Finalizada como improcedente', 'ALTERACAO_ANALISE' => 'Análise alterada',
        'CAUSA_INCLUIDA' => 'Causa incluída', 'CAUSA_ALTERADA' => 'Causa alterada', 'CAUSA_EXCLUIDA' => 'Causa excluída', 'PLANO_INCLUIDO' => 'Ação incluída',
        'PLANO_ALTERADO' => 'Ação alterada', 'PLANO_EXCLUIDO' => 'Ação excluída', 'ENVIO_EFICACIA' => 'Enviada para a avaliação da eficácia',
        'ALTERACAO_EFICACIA' => 'Verificação da eficácia alterada', 'FINALIZADA_EFICAZ' => 'Finalizada: eficaz', 'FINALIZADA_ENCERRADA' => 'Finalizada sem a verificação da eficácia',
        'NAO_EFICAZ' => 'Não eficaz: devolvida ao responsável', 'RECUSA_EFICACIA' => 'Recusada na eficácia: devolvida ao responsável', 'MUDANCA_ETAPA' => 'Mudança de etapa',
        'PLANO_DE_ACAO' => 'Passou para Plano de Ação', 'PLANO_STATUS' => 'Status da ação atualizado', 'FINALIZADA_SEM_EFICACIA' => 'Finalizada sem verificação da eficácia (plano concluído)',
        // Migração 334: ações da Administração de NC
        'CORRECAO_ADMIN' => 'Corrigida pela administração', 'ADMIN_REABRIR' => 'Reaberta pela administração', 'ADMIN_DEVOLVER' => 'Devolvida uma etapa pela administração',
        'ADMIN_ENCERRAR' => 'Encerrada administrativamente', 'TRANSFERENCIA_ADMIN' => 'Responsabilidade transferida pela administração', 'GESTOR_ATRIBUIDO_ADMIN' => 'Gestor atribuído pela administração',
        // Migração 336: análise da causa com IA
        'IA_ANALISE_APLICADA' => 'Sugestão da IA aplicada', 'IA_ANALISE_DESCARTADA' => 'Sugestão da IA descartada',
        'IA_EFICACIA_APLICADA' => 'Parecer da IA sobre a eficácia revisado', 'IA_EFICACIA_DESCARTADA' => 'Parecer da IA sobre a eficácia descartado'];

    /**
     * Campos da aba Ocorrência: campo => [rótulo, lista de vw_nc_opcoes | 'SIMNAO' | 'SIMNAO_LONGO' | 'PROCEDENCIA' | 'USUARIO' | 'DATAHORA' | null,
     * sempre (independe do tipo), campo do qual depende]. Mesma ordem e rótulos da tela.
     */
    private const CAMPOS_OCORRENCIA = [
        'CODUNIDADE' => ['Unidade', 'UNIDADE', true], 'LISTADEDISTRIBUICAO' => ['Lista de distribuição', 'LISTA', true],
        'CODUSUARIO_GESTOR' => ['Gestor', 'USUARIO', true], 'DATA_OCORRENCIA' => ['Data da ocorrência', 'DATAHORA', true],
        'CODSETOR' => ['Setor', 'SETOR', false], 'CODAREA' => ['Área', 'AREA', false], 'CODTIPOOCORRENCIA' => ['Classificação', 'CLASSIFICACAO', false],
        'PARADANALINHA' => ['Ocorreu parada de linha?', 'SIMNAO', false, 'CODTIPOOCORRENCIA'], 'CODTIPOACAO' => ['Tipo de ocorrência', 'TIPOACAO', false],
        'CODORIGEMACAO' => ['Origem da ocorrência', 'ORIGEM', false], 'CODSIGLAGESTAO' => ['Sistema de gestão', 'SISTEMA', false],
        'CODFORNECEDOR' => ['Fornecedor', 'FORNECEDOR', false], 'QTDENVOLVIDA' => ['Quantidade envolvida', null, false],
        'TAMANHOLOTEENVOLVIDO' => ['Lote envolvido', null, false], 'HOUVE_RETENCAO_PRODUTO' => ['Houve retenção de produto?', 'SIMNAO', false],
        'HAVERA_DEVOLUCAO_MATERIAL' => ['Haverá devolução de material?', 'SIMNAO', false], 'DSCCLIENTE' => ['Cliente', null, false],
        'CODUNIDADE_CLIENTE' => ['Unidade do cliente', 'UNIDADE_CLIENTE', false], 'CODIGODOPRODUTO' => ['Produto', 'PRODUTO', false],
        'CODTIPODEDOCUMENTO' => ['Tipo de documento', 'TIPODOC', false], 'NRNDOCUMENTO' => ['Nº do documento', null, false],
        'CODTIPODERECLAMACAO' => ['Tipo de reclamação', 'RECLAMACAO', false], 'CODTIPODETAMPA' => ['Tipo de tampa', 'TAMPA', false],
        'CODTAMANHODALATA' => ['Tamanho da lata', 'LATA', false], 'PROCEDENCIA_CLIENTE' => ['Procedência', 'PROCEDENCIA', false],
        'AFETASEGURANCAALIMENTOS' => ['Afeta a segurança de alimentos?', 'SIMNAO_LONGO', false], 'QTDDEVOLVIDA' => ['Quantidade devolvida', null, false],
        'PASS_PELA_NANALISE_EFICACIA' => ['Passar pela análise da eficácia?', 'SIMNAO', false],
        'IDENTIFICACAO' => ['Sumário da ocorrência', null, false], 'DESCRICAO' => ['Descrição da ocorrência', null, false], 'PROVIDENCIA' => ['Dados complementares', null, false],
    ];
    /** Descrição que vw_nc_ocorrencia já traz, usada quando o código não está na lista de opções (registro desativado ou só do legado). */
    private const DESCRICAO_DA_VIEW = ['CODUNIDADE' => 'DSCUNIDADE', 'LISTADEDISTRIBUICAO' => 'DSCLISTA', 'CODSETOR' => 'DSCSETOR', 'CODAREA' => 'DSCAREA',
        'CODTIPOACAO' => 'DSCTIPOACAO', 'CODORIGEMACAO' => 'DSCORIGEMACAO', 'CODSIGLAGESTAO' => 'DSCSIGLAGESTAO', 'CODUNIDADE_CLIENTE' => 'DSCUNIDADE_CLIENTE',
        'CODIGODOPRODUTO' => 'DSCPRODUTO', 'CODTIPODEDOCUMENTO' => 'DSCTIPODEDOCUMENTO', 'CODTIPODERECLAMACAO' => 'DSCTIPORECLAMACAO',
        'CODTIPODETAMPA' => 'DSCTIPODETAMPA', 'CODTAMANHODALATA' => 'DSCTAMANHODALATA'];
    /** Campos de texto longo (ocupam a linha inteira no PDF). */
    private const LONGOS = ['IDENTIFICACAO', 'DESCRICAO', 'PROVIDENCIA', 'ANALISE_DA_PROVIDENCIA', 'ANALISE_ABRANGENCIA', 'RECUSA_DO_GESTOR', 'ANALISE_DA_CAUSA', 'VERIFICACAO_DA_EFICACIA'];

    /**
     * Relatório: ['codigo', 'numero', 'tipo', 'tipo_codigo', 'etapa', 'situacao', 'pessoas' => [rótulo => nome],
     * 'secoes' => [['titulo', 'campos' => [[rótulo, valor, longo]]] | ['titulo', 'tabela' => ['colunas' => [...], 'linhas' => [[...]]], 'vazio' => texto]]].
     */
    public static function montar(PDO $pdo, int $codigo, array $env, array $session): array
    {
        $W = NaoConformidadeWorkflow::class;
        $R = array_change_key_case($W::ocorrencia($pdo, $codigo), CASE_UPPER);
        if (!$R) return [];
        $tipo = (int)($R['CODTIPONAOCONFORMIDADE'] ?? 0);
        $planejamento = in_array($tipo, [5, 6], true);
        $etapa = (string)($R['ETAPA'] ?? '');
        $op = $W::opcoes($pdo, true); // também os itens desativados (migração 332): o relatório mostra o que está gravado
        $campos = $W::campos($pdo, $tipo);
        $tem = static fn(string $c): bool => in_array(strtoupper($c), $campos, true);
        $v = static fn(string $c): string => trim((string)($R[strtoupper($c)] ?? ''));
        $causas = $W::listar($pdo, 'vw_nc_causas', $codigo, 'CODCAUSA');
        $planos = $W::listar($pdo, 'vw_nc_planodeacao', $codigo, 'CODPLANOACAO');
        $historico = $W::listar($pdo, 'vw_nc_historico', $codigo, 'CODHISTORICO');
        $histPlanos = $W::listar($pdo, 'vw_nc_planodeacao_historico', $codigo, 'CODPLANOACAOHISTORICO');
        $ids = array_filter([(int)($R['CODUSUARIO_GESTOR'] ?? 0), (int)($R['CODUSUARIO_RESPONSAVEL'] ?? 0), (int)($R['CODUSUARIO_PRAZO_ANALISE'] ?? 0), (int)($R['CODUSUARIO_EMISSOR'] ?? 0)]);
        $usuarios = $W::usuarios($pdo, $ids);

        $data = static function (string $x, bool $hora = false): string {
            $x = trim($x);
            if ($x === '' || str_starts_with($x, '0000')) return '';
            $t = strtotime($x);
            $f = IdiomaService::idioma() === 'en' ? 'm/d/Y' : 'd/m/Y';
            return $t ? date($hora && strlen($x) > 10 && substr($x, 11, 5) !== '00:00' ? $f . ' H:i' : $f, $t) : $x;
        };
        $simNao = static fn(string $x): string => match (strtoupper(mb_substr($x, 0, 1))) { 'S' => NaoConformidadeTextos::t('rel.sim'), 'N' => NaoConformidadeTextos::t('rel.nao'), default => NaoConformidadeTextos::valor($x) };
        $lista = static function (?string $fonte, string $campo, string $valor) use ($op, $usuarios, $tipo, $data, $simNao, $R): string {
            if ($valor === '' || ($valor === '0' && $fonte !== null)) return '';
            return match ($fonte) {
                null => $valor,
                'DATAHORA' => $data($valor, true),
                'SIMNAO', 'SIMNAO_LONGO' => $simNao($valor),
                'PROCEDENCIA' => $valor,
                'USUARIO' => $usuarios[$valor] ?? ('#' . $valor),
                'FORNECEDOR' => ($op['FORNECEDOR'][$valor] ?? '') ?: (trim((string)($R['RAZAOSOCIAL'] ?? '')) ?: '#' . $valor),
                'CLASSIFICACAO' => ($tipo === 1 ? ($op['CLASSIF_FORN'][$valor] ?? '') : ($op['CLASSIFICACAO'][$valor] ?? '')) ?: (trim((string)($R['DSCTIPOOCORRENCIA'] ?? '')) ?: '#' . $valor),
                default => ($op[$fonte][$valor] ?? '') ?: (trim((string)($R[self::DESCRICAO_DA_VIEW[$campo] ?? ''] ?? '')) ?: '#' . $valor),
            };
        };
        $c = static fn(string $rotulo, string $valor, bool $longo = false): array => [$rotulo, $valor, $longo];

        // Ocorrência (mesma regra da tela: campos "sempre" + os que o tipo usa)
        $ocorrencia = []; $mostrados = [];
        foreach (self::CAMPOS_OCORRENCIA as $nome => $def) {
            [$rotulo, $fonte, $sempre] = $def; $depende = $def[3] ?? null;
            if (!$sempre && !$tem($depende ?? $nome) && !($planejamento && $nome === 'PASS_PELA_NANALISE_EFICACIA')) continue;
            if ($tipo === 2 && $nome === 'PARADANALINHA') continue;
            $valor = $v($nome);
            if ($nome === 'DSCCLIENTE') $valor = trim($valor);
            $ocorrencia[] = $c(self::l($rotulo), $lista($fonte, $nome, $valor), in_array($nome, self::LONGOS, true));
            $mostrados[$nome] = true;
        }
        $secoes = [['titulo' => self::l('Ocorrência'), 'campos' => $ocorrencia]];

        // Avaliação do gestor (não existe no Planejamento e na Assistência Técnica, que vão direto ao plano de ação)
        if (!$planejamento) {
            $gestor = [$c(self::l('Ação imediata'), $v('ANALISE_DA_PROVIDENCIA'), true), $c(self::l('Análise da abrangência'), $v('ANALISE_ABRANGENCIA'), true),
                $c(self::l('Atualizar relação de riscos e oportunidades?'), $simNao($v('ATUALIZAR_RELACAO_DE_RISCOS_E_OPORTUNIDADES'))),
                $c(self::l('Abrir nova avaliação de mudança?'), $simNao($v('ABRIR_NOVA_AVALIACAO_DE_MUDANCA')))];
            if ($tem('IMPROCEDENTE') || $v('IMPROCEDENTE') !== '') $gestor[] = $c(self::l('Improcedente?'), $simNao($v('IMPROCEDENTE')));
            $gestor[] = $c(self::l('Responsável pela análise da causa raiz e ação corretiva'), $lista('USUARIO', '', $v('CODUSUARIO_RESPONSAVEL')) ?: $v('RESPONSAVEL'));
            $secoes[] = ['titulo' => self::l('Avaliação do gestor'), 'campos' => $gestor];
            $secoes[] = ['titulo' => self::l('Análise da causa'), 'campos' => [$c(self::l('Data prevista de conclusão'), $data($v('DATA_DE_CONCLUSAO_PREVISTA'))), $c(self::l('Análise da causa'), $v('ANALISE_DA_CAUSA'), true)]];
            $secoes[] = ['titulo' => self::l('Causas'), 'vazio' => self::l('Nenhuma causa cadastrada.'), 'tabela' => ['colunas' => [self::l('Tipo'), self::l('Causa'), self::l('Ações')], 'larguras' => [22, 70, 8],
                'linhas' => array_map(static fn($x) => [(string)($x['DSCTIPOCAUSA'] ?? ''), (string)($x['DSCAUSA'] ?? ''), (string)(int)($x['PLANOS'] ?? 0)], $causas)]];
        }

        // Plano de ação: todas as ações (com causa, sem causa, ligadas só pela causa no legado)
        $colunas = array_merge($planejamento ? [] : [self::l('Causa')], [self::l('Ação'), self::l('Prioridade'), self::l('Responsável'), self::l('Início'), self::l('Fim'), self::l('Status'), self::l('Recursos'), self::l('Observação'), self::l('Reprogramação')]);
        $linhas = [];
        foreach ($planos as $p) {
            $p = array_change_key_case($p, CASE_UPPER);
            $repro = trim($data((string)($p['DATA_REPROGRAMACAO'] ?? '')) . ' ' . trim((string)($p['MOTIVO_REPROGRAMACAO'] ?? '')));
            $linhas[] = array_merge($planejamento ? [] : [(string)($p['DSCAUSA'] ?? '')], [(string)($p['ACAO'] ?? ''), (string)($p['PRIORIDADE'] ?? ''),
                (string)($p['RESPONSAVEL'] ?? ''), $data((string)($p['DATAINICIAL'] ?? '')), $data((string)($p['DATAFINAL'] ?? '')),
                (string)(int)($p['STATUS'] ?? 0) . '%', (string)($p['RECURSOS'] ?? ''), (string)($p['OBS'] ?? ''), $repro]);
        }
        $larguras = array_merge($planejamento ? [] : [13], $planejamento ? [26, 7, 12, 8, 8, 6, 10, 13, 10] : [20, 7, 11, 8, 8, 6, 9, 10, 8]);
        $secoes[] = ['titulo' => self::l('Plano de ação'), 'vazio' => self::l('Nenhuma ação cadastrada.'), 'tabela' => ['colunas' => $colunas, 'linhas' => $linhas, 'larguras' => $larguras]];

        // Verificação da eficácia
        $eficacia = isset($mostrados['PASS_PELA_NANALISE_EFICACIA']) ? [] : [$c(self::l('Passa pela análise da eficácia?'), $simNao($v('PASS_PELA_NANALISE_EFICACIA')))];
        $eficacia = array_merge($eficacia, [$c(self::l('Verificação da eficácia'), $v('VERIFICACAO_DA_EFICACIA'), true),
            $c(self::l('Resultado'), $etapa === 'Finalizado' ? NaoConformidadeTextos::valor($v('SITUACAO')) : ''), $c(self::l('Data da análise da eficácia'), $data($v('PRAZO_ANALIZE'))),
            $c(self::l('Responsável por esta pendência'), $lista('USUARIO', '', $v('CODUSUARIO_PRAZO_ANALISE')) ?: $v('RESPONSAVEL_PRAZO_ANALISE'))]);
        $secoes[] = ['titulo' => self::l('Verificação da eficácia'), 'campos' => $eficacia];
        if ($v('RECUSA_DO_GESTOR') !== '') $secoes[] = ['titulo' => self::l('Recusa / devolução'), 'campos' => [$c(self::l('Último motivo de recusa ou devolução'), $v('RECUSA_DO_GESTOR'), true)]];

        // Datas do fluxo (as que existirem no banco)
        $datas = [];
        foreach (['DATA_DE_CRIACAO' => self::l('Criada em'), 'DATA_DE_CONHECIMENTO_FORNECEDOR' => self::l('Conhecimento do fornecedor'), 'DATA_DE_REPOSTA_DO_FORNECEDOR' => self::l('Resposta do fornecedor'),
            'DATA_DE_ENVIA_ANALISE_EFICACIA' => self::l('Enviada para a avaliação da eficácia'), 'DATA_DE_FINALIZACAO' => self::l('Finalizada em'), 'PRAZO_ANALISE' => self::l('Prazo de análise')] as $k => $rot)
            if (array_key_exists($k, $R) && ($d = $data((string)$R[$k], true)) !== '') $datas[] = $c($rot, $d);
        if ($datas) $secoes[] = ['titulo' => self::l('Datas'), 'campos' => $datas];

        // Anexos no GED (só os nomes; respeita a permissão de ver arquivos)
        $secoes[] = self::anexos($pdo, $env, $session, $codigo, $planos);

        // Histórico do fluxo (mesmos rótulos da aba Histórico; Planejamento e Assistência Técnica vão direto ao Plano de Ação)
        $rotulos = array_map(static fn(string $r): string => self::l($r), self::ACOES_HISTORICO);
        if ($tipo === 6) { $rotulos['ENVIO_GESTOR'] = self::l('Plano de ação enviado aos responsáveis'); $rotulos['REENVIO_GESTOR'] = self::l('Plano de ação reenviado'); }
        elseif ($tipo === 5) $rotulos['ENVIO_GESTOR'] = self::l('Enviada ao plano de ação do gestor');
        $secoes[] = ['titulo' => self::l('Histórico do fluxo'), 'vazio' => self::l('Sem registros no greenportal. As alterações feitas no sistema anterior não têm histórico.'), 'tabela' => ['colunas' => [self::l('Data'), self::l('Usuário'), self::l('Ação'), self::l('De'), self::l('Para'), self::l('Detalhe')], 'larguras' => [12, 14, 18, 14, 14, 28],
            'linhas' => array_map(static fn($h) => [$data((string)($h['CRIADO_EM'] ?? ''), true), (string)($h['USUARIO'] ?? ''),
                $rotulos[(string)($h['ACAO'] ?? '')] ?? ucfirst(strtolower(str_replace('_', ' ', (string)($h['ACAO'] ?? '')))),
                NaoConformidadeTextos::valor((string)($h['ETAPA_ANTERIOR'] ?? '')), NaoConformidadeTextos::valor((string)($h['ETAPA_NOVA'] ?? '')), (string)($h['MOTIVO'] ?? '')], $historico)]];
        if ($histPlanos) $secoes[] = ['titulo' => self::l('Histórico das ações'), 'tabela' => ['colunas' => [self::l('Data'), self::l('Ação'), self::l('Tipo'), self::l('Responsável'), self::l('Status'), self::l('Período'), self::l('Observação')], 'larguras' => [12, 24, 10, 13, 7, 14, 20],
            'linhas' => array_map(static function ($h) use ($data) { $h = array_change_key_case($h, CASE_UPPER); return [$data((string)($h['DATA_DO_UPDATE'] ?? ''), true), (string)($h['ACAO'] ?? ''),
                (string)($h['TIPO_DE_UPDATE'] ?? ''), (string)($h['RESPONSAVEL'] ?? ''), (string)($h['STATUS'] ?? '') !== '' ? (int)$h['STATUS'] . '%' : '', trim($data((string)($h['DATAINICIAL'] ?? '')) . ' – ' . $data((string)($h['DATAFINAL'] ?? '')), ' –'),
                (string)($h['OBS'] ?? '')]; }, $histPlanos)]];

        $tenant = (array)($session['tenant'] ?? []);
        $empresa = trim((string)($tenant[self::l('nome')] ?? $tenant['empresa_nome'] ?? '')) ?: (string)($tenant['banco_dados'] ?? '');
        return ['empresa' => $empresa, 'codigo' => $codigo, 'numero' => $v('NRANO') ?: (string)$codigo, 'tipo' => $v('DSCTIPONAOCONFORMIDADE'), 'tipo_codigo' => $tipo,
            'etapa' => NaoConformidadeTextos::valor($etapa), 'situacao' => NaoConformidadeTextos::valor($v('SITUACAO')),
            'pessoas' => [self::l('Emitente') => $v('EMISSOR'), self::l('Gestor') => $v('GESTOR'), self::l('Responsável') => $v('RESPONSAVEL')], 'secoes' => $secoes];
    }

    /** Rótulo em português traduzido pelas chaves de NaoConformidadeTextos (Onda 2 de idiomas). */
    private static function l(string $pt): string { return NaoConformidadeTextos::porTexto($pt); }

    /** Nomes dos anexos no GED (ocorrência, evidências da análise e arquivos de cada ação). */
    private static function anexos(PDO $pdo, array $env, array $session, int $codigo, array $planos): array
    {
        $secao = ['anexos' => true, 'titulo' => self::l('Anexos'), 'vazio' => self::l('Nenhum anexo.'), 'tabela' => ['colunas' => [self::l('Onde'), self::l('Arquivo'), self::l('Data'), self::l('Tamanho')], 'linhas' => [], 'larguras' => [28, 46, 15, 11]]];
        try {
            require_once __DIR__ . '/GedPermissaoTipo.php';
            require_once __DIR__ . '/GedStorageService.php';
            if (empty(GedPermissaoTipo::obter('ncocorrencia')['pode_visualizar'])) { $secao['vazio'] = self::l('Seu perfil não tem permissão para ver os anexos.'); return $secao; }
            $ged = new GedStorageService($pdo, $env, $session);
            $alvos = [[self::l('Ocorrência'), 'nc_indicacao_ocorrencia', $codigo], [self::l('Evidência da análise'), 'nc_analise_ocorrencia', $codigo]];
            foreach ($planos as $p) $alvos[] = [self::l('Ação: ') . mb_substr(trim((string)($p['ACAO'] ?? '')), 0, 60), 'nc_planodeacao', (int)($p['CODPLANOACAO'] ?? 0)];
            foreach ($alvos as [$onde, $pasta, $id]) {
                if ($id < 1) continue;
                foreach ($ged->list($pasta, $id, '') as $f) $secao['tabela']['linhas'][] = [$onde, (string)$f['name'],
                    date((IdiomaService::idioma() === 'en' ? 'm/d/Y' : 'd/m/Y') . ' H:i', strtotime((string)$f['created_at'])), self::tamanho((int)$f['size'])];
            }
        } catch (Throwable $e) {
            $secao['vazio'] = self::l('Não foi possível listar os anexos: ') . mb_substr($e->getMessage(), 0, 120); $secao['erro'] = true;
        }
        return $secao;
    }

    private static function tamanho(int $b): string
    {
        $en = IdiomaService::idioma() === 'en';
        return $b >= 1048576 ? number_format($b / 1048576, 1, $en ? '.' : ',', $en ? ',' : '.') . ' MB' : ($b >= 1024 ? number_format($b / 1024, 0, $en ? '.' : ',', $en ? ',' : '.') . ' KB' : $b . ' B');
    }
}
