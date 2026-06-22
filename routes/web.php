<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\Webhooks\ResendInboundController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Inbound email webhook (Resend). Unauthenticated; the request is verified by
// its Svix signature inside the controller, and CSRF is excluded in bootstrap.
Route::post('webhooks/resend/inbound', ResendInboundController::class)
    ->name('webhooks.resend.inbound');

Route::get('/', function (Request $request) {
    $isMobile = (bool) preg_match('/Mobile|iPhone|iPod|Android.+Mobile|Windows Phone/i', (string) $request->userAgent());

    return redirect($isMobile ? '/messages' : '/tickets');
})->name('home');

// There is no standalone dashboard; the app opens on the tickets/messages
// home. Kept as a named redirect so existing links resolve.
Route::redirect('dashboard', '/')->name('dashboard');

// Accepting a team invitation: a guest sets their own name + password.
Route::middleware('guest')->group(function () {
    Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.accept');
    Route::post('invitations/{token}', [InvitationController::class, 'store'])->name('invitations.store');
});

Route::middleware('auth')->group(function () {
    Route::get('attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->name('attachments.download');
    Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])
        ->name('attachments.show');

    Route::post('push-subscriptions', [PushSubscriptionController::class, 'store'])
        ->name('push-subscriptions.store');
    Route::delete('push-subscriptions', [PushSubscriptionController::class, 'destroy'])
        ->name('push-subscriptions.destroy');
});

require __DIR__.'/projects.php';
require __DIR__.'/settings.php';
