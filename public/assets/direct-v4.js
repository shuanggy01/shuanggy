'use strict';
(() => {
  const data=document.querySelector('#direct-data'),button=document.querySelector('[data-direct-link]');
  if(!data||!button)return;
  const config=JSON.parse(data.textContent),links=config.links.filter(url=>{try{return new URL(url).protocol==='https:';}catch{return false;}});
  if(!links.length){button.hidden=true;return;}
  const key='viduk-direct-v4:'+config.id;let memory=0;
  const read=()=>{try{const stored=localStorage.getItem(key);if(stored===null)return memory;const n=Number(stored);return Number.isSafeInteger(n)&&n>=0?n:memory;}catch{return memory;}};
  button.href=links[read()%links.length];
  const activate=event=>{
    if(event.type==='auxclick'&&event.button!==1)return;
    const index=read()%links.length;button.href=links[index];memory=(index+1)%links.length;
    try{localStorage.setItem(key,String(memory));}catch{}
  };
  button.addEventListener('click',activate);button.addEventListener('auxclick',activate);
})();
