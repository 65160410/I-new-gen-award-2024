<?php 
// Testapi.php

// เปิดแสดง Error (Debug) -- ปิดใน Production
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// เชื่อมต่อฐานข้อมูล
include 'db.php';

// ตั้งค่า JSON Header
header('Content-Type: application/json; charset=utf-8');

// 1) รับ JSON จาก Client
$json_data = file_get_contents("php://input");
$data = json_decode($json_data, true);

// Debug ลงไฟล์ (เฉพาะ Dev)
file_put_contents("debug_log.txt", 
    "Raw JSON:\n".$json_data."\n\nDecoded:\n".print_r($data,true)."\n", 
    FILE_APPEND
);

// 2) ตรวจสอบการเชื่อมต่อ DB
if (!$conn) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "DB connection failed: ".$conn->connect_error
    ]);
    exit;
}

/*
  ตัวอย่างโครงสร้าง JSON ที่คาดหวัง:
  {
    "camera_id": "SOURCE0_CAM_007",
    "camera_lat": 14.22512,
    "camera_long": 101.40544,
    "elephant": true,
    "elephant_lat": [14.22800, 13.22800],
    "elephant_long": [101.40500, 100.40500],
    "elephant_distance": [10.0, 40.0],
    "car_count" : 2,
    "elephant_count": 2,
    "image": null,
    "alert": false,
    "timestamp": "2025-1-19 18:05:20"
  }
*/

// 3) ตรวจสอบฟิลด์บังคับ
$required_fields = ['camera_id','camera_lat','camera_long','elephant','timestamp'];
$errors = [];

foreach ($required_fields as $field) {
    if (!isset($data[$field])) {
        $errors[$field] = "Missing required field";
    } elseif ($data[$field] === null) {
        $errors[$field] = "Field cannot be null";
    } else {
        // ตรวจสอบประเภทเบื้องต้น
        switch($field) {
            case 'camera_id':
                if (!is_string($data[$field]) || trim($data[$field]) === '') {
                    $errors[$field] = "Must be a non-empty string";
                }
                break;
            case 'camera_lat':
            case 'camera_long':
                if (!is_numeric($data[$field])) {
                    $errors[$field] = "Must be numeric";
                } else {
                    // ตรวจสอบช่วงของพิกัด
                    $value = floatval($data[$field]);
                    if ($field === 'camera_lat' && ($value < -90 || $value > 90)) {
                        $errors[$field] = "camera_lat must be between -90 and 90";
                    }
                    if ($field === 'camera_long' && ($value < -180 || $value > 180)) {
                        $errors[$field] = "camera_long must be between -180 and 180";
                    }
                }
                break;
            case 'elephant':
                // รองรับ boolean หรือ 'true'/'false'/'1'/'0'
                if (!is_bool($data[$field])) {
                    $val = strtolower($data[$field]);
                    if (!in_array($val, ['true','false','1','0'], true)) {
                        $errors[$field] = "Must be boolean";
                    }
                }
                break;
            case 'timestamp':
                // ตรวจสอบรูปแบบวันที่คร่าว ๆ
                if (!is_string($data[$field]) || !strtotime($data[$field])) {
                    $errors[$field] = "Invalid datetime format";
                }
                break;
        }
    }
}

// ตรวจสอบ optional fields และเพิ่มการตรวจสอบความถูกต้อง
// elephant_lat และ elephant_long เป็นลิสต์ของตัวเลข
if (isset($data['elephant_lat']) && isset($data['elephant_long']) && isset($data['elephant_distance'])) {
    if (!is_array($data['elephant_lat']) || !is_array($data['elephant_long']) || !is_array($data['elephant_distance'])) {
        $errors['elephant_lat'] = "elephant_lat, elephant_long และ elephant_distance ต้องเป็นอาร์เรย์";
    } else {
        // ตรวจสอบว่าขนาดของทั้งสามอาร์เรย์เท่ากัน
        $count_lat = count($data['elephant_lat']);
        $count_long = count($data['elephant_long']);
        $count_distance = count($data['elephant_distance']);
        if ($count_lat !== $count_long || $count_lat !== $count_distance) {
            $errors['elephant_lat'] = "elephant_lat, elephant_long และ elephant_distance ต้องมีจำนวนองค์ประกอบเท่ากัน";
        } else {
            // ตรวจสอบว่าแต่ละองค์ประกอบเป็นตัวเลข
            foreach ($data['elephant_lat'] as $index => $lat) {
                if (!is_numeric($lat)) {
                    $errors["elephant_lat[$index]"] = "elephant_lat[$index] ต้องเป็นตัวเลข";
                }
            }
            foreach ($data['elephant_long'] as $index => $long) {
                if (!is_numeric($long)) {
                    $errors["elephant_long[$index]"] = "elephant_long[$index] ต้องเป็นตัวเลข";
                } else {
                    // ตรวจสอบช่วงของ Longitude
                    $lon = floatval($long);
                    if ($lon < -180 || $lon > 180) {
                        $errors["elephant_long[$index]"] = "elephant_long[$index] ต้องอยู่ระหว่าง -180 ถึง 180";
                    }
                }
            }
            foreach ($data['elephant_distance'] as $index => $distance) {
                if (!is_numeric($distance)) {
                    $errors["elephant_distance[$index]"] = "elephant_distance[$index] ต้องเป็นตัวเลข";
                } elseif (floatval($distance) < 0) {
                    $errors["elephant_distance[$index]"] = "elephant_distance[$index] ต้องไม่เป็นค่าลบ";
                }
            }
        }
    }
} else {
    // ถ้าไม่มีทั้งสามฟิลด์ ให้ตรวจสอบว่าถูกต้องหรือไม่
    if (isset($data['elephant_lat']) || isset($data['elephant_long']) || isset($data['elephant_distance'])) {
        $errors['elephant_lat'] = "ต้องให้ทั้ง elephant_lat, elephant_long และ elephant_distance พร้อมกัน";
    }
}

// เพิ่มการตรวจสอบอื่นๆ ถ้ามี เช่น elephant_count, car_count, alert, image
$optional_fields = ['car_count','elephant_distance','elephant_count','alert','image'];
foreach ($optional_fields as $field) {
    if (isset($data[$field])) {
        switch($field) {
            case 'car_count':
            case 'elephant_count':
                if (!is_numeric($data[$field])) {
                    $errors[$field] = "Must be numeric";
                } else {
                    if (intval($data[$field]) < 0) {
                        $errors[$field] = "Must be non-negative";
                    }
                }
                break;
            case 'elephant_distance':
                // ได้ทำการตรวจสอบไปแล้วในขั้นตอนก่อนหน้า
                break;
            case 'alert':
                // รองรับ boolean หรือ 'true'/'false'/'1'/'0'
                if (!is_bool($data[$field])) {
                    $val = strtolower($data[$field]);
                    if (!in_array($val, ['true','false','1','0'], true)) {
                        $errors[$field] = "Must be boolean";
                    }
                }
                break;
            case 'image':
                if (!is_null($data[$field]) && !is_string($data[$field])) {
                    $errors[$field] = "Image must be a base64 string or null";
                }
                break;
        }
    }
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Invalid data",
        "errors" => $errors
    ]);
    exit;
}

// 4) จัดเก็บค่าลงตัวแปร (optional field ใช้ null ได้)
$camera_id    = $data['camera_id'];
$camera_lat   = floatval($data['camera_lat']);
$camera_long  = floatval($data['camera_long']);
$timestamp    = $data['timestamp'];

// elephant -> boolean/integer
$elephant_val = 0;
if (isset($data['elephant'])) {
    $elephant_val = ($data['elephant'] === true || 
                     $data['elephant'] === 1 || 
                     strtolower($data['elephant']) === 'true' || 
                     $data['elephant'] === '1')
                    ? 1 : 0;
}

// รับค่าช้าง (lat/long/distance) ถ้าไม่มีให้เป็น null
$elephant_lat      = isset($data['elephant_lat']) ? $data['elephant_lat'] : null;
$elephant_long     = isset($data['elephant_long']) ? $data['elephant_long'] : null;
$elephant_distance = isset($data['elephant_distance']) ? $data['elephant_distance'] : null;

// รับค่ารถ
$car_val = 0;
if (isset($data['car_count']) && is_numeric($data['car_count'])) {
    $car_val = intval($data['car_count']);
}

// elephant_count
$elephant_count_val = 0;
if (isset($data['elephant_count']) && is_numeric($data['elephant_count'])) {
    $elephant_count_val = intval($data['elephant_count']);
}

// alert -> boolean/integer
$alert_val = null;
if (isset($data['alert'])) {
    $alert_val = (strtolower($data['alert']) === 'true' || 
                  $data['alert'] === true || 
                  $data['alert'] === 1 || 
                  $data['alert'] === '1') ? 1 : 0;
} 

// image (base64 หรือ null)
$image_data = array_key_exists('image', $data) ? $data['image'] : null;

// 5) สร้างโฟลเดอร์ uploads (ถ้ายังไม่มี)
$upload_dir = "uploads";
if (!is_dir($upload_dir)) {
    // ควรกำหนด permission ตามเหมาะสม เช่น 0755
    if (!mkdir($upload_dir, 0755, true)) {
        http_response_code(500);
        echo json_encode([
            "status"=>"error",
            "message"=>"Cannot create upload directory."
        ]);
        exit;
    }
}

// 6) บันทึกข้อมูลรูปภาพลงตาราง images (image_path = NULL ถ้าไม่มีรูป)
$image_path_to_save = null;
if (!is_null($image_data)) {
    // ถือว่าเป็น base64 -> ลองถอดรหัสและบันทึกเป็นไฟล์
    $base64_clean = preg_replace('/\s+/', '', $image_data);
    $decoded_img  = base64_decode($base64_clean, true);
    if ($decoded_img === false) {
        http_response_code(400);
        echo json_encode([
            "status"  => "error",
            "message" => "Invalid base64 in 'image'."
        ]);
        exit;
    }
    // ตั้งชื่อไฟล์
    $filename = $upload_dir . "/" . date("Ymd_His") . "_" . uniqid() . ".jpg";
    if (!file_put_contents($filename, $decoded_img)) {
        http_response_code(500);
        echo json_encode([
            "status"=>"error",
            "message"=>"Cannot save image file."
        ]);
        exit;
    }
    $image_path_to_save = $filename;
}

// 7) บันทึกข้อมูลลงตาราง images ด้วย Prepared Statement
$stmt_img = $conn->prepare("
    INSERT INTO images (timestamp, image_path, cam_id)
    VALUES (?, ?, ?)
");
if (!$stmt_img) {
    http_response_code(500);
    echo json_encode([
        "status"=>"error",
        "message"=>"Prepare images failed: ".$conn->error
    ]);
    exit;
}
$stmt_img->bind_param("sss", $timestamp, $image_path_to_save, $camera_id);
if (!$stmt_img->execute()) {
    http_response_code(500);
    echo json_encode([
        "status"=>"error",
        "message"=>"Execute images failed: ".$stmt_img->error
    ]);
    exit;
}
$image_id = $stmt_img->insert_id;
$stmt_img->close();

// 8) บันทึกข้อมูลลงตาราง detections สำหรับแต่ละช้าง ด้วย Prepared Statement
$sql_det = "
  INSERT INTO detections
    (image_id, id_cam, lat_cam, long_cam, elephant, lat_ele, long_ele, distance_ele, `time`, alert, car_count, elephant_count)
  VALUES
    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
";

// เตรียม Prepared Statement สำหรับ detections
$stmt_det = $conn->prepare($sql_det);
if (!$stmt_det) {
    http_response_code(500);
    echo json_encode([
        "status"=>"error",
        "message"=>"Prepare detections failed: ".$conn->error
    ]);
    exit;
}

// กำหนดชนิดข้อมูลตามตาราง
/**
 * image_id        = int         (i)
 * id_cam          = string      (s)
 * lat_cam         = double      (d)
 * long_cam        = double      (d)
 * elephant_val    = tinyint     (i)
 * lat_ele         = double      (d)
 * long_ele        = double      (d)
 * distance_ele    = double      (d)
 * time            = string      (s)
 * alert           = tinyint     (i)
 * car_count       = int         (i)
 * elephant_count  = int         (i)
 */

// วนลูปผ่านแต่ละช้างและบันทึกข้อมูล
for ($i = 0; $i < $elephant_count_val; $i++) {
    // ตรวจสอบว่าข้อมูลมีอยู่ในอาร์เรย์
    if (!isset($elephant_lat[$i], $elephant_long[$i], $elephant_distance[$i])) {
        continue; // ข้ามหากข้อมูลไม่ครบ
    }

    $lat_ele = floatval($elephant_lat[$i]);
    $long_ele = floatval($elephant_long[$i]);
    $distance_ele = floatval($elephant_distance[$i]);

    // ตรวจสอบช่วงของพิกัด
    if ($lat_ele < -90 || $lat_ele > 90) {
        // ข้ามหรือจัดการข้อผิดพลาดตามต้องการ
        continue;
    }
    if ($long_ele < -180 || $long_ele > 180) {
        // ข้ามหรือจัดการข้อผิดพลาดตามต้องการ
        continue;
    }

    // ผูกพารามิเตอร์และ execute
    $stmt_det->bind_param(
        "isddidddsiii", // 12 ตัวอักษร
        $image_id,             // i
        $camera_id,            // s
        $camera_lat,           // d
        $camera_long,          // d
        $elephant_val,         // i
        $lat_ele,              // d
        $long_ele,             // d
        $distance_ele,         // d
        $timestamp,            // s
        $alert_val,            // i
        $car_val,              // i
        $elephant_count_val    // i
    );

    if (!$stmt_det->execute()) {
        // คุณสามารถบันทึกข้อผิดพลาดแต่ละรายการได้ตามต้องการ
        // ตัวอย่างเช่น บันทึกลง debug_log หรือส่งข้อความผิดพลาดเฉพาะเจาะจง
        // ที่นี่เราจะข้ามการ execute ครั้งนี้และดำเนินการกับช้างตัวถัดไป
        continue;
    }
}

$stmt_det->close();
$conn->close();

// 9) ส่งผลลัพธ์
echo json_encode([
    "status"  => "success",
    "message" => "Data stored successfully.",
    "image_id"=> $image_id
]);
?>
