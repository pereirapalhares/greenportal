<?php
declare(strict_types=1);

require_once __DIR__ . '/MenuCatalogoService.php';
require_once __DIR__ . '/NaoConformidadeCadastros.php';
require_once __DIR__ . '/NaoConformidadeAdministracao.php';

/**
 * Migração 335 (22/10/2026, pedido do usuário: "dentro da Não Conformidade/Administração, vamos ter que organizar melhor ... menus
 * de 2 nível"). Organiza Não Conformidades › Administração (grupo NAO_CONFORMIDADES_CADASTROS) em subgrupos, pelo catálogo universal
 * (replicado em todas as empresas; os códigos dos menus não mudam, então as permissões continuam):
 *   Ocorrências            → Correção de ocorrência, Transferência de responsabilidades, Painel de saneamento
 *   Listas e participantes → Listas de distribuição, Participantes externos
 *   Cadastros gerais       → Classificação, Classificação de fornecedores, Tipos de ocorrência, Origens, Sistemas de gestão, Setores, Tipos de causa
 *   Cadastros do cliente   → Clientes, Unidades do cliente, Produtos, Tipos de documento, Tipos de reclamação, Tipos de tampa, Tamanhos da lata
 * O subgrupo não precisa de permissão: aparece quando o usuário vê algum item dele (MenuService).
 * Usado por scripts/aplicar-migracao-335-nc-administracao-menus.php e, para empresas/menus novos, pelas migrações 321 e 333.
 */
final class NaoConformidadeMenus
{
    /** Cria os subgrupos que faltam e põe cada item no seu subgrupo. Retorna o que foi (ou seria) feito. */
    public static function organizar(bool $simular = false): array
    {
        $cat = new MenuCatalogoService();
        $u = $cat->universal();
        $grupo = MigracaoCrud::primeiro($u, 'menus', ['codigo' => NaoConformidadeCadastros::MENU_GRUPO]);
        if (!$grupo) return ['erro' => 'o universal não tem o grupo ' . NaoConformidadeCadastros::MENU_GRUPO . ' (rodar antes a migração 321)'];
        $grupo = array_change_key_case($grupo);
        $r = [];
        $subs = [];
        foreach (NaoConformidadeAdministracao::SUBGRUPOS as $codigo => [$rotulo, $icone, $ordem]) {
            $atual = MigracaoCrud::primeiro($u, 'menus', ['codigo' => $codigo]);
            if ($atual) {
                $atual = array_change_key_case($atual);
                if ((int)$atual['codmenu_pai'] !== (int)$grupo['codmenu']) {
                    if (!$simular) $cat->gravar(['codigo' => $codigo, 'codmenu_pai' => (int)$grupo['codmenu'], 'nivel' => (int)$grupo['nivel'] + 1], (int)$atual['codmenu']);
                    $r['subgrupos'][$codigo] = 'devolvido ao grupo Administração';
                } else {
                    if (!$simular) $cat->replicar($codigo, null, false);
                    $r['subgrupos'][$codigo] = 'já existia (codmenu ' . $atual['codmenu'] . ')';
                }
                $subs[$codigo] = (int)$atual['codmenu'];
                continue;
            }
            if ($simular) { $r['subgrupos'][$codigo] = 'será criado: ' . $rotulo[0]; $subs[$codigo] = 0; continue; }
            $g = $cat->gravar(['codmenu_pai' => (int)$grupo['codmenu'], 'modulo' => 'NAO_CONFORMIDADES', 'codigo' => $codigo, 'label' => $rotulo[0], 'label_pt' => $rotulo[0],
                'label_en' => $rotulo[1], 'label_es' => $rotulo[2], 'url' => null, 'nivel' => (int)$grupo['nivel'] + 1, 'ordem' => $ordem, 'icone' => $icone, 'ativo' => 1]);
            $subs[$codigo] = (int)$g['codmenu'];
            $r['subgrupos'][$codigo] = 'criado: ' . $rotulo[0] . ' (codmenu ' . $g['codmenu'] . ')';
        }
        // item => [subgrupo, ordem]
        $itens = [];
        foreach (NaoConformidadeAdministracao::MENUS as $codigo => $m) $itens[$codigo] = [$m[4], $m[3]];
        $o = ['geral' => 0, 'cliente' => 0];
        foreach (NaoConformidadeCadastros::LISTA as $chave => $c) {
            $sub = NaoConformidadeAdministracao::subgrupoCadastro((string)$chave);
            $k = $sub === NaoConformidadeAdministracao::SUB_CADASTROS_CLIENTE ? 'cliente' : 'geral';
            $itens[$c['menu']] = [$sub, $o[$k] += 10];
        }
        foreach ($itens as $codigo => [$sub, $ordem]) {
            $m = MigracaoCrud::primeiro($u, 'menus', ['codigo' => $codigo]);
            if (!$m) { $r['itens'][$codigo] = 'não existe no universal (será criado pela sua migração)'; continue; }
            $m = array_change_key_case($m);
            $pai = $subs[$sub] ?? 0;
            if ($pai > 0 && (int)$m['codmenu_pai'] === $pai && (int)$m['ordem'] === $ordem) { if (!$simular) $cat->replicar($codigo, null, false); $r['itens'][$codigo] = 'já no lugar'; continue; }
            if (!$simular) $cat->gravar(['codigo' => $codigo, 'codmenu_pai' => $pai, 'nivel' => (int)$grupo['nivel'] + 2, 'ordem' => $ordem], (int)$m['codmenu']);
            $r['itens'][$codigo] = ($simular ? 'irá para ' : 'movido para ') . NaoConformidadeAdministracao::SUBGRUPOS[$sub][0][0] . " (ordem $ordem)";
        }
        // O que mais estiver solto no grupo Administração (fica como está, avisado)
        if (!$simular) foreach (MigracaoCrud::selecionar($u, 'menus', ['codmenu_pai' => (int)$grupo['codmenu']]) as $f) {
            $f = array_change_key_case($f);
            if (!isset(NaoConformidadeAdministracao::SUBGRUPOS[strtoupper((string)$f['codigo'])])) $r['fora_dos_subgrupos'][] = $f['codigo'];
        }
        return $r;
    }

    /** Pai (codmenu no universal) para criar um menu novo da administração: o subgrupo, se já existe; senão o grupo. */
    public static function paiNoUniversal(PDO $u, string $subgrupo): ?int
    {
        $s = MigracaoCrud::primeiro($u, 'menus', ['codigo' => $subgrupo]) ?: MigracaoCrud::primeiro($u, 'menus', ['codigo' => NaoConformidadeCadastros::MENU_GRUPO]);
        return $s ? (int)array_change_key_case($s)['codmenu'] : null;
    }
}
