<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

include 'db_connect.php';

// ตรวจสอบว่า venue_id ถูกต้องไหม
if (!isset($_GET['venue_id']) || !is_numeric($_GET['venue_id'])) {
    die("❌ ไม่พบรหัสสนามที่ถูกต้อง");
}

$venue_id = (int)$_GET['venue_id'];

// ✅ ดึงข้อมูลสนาม
$sql = "SELECT v.*, vt.TypeName
        FROM Tbl_Venue v
        JOIN Tbl_Venue_Type vt ON v.VenueTypeID = vt.VenueTypeID
        WHERE v.VenueID = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $venue_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows == 0) {
    die("❌ ไม่พบข้อมูลสนามนี้");
}
$venue = $result->fetch_assoc();

// ✅ ตรวจสอบว่ามี CreatedAt หรือไม่
$check_col = $conn->query("SHOW COLUMNS FROM Tbl_Review LIKE 'CreatedAt'");
$has_created_at = ($check_col && $check_col->num_rows > 0);

// ✅ ดึงข้อมูลรีวิวของสนาม
if ($has_created_at) {
    $sql_reviews = "SELECT r.*, c.FirstName
                    FROM Tbl_Review r
                    JOIN Tbl_Customer c ON r.CustomerID = c.CustomerID
                    WHERE r.VenueID = ?
                    ORDER BY r.CreatedAt DESC";
} else {
    $sql_reviews = "SELECT r.*, c.FirstName
                    FROM Tbl_Review r
                    JOIN Tbl_Customer c ON r.CustomerID = c.CustomerID
                    WHERE r.VenueID = ?
                    ORDER BY r.ReviewID DESC";
}

$stmt = $conn->prepare($sql_reviews);
$stmt->bind_param("i", $venue_id);
$stmt->execute();
$reviews = $stmt->get_result();

$conn->close();

// helper ตัดข้อความภาษาไทยให้สั้นสวย ๆ
function th_trim($text, $width = 50) {
    $text = trim($text ?? '');
    if ($text === '') return '';
    return function_exists('mb_strimwidth')
        ? mb_strimwidth($text, 0, $width, '…', 'UTF-8')
        : substr($text, 0, $width);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>รายละเอียดสนาม - <?php echo htmlspecialchars($venue['VenueName']); ?></title>
<style>
body {
  font-family: "Prompt", sans-serif;
  margin: 0;
  background: #f9fafb;
  color: #1e293b;
}
.container {
  max-width: 1000px;
  margin: 40px auto;
  background: #fff;
  border-radius: 14px;
  box-shadow: 0 4px 15px rgba(0,0,0,0.1);
  overflow: hidden;
}
img {
  width: 100%;
  height: 400px;
  object-fit: cover;
  border-bottom: 1px solid #e2e8f0;
}
.content { padding: 25px 35px; line-height: 1.8; }
h1 { margin: 0 0 10px; color: #0f172a; }
.type { color: #64748b; font-size: 15px; margin-bottom: 15px; }
.price { color: #0ea5e9; font-size: 18px; font-weight: bold; margin-bottom: 20px; }
.description { font-size: 15px; line-height: 1.8; color: #475569; margin-bottom: 12px; }

/* ➕ แถบแสดงที่อยู่สั้น */
.info-row{
  display:flex; align-items:center; gap:.6rem;
  background:#f8fafc; border:1px solid #e5e7eb;
  padding:.65rem 1rem; border-radius:.75rem; margin:12px 0 18px;
}
.info-icon{font-size:1.1rem}
.address-text{flex:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis}

/* ปุ่ม */
.btn-area { display:flex; flex-wrap:wrap; justify-content:flex-start; gap:12px; margin-top: 6px; }
.btn { background:#3b82f6; color:#fff; padding:10px 16px; border-radius:8px; text-decoration:none; font-weight:600; transition:.25s; }
.btn:hover { background:#2563eb; }
.btn-back { background:#94a3b8; } .btn-back:hover { background:#64748b; }
.btn-review { background:#f59e0b; } .btn-review:hover { background:#d97706; }

/* รีวิว */
.review-section { border-top:1px solid #e2e8f0; padding:25px 35px; background:#fdfdfd; }
.review-item { margin-bottom:20px; padding-bottom:15px; border-bottom:1px solid #e5e7eb; }
.review-item strong{ color:#0f172a; }
.review-item .rating{ color:#f59e0b; font-weight:600; }
.review-item .date{ color:#94a3b8; font-size:13px; margin-top:3px; }
.review-item p{ margin-top:5px; line-height:1.6; }
.no-review{ color:#94a3b8; font-style:italic; }

/* ➕ ส่วนที่อยู่เต็มด้านล่าง */
.address-section{ border-top:1px solid #e2e8f0; padding:22px 35px; background:#fff; }
.address-title{ margin:0 0 8px; font-size:18px; }
.address-full{ margin:0; color:#334155; line-height:1.9; }
</style>
</head>
<body>

<div class="container">
  <img src="<?php echo htmlspecialchars($venue['ImageURL'] ?: 'https://via.placeholder.com/1000x400.png?text=' . urlencode($venue['VenueName'])); ?>" alt="">

  <div class="content">
    <h1><?php echo htmlspecialchars($venue['VenueName']); ?></h1>
    <p class="type"><?php echo htmlspecialchars($venue['TypeName']); ?></p>
    <p class="price">💵 ฿<?php echo number_format($venue['PricePerHour'], 2); ?> / ชม.</p>
    <p class="description"><?php echo nl2br(htmlspecialchars($venue['Description'] ?? 'ไม่มีคำอธิบายเพิ่มเติม')); ?></p>

    <!-- ปุ่มอยู่ฝั่งซ้าย -->
    <div class="btn-area">
      <a href="booking.php?venue_id=<?php echo $venue['VenueID']; ?>" class="btn">🎯 จองสนามนี้</a>
      <a href="venue_reviews.php?venue_id=<?php echo $venue['VenueID']; ?>" class="btn btn-review">⭐ ดูรีวิวทั้งหมด</a>
      <a href="dashboard.php" class="btn btn-back">⬅ กลับหน้าหลัก</a>
    </div>
  </div>

  <div class="review-section">
    <h2>⭐ รีวิวล่าสุดจากผู้ใช้</h2>
    <?php if ($reviews->num_rows == 0): ?>
      <p class="no-review">ยังไม่มีรีวิวสำหรับสนามนี้</p>
    <?php else: ?>
      <?php $count = 0; while ($review = $reviews->fetch_assoc()): $count++; if ($count > 3) break; ?>
        <div class="review-item">
          <strong><?php echo htmlspecialchars($review['FirstName']); ?></strong>
          <span class="rating">⭐ <?php echo $review['Rating']; ?>/5</span>
          <?php if (!empty($review['CreatedAt'])): ?>
            <div class="date"><?php echo date("d/m/Y", strtotime($review['CreatedAt'])); ?></div>
          <?php endif; ?>
          <p><?php echo nl2br(htmlspecialchars($review['Comment'])); ?></p>
        </div>
      <?php endwhile; ?>
    <?php endif; ?>
  </div>

  <!-- 📍 ที่อยู่เต็ม (ล่าง) -->
  <div class="address-section">
    <h3 class="address-title">📍 ที่อยู่สนาม</h3>
    <p class="address-full"><?php echo nl2br(htmlspecialchars(trim($venue['Address'] ?? 'ยังไม่ระบุที่อยู่'))); ?></p>
  </div>
</div>

</body>
</html>
