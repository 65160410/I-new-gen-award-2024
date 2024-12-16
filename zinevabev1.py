from ultralytics import YOLO
import cv2
import numpy as np
import matplotlib.pyplot as plt
import torch
import requests
import base64
import time
import json
import math
from datetime import datetime
import pytz

def draw_perspective_lines(image, road_mask, standard_road_width_meters=3.5, distance_between_lines_meters=10, max_distance_meters=100):
    """
    วาดเส้นบนถนนทุก ๆ ระยะที่กำหนดในหน่วยเมตร และระบุระยะทางบนเส้นเหล่านั้น

    Parameters:
    - image: รูปภาพต้นฉบับในรูปแบบ RGB
    - road_mask: มาสก์ถนนในรูปแบบ ndarray
    - standard_road_width_meters: ความกว้างถนนในหน่วยเมตร
    - distance_between_lines_meters: ระยะห่างระหว่างเส้นในหน่วยเมตร
    - max_distance_meters: ระยะสูงสุดที่จะวาดเส้นในหน่วยเมตร

    Returns:
    - result_image: รูปภาพที่วาดเส้นและระบุระยะทางแล้ว
    - y_positions: ตำแหน่ง y ของเส้นที่วาด
    """
    try:
        height, width = road_mask.shape[:2]
        road_pixels = np.where(road_mask > 0)
        if len(road_pixels[0]) == 0:
            print("[DEBUG] ไม่พบพิกเซลถนน.")
            return image, []

        # คำนวณตำแหน่งสูงสุดและต่ำสุดของถนนในภาพ
        top_y = np.min(road_pixels[0])
        bottom_y = np.max(road_pixels[0])
        road_height_pixels = bottom_y - top_y

        print(f"[DEBUG] top_y: {top_y}, bottom_y: {bottom_y}, road_height_pixels: {road_height_pixels}")

        if road_height_pixels <= 0:
            print("[DEBUG] ค่า road_height_pixels ไม่ถูกต้อง. ไม่สามารถวาดเส้นได้.")
            return image, []

        # สมมติว่าความยาวที่มองเห็นคือ max_distance_meters
        meters_per_pixel_y = max_distance_meters / road_height_pixels
        print(f"[DEBUG] meters_per_pixel_y: {meters_per_pixel_y}")

        # สร้างรายการระยะทางที่ต้องการวาดเส้น (10, 20, ..., max_distance_meters)
        distances_meters = list(range(distance_between_lines_meters, max_distance_meters + distance_between_lines_meters, distance_between_lines_meters))
        print(f"[DEBUG] distances_meters: {distances_meters}")

        # สร้างตำแหน่ง y สำหรับวาดเส้นตามระยะทาง
        y_positions = []
        for distance in distances_meters:
            y = int(bottom_y - (distance / meters_per_pixel_y))
            if y < top_y:
                print(f"[DEBUG] y={y} อยู่เหนือ top_y={top_y}. หยุดการวาดเส้น.")
                break
            y_positions.append(y)
        print(f"[DEBUG] y_positions: {y_positions}")

        if not y_positions:
            print("[DEBUG] ไม่มี y_positions ที่คำนวณได้. ไม่มีเส้นที่จะวาด.")
            return image, []

        result_image = image.copy()
        road_mask_3d = np.stack([road_mask] * 3, axis=2)
        result_image = cv2.addWeighted(result_image, 0.7, (road_mask_3d > 0).astype(np.uint8) * image, 0.3, 0)

        # วาดเส้นตาม y_positions และระบุระยะทาง
        for y, distance in zip(y_positions, distances_meters):
            if y < 0 or y >= height:
                print(f"[DEBUG] ข้าม y ที่อยู่นอกขอบเขต: y={y}")
                continue
            row_pixels = np.where(road_mask[y, :] > 0)[0]
            if len(row_pixels) > 0:
                left_x = row_pixels[0]
                right_x = row_pixels[-1]
                # ทำให้ความหนาของเส้นเพิ่มขึ้นตามระยะทาง
                thickness = int(2 + (distance / max_distance_meters) * 3)
                cv2.line(result_image, (left_x, y), (right_x, y), (255, 255, 255), thickness)
                print(f"[DEBUG] วาดเส้นที่ y={y} (distance={distance}m) จาก x={left_x} ถึง x={right_x} ด้วยความหนา={thickness}")

                # ระบุระยะทางบนเส้น
                text = f"{distance}m"
                font = cv2.FONT_HERSHEY_SIMPLEX
                font_scale = 0.6
                text_thickness = 2
                text_size, _ = cv2.getTextSize(text, font, font_scale, text_thickness)
                text_x = right_x - text_size[0] - 10  # ตำแหน่ง x ของข้อความ (10 พิกเซลจากขวาของเส้น)
                text_y = y - 10  # ตำแหน่ง y ของข้อความ (10 พิกเซลจากเส้น)

                # ตรวจสอบว่าข้อความไม่ออกนอกภาพ
                if text_y < 0:
                    text_y = y + text_size[1] + 10

                cv2.putText(result_image, text, (text_x, text_y), font, font_scale, (255, 255, 255), text_thickness, cv2.LINE_AA)
                print(f"[DEBUG] เพิ่มข้อความ '{text}' ที่ ({text_x}, {text_y})")
            else:
                print(f"[DEBUG] ไม่พบพิกเซลถนนที่ y={y}")

        return result_image, y_positions
    except Exception as e:
        print(f"[ERROR in draw_perspective_lines]: {e}")
        return image, []

def process_road_image(image_path, model_path, params):
    """
    ประมวลผลการตรวจจับถนนจากรูปภาพ

    Parameters:
    - image_path: เส้นทางของรูปภาพ
    - model_path: เส้นทางของโมเดลถนน
    - params: พารามิเตอร์ต่าง ๆ

    Returns:
    - road_found: Boolean ว่าพบถนนหรือไม่
    - road_mask: มาสก์ถนน
    - result_image: รูปภาพที่วาดเส้นแล้ว
    - y_positions: ตำแหน่ง y ของเส้นที่วาด
    """
    try:
        print("[DEBUG] กำลังโหลดโมเดลถนน...")
        road_model = YOLO(model_path)
        print("[DEBUG] โหลดโมเดลถนนเรียบร้อยแล้ว. กำลังรัน inference...")
        road_results = road_model(image_path, conf=params['confidence_threshold'])
        print("[DEBUG] inference เสร็จสิ้น.")

        if isinstance(road_results, list) and len(road_results) > 0:
            road_result = road_results[0]
            class_names = road_model.names

            road_class_id = None
            for cls_id, cls_name in class_names.items():
                if cls_name.lower() == 'road':
                    road_class_id = cls_id
                    break
            print(f"[DEBUG] road_class_id: {road_class_id}")

            image = cv2.imread(image_path)
            if image is None:
                print(f"[ERROR] ไม่พบรูปภาพหรือไม่สามารถโหลดรูปภาพได้: {image_path}")
                return False, None, None, []

            image = cv2.cvtColor(image, cv2.COLOR_BGR2RGB)
            road_mask = np.zeros((road_result.orig_shape[0], road_result.orig_shape[1]), dtype=np.uint8)

            if road_class_id is not None and road_result.masks is not None:
                for mask, cls in zip(road_result.masks.data, road_result.boxes.cls):
                    if int(cls) == road_class_id:
                        mask_image = mask.cpu().numpy().astype(np.uint8) * 255
                        if mask_image.shape != road_mask.shape:
                            mask_image = cv2.resize(
                                mask_image,
                                (road_mask.shape[1], road_mask.shape[0]),
                                interpolation=cv2.INTER_NEAREST
                            )
                        road_mask = cv2.bitwise_or(road_mask, mask_image)

            print(f"[DEBUG] road_mask sum: {np.sum(road_mask)}")
            if np.sum(road_mask) == 0:
                print("[DEBUG] road_mask ว่างเปล่า. ไม่พบถนน.")

            if np.sum(road_mask) > 0:
                print("[DEBUG] พบ road_mask. กำลังวาดเส้นมุมมอง...")
                result_image, y_positions = draw_perspective_lines(
                    image,
                    road_mask,
                    params['standard_road_width_meters'],
                    distance_between_lines_meters=params['distance_between_lines_meters'],
                    max_distance_meters=params.get('max_distance_meters', 100)  # เริ่มต้น 100 เมตร
                )
                return True, road_mask, result_image, y_positions
            else:
                print("[DEBUG] ไม่พบส่วน 'road' ในภาพ.")
                return False, None, image, []
        else:
            print("[DEBUG] ไม่มีผลลัพธ์จากโมเดลถนน.")
            return False, None, None, []
    except Exception as e:
        print(f"[ERROR in process_road_image]: {e}")
        return False, None, None, []

def encode_image(image_path):
    """
    เข้ารหัสรูปภาพเป็น Base64

    Parameters:
    - image_path: เส้นทางของรูปภาพ

    Returns:
    - ข้อความ Base64 ของรูปภาพ
    """
    try:
        with open(image_path, "rb") as img_file:
            data = base64.b64encode(img_file.read()).decode('utf-8')
            print(f"[DEBUG] รูปภาพถูกเข้ารหัส. ความยาว: {len(data)} ตัวอักษร.")
            return data
    except FileNotFoundError:
        print(f"[ERROR] ไม่พบไฟล์รูปภาพ: {image_path}")
        return None
    except Exception as e:
        print(f"[ERROR in encode_image]: {e}")
        return None

def send_to_server(url, data):
    """
    ส่งข้อมูลไปยังเซิร์ฟเวอร์

    Parameters:
    - url: URL ของเซิร์ฟเวอร์
    - data: ข้อมูลที่ต้องการส่ง

    Returns:
    - คำตอบจากเซิร์ฟเวอร์
    """
    headers = {'Content-Type': 'application/json'}
    print("[DEBUG] กำลังส่งข้อมูลไปยังเซิร์ฟเวอร์...")
    try:
        response = requests.post(url, json=data, headers=headers)
        print("[DEBUG] ได้รับการตอบกลับจากเซิร์ฟเวอร์.")
        return response
    except requests.exceptions.RequestException as e:
        print(f"[ERROR ในการส่งข้อมูลไปยังเซิร์ฟเวอร์]: {e}")
        return None

def calculate_destination_latlong(lat, lon, distance_m, bearing_degrees):
    """
    คำนวณตำแหน่งละติจูดและลองจิจูดใหม่จากระยะทางและทิศทาง

    Parameters:
    - lat: ละติจูดปัจจุบัน
    - lon: ลองจิจูดปัจจุบัน
    - distance_m: ระยะทางในหน่วยเมตร
    - bearing_degrees: ทิศทางในองศา

    Returns:
    - ละติจูดและลองจิจูดใหม่
    """
    try:
        R = 6378137.0  # รัศมีของโลกในหน่วยเมตร
        bearing = math.radians(bearing_degrees)
        lat_rad = math.radians(lat)
        lon_rad = math.radians(lon)

        lat_new = math.asin(math.sin(lat_rad)*math.cos(distance_m/R) +
                            math.cos(lat_rad)*math.sin(distance_m/R)*math.cos(bearing))

        lon_new = lon_rad + math.atan2(math.sin(bearing)*math.sin(distance_m/R)*math.cos(lat_rad),
                                       math.cos(distance_m/R)-math.sin(lat_rad)*math.sin(lat_new))

        lat_new_deg = math.degrees(lat_new)
        lon_new_deg = math.degrees(lon_new)
        return lat_new_deg, lon_new_deg
    except Exception as e:
        print(f"[ERROR in calculate_destination_latlong]: {e}")
        return None, None

def detect_objects(image_path, primary_model, secondary_model, class_names_primary, class_names_secondary, conf_threshold=0.5):
    """
    ตรวจจับวัตถุ (ช้างและรถยนต์) จากรูปภาพโดยใช้โมเดล YOLO หลักและโมเดลเพิ่มเติมสำหรับรถยนต์

    Parameters:
    - image_path: เส้นทางของรูปภาพ
    - primary_model: โมเดล YOLO หลัก (ELMoCa.pt)
    - secondary_model: โมเดล YOLO เพิ่มเติมสำหรับรถยนต์ (car1class.pt)
    - class_names_primary: ชื่อคลาสจากโมเดลหลัก
    - class_names_secondary: ชื่อคลาสจากโมเดลเพิ่มเติม
    - conf_threshold: ค่า threshold สำหรับความมั่นใจ

    Returns:
    - detected_objects: รายการวัตถุที่ตรวจจับ
    """
    try:
        detected_objects = []

        # ตรวจจับด้วยโมเดลหลัก (ELMoCa.pt)
        print("[DEBUG] กำลังรันการตรวจจับด้วยโมเดลหลัก (ELMoCa.pt)...")
        primary_results = primary_model(image_path, conf=conf_threshold)
        for result in primary_results:
            if result.boxes is not None:
                for box, cls in zip(result.boxes.xyxy, result.boxes.cls):
                    cls = int(cls)
                    class_name = class_names_primary.get(cls, None)
                    if class_name is not None and class_name.lower() in ['elephant', 'car']:
                        detected_objects.append({
                            "class_name": class_name,
                            "box": box.cpu().numpy().tolist(),
                            "confidence": float(result.boxes.conf[0]) if result.boxes.conf is not None else 0.0
                        })
                        print(f"[DEBUG] ตรวจจับ {class_name} ด้วยโมเดลหลัก ด้วยกล่อง {box.cpu().numpy()}")

        # ตรวจจับด้วยโมเดลเพิ่มเติมสำหรับรถยนต์ (car1class.pt)
        print("[DEBUG] กำลังรันการตรวจจับด้วยโมเดลเพิ่มเติมสำหรับรถยนต์ (car1class.pt)...")
        secondary_results = secondary_model(image_path, conf=conf_threshold)
        for result in secondary_results:
            if result.boxes is not None:
                for box, cls in zip(result.boxes.xyxy, result.boxes.cls):
                    cls = int(cls)
                    class_name = class_names_secondary.get(cls, None)
                    if class_name is not None and class_name.lower() == 'car':
                        detected_objects.append({
                            "class_name": class_name,
                            "box": box.cpu().numpy().tolist(),
                            "confidence": float(result.boxes.conf[0]) if result.boxes.conf is not None else 0.0
                        })
                        print(f"[DEBUG] ตรวจจับ {class_name} ด้วยโมเดลเพิ่มเติม ด้วยกล่อง {box.cpu().numpy()}")

        # รวมการตรวจจับรถยนต์จากทั้งสองโมเดล และใช้ Non-Max Suppression เพื่อลบการตรวจจับซ้ำ
        cars = [obj for obj in detected_objects if obj['class_name'].lower() == 'car']
        elephants = [obj for obj in detected_objects if obj['class_name'].lower() == 'elephant']

        if cars:
            boxes = [car['box'] for car in cars]
            confidences = [car['confidence'] for car in cars]
            indices = cv2.dnn.NMSBoxes(boxes, confidences, conf_threshold, 0.4)
            nms_cars = []
            if len(indices) > 0:
                for i in indices.flatten():
                    nms_cars.append(cars[i])
            print(f"[DEBUG] หลังจาก NMS: ตรวจจับรถยนต์ทั้งหมด {len(nms_cars)} ตัว")
        else:
            nms_cars = []

        # รวมผลการตรวจจับ
        final_detections = elephants + nms_cars
        print(f"[DEBUG] detect_objects: {len(final_detections)} วัตถุที่ตรวจจับได้")

        return final_detections
    except Exception as e:
        print(f"[ERROR in detect_objects]: {e}")
        return []

def create_test_payload(camera_id, camera_lat, camera_long, objects_detected, camera_bearing_degrees, elephant_distance_m, image_path):
    """
    สร้าง payload สำหรับส่งไปยังเซิร์ฟเวอร์

    Parameters:
    - camera_id: รหัสของกล้อง
    - camera_lat: ละติจูดของกล้อง
    - camera_long: ลองจิจูดของกล้อง
    - objects_detected: รายการวัตถุที่ตรวจจับ
    - camera_bearing_degrees: ทิศทางของกล้อง
    - elephant_distance_m: ระยะห่างของช้างในหน่วยเมตร
    - image_path: เส้นทางของรูปภาพ

    Returns:
    - payload: ข้อมูลในรูปแบบ dictionary
    """
    try:
        elephant_lat = None
        elephant_long = None
        alert = False
        elephant_detected = False
        car_detected = False

        if objects_detected:
            for obj in objects_detected:
                if obj['class_name'].lower() == 'elephant':
                    elephant_detected = True
                elif obj['class_name'].lower() == 'car':
                    car_detected = True

        if elephant_detected and elephant_distance_m is not None:
            elephant_lat, elephant_long = calculate_destination_latlong(camera_lat, camera_long, elephant_distance_m, camera_bearing_degrees)

        if elephant_detected and car_detected:
            alert = True

        image_data = encode_image(image_path)

        # รับเวลาปัจจุบันในเขตเวลาไทย
        th_tz = pytz.timezone('Asia/Bangkok')
        th_time = datetime.now(th_tz)
        timestamp = th_time.strftime("%Y-%m-%d %H:%M:%S")

        payload = {
            "camera_id": str(camera_id),
            "camera_lat": float(camera_lat),
            "camera_long": float(camera_long),
            "elephant": elephant_detected,
            "elephant_lat": float(elephant_lat) if elephant_detected and elephant_lat is not None else None,
            "elephant_long": float(elephant_long) if elephant_detected and elephant_long is not None else None,
            "elephant_distance": int(elephant_distance_m) if elephant_detected and elephant_distance_m is not None else None,
            "image": image_data,
            "alert": alert,
            "timestamp": timestamp
        }

        print("[DEBUG] Payload ถูกสร้างขึ้นพร้อม timestamp ของไทย:", timestamp)
        return payload
    except Exception as e:
        print(f"[ERROR in create_test_payload]: {e}")
        return {}

if __name__ == "__main__":
    # Debug Configuration
    CONFIG = {
        'image_path': '/content/ele_4.jpg',
        'road_model_path': 'Road.pt',
        'object_model_path_1': '/content/ELMoCa.pt',  # โมเดลหลักสำหรับ Elephant และ Car
        'object_model_path_2': '/content/car1class.pt',  # โมเดลเพิ่มเติมสำหรับ Car เท่านั้น
        'camera_id': 'TEST_CAM_001',
        'camera_lat': 13.736717,
        'camera_long': 100.523186,
        'bearing_degrees': 45.0,
    }

    PARAMS = {
        'confidence_threshold': 0.1,
        'standard_road_width_meters': 3.5,
        'distance_between_lines_meters': 10,  # ระยะห่างระหว่างเส้นเป็น 10 เมตร
        'max_distance_meters': 100,  # ระยะสูงสุดที่จะวาดเส้นเป็น 100 เมตร
        'output_path': '/content/processed_image.jpg'  # เพิ่มการบันทึกรูปภาพที่ประมวลผลแล้ว
    }

    try:
        # รับเวลาปัจจุบันในเขตเวลาไทย
        th_tz = pytz.timezone('Asia/Bangkok')
        current_time = datetime.now(th_tz)
        print(f"[DEBUG] เริ่มกระบวนการหลัก... (เวลาประเทศไทย: {current_time.strftime('%Y-%m-%d %H:%M:%S')})")

        # ประมวลผลถนนและรับตำแหน่งเส้น
        road_found, road_mask, processed_image, y_positions = process_road_image(CONFIG['image_path'], CONFIG['road_model_path'], PARAMS)

        # ตรวจสอบว่า road_mask ถูกสร้างขึ้นอย่างถูกต้อง
        if road_found:
            plt.figure(figsize=(5,5))
            plt.imshow(road_mask, cmap='gray')
            plt.title('Road Mask')
            plt.show()

        # โหลดโมเดลตรวจจับวัตถุทั้งสองโมเดล
        print("[DEBUG] กำลังโหลดโมเดลตรวจจับวัตถุ...")
        object_model_1 = YOLO(CONFIG['object_model_path_1'])  # โมเดลหลัก
        object_model_2 = YOLO(CONFIG['object_model_path_2'])  # โมเดลเพิ่มเติมสำหรับรถยนต์
        print("[DEBUG] โหลดโมเดลตรวจจับวัตถุเรียบร้อยแล้ว.")

        # รับชื่อคลาสจากแต่ละโมเดล
        class_names_primary = object_model_1.names
        class_names_secondary = object_model_2.names

        # ตรวจจับวัตถุ (ช้างและรถยนต์) โดยใช้ทั้งสองโมเดล
        objects_detected = detect_objects(
            CONFIG['image_path'],
            primary_model=object_model_1,
            secondary_model=object_model_2,
            class_names_primary=class_names_primary,
            class_names_secondary=class_names_secondary,
            conf_threshold=PARAMS['confidence_threshold']
        )

        # แสดงข้อความถ้าไม่พบวัตถุที่ตรวจจับ
        if not objects_detected:
            print("[DEBUG] ไม่พบวัตถุที่ตรวจจับ.")
        else:
            print(f"[DEBUG] พบวัตถุที่ตรวจจับได้: {len(objects_detected)} วัตถุ")

        # สร้างการแสดงผล
        plt.figure(figsize=(15, 10))

        # แสดงผลการตรวจจับถนน
        plt.subplot(2, 1, 1)
        if road_found and processed_image is not None:
            plt.imshow(processed_image)
            plt.title('Road Detection')
        else:
            original_image = cv2.imread(CONFIG['image_path'])
            original_image = cv2.cvtColor(original_image, cv2.COLOR_BGR2RGB)
            plt.imshow(original_image)
            plt.title('Original Image (No Road Detected)')

        # แสดงผลการตรวจจับวัตถุ
        plt.subplot(2, 1, 2)
        original_image = cv2.imread(CONFIG['image_path'])
        original_image = cv2.cvtColor(original_image, cv2.COLOR_BGR2RGB)
        detection_image = original_image.copy()

        # วาดวัตถุที่ตรวจจับได้
        for obj in objects_detected:
            box = obj['box']
            class_name = obj['class_name']
            x1, y1, x2, y2 = map(int, box)
            if class_name.lower() == 'elephant':
                color = (255, 0, 0)  # แดงสำหรับช้าง
            elif class_name.lower() == 'car':
                color = (0, 255, 0)  # เขียวสำหรับรถยนต์
            else:
                color = (0, 0, 255)  # แดงสำหรับวัตถุที่ไม่รู้จัก
            cv2.rectangle(detection_image, (x1, y1), (x2, y2), color, 2)
            cv2.putText(detection_image, class_name, (x1, y1-10), cv2.FONT_HERSHEY_SIMPLEX, 0.9, color, 2)

        plt.imshow(detection_image)
        plt.title('Object Detection')

        # เพิ่มข้อมูลสรุปด้านล่างสุด
        num_elephants = len([obj for obj in objects_detected if obj['class_name'].lower() == 'elephant'])
        num_cars = len([obj for obj in objects_detected if obj['class_name'].lower() == 'car'])
        image_shape = cv2.imread(CONFIG['image_path']).shape[:2][::-1]  # (width, height)
        processing_time = "190.7ms"  # คุณสามารถปรับปรุงให้บันทึกเวลาได้จริง

        summary_text = f"image 1/1 {CONFIG['image_path']}: {image_shape[1]}x{image_shape[0]} {num_elephants} Elephant, {num_cars} Car, {processing_time}"
        plt.figtext(0.5, 0.01, summary_text, wrap=True, horizontalalignment='center', fontsize=10)

        # เพิ่ม timestamp ลงในกราฟ
        plt.suptitle(f'Detection Results - {current_time.strftime("%Y-%m-%d %H:%M:%S")} (เวลาประเทศไทย)', fontsize=12)

        # แสดงกราฟทั้งหมด
        plt.tight_layout(rect=[0, 0.03, 1, 0.95])
        plt.show()

        # บันทึกรูปภาพที่ประมวลผลแล้วหากระบุ output_path
        if PARAMS['output_path'] and road_found and processed_image is not None:
            cv2.imwrite(PARAMS['output_path'], cv2.cvtColor(processed_image, cv2.COLOR_RGB2BGR))
            print(f"[DEBUG] บันทึกรูปภาพที่ประมวลผลแล้วไปยัง {PARAMS['output_path']}")

        # คำนวณระยะทางของช้างหากตรวจจับได้
        elephant_distance_m = None
        if objects_detected:
            for obj in objects_detected:
                if obj['class_name'].lower() == 'elephant' and y_positions:
                    x1, y1, x2, y2 = obj['box']
                    elephant_bottom_y = y2  # ใช้ y2 เป็นตำแหน่งล่างสุดของ bounding box
                    print(f"[DEBUG] ตำแหน่งล่างสุดของช้าง y: {elephant_bottom_y}")

                    y_positions_arr = np.array(y_positions)
                    diff = np.abs(y_positions_arr - elephant_bottom_y)
                    closest_line_idx = np.argmin(diff)
                    elephant_distance_m = PARAMS['distance_between_lines_meters'] * (closest_line_idx + 1)
                    print(f"[DEBUG] ช้างถูกตรวจจับที่ y={elephant_bottom_y}, ตำแหน่งเส้นที่ใกล้ที่สุด idx={closest_line_idx}, ระยะทาง={elephant_distance_m} เมตร")
                    break  # พิจารณาเฉพาะช้างตัวแรกที่ตรวจจับได้

        # สร้าง payload สำหรับส่งไปยังเซิร์ฟเวอร์
        SERVER_URL = "https://aprlabtop.com/elephant_api/Testapi.php"
        data = create_test_payload(
            camera_id=CONFIG['camera_id'],
            camera_lat=CONFIG['camera_lat'],
            camera_long=CONFIG['camera_long'],
            objects_detected=objects_detected,
            camera_bearing_degrees=CONFIG['bearing_degrees'],
            elephant_distance_m=elephant_distance_m,
            image_path=CONFIG['image_path']
        )

        # Debug

        # พิมพ์เฉพาะ 20 ตัวอักษรแรกของภาพ base64
        data_to_print = data.copy()
        if data_to_print.get("image") and data_to_print["image"] is not None:
            data_to_print["image"] = data_to_print["image"][:20] + "..."
        print("Payload:", json.dumps(data_to_print, indent=4))

        response = send_to_server(SERVER_URL, data)
        if response:
            if response.status_code == 200:
                print("ส่งข้อมูลเรียบร้อยแล้ว:", response.text)
            else:
                print("การส่งข้อมูลล้มเหลว. รหัสสถานะ:", response.status_code, "การตอบกลับ:", response.text)
        else:
            print("[DEBUG] ไม่ได้รับการตอบกลับจากเซิร์ฟเวอร์.")
    except Exception as e:
        print(f"[ERROR in __main__]: {e}")
