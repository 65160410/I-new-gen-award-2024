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

def draw_perspective_lines(image, road_mask, standard_road_width_meters=3.5, num_lines=50):
    try:
        height, width = road_mask.shape[:2]
        road_pixels = np.where(road_mask > 0)
        if len(road_pixels[0]) == 0:
            print("[DEBUG] No road pixels found.")
            return image, []

        top_y = np.min(road_pixels[0])
        bottom_y = np.max(road_pixels[0])
        road_height_pixels = bottom_y - top_y

        y_positions = []
        if num_lines > 1:
            for i in range(num_lines):
                y = int(bottom_y - i * (road_height_pixels / (num_lines - 1)))
                y_positions.append(y)
        else:
            y_positions.append(bottom_y)
        
        result_image = image.copy()
        road_mask_3d = np.stack([road_mask] * 3, axis=2)
        result_image = cv2.addWeighted(result_image, 0.7, (road_mask_3d > 0).astype(np.uint8) * image, 0.3, 0)

        for idx, y in enumerate(y_positions):
            row_pixels = np.where(road_mask[y, :] > 0)[0]
            if len(row_pixels) > 0:
                left_x = row_pixels[0]
                right_x = row_pixels[-1]
                progress = idx / (num_lines - 1) if num_lines > 1 else 0
                thickness = int(2 + (1 - progress) * 3)
                cv2.line(result_image, (left_x, y), (right_x, y), (255, 255, 255), thickness)
        
        return result_image, y_positions
    except Exception as e:
        print(f"[ERROR in draw_perspective_lines]: {e}")
        return image, []

def process_road_image(image_path, model_path, params):
    try:
        print("[DEBUG] Loading road model...")
        road_model = YOLO(model_path)
        print("[DEBUG] Road model loaded. Running inference...")
        road_results = road_model(image_path, conf=params['confidence_threshold'])
        print("[DEBUG] Inference done.")

        if isinstance(road_results, list) and len(road_results) > 0:
            road_result = road_results[0]
            class_names = road_model.names

            # Find class id for 'road'
            road_class_id = None
            for cls_id, cls_name in class_names.items():
                if cls_name.lower() == 'road':
                    road_class_id = cls_id
                    break
            print(f"[DEBUG] road_class_id: {road_class_id}")

            image = cv2.imread(image_path)
            if image is None:
                print(f"[ERROR] Image not found or could not be loaded: {image_path}")
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

            if np.sum(road_mask) > 0:
                print("[DEBUG] Road mask found. Drawing perspective lines...")
                result_image, y_positions = draw_perspective_lines(
                    image, 
                    road_mask,
                    params['standard_road_width_meters'],
                    num_lines=50
                )

                if params['output_path']:
                    try:
                        plt.imsave(params['output_path'], result_image)
                        print(f"Saved result to {params['output_path']}")
                    except Exception as e:
                        print(f"[ERROR] Saving image failed: {e}")

                return True, road_mask, result_image, y_positions
            else:
                print("[DEBUG] No 'road' segment found in the image.")
                return False, None, image, []
        else:
            print("[DEBUG] No results from the road model.")
            return False, None, None, []
    except Exception as e:
        print(f"[ERROR in process_road_image]: {e}")
        return False, None, None, []

def encode_image(image_path):
    try:
        with open(image_path, "rb") as img_file:
            data = base64.b64encode(img_file.read()).decode('utf-8')
            print(f"[DEBUG] Image encoded. Length: {len(data)} chars.")
            return data
    except FileNotFoundError:
        print(f"[ERROR] Image file not found: {image_path}")
        return None
    except Exception as e:
        print(f"[ERROR in encode_image]: {e}")
        return None

def send_to_server(url, data):
    headers = {'Content-Type': 'application/json'}
    print("[DEBUG] Sending data to server...")
    try:
        response = requests.post(url, json=data, headers=headers)
        print("[DEBUG] Response received.")
        return response
    except requests.exceptions.RequestException as e:
        print(f"[ERROR sending data to server]: {e}")
        return None

def calculate_destination_latlong(lat, lon, distance_m, bearing_degrees):
    try:
        R = 6378137.0  # Radius of Earth in meters
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

def detect_elephant(image_path, elephant_model_path, conf_threshold=0.5):
    try:
        elephant_model = YOLO(elephant_model_path)
        results = elephant_model(image_path, conf=conf_threshold)
        elephant_detected = False
        elephant_boxes = []

        for result in results:
            if result.boxes is not None:
                for box, cls in zip(result.boxes.xyxy, result.boxes.cls):
                    if int(cls) == 0:  # Elephant class = 0
                        elephant_detected = True
                        elephant_boxes.append(box.cpu().numpy())

        print(f"[DEBUG] detect_elephant: {elephant_detected}, boxes={len(elephant_boxes)}")
        return elephant_detected, elephant_boxes
    except Exception as e:
        print(f"[ERROR in detect_elephant]: {e}")
        return False, []

def detect_car(image_path, car_model_path, conf_threshold=0.5):
    try:
        car_model = YOLO(car_model_path)
        results = car_model(image_path, conf=conf_threshold)
        car_detected = False
        car_boxes = []

        class_names = car_model.names
        car_class_id = None
        for cls_id, cls_name in class_names.items():
            if cls_name.lower() == 'car':
                car_class_id = cls_id
                break

        if car_class_id is not None:
            for result in results:
                if result.boxes is not None:
                    for box, cls in zip(result.boxes.xyxy, result.boxes.cls):
                        if int(cls) == car_class_id:
                            car_detected = True
                            car_boxes.append(box.cpu().numpy())

        print(f"[DEBUG] detect_car: {car_detected}, boxes={len(car_boxes)}")
        return car_detected, car_boxes
    except Exception as e:
        print(f"[ERROR in detect_car]: {e}")
        return False, []

def create_test_payload(camera_id, camera_lat, camera_long, elephant_detected, car_detected, camera_bearing_degrees, elephant_distance_m, image_path):
    try:
        elephant_lat = None
        elephant_long = None
        if elephant_detected and elephant_distance_m is not None:
            elephant_lat, elephant_long = calculate_destination_latlong(camera_lat, camera_long, elephant_distance_m, camera_bearing_degrees)

        alert = False
        if elephant_detected and car_detected:
            alert = True

        image_data = encode_image(image_path)

        payload = {
            "camera_id": camera_id,
            "camera_lat": camera_lat,
            "camera_long": camera_long,
            "elephant": elephant_detected,
            "elephant_lat": elephant_lat if elephant_detected else None,
            "elephant_long": elephant_long if elephant_detected else None,
            "elephant_distance": elephant_distance_m if elephant_detected else None,
            "image": image_data,
            "alert": alert,
            "timestamp": time.strftime("%Y-%m-%d %H:%M:%S", time.localtime())
        }

        print("[DEBUG] Payload created.")
        return payload
    except Exception as e:
        print(f"[ERROR in create_test_payload]: {e}")
        return {}

if __name__ == "__main__":
    # Debug Configuration
    CONFIG = {
        'image_path': 'alert02.jpg',
        'road_model_path': 'Road.pt',
        'elephant_model_path': 'Model_True.pt',
        'car_model_path': 'car.pt',
        'output_path': None,
        'camera_id': 'TEST_CAM_001',
        'camera_lat': 13.736717,
        'camera_long': 100.523186,
        'bearing_degrees': 45.0,
    }

    PARAMS = {
        'confidence_threshold': 0.5,
        'standard_road_width_meters': 3.5,
        'output_path': CONFIG['output_path']
    }

    try:
        print("[DEBUG] Starting main process...")
        # Process road and get line positions
        road_found, road_mask, processed_image, y_positions = process_road_image(CONFIG['image_path'], CONFIG['road_model_path'], PARAMS)
        
        # Detect elephant and car with bounding boxes
        elephant_detected, elephant_boxes = detect_elephant(CONFIG['image_path'], CONFIG['elephant_model_path'], conf_threshold=PARAMS['confidence_threshold'])
        car_detected, car_boxes = detect_car(CONFIG['image_path'], CONFIG['car_model_path'], conf_threshold=PARAMS['confidence_threshold'])

        # Calculate elephant distance if detected
        elephant_distance_m = None
        if elephant_detected and len(elephant_boxes) > 0 and len(y_positions) > 0:
            x1, y1, x2, y2 = elephant_boxes[0]
            elephant_center_y = (y1 + y2) / 2.0
            y_positions_arr = np.array(y_positions)
            diff = np.abs(y_positions_arr - elephant_center_y)
            closest_line_idx = np.argmin(diff)
            elephant_distance_m = closest_line_idx  # closest_line_idx is the meter distance

        SERVER_URL = "https://aprlabtop.com/elephant_api/Testapi.php"
        data = create_test_payload(
            camera_id=CONFIG['camera_id'],
            camera_lat=CONFIG['camera_lat'],
            camera_long=CONFIG['camera_long'],
            elephant_detected=elephant_detected,
            car_detected=car_detected,
            camera_bearing_degrees=CONFIG['bearing_degrees'],
            elephant_distance_m=elephant_distance_m,
            image_path=CONFIG['image_path']
        )

        # Debug print only first 20 chars of the base64 image
        data_to_print = data.copy()
        if data_to_print.get("image") and data_to_print["image"] is not None:
            data_to_print["image"] = data_to_print["image"][:20] + "..."
        print("Payload:", json.dumps(data_to_print, indent=4))

        response = send_to_server(SERVER_URL, data)
        if response:
            if response.status_code == 200:
                print("Data sent successfully:", response.text)
            else:
                print("Failed to send data. Status code:", response.status_code, "Response:", response.text)
        else:
            print("[DEBUG] No response from server.")
    except Exception as e:
        print(f"[ERROR in __main__]: {e}")
