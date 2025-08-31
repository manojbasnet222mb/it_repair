<?php
// /htdocs/it_repair/public/staff/request_new.php
declare(strict_types=1);

session_start();
require_once __DIR__.'/../../includes/functions.php';
require_once __DIR__.'/../../includes/guard.php';
require_once __DIR__.'/../../config/db.php';

require_role('staff','admin');

$errors = [];
$old = [
  'customer_id' => '',
  'device_type'=>'Laptop','brand'=>'','model'=>'','serial_no'=>'',
  'issue_description'=>'','service_type'=>'dropoff','preferred_contact'=>'phone',
  'accessories'=>'','warranty_status'=>'unknown','priority'=>'normal'
];

// Fetch all customers
$customers = db()->query("SELECT id, name, email FROM users WHERE role='customer' ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    $errors['csrf']='Invalid session token';
  }
  foreach ($old as $k=>$v){ $old[$k] = trim($_POST[$k] ?? $v); }

  if ($old['customer_id'] === '') $errors['customer_id']='Please select a customer.';
  if ($old['issue_description'] === '') $errors['issue_description']='Please describe the issue.';
  if ($old['device_type'] === '') $errors['device_type']='Select a device type.';

  if (!$errors) {
    $ticket = ticket_code();
    $stmt = db()->prepare("INSERT INTO repair_requests
      (ticket_code, customer_id, device_type, brand, model, serial_no,
       issue_description, service_type, preferred_contact, accessories,
       warranty_status, priority, status, created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");

    $stmt->execute([
      $ticket,
      $old['customer_id'],
      $old['device_type'],
      $old['brand'],
      $old['model'],
      $old['serial_no'],
      $old['issue_description'],
      $old['service_type'],
      $old['preferred_contact'],
      $old['accessories'],
      $old['warranty_status'],
      $old['priority'],
      'Received'
    ]);

    $req_id = (int)db()->lastInsertId();
    $hist = db()->prepare("INSERT INTO request_status_history (request_id,status,note,changed_by) VALUES (?,?,?,?)");
    $hist->execute([$req_id,'Received','Request created by staff',$_SESSION['user']['id']]);

    $n = db()->prepare("INSERT INTO notifications (user_id,title,body) VALUES (?,?,?)");
    $n->execute([$old['customer_id'],'Request Received',"Ticket $ticket has been created."]);

    redirect(base_url('staff/dashboard.php?new='.$ticket));
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Create Repair Request (Staff) — NexusFix</title>
  <link rel="stylesheet" href="../../assets/css/styles.css">
  <style>
    :root{
      --bg:#f9fafb; --fg:#111827; --card:#ffffff; --muted:#6b7280;
      --border:#e5e7eb; --field:#ffffff; --field-border:#cbd5e1;
    }
    @media (prefers-color-scheme: dark){
      :root{
        --bg:#111827; --fg:#f9fafb; --card:#1f2937; --muted:#9ca3af;
        --border:#374151; --field:#1f2937; --field-border:#374151;
      }
    }
    body { background:var(--bg); color:var(--fg); font-family:system-ui,sans-serif; }
    main { max-width:880px; margin:2rem auto; padding:1rem; }
    h2 { margin-bottom:1rem; }

    .card { background:var(--card); border-radius:12px; padding:1rem; border:1px solid var(--border); }
    form.mini-form label { display:block; font-weight:600; margin-bottom:4px; }
    form.mini-form input, form.mini-form select, form.mini-form textarea {
      width:100%; padding:.65rem .75rem; border:1px solid var(--field-border);
      border-radius:10px; background:var(--field); color:var(--fg);
    }

    .grid-3 { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
    .grid-2 { display:grid; grid-template-columns:repeat(2,1fr); gap:12px; }
    @media (max-width:900px){ .grid-3{grid-template-columns:1fr 1fr} }
    @media (max-width:760px){ .grid-3,.grid-2{grid-template-columns:1fr} }

    /* Chips */
    .chips { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px }
    .chip { border:1px solid var(--field-border); border-radius:999px; padding:6px 12px; cursor:pointer; user-select:none; }
    .chip:hover { background: rgba(0,0,0,.04); }
    @media(prefers-color-scheme: dark){ .chip:hover{ background: rgba(255,255,255,.06);} }

    .actions { display:flex; gap:10px; align-items:end; flex-wrap:wrap; margin-top:8px; }

/* Modal overlay */
#customerModal {
  display:none;
  position:fixed;top:0;left:0;width:100%;height:100%;
  background:rgba(0,0,0,.6);
  align-items:center;justify-content:center;
  z-index:1000;opacity:0;transition:opacity .3s ease;
}
#customerModal.show { opacity:1; }

/* Modal card */
#customerModal .modal-card {
  background:var(--card);
  padding:1.5rem;
  border-radius:12px;
  border:1px solid var(--border);
  max-width:500px;width:95%;
  box-shadow:0 8px 30px rgba(0,0,0,.25);
  transform:translateY(-40px);
  transition:transform .3s ease;
}
#customerModal.show .modal-card { transform:translateY(0); }

#customerModal h3 { margin-bottom:1rem; }
#customerModal label { font-weight:600; display:block; }
#customerModal input {
  width:100%; padding:.6rem .75rem; border:1px solid var(--field-border);
  border-radius:8px; background:var(--field); color:var(--fg);
}
#c_error { color:#dc2626; font-size:.9rem; margin:6px 0 0; }

  </style>
</head>
<body>
  <?php require __DIR__.'/../../includes/header.php'; ?>

  <main>
    <h2>Create Repair Request (Staff)</h2>

    <?php if($errors): ?>
      <div class="card" role="alert">
        <strong>Fix the following:</strong>
        <ul>
          <?php foreach($errors as $m): ?><li><?= e($m) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" class="mini-form" style="display:grid; gap:1rem;">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <!-- Customer selection -->
      <label>Customer
        <div style="display:flex; gap:8px; align-items:center;">
          <select name="customer_id" id="customer_id" required style="flex:1;">
            <option value="">-- Select customer --</option>
            <?php foreach($customers as $c): ?>
              <option value="<?= e((string)$c['id']) ?>" <?= $old['customer_id']==$c['id']?'selected':'' ?>>
                <?= e($c['name']) ?> (<?= e($c['email']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <button type="button" id="addCustomerBtn" class="btn outline">+ Add</button>
        </div>
      </label>

      <!-- Device basics -->
      <div class="grid-3">
        <label>Device type
          <select name="device_type" id="device_type" required>
            <?php foreach(['Laptop','Desktop','Phone','Tablet','Printer','Peripheral','Other'] as $opt): ?>
              <option value="<?= e($opt) ?>" <?= $old['device_type']===$opt?'selected':'' ?>><?= e($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Brand
          <input name="brand" value="<?= e($old['brand']) ?>" placeholder="e.g. Dell, HP, Apple">
        </label>
        <label>Model
          <input name="model" value="<?= e($old['model']) ?>" placeholder="e.g. iPhone 13, ThinkPad T14">
        </label>
      </div>

      <label>Serial/IMEI (optional)
        <input name="serial_no" value="<?= e($old['serial_no']) ?>" placeholder="e.g. SN/IMEI/Service Tag">
      </label>

      <!-- Issue -->
      <label>What’s wrong?
        <textarea name="issue_description" rows="5" required><?= e($old['issue_description']) ?></textarea>
        <div class="chips" id="issue-chips"></div>
      </label>

      <!-- Options -->
      <div class="grid-2">
        <label>Service option
          <select name="service_type">
            <option value="dropoff" <?= $old['service_type']==='dropoff'?'selected':'' ?>>Drop-off</option>
            <option value="pickup" <?= $old['service_type']==='pickup'?'selected':'' ?>>Pickup</option>
            <option value="onsite" <?= $old['service_type']==='onsite'?'selected':'' ?>>On-site</option>
          </select>
        </label>
        <label>Preferred contact
          <select name="preferred_contact">
            <option value="phone" <?= $old['preferred_contact']==='phone'?'selected':'' ?>>Phone</option>
            <option value="email" <?= $old['preferred_contact']==='email'?'selected':'' ?>>Email</option>
            <option value="both" <?= $old['preferred_contact']==='both'?'selected':'' ?>>Both</option>
          </select>
        </label>
      </div>

      <div class="grid-2">
        <label>Accessories
          <input name="accessories" value="<?= e($old['accessories']) ?>" placeholder="Charger, bag, etc.">
        </label>
        <label>Warranty
          <select name="warranty_status">
            <option value="unknown" <?= $old['warranty_status']==='unknown'?'selected':'' ?>>Unknown</option>
            <option value="in_warranty" <?= $old['warranty_status']==='in_warranty'?'selected':'' ?>>In warranty</option>
            <option value="out_of_warranty" <?= $old['warranty_status']==='out_of_warranty'?'selected':'' ?>>Out of warranty</option>
          </select>
        </label>
      </div>

      <label>Priority
        <select name="priority">
          <option value="normal" <?= $old['priority']==='normal'?'selected':'' ?>>Normal</option>
          <option value="high" <?= $old['priority']==='high'?'selected':'' ?>>High</option>
        </select>
      </label>

      <div class="actions">
        <button class="btn primary" type="submit">Submit Request</button>
        <a class="btn subtle" href="<?= e(base_url('staff/dashboard.php')) ?>">Cancel</a>
      </div>
    </form>
  </main>

<!-- Customer Modal -->
<div id="customerModal" role="dialog" aria-modal="true">
  <div class="modal-card">
    <h3>New Customer</h3>
    <form id="newCustomerForm" style="display:grid;gap:1rem;">
      <div class="grid-2">
        <label>Name
          <input id="c_name" placeholder="e.g. John Doe">
        </label>
        <label>Email
          <input id="c_email" type="email" placeholder="e.g. john@example.com">
        </label>
      </div>
      <div class="grid-2">
        <label>Phone
          <input id="c_phone" placeholder="Optional">
        </label>
        <label>Password
          <input id="c_pass" type="password" placeholder="Temporary password">
        </label>
      </div>
      <p id="c_error"></p>
      <div class="actions">
        <button id="saveCustomer" class="btn primary" type="button">Save</button>
        <button id="cancelCustomer" class="btn subtle" type="button">Cancel</button>
      </div>
    </form>
  </div>
</div>


<script>
  // Issue chips (same as customer page)
  const issueOptions = {
    "Phone": [
      "Cracked screen","Back glass cracked","Battery drains fast","Charging port not working",
      "Liquid damage","Camera blurry / not working","Speaker or mic issue",
      "Face ID / Touch ID issue","No signal / SIM not detected","Wi-Fi/Bluetooth issue",
      "Touchscreen unresponsive","Buttons not working"
    ],
    "Laptop": [
      "No power / won’t turn on","Blue screen / frequent crashes","Overheating / loud fan",
      "Slow performance","Storage / hard disk failure","Keyboard/trackpad not working",
      "Broken hinge / chassis","No display / GPU artifacts","USB/ports not working",
      "Operating system / software issue","Virus / malware infection","Data recovery needed"
    ],
    "Tablet": [
      "Cracked screen","Battery issue","Charging problem","Touchscreen unresponsive",
      "Wi-Fi not working","Camera issue","Slow performance","App crashes"
    ],
    "Desktop": [
      "No power","Blue screen","No display","Slow performance",
      "Hard disk failure","Overheating","Fan noise","USB/ports not working"
    ],
    "Printer": [
      "Paper jam","Not printing","Ink/toner issue","Connectivity problem",
      "Error codes","Lines/streaks on prints","Slow printing"
    ],
    "Peripheral": [
      "Not detected by computer","Connection issues","Button failure","Driver/software issue"
    ],
    "Other": [
      "Unidentified issue","Custom hardware","General maintenance","Diagnostics needed"
    ]
  };

  function renderChips(device) {
    const chipBox = document.getElementById("issue-chips");
    chipBox.innerHTML = "";
    (issueOptions[device] || []).forEach(txt => {
      const span = document.createElement("span");
      span.className = "chip";
      span.textContent = txt;
      span.onclick = () => {
        const ta=document.querySelector('textarea[name="issue_description"]');
        if(!ta.value) ta.value=txt;
        else if(!ta.value.includes(txt)) ta.value = ta.value.trim()+"; "+txt;
        ta.focus();
      };
      chipBox.appendChild(span);
    });
  }

  document.addEventListener("DOMContentLoaded", () => {
    const deviceSel=document.getElementById("device_type");
    renderChips(deviceSel.value);
    deviceSel.addEventListener("change", () => renderChips(deviceSel.value));
  });

  // Modal logic
  const modal=document.getElementById('customerModal');
  document.getElementById('addCustomerBtn').onclick=()=>{ 
    modal.style.display='flex'; 
    setTimeout(()=>modal.classList.add('show'),10); 
  };
  document.getElementById('cancelCustomer').onclick=()=>{ 
    modal.classList.remove('show'); 
    setTimeout(()=>modal.style.display='none',300); 
  };

  // Save new customer via AJAX (bulletproof)
  document.getElementById('saveCustomer').onclick=async()=>{
    const name=document.getElementById('c_name').value.trim();
    const email=document.getElementById('c_email').value.trim();
    const phone=document.getElementById('c_phone').value.trim();
    const pass=document.getElementById('c_pass').value;
    const err=document.getElementById('c_error');

    if(!name||!email||!pass){ 
      err.textContent="Name, Email, and Password are required."; 
      return; 
    }

    try {
      const res = await fetch('add_customer.php',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({name,email,phone,password:pass})
      });

      const text = await res.text(); // read raw response
      let data;
      try { data = JSON.parse(text); }
      catch(e){ 
        err.textContent="Server did not return valid JSON. Response was: "+text; 
        return; 
      }

      if(data.error){ 
        err.textContent=data.error; 
        return; 
      }

      // add customer to dropdown
      const sel=document.getElementById('customer_id');
      const opt=document.createElement('option');
      opt.value=data.id; 
      opt.textContent=`${data.name} (${data.email})`; 
      opt.selected=true;
      sel.appendChild(opt);

      // reset form + close modal
      ['c_name','c_email','c_phone','c_pass'].forEach(id => document.getElementById(id).value='');
      err.textContent='';
      modal.classList.remove('show');
      setTimeout(()=>modal.style.display='none',300);

    } catch (errFetch) {
      err.textContent="Request failed: "+errFetch.message;
    }
  };
</script>

</body>
</html>
