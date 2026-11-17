<?php
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    session_start();
    if (empty($_SESSION['autenticado'])) {
        header('Location: ../index.php?erro=sessao_expirada');
        exit;
    }
    http_response_code(404);
    exit('Componente de transação não pode ser acessado diretamente.');
}

require_once dirname(__DIR__) . '/classes/IdiomaService.php';

if (!function_exists('greenPortalPopupTransacao')) {
    function greenPortalPopupTransacao(string $id = 'greenPortalTransacaoModal'): string
    {
        $safeId = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        $t = static fn(string $chave, array $padrao): string => htmlspecialchars(IdiomaService::t($chave, $padrao), ENT_QUOTES, 'UTF-8');
        $fechar = $t('portal.js.fechar', ['pt' => 'Fechar', 'en' => 'Close', 'es' => 'Cerrar']);
        return '<div class="modal fade green-portal-popup-transacao" id="' . $safeId . '" tabindex="-1" aria-hidden="true" data-popup-transacao><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content" style="height:90vh"><div class="modal-header py-2"><h5 class="modal-title" data-popup-transacao-title>' . $t('portal.js.transacao', ['pt' => 'Transação', 'en' => 'Transaction', 'es' => 'Transacción']) . '</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="' . $fechar . '" title="' . $fechar . '"></button></div><div class="modal-body p-0" style="min-height:0;flex:1" data-popup-transacao-body></div></div></div></div>';
    }
}
