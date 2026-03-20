<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\GenerationController;


Route::get('/', function () {
    return redirect()->route('templates.upload');
});


Route::get('/templates/upload', [TemplateController::class, 'showUploadForm'])->name('templates.upload');
Route::post('/templates/upload', [TemplateController::class, 'handleUpload'])->name('templates.upload.post'); // ← This is the one the form should use

Route::get('/templates/configure', [TemplateController::class, 'showConfigureForm'])->name('templates.configure.show');

Route::post('/templates/save-config', [TemplateController::class, 'saveConfig'])->name('templates.save-config');

Route::get('/templates/generate', [GenerationController::class, 'showGenerateForm'])->name('templates.generate');
Route::post('/templates/download', [GenerationController::class, 'download'])->name('templates.download');


