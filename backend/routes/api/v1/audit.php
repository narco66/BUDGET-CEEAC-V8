<?php

use App\Domains\Administration\Http\Controllers\AuditJournalController;
use Illuminate\Support\Facades\Route;

Route::get('/audit/chronologie', [AuditJournalController::class, 'chronologie']);
Route::get('/admin/audit', [AuditJournalController::class, 'index']);
Route::get('/admin/audit/export', [AuditJournalController::class, 'exporter']);
Route::post('/admin/audit/gel', [AuditJournalController::class, 'geler']);
Route::post('/admin/audit/gels/{auditHold}/lever', [AuditJournalController::class, 'lever']);
Route::get('/admin/audit/{auditEvent}', [AuditJournalController::class, 'show']);
Route::post('/admin/audit/{auditEvent}/rectification', [AuditJournalController::class, 'rectifier']);
