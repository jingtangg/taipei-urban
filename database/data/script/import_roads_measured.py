#!/usr/bin/env python3
"""
實測道路資料匯入腳本
匯入對象：roads_measured
資料來源：臺北市寬度超過8公尺道路 Road.shp（工務局）
座標系統：EPSG:3826 TWD97
過濾條件：ROADWIDTH = 0 的路口交叉幾何排除
"""

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

SHP_PATH = "database/data/shapefiles/1.1_roads_measured/Road.shp"
# ============================================================


def get_connection():
    return psycopg2.connect(**DB_CONFIG)


def import_roads_measured(conn):
    """
    匯入實測道路資料
    - 過濾 ROADWIDTH = 0（路口交叉幾何，無意義）
    - 幾何為 Polygon，使用 ST_GeomFromText 帶入 WKT
    """
    print("讀取 Road.shp...")
    gdf = gpd.read_file(SHP_PATH)
    print(f"  原始筆數：{len(gdf)}")

    # 過濾路口幾何
    gdf = gdf[gdf["ROADWIDTH"] > 0].copy()
    print(f"  過濾 ROADWIDTH=0 後：{len(gdf)} 筆")

    rows = []
    for _, row in gdf.iterrows():
        road_name = row["ROADNAME"] if row["ROADNAME"] and str(row["ROADNAME"]).strip() else None
        district  = row["TOWNNAME"]  if row["TOWNNAME"]  and str(row["TOWNNAME"]).strip()  else None
        geom_wkt  = row["geometry"].wkt

        rows.append((
            road_name,
            district,
            float(row["ROADWIDTH"]),
            float(row["AVG"]) if row["AVG"] else None,
            float(row["ROADLENGHT"]) if row["ROADLENGHT"] else None,
            geom_wkt,
        ))

    print(f"  開始寫入資料庫...")
    with conn.cursor() as cur:
        cur.execute("TRUNCATE TABLE roads_measured RESTART IDENTITY;")

        execute_values(
            cur,
            """
            INSERT INTO roads_measured
                (road_name, district, measured_width, avg_width, road_length, geom)
            VALUES %s
            """,
            rows,
            template="""(
                %s, %s, %s, %s, %s,
                ST_SetSRID(ST_GeomFromText(%s), 3826)
            )""",
            page_size=500,
        )

    conn.commit()
    print(f"  ✅ roads_measured 匯入完成：{len(rows)} 筆")


def verify(conn):
    print("\n=== 驗證結果 ===")
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) FROM roads_measured;")
        print(f"總筆數：{cur.fetchone()[0]}")

        cur.execute("""
            SELECT district, COUNT(*) as cnt, ROUND(AVG(measured_width)::numeric, 2) as avg_w
            FROM roads_measured
            GROUP BY district
            ORDER BY cnt DESC;
        """)
        print(f"\n各行政區筆數與平均路寬：")
        for row in cur.fetchall():
            print(f"  {row[0]}：{row[1]} 筆，平均 {row[2]}m")


if __name__ == "__main__":
    try:
        conn = get_connection()
        print("✅ 資料庫連線成功\n")

        import_roads_measured(conn)
        verify(conn)

        conn.close()
        print("\n✅ 匯入完成")

    except Exception as e:
        print(f"❌ 錯誤：{e}")
        raise
