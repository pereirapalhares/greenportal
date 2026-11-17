const fs=require('fs'); const path=require('path');
const root=path.resolve(__dirname,'..'); const result={checks:[],status:'REPROVADO'};
function check(name,ok,details=''){result.checks.push({name,ok:Boolean(ok),details});console.log(`${ok?'PASS':'FAIL'} - ${name}${details?': '+details:''}`);if(!ok)throw new Error(name)}
function source(file){return fs.readFileSync(path.join(root,file),'utf8')}
try{
 const page=source('principal/servicos.php'); const menu=source('classes/MenuService.php'); const sql=source('database/153_servicos_gestao.sql');
 check('Transação de serviços existe',fs.existsSync(path.join(root,'principal/servicos.php')));
 check('Transação valida sessão e perfil',page.includes('UsuarioSessao::atualizar')&&page.includes("ADMINISTRADOR', 'ADM_DA_EMPRESA"));
 check('Administrador da plataforma possui controle global',page.includes('$isPlatformAdmin = $profile === \'ADMINISTRADOR\'')&&page.includes('if (!$isPlatformAdmin)'));
 check('Administrador da empresa não controla serviços',page.includes('Somente o ADMINISTRADOR pode controlar serviços'));
 check('Fila do administrador da empresa é limitada ao tenant',page.includes("$isPlatformAdmin ? $central->query")&&page.includes("'banco_dados'=>$tenantDb"));
 check('Menu Serviços está registrado',sql.includes("ADMINISTRACAO_SERVICOS")&&sql.includes('/principal/servicos.php'));
 check('Menu possui ícone representativo',menu.includes("'ADMINISTRACAO_SERVICOS' => 'bi-gear-wide-connected'"));
 check('Ações sensíveis exigem confirmação',page.includes("confirm('Confirmar ação no serviço selecionado?')"));
 check('Ações usam somente serviços permitidos',page.includes("$serviceMap =")&&page.includes("in_array($operation, ['start','stop','restart','process'], true)"));
 result.status='APROVADO';result.finishedAt=new Date().toISOString();
}catch(e){result.error=e.message;result.finishedAt=new Date().toISOString();console.error('ERRO FATAL: '+e.message);process.exitCode=1}
fs.writeFileSync(path.join(root,'tests','resultado-nao-visual-acesso-servicos.json'),JSON.stringify(result,null,2),'utf8');
