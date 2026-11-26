<?php
declare(strict_types=1);

require_once __DIR__ . '/GreenPortalDatabase.php';

/**
 * Mensagens entre a empresa e o fornecedor sobre um documento da homologação (migração 420; Revisão G itens 146 e 147).
 * Contextos: 'fornecedorcriterios' (documento do fornecedor, fo_fornecedorxcriterios) e 'funcionariocriterios'
 * (documento do funcionário, fo_funcionarioxtiposxcriterios). Tabela hom_chat_mensagem; leitura por vw_hom_chat_mensagem.
 * LADO = EMPRESA ou FORNECEDOR (quem escreveu); LIDA_EM marca a leitura pelo outro lado.
 * automatica(): a conversa começa sozinha quando a análise da IA reprova o documento (HomologacaoDocumentoIa).
 */
final class HomologacaoChat
{
    public const CONTEXTOS = ['fornecedorcriterios', 'funcionariocriterios'];

    public static function disponivel(PDO $pdo): bool
    {
        try { GreenPortalDatabase::call($pdo, 'CALL sp_crud_select(:t,:f,NULL,1,0)', ['t' => 'vw_hom_chat_mensagem', 'f' => '{}']); return true; } catch (Throwable) { return false; }
    }

    public static function mensagens(PDO $pdo, string $contexto, int $registro): array
    {
        $r = GreenPortalDatabase::call($pdo, 'CALL sp_crud_select(:t,:f,NULL,1000,0)', ['t' => 'vw_hom_chat_mensagem', 'f' => json_encode(['CONTEXTO' => ['value' => $contexto, 'exact' => true], 'CODREGISTRO' => ['value' => (string)$registro, 'exact' => true]])]);
        usort($r, static fn($a, $b) => (int)$a['CODMENSAGEM'] <=> (int)$b['CODMENSAGEM']);
        return $r;
    }

    public static function enviar(PDO $pdo, string $contexto, int $registro, int $usuario, string $lado, string $texto, bool $automatica = false): void
    {
        GreenPortalDatabase::call($pdo, 'CALL sp_crud_insert(:t,:d)', ['t' => 'hom_chat_mensagem', 'd' => json_encode([
            'CONTEXTO' => $contexto, 'CODREGISTRO' => $registro, 'CODUSUARIO' => $usuario ?: null, 'LADO' => $lado === 'FORNECEDOR' ? 'FORNECEDOR' : 'EMPRESA',
            'TEXTO' => mb_substr(trim($texto), 0, 4000), 'AUTOMATICA' => $automatica ? 1 : 0], JSON_UNESCAPED_UNICODE)]);
    }

    /** Exclui uma mensagem própria (mesmo usuário e lado) que o outro lado ainda não leu; automáticas não. */
    public static function excluir(PDO $pdo, string $contexto, int $registro, int $id, int $usuario, string $meuLado): bool
    {
        foreach (self::mensagens($pdo, $contexto, $registro) as $m) {
            if ((int)$m['CODMENSAGEM'] !== $id) continue;
            if ((string)$m['LADO'] !== $meuLado || (int)$m['CODUSUARIO'] !== $usuario || $usuario <= 0 || !empty($m['LIDA_EM']) || (int)$m['AUTOMATICA'] === 1) return false;
            GreenPortalDatabase::call($pdo, 'CALL sp_crud_delete(:t,:k)', ['t' => 'hom_chat_mensagem', 'k' => json_encode(['CODMENSAGEM' => $id])]);
            return true;
        }
        return false;
    }

    /** Marca como lidas as mensagens do outro lado. */
    public static function marcarLidas(PDO $pdo, string $contexto, int $registro, string $meuLado): void
    {
        foreach (self::mensagens($pdo, $contexto, $registro) as $m) {
            if ((string)$m['LADO'] === $meuLado || !empty($m['LIDA_EM'])) continue;
            GreenPortalDatabase::call($pdo, 'CALL sp_crud_update(:t,:k,:d)', ['t' => 'hom_chat_mensagem', 'k' => json_encode(['CODMENSAGEM' => (int)$m['CODMENSAGEM']]), 'd' => json_encode(['LIDA_EM' => date('Y-m-d H:i:s')])]);
        }
    }

    /** Não lidas (do outro lado) por registro: [registro => n]. */
    public static function naoLidas(PDO $pdo, string $contexto, array $registros, string $meuLado): array
    {
        $out = [];
        foreach (array_slice(array_values(array_unique(array_map('intval', $registros))), 0, 100) as $id) {
            if ($id <= 0) continue;
            $n = 0; $total = 0;
            foreach (self::mensagens($pdo, $contexto, $id) as $m) { $total++; if ((string)$m['LADO'] !== $meuLado && empty($m['LIDA_EM'])) $n++; }
            if ($total) $out[$id] = ['nao_lidas' => $n, 'total' => $total];
        }
        return $out;
    }

    /**
     * Revisão G item 146: a conversa começa sozinha quando a IA reprova o documento (não corresponde ao exigido, vencido,
     * dados de outra empresa/pessoa, pendências do modelo). Os avisos só de leitura por OCR não contam. Nunca quebra a análise.
     */
    public static function automatica(PDO $pdo, string $contexto, int $registro, array $alertas, string $arquivo): void
    {
        try {
            $serios = array_values(array_filter(array_map('strval', $alertas), static fn($a) => !preg_match('/\bOCR\b|omitid|omitted/i', $a)));
            if (!$serios || !self::disponivel($pdo)) return;
            $texto = "Análise automática do arquivo \"" . $arquivo . "\": o documento não foi aceito.\n- " . implode("\n- ", array_slice($serios, 0, 8))
                . "\nPor favor, envie o documento correto ou responda por aqui.";
            self::enviar($pdo, $contexto, $registro, 0, 'EMPRESA', $texto, true);
        } catch (Throwable $e) { error_log('HomologacaoChat::automatica: ' . $e->getMessage()); }
    }
}
