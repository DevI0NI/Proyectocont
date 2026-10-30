<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    protected function localReturnUrl(Request $request): ?string
    {
        $url = $request->input('return_to');
        if (! is_string($url) || strlen($url) > 4096) {
            return null;
        }

        $decoded = rawurldecode($url);
        if (! str_starts_with($decoded, '/') || str_starts_with($decoded, '//')
            || preg_match('/[\\\\\x00-\x1f\x7f]/', $decoded) || parse_url($url, PHP_URL_HOST) !== null) {
            return null;
        }

        return $url;
    }
}
