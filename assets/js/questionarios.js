(function(){'use strict';
const root=document.querySelector('[data-questionarios]');
if(!root)return;
const api='api/questionarios.php',csrf=root.dataset.csrf;
const grid=document.querySelector('#questionnaireGrid tbody');
const editor=document.querySelector('[data-questionnaire-editor]');
const msg=document.querySelector('[data-editor-message]');
const search=document.querySelector('[data-questionnaire-search]');
const newForm=document.querySelector('[data-new-questionnaire-form]');
const moduleSelect=newForm.querySelector('[name=modulo]');
const newModal=bootstrap.Modal.getOrCreateInstance(document.querySelector('#newQuestionnaireModal'));
let rows=[],currentModule='LEGAL';
const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
const moduleOf=row=>String(row.modulo||'').trim().toUpperCase()||'SEM_TIPO';
async function call(url,body){const r=await fetch(url,{method:body?'POST':'GET',headers:body?{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}:undefined,body:body?new URLSearchParams({...body,csrf}):undefined});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.message||'Não foi possível concluir a operação.');return d;}
function filteredRows(){const term=(search.value||'').toLowerCase();return rows.filter(r=>moduleOf(r)===currentModule&&JSON.stringify(r).toLowerCase().includes(term));}
function renderCounts(){document.querySelectorAll('[data-tab-count]').forEach(b=>{const type=b.dataset.tabCount;const count=rows.filter(r=>moduleOf(r)===type).length;b.textContent=count;});}
function render(){renderCounts();const visible=filteredRows();grid.innerHTML=visible.map(r=>`<tr><td>${esc(r.codigo)}</td><td>${esc(r.nome_pt)}</td><td>${esc(r.modulo||'Não definido')}</td><td>${esc(r.versao)}</td><td><span class="badge text-bg-${r.status==='PUBLICADA'?'success':'secondary'}">${esc(r.status)}</span></td><td><button type="button" class="btn btn-sm btn-outline-primary" data-open="${esc(r.codversao)}" title="Abrir editor"><i class="bi bi-pencil-square"></i></button></td></tr>`).join('')||'<tr><td colspan="6">Nenhum questionário encontrado.</td></tr>';}
function activateTab(type){currentModule=type;document.querySelectorAll('[data-questionnaire-tab]').forEach(tab=>tab.classList.toggle('active',tab.dataset.questionnaireTab===type));if(moduleSelect)moduleSelect.value=type;render();}
async function load(){grid.innerHTML='<tr><td colspan="6">Carregando...</td></tr>';try{rows=(await call(api+'?action=catalog')).rows;render();}catch(e){grid.innerHTML=`<tr><td colspan="6" class="text-danger">${esc(e.message)}</td></tr>`;}}
function editorMessage(text,kind='success'){msg.textContent=text;msg.className='alert alert-'+kind+' py-2';}
async function openEditor(version){try{const b=(await call(api+'?action=builder&codversao='+encodeURIComponent(version))).builder;editor.classList.remove('d-none');editor.dataset.version=b.codversao;document.querySelector('[data-editor-title]').textContent=b.nome_pt+' — '+b.codigo;document.querySelector('[data-editor-meta]').textContent='Versão '+b.versao+' · '+b.status;document.querySelector('[data-publish]').classList.toggle('d-none',b.status!=='RASCUNHO');document.querySelector('[data-question-form-wrap]').classList.toggle('d-none',b.status!=='RASCUNHO');document.querySelector('[data-question-form] [name=codversao]').value=b.codversao;document.querySelector('[data-questions-list]').innerHTML=b.perguntas.length?b.perguntas.map(q=>`<div class="border rounded p-2 mb-2"><div class="d-flex justify-content-between"><strong>${esc(q.ordem)}. ${esc(q.texto_pt)}</strong><span class="badge text-bg-light">${esc(q.tipo)}${q.obrigatoria?' · obrigatória':''}</span></div><div class="small text-muted">${q.opcoes&&q.opcoes.length?q.opcoes.map(o=>esc(o.rotulo_pt)+' ('+esc(o.codigo)+')').join(' · '):'Sem opções'}</div>${b.status==='RASCUNHO'?`<button class="btn btn-sm btn-link px-0" data-add-option="${q.codpergunta}"><i class="bi bi-plus-lg"></i> adicionar opção</button>`:''}</div>`).join(''):'<div class="text-muted small">Nenhuma pergunta cadastrada.</div>';editor.scrollIntoView({behavior:'smooth',block:'start'});}catch(e){editor.classList.remove('d-none');editorMessage(e.message,'danger');}}
document.querySelectorAll('[data-questionnaire-tab]').forEach(tab=>tab.addEventListener('click',()=>activateTab(tab.dataset.questionnaireTab)));
document.querySelector('[data-new-questionnaire]').addEventListener('click',()=>{moduleSelect.value=currentModule;newModal.show();});
document.querySelector('[data-refresh-questionnaires]').addEventListener('click',load);
search.addEventListener('input',render);
document.querySelector('[data-close-editor]').addEventListener('click',()=>editor.classList.add('d-none'));
grid.addEventListener('click',e=>{const b=e.target.closest('[data-open]');if(b)openEditor(b.dataset.open);});
newForm.addEventListener('submit',async e=>{e.preventDefault();const data=Object.fromEntries(new FormData(e.target));data.action='criar';try{const r=await call(api,data);newModal.hide();e.target.reset();await load();activateTab(data.modulo||currentModule);openEditor(r.draft.codversao);}catch(x){alert(x.message);}});
document.querySelector('[data-question-form]').addEventListener('submit',async e=>{e.preventDefault();const data=Object.fromEntries(new FormData(e.target));try{await call(api,data);e.target.reset();e.target.codversao.value=editor.dataset.version;await openEditor(editor.dataset.version);}catch(x){editorMessage(x.message,'danger');}});
document.querySelector('[data-publish]').addEventListener('click',async()=>{if(!confirm('Publicar esta versão? Depois da publicação ela ficará imutável.'))return;try{await call(api,{action:'publicar',codversao:editor.dataset.version});editorMessage('Versão publicada com sucesso.');await load();await openEditor(editor.dataset.version);}catch(x){editorMessage(x.message,'danger');}});
document.querySelector('[data-questions-list]').addEventListener('click',async e=>{const b=e.target.closest('[data-add-option]');if(!b)return;const codigo=prompt('Código da opção:');if(!codigo)return;const rotulo=prompt('Rótulo da opção:',codigo);if(!rotulo)return;const valor=prompt('Valor:',codigo);const pontuacao=prompt('Pontuação (opcional):');try{await call(api,{action:'opcao',codpergunta:b.dataset.addOption,codigo,rotulo_pt:rotulo,valor,pontuacao});await openEditor(editor.dataset.version);}catch(x){editorMessage(x.message,'danger');}});
load();
})();
