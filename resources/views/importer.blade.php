<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') === 'dark'])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Forge Site Importer · {{ config('app.name', 'VitoDeploy') }}</title>
    <script>
        if ('{{ $appearance ?? 'system' }}' === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.documentElement.classList.add('dark');
        }
    </script>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet">
    <link rel="stylesheet" href="{{ route('forge-importer.styles') }}">
</head>
<body class="bg-background text-foreground selection:bg-brand min-h-screen font-sans antialiased selection:text-white">
<main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 lg:py-12">
    <header class="mb-8 flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
        <div class="max-w-2xl">
            <p class="text-muted-foreground mb-2 text-sm font-medium">Server migration</p>
            <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">Import from Laravel Forge</h1>
            <p class="text-muted-foreground mt-2 text-sm sm:text-base">Review and move up to {{ $maxSites }} sites into Vito without changing the source server.</p>
        </div>
        <a href="{{ route('servers') }}" class="bg-background hover:bg-muted inline-flex h-9 items-center justify-center rounded-md border px-4 text-sm font-medium shadow-xs transition-colors">Back to servers</a>
    </header>

    <nav class="mb-8 grid grid-cols-2 overflow-hidden rounded-lg border bg-card sm:grid-cols-4" aria-label="Import progress">
        @foreach([['Connect', 'Forge account'], ['Select', 'Sites and destination'], ['Review', 'Compatibility'], ['Import', 'Progress and results']] as $index => [$title, $description])
            <div class="{{ $index > 0 ? 'border-l' : '' }} {{ $index > 1 ? 'border-t sm:border-t-0' : '' }} px-4 py-3">
                <div class="flex items-baseline gap-2">
                    <span class="{{ $index === 0 || $connected ? 'text-primary' : 'text-muted-foreground' }} text-xs font-semibold">{{ $index + 1 }}</span>
                    <span class="text-sm font-medium">{{ $title }}</span>
                </div>
                <p class="text-muted-foreground mt-0.5 pl-5 text-xs">{{ $description }}</p>
            </div>
        @endforeach
    </nav>

    <div class="bg-muted/30 text-muted-foreground mb-6 rounded-lg border px-4 py-3 text-sm">
        <span class="text-foreground font-medium">Read-only on Forge.</span> Database contents, files, DNS, and SSL stay untouched. Imported deployment scripts are saved but not executed.
    </div>
    <div id="message" class="mb-6 hidden rounded-lg border px-4 py-3 text-sm"></div>

    <section class="overflow-hidden rounded-xl border bg-card shadow-xs" id="connection-card">
        <div class="flex flex-col gap-3 border-b px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="font-semibold">Forge connection</h2>
                <p class="text-muted-foreground mt-1 text-sm">The API token is kept in your encrypted Vito session.</p>
            </div>
            <span id="connection-status" class="{{ $connected ? 'border-green-200 bg-green-50 text-green-700 dark:border-green-900 dark:bg-green-950/40 dark:text-green-400' : 'bg-muted text-muted-foreground' }} w-fit rounded-md border px-2.5 py-1 text-xs font-medium">{{ $connected ? 'Connected' : 'Not connected' }}</span>
        </div>
        <form id="connect-form" class="flex flex-col gap-3 p-5 sm:flex-row">
            <label class="sr-only" for="token">Forge API token</label>
            <input id="token" class="border-input bg-background focus-visible:border-ring focus-visible:ring-ring/50 h-9 min-w-0 flex-1 rounded-md border px-3 text-sm shadow-xs outline-none focus-visible:ring-3 disabled:cursor-not-allowed disabled:opacity-50" type="password" autocomplete="off" placeholder="Forge API token" {{ $connected ? 'disabled' : '' }}>
            <button type="submit" id="connect-button" class="bg-primary text-primary-foreground hover:bg-primary/90 h-9 rounded-md px-4 text-sm font-medium shadow-xs transition-colors disabled:pointer-events-none disabled:opacity-50" {{ $connected ? 'disabled' : '' }}>Connect</button>
            <button type="button" class="bg-background hover:bg-muted h-9 rounded-md border px-4 text-sm font-medium shadow-xs transition-colors {{ $connected ? '' : 'hidden' }}" id="disconnect-button">Disconnect</button>
        </form>
    </section>

    <section class="mt-6 overflow-hidden rounded-xl border bg-card shadow-xs {{ $connected ? '' : 'hidden' }}" id="selection-card">
        <div class="border-b px-5 py-4">
            <h2 class="font-semibold">Choose what to import</h2>
            <p class="text-muted-foreground mt-1 text-sm">Select the Forge source, Vito destination, and resources to inspect.</p>
        </div>
        <div class="p-5">
            <div class="grid gap-5 md:grid-cols-3">
                <div class="space-y-2"><label class="text-sm font-medium" for="organization">Forge organization</label><select class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm shadow-xs outline-none focus-visible:ring-3" id="organization"><option value="">Loading…</option></select></div>
                <div class="space-y-2"><label class="text-sm font-medium" for="forge-server">Forge server</label><select class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm shadow-xs outline-none focus-visible:ring-3 disabled:opacity-50" id="forge-server" disabled><option value="">Select organization</option></select></div>
                <div class="space-y-2"><label class="text-sm font-medium" for="target-server">Vito destination</label><select class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm shadow-xs outline-none focus-visible:ring-3" id="target-server">
                    <option value="">Select destination</option>
                    @foreach($servers as $server)<option value="{{ $server['id'] }}" @selected($selectedServer === $server['id'])>{{ $server['name'] }} · {{ $server['status'] }}</option>@endforeach
                </select></div>
                <div class="space-y-2 md:col-span-3"><span class="text-sm font-medium">Forge sites</span><div id="forge-sites" class="bg-muted/20 text-muted-foreground min-h-12 rounded-lg border px-4 py-3 text-sm">Select a Forge server.</div></div>
                <fieldset class="space-y-3 md:col-span-3">
                    <legend class="text-sm font-medium">Resources to inspect</legend>
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3" id="global-resources">
                        @foreach([['domains', 'Domains and aliases'], ['environment', 'Environment variables'], ['database', 'Database setup'], ['deployment_script', 'Deployment script'], ['cron_jobs', 'Cron jobs'], ['workers', 'Background processes']] as [$value, $label])
                            <label class="hover:bg-muted/40 flex cursor-pointer items-center gap-3 rounded-md border px-3 py-2.5 text-sm transition-colors"><input class="accent-primary size-4" type="checkbox" value="{{ $value }}" checked> {{ $label }}</label>
                        @endforeach
                    </div>
                </fieldset>
            </div>
            <div class="mt-6 flex justify-end border-t pt-5"><button id="preview-button" class="bg-primary text-primary-foreground hover:bg-primary/90 h-9 rounded-md px-4 text-sm font-medium shadow-xs transition-colors disabled:pointer-events-none disabled:opacity-50" type="button">Generate preview</button></div>
        </div>
    </section>

    <section class="mt-6 hidden overflow-hidden rounded-xl border bg-card shadow-xs" id="preview-card">
        <div class="flex flex-col gap-3 border-b px-5 py-4 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="font-semibold">Review the import plan</h2><p class="text-muted-foreground mt-1 text-sm">Matched settings are ready. Resolve blocked items or leave that resource unchecked.</p></div><span class="bg-muted text-muted-foreground w-fit rounded-md px-2.5 py-1 text-xs font-medium" id="plan-expiry"></span></div>
        <div class="space-y-4 p-5" id="preview-sites"></div>
        <div class="flex flex-col-reverse gap-3 border-t px-5 py-4 sm:flex-row sm:justify-end"><button type="button" class="bg-background hover:bg-muted h-9 rounded-md border px-4 text-sm font-medium shadow-xs transition-colors" id="back-button">Change selection</button><button type="button" class="bg-primary text-primary-foreground hover:bg-primary/90 h-9 rounded-md px-4 text-sm font-medium shadow-xs transition-colors disabled:pointer-events-none disabled:opacity-50" id="import-button">Start selected imports</button></div>
    </section>

    <section class="mt-6 hidden overflow-hidden rounded-xl border bg-card shadow-xs" id="run-card">
        <div class="flex items-start justify-between gap-4 border-b px-5 py-4"><div><h2 class="font-semibold">Import progress</h2><p class="text-muted-foreground mt-1 text-sm" id="run-step">Queued</p></div><span class="bg-muted text-muted-foreground rounded-md px-2.5 py-1 text-xs font-medium" id="run-status">pending</span></div>
        <div class="p-5"><div class="bg-muted mb-5 h-2 overflow-hidden rounded-full"><span class="bg-primary block h-full w-0 transition-[width]" id="run-progress"></span></div><div class="divide-y" id="run-results"></div></div>
        <div class="flex gap-3 border-t px-5 py-4"><button type="button" class="bg-background hover:bg-muted hidden h-9 rounded-md border px-4 text-sm font-medium shadow-xs transition-colors" id="retry-button">Retry incomplete</button><button type="button" class="bg-background hover:bg-muted h-9 rounded-md border px-4 text-sm font-medium shadow-xs transition-colors" id="new-button">New import</button></div>
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
function message(text, bad=false) { const box=$('message'); box.textContent=text; box.className=`mb-6 rounded-lg border px-4 py-3 text-sm ${bad?'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-400':'bg-muted/30 text-foreground'}`; setTimeout(()=>box.classList.add('hidden'),7000); }
function option(value, label) { return `<option value="${esc(value)}">${esc(label)}</option>`; }
function resourceValues() { return [...document.querySelectorAll('#global-resources input:checked')].map(i=>i.value); }

async function loadOrganizations() {
    try {
        const {data} = await api(CONFIG.urls.organizations);
        $('organization').innerHTML = '<option value="">Select organization</option>' + data.map(o=>option(o.slug || o.id, o.name || o.slug || o.id)).join('');
    } catch(e) { message(e.message,true); }
}
async function loadForgeServers() {
    state.organization=$('organization').value; $('forge-server').disabled=true; $('forge-server').innerHTML='<option>Loading…</option>'; $('forge-sites').innerHTML='Select a Forge server.';
    if(!state.organization){$('forge-server').innerHTML='<option value="">Select organization</option>';return;}
    try { const {data}=await api(`${CONFIG.urls.forgeServers}?organization=${encodeURIComponent(state.organization)}`); $('forge-server').innerHTML='<option value="">Select server</option>'+data.map(s=>option(s.id,s.name || s.id)).join(''); $('forge-server').disabled=false; }
    catch(e){message(e.message,true);}
}
async function loadForgeSites() {
    state.forgeServer=$('forge-server').value; $('forge-sites').innerHTML='Loading sites…'; if(!state.forgeServer)return;
    try { const {data}=await api(`${CONFIG.urls.forgeSites}?organization=${encodeURIComponent(state.organization)}&server=${encodeURIComponent(state.forgeServer)}`);
        $('forge-sites').innerHTML=data.length?`<div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">${data.map(s=>`<label class="bg-background hover:bg-muted/40 flex cursor-pointer items-center gap-3 rounded-md border px-3 py-2.5 text-foreground transition-colors"><input class="forge-site accent-primary size-4" type="checkbox" value="${esc(s.id)}"> <span class="min-w-0 truncate">${esc(s.name || s.domain || s.id)}</span></label>`).join('')}</div>`:'No sites found.';
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
const inputClass='border-input bg-background focus-visible:border-ring focus-visible:ring-ring/50 h-9 w-full rounded-md border px-3 text-sm shadow-xs outline-none focus-visible:ring-3 disabled:cursor-not-allowed disabled:opacity-60';
const fieldLabelClass='mb-2 block text-sm font-medium';
function matchSummary(label,matched) { return `<span class="${matched?'text-green-700 dark:text-green-400':'text-muted-foreground'}">${esc(label)}: ${matched?'matched':'not matched'}</span>`; }
function renderPreview() {
    $('plan-expiry').textContent=`Preview expires in ${state.plan.expires_in_minutes} minutes`;
    $('preview-sites').innerHTML=state.plan.sites.map((item,index)=>{
        const d=item.defaults, sc=suggestedSource(d.source_control_provider), checks=item.checks.map(c=>`<div class="flex items-start gap-2 text-sm ${c.status==='matched'?'text-green-700 dark:text-green-400':'text-red-700 dark:text-red-400'}"><span class="font-semibold" aria-hidden="true">${c.status==='matched'?'✓':'×'}</span><span>${esc(c.label)} <span class="text-foreground font-mono text-xs">${esc(c.value)}</span></span></div>`).join('');
        const db=d.database;
        const res={domains:true,environment:item.environment.available,database:db.available,deployment_script:item.deployment_script.available,cron_jobs:item.cron_jobs.length>0,workers:item.workers.length>0};
        return `<article class="site-card overflow-hidden rounded-lg border bg-background transition-opacity" data-index="${index}" data-site-type="${esc(d.type)}">
          <div class="flex flex-col gap-3 border-b px-4 py-4 sm:flex-row sm:items-center sm:justify-between"><div><h3 class="font-semibold">${esc(d.domain)}</h3><p class="text-muted-foreground mt-1 text-xs">Forge site ${esc(d.forge_site_id)}</p></div><label class="flex cursor-pointer items-center gap-2 text-sm font-medium"><input class="site-enabled accent-primary size-4" type="checkbox" checked> Include this site</label></div>
          <div class="bg-muted/20 grid gap-2 border-b px-4 py-3 md:grid-cols-2">${checks}</div>
          <div class="grid gap-5 p-4 md:grid-cols-2 lg:grid-cols-3">
            <div><label class="${fieldLabelClass}">Domain</label><input class="${inputClass}" data-field="domain" value="${esc(d.domain)}"></div>
            <div><label class="${fieldLabelClass}">Vito site type</label><select class="${inputClass}" data-field="type">${selectOptions(CONFIG.siteTypes,d.type)}</select></div>
            <div><label class="${fieldLabelClass}">Site user</label><input class="${inputClass}" data-field="user" value="${esc(d.user)}"><p class="text-muted-foreground mt-1.5 text-xs">Forge: ${esc(d.forge_user || 'not provided')}</p></div>
            <div data-type-group="php"><label class="${fieldLabelClass}">PHP version</label><input class="${inputClass}" data-field="php_version" value="${esc(d.php_version)}"></div>
            <div data-type-group="php"><label class="${fieldLabelClass}">Web directory</label><input class="${inputClass}" data-field="web_directory" value="${esc(d.web_directory)}"><p class="text-muted-foreground mt-1.5 truncate text-xs">Forge: ${esc(d.forge_web_directory || 'site root')}</p></div>
            <div><label class="${fieldLabelClass}">Aliases</label><input class="${inputClass}" data-field="aliases" value="${esc(d.aliases.join(', '))}" placeholder="Comma-separated domains"></div>
            <div data-type-group="source"><label class="${fieldLabelClass}">Source control</label><select class="${inputClass}" data-field="source_control_id"><option value="">None</option>${selectOptions(CONFIG.sourceControls,sc,x=>x.id,x=>`${x.provider} · ${x.profile}`)}</select></div>
            <div data-type-group="source"><label class="${fieldLabelClass}">Repository</label><input class="${inputClass}" data-field="repository" value="${esc(d.repository)}"></div>
            <div data-type-group="source"><label class="${fieldLabelClass}">Branch</label><input class="${inputClass}" data-field="branch" value="${esc(d.branch)}"></div>
            <div data-type-group="proxy"><label class="${fieldLabelClass}">App port</label><input class="${inputClass}" data-field="port" type="number" value="${esc(d.port)}"><p class="text-muted-foreground mt-1.5 text-xs">Only used for Node and proxy sites.</p></div>
            <div data-type-group="node"><label class="${fieldLabelClass}">Node version</label><input class="${inputClass}" data-field="node_version" value="${esc(d.node_version)}"></div>
            <div data-type-group="proxy"><label class="${fieldLabelClass}">Start command</label><input class="${inputClass}" data-field="start_command" value="${esc(d.start_command)}"></div>
          </div>
          <div class="bg-muted/20 border-y px-4 py-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><h4 class="text-sm font-semibold">Database</h4><p class="text-muted-foreground mt-1 text-xs">${esc(db.reason)} Contents are not copied.</p></div><label class="flex cursor-pointer items-center gap-2 text-sm font-medium"><input class="accent-primary size-4" data-field="database_enabled" type="checkbox" ${db.enabled?'checked':''} ${db.available?'':'disabled'}> Configure in Vito</label></div>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
              <div><label class="${fieldLabelClass}">Database name</label><input class="${inputClass}" data-field="database_name" value="${esc(db.name)}"></div>
              <div><label class="${fieldLabelClass}">Database user</label><input class="${inputClass}" data-field="database_username" value="${esc(db.username)}"></div>
              <div><label class="${fieldLabelClass}">Forge connection</label><input class="${inputClass}" value="${esc(db.connection)} · ${esc(db.host)}:${esc(db.port)}" disabled></div>
            </div>
            <div class="text-muted-foreground mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs">${matchSummary('Forge database',db.forge_database_match)}${matchSummary('Forge user',db.forge_user_match)}${matchSummary('Vito database',db.vito_database_match)}${matchSummary('Vito user',db.vito_user_match)}<span>${db.has_environment_password?'Password detected privately':'No password returned by Forge'}</span></div>
          </div>
          <div class="px-4 py-4"><div class="mb-3 text-xs font-semibold tracking-wide uppercase">Resources to import</div><div class="flex flex-wrap gap-2">
            ${resourceToggle('domains','Domains and aliases',res.domains)}${resourceToggle('environment',`.env · ${item.environment.keys.length} keys`,res.environment)}${resourceToggle('database','Database setup',res.database)}${resourceToggle('deployment_script',`Deploy script · ${item.deployment_script.lines} lines`,res.deployment_script)}${resourceToggle('cron_jobs',`Cron jobs · ${item.cron_jobs.length}`,res.cron_jobs)}${resourceToggle('workers',`Processes · ${item.workers.length}`,res.workers)}
            <label class="hover:bg-muted/40 flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-xs font-medium" data-type-group="composer"><input class="accent-primary size-3.5" data-field="composer" type="checkbox"> Run Composer</label>
          </div></div>
        </article>`;
    }).join('');
    document.querySelectorAll('.site-enabled').forEach(box=>box.addEventListener('change',()=>box.closest('.site-card').classList.toggle('opacity-60',!box.checked)));
    document.querySelectorAll('.site-card').forEach(card=>{
      const type=card.querySelector('[data-field="type"]');
      type.addEventListener('change',()=>{
        const webDirectory=card.querySelector('[data-field="web_directory"]'), previous=card.dataset.siteType;
        if(webDirectory && webDirectory.value===vitoWebDirectory(previous)) webDirectory.value=vitoWebDirectory(type.value);
        card.dataset.siteType=type.value; syncTypeFields(card);
      });
      syncTypeFields(card);
    });
}
function vitoWebDirectory(type) { return type==='laravel'?'public':''; }
function syncTypeFields(card) {
    const type=card.querySelector('[data-field="type"]').value;
    const groups={php:['laravel','php','php-blank'],source:['laravel','php','node','blank'],proxy:['node','blank'],node:['node'],composer:['laravel','php']};
    card.querySelectorAll('[data-type-group]').forEach(field=>field.classList.toggle('hidden',!groups[field.dataset.typeGroup].includes(type)));
}
function resourceToggle(key,label,available) { return `<label class="${available?'hover:bg-muted/40 cursor-pointer':'cursor-not-allowed opacity-50'} flex items-center gap-2 rounded-md border px-3 py-2 text-xs font-medium" title="${available?'':'Not returned by Forge'}"><input class="accent-primary size-3.5" data-resource="${key}" type="checkbox" ${available?'checked':'disabled'}> ${esc(label)}</label>`; }
function collectSites() {
    return [...document.querySelectorAll('.site-card')].map((card,index)=>{ const d=state.plan.sites[index].defaults, value=(name)=>card.querySelector(`[data-field="${name}"]`); const resources={}; card.querySelectorAll('[data-resource]').forEach(i=>resources[i.dataset.resource]=i.checked);
      return {forge_site_id:String(d.forge_site_id),enabled:card.querySelector('.site-enabled').checked,domain:value('domain').value.trim(),aliases:value('aliases').value.split(',').map(x=>x.trim()).filter(Boolean),type:value('type').value,user:value('user').value.trim(),php_version:value('php_version').value.trim()||null,source_control_id:value('source_control_id').value?Number(value('source_control_id').value):null,repository:value('repository').value.trim()||null,branch:value('branch').value.trim()||null,web_directory:value('web_directory').value.trim(),port:Number(value('port').value||3000),node_version:value('node_version').value.trim()||'22',package_manager:'node',start_command:value('start_command').value.trim()||null,composer:value('composer').checked,database:{enabled:value('database_enabled').checked,name:value('database_name').value.trim()||null,username:value('database_username').value.trim()||null},resources};
    });
}
async function startImport() {
    const button=$('import-button'); button.disabled=true; button.textContent='Queueing…';
    try { const run=await api(CONFIG.urls.runs,{method:'POST',body:JSON.stringify({plan_id:state.plan.plan_id,organization:state.organization,forge_server_id:state.forgeServer,target_server_id:Number(state.targetServer),sites:collectSites()})}); state.runId=run.id; $('preview-card').classList.add('hidden'); $('run-card').classList.remove('hidden'); renderRun(run); pollRun(); }
    catch(e){message(e.message,true);button.disabled=false;button.textContent='Start selected imports';}
}
function renderRun(run) { $('run-status').textContent=run.status; $('run-step').textContent=run.current_step || ''; $('run-progress').style.width=`${run.progress}%`; const sites=run.result?.sites || {};
    $('run-results').innerHTML=Object.values(sites).map(s=>`<div class="py-4 first:pt-0 last:pb-0"><div class="flex items-center justify-between gap-4"><strong class="text-sm">${esc(s.domain || 'Site')}</strong><span class="bg-muted text-muted-foreground rounded-md px-2.5 py-1 text-xs font-medium">${esc(s.state)}</span></div>${s.vito_site_id?`<p class="text-muted-foreground mt-1 text-xs">Vito site ID: ${esc(s.vito_site_id)}</p>`:''}${s.error?`<p class="mt-2 text-sm text-red-700 dark:text-red-400">${esc(s.error)}</p>`:''}${(s.warnings||[]).map(w=>`<p class="mt-2 text-sm text-amber-700 dark:text-amber-400"><span class="font-medium">Warning:</span> ${esc(w)}</p>`).join('')}</div>`).join('');
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
