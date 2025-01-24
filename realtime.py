"""
This script captures frames from a camera, processes them to detect roads and objects (elephants and cars), 
draws perspective lines on the road, and sends the detection results to a server.

Modules and Libraries:
- ultralytics: For YOLO model loading and inference.
- cv2: OpenCV for image processing and camera handling.
- numpy: For numerical operations.
- matplotlib.pyplot: For plotting (not used in the main script).
- torch: For PyTorch operations.
- requests: For sending HTTP requests.
- base64: For encoding images.
- time, datetime, pytz: For time-related operations.
- os, tempfile: For file handling.

Functions:
- draw_perspective_lines: Draws perspective lines on the road based on the road mask.
- process_road_image: Processes the image to detect roads and draw perspective lines.
- encode_image: Encodes an image to Base64.
- send_to_server: Sends data to the server via POST request.
- calculate_destination_latlong: Calculates new latitude and longitude based on distance and bearing.
- convert_to_native_types: Recursively converts NumPy data types in a structure to native Python types.
- detect_objects: Detects objects (elephants and cars) using the primary YOLO model.
- create_test_payload: Creates a payload for the API.
- process_frame: Captures a frame from the camera, processes it, and sends API requests based on detections.
- main: Initializes models, camera, and starts the main loop to process frames every 10 seconds while displaying a live feed.

Main Workflow:
1. Initialize camera and YOLO models.
2. Capture frames from the camera.
3. Process each frame to detect roads and objects.
4. Draw perspective lines on the road if detected.
5. Create a payload with detection results and send it to the server.
6. Display the processed frame with detection results.
7. Repeat the process every 10 seconds.
"""

from ultralytics import YOLO
import cv2
import numpy as np
import torch
import requests
import base64
import time
import json
import math
from datetime import datetime
import pytz
import os
import tempfile
import csv

# --- [Utility Functions] ---

def draw_perspective_lines(image, road_mask, standard_road_width_meters=3.5, distance_between_lines_meters=10, max_distance_meters=100):
    """
    Draws perspective lines on the road based on the road mask.

    Parameters:
    - image: Original RGB image.
    - road_mask: Binary mask of the road.
    - standard_road_width_meters: Standard road width in meters.
    - distance_between_lines_meters: Distance between lines in meters.
    - max_distance_meters: Maximum distance to draw lines in meters.

    Returns:
    - result_image: Image with perspective lines drawn.
    - y_positions: Y-coordinates of the drawn lines.
    """
    try:
        height, width = road_mask.shape[:2]
        road_pixels = np.where(road_mask > 0)
        if len(road_pixels[0]) == 0:
            print("[DEBUG] No road pixels found.")
            return image, []

        top_y = int(np.min(road_pixels[0]))
        bottom_y = int(np.max(road_pixels[0]))
        road_height_pixels = bottom_y - top_y

        print(f"[DEBUG] top_y: {top_y}, bottom_y: {bottom_y}, road_height_pixels: {road_height_pixels}")

        if road_height_pixels <= 0:
            print("[DEBUG] Invalid road_height_pixels. Cannot draw lines.")
            return image, []

        def calculate_y_position(distance_meters):
            alpha = 0.03
            relative_position = 1 - math.exp(-alpha * distance_meters)
            y = int(bottom_y - (relative_position * road_height_pixels))
            return max(top_y, min(bottom_y, y))

        distances_meters = list(range(distance_between_lines_meters, max_distance_meters + distance_between_lines_meters, distance_between_lines_meters))
        print(f"[DEBUG] distances_meters: {distances_meters}")

        y_positions = []
        for distance in distances_meters:
            y = calculate_y_position(distance)
            if y < top_y:
                print(f"[DEBUG] y={y} is above top_y={top_y}. Stopping line drawing.")
                break
            y_positions.append(y)
        print(f"[DEBUG] y_positions: {y_positions}")

        if not y_positions:
            print("[DEBUG] No y_positions calculated. No lines to draw.")
            return image, []

        result_image = image.copy()
        road_mask_3d = np.stack([road_mask] * 3, axis=2)
        result_image = cv2.addWeighted(result_image, 0.7, (road_mask_3d > 0).astype(np.uint8) * image, 0.3, 0)

        for y, distance in zip(y_positions, distances_meters):
            if y < 0 or y >= height:
                print(f"[DEBUG] Skipping y out of bounds: y={y}")
                continue
            row_pixels = np.where(road_mask[y, :] > 0)[0]
            if len(row_pixels) > 0:
                left_x = int(row_pixels[0])
                right_x = int(row_pixels[-1])
                relative_distance = distance / max_distance_meters
                thickness = max(1, int(3 * (1 - relative_distance)))
                cv2.line(result_image, (left_x, y), (right_x, y), (255, 255, 255), thickness)
                print(f"[DEBUG] Drew line at y={y} (distance={distance}m) from x={left_x} to x={right_x} with thickness={thickness}")

                text = f"{distance}m"
                font = cv2.FONT_HERSHEY_SIMPLEX
                font_scale = max(0.4, 0.8 * (1 - relative_distance))
                text_thickness = max(1, int(2 * (1 - relative_distance)))
                text_size, _ = cv2.getTextSize(text, font, font_scale, text_thickness)
                text_x = right_x - text_size[0] - 10
                text_y = y - 10 if y > text_size[1] + 10 else y + text_size[1] + 10

                cv2.putText(result_image, text, (text_x, text_y), font, font_scale, (255, 255, 255), text_thickness, cv2.LINE_AA)
                print(f"[DEBUG] Added text '{text}' at ({text_x}, {text_y})")
            else:
                print(f"[DEBUG] No road pixels found at y={y}")

        return result_image, y_positions
    except Exception as e:
        print(f"[ERROR in draw_perspective_lines]: {e}")
        return image, []

def load_model(model_path, conf_threshold=0.5):
    """
    Loads a YOLO model from the specified path.

    Parameters:
    - model_path: Path to the YOLO model.
    - conf_threshold: Confidence threshold for detections.

    Returns:
    - model: Loaded YOLO model.
    """
    try:
        print(f"[DEBUG] Loading YOLO model from {model_path}...")
        model = YOLO(model_path)
        print("[DEBUG] YOLO model loaded successfully.")
        return model
    except Exception as e:
        print(f"[ERROR] Failed to load model {model_path}: {e}")
        return None

def process_road_image(image_path, road_model, params):
    """
    Processes the image to detect roads and draw perspective lines.

    Parameters:
    - image_path: Path to the image.
    - road_model: YOLO model for road detection.
    - params: Dictionary of parameters.

    Returns:
    - road_found: Boolean indicating if road is found.
    - road_mask: Binary road mask.
    - result_image: Image with perspective lines drawn.
    - y_positions: Y-coordinates of the drawn lines.
    """
    try:
        print("[DEBUG] Running road detection inference...")
        road_results = road_model(image_path, conf=params['confidence_road'])
        print("[DEBUG] Road detection inference completed.")

        if isinstance(road_results, list) and len(road_results) > 0:
            road_result = road_results[0]
            class_names = road_model.names

            road_class_id = get_class_id('road', class_names)
            print(f"[DEBUG] road_class_id: {road_class_id}")

            image = cv2.imread(image_path)
            if image is None:
                print(f"[ERROR] Image not found or cannot be loaded: {image_path}")
                return False, None, None, []

            image_rgb = cv2.cvtColor(image, cv2.COLOR_BGR2RGB)
            road_mask = create_road_mask(road_result, road_class_id, image_rgb.shape[:2])

            print(f"[DEBUG] road_mask sum: {np.sum(road_mask)}")
            if np.sum(road_mask) == 0:
                print("[DEBUG] road_mask is empty. No road found.")

            if np.sum(road_mask) > 0:
                print("[DEBUG] road_mask found. Drawing perspective lines...")
                result_image, y_positions = draw_perspective_lines(
                    image_rgb,
                    road_mask,
                    params['standard_road_width_meters'],
                    distance_between_lines_meters=params['distance_between_lines_meters'],
                    max_distance_meters=params.get('max_distance_meters', 100)
                )
                return True, road_mask, result_image, y_positions
            else:
                print("[DEBUG] 'road' section not found in the image.")
                return False, None, image_rgb, []
        else:
            print("[DEBUG] No road detection results.")
            image = cv2.imread(image_path)
            if image is not None:
                image_rgb = cv2.cvtColor(image, cv2.COLOR_BGR2RGB)
                return False, None, image_rgb, []
            else:
                print(f"[ERROR] Image not found or cannot be loaded: {image_path}")
                return False, None, None, []
    except Exception as e:
        print(f"[ERROR in process_road_image]: {e}")
        return False, None, None, []

def get_class_id(target_class, class_names):
    """
    Retrieves the class ID for the specified target class.

    Parameters:
    - target_class: Name of the target class.
    - class_names: Dictionary mapping class IDs to class names.

    Returns:
    - class_id: ID of the target class or None if not found.
    """
    for cls_id, cls_name in class_names.items():
        if cls_name.lower() == target_class.lower():
            return cls_id
    return None

def create_road_mask(road_result, road_class_id, image_shape):
    """
    Creates a binary mask for the road based on detection results.

    Parameters:
    - road_result: Detection result from YOLO.
    - road_class_id: Class ID for the road.
    - image_shape: Shape of the original image.

    Returns:
    - road_mask: Binary mask of the road.
    """
    road_mask = np.zeros(image_shape, dtype=np.uint8)
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
    return road_mask

def encode_image(image_path):
    """
    Encodes an image to Base64.

    Parameters:
    - image_path: Path to the image.

    Returns:
    - Base64 encoded string of the image or None if encoding fails.
    """
    try:
        with open(image_path, "rb") as img_file:
            data = base64.b64encode(img_file.read()).decode('utf-8')
            print(f"[DEBUG] Image encoded. Length: {len(data)} characters.")
            return data
    except FileNotFoundError:
        print(f"[ERROR] Image file not found: {image_path}")
        return None
    except Exception as e:
        print(f"[ERROR in encode_image]: {e}")
        return None

def send_to_server(url, data):
    """
    Sends data to the server via POST request.

    Parameters:
    - url: Server URL.
    - data: Data payload.

    Returns:
    - Server response or None if request fails.
    """
    headers = {'Content-Type': 'application/json'}
    print("[DEBUG] Sending data to server...")
    try:
        response = requests.post(url, json=data, headers=headers)
        print("[DEBUG] Received response from server.")
        return response
    except requests.exceptions.RequestException as e:
        print(f"[ERROR Sending data to server]: {e}")
        return None

def calculate_destination_latlong(lat, lon, distance_m, bearing_degrees):
    """
    Calculates new latitude and longitude based on distance and bearing.

    Parameters:
    - lat: Current latitude.
    - lon: Current longitude.
    - distance_m: Distance in meters.
    - bearing_degrees: Bearing in degrees.

    Returns:
    - Tuple of new latitude and longitude or (None, None) if calculation fails.
    """
    try:
        R = 6378137.0  # Earth's radius in meters
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

def convert_to_native_types(obj):
    """
    Recursively converts NumPy data types in a structure to native Python types.

    Parameters:
    - obj: The input data structure.

    Returns:
    - The converted data structure with native Python types.
    """
    if isinstance(obj, np.integer):
        return int(obj)
    elif isinstance(obj, np.floating):
        return float(obj)
    elif isinstance(obj, np.ndarray):
        return obj.tolist()
    elif isinstance(obj, list):
        return [convert_to_native_types(item) for item in obj]
    elif isinstance(obj, dict):
        return {key: convert_to_native_types(value) for key, value in obj.items()}
    else:
        return obj

def detect_objects(image_path, primary_model, class_names_primary, conf_threshold=0.5):
    """
    Detects objects (elephants and cars) using the primary YOLO model.

    Parameters:
    - image_path: Path to the image.
    - primary_model: Primary YOLO model.
    - class_names_primary: Class names from the primary model.
    - conf_threshold: Confidence threshold.

    Returns:
    - List of detected objects.
    """
    try:
        detected_objects = []

        # Detect with primary model
        print("[DEBUG] Running detection with primary model...")
        primary_results = primary_model(image_path, conf=conf_threshold)
        for result in primary_results:
            if result.boxes is not None:
                for box, cls, conf in zip(result.boxes.xyxy, result.boxes.cls, result.boxes.conf):
                    cls = int(cls)
                    class_name = class_names_primary.get(cls, None)
                    if class_name is not None and class_name.lower() in ['elephant', 'car']:
                        # Get the confidence for this class
                        confidence = float(conf) if conf is not None else 0.0
                        detected_objects.append({
                            "class_name": class_name,
                            "box": [float(coord) for coord in box.cpu().numpy()],
                            "confidence": confidence
                        })
                        print(f"[DEBUG] Detected {class_name} with box {box.cpu().numpy()} and confidence {confidence} using primary model.")

        # Apply Non-Max Suppression (NMS) to remove duplicate car detections
        cars = [obj for obj in detected_objects if obj['class_name'].lower() == 'car']
        elephants = [obj for obj in detected_objects if obj['class_name'].lower() == 'elephant']

        if cars:
            boxes = [car['box'] for car in cars]
            confidences = [car['confidence'] for car in cars]
            # Convert boxes to list of [x, y, width, height] for cv2.dnn.NMSBoxes
            boxes_nms = [[box[0], box[1], box[2] - box[0], box[3] - box[1]] for box in boxes]
            indices = cv2.dnn.NMSBoxes(boxes_nms, confidences, conf_threshold, 0.4)
            nms_cars = []
            if len(indices) > 0:
                for i in indices.flatten():
                    nms_cars.append(cars[i])
            print(f"[DEBUG] After NMS: {len(nms_cars)} cars detected.")
        else:
            nms_cars = []

        final_detections = elephants + nms_cars
        print(f"[DEBUG] Total objects detected: {len(final_detections)}")

        return final_detections
    except Exception as e:
        print(f"[ERROR in detect_objects]: {e}")
        return []

def create_test_payload(camera_id, camera_lat, camera_long, objects_detected, camera_bearing_degrees, y_positions, params, image_path=None):
    """
    Creates a payload for the API.

    Parameters:
    - camera_id: Camera ID.
    - camera_lat: Camera latitude.
    - camera_long: Camera longitude.
    - objects_detected: List of detected objects.
    - camera_bearing_degrees: Camera bearing in degrees.
    - y_positions: Y-coordinates of perspective lines.
    - params: Parameters dictionary.
    - image_path: Path to the image (optional).

    Returns:
    - Payload dictionary.
    """
    try:
        elephant_lats = []
        elephant_longs = []
        elephant_distances = []
        car_count = 0
        elephant_count = 0
        alert = False

        th_tz = pytz.timezone('Asia/Bangkok')
        th_time = datetime.now(th_tz)
        timestamp = th_time.strftime("%Y-%m-%d %H:%M:%S")

        if objects_detected:
            for obj in objects_detected:
                if obj['class_name'].lower() == 'elephant':
                    elephant_count += 1
                    elephant_distance_m = calculate_elephant_distance(obj, y_positions, params)
                    if elephant_distance_m is not None:
                        lat, lon = calculate_destination_latlong(camera_lat, camera_long, elephant_distance_m, camera_bearing_degrees)
                        if lat is not None and lon is not None:
                            elephant_lats.append(float(lat))
                            elephant_longs.append(float(lon))
                            elephant_distances.append(float(elephant_distance_m))
                    else:
                        elephant_lats.append(None)
                        elephant_longs.append(None)
                        elephant_distances.append(None)
                elif obj['class_name'].lower() == 'car':
                    car_count += 1

        if elephant_count > 0 and car_count > 0:
            alert = True

        # Encode image if at least one elephant is detected
        image_data = encode_image(image_path) if elephant_count > 0 and image_path is not None else None

        payload = {
            "camera_id": str(camera_id),
            "camera_lat": float(camera_lat),
            "camera_long": float(camera_long),
            "elephant": elephant_count > 0,
            "elephant_lat": elephant_lats if elephant_count > 0 else [],
            "elephant_long": elephant_longs if elephant_count > 0 else [],
            "elephant_distance": elephant_distances if elephant_count > 0 else [],
            "car_count": int(car_count),
            "elephant_count": int(elephant_count),
            "image": image_data,
            "alert": bool(alert),
            "timestamp": timestamp
        }

        print("[DEBUG] Payload created with Thailand timestamp:", timestamp)
        return payload
    except Exception as e:
        print(f"[ERROR in create_test_payload]: {e}")
        return {}

def calculate_elephant_distance(obj, y_positions, params):
    """
    Calculates the distance of an elephant based on its bounding box and perspective lines.

    Parameters:
    - obj: Detected elephant object.
    - y_positions: Y-coordinates of perspective lines.
    - params: Parameters dictionary.

    Returns:
    - Distance in meters or None if calculation fails.
    """
    try:
        if y_positions:
            # Extract the bottom y-coordinate of the bounding box
            _, _, _, y2 = map(int, obj['box'])
            print(f"[DEBUG] Elephant bottom y-coordinate: {y2}")

            # Calculate distance based on the closest perspective line
            diff = np.abs(np.array(y_positions) - y2)
            closest_line_idx = np.argmin(diff)
            elephant_distance_m = float(params['distance_between_lines_meters'] * (int(closest_line_idx) + 1))
            print(f"[DEBUG] Elephant detected at y={y2}, closest line index={closest_line_idx}, distance={elephant_distance_m} meters")
            return elephant_distance_m
        else:
            print("[DEBUG] y_positions unavailable. Skipping distance calculation.")
            return None
    except Exception as e:
        print(f"[ERROR in calculate_elephant_distance]: {e}")
        return None

def create_road_detection_model(model_path):
    """
    Loads the road detection YOLO model.

    Parameters:
    - model_path: Path to the road detection model.

    Returns:
    - Loaded YOLO model or None if loading fails.
    """
    return load_model(model_path)

def create_object_detection_model(model_path):
    """
    Loads the object detection YOLO model.

    Parameters:
    - model_path: Path to the object detection model.

    Returns:
    - Loaded YOLO model or None if loading fails.
    """
    return load_model(model_path)

# --- [Main Processing Function] ---

def process_frame(camera, CONFIG, PARAMS, object_model_1, class_names_primary, SERVER_URL, road_model):
    """
    Captures a frame from the camera, processes it, and sends API requests based on detections.

    Parameters:
    - camera: OpenCV VideoCapture object.
    - CONFIG: Configuration dictionary.
    - PARAMS: Parameters dictionary.
    - object_model_1: Primary YOLO model.
    - class_names_primary: Class names from the primary model.
    - SERVER_URL: API endpoint URL.
    - road_model: Road detection YOLO model.
    """
    ret, frame = camera.read()
    if not ret:
        print("[ERROR] Failed to capture image from camera.")
        return

    # Save the captured frame to a temporary file
    temp_image_path = save_frame_to_temp(frame)
    if not temp_image_path:
        print("[ERROR] Failed to save captured frame.")
        return

    try:
        # Process road image
        road_found, road_mask, processed_image, y_positions = process_road_image(
            temp_image_path,
            road_model,
            PARAMS
        )

        # Perform object detection regardless of road_found
        objects_detected = detect_objects(
            temp_image_path,
            primary_model=object_model_1,
            class_names_primary=class_names_primary,
            conf_threshold=PARAMS['confidence_object']
        )
        print("[DEBUG] Object detection completed regardless of road detection.")

        if not objects_detected:
            print("[DEBUG] No objects detected.")
        else:
            print(f"[DEBUG] Detected {len(objects_detected)} objects.")

        # Create payload
        image_needed = any(obj['class_name'].lower() == 'elephant' for obj in objects_detected)
        data = create_test_payload(
            camera_id=CONFIG['camera_id'],
            camera_lat=CONFIG['camera_lat'],
            camera_long=CONFIG['camera_long'],
            objects_detected=objects_detected,
            camera_bearing_degrees=CONFIG['bearing_degrees'],
            y_positions=y_positions,
            params=PARAMS,
            image_path=temp_image_path if image_needed else None
        )

        # Convert all data to native Python types to ensure JSON serialization
        data = convert_to_native_types(data)

        # Debug: Print payload (limit image data)
        data_to_print = data.copy()
        if data_to_print.get("image") and data_to_print["image"] is not None:
            data_to_print["image"] = data_to_print["image"][:20] + "..."
        print("Payload:", json.dumps(data_to_print, indent=4))

        # Send data to server
        response = send_to_server(SERVER_URL, data)
        handle_server_response(response)

        # Store data in a .txt file 
        log_data(data)
    
        # Overlay detection results on the frame for display
        frame_display = overlay_detections(frame, processed_image, road_found, objects_detected)

        # Display the frame
        cv2.imshow('Live Camera Feed', frame_display)

        # Introduce a 5-second delay while allowing window events
        handle_display_delay()

    except Exception as e:
        print(f"[ERROR in processing frame]: {e}")
    finally:
        # Clean up temporary image file
        if os.path.exists(temp_image_path):
            os.remove(temp_image_path)
            print(f"[DEBUG] Temporary image {temp_image_path} deleted.")

def save_frame_to_temp(frame):
    """
    Saves the captured frame to a temporary file.

    Parameters:
    - frame: The captured frame.

    Returns:
    - Path to the temporary image file or None if saving fails.
    """
    try:
        with tempfile.NamedTemporaryFile(suffix=".jpg", delete=False) as temp_image:
            temp_image_path = temp_image.name
            cv2.imwrite(temp_image_path, frame)
            print(f"[DEBUG] Captured image saved to {temp_image_path}")
            return temp_image_path
    except Exception as e:
        print(f"[ERROR in save_frame_to_temp]: {e}")
        return None

def handle_server_response(response):
    """
    Handles the server response after sending data.

    Parameters:
    - response: The server response object.
    """
    if response:
        if response.status_code == 200:
            print("Data sent successfully:", response.text)
        else:
            print("Failed to send data. Status code:", response.status_code, "Response:", response.text)
    else:
        print("[DEBUG] No response from server.")

def log_data(data):
    """
    Logs the data payload to a CSV file with the latest data on top after the header.

    Parameters:
    - data: The data payload dictionary.
    """
    try:
        # Define the CSV file path
        csv_file_path = "data_log.csv"

        # Extract the header from the data keys
        header = list(data.keys())

        # Truncate the base64 image data to the first 20 characters
        if 'image' in data and data['image'] is not None:
            data['image'] = data['image'][:20]

        # Read existing data from the CSV file
        existing_data = []
        if os.path.exists(csv_file_path):
            with open(csv_file_path, "r") as csv_file:
                reader = csv.DictReader(csv_file)
                existing_data = list(reader)

        # Add the new data at the top
        existing_data.insert(0, data)

        # Write the updated data back to the CSV file
        with open(csv_file_path, "w", newline='') as csv_file:
            writer = csv.DictWriter(csv_file, fieldnames=header)
            writer.writeheader()
            writer.writerows(existing_data)

        print("[DEBUG] Data stored in data_log.csv with the latest data on top")
    except Exception as e:
        print(f"[ERROR in log_data]: {e}")

def overlay_detections(original_frame, processed_image, road_found, objects_detected):
    """
    Overlays detection results on the original frame for display.

    Parameters:
    - original_frame: The original captured frame.
    - processed_image: The image with perspective lines drawn.
    - road_found: Boolean indicating if road is detected.
    - objects_detected: List of detected objects.

    Returns:
    - frame_display: The frame with detections overlaid.
    """
    try:
        if road_found:
            # Read the processed image to overlay perspective lines
            processed_image_bgr = cv2.cvtColor(processed_image, cv2.COLOR_RGB2BGR)
            # Draw bounding boxes and labels on the processed image if objects are detected
            if objects_detected:
                for obj in objects_detected:
                    class_name = obj['class_name']
                    box = obj['box']
                    x1, y1, x2, y2 = map(int, box)
                    color = get_detection_color(class_name)
                    cv2.rectangle(processed_image_bgr, (x1, y1), (x2, y2), color, 2)
                    cv2.putText(processed_image_bgr, class_name, (x1, y1 - 10), cv2.FONT_HERSHEY_SIMPLEX, 0.9, color, 2)
            # Replace the current frame with the processed image
            frame_display = processed_image_bgr.copy()
        elif not road_found and objects_detected:
            # If road not found but objects detected, display original frame with bounding boxes
            frame_display = original_frame.copy()
            for obj in objects_detected:
                class_name = obj['class_name']
                box = obj['box']
                x1, y1, x2, y2 = map(int, box)
                color = get_detection_color(class_name)
                cv2.rectangle(frame_display, (x1, y1), (x2, y2), color, 2)
                cv2.putText(frame_display, class_name, (x1, y1 - 10), cv2.FONT_HERSHEY_SIMPLEX, 0.9, color, 2)
        else:
            # If no road or objects detected, display the original frame
            frame_display = original_frame.copy()
        return frame_display
    except Exception as e:
        print(f"[ERROR in overlay_detections]: {e}")
        return original_frame.copy()

def get_detection_color(class_name):
    """
    Returns the color for bounding boxes based on the class name.

    Parameters:
    - class_name: Name of the detected class.

    Returns:
    - color: Tuple representing the BGR color.
    """
    if class_name.lower() == 'elephant':
        return (0, 0, 255)  # Red for elephants
    elif class_name.lower() == 'car':
        return (0, 255, 0)  # Green for cars
    else:
        return (255, 0, 0)  # Blue for unknown classes

def handle_display_delay():
    """
    Introduces a 5-second delay while allowing window events.
    """
    try:
        start_time = time.time()
        while True:
            elapsed_time = time.time() - start_time
            if elapsed_time > 5:
                break
            # Wait for 1 millisecond to process window events
            if cv2.waitKey(1) & 0xFF == ord('q'):
                print("\n[INFO] 'q' pressed. Exiting...")
                exit()
    except Exception as e:
        print(f"[ERROR in handle_display_delay]: {e}")

# --- [Main Initialization and Loop] ---

def main():
    """
    Initializes models, camera, and starts the main loop to process frames every 10 seconds while displaying a live feed.
    """
    # Configuration
    CONFIG = {
        'road_model_path': 'Road.pt',
        'object_model_path_1': 'new(ModelTrue2).pt',    # Primary model for Elephant and Car
        'camera_id': 'SOURCE0_CAM_001',
        'camera_lat': 14.22711,
        'camera_long': 101.40447,
        'bearing_degrees': 315.18,
    }

    PARAMS = {
        'confidence_road': 0.02,
        'confidence_object': 0.7,
        'standard_road_width_meters': 3.5,
        'distance_between_lines_meters': 10,    # Distance between lines in meters
        'max_distance_meters': 100,             # Maximum distance to draw lines in meters
    }

    SERVER_URL = "https://aprlabtop.com/elephant_api/Testapi.php"

    try:
        # Initialize camera (source0)
        camera = cv2.VideoCapture(0)
        if not camera.isOpened():
            print("[ERROR] Unable to access camera source0.")
            return
        print("[DEBUG] Camera source0 initialized successfully.")

        # Load YOLO models
        print("[DEBUG] Loading object detection models...")
        object_model_1 = create_object_detection_model(CONFIG['object_model_path_1'])  # Primary model
        if object_model_1 is None:
            print("[ERROR] Failed to load object detection model.")
            return
        print("[DEBUG] Object detection model loaded successfully.")

        road_model = create_road_detection_model(CONFIG['road_model_path'])
        if road_model is None:
            print("[ERROR] Failed to load road detection model.")
            return
        print("[DEBUG] Road detection model loaded successfully.")

        # Get class names from object detection model
        class_names_primary = object_model_1.names

        print("[DEBUG] Starting main loop. Press 'q' to exit.")

        while True:
            ret, frame = camera.read()
            if not ret:
                print("[ERROR] Failed to read from camera.")
                break

            # Display the live camera feed
            cv2.imshow('Live Camera Feed', frame)

            current_time = datetime.now()
            if current_time.second in [5, 35]:
                print(f"[INFO] Processing frame at {current_time.strftime('%Y-%m-%d %H:%M:%S')}...")
                process_frame(
                    camera,
                    CONFIG,
                    PARAMS,
                    object_model_1,
                    class_names_primary,
                    SERVER_URL,
                    road_model
                )
                # Wait for 1 second to avoid multiple captures within the same second
                time.sleep(1)

            # Check for 'q' or 'ๆ' key press to exit
            if cv2.waitKey(1) & 0xFF in [ord('q'), ord('ๆ')]:
                print("\n[INFO] 'q' or 'ๆ' pressed. Exiting...")
                break

    except KeyboardInterrupt:
        print("\n[INFO] Interrupted by user. Exiting...")
    except Exception as e:
        print(f"[ERROR in main]: {e}")
    finally:
        # Release camera resource
        if 'camera' in locals() and camera.isOpened():
            camera.release()
            print("[DEBUG] Camera released.")
        cv2.destroyAllWindows()

if __name__ == "__main__":
    main()