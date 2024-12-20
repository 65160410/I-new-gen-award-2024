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
    die(json_encode([
        "status" => "error",
        "message" => "Database connection failed: " . $conn->connect_error
    ]));
}

// ฟังก์ชันสำหรับการตรวจสอบฟิลด์
function validate_data($data) {
    $errors = [];

    // ฟิลด์ที่จำเป็นต้องมีอยู่และไม่เป็น null
    $required_fields = ['camera_id', 'camera_lat', 'camera_long', 'elephant', 'timestamp'];
    foreach ($required_fields as $field) {
        if (!isset($data[$field])) {
            $errors[$field] = 'Missing required field';
        } elseif ($data[$field] === null) {
            $errors[$field] = 'Field cannot be null';
        } else {
            // ตรวจสอบประเภทข้อมูลและความถูกต้องเพิ่มเติม
            switch ($field) {
                case 'camera_id':
                    if (!is_string($data[$field]) || empty(trim($data[$field]))) {
                        $errors[$field] = 'Invalid type or empty, expected non-empty string';
                    }
                    break;
                case 'timestamp':
                    if (!is_string($data[$field]) || !strtotime($data[$field])) {
                        $errors[$field] = 'Invalid format, expected valid datetime string';
                    }
                    break;
                case 'camera_lat':
                    if (!is_numeric($data[$field])) {
                        $errors[$field] = 'Invalid type, expected number';
                    } else {
                        $lat = floatval($data[$field]);
                        if ($lat < -90 || $lat > 90) {
                            $errors[$field] = 'Invalid value, latitude must be between -90 and 90';
                        }
                    }
                    break;
                case 'camera_long':
                    if (!is_numeric($data[$field])) {
                        $errors[$field] = 'Invalid type, expected number';
                    } else {
                        $long = floatval($data[$field]);
                        if ($long < -180 || $long > 180) {
                            $errors[$field] = 'Invalid value, longitude must be between -180 and 180';
                        }
                    }
                    break;
                case 'elephant':
                    if (!is_bool($data[$field])) {
                        // รองรับค่า 1/0 หรือ 'true'/'false' ในกรณีที่มาจาก JSON
                        if ($data[$field] !== 1 && $data[$field] !== 0 && strtolower($data[$field]) !== 'true' && strtolower($data[$field]) !== 'false') {
                            $errors[$field] = 'Invalid type, expected boolean';
                        }
                    }
                    break;
            }
        }
    }

    // ฟิลด์ที่ไม่จำเป็น สามารถเป็น null ได้
    $optional_fields = ['image', 'elephant_lat', 'elephant_long', 'elephant_distance', 'alert'];
    foreach ($optional_fields as $field) {
        if (isset($data[$field])) {
            if ($data[$field] !== null) {
                switch ($field) {
                    case 'image':
                        if (!is_string($data[$field])) {
                            $errors[$field] = 'Invalid type, expected base64 string';
                        } else {
                            // ลบอักขระที่ไม่จำเป็น (เช่น ขีดเส้นใต้ หรือช่องว่าง)
                            $base64_str = preg_replace('/\s+/', '', $data[$field]);

                            // ตรวจสอบอักขระที่ถูกต้อง
                            if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $base64_str)) {
                                $errors[$field] = 'Base64 string contains invalid characters';
                            } else {
                                // ตรวจสอบความถูกต้องของ Base64
                                $decoded = base64_decode($base64_str, true);
                                if ($decoded === false) {
                                    $errors[$field] = 'Invalid base64 encoding';
                                } else {
                                    // บันทึกความยาวของ decoded data สำหรับ Debug
                                    file_put_contents("debug_log.txt", "Decoded Image Length: " . strlen($decoded) . "\n", FILE_APPEND);

                                    // ตรวจสอบว่าเป็นรูปภาพที่รองรับ (เช่น JPEG, PNG, GIF)
                                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                                    $mime = $finfo->buffer($decoded);
                                    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif'])) {
                                        $errors[$field] = 'Invalid image type, expected JPEG, PNG, or GIF';
                                    }
                                }
                            }
                        }
                        break;
                    case 'elephant_lat':
                    case 'elephant_long':
                        if (!is_numeric($data[$field])) {
                            $errors[$field] = 'Invalid type, expected number';
                        } else {
                            $value = floatval($data[$field]);
                            if ($field == 'elephant_lat' && ($value < -90 || $value > 90)) {
                                $errors[$field] = 'Invalid value, latitude must be between -90 and 90';
                            }
                            if ($field == 'elephant_long' && ($value < -180 || $value > 180)) {
                                $errors[$field] = 'Invalid value, longitude must be between -180 and 180';
                            }
                        }
                        break;
                    case 'elephant_distance':
                        if (!is_numeric($data[$field])) {
                            $errors[$field] = 'Invalid type, expected number';
                        } else {
                            $distance = floatval($data[$field]);
                            if ($distance < 0) {
                                $errors[$field] = 'Invalid value, distance cannot be negative';
                            }
                        }
                        break;
                    case 'alert':
                        if (!is_bool($data[$field])) {
                            // รองรับค่า 1/0 หรือ 'true'/'false' ในกรณีที่มาจาก JSON
                            if ($data[$field] !== 1 && $data[$field] !== 0 && strtolower($data[$field]) !== 'true' && strtolower($data[$field]) !== 'false') {
                                $errors[$field] = 'Invalid type, expected boolean';
                            }
                        }
                        break;
                }
            }
        }
    }

    return $errors;
}

// ตรวจสอบความถูกต้องของข้อมูล
$validation_errors = validate_data($data);

if (!empty($validation_errors)) {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid data",
        "errors" => $validation_errors
    ]);
    exit;
}

// ถ้าข้อมูลถูกต้อง ทำการประมวลผลต่อไป

// ตรวจสอบว่าฟิลด์ `image` มีค่าเป็น null หรือไม่
$image_path = null;
if (isset($data['image']) && $data['image'] !== null) {
    // Decode base64 image
    $base64_img = $data['image'];
    $decoded_image = base64_decode(preg_replace('/\s+/', '', $base64_img), true);

    // ตรวจสอบการ decode base64 อีกครั้ง
    if ($decoded_image === false) {
        echo json_encode(["status" => "error", "message" => "Invalid image data"]);
        exit;
    }

    // สร้างโฟลเดอร์ uploads หากยังไม่มี
    $upload_dir = "uploads";
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0777, true)) {
            die(json_encode(["status" => "error", "message" => "Failed to create uploads directory."]));
        }
    }

    // ตั้งชื่อไฟล์รูปภาพ
    $filename = $upload_dir . "/" . date("Ymd_His") . ".jpg"; 
    if (!file_put_contents($filename, $decoded_image)) {
        die(json_encode(["status" => "error", "message" => "Failed to save image file."]));
    }

    $image_path = $filename;
}

// บันทึกข้อมูลลงตาราง images
$stmt_img = $conn->prepare("INSERT INTO images (timestamp, image_path, cam_id) 
                            VALUES (?, ?, ?) 
                            ON DUPLICATE KEY UPDATE timestamp = VALUES(timestamp), image_path = VALUES(image_path)");
if (!$stmt_img) {
    die(json_encode(["status" => "error", "message" => "Prepare failed: " . $conn->error]));
}

// Bind parameters
$stmt_img->bind_param("sss", $data['timestamp'], $image_path, $data['camera_id']);
if (!$stmt_img->execute()) {
    die(json_encode(["status" => "error", "message" => "Execute failed: " . $stmt_img->error]));
}
$image_id = $stmt_img->insert_id;  // ดึง id ของแถวที่ถูก insert หรือ update
$stmt_img->close();

// รับค่า elephant และแปลงเป็น 1 หรือ 0
$elephant = $data['elephant'];
$elephant_value = ($elephant === true || strtolower($elephant) === 'true' || $elephant === 1) ? 1 : 0;

// รับค่าอื่นๆ ที่อาจเป็น null
$elephant_lat = isset($data['elephant_lat']) ? ($data['elephant_lat'] !== null ? floatval($data['elephant_lat']) : null) : null;
$elephant_long = isset($data['elephant_long']) ? ($data['elephant_long'] !== null ? floatval($data['elephant_long']) : null) : null;
$elephant_distance = isset($data['elephant_distance']) ? ($data['elephant_distance'] !== null ? floatval($data['elephant_distance']) : null) : null;

// รับค่าของ alert จาก JSON (true/false)
$alert = isset($data['alert']) ? (
    ($data['alert'] === true || strtolower($data['alert']) === 'true' || $data['alert'] === 1) ? 1 : 0
) : null;

// เตรียม statement สำหรับการแทรกข้อมูลลงในตาราง detections
$stmt_det = $conn->prepare("INSERT INTO detections (image_id, id_cam, lat_cam, long_cam, elephant, lat_ele, long_ele, distance_ele, time, alert) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
if (!$stmt_det) {
    die(json_encode(["status" => "error", "message" => "Prepare failed: " . $conn->error]));
}

// Bind parameters โดยใช้ค่า null ถ้าจำเป็น
// การใช้ 's' สำหรับ id_cam (string), 'd' สำหรับ lat_cam, long_cam, elephant, lat_ele, long_ele, distance_ele, 'i' สำหรับ alert
$stmt_det->bind_param(
    "issdddddsi", 
    $image_id,
    $data['camera_id'],
    $data['camera_lat'],
    $data['camera_long'],
    $elephant_value,
    $elephant_lat,
    $elephant_long,
    $elephant_distance,
    $data['timestamp'],
    $alert
);

// Execute the statement
if (!$stmt_det->execute()) {
    die(json_encode(["status" => "error", "message" => "Execute failed: " . $stmt_det->error]));
}

$stmt_det->close();
$conn->close();

// ส่ง response กลับไปยังฝั่ง Python
echo json_encode([
    "status" => "success",
    "message" => "Data stored successfully."
]);
?>
