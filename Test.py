import requests
import base64
import cv2
import json
from ultralytics import YOLO
import time
import os

# โหลดโมเดล YOLOv8 (ปรับชื่อโมเดลให้ตรงกับของคุณ)
model = YOLO('ELMoCa.pt')

def detect_objects(image_path):
    results = model.predict(source=image_path, conf=0.5)
    return results[0]

def draw_bboxes_and_save(image_path, boxes, labels):
    img = cv2.imread(image_path)
    for box, label in zip(boxes, labels):
        x1, y1, x2, y2 = box
        cv2.rectangle(img, (int(x1), int(y1)), (int(x2), int(y2)), (0,255,0), 2)
        cv2.putText(img, label, (int(x1), int(y1)-10), cv2.FONT_HERSHEY_SIMPLEX, 
                    0.9, (0,255,0), 2)
    output_path = 'detected.jpg'
    cv2.imwrite(output_path, img)
    return output_path

def encode_image_to_base64(image_path):
    with open(image_path, "rb") as img_file:
        b64_string = base64.b64encode(img_file.read()).decode('utf-8')
    return b64_string

def send_data_to_server(url, data):
    headers = {'Content-Type': 'application/json'}
    response = requests.post(url, json=data, headers=headers)
    return response

if __name__ == '__main__':
    # ภาพทดสอบ
    image_path = './datasetChang/73092_0.jpg'  # ใส่ชื่อภาพที่ต้องการตรวจจับ
    if not os.path.exists(image_path):
        print("Input image not found.")
        exit()

    results = detect_objects(image_path)

    # ตรวจสอบว่ามีการตรวจจับวัตถุหรือไม่
    if len(results.boxes) > 0:
        boxes = results.boxes.xyxy.cpu().numpy()
        cls_ids = results.boxes.cls.cpu().numpy()
        class_names = results.names
        detected_labels = [class_names[int(cls_id)] for cls_id in cls_ids]
        
        # เพิ่มการพิมพ์ชื่อ label เพื่อการ Debug
        print("Detected labels:", detected_labels)

        # ตรวจสอบว่าพบ 'elephant' หรือ 'car' หรือไม่ (แบบไม่สนใจตัวพิมพ์)
        send_flag = any(lbl.lower() in ["elephant", "car"] for lbl in detected_labels)

        if send_flag:
            output_path = draw_bboxes_and_save(image_path, boxes, detected_labels)
            b64_image = encode_image_to_base64(output_path)

            # สร้าง JSON data
            data = {
                "timestamp": time.strftime("%Y-%m-%d %H:%M:%S", time.localtime()),
                "detections": [],
                "image": b64_image
            }
            for lbl, box in zip(detected_labels, boxes):
                x1, y1, x2, y2 = box
                data["detections"].append({
                    "label": lbl,
                    "bbox": [float(x1), float(y1), float(x2), float(y2)]
                })

            # พิมพ์ JSON data เพื่อการ Debug
            print("JSON Data to send:", json.dumps(data, indent=4))

            # URL ของ server PHP (ปรับตาม host ของคุณ)
            server_url = "https://aprlabtop.com/WaveTest_API/Testapi.php"
            response = send_data_to_server(server_url, data)
            if response.status_code == 200:
                print("Data sent successfully:", response.text)
            else:
                print("Failed to send data. Status code:", response.status_code, "Response:", response.text)
        else:
            print("No elephant or car detected. Detected labels:", detected_labels)
    else:
        print("No objects detected.")
