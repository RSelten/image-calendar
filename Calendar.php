/*
 * ImageCalendar - Weekkalender met plaatjes
 * Copyright (C) 2025  Roy Selten
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

<?php

$env = parse_ini_file(__DIR__ . '/.env');

$dbHost = $env['DB_HOST'];
$dbName = $env['DB_NAME'];
$dbUser = $env['DB_USER'];
$dbPass = $env['DB_PASS'];

// Voorbeeld databaseconnectie (PDO)
$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
$pdo = new PDO($dsn, $dbUser, $dbPass);

$CFG = [
  'db' => [
    'dsn' => "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
    'user' => $dbUser,
    'pass' => $dbPass,
    'options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
  ],
  'image_dir' => __DIR__ . '/afbeeldingen',
  'image_url' => 'afbeeldingen'
];

/**
 * Establish a persistent PDO database connection using configuration settings.
 *
 * Returns:
 *   PDO object with error handling enabled (exception mode).
 */
function pdo_conn(){
  global $CFG; static $pdo=null; if(!$pdo){ $pdo=new PDO($CFG['db']['dsn'],$CFG['db']['user'],$CFG['db']['pass'],$CFG['db']['options']); }
  return $pdo;
}
function ensure_tables(){
  $pdo=pdo_conn();
  $pdo->exec("CREATE TABLE IF NOT EXISTS calendar_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    datum DATE NOT NULL,
    slot TINYINT NOT NULL CHECK (slot IN (1,2,3)),
    afbeelding VARCHAR(255) NOT NULL,
    positie INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(datum), INDEX(slot)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
}
ensure_tables();

function monday_of_iso_week($year,$week){ $d=new DateTime(); $d->setISODate($year,$week,1); $d->setTime(0,0,0); return $d; }
function week_bounds_from_query(){
  if(isset($_GET['start'])){ $s=DateTime::createFromFormat('Y-m-d', $_GET['start']); if($s){ $s->setTime(0,0,0); return $s; } }
  $now=new DateTime('today');
  $year= isset($_GET['year'])? (int)$_GET['year'] : (int)$now->format('o');
  $week= isset($_GET['week'])? (int)$_GET['week'] : (int)$now->format('W');
  return monday_of_iso_week($year,$week);
}
function iso_week_label(DateTime $m){ return ['week'=>(int)$m->format('W'),'year'=>(int)$m->format('o')]; }
function date_ymd(DateTime $d){ return $d->format('Y-m-d'); }

function json_response($obj){ header('Content-Type: application/json'); echo json_encode($obj); exit; }
function list_images(){ global $CFG; $dir=$CFG['image_dir']; $out=[]; if(is_dir($dir)){ foreach(scandir($dir) as $f){ if($f==='.'||$f==='..')continue; $p="$dir/$f"; if(!is_file($p))continue; $ext=strtolower(pathinfo($p,PATHINFO_EXTENSION)); if(!in_array($ext,['png','jpg','jpeg','gif','webp','svg']))continue; $out[]=$f; } sort($out,SORT_NATURAL|SORT_FLAG_CASE); } return $out; }

function api_get_week(){ global $CFG; $pdo=pdo_conn(); $mon=week_bounds_from_query();
  $days=[]; for($i=0;$i<7;$i++){ $d=clone $mon; $d->modify("+{$i} day"); $days[]=date_ymd($d); }
  $ph=implode(',', array_fill(0,count($days),'?'));
  $stmt=$pdo->prepare("SELECT id, datum, slot, afbeelding, positie FROM calendar_items WHERE datum IN ($ph) ORDER BY datum, slot, positie, id");
  $stmt->execute($days); $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
  $by=[]; foreach($days as $d){ $by[$d]=[1=>[],2=>[],3=>[]]; }
  foreach($rows as $r){ $by[$r['datum']][(int)$r['slot']][]=$r; }
  $wl=iso_week_label($mon);
  json_response([
    'monday'=>date_ymd($mon), 'week'=>$wl['week'], 'year'=>$wl['year'],
    'days'=>$days, 'slots'=>$by, 'images'=>list_images(), 'imageUrlBase'=>$CFG['image_url']
  ]);
}
function api_add_item(){ $pdo=pdo_conn(); $datum=$_POST['datum']??''; $slot=(int)($_POST['slot']??0); $afbeelding=basename($_POST['afbeelding']??'');
  if(!$datum||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$datum)||!in_array($slot,[1,2,3])||!$afbeelding) json_response(['ok'=>false,'error'=>'Ongeldige invoer']);
  $stmt=$pdo->prepare("SELECT COALESCE(MAX(positie),-1)+1 FROM calendar_items WHERE datum=? AND slot=?"); $stmt->execute([$datum,$slot]); $pos=(int)$stmt->fetchColumn();
  $ins=$pdo->prepare("INSERT INTO calendar_items (datum,slot,afbeelding,positie) VALUES (?,?,?,?)"); $ins->execute([$datum,$slot,$afbeelding,$pos]);
  json_response(['ok'=>true,'id'=>$pdo->lastInsertId(),'positie'=>$pos]);
}
function api_move_item(){ $pdo=pdo_conn(); $id=(int)($_POST['id']??0); $datum=$_POST['datum']??''; $slot=(int)($_POST['slot']??0); $newIndex= isset($_POST['index'])? (int)$_POST['index'] : null;
  if($id<=0||!$datum||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$datum)||!in_array($slot,[1,2,3])) json_response(['ok'=>false,'error'=>'Ongeldige invoer']);
  if($newIndex===null){ $stmt=$pdo->prepare("SELECT COALESCE(MAX(positie),-1)+1 FROM calendar_items WHERE datum=? AND slot=?"); $stmt->execute([$datum,$slot]); $newIndex=(int)$stmt->fetchColumn(); }
  $pdo->beginTransaction();
  try{
    $pdo->prepare("UPDATE calendar_items SET positie=positie+1 WHERE datum=? AND slot=? AND positie>=?")->execute([$datum,$slot,$newIndex]);
    $pdo->prepare("UPDATE calendar_items SET datum=?, slot=?, positie=? WHERE id=?")->execute([$datum,$slot,$newIndex,$id]);
    $pdo->commit();
  }catch(Exception $e){ $pdo->rollBack(); json_response(['ok'=>false,'error'=>$e->getMessage()]); }
  json_response(['ok'=>true]);
}
function api_remove_item(){ $pdo=pdo_conn(); $id=(int)($_POST['id']??0); if($id<=0) json_response(['ok'=>false,'error'=>'Ongeldige id']);
  $row=$pdo->prepare("SELECT datum, slot, positie FROM calendar_items WHERE id=?"); $row->execute([$id]); $r=$row->fetch(PDO::FETCH_ASSOC); if(!$r) json_response(['ok'=>false,'error'=>'Niet gevonden']);
  $pdo->beginTransaction();
  try{
    $pdo->prepare("DELETE FROM calendar_items WHERE id=?")->execute([$id]);
    $pdo->prepare("UPDATE calendar_items SET positie=positie-1 WHERE datum=? AND slot=? AND positie>?")->execute([$r['datum'],$r['slot'],$r['positie']]);
    $pdo->commit();
  }catch(Exception $e){ $pdo->rollBack(); json_response(['ok'=>false,'error'=>$e->getMessage()]); }
  json_response(['ok'=>true]);
}
function api_reorder_slot(){ $pdo=pdo_conn(); $datum=$_POST['datum']??''; $slot=(int)($_POST['slot']??0); $ids=$_POST['ids']??[];
  if(!$datum||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$datum)||!in_array($slot,[1,2,3])||!is_array($ids)) json_response(['ok'=>false,'error'=>'Ongeldige invoer']);
  $pdo->beginTransaction(); try{ $pos=0; $stmt=$pdo->prepare("UPDATE calendar_items SET positie=? WHERE id=? AND datum=? AND slot=?"); foreach($ids as $id){ $id=(int)$id; $stmt->execute([$pos++,$id,$datum,$slot]); } $pdo->commit(); } catch(Exception $e){ $pdo->rollBack(); json_response(['ok'=>false,'error'=>$e->getMessage()]); }
  json_response(['ok'=>true]);
}
function api_copyfromlastweek(){ $pdo = pdo_conn(); $datum = $_POST['datum'] ?? ''; if(!$datum || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) json_response(['ok'=>false,'error'=>'Ongeldige datum']);

  // Bepaal maandag van de opgegeven week
  $d = new DateTime($datum); $year = (int)$d->format('o'); $week = (int)$d->format('W'); $monday = monday_of_iso_week($year, $week);

  // Bepaal maandag van vorige week
  $prevMonday = clone $monday; $prevMonday->modify('-7 days');

  // Verzamel alle dagen van deze week en vorige week
  $days = []; $prevDays = []; for($i=0; $i<7; $i++){ $days[] = (clone $monday)->modify("+{$i} days")->format('Y-m-d'); $prevDays[] = (clone $prevMonday)->modify("+{$i} days")->format('Y-m-d'); }

  // Haal alle items van vorige week op
  $ph = implode(',', array_fill(0, count($prevDays), '?'));
  $stmt = $pdo->prepare("SELECT datum, slot, afbeelding, positie FROM calendar_items WHERE datum IN ($ph) ORDER BY datum, slot, positie, id"); $stmt->execute($prevDays); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if(!$rows) json_response(['ok'=>false,'error'=>'Geen items gevonden in vorige week']);

  $pdo->beginTransaction();
  try{
    // Verwijder alle items van deze week
    $ph2 = implode(',', array_fill(0, count($days), '?'));
    $pdo->prepare("DELETE FROM calendar_items WHERE datum IN ($ph2)")->execute($days);

    // Kopieer items van vorige week naar deze week (zelfde slot, positie, afbeelding)
    foreach($rows as $r){
      // Bereken de nieuwe datum (zelfde dag van de week)
      $prevIdx = array_search($r['datum'], $prevDays);
      if($prevIdx === false) continue;
      $newDatum = $days[$prevIdx];
      $pdo->prepare("INSERT INTO calendar_items (datum, slot, afbeelding, positie) VALUES (?, ?, ?, ?)")
          ->execute([$newDatum, $r['slot'], $r['afbeelding'], $r['positie']]);
    }
    $pdo->commit();
  }catch(Exception $e){
    $pdo->rollBack();
    json_response(['ok'=>false,'error'=>$e->getMessage()]);
  }
  json_response(['ok'=>true]);
}
/**
 * API endpoint: Remove all images assigned to a specific day.
 *
 * Expects POST parameter:
 *   day (string, YYYY-MM-DD) - Date of the day to clear
 *
 * Returns: JSON response with success or error message.
 */
function api_remove_day(){ $pdo=pdo_conn(); $datum=$_POST['datum']??''; if(!$datum||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$datum)) json_response(['ok'=>false,'error'=>'Ongeldige datum']);
  $pdo->prepare("DELETE FROM calendar_items WHERE datum=?")->execute([$datum]); json_response(['ok'=>true]); }

if(isset($_GET['api'])){
  header('Access-Control-Allow-Origin: *'); header('Access-Control-Allow-Methods: GET, POST, OPTIONS'); header('Access-Control-Allow-Headers: Content-Type'); if($_SERVER['REQUEST_METHOD']==='OPTIONS'){ exit; }
  try{
    switch($_GET['api']){
      case 'week': api_get_week(); break;
      case 'add': api_add_item(); break;
      case 'move': api_move_item(); break;
      case 'remove': api_remove_item(); break;
      case 'reorder': api_reorder_slot(); break;
      case 'remove_day': api_remove_day(); break;
      case 'copyfromlastweek': api_copyfromlastweek(); break;
      case 'images': json_response(['images'=>list_images()]); break;
      default: json_response(['ok'=>false,'error'=>'Onbekende API']);
    }
  }catch(Throwable $e){ json_response(['ok'=>false,'error'=>$e->getMessage()]); }
}

$monday = week_bounds_from_query();
$weekInfo = iso_week_label($monday);
$daysYMD = []; for($i=0;$i<7;$i++){ $d=clone $monday; $d->modify("+{$i} day"); $daysYMD[]=$d->format('Y-m-d'); }
$readonly = isset($_GET['readonly']) && $_GET['readonly']=='1';
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Weekkalender met plaatjes</title>
<style>
  :root{ --bg:#0b1220; --card:#111827; --muted:#1f2937; --text:#e5e7eb; --accent:#60a5fa; --grid:#374151; --ok:#10b981; --warn:#f59e0b; --danger:#ef4444; }
  *{ box-sizing:border-box; }
  body{ margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif; background:var(--bg); color:var(--text); }
  a{ color:var(--accent); text-decoration:none; }
  header{ display:flex; align-items:center; gap:.75rem; padding:0.1rem; position:sticky; top:0; background:linear-gradient(180deg, rgba(11,18,32,.9), rgba(11,18,32,.6)); backdrop-filter: blur(6px); z-index:10; }
  .btn{ padding:.5rem .8rem; border:1px solid var(--grid); background:var(--card); color:var(--text); border-radius:.75rem; cursor:pointer; }
  .btn:hover{ border-color:var(--accent); }
  .spacer{ flex:1; }
  .weeklabel{ font-weight:700; font-size:1.25rem; letter-spacing:.5px; }

  .container{ display:grid; grid-template-columns: 1fr; gap:1rem; padding:1rem; }
  .grid{ overflow:auto; border:1px solid var(--grid); border-radius:1rem; background:var(--card); }
  table{ width:100%; border-collapse:separate; border-spacing:0; table-layout:fixed; align-items:center; }
  th, td{ padding:.5rem; border-bottom:1px solid var(--grid); vertical-align:top;  align-items:center;}
  th{ position:sticky; top:0; background:var(--card); z-index:1; }
  thead th{ text-align:center; font-size:.95rem; }
  colgroup col{ width: calc(100%/7); }

  .dayhead{ display:flex; flex-direction:column; gap:2px; align-items:center; }
  .dayname{ font-weight:700; text-transform:capitalize; }
  .daydate{ font-size:.8rem; opacity:.8; }
  .dayactions{ margin-top:.35rem; }

  .slot{ min-height: 180px; border:1px dashed transparent; padding:.5rem; border-radius:.75rem; align-items:center;}
  .slot.dropzone{ border-color: var(--grid); background: rgba(96,165,250,.05);  align-items:center;}
  .slot.active{ border-color: var(--accent); background: rgba(96,165,250,.12); align-items:center; }

/*  .item{ display:inline-flex; flex-direction:column; align-items:center; gap:.25rem; margin:.25rem; padding:.25rem; border:1px solid var(--grid); background:#0d1628; border-radius:.5rem; user-select:none; touch-action:none; }
*/
  .item{ display:inline-flex; flex-direction:column; align-items:center; gap:.25rem; margin:.25rem; padding:.25rem; border:0px solid var(--grid); border-radius:.5rem; user-select:none; touch-action:none; }
  .item img{ max-width:90px; max-height:60px; display:block; border-radius:.35rem; }
  .item .cap{ font-size:.7rem; max-width:90px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; opacity:.8; }
  .dragging{ opacity:.6; }
  .ghost{ position:fixed; pointer-events:none; z-index:9999; transform: translate(-50%, -50%); box-shadow: 0 10px 30px rgba(0,0,0,.4); background:#0d1628; border:1px solid var(--accent); border-radius:.5rem; }

  .panel{ border:1px solid var(--grid); border-radius:1rem; background:var(--card); padding:.75rem; }
  .panel h2{ margin:.2rem 0 .5rem; font-size:1rem; opacity:.9; }
  .images{ display:flex; flex-wrap:wrap; gap:.25rem; max-height: 240px; overflow:auto; }

  .trash{ position:fixed; right:1rem; bottom:1rem; width:84px; height:84px; border-radius:1rem; border:2px dashed var(--danger); color:#fecaca; display:flex; align-items:center; justify-content:center; background:rgba(239,68,68,.06); font-weight:700; z-index:20; }
  .trash.active{ background:rgba(239,68,68,.15); }

  .legend{ display:flex; gap:.5rem; align-items:center; font-size:.85rem; opacity:.85; }
  .kbd{ border:1px solid var(--grid); border-bottom-width:2px; padding:.1rem .35rem; border-radius:.3rem; background:#0b1424; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
  
  .today-col {
    background-color: rgba(5, 247, 62, 0.38) !important;
  }
  @media (min-width: 1000px){ .container{ grid-template-columns: 1fr 200px; } .images{ max-height: calc(100vh - 240px); } }

</style>
</head>
<body>
<header>
  <button class="btn" id="prevWeek">← Vorige week</button>
  <div class="weeklabel">Week <?php echo htmlspecialchars($weekInfo['week']); ?> — <?php echo htmlspecialchars($weekInfo['year']); ?></div>
  <div class="spacer"></div>
  <button class="btn" id="today">Vandaag</button>
  <?php if(!$readonly): ?>
    <div class="spacer"></div>
    <button class="btn" id="copyLastWeek" onclick="copyFromLastWeek()">Kopieer van vorige week</button>
  <?php endif; ?>
  <div class="spacer"></div>
  <button class="btn" id="nextWeek">Volgende week →</button>
</header>

<div class="container">
  <div class="grid">
    <table id="kalTable">
      <colgroup id="kalCols">
        <?php for($i=0;$i<7;$i++) echo '<col>'; ?>
      </colgroup>
      <thead><tr id="kalHead">
        <?php for($i=0;$i<7;$i++): $d=(clone $monday)->modify("+{$i} day"); ?>
          <th data-col="<?php echo $i; ?>">
            <div class="dayhead">
              <div class="dayname">—</div>
              <div class="daydate">—</div>
              <div class="dayactions"></div>
            </div>
          </th>
        <?php endfor; ?>
      </tr></thead>
      <tbody id="kalBody">
        <?php for($slot=1;$slot<=3;$slot++): ?>
        <tr>
          <?php for($i=0;$i<7;$i++): $d=(clone $monday)->modify("+{$i} day"); $ymd=$d->format('Y-m-d'); ?>
            <td data-col="<?php echo $i; ?>">
              <div class="slot dropzone" data-datum="<?php echo $ymd; ?>" data-slot="<?php echo $slot; ?>"></div>
            </td>
          <?php endfor; ?>
        </tr>
        <?php endfor; ?>
      </tbody>
    </table>
  </div>
    <div class="panel" id="panelImages">
      <h2>Beschikbare plaatjes</h2>
      <div class="legend"><span class="kbd">Sleep</span> een plaatje naar een vak. Meerdere per vak toegestaan.</div>
      <div id="images" class="images"></div>
    </div>

</div>

<div class="trash dropzone" id="trash" aria-label="Prullenbak">🗑️</div>

<script>
const API = {
  week: (start) => fetch(`?api=week&start=${encodeURIComponent(start)}`).then(r=>r.json()),
  add: (p) => fetch(`?api=add`,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(p)}).then(r=>r.json()),
  move: (p) => fetch(`?api=move`,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(p)}).then(r=>r.json()),
  remove: (p) => fetch(`?api=remove`,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(p)}).then(r=>r.json()),
  reorder: (p) => fetch(`?api=reorder`,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(p)}).then(r=>r.json()),
  remove_day: (p) => fetch(`?api=remove_day`,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(p)}).then(r=>r.json()),
  copyfromlastweek: (p) => fetch(`?api=copyfromlastweek`,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(p)}).then(r=>r.json()),
};

const STATE = {
  monday: '<?php echo $monday->format('Y-m-d'); ?>',
  week: <?php echo (int)$weekInfo['week']; ?>,
  year: <?php echo (int)$weekInfo['year']; ?>,
  imageUrlBase: '<?php echo htmlspecialchars($CFG['image_url']); ?>',
  readonly: <?php echo $readonly? 'true':'false'; ?>,
};

const DAY_COLORS = [
  'rgba(96,165,250,0.10)', // ma
  'rgba(52,211,153,0.10)', // di
  'rgba(251,191,36,0.10)', // wo
  'rgba(248,113,113,0.10)', // do
  'rgba(167,139,250,0.10)', // vr
  'rgba(34,197,94,0.10)', // za
  'rgba(14,165,233,0.10)', // zo
];

const DAY_COLORS_TODAY = [
  'rgba(96,165,250,0.50)', // ma
  'rgba(52,211,153,0.50)', // di
  'rgba(251,191,36,0.50)', // wo
  'rgba(248,113,113,0.50)', // do
  'rgba(167,139,250,0.50)', // vr
  'rgba(34,197,94,0.50)', // za
  'rgba(14,165,233,0.50)', // zo
];

function el(tag, attrs={}, ...children){
  const e=document.createElement(tag);
  for(const [k,v] of Object.entries(attrs)){
    if(k==='class'){ e.className=v; }
    else if(k==='html'){ e.innerHTML=v; }
    else if(k==='style' && typeof v==='object'){ Object.assign(e.style,v); }
    else if(k.startsWith('on') && typeof v === 'function'){ e[k] = v; }
    else{ e.setAttribute(k,v); }
  }
  for(const c of children){ if(c==null) continue; e.append(c instanceof Node? c: document.createTextNode(c)); }
  return e;
}

function nlDayname(date){
  const dn = new Date(date+"T00:00:00").toLocaleDateString('nl-NL',{weekday:'long'});
  return dn.charAt(0).toUpperCase()+dn.slice(1);
}
function nlDate(date){
  return new Date(date+"T00:00:00").toLocaleDateString('nl-NL',{day:'2-digit',month:'2-digit',year:'numeric'});
}

function itemNode(row){
  const n=el('div',{class:'item',draggable:'false'});
  n.dataset.id=row.id||''; n.dataset.filename=row.afbeelding||row.filename;
  const img=el('img',{src:`${STATE.imageUrlBase}/${n.dataset.filename}`,alt:n.dataset.filename});
  const cap=el('div',{class:'cap'}, n.dataset.filename);
  n.append(img);
  if (!STATE.readonly) makeDraggable(n);
  return n;
}

function buildGrid(days){
  const headRow=document.getElementById('kalHead');
  const body=document.getElementById('kalBody');
  headRow.innerHTML=''; body.innerHTML='';
  const todayStr = new Date().toISOString().slice(0,10);   // <--- toegevoegd
  days.forEach((d,idx)=>{
    const th=el('th',{"data-col":idx});
    const head=el('div',{class:'dayhead'});
    head.append(
      el('div',{class:'dayname'}, nlDayname(d)),
      el('div',{class:'daydate'}, nlDate(d))
    );
    const actions=el('div',{class:'dayactions'});
    if(!STATE.readonly){
      const btn=el('button',{class:'btn btn-sm', onclick:()=>clearDay(d, idx)}, 'Alles verwijderen');
      actions.append(btn);
    }
    head.append(actions);
    th.append(head);
    if (d === todayStr){
        th.style.backgroundColor = DAY_COLORS_TODAY[idx % DAY_COLORS_TODAY.length];
    }
    else{
        th.style.backgroundColor = DAY_COLORS[idx % DAY_COLORS.length];
    }
    headRow.append(th);
  });
  for(let s=1;s<=3;s++){
    const tr=el('tr');
    days.forEach((d,idx)=>{
      const td=el('td',{"data-col":idx});
      if (d === todayStr){
        td.style.backgroundColor = DAY_COLORS_TODAY[idx % DAY_COLORS_TODAY.length];
      }  
      else{
        td.style.backgroundColor = DAY_COLORS[idx % DAY_COLORS.length];
      }
      const zone=el('div',{class:'slot dropzone',"data-datum":d,"data-slot":String(s)});
      td.append(zone); tr.append(td);
    });
    body.append(tr);
  }
}

function renderWeek(payload){
  buildGrid(payload.days);
  for(const [datum,slots] of Object.entries(payload.slots)){
    for(const slot of [1,2,3]){
      const zone=document.querySelector(`.slot[data-datum="${datum}"][data-slot="${slot}"]`);
      if(!zone){ console.warn('Slot niet gevonden voor', {datum,slot}); continue; }
      (slots[slot]||[]).forEach(row=> zone.append(itemNode(row)) );
    }
  }
  const imgWrap=document.getElementById('images');
  if(imgWrap){ imgWrap.innerHTML=''; (payload.images||[]).forEach(fn=> imgWrap.append(itemNode({filename:fn})) ); }
  document.querySelector('.weeklabel').textContent = `Week ${payload.week} — ${payload.year}`;
  STATE.monday=payload.monday; STATE.imageUrlBase=payload.imageUrlBase;
  document.getElementById('panelImages').style.display = STATE.readonly? 'none':'block';
  document.getElementById('trash').style.display = STATE.readonly? 'none':'flex';
}

async function loadWeek(start){ const data=await API.week(start); renderWeek(data); }
function offsetMonday(days){ const d=new Date(STATE.monday); d.setDate(d.getDate()+days); return d.toISOString().slice(0,10); }

document.getElementById('prevWeek').addEventListener('click', ()=> loadWeek(offsetMonday(-7)) );
document.getElementById('nextWeek').addEventListener('click', ()=> loadWeek(offsetMonday(7)) );
document.getElementById('today').addEventListener('click', ()=> loadWeek(0) );

let drag=null;
function makeDraggable(node){ node.addEventListener('pointerdown', (ev)=>{ if(ev.button!==0) return; ev.preventDefault(); node.setPointerCapture(ev.pointerId); startDrag(ev,node); }); }
function startDrag(ev,node){
  const rect=node.getBoundingClientRect();
  drag={ node, startX:ev.clientX, startY:ev.clientY, offsetX:ev.clientX-rect.left, offsetY:ev.clientY-rect.top, originParent:node.parentElement, ghost:null, data:{ id:node.dataset.id||null, filename:node.dataset.filename } };
  node.classList.add('dragging');
  const ghost=node.cloneNode(true); ghost.classList.add('ghost'); ghost.style.width=rect.width+'px'; ghost.style.height=rect.height+'px'; document.body.appendChild(ghost); drag.ghost=ghost; moveGhost(ev.clientX,ev.clientY);
  window.addEventListener('pointermove', onDragMove); window.addEventListener('pointerup', onDragEnd, {once:true});
}
function moveGhost(x,y){ if(!drag) return; drag.ghost.style.left=x+'px'; drag.ghost.style.top=y+'px'; highlightDropzones(x,y); }
function onDragMove(ev){ if(!drag) return; moveGhost(ev.clientX,ev.clientY); }
function highlightDropzones(x,y){ document.querySelectorAll('.dropzone').forEach(z=> z.classList.remove('active')); const el=document.elementFromPoint(x,y); const zone= el&&el.closest? el.closest('.dropzone'): null; if(zone) zone.classList.add('active'); }
async function onDragEnd(ev){ if(!drag) return; const {node,ghost,originParent,data}=drag; document.querySelectorAll('.dropzone').forEach(z=> z.classList.remove('active')); const x=ev.clientX,y=ev.clientY; const dropped=document.elementFromPoint(x,y); const zone=dropped&&dropped.closest? dropped.closest('.dropzone'): null; ghost.remove(); node.classList.remove('dragging');
  if(zone && zone.id==='trash' && data.id){ const res=await API.remove({id:data.id}); if(res.ok){ originParent&&originParent.removeChild(node); } drag=null; return; }
  if(zone && zone.classList.contains('slot')){
    const datum=zone.getAttribute('data-datum'); const slot=parseInt(zone.getAttribute('data-slot'),10);
    if(!data.id){ const res=await API.add({datum,slot,afbeelding:data.filename}); if(res.ok){ const newNode=itemNode({id:res.id, afbeelding:data.filename}); zone.appendChild(newNode); } }
    else { const children=Array.from(zone.querySelectorAll('.item')); const index=children.length; const res=await API.move({id:data.id, datum, slot, index}); if(res.ok){ zone.appendChild(node); } }
  }
  drag=null;
}

document.addEventListener('click', (ev)=>{
  const node=ev.target.closest&&ev.target.closest('.item'); if(node && ev.altKey){ const slot=node.parentElement; if(!slot||!slot.classList.contains('slot')) return; const datum=slot.getAttribute('data-datum'); const s=parseInt(slot.getAttribute('data-slot'),10); const items=Array.from(slot.querySelectorAll('.item')); const ids=items.map(n=>n.dataset.id); const idx=ids.indexOf(node.dataset.id); if(idx>0){ ids.splice(idx,1); ids.unshift(node.dataset.id); API.reorder({datum,slot:s,ids}); slot.insertBefore(node, slot.firstChild); } }
});

async function clearDay(datum){
  console.log('clearDay →', datum);
  try{
    const res = await API.remove_day({datum});
    console.log('remove_day response', res);
    if(res.ok){
      document.querySelectorAll(`.slot[data-datum="${datum}"]`).forEach(z=> z.innerHTML='');
      if(typeof res.deleted !== 'undefined') console.log(`Verwijderd uit DB: ${res.deleted} items`);
    }else{
      alert('Verwijderen mislukt: ' + (res.error || 'onbekende fout'));
    }
  }catch(e){
    console.error('remove_day failed', e);
    alert('Netwerkfout bij verwijderen (zie console).');
  }
}

async function copyFromLastWeek(){
  const datum = STATE.monday; // Maandag van de huidige week
  if(!confirm(`Weet je zeker dat je alle plaatjes van de week ervoor wilt kopiëren naar de week van ${datum}? Dit overschrijft alle bestaande plaatjes in deze week.`)){
    return;
  }
  try{
    const res = await fetch(`?api=copyfromlastweek`, {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: new URLSearchParams({datum})
    }).then(r => r.json());
    if(res.ok){
      loadWeek(STATE.monday);
    }else{
      alert('Kopiëren mislukt: ' + (res.error || 'onbekende fout'));
    }
  }catch(e){
    console.error('copyfromlastweek failed', e);
    alert('Netwerkfout bij kopiëren (zie console).');
  }
  
}

loadWeek(STATE.monday);
</script>
</body>
</html>