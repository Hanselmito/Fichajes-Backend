<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class QrGeneratorController extends Controller
{
    public function show(Request $request)
    {
        $code = trim((string) $request->query('code', $request->input('code', '')));
        $size = max(100, min(500, (int) $request->query('size', $request->input('size', 300))));

        if ($code === '') {
            return response('', 400)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        $urls = [
            'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&data=' . urlencode($code),
            'https://chart.googleapis.com/chart?chs=' . $size . 'x' . $size . '&cht=qr&chl=' . urlencode($code),
        ];

        foreach ($urls as $url) {
            $response = Http::timeout(10)->withHeaders(['Accept' => 'image/png'])->get($url);
            if ($response->successful()) {
                return response($response->body(), 200)
                    ->header('Content-Type', 'image/png')
                    ->header('Cache-Control', 'max-age=86400');
            }
        }

        return response('', 500)->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}