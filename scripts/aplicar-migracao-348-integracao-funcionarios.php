<?php
declare(strict_types=1);

/*
 * Migração 348 — Integração de funcionários: questionário, avaliação e liberação (Onda 2 da apresentação Atlas Schindler 2026).
 *
 * Universal:
 *   - menu Homologação de Fornecedores › Integração de funcionários (HOMOLOGACAO_INTEGRACAO_FUNCIONARIOS, ao lado da Gestão de
 *     Funcionários, replicado nas empresas);
 *   - questionário modelo INTEGRACAO_FUNCIONARIO_PADRAO em RASCUNHO (meio ambiente, segurança ocupacional e regras da unidade; nota
 *     mínima 70%), só se ainda não houver questionário de integração: revise e publique em Questionários de Integração de Funcionários;
 *   - Ajuda (marcador ml348).
 * Cada empresa da homologação (classes/HomologacaoBancos.php):
 *   1. database/348_integracao_funcionarios.sql — configuração (tipo → questionário), integrações, views e procedures;
 *   2. permissões do menu novo = as da Gestão de Funcionários (HOMOLOGACAO_FORNECEDOR_FUNCIONARIO);
 *   3. registros_migracao (INTEGRACAO_FUNCIONARIOS_348).
 * Página do funcionário (sem senha): integracao.php na raiz do portal. Para o link sair com o endereço externo do portal, configure
 * GREENPORTAL_URL no .env (sem ele, o link usa o endereço por onde o usuário entrou).
 *
 * --criar-modelo (25/10/2026): cria o questionário modelo INTEGRACAO_FUNCIONARIO_PADRAO mesmo que já exista outro questionário de
 *   integração (ex.: um checklist de quem integra), se ainda não houver um com esse código.
 * Uso: E:\wamp\bin\php\php8.4.15\php.exe scripts\aplicar-migracao-348-integracao-funcionarios.php [--simular] [--banco=gatlas_dbp] [--criar-modelo]
 * Pode ser executada mais de uma vez.
 */

require_once dirname(__DIR__) . '/classes/GreenPortalDatabase.php';
require_once dirname(__DIR__) . '/classes/MigracaoCrud.php';
require_once dirname(__DIR__) . '/classes/MenuCatalogoService.php';
require_once dirname(__DIR__) . '/classes/HomologacaoBancos.php';
require_once dirname(__DIR__) . '/classes/IntegracaoFuncionario.php';

$args = $argv ?? [];
$simular = in_array('--simular', $args, true);
$criarModelo = in_array('--criar-modelo', $args, true);
$env = GreenPortalDatabase::env();
$bancoUniversal = preg_replace('/[^A-Za-z0-9_]/', '', (string)($env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp')) ?: 'guniversal_dbp';
$rel = ['ok' => true, 'migracao' => '348', 'modo' => $simular ? 'SIMULACAO' : 'APLICAR', 'universal' => [], 'bancos' => []];
$rel['universal']['endereco_do_link'] = preg_match('#^https?://#i', trim((string)($env['GREENPORTAL_URL'] ?? '')))
    ? rtrim(trim((string)$env['GREENPORTAL_URL']), '/') . '/integracao.php'
    : 'GREENPORTAL_URL não configurado no .env: o link usa o endereço por onde o usuário entrou no portal';

// ---------------------------------------------------------------- universal
$cat = new MenuCatalogoService($env);
try {
    $u = $cat->universal();
    $menu = IntegracaoFuncionario::MENU;
    $ref = MigracaoCrud::primeiro($u, 'menus', ['codigo' => 'HOMOLOGACAO_FORNECEDOR_FUNCIONARIO']);
    $existe = MigracaoCrud::primeiro($u, 'menus', ['codigo' => $menu]);
    if ($existe) { if (!$simular) $cat->replicar($menu, null, false); $rel['universal']['menu'] = 'já existia (codmenu ' . $existe['codmenu'] . ')'; }
    elseif (!$ref) { $rel['universal']['menu'] = 'ERRO: menu HOMOLOGACAO_FORNECEDOR_FUNCIONARIO não encontrado no universal'; $rel['ok'] = false; }
    elseif ($simular) $rel['universal']['menu'] = 'será criado ao lado da Gestão de Funcionários: Integração de funcionários';
    else {
        $ref = array_change_key_case($ref);
        $g = $cat->gravar(['codmenu_pai' => $ref['codmenu_pai'] !== null ? (int)$ref['codmenu_pai'] : null, 'modulo' => $ref['modulo'] ?? null, 'codigo' => $menu,
            'label' => 'Integração de funcionários', 'label_pt' => 'Integração de funcionários', 'label_en' => 'Employee induction', 'label_es' => 'Integración de empleados',
            'url' => IntegracaoFuncionario::TELA, 'nivel' => (int)($ref['nivel'] ?? 1), 'ordem' => (int)($ref['ordem'] ?? 0) + 1, 'icone' => 'bi-person-check', 'ativo' => 1]);
        $rel['universal']['menu'] = 'criado (codmenu ' . $g['codmenu'] . ')';
    }
    // Questionário modelo (rascunho), só se não houver questionário de integração
    $s = $u->query("SELECT COUNT(*) FROM amb_questionario WHERE UPPER(COALESCE(modulo, '')) IN ('EMPLOYEE_INTEGRATION', 'EMPLOYEE', 'GREEN_EMPLOYEE')");
    $qtd = (int)$s->fetchColumn(); $s->closeCursor();
    $s = $u->query("SELECT COUNT(DISTINCT codigo) FROM vw_amb_questionario_catalogo WHERE ativo = 1 AND codpublicacao IS NOT NULL AND UPPER(COALESCE(modulo, '')) IN ('EMPLOYEE_INTEGRATION', 'EMPLOYEE', 'GREEN_EMPLOYEE')");
    $rel['universal']['questionarios_publicados'] = (int)$s->fetchColumn(); $s->closeCursor();
    $s = $u->query("SELECT COUNT(*) FROM amb_questionario WHERE codigo = 'INTEGRACAO_FUNCIONARIO_PADRAO'");
    $temModelo = (int)$s->fetchColumn() > 0; $s->closeCursor();
    if ($temModelo) $rel['universal']['questionario_modelo'] = 'já existe: INTEGRACAO_FUNCIONARIO_PADRAO';
    elseif ($qtd > 0 && !$criarModelo) $rel['universal']['questionario_modelo'] = "não criado: já existem $qtd questionário(s) de integração (use --criar-modelo para criar o modelo do funcionário)";
    elseif ($simular) $rel['universal']['questionario_modelo'] = 'será criado em rascunho: INTEGRACAO_FUNCIONARIO_PADRAO';
    else { criarModelo348($u); $rel['universal']['questionario_modelo'] = 'criado em RASCUNHO: INTEGRACAO_FUNCIONARIO_PADRAO (revise e publique em Questionários de Integração de Funcionários)'; }
    $rel['universal']['ajuda'] = gravarAjuda348($u, require dirname(__DIR__) . '/database/348_ajuda_integracao_funcionarios.php', $simular);
} catch (Throwable $e) { $rel['ok'] = false; $rel['universal']['erro'] = $e->getMessage(); }

// ---------------------------------------------------------------- empresas da homologação
$sql = strtr((string)file_get_contents(dirname(__DIR__) . '/database/348_integracao_funcionarios.sql'), ['{{UNIVERSAL}}' => $bancoUniversal]);
$sql = (string)preg_replace('/^--(?! @@).*$/m', '', $sql);
$blocos = array_values(array_filter(array_map('trim', preg_split('/^-- @@\s*$/m', $sql)), static fn($b) => $b !== ''));
foreach (HomologacaoBancos::lista($args) as $banco) {
    $info = [];
    try {
        $pdo = GreenPortalDatabase::connect($env, $banco, 'GPT');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $um = static function (string $q, array $p = []) use ($pdo): mixed { $s = $pdo->prepare($q); $s->execute($p); $v = $s->fetchColumn(); $s->closeCursor(); return $v; };
        $faltam = [];
        foreach (['fo_funcionarios', 'fo_tipofuncionarios', 'fo_fornecedores', 'unidades', 'vw_homologacao_funcionarios_grid', 'amb_questionario_execucoes', 'amb_questionario_respostas', 'amb_questionario_resultados', 'alertas'] as $t)
            if (!(int)$um('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t])) $faltam[] = $t;
        foreach (['sp_alerta_criar', 'sp_crud_select'] as $r) if (!(int)$um('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = ?', [$r])) $faltam[] = $r . '()';
        if (!(int)$um("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fo_fornecedores' AND COLUMN_NAME = 'CODUSUARIO_RESPONSAVEL_INTEGRACAO'")) $faltam[] = 'fo_fornecedores.CODUSUARIO_RESPONSAVEL_INTEGRACAO';
        if ($faltam) throw new RuntimeException('faltam objetos das migrações anteriores: ' . implode(', ', $faltam));
        $info['funcionarios'] = (int)$um('SELECT COUNT(*) FROM vw_homologacao_funcionarios_grid');
        $info['tipos_de_funcionario'] = (int)$um('SELECT COUNT(*) FROM fo_tipofuncionarios');
        // Migração 350: validade = data de realização + meses (12 sem configuração)
        $info['integracao_em_dia'] = (int)$um('SELECT COUNT(*) FROM vw_homologacao_funcionarios_grid WHERE DATA_VALIDADE >= CURDATE() - INTERVAL 12 MONTH');
        if ($simular) { $info['estrutura'] = (int)$um("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fo_integracoes'") ? 'será regravada (dados mantidos)' : 'será criada'; $rel['bancos'][$banco] = $info; continue; }
        foreach ($blocos as $b) $pdo->exec($b);
        $info['estrutura'] = count($blocos) . ' blocos gravados';
        $info['questionarios_publicados'] = (int)$um('SELECT COUNT(*) FROM vw_fo_integracao_questionarios');
        // Permissão do menu novo = a da Gestão de Funcionários
        $menuNovo = MigracaoCrud::primeiro($pdo, 'menus', ['codigo' => IntegracaoFuncionario::MENU]);
        $menuRef = MigracaoCrud::primeiro($pdo, 'menus', ['codigo' => 'HOMOLOGACAO_FORNECEDOR_FUNCIONARIO']);
        if (!$menuNovo || !$menuRef) $info['permissao'] = 'menu ainda não replicado neste banco';
        else {
            $cont = 0;
            foreach (['permissoes', 'perfil_menus'] as $t) {
                if (!MigracaoCrud::existe($pdo, $t)) continue;
                $ct = MigracaoCrud::colunas($pdo, $t);
                if (!in_array('codmenu', $ct, true) || !in_array('codperfil', $ct, true)) continue;
                foreach (MigracaoCrud::selecionar($pdo, $t, ['codmenu' => (int)$menuRef['codmenu']]) as $p) {
                    $p = array_change_key_case($p);
                    $flags = array_filter($p, static fn($k) => str_starts_with((string)$k, 'pode_'), ARRAY_FILTER_USE_KEY);
                    if ($flags && !array_filter($flags)) continue;
                    if (MigracaoCrud::primeiro($pdo, $t, ['codperfil' => (int)$p['codperfil'], 'codmenu' => (int)$menuNovo['codmenu']])) continue;
                    MigracaoCrud::inserir($pdo, $t, ['codperfil' => (int)$p['codperfil'], 'codmenu' => (int)$menuNovo['codmenu']] + $flags);
                    $cont++;
                }
            }
            $info['permissao'] = $cont . ' permissão(ões) criada(s) (as mesmas da Gestão de Funcionários)';
        }
        if (MigracaoCrud::existe($pdo, 'registros_migracao'))
            $pdo->prepare("INSERT INTO registros_migracao (codigo_migracao, origem, destino, tipo, versao, status, quantidade_registros, iniciada_em, concluida_em, detalhes)
                VALUES ('INTEGRACAO_FUNCIONARIOS_348', 'Homologação › Integração de funcionários', 'fo_integracao_config;fo_integracoes;sp_fo_integracao_*', 'ESTRUTURA', '4.0.1', 'CONCLUIDA', 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), concluida_em = VALUES(concluida_em), detalhes = VALUES(detalhes)")->execute([json_encode($info, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) { $rel['ok'] = false; $info['erro'] = $e->getMessage(); }
    $rel['bancos'][$banco] = $info;
}
$json = json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$logs = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logs)) @mkdir($logs, 0775, true);
@file_put_contents($logs . DIRECTORY_SEPARATOR . 'migracao_348_integracao_funcionarios' . ($simular ? '_simulacao' : '') . '.json', $json);
echo $json . PHP_EOL;
exit($rel['ok'] ? 0 : 1);

/** Questionário modelo de integração (universal, RASCUNHO): blocos, perguntas de escolha única com pontuação e nota mínima de 70%. */
function criarModelo348(PDO $u): void
{
    $u->beginTransaction();
    try {
        $u->prepare("INSERT INTO amb_questionario (codigo, nome_pt, nome_en, nome_es, descricao_pt, modulo) VALUES (?, ?, ?, ?, ?, 'EMPLOYEE_INTEGRATION')")
            ->execute(['INTEGRACAO_FUNCIONARIO_PADRAO', 'Integração de funcionários (modelo)', 'Employee induction (template)', 'Integración de empleados (modelo)',
                'Modelo criado pela migração 348: revise as perguntas conforme as regras da unidade e publique.']);
        $q = (int)$u->lastInsertId();
        $u->prepare("INSERT INTO amb_questionario_versao (codquestionario, versao, status, percentual_minimo, instrucoes_pt) VALUES (?, 1, 'RASCUNHO', 70, ?)")
            ->execute([$q, 'Leia cada pergunta e escolha a resposta correta.']);
        $v = (int)$u->lastInsertId();
        $blocos = [
            ['MEIO_AMBIENTE', 'Meio ambiente', 'Environment', 'Medio ambiente', [
                ['Onde deve ser descartado um pano sujo de óleo?', 'UNICA_ESCOLHA', [['No coletor de resíduos perigosos (Classe I)', 1], ['No lixo comum', 0], ['No coletor de recicláveis', 0]]],
                ['Ao ver um vazamento de produto químico, o que fazer primeiro?', 'UNICA_ESCOLHA', [['Avisar o responsável da área e conter com o kit de emergência, se for treinado', 1], ['Lavar com água até sumir', 0], ['Ignorar se for pequeno', 0]]],
            ]],
            ['SEGURANCA', 'Segurança ocupacional', 'Occupational safety', 'Seguridad ocupacional', [
                ['Quando o uso do EPI indicado para a atividade é obrigatório?', 'UNICA_ESCOLHA', [['Durante toda a atividade', 1], ['Só quando o supervisor estiver perto', 0], ['Só em dias de chuva', 0]]],
                ['Ao ouvir o alarme de emergência, você deve:', 'UNICA_ESCOLHA', [['Seguir a rota de fuga até o ponto de encontro', 1], ['Terminar a tarefa antes de sair', 0], ['Voltar para buscar seus pertences', 0]]],
                ['Trabalho em altura (acima de 2 metros) exige:', 'UNICA_ESCOLHA', [['Treinamento NR-35, ASO apto e permissão de trabalho', 1], ['Somente cinto de segurança', 0], ['Nada, se for rápido', 0]]],
            ]],
            ['REGRAS', 'Regras da unidade', 'Site rules', 'Reglas de la unidad', [
                ['Você recebeu e entendeu as regras de acesso e circulação da unidade?', 'SIM_NAO', [['Sim', 1], ['Não', 0]]],
            ]],
        ];
        $ob = 0;
        foreach ($blocos as $bi => [$cod, $pt, $en, $es, $perguntas]) {
            $u->prepare('INSERT INTO amb_questionario_bloco (codversao, codigo, titulo_pt, titulo_en, titulo_es, ordem) VALUES (?, ?, ?, ?, ?, ?)')->execute([$v, $cod, $pt, $en, $es, $bi + 1]);
            $b = (int)$u->lastInsertId();
            foreach ($perguntas as $pi => [$texto, $tipo, $opcoes]) {
                $u->prepare('INSERT INTO amb_questionario_pergunta (codversao, codbloco, codigo, texto_pt, tipo, obrigatoria, ordem, peso) VALUES (?, ?, ?, ?, ?, 1, ?, 1)')
                    ->execute([$v, $b, $cod . '.P' . ($pi + 1), $texto, $tipo, $pi + 1]);
                $p = (int)$u->lastInsertId();
                foreach ($opcoes as $oi => [$rotulo, $pts]) {
                    $u->prepare('INSERT INTO amb_questionario_opcao (codpergunta, codigo, rotulo_pt, valor, pontuacao, ordem) VALUES (?, ?, ?, ?, ?, ?)')
                        ->execute([$p, 'O' . ($oi + 1), $rotulo, (string)$pts, $pts, $oi + 1]);
                    $ob++;
                }
            }
        }
        $u->commit();
    } catch (Throwable $e) { if ($u->inTransaction()) $u->rollBack(); throw $e; }
}

/** Ajuda: trecho entre <!-- ml348 --> e <!-- /ml348 --> no fim do texto atual (só esse trecho é trocado ao rodar de novo). */
function gravarAjuda348(PDO $u, array $textos, bool $simular): array
{
    if (!MigracaoCrud::existe($u, 'ajuda_transacoes')) return ['aviso' => 'sem ajuda_transacoes no universal'];
    $out = [];
    foreach ($textos as $transacao => $d) {
        $atual = MigracaoCrud::primeiro($u, 'ajuda_transacoes', ['transacao' => $transacao]);
        $pt = (string)($atual['texto_pt'] ?? '');
        $assinatura = trim(html_entity_decode(strip_tags((string)preg_replace('/^.*?<strong>(.*?)<\/strong>.*$/s', '$1', $d['texto']['pt'])), ENT_QUOTES, 'UTF-8'));
        if ($atual !== null && !str_contains($pt, '<!-- ml348 -->') && $assinatura !== '' && str_contains(html_entity_decode(strip_tags($pt), ENT_QUOTES, 'UTF-8'), $assinatura)) { $out[$transacao] = 'já tem o texto (editado na tela de ajuda): não alterada'; continue; }
        $dados = [];
        foreach (['pt', 'en', 'es'] as $lg) {
            $velho = trim((string)preg_replace('/\s*<!-- ml348 -->.*?<!-- \/ml348 -->/s', '', (string)($atual['texto_' . $lg] ?? '')));
            $dados['texto_' . $lg] = ($velho === '' ? '' : $velho . "\n") . "<!-- ml348 -->\n" . $d['texto'][$lg] . "\n<!-- /ml348 -->";
            if ($atual === null || trim((string)($atual['titulo_' . $lg] ?? '')) === '') $dados['titulo_' . $lg] = $d['titulo'][$lg];
        }
        if ($atual !== null) { $igual = true; foreach ($dados as $k => $v) if ((string)($atual[$k] ?? '') !== $v) $igual = false; if ($igual) { $out[$transacao] = 'igual'; continue; } }
        if ($simular) { $out[$transacao] = $atual === null ? 'será incluída' : 'será atualizada'; continue; }
        $r = MigracaoCrud::salvar($u, 'ajuda_transacoes', ['transacao' => $transacao], $dados + ['ativo' => 1]);
        $out[$transacao] = $r === 'alterado' ? 'atualizada' : 'incluída';
    }
    return $out;
}
