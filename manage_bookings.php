<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'employee') {
    header("Location: login.php");
    exit;
}
include 'db_connect.php';

/* ใช้ employee_id จาก session (ถ้าไม่มีให้ fallback เป็น user_id) */
$employee_id = (int)($_SESSION['employee_id'] ?? $_SESSION['user_id']);

/* ดึงข้อมูลการจองทั้งหมด */
$sql = "SELECT 
            b.BookingID, c.FirstName, v.VenueName,
            b.StartTime, b.EndTime, b.TotalPrice,
            bs.StatusName AS BookingStatus,
            ps.StatusName AS PaymentStatus
        FROM Tbl_Booking b
        JOIN Tbl_Customer c ON b.CustomerID = c.CustomerID
        JOIN Tbl_Venue v ON b.VenueID = v.VenueID
        JOIN Tbl_Booking_Status bs ON b.BookingStatusID = bs.BookingStatusID
        JOIN Tbl_Payment_Status ps ON b.PaymentStatusID = ps.PaymentStatusID
        ORDER BY b.BookingID DESC";
$result = $conn->query($sql);

function badgeClassBooking($txt) {
  $txt = trim($txt);
  if (in_array($txt, ['ยืนยันแล้ว','อนุมัติ','สำเร็จ'])) return 'success';
  if (in_array($txt, ['รอยืนยัน','รออนุมัติ','รอดำเนินการ'])) return 'warning';
  if (in_array($txt, ['ยกเลิก','ยกเลิกโดยลูกค้า','ไม่สำเร็จ'])) return 'danger';
  return 'secondary';
}
function badgeClassPayment($txt) {
  $txt = trim($txt);
  if (in_array($txt, ['ชำระเงินสำเร็จ','ชำระแล้ว'])) return 'success';
  if (in_array($txt, ['รอชำระเงิน','รอดำเนินการ'])) return 'warning';
  return 'secondary';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>จัดการการจอง | CY Arena</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
:root{
  --bg:#f5f7fb; --card:#ffffff; --ink:#0f172a; --muted:#64748b;
  --line:#e2e8f0; --brand:#2563eb; --brand-2:#3b82f6;
  --success:#16a34a; --warning:#f59e0b; --danger:#ef4444; --secondary:#94a3b8;
}
*{box-sizing:border-box}
body{font-family:"Prompt",system-ui,-apple-system,Segoe UI,Roboto; background:var(--bg); margin:0; color:var(--ink)}
.header{
  background:#0f172a; color:#fff; padding:14px 22px; display:flex; justify-content:space-between; align-items:center;
}
.header .right a{color:#fff; text-decoration:none; background:var(--danger); padding:6px 12px; border-radius:8px; font-weight:600}
.container{
  max-width:1200px; margin:28px auto; background:var(--card); padding:22px; border-radius:16px;
  box-shadow:0 6px 24px rgba(2,6,23,.08);
}
.topbar{display:flex; flex-wrap:wrap; gap:10px; justify-content:space-between; align-items:center; margin-bottom:14px}
.title{font-size:20px; font-weight:700}
.back-btn{
  background:var(--brand-2); color:#fff; border:0; padding:10px 14px; border-radius:10px; text-decoration:none; font-weight:700;
}
.tools{display:flex; gap:10px; flex-wrap:wrap; margin-top:10px}
.input, .select{
  border:1px solid var(--line); background:#fff; padding:10px 12px; border-radius:10px; font-size:14px; min-width:180px; color:var(--ink);
}
.table-wrap{overflow:auto; border:1px solid var(--line); border-radius:12px}
table{width:100%; border-collapse:separate; border-spacing:0}
thead th{
  position:sticky; top:0; z-index:2; background:#f8fafc; color:#0f172a; text-align:left; padding:12px; font-weight:700; border-bottom:1px solid var(--line)
}
tbody td{padding:12px; border-bottom:1px solid var(--line); vertical-align:middle}
tbody tr:hover{background:#f9fbff}
.small{color:var(--muted); font-size:12px}
.badge{
  display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:999px; font-weight:700; font-size:12px; color:#fff
}
.badge.success{background:var(--success)}
.badge.warning{background:var(--warning)}
.badge.danger{background:var(--danger)}
.badge.secondary{background:var(--secondary)}
.actions{display:flex; gap:8px; flex-wrap:wrap}
.btn{
  border:0; padding:8px 10px; border-radius:10px; font-weight:700; color:#fff; cursor:pointer; text-decoration:none; display:inline-block
}
.btn-yes{background:var(--success)}
.btn-pay{background:#0ea5e9}
.btn-no{background:var(--danger)}
.btn-del{background:#111827}
td.price, td.time{white-space:nowrap}
.footer-note{margin-top:10px; color:var(--muted); font-size:13px}
@media (max-width: 840px){
  .input, .select{min-width:140px}
}
.inline{display:inline}
</style>
</head>
<body>

<header class="header">
  <div><strong>CY Arena Admin</strong></div>
  <div class="right">
    <span class="small" style="margin-right:10px">ผู้ดูแล: <?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?></span>
    <a href="logout.php">ออกจากระบบ</a>
  </div>
</header>

<div class="container">
  <div class="topbar">
    <div class="title">รายการจองทั้งหมด</div>
    <a href="dashboard.php" class="back-btn">⬅ กลับหน้า Dashboard</a>
  </div>

  <!-- แถบเครื่องมือ ค้นหา/กรอง -->
  <div class="tools">
    <input id="q" class="input" type="text" placeholder="🔎 ค้นหา: ลูกค้า / สนาม / เวลา">
    <select id="f-book" class="select">
      <option value="">สถานะการจอง: ทั้งหมด</option>
      <option>ยืนยันแล้ว</option>
      <option>รอยืนยัน</option>
      <option>ยกเลิก</option>
      <option>ยกเลิกโดยลูกค้า</option>
    </select>
    <select id="f-pay" class="select">
      <option value="">สถานะชำระเงิน: ทั้งหมด</option>
      <option>ชำระเงินสำเร็จ</option>
      <option>รอชำระเงิน</option>
    </select>
    <input id="f-date" class="input" type="date" title="กรองตามวันที่เริ่ม (เริ่มต้น)">
  </div>

  <div class="table-wrap" style="margin-top:12px">
    <table id="tbl">
      <thead>
        <tr>
          <th style="width:70px">รหัส</th>
          <th style="min-width:120px">ลูกค้า</th>
          <th style="min-width:220px">สนาม</th>
          <th style="min-width:160px">เริ่ม</th>
          <th style="min-width:100px">สิ้นสุด</th>
          <th style="min-width:110px">ราคา</th>
          <th style="min-width:150px">สถานะ</th>
          <th style="min-width:160px">การชำระเงิน</th>
          <th style="min-width:260px">จัดการ</th>
        </tr>
      </thead>
      <tbody>
        <?php while($row = $result->fetch_assoc()):
          $bCls = badgeClassBooking($row['BookingStatus']);
          $pCls = badgeClassPayment($row['PaymentStatus']);
          $dateKey = date('Y-m-d', strtotime($row['StartTime']));
        ?>
        <tr
          data-key="<?php echo htmlspecialchars(strtolower($row['BookingID'].' '.$row['FirstName'].' '.$row['VenueName'].' '.date('d/m H:i',strtotime($row['StartTime'])))); ?>"
          data-book="<?php echo htmlspecialchars($row['BookingStatus']); ?>"
          data-pay="<?php echo htmlspecialchars($row['PaymentStatus']); ?>"
          data-date="<?php echo $dateKey; ?>"
        >
          <td>#<?php echo (int)$row['BookingID']; ?></td>
          <td><?php echo htmlspecialchars($row['FirstName']); ?></td>
          <td><?php echo htmlspecialchars($row['VenueName']); ?></td>
          <td class="time"><?php echo date("d/m H:i", strtotime($row['StartTime'])); ?></td>
          <td class="time"><?php echo date("H:i", strtotime($row['EndTime'])); ?></td>
          <td class="price">฿<?php echo number_format((float)$row['TotalPrice'], 2); ?></td>
          <td><span class="badge <?php echo $bCls; ?>"><?php echo htmlspecialchars($row['BookingStatus']); ?></span></td>
          <td><span class="badge <?php echo $pCls; ?>"><?php echo htmlspecialchars($row['PaymentStatus']); ?></span></td>
          <td>
            <div class="actions">

              <!-- ยืนยัน (ไม่เปลี่ยนสถานะเงิน) + บันทึกพนักงาน -->
              <form class="inline" action="update_booking_status.php" method="POST">
                <input type="hidden" name="booking_id" value="<?php echo (int)$row['BookingID']; ?>">
                <input type="hidden" name="employee_id" value="<?php echo $employee_id; ?>">
                <input type="hidden" name="action" value="confirm">
                <button type="submit" class="btn btn-yes" title="ยืนยันการจอง (บันทึกผู้ยืนยัน)">ยืนยัน</button>
              </form>

              <!-- ยืนยัน + ชำระเงินสำเร็จ + บันทึกพนักงาน -->
              <form class="inline" action="update_booking_status.php" method="POST">
                <input type="hidden" name="booking_id" value="<?php echo (int)$row['BookingID']; ?>">
                <input type="hidden" name="employee_id" value="<?php echo $employee_id; ?>">
                <input type="hidden" name="action" value="confirm_paid">
                
              </form>

              <!-- ยกเลิก + บันทึกพนักงานผู้ดำเนินการ -->
              <form class="inline" action="update_booking_status.php" method="POST" onsubmit="return askCancel(<?php echo (int)$row['BookingID']; ?>)">
                <input type="hidden" name="booking_id" value="<?php echo (int)$row['BookingID']; ?>">
                <input type="hidden" name="employee_id" value="<?php echo $employee_id; ?>">
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="btn btn-no">ยกเลิก</button>
              </form>

              <!-- ลบถาวร -->
              <form class="inline" action="admin_delete_booking.php" method="POST" onsubmit="return askDel(this)">
                <input type="hidden" name="booking_id" value="<?php echo (int)$row['BookingID']; ?>">
                <button type="submit" class="btn btn-del">ลบ</button>
              </form>

            </div>
          </td>
        </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>

  <div class="footer-note">
    * ใช้ค้นหาด้วยคำสำคัญ (เช่น “สมศรี”, “Sport Hall”, “12:30”) และกรองตามสถานะหรือวันที่ได้ทันที<br>
    * ปุ่ม “ยืนยัน+ชำระเงิน” จะส่งค่า <strong>employee_id</strong> ไปบันทึกในบิลเพื่อให้รายงาน “ผลงานพนักงาน” อัปเดตได้
  </div>
</div>

<script>
const $ = sel => document.querySelector(sel);
const $$ = sel => Array.from(document.querySelectorAll(sel));

function askDel(form){
  const id = form.querySelector('input[name="booking_id"]')?.value || '';
  return confirm(`ยืนยันการลบรายการจอง #${id} ?\n(หากลบไม่ได้ ระบบจะเปลี่ยนสถานะเป็น "ยกเลิก")`);
}
function askCancel(id){
  return confirm(`ยืนยันยกเลิกรายการจอง #${id} ?`);
}

function filterRows(){
  const q = ($('#q').value || '').trim().toLowerCase();
  const fb = $('#f-book').value;
  const fp = $('#f-pay').value;
  const fd = $('#f-date').value; // yyyy-mm-dd

  $$('#tbl tbody tr').forEach(tr=>{
    const key = tr.getAttribute('data-key') || '';
    const b = tr.getAttribute('data-book') || '';
    const p = tr.getAttribute('data-pay') || '';
    const d = tr.getAttribute('data-date') || '';

    let show = true;
    if (q && !key.includes(q)) show = false;
    if (fb && b !== fb) show = false;
    if (fp && p !== fp) show = false;
    if (fd && d !== fd) show = false;

    tr.style.display = show ? '' : 'none';
  });
}

['#q','#f-book','#f-pay','#f-date'].forEach(sel=>{
  document.querySelector(sel).addEventListener('input', filterRows);
  document.querySelector(sel).addEventListener('change', filterRows);
});
</script>
</body>
</html>
