<?php
declare(strict_types=1);

/**
 * Migração 401 — Sincronismo dos USUÁRIOS: <legado>_seguranca.usuarios → <banco>.usuarios (decisões do usuário de 19/11/2026).
 *
 * Até aqui os usuários eram copiados uma vez na migração geral (migrar-seguranca-generico.php); o sincronismo diário só trazia
 * usuários × unidades (316) e participantes de NC (333). Diagnóstico de 19/11: Crown com 857 usuários só no legado.
 *
 * Regras:
 *  - mesmo usuário = mesmo codusuario nos dois lados (a migração manteve o código). Um código que existe nos dois lados com
 *    login diferente e que ainda não está na linha de base é CONFLITO (pode ser um usuário criado só no greenportal): não mexe;
 *  - INCLUIR: usuário do legado que falta no greenportal, com o mesmo código, login, nome, e-mail, senha, ativo e data de
 *    inclusão. Grupo = o do legado (o grupo é criado no greenportal se ainda não existir). Perfil = o que os usuários desse
 *    grupo mais usam no greenportal (respeita os ajustes feitos aqui); sem nenhum, o perfil da migração (código pelo nome do
 *    grupo); sem ele, cria o perfil SEM permissões (o ADMINISTRADOR libera em Perfis e Permissões). Fica de fora (relatório):
 *    login vazio, login repetido no legado ou já usado por outro usuário do greenportal;
 *  - ALTERAR: só o que mudou NO LEGADO desde a última execução (linha de base usuarios_sinc_base no banco da empresa, hash
 *    por campo): login, nome, e-mail, ativo, data de inclusão e grupo (com o perfil pela regra acima). O que foi mudado só no
 *    greenportal fica. A 1ª execução aplicada só cria a linha de base para os usuários que já existem;
 *  - SENHA: sempre a do legado (senha_usa_hash = 0) quando for diferente;
 *  - EXCLUÍDO no legado (estava na linha de base e saiu): desativado no greenportal (ativo = 0), nunca excluído;
 *  - ativado = 'N' no legado → ativo 0; qualquer outro valor (inclusive 'X' dos participantes externos) → ativo 1, como na migração.
 */
final class SincronismoUsuarios
{
    private const CAMPOS = ['userid', 'nome', 'email', 'ativo', 'criado_em', 'codgrupo'];

    public static function sincronizar(PDO $pdo, string $origem, string $destino, bool $aplicar): array
    {
        $seg = $origem . '_seguranca';
        $r = ['origem' => $seg . '.usuarios', 'destino' => $destino . '.usuarios', 'tabelas' => [],
            'totais' => ['incluidos' => 0, 'alterados' => 0, 'excluidos' => 0, 'senhas' => 0, 'conflitos' => 0, 'ignorados' => 0]];
        if (!self::tabela($pdo, $seg, 'usuarios') || !self::tabela($pdo, $destino, 'usuarios')) return $r + ['status' => 'IGNORADA (sem usuarios no banco de segurança do legado)'];
        if ((int)self::valor($pdo, 'SELECT GET_LOCK(?, 5)', ['sinc_usuarios_' . $destino]) !== 1) return $r + ['erro' => 'outro sincronismo de usuários está em execução para este banco'];
        try {
            $cl = self::colunas($pdo, $seg, 'usuarios');
            $m = ['codusuario' => self::pick($cl, ['codusuario', 'id']), 'userid' => self::pick($cl, ['userid', 'login', 'usuario']), 'nome' => self::pick($cl, ['nome']),
                'email' => self::pick($cl, ['email', 'emails']), 'ativo' => self::pick($cl, ['ativado', 'ativo']), 'criado_em' => self::pick($cl, ['dtinclusao', 'criado_em', 'created_at']),
                'senha' => self::pick($cl, ['senha']), 'codgrupo' => self::pick($cl, ['codgrupo', 'grupo_id'])];
            if (!$m['codusuario'] || !$m['userid']) return $r + ['erro' => 'usuarios do legado sem codusuario/userid'];
            $cd = self::colunas($pdo, $destino, 'usuarios');
            foreach (['codusuario', 'userid', 'nome', 'senha', 'ativo', 'codgrupo', 'codperfil'] as $c) if (!isset($cd[$c])) return $r + ['erro' => "usuarios do greenportal sem a coluna $c"];

            // ---------------------------------------------------- legado
            $grupoDoUsuario = []; $gruposLegado = []; // 19/11/2026: todos os grupos do usuário no legado (usuariosxgrupos)
            if (self::tabela($pdo, $seg, 'usuariosxgrupos')) {
                $cg = self::colunas($pdo, $seg, 'usuariosxgrupos');
                $gu = self::pick($cg, ['codusuario', 'usuario_id']); $gg = self::pick($cg, ['codgrupo', 'grupo_id']);
                if ($gu && $gg) foreach (self::linhas($pdo, 'SELECT ' . self::q($cg[$gu]) . ' u, ' . self::q($cg[$gg]) . ' g FROM ' . self::q($seg) . '.usuariosxgrupos ORDER BY 1, 2') as $x) { $grupoDoUsuario[(int)$x['u']] ??= (int)$x['g']; if ((int)$x['g'] > 0) $gruposLegado[(int)$x['u']][(int)$x['g']] = true; }
            }
            $nomesGrupoLegado = [];
            if (self::tabela($pdo, $seg, 'grupos')) {
                $cgr = self::colunas($pdo, $seg, 'grupos');
                $gid = self::pick($cgr, ['codgrupo', 'id']); $gnm = self::pick($cgr, ['nome', 'dscgrupo', 'descricao']);
                if ($gid && $gnm) foreach (self::linhas($pdo, 'SELECT ' . self::q($cgr[$gid]) . ' id, ' . self::q($cgr[$gnm]) . ' nome FROM ' . self::q($seg) . '.grupos') as $g) $nomesGrupoLegado[(int)$g['id']] = (string)$g['nome'];
            }
            $legado = [];
            foreach (self::linhas($pdo, 'SELECT * FROM ' . self::q($seg) . '.usuarios') as $u) {
                $u = array_change_key_case($u, CASE_LOWER);
                $id = (int)($u[$m['codusuario']] ?? 0);
                if ($id <= 0) continue;
                $grupo = $grupoDoUsuario[$id] ?? ($m['codgrupo'] ? (int)($u[$m['codgrupo']] ?? 0) : 0);
                $legado[$id] = [
                    'userid' => trim((string)($u[$m['userid']] ?? '')), 'nome' => trim((string)($m['nome'] ? ($u[$m['nome']] ?? '') : '')),
                    'email' => trim((string)($m['email'] ? ($u[$m['email']] ?? '') : '')),
                    'ativo' => ($m['ativo'] && strtoupper(trim((string)($u[$m['ativo']] ?? 'S'))) === 'N') ? 0 : 1,
                    'criado_em' => self::data($m['criado_em'] ? ($u[$m['criado_em']] ?? null) : null),
                    'codgrupo' => $grupo > 0 ? $grupo : null, 'senha' => (string)($m['senha'] ? ($u[$m['senha']] ?? '') : ''),
                ];
            }

            // ---------------------------------------------------- greenportal
            $D = self::q($destino);
            $portal = [];
            $temCriado = isset($cd['criado_em']);
            foreach (self::linhas($pdo, "SELECT codusuario, userid, nome, email, ativo, codgrupo, codperfil, senha" . ($temCriado ? ', criado_em' : '') . " FROM $D.usuarios") as $u) $portal[(int)$u['codusuario']] = $u;
            $loginPortal = [];
            foreach ($portal as $id => $u) $loginPortal[mb_strtolower(trim((string)$u['userid']), 'UTF-8')] = $id;
            $temBase = self::tabela($pdo, $destino, 'usuarios_sinc_base');
            $base = [];
            if ($temBase) foreach (self::linhas($pdo, "SELECT codusuario, hashes FROM $D.usuarios_sinc_base") as $b) $base[(int)$b['codusuario']] = json_decode((string)$b['hashes'], true) ?: [];
            $grupos = [];
            foreach (self::linhas($pdo, "SELECT codgrupo FROM $D.grupos") as $g) $grupos[(int)$g['codgrupo']] = true;
            // perfil mais usado por grupo no greenportal (respeita os ajustes feitos aqui)
            $perfilDoGrupo = [];
            foreach (self::linhas($pdo, "SELECT codgrupo, codperfil, COUNT(*) n FROM $D.usuarios WHERE codgrupo IS NOT NULL AND codperfil IS NOT NULL GROUP BY codgrupo, codperfil ORDER BY codgrupo, n DESC, codperfil") as $x) $perfilDoGrupo[(int)$x['codgrupo']] ??= (int)$x['codperfil'];
            $perfilPorCodigo = [];
            foreach (self::linhas($pdo, "SELECT codperfil, codigo FROM $D.perfis") as $p) $perfilPorCodigo[strtoupper((string)$p['codigo'])] = (int)$p['codperfil'];

            // ---------------------------------------------------- plano
            $plano = ['incluir' => [], 'alterar' => [], 'senha' => [], 'desativar' => [], 'base' => [], 'conflitos' => [], 'ignorados' => [], 'grupos_novos' => [], 'perfis_novos' => []];
            $loginsLegado = [];
            foreach ($legado as $id => $l) { $k = mb_strtolower($l['userid'], 'UTF-8'); if ($k !== '') $loginsLegado[$k][] = $id; }
            $perfilPara = function (?int $grupo) use (&$perfilDoGrupo, &$perfilPorCodigo, &$plano, $nomesGrupoLegado): ?int {
                if (!$grupo) return null;
                if (isset($perfilDoGrupo[$grupo])) return $perfilDoGrupo[$grupo];
                $codigo = self::perfilCodigo($nomesGrupoLegado[$grupo] ?? ('GRUPO_' . $grupo));
                if (isset($perfilPorCodigo[$codigo])) return $perfilDoGrupo[$grupo] = $perfilPorCodigo[$codigo];
                $plano['perfis_novos'][$codigo] = ['codigo' => $codigo, 'nome' => $nomesGrupoLegado[$grupo] ?? $codigo, 'grupo' => $grupo];
                return null; // definido na aplicação, depois de criar o perfil
            };
            foreach ($legado as $id => $l) {
                $hashes = self::hashes($l);
                if (!isset($portal[$id])) {
                    $k = mb_strtolower($l['userid'], 'UTF-8');
                    if ($k === '') { $plano['ignorados'][] = "$id (sem login)"; continue; }
                    if (count($loginsLegado[$k]) > 1 && $loginsLegado[$k][0] !== $id) { $plano['ignorados'][] = "$id ({$l['userid']}: login repetido no legado)"; continue; }
                    if (isset($loginPortal[$k])) { $plano['ignorados'][] = "$id ({$l['userid']}: login já usado pelo usuário {$loginPortal[$k]} do greenportal)"; continue; }
                    if ($l['codgrupo'] && !isset($grupos[$l['codgrupo']])) $plano['grupos_novos'][$l['codgrupo']] = $nomesGrupoLegado[$l['codgrupo']] ?? ('Grupo ' . $l['codgrupo']);
                    $plano['incluir'][$id] = $l + ['codperfil' => $perfilPara($l['codgrupo'])];
                    $loginPortal[$k] = $id;
                    continue;
                }
                $p = $portal[$id];
                if (!isset($base[$id])) {
                    // ainda não ligado: só liga se o login for o mesmo (senão pode ser outro usuário criado no greenportal)
                    if (mb_strtolower(trim((string)$p['userid']), 'UTF-8') !== mb_strtolower($l['userid'], 'UTF-8')) { $plano['conflitos'][] = "$id (legado {$l['userid']} × greenportal {$p['userid']})"; continue; }
                    $plano['base'][$id] = $hashes;
                } else {
                    $mudou = [];
                    foreach (self::CAMPOS as $c) if (($base[$id][$c] ?? null) !== $hashes[$c]) $mudou[$c] = $l[$c];
                    if (isset($mudou['userid'])) {
                        $k = mb_strtolower($l['userid'], 'UTF-8');
                        if ($k === '' || (isset($loginPortal[$k]) && $loginPortal[$k] !== $id)) { unset($mudou['userid']); $plano['conflitos'][] = "$id (login novo {$l['userid']} vazio ou já usado no greenportal: mantido {$p['userid']})"; }
                    }
                    if (isset($mudou['codgrupo'])) {
                        if ($l['codgrupo'] && !isset($grupos[$l['codgrupo']])) $plano['grupos_novos'][$l['codgrupo']] = $nomesGrupoLegado[$l['codgrupo']] ?? ('Grupo ' . $l['codgrupo']);
                        $mudou['codperfil'] = $perfilPara($l['codgrupo']);
                    }
                    if ($mudou) $plano['alterar'][$id] = $mudou;
                    $plano['base'][$id] = $hashes;
                }
                if ($l['senha'] !== '' && $l['senha'] !== (string)$p['senha']) $plano['senha'][$id] = $l['senha'];
            }
            foreach ($base as $id => $_) if (!isset($legado[$id]) && isset($portal[$id]) && (int)$portal[$id]['ativo'] === 1) $plano['desativar'][] = $id;
            $sairDaBase = array_values(array_diff(array_keys($base), array_keys($legado)));

            // ---------------------------------------------------- vários grupos por usuário (19/11/2026)
            // legado usuariosxgrupos → greenportal responsabilidade_grupoxusuario (tabela que as responsabilidades já usam pela
            // vw_responsabilidade_grupoxusuario). O que é novo no legado entra; o vínculo que saiu do legado desde a última
            // execução aplicada sai do greenportal (ATIVO 0), menos o do grupo principal do usuário. Vínculo criado só no portal fica.
            $gx = ['vincular' => [], 'desvincular' => [], 'base' => [], 'usuarios' => []];
            $temGx = self::tabela($pdo, $destino, 'responsabilidade_grupoxusuario');
            if ($temGx) {
                $hoje = date('Y-m-d');
                $usuariosGx = array_unique(array_merge(array_keys($plano['incluir']), array_keys($plano['base'])));
                $gx['usuarios'] = $usuariosGx;
                $vigente = []; $reativavel = [];
                foreach (self::linhas($pdo, "SELECT CODGRUPOXUSUARIO id, CODUSUARIO u, CODGRUPO g, ATIVO a, VALIDO_DE de, VALIDO_ATE ate FROM $D.responsabilidade_grupoxusuario") as $x) {
                    $u = (int)$x['u']; $g = (int)$x['g'];
                    if ((int)$x['a'] === 1 && (string)$x['de'] <= $hoje && ($x['ate'] === null || (string)$x['ate'] >= $hoje)) $vigente[$u][$g] = (int)$x['id'];
                    elseif ((int)$x['a'] !== 1 && $x['ate'] === null && (string)$x['de'] <= $hoje) $reativavel[$u][$g] = (int)$x['id'];
                }
                $baseGx = [];
                if (self::tabela($pdo, $destino, 'usuariosxgrupos_sinc_base')) foreach (self::linhas($pdo, "SELECT codusuario u, codgrupo g FROM $D.usuariosxgrupos_sinc_base") as $x) $baseGx[(int)$x['u']][(int)$x['g']] = true;
                foreach ($usuariosGx as $u) {
                    $l = $gruposLegado[$u] ?? (!empty($legado[$u]['codgrupo']) ? [(int)$legado[$u]['codgrupo'] => true] : []);
                    $principal = (int)(array_key_exists('codgrupo', $plano['alterar'][$u] ?? []) ? ($plano['alterar'][$u]['codgrupo'] ?? 0)
                        : (isset($plano['incluir'][$u]) ? ($plano['incluir'][$u]['codgrupo'] ?? 0) : ($portal[$u]['codgrupo'] ?? 0)));
                    foreach (array_keys($l) as $g) {
                        $gx['base'][] = [$u, $g];
                        if (!isset($grupos[$g])) $plano['grupos_novos'][$g] ??= $nomesGrupoLegado[$g] ?? ('Grupo ' . $g);
                        if (!isset($vigente[$u][$g])) $gx['vincular'][] = ['u' => $u, 'g' => $g, 'reativar' => $reativavel[$u][$g] ?? 0];
                    }
                    foreach (array_keys($baseGx[$u] ?? []) as $g) if (!isset($l[$g]) && isset($vigente[$u][$g]) && $g !== $principal) $gx['desvincular'][] = $vigente[$u][$g];
                }
            }

            $inf = ['legado' => count($legado), 'portal' => count($portal), 'linha_de_base' => $temBase ? 'existe' : ($aplicar ? 'criada nesta execução' : 'será criada na 1ª execução aplicada'),
                'incluidos' => count($plano['incluir']), 'alterados' => count($plano['alterar']), 'excluidos' => count($plano['desativar']), 'senhas' => count($plano['senha']),
                'conflitos' => count($plano['conflitos']), 'ignorados' => count($plano['ignorados']), 'grupos_novos' => count($plano['grupos_novos']), 'tabela_vinculos_grupo' => $temGx ? 'responsabilidade_grupoxusuario' : 'não existe neste banco (só o grupo principal)', 'perfis_novos_sem_permissao' => array_keys($plano['perfis_novos']),
                'grupos_vinculados' => count($gx['vincular']), 'grupos_desvinculados' => count($gx['desvincular']),
                'origem' => sprintf('senhas trocadas: %d · desativados: %d · conflitos: %d · fora (sem login/login repetido): %d · vínculos de grupo: +%d / -%d', count($plano['senha']), count($plano['desativar']), count($plano['conflitos']), count($plano['ignorados']), count($gx['vincular']), count($gx['desvincular'])),
                'exemplos' => ['incluidos' => array_map(static fn($id) => $id . ' (' . $plano['incluir'][$id]['userid'] . ')', array_slice(array_keys($plano['incluir']), 0, 8)),
                    'alterados' => array_map(static fn($id) => $id . ' (' . implode(', ', array_keys($plano['alterar'][$id])) . ')', array_slice(array_keys($plano['alterar']), 0, 8)),
                    'excluidos' => array_map(static fn($id) => $id . ' (desativado)', array_slice($plano['desativar'], 0, 8)),
                    'conflitos' => array_slice($plano['conflitos'], 0, 8), 'ignorados' => array_slice($plano['ignorados'], 0, 8)]];
            $r['totais'] = ['incluidos' => $inf['incluidos'], 'alterados' => $inf['alterados'] + $inf['senhas'], 'excluidos' => $inf['excluidos'], 'senhas' => $inf['senhas'], 'conflitos' => $inf['conflitos'], 'ignorados' => $inf['ignorados']];
            $r['tabelas']['usuarios'] = $inf;
            if (!$aplicar) return $r;

            // ---------------------------------------------------- aplicação
            $pdo->exec("CREATE TABLE IF NOT EXISTS $D.usuarios_sinc_base (codusuario BIGINT NOT NULL PRIMARY KEY, hashes JSON NOT NULL, atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Migração 401: estado do legado (<legado>_seguranca.usuarios) na última execução aplicada do sincronismo'");
            if ($temGx) $pdo->exec("CREATE TABLE IF NOT EXISTS $D.usuariosxgrupos_sinc_base (codusuario BIGINT NOT NULL, codgrupo BIGINT NOT NULL, PRIMARY KEY (codusuario, codgrupo)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='19/11/2026: vínculos usuário × grupo do legado (usuariosxgrupos) na última execução aplicada do sincronismo'");
            $pdo->beginTransaction();
            try {
                $cg = self::colunas($pdo, $destino, 'grupos');
                foreach ($plano['grupos_novos'] as $gid => $gnome) {
                    $cols = ['codgrupo' => $gid, 'nome' => $gnome] + (isset($cg['ativo']) ? ['ativo' => 1] : []);
                    $pdo->prepare("INSERT IGNORE INTO $D.grupos (" . implode(', ', array_map([self::class, 'q'], array_keys($cols))) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
                }
                $cp = self::colunas($pdo, $destino, 'perfis');
                foreach ($plano['perfis_novos'] as $codigo => $pf) {
                    $cols = ['codigo' => $codigo, 'nome' => $pf['nome']] + (isset($cp['ativo']) ? ['ativo' => 1] : []);
                    $pdo->prepare("INSERT IGNORE INTO $D.perfis (" . implode(', ', array_map([self::class, 'q'], array_keys($cols))) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
                    $perfilPorCodigo[$codigo] = (int)self::valor($pdo, "SELECT codperfil FROM $D.perfis WHERE codigo = ?", [$codigo]);
                    $perfilDoGrupo[$pf['grupo']] = $perfilPorCodigo[$codigo];
                }
                $perfilFinal = static fn(?int $perfil, ?int $grupo): ?int => $perfil ?: ($grupo ? ($perfilDoGrupo[$grupo] ?? null) : null);
                foreach ($plano['incluir'] as $id => $l) {
                    $cols = ['codusuario' => $id, 'userid' => $l['userid'], 'nome' => $l['nome'] !== '' ? $l['nome'] : $l['userid'], 'senha' => $l['senha'], 'ativo' => $l['ativo'],
                        'codgrupo' => $l['codgrupo'], 'codperfil' => $perfilFinal($l['codperfil'], $l['codgrupo'])];
                    if (isset($cd['email'])) $cols['email'] = $l['email'];
                    if (isset($cd['senha_usa_hash'])) $cols['senha_usa_hash'] = 0;
                    if (isset($cd['trocar_senha'])) $cols['trocar_senha'] = 0;
                    if ($temCriado && $l['criado_em']) $cols['criado_em'] = $l['criado_em'];
                    $pdo->prepare("INSERT INTO $D.usuarios (" . implode(', ', array_map([self::class, 'q'], array_keys($cols))) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
                    $plano['base'][$id] = self::hashes($l);
                }
                foreach ($plano['alterar'] as $id => $mudou) {
                    if (array_key_exists('codperfil', $mudou)) $mudou['codperfil'] = $perfilFinal($mudou['codperfil'], $mudou['codgrupo'] ?? null);
                    if (array_key_exists('criado_em', $mudou) && (!$temCriado || !$mudou['criado_em'])) unset($mudou['criado_em']);
                    if (isset($mudou['nome']) && $mudou['nome'] === '') unset($mudou['nome']);
                    if (!isset($cd['email'])) unset($mudou['email']);
                    if (!$mudou) continue;
                    $pdo->prepare("UPDATE $D.usuarios SET " . implode(', ', array_map(static fn($c) => self::q($c) . ' = ?', array_keys($mudou))) . ' WHERE codusuario = ?')->execute([...array_values($mudou), $id]);
                }
                foreach ($plano['senha'] as $id => $senha) {
                    $pdo->prepare("UPDATE $D.usuarios SET senha = ?" . (isset($cd['senha_usa_hash']) ? ', senha_usa_hash = 0' : '') . ' WHERE codusuario = ?')->execute([$senha, $id]);
                }
                foreach ($plano['desativar'] as $id) $pdo->prepare("UPDATE $D.usuarios SET ativo = 0 WHERE codusuario = ?")->execute([$id]);
                if ($temGx) {
                    $insGx = $pdo->prepare("INSERT INTO $D.responsabilidade_grupoxusuario (CODGRUPO, CODUSUARIO, VALIDO_DE, ATIVO) VALUES (?, ?, CURDATE(), 1)");
                    $reaGx = $pdo->prepare("UPDATE $D.responsabilidade_grupoxusuario SET ATIVO = 1 WHERE CODGRUPOXUSUARIO = ?");
                    foreach ($gx['vincular'] as $v) { if ($v['reativar'] > 0) $reaGx->execute([$v['reativar']]); else $insGx->execute([$v['g'], $v['u']]); }
                    $desGx = $pdo->prepare("UPDATE $D.responsabilidade_grupoxusuario SET ATIVO = 0 WHERE CODGRUPOXUSUARIO = ?");
                    foreach ($gx['desvincular'] as $idGx) $desGx->execute([$idGx]);
                    $limpa = $pdo->prepare("DELETE FROM $D.usuariosxgrupos_sinc_base WHERE codusuario = ?");
                    foreach (array_merge($gx['usuarios'], $sairDaBase) as $u) $limpa->execute([$u]);
                    $insBase = $pdo->prepare("INSERT IGNORE INTO $D.usuariosxgrupos_sinc_base (codusuario, codgrupo) VALUES (?, ?)");
                    foreach ($gx['base'] as [$u, $g]) $insBase->execute([$u, $g]);
                }
                $ins = $pdo->prepare("INSERT INTO $D.usuarios_sinc_base (codusuario, hashes) VALUES (?, ?) ON DUPLICATE KEY UPDATE hashes = VALUES(hashes)");
                foreach ($plano['base'] as $id => $h) $ins->execute([$id, json_encode($h)]);
                if ($sairDaBase) { $del = $pdo->prepare("DELETE FROM $D.usuarios_sinc_base WHERE codusuario = ?"); foreach ($sairDaBase as $id) $del->execute([$id]); }
                $pdo->commit();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            return $r;
        } catch (Throwable $e) {
            return $r + ['erro' => $e->getMessage()];
        } finally {
            self::valor($pdo, 'SELECT RELEASE_LOCK(?)', ['sinc_usuarios_' . $destino]);
        }
    }

    /** Mesmo código de perfil da migração geral (migrar-seguranca-generico.php::perfilCodigoOficial). */
    public static function perfilCodigo(string $nome): string
    {
        $v = function_exists('iconv') ? (string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome) : $nome;
        $v = preg_replace('/[^A-Z0-9]+/', '_', strtoupper(trim($v))) ?: 'GRUPO';
        return ['ADMINISTRADOR' => 'ADMINISTRADOR', 'ADM_DA_EMPRESA' => 'ADM_DA_EMPRESA', 'ADM_EMPRESA' => 'ADM_DA_EMPRESA'][$v] ?? $v;
    }

    private static function hashes(array $l): array
    {
        $h = [];
        foreach (self::CAMPOS as $c) $h[$c] = md5((string)($l[$c] ?? ''));
        return $h;
    }

    private static function data(mixed $v): ?string
    {
        $v = trim((string)$v);
        if ($v === '' || str_starts_with($v, '0000')) return null;
        $t = strtotime($v);
        return $t ? date('Y-m-d H:i:s', $t) : null;
    }

    private static function tabela(PDO $pdo, string $db, string $t): bool
    {
        return (int)self::valor($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'", [$db, $t]) > 0;
    }

    /** @return array<string,string> minúsculas => nome real */
    private static function colunas(PDO $pdo, string $db, string $t): array
    {
        $o = [];
        foreach (self::linhas($pdo, 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db, $t]) as $c) $o[strtolower((string)$c['COLUMN_NAME'])] = (string)$c['COLUMN_NAME'];
        return $o;
    }

    private static function pick(array $cols, array $nomes): ?string
    {
        foreach ($nomes as $n) if (isset($cols[$n])) return $n;
        return null;
    }

    public static function q(string $n): string { return '`' . str_replace('`', '``', $n) . '`'; }
    private static function linhas(PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC); $s->closeCursor(); return $r; }
    private static function valor(PDO $pdo, string $sql, array $p = []): mixed { $r = self::linhas($pdo, $sql, $p); return $r ? array_values($r[0])[0] : null; }
}
