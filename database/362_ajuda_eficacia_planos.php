<?php
declare(strict_types=1);

// Migração 362 — Ajuda da avaliação da eficácia nos planos de ação (trecho entre <!-- ml362 --> no fim do texto atual).
// Só as marcas aceitas pela Ajuda: p, ul/ol/li, strong/b, em/i e a.
$L = static fn(string $pt, string $en, string $es): array => ['pt' => $pt, 'en' => $en, 'es' => $es];
$pt = '<p><strong>Avaliação da eficácia</strong></p>'
    . '<p>As últimas colunas do plano de ação dizem se a ação precisa ter a eficácia avaliada:</p><ul>'
    . '<li><strong>Avaliar eficácia?</strong> Sim ou Não (padrão Não);</li>'
    . '<li><strong>Avaliador:</strong> quem avalia. Vazio = o Responsável do registro (da condicionante ou do fornecedor); sem ninguém, o responsável da ação;</li>'
    . '<li>Quando a ação marcada chega a <strong>100%</strong>, o avaliador recebe um alerta e a pendência <em>Avaliar eficácia</em> aparece em Minhas pendências, no sino e em Minhas atividades › Ações;</li>'
    . '<li>O avaliador registra <strong>Eficácia</strong> (Eficaz ou Não eficaz), a <strong>Data da avaliação</strong> (vazia = hoje) e a <strong>Observação</strong>. Só o avaliador, o ADMINISTRADOR e o ADM DA EMPRESA podem registrar, e só com a ação a 100%;</li>'
    . '<li><strong>Não eficaz</strong> reabre a ação (volta para 75%) e o resultado continua aparecendo; quando a ação chegar de novo a 100%, a avaliação fica pendente outra vez;</li>'
    . '<li>A coluna <em>Situação da eficácia</em> resume: Aguardando a ação chegar a 100%, Avaliar eficácia, Eficaz em … ou Não eficaz em … (ação reaberta). Toda avaliação fica guardada no histórico.</li></ul>'
    . '<p>Enquanto o sistema antigo ainda for sincronizado, ele pode alterar ou excluir ações do plano; a marcação e o resultado da eficácia só existem no portal.</p>';
$en = '<p><strong>Effectiveness review</strong></p><p>The last columns of the action plan say whether the action needs an effectiveness review: <strong>Review effectiveness?</strong> (Yes/No) and <strong>Reviewer</strong> (empty = the record owner; with nobody, the action owner). When a flagged action reaches <strong>100%</strong>, the reviewer is alerted and a pending item appears. The reviewer records <strong>Effective</strong> or <strong>Not effective</strong>, date and note (only the reviewer or an admin, only at 100%). Not effective reopens the action (back to 75%); when it reaches 100% again, a new review is pending. Every review is kept in the history.</p>';
$es = '<p><strong>Evaluación de la eficacia</strong></p><p>Las últimas columnas del plan de acción indican si la acción necesita evaluación de eficacia: <strong>¿Evaluar eficacia?</strong> (Sí/No) y <strong>Evaluador</strong> (vacío = el Responsable del registro; sin nadie, el responsable de la acción). Cuando la acción marcada llega al <strong>100%</strong>, el evaluador recibe una alerta y aparece la pendencia. El evaluador registra <strong>Eficaz</strong> o <strong>No eficaz</strong>, fecha y observación (solo el evaluador o un administrador, solo al 100%). No eficaz reabre la acción (vuelve al 75%); al llegar de nuevo al 100%, la evaluación queda pendiente otra vez. Toda evaluación queda en el historial.</p>';
return [
    'liPlanodeAcao' => ['titulo' => $L('Plano de ação da condicionante', 'Condition action plan', 'Plan de acción de la condicionante'), 'texto' => $L($pt, $en, $es)],
    'homologacaofornecedor_fornecedorxplanodeacao' => ['titulo' => $L('Plano de ação do fornecedor', 'Supplier action plan', 'Plan de acción del proveedor'), 'texto' => $L($pt, $en, $es)],
];
