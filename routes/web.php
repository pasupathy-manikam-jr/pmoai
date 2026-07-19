<?php

use App\Http\Controllers\FundDetailController;
use App\Http\Controllers\SnapshotController;
use Illuminate\Support\Facades\Route;

// Land straight on the dashboard (there is only ever one snapshot).
Route::get('/', function () {
    $id = \App\Models\Snapshot::latest('id')->value('id');

    return $id
        ? redirect()->route('snapshots.show', $id)
        : redirect()->route('snapshots.create');
});
Route::get('/snapshots', [SnapshotController::class, 'index'])->name('snapshots.index');
Route::get('/new', [SnapshotController::class, 'create'])->name('snapshots.create');
Route::post('/snapshots', [SnapshotController::class, 'store'])->name('snapshots.store');
Route::get('/snapshots/{snapshot}', [SnapshotController::class, 'show'])->name('snapshots.show');
Route::post('/ingest', [SnapshotController::class, 'ingest'])->name('snapshots.ingest');
Route::post('/ingest-detail', [SnapshotController::class, 'ingestDetail'])->name('snapshots.ingestDetail');
Route::post('/ingest-mfr', [SnapshotController::class, 'ingestMfr'])->name('snapshots.ingestMfr');
Route::post('/ingest-holdings', [SnapshotController::class, 'ingestHoldings'])->name('snapshots.ingestHoldings');
Route::post('/ingest-page', [SnapshotController::class, 'ingestPage'])->name('snapshots.ingestPage');
Route::post('/portfolio/review', [SnapshotController::class, 'portfolioReview'])->name('portfolio.review');
Route::get('/portfolio/review/status', [SnapshotController::class, 'portfolioReviewStatus'])->name('portfolio.reviewStatus');
Route::get('/simulator', [SnapshotController::class, 'simulator'])->name('simulator');

Route::view('/dashboard', 'dashboard')->name('dashboard');

Route::get('/details/{detail}', [FundDetailController::class, 'show'])->name('details.show');
Route::post('/details/{detail}/analyze', [FundDetailController::class, 'analyze'])->name('details.analyze');
Route::get('/details/{detail}/status', [FundDetailController::class, 'status'])->name('details.status');
Route::post('/details/{detail}/chat', [FundDetailController::class, 'chat'])->name('details.chat');
