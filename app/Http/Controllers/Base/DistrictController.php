<?php

namespace App\Http\Controllers\Base;

use App\Http\Controllers\API\BaseController;
use App\Repositories\DistrictRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;
use Exception;

class DistrictController extends BaseController
{
    protected bool $debug;

    public function __construct(private DistrictRepository $repository)
    {
        $this->debug = App::hasDebugModeEnabled();
    }

    /**
     * 回傳所有行政區的基本資訊（不含幾何）
     * 用於下拉選單、統計列表等輕量查詢
     */
    public function index(): JsonResponse
    {
        try {
            $districts = $this->repository->getAll();

            $tableList = array_map(function ($district) {
                return [
                    'id'       => (string) $district->id,
                    'name'     => (string) $district->name,
                    'area_km2' => (float) $district->area_km2,
                ];
            }, $districts);

            return $this->sendResponse([
                'tableList' => $tableList,
                'total'     => count($tableList),
            ], '獲取行政區資料成功!');

        } catch (Exception $e) {
            report($e);
            return $this->debug
                ? $this->sendError($e->getMessage(), ['error' => $e->getMessage()])
                : $this->sendError('獲取行政區資料錯誤,錯誤代碼「DT011」,請通知管理員!!', ['error' => '獲取行政區資料錯誤,錯誤代碼「DT011」,請通知管理員!!']);
        }
    }

    /**
     * 回傳所有行政區的風險統計資料（不含幾何邊界）
     * 幾何邊界由 GeoServer WMS 負責渲染（districts_density SQL View）
     * 此 endpoint 提供：label_center（前端縮放動畫用）、narrowDensity（前端標籤顏色用）
     */
    public function metadata(): JsonResponse
    {
        try {
            $districts = $this->repository->getAllWithMetadata();

            $tableList = $districts->map(function ($district) {
                $totalCount    = $this->repository->getNarrowAlleyCount($district->name);
                $narrowDensity = $district->area_km2 > 0
                    ? round($totalCount / $district->area_km2, 1)
                    : 0;

                return [
                    'id'            => (string) $district->id,
                    'name'          => (string) $district->name,
                    'area_km2'      => (float) $district->area_km2,
                    'label_center'  => $district->label_center,
                    'narrowDensity' => (float) $narrowDensity,
                ];
            })->toArray();

            return $this->sendResponse([
                'tableList' => array_values($tableList),
                'total'     => count($tableList),
            ], '獲取行政區資料成功!');

        } catch (Exception $e) {
            report($e);
            return $this->debug
                ? $this->sendError($e->getMessage(), ['error' => $e->getMessage()])
                : $this->sendError('獲取行政區資料錯誤,錯誤代碼「DT012」,請通知管理員!!', ['error' => '獲取行政區資料錯誤,錯誤代碼「DT012」,請通知管理員!!']);
        }
    }
}
