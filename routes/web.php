<?php

use App\Http\Controllers\AttachmentController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/tickets')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

Route::middleware('auth')->get('attachments/{attachment}/download', [AttachmentController::class, 'download'])
    ->name('attachments.download');

require __DIR__.'/projects.php';
require __DIR__.'/settings.php';
