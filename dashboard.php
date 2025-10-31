<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
include 'db_connect.php';

$userName = $_SESSION['user_name'];
$role     = $_SESSION['role'] ?? 'customer';

// Avatar
$avatarPath  = $_SESSION['avatar_path'] ?? '';
$avatarLocal = 'assets/avatar-default.png';

function _exists_rel($rel){ return is_file(__DIR__ . '/' . ltrim($rel, '/')); }

if ($avatarPath && _exists_rel($avatarPath)) {
  $avatarSrc = $avatarPath;
} elseif (_exists_rel($avatarLocal)) {
  $avatarSrc = $avatarLocal;
} else {
  $avatarSrc = 'data:image/svg+xml;base64,' . base64_encode(
    '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128"><rect width="100%" height="100%" fill="#2563eb"/><text x="50%" y="54%" text-anchor="middle" font-size="48" font-family="Arial" fill="#fff">⚽</text></svg>'
  );
}

// ดึงรายการสนาม
$venues = [];
$sql = "
SELECT
    v.*,
    vt.TypeName,
    IFNULL(ROUND(AVG(r.Rating),1), 0) AS AvgRating,
    COUNT(r.ReviewID) AS ReviewCount,
    CASE
      WHEN v.Status = 'closed' THEN 'closed'
      WHEN v.Status = 'maintenance' THEN 'maintenance'
      WHEN EXISTS (
        SELECT 1 FROM tbl_booking b
        WHERE b.VenueID = v.VenueID
          AND DATE(b.StartTime) = CURDATE()
          AND NOW() BETWEEN b.StartTime AND b.EndTime
          AND b.BookingStatusID NOT IN (3,4)
      ) THEN 'unavailable'
      WHEN EXISTS (
        SELECT 1 FROM tbl_booking b
        WHERE b.VenueID = v.VenueID
          AND DATE(b.StartTime) = CURDATE()
          AND b.StartTime > NOW()
          AND b.BookingStatusID NOT IN (3,4)
      ) THEN 'upcoming'
      ELSE 'available'
    END AS StatusNow
FROM tbl_venue v
JOIN tbl_venue_type vt ON v.VenueTypeID = vt.VenueTypeID
LEFT JOIN tbl_review r ON v.VenueID = r.VenueID
GROUP BY v.VenueID
ORDER BY v.VenueName;
";
if ($res = $conn->query($sql)) {
  $venues = $res->fetch_all(MYSQLI_ASSOC);
}

$bookingSummary = [
  'upcoming'  => 0,
  'confirmed' => 0,
  'completed' => 0,
  'cancelled' => 0,
  'hours'     => 0.0,
];
$upcomingBookings = [];
$hasCustomerData = ($role === 'customer') && !empty($_SESSION['user_id']);

if ($hasCustomerData) {
  $customerId = (int)$_SESSION['user_id'];

  $summarySql = "
    SELECT
      SUM(CASE WHEN b.BookingStatusID IN (1,2) AND b.StartTime >= NOW() THEN 1 ELSE 0 END) AS upcoming_count,
      SUM(CASE WHEN b.BookingStatusID = 2 THEN 1 ELSE 0 END) AS confirmed_count,
      SUM(CASE WHEN b.BookingStatusID = 5 THEN 1 ELSE 0 END) AS completed_count,
      SUM(CASE WHEN b.BookingStatusID IN (3,4) THEN 1 ELSE 0 END) AS cancelled_count,
      SUM(COALESCE(b.HoursBooked, TIMESTAMPDIFF(MINUTE, b.StartTime, b.EndTime) / 60)) AS total_hours
    FROM Tbl_Booking b
    WHERE b.CustomerID = ?
  ";

  if ($stmt = $conn->prepare($summarySql)) {
    $stmt->bind_param('i', $customerId);
    if ($stmt->execute()) {
      if ($result = $stmt->get_result()) {
        if ($row = $result->fetch_assoc()) {
          $bookingSummary['upcoming']  = (int)($row['upcoming_count'] ?? 0);
          $bookingSummary['confirmed'] = (int)($row['confirmed_count'] ?? 0);
          $bookingSummary['completed'] = (int)($row['completed_count'] ?? 0);
          $bookingSummary['cancelled'] = (int)($row['cancelled_count'] ?? 0);
          $bookingSummary['hours']     = round((float)($row['total_hours'] ?? 0), 1);
        }
      }
    }
    $stmt->close();
  }

  $upcomingSql = "
    SELECT
      b.BookingID,
      b.VenueID,
      v.VenueName,
      vt.TypeName,
      b.StartTime,
      b.EndTime,
      bs.StatusName,
      TIMESTAMPDIFF(MINUTE, b.StartTime, b.EndTime) AS DurationMinutes
    FROM Tbl_Booking b
    JOIN Tbl_Venue v ON b.VenueID = v.VenueID
    JOIN Tbl_Venue_Type vt ON v.VenueTypeID = vt.VenueTypeID
    JOIN Tbl_Booking_Status bs ON b.BookingStatusID = bs.BookingStatusID
    WHERE b.CustomerID = ?
      AND b.StartTime >= NOW()
      AND b.BookingStatusID NOT IN (3,4)
    ORDER BY b.StartTime ASC
    LIMIT 3
  ";

  if ($stmt = $conn->prepare($upcomingSql)) {
    $stmt->bind_param('i', $customerId);
    if ($stmt->execute()) {
      if ($result = $stmt->get_result()) {
        $upcomingBookings = $result->fetch_all(MYSQLI_ASSOC);
      }
    }
    $stmt->close();
  }
}

$conn->close();

function formatThaiDateTime(?string $dateTimeStr): string {
  if (empty($dateTimeStr)) {
    return '-';
  }

  try {
    $dt = new DateTime($dateTimeStr);
  } catch (Exception $e) {
    return htmlspecialchars($dateTimeStr, ENT_QUOTES, 'UTF-8');
  }

  $thaiMonths = [
    1 => 'ม.ค.',
    2 => 'ก.พ.',
    3 => 'มี.ค.',
    4 => 'เม.ย.',
    5 => 'พ.ค.',
    6 => 'มิ.ย.',
    7 => 'ก.ค.',
    8 => 'ส.ค.',
    9 => 'ก.ย.',
    10 => 'ต.ค.',
    11 => 'พ.ย.',
    12 => 'ธ.ค.'
  ];

  $month = $thaiMonths[(int)$dt->format('n')] ?? $dt->format('M');
  $day = $dt->format('j');
  $year = (int)$dt->format('Y') + 543;
  $time = $dt->format('H:i');

  return sprintf('%s %s %s • %s น.', $day, $month, $year, $time);
}

function formatDuration(?int $minutes): string {
  if (empty($minutes) || $minutes <= 0) {
    return 'ไม่ระบุระยะเวลา';
  }

  $hours = intdiv($minutes, 60);
  $mins = $minutes % 60;
  $parts = [];

  if ($hours > 0) {
    $parts[] = $hours . ' ชม.';
  }

  if ($mins > 0) {
    $parts[] = $mins . ' นาที';
  }

  return implode(' ', $parts);
}

function getBookingStatusBadgeClass(string $statusName): string {
  $statusName = trim($statusName);

  if ($statusName === 'รอยืนยัน') {
    return 'pending';
  }
  if ($statusName === 'ยืนยันแล้ว') {
    return 'confirmed';
  }
  if ($statusName === 'เข้าใช้บริการแล้ว') {
    return 'completed';
  }
  if ($statusName === 'ยกเลิกโดยลูกค้า' || $statusName === 'ยกเลิกโดยระบบ') {
    return 'cancelled';
  }

  return 'default';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CY Arena - จองสนามกีฬาออนไลน์</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&family=Kanit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root {
  --primary: #2563eb;
  --primary-dark: #1e40af;
  --primary-light: #3b82f6;
  --secondary: #eab308;
  --accent: #f97316;
  --danger: #dc2626;
  --dark: #1c1917;
  --white: #ffffff;
  --gray-50: #fafaf9;
  --gray-100: #f5f5f4;
  --gray-200: #e7e5e4;
  --gray-700: #44403c;
  --gray-900: #1c1917;
  
  --turf-green: #16a34a;
  --court-orange: #f97316;
  --field-blue: #0ea5e9;
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body { 
  font-family: 'Sarabun', 'Kanit', sans-serif; 
  background: var(--gray-50);
  color: var(--gray-900);
  line-height: 1.6;
}

/* ========== HEADER ========== */
.top-bar {
  background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
  color: white;
  padding: 0.5rem 0;
  font-size: 0.875rem;
}

.top-bar-container {
  max-width: 1400px;
  margin: 0 auto;
  padding: 0 2rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.top-bar-info {
  display: flex;
  gap: 2rem;
}

.top-bar-item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.header {
  background: white;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
  position: sticky;
  top: 0;
  z-index: 1000;
  border-bottom: 3px solid var(--primary);
}

.header-container {
  max-width: 1400px;
  margin: 0 auto;
  padding: 1rem 2rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 2rem;
}

.logo {
  display: flex;
  align-items: center;
  gap: 1rem;
  text-decoration: none;
}

.logo-icon {
  width: 60px;
  height: 60px;
  background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2rem;
  box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
  position: relative;
}

.logo-icon::after {
  content: '';
  position: absolute;
  inset: -2px;
  background: linear-gradient(45deg, transparent, rgba(255,255,255,0.3));
  border-radius: 12px;
  z-index: -1;
}

.logo-text {
  display: flex;
  flex-direction: column;
}

.logo-title {
  font-family: 'Kanit', sans-serif;
  font-size: 1.75rem;
  font-weight: 900;
  color: var(--primary);
  line-height: 1;
  letter-spacing: -0.5px;
}

.logo-subtitle {
  font-size: 0.75rem;
  color: var(--gray-700);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 1px;
}

.nav-menu {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex: 1;
  justify-content: center;
}

.nav-link {
  color: var(--gray-900);
  text-decoration: none;
  font-weight: 600;
  padding: 0.75rem 1.5rem;
  border-radius: 8px;
  transition: all 0.3s;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 1rem;
  position: relative;
}

.nav-link:hover {
  background: var(--primary);
  color: white;
  transform: translateY(-2px);
}

.user-section {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.user-menu {
  position: relative;
}

.user-trigger {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.5rem 1rem;
  background: var(--gray-50);
  border: 2px solid var(--primary);
  border-radius: 50px;
  cursor: pointer;
  transition: all 0.3s;
}

.user-trigger:hover {
  background: var(--primary);
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
}

.user-trigger:hover .user-name {
  color: white;
}

.user-avatar {
  width: 42px;
  height: 42px;
  border-radius: 50%;
  object-fit: cover;
  border: 3px solid var(--primary);
}

.user-name {
  font-weight: 700;
  color: var(--gray-900);
  font-size: 1rem;
}

.user-dropdown {
  position: absolute;
  top: calc(100% + 0.75rem);
  right: 0;
  background: white;
  border: 2px solid var(--primary);
  border-radius: 12px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
  min-width: 250px;
  opacity: 0;
  visibility: hidden;
  transform: translateY(-10px);
  transition: all 0.3s;
}

.user-menu:hover .user-dropdown {
  opacity: 1;
  visibility: visible;
  transform: translateY(0);
}

.dropdown-header {
  padding: 1rem 1.25rem;
  background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
  color: white;
  border-radius: 10px 10px 0 0;
}

.dropdown-header-name {
  font-weight: 800;
  font-size: 1.125rem;
  margin-bottom: 0.25rem;
}

.dropdown-header-role {
  font-size: 0.875rem;
  opacity: 0.9;
}

.dropdown-item {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.875rem 1.25rem;
  color: var(--gray-900);
  text-decoration: none;
  border-bottom: 1px solid var(--gray-200);
  transition: all 0.2s;
  font-weight: 600;
}

.dropdown-item:hover {
  background: var(--gray-50);
  color: var(--primary);
  padding-left: 1.5rem;
}

.dropdown-item:last-child { 
  border-bottom: none; 
  color: var(--danger);
}

/* ========== PROMO BAR ========== */
.promo-bar {
  background: linear-gradient(90deg, var(--secondary) 0%, var(--accent) 50%, var(--secondary) 100%);
  background-size: 200% 100%;
  animation: gradientShift 4s ease-in-out infinite;
  color: white;
  padding: 1rem 0;
  overflow: hidden;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

@keyframes gradientShift {
  0%, 100% { background-position: 0% 50%; }
  50% { background-position: 100% 50%; }
}

.promo-content {
  display: flex;
  white-space: nowrap;
}

.promo-text {
  display: inline-block;
  padding-left: 100%;
  animation: scrollPromo 40s linear infinite;
  font-weight: 800;
  font-size: 1rem;
  letter-spacing: 0.5px;
  text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

@keyframes scrollPromo {
  0% { transform: translateX(0); }
  100% { transform: translateX(-50%); }
}

/* ========== HERO SECTION ========== */
.hero {
  background: linear-gradient(135deg, rgba(37, 99, 235, 0.95) 0%, rgba(30, 64, 175, 0.95) 100%),
              url('data:image/svg+xml,<svg width="100" height="100" xmlns="http://www.w3.org/2000/svg"><defs><pattern id="grass" x="0" y="0" width="20" height="20" patternUnits="userSpaceOnUse"><rect fill="%232563eb" width="20" height="20"/><path d="M0 10h20M10 0v20" stroke="%231e40af" stroke-width="0.5" opacity="0.3"/></pattern></defs><rect width="100" height="100" fill="url(%23grass)"/></svg>');
  color: white;
  padding: 4rem 2rem;
  position: relative;
  overflow: hidden;
}

.hero::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: repeating-linear-gradient(
    0deg,
    transparent,
    transparent 40px,
    rgba(255, 255, 255, 0.03) 40px,
    rgba(255, 255, 255, 0.03) 80px
  );
}

.hero-container {
  max-width: 1400px;
  margin: 0 auto;
  position: relative;
  z-index: 1;
}

.hero-content {
  max-width: 900px;
  text-align: center;
  margin: 0 auto;
}

.hero-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.625rem 1.5rem;
  background: rgba(255, 255, 255, 0.25);
  backdrop-filter: blur(10px);
  border-radius: 50px;
  font-size: 0.875rem;
  font-weight: 700;
  margin-bottom: 1.5rem;
  border: 2px solid rgba(255, 255, 255, 0.4);
  text-transform: uppercase;
  letter-spacing: 1px;
}

.hero-title {
  font-family: 'Kanit', sans-serif;
  font-size: 4rem;
  font-weight: 900;
  margin-bottom: 1rem;
  line-height: 1.1;
  text-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
  letter-spacing: -1px;
}

.hero-highlight {
  color: var(--secondary);
  text-shadow: 0 0 30px rgba(234, 179, 8, 0.5);
}

.hero-subtitle {
  font-size: 1.375rem;
  margin-bottom: 2.5rem;
  line-height: 1.6;
  font-weight: 500;
  opacity: 0.95;
}

.search-box {
  max-width: 700px;
  margin: 0 auto;
  position: relative;
}

.search-input {
  width: 100%;
  padding: 1.25rem 5rem 1.25rem 1.75rem;
  border: 3px solid white;
  border-radius: 50px;
  font-size: 1.0625rem;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
  background: white;
  transition: all 0.3s;
  font-weight: 600;
}

.search-input:focus {
  outline: none;
  box-shadow: 0 12px 32px rgba(0, 0, 0, 0.3), 0 0 0 4px rgba(255, 255, 255, 0.3);
  transform: translateY(-2px);
}

.search-btn {
  position: absolute;
  right: 0.5rem;
  top: 50%;
  transform: translateY(-50%);
  background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
  color: white;
  border: none;
  padding: 0.875rem 2rem;
  border-radius: 50px;
  font-weight: 800;
  cursor: pointer;
  transition: all 0.3s;
  font-size: 1rem;
}

.search-btn:hover {
  transform: translateY(-50%) scale(1.05);
  box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
}

/* ========== QUICK ACTIONS ========== */
.quick-actions {
  max-width: 1400px;
  margin: -2rem auto 3rem;
  padding: 0 2rem;
  position: relative;
  z-index: 10;
}

.actions-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 1.5rem;
}

.action-card {
  background: white;
  padding: 2rem;
  border-radius: 16px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
  text-align: center;
  transition: all 0.3s;
  border: 2px solid transparent;
  cursor: pointer;
}

.action-card:hover {
  transform: translateY(-8px);
  box-shadow: 0 12px 24px rgba(0, 0, 0, 0.15);
  border-color: var(--primary);
}

.action-icon {
  width: 80px;
  height: 80px;
  margin: 0 auto 1rem;
  background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
  border-radius: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2.5rem;
  box-shadow: 0 8px 16px rgba(37, 99, 235, 0.3);
}

.action-title {
  font-size: 1.25rem;
  font-weight: 800;
  color: var(--gray-900);
  margin-bottom: 0.5rem;
}

.action-desc {
  font-size: 0.9375rem;
  color: var(--gray-700);
  font-weight: 500;
}

/* ========== FILTERS ========== */
.filters-section {
  max-width: 1400px;
  margin: 0 auto 3rem;
  padding: 0 2rem;
}

.section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 2rem;
}

.section-title {
  font-family: 'Kanit', sans-serif;
  font-size: 2rem;
  font-weight: 900;
  color: var(--gray-900);
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.section-title::before {
  content: '';
  width: 5px;
  height: 40px;
  background: linear-gradient(to bottom, var(--primary), var(--primary-light));
  border-radius: 10px;
}

.filter-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  padding: 1.5rem;
  background: white;
  border-radius: 16px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
  border: 2px solid var(--gray-200);
}

.filter-btn {
  padding: 0.875rem 1.75rem;
  border: 2px solid var(--gray-200);
  background: white;
  border-radius: 50px;
  font-weight: 800;
  cursor: pointer;
  transition: all 0.3s;
  font-size: 1rem;
  font-family: 'Sarabun', sans-serif;
}

.filter-btn:hover {
  border-color: var(--primary);
  color: var(--primary);
  transform: translateY(-3px);
  box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
}

.filter-btn.active {
  background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
  color: white;
  border-color: var(--primary);
  box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
  transform: translateY(-2px);
}

/* ========== VENUE CARDS ========== */
.venues-section {
  max-width: 1400px;
  margin: 0 auto 5rem;
  padding: 0 2rem;
}

.venue-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
  gap: 2rem;
}

.venue-card {
  background: white;
  border-radius: 20px;
  overflow: hidden;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
  transition: all 0.4s;
  border: 3px solid transparent;
  opacity: 0;
  animation: fadeInUp 0.6s ease-out forwards;
}

.venue-card:hover {
  transform: translateY(-12px);
  box-shadow: 0 16px 32px rgba(0, 0, 0, 0.15);
  border-color: var(--primary);
}

.venue-image-wrapper {
  position: relative;
  overflow: hidden;
  height: 260px;
  background: var(--primary);
}

.venue-image {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.6s;
}

.venue-card:hover .venue-image {
  transform: scale(1.1) rotate(1deg);
}

.venue-badge {
  position: absolute;
  top: 1.25rem;
  right: 1.25rem;
  padding: 0.625rem 1.25rem;
  border-radius: 50px;
  font-weight: 900;
  font-size: 0.8125rem;
  backdrop-filter: blur(10px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
  border: 2px solid rgba(255, 255, 255, 0.3);
  letter-spacing: 0.5px;
  text-transform: uppercase;
}

.venue-badge.available {
  background: rgba(22, 163, 74, 0.95);
  color: white;
}

.venue-badge.upcoming {
  background: rgba(234, 179, 8, 0.95);
  color: white;
}

.venue-badge.unavailable,
.venue-badge.maintenance,
.venue-badge.closed {
  background: rgba(220, 38, 38, 0.95);
  color: white;
}

.venue-type-badge {
  position: absolute;
  bottom: 1rem;
  left: 1rem;
  padding: 0.5rem 1rem;
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(10px);
  border-radius: 50px;
  font-weight: 800;
  font-size: 0.875rem;
  color: var(--primary);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

.venue-content {
  padding: 1.75rem;
}

.venue-name {
  font-family: 'Kanit', sans-serif;
  font-size: 1.5rem;
  font-weight: 800;
  color: var(--gray-900);
  margin-bottom: 1rem;
  text-decoration: none;
  display: block;
  transition: color 0.3s;
  line-height: 1.3;
}

.venue-name:hover {
  color: var(--primary);
}

.venue-info {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  margin-bottom: 1rem;
  padding: 1rem;
  background: var(--gray-50);
  border-radius: 12px;
  border-left: 4px solid var(--primary);
}

.info-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  color: var(--gray-900);
  font-size: 1rem;
  font-weight: 600;
}

.info-icon {
  font-size: 1.25rem;
  min-width: 24px;
}

.venue-price {
  background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
  color: white;
  padding: 1rem;
  border-radius: 12px;
  text-align: center;
  margin-bottom: 1rem;
  box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
}

.price-label {
  font-size: 0.875rem;
  opacity: 0.9;
  margin-bottom: 0.25rem;
}

.price-value {
  font-size: 2rem;
  font-weight: 900;
  font-family: 'Kanit', sans-serif;
}

.venue-rating {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.875rem;
  background: linear-gradient(135deg, #fef3c7, #fde68a);
  border-radius: 12px;
  margin-bottom: 1.25rem;
  border: 2px solid #fbbf24;
}

.stars {
  color: #f59e0b;
  font-size: 1.125rem;
}

.rating-text {
  font-size: 0.875rem;
  color: var(--gray-900);
  font-weight: 700;
}

.venue-actions {
  display: flex;
  gap: 1rem;
}

.btn {
  flex: 1;
  padding: 1rem;
  border: none;
  border-radius: 12px;
  font-weight: 800;
  cursor: pointer;
  transition: all 0.3s;
  text-decoration: none;
  text-align: center;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  font-size: 1rem;
}

.btn-primary {
  background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
  color: white;
  box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
  border: 2px solid var(--primary-light);
}

.btn-primary:hover {
  transform: translateY(-3px);
  box-shadow: 0 8px 20px rgba(37, 99, 235, 0.5);
}

.btn-secondary {
  background: white;
  color: var(--primary);
  border: 2px solid var(--primary);
}

.btn-secondary:hover {
  background: var(--primary);
  color: white;
  transform: translateY(-2px);
}

.btn.disabled {
  background: var(--gray-200);
  color: var(--gray-700);
  cursor: not-allowed;
  opacity: 0.6;
  pointer-events: none;
  border-color: var(--gray-200);
}

.section-subtitle {
  margin-top: 0.5rem;
  font-size: 1rem;
  color: var(--gray-700);
  font-weight: 600;
  max-width: 720px;
}

.dashboard-insights {
  max-width: 1400px;
  margin: 0 auto 4rem;
  padding: 0 2rem;
}

.insights-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 1.75rem;
  margin-top: 2rem;
}

.summary-panel,
.upcoming-panel {
  background: white;
  border-radius: 18px;
  padding: 2rem;
  border: 2px solid var(--gray-200);
  box-shadow: 0 12px 24px rgba(37, 99, 235, 0.08);
}

.summary-panel {
  position: relative;
  overflow: hidden;
}

.summary-panel::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(135deg, rgba(37, 99, 235, 0.08), rgba(59, 130, 246, 0.05));
  pointer-events: none;
}

.summary-heading,
.upcoming-heading {
  font-family: 'Kanit', sans-serif;
  font-weight: 800;
  font-size: 1.375rem;
  color: var(--gray-900);
  margin-bottom: 1.25rem;
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.summary-heading span,
.upcoming-heading span {
  font-size: 1.5rem;
}

.metric-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 1rem;
}

.metric-card {
  background: var(--gray-50);
  border-radius: 16px;
  padding: 1.25rem;
  border: 2px solid var(--gray-200);
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  transition: transform 0.3s, box-shadow 0.3s;
}

.metric-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 12px 24px rgba(37, 99, 235, 0.15);
}

.metric-icon {
  font-size: 1.5rem;
}

.metric-value {
  font-size: 2rem;
  font-weight: 900;
  color: var(--primary);
  font-family: 'Kanit', sans-serif;
}

.metric-label {
  font-size: 0.9rem;
  font-weight: 600;
  color: var(--gray-700);
}

.upcoming-list {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.upcoming-card {
  border: 2px solid var(--gray-200);
  border-radius: 14px;
  padding: 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  background: linear-gradient(135deg, rgba(255,255,255,0.95), rgba(243, 244, 246, 0.9));
  transition: transform 0.3s, box-shadow 0.3s;
}

.upcoming-card:hover {
  transform: translateY(-3px);
  box-shadow: 0 10px 22px rgba(37, 99, 235, 0.12);
}

.upcoming-title {
  font-size: 1.1rem;
  font-weight: 800;
  color: var(--gray-900);
}

.upcoming-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  font-weight: 600;
  color: var(--gray-700);
  font-size: 0.95rem;
}

.upcoming-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
}

.booking-badge {
  display: inline-flex;
  align-items: center;
  padding: 0.35rem 0.85rem;
  border-radius: 999px;
  font-weight: 700;
  font-size: 0.85rem;
  border: 2px solid transparent;
}

.booking-badge.pending {
  background: #fef9c3;
  color: #854d0e;
  border-color: #facc15;
}

.booking-badge.confirmed {
  background: #dcfce7;
  color: #166534;
  border-color: #22c55e;
}

.booking-badge.completed {
  background: #dbeafe;
  color: #1d4ed8;
  border-color: #3b82f6;
}

.booking-badge.cancelled {
  background: #fee2e2;
  color: #b91c1c;
  border-color: #ef4444;
}

.booking-badge.default {
  background: #e5e7eb;
  color: #374151;
  border-color: #d1d5db;
}

.insights-placeholder {
  border: 2px dashed var(--primary-light);
  border-radius: 16px;
  padding: 2rem;
  text-align: center;
  display: flex;
  flex-direction: column;
  gap: 1rem;
  align-items: center;
  justify-content: center;
  color: var(--gray-700);
  font-weight: 600;
  min-height: 220px;
}

.insights-placeholder-icon {
  font-size: 2.5rem;
}

#noResults {
  margin-top: 2rem;
  display: none;
}

/* ========== FOOTER ========== */
.footer {
  background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
  color: white;
  padding: 3rem 2rem 2rem;
  margin-top: 5rem;
}

.footer-container {
  max-width: 1400px;
  margin: 0 auto;
}

.footer-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 3rem;
  margin-bottom: 2rem;
}

.footer-section h3 {
  font-family: 'Kanit', sans-serif;
  font-size: 1.5rem;
  font-weight: 800;
  margin-bottom: 1rem;
}

.footer-link {
  display: block;
  color: rgba(255, 255, 255, 0.9);
  text-decoration: none;
  font-weight: 600;
  margin-bottom: 0.75rem;
  transition: all 0.3s;
}

.footer-link:hover {
  color: var(--secondary);
  padding-left: 0.5rem;
}

.footer-bottom {
  padding-top: 2rem;
  border-top: 2px solid rgba(255, 255, 255, 0.2);
  text-align: center;
  font-weight: 600;
}

/* ========== RESPONSIVE ========== */
@media (max-width: 1024px) {
  .venue-grid {
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  }
}

@media (max-width: 768px) {
  .top-bar {
    display: none;
  }

  .dashboard-insights {
    padding: 0 1.25rem;
  }

  .summary-panel,
  .upcoming-panel {
    padding: 1.5rem;
  }

  .metric-grid {
    grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
  }

  .header-container {
    flex-direction: column;
    gap: 1rem;
  }
  
  .nav-menu {
    flex-wrap: wrap;
    justify-content: center;
  }
  
  .nav-link {
    font-size: 0.875rem;
    padding: 0.625rem 1rem;
  }
  
  .hero-title {
    font-size: 2.5rem;
  }
  
  .hero-subtitle {
    font-size: 1.125rem;
  }
  
  .search-input {
    padding: 1rem 4rem 1rem 1.25rem;
    font-size: 0.9375rem;
  }
  
  .search-btn {
    padding: 0.75rem 1.5rem;
    font-size: 0.875rem;
  }
  
  .actions-grid {
    grid-template-columns: 1fr;
  }
  
  .venue-grid {
    grid-template-columns: 1fr;
  }
  
  .filter-tabs {
    justify-content: center;
  }
  
  .section-title {
    font-size: 1.5rem;
  }
}

/* ========== ANIMATIONS ========== */
@keyframes fadeInUp {
  from {
    opacity: 0;
    transform: translateY(30px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.venue-card:nth-child(1) { animation-delay: 0.05s; }
.venue-card:nth-child(2) { animation-delay: 0.1s; }
.venue-card:nth-child(3) { animation-delay: 0.15s; }
.venue-card:nth-child(4) { animation-delay: 0.2s; }
.venue-card:nth-child(5) { animation-delay: 0.25s; }
.venue-card:nth-child(6) { animation-delay: 0.3s; }

/* ========== EMPTY STATE ========== */
.empty-state {
  grid-column: 1 / -1;
  text-align: center;
  padding: 5rem 2rem;
  background: white;
  border-radius: 20px;
  border: 3px dashed var(--gray-200);
}

.empty-state-icon {
  font-size: 5rem;
  margin-bottom: 1.5rem;
  opacity: 0.5;
}

.empty-state-title {
  font-family: 'Kanit', sans-serif;
  font-size: 2rem;
  font-weight: 800;
  color: var(--gray-900);
  margin-bottom: 0.5rem;
}

.empty-state-text {
  font-size: 1.125rem;
  color: var(--gray-700);
  font-weight: 500;
}
</style>
</head>
<body>

<!-- ========== TOP BAR ========== -->
<div class="top-bar">
  <div class="top-bar-container">
    <div class="top-bar-info">
      <div class="top-bar-item">
        📞 <span>02-XXX-XXXX</span>
      </div>
      <div class="top-bar-item">
        📧 <span>contact@cyarena.com</span>
      </div>
      <div class="top-bar-item">
        ⏰ <span>เปิดให้บริการ 06:00 - 23:00 น.</span>
      </div>
    </div>
    <div>
      🎁 <strong>สมัครสมาชิก</strong> รับส่วนลด 20%
    </div>
  </div>
</div>

<!-- ========== HEADER ========== -->
<header class="header">
  <div class="header-container">
    <a href="dashboard.php" class="logo">
      <div class="logo-icon">⚽</div>
      <div class="logo-text">
        <div class="logo-title">CY ARENA</div>
        <div class="logo-subtitle">Sports Booking System</div>
      </div>
    </a>
    
    <nav class="nav-menu">
      <?php if ($role === 'customer'): ?>
        <a href="my_bookings.php" class="nav-link">📋 การจองของฉัน</a>
        <a href="bookings_calendar_public.php" class="nav-link">📅 ปฏิทินสนาม</a>
        <a href="my_reviews.php" class="nav-link">⭐ รีวิวของฉัน</a>
      <?php else: ?>
        <a href="manage_bookings.php" class="nav-link">🛠️ จัดการจอง</a>
        <a href="admin_venues.php" class="nav-link">🏟️ จัดการสนาม</a>
        <a href="bookings_calendar.php" class="nav-link">📅 ปฏิทิน</a>
        <a href="report.php" class="nav-link">📊 รายงาน</a>
      <?php endif; ?>
    </nav>
    
    <div class="user-section">
      <div class="user-menu">
        <div class="user-trigger">
          <img src="<?php echo htmlspecialchars($avatarSrc); ?>" alt="avatar" class="user-avatar">
          <span class="user-name"><?php echo htmlspecialchars($userName); ?></span>
        </div>
        <div class="user-dropdown">
          <div class="dropdown-header">
            <div class="dropdown-header-name"><?php echo htmlspecialchars($userName); ?></div>
            <div class="dropdown-header-role"><?php echo $role === 'customer' ? '👤 ลูกค้า' : '👨‍💼 พนักงาน'; ?></div>
          </div>
          <a href="profile_edit.php" class="dropdown-item">✏️ แก้ไขโปรไฟล์</a>
          <a href="logout.php" class="dropdown-item">🚪 ออกจากระบบ</a>
        </div>
      </div>
    </div>
  </div>
</header>

<!-- ========== PROMO BAR ========== -->
<div class="promo-bar">
  <div class="promo-content">
    <span class="promo-text">⚽ โปรพิเศษ! ลด 20% ทุกวันธรรมดา 🏀 จองครบ 3 ชม. ฟรี 1 ชม. 🎾 สมาชิกใหม่ลดทันที 50 บาท 🏸 โปรศุกร์-เสาร์-อาทิตย์ ลดสูงสุด 30% 🏓 จองออนไลน์รับคะแนนสะสม ⚾ แนะนำเพื่อนรับส่วนลดเพิ่ม</span>
  </div>
</div>

<!-- ========== HERO ========== -->
<section class="hero">
  <div class="hero-container">
    <div class="hero-content">
      <div class="hero-badge">
        ⭐ ระบบจองสนามกีฬา อันดับ 1
      </div>
      <h1 class="hero-title">
        จองสนามกีฬา<br>
        <span class="hero-highlight">ง่าย รวดเร็ว ปลอดภัย</span>
      </h1>
      <p class="hero-subtitle">
        เลือกสนามกีฬาที่คุณชอบ จองได้ทันที ไม่ต้องรอนาน<br>
        พร้อมโปรโมชั่นสุดคุ้มและระบบจัดการที่ทันสมัย
      </p>
      <div class="search-box">
        <input type="text" id="searchBox" class="search-input" placeholder="ค้นหาสนาม ประเภทกีฬา หรือสถานที่...">
        <button class="search-btn">🔍 ค้นหา</button>
      </div>
    </div>
  </div>
</section>

<!-- ========== QUICK ACTIONS ========== -->
<section class="quick-actions">
  <div class="actions-grid">
    <div class="action-card" onclick="window.location.href='#venues'">
      <div class="action-icon">🏟️</div>
      <div class="action-title">สนามทั้งหมด</div>
      <div class="action-desc"><?php echo count($venues); ?> สนาม</div>
    </div>
    <div class="action-card" onclick="window.location.href='#venues'">
      <div class="action-icon" style="background: linear-gradient(135deg, #16a34a 0%, #22c55e 100%);">✅</div>
      <div class="action-title">พร้อมให้บริการ</div>
      <div class="action-desc"><?php echo count(array_filter($venues, fn($v) => $v['StatusNow'] === 'available')); ?> สนาม</div>
    </div>
    <div class="action-card" onclick="window.location.href='bookings_calendar_public.php'">
      <div class="action-icon" style="background: linear-gradient(135deg, #eab308 0%, #f59e0b 100%);">📅</div>
      <div class="action-title">ปฏิทินการจอง</div>
      <div class="action-desc">ดูตารางว่าง</div>
    </div>
    <div class="action-card" onclick="window.location.href='my_bookings.php'">
      <div class="action-icon" style="background: linear-gradient(135deg, #3b82f6 0%, #60a5fa 100%);">📋</div>
      <div class="action-title">การจองของฉัน</div>
      <div class="action-desc">ตรวจสอบการจอง</div>
    </div>
  </div>
</section>

<!-- ========== CUSTOMER INSIGHTS ========== -->
<section class="dashboard-insights">
  <div class="section-header">
    <h2 class="section-title">ภาพรวมการใช้งานล่าสุด</h2>
    <p class="section-subtitle">ติดตามสถานะการจองสนามของคุณ พร้อมดูรายการนัดหมายที่จะมาถึงแบบรวดเร็ว</p>
  </div>

  <div class="insights-grid">
    <div class="summary-panel">
      <h3 class="summary-heading"><span>📊</span> สรุปรายการจอง</h3>
      <?php if ($hasCustomerData): ?>
        <div class="metric-grid">
          <div class="metric-card">
            <div class="metric-icon">⏳</div>
            <div class="metric-value"><?php echo number_format($bookingSummary['upcoming']); ?></div>
            <div class="metric-label">การจองที่กำลังจะถึง</div>
          </div>
          <div class="metric-card">
            <div class="metric-icon">✅</div>
            <div class="metric-value"><?php echo number_format($bookingSummary['confirmed']); ?></div>
            <div class="metric-label">ยืนยันแล้วทั้งหมด</div>
          </div>
          <div class="metric-card">
            <div class="metric-icon">🏁</div>
            <div class="metric-value"><?php echo number_format($bookingSummary['completed']); ?></div>
            <div class="metric-label">ใช้บริการเสร็จสิ้น</div>
          </div>
          <div class="metric-card">
            <div class="metric-icon">🕒</div>
            <div class="metric-value"><?php echo number_format($bookingSummary['hours'], 1); ?></div>
            <div class="metric-label">ชั่วโมงที่จองทั้งหมด</div>
          </div>
          <div class="metric-card">
            <div class="metric-icon">⚠️</div>
            <div class="metric-value"><?php echo number_format($bookingSummary['cancelled']); ?></div>
            <div class="metric-label">รายการที่ถูกยกเลิก</div>
          </div>
        </div>
      <?php else: ?>
        <div class="insights-placeholder">
          <div class="insights-placeholder-icon">👀</div>
          <div>เข้าสู่ระบบด้วยบัญชีลูกค้าเพื่อดูสรุปการจองส่วนตัวของคุณ</div>
          <a class="btn btn-secondary" href="<?php echo $role === 'employee' ? 'manage_bookings.php' : 'login.php'; ?>">ไปยังหน้าจัดการ</a>
        </div>
      <?php endif; ?>
    </div>

    <div class="upcoming-panel">
      <h3 class="upcoming-heading"><span>📅</span> การจองที่จะมาถึง</h3>
      <?php if ($hasCustomerData && !empty($upcomingBookings)): ?>
        <div class="upcoming-list">
          <?php foreach ($upcomingBookings as $booking): ?>
            <div class="upcoming-card">
              <div class="upcoming-title"><?php echo htmlspecialchars($booking['VenueName']); ?></div>
              <div class="upcoming-meta">
                <span>🗓️ <?php echo htmlspecialchars(formatThaiDateTime($booking['StartTime'])); ?></span>
                <span>⌛ <?php echo htmlspecialchars(formatDuration((int)($booking['DurationMinutes'] ?? 0))); ?></span>
                <span>🏷️ <?php echo htmlspecialchars($booking['TypeName']); ?></span>
              </div>
              <span class="booking-badge <?php echo getBookingStatusBadgeClass($booking['StatusName']); ?>">
                <?php echo htmlspecialchars($booking['StatusName']); ?>
              </span>
              <div class="upcoming-actions" style="margin-top: 0.5rem;">
                <a href="my_bookings.php#booking-<?php echo (int)$booking['BookingID']; ?>" class="btn btn-secondary" style="flex: none; padding: 0.75rem 1rem;">รายละเอียดการจอง</a>
                <a href="venue_detail.php?venue_id=<?php echo (int)$booking['VenueID']; ?>" class="btn btn-primary" style="flex: none; padding: 0.75rem 1rem;">ดูข้อมูลสนาม</a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php elseif ($hasCustomerData): ?>
        <div class="insights-placeholder">
          <div class="insights-placeholder-icon">🎉</div>
          <div>ยังไม่มีการจองที่จะถึงในตอนนี้<br>เริ่มต้นจองสนามแรกของคุณได้เลย</div>
          <a class="btn btn-primary" href="#venues" style="flex: none; padding: 0.85rem 1.5rem;">สำรวจสนามว่าง</a>
        </div>
      <?php else: ?>
        <div class="insights-placeholder">
          <div class="insights-placeholder-icon">ℹ️</div>
          <div>ดูปฏิทินสาธารณะเพื่อเช็คความพร้อมของสนามทั้งหมดได้ทันที</div>
          <a class="btn btn-primary" href="bookings_calendar_public.php" style="flex: none; padding: 0.85rem 1.5rem;">เปิดปฏิทินสนาม</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ========== FILTERS ========== -->
<section class="filters-section" id="venues">
  <div class="section-header">
    <h2 class="section-title">เลือกประเภทสนาม</h2>
  </div>
<div class="filter-tabs">
  <button class="filter-btn active" data-type="all">🏆 ทั้งหมด</button>
  <button class="filter-btn" data-type="ฟุตบอล">⚽ ฟุตบอล</button>
  <button class="filter-btn" data-type="ฟุตซอล">🥅 ฟุตซอล</button>
  <button class="filter-btn" data-type="บาสเกตบอล">🏀 บาสเกตบอล</button>
  <button class="filter-btn" data-type="แบดมินตัน">🏸 แบดมินตัน</button>
  <button class="filter-btn" data-type="เทนนิส">🎾 เทนนิส</button>
  <button class="filter-btn" data-type="ปิงปอง">🏓 ปิงปอง</button>
  <button class="filter-btn" data-type="วอลเลย์บอล">🏐 วอลเลย์บอล</button>
  <button class="filter-btn" data-type="เบสบอล">⚾ เบสบอล</button>
  <button class="filter-btn" data-type="ยิงธนู">🏹 ยิงธนู</button>
  <button class="filter-btn" data-type="รักบี้">🏈 รักบี้</button>
  <button class="filter-btn" data-type="ปีนผา">🧗 ปีนผา</button>
  <button class="filter-btn" data-type="ฮอกกี้พื้นสนาม">🏑 ฮอกกี้พื้นสนาม</button>
</div>

</section>

<!-- ========== VENUES ========== -->
<section class="venues-section">
  <div class="section-header">
    <h2 class="section-title">สนามแนะนำ</h2>
  </div>
  
  <div class="venue-grid" id="venueGrid">
    <?php if (empty($venues)): ?>
      <div class="empty-state">
        <div class="empty-state-icon">🏟️</div>
        <div class="empty-state-title">ไม่พบสนามกีฬา</div>
        <div class="empty-state-text">ขออภัย ไม่มีสนามกีฬาในระบบในขณะนี้</div>
      </div>
    <?php else: foreach ($venues as $venue):
      $st = $venue['StatusNow'] ?? 'available';
      $disableBooking = in_array($st, ['unavailable','maintenance','closed']);
      $statusMap = [
        'available' => ['label' => '🟢 ว่าง', 'class' => 'available'],
        'upcoming' => ['label' => '🟡 มีจอง', 'class' => 'upcoming'],
        'unavailable' => ['label' => '🔴 ไม่ว่าง', 'class' => 'unavailable'],
        'maintenance' => ['label' => '🛠️ ปรับปรุง', 'class' => 'maintenance'],
        'closed' => ['label' => '🚫 ปิด', 'class' => 'closed']
      ];
      $statusInfo = $statusMap[$st] ?? ['label' => 'ไม่ทราบ', 'class' => 'unavailable'];
      $addressFull = trim($venue['Address'] ?? '');
      if ($addressFull === '') {
        $addressFull = 'กรุงเทพมหานคร';
      }
    ?>
      <div class="venue-card"
           data-type="<?php echo htmlspecialchars($venue['TypeName']); ?>"
           data-name="<?php echo htmlspecialchars($venue['VenueName']); ?>"
           data-address="<?php echo htmlspecialchars($addressFull); ?>">
        <div class="venue-image-wrapper">
          <a href="venue_detail.php?venue_id=<?php echo $venue['VenueID']; ?>">
            <img src="<?php echo htmlspecialchars($venue['ImageURL'] ?: 'https://images.unsplash.com/photo-1574629810360-7efbbe195018?w=400&h=300&fit=crop'); ?>"
                 alt="<?php echo htmlspecialchars($venue['VenueName']); ?>"
                 class="venue-image">
          </a>
          <span class="venue-badge <?php echo $statusInfo['class']; ?>">
            <?php echo $statusInfo['label']; ?>
          </span>
          <span class="venue-type-badge">
            <?php echo htmlspecialchars($venue['TypeName']); ?>
          </span>
        </div>
        
        <div class="venue-content">
          <a href="venue_detail.php?venue_id=<?php echo $venue['VenueID']; ?>" class="venue-name">
            <?php echo htmlspecialchars($venue['VenueName']); ?>
          </a>
          
          <div class="venue-info">
  <div class="info-row">
    <span class="info-icon">🕐</span>
    <span>
      เวลาทำการ:
      <?= htmlspecialchars(substr($venue['TimeOpen'] ?? '--:--', 0, 5)) ?>
      -
      <?= htmlspecialchars(substr($venue['TimeClose'] ?? '--:--', 0, 5)) ?> น.
    </span>
  </div>

  <div class="info-row">
    <span class="info-icon">📍</span>
    <?php
      $addrShort = function_exists('mb_strimwidth')
        ? mb_strimwidth($addressFull, 0, 50, '…', 'UTF-8')
        : (function_exists('mb_substr') ? mb_substr($addressFull, 0, 50, 'UTF-8') : substr($addressFull, 0, 50));
    ?>
    <span title="<?= htmlspecialchars($addressFull) ?>">
      <?= htmlspecialchars($addrShort) ?>
    </span>
  </div>
</div>

          
          <div class="venue-price">
            <div class="price-label">ราคาเริ่มต้น</div>
            <div class="price-value">฿<?php echo number_format($venue['PricePerHour'], 0); ?> <span style="font-size:1rem;font-weight:600;">/ชม.</span></div>
          </div>
          
          <div class="venue-rating">
            <span class="stars">
              <?php 
                $rating = (int)$venue['AvgRating'];
                echo str_repeat("⭐", min(5, $rating));
                if ($rating < 5) echo str_repeat("☆", 5 - $rating);
              ?>
            </span>
            <span class="rating-text">
              <?php echo $venue['AvgRating'] > 0 ? "{$venue['AvgRating']}/5 ({$venue['ReviewCount']} รีวิว)" : "ยังไม่มีรีวิว"; ?>
            </span>
          </div>
          
          <div class="venue-actions">
            <a href="venue_detail.php?venue_id=<?php echo $venue['VenueID']; ?>" class="btn btn-secondary">
              📋 ดูรายละเอียด
            </a>
            <a href="<?php echo $disableBooking ? '#' : 'booking.php?venue_id='.$venue['VenueID']; ?>"
               class="btn btn-primary<?php echo $disableBooking ? ' disabled' : ''; ?>">
              🎯 จองทันที
            </a>
          </div>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="empty-state" id="noResults">
    <div class="empty-state-icon">🔍</div>
    <div class="empty-state-title">ไม่พบสนามที่ตรงกับการค้นหา</div>
    <div class="empty-state-text">ลองเปลี่ยนคำค้นหรือเลือกประเภทสนามอื่น</div>
  </div>
</section>

<!-- ========== FOOTER ========== -->
<footer class="footer">
  <div class="footer-container">
    <div class="footer-grid">
      <div class="footer-section">
        <h3>เกี่ยวกับเรา</h3>
        <p style="color: rgba(255, 255, 255, 0.9); font-weight: 500; line-height: 1.8;">
          CY Arena เป็นระบบจองสนามกีฬาออนไลน์ที่ทันสมัย 
          ให้บริการครบวงจร จองง่าย รวดเร็ว ปลอดภัย
        </p>
      </div>
      <div class="footer-section">
        <h3>เมนูหลัก</h3>
        <a href="dashboard.php" class="footer-link">หน้าแรก</a>
        <a href="#venues" class="footer-link">สนามกีฬา</a>
        <a href="my_bookings.php" class="footer-link">การจองของฉัน</a>
        <a href="bookings_calendar_public.php" class="footer-link">ปฏิทิน</a>
      </div>
      <div class="footer-section">
        <h3>ติดต่อเรา</h3>
        <a href="tel:02-xxx-xxxx" class="footer-link">📞 02-XXX-XXXX</a>
        <a href="mailto:contact@cyarena.com" class="footer-link">📧 contact@cyarena.com</a>
        <a href="#" class="footer-link">📍 กรุงเทพมหานคร</a>
      </div>
      <div class="footer-section">
        <h3>เวลาทำการ</h3>
        <p style="color: rgba(255, 255, 255, 0.9); font-weight: 600; line-height: 1.8;">
          จันทร์ - ศุกร์: 06:00 - 23:00<br>
          เสาร์ - อาทิตย์: 06:00 - 24:00<br>
          <strong style="color: var(--secondary);">เปิดบริการทุกวัน</strong>
        </p>
      </div>
    </div>
    <div class="footer-bottom">
      © 2025 CY Arena - ระบบจองสนามกีฬาออนไลน์ | Developed with ❤️
    </div>
  </div>
</footer>

<script>
// ========== Promo Bar Animation ==========
document.addEventListener('DOMContentLoaded', () => {
  const promoText = document.querySelector('.promo-text');
  if (promoText) {
    const text = promoText.textContent;
    promoText.textContent = text + '   ' + text;
  }
});

// ========== Search & Filter Functionality ==========
const searchBox = document.getElementById('searchBox');
const searchBtn = document.querySelector('.search-btn');
let activeFilter = 'all';

function updateNoResultsState() {
  const cards = document.querySelectorAll('.venue-card');
  const message = document.getElementById('noResults');
  if (!message) return;
  if (cards.length === 0) {
    message.style.display = 'none';
    return;
  }
  const hasVisible = Array.from(cards).some(card => card.style.display !== 'none');
  message.style.display = hasVisible ? 'none' : 'flex';
}

function updateVenueVisibility(scrollToResults = false) {
  const query = searchBox ? searchBox.value.toLowerCase().trim() : '';
  const cards = document.querySelectorAll('.venue-card');
  let visibleCount = 0;

  cards.forEach(card => {
    const type = (card.dataset.type || '').toLowerCase();
    const name = (card.dataset.name || '').toLowerCase();
    const address = (card.dataset.address || '').toLowerCase();

    const matchesFilter = activeFilter === 'all' || type === activeFilter;
    const matchesSearch = !query || name.includes(query) || type.includes(query) || address.includes(query);

    const shouldShow = matchesFilter && matchesSearch;
    card.style.display = shouldShow ? 'block' : 'none';
    if (shouldShow) {
      visibleCount++;
    }
  });

  const shouldScroll = scrollToResults && visibleCount > 0;
  updateNoResultsState();

  if (shouldScroll) {
    const section = document.getElementById('venues');
    if (section) {
      section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }
}

if (searchBox && searchBtn) {
  searchBox.addEventListener('input', () => updateVenueVisibility());
  searchBtn.addEventListener('click', () => updateVenueVisibility(true));
  searchBox.addEventListener('keypress', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      updateVenueVisibility(true);
    }
  });
}

document.querySelectorAll('.filter-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    activeFilter = (btn.dataset.type || 'all').toLowerCase();
    updateVenueVisibility();
  });
});

updateVenueVisibility();

// ========== Smooth Scroll ==========
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
  anchor.addEventListener('click', function (e) {
    const selector = this.getAttribute('href');
    if (!selector || selector === '#') {
      return;
    }
    e.preventDefault();
    const target = document.querySelector(selector);
    if (target) {
      target.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
      });
    }
  });
});
</script>

</body>
</html>