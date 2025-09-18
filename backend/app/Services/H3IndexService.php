<?php

namespace App\Services;

use RuntimeException;

class H3IndexService {
    /** @var callable */
    private $converter;

    public function __construct() {
        $this->converter = $this->resolveConverter();
    }

    public function toH3(float $lat, float $lng, int $resolution): string {
        $converter = $this->converter;

        return $converter($lat, $lng, $resolution);
    }

    /**
     * @param float $lat
     * @param float $lng
     * @param int[] $resolutions
     * @return array<int, string>
     */
    public function indexesFor(float $lat, float $lng, array $resolutions): array {
        $results = [];
        foreach ($resolutions as $resolution) {
            $results[$resolution] = $this->toH3($lat, $lng, $resolution);
        }

        return $results;
    }

    private function resolveConverter(): callable {
        if (class_exists('\\H3\\H3')) {
            $h3 = new \H3\H3();

            if (method_exists($h3, 'latLngToCell')) {
                return fn(float $lat, float $lng, int $res): string => $h3->latLngToCell($lat, $lng, $res);
            }

            if (method_exists($h3, 'geoToH3')) {
                return fn(float $lat, float $lng, int $res): string => $h3->geoToH3($lat, $lng, $res);
            }
        }

        if (function_exists('H3\\latLngToCell')) {
            return fn(float $lat, float $lng, int $res): string => \H3\latLngToCell($lat, $lng, $res);
        }

        if (function_exists('H3\\geoToH3')) {
            return fn(float $lat, float $lng, int $res): string => \H3\geoToH3($lat, $lng, $res);
        }

        if (function_exists('latLngToCell')) {
            return fn(float $lat, float $lng, int $res): string => latLngToCell($lat, $lng, $res);
        }

        if (function_exists('geoToH3')) {
            return fn(float $lat, float $lng, int $res): string => geoToH3($lat, $lng, $res);
        }

        throw new RuntimeException('H3 library is not available');
    }
}
