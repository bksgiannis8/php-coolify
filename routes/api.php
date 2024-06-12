<?php

use App\Http\Controllers\Api\Deploy;
use App\Http\Controllers\Api\Domains;
use App\Http\Controllers\Api\Resources;
use App\Http\Controllers\Api\Server;
use App\Http\Controllers\Api\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return 'OK';
});
Route::post('/feedback', function (Request $request) {
    $content = $request->input('content');
    $webhook_url = config('coolify.feedback_discord_webhook');
    if ($webhook_url) {
        Http::post($webhook_url, [
            'content' => $content,
        ]);
    }

    return response()->json(['message' => 'Feedback sent.'], 200);
});

