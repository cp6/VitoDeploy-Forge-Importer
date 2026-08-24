<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') === 'dark'])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Forge Site Importer · {{ config('app.name', 'VitoDeploy') }}</title>
    <style>
        :root { color-scheme: light dark; --bg:#f7f7f8; --card:#fff; --text:#18181b; --muted:#71717a; --line:#e4e4e7; --brand:#ef4444; --ok:#15803d; --bad:#b91c1c; --warn:#a16207; }
        .dark { --bg:#09090b; --card:#18181b; --text:#fafafa; --muted:#a1a1aa; --line:#3f3f46; --brand:#f87171; --ok:#4ade80; --bad:#f87171; --warn:#facc15; }
        * { box-sizing:border-box } body { margin:0; background:var(--bg); color:var(--text); font:14px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif }
        .shell { max-width:1180px; margin:0 auto; padding:28px 20px 60px } .top { display:flex; align-items:center; justify-content:space-between; gap:18px; margin-bottom:24px }
        h1 { font-size:25px; margin:0 0 4px } h2 { font-size:18px; margin:0 } h3 { margin:0; font-size:16px } p { margin:5px 0; color:var(--muted) }
        .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:18px; margin-bottom:16px; box-shadow:0 1px 2px rgb(0 0 0 / .04) }
        .grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px } .grid-3 { grid-template-columns:repeat(3,minmax(0,1fr)) } .full { grid-column:1/-1 }
        label { display:block; font-weight:600; margin-bottom:5px } input,select,textarea { width:100%; border:1px solid var(--line); border-radius:7px; padding:9px 10px; background:var(--card); color:var(--text) }
        input[type=checkbox] { width:auto; accent-color:var(--brand) } button,.button { border:1px solid transparent; border-radius:7px; padding:9px 13px; font-weight:650; cursor:pointer; background:var(--brand); color:#fff; text-decoration:none }
        button.secondary,.button.secondary { background:transparent; border-color:var(--line); color:var(--text) } button:disabled { opacity:.5; cursor:not-allowed }
        .row { display:flex; align-items:center; gap:10px; flex-wrap:wrap } .between { justify-content:space-between } .hidden { display:none!important }
        .notice { border-left:4px solid var(--warn); background:color-mix(in srgb,var(--warn) 10%,transparent); padding:11px 13px; border-radius:5px; margin-bottom:16px }
        .error { border-color:var(--bad)!important; color:var(--bad) } .status { font-size:12px; border-radius:999px; padding:3px 8px; background:var(--bg); border:1px solid var(--line) }
        .check { display:flex; align-items:flex-start; gap:7px; padding:5px 0; color:var(--muted) } .check.matched { color:var(--ok) } .check.blocked { color:var(--bad) }
        .resource-list { display:flex; flex-wrap:wrap; gap:12px; padding:10px 0 } .resource-list label { font-weight:500; margin:0; display:flex; gap:6px; align-items:center }
        .site-card { border:1px solid var(--line); border-radius:10px; padding:15px; margin-top:13px } .site-card.disabled { opacity:.58 }
        .progress { height:9px; background:var(--line); border-radius:99px; overflow:hidden; margin:12px 0 } .progress span { display:block; height:100%; width:0; background:var(--brand); transition:width .3s }
        .result-site { border-top:1px solid var(--line); padding:10px 0 } code { background:var(--bg); border:1px solid var(--line); padding:1px 4px; border-radius:4px }
        @media(max-width:760px){ .grid,.grid-3{grid-template-columns:1fr}.top{align-items:flex-start;flex-direction:column} }
    </style>
</head>
<body>
<main class="shell">
    <div class="top">
        <div><h1>Laravel Forge Site Importer</h1><p>Preview, customize and import up to {{ $maxSites }} sites at once.</p></div>
        <a href="{{ route('servers') }}" class="button secondary">Back to Vito</a>
    </div>

    <div class="notice"><strong>Safe by default.</strong> Forge remains read-only. This importer does not move database contents/files or change DNS/SSL, and imported deployment scripts are not executed.</div>
    <div id="message" class="card hidden"></div>

    <section class="card" id="connection-card">
        <div class="row between"><div><h2>1. Connect Forge</h2><p>The token stays in your encrypted Vito session and is not saved with an import.</p></div><span id="connection-status" class="status">{{ $connected ? 'Connected' : 'Not connected' }}</span></div>
        <form id="connect-form" class="row" style="margin-top:14px">
            <input id="token" type="password" autocomplete="off" placeholder="Forge API token" style="flex:1;min-width:240px" {{ $connected ? 'disabled' : '' }}>
            <button type="submit" id="connect-button" {{ $connected ? 'disabled' : '' }}>Connect</button>
            <button type="button" class="secondary {{ $connected ? '' : 'hidden' }}" id="disconnect-button">Disconnect</button>
        </form>
    </section>

    <section class="card {{ $connected ? '' : 'hidden' }}" id="selection-card">
        <h2>2. Choose sites and destination</h2>
        <div class="grid grid-3" style="margin-top:14px">
            <div><label for="organization">Forge organization</label><select id="organization"><option value="">Loading…</option></select></div>
            <div><label for="forge-server">Forge server</label><select id="forge-server" disabled><option value="">Select organization</option></select></div>
            <div><label for="target-server">Vito destination server</label><select id="target-server">
                <option value="">Select destination</option>
                @foreach($servers as $server)<option value="{{ $server['id'] }}" @selected($selectedServer === $server['id'])>{{ $server['name'] }} · {{ $server['status'] }}</option>@endforeach
            </select></div>
            <div class="full"><label>Forge sites</label><div id="forge-sites"><p>Select a Forge server.</p></div></div>
            <div class="full"><label>Discover resources</label><div class="resource-list" id="global-resources">
                <label><input type="checkbox" value="domains" checked> Domains/aliases</label>
                <label><input type="checkbox" value="environment" checked> .env</label>
                <label><input type="checkbox" value="deployment_script" checked> Deployment script</label>
                <label><input type="checkbox" value="cron_jobs" checked> Cron jobs</label>
                <label><input type="checkbox" value="workers" checked> Background processes</label>
            </div></div>
        </div>
        <button id="preview-button" type="button">Generate preview</button>
    </section>

    <section class="card hidden" id="preview-card">
        <div class="row between"><div><h2>3. Review and customize</h2><p>Green ticks match. Red items must be corrected in the editable mapping.</p></div><span class="status" id="plan-expiry"></span></div>
        <div id="preview-sites"></div>
        <div class="row" style="margin-top:16px"><button type="button" id="import-button">Start selected imports</button><button type="button" class="secondary" id="back-button">Change selection</button></div>
    </section>

    <section class="card hidden" id="run-card">
        <div class="row between"><div><h2>4. Import progress</h2><p id="run-step">Queued</p></div><span class="status" id="run-status">pending</span></div>
        <div class="progress"><span id="run-progress"></span></div>
        <div id="run-results"></div>
        <div class="row"><button type="button" class="secondary hidden" id="retry-button">Retry incomplete</button><button type="button" class="secondary" id="new-button">New import</button></div>
    </section>
</main>

<script>
const CONFIG = @json($frontendConfig);
const state = { plan:null, organization:'', forgeServer:'', targetServer:String(CONFIG.selectedServer || ''), runId:null, poll:null };
const $ = (id) => document.getElementById(id);
const csrf = document.querySelector('meta[name="csrf-token"]').content;
const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));

async function api(url, options = {}) {
    const headers = {'Accept':'application/json','X-CSRF-TOKEN':csrf,...(options.headers || {})};
    if (options.body && !(options.body instanceof FormData)) headers['Content-Type'] = 'application/json';
    const response = await fetch(url, {...options, headers});
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || `Request failed (${response.status})`);
    return data;
}
function message(text, bad=false) { const box=$('message'); box.textContent=text; box.className=`card ${bad?'error':''}`; setTimeout(()=>box.classList.add('hidden'),7000); }
function option(value, label) { return `<option value="${esc(value)}">${esc(label)}</option>`; }
function resourceValues() { return [...document.querySelectorAll('#global-resources input:checked')].map(i=>i.value); }

async function loadOrganizations() {
    try {
        const {data} = await api(CONFIG.urls.organizations);
        $('organization').innerHTML = '<option value="">Select organization</option>' + data.map(o=>option(o.slug || o.id, o.name || o.slug || o.id)).join('');
    } catch(e) { message(e.message,true); }
}
async function loadForgeServers() {
    state.organization=$('organization').value; $('forge-server').disabled=true; $('forge-server').innerHTML='<option>Loading…</option>'; $('forge-sites').innerHTML='<p>Select a Forge server.</p>';
    if(!state.organization){$('forge-server').innerHTML='<option value="">Select organization</option>';return;}
    try { const {data}=await api(`${CONFIG.urls.forgeServers}?organization=${encodeURIComponent(state.organization)}`); $('forge-server').innerHTML='<option value="">Select server</option>'+data.map(s=>option(s.id,s.name || s.id)).join(''); $('forge-server').disabled=false; }
    catch(e){message(e.message,true);}
}
async function loadForgeSites() {
    state.forgeServer=$('forge-server').value; $('forge-sites').innerHTML='<p>Loading sites…</p>'; if(!state.forgeServer)return;
    try { const {data}=await api(`${CONFIG.urls.forgeSites}?organization=${encodeURIComponent(state.organization)}&server=${encodeURIComponent(state.forgeServer)}`);
        $('forge-sites').innerHTML=data.length?data.map(s=>`<label style="display:inline-flex;align-items:center;gap:6px;margin:0 18px 8px 0;font-weight:500"><input class="forge-site" type="checkbox" value="${esc(s.id)}"> ${esc(s.name || s.domain || s.id)}</label>`).join(''):'<p>No sites found.</p>';
    } catch(e){message(e.message,true);}
}
async function generatePreview() {
    const siteIds=[...document.querySelectorAll('.forge-site:checked')].map(i=>i.value); state.targetServer=$('target-server').value;
    if(!state.organization || !state.forgeServer || !state.targetServer || !siteIds.length) return message('Choose an organization, Forge server, at least one site, and a Vito destination.',true);
    const button=$('preview-button'); button.disabled=true; button.textContent='Building preview…';
    try { state.plan=await api(CONFIG.urls.preview,{method:'POST',body:JSON.stringify({organization:state.organization,forge_server_id:state.forgeServer,site_ids:siteIds,target_server_id:Number(state.targetServer),resources:resourceValues()})}); renderPreview(); $('preview-card').classList.remove('hidden'); $('selection-card').classList.add('hidden'); }
    catch(e){message(e.message,true);} finally {button.disabled=false;button.textContent='Generate preview';}
}
function suggestedSource(provider) { const found=CONFIG.sourceControls.find(s=>String(s.provider).startsWith(provider || '')); return found?.id || CONFIG.sourceControls[0]?.id || ''; }
function selectOptions(items,current,getValue=x=>x.id,getLabel=x=>x.label) { return items.map(item=>`<option value="${esc(getValue(item))}" ${String(getValue(item))===String(current)?'selected':''}>${esc(getLabel(item))}</option>`).join(''); }
function renderPreview() {
    $('plan-expiry').textContent=`Preview expires in ${state.plan.expires_in_minutes} minutes`;
    $('preview-sites').innerHTML=state.plan.sites.map((item,index)=>{
        const d=item.defaults, sc=suggestedSource(d.source_control_provider), checks=item.checks.map(c=>`<div class="check ${c.status}"><span>${c.status==='matched'?'✓':'✕'}</span><span>${esc(c.label)}: <code>${esc(c.value)}</code></span></div>`).join('');
        const res={domains:true,environment:item.environment.available,deployment_script:item.deployment_script.available,cron_jobs:item.cron_jobs.length>0,workers:item.workers.length>0};
        return `<article class="site-card" data-index="${index}">
          <div class="row between"><div><h3>${esc(d.domain)}</h3><p>Forge site ${esc(d.forge_site_id)}</p></div><label class="row"><input class="site-enabled" type="checkbox" checked> Import site</label></div>
          <div style="margin:9px 0">${checks}</div>
          <div class="grid grid-3">
            <div><label>Domain</label><input data-field="domain" value="${esc(d.domain)}"></div>
            <div><label>Vito site type</label><select data-field="type">${selectOptions(CONFIG.siteTypes,d.type)}</select></div>
            <div><label>Site user</label><input data-field="user" value="${esc(d.user)}"></div>
            <div><label>PHP version</label><input data-field="php_version" value="${esc(d.php_version)}"></div>
            <div><label>Web directory</label><input data-field="web_directory" value="${esc(d.web_directory)}"></div>
            <div><label>Aliases (comma-separated)</label><input data-field="aliases" value="${esc(d.aliases.join(', '))}"></div>
            <div><label>Source control</label><select data-field="source_control_id"><option value="">None</option>${selectOptions(CONFIG.sourceControls,sc,x=>x.id,x=>`${x.provider} · ${x.profile}`)}</select></div>
            <div><label>Repository</label><input data-field="repository" value="${esc(d.repository)}"></div>
            <div><label>Branch</label><input data-field="branch" value="${esc(d.branch)}"></div>
            <div><label>App port</label><input data-field="port" type="number" value="${esc(d.port)}"></div>
            <div><label>Node version</label><input data-field="node_version" value="${esc(d.node_version)}"></div>
            <div><label>Start command</label><input data-field="start_command" value="${esc(d.start_command)}"></div>
          </div>
          <div class="resource-list"><strong>Import:</strong>
            ${resourceToggle('domains','Domains/aliases',res.domains)}${resourceToggle('environment',`.env (${item.environment.keys.length} keys)`,res.environment)}${resourceToggle('deployment_script',`Deployment script (${item.deployment_script.lines} lines)`,res.deployment_script)}${resourceToggle('cron_jobs',`Cron jobs (${item.cron_jobs.length})`,res.cron_jobs)}${resourceToggle('workers',`Processes (${item.workers.length})`,res.workers)}
            <label><input data-field="composer" type="checkbox"> Run Composer during site creation</label>
          </div>
        </article>`;
    }).join('');
    document.querySelectorAll('.site-enabled').forEach(box=>box.addEventListener('change',()=>box.closest('.site-card').classList.toggle('disabled',!box.checked)));
}
function resourceToggle(key,label,available) { return `<label title="${available?'':'Not returned by Forge'}"><input data-resource="${key}" type="checkbox" ${available?'checked':'disabled'}> ${esc(label)}</label>`; }
function collectSites() {
    return [...document.querySelectorAll('.site-card')].map((card,index)=>{ const d=state.plan.sites[index].defaults, value=(name)=>card.querySelector(`[data-field="${name}"]`); const resources={}; card.querySelectorAll('[data-resource]').forEach(i=>resources[i.dataset.resource]=i.checked);
      return {forge_site_id:String(d.forge_site_id),enabled:card.querySelector('.site-enabled').checked,domain:value('domain').value.trim(),aliases:value('aliases').value.split(',').map(x=>x.trim()).filter(Boolean),type:value('type').value,user:value('user').value.trim(),php_version:value('php_version').value.trim()||null,source_control_id:value('source_control_id').value?Number(value('source_control_id').value):null,repository:value('repository').value.trim()||null,branch:value('branch').value.trim()||null,web_directory:value('web_directory').value.trim(),port:Number(value('port').value||3000),node_version:value('node_version').value.trim()||'22',package_manager:'node',start_command:value('start_command').value.trim()||null,composer:value('composer').checked,resources};
    });
}
async function startImport() {
    const button=$('import-button'); button.disabled=true; button.textContent='Queueing…';
    try { const run=await api(CONFIG.urls.runs,{method:'POST',body:JSON.stringify({plan_id:state.plan.plan_id,organization:state.organization,forge_server_id:state.forgeServer,target_server_id:Number(state.targetServer),sites:collectSites()})}); state.runId=run.id; $('preview-card').classList.add('hidden'); $('run-card').classList.remove('hidden'); renderRun(run); pollRun(); }
    catch(e){message(e.message,true);button.disabled=false;button.textContent='Start selected imports';}
}
function renderRun(run) { $('run-status').textContent=run.status; $('run-step').textContent=run.current_step || ''; $('run-progress').style.width=`${run.progress}%`; const sites=run.result?.sites || {};
    $('run-results').innerHTML=Object.values(sites).map(s=>`<div class="result-site"><div class="row between"><strong>${esc(s.domain || 'Site')}</strong><span class="status">${esc(s.state)}</span></div>${s.vito_site_id?`<p>Vito site ID: ${esc(s.vito_site_id)}</p>`:''}${s.error?`<p class="error">${esc(s.error)}</p>`:''}${(s.warnings||[]).map(w=>`<p style="color:var(--warn)">⚠ ${esc(w)}</p>`).join('')}</div>`).join('');
    $('retry-button').classList.toggle('hidden',!['failed','partial'].includes(run.status));
}
function pollRun() { clearInterval(state.poll); const tick=async()=>{ try { const run=await api(`${CONFIG.urls.runBase}/${state.runId}`); renderRun(run); if(['complete','partial','failed','cancelled'].includes(run.status))clearInterval(state.poll); } catch(e){message(e.message,true);clearInterval(state.poll);} }; tick(); state.poll=setInterval(tick,4000); }

$('connect-form').addEventListener('submit',async e=>{e.preventDefault();try{await api(CONFIG.urls.connect,{method:'POST',body:JSON.stringify({token:$('token').value})});location.reload();}catch(err){message(err.message,true);}});
$('disconnect-button').addEventListener('click',async()=>{await api(CONFIG.urls.connect,{method:'DELETE'});location.reload();});
$('organization').addEventListener('change',loadForgeServers); $('forge-server').addEventListener('change',loadForgeSites); $('target-server').addEventListener('change',e=>state.targetServer=e.target.value);
$('preview-button').addEventListener('click',generatePreview); $('import-button').addEventListener('click',startImport); $('back-button').addEventListener('click',()=>{$('preview-card').classList.add('hidden');$('selection-card').classList.remove('hidden');});
$('new-button').addEventListener('click',()=>location.reload()); $('retry-button').addEventListener('click',async()=>{const run=await api(`${CONFIG.urls.runBase}/${state.runId}/retry`,{method:'POST'});renderRun(run);pollRun();});
if(CONFIG.connected) loadOrganizations();
</script>
</body>
</html>
