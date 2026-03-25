<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Anonymous voting game — no auth required
Route::livewire('/', 'pages::vote')->name('vote');

// Authenticated routes
Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('/dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('/playground', 'pages::playground')->name('playground');
});

// Auth routes
Route::middleware('guest')->group(function () {
    Route::livewire('/auth/login', 'pages::auth.login')->name('login');
    Route::livewire('/auth/register', 'pages::auth.register')->name('register');
    Route::livewire('/auth/forgot-password', 'pages::auth.forgot-password')->name('password.request');
    Route::livewire('/auth/reset-password/{token}', 'pages::auth.reset-password')->name('password.reset');
});

// Profile routes
Route::middleware(['auth'])->group(function () {
    Route::livewire('/profile', 'pages::profile.index')->name('profile.update');
});

// Email verification
Route::get('/verify-email', function () {
    return view('auth.verify-email');
})->middleware('auth')->name('verification.notice');

Route::get('/verify-email/{id}/{hash}', function () {
    // Handled by Laravel's built-in email verification
})->middleware(['auth', 'signed'])->name('verification.verify');
