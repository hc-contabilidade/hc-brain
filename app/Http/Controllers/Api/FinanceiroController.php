<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use Illuminate\Http\JsonResponse;

class FinanceiroController extends Controller
{
    /**
     * Carteira do financeiro geral. Empresas de rascunho (Empresa X e as
     * demais do seeder) têm honorários fictícios e ficam de fora: só entra
     * quem já está vinculado a um app Omie e a um código de cliente.
     */
    public function index(): JsonResponse
    {
        $carteira = Empresa::with(['socio', 'financeiro'])
            ->whereNotNull('omie_app_id')
            ->whereNotNull('omie_codigo_cliente')
            ->whereHas('financeiro')
            ->orderBy('created_at')
            ->get()
            ->map(fn (Empresa $empresa) => [
                'id' => $empresa->id,
                'nome' => $empresa->nome,
                'setor' => $empresa->setor,
                'socio' => $empresa->socio->nome,
                'regime' => $empresa->financeiro->regime,
                'desde' => $empresa->financeiro->desde->format('Y-m-d'),
                'meses' => $empresa->financeiro->mesesDeVida(),
                'primeiro' => $empresa->financeiro->primeiro,
                'atual' => $empresa->financeiro->atual,
                'total' => $empresa->financeiro->total,
            ]);

        return response()->json($carteira);
    }

    /**
     * Lista de baixo do financeiro, no formato da automação: código, cliente
     * e documento. Entram ativas e inativas. O status é o da última
     * sincronização, então uma ativa que vira inativa muda aqui sozinha.
     */
    public function empresas(): JsonResponse
    {
        $empresas = Empresa::query()
            ->whereNotNull('automacao_id')
            ->get(['id', 'codigo', 'nome', 'documento', 'documento_tipo', 'status', 'status_tag'])
            ->sortBy(fn (Empresa $empresa) => sprintf('%010d-%s', (int) $empresa->codigo, $empresa->nome))
            ->values()
            ->map(fn (Empresa $empresa) => [
                'id' => $empresa->id,
                'codigo' => $empresa->codigo,
                'nome' => $empresa->nome,
                'documento' => $empresa->documento,
                'documentoTipo' => $empresa->documento_tipo,
                'status' => $empresa->status,
                'statusTag' => $empresa->status_tag,
            ]);

        return response()->json($empresas);
    }
}
