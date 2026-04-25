<?php

declare(strict_types=1);

use Core\Mod\Commerce\Controllers\MatrixTrainingController;
use Core\Mod\Commerce\View\Modal\Web\CheckoutCancel;
use Core\Mod\Commerce\View\Modal\Web\CheckoutSuccess;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Commerce Matrix Routes
|--------------------------------------------------------------------------
*/

Route::prefix('commerce')->name('commerce.')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Permission Matrix Training Routes
    |--------------------------------------------------------------------------
    */

    Route::prefix('matrix')->name('matrix.')->group(function () {
        // Training submission (POST form from train-prompt view)
        Route::post('/train', [MatrixTrainingController::class, 'train'])
            ->name('train');

        // Pending requests view
        Route::get('/pending', [MatrixTrainingController::class, 'pending'])
            ->name('pending');

        // Bulk training
        Route::post('/bulk-train', [MatrixTrainingController::class, 'bulkTrain'])
            ->name('bulk-train');
    });

});

Route::get('/checkout/success', CheckoutSuccess::class)->name('checkout.success');
Route::get('/checkout/cancel', CheckoutCancel::class)->name('checkout.cancel');
