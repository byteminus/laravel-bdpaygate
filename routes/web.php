<?php

use ByteMinus\BdPayGate\Http\Controllers\CallbackController;
use Illuminate\Support\Facades\Route;

// Gateways come back here by GET or POST. No session, no CSRF: see CallbackController.
Route::match(['get', 'post'], '/{gateway}/{outcome}', CallbackController::class)
    ->where('gateway', '[a-z0-9_-]+')
    ->where('outcome', 'success|fail|cancel|ipn|callback')
    ->name('bdpaygate.callback');
