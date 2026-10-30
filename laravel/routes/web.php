<?php

use App\Http\Controllers\ArquivosController;
use App\Http\Controllers\BuscaController;
use App\Http\Controllers\CategoriasController;
use App\Http\Controllers\ClientesController;
use App\Http\Controllers\KitsController;
use App\Http\Controllers\LocacoesController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\PainelController;
use App\Http\Controllers\PonteController;
use App\Http\Controllers\ProdutosController;
use App\Http\Controllers\UnidadesController;
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
    Route::get('/produtos', [ProdutosController::class, 'index'])->name('produtos.index');
    Route::post('/produtos/filtrar', [ProdutosController::class, 'filtrar'])->name('produtos.filtrar');
    Route::get('/produtos/novo', [ProdutosController::class, 'create'])->name('produtos.create');
    Route::post('/produtos', [ProdutosController::class, 'store'])->name('produtos.store');
    Route::get('/produtos/{id}/editar', [ProdutosController::class, 'edit'])->whereUuid('id')->name('produtos.edit');
    Route::put('/produtos/{id}', [ProdutosController::class, 'update'])->whereUuid('id')->name('produtos.update');
    Route::delete('/produtos/{id}', [ProdutosController::class, 'destroy'])->whereUuid('id')->name('produtos.destroy');
    Route::get('/produtos/categorias', [CategoriasController::class, 'index'])->name('categorias.index');
    Route::post('/produtos/categorias', [CategoriasController::class, 'store'])->name('categorias.store');
    Route::put('/produtos/categorias/{id}', [CategoriasController::class, 'update'])->whereUuid('id')->name('categorias.update');
    Route::delete('/produtos/categorias/{id}', [CategoriasController::class, 'destroy'])->whereUuid('id')->name('categorias.destroy');
    Route::get('/produtos/kits', [KitsController::class, 'index'])->name('kits.index');
    Route::get('/produtos/kits/novo', [KitsController::class, 'create'])->name('kits.create');
    Route::post('/produtos/kits', [KitsController::class, 'store'])->name('kits.store');
    Route::get('/produtos/kits/{id}/editar', [KitsController::class, 'edit'])->whereUuid('id')->name('kits.edit');
    Route::put('/produtos/kits/{id}', [KitsController::class, 'update'])->whereUuid('id')->name('kits.update');
    Route::delete('/produtos/kits/{id}', [KitsController::class, 'destroy'])->whereUuid('id')->name('kits.destroy');
    Route::get('/produtos/{produto}/unidades/nova', [UnidadesController::class, 'create'])->whereUuid('produto')->name('unidades.create');
    Route::post('/produtos/{produto}/unidades', [UnidadesController::class, 'store'])->whereUuid('produto')->name('unidades.store');
    Route::get('/produtos/unidades/{id}', [UnidadesController::class, 'show'])->whereUuid('id')->name('unidades.show');
    Route::put('/produtos/unidades/{id}', [UnidadesController::class, 'update'])->whereUuid('id')->name('unidades.update');
    Route::post('/produtos/unidades/{id}/baixar', [UnidadesController::class, 'baixar'])->whereUuid('id')->name('unidades.baixar');
    Route::delete('/produtos/unidades/{id}', [UnidadesController::class, 'destroy'])->whereUuid('id')->name('unidades.destroy');
    Route::get('/locacoes', [LocacoesController::class, 'index'])->name('locacoes.index');
    Route::post('/locacoes/filtrar', [LocacoesController::class, 'filtrar'])->name('locacoes.filtrar');
    Route::get('/locacoes/{numero}', [LocacoesController::class, 'show'])->whereNumber('numero')->name('locacoes.show');
    foreach (['receber', 'prorrogar', 'cancelar', 'entrega', 'unidades'] as $acao) {
        Route::post("/locacoes/{numero}/$acao", [LocacoesController::class, $acao])->whereNumber('numero')->name("locacoes.$acao");
    }
    Route::post('/busca', [BuscaController::class, 'buscar'])->name('busca.buscar');
    Route::get('/busca', [BuscaController::class, 'index'])->name('busca.index');
    Route::post('/busca/rapida', [BuscaController::class, 'rapida'])->name('busca.rapida');
    Route::get('/ir', [PonteController::class, 'ir'])->name('ir');
    Route::get('/arquivos/logo', [ArquivosController::class, 'logo'])->name('arquivos.logo');
});
