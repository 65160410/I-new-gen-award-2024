<?php
// get_detections.php

// ปิดการแสดงข้อผิดพลาด (ควรปิดใน production)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

// ตั้งค่า headers สำหรับ JSON
header('Content-Type: application/json; charset=utf-8');

// เริ่ม session (ถ้าต้องการตรวจสอบสิทธิ์)
session_start();

// รวมไฟล์เชื่อมต่อฐานข้อมูล
include '../elephant_api/db.php';

// ตรวจสอบการเชื่อมต่อ DB
if (!isset($conn) || !$conn instanceof mysqli) {
    echo json_encode(['status' => 'error', 'message' => 'การเชื่อมต่อฐานข้อมูลล้มเหลว']);
    exit;
}

/**
 * ฟังก์ชันแปลง Intensity Level และกำหนดคลาสสี
 * ส่งคืนข้อความและคลาสสีที่เกี่ยวข้อง
 */
function getIntensityInfo($elephant, $alert, $distance) {
    $dist = floatval($distance);
    if ($elephant && $alert) {
        return ['text' => 'ฉุกเฉิน', 'class' => 'bg-red-600 text-white'];
    } elseif ($elephant && !$alert) {
        return ['text' => 'ความเสี่ยงสูง', 'class' => 'bg-yellow-200 text-gray-700'];
    } else {
        return ['text' => 'ปกติ', 'class' => 'bg-white text-black'];
    }
}

/**
 * ฟังก์ชันอ่านไฟล์ camera_locations.txt และสร้างแผนที่ camera_id กับที่อยู่
 * @param string $filepath เส้นทางไปยังไฟล์
 * @return array แผนที่ camera_id กับที่อยู่
 */
function getCameraAddressesFromFile($filepath) {
    $camera_addresses = [];

    if (!file_exists($filepath)) {
        error_log("ไม่พบไฟล์ camera_locations.txt");
        return $camera_addresses; // คืนค่าว่างถ้าไฟล์ไม่พบ
    }

    $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $parts = explode("=", $line, 2);
        if (count($parts) == 2) {
            $camera_id = trim($parts[0]);
            $address = trim($parts[1]);
            $camera_addresses[$camera_id] = $address;
        }
    }

    return $camera_addresses;
}

// อ่านข้อมูลตำแหน่งกล้องจากไฟล์ camera_locations.txt
$camera_locations_file = __DIR__ . '/../Honey_test/camera_locations.txt'; // ปรับเส้นทางให้ถูกต้อง
$camera_addresses = getCameraAddressesFromFile($camera_locations_file);

// ตรวจสอบการอ่านไฟล์
if (empty($camera_addresses)) {
    error_log("ไม่มีที่อยู่กล้องถูกโหลด กรุณาตรวจสอบ camera_locations.txt");
}

// กำหนดการแมปสถานะจากภาษาอังกฤษเป็นภาษาไทย
$status_map = [
    'pending' => 'รอดำเนินการ',
    'completed' => 'ดำเนินการแล้ว',
];

// รับพารามิเตอร์ last_id จาก GET
$last_id = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;

// สร้าง Query เพื่อดึง detections ที่ id > last_id
$sql_new_detections = "
    SELECT
        d.id,
        d.id_cam,         
        d.elephant,
        d.lat_ele,
        d.long_ele,
        d.distance_ele,
        d.alert,
        d.status,
        d.car_count,              
        d.elephant_count,   
        i.timestamp,
        i.image_path
    FROM detections d
    LEFT JOIN images i ON d.image_id = i.id
    WHERE d.id > ?
    ORDER BY d.id ASC
";

$stmt = $conn->prepare($sql_new_detections);
if (!$stmt) {
    echo json_encode(['status' => 'error', 'message' => 'การเตรียม Query ล้มเหลว: ' . $conn->error]);
    exit;
}
$stmt->bind_param("i", $last_id);
if (!$stmt->execute()) {
    echo json_encode(['status' => 'error', 'message' => 'การดำเนินการ Query ล้มเหลว: ' . $stmt->error]);
    exit;
}
$result = $stmt->get_result();

$new_detections = [];

while ($row = $result->fetch_assoc()) {
    // สร้าง path รูป
    if (!empty($row['image_path'])) {
        // ตรวจสอบว่า path เริ่มด้วย 'uploads/' หรือไม่
        if (strpos($row['image_path'], 'uploads/') === 0) {
            $full_image_path = 'https://aprlabtop.com/elephant_api/' . $row['image_path'];
        } else {
            $full_image_path = 'https://aprlabtop.com/elephant_api/uploads/' . $row['image_path'];
        }
    } else {
        $full_image_path = null;
    }

    // ประมวลผลจำนวนรถ
    $car_count = isset($row['car_count']) && is_numeric($row['car_count']) ? intval($row['car_count']) : 0;

    // ประมวลผลจำนวนช้าง
    $elephant_count = isset($row['elephant_count']) && is_numeric($row['elephant_count']) ? intval($row['elephant_count']) : 0;

    // สร้างอาร์เรย์สำหรับสิ่งที่ตรวจจับ
    $detection_types = [];

    if ($car_count > 0) {
        $detection_types[] = "รถ " . $car_count . " คัน";
    }

    if ($elephant_count > 0) {
        $detection_types[] = "ช้าง " . $elephant_count . " ตัว";
    }

    // สร้างข้อความแสดงผล
    $detection_display = !empty($detection_types) ? implode(", ", $detection_types) : '<span class="text-red-800">ไม่มีการตรวจจับ</span>';

    // ดึง camera_id จากแถวข้อมูล
    $id_cam = isset($row['id_cam']) ? trim($row['id_cam']) : '';
    error_log("กำลังประมวลผล detection ID: " . $row['id'] . " กับ camera_id: " . $id_cam);
    $camera_address = "กล้องตัวนี้อยู่ที่ ไม่ทราบสถานที่";

    if (!empty($id_cam)) {
        if (isset($camera_addresses[$id_cam])) {
            $camera_address = "กล้องตัวนี้อยู่ที่ " . $camera_addresses[$id_cam];
            error_log("แมป camera_id {$id_cam} กับที่อยู่: " . $camera_addresses[$id_cam]);
        } else {
            // หากไม่มีการจับคู่ในไฟล์ camera_locations.txt ให้ตั้งค่าเป็น "กล้องตัวนี้อยู่ที่ ไม่ทราบสถานที่" และล็อกข้อผิดพลาด
            $camera_address = "กล้องตัวนี้อยู่ที่ ไม่ทราบสถานที่";
            error_log("ไม่พบ camera_id ใน camera_locations.txt: " . $id_cam);
        }
    } else {
        error_log("id_cam ว่างเปล่าสำหรับ detection ID: " . $row['id']);
    }

    // แปลงค่าของ status เป็นภาษาไทย
    $status_th = isset($status_map[$row['status']]) ? $status_map[$row['status']] : 'ไม่ทราบสถานะ';

    // สร้างลิงก์แก้ไขถ้าสถานะเป็น 'รอดำเนินการ'
    $edit_link = '';
    if ($status_th === 'รอดำเนินการ') {
        // ป้องกัน XSS โดยใช้ htmlspecialchars กับค่า id
        $safe_id = htmlspecialchars(urlencode($row['id']), ENT_QUOTES, 'UTF-8');
        $edit_link = '<a href="solutions_admin.php?id=' . $safe_id . '" class="edit-icon text-blue-600 hover:text-blue-800"><i class="fas fa-pencil-alt"></i></a>';
    }

    // กำหนดข้อมูล Intensity
    $intensityInfo = getIntensityInfo($row['elephant'], $row['alert'], $row['distance_ele']);

    // เก็บข้อมูลลงอาร์เรย์
    $new_detections[] = [
        'id'                => $row['id'],
        'timestamp'         => $row['timestamp'],
        'id_cam'            => $id_cam,                      // เพิ่มฟิลด์ camera_id
        'camera_address'    => $camera_address,             // แสดงที่อยู่จากไฟล์พร้อมข้อความ
        'elephant'          => filter_var($row['elephant'], FILTER_VALIDATE_BOOLEAN),
        'elephant_lat'      => $row['lat_ele'],
        'elephant_long'     => $row['long_ele'],
        'distance_ele'      => $row['distance_ele'],
        'alert'             => filter_var($row['alert'], FILTER_VALIDATE_BOOLEAN),
        'status'            => htmlspecialchars($status_th, ENT_QUOTES, 'UTF-8'), // แสดงสถานะเป็นภาษาไทยเท่านั้น
        'edit_link'         => $edit_link, // เพิ่มฟิลด์สำหรับลิงก์แก้ไข
        'image_path'        => $full_image_path,
        'car_count'               => $car_count,                         // ใช้ฟิลด์ car
        'elephant_count'    => $elephant_count,              // ใช้จากฐานข้อมูล
        'detection_display' => $detection_display,           // เพิ่มฟิลด์นี้
        'intensity_text'    => $intensityInfo['text'],
        'intensity_class'   => $intensityInfo['class']
    ];
}

$stmt->close();

// ส่งผลลัพธ์กลับเป็น JSON
echo json_encode(['status' => 'success', 'data' => $new_detections], JSON_UNESCAPED_UNICODE);
?>
