===============================================================================
GREEN PORTAL - RELATORIO DE TESTE DE PROTECAO BASICA CONTRA ATAQUES
Ambiente testado: http://187.191.99.180/greenportal (instancia acessivel externamente)
Data do teste: 19/08/2026
Metodo: verificacao manual nao invasiva via navegador (Chrome), sem uso de
        ferramentas de exploracao automatizada, sem injecao de payloads e sem
        tentativa de acesso indevido a dados de outras empresas/usuarios.
        Login de teste usado: empresa "Modelo", usuario "admin" (credencial
        fornecida pelo solicitante, dono da aplicacao).
===============================================================================

AVISO IMPORTANTE SOBRE O ESCOPO DESTE TESTE
-------------------------------------------------------------------------------
Este relatorio cobre PROTECOES BASICAS de seguranca (exposicao de arquivos,
cabecalhos, transporte, autenticacao, gerenciamento de erros) usando apenas
tecnicas passivas/nao destrutivas. NAO foi realizado teste de invasao completo
(pentest) - nao foram testados, por exemplo, SQL injection com payloads reais,
XSS, escalonamento de privilegios entre empresas/tenants, nem forca bruta
extensiva. Para um ambiente que ja vai para producao com dados de compliance
legal de clientes, recomenda-se contratar um pentest formal (ou usar
ferramentas como OWASP ZAP) alem de aplicar as correcoes abaixo.

Como o ambiente de automacao usado aqui bloqueia deliberadamente a leitura de
cookies e de respostas HTTP brutas via JavaScript (protecao contra
exfiltracao de sessao), alguns itens (flags exatas dos cookies de sessao,
header Content-Security-Policy completo) nao puderam ser confirmados
tecnicamente nesta rodada - estao listados na secao 4 como "a verificar".

===============================================================================
1. CRITICO - CORRIGIR IMEDIATAMENTE
===============================================================================

1.1 Arquivo .env publicamente acessivel (credenciais de banco expostas)

  URL: http://187.191.99.180/greenportal/.env
  Status: acessivel sem autenticacao, retorna o conteudo completo do arquivo.

  O arquivo expõe, em texto puro: host, porta, nome do banco e USUARIO/SENHA
  de conexao para TODOS os bancos usados pela aplicacao (guniversal_dbp,
  gmodelo_dbp/GPT, bancos legados green_modelo e green_modelo_seguranca, e o
  banco de legislacao glegislacao_dbp). O usuario e "root" e a MESMA senha e
  reutilizada em todas as conexoes.

  Impacto: qualquer pessoa na internet que acesse essa URL obtem acesso
  administrativo completo (leitura, alteracao e exclusao) a todos os dados de
  todas as empresas cadastradas no portal, incluindo dados de compliance
  legal, usuarios e licencas. Este e o achado mais grave encontrado.

  Acao imediata recomendada:
    a) Trocar a senha do usuario "root" do MySQL agora (ela ja foi exposta e
       deve ser considerada comprometida) e, idealmente, parar de usar o
       usuario root para a aplicacao - criar um usuario de banco dedicado com
       privilegios minimos por banco.
    b) Bloquear o acesso HTTP a arquivos .env no servidor: no Apache, negar
       acesso a arquivos que comecem com "." (FilesMatch "^\.") no vhost ou
       via .htaccess na raiz da aplicacao, ou mover o .env para fora da pasta
       publica (webroot) do Apache.
    c) Revisar se ha logs de acesso do servidor mostrando se esse arquivo ja
       foi acessado por terceiros.

-------------------------------------------------------------------------------
1.2 Listagem de diretorios habilitada (Directory Listing / Apache "Indexes")

  Confirmado em varias pastas, por exemplo:
    http://187.191.99.180/greenportal/database/
    http://187.191.99.180/greenportal/classes/
    http://187.191.99.180/greenportal/tests/

  Isso expõe:
    - Todos os scripts de migracao SQL em /database/ (nomes descrevem
      inclusive a estrutura interna: criacao de empresas, procedures CRUD,
      menus de seguranca, chaves etc.) - um atacante consegue baixar cada
      script e reconstruir o schema completo do banco sem precisar de acesso
      direto a ele.
    - A lista completa de classes PHP do sistema em /classes/, facilitando
      mapear a superficie de ataque (nomes de arquivos como
      ExportacaoGridWorker, ModuloLegalRequisitosGrid etc.).
    - Os proprios relatorios de auditoria/diagnostico gerados durante o
      desenvolvimento (pasta /tests/), incluindo relatorios anteriores que
      documentam vulnerabilidades e pontos fracos do sistema - ou seja, um
      atacante poderia ler o "mapa" das fragilidades ja identificadas.

  Acao recomendada: desabilitar a listagem de diretorios no Apache
  (Options -Indexes no vhost, aplicavel a toda a aplicacao) e, adicionalmente,
  mover pastas que nao precisam ser publicas (database/, tests/, classes/ -
  esta ultima ja e protegida por logica de include, mas nao deveria estar
  dentro do webroot) para fora do diretorio servido publicamente pelo Apache.

===============================================================================
2. ALTO
===============================================================================

2.1 Aplicacao roda apenas em HTTP, sem HTTPS/TLS

  A porta 443 nao responde com um certificado valido (erro de privacidade ao
  tentar https://187.191.99.180/greenportal/). Toda a navegacao, incluindo o
  formulario de login (usuario, empresa e senha), trafega em texto puro pela
  rede. Qualquer pessoa na mesma rede, ou em um ponto intermediario, pode
  capturar credenciais e o cookie de sessao (ataque de rede/"man-in-the-
  middle").

  Acao recomendada: emitir um certificado TLS (Let's Encrypt e gratuito) para
  o dominio/IP publico, configurar o Apache para servir em HTTPS e redirecionar
  automaticamente HTTP -> HTTPS. Depois disso, ativar o cabecalho
  Strict-Transport-Security (HSTS) e marcar os cookies de sessao como Secure.

-------------------------------------------------------------------------------
2.2 Sem indicio de protecao contra forca bruta / credential stuffing no login

  Foram realizadas 5 tentativas de login consecutivas com senha incorreta
  para o usuario "admin" (empresa Modelo). Todas retornaram a mesma resposta
  imediata, sem atraso crescente, sem CAPTCHA e sem bloqueio temporario da
  conta ou do IP.

  Combinado com o item 2.1 (sem HTTPS), isso deixa a tela de login vulneravel
  a ataques automatizados de tentativa e erro (forca bruta ou credential
  stuffing com listas de senhas vazadas).

  Ponto positivo encontrado: a mensagem de erro e generica ("Nao foi possivel
  realizar o acesso. Confira a empresa, o usuario e a senha.") tanto para
  usuario inexistente quanto para senha incorreta - isso evita enumeracao de
  usuarios validos, o que e uma boa pratica ja presente.

  Acao recomendada: implementar limite de tentativas por usuario/IP (ex.: 5
  tentativas, depois bloqueio temporario crescente ou exigencia de CAPTCHA),
  e registrar tentativas de login malsucedidas para auditoria.

-------------------------------------------------------------------------------
2.3 Login sem token CSRF

  O formulario de login (POST para credenciais/logar.php) nao possui nenhum
  campo oculto de token CSRF - os unicos campos enviados sao empresa, userid,
  senha e o checkbox "lembrar". Isso e consistente com o achado ja registrado
  no relatorio de codigo anterior (ausencia de CSRF em varios pontos do
  sistema). No caso do login especificamente, isso permite login CSRF (um
  site malicioso forcar o navegador da vitima a autenticar em uma conta
  controlada pelo atacante, usada depois para rastrear atividade).

  Acao recomendada: aplicar a mesma correcao ja apontada no relatorio de
  ajustes de codigo - gerar e validar token CSRF tambem no formulario de
  login, alem das demais telas que realizam operacoes de escrita.

===============================================================================
3. MEDIO
===============================================================================

3.1 Divulgacao de versao do servidor (Server Banner)

  As paginas de erro padrao do Apache (404, testado em varias URLs)
  divulgam a stack completa de software e versoes:
    "Apache/2.4.65 (Win64) PHP/8.3.28 mod_fcgid/2.3.10-dev Server at
    187.191.99.180 Port 80"

  Isso facilita que um atacante pesquise vulnerabilidades conhecidas (CVEs)
  especificas dessas versoes exatas, reduzindo o esforco de reconhecimento.

  Acao recomendada: no Apache, configurar ServerSignature Off e ServerTokens
  Prod (ou Prod mais restrito) no httpd.conf, e criar paginas de erro 404/500
  customizadas para a aplicacao em vez de usar a pagina padrao do Apache.

-------------------------------------------------------------------------------
3.2 Ausencia de cabecalhos de seguranca HTTP (a confirmar/reforcar)

  Nao foi possivel inspecionar os cabecalhos HTTP brutos de resposta nesta
  rodada (ferramenta de automacao bloqueia leitura programatica de respostas
  por protecao de privacidade). Pela ausencia de meta tag de
  Content-Security-Policy no HTML e por nao ter sido observado nenhum
  comportamento de bloqueio de iframe/mixed content durante a navegacao,
  recomenda-se validar e, se ausentes, implementar os seguintes cabecalhos no
  Apache/PHP (podem ser adicionados via .htaccess/vhost, no mesmo padrao ja
  usado no arquivo assets/.htaccess do projeto, que hoje so cobre cache):
    - Content-Security-Policy (ao menos um CSP basico, restringindo scripts
      a origem propria)
    - X-Content-Type-Options: nosniff
    - X-Frame-Options: SAMEORIGIN (protege contra clickjacking, relevante
      pois o portal usa iframes internamente para as telas - confirmar que
      isso nao quebra o proprio uso interno de iframes)
    - Referrer-Policy: same-origin ou strict-origin-when-cross-origin

  Sugestao pratica: apos aplicar HTTPS (item 2.1), rodar uma checagem publica
  gratuita como securityheaders.com contra o dominio para confirmar quais
  cabecalhos estao de fato presentes e ajustar a partir do resultado.

===============================================================================
4. A VERIFICAR (nao confirmado tecnicamente nesta rodada)
-------------------------------------------------------------------------------

  - Atributos do cookie de sessao (HttpOnly, Secure, SameSite): a ferramenta
    de automacao usada bloqueia deliberadamente a leitura de cookies via
    JavaScript por seguranca, entao nao foi possivel confirmar programati-
    camente. Recomenda-se verificar diretamente no codigo (configuracao de
    session.cookie_httponly, session.cookie_secure e session.cookie_samesite
    no php.ini ou via session_set_cookie_params) e garantir httponly=true,
    samesite=Lax ou Strict, e secure=true assim que o HTTPS (item 2.1)
    estiver ativo.

  - Isolamento entre empresas/tenants (multitenancy): nao foi testado se um
    usuario autenticado em uma empresa consegue, por manipulacao de
    parametros, acessar dados de outra empresa (IDOR entre tenants). Dado que
    esse e o modelo central da aplicacao (um banco por empresa), este e um
    ponto que merece um teste dedicado, idealmente com duas empresas de teste
    distintas.

  - Rate limiting em endpoints de API/AJAX usados pelas grids (fora da tela
    de login) nao foi avaliado.

===============================================================================
5. OBSERVACOES / PONTOS POSITIVOS
-------------------------------------------------------------------------------

  - A mensagem de erro de login e generica e nao revela se o usuario existe
    ou nao (boa pratica contra enumeracao de usuarios).
  - O campo de senha usa type="password" e autocomplete="current-password"
    (comportamento padrao e aceitavel dos navegadores modernos).
  - As paginas de erro 404 nao vazam stack trace de PHP nem caminhos internos
    do servidor (apenas o banner de versao do Apache, ja tratado no item 3.1).
  - O portal usa o padrao PRG (Post/Redirect/Get) apos falha de login, sem
    colocar usuario/senha na URL.

===============================================================================
6. PROXIMOS PASSOS SUGERIDOS (EM ORDEM DE PRIORIDADE)
-------------------------------------------------------------------------------

  1. [CRITICO - AGORA] Trocar a senha do MySQL (root) e bloquear o acesso
     publico ao arquivo .env (item 1.1).

  2. [CRITICO - AGORA] Desabilitar listagem de diretorios no Apache e mover
     pastas nao publicas (database/, tests/) para fora do webroot (item 1.2).

  3. [ALTO] Configurar HTTPS/TLS com redirecionamento obrigatorio de HTTP
     para HTTPS (item 2.1).

  4. [ALTO] Implementar limite de tentativas de login / bloqueio temporario
     (item 2.2).

  5. [ALTO] Adicionar token CSRF ao formulario de login, alinhado com a
     correcao ja pendente nas demais telas (item 2.3, ja registrado no
     relatorio de ajustes de codigo anterior).

  6. [MEDIO] Ocultar a versao do Apache/PHP nas paginas de erro e customizar
     as paginas 404/500 (item 3.1).

  7. [MEDIO] Configurar cabecalhos de seguranca HTTP (CSP, X-Frame-Options,
     X-Content-Type-Options, Referrer-Policy) e validar com
     securityheaders.com apos o HTTPS estar ativo (item 3.2).

  8. [A PLANEJAR] Confirmar flags do cookie de sessao (item 4) e considerar
     um pentest formal focado em isolamento entre empresas (multitenancy)
     antes de expor a aplicacao de forma definitiva em producao.

===============================================================================
FIM DO RELATORIO
===============================================================================
