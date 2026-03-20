<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\GenerationController;


Route::get('/', function () {
    return redirect()->route('templates.upload');
});


// Step 1: Upload
Route::get('/templates/upload', [TemplateController::class, 'showUploadForm'])->name('templates.upload');
Route::post('/templates/upload', [TemplateController::class, 'handleUpload'])->name('templates.upload.post'); // ← This is the one the form should use

// Step 2: Configure (GET only - no POST here)
Route::get('/templates/configure', [TemplateController::class, 'showConfigureForm'])->name('templates.configure.show');

// Step 2 → 3: Save config
Route::post('/templates/save-config', [TemplateController::class, 'saveConfig'])->name('templates.save-config');

// Step 3: Generate
Route::get('/templates/generate', [GenerationController::class, 'showGenerateForm'])->name('templates.generate');
Route::post('/templates/download', [GenerationController::class, 'download'])->name('templates.download');


// Route::middleware('api')->group(function () {
//     // Example: Route::post('/api/validate-excel', [TemplateController::class, 'validateExcel']);
// });
