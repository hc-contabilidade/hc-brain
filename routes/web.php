<?php

use App\Http\Controllers\Api;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SenhaController;
use App\Http\Controllers\GoogleController;
use App\Http\Controllers\TelaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| HC Brain
|--------------------------------------------------------------------------
| Cada tela tem a sua URL. Qualquer página sem sessão volta para o login
| guardando o destino pretendido, e a API que alimenta o TypeScript vive na
| mesma sessão do navegador — por isso ela fica aqui, e não em routes/api.php.
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    // O limite é por IP e por e-mail: tentar mil senhas em um minuto não é
    // uso normal, e sem isso qualquer conta fica exposta a força bruta.
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:10,1');

    Route::get('/esqueci-a-senha', [SenhaController::class, 'solicitar'])->name('senha.solicitar');
    // Pedir link também é limitado: sem isso a tela vira uma forma de inundar
    // a caixa de entrada de quem tem acesso.
    Route::post('/esqueci-a-senha', [SenhaController::class, 'enviarLink'])
        ->middleware('throttle:5,1')
        ->name('senha.enviar');
    Route::get('/redefinir-senha/{token}', [SenhaController::class, 'redefinir'])->name('senha.redefinir');
    Route::post('/redefinir-senha', [SenhaController::class, 'salvar'])
        ->middleware('throttle:5,1')
        ->name('senha.salvar');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', [TelaController::class, 'inicio'])->name('inicio');
    Route::get('/pesquisa', [TelaController::class, 'pesquisa'])->name('pesquisa');
    Route::get('/documentos', [TelaController::class, 'documentos'])->name('documentos');
    Route::get('/sofia-ia', [TelaController::class, 'sofiaIa'])->name('sofia-ia');
    Route::get('/treinamentos', [TelaController::class, 'treinamentos'])->name('treinamentos');
    Route::get('/clientes', [TelaController::class, 'clientes'])->name('clientes');
    Route::get('/clientes/{empresa}', [TelaController::class, 'cliente'])->name('cliente');
    Route::get('/usuarios', [TelaController::class, 'usuarios'])->name('usuarios');
    Route::get('/comercial', [TelaController::class, 'comercial'])->name('comercial');
    Route::get('/reunioes', [TelaController::class, 'reunioes'])->name('reunioes');
    Route::get('/financeiro', [TelaController::class, 'financeiro'])->name('financeiro');
    Route::get('/projetos', [TelaController::class, 'projetos'])->name('projetos');
    Route::get('/processos', [TelaController::class, 'processos'])->name('processos');
    Route::get('/perfil', [TelaController::class, 'perfil'])->name('perfil');

    Route::get('/google/conectar', [GoogleController::class, 'conectar'])->name('google.conectar');
    Route::get('/google/retorno', [GoogleController::class, 'retorno'])->name('google.retorno');

    Route::prefix('api')->group(function () {
        Route::get('/contadores', [Api\ContadorController::class, 'index']);
        Route::get('/inicio', [Api\InicioController::class, 'index']);
        Route::get('/comercial', [Api\ComercialController::class, 'index']);
        Route::get('/comercial/{periodo}', [Api\ComercialController::class, 'show']);
        Route::get('/financeiro', [Api\FinanceiroController::class, 'index']);
        Route::get('/pesquisa', [Api\PesquisaController::class, 'index']);

        // As rotas de escrita são escritas uma a uma, e não por apiResource,
        // porque o nome do parâmetro aqui é em português: o singular que o
        // Laravel deduz de "reunioes" não é "reuniao".
        Route::get('/empresas', [Api\EmpresaController::class, 'index']);
        Route::post('/empresas', [Api\EmpresaController::class, 'store']);
        Route::get('/empresas/{empresa}', [Api\EmpresaController::class, 'show']);
        Route::put('/empresas/{empresa}', [Api\EmpresaController::class, 'update']);
        Route::delete('/empresas/{empresa}', [Api\EmpresaController::class, 'destroy']);

        Route::get('/reunioes', [Api\ReuniaoController::class, 'index']);
        Route::post('/reunioes', [Api\ReuniaoController::class, 'store']);
        Route::put('/reunioes/{reuniao}', [Api\ReuniaoController::class, 'update']);
        Route::delete('/reunioes/{reuniao}', [Api\ReuniaoController::class, 'destroy']);

        Route::get('/projetos', [Api\ProjetoController::class, 'index']);
        Route::post('/projetos', [Api\ProjetoController::class, 'store']);
        Route::put('/projetos/{projeto}', [Api\ProjetoController::class, 'update']);
        Route::delete('/projetos/{projeto}', [Api\ProjetoController::class, 'destroy']);

        Route::get('/processos', [Api\ProcessoController::class, 'index']);
        Route::post('/processos', [Api\ProcessoController::class, 'store']);
        Route::put('/processos/{processo}', [Api\ProcessoController::class, 'update']);
        Route::delete('/processos/{processo}', [Api\ProcessoController::class, 'destroy']);

        Route::get('/treinamentos', [Api\TreinamentoController::class, 'index']);
        Route::post('/treinamentos', [Api\TreinamentoController::class, 'store']);
        Route::put('/treinamentos/{treinamento}', [Api\TreinamentoController::class, 'update']);
        Route::delete('/treinamentos/{treinamento}', [Api\TreinamentoController::class, 'destroy']);

        Route::get('/usuarios', [Api\UsuarioController::class, 'index']);
        Route::post('/usuarios', [Api\UsuarioController::class, 'store']);
        Route::put('/usuarios/{usuario}', [Api\UsuarioController::class, 'update']);
        Route::delete('/usuarios/{usuario}', [Api\UsuarioController::class, 'destroy']);

        Route::get('/agendas-google', [Api\ContaGoogleController::class, 'index']);
        Route::post('/agendas-google/sincronizar', [Api\ContaGoogleController::class, 'sincronizar']);
        Route::delete('/agendas-google/{contaGoogle}', [Api\ContaGoogleController::class, 'destroy']);

        Route::post('/pastas', [Api\PastaController::class, 'store']);
        Route::put('/pastas/{pasta}', [Api\PastaController::class, 'update']);
        Route::delete('/pastas/{pasta}', [Api\PastaController::class, 'destroy']);

        Route::get('/documentos', [Api\DocumentoController::class, 'index']);
        Route::post('/documentos', [Api\DocumentoController::class, 'store']);
        Route::put('/documentos/{documento}', [Api\DocumentoController::class, 'update']);
        Route::delete('/documentos/{documento}', [Api\DocumentoController::class, 'destroy']);
        // O arquivo passa pelo Laravel para continuar atrás da sessão.
        Route::get('/documentos/{documento}/arquivo', [Api\DocumentoController::class, 'download'])
            ->name('documentos.arquivo');

        Route::get('/sofia/sugestoes', [Api\SofiaController::class, 'sugestoes']);
        Route::post('/sofia/perguntar', [Api\SofiaController::class, 'perguntar'])
            ->middleware('throttle:10,1');
        Route::post('/sofia/audio', [Api\SofiaController::class, 'audio'])
            ->middleware('throttle:10,1');
    });
});
