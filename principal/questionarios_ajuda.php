<?php
declare(strict_types=1);

// Ajuda de Administração › Questionários: versões universais e por empresa, merge e respostas por versão (migração 291).
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['autenticado'])) { header('Location: ../index.php?erro=sessao_expirada'); exit; }
session_write_close();
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Questionários — ajuda</title>
<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css?v=2">
<link rel="stylesheet" href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=2">
<link rel="stylesheet" href="../assets/css/app.css?v=4"><link rel="stylesheet" href="../assets/css/main.css?v=18">
<style>
body{background:#f7faf8;color:#23332c}
.ajuda{max-width:980px;margin:0 auto;padding:22px 26px 40px;font-size:14px;line-height:1.55}
.ajuda h1{font-size:20px;font-weight:700;margin:0 0 4px}
.ajuda h2{font-size:15px;font-weight:700;margin:26px 0 8px;color:#245846;display:flex;align-items:center;gap:8px}
.ajuda h2 .num{width:24px;height:24px;display:grid;place-items:center;border-radius:50%;background:#3ba179;color:#fff;font-size:12px}
.ajuda .lead{color:#5e776d;font-size:14px}
.ajuda .card-ajuda{background:#fff;border:1px solid #dfe7e3;border-radius:10px;padding:14px 16px;margin:10px 0}
.ajuda table{font-size:13px;background:#fff}
.ajuda th{color:#5e776d;font-weight:600}
.fluxo{display:flex;flex-wrap:wrap;align-items:stretch;gap:8px;margin:10px 0}
.fluxo .passo{flex:1 1 160px;background:#fff;border:1px solid #dfe7e3;border-radius:10px;padding:10px 12px;font-size:13px}
.fluxo .passo strong{display:block;color:#245846;margin-bottom:2px}
.fluxo .seta{align-self:center;color:#9ab3a8}
.tag{display:inline-block;padding:1px 8px;border-radius:999px;font-size:12px;font-weight:600}
.tag-u{background:#e5efff;color:#2c5aa0}.tag-e{background:#e3f4ee;color:#246b50}.tag-r{background:#fff3cd;color:#8a5a00}.tag-p{background:#d9f2e4;color:#1f7a4d}
.ajuda code{color:#7a3e9d}
</style>
</head>
<body>
<main class="ajuda">
  <h1><i class="bi bi-ui-checks-grid me-2"></i>Questionários: versões universais e por empresa</h1>
  <p class="lead">Como os questionários Legal, Fornecedores e Perfil de atribuições são alterados, publicados e usados por cada empresa.</p>

  <h2><span class="num">1</span>Quem altera o quê</h2>
  <div class="card-ajuda">
    <table class="table table-sm mb-0">
      <thead><tr><th>Perfil</th><th>Altera</th><th>Vale para</th></tr></thead>
      <tbody>
        <tr><td><strong>ADMINISTRADOR</strong></td><td>A versão <span class="tag tag-u">universal</span> do questionário</td><td>Todas as empresas que não têm versão própria</td></tr>
        <tr><td><strong>ADM DA EMPRESA</strong><br><small class="text-muted">ou outro perfil com Editar em Questionários</small></td><td>A versão <span class="tag tag-e">da empresa</span>, criada a partir da versão em uso</td><td>Só a empresa a que ele pertence</td></tr>
        <tr><td>Demais perfis com acesso à tela</td><td>Nada: só consultam</td><td>—</td></tr>
      </tbody>
    </table>
  </div>
  <p>No alto de cada aba aparece qual versão você está alterando e qual versão a empresa usa hoje.</p>

  <h2><span class="num">2</span>Como alterar um questionário</h2>
  <div class="fluxo">
    <div class="passo"><strong>Nova versão</strong>Clique em <em>Nova versão universal</em> (ADMINISTRADOR) ou <em>Criar versão da empresa</em> (ADM DA EMPRESA). O sistema cria um <span class="tag tag-r">rascunho</span> copiando a versão em uso.</div>
    <div class="seta"><i class="bi bi-arrow-right"></i></div>
    <div class="passo"><strong>Alterar</strong>Inclua, edite ou exclua perguntas, opções de resposta e o bloco de cada pergunta. Só o rascunho pode ser alterado.</div>
    <div class="seta"><i class="bi bi-arrow-right"></i></div>
    <div class="passo"><strong>Publicar</strong>Clique em <i class="bi bi-cloud-arrow-up"></i> <em>Publicar</em>. A versão vira <span class="tag tag-p">publicada</span> e passa a valer. Ela não muda mais.</div>
  </div>
  <ul>
    <li>Uma versão publicada nunca é alterada nem apagada. Para mudar, crie uma nova versão.</li>
    <li><em>Descartar rascunho</em> arquiva o rascunho, que continua no <em>Histórico</em>.</li>
    <li>A versão da empresa recebe o próximo número. Exemplo: a empresa usa a universal v1 e cria a sua v2, que só vale para ela.</li>
  </ul>

  <h2><span class="num">3</span>Qual versão cada empresa usa</h2>
  <div class="card-ajuda">
    A empresa usa <strong>a versão publicada dela</strong>. Se não tiver versão própria, usa <strong>a versão universal publicada</strong>.
  </div>

  <h2><span class="num">4</span>Nova versão universal: o merge com a versão da empresa</h2>
  <p>Quando o ADMINISTRADOR publica uma nova versão universal, cada empresa que tem versão própria recebe automaticamente uma nova versão, que junta as duas (<em>merge</em>). A comparação é feita pelo <strong>código</strong> de cada pergunta, bloco e regra, olhando o que mudou desde a versão universal de onde a versão da empresa veio:</p>
  <table class="table table-sm">
    <thead><tr><th>Situação da pergunta</th><th>Resultado na empresa</th></tr></thead>
    <tbody>
      <tr><td>Nova na universal</td><td>Entra na versão da empresa</td></tr>
      <tr><td>Alterada só na universal</td><td>Fica a da universal</td></tr>
      <tr><td>Alterada só pela empresa</td><td>Fica a da empresa</td></tr>
      <tr><td>Alterada pelas duas, de forma diferente</td><td>Fica a da empresa e vira <strong>conflito</strong> para revisão</td></tr>
      <tr><td>Retirada da universal, sem alteração da empresa</td><td>Sai da versão da empresa</td></tr>
      <tr><td>Retirada da universal, mas alterada pela empresa</td><td>Continua (aparece como aviso)</td></tr>
      <tr><td>Criada ou retirada pela empresa</td><td>Continua como a empresa deixou</td></tr>
    </tbody>
  </table>
  <p>As opções de resposta fazem parte da pergunta: mudar uma opção conta como alterar a pergunta.</p>
  <div class="card-ajuda">
    <strong>Sem conflito</strong>: a nova versão da empresa já é <span class="tag tag-p">publicada</span>.<br>
    <strong>Com conflito</strong>: ela fica em <span class="tag tag-r">rascunho</span>. O ADM DA EMPRESA abre a aba e, para cada conflito, escolhe <em>Manter da empresa</em> ou <em>Usar o universal</em>. Depois publica. Enquanto houver conflito aberto, a publicação fica bloqueada, e a empresa continua usando a versão anterior.<br>
    <strong>Rascunho em edição</strong>: se a empresa estava editando um rascunho, o merge usa esse rascunho. O resultado vira um novo rascunho, e o anterior fica arquivado no histórico.
  </div>

  <h2><span class="num">5</span>Quem já respondeu</h2>
  <p>Cada resposta fica gravada com a versão do questionário que foi respondida.</p>
  <ul>
    <li><strong>Exemplo:</strong> José respondeu o Perfil de atribuições na versão 1. Depois, o ADM DA EMPRESA publicou a versão 2.
      <ul>
        <li>O gestor do José, ao consultar, vê <strong>as perguntas e respostas da versão 1</strong>, exatamente como o José respondeu, com a indicação de que existe uma versão mais nova.</li>
        <li>O perfil aprovado do José <strong>continua valendo</strong>. Ninguém precisa responder de novo só porque saiu uma versão nova.</li>
        <li>Quando o José responder de novo (revisão periódica ou devolução do gestor), ele recebe a versão vigente. As respostas das perguntas que continuam com o mesmo código já vêm preenchidas.</li>
      </ul>
    </li>
  </ul>

  <h2><span class="num">6</span>Cuidados com os códigos</h2>
  <ul>
    <li>O <strong>código</strong> liga a pergunta entre as versões, nas respostas e no merge. Por isso, o código de uma pergunta que já existe em outra versão não pode ser alterado. O texto pode.</li>
    <li>No Perfil de atribuições, os códigos <code>&lt;BLOCO&gt;.PILAR.&lt;ATIVIDADE&gt;</code>, <code>.TEMAS</code>, <code>.CATEGORIAS</code> e <code>GERAL.UNIDADES</code> alimentam a indicação de responsáveis pelas tarefas. Eles são fixos.</li>
    <li>Nas perguntas de atividade (pilar), mantenha as opções <code>EXECUTA</code>, <code>APROVA</code> e <code>ACOMPANHA</code>.</li>
    <li>Pergunta nova: use um código novo, só com letras, números, <code>_</code> e <code>.</code>.</li>
  </ul>
</main>
</body>
</html>
