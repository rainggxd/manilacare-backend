<?php

namespace App\Libraries;

/**
 * One place that decides which websites may call the API from a browser.
 *
 * The allowed addresses come from CORS_ALLOWED_ORIGINS in the .env file
 * (separate several with commas). If it is not set, only the local
 * development addresses are allowed.
 *
 * Example .env line:
 *   CORS_ALLOWED_ORIGINS = http://localhost:5173,http://192.168.1.10:5173
 */
class Cors
{
    public static function allowedOrigins(): array
    {
        $fromEnv = array_filter(array_map('trim', explode(',', (string) getenv('CORS_ALLOWED_ORIGINS'))));

        return $fromEnv ?: ['http://localhost:5173', 'http://127.0.0.1:5173'];
    }

    /** Adds the CORS headers, but only when the request comes from an allowed address. */
    public static function apply($response, string $origin)
    {
        if ($origin !== '' && in_array($origin, self::allowedOrigins(), true)) {
            $response->setHeader('Access-Control-Allow-Origin', $origin);
            $response->setHeader('Vary', 'Origin');
            $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
            $response->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        }

        return $response;
    }
}