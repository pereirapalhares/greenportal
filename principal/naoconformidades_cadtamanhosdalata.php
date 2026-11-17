<?php
declare(strict_types=1);

// Não Conformidades › Cadastros › Tamanhos de lata (menu NAO_CONFORMIDADES_CAD_TAMANHOS_LATA, tabela nc_tamanho_da_lata; migração 327).
// Registros do sistema anterior só para consulta até a virada; os novos ficam na faixa do greenportal (classes/NaoConformidadeCadastros.php).
require_once dirname(__DIR__) . "/classes/NaoConformidadeCadastros.php";
NaoConformidadeCadastros::tela("tamanhos_lata");
