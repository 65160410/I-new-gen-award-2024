import os
import json
from flask import Flask, render_template, jsonify, request, session, redirect, url_for
from werkzeug.security import generate_password_hash, check_password_hash

app = Flask(__name__)
app.secret_key = "your_secret_key"

# ชื่อไฟล์สำหรับเก็บข้อมูลผู้ใช้
USERS_FILE = "users.json"

# ฟังก์ชันโหลดข้อมูลผู้ใช้จากไฟล์ JSON
def load_users():
    if os.path.exists(USERS_FILE):
        with open(USERS_FILE, "r") as file:
            return json.load(file)
    return {}

# ฟังก์ชันบันทึกข้อมูลผู้ใช้ลงไฟล์ JSON
def save_users(users):
    with open(USERS_FILE, "w") as file:
        json.dump(users, file, indent=4)

# โหลดข้อมูลผู้ใช้เมื่อเริ่มต้น
users_db = load_users()

locations = [
    {"name": "Cam1", "lat": 14.23142, "lng": 101.40089},
    {"name": "Cam2", "lat": 14.23249, "lng": 101.40044},
    {"name": "Cam3", "lat": 14.23391, "lng": 101.39945},
]

@app.route("/")
def index():
    logged_in = "username" in session
    return render_template("index.html", logged_in=logged_in)

@app.route("/login", methods=["GET", "POST"])
def login():
    if request.method == "POST":
        username = request.form.get("username")
        password = request.form.get("password")

        # ตรวจสอบชื่อผู้ใช้และรหัสผ่าน
        if username in users_db and check_password_hash(users_db[username], password):
            session["username"] = username
            return redirect(url_for("index"))
        else:
            return "ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง", 401

    return render_template("login.html")

@app.route("/register", methods=["GET", "POST"])
def register():
    if request.method == "POST":
        username = request.form.get("username")
        password = request.form.get("password")
        confirm_password = request.form.get("confirm_password")

        # ตรวจสอบรหัสผ่านและชื่อผู้ใช้
        if username in users_db:
            return "ชื่อผู้ใช้นี้มีอยู่แล้ว", 400
        if password != confirm_password:
            return "รหัสผ่านไม่ตรงกัน", 400

        # บันทึกข้อมูลผู้ใช้ใหม่
        hashed_password = generate_password_hash(password)
        users_db[username] = hashed_password
        save_users(users_db)
        return redirect(url_for("login"))

    return render_template("register.html")

@app.route("/logout")
def logout():
    session.pop("username", None)
    return redirect(url_for("index"))

@app.route("/api/locations")
def get_locations():
    return jsonify(locations)

if __name__ == "__main__":
    app.run(debug=True)