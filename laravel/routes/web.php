<?php

use App\Http\Controllers\ArquivosController;
use App\Http\Controllers\ClientesController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\PainelController;
use App\Http\Middleware\SessaoLocadora;
use Illuminate\Support\Facades\Route;

Route::get('/login', [LoginController::class, 'index'])->name('login');
Route::post('/login', [LoginController::class, 'entrar'])->name('login.entrar');
Route::post('/sair', [LoginController::class, 'sair'])->name('sair');

// Tudo abaixo exige sessão de uma locadora: o middleware define o app.tenant_id (RLS) da conexão
Route::middleware(SessaoLocadora::class)->group(function () {
    Route::redirect('/', '/painel');
    Route::get('/painel', [PainelController::class, 'index'])->name('painel');
    Route::get('/clientes', [ClientesController::class, 'index'])->name('clientes.index');
    Route::get('/clientes/{id}', [ClientesController::class, 'show'])->name('clientes.show');
    Route::get('/arquivos/logo', [ArquivosController::class, 'logo'])->name('arquivos.logo');
});
