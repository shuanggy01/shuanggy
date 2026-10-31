const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
class Element {
 constructor(tag='DIV'){this.tagName=tag.toUpperCase();this.listeners={};this.children=[];this.hidden=false;this.disabled=false;this.textContent='';this.currentTime=0;this.duration=120;this.paused=true;this.ended=false;this.seeking=false;this.classList={toggle(){}};}
 addEventListener(k,fn){(this.listeners[k]??=[]).push(fn)}
 emit(k){for(const f of this.listeners[k]||[])f({target:this});}
 append(...items){this.children.push(...items)}
 replaceChildren(...items){this.children=items}
 removeAttribute(){}setAttribute(k,v){this[k]=v}
 load(){}play(){this.paused=false;this.emit('play');return Promise.resolve()}
 pause(){this.paused=true;this.emit('pause')}
 click(){if(!this.disabled&&this.onclick)this.onclick();}
}
const elements={};const get=id=>elements[id]??=(new Element(id.includes('video')?'VIDEO':'DIV'));
const ads=['before1','before2','before3','preroll','pause'].map(slot=>({slot,enabled:true,type:slot==='preroll'?'video':'image',url:'https://example.com/ad',link:'',seconds:2}));
get('#player-data').textContent=JSON.stringify({id:'deadbeef',ads});get('meta[name="csrf"]').content='token';
let timers=new Map(),serial=0,requests=[];
const document={hidden:false,querySelector:get,createElement:tag=>new Element(tag),fullscreenElement:null};
const context={document,console,location:{href:'https://example.com/?v=deadbeef'},navigator:{clipboard:{writeText:async()=>{}}},window:{prompt(){}},setInterval:f=>{timers.set(++serial,f);return serial},clearInterval:id=>timers.delete(id),setTimeout:()=>++serial,clearTimeout(){},fetch:(...args)=>{requests.push(args);return Promise.resolve({});}};
vm.runInNewContext(fs.readFileSync(__dirname+'/../public/assets/player.js','utf8'),context);
const tick=n=>{for(let i=0;i<n;i++)for(const f of [...timers.values()])f();};
const main=get('#main-video'),next=get('#ad-next');assert.equal(main.controls,false);assert.equal(get('#start-screen').hidden,false);
get('#start-play').click();assert.equal(get('#ad-position').textContent,'1 / 4');assert.equal(next.disabled,true);
document.hidden=true;tick(4);assert.equal(next.disabled,true);document.hidden=false;tick(2);assert.equal(next.disabled,false);
next.click();assert.equal(get('#ad-position').textContent,'2 / 4');tick(2);next.click();assert.equal(get('#ad-position').textContent,'3 / 4');tick(2);next.click();assert.equal(get('#ad-label').textContent,'IKLAN VIDEO');
const ad=get('#ad-content').children[0];assert.equal(ad.tagName,'VIDEO');assert.equal(next.disabled,true);
for(let i=0;i<8;i++){ad.currentTime+=.25;tick(1);}assert.equal(next.disabled,false);next.click();assert.equal(main.paused,false);assert.equal(get('#ad-layer').hidden,true);
main.currentTime=4;main.emit('timeupdate');main.emit('timeupdate');assert.equal(requests.length,1);
get('#toggle-play').click();assert.equal(get('#ad-label').textContent,'JEDA IKLAN');assert.equal(get('#ad-layer').hidden,false);assert.equal(next.disabled,false);next.click();assert.equal(main.paused,false);
main.currentTime=120;main.ended=true;main.paused=true;main.emit('ended');assert.equal(get('#toggle-play').textContent,'↻');get('#toggle-play').click();assert.equal(main.currentTime,0);assert.equal(main.paused,false);assert.equal(get('#ad-layer').hidden,true);
console.log('PASS: ordered before-play slots, background countdown, pre-roll threshold, main playback, view debounce, pause ad, resume/replay.');
