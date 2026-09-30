<?php

use heavymetalavo\craftaialttext\controllers\GenerateController;
use Illuminate\Support\Facades\Route;

// No prefix or middleware here: the caller supplies both. Craft's HasRoutes concern registers
// this file under `{cpTrigger}/{actionTrigger}/{handle}` (and the site equivalent), and
// AiAltTextBootstrapProvider does the same when the concern doesn't run.
//
// The bulk actions are POST-only: they queue work for every matching asset, so they must not be
// triggerable by a plain link. The utility submits CSRF-protected forms.
Route::post('generate/single-asset', [GenerateController::class, 'actionSingleAsset']);
Route::post('generate/generate-all-assets', [GenerateController::class, 'actionGenerateAllAssets']);
Route::post('generate-all-assets', [GenerateController::class, 'actionGenerateAllAssets']);
Route::post('generate/generate-assets-without-alt-text', [GenerateController::class, 'actionGenerateAssetsWithoutAltText']);
Route::post('generate-assets-without-alt-text', [GenerateController::class, 'actionGenerateAssetsWithoutAltText']);
