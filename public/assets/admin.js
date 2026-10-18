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
for (const id of ['ads-form','general-form']) $('#'+id).addEventListener('submit', async e=>{
  e.preventDefault();const b=$('button[type="submit"],button.primary',e.target);b.disabled=true;
  try {const next={...readGeneral(),ads:readAds()};await api('settings',next);Object.assign(site,next);toast('Pengaturan tersimpan.');}catch(err){toast(err.message,true);}finally{b.disabled=false;}
});
$('#r2-form').addEventListener('submit',async e=>{e.preventDefault();if(working)return toast('Tunggu posting selesai.',true);const b=$('.primary',e.target);b.disabled=true;try{await api('r2_save',Object.fromEntries(new FormData(e.target)));for(const k of ['access','secret']){e.target.elements[k].value='';e.target.elements[k].placeholder='Tersimpan · kosongkan agar tetap';}toast('Koneksi R2 disimpan.');}catch(err){toast(err.message,true);}finally{b.disabled=false;}});
$('#test-r2').addEventListener('click',async e=>{e.target.disabled=true;try {toast((await api('r2_test')).message);}catch(err){toast(err.message,true);}finally{e.target.disabled=false;}});
$('#password-form').addEventListener('submit',async e=>{e.preventDefault();try{await api('password',Object.fromEntries(new FormData(e.target)));e.target.reset();toast('Password diperbarui.');}catch(err){toast(err.message,true);}});
$('#search').addEventListener('input',e=>{let count=0;$$('.video-row').forEach(row=>{row.hidden=!row.dataset.title.includes(e.target.value.toLowerCase());if(!row.hidden)count++;});$('#no-results').hidden=count!==0||!e.target.value;});
$$('.video-row').forEach(row=>{
  $('.copy-link',row).onclick=()=>copy(new URL(row.dataset.path||('index.php?v='+encodeURIComponent(row.dataset.id)),location.href).href);
  $('.edit-video',row).onclick=async()=>{const title=prompt('Judul video:',$('h3',row).textContent);if(title===null)return;try{await api('video_edit',{id:row.dataset.id,title});$('h3',row).textContent=title;row.dataset.title=title.toLowerCase();toast('Judul diperbarui.');}catch(err){toast(err.message,true);}};
  $('.delete-video',row).onclick=async()=>{if(!confirm('Hapus video ini dari website? File asli tetap tersimpan di R2.'))return;try{await api('video_delete',{id:row.dataset.id});row.remove();toast('Video dihapus dari website.');}catch(err){toast(err.message,true);}};
});
$('#url-form').addEventListener('submit',async e=>{
  e.preventDefault();if(working)return;working=true;const btn=$('#publish-url');btn.disabled=true;btn.textContent='Menerbitkan…';
  try{const result=await api('publish_url',Object.fromEntries(new FormData(e.target)));const area=$('#publish-result');area.replaceChildren(document.createTextNode('Video berhasil diterbitkan. '));const link=document.createElement('a');link.href=result.path?new URL(result.path,location.href).href:videoLink(result.id);link.textContent='Buka video ↗';link.target='_blank';link.rel='noopener';area.append(link,document.createTextNode(' · '));const refresh=document.createElement('a');refresh.href='admin.php#library';refresh.textContent='Perbarui koleksi';area.append(refresh);area.hidden=false;e.target.reset();toast('Video berhasil diterbitkan.');}catch(err){toast(err.message,true);}finally{working=false;btn.disabled=false;btn.textContent='Terbitkan video ↗';}
});
