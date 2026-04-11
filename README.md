# 台北市都市防災地圖｜後端 API

台北市道路、窄巷、消防設施空間資料的 RESTful API 服務。提供 GeoJSON 格式的地理資料供前端地圖渲染，並透過 PostGIS 空間函式進行行政區統計與密度分析。

> **前端地圖**：[taipei-urban_frontend](https://github.com/jingtangg/taipei-urban_frontend)（React + OpenLayers）

---

## 功能特色

- **空間查詢**：使用 PostGIS `ST_Intersects`、`ST_Transform`、`ST_AsGeoJSON` 進行座標系轉換與空間交集計算
- **窄巷去重統計**：透過 SQL CTE 合併「計畫窄巷」與「消防局實測新發現」，避免重複計算
- **快取層**：Dashboard 端點結果快取 3600 秒，`CacheKey` Enum 統一管理 Key 命名
- **FormRequest 驗證**：行政區名稱以 `Rule::in(TaipeiDistrict::ALL)` 驗證，拒絕無效輸入
- **統一回應格式**：`BaseController` 提供 `sendResponse()` / `sendError()`，所有端點輸出一致的 JSON envelope

---

## 技術棧

| 類別 | 技術 |
|------|------|
| 語言 | PHP 8.2+ |
| 框架 | Laravel 12 |
| 資料庫 | PostgreSQL 14+ + PostGIS |
| 地圖圖磚 | GeoServer 2.x（WMS / SLD） |
| 架構模式 | Controller → Repository（薄 Controller，查詢邏輯集中在 Repository） |
| 快取 | Laravel Cache（`Cache::remember`，TTL 3600s） |
| 部署 | Docker（docker-compose） |

---

## 程式架構

```
┌─────────────────────────────────────────────────────────────────┐
│ HTTP 請求層                                                      │
│ routes/api.php                                                   │
│   throttle:60,1  →  /taipei/api/*                               │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ 請求驗證層                                                       │
│ app/Http/Requests/                                               │
│   DashboardFilterRequest   district → Rule::in(TaipeiDistrict)  │
│   NarrowAlleyRequest       district + category 雙重驗證          │
│   FireHydrantRequest · FireStationRequest · RoadPlannedRequest  │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ Controller 層                                                    │
│ app/Http/Controllers/Base/                                       │
│   DistrictController       FireHydrantController                 │
│   NarrowAlleyController    FireStationController                 │
│   RoadPlannedController    DashboardController                   │
│                                                                  │
│   全部繼承 BaseController                                        │
│   → sendResponse() / sendError()                                 │
│   → 統一 JSON：{ success, data, message }                       │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ 快取層（Dashboard 端點）                                         │
│ Cache::remember(key, 3600, fn)                                   │
│   CacheKey Enum 產生具名快取 Key                                  │
│   taipei_urban:narrow_alley_stats:{district|all}                │
│   taipei_urban:district_rankings                                 │
│   taipei_urban:hydrant_stats:{district|all}                     │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ Repository 層                                                    │
│ app/Repositories/                                                │
│   DistrictRepository      getAll()                              │
│                           getAllWithNarrowAlleyCounts()          │
│                           （單查詢 + 三個子查詢，避免 N+1）       │
│   NarrowAlleyRepository   getFiltered(district, category)       │
│   DashboardRepository     getNarrowAlleyStatistics()            │
│                           getDistrictRankings()（CTE 去重排名）  │
│                           getHydrantStatistics()                │
│   FireHydrantRepository · FireStationRepository                 │
│   RoadPlannedRepository                                         │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ 資料庫層                                                         │
│ PostgreSQL 14 + PostGIS                                          │
│   空間函式：ST_Intersects · ST_Transform · ST_AsGeoJSON          │
│             ST_Centroid · ST_Length · ST_AsText                  │
│   座標系：TWD97 TM2 (EPSG:3826) ↔ WGS84 (EPSG:4326)            │
└─────────────────────────────────────────────────────────────────┘
          ↓                                ↓
┌─────────────────┐             ┌──────────────────────────────┐
│  JSON API 回應  │             │  GeoServer WMS               │
│  GeoJSON 線型   │             │  行政區邊界 + SLD 分色渲染    │
│  + 屬性統計     │             │  districts_density SQL View  │
└─────────────────┘             └──────────────────────────────┘
```

---

## API 端點

所有端點前綴：`/taipei/api`，限流：**60 次 / 分鐘**

所有回應格式：

```json
{
  "success": true,
  "data": { ... },
  "message": "..."
}
```

### 行政區

| 方法 | 路徑 | 說明 |
|------|------|------|
| GET | `/districts` | 12 個行政區清單（id、名稱、面積） |
| GET | `/districts/metadata` | 各行政區中心點座標 + 窄巷總數 |

### 空間圖層

| 方法 | 路徑 | Query 參數 | 說明 |
|------|------|-----------|------|
| GET | `/roads/planned` | `district?` | 計畫道路 GeoJSON（含路寬） |
| GET | `/narrow-alleys` | `district?` `category?` | 窄巷 GeoJSON（含實測路寬與偏移距離） |
| GET | `/fire-hydrants` | `district?` | 消防栓座標 |
| GET | `/fire-stations` | `district?` | 消防局座標 |

### Dashboard 統計（有快取）

| 方法 | 路徑 | Query 參數 | 說明 |
|------|------|-----------|------|
| GET | `/dashboard/narrow-alley-statistics` | `district?` | 窄巷總數、計畫數、實測新發現數 |
| GET | `/dashboard/district-rankings` | — | 12 行政區窄巷密度排名 |
| GET | `/dashboard/hydrant-statistics` | `district?` | 消防栓總數、密度、服務半徑 |

---

## 資料表結構

| 資料表 | 說明 |
|--------|------|
| `districts` | 行政區邊界（PostGIS geom，TWD97） |
| `roads_planned` | 都市計畫道路線型與路寬 |
| `roads_measured` | 實際測量道路資料 |
| `narrow_alleys_temp` | 消防局實測窄巷（含 snap 距離與 matched_road_id） |
| `fire_hydrants` | 消防栓點位 |
| `fire_stations` | 消防局點位 |

---

## 快速開始

### 環境需求

- PHP 8.2+、Composer 2
- PostgreSQL 14+（含 PostGIS 擴充）
- GeoServer 2.x

### 安裝與執行

```bash
# 1. 安裝依賴
composer install

# 2. 設定環境變數
cp .env.example .env
# 填入 DB_HOST、DB_DATABASE、DB_USERNAME、DB_PASSWORD

# 3. 產生應用程式金鑰
php artisan key:generate

# 4. 執行 Migration
php artisan migrate

# 5. 啟動開發伺服器（預設 port 8000）
php artisan serve
```

### 使用 Docker

```bash
docker-compose up -d
```

---

## 專案結構

```
app/
├── Enums/
│   ├── TaipeiDistrict.php      # 台北市 12 行政區常數（驗證白名單）
│   ├── CacheKey.php            # 快取 Key 命名工廠
│   ├── RoadCategory.php        # 道路類別
│   └── AlleyCategory.php       # 窄巷類別
├── Http/
│   ├── Controllers/
│   │   ├── API/BaseController.php      # sendResponse / sendError
│   │   └── Base/                       # 各資源 Controller
│   └── Requests/                       # FormRequest 輸入驗證
└── Repositories/                       # 資料庫查詢邏輯（PostGIS SQL）
```
