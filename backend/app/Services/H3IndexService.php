<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use RuntimeException;

/**
 * Provides a thin abstraction around whichever H3 PHP binding is installed.
 *
 * By resolving the converter lazily we can support ext-h3, h3-php, and native
 * bindings without leaking implementation details across the rest of the code
 * base.
 */
class H3IndexService
{
    /**
     * Converts latitude/longitude pairs into an H3 index for a given resolution.
     *
     * @var Closure(float, float, int):string
     */
    private readonly Closure $converter;

    /**
     * @throws RuntimeException When no compatible H3 implementation is installed
     */
    public function __construct()
    {
        $this->converter = $this->resolveConverter();
    }

    /**
     * Convert a latitude/longitude pair into an H3 index for the requested resolution.
     *
     * @throws RuntimeException When the underlying H3 binding fails to resolve
     */
    public function toH3(float $lat, float $lng, int $resolution): string
    {
        $converter = $this->converter;

        return $converter($lat, $lng, $resolution);
    }

    /**
     * Generate multiple H3 indexes for the coordinate across the requested resolutions.
     *
     * @param int[] $resolutions
     * @return array<int, string>
     *
     * @throws RuntimeException When the underlying H3 binding fails to resolve
     */
    public function indexesFor(float $lat, float $lng, array $resolutions): array
    {
        $results = [];
        foreach ($resolutions as $resolution) {
            $results[$resolution] = $this->toH3($lat, $lng, $resolution);
        }

        return $results;
    }

    /**
     * Resolve the optimal conversion callback exposed by the available H3 extension.
     *
     * @throws RuntimeException When no compatible H3 implementation is installed
     */
    private function resolveConverter(): Closure
    {
        if (class_exists('\\H3\\H3')) {
            $h3 = new \H3\H3();

            if (method_exists($h3, 'latLngToCell')) {
                return fn (float $lat, float $lng, int $res): string => $h3->latLngToCell($lat, $lng, $res);
            }

            if (method_exists($h3, 'geoToH3')) {
                return fn (float $lat, float $lng, int $res): string => $h3->geoToH3($lat, $lng, $res);
            }
        }

        if (function_exists('H3\\latLngToCell')) {
            return fn (float $lat, float $lng, int $res): string => \H3\latLngToCell($lat, $lng, $res);
        }

        if (function_exists('H3\\geoToH3')) {
            return fn (float $lat, float $lng, int $res): string => \H3\geoToH3($lat, $lng, $res);
        }

        if (function_exists('latLngToCell')) {
            return fn (float $lat, float $lng, int $res): string => latLngToCell($lat, $lng, $res);
        }

        if (function_exists('geoToH3')) {
            return fn (float $lat, float $lng, int $res): string => geoToH3($lat, $lng, $res);
        }

        throw new RuntimeException('H3 library is not available');
    }
}
