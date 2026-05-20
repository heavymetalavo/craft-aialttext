<?php

use heavymetalavo\craftaialttext\controllers\GenerateController;
use Illuminate\Support\Facades\Route;

// Add 'web' middleware so the session is started for both CP-prefixed
// (admin/actions/...) and non-prefixed (actions/...) route variants.
// HasRoutes only applies ['craft','craft.cp'] to the CP variant, omitting
// 'web' — which means no session and therefore no authenticated user.
Route::prefix('ai-alt-text')->middleware(['web', 'auth:craft'])->group(function () {
    Route::post('generate/single-asset', [GenerateController::class, 'actionSingleAsset']);
    Route::get('generate/generate-all-assets', [GenerateController::class, 'actionGenerateAllAssets']);
    Route::get('generate-all-assets', [GenerateController::class, 'actionGenerateAllAssets']);
    Route::get('generate/generate-assets-without-alt-text', [GenerateController::class, 'actionGenerateAssetsWithoutAltText']);
    Route::get('generate-assets-without-alt-text', [GenerateController::class, 'actionGenerateAssetsWithoutAltText']);
});
