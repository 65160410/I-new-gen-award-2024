<?php
// db.php

// เปิดการแสดงข้อผิดพลาด (สำหรับการ Debug)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ตั้งค่าเชื่อมต่อฐานข้อมูลของคุณ
$servername = "51.79.177.24"; // หรือ IP ของฐานข้อมูล
$username = "aprlab_ele"; // เปลี่ยนเป็นชื่อผู้ใช้ของคุณ
$password = "Xlzv0^372"; // เปลี่ยนเป็นรหัสผ่านของคุณ
$dbname = "aprlab_db"; // เปลี่ยนเป็นชื่อฐานข้อมูลของคุณ

// สร้างการเชื่อมต่อ
$conn = new mysqli($servername, $username, $password, $dbname);

// ตรวจสอบการเชื่อมต่อ
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
} else {
    // สำหรับ Debug: แสดงข้อความว่าการเชื่อมต่อสำเร็จ
    // echo "<p>Database connected successfully.</p>";
}
?>
