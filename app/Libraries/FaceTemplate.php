<?php

namespace App\Libraries;

use App\Models\SystemSettingModel;

/**
 * Small helpers shared by the patient registration and the face feature.
 */
class FaceTemplate
{
    public const SIZE = 128;

    /** True unless Master Admin turned facial recognition off in Settings. */
    public static function enabled(): bool
    {
        $settings = (new SystemSettingModel())->getAllAsMap();
        return ($settings['facial_recognition_enabled'] ?? '1') === '1';
    }

    /** Returns a clean array of 128 floats, or null if the input is not valid. */
    public static function clean($raw): ?array
    {
        if (!is_array($raw) || count($raw) !== self::SIZE) {
            return null;
        }

        $out  = [];
        $norm = 0.0;
        foreach (array_values($raw) as $value) {
            if (!is_int($value) && !is_float($value)) {
                return null;
            }
            $value = (float) $value;
            if (!is_finite($value) || abs($value) > 5) {
                return null;
            }
            $out[] = round($value, 6);
            $norm += $value * $value;
        }

        // An all-zero (or almost empty) template is not a real face.
        if (sqrt($norm) < 0.1) {
            return null;
        }

        return $out;
    }
}