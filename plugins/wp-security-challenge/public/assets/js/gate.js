(function(){
'use strict';
var cfg=window.WPSC_GATE||{};
var start=performance.now();
var q=function(s){return document.querySelector(s)};
var checkStage=q('#wpsc-stage-check'),captchaStage=q('#wpsc-stage-captcha'),title=q('#wpsc-title'),description=q('#wpsc-description');
var progress=q('#wpsc-progress-bar'),statusText=q('#wpsc-status-text'),captchaCode=q('#wpsc-captcha-code'),captchaInput=q('#wpsc-captcha-input'),captchaForm=q('#wpsc-captcha-form'),refreshBtn=q('#wpsc-refresh'),errorBox=q('#wpsc-error'),submitBtn=q('#wpsc-submit');
var captchaId='';
var reduce=window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function animateUI(){
  if(reduce||!window.gsap)return;
  gsap.fromTo('#wpsc-card',{y:18,opacity:0,scale:.985},{y:0,opacity:1,scale:1,duration:.65,ease:'power3.out'});
  gsap.fromTo('.wpsc-topline',{y:-7,opacity:0},{y:0,opacity:1,duration:.5,delay:.12,ease:'power2.out'});
  gsap.to('.wpsc-ring-1',{rotation:360,duration:3.4,repeat:-1,ease:'none'});
  gsap.to('.wpsc-ring-2',{rotation:-360,duration:4.7,repeat:-1,ease:'none'});
  gsap.to('.wpsc-loader-glow',{scale:1.18,opacity:.62,duration:1.4,repeat:-1,yoyo:true,ease:'sine.inOut'});
  gsap.fromTo('.wpsc-scan',{y:4,opacity:.25},{y:56,opacity:1,duration:1.3,repeat:-1,yoyo:true,ease:'sine.inOut'});
  gsap.fromTo('#wpsc-title,#wpsc-description',{y:8,opacity:0},{y:0,opacity:1,duration:.5,stagger:.08,delay:.18,ease:'power2.out'});
}

function setProgress(value,label){
  if(statusText&&label)statusText.textContent=label;
  if(!progress)return;
  if(window.gsap&&!reduce)gsap.to(progress,{width:value+'%',duration:.55,ease:'power2.out'});else progress.style.width=value+'%';
}
function post(action,data){
  var body=new URLSearchParams();body.set('action',action);body.set('nonce',cfg.nonce||'');body.set('gate_id',cfg.gateId||'');
  Object.keys(data||{}).forEach(function(k){body.set(k,data[k])});
  return fetch(cfg.ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()}).then(function(r){return r.json()});
}
function storageWorks(){try{var k='__wpsc__';localStorage.setItem(k,'1');localStorage.removeItem(k);return true}catch(e){return false}}
function collect(){
  return {
    webdriver:!!navigator.webdriver,
    cookieEnabled:navigator.cookieEnabled!==false,
    localStorage:storageWorks(),
    language:navigator.language||'',
    languages:(navigator.languages||[]).slice(0,5),
    platform:navigator.platform||'',
    hardwareConcurrency:Number(navigator.hardwareConcurrency||0),
    deviceMemory:Number(navigator.deviceMemory||0),
    maxTouchPoints:Number(navigator.maxTouchPoints||0),
    screenW:Number(screen.width||0),screenH:Number(screen.height||0),
    colorDepth:Number(screen.colorDepth||0),pixelRatio:Number(window.devicePixelRatio||1),
    timezone:(Intl&&Intl.DateTimeFormat?Intl.DateTimeFormat().resolvedOptions().timeZone:''),
    plugins:navigator.plugins?Math.min(navigator.plugins.length,30):0,
    visibility:document.visibilityState||'',
    elapsed:Math.round(performance.now()-start)
  };
}
function showError(message){if(!errorBox)return;errorBox.textContent=message||cfg.texts.error;errorBox.hidden=false;if(window.gsap&&!reduce)gsap.fromTo(errorBox,{y:-4,opacity:0},{y:0,opacity:1,duration:.25})}
function applyCaptcha(payload){
  captchaId=payload.captcha_id||'';captchaCode.innerHTML=payload.captcha||'';errorBox.hidden=true;captchaInput.value='';
}
function showCaptcha(payload){
  setProgress(100,cfg.texts.statusExtra||'نیاز به تأیید تکمیلی');applyCaptcha(payload);checkStage.hidden=true;captchaStage.hidden=false;
  if(window.gsap&&!reduce){gsap.fromTo(captchaStage,{y:12,opacity:0},{y:0,opacity:1,duration:.45,ease:'power3.out'});gsap.fromTo('#wpsc-captcha-code span',{y:8,opacity:0,rotation:0},{y:0,opacity:1,duration:.3,stagger:.045,ease:'back.out(1.8)'})}
  setTimeout(function(){captchaInput.focus()},120);
}
function success(redirect){
  checkStage.hidden=false;captchaStage.hidden=true;title.textContent=cfg.texts.successTitle||'تأیید شد';description.textContent=cfg.texts.successText||'در حال هدایت به سایت هستید...';setProgress(100,cfg.texts.statusVerified||'اتصال امن تأیید شد');
  if(window.gsap&&!reduce){gsap.to('.wpsc-ring-1,.wpsc-ring-2,.wpsc-scan',{opacity:0,duration:.25});gsap.to('.wpsc-shield-check',{strokeDashoffset:0,duration:.55,ease:'power2.out'});gsap.fromTo('.wpsc-shield',{scale:.94},{scale:1.08,duration:.25,yoyo:true,repeat:1,ease:'power2.out'})}
  setTimeout(function(){window.location.replace(redirect||'/')},620);
}
function inspect(){
  setProgress(28,cfg.texts.statusBrowser||'بررسی قابلیت‌های مرورگر...');
  setTimeout(function(){setProgress(57,cfg.texts.statusBehavior||'تحلیل رفتار اتصال...')},220);
  setTimeout(function(){setProgress(78,cfg.texts.statusSession||'اعتبارسنجی نشست امنیتی...')},450);
  setTimeout(function(){
    post('wpsc_inspect',{signals:JSON.stringify(collect())}).then(function(res){
      if(!res||!res.success){throw new Error(res&&res.data&&res.data.message?res.data.message:cfg.texts.error)}
      if(res.data.status==='captcha')showCaptcha(res.data);else success(res.data.redirect);
    }).catch(function(err){setProgress(100,cfg.texts.statusStopped||'بررسی متوقف شد');showError(err.message||cfg.texts.error)});
  },Math.max(360,Math.min(900,Number(cfg.inspectDelay||720))));
}
if(captchaForm)captchaForm.addEventListener('submit',function(e){e.preventDefault();var answer=(captchaInput.value||'').trim();if(answer.length<5){showError(cfg.texts.captchaIncomplete||'لطفاً کد امنیتی را کامل وارد کنید.');return}submitBtn.disabled=true;errorBox.hidden=true;post('wpsc_verify_captcha',{captcha_id:captchaId,answer:answer}).then(function(res){if(res&&res.success){success(res.data.redirect);return}if(res&&res.data){if(res.data.captcha)applyCaptcha(res.data);showError(res.data.message)}else showError(cfg.texts.error)}).catch(function(){showError(cfg.texts.error)}).finally(function(){submitBtn.disabled=false})});
if(refreshBtn)refreshBtn.addEventListener('click',function(){refreshBtn.disabled=true;post('wpsc_refresh_captcha',{}).then(function(res){if(res&&res.success){applyCaptcha(res.data);if(window.gsap&&!reduce)gsap.fromTo('#wpsc-captcha-code span',{scale:.6,opacity:0},{scale:1,opacity:1,duration:.28,stagger:.04,ease:'back.out(2)'})}}).finally(function(){refreshBtn.disabled=false})});
function wpscLatinDigits(v){return String(v||'').replace(/[۰-۹]/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)}).replace(/[٠-٩]/g,function(d){return '٠١٢٣٤٥٦٧٨٩'.indexOf(d)})}
if(captchaInput)captchaInput.addEventListener('input',function(){
  var oldValue=this.value,oldStart=this.selectionStart,oldEnd=this.selectionEnd;
  var before=oldValue.slice(0,oldStart==null?oldValue.length:oldStart);
  var normalized=wpscLatinDigits(oldValue).replace(/[^a-zA-Z0-9]/g,'').toUpperCase();
  var normalizedBefore=wpscLatinDigits(before).replace(/[^a-zA-Z0-9]/g,'').toUpperCase();
  if(this.value!==normalized)this.value=normalized;
  var pos=Math.min(normalizedBefore.length,normalized.length);
  try{this.setSelectionRange(pos,pos)}catch(e){}
  errorBox.hidden=true;
});
animateUI();inspect();
})();
