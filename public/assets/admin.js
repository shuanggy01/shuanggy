'use strict';
const $ = (s, root = document) => root.querySelector(s);
const $$ = (s, root = document) => [...root.querySelectorAll(s)];
const csrf = $('meta[name="csrf"]').content;
const site = JSON.parse($('#site-data').textContent);
let working = false;
async function api(action, data = {}) {
  const res = await fetch('api.php', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-CSRF-Token':csrf}, body:JSON.stringify({action,...data})});
  let json; try { json = await res.json(); } catch { throw new Error('Respons server tidak valid. Periksa PHP dan error log hosting.'); }
  if (!res.ok || json.error) throw new Error(json.error || 'Permintaan gagal.'); return json;
}
let toastTimer;
function toast(message, error = false) {const t=$('#toast'); t.textContent=message; t.classList.toggle('error',error); t.hidden=false;clearTimeout(toastTimer);toastTimer=setTimeout(()=>t.hidden=true,7000);}
async function copy(text) {try {await navigator.clipboard.writeText(text); toast('Berhasil disalin.');} catch {window.prompt('Salin teks berikut:',text);}}
const videoLink = id => new URL('index.php?v='+encodeURIComponent(id),location.href).href;
function readAds() {return $$('.ad-config').map(box=>{const v=n=>$('[name="'+n+'"]',box);return {slot:box.dataset.slot,enabled:v('enabled').checked,content:v('content').value,seconds:v('seconds')?Number(v('seconds').value):0,times:v('times')?v('times').value:''};});}
function readGeneral() {const f=$('#general-form');return {site_name:f.elements.site_name.value,vip_url:f.elements.vip_url.value,download:f.elements.download.checked,pretty_links:f.elements.pretty_links.checked,media_proxy:false};}
$$('.ad-config').forEach(box=>{const cb=$('[name="enabled"]',box),st=$('.acc-status',box);cb.addEventListener('change',()=>{st.textContent=cb.checked?'Aktif':'Nonaktif';st.classList.toggle('on',cb.checked);$('#ads-pill').textContent=$$('.ad-config [name="enabled"]:checked').length+' / '+$$('.ad-config').length+' aktif';});});
const openFold=id=>{const box=id&&document.getElementById(id);if(box&&box.classList.contains('fold'))box.open=true;};
let opened=[];try{opened=JSON.parse(localStorage.getItem('studio-open')||'[]');}catch{}
opened.forEach(openFold);openFold(location.hash.slice(1));
$$('.fold').forEach(box=>box.addEventListener('toggle',()=>{try{localStorage.setItem('studio-open',JSON.stringify($$('.fold[open]').map(b=>b.id)));}catch{}}));
addEventListener('hashchange',()=>openFold(location.hash.slice(1)));
$$('.sidebar nav a[href^="#"]').forEach(a=>a.addEventListener('click',()=>openFold(a.hash.slice(1))));
for (const id of ['ads-form','general-form']) $('#'+id).addEventListener('submit', async e=>{
  e.preventDefault();const b=$('button[type="submit"],button.primary',e.target);b.disabled=true;
  try {const next={...readGeneral(),ads:readAds()};await api('settings',next);Object.assign(site,next);toast('Pengaturan tersimpan.');}catch(err){toast(err.message,true);}finally{b.disabled=false;}
});
$('#r2-form').addEventListener('submit',async e=>{e.preventDefault();if(working)return toast('Tunggu posting selesai.',true);const b=$('.primary',e.target);b.disabled=true;try{await api('r2_save',Object.fromEntries(new FormData(e.target)));for(const k of ['access','secret']){e.target.elements[k].value='';e.target.elements[k].placeholder='Tersimpan · kosongkan agar tetap';}toast('Koneksi R2 disimpan.');}catch(err){toast(err.message,true);}finally{b.disabled=false;}});
$('#test-r2').addEventListener('click',async e=>{e.target.disabled=true;try {toast((await api('r2_test')).message);}catch(err){toast(err.message,true);}finally{e.target.disabled=false;}});
$('#password-form').addEventListener('submit',async e=>{e.preventDefault();try{await api('password',Object.fromEntries(new FormData(e.target)));e.target.reset();toast('Password diperbarui.');}catch(err){toast(err.message,true);}});
$('#search').addEventListener('input',e=>{const q=e.target.value.toLowerCase();let count=0;$$('.video-row,.folder').forEach(row=>{row.hidden=!row.dataset.title.includes(q);if(!row.hidden)count++;});$('#no-results').hidden=count!==0||!q;});
// Folder: same format as bot Telegram (tipe=folder, list of post IDs).
const editor=$('#folder-editor');let picked=[],keep=[];
function openEditor(box){editor.hidden=false;editor.elements.id.value=box?box.dataset.id:'';editor.elements.title.value=box?$('.folder-name',box).textContent:'';
  const items=box?JSON.parse(box.dataset.items):[];const ids=$$('.pick input').map(c=>c.value);picked=items.filter(i=>ids.includes(i));keep=items.filter(i=>!ids.includes(i));
  $$('.pick input').forEach(c=>c.checked=picked.includes(c.value));editor.scrollIntoView({behavior:'smooth',block:'start'});editor.elements.title.focus({preventScroll:true});}
$$('.pick input').forEach(c=>c.addEventListener('change',()=>{picked=picked.filter(i=>i!==c.value);if(c.checked)picked.push(c.value);}));
$('#new-folder').onclick=()=>openEditor(null);$('#cancel-folder').onclick=()=>{editor.hidden=true;};
editor.addEventListener('submit',async e=>{e.preventDefault();const b=$('#save-folder');b.disabled=true;
  try{await api('folder_save',{id:editor.elements.id.value,title:editor.elements.title.value,items:[...picked,...keep]});toast('Folder tersimpan.');location.hash='library';location.reload();}catch(err){toast(err.message,true);b.disabled=false;}});
$$('.folder').forEach(box=>{
  $('.copy-folder',box).onclick=()=>copy(videoLink(box.dataset.id));
  $('.edit-folder',box).onclick=()=>openEditor(box);
  $('.delete-folder',box).onclick=async()=>{if(!confirm('Hapus folder ini? Video di dalamnya tetap ada.'))return;try{await api('folder_delete',{id:box.dataset.id});box.remove();toast('Folder dihapus.');}catch(err){toast(err.message,true);}};
});
$$('.video-row').forEach(row=>{
  $('.copy-link',row).onclick=()=>copy(new URL(row.dataset.path||('index.php?v='+encodeURIComponent(row.dataset.id)),location.href).href);
  $('.edit-video',row).onclick=async()=>{const title=prompt('Judul video:',$('h3',row).textContent);if(title===null)return;try{await api('video_edit',{id:row.dataset.id,title});$('h3',row).textContent=title;row.dataset.title=title.toLowerCase();toast('Judul diperbarui.');}catch(err){toast(err.message,true);}};
  $('.delete-video',row).onclick=async()=>{if(!confirm('Hapus video ini dari website? File asli tetap tersimpan di R2.'))return;try{await api('video_delete',{id:row.dataset.id});row.remove();toast('Video dihapus dari website.');}catch(err){toast(err.message,true);}};
});
$('#url-form').addEventListener('submit',async e=>{
  e.preventDefault();if(working)return;working=true;const btn=$('#publish-url');btn.disabled=true;btn.textContent='Menerbitkan…';
  try{const result=await api('publish_url',Object.fromEntries(new FormData(e.target)));const area=$('#publish-result');area.replaceChildren(document.createTextNode('Video berhasil diterbitkan. '));const link=document.createElement('a');link.href=result.path?new URL(result.path,location.href).href:videoLink(result.id);link.textContent='Buka video ↗';link.target='_blank';link.rel='noopener';area.append(link,document.createTextNode(' · '));const refresh=document.createElement('a');refresh.href='admin.php#library';refresh.textContent='Perbarui koleksi';refresh.onclick=ev=>{ev.preventDefault();location.hash='library';location.reload();};area.append(refresh);area.hidden=false;e.target.reset();toast('Video berhasil diterbitkan.');}catch(err){toast(err.message,true);}finally{working=false;btn.disabled=false;btn.textContent='Terbitkan video ↗';}
});
