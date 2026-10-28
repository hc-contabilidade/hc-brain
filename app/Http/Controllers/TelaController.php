<?php

namespace App\Http\Controllers;

use App\Http\Resources\UsuarioResource;
use App\Models\Empresa;
use App\Support\SofiaModelos;
use App\Support\Transcricao;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Cada tela é uma view Blade enxuta: o HTML é o esqueleto da página e o
 * TypeScript daquela tela preenche o conteúdo com os dados da API.
 */
class TelaController extends Controller
{
    public function inicio(): View
    {
        return view('telas.inicio');
    }

    public function pesquisa(): View
    {
        return view('telas.pesquisa');
    }

    public function documentos(): View
    {
        return view('telas.documentos');
    }

    /**
     * A tela precisa saber, antes do primeiro clique no microfone, se o
     * servidor transcreve áudio. Sem isso — e sem reconhecimento de voz no
     * navegador — ela esconde o caminho do áudio em vez de falhar depois.
     */
    public function sofiaIa(): View
    {
        return view('telas.sofia-ia', [
            'transcricaoConfigurada' => Transcricao::configurada(),
            'modelosSofia' => SofiaModelos::paraTela(),
            'modeloSofiaPadrao' => SofiaModelos::padrao(),
        ]);
    }

    public function treinamentos(): View
    {
        return view('telas.treinamentos');
    }

    public function clientes(): View
    {
        return view('telas.clientes');
    }

    /**
     * O detalhe do cliente pode ser compartilhado por link. Quando o id não
     * existe na base, a página mostra o estado de erro com o caminho de volta
     * em vez de uma tela de 404 genérica.
     */
    public function cliente(string $empresa): Response|View
    {
        $registro = Empresa::find($empresa);

        if ($registro === null) {
            return response()->view('telas.cliente', ['empresa' => null], 404);
        }

        return view('telas.cliente', ['empresa' => $registro]);
    }

    public function usuarios(): View
    {
        return view('telas.usuarios');
    }

    public function comercial(): View
    {
        return view('telas.comercial');
    }

    public function reunioes(): View
    {
        return view('telas.reunioes');
    }

    public function financeiro(): View
    {
        return view('telas.financeiro');
    }

    public function projetos(): View
    {
        return view('telas.projetos');
    }

    public function processos(): View
    {
        return view('telas.processos');
    }

    /**
     * O perfil é sempre o de quem está logado: não há id na rota, então não
     * há como pedir o cadastro de outra pessoa por aqui.
     */
    public function perfil(Request $request): View
    {
        $usuario = $request->user();

        return view('telas.perfil', [
            'usuario' => $usuario,
            // O mesmo texto que a tela de Usuários mostra nesta coluna.
            'ultimoAcesso' => UsuarioResource::quando($usuario->ultimo_acesso),
        ]);
    }
}
