<?php

use App\Http\Controllers\ArquivosController;
use App\Http\Controllers\BuscaController;
use App\Http\Controllers\ClientesController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\PainelController;
use App\Http\Controllers\PonteController;
use App\Http\Middleware\SessaoLocadora;
use Illuminate\Support\Facades\Route;

Route::get('/login', [LoginController::class, 'index'])->name('login');
Route::post('/login', [LoginController::class, 'entrar'])->name('login.entrar');
Route::post('/sair', [LoginController::class, 'sair'])->name('sair');
// Chegada pela ponte de sessão do sistema antigo (a passagem assinada é a autorização)
Route::get('/ponte', [PonteController::class, 'entrar'])->name('ponte');

// Tudo abaixo exige sessão de uma locadora: o middleware define o app.tenant_id (RLS) da conexão
Route::middleware(SessaoLocadora::class)->group(function () {
    Route::redirect('/', '/painel');
    Route::get('/painel', [PainelController::class, 'index'])->name('painel');
    Route::get('/clientes', [ClientesController::class, 'index'])->name('clientes.index');
    Route::post('/clientes/filtrar', [ClientesController::class, 'filtrar'])->name('clientes.filtrar');
    Route::get('/clientes/novo', [ClientesController::class, 'create'])->name('clientes.create');
    Route::post('/clientes', [ClientesController::class, 'store'])->name('clientes.store');
    Route::get('/clientes/{id}', [ClientesController::class, 'show'])->whereUuid('id')->name('clientes.show');
    Route::get('/clientes/{id}/editar', [ClientesController::class, 'edit'])->whereUuid('id')->name('clientes.edit');
    Route::put('/clientes/{id}', [ClientesController::class, 'update'])->whereUuid('id')->name('clientes.update');
    Route::post('/clientes/{id}/bloquear', [ClientesController::class, 'bloquear'])->whereUuid('id')->name('clientes.bloquear');
    Route::delete('/clientes/{id}', [ClientesController::class, 'destroy'])->whereUuid('id')->name('clientes.destroy');
    Route::post('/busca', [BuscaController::class, 'buscar'])->name('busca.buscar');
    Route::get('/busca', [BuscaController::class, 'index'])->name('busca.index');
    Route::post('/busca/rapida', [BuscaController::class, 'rapida'])->name('busca.rapida');
    Route::get('/ir', [PonteController::class, 'ir'])->name('ir');
    Route::get('/arquivos/logo', [ArquivosController::class, 'logo'])->name('arquivos.logo');
});
