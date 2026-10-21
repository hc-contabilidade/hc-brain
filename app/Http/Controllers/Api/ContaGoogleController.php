<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContaGoogle;
use App\Support\GoogleAgenda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

class ContaGoogleController extends Controller
{
    /** As agendas conectadas e se o ambiente tem o cliente do Google configurado. */
    public function index(): JsonResponse
    {
        return response()->json($this->agendas());
    }

    /**
     * Lê as agendas na hora, sem esperar o agendador — que no Render para
     * quando a instância hiberna. Relê a janela inteira, para recuperar até
     * evento que uma leitura anterior deixou passar, e devolve o que cada
     * conta trouxe.
     */
    public function sincronizar(Request $request): JsonResponse
    {
        abort_unless($request->user()->ehAdministrador(), 403, 'Só um administrador sincroniza as agendas do Google.');

        Artisan::call('hc:sincronizar-agendas', ['--completa' => true]);

        $relatorio = Str::of(Artisan::output())
            ->explode("\n")
            ->map(fn (string $linha) => trim((string) preg_replace('/^\s*(INFO|WARN|ERROR)\s+/', '', $linha)))
            ->filter()
            ->values();

        return response()->json([...$this->agendas(), 'relatorio' => $relatorio]);
    }

    /** As reuniões que vieram desta agenda ficam no histórico, só perdem o vínculo. */
    public function destroy(Request $request, ContaGoogle $contaGoogle): Response
    {
        abort_unless($request->user()->ehAdministrador(), 403, 'Só um administrador desconecta agendas do Google.');

        $contaGoogle->delete();

        return response()->noContent();
    }

    /**
     * @return array{configurada: bool, contas: Collection<int, array{id: int, email: string, nome: string, sincronizadaEm: string|null, erro: string|null}>}
     */
    private function agendas(): array
    {
        return [
            'configurada' => GoogleAgenda::configurada(),
            'contas' => ContaGoogle::orderBy('email')->get()->map(fn (ContaGoogle $conta) => [
                'id' => $conta->id,
                'email' => $conta->email,
                'nome' => $conta->rotuloNaAgenda(),
                'sincronizadaEm' => $conta->sincronizada_em?->setTimezone(GoogleAgenda::FUSO)->format('d/m/Y H:i'),
                'erro' => $conta->erro,
            ]),
        ];
    }
}
