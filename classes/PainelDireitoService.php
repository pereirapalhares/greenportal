<?php
declare(strict_types=1);

final class PainelDireitoService
{
    public static function itens(string $baseUrl, string $idioma = 'pt'): array
    {
        $labels = [
            'pt' => ['pendencias' => 'Pendências', 'aprovacoes' => 'Aprovações', 'prazos' => 'Próximos prazos', 'ajuda' => 'Ajuda'],
            'en' => ['pendencias' => 'Pending items', 'aprovacoes' => 'Approvals', 'prazos' => 'Upcoming deadlines', 'ajuda' => 'Help'],
            'es' => ['pendencias' => 'Pendientes', 'aprovacoes' => 'Aprobaciones', 'prazos' => 'Próximos plazos', 'ajuda' => 'Ayuda'],
        ][$idioma] ?? [];
        return [
            ['codigo' => 'PENDENCIAS', 'icone' => 'bi-list-check', 'label' => $labels['pendencias'] ?? 'Pendências', 'badge' => 0, 'url' => $baseUrl . '/principal/painel_pendencias.php'],
            ['codigo' => 'APROVACOES', 'icone' => 'bi-check2-square', 'label' => $labels['aprovacoes'] ?? 'Aprovações', 'badge' => 0, 'url' => $baseUrl . '/principal/painel_aprovacoes.php'],
            ['codigo' => 'PRAZOS', 'icone' => 'bi-calendar-event', 'label' => $labels['prazos'] ?? 'Próximos prazos', 'badge' => 0, 'url' => $baseUrl . '/principal/painel_prazos.php'],
            ['codigo' => 'AJUDA', 'icone' => 'bi-question-circle', 'label' => $labels['ajuda'] ?? 'Ajuda', 'badge' => 0, 'url' => $baseUrl . '/principal/painel_ajuda.php'],
            ['codigo' => 'CALENDARIO', 'icone' => 'bi-calendar3', 'label' => $idioma === 'en' ? 'Today pending items' : ($idioma === 'es' ? 'Pendientes de hoy' : 'Pendências do dia'), 'badge' => (int)date('d'), 'calendar' => true, 'url' => $baseUrl . '/principal/painel_calendario.php'],
        ];
    }
}
