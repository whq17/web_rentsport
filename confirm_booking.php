<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require 'db_connect.php';

/* --------------------------- UI Helper (One-file) --------------------------- */
function render_booking_result_ui(array $opt = []) {
    $status        = $opt['status']        ?? 'error';
    $title         = $opt['title']         ?? (($status==='success') ? 'จองสำเร็จ' : 'จองไม่สำเร็จ');
    $message       = $opt['message']       ?? '';
    $conflicts     = $opt['conflicts']     ?? [];
    $back_url      = $opt['back_url']      ?? 'booking.php';
    $calendar_url  = $opt['calendar_url']  ?? null;
    $dashboard_url = $opt['dashboard_url'] ?? 'dashboard.php';
    ?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>สถานะการจอง</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    :root{ --ok:#16a34a; --bad:#ef4444; --ink:#111827; --muted:#6b7280; --bg:#f8fafc; --card:#fff; }
    html,body{ background:var(--bg); color:var(--ink); font-family:"Kanit",system-ui,-apple-system,Segoe UI,Roboto,"Helvetica Neue",Arial }
    .container-narrow{ max-width: 820px; margin:auto; padding: 28px 16px; }
    .panel{ background:var(--card); border:1px solid #eef2ff; border-radius:18px; padding:22px; box-shadow:0 18px 35px rgba(15,23,42,.06); }
    .icon-wrap{ width:58px; height:58px; border-radius:50%; display:flex; align-items:center; justify-content:center; }
    .icon-ok{ background:rgba(22,163,74,.12); color:#059669; border:1px solid rgba(22,163,74,.2); }
    .icon-bad{ background:rgba(239,68,68,.12); color:#dc2626; border:1px solid rgba(239,68,68,.2); }
    .title{ font-weight:600; font-size:1.4rem; margin-bottom:4px }
    .sub{ color:var(--muted) }
    .table thead th{ background:#f3f4f6; }
    .btn-pill{ border-radius:999px; }
  </style>
</head>
<body>
  <div class="container-narrow">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h1 class="h4 m-0">สถานะการจอง</h1>
    </div>

    <div class="panel">
      <div class="d-flex align-items-start gap-3">
        <div class="icon-wrap <?= ($status==='success'?'icon-ok':'icon-bad') ?>">
          <?php if ($status==='success'): ?>
            <svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
          <?php else: ?>
            <svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor"><path d="M18.3 5.71 12 12l6.3 6.29-1.41 1.42L10.59 13.4 4.3 19.71 2.89 18.3 9.17 12 2.89 5.71 4.3 4.29l6.29 6.3 6.29-6.3z"/></svg>
          <?php endif; ?>
        </div>
        <div class="flex-grow-1">
          <div class="title"><?= htmlspecialchars($title) ?></div>
          <div class="sub"><?= nl2br(htmlspecialchars($message)) ?></div>

          <?php if (!empty($conflicts) && is_array($conflicts)): ?>
            <div class="mt-3">
              <div class="fw-semibold mb-1">รายละเอียดเวลาที่ชน</div>
              <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                  <thead><tr><th>วันที่</th><th>เริ่ม</th><th>สิ้นสุด</th><th>สนาม</th></tr></thead>
                  <tbody>
                    <?php foreach ($conflicts as $c): ?>
                      <tr>
                        <td><?= htmlspecialchars($c['date'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($c['start'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($c['end'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($c['venue'] ?? '-') ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>

          <div class="d-flex flex-wrap gap-2 mt-3">
            <?php if ($back_url): ?>
              <a href="<?= htmlspecialchars($back_url) ?>" class="btn btn-primary btn-pill">ลองเลือกเวลาใหม่</a>
            <?php endif; ?>
            <?php if ($calendar_url): ?>
              <a href="<?= htmlspecialchars($calendar_url) ?>" class="btn btn-outline-secondary btn-pill">ดูปฏิทินสนาม</a>
            <?php endif; ?>
            <a href="<?= htmlspecialchars($dashboard_url) ?>" class="btn btn-light border btn-pill">ไปหน้า Dashboard</a>
          </div>
        </div>
      </div>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
    exit;
}
/* ------------------------- End UI Helper (One-file) ------------------------- */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    render_booking_result_ui([
        'status'  => 'error',
        'title'   => 'Access Denied',
        'message' => 'ไม่มีข้อมูลที่ส่งมา',
        'back_url'=> 'booking.php'
    ]);
}

$tz = new DateTimeZone('Asia/Bangkok');

/* ---------- รับค่าฟอร์ม (ยืดหยุ่นชื่อ field) ---------- */
function pick($arr, $keys, $default=null){
  foreach ($keys as $k){ if (isset($arr[$k]) && $arr[$k] !== '') return $arr[$k]; }
  return $default;
}

$venue_id     = (int) pick($_POST, ['venue_id','VenueID'], 0);
$booking_date = trim(pick($_POST, ['booking_date','date','BookingDate'], ''));
$start_time   = trim(pick($_POST, ['start_time','time_start','StartTime'], ''));
$hours        = (float) pick($_POST, ['hours','duration','HoursBooked'], 0);
$total_price  = (float) pick($_POST, ['total_price','TotalPrice','price'], 0);
$customer_id  = (int) $_SESSION['user_id'];
$promotion_id = pick($_POST, ['promotion_id','PromotionID'], null);
$promotion_id = ($promotion_id === null || $promotion_id === '') ? null : (int)$promotion_id;
$promo_code   = trim(pick($_POST, ['promo_code','PromoCode'], ''));

$back = 'booking.php' . ($venue_id ? ('?venue_id='.$venue_id) : '');

/* เช็คความครบ */
$missing = [];
if ($venue_id <= 0)       $missing[] = 'รหัสสนาม';
if ($booking_date === '') $missing[] = 'วันที่จอง';
if ($start_time === '')   $missing[] = 'เวลาเริ่ม';
if ($hours <= 0)          $missing[] = 'จำนวนชั่วโมง';
if ($missing){
    render_booking_result_ui([
        'status'=>'error',
        'title'=>'ข้อมูลไม่ครบ',
        'message'=>"กรุณากลับไปกรอกให้ครบ:\n• ".implode("\n• ", $missing),
        'back_url'=>$back
    ]);
}

/* ============================ สนาม + เวลาทำการ (ไม่มีคอลัมน์ Status) ============================ */
$st = $conn->prepare("
  SELECT VenueName, TimeOpen, TimeClose, PricePerHour
  FROM Tbl_Venue
  WHERE VenueID = ?
  LIMIT 1
");
$st->bind_param("i", $venue_id);
$st->execute();
$venueRow = $st->get_result()->fetch_assoc();
$st->close();

if (!$venueRow) {
    render_booking_result_ui([
        'status'=>'error','title'=>'ไม่พบข้อมูลสนาม',
        'message'=>'กรุณาลองใหม่อีกครั้งหรือเลือกสนามอื่น',
        'back_url'=>'booking.php'
    ]);
}

/* ===================== แปลงวัน/เวลา + ไม่ย้อนหลัง ===================== */
$selDate = DateTime::createFromFormat('!Y-m-d', $booking_date, $tz);
if (!$selDate) {
    render_booking_result_ui([
        'status'=>'error','title'=>'รูปแบบวันที่ไม่ถูกต้อง',
        'message'=>'โปรดระบุวันที่ในรูปแบบ YYYY-MM-DD',
        'back_url'=>$back
    ]);
}

$startDT = DateTime::createFromFormat('Y-m-d H:i', $selDate->format('Y-m-d').' '.$start_time, $tz);
if (!$startDT) {
    render_booking_result_ui([
        'status'=>'error','title'=>'เวลาเริ่มไม่ถูกต้อง',
        'message'=>'โปรดเลือกเวลาเริ่มใหม่',
        'back_url'=>$back
    ]);
}

$now   = new DateTime('now', $tz);
$today = new DateTime('today', $tz);
if ($startDT < $today) {
    render_booking_result_ui([
        'status'=>'error','title'=>'วันที่ย้อนหลัง',
        'message'=>'วันที่จองต้องไม่น้อยกว่าวันนี้',
        'back_url'=>$back
    ]);
}
if ($startDT->format('Y-m-d') === $now->format('Y-m-d')) {
    $minStart = (clone $now)->modify('+30 minutes');
    if ($startDT < $minStart) {
        render_booking_result_ui([
            'status'=>'error','title'=>'เวลายังเร็วเกินไป',
            'message'=>'เวลาจองวันนี้ต้องเริ่มล่วงหน้าอย่างน้อย 30 นาที',
            'back_url'=>$back
        ]);
    }
}

/* เวลาสิ้นสุด > เริ่ม */
$endDT = clone $startDT;
$endDT->modify("+{$hours} hours");
if ($endDT <= $startDT) {
    render_booking_result_ui([
        'status'=>'error','title'=>'เวลาสิ้นสุดไม่ถูกต้อง',
        'message'=>'เวลาสิ้นสุดต้องมากกว่าเวลาเริ่ม',
        'back_url'=>$back
    ]);
}

/* ================== อยู่ในช่วงเวลาเปิด–ปิดสนาม ================== */
$openDT  = DateTime::createFromFormat('Y-m-d H:i:s', $selDate->format('Y-m-d').' '.$venueRow['TimeOpen'],  $tz);
$closeDT = DateTime::createFromFormat('Y-m-d H:i:s', $selDate->format('Y-m-d').' '.$venueRow['TimeClose'], $tz);
if (!$openDT || !$closeDT) {
    render_booking_result_ui([
        'status'=>'error','title'=>'เวลาเปิด–ปิดไม่ถูกต้อง',
        'message'=>'ข้อมูลเวลาเปิด–ปิดของสนามผิดรูปแบบ',
        'back_url'=>$back
    ]);
}
if ($startDT < $openDT || $endDT > $closeDT) {
    render_booking_result_ui([
        'status'=>'error','title'=>'นอกเวลาให้บริการ',
        'message'=>'เวลาที่เลือกอยู่นอกช่วงเวลาเปิดให้บริการของสนาม',
        'back_url'=>$back,
        'calendar_url'=>'bookings_calendar.php?venue_id='.$venue_id
    ]);
}

/* ============ กันชนสนามเดียวกัน ============ */
$endStr   = $endDT->format('Y-m-d H:i:s');
$startStr = $startDT->format('Y-m-d H:i:s');

$conflictRows = [];
$chkVenue = $conn->prepare("
  SELECT StartTime, EndTime
  FROM Tbl_Booking
  WHERE VenueID = ?
    AND BookingStatusID NOT IN (3,4)
    AND NOT ( ? <= StartTime OR ? >= EndTime )
  ORDER BY StartTime
  LIMIT 5
");
$chkVenue->bind_param("iss", $venue_id, $endStr, $startStr);
$chkVenue->execute();
$r = $chkVenue->get_result();
$venueOverlap = $r->num_rows > 0;
if ($venueOverlap) {
    while ($row = $r->fetch_assoc()) {
        $stO = new DateTime($row['StartTime'], $tz);
        $enO = new DateTime($row['EndTime'],   $tz);
        $conflictRows[] = [
            'date'  => $stO->format('Y-m-d'),
            'start' => $stO->format('H:i'),
            'end'   => $enO->format('H:i'),
            'venue' => $venueRow['VenueName'] ?? 'สนามนี้'
        ];
    }
}
$chkVenue->close();

if ($venueOverlap) {
    render_booking_result_ui([
        'status'   => 'error',
        'title'    => 'ช่วงเวลานี้ถูกจองแล้ว',
        'message'  => "ไม่อนุญาตให้จองทับเวลา กรุณาเลือกช่วงเวลาใหม่หรือตรวจสอบปฏิทินสนาม",
        'conflicts'=> $conflictRows,
        'back_url' => $back,
        'calendar_url' => 'bookings_calendar.php?venue_id='.$venue_id
    ]);
}

/* ============ กันชนของลูกค้า (สนามเดียวกัน) ============ */
$conflictRows = [];
$chkCust = $conn->prepare("
  SELECT b.StartTime, b.EndTime, v.VenueName
  FROM Tbl_Booking b
  JOIN Tbl_Venue v ON v.VenueID = b.VenueID
  WHERE b.CustomerID = ?
    AND b.VenueID = ?
    AND b.BookingStatusID NOT IN (3,4)
    AND NOT ( ? <= b.StartTime OR ? >= b.EndTime )
  ORDER BY b.StartTime
  LIMIT 5
");
$chkCust->bind_param("iiss", $customer_id, $venue_id, $endStr, $startStr);
$chkCust->execute();
$rc = $chkCust->get_result();
$custOverlap = $rc->num_rows > 0;
if ($custOverlap) {
    while ($row = $rc->fetch_assoc()) {
        $stO = new DateTime($row['StartTime'], $tz);
        $enO = new DateTime($row['EndTime'],   $tz);
        $conflictRows[] = [
            'date'  => $stO->format('Y-m-d'),
            'start' => $stO->format('H:i'),
            'end'   => $enO->format('H:i'),
            'venue' => $row['VenueName'] ?? '-'
        ];
    }
}
$chkCust->close();

if ($custOverlap) {
    render_booking_result_ui([
        'status'   => 'error',
        'title'    => 'คุณมีการจองช่วงเวลานี้อยู่แล้ว (สนามเดียวกัน)',
        'message'  => 'ไม่สามารถจองทับเวลากับการจองเดิมในสนามเดียวกันได้',
        'conflicts'=> $conflictRows,
        'back_url' => $back,
        'calendar_url' => 'bookings_calendar.php?venue_id='.$venue_id
    ]);
}

/* ============================ ราคา + โปรโมชัน ============================ */
if ($total_price <= 0) {
    $total_price = ((float)$venueRow['PricePerHour']) * $hours;
}

/* แก้เงื่อนไข: เอา Status ออก เพราะตารางโปรโมชันของคุณไม่มีคอลัมน์นี้ */
if (!$promotion_id && $promo_code !== '') {
    $q = $conn->prepare("
      SELECT PromotionID
      FROM Tbl_Promotion
      WHERE PromoCode = ?
        AND (StartDate IS NULL OR NOW() >= StartDate)
        AND (EndDate   IS NULL OR NOW() <= EndDate)
      LIMIT 1
    ");
    $q->bind_param("s", $promo_code);
    $q->execute();
    $rowPromo = $q->get_result()->fetch_assoc();
    $promotion_id = $rowPromo['PromotionID'] ?? null;
    $q->close();
}

$netPrice = $total_price;
if ($promotion_id) {
    /* ใช้ SELECT * เผื่อคอลัมน์ส่วนลดในสคีมาของคุณชื่อไม่ตรง */
    $pq = $conn->prepare("SELECT * FROM Tbl_Promotion WHERE PromotionID = ? LIMIT 1");
    $pq->bind_param("i", $promotion_id);
    if ($pq->execute()) {
        $prow = $pq->get_result()->fetch_assoc();
        if ($prow) {
            $discPercent = isset($prow['DiscountPercent']) ? (float)$prow['DiscountPercent'] : null;
            $discAmount  = isset($prow['DiscountAmount'])  ? (float)$prow['DiscountAmount']  : null;

            if ($discPercent !== null && $discPercent > 0) {
                $netPrice = max(0, $total_price * (1 - ($discPercent/100)));
            } elseif ($discAmount !== null && $discAmount > 0) {
                $netPrice = max(0, $total_price - $discAmount);
            }
        }
    }
    $pq->close();
}

/* ============================ INSERT ============================ */
$booking_status_id = 1; // รอยืนยัน
$payment_status_id = 1; // รอชำระเงิน

$ins = $conn->prepare("
  INSERT INTO Tbl_Booking
  (CustomerID, VenueID, BookingStatusID, PaymentStatusID, StartTime, EndTime, HoursBooked, TotalPrice, NetPrice, PromotionID)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$startDB = $startDT->format('Y-m-d H:i:s');
$endDB   = $endDT->format('Y-m-d H:i:s');

$ins->bind_param(
  "iiiissdddi",
  $customer_id,
  $venue_id,
  $booking_status_id,
  $payment_status_id,
  $startDB,
  $endDB,
  $hours,
  $total_price,
  $netPrice,
  $promotion_id
);

if ($ins->execute()) {
    $venueName = $venueRow['VenueName'] ?? '-';
    $dateText  = (new DateTime($startDB, $tz))->format('Y-m-d');
    $timeText  = (new DateTime($startDB, $tz))->format('H:i') . ' - ' . (new DateTime($endDB, $tz))->format('H:i');
    render_booking_result_ui([
        'status'  => 'success',
        'title'   => 'จองสำเร็จแล้ว 🎉',
        'message' => "สนาม: {$venueName}\nวันที่: {$dateText}\nเวลา: {$timeText}\nยอดชำระ (หลังส่วนลด): ฿".number_format($netPrice,2),
        'back_url'=> 'my_bookings.php',
        'calendar_url' => 'bookings_calendar.php?venue_id='.$venue_id
    ]);
} else {
    render_booking_result_ui([
        'status'  => 'error',
        'title'   => 'บันทึกการจองไม่สำเร็จ',
        'message' => 'สาเหตุทางเทคนิค: '.$conn->error,
        'back_url'=> $back
    ]);
}
$ins->close();
$conn->close();
