<?php
// admin_logout.php

session_start();

// ลบข้อมูลเซสชันทั้งหมด
$_SESSION = [];
session_destroy();

// รีไดเรกไปยังหน้าเข้าสู่ระบบ
header("Location: admin_login.php");
exit;
?>
