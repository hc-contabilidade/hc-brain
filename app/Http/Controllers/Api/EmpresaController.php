<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalvarEmpresaRequest;
use App\Http\Resources\EmpresaResource;
use App\Http\Resources\ReuniaoResource;
use App\Http\Resources\TreinamentoResource;
use App\Models\Empresa;
use App\Models\ReceitaMensal;
use App\Models\Transacao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class EmpresaController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $empresas = Empresa::with(['socio', 'ultimaReuniao'])
            ->withCount(['reunioes', 'fontes'])
            ->orderBy('nome')
            ->get();

        return EmpresaResource::collection($empresas);
    }

    /** Tudo o que o detalhe do cliente mostra, em uma resposta só. */
    public function show(Empresa $empresa): JsonResponse
    {
        $empresa->load([
            'socio', 'fontes', 'ultimaReuniao', 'reunioes.empresa.socio', 'treinamentos',
            'financeiro', 'receitasMensais', 'transacoes',
        ]);

        $financeiro = $empresa->financeiro;

        return response()->json([
            'empresa' => new EmpresaResource($empresa),
            'reunioes' => ReuniaoResource::collection($empresa->reunioes),
            'treinamentos' => TreinamentoResource::collection($empresa->treinamentos),
            'financeiro' => $financeiro === null ? null : [
                'regime' => $financeiro->regime,
                'desde' => $financeiro->desde->format('Y-m-d'),
                'meses' => $financeiro->mesesDeVida(),
                'primeiro' => $financeiro->primeiro,
                'atual' => $financeiro->atual,
                'total' => $financeiro->total,
                'saldo' => $financeiro->saldo,
                'receber' => $financeiro->receber,
                'despesas' => $financeiro->despesas,
                'margem' => $financeiro->margem,
                'receitaMensal' => $empresa->receitasMensais
                    ->map(fn (ReceitaMensal $item) => ['mes' => $item->mes, 'valor' => $item->valor])
                    ->all(),
                'transacoes' => $empresa->transacoes->map(fn (Transacao $item) => [
                    'data' => $item->data,
                    'desc' => $item->descricao,
                    'cat' => $item->categoria,
                    'tipo' => $item->tipo,
                    'valor' => $item->valor,
                    'status' => $item->status,
                ])->all(),
            ],
        ]);
    }

    /**
     * Novas empresas ainda vêm da sincronização com a automação. A edição
     * manual (nome, status, sócio) é feita pela tela de Empresas & clientes.
     */
    public function store(Request $request): JsonResponse
    {
        abort(403, 'O cadastro de empresas novas vem da automação.');
    }

    public function update(SalvarEmpresaRequest $request, Empresa $empresa): EmpresaResource
    {
        DB::transaction(function () use ($request, $empresa): void {
            $empresa->update([
                'nome' => $request->string('nome')->toString(),
                'setor' => $request->string('setor')->toString(),
                'status' => $request->string('status')->toString(),
                'status_tag' => $request->string('statusTag')->toString(),
            ]);

            $empresa->socio()->updateOrCreate(
                ['empresa_id' => $empresa->id],
                $request->dadosDoSocio(),
            );
        });

        $empresa->load(['socio', 'ultimaReuniao'])
            ->loadCount(['reunioes', 'fontes']);

        return new EmpresaResource($empresa);
    }

    public function destroy(Empresa $empresa): Response
    {
        abort(403, 'A exclusão de empresas não é feita por esta tela.');
    }
}
