<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GreenPortalDatabase.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'IdiomaService.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ModuloEmpresaService.php';

final class MenuService
{
    private const ICONS = [
        'DASHBOARD' => 'bi-speedometer2', 'SETUP' => 'bi-sliders', 'SETUP_LOGIN' => 'bi-box-arrow-in-right',
        'SETUP_MAIN' => 'bi-window', 'SETUP_LAYOUT' => 'bi-palette', 'SETUP_GRID' => 'bi-table',
        'SETUP_FORMULARIO' => 'bi-ui-checks-grid', 'SETUP_IA_PROMPT' => 'bi-stars', 'SEGURANCA' => 'bi-shield-lock-fill',
        'SEGURANCA_GRUPOS' => 'bi-people', 'SEGURANCA_USUARIOS' => 'bi-person-lines-fill',
        'SEGURANCA_PERFIL' => 'bi-person-badge', 'SEGURANCA_PERMISSOES' => 'bi-key',
        'SEGURANCA_FORMULARIO' => 'bi-ui-checks', 'SEGURANCA_LISTA' => 'bi-list-check',
        'ADMINISTRACAO' => 'bi-building-gear', 'ADMINISTRACAO_EMPRESAS_MIGRACAO' => 'bi-database-gear', 'ADMINISTRACAO_UNIDADES' => 'bi-buildings', 'ADMINISTRACAO_AREAS' => 'bi-diagram-3', 'ADMINISTRACAO_ATIVIDADES' => 'bi-list-task', 'ADMINISTRACAO_SERVICOS' => 'bi-gear-wide-connected', 'ADMINISTRACAO_EMAILS_ENVIADOS' => 'bi-envelope-check', 'ADMINISTRACAO_HELPDESK' => 'bi-headset', 'PORTARIA' => 'bi-person-badge', 'PORTARIA_DOCUMENTACAO_FORNECEDOR' => 'bi-file-earmark-person', 'PORTARIA_DOCUMENTACAO_FUNCIONARIO' => 'bi-person-vcard',
        'MANUTENCAO_LEGAL' => 'bi-clipboard-check', 'LEGISLACAO' => 'bi-journal-text', 'LEGISLACAO_X_EMPRESAS' => 'bi-building-gear', 'LEGISLACAO_X_PODER' => 'bi-diagram-3',
        'TIPOS' => 'bi-tags', 'AMBITOS' => 'bi-globe2', 'ASPECTOS' => 'bi-list-check', 'IMPACTOS' => 'bi-lightning', 'PERIGOS' => 'bi-exclamation-triangle', 'RISCOS' => 'bi-shield-exclamation', 'ASSUNTOS' => 'bi-bookmarks', 'MODULO_LEGAL' => 'bi-journal-text',
        'MODULO_LAIA' => 'bi-diagram-3',
        'REQUISITOS_LEGAIS' => 'bi-file-earmark-check', 'LICENCAS' => 'bi-file-earmark-text',
        'LAIA' => 'bi-clipboard-data', 'LAIA_ATIVIDADES' => 'bi-list-task', 'LAIA_DESTINOS' => 'bi-signpost-2', 'LAIA_REVISAO' => 'bi-arrow-repeat',
        'LAIA_REQUISITOS_LEGAIS' => 'bi-file-earmark-check', 'LAIA_CONTROLES_DOCUMENTOS' => 'bi-clipboard2-check', 'LAIA_OBJETIVOS_METAS' => 'bi-bullseye',
        'LPRO' => 'bi-shield-exclamation',
        'LAIS' => 'bi-people', 'LAIS_REQUISITOS_LEGAIS' => 'bi-file-earmark-check', 'LAIS_CONTROLES_DOCUMENTOS' => 'bi-clipboard2-check', 'LAIS_OBJETIVOS_METAS' => 'bi-bullseye',
        'LAIS_PARTES_INTERESSADAS' => 'bi-person-heart', 'LAIS_ASPECTOS_SOCIAIS' => 'bi-diagram-3',
        'LAIS_IMPACTOS_SOCIAIS' => 'bi-exclamation-diamond', 'LAIS_REVISOES' => 'bi-arrow-repeat',
        'SITUACAO_CUMPRIMENTO' => 'bi-clipboard2-pulse',
        'CORRELACIONAMENTO_AMBIENTAL' => 'bi-tree', 'CORRELACIONAMENTO_OCUPACIONAL' => 'bi-person-workspace', 'CORRELACIONAMENTO_SOCIAL' => 'bi-people-fill',
        'CONTROLE_DOCUMENTOS' => 'bi-folder2-open', 'MODULOLEGAL_TREINAMENTOS' => 'bi-mortarboard', 'MODULOLEGAL_INFRAESTRUTURA' => 'bi-buildings', 'MODULOLEGAL_POTENCIAIS' => 'bi-stars',
        'HOMOLOGACAO_FORNECEDORES' => 'bi-person-check',
        'HOMOLOGACAO_FORNECEDOR_FORNECEDOR' => 'bi-building-check',
        'HOMOLOGACAO_FORNECEDOR_FUNCIONARIO' => 'bi-person-vcard',
        'NAO_CONFORMIDADES' => 'bi-clipboard2-pulse',
        'NAO_CONFORMIDADES_LISTA_OCORRENCIAS' => 'bi-clipboard2-check',
        'NAO_CONFORMIDADES_MINHAS_OCORRENCIAS' => 'bi-person-lines-fill',
        'NAO_CONFORMIDADES_NOVA_OCORRENCIA_EMPRESA' => 'bi-file-earmark-medical',
        'NAO_CONFORMIDADES_NOVA_OCORRENCIA_FORNECEDOR' => 'bi-truck',
        'NAO_CONFORMIDADES_NOVA_OCORRENCIA_PLANEJAMENTO' => 'bi-calendar-check',
        'NAO_CONFORMIDADES_NOVA_OCORRENCIA_CLIENTE' => 'bi-person-lines-fill',
        'NAO_CONFORMIDADES_NOVA_OCORRENCIA_ASSISTENCIA' => 'bi-tools',
        'NAO_CONFORMIDADES_NOVA_OCORRENCIA_ACIDENTE' => 'bi-exclamation-triangle',
        'MEDICAO_MONITORAMENTO' => 'bi-graph-up-arrow', 'LEVANTAMENTO_AMBIENTAL' => 'bi-tree',
        'LEVANTAMENTO_OCUPACIONAL' => 'bi-person-workspace',
        'LEVANTAMENTO_SOCIAL' => 'bi-people',
        'MM_EQUIPAMENTOS' => 'bi-tools',
        'MM_MEDICOES' => 'bi-speedometer2', 'MM_CONTROLE_CALIBRACAO' => 'bi-check2-square',
        'MM_PLANO_MEDICAO_MONITORAMENTO' => 'bi-calendar2-week',
    ];

    public function __construct(private PDO $pdo, private string $idioma, private array $usuario, private array $permissoes)
    {
    }

    public function carregar(): array
    {
        $campo = in_array($this->idioma, ['pt', 'en', 'es'], true) ? 'label_' . $this->idioma : 'label_pt';
        $rows = [];
        try {
            $env = GreenPortalDatabase::env();
            $universal = GreenPortalDatabase::connect($env, $env['GUNIVERSAL_DB_DATABASE'] ?? 'guniversal_dbp', 'GUNIVERSAL');
            $statement = $universal->query('SELECT codmenu,codmenu_pai,modulo,codigo,label,label_pt,label_en,label_es,url,nivel,ordem,icone,ativo FROM menus WHERE ativo=1 ORDER BY ordem,codmenu');
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            // Fallback temporário para bases antigas durante a homologação.
            $statement = $this->pdo->prepare('CALL sp_crud_select(:tabela, :filtros, NULL, 1000, 0)');
            $statement->execute(['tabela' => 'vw_menus_grid', 'filtros' => json_encode(['ativo' => 1], JSON_UNESCAPED_UNICODE)]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement->closeCursor();
        }
        // Pré-visualização do rascunho (Administração › Textos e idiomas): só para o ADMINISTRADOR.
        if (class_exists('IdiomaService') && IdiomaService::previa()) {
            $rascunho = IdiomaService::grupo('MENUS');
            foreach ($rows as &$linha) {
                $texto = $rascunho['menu.' . (string)$linha['codigo']] ?? null;
                if ($texto) {
                    foreach (['pt', 'en', 'es'] as $idiomaMenu) $linha['label_' . $idiomaMenu] = ($texto[$idiomaMenu] ?? '') !== '' ? $texto[$idiomaMenu] : null;
                    $linha['label_pt'] = $linha['label_pt'] ?? $linha['label'];
                }
            }
            unset($linha);
        }
        if (!ModuloEmpresaService::ehAdministrador()) {
            $rows = array_values(array_filter($rows, static fn(array $menu): bool => ModuloEmpresaService::permitidoParaMenu((string)($menu['codigo'] ?? ''))));
        }
        $admin = (($this->usuario['codigo_perfil'] ?? '') === 'ADMINISTRADOR');
        // Permissões pelo código da transação (o menu vem do catálogo universal e as permissões do menu da empresa:
        // o código é o mesmo nos dois, o codmenu pode não ser)
        $permissions = [];
        foreach ($this->permissoes as $permission) {
            $codigoMenu = strtoupper((string)($permission['menu_codigo'] ?? ''));
            if ($codigoMenu !== '') $permissions[$codigoMenu] = $permission;
        }
        if (!$admin) {
            $rowsById = [];
            foreach ($rows as $menu) $rowsById[(int)$menu['codmenu']] = $menu;
            $visibleIds = [];
            // Grupo (sem tela e com itens dentro) só aparece se algum item dele aparecer: a permissão do próprio grupo não
            // basta (antes um grupo liberado com todos os itens sem "Visualizar" aparecia vazio no menu).
            $temFilho = [];
            foreach ($rows as $menu) if ((int)($menu['codmenu_pai'] ?? 0) > 0) $temFilho[(int)$menu['codmenu_pai']] = true;
            foreach ($rows as $menu) {
                if (trim((string)($menu['url'] ?? '')) === '' && isset($temFilho[(int)$menu['codmenu']])) continue;
                $permission = $permissions[strtoupper((string)($menu['codigo'] ?? ''))] ?? null;
                if (!is_array($permission) || (empty($permission['pode_visualizar']) && empty($permission['pode_visualizar_total']))) continue;
                $current = $menu;
                while ($current) {
                    $currentId = (int)$current['codmenu'];
                    if (isset($visibleIds[$currentId])) break;
                    $visibleIds[$currentId] = true;
                    $parentId = (int)($current['codmenu_pai'] ?? 0);
                    $current = $parentId > 0 ? ($rowsById[$parentId] ?? null) : null;
                }
            }
            $rows = array_values(array_filter($rows, static fn(array $menu): bool => isset($visibleIds[(int)$menu['codmenu']])));
        }
        $byParent = [];
        foreach ($rows as $row) {
            $row['_label'] = (string)($row[$campo] ?? $row['label_pt'] ?? $row['label'] ?? $row['codigo']);
            $row['_icon'] = (string)($row['icone'] ?? '') ?: (self::ICONS[(string)$row['codigo']] ?? 'bi-circle');
            $row['_id'] = 'menu-' . strtolower((string)$row['codigo']);
            $byParent[(int)($row['codmenu_pai'] ?? 0)][] = $row;
        }
        foreach ($byParent as &$items) usort($items, static fn(array $a, array $b): int => ((int)$a['ordem'] <=> (int)$b['ordem']) ?: ((int)$a['codmenu'] <=> (int)$b['codmenu']));
        unset($items);
        return $byParent;
    }
}
