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
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit;
}

// ฟังก์ชัน Reverse Geocoding โดยใช้ Nominatim API
function getAddressFromCoords($lat, $lng) {
    $url = "https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat={$lat}&lon={$lng}&addressdetails=1";
    
    // ระบุ User-Agent ตามนโยบายของ Nominatim
    $opts = [
        'http' => [
            'header' => "User-Agent: YourAppName/1.0 (your.email@example.com)\r\n"
        ]
    ];
    $context = stream_context_create($opts);
    
    $response = @file_get_contents($url, false, $context);
    if ($response === FALSE) {
        return "ไม่ทราบสถานที่";
    }
    $data = json_decode($response, true);
    if (isset($data['address'])) {
        $address = $data['address'];
        // สร้างที่อยู่จากข้อมูลที่ได้รับ
        $parts = [];
        if (isset($address['road'])) {
            $parts[] = $address['road'];
        }
        if (isset($address['village'])) {
            $parts[] = "หมู่บ้าน " . $address['village'];
        } elseif (isset($address['town'])) {
            $parts[] = "เมือง " . $address['town'];
        } elseif (isset($address['hamlet'])) {
            $parts[] = "ชุมชน " . $address['hamlet'];
        }
        if (isset($address['suburb'])) {
            $parts[] = "ตำบล " . $address['suburb'];
        } elseif (isset($address['neighbourhood'])) {
            $parts[] = "ย่าน " . $address['neighbourhood'];
        }
        if (isset($address['county'])) {
            $parts[] = "อำเภอ " . $address['county'];
        }
        if (isset($address['state'])) {
            $parts[] = "จังหวัด " . $address['state'];
        }
        if (isset($address['postcode'])) {
            $parts[] = "รหัสไปรษณีย์ " . $address['postcode'];
        }
        return implode(", ", $parts);
    } else {
        return "ไม่ทราบสถานที่";
    }
}

// ฟังก์ชันแปลง Intensity Level และกำหนดคลาสสี
function getIntensityInfo($elephant, $alert, $distance) {
    if (floatval($distance) <= 1) {
        return ['text' => 'ฉุกเฉิน', 'class' => 'bg-red-600 text-white'];
    } elseif ($elephant && $alert) {
        return ['text' => 'ความเสี่ยงสูง', 'class' => 'bg-red-300 text-red-800'];
    } elseif ($elephant && !$alert) {
        return ['text' => 'ความเสี่ยงปานกลาง', 'class' => 'bg-yellow-200 text-gray-700'];
    } else {
        return ['text' => 'ปกติ', 'class' => 'bg-white text-black'];
    }
}

// รับค่าพารามิเตอร์ last_id จาก GET
$last_id = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;

// Query เพื่อดึงข้อมูลการตรวจจับที่มี id > last_id
$sql_new_detections = "
    SELECT
        detections.id,
        detections.lat_cam, detections.long_cam,
        detections.elephant,
        detections.lat_ele,
        detections.long_ele,
        detections.distance_ele,
        detections.alert,
        detections.status,
        images.timestamp,
        images.image_path
    FROM detections
    LEFT JOIN images ON detections.image_id = images.id
    WHERE detections.id > ?
    ORDER BY detections.id ASC
";

$stmt = $conn->prepare($sql_new_detections);
if (!$stmt) {
    echo json_encode(['status' => 'error', 'message' => 'Prepare failed: ' . $conn->error]);
    exit;
}
$stmt->bind_param("i", $last_id);
$stmt->execute();
$result = $stmt->get_result();

$new_detections = [];
$address_cache = []; // แคชที่อยู่เพื่อเพิ่มประสิทธิภาพ

while ($row = $result->fetch_assoc()) {
    // สร้าง path รูป
    if (!empty($row['image_path'])) {
        if (strpos($row['image_path'], 'uploads/') === 0) {
            $full_image_path = 'https://aprlabtop.com/elephant_api/' . $row['image_path'];
        } else {
            $full_image_path = 'https://aprlabtop.com/elephant_api/uploads/' . $row['image_path'];
        }
    } else {
        $full_image_path = '';
    }

    // ดึงค่าจำนวนเดียวของ elephant_lat และ elephant_long
    $elephant_lat = $row['lat_ele'];
    $elephant_long = $row['long_ele'];

    // กำหนด elephant_count ตามค่าที่ได้มา
    if (is_numeric($elephant_lat) && is_numeric($elephant_long)) {
        $elephant_count = 1;
    } else {
        $elephant_count = 0;
    }

    // กำหนดค่าพิกัดโดยตรง
    $valid_prefs = [
        'lat' => $elephant_lat,
        'lng' => $elephant_long
    ];

    // เช็คพิกัด
    $has_null_coords = (
        is_null($row['lat_cam']) || is_null($row['long_cam']) ||
        is_null($elephant_lat) || is_null($elephant_long)
    );

    // รับที่อยู่ของ Camera Location จากพิกัด
    $camera_address = "ไม่ทราบสถานที่";
    if (!is_null($row['lat_cam']) && !is_null($row['long_cam'])) {
        // ตรวจสอบว่าพิกัดกล้องเป็นตัวเลข
        if (is_numeric($row['lat_cam']) && is_numeric($row['long_cam'])) {
            $coord_key = $row['lat_cam'] . "," . $row['long_cam'];
            if (isset($address_cache[$coord_key])) {
                // ใช้แคชที่อยู่ถ้ามี
                $camera_address = $address_cache[$coord_key];
            } else {
                // เรียกใช้ฟังก์ชัน Reverse Geocoding
                $camera_address = getAddressFromCoords($row['lat_cam'], $row['long_cam']);
                // เก็บลงในแคช
                $address_cache[$coord_key] = $camera_address;
            }
        }
    }

    // กำหนด Intensity Info
    $intensityInfo = getIntensityInfo($row['elephant'], $row['alert'], $row['distance_ele']);

    $new_detections[] = [
        'id'                => $row['id'],
        'timestamp'         => $row['timestamp'],
        'lat_cam'           => $row['lat_cam'],
        'long_cam'          => $row['long_cam'],
        'camera_address'    => $camera_address,
        'elephant'          => filter_var($row['elephant'], FILTER_VALIDATE_BOOLEAN),
        'elephant_lat'      => $valid_prefs['lat'], // เป็นค่าจำนวนเดียวของ latitudes ที่ส่งมา
        'elephant_long'     => $valid_prefs['lng'], // เป็นค่าจำนวนเดียวของ longitudes ที่ส่งมา
        'distance_ele'      => $row['distance_ele'], // ใช้ distance_ele แทน distance_ele
        'alert'             => filter_var($row['alert'], FILTER_VALIDATE_BOOLEAN),
        'status'            => $row['status'],
        'image_path'        => $full_image_path,
        'elephant_count'    => $elephant_count
    ];
}

$stmt->close();

// ส่งผลลัพธ์กลับเป็น JSON
echo json_encode(['status' => 'success', 'data' => $new_detections]);
?>
