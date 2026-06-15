<?php

use App\Http\Controllers\AttachmentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function (Request $request) {
    $isMobile = (bool) preg_match('/Mobile|iPhone|iPod|Android.+Mobile|Windows Phone/i', (string) $request->userAgent());

    return redirect($isMobile ? '/messages' : '/tickets');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

Route::middleware('auth')->group(function () {
    Route::get('attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->name('attachments.download');
    Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])
        ->name('attachments.show');
});

require __DIR__.'/projects.php';
require __DIR__.'/settings.php';
