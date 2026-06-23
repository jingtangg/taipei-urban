<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class BaseController extends Controller
{
    /**
     * success response method.
     */
    public function sendResponse($result, $message): JsonResponse
    {
        $response = [
            'success' => true,
            'data'    => $result,
            'message' => $message,
        ];

        return response()->json($response, 200);
    }

    /**
     * 將已型別化的 Collection 包裝成 GeoJSON FeatureCollection (RFC 7946)
     * $items 每筆需含 $geometryKey 欄位（已解碼的 stdClass 或 array）
     */
    protected function toFeatureCollection(
        \Illuminate\Support\Collection $items,
        string $geometryKey = 'geometry',
        array $propertyKeys = []
    ): array {
        return [
            'type' => 'FeatureCollection',
            'features' => $items->map(function ($item) use ($geometryKey, $propertyKeys) {
                $row = is_array($item) ? $item : (array) $item;

                $geometry = $row[$geometryKey];
                if (is_string($geometry)) {
                    $geometry = json_decode($geometry);
                }

                $properties = empty($propertyKeys)
                    ? collect($row)->except($geometryKey)->toArray()
                    : collect($row)->only($propertyKeys)->toArray();

                return [
                    'type'       => 'Feature',
                    'geometry'   => $geometry,
                    'properties' => $properties,
                ];
            })->values()->toArray(),
        ];
    }

    /**
     * 依路寬計算風險等級
     * 閾值：3.5m（極高風險）/ 6m（高風險）
     * 此為系統核心判斷邏輯，集中於後端確保所有 API 消費端取得一致結果
     */
    protected function calculateRiskLevel(float $widthM): string
    {
        if ($widthM < 3.5) return '極高風險';
        if ($widthM < 6.0) return '高風險';
        return '一般';
    }

    /**
     * return error response.
     */
    public function sendError($error, $errorMessages = [], $code = 404): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $error,
        ];

        if(!empty($errorMessages)){
            $response['data'] = $errorMessages;
        }

        return response()->json($response, $code);
    }
}
