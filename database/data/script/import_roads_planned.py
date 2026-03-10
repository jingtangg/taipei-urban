#!/usr/bin/env python3
"""
計畫道路資料匯入腳本
匯入對象：roads_planned
資料來源：臺北市道路寬度 roadsize2.shp（都市發展局）
座標系統：EPSG:3826 TWD97
說明：這是都市計畫的計畫值，非實測，標注於 popup
"""

import re
import geopandas as gpd
import psycopg2
from psycopg2.extras import execute_values

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

SHP_PATH = "database/data/shapefiles/1.2_roads_planned/roadsize2.shp"
# ============================================================


def get_connection():
    return psycopg2.connect(**DB_CONFIG)


def parse_width(value: str) -> float:
    """
    從字串格式解析路寬數值
    例：'8M' → 8.0，'3.5M' → 3.5
    """
    m = re.findall(r'[\d.]+', str(value))
    return float(m[0]) if m else 0.0


def get_category(width_m: float) -> str:
    """
    依路寬數值返回分級
    narrow : < 3.5m  → 地圖紅色
    mid    : 3.5-6m  → 地圖黃色
    wide   : >= 6m   → 地圖綠色
    """
    if width_m < 3.5:
        return "narrow"
    elif width_m < 6:
        return "mid"
    else:
        return "wide"


def import_roads_planned(conn):
    """
    匯入計畫道路資料
    - 保留全部 22,676 筆（含窄路，這是資料集 a 的核心價值）
    - width_m 與 width_category 在 import 時計算好存入，API 不需重算
    - 幾何為 LineString
    """
    print("讀取 roadsize2.shp...")
    gdf = gpd.read_file(SHP_PATH)
    print(f"  總筆數：{len(gdf)}")

    rows = []
    for _, row in gdf.iterrows():
        width_m    = parse_width(row["road_width"])
        category   = get_category(width_m)
        geom_wkt   = row["geometry"].wkt

        rows.append((
            str(row["road_width"]),  # 原始字串，如「8M」
            width_m,                 # 解析後數值，如 8.0
            category,                # narrow / mid / wide
            geom_wkt,
        ))

    print("  開始寫入資料庫...")
    with conn.cursor() as cur:
        cur.execute("TRUNCATE TABLE roads_planned RESTART IDENTITY;")

        execute_values(
            cur,
            """
            INSERT INTO roads_planned
                (road_width, width_m, width_category, geom)
            VALUES %s
            """,
            rows,
            template="""(
                %s, %s, %s,
                ST_SetSRID(ST_GeomFromText(%s), 3826)
            )""",
            page_size=500,
        )

    conn.commit()
    print(f"  ✅ roads_planned 匯入完成：{len(rows)} 筆")


def verify(conn):
    print("\n=== 驗證結果 ===")
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) FROM roads_planned;")
        print(f"總筆數：{cur.fetchone()[0]}")

        cur.execute("""
            SELECT
                width_category,
                COUNT(*) as cnt,
                ROUND(SUM(ST_Length(geom))::numeric / 1000, 2) as total_km
            FROM roads_planned
            GROUP BY width_category
            ORDER BY cnt DESC;
        """)
        print(f"\n分級統計：")
        for row in cur.fetchall():
            label = {"narrow": "紅(<3.5m)", "mid": "黃(3.5-6m)", "wide": "綠(>=6m)"}
            print(f"  {label.get(row[0], row[0])}：{row[1]} 筆，{row[2]} km")


if __name__ == "__main__":
    try:
        conn = get_connection()
        print("✅ 資料庫連線成功\n")

        import_roads_planned(conn)
        verify(conn)

        conn.close()
        print("\n✅ 匯入完成")

    except Exception as e:
        print(f"❌ 錯誤：{e}")
        raise
