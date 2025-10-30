<?php

// 1. ตั้งค่าข้อมูลสำหรับเชื่อมต่อ
$servername = "localhost"; // หรือ "127.0.0.1"
$username   = "root";       // นี่คือ username เริ่มต้นของ XAMPP
$password   = "";           // XAMPP เริ่มต้นจะไม่มีรหัสผ่าน (เว้นว่างไว้)
$dbname     = "cy_arena_db"; // ชื่อฐานข้อมูลที่คุณสร้างในขั้นตอนที่ 2

// 2. สร้างการเชื่อมต่อ (OOP Style)
$conn = new mysqli($servername, $username, $password, $dbname);

// 3. ตรวจสอบการเชื่อมต่อ
if ($conn->connect_error) {
    // ถ้าเชื่อมต่อไม่สำเร็จ
    die("Connection failed: " . $conn->connect_error);
}

// ถ้ามาถึงบรรทัดนี้ได้ แปลว่าเชื่อมต่อสำเร็จ


// 4. ปิดการเชื่อมต่อ (เป็นนิสัยที่ดี)
//$conn->close();

?>