<?php
/**
 * Network Connections Module – integrated nmcli UI (2025)
 * Provides listing and basic up / down / delete actions for NetworkManager
 * Using same design + AJAX/JSON pattern as other modules.
 */

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
//  Module metadata (used by parent framework during discovery)
// ─────────────────────────────────────────────────────────────────────────────
$module = [
    'id'          => 'network',
    'title'       => 'Network',
    'icon'        => 'ethernet',
    'description' => 'Manage NetworkManager connections (up / down / delete)',
    'category'    => 'network'
];

// Early return during discovery
if (!defined('EMBEDDED_MODULE') && !defined('MODULE_POST_HANDLER')) {
    return;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Helpers & environment
// ─────────────────────────────────────────────────────────────────────────────
if (!function_exists('csrf')) {
    function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
}
if (!function_exists('json_out')) {
    function json_out(array $p,int $c=200):never{
        http_response_code($c);
        header('Content-Type: application/json');
        echo json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
if (!function_exists('bad')) {
    function bad(string $m,int $c=400):never{ json_out(['error'=>$m],$c); }
}

// Execute nmcli safely and capture output
function nm_exec(string $template, array $args = [], ?array &$out = null, ?int &$rc = null): bool
{
    $quoted = array_map('escapeshellarg', $args);
    $cmd    = vsprintf($template, $quoted) . ' 2>&1';
    exec($cmd, $out, $rc);
    return $rc === 0;
}

$re_uuid = '/^[0-9a-f\-]{36}$/i';

// ─────────────────────────────────────────────────────────────────────────────
//  AJAX / POST handler (JSON responses)
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (($_POST['csrf'] ?? '') !== csrf()) bad('Invalid CSRF',403);

    $act = $_POST['action'];

    // ───────────── list connections ─────────────
    if ($act === 'list_conns') {
        nm_exec('nmcli -t -f NAME,UUID,TYPE,DEVICE,AUTOCONNECT connection show', [], $rows, $rc);
        if ($rc !== 0) bad('nmcli error');
        $list = [];
        foreach ($rows as $ln) {
            if ($ln==='') continue;
            [$name,$uuid,$type,$dev,$auto] = explode(':', $ln)+array_fill(0,5,'');
            $list[] = [
                'name'  => $name,
                'uuid'  => $uuid,
                'type'  => $type,
                'device'=> $dev,
                'auto'  => $auto,
            ];
        }
        json_out(['conns'=>$list]);
    }

    // ───────────── up / down / delete ─────────────
    if (in_array($act, ['up_conn','down_conn','del_conn'], true)) {
        $uuid = $_POST['uuid'] ?? '';
        if (!preg_match($re_uuid, $uuid)) bad('Bad UUID');
        $cmdMap = [
            'up_conn'   => 'nmcli connection up uuid %s',
            'down_conn' => 'nmcli connection down uuid %s',
            'del_conn'  => 'nmcli connection delete uuid %s'
        ];
        if (nm_exec($cmdMap[$act], [$uuid], $o, $r)) {
            json_out(['success'=>true]);
        } else {
            json_out(['error'=>implode('\n', $o)]);
        }
    }

    // ─────────── get details ───────────
    if ($act==='get_conn_details') {
        $uuid=$_POST['uuid']??'';
        if(!preg_match($re_uuid,$uuid)) bad('Bad UUID');
        
        // Curated list of commonly editable fields (avoids read-only and problematic fields)
        $editableFields = [
            'connection.id',
            'connection.autoconnect',
            'connection.autoconnect-priority',
            '802-11-wireless.ssid',
            '802-11-wireless.mode',
            '802-11-wireless-security.key-mgmt',
            '802-11-wireless-security.psk',
            '802-3-ethernet.mtu',
            'ipv4.method',
            'ipv4.addresses',
            'ipv4.gateway',
            'ipv4.dns',
            'ipv6.method',
            'ipv6.addresses',
            'ipv6.gateway',
            'ipv6.dns'
        ];
        $fieldsStr = implode(',', $editableFields);
        
        nm_exec('nmcli -t -s --fields %s connection show uuid %s', [$fieldsStr, $uuid], $out, $rc);
        if($rc!==0) bad('nmcli error');
        $rows=[];
        foreach($out as $ln){
            if(!str_contains($ln,':')) continue;
            [$k,$v]=explode(':',$ln,2); $rows[]=['key'=>trim($k), 'val'=>trim($v)];
        }
        json_out(['details'=>$rows]);
    }

    // ─────────── save details ───────────
    if ($act==='save_conn_details') {
        $uuid=$_POST['uuid']??''; $rows=json_decode($_POST['rows']??'[]',true);
        if(!preg_match($re_uuid,$uuid)) bad('Bad UUID');
        if(!is_array($rows)) bad('Bad rows');
        
        foreach($rows as $r){
            if(!isset($r['key'],$r['val'])) continue;
            nm_exec('nmcli connection modify uuid %s %s %s',[$uuid,$r['key'],$r['val']],$o,$rc);
            if($rc!==0) bad('Error: '.implode('\n',$o));
        }
        json_out(['success'=>true]);
    }

    bad('Unknown action'); // fallback
}

// ─────────────────────────────────────────────────────────────────────────────
//  EMBEDDED PAGE (Bootstrap UI)
// ─────────────────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
$csrf = csrf();
?>
<h3 class="mb-3">Network Connections</h3>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h5 class="mb-0">Connections</h5>
    <button class="btn btn-sm btn-primary" id="refreshBtn"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
  </div>
  <div class="card-body p-0">
    <div class="cp-table-responsive">
      <table class="table table-sm table-hover align-middle mb-0" id="connTable">
        <thead class="table-light"><tr>
          <th>Name</th>
          <th class="cp-col-sm">UUID</th>
          <th class="cp-col-md">Type</th>
          <th class="cp-col-lg">Device</th>
          <th class="cp-col-md">Auto</th>
          <th style="width:18%">Actions</th>
          <th class="d-table-cell d-sm-none" style="width:1%">
            <i class="bi bi-info-circle" title="Tap row for details"></i>
          </th>
        </tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<div class="cp-toast-container" id="toastArea"></div>

<script>
const csrf = '<?= htmlspecialchars($csrf) ?>';
function toast(msg, ok=true){
  const t=document.createElement('div');
  t.className='toast align-items-center text-bg-'+(ok?'success':'danger');
  t.innerHTML='<div class="d-flex"><div class="toast-body">'+msg+'</div>'+
              '<button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
  document.getElementById('toastArea').append(t);
  new bootstrap.Toast(t,{delay:4000}).show();
}
async function api(action, body={}){
  const fd=new FormData();
  fd.append('action',action); fd.append('csrf',csrf);
  for(const k in body) fd.append(k,body[k]);
  const r=await fetch('?module=network',{method:'POST',body:fd});
  return r.json();
}
function render(conns){
  const tb=document.querySelector('#connTable tbody');
  tb.innerHTML='';
  conns.forEach((c,idx)=>{
    // Mobile-friendly action buttons
    const mobileActions = `
      <div class="cp-btn-group-mobile">
        <button class='btn btn-sm btn-outline-secondary w-100' title='Edit' onclick="openEdit('${c.uuid}')"><i class='bi bi-pencil-square me-1'></i>Edit</button>
        <button class='btn btn-sm btn-outline-success w-100' title='Up' onclick="act('up_conn','${c.uuid}')"><i class='bi bi-play me-1'></i>Up</button>
        <button class='btn btn-sm btn-outline-secondary w-100' title='Down' onclick="act('down_conn','${c.uuid}')"><i class='bi bi-stop me-1'></i>Down</button>
        <button class='btn btn-sm btn-outline-danger w-100' title='Delete' onclick="act('del_conn','${c.uuid}',true)"><i class='bi bi-trash me-1'></i>Delete</button>
      </div>
      <div class="cp-btn-group-desktop">
        <button class='btn btn-sm btn-outline-secondary me-1' title='Edit' onclick="openEdit('${c.uuid}')"><i class='bi bi-pencil-square'></i></button>
        <button class='btn btn-sm btn-outline-success me-1' title='Up' onclick="act('up_conn','${c.uuid}')"><i class='bi bi-play'></i></button>
        <button class='btn btn-sm btn-outline-secondary me-1' title='Down' onclick="act('down_conn','${c.uuid}')"><i class='bi bi-stop'></i></button>
        <button class='btn btn-sm btn-outline-danger' title='Delete' onclick="act('del_conn','${c.uuid}',true)"><i class='bi bi-trash'></i></button>
      </div>
    `;

    const tr=document.createElement('tr');
    tr.className='cp-table-row';
    tr.dataset.row=idx;
    tr.innerHTML=`<td class="fw-semibold">${c.name||''}</td><td class="cp-col-sm"><code>${c.uuid}</code></td><td class="cp-col-md">${c.type}</td>`+
                 `<td class="cp-col-lg">${c.device}</td><td class="cp-col-md">${c.auto}</td>`+
                 `<td>${mobileActions}</td>`+
                 `<td class="d-table-cell d-sm-none">
                   <button class="cp-expand-btn" data-row="${idx}">
                     <i class="bi bi-chevron-down"></i>
                   </button>
                 </td>`;
    tb.append(tr);

    // Add mobile details row (hidden by default)
    const detailsRow=document.createElement('tr');
    detailsRow.className='cp-mobile-details d-sm-none';
    detailsRow.id=`details-${idx}`;
    detailsRow.style.display='none';
    detailsRow.innerHTML=`<td colspan="7" class="p-3">
       <div class="cp-details-grid">
         <div class="cp-detail-item"><div class="cp-detail-label">UUID:</div><div><code class="cp-code-text">${c.uuid}</code></div></div>
         <div class="cp-detail-item"><div class="cp-detail-label">Type:</div><div>${c.type}</div></div>
         <div class="cp-detail-item"><div class="cp-detail-label">Device:</div><div>${c.device}</div></div>
         <div class="cp-detail-item"><div class="cp-detail-label">Auto:</div><div>${c.auto}</div></div>
       </div>
     </td>`;
    tb.append(detailsRow);
  });
}
async function load(){
  const d=await api('list_conns');
  if(d.error) toast(d.error,false); else render(d.conns);
}
async function act(a,u,confirmDel=false){
  if(confirmDel && !confirm('Delete connection?')) return;
  const d=await api(a,{uuid:u});
  if(d.error) toast(d.error,false); else { toast('OK'); load(); }
}

document.getElementById('refreshBtn').addEventListener('click',load);
load();

// Handle mobile details expansion
document.addEventListener('click',e=>{
  const expandBtn=e.target.closest('.cp-expand-btn');
  if(expandBtn){
    const rowIdx=expandBtn.dataset.row;
    const detailsRow=document.querySelector(`#details-${rowIdx}`);
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

// ─────────── Edit Modal ───────────
function openEdit(uuid){
  // Build modal skeleton if not present
  let modal=document.getElementById('editModal');
  if(!modal){
    document.body.insertAdjacentHTML('beforeend',`
    <div class="modal fade cp-modal-responsive" id="editModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Edit Connection</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><div id="editBody" class="text-center p-4"><div class="spinner-border"></div></div></div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="saveEditBtn">Save</button></div>
    </div></div></div>`);
    modal=document.getElementById('editModal');
  }
  const mInst=new bootstrap.Modal(modal); mInst.show();
  const bodyDiv=document.getElementById('editBody');
  bodyDiv.innerHTML='<div class="spinner-border"></div>';

  api('get_conn_details',{uuid}).then(d=>{
    if(d.error){ bodyDiv.innerHTML='<p class="text-danger">'+d.error+'</p>'; return; }
    const tbl=document.createElement('table'); tbl.className='table table-sm';
    d.details.forEach(row=>{
      const tr=document.createElement('tr');
      tr.innerHTML=`<td class='text-nowrap'>${row.key}</td><td><input type='text' class='form-control form-control-sm' value="${row.val.replace(/"/g,'&quot;')}"></td>`;
      tbl.append(tr);
    });
    bodyDiv.innerHTML=''; bodyDiv.append(tbl);

    document.getElementById('saveEditBtn').onclick=async()=>{
      const inputs=[...tbl.querySelectorAll('tr')].map(tr=>{
        return {key:tr.children[0].textContent.trim(), val:tr.children[1].firstChild.value};
      });
      const d2=await api('save_conn_details',{uuid, rows:JSON.stringify(inputs)});
      if(d2.error) toast(d2.error,false); else { toast('Saved'); mInst.hide(); load(); }
    };
  });
}
</script> 