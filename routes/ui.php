<?php

use Illuminate\Support\Facades\Route;
use Kstmostofa\LaravelWhatsApp\Http\Controllers\AvatarProxyController;
use Kstmostofa\LaravelWhatsApp\Http\Controllers\MediaProxyController;
use Kstmostofa\LaravelWhatsApp\Http\Controllers\UiAssetsController;
use Kstmostofa\LaravelWhatsApp\Livewire\ChatHistory;
use Kstmostofa\LaravelWhatsApp\Livewire\Compose;
use Kstmostofa\LaravelWhatsApp\Livewire\ContactsIndex;
use Kstmostofa\LaravelWhatsApp\Livewire\Dashboard;
use Kstmostofa\LaravelWhatsApp\Livewire\GroupsIndex;
use Kstmostofa\LaravelWhatsApp\Livewire\SessionsIndex;
use Kstmostofa\LaravelWhatsApp\Livewire\Status;
use Kstmostofa\LaravelWhatsApp\Livewire\WebhooksLog;

Route::get('/', Dashboard::class)->name('whatsapp.ui.dashboard');
Route::get('/sessions', SessionsIndex::class)->name('whatsapp.ui.sessions');
Route::get('/compose', Compose::class)->name('whatsapp.ui.compose');
Route::get('/chats/{session?}', ChatHistory::class)->name('whatsapp.ui.chats');
Route::get('/groups/{session?}', GroupsIndex::class)->name('whatsapp.ui.groups');
Route::get('/contacts/{session?}', ContactsIndex::class)->name('whatsapp.ui.contacts');
Route::get('/webhooks', WebhooksLog::class)->name('whatsapp.ui.webhooks');
Route::get('/status', Status::class)->name('whatsapp.ui.status');
Route::get('/media/{session}/{messageId}', [MediaProxyController::class, 'show'])
    ->where('messageId', '.*')
    ->name('whatsapp.ui.media');
Route::get('/avatar/{session}/{contactId}', [AvatarProxyController::class, 'show'])
    ->where('contactId', '.*')
    ->name('whatsapp.ui.avatar');
Route::get('/_assets/laravel-whatsapp.css', [UiAssetsController::class, 'css'])
    ->name('whatsapp.ui.assets.css');
