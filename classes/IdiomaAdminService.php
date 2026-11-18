<?php
declare(strict_types=1);
require_once __DIR__ . '/AdministracaoTextos.php';

require_once __DIR__ . '/IdiomaService.php';

/**
 * Administração › Textos e idiomas: leitura pelas views vw_idioma_* e gravação pelas procedures sp_idioma_*
 * (guniversal_dbp). Os textos valem para todas as empresas; a edição acontece no rascunho e só chega às
 * telas depois de "Publicar".
 */
final class IdiomaAdminService
{
    public const MAX_TEXTO = 1000;
    public const MAX_ITENS = 2000;

    public function __construct(private readonly PDO $universal)
    {
    }

    /** Traz para o grupo MENUS os menus novos e os nomes alterados direto na tabela menus. */
    public function sincronizarMenus(string $usuario): void
    {
        try {
            GreenPortalDatabase::call($this->universal, 'CALL sp_idioma_sincronizar_menus(?)', [mb_substr($usuario ?: 'Sincronização', 0, 150)]);
        } catch (Throwable $e) {
            error_log('IdiomaAdminService::sincronizarMenus: ' . $e->getMessage());
        }
    }

    public function grupos(): array
    {
        $rows = $this->universal->query('SELECT codgrupo, nome_pt, nome_en, nome_es, transacao, ordem, qtd_textos FROM vw_idioma_grupos ORDER BY ordem, nome_pt')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $r): array => [
            'codgrupo' => (string)$r['codgrupo'],
            'nome' => ['pt' => (string)$r['nome_pt'], 'en' => (string)($r['nome_en'] ?: $r['nome_pt']), 'es' => (string)($r['nome_es'] ?: $r['nome_pt'])],
            'transacao' => (string)($r['transacao'] ?? ''), 'qtd' => (int)$r['qtd_textos'],
        ], $rows);
    }

    public function textos(): array
    {
        $rows = $this->universal->query('SELECT codtexto, chave, codgrupo, tipo, descricao, pt, en, es, pt_revisao, en_revisao, es_revisao,
                pt_publicado, en_publicado, es_publicado, alterado_em, alterado_por, pendente, faltando
            FROM vw_idioma_textos_admin ORDER BY grupo_ordem, codgrupo, ordem, chave')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static function (array $r): array {
            $item = [
                'codtexto' => (int)$r['codtexto'], 'chave' => (string)$r['chave'], 'grupo' => (string)$r['codgrupo'],
                'tipo' => (string)$r['tipo'], 'descricao' => (string)($r['descricao'] ?? ''),
                'alterado_em' => (string)($r['alterado_em'] ?? ''), 'alterado_por' => (string)($r['alterado_por'] ?? ''),
                'pendente' => (bool)$r['pendente'], 'faltando' => (bool)$r['faltando'],
                'texto' => [], 'revisao' => [], 'publicado' => [],
            ];
            foreach (IdiomaService::IDIOMAS as $idioma) {
                $item['texto'][$idioma] = (string)($r[$idioma] ?? '');
                $item['revisao'][$idioma] = (int)($r[$idioma . '_revisao'] ?? 0);
                $item['publicado'][$idioma] = $r[$idioma . '_publicado'] === null ? null : (string)$r[$idioma . '_publicado'];
            }
            return $item;
        }, $rows);
    }

    public function situacao(): array
    {
        $r = $this->universal->query('SELECT numero_publicado, publicado_em, publicado_por, pendentes FROM vw_idioma_situacao')->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'numero' => isset($r['numero_publicado']) ? (int)$r['numero_publicado'] : null,
            'publicado_em' => (string)($r['publicado_em'] ?? ''), 'publicado_por' => (string)($r['publicado_por'] ?? ''),
            'pendentes' => (int)($r['pendentes'] ?? 0),
        ];
    }

    public function publicacoes(): array
    {
        $rows = $this->universal->query('SELECT codpublicacao, numero, origem, observacao, qtd_alteracoes, publicado_por, publicado_banco, publicado_em, vigente FROM vw_idioma_publicacoes ORDER BY numero DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $r): array => [
            'codpublicacao' => (int)$r['codpublicacao'], 'numero' => (int)$r['numero'], 'origem' => (string)$r['origem'],
            'observacao' => (string)($r['observacao'] ?? ''), 'qtd' => (int)$r['qtd_alteracoes'],
            'publicado_por' => (string)($r['publicado_por'] ?? ''), 'publicado_banco' => (string)($r['publicado_banco'] ?? ''),
            'publicado_em' => (string)$r['publicado_em'], 'vigente' => (bool)$r['vigente'],
        ], $rows);
    }

    public function itensPublicacao(int $codpublicacao): array
    {
        $stmt = $this->universal->prepare('SELECT chave, codgrupo, descricao, codidioma, texto_anterior, texto_novo FROM vw_idioma_publicacoes_itens WHERE codpublicacao = ? ORDER BY codgrupo, chave, FIELD(codidioma, \'pt\', \'en\', \'es\')');
        $stmt->execute([$codpublicacao]);
        return array_map(static fn(array $r): array => [
            'chave' => (string)$r['chave'], 'grupo' => (string)$r['codgrupo'], 'descricao' => (string)($r['descricao'] ?? ''),
            'idioma' => (string)$r['codidioma'], 'anterior' => $r['texto_anterior'] === null ? null : (string)$r['texto_anterior'], 'novo' => (string)$r['texto_novo'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** $itens: [['codtexto' => 1, 'idioma' => 'en', 'texto' => '...', 'revisao' => 3], ...] → revisões novas. */
    public function salvar(array $itens, array $autor): array
    {
        if (!$itens) throw new InvalidArgumentException(adm_t('Nenhuma alteração para salvar.'));
        if (count($itens) > self::MAX_ITENS) throw new InvalidArgumentException(adm_t('Alterações demais em uma única gravação.'));
        $normalizados = [];
        foreach ($itens as $item) {
            if (!is_array($item)) throw new InvalidArgumentException(adm_t('Alteração inválida.'));
            $codtexto = (int)($item['codtexto'] ?? 0);
            $idioma = strtolower((string)($item['idioma'] ?? ''));
            if ($codtexto <= 0 || !in_array($idioma, IdiomaService::IDIOMAS, true)) throw new InvalidArgumentException(adm_t('Alteração inválida.'));
            $texto = self::normalizarTexto((string)($item['texto'] ?? ''));
            if ($idioma === 'pt' && $texto === '') throw new InvalidArgumentException(adm_t('O texto em português é obrigatório.'));
            $normalizados[$codtexto . '|' . $idioma] = ['codtexto' => $codtexto, 'idioma' => $idioma, 'texto' => $texto, 'revisao' => max(0, (int)($item['revisao'] ?? 0))];
        }
        $rows = GreenPortalDatabase::call($this->universal, 'CALL sp_idioma_salvar_rascunho(?, ?, ?, ?)', [
            json_encode(array_values($normalizados), JSON_UNESCAPED_UNICODE), $autor['nome'], $autor['codusuario'] ?: null, $autor['banco'],
        ]);
        return array_map(static fn(array $r): array => ['codtexto' => (int)$r['codtexto'], 'idioma' => (string)$r['codidioma'], 'revisao' => (int)$r['revisao']], $rows);
    }

    public function publicar(string $observacao, array $autor): array
    {
        $observacao = mb_substr(trim(strip_tags($observacao)), 0, 500);
        $row = GreenPortalDatabase::call($this->universal, 'CALL sp_idioma_publicar(?, ?, ?, ?)', [$observacao, $autor['nome'], $autor['codusuario'] ?: null, $autor['banco']])[0] ?? null;
        if (!$row) throw new RuntimeException(adm_t('Publicação não registrada.'));
        IdiomaService::limparCache();
        return ['codpublicacao' => (int)$row['codpublicacao'], 'numero' => (int)$row['numero'], 'qtd' => (int)$row['qtd_alteracoes'], 'publicado_em' => (string)$row['publicado_em']];
    }

    /** $codtextos: lista de códigos, ou null para descartar todo o rascunho. */
    public function descartar(?array $codtextos, array $autor): int
    {
        $lista = $codtextos === null ? null : json_encode(array_values(array_unique(array_filter(array_map('intval', $codtextos), static fn(int $v): bool => $v > 0))));
        if ($lista === '[]') throw new InvalidArgumentException(adm_t('Nenhum texto selecionado.'));
        $row = GreenPortalDatabase::call($this->universal, 'CALL sp_idioma_descartar_rascunho(?, ?, ?, ?)', [$lista, $autor['nome'], $autor['codusuario'] ?: null, $autor['banco']])[0] ?? [];
        return (int)($row['descartados'] ?? 0);
    }

    /** Texto simples: sem tags, sem quebras de linha nem espaços nas pontas. */
    public static function normalizarTexto(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $texto);
        $texto = trim((string)preg_replace('/\s{2,}/u', ' ', $texto));
        if (preg_match('/[<>]/', $texto)) throw new InvalidArgumentException((adm_t('Os textos não podem conter os caracteres') . ' < e >.'));
        if (mb_strlen($texto) > self::MAX_TEXTO) throw new InvalidArgumentException(adm_t('Texto com mais de ') . self::MAX_TEXTO . ' caracteres.');
        return $texto;
    }

    /** [status HTTP, mensagem para o usuário] */
    public static function mensagemErro(Throwable $e): array
    {
        $mensagem = $e->getMessage();
        if ($e instanceof InvalidArgumentException) return [422, $mensagem];
        if (str_contains($mensagem, 'CONFLITO_REVISAO')) return [409, adm_t('Outro administrador alterou estes textos enquanto você editava. Recarregue a tela; suas alterações não foram gravadas.')];
        if (str_contains($mensagem, 'SEM_ALTERACOES')) return [409, adm_t('Não há alterações no rascunho para publicar.')];
        if (preg_match('/1644\s+(.+)$/u', $mensagem, $m)) return [422, IdiomaService::mensagemBanco(trim($m[1]))];
        error_log('IdiomaAdminService: ' . $mensagem);
        return [500, adm_t('Não foi possível concluir a operação.')];
    }
}
