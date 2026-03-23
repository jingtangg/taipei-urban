#!/usr/bin/env python3
"""
窄巷資料 Geocoding 腳本 - Step 2: 地址定位
使用 Google Geocoding API 將地址轉換為座標
資料來源：narrow_alleys_temp
輸出：更新 latitude, longitude, geocode_status 欄位

⚠️  API Key 設定方式：
1. 在專案根目錄的 .env 檔案中加入：
   GOOGLE_MAPS_API_KEY=your_actual_api_key_here

2. .env 檔案已在 .gitignore 中，不會被 commit
"""

import psycopg2
import requests
import time
from datetime import datetime
from pathlib import Path

# ============================================================
# 設定區
# ============================================================
DB_CONFIG = {
    "host": "127.0.0.1",
    "port": 5433,
    "database": "taipei_urban",
    "user": "urban",
    "password": "urban1234",
}

# API 設定
GEOCODING_URL = "https://maps.googleapis.com/maps/api/geocode/json"
REQUEST_DELAY = 0.1  # 每次請求間隔（秒），避免超過 API 限制
# ============================================================


def load_env_file():
    """
    讀取專案根目錄的 .env 檔案
    回傳一個 dict，包含所有環境變數
    """
    env_path = Path(__file__).resolve().parents[3] / ".env"  # 專案根目錄的 .env

    if not env_path.exists():
        return {}

    env_vars = {}
    with open(env_path, 'r', encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            # 跳過註解和空行
            if not line or line.startswith('#'):
                continue
            # 解析 KEY=VALUE
            if '=' in line:
                key, value = line.split('=', 1)
                env_vars[key.strip()] = value.strip()

    return env_vars


def get_api_key():
    """取得 Google Maps API Key"""
    env_vars = load_env_file()
    api_key = env_vars.get('GOOGLE_MAPS_API_KEY', '')

    if not api_key:
        print("\n❌ 錯誤：未設定 Google Maps API Key")
        print("\n請在專案根目錄的 .env 檔案中加入：")
        print("  GOOGLE_MAPS_API_KEY=your_actual_api_key_here")
        print("\n.env 檔案已在 .gitignore 中，不會被 git 追蹤")
        raise ValueError("Missing GOOGLE_MAPS_API_KEY")

    return api_key


def get_connection():
    return psycopg2.connect(**DB_CONFIG)


def geocode_address(address: str, district: str, api_key: str) -> tuple:
    """
    使用 Google Geocoding API 定位地址

    Args:
        address: 巷道名稱（如「齊東街82巷」）
        district: 行政區（如「中正區」）
        api_key: Google Maps API Key

    Returns:
        (lat, lng, status, error_msg)
        - success: (25.123, 121.456, 'success', None)
        - failed: (None, None, 'failed', '錯誤訊息')
    """
    # 組合完整地址：台北市 + 行政區 + 巷道名稱
    full_address = f"台北市{district}{address}"

    params = {
        "address": full_address,
        "key": api_key,
        "region": "tw",  # 限定台灣地區
    }

    try:
        response = requests.get(GEOCODING_URL, params=params, timeout=10)
        data = response.json()

        if data["status"] == "OK" and len(data["results"]) > 0:
            location = data["results"][0]["geometry"]["location"]
            lat = location["lat"]
            lng = location["lng"]
            return (lat, lng, "success", None)
        else:
            error_msg = data.get("status", "UNKNOWN_ERROR")
            return (None, None, "failed", error_msg)

    except Exception as e:
        return (None, None, "failed", str(e))


def process_geocoding(conn, api_key):
    """
    批次處理 Geocoding
    - 只處理 geocode_status = 'pending' 的資料
    - 逐筆呼叫 Google API
    - 更新結果到資料庫
    """
    print("開始執行 Geocoding...")

    with conn.cursor() as cur:
        # 取得待處理的資料
        cur.execute("""
            SELECT id, alley_name, district
            FROM narrow_alleys_temp
            WHERE geocode_status = 'pending'
            ORDER BY id;
        """)
        pending_rows = cur.fetchall()

        if not pending_rows:
            print("✅ 沒有待處理的資料")
            return

        total = len(pending_rows)
        print(f"\n待處理筆數：{total} 筆")
        print(f"預估時間：約 {int(total * REQUEST_DELAY / 60)} 分鐘")
        print(f"開始時間：{datetime.now().strftime('%H:%M:%S')}\n")

        success_count = 0
        failed_count = 0

        for idx, (row_id, alley_name, district) in enumerate(pending_rows, 1):
            # Geocoding
            lat, lng, status, error_msg = geocode_address(alley_name, district, api_key)

            # 更新資料庫
            if status == "success":
                cur.execute("""
                    UPDATE narrow_alleys_temp
                    SET latitude = %s, longitude = %s, geocode_status = 'success', geocode_error = NULL
                    WHERE id = %s;
                """, (lat, lng, row_id))
                success_count += 1
                print(f"[{idx}/{total}] ✅ {district} {alley_name} → ({lat:.6f}, {lng:.6f})")
            else:
                cur.execute("""
                    UPDATE narrow_alleys_temp
                    SET geocode_status = 'failed', geocode_error = %s
                    WHERE id = %s;
                """, (error_msg, row_id))
                failed_count += 1
                print(f"[{idx}/{total}] ❌ {district} {alley_name} → 失敗：{error_msg}")

            # 每 10 筆 commit 一次
            if idx % 10 == 0:
                conn.commit()

            # 延遲避免超過 API 限制
            time.sleep(REQUEST_DELAY)

        # 最後 commit
        conn.commit()

        print(f"\n結束時間：{datetime.now().strftime('%H:%M:%S')}")
        print(f"\n✅ Geocoding 完成")
        print(f"   成功：{success_count} 筆")
        print(f"   失敗：{failed_count} 筆")


def verify(conn):
    """驗證 Geocoding 結果"""
    print("\n=== 驗證結果 ===")
    with conn.cursor() as cur:
        # 狀態統計
        cur.execute("""
            SELECT geocode_status, COUNT(*) as cnt
            FROM narrow_alleys_temp
            GROUP BY geocode_status
            ORDER BY geocode_status;
        """)
        print(f"\nGeocoding 狀態：")
        for row in cur.fetchall():
            print(f"  {row[0]}：{row[1]} 筆")

        # 成功的座標範圍（WGS84）
        cur.execute("""
            SELECT
                ROUND(MIN(latitude)::numeric, 6) AS lat_min,
                ROUND(MAX(latitude)::numeric, 6) AS lat_max,
                ROUND(MIN(longitude)::numeric, 6) AS lng_min,
                ROUND(MAX(longitude)::numeric, 6) AS lng_max
            FROM narrow_alleys_temp
            WHERE geocode_status = 'success';
        """)
        r = cur.fetchone()
        if r and r[0]:
            print(f"\n成功定位的座標範圍（WGS84）：")
            print(f"  緯度：{r[0]} ~ {r[1]}")
            print(f"  經度：{r[2]} ~ {r[3]}")
            print(f"  （台北市正常範圍 緯度:24.9~25.2, 經度:121.4~121.7）")

        # 失敗的案例
        cur.execute("""
            SELECT alley_name, district, geocode_error
            FROM narrow_alleys_temp
            WHERE geocode_status = 'failed'
            LIMIT 10;
        """)
        failed_rows = cur.fetchall()
        if failed_rows:
            print(f"\n失敗案例（前 10 筆）：")
            for alley, dist, error in failed_rows:
                print(f"  {dist} {alley} → {error}")


def show_next_steps():
    """顯示下一步操作說明"""
    print("\n" + "=" * 60)
    print("📌 下一步：執行 Snapping（吸附到道路）")
    print("=" * 60)
    print("\n請執行以下指令：")
    print("\n  python database/data/script/snap_narrow_alleys.py")
    print("\n這個腳本會：")
    print("  1. 將 WGS84 座標轉換為 TWD97")
    print("  2. 吸附到最近的 roads_planned 道路")
    print("  3. 更新 snapped_lat, snapped_lng, matched_road_id 欄位")
    print("=" * 60)


if __name__ == "__main__":
    try:
        # 取得 API Key
        api_key = get_api_key()

        conn = get_connection()
        print("✅ 資料庫連線成功")
        print(f"✅ Google Maps API Key 已載入\n")

        process_geocoding(conn, api_key)
        verify(conn)
        show_next_steps()

        conn.close()

    except Exception as e:
        print(f"\n❌ 錯誤：{e}")
        raise
