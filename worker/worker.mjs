// Replace the existing module Worker with this file, then configure its variables/routes.
// No R2 credentials required: sources are public HTTPS MP4/WebM URLs.
const NO_CACHE={cacheEverything:false,cacheTtlByStatus:{'100-599':-1}};
const tokenPattern=/^[A-Za-z0-9_-]+$/;
// Video plus thumbnail images, so the CDN domain never appears in the page.
const MEDIA_EXT=/\.(mp4|webm|jpe?g|png|webp)$/i,IMAGE_EXT=/\.(jpe?g|png|webp)$/i;
function hosts(value){return new Set(String(value||'').split(',').map(s=>s.trim().toLowerCase()).filter(Boolean));}
function error(message,status=400){return new Response(message,{status,headers:{'Content-Type':'text/plain; charset=utf-8','Cache-Control':'no-store','X-Content-Type-Options':'nosniff'}});}
function decodeToken(token){
  if(!tokenPattern.test(token)||token.length>5500||token.length%4===1)throw Error('token');
  const bytes=Uint8Array.from(atob(token.replace(/-/g,'+').replace(/_/g,'/')),c=>c.charCodeAt(0));
  if(btoa(String.fromCharCode(...bytes)).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'')!==token)throw Error('token');
  const raw=new TextDecoder('utf-8',{fatal:true}).decode(bytes);
  if(bytes.length>4096||/[\x00-\x20\x7f]/.test(raw))throw Error('url');
  const target=new URL(raw);
  if(target.protocol!=='https:'||target.username||target.password||target.port||target.hash||!MEDIA_EXT.test(target.pathname))throw Error('url');
  return target;
}
// MEDIA_KEY set: tokens are AES-256-GCM sealed by PHP ({u:url,e:expiry,i:ip prefix}).
function ipPrefix(ip){
  ip=String(ip||'');
  if(/^\d{1,3}(\.\d{1,3}){3}$/.test(ip))return ip.split('.').slice(0,3).map(n=>(+n).toString(16).padStart(2,'0')).join('');
  if(!ip.includes(':'))return '';
  const [h,t]=ip.split('::'),head=h?h.split(':'):[],tail=t?t.split(':'):[];
  const parts=ip.includes('::')?[...head,...Array(Math.max(0,8-head.length-tail.length)).fill('0'),...tail]:head;
  return parts.length===8?parts.slice(0,4).map(p=>p.toLowerCase().padStart(4,'0')).join(''):'';
}
let sealKey=null,sealSecret=null;
async function openSealed(token,env,request){
  if(!tokenPattern.test(token)||token.length>6000)throw Error('token');
  const bytes=Uint8Array.from(atob(token.replace(/-/g,'+').replace(/_/g,'/')),c=>c.charCodeAt(0));
  if(bytes.length<29)throw Error('token');
  if(sealSecret!==env.MEDIA_KEY){sealKey=await crypto.subtle.importKey('raw',await crypto.subtle.digest('SHA-256',new TextEncoder().encode(env.MEDIA_KEY)),'AES-GCM',false,['decrypt']);sealSecret=env.MEDIA_KEY;}
  let data;try{data=JSON.parse(new TextDecoder().decode(await crypto.subtle.decrypt({name:'AES-GCM',iv:bytes.slice(0,12)},sealKey,bytes.slice(12))));}catch{throw Error('token');}
  if(data.e&&data.e<Date.now()/1000)throw Error('expired');
  if(data.i&&data.i!==ipPrefix(request.headers.get('CF-Connecting-IP')))throw Error('ip');
  const target=new URL(String(data.u));
  if(target.protocol!=='https:'||target.username||target.password||target.port||target.hash||!MEDIA_EXT.test(target.pathname))throw Error('url');
  return target;
}
function allowedTarget(target,allowed,players){
  return target.protocol==='https:'&&!target.username&&!target.password&&!target.port&&!target.hash&&
    allowed.has(target.hostname)&&!players.has(target.hostname)&&MEDIA_EXT.test(target.pathname);
}
async function cancel(response){try{await response.body?.cancel();}catch{}}
async function media(request,url,env,players){
  if(!['GET','HEAD'].includes(request.method))return new Response('Gunakan GET atau HEAD.',{status:405,headers:{Allow:'GET, HEAD','Cache-Control':'no-store'}});
  const allowed=hosts(env.ALLOWED_MEDIA_HOSTS);
  if(!allowed.size)return error('Isi ALLOWED_MEDIA_HOSTS pada Worker terlebih dahulu.',503);
  // Embedding /media/ on other websites is refused; requests without a Referer (apps, download managers on the viewer's device) still work.
  const referer=request.headers.get('Referer');
  if(referer){let host='';try{host=new URL(referer).hostname;}catch{}if(!players.has(host))return error('Video hanya bisa diputar di website asli.',403);}
  let target;const token=url.pathname.slice('/media/'.length);
  try{target=env.MEDIA_KEY?await openSealed(token,env,request):decodeToken(token);}
  catch(e){
    if(e.message==='expired')return error('Link video sudah kedaluwarsa. Muat ulang halaman video.',403);
    if(e.message==='ip')return error('Link video hanya berlaku untuk penonton yang membuka halaman.',403);
    return error('Token video tidak valid.');
  }
  if(!allowedTarget(target,allowed,players))return error('Domain sumber video belum diizinkan.',403);
  const range=request.headers.get('Range');
  if(range&&(range.length>80||!/^bytes=(?:\d+-\d*|-\d+)$/.test(range)))return error('Range tidak didukung.',416);
  // Forward only playback headers. Never send website cookies/auth to a CDN.
  const headers=new Headers({'Accept-Encoding':'identity'});
  for(const name of ['Range','If-Range','If-None-Match','If-Modified-Since'])if(request.headers.has(name))headers.set(name,request.headers.get(name));
  let upstream;
  for(let hop=0;hop<4;hop++){
    try{upstream=await fetch(target.href,{method:request.method,headers,redirect:'manual',signal:request.signal,cf:NO_CACHE});}catch{return error('Sumber video belum dapat dihubungi.',502);}
    if(![301,302,303,307,308].includes(upstream.status))break;
    let next;try{next=new URL(upstream.headers.get('Location')||'',target);}catch{await cancel(upstream);return error('Redirect sumber tidak valid.',502);}
    await cancel(upstream);
    if(hop===3||!allowedTarget(next,allowed,players))return error('Redirect sumber video tidak diizinkan.',502);
    target=next;
  }
  if(![200,206,304,416].includes(upstream.status)){
    const status=upstream.status===404?404:502;await cancel(upstream);return error('File video belum tersedia.',status);
  }
  const type=(upstream.headers.get('Content-Type')||'').split(';')[0].trim().toLowerCase();
  const image=IMAGE_EXT.test(target.pathname);
  const okTypes=image?['image/jpeg','image/png','image/webp','application/octet-stream','binary/octet-stream']:['video/mp4','video/webm','application/octet-stream','binary/octet-stream'];
  if([200,206].includes(upstream.status)&&!okTypes.includes(type)){
    await cancel(upstream);return error('Respons sumber bukan file video/gambar yang didukung.',502);
  }
  const out=new Headers({'Cache-Control':image?'public, max-age=86400':'private, no-store','X-Content-Type-Options':'nosniff','Referrer-Policy':'no-referrer','Cross-Origin-Resource-Policy':'same-origin','X-Frame-Options':'SAMEORIGIN'});
  for(const name of ['Content-Length','Content-Range','Accept-Ranges','ETag','Last-Modified','Content-Encoding'])if(upstream.headers.has(name))out.set(name,upstream.headers.get(name));
  if([200,206].includes(upstream.status))out.set('Content-Type',image?({png:'image/png',webp:'image/webp'}[target.pathname.split('.').pop().toLowerCase()]||'image/jpeg'):(/\.webm$/i.test(target.pathname)?'video/webm':'video/mp4'));
  if(url.searchParams.get('download')==='1')out.set('Content-Disposition','attachment; filename="video.'+(/\.webm$/i.test(target.pathname)?'webm':'mp4')+'"');
  else out.set('Content-Disposition','inline');
  if(request.method==='HEAD'||[304,416].includes(upstream.status)){
    await cancel(upstream);if(upstream.status===416)out.delete('Content-Length');
    return new Response(null,{status:upstream.status,headers:out});
  }
  // Forward the readable stream immediately: never buffer the full video in memory.
  return new Response(upstream.body,{status:upstream.status,headers:out,encodeBody:'manual'});
}
async function origin(request,noCache){
  const response=await fetch(request,noCache?{cf:NO_CACHE}:{});
  if(response.status===101)return response;
  const headers=new Headers(response.headers);
  headers.set('X-Content-Type-Options','nosniff');
  if(!headers.has('Referrer-Policy'))headers.set('Referrer-Policy','strict-origin-when-cross-origin');
  if(!headers.has('X-Frame-Options'))headers.set('X-Frame-Options','SAMEORIGIN');
  // Keep an explicit public cache from PHP (e.g. the fast Shopee/link redirect); force no-store otherwise.
  if(noCache&&!/(^|,)\s*public\b/i.test(headers.get('Cache-Control')||''))headers.set('Cache-Control','private, no-store');
  return new Response(response.body,{status:response.status,statusText:response.statusText,headers,encodeBody:'manual'});
}
export default {
  async fetch(request,env={}){
    const url=new URL(request.url),players=hosts(env.PLAYER_HOSTS||'viduk.fun');
    if(players.has(url.hostname)&&url.pathname.startsWith('/media/'))return media(request,url,env,players);
    if(players.has(url.hostname)&&url.pathname.startsWith('/play/')){
      if(!['GET','HEAD'].includes(request.method))return error('Gunakan GET atau HEAD.',405);
      const token=url.pathname.slice('/play/'.length);try{decodeToken(token);}catch{return error('Link player tidak valid.');}
      // Same-origin route to the existing PHP app; keep its session for the ad/download flow.
      const page=new URL('/index.php',url.origin);page.searchParams.set('play',token);
      try{return await origin(new Request(page,request),true);}catch{return error('Player belum dapat dihubungi.',502);}
    }
    const staticAsset=/\.(?:css|js|png|jpe?g|gif|webp|svg|ico|woff2?|mp4|webm)$/i.test(url.pathname);
    const sensitive=!['GET','HEAD'].includes(request.method)||request.headers.has('Cookie')||request.headers.has('Authorization')||!staticAsset||url.pathname.startsWith('/wp-admin')||url.pathname.startsWith('/wp-json');
    try{return await origin(request,sensitive);}catch{return error('Website belum dapat dihubungi.',502);}
  }
};
