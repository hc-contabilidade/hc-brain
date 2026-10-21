<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalvarReuniaoRequest;
use App\Http\Resources\ReuniaoResource;
use App\Models\Empresa;
use App\Models\Reuniao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ReuniaoController extends Controller
{
    /** Histórico completo, já ordenado como a linha do tempo mostra. */
    public function index(): JsonResponse
    {
        $reunioes = Reuniao::with(['empresa.socio', 'contaGoogle'])
            ->orderByDesc('data')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'reunioes' => ReuniaoResource::collection($reunioes),
            // A tela precisa do id junto do nome: o filtro mostra o nome, mas
            // o cadastro grava o vínculo pelo id.
            'empresas' => Empresa::orderBy('nome')->get(['id', 'nome']),
        ]);
    }

    public function store(SalvarReuniaoRequest $request): JsonResponse
    {
        $reuniao = Reuniao::create($request->paraOBanco());

        return self::envelope($reuniao, 201);
    }

    public function update(SalvarReuniaoRequest $request, Reuniao $reuniao): JsonResponse
    {
        $reuniao->update($request->paraOBanco());

        return self::envelope($reuniao);
    }

    public function destroy(Reuniao $reuniao): Response
    {
        $reuniao->delete();

        return response()->noContent();
    }

    /**
     * O envelope `data` é escrito à mão aqui. A reunião tem um campo chamado
     * `data` — a data dela —, e o Laravel, vendo essa chave, entende que a
     * resposta já veio envelopada e devolve o recurso cru. Sem isto, a reunião
     * seria a única entidade da API a responder fora do padrão.
     */
    private static function envelope(Reuniao $reuniao, int $status = 200): JsonResponse
    {
        $recurso = new ReuniaoResource($reuniao->load(['empresa.socio', 'contaGoogle']));

        return response()->json(['data' => $recurso], $status);
    }
}
