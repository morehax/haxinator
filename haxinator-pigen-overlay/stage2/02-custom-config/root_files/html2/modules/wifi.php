<?php
declare(strict_types=1);

/**
 * Wi-Fi Manager Module
 * wifi.php – NM 1.46 web UI  (2025-05 rev 2)
 * ------------------------------------------
 *  • Scan / Connect / Disconnect / Forget
 *  • Inline auto-connect priority (–100…100)
 *  • Per-adapter Wi-Fi hotspot
 *  • "Share this connection" for wired / USB
 *  SECURITY: CSRF token + regex + escapeshellarg
 */

// Module metadata
$module = [
    'id' => 'wifi',
    'title' => 'Wi-Fi',
    'icon' => 'wifi',
    'description' => 'Manage wireless connections and hotspots',
    'category' => 'network'
];

// If this is being included for metadata discovery, return early
if (!defined('EMBEDDED_MODULE') && !defined('MODULE_POST_HANDLER')) {
    return;
}

if (!session_id()) session_start();
header('X-Content-Type-Options: nosniff');

/* -------- helpers ------------------------------------------------- */
if (!function_exists('csrf')) {
    function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
}
if (!function_exists('json_out')) {
    function json_out(array $p,int $c=200):never{http_response_code($c);header('Content-Type: application/json');echo json_encode($p,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
}
if (!function_exists('bad')) {
    function bad(string $m,int $c=400):never{json_out(['error'=>$m],$c);}
}
if (!function_exists('pretty')) {
    function pretty(string $t):string{
      return match(true){
        str_contains($t,'No network with SSID')        =>'Network not found',
        str_contains($t,'Secrets were required')       =>'Wrong / missing password',
        str_contains($t,'Connection activation failed')=>'Could not obtain IP',
        default                                         =>trim($t),
      };
    }
}
$re_iface='/^[\w\-.]{1,15}$/';  $re_ssid='/^[\p{L}\d _\-.@]{1,32}$/u';

/* -------- AJAX ---------------------------------------------------- */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])){
  if(($_POST['csrf']??'')!==csrf()) bad('Invalid CSRF',403);
  $act=$_POST['action']; $iface=$_POST['iface']??'';
  if($iface && !preg_match($re_iface,$iface)) bad('Bad interface');

  /* ---------- scan ------------------------------------------------ */
  if($act==='scan'){
    if(!$iface){
      $iface=trim(shell_exec("nmcli -t -f DEVICE,TYPE d | grep ':wifi' | cut -d: -f1 | head -n1"));
      if($iface==='') bad('No Wi-Fi devices');
    }
    
    // Check if we should force a rescan (default: yes for backward compatibility)
    $rescan = ($_POST['rescan'] ?? 'yes') === 'yes' ? 'yes' : 'no';
    
    // Get local WiFi interface MAC addresses for hotspot detection
    $localMacs = [];
    $macOutput = shell_exec("nmcli -t -f DEVICE,TYPE device status | grep ':wifi'") ?? '';
    foreach(explode("\n", trim($macOutput)) as $line) {
      if(!$line) continue;
      [$dev, $type] = explode(':', $line);
      $mac = trim(shell_exec("nmcli -t -f GENERAL.HWADDR device show $dev 2>/dev/null") ?? '');
      if($mac && preg_match('/^GENERAL\.HWADDR:(.+)$/', $mac, $matches)) {
        $localMacs[] = strtoupper(str_replace('-', ':', $matches[1]));
      }
    }
    
    $fields='IN-USE,SSID,BSSID,CHAN,FREQ,RATE,SIGNAL,SECURITY,DEVICE,MODE';
    $raw=shell_exec(sprintf('nmcli -t --escape yes -f %s dev wifi list ifname %s --rescan %s 2>&1',escapeshellarg($fields),escapeshellarg($iface),$rescan))??'';
    $nets=[];foreach(explode("\n",trim($raw)) as $l){if(!$l)continue;
      $c=preg_split('/(?<!\\\\):/',$l);$c=array_pad($c,10,'');$c=array_map(fn($v)=>str_replace(['\\:', '\\\\'],[':','\\'],$v),$c);
      [$in,$ssid,$bssid,$ch,$fq,$rate,$sig,$sec,$dev,$mode]=$c;$fqN=(int)filter_var($fq,FILTER_SANITIZE_NUMBER_INT);
      
      // Check if this is our hotspot
      $isHotspot = $in === '*' && in_array(strtoupper($bssid), $localMacs, true);
      
      $nets[]=['in_use'=>$in==='*','ssid'=>$ssid,'bssid'=>$bssid,'chan'=>(int)$ch,'band'=>$fqN>=5950?'6 GHz':($fqN>4900?'5 GHz':'2.4 GHz'),'rate'=>$rate,'signal'=>(int)$sig,'security'=>$sec?:'OPEN','device'=>$dev,'mode'=>$mode,'is_hotspot'=>$isHotspot];
    }
    /* saved connections (wifi + priorities) */
    $saved=[];$out=shell_exec("nmcli -t -f NAME,UUID,TYPE,AUTOCONNECT,AUTOCONNECT-PRIORITY connection show")?:'';
    foreach(explode("\n",trim($out)) as $l){[$n,$u,$t,$ac,$pri]=$l?explode(':',$l):['','','','no','0'];if($t==='802-11-wireless')$saved[$n]=['uuid'=>$u,'ac'=>$ac,'pri'=>(int)$pri];}
    /* wired / usb profiles for sharing */
    $wired=[];$out=shell_exec("nmcli -t -f NAME,UUID,TYPE,DEVICE connection show")?:'';  // ipv4.method per uuid next
    foreach(explode("\n",trim($out)) as $l){
      [$n,$u,$t,$dev]=$l?explode(':',$l):['','','','']; if(!preg_match('/802-3-ethernet|gsm|bluetooth/',$t)) continue;
      $m=trim(shell_exec("nmcli -g ipv4.method connection show $u")); $wired[]=['name'=>$n,'uuid'=>$u,'method'=>$m,'device'=>$dev];
    }
    json_out(['networks'=>$nets,'saved'=>$saved,'wired'=>$wired,'iface'=>$iface]);
  }

  /* ---------- connect / disconnect / forget ----------------------- */
  if(in_array($act,['connect','disconnect','forget'],true)){
    $ssid=$_POST['ssid']??''; if(!preg_match($re_ssid,$ssid)) bad('Bad SSID');
    $isHotspot = ($_POST['is_hotspot'] ?? '') === 'true';
    
    if($act==='connect'){
      $psk=$_POST['psk']??'';$hid=isset($_POST['hidden'])?'hidden yes ':'';
      $cmd="nmcli dev wifi connect ".escapeshellarg($ssid).' '.($psk!==''?'password '.escapeshellarg($psk).' ':'').$hid.($iface?'ifname '.escapeshellarg($iface).' ':'')."2>&1";
      $out=shell_exec($cmd)??'';json_out(['success'=>str_contains($out,'successfully'),'msg'=>pretty($out)]);
    }
    
    if($act==='disconnect'||$act==='forget'){
      $connectionName = $ssid; // Default to SSID
      
      // For hotspots, find the actual connection name
      if($isHotspot) {
        $conList = shell_exec("nmcli -t -f NAME,TYPE connection show") ?? '';
        foreach(explode("\n", trim($conList)) as $line) {
          if(!$line) continue;
          [$name, $type] = explode(':', $line);
          if($type === '802-11-wireless') {
            // Check if this connection has the matching SSID
            $conSsid = trim(shell_exec("nmcli -g 802-11-wireless.ssid connection show ".escapeshellarg($name)." 2>/dev/null") ?? '');
            if($conSsid === $ssid) {
              $connectionName = $name;
              break;
            }
          }
        }
      }
      
      if($act==='disconnect') {
        if($isHotspot) {
          // For hotspots, we "down" the connection
          $cmd="nmcli connection down id ".escapeshellarg($connectionName)." 2>&1";
        } else {
          // For regular WiFi, we can disconnect the interface
          $cmd="nmcli connection down id ".escapeshellarg($connectionName)." 2>&1";
        }
      } else {
        // forget - delete the connection profile
        $cmd="nmcli connection delete id ".escapeshellarg($connectionName)." 2>&1";
      }
      
      $out=shell_exec($cmd)??'';
      $success = str_contains($out, $act==='disconnect'?'successfully':'deleted') || 
                 str_contains($out, 'deactivated') || 
                 ($act==='disconnect' && $isHotspot && str_contains($out, 'Connection'));
      json_out(['success'=>$success,'msg'=>pretty($out)]);
    }
  }

  /* ---------- set_priority ---------------------------------------- */
  if($act==='set_priority'){
    $uuid=$_POST['uuid']??''; $val=(int)($_POST['val']??0);
    if(!preg_match('/^[0-9a-f\-]{36}$/i',$uuid)) bad('Bad UUID'); if($val>100||$val<-100) bad('Out of range');
    $out=shell_exec("nmcli connection modify $uuid connection.autoconnect-priority $val 2>&1")??'';
    json_out(['success'=>!$out,'msg'=>$out?:'Priority saved']);
  }

  /* ---------- hotspot --------------------------------------------- */
  if($act==='hotspot'){
    $mode=$_POST['mode']??'start'; if(!preg_match($re_iface,$iface)) bad('Bad iface');
    if($mode==='stop'){
      $out=shell_exec("nmcli connection down \"Hotspot $iface\" 2>&1")??'';
      json_out(['success'=>str_contains($out,'deactivated')||str_contains($out,'Unknown'),'msg'=>pretty($out?:'Hotspot stopped')]);
    }else{
      $ssid=$_POST['ssid']??'MyHotspot'; $pass=$_POST['psk']??'';
      $band=$_POST['band']??'2.4'; 
      $conName=$_POST['con_name']??''; 
      $channel=$_POST['channel']??'';
      $ipRange=$_POST['ip_range']??'';
      $persistent=($_POST['persistent']??'') === '1';
      
      // Convert band notation for nmcli
      $bandArg = '';
      if($band === '5') $bandArg = 'band a ';
      elseif($band === '2.4') $bandArg = 'band bg ';
      
      $pwArg=strlen($pass)>=8?"password ".escapeshellarg($pass).' ':'';
      $conArg=$conName ? "con-name ".escapeshellarg($conName).' ' : '';
      $chanArg=$channel && is_numeric($channel) && $channel > 0 && $channel <= 165 ? "channel ".escapeshellarg($channel).' ' : '';
      
      $cmd="nmcli device wifi hotspot ifname ".escapeshellarg($iface)." ssid ".escapeshellarg($ssid).' '.$pwArg.$bandArg.$conArg.$chanArg."2>&1";
      $out=shell_exec($cmd)??'';
      
      $success = !str_contains($out,'Error');
      
      // If successful and we have additional settings to apply
      if($success && ($ipRange || !$persistent)) {
        $actualConName = $conName ?: "Hotspot $iface";
        
        // Set custom IP range if specified
        if($ipRange && preg_match('/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/', $ipRange)) {
          $ipCmd = "nmcli connection modify ".escapeshellarg($actualConName)." ipv4.addresses ".escapeshellarg($ipRange)." 2>&1";
          shell_exec($ipCmd);
        }
        
        // Set persistence
        $autoConnect = $persistent ? 'yes' : 'no';
        $persistCmd = "nmcli connection modify ".escapeshellarg($actualConName)." connection.autoconnect $autoConnect 2>&1";
        shell_exec($persistCmd);
      }
      
      json_out(['success'=>$success,'msg'=>pretty($out)]);
    }
  }

  /* ---------- share toggle ---------------------------------------- */
  if($act==='share_toggle'){
    $uuid=$_POST['uuid']??'';$on=$_POST['on']==='1';
    if(!preg_match('/^[0-9a-f\-]{36}$/i',$uuid)) bad('Bad UUID');
    $method=$on?'shared':'auto';
    $out=shell_exec("nmcli connection modify $uuid ipv4.method $method ipv6.method ignore 2>&1")??'';
    json_out(['success'=>!$out,'msg'=>$out?:'Settings saved']);
  }

  bad('Unknown action');
}

/* -------- initial interface list --------------------------------- */
$raw=shell_exec("nmcli -t -f DEVICE,TYPE device status")?:'';$ifs=[];$wifiIfs=[];
foreach(explode("\n",trim($raw)) as $l){[$d,$t]=explode(':',$l);$ifs[]=$d;if(str_ends_with($t,'wifi'))$wifiIfs[]=$d;}
$iface0=$wifiIfs[0]??'';$csrf=csrf();

// Check if we're in embedded mode (when used as module)
$embedded = defined('EMBEDDED_MODULE');

if (!$embedded) {
?><!doctype html><html lang="en"><head>
<meta charset="utf-8"><title>Wi-Fi Manager</title><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?=htmlspecialchars($csrf)?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>body{padding:1.2rem}td,th{vertical-align:middle}.signal-badge{min-width:3rem}#hotspotModal .modal-dialog{max-width:600px}</style></head>
<body class="bg-light">
<?php } else { ?>
<!-- Embedded mode: include required CSS inline and set CSRF token -->
<style>
/* WiFi-specific styles only */
.cp-toast-container{position:fixed!important;top:0!important;right:0!important;z-index:9999!important;padding:1rem!important}
</style>
<script>
// Set CSRF token for embedded mode
if (typeof document !== 'undefined' && !document.querySelector('meta[name="csrf-token"]')) {
  const meta = document.createElement('meta');
  meta.name = 'csrf-token';
  meta.content = '<?=htmlspecialchars($csrf)?>';
  document.head.appendChild(meta);
}
</script>
<?php } ?>

<h3 class="mb-3">Wi-Fi Manager</h3>

<div class="row g-3 mb-4 align-items-end">
  <div class="col-12 col-sm-auto">
    <label class="form-label mb-1 d-block">Interface</label>
    <select id="iface" class="form-select form-select-sm" style="min-width: 120px;"><?php
      foreach($wifiIfs as $d)echo'<option'.($d===$iface0?' selected':'').'>'.htmlspecialchars($d).'</option>';?></select>
  </div>
  <div class="col-auto">
    <button id="scanBtn" class="btn btn-primary btn-sm d-flex align-items-center">
      <i class="bi bi-arrow-clockwise me-1"></i>Scan
    </button>
  </div>
  <div class="col-auto" id="busy" style="display:none">
    <div class="spinner-border spinner-border-sm text-secondary"></div>
  </div>
  <div class="col-auto">
    <button id="hotspotBtn" class="btn btn-outline-success btn-sm d-flex align-items-center">
      <i class="bi bi-wifi me-1"></i>Hotspot
    </button>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h5 class="mb-0">Wi-Fi Networks</h5>
    <small class="text-muted">Tap rows on mobile for details</small>
  </div>
  <div class="card-body p-0">
    <div class="cp-table-responsive">
    <table class="table table-sm table-hover align-middle mb-0" id="wifiTable">
     <thead class="table-light"><tr>
      <th style="width:1%"></th>
      <th>SSID</th>
      <th class="cp-col-sm">S&amp;L</th>
      <th class="cp-col-md">Sec</th>
      <th class="cp-col-lg">Band</th>
      <th class="cp-col-lg">Ch</th>
      <th class="cp-col-xl">Rate</th>
      <th class="cp-col-xl">BSSID</th>
      <th class="cp-col-xl">Dev</th>
      <th class="cp-col-md">Priority</th>
      <th style="width:18%">Action</th>
      <th class="d-table-cell d-sm-none" style="width:1%">
       <i class="bi bi-info-circle" title="Tap row for details"></i>
      </th>
     </tr></thead><tbody></tbody>
    </table>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h5 class="mb-0">Wired / USB Sharing</h5>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-sm align-middle mb-0" id="wiredTable">
     <thead class="table-light"><tr><th>Connection</th><th>Device</th><th>Shared?</th></tr></thead><tbody></tbody>
    </table>
    </div>
  </div>
</div>

<div class="cp-toast-container" id="toastArea"></div>

<!-- hotspot modal -->
<div class="modal fade cp-modal-responsive" id="hotspotModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered">
<form class="modal-content" id="hotspotForm">
 <div class="modal-header"><h5 class="modal-title">Start hotspot (<span id="hsIf"></span>)</h5>
  <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
 <div class="modal-body">
  <div class="cp-form-row-responsive">
   <div class="cp-form-group-responsive">
    <label class="form-label">SSID</label>
    <input name="ssid" class="form-control" value="MyHotspot" maxlength="32" required>
   </div>
   <div class="cp-form-group-responsive">
    <label class="form-label">Connection Name</label>
    <input name="con_name" class="form-control" placeholder="Auto-generated" maxlength="32">
   </div>
  </div>
  <div class="cp-form-group-responsive">
   <label class="form-label">Password (≥8 chars for WPA2; blank = open)</label>
   <input name="psk" class="form-control" maxlength="63">
  </div>
  <div class="cp-form-row-responsive">
   <div class="cp-form-group-responsive">
    <label class="form-label">Band</label>
    <select name="band" class="form-select">
     <option value="2.4">2.4 GHz</option>
     <option value="5">5 GHz</option>
    </select>
   </div>
   <div class="cp-form-group-responsive">
    <label class="form-label">Channel</label>
    <input name="channel" type="number" class="form-control" placeholder="Auto" min="1" max="165">
    <div class="form-text">1-14 for 2.4GHz, 36-165 for 5GHz</div>
   </div>
  </div>
  <div class="cp-form-group-responsive">
   <label class="form-label">IP Range (CIDR)</label>
   <input name="ip_range" class="form-control" placeholder="10.42.0.1/24 (default)">
   <div class="form-text">e.g. 192.168.4.1/24 or leave blank for default</div>
  </div>
  <div class="form-check">
   <input name="persistent" type="checkbox" class="form-check-input" id="persistentCheck" value="1">
   <label class="form-check-label" for="persistentCheck">
    Remember this hotspot (auto-connect on boot)
   </label>
  </div>
 </div>
 <div class="modal-footer">
   <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
   <button type="submit" class="btn btn-primary">Start Hotspot</button>
 </div>
</form></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const qs=s=>document.querySelector(s),toastArea=qs('#toastArea'),busy=qs('#busy');
const csrf=document.querySelector('meta[name="csrf-token"]').content;
const modalHS=new bootstrap.Modal(qs('#hotspotModal')); let hsIface='';
function toast(m,ok=true){const d=document.createElement('div');
  d.className='toast align-items-center text-bg-'+(ok?'success':'danger');
  d.innerHTML='<div class="d-flex"><div class="toast-body">'+m+'</div>'
   +'<button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
  toastArea.append(d); new bootstrap.Toast(d,{delay:4500}).show();}
function showBusy(x){busy.style.display=x?'block':'none';}
async function api(p){showBusy(true);const fd=new URLSearchParams(p);fd.append('iface',qs('#iface').value);fd.append('csrf',csrf);
  const r=await fetch(location.href,{method:'POST',body:fd});showBusy(false);return r.json();}

function esc(s){return s.replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}

/* ----- render Wi-Fi table --------------------------------------- */
function renderWifi(rows,saved){
  const tb=qs('#wifiTable tbody');tb.innerHTML='';
  rows.forEach((n,idx)=>{
    const sIcon=`<span class="badge bg-${n.signal>75?'success':n.signal>50?'warning':'secondary'} cp-signal-badge">${n.signal}%</span>`;
    const savedProf=saved[n.ssid]||null;
    const priCell=savedProf?`<input type="number" class="pri form-control form-control-sm d-inline-block" style="width:5rem" min="-100" max="100" value="${savedProf.pri}" data-uuid="${savedProf.uuid}">`:'';
    
    // Different button text for hotspots
    const disconnectText = n.is_hotspot ? 'Stop Hotspot' : 'Disconnect';
    const connectText = n.is_hotspot ? 'Start Hotspot' : 'Connect';
    const buttonClass = n.in_use ? 'secondary' : 'primary';
    const buttonAction = n.in_use ? 'disconnect' : 'connect';
    const buttonLabel = n.in_use ? disconnectText : connectText;
    
    // Mobile-friendly action buttons
    const mobileActions = `
      <div class="cp-btn-group-mobile">
        ${btn(buttonAction,buttonLabel,buttonClass,n.ssid,n.is_hotspot,'w-100')}
        ${(savedProf)?btn('forget','Forget','danger',n.ssid,n.is_hotspot,'w-100'):''}
      </div>
      <div class="cp-btn-group-desktop">
        ${btn(buttonAction,buttonLabel,buttonClass,n.ssid,n.is_hotspot)}
        ${(savedProf)?btn('forget','Forget','danger',n.ssid,n.is_hotspot):''}
      </div>
    `;

    tb.insertAdjacentHTML('beforeend',`<tr class="cp-table-row" data-row="${idx}">
     <td>${n.in_use?'✅':''}</td>
     <td class="fw-semibold">${esc(n.ssid)}</td>
     <td class="cp-col-sm">${sIcon}</td>
     <td class="cp-col-md"><span class="badge bg-light text-dark">${n.security}</span></td>
     <td class="cp-col-lg">${n.band}</td>
     <td class="cp-col-lg">${n.chan}</td>
     <td class="cp-col-xl">${n.rate}</td>
     <td class="cp-col-xl"><small class="text-muted">${n.bssid}</small></td>
     <td class="cp-col-xl"><small class="text-muted">${n.device}</small></td>
     <td class="cp-col-md">${priCell}</td>
     <td>${mobileActions}</td>
     <td class="d-table-cell d-sm-none">
       <button class="cp-expand-btn" data-row="${idx}">
         <i class="bi bi-chevron-down"></i>
       </button>
     </td>
    </tr>`);

    // Add mobile details row (hidden by default)
    tb.insertAdjacentHTML('beforeend',`<tr class="cp-mobile-details d-sm-none" id="details-${idx}" style="display:none">
     <td colspan="4" class="p-3">
       <div class="cp-details-grid">
         <div class="cp-detail-item"><div class="cp-detail-label">Signal:</div><div>${sIcon}</div></div>
         <div class="cp-detail-item"><div class="cp-detail-label">Security:</div><div><span class="badge bg-light text-dark">${n.security}</span></div></div>
         <div class="cp-detail-item"><div class="cp-detail-label">Band:</div><div>${n.band}</div></div>
         <div class="cp-detail-item"><div class="cp-detail-label">Channel:</div><div>${n.chan}</div></div>
         <div class="cp-detail-item"><div class="cp-detail-label">Rate:</div><div>${n.rate}</div></div>
         <div class="cp-detail-item"><div class="cp-detail-label">Device:</div><div>${n.device}</div></div>
         <div class="cp-detail-item cp-detail-full"><div class="cp-detail-label">BSSID:</div><div><code class="cp-code-text">${n.bssid}</code></div></div>
         ${savedProf ? `<div class="cp-detail-item cp-detail-full"><div class="cp-detail-label">Priority:</div><div>${priCell}</div></div>` : ''}
       </div>
     </td>
    </tr>`);
  });
}

/* ----- render wired / USB table --------------------------------- */
function renderWired(arr){
  const tb=qs('#wiredTable tbody');tb.innerHTML='';
  arr.forEach(w=>{
    tb.insertAdjacentHTML('beforeend',`<tr><td>${esc(w.name)}</td><td>${w.device}</td>
      <td><input type="checkbox" class="shareToggle form-check-input" data-uuid="${w.uuid}" ${w.method==='shared'?'checked':''}></td></tr>`);
  });
}

const btn=(a,l,c,s,isHotspot=false,extraClass='')=>
 `<button class="btn btn-${c} btn-sm me-1 wifiAct ${extraClass}" data-a="${a}" data-s="${esc(s)}" data-hotspot="${isHotspot}">${l}</button>`;

/* ----- event delegation ----------------------------------------- */
document.addEventListener('click',e=>{
  const b=e.target.closest('.wifiAct'); if(b){ e.preventDefault();action(b.dataset.a,b.dataset.s,b.dataset.hotspot==='true');return;}
  const p=e.target.closest('.pri'); if(p){p.dataset.dirty='1';return;}
  
  // Handle mobile details expansion
  const expandBtn=e.target.closest('.cp-expand-btn');
  if(expandBtn){
    const rowIdx=expandBtn.dataset.row;
    const detailsRow=qs(`#details-${rowIdx}`);
    const icon=expandBtn.querySelector('i');
    
    if(detailsRow.style.display==='none'){
      detailsRow.style.display='table-row';
      icon.className='bi bi-chevron-up';
    }else{
      detailsRow.style.display='none';
      icon.className='bi bi-chevron-down';
    }
    return;
  }
});
/* save priority on blur */
document.addEventListener('blur',e=>{
  const p=e.target.closest('.pri'); if(p && p.dataset.dirty){savePri(p);delete p.dataset.dirty;}},true);
/* share toggle */
document.addEventListener('change',e=>{
  const cb=e.target.closest('.shareToggle'); if(cb){toggleShare(cb);} });

async function action(a,ssid,isHotspot=false){
  if(a==='connect'){const p=prompt(`Password for "${ssid}" (blank if open):`,''); if(p===null)return;
    const r=await api({action:'connect',ssid,psk:p}); toast(r.msg,r.success); if(r.success) scan(); }
  else if(a==='disconnect'||a==='forget'){
    const actionText = isHotspot && a==='disconnect' ? 'stop hotspot' : a;
    if(!confirm(`${actionText} "${ssid}"?`)) return;
    const r=await api({action:a,ssid,is_hotspot:isHotspot}); toast(r.msg,r.success); scan();
  }
}
async function savePri(el){
  let v=parseInt(el.value,10); if(isNaN(v))v=0; v=Math.max(-100,Math.min(100,v)); el.value=v;
  const r=await api({action:'set_priority',uuid:el.dataset.uuid,val:v});
  toast(r.msg,r.success);
}
async function toggleShare(cb){
  const r=await api({action:'share_toggle',uuid:cb.dataset.uuid,on:cb.checked?'1':'0'});
  toast(r.msg,r.success);
}
/* ----- hotspot controls ----------------------------------------- */
qs('#hotspotBtn').addEventListener('click',()=>{
  hsIface=qs('#iface').value; qs('#hsIf').textContent=hsIface; modalHS.show();});
qs('#hotspotForm').addEventListener('submit',async e=>{
  e.preventDefault();
  const fd=new FormData(e.target); fd.append('action','hotspot'); fd.append('mode','start'); fd.append('iface',hsIface);
  const r=await api(Object.fromEntries(fd)); modalHS.hide(); toast(r.msg,r.success);
});
/* stop hotspot on double-click of Hotspot button (simple UX) */
qs('#hotspotBtn').addEventListener('dblclick',async ()=>{
  const r=await api({action:'hotspot',mode:'stop',iface:qs('#iface').value}); toast(r.msg,r.success);});

/* ----- hotspot validation --------------------------------------- */
const bandSelect = qs('select[name="band"]');
const channelInput = qs('input[name="channel"]');

bandSelect.addEventListener('change', () => {
  const band = bandSelect.value;
  if (band === '2.4') {
    channelInput.placeholder = 'Auto (1-14)';
    channelInput.max = '14';
  } else {
    channelInput.placeholder = 'Auto (36-165)';
    channelInput.max = '165';
  }
  // Clear invalid channel when band changes
  const ch = parseInt(channelInput.value);
  if (ch && ((band === '2.4' && ch > 14) || (band === '5' && ch < 36))) {
    channelInput.value = '';
  }
});

// Validate channel input
channelInput.addEventListener('input', () => {
  const ch = parseInt(channelInput.value);
  const band = bandSelect.value;
  if (ch) {
    if (band === '2.4' && (ch < 1 || ch > 14)) {
      channelInput.setCustomValidity('2.4 GHz channels: 1-14');
    } else if (band === '5' && (ch < 36 || ch > 165)) {
      channelInput.setCustomValidity('5 GHz channels: 36-165');
    } else {
      channelInput.setCustomValidity('');
    }
  } else {
    channelInput.setCustomValidity('');
  }
});

/* ----- scan & initial load -------------------------------------- */
async function loadData(){
  const d=await api({action:'scan', rescan:'no'}); 
  if(d.error) toast(d.error,false); 
  else{ renderWifi(d.networks,d.saved); renderWired(d.wired); }
}
async function scan(){
  const d=await api({action:'scan', rescan:'yes'}); 
  if(d.error) toast(d.error,false); 
  else{ renderWifi(d.networks,d.saved); renderWired(d.wired); }
}
qs('#scanBtn').addEventListener('click',scan); loadData();
</script><?php if (!$embedded) { ?></body></html><?php } ?>
