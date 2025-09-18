<?php
namespace App\Services;

use RuntimeException;

class H3GeometryService {

    /** @var callable */
    private $boundaryResolver;
    private bool $geoJsonOrder;

    public function __construct() {
        [$resolver, $geoJsonOrder] = $this->resolveBoundaryResolver();
        $this->boundaryResolver = $resolver;
        $this->geoJsonOrder = $geoJsonOrder;
    }

    /**
     * @param string $h3
     *
     * @return array
     */
    public function polygonCoordinates(string $h3): array {
        $boundary = ($this->boundaryResolver)($h3);
        if (!is_array($boundary)) {
            throw new RuntimeException('Invalid boundary response from H3 library');
        }

        $coordinates = [];

        if ($this->geoJsonOrder) {
            foreach ($boundary as $vertex) {
                if (!is_array($vertex) || !isset($vertex[0], $vertex[1])) {
                    throw new RuntimeException('Unexpected vertex format from H3 boundary resolver');
                }
                $coordinates[] = [(float) $vertex[0], (float) $vertex[1]];
            }
        } else {
            foreach ($boundary as $vertex) {
                if (!is_array($vertex)) {
                    throw new RuntimeException('Unexpected vertex format from H3 boundary resolver');
                }

                if (array_key_exists('lng', $vertex)) {
                    $lng = $vertex['lng'];
                } elseif (array_key_exists('lon', $vertex)) {
                    $lng = $vertex['lon'];
                } elseif (array_key_exists('longitude', $vertex)) {
                    $lng = $vertex['longitude'];
                } elseif (array_key_exists(1, $vertex)) {
                    $lng = $vertex[1];
                } else {
                    throw new RuntimeException('Longitude missing from H3 boundary vertex');
                }

                if (array_key_exists('lat', $vertex)) {
                    $lat = $vertex['lat'];
                } elseif (array_key_exists('latitude', $vertex)) {
                    $lat = $vertex['latitude'];
                } elseif (array_key_exists(0, $vertex)) {
                    $lat = $vertex[0];
                } else {
                    throw new RuntimeException('Latitude missing from H3 boundary vertex');
                }

                $coordinates[] = [(float) $lng, (float) $lat];
            }
        }

        if ($coordinates) {
            $first = $coordinates[0];
            $last = end($coordinates);
            if ($first[0] !== $last[0] || $first[1] !== $last[1]) {
                $coordinates[] = $first;
            }
        }

        return $coordinates;
    }

    /**
     * @return array{0: callable, 1: bool}
     */
    private function resolveBoundaryResolver(): array {
        if (class_exists('\\H3\\H3')) {
            $h3 = new \H3\H3();

            if (method_exists($h3, 'cellToBoundary')) {
                return [fn(string $index): array => $h3->cellToBoundary($index, true), true];
            }

            if (method_exists($h3, 'cellToGeoBoundary')) {
                return [fn(string $index): array => $h3->cellToGeoBoundary($index), false];
            }

            if (method_exists($h3, 'h3ToGeoBoundary')) {
                return [fn(string $index): array => $h3->h3ToGeoBoundary($index), false];
            }
        }

        if (function_exists('H3\\cellToBoundary')) {
            return [fn(string $index): array => \H3\cellToBoundary($index, true), true];
        }

        if (function_exists('H3\\cellToGeoBoundary')) {
            return [fn(string $index): array => \H3\cellToGeoBoundary($index), false];
        }

        if (function_exists('H3\\h3ToGeoBoundary')) {
            return [fn(string $index): array => \H3\h3ToGeoBoundary($index), false];
        }

        if (function_exists('cellToBoundary')) {
            return [fn(string $index): array => cellToBoundary($index, true), true];
        }

        if (function_exists('cellToGeoBoundary')) {
            return [fn(string $index): array => cellToGeoBoundary($index), false];
        }

        if (function_exists('h3ToGeoBoundary')) {
            return [fn(string $index): array => h3ToGeoBoundary($index), false];
        }

        throw new RuntimeException('H3 boundary conversion is not available');
    }
}
