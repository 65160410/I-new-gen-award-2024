from ultralytics import YOLO
import cv2
import numpy as np
import time
import json
import math
from datetime import datetime
import pytz
import requests
import base64

def encode_frame_to_base64(frame):
    """Convert frame to base64 string"""
    try:
        _, buffer = cv2.imencode('.jpg', frame)
        return base64.b64encode(buffer).decode('utf-8')
    except Exception as e:
        print(f"[ERROR in encode_frame_to_base64]: {e}")
        return None

def detect_objects(frame, primary_model, secondary_model, class_names_primary, class_names_secondary, conf_threshold=0.5):
    """Modified detect_objects function to work with frames instead of image paths"""
    try:
        detected_objects = []
        
        # Convert frame to RGB for YOLO
        frame_rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
        
        # Detect with primary model
        primary_results = primary_model(frame_rgb, conf=conf_threshold)
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

        # Detect with secondary model (car specific)
        secondary_results = secondary_model(frame_rgb, conf=conf_threshold)
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

        # Combine and apply NMS
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
        else:
            nms_cars = []

        return elephants + nms_cars
    except Exception as e:
        print(f"[ERROR in detect_objects]: {e}")
        return []

def create_payload(camera_id, camera_lat, camera_long, objects_detected, frame=None):
    """Create payload for server with conditional base64 encoding"""
    try:
        elephant_detected = any(obj['class_name'].lower() == 'elephant' for obj in objects_detected)
        car_detected = any(obj['class_name'].lower() == 'car' for obj in objects_detected)
        
        # Get current time in Thai timezone
        th_tz = pytz.timezone('Asia/Bangkok')
        th_time = datetime.now(th_tz)
        timestamp = th_time.strftime("%Y-%m-%d %H:%M:%S")

        payload = {
            "camera_id": str(camera_id),
            "camera_lat": float(camera_lat),
            "camera_long": float(camera_long),
            "elephant": elephant_detected,
            "elephant_lat": None,  # Add actual calculation if needed
            "elephant_long": None,  # Add actual calculation if needed
            "elephant_distance": None,  # Add actual calculation if needed
            "image": encode_frame_to_base64(frame) if elephant_detected and frame is not None else None,
            "alert": elephant_detected and car_detected,
            "timestamp": timestamp
        }
        
        return payload
    except Exception as e:
        print(f"[ERROR in create_payload]: {e}")
        return {}

def main():
    # Configuration
    CONFIG = {
        'camera_source': 0,
        'object_model_path_1': '/content/ELMoCa.pt',
        'object_model_path_2': '/content/car1class.pt',
        'camera_id': 'TEST_CAM_001',
        'camera_lat': 13.736717,
        'camera_long': 100.523186,
        'server_url': "https://aprlabtop.com/elephant_api/Testapi.php",
        'detection_interval': 30  # seconds
    }

    try:
        # Load models
        print("[DEBUG] Loading detection models...")
        object_model_1 = YOLO(CONFIG['object_model_path_1'])
        object_model_2 = YOLO(CONFIG['object_model_path_2'])
        
        # Get class names
        class_names_primary = object_model_1.names
        class_names_secondary = object_model_2.names

        # Initialize camera
        print("[DEBUG] Initializing camera...")
        cap = cv2.VideoCapture(CONFIG['camera_source'])
        if not cap.isOpened():
            raise Exception("Could not open camera")

        last_detection_time = 0

        print("[DEBUG] Starting detection loop...")
        while True:
            current_time = time.time()
            
            # Check if it's time for detection
            if current_time - last_detection_time >= CONFIG['detection_interval']:
                ret, frame = cap.read()
                if not ret:
                    print("[ERROR] Failed to grab frame")
                    continue

                # Perform detection
                objects_detected = detect_objects(
                    frame,
                    object_model_1,
                    object_model_2,
                    class_names_primary,
                    class_names_secondary
                )

                # Create and send payload
                payload = create_payload(
                    CONFIG['camera_id'],
                    CONFIG['camera_lat'],
                    CONFIG['camera_long'],
                    objects_detected,
                    frame
                )

                # Send to server
                try:
                    response = requests.post(
                        CONFIG['server_url'],
                        json=payload,
                        headers={'Content-Type': 'application/json'}
                    )
                    print(f"[DEBUG] Server response: {response.status_code}")
                except Exception as e:
                    print(f"[ERROR] Failed to send to server: {e}")

                last_detection_time = current_time

            # Small delay to prevent CPU overload
            time.sleep(0.1)

    except KeyboardInterrupt:
        print("\n[INFO] Stopping detection...")
    except Exception as e:
        print(f"[ERROR] in main: {e}")
    finally:
        if 'cap' in locals():
            cap.release()
        cv2.destroyAllWindows()

if __name__ == "__main__":
    main()
