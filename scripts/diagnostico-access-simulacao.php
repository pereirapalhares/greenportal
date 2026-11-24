<?php
declare(strict_types=1);

/*
 * Diagnóstico da simulação da Atualização do Access (24/11/2026): compara, para cada .mdb de unidade enviado,
 * as leis do .mdb com os vínculos da unidade no portal (legislacaoxempresa) e o controle (legislacaoxpoder).
 * Só leitura: o .mdb é lido numa cópia. Grava storage/diag-access-simulacao.json.
 * Uso: php scripts\diagnostico-access-simulacao.php        Temporário: apague depois (com o .json).
 */

require_once dirname(__DIR__) . '/classes/AccessAtualizacao.php';
@set_time_limit(0);

function norm(string $c): string { return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($c))); }

$saida = ['gerado' => date('Y-m-d H:i:s'), 'unidades' => []];
try {
    $leg = LegislacaoImportacao::legislacao();
    $idCod = []; $codId = []; $codInfo = [];
    foreach ($leg->query('SELECT CODLEGISLACAO, CODIGO, TIPO, NR, ANO FROM vw_legislacao_access') as $r) {
        $c = strtoupper(trim((string)$r['CODIGO']));
        $idCod[(int)$r['CODLEGISLACAO']] = $c; $codId[$c] = (int)$r['CODLEGISLACAO'];
        $codInfo[$c] = trim($r['TIPO'] . ' ' . $r['NR'] . '/' . $r['ANO']);
    }
    $saida['leis_portal'] = count($idCod);
    $arqs = AccessAtualizacao::arquivos();
    $migradas = LegislacaoEmpresas::migradas();
    foreach ($arqs['UNIDADES'] as $codemp => $lista) {
        $pdo = LegislacaoEmpresas::pdo((int)$codemp);
        $vinc = LegislacaoEmpresas::ler($pdo, 'legislacaoxempresa', ['CODEMPRESA' => (int)$codemp]);
        $poder = LegislacaoEmpresas::ler($pdo, 'legislacaoxpoder');
        $colsVinc = $vinc ? array_keys($vinc[0]) : [];
        foreach ($lista as $codun => $arq) {
            $d = ['empresa' => $migradas[$codemp]['nome'] ?? $codemp, 'codunidade' => $codun, 'arquivo' => $arq['nome_arquivo'], 'colunas_vinculo' => $colsVinc];
            // vínculos da unidade
            $vUn = []; $vSemCodigo = 0; $vPorTipo = [];
            foreach ($vinc as $v) {
                $v = array_change_key_case($v, CASE_UPPER);
                if ((int)($v['CODUNIDADE'] ?? 0) !== (int)$codun) continue;
                $c = $idCod[(int)$v['CODLEGISLACAO']] ?? null;
                if ($c === null) { $vSemCodigo++; continue; }
                $t = strtoupper(trim((string)($v['TIPO_DE_LEI'] ?? '')));
                $vPorTipo[$t === '' ? '(vazio)' : $t] = ($vPorTipo[$t === '' ? '(vazio)' : $t] ?? 0) + 1;
                $vUn[$c] = $v;
            }
            $pUn = [];
            foreach ($poder as $v) {
                $v = array_change_key_case($v, CASE_UPPER);
                if ((int)($v['CODUNIDADE'] ?? 0) !== (int)$codun) continue;
                $c = $idCod[(int)$v['CODLEGISLACAO']] ?? null;
                if ($c !== null) $pUn[$c] = trim((string)($v['PODER'] ?? ''));
            }
            // .mdb (cópia)
            $copia = AccessAtualizacao::pasta('tmp') . DIRECTORY_SEPARATOR . 'diag_' . $codun . '_' . date('His') . '.mdb';
            copy(AccessAtualizacao::caminho($arq), $copia);
            $mdb = []; $mdbNorm = []; $mdbCtl = []; $mdbUn = [];
            try {
                $cn = AccessAtualizacao::abrir($copia, true);
                $rs = $cn->Execute('SELECT [N°], [DIRETA / INDIRETA], [DSCUNIDADE] FROM [Legislação Ambiental Aplicável]');
                while (!$rs->EOF) {
                    $c = strtoupper(trim((string)$rs->Fields(0)->Value));
                    if ($c !== '') { $mdb[$c] = true; $mdbNorm[norm($c)] = $c; $mdbCtl[$c] = trim((string)$rs->Fields(1)->Value); $u = trim((string)$rs->Fields(2)->Value); $mdbUn[$u] = ($mdbUn[$u] ?? 0) + 1; }
                    $rs->MoveNext();
                }
                $rs->Close(); $cn->Close();
            } finally { @unlink($copia); }
            $faltam = array_values(array_diff(array_keys($vUn), array_keys($mdb)));
            $soMdb = array_values(array_diff(array_keys($mdb), array_keys($vUn)));
            $quase = [];
            foreach ($faltam as $c) if (isset($mdbNorm[norm($c)])) $quase[] = $c . ' ~ ' . $mdbNorm[norm($c)];
            $mdbForaPortal = array_values(array_filter($soMdb, static fn($c) => !isset($codId[$c])));
            // letra de padrão: o código do vínculo termina em letra e o base está no .mdb
            $baseNoMdb = 0;
            foreach ($faltam as $c) if (preg_match('/^(.*\d)[A-Z]$/', $c, $m) && isset($mdb[$m[1]])) $baseNoMdb++;
            // anos dos que faltam
            $anos = [];
            foreach ($faltam as $c) { $a = preg_match('/(\d{4})\D*$/', $codInfo[$c] ?? '', $m) ? $m[1] : '?'; $dec = $a === '?' ? '?' : substr($a, 0, 3) . '0s'; $anos[$dec] = ($anos[$dec] ?? 0) + 1; }
            ksort($anos);
            $ctlDif = 0; $ctlIgual = 0; $ctlVazioMdb = 0; $exDif = [];
            foreach ($pUn as $c => $val) {
                if (!isset($mdb[$c])) continue;
                if (($mdbCtl[$c] ?? '') === '') $ctlVazioMdb++;
                if (mb_strtolower($mdbCtl[$c] ?? '') === mb_strtolower($val)) $ctlIgual++; else { $ctlDif++; if (count($exDif) < 15) $exDif[] = $c . ': mdb "' . ($mdbCtl[$c] ?? '') . '" → portal "' . $val . '"'; }
            }
            $d += [
                'leis_mdb' => count($mdb), 'dscunidade_no_mdb' => $mdbUn,
                'vinculos_portal_unidade' => count($vUn), 'vinculos_sem_codigo' => $vSemCodigo, 'vinculos_por_tipo' => $vPorTipo,
                'em_ambos' => count(array_intersect_key($vUn, $mdb)),
                'faltam_no_mdb' => count($faltam), 'faltam_exemplos' => array_map(static fn($c) => $c . ' (' . ($codInfo[$c] ?? '') . ')', array_slice($faltam, 0, 25)),
                'faltam_por_decada' => $anos, 'faltam_quase_iguais' => array_slice($quase, 0, 25), 'faltam_quase_iguais_total' => count($quase),
                'faltam_com_base_no_mdb' => $baseNoMdb,
                'so_no_mdb' => count($soMdb), 'so_no_mdb_exemplos' => array_slice($soMdb, 0, 25), 'so_no_mdb_fora_do_portal' => count($mdbForaPortal),
                'controle_portal_unidade' => count($pUn), 'controle_igual' => $ctlIgual, 'controle_diferente' => $ctlDif, 'controle_vazio_no_mdb' => $ctlVazioMdb, 'controle_diferente_exemplos' => $exDif,
            ];
            $saida['unidades'][] = $d;
        }
    }
    $saida['ok'] = true;
} catch (Throwable $e) {
    $saida['ok'] = false; $saida['erro'] = $e->getMessage();
}
$arquivo = dirname(__DIR__) . '/storage/diag-access-simulacao.json';
file_put_contents($arquivo, json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo 'gravado em storage/diag-access-simulacao.json' . PHP_EOL;
