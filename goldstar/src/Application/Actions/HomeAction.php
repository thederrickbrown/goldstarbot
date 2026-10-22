<?php
declare(strict_types=1);

namespace App\Application\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Branded marketing landing page served at GET /.
 * Includes an interactive, client-side demo and a pricing section.
 */
final class HomeAction
{
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>GoldStarBot — peer recognition for Slack</title>
<meta name="description" content="GoldStarBot lets your team give each other gold stars in Slack — with tallies, leaderboards, and achievements. Try the live demo.">
<link rel="icon" type="image/svg+xml" href="/assets/goldstarbot-icon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Courier+Prime:wght@400;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{--navy:#1F2937;--gold:#F6B31A;--gold-dk:#b57e07;--green:#22A85A;--green-dk:#1B8E4A;
        --mint:#EAF7EE;--line:#cfeede;--muted:#64748b}
  *{box-sizing:border-box}
  html{scroll-behavior:smooth}
  body{margin:0;color:var(--navy);background:#fff;
    font:16px/1.6 "Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
  a{color:var(--green-dk)}
  .mono{font-family:"Courier Prime",ui-monospace,monospace}
  .wrap{max-width:1040px;margin:0 auto;padding:0 20px}

  /* top bar */
  header.top{position:sticky;top:0;z-index:10;background:rgba(255,255,255,.85);backdrop-filter:blur(8px);border-bottom:1px solid var(--line)}
  header.top .wrap{display:flex;align-items:center;justify-content:space-between;height:64px}
  .brand{display:flex;align-items:center;gap:9px;text-decoration:none;color:var(--navy)}
  .brand img{height:30px;width:auto}
  .brand b{font:700 24px/1 "Courier Prime",ui-monospace,monospace;letter-spacing:-.02em;position:relative;top:2px}
  .brand b .g{color:var(--green)}
  .navlinks{display:flex;gap:20px;align-items:center}
  .navlinks a{color:var(--muted);text-decoration:none;font-weight:600;font-size:14px}
  .navlinks a:hover{color:var(--navy)}
  .btn{display:inline-block;text-decoration:none;font-weight:700;border-radius:10px;padding:11px 18px;font-size:14px;background:var(--navy);color:#fff;border:0;cursor:pointer}
  .btn:hover{background:#111827}
  .btn.gold{background:var(--gold);color:var(--navy)}.btn.gold:hover{background:#e5a512}
  .btn.ghost{background:#fff;color:var(--navy);border:1px solid var(--line)}.btn.ghost:hover{background:var(--mint)}

  /* hero */
  .hero{background:linear-gradient(180deg,#d8f5e3 0%,#f2fdf6 70%,#fff 100%);padding:56px 0 40px;text-align:center}
  .hero img.logo{width:min(220px,60vw)}
  .hero h1{font-size:clamp(28px,5vw,44px);margin:14px 0 8px;letter-spacing:-.02em}
  .hero p{font-size:19px;color:#374151;max-width:34ch;margin:0 auto 22px}
  .hero .cta{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
  .kicker{font:700 13px/1 "Courier Prime",ui-monospace,monospace;letter-spacing:.08em;text-transform:uppercase;color:var(--green-dk)}

  section{padding:56px 0}
  section h2{font-size:clamp(24px,4vw,32px);text-align:center;margin:0 0 6px;letter-spacing:-.01em}
  section .sub{text-align:center;color:var(--muted);max-width:52ch;margin:0 auto 34px}

  /* demo — fake slack */
  .demo{background:var(--mint)}
  .slack{max-width:620px;margin:0 auto;background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden;box-shadow:0 12px 40px rgba(31,41,55,.10)}
  .slack-head{background:#3f0e40;color:#fff;padding:12px 16px;font-weight:700;font-size:15px}
  .slack-head .hash{opacity:.6;margin-right:2px}
  .slack-msgs{height:340px;overflow-y:auto;padding:10px 16px;background:#fff}
  .m{display:flex;gap:10px;padding:8px 0}
  .av{width:36px;height:36px;border-radius:8px;flex:none;color:#fff;font-weight:700;display:flex;align-items:center;justify-content:center;font-size:14px}
  .av.you{background:#616061}
  img.av{object-fit:cover;background:#eaf6ef}
  .mb{min-width:0}
  .mn{font-weight:700;font-size:14px;color:#1d1c1d}
  .mn .app{background:#e8e8e8;color:#616061;font-size:10px;font-weight:700;border-radius:3px;padding:1px 5px;margin-left:5px;vertical-align:middle;letter-spacing:.02em}
  .mn .mt{color:#9a9a9a;font-weight:400;font-size:12px;margin-left:6px}
  .mtext{font-size:15px;color:#1d1c1d;word-wrap:break-word}
  .mtext code{background:#f3f3f3;color:#1F2937;border-radius:4px;padding:1px 5px;font-size:13px}
  .star{border:1px solid var(--line);border-left:4px solid var(--gold);border-radius:8px;padding:12px 14px;margin-top:4px;background:#fffdf7}
  .star .sh{font-size:15px}
  .star .sq{border-left:3px solid #d9e6e0;padding-left:10px;color:#3f4a54;margin:8px 0}
  .star .sc{color:#64748b;font-size:13px;margin-top:8px}
  .star .ach{margin-top:10px;padding-top:10px;border-top:1px dashed var(--line);font-size:14px}
  .slack-input{display:flex;gap:8px;padding:12px 16px;border-top:1px solid var(--line);background:#fff}
  .slack-input input{flex:1;border:1px solid #cfd6dd;border-radius:8px;padding:11px 12px;font-size:15px}
  .slack-input input:focus{outline:none;border-color:var(--green);box-shadow:0 0 0 3px rgba(34,168,90,.15)}
  .chips{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin-top:16px}
  .chip{cursor:pointer;border:1px solid var(--line);background:#fff;border-radius:999px;padding:7px 13px;font-size:13px;font-weight:600;color:var(--navy)}
  .chip:hover{background:#fff;border-color:var(--green);color:var(--green-dk)}

  /* features */
  .feat{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
  .feat .f{border:1px solid var(--line);border-radius:14px;padding:22px;background:#fff}
  .feat .f .ic{font-size:26px}
  .feat .f h3{margin:10px 0 4px;font-size:17px}
  .feat .f p{margin:0;color:var(--muted);font-size:14px}

  /* pricing */
  .pricing{background:linear-gradient(180deg,#fff, #f2fdf6)}
  .tiers{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;align-items:stretch}
  .tiers.two{grid-template-columns:1fr 1fr;max-width:720px;margin:0 auto}
  .tiers.one{grid-template-columns:1fr;max-width:400px;margin:0 auto}
  .finep{margin-top:10px;font-size:12px;color:var(--muted);text-align:center}
  .tier{border:1px solid var(--line);border-radius:16px;padding:26px 22px;background:#fff;display:flex;flex-direction:column}
  .tier.pop{border:2px solid var(--green);box-shadow:0 12px 40px rgba(34,168,90,.12);position:relative}
  .tier.pop .tag{position:absolute;top:-12px;left:50%;transform:translateX(-50%);background:var(--green);color:#fff;font-size:12px;font-weight:700;border-radius:999px;padding:4px 12px}
  .tier h3{margin:0 0 2px;font-size:18px}
  .tier .price{font-size:34px;font-weight:800;letter-spacing:-.02em;margin:8px 0 2px}
  .tier .price small{font-size:14px;font-weight:600;color:var(--muted)}
  .tier .who{color:var(--muted);font-size:14px;margin-bottom:16px;min-height:20px}
  .tier ul{list-style:none;padding:0;margin:0 0 22px;flex:1}
  .tier li{padding:6px 0 6px 26px;position:relative;font-size:14px}
  .tier li:before{content:"✓";position:absolute;left:0;color:var(--green);font-weight:800}
  .tier .btn{width:100%;text-align:center}

  footer.foot{background:var(--navy);color:#cfe3d8;padding:30px 0;text-align:center;font-size:14px}
  footer.foot a{color:#a9e7c4;text-decoration:none;font-weight:600}
  footer.foot .brand b{color:#fff}footer.foot .brand b .g{color:#3FD37E}

  @media(max-width:760px){
    .feat,.tiers{grid-template-columns:1fr}
    .navlinks a.hideol{display:none}
  }
</style>
</head>
<body>

<header class="top"><div class="wrap">
  <a class="brand" href="/"><img src="/assets/goldstarbot-icon-transparent.svg" alt=""><b>goldstar<span class="g">bot</span></b></a>
  <div class="navlinks">
    <a class="hideol" href="#demo">Demo</a>
    <a class="hideol" href="#pricing">Pricing</a>
    <a class="hideol" href="/setup">Setup</a>
    <a href="/login">Login</a>
    <a class="btn gold" href="/checkout?plan=team">Get started</a>
  </div>
</div></header>

<section class="hero">
  <div class="wrap">
    <img class="logo" src="/assets/goldstarbot-full.svg" alt="GoldStarBot">
    <div class="kicker">Peer recognition for Slack</div>
    <h1>Celebrate great work, right where it happens</h1>
    <p>Give teammates a gold star with one command. Tallies, leaderboards &amp; achievements — automatic.</p>
    <div class="cta">
      <a class="btn gold" href="/checkout?plan=team">Start 7-day free trial</a>
      <a class="btn ghost" href="#demo">Try the demo</a>
    </div>
  </div>
</section>

<section class="demo" id="demo">
  <div class="wrap">
    <h2>See it in action</h2>
    <p class="sub">This is a live sandbox — type a command and watch GoldStarBot respond. Nothing leaves your browser.</p>
    <div class="slack">
      <div class="slack-head"><span class="hash">#</span>recognition</div>
      <div class="slack-msgs" id="demoMsgs"></div>
      <form class="slack-input" id="demoForm">
        <input id="demoInput" autocomplete="off" placeholder="Try: /gs @maya shipping the new dashboard">
        <button class="btn" type="submit">Send</button>
      </form>
    </div>
    <div class="chips">
      <button class="chip" data-cmd="/gs @maya shipping the new dashboard">/gs @maya …</button>
      <button class="chip" data-cmd="/gs @leo great customer save">/gs @leo …</button>
      <button class="chip" data-cmd="/gs leaderboard">/gs leaderboard</button>
      <button class="chip" data-cmd="/gs achievements">/gs achievements</button>
    </div>
  </div>
</section>

<section>
  <div class="wrap">
    <h2>Recognition that runs itself</h2>
    <p class="sub">No new app to learn — it lives inside Slack.</p>
    <div class="feat">
      <div class="f"><div class="ic">⭐</div><h3>One-command kudos</h3><p>Type <code class="mono">/gs @teammate</code> in any channel. It posts a celebratory shout everyone can see.</p></div>
      <div class="f"><div class="ic">🏆</div><h3>Tallies &amp; leaderboards</h3><p>Every star is counted per person, with a leaderboard of who's shining brightest.</p></div>
      <div class="f"><div class="ic">🎖️</div><h3>Achievements</h3><p>Six milestone badges unlock automatically — from First Star to Legend at 100.</p></div>
    </div>
  </div>
</section>

<section class="pricing" id="pricing">
  <div class="wrap">
    <h2>Simple pricing</h2>
    <p class="sub">Start with a 7-day free trial. Cancel anytime before it ends and you won't be charged.</p>
    <div class="tiers one">
      <div class="tier pop">
        <div class="tag">7-day free trial</div>
        <h3>Team</h3>
        <div class="price"><span style="text-decoration:line-through;opacity:.4;font-weight:400;font-size:.5em;vertical-align:middle;margin-right:8px">$29</span>$19 <small>/ mo</small></div>
        <div class="who">Unlimited users — one flat price</div>
        <ul>
          <li><code class="mono">/gs</code> recognition in any channel</li>
          <li>Tallies, leaderboard &amp; all 6 achievements</li>
          <li>Central #starbot channel</li>
          <li><strong>Unlimited members — no per-seat fees</strong></li>
          <li>Priority email support</li>
        </ul>
        <a class="btn gold" href="/checkout?plan=team">Start 7-day free trial</a>
        <div class="finep">7 days free, then a flat $19/mo for your whole team — unlimited users. Cancel anytime.</div>
      </div>
    </div>
  </div>
</section>

<footer class="foot"><div class="wrap">
  <a class="brand" href="/" style="justify-content:center;color:#fff;margin-bottom:8px"><img src="/assets/goldstarbot-icon-transparent.svg" alt=""><b>goldstar<span class="g">bot</span></b></a><br>
  <a href="#demo">Demo</a> · <a href="#pricing">Pricing</a> · <a href="/setup">Setup guide</a> · <a href="/login">Log in</a>
  <div style="margin-top:6px"><a href="/terms">Terms</a> · <a href="/privacy">Privacy</a></div>
  <div style="opacity:.6;margin-top:8px">© goldstarbot.com — peer recognition for Slack</div>
</div></footer>

<script>
(function(){
  var msgs=document.getElementById('demoMsgs');
  var tiers=[{t:1,e:'🌟',n:'First Star'},{t:5,e:'✨',n:'Rising Star'},{t:10,e:'⭐',n:'Star Player'},{t:25,e:'🌠',n:'Superstar'},{t:50,e:'💫',n:'All-Star'},{t:100,e:'🏆',n:'Legend'}];
  var tally={maya:4,leo:0,priya:9,sam:2};
  var colors={maya:'#e8794b',leo:'#3aa0d1',priya:'#8b5cf6',sam:'#22A85A'};
  function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
  function sb(){msgs.scrollTop=msgs.scrollHeight;}
  function av(name){var c=colors[name]||'#1F2937';return '<div class="av" style="background:'+c+'">'+esc((name[0]||'?').toUpperCase())+'</div>';}
  function row(a,name,app,text){return '<div class="m">'+a+'<div class="mb"><div class="mn">'+name+(app?'<span class="app">APP</span>':'')+'<span class="mt">now</span></div><div class="mtext">'+text+'</div></div></div>';}
  function you(t){msgs.insertAdjacentHTML('beforeend',row('<div class="av you">Y</div>','You',false,esc(t)));sb();}
  function bot(inner){msgs.insertAdjacentHTML('beforeend',row('<img class="av" src="/assets/goldstarbot-icon.svg" alt="">','GoldStarBot',true,inner));sb();}
  function plur(n){return n===1?'star':'stars';}
  function give(name,reason){
    var prev=tally[name]||0, now=prev+1; tally[name]=now;
    var unlocked=tiers.filter(function(x){return x.t>prev&&x.t<=now;});
    var c='<div class="star"><div class="sh">⭐ <b>You</b> gave a gold star to <b>@'+esc(name)+'</b>!</div>';
    if(reason) c+='<div class="sq">'+esc(reason)+'</div>';
    c+='<div class="sc"><b>@'+esc(name)+'</b> now has <b>'+now+'</b> '+plur(now)+'.</div>';
    if(unlocked.length){c+='<div class="ach">🎉 '+unlocked.map(function(u){return u.e+' <b>@'+esc(name)+'</b> unlocked <b>'+u.n+'</b> — '+u.t+' '+plur(u.t)+'!';}).join('<br>')+'</div>';}
    c+='</div>'; bot(c);
  }
  function leaderboard(){
    var rows=Object.keys(tally).map(function(k){return [k,tally[k]];}).sort(function(a,b){return b[1]-a[1];}).slice(0,5);
    var med=['🥇','🥈','🥉'];
    var list=rows.map(function(r,i){return (med[i]||('<b>'+(i+1)+'.</b>'))+' <b>@'+esc(r[0])+'</b> — '+r[1]+' '+plur(r[1]);}).join('<br>');
    bot('<div class="star"><div class="sh">⭐ Gold Star Leaderboard</div><div class="sc">'+list+'</div></div>');
  }
  function achievements(){
    bot('<div class="star"><div class="sh">⭐ Achievements</div><div class="sc">'+tiers.map(function(t){return t.e+' <b>'+t.n+'</b> — '+t.t+' '+plur(t.t);}).join('<br>')+'</div></div>');
  }
  function handle(raw){
    var cmd=(raw||'').trim(); if(!cmd)return;
    you(cmd);
    if(!/^\/gs\b/i.test(cmd)){bot('Type <code>/gs @name reason</code> to give a gold star.');return;}
    var rest=cmd.replace(/^\/gs\s*/i,'');
    if(/^(leaderboard|top)/i.test(rest))return leaderboard();
    if(/^help/i.test(rest)){bot('Try <code>/gs @teammate why they earned it</code>, <code>/gs leaderboard</code>, or <code>/gs achievements</code>.');return;}
    if(/^(achievements|badges)/i.test(rest))return achievements();
    var m=rest.match(/@([a-z0-9_.]+)/i);
    if(!m){bot('Who are you recognizing? Try <code>/gs @maya great work</code>');return;}
    give(m[1].toLowerCase(), rest.replace(/@[a-z0-9_.]+/i,'').trim());
  }
  document.getElementById('demoForm').addEventListener('submit',function(e){e.preventDefault();var i=document.getElementById('demoInput');handle(i.value);i.value='';});
  Array.prototype.forEach.call(document.querySelectorAll('.chip'),function(b){b.addEventListener('click',function(){handle(b.getAttribute('data-cmd'));});});
  // seed one interaction so the sandbox isn't empty (maya 4 -> 5 unlocks Rising Star)
  you('/gs @maya launching the new onboarding flow');
  give('maya','launching the new onboarding flow');
})();
</script>
</body>
</html>
HTML;

        $response->getBody()->write($html);
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
    }
}
