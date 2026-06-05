# Taipei City Urban Disaster Prevention Map | Backend API

A RESTful API service for Taipei City road, narrow alley, and fire safety infrastructure spatial data. Serves GeoJSON-format geospatial data for frontend map rendering, and performs administrative district statistics and density analysis via PostGIS spatial functions.

> **Frontend Map**: [taipei-urban_frontend](https://github.com/jingtangg/taipei-urban_frontend) (React + OpenLayers)

---

## Features

- **Spatial Queries**: Uses PostGIS `ST_Intersects`, `ST_Transform`, `ST_AsGeoJSON` for coordinate system transformation and spatial intersection calculations
- **Narrow Alley Deduplication**: Merges "urban-planned narrow alleys" and "fire department field-surveyed new discoveries" via SQL CTE to prevent double-counting
- **Cache Layer**: Dashboard endpoint results cached for 3600 seconds; `CacheKey` Enum provides centralized key naming
- **FormRequest Validation**: District names validated with `Rule::in(TaipeiDistrict::ALL)`, rejecting invalid input
- **Unified Response Format**: `BaseController` provides `sendResponse()` / `sendError()`, all endpoints output a consistent JSON envelope

---

## Tech Stack

| Category | Technology |
|----------|-----------|
| Language | PHP 8.2+ |
| Framework | Laravel 12 |
| Database | PostgreSQL 14+ + PostGIS |
| Map Tiles | GeoServer 2.x (WMS / SLD) |
| Architecture | Controller → Repository (thin Controller, query logic centralized in Repository) |
| Cache | Laravel Cache (`Cache::remember`, TTL 3600s) |
| Deployment | Docker (docker-compose) |

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│ HTTP Request Layer                                               │
│ routes/api.php                                                   │
│   throttle:60,1  →  /taipei/api/*                               │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ Request Validation Layer                                         │
│ app/Http/Requests/                                               │
│   DashboardFilterRequest   district → Rule::in(TaipeiDistrict)  │
│   NarrowAlleyRequest       district + category dual validation   │
│   FireHydrantRequest · FireStationRequest · RoadPlannedRequest  │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ Controller Layer                                                 │
│ app/Http/Controllers/Base/                                       │
│   DistrictController       FireHydrantController                 │
│   NarrowAlleyController    FireStationController                 │
│   RoadPlannedController    DashboardController                   │
│                                                                  │
│   All extend BaseController                                      │
│   → sendResponse() / sendError()                                 │
│   → Unified JSON: { success, data, message }                    │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ Cache Layer (Dashboard endpoints)                                │
│ Cache::remember(key, 3600, fn)                                   │
│   CacheKey Enum generates named cache keys                       │
│   taipei_urban:narrow_alley_stats:{district|all}                │
│   taipei_urban:district_rankings                                 │
│   taipei_urban:hydrant_stats:{district|all}                     │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ Repository Layer                                                 │
│ app/Repositories/                                                │
│   DistrictRepository      getAll()                              │
│                           getAllWithNarrowAlleyCounts()          │
│                           (single query + 3 subqueries, N+1-free)│
│   NarrowAlleyRepository   getFiltered(district, category)       │
│   DashboardRepository     getNarrowAlleyStatistics()            │
│                           getDistrictRankings() (CTE dedup rank) │
│                           getHydrantStatistics()                │
│   FireHydrantRepository · FireStationRepository                 │
│   RoadPlannedRepository                                         │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ Database Layer                                                   │
│ PostgreSQL 14 + PostGIS                                          │
│   Spatial functions: ST_Intersects · ST_Transform · ST_AsGeoJSON │
│                      ST_Centroid · ST_Length · ST_AsText         │
│   Coordinate systems: TWD97 TM2 (EPSG:3826) ↔ WGS84 (EPSG:4326)│
└─────────────────────────────────────────────────────────────────┘
          ↓                                ↓
┌─────────────────┐             ┌──────────────────────────────┐
│  JSON API       │             │  GeoServer WMS               │
│  GeoJSON lines  │             │  District boundaries +       │
│  + statistics   │             │  SLD choropleth rendering    │
│                 │             │  districts_density SQL View  │
└─────────────────┘             └──────────────────────────────┘
```

---

## API Endpoints

All endpoints prefixed with `/taipei/api`, rate limit: **60 requests / minute**

All responses:

```json
{
  "success": true,
  "data": { ... },
  "message": "..."
}
```

### Administrative Districts

| Method | Path | Description |
|--------|------|-------------|
| GET | `/districts` | List of 12 districts (id, name, area) |
| GET | `/districts/metadata` | District centroid coordinates + narrow alley counts |

### Spatial Layers

| Method | Path | Query Params | Description |
|--------|------|-------------|-------------|
| GET | `/roads/planned` | `district?` | Urban-planned roads GeoJSON (with road width) |
| GET | `/narrow-alleys` | `district?` `category?` | Narrow alley GeoJSON (with field-measured width and offset distance) |
| GET | `/fire-hydrants` | `district?` | Fire hydrant coordinates |
| GET | `/fire-stations` | `district?` | Fire station coordinates |

### Dashboard Statistics (cached)

| Method | Path | Query Params | Description |
|--------|------|-------------|-------------|
| GET | `/dashboard/narrow-alley-statistics` | `district?` | Total, planned, and field-surveyed new alley counts |
| GET | `/dashboard/district-rankings` | — | Narrow alley density rankings across all 12 districts |
| GET | `/dashboard/hydrant-statistics` | `district?` | Total hydrant count, density, and service radius |

---

## Database Schema

| Table | Description |
|-------|-------------|
| `districts` | District boundaries (PostGIS geom, TWD97) |
| `roads_planned` | Urban-planned road geometry and widths |
| `roads_measured` | Field-measured road data |
| `narrow_alleys_temp` | Fire department field-surveyed narrow alleys (with snap distance and matched_road_id) |
| `fire_hydrants` | Fire hydrant point locations |
| `fire_stations` | Fire station point locations |

---

## Quick Start

### Requirements

- PHP 8.2+, Composer 2
- PostgreSQL 14+ (with PostGIS extension)
- GeoServer 2.x

### Installation

```bash
# 1. Install dependencies
composer install

# 2. Configure environment
cp .env.example .env
# Fill in DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD

# 3. Generate application key
php artisan key:generate

# 4. Run migrations
php artisan migrate

# 5. Start development server (default port 8000)
php artisan serve
```

### Using Docker

```bash
docker-compose up -d
```

---

## Project Structure

```
app/
├── Enums/
│   ├── TaipeiDistrict.php      # Taipei City 12-district constants (validation whitelist)
│   ├── CacheKey.php            # Cache key naming factory
│   ├── RoadCategory.php        # Road category constants
│   └── AlleyCategory.php       # Alley category constants
├── Http/
│   ├── Controllers/
│   │   ├── API/BaseController.php      # sendResponse / sendError
│   │   └── Base/                       # Per-resource Controllers
│   └── Requests/                       # FormRequest input validation
└── Repositories/                       # Database query logic (PostGIS SQL)
```
