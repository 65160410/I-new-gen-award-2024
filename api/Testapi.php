<?php
// เปิดการแสดงข้อผิดพลาด (สำหรับการ Debug)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// รวมไฟล์เชื่อมต่อฐานข้อมูล
include 'db.php';

// รับ JSON input จาก Python
$json_data = file_get_contents("php://input");
$data = json_decode($json_data, true);

// พิมพ์ข้อมูลที่ได้รับมา (สำหรับ Debug)
file_put_contents("debug_log.txt", print_r($data, true));

// ตรวจสอบการเชื่อมต่อฐานข้อมูล
if (!$conn) {
    die(json_encode(["status" => "error", "message" => "Database connection failed: " . $conn->connect_error]));
}

// ตรวจสอบข้อมูลที่จำเป็น
if (isset($data['camera_id'], $data['camera_lat'], $data['camera_long'], $data['elephant'], $data['image'], $data['timestamp'])) {
    // Decode base64 image
    $base64_img = $data['image'];
    $img_data = base64_decode($base64_img);

    // สร้างโฟลเดอร์ uploads หากยังไม่มี
    if (!is_dir("uploads")) {
        if (!mkdir("uploads", 0777, true)) {
            die(json_encode(["status" => "error", "message" => "Failed to create uploads directory."]));
        }
    }

    // ตั้งชื่อไฟล์รูปภาพ
    $filename = "uploads/" . date("Ymd_His") . ".jpg"; 
    if (!file_put_contents($filename, $img_data)) {
        die(json_encode(["status" => "error", "message" => "Failed to save image file."]));
    }

    // บันทึกข้อมูลลงตาราง images
    $timestamp = $data['timestamp'];
    $stmt_img = $conn->prepare("INSERT INTO images (timestamp, image_path) VALUES (?, ?)");
    if (!$stmt_img) {
        die(json_encode(["status" => "error", "message" => "Prepare failed: " . $conn->error]));
    }
    $stmt_img->bind_param("ss", $timestamp, $filename);
    if (!$stmt_img->execute()) {
        die(json_encode(["status" => "error", "message" => "Execute failed: " . $stmt_img->error]));
    }
    $image_id = $stmt_img->insert_id;
    $stmt_img->close();

    // รับค่า elephant
    $elephant = $data['elephant'];
    $elephant_value = $elephant ? 1 : 0;

    // รับค่าอื่นๆ ที่อาจเป็น null
    $elephant_lat = isset($data['elephant_lat']) && $data['elephant_lat'] !== null ? $data['elephant_lat'] : 0.0;
    $elephant_long = isset($data['elephant_long']) && $data['elephant_long'] !== null ? $data['elephant_long'] : 0.0;
    $elephant_distance = isset($data['elephant_distance']) ? $data['elephant_distance'] : null;

    // บันทึกข้อมูลการตรวจจับ
    $stmt_det = $conn->prepare("INSERT INTO detections (image_id, id_cam, lat_cam, long_cam, elephant, lat_ele, long_ele, distance_ele, time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt_det) {
        die(json_encode(["status" => "error", "message" => "Prepare failed: " . $conn->error]));
    }
    $stmt_det->bind_param(
        "issddddds", 
        $image_id,
        $data['camera_id'],
        $data['camera_lat'],
        $data['camera_long'],
        $elephant_value,
        $elephant_lat,
        $elephant_long,
        $elephant_distance,
        $data['timestamp']
    );

    if (!$stmt_det->execute()) {
        die(json_encode(["status" => "error", "message" => "Execute failed: " . $stmt_det->error]));
    }

    $stmt_det->close();
    $conn->close();

    // ส่ง response กลับไปยังฝั่ง Python
    echo json_encode(["status" => "success", "message" => "Data stored successfully."]);
} else {
    echo json_encode(["status" => "error", "message" => "Invalid data"]);
}
?>
