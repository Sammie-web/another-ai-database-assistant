<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Another · Database assistant</title>
    <style>
        :root{color-scheme:light;font-family:Inter,system-ui,sans-serif;color:#172d35;background:#f2f5f3}*{box-sizing:border-box}body{margin:0}button,textarea{font:inherit}button{cursor:pointer}button:disabled{opacity:.55;cursor:wait}.shell{display:grid;grid-template-columns:250px 1fr;min-height:100vh}aside{background:#143d38;color:#e3ede8;padding:32px 24px}aside h2{font-size:23px;letter-spacing:-1px}aside p{font-size:14px;line-height:1.7;color:#bad2c9}.badge{display:inline-block;border:1px solid #80a197;border-radius:20px;padding:6px 10px;font-size:12px}.workspace{max-width:1000px;width:100%;margin:auto;padding:32px 48px}header{display:flex;justify-content:space-between;align-items:center;gap:20px;border-bottom:1px solid #d6e0da;padding-bottom:24px}h1{font-size:25px;letter-spacing:-.7px;margin:6px 0}.eyebrow{text-transform:uppercase;letter-spacing:2px;font-size:11px;color:#56756c}.quiet{border:1px solid #ccd9d1;background:white;padding:10px 14px;border-radius:8px}.intro{padding:45px 0 22px}.intro h2{font-size:34px;max-width:530px;font-weight:550;letter-spacing:-1px}.intro p{color:#60766d;line-height:1.7}.suggestions{display:flex;gap:10px;flex-wrap:wrap}.suggestions button{background:#fff;border:1px solid #d6e0da;padding:12px;border-radius:10px;color:#284d41}#messages{padding:24px 0;min-height:140px}.message{background:white;border:1px solid #dae3dd;border-radius:12px;padding:18px 20px;margin:12px 0;white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.6}.message.user{background:#e2eee7;margin-left:45px}.label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#597468;margin-bottom:8px}.proposal{border:1px solid #c69d50;padding:18px;border-radius:12px;background:#fffbef;margin:12px 0}.proposal pre,details pre{overflow:auto;font-size:12px;max-height:280px;white-space:pre-wrap}.approve,.send{border:0;background:#22634f;color:white;border-radius:8px;padding:12px 18px}.composer{background:white;border:1px solid #b9cec1;border-radius:14px;padding:15px;box-shadow:0 8px 25px #163e3010}.composer textarea{width:100%;border:0;resize:vertical;min-height:65px;outline-offset:4px}.composer footer{display:flex;align-items:center;justify-content:space-between;gap:12px}.hint{font-size:12px;color:#637c6e}#status{min-height:28px;color:#805d26;font-size:14px}details{margin:14px 0}summary{cursor:pointer;color:#426d5a}.privacy{font-size:12px;color:#63776e;margin-top:15px;line-height:1.6}@media(max-width:750px){.shell{display:block}aside{padding:16px 24px}aside p,aside .badge{display:none}aside h2{margin:0}.workspace{padding:24px}.intro h2{font-size:28px}header{align-items:flex-start}.message.user{margin-left:20px}}
    </style>
</head>
<body><div class="shell">
<aside><h2>another<span style="color:#94bf6c">.</span></h2><p>Your workspace<br>Database assistant</p><div class="badge">Scoped to your workspace</div><p style="margin-top:60px">Explore your data.<br>Understand your records.<br>Review every change.</p></aside>
<main class="workspace"><header><div><span class="eyebrow">Workspace intelligence</span><h1>Database assistant</h1></div><button class="quiet" id="reset">New conversation</button></header>
<section class="intro"><span class="eyebrow">Ask in plain language</span><h2>What would you like to know about your data?</h2><p>Explore available records, get counts, or prepare a change for review.</p><div class="suggestions"><button>What data can I access?</button><button>Show my first 10 projects</button><button>How many projects do I have?</button></div></section>
<section id="messages" role="log" aria-label="Conversation" aria-live="polite"></section><div id="status" role="status"></div>
<form class="composer" id="chat"><label class="hint" for="prompt">Your message</label><textarea id="prompt" maxlength="4000" required placeholder="Ask a question about your workspace…"></textarea><footer><span class="hint">Changes require your confirmation.</span><button class="send" id="send">Send message ↗</button></footer></form>
<p class="privacy">AI answers can contain mistakes. Check source results before acting. Permitted database results are sent to {{ config('assistant.provider') === 'gemini' ? 'Google Gemini' : 'OpenAI' }} to answer your question.</p>
</main></div>
<script>
const endpoints = {chat: @json(route('assistant.chat')), reset: @json(route('assistant.reset')), confirm: @json(url('assistant/proposals'))};
const messages = document.querySelector('#messages'), promptBox = document.querySelector('#prompt'), status = document.querySelector('#status');
let busy = false;
async function post(url, body) {
    const response = await fetch(url, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}, body:JSON.stringify(body)});
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || 'Request failed. Refresh the page and try again.');
    return data;
}
function addMessage(role, text) {
    const el = document.createElement('div'); el.className = 'message ' + role;
    const label = document.createElement('strong'); label.className='label'; label.textContent=role === 'user' ? 'You' : 'Assistant';
    el.append(label, document.createTextNode(text)); messages.append(el);
}
function showProposal(proposal) {
    const card = document.createElement('section'); card.className='proposal';
    const title=document.createElement('strong'); title.textContent='Review change · ' + proposal.operation + ' ' + proposal.resource;
    const preview=document.createElement('pre'); preview.textContent=JSON.stringify({record_id:proposal.record_id,before:proposal.before,after:proposal.after},null,2);
    const button=document.createElement('button'); button.className='approve'; button.textContent='Confirm this change';
    const dismiss=document.createElement('button'); dismiss.className='quiet'; dismiss.textContent='Dismiss'; dismiss.onclick=()=>card.remove();
    const note=document.createElement('p'); note.className='hint'; note.textContent='One record only. Preview expires after 10 minutes. Deletion may be irreversible.';
    button.onclick=async()=>{button.disabled=true;try{const result=await post(endpoints.confirm+'/'+encodeURIComponent(proposal.proposal_id)+'/confirm',{confirmed:true});button.textContent=result.status==='already_confirmed'?'Already applied':'Change applied';dismiss.remove();}catch(error){note.textContent=error.message;button.disabled=false;}};
    card.append(title,preview,note,button,dismiss);messages.append(card);
}
document.querySelector('#chat').onsubmit=async(event)=>{
    event.preventDefault(); const message=promptBox.value.trim();if(busy||!message)return;
    busy=true;document.querySelector('#send').disabled=true;document.querySelector('#reset').disabled=true;
    addMessage('user',message);promptBox.value='';status.textContent='Checking your workspace…';
    try {const result=await post(endpoints.chat,{message});addMessage('assistant',result.text);
        for(const evidence of result.evidence || []) {const details=document.createElement('details'),summary=document.createElement('summary'),pre=document.createElement('pre');summary.textContent='Source results · '+evidence.resource;pre.textContent=JSON.stringify(evidence.result,null,2);details.append(summary,pre);messages.append(details);}
        for(const proposal of result.proposals || [])showProposal(proposal);status.textContent='';
    }catch(error){status.textContent=error.message;promptBox.value=message;}
    finally{busy=false;document.querySelector('#send').disabled=false;document.querySelector('#reset').disabled=false;promptBox.focus();}
};
document.querySelectorAll('.suggestions button').forEach(button=>button.onclick=()=>{promptBox.value=button.textContent;promptBox.focus();});
document.querySelector('#reset').onclick=async()=>{if(busy)return;try{await post(endpoints.reset,{});messages.replaceChildren();status.textContent='Conversation cleared.';}catch(error){status.textContent=error.message;}};
</script></body></html>
