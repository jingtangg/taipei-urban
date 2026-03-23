#!/usr/bin/env python3
"""
窄巷資料 Snapping 腳本 - Step 3: 吸附到道路
將 Geocoding 結果吸附到最近的 roads_planned 道路
資料來源：narrow_alleys_temp（geocode_status = 'success'）
輸出：更新 snapped_lat, snapped_lng, matched_road_id, snap_distance_m 欄位
"""

import psycopg2

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

# Snapping 設定
MAX_SNAP_DISTANCE_M = 500  # 最大吸附距離（公尺），超過視為無效
# ============================================================


def get_connection():
    return psycopg2.connect(**DB_CONFIG)


def process_snapping(conn):
    """
    批次處理 Snapping
    步驟：
    1. 將 WGS84 (latitude, longitude) 轉換為 TWD97 (EPSG:3826)
    2. 找到最近的 roads_planned 道路
    3. 計算吸附距離
    4. 更新 snapped_lat, snapped_lng, matched_road_id, snap_distance_m
    """
    print("開始執行 Snapping...")

    with conn.cursor() as cur:
        # 取得成功 geocoding 的資料
        cur.execute("""
            SELECT COUNT(*)
            FROM narrow_alleys_temp
            WHERE geocode_status = 'success' AND latitude IS NOT NULL;
        """)
        total = cur.fetchone()[0]

        if total == 0:
            print("✅ 沒有待處理的資料")
            return

        print(f"\n待處理筆數：{total} 筆\n")

        # 執行 Snapping
        # 使用 PostGIS 的 ST_ClosestPoint 找到最近的道路點
        cur.execute(f"""
            UPDATE narrow_alleys_temp AS na
            SET
                -- 吸附後的座標（TWD97 轉回 WGS84）
                snapped_lat = ST_Y(ST_Transform(closest_point, 4326)),
                snapped_lng = ST_X(ST_Transform(closest_point, 4326)),
                -- 匹配到的道路 ID
                matched_road_id = matched_road.road_id,
                -- 偏移距離（公尺）
                snap_distance_m = ROUND(ST_Distance(geocoded_point, closest_point)::numeric, 2)
            FROM (
                SELECT
                    na_sub.id,
                    na_sub.geocoded_point,
                    rp.id AS road_id,
                    -- 找到 roads_planned 上最近的點
                    ST_ClosestPoint(rp.geom, na_sub.geocoded_point) AS closest_point
                FROM (
                    -- 子查詢：準備待處理的窄巷資料
                    SELECT
                        id,
                        -- 將 WGS84 轉換為 TWD97
                        ST_Transform(
                            ST_SetSRID(ST_MakePoint(longitude, latitude), 4326),
                            3826
                        ) AS geocoded_point
                    FROM narrow_alleys_temp
                    WHERE geocode_status = 'success' AND latitude IS NOT NULL
                ) AS na_sub
                CROSS JOIN LATERAL (
                    -- 找到最近的道路（限制在 {MAX_SNAP_DISTANCE_M} 公尺內）
                    SELECT id, geom
                    FROM roads_planned
                    WHERE ST_DWithin(geom, na_sub.geocoded_point, {MAX_SNAP_DISTANCE_M})
                    ORDER BY ST_Distance(geom, na_sub.geocoded_point)
                    LIMIT 1
                ) AS rp
            ) AS matched_road
            WHERE na.id = matched_road.id;
        """)

        updated_count = cur.rowcount
        conn.commit()

        print(f"✅ Snapping 完成：{updated_count} 筆")

        # 檢查未成功 snapping 的資料
        cur.execute("""
            SELECT COUNT(*)
            FROM narrow_alleys_temp
            WHERE geocode_status = 'success'
              AND latitude IS NOT NULL
              AND matched_road_id IS NULL;
        """)
        not_matched = cur.fetchone()[0]

        if not_matched > 0:
            print(f"\n⚠️  警告：有 {not_matched} 筆無法吸附到道路")
            print(f"   （可能原因：geocoding 座標偏離太遠，超過 {MAX_SNAP_DISTANCE_M} 公尺）")


def verify(conn):
    """驗證 Snapping 結果"""
    print("\n=== 驗證結果 ===")
    with conn.cursor() as cur:
        # Snapping 成功筆數
        cur.execute("""
            SELECT COUNT(*)
            FROM narrow_alleys_temp
            WHERE matched_road_id IS NOT NULL;
        """)
        matched_count = cur.fetchone()[0]
        print(f"\n成功吸附到道路：{matched_count} 筆")

        # 偏移距離統計
        cur.execute("""
            SELECT
                ROUND(MIN(snap_distance_m)::numeric, 2) AS min_dist,
                ROUND(MAX(snap_distance_m)::numeric, 2) AS max_dist,
                ROUND(AVG(snap_distance_m)::numeric, 2) AS avg_dist,
                ROUND(PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY snap_distance_m)::numeric, 2) AS median_dist
            FROM narrow_alleys_temp
            WHERE matched_road_id IS NOT NULL;
        """)
        r = cur.fetchone()
        if r and r[0] is not None:
            print(f"\n偏移距離統計（公尺）：")
            print(f"  最小：{r[0]} m")
            print(f"  最大：{r[1]} m")
            print(f"  平均：{r[2]} m")
            print(f"  中位數：{r[3]} m")

        # 偏移距離分布
        cur.execute("""
            SELECT
                CASE
                    WHEN snap_distance_m < 10 THEN '< 10m'
                    WHEN snap_distance_m < 50 THEN '10-50m'
                    WHEN snap_distance_m < 100 THEN '50-100m'
                    WHEN snap_distance_m < 200 THEN '100-200m'
                    ELSE '> 200m'
                END AS distance_range,
                COUNT(*) AS cnt
            FROM narrow_alleys_temp
            WHERE matched_road_id IS NOT NULL
            GROUP BY distance_range
            ORDER BY distance_range;
        """)
        print(f"\n偏移距離分布：")
        for range_name, count in cur.fetchall():
            print(f"  {range_name}：{count} 筆")

        # 未匹配的案例
        cur.execute("""
            SELECT alley_name, district, latitude, longitude
            FROM narrow_alleys_temp
            WHERE geocode_status = 'success'
              AND latitude IS NOT NULL
              AND matched_road_id IS NULL
            LIMIT 5;
        """)
        not_matched_rows = cur.fetchall()
        if not_matched_rows:
            print(f"\n未匹配案例（前 5 筆）：")
            for alley, dist, lat, lng in not_matched_rows:
                print(f"  {dist} {alley} ({lat:.6f}, {lng:.6f})")


def show_summary(conn):
    """顯示整體處理摘要"""
    print("\n" + "=" * 60)
    print("📊 整體處理摘要")
    print("=" * 60)

    with conn.cursor() as cur:
        # 總筆數
        cur.execute("SELECT COUNT(*) FROM narrow_alleys_temp;")
        total = cur.fetchone()[0]

        # Geocoding 成功
        cur.execute("SELECT COUNT(*) FROM narrow_alleys_temp WHERE geocode_status = 'success';")
        geocoded = cur.fetchone()[0]

        # Snapping 成功
        cur.execute("SELECT COUNT(*) FROM narrow_alleys_temp WHERE matched_road_id IS NOT NULL;")
        snapped = cur.fetchone()[0]

        print(f"\n總筆數：{total} 筆")
        print(f"Geocoding 成功：{geocoded} 筆 ({geocoded/total*100:.1f}%)")
        print(f"Snapping 成功：{snapped} 筆 ({snapped/total*100:.1f}%)")
        print(f"\n最終可用資料：{snapped} 筆")


def show_next_steps():
    """顯示下一步操作說明"""
    print("\n" + "=" * 60)
    print("📌 下一步：建立 API Controller")
    print("=" * 60)
    print("\n暫存表處理完成！可以：")
    print("\n1. 建立 NarrowAlleyController 提供 API")
    print("2. 或稍後匯入到正式表 narrow_alleys_official")
    print("=" * 60)


if __name__ == "__main__":
    try:
        conn = get_connection()
        print("✅ 資料庫連線成功\n")

        process_snapping(conn)
        verify(conn)
        show_summary(conn)
        show_next_steps()

        conn.close()
        print("\n✅ 處理完成")

    except Exception as e:
        print(f"\n❌ 錯誤：{e}")
        raise