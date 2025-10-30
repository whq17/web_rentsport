<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$customerName = $_SESSION['user_name'];

include 'db_connect.php';

if (!isset($_GET['venue_id']) || !is_numeric($_GET['venue_id'])) {
    die("Error: ไม่พบรหัสสนามกีฬา");
}
$venue_id = (int)$_GET['venue_id'];

$sql = "SELECT v.*, vt.TypeName
        FROM Tbl_Venue AS v
        JOIN Tbl_Venue_Type AS vt ON v.VenueTypeID = vt.VenueTypeID
        WHERE v.VenueID = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $venue_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 1) {
    $venue = $result->fetch_assoc();
} else {
    die("Error: ไม่พบสนามกีฬานี้ หรือสนามไม่พร้อมให้บริการ");
}
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>จองสนาม - <?php echo htmlspecialchars($venue['VenueName']); ?></title>
<style>
body { font-family: "Prompt", sans-serif; background-color: #f1f5f9; margin: 0; color: #1e293b; }
.header { background: #0f172a; color: white; display: flex; justify-content: space-between; align-items: center; padding: 16px 40px; box-shadow: 0 3px 8px rgba(0,0,0,0.2); }
.header .logo { font-size: 1.4em; font-weight: 600; }
.logout-btn { background: #ef4444; color: white; border: none; padding: 8px 14px; border-radius: 6px; text-decoration: none; font-weight: 500; transition: background 0.2s; }
.logout-btn:hover { background: #dc2626; }
.container { max-width: 650px; background: white; margin: 40px auto; border-radius: 16px; padding: 35px 40px; box-shadow: 0 10px 25px rgba(0,0,0,0.08); transition: 0.3s ease; }
.container:hover { box-shadow: 0 15px 30px rgba(0,0,0,0.12); }
h1 { color: #0f172a; font-size: 1.6em; margin-bottom: 10px; }
h2 { color: #2563eb; font-size: 1.3em; margin-top: 30px; }
p { line-height: 1.7; margin: 6px 0; }
.price { font-size: 1.5em; font-weight: bold; color: #16a34a; margin: 10px 0 15px; }
.form-group { margin-bottom: 18px; }
label { display: block; font-weight: 600; margin-bottom: 6px; color: #1e3a8a; }
input[type="date"], input[type="number"], input[type="text"], select { width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 1em; transition: border-color 0.2s; background: #fff; }
input:focus, select:focus { border-color: #2563eb; outline: none; }
button, .submit-btn { background: #3b82f6; color: white; padding: 12px 16px; border-radius: 8px; border: none; font-weight: 600; cursor: pointer; transition: all 0.25s; }
button:hover, .submit-btn:hover { background: #2563eb; transform: translateY(-2px); }
#promoResult { margin-top: 8px; font-weight: 500; }
.back-link { display: inline-block; margin-top: 20px; text-decoration: none; color: #2563eb; font-weight: 500; }
.back-link:hover { text-decoration: underline; }
.promo-group { display: flex; gap: 10px; align-items: center; }
.promo-group input { flex: 1; }
.small { color:#64748b; font-size: 0.92em; margin-top:6px; }
.error { color:#dc2626; font-weight:600; }
.time-row { display:flex; gap:8px; align-items:center; }
.time-row select { width:auto; min-width: 92px; }
.time-row .ampm { min-width: 80px; }
.time-row .sep { opacity:.6; }
.readonly-like { background:#f8fafc; border-color:#e2e8f0; }
</style>
</head>

<body>
<header class="header">
  <div class="logo">CY Arena</div>
  <div>สวัสดี, <?php echo htmlspecialchars($customerName); ?> 
    <a href="logout.php" class="logout-btn">ออกจากระบบ</a>
  </div>
</header>

<div class="container">
  <h1><?php echo htmlspecialchars($venue['VenueName']); ?></h1>
  <p><strong>ประเภท:</strong> <?php echo htmlspecialchars($venue['TypeName']); ?></p>
  <p><strong>รายละเอียด:</strong> <?php echo nl2br(htmlspecialchars($venue['Description'])); ?></p>
  <p class="price">💵 ราคา: ฿<?php echo number_format($venue['PricePerHour'], 2); ?> / ชั่วโมง</p>
  <p><strong>เวลาทำการ:</strong> <?php echo date("H:i", strtotime($venue['TimeOpen'])); ?> - <?php echo date("H:i", strtotime($venue['TimeClose'])); ?> น.</p>

  <form action="confirm_booking.php" method="POST" id="bookingForm">
    <h2>📅 กรอกรายละเอียดการจอง</h2>

    <!-- จำเป็นต้องส่งไปหน้า confirm -->
    <input type="hidden" name="venue_id" id="venue_id" value="<?php echo (int)$venue_id; ?>">
    <input type="hidden" name="promotion_id" id="promotion_id" value="">
    <input type="hidden" name="total_price" id="total_price" value="">

    <div class="form-group">
      <label>เลือกวันที่:</label>
      <input
        type="date"
        name="booking_date"
        id="booking_date"
        required
        min="<?= date('Y-m-d') ?>"
        value="<?= date('Y-m-d') ?>"
      >
    </div>

    <div class="form-group">
      <label>เวลาเริ่ม:</label>
      <div class="time-row">
        <select id="hh12"><option value="">--</option></select>
        <span class="sep">:</span>
        <select id="mm"><option value="">--</option></select>
        <select id="ampm" class="ampm">
          <option value="AM">AM</option>
          <option value="PM">PM</option>
        </select>
      </div>
      <input type="hidden" name="start_time" id="start_time">
      <input type="hidden" id="open_24"  value="<?= date('H:i', strtotime($venue['TimeOpen'])) ?>">
      <input type="hidden" id="close_24" value="<?= date('H:i', strtotime($venue['TimeClose'])) ?>">
      <div class="small">* เลือกได้ทีละ 30 นาที และถ้าเป็น “วันนี้” จะไม่ให้เร็วกว่าปัจจุบัน (ปัดขึ้น 30 นาที)</div>
      <div id="startHelp" class="small"></div>
    </div>

    <div class="form-group">
      <label>จำนวนชั่วโมง:</label>
      <input type="number" name="hours" id="hours" min="1" step="0.5" value="1" required>
    </div>

    <div class="form-group">
      <label>เวลาเสร็จสิ้น (คำนวณอัตโนมัติ):</label>
      <input type="text" id="end_time_display" class="readonly-like" readonly placeholder="--:-- AM/PM">
      <input type="hidden" name="end_time" id="end_time">
      <div id="endHelp" class="small"></div>
    </div>

    <div class="form-group">
      <label>🎁 รหัสโปรโมชั่น (ถ้ามี)</label>
      <div class="promo-group">
        <input type="text" id="promoCode" name="promo_code" placeholder="กรอกรหัสโปรโมชั่น">
        <button type="button" onclick="checkPromotion()">ตรวจสอบโค้ด</button>
      </div>
      <p id="promoResult"></p>
    </div>

    <button type="submit" class="submit-btn" id="submitBtn">✅ จองสนาม</button>
  </form>

  <a href="dashboard.php" class="back-link">← กลับไปเลือกสนาม</a>
</div>

<script>
function checkPromotion() {
  const code = document.getElementById('promoCode').value.trim();
  const resultEl = document.getElementById('promoResult');
  if (!code) { resultEl.innerHTML = "⚠️ กรุณากรอกรหัสโปรโมชั่น"; resultEl.style.color = "#dc2626"; return; }
  fetch('promotion_check.php?code=' + encodeURIComponent(code))
    .then(res => res.json())
    .then(data => {
      if (data.valid) {
        resultEl.innerHTML = `✅ ใช้ได้: ส่วนลด <strong>${data.discount_text}</strong>`;
        resultEl.style.color = "#16a34a";
        if (data.promotion_id) document.getElementById('promotion_id').value = data.promotion_id;
      } else {
        resultEl.innerHTML = `❌ ${data.message}`;
        resultEl.style.color = "#dc2626";
        document.getElementById('promotion_id').value = '';
      }
    })
    .catch(() => { resultEl.innerHTML = "⚠️ ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้"; resultEl.style.color = "#dc2626"; });
}

/* ===== Utilities ===== */
function pad2(n){ return String(n).padStart(2,'0'); }
function to12(hhmm){
  let [h,m]=hhmm.split(':').map(Number);
  const ampm = h>=12 ? 'PM':'AM';
  h = h%12; if(h===0) h=12;
  return `${pad2(h)}:${pad2(m)} ${ampm}`;
}
function to24_from_parts(h12, m, ap){
  let h = parseInt(h12||'0',10);
  if (isNaN(h)) return '';
  if (ap === 'PM' && h !== 12) h += 12;
  if (ap === 'AM' && h === 12) h = 0;
  return `${pad2(h)}:${pad2(parseInt(m||'0',10))}`;
}
function cmpTime(a,b){ return a===b?0:(a>b?1:-1); }
function addMinutes(hhmm, mins){
  let [h,m]=hhmm.split(':').map(Number);
  let t=h*60+m+mins;
  if (t<0) t=0;
  return `${pad2(Math.floor(t/60))}:${pad2(t%60)}`;
}
function roundUpTo30(hhmm){
  let [h,m]=hhmm.split(':').map(Number);
  const mins=h*60+m;
  const add=(30-(mins%30))%30;
  const next=mins+add;
  return `${pad2(Math.floor(next/60))}:${pad2(next%60)}`;
}
function nowHHMM(){
  const d=new Date();
  return `${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
}

/* ===== Main ===== */
(function(){
  const dateEl = document.getElementById('booking_date');
  const hh12El = document.getElementById('hh12');
  const mmEl   = document.getElementById('mm');
  const apEl   = document.getElementById('ampm');
  const startHidden = document.getElementById('start_time');
  const hoursEl = document.getElementById('hours');
  const endDisp = document.getElementById('end_time_display');
  const endHidden = document.getElementById('end_time');
  const startHelp = document.getElementById('startHelp');
  const endHelp = document.getElementById('endHelp');
  const submitBtn = document.getElementById('submitBtn');
  const open24 = document.getElementById('open_24').value;
  const close24= document.getElementById('close_24').value;
  const totalPriceEl = document.getElementById('total_price');
  const pricePerHour = <?php echo (float)$venue['PricePerHour']; ?>;

  (function forceLocalToday(){
    const d = new Date();
    const todayLocal = `${d.getFullYear()}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())}`;
    if (dateEl.min !== todayLocal) dateEl.min = todayLocal;
    if (dateEl.value !== todayLocal) dateEl.value = todayLocal;
  })();

  function buildStaticLists(){
    hh12El.innerHTML = '<option value="">--</option>';
    for (let i=1;i<=12;i++) {
      const v = pad2(i);
      const opt = document.createElement('option');
      opt.value=v; opt.textContent=v;
      hh12El.appendChild(opt);
    }
    mmEl.innerHTML = '<option value="">--</option>';
    ['00','30'].forEach(v=>{
      const opt=document.createElement('option');
      opt.value=v; opt.textContent=v;
      mmEl.appendChild(opt);
    });
  }

  function applyMinForToday(){
    const d = new Date();
    const todayStr = `${d.getFullYear()}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())}`;
    startHelp.textContent='';
    if (dateEl.value === todayStr){
      let minStart = roundUpTo30(nowHHMM());
      if (cmpTime(minStart, open24) < 0) minStart = open24;
      startHelp.textContent = `เวลาที่เร็วสุดวันนี้: ${to12(minStart)}`;
    }
  }

  function autoClampToAllowed(){
    if (!hh12El.value || !mmEl.value || !apEl.value) {
      startHidden.value=''; endDisp.value=''; endHidden.value=''; return;
    }
    let st24 = to24_from_parts(hh12El.value, mmEl.value, apEl.value);
    if (!st24){ return; }

    const todayStr = new Date().toISOString().slice(0,10);
    if (dateEl.value === todayStr){
      let minStart = roundUpTo30(nowHHMM());
      if (cmpTime(minStart, open24) < 0) minStart = open24;
      if (cmpTime(st24, minStart) < 0) {
        const twelve = to12(minStart);
        const [t,ap] = twelve.split(' '); const [H,M]=t.split(':');
        hh12El.value = H; mmEl.value = M; apEl.value = ap;
        st24 = minStart;
      }
    } else {
      if (cmpTime(st24, open24) < 0) st24 = open24;
    }

    if (cmpTime(st24, close24) > 0) {
      const twelve = to12(close24);
      const [t,ap]=twelve.split(' '); const [H,M]=t.split(':');
      hh12El.value=H; mmEl.value=M; apEl.value=ap;
      st24 = close24;
    }

    startHidden.value = st24;
    computeEnd();
  }

  function computeEnd(){
    endHelp.textContent=''; endHelp.classList.remove('error'); submitBtn.disabled=false;
    const st = startHidden.value;
    const hrs = parseFloat(hoursEl.value||'0');
    if (!st || !hrs || hrs<=0){ endDisp.value=''; endHidden.value=''; totalPriceEl.value=''; return; }
    const end24 = addMinutes(st, Math.round(hrs*60));
    endHidden.value = end24;
    endDisp.value = to12(end24);

    // ราคาเต็มเพื่อส่งไป confirm
    totalPriceEl.value = (hrs * pricePerHour).toFixed(2);

    if (cmpTime(end24, close24) > 0){
      endHelp.textContent='❌ เวลาเสร็จสิ้นเกินเวลาปิดสนาม โปรดปรับเวลาเริ่มหรือจำนวนชั่วโมง';
      endHelp.classList.add('error');
      submitBtn.disabled = true;
    }
  }

  buildStaticLists();
  applyMinForToday();

  (function setDefaultEarliest(){
    const d = new Date();
    const todayLocal = `${d.getFullYear()}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())}`;
    let earliest = open24;
    if (dateEl.value === todayLocal){
      let n = roundUpTo30(nowHHMM());
      if (cmpTime(n, earliest) > 0) earliest = n;
    }
    const twelve = to12(earliest);
    const [t,ap] = twelve.split(' '); const [H,M]=t.split(':');
    document.getElementById('hh12').value = H;
    document.getElementById('mm').value = M;
    document.getElementById('ampm').value = ap;
    document.getElementById('start_time').value = earliest;
    computeEnd();
  })();

  hh12El.addEventListener('change', autoClampToAllowed);
  mmEl.addEventListener('change', autoClampToAllowed);
  apEl.addEventListener('change', autoClampToAllowed);
  hoursEl.addEventListener('input', computeEnd);
  dateEl.addEventListener('change', ()=>{ applyMinForToday(); autoClampToAllowed(); });
})();
</script>
<script>
(function () {
  const form = document.getElementById('bookingForm');
  const startHidden = document.getElementById('start_time');
  const endHidden = document.getElementById('end_time');
  const submitBtn = document.getElementById('submitBtn');
  const dateEl = document.getElementById('booking_date');
  const startHelp = document.getElementById('startHelp');
  const endHelp = document.getElementById('endHelp');
  const open24 = document.getElementById('open_24').value;
  const close24= document.getElementById('close_24').value;

  function pad2(n){ return String(n).padStart(2,'0'); }
  function cmpTime(a,b){ return a===b?0:(a>b?1:-1); }
  function to12(hhmm){
    let [h,m]=hhmm.split(':').map(Number);
    const ap = h>=12?'PM':'AM';
    h = h%12; if (h===0) h=12;
    return `${pad2(h)}:${pad2(m)} ${ap}`;
  }
  function nowHHMM(){
    const d=new Date();
    return `${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
  }
  function roundUpTo30(hhmm){
    let [h,m]=hhmm.split(':').map(Number);
    const mins=h*60+m, add=(30-(mins%30))%30, next=mins+add;
    return `${pad2(Math.floor(next/60))}:${pad2(next%60)}`;
  }

  function guardTodayClosed() {
    const d = new Date();
    const today = `${d.getFullYear()}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())}`;
    if (dateEl.value === today) {
      const now = nowHHMM();
      if (cmpTime(now, close24) >= 0) {
        submitBtn.disabled = true;
        startHelp.innerHTML = `❌ วันนี้จองไม่ได้แล้ว (เวลาปิด ${to12(close24)}) — โปรดเลือกวันถัดไป`;
        return true;
      }
    }
    return false;
  }

  form.addEventListener('submit', function (e) {
    startHelp.classList.remove('error');
    endHelp.classList.remove('error');

    if (guardTodayClosed()) { e.preventDefault(); return; }

    if (!startHidden.value) {
      e.preventDefault();
      startHelp.textContent = '❌ กรุณาเลือกเวลาเริ่มให้ครบ';
      startHelp.classList.add('error');
      return;
    }

    if (!endHidden.value || (endHidden.value > document.getElementById('close_24').value)) {
      e.preventDefault();
      endHelp.textContent = '❌ เวลาเสร็จสิ้นเกินเวลาปิดสนาม โปรดปรับเวลาเริ่มหรือจำนวนชั่วโมง';
      endHelp.classList.add('error');
      return;
    }
  });

  guardTodayClosed();
})();
</script>

</body>
</html>
