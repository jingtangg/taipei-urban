#!/usr/bin/env python3
"""
行政區界資料匯入腳本
匯入對象：districts
資料來源：臺北市區界圖 G97_A_CADIST_P.shp（民政局）
座標系統：EPSG:3826 TWD97
說明：全系統 district_name 的 JOIN 基準，共 12 筆
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

SHP_PATH = "database/data/shapefiles/3_districts/G97_A_CADIST_P.shp"
# ============================================================


def get_connection():
    return psycopg2.connect(**DB_CONFIG)


def import_districts(conn):
    """
    匯入行政區界資料
    - TNAME 原始值為「北投區」，直接使用，不需截取
    - AREA 單位為平方公尺，原始值存入，API 層換算 km²
    - 幾何為 Polygon
    """
    print("讀取 G97_A_CADIST_P.shp...")
    gdf = gpd.read_file(SHP_PATH)
    print(f"  總筆數：{len(gdf)}")
    print(f"  行政區：{sorted(gdf['TNAME'].tolist())}")

    rows = []
    for _, row in gdf.iterrows():
        rows.append((
            row["TNAME"],        # 行政區名，如「北投區」
            float(row["AREA"]),  # 面積（平方公尺）
            row["geometry"].wkt,
        ))

    with conn.cursor() as cur:
        cur.execute("TRUNCATE TABLE districts RESTART IDENTITY;")

        execute_values(
            cur,
            """
            INSERT INTO districts (district_name, area_m2, geom)
            VALUES %s
            """,
            rows,
            template="""(
                %s, %s,
                ST_SetSRID(ST_GeomFromText(%s), 3826)
            )""",
        )

    conn.commit()
    print(f"  ✅ districts 匯入完成：{len(rows)} 筆")


def verify(conn):
    print("\n=== 驗證結果 ===")
    with conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) FROM districts;")
        print(f"總筆數：{cur.fetchone()[0]}（應為 12）")

        cur.execute("""
            SELECT
                district_name,
                ROUND((area_m2 / 1000000)::numeric, 2) AS area_km2
            FROM districts
            ORDER BY area_km2 DESC;
        """)
        print(f"\n行政區面積（大到小）：")
        for row in cur.fetchall():
            print(f"  {row[0]}：{row[1]} km²")


if __name__ == "__main__":
    try:
        conn = get_connection()
        print("✅ 資料庫連線成功\n")

        import_districts(conn)
        verify(conn)

        conn.close()
        print("\n✅ 匯入完成")

    except Exception as e:
        print(f"❌ 錯誤：{e}")
        raise
