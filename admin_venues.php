<?php
// admin_venues.php
// Admin page to create/edit venues, upload images, set maintenance status, and delete.

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// ✅ ตรวจสอบบทบาท (ต้องเป็นพนักงาน หรือ admin)
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'employee') {
    echo "<h2 style='color:red;text-align:center;margin-top:50px;'>❌ คุณไม่มีสิทธิ์เข้าถึงหน้านี้</h2>";
    exit;
}

include 'db_connect.php';

// Fetch venue types for dropdown
$types = [];
$typeSql = "SELECT VenueTypeID, TypeName FROM tbl_venue_type ORDER BY TypeName ASC";
if ($res = $conn->query($typeSql)) {
    while ($row = $res->fetch_assoc()) { $types[] = $row; }
    $res->free();
}

// If editing
$editing = false;
$editRow = null;
if (isset($_GET['id']) && ctype_digit($_GET['id'])) {
    $stmt = $conn->prepare("SELECT * FROM tbl_venue WHERE VenueID = ?");
    $stmt->bind_param("i", $_GET['id']);
    $stmt->execute();
    $editRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($editRow) $editing = true;
}

// Fetch all venues
$venues = [];
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = $conn->prepare("SELECT v.*, t.TypeName FROM tbl_venue v 
        JOIN tbl_venue_type t ON v.VenueTypeID = t.VenueTypeID
        WHERE v.VenueName LIKE ? OR t.TypeName LIKE ? OR v.Status LIKE ?
        ORDER BY v.VenueID DESC");
    $stmt->bind_param("sss", $like, $like, $like);
    $stmt->execute();
    $venues = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $sql = "SELECT v.*, t.TypeName FROM tbl_venue v 
            JOIN tbl_venue_type t ON v.VenueTypeID = t.VenueTypeID
            ORDER BY v.VenueID DESC";
    if ($res = $conn->query($sql)) {
        $venues = $res->fetch_all(MYSQLI_ASSOC);
        $res->free();
    }
}

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>จัดการสนาม (Admin)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.badge-status { text-transform: capitalize; }
img.thumb { width: 80px; height: 60px; object-fit: cover; border-radius: 6px; }
</style>
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">จัดการสนาม</h1>
        <a href="dashboard.php" class="btn btn-primary">กลับหน้า Dashboard</a>
    </div>

    <!-- Flash messages -->
    <?php if (!empty($_SESSION['flash_success'])): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= h($_SESSION['flash_success']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['flash_error'])): ?>
      <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= h($_SESSION['flash_error']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <!-- Search -->
    <form class="row g-2 mb-4" method="get" action="admin_venues.php">
        <div class="col-md-6">
            <input type="text" name="q" class="form-control" placeholder="ค้นหาตามชื่อ ประเภท หรือสถานะ..." value="<?= h($search) ?>">
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-primary w-100">ค้นหา</button>
        </div>
        <div class="col-md-3 text-md-end">
            <a href="admin_venues.php" class="btn btn-outline-dark w-100">ล้างตัวกรอง</a>
        </div>
    </form>

    <div class="row">
        <div class="col-lg-5">
            <div class="card mb-4">
                <div class="card-header"><?= $editing ? 'แก้ไขสนาม #' . (int)$editRow['VenueID'] : 'เพิ่มสนามใหม่' ?></div>
                <div class="card-body">
                    <form action="venue_save.php" method="post" enctype="multipart/form-data">
                        <?php if ($editing): ?>
                            <input type="hidden" name="VenueID" value="<?= (int)$editRow['VenueID'] ?>">
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label">ชื่อสนาม</label>
                            <input type="text" name="VenueName" class="form-control" required value="<?= h($editRow['VenueName'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">ประเภทสนาม</label>
                            <select name="VenueTypeID" class="form-select" required>
                                <option value="">-- เลือกประเภท --</option>
                                <?php foreach ($types as $t): ?>
                                    <option value="<?= (int)$t['VenueTypeID'] ?>" <?= ($editing && $editRow['VenueTypeID']==$t['VenueTypeID'])?'selected':'' ?>>
                                        <?= h($t['TypeName']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                          <div class="col-md-6 mb-3">
                              <label class="form-label">ราคา/ชั่วโมง (บาท)</label>
                              <input type="number" min="0" step="0.01" name="PricePerHour" class="form-control" required value="<?= h($editRow['PricePerHour'] ?? '') ?>">
                          </div>
                          <div class="col-md-3 mb-3">
                              <label class="form-label">เปิด</label>
                              <input type="time" name="TimeOpen" class="form-control" value="<?= h($editRow['TimeOpen'] ?? '') ?>">
                          </div>
                          <div class="col-md-3 mb-3">
                              <label class="form-label">ปิด</label>
                              <input type="time" name="TimeClose" class="form-control" value="<?= h($editRow['TimeClose'] ?? '') ?>">
                          </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">ที่อยู่</label>
                            <textarea name="Address" class="form-control" rows="2"><?= h($editRow['Address'] ?? '') ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">รายละเอียด</label>
                            <textarea name="Description" class="form-control" rows="3"><?= h($editRow['Description'] ?? '') ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">รูปภาพ (อัปโหลดใหม่เพื่อเปลี่ยน)</label>
                            <input type="file" name="ImageFile" accept="image/*" class="form-control">
                            <?php if ($editing && !empty($editRow['ImageURL'])): ?>
                                <div class="mt-2">
                                    <img src="<?= h($editRow['ImageURL']) ?>" class="thumb" alt="">
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">สถานะ</label>
                            <select name="Status" class="form-select">
                                <?php 
                                $statuses = ['available' => 'เปิดให้จอง', 'maintenance' => 'ปิดปรับปรุงชั่วคราว', 'closed' => 'ปิดถาวร'];
                                $cur = $editing ? ($editRow['Status'] ?? 'available') : 'available';
                                foreach ($statuses as $value => $label):
                                ?>
                                  <option value="<?= $value ?>" <?= ($cur === $value ? 'selected' : '') ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">เลือก "ปิดปรับปรุงชั่วคราว" เพื่อไม่ให้ลูกค้าจองได้</div>
                        </div>
                        <button class="btn btn-success w-100"><?= $editing ? 'บันทึกการแก้ไข' : 'เพิ่มสนาม' ?></button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">รายการสนามทั้งหมด</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                      <table class="table table-striped table-hover mb-0 align-middle">
                        <thead class="table-light">
                          <tr>
                            <th>#</th>
                            <th>รูป</th>
                            <th>ชื่อ</th>
                            <th>ประเภท</th>
                            <th>ราคา/ชม.</th>
                            <th>สถานะ</th>
                            <th class="text-end">การทำงาน</th>
                          </tr>
                        </thead>
                        <tbody>
<?php $i = count($venues); ?> <!-- เริ่มนับถอยหลังจากจำนวนทั้งหมด -->
<?php foreach ($venues as $v): ?>
  <tr>
    <td><?= $i-- ?></td>
    <td>
      <?php if (!empty($v['ImageURL'])): ?>
        <img class="thumb" src="<?= h($v['ImageURL']) ?>" alt="">
      <?php endif; ?>
    </td>
    <td><?= h($v['VenueName']) ?></td>
    <td><?= h($v['TypeName']) ?></td>
    <td><?= number_format((float)$v['PricePerHour'], 2) ?></td>
    <td>
      <?php 
        $map = ['available'=>'success', 'maintenance'=>'warning', 'closed'=>'secondary'];
        $label = ['available'=>'เปิดให้จอง', 'maintenance'=>'ปิดปรับปรุงชั่วคราว', 'closed'=>'ปิดถาวร'];
        $status = $v['Status'] ?? 'available';
      ?>
      <span class="badge bg-<?= $map[$status] ?? 'secondary' ?> badge-status"><?= $label[$status] ?? h($status) ?></span>
    </td>
    <td class="text-end">
        <a class="btn btn-sm btn-primary" href="admin_venues.php?id=<?= (int)$v['VenueID'] ?>">แก้ไข</a>

        <form action="venue_set_status.php" method="post" class="d-inline">
            <input type="hidden" name="VenueID" value="<?= (int)$v['VenueID'] ?>">
            <?php if (($v['Status'] ?? 'available') !== 'maintenance'): ?>
              <input type="hidden" name="Status" value="maintenance">
              <button class="btn btn-sm btn-warning">ตั้งเป็นปิดปรับปรุง</button>
            <?php else: ?>
              <input type="hidden" name="Status" value="available">
              <button class="btn btn-sm btn-success">ตั้งเป็นเปิดให้จอง</button>
            <?php endif; ?>
        </form>

        <!-- ✅ ปุ่มลบสนาม -->
        <form action="venue_delete.php" method="post" class="d-inline"
              onsubmit="return confirm('ยืนยันลบสนามนี้หรือไม่? การลบไม่สามารถกู้คืนได้');">
            <input type="hidden" name="VenueID" value="<?= (int)$v['VenueID'] ?>">
            <button class="btn btn-sm btn-outline-danger">ลบ</button>
        </form>
    </td>
  </tr>
<?php endforeach; ?>

<?php if (empty($venues)): ?>
  <tr><td colspan="7" class="text-center text-muted py-4">ไม่พบข้อมูล</td></tr>
<?php endif; ?>
                        </tbody>
                      </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
