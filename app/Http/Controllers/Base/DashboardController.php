<?php

namespace App\Http\Controllers\Base;

use App\Enums\CacheKey;
use App\Http\Controllers\API\BaseController;
use App\Http\Requests\DashboardFilterRequest;
use App\Repositories\DashboardRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Exception;

class DashboardController extends BaseController
{
    protected bool $debug;

    public function __construct(private DashboardRepository $repository)
    {
        $this->debug = App::hasDebugModeEnabled();
    }

    /**
     * 回傳窄巷統計數據
     * 可透過 ?district=大安區 篩選特定行政區
     * 不傳 district 則回傳全台北市統計
     */
    public function narrowAlleyStatistics(DashboardFilterRequest $request): JsonResponse
    {
        try {
            $district = $request->validated()['district'] ?? null;
            $cacheKey = CacheKey::narrowAlleyStats($district);

            return Cache::remember($cacheKey, 3600, function () use ($district) {
                $stats = $this->repository->getNarrowAlleyStatistics($district);
                return $this->sendResponse($stats, '獲取窄巷統計成功!');
            });

        } catch (Exception $e) {
            report($e);
            return $this->debug
                ? $this->sendError($e->getMessage(), ['error' => $e->getMessage()])
                : $this->sendError('獲取窄巷統計錯誤,錯誤代碼「DASH001」,請通知管理員!!', ['error' => '獲取窄巷統計錯誤,錯誤代碼「DASH001」,請通知管理員!!']);
        }
    }

    /**
     * 回傳各行政區密度排名
     * 固定回傳全台北市12個行政區
     */
    public function districtRankings(): JsonResponse
    {
        try {
            return Cache::remember(CacheKey::districtRankings(), 3600, function () {
                $rankings = $this->repository->getDistrictRankings();
                return $this->sendResponse(['rankings' => $rankings], '獲取行政區排名成功!');
            });

        } catch (Exception $e) {
            report($e);
            return $this->debug
                ? $this->sendError($e->getMessage(), ['error' => $e->getMessage()])
                : $this->sendError('獲取行政區排名錯誤,錯誤代碼「DASH002」,請通知管理員!!', ['error' => '獲取行政區排名錯誤,錯誤代碼「DASH002」,請通知管理員!!']);
        }
    }

    /**
     * 回傳消防栓統計數據
     * 可透過 ?district=大安區 篩選特定行政區
     * 不傳 district 則回傳全台北市統計
     */
    public function hydrantStatistics(DashboardFilterRequest $request): JsonResponse
    {
        try {
            $district = $request->validated()['district'] ?? null;
            $cacheKey = CacheKey::hydrantStats($district);

            return Cache::remember($cacheKey, 3600, function () use ($district) {
                $stats = $this->repository->getHydrantStatistics($district);
                return $this->sendResponse($stats, '獲取消防栓統計成功!');
            });

        } catch (Exception $e) {
            report($e);
            return $this->debug
                ? $this->sendError($e->getMessage(), ['error' => $e->getMessage()])
                : $this->sendError('獲取消防栓統計錯誤,錯誤代碼「DASH003」,請通知管理員!!', ['error' => '獲取消防栓統計錯誤,錯誤代碼「DASH003」,請通知管理員!!']);
        }
    }
}
