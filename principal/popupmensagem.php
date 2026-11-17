<?php
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    session_start();
    if (empty($_SESSION['autenticado'])) {
        header('Location: ../index.php?erro=sessao_expirada');
        exit;
    }
    http_response_code(404);
    exit('Componente de mensagem não pode ser acessado diretamente.');
}

require_once dirname(__DIR__) . '/classes/IdiomaService.php';

if (!function_exists('greenPortalPopupMensagem')) {
    function greenPortalPopupMensagem(string $id = 'greenPortalMensagemModal'): string
    {
        $safeId = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        $t = static fn(string $chave, array $padrao): string => htmlspecialchars(IdiomaService::t($chave, $padrao), ENT_QUOTES, 'UTF-8');
        $fechar = $t('portal.js.fechar', ['pt' => 'Fechar', 'en' => 'Close', 'es' => 'Cerrar']);
        return '<div class="modal fade green-portal-popup-mensagem" id="' . $safeId . '" tabindex="-1" aria-hidden="true" data-popup-mensagem><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header py-2"><h5 class="modal-title"><i class="bi bi-info-circle me-2" data-popup-mensagem-icon></i><span data-popup-mensagem-title>' . $t('portal.js.mensagem', ['pt' => 'Mensagem', 'en' => 'Message', 'es' => 'Mensaje']) . '</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="' . $fechar . '"></button></div><div class="modal-body" data-popup-mensagem-body></div><div class="modal-footer py-2"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">' . $fechar . '</button><button type="button" class="btn btn-sm btn-success d-none" data-popup-mensagem-confirm>' . $t('comum.botao.confirmar', ['pt' => 'Confirmar', 'en' => 'Confirm', 'es' => 'Confirmar']) . '</button></div></div></div></div>';
    }
}
