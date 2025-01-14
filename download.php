<?php
// download.php (หรือ download_all_images.php)

// เปิด Debug (ถ้าจำเป็น)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ตรวจสอบสิทธิ์ Admin (ตามต้องการ)
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit;
}

// เชื่อมต่อ DB
include '../elephant_api/db.php';
if (!$conn || !$conn instanceof mysqli) {
    die("DB connection error");
}

// ดึง image_path จากตาราง images
$sql = "SELECT image_path FROM images WHERE image_path != ''";
$res = $conn->query($sql);
$files = [];
if ($res && $res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {
        $pathInDB = $row['image_path'];

        // สมมติ pathInDB เริ่มต้นด้วย uploads/ อยู่แล้ว
        // หากไม่ใช่ก็เติม "uploads/"
        if (strpos($pathInDB, 'uploads/') !== 0) {
            $pathInDB = 'uploads/' . $pathInDB;
        }

        // แปลงเป็น path จริงใน server (ปรับตาม directory จริง)
        // สมมติ ../elephant_api/ เป็นตำแหน่งโฟลเดอร์
        $fullpath = __DIR__ . '/../elephant_api/' . $pathInDB;

        if (is_file($fullpath)) {
            $files[] = $fullpath;
        }
    }
}
$conn->close();

// ตรวจสอบว่าเจอไฟล์หรือไม่
if (count($files) === 0) {
    die("No files to download.");
}

// สร้าง zip ชั่วคราว
$zipName = 'all_images_'.date('Ymd_His').'.zip';
$tmpDir = __DIR__ . '/tmp';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0777, true);
}
$zipPath = $tmpDir . '/' . $zipName;

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("Cannot create zip file");
}
foreach ($files as $file) {
    $zip->addFile($file, basename($file));
}
$zip->close();

// ส่งไฟล์ zip ให้ browser
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="'.$zipName.'"');
header('Content-Length: ' . filesize($zipPath));
readfile($zipPath);

// ลบไฟล์ zip ชั่วคราว ถ้าไม่ต้องการเก็บ
unlink($zipPath);
exit;
