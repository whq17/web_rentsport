<?php
// bookings_calendar.php  (เวอร์ชันแสดงชื่อโปรไฟล์ลูกค้า + จัดกลุ่มตามประเภทสนาม)
session_start();
if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
  exit;
}
require 'db_connect.php';

$tz = new DateTimeZone('Asia/Bangkok');

// รับเดือน/ปี จาก query (ค่าเริ่มต้น = เดือนปัจจุบัน)
$year  = (isset($_GET['y']) && ctype_digit($_GET['y'])) ? (int)$_GET['y'] : (int)(new DateTime('now', $tz))->format('Y');
$month = (isset($_GET['m']) && ctype_digit($_GET['m'])) ? (int)$_GET['m'] : (int)(new DateTime('now', $tz))->format('n');

$firstDay = new DateTime("$year-$month-01 00:00:00", $tz);
$lastDay  = (clone $firstDay)->modify('last day of this month')->setTime(23,59,59);

// ดึงการจองของเดือนนี้ (ไม่นับยกเลิก/ไม่สำเร็จ -> 3,4 ปรับตามระบบคุณ)
// ✅ เพิ่ม JOIN Tbl_Venue_Type vt เพื่อดึงชื่อประเภทสนาม (TypeName)
// ✅ เพิ่ม LEFT JOIN Tbl_Customer เพื่อดึงชื่อโปรไฟล์ลูกค้า
$sql = "
  SELECT 
    b.BookingID,
    b.CustomerID,
    b.StartTime,
    b.EndTime,
    v.VenueName,
    vt.TypeName   AS VenueTypeName,
    c.FirstName   AS CustomerName
  FROM Tbl_Booking b
  JOIN Tbl_Venue        v  ON v.VenueID = b.VenueID
  JOIN Tbl_Venue_Type   vt ON vt.VenueTypeID = v.VenueTypeID
  LEFT JOIN Tbl_Customer c ON c.CustomerID = b.CustomerID
  WHERE b.BookingStatusID NOT IN (3,4)
    AND b.StartTime BETWEEN ? AND ?
  ORDER BY b.StartTime ASC
";
$stmt = $conn->prepare($sql);
$startStr = $firstDay->format('Y-m-d H:i:s');
$endStr   = $lastDay->format('Y-m-d H:i:s');
$stmt->bind_param('ss', $startStr, $endStr);
$stmt->execute();
$res = $stmt->get_result();

$bookingsByDate = []; // 'Y-m-d' => rows
while ($row = $res->fetch_assoc()) {
  // ทำ fallback ชื่อ หาก FirstName ว่าง
  $row['CustomerName'] = trim($row['CustomerName'] ?? '');
  if ($row['CustomerName'] === '') {
    $row['CustomerName'] = 'ลูกค้า #' . (int)$row['CustomerID'];
  }
  $key = (new DateTime($row['StartTime'], $tz))->format('Y-m-d');
  $bookingsByDate[$key][] = $row;
}
$stmt->close();
$conn->close();

$daysInMonth  = (int)$firstDay->format('t');
$startWeekday = (int)$firstDay->format('w'); // 0=อา
$todayKey     = (new DateTime('today', $tz))->format('Y-m-d');

// ปุ่มเลื่อนเดือน
$prev = (clone $firstDay)->modify('-1 month');
$next = (clone $firstDay)->modify('+1 month');

// เตรียม payload ให้ JS (เพิ่ม cust_name + type)
$payload = [];
foreach ($bookingsByDate as $d => $rows) {
  foreach ($rows as $r) {
    $payload[$d][] = [
      'venue'     => $r['VenueName'],
      'type'      => $r['VenueTypeName'] ?? 'อื่น ๆ',
      'start'     => (new DateTime($r['StartTime'], $tz))->format('H:i'),
      'end'       => (new DateTime($r['EndTime'], $tz))->format('H:i'),
      'cust'      => (int)$r['CustomerID'],
      'cust_name' => $r['CustomerName'],
    ];
  }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ปฏิทินการจอง - CY Arena</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{font-family:"Prompt",system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue","Noto Sans Thai",sans-serif;background:#f8fafc}
.page{max-width:1200px;margin:24px auto;padding:0 16px}
.header-bar{display:flex;gap:12px;align-items:center;justify-content:space-between;margin-bottom:16px}
.calendar-wrap{background:#fff;border-radius:14px;box-shadow:0 6px 16px rgba(0,0,0,.08);padding:18px 18px 26px}
.cal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.cal-header h3{margin:0;color:#0f172a}
.cal-nav{color:#2563eb;text-decoration:none;font-weight:600}
.cal-nav:hover{text-decoration:underline}
.calendar-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:10px}
.cal-dayname{font-weight:700;color:#475569;text-align:center}
.cal-cell{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;min-height:120px;padding:10px;display:flex;flex-direction:column}
.cal-cell.today{border-color:#3b82f6;box-shadow:0 0 0 2px rgba(59,130,246,.2) inset}
.cal-cell.empty{background:transparent;border:none}
.cal-date{font-weight:700;color:#0f172a}
.badge-book{background:#fee2e2;color:#b91c1c;border-radius:999px;padding:2px 8px;font-size:12px;font-weight:700;margin-top:4px;display:inline-block}
.cal-sample{list-style:none;margin:6px 0 0;padding:0;flex:1 1 auto;overflow:hidden}
.cal-sample li{font-size:13px;color:#334155;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cal-sample .time{color:#64748b;margin-left:4px}
.cal-sample .cust{color:#0ea5e9;margin-left:6px}
.cal-sample .more{color:#64748b;font-style:italic}
.cal-none{margin-top:8px;color:#94a3b8;font-size:13px}
.view-day{align-self:flex-start;margin-top:auto;background:#3b82f6;color:#fff;border:none;border-radius:8px;padding:6px 10px;cursor:pointer;font-weight:600}
.view-day:hover{background:#2563eb}
.cal-modal{position:fixed;inset:0;background:rgba(0,0,0,.25);display:none;align-items:center;justify-content:center;z-index:1000}
.cal-modal-card{width:min(720px,92vw);background:#fff;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.2);overflow:hidden}
.cal-modal-head{display:flex;justify-content:space-between;align-items:center;background:#f8fafc;border-bottom:1px solid #e2e8f0;padding:12px 16px}
.cal-modal-body{padding:16px;max-height:70vh;overflow:auto}
.cal-close{background:#ef4444;border:none;color:#fff;border-radius:8px;padding:6px 10px;cursor:pointer;font-weight:700}
.cal-close:hover{background:#dc2626}

/* ===== THEME สดใส ===== */
:root {
  --bg: #f5f7fb; --card: #ffffff; --ink: #0f172a; --muted: #64748b; --line: #e2e8f0;
  --brand: #2563eb; --brand-2: #3b82f6; --brand-3: #dbeafe; --accent: #60a5fa;
  --grad1: linear-gradient(90deg, #3b82f6 0%, #60a5fa 100%);
}
body{background:var(--bg);color:var(--ink)}
.calendar-wrap{background:var(--card);border-radius:16px;box-shadow:0 10px 28px rgba(37,99,235,.08);padding:20px;border-top:5px solid #60a5fa}
.cal-header h3{font-weight:600;background:var(--grad1);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.cal-nav{color:var(--brand);font-weight:600}.cal-nav:hover{color:var(--brand-2);text-decoration:underline}
.calendar-grid{gap:10px;border-top:4px solid #93c5fd;padding-top:10px;position:relative;background:linear-gradient(180deg,#f0f7ff 0%,#fff 100%);border-radius:12px}
.cal-dayname{font-weight:700;color:#1e3a8a;text-align:center;background:linear-gradient(90deg,#dbeafe 0%,#e0f2fe 100%);padding:10px 0;border-radius:8px;box-shadow:0 2px 4px rgba(37,99,235,.15);text-shadow:0 1px 0 rgba(255,255,255,.6)}
.cal-cell{background:#f9fbff;border:1px solid var(--line);border-radius:12px;padding:10px;transition:all .2s}
.cal-cell:hover{background:#eff6ff;box-shadow:0 6px 16px rgba(37,99,235,.15);transform:translateY(-2px)}
.cal-cell.today{border-color:#3b82f6;box-shadow:inset 0 0 0 2px #3b82f6,0 6px 18px rgba(37,99,235,.15);background:#e0f2fe}
.badge-book{background:linear-gradient(90deg,#60a5fa,#3b82f6);color:#fff;border-radius:20px;padding:3px 8px;font-size:12px;font-weight:600;display:inline-block;box-shadow:0 2px 5px rgba(37,99,235,.25)}
.view-day{margin-top:6px;background:var(--grad1);color:#fff;font-weight:600;border:none;border-radius:8px;padding:6px 12px;cursor:pointer;box-shadow:0 4px 8px rgba(59,130,246,.3);transition:all .2s}
.view-day:hover{background:linear-gradient(90deg,#1d4ed8,#2563eb);box-shadow:0 6px 12px rgba(59,130,246,.35)}
.cal-modal{background:rgba(15,23,42,.35)}
.cal-modal-card{background:#fff;border-radius:16px;box-shadow:0 10px 25px rgba(37,99,235,.25)}
.cal-modal-head{background:linear-gradient(90deg,#3b82f6 0%,#60a5fa 100%);color:#fff}
.cal-close{background:#ef4444}.cal-close:hover{background:#dc2626}

/* ===== กลุ่มตามประเภทสนามในโมดัล ===== */
.group-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;margin-bottom:10px}
.group-head{width:100%;display:flex;align-items:center;gap:10px;justify-content:space-between;background:#f8fafc;border:0;padding:10px 12px;cursor:pointer}
.group-left{display:flex;align-items:center;gap:10px}
.dot{width:10px;height:10px;border-radius:50%}
.gtitle{font-weight:700;color:#0f172a}
.gcount{padding:2px 8px;border-radius:999px;color:#fff;font-weight:700;font-size:12px}
.chev{color:#64748b;transition:transform .2s}
.group-head.collapsed .chev{transform:rotate(-90deg)}
.group-body{padding:10px;border-top:1px solid #e5e7eb}
.cal-book{border:1px solid #e2e8f0;border-radius:10px;padding:10px;margin-bottom:10px}
.cal-book h5{margin:0 0 6px;color:#0f172a}
.cal-book .meta{font-size:13px;color:#475569}
.empty-note{background:#fff;border:1px dashed #e5e7eb;padding:16px;border-radius:12px;color:#64748b;text-align:center}
</style>
</head>
<body>
<div class="page">
  <div class="header-bar">
    <a href="dashboard.php" class="btn btn-outline-secondary">← กลับหน้า Dashboard</a>
    <h2 class="m-0">📅 ปฏิทินการจอง</h2>
    <div></div>
  </div>

  <section class="calendar-wrap">
    <div class="cal-header">
      <a class="cal-nav" href="?y=<?= $prev->format('Y') ?>&m=<?= $prev->format('n') ?>">&laquo; เดือนก่อน</a>
      <h3><?= $firstDay->format('F Y') ?></h3>
      <a class="cal-nav" href="?y=<?= $next->format('Y') ?>&m=<?= $next->format('n') ?>">เดือนถัดไป &raquo;</a>
    </div>

    <div class="calendar-grid">
      <div class="cal-dayname">อา</div><div class="cal-dayname">จ</div><div class="cal-dayname">อ</div>
      <div class="cal-dayname">พ</div><div class="cal-dayname">พฤ</div><div class="cal-dayname">ศ</div><div class="cal-dayname">ส</div>

      <?php for ($i=0; $i<$startWeekday; $i++): ?>
        <div class="cal-cell empty"></div>
      <?php endfor; ?>

      <?php for ($d=1; $d <= $daysInMonth; $d++):
        $dateKey = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $isToday = ($dateKey === $todayKey);
        $has     = !empty($bookingsByDate[$dateKey]);
        $count   = $has ? count($bookingsByDate[$dateKey]) : 0;
      ?>
      <div class="cal-cell<?= $isToday ? ' today':'' ?>">
        <div class="cal-date"><?= $d ?></div>
        <?php if ($has): ?>
          <span class="badge-book"><?= $count ?> รายการ</span>
          <ul class="cal-sample">
            <?php $i=0; foreach ($bookingsByDate[$dateKey] as $bk): if ($i>=3){ ?>
              <li class="more">...ดูทั้งหมด</li>
              <?php break; } 
              $custName = htmlspecialchars(trim($bk['CustomerName']) ?: ('ลูกค้า #'.(int)$bk['CustomerID']));
            ?>
              <li>
                <?= htmlspecialchars($bk['VenueName']) ?>
                <span class="time">
                  <?= (new DateTime($bk['StartTime'], $tz))->format('H:i') ?>-
                  <?= (new DateTime($bk['EndTime'], $tz))->format('H:i') ?>
                </span>
                <span class="cust"><?= $custName ?></span>
              </li>
            <?php $i++; endforeach; ?>
          </ul>
          <button class="view-day" data-date="<?= $dateKey ?>">ดูรายละเอียด</button>
        <?php else: ?>
          <div class="cal-none">ไม่มีการจอง</div>
        <?php endif; ?>
      </div>
      <?php endfor; ?>
    </div>
  </section>
</div>

<!-- Modal -->
<div id="dayModal" class="cal-modal">
  <div class="cal-modal-card">
    <div class="cal-modal-head">
      <h4 id="modalTitle" class="m-0">การจอง</h4>
      <button id="modalClose" class="cal-close">&times;</button>
    </div>
    <div id="modalBody" class="cal-modal-body"></div>
  </div>
</div>

<script>
(function(){
  const payload = <?= json_encode($payload, JSON_UNESCAPED_UNICODE) ?>;

  const modal   = document.getElementById('dayModal');
  const mTitle  = document.getElementById('modalTitle');
  const mBody   = document.getElementById('modalBody');
  const mClose  = document.getElementById('modalClose');

  // สีประจำประเภท (เพิ่ม/แก้ชื่อได้)
  const typeColors = {
    'ฟุตบอล':   '#2563eb',
    'ฟุตซอล':   '#16a34a',
    'บาสเกตบอล':'#7c3aed',
    'แบดมินตัน':'#f59e0b',
    'ปิงปอง':   '#ef4444'
  };
  const defaultColor = '#64748b';

  function openDay(dateStr){
    const items = payload[dateStr] || [];
    mTitle.textContent = `การจองวันที่ ${dateStr}`;

    if (!items.length) {
      mBody.innerHTML = '<div class="empty-note">ไม่มีการจอง</div>';
      modal.style.display = 'flex';
      return;
    }

    // จัดกลุ่มตามประเภทสนาม
    const groups = {};
    items.forEach(it => {
      const t = it.type || 'อื่น ๆ';
      if (!groups[t]) groups[t] = [];
      groups[t].push(it);
    });

    // เรนเดอร์กลุ่มแบบ accordion
    let html = '';
    Object.keys(groups).sort().forEach((typeName, gi) => {
      const color = typeColors[typeName] || defaultColor;
      const list  = groups[typeName];
      const gid   = 'grp_' + gi + '_' + dateStr.replaceAll('-','');

      html += `
        <div class="group-card">
          <button class="group-head" data-target="#${gid}">
            <div class="group-left">
              <span class="dot" style="background:${color}"></span>
              <span class="gtitle">${escapeHtml(typeName)}</span>
            </div>
            <div class="group-right">
              <span class="gcount" style="background:${color}">${list.length}</span>
              <svg class="chev" width="18" height="18" viewBox="0 0 24 24" fill="none">
                <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
              </svg>
            </div>
          </button>
          <div class="group-body" id="${gid}">
      `;

      list.forEach((it, idx) => {
        html += `
          <div class="cal-book">
            <h5>${idx+1}. ${escapeHtml(it.venue)}</h5>
            <div class="meta">เวลา: ${it.start} - ${it.end}</div>
            <div class="meta">ผู้จอง: ${escapeHtml(it.cust_name || ('ลูกค้า #' + (it.cust ?? '')))}</div>
          </div>
        `;
      });

      html += `</div></div>`;
    });

    mBody.innerHTML = html;
    // ติด toggle ให้หัวข้อ
    mBody.querySelectorAll('.group-head').forEach(btn=>{
      const sel = btn.getAttribute('data-target');
      const body = mBody.querySelector(sel);
      const chev = btn.querySelector('.chev');
      // เปิดเริ่มต้น
      body.style.display = '';
      btn.classList.remove('collapsed');
      // toggle
      btn.addEventListener('click', ()=>{
        const shown = body.style.display !== 'none';
        if (shown) {
          body.style.display = 'none';
          btn.classList.add('collapsed');
        } else {
          body.style.display = '';
          btn.classList.remove('collapsed');
        }
      });
    });

    modal.style.display = 'flex';
  }

  function closeModal(){ modal.style.display='none'; }
  function escapeHtml(s){ return String(s).replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }

  document.querySelectorAll('.view-day').forEach(btn=>{
    btn.addEventListener('click', ()=> openDay(btn.dataset.date));
  });
  mClose.addEventListener('click', closeModal);
  modal.addEventListener('click', (e)=>{ if(e.target===modal) closeModal(); });
})();
</script>
</body>
</html>
