<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
include 'db_connect.php';

$customer_id = $_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? 'ลูกค้า';
$role = $_SESSION['role'] ?? 'customer';

// Avatar logic (Copied from dashboard.php for consistency)
$avatarPath  = $_SESSION['avatar_path'] ?? '';
$avatarLocal = 'assets/avatar-default.png';

function _exists_rel($rel){ return is_file(__DIR__ . '/' . ltrim($rel, '/')); }

if ($avatarPath && _exists_rel($avatarPath)) {
  $avatarSrc = $avatarPath;
} elseif (_exists_rel($avatarLocal)) {
  $avatarSrc = $avatarLocal;
} else {
  $avatarSrc = 'data:image/svg+xml;base64,' . base64_encode(
    '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128"><rect width="100%" height="100%" fill="#16a34a"/><text x="50%" y="54%" text-anchor="middle" font-size="48" font-family="Arial" fill="#fff">⚽</text></svg>'
  );
}


// ดึงข้อมูลการจองทั้งหมดของลูกค้า และตรวจสอบสถานะรีวิว
$sql = "SELECT 
            b.BookingID, v.VenueName, v.VenueID, b.StartTime, b.EndTime, 
            b.HoursBooked, b.TotalPrice, bs.StatusName AS BookingStatus, 
            ps.StatusName AS PaymentStatus
        FROM Tbl_Booking b
        JOIN Tbl_Venue v ON b.VenueID = v.VenueID
        JOIN Tbl_Booking_Status bs ON b.BookingStatusID = bs.BookingStatusID
        JOIN Tbl_Payment_Status ps ON b.PaymentStatusID = ps.PaymentStatusID
        WHERE b.CustomerID = ?
        ORDER BY b.BookingID DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$result = $stmt->get_result();

$bookings = [];
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        // Check review status for each booking
        // ตรวจสอบว่ามีรีวิวสำหรับ BookingID นี้แล้วหรือไม่
        $sql_review = "SELECT ReviewID FROM Tbl_Review WHERE BookingID = ?";
        $stmt_review = $conn->prepare($sql_review);
        $stmt_review->bind_param("i", $row['BookingID']);
        $stmt_review->execute();
        $review_result = $stmt_review->get_result();
        // ตั้งค่าแฟล็ก ReviewDone เป็น true หากมีการรีวิวแล้ว
        $row['ReviewDone'] = $review_result->num_rows > 0;
        $stmt_review->close();

        $bookings[] = $row;
    }
}

$conn->close();

// Helper function to determine status class
function get_status_class($status_name) {
    $class_map = [
        'รอยืนยัน' => 'bg-yellow-100 text-yellow-800 border-yellow-300',
        'ยืนยันแล้ว' => 'bg-green-100 text-green-800 border-green-300',
        'ยกเลิกแล้ว' => 'bg-red-100 text-red-800 border-red-300',
        'เข้าใช้บริการแล้ว' => 'bg-blue-100 text-blue-800 border-blue-300', // สถานะนี้จะเปิดให้รีวิว
        'รอชำระ' => 'bg-yellow-100 text-yellow-800 border-yellow-300',
        'ชำระแล้ว' => 'bg-teal-100 text-teal-800 border-teal-300',
        'คืนเงินแล้ว' => 'bg-gray-100 text-gray-600 border-gray-300',
    ];
    return $class_map[$status_name] ?? 'bg-gray-100 text-gray-800 border-gray-300';
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ประวัติการจองของฉัน | CY Arena</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
  body {
    font-family: 'Prompt', sans-serif;
    /* ใช้สีพื้นหลังเดียวกับ Dashboard */
    background-color: #f4f7f6;
    color: #1f2937;
  }
  .container {
    max-width: 1200px;
  }

  /* Navbar Styles */
  ._header_nav a {
    transition: color 0.2s, background-color 0.2s;
  }
  ._header_nav a:hover {
    color: #047857;
    background-color: #ecfdf5;
    border-radius: 6px;
  }

  /* Status Badge Styling (Pill style with border for clear separation) */
  .status-badge {
      font-weight: 600;
      padding: 4px 10px;
      border-radius: 9999px; /* Pill shape */
      display: inline-flex;
      align-items: center;
      font-size: 0.75rem; /* text-xs */
      line-height: 1; 
      border-width: 1px;
      box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); /* Soft shadow */
  }

  /* Review Action Style */
  .review-link-btn {
      display: inline-flex;
      align-items: center;
      padding: 6px 12px;
      font-weight: 600;
      color: #059669; /* Teal-600 */
      background-color: #e0f2f1; /* Teal-100 */
      border-radius: 8px;
      transition: background-color 0.2s;
      font-size: 0.875rem;
  }
  .review-link-btn:hover {
      background-color: #ccfbf1; /* Teal-200 */
  }

  @media (max-width: 768px) {
    /* Hide the traditional table on mobile */
    .booking-table-container {
        display: none;
    }
    /* Show the Card Grid on mobile */
    .booking-card-grid {
        display: grid;
    }
    ._header_nav {
      display: none;
      flex-direction: column;
      width: 100%;
      position: absolute;
      top: 64px;
      left: 0;
      background-color: white;
      border-top: 1px solid #f3f4f6;
      padding: 1rem 0;
      z-index: 10;
    }
    ._header_nav a {
      padding: 0.75rem 1.5rem;
      width: 100%;
      border-radius: 0;
    }
    .burger-menu {
      display: block;
    }
  }

  @media (min-width: 769px) {
    /* Show the traditional table on desktop */
    .booking-table-container {
        display: block;
    }
    /* Hide the Card Grid on desktop */
    .booking-card-grid {
        display: none;
    }
  }
</style>
</head>
<body>

<!-- Header/Navbar (Copied from dashboard.php for full consistency) -->
<header class="bg-white shadow-md sticky top-0 z-20">
  <div class="container mx-auto flex justify-between items-center p-4">
    <div class="text-2xl font-bold text-teal-600">CY Arena</div>
    
    <!-- Desktop Navigation -->
    <nav class="_header_nav hidden md:flex space-x-2">
      <a href="dashboard.php" class="px-3 py-2 text-gray-700 hover:text-teal-600">หน้าหลัก</a>
      <a href="#my-bookings-section" class="px-3 py-2 text-teal-600 font-semibold bg-teal-50 rounded-lg">การจองของฉัน</a>
       <a href="my_reviews.php" class="px-3 py-2 text-teal-600 font-semibold bg-teal-50 rounded-lg">รีวิวของฉัน</a>
      <?php if ($role === 'admin'): ?>
      <a href="admin.php" class="px-3 py-2 text-teal-600 font-semibold hover:bg-teal-50">จัดการระบบ</a>
      <?php endif; ?>
    </nav>
    
    <!-- User/Logout Section -->
    <div class="flex items-center space-x-3">
      <span class="text-sm font-medium hidden sm:inline text-gray-800">สวัสดี, <?php echo htmlspecialchars($userName); ?></span>
      <div class="w-10 h-10 rounded-full bg-gray-200 overflow-hidden border-2 border-teal-500">
        <img src="<?php echo $avatarSrc; ?>" alt="User Avatar" class="w-full h-full object-cover">
      </div>
      <a href="logout.php" class="text-red-500 hover:text-red-700 text-sm font-medium ml-4 hidden md:inline">
        <i class="fas fa-sign-out-alt"></i> ออกจากระบบ
      </a>
      <!-- Burger Menu Button for Mobile -->
      <button class="burger-menu md:hidden text-gray-600 hover:text-teal-600 text-xl" onclick="toggleMenu()">
        <i class="fas fa-bars"></i>
      </button>
    </div>
  </div>
</header>

<!-- Mobile Navigation Overlay (Hidden by default) -->
<nav id="mobile-menu" class="_header_nav md:hidden" style="display: none;">
  <a href="dashboard.php">หน้าหลัก</a>
  <a href="#my-bookings-section">การจองของฉัน</a>
  <a href="profile.php">โปรไฟล์</a>
   <a href="#my-reviews">รีวิวของฉัน</a>
  <?php if ($role === 'admin'): ?>
  <a href="admin.php">จัดการระบบ</a>
  <?php endif; ?>
  <a href="logout.php" class="text-red-500">ออกจากระบบ</a>
</nav>

<!-- SECTION: My Bookings (Refined UX/UI) -->
 
<section id="my-bookings-section" class="py-8 md:py-16">
  <div class="container mx-auto px-4">
    <h2 class="text-3xl md:text-4xl font-bold text-center text-teal-700 mb-8 md:mb-12">ประวัติการจองของฉัน</h2>
    
    <?php if (empty($bookings)): ?>
        <!-- Empty State (Copied style from Dashboard's Empty Venue state) -->
        <div class="bg-white rounded-xl shadow-lg p-10 text-center border-t-4 border-teal-500/50">
            <i class="fas fa-calendar-alt text-teal-500 text-4xl mb-4"></i>
            <p class="text-xl font-semibold text-gray-700">คุณยังไม่มีการจองในขณะนี้</p>
            <p class="text-gray-500 mt-2">ไปค้นหาสนามและจองวันเวลาที่ต้องการได้เลย</p>
            <a href="dashboard.php#venues" class="mt-5 inline-block bg-teal-600 hover:bg-teal-700 text-white font-medium py-2 px-6 rounded-lg transition duration-300 shadow-md">
                ค้นหาสนาม <i class="fas fa-arrow-right ml-2"></i>
            </a>
        </div>
    <?php else: ?>
    
    <!-- NOTE: เพิ่มคำแนะนำการรีวิว -->
    <div class="bg-blue-50 border-l-4 border-blue-500 text-blue-800 p-4 mb-6 rounded-lg shadow-sm">
        <div class="flex items-center">
            <i class="fas fa-info-circle mr-3 text-lg"></i>
            <p class="text-sm font-medium">ปุ่มให้รีวิวจะปรากฏขึ้นสำหรับรายการจองที่มี **สถานะการจอง** เป็น <span class="font-bold">'เข้าใช้บริการแล้ว'</span> เท่านั้น</p>
        </div>
    </div>


    <!-- 1. DESKTOP/TABLET VIEW: Traditional Table (visible > md) -->
    <div class="booking-table-container hidden md:block bg-white rounded-xl shadow-lg border border-gray-200">
      <table class="booking-table w-full text-sm text-left text-gray-700">
        <thead class="text-xs text-gray-900 uppercase bg-teal-50 border-b border-teal-200">
          <tr>
            <th scope="col" class="py-3 px-6 rounded-tl-xl">ID</th>
            <th scope="col" class="py-3 px-6">สนาม</th>
            <th scope="col" class="py-3 px-6">วัน/เวลาเริ่มต้น</th>
            <th scope="col" class="py-3 px-6">สิ้นสุด</th>
            <th scope="col" class="py-3 px-6">ชั่วโมง</th>
            <th scope="col" class="py-3 px-6">สถานะการชำระ</th>
            <th scope="col" class="py-3 px-6">รวม (฿)</th>
            <th scope="col" class="py-3 px-6">สถานะการจอง</th>
            <th scope="col" class="py-3 px-6 rounded-tr-xl">จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($bookings as $row): ?>
          <tr class="bg-white border-b hover:bg-teal-50/50 transition duration-150">
            <td class="py-4 px-6 font-medium text-gray-900"><?php echo $row['BookingID']; ?></td>
            <td class="py-4 px-6 font-semibold text-gray-800">
                <a href="venue_detail.php?id=<?php echo $row['VenueID']; ?>" class="text-teal-600 hover:text-teal-700 hover:underline">
                    <?php echo htmlspecialchars($row['VenueName']); ?>
                </a>
            </td>
            <td class="py-4 px-6 text-gray-600 text-xs"><?php echo date("d/m/Y", strtotime($row['StartTime'])); ?><br><span class="font-medium text-sm"><?php echo date("H:i", strtotime($row['StartTime'])); ?> - <?php echo date("H:i", strtotime($row['EndTime'])); ?></span></td>
            <td class="py-4 px-6 text-gray-600 hidden"><?php echo date("H:i", strtotime($row['EndTime'])); ?></td>
            <td class="py-4 px-6 text-gray-600"><?php echo $row['HoursBooked']; ?> ชม.</td>
            
            <!-- Payment Status -->
            <td class="py-4 px-6">
                <?php $payment_status = htmlspecialchars($row['PaymentStatus']); ?>
                <span class="status-badge <?php echo get_status_class($payment_status); ?>">
                    <?php echo $payment_status; ?>
                </span>
            </td>

            <td class="py-4 px-6 text-base font-bold text-teal-600">฿<?php echo number_format($row['TotalPrice'], 2); ?></td>
            
            <!-- Booking Status -->
            <td class="py-4 px-6">
              <?php $booking_status = htmlspecialchars($row['BookingStatus']); ?>
              <span class="status-badge <?php echo get_status_class($booking_status); ?>">
                  <?php echo $booking_status; ?>
              </span>
            </td>
            
            <!-- Review Action Logic -->
            <td class="py-4 px-6">
              <?php
                if ($row['BookingStatus'] == 'เข้าใช้บริการแล้ว') {
                    if ($row['ReviewDone']) {
                        echo '<span class="text-teal-600 font-semibold flex items-center"><i class="fas fa-check-circle mr-1"></i> รีวิวแล้ว</span>';
                    } else {
                        // Link to review page
                        echo '<a href="review.php?booking_id=' . $row['BookingID'] . '" class="review-link-btn flex items-center"><i class="fas fa-star mr-1"></i> ให้รีวิว</a>';
                    }
                } else {
                    echo '<span class="text-gray-400">-</span>';
                }
              ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    
    <!-- 2. MOBILE VIEW: Card Grid (visible < md) -->
    <div class="booking-card-grid grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 md:hidden">
      <?php foreach ($bookings as $row): ?>
      <div class="bg-white rounded-xl shadow-md p-4 border border-teal-100 hover:shadow-lg transition duration-300">
        <!-- Card Header (Venue Name & ID) -->
        <div class="flex justify-between items-start mb-2 border-b pb-2">
            <div>
                <a href="venue_detail.php?id=<?php echo $row['VenueID']; ?>" class="text-lg font-bold text-teal-600 hover:text-teal-700 hover:underline">
                    <?php echo htmlspecialchars($row['VenueName']); ?>
                </a>
                <p class="text-xs text-gray-500 mt-0.5">Booking ID: #<?php echo $row['BookingID']; ?></p>
            </div>
            <!-- Booking Status (Top Right) -->
            <?php $booking_status = htmlspecialchars($row['BookingStatus']); ?>
            <span class="status-badge <?php echo get_status_class($booking_status); ?> ml-2">
                <?php echo $booking_status; ?>
            </span>
        </div>

        <!-- Booking Details -->
        <div class="space-y-2 text-sm">
            <div class="flex items-center text-gray-700">
                <i class="fas fa-calendar-alt w-5 text-teal-500"></i>
                <span class="font-medium ml-2">วันที่:</span>
                <span class="ml-auto"><?php echo date("d/m/Y", strtotime($row['StartTime'])); ?></span>
            </div>
            <div class="flex items-center text-gray-700">
                <i class="fas fa-clock w-5 text-teal-500"></i>
                <span class="font-medium ml-2">เวลา:</span>
                <span class="ml-auto"><?php echo date("H:i", strtotime($row['StartTime'])); ?> - <?php echo date("H:i", strtotime($row['EndTime'])); ?></span>
            </div>
            <div class="flex items-center text-gray-700">
                <i class="fas fa-hourglass-half w-5 text-teal-500"></i>
                <span class="font-medium ml-2">ชั่วโมง:</span>
                <span class="ml-auto"><?php echo $row['HoursBooked']; ?> ชม.</span>
            </div>
        </div>
        
        <hr class="my-3 border-gray-100">

        <!-- Payment and Total -->
        <div class="space-y-2 text-sm">
            <div class="flex justify-between items-center font-medium">
                <div class="text-gray-600 flex items-center"><i class="fas fa-wallet w-5 text-gray-500"></i> สถานะชำระ:</div>
                <?php $payment_status = htmlspecialchars($row['PaymentStatus']); ?>
                <span class="status-badge <?php echo get_status_class($payment_status); ?> ml-2">
                    <?php echo $payment_status; ?>
                </span>
            </div>
            <div class="flex justify-between items-center text-lg font-extrabold text-teal-600 pt-1">
                <div class="flex items-center"><i class="fas fa-money-bill-wave w-5 text-teal-600"></i> รวม:</div>
                <span>฿<?php echo number_format($row['TotalPrice'], 2); ?></span>
            </div>
        </div>

        <!-- Action Button (Review Logic) -->
        <div class="mt-4 pt-4 border-t border-gray-100">
            <?php
                if ($row['BookingStatus'] == 'เข้าใช้บริการแล้ว') {
                    if ($row['ReviewDone']) {
                        echo '<span class="text-teal-600 font-semibold flex items-center justify-center text-base"><i class="fas fa-check-circle mr-2"></i> รีวิวเรียบร้อย</span>';
                    } else {
                        // Link to review page
                        echo '<a href="review.php?booking_id=' . $row['BookingID'] . '" class="review-link-btn w-full justify-center"><i class="fas fa-star mr-2"></i> ให้รีวิวสนาม</a>';
                    }
                } else {
                    echo '<span class="text-gray-400 text-center block text-sm">- ยังไม่สามารถดำเนินการได้ -</span>';
                }
            ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <!-- END MOBILE VIEW -->
    
    <?php endif; ?>
  </div>
</section>
<!-- END SECTION: My Bookings -->


<!-- JavaScript for Interactivity -->
<script>
// Mobile Menu Toggle
function toggleMenu() {
  const menu = document.getElementById('mobile-menu');
  menu.style.display = menu.style.display === 'flex' ? 'none' : 'flex';
}
</script>
</body>
</html>
