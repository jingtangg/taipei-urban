#!/usr/bin/env python3
"""
窄巷資料匯入腳本 - Step 1: 匯入 CSV 到暫存表
匯入對象：narrow_alleys_temp（窄巷暫存表）
資料來源：台北市消防局 113 年清冊（PDF 轉 CSV）
總筆數：274 筆（紅區 + 黃區）
"""

import pandas as pd
import psycopg2
from psycopg2.extras import execute_values

# ============================================================
# 設定區：修改成你的資料庫連線與檔案路徑
# ============================================================
DB_CONFIG = {
    "host": "127.0.0.1",
    "port": 5433,
    "database": "taipei_urban",
    "user": "urban",
    "password": "urban1234",
}

# 修改成你本機的 CSV 路徑
CSV_FILE = "database/data/csv/narrow_alleys.csv"
# ============================================================


def get_connection():
    return psycopg2.connect(**DB_CONFIG)


def import_narrow_alleys(conn):
    """
    匯入窄巷資料到暫存表
    - 讀取 CSV 的 274 筆資料
    - 匯入到 narrow_alleys_temp
    - geocode_status 預設為 'pending'
    - 座標欄位先保持 NULL，等待 geocoding
    """
    print("開始匯入窄巷資料...")
    df = pd.read_csv(CSV_FILE)
    print(f"  總筆數：{len(df)} 筆")

    # 統計分類
    category_count = df['category'].value_counts()
    print(f"\n  分類統計：")
    for cat, count in category_count.items():
        print(f"    {cat}：{count} 筆")

    # 統計行政區
    district_count = df['district'].value_counts()
    print(f"\n  行政區統計（前 5 名）：")
    for dist, count in district_count.head(5).items():
        print(f"    {dist}：{count} 筆")

    rows = []
    for _, row in df.iterrows():
        rows.append((
            int(row['seq_number']),
            str(row['code']),
            row['category'],
            row['district'],
            row['fire_station_division'],
            row['alley_name'],
            float(row['width_m']),
        ))

    with conn.cursor() as cur:
        # 清空舊資料（重跑腳本時避免重複）
        cur.execute("TRUNCATE TABLE narrow_alleys_temp RESTART IDENTITY;")
        print(f"\n  已清空暫存表")

        execute_values(
            cur,
            """
            INSERT INTO narrow_alleys_temp
                (seq_number, code, category, district, fire_station_division, alley_name, width_m)
            VALUES %s
            """,
            rows,
            template="(%s, %s, %s, %s, %s, %s, %s)",
        )

    conn.commit()
    print(f"\n  ✅ 窄巷資料匯入完成：{len(rows)} 筆")


def verify(conn):
    """匯入後驗證筆數與狀態"""
    print("\n=== 驗證結果 ===")
    with conn.cursor() as cur:
        # 總筆數
        cur.execute("SELECT COUNT(*) FROM narrow_alleys_temp;")
        print(f"narrow_alleys_temp 總筆數：{cur.fetchone()[0]} 筆")

        # 各分類筆數
        cur.execute("""
            SELECT category, COUNT(*) as cnt
            FROM narrow_alleys_temp
            GROUP BY category
            ORDER BY cnt DESC;
        """)
        print(f"\n分類筆數：")
        for row in cur.fetchall():
            print(f"  {row[0]}：{row[1]} 筆")

        # Geocoding 狀態
        cur.execute("""
            SELECT geocode_status, COUNT(*) as cnt
            FROM narrow_alleys_temp
            GROUP BY geocode_status
            ORDER BY cnt DESC;
        """)
        print(f"\nGeocoding 狀態：")
        for row in cur.fetchall():
            print(f"  {row[0]}：{row[1]} 筆")

        # 寬度範圍
        cur.execute("""
            SELECT
                ROUND(MIN(width_m)::numeric, 2) AS min_width,
                ROUND(MAX(width_m)::numeric, 2) AS max_width,
                ROUND(AVG(width_m)::numeric, 2) AS avg_width
            FROM narrow_alleys_temp;
        """)
        r = cur.fetchone()
        print(f"\n窄巷寬度範圍：")
        print(f"  最小：{r[0]} 公尺")
        print(f"  最大：{r[1]} 公尺")
        print(f"  平均：{r[2]} 公尺")


def show_next_steps():
    """顯示下一步操作說明"""
    print("\n" + "=" * 60)
    print("📌 下一步：執行 Geocoding")
    print("=" * 60)
    print("\n請執行以下指令進行地址定位：")
    print("\n  python database/data/script/geocode_narrow_alleys.py")
    print("\n這個腳本會：")
    print("  1. 呼叫 Google Geocoding API")
    print("  2. 將地址轉換為座標（WGS84）")
    print("  3. 更新 narrow_alleys_temp 的 latitude, longitude 欄位")
    print("\n⚠️  注意：需要 Google Maps API Key")
    print("=" * 60)


if __name__ == "__main__":
    try:
        conn = get_connection()
        print("✅ 資料庫連線成功\n")

        import_narrow_alleys(conn)
        verify(conn)
        show_next_steps()

        conn.close()
        print("\n✅ 匯入完成")

    except Exception as e:
        print(f"❌ 錯誤：{e}")
        raise
