#!/usr/bin/env python3
"""
花蓮縣窄巷 Geocoding + OSM Snap Pipeline
=========================================

流程：
  1. 讀取花蓮縣窄巷清冊 CSV
  2. Google Geocoding API → 取得各巷道中心點座標 (WGS84)
  3. 寫入 PostgreSQL 暫存表 _hualien_geocoded
  4. OSM Snap SQL：以中心點為基準，找最近 OSM 道路，
     用 ST_LineSubstring 擷取對應長度的線段，轉換至 EPSG:3826
  5. 寫入最終表 hualien_narrow_alleys

用法：
  pip install requests psycopg2-binary python-dotenv
  python scripts/geocode_hualien.py --csv data/hualien_narrow_alleys.csv

環境變數（.env 或 shell）：
  GOOGLE_GEOCODING_KEY=AIza...
  DB_HOST=localhost
  DB_PORT=5432
  DB_NAME=taipei_urban
  DB_USER=postgres
  DB_PASSWORD=...
"""

import argparse
import csv
import logging
import os
import re
import sys
import time
from dataclasses import dataclass, field
from typing import Optional

import psycopg2
import requests
from dotenv import load_dotenv

load_dotenv()
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
    handlers=[logging.StreamHandler(sys.stdout)],
)
log = logging.getLogger(__name__)

# ── 設定 ──────────────────────────────────────────────────────────────────────

GEOCODING_URL = "https://maps.googleapis.com/maps/api/geocode/json"
GEOCODING_DELAY_S = 0.05        # 每次請求間隔，避免觸發 QPS 上限
GEOCODING_RETRY = 2             # 失敗重試次數

# OSM snap 搜尋半徑（公尺，EPSG:3826）
OSM_SNAP_RADIUS_M = 200

# OSM highway 類型白名單（排除幹道，避免吸附到不相干道路）
OSM_HIGHWAY_FILTER = (
    "residential", "service", "unclassified",
    "living_street", "pedestrian", "footway", "path",
    "tertiary", "secondary",
)

# ── 資料類別 ──────────────────────────────────────────────────────────────────

@dataclass
class HualienAlley:
    alley_name: str
    township: str
    fire_station: str
    length_raw: str           # 原始長度字串
    width_m_min: float
    width_m_max: float
    risk_level: str
    length_m: Optional[float] = None    # 解析後數值
    lat: Optional[float] = None         # Google Geocoding 結果
    lng: Optional[float] = None
    snap_distance_m: Optional[float] = None
    geocode_failed: bool = False


# ── 輔助函式 ─────────────────────────────────────────────────────────────────

def _parse_width(raw: str) -> tuple[float, float]:
    """
    解析路寬欄位，回傳 (min, max)。
    支援：
      "3.5"        → (3.5, 3.5)
      "4.2-5.6"   → (4.2, 5.6)
      "3.5以下"   → (0.0, 3.5)
      "6以上"     → (6.0, 6.0)
    """
    raw = raw.strip()
    # 範圍型，如 "4.2-5.6"
    m = re.match(r"^(\d+\.?\d*)[~－\-](\d+\.?\d*)$", raw)
    if m:
        return float(m.group(1)), float(m.group(2))
    # 上限型，如 "3.5以下" / "3.5m以下"
    m = re.match(r"^(\d+\.?\d*)m?以下$", raw)
    if m:
        v = float(m.group(1))
        return 0.0, v
    # 下限型，如 "6以上"
    m = re.match(r"^(\d+\.?\d*)m?以上$", raw)
    if m:
        v = float(m.group(1))
        return v, v
    # 純數字
    try:
        v = float(re.sub(r"[^\d.]", "", raw))
        return v, v
    except ValueError:
        raise ValueError(f"無法解析路寬: {raw!r}")


def _parse_length(raw: str) -> Optional[float]:
    """
    解析長度欄位，回傳公尺數（float）或 None。
    支援：
      "150"        → 150.0
      "約700"      → 700.0
      "大於200m"  → 200.0  （取下限）
      "100-200"   → 150.0  （取平均）
      ""           → None
    """
    if not raw or raw.strip() == "":
        return None
    raw = raw.strip()
    # 範圍型
    m = re.match(r"(\d+)[~－\-](\d+)", raw)
    if m:
        return (float(m.group(1)) + float(m.group(2))) / 2
    # 取第一個數字
    m = re.search(r"(\d+\.?\d*)", raw)
    if m:
        return float(m.group(1))
    return None


def _calculate_risk_level(width_m_min: float) -> str:
    """
    與後端 BaseController::calculateRiskLevel() 邏輯一致。
    使用 width_m_min（保守取危險端）計算。
    """
    if width_m_min < 3.5:
        return "極高風險"
    if width_m_min < 6.0:
        return "高風險"
    return "一般"


def _connect_db() -> psycopg2.extensions.connection:
    return psycopg2.connect(
        host=os.environ["DB_HOST"],
        port=int(os.environ.get("DB_PORT", 5432)),
        dbname=os.environ["DB_NAME"],
        user=os.environ["DB_USER"],
        password=os.environ["DB_PASSWORD"],
    )


# ── Step 1：讀取 CSV ──────────────────────────────────────────────────────────

def load_csv(path: str) -> list[HualienAlley]:
    """
    CSV 欄位（原始中文 header 需對應）：
      巷道名稱, 鄉鎮市區, 管轄消防局, 長度, 寬度
    """
    rows = []
    with open(path, encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)
        for i, row in enumerate(reader, 1):
            alley_name  = row.get("巷道名稱", "").strip()
            township    = row.get("鄉鎮市區", "").strip()
            fire_station = row.get("管轄消防局", "").strip()
            length_raw  = row.get("長度", "").strip()
            width_raw   = row.get("寬度", "").strip()

            if not alley_name:
                log.warning(f"第 {i} 列巷道名稱為空，略過")
                continue

            try:
                w_min, w_max = _parse_width(width_raw)
            except ValueError as e:
                log.warning(f"第 {i} 列 {alley_name!r}: {e}，路寬設為 None，將以 NULL 寫入")
                w_min, w_max = 0.0, 0.0

            length_m = _parse_length(length_raw)
            risk_level = _calculate_risk_level(w_min)

            rows.append(HualienAlley(
                alley_name=alley_name,
                township=township,
                fire_station=fire_station,
                length_raw=length_raw,
                width_m_min=w_min,
                width_m_max=w_max,
                risk_level=risk_level,
                length_m=length_m,
            ))

    log.info(f"讀取 CSV 完成：{len(rows)} 筆")
    return rows


# ── Step 2：Google Geocoding ──────────────────────────────────────────────────

def geocode_alleys(alleys: list[HualienAlley], api_key: str) -> None:
    """原地修改 alleys，填入 lat/lng；失敗時設 geocode_failed=True。"""
    success, failed = 0, 0
    for alley in alleys:
        address = f"{alley.alley_name}, {alley.township}, 花蓮縣"
        for attempt in range(1, GEOCODING_RETRY + 2):
            try:
                resp = requests.get(
                    GEOCODING_URL,
                    params={"address": address, "key": api_key, "language": "zh-TW"},
                    timeout=10,
                )
                data = resp.json()
            except Exception as e:
                log.warning(f"Geocoding 請求失敗 [{alley.alley_name}] attempt {attempt}: {e}")
                time.sleep(1)
                continue

            status = data.get("status")
            if status == "OK":
                loc = data["results"][0]["geometry"]["location"]
                alley.lat = loc["lat"]
                alley.lng = loc["lng"]
                success += 1
                break
            elif status in ("ZERO_RESULTS", "INVALID_REQUEST"):
                log.warning(f"Geocoding 無結果 [{alley.alley_name}]: {status}")
                alley.geocode_failed = True
                failed += 1
                break
            else:
                log.warning(f"Geocoding 狀態 [{alley.alley_name}]: {status}，重試 {attempt}")
                time.sleep(2)
        else:
            alley.geocode_failed = True
            failed += 1

        time.sleep(GEOCODING_DELAY_S)

    log.info(f"Geocoding 完成：成功 {success} / 失敗 {failed}")


# ── Step 3：寫入暫存表 ────────────────────────────────────────────────────────

STAGING_DDL = """
CREATE TABLE IF NOT EXISTS _hualien_geocoded (
    id             SERIAL PRIMARY KEY,
    alley_name     TEXT NOT NULL,
    township       TEXT,
    fire_station   TEXT,
    length_raw     TEXT,
    length_m       NUMERIC(8,1),
    width_m_min    NUMERIC(5,2),
    width_m_max    NUMERIC(5,2),
    risk_level     TEXT,
    lat            DOUBLE PRECISION,
    lng            DOUBLE PRECISION,
    geocode_failed BOOLEAN DEFAULT FALSE,
    geom_4326      GEOMETRY(Point, 4326),
    created_at     TIMESTAMPTZ DEFAULT NOW()
);
"""

def write_staging(conn, alleys: list[HualienAlley]) -> None:
    with conn.cursor() as cur:
        cur.execute(STAGING_DDL)
        cur.execute("TRUNCATE _hualien_geocoded RESTART IDENTITY")

        for a in alleys:
            geom = (
                f"ST_SetSRID(ST_MakePoint({a.lng}, {a.lat}), 4326)"
                if a.lat is not None else "NULL"
            )
            cur.execute(
                f"""
                INSERT INTO _hualien_geocoded
                  (alley_name, township, fire_station, length_raw, length_m,
                   width_m_min, width_m_max, risk_level,
                   lat, lng, geocode_failed, geom_4326)
                VALUES (%s, %s, %s, %s, %s,
                        %s, %s, %s,
                        %s, %s, %s, {geom})
                """,
                (
                    a.alley_name, a.township, a.fire_station,
                    a.length_raw, a.length_m,
                    a.width_m_min, a.width_m_max, a.risk_level,
                    a.lat, a.lng, a.geocode_failed,
                ),
            )
    conn.commit()
    log.info(f"暫存表 _hualien_geocoded 寫入完成：{len(alleys)} 筆")


# ── Step 4+5：OSM Snap + 寫入最終表 ──────────────────────────────────────────

OSM_SNAP_SQL = """
INSERT INTO hualien_narrow_alleys
  (alley_name, township, fire_station, length_raw,
   width_m_min, width_m_max, risk_level, snap_distance_m, geom)
SELECT
    g.alley_name,
    g.township,
    g.fire_station,
    g.length_raw,
    g.width_m_min,
    g.width_m_max,
    g.risk_level,
    -- snap distance: 中心點 → 最近 OSM 道路（以 EPSG:3826 計算，公尺）
    ST_Distance(
        ST_Transform(g.geom_4326, 3826),
        ST_Transform(osm.way, 3826)
    ) AS snap_distance_m,
    -- 從 OSM 道路擷取與巷道長度對應的線段，存為 EPSG:3826
    ST_Transform(
        ST_LineSubstring(
            osm.way,
            GREATEST(0,
                ST_LineLocatePoint(osm.way, ST_ClosestPoint(osm.way, g.geom_4326))
                - (COALESCE(g.length_m, 50) / 2.0) / ST_Length(ST_Transform(osm.way, 3826))
            ),
            LEAST(1,
                ST_LineLocatePoint(osm.way, ST_ClosestPoint(osm.way, g.geom_4326))
                + (COALESCE(g.length_m, 50) / 2.0) / ST_Length(ST_Transform(osm.way, 3826))
            )
        ),
        3826
    ) AS geom
FROM _hualien_geocoded g
CROSS JOIN LATERAL (
    -- KNN 搜尋使用 4326 index（osm2pgsql --latlong 預設 SRID 4326）
    -- 先篩 highway 白名單，再取最近一條
    SELECT way
    FROM planet_osm_line
    WHERE highway IN %(highway_filter)s
      AND way && ST_Expand(g.geom_4326, %(search_deg)s)
    ORDER BY way <-> g.geom_4326
    LIMIT 1
) osm
WHERE g.geocode_failed = FALSE
  AND ST_DWithin(
        ST_Transform(g.geom_4326, 3826),
        ST_Transform(osm.way, 3826),
        %(snap_radius)s
      );
"""

def run_osm_snap(conn) -> int:
    """執行 OSM snap SQL，回傳寫入筆數。"""
    # 約 200m 對應的十進位度數（花蓮緯度約 23.9°）
    search_deg = OSM_SNAP_RADIUS_M / 111_000

    with conn.cursor() as cur:
        cur.execute("TRUNCATE hualien_narrow_alleys RESTART IDENTITY")
        cur.execute(
            OSM_SNAP_SQL,
            {
                "highway_filter": tuple(OSM_HIGHWAY_FILTER),
                "search_deg": search_deg,
                "snap_radius": OSM_SNAP_RADIUS_M,
            },
        )
        count = cur.rowcount
    conn.commit()
    log.info(f"OSM snap 完成：寫入 hualien_narrow_alleys {count} 筆")
    return count


def report_failures(conn) -> None:
    """印出 geocode 失敗的巷道清單，供人工補正。"""
    with conn.cursor() as cur:
        cur.execute(
            "SELECT alley_name, township FROM _hualien_geocoded WHERE geocode_failed = TRUE"
        )
        rows = cur.fetchall()
    if rows:
        log.warning(f"\n=== Geocoding 失敗清單（共 {len(rows)} 筆，需人工補座標）===")
        for name, township in rows:
            log.warning(f"  {township} {name}")
    else:
        log.info("所有巷道 Geocoding 成功，無需人工補正。")

    # 同時報告 snap 失敗（在暫存表但不在最終表）
    with conn.cursor() as cur:
        cur.execute("""
            SELECT g.alley_name, g.township
            FROM _hualien_geocoded g
            LEFT JOIN hualien_narrow_alleys h ON g.alley_name = h.alley_name AND g.township = h.township
            WHERE g.geocode_failed = FALSE AND h.id IS NULL
        """)
        snap_failed = cur.fetchall()
    if snap_failed:
        log.warning(f"\n=== OSM Snap 失敗（共 {len(snap_failed)} 筆，超出 {OSM_SNAP_RADIUS_M}m 無 OSM 道路）===")
        for name, township in snap_failed:
            log.warning(f"  {township} {name}")


# ── 主程式 ────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description="花蓮窄巷 Geocoding + OSM Snap Pipeline")
    parser.add_argument("--csv",  required=True, help="花蓮窄巷清冊 CSV 路徑")
    parser.add_argument("--skip-geocode", action="store_true",
                        help="略過 Geocoding，直接從已有暫存表執行 OSM snap（重跑用）")
    args = parser.parse_args()

    api_key = os.environ.get("GOOGLE_GEOCODING_KEY")
    if not args.skip_geocode and not api_key:
        log.error("缺少環境變數 GOOGLE_GEOCODING_KEY")
        sys.exit(1)

    conn = _connect_db()
    log.info("資料庫連線成功")

    if not args.skip_geocode:
        alleys = load_csv(args.csv)
        geocode_alleys(alleys, api_key)
        write_staging(conn, alleys)
    else:
        log.info("略過 Geocoding，使用現有暫存表")

    snapped = run_osm_snap(conn)
    report_failures(conn)

    conn.close()
    log.info(f"Pipeline 完成。hualien_narrow_alleys 共 {snapped} 筆。")


if __name__ == "__main__":
    main()
