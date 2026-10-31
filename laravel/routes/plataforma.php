<?php

use App\Http\Controllers\Plataforma\AuditoriaController;
use App\Http\Controllers\Plataforma\ContaController;
use App\Http\Controllers\Plataforma\LocadorasController;
use App\Http\Controllers\Plataforma\LoginController;
use App\Http\Controllers\Plataforma\NumerosController;
use App\Http\Controllers\Plataforma\PlanosController;
use App\Http\Controllers\Plataforma\RecadosController;
use App\Http\Controllers\Plataforma\UsoController;
use App\Http\Middleware\SessaoPlataforma;
use Illuminate\Support\Facades\Route;

// Painel da plataforma (dono do SaaS): sessão própria, separada da sessão das locadoras (SessaoPlataforma)
Route::prefix('plataforma')->name('plataforma.')->group(function () {
    Route::get('/login', [LoginController::class, 'index'])->name('login');
    Route::post('/login', [LoginController::class, 'entrar'])->name('entrar');
    Route::post('/sair', [LoginController::class, 'sair'])->name('sair');

    Route::middleware(SessaoPlataforma::class)->group(function () {
        Route::redirect('/', '/plataforma/locadoras');

        Route::get('/locadoras', [LocadorasController::class, 'index'])->name('locadoras');
        Route::get('/locadoras/nova', [LocadorasController::class, 'nova'])->name('locadoras.nova');
        Route::post('/locadoras', [LocadorasController::class, 'criar'])->name('locadoras.criar');
        Route::get('/locadoras/criada', [LocadorasController::class, 'criada'])->name('locadoras.criada');
        Route::get('/locadoras/{id}', [LocadorasController::class, 'editar'])->whereUuid('id')->name('locadoras.editar');
        Route::put('/locadoras/{id}', [LocadorasController::class, 'salvar'])->whereUuid('id')->name('locadoras.salvar');
        Route::post('/locadoras/{id}/status', [LocadorasController::class, 'status'])->whereUuid('id')->name('locadoras.status');
        Route::post('/locadoras/{id}/suporte', [LocadorasController::class, 'suporteEntrar'])->whereUuid('id')->name('locadoras.suporte');
        Route::post('/locadoras/{id}/pagamento', [LocadorasController::class, 'pagamento'])->whereUuid('id')->name('locadoras.pagamento');
        Route::post('/locadoras/{id}/estornar', [LocadorasController::class, 'estornar'])->whereUuid('id')->name('locadoras.estornar');
        Route::post('/locadoras/{id}/senha', [LocadorasController::class, 'resetSenha'])->whereUuid('id')->name('locadoras.senha');
        Route::post('/locadoras/{id}/cancelar', [LocadorasController::class, 'cancelarContrato'])->whereUuid('id')->name('locadoras.cancelar');
        Route::post('/locadoras/{id}/cobranca/config', [LocadorasController::class, 'cobrancaConfig'])->whereUuid('id')->name('locadoras.cobranca.config');
        Route::post('/locadoras/{id}/cobranca/gerar', [LocadorasController::class, 'cobrancaGerar'])->whereUuid('id')->name('locadoras.cobranca.gerar');
        Route::post('/locadoras/{id}/cobranca/sincronizar', [LocadorasController::class, 'cobrancaSincronizar'])->whereUuid('id')->name('locadoras.cobranca.sincronizar');
        Route::post('/locadoras/{id}/cobranca/cancelar', [LocadorasController::class, 'cobrancaCancelar'])->whereUuid('id')->name('locadoras.cobranca.cancelar');

        Route::get('/planos', [PlanosController::class, 'index'])->name('planos.index');
        Route::post('/planos', [PlanosController::class, 'criar'])->name('planos.criar');
        Route::put('/planos/{id}', [PlanosController::class, 'atualizar'])->whereUuid('id')->name('planos.atualizar');
        Route::delete('/planos/{id}', [PlanosController::class, 'excluir'])->whereUuid('id')->name('planos.excluir');

        Route::get('/numeros', [NumerosController::class, 'index'])->name('numeros');
        Route::get('/uso', [UsoController::class, 'index'])->name('uso');

        Route::get('/recados', [RecadosController::class, 'index'])->name('recados.index');
        Route::post('/recados', [RecadosController::class, 'criar'])->name('recados.criar');
        Route::post('/recados/{id}/desativar', [RecadosController::class, 'desativar'])->whereUuid('id')->name('recados.desativar');

        Route::get('/auditoria', [AuditoriaController::class, 'index'])->name('auditoria');

        Route::get('/conta', [ContaController::class, 'index'])->name('conta');
        Route::put('/conta/senha', [ContaController::class, 'salvar'])->name('conta.senha');
    });
});
