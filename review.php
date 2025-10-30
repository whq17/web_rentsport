<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
include 'db_connect.php';

$booking_id = $_GET['booking_id'] ?? 0;
$customer_id = $_SESSION['user_id'];

// ตรวจสอบว่าการจองนี้เป็นของลูกค้าคนนี้จริง และยังไม่ได้รีวิว
$sql = "SELECT b.BookingID, v.VenueName 
        FROM Tbl_Booking b
        JOIN Tbl_Venue v ON b.VenueID = v.VenueID
        LEFT JOIN Tbl_Review r ON b.BookingID = r.BookingID
        WHERE b.BookingID = ? AND b.CustomerID = ? AND r.ReviewID IS NULL";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $booking_id, $customer_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // ❌ ถ้ารีวิวไม่ได้ แสดงหน้าแจ้งเตือนแบบสวยงาม
    ?>
    <!DOCTYPE html>
    <html lang="th">
    <head>
    <meta charset="UTF-8">
    <title>ไม่สามารถรีวิวได้ | CY Arena</title>
    <style>
    body {
        font-family: "Prompt", sans-serif;
        background: #f8fafc;
        margin: 0;
        display: flex;
        justify-content: center;
        align-items: center;
        height: 100vh;
    }
    .error-box {
        background: #fff;
        padding: 40px 50px;
        border-radius: 14px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        text-align: center;
        max-width: 500px;
    }
    .error-icon {
        font-size: 60px;
        color: #ef4444;
    }
    h2 {
        color: #0f172a;
        margin-top: 15px;
    }
    p {
        color: #64748b;
        font-size: 15px;
        margin: 10px 0 20px;
    }
    .btn {
        display: inline-block;
        padding: 10px 16px;
        background: #3b82f6;
        color: #fff;
        text-decoration: none;
        border-radius: 8px;
        font-weight: 600;
        transition: background 0.2s;
    }
    .btn:hover { background: #2563eb; }
    </style>
    </head>
    <body>
        <div class="error-box">
            <div class="error-icon">❌</div>
            <h2>ไม่สามารถรีวิวได้</h2>
            <p>อาจรีวิวไปแล้ว หรือข้อมูลการจองไม่ถูกต้อง</p>
            <a href="my_bookings.php" class="btn">⬅ กลับไปหน้าการจองของฉัน</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$booking = $result->fetch_assoc();

// ✅ เมื่อเปิดหน้ารีวิวได้
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rating = $_POST['rating'];
    $comment = $_POST['comment'];

    $sql = "INSERT INTO Tbl_Review (CustomerID, VenueID, BookingID, Rating, Comment)
            VALUES (?, (SELECT VenueID FROM Tbl_Booking WHERE BookingID=?), ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiiss", $customer_id, $booking_id, $booking_id, $rating, $comment);
    $stmt->execute();

    echo "<script>alert('ขอบคุณสำหรับการรีวิว!');window.location='my_bookings.php';</script>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>รีวิวสนาม | CY Arena</title>
<style>
body {
  font-family:"Prompt",sans-serif;
  background:#f4f7f6;
  margin:0;
}
.container {
  max-width:600px;
  margin:50px auto;
  background:#fff;
  padding:30px;
  border-radius:12px;
  box-shadow:0 6px 20px rgba(0,0,0,0.08);
}
h2 {
  text-align:center;
  color:#1e293b;
  margin-bottom:25px;
}
label {
  display:block;
  margin-top:15px;
  font-weight:bold;
  color:#334155;
}
input[type=number],
textarea {
  width:100%;
  padding:10px;
  margin-top:6px;
  border-radius:6px;
  border:1px solid #cbd5e1;
  font-size:15px;
  font-family:"Prompt",sans-serif;
}
textarea { resize: vertical; }
button {
  margin-top:25px;
  width:100%;
  padding:12px;
  background:#16a34a;
  color:#fff;
  border:none;
  border-radius:8px;
  font-weight:600;
  font-size:16px;
  cursor:pointer;
  transition:background 0.2s, transform 0.1s;
}
button:hover { background:#15803d; transform: translateY(-2px); }
button:active { transform: translateY(1px); }
.back-btn {
  display:block;
  text-align:center;
  margin-top:15px;
  color:#3b82f6;
  text-decoration:none;
  font-weight:500;
}
.back-btn:hover { text-decoration:underline; }
</style>
</head>
<body>
<div class="container">
    <h2>📝 รีวิวสนาม: <?php echo htmlspecialchars($booking['VenueName']); ?></h2>
    <form method="POST">
        <label>ให้คะแนนสนาม (1–5):</label>
        <input type="number" name="rating" min="1" max="5" required>

        <label>ความคิดเห็นเพิ่มเติม:</label>
        <textarea name="comment" rows="5" placeholder="บอกความคิดเห็นของคุณ..." required></textarea>

        <button type="submit">ส่งรีวิว</button>
    </form>
    <a href="my_bookings.php" class="back-btn">⬅ กลับไปหน้าการจองของฉัน</a>
</div>
</body>
</html>
