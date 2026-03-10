#!/usr/bin/env python3
"""
消防設施資料匯入腳本
匯入對象：fire_hydrants（消防栓）、fire_stations（消防隊）
資料來源：台北市開放資料平台
座標系統：EPSG:3826 TWD97
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
HYDRANT_CSV = "/Users/mini/Developer/personal/taipei-urban/database/data/csv/2.1_fire_hydrants.csv"
STATION_CSV = "/Users/mini/Developer/personal/taipei-urban/database/data/csv/2.2_fire_stations.csv"
# ============================================================


def get_connection():
    return psycopg2.connect(**DB_CONFIG)


def extract_district(location: str) -> str:
    """
    從「臺北市萬華區○○里...」截取行政區名「萬華區」
    對應 districts.district_name 的格式
    """
    if not isinstance(location, str):
        return ""
    # 去掉「臺北市」前綴，取接下來3個字（如「萬華區」）
    if location.startswith("臺北市"):
        return location[3:6]
    return ""


def import_fire_hydrants(conn):
    """
    匯入消防栓資料
    - 只保留臺北市
    - 座標來源：97X座標 + 97Y座標（TWD97）
    - district 從所在地區截取
    """
    print("開始匯入消防栓...")
    df = pd.read_csv(HYDRANT_CSV)

    # 只保留臺北市
    df = df[df["所在地區"].str.startswith("臺北市", na=False)].copy()
    print(f"  過濾後（僅臺北市）：{len(df)} 筆")

    rows = []
    for _, row in df.iterrows():
        district = extract_district(row["所在地區"])
        if not district:
            continue

        rows.append((
            row["WPID"],
            row["型式"],
            district,
            row["97X座標"],
            row["97Y座標"],
        ))

    with conn.cursor() as cur:
        # 清空舊資料（重跑腳本時避免重複）
        cur.execute("TRUNCATE TABLE fire_hydrants RESTART IDENTITY;")

        execute_values(
            cur,
            """
            INSERT INTO fire_hydrants (wpid, type, district, geom)
            VALUES %s
            """,
            rows,
            template="""(
                %s, %s, %s,
                ST_SetSRID(ST_MakePoint(%s, %s), 3826)
            )""",
        )

    conn.commit()
    print(f"  ✅ 消防栓匯入完成：{len(rows)} 筆")


def import_fire_stations(conn):
    """
    匯入消防隊資料
    - 全部 46 筆皆為臺北市，不需過濾
    - 座標來源：「經度」+「緯度」欄位（實為 TWD97 整數，欄位命名有誤）
    """
    print("開始匯入消防隊...")
    df = pd.read_csv(STATION_CSV)
    print(f"  總筆數：{len(df)} 筆")

    rows = []
    for _, row in df.iterrows():
        rows.append((
            row["分隊名稱"],
            row["地址"],
            float(row["經度"]),   # 實為 TWD97 X
            float(row["緯度"]),   # 實為 TWD97 Y
        ))

    with conn.cursor() as cur:
        cur.execute("TRUNCATE TABLE fire_stations RESTART IDENTITY;")

        execute_values(
            cur,
            """
            INSERT INTO fire_stations (name, address, geom)
            VALUES %s
            """,
            rows,
            template="""(
                %s, %s,
                ST_SetSRID(ST_MakePoint(%s, %s), 3826)
            )""",
        )

    conn.commit()
    print(f"  ✅ 消防隊匯入完成：{len(rows)} 筆")


def verify(conn):
    """匯入後驗證筆數與座標範圍"""
    print("\n=== 驗證結果 ===")
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) FROM fire_hydrants;")
        print(f"fire_hydrants：{cur.fetchone()[0]} 筆")

        cur.execute("SELECT COUNT(*) FROM fire_stations;")
        print(f"fire_stations：{cur.fetchone()[0]} 筆")

        # 確認座標落在台北市範圍內（TWD97 大約 X:296000-314000, Y:2763000-2785000）
        cur.execute("""
            SELECT
                ROUND(MIN(ST_X(geom))::numeric, 0) AS x_min,
                ROUND(MAX(ST_X(geom))::numeric, 0) AS x_max,
                ROUND(MIN(ST_Y(geom))::numeric, 0) AS y_min,
                ROUND(MAX(ST_Y(geom))::numeric, 0) AS y_max
            FROM fire_hydrants;
        """)
        r = cur.fetchone()
        print(f"\n消防栓座標範圍：")
        print(f"  X: {r[0]} ~ {r[1]}")
        print(f"  Y: {r[2]} ~ {r[3]}")
        print(f"  （台北市正常範圍 X:296000~314000, Y:2763000~2785000）")

        cur.execute("""
            SELECT district, COUNT(*) as cnt
            FROM fire_hydrants
            GROUP BY district
            ORDER BY cnt DESC;
        """)
        print(f"\n消防栓各行政區筆數：")
        for row in cur.fetchall():
            print(f"  {row[0]}：{row[1]} 個")


if __name__ == "__main__":
    try:
        conn = get_connection()
        print("✅ 資料庫連線成功\n")

        import_fire_hydrants(conn)
        import_fire_stations(conn)
        verify(conn)

        conn.close()
        print("\n✅ 全部匯入完成")

    except Exception as e:
        print(f"❌ 錯誤：{e}")
        raise