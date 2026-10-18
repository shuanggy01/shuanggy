'use strict';
(() => {
const $ = s => document.querySelector(s);
const config=JSON.parse($('#player-data').textContent),video=$('#main-video'),shell=$('#player-shell'),layer=$('#ad-layer'),content=$('#ad-content'),count=$('#ad-count'),frames=$('#ad-frames'),tap=$('#ad-tap'),controls=$('#custom-controls'),start=$('#start-screen');
const ads=(config.ads||[]).filter(a=>a.enabled);const stages=ads.filter(a=>a.slot==='preroll');const pauseAd=ads.find(a=>a.slot==='pause');const inter=ads.find(a=>a.slot==='interstitial');const interFired={};
let state='idle',index=-1,timer=null,watchdog=null,adVideo=null,viewSent=false,adCleanup=()=>{},generation=0;
video.controls=false;start.hidden=false;
if(document.readyState==='complete')preload();else addEventListener('load',preload,{once:true});
const message=text=>{$('#player-message').textContent=text;$('#player-message').hidden=!text;};
function clearAd(){generation++;clearInterval(timer);clearTimeout(watchdog);adCleanup();adCleanup=()=>{};if(adVideo){adVideo.pause();adVideo.removeAttribute('src');adVideo.load();adVideo=null;}content.replaceChildren();layer.classList.remove('ad-on','ad-done');layer.onclick=null;tap.hidden=true;tap.onclick=null;for(const f of frames.children)f.classList.remove('on');count.textContent='';}
function safePlay(v,onBlocked){const p=v.play();if(p&&p.catch)p.catch(onBlocked);}
function playMain(){clearAd();setTimeout(loadPopup,1200);state='main';start.hidden=true;controls.hidden=false;message('');safePlay(video,()=>{message('Ketuk ▶ untuk melanjutkan video.');$('#toggle-play').textContent='▶';});}
function advance(){if(state==='main'||state==='ended')return;clearAd();index++;if(index>=stages.length){playMain();return;}state='ad';showAd(stages[index],false);}

// Ad frames are created ahead of time (inside the invisible layer) so they are already loaded when shown.
const frameMap={};
function frameFor(slot){if(frameMap[slot])return frameMap[slot];const f=document.createElement('iframe');f.title='Iklan';f.className='ad-frame';f.setAttribute('scrolling','no');
  f.sandbox='allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox';f.referrerPolicy='no-referrer-when-downgrade';f.src='ad.php?slot='+encodeURIComponent(slot);
  f.addEventListener('load',()=>{let n=0;const fit=()=>{try{const d=f.contentDocument;if(d&&d.body){const h=Math.max(d.body.scrollHeight,d.documentElement.scrollHeight);if(h>20)f.style.height=Math.min(h,layer.clientHeight-56)+'px';}}catch(e){}if(++n<20)setTimeout(fit,500);};fit();});
  frames.append(f);frameMap[slot]=f;return f;}
function preload(){for(const a of ads)if(a.kind==='html')frameFor(a.slot);}
// Popunder/page script runs only in the top window, after the pre-roll is done.
let popDone=false;function loadPopup(){if(popDone)return;popDone=true;const t=document.getElementById('popup-code');if(!t)return;
  for(const n of [...t.content.childNodes]){if(n.nodeName==='SCRIPT'){const s=document.createElement('script');for(const at of n.attributes)s.setAttribute(at.name,at.value);s.text=n.textContent;document.body.append(s);}else document.body.append(n.cloneNode(true));}}
function showAd(ad,resume){
  const stamp=generation;layer.classList.add('ad-on');controls.hidden=true;start.hidden=true;let ready=false;
  const finish=()=>{if(resume)playMain();else advance();};
  // After the countdown, tapping the dark overlay continues (smartlink opens on that tap).
  const done=()=>{if(stamp!==generation)return;ready=true;count.textContent='✕';layer.classList.add('ad-done');if(target)tap.hidden=false;};
  const target=ad.kind==='link'?ad.url:(ad.link||'');
  const go=()=>{if(target)window.open(target,'_blank','noopener,noreferrer');finish();};
  // Background tap (outside the ad frame) continues after the countdown.
  layer.onclick=e=>{if(!ready||e.target===tap)return;if(e.target.closest&&e.target.closest('a'))return;go();};
  tap.onclick=e=>{e.stopPropagation();if(ready)go();};
  function unavailable(){if(stamp!==generation)return;clearInterval(timer);clearTimeout(watchdog);content.replaceChildren();done();}
  if(ad.kind==='video'){
    adVideo=document.createElement('video');const v=adVideo;v.playsInline=true;v.preload='auto';v.controls=false;v.src=ad.url;content.append(v);
    if(ad.link){v.style.cursor='pointer';v.addEventListener('click',e=>{e.stopPropagation();window.open(ad.link,'_blank','noopener,noreferrer');});}
    let watched=0,last=0;const update=()=>{const now=v.currentTime;const delta=now-last;if(!v.seeking&&!v.paused&&delta>0&&delta<1.5)watched+=delta;last=now;const left=Math.max(0,Math.ceil(ad.seconds-watched));if(left)count.textContent=left;else if(!ready)done();};
    timer=setInterval(()=>{if(!document.hidden)update();else last=v.currentTime;},250);update();
    v.addEventListener('ended',()=>{if(stamp===generation)finish();},{once:true});v.addEventListener('error',unavailable,{once:true});
    const stalled=()=>{clearTimeout(watchdog);watchdog=setTimeout(unavailable,15000);};v.addEventListener('waiting',stalled);v.addEventListener('playing',()=>clearTimeout(watchdog));stalled();
    safePlay(v,()=>{clearTimeout(watchdog);v.muted=true;safePlay(v,()=>{v.controls=true;});});
    return;
  }
  if(ad.kind==='html'){
    const f=frameFor(ad.slot);f.classList.add('on');if(ad.slot!=='preroll'&&f.dataset.used)f.contentWindow&&f.contentWindow.location.reload();f.dataset.used='1';
  }else if(ad.kind==='image'){
    const img=document.createElement('img');img.src=ad.url;img.alt='Iklan';img.onerror=unavailable;
    if(ad.link){const a=document.createElement('a');a.href=ad.link;a.target='_blank';a.rel='noopener sponsored';a.append(img);content.append(a);}else content.append(img);
  }
  let left=Math.max(0,Number(ad.seconds)||0);const tick=()=>{if(left)count.textContent=left;else done();};tick();
  if(left)timer=setInterval(()=>{if(!document.hidden)left=Math.max(0,left-1);tick();if(!left)clearInterval(timer);},1000);
}
$('#start-play').onclick=()=>{if(state!=='idle')return;advance();};
function toggle(){if(state!=='main'&&state!=='ended')return;if(video.paused){if(state==='ended'){state='main';video.currentTime=0;}message('');safePlay(video,()=>message('Video belum bisa diputar. Coba lagi.'));}else video.pause();}
$('#toggle-play').onclick=toggle;video.addEventListener('click',()=>{if(state==='idle')$('#start-play').click();else toggle();});
video.addEventListener('play',()=>{if(!['main','ended'].includes(state)){video.pause();return;}state='main';$('#toggle-play').textContent='Ⅱ';$('#toggle-play').setAttribute('aria-label','Jeda');message('');});
video.addEventListener('pause',()=>{if(state!=='main')return;$('#toggle-play').textContent='▶';$('#toggle-play').setAttribute('aria-label','Putar');if(pauseAd&&!video.ended){state='pause';showAd(pauseAd,true);}});
video.addEventListener('ended',()=>{clearAd();state='ended';controls.hidden=false;$('#toggle-play').textContent='↻';$('#toggle-play').setAttribute('aria-label','Putar ulang');});
video.addEventListener('error',()=>{clearAd();state='main';start.hidden=true;controls.hidden=false;message('Video tidak dapat dimuat. Periksa koneksi atau coba buka kembali link ini.');});
video.addEventListener('waiting',()=>{if(state==='main')message('Memuat video…');});video.addEventListener('playing',()=>message(''));
function time(n){if(!Number.isFinite(n))return '0:00';const s=Math.floor(n);return s>=3600?Math.floor(s/3600)+':'+String(Math.floor(s/60)%60).padStart(2,'0')+':'+String(s%60).padStart(2,'0'):Math.floor(s/60)+':'+String(s%60).padStart(2,'0');}
function update(){const duration=video.duration;$('#time-label').textContent=time(video.currentTime)+' / '+time(duration);$('#seek').value=Number.isFinite(duration)&&duration>0?video.currentTime/duration*100:0;
 if(!viewSent&&state==='main'&&video.currentTime>=3){viewSent=true;fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':$('meta[name="csrf"]').content},body:JSON.stringify({action:'view',id:config.id})}).catch(()=>{});}}
video.addEventListener('timeupdate',update);video.addEventListener('timeupdate',()=>{if(!inter||state!=='main'||video.seeking)return;const t=video.currentTime;const due=(inter.times||[]).filter(sec=>!interFired[sec]&&t>=sec);if(!due.length)return;for(const sec of due)interFired[sec]=true;state='ad';video.pause();showAd(inter,true);});video.addEventListener('loadedmetadata',()=>{shell.classList.toggle('portrait',video.videoHeight>video.videoWidth);update();});
$('#seek').addEventListener('input',e=>{if(state==='main'||state==='ended'){if(Number.isFinite(video.duration))video.currentTime=Number(e.target.value)/100*video.duration;}});
$('#mute').onclick=()=>{video.muted=!video.muted;$('#mute').textContent=video.muted?'×♪':'♪';$('#mute').setAttribute('aria-label',video.muted?'Aktifkan suara':'Matikan suara');};
$('#fullscreen').onclick=async()=>{try{if(document.fullscreenElement)await document.exitFullscreen();else if(shell.requestFullscreen)await shell.requestFullscreen();else if(video.webkitEnterFullscreen)video.webkitEnterFullscreen();else message('Layar penuh tidak tersedia pada browser ini.');}catch{message('Layar penuh belum tersedia.');}};
$('#copy-video-link').onclick=async()=>{try{await navigator.clipboard.writeText(config.sharePath?new URL(config.sharePath,location.origin).href:location.href);$('#viewer-note').textContent='Link berhasil disalin.';}catch{window.prompt('Salin link video:',config.sharePath?new URL(config.sharePath,location.origin).href:location.href);}};
shell.addEventListener('keydown',e=>{if((e.key===' '||e.key==='k')&&!['INPUT','BUTTON','A','VIDEO'].includes(e.target.tagName)){e.preventDefault();toggle();}});
})();
